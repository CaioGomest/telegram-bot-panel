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
$ranking_mensal_dash = null;
$ranking_dash = null;
try {
    $ranking_mensal_dash = buscarRankingMensal($user_id);
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
        // (hoje + 7 pra trás). Ver anotacoes/OBSERVACOES.md.
        $where_data_vendas = "AND v.criado_em >= CURDATE() - INTERVAL 7 DAY";
        $where_data_leads = "AND l.criado_em >= CURDATE() - INTERVAL 7 DAY";
        $where_data_metricas = "AND data >= CURDATE() - INTERVAL 7 DAY";
        break;
    case '30dias':
        // 30 dias corridos, igual ao gráfico (hoje + 29 pra trás).
        $where_data_vendas = "AND v.criado_em >= CURDATE() - INTERVAL 29 DAY";
        $where_data_leads = "AND l.criado_em >= CURDATE() - INTERVAL 29 DAY";
        $where_data_metricas = "AND data >= CURDATE() - INTERVAL 29 DAY";
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
// por bot), então cai de volta pra query ao vivo -- ver anotacoes/OBSERVACOES.md.
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

// Bloco "Vendas" do rodapé: as últimas vendas PAGAS dentro do mesmo filtro dos cards (período + bot). Duas etapas (ids, depois os dados)
// e ids de bot como literais inteiros, o mesmo cuidado de leads.php/vendas.php para tabela grande.
$vendas_dash = [];
// Mesmo recorte de $where_data_vendas, mas em faixa de horário (sem DATE() em volta da coluna): com DATE() o MySQL não usa o índice e "hoje"
// levava ~1 s num vendedor de 766 mil vendas.
switch ($periodo) {
    case 'hoje': $where_data_lista_dash = "AND v.criado_em >= CURDATE()"; break;
    case 'ontem': $where_data_lista_dash = "AND v.criado_em >= CURDATE() - INTERVAL 1 DAY AND v.criado_em < CURDATE()"; break;
    case 'personalizado': $where_data_lista_dash = $datas_validas ? "AND v.criado_em >= '$data_inicio 00:00:00' AND v.criado_em < '$data_fim 00:00:00' + INTERVAL 1 DAY" : ''; break;
    default: $where_data_lista_dash = $where_data_vendas; // 7dias, 30dias e total já são faixas
}
try {
    require_once __DIR__ . '/funcoes/vendas_lista.php';
    $ids_bots_vendas_dash = $bot_id_selecionado === 'todos' ? $ids_bots_permitidos : [(int) $bot_id_selecionado];
    if ($ids_bots_vendas_dash) {
        $stmt_ids_dash = $pdo->query("SELECT v.id FROM vendas v WHERE v.bot_id IN (" . implode(',', array_map('intval', $ids_bots_vendas_dash)) . ") AND v.status = 'pago' $where_data_lista_dash ORDER BY v.criado_em DESC, v.id DESC LIMIT 10");
        $ids_vendas_dash = array_map('intval', $stmt_ids_dash->fetchAll(PDO::FETCH_COLUMN));
        if ($ids_vendas_dash) {
            $vendas_dash = $pdo->query(sqlCamposVendasLista(colunaExiste('vendas', 'nome_produto'), colunaExiste('vendas', 'id_fluxo')) . ' WHERE v.id IN (' . implode(',', $ids_vendas_dash) . ') ORDER BY v.criado_em DESC, v.id DESC')->fetchAll(PDO::FETCH_ASSOC);
        }
    }
} catch (\Throwable $e) {
    error_log('[dashboard] lista de vendas indisponível: ' . $e->getMessage());
}
// Link "Ver todas": leva para a página Vendas com o mesmo recorte (datas calculadas como o dashboard calcula).
$hoje_dash = date('Y-m-d');
$recorte_vendas_dash = ['aba' => 'pagas'];
if ($bot_id_selecionado !== 'todos') $recorte_vendas_dash['bot_id'] = $bot_id_selecionado;
if ($periodo === 'hoje') { $recorte_vendas_dash += ['data_inicio' => $hoje_dash, 'data_fim' => $hoje_dash]; $rotulo_periodo_vendas_dash = 'Hoje'; }
elseif ($periodo === 'ontem') { $ontem_dash = date('Y-m-d', strtotime('-1 day')); $recorte_vendas_dash += ['data_inicio' => $ontem_dash, 'data_fim' => $ontem_dash]; $rotulo_periodo_vendas_dash = 'Ontem'; }
elseif ($periodo === '7dias') { $recorte_vendas_dash['data_inicio'] = date('Y-m-d', strtotime('-7 days')); $rotulo_periodo_vendas_dash = 'Últimos 7 dias'; }
elseif ($periodo === '30dias') { $recorte_vendas_dash['data_inicio'] = date('Y-m-d', strtotime('-29 days')); $rotulo_periodo_vendas_dash = 'Últimos 30 dias'; }
elseif ($periodo === 'personalizado' && $datas_validas) { $recorte_vendas_dash += ['data_inicio' => $data_inicio, 'data_fim' => $data_fim]; $rotulo_periodo_vendas_dash = date('d/m', strtotime($data_inicio)) . ' a ' . date('d/m', strtotime($data_fim)); }
else { $rotulo_periodo_vendas_dash = 'Todo o período'; }

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

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM leads_starts l WHERE 1=1 $where_data_leads $where_user_leads $where_bot_leads");
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
        $texto_grafico = "Hoje (por hora)";
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
        $texto_grafico = "Ontem (por hora)";
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
        $texto_grafico = "Últimos 30 dias";
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
        $texto_grafico = "Vendas nos últimos 12 meses";

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
        $texto_grafico = "Últimos 8 dias";
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
        $texto_grafico = "Período personalizado";
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

/**
 * Starts (leads) por intervalo do gráfico, no mesmo recorte do "Seu desempenho"
 * (hora / dia / mês), pro mini-gráfico do card "Total starts".
 */
function serieStartsDashboard(PDO $pdo, string $periodo, bool $usa_cache, int $user_id, string $where_user_leads, string $where_bot_leads, string $data_inicio, string $data_fim, bool $datas_validas): array
{
    $hoje = date('Y-m-d');
    $granularidade = 'dia';
    switch ($periodo) {
        case 'hoje':
            $granularidade = 'hora';
            $ini = $fim = $hoje;
            $chaves = range(0, (int) date('G'));
            break;
        case 'ontem':
            $granularidade = 'hora';
            $ini = $fim = date('Y-m-d', strtotime('-1 day'));
            $chaves = range(0, 23);
            break;
        case 'total':
            $granularidade = 'mes';
            $ini = $fim = $hoje;
            $chaves = [];
            for ($i = 11; $i >= 0; $i--) {
                $chaves[] = date('Y-m', strtotime("-$i months"));
            }
            break;
        default:
            if ($periodo === 'personalizado' && $datas_validas) {
                $ini = min($data_inicio, $data_fim);
                $fim = max($data_inicio, $data_fim);
            } else {
                $ini = date('Y-m-d', strtotime($periodo === '30dias' ? '-29 days' : '-7 days'));
                $fim = $hoje;
            }
            $chaves = [];
            for ($d = new DateTime($ini); $d->format('Y-m-d') <= $fim; $d->modify('+1 day')) {
                $chaves[] = $d->format('Y-m-d');
            }
    }

    if ($usa_cache) {
        if ($granularidade === 'hora') {
            $sql = 'SELECT hora AS k, SUM(qtd_leads) AS q FROM metricas_horarias_usuario WHERE data = ? AND id_usuario = ? GROUP BY hora';
            $params = [$ini, $user_id];
        } elseif ($granularidade === 'mes') {
            $sql = "SELECT DATE_FORMAT(data, '%Y-%m') AS k, SUM(qtd_leads) AS q FROM metricas_horarias_usuario WHERE data >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) AND id_usuario = ? GROUP BY k";
            $params = [$user_id];
        } else {
            $sql = 'SELECT data AS k, SUM(qtd_leads) AS q FROM metricas_horarias_usuario WHERE data BETWEEN ? AND ? AND id_usuario = ? GROUP BY data';
            $params = [$ini, $fim, $user_id];
        }
    } else {
        if ($granularidade === 'hora') {
            $sql = "SELECT HOUR(l.criado_em) AS k, COUNT(*) AS q FROM leads_starts l WHERE l.criado_em >= ? AND l.criado_em < ? + INTERVAL 1 DAY $where_user_leads $where_bot_leads GROUP BY k";
            $params = [$ini, $ini];
        } elseif ($granularidade === 'mes') {
            $sql = "SELECT DATE_FORMAT(l.criado_em, '%Y-%m') AS k, COUNT(*) AS q FROM leads_starts l WHERE l.criado_em >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) $where_user_leads $where_bot_leads GROUP BY k";
            $params = [];
        } else {
            $sql = "SELECT DATE(l.criado_em) AS k, COUNT(*) AS q FROM leads_starts l WHERE l.criado_em >= ? AND l.criado_em < ? + INTERVAL 1 DAY $where_user_leads $where_bot_leads GROUP BY k";
            $params = [$ini, $fim];
        }
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $por_chave = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'q', 'k');

    return array_map(static function ($chave) use ($por_chave): int {
        return (int) ($por_chave[$chave] ?? 0);
    }, $chaves);
}

try {
    $serie_starts = serieStartsDashboard($pdo, $periodo, $usa_cache_metricas, (int) $user_id, $where_user_leads, $where_bot_leads, $data_inicio, $data_fim, $datas_validas);
} catch (Throwable $e) {
    error_log('[dashboard] série de starts indisponível: ' . $e->getMessage());
    $serie_starts = [];
}

$taxa_aprovacao_percentual = $pix_gerados > 0 ? ($pix_pagos / $pix_gerados) * 100 : 0;


?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Dashboard - <?php echo htmlspecialchars(nomeSistema()); ?></title>
    <?php include 'tema_inline.php'; ?>
    <link rel="stylesheet" href="assets/css/painel.css?v=<?php echo @filemtime(__DIR__.'/assets/css/painel.css'); ?>">
    <script src="https://cdn.jsdelivr.net/npm/chart.js" defer></script>
</head>
<body class="pg-dash">
<div class="layout-painel">
    <?php include 'barra_lateral.php'; ?>

    <main class="conteudo-principal">
        <header class="a-ph">
            <div>
                <h1 class="a-h1">Dashboard</h1>
                <p class="a-sub">Acompanhe vendas, conversão e atividade dos seus bots.</p>
            </div>
        </header>

        <?php if (storiesAtivos()) { include __DIR__ . '/parciais/barra_stories.php'; } ?>

        <form method="GET" class="a-per" id="form-periodo">
            <input type="hidden" name="periodo" value="<?php echo htmlspecialchars($periodo); ?>">
            <div class="a-seg" role="group" aria-label="Período">
                <a href="<?php echo htmlspecialchars(montarUrlFiltroDashboard(['periodo' => 'hoje'])); ?>"<?php echo $periodo == 'hoje' ? ' class="on" aria-current="true"' : ''; ?>>Hoje</a>
                <a href="<?php echo htmlspecialchars(montarUrlFiltroDashboard(['periodo' => 'ontem'])); ?>"<?php echo $periodo == 'ontem' ? ' class="on" aria-current="true"' : ''; ?>>Ontem</a>
                <a href="<?php echo htmlspecialchars(montarUrlFiltroDashboard(['periodo' => '7dias'])); ?>"<?php echo $periodo == '7dias' ? ' class="on" aria-current="true"' : ''; ?>>8 dias</a>
                <a href="<?php echo htmlspecialchars(montarUrlFiltroDashboard(['periodo' => '30dias'])); ?>"<?php echo $periodo == '30dias' ? ' class="on" aria-current="true"' : ''; ?>>30 dias</a>
                <a href="<?php echo htmlspecialchars(montarUrlFiltroDashboard(['periodo' => 'total'])); ?>"<?php echo $periodo == 'total' ? ' class="on" aria-current="true"' : ''; ?>>Total</a>
                <?php if ($periodo === 'personalizado'): ?>
                <a href="<?php echo htmlspecialchars(montarUrlFiltroDashboard(['periodo' => 'personalizado', 'data_inicio' => $data_inicio, 'data_fim' => $data_fim])); ?>" class="on" aria-current="true">Personalizado</a>
                <?php endif; ?>
            </div>
            <?php if ($mostrar_filtro_bot): ?>
                <label class="a-fld" for="filtro-bot-dashboard">
                    <span class="a-lab" style="position: absolute; left: -9999px">Bot</span>
                    <select class="a-in" name="bot_id" id="filtro-bot-dashboard" onchange="this.form.submit()" aria-label="Filtrar por bot">
                        <option value="todos" <?php echo ($bot_id_selecionado === 'todos' ? 'selected' : ''); ?>>Todos os bots</option>
                        <?php foreach ($bots_filtro as $bot): ?>
                            <option value="<?php echo (int) $bot['id']; ?>" <?php echo ($bot_id_selecionado === (string) $bot['id'] ? 'selected' : ''); ?>>
                                <?php echo htmlspecialchars($bot['nome']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endif; ?>
        </form>

        <?php
        $rotulos_periodo_destaque = [
            'hoje' => 'HOJE', 'ontem' => 'ONTEM', '7dias' => '8 DIAS', '30dias' => '30 DIAS',
            'total' => 'TOTAL', 'personalizado' => 'PERÍODO',
        ];
        $rotulo_periodo_destaque = $rotulos_periodo_destaque[$periodo] ?? '8 DIAS';
        ?>
        <section class="a-grid" aria-label="Indicadores">

            <div class="a-card a-hero s6 r2">
                <div class="a-ch" style="margin: 0">
                    <span class="a-lab">Aprovado · <?php echo $rotulo_periodo_destaque; ?></span>
                    <?php if ($variacao_percentual !== null):
                        // 0,0% não é "alta" nem "queda" -- não houve variação nenhuma pra comparar (ex.: conta nova, ou mesmo
                        // valor nos dois períodos). Estilo neutro pra esse caso, verde só quando é realmente positivo.
                        if ($variacao_percentual > 0) { $classe_variacao = ''; $prefixo_variacao = '+'; }
                        elseif ($variacao_percentual < 0) { $classe_variacao = ' er'; $prefixo_variacao = ''; }
                        else { $classe_variacao = ' neutro'; $prefixo_variacao = ''; }
                    ?>
                        <span class="a-delta<?php echo $classe_variacao; ?>"><?php echo $prefixo_variacao; ?><?php echo number_format($variacao_percentual, 1, ',', '.'); ?>% <span>vs período anterior</span></span>
                    <?php endif; ?>
                </div>
                <div class="a-kv">R$ <?php echo number_format($vendas_aprovadas, 2, ',', '.'); ?></div>
                <div style="display: grid; gap: 8px">
                    <div class="a-bar"><i style="width: <?php echo min(100, round($taxa_aprovacao_percentual)); ?>%"></i></div>
                    <div class="a-kf"><strong><?php echo number_format($pix_pagos, 0, ",", "."); ?></strong> <?php echo $pix_pagos === 1 ? "aprovação" : "aprovações"; ?> · <?php echo number_format($taxa_aprovacao_percentual, 0); ?>% aprov.</div>
                </div>
                <div class="a-ch" style="margin: 8px 0 0"><h2>Seu desempenho</h2><span class="a-mut" style="font-size: 12.5px"><?php echo $texto_grafico; ?></span></div>
                <div class="a-chart-area"><canvas id="performanceChart" role="img" aria-label="Gráfico de receita do período"></canvas></div>
            </div>

            <div class="a-card a-kpi s3">
                <div class="a-kh"><span class="a-ki"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M22 12h-4l-3 9L9 3l-3 9H2"></path></svg></span><span class="a-lab">Conversão</span></div>
                <div class="a-gauge-c" role="img" aria-label="Conversão de <?php echo number_format($taxa_conversao, 2, ',', '.'); ?>%">
                    <canvas id="kpiMedidorConversao"></canvas>
                    <b><?php echo number_format($taxa_conversao, 2, ',', '.'); ?>%</b>
                </div>
                <span class="a-kf" style="text-align: center"><?php echo number_format($pix_pagos, 0, ",", "."); ?> pagos de <?php echo number_format($pix_gerados, 0, ",", "."); ?> PIX</span>
            </div>

            <div class="a-card a-kpi s3">
                <div class="a-kh"><span class="a-ki"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"></path></svg></span><span class="a-lab">Total starts</span></div>
                <span class="a-kv"><?php echo number_format($total_starts, 0, ",", "."); ?></span>
                <span class="a-kf">Inícios de conversa (novos e de quem voltou)</span>
                <div class="a-spark"><canvas id="kpiBarrasStarts"></canvas></div>
            </div>

            <div class="a-card a-kpi s6">
                <div class="a-kh"><span class="a-ki"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2v20M17 6H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg></span><span class="a-lab">Ticket médio</span></div>
                <span class="a-kv">R$ <?php echo number_format($ticket_medio, 2, ',', '.'); ?></span>
                <span class="a-kf">Vendas: <strong>R$ <?php echo number_format($vendas_aprovadas, 2, ',', '.'); ?></strong></span>
                <div style="display: grid; gap: 6px; margin-top: 6px">
                    <div class="a-kf" style="display: flex; justify-content: space-between"><span>PIX pagos</span><strong><?php echo number_format($pix_pagos, 0, ",", "."); ?></strong></div>
                    <div class="a-bar"><i style="width: <?php echo min(100, round($taxa_aprovacao_percentual)); ?>%"></i></div>
                    <div class="a-kf" style="text-align: right">de <?php echo number_format($pix_gerados, 0, ",", "."); ?> PIX gerados</div>
                </div>
            </div>
        </section>

        <?php $tem_ranking_dash = (bool) ($campanha_dash || $ranking_mensal_dash); ?>
        <section class="a-grid" style="margin-top: 16px">
            <div class="a-card a-act <?php echo $tem_ranking_dash ? 's5' : 's12'; ?>">
                <div class="a-ch"><h2>Atividade</h2></div>
                <?php echo inicioBlocoPaginado('atividade'); ?>
                <?php include __DIR__ . '/parciais/lista_atividades.php'; ?>
                <?php echo fimBlocoPaginado(); ?>
            </div>

        <?php if ($tem_ranking_dash):
            // Lista compacta (top 5 + "você" se estiver fora) usada pelas duas abas do widget.
            $desenhar_mini_ranking = function (?array $dados, string $msg_vazio) use ($user_id) {
                if (!$dados || empty($dados['top3'])) {
                    echo '<div class="a-empty">' . htmlspecialchars($msg_vazio) . '</div>';
                    return;
                }
                $top5 = array_slice(array_merge($dados['top3'], $dados['linhas']), 0, 5);
                $fora_do_top5 = $dados['sua_posicao'] && (int) $dados['sua_posicao']['posicao'] > 5;
                echo '<ul>';
                $linha = function (array $item, bool $eh_lider, bool $eh_voce) {
                    $nome_item = nomeExibicaoRanking($item['apelido_publico'], (int) $item['id_usuario']);
                    echo '<li class="' . ($eh_lider ? 'lider' : (($eh_voce) ? 'voce' : '')) . '">'
                        . '<span class="pos">#' . (int) $item['posicao'] . '</span>'
                        . htmlAvatarRanking($item, $nome_item)
                        . '<span class="nm">' . htmlspecialchars($nome_item) . ($eh_voce ? '<span class="a-tag">você</span>' : '') . '</span>'
                        . '<span class="vl">' . htmlspecialchars(formatarReaisResumido((float) $item['faturamento'])) . '</span></li>';
                };
                foreach ($top5 as $item) {
                    $linha($item, (int) $item['posicao'] === 1, (int) $item['id_usuario'] === $user_id);
                }
                if ($fora_do_top5) {
                    $linha($dados['sua_posicao'], false, true);
                }
                echo '</ul>';
            };
            $aba_mini_inicial = $campanha_dash ? 'concurso' : 'mensal';
            $msg_vazio_concurso = ($campanha_dash && $campanha_dash['estado'] === 'agendada')
                ? 'O placar aparece aqui quando a campanha abrir.' : 'Ninguém pontuou ainda nesta campanha.';
        ?>
            <div class="a-card a-mr s7" id="mini-ranking">
                <div class="a-ch">
                    <h2 id="mini-ranking-titulo"><?php echo htmlspecialchars($campanha_dash ? $campanha_dash['titulo'] : 'Ranking mensal'); ?></h2>
                    <?php if ($campanha_dash): ?>
                    <div class="a-seg" role="tablist" aria-label="Tipo de ranking">
                        <button type="button" class="on" data-aba-mini="concurso" role="tab" aria-selected="true">Concurso</button>
                        <button type="button" data-aba-mini="mensal" role="tab" aria-selected="false">Mensal</button>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if ($campanha_dash): ?>
                <div data-aba-painel="concurso" data-titulo="<?php echo htmlspecialchars($campanha_dash['titulo']); ?>">
                    <?php $desenhar_mini_ranking(($campanha_dash['estado'] === 'agendada') ? null : $ranking_dash, $msg_vazio_concurso); ?>
                </div>
                <?php endif; ?>
                <div data-aba-painel="mensal" data-titulo="Ranking mensal"<?php echo $campanha_dash ? ' hidden' : ''; ?>>
                    <?php $desenhar_mini_ranking($ranking_mensal_dash, 'Ninguém faturou neste mês ainda.'); ?>
                </div>

                <div class="a-more">
                    <a href="ranking?aba=<?php echo $aba_mini_inicial; ?>" id="mini-ranking-link">Ver ranking completo<svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"></path></svg></a>
                </div>
            </div>
            <script>
            (function () {
                var caixa = document.getElementById('mini-ranking');
                if (!caixa) return;
                caixa.querySelectorAll('[data-aba-mini]').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        var aba = btn.getAttribute('data-aba-mini');
                        caixa.querySelectorAll('[data-aba-mini]').forEach(function (b) {
                            b.classList.toggle('on', b === btn);
                            b.setAttribute('aria-selected', b === btn ? 'true' : 'false');
                        });
                        caixa.querySelectorAll('[data-aba-painel]').forEach(function (p) {
                            var ativo = p.getAttribute('data-aba-painel') === aba;
                            p.hidden = !ativo;
                            if (ativo) document.getElementById('mini-ranking-titulo').textContent = p.getAttribute('data-titulo');
                        });
                        document.getElementById('mini-ranking-link').setAttribute('href', 'ranking?aba=' + aba);
                    });
                });
            })();
            </script>
        <?php endif; ?>
        </section>

        <section class="a-card a-tw" id="vendas-dashboard" style="margin-top: 16px" aria-label="Vendas do período">
            <div class="a-ch">
                <h2>Vendas</h2>
                <span class="a-mut" style="font-size: 12.5px"><?php echo htmlspecialchars($rotulo_periodo_vendas_dash); ?> · <?php echo number_format((int) $pix_pagos, 0, ',', '.'); ?> <?php echo (int) $pix_pagos === 1 ? 'venda paga' : 'vendas pagas'; ?></span>
            </div>
            <table class="a-t" id="tabela-vendas-dashboard">
                <thead>
                    <tr><th>Data</th><th>Cliente</th><th>Produto</th><th>Bot</th><th class="num">Valor</th></tr>
                </thead>
                <tbody>
                    <?php if (empty($vendas_dash)): ?>
                        <tr><td colspan="5" class="full a-vazio-linha">Nenhuma venda paga neste período.</td></tr>
                    <?php else: foreach ($vendas_dash as $vd):
                        $cliente_vd = trim((string) ($vd['nome_cliente'] ?? '')) ?: 'Cliente ' . $vd['id_telegram'];
                        $produto_vd = trim((string) ($vd['nome_produto'] ?? ''));
                        $tipo_vd = tipoDaVenda($vd);
                    ?>
                        <tr>
                            <td data-l="Data" class="mono o3"><?php echo date('d/m/Y H:i', strtotime((string) $vd['pago_em'] ?: (string) $vd['criado_em'])); ?></td>
                            <td class="c-main">
                                <b><?php echo htmlspecialchars($cliente_vd); ?></b>
                                <span class="sub">ID: <?php echo htmlspecialchars((string) $vd['id_telegram']); ?></span>
                            </td>
                            <td data-l="Produto" class="full o5">
                                <?php echo $produto_vd !== '' ? htmlspecialchars($produto_vd) : '<span class="a-mut">—</span>'; ?>
                                <?php if ($tipo_vd !== 'primeira'): ?>
                                    <span class="a-bd<?php echo $tipo_vd === 'recompra' ? '' : ' wa'; ?>"><?php echo $tipo_vd === 'recompra' ? 'Recompra' : 'Renovação'; ?></span>
                                <?php endif; ?>
                            </td>
                            <td data-l="Bot" class="o4"><?php echo htmlspecialchars((string) $vd['nome_bot']); ?></td>
                            <td class="num c-val">R$ <?php echo number_format((float) $vd['valor'], 2, ',', '.'); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
            <div class="a-more">
                <a href="vendas?<?php echo htmlspecialchars(http_build_query($recorte_vendas_dash)); ?>">Ver todas as vendas<svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"></path></svg></a>
            </div>
        </section>
    </main>
</div>

<script src="assets/js/tema.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/tema.js'); ?>"></script>
<script src="assets/js/paginacao.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/paginacao.js'); ?>"></script>
<?php if (storiesAtivos()): ?><script src="assets/stories.js?v=<?php echo @filemtime(__DIR__ . '/assets/stories.js'); ?>"></script><?php endif; ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const filter_form = document.getElementById('form-periodo');
    const periodo_btns = filter_form ? filter_form.querySelectorAll('.a-seg a') : [];
    const date_inputs = filter_form ? filter_form.querySelectorAll('input[type="date"]') : [];
    const periodo_hidden = filter_form ? filter_form.querySelector('input[name="periodo"]') : null;

    periodo_btns.forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();

            const url = new URL(this.href, window.location.origin);
            const periodo_selecionado = url.searchParams.get('periodo');

            periodo_hidden.value = periodo_selecionado;

            if (periodo_selecionado === 'personalizado') {
                date_inputs[0].focus();
            } else {
                // Datas só valem no período personalizado: campo desabilitado não vai no envio.
                date_inputs.forEach(input => input.disabled = true);
                filter_form.submit();
            }
        });
    });

    // De e Até ficam sempre visíveis: com as duas preenchidas, o período vira "personalizado" e aplica.
    date_inputs.forEach(input => {
        input.addEventListener('change', function() {
            const data_inicio = filter_form.querySelector('input[name="data_inicio"]').value;
            const data_fim = filter_form.querySelector('input[name="data_fim"]').value;

            if (data_inicio && data_fim) {
                periodo_hidden.value = 'personalizado';
                date_inputs.forEach(i => i.disabled = false);
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

    // Gráficos dos cards de KPI: medidor da conversão e barras de starts.
    const raiz_kpi = getComputedStyle(document.documentElement);
    const cor_kpi = raiz_kpi.getPropertyValue('--or').trim() || '#ff6a1a';
    const cor_trilho = raiz_kpi.getPropertyValue('--p3').trim() || '#22262f';
    const ctxMedidor = document.getElementById('kpiMedidorConversao');
    if (ctxMedidor) {
        const taxa = Math.max(0, Math.min(100, <?php echo json_encode(round($taxa_conversao, 2)); ?>));
        new Chart(ctxMedidor.getContext('2d'), {
            type: 'doughnut',
            data: { datasets: [{ data: [taxa, 100 - taxa], backgroundColor: [cor_kpi, cor_trilho], borderWidth: 0, borderRadius: 6 }] },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                rotation: -90,
                circumference: 180,
                cutout: '78%',
                plugins: { legend: { display: false }, tooltip: { enabled: false } }
            }
        });
    }
    const ctxStarts = document.getElementById('kpiBarrasStarts');
    if (ctxStarts) {
        const serie_starts = <?php echo json_encode($serie_starts); ?>;
        const ctx_starts = ctxStarts.getContext('2d');
        const gradiente_starts = ctx_starts.createLinearGradient(0, 0, 0, 70);
        gradiente_starts.addColorStop(0, cor_kpi + '55');
        gradiente_starts.addColorStop(1, cor_kpi + '00');
        new Chart(ctx_starts, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($grafico_labels); ?>,
                datasets: [{
                    data: serie_starts,
                    borderColor: cor_kpi,
                    backgroundColor: gradiente_starts,
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4,
                    pointRadius: 0,
                    pointHoverRadius: 4,
                    pointHoverBackgroundColor: cor_kpi
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: { legend: { display: false }, tooltip: { displayColors: false, callbacks: { label: function (c) { return c.parsed.y + (c.parsed.y === 1 ? ' start' : ' starts'); } } } },
                scales: {
                    x: { grid: { display: false }, border: { display: false }, ticks: { maxTicksLimit: 4, maxRotation: 0, color: raiz_kpi.getPropertyValue('--m').trim(), font: { size: 10, family: "'JetBrains Mono', monospace" } } },
                    y: { display: false, beginAtZero: true, suggestedMax: 3 }
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