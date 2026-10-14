#!/bin/bash
# GUIADO 9 (09/10/2026): venda não gravada / gateway lenta (o MySQL fecha conexão ociosa em 20 s) e pagamento sem venda.
source /tmp/pt/lib.sh
VENDA='q("SELECT * FROM vendas WHERE bot_id=? ORDER BY id DESC LIMIT 1",[ids()["bot"]])[0]'
NVENDAS='q1("SELECT COUNT(*) FROM vendas WHERE bot_id=?",[ids()["bot"]])'
LONGO=$(printf '9%.0s' $(seq 1 80))
compra() { janela; reset; setflow guiado_base "$1"; msg "/start" Maria >/dev/null; mark; cb "basico::plano::p_mensal" >/dev/null; }

secao "G9.1 Gateway lenta (responde depois do wait_timeout de 20 s do MySQL)"
touch /tmp/pt/lento
compra '{}'
rm -f /tmp/pt/lento
chk "a venda é gravada mesmo com a conexão do banco caída e o Pix chega ao cliente" '('"$VENDA"')["status"]=="gerado" && count(gw())==1 && tem("Pedido Criado") && count(tg("sendMessage"))>=2'
TX=$(ultimotx); pagar "$TX"; cb "verificar_pagamento_$TX" >/dev/null
chk "pago pelo botão: venda 'pago' e confirmação enviada" '('"$VENDA"')["status"]=="pago" && tem("Pagamento Confirmado")'

secao "G9.2 Venda impossível de gravar: o cliente NÃO recebe o Pix e o dono é avisado"
sql "DELETE FROM atividades WHERE titulo='Falha ao gravar venda'" >/dev/null
janela; reset; setflow guiado_base '{}'; msg "/start" Maria >/dev/null
# força a falha de gravação: já existe uma venda com o código que a gateway simulada vai devolver (índice UNIQUE em transacao_id)
PROX=$(php -r 'echo (json_decode(file_get_contents("/tmp/pt/contadores.json"),true)["tx"] ?? 1000) + 1;')
BOTID=$(php -r 'echo json_decode(file_get_contents("/tmp/pt/ids.json"),true)["bot"];')
sql "INSERT INTO vendas (id_telegram, bot_id, valor, status, transacao_id) VALUES ('1', $BOTID, 1, 'gerado', 'SIMTX$PROX')" >/dev/null
mark; cb "basico::plano::p_mensal" >/dev/null
chk "venda duplicada recusada pelo banco: o cliente recebe só 'Não foi possível gerar seu Pix' (sem código nem botões de pagamento)" 'q1("SELECT COUNT(*) FROM vendas WHERE transacao_id=?",["SIMTX'"$PROX"'"])==1 && tem("Não foi possível gerar seu Pix agora") && !tem("Pedido Criado") && count(tg("sendPhoto"))==0 && !tem("00020126")'
chk "o dono vê 'Falha ao gravar venda' no sino com o código da transação" 'q1("SELECT COUNT(*) FROM atividades WHERE id_usuario=? AND titulo=\"Falha ao gravar venda\" AND descricao LIKE \"%SIMTX%\"",[ids()["uid"]])>=1'

secao "G9.3 Pagamento aprovado sem venda correspondente"
sql "DELETE FROM atividades WHERE titulo='Pagamento sem venda'" >/dev/null
mark; owh '{"event":"TRANSACTION_PAID","transaction":{"id":"ORFAO1","status":"COMPLETED"}}'
chk "webhook de pagamento sem venda: admins recebem 'Pagamento sem venda' com o código" 'q1("SELECT COUNT(*) FROM atividades a JOIN usuarios u ON u.id=a.id_usuario WHERE u.perfil=\"admin\" AND a.titulo=\"Pagamento sem venda\" AND a.descricao LIKE \"%ORFAO1%\"")>=1'
chk "e nada é marcado como pago nem enviado ao cliente" 'count(tg("sendMessage"))==0'
sql "DELETE FROM atividades WHERE titulo='Pagamento sem venda' AND descricao LIKE '%ORFAO1%'" >/dev/null
sql "DELETE FROM atividades WHERE titulo='Falha ao gravar venda'" >/dev/null
sql "DELETE FROM vendas WHERE transacao_id='SIMTX$PROX'" >/dev/null
