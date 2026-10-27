<?php
declare(strict_types=1);

require_once __DIR__ . '/funcoes/usuario.php';
require_once __DIR__ . '/funcoes/paginador.php';
require_once __DIR__ . '/funcoes/icones.php';
require_once __DIR__ . '/funcoes/vendas_lista.php';
bloquearAdmin();

$id_usuario = (int) $_SESSION['usuario_id'];

$bot_id = isset($_GET['bot_id']) ? (int) $_GET['bot_id'] : 0;
$aba = (string) ($_GET['aba'] ?? '');
$busca = trim((string) ($_GET['busca'] ?? ''));
$periodo = (string) ($_GET['periodo'] ?? '');
$data_inicio = trim((string) ($_GET['data_inicio'] ?? ''));
$data_fim = trim((string) ($_GET['data_fim'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_inicio)) $data_inicio = '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_fim)) $data_fim = '';
// Atalhos de período: viram datas de verdade, então o filtro "de/até" mostra o recorte que está valendo.
if ($periodo === 'hoje') { $data_inicio = $data_fim = date('Y-m-d'); }
elseif ($periodo === 'ontem') { $data_inicio = $data_fim = date('Y-m-d', strtotime('-1 day')); }
elseif ($periodo === '7') { $data_inicio = date('Y-m-d', strtotime('-6 days')); $data_fim = ''; }
elseif ($periodo === '30') { $data_inicio = date('Y-m-d', strtotime('-29 days')); $data_fim = ''; }

// Bots do próprio usuário (poucas linhas) coladas como literais inteiros no WHERE: com bind o MySQL escolhia um plano pior
// em tabelas grandes (mesma razão de leads.php). Os valores vêm de uma consulta nossa e passam por intval.
$stmt_bots = $pdo->prepare('SELECT id, COALESCE(primeiro_nome, nome_usuario) AS nome FROM bots WHERE id_usuario = ?');
$stmt_bots->execute([$id_usuario]);
$meus_bots = $stmt_bots->fetchAll(PDO::FETCH_ASSOC);
$ids_bots = array_map('intval', array_column($meus_bots, 'id'));
if ($bot_id > 0) {
    $ids_bots = in_array($bot_id, $ids_bots, true) ? [$bot_id] : [];
}

$tem_nome_produto = colunaExiste('vendas', 'nome_produto');
$tem_id_fluxo = colunaExiste('vendas', 'id_fluxo');

$ABAS = [
    '' => ['Todas', null],
    'pagas' => ['Pagas', ["v.status = 'pago'"]],
    'pendentes' => ['Aguardando Pix', ["v.status = 'gerado'"]],
    'expiradas' => ['Expiradas / canceladas', ["v.status IN ('expirado', 'cancelado')"]],
];
if (!isset($ABAS[$aba])) $aba = '';

// Filtros que valem para a lista E para os cards (tudo menos a aba de status, para os cards continuarem mostrando o conjunto inteiro).
$where_base = [];
$params_base = [];
if (empty($ids_bots)) {
    $where_base[] = '1=0';
} else {
    $where_base[] = 'v.bot_id IN (' . implode(',', $ids_bots) . ')';
}
if ($data_inicio !== '') { $where_base[] = 'v.criado_em >= ?'; $params_base[] = $data_inicio . ' 00:00:00'; }
if ($data_fim !== '') { $where_base[] = 'v.criado_em <= ?'; $params_base[] = $data_fim . ' 23:59:59'; }
if ($busca !== '') {
    // Busca pensada para tabela grande (um vendedor chegou a ter 766 mil vendas): código da transação por PREFIXO e ID do Telegram exato
    // usam índice; o nome do cliente é resolvido antes, em leads (no máximo 200 ids), e vira "id_telegram IN (...)". Um LIKE %texto% direto
    // em vendas levava ~5 s nessa conta. O nome do produto não entra na busca (aparece na tabela e no CSV).
    $ids_por_nome = [];
    if (!empty($ids_bots) && mb_strlen($busca) >= 2) {
        $stmt_nome = $pdo->prepare('SELECT DISTINCT l.id_telegram FROM leads l WHERE l.bot_id IN (' . implode(',', $ids_bots) . ') AND l.nome LIKE ? LIMIT 200');
        $stmt_nome->execute(["%$busca%"]);
        $ids_por_nome = $stmt_nome->fetchAll(PDO::FETCH_COLUMN);
    }
    $condicoes = ['v.transacao_id LIKE ?', 'v.id_telegram = ?'];
    array_push($params_base, $busca . '%', $busca);
    if ($ids_por_nome) {
        $condicoes[] = 'v.id_telegram IN (' . implode(',', array_fill(0, count($ids_por_nome), '?')) . ')';
        foreach ($ids_por_nome as $id_tg) $params_base[] = (string) $id_tg;
    }
    $where_base[] = '(' . implode(' OR ', $condicoes) . ')';
}
$where_lista = $where_base;
if ($ABAS[$aba][1]) $where_lista = array_merge($where_lista, $ABAS[$aba][1]);
$where_sql = implode(' AND ', $where_lista);
$where_resumo_sql = implode(' AND ', $where_base);

$sql_campos = sqlCamposVendasLista($tem_nome_produto, $tem_id_fluxo);

// Cards: um agregado só, sobre o recorte (bot + período + busca), sem a aba de status.
$stmt = $pdo->prepare("SELECT COUNT(*) AS total, SUM(v.status = 'pago') AS pagas, COALESCE(SUM(CASE WHEN v.status = 'pago' THEN v.valor END), 0) AS faturado FROM vendas v WHERE $where_resumo_sql");
$stmt->execute($params_base);
$resumo = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'pagas' => 0, 'faturado' => 0];
$qtd_total = (int) $resumo['total'];
$qtd_pagas = (int) $resumo['pagas'];
$faturado = (float) $resumo['faturado'];
$ticket_medio = $qtd_pagas > 0 ? $faturado / $qtd_pagas : 0.0;
$conversao = $qtd_total > 0 ? $qtd_pagas / $qtd_total * 100 : 0.0;

$stmt = $pdo->prepare("SELECT COUNT(*) FROM vendas v WHERE $where_sql");
$stmt->execute($params_base);
$total_linhas = (int) $stmt->fetchColumn();

$por_pagina = 25;
$total_paginas = $total_linhas > 0 ? (int) ceil($total_linhas / $por_pagina) : 1;
$pagina_atual = min(max(1, (int) ($_GET['pagina'] ?? 1)), $total_paginas);
$offset = ($pagina_atual - 1) * $por_pagina;

// Duas etapas (mesmo padrão de leads.php e admin/transacoes.php): só os ids da página, ordenando direto em vendas; depois os dados completos desses ids.
$stmt = $pdo->prepare("SELECT v.id FROM vendas v WHERE $where_sql ORDER BY v.criado_em DESC, v.id DESC LIMIT $por_pagina OFFSET $offset");
$stmt->execute($params_base);
$ids_pagina = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
$vendas = [];
if ($ids_pagina) {
    $vendas = $pdo->query($sql_campos . ' WHERE v.id IN (' . implode(',', $ids_pagina) . ') ORDER BY v.criado_em DESC, v.id DESC')->fetchAll(PDO::FETCH_ASSOC);
}

$params_link = $_GET;
unset($params_link['pagina']);
$tem_filtro = $bot_id > 0 || $busca !== '' || $data_inicio !== '' || $data_fim !== '' || $aba !== '';
$dinheiro = static fn(float $v): string => 'R$ ' . number_format($v, 2, ',', '.');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vendas - <?php echo htmlspecialchars(nomeSistema()); ?></title>
    <?php include 'tema_inline.php'; ?>
    <link rel="stylesheet" href="assets/css/painel.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/painel.css'); ?>">
</head>
<body>
<div class="layout-painel">
    <?php include 'barra_lateral.php'; ?>
    <main class="conteudo-principal">
        <header class="a-ph">
            <div>
                <h1 class="a-h1">Vendas</h1>
                <p class="a-sub">Registro de todas as vendas dos seus bots: pagas, aguardando Pix e expiradas.</p>
            </div>
        </header>

        <section class="a-grid" id="resumo-vendas" style="margin-bottom: 16px" aria-label="Resumo das vendas">
            <div class="a-card a-kpi s3 wide-m">
                <span class="a-lab">Faturado</span>
                <span class="a-kv"><?php echo $dinheiro($faturado); ?></span>
                <span class="a-kf"><?php echo number_format($qtd_pagas, 0, ',', '.'); ?> <?php echo $qtd_pagas === 1 ? 'venda paga' : 'vendas pagas'; ?></span>
            </div>
            <div class="a-card a-kpi s3">
                <span class="a-lab">Ticket médio</span>
                <span class="a-kv"><?php echo $dinheiro($ticket_medio); ?></span>
                <span class="a-kf">Por venda paga</span>
            </div>
            <div class="a-card a-kpi s3">
                <span class="a-lab">Pix gerados</span>
                <span class="a-kv"><?php echo number_format($qtd_total, 0, ',', '.'); ?></span>
                <span class="a-kf"><?php echo number_format($qtd_total - $qtd_pagas, 0, ',', '.'); ?> sem pagamento</span>
            </div>
            <div class="a-card a-kpi s3">
                <span class="a-lab">Conversão</span>
                <span class="a-kv"><?php echo number_format($conversao, 1, ',', '.'); ?>%</span>
                <span class="a-kf">Pagos / gerados</span>
            </div>
        </section>

        <input type="checkbox" id="a-f" class="a-cb a-cbf" aria-label="Mostrar filtros">
        <form method="GET" class="a-card a-fv flat" id="form-filtros-vendas">
            <?php if ($aba !== ''): ?><input type="hidden" name="aba" value="<?php echo htmlspecialchars($aba); ?>"><?php endif; ?>
            <div class="segs gav">
                <div class="a-seg" role="group" aria-label="Status da venda">
                    <?php foreach ($ABAS as $chave => [$rotulo]): ?>
                        <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($params_link, ['aba' => $chave]))); ?>"<?php echo $aba === $chave ? ' class="on" aria-current="true"' : ''; ?>><?php echo htmlspecialchars($rotulo); ?></a>
                    <?php endforeach; ?>
                </div>
                <div class="a-seg" role="group" aria-label="Período">
                    <?php foreach (['hoje' => 'Hoje', 'ontem' => 'Ontem', '7' => '7 dias', '30' => '30 dias', '' => 'Tudo'] as $chave => $rotulo):
                        $ativo_periodo = $chave === '' ? ($data_inicio === '' && $data_fim === '') : ($periodo === (string) $chave);
                        $link_periodo = array_merge($params_link, ['periodo' => $chave, 'data_inicio' => '', 'data_fim' => '']);
                    ?>
                        <a href="?<?php echo htmlspecialchars(http_build_query($link_periodo)); ?>"<?php echo $ativo_periodo ? ' class="on" aria-current="true"' : ''; ?>><?php echo $rotulo; ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
            <label class="a-search" for="busca-venda">
                <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="M20 20l-4-4"></path></svg>
                <input class="a-in" type="text" name="busca" id="busca-venda" value="<?php echo htmlspecialchars($busca); ?>" placeholder="Cliente, ID do Telegram ou código da transação" aria-label="Buscar venda">
            </label>
            <label class="a-btn a-fbtn" for="a-f" aria-label="Filtros">
                <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M22 3H2l8 9.46V19l4 2v-8.54L22 3z"></path></svg><span class="t">Filtros</span>
            </label>
            <label class="a-fld gav bot" for="filtro-bot-venda"><span>Bot</span>
                <select class="a-in" name="bot_id" id="filtro-bot-venda" onchange="this.form.submit()">
                    <option value="">Todos os bots</option>
                    <?php foreach ($meus_bots as $b): ?>
                        <option value="<?php echo (int) $b['id']; ?>" <?php echo $bot_id === (int) $b['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars((string) $b['nome']); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="acts gav">
                <button type="submit" class="a-btn pri">Filtrar</button>
                <?php if ($tem_filtro): ?><a href="vendas" class="a-btn">Limpar</a><?php endif; ?>
            </div>
        </form>

        <section class="a-card a-tw flat" aria-label="Lista de vendas">
            <table class="a-t" id="tabela-vendas">
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Cliente</th>
                        <th>Produto</th>
                        <th>Status</th>
                        <th class="num">Valor</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($vendas)): ?>
                        <tr><td colspan="6" class="full a-vazio-linha"><?php echo $tem_filtro ? 'Nenhuma venda encontrada com esses filtros.' : 'Nenhuma venda ainda. Quando alguém gerar um Pix no seu bot, ele aparece aqui.'; ?></td></tr>
                    <?php else: foreach ($vendas as $v):
                        $cliente = trim((string) ($v['nome_cliente'] ?? '')) ?: 'Cliente ' . $v['id_telegram'];
                        $produto = trim((string) ($v['nome_produto'] ?? ''));
                        $tipo_v = tipoDaVenda($v);
                        $detalhe = [
                            'id' => (int) $v['id'],
                            'cliente' => $cliente,
                            'id_telegram' => (string) $v['id_telegram'],
                            'username' => !empty($v['username']) ? '@' . $v['username'] : '',
                            'produto' => $produto,
                            'tipo_venda' => rotuloTipoDaVenda($tipo_v),
                            'fluxo' => (string) ($v['nome_fluxo'] ?? ''),
                            'modo_fluxo' => $v['modo_fluxo'] === 'basico' ? 'Guiado' : ($v['modo_fluxo'] ? 'Visual' : ''),
                            'fluxo_removido' => empty($v['nome_fluxo']) && !empty($v['id_fluxo']),
                            'bot' => (string) $v['nome_bot'],
                            'cobranca' => rotuloTipoCobranca($v['tipo_cobranca']),
                            'status' => (string) $v['status'],
                            'criada_em' => date('d/m/Y H:i', strtotime($v['criado_em'])),
                            'paga_em' => $v['pago_em'] ? date('d/m/Y H:i', strtotime($v['pago_em'])) : '',
                            'valor' => $dinheiro((float) $v['valor']),
                            'codigo' => (string) ($v['transacao_id'] ?? ''),
                        ];
                    ?>
                        <tr>
                            <td data-l="Data" class="mono o3"><?php echo date('d/m/Y H:i', strtotime($v['criado_em'])); ?></td>
                            <td class="c-main">
                                <b><?php echo htmlspecialchars($cliente); ?></b>
                                <span class="sub">ID: <?php echo htmlspecialchars((string) $v['id_telegram']); ?></span>
                            </td>
                            <td data-l="Produto" class="full o5">
                                <?php echo $produto !== '' ? htmlspecialchars($produto) : '<span class="a-mut">—</span>'; ?>
                                <?php if ($tipo_v !== 'primeira'): ?>
                                    <span class="a-bd<?php echo $tipo_v === 'recompra' ? '' : ' wa'; ?>"><?php echo $tipo_v === 'recompra' ? 'Recompra' : 'Renovação'; ?></span>
                                <?php endif; ?>
                            </td>
                            <td data-l="Status" class="o4"><?php echo rotuloStatusVenda((string) $v['status']); ?></td>
                            <td class="num c-val"><?php echo $dinheiro((float) $v['valor']); ?></td>
                            <td class="act o6">
                                <button type="button" class="a-btn btn-detalhes-venda" data-venda="<?php echo htmlspecialchars(json_encode($detalhe, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP), ENT_QUOTES); ?>" title="Ver detalhes" aria-label="Ver detalhes da venda <?php echo (int) $v['id']; ?>"><?php echo iconeAcao('ver'); ?>Detalhes</button>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </section>
        <?php echo paginador($total_linhas, $por_pagina); ?>
    </main>
</div>
<script src="assets/js/tema.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/tema.js'); ?>"></script>

<div id="modal-venda" class="a-over" aria-hidden="true">
    <div class="a-modal" role="dialog" aria-modal="true" aria-labelledby="modal-venda-titulo">
        <div class="a-mh">
            <h2 id="modal-venda-titulo">Venda</h2>
            <button type="button" class="a-ib" id="modal-venda-fechar" aria-label="Fechar"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6L6 18M6 6l12 12"></path></svg></button>
        </div>
        <div class="a-vv">
            <span class="a-kv" id="mv-valor"></span>
            <div id="mv-status"></div>
        </div>
        <div id="mv-lista"></div>
    </div>
</div>
<script>
(function () {
    var modal = document.getElementById('modal-venda');
    var lista = document.getElementById('mv-lista');
    var ROTULOS_STATUS = { pago: ['Pago', 'ok'], gerado: ['Aguardando Pix', 'wa'], expirado: ['Expirado', ''], cancelado: ['Cancelado', ''] };
    var gatilho = null;
    function el(tag, classe, texto) { var e = document.createElement(tag); if (classe) e.className = classe; if (texto != null) e.textContent = texto; return e; }
    // Um campo = rótulo pequeno em cima e valor embaixo; vários campos de uma seção ficam em grade (2 colunas, 1 no celular).
    function campo(rotulo, conteudo, larga) {
        var c = el('div', 'a-fld');
        if (larga) c.style.gridColumn = '1 / -1';
        c.appendChild(el('span', '', rotulo));
        var v = el('b');
        if (typeof conteudo === 'string') v.textContent = conteudo || '—'; else v.appendChild(conteudo);
        c.appendChild(v);
        return c;
    }
    function secao(titulo, campos) {
        var s = el('section', 'a-sec');
        s.appendChild(el('h4', '', titulo));
        var g = el('div', 'a-sg');
        campos.forEach(function (c) { g.appendChild(c); });
        s.appendChild(g);
        lista.appendChild(s);
    }
    function selo(texto, classe) { return el('span', 'a-bd' + (classe ? ' ' + classe : ''), texto); }
    function abrir(v) {
        document.getElementById('modal-venda-titulo').textContent = 'Venda #' + v.id;
        document.getElementById('mv-valor').textContent = v.valor;
        var st = ROTULOS_STATUS[v.status] || [v.status, ''];
        var alvo = document.getElementById('mv-status'); alvo.innerHTML = ''; alvo.appendChild(selo(st[0], st[1]));
        lista.innerHTML = '';

        var cliente = el('div');
        cliente.appendChild(el('div', '', v.cliente));
        cliente.appendChild(el('small', 'mono a-mut', 'ID ' + v.id_telegram + (v.username ? ' · ' + v.username : '')));
        secao('Cliente', [campo('Nome', cliente, true)]);

        var tipoClasse = v.tipo_venda === 'Primeira compra' ? 'ok' : (v.tipo_venda === 'Recompra' ? '' : 'wa');
        var produto = el('div');
        produto.appendChild(el('div', '', v.produto || '—'));
        if (v.tipo_venda) { var linhaTipo = el('div'); linhaTipo.style.marginTop = '4px'; linhaTipo.appendChild(selo(v.tipo_venda, tipoClasse)); produto.appendChild(linhaTipo); }
        var fluxo = el('div');
        if (v.fluxo) {
            fluxo.appendChild(el('div', '', v.fluxo));
            if (v.modo_fluxo) { var linhaModo = el('div'); linhaModo.style.marginTop = '4px'; linhaModo.appendChild(selo(v.modo_fluxo, v.modo_fluxo === 'Guiado' ? 'or' : 'vi')); fluxo.appendChild(linhaModo); }
        } else {
            fluxo.appendChild(el('div', 'a-mut', v.fluxo_removido ? 'Fluxo removido' : '—'));
        }
        secao('Compra', [campo('Produto', produto), campo('Fluxo', fluxo), campo('Bot', v.bot), campo('Cobrança', v.cobranca)]);

        secao('Datas', [campo('Criada em', v.criada_em), campo('Paga em', v.paga_em)]);

        var cod = el('div');
        cod.style.cssText = 'display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap';
        cod.appendChild(el('span', 'mono', v.codigo || '—'));
        cod.firstChild.style.overflowWrap = 'anywhere';
        if (v.codigo && navigator.clipboard) {
            var copiar = el('button', 'a-btn', 'Copiar'); copiar.type = 'button';
            copiar.addEventListener('click', function () { navigator.clipboard.writeText(v.codigo).then(function () { copiar.textContent = 'Copiado'; setTimeout(function () { copiar.textContent = 'Copiar'; }, 1500); }); });
            cod.appendChild(copiar);
        }
        secao('Transação', [campo('Código', cod, true)]);
        modal.classList.add('aberto'); modal.setAttribute('aria-hidden', 'false');
        document.getElementById('modal-venda-fechar').focus();
    }
    function fechar() {
        if (!modal.classList.contains('aberto')) return;
        modal.classList.remove('aberto'); modal.setAttribute('aria-hidden', 'true');
        if (gatilho && gatilho.focus) gatilho.focus();
    }
    document.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('.btn-detalhes-venda') : null;
        if (b) { try { gatilho = b; abrir(JSON.parse(b.getAttribute('data-venda'))); } catch (x) {} return; }
        if (e.target === modal || (e.target.closest && e.target.closest('#modal-venda-fechar'))) fechar();
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') fechar(); });
})();
</script>
</body>
</html>
