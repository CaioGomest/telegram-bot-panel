#!/usr/bin/env bash
# setpool.sh MAX INICIAIS — muda o tamanho do pool PHP-FPM 'estresse' COM SEGURANÇA: ajusta os 4 parâmetros coerentes (start entre min e max spare, max ≥ start),
# VALIDA a configuração (php-fpm -t) antes de recarregar e, se ela for inválida, devolve a original (nunca derruba o PHP-FPM — e com ele o painel).
#   setpool.sh 150 50      setpool.sh restaurar
set -uo pipefail
POOL=$(ls /etc/php/*/fpm/pool.d/estresse.conf | head -1)
PHPV=$(echo "$POOL" | sed -E 's#/etc/php/([0-9.]+)/.*#\1#')
ORIG=/root/res/estresse_pool_original.conf
FPM="php${PHPV}-fpm"
mkdir -p /root/res
if [[ "${1:-}" == "restaurar" ]]; then
  [[ -f "$ORIG" ]] && cp "$ORIG" "$POOL"
  "php-fpm${PHPV}" -t >/dev/null 2>&1 && systemctl reload "$FPM" && echo "pool restaurado"
  exit 0
fi
MAXF="$1"; INI="${2:-$((MAXF/3))}"
[[ -f "$ORIG" ]] || cp "$POOL" "$ORIG"
MINS=$((INI/2)); (( MINS < 2 )) && MINS=2
MAXS=$((INI*2)); (( MAXS > MAXF )) && MAXS=$MAXF
(( INI > MAXS )) && INI=$MAXS
cp "$POOL" /tmp/pool_antes.conf
sed -i -E "s/^pm.max_children *=.*/pm.max_children = $MAXF/; s/^pm.start_servers *=.*/pm.start_servers = $INI/; s/^pm.min_spare_servers *=.*/pm.min_spare_servers = $MINS/; s/^pm.max_spare_servers *=.*/pm.max_spare_servers = $MAXS/" "$POOL"
if ! "php-fpm${PHPV}" -t >/dev/null 2>&1; then
  echo "CONFIGURAÇÃO INVÁLIDA — devolvendo a anterior" >&2
  cp /tmp/pool_antes.conf "$POOL"
  exit 1
fi
systemctl reload "$FPM" || { cp /tmp/pool_antes.conf "$POOL"; systemctl start "$FPM"; exit 1; }
sleep 3
systemctl is-active --quiet "$FPM" && echo "pool estresse: max=$MAXF inicial=$INI min_spare=$MINS max_spare=$MAXS"
