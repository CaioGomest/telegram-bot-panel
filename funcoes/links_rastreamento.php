<?php
declare(strict_types=1);

function listarLinksRastreamento(int $usuario_id): array
{
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT lr.*, b.nome_usuario AS bot_username, b.primeiro_nome AS bot_nome
        FROM links_rastreamento lr
        JOIN bots b ON lr.bot_id = b.id
        WHERE lr.id_usuario = ?
        ORDER BY lr.criado_em DESC
    ");
    $stmt->execute([$usuario_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function obterLinkRastreamento(int $usuario_id, int $id): array|false
{
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT lr.*, b.nome_usuario AS bot_username, b.primeiro_nome AS bot_nome
        FROM links_rastreamento lr
        JOIN bots b ON lr.bot_id = b.id
        WHERE lr.id = ? AND lr.id_usuario = ?
    ");
    $stmt->execute([$id, $usuario_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function criarLinkRastreamento(int $usuario_id, string $titulo, string $identificador, int $bot_id): array
{
    global $pdo;

    $stmt_bot = $pdo->prepare("SELECT id, nome_usuario FROM bots WHERE id = ? AND id_usuario = ?");
    $stmt_bot->execute([$bot_id, $usuario_id]);
    if (!$stmt_bot->fetchColumn()) {
        return ['sucesso' => false, 'mensagem' => 'Bot não encontrado ou sem permissão.'];
    }

    $stmt_check = $pdo->prepare("SELECT id FROM links_rastreamento WHERE id_usuario = ? AND identificador = ?");
    $stmt_check->execute([$usuario_id, $identificador]);
    if ($stmt_check->fetchColumn()) {
        return ['sucesso' => false, 'mensagem' => 'Identificador já em uso. Escolha outro.'];
    }

    $stmt = $pdo->prepare("
        INSERT INTO links_rastreamento (id_usuario, titulo, identificador, bot_id)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$usuario_id, $titulo, $identificador, $bot_id]);

    return ['sucesso' => true, 'id' => (int) $pdo->lastInsertId(), 'mensagem' => 'Link criado com sucesso.'];
}

function editarLinkRastreamento(int $usuario_id, int $id, string $titulo, string $identificador, int $bot_id): array
{
    global $pdo;

    $stmt_bot = $pdo->prepare("SELECT id FROM bots WHERE id = ? AND id_usuario = ?");
    $stmt_bot->execute([$bot_id, $usuario_id]);
    if (!$stmt_bot->fetchColumn()) {
        return ['sucesso' => false, 'mensagem' => 'Bot não encontrado ou sem permissão.'];
    }

    $stmt_check = $pdo->prepare("SELECT id FROM links_rastreamento WHERE id_usuario = ? AND identificador = ? AND id != ?");
    $stmt_check->execute([$usuario_id, $identificador, $id]);
    if ($stmt_check->fetchColumn()) {
        return ['sucesso' => false, 'mensagem' => 'Identificador já em uso. Escolha outro.'];
    }

    $stmt = $pdo->prepare("
        UPDATE links_rastreamento SET titulo = ?, identificador = ?, bot_id = ?
        WHERE id = ? AND id_usuario = ?
    ");
    $stmt->execute([$titulo, $identificador, $bot_id, $id, $usuario_id]);

    if ($stmt->rowCount() === 0) {
        return ['sucesso' => false, 'mensagem' => 'Link não encontrado.'];
    }

    return ['sucesso' => true, 'mensagem' => 'Link atualizado com sucesso.'];
}

function excluirLinkRastreamento(int $usuario_id, int $id): array
{
    global $pdo;
    $stmt = $pdo->prepare("DELETE FROM links_rastreamento WHERE id = ? AND id_usuario = ?");
    $stmt->execute([$id, $usuario_id]);

    if ($stmt->rowCount() === 0) {
        return ['sucesso' => false, 'mensagem' => 'Link não encontrado.'];
    }

    return ['sucesso' => true, 'mensagem' => 'Link excluído com sucesso.'];
}

function gerarUrlLink(string $bot_username, string $identificador): string
{
    return 'https://t.me/' . ltrim($bot_username, '@') . '?start=' . urlencode($identificador);
}
