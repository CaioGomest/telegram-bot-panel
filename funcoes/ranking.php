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
        $stmt = $pdo->prepare("SELECT * FROM campanhas_ranking WHERE ativa = 1 AND arquivada_em IS NULL AND $condicao ORDER BY $ordem LIMIT 1");
        $stmt->execute();
        $campanha = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($campanha) {
            $campanha['estado'] = $estado;
            return $campanha;
        }
    }
    return null;
}
/**
 * Situação da campanha, lida como a tela do usuário lê: quem diz se está rolando são as datas; `ativa` é só o interruptor do admin.
 * @return array{0:string,1:string} [rótulo, classe do badge]
 */
function situacaoCampanhaRanking(array $c): array
{
    $agora = time();
    if (!empty($c['arquivada_em']))            { return ['Arquivada', 'badge-neutro']; }
    if (!$c['ativa'])                          { return ['Desligada', 'badge-neutro']; }
    if (strtotime($c['data_inicio']) > $agora) { return ['Agendada', 'badge-alerta']; }
    if (strtotime($c['data_fim']) < $agora)    { return ['Encerrada', 'badge-neutro']; }
    return ['Em andamento', 'badge-sucesso'];
}

function buscarPremiosCampanha(int $campanha_id): array
{
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM campanhas_ranking_premios WHERE campanha_id = ? ORDER BY posicao ASC");
    $stmt->execute([$campanha_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** A próxima campanha agendada (ativa e que ainda não começou), pra avisar na tela do resultado final. */
function buscarProximaCampanha(): ?array
{
    global $pdo;
    $stmt = $pdo->query("SELECT id, titulo, data_inicio FROM campanhas_ranking WHERE ativa = 1 AND arquivada_em IS NULL AND data_inicio > NOW() ORDER BY data_inicio ASC LIMIT 1");
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Quem ficou em cada posição premiada (posição => lista de apelidos; empate = mais de um nome na mesma posição).
 * Lê do ranking_cache, que na campanha fechada guarda o placar final.
 */
function buscarGanhadoresCampanha(int $campanha_id, int $ate_posicao): array
{
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT rc.posicao, rc.id_usuario, u.apelido_publico
        FROM ranking_cache rc
        JOIN usuarios u ON u.id = rc.id_usuario
        WHERE rc.campanha_id = ? AND rc.posicao <= ?
        ORDER BY rc.posicao ASC, rc.faturamento DESC
        LIMIT 30
    ");
    $stmt->execute([$campanha_id, max(1, $ate_posicao)]);
    $por_posicao = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $por_posicao[(int) $l['posicao']][] = nomeExibicaoRanking($l['apelido_publico'], (int) $l['id_usuario']);
    }
    return $por_posicao;
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
 * Trecho de SELECT que traz a foto de perfil. Banco que ainda não rodou a migração da coluna `foto_perfil`
 * devolve NULL (o avatar cai nas iniciais) em vez de derrubar o ranking inteiro. `$agregado` serve às
 * consultas com GROUP BY, onde a coluna precisa de função de agregação.
 */
function colunaFotoRanking(bool $agregado = false): string
{
    if (!function_exists('colunaFotoPerfilDisponivel') || !colunaFotoPerfilDisponivel()) {
        return 'NULL AS foto_perfil';
    }
    return $agregado ? 'MAX(u.foto_perfil) AS foto_perfil' : 'u.foto_perfil';
}

/**
 * Avatar redondo de uma linha do ranking: a foto do usuário e, sem foto, as iniciais do apelido.
 * Quem está no pódio (posição 1 a 3) ganha o aro de medalha (`m1` ouro, `m2` prata, `m3` bronze).
 */
function htmlAvatarRanking(array $linha, string $nome_exibicao, string $classe = 'av'): string
{
    $posicao = (int) ($linha['posicao'] ?? 0);
    if ($posicao >= 1 && $posicao <= 3) {
        $classe .= ' m' . $posicao;
    }
    return htmlAvatarUsuario(iniciaisRanking($nome_exibicao), (string) ($linha['foto_perfil'] ?? ''), $classe);
}

/**
 * Pódio (top 3), linhas 4–10 e a posição do usuário logado, todas lidas de
 * `ranking_cache` (join com `usuarios` só pro apelido público — nunca nome
 * real/e-mail aparecem nessa tela pros outros usuários verem).
 */
function buscarRankingCampanha(int $campanha_id, int $usuario_atual_id, int $corte = 5): array
{
    global $pdo;
    $corte = max(1, min(10, $corte));

    $coluna_foto = colunaFotoRanking();
    $stmt = $pdo->prepare("
        SELECT rc.posicao, rc.id_usuario, rc.faturamento, u.apelido_publico, $coluna_foto
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
    $faturamento_top5 = $linhas_brutas[$corte - 1]['faturamento'] ?? ($linhas_brutas[count($linhas_brutas) - 1]['faturamento'] ?? null);

    $stmt_total = $pdo->prepare("SELECT COUNT(*) FROM ranking_cache WHERE campanha_id = ?");
    $stmt_total->execute([$campanha_id]);
    $total_participantes = (int) $stmt_total->fetchColumn();

    $stmt_eu = $pdo->prepare("
        SELECT rc.posicao, rc.id_usuario, rc.faturamento, u.apelido_publico, $coluna_foto
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

/** Nome do mês em português ("Outubro de 2026"). */
function nomeMesRanking(DateTime $data): string
{
    $meses = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
    return ucfirst($meses[(int) $data->format('n') - 1]) . ' de ' . $data->format('Y');
}

/**
 * Ranking MENSAL: quanto cada usuário faturou (vendas aprovadas) no mês corrente. Não depende de campanha
 * nem de admin -- zera sozinho no dia 1. Lê de metricas_horarias_usuario (a mesma tabela que alimenta o
 * dashboard, preenchida pela cron_metricas_admin.php), então é barato mesmo com milhões de vendas.
 * Devolve o mesmo formato de buscarRankingCampanha() pra tela reaproveitar o mesmo desenho.
 * Empate de faturamento = mesma posição (padrão "1, 2, 2, 4").
 */
function buscarRankingMensal(int $usuario_atual_id, ?DateTime $referencia = null): array
{
    global $pdo;
    $referencia = $referencia ?? new DateTime();
    $inicio = $referencia->format('Y-m-01');
    $fim = $referencia->format('Y-m-t');
    $vazio = ['top3' => [], 'linhas' => [], 'sua_posicao' => null, 'total_participantes' => 0, 'faturamento_lider' => null, 'faturamento_top5' => null];

    try {
        $coluna_foto_agregada = colunaFotoRanking(true);
        $stmt = $pdo->prepare("
            SELECT m.id_usuario, SUM(m.valor_pago) AS faturamento, MAX(u.apelido_publico) AS apelido_publico, $coluna_foto_agregada
            FROM metricas_horarias_usuario m
            JOIN usuarios u ON u.id = m.id_usuario
            WHERE m.data BETWEEN ? AND ? AND u.perfil = 'usuario'
            GROUP BY m.id_usuario
            HAVING SUM(m.valor_pago) > 0
            ORDER BY faturamento DESC, m.id_usuario ASC
            LIMIT 10
        ");
        $stmt->execute([$inicio, $fim]);
        $brutas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $posicao = 0;
        $anterior = null;
        foreach ($brutas as $i => $linha) {
            $fat = (float) $linha['faturamento'];
            if ($anterior === null || $fat < $anterior) {
                $posicao = $i + 1;
            }
            $anterior = $fat;
            $brutas[$i]['posicao'] = $posicao;
            $brutas[$i]['faturamento'] = $fat;
        }

        $stmt_total = $pdo->prepare("
            SELECT COUNT(*) FROM (
                SELECT m.id_usuario FROM metricas_horarias_usuario m
                JOIN usuarios u ON u.id = m.id_usuario
                WHERE m.data BETWEEN ? AND ? AND u.perfil = 'usuario'
                GROUP BY m.id_usuario HAVING SUM(m.valor_pago) > 0
            ) t
        ");
        $stmt_total->execute([$inicio, $fim]);
        $total = (int) $stmt_total->fetchColumn();

        $stmt_eu = $pdo->prepare("SELECT COALESCE(SUM(valor_pago), 0) FROM metricas_horarias_usuario WHERE id_usuario = ? AND data BETWEEN ? AND ?");
        $stmt_eu->execute([$usuario_atual_id, $inicio, $fim]);
        $meu_fat = (float) $stmt_eu->fetchColumn();
        $sua_posicao = null;
        if ($meu_fat > 0) {
            $stmt_pos = $pdo->prepare("
                SELECT COUNT(*) FROM (
                    SELECT m.id_usuario FROM metricas_horarias_usuario m
                    JOIN usuarios u ON u.id = m.id_usuario
                    WHERE m.data BETWEEN ? AND ? AND u.perfil = 'usuario'
                    GROUP BY m.id_usuario HAVING SUM(m.valor_pago) > ?
                ) t
            ");
            $stmt_pos->execute([$inicio, $fim, $meu_fat]);
            $stmt_ap = $pdo->prepare("SELECT apelido_publico, " . colunaFotoRanking() . " FROM usuarios u WHERE u.id = ?");
            $stmt_ap->execute([$usuario_atual_id]);
            $meus_dados = $stmt_ap->fetch(PDO::FETCH_ASSOC) ?: [];
            $sua_posicao = [
                'posicao' => (int) $stmt_pos->fetchColumn() + 1,
                'id_usuario' => $usuario_atual_id,
                'faturamento' => $meu_fat,
                'apelido_publico' => ($meus_dados['apelido_publico'] ?? '') ?: null,
                'foto_perfil' => $meus_dados['foto_perfil'] ?? null,
            ];
        }
    } catch (\Throwable $e) {
        error_log('[ranking] mensal indisponível: ' . $e->getMessage());
        return $vazio;
    }

    $top5 = $brutas[4]['faturamento'] ?? ($brutas ? $brutas[count($brutas) - 1]['faturamento'] : null);
    return [
        'top3' => array_slice($brutas, 0, 3),
        'linhas' => array_slice($brutas, 3, 7),
        'sua_posicao' => $sua_posicao,
        'total_participantes' => $total,
        'faturamento_lider' => $brutas ? (float) $brutas[0]['faturamento'] : null,
        'faturamento_top5' => $top5 !== null ? (float) $top5 : null,
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
