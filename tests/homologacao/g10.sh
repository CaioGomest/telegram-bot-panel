#!/bin/bash
# GUIADO 10 (09/10/2026): "starts" do dashboard contam cada /start (lead novo ou quem voltou), não só lead novo.
source /tmp/pt/lib.sh
BOT='ids()["bot"]'
STARTS='q1("SELECT COUNT(*) FROM leads_starts WHERE bot_id=?",['"$BOT"'])'
LEADS='q1("SELECT COUNT(*) FROM leads WHERE bot_id=?",['"$BOT"'])'
HOJE='q1("SELECT COUNT(*) FROM leads_starts WHERE bot_id=? AND DATE(criado_em)=CURDATE()",['"$BOT"'])'
BOTID=$(php -r 'echo json_decode(file_get_contents("/tmp/pt/ids.json"),true)["bot"];')

secao "G10.1 Cada /start vira um start; repetição em 10 min não conta de novo"
janela; reset; setflow guiado_base '{}'
msg "/start" Maria >/dev/null
chk "lead novo: 1 lead e 1 start" "$LEADS"'==1 && '"$STARTS"'==1'
msg "/start" Maria >/dev/null
chk "/start repetido em seguida: continua 1 lead e 1 start (não duplica)" "$LEADS"'==1 && '"$STARTS"'==1'
sql "UPDATE leads_starts SET criado_em = NOW() - INTERVAL 11 MINUTE WHERE bot_id=$BOTID" >/dev/null
msg "/start" Maria >/dev/null
chk "depois de 10 min: o mesmo lead volta e conta um novo start (1 lead, 2 starts)" "$LEADS"'==1 && '"$STARTS"'==2'

secao "G10.2 Cenário do painel: lead antigo compra hoje (antes: 0 starts com vendas)"
janela; reset; setflow guiado_base '{}'
msg "/start" Maria >/dev/null
sql "UPDATE leads SET criado_em = NOW() - INTERVAL 9 DAY WHERE bot_id=$BOTID" >/dev/null
sql "UPDATE leads_starts SET criado_em = NOW() - INTERVAL 9 DAY WHERE bot_id=$BOTID" >/dev/null
msg "/start" Maria >/dev/null
mark; cb "basico::plano::p_mensal" >/dev/null
chk "lead criado há 9 dias que volta e gera Pix hoje: 0 leads novos hoje, mas 1 start hoje" 'q1("SELECT COUNT(*) FROM leads WHERE bot_id=? AND DATE(criado_em)=CURDATE()",['"$BOT"'])==0 && '"$HOJE"'==1 && q1("SELECT COUNT(*) FROM vendas WHERE bot_id=?",['"$BOT"'])==1'

secao "G10.3 Cache de métricas por hora usa os starts"
UIDT=$(php -r 'echo json_decode(file_get_contents("/tmp/pt/ids.json"),true)["uid"];')
sql "DELETE FROM metricas_horarias_usuario WHERE id_usuario=$UIDT AND data=CURDATE()" >/dev/null
cron cron_metricas_admin
chk "metricas_horarias_usuario.qtd_leads de hoje = starts de hoje (1)" 'q1("SELECT COALESCE(SUM(qtd_leads),0) FROM metricas_horarias_usuario WHERE id_usuario=? AND data=CURDATE()",[ids()["uid"]])==1'
