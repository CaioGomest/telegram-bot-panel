#!/usr/bin/env bash
# =====================================================================================================================
# 07_retomar.sh — religa as rotinas automáticas (crons) e recarrega o PHP-FPM e o Apache. Não apaga nada; não exige a frase.
#
#   bash 07_retomar.sh
#
# Deixe para rodar DEPOIS do 03 (banco zerado), do 04 (arquivos) e do 05 (gateway/split): assim nenhuma rotina enxerga dados de teste
# nem processa e-mails antigos. Faz o contrário do 02_pausar.sh.
# =====================================================================================================================
. "$(dirname "$0")/lib.sh"
precisa_root
carregar_sistema
iniciar_log retomar

titulo "1. Crons"
if [[ -f "$CRON_ATIVO" && ! -f "$CRON_PAUSADO" ]]; then
  ok "crons já estão ativos em $CRON_ATIVO"
elif [[ -f "$CRON_PAUSADO" && ! -f "$CRON_ATIVO" ]]; then
  mv "$CRON_PAUSADO" "$CRON_ATIVO"; chmod 644 "$CRON_ATIVO"; ok "crons religados ($CRON_ATIVO)"
elif [[ -f "$CRON_PAUSADO" && -f "$CRON_ATIVO" ]]; then
  die "Existem os DOIS arquivos ($CRON_ATIVO e $CRON_PAUSADO). Compare com diff e remova o que estiver sobrando; não mexi em nada."
else
  die "Não achei nenhum arquivo de cron do painel. A instalação está completa?"
fi
systemctl is-active --quiet cron && ok "serviço cron ativo" || { systemctl restart cron && ok "serviço cron reiniciado"; }

titulo "2. Recarregar PHP-FPM e Apache (limpa o opcache)"
FPM="$(servico_fpm)"
if [[ -n "$FPM" ]]; then
  # valida a configuração ANTES de recarregar (config inválida já derrubou o PHP-FPM uma vez)
  VER="$(echo "$FPM" | sed -E 's/^php([0-9.]+)-fpm.*/\1/')"   # serviço php8.5-fpm → binário php-fpm8.5
  BIN="php-fpm${VER}"
  if command -v "$BIN" >/dev/null 2>&1; then
    "$BIN" -t >/dev/null 2>&1 || die "Configuração do $BIN inválida — NÃO recarreguei. Rode '$BIN -t' para ver o erro."
    ok "configuração do $BIN válida"
  else aviso "não achei o binário $BIN para validar a configuração; recarregando mesmo assim"; fi
  systemctl reload "$FPM" && ok "$FPM recarregado"
fi
apachectl configtest >/dev/null 2>&1 || die "Configuração do Apache inválida — NÃO recarreguei. Rode 'apachectl configtest'."
systemctl reload apache2 && ok "apache2 recarregado"

titulo "3. Estado"
for s in apache2 mariadb cron "$FPM"; do [[ -n "$s" ]] && printf '  %-22s %s\n' "$s" "$(systemctl is-active "$s" 2>/dev/null || true)"; done
echo
log "rotinas religadas. Próximo: 08_verificar.sh"
