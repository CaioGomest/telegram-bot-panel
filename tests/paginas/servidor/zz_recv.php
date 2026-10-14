<?php
// Receptor TEMPORÁRIO de webhook para o teste (registra o que chega). Removido no fim do teste.
$corpo = file_get_contents('php://input');
$h = [];
foreach (['HTTP_X_WEBHOOK_SIGNATURE' => 'assinatura', 'CONTENT_TYPE' => 'tipo', 'HTTP_USER_AGENT' => 'ua'] as $k => $n) { $h[$n] = $_SERVER[$k] ?? null; }
file_put_contents(__DIR__ . '/zz_recv.log', json_encode(['metodo' => $_SERVER['REQUEST_METHOD'], 'qs' => $_SERVER['QUERY_STRING'] ?? '', 'h' => $h, 'corpo' => $corpo], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
$modo = trim((string) @file_get_contents(__DIR__ . '/zz_recv.modo'));
if ($modo === '500') { http_response_code(500); echo 'erro'; exit; }
if ($modo === 'lento') { sleep(6); }
echo 'ok';
