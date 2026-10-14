#!/usr/bin/env bash
# fase_paginas.sh RÓTULO TAG N — mede as TELAS do painel (cópia de teste, 127.0.0.1:8080) com o volume de dados que existe agora no banco.
#   1) tempo de cada tela sozinha (8 conexões, 10 s)  2) rampa de concorrência da mistura (sem pausa: o máximo que o servidor entrega)
#   3) "humanos": N usuários com pausa média de 15 s entre as páginas + consulta de notificações
# Resultado em /root/res/RÓTULO_*.  Roda NA VPS, em segundo plano (leva ~12 min).
set -uo pipefail
ulimit -n 100000
R="$1"; TAG="$2"; N="${3:-1000}"
cd /root/estresse
php sessoes.php --tag="$TAG" --n="$N" > "/root/res/${R}_sessoes.txt"
export WRK_COOKIES=/root/res/cookies_usuarios.txt
W="wrk -t2 --latency -s /root/estresse/paginas.lua http://127.0.0.1:8080"
: > "/root/res/${R}_paginas_individuais.txt"
for P in /index "/index?periodo=30" /vendas "/vendas?aba=pagas&periodo=30" /leads "/leads?pagina=2" "/leads?status=pagou" /bots /fluxos /remarketing /traqueamento /links_rastreamento /gateways /configuracao_usuario /ranking /comunidade /redirecionamento /webhooks /ajax/notificacoes.php; do
  WRK_PATH="$P" WRK_THINK=0 $W -c8 -d10s > /tmp/pg1.out 2>&1
  printf '%-34s %s | %s | %s\n' "$P" "$(grep -E 'Requests/sec' /tmp/pg1.out | awk '{print $2" req/s"}')" "$(grep -E '^\s+50%|^\s+99%' /tmp/pg1.out | tr -s ' ' | tr '\n' ' ')" "$(grep -E 'HTTP ' /tmp/pg1.out | tr -s ' ' | tr '\n' ' ')" >> "/root/res/${R}_paginas_individuais.txt"
  sleep 2
done
for C in 8 32 64 128 256 512; do
  WRK_THINK=0 bash rodada.sh "${R}_mix_c$C" $W -c$C -d30s > /dev/null
  sleep 5
done
for U in 500 1500 3000 5000; do
  WRK_THINK=15000 bash rodada.sh "${R}_humanos_$U" $W -c$U -d60s > /dev/null
  sleep 5
done
echo pronto > "/root/res/${R}_FIM"
