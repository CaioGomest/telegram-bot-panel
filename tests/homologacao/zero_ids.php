<?php
// Imprime variáveis de shell com os ids de planos (P1..) e packs (K1..) do fluxo guiado criado pela tela: eval "$(php zero_ids.php ID)"
require '/tmp/pt/common.php';
$f = q1("SELECT dados_fluxograma FROM fluxos WHERE id = ?", [(int) ($argv[1] ?? 0)]);
$d = json_decode((string) $f, true) ?: [];
foreach (($d['planos'] ?? []) as $i => $p) echo 'P' . ($i + 1) . '=' . escapeshellarg((string) $p['id']) . "\n";
foreach (($d['packs']['itens'] ?? []) as $i => $p) echo 'K' . ($i + 1) . '=' . escapeshellarg((string) $p['id']) . "\n";
