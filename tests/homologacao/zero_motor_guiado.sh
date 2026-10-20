#!/bin/bash
# O fluxo GUIADO criado DO ZERO pela tela (ui/zero_guiado.js) roda no motor com Telegram e Pix simulados:
# cada coisa digitada/escolhida no editor tem que aparecer no que o cliente recebe. Uso: bash zero_motor_guiado.sh <id do fluxo>
source /tmp/pt/lib.sh
FID=$1
BOTID=$(php -r 'echo json_decode(file_get_contents("/tmp/pt/ids.json"),true)["bot"];')
eval "$(php /tmp/pt/zero_ids.php "$FID")"
VENDA='q("SELECT * FROM vendas WHERE bot_id=? ORDER BY id DESC LIMIT 1",[ids()["bot"]])[0]'
MEMBRO='q("SELECT * FROM membros_grupos WHERE bot_id=? ORDER BY id DESC LIMIT 1",[ids()["bot"]])[0]'
novo() { janela; reset; sql "UPDATE bots SET id_fluxo_conectado=$FID WHERE id=$BOTID" >/dev/null; msg "/start" Maria >/dev/null; mark; }

secao "ZG1 Boas-vindas e lista de planos (tudo o que foi digitado na tela)"
janela; reset; sql "UPDATE bots SET id_fluxo_conectado=$FID WHERE id=$BOTID" >/dev/null
mark; msg "/start" "Maria"
chk "boas-vindas: a foto anexada na tela sai como sendPhoto, com o texto digitado na legenda" '($e=ultimo("sendPhoto")) && txt($e)=="Olá! Bem-vindo ao ZERO 🚀\nSegunda linha da mensagem" && count(tg("sendMessage"))==0'
chk "botão de boas-vindas: texto 'Quero entrar agora' na cor verde (success), leva para a lista de planos" '($b=botoes(ultimo("sendPhoto"))) && count($b)==1 && $b[0]["text"]=="Quero entrar agora" && $b[0]["style"]=="success" && cbs(ultimo("sendPhoto"))==["basico::ver_planos"]'
mark; cb "basico::ver_planos"
chk "lista: os 4 planos (nome — preço, cor de cada um), depois Prévias, Packs e o suporte" '($b=botoes(ultimo("sendMessage"))) && count($b)==7 && $b[0]["text"]=="Mensal Zero — R$ 19,90" && $b[0]["style"]=="primary" && $b[1]["text"]=="Passe 2h Zero — R$ 5,00" && $b[1]["style"]=="success" && $b[2]["text"]=="Teste 90min Zero — R$ 3,50" && $b[2]["style"]=="danger" && $b[3]["text"]=="Anual Zero — R$ 199,00" && !isset($b[3]["style"]) && $b[4]["text"]=="👀 Prévias Zero" && $b[5]["text"]=="📦 Meus Packs Zero" && $b[6]["text"]=="💬 Falar com o suporte" && $b[6]["url"]=="https://t.me/suporte_zero" && $b[6]["style"]=="primary"'
chk "sem erro de PHP no caminho todo" 'semerros() && count(tgerr())==0'

secao "ZG2 Mensal -> aceita o Upsell (Anual -20%) -> aceita o Order Bump"
novo; cb "basico::plano::$P1"
chk "Upsell digitado na tela: texto, botões e cores" '($e=ultimo("sendMessage")) && txt($e)=="Leve o Anual com 20% OFF!" && ($b=botoes($e)) && $b[0]["text"]=="Quero o Anual" && $b[0]["style"]=="success" && $b[1]["text"]=="Prefiro o Mensal" && $b[1]["style"]=="danger" && cbs($e)==["basico::of::up::'"$P1"'::1","basico::of::up::'"$P1"'::0"]'
mark; cb "basico::of::up::$P1::1"
chk "aceitou o Upsell: Order Bump digitado (texto, botões e cores)" '($e=ultimo("sendMessage")) && txt($e)=="Adicione o Bônus VIP Zero por R$ 7,50?" && ($b=botoes($e)) && $b[0]["text"]=="Adicionar bônus" && $b[0]["style"]=="success" && $b[1]["text"]=="Sem bônus" && $b[1]["style"]=="danger"'
mark; cb "basico::of::bp::$P1::u::1"
chk "Pix = Anual (199) com 20% = 159,20 + bônus 7,50 = R\$ 166,70, produto 'Anual Zero + Bônus VIP Zero'" 'count(gw())==1 && abs(gw()[0]["valor"]-166.70)<0.001 && tem("Anual Zero + Bônus VIP Zero")'
chk "mensagem de instruções digitada; sem botão Copiar (desligado); QR Code em foto (ligado); código do Pix em texto" 'tem("Instruções Zero: copie o código e pague") && count(array_filter(botoes(ultimo("sendMessage")), fn($b)=>isset($b["copy_text"])))==0 && count(tg("sendPhoto"))==1 && strpos(txt(ultimo("sendMessage")),"00020126")!==false'
chk "botão de confirmar pagamento presente (ligado)" 'count(array_filter(cbs(ultimo("sendMessage")), fn($c)=>strpos($c,"verificar_pagamento_")===0))==1'
chk "venda: 365 dias (525600 min), grupo VIP, valor 166,70" '($v='"$VENDA"') && $v["tempo_acesso_minutos"]==525600 && abs($v["valor"]-166.70)<0.001 && in_array("-1001000000001", array_map("strval",$v), true)'
TX=$(ultimotx); pagar "$TX"
mark; cb "verificar_pagamento_$TX"
chk "pago: confirmação digitada na tela ('Confirmado Zero: acesso liberado!') e convite do grupo" 'tem("Confirmado Zero: acesso liberado!") && count(tg("createChatInviteLink"))>=1'
chk "membro criado com 365 dias de acesso (525600 min)" '($m='"$MEMBRO"') && abs(q1("SELECT TIMESTAMPDIFF(MINUTE, NOW(), data_expiracao) FROM membros_grupos WHERE id=?",[$m["id"]])-525600)<=2'

secao "ZG3 Mensal -> recusa o Upsell -> aceita o Downsell (90 min -10%) -> aceita o bônus"
novo; cb "basico::plano::$P1"
mark; cb "basico::of::up::$P1::0"
chk "recusou o Upsell: Downsell digitado (texto, botões e cores)" '($e=ultimo("sendMessage")) && txt($e)=="Que tal só 90 minutos por menos?" && ($b=botoes($e)) && $b[0]["text"]=="Pode ser" && $b[0]["style"]=="primary" && $b[1]["text"]=="Agora não" && !isset($b[1]["style"])'
mark; cb "basico::of::dn::$P1::1"
chk "aceitou o Downsell: Order Bump" 'txt(ultimo("sendMessage"))=="Adicione o Bônus VIP Zero por R$ 7,50?"'
mark; cb "basico::of::bp::$P1::d::1"
chk "Pix = 90 min (3,50) com 10% = 3,15 + 7,50 = R\$ 10,65, 'Teste 90min Zero + Bônus VIP Zero'" 'count(gw())==1 && abs(gw()[0]["valor"]-10.65)<0.001 && tem("Teste 90min Zero + Bônus VIP Zero")'
chk "venda: 90 minutos de acesso e sem grupo (plano sem grupo)" '($v='"$VENDA"') && $v["tempo_acesso_minutos"]==90 && !in_array("-1001000000001", array_map("strval",$v), true) && !in_array("-1001000000002", array_map("strval",$v), true)'

secao "ZG4 Mensal -> recusa Upsell e Downsell -> recusa o bônus (Pix cheio)"
novo; cb "basico::plano::$P1" >/dev/null; cb "basico::of::up::$P1::0" >/dev/null; mark; cb "basico::of::dn::$P1::0"
chk "recusou os dois: Order Bump" 'txt(ultimo("sendMessage"))=="Adicione o Bônus VIP Zero por R$ 7,50?"'
mark; cb "basico::of::bp::$P1::o::0"
chk "Pix = Mensal cheio R\$ 19,90, sem bônus no nome" 'count(gw())==1 && abs(gw()[0]["valor"]-19.90)<0.001 && tem("Mensal Zero") && !tem("Bônus VIP Zero")'
chk "venda: 30 dias (43200 min), grupo VIP" '($v='"$VENDA"') && $v["tempo_acesso_minutos"]==43200 && in_array("-1001000000001", array_map("strval",$v), true)'
TX=$(ultimotx); pagar "$TX"; cb "verificar_pagamento_$TX" >/dev/null
chk "membro do Mensal com 30 dias" '($m='"$MEMBRO"') && abs(q1("SELECT TIMESTAMPDIFF(MINUTE, NOW(), data_expiracao) FROM membros_grupos WHERE id=?",[$m["id"]])-43200)<=2'

secao "ZG5 Anual direto (o Upsell aponta para ele mesmo: pula) e Passe 2h (em horas)"
novo; mark; cb "basico::plano::$P4"
chk "plano Anual: o Upsell (que oferece o próprio Anual) é pulado e vai direto ao Downsell" 'txt(ultimo("sendMessage"))=="Que tal só 90 minutos por menos?"'
cb "basico::of::dn::$P4::0" >/dev/null
mark; cb "basico::of::bp::$P4::o::1"
chk "Pix = Anual 199,00 + bônus 7,50 = R\$ 206,50" 'count(gw())==1 && abs(gw()[0]["valor"]-206.50)<0.001 && tem("Anual Zero + Bônus VIP Zero")'
novo; cb "basico::plano::$P2" >/dev/null; cb "basico::of::up::$P2::0" >/dev/null; cb "basico::of::dn::$P2::0" >/dev/null
mark; cb "basico::of::bp::$P2::o::0"
chk "Passe 2h: Pix R\$ 5,00 e acesso de 120 minutos (unidade 'horas' da tela) no grupo Bônus" 'count(gw())==1 && abs(gw()[0]["valor"]-5.00)<0.001 && ($v='"$VENDA"') && $v["tempo_acesso_minutos"]==120 && in_array("-1001000000002", array_map("strval",$v), true)'

secao "ZG6 Packs (2 ativos, 1 desligado na tela)"
novo; mark; cb "basico::packs"
chk "lista de packs: texto digitado; só os 2 ativos aparecem (o desligado some)" '($e=ultimo("sendMessage")) && txt($e)=="Escolha seu pack Zero:" && ($t=array_column(botoes($e),"text")) && count($t)==2 && $t[0]=="🔥 Pack Hot Zero — R$ 29,90" && $t[1]=="🎬 Pack Vídeo Zero — R$ 39,90"'
mark; cb "basico::pack::$K1"
chk "pack 1: prévia com a FOTO anexada na tela, descrição e botão Comprar" '($e=ultimo("sendPhoto")) && strpos(txt($e),"Descrição do pack hot")!==false && strpos(botoes($e)[0]["text"],"Comprar")!==false && cbs($e)==["basico::packbuy::'"$K1"'"]'
mark; cb "basico::pack::$K2"
chk "pack 2: prévia com o VÍDEO anexado" '($e=ultimo("sendVideo")) && strpos(txt($e),"Descrição do pack vídeo")!==false'
mark; cb "basico::packbuy::$K1"
chk "comprar o pack 1: Pix de R\$ 29,90 no grupo VIP" 'count(gw())==1 && abs(gw()[0]["valor"]-29.90)<0.001 && in_array("-1001000000001", array_map("strval",'"$VENDA"'), true)'
mark; cb "basico::pack::$K3"
chk "pack desligado na tela: link antigo não vende (mensagem de indisponível)" 'count(gw())==0'

secao "ZG7 Prévias que somem (3 mídias: áudio, foto, vídeo; 30 s; protegidas; 1 vez a cada 24 h)"
novo; mark; cb "basico::previa"
chk "ordem do envio: mensagem junto, depois Áudio, Foto e Vídeo (a ordem montada na tela)" 'array_values(array_diff(sequencia(),["answerCallbackQuery"]))==["sendMessage","sendAudio","sendPhoto","sendVideo"] && txt(tg("sendMessage")[0])=="Corre! Somem em 30 segundos"'
chk "tudo protegido contra encaminhar/salvar" 'count(array_filter(array_merge(tg("sendMessage"),tg("sendAudio"),tg("sendPhoto"),tg("sendVideo")), fn($e)=>($e["p"]["protect_content"]??"")==="true"))==4'
sql "UPDATE mensagens_agendadas SET apagar_em = NOW() - INTERVAL 1 SECOND WHERE bot_id=$BOTID" >/dev/null
mark; cron cron_aviso_vencimento
chk "passados os 30 s: as 4 mensagens somem e vai a mensagem de 'depois' digitada, sem botão (desligado na tela)" 'count(tg("deleteMessage"))==4 && ($e=ultimo("sendMessage")) && txt($e)=="As prévias expiraram, o VIP te espera" && count(botoes($e))==0'
mark; cb "basico::previa"
chk "mesmo lead, de novo: 'ver de novo = 1 vez a cada 24 h' bloqueia e leva aos planos" 'count(tg("sendAudio"))==0 && strpos(txt(ultimo("sendMessage")),"Você já viu as prévias")!==false'
sql "UPDATE previas_vistas SET visto_em = NOW() - INTERVAL 25 HOUR WHERE bot_id=$BOTID" >/dev/null
mark; cb "basico::previa"
chk "25 h depois: libera de novo" 'count(tg("sendAudio"))==1'

secao "ZG8 Renovação e avisos (aba Assinatura)"
novo; cb "basico::plano::$P1" >/dev/null; cb "basico::of::up::$P1::0" >/dev/null; cb "basico::of::dn::$P1::0" >/dev/null; cb "basico::of::bp::$P1::o::0" >/dev/null
TX=$(ultimotx); pagar "$TX"; cb "verificar_pagamento_$TX" >/dev/null
mark; cb "basico::renovar"
chk "renovar: planos com 25% de desconto (digitado na tela)" '($t=array_column(botoes(ultimo("sendMessage")),"text")) && count($t)>=1 && strpos($t[0],"Mensal Zero — R$ 14,9")===0 && strpos($t[0],"(-25%)")!==false'
janela; sql "UPDATE membros_grupos SET criado_em = NOW() - INTERVAL 20 DAY, data_expiracao = NOW() + INTERVAL 160 HOUR WHERE bot_id=$BOTID" >/dev/null
mark; cron cron_aviso_vencimento
chk "faltam ~6,7 dias (janela do aviso de 7 dias): sai o aviso digitado (com {nome}, {plano}, {prazo}, {dias} preenchidos), com a FOTO e o botão Renovar" '($e=ultimo("sendPhoto")) && preg_match("/^Oi Maria Teste, o plano Mensal Zero expira em [67] dias[^.]*\. Renove com [67] dias de folga$/u", txt($e)) && cbs($e)==["basico::renovar"]'
sql "UPDATE membros_grupos SET data_expiracao = NOW() - INTERVAL 1 MINUTE WHERE bot_id=$BOTID" >/dev/null
mark; cron cron_verificar_acessos
chk "expirou com 'Expulsar do grupo' DESLIGADO na tela: ninguém é banido" 'count(tg("banChatMember"))==0 && count(tg("unbanChatMember"))==0'
chk "membro vira expirado e recebe a mensagem digitada na tela ('expirou! Renove agora') com o ÁUDIO anexado" '('"$MEMBRO"')["status"]=="expirado" && tem("expirou! Renove agora","sendAudio") && cbs(ultimo("sendAudio"))==["basico::renovar"]'

echo; echo "FIM ZERO->MOTOR GUIADO"
