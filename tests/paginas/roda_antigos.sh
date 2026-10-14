#!/bin/bash
# Roda pg_a1..pg_a16 com os ids do seed atual (os scripts antigos têm 311/312/471/472/49 fixos) e limpa o Chrome de cada rodada.
# Ordem: leadsextra (33 leads de massa) entra entre a4 e a5, que contam 39 leads. Saída mascarada em $LOG.
LOG=${LOG:-$TEMP/pg_antigos.log}; : > "$LOG"
mask() { sed "s/$(printf '%s' "$PGPW" | sed 's/[][\.*^$/]/\&/g')/***/g"; }
B1=${B1:?}; B2=${B2:?}; FG=${FG:?}; FV=${FV:?}; U=${U:?}
for n in ${LISTA:-1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16}; do
  if [ "$n" = "5" ]; then plink -batch -P 65002 -pw "$PGPW" u214219698@185.213.81.10 "cd domains/stackcode.com.br/public_html/telegram && php zz_pg.php leadsextra" 2>&1 | mask >> "$LOG"; fi
  perl -pe 's/\b311\b/'$B1'/g; s/\b312\b/'$B2'/g; s/\b471\b/'$FG'/g; s/\b472\b/'$FV'/g; s/\b49\b(?![,.]\d)/'$U'/g' pg_a$n.js > pg_x$n.js
  echo "##### pg_a$n" >> "$LOG"
  OUT="$TEMP" timeout 420 node pg_x$n.js 2>&1 | mask | grep -E "^==|PASS|FAIL|NOTA|>>|ERRO|Error" | cut -c1-300 >> "$LOG"
  rm -f pg_x$n.js; rm -rf "$TEMP"/cdp-* 2>/dev/null
done
echo "TERMINOU" >> "$LOG"
