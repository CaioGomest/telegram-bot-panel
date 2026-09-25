<?php
declare(strict_types=1);

require_once __DIR__ . '/funcoes/usuario.php';
bloquearAdmin();
$id_usuario = $_SESSION['usuario_id'];
$stmt_bots = $pdo->prepare("SELECT id, COALESCE(primeiro_nome, nome_usuario) as nome FROM bots WHERE id_usuario = ?");
$stmt_bots->execute([$id_usuario]);
$meus_bots = $stmt_bots->fetchAll(PDO::FETCH_ASSOC);

function processarCampanha(array $input, PDO $pdo, int $id_usuario): array {
    $bot_id = isset($input['bot_id']) ? (int)$input['bot_id'] : 0;
    $audiencia = isset($input['audiencia']) ? $input['audiencia'] : '';
    $mensagem = isset($input['mensagem']) ? trim((string)$input['mensagem']) : '';
    $agendado_str = isset($input['agendado_em']) ? trim((string)$input['agendado_em']) : '';
    if (!in_array($audiencia, ['nao_comprou', 'comprou'], true)) {
        return ['sucesso' => false, 'mensagem' => 'Audiência inválida.'];
    }
    if ($bot_id <= 0 || $mensagem === '') {
        return ['sucesso' => false, 'mensagem' => 'Informe bot e mensagem.'];
    }
    // Sempre agenda via cron — nunca envia inline para não travar a requisição
    $agendado_em = $agendado_str !== '' ? date('Y-m-d H:i:s', strtotime($agendado_str)) : date('Y-m-d H:i:s');
    $pdo->prepare("INSERT INTO remarketing_campanhas (id_usuario, bot_id, audiencia, mensagem, agendado_em, status, criado_em) VALUES (?, ?, ?, ?, ?, 'pendente', NOW())")
        ->execute([$id_usuario, $bot_id, $audiencia, $mensagem, $agendado_em]);
    $campanha_id = (int)$pdo->lastInsertId();

    if ($agendado_str !== '') {
        return ['sucesso' => true, 'mensagem' => 'Campanha agendada para ' . date('d/m/Y H:i', strtotime($agendado_em)) . '.', 'campanha_id' => $campanha_id];
    }
    return ['sucesso' => true, 'mensagem' => 'Campanha criada! O envio será processado em instantes pelo cron.', 'campanha_id' => $campanha_id];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false);
    if ($is_ajax) {
        header('Content-Type: application/json; charset=utf-8');
        verificarCsrf();
        $resp = processarCampanha($_POST, $pdo, $id_usuario);
        echo json_encode($resp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (($_GET['action'] ?? '') === 'contar_destinatarios') {
    header('Content-Type: application/json; charset=utf-8');
    $bot_id = isset($_GET['bot_id']) ? (int)$_GET['bot_id'] : 0;
    $audiencia = $_GET['audiencia'] ?? '';
    if ($bot_id <= 0 || !in_array($audiencia, ['nao_comprou', 'comprou'], true)) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Parâmetros inválidos.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    $chk = $pdo->prepare("SELECT 1 FROM bots WHERE id = ? AND id_usuario = ?");
    $chk->execute([$bot_id, $id_usuario]);
    if (!$chk->fetchColumn()) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Bot não encontrado.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    $sql_dest = "SELECT COUNT(*) FROM leads l WHERE l.bot_id = :bot_id";
    if ($audiencia === 'nao_comprou') {
        $sql_dest .= " AND NOT EXISTS (SELECT 1 FROM vendas v WHERE v.id_telegram = l.id_telegram AND v.bot_id = l.bot_id AND v.status = 'pago')";
    } else {
        $sql_dest .= " AND EXISTS (SELECT 1 FROM vendas v WHERE v.id_telegram = l.id_telegram AND v.bot_id = l.bot_id AND v.status = 'pago')";
    }
    $stmt_dest = $pdo->prepare($sql_dest);
    $stmt_dest->execute(['bot_id' => $bot_id]);
    $total = (int)$stmt_dest->fetchColumn();
    echo json_encode(['sucesso' => true, 'total' => $total], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Remarketing - Gerenciamento de Bots</title>
    <?php include 'tema_inline.php'; ?>
    <link rel="stylesheet" href="assets/css/coyote.css?v=<?php echo @filemtime(__DIR__.'/assets/css/coyote.css'); ?>">
</head>
<body>
<div class="layout-painel">
    <?php include 'barra_lateral.php'; ?>
    <main class="conteudo-principal">
        <div class="cabecalho-pagina">
            <div>
                <h1>Remarketing</h1>
                <p>Crie campanhas para recuperar quem não comprou ou engajar quem comprou.</p>
            </div>
            <div class="acoes-cabecalho">
                <button class="botao botao-primario" id="btn-nova-campanha">+ Nova Campanha</button>
                <button type="button" class="alternador-tema" onclick="alternarTema()" aria-label="Alternar tema">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"></path></svg>
                    Tema
                </button>
            </div>
        </div>
        <div class="painel">
            <div class="barra-filtros">
                <form method="GET" style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;flex:1;">
                <?php
                    $f_bot = isset($_GET['bot_id']) ? (int)$_GET['bot_id'] : 0;
                    $f_aud = $_GET['audiencia'] ?? '';
                    $f_status = $_GET['status'] ?? '';
                    $limite = max(5, min(50, (int)($_GET['limite'] ?? 10)));
                ?>
                    <select name="bot_id" id="bot_id">
                        <option value="">Todos os bots</option>
                        <?php foreach ($meus_bots as $b): ?>
                            <option value="<?php echo $b['id']; ?>" <?php echo $f_bot == $b['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($b['nome']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="audiencia" id="audiencia">
                        <option value="">Todas as audiências</option>
                        <option value="nao_comprou" <?php echo $f_aud === 'nao_comprou' ? 'selected' : ''; ?>>Não comprou</option>
                        <option value="comprou" <?php echo $f_aud === 'comprou' ? 'selected' : ''; ?>>Comprou</option>
                    </select>
                    <select name="status" id="status">
                        <option value="">Todos os status</option>
                        <option value="pendente" <?php echo $f_status === 'pendente' ? 'selected' : ''; ?>>Pendente</option>
                        <option value="processando" <?php echo $f_status === 'processando' ? 'selected' : ''; ?>>Processando</option>
                        <option value="concluida" <?php echo $f_status === 'concluida' ? 'selected' : ''; ?>>Concluída</option>
                    </select>
                    <select name="limite" id="limite">
                        <?php foreach ([10,20,30,50] as $opt): ?>
                            <option value="<?php echo $opt; ?>" <?php echo $limite === $opt ? 'selected' : ''; ?>><?php echo $opt; ?> por página</option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="botao botao-primario">Filtrar</button>
                </form>
            </div>
            <?php
            $pagina = max(1, (int)($_GET['pagina'] ?? 1));
            $where = "c.id_usuario = :uid";
            $params = ['uid' => $id_usuario];
            if ($f_bot > 0) { $where .= " AND c.bot_id = :bot"; $params['bot'] = $f_bot; }
            if ($f_aud === 'nao_comprou' || $f_aud === 'comprou') { $where .= " AND c.audiencia = :aud"; $params['aud'] = $f_aud; }
            if (in_array($f_status, ['pendente','processando','concluida'], true)) { $where .= " AND c.status = :status"; $params['status'] = $f_status; }
            $stmt_total = $pdo->prepare("SELECT COUNT(*) FROM remarketing_campanhas c WHERE $where");
            $stmt_total->execute($params);
            $total_reg = (int)$stmt_total->fetchColumn();
            $total_paginas = max(1, (int)ceil($total_reg / $limite));
            $pagina = min($pagina, $total_paginas);
            $offset = ($pagina - 1) * $limite;
            ?>
            <?php
            $stmt_list = $pdo->prepare("SELECT c.*, COALESCE(b.primeiro_nome, b.nome_usuario) as nome_bot FROM remarketing_campanhas c JOIN bots b ON c.bot_id = b.id WHERE $where ORDER BY c.criado_em DESC LIMIT :lim OFFSET :off");
            foreach ($params as $k => $v) {
                $stmt_list->bindValue(':' . $k, $v);
            }
            $stmt_list->bindValue(':lim', $limite, PDO::PARAM_INT);
            $stmt_list->bindValue(':off', $offset, PDO::PARAM_INT);
            $stmt_list->execute();
            $campanhas = $stmt_list->fetchAll(PDO::FETCH_ASSOC);
            ?>
            <div class="tabela-dados">
                <table>
                    <thead>
                        <tr>
                            <th>Bot</th>
                            <th>Aud.</th>
                            <th>Mensagem</th>
                            <th>Agendado</th>
                            <th>Status</th>
                            <th>Dest.</th>
                            <th>Sucesso</th>
                            <th>Falhas</th>
                            <th>Processado</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($campanhas)): ?>
                            <tr>
                                <td colspan="10">
                                    <div class="estado-vazio">
                                        <div style="margin-bottom:10px; font-weight:600;">Nenhuma campanha criada</div>
                                        <div>Crie sua primeira campanha de remarketing para engajar sua audiência.</div>
                                    </div>
                                </td>
                            </tr>
                        <?php else: foreach ($campanhas as $c): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($c['nome_bot']); ?></td>
                                <td>
                                    <span class="badge badge-neutro"><?php echo $c['audiencia'] === 'nao_comprou' ? 'Não comprou' : 'Comprou'; ?></span>
                                </td>
                                <td title="<?php echo htmlspecialchars($c['mensagem']); ?>" class="texto-suave">
                                    <?php echo htmlspecialchars(mb_strimwidth($c['mensagem'], 0, 60, '...')); ?>
                                </td>
                                <td class="mono"><?php echo date('d/m/Y H:i', strtotime($c['agendado_em'])); ?></td>
                                <td>
                                    <?php
                                        $status = $c['status'];
                                        $classe_status = 'badge-neutro';
                                        if ($status === 'pendente') { $classe_status = 'badge-alerta'; }
                                        if ($status === 'processando') { $classe_status = 'badge-neutro'; }
                                        if ($status === 'concluida') { $classe_status = 'badge-sucesso'; }
                                    ?>
                                    <span class="badge <?php echo $classe_status; ?>"><?php echo htmlspecialchars($status); ?></span>
                                </td>
                                <td><?php echo (int)$c['total_destinatarios']; ?></td>
                                <td><?php echo (int)$c['entregues']; ?></td>
                                <td><?php echo (int)$c['falhas']; ?></td>
                                <td class="texto-suave"><?php echo $c['processado_em'] ? date('d/m/Y H:i', strtotime($c['processado_em'])) : '-'; ?></td>
                                <td>
                                    <a class="botao" href="?detalhes=<?php echo (int)$c['id']; ?>">Ver</a>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
            <div style="margin-top:14px; display:flex;justify-content: center; gap:8px; align-items:center;">
                <?php
                $qs = [
                    'bot_id' => $f_bot ?: null,
                    'audiencia' => $f_aud ?: null,
                    'status' => $f_status ?: null,
                    'limite' => $limite
                ];
                $qs = array_filter($qs, function($v){ return $v !== null && $v !== ''; });
                $base = 'remarketing.php?' . http_build_query($qs) . '&pagina=';
                $prev = max(1, $pagina - 1);
                $next = min($total_paginas, $pagina + 1);
                ?>
                <a class="botao" href="<?php echo $base . $prev; ?>">&laquo;</a>
                <span class="texto-suave">Página <?php echo $pagina; ?> de <?php echo $total_paginas; ?> (<?php echo $total_reg; ?> campanhas)</span>
                <a class="botao" href="<?php echo $base . $next; ?>">&raquo;</a>
            </div>

            <?php
            if (isset($_GET['detalhes'])) {
                $det_id = (int)$_GET['detalhes'];
                $stmt_det = $pdo->prepare("SELECT e.* FROM remarketing_envios e WHERE e.campanha_id = ? ORDER BY e.enviado_em DESC");
                $stmt_det->execute([$det_id]);
                $envios = $stmt_det->fetchAll(PDO::FETCH_ASSOC);
                echo '<h2 style="margin:20px 0 0;font-size:18px;">Detalhes da Campanha #' . $det_id . '</h2>';
                echo '<div class="tabela-dados" style="margin-top:12px;"><table><thead><tr><th>ID Telegram</th><th>Resultado</th><th>Enviado Em</th></tr></thead><tbody>';
                if (empty($envios)) {
                    echo '<tr><td colspan="3" style="text-align:center; padding: 10px;" class="texto-suave">Sem envios registrados.</td></tr>';
                } else {
                    foreach ($envios as $e) {
                        echo '<tr><td>' . htmlspecialchars($e['id_telegram']) . '</td><td>' . htmlspecialchars($e['resultado']) . '</td><td class="mono">' . date('d/m/Y H:i', strtotime($e['enviado_em'])) . '</td></tr>';
                    }
                }
                echo '</tbody></table></div>';
            }
            ?>
        </div>
        <div id="modal-nova-campanha" class="sobreposicao-modal">
            <div class="modal-gateway" style="max-width: 720px;">
                <div class="cabecalho-modal">
                    <div class="titulo-modal">Nova Campanha</div>
                    <button type="button" class="fechar-modal" id="btn-fechar-modal">✕</button>
                </div>
                <div class="corpo-modal">
                    <form id="form-campanha">
                        <?php echo campoCsrf(); ?>
                        <div class="grade grade-2 grade-compacta">
                            <div class="campo">
                                <label for="modal-bot_id">Bot</label>
                                <select id="modal-bot_id" name="bot_id" required>
                                    <option value="">Selecione um Bot</option>
                                    <?php foreach ($meus_bots as $b): ?>
                                        <option value="<?php echo $b['id']; ?>"><?php echo htmlspecialchars($b['nome']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="campo">
                                <label for="modal-audiencia">Audiência</label>
                                <select id="modal-audiencia" name="audiencia" required>
                                    <option value="nao_comprou">Acessou e não comprou</option>
                                    <option value="comprou">Acessou e comprou</option>
                                </select>
                            </div>
                        </div>
                        <div class="campo" style="margin-top:12px;">
                            <label>Agendar envio</label>
                            <div style="display:flex;gap:8px;">
                                <input type="date" id="modal-data" placeholder="dd/mm/aaaa">
                                <input type="time" id="modal-hora" placeholder="--:--">
                            </div>
                            <input type="hidden" id="modal-agendado_em" name="agendado_em">
                            <small>Se vazio, envia imediatamente.</small>
                        </div>
                        <div class="campo" style="margin-top:12px;">
                            <small id="contador-destinatarios" style="display:none;"></small>
                        </div>
                        <div class="campo" style="margin-top:12px;">
                            <label for="modal-mensagem">Mensagem</label>
                            <textarea id="modal-mensagem" name="mensagem" rows="5" placeholder="Digite a mensagem da campanha..." required></textarea>
                            <small><span id="contador-mensagem">0</span>/4096 caracteres</small>
                        </div>
                        <div class="linha-acoes" style="margin-top:16px;">
                            <button type="submit" class="botao botao-primario">Salvar/Enviar</button>
                            <button type="button" class="botao" id="btn-cancelar-campanha">Cancelar</button>
                        </div>
                    </form>
                    <div id="modal-feedback" class="texto-suave" style="display:none;margin-top:12px;"></div>
                </div>
            </div>
        </div>
        <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
        <script src="assets/js/tema.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/tema.js'); ?>"></script>
        <script src="assets/remarketing.js?v=<?php echo @filemtime(__DIR__ . '/assets/remarketing.js'); ?>"></script>
    </main>
</div>
</body>
</html>
