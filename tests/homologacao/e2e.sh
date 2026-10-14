#!/bin/bash
# PONTA A PONTA: jornada completa do Guiado (tudo ligado) e do Editor Visual (3 clientes), com os números das telas.
source /tmp/pt/lib.sh
BOTID=$(php -r 'echo json_decode(file_get_contents("/tmp/pt/ids.json"),true)["bot"];')
UID_=$(php -r 'echo json_decode(file_get_contents("/tmp/pt/ids.json"),true)["uid"];')
HORA=$(php -r 'date_default_timezone_set("America/Sao_Paulo"); echo date("H");')
MEM() { echo "q(\"SELECT * FROM membros_grupos WHERE bot_id=? AND id_grupo_telegram=? AND id_telegram=?\",[ids()[\"bot\"],\"$1\",\"${2:-777000111}\"])[0]"; }
payload() { echo "{\"event\":\"TRANSACTION_PAID\",\"transaction\":{\"id\":\"$1\",\"status\":\"PAID\"}}"; }
G3='-1001000000003'

secao "E2E-1 Fluxo Guiado: jornada completa de um cliente (tudo ligado)"
janela; reset
setflow guiado_base "{\"cache_chat_id\":\"-1001000000099\",\"boas_vindas\":{\"mensagem\":\"Bem-vinda ao curso! 🎓\",\"midia_tipo\":\"image\",\"midia_path\":\"uploads/t_foto.png\"},\"previas\":{\"ativo\":true},\"packs\":{\"ativo\":true,\"itens!\":[{\"id\":\"k1\",\"ativo\":true,\"emoji\":\"📷\",\"nome\":\"Aula 1 — Exposição\",\"preco\":9.9,\"descricao\":\"Triângulo da exposição.\",\"midia_tipo\":\"video\",\"midia_path\":\"uploads/t_video.mp4\",\"id_grupo\":\"$G3\"}]},\"ofertas\":{\"upsell\":{\"ativo\":true},\"downsell\":{\"ativo\":true},\"order_bump\":{\"ativo\":true}},\"assinatura\":{\"avisos_antes\":{\"ativo\":true},\"avisos_no_dia\":{\"ativo\":true,\"horarios!\":[\"$HORA:00\"]},\"ao_expirar\":{\"ativo\":true,\"mensagem\":\"{nome}, seu plano {plano} acabou. Renove com desconto!\"},\"renovacao\":{\"desconto\":10}},\"pagamentos\":{\"msg_confirmado\":\"Pagamento aprovado! Bem-vindo(a) ao curso. 🎉\"}}"
mark; JORNADA=$(wc -l < /tmp/pt/sim.log)
msg "/start" "Maria" "maria_t"
chk "1) /start: foto de boas-vindas (upload no cache + envio por file_id) com botão Quero aprender" 'count(tg("sendPhoto"))==2 && txt(ultimo("sendPhoto"))=="Bem-vinda ao curso! 🎓" && cbs(ultimo("sendPhoto"))==["basico::ver_planos"]'
cb "basico::ver_planos" >/dev/null
mark; cb "basico::previa"
chk "2) prévia: texto + foto + vídeo + áudio protegidos" 'count(tg("sendPhoto"))==1 && count(tg("sendVideo"))==1 && count(tg("sendAudio"))==1'
janela; sql "UPDATE mensagens_agendadas SET apagar_em = NOW() - INTERVAL 1 SECOND WHERE bot_id=$BOTID" >/dev/null
mark; cron cron_aviso_vencimento
chk "3) cron apaga a prévia e entrega a mensagem de conversão com 'Ver Planos'" 'count(tg("deleteMessage"))==4 && cbs(ultimo("sendMessage"))==["basico::ver_planos"]'
cb "basico::packs" >/dev/null; cb "basico::pack::k1" >/dev/null
mark; cb "basico::packbuy::k1"; TXP=$(ultimotx)
chk "4) compra do pack k1 (R\$ 9,90)" 'count(gw())==1 && abs(gw()[0]["valor"]-9.9)<0.001'
pagar "$TXP"; mark; owh "$(payload $TXP)"
chk "4b) pago pelo webhook da gateway: acesso ao grupo do pack, 'vitalício', confirmação personalizada" 'tem("Pagamento aprovado! Bem-vindo(a) ao curso.") && tem("Acesso vitalício") && ($m='"$(MEM $G3)"') && $m["status"]=="ativo" && strtotime($m["data_expiracao"])>time()+36000*86400'
msg "/start" "Maria" "maria_t" >/dev/null
mark; cb "basico::plano::p_mensal"
chk "5) escolhe o Mensal: aparece o upsell" 'cbs(ultimo("sendMessage"))==["basico::of::up::p_mensal::1","basico::of::up::p_mensal::0"]'
mark; cb "basico::of::up::p_mensal::0"
chk "5b) recusa o upsell: downsell do Mensal é pulado, aparece o bump" 'cbs(ultimo("sendMessage"))==["basico::of::bp::p_mensal::o::1","basico::of::bp::p_mensal::o::0"]'
mark; cb "basico::of::bp::p_mensal::o::1"; TXM=$(ultimotx)
chk "5c) aceita o bump: Pix de R\$ 27,80 (Mensal + Checklist PDF)" 'count(gw())==1 && abs(gw()[0]["valor"]-27.8)<0.001 && tem("Mensal + Checklist PDF")'
pagar "$TXM"; mark; cron cron_verificar_pix
chk "5d) cron confirma: acesso de 30 dias ao grupo do plano, com a mensagem personalizada" 'tem("Pagamento aprovado! Bem-vindo(a) ao curso.") && ($m='"$(MEM -1001000000001)"') && abs(strtotime($m["data_expiracao"])-time()-30*86400)<180'
janela
sql "UPDATE membros_grupos SET criado_em = NOW() - INTERVAL 26 DAY, data_expiracao = NOW() + INTERVAL 108 HOUR WHERE bot_id=$BOTID AND id_grupo_telegram='-1001000000001'" >/dev/null
mark; cron cron_aviso_vencimento
chk "6) faltam 4,5 dias: aviso de renovação com nome/plano preenchidos" 'count(tg("sendMessage"))==1 && preg_match("/, Maria Teste! Seu plano atual expira em [45] dias/u", txt(ultimo("sendMessage"))) && cbs(ultimo("sendMessage"))==["basico::renovar"]'
sql "UPDATE membros_grupos SET data_expiracao = NOW() + INTERVAL 36 HOUR WHERE bot_id=$BOTID AND id_grupo_telegram='-1001000000001'" >/dev/null
mark; cron cron_aviso_vencimento
chk "6b) faltam 1,5 dia: segundo aviso (2 dias)" 'count(tg("sendMessage"))==1'
sql "UPDATE membros_grupos SET data_expiracao = CONCAT(CURDATE(), ' 23:59:00') WHERE bot_id=$BOTID AND id_grupo_telegram='-1001000000001'" >/dev/null
mark; cron cron_aviso_vencimento
chk "6c) vence hoje: aviso 'no dia'" 'count(tg("sendMessage"))==1 && strpos(txt(ultimo("sendMessage")),"expira hoje")!==false'
EXP=$(php /tmp/pt/db.php "SELECT data_expiracao e FROM membros_grupos WHERE bot_id=$BOTID AND id_grupo_telegram='-1001000000001'" | php -r '$j=json_decode(trim(stream_get_contents(STDIN)),true); echo $j["e"];')
mark; cb "basico::renovar"
chk "7) 'Renovar agora': oferta com 10% de desconto" '($t=array_column(botoes(ultimo("sendMessage")),"text")) && $t[0]=="Mensal — R$ 17,91 (-10%)"'
cb "basico::renovplano::p_mensal" >/dev/null; TXR=$(ultimotx); pagar "$TXR"
mark; cb "verificar_pagamento_$TXR"
chk "7b) renovação paga pelo botão: estende o prazo a partir do vencimento atual (+30 dias)" '($m='"$(MEM -1001000000001)"') && abs(strtotime($m["data_expiracao"])-(strtotime("'"$EXP"'")+30*86400))<5 && count(tg("revokeChatInviteLink"))==1'
janela
sql "UPDATE membros_grupos SET data_expiracao = NOW() - INTERVAL 1 MINUTE WHERE bot_id=$BOTID AND id_grupo_telegram='-1001000000001'" >/dev/null
mark; cron cron_verificar_acessos
chk "8) venceu: removido do grupo (revoga, bane, desbane) e recebe a mensagem 'ao expirar' com Renovar" 'sequencia()==["revokeChatInviteLink","banChatMember","unbanChatMember","sendMessage"] && strpos(txt(ultimo("sendMessage")),"Maria Teste, seu plano atual acabou")===0 && cbs(ultimo("sendMessage"))==["basico::renovar"] && ('"$(MEM -1001000000001)"')["status"]=="expirado"'
chk "8b) o acesso vitalício do pack NÃO foi tocado" '('"$(MEM $G3)"')["status"]=="ativo"'
cb "basico::renovar" >/dev/null; cb "basico::renovplano::p_mensal" >/dev/null; TXV=$(ultimotx); pagar "$TXV"
mark; owh "$(payload $TXV)"
chk "9) volta a comprar depois de expirar: acesso reativado por 30 dias a partir de agora" '($m='"$(MEM -1001000000001)"') && $m["status"]=="ativo" && abs(strtotime($m["data_expiracao"])-time()-30*86400)<180 && q1("SELECT COUNT(*) FROM membros_grupos WHERE bot_id=? AND id_grupo_telegram=\"-1001000000001\"",[ids()["bot"]])==1'
mark
chk "10) vendas pagas: 4 (9,90 + 27,80 + 17,91 + 17,91 = R\$ 73,52)" 'q1("SELECT COUNT(*) FROM vendas WHERE bot_id=? AND status=\"pago\"",[ids()["bot"]])==4 && abs(q1("SELECT SUM(valor) FROM vendas WHERE bot_id=? AND status=\"pago\"",[ids()["bot"]])-73.52)<0.001'
chk "10a) a jornada inteira sem nenhum erro de Telegram nem aviso/erro de PHP" 'count($x=erros_desde('"$JORNADA"'))==0 || (print_r(array_slice($x,0,6)) && false)'
chk "10b) feed de atividades do dono: 1 lead, 4 Pix gerados, 4 vendas aprovadas" 'q1("SELECT COUNT(*) FROM atividades WHERE id_usuario=? AND tipo=\"lead\"",[ids()["uid"]])==1 && q1("SELECT COUNT(*) FROM atividades WHERE id_usuario=? AND tipo=\"pix_gerado\"",[ids()["uid"]])==4 && q1("SELECT COUNT(*) FROM atividades WHERE id_usuario=? AND tipo=\"venda\"",[ids()["uid"]])==4'
FID=$(fluxoid)
api GET resumo_fluxo '' "id=$FID"
chk "11) Resumo do fluxo (tela de fluxos): 1 lead, 1 comprador (VIP), receita R\$ 73,52" 'resp()["resumo"]["leads"]==1 && resp()["resumo"]["vips"]==1 && abs(resp()["resumo"]["receita"]-73.52)<0.001'
api GET conversoes_fluxo '' "id=$FID"
chk "11b) Conversões do fluxo: 4 Pix gerados, 4 pagos, ticket médio R\$ 18,38, receita R\$ 73,52" 'resp()["conversoes"]["gerados"]==4 && resp()["conversoes"]["pagos"]==4 && abs(resp()["conversoes"]["ticket_medio"]-18.38)<0.001 && abs(resp()["conversoes"]["receita"]-73.52)<0.001 && resp()["conversoes"]["compradores"]==1'
api GET listar_bots
chk "11c) Card do bot (Meus Bots): 1 lead e 4 vendas nos últimos 8 dias" '($b=array_values(array_filter(resp()["bots"],fn($x)=>$x["id"]==ids()["bot"]))[0]) && $b["leads_7d"]==1 && $b["vendas_7d"]==4'

secao "E2E-2 Editor Visual: funil completo com 3 clientes ao mesmo tempo"
janela; reset; setflow v_completo
A=777000111; B=777000222; C=777000333
mark
msg "/start" "Ana" "" $A; msg "/start" "Beto" "" $B; msg "/start" "Caio" "" $C
chk "todos recebem boas-vindas + foto + pergunta com botões (sem se misturar)" 'count(tg("sendPhoto"))==3 && count(array_filter(array_map("txt",tg("sendMessage")),fn($t)=>$t=="Quer garantir agora?"))==3 && msgs_chat("777000111")[0]=="Olá! Bem-vindo ao funil completo 👋" && msgs_chat("777000222")[0]=="Olá! Bem-vindo ao funil completo 👋"'
chk "callbacks das perguntas: btn::esc::0 (Comprar) e btn::esc::1 (Depois)" 'cbs(ultimo("sendMessage"))==["btn::esc::0","btn::esc::1"]'
mark
cb "btn::esc::0" 900 $A; cb "btn::esc::1" 901 $B; cb "btn::esc::0" 902 $C
chk "Ana e Caio: upsell (20%); Beto: 'Sem problemas, te aviso depois' + delay de 5 min agendado" 'count(tg("deleteMessage"))==3 && count(array_filter(array_map("txt",tg("sendMessage")),fn($t)=>$t=="Leve o combo com 20% off!"))==2 && in_array("Sem problemas, te aviso depois ⏳", msgs_chat("777000222")) && q1("SELECT COUNT(*) FROM fluxo_continuacoes WHERE bot_id=? AND id_telegram=\"777000222\" AND status=\"pendente\"",[ids()["bot"]])==1'
mark
cb "saida::up::aceito" 903 $A; cb "saida::bp::recusado" 904 $A
chk "Ana: aceita upsell e recusa o bump: Pix R\$ 40,00 (50 com 20%)" 'count(gw())==1 && abs(gw()[0]["valor"]-40)<0.001 && count(tg("sendPhoto"))==1'
TXA=$(ultimotx)
mark
cb "saida::up::recusado" 905 $C; cb "saida::bp::aceito" 906 $C
chk "Caio (ofertas independentes da Ana): recusa upsell e aceita o bump: Pix R\$ 60,00 (50 + 10)" 'count(gw())==1 && abs(gw()[0]["valor"]-60)<0.001'
TXC=$(ultimotx)
janela
pagar "$TXA"
mark; owh "$(payload $TXA)"
chk "Ana paga (webhook): confirmação padrão, 'Compra aprovada', convite G2 com botão e delay de 2 min agendado" 'in_array("🎉 Compra aprovada!", msgs_chat("777000111")) && count(array_filter(tg("sendMessage"),fn($e)=>($b=botoes($e)) && $b && $b[0]["text"]=="Entrar no VIP"))==1 && q1("SELECT COUNT(*) FROM fluxo_continuacoes WHERE bot_id=? AND id_telegram=\"777000111\" AND status=\"pendente\"",[ids()["bot"]])==1'
sql "UPDATE vendas SET criado_em = NOW() - INTERVAL 20 MINUTE WHERE transacao_id='$TXC'" >/dev/null
sql "UPDATE fluxo_continuacoes SET executar_em = NOW() - INTERVAL 1 SECOND WHERE bot_id=$BOTID" >/dev/null
mark; cron cron_verificar_pix; cron cron_aviso_vencimento
chk "cron: Caio expira ('Seu Pix expirou' + delay 1 min agendado), Ana recebe o material (2 min) e Beto o lembrete (5 min)" 'in_array("⏰ Seu Pix expirou", msgs_chat("777000333")) && in_array("📚 Seu material chegou (depois de 2 min)", msgs_chat("777000111")) && in_array("🔔 Lembrete: sua oferta continua válida!", msgs_chat("777000222"))'
sql "UPDATE fluxo_continuacoes SET executar_em = NOW() - INTERVAL 1 SECOND WHERE bot_id=$BOTID AND status='pendente'" >/dev/null
mark; cron cron_aviso_vencimento
chk "cron seguinte: Caio recebe 'Ainda dá tempo' (depois do delay do ramo não pago)" 'in_array("💬 Ainda dá tempo! Mande /start para tentar de novo", msgs_chat("777000333"))'
mark
chk "sem erro de Telegram nem aviso de PHP em todo o funil" 'count(tgerr())==0 && semerros()'
FID=$(fluxoid)
api GET resumo_fluxo '' "id=$FID"
chk "Resumo do fluxo visual: 3 leads, 1 comprador, receita R\$ 40,00" 'resp()["resumo"]["leads"]==3 && resp()["resumo"]["vips"]==1 && abs(resp()["resumo"]["receita"]-40)<0.001'
api GET conversoes_fluxo '' "id=$FID"
chk "Conversões do fluxo visual: 2 Pix gerados, 1 pago, 1 expirado, taxa Pix→pago 50%" 'resp()["conversoes"]["gerados"]==2 && resp()["conversoes"]["pagos"]==1 && resp()["conversoes"]["expirados"]==1 && resp()["conversoes"]["taxa_pix_pago"]==50'
echo; echo "FIM E2E"
