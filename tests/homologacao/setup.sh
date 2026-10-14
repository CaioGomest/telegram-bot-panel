#!/bin/bash
# Prepara /tmp/pt/app (cópia do painel do servidor) com o simulador aplicado.
set -e
# Na hospedagem de homologação o painel fica nesta pasta; na VPS use PAINEL_REAL=/var/www/painel
REAL=${PAINEL_REAL:-$HOME/domains/stackcode.com.br/public_html/telegram}
rm -rf /tmp/pt/app
mkdir -p /tmp/pt/app /tmp/pt/tmp
cd "$REAL"
tar cf - --exclude='./uploads' --exclude='./logs' --exclude='*.bak-*' --exclude='./storage' . | (cd /tmp/pt/app && tar xf -)
mkdir -p /tmp/pt/app/uploads /tmp/pt/app/logs /tmp/pt/app/storage
echo '{}' > /tmp/pt/app/storage/cache_midia.json
touch /tmp/pt/sim.log /tmp/pt/resultados.txt /tmp/pt/php_error.log /tmp/pt/php_stderr.log
echo 0 > /tmp/pt/marca
# o isolamento das crons precisa do bot de teste: cria usuario/bot de teste antes de aplicar o patch
[ -f /tmp/pt/ids.json ] || php /tmp/pt/seed.php
php /tmp/pt/patch.php
du -sh /tmp/pt/app
