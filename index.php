<?php declare(strict_types=1);
require_once __DIR__ . '/funcoes/usuario.php';
require_once __DIR__ . '/funcoes/log.php';
require_once __DIR__ . '/funcoes/paginador.php';
require_once __DIR__ . '/conexao.php';
verificarLogin();

$user_id = $_SESSION['usuario_id'];
$is_admin = ehAdmin();

$where_user = "";
$where_user_vendas = "";
$where_user_leads = "";
$where_user_atividades = "";
$where_bot_vendas = "";
$where_bot_leads = "";
$bot_id_selecionado = 'todos';
$bots_filtro = [];
$mostrar_filtro_bot = false;

if (!$is_admin) {
    $where_user_vendas = " AND v.bot_id IN (SELECT id FROM bots WHERE id_usuario = $user_id) ";
    $where_user_leads = " AND l.bot_id IN (SELECT id FROM bots WHERE id_usuario = $user_id) ";
    $where_user_atividades = " WHERE id_usuario = $user_id ";
} else {
    $where_user_vendas = "";
    $where_user_leads = "";
    $where_user_atividades = "";
}

if ($is_admin) {
    $stmt_bots = $pdo->prepare("
        SELECT 
            id,
            COALESCE(NULLIF(primeiro_nome, ''), NULLIF(nome_usuario, ''), CONCAT('Bot #', id)) AS nome
        FROM bots
        ORDER BY nome ASC
    ");
    $stmt_bots->execute();
} else {
    $stmt_bots = $pdo->prepare("
        SELECT 
            id,
            COALESCE(NULLIF(primeiro_nome, ''), NULLIF(nome_usuario, ''), CONCAT('Bot #', id)) AS nome
        FROM bots
        WHERE id_usuario = ?
        ORDER BY nome ASC
    ");
    $stmt_bots->execute([$user_id]);
}
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
        $where_bot_leads = " AND l.bot_id = $bot_id_informado";
    }
}

$periodo = $_GET['periodo'] ?? '7dias';
$where_data_vendas = '';
$where_data_leads = '';
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
    $where_data_leads = "AND DATE(l.criado_em) BETWEEN '$data_inicio' AND '$data_fim'";
}

switch ($periodo) {
    case 'hoje':
        $where_data_vendas = "AND DATE(v.criado_em) = CURDATE()";
        $where_data_leads = "AND DATE(l.criado_em) = CURDATE()";
        break;
    case 'ontem':
        $where_data_vendas = "AND DATE(v.criado_em) = CURDATE() - INTERVAL 1 DAY";
        $where_data_leads = "AND DATE(l.criado_em) = CURDATE() - INTERVAL 1 DAY";
        break;
    case '7dias':
        $where_data_vendas = "AND v.criado_em >= CURDATE() - INTERVAL 7 DAY";
        $where_data_leads = "AND l.criado_em >= CURDATE() - INTERVAL 7 DAY";
        break;
    case '30dias':
        $where_data_vendas = "AND v.criado_em >= CURDATE() - INTERVAL 30 DAY";
        $where_data_leads = "AND l.criado_em >= CURDATE() - INTERVAL 30 DAY";
        break;
    case 'total':
        $where_data_vendas = "";
        $where_data_leads = "";
        break;
    case 'personalizado':
        // Manter como personalizado mesmo se as datas não forem válidas
        // para que o usuário consiga preencher os inputs de data
        if ($datas_validas) {
        } else {
            $where_data_vendas = "";
            $where_data_leads = "";
        }
        break;
    default:
        $where_data_vendas = "AND v.criado_em >= CURDATE() - INTERVAL 7 DAY";
        $where_data_leads = "AND l.criado_em >= CURDATE() - INTERVAL 7 DAY";
        $periodo = '7dias';
        break;
}

function montarUrlFiltroDashboard(array $overrides = []): string
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

try {
    $stmt = $pdo->prepare("SELECT SUM(v.valor) FROM vendas v WHERE v.status = 'pago' $where_data_vendas $where_user_vendas $where_bot_vendas");
    $stmt->execute();
    $vendas_aprovadas = (float) $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM leads l WHERE 1=1 $where_data_leads $where_user_leads $where_bot_leads");
    $stmt->execute();
    $total_starts = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM vendas v WHERE v.status = 'pago' $where_data_vendas $where_user_vendas $where_bot_vendas");
    $stmt->execute();
    $pix_pagos = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM vendas v WHERE 1=1 $where_data_vendas $where_user_vendas $where_bot_vendas");
    $stmt->execute();
    $pix_gerados = (int) $stmt->fetchColumn();

    $taxa_conversao = $pix_gerados > 0 ? ($pix_pagos / $pix_gerados) * 100 : 0;

    $ticket_medio = $pix_pagos > 0 ? $vendas_aprovadas / $pix_pagos : 0;

    $grafico_dados = [];
    $grafico_labels = [];
    $texto_grafico = "";

    if ($periodo == 'hoje') {
        $texto_grafico = "HOJE (POR HORA)";
        for ($i = 0; $i <= date('H'); $i++) {
            $hora = sprintf('%02d:00', $i);
            $grafico_labels[] = $hora;
            
            $stmt = $pdo->prepare("SELECT SUM(v.valor) FROM vendas v WHERE v.status = 'pago' AND DATE(v.criado_em) = CURDATE() AND HOUR(v.criado_em) = ? $where_user_vendas $where_bot_vendas");
            $stmt->execute([$i]);
            $grafico_dados[] = (float) $stmt->fetchColumn();
        }
    } elseif ($periodo == 'ontem') {
        $texto_grafico = "ONTEM (POR HORA)";
        for ($i = 0; $i <= 23; $i++) {
            $hora = sprintf('%02d:00', $i);
            $grafico_labels[] = $hora;
            
            $stmt = $pdo->prepare("SELECT SUM(v.valor) FROM vendas v WHERE v.status = 'pago' AND DATE(v.criado_em) = CURDATE() - INTERVAL 1 DAY AND HOUR(v.criado_em) = ? $where_user_vendas $where_bot_vendas");
            $stmt->execute([$i]);
            $grafico_dados[] = (float) $stmt->fetchColumn();
        }
    } elseif ($periodo == '30dias') {
        $texto_grafico = "ÚLTIMOS 30 DIAS";
        for ($i = 29; $i >= 0; $i--) {
            $data = date('Y-m-d', strtotime("-$i days"));
            $grafico_labels[] = date('d/m', strtotime("-$i days"));

            $stmt = $pdo->prepare("SELECT SUM(v.valor) FROM vendas v WHERE v.status = 'pago' AND DATE(v.criado_em) = ? $where_user_vendas $where_bot_vendas");
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
            SELECT DATE_FORMAT(v.criado_em, '%Y-%m') as mes, SUM(v.valor) as total 
            FROM vendas v 
            WHERE v.status = 'pago' AND v.criado_em >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) $where_user_vendas $where_bot_vendas
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

            $stmt = $pdo->prepare("SELECT SUM(v.valor) FROM vendas v WHERE v.status = 'pago' AND DATE(v.criado_em) = ? $where_user_vendas $where_bot_vendas");
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
                $stmt = $pdo->prepare("SELECT SUM(v.valor) FROM vendas v WHERE v.status = 'pago' AND DATE(v.criado_em) = ? $where_user_vendas $where_bot_vendas");
                $stmt->execute([$data_iso]);
                $grafico_dados[] = (float) $stmt->fetchColumn();
                $data_atual->modify('+1 day');
            }
        }
    }


    // Para usuários, mostrar apenas eventos relacionados ao Telegram (venda, lead)
    // Para admin, mostra tudo (ou poderia ser só eventos globais, mas aqui seguimos o padrão dashboard)
    $filtros_atividades = [];
    if (!$is_admin) {
        $filtros_atividades['id_usuario'] = $user_id;
        $filtros_atividades['tipos_in'] = ['venda', 'pix_gerado', 'lead'];
    }

    $por_pagina = 10;
    $pagina_atual = isset($_GET['pagina']) ? max(1, (int)$_GET['pagina']) : 1;
    $offset = ($pagina_atual - 1) * $por_pagina;

    $total_atividades = contarAtividades($filtros_atividades);
    $atividades = listarAtividades($filtros_atividades, $por_pagina, $offset);

} catch (Exception $e) {
    // Se der erro, define valores padrão para evitar que a página quebre
    error_log("Erro no dashboard: " . $e->getMessage());
    $vendas_aprovadas = 0;
    $total_starts = 0;
    $pix_pagos = 0;
    $pix_gerados = 0;
    $taxa_conversao = 0;
    $ticket_medio = 0;
    $grafico_dados = [];
    $grafico_labels = [];
    $texto_grafico = "Erro ao carregar dados";
    $atividades = [];
    $total_atividades = 0;
}



?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard</title>
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
            grid-template-columns: repeat(4, 1fr);
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
            height: 300px; /* Altura fixa para alinhar com o gráfico */
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

        .log-icon.venda { color: var(--green); background: #f0fdf4; border-color: #bbf7d0; }
        .log-icon.pix_gerado { color: #d97706; background: #fff7ed; border-color: #fde68a; }
        .log-icon.lead { color: var(--primary); background: #eff6ff; border-color: #bfdbfe; }

        .log-content { flex: 1; }
        .log-title { font-size: 14px; font-weight: 600; color: var(--text); margin: 0 0 4px 0; }
        .log-desc { font-size: 12px; color: var(--muted); margin: 0; }
        
        .log-time { font-size: 12px; color: var(--muted); text-align: right; }

        .progress-circle {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: conic-gradient(var(--primary) <?php echo $taxa_conversao; ?>%, #e2e8f0 0);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto;
        }
        .progress-inner {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
        }
        .progress-val { font-size: 20px; font-weight: 700; color: var(--text); }
        .progress-sub { font-size: 10px; color: var(--muted); }

        .dash-log-list::-webkit-scrollbar { width: 6px; }
        .dash-log-list::-webkit-scrollbar-track { background: #f1f5f9; }
        .dash-log-list::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        
        @media (max-width: 1200px) {
            .dash-grid { grid-template-columns: repeat(2, 1fr); }
            .dash-card.span-2 { grid-column: span 2; }
        }
        
        @media (max-width: 768px) {
            .dash-grid { grid-template-columns: 1fr; }
            .dash-card.span-2 { grid-column: span 1; }
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
                    <a href="<?php echo htmlspecialchars(montarUrlFiltroDashboard(['periodo' => 'hoje'])); ?>" class="<?php echo ($periodo == 'hoje' ? 'active' : ''); ?>">Hoje</a>
                    <a href="<?php echo htmlspecialchars(montarUrlFiltroDashboard(['periodo' => 'ontem'])); ?>" class="<?php echo ($periodo == 'ontem' ? 'active' : ''); ?>">Ontem</a>
                    <a href="<?php echo htmlspecialchars(montarUrlFiltroDashboard(['periodo' => '7dias'])); ?>" class="<?php echo ($periodo == '7dias' ? 'active' : ''); ?>">7 dias</a>
                    <a href="<?php echo htmlspecialchars(montarUrlFiltroDashboard(['periodo' => '30dias'])); ?>" class="<?php echo ($periodo == '30dias' ? 'active' : ''); ?>">30 dias</a>
                    <a href="<?php echo htmlspecialchars(montarUrlFiltroDashboard(['periodo' => 'total'])); ?>" class="<?php echo ($periodo == 'total' ? 'active' : ''); ?>">Total</a>
                    <a href="<?php echo htmlspecialchars(montarUrlFiltroDashboard(['periodo' => 'personalizado'])); ?>" class="<?php echo ($periodo == 'personalizado' ? 'active' : ''); ?>">Personalizado</a>
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
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        Vendas Aprovadas
                    </div>
                    <div class="dash-card-value">R$ <?php echo number_format($vendas_aprovadas, 2, ',', '.'); ?></div>
                    <div class="dash-card-sub">
                        <span></span>
                        <span><?php echo $pix_pagos; ?> Aprov.</span>
                    </div>
                </div>

                <div class="dash-card">
                    <div class="dash-card-header">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" /></svg>
                        Taxa de Conversão
                    </div>
                    <div class="progress-circle">
                        <div class="progress-inner">
                            <span class="progress-val"><?php echo round($taxa_conversao); ?>%</span>
                            <span class="progress-sub">de PIX</span>
                        </div>
                    </div>
                </div>

                <div class="dash-card">
                    <div class="dash-card-header">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" /></svg>
                        Total Starts
                    </div>
                    <div class="dash-card-value"><?php echo $total_starts; ?></div>
                    <div class="dash-card-sub">
                        <span>Leads iniciaram conversa</span>
                    </div>
                </div>

                <div class="dash-card">
                    <div class="dash-card-header">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z" /></svg>
                        Ticket Médio
                    </div>
                    <div class="dash-card-value">R$ <?php echo number_format($ticket_medio, 2, ',', '.'); ?></div>
                    <div class="dash-card-sub">
                        <span>Vendas: R$ <?php echo number_format($vendas_aprovadas, 2, ',', '.'); ?></span>
                        <span><?php echo $pix_gerados; ?> PIX gerados</span>
                    </div>
                </div>

                <div class="dash-card span-2">
                    <div class="dash-card-header">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z" /></svg>
                        Seu Desempenho
                        <span style="font-size: 11px; margin-left: 10px; color: var(--muted);"><?php echo $texto_grafico; ?></span>
                    </div>
                    <div class="chart-container">
                        <canvas id="performanceChart"></canvas>
                    </div>
                </div>

                <div class="dash-card span-2">
                    <div class="dash-card-header">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                        Eventos Recentes
                    </div>
                    <div class="dash-log-list">
                        <?php foreach($atividades as $ativ): ?>
                            <?php 
                                $icone_svg = '';
                                if ($ativ['tipo'] == 'venda') {
                                    $icone_svg = '<svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>';
                                } elseif ($ativ['tipo'] == 'pix_gerado') {
                                    $icone_svg = '<svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>';
                                } else {
                                    $icone_svg = '<svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>';
                                }

                                $data_criado = strtotime($ativ['criado_em']);
                                $diff = abs(time() - $data_criado);
                                if ($diff < 60) $tempo = 'agora';
                                elseif ($diff < 3600) $tempo = floor($diff / 60) . 'm';
                                elseif ($diff < 86400) $tempo = floor($diff / 3600) . 'h';
                                else $tempo = floor($diff / 86400) . 'd';
                            ?>
                            <div class="dash-log-item">
                                <div class="log-icon <?php echo htmlspecialchars($ativ['tipo']); ?>">
                                    <?php echo $icone_svg; ?>
                                </div>
                                <div class="log-content">
                                    <p class="log-title"><?php echo htmlspecialchars($ativ['titulo']); ?></p>
                                    <p class="log-desc"><?php echo htmlspecialchars($ativ['descricao']); ?></p>
                                </div>
                                <div class="log-time">
                                    <?php echo $tempo; ?><br>
                                    <?php echo date('d/m, H:i', $data_criado); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if(empty($atividades)): ?>
                            <div style="text-align:center; color: #6b7280; padding: 20px;">Nenhum evento recente.</div>
                        <?php endif; ?>
                    </div>
                    <?php echo paginador($total_atividades, $por_pagina); ?>
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

    const ctx = document.getElementById('performanceChart');
    if (ctx) {
        const chart_ctx = ctx.getContext('2d');

        let gradient = chart_ctx.createLinearGradient(0, 0, 0, 400);
        gradient.addColorStop(0, 'rgba(37, 99, 235, 0.2)');   
        gradient.addColorStop(1, 'rgba(37, 99, 235, 0.0)');

        new Chart(chart_ctx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($grafico_labels); ?>,
                datasets: [{
                    label: 'Receita',
                    data: <?php echo json_encode($grafico_dados); ?>,
                    borderColor: '#2563eb',
                    backgroundColor: gradient,
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#ffffff',
                    pointBorderColor: '#2563eb',
                    pointBorderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    x: {
                        grid: { display: false, drawBorder: false },
                        ticks: { color: '#6b7280', font: { size: 12 } }
                    },
                    y: {
                        grid: { color: 'rgba(0, 0, 0, 0.05)', drawBorder: false },
                        ticks: { display: false },
                        beginAtZero: true // Isso evita o bug do gráfico descer infinitamente
                    }
                }
            }
        });
    }
});
</script>
</body>
</html>