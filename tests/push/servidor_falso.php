<?php
declare(strict_types=1);

/**
 * Serviço de push FALSO para os testes dos avisos push. Script "roteador" do servidor embutido do PHP:
 *   PUSH_FALSO_DIR=/tmp/x PHP_CLI_SERVER_WORKERS=16 php -S 127.0.0.1:8099 tests/push/servidor_falso.php
 * Registra cada requisição em $PUSH_FALSO_DIR/recebidos.jsonl (com início e fim, para medir simultaneidade) e responde pelo primeiro segmento do caminho:
 *   /ok/..  201 · /morta/..  410 · /naoexiste/..  404 · /limite/..  429 + Retry-After · /erro500/..  500 · /assinatura/..  403 · /grande/..  413
 *   /lento/..  espera controle.lento_s (padrão 12) e responde 201 (para provar o timeout) · /latencia/..  espera controle.latencia_ms e responde 201
 * controle.log_minimo=true grava só horário/caminho/código (carga). Qualquer caminho espera também controle.latencia_global_ms (padrão 0) antes de responder. O arquivo $PUSH_FALSO_DIR/controle.json é relido a cada requisição.
 */

$dir = (string) getenv('PUSH_FALSO_DIR');
$inicio = microtime(true);
$controle = [];
if ($dir !== '' && is_file($dir . '/controle.json')) {
    $controle = json_decode((string) file_get_contents($dir . '/controle.json'), true) ?: [];
}
$caminho = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
$cenario = explode('/', ltrim($caminho, '/'))[0] ?? 'ok';
$corpo = (string) file_get_contents('php://input');
$cab = function (string $nome): string {
    return (string) ($_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $nome))] ?? '');
};

$espera_ms = (int) ($controle['latencia_global_ms'] ?? 0);
if ($cenario === 'lento') {
    $espera_ms += (int) (($controle['lento_s'] ?? 12) * 1000);
} elseif ($cenario === 'latencia') {
    $espera_ms += (int) ($controle['latencia_ms'] ?? 150);
}
if ($espera_ms > 0) {
    usleep($espera_ms * 1000);
}

$codigo = match ($cenario) {
    'morta' => 410,
    'naoexiste' => 404,
    'limite' => 429,
    'erro500' => 500,
    'assinatura' => 403,
    'grande' => 413,
    default => 201,
};
http_response_code($codigo);
if ($codigo === 429) {
    header('Retry-After: ' . (int) ($controle['retry_after'] ?? 3));
}
if ($codigo === 201) {
    header('Location: https://push.exemplo.invalid/mensagem/' . bin2hex(random_bytes(6)));
}

if ($dir !== '' && !empty($controle['log_minimo'])) {
    // Teste de carga (centenas de milhares de requisições): só horário, caminho e código; sem corpo nem cabeçalhos
    @file_put_contents($dir . '/recebidos.jsonl', json_encode(['ini' => $inicio, 'fim' => microtime(true), 'caminho' => $caminho, 'codigo' => $codigo]) . "\n", FILE_APPEND | LOCK_EX);
} elseif ($dir !== '') {
    $linha = json_encode([
        'ini' => $inicio, 'fim' => microtime(true), 'caminho' => $caminho, 'metodo' => $_SERVER['REQUEST_METHOD'] ?? '', 'codigo' => $codigo,
        'autorizacao' => $cab('Authorization'), 'ttl' => $cab('TTL'), 'urgencia' => $cab('Urgency'), 'topico' => $cab('Topic'),
        'codificacao' => $cab('Content-Encoding'), 'tipo' => $cab('Content-Type'), 'tamanho' => strlen($corpo), 'corpo_b64' => base64_encode($corpo),
    ]);
    @file_put_contents($dir . '/recebidos.jsonl', $linha . "\n", FILE_APPEND | LOCK_EX);
}
