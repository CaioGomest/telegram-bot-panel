<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/funcoes/usuario.php';
require_once __DIR__ . '/funcoes/google_auth.php';

if (usuarioLogado()) {
    if (ehAdmin()) {
        header('Location: admin/dashboard');
    } else {
        header('Location: index');
    }
    exit;
}

$erro_login = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'login') {
    verificarCsrf();
    $email = $_POST['email'] ?? '';
    $senha = $_POST['senha'] ?? '';
    $lembrar = !empty($_POST['lembrar']);
    if (loginEstaBloqueado($email)) {
        $erro_login = 'bloqueado';
    } elseif (fazerLogin($email, $senha, $lembrar)) {
        if (ehAdmin()) {
            header('Location: admin/dashboard');
        } else {
            header('Location: index');
        }
        exit;
    } else {
        $erro_login = 'credenciais';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?php echo htmlspecialchars(nomeSistema()); ?></title>
    <?php include 'tema_inline.php'; ?>
    <link rel="stylesheet" href="assets/css/painel.css?v=<?php echo @filemtime(__DIR__.'/assets/css/painel.css'); ?>">
</head>
<body>
<button type="button" class="alternador-tema alternador-tema-flutuante" onclick="alternarTema()" aria-label="Alternar tema" style="position:absolute;top:20px;right:20px;z-index:2;">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"></path></svg>
    Tema
</button>

<div class="tela-login">
    <div class="login-marca">
        <img src="<?php echo htmlspecialchars(logoSistema()); ?>" alt="<?php echo htmlspecialchars(nomeSistema()); ?>" class="login-logo">
        <h1 class="login-titulo">Seus bots vendendo no automático</h1>
        <p class="login-descricao">Fluxos, PIX, remarketing e leads em um só painel. Entre para continuar de onde parou.</p>
    </div>

    <div class="login-formulario">
        <div class="login-formulario-conteudo">
            <div class="login-cabecalho-mobile">
                <img src="<?php echo htmlspecialchars(logoSistema()); ?>" alt="<?php echo htmlspecialchars(nomeSistema()); ?>" class="login-cabecalho-logo">
                <button type="button" class="alternador-tema" onclick="alternarTema()" aria-label="Alternar tema">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"></path></svg>
                </button>
            </div>
            <h2>Entrar no painel</h2>
            <p class="texto-suave" style="margin:8px 0 0;">Bem-vindo de volta. Continue de onde parou.</p>
            <nav class="login-abas">
                <a href="login" class="login-aba ativa">Entrar</a>
                <a href="cadastro" class="login-aba">Criar conta</a>
            </nav>

            <?php if ($erro_login || isset($_GET['erro'])): ?>
                <div class="aviso aviso-erro">
                    <?php
                    $erro_code = $erro_login ?? ($_GET['erro'] ?? '');
                    switch ($erro_code) {
                        case 'credenciais':
                            echo 'Email ou senha incorretos.';
                            break;
                        case 'bloqueado':
                            echo 'Muitas tentativas de login. Aguarde alguns minutos e tente novamente.';
                            break;
                        case 'acesso':
                            echo 'Você precisa fazer login para acessar esta página.';
                            break;
                        case 'google_sessao_expirada':
                            echo 'A sessão expirou antes de voltar do Google. Tente entrar com Google de novo.';
                            break;
                        case 'google_falhou':
                            echo 'Não foi possível entrar com o Google. Tente novamente.';
                            break;
                        case 'google_nao_configurado':
                            echo 'Login com Google ainda não está configurado neste painel.';
                            break;
                        default:
                            echo 'Erro no login. Tente novamente.';
                    }
                    ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['sucesso']) && $_GET['sucesso'] === 'cadastro'): ?>
                <div class="aviso aviso-sucesso">Cadastro realizado com sucesso! Faça login.</div>
            <?php endif; ?>

            <form method="POST" action="login">
                <?php echo campoCsrf(); ?>
                <input type="hidden" name="acao" value="login">

                <div class="campo">
                    <label for="email">E-mail</label>
                    <input type="email" id="email" name="email" required placeholder="voce@email.com"
                           value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                </div>

                <div class="campo" style="margin-top:16px;">
                    <label for="senha">Senha</label>
                    <div class="campo-com-acao">
                        <input type="password" id="senha" name="senha" required>
                        <button type="button" class="botao" id="btn-toggle-senha" aria-label="Mostrar senha">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                        </button>
                    </div>
                </div>

                <div class="login-opcoes">
                    <label class="chave-gateway laranja" title="Lembrar de mim">
                        <input type="checkbox" id="lembrar" name="lembrar" value="1">
                        <span class="chave-gateway-trilho"></span>
                    </label>
                    <span class="texto-suave" style="margin-right:auto;margin-left:8px;">Lembrar</span>
                    <a href="#" class="login-link-esqueci" onclick="abrirEsqueciSenha(); return false;">Esqueci a senha</a>
                </div>

                <button type="submit" class="botao botao-primario botao-bloco" style="margin-top:20px;padding:15px;">Entrar</button>
            </form>

            <?php if (googleLoginConfigurado()): ?>
                <div class="login-divisor"><span>ou</span></div>
                <a href="google_login" class="botao botao-google botao-bloco">
                    <svg width="18" height="18" viewBox="0 0 24 24"><path fill="#4285F4" d="M23.52 12.27c0-.85-.08-1.67-.22-2.46H12v4.65h6.47a5.54 5.54 0 0 1-2.4 3.63v3h3.88c2.27-2.09 3.57-5.17 3.57-8.82z"/><path fill="#34A853" d="M12 24c3.24 0 5.96-1.07 7.95-2.91l-3.88-3a7.4 7.4 0 0 1-11-3.89H1.1v3.09A12 12 0 0 0 12 24z"/><path fill="#FBBC05" d="M5.07 14.2a7.2 7.2 0 0 1 0-4.4V6.71H1.1a12 12 0 0 0 0 10.58z"/><path fill="#EA4335" d="M12 4.75c1.76 0 3.35.61 4.6 1.8l3.44-3.44C17.95 1.19 15.24 0 12 0A12 12 0 0 0 1.1 6.71l3.97 3.09A7.16 7.16 0 0 1 12 4.75z"/></svg>
                    Continuar com Google
                </a>
            <?php endif; ?>

            <p style="margin:18px 0 0;text-align:center;font-size:12.5px;" class="texto-suave login-rodape-alternar">
                Não tem conta? <a href="cadastro" class="login-link-esqueci">Cadastre-se</a>
            </p>
        </div>
    </div>
</div>

<div id="modal-esqueci-senha" class="sobreposicao-modal" onclick="if (event.target === this) fecharEsqueciSenha()">
    <div class="modal-gateway">
        <div class="cabecalho-modal">
            <div class="titulo-modal">Recuperar senha</div>
            <button type="button" class="fechar-modal" onclick="fecharEsqueciSenha()" aria-label="Fechar">✕</button>
        </div>
        <div class="corpo-modal">
            <div class="rec-form">
                <div id="rec-aviso" class="aviso" role="alert" hidden></div>

                <div id="rec-passo-email">
                    <p class="texto-suave rec-intro">Informe o e-mail da sua conta. Enviaremos um código de 6 dígitos, que vale por 15 minutos.</p>
                </div>
                <div class="campo">
                    <label for="rec-email">E-mail</label>
                    <div class="campo-com-acao">
                        <input type="email" id="rec-email" placeholder="seu@email.com" autocomplete="email" maxlength="190">
                        <button type="button" id="rec-enviar" class="botao botao-primario">Enviar código</button>
                    </div>
                </div>

                <div id="rec-passo-codigo" class="rec-form" hidden>
                    <div class="campo">
                        <label for="rec-codigo">Código</label>
                        <input type="text" id="rec-codigo" class="rec-codigo" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="000000">
                        <small class="texto-suave">Não chegou? Confira também a caixa de spam.</small>
                    </div>
                    <div class="campo">
                        <label for="rec-nova">Nova senha</label>
                        <input type="password" id="rec-nova" autocomplete="new-password" placeholder="Mínimo de 8 caracteres">
                    </div>
                    <div class="campo">
                        <label for="rec-confirmar">Confirmar nova senha</label>
                        <input type="password" id="rec-confirmar" autocomplete="new-password">
                    </div>
                </div>

                <div class="linha-acoes" id="rec-acoes" hidden>
                    <button type="button" id="rec-salvar" class="botao botao-primario">Salvar nova senha</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="assets/js/tema.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/tema.js'); ?>"></script>
<script>
(function () {
    var ESPERA_PADRAO = 60;
    var el = function (id) { return document.getElementById(id); };
    var proximoEnvioEm = 0;      // quando o botão "Enviar código" volta a valer (o servidor também impõe a espera)
    var emailDoPedido = '';      // e-mail do último pedido: se a pessoa troca o e-mail, a espera do anterior não vale
    var relogio = null;
    var enviando = false, salvando = false;

    function aviso(tipo, texto) {
        var a = el('rec-aviso');
        if (!texto) { a.hidden = true; a.textContent = ''; return; }
        a.className = 'aviso aviso-' + tipo;
        a.textContent = texto;
        a.hidden = false;
    }
    function emailValido(v) { return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v); }

    function atualizarBotaoEnviar() {
        var b = el('rec-enviar');
        var restante = Math.ceil((proximoEnvioEm - Date.now()) / 1000);
        var mesmoEmail = el('rec-email').value.trim().toLowerCase() === emailDoPedido;
        if (enviando) { b.disabled = true; b.textContent = 'Enviando…'; return; }
        if (restante > 0 && mesmoEmail) {
            b.disabled = true;
            b.textContent = 'Reenviar em ' + restante + 's';
            return;
        }
        b.disabled = false;
        b.textContent = emailDoPedido && mesmoEmail ? 'Reenviar código' : 'Enviar código';
        if (restante <= 0 && relogio) { clearInterval(relogio); relogio = null; }
    }
    function iniciarEspera(segundos) {
        proximoEnvioEm = Date.now() + segundos * 1000;
        if (relogio) clearInterval(relogio);
        relogio = setInterval(atualizarBotaoEnviar, 500);
        atualizarBotaoEnviar();
    }

    window.abrirEsqueciSenha = function () {
        var login = el('email');
        if (login && !el('rec-email').value) el('rec-email').value = login.value || '';
        aviso('', '');
        el('modal-esqueci-senha').classList.add('aberto');
        setTimeout(function () { el('rec-email').focus(); }, 50);
        atualizarBotaoEnviar();
    };
    window.fecharEsqueciSenha = function () {
        el('modal-esqueci-senha').classList.remove('aberto');
    };

    function enviarCodigo() {
        if (enviando) return;
        var email = el('rec-email').value.trim();
        if (!emailValido(email)) { aviso('erro', 'Informe um e-mail válido.'); el('rec-email').focus(); return; }
        enviando = true; aviso('', ''); atualizarBotaoEnviar();
        fetch('funcoes/usuario.php?api=usuario&acao=enviar_codigo_senha_login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email: email }) })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                enviando = false;
                emailDoPedido = email.toLowerCase();
                if (j.sucesso) {
                    aviso('sucesso', j.mensagem || 'Se esse e-mail estiver cadastrado, enviamos um código.');
                    el('rec-passo-codigo').hidden = false;
                    el('rec-acoes').hidden = false;
                    el('rec-passo-email').hidden = true;
                    setTimeout(function () { el('rec-codigo').focus(); }, 50);
                    iniciarEspera(j.espera || ESPERA_PADRAO);
                } else {
                    aviso('erro', j.erro || 'Não foi possível enviar agora.');
                    if (j.espera) iniciarEspera(j.espera); else atualizarBotaoEnviar();
                }
            })
            .catch(function () {
                enviando = false;
                aviso('erro', 'Sem conexão com o servidor. Tente de novo.');
                atualizarBotaoEnviar();
            });
    }

    function salvar() {
        if (salvando) return;
        var email = el('rec-email').value.trim();
        var codigo = el('rec-codigo').value.trim();
        var nova = el('rec-nova').value;
        var conf = el('rec-confirmar').value;
        if (!/^\d{6}$/.test(codigo)) { aviso('erro', 'Digite o código de 6 dígitos que chegou no e-mail.'); el('rec-codigo').focus(); return; }
        if (nova.length < 8) { aviso('erro', 'A nova senha precisa ter pelo menos 8 caracteres.'); el('rec-nova').focus(); return; }
        if (nova !== conf) { aviso('erro', 'As senhas não conferem.'); el('rec-confirmar').focus(); return; }
        salvando = true; aviso('', '');
        var b = el('rec-salvar'); b.disabled = true; b.textContent = 'Salvando…';
        fetch('funcoes/usuario.php?api=usuario&acao=trocar_senha_login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email: email, codigo: codigo, nova_senha: nova }) })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                salvando = false; b.disabled = false; b.textContent = 'Salvar nova senha';
                if (j.sucesso) {
                    aviso('sucesso', 'Senha atualizada! Entre com a nova senha.');
                    var login = el('email'); if (login) login.value = email;
                    b.disabled = true;
                    setTimeout(function () { fecharEsqueciSenha(); location.reload(); }, 1800);
                } else {
                    aviso('erro', j.erro || 'Não foi possível salvar.');
                }
            })
            .catch(function () {
                salvando = false; b.disabled = false; b.textContent = 'Salvar nova senha';
                aviso('erro', 'Sem conexão com o servidor. Tente de novo.');
            });
    }

    document.addEventListener('DOMContentLoaded', function () {
        el('rec-enviar').addEventListener('click', enviarCodigo);
        el('rec-salvar').addEventListener('click', salvar);
        el('rec-email').addEventListener('input', atualizarBotaoEnviar);
        el('rec-codigo').addEventListener('input', function () { this.value = this.value.replace(/\D/g, '').slice(0, 6); });
        el('modal-esqueci-senha').addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { fecharEsqueciSenha(); return; }
            if (e.key !== 'Enter') return;
            e.preventDefault();
            if (!el('rec-passo-codigo').hidden && e.target.id !== 'rec-email') salvar(); else enviarCodigo();
        });
    });
})();
(function(){
    var btn = document.getElementById('btn-toggle-senha');
    var inp = document.getElementById('senha');
    if (btn && inp) {
        btn.addEventListener('click', function(){
            var visivel = inp.getAttribute('type') === 'text';
            inp.setAttribute('type', visivel ? 'password' : 'text');
        });
    }
    try {
        var lembrar = document.getElementById('lembrar');
        var email = document.getElementById('email');
        var pref = localStorage.getItem('loginPref');
        if (pref) {
            var j = JSON.parse(pref);
            if (j.email) email.value = j.email;
            if (j.lembrar) lembrar.checked = true;
        }
        document.querySelector('form').addEventListener('submit', function(){
            if (lembrar && lembrar.checked) {
                localStorage.setItem('loginPref', JSON.stringify({ email: email.value, lembrar: true }));
            } else {
                localStorage.removeItem('loginPref');
            }
        });
    } catch(e) {}
})();
</script>
</body>
</html>
