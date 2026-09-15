<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/funcoes/usuario.php';

if (usuarioLogado()) {
    if (ehAdmin()) {
        header('Location: admin_dashboard.php');
    } else {
        header('Location: index.php');
    }
    exit;
}

$erro_login = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'login') {
    $email = $_POST['email'] ?? '';
    $senha = $_POST['senha'] ?? '';
    if (loginEstaBloqueado($email)) {
        $erro_login = 'bloqueado';
    } elseif (fazerLogin($email, $senha)) {
        if (ehAdmin()) {
            header('Location: admin_dashboard.php');
        } else {
            header('Location: index.php');
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
    <title>Login - Telegram Bot Admin</title>
    <link rel="stylesheet" href="assets/login.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        .login-container { min-height: 100vh; display: grid; grid-template-columns: 1fr; background: var(--cor-fundo); }
        .login-hero { display: none; }
        .login-side { display:flex; align-items:center; justify-content:center; min-height:100vh; background: var(--cor-fundo); }
        body.tema-claro .login-side { background: linear-gradient(180deg, #f3f4f6 0%, #ffffff 100%); }
        body.tema-escuro .login-side { background: linear-gradient(180deg, #0f0f11 0%, #141416 100%); }
        @media (max-width: 899px) { html, body { height:100%; } body { background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%) !important; background-attachment: fixed; } .login-container { background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%) !important; } .login-side { background: transparent; } }
        @media (min-width: 900px) {
            .login-container { grid-template-columns: 1fr 1fr; }
            .login-hero { display:flex; align-items:center; justify-content:center; background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%); position:relative; overflow:hidden; }
            .login-hero::before { content:""; position:absolute; width:600px; height:600px; right:-120px; top:-120px; background: radial-gradient(circle, rgba(255,255,255,0.25), transparent 60%); filter: blur(6px); }
            .hero-content { position:relative; z-index:1; max-width:520px; padding:40px; color:#fff; display:flex; flex-direction:column; gap:18px; }
            .hero-brand { display:flex; align-items:center; gap:12px; }
            .hero-logo { width:56px; height:56px; border-radius:16px; background:#fff; color:#0284c7; display:flex; align-items:center; justify-content:center; box-shadow: 0 12px 28px rgba(17,17,17,0.35); font-size: 28px; }
            .hero-title { display:flex; flex-direction:column; line-height:1.1; }
            .hero-title .peak { font-weight:800; font-size:1.2rem; }
            .hero-title .sub { font-size:0.95rem; font-weight:700; color:#e0f2fe; }
            .hero-desc { font-size:1.05rem; font-weight:600; color:#e0f2fe; }
        }
        .login-card { background: rgba(24,24,27,0.65); color: var(--cor-texto); padding: 24px; border-radius:16px; width:100%; max-width:420px; backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); border:1px solid rgba(255,255,255,0.12); box-shadow: 0 10px 24px rgba(0,0,0,0.18); }
        body.tema-claro .login-card { background:#fff; color: var(--cor-texto); border:1px solid rgba(0,0,0,0.08); box-shadow: 0 10px 24px rgba(0,0,0,0.08); }
        .login-header { display:flex; align-items:center; gap:12px; justify-content:center; margin-bottom:18px; }
        .brand-logo { width:44px; height:44px; border-radius:12px; background: linear-gradient(180deg, rgba(2,132,199,0.35), rgba(2,132,199,0.15)); color:#fff; display:flex; align-items:center; justify-content:center; box-shadow: 0 8px 20px rgba(2,132,199,0.25); font-size: 20px; }
        .brand-text { display:flex; flex-direction:column; line-height:1.1; align-items:flex-start; }
        .brand-title { font-weight:700; color: var(--cor-texto); font-size:1rem; }
        .brand-sub { font-size:0.85rem; color: #0ea5e9; font-weight:600; }
        .form-group { margin-bottom:1rem; }
        .form-group label { display:block; margin-bottom:0.35rem; color: var(--cor-texto); font-weight:600; font-size:0.9rem; }
        .input-wrap { position:relative; }
        .form-group input { width:100%; padding:0.65rem 0.75rem; border:1px solid var(--cor-borda); border-radius:10px; font-size:1rem; background: var(--cor-fundo-secundario); color: var(--cor-texto); transition: border-color 0.2s, background-color 0.2s, box-shadow 0.2s; }
        .input-icon { position:absolute; left:12px; top:50%; transform:translateY(-50%); color: var(--cor-texto-secundario); }
        .input-wrap input { padding-left:2.2rem; }
        .toggle-senha { position:absolute; right:10px; top:50%; transform: translateY(-50%); background:none; border:1px solid rgba(0,0,0,0.06); color: var(--cor-texto-secundario); width:34px; height:34px; border-radius:8px; display:inline-flex; align-items:center; justify-content:center; cursor:pointer; }
        body.tema-claro .form-group input { background:#fff; }
        .form-group input:focus { outline:none; border-color: rgba(2,132,199,0.65); box-shadow: 0 0 0 3px rgba(2,132,199,0.25); }
        .row-opcoes { display:flex; align-items:center; justify-content:space-between; margin-top:8px; }
        .check { display:inline-flex; align-items:center; gap:8px; color: var(--cor-texto); font-size:0.9rem; }
        .check input { width:18px; height:18px; accent-color: #0284c7; }
        .btn-login { width:100%; padding:0.8rem; background: linear-gradient(180deg, #0284c7, #0369a1); color:#fff; border:1px solid rgba(2,132,199,0.35); border-radius:12px; font-size:1rem; font-weight:700; cursor:pointer; transition: transform 0.2s, box-shadow 0.2s, background-color 0.2s; box-shadow: 0 8px 20px rgba(2,132,199,0.25); }
        .btn-login:hover { transform: translateY(-1px); box-shadow: 0 12px 26px rgba(2,132,199,0.35); }
        .btn-login:active { transform: translateY(0); }
        .alert { padding:0.75rem; border-radius:10px; margin-bottom:1rem; }
        .alert-error { background-color: rgba(244,67,54,0.12); color:#b91c1c; border:1px solid rgba(244,67,54,0.25); }
        .link-esqueci { display:inline-block; margin-top:0.5rem; color: #0ea5e9; cursor:pointer; font-size:0.9rem; font-weight:600; }
        .modal { position: fixed; top:0; left:0; width:100%; height:100%; background: rgba(0,0,0,0.5); display:none; align-items:center; justify-content:center; z-index:1000; }
        .modal.ativo { display:flex; }
        .modal-conteudo { background: var(--cor-fundo-secundario, #fff); color: var(--cor-texto, #111); border-radius: 12px; width: 95%; max-width: 480px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); }
        .modal-cabecalho { display:flex; align-items:center; justify-content:space-between; padding: 16px 20px; border-bottom: 1px solid var(--cor-borda, #eee); }
        .modal-body { padding: 16px 20px; }
        .modal-actions { display:flex; gap:10px; padding: 0 20px 16px; }
        .btn { padding: 10px 12px; border-radius: 8px; border:none; cursor:pointer; }
        .btn-primario { background: #0284c7; color:#fff; font-weight:700; border:1px solid rgba(2,132,199,0.35); }
        .btn-secundario { background: var(--cor-borda, #eee); color: var(--cor-texto, #111); }
    </style>
    <link rel="preload" href="assets/login.css" as="style">
</head>
<body>
    <script>
    (function(){
        try {
            var escuro = localStorage.getItem('temaEscuro');
            if (escuro === 'true') {
                document.body.classList.add('tema-escuro');
            } else {
                document.body.classList.add('tema-claro');
            }
        } catch (e) {
            document.body.classList.add('tema-escuro');
        }
    })();
    </script>
    <div class="login-container">
        <div class="login-hero">
            <div class="hero-content">
                <div class="hero-brand">
                    <div class="hero-logo"><i class="fab fa-telegram-plane"></i></div>
                    <div class="hero-title">
                        <span class="peak">Admin Bot</span>
                        <span class="sub">Telegram Dashboard</span>
                    </div>
                </div>
                <div class="hero-desc">Gerencie seus bots e fluxos de mensagens com facilidade.</div>
            </div>
        </div>
        <div class="login-side">
        <div class="login-card">
            <div class="login-header">
                <div class="brand-logo"><i class="fab fa-telegram-plane"></i></div>
                <div class="brand-text">
                    <span class="brand-title">Admin Bot</span>
                    <span class="brand-sub">Acesso ao Sistema</span>
                </div>
            </div>
            <p style="text-align:center; color: var(--cor-texto-secundario); margin:0 0 12px 0;">Faça login para acessar sua conta</p>
            
            <?php if ($erro_login || isset($_GET['erro'])): ?>
                <div class="alert alert-error">
                    <?php 
                    $erro_code = $erro_login ?? ($_GET['erro'] ?? '');
                    switch($erro_code) {
                        case 'credenciais':
                            echo 'Email ou senha incorretos.';
                            break;
                        case 'bloqueado':
                            echo 'Muitas tentativas de login. Aguarde alguns minutos e tente novamente.';
                            break;
                        case 'acesso':
                            echo 'Você precisa fazer login para acessar esta página.';
                            break;
                        default:
                            echo 'Erro no login. Tente novamente.';
                    }
                    ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['sucesso']) && $_GET['sucesso'] === 'cadastro'): ?>
                <div class="alert alert-success">
                    Cadastro realizado com sucesso! Faça login.
                </div>
            <?php endif; ?>
            
            <form method="POST" action="login.php">
                <input type="hidden" name="acao" value="login">
                
                <div class="form-group">
                    <label for="email">Email:</label>
                    <div class="input-wrap">
                        <span class="input-icon"><i class="fas fa-envelope"></i></span>
                        <input type="email" id="email" name="email" required 
                               value="<?php echo htmlspecialchars($_POST['email'] ?? 'admin@admin.com'); ?>">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="senha">Senha:</label>
                    <div class="input-wrap">
                        <span class="input-icon"><i class="fas fa-lock"></i></span>
                        <input type="password" id="senha" name="senha" required>
                        <button type="button" class="toggle-senha" id="btn-toggle-senha"><i class="fas fa-eye-slash"></i></button>
                    </div>
                    <div class="row-opcoes">
                        <label class="check"><input type="checkbox" id="lembrar"> Lembrar-me</label>
                        <span class="link-esqueci" onclick="abrirEsqueciSenha()">Esqueci minha senha</span>
                    </div>
                </div>
                
                <button type="submit" class="btn-login">Entrar</button>
            </form>
            
            <div style="text-align: center; margin-top: 1rem; color: var(--cor-texto-secundario); font-size: 0.9rem;">
                Não tem uma conta? <a href="cadastro.php" style="color: #0ea5e9; text-decoration: none; font-weight: 600;">Cadastre-se</a>
            </div>
        </div>
        </div>
    </div>

    <div id="modal-esqueci-senha" class="modal">
        <div class="modal-conteudo">
            <div class="modal-cabecalho">
                <h3>Recuperar Senha</h3>
                <button class="btn btn-secundario" onclick="fecharEsqueciSenha()">Fechar</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" id="rec-email" placeholder="seu@email.com">
                </div>
                <div class="form-group" style="display:flex; gap:10px; align-items:center">
                    <button class="btn btn-primario" onclick="enviarCodigoRecuperacao()">Enviar código</button>
                    <span id="rec-info" style="color: var(--cor-texto-secundario, #666)"></span>
                </div>
                <div class="form-group">
                    <label>Código</label>
                    <input type="text" id="rec-codigo" placeholder="000000">
                </div>
                <div class="form-group">
                    <label>Nova Senha</label>
                    <input type="password" id="rec-nova">
                </div>
                <div class="form-group">
                    <label>Confirmar Senha</label>
                    <input type="password" id="rec-confirmar">
                </div>
            </div>
            <div class="modal-actions">
                <button class="btn btn-secundario" onclick="fecharEsqueciSenha()">Cancelar</button>
                <button class="btn btn-primario" onclick="salvarRecuperacao()">Salvar</button>
            </div>
        </div>
    </div>

    <script>
    function abrirEsqueciSenha(){
        var m = document.getElementById('modal-esqueci-senha');
        var email_login = document.getElementById('email');
        var rec = document.getElementById('rec-email');
        if (rec && email_login) rec.value = email_login.value || '';
        if (m) m.classList.add('ativo');
    }
    function fecharEsqueciSenha(){
        var m = document.getElementById('modal-esqueci-senha');
        if (m) m.classList.remove('ativo');
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
                var ic = btn.querySelector('i');
                if (ic) ic.className = visivel ? 'fas fa-eye-slash' : 'fas fa-eye';
            });
        }
        try {
            var lembrar = document.getElementById('lembrar');
            var email = document.getElementById('email');
            var senha = document.getElementById('senha');
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
