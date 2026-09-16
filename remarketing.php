<?php
declare(strict_types=1);

require_once __DIR__ . '/funcoes/usuario.php';
verificarLogin();
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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/app.css">
    <style>
        .filtros { display: flex; gap: 10px; margin-bottom: 12px; align-items: flex-end; }
        .form-group { margin-bottom: 0; }
        .textarea { width: 100%; min-height: 120px; padding: 10px; border: 1px solid #e5e7eb; border-radius: 6px; font-family: inherit; }
        .resultado { margin-top: 15px; color: #111827; }
        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #e5e7eb; }
        th { font-weight: 600; color: #374151; background-color: #f9fafb; }
        .badge { padding: 4px 8px; border-radius: 6px; font-size: 12px; font-weight: 600; }
        .badge-amber { background-color: #fff7ed; color: #c2410c; }
        .badge-blue { background-color: #eff6ff; color: #1d4ed8; }
        .badge-green { background-color: #ecfdf5; color: #047857; }
        .badge-gray { background-color: #f1f5f9; color: #6b7280; }
    </style>
</head>
<body>
<div class="dashboard-layout">
    <?php include 'sidebar.php'; ?>
    <main class="main-content">
        <div class="cabecalho-pagina">
            <div>
                <h1>Remarketing</h1>
                <p>Crie campanhas para recuperar quem não comprou ou engajar quem comprou.</p>
            </div>
        </div>
        <div class="painel">
            <div class="painel-cabecalho" style="justify-content: space-between; align-items: center;">
                <h2 style="margin:0">Campanhas de Remarketing</h2>
                <button class="botao botao-primario" id="btn-nova-campanha">Nova Campanha</button>
            </div>
            <form class="filtros" method="GET">
                <?php
                    $f_bot = isset($_GET['bot_id']) ? (int)$_GET['bot_id'] : 0;
                    $f_aud = $_GET['audiencia'] ?? '';
                    $f_status = $_GET['status'] ?? '';
                    $limite = max(5, min(50, (int)($_GET['limite'] ?? 10)));
                ?>
                <div class="form-group">
                    <label for="bot_id">Bot</label>
                    <select name="bot_id" id="bot_id" class="input-campo">
                        <option value="">Todos</option>
                        <?php foreach ($meus_bots as $b): ?>
                            <option value="<?php echo $b['id']; ?>" <?php echo $f_bot == $b['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($b['nome']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="audiencia">Audiência</label>
                    <select name="audiencia" id="audiencia" class="input-campo">
                        <option value="">Todas</option>
                        <option value="nao_comprou" <?php echo $f_aud === 'nao_comprou' ? 'selected' : ''; ?>>Não comprou</option>
                        <option value="comprou" <?php echo $f_aud === 'comprou' ? 'selected' : ''; ?>>Comprou</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="status">Status</label>
                    <select name="status" id="status" class="input-campo">
                        <option value="">Todos</option>
                        <option value="pendente" <?php echo $f_status === 'pendente' ? 'selected' : ''; ?>>Pendente</option>
                        <option value="processando" <?php echo $f_status === 'processando' ? 'selected' : ''; ?>>Processando</option>
                        <option value="concluida" <?php echo $f_status === 'concluida' ? 'selected' : ''; ?>>Concluída</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="limite">Itens por página</label>
                    <select name="limite" id="limite" class="input-campo">
                        <?php foreach ([10,20,30,50] as $opt): ?>
                            <option value="<?php echo $opt; ?>" <?php echo $limite === $opt ? 'selected' : ''; ?>><?php echo $opt; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="botao botao-primario">Filtrar</button>
            </form>
            <?php
            $pagina = max(1, (int)($_GET['pagina'] ?? 1));
            $offset = ($pagina - 1) * $limite;
            $where = "c.id_usuario = :uid";
            $params = ['uid' => $id_usuario];
            if ($f_bot > 0) { $where .= " AND c.bot_id = :bot"; $params['bot'] = $f_bot; }
            if ($f_aud === 'nao_comprou' || $f_aud === 'comprou') { $where .= " AND c.audiencia = :aud"; $params['aud'] = $f_aud; }
            if (in_array($f_status, ['pendente','processando','concluida'], true)) { $where .= " AND c.status = :status"; $params['status'] = $f_status; }
            $stmt_total = $pdo->prepare("SELECT COUNT(*) FROM remarketing_campanhas c WHERE $where");
            $stmt_total->execute($params);
            $total_reg = (int)$stmt_total->fetchColumn();
            $total_paginas = max(1, (int)ceil($total_reg / $limite));
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
            <div class="table-responsive">
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
                                        <div style="margin-bottom:10px; font-weight:600; color:#111827;">Nenhuma campanha criada</div>
                                        <div style="margin-bottom:12px;">Crie sua primeira campanha de remarketing para engajar sua audiência.</div>
                                    </div>
                                </td>
                            </tr>
                        <?php else: foreach ($campanhas as $c): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($c['nome_bot']); ?></td>
                                <td>
                                    <span class="badge"><?php echo $c['audiencia'] === 'nao_comprou' ? 'Não comprou' : 'Comprou'; ?></span>
                                </td>
                                <td title="<?php echo htmlspecialchars($c['mensagem']); ?>">
                                    <?php echo htmlspecialchars(mb_strimwidth($c['mensagem'], 0, 60, '...')); ?>
                                </td>
                                <td><?php echo date('d/m/Y H:i', strtotime($c['agendado_em'])); ?></td>
                                <td>
                                    <?php 
                                        $status = $c['status'];
                                        $cor = '#f1f5f9'; $texto = '#6b7280';
                                        if ($status === 'pendente') { $cor = '#fff7ed'; $texto = '#c2410c'; }
                                        if ($status === 'processando') { $cor = '#eff6ff'; $texto = '#1d4ed8'; }
                                        if ($status === 'concluida') { $cor = '#ecfdf5'; $texto = '#047857'; }
                                    ?>
                                    <span class="badge" style="background: <?php echo $cor; ?>; color: <?php echo $texto; ?>;"><?php echo htmlspecialchars($status); ?></span>
                                </td>
                                <td><?php echo (int)$c['total_destinatarios']; ?></td>
                                <td><?php echo (int)$c['entregues']; ?></td>
                                <td><?php echo (int)$c['falhas']; ?></td>
                                <td><?php echo $c['processado_em'] ? date('d/m/Y H:i', strtotime($c['processado_em'])) : '-'; ?></td>
                                <td>
                                    <a class="botao botao-claro" href="?detalhes=<?php echo (int)$c['id']; ?>">Ver</a>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="paginacao" style="margin-top:12px; display:flex;justify-content: center; gap:8px; align-items:center;">
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
                <a class="paginacao-botao" href="<?php echo $base . $prev; ?>">&laquo;</a>
                <span class="paginacao-info">Página <?php echo $pagina; ?> de <?php echo $total_paginas; ?> (<?php echo $total_reg; ?> campanhas)</span>
                <a class="paginacao-botao" href="<?php echo $base . $next; ?>">&raquo;</a>
            </div>
            
            <?php
            if (isset($_GET['detalhes'])) {
                $det_id = (int)$_GET['detalhes'];
                $stmt_det = $pdo->prepare("SELECT e.* FROM remarketing_envios e WHERE e.campanha_id = ? ORDER BY e.enviado_em DESC");
                $stmt_det->execute([$det_id]);
                $envios = $stmt_det->fetchAll(PDO::FETCH_ASSOC);
                echo '<h3 style="margin-top:20px;">Detalhes da Campanha #' . $det_id . '</h3>';
                echo '<div class="table-responsive"><table><thead><tr><th>ID Telegram</th><th>Resultado</th><th>Enviado Em</th></tr></thead><tbody>';
                if (empty($envios)) {
                    echo '<tr><td colspan="3" style="text-align:center; padding: 10px; color:#6b7280;">Sem envios registrados.</td></tr>';
                } else {
                    foreach ($envios as $e) {
                        echo '<tr><td>' . htmlspecialchars($e['id_telegram']) . '</td><td>' . htmlspecialchars($e['resultado']) . '</td><td>' . date('d/m/Y H:i', strtotime($e['enviado_em'])) . '</td></tr>';
                    }
                }
                echo '</tbody></table></div>';
            }
            ?>
        </div>
        <div id="modal-nova-campanha" style="display:none; position: fixed; inset: 0; background: rgba(0,0,0,0.35); align-items: center; justify-content: center; z-index: 9999;">
            <div class="painel" style="max-width: 720px; width: 95%;">
                <div class="painel-cabecalho" style="justify-content: space-between;">
                    <h2 style="margin:0">Nova Campanha</h2>
                    <button type="button" class="botao botao-claro" id="btn-fechar-modal">Fechar</button>
                </div>
                <form id="form-campanha">
                    <div class="grade grade-4 grade-compacta">
                        <div class="campo">
                            <label for="modal-bot_id">Bot</label>
                            <select id="modal-bot_id" name="bot_id" class="input-campo" required>
                                <option value="">Selecione um Bot</option>
                                <?php foreach ($meus_bots as $b): ?>
                                    <option value="<?php echo $b['id']; ?>"><?php echo htmlspecialchars($b['nome']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="campo">
                            <label for="modal-audiencia">Audiência</label>
                            <select id="modal-audiencia" name="audiencia" class="input-campo" required>
                                <option value="nao_comprou">Acessou e não comprou</option>
                                <option value="comprou">Acessou e comprou</option>
                            </select>
                        </div>
                        <div class="campo">
                            <label>Agendar envio</label>
                            <div class="input-grupo">
                                <input type="date" id="modal-data" class="input-campo" placeholder="dd/mm/aaaa">
                                <input type="time" id="modal-hora" class="input-campo" placeholder="--:--">
                            </div>
                            <input type="hidden" id="modal-agendado_em" name="agendado_em">
                            <div class="texto-ajuda">Se vazio, envia imediatamente.</div>
                        <br>
                        </div>
                    </div>
                    <div class="campo">
                        <div class="texto-ajuda" id="contador-destinatarios" style="margin-bottom:8px; display:none;"></div>
                    </div>
                    <div class="campo">
                        <label for="modal-mensagem">Mensagem</label>
                        <textarea id="modal-mensagem" name="mensagem" class="textarea" placeholder="Digite a mensagem da campanha..." required></textarea>
                        <div class="texto-ajuda"><span id="contador-mensagem">0</span>/4096 caracteres</div>
                    </div>
                    <div class="linha-acoes">
                        <button type="submit" class="botao botao-primario">Salvar/Enviar</button>
                        <button type="button" class="botao botao-claro" id="btn-cancelar-campanha">Cancelar</button>
                    </div>
                </form>
                <div id="modal-feedback" class="resultado" style="display:none;"></div>
            </div>
        </div>
        <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
        <script src="assets/remarketing.js"></script>
    </main>
</div>
</body>
</html>
