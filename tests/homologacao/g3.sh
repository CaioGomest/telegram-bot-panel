#!/bin/bash
# GUIADO 3: confirmação de pagamento (botão, webhook da gateway, cron), duplicidade, entrega no grupo, renovação, expiração.
source /tmp/pt/lib.sh
BOTID=$(php -r 'echo json_decode(file_get_contents("/tmp/pt/ids.json"),true)["bot"];')
UID_=$(php -r 'echo json_decode(file_get_contents("/tmp/pt/ids.json"),true)["uid"];')
VENDA='q("SELECT * FROM vendas WHERE bot_id=? ORDER BY id DESC LIMIT 1",[ids()["bot"]])[0]'
MEMBRO='q("SELECT * FROM membros_grupos WHERE bot_id=? ORDER BY id DESC LIMIT 1",[ids()["bot"]])[0]'
comprar() { mark; cb "basico::plano::${1:-p_mensal}"; TX=$(ultimotx); }
payload() { echo "{\"event\":\"TRANSACTION_PAID\",\"transaction\":{\"id\":\"$1\",\"status\":\"${2:-PAID}\"}}"; }

secao "G3.1 Confirmação pelo botão 'Já fiz o pagamento'"
reset; setflow guiado_base
comprar p_mensal
mark; cb "verificar_pagamento_$TX"
chk "ainda não pago: 'Pagamento ainda não identificado' e venda continua gerado" 'tem("ainda n") && '"$VENDA"'["status"]=="gerado" && q1("SELECT COUNT(*) FROM membros_grupos WHERE bot_id=?",[ids()["bot"]])==0'
pagar "$TX"
mark; cb "verificar_pagamento_$TX"
chk "pago: venda vira 'pago' com pago_em preenchido" '($v='"$VENDA"') && $v["status"]=="pago" && $v["pago_em"]!==null'
chk "convite do grupo criado (1 uso, ~15 min, nome 'Venda #id')" '($c=tg("createChatInviteLink")) && count($c)==1 && $c[0]["p"]["chat_id"]=="-1001000000001" && $c[0]["p"]["member_limit"]==1 && abs($c[0]["p"]["expire_date"]-time()-900)<90 && strpos($c[0]["p"]["name"],"Venda #")===0'
chk "membro do grupo: ativo, convite salvo, expira em ~30 dias" '($m='"$MEMBRO"') && $m["status"]=="ativo" && strpos($m["invite_link"],"https://t.me/+SIM")===0 && abs(strtotime($m["data_expiracao"])-time()-30*86400)<180'
chk "mensagem: Pagamento Confirmado + Acesso Liberado + link + 30 dias + data exata" '($e=ultimo("sendMessage")) && ($t=txt($e)) && strpos($t,"Pagamento Confirmado")!==false && strpos($t,"Acesso Liberado")!==false && strpos($t,"https://t.me/+SIM")!==false && strpos($t,"30 dias")!==false && strpos($t,"Data exata")!==false && $e["p"]["parse_mode"]=="Markdown" && count(tgerr())==0'
chk "evento de rastreio 'compra' e webhook payment_approved disparados" 'count(array_filter(ev("track"),fn($e)=>$e["track"]=="compra"))==1 && count(array_filter(ev("whk"),fn($e)=>$e["whk"]=="payment_approved"))==1'
chk "atividade 'Venda Aprovada' aparece no feed do dono (notificações)" 'q1("SELECT COUNT(*) FROM atividades WHERE id_usuario=? AND tipo=\"venda\"",[ids()["uid"]])>=1'
chk "sem aviso/erro de PHP" 'semerros()'
mark; cb "verificar_pagamento_$TX"
chk "clicar de novo: 'já foi confirmado' e nada é reprocessado" 'tem("j") && tem("confirmado") && count(tg("createChatInviteLink"))==0 && q1("SELECT COUNT(*) FROM membros_grupos WHERE bot_id=?",[ids()["bot"]])==1'
mark; cb "verificar_pagamento_TXINEXISTENTE"
chk "txid desconhecido: 'Pagamento não encontrado'" 'tem("não encontrado")'

secao "G3.2 Mensagem de confirmação personalizada + planos em horas/minutos"
reset; setflow guiado_base '{"pagamentos":{"msg_confirmado":"Aprovado! Bem-vindo (curso_foto*top)"}}'
comprar p_horas; pagar "$TX"
mark; cb "verificar_pagamento_$TX"
chk "msg_confirmado personalizada substitui o texto padrão (Markdown escapado, aceito)" 'tem("Aprovado! Bem-vindo") && !tem("Pagamento Confirmado") && count(tgerr())==0'
chk "plano de 2 horas: texto 'expira em 2 horas' e expiração ~+2h" 'tem("2 horas") && ($m='"$MEMBRO"') && abs(strtotime($m["data_expiracao"])-time()-7200)<180'
reset; setflow guiado_base
comprar p_min; pagar "$TX"
mark; cb "verificar_pagamento_$TX"
chk "plano de 30 minutos: 'expira em 30 minutos' e expiração ~+30min" 'tem("30 minutos") && ($m='"$MEMBRO"') && abs(strtotime($m["data_expiracao"])-time()-1800)<180'
chk "plano de 30 min usa o grupo 2" '('"$MEMBRO"')["id_grupo_telegram"]=="-1001000000002"'
reset; setflow guiado_base '{"planos!":[{"id":"sg","nome":"Sem grupo","valor":10,"dias_acesso":7,"unidade_acesso":"dias","id_grupo":""}]}'
comprar sg; pagar "$TX"
mark; cb "verificar_pagamento_$TX"
chk "plano SEM grupo: só confirma (sem convite, sem membro)" 'count(tg("createChatInviteLink"))==0 && q1("SELECT COUNT(*) FROM membros_grupos WHERE bot_id=?",[ids()["bot"]])==0 && tem("Pagamento Confirmado") && !tem("Acesso Liberado")'
chk "plano sem grupo: venda marcada paga" '('"$VENDA"')["status"]=="pago"'

secao "G3.3 Falha ao criar o convite (Telegram/permissão)"
reset; setflow guiado_base
comprar p_mensal; pagar "$TX"
falha '{"createChatInviteLink":"Bad Request: not enough rights to create a chat invite link"}'
mark; cb "verificar_pagamento_$TX"
chk "sem convite: venda paga, acesso/expiração gravados mesmo assim e cliente avisado" '($m='"$MEMBRO"') && $m["status"]=="ativo" && $m["invite_link"]===null && tem("Não foi possível gerar o link") && '"$VENDA"'["status"]=="pago"'
semfalha

secao "G3.4 Renovação antes de vencer: estende o prazo e revoga o link antigo"
reset; setflow guiado_base
comprar p_mensal; pagar "$TX"; cb "verificar_pagamento_$TX"
EXP1=$(php /tmp/pt/db.php "SELECT data_expiracao FROM membros_grupos WHERE bot_id=$BOTID" | php -r '$j=json_decode(trim(stream_get_contents(STDIN)),true); echo $j["data_expiracao"];')
LINK1=$(php /tmp/pt/db.php "SELECT invite_link FROM membros_grupos WHERE bot_id=$BOTID" | php -r '$j=json_decode(trim(stream_get_contents(STDIN)),true); echo $j["invite_link"];')
comprar p_mensal; pagar "$TX"
mark; cb "verificar_pagamento_$TX"
chk "2ª compra do mesmo plano: expiração = vencimento anterior + 30 dias (não perde os dias pagos)" '($m='"$MEMBRO"') && abs(strtotime($m["data_expiracao"])-(strtotime("'"$EXP1"'")+30*86400))<5 && q1("SELECT COUNT(*) FROM membros_grupos WHERE bot_id=?",[ids()["bot"]])==1'
chk "link antigo é revogado antes de criar o novo" '($r=tg("revokeChatInviteLink")) && count($r)==1 && $r[0]["p"]["invite_link"]=="'"$LINK1"'" && $r[0]["p"]["chat_id"]=="-1001000000001"'
chk "membro volta a 'ativo' com aviso_enviado zerado e venda_id da compra nova" '($m='"$MEMBRO"') && $m["aviso_enviado"]==0 && $m["venda_id"]=='"$VENDA"'["id"]'

secao "G3.5 Webhook da OmegaPayments"
reset; setflow guiado_base
comprar p_mensal
mark; owh ""
chk "ping com corpo vazio: 200 sem processar nada" 'count(ev("owh"))==2 && count(tg("sendMessage"))==0'
mark; owh "isso nao e json"
chk "corpo que não é JSON é ignorado sem erro" 'semerros() && count(tg("sendMessage"))==0'
mark; owh '{"event":"X","foo":"bar"}'
chk "payload sem identificador de transação é ignorado" 'semerros() && count(tg("sendMessage"))==0'
mark; owh "$(payload TXDESCONHECIDO PAID)"
chk "transação desconhecida é ignorada" 'semerros() && count(tg("sendMessage"))==0'
mark; owh "$(payload $TX PAID)"
chk "payload FORJADO (diz PAID mas a gateway diz pendente): NÃO libera nada" '(('"$VENDA"')["status"]=="gerado") && count(tg("sendMessage"))==0 && count(tg("createChatInviteLink"))==0'
mark; owh "$(payload $TX EXPIRED)"
chk "evento que não é pagamento (EXPIRED) é ignorado" '('"$VENDA"')["status"]=="gerado" && count(tg("sendMessage"))==0'
pagar "$TX"
mark; owh "$(payload $TX PAID)"
chk "pagamento real confirmado na API: venda paga + membro ativo" '('"$VENDA"')["status"]=="pago" && ('"$MEMBRO"')["status"]=="ativo"'
chk "mensagem em HTML: Pagamento Confirmado + link + dias" '($e=ultimo("sendMessage")) && ($t=txt($e)) && strpos($t,"Pagamento Confirmado")!==false && strpos($t,"https://t.me/+SIM")!==false && strpos($t,"30 dias")!==false && $e["p"]["parse_mode"]=="HTML" && count(tgerr())==0'
chk "atividade 'Venda Aprovada' + evento compra + webhook payment_approved" 'q1("SELECT COUNT(*) FROM atividades WHERE id_usuario=? AND tipo=\"venda\"",[ids()["uid"]])==1 && count(array_filter(ev("track"),fn($e)=>$e["track"]=="compra"))==1 && count(array_filter(ev("whk"),fn($e)=>$e["whk"]=="payment_approved"))==1'
mark; owh "$(payload $TX PAID)"
chk "notificação duplicada: ignorada (sem 2ª mensagem nem 2º convite)" 'count(tg("sendMessage"))==0 && count(tg("createChatInviteLink"))==0'

secao "G3.6 Cron de verificação do Pix (rede de segurança)"
reset; setflow guiado_base
comprar p_mensal; pagar "$TX"
mark; cron cron_verificar_pix
chk "cron acha o pagamento: venda paga, membro ativo, mensagem com link" '('"$VENDA"')["status"]=="pago" && ('"$MEMBRO"')["status"]=="ativo" && tem("Acesso Liberado") && count(tgerr())==0'
chk "cron: evento de rastreio 'compra' é enviado" 'count(array_filter(ev("track"),fn($e)=>$e["track"]=="compra"))==1'
chk "cron: atividade 'Venda Aprovada' registrada" 'q1("SELECT COUNT(*) FROM atividades WHERE id_usuario=? AND tipo=\"venda\"",[ids()["uid"]])>=1'
mark; cron cron_verificar_pix
chk "2ª execução do cron não repete nada" 'count(tg("sendMessage"))==0'

secao "G3.7 Os três caminhos ao mesmo tempo (corrida): só um processa"
reset; setflow guiado_base
comprar p_mensal; pagar "$TX"
mark; owh "$(payload $TX PAID)"; cron cron_verificar_pix; cb "verificar_pagamento_$TX"
chk "webhook + cron + botão com o mesmo pagamento: 1 confirmação, 1 convite, 1 membro" 'count(array_filter(tg("sendMessage"),fn($e)=>strpos(txt($e),"Pagamento Confirmado")!==false))==1 && count(tg("createChatInviteLink"))==1 && q1("SELECT COUNT(*) FROM membros_grupos WHERE bot_id=?",[ids()["bot"]])==1'
chk "e o botão responde 'já confirmado'" 'tem("confirmado")'

secao "G3.8 Pix expira sem pagamento (guiado não tem caminho 'não pago')"
reset; setflow guiado_base
comprar p_mensal
sql "UPDATE vendas SET criado_em = NOW() - INTERVAL 20 MINUTE WHERE bot_id=$BOTID" >/dev/null
mark; cron cron_verificar_pix
chk "venda vencida (20 min > 15) vira 'expirado' em silêncio (sem mensagem ao cliente)" '('"$VENDA"')["status"]=="expirado" && count(tg("sendMessage"))==0'
mark; cb "verificar_pagamento_$TX"
chk "clicar em 'Já fiz' num Pix expirado e não pago: 'ainda não identificado' (sem erro)" 'semerros() && (tem("ainda n") || tem("não encontrado"))'
comprar p_mensal; pagar "$TX"
sql "UPDATE vendas SET criado_em = NOW() - INTERVAL 20 MINUTE WHERE bot_id=$BOTID AND transacao_id='$TX'" >/dev/null
mark; cron cron_verificar_pix
chk "pago na gateway mas já passou da validade: o cron confirma o pagamento antes de expirar" '('"$VENDA"')["status"]=="pago"'

secao "G3.9 Falha ao consultar a gateway"
reset; setflow guiado_base
comprar p_mensal; pagar "$TX"
falha '{"gw:*":"timeout simulado"}'
mark; cb "verificar_pagamento_$TX"
chk "gateway fora do ar no clique: 'ainda não identificado', venda segue gerada" '('"$VENDA"')["status"]=="gerado" && semerros()'
mark; cron cron_verificar_pix
chk "gateway fora do ar no cron: nada quebra e nada é marcado" '('"$VENDA"')["status"]=="gerado" && semerros()'
semfalha
mark; cron cron_verificar_pix
chk "gateway volta: o cron confirma o pagamento pendente" '('"$VENDA"')["status"]=="pago"'
secao "G3.10 Cliques simultâneos (dois processos ao mesmo tempo)"
reset; setflow guiado_base
comprar p_mensal; pagar "$TX"
mark; ( cb "verificar_pagamento_$TX" ) & ( cb "verificar_pagamento_$TX" ) & ( owh "$(payload $TX PAID)" ) & ( cron cron_verificar_pix ) & wait
chk "botão x2 + webhook + cron EM PARALELO: uma única confirmação, um único convite, um único membro" 'count(array_filter(tg("sendMessage"),fn($e)=>strpos(txt($e),"Pagamento Confirmado")!==false))==1 && count(tg("createChatInviteLink"))==1 && q1("SELECT COUNT(*) FROM membros_grupos WHERE bot_id=?",[ids()["bot"]])==1 && q1("SELECT COUNT(*) FROM atividades WHERE id_usuario=? AND tipo=\"venda\"",[ids()["uid"]])==1'
echo; echo "FIM G3"
