<?php
declare(strict_types=1);

require_once __DIR__ . '/funcoes/usuario.php';
bloquearAdmin();
$id_usuario = $_SESSION['usuario_id'];

$campanha_id = (int) ($_GET['campanha'] ?? 0);

// A query antiga (antes desta virar página própria) nunca conferia se a campanha era do
// usuário logado -- qualquer conta autenticada conseguia ver o log de envio (IDs de
// Telegram reais) de campanha de outra conta só trocando o número na URL. Corrigido junto
// com a separação de página.
$stmt_campanha = $pdo->prepare("
    SELECT c.*, COALESCE(b.primeiro_nome, b.nome_usuario) AS nome_bot
    FROM remarketing_campanhas c
    JOIN bots b ON c.bot_id = b.id
    WHERE c.id = ? AND c.id_usuario = ?
");
$stmt_campanha->execute([$campanha_id, $id_usuario]);
$campanha = $stmt_campanha->fetch(PDO::FETCH_ASSOC);

if ($campanha) {
    $resultado = in_array($_GET['resultado'] ?? '', ['sucesso', 'falha', 'todos'], true) ? $_GET['resultado'] : 'falha';
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

    $rotulos_status = ['pendente' => 'Pendente', 'processando' => 'Processando', 'concluida' => 'Concluída', 'falha' => 'Falha'];
    $classes_status = ['pendente' => 'badge-alerta', 'processando' => 'badge-alerta', 'concluida' => 'badge-sucesso', 'falha' => 'badge-perigo'];
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detalhes da Campanha - <?php echo htmlspecialchars(nomeSistema()); ?></title>
    <?php include 'tema_inline.php'; ?>
    <link rel="stylesheet" href="assets/css/coyote.css?v=<?php echo @filemtime(__DIR__.'/assets/css/coyote.css'); ?>">
</head>
<body>
<div class="layout-painel">
    <?php include 'barra_lateral.php'; ?>
    <main class="conteudo-principal">
        <div class="cabecalho-pagina">
            <div>
                <h1><?php echo $campanha ? htmlspecialchars($campanha['nome'] ?: ('Campanha #' . $campanha['id'])) : 'Campanha não encontrada'; ?></h1>
                <p><a href="remarketing">‹ Voltar para Remarketing</a></p>
            </div>
            <div class="acoes-cabecalho">
                <button type="button" class="alternador-tema" onclick="alternarTema()" aria-label="Alternar tema">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"></path></svg>
                    Tema
                </button>
            </div>
        </div>

        <?php if (!$campanha): ?>
            <div class="painel">
                <div class="estado-vazio">
                    <div style="margin-bottom:10px; font-weight:600;">Campanha não encontrada</div>
                    <div>Ela pode ter sido removida, ou não pertence à sua conta.</div>
                </div>
            </div>
        <?php else: ?>
            <div class="painel">
                <div class="grade grade-2 grade-compacta" style="margin-bottom:16px;">
                    <div class="campo"><label>Bot</label><div><?php echo htmlspecialchars($campanha['nome_bot']); ?></div></div>
                    <div class="campo"><label>Audiência</label><div><span class="badge badge-neutro"><?php echo $campanha['audiencia'] === 'nao_comprou' ? 'Não comprou' : 'Comprou'; ?></span></div></div>
                    <div class="campo"><label>Status</label><div><span class="badge <?php echo $classes_status[$campanha['status']] ?? 'badge-neutro'; ?>"><?php echo htmlspecialchars($rotulos_status[$campanha['status']] ?? $campanha['status']); ?></span></div></div>
                    <div class="campo"><label>Agendado</label><div class="mono"><?php echo date('d/m/Y H:i', strtotime($campanha['agendado_em'])); ?></div></div>
                    <div class="campo"><label>Destinatários</label><div><?php echo (int) $campanha['total_destinatarios']; ?></div></div>
                    <div class="campo"><label>Sucesso / Falhas</label><div><?php echo (int) $campanha['entregues']; ?> / <?php echo (int) $campanha['falhas']; ?></div></div>
                </div>
                <div class="campo">
                    <label>Mensagem</label>
                    <div class="texto-suave" style="white-space:pre-wrap;"><?php echo htmlspecialchars($campanha['mensagem']); ?></div>
                </div>

                <div class="seletor-periodo" style="margin-top:20px;">
                    <a href="<?php echo urlDetalhesRemarketing($campanha_id, 'todos', 1); ?>" class="periodo-item<?php echo $resultado === 'todos' ? ' ativo' : ''; ?>">Todos</a>
                    <a href="<?php echo urlDetalhesRemarketing($campanha_id, 'sucesso', 1); ?>" class="periodo-item<?php echo $resultado === 'sucesso' ? ' ativo' : ''; ?>">Sucesso</a>
                    <a href="<?php echo urlDetalhesRemarketing($campanha_id, 'falha', 1); ?>" class="periodo-item<?php echo $resultado === 'falha' ? ' ativo' : ''; ?>">Falha</a>
                </div>
                <div class="tabela-dados" style="margin-top:12px;">
                    <table>
                        <thead><tr><th>ID Telegram</th><th>Resultado</th><th>Resposta</th><th>Enviado Em</th></tr></thead>
                        <tbody>
                        <?php if (empty($envios)): ?>
                            <tr><td colspan="4" style="text-align:center;padding:10px;" class="texto-suave">Nenhum envio com esse filtro.</td></tr>
                        <?php else: foreach ($envios as $e): ?>
                            <tr>
                                <td class="mono"><?php echo htmlspecialchars($e['id_telegram']); ?></td>
                                <td><span class="badge <?php echo $e['resultado'] === 'sucesso' ? 'badge-sucesso' : 'badge-perigo'; ?>"><?php echo htmlspecialchars($e['resultado']); ?></span></td>
                                <td class="texto-suave mono" title="<?php echo htmlspecialchars((string) $e['resposta']); ?>"><?php echo htmlspecialchars(mb_strimwidth((string) $e['resposta'], 0, 60, '...')); ?></td>
                                <td class="mono"><?php echo date('d/m/Y H:i', strtotime($e['enviado_em'])); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($total > 0): ?>
                <div style="margin-top:14px; display:flex;justify-content:center; gap:8px; align-items:center;">
                    <a class="btn-icon" href="<?php echo urlDetalhesRemarketing($campanha_id, $resultado, max(1, $pagina - 1)); ?>" aria-label="Página anterior">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"></path></svg>
                    </a>
                    <span class="texto-suave">Página <?php echo $pagina; ?> de <?php echo $total_paginas; ?> · <?php echo $total; ?> envio<?php echo $total === 1 ? '' : 's'; ?></span>
                    <a class="btn-icon" href="<?php echo urlDetalhesRemarketing($campanha_id, $resultado, min($total_paginas, $pagina + 1)); ?>" aria-label="Próxima página">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"></path></svg>
                    </a>
                </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </main>
</div>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="assets/js/tema.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/tema.js'); ?>"></script>
</body>
</html>
