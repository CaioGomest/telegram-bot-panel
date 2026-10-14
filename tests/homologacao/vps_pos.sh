#!/bin/bash
# Desfaz o que vps_pre.sh fez: gateway como estava, "outro usuário" apagado (leva fluxo e bot junto), crons religadas.
# Rode DEPOIS de all.sh e de  php /tmp/pt/cleanup.php.
BANCO="${BANCO_NOME:-painel}"
if [ -s /tmp/pt/gateway_antes.txt ]; then
  read -r ATIVO TAXA TIPO CHAVE < <(tr '\t' ' ' < /tmp/pt/gateway_antes.txt)
  mysql "$BANCO" -e "UPDATE gateways SET ativo = $ATIVO, taxa_split = $TAXA, tipo_split = '$TIPO', chave_pix_split = $( [ -n "$CHAVE" ] && echo "'$CHAVE'" || echo NULL ) WHERE nome = 'omegapayments'"
fi
mysql "$BANCO" -e "DELETE FROM usuarios WHERE email = 'outro-usuario-vps@example.invalid'"
[ -f /root/painel-cron.pausado ] && mv /root/painel-cron.pausado /etc/cron.d/painel
echo "VPS de volta ao estado de instalação (gateway original, usuário de teste removido, crons religadas)"
mysql "$BANCO" -N -e "SELECT CONCAT('usuarios: ', COUNT(*)) FROM usuarios; SELECT CONCAT('bots: ', COUNT(*)) FROM bots; SELECT CONCAT('gateway ativo=', ativo, ' split=', taxa_split, ' ', tipo_split) FROM gateways WHERE nome='omegapayments'"
