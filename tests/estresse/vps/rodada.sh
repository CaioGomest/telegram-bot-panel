#!/usr/bin/env bash
# rodada.sh NOME COMANDO... — roda um comando de carga com o monitor de recursos ligado e guarda tudo em /root/res/NOME.*
#   .out/.err (saída do comando) · .monitor.csv (CPU, memória, disco, PHP-FPM, MariaDB a cada 2 s) · .resumo.txt (picos e médias da rodada)
# Antes de cada rodada espera o banco e a CPU assentarem (≤ 20 s).
set -uo pipefail
N="$1"; shift
mkdir -p /root/res
for i in $(seq 1 10); do [[ "$(awk '{print int($1*100)}' /proc/loadavg)" -lt 40 ]] && break; sleep 2; done
rm -f /dev/shm/est/n_* /dev/shm/est/pagos/* 2>/dev/null
php /root/estresse/monitor.php "/root/res/$N.monitor.csv" 2 & MP=$!
sleep 3
INI=$(date +%s)
"$@" > "/root/res/$N.out" 2> "/root/res/$N.err"
RC=$?
FIM=$(date +%s)
sleep 3
kill -TERM "$MP" 2>/dev/null; wait "$MP" 2>/dev/null
for f in /dev/shm/est/n_*; do [[ -f "$f" ]] && printf '%s=%s\n' "$(basename "$f")" "$(stat -c %s "$f")"; done > "/root/res/$N.chamadas_simuladas.txt" 2>/dev/null
php /root/estresse/resumo_monitor.php "/root/res/$N.monitor.csv" "$INI" "$FIM" > "/root/res/$N.resumo.txt" 2>&1
echo "[$N] rc=$RC  duração=$((FIM-INI))s"
cat "/root/res/$N.resumo.txt"
