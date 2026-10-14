#!/usr/bin/env bash
# fase_s2.sh RÓTULO — repete as medidas-chave com o banco MAIOR (muitos vendedores + 1 vendedor gigante), para ver como a capacidade cai com o volume.
#   1) recalcula as métricas (o que a cron de 5 min faz)  2) sessões  3) telas uma a uma (vendedor comum e vendedor gigante)
#   4) mistura em rampa  5) admin + tempo das crons  6) funil (pool 150) em 3 pontos
set -uo pipefail
ulimit -n 100000
R="$1"
cd /root/estresse
for I in 1 2; do /usr/bin/time -f "metricas (cron_metricas_admin) %e s" runuser -u www-data -- php -d auto_prepend_file=/opt/estresse/sim_rapido.php /var/www/painel-estresse/cron/cron_metricas_admin.php >> "/root/res/${R}_metricas.txt" 2>&1; done
php sessoes.php --tag=e1 --n=2000 --saida=/root/res/c_e1.txt > /dev/null
php sessoes.php --tag=e2 --n=4000 --saida=/root/res/c_e2.txt > /dev/null
cat /root/res/c_e1.txt /root/res/c_e2.txt > /root/res/cookies_usuarios.txt
php sessoes.php --tag=mega --n=1 --saida=/root/res/cookies_mega.txt > /dev/null
W="wrk -t2 --latency -s /root/estresse/paginas.lua http://127.0.0.1:8080"
: > "/root/res/${R}_paginas_individuais.txt"
for P in /index "/index?periodo=30" /vendas "/vendas?aba=pagas&periodo=30" /leads "/leads?pagina=2" /bots /fluxos /remarketing /ranking /ajax/notificacoes.php; do
  WRK_COOKIES=/root/res/cookies_usuarios.txt WRK_PATH="$P" WRK_THINK=0 $W -c8 -d10s > /tmp/pg1.out 2>&1
  printf '%-34s %s | %s | %s\n' "$P" "$(grep -E 'Requests/sec' /tmp/pg1.out | awk '{print $2" req/s"}')" "$(grep -E '^\s+50%|^\s+99%' /tmp/pg1.out | tr -s ' ' | tr '\n' ' ')" "$(grep -E 'HTTP ' /tmp/pg1.out | tr -s ' ' | tr '\n' ' ')" >> "/root/res/${R}_paginas_individuais.txt"
  sleep 2
done
: > "/root/res/${R}_paginas_gigante.txt"
for P in /index "/index?periodo=30" /vendas "/vendas?aba=pagas&periodo=30" /leads "/leads?pagina=2" "/leads?pagina=500" "/leads?status=pagou" /bots /remarketing /ajax/notificacoes.php; do
  WRK_COOKIES=/root/res/cookies_mega.txt WRK_PATH="$P" WRK_THINK=0 $W -c4 -d10s > /tmp/pg1.out 2>&1
  printf '%-34s %s | %s | %s\n' "$P" "$(grep -E 'Requests/sec' /tmp/pg1.out | awk '{print $2" req/s"}')" "$(grep -E '^\s+50%|^\s+99%' /tmp/pg1.out | tr -s ' ' | tr '\n' ' ')" "$(grep -E 'HTTP ' /tmp/pg1.out | tr -s ' ' | tr '\n' ' ')" >> "/root/res/${R}_paginas_gigante.txt"
  sleep 2
done
export WRK_COOKIES=/root/res/cookies_usuarios.txt
for C in 16 64 128; do WRK_THINK=0 bash rodada.sh "${R}_mix_c$C" $W -c$C -d30s > /dev/null; sleep 5; done
bash fase_admin_crons.sh "${R}" > "/root/res/${R}_admin_crons.log" 2>&1
# funil com o pool de 150 processos
bash /root/estresse/setpool.sh 150 50
echo '{"tg":150,"gw":700,"gw_consulta":250}' > /dev/shm/est/lat.json
run() { local nome="$1"; shift; bash rodada.sh "${R}_$nome" php funil.php --tag=e2 --saida="/root/res/${R}_$nome.json" "$@" > /dev/null
  mysql painel -e "UPDATE vendas SET status='expirado' WHERE status='gerado' AND transacao_id LIKE 'SIMTX%'"; sleep 4; }
run funil_c192 --conc=192 --dur=40 --conversao=0.25 --dup=0.1
run funil_aberto_t45 --taxa=45 --dur=45 --conversao=0.25 --dup=0.1 --max-voando=3000
run funil_aberto_t60 --taxa=60 --dur=45 --conversao=0.25 --dup=0.1 --max-voando=3000
bash /root/estresse/setpool.sh restaurar
echo pronto > "/root/res/${R}_FIM"
