<?php
// Estatística do bloco Sorteio do fluxo visual criado pela tela: 20 mil sorteios com os pesos digitados (50/30/20), sem Telegram nem espera.
// Uso: php zero_sorteio_estat.php FLUXO
require '/tmp/pt/common.php';
require_once '/tmp/pt/app/funcoes/execucao_fluxo.php';
$d = json_decode((string) q1("SELECT dados_fluxograma FROM fluxos WHERE id = ?", [(int) ($argv[1] ?? 0)]), true);
$prop = $d['operators']['operator_8']['properties'] ?? null;
if (!$prop || ($prop['type'] ?? '') !== 'randomizer') { echo "operator_8 não é o Sorteio\n"; exit(1); }
$pesos = array_map(fn($c) => $c['peso'], $prop['caminhos']);
$cont = [];
$n = 20000;
for ($i = 0; $i < $n; $i++) { $p = proximoNoConsiderandoTipo($d, 'operator_8', $prop); $cont[$p] = ($cont[$p] ?? 0) + 1; }
ksort($cont);
$soma = array_sum($pesos);
$ok = true;
echo "pesos: ", implode('/', $pesos), "\n";
foreach (['operator_13' => 0, 'operator_14' => 1, 'operator_17' => 2] as $op => $i) {
    $obs = ($cont[$op] ?? 0) / $n * 100; $esp = $pesos[$i] / $soma * 100;
    $dentro = abs($obs - $esp) <= 1.5;   // 3 desvios-padrão de uma amostra de 20 mil ~ 1,1 ponto
    $ok = $ok && $dentro;
    printf("  %s (caminho %d): %.2f%% observado, %.2f%% esperado %s\n", $op, $i + 1, $obs, $esp, $dentro ? 'OK' : 'FORA');
}
echo $ok ? "  PASS | sorteio respeita os pesos digitados na tela\n" : "  FAIL | sorteio fora dos pesos\n";
