<?php
declare(strict_types=1);

// POST {id} ou {endpoint}: remove um aparelho do próprio vendedor. Com "endpoint", é o aparelho atual (usado ao sair da conta,
// para um computador compartilhado não continuar recebendo os avisos de quem saiu).
require_once __DIR__ . '/_push.php';

[$id_usuario, $dados] = pushAjaxInicio('POST');
$id = isset($dados['id']) ? (int) $dados['id'] : null;
$endpoint = isset($dados['endpoint']) && is_string($dados['endpoint']) ? substr($dados['endpoint'], 0, 600) : null;
if (($id === null || $id <= 0) && ($endpoint === null || $endpoint === '')) {
    pushResponder(['sucesso' => false, 'mensagem' => 'Dados inválidos.'], 422);
}
$removidos = pushRemoverAparelho($pdo, $id_usuario, $id, $endpoint);
pushResponder(['sucesso' => true, 'removidos' => $removidos]);
