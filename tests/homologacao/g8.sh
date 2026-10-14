#!/bin/bash
# GUIADO 8 (09/10/2026): plano com ASSINATURA RECORRENTE no Fluxo Guiado (Pix automático da gateway) ao lado de plano de Pix único.
source /tmp/pt/lib.sh
VENDA='q("SELECT * FROM vendas WHERE bot_id=? ORDER BY id DESC LIMIT 1",[ids()["bot"]])[0]'
UID_=$(php -r 'echo json_decode(file_get_contents("/tmp/pt/ids.json"),true)["uid"];')
PLANOS='{"planos!":[{"id":"p_unico","nome":"Único","valor":19.9,"dias_acesso":30,"unidade_acesso":"dias","id_grupo":"-1001000000001","cor":""},{"id":"p_sem","nome":"Semanal","valor":9.9,"dias_acesso":30,"unidade_acesso":"dias","id_grupo":"-1001000000001","cor":"","cobranca":"recorrente","periodicidade":"semanal"},{"id":"p_men","nome":"Mensal","valor":29.9,"dias_acesso":1,"unidade_acesso":"dias","id_grupo":"-1001000000001","cor":"","cobranca":"recorrente","periodicidade":"mensal"}]'
compra() { janela; reset; setflow guiado_base "$PLANOS$2}"; msg "/start" Maria >/dev/null; mark; cb "basico::plano::$1" >/dev/null; }

secao "G8.1 Lista de planos"
janela; reset; setflow guiado_base "$PLANOS}"; msg "/start" Maria >/dev/null; mark; cb "basico::ver_planos" >/dev/null
chk "botões: plano único sem sufixo; recorrentes com '/semana' e '/mês'" '($t=array_map(fn($b)=>$b["text"], botoes(ultimo("sendMessage")))) && in_array("Único — R$ 19,90",$t) && in_array("Semanal — R$ 9,90/semana",$t) && in_array("Mensal — R$ 29,90/mês",$t)'

secao "G8.2 Plano recorrente semanal"
compra p_sem
chk "usa o endpoint de ASSINATURA com valor, produto e periodicidade semanal" 'count(gw())==1 && gw()[0]["gw"]=="POST /gateway/pix/subscription" && abs(gw()[0]["valor"]-9.9)<0.001 && gw()[0]["produto"]=="Semanal" && gw()[0]["periodicidade"]["periodicityType"]=="WEEKS" && gw()[0]["periodicidade"]["periodicity"]==1 && !empty(gw()[0]["splits"])'
chk "venda: tipo assinatura, id_assinatura, acesso de 7 dias (ignora o tempo do plano) e grupo do plano" '($v='"$VENDA"') && $v["tipo_cobranca"]=="assinatura" && strpos($v["id_assinatura"],"SUBSIM")===0 && $v["dias_acesso"]==7 && $v["tempo_acesso_minutos"]==10080 && $v["id_grupo_telegram"]=="-1001000000001"'
chk "documento do pagador: CNPJ fixo exigido pela gateway" 'strlen(gw()[0]["doc"])==14'

secao "G8.3 Plano recorrente mensal e plano único"
compra p_men
chk "mensal: MONTHS x1, 30 dias, valor 29,90" 'gw()[0]["periodicidade"]["periodicityType"]=="MONTHS" && gw()[0]["periodicidade"]["periodicity"]==1 && abs(gw()[0]["valor"]-29.9)<0.001 && ('"$VENDA"')["dias_acesso"]==30'
compra p_unico
chk "plano único continua no Pix avulso (receive), tipo 'unica', sem id_assinatura" 'gw()[0]["gw"]=="POST /gateway/pix/receive" && ($v='"$VENDA"') && $v["tipo_cobranca"]=="unica" && empty($v["id_assinatura"])'

secao "G8.4 Order bump e upsell com plano recorrente"
BUMP='"ofertas":{"order_bump":{"ativo":true,"nome":"Extra","valor_extra":5,"mensagem":"Adicione o extra","texto_aceitar":"Sim","texto_recusar":"Não"}}'
compra p_sem ",$BUMP"
chk "recorrente + order bump ativo: NÃO pergunta o extra (cobraria todo ciclo) e gera a assinatura de R$ 9,90" 'count(gw())==1 && abs(gw()[0]["valor"]-9.9)<0.001 && !tem("Adicione o extra")'
compra p_unico ",$BUMP"
chk "plano único + order bump ativo: pergunta o extra normalmente" 'tem("Adicione o extra") && count(gw())==0'
UP='"ofertas":{"upsell":{"ativo":true,"id_plano_destino":"p_men","desconto_percentual":10,"mensagem":"Leve o mensal","texto_aceitar":"Quero","texto_recusar":"Não"}}'
compra p_unico ",$UP"
cb "basico::of::up::p_unico::1" >/dev/null
chk "upsell aceito para plano recorrente: assinatura mensal com 10% de desconto (R$ 26,91)" 'count(gw())==1 && gw()[0]["gw"]=="POST /gateway/pix/subscription" && abs(gw()[0]["valor"]-26.91)<0.001'

secao "G8.5 Pagamento do 1º ciclo e conta PF"
compra p_sem
TX=$(ultimotx); pagar "$TX"; cb "verificar_pagamento_$TX" >/dev/null
chk "1º Pix pago: venda 'pago', membro entra e a mensagem de aprovado sai" '('"$VENDA"')["status"]=="pago" && tem("Pagamento Confirmado")'
reset; setflow guiado_base "$PLANOS}"
sql "UPDATE usuarios_gateways SET tipo_conta='pf' WHERE id_usuario=$UID_" >/dev/null
msg "/start" Maria >/dev/null; mark; cb "basico::plano::p_sem" >/dev/null
chk "conta PF não suporta recorrente: mensagem amigável e nenhuma assinatura criada" 'tem("Não foi possível gerar seu Pix agora") && count(gw())==0'
sql "UPDATE usuarios_gateways SET tipo_conta='pj' WHERE id_usuario=$UID_" >/dev/null
