<?php
declare(strict_types=1);

/**
 * Campanha ativa de um tipo (`oficial` ou `mensal`). O ranking em si vem de
 * `ranking_cache`, recalculado periodicamente por cron/cron_ranking.php — esta
 * função nunca agrega `vendas` diretamente.
 */
function buscarCampanhaAtiva(string $tipo = 'oficial'): ?array
{
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT * FROM campanhas_ranking
        WHERE tipo = ? AND ativa = 1 AND NOW() BETWEEN data_inicio AND data_fim
        ORDER BY data_inicio DESC
        LIMIT 1
    ");
    $stmt->execute([$tipo]);
    $campanha = $stmt->fetch(PDO::FETCH_ASSOC);
    return $campanha ?: null;
}

function buscarPremiosCampanha(int $campanha_id): array
{
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM campanhas_ranking_premios WHERE campanha_id = ? ORDER BY posicao ASC");
    $stmt->execute([$campanha_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function nomeExibicaoRanking(?string $apelido, int $id_usuario): string
{
    $apelido = trim((string) $apelido);
    return $apelido !== '' ? $apelido : "Usuário #$id_usuario";
}

function iniciaisRanking(string $nome_exibicao): string
{
    $limpo = ltrim($nome_exibicao, '@');
    return mb_strtoupper(mb_substr($limpo, 0, 2), 'UTF-8') ?: '?';
}

/**
 * Pódio (top 3), linhas 4–10 e a posição do usuário logado, todas lidas de
 * `ranking_cache` (join com `usuarios` só pro apelido público — nunca nome
 * real/e-mail aparecem nessa tela pros outros usuários verem).
 */
function buscarRankingCampanha(int $campanha_id, int $usuario_atual_id): array
{
    global $pdo;

    $stmt = $pdo->prepare("
        SELECT rc.posicao, rc.id_usuario, rc.faturamento, u.apelido_publico
        FROM ranking_cache rc
        JOIN usuarios u ON u.id = rc.id_usuario
        WHERE rc.campanha_id = ?
        ORDER BY rc.posicao ASC
        LIMIT 10
    ");
    $stmt->execute([$campanha_id]);
    $linhas_brutas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $top3 = array_slice($linhas_brutas, 0, 3);
    $linhas = array_slice($linhas_brutas, 3, 7);
    $faturamento_lider = $top3[0]['faturamento'] ?? null;
    $faturamento_top5 = $linhas_brutas[4]['faturamento'] ?? ($linhas_brutas[count($linhas_brutas) - 1]['faturamento'] ?? null);

    $stmt_total = $pdo->prepare("SELECT COUNT(*) FROM ranking_cache WHERE campanha_id = ?");
    $stmt_total->execute([$campanha_id]);
    $total_participantes = (int) $stmt_total->fetchColumn();

    $stmt_eu = $pdo->prepare("
        SELECT rc.posicao, rc.id_usuario, rc.faturamento, u.apelido_publico
        FROM ranking_cache rc
        JOIN usuarios u ON u.id = rc.id_usuario
        WHERE rc.campanha_id = ? AND rc.id_usuario = ?
    ");
    $stmt_eu->execute([$campanha_id, $usuario_atual_id]);
    $sua_posicao = $stmt_eu->fetch(PDO::FETCH_ASSOC) ?: null;

    return [
        'top3' => $top3,
        'linhas' => $linhas,
        'sua_posicao' => $sua_posicao,
        'total_participantes' => $total_participantes,
        'faturamento_lider' => $faturamento_lider !== null ? (float) $faturamento_lider : null,
        'faturamento_top5' => $faturamento_top5 !== null ? (float) $faturamento_top5 : null,
    ];
}

function formatarReaisResumido(float $valor): string
{
    if ($valor >= 1000000) {
        return 'R$ ' . number_format($valor / 1000000, 1, ',', '.') . ' mi';
    }
    if ($valor >= 1000) {
        return 'R$ ' . number_format($valor / 1000, 1, ',', '.') . ' mil';
    }
    return 'R$ ' . number_format($valor, 2, ',', '.');
}
