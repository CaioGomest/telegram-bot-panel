<?php
declare(strict_types=1);

require_once __DIR__ . '/funcoes/usuario.php';
require_once __DIR__ . '/funcoes/webhooks.php';
bloquearAdmin();

$id_usuario = (int) $_SESSION['usuario_id'];
$disponivel = webhooksDisponivel();
$mensagem = '';
$erro = '';
$abrir_modal = false;
$eventos_catalogo = eventosWebhook();
$form = [
    'id' => '',
    'nome' => '',
    'url' => '',
    'eventos' => array_keys($eventos_catalogo),
    'bot_id' => '',
    'ativo' => 1,
    'tem_secret' => false,
];

$bots = [];
$lista = [];
$stats = ['ativos' => 0, 'bots_monitorados' => 0, 'enviados' => 0];

if ($disponivel) {
    try {
        $stmt_bots = $pdo->prepare('SELECT id, primeiro_nome, nome_usuario FROM bots WHERE id_usuario = ? ORDER BY primeiro_nome, id');
        $stmt_bots->execute([$id_usuario]);
        $bots = $stmt_bots->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $bots = [];
    }
}

if ($disponivel && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verificarCsrf();
    $acao = (string) ($_POST['acao'] ?? '');
    try {
        if ($acao === 'salvar') {
            $id = isset($_POST['id']) && $_POST['id'] !== '' ? (int) $_POST['id'] : null;
            salvarWebhook($id_usuario, $id, $_POST);
            header('Location: webhooks?salvo=1');
            exit;
        }
        if ($acao === 'excluir') {
            excluirWebhook((int) ($_POST['id'] ?? 0), $id_usuario);
            header('Location: webhooks?excluido=1');
            exit;
        }
        if ($acao === 'alternar') {
            alternarWebhook((int) ($_POST['id'] ?? 0), $id_usuario);
            header('Location: webhooks?atualizado=1');
            exit;
        }
    } catch (RuntimeException $e) {
        $erro = $e->getMessage();
        if ($acao === 'salvar') {
            $abrir_modal = true;
            $form['id'] = (string) ($_POST['id'] ?? '');
            $form['nome'] = (string) ($_POST['nome'] ?? '');
            $form['url'] = (string) ($_POST['url'] ?? '');
            $form['eventos'] = is_array($_POST['eventos'] ?? null) ? array_map('strval', $_POST['eventos']) : [];
            $form['bot_id'] = (string) ($_POST['bot_id'] ?? '');
            $form['ativo'] = !empty($_POST['ativo']) ? 1 : 0;
            $existente = $form['id'] !== '' ? buscarWebhookDoUsuario((int) $form['id'], $id_usuario) : null;
            $form['tem_secret'] = $existente && trim((string) ($existente['secret'] ?? '')) !== '';
        }
    } catch (PDOException $e) {
        error_log('webhooks: ' . $e->getMessage());
        $erro = 'Não foi possível salvar. Peça ao administrador para atualizar o banco.';
    }
}

if ($disponivel && !$abrir_modal && isset($_GET['editar'])) {
    $editando = buscarWebhookDoUsuario((int) $_GET['editar'], $id_usuario);
    if ($editando) {
        $abrir_modal = true;
        $form['id'] = (string) $editando['id'];
        $form['nome'] = (string) $editando['nome'];
        $form['url'] = (string) $editando['url'];
        $form['eventos'] = array_filter(array_map('trim', explode(',', (string) $editando['eventos'])));
        $form['bot_id'] = $editando['bot_id'] !== null ? (string) $editando['bot_id'] : '';
        $form['ativo'] = (int) $editando['ativo'];
        $form['tem_secret'] = trim((string) ($editando['secret'] ?? '')) !== '';
    }
}

if ($disponivel && $erro === '') {
    if (isset($_GET['salvo'])) {
        $mensagem = 'Webhook salvo.';
    } elseif (isset($_GET['excluido'])) {
        $mensagem = 'Webhook excluído.';
    } elseif (isset($_GET['atualizado'])) {
        $mensagem = 'Webhook atualizado.';
    }
}

if ($disponivel) {
    try {
        $lista = listarWebhooksUsuario($id_usuario);
        $stats = estatisticasWebhooks($id_usuario);
    } catch (Throwable $e) {
        $disponivel = false;
    }
}

function nomeBotWebhook(array $bot): string
{
    $nome = trim((string) ($bot['primeiro_nome'] ?? ''));
    if ($nome !== '') {
        return $nome;
    }
    $usuario = trim((string) ($bot['nome_usuario'] ?? ''));
    return $usuario !== '' ? '@' . ltrim($usuario, '@') : ('Bot #' . (int) $bot['id']);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Webhooks - <?php echo htmlspecialchars(nomeSistema()); ?></title>
    <?php include 'tema_inline.php'; ?>
    <link rel="stylesheet" href="assets/css/coyote.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/coyote.css'); ?>">
</head>
<body>
<div class="layout-painel">
    <?php include 'barra_lateral.php'; ?>
    <main class="conteudo-principal">
        <div class="cabecalho-pagina">
            <div>
                <h1>Webhooks</h1>
                <p>Receba lead, PIX gerado e pagamento aprovado em um endereço seu.</p>
            </div>
            <div class="acoes-cabecalho">
                <?php if ($disponivel): ?>
                    <button type="button" class="botao botao-primario" id="btn-novo-webhook">Novo webhook</button>
                <?php endif; ?>
                <button type="button" class="alternador-tema" onclick="alternarTema()" aria-label="Alternar tema">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"></path></svg>
                    Tema
                </button>
            </div>
        </div>

        <?php if ($mensagem): ?>
            <div class="aviso aviso-sucesso"><?php echo htmlspecialchars($mensagem); ?></div>
        <?php endif; ?>
        <?php if ($erro && !$abrir_modal): ?>
            <div class="aviso aviso-erro"><?php echo htmlspecialchars($erro); ?></div>
        <?php endif; ?>

        <?php if (!$disponivel): ?>
            <div class="estado-vazio">
                <h3>Webhooks ainda não disponíveis</h3>
                <p>Peça ao administrador para rodar Atualizar banco. A tela libera assim que as tabelas existirem.</p>
            </div>
        <?php else: ?>

        <div class="grade-kpi">
            <div class="cartao-kpi">
                <div class="cartao-kpi-cabecalho">
                    <span class="rotulo-kpi">Webhooks ativos</span>
                </div>
                <div class="valor-kpi"><?php echo (int) $stats['ativos']; ?></div>
            </div>
            <div class="cartao-kpi">
                <div class="cartao-kpi-cabecalho">
                    <span class="rotulo-kpi">Bots monitorados</span>
                </div>
                <div class="valor-kpi"><?php echo (int) $stats['bots_monitorados']; ?></div>
            </div>
            <div class="cartao-kpi">
                <div class="cartao-kpi-cabecalho">
                    <span class="rotulo-kpi">Envios com sucesso</span>
                </div>
                <div class="valor-kpi"><?php echo number_format((int) $stats['enviados'], 0, ',', '.'); ?></div>
            </div>
        </div>

        <div class="aviso aviso-alerta">
            A URL precisa ser https e pública. Se você definir um secret, cada envio leva o header <span class="mono">X-Webhook-Signature</span> com o HMAC-SHA256 do corpo em hexadecimal. Depois de 5 falhas seguidas o webhook desliga sozinho. O código Pix só vai no pagamento criado — na aprovação ele não fica salvo.
        </div>

        <div class="painel">
            <div class="painel-cabecalho">
                <h2>Webhooks cadastrados</h2>
            </div>
            <div class="tabela-dados">
                <table>
                    <thead>
                        <tr>
                            <th>Nome</th>
                            <th>URL</th>
                            <th>Eventos</th>
                            <th>Bot</th>
                            <th>Falhas</th>
                            <th>Situação</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$lista): ?>
                            <tr><td colspan="7" class="texto-suave" style="text-align:center;padding:28px;">Nenhum webhook ainda.</td></tr>
                        <?php else: foreach ($lista as $item):
                            $rotulos = [];
                            foreach (array_filter(array_map('trim', explode(',', (string) $item['eventos']))) as $codigo) {
                                $rotulos[] = $eventos_catalogo[$codigo] ?? $codigo;
                            }
                            $bot_rotulo = 'Todos os bots';
                            if ($item['bot_id'] !== null) {
                                $bot_rotulo = trim((string) ($item['bot_nome'] ?? ''));
                                if ($bot_rotulo === '') {
                                    $usuario = trim((string) ($item['bot_usuario'] ?? ''));
                                    $bot_rotulo = $usuario !== '' ? '@' . ltrim($usuario, '@') : 'Bot removido';
                                }
                            }
                        ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string) $item['nome']); ?></td>
                                <td class="mono texto-suave" title="<?php echo htmlspecialchars((string) $item['url']); ?>"><?php echo htmlspecialchars((string) $item['url']); ?></td>
                                <td><?php echo htmlspecialchars(implode(', ', $rotulos)); ?></td>
                                <td><?php echo htmlspecialchars($bot_rotulo); ?></td>
                                <td class="mono"><?php echo (int) $item['falhas_consecutivas']; ?>/5</td>
                                <td>
                                    <?php if ((int) $item['ativo']): ?>
                                        <span class="badge badge-sucesso">Ativo</span>
                                    <?php elseif ((int) $item['falhas_consecutivas'] >= 5): ?>
                                        <span class="badge badge-perigo">Desligado (5 falhas)</span>
                                    <?php else: ?>
                                        <span class="badge badge-neutro">Inativo</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="display:flex;gap:6px;flex-wrap:wrap;">
                                        <a href="webhooks?editar=<?php echo (int) $item['id']; ?>" class="btn-icon editar" title="Editar">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                        </a>
                                        <form method="POST" style="display:inline;">
                                            <?php echo campoCsrf(); ?>
                                            <input type="hidden" name="acao" value="alternar">
                                            <input type="hidden" name="id" value="<?php echo (int) $item['id']; ?>">
                                            <?php $ativar = !(int) $item['ativo']; ?>
                                            <button type="submit" class="btn-icon <?php echo $ativar ? 'ativar' : 'desativar'; ?>" title="<?php echo $ativar ? 'Ativar' : 'Desativar'; ?>">
                                                <?php if ($ativar): ?>
                                                    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                                                <?php else: ?>
                                                    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                                <?php endif; ?>
                                            </button>
                                        </form>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Excluir este webhook?');">
                                            <?php echo campoCsrf(); ?>
                                            <input type="hidden" name="acao" value="excluir">
                                            <input type="hidden" name="id" value="<?php echo (int) $item['id']; ?>">
                                            <button type="submit" class="btn-icon excluir" title="Excluir">
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="painel" style="margin-top:16px;">
            <div class="painel-cabecalho">
                <h2>Exemplo de payload</h2>
            </div>
            <div class="exemplos-webhook">
                <p class="texto-suave">E-mail, sobrenome, usuário do Telegram e IP não existem neste painel — esses campos vão null. O telefone entra quando o lead tem número. O código Pix só aparece em pagamento criado.</p>
                <?php foreach (exemplosPayloadWebhook() as $codigo => $exemplo): ?>
                    <details>
                        <summary><?php echo htmlspecialchars($eventos_catalogo[$codigo] ?? $codigo); ?></summary>
                        <pre class="exemplo-payload"><?php echo htmlspecialchars(json_encode($exemplo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></pre>
                    </details>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </main>
</div>

<?php if ($disponivel): ?>
<div class="sobreposicao-modal<?php echo $abrir_modal ? ' aberto' : ''; ?>" id="modal-webhook">
    <div class="modal-gateway">
        <div class="cabecalho-modal">
            <div>
                <div class="titulo-modal"><?php echo $form['id'] !== '' ? 'Editar webhook' : 'Novo webhook'; ?></div>
                <p class="texto-suave" style="margin:4px 0 0;">HTTPS obrigatório. Secret em branco no editar mantém o atual.</p>
            </div>
            <button type="button" class="fechar-modal" id="btn-fechar-webhook">✕</button>
        </div>
        <div class="corpo-modal">
            <?php if ($erro && $abrir_modal): ?>
                <div class="aviso aviso-erro"><?php echo htmlspecialchars($erro); ?></div>
            <?php endif; ?>
            <form method="POST" autocomplete="off">
                <?php echo campoCsrf(); ?>
                <input type="hidden" name="acao" value="salvar">
                <input type="hidden" name="id" id="webhook-id" value="<?php echo htmlspecialchars($form['id']); ?>">

                <div class="campo">
                    <label for="webhook-nome">Nome</label>
                    <input type="text" id="webhook-nome" name="nome" maxlength="80" required value="<?php echo htmlspecialchars($form['nome']); ?>" placeholder="Ex: CRM de vendas">
                </div>
                <div class="campo" style="margin-top:14px;">
                    <label for="webhook-url">URL</label>
                    <input type="url" id="webhook-url" name="url" maxlength="500" required value="<?php echo htmlspecialchars($form['url']); ?>" placeholder="https://">
                </div>
                <div class="campo" style="margin-top:14px;">
                    <label for="webhook-secret">Secret</label>
                    <div style="display:flex;gap:8px;">
                        <input type="text" id="webhook-secret" name="secret" maxlength="128" value="" placeholder="<?php echo $form['tem_secret'] ? 'Secret já salvo — preencha só para trocar' : 'Opcional'; ?>" autocomplete="off" style="flex:1;">
                        <button type="button" class="botao" id="btn-gerar-secret">Gerar</button>
                    </div>
                </div>
                <div class="campo" style="margin-top:14px;">
                    <label>Eventos</label>
                    <?php foreach ($eventos_catalogo as $codigo => $rotulo): ?>
                        <label class="opcao-ativar-gateway">
                            <input type="checkbox" name="eventos[]" value="<?php echo htmlspecialchars($codigo); ?>" <?php echo in_array($codigo, $form['eventos'], true) ? 'checked' : ''; ?>>
                            <?php echo htmlspecialchars($rotulo); ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <div class="campo" style="margin-top:14px;">
                    <label for="webhook-bot">Bot</label>
                    <select id="webhook-bot" name="bot_id">
                        <option value="">Todos os meus bots</option>
                        <?php foreach ($bots as $bot): ?>
                            <option value="<?php echo (int) $bot['id']; ?>" <?php echo (string) $bot['id'] === $form['bot_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars(nomeBotWebhook($bot)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <label class="opcao-ativar-gateway" style="margin-top:14px;">
                    <input type="checkbox" name="ativo" value="1" <?php echo $form['ativo'] ? 'checked' : ''; ?>>
                    Ativo
                </label>
                <div class="linha-acoes" style="margin-top:20px;">
                    <button type="button" class="botao" id="btn-cancelar-webhook">Cancelar</button>
                    <button type="submit" class="botao botao-primario">Salvar webhook</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="assets/js/tema.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/tema.js'); ?>"></script>
<?php if ($disponivel): ?>
<script>
(function () {
    var modal = document.getElementById('modal-webhook');
    function abrir() { modal.classList.add('aberto'); }
    function fechar() { modal.classList.remove('aberto'); }
    document.getElementById('btn-novo-webhook').addEventListener('click', function () {
        document.getElementById('webhook-id').value = '';
        document.getElementById('webhook-nome').value = '';
        document.getElementById('webhook-url').value = '';
        document.getElementById('webhook-secret').value = '';
        document.getElementById('webhook-secret').placeholder = 'Opcional';
        document.getElementById('webhook-bot').value = '';
        modal.querySelectorAll('input[name="eventos[]"]').forEach(function (el) { el.checked = true; });
        modal.querySelector('input[name="ativo"]').checked = true;
        modal.querySelector('.titulo-modal').textContent = 'Novo webhook';
        abrir();
    });
    document.getElementById('btn-fechar-webhook').addEventListener('click', fechar);
    document.getElementById('btn-cancelar-webhook').addEventListener('click', fechar);
    modal.addEventListener('click', function (evento) {
        if (evento.target === modal) fechar();
    });
    document.getElementById('btn-gerar-secret').addEventListener('click', function () {
        var bytes = new Uint8Array(24);
        crypto.getRandomValues(bytes);
        var hex = Array.from(bytes).map(function (b) { return b.toString(16).padStart(2, '0'); }).join('');
        var campo = document.getElementById('webhook-secret');
        campo.value = hex;
    });
})();
</script>
<?php endif; ?>
</body>
</html>
