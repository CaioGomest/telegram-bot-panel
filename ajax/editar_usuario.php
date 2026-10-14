<?php
declare(strict_types=1);
require_once __DIR__ . '/../funcoes/usuario.php';
verificarAdmin();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['sucesso' => false, 'erro' => 'Método inválido.']);
    exit;
}
verificarCsrf();

$id     = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$nome   = trim($_POST['nome'] ?? '');
$email  = trim($_POST['email'] ?? '');
$senha  = $_POST['senha'] ?? '';
$perfil = $_POST['perfil'] ?? 'usuario';

if (!$id) {
    echo json_encode(['sucesso' => false, 'erro' => 'ID inválido.']);
    exit;
}

if (!in_array($perfil, ['usuario', 'admin'], true)) {
    $perfil = 'usuario';
}

// Rebaixar o ÚLTIMO administrador deixaria o sistema sem admin, e sem admin o instalador (instalacao.php) volta a
// abrir para qualquer visitante. Quem precisa trocar de admin promove o novo antes.
if ($perfil !== 'admin') {
    global $pdo;
    $stmt_perfil = $pdo->prepare("SELECT perfil FROM usuarios WHERE id = ?");
    $stmt_perfil->execute([$id]);
    if ($stmt_perfil->fetchColumn() === 'admin' && (int) $pdo->query("SELECT COUNT(*) FROM usuarios WHERE perfil = 'admin'")->fetchColumn() <= 1) {
        echo json_encode(['sucesso' => false, 'erro' => 'Este é o único administrador. Promova outro usuário a administrador antes de rebaixar este.']);
        exit;
    }
}

$resultado = atualizarPerfilUsuario($id, $nome, $email, $senha ?: null);

if ($resultado['sucesso']) {
    global $pdo;
    $stmt = $pdo->prepare("UPDATE usuarios SET perfil = ? WHERE id = ?");
    $stmt->execute([$perfil, $id]);
    echo json_encode(['sucesso' => true]);
} else {
    echo json_encode($resultado);
}
