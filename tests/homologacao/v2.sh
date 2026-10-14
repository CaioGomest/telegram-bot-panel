#!/bin/bash
# VISUAL 2: upsell / downsell / order bump (valores finais no Pix), sorteio (randomizer).
source /tmp/pt/lib.sh
BOTID=$(php -r 'echo json_decode(file_get_contents("/tmp/pt/ids.json"),true)["bot"];')
UID_=$(php -r 'echo json_decode(file_get_contents("/tmp/pt/ids.json"),true)["uid"];')
VENDA='q("SELECT * FROM vendas WHERE bot_id=? ORDER BY id DESC LIMIT 1",[ids()["bot"]])[0]'
OFERTAS='q("SELECT tipo, desconto_percentual, valor_extra, nome FROM fluxo_ofertas_aceitas WHERE bot_id=? ORDER BY criado_em",[ids()["bot"]])'

secao "V2.1 Ofertas: mensagens e callbacks"
reset; setflow v_ofertas
mark; msg "/start"
chk "upsell: mensagem do bloco com botões Quero/Agora não e callbacks saida::<bloco>::aceito|recusado" '($e=ultimo("sendMessage")) && txt($e)=="Upsell: 30% off!" && cbs($e)==["saida::up::aceito","saida::up::recusado"] && array_column(botoes($e),"text")==["Quero","Agora não"]'
chk "fluxo para na oferta (espera o cliente): nada de Pix ainda" 'count(gw())==0'
setflow v_ofertas '{"operators":{"up":{"properties":{"mensagem":"","texto_aceitar":"","texto_recusar":"","cor_aceitar":"success","cor_recusar":"danger"}}}}'
mark; msg "/start"
chk "oferta sem textos: usa os padrões e as cores configuradas" '($e=ultimo("sendMessage")) && txt($e)=="Temos uma oferta especial para você." && array_column(botoes($e),"text")==["Sim, quero! 🔥","Não, obrigado"] && botoes($e)[0]["style"]=="success" && botoes($e)[1]["style"]=="danger"'

secao "V2.2 Ofertas: valor final do Pix em cada caminho (base R\$ 100,00)"
reset; setflow v_ofertas; msg "/start" >/dev/null
mark; cb "saida::up::aceito"
chk "upsell aceito: vai pro order bump; resposta guardada (30%)" 'cbs(ultimo("sendMessage"))==["saida::bp::aceito","saida::bp::recusado"] && ($o='"$OFERTAS"') && count($o)==1 && $o[0]["tipo"]=="upsell" && $o[0]["desconto_percentual"]==30'
mark; cb "saida::bp::aceito"
chk "upsell 30% + bump +R\$ 5 = R\$ 75,00; produto 'Combo_Visual* + Bônus ebook' (escapado)" 'count(gw())==1 && abs(gw()[0]["valor"]-75)<0.001 && tem("Combo".chr(92)."_Visual".chr(92)."* + Bônus ebook") && count(tgerr())==0'
chk "venda do visual: id_operador_fluxo='pix', valor 75, ligada ao fluxo" '($v='"$VENDA"') && $v["id_operador_fluxo"]=="pix" && abs($v["valor"]-75)<0.001 && $v["id_fluxo"]==ids()["fluxo"]'
reset; setflow v_ofertas; msg "/start" >/dev/null
mark; cb "saida::up::recusado"
chk "upsell recusado: vai ao downsell" 'cbs(ultimo("sendMessage"))==["saida::dn::aceito","saida::dn::recusado"]'
mark; cb "saida::dn::aceito"; cb "saida::bp::recusado"
chk "downsell 10% aceito + bump recusado = R\$ 90,00 (sem extra no nome)" 'count(gw())==1 && abs(gw()[0]["valor"]-90)<0.001 && !tem("Bônus")'
reset; setflow v_ofertas; msg "/start" >/dev/null
cb "saida::up::recusado" >/dev/null; cb "saida::dn::recusado" >/dev/null
mark; cb "saida::bp::recusado"
chk "tudo recusado: Pix cheio R\$ 100,00" 'count(gw())==1 && abs(gw()[0]["valor"]-100)<0.001 && q1("SELECT COUNT(*) FROM fluxo_ofertas_aceitas WHERE bot_id=?",[ids()["bot"]])==0'
reset; setflow v_ofertas; msg "/start" >/dev/null
cb "saida::up::aceito" >/dev/null
msg "/start" >/dev/null
chk "novo /start apaga as ofertas aceitas da jornada anterior" 'q1("SELECT COUNT(*) FROM fluxo_ofertas_aceitas WHERE bot_id=?",[ids()["bot"]])==0'
cb "saida::up::recusado" >/dev/null; cb "saida::dn::recusado" >/dev/null
mark; cb "saida::bp::recusado"
chk "depois do /start o desconto antigo NÃO vale: Pix cheio R\$ 100,00" 'abs(gw()[0]["valor"]-100)<0.001'
reset; setflow v_ofertas; msg "/start" >/dev/null
cb "saida::up::aceito" >/dev/null
sql "UPDATE fluxo_ofertas_aceitas SET criado_em = NOW() - INTERVAL 13 HOUR WHERE bot_id=$BOTID" >/dev/null
mark; cb "saida::bp::recusado"
chk "oferta aceita há mais de 12 h expira: Pix cheio R\$ 100,00" 'abs(gw()[0]["valor"]-100)<0.001'
reset; setflow v_ofertas; msg "/start" >/dev/null
cb "saida::up::aceito" >/dev/null; cb "saida::dn::aceito" >/dev/null
mark; cb "saida::bp::recusado"
chk "upsell e downsell aceitos (cliques fora de ordem): vale o último desconto (10%) = R\$ 90,00" 'abs(gw()[0]["valor"]-90)<0.001'
reset; setflow v_ofertas '{"operators":{"pix":{"properties":{"valor":3}},"up":{"properties":{"desconto_percentual":70}}}}'; msg "/start" >/dev/null
cb "saida::up::aceito" >/dev/null
mark; cb "saida::bp::recusado"
chk "desconto que levaria abaixo do mínimo da gateway (R\$ 3 com 70% = 0,90) é limitado ao mínimo R\$ 2,10" 'count(gw())==1 && abs(gw()[0]["valor"]-2.1)<0.001'
reset; setflow v_ofertas; msg "/start" >/dev/null
mark; cb "saida::nao_existe::aceito"
chk "callback de oferta de bloco inexistente: ignorado sem erro" 'count(tg("sendMessage"))==0 && count(gw())==0 && semerros()'
mark; cb "saida::up"
chk "callback de oferta malformado: ignorado sem erro" 'count(tg("sendMessage"))==0 && semerros()'
reset; setflow v_ofertas '{"operators":{"bp":{"properties":{"nome":"","valor_extra":8}}}}'; msg "/start" >/dev/null
cb "saida::up::recusado" >/dev/null; cb "saida::dn::recusado" >/dev/null
mark; cb "saida::bp::aceito"
chk "bump sem nome: produto 'Combo... + Extra'; valor 100 + 8 = R\$ 108,00" 'abs(gw()[0]["valor"]-108)<0.001 && tem("+ Extra")'
reset; setflow v_ofertas '{"operators":{"up":{"properties":{"desconto_percentual":150}}}}'; msg "/start" >/dev/null
cb "saida::up::aceito" >/dev/null
mark; cb "saida::bp::recusado"
chk "desconto acima de 100% é limitado a 100% e o mínimo da gateway protege o valor (R\$ 2,10)" 'count(gw())==1 && abs(gw()[0]["valor"]-2.1)<0.001'

secao "V2.3 Sorteio (randomizer)"
reset; setflow v_sorteio
mark; for i in $(seq 1 120); do msg "/start" >/dev/null; done
chk "120 sorteios 70/30: distribuição perto do esperado (caminho 0 entre 55% e 85%)" '($t=array_map("txt",tg("sendMessage"))) && ($c0=count(array_filter($t,fn($x)=>$x=="CAMINHO_0"))) && ($c1=count(array_filter($t,fn($x)=>$x=="CAMINHO_1"))) && $c0+$c1==120 && $c0>=66 && $c0<=102 && $c1>0'
setflow v_sorteio '{"operators":{"sort":{"properties":{"caminhos!":[{"peso":100},{"peso":0}]}}}}'
mark; for i in $(seq 1 15); do msg "/start" >/dev/null; done
chk "pesos 100/0: sempre o caminho 0" 'count(array_filter(array_map("txt",tg("sendMessage")),fn($x)=>$x=="CAMINHO_0"))==15'
setflow v_sorteio '{"operators":{"sort":{"properties":{"caminhos!":[{"peso":0},{"peso":100}]}}}}'
mark; for i in $(seq 1 15); do msg "/start" >/dev/null; done
chk "pesos 0/100: sempre o caminho 1" 'count(array_filter(array_map("txt",tg("sendMessage")),fn($x)=>$x=="CAMINHO_1"))==15'
setflow v_sorteio '{"operators":{"sort":{"properties":{"caminhos!":[{"peso":0},{"peso":0}]}}}}'
mark; msg "/start"
chk "pesos 0/0: cai no primeiro caminho ligado, sem erro" 'count(tg("sendMessage"))==1 && semerros()'
setflow v_sorteio '{"operators":{"sort":{"properties":{"caminhos!":[]}}}}'
mark; msg "/start"
chk "sorteio sem caminhos configurados: segue o primeiro link, sem erro" 'count(tg("sendMessage"))==1 && semerros()'
setflow v_sorteio '{"operators":{"sort":{"properties":{"caminhos!":[{"peso":50},{"peso":50},{"peso":50}]}}}}'
mark; for i in $(seq 1 12); do msg "/start" >/dev/null; done
chk "3º caminho sem ligação no grafo: nada é enviado nele (sem erro), os outros funcionam" 'semerros() && count(tg("sendMessage"))<=12 && count(tg("sendMessage"))>=4'
echo; echo "FIM V2"
