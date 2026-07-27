<?php
// Limpar qualquer saída anterior (como warnings do PHP)
ob_start();

require_once __DIR__ . '/../conexao.php';
require_once __DIR__ . '/../funcoes/usuario.php';

// A sessão já é iniciada em usuario.php, mas por segurança verificamos
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Limpa o buffer antes de verificar admin para evitar HTML de erro
ob_clean();

verificarAdmin();

header('Content-Type: application/json; charset=utf-8');

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id) {
    echo json_encode(['erro' => 'ID inválido']);
    exit;
}

$dados = obterDetalhesUsuario($id);

if (empty($dados)) {
    echo json_encode(['erro' => 'Usuário não encontrado']);
    exit;
}

echo json_encode($dados);
