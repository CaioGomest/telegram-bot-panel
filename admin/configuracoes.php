<?php
declare(strict_types=1);

require_once __DIR__ . '/../funcoes/usuario.php';
require_once __DIR__ . '/../funcoes/google_auth.php';
verificarAdmin();
$caminho_base = '../';

$mensagem = '';
$tipo_mensagem = '';
$mensagem_google = '';
$tipo_mensagem_google = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'identidade') {
    verificarCsrf();
    try {
        $nome = trim($_POST['nome_sistema'] ?? '');
        if ($nome === '') {
            throw new RuntimeException('O nome do sistema não pode ficar vazio.');
        }
        definirConfigSistema('nome_sistema', mb_substr($nome, 0, 60));

        $cor_primaria = trim($_POST['cor_primaria'] ?? '');
        if (!preg_match('/^#[0-9a-f]{6}$/i', $cor_primaria)) {
            throw new RuntimeException('Cor inválida.');
        }
        definirConfigSistema('cor_primaria', strtolower($cor_primaria));

        if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            definirConfigSistema('logo', salvarArquivoMarca($_FILES['logo'], 'marca_logo'));
        }
        if (isset($_FILES['favicon']) && $_FILES['favicon']['error'] === UPLOAD_ERR_OK) {
            definirConfigSistema('favicon', salvarArquivoMarca($_FILES['favicon'], 'marca_favicon'));
        }

        registrarAtividade((int) $_SESSION['usuario_id'], 'sistema', 'Identidade visual', 'Nome/logo/favicon do painel atualizados.');
        header('Location: configuracoes?salvo=1');
        exit;
    } catch (RuntimeException $e) {
        $mensagem = $e->getMessage();
        $tipo_mensagem = 'erro';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'google') {
    verificarCsrf();
    $client_id = trim($_POST['google_client_id'] ?? '');
    $client_secret = trim($_POST['google_client_secret'] ?? '');
    if ($client_id === '') {
        $mensagem_google = 'Preencha o Client ID.';
        $tipo_mensagem_google = 'erro';
    } elseif (definirCredenciaisGoogle($client_id, $client_secret)) {
        registrarAtividade((int) $_SESSION['usuario_id'], 'sistema', 'Login com Google', 'Credenciais do login com Google atualizadas.');
        header('Location: configuracoes?salvo_google=1');
        exit;
    } else {
        $mensagem_google = 'Erro ao salvar as credenciais.';
        $tipo_mensagem_google = 'erro';
    }
}

if (isset($_GET['salvo'])) {
    $mensagem = 'Identidade visual atualizada.';
    $tipo_mensagem = 'sucesso';
}
if (isset($_GET['salvo_google'])) {
    $mensagem_google = 'Credenciais do Google atualizadas.';
    $tipo_mensagem_google = 'sucesso';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configurações</title>
    <?php include __DIR__ . '/../tema_inline.php'; ?>
    <link rel="stylesheet" href="../assets/css/coyote.css?v=<?php echo @filemtime(__DIR__.'/../assets/css/coyote.css'); ?>">
</head>
<body>
<div class="layout-painel">
    <?php include __DIR__ . '/../barra_lateral.php'; ?>

    <main class="conteudo-principal">
        <div class="cabecalho-pagina">
            <div>
                <h1>Configurações</h1>
                <p>Identidade visual e integrações do painel.</p>
            </div>
            <div class="acoes-cabecalho">
                <button type="button" class="alternador-tema" onclick="alternarTema()" aria-label="Alternar tema">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"></path></svg>
                    Tema
                </button>
            </div>
        </div>

        <div class="painel" style="max-width: 700px;">
            <?php if ($mensagem): ?>
                <div class="aviso aviso-<?php echo $tipo_mensagem; ?>"><?php echo htmlspecialchars($mensagem); ?></div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data" id="form-identidade" data-confirmar="Confirma salvar essas alterações de identidade visual? Isso muda o nome, logo, favicon e/ou cor de destaque pra todo mundo que usa o painel.">
                <?php echo campoCsrf(); ?>
                <input type="hidden" name="acao" value="identidade">

                <div class="campo">
                    <label for="nome_sistema">Nome do sistema</label>
                    <input type="text" id="nome_sistema" name="nome_sistema" maxlength="60" required
                           value="<?php echo htmlspecialchars(nomeSistema()); ?>">
                    <small>Aparece na barra lateral, no topo do celular e no título das abas do navegador.</small>
                </div>

                <div class="campo" style="margin-top:18px;">
                    <label>Logo</label>
                    <div style="display:flex;align-items:center;gap:14px;margin-bottom:8px;">
                        <img src="<?php echo htmlspecialchars(logoSistema($caminho_base)); ?>" alt=""
                             style="width:56px;height:56px;border-radius:14px;object-fit:cover;border:1px solid var(--bd);"
                             onerror="this.style.display='none'">
                        <span class="texto-suave">Atual</span>
                    </div>
                    <input type="file" name="logo" accept=".png,.jpg,.jpeg,.webp">
                    <small>PNG, JPG ou WEBP, até 2 MB. Quadrada fica melhor. Deixe em branco para manter a atual.</small>
                </div>

                <div class="campo" style="margin-top:18px;">
                    <label>Favicon</label>
                    <div style="display:flex;align-items:center;gap:14px;margin-bottom:8px;">
                        <img src="<?php echo htmlspecialchars(faviconSistema($caminho_base)); ?>" alt=""
                             style="width:28px;height:28px;border-radius:7px;object-fit:cover;border:1px solid var(--bd);"
                             onerror="this.style.display='none'">
                        <span class="texto-suave">Atual</span>
                    </div>
                    <input type="file" name="favicon" accept=".png,.ico,.jpg,.jpeg,.webp">
                    <small>Ícone da aba do navegador. Sem favicon próprio, o sistema usa a logo.</small>
                </div>

                <div class="campo" style="margin-top:18px;">
                    <label for="cor_primaria">Cor de destaque</label>
                    <input type="color" id="cor_primaria" name="cor_primaria"
                           value="<?php echo htmlspecialchars(corPrimariaSistema()); ?>">
                    <small>Cor usada em botões, links e destaques no painel inteiro (claro e escuro).</small>
                </div>

                <div class="linha-acoes" style="margin-top: 22px;">
                    <button type="submit" class="botao botao-primario">Salvar</button>
                </div>
            </form>
        </div>

        <div class="painel" style="max-width: 700px; margin-top: 20px;">
            <div class="painel-cabecalho">
                <h2>Login com Google</h2>
                <span class="badge <?php echo googleLoginConfigurado() ? 'badge-sucesso' : 'badge-neutro'; ?>">
                    <?php echo googleLoginConfigurado() ? 'Ativo' : 'Não configurado'; ?>
                </span>
            </div>

            <?php if ($mensagem_google): ?>
                <div class="aviso aviso-<?php echo $tipo_mensagem_google; ?>"><?php echo htmlspecialchars($mensagem_google); ?></div>
            <?php endif; ?>

            <p class="texto-suave" style="margin:0 0 16px;line-height:1.6;">
                Pra ativar, crie um "OAuth 2.0 Client ID" em
                <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener" class="login-link-esqueci">console.cloud.google.com/apis/credentials</a>
                (tipo "Aplicativo da Web") e cole abaixo o Client ID e o Client Secret que o Google gerar.
                Em "URIs de redirecionamento autorizados", cole exatamente esta URL:
            </p>
            <div class="campo" style="margin-bottom:18px;">
                <div style="display:flex;align-items:center;gap:8px;padding:10px 12px;border:1px solid var(--bd);border-radius:10px;background:var(--p2);">
                    <span id="google-redirect-uri" class="mono" style="flex:1;word-break:break-all;font-size:12.5px;">
                        <?php echo htmlspecialchars(googleRedirectUri()); ?>
                    </span>
                    <button type="button" class="botao" id="btn-copiar-redirect-uri" style="flex-shrink:0;">Copiar</button>
                </div>
                <small>Tem que bater caractere por caractere com o que está cadastrado no Google, incluindo https.</small>
            </div>

            <form method="POST" id="form-google" data-confirmar="Confirma salvar essas credenciais do login com Google? Se estiverem erradas, o botão de login com Google pode parar de funcionar pra quem já usa.">
                <?php echo campoCsrf(); ?>
                <input type="hidden" name="acao" value="google">

                <div class="campo">
                    <label for="google_client_id">Client ID</label>
                    <input type="text" id="google_client_id" name="google_client_id" autocomplete="off"
                           value="<?php echo htmlspecialchars(googleClientId()); ?>" placeholder="xxxxxxxxxx.apps.googleusercontent.com">
                </div>

                <div class="campo" style="margin-top:16px;">
                    <label for="google_client_secret">Client Secret</label>
                    <input type="password" id="google_client_secret" name="google_client_secret"
                           autocomplete="new-password" data-lpignore="true" data-1p-ignore
                           placeholder="<?php echo googleClientSecret() !== '' ? '•••••••• (salvo)' : ''; ?>">
                    <?php if (googleClientSecret() !== ''): ?>
                        <small>Deixe em branco para manter o atual.</small>
                    <?php endif; ?>
                </div>

                <div class="linha-acoes" style="margin-top: 22px;">
                    <button type="submit" class="botao botao-primario">Salvar</button>
                </div>
            </form>
        </div>
    </main>
</div>

<div class="sobreposicao-modal" id="modal-confirmar-config">
    <div class="modal-gateway" style="max-width:420px;">
        <div class="cabecalho-modal">
            <span class="titulo-modal">Confirmar alteração</span>
            <button type="button" class="fechar-modal" id="btn-fechar-confirmar-config">✕</button>
        </div>
        <div class="corpo-modal">
            <p id="texto-confirmar-config" style="margin:0 0 18px;line-height:1.6;"></p>
            <div class="linha-acoes">
                <button type="button" class="botao" id="btn-cancelar-confirmar-config">Cancelar</button>
                <button type="button" class="botao botao-primario" id="btn-confirmar-confirmar-config">Confirmar e salvar</button>
            </div>
        </div>
    </div>
</div>

<script src="../assets/js/tema.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/tema.js'); ?>"></script>
<script>
// Segunda confirmação antes de salvar: essa tela muda a identidade visual pra todo mundo
// (nome/logo/favicon/cor) e as credenciais do login com Google -- um clique errado aqui
// tem efeito em todos os usuários do painel, ou pode quebrar o login com Google de quem
// já usa. Intercepta o submit, mostra o que vai mudar, e só envia de fato no "Confirmar".
(function () {
    var modal = document.getElementById('modal-confirmar-config');
    var texto = document.getElementById('texto-confirmar-config');
    var btnConfirmar = document.getElementById('btn-confirmar-confirmar-config');
    var btnCancelar = document.getElementById('btn-cancelar-confirmar-config');
    var btnFechar = document.getElementById('btn-fechar-confirmar-config');
    var formPendente = null;

    function abrirConfirmacao(form) {
        formPendente = form;
        texto.textContent = form.dataset.confirmar || 'Confirma salvar essas alterações?';
        modal.classList.add('aberto');
    }
    function fecharConfirmacao() {
        formPendente = null;
        modal.classList.remove('aberto');
    }

    ['form-identidade', 'form-google'].forEach(function (id) {
        var form = document.getElementById(id);
        if (!form) return;
        form.addEventListener('submit', function (e) {
            if (form.dataset.confirmado === '1') return; // já confirmado, deixa enviar
            e.preventDefault();
            abrirConfirmacao(form);
        });
    });

    btnConfirmar.addEventListener('click', function () {
        if (!formPendente) return;
        formPendente.dataset.confirmado = '1';
        formPendente.submit();
        fecharConfirmacao();
    });
    btnCancelar.addEventListener('click', fecharConfirmacao);
    btnFechar.addEventListener('click', fecharConfirmacao);
    modal.addEventListener('click', function (e) {
        if (e.target === modal) fecharConfirmacao();
    });
})();
</script>
<script>
// Mesmo padrão em duas etapas já usado em assets/links_rastreamento.js -- Clipboard API
// quando disponível (precisa de contexto seguro, https), senão volta pro jeito antigo via
// textarea temporária + execCommand. Achado no celular: a URL não cabia numa linha só e
// não dava pra ver/copiar o resto sem um botão de verdade.
(function () {
    var btn = document.getElementById('btn-copiar-redirect-uri');
    if (!btn) return;
    var texto = document.getElementById('google-redirect-uri').textContent.trim();

    function avisar() {
        var original = btn.textContent;
        btn.textContent = 'Copiado!';
        setTimeout(function () { btn.textContent = original; }, 1500);
    }

    btn.addEventListener('click', function () {
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(texto).then(avisar).catch(function () {
                copiarFallback(texto, avisar);
            });
        } else {
            copiarFallback(texto, avisar);
        }
    });

    function copiarFallback(valor, callback) {
        var tmp = document.createElement('textarea');
        tmp.value = valor;
        document.body.appendChild(tmp);
        tmp.select();
        document.execCommand('copy');
        document.body.removeChild(tmp);
        callback();
    }
})();
</script>
</body>
</html>
