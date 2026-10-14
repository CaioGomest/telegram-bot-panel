<?php
// Executa o webhook.php do bot como se o Telegram tivesse chamado. Uso: php hook.php <token> '<json do update>' [segredo]
chdir('/tmp/pt/app');
$GLOBALS['SIM_INPUT'] = $argv[2] ?? '{}';
$_GET['token'] = $argv[1] ?? '';
if (!empty($argv[3])) $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] = $argv[3];
sim_log(['webhook' => 'in', 'update' => json_decode($GLOBALS['SIM_INPUT'], true)]);
ob_start();
register_shutdown_function(function () { $o = ob_get_clean(); sim_log(['webhook' => 'out', 'resposta' => trim($o), 'http' => http_response_code()]); echo $o; });
require '/tmp/pt/app/webhook.php';
