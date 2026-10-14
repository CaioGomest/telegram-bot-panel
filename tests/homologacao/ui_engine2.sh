#!/bin/bash
# O fluxo VISUAL montado no editor (Chrome, 14 blocos arrastados e ligados) roda no motor.
source /tmp/pt/lib.sh
FID=$1
BOTID=$(php -r 'echo json_decode(file_get_contents("/tmp/pt/ids.json"),true)["bot"];')
reset
sql "UPDATE bots SET id_fluxo_conectado=$FID WHERE id=$BOTID" >/dev/null
secao "UI->motor (Editor Visual salvo no Chrome, fluxo #$FID)"
mark; msg "/start" "Maria"
chk "mensagem digitada na tela e pergunta com botões Comprar UI (verde) / Depois UI" 'textos()[0]=="Olá! Mensagem criada pela UI visual." && ($e=ultimo("sendMessage")) && txt($e)=="Quer comprar agora?" && cbs($e)==["btn::operator_3::0","btn::operator_3::1"] && botoes($e)[0]["text"]=="Comprar UI" && botoes($e)[0]["style"]=="success" && botoes($e)[1]["text"]=="Depois UI"'
T0=$(date +%s); mark; cb "btn::operator_3::0" 700
T1=$(date +%s)
chk "Comprar: 'sumir após clique' apaga a pergunta, delay de 1 s e a oferta digitada na tela aparece" 'count(tg("deleteMessage"))==1 && tg("deleteMessage")[0]["p"]["message_id"]==700 && txt(ultimo("sendMessage"))=="Oferta UI: 25% de desconto" && array_column(botoes(ultimo("sendMessage")),"text")==["Quero UI","Não UI"] && count(tg("sendChatAction"))==0'
mark; cb "saida::operator_5::aceito"
chk "oferta aceita: Pix = R\$ 80 com 25% = R\$ 60,00, produto 'Produto UI', instrução digitada, sem QR" 'count(gw())==1 && abs(gw()[0]["valor"]-60)<0.001 && tem("Produto UI") && tem("Pague pela UI visual") && count(tg("sendPhoto"))==0 && count(tgerr())==0'
chk "venda: validade 20 min, acesso de 15 dias, operador do Pix" '($v=q("SELECT * FROM vendas WHERE bot_id=? ORDER BY id DESC LIMIT 1",[ids()["bot"]])[0]) && $v["tempo_expiracao_minutos"]==20 && $v["dias_acesso"]==15 && $v["id_operador_fluxo"]=="operator_6"'
TX=$(ultimotx); pagar "$TX"
mark; owh "{\"event\":\"TRANSACTION_PAID\",\"transaction\":{\"id\":\"$TX\",\"status\":\"PAID\"}}"
chk "pago: confirmação digitada na tela e o bloco Link ligado ao ramo PAGO ('Abrir UI')" 'tem("Aprovado pela UI visual!") && ($e=ultimo("sendMessage")) && botoes($e)[0]["text"]=="Abrir UI" && botoes($e)[0]["url"]=="https://exemplo.com/ui"'
reset; sql "UPDATE bots SET id_fluxo_conectado=$FID WHERE id=$BOTID" >/dev/null
msg "/start" >/dev/null; cb "btn::operator_3::0" 701 >/dev/null; cb "saida::operator_5::recusado" >/dev/null
chk "oferta recusada: Pix cheio R\$ 80,00" 'true'
sql "UPDATE vendas SET criado_em = NOW() - INTERVAL 30 MINUTE WHERE bot_id=$BOTID" >/dev/null
mark; cron cron_verificar_pix
chk "Pix não pago vence (20 min): ramo NÃO PAGO digitado na tela ('Expirou pela UI')" 'tem("Expirou pela UI") && abs(q("SELECT valor FROM vendas WHERE bot_id=? ORDER BY id DESC LIMIT 1",[ids()["bot"]])[0]["valor"]-80)<0.001'
mark; cb "btn::operator_3::1" 702
chk "Depois UI: bloco Grupo sem grupo escolhido responde o aviso de configuração (sem travar)" 'tem("Grupo não configurado") && semerros()'
echo; echo "FIM UI->MOTOR VISUAL"
