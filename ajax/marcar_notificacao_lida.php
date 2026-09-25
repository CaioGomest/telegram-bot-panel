<?php
declare(strict_types=1);

require_once __DIR__ . '/../funcoes/usuario.php';
require_once __DIR__ . '/../funcoes/log.php';

header('Content-Type: application/json; charset=utf-8');

if (!usuarioLogado()) {
    http_response_code(401);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Não autorizado.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Método inválido.']);
    exit;
}

verificarCsrf();

$id_usuario = (int) $_SESSION['usuario_id'];
$dados = $_POST;
$tipo_conteudo = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($tipo_conteudo, 'application/json') !== false) {
    $bruto = file_get_contents('php://input');
    $decodificado = json_decode($bruto ?: '{}', true);
    if (is_array($decodificado)) {
        $dados = $decodificado;
    }
}

$id = isset($dados['id']) ? (int) $dados['id'] : 0;
$marcadas = marcarAtividadesLidas($id_usuario, $id > 0 ? $id : null);

echo json_encode([
    'sucesso'  => true,
    'marcadas' => $marcadas,
], JSON_UNESCAPED_UNICODE);
