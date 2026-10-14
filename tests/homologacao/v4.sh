#!/bin/bash
# VISUAL 4: bloco Pix (único e recorrente), pós-pagamento pelos 3 caminhos, expiração, ciclos de assinatura.
source /tmp/pt/lib.sh
BOTID=$(php -r 'echo json_decode(file_get_contents("/tmp/pt/ids.json"),true)["bot"];')
UID_=$(php -r 'echo json_decode(file_get_contents("/tmp/pt/ids.json"),true)["uid"];')
VENDA='q("SELECT * FROM vendas WHERE bot_id=? ORDER BY id DESC LIMIT 1",[ids()["bot"]])[0]'
MEMBRO='q("SELECT * FROM membros_grupos WHERE bot_id=? ORDER BY id DESC LIMIT 1",[ids()["bot"]])[0]'
payload() { echo "{\"event\":\"TRANSACTION_PAID\",\"transaction\":{\"id\":\"$1\",\"status\":\"${2:-PAID}\"}}"; }
CADEIA_PAGO='tem("Pós-pagamento 1") && tem("Entrar no grupo pós-pago", "sendMessage") === false && count(array_filter(tg("sendMessage"),fn($e)=>($b=botoes($e)) && $b && $b[0]["text"]=="Entrar no grupo pós-pago"))==1 && tem("Pós-pagamento 2 (fim)")'

secao "V4.1 Pix único: geração"
reset; setflow v_pix
mark; msg "/start"; TX=$(ultimotx)
chk "mensagem antes do Pix e depois o pedido (Markdown) com instruções personalizadas" 'textos()[0]=="Vai pagar?" && ($e=tg("sendMessage")[1]) && strpos(txt($e),"Pedido Criado")!==false && strpos(txt($e),"R$ 29,90")!==false && strpos(txt($e),"Pague em ".chr(92)."*15 min".chr(92)."*")!==false && !tem("Aguardando pagamento") && count(tgerr())==0'
chk "QR Code gerado localmente e enviado como foto com a legenda" '($q=tg("sendPhoto")) && count($q)==1 && txt($q[0])=="Escaneie o QR Code para pagar." && strpos($q[0]["arquivos"]["photo"],"qrpix_")===0'
chk "código copia-e-cola em <code> com botão Copiar + Já fiz" '($e=tg("sendMessage")[2]) && strpos(txt($e),"<code>0002")===0 && count(botoes($e))==2'
chk "ordem: texto, pedido, QR, código" 'sequencia()==["sendMessage","sendMessage","sendPhoto","sendMessage"]'
chk "venda: operador 'pix', grupo G1, 15 min de validade, gateway e fluxo" '($v='"$VENDA"') && $v["id_operador_fluxo"]=="pix" && $v["id_grupo_telegram"]=="-1001000000001" && $v["tempo_expiracao_minutos"]==15 && $v["status"]=="gerado" && $v["id_gateway"]==ids()["gw"] && $v["tipo_cobranca"]=="unica"'
chk "evento de rastreio 'pix_gerado' enviado com os dados do lead" '($t=array_filter(ev("track"),fn($e)=>$e["track"]=="pix_gerado")) && count($t)==1 && array_values($t)[0]["user_data"]["id_telegram"]=="777000111" && semerros()'
chk "webhook do usuário payment_created disparado com o código Pix" '($w=array_values(array_filter(ev("whk"),fn($e)=>$e["whk"]=="payment_created"))) && count($w)==1 && strpos($w[0]["ctx"]["pix_code"],"0002")===0'

secao "V4.2 Pix único: pagamento libera o ramo 'PAGO' (3 caminhos)"
reset; setflow v_pix; msg "/start" >/dev/null; TX=$(ultimotx); pagar "$TX"
mark; owh "$(payload $TX PAID)"
chk "webhook da gateway: convite G1 -> confirmação personalizada -> msg pós 1 -> convite G2 + botão -> msg pós 2" 'sequencia()==["createChatInviteLink","sendMessage","sendMessage","createChatInviteLink","sendMessage","sendMessage"] && tem("Aprovado! 🎉") && '"$CADEIA_PAGO"
chk "membro do grupo G1 ativo ~30 dias; venda paga" '($m='"$MEMBRO"') && $m["id_grupo_telegram"]=="-1001000000001" && abs(strtotime($m["expira"] ?? $m["data_expiracao"])-time()-30*86400)<180 && ('"$VENDA"')["status"]=="pago"'
reset; setflow v_pix; msg "/start" >/dev/null; TX=$(ultimotx); pagar "$TX"
mark; cb "verificar_pagamento_$TX"
chk "botão 'Já fiz o pagamento': mesma cadeia 'PAGO' do fluxo" 'tem("Aprovado! 🎉") && '"$CADEIA_PAGO"' && count(tgerr())==0'
reset; setflow v_pix; msg "/start" >/dev/null; TX=$(ultimotx); pagar "$TX"
mark; cron cron_verificar_pix
chk "cron de verificação: mesma cadeia 'PAGO' do fluxo" 'tem("Aprovado! 🎉") && '"$CADEIA_PAGO"' && count(tgerr())==0'
mark; cron cron_verificar_pix; owh "$(payload $TX PAID)"; cb "verificar_pagamento_$TX"
chk "reprocessar (cron + webhook + botão) não repete a cadeia" 'count(tg("sendMessage"))<=1 && count(tg("createChatInviteLink"))==0'
reset; setflow v_pix '{"operators":{"pix":{"properties":{"msg_confirmado":""}}}}'; msg "/start" >/dev/null; TX=$(ultimotx); pagar "$TX"
mark; owh "$(payload $TX PAID)"
chk "sem msg_confirmado: texto padrão 'Pagamento Confirmado'" 'tem("Pagamento Confirmado") && !tem("Aprovado")'
reset; setflow v_pix '{"links!":[{"fromOperator":"start","fromConnector":"output_1","toOperator":"m1","toConnector":"input_1"},{"fromOperator":"m1","fromConnector":"output_1","toOperator":"pix","toConnector":"input_1"}]}'; msg "/start" >/dev/null; TX=$(ultimotx); pagar "$TX"
mark; owh "$(payload $TX PAID)"
chk "Pix sem saída 'PAGO' ligada: confirma o pagamento e entrega o grupo, sem erro" '('"$VENDA"')["status"]=="pago" && semerros() && tem("Acesso Liberado")'

secao "V4.3 Pix expirado: ramo 'NÃO PAGO'"
reset; setflow v_pix; msg "/start" >/dev/null; TX=$(ultimotx)
sql "UPDATE vendas SET criado_em = NOW() - INTERVAL 20 MINUTE WHERE bot_id=$BOTID" >/dev/null
mark; cron cron_verificar_pix
chk "venda expira e o cliente recebe a mensagem do ramo 'não pago'" '('"$VENDA"')["status"]=="expirado" && textos()==["⏰ Pix expirou sem pagamento"]'
mark; cron cron_verificar_pix
chk "cron seguinte não repete" 'count(tg("sendMessage"))==0'
reset; setflow v_pix; msg "/start" >/dev/null; TX=$(ultimotx); pagar "$TX"
sql "UPDATE vendas SET criado_em = NOW() - INTERVAL 20 MINUTE WHERE bot_id=$BOTID" >/dev/null
mark; cron cron_verificar_pix
chk "pago na gateway mas já vencido: o cron confirma como pago (não expira) e roda o ramo PAGO" '('"$VENDA"')["status"]=="pago" && tem("Pós-pagamento 1") && !tem("Pix expirou")'
reset; setflow v_pix '{"operators":{"pix":{"properties":{"expiracao_minutos":5}}}}'
mark; msg "/start"
chk "expiracao_minutos=5: venda com 5 min e vencimento na gateway em ~5 min" '('"$VENDA"')["tempo_expiracao_minutos"]==5 && abs(strtotime(gw()[0]["dueDate"])-time()-300)<90'
reset; setflow v_pix '{"operators":{"pix":{"properties":{"expiracao_minutos":null,"tempo_nao_pago":7}}}}'
mark; msg "/start"
chk "propriedade antiga tempo_nao_pago=7 também é aceita" '('"$VENDA"')["tempo_expiracao_minutos"]==7'

secao "V4.4 Pix: flags, unidades e valores inválidos"
reset; setflow v_pix '{"operators":{"pix":{"properties":{"mostrar_qrcode":false,"mostrar_copiar":false,"mostrar_confirmar":false,"msg_instrucoes":""}}}}'
mark; msg "/start"
chk "tudo desligado: sem QR, sem teclado, texto padrão de instruções" 'count(tg("sendPhoto"))==0 && !isset(tg("sendMessage")[2]["p"]["reply_markup"]) && tem("Aguardando pagamento")'
reset; setflow v_pix '{"operators":{"pix":{"properties":{"unidade_acesso":"horas","dias_acesso":3,"id_grupo":""}}}}'
mark; msg "/start"; TX=$(ultimotx); pagar "$TX"; owh "$(payload $TX PAID)"
chk "acesso em 3 horas = 180 min; sem grupo no bloco: nenhum convite/membro" '($v='"$VENDA"') && $v["tempo_acesso_minutos"]==180 && $v["dias_acesso"]==0 && q1("SELECT COUNT(*) FROM membros_grupos WHERE bot_id=?",[ids()["bot"]])==0'
reset; setflow v_pix '{"operators":{"pix":{"properties":{"unidade_acesso":"minutos","dias_acesso":45}}}}'
mark; msg "/start"
chk "acesso em 45 minutos = 45 min" '('"$VENDA"')["tempo_acesso_minutos"]==45'
reset; setflow v_pix '{"operators":{"pix":{"properties":{"valor":0}}}}'
mark; msg "/start"
chk "valor 0: 'Erro: Valor inválido' ao cliente, nenhuma venda" 'tem("Valor inválido") && q1("SELECT COUNT(*) FROM vendas WHERE bot_id=?",[ids()["bot"]])==0'
reset; setflow v_pix '{"operators":{"pix":{"properties":{"nome":"","valor":10}}}}'
mark; msg "/start"
chk "Pix sem nome de produto: usa 'Produto'" 'tem("Produto:")'
reset; setflow v_pix
falha '{"gw:*":"erro simulado"}'
mark; msg "/start"
chk "gateway falhou no bloco Pix: mensagem amigável e o fluxo para (sem 'PAGO'/'NÃO PAGO')" 'tem("Não foi possível gerar seu Pix agora") && !tem("Pix expirou") && q1("SELECT COUNT(*) FROM vendas WHERE bot_id=?",[ids()["bot"]])==0'
semfalha

secao "V4.5 Pix recorrente (assinatura)"
reset; setflow v_recorrente
mark; msg "/start"; TX=$(ultimotx)
chk "usa o endpoint de ASSINATURA (não o Pix avulso) com valor, produto, periodicidade e split" 'count(gw())==1 && gw()[0]["gw"]=="POST /gateway/pix/subscription" && abs(gw()[0]["valor"]-49.9)<0.001 && gw()[0]["produto"]=="Assinatura VIP" && gw()[0]["periodicidade"]["periodicityType"]=="MONTHS" && gw()[0]["periodicidade"]["periodicity"]==1 && !empty(gw()[0]["splits"])'
chk "venda: tipo assinatura, id_assinatura guardado, 30 dias" '($v='"$VENDA"') && $v["tipo_cobranca"]=="assinatura" && strpos($v["id_assinatura"],"SUBSIM")===0 && $v["dias_acesso"]==30 && $v["tempo_acesso_minutos"]==43200'
chk "documento do pagador: CNPJ fixo exigido pela gateway" 'strlen(gw()[0]["doc"])==14 && semerros()'
for P in "semanal:WEEKS:1:7" "trimestral:MONTHS:3:90" "semestral:MONTHS:6:180" "anual:YEARS:1:365"; do
  IFS=: read NOME TIPO QTD DIAS <<< "$P"
  reset; setflow v_recorrente "{\"operators\":{\"pix\":{\"properties\":{\"periodicidade\":\"$NOME\"}}}}"
  mark; msg "/start"
  chk "periodicidade $NOME -> $TIPO x$QTD e acesso de $DIAS dias" 'gw()[0]["periodicidade"]["periodicityType"]=="'$TIPO'" && gw()[0]["periodicidade"]["periodicity"]=='$QTD' && ('"$VENDA"')["dias_acesso"]=='$DIAS
done
reset; setflow v_recorrente
sql "UPDATE usuarios_gateways SET tipo_conta='pf' WHERE id_usuario=$UID_" >/dev/null
mark; msg "/start"
chk "conta PF não suporta Pix recorrente: mensagem amigável e nenhuma assinatura criada" 'tem("Não foi possível gerar seu Pix agora") && count(gw())==0 && q1("SELECT COUNT(*) FROM vendas WHERE bot_id=?",[ids()["bot"]])==0'
sql "UPDATE usuarios_gateways SET tipo_conta='pj' WHERE id_usuario=$UID_" >/dev/null

secao "V4.6 Assinatura: 1º pagamento e ciclos seguintes (webhook da gateway)"
reset; setflow v_recorrente; msg "/start" >/dev/null; TX=$(ultimotx)
SUB=$(php /tmp/pt/db.php "SELECT id_assinatura s FROM vendas WHERE bot_id=$BOTID" | php -r '$j=json_decode(trim(stream_get_contents(STDIN)),true); echo $j["s"];')
INI=$(date -u +%Y-%m-%dT%H:%M:%S.000Z)
pagar "$TX"
mark; owh "{\"event\":\"TRANSACTION_PAID\",\"transaction\":{\"id\":\"$TX\",\"status\":\"PAID\"},\"subscription\":{\"id\":\"$SUB\",\"cycle\":1,\"startAt\":\"$INI\",\"intervalType\":\"MONTHS\",\"intervalCount\":1}}"
chk "1º ciclo pago: venda paga, membro ativo (expiração >= 30 dias), ramo PAGO do fluxo" '('"$VENDA"')["status"]=="pago" && ($m='"$MEMBRO"') && strtotime($m["data_expiracao"])-time()>=30*86400-180 && tem("Assinatura ativa")'
EXP1=$(php /tmp/pt/db.php "SELECT data_expiracao e FROM membros_grupos WHERE bot_id=$BOTID" | php -r '$j=json_decode(trim(stream_get_contents(STDIN)),true); echo $j["e"];')
CICLO2="{\"event\":\"TRANSACTION_CREATED\",\"transaction\":{\"id\":\"SIMTXC2\",\"status\":\"PENDING\",\"amount\":49.9,\"pixInformation\":{\"qrCode\":\"00020126SIMCICLO2\",\"expiresAt\":\"$(date -u -d '+3 days' +%Y-%m-%dT%H:%M:%S.000Z)\"}},\"subscription\":{\"id\":\"$SUB\",\"cycle\":2,\"startAt\":\"$INI\",\"intervalType\":\"MONTHS\",\"intervalCount\":1}}"
mark; owh "$CICLO2"
chk "ciclo 2 criado pela gateway: cria venda filha 'gerado' e avisa o assinante com o código Pix (HTML)" '($v=q("SELECT * FROM vendas WHERE transacao_id=\"SIMTXC2\"")[0]) && $v["status"]=="gerado" && $v["venda_pai_id"]!==null && $v["id_operador_fluxo"]===null && ($e=ultimo("sendMessage")) && strpos(txt($e),"Renovação da sua assinatura")!==false && strpos(txt($e),"00020126SIMCICLO2")!==false && $e["p"]["parse_mode"]=="HTML" && count(tgerr())==0'
mark; owh "$CICLO2"
chk "mesmo evento entregue de novo: não duplica a venda nem o aviso" 'q1("SELECT COUNT(*) FROM vendas WHERE transacao_id=\"SIMTXC2\"")==1 && count(tg("sendMessage"))<=1'
pagar "SIMTXC2"
mark; owh "{\"event\":\"TRANSACTION_PAID\",\"transaction\":{\"id\":\"SIMTXC2\",\"status\":\"PAID\",\"amount\":49.9},\"subscription\":{\"id\":\"$SUB\",\"cycle\":2,\"startAt\":\"$INI\",\"intervalType\":\"MONTHS\",\"intervalCount\":1}}"
chk "ciclo 2 pago: 'Renovação confirmada!', acesso estendido e o funil de vendas NÃO roda de novo" 'tem("Renovação confirmada") && !tem("Assinatura ativa") && strtotime(('"$MEMBRO"')["data_expiracao"])>strtotime("'"$EXP1"'")+20*86400'
mark; owh "{\"event\":\"TRANSACTION_PAID\",\"transaction\":{\"id\":\"SIMTXC3\",\"status\":\"PAID\",\"amount\":49.9,\"pixInformation\":{\"qrCode\":\"x\"}},\"subscription\":{\"id\":\"$SUB\",\"cycle\":3,\"startAt\":\"$INI\",\"intervalType\":\"MONTHS\",\"intervalCount\":1}}"
chk "ciclo 3 FORJADO (payload diz PAID, gateway diz pendente): venda de ciclo é descartada, sem liberar acesso" 'q1("SELECT COUNT(*) FROM vendas WHERE transacao_id=\"SIMTXC3\"")==0 && count(tg("sendMessage"))==0'
mark; owh "{\"event\":\"TRANSACTION_PAID\",\"transaction\":{\"id\":\"SIMTXC9\",\"status\":\"PAID\"},\"subscription\":{\"id\":\"ASSINATURA_DESCONHECIDA\",\"cycle\":2}}"
chk "ciclo de uma assinatura que não conhecemos: ignorado sem erro" 'semerros() && count(tg("sendMessage"))==0'
echo; echo "FIM V4"
