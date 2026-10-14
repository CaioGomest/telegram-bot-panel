<?php
declare(strict_types=1);

/**
 * Classificação de UMA campanha de ranking (admin): quem está ganhando agora / quem ganhou.
 * Lê do ranking_cache, que o cron do ranking recalcula a cada minuto e congela no fim da campanha.
 * O admin vê nome e e-mail reais; o vendedor só vê o apelido público (na tela Ranking dele).
 */

require_once __DIR__ . '/../funcoes/usuario.php';
require_once __DIR__ . '/../funcoes/ranking.php';
require_once __DIR__ . '/../funcoes/paginador.php';
verificarAdmin();
$caminho_base = '../';

$campanha = null;
$stmt = $pdo->prepare("SELECT * FROM campanhas_ranking WHERE id = ?");
$stmt->execute([(int) ($_GET['id'] ?? 0)]);
$campanha = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

if ($campanha) {
    $campanha_id = (int) $campanha['id'];
    $sit = situacaoCampanhaRanking($campanha);

    $premios = [];
    $stmt = $pdo->prepare("SELECT posicao, titulo, descricao FROM campanhas_ranking_premios WHERE campanha_id = ? ORDER BY posicao");
    $stmt->execute([$campanha_id]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $premios[(int) $p['posicao']] = $p;
    }
    // Posições "premiadas": as que têm prêmio cadastrado; sem prêmio nenhum, o pódio (1º ao 3º).
    $posicoes_premiadas = $premios ? array_keys($premios) : [1, 2, 3];

    $stmt = $pdo->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(faturamento), 0) AS soma, MAX(atualizado_em) AS ultima FROM ranking_cache WHERE campanha_id = ?");
    $stmt->execute([$campanha_id]);
    $totais = $stmt->fetch(PDO::FETCH_ASSOC);

    // Exportação do ranking completo (posição, vendedor, e-mail, apelido, faturamento, prêmio). O botão está COMENTADO na tela
    // (decisão de 13/10/2026); a rota continua funcionando em ?id=ID&exportar=csv caso queiram religar.
    if (($_GET['exportar'] ?? '') === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=ranking-' . preg_replace('/[^A-Za-z0-9_-]/', '', (string) $campanha['slug']) . '.csv');
        $saida = fopen('php://output', 'w');
        fwrite($saida, "\xEF\xBB\xBF");   // BOM: o Excel abre os acentos certos
        fputcsv($saida, ['Posicao', 'Vendedor', 'E-mail', 'Apelido publico', 'Faturamento', 'Premio']);
        $stmt = $pdo->prepare("SELECT rc.posicao, rc.faturamento, u.nome, u.email, u.apelido_publico
            FROM ranking_cache rc JOIN usuarios u ON u.id = rc.id_usuario
            WHERE rc.campanha_id = ? ORDER BY rc.posicao ASC, rc.faturamento DESC, u.id ASC", [PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false]);
        $stmt->execute([$campanha_id]);
        while ($l = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($saida, [(int) $l['posicao'], csvSeguro($l['nome']), csvSeguro($l['email']), csvSeguro((string) $l['apelido_publico']),
                number_format((float) $l['faturamento'], 2, ',', ''), csvSeguro($premios[(int) $l['posicao']]['titulo'] ?? '')]);
        }
        fclose($saida);
        exit;
    }

    // Filtros: aba (todos / premiados) e busca por nome, e-mail ou apelido.
    $aba = ($_GET['aba'] ?? '') === 'premiados' ? 'premiados' : 'todos';
    $busca = trim((string) ($_GET['busca'] ?? ''));
    $where = 'rc.campanha_id = ?';
    $params = [$campanha_id];
    if ($aba === 'premiados') {
        $where .= ' AND rc.posicao IN (' . implode(',', array_map('intval', $posicoes_premiadas)) . ')';
    }
    if ($busca !== '') {
        $where .= ' AND (u.nome LIKE ? OR u.email LIKE ? OR u.apelido_publico LIKE ?)';
        $like = '%' . $busca . '%';
        array_push($params, $like, $like, $like);
    }
    $por_pagina = 25;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM ranking_cache rc JOIN usuarios u ON u.id = rc.id_usuario WHERE $where");
    $stmt->execute($params);
    $total = (int) $stmt->fetchColumn();
    $paginas = max(1, (int) ceil($total / $por_pagina));
    $pagina = min($paginas, max(1, (int) ($_GET['pagina'] ?? 1)));
    $offset = ($pagina - 1) * $por_pagina;
    $stmt = $pdo->prepare("SELECT rc.posicao, rc.faturamento, u.id AS id_usuario, u.nome, u.email, u.apelido_publico
        FROM ranking_cache rc JOIN usuarios u ON u.id = rc.id_usuario
        WHERE $where ORDER BY rc.posicao ASC, rc.faturamento DESC, u.id ASC LIMIT $por_pagina OFFSET $offset");
    $stmt->execute($params);
    $linhas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Estado do placar, em palavras.
    if (!empty($campanha['finalizada_em'])) {
        $placar = ['Final', 'Placar fechado em ' . date('d/m/y H:i', strtotime($campanha['finalizada_em']))];
    } elseif (!$campanha['ativa']) {
        $placar = ['Campanha desligada', 'O placar não é recalculado enquanto ela estiver desligada'];
    } elseif (strtotime($campanha['data_fim']) < time()) {
        $placar = ['Aguardando o cron', 'O cron do ranking fecha o placar final na próxima rodada'];
    } elseif (strtotime($campanha['data_inicio']) > time()) {
        $placar = ['Ainda não começou', 'Começa em ' . date('d/m/y H:i', strtotime($campanha['data_inicio']))];
    } else {
        $placar = ['Parcial', 'O cron recalcula a cada minuto' . (!empty($totais['ultima']) ? ' · última vez às ' . date('H:i', strtotime($totais['ultima'])) : '')];
    }
}

function urlClassificacao(array $base, array $extra): string
{
    return '?' . http_build_query(array_filter(array_merge($base, $extra), fn($v) => $v !== '' && $v !== null));
}
$medalhas = [1 => '🥇', 2 => '🥈', 3 => '🥉'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Classificação<?php echo $campanha ? ' — ' . htmlspecialchars($campanha['titulo']) : ''; ?> - <?php echo htmlspecialchars(nomeSistema()); ?></title>
    <?php include __DIR__ . '/../tema_inline.php'; ?>
    <link rel="stylesheet" href="../assets/css/painel.css?v=<?php echo @filemtime(__DIR__.'/../assets/css/painel.css'); ?>">
</head>
<body>
<div class="layout-painel">
    <?php include __DIR__ . '/../barra_lateral.php'; ?>

    <main class="conteudo-principal">
        <div class="cabecalho-pagina">
            <div>
                <h1><?php echo $campanha ? 'Classificação — ' . htmlspecialchars($campanha['titulo']) : 'Campanha não encontrada'; ?></h1>
                <p><a href="ranking">‹ Voltar para Campanhas de Ranking</a></p>
            </div>
            <div class="acoes-cabecalho">
                <?php /* Exportar CSV — comentado a pedido (13/10/2026). Para religar, tire o comentário:
                <?php if ($campanha && (int) $totais['n'] > 0): ?>
                    <a class="botao" href="ranking_classificacao?id=<?php echo $campanha_id; ?>&amp;exportar=csv">Exportar CSV</a>
                <?php endif; ?>
                */ ?>
                <button type="button" class="alternador-tema" onclick="alternarTema()" aria-label="Alternar tema">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"></path></svg>
                    Tema
                </button>
            </div>
        </div>

        <?php if (!$campanha): ?>
            <div class="painel">
                <div style="text-align:center;padding:32px;" class="texto-suave">
                    <div style="margin-bottom:10px; font-weight:600;">Campanha não encontrada</div>
                    <div>Ela pode ter sido removida.</div>
                </div>
            </div>
        <?php else: ?>
            <div class="grade-kpi grade-kpi-empilha">
                <div class="cartao-kpi">
                    <div class="cartao-kpi-cabecalho"><span class="rotulo-kpi">Participantes</span></div>
                    <div class="valor-kpi"><?php echo number_format((int) $totais['n'], 0, ',', '.'); ?></div>
                    <div class="texto-suave">vendedores com venda paga no período</div>
                </div>
                <div class="cartao-kpi">
                    <div class="cartao-kpi-cabecalho"><span class="rotulo-kpi">Faturamento somado</span></div>
                    <div class="valor-kpi">R$ <?php echo number_format((float) $totais['soma'], 2, ',', '.'); ?></div>
                    <div class="texto-suave"><?php echo date('d/m/y', strtotime($campanha['data_inicio'])); ?> — <?php echo date('d/m/y', strtotime($campanha['data_fim'])); ?></div>
                </div>
                <div class="cartao-kpi">
                    <div class="cartao-kpi-cabecalho"><span class="rotulo-kpi">Placar</span> <span class="badge <?php echo $sit[1]; ?>" style="margin-left:auto;"><?php echo $sit[0]; ?></span></div>
                    <div class="valor-kpi" style="font-size:22px;"><?php echo htmlspecialchars($placar[0]); ?></div>
                    <div class="texto-suave"><?php echo htmlspecialchars($placar[1]); ?></div>
                </div>
            </div>

            <div class="barra-filtros">
                <form method="GET" class="campo-busca">
                    <input type="hidden" name="id" value="<?php echo $campanha_id; ?>">
                    <?php if ($aba !== 'todos'): ?><input type="hidden" name="aba" value="<?php echo htmlspecialchars($aba); ?>"><?php endif; ?>
                    <button type="submit" aria-label="Buscar">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"></circle><path d="M20 20l-4-4"></path></svg>
                    </button>
                    <input type="text" name="busca" value="<?php echo htmlspecialchars($busca); ?>" placeholder="Buscar por nome, e-mail ou apelido">
                </form>
                <div class="abas-status">
                    <a href="<?php echo urlClassificacao(['id' => $campanha_id, 'busca' => $busca], ['aba' => '']); ?>" class="aba-status<?php echo $aba === 'todos' ? ' ativa' : ''; ?>">Todos</a>
                    <a href="<?php echo urlClassificacao(['id' => $campanha_id, 'busca' => $busca], ['aba' => 'premiados']); ?>" class="aba-status<?php echo $aba === 'premiados' ? ' ativa' : ''; ?>"><?php echo $premios ? 'Premiados' : 'Pódio'; ?></a>
                </div>
            </div>

            <div class="painel">
                <div class="tabela-dados">
                    <table class="tabela-classificacao">
                        <thead>
                            <tr>
                                <th>Posição</th>
                                <th>Vendedor</th>
                                <th>Apelido público</th>
                                <th class="col-numerica">Faturamento</th>
                                <th>Prêmio</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($linhas)): ?>
                                <tr><td colspan="5" style="text-align:center;padding:20px;color:var(--m);">
                                    <?php echo (int) $totais['n'] === 0 ? 'Ainda não há classificação: nenhuma venda paga no período, ou o cron do ranking ainda não rodou para esta campanha.' : 'Ninguém encontrado com esse filtro.'; ?>
                                </td></tr>
                            <?php else: foreach ($linhas as $l): $pos = (int) $l['posicao']; $premio = $premios[$pos] ?? null; ?>
                                <tr<?php echo $premio ? ' class="premiada"' : ''; ?>>
                                    <td class="mono"><?php echo ($medalhas[$pos] ?? '') . ' ' . $pos . 'º'; ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($l['nome']); ?>
                                        <div class="celula-sub"><?php echo htmlspecialchars($l['email']); ?></div>
                                    </td>
                                    <td class="texto-suave"><?php echo htmlspecialchars((string) ($l['apelido_publico'] ?: '—')); ?></td>
                                    <td class="mono col-numerica">R$ <?php echo number_format((float) $l['faturamento'], 2, ',', '.'); ?></td>
                                    <td>
                                        <?php if ($premio): ?>
                                            <span class="badge badge-sucesso"><?php echo htmlspecialchars($premio['titulo']); ?></span>
                                            <?php if (!empty($premio['descricao'])): ?><div class="celula-sub"><?php echo htmlspecialchars($premio['descricao']); ?></div><?php endif; ?>
                                        <?php else: ?>
                                            <span class="texto-suave">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php echo paginador($total, $por_pagina); ?>
            </div>
        <?php endif; ?>
    </main>
</div>

<script src="../assets/js/tema.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/tema.js'); ?>"></script>
</body>
</html>
