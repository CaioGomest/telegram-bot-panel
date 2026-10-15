#!/usr/bin/env bash
# Parte 2: reenvio de confirmacao, cadastro, troca de senha/e-mail em Minha Conta, politica da fila, TLS, cabecalhos e tokens de verificacao.
H=${PAINEL_HOST:-srv2059665.hstgr.cloud}
S=/tmp/sink
OK=0; FALHA=0
chk() { if [ "$2" = "1" ]; then OK=$((OK+1)); echo "  [ok]    $1"; else FALHA=$((FALHA+1)); echo "  [FALHA] $1 ${3:+-> $3}"; fi; }
curlv() { curl -sk --resolve $H:443:127.0.0.1 "$@"; }
sql() { mysql painel -N -e "$1"; }
nsink() { ls $S/*.eml 2>/dev/null | wc -l; }
zera() { mysql painel -e "DELETE FROM limites_taxa"; rm -f $S/*.eml; }
recuar() { mysql painel -e "UPDATE limites_taxa SET janela_inicio = NOW() - INTERVAL 120 SECOND WHERE chave LIKE '$1%'"; }
novajar() { mktemp /tmp/jar.XXXXXX; }
fila() { runuser -u www-data -- php -r 'chdir("/var/www/painel"); require "conexao.php"; require "funcoes/email.php"; processarFilaEmails($pdo);'; }
assuntos() { python3 /root/smtp_test/inbox.py assuntos; }
csrf_de() { grep -o 'name="csrf_token" value="[^"]*"' | head -1 | sed 's/.*value="//; s/"$//'; }
logar() { # jar email senha
  local t; t=$(curlv -c "$1" "https://$H/login" | csrf_de)
  curlv -b "$1" -c "$1" -o /dev/null --data-urlencode "csrf_token=$t" --data-urlencode "acao=login" --data-urlencode "email=$2" --data-urlencode "senha=$3" "https://$H/login"; }
tokenjs() { grep -o 'window.CSRF_TOKEN = "[^"]*"' | head -1 | sed 's/.*= "//; s/"$//'; }
decod() { php -r '$j=json_decode(stream_get_contents(STDIN),true); echo json_encode($j, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);'; }
campo() { php -r '$j=json_decode(stream_get_contents(STDIN),true); $v=$j[$argv[1]]??null; echo is_bool($v)?($v?"true":"false"):(string)$v;' "$1"; }

HASH=$(php -r 'echo password_hash("SenhaAntiga#1", PASSWORD_DEFAULT);')
for n in 3 4; do sql "UPDATE usuarios SET senha='$HASH', email=CONCAT('rec-teste-', $n, '@estresse.invalid') WHERE nome='Teste Rec $n'"; done
sql "UPDATE usuarios SET email_verificado_em = NULL WHERE nome='Teste Rec 3'"; sql "UPDATE usuarios SET email_verificado_em = NOW() WHERE nome='Teste Rec 4'"
iniciar() { kill "$(cat /root/smtp_test/sink.pid)" 2>/dev/null; sleep 0.6; SINK_ATRASO=0 SINK_PORTA=2526 setsid nohup python3 /root/smtp_test/sink.py > /root/smtp_test/sink.log 2>&1 < /dev/null & echo $! > /root/smtp_test/sink.pid; sleep 1; }
iniciar

echo "=== T8  reenvio de confirmacao (logado): 1 por minuto, 5 por hora"
zera; J=$(novajar); logar $J rec-teste-3@estresse.invalid 'SenhaAntiga#1'
TK=$(curlv -b $J "https://$H/bots" | tokenjs)
chk "login do usuario de teste funcionou e achei o token CSRF" "$([ -n "$TK" ] && echo 1)"
reenv() { curlv -b $J -c $J -H "X-CSRF-Token: $TK" -H 'Content-Type: application/json' -X POST -d '{}' "https://$H/funcoes/usuario.php?api=usuario&acao=reenviar_verificacao_email" | decod; }
a=$(reenv); b=$(reenv)
chk "1o reenvio aceito" "$([ "$(echo "$a" | campo sucesso)" = true ] && echo 1)" "$a"
chk "2o reenvio imediato recusado com espera" "$([ "$(echo "$b" | campo sucesso)" = false ] && [ "$(echo "$b" | campo espera)" -ge 50 ] && echo 1)" "$b"
for i in 1 2 3 4; do recuar verif_cd; reenv > /dev/null; done
recuar verif_cd; f=$(reenv)
chk "6o pedido na mesma hora recusado (limite de 5/h)" "$(echo "$f" | grep -q 'Muitos pedidos' && echo 1)" "$f"
fila
chk "o e-mail de confirmacao chegou (pela fila)" "$(assuntos | grep -q 'Confirme seu e-mail' && echo 1)"
sem_csrf=$(curlv -b $J -o /dev/null -w '%{http_code}' -H 'Content-Type: application/json' -X POST -d '{}' "https://$H/funcoes/usuario.php?api=usuario&acao=reenviar_verificacao_email")
chk "sem token CSRF o reenvio e barrado" "$([ "$sem_csrf" = 403 ] && echo 1)" "HTTP $sem_csrf"
sem_login=$(curlv -o /dev/null -w '%{http_code}' -H 'Content-Type: application/json' -X POST -d '{}' "https://$H/funcoes/usuario.php?api=usuario&acao=reenviar_verificacao_email")
chk "sem login o reenvio e barrado (401)" "$([ "$sem_login" = 401 ] && echo 1)" "HTTP $sem_login"

echo; echo "=== T9  cadastro: 5 por hora por IP (cada cadastro manda e-mail)"
zera; J=$(novajar); T=$(curlv -c $J "https://$H/cadastro" | csrf_de); ok=0; bloq=0
for i in 1 2 3 4 5 6 7; do
  cod=$(curlv -b $J -c $J -o /tmp/cad_$i.html -w '%{http_code}' --data-urlencode "csrf_token=$T" --data-urlencode "nome=Teste Cad $i" --data-urlencode "email=rec-teste-cad-$i@estresse.invalid" --data-urlencode "senha=SenhaCad#2026" --data-urlencode "confirma_senha=SenhaCad#2026" "https://$H/cadastro")
  if [ "$cod" = 302 ]; then ok=$((ok+1)); elif grep -q 'Muitos cadastros' /tmp/cad_$i.html; then bloq=$((bloq+1)); fi; rm -f /tmp/cad_$i.html
done
chk "5 cadastros aceitos e os seguintes recusados (aceitos=$ok, recusados=$bloq)" "$([ $ok = 5 ] && [ $bloq = 2 ] && echo 1)"
chk "5 contas novas existem no banco" "$([ "$(sql "SELECT COUNT(*) FROM usuarios WHERE email LIKE 'rec-teste-cad-%@estresse.invalid'")" = 5 ] && echo 1)"
fila
chk "5 e-mails de boas-vindas (com confirmacao) enviados" "$([ "$(assuntos | grep -c 'Bem-vindo')" = 5 ] && echo 1)" "$(assuntos | grep -c 'Bem-vindo')"

echo; echo "=== T10 Minha Conta: trocar senha + e-mail avisa o ENDERECO ANTIGO e pede confirmar o novo"
zera; J=$(novajar); logar $J rec-teste-4@estresse.invalid 'SenhaAntiga#1'
P=$(curlv -b $J "https://$H/configuracao_usuario"); T=$(echo "$P" | csrf_de)
TOK_ANTES=$(sql "SELECT COUNT(*) FROM tokens_email WHERE id_usuario=(SELECT id FROM usuarios WHERE nome='Teste Rec 4')")
resp=$(curlv -b $J -c $J --data-urlencode "csrf_token=$T" --data-urlencode "nome=Teste Rec 4" --data-urlencode "email=rec-teste-4-novo@estresse.invalid" --data-urlencode "apelido_publico=" --data-urlencode "senha_atual=SenhaAntiga#1" --data-urlencode "senha=SenhaNova#2026" --data-urlencode "confirmar_senha=SenhaNova#2026" "https://$H/configuracao_usuario")
chk "perfil atualizado com sucesso" "$(echo "$resp" | grep -q 'Perfil atualizado com sucesso' && echo 1)"
fila
echo "  e-mails: $(assuntos | tr '\n' ';')"
chk "aviso 'senha alterada' foi para o endereco ANTIGO (rec-teste-4@)" "$(assuntos | grep 'senha foi alterada' | grep -q 'rec-teste-4@estresse' && echo 1)"
chk "aviso 'e-mail alterado' foi para o endereco ANTIGO" "$(assuntos | grep 'e-mail da sua conta foi alterado' | grep -q 'rec-teste-4@estresse' && echo 1)"
chk "confirmacao de e-mail foi para o endereco NOVO" "$(assuntos | grep 'Confirme seu e-mail' | grep -q 'rec-teste-4-novo@estresse' && echo 1)"
chk "o aviso do e-mail antigo mostra o novo endereco MASCARADO (r***@), nunca o completo" "$(python3 /root/smtp_test/inbox.py contem 'e-mail da sua conta foi alterado' 'r***@estresse.invalid' && ! python3 /root/smtp_test/inbox.py contem 'e-mail da sua conta foi alterado' 'rec-teste-4-novo' && echo 1)"
chk "o selo 'verificado' foi zerado ate confirmar o novo e-mail" "$([ -z "$(sql "SELECT email_verificado_em FROM usuarios WHERE email='rec-teste-4-novo@estresse.invalid'" | tr -d 'NULL')" ] && echo 1)"

echo; echo "=== T11 fila: recusa definitiva (550) nao e repetida; erro de configuracao nao gasta tentativa"
zera
runuser -u www-data -- php -r 'chdir("/var/www/painel"); require "conexao.php"; require "funcoes/email.php"; enfileirarEmail("rejeitar-1@estresse.invalid", "Fila 550", "<p>x</p>", "geral");'
fila
chk "destinatario recusado (550): vai para 'falhou' na 1a tentativa (nao repete 5x)" "$([ "$(sql "SELECT CONCAT(status,'/',tentativas) FROM fila_emails WHERE para='rejeitar-1@estresse.invalid' ORDER BY id DESC LIMIT 1")" = "falhou/1" ] && echo 1)" "$(sql "SELECT CONCAT(status,'/',tentativas,' ',LEFT(ultimo_erro,70)) FROM fila_emails WHERE para='rejeitar-1@estresse.invalid' ORDER BY id DESC LIMIT 1")"
sql "UPDATE configuracoes SET valor='ruim' WHERE chave='smtp_usuario'"
runuser -u www-data -- php -r 'chdir("/var/www/painel"); require "conexao.php"; require "funcoes/email.php"; enfileirarEmail("fila-ok@estresse.invalid", "Fila config", "<p>x</p>", "geral");'
fila
R=$(sql "SELECT CONCAT(status,'/',tentativas,'/', IF(proximo_envio > NOW() + INTERVAL 10 MINUTE,'+15min','agora')) FROM fila_emails WHERE para='fila-ok@estresse.invalid' ORDER BY id DESC LIMIT 1")
chk "login do SMTP recusado: fica 'pendente', 0 tentativas gastas, proxima em ~15 min" "$([ "$R" = "pendente/0/+15min" ] && echo 1)" "$R"
sql "UPDATE configuracoes SET valor='sink' WHERE chave='smtp_usuario'"; sql "UPDATE fila_emails SET proximo_envio = NOW() WHERE para='fila-ok@estresse.invalid'"
fila
chk "corrigido o SMTP, o e-mail que estava parado sai sozinho" "$([ "$(sql "SELECT status FROM fila_emails WHERE para='fila-ok@estresse.invalid' ORDER BY id DESC LIMIT 1")" = enviado ] && echo 1)"
sql "UPDATE configuracoes SET valor='ruim' WHERE chave='smtp_usuario'"
runuser -u www-data -- php -r 'chdir("/var/www/painel"); require "conexao.php"; require "funcoes/email.php"; enfileirarEmail("fila-velha@estresse.invalid", "Fila velha", "<p>x</p>", "geral");'
sql "UPDATE fila_emails SET criado_em = NOW() - INTERVAL 2 DAY WHERE para='fila-velha@estresse.invalid'"
fila
R2=$(sql "SELECT CONCAT(status,'/',tentativas) FROM fila_emails WHERE para='fila-velha@estresse.invalid' ORDER BY id DESC LIMIT 1")
chk "parado ha mais de 24 h, passa a contar tentativas (nao tenta para sempre)" "$([ "$R2" = "pendente/1" ] && echo 1)" "$R2"
sql "UPDATE configuracoes SET valor='sink' WHERE chave='smtp_usuario'"

echo; echo "=== T12 TLS: certificado do servidor SMTP e VERIFICADO (autoassinado deve ser recusado)"
cd /root/smtp_test && openssl req -x509 -newkey rsa:2048 -nodes -keyout k.pem -out c.pem -days 2 -subj "/CN=127.0.0.1" >/dev/null 2>&1
SINK_PORTA=2527 SINK_CERT=/root/smtp_test/c.pem SINK_KEY=/root/smtp_test/k.pem setsid nohup python3 /root/smtp_test/sink.py > /root/smtp_test/sink2.log 2>&1 < /dev/null & echo $! > /root/smtp_test/sink2.pid; sleep 1
sql "UPDATE configuracoes SET valor='2527' WHERE chave='smtp_porta'"; sql "UPDATE configuracoes SET valor='tls' WHERE chave='smtp_seguranca'"
cat > /tmp/t_tls.php <<'PHPEOF'
<?php
chdir("/var/www/painel"); require "conexao.php"; require "funcoes/email.php";
$ok = enviarEmail("caixa@estresse.invalid", "Teste TLS", "<p>x</p>");
echo $ok ? "ENVIOU\n" : "RECUSOU: " . emailUltimoErro() . "\n";
PHPEOF
chmod 644 /tmp/t_tls.php; TLS=$(runuser -u www-data -- php /tmp/t_tls.php); rm -f /tmp/t_tls.php
chk "servidor com certificado autoassinado e RECUSADO (nao entrega credenciais)" "$(echo "$TLS" | grep -q 'RECUSOU' && echo 1)" "$TLS"
sql "UPDATE configuracoes SET valor='2526' WHERE chave='smtp_porta'"; sql "UPDATE configuracoes SET valor='nenhuma' WHERE chave='smtp_seguranca'"
kill "$(cat /root/smtp_test/sink2.pid)" 2>/dev/null; rm -f /root/smtp_test/k.pem /root/smtp_test/c.pem

echo; echo "=== T13 cabecalhos: injecao por quebra de linha"
zera
cat > /tmp/t_hdr.php <<'PHPEOF'
<?php
chdir("/var/www/painel"); require "conexao.php"; require "funcoes/email.php";
echo enviarEmail("a@estresse.invalid\r\nBcc: vitima@estresse.invalid", "x", "<p>x</p>") ? "ENVIOU" : "RECUSOU", "\n";
echo enviarEmail("b@estresse.invalid", "Assunto\r\nBcc: vitima@estresse.invalid", "<p>x</p>") ? "ENVIOU" : "RECUSOU", "\n";
PHPEOF
chmod 644 /tmp/t_hdr.php; HD=$(runuser -u www-data -- php /tmp/t_hdr.php); rm -f /tmp/t_hdr.php
chk "destinatario com CRLF+Bcc recusado" "$([ "$(echo "$HD" | sed -n 1p)" = RECUSOU ] && echo 1)" "$HD"
chk "assunto com CRLF+Bcc nao cria cabecalho Bcc" "$([ "$(grep -il '^Bcc:' $S/*.eml 2>/dev/null | wc -l)" = 0 ] && echo 1)"
chk "ninguem recebeu copia para vitima@" "$([ "$(grep -l 'vitima@' $S/*.eml 2>/dev/null | wc -l)" = 0 ] && echo 1)"

echo; echo "=== T14 token do link de confirmacao: aleatorio, so o hash no banco, substituivel, expira, so hex"
cat > /tmp/t_tok.php <<'PHPEOF'
<?php
chdir("/var/www/painel"); require "conexao.php"; require "funcoes/usuario.php";
$id = (int) $pdo->query("SELECT id FROM usuarios WHERE nome='Teste Rec 3'")->fetchColumn();
$t1 = criarTokenVerificacaoEmail($id);
$t2 = criarTokenVerificacaoEmail($id);
echo "formato: ", preg_match('/^[a-f0-9]{64}$/', (string) $t1) ? "64 hex" : "RUIM", "\n";
echo "plaintext no banco: ", (int) $pdo->query("SELECT COUNT(*) FROM tokens_email WHERE token_hash IN ('$t1','$t2')")->fetchColumn(), " (esperado 0)\n";
echo "hash no banco: ", (int) $pdo->query("SELECT COUNT(*) FROM tokens_email WHERE token_hash = '" . hash('sha256', $t2) . "'")->fetchColumn(), " (esperado 1)\n";
echo "antigo apos gerar novo: ", confirmarEmailPorToken($t1)['mensagem'], "\n";
echo "lixo (nao hex): ", confirmarEmailPorToken("' OR 1=1 -- ")['mensagem'], "\n";
$pdo->exec("UPDATE tokens_email SET expira_em = NOW() - INTERVAL 1 HOUR WHERE token_hash = '" . hash('sha256', $t2) . "'");
echo "expirado: ", confirmarEmailPorToken($t2)['mensagem'], "\n";
$t3 = criarTokenVerificacaoEmail($id);
echo "valido: ", confirmarEmailPorToken($t3)['mensagem'], "\n";
echo "reuso: ", confirmarEmailPorToken($t3)['mensagem'], "\n";
PHPEOF
chmod 644 /tmp/t_tok.php; TK2=$(runuser -u www-data -- php /tmp/t_tok.php); rm -f /tmp/t_tok.php
echo "$TK2" | sed 's/^/    /'
chk "token: 64 hex" "$(echo "$TK2" | grep -q 'formato: 64 hex' && echo 1)"
chk "token: texto puro NAO fica no banco, so o hash" "$(echo "$TK2" | grep -q 'plaintext no banco: 0' && echo "$TK2" | grep -q 'hash no banco: 1' && echo 1)"
chk "token antigo morre quando se pede um novo" "$(echo "$TK2" | grep -q 'antigo apos gerar novo: Link inválido ou já substituído' && echo 1)"
chk "entrada que nao e hex e recusada" "$(echo "$TK2" | grep -q 'lixo (nao hex): Link inválido' && echo 1)"
chk "token expirado e recusado" "$(echo "$TK2" | grep -q 'expirado: Este link expirou' && echo 1)"
chk "token valido confirma e reuso e inofensivo" "$(echo "$TK2" | grep -q 'valido: E-mail confirmado' && echo "$TK2" | grep -q 'reuso: Este e-mail já estava confirmado' && echo 1)"

echo; echo "=== limpeza: contas e linhas de teste"
sql "DELETE FROM usuarios WHERE nome LIKE 'Teste Rec %' OR nome LIKE 'Teste Cad %'"
sql "DELETE FROM fila_emails WHERE para LIKE '%@estresse.invalid'"; sql "DELETE FROM email_envios WHERE para LIKE '%@estresse.invalid'"; sql "DELETE FROM limites_taxa"
echo "  contas de teste restantes: $(sql "SELECT COUNT(*) FROM usuarios WHERE email LIKE 'rec-teste-%'")"
echo; echo "=== resumo da parte 2: $OK ok, $FALHA falha(s)"
