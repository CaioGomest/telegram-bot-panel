#!/usr/bin/env bash
# fase_combinado.sh RÓTULO — o cenário mais realista: vendas entrando pelo funil (Telegram) E vendedores navegando no painel AO MESMO TEMPO.
# 120 s com pool de 150: funil em laço aberto (TAXA clientes/s) + N vendedores com pausa média de 15 s. Mede a latência dos DOIS lados.
set -uo pipefail
ulimit -n 100000
R="$1"
cd /root/estresse
bash /root/estresse/setpool.sh 150 50
echo '{"tg":150,"gw":700,"gw_consulta":250}' > /dev/shm/est/lat.json
export WRK_COOKIES=/root/res/cookies_usuarios.txt
for CEN in "20 300" "35 500" "50 800"; do
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
bash /root/estresse/setpool.sh restaurar
echo pronto > "/root/res/${R}_FIM"
