#!/bin/bash
# SEGURANÇA 1: o usuário não pode ligar o PRÓPRIO bot a um fluxo de OUTRO usuário (salvar_bot / atualizar_perfil_bot).
source /tmp/pt/lib.sh
BOTID=$(php -r 'echo json_decode(file_get_contents("/tmp/pt/ids.json"),true)["bot"];')
UID_=$(php -r 'echo json_decode(file_get_contents("/tmp/pt/ids.json"),true)["uid"];')
ALHEIO=$(php /tmp/pt/db.php "SELECT id i FROM fluxos WHERE id_usuario<>$UID_ ORDER BY id DESC LIMIT 1" | php -r '$j=json_decode(trim(stream_get_contents(STDIN)),true); echo $j["i"];')
secao "S1 Fluxo de outro usuário não pode ser vinculado ao meu bot"
reset
sql "UPDATE bots SET id_fluxo_conectado=NULL WHERE id=$BOTID" >/dev/null
mark; api POST atualizar_perfil_bot "{\"id\":$BOTID,\"nome\":\"Bot Teste\",\"descricao\":\"d\",\"descricao_curta\":\"c\",\"id_fluxo_conectado\":$ALHEIO}"
chk "atualizar_perfil_bot com fluxo alheio: não vincula" 'q1("SELECT id_fluxo_conectado FROM bots WHERE id=?",[ids()["bot"]])!='"$ALHEIO"
mark; api POST salvar_bot "{\"id\":$BOTID,\"token\":\"$TOKEN\",\"id_fluxo_conectado\":$ALHEIO}"
chk "salvar_bot (editar) com fluxo alheio: não vincula" 'q1("SELECT id_fluxo_conectado FROM bots WHERE id=?",[ids()["bot"]])!='"$ALHEIO"
mark; api POST salvar_bot "{\"token\":\"SIMBOT:NOVO-S1\",\"id_fluxo_conectado\":$ALHEIO}"
chk "salvar_bot (novo bot) com fluxo alheio: o bot novo não nasce vinculado" 'q1("SELECT COUNT(*) FROM bots WHERE token=\"SIMBOT:NOVO-S1\" AND id_fluxo_conectado='"$ALHEIO"'")==0'
sql "DELETE FROM bots WHERE token='SIMBOT:NOVO-S1'" >/dev/null
OWN=$(php /tmp/pt/db.php "SELECT id i FROM fluxos WHERE id_usuario=$UID_ ORDER BY id DESC LIMIT 1" | php -r '$j=json_decode(trim(stream_get_contents(STDIN)),true); echo $j["i"] ?? "";')
if [ -n "$OWN" ]; then
  mark; api POST atualizar_perfil_bot "{\"id\":$BOTID,\"nome\":\"Bot Teste\",\"descricao\":\"d\",\"descricao_curta\":\"c\",\"id_fluxo_conectado\":$OWN}"
  chk "fluxo do PRÓPRIO usuário continua podendo ser vinculado" 'q1("SELECT id_fluxo_conectado FROM bots WHERE id=?",[ids()["bot"]])=='"$OWN"
fi
# restaura o bot de teste (o salvar_bot simulado regrava id_bot_telegram/nome e atrapalharia as suítes seguintes)
sql "UPDATE bots SET id_bot_telegram=7000001, nome_usuario='sim_homolog_bot', primeiro_nome='Bot Homolog', id_fluxo_conectado=NULL WHERE id=$BOTID" >/dev/null
