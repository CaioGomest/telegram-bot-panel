<?php
// Lê o sim.log desde a última marca e diz por qual caminho do Sorteio o lead foi: U (Upsell), D (Downsell), M (mensagem do caminho 3) ou ? (nenhum).
$marca = (int) @file_get_contents('/tmp/pt/marca');
$linhas = array_slice(is_file('/tmp/pt/sim.log') ? file('/tmp/pt/sim.log', FILE_IGNORE_NEW_LINES) : [], $marca);
foreach ($linhas as $l) {
    $e = json_decode($l, true);
    if (($e['tg'] ?? '') !== 'sendMessage') continue;
    $t = (string) ($e['p']['text'] ?? '');
    if (strpos($t, 'Upsell zero') === 0) { echo 'U'; exit; }
    if (strpos($t, 'Downsell zero') === 0) { echo 'D'; exit; }
    if (strpos($t, 'Caminho 3 do sorteio zero') === 0) { echo 'M'; exit; }
}
echo '?';
