#!/bin/bash
# GUIADO 5: avisos de vencimento, expiração/remoção do grupo, carência de assinatura, renovação manual.
source /tmp/pt/lib.sh
BOTID=$(php -r 'echo json_decode(file_get_contents("/tmp/pt/ids.json"),true)["bot"];')
UID_=$(php -r 'echo json_decode(file_get_contents("/tmp/pt/ids.json"),true)["uid"];')
VENDA='q("SELECT * FROM vendas WHERE bot_id=? ORDER BY id DESC LIMIT 1",[ids()["bot"]])[0]'
MEMBRO='q("SELECT * FROM membros_grupos WHERE bot_id=? ORDER BY id DESC LIMIT 1",[ids()["bot"]])[0]'
HORA=$(php -r 'date_default_timezone_set("America/Sao_Paulo"); echo date("H");')
ASS_ANTES='{"assinatura":{"avisos_antes":{"ativo":true},"avisos_no_dia":{"ativo":false}}}'
# comprar+pagar: deixa um membro ativo (plano $1, padrão p_mensal)
membro() { janela; reset; setflow guiado_base "${2:-$ASS_ANTES}"; msg "/start" Maria >/dev/null; mark; cb "basico::plano::${1:-p_mensal}" >/dev/null; TX=$(ultimotx); pagar "$TX"; cb "verificar_pagamento_$TX"; mark; }
ajusta() { janela; sql "UPDATE membros_grupos SET criado_em = NOW() - INTERVAL $1, data_expiracao = $2 WHERE bot_id=$BOTID" >/dev/null; }

secao "G5.1 Avisos antes do vencimento (aba Assinatura)"
membro p_mensal
ajusta "26 DAY" "NOW() + INTERVAL 108 HOUR"
mark; cron cron_aviso_vencimento
chk "faltam ~4,5 dias: sai o aviso de 5 dias, uma vez, com as variáveis preenchidas" 'count(tg("sendMessage"))==1 && preg_match("/^(Bom dia|Boa tarde|Boa noite), Maria Teste! Seu plano Mensal expira em [45] dias \(\d\d\/\d\d\/\d{4}\)\. Faltam [45] dia\(s\)\.$/u", txt(ultimo("sendMessage")))'
chk "aviso traz o botão 'Renovar agora' (basico::renovar)" 'cbs(ultimo("sendMessage"))==["basico::renovar"] && botoes(ultimo("sendMessage"))[0]["text"]=="🔄 Renovar agora"'
chk "marco d5 gravado (formato d5@AAAAMMDD)" 'q1("SELECT COUNT(*) FROM avisos_assinatura_enviados a JOIN membros_grupos m ON m.id=a.membro_id WHERE m.bot_id=? AND a.marco LIKE \"d5@%\"",[ids()["bot"]])==1'
mark; cron cron_aviso_vencimento
chk "cron roda de novo no mesmo minuto/dia: não repete o aviso" 'count(tg("sendMessage"))==0'
ajusta "26 DAY" "NOW() + INTERVAL 36 HOUR"
mark; cron cron_aviso_vencimento
chk "faltam 1,5 dia: sai o aviso de 2 dias" 'count(tg("sendMessage"))==1 && q1("SELECT COUNT(*) FROM avisos_assinatura_enviados a JOIN membros_grupos m ON m.id=a.membro_id WHERE m.bot_id=? AND a.marco LIKE \"d2@%\"",[ids()["bot"]])==1'
ajusta "26 DAY" "NOW() + INTERVAL 80 HOUR"
mark; cron cron_aviso_vencimento
chk "faltam 3,3 dias (entre as janelas de 5 e 2): nenhum aviso" 'count(tg("sendMessage"))==0'
ajusta "1 SECOND" "NOW() + INTERVAL 108 HOUR"
sql "DELETE a FROM avisos_assinatura_enviados a JOIN membros_grupos m ON m.id=a.membro_id WHERE m.bot_id=$BOTID" >/dev/null
mark; cron cron_aviso_vencimento
chk "plano curto (4,5 dias de duração) NÃO recebe aviso de '5 dias' logo depois de comprar" 'count(tg("sendMessage"))==0'
ajusta "26 DAY" "DATE_ADD(CONCAT(CURDATE(), ' 23:59:00'), INTERVAL 0 SECOND)"
sql "DELETE a FROM avisos_assinatura_enviados a JOIN membros_grupos m ON m.id=a.membro_id WHERE m.bot_id=$BOTID" >/dev/null
mark; cron cron_aviso_vencimento
chk "vence hoje (mais tarde): aviso 'no dia' (d0) com 'expira hoje'" 'count(tg("sendMessage"))==1 && strpos(txt(ultimo("sendMessage")),"expira hoje")!==false'
chk "marco d0 gravado" 'q1("SELECT COUNT(*) FROM avisos_assinatura_enviados a JOIN membros_grupos m ON m.id=a.membro_id WHERE m.bot_id=? AND a.marco LIKE \"d0@%\"",[ids()["bot"]])==1'

secao "G5.2 Avisos 'no dia' por horário + d0 no mesmo minuto = um aviso só"
membro p_mensal "{\"assinatura\":{\"avisos_antes\":{\"ativo\":true},\"avisos_no_dia\":{\"ativo\":true,\"horarios!\":[\"$HORA:00\"]}}}"
ajusta "26 DAY" "CONCAT(CURDATE(), ' 23:59:00')"
mark; cron cron_aviso_vencimento
chk "hora atual coincide com o horário configurado + d0: manda UM aviso" 'count(tg("sendMessage"))==1'
chk "os dois marcos (d0 e h$HORA) ficam reservados" 'q1("SELECT COUNT(*) FROM avisos_assinatura_enviados a JOIN membros_grupos m ON m.id=a.membro_id WHERE m.bot_id=?",[ids()["bot"]])==2'

secao "G5.3 Aviso com mídia, mensagem padrão e variáveis"
membro p_mensal '{"assinatura":{"avisos_antes":{"ativo":true,"midia_tipo":"image","midia_path":"uploads/t_foto.png","mensagem":""}}}'
ajusta "26 DAY" "NOW() + INTERVAL 108 HOUR"
mark; cron cron_aviso_vencimento
chk "aviso com FOTO: sendPhoto com a mensagem padrão na legenda + botão Renovar" '($e=ultimo("sendPhoto")) && strpos(txt($e),"Olá Maria Teste! Sua assinatura do plano Mensal expira")===0 && cbs($e)==["basico::renovar"] && count(tg("sendMessage"))==0'
membro p_mensal '{"assinatura":{"avisos_antes":{"ativo":true,"midia_tipo":"image","midia_path":"uploads/some.png"}}}'
ajusta "26 DAY" "NOW() + INTERVAL 108 HOUR"
mark; cron cron_aviso_vencimento
chk "mídia do aviso que sumiu: cai pra mensagem de texto" 'count(tg("sendPhoto"))==0 && count(tg("sendMessage"))==1'

secao "G5.4 Aviso genérico (fluxo sem avisos da aba Assinatura)"
membro p_mensal '{"assinatura":{"avisos_antes":{"ativo":false}}}'
ajusta "29 DAY" "NOW() + INTERVAL 20 HOUR"
mark; cron cron_aviso_vencimento
chk "plano de dias com <24h restantes: 'Seu acesso vence em breve' (Markdown) + botão Recomeçar/Renovar (/start)" '($e=ultimo("sendMessage")) && strpos(txt($e),"vence em breve")!==false && strpos(txt($e),"Faltam menos de")!==false && ($e["p"]["parse_mode"]??"")=="Markdown" && cbs($e)==["/start"] && count(tgerr())==0'
chk "membro marcado aviso_enviado=1" '('"$MEMBRO"')["aviso_enviado"]==1'
mark; cron cron_aviso_vencimento
chk "não repete o aviso genérico" 'count(tg("sendMessage"))==0'
membro p_min '{"assinatura":{"avisos_antes":{"ativo":false}}}'
ajusta "26 MINUTE" "NOW() + INTERVAL 4 MINUTE"
mark; cron cron_aviso_vencimento
chk "plano de 30 min: 'Faltam apenas 4 minutos'" 'tem("Faltam apenas") && tem("minutos")'
membro p_mensal '{"assinatura":{"avisos_antes":{"ativo":false}}}'
ajusta "10 DAY" "NOW() + INTERVAL 10 DAY"
mark; cron cron_aviso_vencimento
chk "faltando 10 dias: nenhum aviso genérico" 'count(tg("sendMessage"))==0'

secao "G5.5 Expiração: remoção do grupo (compra única)"
membro p_mensal '{"assinatura":{"avisos_antes":{"ativo":false}}}'
LINK=$(php /tmp/pt/db.php "SELECT invite_link FROM membros_grupos WHERE bot_id=$BOTID" | php -r '$j=json_decode(trim(stream_get_contents(STDIN)),true); echo $j["invite_link"];')
ajusta "31 DAY" "NOW() - INTERVAL 1 MINUTE"
mark; cron cron_verificar_acessos
chk "ordem: revoga convite -> bane -> desbane" 'sequencia()==["revokeChatInviteLink","banChatMember","unbanChatMember","sendMessage"]'
chk "convite revogado é o do membro; ban no grupo certo, só ~35s, e desban 'only_if_banned'" '($r=tg("revokeChatInviteLink")[0]) && $r["p"]["invite_link"]=="'"$LINK"'" && ($b=tg("banChatMember")[0]) && $b["p"]["chat_id"]=="-1001000000001" && $b["p"]["user_id"]=="777000111" && abs($b["p"]["until_date"]-time()-35)<10 && tg("unbanChatMember")[0]["p"]["only_if_banned"]==true'
chk "membro vira 'expirado' e recebe a mensagem padrão com botão Recomeçar (/start)" '('"$MEMBRO"')["status"]=="expirado" && strpos(txt(ultimo("sendMessage")),"Seu acesso ao grupo expirou")!==false && cbs(ultimo("sendMessage"))==["/start"]'
chk "atividade 'Acesso' vai para o DONO do bot (e não para o usuário de id igual ao id do bot)" 'q1("SELECT COUNT(*) FROM atividades WHERE id_usuario=? AND titulo=\"Acesso\"",[ids()["uid"]])==1 && q1("SELECT COUNT(*) FROM atividades WHERE id_usuario=? AND titulo=\"Acesso\"",[ids()["bot"]])==0'
mark; cron cron_verificar_acessos
chk "2ª rodada: nada novo" 'count(tg("banChatMember"))==0'
membro p_mensal '{"assinatura":{"avisos_antes":{"ativo":false},"ao_expirar":{"ativo":true,"mensagem":"{nome}, o plano {plano} acabou ({data_expiracao}). Renove! {prazo}"}}}'
ajusta "31 DAY" "NOW() - INTERVAL 1 MINUTE"
mark; cron cron_verificar_acessos
chk "mensagem 'ao expirar' personalizada com variáveis + botão Renovar" '($e=ultimo("sendMessage")) && strpos(txt($e),"Maria Teste, o plano Mensal acabou")===0 && cbs($e)==["basico::renovar"] && tg("banChatMember")'
membro p_mensal '{"assinatura":{"avisos_antes":{"ativo":false},"ao_expirar":{"ativo":true,"mensagem":"Acabou!","midia_tipo":"image","midia_path":"uploads/t_foto.png"},"acoes":{"expulsar":false}}}'
ajusta "31 DAY" "NOW() - INTERVAL 1 MINUTE"
mark; cron cron_verificar_acessos
chk "expulsar=false: NÃO remove do grupo (sem ban/revoke), encerra o acesso e manda a mensagem (com foto)" 'count(tg("banChatMember"))==0 && count(tg("revokeChatInviteLink"))==0 && ('"$MEMBRO"')["status"]=="expirado" && count(tg("sendPhoto"))==1'
membro p_mensal '{"assinatura":{"avisos_antes":{"ativo":false},"ao_expirar":{"ativo":false},"acoes":{"expulsar":false}}}'
ajusta "31 DAY" "NOW() - INTERVAL 1 MINUTE"
mark; cron cron_verificar_acessos
chk "expulsar=false sem mensagem: expira em silêncio" 'count(tg("sendMessage"))==0 && ('"$MEMBRO"')["status"]=="expirado"'
membro p_mensal '{"assinatura":{"avisos_antes":{"ativo":false}}}'
ajusta "31 DAY" "NOW() - INTERVAL 1 MINUTE"
falha '{"banChatMember":"Bad Request: can'"'"'t remove chat owner"}'
mark; cron cron_verificar_acessos
chk "erro permanente do Telegram (dono do grupo): marca expirado e não tenta de novo" '('"$MEMBRO"')["status"]=="expirado"'
semfalha
membro p_mensal '{"assinatura":{"avisos_antes":{"ativo":false}}}'
ajusta "31 DAY" "NOW() - INTERVAL 1 MINUTE"
falha '{"banChatMember":"Too Many Requests: retry after 5"}'
mark; cron cron_verificar_acessos
chk "erro temporário (429): continua ativo pra tentar de novo, sem mensagem" '('"$MEMBRO"')["status"]=="ativo" && count(tg("sendMessage"))==0'
semfalha
mark; cron cron_verificar_acessos
chk "na rodada seguinte (Telegram ok) remove normalmente" '('"$MEMBRO"')["status"]=="expirado" && count(tg("banChatMember"))==1'

secao "G5.6 Vários membros de uma vez (um vencido, um válido, um vitalício)"
reset; setflow guiado_base '{"assinatura":{"avisos_antes":{"ativo":false}},"packs":{"ativo":true}}'
for CH in 111 222 333; do
  mark; cb "basico::plano::p_mensal" 555 "$CH" >/dev/null; T=$(ultimotx); pagar "$T"; cb "verificar_pagamento_$T" 555 "$CH" >/dev/null
done
sql "UPDATE membros_grupos SET data_expiracao = NOW() - INTERVAL 1 MINUTE WHERE bot_id=$BOTID AND id_telegram='111'" >/dev/null
sql "UPDATE membros_grupos SET data_expiracao = NOW() + INTERVAL 3650 DAY WHERE bot_id=$BOTID AND id_telegram='333'" >/dev/null
mark; cron cron_verificar_acessos
chk "só o vencido é removido; o válido e o de acesso longo seguem ativos" 'count(tg("banChatMember"))==1 && tg("banChatMember")[0]["p"]["user_id"]=="111" && q1("SELECT COUNT(*) FROM membros_grupos WHERE bot_id=? AND status=\"ativo\"",[ids()["bot"]])==2'

secao "G5.7 Carência de 2 dias para assinatura"
membro p_mensal '{"assinatura":{"avisos_antes":{"ativo":false}}}'
sql "UPDATE vendas SET tipo_cobranca='assinatura' WHERE bot_id=$BOTID" >/dev/null
ajusta "31 DAY" "NOW() - INTERVAL 1 DAY"
mark; cron cron_verificar_acessos
chk "assinatura vencida há 1 dia: dentro da carência, NÃO remove; avisa uma vez" 'count(tg("banChatMember"))==0 && ('"$MEMBRO"')["status"]=="ativo" && strpos(txt(ultimo("sendMessage")),"Seu acesso venceu")!==false && ('"$MEMBRO"')["aviso_enviado"]==1'
mark; cron cron_verificar_acessos
chk "carência: o aviso não é repetido a cada minuto" 'count(tg("sendMessage"))==0'
ajusta "33 DAY" "NOW() - INTERVAL 3 DAY"
mark; cron cron_verificar_acessos
chk "vencida há 3 dias (carência de 2 esgotada): remove" 'count(tg("banChatMember"))==1 && ('"$MEMBRO"')["status"]=="expirado"'
membro p_mensal '{"assinatura":{"avisos_antes":{"ativo":false}}}'
sql "UPDATE vendas SET tipo_cobranca='assinatura', id_assinatura='SUBSIM1' WHERE bot_id=$BOTID" >/dev/null
ajusta "31 DAY" "NOW() - INTERVAL 30 HOUR"
mark; cron cron_verificar_acessos
chk "recorrente nativo (gateway cobra sozinha) com 30h de atraso: 'problema na renovação', não remove" 'count(tg("banChatMember"))==0 && strpos(txt(ultimo("sendMessage")),"problema na renova")!==false'

secao "G5.8 Renovação manual por Pix (cron_renovacao, assinatura sem id_assinatura)"
membro p_mensal '{"assinatura":{"avisos_antes":{"ativo":false}}}'
sql "UPDATE vendas SET tipo_cobranca='assinatura' WHERE bot_id=$BOTID" >/dev/null
ajusta "28 DAY" "NOW() + INTERVAL 2 DAY"
mark; cron cron_renovacao
chk "faltando 2 dias: gera Pix de renovação (valor da venda), grava venda filha e manda mensagem + código" 'count(gw())==1 && abs(gw()[0]["valor"]-19.9)<0.001 && count(tg("sendMessage"))==2 && q1("SELECT COUNT(*) FROM vendas WHERE bot_id=? AND venda_pai_id IS NOT NULL",[ids()["bot"]])==1 && ('"$MEMBRO"')["em_renovacao"]==1'
mark; cron cron_renovacao
chk "rodada seguinte: não gera outro Pix de renovação" 'count(gw())==0'
echo; echo "FIM G5"
