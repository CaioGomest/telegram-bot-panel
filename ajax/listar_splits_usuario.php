<?php
declare(strict_types=1);
require_once __DIR__ . '/../funcoes/usuario.php';
require_once __DIR__ . '/../funcoes/gateways.php';
verificarAdmin();

header('Content-Type: application/json; charset=utf-8');

$user_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$user_id) {
    echo json_encode(['erro' => 'ID inválido.']);
    exit;
}

// gateway_nome é opcional só por retrocompatibilidade de chamada antiga (sem filtro,
// devolve todos os gateways misturados); a UI de admin/usuarios.php sempre manda esse
// parâmetro agora, um por aba de gateway.
$gateway_nome = trim((string)($_GET['gateway_nome'] ?? ''));

global $pdo;
if ($gateway_nome !== '' && in_array($gateway_nome, gatewaysSuportados(), true)) {
    $stmt = $pdo->prepare("SELECT * FROM usuarios_splits WHERE id_usuario = ? AND gateway_nome = ? ORDER BY ordem, id");
    $stmt->execute([$user_id, $gateway_nome]);
} else {
    $stmt = $pdo->prepare("SELECT * FROM usuarios_splits WHERE id_usuario = ? ORDER BY gateway_nome, ordem, id");
    $stmt->execute([$user_id]);
}
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
