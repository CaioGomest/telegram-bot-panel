#!/bin/bash
# GUIADO 2: ofertas (upsell/downsell/order bump), geração do Pix, unidades de acesso, flags de pagamento, falhas de gateway.
source /tmp/pt/lib.sh
BOTID=$(php -r 'echo json_decode(file_get_contents("/tmp/pt/ids.json"),true)["bot"];')
UID_=$(php -r 'echo json_decode(file_get_contents("/tmp/pt/ids.json"),true)["uid"];')
ULTVENDA='q("SELECT * FROM vendas WHERE bot_id=? ORDER BY id DESC LIMIT 1",[ids()["bot"]])[0]'

secao "G2.1 Plano sem ofertas -> Pix direto"
reset; setflow guiado_base
mark; msg "/start"; cb "basico::ver_planos"; mark; cb "basico::plano::p_mensal"
chk "clique no plano gera Pix na hora (sem oferta no meio)" 'count(gw())==1 && abs(gw()[0]["valor"]-19.9)<0.001'
chk "mensagem do pedido: produto e valor formatado" '($e=tg("sendMessage")[0]) && ($t=txt($e)) && strpos($t,"Pedido Criado")!==false && strpos($t,"Mensal")!==false && strpos($t,"R$ 19,90")!==false && ($e["p"]["parse_mode"]??"")=="Markdown"'
chk "texto padrão de instruções (aguardando pagamento) quando msg_instrucoes vazia" 'tem("Aguardando pagamento") && tem("Toque no c")'
chk "2ª mensagem traz o Pix copia-e-cola em <code> (HTML)" '($e=tg("sendMessage")[1]) && strpos(txt($e),"<code>00020126")===0 && $e["p"]["parse_mode"]=="HTML"'
chk "botões: Copiar (copy_text) + 'Já fiz o pagamento' com callback do txid" '($b=botoes(tg("sendMessage")[1])) && count($b)==2 && isset($b[0]["copy_text"]["text"]) && strpos($b[1]["callback_data"],"verificar_pagamento_SIMTX")===0'
chk "nenhum erro do Telegram nem aviso de PHP" 'count(tgerr())==0 && semerros()'
chk "venda gravada: valor, status gerado, tipo única, expiração 15 min" '($v='"$ULTVENDA"') && abs($v["valor"]-19.9)<0.001 && $v["status"]=="gerado" && $v["tipo_cobranca"]=="unica" && $v["tempo_expiracao_minutos"]==15'
chk "venda: grupo, dias e minutos de acesso do plano (30 dias = 43200 min)" '($v='"$ULTVENDA"') && $v["id_grupo_telegram"]=="-1001000000001" && $v["dias_acesso"]==30 && $v["tempo_acesso_minutos"]==43200'
chk "venda sem operador de fluxo (guiado) e ligada ao fluxo e ao gateway" '($v='"$ULTVENDA"') && $v["id_operador_fluxo"]===null && $v["id_fluxo"]==ids()["fluxo"] && $v["id_gateway"]==ids()["gw"]'
chk "venda guarda o txid e o telegram do cliente" '($v='"$ULTVENDA"') && strpos($v["transacao_id"],"SIMTX")===0 && $v["id_telegram"]=="777000111"'
chk "atividade 'Pix Gerado' registrada para o dono" 'q1("SELECT COUNT(*) FROM atividades WHERE id_usuario=? AND tipo=\"pix_gerado\"",[ids()["uid"]])==1'
chk "payload do gateway: documento 14 dígitos (CNPJ fixo), nome do lead e vencimento ~15 min" '($g=gw()[0]) && strlen($g["doc"])==14 && $g["nome"]=="Maria Teste" && abs(strtotime($g["dueDate"])-time()-900)<120'
SPLIT=$(sql "SELECT taxa_split,tipo_split,chave_pix_split FROM gateways WHERE nome='omegapayments'" | head -1); echo "  (split atual do gateway: $SPLIT)"

secao "G2.2 Ofertas isoladas (cada uma sozinha)"
# bump
reset; setflow guiado_base '{"ofertas":{"order_bump":{"ativo":true}}}'
mark; msg "/start"; cb "basico::ver_planos"; mark; cb "basico::plano::p_mensal"
chk "ORDER BUMP: antes do Pix aparece a oferta com aceitar/recusar" 'count(gw())==0 && ($e=ultimo("sendMessage")) && strpos(txt($e),"Checklist")!==false && cbs($e)==["basico::of::bp::p_mensal::o::1","basico::of::bp::p_mensal::o::0"]'
chk "oferta: cores dos botões (success no aceitar)" '($b=botoes(ultimo("sendMessage"))) && ($b[0]["style"]??"")=="success" && !isset($b[1]["style"])'
mark; cb "basico::of::bp::p_mensal::o::1"
chk "bump ACEITO: Pix = 19,90 + 7,90 = 27,80 e nome 'Mensal + Checklist PDF'" 'count(gw())==1 && abs(gw()[0]["valor"]-27.8)<0.001 && tem("Mensal + Checklist PDF")'
chk "venda gravada com valor 27,80" 'abs('"$ULTVENDA"'["valor"]-27.8)<0.001'
mark; cb "basico::of::bp::p_mensal::o::0"
chk "bump RECUSADO: Pix = 19,90 sem extra" 'count(gw())==1 && abs(gw()[0]["valor"]-19.9)<0.001 && !tem("Checklist")'
# upsell
reset; setflow guiado_base '{"ofertas":{"upsell":{"ativo":true}}}'
mark; msg "/start"; mark; cb "basico::plano::p_mensal"
chk "UPSELL: oferta aparece com a mensagem e callbacks up" 'count(gw())==0 && ($e=ultimo("sendMessage")) && strpos(txt($e),"ANUAL")!==false && cbs($e)==["basico::of::up::p_mensal::1","basico::of::up::p_mensal::0"]'
mark; cb "basico::of::up::p_mensal::1"
chk "upsell ACEITO: Pix do plano Anual com 15% = R\$ 126,65 e dias do anual" 'count(gw())==1 && abs(gw()[0]["valor"]-126.65)<0.001 && tem("Anual") && ($v='"$ULTVENDA"') && $v["dias_acesso"]==365 && $v["tempo_acesso_minutos"]==525600'
mark; cb "basico::of::up::p_mensal::0"
chk "upsell RECUSADO: segue com o plano escolhido (19,90)" 'count(gw())==1 && abs(gw()[0]["valor"]-19.9)<0.001 && tem("Mensal")'
mark; cb "basico::plano::p_anual"
chk "upsell cujo destino é o próprio plano escolhido é pulado (Pix direto do Anual = 149)" 'count(gw())==1 && abs(gw()[0]["valor"]-149)<0.001'
# downsell
reset; setflow guiado_base '{"ofertas":{"downsell":{"ativo":true}}}'
mark; cb "basico::plano::p_anual"
chk "DOWNSELL: oferta aparece ao escolher o plano caro" 'count(gw())==0 && ($e=ultimo("sendMessage")) && strpos(txt($e),"mensal")!==false && cbs($e)==["basico::of::dn::p_anual::1","basico::of::dn::p_anual::0"]'
mark; cb "basico::of::dn::p_anual::1"
chk "downsell ACEITO: vira Pix do Mensal (19,90, 30 dias)" 'count(gw())==1 && abs(gw()[0]["valor"]-19.9)<0.001 && ($v='"$ULTVENDA"') && $v["dias_acesso"]==30'
mark; cb "basico::of::dn::p_anual::0"
chk "downsell RECUSADO: segue com o Anual (149)" 'count(gw())==1 && abs(gw()[0]["valor"]-149)<0.001'
mark; cb "basico::plano::p_mensal"
chk "downsell com destino igual ao plano escolhido é pulado" 'count(gw())==1'

secao "G2.3 Cadeia completa (upsell + downsell + bump)"
reset; setflow guiado_base '{"ofertas":{"upsell":{"ativo":true},"downsell":{"ativo":true,"desconto_percentual":20},"order_bump":{"ativo":true}}}'
mark; cb "basico::plano::p_mensal"
chk "passo 1: upsell" 'cbs(ultimo("sendMessage"))[0]=="basico::of::up::p_mensal::1" && count(gw())==0'
mark; cb "basico::of::up::p_mensal::1"
chk "passo 2 (aceitou upsell): vai direto ao bump (pula o downsell)" 'cbs(ultimo("sendMessage"))[0]=="basico::of::bp::p_mensal::u::1" && count(gw())==0'
mark; cb "basico::of::bp::p_mensal::u::1"
chk "upsell + bump aceitos: 149 * 0,85 + 7,90 = R\$ 134,55 — 'Anual + Checklist PDF'" 'count(gw())==1 && abs(gw()[0]["valor"]-134.55)<0.001 && tem("Anual + Checklist PDF")'
mark; cb "basico::of::up::p_mensal::0"
chk "recusou upsell: downsell com destino=Mensal (o plano escolhido) é pulado -> segue pro bump" 'count(gw())==0 && cbs(ultimo("sendMessage"))[0]=="basico::of::bp::p_mensal::o::1"'
mark; cb "basico::plano::p_anual"
chk "plano Anual: upsell pulado (destino=Anual), cai no downsell" 'strpos(cbs(ultimo("sendMessage"))[0],"basico::of::dn::p_anual")===0'
mark; cb "basico::of::dn::p_anual::1"
chk "downsell aceito do Anual: segue pro bump com variante d" 'cbs(ultimo("sendMessage"))[0]=="basico::of::bp::p_anual::d::1"'
mark; cb "basico::of::bp::p_anual::d::1"
chk "downsell (Mensal com 20% = 15,92) + bump 7,90 = R\$ 23,82 — 'Mensal + Checklist PDF'" 'count(gw())==1 && abs(gw()[0]["valor"]-23.82)<0.001 && tem("Mensal + Checklist PDF")'
mark; cb "basico::of::bp::p_anual::o::0"
chk "bump recusado após recusar tudo: Anual cheio 149" 'abs(gw()[0]["valor"]-149)<0.001'
mark; cb "basico::of::bp::p_mensal::x::1"
chk "variante adulterada (x) é tratada como 'o' (sem desconto): 19,90 + 7,90" 'abs(gw()[0]["valor"]-27.8)<0.001'
mark; cb "basico::of::up::nao_existe::1"
chk "oferta com plano inexistente não gera Pix nem erro fatal" 'semerros() && count(gw())<=1'
setflow guiado_base '{"ofertas":{"order_bump":{"ativo":true,"valor_extra":0}}}'
mark; cb "basico::plano::p_mensal"
chk "bump ativo com valor extra 0 é ignorado: Pix direto" 'count(gw())==1 && abs(gw()[0]["valor"]-19.9)<0.001'

secao "G2.4 Unidades de acesso (dias/horas/minutos) e nomes com caracteres especiais"
reset; setflow guiado_base
mark; cb "basico::plano::p_horas"
chk "plano em HORAS (2h): 120 minutos, dias_acesso=0" '($v='"$ULTVENDA"') && $v["tempo_acesso_minutos"]==120 && $v["dias_acesso"]==0'
mark; cb "basico::plano::p_min"
chk "plano em MINUTOS (30): 30 minutos, grupo 2" '($v='"$ULTVENDA"') && $v["tempo_acesso_minutos"]==30 && $v["dias_acesso"]==0 && $v["id_grupo_telegram"]=="-1001000000002"'
chk "nome com _ e * (Passe_30*min) escapado: Telegram aceitou o Markdown" 'count(tgerr())==0 && tem("Passe".chr(92)."_30".chr(92)."*min")'
setflow guiado_base '{"planos!":[{"id":"sg","nome":"Sem grupo","valor":10,"dias_acesso":7,"unidade_acesso":"dias","id_grupo":""}]}'
mark; cb "basico::plano::sg"
chk "plano sem grupo: venda sem id_grupo_telegram (só confirmação, sem convite)" '($v='"$ULTVENDA"') && $v["id_grupo_telegram"]===null && $v["tempo_acesso_minutos"]==10080'

secao "G2.5 Seção Pagamentos: mensagens e botões"
reset
setflow guiado_base '{"pagamentos":{"msg_instrucoes":"Pague em 15 min (rápido_e*fácil)","mostrar_copiar":false,"mostrar_confirmar":true}}'
mark; cb "basico::plano::p_mensal"
chk "instruções personalizadas substituem o texto padrão (com Markdown escapado)" 'tem("Pague em 15 min") && !tem("Aguardando pagamento") && count(tgerr())==0'
chk "só o botão 'Já fiz o pagamento' (copiar desligado)" '($b=botoes(tg("sendMessage")[1])) && count($b)==1 && isset($b[0]["callback_data"])'
setflow guiado_base '{"pagamentos":{"mostrar_copiar":true,"mostrar_confirmar":false}}'
mark; cb "basico::plano::p_mensal"
chk "só o botão Copiar (confirmar desligado)" '($b=botoes(tg("sendMessage")[1])) && count($b)==1 && isset($b[0]["copy_text"])'
setflow guiado_base '{"pagamentos":{"mostrar_copiar":false,"mostrar_confirmar":false}}'
mark; cb "basico::plano::p_mensal"
chk "os dois desligados: Pix em texto, sem teclado" '!isset(tg("sendMessage")[1]["p"]["reply_markup"])'
setflow guiado_base
sql "UPDATE fluxos SET dados_fluxograma=JSON_REMOVE(dados_fluxograma, '$.pagamentos') WHERE id=$(php -r 'echo json_decode(file_get_contents("/tmp/pt/ids.json"),true)["fluxo"];')" >/dev/null
mark; cb "basico::plano::p_mensal"
chk "fluxo sem a seção Pagamentos: usa padrões (copiar + confirmar ligados)" 'count(botoes(tg("sendMessage")[1]))==2'
setflow guiado_base
falha_cp() { echo '{"copy_text":"Bad Request: copy_text is not supported"}' > /tmp/pt/falhas.json; }
falha_cp
mark; cb "basico::plano::p_mensal"
chk "Telegram sem suporte a copy_text: reenvia o código sem o botão Copiar (mantém 'Já fiz')" '($m=tg("sendMessage")) && count($m)==3 && !($m[1]["ok"]??true) && ($b=botoes($m[2])) && count($b)==1 && isset($b[0]["callback_data"])'
semfalha

secao "G2.6 Falhas de gateway (mensagem amigável ao cliente, aviso ao dono)"
reset; setflow guiado_base
falha "{\"gw:*\":\"Erro simulado da OmegaPayments\"}"
mark; cb "basico::plano::p_mensal"
chk "gateway falhou: cliente recebe mensagem amigável e SEM detalhes técnicos" 'tem("Não foi possível gerar seu Pix agora") && !tem("OmegaPayments") && !tem("Tentativas")'
chk "nenhuma venda criada e atividade 'Falha ao gerar Pix' para o dono" 'q1("SELECT COUNT(*) FROM vendas WHERE bot_id=?",[ids()["bot"]])==0 && q1("SELECT COUNT(*) FROM atividades WHERE id_usuario=? AND titulo=\"Falha ao gerar Pix\"",[ids()["uid"]])==1'
semfalha
sql "UPDATE usuarios_gateways SET ativo=0 WHERE id_usuario=$UID_" >/dev/null
mark; cb "basico::plano::p_mensal"
chk "usuário desativou o gateway: 'Nenhum gateway configurado' ou falha amigável (sem fatal)" 'semerros() && (tem("Nenhum gateway") || tem("Não foi possível gerar"))'
sql "UPDATE usuarios_gateways SET ativo=1, client_id='' WHERE id_usuario=$UID_" >/dev/null
mark; cb "basico::plano::p_mensal"
chk "gateway com credencial vazia: falha amigável, sem venda" 'tem("Não foi possível gerar") && q1("SELECT COUNT(*) FROM vendas WHERE bot_id=?",[ids()["bot"]])==0'
sql "UPDATE usuarios_gateways SET client_id='sim_pub_key' WHERE id_usuario=$UID_" >/dev/null
setflow guiado_base '{"planos!":[{"id":"barato","nome":"Barato","valor":1,"dias_acesso":7,"unidade_acesso":"dias","id_grupo":""}]}'
mark; cb "basico::plano::barato"
chk "plano abaixo do mínimo da API (R\$ 1,00): gateway recusa, cliente recebe mensagem amigável" 'tem("Não foi possível gerar") && q1("SELECT COUNT(*) FROM vendas WHERE bot_id=?",[ids()["bot"]])==0'
setflow guiado_base '{"planos!":[{"id":"zero","nome":"Zero","valor":0,"dias_acesso":7,"unidade_acesso":"dias","id_grupo":""}]}'
mark; cb "basico::plano::zero"
chk "plano com valor 0: 'Não foi possível gerar o pagamento deste plano'" 'tem("Não foi possível gerar o pagamento deste plano") && count(gw())==0'
echo; echo "FIM G2"
