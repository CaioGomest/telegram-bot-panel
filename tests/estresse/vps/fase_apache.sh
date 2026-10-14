#!/usr/bin/env bash
# fase_apache.sh RÓTULO — repete o cenário combinado (funil + usuários no painel, COM as correções índice+cache do ranking) com o Apache aceitando MAIS requisições
# ao mesmo tempo (MaxRequestWorkers 200 → 600). Usa um arquivo temporário (zz-teste-mpm.conf), valida (configtest) antes de recarregar e o remove no fim.
set -uo pipefail
ulimit -n 100000
R="$1"
cd /root/estresse
cat > /etc/apache2/conf-available/zz-teste-mpm.conf <<'EOF'
<IfModule mpm_event_module>
    ServerLimit 24
    MaxRequestWorkers 600
</IfModule>
EOF
a2enconf -q zz-teste-mpm
if ! apache2ctl configtest >/dev/null 2>&1; then echo "configtest falhou — desfazendo" >&2; a2disconf -q zz-teste-mpm; rm -f /etc/apache2/conf-available/zz-teste-mpm.conf; exit 1; fi
systemctl restart apache2; sleep 3
apache2ctl -t -D DUMP_RUN_CFG 2>/dev/null | grep -iE "MaxRequestWorkers|ServerLimit" | tr '\n' ' '; echo
mysql painel -e "ALTER TABLE bots ADD INDEX idx_bots_token (token)"
php patch_ranking_cache.php aplicar > /dev/null
bash /root/estresse/setpool.sh 150 50
echo '{"tg":150,"gw":700,"gw_consulta":250}' > /dev/shm/est/lat.json
export WRK_COOKIES=/root/res/cookies_usuarios.txt
for CEN in "50 800" "70 1200" "90 1600"; do
  set -- $CEN; TAXA=$1; USERS=$2
  php monitor.php "/root/res/${R}_t${TAXA}_u${USERS}.monitor.csv" 2 & MP=$!
  php funil.php --tag=e2 --taxa=$TAXA --dur=120 --conversao=0.25 --dup=0.1 --max-voando=3000 --saida="/root/res/${R}_t${TAXA}_u${USERS}_funil.json" > /dev/null 2>&1 &
  PF=$!
  sleep 5
  WRK_THINK=15000 wrk -t2 -c$USERS -d110s --latency -s /root/estresse/paginas.lua http://127.0.0.1:8080 > "/root/res/${R}_t${TAXA}_u${USERS}_painel.out" 2>&1
  wait $PF
  sleep 3; kill -TERM $MP 2>/dev/null; wait $MP 2>/dev/null
  php resumo_monitor.php "/root/res/${R}_t${TAXA}_u${USERS}.monitor.csv" > "/root/res/${R}_t${TAXA}_u${USERS}.resumo.txt" 2>&1
  mysql painel -e "UPDATE vendas SET status='expirado' WHERE status='gerado' AND transacao_id LIKE 'SIMTX%'"
  sleep 8
done
php patch_ranking_cache.php desfazer > /dev/null
mysql painel -e "ALTER TABLE bots DROP INDEX idx_bots_token"
bash /root/estresse/setpool.sh restaurar
a2disconf -q zz-teste-mpm; rm -f /etc/apache2/conf-available/zz-teste-mpm.conf
apache2ctl configtest >/dev/null 2>&1 && systemctl restart apache2
echo pronto > "/root/res/${R}_FIM"
