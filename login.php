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
    <link rel="stylesheet" href="assets/css/coyote.css?v=<?php echo @filemtime(__DIR__.'/assets/css/coyote.css'); ?>">
</head>
<body>
<button type="button" class="alternador-tema" onclick="alternarTema()" aria-label="Alternar tema" style="position:absolute;top:20px;right:20px;z-index:2;">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"></path></svg>
    Tema
</button>

<div class="tela-login">
    <div class="login-marca">
        <img src="<?php echo htmlspecialchars(logoSistema()); ?>" alt="<?php echo htmlspecialchars(nomeSistema()); ?>" class="login-logo">
        <h1 class="login-titulo">Seus bots vendendo no automático</h1>
        <p class="login-descricao">Fluxos, PIX, remarketing e leads em um só painel. Entre para continuar de onde parou.</p>
        <div class="login-metricas">
            <div><div class="login-metrica-valor">1.482</div><div class="login-metrica-rotulo">Leads</div></div>
            <div><div class="login-metrica-valor">4</div><div class="login-metrica-rotulo">Bots ativos</div></div>
            <div><div class="login-metrica-valor">14,7%</div><div class="login-metrica-rotulo">Conversão</div></div>
        </div>
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

            <?php if (googleLoginConfigurado()): ?>
                <a href="google_login" class="botao botao-google botao-bloco">
                    <svg width="18" height="18" viewBox="0 0 24 24"><path fill="#4285F4" d="M23.52 12.27c0-.85-.08-1.67-.22-2.46H12v4.65h6.47a5.54 5.54 0 0 1-2.4 3.63v3h3.88c2.27-2.09 3.57-5.17 3.57-8.82z"/><path fill="#34A853" d="M12 24c3.24 0 5.96-1.07 7.95-2.91l-3.88-3a7.4 7.4 0 0 1-11-3.89H1.1v3.09A12 12 0 0 0 12 24z"/><path fill="#FBBC05" d="M5.07 14.2a7.2 7.2 0 0 1 0-4.4V6.71H1.1a12 12 0 0 0 0 10.58z"/><path fill="#EA4335" d="M12 4.75c1.76 0 3.35.61 4.6 1.8l3.44-3.44C17.95 1.19 15.24 0 12 0A12 12 0 0 0 1.1 6.71l3.97 3.09A7.16 7.16 0 0 1 12 4.75z"/></svg>
                    Continuar com Google
                </a>
                <div class="login-divisor"><span>ou entre com e-mail</span></div>
            <?php endif; ?>

            <form method="POST" action="login">
                <?php echo campoCsrf(); ?>
                <input type="hidden" name="acao" value="login">

                <div class="campo">
                    <label for="email">E-mail</label>
                    <input type="email" id="email" name="email" required placeholder="voce@email.com"
                           value="<?php echo htmlspecialchars($_POST['email'] ?? 'admin@admin.com'); ?>">
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
                    <span class="login-link-esqueci" onclick="abrirEsqueciSenha()">Esqueci a senha</span>
                </div>

                <button type="submit" class="botao botao-primario botao-bloco" style="margin-top:20px;padding:15px;">Entrar</button>
            </form>

            <p style="margin:18px 0 0;text-align:center;font-size:12.5px;" class="texto-suave login-rodape-alternar">
                Não tem conta? <a href="cadastro" class="login-link-esqueci">Cadastre-se</a>
            </p>
        </div>
    </div>
</div>

<div id="modal-esqueci-senha" class="sobreposicao-modal">
    <div class="modal-gateway">
        <div class="cabecalho-modal">
            <div class="titulo-modal">Recuperar Senha</div>
            <button class="fechar-modal" onclick="fecharEsqueciSenha()">✕</button>
        </div>
        <div class="corpo-modal">
            <div class="campo">
                <label>Email</label>
                <input type="email" id="rec-email" placeholder="seu@email.com">
            </div>
            <div class="campo" style="display:flex; gap:10px; align-items:center; flex-direction:row;">
                <button type="button" class="botao botao-primario" onclick="enviarCodigoRecuperacao()">Enviar código</button>
                <span id="rec-info" class="texto-suave"></span>
            </div>
            <div class="campo">
                <label>Código</label>
                <input type="text" id="rec-codigo" placeholder="000000">
            </div>
            <div class="campo">
                <label>Nova Senha</label>
                <input type="password" id="rec-nova">
            </div>
            <div class="campo">
                <label>Confirmar Senha</label>
                <input type="password" id="rec-confirmar">
            </div>
            <div class="linha-acoes" style="margin-top:16px;">
                <button type="button" class="botao" onclick="fecharEsqueciSenha()">Cancelar</button>
                <button type="button" class="botao botao-primario" onclick="salvarRecuperacao()">Salvar</button>
            </div>
        </div>
    </div>
</div>

<script src="assets/js/tema.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/tema.js'); ?>"></script>
<script>
function abrirEsqueciSenha(){
    var m = document.getElementById('modal-esqueci-senha');
    var email_login = document.getElementById('email');
    var rec = document.getElementById('rec-email');
    if (rec && email_login) rec.value = email_login.value || '';
    if (m) m.classList.add('aberto');
}
function fecharEsqueciSenha(){
    var m = document.getElementById('modal-esqueci-senha');
    if (m) m.classList.remove('aberto');
    document.getElementById('rec-info').textContent = '';
}
function enviarCodigoRecuperacao(){
    var email = document.getElementById('rec-email').value.trim();
    if (!email) { alert('Informe o email'); return; }
    document.getElementById('rec-info').textContent = 'Enviando...';
    fetch('funcoes/usuario.php?api=usuario&acao=enviar_codigo_senha_login', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ email: email }) })
    .then(r=>r.json()).then(j=>{ document.getElementById('rec-info').textContent = j.sucesso ? 'Código enviado' : (j.erro || 'Falha'); })
    .catch(()=>{ document.getElementById('rec-info').textContent = 'Erro'; });
}
function salvarRecuperacao(){
    var email = document.getElementById('rec-email').value.trim();
    var codigo = document.getElementById('rec-codigo').value.trim();
    var nova = document.getElementById('rec-nova').value;
    var conf = document.getElementById('rec-confirmar').value;
    if (!email || !codigo || !nova || nova !== conf) { alert('Verifique os campos'); return; }
    fetch('funcoes/usuario.php?api=usuario&acao=trocar_senha_login', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ email: email, codigo: codigo, nova_senha: nova }) })
    .then(r=>r.json()).then(j=>{ if (j.sucesso) { alert('Senha atualizada'); fecharEsqueciSenha(); } else { alert('Erro: ' + (j.erro || 'Falha')); } })
    .catch(()=>{ alert('Erro ao salvar'); });
}
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
