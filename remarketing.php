<?php
declare(strict_types=1);

require_once __DIR__ . '/funcoes/usuario.php';
require_once __DIR__ . '/funcoes/paginador.php';
bloquearAdmin();
$id_usuario = $_SESSION['usuario_id'];
$stmt_bots = $pdo->prepare("SELECT id, COALESCE(primeiro_nome, nome_usuario) as nome FROM bots WHERE id_usuario = ?");
$stmt_bots->execute([$id_usuario]);
$meus_bots = $stmt_bots->fetchAll(PDO::FETCH_ASSOC);

/**
 * Confere que o caminho vindo do cliente (já devolvido pelo próprio endpoint de upload,
 * mas nunca é bom confiar cegamente num campo de formulário) fica de fato dentro de
 * uploads/ -- mesma proteção de webhook.php::resolverCaminhoUploadSeguro(), copiada
 * localmente porque remarketing.php não inclui webhook.php.
 */
function resolverCaminhoUploadSeguroRemarketing(string $caminho): ?string
{
    if ($caminho === '' || strpos($caminho, 'uploads/') !== 0) {
        return null;
    }
    $base_real = realpath(__DIR__ . '/uploads');
    if ($base_real === false) {
        return null;
    }
    $real = realpath(__DIR__ . '/' . $caminho);
    if ($real === false) {
        return null;
    }
    if ($real !== $base_real && strpos($real, $base_real . DIRECTORY_SEPARATOR) !== 0) {
        return null;
    }
    return $real;
}

function processarCampanha(array $input, PDO $pdo, int $id_usuario): array {
    $bot_id = isset($input['bot_id']) ? (int)$input['bot_id'] : 0;
    $nome = isset($input['nome']) ? mb_substr(trim((string)$input['nome']), 0, 100) : '';
    $audiencia = isset($input['audiencia']) ? $input['audiencia'] : '';
    $mensagem = isset($input['mensagem']) ? trim((string)$input['mensagem']) : '';
    $agendado_str = isset($input['agendado_em']) ? trim((string)$input['agendado_em']) : '';
    $midia_caminho = isset($input['midia_caminho']) ? trim((string)$input['midia_caminho']) : '';
    $midia_tipo = isset($input['midia_tipo']) ? trim((string)$input['midia_tipo']) : '';

    if (!in_array($audiencia, ['nao_comprou', 'comprou'], true)) {
        return ['sucesso' => false, 'mensagem' => 'Audiência inválida.'];
    }
    if ($bot_id <= 0 || $mensagem === '') {
        return ['sucesso' => false, 'mensagem' => 'Informe bot e mensagem.'];
    }
    if ($agendado_str === '' || strtotime($agendado_str) === false) {
        return ['sucesso' => false, 'mensagem' => 'Informe a data e a hora do envio.'];
    }

    if ($midia_caminho !== '') {
        if (!in_array($midia_tipo, ['foto', 'video'], true) || !resolverCaminhoUploadSeguroRemarketing($midia_caminho)) {
            return ['sucesso' => false, 'mensagem' => 'Mídia inválida. Envie o arquivo de novo.'];
        }
        // Legenda de foto/vídeo no Telegram tem teto de 1024 caracteres -- bem menor que
        // os 4096 de uma mensagem solta. Sem essa checagem, campanha grande falharia por
        // destinatário lá no cron, sem aviso nenhum aqui na hora de criar.
        if (mb_strlen($mensagem) > 1024) {
            return ['sucesso' => false, 'mensagem' => 'Com foto/vídeo, a mensagem tem limite de 1024 caracteres (é a legenda, o Telegram não aceita mais que isso).'];
        }
    } else {
        $midia_tipo = null;
        $midia_caminho = null;
    }

    // Sempre agenda via cron — nunca envia inline para não travar a requisição
    $agendado_em = date('Y-m-d H:i:s', strtotime($agendado_str));
    $pdo->prepare("INSERT INTO remarketing_campanhas (id_usuario, bot_id, nome, audiencia, mensagem, midia_caminho, midia_tipo, agendado_em, status, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pendente', NOW())")
        ->execute([$id_usuario, $bot_id, $nome ?: null, $audiencia, $mensagem, $midia_caminho, $midia_tipo, $agendado_em]);
    $campanha_id = (int)$pdo->lastInsertId();

    return ['sucesso' => true, 'mensagem' => 'Campanha agendada para ' . date('d/m/Y H:i', strtotime($agendado_em)) . '.', 'campanha_id' => $campanha_id];
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
    <title>Remarketing - <?php echo htmlspecialchars(nomeSistema()); ?></title>
    <?php include 'tema_inline.php'; ?>
    <link rel="stylesheet" href="assets/css/painel.css?v=<?php echo @filemtime(__DIR__.'/assets/css/painel.css'); ?>">
</head>
<body>
<div class="layout-painel">
    <?php include 'barra_lateral.php'; ?>
    <main class="conteudo-principal">
        <div class="cabecalho-pagina">
            <div>
                <h1>Remarketing</h1>
                <p>Campanhas para recuperar quem não comprou ou engajar quem comprou.</p>
            </div>
            <div class="acoes-cabecalho">
                <button class="botao botao-primario" id="btn-nova-campanha">+ Nova campanha</button>
                <button type="button" class="alternador-tema" onclick="alternarTema()" aria-label="Alternar tema">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"></path></svg>
                    Tema
                </button>
            </div>
        </div>
        <div class="painel">
            <?php
                $f_bot = isset($_GET['bot_id']) ? (int)$_GET['bot_id'] : 0;
                $f_aud = $_GET['audiencia'] ?? '';
                $f_status = $_GET['status'] ?? '';
                $limite = max(5, min(50, (int)($_GET['limite'] ?? 10)));

                function montarUrlFiltroRemarketing(array $overrides, int $f_bot, string $f_aud, string $f_status, int $limite): string
                {
                    $params = array_filter([
                        'bot_id'    => $f_bot ?: null,
                        'audiencia' => $f_aud ?: null,
                        'status'    => $f_status ?: null,
                        'limite'    => $limite,
                    ], fn($v) => $v !== null && $v !== '');
                    $params = array_merge($params, $overrides);
                    $params = array_filter($params, fn($v) => $v !== null && $v !== '');
                    return 'remarketing?' . http_build_query($params);
                }
            ?>
            <div class="barra-filtros" style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;">
                <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
                    <form method="GET" id="form-filtro-bot">
                        <input type="hidden" name="audiencia" value="<?php echo htmlspecialchars($f_aud); ?>">
                        <input type="hidden" name="status" value="<?php echo htmlspecialchars($f_status); ?>">
                        <input type="hidden" name="limite" value="<?php echo $limite; ?>">
                        <select name="bot_id" onchange="this.form.submit()">
                            <option value="">Todos os bots</option>
                            <?php foreach ($meus_bots as $b): ?>
                                <option value="<?php echo $b['id']; ?>" <?php echo $f_bot == $b['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($b['nome']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                    <form method="GET" id="form-filtro-audiencia">
                        <input type="hidden" name="bot_id" value="<?php echo $f_bot; ?>">
                        <input type="hidden" name="status" value="<?php echo htmlspecialchars($f_status); ?>">
                        <input type="hidden" name="limite" value="<?php echo $limite; ?>">
                        <select name="audiencia" onchange="this.form.submit()">
                            <option value="">Todas as audiências</option>
                            <option value="nao_comprou" <?php echo $f_aud === 'nao_comprou' ? 'selected' : ''; ?>>Acessou e não comprou</option>
                            <option value="comprou" <?php echo $f_aud === 'comprou' ? 'selected' : ''; ?>>Acessou e comprou</option>
                        </select>
                    </form>
                    <div class="seletor-periodo">
                        <a href="<?php echo htmlspecialchars(montarUrlFiltroRemarketing(['status' => ''], $f_bot, $f_aud, $f_status, $limite)); ?>" class="periodo-item<?php echo $f_status === '' ? ' ativo' : ''; ?>">Todos</a>
                        <a href="<?php echo htmlspecialchars(montarUrlFiltroRemarketing(['status' => 'pendente'], $f_bot, $f_aud, $f_status, $limite)); ?>" class="periodo-item<?php echo $f_status === 'pendente' ? ' ativo' : ''; ?>">Pendente</a>
                        <a href="<?php echo htmlspecialchars(montarUrlFiltroRemarketing(['status' => 'processando'], $f_bot, $f_aud, $f_status, $limite)); ?>" class="periodo-item<?php echo $f_status === 'processando' ? ' ativo' : ''; ?>">Processando</a>
                        <a href="<?php echo htmlspecialchars(montarUrlFiltroRemarketing(['status' => 'concluida'], $f_bot, $f_aud, $f_status, $limite)); ?>" class="periodo-item<?php echo $f_status === 'concluida' ? ' ativo' : ''; ?>">Concluída</a>
                    </div>
                </div>
                <form method="GET" id="form-filtro-limite">
                    <input type="hidden" name="bot_id" value="<?php echo $f_bot; ?>">
                    <input type="hidden" name="audiencia" value="<?php echo htmlspecialchars($f_aud); ?>">
                    <input type="hidden" name="status" value="<?php echo htmlspecialchars($f_status); ?>">
                    <select name="limite" onchange="this.form.submit()">
                        <?php foreach ([10,20,30,50] as $opt): ?>
                            <option value="<?php echo $opt; ?>" <?php echo $limite === $opt ? 'selected' : ''; ?>><?php echo $opt; ?> por página</option>
                        <?php endforeach; ?>
                    </select>
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
                            <th>Nome</th>
                            <th>Bot</th>
                            <th>Aud.</th>
                            <th>Mensagem</th>
                            <th>Agendado</th>
                            <th>Status</th>
                            <th>Dest.</th>
                            <th>Sucesso</th>
                            <th>Falhas</th>
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
                                <td><?php echo htmlspecialchars($c['nome'] ?: ('Campanha #' . $c['id'])); ?></td>
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
                                        $rotulos_status = ['pendente' => 'Pendente', 'processando' => 'Processando', 'concluida' => 'Concluída', 'falha' => 'Falha'];
                                        $classe_status = 'badge-neutro';
                                        if ($status === 'pendente') { $classe_status = 'badge-alerta'; }
                                        if ($status === 'processando') { $classe_status = 'badge-alerta'; }
                                        if ($status === 'concluida') { $classe_status = 'badge-sucesso'; }
                                        if ($status === 'falha') { $classe_status = 'badge-perigo'; }
                                    ?>
                                    <span class="badge <?php echo $classe_status; ?>"><?php echo htmlspecialchars($rotulos_status[$status] ?? $status); ?></span>
                                </td>
                                <td><?php echo (int)$c['total_destinatarios']; ?></td>
                                <td><?php echo (int)$c['entregues']; ?></td>
                                <td><?php echo (int)$c['falhas']; ?></td>
                                <td>
                                    <a class="botao" href="remarketing_detalhes?campanha=<?php echo (int)$c['id']; ?>">Detalhes</a>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
            <?php echo paginador($total_reg, $limite); ?>
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
                        <div class="campo">
                            <label for="modal-nome">Nome da campanha (opcional)</label>
                            <input type="text" id="modal-nome" name="nome" maxlength="100" placeholder="Ex.: Recuperação carrinho — setembro">
                        </div>
                        <div class="grade grade-2 grade-compacta" style="margin-top:12px;">
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
                                <input type="date" id="modal-data" aria-label="Data do envio" required>
                                <input type="time" id="modal-hora" aria-label="Hora do envio" required>
                            </div>
                            <input type="hidden" id="modal-agendado_em" name="agendado_em">
                            <small>Obrigatório. A campanha só é enviada nessa data e hora.</small>
                        </div>
                        <div class="campo" style="margin-top:12px;">
                            <small id="contador-destinatarios" style="display:none;"></small>
                        </div>
                        <div class="campo" style="margin-top:12px;">
                            <label for="modal-mensagem">Mensagem</label>
                            <textarea id="modal-mensagem" name="mensagem" rows="5" placeholder="Digite a mensagem da campanha..." required></textarea>
                            <small><span id="contador-mensagem">0</span>/<span id="limite-mensagem">4096</span> caracteres</small>
                        </div>
                        <div class="campo" style="margin-top:12px;">
                            <label for="modal-midia">Foto ou vídeo (opcional)</label>
                            <input type="file" id="modal-midia" accept="image/jpeg,image/png,video/mp4,video/quicktime,video/x-matroska,video/webm">
                            <input type="hidden" id="modal-midia-caminho" name="midia_caminho">
                            <input type="hidden" id="modal-midia-tipo" name="midia_tipo">
                            <small>Foto até 5MB (jpg/png) ou vídeo até 20MB (mp4/mov/mkv/webm). Com mídia, a mensagem vira legenda e o limite cai pra 1024 caracteres.</small>
                        </div>
                        <div class="linha-acoes" style="margin-top:16px;">
                            <button type="button" class="botao" id="btn-cancelar-campanha">Cancelar</button>
                            <button type="submit" class="botao botao-primario">Agendar</button>
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
