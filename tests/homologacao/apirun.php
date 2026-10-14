<?php
// Executa o api.php da cópia como um usuário logado. Uso: php apirun.php METODO acao 'json-do-corpo' ['qs=a&b=c'] [uid]
$metodo = $argv[1] ?? 'GET';
$acao = $argv[2] ?? '';
$corpo = $argv[3] ?? '';
parse_str($argv[4] ?? '', $qs);
$ids = json_decode((string) file_get_contents('/tmp/pt/ids.json'), true);
$uid = (int) ($argv[5] ?? $ids['uid']);
ini_set('session.save_path', '/tmp/pt/tmp');
chdir('/tmp/pt/app');
session_start();
if ($uid > 0) {
    $_SESSION['usuario_id'] = $uid;
    $_SESSION['usuario_perfil'] = 'usuario';
    $_SESSION['usuario_nome'] = 'Teste';
} else {
    unset($_SESSION['usuario_id']);
}
$_SESSION['csrf_token'] = 'tokencsrf';
$_SERVER['REQUEST_METHOD'] = $metodo;
$_SERVER['CONTENT_TYPE'] = 'application/json';
if (empty($qs['sem_csrf'])) $_SERVER['HTTP_X_CSRF_TOKEN'] = 'tokencsrf';
unset($qs['sem_csrf']);
$_GET = ['action' => $acao] + $qs;
$GLOBALS['SIM_INPUT'] = $corpo;
ob_start();
register_shutdown_function(function () {
    $o = ob_get_clean();
    file_put_contents('/tmp/pt/resp.json', json_encode(['http' => http_response_code() ?: 200, 'body' => json_decode($o, true), 'raw' => mb_substr($o, 0, 400)], JSON_UNESCAPED_UNICODE));
    echo $o, "\n";
});
require '/tmp/pt/app/api.php';
