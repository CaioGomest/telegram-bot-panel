#!/bin/bash
# GUIADO 7 (08/10/2026): operação em escala — lead "pagante" (leads.pagou) nos 3 caminhos de pagamento, aviso de venda sem grupo,
# token de bot recusado (401) e rotação/resumo dos logs.
source /tmp/pt/lib.sh
BOTID=$(php -r 'echo json_decode(file_get_contents("/tmp/pt/ids.json"),true)["bot"];')
LEAD='q("SELECT * FROM leads WHERE bot_id=? ORDER BY id DESC LIMIT 1",[ids()["bot"]])[0]'
VENDA='q("SELECT * FROM vendas WHERE bot_id=? ORDER BY id DESC LIMIT 1",[ids()["bot"]])[0]'
payload() { echo "{\"event\":\"TRANSACTION_PAID\",\"transaction\":{\"id\":\"$1\",\"status\":\"${2:-PAID}\"}}"; }
comprar() { msg "/start" Maria >/dev/null; mark; cb "basico::plano::${1:-p_mensal}" >/dev/null; TX=$(ultimotx); }
SEMGRUPO='{"planos!":[{"id":"p_mensal","nome":"Mensal","valor":19.9,"dias_acesso":30,"unidade_acesso":"dias","id_grupo":"","cor":""}]}'
AVISO_SG='q1("SELECT COUNT(*) FROM atividades WHERE id_usuario=? AND titulo=\"Venda sem grupo\"",[ids()["uid"]])'

secao "G7.1 Lead vira pagante (leads.pagou) nos 3 caminhos de confirmação"
reset; setflow guiado_base
msg "/start" Maria >/dev/null
chk "lead novo nasce com pagou=0" '('"$LEAD"')["pagou"]==0'
chk "filtro 'não pagou' (condicaoLeadPagou) enxerga o lead; 'pagou' não" 'q1("SELECT COUNT(*) FROM leads l WHERE l.bot_id=? AND l.pagou=0",[ids()["bot"]])==1 && q1("SELECT COUNT(*) FROM leads l WHERE l.bot_id=? AND l.pagou=1",[ids()["bot"]])==0'
mark; cb "basico::plano::p_mensal" >/dev/null; TX=$(ultimotx); pagar "$TX"
chk "Pix gerado e ainda não pago: lead continua pagou=0" '('"$LEAD"')["pagou"]==0'
mark; cb "verificar_pagamento_$TX"
chk "caminho 1 — botão 'Já fiz o pagamento': lead pagou=1" '('"$VENDA"')["status"]=="pago" && ('"$LEAD"')["pagou"]==1'
reset; setflow guiado_base; comprar p_mensal; pagar "$TX"
mark; owh "$(payload $TX PAID)"
chk "caminho 2 — webhook da gateway: lead pagou=1" '('"$VENDA"')["status"]=="pago" && ('"$LEAD"')["pagou"]==1'
reset; setflow guiado_base; comprar p_mensal; pagar "$TX"
mark; cron cron_verificar_pix
chk "caminho 3 — cron de verificação: lead pagou=1" '('"$VENDA"')["status"]=="pago" && ('"$LEAD"')["pagou"]==1'
mark; cb "verificar_pagamento_$TX"
chk "confirmar de novo (duplicata) não quebra nada: continua pagou=1 e 1 venda paga" '('"$LEAD"')["pagou"]==1 && q1("SELECT COUNT(*) FROM vendas WHERE bot_id=? AND status=\"pago\"",[ids()["bot"]])==1'

secao "G7.2 Venda de plano sem grupo: o dono é avisado (no máximo 1x por dia)"
reset; setflow guiado_base "$SEMGRUPO"
comprar p_mensal; pagar "$TX"; mark; cb "verificar_pagamento_$TX"
chk "pago sem grupo: nenhum convite criado e o cliente só recebe a mensagem de aprovado" 'count(tg("createChatInviteLink"))==0 && ('"$VENDA"')["status"]=="pago"'
chk "o DONO recebe no sino 'Venda sem grupo' citando a venda" "$AVISO_SG"'==1 && strpos(q1("SELECT descricao FROM atividades WHERE titulo=\"Venda sem grupo\" ORDER BY id DESC LIMIT 1"),"#".('"$VENDA"')["id"])!==false'
comprar p_mensal; pagar "$TX"; mark; cb "verificar_pagamento_$TX"
chk "2ª venda sem grupo no mesmo dia: NÃO repete o aviso (1 por dia)" "$AVISO_SG"'==1'
sql "UPDATE atividades SET criado_em = NOW() - INTERVAL 2 DAY WHERE titulo='Venda sem grupo'" >/dev/null
comprar p_mensal; pagar "$TX"; mark; cb "verificar_pagamento_$TX"
chk "no dia seguinte avisa de novo" "$AVISO_SG"'==2'
reset; setflow guiado_base
comprar p_mensal; pagar "$TX"; mark; cb "verificar_pagamento_$TX"
chk "plano COM grupo: sem aviso e convite criado normalmente" "$AVISO_SG"'==0 && count(tg("createChatInviteLink"))==1'

secao "G7.3 Token de bot recusado pelo Telegram (401)"
sql "UPDATE bots SET token_invalido_em = NULL WHERE id=$BOTID" >/dev/null
reset; setflow guiado_base
falha '{"sendMessage":"401:Unauthorized"}'
mark; msg "/start" Maria >/dev/null
chk "401 do Telegram: o bot é marcado com token_invalido_em" 'q1("SELECT token_invalido_em FROM bots WHERE id=?",[ids()["bot"]])!==null'
chk "o DONO é avisado no sino ('Bot desconectado' com o @ do bot)" 'q1("SELECT COUNT(*) FROM atividades WHERE id_usuario=? AND titulo=\"Bot desconectado\"",[ids()["uid"]])==1 && strpos(q1("SELECT descricao FROM atividades WHERE titulo=\"Bot desconectado\" ORDER BY id DESC LIMIT 1"),"@")!==false'
mark; msg "oi" Maria >/dev/null
chk "falhar de novo não repete o aviso (uma vez só)" 'q1("SELECT COUNT(*) FROM atividades WHERE id_usuario=? AND titulo=\"Bot desconectado\"",[ids()["uid"]])==1'
semfalha
sql "UPDATE bots SET token_invalido_em = NULL WHERE id=$BOTID" >/dev/null
falha '{"sendMessage":"Bad Request: chat not found"}'
mark; msg "/start" Maria >/dev/null
chk "erro comum do destinatário (400 chat not found) NÃO marca o bot como desconectado" 'q1("SELECT token_invalido_em FROM bots WHERE id=?",[ids()["bot"]])===null'
semfalha
sql "UPDATE atividades SET criado_em = NOW() - INTERVAL 2 DAY WHERE titulo='Bot desconectado'" >/dev/null
falha '{"sendMessage":"404:Not Found"}'
mark; msg "/start" Maria >/dev/null
chk "404 Not Found (token que não existe mais) também conta: bot marcado e avisado de novo" 'q1("SELECT token_invalido_em FROM bots WHERE id=?",[ids()["bot"]])!==null && q1("SELECT COUNT(*) FROM atividades WHERE id_usuario=? AND titulo=\"Bot desconectado\"",[ids()["uid"]])==2'
semfalha
php -r 'require "/tmp/pt/common.php"; require_once "/tmp/pt/app/funcoes/operacao.php"; limparTokenInvalido((int) ids()["bot"]);'
chk "token novo salvo (limparTokenInvalido): o aviso some" 'q1("SELECT token_invalido_em FROM bots WHERE id=?",[ids()["bot"]])===null'

secao "G7.4 Logs: resumo do payload e rotação"
RES=$(php -r 'require "/tmp/pt/app/funcoes/operacao.php"; echo resumoPayloadGateway(json_encode(["event"=>"TRANSACTION_PAID","client"=>["name"=>"Fulano Silva","email"=>"fulano@x.com","phone"=>"11999999999","cnpj"=>"65.915.116/0001-04"],"transaction"=>["id"=>"TX123","status"=>"COMPLETED","amount"=>2.1,"pixInformation"=>["qrCode"=>"00020126870014br.gov.bcb.pix2565pix.exemplo.com.br/qr/v3/at/abc"]],"subscription"=>["id"=>"SUB9","cycle"=>2]]));')
chk "resumo do payload traz evento, transação, status, valor e ciclo" '"'"$RES"'" !== "" && strpos("'"$RES"'","evento=TRANSACTION_PAID")!==false && strpos("'"$RES"'","transacao=TX123")!==false && strpos("'"$RES"'","status=COMPLETED")!==false && strpos("'"$RES"'","valor=2.1")!==false && strpos("'"$RES"'","ciclo=2")!==false'
chk "resumo NÃO tem nome, e-mail, telefone, documento nem código Pix" 'strpos("'"$RES"'","Fulano")===false && strpos("'"$RES"'","fulano@")===false && strpos("'"$RES"'","11999999999")===false && strpos("'"$RES"'","65.915")===false && strpos("'"$RES"'","br.gov.bcb")===false'
LOGS=/tmp/pt/app/logs; mkdir -p $LOGS; rm -f $LOGS/zz_teste*.log*
head -c 6000000 /dev/zero | tr '\0' 'x' > $LOGS/zz_teste_grande.log
head -c 1000 /dev/zero | tr '\0' 'y' > $LOGS/zz_teste_pequeno.log
cron cron_rotacionar_logs
chk "log acima de 5 MB é rotacionado (.log vira .log.1) e o pequeno fica quieto" 'is_file("'$LOGS'/zz_teste_grande.log.1") && !is_file("'$LOGS'/zz_teste_grande.log") && filesize("'$LOGS'/zz_teste_pequeno.log")==1000 && !is_file("'$LOGS'/zz_teste_pequeno.log.1")'
head -c 6000000 /dev/zero | tr '\0' 'z' > $LOGS/zz_teste_grande.log; cron cron_rotacionar_logs
head -c 6000000 /dev/zero | tr '\0' 'w' > $LOGS/zz_teste_grande.log; cron cron_rotacionar_logs
head -c 6000000 /dev/zero | tr '\0' 'v' > $LOGS/zz_teste_grande.log; cron cron_rotacionar_logs
chk "depois de várias rotações guarda no máximo 3 gerações (.1, .2, .3), sem .4" 'is_file("'$LOGS'/zz_teste_grande.log.3") && !is_file("'$LOGS'/zz_teste_grande.log.4") && substr(file_get_contents("'$LOGS'/zz_teste_grande.log.1",false,null,0,1),0,1)=="v"'
rm -f $LOGS/zz_teste*.log*
echo; echo "FIM G7"
