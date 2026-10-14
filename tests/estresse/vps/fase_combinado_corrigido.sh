#!/usr/bin/env bash
# fase_combinado_corrigido.sh RÓTULO — o mesmo cenário do fase_combinado.sh, mas COM as duas correções que os testes apontaram:
#   (1) índice em bots.token (aplicado no banco e REMOVIDO no fim: o esquema volta a ser o que o painel define)
#   (2) cache do ranking (só na cópia de teste; desfeito no fim)
set -uo pipefail
ulimit -n 100000
R="$1"
cd /root/estresse
mysql painel -e "ALTER TABLE bots ADD INDEX idx_bots_token (token)"
php patch_ranking_cache.php aplicar > /dev/null
bash /root/estresse/setpool.sh 150 50
echo '{"tg":150,"gw":700,"gw_consulta":250}' > /dev/shm/est/lat.json
export WRK_COOKIES=/root/res/cookies_usuarios.txt
for CEN in "35 500" "50 800" "70 1200" "90 1600"; do
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
echo pronto > "/root/res/${R}_FIM"
