#!/usr/bin/env bash
# fase_admin_crons.sh RÓTULO — (1) telas do ADMINISTRADOR uma a uma e (2) tempo das ROTINAS (cron) com o volume de dados atual.
# As crons rodam a CÓPIA de teste (Telegram/gateway simulados) como www-data, em linha de comando, exatamente como o /etc/cron.d/painel as chama.
set -uo pipefail
ulimit -n 100000
R="$1"
cd /root/estresse
export WRK_COOKIES=/root/res/cookie_admin.txt
: > "/root/res/${R}_admin_paginas.txt"
for P in /admin/index /admin/usuarios /admin/transacoes /admin/logs /admin/configuracoes /admin/ranking /admin/comunidade /admin/consultar_venda /ranking; do
  WRK_PATH="$P" WRK_THINK=0 wrk -t1 -c2 -d8s --latency -s /root/estresse/paginas.lua http://127.0.0.1:8080 > /tmp/pa.out 2>&1
  printf '%-26s %s | %s | %s\n' "$P" "$(grep -E 'Requests/sec' /tmp/pa.out | awk '{print $2" req/s"}')" "$(grep -E '^\s+50%|^\s+99%' /tmp/pa.out | tr -s ' ' | tr '\n' ' ')" "$(grep -E 'HTTP ' /tmp/pa.out | tr -s ' ' | tr '\n' ' ')" >> "/root/res/${R}_admin_paginas.txt"
  sleep 2
done
: > "/root/res/${R}_crons.txt"
for CR in cron_verificar_pix cron_verificar_acessos cron_aviso_vencimento cron_ranking cron_remarketing cron_metricas_admin cron_renovacao cron_limpar_stories cron_limpar_remarketing_envios; do
  for I in 1 2; do
    /usr/bin/time -f "%e s  %M KB" -o /tmp/cron_t.txt runuser -u www-data -- php -d auto_prepend_file=/opt/estresse/sim_rapido.php /var/www/painel-estresse/cron/$CR.php > /tmp/cron_o.txt 2>&1
    printf '%-34s exec %s: %s | %s\n' "$CR" "$I" "$(tail -1 /tmp/cron_t.txt)" "$(head -c 120 /tmp/cron_o.txt | tr '\n' ' ')" >> "/root/res/${R}_crons.txt"
  done
done
echo pronto > "/root/res/${R}_admin_crons_FIM"
