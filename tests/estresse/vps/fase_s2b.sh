#!/usr/bin/env bash
# fase_s2b.sh RÓTULO — experimentos de GANHO no banco grande (S2): (a) cache do ranking (só na cópia de teste); (b) buffer pool do MariaDB de 2 GB → 4 GB.
set -uo pipefail
ulimit -n 100000
R="$1"
cd /root/estresse
export WRK_COOKIES=/root/res/cookies_usuarios.txt
W="wrk -t2 --latency -s /root/estresse/paginas.lua http://127.0.0.1:8080"
echo "== (a) COM cache do ranking"
php patch_ranking_cache.php aplicar > /dev/null
: > "/root/res/${R}_cache_ranking.txt"
for P in /index /ranking; do
  WRK_PATH="$P" WRK_THINK=0 $W -c16 -d10s > /tmp/pg1.out 2>&1
  printf '%-14s %s | %s\n' "$P" "$(grep -E 'Requests/sec' /tmp/pg1.out | awk '{print $2" req/s"}')" "$(grep -E '^\s+50%|^\s+99%' /tmp/pg1.out | tr -s ' ' | tr '\n' ' ')" >> "/root/res/${R}_cache_ranking.txt"
done
WRK_THINK=0 bash rodada.sh "${R}_cache_mix_c32" $W -c32 -d30s > /dev/null
php patch_ranking_cache.php desfazer > /dev/null
echo "== (b) buffer pool 4 GB"
mysql -e "SET GLOBAL innodb_buffer_pool_size = 4294967296"
for i in $(seq 1 60); do [[ "$(mysql -N -e "SELECT variable_value FROM information_schema.global_status WHERE variable_name='INNODB_BUFFER_POOL_RESIZE_STATUS'" 2>/dev/null)" == *"Completed"* || $i -gt 20 ]] && break; sleep 2; done
mysql -N -e "SELECT CONCAT('buffer pool agora: ', @@innodb_buffer_pool_size/1048576, ' MB')"
# aquece e mede
WRK_THINK=0 $W -c16 -d20s > /dev/null 2>&1
WRK_THINK=0 bash rodada.sh "${R}_bp4g_mix_c16" $W -c16 -d30s > /dev/null
bash /root/estresse/setpool.sh 150 50
echo '{"tg":150,"gw":700,"gw_consulta":250}' > /dev/shm/est/lat.json
bash rodada.sh "${R}_bp4g_funil_c192" php funil.php --tag=e2 --conc=192 --dur=40 --conversao=0.25 --dup=0.1 --saida="/root/res/${R}_bp4g_funil_c192.json" > /dev/null
mysql painel -e "UPDATE vendas SET status='expirado' WHERE status='gerado' AND transacao_id LIKE 'SIMTX%'"
bash /root/estresse/setpool.sh restaurar
mysql -e "SET GLOBAL innodb_buffer_pool_size = 2080374784"
echo pronto > "/root/res/${R}_FIM"
