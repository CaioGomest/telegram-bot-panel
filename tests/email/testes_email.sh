#!/usr/bin/env bash
# Testes dos envios de e-mail / recuperacao de senha / limites. Roda NA VPS, contra o painel real, com o SMTP apontado para o sink.
# As requisicoes saem de 127.0.0.1 (nao gastam o limite do IP de quem esta no navegador).
H=${PAINEL_HOST:-srv2059665.hstgr.cloud}
S=/tmp/sink
OK=0; FALHA=0
chk() { if [ "$2" = "1" ]; then OK=$((OK+1)); echo "  [ok]    $1"; else FALHA=$((FALHA+1)); echo "  [FALHA] $1 ${3:+-> $3}"; fi; }
curlv() { curl -sk --resolve $H:443:127.0.0.1 "$@"; }
api() { curlv -b "$1" -c "$1" -H 'Content-Type: application/json' -X POST -d "$3" "https://$H/funcoes/usuario.php?api=usuario&acao=$2" | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo json_encode($j, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);'; }
jq_() { php -r '$j=json_decode(stream_get_contents(STDIN),true); $c=$argv[1]; $v=$j[$c]??null; echo is_bool($v)?($v?"true":"false"):(string)$v;' "$1"; }
nsink() { ls $S/*.eml 2>/dev/null | wc -l; }
zera() { mysql painel -e "DELETE FROM limites_taxa"; rm -f $S/*.eml; }
recuar() { mysql painel -e "UPDATE limites_taxa SET janela_inicio = NOW() - INTERVAL 120 SECOND WHERE chave LIKE '$1%'"; }
sql() { mysql painel -N -e "$1"; }
novajar() { mktemp /tmp/jar.XXXXXX; }
iniciar_sink() { kill "$(cat /root/smtp_test/sink.pid)" 2>/dev/null; sleep 0.6; SINK_ATRASO=$1 SINK_PORTA=2526 setsid nohup python3 /root/smtp_test/sink.py > /root/smtp_test/sink.log 2>&1 < /dev/null & echo $! > /root/smtp_test/sink.pid; sleep 1; }

echo "=== preparo: 4 contas de teste (apagadas no fim)"
HASH=$(php -r 'echo password_hash("SenhaAntiga#1", PASSWORD_DEFAULT);')
for n in 1 2 3 4; do sql "INSERT IGNORE INTO usuarios (nome, email, senha, perfil) VALUES ('Teste Rec $n', 'rec-teste-$n@estresse.invalid', '$HASH', 'usuario')"; done
sql "UPDATE usuarios SET senha = '$HASH' WHERE email LIKE 'rec-teste-%@estresse.invalid'"
sql "UPDATE usuarios SET email_verificado_em = NOW() WHERE email IN ('rec-teste-1@estresse.invalid','rec-teste-2@estresse.invalid','rec-teste-4@estresse.invalid')"
sql "UPDATE usuarios SET email_verificado_em = NULL WHERE email = 'rec-teste-3@estresse.invalid'"
echo "  contas: $(sql "SELECT COUNT(*) FROM usuarios WHERE email LIKE 'rec-teste-%@estresse.invalid'")"

echo; echo "=== T1  intervalo entre pedidos (1 por minuto por e-mail) e clique repetido"
zera; J=$(novajar)
r1=$(api $J enviar_codigo_senha_login '{"email":"rec-teste-1@estresse.invalid"}')
r2=$(api $J enviar_codigo_senha_login '{"email":"rec-teste-1@estresse.invalid"}')
sleep 2
chk "1o pedido aceito" "$([ "$(echo "$r1" | jq_ sucesso)" = true ] && echo 1)" "$r1"
chk "2o pedido logo em seguida e recusado com tempo de espera" "$([ "$(echo "$r2" | jq_ sucesso)" = false ] && [ "$(echo "$r2" | jq_ espera)" -ge 50 ] && echo 1)" "$r2"
chk "so 1 e-mail saiu" "$([ "$(nsink)" = 1 ] && echo 1)" "saiu $(nsink)"
zera; J=$(novajar)
for i in 1 2 3 4 5 6; do api $J enviar_codigo_senha_login '{"email":"rec-teste-2@estresse.invalid"}' > /tmp/par_$i.json & done; wait
aceitos=0; for i in 1 2 3 4 5 6; do [ "$(jq_ sucesso < /tmp/par_$i.json)" = true ] && aceitos=$((aceitos+1)); done; rm -f /tmp/par_*.json; sleep 2
chk "6 cliques SIMULTANEOS: so 1 aceito (limite atomico)" "$([ $aceitos = 1 ] && echo 1)" "aceitos=$aceitos"
chk "6 cliques SIMULTANEOS: so 1 e-mail" "$([ "$(nsink)" = 1 ] && echo 1)" "saiu $(nsink)"

echo; echo "=== T2  limite por hora por e-mail (3/h) e variacao de maiusculas"
zera; J=$(novajar)
for i in 1 2 3; do api $J enviar_codigo_senha_login '{"email":"rec-teste-1@estresse.invalid"}' > /dev/null; recuar rec_cd; done
r4=$(api $J enviar_codigo_senha_login '{"email":"REC-TESTE-1@Estresse.Invalid"}')
sleep 2
chk "4o pedido na mesma hora (e-mail em MAIUSCULAS) e recusado" "$([ "$(echo "$r4" | jq_ sucesso)" = false ] && echo "$r4" | grep -q 'demais' && echo 1)" "$r4"
chk "3 e-mails saíram, o 4o nao" "$([ "$(nsink)" = 3 ] && echo 1)" "saiu $(nsink)"

echo; echo "=== T3  limite por IP (10/h): e-mails que existem e que NAO existem contam igual; resposta idêntica"
zera; J=$(novajar); bloqueou=0
rExiste=$(api $J enviar_codigo_senha_login '{"email":"rec-teste-4@estresse.invalid"}')
rNaoExiste=$(api $J enviar_codigo_senha_login '{"email":"ninguem-existe-aqui@estresse.invalid"}')
chk "resposta para e-mail que existe e que nao existe e IDENTICA" "$([ "$rExiste" = "$rNaoExiste" ] && echo 1)" "A=$rExiste B=$rNaoExiste"
for i in $(seq 1 10); do r=$(api $J enviar_codigo_senha_login "{\"email\":\"lixo-$i@estresse.invalid\"}"); echo "$r" | grep -q 'deste endereço' && bloqueou=$((bloqueou+1)); done
chk "depois de 10 pedidos do mesmo IP (qualquer e-mail), os seguintes sao recusados" "$([ $bloqueou -ge 1 ] && echo 1)" "bloqueados=$bloqueou"
sleep 2
chk "só a conta que existia recebeu e-mail (nenhum para os 'lixo-*')" "$([ "$(grep -l 'lixo-' $S/*.eml 2>/dev/null | wc -l)" = 0 ] && echo 1)"

echo; echo "=== T4  tempo de resposta NAO revela se a conta existe (SMTP lento: 2,5 s)"
iniciar_sink 2.5
zera
t_existe=$(curlv -o /dev/null -w '%{time_total}' -H 'Content-Type: application/json' -X POST -d '{"email":"rec-teste-4@estresse.invalid"}' "https://$H/funcoes/usuario.php?api=usuario&acao=enviar_codigo_senha_login")
t_nao=$(curlv -o /dev/null -w '%{time_total}' -H 'Content-Type: application/json' -X POST -d '{"email":"outro-inexistente@estresse.invalid"}' "https://$H/funcoes/usuario.php?api=usuario&acao=enviar_codigo_senha_login")
echo "  tempo (conta existe, SMTP leva 2,5 s): ${t_existe}s | tempo (conta nao existe): ${t_nao}s"
chk "resposta da conta que EXISTE volta rapido (< 1,2 s) mesmo com SMTP lento" "$(awk -v t=$t_existe 'BEGIN{print (t<1.2)?1:0}')" "${t_existe}s"
chk "diferenca entre existir e nao existir < 0,5 s" "$(awk -v a=$t_existe -v b=$t_nao 'BEGIN{d=a-b; if(d<0)d=-d; print (d<0.5)?1:0}')"
sleep 4
chk "o e-mail da conta que existe saiu mesmo assim (depois da resposta)" "$([ "$(nsink)" = 1 ] && echo 1)" "saiu $(nsink)"
iniciar_sink 0

echo; echo "=== T5  codigo: 5 erros queimam o codigo; codigo certo depois disso nao vale; novo codigo vale uma vez"
zera; J=$(novajar)
api $J enviar_codigo_senha_login '{"email":"rec-teste-2@estresse.invalid"}' > /dev/null; sleep 2
COD=$(python3 /root/smtp_test/inbox.py codigo rec-teste-2@estresse.invalid)
chk "codigo de 6 digitos chegou no e-mail" "$(echo "$COD" | grep -qE '^[0-9]{6}$' && echo 1)" "$COD"
ERRADO=$([ "$COD" = 000000 ] && echo 111111 || echo 000000)
for i in 1 2 3 4; do r=$(api $J trocar_senha_login "{\"email\":\"rec-teste-2@estresse.invalid\",\"codigo\":\"$ERRADO\",\"nova_senha\":\"NovaSenha#2026\"}"); done
chk "4 erros: ainda 'invalido ou expirado'" "$(echo "$r" | grep -q 'inválido ou expirado' && echo 1)" "$r"
r5=$(api $J trocar_senha_login "{\"email\":\"rec-teste-2@estresse.invalid\",\"codigo\":\"$ERRADO\",\"nova_senha\":\"NovaSenha#2026\"}")
chk "5o erro cancela o codigo" "$(echo "$r5" | grep -q 'cancelado' && echo 1)" "$r5"
rcerto=$(api $J trocar_senha_login "{\"email\":\"rec-teste-2@estresse.invalid\",\"codigo\":\"$COD\",\"nova_senha\":\"NovaSenha#2026\"}")
chk "o codigo CERTO nao vale mais depois de queimado" "$(echo "$rcerto" | grep -q 'inválido ou expirado' && echo 1)" "$rcerto"
chk "a senha NAO mudou" "$(sql "SELECT senha FROM usuarios WHERE email='rec-teste-2@estresse.invalid'" | php -r '$h=trim(stream_get_contents(STDIN)); echo password_verify("SenhaAntiga#1",$h)?1:0;')"
recuar rec_cd; rm -f $S/*.eml
api $J enviar_codigo_senha_login '{"email":"rec-teste-2@estresse.invalid"}' > /dev/null; sleep 2
COD2=$(python3 /root/smtp_test/inbox.py codigo rec-teste-2@estresse.invalid)
chk "novo pedido gera OUTRO codigo (o antigo morreu)" "$([ "$COD2" != "$COD" ] && echo 1)" "$COD / $COD2"
J2=$(novajar)
rcruz=$(api $J2 trocar_senha_login "{\"email\":\"rec-teste-2@estresse.invalid\",\"codigo\":\"$COD2\",\"nova_senha\":\"NovaSenha#2026\"}")
chk "o codigo NAO vale em outro navegador/sessao (codigo e da sessao de quem pediu)" "$(echo "$rcruz" | grep -q 'inválido ou expirado' && echo 1)" "$rcruz"
rok=$(api $J trocar_senha_login "{\"email\":\"REC-TESTE-2@estresse.invalid\",\"codigo\":\"$COD2\",\"nova_senha\":\"NovaSenha#2026\"}")
chk "codigo novo, na sessao certa, troca a senha" "$([ "$(echo "$rok" | jq_ sucesso)" = true ] && echo 1)" "$rok"
chk "senha nova funciona e a antiga nao" "$(sql "SELECT senha FROM usuarios WHERE email='rec-teste-2@estresse.invalid'" | php -r '$h=trim(stream_get_contents(STDIN)); echo (password_verify("NovaSenha#2026",$h) && !password_verify("SenhaAntiga#1",$h))?1:0;')"
rreuso=$(api $J trocar_senha_login "{\"email\":\"rec-teste-2@estresse.invalid\",\"codigo\":\"$COD2\",\"nova_senha\":\"OutraSenha#2026\"}")
chk "o codigo e de uso unico (reuso recusado)" "$(echo "$rreuso" | grep -q 'inválido ou expirado' && echo 1)" "$rreuso"
sleep 1; mysql painel -e "SELECT 1" >/dev/null
echo "  (fila: processando para sair o aviso de senha alterada)"
runuser -u www-data -- php -r 'chdir("/var/www/painel"); require "conexao.php"; require "funcoes/email.php"; processarFilaEmails($pdo);'
chk "aviso 'Sua senha foi alterada' enviado" "$(python3 /root/smtp_test/inbox.py assuntos | grep -q 'Sua senha foi alterada' && echo 1)"

echo; echo "=== T6  limite de tentativas por IP (20/h) nas trocas de senha"
zera; J=$(novajar); bloq=0
for i in $(seq 1 22); do r=$(api $J trocar_senha_login '{"email":"x@estresse.invalid","codigo":"123456","nova_senha":"SenhaQualquer1"}'); echo "$r" | grep -q 'deste endereço' && bloq=$((bloq+1)); done
chk "depois de 20 tentativas erradas do mesmo IP, as seguintes sao recusadas" "$([ $bloq -ge 1 ] && echo 1)" "bloqueadas=$bloq"

echo; echo "=== T7  validacao de entrada"
zera; J=$(novajar)
rinv=$(api $J enviar_codigo_senha_login '{"email":"isto nao e email"}')
chk "e-mail invalido e recusado sem gastar limite" "$(echo "$rinv" | grep -q 'e-mail válido' && [ "$(sql 'SELECT COUNT(*) FROM limites_taxa')" = 0 ] && echo 1)" "$rinv"
rlongo=$(api $J enviar_codigo_senha_login "{\"email\":\"$(printf 'a%.0s' $(seq 1 200))@estresse.invalid\"}")
chk "e-mail com 200+ caracteres e recusado" "$(echo "$rlongo" | grep -q 'e-mail válido' && echo 1)"
rsenhacurta=$(api $J trocar_senha_login '{"email":"a@b.com","codigo":"123456","nova_senha":"curta"}')
chk "senha curta (<8) recusada" "$(echo "$rsenhacurta" | grep -q '8 caracteres' && echo 1)"
rsenhagrande=$(api $J trocar_senha_login "{\"email\":\"a@b.com\",\"codigo\":\"123456\",\"nova_senha\":\"$(printf 'x%.0s' $(seq 1 250))\"}")
chk "senha gigante (>200) recusada" "$(echo "$rsenhagrande" | grep -q 'grande demais' && echo 1)"

echo; echo "=== resumo: $OK ok, $FALHA falha(s)"
