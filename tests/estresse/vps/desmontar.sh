#!/usr/bin/env bash
# desmontar.sh — roda NA VPS (como root): remove a cópia de teste do painel, o pool e o site do teste de carga (e opcionalmente as ferramentas).
# NÃO apaga os dados de teste do banco (isso é o "zerar", feito só quando o dono mandar: ver tests/estresse/vps/README.md) nem o /root/estado-zero.
#   bash desmontar.sh [--ferramentas]
set -uo pipefail
(( EUID == 0 )) || { echo "Rode como root." >&2; exit 1; }
PHPV="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
a2disconf -q zz-estresse 2>/dev/null; rm -f /etc/apache2/conf-available/zz-estresse.conf
rm -f "/etc/php/$PHPV/fpm/pool.d/estresse.conf"
systemctl reload "php${PHPV}-fpm"; systemctl reload apache2
rm -rf /var/www/painel-estresse /opt/estresse /var/lib/php/estresse-sessoes /var/log/php/estresse-*.log /dev/shm/est
[[ "${1:-}" == "--ferramentas" ]] && DEBIAN_FRONTEND=noninteractive apt-get remove -y -qq wrk apache2-utils sysstat >/dev/null 2>&1
echo "ambiente de teste desmontado (dados de teste do banco e /root/estado-zero mantidos)"
