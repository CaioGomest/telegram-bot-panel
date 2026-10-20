#!/bin/bash
# O fluxo VISUAL criado DO ZERO pela tela (ui/zero_visual.js: 18 blocos, 22 ligações) roda no motor com Telegram e Pix simulados.
# Uso: bash zero_motor_visual.sh <id do fluxo>   (os blocos são operator_2.. na ordem em que o teste de tela os soltou)
source /tmp/pt/lib.sh
FID=$1
BOTID=$(php -r 'echo json_decode(file_get_contents("/tmp/pt/ids.json"),true)["bot"];')
VENDA='q("SELECT * FROM vendas WHERE bot_id=? ORDER BY id DESC LIMIT 1",[ids()["bot"]])[0]'
CONT='q("SELECT * FROM fluxo_continuacoes WHERE bot_id=? ORDER BY id",[ids()["bot"]])'
AGEND='q("SELECT TIMESTAMPDIFF(SECOND, NOW(), apagar_em) AS s FROM mensagens_agendadas WHERE bot_id=? ORDER BY id",[ids()["bot"]])'
novo() { janela; reset; sql "UPDATE bots SET id_fluxo_conectado=$FID WHERE id=$BOTID" >/dev/null; }
sorteio() { php /tmp/pt/zero_pesos.php "$FID" operator_8 "$1" "$2" "$3" >/dev/null; }

secao "ZV1 /start: mensagem, foto, vídeo, áudio e a pergunta com 3 botões"
sorteio 100 0 0
novo; mark; msg "/start" "Maria"
chk "ordem do envio: Mensagem, Foto, Vídeo, Áudio (vai como mensagem de voz), Botões (a ordem das ligações montada na tela)" 'array_values(array_diff(sequencia(),["answerCallbackQuery","sendChatAction"]))==["sendMessage","sendPhoto","sendVideo","sendVoice","sendMessage"]'
chk "mensagem 1 com o texto digitado" 'txt(tg("sendMessage")[0])=="Boas-vindas do funil visual criado do zero 🚀"'
chk "foto: legenda digitada e spoiler ligado" '($e=ultimo("sendPhoto")) && txt($e)=="Legenda da foto zero" && in_array(($e["p"]["has_spoiler"]??""), [true,"true",1,"1"], true)'
chk "vídeo: legenda digitada e spoiler ligado" '($e=ultimo("sendVideo")) && txt($e)=="Legenda do vídeo zero" && in_array(($e["p"]["has_spoiler"]??""), [true,"true",1,"1"], true)'
chk "áudio (enviado como mensagem de voz): legenda digitada, sem spoiler (o áudio não tem)" '($e=ultimo("sendVoice")) && txt($e)=="Legenda do áudio zero" && !isset($e["p"]["has_spoiler"])'
chk "auto-deletar: 3 mensagens agendadas para sumir em ~40, ~45 e ~50 segundos (valores digitados na tela)" '($a=array_column('"$AGEND"',"s")) && count($a)==3 && $a[0]>=34 && $a[0]<=41 && $a[1]>=39 && $a[1]<=46 && $a[2]>=44 && $a[2]<=51'
chk "pergunta: texto digitado e 3 botões com cores (verde, azul, vermelho)" '($e=ultimo("sendMessage")) && txt($e)=="Quer ver as ofertas?" && ($b=botoes($e)) && count($b)==3 && $b[0]["text"]=="Sim, mostrar" && $b[0]["style"]=="success" && $b[1]["text"]=="Talvez depois" && $b[1]["style"]=="primary" && $b[2]["text"]=="Não quero" && $b[2]["style"]=="danger" && cbs($e)==["btn::operator_6::0","btn::operator_6::1","btn::operator_6::2"]'
chk "sem erro de PHP e sem recusa do Telegram" 'semerros() && count(tgerr())==0'

secao "ZV2 Sim -> espera 1 s (digitando) -> Sorteio -> Upsell (25%) -> aceita -> Order bump (R\$ 7,50) -> aceita -> Pix"
sorteio 100 0 0
T0=$(date +%s); mark; cb "btn::operator_6::0" 700; T1=$(date +%s)
chk "'Sumir após clique': a pergunta (mensagem 700) é apagada" 'count(tg("deleteMessage"))==1 && tg("deleteMessage")[0]["p"]["message_id"]==700'
chk "espera de 1 s com 'digitando…' (ligado na tela) antes do Sorteio" 'count(tg("sendChatAction"))>=1 && tg("sendChatAction")[0]["p"]["action"]=="typing"'
[ $((T1 - T0)) -ge 1 ] && [ $((T1 - T0)) -le 6 ] && echo "  PASS | a requisição esperou ~1 s (levou $((T1 - T0)) s)" || echo "  FAIL | espera de 1 s: levou $((T1 - T0)) s"
chk "Sorteio com pesos 100/0/0 (editados só no teste) leva ao Upsell digitado: texto, botões e cores" '($e=ultimo("sendMessage")) && txt($e)=="Upsell zero: leve o plano maior com 25% OFF" && ($b=botoes($e)) && $b[0]["text"]=="Quero o maior" && $b[0]["style"]=="success" && $b[1]["text"]=="Fico no atual" && $b[1]["style"]=="danger" && cbs($e)==["saida::operator_13::aceito","saida::operator_13::recusado"]'
mark; cb "saida::operator_13::aceito"
chk "Upsell aceito -> Order bump digitado (texto, botões e cores)" '($e=ultimo("sendMessage")) && txt($e)=="Order bump zero: leve o bônus por R$ 7,50" && ($b=botoes($e)) && $b[0]["text"]=="Adicionar bônus" && $b[0]["style"]=="success" && $b[1]["text"]=="Sem bônus" && $b[1]["style"]=="danger"'
mark; cb "saida::operator_15::aceito"
chk "Pix = R\$ 80 com 25% = 60,00 + bônus 7,50 = R\$ 67,50 (o nome do extra é o título do bloco Order bump)" 'count(gw())==1 && abs(gw()[0]["valor"]-67.50)<0.001 && tem("Produto Visual Zero + Order bump")'
chk "instruções digitadas; sem botão Copiar (desligado); QR Code em foto (ligado); botão de confirmar" 'tem("Pague pelo visual zero") && count(array_filter(botoes(ultimo("sendMessage")), fn($b)=>isset($b["copy_text"])))==0 && count(tg("sendPhoto"))==1 && count(array_filter(cbs(ultimo("sendMessage")), fn($c)=>strpos($c,"verificar_pagamento_")===0))==1'
chk "venda: validade 20 min, acesso 15 dias, grupo VIP, operador do Pix (operator_16)" '($v='"$VENDA"') && $v["tempo_expiracao_minutos"]==20 && $v["dias_acesso"]==15 && $v["id_operador_fluxo"]=="operator_16" && in_array("-1001000000001", array_map("strval",$v), true)'
TX=$(ultimotx); pagar "$TX"
mark; owh "{\"event\":\"TRANSACTION_PAID\",\"transaction\":{\"id\":\"$TX\",\"status\":\"PAID\"}}"
chk "pago: confirmação digitada ('Aprovado pelo visual zero!') e, pelo ramo PAGO, a mensagem final" 'tem("Aprovado pelo visual zero!") && tem("Pago! Obrigado pelo visual zero")'
chk "convite do grupo VIP gerado" 'count(tg("createChatInviteLink"))>=1'

secao "ZV3 Recusa o Upsell -> Downsell (10%) -> aceita -> bônus -> recusa (Pix com 10%)"
novo; msg "/start" "Maria" >/dev/null; cb "btn::operator_6::0" 700 >/dev/null; mark; cb "saida::operator_13::recusado"
chk "Upsell recusado leva ao Downsell digitado: texto, botões e cores (o 2º sem cor)" '($e=ultimo("sendMessage")) && txt($e)=="Downsell zero: que tal este com 10% OFF?" && ($b=botoes($e)) && $b[0]["text"]=="Pode ser" && $b[0]["style"]=="primary" && $b[1]["text"]=="Agora não" && !isset($b[1]["style"])'
mark; cb "saida::operator_14::aceito"
chk "Downsell aceito leva ao Order bump" 'txt(ultimo("sendMessage"))=="Order bump zero: leve o bônus por R$ 7,50"'
mark; cb "saida::operator_15::recusado"
chk "bônus recusado: Pix = R\$ 80 com 10% = R\$ 72,00, sem extra no nome" 'count(gw())==1 && abs(gw()[0]["valor"]-72.00)<0.001 && tem("Produto Visual Zero") && !tem("Order bump")'

secao "ZV4 Downsell recusado -> Pix direto (R\$ 80,00); Pix não pago vence em 20 min -> ramo NÃO PAGO"
novo; msg "/start" "Maria" >/dev/null; cb "btn::operator_6::0" 700 >/dev/null; cb "saida::operator_13::recusado" >/dev/null; mark; cb "saida::operator_14::recusado"
chk "Downsell recusado vai direto ao Pix, cheio: R\$ 80,00" 'count(gw())==1 && abs(gw()[0]["valor"]-80.00)<0.001'
sql "UPDATE vendas SET criado_em = NOW() - INTERVAL 30 MINUTE WHERE bot_id=$BOTID" >/dev/null
mark; cron cron_verificar_pix
chk "Pix vence (20 min digitados na tela): ramo NÃO PAGO envia a mensagem digitada" 'tem("O Pix visual zero expirou")'

secao "ZV5 Os outros caminhos do Sorteio e os outros botões da pergunta"
sorteio 0 100 0
novo; msg "/start" "Maria" >/dev/null; mark; cb "btn::operator_6::0" 700
chk "Sorteio 0/100/0 vai ao Downsell" 'txt(ultimo("sendMessage"))=="Downsell zero: que tal este com 10% OFF?"'
sorteio 0 0 100
novo; msg "/start" "Maria" >/dev/null; mark; cb "btn::operator_6::0" 700
chk "Sorteio 0/0/100 vai à mensagem do caminho 3 e o fluxo termina ali" 'txt(ultimo("sendMessage"))=="Caminho 3 do sorteio zero" && count(gw())==0 && count(tgerr())==0'
sorteio 50 30 20
novo; msg "/start" "Maria" >/dev/null; mark; cb "btn::operator_6::2" 700
chk "botão 'Não quero': bloco Grupo gera o convite do grupo VIP e mostra o botão digitado" 'count(tg("createChatInviteLink"))==1 && ($e=ultimo("sendMessage")) && botoes($e)[0]["text"]=="Entrar no grupo zero" && strpos(botoes($e)[0]["url"],"http")===0'
novo; msg "/start" "Maria" >/dev/null; mark; cb "btn::operator_6::1" 701
chk "botão 'Talvez depois': a espera de 2 a 3 MINUTOS (sem 'digitando') vira uma continuação agendada, nada sai agora" 'count(tg("sendChatAction"))==0 && ($c='"$CONT"') && count($c)==1 && $c[0]["status"]=="pendente" && $c[0]["id_operador_destino"]=="operator_10" && abs(strtotime($c[0]["executar_em"])-time())>=110 && abs(strtotime($c[0]["executar_em"])-time())<=190'
janela
sql "UPDATE fluxo_continuacoes SET executar_em = NOW() - INTERVAL 1 SECOND WHERE bot_id=$BOTID" >/dev/null
mark; cron cron_aviso_vencimento
chk "a cron retoma depois da espera: mensagem digitada e, ligado a ela, o bloco Link com o botão digitado" 'tem("Sem pressa, a oferta fica aqui") && ($e=ultimo("sendMessage")) && botoes($e)[0]["text"]=="Abrir oferta zero" && botoes($e)[0]["url"]=="https://exemplo.com/zero"'

secao "ZV6 Sorteio de verdade (pesos 50/30/20 digitados na tela): 15 execuções completas + 20 mil sorteios"
sorteio 50 30 20
rm -f /tmp/pt/zero_sorteio.txt
for i in $(seq 1 15); do novo; msg "/start" "Maria" >/dev/null; mark; cb "btn::operator_6::0" 700 >/dev/null; php /tmp/pt/zero_conta.php >> /tmp/pt/zero_sorteio.txt; done
U=$(grep -o U /tmp/pt/zero_sorteio.txt | wc -l); D=$(grep -o D /tmp/pt/zero_sorteio.txt | wc -l); M=$(grep -o M /tmp/pt/zero_sorteio.txt | wc -l); X=$(grep -o '?' /tmp/pt/zero_sorteio.txt | wc -l)
echo "  (15 execuções: Upsell=$U Downsell=$D Caminho3=$M sem caminho=$X)"
[ $X -eq 0 ] && echo "  PASS | nenhum lead fica sem caminho depois do Sorteio (15 execuções)" || echo "  FAIL | lead sem caminho depois do Sorteio: U=$U D=$D M=$M ?=$X"
php /tmp/pt/zero_sorteio_estat.php "$FID"
sorteio 50 30 20

echo; echo "FIM ZERO->MOTOR VISUAL"
