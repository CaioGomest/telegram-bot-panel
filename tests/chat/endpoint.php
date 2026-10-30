<?php
declare(strict_types=1);

// Roda um ajax/chat_*.php como o servidor faria (sessão, método, cabeçalho CSRF e corpo JSON simulados) e imprime "<código HTTP>\n<corpo>".
// Uso (pelo integracao.php): php endpoint.php <config.json>
// config: {script, method, get, body, usuario_id (ou null), csrf (token enviado no cabeçalho, ou null)}
$cfg = json_decode((string) file_get_contents($argv[1]), true);
chdir(__DIR__ . '/..');

session_id('chat' . bin2hex(random_bytes(6)));
session_start();
if (!empty($cfg['usuario_id'])) {
    $_SESSION['usuario_id'] = (int) $cfg['usuario_id'];
}
$_SESSION['csrf_token'] = 'tok-teste';
session_write_close();

$_SERVER['REQUEST_METHOD'] = $cfg['method'] ?? 'GET';
$_SERVER['CONTENT_TYPE'] = 'application/json';
if (!empty($cfg['csrf'])) {
    $_SERVER['HTTP_X_CSRF_TOKEN'] = $cfg['csrf'];
}
$_GET = $cfg['get'] ?? [];
$GLOBALS['SIM_INPUT'] = json_encode($cfg['body'] ?? new stdClass());

ob_start();
register_shutdown_function(static function (): void {
    $corpo = (string) ob_get_clean();
    $codigo = http_response_code();
    echo (is_int($codigo) ? $codigo : 200) . "\n" . $corpo;
});
require $cfg['script'];
