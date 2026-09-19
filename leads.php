<?php
declare(strict_types=1);

require_once __DIR__ . '/funcoes/usuario.php';
require_once __DIR__ . '/funcoes/paginador.php';
bloquearAdmin();

$id_usuario = $_SESSION['usuario_id'];
$is_admin = ehAdmin();

$bot_id = isset($_GET['bot_id']) ? (int)$_GET['bot_id'] : 0;
$status = isset($_GET['status']) ? $_GET['status'] : '';

// Resolve os bots do usuário primeiro (sempre poucas linhas) e filtra leads direto
// por "l.bot_id IN (...)", em vez de "JOIN bots b WHERE b.id_usuario = ?". Testado:
// com o filtro no JOIN, o MySQL não usava o índice (bot_id, criado_em) de leads e
// fazia table scan completo + filesort mesmo só pra pegar 25 linhas (ver
// anotacoes/analise-potencia-e-escala.md) -- filtrando direto em l.bot_id, o índice
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

    if ($status === 'pago') {
        $where[] = "EXISTS (SELECT 1 FROM vendas v WHERE v.id_telegram = l.id_telegram AND v.bot_id = l.bot_id AND v.status = 'pago')";
    } elseif ($status === 'nao_pago') {
        $where[] = "NOT EXISTS (SELECT 1 FROM vendas v WHERE v.id_telegram = l.id_telegram AND v.bot_id = l.bot_id AND v.status = 'pago')";
    }

    $where_sql = implode(' AND ', $where);
}
$sql_base = "FROM leads l JOIN bots b ON l.bot_id = b.id WHERE $where_sql";

$exportando_csv = isset($_GET['export']) && $_GET['export'] === 'csv';

$sql_campos = "
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
            $lead['nome'],
            $lead['id_telegram'],
            $lead['nome_bot'],
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
$offset = ($pagina_atual - 1) * $por_pagina;

$stmt = $pdo->prepare($sql_campos . " LIMIT $por_pagina OFFSET $offset");
$stmt->execute($params);
$leads = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
    <?php include 'tema_inline.php'; ?>
    <link rel="stylesheet" href="assets/css/coyote.css?v=<?php echo @filemtime(__DIR__.'/assets/css/coyote.css'); ?>">
</head>
<body>
<div class="layout-painel">
    <?php include 'barra_lateral.php'; ?>
    <main class="conteudo-principal">
        <div class="cabecalho-pagina">
            <div>
                <h1>Leads</h1>
                <p>Visualize os usuários que interagiram com seus bots.</p>
            </div>
            <div class="acoes-cabecalho">
                <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>" class="botao">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                    Exportar CSV
                </a>
                <button type="button" class="alternador-tema" onclick="alternarTema()" aria-label="Alternar tema">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"></path></svg>
                    Tema
                </button>
            </div>
        </div>

        <div class="painel">
            <div class="barra-filtros">
                <div class="campo-busca">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"></circle><path d="M20 20l-4-4"></path></svg>
                    <input type="text" id="busca-lead" placeholder="Buscar por nome ou ID do Telegram">
                </div>
                <div class="abas-status">
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['status' => ''])); ?>" class="aba-status<?php echo $status === '' ? ' ativa' : ''; ?>">Todos</a>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['status' => 'pago'])); ?>" class="aba-status<?php echo $status === 'pago' ? ' ativa' : ''; ?>">Já pagou</a>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['status' => 'nao_pago'])); ?>" class="aba-status<?php echo $status === 'nao_pago' ? ' ativa' : ''; ?>">Não pagou</a>
                </div>
                <form method="GET">
                    <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?php echo htmlspecialchars($status); ?>"><?php endif; ?>
                    <select name="bot_id" onchange="this.form.submit()">
                        <option value="">Todos os Bots</option>
                        <?php foreach ($meus_bots as $b): ?>
                            <option value="<?php echo $b['id']; ?>" <?php echo $bot_id == $b['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($b['nome']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>

            <div class="tabela-dados">
                <table id="tabela-leads">
                    <thead>
                        <tr>
                            <th>Lead</th>
                            <th>Número</th>
                            <th>Bot</th>
                            <th>Início</th>
                            <th>Status</th>
                            <th>Plano</th>
                            <th>Compras</th>
                            <th class="col-numerica">Total gasto</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($leads)): ?>
                            <tr>
                                <td colspan="8" style="text-align: center; padding: 20px; color: var(--m);">Nenhum lead encontrado.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($leads as $lead): ?>
                                <?php
                                    $status_badge = '<span class="badge badge-neutro">Iniciou</span>';
                                    if ($lead['total_compras'] > 0) {
                                        $status_badge = '<span class="badge badge-sucesso">Cliente</span>';
                                    } elseif ($lead['ultimo_status_pagamento'] === 'gerado') {
                                        $status_badge = '<span class="badge badge-alerta">Gerou Pix</span>';
                                    }
                                    $inicial_lead = mb_strtoupper(mb_substr($lead['nome'] ?: '?', 0, 1), 'UTF-8');
                                    $busca_lead = mb_strtolower($lead['nome'] . ' ' . $lead['id_telegram'], 'UTF-8');
                                ?>
                                <tr data-busca="<?php echo htmlspecialchars($busca_lead); ?>">
                                    <td>
                                        <div class="celula-principal">
                                            <span class="avatar-item"><?php echo htmlspecialchars($inicial_lead); ?></span>
                                            <div>
                                                <div><?php echo htmlspecialchars($lead['nome']); ?></div>
                                                <div class="celula-sub mono">ID: <?php echo htmlspecialchars((string) $lead['id_telegram']); ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?php echo $lead['telefone'] ? htmlspecialchars($lead['telefone']) : '<span class="texto-suave">—</span>'; ?></td>
                                    <td><?php echo htmlspecialchars($lead['nome_bot']); ?></td>
                                    <td class="mono"><?php echo date('d/m/Y H:i', strtotime($lead['data_inicio'])); ?></td>
                                    <td><?php echo $status_badge; ?></td>
                                    <td>
                                        <?php echo $lead['plano_ativo'] ? '<span class="badge badge-sucesso">Ativo</span>' : '<span class="badge badge-neutro">Inativo</span>'; ?>
                                    </td>
                                    <td><?php echo $lead['total_compras']; ?></td>
                                    <td class="col-numerica mono">R$ <?php echo number_format((float)$lead['total_gasto'], 2, ',', '.'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php echo paginador($total_leads, $por_pagina); ?>
        </div>
    </main>
</div>

<script src="assets/js/tema.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/tema.js'); ?>"></script>
<script>
document.getElementById('busca-lead').addEventListener('input', function () {
    const termo = this.value.trim().toLowerCase();
    document.querySelectorAll('#tabela-leads tbody tr[data-busca]').forEach(function (linha) {
        linha.style.display = linha.dataset.busca.includes(termo) ? '' : 'none';
    });
});
</script>
</body>
</html>
