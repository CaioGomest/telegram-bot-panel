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
        <div class="cabecalho-pagina">
            <div>
                <h1>Vendas</h1>
                <p>Registro de todas as vendas dos seus bots: pagas, aguardando Pix e expiradas.</p>
            </div>
            <div class="acoes-cabecalho">
                <button type="button" class="alternador-tema" onclick="alternarTema()" aria-label="Alternar tema">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"></path></svg>
                    Tema
                </button>
            </div>
        </div>

        <div class="grade-kpi" id="resumo-vendas">
            <div class="cartao-kpi">
                <div class="cartao-kpi-cabecalho"><span class="rotulo-kpi">Faturado</span></div>
                <div class="valor-kpi"><?php echo $dinheiro($faturado); ?></div>
                <div class="rodape-kpi"><span><?php echo number_format($qtd_pagas, 0, ',', '.'); ?> <?php echo $qtd_pagas === 1 ? 'venda paga' : 'vendas pagas'; ?></span></div>
            </div>
            <div class="cartao-kpi">
                <div class="cartao-kpi-cabecalho"><span class="rotulo-kpi">Ticket médio</span></div>
                <div class="valor-kpi"><?php echo $dinheiro($ticket_medio); ?></div>
                <div class="rodape-kpi"><span>Por venda paga</span></div>
            </div>
            <div class="cartao-kpi">
                <div class="cartao-kpi-cabecalho"><span class="rotulo-kpi">Pix gerados</span></div>
                <div class="valor-kpi"><?php echo number_format($qtd_total, 0, ',', '.'); ?></div>
                <div class="rodape-kpi"><span><?php echo number_format($qtd_total - $qtd_pagas, 0, ',', '.'); ?> sem pagamento</span></div>
            </div>
            <div class="cartao-kpi">
                <div class="cartao-kpi-cabecalho"><span class="rotulo-kpi">Conversão</span></div>
                <div class="valor-kpi"><?php echo number_format($conversao, 1, ',', '.'); ?>%</div>
                <div class="rodape-kpi"><span>Pagos / gerados</span></div>
            </div>
        </div>

        <div class="filtros-vendas">
            <div class="filtros-vendas-abas">
                <div class="abas-status">
                    <?php foreach ($ABAS as $chave => [$rotulo]): ?>
                        <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($params_link, ['aba' => $chave]))); ?>" class="aba-status<?php echo $aba === $chave ? ' ativa' : ''; ?>"><?php echo htmlspecialchars($rotulo); ?></a>
                    <?php endforeach; ?>
                </div>
                <div class="abas-status">
                    <?php foreach (['hoje' => 'Hoje', '7' => '7 dias', '30' => '30 dias', '' => 'Tudo'] as $chave => $rotulo):
                        $ativo_periodo = $chave === '' ? ($data_inicio === '' && $data_fim === '') : ($periodo === (string) $chave);
                        $link_periodo = array_merge($params_link, ['periodo' => $chave, 'data_inicio' => '', 'data_fim' => '']);
                    ?>
                        <a href="?<?php echo htmlspecialchars(http_build_query($link_periodo)); ?>" class="aba-status<?php echo $ativo_periodo ? ' ativa' : ''; ?>"><?php echo $rotulo; ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
            <form method="GET" class="filtros-vendas-form">
                <?php if ($aba !== ''): ?><input type="hidden" name="aba" value="<?php echo htmlspecialchars($aba); ?>"><?php endif; ?>
                <label class="filtro-campo filtro-bot"><span>Bot</span>
                    <select name="bot_id" onchange="this.form.submit()">
                        <option value="">Todos os bots</option>
                        <?php foreach ($meus_bots as $b): ?>
                            <option value="<?php echo (int) $b['id']; ?>" <?php echo $bot_id === (int) $b['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars((string) $b['nome']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="filtro-campo filtro-data"><span>De</span><input type="date" name="data_inicio" value="<?php echo htmlspecialchars($data_inicio); ?>"></label>
                <label class="filtro-campo filtro-data"><span>Até</span><input type="date" name="data_fim" value="<?php echo htmlspecialchars($data_fim); ?>"></label>
                <label class="filtro-campo filtro-busca"><span>Buscar</span><input type="text" name="busca" id="busca-venda" value="<?php echo htmlspecialchars($busca); ?>" placeholder="Cliente, ID do Telegram ou código da transação"></label>
                <div class="filtro-acoes">
                    <button type="submit" class="botao botao-primario">Filtrar</button>
                    <?php if ($tem_filtro): ?><a href="vendas" class="botao">Limpar</a><?php endif; ?>
                </div>
            </form>
        </div>

        <div class="painel">
            <div class="tabela-dados">
                <table id="tabela-vendas">
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Cliente</th>
                            <th>Produto</th>
                            <th>Status</th>
                            <th class="col-numerica">Valor</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($vendas)): ?>
                            <tr><td colspan="6" style="text-align:center;padding:20px;color:var(--m);"><?php echo $tem_filtro ? 'Nenhuma venda encontrada com esses filtros.' : 'Nenhuma venda ainda. Quando alguém gerar um Pix no seu bot, ele aparece aqui.'; ?></td></tr>
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
                                <td class="mono"><?php echo date('d/m/Y H:i', strtotime($v['criado_em'])); ?></td>
                                <td>
                                    <div><?php echo htmlspecialchars($cliente); ?></div>
                                    <div class="celula-sub mono">ID: <?php echo htmlspecialchars((string) $v['id_telegram']); ?></div>
                                </td>
                                <td>
                                    <?php echo $produto !== '' ? htmlspecialchars($produto) : '<span class="texto-suave">—</span>'; ?>
                                    <?php if ($tipo_v !== 'primeira'): ?>
                                        <span class="badge <?php echo $tipo_v === 'recompra' ? 'badge-neutro' : 'badge-alerta'; ?>"><?php echo $tipo_v === 'recompra' ? 'Recompra' : 'Renovação'; ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo rotuloStatusVenda((string) $v['status']); ?></td>
                                <td class="col-numerica mono"><?php echo $dinheiro((float) $v['valor']); ?></td>
                                <td>
                                    <div class="col-acoes">
                                        <button type="button" class="btn-icon ver btn-detalhes-venda" data-venda="<?php echo htmlspecialchars(json_encode($detalhe, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP), ENT_QUOTES); ?>" title="Ver detalhes" aria-label="Ver detalhes"><?php echo iconeAcao('ver'); ?></button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
            <?php echo paginador($total_linhas, $por_pagina); ?>
        </div>
    </main>
</div>
<script src="assets/js/tema.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/tema.js'); ?>"></script>

<div id="modal-venda" class="sobreposicao-modal" aria-hidden="true">
    <div class="modal-gateway" style="max-width: 600px;" role="dialog" aria-modal="true" aria-labelledby="modal-venda-titulo">
        <div class="cabecalho-modal">
            <div class="titulo-modal" id="modal-venda-titulo">Venda</div>
            <button type="button" class="fechar-modal" id="modal-venda-fechar" aria-label="Fechar">✕</button>
        </div>
        <div class="corpo-modal">
            <div class="venda-resumo">
                <div class="venda-resumo-valor" id="mv-valor"></div>
                <div id="mv-status"></div>
            </div>
            <div class="venda-detalhes" id="mv-lista"></div>
        </div>
    </div>
</div>
<script>
(function () {
    var modal = document.getElementById('modal-venda');
    var lista = document.getElementById('mv-lista');
    var ROTULOS_STATUS = { pago: ['Pago', 'badge-sucesso'], gerado: ['Aguardando Pix', 'badge-alerta'], expirado: ['Expirado', 'badge-neutro'], cancelado: ['Cancelado', 'badge-neutro'] };
    function el(tag, classe, texto) { var e = document.createElement(tag); if (classe) e.className = classe; if (texto != null) e.textContent = texto; return e; }
    // Um campo = rótulo pequeno em cima e valor embaixo (com bastante respiro); vários campos de uma seção ficam em grade.
    function campo(rotulo, conteudo) {
        var c = el('div', 'venda-campo');
        c.appendChild(el('span', 'venda-campo-rotulo', rotulo));
        var v = el('div', 'venda-campo-valor');
        if (typeof conteudo === 'string') v.textContent = conteudo || '—'; else v.appendChild(conteudo);
        c.appendChild(v);
        return c;
    }
    function secao(titulo, campos) {
        var s = el('section', 'venda-secao');
        s.appendChild(el('h4', 'venda-secao-titulo', titulo));
        var g = el('div', 'venda-secao-grade');
        campos.forEach(function (c) { g.appendChild(c); });
        s.appendChild(g);
        lista.appendChild(s);
    }
    function selo(texto, classe) { return el('span', 'badge ' + classe, texto); }
    function abrir(v) {
        document.getElementById('modal-venda-titulo').textContent = 'Venda #' + v.id;
        document.getElementById('mv-valor').textContent = v.valor;
        var st = ROTULOS_STATUS[v.status] || [v.status, 'badge-neutro'];
        var alvo = document.getElementById('mv-status'); alvo.innerHTML = ''; alvo.appendChild(selo(st[0], st[1]));
        lista.innerHTML = '';

        var cliente = el('div');
        cliente.appendChild(el('div', '', v.cliente));
        cliente.appendChild(el('div', 'venda-sub mono', 'ID ' + v.id_telegram + (v.username ? ' · ' + v.username : '')));
        secao('Cliente', [campo('Nome', cliente)]);

        var tipoClasse = v.tipo_venda === 'Primeira compra' ? 'badge-sucesso' : (v.tipo_venda === 'Recompra' ? 'badge-neutro' : 'badge-alerta');
        var produto = el('div');
        produto.appendChild(el('div', '', v.produto || '—'));
        if (v.tipo_venda) { var linhaTipo = el('div', 'venda-sub'); linhaTipo.appendChild(selo(v.tipo_venda, tipoClasse)); produto.appendChild(linhaTipo); }
        var fluxo = el('div');
        if (v.fluxo) {
            fluxo.appendChild(el('div', '', v.fluxo));
            if (v.modo_fluxo) { var linhaModo = el('div', 'venda-sub'); linhaModo.appendChild(el('span', 'badge-modo-fluxo ' + (v.modo_fluxo === 'Guiado' ? 'basico' : 'visual') + ' venda-modo', v.modo_fluxo)); fluxo.appendChild(linhaModo); }
        } else {
            fluxo.appendChild(el('div', 'texto-suave', v.fluxo_removido ? 'Fluxo removido' : '—'));
        }
        secao('Compra', [campo('Produto', produto), campo('Fluxo', fluxo), campo('Bot', v.bot), campo('Cobrança', v.cobranca)]);

        secao('Datas', [campo('Criada em', v.criada_em), campo('Paga em', v.paga_em)]);

        var cod = el('div', 'venda-codigo');
        cod.appendChild(el('span', 'mono', v.codigo || '—'));
        if (v.codigo && navigator.clipboard) {
            var copiar = el('button', 'botao', 'Copiar'); copiar.type = 'button';
            copiar.addEventListener('click', function () { navigator.clipboard.writeText(v.codigo).then(function () { copiar.textContent = 'Copiado'; setTimeout(function () { copiar.textContent = 'Copiar'; }, 1500); }); });
            cod.appendChild(copiar);
        }
        secao('Transação', [campo('Código', cod)]);
        modal.classList.add('aberto'); modal.setAttribute('aria-hidden', 'false');
    }
    function fechar() { modal.classList.remove('aberto'); modal.setAttribute('aria-hidden', 'true'); }
    document.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('.btn-detalhes-venda') : null;
        if (b) { try { abrir(JSON.parse(b.getAttribute('data-venda'))); } catch (x) {} return; }
        if (e.target === modal || e.target.id === 'modal-venda-fechar') fechar();
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') fechar(); });
})();
</script>
</body>
</html>
