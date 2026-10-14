<?php
// Executa o webhook_omegapayments.php. Uso: php owh.php '<json>'
chdir('/tmp/pt/app');
$GLOBALS['SIM_INPUT'] = $argv[1] ?? '';
sim_log(['owh' => 'in', 'payload' => json_decode($GLOBALS['SIM_INPUT'], true)]);
ob_start();
register_shutdown_function(function () { $o = ob_get_clean(); sim_log(['owh' => 'out', 'http' => http_response_code(), 'saida' => trim($o)]); echo $o; });
require '/tmp/pt/app/webhook_omegapayments.php';
