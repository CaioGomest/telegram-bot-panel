#!/usr/bin/env bash
# =====================================================================================================================
# 04_limpar_arquivos.sh — apaga os ARQUIVOS de teste do site. Exige a frase RESETAR TUDO.
#
#   bash 04_limpar_arquivos.sh --frase "RESETAR TUDO"       (ou digite a frase quando pedir)
#   bash 04_limpar_arquivos.sh --simular                    (só lista o que apagaria)
#
# Funciona por LISTA DO QUE APAGAR (nunca "apaga tudo menos…"): só some arquivo com nome de um tipo conhecido de conteúdo de usuário.
# Qualquer outro arquivo é só listado no fim, para você olhar — não é tocado.
#
#   APAGA em uploads/:  bot_avatar_*  flow_image_*  flow_video_*  flow_audio_*  remarketing_foto_*  remarketing_video_*
#                       perfis/ (fotos de perfil)  stories/  e campanha_banner_* (só se RANKING=apagar)
#   APAGA em storage/:  cache/  cache_midia.json  omegapayments_429_*.txt  (cache e travas de teste)
#   APAGA em certificados/ e em /var/lib/php/painel-sessoes/ (sessões: desloga todo mundo — se LIMPAR_SESSOES=sim)
#   ZERA (sem apagar o arquivo) os logs em logs/*.log (menos controle.log) e em /var/log/php/*.log — cópia já está no backup (01)
#
#   NUNCA TOCA: uploads/marca_logo* e uploads/marca_favicon* (identidade visual), storage/controle_estado.json e
#   storage/controle_chave.json (suspensão remota), config.php, .htaccess, .gitkeep, index.html, logs/controle.log.
# =====================================================================================================================
. "$(dirname "$0")/lib.sh"
ler_args "$@"
precisa_root
carregar_sistema
carregar_config
iniciar_log limpar_arquivos

(( SIMULAR )) || { [[ -f "$CRON_PAUSADO" && ! -f "$CRON_ATIVO" ]] || die "Os crons não estão pausados. Rode antes:  bash 02_pausar.sh"; exigir_frase; }

apagar() {   # apagar <descrição> <comando find completo sem -delete…>
  local desc="$1"; shift
  local n; n="$("$@" -type f -print 2>/dev/null | wc -l)" || n=0
  if (( SIMULAR )); then printf '  [simulação] %-52s %s arquivo(s)\n' "$desc" "$n"; return 0; fi
  "$@" -type f -delete 2>/dev/null || true
  printf '  %-60s %s apagado(s)\n' "$desc" "$n"
}
U="$SITE_DIR/uploads"; S="$SITE_DIR/storage"

titulo "1. uploads/ — mídia de usuários"
for p in 'bot_avatar_*' 'flow_image_*' 'flow_video_*' 'flow_audio_*' 'remarketing_foto_*' 'remarketing_video_*'; do
  apagar "uploads/$p" find "$U" -maxdepth 1 -name "$p"
done
[[ -d "$U/perfis"  ]] && apagar "uploads/perfis/ (fotos de perfil)" find "$U/perfis"  -mindepth 1 ! -name '.htaccess' ! -name '.gitkeep' ! -name 'index.html'
[[ -d "$U/stories" ]] && apagar "uploads/stories/"                  find "$U/stories" -mindepth 1 ! -name '.htaccess' ! -name '.gitkeep' ! -name 'index.html'
if [[ "$RANKING" == "apagar" ]]; then apagar "uploads/campanha_banner_* (RANKING=apagar)" find "$U" -maxdepth 1 -name 'campanha_banner_*'
else echo "  uploads/campanha_banner_*  mantido (RANKING=manter)"; fi

titulo "2. storage/ — cache e travas"
[[ -d "$S/cache" ]] && apagar "storage/cache/" find "$S/cache" -mindepth 1 ! -name '.htaccess' ! -name '.gitkeep'
apagar "storage/cache_midia.json" find "$S" -maxdepth 1 -name 'cache_midia.json'
apagar "storage/omegapayments_429_*.txt" find "$S" -maxdepth 1 -name 'omegapayments_429_*.txt'

titulo "3. certificados/ e sessões"
[[ -d "$SITE_DIR/certificados" ]] && apagar "certificados/ (certificados de gateways de teste)" find "$SITE_DIR/certificados" -mindepth 1 ! -name '.htaccess' ! -name '.gitkeep' ! -name 'index.html'
if [[ "$LIMPAR_SESSOES" == "sim" ]]; then
  [[ -d /var/lib/php/painel-sessoes ]] && apagar "sessões do PHP (todo mundo é deslogado)" find /var/lib/php/painel-sessoes -maxdepth 1 -name 'sess_*'
else echo "  sessões mantidas (LIMPAR_SESSOES=nao)"; fi

titulo "4. logs (zerados, não apagados)"
zerar() {
  local f="$1"; [[ -f "$f" ]] || return 0
  if (( SIMULAR )); then printf '  [simulação] zeraria %s (%s)\n' "$f" "$(du -h "$f" | cut -f1)"; else : > "$f"; printf '  zerado %s\n' "$f"; fi
}
for f in "$SITE_DIR"/logs/*.log; do [[ "$(basename "$f")" == "controle.log" ]] && { echo "  mantido logs/controle.log (registro da suspensão remota)"; continue; }; zerar "$f"; done
for f in /var/log/php/*.log; do zerar "$f"; done

titulo "5. O que NÃO foi tocado — para você olhar"
echo "  uploads/ (arquivos que não são de um tipo conhecido; marca_* é a identidade visual e fica):"
find "$U" -maxdepth 1 -type f ! -name '.htaccess' ! -name '.gitkeep' ! -name 'index.html' ! -name 'marca_logo*' ! -name 'marca_favicon*' ! -name 'campanha_banner_*' 2>/dev/null | head -20 | sed 's/^/    /'
echo "  storage/ (o que sobrou):"
find "$S" -maxdepth 1 -type f ! -name '.htaccess' ! -name '.gitkeep' 2>/dev/null | sed 's/^/    /'
for f in storage/controle_estado.json storage/controle_chave.json; do [[ -f "$SITE_DIR/$f" ]] && ok "$f intacto" || aviso "$f não existe"; done
ls "$U"/marca_logo* >/dev/null 2>&1 && ok "logo da marca intacto" || aviso "não há logo da marca em uploads/ (defina em Admin → Configurações → Identidade)"
echo
log "limpeza de arquivos concluída. Próximo: 05_configurar_gateway.sh"
