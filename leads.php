<?php
declare(strict_types=1);

require_once __DIR__ . '/funcoes/usuario.php';
verificarLogin();

$id_usuario = $_SESSION['usuario_id'];
$is_admin = ehAdmin();

$bot_id = isset($_GET['bot_id']) ? (int)$_GET['bot_id'] : 0;
$status = isset($_GET['status']) ? $_GET['status'] : '';

$sql = "
    SELECT 
        l.id,
        l.nome,
        l.id_telegram,
        l.telefone,
        l.criado_em as data_inicio, 
        COALESCE(b.primeiro_nome, b.nome_usuario) as nome_bot,
        (SELECT COUNT(*) FROM vendas v WHERE v.id_telegram = l.id_telegram AND v.bot_id = l.bot_id AND v.status = 'pago') as total_compras,
        (SELECT SUM(v.valor) FROM vendas v WHERE v.id_telegram = l.id_telegram AND v.bot_id = l.bot_id AND v.status = 'pago') as total_gasto,
        (SELECT v.status FROM vendas v WHERE v.id_telegram = l.id_telegram AND v.bot_id = l.bot_id ORDER BY v.criado_em DESC LIMIT 1) as ultimo_status_pagamento,
        EXISTS (
            SELECT 1 FROM membros_grupos mg 
            WHERE mg.id_telegram = l.id_telegram
              AND mg.bot_id = l.bot_id
              AND mg.status = 'ativo'
              AND (mg.data_expiracao IS NULL OR mg.data_expiracao > NOW())
        ) as plano_ativo
    FROM leads l
    JOIN bots b ON l.bot_id = b.id
    WHERE b.id_usuario = :id_usuario
";

$params = ['id_usuario' => $id_usuario];

if ($bot_id > 0) {
    $sql .= " AND l.bot_id = :bot_id";
    $params['bot_id'] = $bot_id;
}

if ($status === 'pago') {
    $sql .= " AND EXISTS (SELECT 1 FROM vendas v WHERE v.id_telegram = l.id_telegram AND v.bot_id = l.bot_id AND v.status = 'pago')";
} elseif ($status === 'nao_pago') {
    $sql .= " AND NOT EXISTS (SELECT 1 FROM vendas v WHERE v.id_telegram = l.id_telegram AND v.bot_id = l.bot_id AND v.status = 'pago')";
}

$sql .= " ORDER BY l.criado_em DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$leads = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=leads.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Nome', 'ID Telegram', 'Bot', 'Data Inicio', 'Status', 'Plano Ativo', 'Total Compras', 'Total Gasto']);
    
    foreach ($leads as $lead) {
        $status_texto = 'Iniciou Conversa';
        if ($lead['total_compras'] > 0) {
            $status_texto = 'Cliente (Pagou)';
        } elseif ($lead['ultimo_status_pagamento'] === 'gerado') {
            $status_texto = 'Gerou Pix (Não Pago)';
        }
        
        fputcsv($output, [
            $lead['nome'],
            $lead['id_telegram'],
            $lead['nome_bot'],
            date('d/m/Y H:i', strtotime($lead['data_inicio'])),
            $status_texto,
            $lead['plano_ativo'] ? 'Ativo' : 'Inativo',
            $lead['total_compras'],
            number_format((float)$lead['total_gasto'], 2, ',', '.')
        ]);
    }
    fclose($output);
    exit;
}

$stmt_bots = $pdo->prepare("SELECT id, COALESCE(primeiro_nome, nome_usuario) as nome FROM bots WHERE id_usuario = ?");
$stmt_bots->execute([$id_usuario]);
$meus_bots = $stmt_bots->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leads - Gerenciamento de Bots</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/app.css">
    <style>
        .filtros { display: flex; gap: 10px; margin-bottom: 20px; align-items: flex-end; }
        .form-group { margin-bottom: 0; }
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 600; }
        .badge-success { background-color: #d1fae5; color: #065f46; }
        .badge-warning { background-color: #fef3c7; color: #92400e; }
        .badge-gray { background-color: #f3f4f6; color: #374151; }
        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #e5e7eb; }
        th { font-weight: 600; color: #374151; background-color: #f9fafb; }
    </style>
</head>
<body>
<div class="dashboard-layout">
    <?php include 'sidebar.php'; ?>
    <main class="main-content">
        <div class="cabecalho-pagina">
            <div>
                <h1>Leads</h1>
                <p>Visualize os usuários que interagiram com seus bots.</p>
            </div>
            <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>" class="botao botao-secundario">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                Exportar CSV
            </a>
        </div>

        <div class="painel">
            <form class="filtros" method="GET">
                <div class="form-group">
                    <label for="bot_id">Filtrar por Bot</label>
                    <select name="bot_id" id="bot_id" class="input-campo">
                        <option value="">Todos os Bots</option>
                        <?php foreach ($meus_bots as $b): ?>
                            <option value="<?php echo $b['id']; ?>" <?php echo $bot_id == $b['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($b['nome']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="status">Status</label>
                    <select name="status" id="status" class="input-campo">
                        <option value="">Todos</option>
                        <option value="pago" <?php echo $status === 'pago' ? 'selected' : ''; ?>>Já Pagou</option>
                        <option value="nao_pago" <?php echo $status === 'nao_pago' ? 'selected' : ''; ?>>Não Pagou</option>
                    </select>
                </div>
                <button type="submit" class="botao botao-primario">Filtrar</button>
            </form>

            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Nome</th>
                            <th>Número</th>
                            <th>Bot</th>
                            <th>Data Início</th>
                            <th>Status</th>
                            <th>Plano</th>
                            <th>Compras</th>
                            <th>Total Gasto</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($leads)): ?>
                            <tr>
                                <td colspan="8" style="text-align: center; padding: 20px; color: #6b7280;">Nenhum lead encontrado.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($leads as $lead): ?>
                                <?php
                                    $status_badge = '<span class="badge badge-gray">Iniciou</span>';
                                    if ($lead['total_compras'] > 0) {
                                        $status_badge = '<span class="badge badge-success">Cliente</span>';
                                    } elseif ($lead['ultimo_status_pagamento'] === 'gerado') {
                                        $status_badge = '<span class="badge badge-warning">Gerou Pix</span>';
                                    }
                                ?>
                                <tr>
                                    <td>
                                        <div style="font-weight: 500;"><?php echo htmlspecialchars($lead['nome']); ?></div>
                                        <div style="font-size: 12px; color: #6b7280;">ID: <?php echo $lead['id_telegram']; ?></div>
                                    </td>
                            <td><?php echo $lead['telefone'] ? htmlspecialchars($lead['telefone']) : '<span style="color:#9ca3af">—</span>'; ?></td>
                                    <td><?php echo htmlspecialchars($lead['nome_bot']); ?></td>
                                    <td><?php echo date('d/m/Y H:i', strtotime($lead['data_inicio'])); ?></td>
                                    <td><?php echo $status_badge; ?></td>
                            <td>
                                <?php echo $lead['plano_ativo'] ? '<span class="badge badge-success">Ativo</span>' : '<span class="badge badge-gray">Inativo</span>'; ?>
                            </td>
                                    <td><?php echo $lead['total_compras']; ?></td>
                                    <td>R$ <?php echo number_format((float)$lead['total_gasto'], 2, ',', '.'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>
</body>
</html>
