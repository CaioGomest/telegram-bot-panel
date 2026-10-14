#!/usr/bin/env bash
# capturar_consultas.sh — registra as consultas que examinam muitas linhas (>2000) durante o funil, as telas e as crons, e lista as piores por tempo total.
set -uo pipefail
ulimit -n 100000
cd /root/estresse
mysql -e "SET GLOBAL log_queries_not_using_indexes=1; SET GLOBAL min_examined_row_limit=2000; SET GLOBAL long_query_time=0"
: > /var/log/mysql/slow.log
bash /root/estresse/setpool.sh 150 50
echo '{"tg":50,"gw":200,"gw_consulta":100}' > /dev/shm/est/lat.json
php funil.php --tag=e2 --conc=64 --dur=20 --conversao=0.3 --dup=0.1 > /dev/null 2>&1
export WRK_COOKIES=/root/res/cookies_usuarios.txt
WRK_THINK=0 wrk -t2 -c16 -d15s -s /root/estresse/paginas.lua http://127.0.0.1:8080 > /dev/null 2>&1
WRK_COOKIES=/root/res/cookies_mega.txt WRK_THINK=0 wrk -t1 -c2 -d12s -s /root/estresse/paginas.lua http://127.0.0.1:8080 > /dev/null 2>&1
mysql -e "SET GLOBAL log_queries_not_using_indexes=0; SET GLOBAL min_examined_row_limit=0; SET GLOBAL long_query_time=1"
mysql painel -e "UPDATE vendas SET status='expirado' WHERE status='gerado' AND transacao_id LIKE 'SIMTX%'"
bash /root/estresse/setpool.sh restaurar
echo "consultas registradas: $(grep -c '^# Query_time' /var/log/mysql/slow.log)"
mariadb-dumpslow -s t -t 14 /var/log/mysql/slow.log 2>/dev/null | cut -c1-200 | grep -vE "^\s*(use |SET timestamp)"
