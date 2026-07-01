<?php
declare(strict_types=1);

function listarLinksRastreamento(int $usuarioId): array
{
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT lr.*, b.nome_usuario AS bot_username, b.primeiro_nome AS bot_nome
        FROM links_rastreamento lr
        JOIN bots b ON lr.bot_id = b.id
        WHERE lr.id_usuario = ?
        ORDER BY lr.criado_em DESC
    ");
    $stmt->execute([$usuarioId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function obterLinkRastreamento(int $usuarioId, int $id): array|false
{
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT lr.*, b.nome_usuario AS bot_username, b.primeiro_nome AS bot_nome
        FROM links_rastreamento lr
        JOIN bots b ON lr.bot_id = b.id
        WHERE lr.id = ? AND lr.id_usuario = ?
    ");
    $stmt->execute([$id, $usuarioId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function criarLinkRastreamento(int $usuarioId, string $titulo, string $identificador, int $botId): array
{
    global $pdo;

    $stmtBot = $pdo->prepare("SELECT id, nome_usuario FROM bots WHERE id = ? AND id_usuario = ?");
    $stmtBot->execute([$botId, $usuarioId]);
    if (!$stmtBot->fetchColumn()) {
        return ['sucesso' => false, 'mensagem' => 'Bot não encontrado ou sem permissão.'];
    }

    $stmtCheck = $pdo->prepare("SELECT id FROM links_rastreamento WHERE id_usuario = ? AND identificador = ?");
    $stmtCheck->execute([$usuarioId, $identificador]);
    if ($stmtCheck->fetchColumn()) {
        return ['sucesso' => false, 'mensagem' => 'Identificador já em uso. Escolha outro.'];
    }

    $stmt = $pdo->prepare("
        INSERT INTO links_rastreamento (id_usuario, titulo, identificador, bot_id)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$usuarioId, $titulo, $identificador, $botId]);

    return ['sucesso' => true, 'id' => (int) $pdo->lastInsertId(), 'mensagem' => 'Link criado com sucesso.'];
}

function editarLinkRastreamento(int $usuarioId, int $id, string $titulo, string $identificador, int $botId): array
{
    global $pdo;

    $stmtBot = $pdo->prepare("SELECT id FROM bots WHERE id = ? AND id_usuario = ?");
    $stmtBot->execute([$botId, $usuarioId]);
    if (!$stmtBot->fetchColumn()) {
        return ['sucesso' => false, 'mensagem' => 'Bot não encontrado ou sem permissão.'];
    }

    $stmtCheck = $pdo->prepare("SELECT id FROM links_rastreamento WHERE id_usuario = ? AND identificador = ? AND id != ?");
    $stmtCheck->execute([$usuarioId, $identificador, $id]);
    if ($stmtCheck->fetchColumn()) {
        return ['sucesso' => false, 'mensagem' => 'Identificador já em uso. Escolha outro.'];
    }

    $stmt = $pdo->prepare("
        UPDATE links_rastreamento SET titulo = ?, identificador = ?, bot_id = ?
        WHERE id = ? AND id_usuario = ?
    ");
    $stmt->execute([$titulo, $identificador, $botId, $id, $usuarioId]);

    if ($stmt->rowCount() === 0) {
        return ['sucesso' => false, 'mensagem' => 'Link não encontrado.'];
    }

    return ['sucesso' => true, 'mensagem' => 'Link atualizado com sucesso.'];
}

function excluirLinkRastreamento(int $usuarioId, int $id): array
{
    global $pdo;
    $stmt = $pdo->prepare("DELETE FROM links_rastreamento WHERE id = ? AND id_usuario = ?");
    $stmt->execute([$id, $usuarioId]);

    if ($stmt->rowCount() === 0) {
        return ['sucesso' => false, 'mensagem' => 'Link não encontrado.'];
    }

    return ['sucesso' => true, 'mensagem' => 'Link excluído com sucesso.'];
}

function gerarUrlLink(string $botUsername, string $identificador): string
{
    return 'https://t.me/' . ltrim($botUsername, '@') . '?start=' . urlencode($identificador);
}
