<?php
// Roda um cron da cópia. Uso: php cron.php cron_verificar_pix
chdir('/tmp/pt/app/cron');
$nome = $argv[1] ?? '';
sim_log(['cron' => $nome, 'ini' => 1]);
ob_start();
register_shutdown_function(function () use ($nome) { $o = ob_get_clean(); sim_log(['cron' => $nome, 'fim' => 1, 'saida' => mb_substr(trim($o), 0, 600)]); echo $o; });
require "/tmp/pt/app/cron/$nome.php";
