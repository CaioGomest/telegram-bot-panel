<?php
declare(strict_types=1);

require_once __DIR__ . '/../funcoes/usuario.php';
require_once __DIR__ . '/../funcoes/paginador.php';

verificarAdmin();
$caminho_base = '../';

$usuario_id = (int)($_GET['usuario_id'] ?? 0);
$status = trim($_GET['status'] ?? '');
$split_status = trim($_GET['split_status'] ?? '');
$data_inicio = trim($_GET['data_inicio'] ?? '');
$data_fim = trim($_GET['data_fim'] ?? '');
$busca = trim($_GET['busca'] ?? '');

$where = ['1=1'];
$params = [];

if ($usuario_id > 0) {
    $where[] = 'u.id = ?';
    $params[] = $usuario_id;
}
if ($status !== '') {
    $where[] = 'v.status = ?';
    $params[] = $status;
}
if ($busca !== '') {
    $where[] = '(v.transacao_id LIKE ? OR v.id_telegram LIKE ?)';
    $params[] = "%$busca%";
    $params[] = "%$busca%";
}
if ($data_inicio !== '') {
    $where[] = 'v.criado_em >= ?';
    $params[] = $data_inicio . ' 00:00:00';
}
if ($data_fim !== '') {
    $where[] = 'v.criado_em <= ?';
    $params[] = $data_fim . ' 23:59:59';
}
if ($split_status === 'pendente') {
    $where[] = "v.status = 'pago' AND v.split_status IS NULL";
} elseif ($split_status !== '') {
    $where[] = 'v.split_status = ?';
    $params[] = $split_status;
}

$where_sql = implode(' AND ', $where);

$sql_base = "
    FROM vendas v
    JOIN bots b ON v.bot_id = b.id
    JOIN usuarios u ON b.id_usuario = u.id
    LEFT JOIN gateways g ON v.id_gateway = g.id
    LEFT JOIN (
        SELECT id_usuario, SUM(taxa_split) AS soma_pct
        FROM usuarios_splits
        WHERE gateway_nome = 'infopago'
        GROUP BY id_usuario
    ) us ON us.id_usuario = u.id
    WHERE $where_sql
";

// O COUNT não precisa dos LEFT JOINs (gateway/split nunca reduzem linha) nem do JOIN
// com bots/usuarios quando não há filtro por usuário -- só existem ali pra dar suporte
// a filtros que podem nem estar ativos. Sem filtro nenhum (visão padrão), juntar tudo
// pra só contar forçava o MySQL a escanear vendas inteira (testado: full table scan
// mesmo sem WHERE nenhum). Ver anotacoes/analise-potencia-e-escala.md.
if ($usuario_id > 0) {
    $sql_count_base = "FROM vendas v JOIN bots b ON v.bot_id = b.id JOIN usuarios u ON b.id_usuario = u.id WHERE $where_sql";
} else {
    $sql_count_base = "FROM vendas v WHERE $where_sql";
}
if ($where_sql === '1=1') {
    // Visão padrão (sem nenhum filtro): um COUNT(*) exato aqui é full table scan --
    // medido em 2026-09-17 a 6,8 milhões de linhas: 1,57s, escala linear com o total
    // acumulado na plataforma (ver anotacoes/analise-potencia-e-escala.md secao 11).
    // $total_transacoes só alimenta o paginador() (numeração de página), nunca é
    // exibido como "X resultados" -- uma estimativa instantânea das estatísticas do
    // InnoDB é suficiente pra isso, não precisa ser exata.
    $total_transacoes = (int) ($pdo->query("
        SELECT TABLE_ROWS FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendas'
    ")->fetchColumn() ?: 0);
} else {
    // Com filtro ativo, a query já cai num índice (status/criado_em/usuario) e fica
    // rápida mesmo em tabela grande -- COUNT(*) exato aqui não tem o mesmo custo.
    $stmt_total = $pdo->prepare("SELECT COUNT(*) $sql_count_base");
    $stmt_total->execute($params);
    $total_transacoes = (int)$stmt_total->fetchColumn();
}

$pagina_atual = isset($_GET['pagina']) ? max(1, (int)$_GET['pagina']) : 1;
$por_pagina = 25;
$offset = ($pagina_atual - 1) * $por_pagina;

// Busca só os IDs da página atual primeiro (sem os LEFT JOINs caros de gateway/split),
// usando o índice em criado_em -- e só faz o JOIN pesado pras poucas linhas que
// sobraram, não pra tabela inteira. Sem isso, com muitas vendas acumuladas o MySQL
// materializa a junção inteira antes de ordenar (testado: 5,75s -> 0,002s com 1M de
// vendas). Ver anotacoes/analise-potencia-e-escala.md.
$sql_ids = "
    SELECT v.id
    FROM vendas v
    JOIN bots b ON v.bot_id = b.id
    JOIN usuarios u ON b.id_usuario = u.id
    WHERE $where_sql
    ORDER BY v.criado_em DESC
    LIMIT $por_pagina OFFSET $offset
";
$stmt_ids = $pdo->prepare($sql_ids);
$stmt_ids->execute($params);
$ids_pagina = $stmt_ids->fetchAll(PDO::FETCH_COLUMN);

if ($ids_pagina) {
    $placeholders_ids = implode(',', array_fill(0, count($ids_pagina), '?'));
    $sql = "
        SELECT
            v.id, v.valor, v.status, v.transacao_id, v.id_telegram, v.criado_em, v.pago_em,
            v.tipo_cobranca, v.comissao_admin, v.split_status, v.split_em,
            b.id AS id_bot, COALESCE(b.primeiro_nome, b.nome_usuario) AS nome_bot,
            u.id AS id_usuario, u.nome AS nome_usuario,
            g.titulo AS titulo_gateway,
            us.soma_pct
        FROM vendas v
        JOIN bots b ON v.bot_id = b.id
        JOIN usuarios u ON b.id_usuario = u.id
        LEFT JOIN gateways g ON v.id_gateway = g.id
        LEFT JOIN (
            SELECT id_usuario, SUM(taxa_split) AS soma_pct
            FROM usuarios_splits
            WHERE gateway_nome = 'infopago'
            GROUP BY id_usuario
        ) us ON us.id_usuario = u.id
        WHERE v.id IN ($placeholders_ids)
        ORDER BY v.criado_em DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($ids_pagina);
    $transacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $transacoes = [];
}

$splits_por_venda = [];
$venda_ids = array_column($transacoes, 'id');
if (!empty($venda_ids)) {
    $placeholders = implode(',', array_fill(0, count($venda_ids), '?'));
    $stmt_splits = $pdo->prepare("SELECT venda_id, chave_pix, descricao, valor, status FROM vendas_splits WHERE venda_id IN ($placeholders) ORDER BY id");
    $stmt_splits->execute($venda_ids);
    foreach ($stmt_splits->fetchAll(PDO::FETCH_ASSOC) as $linha) {
        $splits_por_venda[$linha['venda_id']][] = $linha;
    }
}

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=transacoes.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Data', 'Usuário', 'Bot', 'Valor', 'Status', 'Gateway', 'TXID', 'Status do Split', 'Detalhe do Split']);

    $sql_export = "
        SELECT
            v.id, v.valor, v.status, v.transacao_id, v.criado_em,
            b.id AS id_bot, COALESCE(b.primeiro_nome, b.nome_usuario) AS nome_bot,
            u.nome AS nome_usuario,
            g.titulo AS titulo_gateway,
            v.split_status
        $sql_base
        ORDER BY v.criado_em DESC
    ";
    $stmt_export = $pdo->prepare($sql_export);
    $stmt_export->execute($params);
    $vendas_export = $stmt_export->fetchAll(PDO::FETCH_ASSOC);

    $splits_export = [];
    $ids_export = array_column($vendas_export, 'id');
    if (!empty($ids_export)) {
        $placeholders = implode(',', array_fill(0, count($ids_export), '?'));
        $stmt_splits_export = $pdo->prepare("SELECT venda_id, chave_pix, descricao, valor, status FROM vendas_splits WHERE venda_id IN ($placeholders) ORDER BY id");
        $stmt_splits_export->execute($ids_export);
        foreach ($stmt_splits_export->fetchAll(PDO::FETCH_ASSOC) as $linha) {
            $splits_export[$linha['venda_id']][] = $linha;
        }
    }

    foreach ($vendas_export as $linha) {
        $detalhe_split = '-';
        if (!empty($splits_export[$linha['id']])) {
            $partes = array_map(function ($s) {
                $nome = $s['descricao'] ?: $s['chave_pix'];
                return "$nome: R$ " . number_format((float)$s['valor'], 2, ',', '.') . " ({$s['status']})";
            }, $splits_export[$linha['id']]);
            $detalhe_split = implode(' | ', $partes);
        }

        fputcsv($output, [
            date('d/m/Y H:i', strtotime($linha['criado_em'])),
            $linha['nome_usuario'],
            $linha['nome_bot'],
            number_format((float)$linha['valor'], 2, ',', '.'),
            $linha['status'],
            $linha['titulo_gateway'] ?? '-',
            $linha['transacao_id'] ?? '-',
            $linha['split_status'] ?? 'pendente',
            $detalhe_split,
        ]);
    }
    fclose($output);
    exit;
}

$usuarios_filtro = $pdo->query('SELECT id, nome FROM usuarios ORDER BY nome')->fetchAll(PDO::FETCH_ASSOC);

function badgeStatusVenda(string $status): string {
    $mapa = [
        'pago' => ['Pago', 'badge-sucesso'],
        'gerado' => ['Aguardando Pix', 'badge-alerta'],
        'cancelado' => ['Cancelado', 'badge-neutro'],
        'expirado' => ['Expirado', 'badge-neutro'],
    ];
    [$texto, $classe] = $mapa[$status] ?? [htmlspecialchars(ucfirst($status)), 'badge-neutro'];
    return "<span class=\"badge $classe\">$texto</span>";
}

function celulaSplit(array $venda, array $linhas_split): string {
    if ($venda['status'] !== 'pago') {
        return '<span class="badge badge-neutro">—</span>';
    }

    if ($venda['split_status'] === null) {
        $html = '<span class="badge badge-alerta">Pendente</span>';
        if ($venda['soma_pct'] !== null) {
            $valor_esperado = round((float)$venda['valor'] * ((float)$venda['soma_pct'] / 100), 2);
            $html .= '<div class="valor-esperado">≈ R$ ' . number_format($valor_esperado, 2, ',', '.') . '</div>';
        }
        return $html;
    }

    $mapa_resumo = [
        'pago' => ['Pago', 'badge-sucesso'],
        'falhou' => ['Falhou', 'badge-perigo'],
        'parcial' => ['Incompleto', 'badge-alerta'],
        'sem_split' => ['Sem split configurado', 'badge-neutro'],
        'sem_credenciais' => ['Sem credenciais de Cash-Out', 'badge-alerta'],
    ];
    [$texto_resumo, $classe_resumo] = $mapa_resumo[$venda['split_status']] ?? [htmlspecialchars((string) $venda['split_status']), 'badge-neutro'];
    $html = "<span class=\"badge $classe_resumo\">$texto_resumo</span>";

    foreach ($linhas_split as $linha) {
        $ok = $linha['status'] === 'pago';
        $classe = $ok ? 'badge-sucesso' : 'badge-perigo';
        $texto = $ok ? 'Pago' : 'Falhou';
        $nome = htmlspecialchars($linha['descricao'] ?: $linha['chave_pix']);
        $valor = number_format((float)$linha['valor'], 2, ',', '.');
        $html .= "<div class=\"celula-sub\"><span class=\"badge $classe\">$texto</span> $nome — R$ $valor</div>";
    }

    return $html;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transações - Painel Admin</title>
    <?php include __DIR__ . '/../tema_inline.php'; ?>
    <link rel="stylesheet" href="../assets/css/coyote.css?v=<?php echo @filemtime(__DIR__.'/../assets/css/coyote.css'); ?>">
</head>
<body>
<div class="layout-painel">
    <?php include __DIR__ . '/../barra_lateral.php'; ?>

    <main class="conteudo-principal">
        <div class="cabecalho-pagina">
            <div>
                <h1>Transações</h1>
                <p>Histórico de todas as vendas da plataforma, de todos os usuários, com status do split.</p>
            </div>
            <div class="acoes-cabecalho">
                <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>" class="botao">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                    Exportar CSV
                </a>
                <button type="button" class="alternador-tema" onclick="alternarTema()" aria-label="Alternar tema">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"></path></svg>
                    Tema
                </button>
            </div>
        </div>


        <div class="painel">
            <div class="barra-filtros" style="flex-wrap:wrap;">
                <form method="GET" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;flex:1;">
                    <select name="usuario_id" id="usuario_id">
                        <option value="">Todos os usuários</option>
                        <?php foreach ($usuarios_filtro as $u): ?>
                            <option value="<?php echo $u['id']; ?>" <?php echo $usuario_id === (int)$u['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($u['nome']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="status" id="status">
                        <option value="">Todos os status</option>
                        <option value="pago" <?php echo $status === 'pago' ? 'selected' : ''; ?>>Pago</option>
                        <option value="gerado" <?php echo $status === 'gerado' ? 'selected' : ''; ?>>Aguardando Pix</option>
                        <option value="cancelado" <?php echo $status === 'cancelado' ? 'selected' : ''; ?>>Cancelado</option>
                        <option value="expirado" <?php echo $status === 'expirado' ? 'selected' : ''; ?>>Expirado</option>
                    </select>
                    <select name="split_status" id="split_status">
                        <option value="">Split: todos</option>
                        <option value="pago" <?php echo $split_status === 'pago' ? 'selected' : ''; ?>>Pago (todos)</option>
                        <option value="parcial" <?php echo $split_status === 'parcial' ? 'selected' : ''; ?>>Parcial (alguns falharam)</option>
                        <option value="pendente" <?php echo $split_status === 'pendente' ? 'selected' : ''; ?>>Pendente</option>
                        <option value="falhou" <?php echo $split_status === 'falhou' ? 'selected' : ''; ?>>Falhou (todos)</option>
                        <option value="sem_split" <?php echo $split_status === 'sem_split' ? 'selected' : ''; ?>>Sem split configurado</option>
                        <option value="sem_credenciais" <?php echo $split_status === 'sem_credenciais' ? 'selected' : ''; ?>>Sem credenciais de Cash-Out</option>
                    </select>
                    <input type="date" name="data_inicio" id="data_inicio" value="<?php echo htmlspecialchars($data_inicio); ?>" style="width:150px;">
                    <input type="date" name="data_fim" id="data_fim" value="<?php echo htmlspecialchars($data_fim); ?>" style="width:150px;">
                    <input type="text" name="busca" id="busca" placeholder="TXID ou ID do Telegram" value="<?php echo htmlspecialchars($busca); ?>" style="min-width:180px;">
                    <button type="submit" class="botao botao-primario">Filtrar</button>
                    <a href="transacoes.php" class="botao">Limpar</a>
                </form>
            </div>

            <div class="tabela-dados">
                <table>
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Usuário / Bot</th>
                            <th>Valor</th>
                            <th>Status</th>
                            <th>Gateway</th>
                            <th>TXID</th>
                            <th class="col-numerica">Split</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transacoes as $t): ?>
                        <tr>
                            <td class="mono texto-suave"><?php echo date('d/m/Y H:i', strtotime($t['criado_em'])); ?></td>
                            <td>
                                <div><?php echo htmlspecialchars($t['nome_usuario']); ?></div>
                                <div class="celula-sub mono"><?php echo htmlspecialchars($t['nome_bot'] ?? '-'); ?></div>
                            </td>
                            <td class="mono">R$ <?php echo number_format((float)$t['valor'], 2, ',', '.'); ?></td>
                            <td><?php echo badgeStatusVenda($t['status']); ?></td>
                            <td class="texto-suave"><?php echo htmlspecialchars($t['titulo_gateway'] ?? '-'); ?></td>
                            <td class="mono texto-suave"><?php echo htmlspecialchars($t['transacao_id'] ?? '-'); ?></td>
                            <td class="col-numerica"><?php echo celulaSplit($t, $splits_por_venda[$t['id']] ?? []); ?></td>
                        </tr>
                        <?php endforeach; ?>

                        <?php if (empty($transacoes)): ?>
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 40px; color: var(--m);">Nenhuma transação encontrada.</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php echo paginador($total_transacoes, $por_pagina); ?>
        </div>
    </main>
</div>

<script src="../assets/js/tema.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/tema.js'); ?>"></script>
</body>
</html>
