#!/bin/bash
# Rodada final consolidada: reconstrói a cópia a partir do código que está NO SERVIDOR e roda tudo.
cd /tmp/pt
rm -f final_*.txt FINAL_PRONTO
# 1º cria o bot de teste: o isolamento das crons (patch.php, dentro do setup) usa o id dele. Sem isso, depois de uma limpeza
# o setup fixa o id de um bot que não existe mais e as crons da cópia não enxergam o bot novo (falso alarme em g3/g5/g6).
php seed.php > final_seed0.txt 2>&1
bash setup.sh > final_setup.txt 2>&1
php seed.php > final_seed.txt 2>&1
: > resultados.txt
# SEM_UI=1 pula os dois testes que precisam de um fluxo salvo pelo editor no Chrome (sem eles dão 15 falhas esperadas)
LISTA="g1 g2 g3 g4 g5 g6 g7 g8 g9 g10 v1 v2 v3 v4 t1 s1 e2e a2 a1"
[ -z "${SEM_UI:-}" ] && LISTA="ui_engine1:${FLUXO_GUIADO:-0} ui_engine2:${FLUXO_VISUAL:-0} $LISTA"
for s in $LISTA; do
  nome=${s%%:*}; arg=${s#*:}; [ "$arg" = "$s" ] && arg=""
  bash $nome.sh $arg > final_$nome.txt 2>&1
done
echo "PASS=$(grep -c '^PASS' resultados.txt) FAIL=$(grep -c '^FAIL' resultados.txt)" > FINAL_PRONTO
