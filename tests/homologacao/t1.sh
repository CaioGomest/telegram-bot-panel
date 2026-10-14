#!/bin/bash
# TRAQUEAMENTO 1: envio de eventos ao Facebook (Conversions API) e à UTMify (HTTP simulado e validado pelo sim_eventos.php).
source /tmp/pt/lib.sh
BOTID=$(php -r 'echo json_decode(file_get_contents("/tmp/pt/ids.json"),true)["bot"];')
VENDA='q("SELECT * FROM vendas WHERE bot_id=? ORDER BY id DESC LIMIT 1",[ids()["bot"]])[0]'
payload() { echo "{\"event\":\"TRANSACTION_PAID\",\"transaction\":{\"id\":\"$1\",\"status\":\"${2:-PAID}\"}}"; }
FB='array_values(array_filter(ev("evt"),fn($e)=>$e["evt"]=="facebook"))'
UT='array_values(array_filter(ev("evt"),fn($e)=>$e["evt"]=="utmfy"))'
LOG=/tmp/pt/app/logs/traqueamento.log
TOKFB='EAAB-TOKEN-SECRETO-FB-123'
TOKUT='utm-token-secreto-456'
cfg() { php /tmp/pt/trkcfg.php "$1" >/dev/null; }
CFG_AMBOS="{\"fb\":1,\"pixel\":\"1234567890\",\"fbtoken\":\"$TOKFB\",\"ut\":1,\"uttoken\":\"$TOKUT\"}"
# cliente "Ana" vindo de campanha, com telefone, vai até o Pix. Deixa o TX em $TX.
comprar() { reset; setflow guiado_base; cfg "${1:-$CFG_AMBOS}"; mkdir -p /tmp/pt/app/logs; : > $LOG
  sql "INSERT INTO links_rastreamento (id_usuario, titulo, identificador, bot_id) SELECT id_usuario, 'camp t', 'camp_t', id FROM bots WHERE id=$BOTID AND NOT EXISTS (SELECT 1 FROM links_rastreamento WHERE identificador='camp_t' AND bot_id=$BOTID)" >/dev/null 2>&1
  msg "/start camp_t" "Ana" >/dev/null; sql "UPDATE leads SET telefone='11999998888' WHERE bot_id=$BOTID" >/dev/null
  mark; cb "basico::plano::p_mensal"; TX=$(ultimotx); }

secao "T1.1 Pix gerado: InitiateCheckout (Facebook) + waiting_payment (UTMify)"
comprar
chk "exatamente 2 envios: 1 Facebook e 1 UTMify, os dois aceitos pela API simulada" 'count(ev("evt"))==2 && count('"$FB"')==1 && count('"$UT"')==1 && '"$FB"'[0]["ok"] && '"$UT"'[0]["ok"]'
chk "Facebook: evento InitiateCheckout, moeda BRL e valor 19.90" '($e=('"$FB"')[0]["payload"]["data"][0]) && $e["event_name"]=="InitiateCheckout" && $e["custom_data"]["currency"]=="BRL" && abs($e["custom_data"]["value"]-19.9)<0.001 && $e["action_source"]=="system_generated"'
chk "Facebook: order_id e event_id iguais ao txid da venda" '($e=('"$FB"')[0]["payload"]["data"][0]) && $e["custom_data"]["order_id"]==('"$VENDA"')["transacao_id"] && $e["event_id"]==$e["custom_data"]["order_id"]'
chk "Facebook: user_data com external_id, telefone (55+DDD, hash) e primeiro nome, tudo SHA-256; sem e-mail inventado" '($u=('"$FB"')[0]["payload"]["data"][0]["user_data"]) && $u["external_id"]==hash("sha256","777000111") && $u["ph"]==hash("sha256","5511999998888") && $u["fn"]==hash("sha256","ana") && !isset($u["em"])'
chk "Facebook: access_token vai no corpo (não na URL) e a URL usa o pixel configurado" '($e=('"$FB"')[0]) && $e["token"]=="'"$TOKFB"'" && strpos($e["url"],"/1234567890/events")!==false && strpos($e["url"],"access_token")===false'
chk "UTMify: status waiting_payment, approvedDate nulo, orderId = txid, preço em centavos (1990)" '($p=('"$UT"')[0]["payload"]) && $p["status"]=="waiting_payment" && $p["approvedDate"]===null && $p["orderId"]==('"$VENDA"')["transacao_id"] && $p["products"][0]["priceInCents"]===1990'
chk "UTMify: cliente com nome e telefone só dígitos; UTMs da campanha (source telegram, campaign camp_t)" '($p=('"$UT"')[0]["payload"]) && $p["customer"]["name"]=="Ana Teste" && $p["customer"]["phone"]=="11999998888" && $p["trackingParameters"]["utm_campaign"]=="camp_t" && $p["trackingParameters"]["utm_source"]=="telegram"'
chk "UTMify: token vai no header x-api-token e a URL é a oficial de pedidos" '($e=('"$UT"')[0]) && in_array("x-api-token: '"$TOKUT"'",$e["headers"],true) && $e["url"]=="https://api.utmify.com.br/api-credentials/orders"'
chk "log: grava os dois envios e NÃO grava o access_token do Facebook em claro" 'is_file("'$LOG'") && ($l=file_get_contents("'$LOG'")) && strpos($l,"PLATAFORMA: Facebook")!==false && strpos($l,"PLATAFORMA: UTMfy")!==false && strpos($l,"'"$TOKFB"'")===false && strpos($l,"***")!==false'
chk "venda continua 'gerado', o Pix foi entregue ao cliente, sem aviso de PHP" '('"$VENDA"')["status"]=="gerado" && count(tg("sendMessage"))>=1 && count(tgerr())==0 && semerros()'

secao "T1.2 Pagamento pelo webhook da gateway: Purchase + paid, sem duplicar"
pagar "$TX"; mark; owh "$(payload $TX PAID)"
chk "2 envios: Facebook Purchase e UTMify paid, aceitos" 'count(ev("evt"))==2 && count('"$FB"')==1 && count('"$UT"')==1 && '"$FB"'[0]["ok"] && '"$UT"'[0]["ok"] && '"$FB"'[0]["payload"]["data"][0]["event_name"]=="Purchase"'
chk "Purchase com valor 19.90, mesmo order_id da venda" '($e=('"$FB"')[0]["payload"]["data"][0]) && abs($e["custom_data"]["value"]-19.9)<0.001 && $e["custom_data"]["order_id"]==('"$VENDA"')["transacao_id"]'
chk "UTMify paid: mesmo orderId do Pix gerado (atualiza o pedido), approvedDate preenchido, comissão fecha com o total" '($p=('"$UT"')[0]["payload"]) && $p["status"]=="paid" && $p["orderId"]==('"$VENDA"')["transacao_id"] && $p["approvedDate"]!==null && $p["commission"]["totalPriceInCents"]===1990 && $p["commission"]["userCommissionInCents"]+$p["commission"]["gatewayFeeInCents"]===1990'
chk "UTMify paid: createdAt é o momento em que o Pix foi gerado (UTC), não 'agora'" '($p=('"$UT"')[0]["payload"]) && $p["createdAt"]==gmdate("Y-m-d H:i:s",strtotime(('"$VENDA"')["criado_em"]))'
chk "Purchase e paid levam o nome do plano (Mensal), o mesmo do Pix gerado — não 'Produto'" '('"$FB"')[0]["payload"]["data"][0]["custom_data"]["content_name"]=="Mensal" && ('"$UT"')[0]["payload"]["products"][0]["name"]=="Mensal" && ('"$UT"')[0]["payload"]["products"][0]["planName"]=="Mensal"'
chk "cliente recebeu a confirmação e a venda ficou paga" '('"$VENDA"')["status"]=="pago" && tem("Pagamento Confirmado") && semerros()'
mark; owh "$(payload $TX PAID)"
chk "webhook repetido pela gateway: nenhum evento duplicado" 'count(ev("evt"))==0'

secao "T1.3 Pagamento pelo botão 'Já fiz o pagamento'"
comprar; pagar "$TX"; mark; cb "verificar_pagamento_$TX"
chk "botão: 1 Purchase + 1 paid; clicar de novo não reenvia" 'count('"$FB"')==1 && count('"$UT"')==1 && '"$FB"'[0]["payload"]["data"][0]["event_name"]=="Purchase" && '"$UT"'[0]["payload"]["status"]=="paid"'
mark; cb "verificar_pagamento_$TX"
chk "segundo clique: nada reenviado" 'count(ev("evt"))==0'

secao "T1.4 Pagamento achado pela cron de verificação de Pix"
comprar; pagar "$TX"; mark; cron cron_verificar_pix
chk "cron: 1 Purchase + 1 paid com os dados do lead" 'count('"$FB"')==1 && count('"$UT"')==1 && '"$UT"'[0]["payload"]["customer"]["name"]=="Ana Teste" && '"$UT"'[0]["payload"]["status"]=="paid" && semerros()'
mark; cron cron_verificar_pix
chk "cron rodando de novo: nada reenviado" 'count(ev("evt"))==0'
mark; owh "$(payload $TX PAID)"
chk "webhook depois da cron: nada reenviado" 'count(ev("evt"))==0'

secao "T1.5 Quem recebe: plataforma desligada, sem credencial ou só uma ligada"
comprar "{\"fb\":1,\"pixel\":\"1234567890\",\"fbtoken\":\"$TOKFB\",\"ut\":0,\"uttoken\":\"$TOKUT\"}"
chk "UTMify desligada (token salvo): só o Facebook recebe" 'count(ev("evt"))==1 && count('"$FB"')==1'
comprar "{\"fb\":0,\"pixel\":\"1234567890\",\"fbtoken\":\"$TOKFB\",\"ut\":1,\"uttoken\":\"$TOKUT\"}"
chk "Facebook desligado (credenciais salvas): só a UTMify recebe" 'count(ev("evt"))==1 && count('"$UT"')==1'
comprar "{\"fb\":1,\"pixel\":\"1234567890\",\"fbtoken\":\"\",\"ut\":1,\"uttoken\":\"\"}"
chk "ligadas mas sem token: nada é enviado" 'count(ev("evt"))==0 && count(tgerr())==0 && semerros()'
comprar "{\"fb\":1,\"pixel\":\"\",\"fbtoken\":\"$TOKFB\",\"ut\":0}"
chk "Facebook sem Pixel ID: nada é enviado" 'count(ev("evt"))==0'
comprar '{"fb":0,"ut":0}'
chk "tudo desligado: nada é enviado e o Pix sai normalmente" 'count(ev("evt"))==0 && count(tg("sendMessage"))>=1'
comprar limpar
chk "usuário sem nenhuma configuração de traqueamento: nada é enviado, sem erro" 'count(ev("evt"))==0 && semerros()'

secao "T1.6 Falhas das APIs não quebram a venda"
comprar; falha '{"evento:facebook":"500"}'; pagar "$TX"; mark; owh "$(payload $TX PAID)"
chk "Facebook fora do ar (HTTP 500): UTMify ainda recebe, venda paga e cliente recebe o acesso" 'count('"$UT"')==1 && '"$UT"'[0]["ok"] && ('"$VENDA"')["status"]=="pago" && tem("Pagamento Confirmado") && semerros()'
chk "log registra a falha do Facebook com o código HTTP" 'strpos(file_get_contents("'$LOG'"),"HTTP_CODE: 500")!==false'
semfalha
comprar; falha '{"evento:*":"400"}'; pagar "$TX"; mark; cb "verificar_pagamento_$TX"
chk "as duas APIs recusando (400): confirmação do pagamento continua funcionando" '('"$VENDA"')["status"]=="pago" && tem("Pagamento Confirmado") && count(tgerr())==0 && semerros()'
semfalha
comprar; falha '{"evento:*":"500"}'
chk "falha já no Pix gerado: o cliente ainda recebe o Pix" '('"$VENDA"')["status"]=="gerado" && count(tg("sendMessage"))>=1 && semerros()'
semfalha

secao "T1.7 UTMify por URL de postback"
comprar '{"fb":0,"ut":1,"uttoken":"https://example.com/postback/abc"}'
chk "URL de postback: payload genérico com event, valor e transaction_id" '($e='"$UT"') && count($e)==1 && $e[0]["url"]=="https://example.com/postback/abc" && $e[0]["payload"]["event"]=="pix_gerado" && abs($e[0]["payload"]["value"]-19.9)<0.001 && $e[0]["payload"]["transaction_id"]==('"$VENDA"')["transacao_id"] && $e[0]["payload"]["utm_campaign"]=="camp_t"'
comprar '{"fb":0,"ut":1,"uttoken":"http://127.0.0.1/interno"}'
chk "URL de postback apontando para host interno é recusada antes do envio (SSRF), sem aviso de PHP" 'count(ev("evt"))==0 && semerros() && strpos(file_get_contents("'$LOG'"),"recusada")!==false'

secao "T1.8 Unidades: mapeamento, hash e robustez"
chk "Facebook: telefone com DDI mantido, sem DDI recebe 55; nome normalizado (minúsculas, 1º nome)" '(function(){ require_once "/tmp/pt/app/funcoes/facebook.php"; $a=enviarEventoFacebook("1","t","compra",["valor"=>5,"transacao_id"=>"X1"],["telefone"=>"+55 (11) 99999-8888","first_name"=>"JOÃO Silva","id_telegram"=>"9"]); $u=$a["payload"]["data"][0]["user_data"]; return $u["ph"]==hash("sha256","5511999998888") && $u["fn"]==hash("sha256","joão"); })()'
chk "Facebook: evento sem event_id/order_id não manda null (campos omitidos)" '(function(){ require_once "/tmp/pt/app/funcoes/facebook.php"; $a=enviarEventoFacebook("1","t","pix_gerado",["valor"=>5],["id_telegram"=>"9"]); $e=$a["payload"]["data"][0]; return !array_key_exists("event_id",$e) && !array_key_exists("order_id",$e["custom_data"]) && $e["event_name"]=="InitiateCheckout"; })()'
chk "UTMify: evento desconhecido NÃO vira venda paga (recusado)" '(function(){ require_once "/tmp/pt/app/funcoes/utmfy.php"; $r=enviarEventoUtmfy("tok","evento_novo",["valor"=>5],[]); return $r["sucesso"]===false; })()'
chk "UTMify sem nome/e-mail/telefone: usa valores seguros (Cliente Telegram, e-mail .invalid, telefone nulo)" '(function(){ require_once "/tmp/pt/app/funcoes/utmfy.php"; $r=enviarEventoUtmfy("tok","compra",["valor"=>5,"transacao_id"=>"U1"],["id_telegram"=>"9"]); $c=$r["payload"]["customer"]; return $c["name"]=="Cliente Telegram" && substr($c["email"],-8)==".invalid" && $c["phone"]===null; })()'
chk "log: segredo mascarado em qualquer nível do payload" '(function(){ require_once "/tmp/pt/app/funcoes/traqueamento.php"; $m=mascararSegredosTraqueamento(["access_token"=>"SEGREDO","data"=>[["x"=>1,"utmfy_token"=>"S2"]],"ok"=>"visivel"]); return $m["access_token"]=="***" && $m["data"][0]["utmfy_token"]=="***" && $m["ok"]=="visivel"; })()'
chk "exceção dentro do envio vira falha registrada e não propaga" '(function(){ require_once "/tmp/pt/app/funcoes/traqueamento.php"; $r=executarEnvioTraqueamento("Teste","compra",function(){ throw new RuntimeException("boom"); }); return $r["sucesso"]===false && strpos($r["erro"],"boom")!==false && $r["codigo"]===0; })()'
cfg limpar

secao "T1.9 Editor visual: Pix único, assinatura e renovação de ciclo"
reset; setflow v_pix; cfg "$CFG_AMBOS"; : > $LOG
mark; msg "/start" "Ana" >/dev/null; TX=$(ultimotx)
chk "visual (Pix único): InitiateCheckout + waiting_payment, mesmo valor da venda e nome do produto gravado" 'count('"$FB"')==1 && count('"$UT"')==1 && '"$FB"'[0]["payload"]["data"][0]["event_name"]=="InitiateCheckout" && '"$UT"'[0]["payload"]["status"]=="waiting_payment" && abs('"$FB"'[0]["payload"]["data"][0]["custom_data"]["value"]-(float)('"$VENDA"')["valor"])<0.001 && ($n=('"$VENDA"')["nome_produto"]) && $n!=="" && '"$FB"'[0]["payload"]["data"][0]["custom_data"]["content_name"]==$n && semerros()'
pagar "$TX"; mark; owh "$(payload $TX PAID)"
chk "visual (Pix único) pago: só Purchase + paid (a cadeia de mensagens/grupos do pós-pagamento não gera evento extra), com o mesmo nome" 'count(ev("evt"))==2 && '"$FB"'[0]["payload"]["data"][0]["event_name"]=="Purchase" && '"$UT"'[0]["payload"]["status"]=="paid" && '"$UT"'[0]["payload"]["products"][0]["name"]==('"$VENDA"')["nome_produto"] && semerros()'
reset; setflow v_recorrente; cfg "$CFG_AMBOS"; : > $LOG
mark; msg "/start" "Ana" >/dev/null; TX=$(ultimotx)
SUB=$(php /tmp/pt/db.php "SELECT id_assinatura s FROM vendas WHERE bot_id=$BOTID" | php -r '$j=json_decode(trim(stream_get_contents(STDIN)),true); echo $j["s"];')
INI=$(date -u +%Y-%m-%dT%H:%M:%S.000Z)
chk "assinatura: Pix gerado dispara InitiateCheckout + waiting_payment" 'count('"$FB"')==1 && count('"$UT"')==1 && '"$UT"'[0]["payload"]["status"]=="waiting_payment" && semerros()'
pagar "$TX"; mark; owh "{\"event\":\"TRANSACTION_PAID\",\"transaction\":{\"id\":\"$TX\",\"status\":\"PAID\"},\"subscription\":{\"id\":\"$SUB\",\"cycle\":1,\"startAt\":\"$INI\",\"intervalType\":\"MONTHS\",\"intervalCount\":1}}"
chk "assinatura, 1º ciclo pago: Purchase + paid" 'count('"$FB"')==1 && count('"$UT"')==1 && '"$FB"'[0]["payload"]["data"][0]["event_name"]=="Purchase" && '"$UT"'[0]["payload"]["status"]=="paid"'
CICLO2="{\"event\":\"TRANSACTION_CREATED\",\"transaction\":{\"id\":\"SIMTXC2\",\"status\":\"PENDING\",\"amount\":49.9,\"pixInformation\":{\"qrCode\":\"00020126SIMCICLO2\",\"expiresAt\":\"$(date -u -d '+3 days' +%Y-%m-%dT%H:%M:%S.000Z)\"}},\"subscription\":{\"id\":\"$SUB\",\"cycle\":2,\"startAt\":\"$INI\",\"intervalType\":\"MONTHS\",\"intervalCount\":1}}"
mark; owh "$CICLO2"
chk "renovação: criar a cobrança do ciclo 2 não gera evento de venda" 'count(ev("evt"))==0'
pagar "SIMTXC2"; mark; owh "{\"event\":\"TRANSACTION_PAID\",\"transaction\":{\"id\":\"SIMTXC2\",\"status\":\"PAID\",\"amount\":49.9},\"subscription\":{\"id\":\"$SUB\",\"cycle\":2,\"startAt\":\"$INI\",\"intervalType\":\"MONTHS\",\"intervalCount\":1}}"
chk "renovação paga (ciclo 2): Purchase + paid com valor 49.90, orderId do ciclo e o nome do produto herdado da assinatura" 'count('"$FB"')==1 && count('"$UT"')==1 && '"$UT"'[0]["payload"]["orderId"]=="SIMTXC2" && '"$UT"'[0]["payload"]["products"][0]["priceInCents"]===4990 && ($n=q1("SELECT nome_produto FROM vendas WHERE transacao_id=\"'"$TX"'\"")) && $n!=="" && '"$UT"'[0]["payload"]["products"][0]["name"]==$n && semerros()'
cfg limpar
