#!/usr/bin/env bash
# =====================================================================================================================
# 05_configurar_gateway.sh — deixa a gateway OmegaPayments pronta para o lançamento (liberada para os usuários + split da plataforma).
# Exige a frase RESETAR TUDO (muda configuração de verdade). Os valores vêm de config.env — nunca digitados aqui.
#
#   bash 05_configurar_gateway.sh --frase "RESETAR TUDO"      (ou digite a frase)
#   bash 05_configurar_gateway.sh --simular                   (mostra o que mudaria)
#
#   GATEWAY_OMEGA=ativar   → gateways.ativo=1 (os vendedores passam a ver a OmegaPayments em "Gateways"). Com 'manter' não mexe.
#   SPLIT_APLICAR=sim      → grava SPLIT_TIPO, SPLIT_VALOR e SPLIT_ID_RECEBEDOR em gateways (o que Admin → Gateways grava).
#
# COMO O SPLIT FUNCIONA (conferido em funcoes/omegapayments_banco.php, montaSplits):
#   fixo       = esse valor, em reais, POR COMPRA (cada Pix pago), qualquer que seja o preço (limitado ao valor da venda).
#   percentual = esse % SOBRE O VALOR de cada venda (arredondado em centavos).
#   A Omega ainda cobra a tarifa dela (R$ 0,70 por Pix). O preço mínimo de um plano = split + tarifa (fixo) — veja a conta no fim.
#   SPLIT_ID_RECEBEDOR é o "producerId" (identificador do recebedor na Omega) — NÃO é chave Pix.
# =====================================================================================================================
. "$(dirname "$0")/lib.sh"
ler_args "$@"
precisa_root
carregar_sistema
carregar_config
iniciar_log gateway

mascara() { local v="$1"; (( ${#v} <= 6 )) && printf '***' || printf '%s…%s (%s caracteres)' "${v:0:3}" "${v: -2}" "${#v}"; }
mostrar() {
  sql "SELECT CONCAT('  ', nome, ' | ativo=', ativo, ' | split ', tipo_split, ' ', taxa_split, ' | recebedor: ', IF(chave_pix_split IS NULL OR chave_pix_split='', 'VAZIO', CONCAT(LEFT(chave_pix_split,3), '… (', CHAR_LENGTH(chave_pix_split), ' caracteres)'))) FROM gateways WHERE nome='omegapayments'"
}

titulo "1. Estado atual"
[[ "$(sql "SELECT COUNT(*) FROM gateways WHERE nome='omegapayments'")" == "1" ]] || die "A linha da gateway 'omegapayments' não existe na tabela gateways (rode a atualização do banco do painel)."
mostrar

(( SIMULAR )) || { exigir_frase; }

titulo "2. Aplicar"
if [[ "$GATEWAY_OMEGA" == "ativar" ]]; then
  if (( SIMULAR )); then log "[simulação] UPDATE gateways SET ativo=1 WHERE nome='omegapayments'"; else sql "UPDATE gateways SET ativo=1 WHERE nome='omegapayments'"; ok "OmegaPayments liberada para os usuários"; fi
else echo "  GATEWAY_OMEGA=manter — ativo não foi mexido"; fi

if [[ "$SPLIT_APLICAR" == "sim" ]]; then
  echo "  split a gravar: $SPLIT_TIPO $SPLIT_VALOR | recebedor $(mascara "$SPLIT_ID_RECEBEDOR")"
  if (( SIMULAR )); then log "[simulação] UPDATE gateways SET tipo_split='$SPLIT_TIPO', taxa_split=$SPLIT_VALOR, chave_pix_split=<recebedor> WHERE nome='omegapayments'"
  else
    sql "UPDATE gateways SET tipo_split='${SPLIT_TIPO}', taxa_split=${SPLIT_VALOR}, chave_pix_split='${SPLIT_ID_RECEBEDOR}' WHERE nome='omegapayments'"
    ok "split gravado"
  fi
else echo "  SPLIT_APLICAR=nao — split NÃO foi mexido (continua o que está acima)"; fi

titulo "3. Resultado"
mostrar
MIN="$(runuser -u www-data -- env SITE_DIR="$SITE_DIR" php -r 'chdir(getenv("SITE_DIR")); require "conexao.php"; require "funcoes/gateways.php"; echo number_format(precoMinimoOmegaPayments(getGatewaySplit("omegapayments")), 2, ",", ".");' 2>/dev/null || true)"
if [[ -n "$MIN" ]]; then log "preço mínimo de um plano com esse split (calculado pelo próprio painel): R\$ $MIN"
else aviso "não consegui pedir o preço mínimo ao painel; confira em Admin → Gateways (ou na tela de Fluxos ao salvar um plano)"; fi
SPLIT_ATUAL="$(sql "SELECT CONCAT(tipo_split, ' ', taxa_split) FROM gateways WHERE nome='omegapayments'")"
RECEB_VAZIO="$(sql "SELECT (chave_pix_split IS NULL OR chave_pix_split='') FROM gateways WHERE nome='omegapayments'")"
[[ "$RECEB_VAZIO" == "1" ]] && aviso "o split está SEM recebedor: o painel ignora o split nesse caso (a plataforma não ganha nada por venda)."
[[ "$(sql "SELECT ativo FROM gateways WHERE nome='omegapayments'")" == "1" ]] || aviso "a OmegaPayments está DESLIGADA: os vendedores não conseguem configurá-la."
echo
log "gateway conferida (split atual: $SPLIT_ATUAL). Próximo: 06_senha_admin.sh (interativo) e 07_retomar.sh"
