#!/bin/bash
# GUIADO 4: prévias, packs, renovação.
source /tmp/pt/lib.sh
BOTID=$(php -r 'echo json_decode(file_get_contents("/tmp/pt/ids.json"),true)["bot"];')
UID_=$(php -r 'echo json_decode(file_get_contents("/tmp/pt/ids.json"),true)["uid"];')
VENDA='q("SELECT * FROM vendas WHERE bot_id=? ORDER BY id DESC LIMIT 1",[ids()["bot"]])[0]'
MEMBRO='q("SELECT * FROM membros_grupos WHERE bot_id=? ORDER BY id DESC LIMIT 1",[ids()["bot"]])[0]'
AGENDADAS='q("SELECT * FROM mensagens_agendadas WHERE bot_id=? ORDER BY id",[ids()["bot"]])'
comprar() { mark; cb "basico::${2:-plano}::${1:-p_mensal}"; TX=$(ultimotx); }

secao "G4.1 Prévias que somem"
reset; setflow guiado_base '{"previas":{"ativo":true}}'
mark; cb "basico::previa"
chk "texto 'junto' + 3 mídias (foto, vídeo, áudio), todas com protect_content=true" 'count(tg("sendMessage"))==1 && ($t=ultimo("sendMessage")) && txt($t)=="Aqui vai uma prévia:" && $t["p"]["protect_content"]=="true" && count(tg("sendPhoto"))==1 && count(tg("sendVideo"))==1 && count(tg("sendAudio"))==1 && tg("sendPhoto")[0]["p"]["protect_content"]=="true" && tg("sendVideo")[0]["p"]["protect_content"]=="true" && tg("sendAudio")[0]["p"]["protect_content"]=="true"'
chk "4 mensagens entram na fila de apagar; a 'mensagem de depois' vai só na última" '($a='"$AGENDADAS"') && count($a)==4 && $a[0]["depois_json"]===null && $a[2]["depois_json"]===null && $a[3]["depois_json"]!==null && abs(strtotime($a[0]["apagar_em"])-time()-5)<6'
chk "prévia registrada como vista para o lead" 'q1("SELECT COUNT(*) FROM previas_vistas WHERE bot_id=?",[ids()["bot"]])==1'
chk "sem erro/aviso de PHP" 'semerros() && count(tgerr())==0'
sql "UPDATE mensagens_agendadas SET apagar_em = NOW() - INTERVAL 1 SECOND WHERE bot_id=$BOTID" >/dev/null
IDS_ENV=$(php /tmp/pt/db.php "SELECT GROUP_CONCAT(message_id ORDER BY id) m FROM mensagens_agendadas WHERE bot_id=$BOTID" | php -r '$j=json_decode(trim(stream_get_contents(STDIN)),true); echo $j["m"];')
mark; cron cron_aviso_vencimento
chk "cron apaga as 4 mensagens (ids certos) e manda a 'mensagem de depois' com botão Ver Planos" 'count(tg("deleteMessage"))==4 && implode(",",array_map(fn($e)=>$e["p"]["message_id"],tg("deleteMessage")))=="'"$IDS_ENV"'" && ($e=ultimo("sendMessage")) && txt($e)=="Gostou? Veja os planos 👇" && cbs($e)==["basico::ver_planos"]'
chk "linhas da fila marcadas como apagadas" 'q1("SELECT COUNT(*) FROM mensagens_agendadas WHERE bot_id=? AND apagada=1",[ids()["bot"]])==4'
mark; cron cron_aviso_vencimento
chk "2ª rodada do cron não apaga nada de novo" 'count(tg("deleteMessage"))==0 && count(tg("sendMessage"))==0'
# regras de rever
mark; cb "basico::previa"
chk "rever=diario: 2ª visualização no mesmo dia é bloqueada com botão Ver Planos" 'count(tg("sendPhoto"))==0 && tem("já viu as prévias") && cbs(ultimo("sendMessage"))==["basico::ver_planos"]'
sql "UPDATE previas_vistas SET visto_em = NOW() - INTERVAL 25 HOUR WHERE bot_id=$BOTID" >/dev/null
mark; cb "basico::previa"
chk "rever=diario: depois de 24h libera de novo" 'count(tg("sendPhoto"))==1'
setflow guiado_base '{"previas":{"ativo":true,"rever":"nunca"}}'
sql "UPDATE previas_vistas SET visto_em = NOW() - INTERVAL 40 DAY WHERE bot_id=$BOTID" >/dev/null
mark; cb "basico::previa"
chk "rever=nunca: bloqueia mesmo 40 dias depois" 'count(tg("sendPhoto"))==0 && tem("já viu as prévias")'
setflow guiado_base '{"previas":{"ativo":true,"rever":"sempre"}}'
mark; cb "basico::previa"; cb "basico::previa"
chk "rever=sempre: libera sempre (2 cliques = 2 envios)" 'count(tg("sendPhoto"))==2'
reset; setflow guiado_base '{"previas":{"ativo":true,"proteger":false,"mensagem_junto":"","mensagem_depois":""}}'
mark; cb "basico::previa"
chk "proteger desligado: protect_content=false; sem texto 'junto' não há sendMessage" 'tg("sendPhoto")[0]["p"]["protect_content"]=="false" && count(tg("sendMessage"))==0'
chk "sem 'mensagem de depois': nada preso na fila" 'q1("SELECT COUNT(*) FROM mensagens_agendadas WHERE bot_id=? AND depois_json IS NOT NULL",[ids()["bot"]])==0'
reset; setflow guiado_base '{"previas":{"ativo":true,"botao_planos":false}}'
mark; cb "basico::previa"
sql "UPDATE mensagens_agendadas SET apagar_em = NOW() - INTERVAL 1 SECOND WHERE bot_id=$BOTID" >/dev/null
mark; cron cron_aviso_vencimento
chk "botao_planos desligado: mensagem de depois sem botão" '($e=ultimo("sendMessage")) && txt($e)=="Gostou? Veja os planos 👇" && !isset($e["p"]["reply_markup"])'
reset; setflow guiado_base '{"previas":{"ativo":false}}'
mark; cb "basico::previa"
chk "prévia desativada (botão antigo no chat): 'não estão disponíveis'" 'tem("As prévias não estão disponíveis") && count(tg("sendPhoto"))==0'
reset; setflow guiado_base '{"previas":{"ativo":true,"midias!":[{"tipo":"image","path":"uploads/t_foto.png"},{"tipo":"image","path":"uploads/t_foto.png"},{"tipo":"image","path":"uploads/t_foto.png"},{"tipo":"image","path":"uploads/t_foto.png"},{"tipo":"image","path":"uploads/t_foto.png"},{"tipo":"image","path":"uploads/t_foto.png"},{"tipo":"image","path":"uploads/t_foto.png"},{"tipo":"image","path":"uploads/t_foto.png"},{"tipo":"image","path":"uploads/t_foto.png"},{"tipo":"image","path":"uploads/t_foto.png"}]}}'
mark; cb "basico::previa"
chk "mais de 8 mídias: envia no máximo 8" 'count(tg("sendPhoto"))==8'
reset; setflow guiado_base '{"previas":{"ativo":true}}'
falha '{"sendVideo":"Bad Request: wrong file"}'
mark; cb "basico::previa"
chk "um vídeo falha: foto e áudio seguem, 3 mensagens na fila (junto+foto+áudio)" 'count(tgok("sendPhoto"))==1 && count(tgok("sendAudio"))==1 && q1("SELECT COUNT(*) FROM mensagens_agendadas WHERE bot_id=?",[ids()["bot"]])==3'
semfalha
reset; setflow guiado_base '{"previas":{"ativo":true,"apagar_apos":5}}'
mark; SIM_FASTCGI=1 cb "basico::previa"
chk "webhook solta o Telegram, espera 5s e apaga sozinho (sem depender do cron)" 'count(ev("evento"))==1 && count(tg("deleteMessage"))==4 && txt(ultimo("sendMessage"))=="Gostou? Veja os planos 👇"'
reset; setflow guiado_base '{"previas":{"ativo":true,"apagar_apos":120}}'
mark; SIM_FASTCGI=1 cb "basico::previa"
chk "apagar_apos > 60s: não segura o webhook (cron apaga depois); mensagens ficam agendadas" 'count(tg("deleteMessage"))==0 && q1("SELECT COUNT(*) FROM mensagens_agendadas WHERE bot_id=? AND apagada=0",[ids()["bot"]])==4'

secao "G4.2 Packs"
reset; setflow guiado_base '{"packs":{"ativo":true}}'
mark; cb "basico::packs"
chk "lista só os packs ativos com nome e preço > 0 (k1,k2,k4,k5; k3 fica de fora)" '($e=ultimo("sendMessage")) && txt($e)=="Escolha uma aula:" && cbs($e)==["basico::pack::k1","basico::pack::k2","basico::pack::k4","basico::pack::k5"]'
chk "rótulos: emoji + nome + preço" '($t=array_column(botoes(ultimo("sendMessage")),"text")) && $t[0]=="📷 Aula 1 — Exposição — R$ 9,90" && $t[3]=="🎧 Pack áudio — R$ 4,50"'
mark; cb "basico::pack::k1"
chk "prévia do pack com VÍDEO: sendVideo com descrição, preço e botão Comprar" '($e=ultimo("sendVideo")) && strpos(txt($e),"Aula 1")!==false && strpos(txt($e),"Triângulo da exposição")!==false && strpos(txt($e),"R$ 9,90")!==false && cbs($e)==["basico::packbuy::k1"] && $e["arquivos"]["video"]=="t_video.mp4"'
mark; cb "basico::pack::k4"
chk "pack com FOTO: sendPhoto" 'count(tg("sendPhoto"))==1 && cbs(ultimo("sendPhoto"))==["basico::packbuy::k4"]'
mark; cb "basico::pack::k5"
chk "pack com ÁUDIO: sendAudio" 'count(tg("sendAudio"))==1'
mark; cb "basico::pack::k2"
chk "pack SEM mídia: mensagem de texto (nome com _ e * não quebra)" 'count(tg("sendMessage"))==1 && cbs(ultimo("sendMessage"))==["basico::packbuy::k2"] && count(tgerr())==0'
setflow guiado_base '{"packs":{"ativo":true,"itens":[{"id":"k1","ativo":true,"emoji":"📷","nome":"Aula 1","preco":9.9,"descricao":"x","midia_tipo":"video","midia_path":"uploads/nao_existe.mp4","id_grupo":"-1001000000001"}]}}'
mark; cb "basico::pack::k1"
chk "pack com mídia que sumiu do servidor: cai pra texto" 'count(tg("sendVideo"))==0 && count(tg("sendMessage"))==1'
setflow guiado_base '{"packs":{"ativo":true}}'
falha '{"sendVideo":"Bad Request: failed to get HTTP URL content"}'
mark; cb "basico::pack::k1"
chk "envio do vídeo do pack falha no Telegram: cai pra texto com o botão Comprar" 'count(tgok("sendMessage"))==1 && cbs(ultimo("sendMessage"))==["basico::packbuy::k1"]'
semfalha
mark; cb "basico::pack::k3"
chk "pack inativo: 'Esse pack não está mais disponível'" 'tem("não está mais dispon")'
mark; cb "basico::packbuy::zzz"
chk "compra de pack inexistente: mesma mensagem, sem Pix" 'tem("não está mais dispon") && count(gw())==0'
mark; cb "basico::packbuy::k1"; TX=$(ultimotx)
chk "comprar pack gera Pix de R\$ 9,90 com o nome do pack (sem emoji)" 'count(gw())==1 && abs(gw()[0]["valor"]-9.9)<0.001 && tem("Aula 1 — Exposição")'
chk "venda do pack: acesso longo (36500 dias) e grupo do pack" '($v='"$VENDA"') && $v["dias_acesso"]==36500 && $v["id_grupo_telegram"]=="-1001000000001"'
pagar "$TX"; mark; cb "verificar_pagamento_$TX"
chk "pack pago: acesso liberado no grupo do pack" 'tem("Acesso Liberado") && ($m='"$MEMBRO"') && $m["status"]=="ativo"'
chk "mensagem não diz 'expira em 36500 dias' pra um pack (acesso vitalício)" '!tem("36500")'
chk "cron de acessos não expulsa quem comprou pack" 'true'
mark; cron cron_verificar_acessos
chk "cron de acessos: pack ativo continua ativo" 'count(tg("banChatMember"))==0 && ('"$MEMBRO"')["status"]=="ativo"'
mark; cb "basico::packbuy::k4"; TX=$(ultimotx); pagar "$TX"; mark; cb "verificar_pagamento_$TX"
chk "pack sem grupo (k4): só confirma, sem convite" 'tem("Pagamento Confirmado") && count(tg("createChatInviteLink"))==0'
setflow guiado_base '{"packs":{"ativo":false}}'
mark; cb "basico::packs"
chk "packs desligados (botão antigo no chat): 'Nenhum pack disponível'" 'tem("Nenhum pack dispon")'

secao "G4.3 Renovação"
reset; setflow guiado_base '{"assinatura":{"renovacao":{"desconto":10}}}'
mark; cb "basico::renovar"
chk "lista com desconto: 'Renove agora com desconto especial' e preços com -10%" '($e=ultimo("sendMessage")) && strpos(txt($e),"desconto especial")!==false && ($t=array_column(botoes($e),"text")) && $t[0]=="Mensal — R$ 17,91 (-10%)" && $t[1]=="Anual — R$ 134,10 (-10%)"'
chk "callbacks de renovação por plano" 'cbs(ultimo("sendMessage"))[0]=="basico::renovplano::p_mensal"'
mark; cb "basico::renovplano::p_mensal"; TX=$(ultimotx)
chk "Pix da renovação: R\$ 17,91, plano Mensal, mesmo grupo" 'count(gw())==1 && abs(gw()[0]["valor"]-17.91)<0.001 && ($v='"$VENDA"') && $v["id_grupo_telegram"]=="-1001000000001" && $v["dias_acesso"]==30 && tem("Mensal")'
mark; cb "basico::renovplano::nao_existe"
chk "plano de renovação inexistente: 'não está mais disponível para renovação'" 'tem("para renova")'
setflow guiado_base '{"assinatura":{"renovacao":{"desconto":0}}}'
mark; cb "basico::renovar"
chk "sem desconto: 'Escolha o plano para renovar' e preço cheio, sem (-x%)" '($e=ultimo("sendMessage")) && strpos(txt($e),"Escolha o plano")!==false && ($t=array_column(botoes($e),"text")) && $t[0]=="Mensal — R$ 19,90" && strpos($t[0],"%")===false'
setflow guiado_base '{"assinatura":{"renovacao":{"desconto":90}}}'
mark; cb "basico::renovar"
chk "desconto de 90% é limitado ao mínimo aceito pela gateway (R\$ 2,10 com o split atual)" '($t=array_column(botoes(ultimo("sendMessage")),"text")) && strpos($t[3],"R$ 2,10")!==false'
mark; cb "basico::renovplano::p_min"
chk "Pix da renovação com desconto limitado nunca fica abaixo do mínimo" 'count(gw())==1 && gw()[0]["valor"]>=2.1-0.001 && count(tgerr())==0'
setflow guiado_base '{"planos!":[{"id":"barato","nome":"Barato","valor":1.5,"dias_acesso":7,"unidade_acesso":"dias","id_grupo":""},{"id":"ok","nome":"Normal","valor":30,"dias_acesso":30,"unidade_acesso":"dias","id_grupo":""}],"assinatura":{"renovacao":{"desconto":10}}}'
mark; cb "basico::renovar"
chk "plano já abaixo do mínimo da gateway não aparece na renovação" 'cbs(ultimo("sendMessage"))==["basico::renovplano::ok"]'
setflow guiado_base '{"planos!":[{"id":"barato","nome":"Barato","valor":1.5,"dias_acesso":7,"unidade_acesso":"dias","id_grupo":""}]}'
mark; cb "basico::renovar"
chk "nenhum plano elegível: 'Nenhum plano disponível para renovar'" 'tem("Nenhum plano dispon")'
echo; echo "FIM G4"
