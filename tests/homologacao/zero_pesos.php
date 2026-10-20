<?php
// Troca os pesos do bloco Sorteio de um fluxo visual (para o teste de motor ser determinístico). Uso: php zero_pesos.php FLUXO OPERADOR P0 P1 P2
require '/tmp/pt/common.php';
[, $fid, $op, $p0, $p1, $p2] = $argv + [null, 0, '', 50, 30, 20];
$d = json_decode((string) q1("SELECT dados_fluxograma FROM fluxos WHERE id = ?", [(int) $fid]), true);
if (!isset($d['operators'][$op])) { echo "operador $op não existe\n"; exit(1); }
foreach ([$p0, $p1, $p2] as $i => $p) $d['operators'][$op]['properties']['caminhos'][$i]['peso'] = (float) $p;
$pdo->prepare("UPDATE fluxos SET dados_fluxograma = ? WHERE id = ?")->execute([json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), (int) $fid]);
echo "pesos de $op: $p0/$p1/$p2\n";
