<?php
declare(strict_types=1);

require_once __DIR__ . '/funcoes/usuario.php';
require_once __DIR__ . '/funcoes/paginador.php';
require_once __DIR__ . '/funcoes/remarketing_campanhas.php';
bloquearAdmin();
$id_usuario = (int) $_SESSION['usuario_id'];

$campanha_id = (int) ($_GET['campanha'] ?? 0);

// A query antiga (antes desta virar página própria) nunca conferia se a campanha era do
// usuário logado -- qualquer conta autenticada conseguia ver o log de envio (IDs de
// Telegram reais) de campanha de outra conta só trocando o número na URL. Corrigido junto
// com a separação de página. A campanha de um fluxo não tem bot fixo (bot_id vazio): por isso LEFT JOIN.
$stmt_campanha = $pdo->prepare("
    SELECT c.*, f.nome AS nome_fluxo, COALESCE(b.primeiro_nome, b.nome_usuario) AS nome_bot
    FROM remarketing_campanhas c
    LEFT JOIN fluxos f ON f.id = c.id_fluxo AND f.id_usuario = c.id_usuario
    LEFT JOIN bots b ON b.id = c.bot_id
    WHERE c.id = ? AND c.id_usuario = ?
");
$stmt_campanha->execute([$campanha_id, $id_usuario]);
$campanha = $stmt_campanha->fetch(PDO::FETCH_ASSOC);

if ($campanha) {
    $tem_variante = colunaExiste('remarketing_envios', 'variante');
    $resultado = in_array($_GET['resultado'] ?? '', ['sucesso', 'falha', 'todos'], true) ? $_GET['resultado'] : 'todos';
    $pagina = max(1, (int) ($_GET['pagina'] ?? 1));
    $por_pagina = 50;

    $where = "campanha_id = ?";
    $params = [$campanha_id];
    if ($resultado !== 'todos') {
        $where .= " AND resultado = ?";
        $params[] = $resultado;
    }

    $stmt_total = $pdo->prepare("SELECT COUNT(*) FROM remarketing_envios WHERE $where");
    $stmt_total->execute($params);
    $total = (int) $stmt_total->fetchColumn();
    $total_paginas = max(1, (int) ceil($total / $por_pagina));
    $pagina = min($pagina, $total_paginas);
    $offset = ($pagina - 1) * $por_pagina;

    $stmt_envios = $pdo->prepare("SELECT * FROM remarketing_envios WHERE $where ORDER BY enviado_em DESC LIMIT $por_pagina OFFSET $offset");
    $stmt_envios->execute($params);
    $envios = $stmt_envios->fetchAll(PDO::FETCH_ASSOC);

    function urlDetalhesRemarketing(int $campanha_id, string $resultado, int $pagina): string
    {
        return 'remarketing_detalhes?campanha=' . $campanha_id . '&resultado=' . urlencode($resultado) . '&pagina=' . $pagina;
    }

    $classes_status = ['rascunho' => 'or', 'pendente' => 'wa', 'processando' => 'ok', 'concluida' => '', 'falha' => 'er'];
    $variantes = remarketingVariantesDaCampanha($campanha);
    $ofertas = remarketingOfertasDaCampanha($campanha);

    // Números de cada variante: envios (registro de envios) e vendas pagas (remarketing_vendas).
    $por_variante = [];
    foreach (array_keys($variantes) as $i) {
        $por_variante[$i] = ['enviadas' => 0, 'falhas' => 0, 'pagas' => 0, 'receita' => 0.0];
    }
    if ($tem_variante) {
        $st = $pdo->prepare("SELECT variante, SUM(resultado = 'sucesso') AS ok, SUM(resultado = 'falha') AS falha FROM remarketing_envios WHERE campanha_id = ? GROUP BY variante");
        $st->execute([$campanha_id]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $i = min((int) $l['variante'], count($variantes) - 1);
            $por_variante[$i]['enviadas'] += (int) $l['ok'];
            $por_variante[$i]['falhas'] += (int) $l['falha'];
        }
    } else {
        $por_variante[0]['enviadas'] = (int) $campanha['entregues'];
        $por_variante[0]['falhas'] = (int) $campanha['falhas'];
    }
    $st = $pdo->prepare("SELECT rv.variante, COUNT(*) AS pagas, SUM(v.valor) AS receita FROM remarketing_vendas rv JOIN vendas v ON v.id = rv.venda_id AND v.status = 'pago' WHERE rv.campanha_id = ? GROUP BY rv.variante");
    $st->execute([$campanha_id]);
    $vendas_pagas = 0;
    $receita_total = 0.0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $i = min((int) $l['variante'], count($variantes) - 1);
        $por_variante[$i]['pagas'] += (int) $l['pagas'];
        $por_variante[$i]['receita'] += (float) $l['receita'];
        $vendas_pagas += (int) $l['pagas'];
        $receita_total += (float) $l['receita'];
    }
    // Agendada para já mas esperando o fluxo liberar (outra campanha do fluxo em envio ou envio recente): não está em envio.
    $vencida = $campanha['status'] === 'pendente' && strtotime((string) $campanha['agendado_em']) <= time();
    $bloqueio_fluxo = $vencida ? remarketingBloqueioDoGrupo(remarketingGrupoDeCampanhas($pdo, $id_usuario, $campanha), (int) $campanha['id'], time()) : null;
    $retida = $bloqueio_fluxo !== null && $bloqueio_fluxo['motivo'] !== 'agendada_perto';
    $em_envio = $campanha['status'] === 'processando' || ($vencida && !$retida);
    $rotulo_status = $em_envio ? 'Ativa' : (REMARKETING_STATUS_ROTULOS[$campanha['status']] ?? $campanha['status']);
    $recorrencia = remarketingRecorrenciaDaCampanha($campanha);
    $execucoes = max((int) ($campanha['execucoes'] ?? 0), remarketingTemExecucaoAnterior($campanha) || $campanha['status'] === 'processando' ? 1 : 0);
    $entregues_total = (int) $campanha['entregues'] + (int) ($campanha['acum_entregues'] ?? 0);   // de todas as execuções
    $falhas_total = (int) $campanha['falhas'] + (int) ($campanha['acum_falhas'] ?? 0);
    $periodos = REMARKETING_PERIODOS;
    $periodo_texto = $periodos[$campanha['periodo_tipo'] ?? 'total'] ?? 'Total';
    if (($campanha['periodo_tipo'] ?? '') === 'custom' && $campanha['periodo_inicio']) {
        $periodo_texto = date('d/m/Y', strtotime((string) $campanha['periodo_inicio'])) . ' a ' . date('d/m/Y', strtotime((string) $campanha['periodo_fim']));
    }
    $rotulos_acesso = ['semanal' => 'Semanal', 'mensal' => 'Mensal', 'trimestral' => 'Trimestral', 'semestral' => 'Semestral', 'anual' => 'Anual', 'vitalicio' => 'Vitalício', 'dias' => 'dias'];
}
$ic = static function (string $caminho): string {
    return '<svg class="ic" viewBox="0 0 24 24" aria-hidden="true">' . $caminho . '</svg>';
};
$reais = static fn(float $v): string => 'R$ ' . number_format($v, 2, ',', '.');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Detalhes da Campanha - <?php echo htmlspecialchars(nomeSistema()); ?></title>
    <?php include 'tema_inline.php'; ?>
    <link rel="stylesheet" href="assets/css/painel.css?v=<?php echo @filemtime(__DIR__.'/assets/css/painel.css'); ?>">
    <link rel="stylesheet" href="assets/css/remarketing.css?v=<?php echo @filemtime(__DIR__.'/assets/css/remarketing.css'); ?>">
</head>
<body>
<div class="layout-painel">
    <?php include 'barra_lateral.php'; ?>
    <main class="conteudo-principal">
        <header class="a-ph">
            <div>
                <h1 class="a-h1"><?php echo $campanha ? htmlspecialchars($campanha['nome'] ?: ('Campanha #' . $campanha['id'])) : 'Campanha não encontrada'; ?></h1>
                <p class="a-sub"><a href="remarketing">‹ Voltar para Remarketing</a></p>
            </div>
            <?php if ($campanha): ?>
            <div class="a-acts">
                <?php if ($total > 0 || (int) $campanha['enviados'] > 0): ?>
                    <a class="a-btn" href="remarketing?action=exportar&amp;id=<?php echo (int) $campanha['id']; ?>"><?php echo $ic('<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"></path>'); ?> Baixar envios (CSV)</a>
                <?php endif; ?>
                <?php if (!empty($campanha['id_fluxo']) && remarketingEditavel($campanha)): ?>
                    <a class="a-btn pri" href="remarketing_nova?id=<?php echo (int) $campanha['id']; ?>">Configurar campanha</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </header>

        <?php if (!$campanha): ?>
            <div class="a-empty">Ela pode ter sido removida, ou não pertence à sua conta.</div>
        <?php else: ?>
            <section class="rm-kpis" aria-label="Resumo da campanha">
                <article class="a-card a-kpi"><div class="a-kh"><span class="a-ki"><?php echo $ic('<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle>'); ?></span><span class="a-lab"><?php echo $execucoes > 1 ? 'Destinatários (última execução)' : 'Destinatários'; ?></span></div><div class="a-kv"><?php echo number_format((int) $campanha['total_destinatarios'], 0, ',', '.'); ?></div></article>
                <article class="a-card a-kpi"><div class="a-kh"><span class="a-ki"><?php echo $ic('<path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"></path>'); ?></span><span class="a-lab">Enviadas</span></div><div class="a-kv"><?php echo number_format($entregues_total, 0, ',', '.'); ?></div></article>
                <article class="a-card a-kpi"><div class="a-kh"><span class="a-ki"><?php echo $ic('<circle cx="12" cy="12" r="9"></circle><path d="M5.6 5.6l12.8 12.8"></path>'); ?></span><span class="a-lab">Falhas</span></div><div class="a-kv"><?php echo number_format($falhas_total, 0, ',', '.'); ?></div></article>
                <article class="a-card a-kpi"><div class="a-kh"><span class="a-ki"><?php echo $ic('<path d="M12 2v20M17 6H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>'); ?></span><span class="a-lab">Receita (<?php echo $vendas_pagas; ?> <?php echo $vendas_pagas === 1 ? 'venda' : 'vendas'; ?>)</span></div><div class="a-kv"><?php echo htmlspecialchars($reais($receita_total)); ?></div></article>
            </section>

            <div class="a-card rm-info">
                <dl>
                    <div><dt><?php echo !empty($campanha['id_fluxo']) ? 'Fluxo' : 'Bot'; ?></dt><dd><?php echo htmlspecialchars((string) (!empty($campanha['id_fluxo']) ? ($campanha['nome_fluxo'] ?? 'Fluxo removido') : ($campanha['nome_bot'] ?? 'Bot removido'))); ?></dd></div>
                    <div><dt>Público</dt><dd><?php echo htmlspecialchars(remarketingRotuloSegmento((string) $campanha['audiencia'])); ?></dd></div>
                    <div><dt>Período de entrada</dt><dd><?php echo htmlspecialchars($periodo_texto); ?></dd></div>
                    <div><dt>Situação</dt><dd><span class="a-bd <?php echo $em_envio ? 'ok' : ($classes_status[$campanha['status']] ?? ''); ?>"><?php echo htmlspecialchars($rotulo_status); ?></span></dd></div>
                    <div><dt><?php echo $campanha['status'] === 'rascunho' ? 'Criada em' : ($campanha['status'] === 'pendente' && $execucoes > 0 ? 'Próximo envio' : 'Envio'); ?></dt><dd class="mono"><?php echo $campanha['status'] === 'rascunho' ? htmlspecialchars(remarketingDataCurta((string) $campanha['criado_em'])) : htmlspecialchars(remarketingDataCurta((string) $campanha['agendado_em'])); ?></dd></div>
                    <?php if (!empty($campanha['processado_em'])): ?><div><dt><?php echo $execucoes > 1 ? 'Última execução terminou em' : 'Terminou em'; ?></dt><dd class="mono"><?php echo htmlspecialchars(remarketingDataCurta((string) $campanha['processado_em'])); ?></dd></div><?php endif; ?>
                    <?php if ($recorrencia !== null): ?><div><dt>Repete</dt><dd><?php echo htmlspecialchars(remarketingDescreverRecorrencia($recorrencia)); ?></dd></div><?php endif; ?>
                    <?php if ($execucoes > 1): ?><div><dt>Execuções</dt><dd><?php echo $execucoes; ?></dd></div><?php endif; ?>
                </dl>
            </div>

            <div class="rm-cols">
                <?php foreach ($variantes as $i => $v): $n = $por_variante[$i]; $conv = $n['enviadas'] > 0 ? round($n['pagas'] / $n['enviadas'] * 100, 1) : 0; ?>
                    <article class="a-card rm-msg">
                        <div class="a-ch"><h2><?php echo count($variantes) > 1 ? 'Variante ' . chr(65 + $i) : 'Mensagem'; ?></h2>
                            <?php if ($v['midia_caminho'] !== ''): ?><span class="a-bd"><?php echo $v['midia_tipo'] === 'video' ? 'com vídeo' : 'com foto'; ?></span><?php endif; ?></div>
                        <div class="rm-msg-texto"><?php echo htmlspecialchars($v['mensagem']); ?></div>
                        <div class="rm-nums rm-msg-nums">
                            <div><small>Enviadas</small><b class="ok"><?php echo number_format($n['enviadas'], 0, ',', '.'); ?></b></div>
                            <div><small>Vendas pagas</small><b><?php echo $n['pagas']; ?></b></div>
                            <div><small>Receita</small><b title="<?php echo htmlspecialchars($reais($n['receita'])); ?>"><?php echo htmlspecialchars($reais($n['receita'])); ?></b></div>
                        </div>
                        <?php if (count($variantes) > 1 || $n['pagas'] > 0): ?><div class="rm-meta"><span>Conversão: <?php echo str_replace('.', ',', (string) $conv); ?>%</span><span><?php echo number_format($n['falhas'], 0, ',', '.'); ?> <?php echo $n['falhas'] === 1 ? 'falha' : 'falhas'; ?></span></div><?php endif; ?>
                    </article>
                <?php endforeach; ?>
                <?php if (remarketingTemOferta($ofertas) || $ofertas['order_bump']['ativo']): ?>
                    <article class="a-card rm-msg">
                        <div class="a-ch"><h2>Oferta</h2></div>
                        <ul class="rm-lista-of">
                            <?php foreach ($ofertas['planos'] as $p): ?>
                                <li><b><?php echo htmlspecialchars((string) $p['nome']); ?></b> <span><?php echo htmlspecialchars($reais((float) $p['valor'])); ?> · acesso <?php echo ($p['acesso'] ?? '') === 'dias' ? (int) ($p['dias'] ?? 0) . ' dias' : strtolower($rotulos_acesso[$p['acesso'] ?? 'mensal'] ?? 'mensal'); ?> · <?php echo ($p['entrega_tipo'] ?? '') === 'grupo' ? 'entrega no grupo' : (($p['entrega_tipo'] ?? '') === 'link' ? 'entrega por link' : 'sem entrega'); ?></span></li>
                            <?php endforeach; ?>
                            <?php if ($ofertas['redirect']['ativo']): ?><li><b>Botão "<?php echo htmlspecialchars($ofertas['redirect']['texto']); ?>"</b> <span>leva para <?php echo htmlspecialchars($ofertas['redirect']['url']); ?></span></li><?php endif; ?>
                            <?php if ($ofertas['order_bump']['ativo']): ?><li><b>Order bump: <?php echo htmlspecialchars($ofertas['order_bump']['nome']); ?></b> <span>+ <?php echo htmlspecialchars($reais($ofertas['order_bump']['valor'])); ?> no pedido</span></li><?php endif; ?>
                        </ul>
                    </article>
                <?php endif; ?>
            </div>

            <section class="a-card" aria-label="Registro de envios">
                <div class="a-ch">
                    <h2>Registro de envios</h2>
                    <div class="a-seg">
                        <a href="<?php echo urlDetalhesRemarketing($campanha_id, 'todos', 1); ?>" class="<?php echo $resultado === 'todos' ? 'on' : ''; ?>">Todos</a>
                        <a href="<?php echo urlDetalhesRemarketing($campanha_id, 'sucesso', 1); ?>" class="<?php echo $resultado === 'sucesso' ? 'on' : ''; ?>">Sucesso</a>
                        <a href="<?php echo urlDetalhesRemarketing($campanha_id, 'falha', 1); ?>" class="<?php echo $resultado === 'falha' ? 'on' : ''; ?>">Falha</a>
                    </div>
                </div>
                <div class="a-tw">
                    <table class="a-t">
                        <thead><tr><th>ID Telegram</th><th>Resultado</th><?php if ($tem_variante && count($variantes) > 1): ?><th>Variante</th><?php endif; ?><th>Resposta</th><th>Enviado em</th></tr></thead>
                        <tbody>
                        <?php if (empty($envios)): ?>
                            <tr><td colspan="5" class="a-vazio-linha">Nenhum envio com esse filtro.</td></tr>
                        <?php else: foreach ($envios as $e): ?>
                            <tr>
                                <td class="mono"><?php echo htmlspecialchars($e['id_telegram']); ?></td>
                                <td><span class="a-bd <?php echo $e['resultado'] === 'sucesso' ? 'ok' : 'er'; ?>"><?php echo $e['resultado'] === 'sucesso' ? 'sucesso' : 'falha'; ?></span></td>
                                <?php if ($tem_variante && count($variantes) > 1): ?><td><?php echo chr(65 + (int) ($e['variante'] ?? 0)); ?></td><?php endif; ?>
                                <td class="a-mut mono" title="<?php echo htmlspecialchars((string) $e['resposta']); ?>"><?php echo htmlspecialchars(mb_strimwidth((string) $e['resposta'], 0, 60, '...')); ?></td>
                                <td class="mono"><?php echo date('d/m/Y H:i', strtotime($e['enviado_em'])); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php echo paginador($total, $por_pagina); ?>
            </section>
        <?php endif; ?>
    </main>
</div>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="assets/js/tema.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/tema.js'); ?>"></script>
</body>
</html>
