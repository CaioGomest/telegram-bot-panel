#!/bin/bash
# VISUAL 3: delay (curto inline, médio no webhook, longo agendado pela cron), retomada, cancelamento.
source /tmp/pt/lib.sh
BOTID=$(php -r 'echo json_decode(file_get_contents("/tmp/pt/ids.json"),true)["bot"];')
CONT='q("SELECT * FROM fluxo_continuacoes WHERE bot_id=? ORDER BY id",[ids()["bot"]])'

secao "V3.1 Delays curto, médio e longo no mesmo fluxo"
reset; setflow v_delays
T0=$(date +%s); mark; msg "/start"; T1=$(date +%s); ELAPSED=$((T1 - T0))
echo "  (tempo da requisição: ${ELAPSED}s)"
chk "mensagens saem na ordem; o delay longo (3 min) para o fluxo (não envia 'DEPOIS do delay longo')" 'textos()==["ANTES do delay curto","DEPOIS do delay curto","DEPOIS do delay médio"]'
chk "delay curto (2s) e médio (7s) 'digitando…': sendChatAction typing enviado" 'count(tg("sendChatAction"))>=3 && tg("sendChatAction")[0]["p"]["action"]=="typing"'
[ $ELAPSED -ge 9 ] && [ $ELAPSED -le 20 ] && echo "  PASS | espera real de ~9 s (2 + 7) dentro da requisição (levou ${ELAPSED}s)" || echo "  FAIL | espera de ~9 s dentro da requisição: levou ${ELAPSED}s"
chk "delay longo agendado: 1 continuação pendente, destino 'd', ~180 s no futuro" '($c='"$CONT"') && count($c)==1 && $c[0]["status"]=="pendente" && $c[0]["id_operador_destino"]=="d" && abs(strtotime($c[0]["executar_em"])-time()-180)<10'
chk "sem erro/aviso de PHP" 'semerros()'
janela
sql "UPDATE fluxo_continuacoes SET executar_em = NOW() - INTERVAL 1 SECOND WHERE bot_id=$BOTID" >/dev/null
mark; cron cron_aviso_vencimento
chk "cron retoma: envia 'DEPOIS do delay longo (cron)' e marca a continuação como feita" 'textos()==["DEPOIS do delay longo (cron)"] && ('"$CONT"')[0]["status"]=="feito"'
mark; cron cron_aviso_vencimento
chk "2ª rodada do cron não reenvia" 'count(tg("sendMessage"))==0'

secao "V3.2 /start cancela esperas pendentes"
reset; setflow v_delays '{"operators":{"d1":{"properties":{"delay_min":0,"delay_max":0}},"d2":{"properties":{"delay_min":0,"delay_max":0}}}}'
mark; msg "/start"
chk "sem delays curtos: já chega no delay longo e agenda" 'count('"$CONT"')==1 && textos()==["ANTES do delay curto","DEPOIS do delay curto","DEPOIS do delay médio"]'
janela
sql "UPDATE fluxo_continuacoes SET executar_em = NOW() - INTERVAL 1 SECOND WHERE bot_id=$BOTID" >/dev/null
mark; msg "/start"
chk "novo /start cancela a espera antiga (status cancelado) e cria uma nova" '($c='"$CONT"') && $c[0]["status"]=="cancelado" && $c[1]["status"]=="pendente"'
mark; cron cron_aviso_vencimento
chk "cron não dispara a espera cancelada (a nova ainda não venceu)" 'count(tg("sendMessage"))==0'

secao "V3.3 Delay aleatório (mín/máx), unidade e 'digitando'"
reset; setflow v_delays '{"operators":{"d1":{"properties":{"delay_min":1,"delay_max":3,"delay_digitando":false}},"d2":{"properties":{"delay_min":0,"delay_max":0}},"d3":{"properties":{"delay_min":0,"delay_max":0}}}}'
MIN=999999; MAX=0
for i in 1 2 3 4 5 6; do T0=$(date +%s%N); msg "/start" >/dev/null; T1=$(date +%s%N); MS=$(( (T1 - T0) / 1000000 )); [ $MS -lt $MIN ] && MIN=$MS; [ $MS -gt $MAX ] && MAX=$MS; done
echo "  (6 execuções: menor ${MIN}ms, maior ${MAX}ms)"
[ $MIN -ge 1000 ] && [ $MAX -le 4800 ] && echo "  PASS | espera sorteada entre 1 e 3 s respeitada nas 6 execuções" || echo "  FAIL | espera sorteada (1-3 s) fora do esperado: ${MIN}-${MAX} ms"
chk "delay_digitando=false: nenhum 'digitando…'" 'count(tg("sendChatAction"))==0'
reset; setflow v_delays '{"operators":{"d1":{"properties":{"delay_min":0,"delay_max":0}},"d2":{"properties":{"delay_min":0,"delay_max":0}},"d3":{"properties":{"delay_min":99999,"delay_max":99999,"delay_unidade":"min"}}}}'
mark; msg "/start"
chk "delay absurdo (99999 min) é limitado a 7 dias" '($c='"$CONT"') && count($c)==1 && abs(strtotime($c[0]["executar_em"])-time()-604800)<30'
reset; setflow v_delays '{"operators":{"d1":{"properties":{"delay_min":0,"delay_max":0}},"d2":{"properties":{"delay_min":0,"delay_max":0}},"d3":{"properties":{"delay_min":0,"delay_max":0}}}}'
mark; msg "/start"
chk "delays com 0: não esperam nem agendam; fluxo inteiro sai de uma vez" 'count(tg("sendMessage"))==4 && count('"$CONT"')==0 && count(tg("sendChatAction"))==0'
reset; setflow v_delays '{"operators":{"d3":{"properties":{"delay_min":3,"delay_max":3,"delay_unidade":"min"}}},"links!":[{"fromOperator":"start","fromConnector":"output_1","toOperator":"d3","toConnector":"input_1"}]}'
mark; msg "/start"
chk "delay logo no começo, sem nada depois: nada agendado" 'count('"$CONT"')==0 && semerros()'

secao "V3.4 Esperas encadeadas e retomada por contexto"
reset; setflow v_delays '{"operators":{"d1":{"properties":{"delay_min":0,"delay_max":0}},"d2":{"properties":{"delay_min":30,"delay_max":30,"delay_unidade":"seg"}}}}'
mark; msg "/start"
chk "delay de 30 s (> 10 s) também é agendado pela cron (não segura o webhook)" 'textos()==["ANTES do delay curto","DEPOIS do delay curto"] && ($c='"$CONT"') && count($c)==1 && $c[0]["id_operador_destino"]=="c"'
janela
sql "UPDATE fluxo_continuacoes SET executar_em = NOW() - INTERVAL 1 SECOND WHERE bot_id=$BOTID" >/dev/null
mark; cron cron_aviso_vencimento
chk "1ª retomada: 'DEPOIS do delay médio' e já agenda o delay longo seguinte (3 min)" 'textos()==["DEPOIS do delay médio"] && count('"$CONT"')==2 && ('"$CONT"')[1]["status"]=="pendente" && ('"$CONT"')[1]["id_operador_destino"]=="d"'
sql "UPDATE fluxo_continuacoes SET executar_em = NOW() - INTERVAL 1 SECOND WHERE bot_id=$BOTID AND status='pendente'" >/dev/null
mark; cron cron_aviso_vencimento
chk "2ª retomada: 'DEPOIS do delay longo (cron)'" 'textos()==["DEPOIS do delay longo (cron)"]'
reset; setflow v_delays '{"operators":{"d1":{"properties":{"delay_min":0,"delay_max":0}},"d2":{"properties":{"delay_min":0,"delay_max":0}}}}'
msg "/start" >/dev/null
sql "UPDATE bots SET id_fluxo_conectado=NULL WHERE id=$BOTID" >/dev/null
sql "DELETE FROM fluxos WHERE id=$(php -r 'echo json_decode(file_get_contents("/tmp/pt/ids.json"),true)["fluxo"];')" >/dev/null
janela
sql "UPDATE fluxo_continuacoes SET executar_em = NOW() - INTERVAL 1 SECOND WHERE bot_id=$BOTID" >/dev/null
mark; cron cron_aviso_vencimento
chk "fluxo apagado enquanto havia espera: a continuação vira 'falhou' sem derrubar a cron" 'semerros() && count(tg("sendMessage"))==0 && ('"$CONT"')[0]["status"]=="falhou"'
echo; echo "FIM V3"
