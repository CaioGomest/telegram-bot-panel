<?php declare(strict_types=1);
require_once __DIR__ . '/../funcoes/usuario.php';
require_once __DIR__ . '/../funcoes/log.php';
require_once __DIR__ . '/../funcoes/paginador.php';
require_once __DIR__ . '/../conexao.php';
verificarAdmin();
$caminho_base = '../';

// O log do sistema e' buscado ANTES das consultas de KPI/grafico de proposito: quando o
// pedido e' so' desta lista, a guarda abaixo sai daqui e nenhuma das ~19 consultas do
// painel chega a rodar. Ela nao depende de nada calculado abaixo.
// Exclui 'venda'/'lead'/'pix_gerado' pro log do admin focar em ações administrativas/de sistema.
$filtros_logs = ['excluir_tipos' => ['venda', 'lead', 'pix_gerado']];
$por_pagina = 10;
$offset = (max(1, (int) ($_GET['pagina'] ?? 1)) - 1) * $por_pagina;
$total_atividades = contarAtividades($filtros_logs);
$atividades = listarAtividades($filtros_logs, $por_pagina, $offset);
$mostrar_usuario = true;
$texto_vazio = 'Nenhuma atividade registrada ainda.';

if (pedidoDeBloco('atividade')) {
    include __DIR__ . '/../parciais/lista_atividades.php';
    exit;
}

$periodo = $_GET['periodo'] ?? '7dias';
$where_data_vendas = '';
// Visão do admin é sempre "todos os bots" (removido o filtro por bot da UI) -- ver
// anotacoes/testes/plano-de-testes-24-09.md. $where_bot_vendas fica vazio de propósito,
// só existe pra reaproveitar as mesmas queries de quando o filtro existia.
$where_bot_vendas = '';
$bot_id_selecionado = 'todos';

$data_inicio = $_GET['data_inicio'] ?? '';
$data_fim = $_GET['data_fim'] ?? '';
$data_inicio_obj = DateTime::createFromFormat('Y-m-d', $data_inicio);
$data_fim_obj = DateTime::createFromFormat('Y-m-d', $data_fim);
$datas_validas = $data_inicio_obj !== false
    && $data_fim_obj !== false
    && $data_inicio_obj->format('Y-m-d') === $data_inicio
    && $data_fim_obj->format('Y-m-d') === $data_fim;

// $where_data_metricas é o mesmo filtro de data que $where_data_vendas, só que contra a
// coluna 'data' de metricas_horarias_admin (cache) em vez de 'v.criado_em' (vendas ao vivo) —
// ver o bloco "todos os bots" mais abaixo.
$where_data_metricas = '';

if ($periodo === 'personalizado' && $datas_validas) {
    if ($data_inicio > $data_fim) {
        $tmp = $data_inicio;
        $data_inicio = $data_fim;
        $data_fim = $tmp;
    }
    $where_data_vendas = "AND DATE(v.criado_em) BETWEEN '$data_inicio' AND '$data_fim'";
    $where_data_metricas = "AND data BETWEEN '$data_inicio' AND '$data_fim'";
}

switch ($periodo) {
    case 'hoje':
        $where_data_vendas = "AND DATE(v.criado_em) = CURDATE()";
        $where_data_metricas = "AND data = CURDATE()";
        break;
    case 'ontem':
        $where_data_vendas = "AND DATE(v.criado_em) = CURDATE() - INTERVAL 1 DAY";
        $where_data_metricas = "AND data = CURDATE() - INTERVAL 1 DAY";
        break;
    case '7dias':
        // Chave interna ficou "7dias", mas o pedido original do Caio foi "8d" -- INTERVAL
        // 7 DAY já cobre 8 dias corridos (hoje + 7 pra trás). Ver
        // anotacoes/pedido-filtro-periodo-dashboard.md.
        $where_data_vendas = "AND v.criado_em >= CURDATE() - INTERVAL 7 DAY";
        $where_data_metricas = "AND data >= CURDATE() - INTERVAL 7 DAY";
        break;
    case '30dias':
        $where_data_vendas = "AND v.criado_em >= CURDATE() - INTERVAL 30 DAY";
        $where_data_metricas = "AND data >= CURDATE() - INTERVAL 30 DAY";
        break;
    case 'total':
        $where_data_vendas = "";
        $where_data_metricas = "";
        break;
    case 'personalizado':
        // Manter como personalizado mesmo se as datas não forem válidas
        // para que o usuário consiga preencher os inputs de data
        if ($datas_validas) {
        } else {
            $where_data_vendas = "";
            $where_data_metricas = "";
        }
        break;
    default:
        $where_data_vendas = "AND v.criado_em >= CURDATE() - INTERVAL 7 DAY";
        $where_data_metricas = "AND data >= CURDATE() - INTERVAL 7 DAY";
        $periodo = '7dias';
        break;
}

function montarUrlFiltroAdminDashboard(array $overrides = []): string
{
    $params = [
        'periodo' => $_GET['periodo'] ?? '7dias',
        'data_inicio' => $_GET['data_inicio'] ?? '',
        'data_fim' => $_GET['data_fim'] ?? '',
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

/**
 * "Todos os bots" lê de metricas_horarias_admin (cache pré-calculado por
 * cron/cron_metricas_admin.php) em vez de agregar 'vendas' ao vivo — a plataforma
 * inteira pode ter dezenas de milhões de linhas em 'vendas', mas essa tabela de
 * cache fica sempre pequena (~24 linhas/dia). Com um bot específico selecionado,
 * o volume já é naturalmente pequeno (só as vendas daquele bot), então continua
 * direto em 'vendas' sem precisar de cache. Ver anotacoes/analise-potencia-e-escala.md.
 */
$usa_cache_metricas = ($bot_id_selecionado === 'todos');

if ($usa_cache_metricas) {
    $stmt = $pdo->query("SELECT SUM(faturamento), SUM(comissao), SUM(quantidade) FROM metricas_horarias_admin WHERE 1=1 $where_data_metricas");
    [$total_transacionado, $receita_admin, $total_vendas] = $stmt->fetch(PDO::FETCH_NUM);
    $total_transacionado = (float) $total_transacionado;
    $receita_admin = (float) $receita_admin;
    $total_vendas = (int) $total_vendas;
} else {
    $stmt = $pdo->prepare("SELECT SUM(v.valor) FROM vendas v WHERE v.status = 'pago' $where_data_vendas $where_bot_vendas");
    $stmt->execute();
    $total_transacionado = (float) $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT SUM(v.comissao_admin) FROM vendas v WHERE v.status = 'pago' $where_data_vendas $where_bot_vendas");
    $stmt->execute();
    $receita_admin = (float) $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM vendas v WHERE v.status = 'pago' $where_data_vendas $where_bot_vendas");
    $stmt->execute();
    $total_vendas = (int) $stmt->fetchColumn();
}

$stmt = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE perfil != 'admin'");
$total_usuarios = (int) $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM bots");
$total_bots = (int) $stmt->fetchColumn();

$grafico_dados = [];
$grafico_labels = [];
$texto_grafico = "";

if ($periodo == 'hoje') {
    $texto_grafico = "HOJE (POR HORA)";
    if ($usa_cache_metricas) {
        $stmt = $pdo->prepare("SELECT hora, comissao FROM metricas_horarias_admin WHERE data = CURDATE()");
        $stmt->execute();
        $por_hora = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'comissao', 'hora');
    } else {
        // Uma consulta agregada em vez de uma por ponto do grafico, e range em criado_em
        // no lugar de DATE()/HOUR() no WHERE -- funcao na coluna impede o indice de ser
        // usado, entao cada iteracao varria a tabela de vendas inteira. Medido antes:
        // 'ontem' com um bot selecionado levava 31,9s.
        $stmt = $pdo->prepare("SELECT HOUR(v.criado_em) AS h, SUM(v.comissao_admin) AS t FROM vendas v WHERE v.status = 'pago' AND v.criado_em >= CURDATE() AND v.criado_em < CURDATE() + INTERVAL 1 DAY $where_bot_vendas GROUP BY h");
        $stmt->execute();
        $por_hora = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 't', 'h');
    }
    for ($i = 0; $i <= date('H'); $i++) {
        $grafico_labels[] = sprintf('%02d:00', $i);
        $grafico_dados[] = (float) ($por_hora[$i] ?? 0);
    }
} elseif ($periodo == 'ontem') {
    $texto_grafico = "ONTEM (POR HORA)";
    if ($usa_cache_metricas) {
        $stmt = $pdo->prepare("SELECT hora, comissao FROM metricas_horarias_admin WHERE data = CURDATE() - INTERVAL 1 DAY");
        $stmt->execute();
        $por_hora = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'comissao', 'hora');
    } else {
        $stmt = $pdo->prepare("SELECT HOUR(v.criado_em) AS h, SUM(v.comissao_admin) AS t FROM vendas v WHERE v.status = 'pago' AND v.criado_em >= CURDATE() - INTERVAL 1 DAY AND v.criado_em < CURDATE() $where_bot_vendas GROUP BY h");
        $stmt->execute();
        $por_hora = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 't', 'h');
    }
    for ($i = 0; $i <= 23; $i++) {
        $grafico_labels[] = sprintf('%02d:00', $i);
        $grafico_dados[] = (float) ($por_hora[$i] ?? 0);
    }
} elseif ($periodo == '30dias') {
    $texto_grafico = "ÚLTIMOS 30 DIAS";
    if ($usa_cache_metricas) {
        $stmt = $pdo->prepare("SELECT data, SUM(comissao) AS total FROM metricas_horarias_admin WHERE data >= CURDATE() - INTERVAL 29 DAY GROUP BY data");
        $stmt->execute();
        $por_dia = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'total', 'data');
    } else {
        $stmt = $pdo->prepare("SELECT DATE(v.criado_em) AS d, SUM(v.comissao_admin) AS t FROM vendas v WHERE v.status = 'pago' AND v.criado_em >= CURDATE() - INTERVAL 29 DAY AND v.criado_em < CURDATE() + INTERVAL 1 DAY $where_bot_vendas GROUP BY d");
        $stmt->execute();
        $por_dia = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 't', 'd');
    }
    for ($i = 29; $i >= 0; $i--) {
        $data = date('Y-m-d', strtotime("-$i days"));
        $grafico_labels[] = date('d/m', strtotime("-$i days"));
        $grafico_dados[] = (float) ($por_dia[$data] ?? 0);
    }
} elseif ($periodo == 'total') {
    $texto_grafico = "HISTÓRICO TOTAL (ÚLTIMOS 12 MESES)";

    $meses_data = [];
    for ($i = 11; $i >= 0; $i--) {
        $mes_ano = date('Y-m', strtotime("-$i months"));
        $meses_data[$mes_ano] = 0;
    }

    if ($usa_cache_metricas) {
        $stmt = $pdo->prepare("
            SELECT DATE_FORMAT(data, '%Y-%m') as mes, SUM(comissao) as total
            FROM metricas_horarias_admin
            WHERE data >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
            GROUP BY mes
            ORDER BY mes ASC
        ");
        $stmt->execute();
    } else {
        $stmt = $pdo->prepare("
            SELECT DATE_FORMAT(v.criado_em, '%Y-%m') as mes, SUM(v.comissao_admin) as total
            FROM vendas v
            WHERE v.status = 'pago' AND v.criado_em >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) $where_bot_vendas
            GROUP BY mes
            ORDER BY mes ASC
        ");
        $stmt->execute();
    }
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
    $texto_grafico = "ÚLTIMOS 8 DIAS";
    if ($usa_cache_metricas) {
        $stmt = $pdo->prepare("SELECT data, SUM(comissao) AS total FROM metricas_horarias_admin WHERE data >= CURDATE() - INTERVAL 7 DAY GROUP BY data");
        $stmt->execute();
        $por_dia = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'total', 'data');
    } else {
        $stmt = $pdo->prepare("SELECT DATE(v.criado_em) AS d, SUM(v.comissao_admin) AS t FROM vendas v WHERE v.status = 'pago' AND v.criado_em >= CURDATE() - INTERVAL 7 DAY AND v.criado_em < CURDATE() + INTERVAL 1 DAY $where_bot_vendas GROUP BY d");
        $stmt->execute();
        $por_dia = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 't', 'd');
    }
    for ($i = 7; $i >= 0; $i--) {
        $data = date('Y-m-d', strtotime("-$i days"));
        $dia_semana = date('D', strtotime("-$i days"));
        $dias_map = ['Sun'=>'Dom', 'Mon'=>'Seg', 'Tue'=>'Ter', 'Wed'=>'Qua', 'Thu'=>'Qui', 'Fri'=>'Sex', 'Sat'=>'Sáb'];
        $grafico_labels[] = $dias_map[$dia_semana] ?? $dia_semana;
        $grafico_dados[] = (float) ($por_dia[$data] ?? 0);
    }
}

if ($periodo === 'personalizado') {
    $texto_grafico = "PERÍODO PERSONALIZADO";
    $grafico_dados = [];
    $grafico_labels = [];
    if ($datas_validas) {
        if ($usa_cache_metricas) {
            $stmt = $pdo->prepare("SELECT data, SUM(comissao) AS total FROM metricas_horarias_admin WHERE data BETWEEN ? AND ? GROUP BY data");
            $stmt->execute([$data_inicio, $data_fim]);
            $por_dia = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'total', 'data');
        } else {
            $stmt = $pdo->prepare("SELECT DATE(v.criado_em) AS d, SUM(v.comissao_admin) AS t FROM vendas v WHERE v.status = 'pago' AND v.criado_em >= ? AND v.criado_em < DATE_ADD(?, INTERVAL 1 DAY) $where_bot_vendas GROUP BY d");
            $stmt->execute([$data_inicio, $data_fim]);
            $por_dia = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 't', 'd');
        }
        $data_atual = new DateTime($data_inicio);
        $data_final = new DateTime($data_fim);
        while ($data_atual <= $data_final) {
            $data_iso = $data_atual->format('Y-m-d');
            $grafico_labels[] = $data_atual->format('d/m');
            $grafico_dados[] = (float) ($por_dia[$data_iso] ?? 0);
            $data_atual->modify('+1 day');
        }
    }
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel Admin</title>
    <?php include __DIR__ . '/../tema_inline.php'; ?>
    <link rel="stylesheet" href="../assets/css/coyote.css?v=<?php echo @filemtime(__DIR__.'/../assets/css/coyote.css'); ?>">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
<div class="layout-painel">
    <?php include __DIR__ . '/../barra_lateral.php'; ?>

    <main class="conteudo-principal">
        <div class="cabecalho-pagina">
            <div>
                <h1>Visão Geral</h1>
                <p>Volume, receita e atividade de toda a plataforma.</p>
            </div>
            <div class="acoes-cabecalho">
                <div class="seletor-periodo">
                    <a href="<?php echo htmlspecialchars(montarUrlFiltroAdminDashboard(['periodo' => 'hoje'])); ?>" class="periodo-item<?php echo ($periodo == 'hoje' ? ' ativo' : ''); ?>">Hoje</a>
                    <a href="<?php echo htmlspecialchars(montarUrlFiltroAdminDashboard(['periodo' => 'ontem'])); ?>" class="periodo-item<?php echo ($periodo == 'ontem' ? ' ativo' : ''); ?>">Ontem</a>
                    <a href="<?php echo htmlspecialchars(montarUrlFiltroAdminDashboard(['periodo' => '7dias'])); ?>" class="periodo-item<?php echo ($periodo == '7dias' ? ' ativo' : ''); ?>">8 dias</a>
                    <a href="<?php echo htmlspecialchars(montarUrlFiltroAdminDashboard(['periodo' => '30dias'])); ?>" class="periodo-item<?php echo ($periodo == '30dias' ? ' ativo' : ''); ?>">30 dias</a>
                    <a href="<?php echo htmlspecialchars(montarUrlFiltroAdminDashboard(['periodo' => 'total'])); ?>" class="periodo-item<?php echo ($periodo == 'total' ? ' ativo' : ''); ?>">Total</a>
                    <?php // "Personalizado" fora da barra por enquanto (2026-09-17) -- mesma decisão de index.php:
                          // o protótipo só tem os 5 períodos fixos. Continua acessível por URL. ?>
                    <?php if (false): ?>
                    <a href="<?php echo htmlspecialchars(montarUrlFiltroAdminDashboard(['periodo' => 'personalizado'])); ?>" class="periodo-item<?php echo ($periodo == 'personalizado' ? ' ativo' : ''); ?>">Personalizado</a>
                    <?php endif; ?>
                </div>
                <form method="GET" class="form-periodo">
                    <input type="hidden" name="periodo" value="<?php echo htmlspecialchars($periodo); ?>">
                    <div class="grupo-data-personalizada<?php echo ($periodo === 'personalizado' ? ' ativo' : ''); ?>">
                        <input type="date" name="data_inicio" value="<?php echo htmlspecialchars($data_inicio); ?>" <?php echo ($periodo === 'personalizado' ? '' : 'disabled'); ?>>
                        <input type="date" name="data_fim" value="<?php echo htmlspecialchars($data_fim); ?>" <?php echo ($periodo === 'personalizado' ? '' : 'disabled'); ?>>
                    </div>
                </form>
                <button type="button" class="alternador-tema" onclick="alternarTema()" aria-label="Alternar tema">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"></path></svg>
                    Tema
                </button>
            </div>
        </div>


        <div class="">
        <div class="grade-kpi">
            <div class="cartao-kpi">
                <div class="cartao-kpi-cabecalho">
                    <div class="icone-kpi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg></div>
                    <span class="rotulo-kpi">Volume total</span>
                </div>
                <div class="valor-kpi">R$ <?php echo number_format($total_transacionado, 2, ',', '.'); ?></div>
                <div class="rodape-kpi"><span>Vendas brutas (global)</span></div>
            </div>

            <div class="cartao-kpi">
                <div class="cartao-kpi-cabecalho">
                    <div class="icone-kpi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"></path></svg></div>
                    <span class="rotulo-kpi">Qtd. vendas</span>
                </div>
                <div class="valor-kpi"><?php echo $total_vendas; ?></div>
                <div class="rodape-kpi"><span>Vendas aprovadas</span></div>
            </div>

            <div class="cartao-kpi">
                <div class="cartao-kpi-cabecalho">
                    <div class="icone-kpi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path></svg></div>
                    <span class="rotulo-kpi">Receita líquida</span>
                </div>
                <div class="valor-kpi" style="color: var(--or);">R$ <?php echo number_format($receita_admin, 2, ',', '.'); ?></div>
                <div class="rodape-kpi"><span>Sua comissão acumulada</span></div>
            </div>

            <div class="cartao-kpi">
                <div class="cartao-kpi-cabecalho">
                    <div class="icone-kpi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg></div>
                    <span class="rotulo-kpi">Usuários</span>
                </div>
                <div class="valor-kpi"><?php echo $total_usuarios; ?></div>
                <div class="rodape-kpi"><span>Clientes registrados</span></div>
            </div>

            <div class="cartao-kpi">
                <div class="cartao-kpi-cabecalho">
                    <div class="icone-kpi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path></svg></div>
                    <span class="rotulo-kpi">Bots ativos</span>
                </div>
                <div class="valor-kpi"><?php echo $total_bots; ?></div>
                <div class="rodape-kpi"><span>Bots na plataforma</span></div>
            </div>
        </div>

        <div class="grade-dashboard-baixo">
            <div class="painel">
                <div class="painel-cabecalho">
                    <h2>Receita da plataforma</h2>
                    <span class="texto-suave"><?php echo $texto_grafico; ?></span>
                </div>
                <div class="area-grafico">
                    <canvas id="adminChart"></canvas>
                </div>
            </div>

            <div class="painel">
                <div class="painel-cabecalho">
                    <h2>Log do sistema</h2>
                </div>
                <?php echo inicioBlocoPaginado('atividade'); ?>
                <?php include __DIR__ . '/../parciais/lista_atividades.php'; ?>
                <?php echo fimBlocoPaginado(); ?>
            </div>
        </div>
        </div>
    </main>
</div>

<script src="../assets/js/tema.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/tema.js'); ?>"></script>
<script src="../assets/js/paginacao.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/paginacao.js'); ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const periodo_btns = document.querySelectorAll('.seletor-periodo a');
    const date_inputs = document.querySelectorAll('.grupo-data-personalizada input[type="date"]');
    const filter_form = document.querySelector('.form-periodo');
    const periodo_hidden = document.querySelector('input[name="periodo"]');
    const date_group = document.querySelector('.grupo-data-personalizada');

    periodo_btns.forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();

            const url = new URL(this.href, window.location.origin);
            const periodo_selecionado = url.searchParams.get('periodo');

            periodo_hidden.value = periodo_selecionado;

            if (periodo_selecionado === 'personalizado') {
                date_inputs.forEach(input => input.disabled = false);
                date_group.classList.add('ativo');
                date_inputs[0].focus();
            } else {
                date_inputs.forEach(input => input.disabled = true);
                date_group.classList.remove('ativo');
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
        const estilo_raiz = getComputedStyle(document.documentElement);
        const cor_acento = estilo_raiz.getPropertyValue('--or').trim() || '#ff6a1a';
        const cor_muda = estilo_raiz.getPropertyValue('--m').trim() || '#8b9099';
        const cor_borda = estilo_raiz.getPropertyValue('--bd').trim() || 'rgba(255,255,255,.09)';

        const chart_ctx = ctx.getContext('2d');

        let gradient = chart_ctx.createLinearGradient(0, 0, 0, 400);
        gradient.addColorStop(0, cor_acento + '33');
        gradient.addColorStop(1, cor_acento + '00');

        new Chart(chart_ctx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($grafico_labels); ?>,
                datasets: [{
                    label: 'Receita Admin',
                    data: <?php echo json_encode($grafico_dados); ?>,
                    borderColor: cor_acento,
                    backgroundColor: gradient,
                    borderWidth: 2.5,
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: cor_acento,
                    pointBorderColor: cor_acento,
                    pointBorderWidth: 0,
                    pointRadius: 0,
                    pointHoverRadius: 5
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
                        ticks: { color: cor_muda, font: { size: 11, family: "'JetBrains Mono', monospace" } }
                    },
                    y: {
                        grid: { color: cor_borda, drawBorder: false },
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
