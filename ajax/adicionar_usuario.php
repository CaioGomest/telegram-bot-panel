<?php
declare(strict_types=1);
require_once __DIR__ . '/../funcoes/usuario.php';
verificarAdmin();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['sucesso' => false, 'erro' => 'Método inválido.']);
    exit;
}

$nome   = trim($_POST['nome'] ?? '');
$email  = trim($_POST['email'] ?? '');
$senha  = $_POST['senha'] ?? '';
$perfil = $_POST['perfil'] ?? 'usuario';

if (!in_array($perfil, ['usuario', 'admin'], true)) {
    $perfil = 'usuario';
}

$resultado = criarUsuario($nome, $email, $senha);

if ($resultado['sucesso']) {
    if ($perfil === 'admin') {
        global $pdo;
        $stmt = $pdo->prepare("UPDATE usuarios SET perfil = 'admin' WHERE email = ?");
        $stmt->execute([$email]);
    }
    echo json_encode(['sucesso' => true]);
} else {
    echo json_encode($resultado);
}
