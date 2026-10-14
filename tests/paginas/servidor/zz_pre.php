<?php
chdir(__DIR__); require_once 'conexao.php';
$b = $pdo->query("SELECT id,token,id_fluxo_conectado,token_invalido_em FROM bots WHERE id=15")->fetch(PDO::FETCH_ASSOC);
$w = json_decode(@file_get_contents("https://api.telegram.org/bot{$b['token']}/getWebhookInfo"), true)['result'] ?? [];
echo "fluxo=", $b['id_fluxo_conectado'], " token_invalido=", var_export($b['token_invalido_em'], true), "\n";
echo "webhook_url_ok=", var_export(strpos($w['url'] ?? '', 'telegram.stackcode.com.br') !== false, true), " pendentes=", $w['pending_update_count'] ?? '?', " erro=", $w['last_error_message'] ?? '-', "\n";
foreach (glob(__DIR__ . '/logs/*.log') as $f) { echo basename($f), ' ', date('d/m H:i', filemtime($f)), "\n"; }
echo "pix_pendentes=", $pdo->query("SELECT COUNT(*) FROM vendas WHERE status='pendente'")->fetchColumn(), "\n";
