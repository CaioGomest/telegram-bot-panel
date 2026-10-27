#!/bin/bash
# GUIADO 11 (22/10/2026): entrega por LINK, grupo ou nada em plano, pack, Upsell e Downsell (funcoes/entrega.php).
# Confere a venda gravada (id_grupo_telegram / link_entrega), a mensagem de pagamento confirmado nos 3 caminhos (botão, webhook da Omega, cron),
# o salvar da API (link inválido = 422) e o banco ainda sem a coluna link_entrega (migração não rodou).
source /tmp/pt/lib.sh
BOTID=$(php -r 'echo json_decode(file_get_contents("/tmp/pt/ids.json"),true)["bot"];')
UID_=$(php -r 'echo json_decode(file_get_contents("/tmp/pt/ids.json"),true)["uid"];')
VENDA='q("SELECT * FROM vendas WHERE bot_id=? ORDER BY id DESC LIMIT 1",[ids()["bot"]])[0]'
MEMBROS='q1("SELECT COUNT(*) FROM membros_grupos WHERE bot_id=?",[ids()["bot"]])'
SEMGRUPO='q1("SELECT COUNT(*) FROM atividades WHERE id_usuario=? AND titulo=\"Venda sem grupo\"",[ids()["uid"]])'
# endereço com "_", "*", "&" e "?" de propósito: sem escape o Markdown do Telegram recusaria a mensagem inteira
LINK='https://area.exemplo.com.br/aulas_vip*2026/entrar?x=1&y=2'
LINK2='https://bonus.exemplo.com.br/oferta_extra*/ok'
G1_='-1001000000001'
G2_='-1001000000002'
comprar() { mark; cb "basico::plano::${1:-p_mensal}"; TX=$(ultimotx); }
payload() { echo "{\"event\":\"TRANSACTION_PAID\",\"transaction\":{\"id\":\"$1\",\"status\":\"PAID\"}}"; }
# texto sem as barras do escape de Markdown, para achar o endereço original
TXT_LIMPO='str_replace("\\","",txt(ultimo("sendMessage")))'
PLANOS='{"planos!":[
 {"id":"p_link","nome":"VIP Link","valor":19.9,"dias_acesso":30,"unidade_acesso":"dias","entrega_tipo":"link","link_entrega":"'"$LINK"'","id_grupo":""},
 {"id":"p_grupo","nome":"Com grupo","valor":19.9,"dias_acesso":30,"unidade_acesso":"dias","entrega_tipo":"grupo","id_grupo":"'"$G1_"'"},
 {"id":"p_nada","nome":"Sem entrega","valor":19.9,"dias_acesso":30,"unidade_acesso":"dias","entrega_tipo":"nenhuma","id_grupo":"'"$G1_"'"},
 {"id":"p_antigo","nome":"Plano antigo","valor":19.9,"dias_acesso":30,"unidade_acesso":"dias","id_grupo":"'"$G2_"'"}
]}'

secao "G11.1 Plano com entrega por LINK: venda guarda o link; os 3 caminhos de confirmação entregam o link"
reset; setflow guiado_base "$PLANOS"
comprar p_link
chk "Pix gerado e venda gravada com o link, SEM grupo" 'count(gw())==1 && ($v='"$VENDA"') && $v["status"]=="gerado" && $v["link_entrega"]=="'"$LINK"'" && $v["id_grupo_telegram"]===null && count(tgerr())==0'
pagar "$TX"
mark; cb "verificar_pagamento_$TX"
chk "botão: venda paga e mensagem com Pagamento Confirmado + o link (Markdown escapado, aceito pelo Telegram)" '('"$VENDA"')["status"]=="pago" && ($e=ultimo("sendMessage")) && strpos(txt($e),"Pagamento Confirmado")!==false && strpos('"$TXT_LIMPO"',"'"$LINK"'")!==false && $e["p"]["parse_mode"]=="Markdown" && count(tgerr())==0'
chk "botão: nenhum convite de grupo, nenhum membro, sem 'Acesso Liberado'" 'count(tg("createChatInviteLink"))==0 && '"$MEMBROS"'==0 && !tem("Acesso Liberado")'
chk "venda com link NÃO gera o aviso 'Venda sem grupo' ao dono" "$SEMGRUPO"'==0'
chk "sem erro de PHP" 'semerros()'
reset; setflow guiado_base "$PLANOS"
comprar p_link; pagar "$TX"
mark; owh "$(payload $TX)"
chk "webhook da Omega (HTML): mensagem com o link e sem convite" '($e=ultimo("sendMessage")) && strpos(txt($e),"Pagamento Confirmado")!==false && strpos(txt($e),"'"$(echo "$LINK" | sed 's/&/\&amp;/g')"'")!==false && $e["p"]["parse_mode"]=="HTML" && count(tg("createChatInviteLink"))==0 && count(tgerr())==0'
reset; setflow guiado_base "$PLANOS"
comprar p_link; pagar "$TX"
mark; cron cron_verificar_pix
chk "cron: a mensagem de pagamento confirmado com o link SAI (venda sem grupo também recebe o aviso)" '('"$VENDA"')["status"]=="pago" && strpos('"$TXT_LIMPO"',"'"$LINK"'")!==false && count(tg("createChatInviteLink"))==0 && count(tgerr())==0'
mark; cron cron_verificar_pix
chk "cron: 2ª execução não repete a mensagem" 'count(tg("sendMessage"))==0'

secao "G11.2 Grupo, plano antigo (sem entrega_tipo) e 'nenhuma' continuam como eram"
reset; setflow guiado_base "$PLANOS"
comprar p_grupo; pagar "$TX"; mark; cb "verificar_pagamento_$TX"
chk "tipo 'grupo': convite do grupo 1, nenhum link gravado" '($v='"$VENDA"') && $v["id_grupo_telegram"]=="'"$G1_"'" && $v["link_entrega"]===null && tem("Acesso Liberado") && '"$MEMBROS"'==1'
reset; setflow guiado_base "$PLANOS"
comprar p_antigo; pagar "$TX"; mark; cb "verificar_pagamento_$TX"
chk "plano antigo (só id_grupo): convite do grupo 2, como antes" '($v='"$VENDA"') && $v["id_grupo_telegram"]=="'"$G2_"'" && $v["link_entrega"]===null && tem("Acesso Liberado")'
reset; setflow guiado_base "$PLANOS"
comprar p_nada
chk "tipo 'nenhuma': o grupo que sobrou guardado NÃO vai para a venda" '($v='"$VENDA"') && $v["id_grupo_telegram"]===null && $v["link_entrega"]===null'
pagar "$TX"; mark; cb "verificar_pagamento_$TX"
chk "só confirma o pagamento (sem convite, sem link) e o dono recebe o aviso 'Venda sem grupo'" 'tem("Pagamento Confirmado") && !tem("Acesso Liberado") && count(tg("createChatInviteLink"))==0 && '"$SEMGRUPO"'==1'

secao "G11.3 Upsell (depois do pagamento) com entrega própria"
UP='{"ofertas":{"upsell":{"ativo":true,"id_plano_destino":"p_anual","desconto_percentual":0,"entrega_tipo":"link","link_entrega":"'"$LINK2"'"}}}'
reset; setflow guiado_base "$(php -r '$a=json_decode($argv[1],true);$b=json_decode($argv[2],true);echo json_encode(array_replace_recursive($a,$b));' "$PLANOS" "$UP")"
comprar p_mensal; pagar "$TX"; mark; cb "verificar_pagamento_$TX"
chk "depois de pagar o Mensal, a oferta do Upsell chega com os botões 'pos'" 'cbs(ultimo("sendMessage"))==["basico::pos::up::p_anual::1","basico::pos::up::p_anual::0"]'
mark; cb "basico::pos::up::p_anual::1"; TX=$(ultimotx)
chk "aceitar: Pix 'Upgrade: Anual' com o LINK do Upsell (e nenhum grupo, apesar de o Anual ter grupo)" '($v='"$VENDA"') && $v["nome_produto"]=="Upgrade: Anual" && $v["link_entrega"]=="'"$LINK2"'" && $v["id_grupo_telegram"]===null'
pagar "$TX"; mark; cb "verificar_pagamento_$TX"
chk "pagou o upgrade: mensagem com o link do Upsell, sem convite de grupo" 'strpos('"$TXT_LIMPO"',"'"$LINK2"'")!==false && count(tg("createChatInviteLink"))==0 && count(tgerr())==0'
UP='{"ofertas":{"upsell":{"ativo":true,"id_plano_destino":"p_anual","desconto_percentual":0}}}'
reset; setflow guiado_base "$(php -r '$a=json_decode($argv[1],true);$b=json_decode($argv[2],true);echo json_encode(array_replace_recursive($a,$b));' "$PLANOS" "$UP")"
comprar p_mensal; pagar "$TX"; cb "verificar_pagamento_$TX"; mark; cb "basico::pos::up::p_anual::1"
chk "Upsell antigo (sem entrega_tipo): usa a entrega do plano oferecido (grupo 1), como antes" '($v='"$VENDA"') && $v["id_grupo_telegram"]=="'"$G1_"'" && $v["link_entrega"]===null'
UP='{"ofertas":{"upsell":{"ativo":true,"id_plano_destino":"p_link","desconto_percentual":0,"entrega_tipo":"plano"}}}'
reset; setflow guiado_base "$(php -r '$a=json_decode($argv[1],true);$b=json_decode($argv[2],true);echo json_encode(array_replace_recursive($a,$b));' "$PLANOS" "$UP")"
comprar p_mensal; pagar "$TX"; cb "verificar_pagamento_$TX"; mark; cb "basico::pos::up::p_link::1"
chk "Upsell 'igual ao plano' cujo plano entrega por link: leva o link do plano" '($v='"$VENDA"') && $v["link_entrega"]=="'"$LINK"'" && $v["id_grupo_telegram"]===null'
UP='{"ofertas":{"upsell":{"ativo":true,"id_plano_destino":"p_anual","desconto_percentual":0,"entrega_tipo":"grupo","id_grupo":"'"$G2_"'"}}}'
reset; setflow guiado_base "$(php -r '$a=json_decode($argv[1],true);$b=json_decode($argv[2],true);echo json_encode(array_replace_recursive($a,$b));' "$PLANOS" "$UP")"
comprar p_mensal; pagar "$TX"; cb "verificar_pagamento_$TX"; mark; cb "basico::pos::up::p_anual::1"
chk "Upsell com grupo próprio (grupo 2)" '($v='"$VENDA"') && $v["id_grupo_telegram"]=="'"$G2_"'" && $v["link_entrega"]===null'

secao "G11.4 Downsell com entrega própria (o clique no plano do downsell gera o Pix)"
DN='{"ofertas":{"downsell":{"ativo":true,"planos_oferecidos":["p_grupo","p_link"],"id_plano_destino":"p_grupo","desconto_percentual":10,"entrega_tipo":"link","link_entrega":"'"$LINK2"'"}}}'
reset; setflow guiado_base "$(php -r '$a=json_decode($argv[1],true);$b=json_decode($argv[2],true);echo json_encode(array_replace_recursive($a,$b));' "$PLANOS" "$DN")"
mark; cb "basico::of::bp::p_mensal::d0::0"; TX=$(ultimotx)
chk "plano do downsell com link próprio: Pix com 10% de desconto, link do Downsell, sem grupo (mesmo o plano tendo grupo)" '($v='"$VENDA"') && abs($v["valor"]-17.91)<0.001 && $v["link_entrega"]=="'"$LINK2"'" && $v["id_grupo_telegram"]===null'
pagar "$TX"; mark; cb "verificar_pagamento_$TX"
chk "pagou: mensagem com o link do Downsell" 'strpos('"$TXT_LIMPO"',"'"$LINK2"'")!==false && count(tgerr())==0'
DN='{"ofertas":{"downsell":{"ativo":true,"planos_oferecidos":["p_grupo","p_link"],"id_plano_destino":"p_grupo","desconto_percentual":0}}}'
reset; setflow guiado_base "$(php -r '$a=json_decode($argv[1],true);$b=json_decode($argv[2],true);echo json_encode(array_replace_recursive($a,$b));' "$PLANOS" "$DN")"
mark; cb "basico::of::bp::p_mensal::d0::0"
chk "Downsell sem entrega própria: cada plano entrega do jeito dele (d0 = grupo 1)" '($v='"$VENDA"') && $v["id_grupo_telegram"]=="'"$G1_"'" && $v["link_entrega"]===null'
mark; cb "basico::of::bp::p_mensal::d1::0"
chk "Downsell sem entrega própria (d1 = plano com link): leva o link do plano" '($v='"$VENDA"') && $v["link_entrega"]=="'"$LINK"'" && $v["id_grupo_telegram"]===null'
DN='{"ofertas":{"downsell":{"ativo":true,"planos_oferecidos":["p_grupo"],"id_plano_destino":"p_grupo","desconto_percentual":0,"entrega_tipo":"nenhuma"}}}'
reset; setflow guiado_base "$(php -r '$a=json_decode($argv[1],true);$b=json_decode($argv[2],true);echo json_encode(array_replace_recursive($a,$b));' "$PLANOS" "$DN")"
mark; cb "basico::of::bp::p_mensal::d0::0"
chk "Downsell 'nenhuma': só a venda, sem grupo e sem link" '($v='"$VENDA"') && $v["id_grupo_telegram"]===null && $v["link_entrega"]===null'

secao "G11.5 Pack com entrega por link"
PK='{"packs":{"ativo":true,"itens!":[
 {"id":"k_link","ativo":true,"emoji":"🔗","nome":"Pack por link","preco":9.9,"descricao":"","midia_tipo":"none","midia_path":"","entrega_tipo":"link","link_entrega":"'"$LINK2"'","id_grupo":""},
 {"id":"k_grupo","ativo":true,"emoji":"👥","nome":"Pack grupo antigo","preco":9.9,"descricao":"","midia_tipo":"none","midia_path":"","id_grupo":"'"$G2_"'"}]}}'
reset; setflow guiado_base "$PK"
mark; cb "basico::packbuy::k_link"; TX=$(ultimotx)
chk "pack com link: venda com o link, sem grupo, e o prazo é 'vitalício' (36500 dias)" '($v='"$VENDA"') && $v["link_entrega"]=="'"$LINK2"'" && $v["id_grupo_telegram"]===null && $v["dias_acesso"]==36500'
pagar "$TX"; mark; cb "verificar_pagamento_$TX"
chk "pagou o pack: mensagem com o link" 'strpos('"$TXT_LIMPO"',"'"$LINK2"'")!==false && count(tg("createChatInviteLink"))==0 && count(tgerr())==0'
mark; cb "basico::packbuy::k_grupo"
chk "pack antigo (só id_grupo): continua entregando no grupo 2" '($v='"$VENDA"') && $v["id_grupo_telegram"]=="'"$G2_"'" && $v["link_entrega"]===null'

secao "G11.6 Salvar pela API: link inválido é recusado (422), link bom é salvo e volta igual"
reset; sql "DELETE FROM fluxos WHERE id_usuario=$UID_" >/dev/null
RUIM='{"planos!":[{"id":"p1","nome":"Mensal","valor":19.9,"dias_acesso":30,"unidade_acesso":"dias","entrega_tipo":"link","link_entrega":"meusite.com/area"}]}'
mark; api POST salvar_fluxo "$(payloadfluxo guiado_base "$RUIM" 'Link ruim')"
chk "plano com link sem https://: 422 e a mensagem cita o plano" 'http()==422 && strpos(resp()["mensagem"],"do plano \"Mensal\"")!==false && strpos(resp()["mensagem"],"https://")!==false && q1("SELECT COUNT(*) FROM fluxos WHERE id_usuario=?",[ids()["uid"]])==0'
RUIM='{"planos!":[{"id":"p1","nome":"Mensal","valor":19.9,"dias_acesso":30,"unidade_acesso":"dias","entrega_tipo":"link","link_entrega":"javascript:alert(1)"}]}'
mark; api POST salvar_fluxo "$(payloadfluxo guiado_base "$RUIM" 'Link js')"
chk "link 'javascript:' é recusado" 'http()==422 && q1("SELECT COUNT(*) FROM fluxos WHERE id_usuario=?",[ids()["uid"]])==0'
RUIM='{"ofertas":{"upsell":{"ativo":true,"entrega_tipo":"link","link_entrega":""}}}'
mark; api POST salvar_fluxo "$(payloadfluxo guiado_base "$RUIM" 'Upsell ruim')"
chk "Upsell ligado com entrega por link vazia: 422 citando o Upsell" 'http()==422 && strpos(resp()["mensagem"],"do Upsell")!==false'
RUIM='{"ofertas":{"upsell":{"ativo":false,"entrega_tipo":"link","link_entrega":""}}}'
mark; api POST salvar_fluxo "$(payloadfluxo guiado_base "$RUIM" 'Upsell desligado')"
chk "Upsell DESLIGADO com link vazio não trava o salvar" 'http()==200 && resp()["sucesso"]===true'
sql "DELETE FROM fluxos WHERE id_usuario=$UID_" >/dev/null
mark; api POST salvar_fluxo "$(payloadfluxo guiado_base "$PLANOS" 'Link bom')"
chk "links bons: salvo (200)" 'http()==200 && resp()["sucesso"]===true'
GID=$(php -r '$j=json_decode(file_get_contents("/tmp/pt/resp.json"),true); echo $j["body"]["fluxo"]["id"];')
mark; api GET obter_fluxo '' "id=$GID"
chk "ao reabrir, o plano volta com entrega_tipo e link_entrega iguais" 'http()==200 && ($p=resp()["fluxo"]["dados_fluxograma"]["planos"][0]) && $p["entrega_tipo"]=="link" && $p["link_entrega"]=="'"$LINK"'"'
sql "DELETE FROM fluxos WHERE id_usuario=$UID_" >/dev/null

secao "G11.7 Banco ainda SEM a coluna link_entrega (migração não rodou): nada quebra"
reset; setflow guiado_base "$PLANOS"
sql "ALTER TABLE vendas DROP COLUMN link_entrega" >/dev/null
comprar p_link
chk "Pix é gerado normalmente; a venda fica sem link e o aviso vai para o log" 'count(gw())==1 && count(tgerr())==0 && ($v='"$VENDA"') && $v["status"]=="gerado" && !array_key_exists("link_entrega",$v) && strpos((string)@file_get_contents("/tmp/pt/app/logs/vendas_debug.log"),"LINK DE ENTREGA NAO GRAVADO")!==false'
pagar "$TX"; mark; cb "verificar_pagamento_$TX"
chk "o pagamento confirma sem erro (só a mensagem, sem link) e o dono recebe 'Venda sem grupo'" 'tem("Pagamento Confirmado") && !tem("area.exemplo") && count(tgerr())==0 && semerros() && '"$SEMGRUPO"'==1'
sql "ALTER TABLE vendas ADD COLUMN link_entrega VARCHAR(500) DEFAULT NULL" >/dev/null
reset; setflow guiado_base "$PLANOS"
comprar p_link
chk "coluna de volta: o link volta a ser gravado" '('"$VENDA"')["link_entrega"]=="'"$LINK"'"'
