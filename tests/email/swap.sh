#!/usr/bin/env bash
# Aponta o SMTP do painel para o servidor FALSO (sink) durante os testes. Guarda a configuracao real antes e agenda a restauracao
# automatica (rede de seguranca) caso o teste seja interrompido. NAO envia nada para o Brevo enquanto estiver ativo.
set -e
D=/root/smtp_test
mkdir -p $D /tmp/sink; rm -f /tmp/sink/*.eml
CHAVES="'smtp_host','smtp_porta','smtp_seguranca','smtp_usuario','smtp_senha','email_remetente','email_remetente_nome'"
mysqldump painel configuracoes --no-create-info --replace --skip-extended-insert --where="chave IN ($CHAVES)" > $D/backup_config.sql
[ -s $D/backup_config.sql ] || { echo "backup vazio: abortando"; exit 1; }
mysql painel -N -e "SELECT chave, valor FROM configuracoes WHERE chave IN ($CHAVES) ORDER BY chave" | md5sum | cut -d' ' -f1 > $D/antes.md5
echo "configuracao real guardada ($(grep -c REPLACE $D/backup_config.sql) chaves; md5 $(cut -c1-8 $D/antes.md5))"

cd $D
SINK_PORTA=2526 setsid nohup python3 $D/sink.py > $D/sink.log 2>&1 < /dev/null &
echo $! > $D/sink.pid
sleep 1
mysql painel -e "REPLACE INTO configuracoes (chave, valor) VALUES ('smtp_host','127.0.0.1'),('smtp_porta','2526'),('smtp_seguranca','nenhuma'),('smtp_usuario','sink'),('email_remetente','teste@estresse.invalid'),('email_remetente_nome','Teste')"
cat > $D/setsenha.php <<'PHPEOF'
<?php
chdir('/var/www/painel'); require 'conexao.php'; require 'funcoes/configuracoes.php'; require 'funcoes/criptografia.php';
definirConfigSistema('smtp_senha', criptografarSegredo('x'));
PHPEOF
chmod 644 $D/setsenha.php
runuser -u www-data -- php $D/setsenha.php
setsid nohup bash -c "sleep 2700; $D/restore.sh" > $D/failsafe.log 2>&1 < /dev/null &
echo $! > $D/failsafe.pid
echo "SMTP do painel agora aponta para o sink (127.0.0.1:2526). Restauracao automatica em 45 min se eu esquecer."
