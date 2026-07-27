<?php
declare(strict_types=1);

require_once __DIR__ . '/funcoes/usuario.php';
require_once __DIR__ . '/funcoes/paginador.php';

verificarAdmin();

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
    LEFT JOIN usuarios_splits us ON us.id_usuario = u.id AND us.gateway_nome = 'infopago'
    WHERE $where_sql
";

$stmt_total = $pdo->prepare("SELECT COUNT(*) $sql_base");
$stmt_total->execute($params);
$total_transacoes = (int)$stmt_total->fetchColumn();

$pagina_atual = isset($_GET['pagina']) ? max(1, (int)$_GET['pagina']) : 1;
$por_pagina = 25;
$offset = ($pagina_atual - 1) * $por_pagina;

$sql = "
    SELECT
        v.id, v.valor, v.status, v.transacao_id, v.id_telegram, v.criado_em, v.pago_em,
        v.tipo_cobranca, v.comissao_admin, v.split_status, v.split_valor, v.split_em,
        b.id AS id_bot, COALESCE(b.primeiro_nome, b.nome_usuario) AS nome_bot,
        u.id AS id_usuario, u.nome AS nome_usuario,
        g.titulo AS titulo_gateway,
        us.tipo_split, us.taxa_split
    $sql_base
    ORDER BY v.criado_em DESC
    LIMIT $por_pagina OFFSET $offset
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$transacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=transacoes.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Data', 'Usuário', 'Bot', 'Valor', 'Status', 'Gateway', 'TXID', 'Status do Split', 'Valor do Split', 'Split em']);

    $sql_export = "
        SELECT
            v.valor, v.status, v.transacao_id, v.criado_em,
            b.id AS id_bot, COALESCE(b.primeiro_nome, b.nome_usuario) AS nome_bot,
            u.nome AS nome_usuario,
            g.titulo AS titulo_gateway,
            v.split_status, v.split_valor, v.split_em
        $sql_base
        ORDER BY v.criado_em DESC
    ";
    $stmt_export = $pdo->prepare($sql_export);
    $stmt_export->execute($params);

    while ($linha = $stmt_export->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            date('d/m/Y H:i', strtotime($linha['criado_em'])),
            $linha['nome_usuario'],
            $linha['nome_bot'],
            number_format((float)$linha['valor'], 2, ',', '.'),
            $linha['status'],
            $linha['titulo_gateway'] ?? '-',
            $linha['transacao_id'] ?? '-',
            $linha['split_status'] ?? 'pendente',
            $linha['split_valor'] !== null ? number_format((float)$linha['split_valor'], 2, ',', '.') : '-',
            $linha['split_em'] ? date('d/m/Y H:i', strtotime($linha['split_em'])) : '-',
        ]);
    }
    fclose($output);
    exit;
}

$usuarios_filtro = $pdo->query('SELECT id, nome FROM usuarios ORDER BY nome')->fetchAll(PDO::FETCH_ASSOC);

function calcularSplitEsperado(?string $tipo_split, ?string $taxa_split, string $valor_venda): ?float {
    if ($tipo_split === null || $taxa_split === null) {
        return null;
    }
    return $tipo_split === 'fixo'
        ? (float)$taxa_split
        : round((float)$valor_venda * ((float)$taxa_split / 100), 2);
}

function badgeStatusVenda(string $status): string {
    $mapa = [
        'pago' => ['Pago', 'badge-sucesso'],
        'gerado' => ['Aguardando Pix', 'badge-alerta'],
        'cancelado' => ['Cancelado', 'badge-cinza'],
        'expirado' => ['Expirado', 'badge-cinza'],
    ];
    [$texto, $classe] = $mapa[$status] ?? [ucfirst($status), 'badge-cinza'];
    return "<span class=\"badge $classe\">$texto</span>";
}

function badgeSplit(array $venda): string {
    if ($venda['status'] !== 'pago') {
        return '<span class="badge badge-cinza">—</span>';
    }
    $mapa = [
        'pago' => ['Pago', 'badge-sucesso'],
        'falhou' => ['Falhou', 'badge-perigo'],
        'sem_split' => ['Sem split configurado', 'badge-cinza'],
        'sem_credenciais' => ['Sem credenciais de Cash-Out', 'badge-alerta'],
    ];
    if ($venda['split_status'] === null) {
        return '<span class="badge badge-alerta">Pendente</span>';
    }
    [$texto, $classe] = $mapa[$venda['split_status']] ?? [$venda['split_status'], 'badge-cinza'];
    return "<span class=\"badge $classe\">$texto</span>";
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transações - Painel Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/app.css">
    <style>
        .filtros { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 20px; align-items: flex-end; }
        .form-group { margin-bottom: 0; }
        .form-group label { display: block; font-size: 12px; color: var(--muted); margin-bottom: 4px; }
        .table-responsive { overflow-x: auto; }
        .table { width: 100%; border-collapse: collapse; }
        .table th, .table td { padding: 12px 16px; text-align: left; border-bottom: 1px solid var(--border); white-space: nowrap; }
        .table th { font-weight: 600; color: var(--muted); font-size: 13px; background: #f8fafc; }
        .table td { font-size: 14px; color: var(--text); }
        .badge-sucesso { background: #dcfce7; color: #166534; }
        .badge-alerta { background: #fef3c7; color: #92400e; }
        .badge-perigo { background: #fee2e2; color: #991b1b; }
        .badge-cinza { background: #f3f4f6; color: #374151; }
        .text-muted { color: var(--muted); font-size: 13px; }
        .valor-esperado { font-size: 12px; color: var(--muted); }
        .resumo-cards { display: flex; gap: 16px; margin-bottom: 20px; flex-wrap: wrap; }
        .resumo-card { flex: 1; min-width: 180px; background: #fff; border: 1px solid var(--border); border-radius: 12px; padding: 16px 20px; }
        .resumo-card .titulo { font-size: 13px; color: var(--muted); margin-bottom: 6px; }
        .resumo-card .valor { font-size: 22px; font-weight: 700; color: var(--text); }
    </style>
</head>
<body>
<div class="dashboard-layout">
    <?php include 'sidebar.php'; ?>

    <main class="main-content">
        <div class="cabecalho-pagina">
            <div>
                <h1>Transações</h1>
                <p>Histórico de todas as vendas da plataforma, de todos os usuários, com status do split.</p>
            </div>
            <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>" class="botao botao-secundario">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                Exportar CSV
            </a>
        </div>

        <div class="painel">
            <form class="filtros" method="GET">
                <div class="form-group">
                    <label for="usuario_id">Usuário</label>
                    <select name="usuario_id" id="usuario_id" class="input-campo">
                        <option value="">Todos</option>
                        <?php foreach ($usuarios_filtro as $u): ?>
                            <option value="<?php echo $u['id']; ?>" <?php echo $usuario_id === (int)$u['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($u['nome']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="status">Status da venda</label>
                    <select name="status" id="status" class="input-campo">
                        <option value="">Todos</option>
                        <option value="pago" <?php echo $status === 'pago' ? 'selected' : ''; ?>>Pago</option>
                        <option value="gerado" <?php echo $status === 'gerado' ? 'selected' : ''; ?>>Aguardando Pix</option>
                        <option value="cancelado" <?php echo $status === 'cancelado' ? 'selected' : ''; ?>>Cancelado</option>
                        <option value="expirado" <?php echo $status === 'expirado' ? 'selected' : ''; ?>>Expirado</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="split_status">Split</label>
                    <select name="split_status" id="split_status" class="input-campo">
                        <option value="">Todos</option>
                        <option value="pago" <?php echo $split_status === 'pago' ? 'selected' : ''; ?>>Pago</option>
                        <option value="pendente" <?php echo $split_status === 'pendente' ? 'selected' : ''; ?>>Pendente</option>
                        <option value="falhou" <?php echo $split_status === 'falhou' ? 'selected' : ''; ?>>Falhou</option>
                        <option value="sem_split" <?php echo $split_status === 'sem_split' ? 'selected' : ''; ?>>Sem split configurado</option>
                        <option value="sem_credenciais" <?php echo $split_status === 'sem_credenciais' ? 'selected' : ''; ?>>Sem credenciais de Cash-Out</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="data_inicio">De</label>
                    <input type="date" name="data_inicio" id="data_inicio" class="input-campo" value="<?php echo htmlspecialchars($data_inicio); ?>">
                </div>
                <div class="form-group">
                    <label for="data_fim">Até</label>
                    <input type="date" name="data_fim" id="data_fim" class="input-campo" value="<?php echo htmlspecialchars($data_fim); ?>">
                </div>
                <div class="form-group">
                    <label for="busca">TXID / ID Telegram</label>
                    <input type="text" name="busca" id="busca" class="input-campo" placeholder="Buscar..." value="<?php echo htmlspecialchars($busca); ?>">
                </div>
                <button type="submit" class="botao botao-primario">Filtrar</button>
                <a href="admin_transacoes.php" class="botao botao-claro">Limpar</a>
            </form>

            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Usuário</th>
                            <th>Bot</th>
                            <th>Valor</th>
                            <th>Status</th>
                            <th>Gateway</th>
                            <th>TXID</th>
                            <th>Split</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transacoes as $t): ?>
                        <tr>
                            <td class="text-muted"><?php echo date('d/m/Y H:i', strtotime($t['criado_em'])); ?></td>
                            <td style="font-weight: 500;"><?php echo htmlspecialchars($t['nome_usuario']); ?></td>
                            <td><?php echo htmlspecialchars($t['nome_bot'] ?? '-'); ?></td>
                            <td>R$ <?php echo number_format((float)$t['valor'], 2, ',', '.'); ?></td>
                            <td><?php echo badgeStatusVenda($t['status']); ?></td>
                            <td class="text-muted"><?php echo htmlspecialchars($t['titulo_gateway'] ?? '-'); ?></td>
                            <td class="text-muted"><?php echo htmlspecialchars($t['transacao_id'] ?? '-'); ?></td>
                            <td>
                                <?php echo badgeSplit($t); ?>
                                <?php if ($t['status'] === 'pago' && $t['split_valor'] !== null): ?>
                                    <div class="valor-esperado">R$ <?php echo number_format((float)$t['split_valor'], 2, ',', '.'); ?></div>
                                <?php elseif ($t['status'] === 'pago' && $t['split_status'] === null): ?>
                                    <?php $valor_esperado = calcularSplitEsperado($t['tipo_split'], $t['taxa_split'], $t['valor']); ?>
                                    <?php if ($valor_esperado !== null): ?>
                                        <div class="valor-esperado">≈ R$ <?php echo number_format($valor_esperado, 2, ',', '.'); ?></div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>

                        <?php if (empty($transacoes)): ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 40px; color: var(--muted);">Nenhuma transação encontrada.</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php echo paginador($total_transacoes, $por_pagina); ?>
        </div>
    </main>
</div>
</body>
</html>
