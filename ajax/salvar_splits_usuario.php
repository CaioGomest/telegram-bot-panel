<?php
declare(strict_types=1);
require_once __DIR__ . '/../funcoes/usuario.php';
verificarAdmin();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['sucesso' => false, 'erro' => 'Método inválido.']);
    exit;
}

$user_id = filter_input(INPUT_POST, 'id_usuario', FILTER_VALIDATE_INT);
if (!$user_id) {
    echo json_encode(['sucesso' => false, 'erro' => 'ID inválido.']);
    exit;
}

$splits_json = $_POST['splits'] ?? '[]';
$splits = json_decode($splits_json, true);
if (!is_array($splits)) {
    echo json_encode(['sucesso' => false, 'erro' => 'Dados inválidos.']);
    exit;
}

$gateways_validos = ['infopago'];

global $pdo;
try {
    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM usuarios_splits WHERE id_usuario = ?")->execute([$user_id]);

    $ordem = 0;
    foreach ($splits as $s) {
        $gateway_nome = in_array($s['gateway_nome'] ?? '', $gateways_validos, true) ? $s['gateway_nome'] : 'infopago';
        $tipo        = in_array($s['tipo_split'] ?? '', ['percentual', 'fixo'], true) ? $s['tipo_split'] : 'percentual';
        $taxa        = max(0, (float)($s['taxa_split'] ?? 0));
        $chave       = trim($s['chave_pix_split'] ?? '');
        $descricao   = substr(trim($s['descricao'] ?? ''), 0, 100);

        if ($chave === '') continue;

        $pdo->prepare(
            "INSERT INTO usuarios_splits (id_usuario, gateway_nome, tipo_split, taxa_split, chave_pix_split, descricao, ordem)
             VALUES (?,?,?,?,?,?,?)"
        )->execute([$user_id, $gateway_nome, $tipo, $taxa, $chave, $descricao, $ordem]);
        $ordem++;
    }

    $pdo->commit();
    echo json_encode(['sucesso' => true]);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['sucesso' => false, 'erro' => 'Erro ao salvar: ' . $e->getMessage()]);
}
