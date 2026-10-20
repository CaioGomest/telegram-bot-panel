#!/usr/bin/env bash
# =====================================================================================================================
# 02_pausar.sh — para as rotinas automáticas e tira da VPS as sobras do teste de carga. Exige a frase RESETAR TUDO.
#
#   bash 02_pausar.sh --frase "RESETAR TUDO"          (ou digite a frase quando pedir)
#   bash 02_pausar.sh --simular                       (só mostra o que faria)
#
# Por quê: se os crons rodassem DURANTE o reset, poderiam gravar vendas/leads/e-mails no meio da limpeza (ou consultar a OmegaPayments
# de verdade). O cron é pausado do mesmo jeito que o painel-restaurar faz (mv /etc/cron.d/painel → /root/painel-cron.pausado),
# e o 07_retomar.sh devolve.
# =====================================================================================================================
. "$(dirname "$0")/lib.sh"
ler_args "$@"
precisa_root
carregar_sistema
iniciar_log pausar

(( SIMULAR )) || exigir_frase

titulo "1. Pausar os crons"
if [[ -f "$CRON_PAUSADO" ]]; then
  aviso "$CRON_PAUSADO já existe — os crons já estavam pausados (não vou sobrescrever)."
elif [[ -f "$CRON_ATIVO" ]]; then
  if (( SIMULAR )); then log "[simulação] mv $CRON_ATIVO $CRON_PAUSADO"; else mv "$CRON_ATIVO" "$CRON_PAUSADO"; ok "crons pausados (arquivo guardado em $CRON_PAUSADO)"; fi
else
  die "Não achei $CRON_ATIVO nem $CRON_PAUSADO — o painel está instalado direito?"
fi

titulo "2. Esperar as rotinas em andamento terminarem"
if (( SIMULAR )); then log "[simulação] esperaria até 90 s os processos cron/cron_*.php"; else
  for i in $(seq 1 18); do
    n="$(crons_rodando)"
    (( n == 0 )) && break
    log "ainda há $n rotina(s) rodando… (${i}/18)"; sleep 5
  done
  n="$(crons_rodando)"
  (( n == 0 )) && ok "nenhuma rotina rodando" || die "Ainda há $n rotina(s) rodando depois de 90 s. Veja: pgrep -af 'cron/cron_'. Nada foi apagado; o cron continua pausado (07_retomar.sh devolve)."
fi

titulo "3. Sobras do teste de carga (/root/estresse, site e pool 'estresse')"
if [[ -x /root/estresse/desmontar.sh ]]; then
  log "encontrei /root/estresse/desmontar.sh (script do projeto: tira a cópia de teste, o pool e o site da porta 8080)"
  if (( SIMULAR )); then log "[simulação] bash /root/estresse/desmontar.sh"; else bash /root/estresse/desmontar.sh && ok "teste de carga desmontado"; fi
else
  ok "não há /root/estresse/desmontar.sh (nada a desmontar por aqui)"
fi
for p in /root/res /root/estresse; do
  if [[ -e "$p" ]]; then
    if (( SIMULAR )); then log "[simulação] rm -rf $p"; else rm -rf "$p" && ok "removido $p"; fi
  fi
done
echo
log "pausa concluída."
