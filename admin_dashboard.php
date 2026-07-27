<?php declare(strict_types=1);
require_once __DIR__ . '/funcoes/usuario.php';
require_once __DIR__ . '/funcoes/log.php';
require_once __DIR__ . '/funcoes/paginador.php';
require_once __DIR__ . '/conexao.php';
verificarAdmin();

$periodo = $_GET['periodo'] ?? '7dias';
$where_data_vendas = '';
$where_bot_vendas = '';
$bot_id_selecionado = 'todos';

$stmt_bots = $pdo->prepare("
    SELECT 
        id,
        COALESCE(NULLIF(primeiro_nome, ''), NULLIF(nome_usuario, ''), CONCAT('Bot #', id)) AS nome
    FROM bots
    ORDER BY nome ASC
");
$stmt_bots->execute();
$bots_filtro = $stmt_bots->fetchAll(PDO::FETCH_ASSOC);
$mostrar_filtro_bot = count($bots_filtro) > 1;
$ids_bots_permitidos = array_map(static function (array $bot): int {
    return (int) $bot['id'];
}, $bots_filtro);

if (isset($_GET['bot_id']) && $_GET['bot_id'] !== 'todos') {
    $bot_id_informado = (int) $_GET['bot_id'];
    if ($bot_id_informado > 0 && in_array($bot_id_informado, $ids_bots_permitidos, true)) {
        $bot_id_selecionado = (string) $bot_id_informado;
        $where_bot_vendas = " AND v.bot_id = $bot_id_informado";
    }
}

$data_inicio = $_GET['data_inicio'] ?? '';
$data_fim = $_GET['data_fim'] ?? '';
$data_inicio_obj = DateTime::createFromFormat('Y-m-d', $data_inicio);
$data_fim_obj = DateTime::createFromFormat('Y-m-d', $data_fim);
$datas_validas = $data_inicio_obj !== false
    && $data_fim_obj !== false
    && $data_inicio_obj->format('Y-m-d') === $data_inicio
    && $data_fim_obj->format('Y-m-d') === $data_fim;

if ($periodo === 'personalizado' && $datas_validas) {
    if ($data_inicio > $data_fim) {
        $tmp = $data_inicio;
        $data_inicio = $data_fim;
        $data_fim = $tmp;
    }
    $where_data_vendas = "AND DATE(v.criado_em) BETWEEN '$data_inicio' AND '$data_fim'";
}

switch ($periodo) {
    case 'hoje':
        $where_data_vendas = "AND DATE(v.criado_em) = CURDATE()";
        break;
    case 'ontem':
        $where_data_vendas = "AND DATE(v.criado_em) = CURDATE() - INTERVAL 1 DAY";
        break;
    case '7dias':
        $where_data_vendas = "AND v.criado_em >= CURDATE() - INTERVAL 7 DAY";
        break;
    case '30dias':
        $where_data_vendas = "AND v.criado_em >= CURDATE() - INTERVAL 30 DAY";
        break;
    case 'total':
        $where_data_vendas = "";
        break;
    case 'personalizado':
        // Manter como personalizado mesmo se as datas não forem válidas
        // para que o usuário consiga preencher os inputs de data
        if ($datas_validas) {
        } else {
            $where_data_vendas = "";
        }
        break;
    default:
        $where_data_vendas = "AND v.criado_em >= CURDATE() - INTERVAL 7 DAY";
        $periodo = '7dias';
        break;
}

function montarUrlFiltroAdminDashboard(array $overrides = []): string
{
    $params = [
        'periodo' => $_GET['periodo'] ?? '7dias',
        'data_inicio' => $_GET['data_inicio'] ?? '',
        'data_fim' => $_GET['data_fim'] ?? '',
        'bot_id' => $_GET['bot_id'] ?? 'todos',
    ];
    foreach ($overrides as $chave => $valor) {
        if ($valor === null) {
            unset($params[$chave]);
            continue;
        }
        $params[$chave] = $valor;
    }
    return '?' . http_build_query($params);
}

$stmt = $pdo->prepare("SELECT SUM(v.valor) FROM vendas v WHERE v.status = 'pago' $where_data_vendas $where_bot_vendas");
$stmt->execute();
$total_transacionado = (float) $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT SUM(v.comissao_admin) FROM vendas v WHERE v.status = 'pago' $where_data_vendas $where_bot_vendas");
$stmt->execute();
$receita_admin = (float) $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM vendas v WHERE v.status = 'pago' $where_data_vendas $where_bot_vendas");
$stmt->execute();
$total_vendas = (int) $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE perfil != 'admin'");
$total_usuarios = (int) $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM bots");
$total_bots = (int) $stmt->fetchColumn();

$grafico_dados = [];
$grafico_labels = [];
$texto_grafico = "";

if ($periodo == 'hoje') {
    $texto_grafico = "HOJE (POR HORA)";
    for ($i = 0; $i <= date('H'); $i++) {
        $hora = sprintf('%02d:00', $i);
        $grafico_labels[] = $hora;
        
        $stmt = $pdo->prepare("SELECT SUM(v.comissao_admin) FROM vendas v WHERE v.status = 'pago' AND DATE(v.criado_em) = CURDATE() AND HOUR(v.criado_em) = ? $where_bot_vendas");
        $stmt->execute([$i]);
        $grafico_dados[] = (float) $stmt->fetchColumn();
    }
} elseif ($periodo == 'ontem') {
    $texto_grafico = "ONTEM (POR HORA)";
    for ($i = 0; $i <= 23; $i++) {
        $hora = sprintf('%02d:00', $i);
        $grafico_labels[] = $hora;
        
        $stmt = $pdo->prepare("SELECT SUM(v.comissao_admin) FROM vendas v WHERE v.status = 'pago' AND DATE(v.criado_em) = CURDATE() - INTERVAL 1 DAY AND HOUR(v.criado_em) = ? $where_bot_vendas");
        $stmt->execute([$i]);
        $grafico_dados[] = (float) $stmt->fetchColumn();
    }
} elseif ($periodo == '30dias') {
    $texto_grafico = "ÚLTIMOS 30 DIAS";
    for ($i = 29; $i >= 0; $i--) {
        $data = date('Y-m-d', strtotime("-$i days"));
        $grafico_labels[] = date('d/m', strtotime("-$i days"));

        $stmt = $pdo->prepare("SELECT SUM(v.comissao_admin) FROM vendas v WHERE v.status = 'pago' AND DATE(v.criado_em) = ? $where_bot_vendas");
        $stmt->execute([$data]);
        $grafico_dados[] = (float) $stmt->fetchColumn();
    }
} elseif ($periodo == 'total') {
    $texto_grafico = "HISTÓRICO TOTAL (ÚLTIMOS 12 MESES)";

    $meses_data = [];
    for ($i = 11; $i >= 0; $i--) {
        $mes_ano = date('Y-m', strtotime("-$i months"));
        $meses_data[$mes_ano] = 0;
    }

    $stmt = $pdo->prepare("
        SELECT DATE_FORMAT(v.criado_em, '%Y-%m') as mes, SUM(v.comissao_admin) as total 
        FROM vendas v 
        WHERE v.status = 'pago' AND v.criado_em >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) $where_bot_vendas
        GROUP BY mes 
        ORDER BY mes ASC
    ");
    $stmt->execute();
    $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($resultados as $row) {
        if (isset($meses_data[$row['mes']])) {
            $meses_data[$row['mes']] = (float) $row['total'];
        }
    }

    foreach ($meses_data as $mes => $total) {
        $date_obj = DateTime::createFromFormat('Y-m', $mes);

        $meses_pt = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];
        $mes_index = (int)$date_obj->format('n') - 1;
        $label_formatada = $meses_pt[$mes_index] . '/' . $date_obj->format('y');
        
        $grafico_labels[] = $label_formatada;
        $grafico_dados[] = $total;
    }
} else {
    $texto_grafico = "ÚLTIMOS 7 DIAS";
    for ($i = 6; $i >= 0; $i--) {
        $data = date('Y-m-d', strtotime("-$i days"));
        $dia_semana = date('D', strtotime("-$i days"));
        $dias_map = ['Sun'=>'Dom', 'Mon'=>'Seg', 'Tue'=>'Ter', 'Wed'=>'Qua', 'Thu'=>'Qui', 'Fri'=>'Sex', 'Sat'=>'Sáb'];
        $grafico_labels[] = $dias_map[$dia_semana] ?? $dia_semana;

        $stmt = $pdo->prepare("SELECT SUM(v.comissao_admin) FROM vendas v WHERE v.status = 'pago' AND DATE(v.criado_em) = ? $where_bot_vendas");
        $stmt->execute([$data]);
        $grafico_dados[] = (float) $stmt->fetchColumn();
    }
}

if ($periodo === 'personalizado') {
    $texto_grafico = "PERÍODO PERSONALIZADO";
    $grafico_dados = [];
    $grafico_labels = [];
    if ($datas_validas) {
        $data_atual = new DateTime($data_inicio);
        $data_final = new DateTime($data_fim);
        while ($data_atual <= $data_final) {
            $data_iso = $data_atual->format('Y-m-d');
            $grafico_labels[] = $data_atual->format('d/m');
            $stmt = $pdo->prepare("SELECT SUM(v.comissao_admin) FROM vendas v WHERE v.status = 'pago' AND DATE(v.criado_em) = ? $where_bot_vendas");
            $stmt->execute([$data_iso]);
            $grafico_dados[] = (float) $stmt->fetchColumn();
            $data_atual->modify('+1 day');
        }
    }
}

// Exclui 'venda'/'lead'/'pix_gerado' para o log do admin focar em ações administrativas/de sistema
$filtros_logs = ['excluir_tipos' => ['venda', 'lead', 'pix_gerado']];
$por_pagina = 10;
$pagina_atual = isset($_GET['pagina']) ? max(1, (int)$_GET['pagina']) : 1;
$offset = ($pagina_atual - 1) * $por_pagina;

$total_logs = contarAtividades($filtros_logs);
$atividades = listarAtividades($filtros_logs, $por_pagina, $offset);

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/app.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .dashboard-container {
            background-color: var(--bg);
            color: var(--text);
            min-height: 100vh;
            padding: 24px;
        }
        
        .dash-header {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 24px;
        }

        .dash-filters {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            background: #fff;
            padding: 6px;
            border-radius: 10px;
            border: 1px solid var(--border);
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
        }

        .dash-filters a {
            background: transparent;
            border: none;
            color: var(--muted);
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 500;
            text-decoration: none;
            display: inline-block;
            cursor: pointer;
        }

        .dash-filters a.active {
            background: var(--primary);
            color: #fff;
        }

        .dash-filter-form {
            display: flex;
            align-items: center;
            gap: 8px;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 6px;
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
        }

        .dash-date-group {
            display: flex;
            gap: 8px;
            max-width: 0;
            opacity: 0;
            overflow: hidden;
            transform: translateX(4px);
            transition: all .2s ease;
        }

        .dash-date-group.active {
            max-width: 1000px;
            opacity: 1;
            overflow: visible;
            transform: translateX(0);
        }

        .dash-filter-form input[type="date"],
        .dash-filter-form select {
            height: 34px;
            border: 1px solid #dbe3ef;
            border-radius: 8px;
            padding: 0 10px;
            font-size: 13px;
            color: #334155;
            background: #f8fafc;
        }

        .dash-filter-form input[type="date"] {
            width: 140px;
            min-width: 140px;
        }

        .dash-filter-form select {
            min-width: 170px;
        }

        .dash-filter-form button {
            height: 34px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 0 14px;
            font-size: 13px;
            font-weight: 600;
            background: #fff;
            color: #1e293b;
            cursor: pointer;
            transition: all .15s ease;
        }

        .dash-filter-form button:hover {
            background: #f1f5f9;
            border-color: #94a3b8;
        }

        .dash-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 20px;
            margin-bottom: 20px;
        }

        .dash-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 16px;
            display: flex;
            flex-direction: column;
            position: relative;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .dash-card.span-2 {
            grid-column: span 2;
        }

        .dash-card.span-3 {
            grid-column: span 3;
        }

        .dash-card-header {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--muted);
            font-size: 13px;
            font-weight: 500;
            margin-bottom: 12px;
            white-space: nowrap;
        }

        .dash-card-header svg {
            color: var(--primary);
            width: 16px;
            height: 16px;
            flex-shrink: 0;
        }

        .dash-card-value {
            font-size: 22px;
            font-weight: 700;
            color: var(--text);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .dash-card-sub {
            font-size: 11px;
            color: var(--muted);
            margin-top: 6px;
            display: flex;
            justify-content: space-between;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
        }

        .dash-log-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
            height: 300px;
            overflow-y: auto;
        }

        .dash-log-item {
            background: #f8fafc;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 12px 16px;
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .log-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #fff;
            border: 1px solid var(--border);
        }
        
        /* Cores Admin */
        .dash-card-header svg.admin-icon { color: #7c3aed; } /* Roxo para admin */
        .log-icon.admin { color: #7c3aed; background: #f5f3ff; border-color: #ddd6fe; }
        
        .log-content { flex: 1; }
        .log-title { font-size: 14px; font-weight: 600; color: var(--text); margin: 0 0 4px 0; }
        .log-desc { font-size: 12px; color: var(--muted); margin: 0; }
        .log-time { font-size: 12px; color: var(--muted); text-align: right; }

        /* Scrollbar custom */
        .dash-log-list::-webkit-scrollbar { width: 6px; }
        .dash-log-list::-webkit-scrollbar-track { background: #f1f5f9; }
        .dash-log-list::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        
        @media (max-width: 1200px) {
            .dash-grid { grid-template-columns: repeat(3, 1fr); }
            .dash-card.span-2 { grid-column: span 3; }
            .dash-card.span-3 { grid-column: span 3; }
        }
        
        @media (max-width: 768px) {
            .dash-grid { grid-template-columns: 1fr; }
            .dash-card.span-2 { grid-column: span 1; }
            .dash-card.span-3 { grid-column: span 1; }
            .dash-header { justify-content: stretch; }
            .dash-filters, .dash-filter-form { width: 100%; }
            .dash-date-group.active { max-width: 100%; width: 100%; }
            .dash-filter-form { flex-wrap: wrap; }
        }
    </style>
</head>
<body>
<div class="dashboard-layout">
    <?php include 'sidebar.php'; ?>
    
    <main class="main-content" style="padding: 0;">
        <div class="dashboard-container">
            
            <div class="dash-header">
                <div class="dash-filters">
                    <a href="<?php echo htmlspecialchars(montarUrlFiltroAdminDashboard(['periodo' => 'hoje'])); ?>" class="<?php echo ($periodo == 'hoje' ? 'active' : ''); ?>">Hoje</a>
                    <a href="<?php echo htmlspecialchars(montarUrlFiltroAdminDashboard(['periodo' => 'ontem'])); ?>" class="<?php echo ($periodo == 'ontem' ? 'active' : ''); ?>">Ontem</a>
                    <a href="<?php echo htmlspecialchars(montarUrlFiltroAdminDashboard(['periodo' => '7dias'])); ?>" class="<?php echo ($periodo == '7dias' ? 'active' : ''); ?>">7 dias</a>
                    <a href="<?php echo htmlspecialchars(montarUrlFiltroAdminDashboard(['periodo' => '30dias'])); ?>" class="<?php echo ($periodo == '30dias' ? 'active' : ''); ?>">30 dias</a>
                    <a href="<?php echo htmlspecialchars(montarUrlFiltroAdminDashboard(['periodo' => 'total'])); ?>" class="<?php echo ($periodo == 'total' ? 'active' : ''); ?>">Total</a>
                    <a href="<?php echo htmlspecialchars(montarUrlFiltroAdminDashboard(['periodo' => 'personalizado'])); ?>" class="<?php echo ($periodo == 'personalizado' ? 'active' : ''); ?>">Personalizado</a>
                </div>
                <form method="GET" class="dash-filter-form">
                    <input type="hidden" name="periodo" value="<?php echo htmlspecialchars($periodo); ?>">
                    <div class="dash-date-group <?php echo ($periodo === 'personalizado' ? 'active' : ''); ?>">
                        <input type="date" name="data_inicio" value="<?php echo htmlspecialchars($data_inicio); ?>" <?php echo ($periodo === 'personalizado' ? '' : 'disabled'); ?>>
                        <input type="date" name="data_fim" value="<?php echo htmlspecialchars($data_fim); ?>" <?php echo ($periodo === 'personalizado' ? '' : 'disabled'); ?>>
                    </div>
                    <?php if ($mostrar_filtro_bot): ?>
                        <select name="bot_id">
                            <option value="todos" <?php echo ($bot_id_selecionado === 'todos' ? 'selected' : ''); ?>>Todos os bots</option>
                            <?php foreach ($bots_filtro as $bot): ?>
                                <option value="<?php echo (int) $bot['id']; ?>" <?php echo ($bot_id_selecionado === (string) $bot['id'] ? 'selected' : ''); ?>>
                                    <?php echo htmlspecialchars($bot['nome']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                    <button type="submit">Aplicar</button>
                </form>
            </div>

            <div class="dash-grid">
                <div class="dash-card">
                    <div class="dash-card-header">
                        <svg class="admin-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        Volume Total
                    </div>
                    <div class="dash-card-value">R$ <?php echo number_format($total_transacionado, 2, ',', '.'); ?></div>
                    <div class="dash-card-sub">
                        <span>Vendas Brutas (Global)</span>
                    </div>
                </div>

                <div class="dash-card">
                    <div class="dash-card-header">
                        <svg class="admin-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" /></svg>
                        Qtd. Vendas
                    </div>
                    <div class="dash-card-value"><?php echo $total_vendas; ?></div>
                    <div class="dash-card-sub">
                        <span>Vendas aprovadas</span>
                    </div>
                </div>

                <div class="dash-card">
                    <div class="dash-card-header">
                        <svg class="admin-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" /></svg>
                        Receita Líquida (Admin)
                    </div>
                    <div class="dash-card-value" style="color: #7c3aed;">R$ <?php echo number_format($receita_admin, 2, ',', '.'); ?></div>
                    <div class="dash-card-sub">
                        <span>Sua comissão acumulada</span>
                    </div>
                </div>

                <div class="dash-card">
                    <div class="dash-card-header">
                        <svg class="admin-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" /></svg>
                        Usuários
                    </div>
                    <div class="dash-card-value"><?php echo $total_usuarios; ?></div>
                    <div class="dash-card-sub">
                        <span>Clientes registrados</span>
                    </div>
                </div>

                <div class="dash-card">
                    <div class="dash-card-header">
                        <svg class="admin-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" /></svg>
                        Bots Ativos
                    </div>
                    <div class="dash-card-value"><?php echo $total_bots; ?></div>
                    <div class="dash-card-sub">
                        <span>Bots na plataforma</span>
                    </div>
                </div>

                <div class="dash-card span-3">
                    <div class="dash-card-header">
                        <svg class="admin-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z" /></svg>
                        Evolução da Receita (Admin)
                        <span style="font-size: 11px; margin-left: 10px; color: var(--muted);"><?php echo $texto_grafico; ?></span>
                    </div>
                    <div class="chart-container">
                        <canvas id="adminChart"></canvas>
                    </div>
                </div>

                <div class="dash-card span-2">
                    <div class="dash-card-header">
                        <svg class="admin-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" /></svg>
                        Log do Sistema (Admin)
                    </div>
                    <div class="dash-log-list">
                        <?php foreach($atividades as $ativ): ?>
                            <?php
                                $icone_svg = '<svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>';
                                if ($ativ['tipo'] == 'venda') {
                                    $icone_svg = '<svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>';
                                } elseif ($ativ['tipo'] == 'lead') {
                                    $icone_svg = '<svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>';
                                }

                                $data_criado = strtotime($ativ['criado_em']);
                                $diff = abs(time() - $data_criado);
                                if ($diff < 60) $tempo = 'agora';
                                elseif ($diff < 3600) $tempo = floor($diff / 60) . 'm';
                                elseif ($diff < 86400) $tempo = floor($diff / 3600) . 'h';
                                else $tempo = floor($diff / 86400) . 'd';
                                
                                $nome_user = $ativ['nome_usuario'] ? " (" . htmlspecialchars($ativ['nome_usuario']) . ")" : "";
                            ?>
                            <div class="dash-log-item">
                                <div class="log-icon admin">
                                    <?php echo $icone_svg; ?>
                                </div>
                                <div class="log-content">
                                    <p class="log-title"><?php echo htmlspecialchars($ativ['titulo']); ?><span style="font-weight:400; font-size:12px; color:#94a3b8;"><?php echo $nome_user; ?></span></p>
                                    <p class="log-desc"><?php echo htmlspecialchars($ativ['descricao']); ?></p>
                                </div>
                                <div class="log-time">
                                    <?php echo $tempo; ?><br>
                                    <?php echo date('d/m, H:i', $data_criado); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if(empty($atividades)): ?>
                            <div style="text-align:center; color: #6b7280; padding: 20px;">Nenhuma atividade registrada ainda.</div>
                        <?php endif; ?>
                    </div>
                    <?php echo paginador($total_logs, $por_pagina); ?>
                </div>
            </div>

        </div>
    </main>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const periodo_btns = document.querySelectorAll('.dash-filters a');
    const date_inputs = document.querySelectorAll('.dash-date-group input[type="date"]');
    const filter_form = document.querySelector('.dash-filter-form');
    const periodo_hidden = document.querySelector('input[name="periodo"]');
    const date_group = document.querySelector('.dash-date-group');

    periodo_btns.forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();

            const url = new URL(this.href, window.location.origin);
            const periodo_selecionado = url.searchParams.get('periodo');

            periodo_hidden.value = periodo_selecionado;

            if (periodo_selecionado === 'personalizado') {
                date_inputs.forEach(input => input.disabled = false);
                date_group.classList.add('active');
                date_inputs[0].focus();
            } else {
                date_inputs.forEach(input => input.disabled = true);
                date_group.classList.remove('active');
                filter_form.submit();
            }
        });
    });

    date_inputs.forEach(input => {
        input.addEventListener('change', function() {
            const data_inicio = document.querySelector('input[name="data_inicio"]').value;
            const data_fim = document.querySelector('input[name="data_fim"]').value;

            if (data_inicio && data_fim && periodo_hidden.value === 'personalizado') {
                filter_form.submit();
            }
        });
    });

    const ctx = document.getElementById('adminChart');
    if (ctx) {
        const chart_ctx = ctx.getContext('2d');

        let gradient = chart_ctx.createLinearGradient(0, 0, 0, 400);
        gradient.addColorStop(0, 'rgba(124, 58, 237, 0.2)');   
        gradient.addColorStop(1, 'rgba(124, 58, 237, 0.0)');

        new Chart(chart_ctx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($grafico_labels); ?>,
                datasets: [{
                    label: 'Receita Admin',
                    data: <?php echo json_encode($grafico_dados); ?>,
                    borderColor: '#7c3aed',
                    backgroundColor: gradient,
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#ffffff',
                    pointBorderColor: '#7c3aed',
                    pointBorderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    x: {
                        grid: { display: false, drawBorder: false },
                        ticks: { color: '#6b7280', font: { size: 12 } }
                    },
                    y: {
                        grid: { color: 'rgba(0, 0, 0, 0.05)', drawBorder: false },
                        ticks: { display: false },
                        beginAtZero: true
                    }
                }
            }
        });
    }
});
</script>
</body>
</html>
