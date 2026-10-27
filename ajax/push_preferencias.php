<?php
declare(strict_types=1);

// POST {id, pref_pix_gerado?, pref_pix_pago?}: liga/desliga cada aviso de UM aparelho do próprio vendedor.
require_once __DIR__ . '/_push.php';

[$id_usuario, $dados] = pushAjaxInicio('POST');
$id = (int) ($dados['id'] ?? 0);
$gerado = array_key_exists('pref_pix_gerado', $dados) ? (bool) $dados['pref_pix_gerado'] : null;
$pago = array_key_exists('pref_pix_pago', $dados) ? (bool) $dados['pref_pix_pago'] : null;
if ($id <= 0 || ($gerado === null && $pago === null)) {
    pushResponder(['sucesso' => false, 'mensagem' => 'Dados inválidos.'], 422);
}
$ok = pushAtualizarPreferencias($pdo, $id_usuario, $id, $gerado, $pago);
pushResponder($ok ? ['sucesso' => true] : ['sucesso' => false, 'mensagem' => 'Aparelho não encontrado.'], $ok ? 200 : 404);
