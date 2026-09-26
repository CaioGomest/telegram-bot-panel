<?php
declare(strict_types=1);

require_once __DIR__ . '/../funcoes/usuario.php';
require_once __DIR__ . '/../funcoes/comunidade.php';
verificarAdmin();
$caminho_base = '../';

$mensagem = '';
$erro = '';
$disponivel = comunidadeDisponivel();
$form = [
    'id' => '',
    'tipo' => 'grupo',
    'titulo' => '',
    'subtitulo' => '',
    'url' => '',
    'icone' => 'whatsapp',
    'membros_atual' => '0',
    'membros_max' => '0',
    'ativo' => 1,
];

if ($disponivel && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verificarCsrf();
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'salvar') {
        $id = isset($_POST['id']) && $_POST['id'] !== '' ? (int) $_POST['id'] : null;
        try {
            salvarLinkComunidade($id, $_POST);
            registrarAtividade((int) $_SESSION['usuario_id'], 'sistema', 'Comunidade', 'Link da comunidade salvo: ' . trim((string) ($_POST['titulo'] ?? '')));
            header('Location: comunidade?salvo=1');
            exit;
        } catch (RuntimeException $e) {
            $erro = $e->getMessage();
        } catch (PDOException $e) {
            error_log('comunidade: ' . $e->getMessage());
            $erro = 'Não foi possível salvar o link. Rode Atualizar banco e tente de novo.';
        }
        if ($erro !== '') {
            $form = [
                'id' => $_POST['id'] ?? '',
                'tipo' => (string) ($_POST['tipo'] ?? 'grupo'),
                'titulo' => (string) ($_POST['titulo'] ?? ''),
                'subtitulo' => (string) ($_POST['subtitulo'] ?? ''),
                'url' => (string) ($_POST['url'] ?? ''),
                'icone' => (string) ($_POST['icone'] ?? 'link'),
                'membros_atual' => (string) ($_POST['membros_atual'] ?? '0'),
                'membros_max' => (string) ($_POST['membros_max'] ?? '0'),
                'ativo' => isset($_POST['ativo']) ? 1 : 0,
            ];
        }
    } elseif ($acao === 'excluir') {
        $titulo = excluirLinkComunidade((int) ($_POST['id'] ?? 0));
        if ($titulo !== null) {
            registrarAtividade((int) $_SESSION['usuario_id'], 'sistema', 'Comunidade', 'Link da comunidade excluído: ' . $titulo);
        }
        header('Location: comunidade?excluido=1');
        exit;
    } elseif ($acao === 'alternar') {
        $link = alternarLinkComunidade((int) ($_POST['id'] ?? 0));
        if ($link) {
            $estado = (int) $link['ativo'] === 1 ? 'ativado' : 'desativado';
            registrarAtividade((int) $_SESSION['usuario_id'], 'sistema', 'Comunidade', 'Link da comunidade ' . $estado . ': ' . $link['titulo']);
        }
        header('Location: comunidade');
        exit;
    } elseif ($acao === 'mover') {
        moverLinkComunidade((int) ($_POST['id'] ?? 0), (string) ($_POST['direcao'] ?? ''));
        header('Location: comunidade');
        exit;
    }
}

if (isset($_GET['salvo'])) {
    $mensagem = 'Link salvo.';
}
if (isset($_GET['excluido'])) {
    $mensagem = 'Link excluído.';
}

$editando = null;
if ($disponivel && $erro === '' && isset($_GET['editar'])) {
    $editando = buscarLinkComunidade((int) $_GET['editar']);
    if (!$editando) {
        $erro = 'Link não encontrado.';
    } else {
        $form = [
            'id' => (string) $editando['id'],
            'tipo' => (string) $editando['tipo'],
            'titulo' => (string) $editando['titulo'],
            'subtitulo' => (string) ($editando['subtitulo'] ?? ''),
            'url' => (string) $editando['url'],
            'icone' => (string) $editando['icone'],
            'membros_atual' => (string) $editando['membros_atual'],
            'membros_max' => (string) $editando['membros_max'],
            'ativo' => (int) $editando['ativo'],
        ];
    }
}

$links = $disponivel ? listarLinksComunidade(false) : [];
$tipos = tiposComunidade();
// Não usar $icones: barra_lateral.php declara a variável de novo pros ícones do menu
// e apagava esta lista. O formulário morria no primeiro item e sumia o campo de link.
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Comunidade - <?php echo htmlspecialchars(nomeSistema()); ?></title>
    <?php include __DIR__ . '/../tema_inline.php'; ?>
    <link rel="stylesheet" href="../assets/css/coyote.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/coyote.css'); ?>">
</head>
<body>
<div class="layout-painel">
    <?php include __DIR__ . '/../barra_lateral.php'; ?>

    <main class="conteudo-principal">
        <div class="cabecalho-pagina">
            <div>
                <h1>Comunidade</h1>
                <p>Publique os links que aparecem na aba Comunidade de todos os usuários.</p>
            </div>
            <div class="acoes-cabecalho">
                <?php if ($editando || $form['id'] !== ''): ?>
                    <a href="comunidade.php" class="botao">Novo link</a>
                <?php endif; ?>
                <button type="button" class="alternador-tema" onclick="alternarTema()" aria-label="Alternar tema">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"></path></svg>
                    Tema
                </button>
            </div>
        </div>

        <?php if (!$disponivel): ?>
            <div class="painel">
                <div class="aviso aviso-erro">A tabela ainda não existe. Rode <a href="atualiza_banco.php" class="login-link-esqueci">Atualizar banco</a> e volte nesta página.</div>
            </div>
        <?php else: ?>

        <?php if ($mensagem): ?>
            <div class="painel"><div class="aviso aviso-sucesso"><?php echo htmlspecialchars($mensagem); ?></div></div>
        <?php endif; ?>
        <?php if ($erro): ?>
            <div class="painel"><div class="aviso aviso-erro"><?php echo htmlspecialchars($erro); ?></div></div>
        <?php endif; ?>

        <div class="painel">
            <div class="painel-cabecalho">
                <h2><?php echo $form['id'] !== '' ? 'Editar link' : 'Novo link'; ?></h2>
            </div>
            <form method="POST">
                <?php echo campoCsrf(); ?>
                <input type="hidden" name="acao" value="salvar">
                <?php if ($form['id'] !== ''): ?><input type="hidden" name="id" value="<?php echo (int) $form['id']; ?>"><?php endif; ?>

                <div class="grade grade-2 grade-compacta">
                    <div class="campo">
                        <label for="tipo">Tipo</label>
                        <select id="tipo" name="tipo">
                            <?php foreach ($tipos as $valor => $rotulo): ?>
                                <option value="<?php echo htmlspecialchars($valor); ?>" <?php echo $form['tipo'] === $valor ? 'selected' : ''; ?>><?php echo htmlspecialchars($rotulo); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="campo completo">
                        <label>Ícone</label>
                        <div class="comunidade-icones">
                            <?php foreach (iconesComunidade() as $valor => $icone): ?>
                                <label class="comunidade-icone-opcao">
                                    <input type="radio" name="icone" value="<?php echo htmlspecialchars($valor); ?>" <?php echo $form['icone'] === $valor ? 'checked' : ''; ?>>
                                    <span class="comunidade-icone icone-<?php echo htmlspecialchars($valor); ?>"><?php echo svgIconeComunidade($valor); ?></span>
                                    <?php echo htmlspecialchars($icone['label']); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="campo">
                        <label for="titulo">Título</label>
                        <input type="text" id="titulo" name="titulo" required maxlength="80" placeholder="Grupo WhatsApp 1"
                               value="<?php echo htmlspecialchars($form['titulo']); ?>">
                    </div>
                    <div class="campo">
                        <label for="subtitulo">Subtítulo (opcional)</label>
                        <input type="text" id="subtitulo" name="subtitulo" maxlength="120" placeholder="Grupo oficial de avisos"
                               value="<?php echo htmlspecialchars($form['subtitulo']); ?>">
                    </div>
                    <div class="campo completo">
                        <label for="url">Link</label>
                        <input type="text" id="url" name="url" required maxlength="500" placeholder="https://chat.whatsapp.com/..."
                               value="<?php echo htmlspecialchars($form['url']); ?>">
                        <small>https://, mailto: ou tel:+5511...</small>
                    </div>
                    <div class="campo">
                        <label for="membros_atual">Vagas atuais</label>
                        <input type="number" id="membros_atual" name="membros_atual" min="0" max="1000000" step="1"
                               value="<?php echo htmlspecialchars($form['membros_atual']); ?>">
                    </div>
                    <div class="campo">
                        <label for="membros_max">Vagas máximas</label>
                        <input type="number" id="membros_max" name="membros_max" min="0" max="1000000" step="1"
                               value="<?php echo htmlspecialchars($form['membros_max']); ?>">
                    </div>
                </div>
                <small>Deixe o máximo em 0 para um link sem limite. Com máximo preenchido, o card mostra a contagem e fica LOTADO quando o atual alcança o máximo — aí ele deixa de abrir.</small>

                <label class="opcao-ativar-gateway" style="margin-top:14px;">
                    <input type="checkbox" name="ativo" <?php echo $form['ativo'] ? 'checked' : ''; ?>>
                    <span>Link ativo (visível na aba Comunidade)</span>
                </label>

                <div class="linha-acoes" style="margin-top:20px;">
                    <?php if ($form['id'] !== ''): ?><a href="comunidade.php" class="botao">Cancelar edição</a><?php endif; ?>
                    <button type="submit" class="botao botao-primario"><?php echo $form['id'] !== '' ? 'Salvar alterações' : 'Publicar link'; ?></button>
                </div>
            </form>
        </div>

        <div class="painel">
            <div class="painel-cabecalho"><h2>Links cadastrados</h2></div>
            <div class="tabela-dados">
                <table>
                    <thead>
                        <tr>
                            <th></th>
                            <th>Ordem</th>
                            <th>Título</th>
                            <th>Tipo</th>
                            <th>Vagas</th>
                            <th>Situação</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$links): ?>
                            <tr><td colspan="7" style="text-align:center;padding:32px;" class="texto-suave">Nenhum link cadastrado ainda.</td></tr>
                        <?php else: foreach ($links as $i => $link):
                            $max = (int) $link['membros_max'];
                            $lotado = linkComunidadeLotado($link);
                            $primeiro = $i === 0;
                            $ultimo = $i === count($links) - 1;
                            $chave_icone = preg_replace('/[^a-z]/', '', (string) $link['icone']);
                        ?>
                            <tr>
                                <td>
                                    <span class="comunidade-icone icone-<?php echo htmlspecialchars($chave_icone); ?>"><?php echo svgIconeComunidade($chave_icone); ?></span>
                                </td>
                                <td>
                                    <div style="display:flex;gap:6px;">
                                        <?php if (!$primeiro): ?>
                                            <form method="POST" style="display:inline;">
                                                <?php echo campoCsrf(); ?>
                                                <input type="hidden" name="acao" value="mover">
                                                <input type="hidden" name="direcao" value="subir">
                                                <input type="hidden" name="id" value="<?php echo (int) $link['id']; ?>">
                                                <button type="submit" class="botao" title="Subir" aria-label="Mover para cima">↑</button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if (!$ultimo): ?>
                                            <form method="POST" style="display:inline;">
                                                <?php echo campoCsrf(); ?>
                                                <input type="hidden" name="acao" value="mover">
                                                <input type="hidden" name="direcao" value="descer">
                                                <input type="hidden" name="id" value="<?php echo (int) $link['id']; ?>">
                                                <button type="submit" class="botao" title="Descer" aria-label="Mover para baixo">↓</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars((string) $link['titulo']); ?>
                                    <div class="celula-sub"><?php echo htmlspecialchars((string) ($link['subtitulo'] ?: $link['url'])); ?></div>
                                </td>
                                <td><?php echo htmlspecialchars($tipos[$link['tipo']] ?? $link['tipo']); ?></td>
                                <td class="mono texto-suave"><?php echo $max > 0 ? number_format((int) $link['membros_atual'], 0, ',', '.') . '/' . number_format($max, 0, ',', '.') : '—'; ?></td>
                                <td>
                                    <div style="display:flex;gap:6px;flex-wrap:wrap;">
                                        <?php if ((int) $link['ativo']): ?>
                                            <span class="badge badge-sucesso">Ativo</span>
                                        <?php else: ?>
                                            <span class="badge badge-neutro">Inativo</span>
                                        <?php endif; ?>
                                        <?php if ($lotado): ?>
                                            <span class="badge badge-perigo">LOTADO</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div style="display:flex;gap:6px;flex-wrap:wrap;">
                                        <a href="comunidade.php?editar=<?php echo (int) $link['id']; ?>" class="btn-icon editar" title="Editar">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                        </a>
                                        <form method="POST" style="display:inline;">
                                            <?php echo campoCsrf(); ?>
                                            <input type="hidden" name="acao" value="alternar">
                                            <input type="hidden" name="id" value="<?php echo (int) $link['id']; ?>">
                                            <?php $ativar = !(int) $link['ativo']; ?>
                                            <button type="submit" class="btn-icon <?php echo $ativar ? 'ativar' : 'desativar'; ?>" title="<?php echo $ativar ? 'Ativar' : 'Desativar'; ?>">
                                                <?php if ($ativar): ?>
                                                    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                                                <?php else: ?>
                                                    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                                <?php endif; ?>
                                            </button>
                                        </form>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Excluir este link da comunidade?');">
                                            <?php echo campoCsrf(); ?>
                                            <input type="hidden" name="acao" value="excluir">
                                            <input type="hidden" name="id" value="<?php echo (int) $link['id']; ?>">
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
        <?php endif; ?>
    </main>
</div>

<script src="../assets/js/tema.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/tema.js'); ?>"></script>
<?php if ($disponivel): ?>
<script>
(function () {
    var mapa = <?php echo json_encode(iconePadraoPorTipoComunidade(), JSON_UNESCAPED_UNICODE); ?>;
    var tipo = document.getElementById('tipo');
    if (!tipo) return;
    function iconeMarcado() {
        var el = document.querySelector('input[name="icone"]:checked');
        return el ? el.value : '';
    }
    function marcarIcone(valor) {
        var el = document.querySelector('input[name="icone"][value="' + valor + '"]');
        if (el) el.checked = true;
    }
    var ultimo = mapa[tipo.value] || 'link';
    tipo.addEventListener('change', function () {
        var sugerido = mapa[tipo.value] || 'link';
        if (iconeMarcado() === ultimo) marcarIcone(sugerido);
        ultimo = sugerido;
    });
})();
</script>
<?php endif; ?>
</body>
</html>
