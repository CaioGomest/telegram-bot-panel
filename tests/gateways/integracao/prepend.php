<?php
// Carregado por auto_prepend_file em todo processo PHP do teste: simuladores + corpo/cabeçalhos HTTP que o teste entrega ao webhook.
//   SIM_INPUT_ARQ  = arquivo com o corpo da requisição (substitui php://input, trocado na cópia do painel)
//   SIM_HEADERS    = JSON {"X-NXGate-Signature": "..."} vira $_SERVER['HTTP_X_NXGATE_SIGNATURE']
require_once (getenv('SIM_DIR') ?: '/tmp/pt') . '/sim.php';
if (is_file(SIM_DIR . '/sim_gateways.php')) {
    require_once SIM_DIR . '/sim_gateways.php';
}
$arquivo_corpo = getenv('SIM_INPUT_ARQ');
if ($arquivo_corpo !== false && $arquivo_corpo !== '' && is_file($arquivo_corpo)) {
    $GLOBALS['SIM_INPUT'] = (string) file_get_contents($arquivo_corpo);
}
foreach ((json_decode((string) getenv('SIM_HEADERS'), true) ?: []) as $nome => $valor) {
    $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', (string) $nome))] = (string) $valor;
}
