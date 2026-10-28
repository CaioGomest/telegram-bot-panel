<?php
declare(strict_types=1);

require_once __DIR__ . '/../funcoes/usuario.php';
require_once __DIR__ . '/../funcoes/paginador.php';

verificarAdmin();
$caminho_base = '../';

$usuario_id = (int)($_GET['usuario_id'] ?? 0);
$status = trim($_GET['status'] ?? '');
// Comissão da plataforma na venda (vendas.comissao_admin, gravada na criação da cobrança): '' = todas, 'com' = pagas com comissão, 'sem' = pagas sem comissão.
// (O antigo filtro "Split" lia vendas.split_status, coluna do cash-out do InfoPago que nada mais preenche: removida.)
$comissao = in_array($_GET['comissao'] ?? '', ['com', 'sem'], true) ? (string) $_GET['comissao'] : '';
$periodo = trim($_GET['periodo'] ?? '');
$data_inicio = '';
$data_fim = '';
if ($periodo === 'hoje') { $data_inicio = $data_fim = date('Y-m-d'); }
elseif ($periodo === 'ontem') { $data_inicio = $data_fim = date('Y-m-d', strtotime('-1 day')); }
elseif ($periodo === '7') { $data_inicio = date('Y-m-d', strtotime('-6 days')); }
elseif ($periodo === '30') { $data_inicio = date('Y-m-d', strtotime('-29 days')); }
else { $periodo = ''; }
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
if ($comissao === 'com') {
    $where[] = "v.status = 'pago' AND v.comissao_admin > 0";
} elseif ($comissao === 'sem') {
    $where[] = "v.status = 'pago' AND v.comissao_admin = 0";
}

$where_sql = implode(' AND ', $where);

$sql_base = "
    FROM vendas v
    JOIN bots b ON v.bot_id = b.id
    JOIN usuarios u ON b.id_usuario = u.id
    LEFT JOIN gateways g ON v.id_gateway = g.id
    WHERE $where_sql
";

// O COUNT não precisa dos LEFT JOINs (gateway/split nunca reduzem linha) nem do JOIN
// com bots/usuarios quando não há filtro por usuário -- só existem ali pra dar suporte
// a filtros que podem nem estar ativos. Sem filtro nenhum (visão padrão), juntar tudo
// pra só contar forçava o MySQL a escanear vendas inteira (testado: full table scan
// mesmo sem WHERE nenhum). Ver anotacoes/OBSERVACOES.md.
if ($usuario_id > 0) {
    $sql_count_base = "FROM vendas v JOIN bots b ON v.bot_id = b.id JOIN usuarios u ON b.id_usuario = u.id WHERE $where_sql";
} else {
    $sql_count_base = "FROM vendas v WHERE $where_sql";
}
if ($where_sql === '1=1') {
    // Visão padrão (sem nenhum filtro): um COUNT(*) exato aqui é full table scan --
    // medido em 2026-09-17 a 6,8 milhões de linhas: 1,57s, escala linear com o total
    // acumulado na plataforma (ver anotacoes/OBSERVACOES.md).
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
    // O COUNT exato de um filtro (ex.: só "pago") varre centenas de milhares de linhas e cresce junto com a base (390 ms com
    // 809 mil vendas): fica guardado por 60 s, o que só atrasa o número de páginas em até 1 minuto.
    $total_transacoes = contagemEmCache('transacoes|' . $sql_count_base . '|' . json_encode($params), static function () use ($pdo, $sql_count_base, $params) {
        $stmt_total = $pdo->prepare("SELECT COUNT(*) $sql_count_base");
        $stmt_total->execute($params);
        return (int) $stmt_total->fetchColumn();
    }, 60);
}

$por_pagina = 25;
$pagina_atual = isset($_GET['pagina']) ? max(1, (int)$_GET['pagina']) : 1;
$visao_padrao = ($where_sql === '1=1');
// Sem filtro o total vem da estimativa do InnoDB, que costuma ser menor que a
// tabela de verdade e cortava o fim da lista. Não trava a página nesse número:
// busca uma linha a mais e, se ela existir, libera a página seguinte.
if (!$visao_padrao) {
    $total_paginas = $total_transacoes > 0 ? (int) ceil($total_transacoes / $por_pagina) : 1;
    $pagina_atual = min($pagina_atual, $total_paginas);
}
$offset = ($pagina_atual - 1) * $por_pagina;
$limite_ids = $visao_padrao ? $por_pagina + 1 : $por_pagina;

// Busca só os IDs da página atual primeiro (sem os LEFT JOINs caros de gateway/split),
// usando o índice em criado_em -- e só faz o JOIN pesado pras poucas linhas que
// sobraram, não pra tabela inteira. Sem isso, com muitas vendas acumuladas o MySQL
// materializa a junção inteira antes de ordenar (testado: 5,75s -> 0,002s com 1M de
// vendas). Ver anotacoes/OBSERVACOES.md.
$sql_ids = "
    SELECT v.id
    FROM vendas v
    JOIN bots b ON v.bot_id = b.id
    JOIN usuarios u ON b.id_usuario = u.id
    WHERE $where_sql
    ORDER BY v.criado_em DESC
    LIMIT $limite_ids OFFSET $offset
";
$stmt_ids = $pdo->prepare($sql_ids);
$stmt_ids->execute($params);
$ids_pagina = $stmt_ids->fetchAll(PDO::FETCH_COLUMN);
if ($visao_padrao) {
    $tem_mais = count($ids_pagina) > $por_pagina;
    if ($tem_mais) {
        array_pop($ids_pagina);
        $total_transacoes = max($total_transacoes, $offset + $por_pagina + 1);
    } else {
        $total_transacoes = $offset + count($ids_pagina);
    }
}

if ($ids_pagina) {
    $placeholders_ids = implode(',', array_fill(0, count($ids_pagina), '?'));
    $sql = "
        SELECT
            v.id, v.valor, v.status, v.transacao_id, v.id_telegram, v.criado_em, v.pago_em,
            v.tipo_cobranca, v.comissao_admin,
            b.id AS id_bot, COALESCE(b.primeiro_nome, b.nome_usuario) AS nome_bot,
            u.id AS id_usuario, u.nome AS nome_usuario,
            g.titulo AS titulo_gateway
        FROM vendas v
        JOIN bots b ON v.bot_id = b.id
        JOIN usuarios u ON b.id_usuario = u.id
        LEFT JOIN gateways g ON v.id_gateway = g.id
        WHERE v.id IN ($placeholders_ids)
        ORDER BY v.criado_em DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($ids_pagina);
    $transacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $transacoes = [];
}

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=transacoes.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Data', 'Usuário', 'Bot', 'Valor', 'Status', 'Gateway', 'TXID', 'Comissão da plataforma (R$)']);

    $sql_export = "
        SELECT
            v.id, v.valor, v.status, v.transacao_id, v.criado_em,
            b.id AS id_bot, COALESCE(b.primeiro_nome, b.nome_usuario) AS nome_bot,
            u.nome AS nome_usuario,
            g.titulo AS titulo_gateway,
            v.comissao_admin
        $sql_base
        ORDER BY v.criado_em DESC
    ";
    $stmt_export = $pdo->prepare($sql_export);
    $stmt_export->execute($params);
    $vendas_export = $stmt_export->fetchAll(PDO::FETCH_ASSOC);

    foreach ($vendas_export as $linha) {
        fputcsv($output, [
            $linha['id'],
            date('d/m/Y H:i', strtotime($linha['criado_em'])),
            csvSeguro($linha['nome_usuario']),
            csvSeguro($linha['nome_bot']),
            number_format((float)$linha['valor'], 2, ',', '.'),
            $linha['status'],
            csvSeguro($linha['titulo_gateway'] ?? '-'),
            csvSeguro($linha['transacao_id'] ?? '-'),
            $linha['status'] === 'pago' ? number_format((float) $linha['comissao_admin'], 2, ',', '.') : '',
        ]);
    }
    fclose($output);
    exit;
}

$usuarios_filtro = $pdo->query('SELECT id, nome, email FROM usuarios ORDER BY nome')->fetchAll(PDO::FETCH_ASSOC);

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

/**
 * Comissão que a plataforma ganhou na venda: vendas.comissao_admin, o split enviado na cobrança (a OmegaPayments divide no pagamento). Venda não paga ainda
 * não rendeu nada ("—"); paga sem comissão = a gateway não tinha split ligado naquela hora.
 */
function celulaComissao(array $venda): string {
    if ($venda['status'] !== 'pago') {
        return '<span class="badge badge-neutro">—</span>';
    }
    $comissao = (float) $venda['comissao_admin'];
    if ($comissao <= 0) {
        return '<span class="badge badge-neutro">Sem split</span>';
    }
    return '<span class="mono">R$ ' . number_format($comissao, 2, ',', '.') . '</span>';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Transações - <?php echo htmlspecialchars(nomeSistema()); ?></title>
    <?php include __DIR__ . '/../tema_inline.php'; ?>
    <link rel="stylesheet" href="../assets/css/painel.css?v=<?php echo @filemtime(__DIR__.'/../assets/css/painel.css'); ?>">
</head>
<body>
<div class="layout-painel">
    <?php include __DIR__ . '/../barra_lateral.php'; ?>

    <main class="conteudo-principal">
        <div class="cabecalho-pagina">
            <div>
                <h1>Transações</h1>
                <p>Histórico de todas as vendas da plataforma, de todos os usuários, com a comissão da plataforma em cada venda paga.</p>
            </div>
            <div class="acoes-cabecalho">
                <?php /* Botão "Exportar CSV" desligado a pedido (14/10/2026). A exportação continua no topo deste arquivo (?export=csv); para religar, tire este comentário.
                <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>" class="botao">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                    Exportar CSV
                </a>
                */ ?>
                <button type="button" class="alternador-tema" onclick="alternarTema()" aria-label="Alternar tema">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"></path></svg>
                    Tema
                </button>
            </div>
        </div>


        <?php $tem_filtro = $usuario_id > 0 || $status !== '' || $comissao !== '' || $data_inicio !== '' || $data_fim !== '' || $busca !== ''; ?>
        <div class="filtros-vendas">
            <form method="GET" class="filtros-vendas-form filtros-transacoes-form">
                <label class="filtro-campo"><span>Usuário</span>
                    <select name="usuario_id" id="usuario_id">
                        <option value="">Todos os usuários</option>
                        <?php foreach ($usuarios_filtro as $u): ?>
                            <option value="<?php echo $u['id']; ?>" <?php echo $usuario_id === (int)$u['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($u['nome'] . ' — ' . $u['email']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="filtro-campo"><span>Status</span>
                    <select name="status" id="status">
                        <option value="">Todos os status</option>
                        <option value="pago" <?php echo $status === 'pago' ? 'selected' : ''; ?>>Pago</option>
                        <option value="gerado" <?php echo $status === 'gerado' ? 'selected' : ''; ?>>Aguardando Pix</option>
                        <option value="cancelado" <?php echo $status === 'cancelado' ? 'selected' : ''; ?>>Cancelado</option>
                        <option value="expirado" <?php echo $status === 'expirado' ? 'selected' : ''; ?>>Expirado</option>
                    </select>
                </label>
                <label class="filtro-campo"><span>Comissão</span>
                    <select name="comissao" id="comissao">
                        <option value="">Todas</option>
                        <option value="com" <?php echo $comissao === 'com' ? 'selected' : ''; ?>>Pagas com comissão</option>
                        <option value="sem" <?php echo $comissao === 'sem' ? 'selected' : ''; ?>>Pagas sem comissão</option>
                    </select>
                </label>
                <label class="filtro-campo"><span>Período</span>
                    <select name="periodo" id="periodo">
                        <?php foreach (['' => 'Total', 'hoje' => 'Hoje', 'ontem' => 'Ontem', '7' => '7 dias', '30' => '30 dias'] as $chave_periodo => $rotulo_periodo): ?>
                            <option value="<?php echo $chave_periodo; ?>" <?php echo $periodo === (string) $chave_periodo ? 'selected' : ''; ?>><?php echo $rotulo_periodo; ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="filtro-campo filtro-busca"><span>Buscar</span><input type="text" name="busca" id="busca" maxlength="100" value="<?php echo htmlspecialchars($busca); ?>" placeholder="TXID ou ID do Telegram"></label>
                <div class="filtro-acoes">
                    <button type="submit" class="botao botao-primario">Filtrar</button>
                    <?php if ($tem_filtro): ?><a href="transacoes" class="botao">Limpar</a><?php endif; ?>
                </div>
            </form>
        </div>

        <div class="painel">
            <div class="tabela-dados">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Data</th>
                            <th>Usuário / Bot</th>
                            <th>Valor</th>
                            <th>Status</th>
                            <th>Gateway</th>
                            <th>TXID</th>
                            <th class="col-numerica">Comissão</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transacoes as $t): ?>
                        <tr>
                            <td class="mono"><?php echo (int) $t['id']; ?></td>
                            <td class="mono texto-suave"><?php echo date('d/m/Y H:i', strtotime($t['criado_em'])); ?></td>
                            <td>
                                <div><?php echo htmlspecialchars($t['nome_usuario']); ?></div>
                                <div class="celula-sub mono"><?php echo htmlspecialchars($t['nome_bot'] ?? '-'); ?></div>
                            </td>
                            <td class="mono">R$ <?php echo number_format((float)$t['valor'], 2, ',', '.'); ?></td>
                            <td><?php echo badgeStatusVenda($t['status']); ?></td>
                            <td class="texto-suave"><?php echo htmlspecialchars($t['titulo_gateway'] ?? '-'); ?></td>
                            <td class="mono texto-suave"><?php echo htmlspecialchars($t['transacao_id'] ?? '-'); ?></td>
                            <td class="col-numerica"><?php echo celulaComissao($t); ?></td>
                        </tr>
                        <?php endforeach; ?>

                        <?php if (empty($transacoes)): ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 40px; color: var(--m);">Nenhuma transação encontrada.</td>
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
