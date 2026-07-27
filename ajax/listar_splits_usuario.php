<?php
declare(strict_types=1);
require_once __DIR__ . '/../funcoes/usuario.php';
verificarAdmin();

header('Content-Type: application/json; charset=utf-8');

$user_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$user_id) {
    echo json_encode(['erro' => 'ID inválido.']);
    exit;
}

global $pdo;
$stmt = $pdo->prepare("SELECT * FROM usuarios_splits WHERE id_usuario = ? ORDER BY gateway_nome, ordem, id");
$stmt->execute([$user_id]);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
