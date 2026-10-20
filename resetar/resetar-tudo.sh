#!/usr/bin/env bash
# =====================================================================================================================
# resetar-tudo.sh — roda o reset COMPLETO, na ordem certa, com UMA confirmação. Só use quando o dono mandar "RESETAR TUDO".
#
#   bash resetar-tudo.sh --frase "RESETAR TUDO"       (ou digite a frase quando pedir)
#   bash resetar-tudo.sh --simular                    (ensaio: mostra o que cada passo faria, sem mudar nada e sem backup)
#
# Ordem:  00 conferir → 01 backup → 02 pausar → 03 zerar banco → 04 limpar arquivos → 05 gateway/split → 06 senha do admin
#         → 07 retomar → 08 verificar → 09 marco zero.
# Para no primeiro erro (e diz como voltar). O passo 06 precisa de terminal (a senha é digitada); sem terminal ele fica PENDENTE
# e o 08 acusa "NÃO PRONTO" até você rodar  bash 06_senha_admin.sh  e depois  bash 08_verificar.sh  e  bash 09_marco_zero.sh.
# Antes: preencha config.env (cp config.exemplo.env config.env) e leia PASSO-A-PASSO.md.
# =====================================================================================================================
. "$(dirname "$0")/lib.sh"
ler_args "$@"
precisa_root
carregar_sistema
carregar_config
iniciar_log tudo

trap 'falha "O reset parou no meio (linha $LINENO). Os crons podem estar PAUSADOS ($CRON_PAUSADO). Veja o log $LOG_ARQ. Para voltar ao estado de antes: bash $AQUI/desfazer.sh"' ERR

titulo "RESET COMPLETO — $(hostname) — banco '$BANCO_NOME' — site '$SITE_DIR'"
echo "  Vai APAGAR todos os dados de teste (usuários que não são admin, bots, fluxos, leads, vendas, remarketing, webhooks, e-mails na fila,"
echo "  uploads de usuários, logs). Mantém: admin(s) $MANTER_ADMINS, gateways e split, configurações (marca, SMTP, domínio), logo e a suspensão remota."
echo "  Gateway Omega: $GATEWAY_OMEGA | split: $( [[ "$SPLIT_APLICAR" == sim ]] && echo "$SPLIT_TIPO $SPLIT_VALOR (recebedor informado)" || echo "não mexer" ) | ranking: $RANKING | comunidade: $COMUNIDADE"
echo

if (( SIMULAR )); then
  bash "$AQUI/00_conferir.sh"
  bash "$AQUI/02_pausar.sh" --simular
  bash "$AQUI/03_zerar_banco.sh" --simular
  bash "$AQUI/04_limpar_arquivos.sh" --simular
  bash "$AQUI/05_configurar_gateway.sh" --simular
  echo; log "ensaio terminado — nada foi alterado."
  exit 0
fi

exigir_frase
F=(--frase "$FRASE_RESET")

bash "$AQUI/00_conferir.sh"
bash "$AQUI/01_backup_antes.sh"
bash "$AQUI/02_pausar.sh" "${F[@]}"
bash "$AQUI/03_zerar_banco.sh" "${F[@]}"
bash "$AQUI/04_limpar_arquivos.sh" "${F[@]}"
bash "$AQUI/05_configurar_gateway.sh" "${F[@]}"

titulo "06 — senha do administrador"
if [[ "${TROCAR_SENHA_ADMIN:-sim}" == "sim" ]]; then
  if [[ -t 0 ]]; then bash "$AQUI/06_senha_admin.sh"
  else aviso "PENDENTE: sem terminal aqui. Rode depois, num terminal:  bash $AQUI/06_senha_admin.sh"; fi
else aviso "TROCAR_SENHA_ADMIN=nao — a senha do admin NÃO foi trocada (na VPS ela está trivial desde os testes)"; fi

bash "$AQUI/07_retomar.sh"
if bash "$AQUI/08_verificar.sh"; then
  bash "$AQUI/09_marco_zero.sh"
  echo; ok "RESET CONCLUÍDO E VERIFICADO. Siga a fase 6 do PASSO-A-PASSO.md (primeiro acesso e aceite)."
else
  echo; falha "Reset aplicado, mas a verificação acusou pendências (acima). Corrija e rode:  bash $AQUI/08_verificar.sh  e depois  bash $AQUI/09_marco_zero.sh"
  exit 1
fi
