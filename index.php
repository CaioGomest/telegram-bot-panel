<?php declare(strict_types=1);
require_once __DIR__ . '/funcoes/usuario.php';
require_once __DIR__ . '/funcoes/log.php';
require_once __DIR__ . '/funcoes/paginador.php';
require_once __DIR__ . '/conexao.php';
verificarLogin();

$userId = $_SESSION['usuario_id'];
$isAdmin = ehAdmin();

$whereUser = "";
$whereUserVendas = "";
$whereUserLeads = "";
$whereUserAtividades = "";
$whereBotVendas = "";
$whereBotLeads = "";
$botIdSelecionado = 'todos';
$botsFiltro = [];
$mostrarFiltroBot = false;

if (!$isAdmin) {
    // Filtros para Usuário Comum
    // Vendas: Filtra pelo bot_id vinculado aos bots do usuário
    $whereUserVendas = " AND v.bot_id IN (SELECT id FROM bots WHERE id_usuario = $userId) ";
    
    // Leads: Filtra pelo bot_id vinculado aos bots do usuário (assumindo alias 'l' na query principal se necessário, ou sem alias)
    // Como a query original não usa alias, vou adicionar alias nas queries abaixo.
    $whereUserLeads = " AND l.bot_id IN (SELECT id FROM bots WHERE id_usuario = $userId) ";

    // Atividades: Filtra pelo id_usuario
    $whereUserAtividades = " WHERE id_usuario = $userId ";
} else {
    // Admin vê tudo
    $whereUserVendas = "";
    $whereUserLeads = "";
    $whereUserAtividades = "";
}

// Lista de bots para filtro
if ($isAdmin) {
    $stmtBots = $pdo->prepare("
        SELECT 
            id,
            COALESCE(NULLIF(primeiro_nome, ''), NULLIF(nome_usuario, ''), CONCAT('Bot #', id)) AS nome
        FROM bots
        ORDER BY nome ASC
    ");
    $stmtBots->execute();
} else {
    $stmtBots = $pdo->prepare("
        SELECT 
            id,
            COALESCE(NULLIF(primeiro_nome, ''), NULLIF(nome_usuario, ''), CONCAT('Bot #', id)) AS nome
        FROM bots
        WHERE id_usuario = ?
        ORDER BY nome ASC
    ");
    $stmtBots->execute([$userId]);
}
$botsFiltro = $stmtBots->fetchAll(PDO::FETCH_ASSOC);
$mostrarFiltroBot = count($botsFiltro) > 1;
$idsBotsPermitidos = array_map(static function (array $bot): int {
    return (int) $bot['id'];
}, $botsFiltro);

if (isset($_GET['bot_id']) && $_GET['bot_id'] !== 'todos') {
    $botIdInformado = (int) $_GET['bot_id'];
    if ($botIdInformado > 0 && in_array($botIdInformado, $idsBotsPermitidos, true)) {
        $botIdSelecionado = (string) $botIdInformado;
        $whereBotVendas = " AND v.bot_id = $botIdInformado";
        $whereBotLeads = " AND l.bot_id = $botIdInformado";
    }
}

// Filtro de Data
$periodo = $_GET['periodo'] ?? '7dias';
$whereDataVendas = ''; // Para vendas com alias 'v'
$whereDataLeads = '';  // Para leads com alias 'l'
$dataInicio = $_GET['data_inicio'] ?? '';
$dataFim = $_GET['data_fim'] ?? '';
$dataInicioObj = DateTime::createFromFormat('Y-m-d', $dataInicio);
$dataFimObj = DateTime::createFromFormat('Y-m-d', $dataFim);
$datasValidas = $dataInicioObj !== false
    && $dataFimObj !== false
    && $dataInicioObj->format('Y-m-d') === $dataInicio
    && $dataFimObj->format('Y-m-d') === $dataFim;

if ($periodo === 'personalizado' && $datasValidas) {
    if ($dataInicio > $dataFim) {
        $tmp = $dataInicio;
        $dataInicio = $dataFim;
        $dataFim = $tmp;
    }
    $whereDataVendas = "AND DATE(v.criado_em) BETWEEN '$dataInicio' AND '$dataFim'";
    $whereDataLeads = "AND DATE(l.criado_em) BETWEEN '$dataInicio' AND '$dataFim'";
}

switch ($periodo) {
    case 'hoje':
        $whereDataVendas = "AND DATE(v.criado_em) = CURDATE()";
        $whereDataLeads = "AND DATE(l.criado_em) = CURDATE()";
        break;
    case 'ontem':
        $whereDataVendas = "AND DATE(v.criado_em) = CURDATE() - INTERVAL 1 DAY";
        $whereDataLeads = "AND DATE(l.criado_em) = CURDATE() - INTERVAL 1 DAY";
        break;
    case '7dias':
        $whereDataVendas = "AND v.criado_em >= CURDATE() - INTERVAL 7 DAY";
        $whereDataLeads = "AND l.criado_em >= CURDATE() - INTERVAL 7 DAY";
        break;
    case '30dias':
        $whereDataVendas = "AND v.criado_em >= CURDATE() - INTERVAL 30 DAY";
        $whereDataLeads = "AND l.criado_em >= CURDATE() - INTERVAL 30 DAY";
        break;
    case 'total':
        $whereDataVendas = "";
        $whereDataLeads = "";
        break;
    case 'personalizado':
        // Manter como personalizado mesmo se as datas não forem válidas 
        // para que o usuário consiga preencher os inputs de data
        if ($datasValidas) {
            // Se as datas são válidas, usar o WHERE com intervalo
            // (já foi definido acima)
        } else {
            // Se as datas não forem válidas, não aplicar WHERE de data
            // deixando os inputs habilitados para o usuário preencher
            $whereDataVendas = "";
            $whereDataLeads = "";
        }
        break;
    default:
        $whereDataVendas = "AND v.criado_em >= CURDATE() - INTERVAL 7 DAY";
        $whereDataLeads = "AND l.criado_em >= CURDATE() - INTERVAL 7 DAY";
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

// Estatísticas Rápidas
try {
    // 1. Vendas Aprovadas
    $stmt = $pdo->prepare("SELECT SUM(v.valor) FROM vendas v WHERE v.status = 'pago' $whereDataVendas $whereUserVendas $whereBotVendas");
    $stmt->execute();
    $vendasAprovadas = (float) $stmt->fetchColumn();

    // 2. Total Starts
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM leads l WHERE 1=1 $whereDataLeads $whereUserLeads $whereBotLeads");
    $stmt->execute();
    $totalStarts = (int) $stmt->fetchColumn();

    // 3. Taxa de Conversão (PIX Pagos / PIX Gerados)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM vendas v WHERE v.status = 'pago' $whereDataVendas $whereUserVendas $whereBotVendas");
    $stmt->execute();
    $pixPagos = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM vendas v WHERE 1=1 $whereDataVendas $whereUserVendas $whereBotVendas");
    $stmt->execute();
    $pixGerados = (int) $stmt->fetchColumn();

    $taxaConversao = $pixGerados > 0 ? ($pixPagos / $pixGerados) * 100 : 0;

    // 4. Ticket Médio
    $ticketMedio = $pixPagos > 0 ? $vendasAprovadas / $pixPagos : 0;

    // 5. Gráfico Dinâmico baseado no filtro
    $graficoDados = [];
    $graficoLabels = [];
    $textoGrafico = "";

    if ($periodo == 'hoje') {
        $textoGrafico = "HOJE (POR HORA)";
        // Buscar as horas do dia atual que tiveram venda
        for ($i = 0; $i <= date('H'); $i++) {
            $hora = sprintf('%02d:00', $i);
            $graficoLabels[] = $hora;
            
            $stmt = $pdo->prepare("SELECT SUM(v.valor) FROM vendas v WHERE v.status = 'pago' AND DATE(v.criado_em) = CURDATE() AND HOUR(v.criado_em) = ? $whereUserVendas $whereBotVendas");
            $stmt->execute([$i]);
            $graficoDados[] = (float) $stmt->fetchColumn();
        }
    } elseif ($periodo == 'ontem') {
        $textoGrafico = "ONTEM (POR HORA)";
        for ($i = 0; $i <= 23; $i++) {
            $hora = sprintf('%02d:00', $i);
            $graficoLabels[] = $hora;
            
            $stmt = $pdo->prepare("SELECT SUM(v.valor) FROM vendas v WHERE v.status = 'pago' AND DATE(v.criado_em) = CURDATE() - INTERVAL 1 DAY AND HOUR(v.criado_em) = ? $whereUserVendas $whereBotVendas");
            $stmt->execute([$i]);
            $graficoDados[] = (float) $stmt->fetchColumn();
        }
    } elseif ($periodo == '30dias') {
        $textoGrafico = "ÚLTIMOS 30 DIAS";
        for ($i = 29; $i >= 0; $i--) {
            $data = date('Y-m-d', strtotime("-$i days"));
            $graficoLabels[] = date('d/m', strtotime("-$i days"));

            $stmt = $pdo->prepare("SELECT SUM(v.valor) FROM vendas v WHERE v.status = 'pago' AND DATE(v.criado_em) = ? $whereUserVendas $whereBotVendas");
            $stmt->execute([$data]);
            $graficoDados[] = (float) $stmt->fetchColumn();
        }
    } elseif ($periodo == 'total') {
        $textoGrafico = "HISTÓRICO TOTAL (ÚLTIMOS 12 MESES)";
        
        // Preparar array com os últimos 12 meses (incluindo o atual) com valor zero
        $mesesData = [];
        for ($i = 11; $i >= 0; $i--) {
            $mesAno = date('Y-m', strtotime("-$i months"));
            $mesesData[$mesAno] = 0;
        }

        // Buscar os dados agrupados por mês
        $stmt = $pdo->prepare("
            SELECT DATE_FORMAT(v.criado_em, '%Y-%m') as mes, SUM(v.valor) as total 
            FROM vendas v 
            WHERE v.status = 'pago' AND v.criado_em >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) $whereUserVendas $whereBotVendas
            GROUP BY mes 
            ORDER BY mes ASC
        ");
        $stmt->execute();
        $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Preencher os dados encontrados no array base
        foreach ($resultados as $row) {
            if (isset($mesesData[$row['mes']])) {
                $mesesData[$row['mes']] = (float) $row['total'];
            }
        }
        
        // Transformar o array base em labels e dados para o gráfico
        foreach ($mesesData as $mes => $total) {
            $dateObj = DateTime::createFromFormat('Y-m', $mes);
            
            // Traduzir o mês para português
            $mesesPt = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];
            $mesIndex = (int)$dateObj->format('n') - 1;
            $labelFormatada = $mesesPt[$mesIndex] . '/' . $dateObj->format('y');
            
            $graficoLabels[] = $labelFormatada;
            $graficoDados[] = $total;
        }
    } else {
        // 7 dias (Padrão)
        $textoGrafico = "ÚLTIMOS 7 DIAS";
        for ($i = 6; $i >= 0; $i--) {
            $data = date('Y-m-d', strtotime("-$i days"));
            $diaSemana = date('D', strtotime("-$i days"));
            $diasMap = ['Sun'=>'Dom', 'Mon'=>'Seg', 'Tue'=>'Ter', 'Wed'=>'Qua', 'Thu'=>'Qui', 'Fri'=>'Sex', 'Sat'=>'Sáb'];
            $graficoLabels[] = $diasMap[$diaSemana] ?? $diaSemana;

            $stmt = $pdo->prepare("SELECT SUM(v.valor) FROM vendas v WHERE v.status = 'pago' AND DATE(v.criado_em) = ? $whereUserVendas $whereBotVendas");
            $stmt->execute([$data]);
            $graficoDados[] = (float) $stmt->fetchColumn();
        }
    }

    if ($periodo === 'personalizado') {
        $textoGrafico = "PERÍODO PERSONALIZADO";
        $graficoDados = [];
        $graficoLabels = [];
        if ($datasValidas) {
            $dataAtual = new DateTime($dataInicio);
            $dataFinal = new DateTime($dataFim);
            while ($dataAtual <= $dataFinal) {
                $dataIso = $dataAtual->format('Y-m-d');
                $graficoLabels[] = $dataAtual->format('d/m');
                $stmt = $pdo->prepare("SELECT SUM(v.valor) FROM vendas v WHERE v.status = 'pago' AND DATE(v.criado_em) = ? $whereUserVendas $whereBotVendas");
                $stmt->execute([$dataIso]);
                $graficoDados[] = (float) $stmt->fetchColumn();
                $dataAtual->modify('+1 day');
            }
        }
    }


    // 6. Eventos Recentes (Telegram)
    // Para usuários, mostrar apenas eventos relacionados ao Telegram (venda, lead)
    // Para admin, mostra tudo (ou poderia ser só eventos globais, mas aqui seguimos o padrão dashboard)
    $filtrosAtividades = [];
    if (!$isAdmin) {
        $filtrosAtividades['id_usuario'] = $userId;
        $filtrosAtividades['tipos_in'] = ['venda', 'pix_gerado', 'lead'];
    }

    $por_pagina = 10;
    $pagina_atual = isset($_GET['pagina']) ? max(1, (int)$_GET['pagina']) : 1;
    $offset = ($pagina_atual - 1) * $por_pagina;

    $total_atividades = contarAtividades($filtrosAtividades);
    $atividades = listarAtividades($filtrosAtividades, $por_pagina, $offset);

} catch (Exception $e) {
    // Se der erro, define valores padrão para evitar que a página quebre
    error_log("Erro no dashboard: " . $e->getMessage());
    $vendasAprovadas = 0;
    $totalStarts = 0;
    $pixPagos = 0;
    $pixGerados = 0;
    $taxaConversao = 0;
    $ticketMedio = 0;
    $graficoDados = [];
    $graficoLabels = [];
    $textoGrafico = "Erro ao carregar dados";
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
        /* Light Theme Override for Dashboard to match system */
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

        /* Card spanning 2 columns (for Chart and Log) */
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
        .log-icon.pix_gerado { color: #d97706; background: #fff7ed; border-color: #fde68a; } /* Orange */
        .log-icon.lead { color: var(--primary); background: #eff6ff; border-color: #bfdbfe; }

        .log-content { flex: 1; }
        .log-title { font-size: 14px; font-weight: 600; color: var(--text); margin: 0 0 4px 0; }
        .log-desc { font-size: 12px; color: var(--muted); margin: 0; }
        
        .log-time { font-size: 12px; color: var(--muted); text-align: right; }

        /* Progress Bar for Conversion */
        .progress-circle {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: conic-gradient(var(--primary) <?php echo $taxaConversao; ?>%, #e2e8f0 0);
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

        /* Scrollbar custom for logs */
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
                        <input type="date" name="data_inicio" value="<?php echo htmlspecialchars($dataInicio); ?>" <?php echo ($periodo === 'personalizado' ? '' : 'disabled'); ?>>
                        <input type="date" name="data_fim" value="<?php echo htmlspecialchars($dataFim); ?>" <?php echo ($periodo === 'personalizado' ? '' : 'disabled'); ?>>
                    </div>
                    <?php if ($mostrarFiltroBot): ?>
                        <select name="bot_id">
                            <option value="todos" <?php echo ($botIdSelecionado === 'todos' ? 'selected' : ''); ?>>Todos os bots</option>
                            <?php foreach ($botsFiltro as $bot): ?>
                                <option value="<?php echo (int) $bot['id']; ?>" <?php echo ($botIdSelecionado === (string) $bot['id'] ? 'selected' : ''); ?>>
                                    <?php echo htmlspecialchars($bot['nome']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                    <button type="submit">Aplicar</button>
                </form>
            </div>

            <div class="dash-grid">
                <!-- Vendas Aprovadas -->
                <div class="dash-card">
                    <div class="dash-card-header">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        Vendas Aprovadas
                    </div>
                    <div class="dash-card-value">R$ <?php echo number_format($vendasAprovadas, 2, ',', '.'); ?></div>
                    <div class="dash-card-sub">
                        <span></span>
                        <span><?php echo $pixPagos; ?> Aprov.</span>
                    </div>
                </div>

                <!-- Taxa de Conversão -->
                <div class="dash-card">
                    <div class="dash-card-header">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" /></svg>
                        Taxa de Conversão
                    </div>
                    <div class="progress-circle">
                        <div class="progress-inner">
                            <span class="progress-val"><?php echo round($taxaConversao); ?>%</span>
                            <span class="progress-sub">de PIX</span>
                        </div>
                    </div>
                </div>

                <!-- Total Starts -->
                <div class="dash-card">
                    <div class="dash-card-header">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" /></svg>
                        Total Starts
                    </div>
                    <div class="dash-card-value"><?php echo $totalStarts; ?></div>
                    <div class="dash-card-sub">
                        <span>Leads iniciaram conversa</span>
                    </div>
                </div>

                <!-- Ticket Médio -->
                <div class="dash-card">
                    <div class="dash-card-header">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z" /></svg>
                        Ticket Médio
                    </div>
                    <div class="dash-card-value">R$ <?php echo number_format($ticketMedio, 2, ',', '.'); ?></div>
                    <div class="dash-card-sub">
                        <span>Vendas: R$ <?php echo number_format($vendasAprovadas, 2, ',', '.'); ?></span>
                        <span><?php echo $pixGerados; ?> PIX gerados</span>
                    </div>
                </div>

                <!-- Chart (Seu Desempenho) -->
                <div class="dash-card span-2">
                    <div class="dash-card-header">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z" /></svg>
                        Seu Desempenho
                        <span style="font-size: 11px; margin-left: 10px; color: var(--muted);"><?php echo $textoGrafico; ?></span>
                    </div>
                    <div class="chart-container">
                        <canvas id="performanceChart"></canvas>
                    </div>
                </div>

                <!-- Eventos Recentes -->
                <div class="dash-card span-2">
                    <div class="dash-card-header">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                        Eventos Recentes
                    </div>
                    <div class="dash-log-list">
                        <?php foreach($atividades as $ativ): ?>
                            <?php 
                                $iconeSvg = '';
                                if ($ativ['tipo'] == 'venda') {
                                    $iconeSvg = '<svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>';
                                } elseif ($ativ['tipo'] == 'pix_gerado') {
                                    $iconeSvg = '<svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>'; // Relógio/Pendente
                                } else {
                                    $iconeSvg = '<svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>';
                                }

                                // Tempo decorrido básico
                                $dataCriado = strtotime($ativ['criado_em']);
                                $diff = abs(time() - $dataCriado); // abs() garante que não fique negativo
                                if ($diff < 60) $tempo = 'agora';
                                elseif ($diff < 3600) $tempo = floor($diff / 60) . 'm';
                                elseif ($diff < 86400) $tempo = floor($diff / 3600) . 'h';
                                else $tempo = floor($diff / 86400) . 'd';
                            ?>
                            <div class="dash-log-item">
                                <div class="log-icon <?php echo htmlspecialchars($ativ['tipo']); ?>">
                                    <?php echo $iconeSvg; ?>
                                </div>
                                <div class="log-content">
                                    <p class="log-title"><?php echo htmlspecialchars($ativ['titulo']); ?></p>
                                    <p class="log-desc"><?php echo htmlspecialchars($ativ['descricao']); ?></p>
                                </div>
                                <div class="log-time">
                                    <?php echo $tempo; ?><br>
                                    <?php echo date('d/m, H:i', $dataCriado); ?>
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
    // Configurar clique nos botões de período
    const periodoBtns = document.querySelectorAll('.dash-filters a');
    const dateInputs = document.querySelectorAll('.dash-date-group input[type="date"]');
    const filterForm = document.querySelector('.dash-filter-form');
    const periodoHidden = document.querySelector('input[name="periodo"]');
    const dateGroup = document.querySelector('.dash-date-group');

    periodoBtns.forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            
            // Extrair período da URL
            const url = new URL(this.href, window.location.origin);
            const periodoSelecionado = url.searchParams.get('periodo');
            
            // Atualizar o input hidden
            periodoHidden.value = periodoSelecionado;
            
            // Se é personalizado, habilitar inputs de data
            if (periodoSelecionado === 'personalizado') {
                dateInputs.forEach(input => input.disabled = false);
                dateGroup.classList.add('active');
                dateInputs[0].focus(); // Focar no primeiro input
            } else {
                // Otros períodos, desabilitar inputs de data
                dateInputs.forEach(input => input.disabled = true);
                dateGroup.classList.remove('active');
                // Submeter formulário imediatamente
                filterForm.submit();
            }
        });
    });

    // Submeter formulário quando ambas as datas forem preenchidas
    dateInputs.forEach(input => {
        input.addEventListener('change', function() {
            const dataInicio = document.querySelector('input[name="data_inicio"]').value;
            const dataFim = document.querySelector('input[name="data_fim"]').value;
            
            // Se ambas as datas foram preenchidas e o período é personalizado
            if (dataInicio && dataFim && periodoHidden.value === 'personalizado') {
                filterForm.submit();
            }
        });
    });

    // Gráfico de Desempenho
    const ctx = document.getElementById('performanceChart');
    if (ctx) {
        const chartCtx = ctx.getContext('2d');
        
        // Gradiente para a linha (adaptado para tema claro)
        let gradient = chartCtx.createLinearGradient(0, 0, 0, 400);
        gradient.addColorStop(0, 'rgba(37, 99, 235, 0.2)');   
        gradient.addColorStop(1, 'rgba(37, 99, 235, 0.0)');

        new Chart(chartCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($graficoLabels); ?>,
                datasets: [{
                    label: 'Receita',
                    data: <?php echo json_encode($graficoDados); ?>,
                    borderColor: '#2563eb',
                    backgroundColor: gradient,
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4, // Suaviza a curva
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
                        ticks: { display: false }, // Oculta os valores do eixo Y
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