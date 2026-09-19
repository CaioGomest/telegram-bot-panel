<?php
declare(strict_types=1);

/**
 * A campanha que a tela deve mostrar, e em que estado ela está.
 *
 * Existe UMA campanha em cartaz por vez. A escolha segue esta ordem, e o primeiro que
 * existir ganha:
 *
 *   1. a que está rodando agora          -> estado 'ativa'
 *   2. senão, a última que já terminou   -> estado 'encerrada'
 *   3. senão, a próxima agendada         -> estado 'agendada'
 *
 * O passo 2 é o ponto importante. Antes a busca exigia `NOW() BETWEEN data_inicio AND
 * data_fim`, então a campanha sumia da tela no segundo em que acabava -- justamente quando
 * todo mundo quer ver quem ganhou. Agora o resultado final fica no ar até a próxima campanha
 * começar (ou até o admin desativar essa).
 *
 * `ativa` na tabela é o interruptor do admin ("essa campanha conta?"), não tem nada a ver
 * com estar em andamento -- quem diz isso são as datas.
 */
function buscarCampanhaVigente(): ?array
{
    global $pdo;

    // Cada consulta pega um dos três casos, na ordem de prioridade acima.
    $tentativas = [
        ['ativa',      "data_inicio <= NOW() AND data_fim >= NOW()", "data_fim ASC"],
        ['encerrada',  "data_fim < NOW()",                           "data_fim DESC"],
        ['agendada',   "data_inicio > NOW()",                        "data_inicio ASC"],
    ];

    foreach ($tentativas as [$estado, $condicao, $ordem]) {
        $stmt = $pdo->prepare("SELECT * FROM campanhas_ranking WHERE ativa = 1 AND $condicao ORDER BY $ordem LIMIT 1");
        $stmt->execute();
        $campanha = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($campanha) {
            $campanha['estado'] = $estado;
            return $campanha;
        }
    }
    return null;
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
