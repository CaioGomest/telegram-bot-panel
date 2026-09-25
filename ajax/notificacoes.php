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

$id_usuario = (int) $_SESSION['usuario_id'];
$tipos = tiposNotificacao();
$filtros = [
    'id_usuario' => $id_usuario,
    'tipos_in'   => $tipos,
];

$itens_brutos = listarAtividades($filtros, 15, 0);
$nao_lidas = contarAtividades($filtros + ['apenas_nao_lidas' => true]);

$itens = [];
foreach ($itens_brutos as $linha) {
    $itens[] = [
        'id'        => (int) $linha['id'],
        'tipo'      => (string) $linha['tipo'],
        'titulo'    => (string) ($linha['titulo'] ?? ''),
        'descricao' => (string) ($linha['descricao'] ?? ''),
        'criado_em' => (string) ($linha['criado_em'] ?? ''),
        'tempo'     => tempoRelativoAtividade((string) ($linha['criado_em'] ?? '')),
        'lido'      => !empty($linha['lido_em']),
    ];
}

echo json_encode([
    'sucesso'   => true,
    'nao_lidas' => $nao_lidas,
    'itens'     => $itens,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
