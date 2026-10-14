#!/usr/bin/env bash
# =====================================================================================================================
# preparar.sh — roda NA VPS (como root): monta o ambiente do teste de carga pesado. Idempotente.
#
#   1. guarda o ESTADO ZERO (backup do banco e dos arquivos) em /root/estado-zero — é para onde "deixar zero" volta
#   2. instala as ferramentas de medida (wrk, sysstat, apache2-utils)
#   3. cria a CÓPIA DE TESTE do painel (/var/www/painel-estresse, mesmo banco) com Telegram e OmegaPayments simulados,
#      um pool próprio do PHP-FPM (igual ao do painel, mais o simulador) e um site só em 127.0.0.1:8080 (não aparece na internet)
#
# O painel de verdade (/var/www/painel) não é alterado. Para desmontar tudo: bash desmontar.sh
# =====================================================================================================================
set -Eeuo pipefail
trap 'echo "[ERRO] falhou na linha $LINENO: $BASH_COMMAND" >&2' ERR
(( EUID == 0 )) || { echo "Rode como root." >&2; exit 1; }
AQUI="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SITE=/var/www/painel
COPIA=/var/www/painel-estresse
PHPV="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"

echo "== 1/5 estado zero"
if [[ ! -s /root/estado-zero/banco.sql.gz ]]; then
  mkdir -p /root/estado-zero && chmod 700 /root/estado-zero
  mysqldump --single-transaction --quick --routines --triggers --events painel | gzip -9 > /root/estado-zero/banco.sql.gz
  tar czf /root/estado-zero/arquivos.tar.gz -C "$SITE" config.php uploads certificados storage 2>/dev/null || true
  date '+%F %T' > /root/estado-zero/criado_em.txt
  echo "   estado zero guardado: $(du -h /root/estado-zero/banco.sql.gz | cut -f1) de banco  ($(cat /root/estado-zero/criado_em.txt))"
else
  echo "   já existe /root/estado-zero ($(cat /root/estado-zero/criado_em.txt)) — mantido"
fi

echo "== 2/5 ferramentas"
DEBIAN_FRONTEND=noninteractive apt-get install -y -qq wrk sysstat apache2-utils >/dev/null 2>&1 || apt-get install -y wrk sysstat apache2-utils
sed -i 's/^ENABLED="false"/ENABLED="true"/' /etc/default/sysstat 2>/dev/null || true
echo "   wrk: $(wrk --version 2>&1 | head -1)"

echo "== 3/5 cópia de teste do painel"
mkdir -p "$COPIA" /opt/estresse
rsync -a --delete --exclude='/config.php' --exclude='/uploads/*' --exclude='/logs/*' --exclude='/storage/*' --exclude='/certificados/*' "$SITE"/ "$COPIA"/
install -m 0640 -o root -g www-data "$SITE/config.php" "$COPIA/config.php"
for d in uploads logs storage certificados; do
  mkdir -p "$COPIA/$d"; [[ -f "$SITE/$d/.htaccess" ]] && install -m 0644 "$SITE/$d/.htaccess" "$COPIA/$d/.htaccess"
done
mkdir -p "$COPIA/storage/cache"
echo '{}' > "$COPIA/storage/cache_midia.json"
php "$AQUI/patch_estresse.php" "$COPIA" | tail -2
install -m 0644 "$AQUI/sim_rapido.php" /opt/estresse/sim_rapido.php
chown -R root:www-data "$COPIA"; find "$COPIA" -type d -exec chmod 750 {} +; find "$COPIA" -type f -exec chmod 640 {} +
for d in uploads logs storage certificados; do chown -R www-data:www-data "$COPIA/$d"; done
mkdir -p /var/lib/php/estresse-sessoes && chown www-data:www-data /var/lib/php/estresse-sessoes && chmod 750 /var/lib/php/estresse-sessoes
touch /var/log/php/estresse-erros.log && chown www-data:www-data /var/log/php/estresse-erros.log

echo "== 4/5 pool PHP-FPM 'estresse' (igual ao 'painel' + simulador)"
sed -e 's/^\[painel\]/[estresse]/' -e 's#/run/php/painel.sock#/run/php/estresse.sock#' \
    -e 's#/var/log/php/painel-erros.log#/var/log/php/estresse-erros.log#' -e 's#/var/log/php/painel-lento.log#/var/log/php/estresse-lento.log#' \
    -e 's#/var/lib/php/painel-sessoes#/var/lib/php/estresse-sessoes#' "/etc/php/$PHPV/fpm/pool.d/painel.conf" > "/etc/php/$PHPV/fpm/pool.d/estresse.conf"
grep -q 'auto_prepend_file' "/etc/php/$PHPV/fpm/pool.d/estresse.conf" || echo 'php_admin_value[auto_prepend_file] = /opt/estresse/sim_rapido.php' >> "/etc/php/$PHPV/fpm/pool.d/estresse.conf"
systemctl reload "php${PHPV}-fpm"

echo "== 5/5 site de teste em 127.0.0.1:8080 (só local)"
cat > /etc/apache2/conf-available/zz-estresse.conf <<EOF
Listen 127.0.0.1:8080
<VirtualHost 127.0.0.1:8080>
    ServerName estresse.local
    KeepAliveTimeout 120
    MaxKeepAliveRequests 0
    DocumentRoot $COPIA
    DirectoryIndex index.php
    <Directory $COPIA>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    <FilesMatch "\.php\$">
        SetHandler "proxy:unix:/run/php/estresse.sock|fcgi://localhost"
    </FilesMatch>
    ErrorLog \${APACHE_LOG_DIR}/estresse-error.log
    CustomLog /dev/null combined
</VirtualHost>
EOF
a2enconf -q zz-estresse
apache2ctl configtest 2>&1 | tail -1
systemctl reload apache2

mkdir -p /dev/shm/est/pagos
[[ -f /dev/shm/est/lat.json ]] || echo '{"tg":150,"gw":700,"gw_consulta":250}' > /dev/shm/est/lat.json
chmod -R 777 /dev/shm/est
echo "== pronto"
echo "   teste rápido: curl -s -o /dev/null -w '%{http_code}\n' -X POST 'http://127.0.0.1:8080/webhook.php?token=nada'  → 200 ('bot não encontrado')"
curl -s -w ' [%{http_code}]\n' -X POST 'http://127.0.0.1:8080/webhook.php?token=nada'
