#!/usr/bin/env bash
# fase_funil.sh RÓTULO TAG — capacidade do FUNIL DE VENDAS (webhook do Telegram + gateway), com Telegram e gateway simulados COM latência.
#   A) latência realista (Telegram 150 ms, gateway 700 ms): rampa de clientes simultâneos (laço fechado)
#   B) externas rápidas (50/300 ms) e lentas (300/1500 ms): 3 pontos cada
#   C) laço aberto: chegada fixa de clientes por segundo (mostra onde satura e como degrada)
# Resultado em /root/res/RÓTULO_*.  Roda NA VPS em segundo plano (~30 min). Os pontos de cada rodada ficam em *.json (+ monitor de recursos).
set -uo pipefail
ulimit -n 100000
R="$1"; TAG="$2"
cd /root/estresse
lat() { echo "{\"tg\":$1,\"gw\":$2,\"gw_consulta\":$3}" > /dev/shm/est/lat.json; }
run() { local nome="$1"; shift; bash rodada.sh "${R}_$nome" php funil.php --tag="$TAG" --saida="/root/res/${R}_$nome.json" "$@" > /dev/null
  # nunca deixar Pix "gerado" de teste para trás: a cron REAL de Pix os consultaria na gateway de verdade (credencial falsa)
  mysql painel -e "UPDATE vendas SET status='expirado' WHERE status='gerado' AND transacao_id LIKE 'SIMTX%'"; sleep 4; }
lat 150 700 250
for C in 8 16 32 48 64 96 128 192 256; do run "real_c$C" --conc=$C --dur=40 --conversao=0.25 --dup=0.1; done
lat 50 300 100
for C in 32 96 192; do run "rapida_c$C" --conc=$C --dur=40 --conversao=0.25 --dup=0.1; done
lat 300 1500 500
for C in 32 96 192; do run "lenta_c$C" --conc=$C --dur=40 --conversao=0.25 --dup=0.1; done
lat 150 700 250
for T in 20 40 60 80 100 130 170 220 300; do run "aberto_t$T" --taxa=$T --dur=45 --conversao=0.25 --dup=0.1 --max-voando=3000; done
echo pronto > "/root/res/${R}_FIM"
