#!/usr/bin/env bash
# Devolve a configuracao REAL do SMTP (Brevo) e desliga o sink.
D=/root/smtp_test
CHAVES="'smtp_host','smtp_porta','smtp_seguranca','smtp_usuario','smtp_senha','email_remetente','email_remetente_nome'"
for p in sink.pid sink2.pid; do [ -f $D/$p ] && kill "$(cat $D/$p)" 2>/dev/null; done
mysql painel < $D/backup_config.sql
depois=$(mysql painel -N -e "SELECT chave, valor FROM configuracoes WHERE chave IN ($CHAVES) ORDER BY chave" | md5sum | cut -d' ' -f1)
if [ "$depois" = "$(cat $D/antes.md5)" ]; then echo "RESTAURADO: configuracao do SMTP identica a de antes (md5 ${depois:0:8})"; else echo "ATENCAO: configuracao DIFERENTE da original!"; fi
mysql painel -N -e "SELECT chave, IF(chave='smtp_senha', CONCAT('(', LENGTH(valor), ' car.)'), valor) FROM configuracoes WHERE chave IN ($CHAVES) ORDER BY chave" | sed 's/^/   /'
[ -f $D/failsafe.pid ] && kill "$(cat $D/failsafe.pid)" 2>/dev/null
exit 0
