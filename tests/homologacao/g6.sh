#!/bin/bash
# GUIADO 6 (08/10/2026): bot sem permissão de banir no grupo e texto do aviso quando o fluxo NÃO remove do grupo.
source /tmp/pt/lib.sh
BOTID=$(php -r 'echo json_decode(file_get_contents("/tmp/pt/ids.json"),true)["bot"];')
MEMBRO='q("SELECT * FROM membros_grupos WHERE bot_id=? ORDER BY id DESC LIMIT 1",[ids()["bot"]])[0]'
membro() { janela; reset; setflow guiado_base "$2"; msg "/start" Maria >/dev/null; mark; cb "basico::plano::${1:-p_mensal}" >/dev/null; TX=$(ultimotx); pagar "$TX"; cb "verificar_pagamento_$TX"; mark; }
ajusta() { janela; sql "UPDATE membros_grupos SET criado_em = NOW() - INTERVAL $1, data_expiracao = $2 WHERE bot_id=$BOTID" >/dev/null; }
AVISO_DONO='q1("SELECT COUNT(*) FROM atividades WHERE id_usuario=? AND titulo=\"Remoção do grupo falhou\"",[ids()["uid"]])'

secao "G6.1 Bot sem permissão de banir no grupo"
sql "DELETE FROM atividades WHERE titulo='Remoção do grupo falhou'" >/dev/null
membro p_mensal '{"assinatura":{"avisos_antes":{"ativo":false}}}'
ajusta "31 DAY" "NOW() - INTERVAL 1 MINUTE"
falha '{"banChatMember":"Bad Request: not enough rights to restrict/ban chat member"}'
mark; cron cron_verificar_acessos
chk "ban recusado por falta de permissão: o membro continua 'ativo' (vai tentar de novo) e não recebe 'acesso expirou'" '('"$MEMBRO"')["status"]=="ativo" && count(tg("sendMessage"))==0'
chk "o DONO do bot é avisado no sino ('Remoção do grupo falhou', com a dica de dar a permissão Banir usuários)" "$AVISO_DONO"'==1 && strpos(q1("SELECT descricao FROM atividades WHERE titulo=\"Remoção do grupo falhou\" ORDER BY id DESC LIMIT 1"),"Banir usuários")!==false'
mark; cron cron_verificar_acessos
chk "rodada seguinte (mesmo dia): tenta de novo, mas NÃO repete o aviso ao dono" 'count(tg("banChatMember"))==1 && '"$AVISO_DONO"'==1'
semfalha
mark; cron cron_verificar_acessos
chk "bot ganhou a permissão: na rodada seguinte remove, expira o membro e manda 'acesso expirou'" 'count(tg("banChatMember"))==1 && ('"$MEMBRO"')["status"]=="expirado" && strpos(txt(ultimo("sendMessage")),"Seu acesso ao grupo expirou")!==false'

secao "G6.2 Aviso de vencimento conforme a opção 'Expulsar do grupo'"
membro p_mensal '{"assinatura":{"avisos_antes":{"ativo":false}}}'
ajusta "29 DAY" "NOW() + INTERVAL 20 HOUR"
mark; cron cron_aviso_vencimento
chk "padrão (remove do grupo ao expirar): aviso diz 'para não ser removido do grupo'" 'strpos(txt(ultimo("sendMessage")),"para não ser removido do grupo")!==false'
membro p_mensal '{"assinatura":{"avisos_antes":{"ativo":false},"acoes":{"expulsar":false}}}'
ajusta "29 DAY" "NOW() + INTERVAL 20 HOUR"
mark; cron cron_aviso_vencimento
chk "fluxo configurado para NÃO remover: aviso diz 'para não perder o acesso' e não fala em remoção" '($t=txt(ultimo("sendMessage"))) && strpos($t,"para não perder o acesso")!==false && strpos($t,"removido")===false'
secao "G6.3 Pix do Fluxo Guiado: QR Code em foto e botão Copiar"
COPIA='count(array_filter(tg("sendMessage"), fn($e) => count(array_filter(botoes($e), fn($b) => isset($b["copy_text"])))>0))'
compra() { janela; reset; setflow guiado_base "$1"; msg "/start" Maria >/dev/null; mark; cb "basico::plano::p_mensal" >/dev/null; }
compra '{"pagamentos":{"mostrar_copiar":true,"mostrar_qrcode":false}}'
chk "fluxo antigo (mostrar_qrcode=false gravado pelo editor antigo, sem escolha): manda o QR Code em foto" 'count(tg("sendPhoto"))==1 && strpos(txt(tg("sendPhoto")[0]),"QR Code")!==false && isset(tg("sendPhoto")[0]["arquivos"]["photo"])'
chk "botão 📋 Copiar código Pix presente quando mostrar_copiar=true" "$COPIA"'==1'
compra '{"pagamentos":{"mostrar_copiar":false,"mostrar_qrcode":false,"qrcode_definido":true}}'
chk "QR desligado de propósito no editor novo (qrcode_definido): sem foto; e sem botão de copiar quando desligado" 'count(tg("sendPhoto"))==0'" && $COPIA"'==0'
compra '{"pagamentos":{"mostrar_copiar":true,"mostrar_qrcode":true,"qrcode_definido":true}}'
chk "QR ligado no editor novo: manda a foto do QR e o botão copiar" 'count(tg("sendPhoto"))==1'" && $COPIA"'==1'
semfalha
echo; echo "FIM G6"
