<?php declare(strict_types=1);
require_once __DIR__ . '/funcoes/usuario.php';
require_once __DIR__ . '/funcoes/log.php';
require_once __DIR__ . '/funcoes/paginador.php';
require_once __DIR__ . '/funcoes/ranking.php';
require_once __DIR__ . '/conexao.php';
bloquearAdmin();

$user_id = $_SESSION['usuario_id'];

$where_user = "";
$where_user_vendas = "";
$where_user_leads = "";
$where_user_atividades = "";
$where_bot_vendas = "";
$where_bot_leads = "";
$bot_id_selecionado = 'todos';
$bots_filtro = [];
$mostrar_filtro_bot = false;

// Esta tela é do usuário. O admin nunca chega aqui (bloquearAdmin() manda pra
// /admin/dashboard), então não existe mais o ramo "admin vê tudo" que havia aqui.
$where_user_vendas = " AND v.bot_id IN (SELECT id FROM bots WHERE id_usuario = $user_id) ";
$where_user_leads = " AND l.bot_id IN (SELECT id FROM bots WHERE id_usuario = $user_id) ";
$where_user_atividades = " WHERE id_usuario = $user_id ";

$stmt_bots = $pdo->prepare("
    SELECT 
        id,
        COALESCE(NULLIF(primeiro_nome, ''), NULLIF(nome_usuario, ''), CONCAT('Bot #', id)) AS nome
    FROM bots
    WHERE id_usuario = ?
    ORDER BY nome ASC
");
$stmt_bots->execute([$user_id]);
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

// Mini-ranking da dashboard: top 5 da campanha vigente. Try/catch próprio -- é o mesmo
// raciocínio de tentativas_login e membros_grupos.em_renovacao: uma instalação que ainda não
// rodou atualiza_banco.php (sem as tabelas de campanhas_ranking) não pode derrubar a
// dashboard inteira por causa de um widget que é só um resumo do que já existe em /ranking.
$campanha_dash = null;
$ranking_dash = null;
try {
    $campanha_dash = buscarCampanhaVigente();
    if ($campanha_dash && $campanha_dash['estado'] !== 'agendada') {
        $ranking_dash = buscarRankingCampanha((int) $campanha_dash['id'], $user_id);
    }
} catch (\Throwable $e) {
    error_log('[dashboard] mini-ranking indisponível: ' . $e->getMessage());
}

$periodo = $_GET['periodo'] ?? '7dias';
$where_data_vendas = '';
$where_data_leads = '';
// Filtro de data equivalente, mas pra ler de metricas_horarias_usuario (cache) em vez
// de agregar vendas/leads ao vivo -- ver bloco "usa_cache_metricas" mais abaixo.
$where_data_metricas = '';
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
    $where_data_metricas = "AND data BETWEEN '$data_inicio' AND '$data_fim'";
}

switch ($periodo) {
    case 'hoje':
        $where_data_vendas = "AND DATE(v.criado_em) = CURDATE()";
        $where_data_leads = "AND DATE(l.criado_em) = CURDATE()";
        $where_data_metricas = "AND data = CURDATE()";
        break;
    case 'ontem':
        $where_data_vendas = "AND DATE(v.criado_em) = CURDATE() - INTERVAL 1 DAY";
        $where_data_leads = "AND DATE(l.criado_em) = CURDATE() - INTERVAL 1 DAY";
        $where_data_metricas = "AND data = CURDATE() - INTERVAL 1 DAY";
        break;
    case '7dias':
        // Chave interna ficou "7dias" (não vale a pena renomear só por isso), mas o
        // pedido original do Caio foi "8d" -- INTERVAL 7 DAY já cobre 8 dias corridos
        // (hoje + 7 pra trás). Ver anotacoes/pedido-filtro-periodo-dashboard.md.
        $where_data_vendas = "AND v.criado_em >= CURDATE() - INTERVAL 7 DAY";
        $where_data_leads = "AND l.criado_em >= CURDATE() - INTERVAL 7 DAY";
        $where_data_metricas = "AND data >= CURDATE() - INTERVAL 7 DAY";
        break;
    case '30dias':
        $where_data_vendas = "AND v.criado_em >= CURDATE() - INTERVAL 30 DAY";
        $where_data_leads = "AND l.criado_em >= CURDATE() - INTERVAL 30 DAY";
        $where_data_metricas = "AND data >= CURDATE() - INTERVAL 30 DAY";
        break;
    case 'total':
        $where_data_vendas = "";
        $where_data_leads = "";
        $where_data_metricas = "";
        break;
    case 'personalizado':
        // Manter como personalizado mesmo se as datas não forem válidas
        // para que o usuário consiga preencher os inputs de data
        if ($datas_validas) {
        } else {
            $where_data_vendas = "";
            $where_data_leads = "";
            $where_data_metricas = "";
        }
        break;
    default:
        $where_data_vendas = "AND v.criado_em >= CURDATE() - INTERVAL 7 DAY";
        $where_data_leads = "AND l.criado_em >= CURDATE() - INTERVAL 7 DAY";
        $where_data_metricas = "AND data >= CURDATE() - INTERVAL 7 DAY";
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

// "Todos os bots" lê de metricas_horarias_usuario (cache pré-calculado por
// cron/cron_metricas_admin.php) em vez de agregar vendas/leads ao vivo -- um usuário
// com muitos anos de histórico (ou o admin vendo a plataforma inteira) pode ter
// milhões de linhas, e as mesmas 4 consultas rodavam a cada carregamento de página.
// Com um bot específico selecionado, o cache não serve (só agrega por usuário, não
// por bot), então cai de volta pra query ao vivo -- ver anotacoes/analise-potencia-e-escala.md.
$usa_cache_metricas = ($bot_id_selecionado === 'todos');

// A lista de atividade e' buscada ANTES do bloco pesado de KPI/grafico de proposito:
// quando o pedido e' so' desta lista, a guarda abaixo sai daqui e nenhuma das ~20
// consultas do painel chega a rodar. Ela so' depende de $user_id e do ?pagina=.
// Só eventos do próprio usuário e só os que vêm do Telegram (venda, lead).
$filtros_atividades = [
    'id_usuario' => $user_id,
    'tipos_in'   => ['venda', 'pix_gerado', 'lead'],
];
$por_pagina = 10;
$offset = (max(1, (int) ($_GET['pagina'] ?? 1)) - 1) * $por_pagina;
$total_atividades = contarAtividades($filtros_atividades);
$atividades = listarAtividades($filtros_atividades, $por_pagina, $offset);

if (pedidoDeBloco('atividade')) {
    include __DIR__ . '/parciais/lista_atividades.php';
    exit;
}

try {
    if ($usa_cache_metricas) {
        $where_usuario_metricas = "AND id_usuario = $user_id";
        $stmt = $pdo->query("SELECT SUM(valor_pago), SUM(qtd_paga), SUM(qtd_gerada), SUM(qtd_leads) FROM metricas_horarias_usuario WHERE 1=1 $where_data_metricas $where_usuario_metricas");
        [$vendas_aprovadas, $pix_pagos, $pix_gerados, $total_starts] = $stmt->fetch(PDO::FETCH_NUM);
        $vendas_aprovadas = (float) $vendas_aprovadas;
        $pix_pagos = (int) $pix_pagos;
        $pix_gerados = (int) $pix_gerados;
        $total_starts = (int) $total_starts;
    } else {
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
    }

    $taxa_conversao = $pix_gerados > 0 ? ($pix_pagos / $pix_gerados) * 100 : 0;

    $ticket_medio = $pix_pagos > 0 ? $vendas_aprovadas / $pix_pagos : 0;

    // Variação vs período anterior equivalente (ex. "+18,4% vs semana anterior"), pro
    // card de destaque no topo do mobile. "Total" não tem "período anterior" que faça
    // sentido, então não mostra selo nesse caso.
    $mostra_variacao = true;
    switch ($periodo) {
        case 'hoje':
            $data_ini_ant = date('Y-m-d', strtotime('-1 day'));
            $data_fim_ant = $data_ini_ant;
            break;
        case 'ontem':
            $data_ini_ant = date('Y-m-d', strtotime('-2 days'));
            $data_fim_ant = $data_ini_ant;
            break;
        case '30dias':
            $data_ini_ant = date('Y-m-d', strtotime('-60 days'));
            $data_fim_ant = date('Y-m-d', strtotime('-31 days'));
            break;
        case 'personalizado':
            if ($datas_validas) {
                $dias_periodo = (int) ((strtotime($data_fim) - strtotime($data_inicio)) / 86400) + 1;
                $data_fim_ant = date('Y-m-d', strtotime($data_inicio . ' -1 day'));
                $data_ini_ant = date('Y-m-d', strtotime($data_fim_ant . ' -' . ($dias_periodo - 1) . ' days'));
            } else {
                $mostra_variacao = false;
            }
            break;
        case 'total':
            $mostra_variacao = false;
            break;
        default: // 7dias (na verdade 8 dias -- ver INTERVAL 7 DAY acima)
            $data_ini_ant = date('Y-m-d', strtotime('-15 days'));
            $data_fim_ant = date('Y-m-d', strtotime('-8 days'));
            break;
    }

    $variacao_percentual = null;
    if ($mostra_variacao) {
        if ($usa_cache_metricas) {
            $stmt = $pdo->prepare("SELECT SUM(valor_pago) FROM metricas_horarias_usuario WHERE data BETWEEN ? AND ? $where_usuario_metricas");
            $stmt->execute([$data_ini_ant, $data_fim_ant]);
            $vendas_periodo_anterior = (float) $stmt->fetchColumn();
        } else {
            $stmt = $pdo->prepare("SELECT SUM(v.valor) FROM vendas v WHERE v.status = 'pago' AND DATE(v.criado_em) BETWEEN ? AND ? $where_user_vendas $where_bot_vendas");
            $stmt->execute([$data_ini_ant, $data_fim_ant]);
            $vendas_periodo_anterior = (float) $stmt->fetchColumn();
        }

        if ($vendas_periodo_anterior > 0) {
            $variacao_percentual = (($vendas_aprovadas - $vendas_periodo_anterior) / $vendas_periodo_anterior) * 100;
        } elseif ($vendas_aprovadas > 0) {
            $variacao_percentual = 100.0;
        } else {
            $variacao_percentual = 0.0;
        }
    }

    $grafico_dados = [];
    $grafico_labels = [];
    $texto_grafico = "";

    if ($periodo == 'hoje') {
        $texto_grafico = "HOJE (POR HORA)";
        if ($usa_cache_metricas) {
            $stmt = $pdo->prepare("SELECT hora, valor_pago FROM metricas_horarias_usuario WHERE data = CURDATE() $where_usuario_metricas");
            $stmt->execute();
            $por_hora = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'valor_pago', 'hora');
        } else {
            // Uma consulta agregada em vez de uma por hora. E range em criado_em em vez de
            // DATE()/HOUR() no WHERE: função na coluna impede o índice de ser usado.
            $stmt = $pdo->prepare("SELECT HOUR(v.criado_em) AS h, SUM(v.valor) AS t FROM vendas v WHERE v.status = 'pago' AND v.criado_em >= CURDATE() AND v.criado_em < CURDATE() + INTERVAL 1 DAY $where_user_vendas $where_bot_vendas GROUP BY h");
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
            $stmt = $pdo->prepare("SELECT hora, valor_pago FROM metricas_horarias_usuario WHERE data = CURDATE() - INTERVAL 1 DAY $where_usuario_metricas");
            $stmt->execute();
            $por_hora = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'valor_pago', 'hora');
        } else {
            $stmt = $pdo->prepare("SELECT HOUR(v.criado_em) AS h, SUM(v.valor) AS t FROM vendas v WHERE v.status = 'pago' AND v.criado_em >= CURDATE() - INTERVAL 1 DAY AND v.criado_em < CURDATE() $where_user_vendas $where_bot_vendas GROUP BY h");
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
            $stmt = $pdo->prepare("SELECT data, SUM(valor_pago) AS total FROM metricas_horarias_usuario WHERE data >= CURDATE() - INTERVAL 29 DAY $where_usuario_metricas GROUP BY data");
            $stmt->execute();
            $por_dia = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'total', 'data');
        } else {
            $stmt = $pdo->prepare("SELECT DATE(v.criado_em) AS d, SUM(v.valor) AS t FROM vendas v WHERE v.status = 'pago' AND v.criado_em >= CURDATE() - INTERVAL 29 DAY AND v.criado_em < CURDATE() + INTERVAL 1 DAY $where_user_vendas $where_bot_vendas GROUP BY d");
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
                SELECT DATE_FORMAT(data, '%Y-%m') as mes, SUM(valor_pago) as total
                FROM metricas_horarias_usuario
                WHERE data >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) $where_usuario_metricas
                GROUP BY mes
                ORDER BY mes ASC
            ");
            $stmt->execute();
        } else {
            $stmt = $pdo->prepare("
                SELECT DATE_FORMAT(v.criado_em, '%Y-%m') as mes, SUM(v.valor) as total
                FROM vendas v
                WHERE v.status = 'pago' AND v.criado_em >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) $where_user_vendas $where_bot_vendas
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
            $stmt = $pdo->prepare("SELECT data, SUM(valor_pago) AS total FROM metricas_horarias_usuario WHERE data >= CURDATE() - INTERVAL 7 DAY $where_usuario_metricas GROUP BY data");
            $stmt->execute();
            $por_dia = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'total', 'data');
        }
        for ($i = 7; $i >= 0; $i--) {
            $data = date('Y-m-d', strtotime("-$i days"));
            $dia_semana = date('D', strtotime("-$i days"));
            $dias_map = ['Sun'=>'Dom', 'Mon'=>'Seg', 'Tue'=>'Ter', 'Wed'=>'Qua', 'Thu'=>'Qui', 'Fri'=>'Sex', 'Sat'=>'Sáb'];
            $grafico_labels[] = $dias_map[$dia_semana] ?? $dia_semana;

            if ($usa_cache_metricas) {
                $grafico_dados[] = (float) ($por_dia[$data] ?? 0);
            } else {
                $stmt = $pdo->prepare("SELECT SUM(v.valor) FROM vendas v WHERE v.status = 'pago' AND DATE(v.criado_em) = ? $where_user_vendas $where_bot_vendas");
                $stmt->execute([$data]);
                $grafico_dados[] = (float) $stmt->fetchColumn();
            }
        }
    }

    if ($periodo === 'personalizado') {
        $texto_grafico = "PERÍODO PERSONALIZADO";
        $grafico_dados = [];
        $grafico_labels = [];
        if ($datas_validas) {
            if ($usa_cache_metricas) {
                $stmt = $pdo->prepare("SELECT data, SUM(valor_pago) AS total FROM metricas_horarias_usuario WHERE data BETWEEN ? AND ? $where_usuario_metricas GROUP BY data");
                $stmt->execute([$data_inicio, $data_fim]);
                $por_dia = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'total', 'data');
            }
            $data_atual = new DateTime($data_inicio);
            $data_final = new DateTime($data_fim);
            while ($data_atual <= $data_final) {
                $data_iso = $data_atual->format('Y-m-d');
                $grafico_labels[] = $data_atual->format('d/m');
                if ($usa_cache_metricas) {
                    $grafico_dados[] = (float) ($por_dia[$data_iso] ?? 0);
                } else {
                    $stmt = $pdo->prepare("SELECT SUM(v.valor) FROM vendas v WHERE v.status = 'pago' AND DATE(v.criado_em) = ? $where_user_vendas $where_bot_vendas");
                    $stmt->execute([$data_iso]);
                    $grafico_dados[] = (float) $stmt->fetchColumn();
                }
                $data_atual->modify('+1 day');
            }
        }
    }


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
}



?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard</title>
    <?php include 'tema_inline.php'; ?>
    <link rel="stylesheet" href="assets/css/coyote.css?v=<?php echo @filemtime(__DIR__.'/assets/css/coyote.css'); ?>">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
<div class="layout-painel">
    <?php include 'barra_lateral.php'; ?>

    <main class="conteudo-principal">
        <div class="cabecalho-pagina">
            <div>
                <h1>Dashboard</h1>
                <p>Acompanhe vendas, conversão e atividade dos seus bots.</p>
            </div>
            <div class="acoes-cabecalho">
                <div class="seletor-periodo">
                    <a href="<?php echo htmlspecialchars(montarUrlFiltroDashboard(['periodo' => 'hoje'])); ?>" class="periodo-item<?php echo ($periodo == 'hoje' ? ' ativo' : ''); ?>">Hoje</a>
                    <a href="<?php echo htmlspecialchars(montarUrlFiltroDashboard(['periodo' => 'ontem'])); ?>" class="periodo-item<?php echo ($periodo == 'ontem' ? ' ativo' : ''); ?>">Ontem</a>
                    <a href="<?php echo htmlspecialchars(montarUrlFiltroDashboard(['periodo' => '7dias'])); ?>" class="periodo-item<?php echo ($periodo == '7dias' ? ' ativo' : ''); ?>">8 dias</a>
                    <a href="<?php echo htmlspecialchars(montarUrlFiltroDashboard(['periodo' => '30dias'])); ?>" class="periodo-item<?php echo ($periodo == '30dias' ? ' ativo' : ''); ?>">30 dias</a>
                    <a href="<?php echo htmlspecialchars(montarUrlFiltroDashboard(['periodo' => 'total'])); ?>" class="periodo-item<?php echo ($periodo == 'total' ? ' ativo' : ''); ?>">Total</a>
                    <?php // "Personalizado" fora da barra por enquanto (2026-09-17) -- o protótipo só tem os 5
                          // períodos fixos. Pra trazer de volta é só reativar o if abaixo; o período em si
                          // continua funcionando por URL (?periodo=personalizado&data_inicio=...&data_fim=...). ?>
                    <?php if (false): ?>
                    <a href="<?php echo htmlspecialchars(montarUrlFiltroDashboard(['periodo' => 'personalizado'])); ?>" class="periodo-item<?php echo ($periodo == 'personalizado' ? ' ativo' : ''); ?>">Personalizado</a>
                    <?php endif; ?>
                </div>
                <form method="GET" class="form-periodo">
                    <input type="hidden" name="periodo" value="<?php echo htmlspecialchars($periodo); ?>">
                    <div class="grupo-data-personalizada<?php echo ($periodo === 'personalizado' ? ' ativo' : ''); ?>">
                        <input type="date" name="data_inicio" value="<?php echo htmlspecialchars($data_inicio); ?>" <?php echo ($periodo === 'personalizado' ? '' : 'disabled'); ?>>
                        <input type="date" name="data_fim" value="<?php echo htmlspecialchars($data_fim); ?>" <?php echo ($periodo === 'personalizado' ? '' : 'disabled'); ?>>
                    </div>
                    <?php if ($mostrar_filtro_bot): ?>
                        <select name="bot_id" onchange="this.form.submit()">
                            <option value="todos" <?php echo ($bot_id_selecionado === 'todos' ? 'selected' : ''); ?>>Todos os bots</option>
                            <?php foreach ($bots_filtro as $bot): ?>
                                <option value="<?php echo (int) $bot['id']; ?>" <?php echo ($bot_id_selecionado === (string) $bot['id'] ? 'selected' : ''); ?>>
                                    <?php echo htmlspecialchars($bot['nome']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </form>
                <button type="button" class="alternador-tema" onclick="alternarTema()" aria-label="Alternar tema">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"></path></svg>
                    Tema
                </button>
            </div>
        </div>

        <?php include __DIR__ . '/parciais/barra_stories.php'; ?>

        <?php
        $rotulos_periodo_destaque = [
            'hoje' => 'HOJE', 'ontem' => 'ONTEM', '7dias' => '8 DIAS', '30dias' => '30 DIAS',
            'total' => 'TOTAL', 'personalizado' => 'PERÍODO',
        ];
        $rotulo_periodo_destaque = $rotulos_periodo_destaque[$periodo] ?? '8 DIAS';
        ?>
        <div class="cartao-destaque oculto-desktop">
            <span class="rotulo-destaque">APROVADO · <?php echo $rotulo_periodo_destaque; ?></span>
            <div class="valor-destaque">R$ <?php echo number_format($vendas_aprovadas, 2, ',', '.'); ?></div>
            <?php if ($variacao_percentual !== null): ?>
                <span class="selo-variacao <?php echo $variacao_percentual >= 0 ? 'selo-variacao-positivo' : 'selo-variacao-negativo'; ?>">
                    <?php echo $variacao_percentual >= 0 ? '+' : ''; ?><?php echo number_format($variacao_percentual, 1, ',', '.'); ?>% <span class="texto-suave">vs período anterior</span>
                </span>
            <?php endif; ?>
            <div class="mini-grafico-destaque">
                <canvas id="miniChart"></canvas>
            </div>
        </div>

        <div class="grade-dashboard-topo">
            <div class="grade-kpi">
                <div class="cartao-kpi oculto-mobile">
                    <div class="cartao-kpi-cabecalho">
                        <div class="icone-kpi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20M17 6H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg></div>
                        <span class="rotulo-kpi">Vendas aprovadas</span>
                    </div>
                    <div class="valor-kpi">R$ <?php echo number_format($vendas_aprovadas, 2, ',', '.'); ?></div>
                    <div class="rodape-kpi"><span><?php echo number_format($pix_pagos, 0, ",", "."); ?> aprovações</span></div>
                </div>

                <div class="cartao-kpi">
                    <div class="cartao-kpi-cabecalho">
                        <div class="icone-kpi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path></svg></div>
                        <span class="rotulo-kpi">Conversão</span>
                    </div>
                    <div class="valor-kpi"><?php echo round($taxa_conversao); ?>%</div>
                    <div class="rodape-kpi"><span><?php echo number_format($pix_pagos, 0, ",", "."); ?> de <?php echo number_format($pix_gerados, 0, ",", "."); ?> PIX</span></div>
                </div>

                <div class="cartao-kpi">
                    <div class="cartao-kpi-cabecalho">
                        <div class="icone-kpi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg></div>
                        <span class="rotulo-kpi">Total starts</span>
                    </div>
                    <div class="valor-kpi"><?php echo number_format($total_starts, 0, ",", "."); ?></div>
                    <div class="rodape-kpi"><span>Leads iniciaram conversa</span></div>
                </div>

                <div class="cartao-kpi">
                    <div class="cartao-kpi-cabecalho">
                        <div class="icone-kpi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path></svg></div>
                        <span class="rotulo-kpi">Ticket médio</span>
                    </div>
                    <div class="valor-kpi">R$ <?php echo number_format($ticket_medio, 2, ',', '.'); ?></div>
                    <div class="rodape-kpi"><span><?php echo number_format($pix_gerados, 0, ",", "."); ?> PIX gerados</span></div>
                </div>
            </div>

            <div class="painel">
                <div class="painel-cabecalho">
                    <h2>Seu desempenho</h2>
                    <span class="texto-suave"><?php echo $texto_grafico; ?></span>
                </div>
                <div class="area-grafico">
                    <canvas id="performanceChart"></canvas>
                </div>
            </div>
        </div>

        <div class="<?php echo $campanha_dash ? 'grade-dashboard-baixo grade-igual' : ''; ?>" style="margin-top: 14px;">
            <div class="painel">
                <div class="painel-cabecalho">
                    <h2>Atividade</h2>
                </div>
                <?php echo inicioBlocoPaginado('atividade'); ?>
                <?php include __DIR__ . '/parciais/lista_atividades.php'; ?>
                <?php echo fimBlocoPaginado(); ?>
            </div>

        <?php if ($campanha_dash): ?>
        <div class="painel">
            <div class="painel-cabecalho">
                <h2><?php echo htmlspecialchars($campanha_dash['titulo']); ?></h2>
                <span class="texto-suave">
                    <?php
                    echo $campanha_dash['estado'] === 'ativa' ? 'Em disputa'
                       : ($campanha_dash['estado'] === 'encerrada' ? 'Encerrada' : 'Começa em breve');
                    ?>
                </span>
            </div>

            <?php if ($campanha_dash['estado'] === 'agendada' || !$ranking_dash || empty($ranking_dash['top3'])): ?>
                <div class="mini-ranking-vazio">
                    <?php echo $campanha_dash['estado'] === 'agendada'
                        ? 'O placar aparece aqui quando a campanha abrir.'
                        : 'Ninguém pontuou ainda nesta campanha.'; ?>
                </div>
            <?php else: ?>
                <?php
                // Top 5 pra caber num widget compacto -- o pódio completo (top 3 + linhas 4-10)
                // e "sua posição" continuam só em /ranking, essa aqui é a vitrine.
                $top5_dash = array_slice(array_merge($ranking_dash['top3'], $ranking_dash['linhas']), 0, 5);
                $minha_posicao_fora_do_top5 = $ranking_dash['sua_posicao']
                    && (int) $ranking_dash['sua_posicao']['posicao'] > 5;
                ?>
                <div class="mini-ranking-lista">
                    <?php foreach ($top5_dash as $item):
                        $nome_item = nomeExibicaoRanking($item['apelido_publico'], (int) $item['id_usuario']);
                        $eh_lider = (int) $item['posicao'] === 1;
                        $eh_voce = (int) $item['id_usuario'] === $user_id;
                    ?>
                    <div class="mini-ranking-item<?php echo $eh_lider ? ' lider' : ''; ?><?php echo (!$eh_lider && $eh_voce) ? ' voce' : ''; ?>">
                        <div class="mini-ranking-avatar"><?php echo htmlspecialchars(iniciaisRanking($nome_item)); ?></div>
                        <div class="mini-ranking-corpo">
                            <div class="mini-ranking-nome">
                                <span class="mini-ranking-pos">#<?php echo (int) $item['posicao']; ?></span>
                                <span class="mini-ranking-nome-texto"><?php echo htmlspecialchars($nome_item); ?></span>
                                <?php if ($eh_voce): ?><span class="mini-ranking-voce-tag">você</span><?php endif; ?>
                            </div>
                        </div>
                        <div class="mini-ranking-valor"><?php echo htmlspecialchars(formatarReaisResumido((float) $item['faturamento'])); ?></div>
                    </div>
                    <?php endforeach; ?>

                    <?php if ($minha_posicao_fora_do_top5):
                        $meu_nome_dash = nomeExibicaoRanking($ranking_dash['sua_posicao']['apelido_publico'], $user_id);
                    ?>
                    <div class="mini-ranking-item voce">
                        <div class="mini-ranking-avatar"><?php echo htmlspecialchars(iniciaisRanking($meu_nome_dash)); ?></div>
                        <div class="mini-ranking-corpo">
                            <div class="mini-ranking-nome">
                                <span class="mini-ranking-pos">#<?php echo (int) $ranking_dash['sua_posicao']['posicao']; ?></span>
                                <span class="mini-ranking-nome-texto"><?php echo htmlspecialchars($meu_nome_dash); ?></span>
                                <span class="mini-ranking-voce-tag">você</span>
                            </div>
                        </div>
                        <div class="mini-ranking-valor"><?php echo htmlspecialchars(formatarReaisResumido((float) $ranking_dash['sua_posicao']['faturamento'])); ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="mini-ranking-rodape">
                <a href="ranking">Ver ranking completo →</a>
            </div>
        </div>
        <?php endif; ?>
        </div>
    </main>
</div>

<script src="assets/js/tema.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/tema.js'); ?>"></script>
<script src="assets/js/paginacao.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/paginacao.js'); ?>"></script>
<script src="assets/stories.js?v=<?php echo @filemtime(__DIR__ . '/assets/stories.js'); ?>"></script>
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

    const ctx = document.getElementById('performanceChart');
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
                    label: 'Receita',
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
                    legend: {
                        display: false
                    }
                },
                scales: {
                    x: {
                        grid: { display: false, drawBorder: false },
                        ticks: { color: cor_muda, font: { size: 11, family: "'JetBrains Mono', monospace" } }
                    },
                    y: {
                        grid: { color: cor_borda, drawBorder: false },
                        ticks: { display: false },
                        beginAtZero: true // Isso evita o bug do gráfico descer infinitamente
                    }
                }
            }
        });
    }

    // Mini-gráfico dentro do card de destaque (só mobile) -- reaproveita os mesmos
    // dados do gráfico completo acima, só que sem eixos/legenda, minimalista.
    const ctxMini = document.getElementById('miniChart');
    if (ctxMini) {
        const estilo_raiz = getComputedStyle(document.documentElement);
        const cor_acento = estilo_raiz.getPropertyValue('--or').trim() || '#ff6a1a';
        const chart_ctx_mini = ctxMini.getContext('2d');
        let gradient_mini = chart_ctx_mini.createLinearGradient(0, 0, 0, 80);
        gradient_mini.addColorStop(0, cor_acento + '33');
        gradient_mini.addColorStop(1, cor_acento + '00');

        new Chart(chart_ctx_mini, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($grafico_labels); ?>,
                datasets: [{
                    data: <?php echo json_encode($grafico_dados); ?>,
                    borderColor: cor_acento,
                    backgroundColor: gradient_mini,
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4,
                    pointRadius: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: { enabled: false } },
                scales: {
                    x: { display: false },
                    y: { display: false, beginAtZero: true }
                }
            }
        });
    }
});
</script>
</body>
</html>