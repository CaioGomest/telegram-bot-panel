#!/usr/bin/env bash
# fase_tuning_fpm.sh RÓTULO TAG MAX_FILHOS [INICIAIS] — refaz os pontos-chave do funil com OUTRO tamanho do pool PHP-FPM da cópia de teste
# (é o gargalo do funil: cada webhook segura um processo enquanto espera o Telegram/a gateway). Mexe só no pool 'estresse'; restaura o original no fim.
set -uo pipefail
ulimit -n 100000
R="$1"; TAG="$2"; MAXF="$3"; INI="${4:-$((MAXF/3))}"
set_pool() { bash /root/estresse/setpool.sh "$1" "$2"; }
cd /root/estresse
lat() { echo "{\"tg\":$1,\"gw\":$2,\"gw_consulta\":$3}" > /dev/shm/est/lat.json; }
run() { local nome="$1"; shift; bash rodada.sh "${R}_$nome" php funil.php --tag="$TAG" --saida="/root/res/${R}_$nome.json" "$@" > /dev/null
  mysql painel -e "UPDATE vendas SET status='expirado' WHERE status='gerado' AND transacao_id LIKE 'SIMTX%'"; sleep 4; }
set_pool "$MAXF" "$INI"
lat 150 700 250
for C in 96 192 288; do run "c$C" --conc=$C --dur=40 --conversao=0.25 --dup=0.1; done
for T in 30 45 60 80 100; do run "aberto_t$T" --taxa=$T --dur=45 --conversao=0.25 --dup=0.1 --max-voando=3000; done
lat 300 1500 500
run "lenta_c192" --conc=192 --dur=40 --conversao=0.25 --dup=0.1
lat 150 700 250
# restaura o pool original
bash /root/estresse/setpool.sh restaurar
echo pronto > "/root/res/${R}_FIM"
