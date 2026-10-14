#!/usr/bin/env bash
# fase_soak.sh RÓTULO TAXA_SOAK DUR_SOAK_S — (1) SOBRECARGA com recuperação: 120 clientes/s por 60 s + um "cliente sonda" a 3/s durante 4 min, para ver como e
# quando o atendimento volta ao normal; (2) SOAK: carga constante (TAXA_SOAK clientes/s) por DUR_SOAK_S, vendo deriva de latência, memória, processos e disco.
# Usa o pool de 150 processos. Restaura o pool no fim.
set -uo pipefail
ulimit -n 100000
R="$1"; TAXA="${2:-40}"; DUR="${3:-900}"
bash /root/estresse/setpool.sh 150 50
echo '{"tg":150,"gw":700,"gw_consulta":250}' > /dev/shm/est/lat.json
cd /root/estresse
expira() { mysql painel -e "UPDATE vendas SET status='expirado' WHERE status='gerado' AND transacao_id LIKE 'SIMTX%'"; }
php monitor.php "/root/res/${R}_sobrecarga.monitor.csv" 2 & MP=$!
php funil.php --tag=e2 --taxa=120 --dur=60 --max-voando=6000 --timeout=30 --conversao=0.25 --dup=0.1 --saida="/root/res/${R}_sobrecarga.json" > /dev/null 2> "/root/res/${R}_sobrecarga.err" &
PA=$!
sleep 1
php funil.php --tag=e1 --taxa=3 --dur=240 --timeout=60 --conversao=0 --saida="/root/res/${R}_sonda.json" > /dev/null 2> "/root/res/${R}_sonda.err" &
PB=$!
wait $PA; wait $PB
sleep 5; kill -TERM $MP 2>/dev/null; wait $MP 2>/dev/null
expira
mysql -N -e "SHOW GLOBAL STATUS LIKE 'Threads_connected'" > "/root/res/${R}_apos_sobrecarga.txt"
sleep 30
PSBEFORE=$(ps -eo rss,cmd | grep "php-fpm: pool estresse" | grep -v grep | awk '{s+=$1;n++} END {printf "%d proc, %.0f MB RSS total", n, s/1024}')
echo "antes do soak: $PSBEFORE" > "/root/res/${R}_soak_memoria.txt"
df -m / | tail -1 | awk '{print "disco usado antes: "$3" MB"}' >> "/root/res/${R}_soak_memoria.txt"
bash rodada.sh "${R}_soak" php funil.php --tag=e2 --taxa="$TAXA" --dur="$DUR" --max-voando=3000 --conversao=0.25 --dup=0.1 --saida="/root/res/${R}_soak.json" > /dev/null
expira
PSAFTER=$(ps -eo rss,cmd | grep "php-fpm: pool estresse" | grep -v grep | awk '{s+=$1;n++} END {printf "%d proc, %.0f MB RSS total", n, s/1024}')
echo "depois do soak: $PSAFTER" >> "/root/res/${R}_soak_memoria.txt"
df -m / | tail -1 | awk '{print "disco usado depois: "$3" MB"}' >> "/root/res/${R}_soak_memoria.txt"
bash /root/estresse/setpool.sh restaurar
echo pronto > "/root/res/${R}_FIM"
