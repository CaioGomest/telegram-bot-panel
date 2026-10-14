#!/usr/bin/env bash
# fase_pagamentos.sh RÓTULO TAG — rajada de CONFIRMAÇÕES DE PAGAMENTO (webhook da gateway), com o pool PHP-FPM do painel (48) e com 150.
# 1) gera ~10 mil Pix pendentes (funil sem conversão)  2) dispara os avisos de pagamento em rajada com 32/64/128 conexões e 20% de avisos repetidos
set -uo pipefail
ulimit -n 100000
R="$1"; TAG="$2"
set_pool() { bash /root/estresse/setpool.sh "$1" "$2"; }
cd /root/estresse
echo '{"tg":150,"gw":700,"gw_consulta":250}' > /dev/shm/est/lat.json
set_pool 150 50 25 100
bash rodada.sh "${R}_gera_pendentes" php funil.php --tag="$TAG" --conc=192 --dur=170 --conversao=0 --saida="/root/res/${R}_gera_pendentes.json" > /dev/null
PEND=$(mysql painel -N -e "SELECT COUNT(*) FROM vendas WHERE status='gerado' AND transacao_id LIKE 'SIMTX%'")
echo "pendentes: $PEND" > "/root/res/${R}_pendentes.txt"
for POOLN in 48 150; do
  if [[ $POOLN == 48 ]]; then set_pool 48 12 6 24; else set_pool 150 50 25 100; fi
  for C in 32 64 128; do
    bash rodada.sh "${R}_pool${POOLN}_c$C" php pagamentos.php --n=1500 --conc=$C --dup=0.2 --saida="/root/res/${R}_pool${POOLN}_c$C.json" > /dev/null
    sleep 3
  done
done
mysql painel -e "UPDATE vendas SET status='expirado' WHERE status='gerado' AND transacao_id LIKE 'SIMTX%'"
bash /root/estresse/setpool.sh restaurar
echo pronto > "/root/res/${R}_FIM"
