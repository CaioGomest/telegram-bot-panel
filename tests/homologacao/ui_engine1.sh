#!/bin/bash
# O fluxo GUIADO salvo pelo editor (Chrome) roda no motor: mesmos números que foram digitados na tela.
source /tmp/pt/lib.sh
FID=$1
BOTID=$(php -r 'echo json_decode(file_get_contents("/tmp/pt/ids.json"),true)["bot"];')
reset
sql "UPDATE bots SET id_fluxo_conectado=$FID WHERE id=$BOTID" >/dev/null
P1=$(php /tmp/pt/db.php "SELECT JSON_UNQUOTE(JSON_EXTRACT(dados_fluxograma,'\$.planos[0].id')) i FROM fluxos WHERE id=$FID" | php -r '$j=json_decode(trim(stream_get_contents(STDIN)),true); echo $j["i"];')
P2=$(php /tmp/pt/db.php "SELECT JSON_UNQUOTE(JSON_EXTRACT(dados_fluxograma,'\$.planos[1].id')) i FROM fluxos WHERE id=$FID" | php -r '$j=json_decode(trim(stream_get_contents(STDIN)),true); echo $j["i"];')
secao "UI->motor (Guiado salvo pelo editor no Chrome, fluxo #$FID)"
mark; msg "/start" "Maria"
chk "boas-vindas digitada na tela, botão 'Quero entrar' verde" '($e=ultimo("sendMessage")) && txt($e)=="Olá pela UI! Bem-vindo ao curso de teste." && botoes($e)[0]["text"]=="Quero entrar" && botoes($e)[0]["style"]=="success"'
mark; cb "basico::ver_planos"
chk "lista: 'Mensal UI — R\$ 19,90' (azul), 'Passe 2h UI — R\$ 5,00' e suporte @suporte_ui" '($b=botoes(ultimo("sendMessage"))) && $b[0]["text"]=="Mensal UI — R$ 19,90" && $b[0]["style"]=="primary" && $b[1]["text"]=="Passe 2h UI — R$ 5,00" && end($b)["url"]=="https://t.me/suporte_ui"'
mark; cb "basico::plano::$P1"
chk "upsell digitado na tela aparece (mensagem 'Upsell da UI')" 'txt(ultimo("sendMessage"))=="Upsell da UI"'
mark; cb "basico::of::up::$P1::1"
chk "aceitou upsell: aparece o bump 'Bump da UI'" 'txt(ultimo("sendMessage"))=="Bump da UI"'
mark; cb "basico::of::bp::$P1::u::1"
chk "Pix = Passe 2h com 15% (4,25) + bônus 7,50 = R\$ 11,75, nome 'Passe 2h UI + Bônus UI'" 'count(gw())==1 && abs(gw()[0]["valor"]-11.75)<0.001 && tem("Passe 2h UI + Bônus UI") && tem("Pague agora pela UI")'
chk "venda do plano em horas: 120 minutos" '($v=q("SELECT * FROM vendas WHERE bot_id=? ORDER BY id DESC LIMIT 1",[ids()["bot"]])[0]) && $v["tempo_acesso_minutos"]==120'
TX=$(ultimotx); pagar "$TX"
mark; cb "verificar_pagamento_$TX"
chk "pago: confirmação personalizada digitada na tela ('Aprovado pela UI!')" 'tem("Aprovado pela UI!")'
mark; cb "basico::renovar"
chk "renovação com 10% de desconto configurado na tela" '($t=array_column(botoes(ultimo("sendMessage")),"text")) && $t[0]=="Mensal UI — R$ 17,91 (-10%)"'
echo; echo "FIM UI->MOTOR"
