<?php
declare(strict_types=1);
require_once __DIR__ . '/../funcoes/usuario.php';
verificarAdmin();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['sucesso' => false, 'erro' => 'Método inválido.']);
    exit;
}

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

if (!$id) {
    echo json_encode(['sucesso' => false, 'erro' => 'ID inválido.']);
    exit;
}

// Impede que o admin delete a si mesmo
if ($id === (int)$_SESSION['usuario_id']) {
    echo json_encode(['sucesso' => false, 'erro' => 'Você não pode excluir sua própria conta.']);
    exit;
}

global $pdo;
try {
    $stmt = $pdo->prepare("DELETE FROM usuarios WHERE id = ?");
    $stmt->execute([$id]);
    echo json_encode(['sucesso' => true]);
} catch (PDOException $e) {
    error_log("Erro ao deletar usuário: " . $e->getMessage());
    echo json_encode(['sucesso' => false, 'erro' => 'Erro ao excluir usuário.']);
}
