<?php
declare(strict_types=1);

require_once __DIR__ . '/funcoes/usuario.php';
require_once __DIR__ . '/funcoes/paginador.php';
bloquearAdmin();

$id_usuario = $_SESSION['usuario_id'];
$is_admin = ehAdmin();

$bot_id = isset($_GET['bot_id']) ? (int)$_GET['bot_id'] : 0;
$status = isset($_GET['status']) ? $_GET['status'] : '';
$busca = trim($_GET['busca'] ?? '');

// Resolve os bots do usuário primeiro (sempre poucas linhas) e filtra leads direto
// por "l.bot_id IN (...)", em vez de "JOIN bots b WHERE b.id_usuario = ?". Testado:
// com o filtro no JOIN, o MySQL não usava o índice (bot_id, criado_em) de leads e
// fazia table scan completo + filesort mesmo só pra pegar 25 linhas (ver
// anotacoes/OBSERVACOES.md) -- filtrando direto em l.bot_id, o índice
// passa a ser usado de verdade.
$stmt_ids_bots = $pdo->prepare('SELECT id FROM bots WHERE id_usuario = ?');
$stmt_ids_bots->execute([$id_usuario]);
$ids_bots_usuario = array_map('intval', $stmt_ids_bots->fetchAll(PDO::FETCH_COLUMN));

if ($bot_id > 0) {
    $ids_bots_usuario = in_array($bot_id, $ids_bots_usuario) ? [$bot_id] : [];
}

// WHERE base (sem os campos calculados por lead) -- reaproveitado no COUNT e na
// listagem paginada. Nunca faz SELECT sem LIMIT aqui: com muitos leads acumulados
// (anos de bot rodando), buscar tudo de uma vez trava a página inteira -- os 4
// campos calculados por lead (compras/gasto/status/plano) só custam caro se
// rodarem pra linha demais, então a correção é sempre limitar quantas linhas
// passam por eles, não eliminá-los.
//
// Os IDs de bot são colados direto na query (não como parâmetro do PDO) de propósito:
// testado em produção que "l.bot_id IN (?)" com bind faz o MySQL escolher um plano
// muito pior (table scan + filesort mesmo pegando só 25 linhas, 25s+) do que
// "l.bot_id IN (2)" com o valor literal (0,003s) -- é seguro aqui porque os valores
// vêm de uma query nossa (bots do próprio usuário logado), nunca de input externo,
// e passam por array_map('intval', ...) antes de qualquer coisa.
if (empty($ids_bots_usuario)) {
    $where_sql = '1=0';
    $params = [];
} else {
    $ids_bots_sql = implode(',', $ids_bots_usuario);
    $where = ["l.bot_id IN ($ids_bots_sql)"];
    $params = [];

    // "Pagou / Não pagou" lê leads.pagou (índice bot+pagou+data, mantido na confirmação do pagamento): continua rápido
    // com milhões de leads, em vez de procurar venda lead por lead.
    if ($status === 'pago') {
        $where[] = condicaoLeadPagou(true);
    } elseif ($status === 'nao_pago') {
        $where[] = condicaoLeadPagou(false);
    }

    // A caixa de busca era só um filtro de JS escondendo <tr> já carregado na página --
    // filtrava dentro dos 25 leads da página atual, mas a paginação embaixo continuava
    // contando TODOS os leads (sem a busca), então "Caio" podia mostrar "43695 páginas"
    // mesmo a pessoa toda inteira estando numa página que não era a atual. Agora é filtro
    // de banco de verdade: entra no WHERE, então conta e pagina certo.
    if ($busca !== '') {
        $where[] = "(l.nome LIKE ? OR l.id_telegram LIKE ?)";
        $params[] = "%$busca%";
        $params[] = "%$busca%";
    }

    $where_sql = implode(' AND ', $where);
}
$sql_base = "FROM leads l JOIN bots b ON l.bot_id = b.id WHERE $where_sql";

$exportando_csv = isset($_GET['export']) && $_GET['export'] === 'csv';

$sql_select = "
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
";
$sql_campos = $sql_select . "
    $sql_base
    ORDER BY l.criado_em DESC
";

if ($exportando_csv) {
    // Exportação é uma ação pontual e explícita -- aqui sim faz sentido buscar tudo
    // que casa com o filtro, sem paginação (é o objetivo da exportação). Mas com
    // contas grandes (1M+ leads) dá pra exportar sem empilhar tudo na memória de
    // uma vez: usa query sem buffer (MYSQL_ATTR_USE_BUFFERED_QUERY=false) e escreve
    // cada linha no CSV assim que chega do banco, em vez de fetchAll() primeiro.
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=leads.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Nome', 'ID Telegram', 'Bot', 'Data Inicio', 'Status', 'Plano Ativo', 'Total Compras', 'Total Gasto']);

    $stmt = $pdo->prepare($sql_campos, [PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false]);
    $stmt->execute($params);

    $linha_num = 0;
    while ($lead = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $status_texto = 'Iniciou Conversa';
        if ($lead['total_compras'] > 0) {
            $status_texto = 'Cliente (Pagou)';
        } elseif ($lead['ultimo_status_pagamento'] === 'gerado') {
            $status_texto = 'Gerou Pix (Não Pago)';
        }

        fputcsv($output, [
            csvSeguro($lead['nome']),
            csvSeguro($lead['id_telegram']),
            csvSeguro($lead['nome_bot']),
            date('d/m/Y H:i', strtotime($lead['data_inicio'])),
            $status_texto,
            $lead['plano_ativo'] ? 'Ativo' : 'Inativo',
            $lead['total_compras'],
            number_format((float)$lead['total_gasto'], 2, ',', '.')
        ]);

        // Manda pro navegador aos poucos em vez de só no final -- em exportações
        // grandes, evita segurar tudo em buffer até completar.
        if (++$linha_num % 2000 === 0) {
            flush();
        }
    }
    fclose($output);
    exit;
}

$stmt_total = $pdo->prepare("SELECT COUNT(*) $sql_base");
$stmt_total->execute($params);
$total_leads = (int) $stmt_total->fetchColumn();

$por_pagina = 25;
$pagina_atual = isset($_GET['pagina']) ? max(1, (int)$_GET['pagina']) : 1;
// Trava contra página presa: trocar de aba/bot/busca sem sair de "página 5" (os links de
// filtro preservam ?pagina= do jeito que estavam) podia deixar o offset além do total e
// devolver "Nenhum lead encontrado" mesmo havendo resultado -- só que na página 1.
$total_paginas = $total_leads > 0 ? (int) ceil($total_leads / $por_pagina) : 1;
$pagina_atual = min($pagina_atual, $total_paginas);
$offset = ($pagina_atual - 1) * $por_pagina;

// Página em DUAS etapas (mesmo padrão de admin/transacoes.php): primeiro só os ids dos 25 leads da página, ordenando
// direto na tabela leads; depois os dados completos só desses 25. Em uma etapa só, o MySQL juntava bots primeiro e
// ordenava os ~200 mil leads do vendedor numa tabela temporária (medido: 4 a 6 s numa conta com 200 mil leads; a lista
// padrão e as páginas fundas caem para a casa de centenas de ms). l.id desempata pra paginação não repetir/pular linha.
$stmt_ids = $pdo->prepare("SELECT l.id FROM leads l WHERE $where_sql ORDER BY l.criado_em DESC, l.id DESC LIMIT $por_pagina OFFSET $offset");
$stmt_ids->execute($params);
$ids_pagina = array_map('intval', $stmt_ids->fetchAll(PDO::FETCH_COLUMN));
$leads = [];
if ($ids_pagina) {
    $stmt = $pdo->query($sql_select . " FROM leads l JOIN bots b ON l.bot_id = b.id WHERE l.id IN (" . implode(',', $ids_pagina) . ") ORDER BY l.criado_em DESC, l.id DESC");
    $leads = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$stmt_bots = $pdo->prepare("SELECT id, COALESCE(primeiro_nome, nome_usuario) as nome FROM bots WHERE id_usuario = ?");
$stmt_bots->execute([$id_usuario]);
$meus_bots = $stmt_bots->fetchAll(PDO::FETCH_ASSOC);

// Base pros links de filtro (abas de status): tira 'pagina', senão trocar de aba estando na
// página 5 mantinha ?pagina=5 -- o clamp acima já protege contra erro, mas o certo é o link
// já levar pra página 1 de cara, não "pular" pra última página disponível do novo filtro.
$params_link = $_GET;
unset($params_link['pagina']);

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leads - <?php echo htmlspecialchars(nomeSistema()); ?></title>
    <?php include 'tema_inline.php'; ?>
    <link rel="stylesheet" href="assets/css/painel.css?v=<?php echo @filemtime(__DIR__.'/assets/css/painel.css'); ?>">
</head>
<body>
<div class="layout-painel">
    <?php include 'barra_lateral.php'; ?>
    <main class="conteudo-principal">
        <header class="a-ph">
            <div>
                <h1 class="a-h1">Leads</h1>
                <p class="a-sub">Visualize os usuários que interagiram com seus bots.</p>
            </div>
        </header>

        <input type="checkbox" id="a-f" class="a-cb a-cbf" aria-label="Mostrar filtros">
        <div class="a-card a-fbar flat">
            <form method="GET" class="a-search" id="form-busca-lead" role="search">
                <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?php echo htmlspecialchars($status); ?>"><?php endif; ?>
                <?php if ($bot_id > 0): ?><input type="hidden" name="bot_id" value="<?php echo $bot_id; ?>"><?php endif; ?>
                <button type="submit" class="a-search-btn" aria-label="Buscar">
                    <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="M20 20l-4-4"></path></svg>
                </button>
                <input class="a-in" type="text" name="busca" id="busca-lead" value="<?php echo htmlspecialchars($busca); ?>" placeholder="Buscar por nome ou ID do Telegram" aria-label="Buscar por nome ou ID do Telegram">
            </form>
            <label class="a-btn a-fbtn" for="a-f" aria-label="Filtros">
                <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M22 3H2l8 9.46V19l4 2v-8.54L22 3z"></path></svg><span class="t">Filtros</span>
            </label>
            <div class="a-fpanel">
                <div class="a-seg" role="group" aria-label="Status do lead">
                    <a href="?<?php echo http_build_query(array_merge($params_link, ['status' => ''])); ?>"<?php echo $status === '' ? ' class="on" aria-current="true"' : ''; ?>>Todos</a>
                    <a href="?<?php echo http_build_query(array_merge($params_link, ['status' => 'pago'])); ?>"<?php echo $status === 'pago' ? ' class="on" aria-current="true"' : ''; ?>>Já pagou</a>
                    <a href="?<?php echo http_build_query(array_merge($params_link, ['status' => 'nao_pago'])); ?>"<?php echo $status === 'nao_pago' ? ' class="on" aria-current="true"' : ''; ?>>Não pagou</a>
                </div>
                <form method="GET" class="a-fld">
                    <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?php echo htmlspecialchars($status); ?>"><?php endif; ?>
                    <?php if ($busca !== ''): ?><input type="hidden" name="busca" value="<?php echo htmlspecialchars($busca); ?>"><?php endif; ?>
                    <select class="a-in" name="bot_id" onchange="this.form.submit()" aria-label="Filtrar por bot">
                        <option value="">Todos os Bots</option>
                        <?php foreach ($meus_bots as $b): ?>
                            <option value="<?php echo $b['id']; ?>" <?php echo $bot_id == $b['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($b['nome']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
        </div>

        <section class="a-card a-tw flat" aria-label="Lista de leads">
            <table class="a-t" id="tabela-leads">
                <thead>
                    <tr>
                        <th>Lead</th>
                        <th>Número</th>
                        <th>Bot</th>
                        <th>Início</th>
                        <th>Status</th>
                        <th>Plano</th>
                        <th>Compras</th>
                        <th class="num">Total gasto</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($leads)): ?>
                        <tr>
                            <td colspan="8" class="full a-vazio-linha">Nenhum lead encontrado.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($leads as $lead): ?>
                            <?php
                                $status_badge = '<span class="a-bd">Iniciou</span>';
                                if ($lead['total_compras'] > 0) {
                                    $status_badge = '<span class="a-bd ok">Cliente</span>';
                                } elseif ($lead['ultimo_status_pagamento'] === 'gerado') {
                                    $status_badge = '<span class="a-bd wa">Gerou Pix</span>';
                                }
                                $nome_lead = trim((string) $lead['nome']) !== '' ? (string) $lead['nome'] : 'Sem nome';
                                $inicial_lead = mb_strtoupper(mb_substr($nome_lead, 0, 1), 'UTF-8');
                            ?>
                            <tr>
                                <td class="c-main">
                                    <div class="who">
                                        <span class="a-av2"><?php echo htmlspecialchars($inicial_lead); ?></span>
                                        <div>
                                            <b><?php echo htmlspecialchars($nome_lead); ?></b>
                                            <span class="sub">ID: <?php echo htmlspecialchars((string) $lead['id_telegram']); ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td data-l="Número"><?php echo $lead['telefone'] ? htmlspecialchars($lead['telefone']) : '<span class="a-mut">—</span>'; ?></td>
                                <td data-l="Bot"><?php echo htmlspecialchars((string) $lead['nome_bot']); ?></td>
                                <td data-l="Início" class="mono"><?php echo date('d/m/Y H:i', strtotime($lead['data_inicio'])); ?></td>
                                <td data-l="Status"><?php echo $status_badge; ?></td>
                                <td data-l="Plano">
                                    <?php echo $lead['plano_ativo'] ? '<span class="a-bd ok">Ativo</span>' : '<span class="a-bd">Inativo</span>'; ?>
                                </td>
                                <td data-l="Compras"><?php echo $lead['total_compras']; ?></td>
                                <td class="num c-val">R$ <?php echo number_format((float)$lead['total_gasto'], 2, ',', '.'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>
        <?php echo paginador($total_leads, $por_pagina); ?>
    </main>
</div>

<script src="assets/js/tema.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/tema.js'); ?>"></script>
<script>
(function () {
    var form = document.getElementById('form-busca-lead');
    var input = document.getElementById('busca-lead');
    if (!form || !input) return;
    var inicial = input.value;
    var timer = null;
    // A busca é no banco (todas as páginas). O filtro antigo só escondia as linhas
    // já desenhadas nesta página, então um lead de outra página parecia não existir.
    input.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(function () {
            if (input.value.trim() !== inicial.trim()) form.submit();
        }, 400);
    });
})();
</script>
</body>
</html>
