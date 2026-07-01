<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/funcoes/usuario.php';

if (usuarioLogado()) {
    header('Location: index.php');
    exit;
}

$erro = null;
$sucesso = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome = $_POST['nome'] ?? '';
    $email = $_POST['email'] ?? '';
    $senha = $_POST['senha'] ?? '';
    $confirmaSenha = $_POST['confirma_senha'] ?? '';
    
    if ($senha !== $confirmaSenha) {
        $erro = 'As senhas não conferem.';
    } else {
        $resultado = criarUsuario($nome, $email, $senha);
        if ($resultado['sucesso']) {
            header('Location: login.php?sucesso=cadastro');
            exit;
        } else {
            $erro = $resultado['erro'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastro - Telegram Bot Admin</title>
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
        .btn-login { width:100%; padding:0.8rem; background: linear-gradient(180deg, #0284c7, #0369a1); color:#fff; border:1px solid rgba(2,132,199,0.35); border-radius:12px; font-size:1rem; font-weight:700; cursor:pointer; transition: transform 0.2s, box-shadow 0.2s, background-color 0.2s; box-shadow: 0 8px 20px rgba(2,132,199,0.25); }
        .btn-login:hover { transform: translateY(-1px); box-shadow: 0 12px 26px rgba(2,132,199,0.35); }
        .alert { padding:0.75rem; border-radius:10px; margin-bottom:1rem; }
        .alert-error { background-color: rgba(244,67,54,0.12); color:#b91c1c; border:1px solid rgba(244,67,54,0.25); }
        .alert-success { background-color: rgba(34,197,94,0.12); color:#15803d; border:1px solid rgba(34,197,94,0.25); }
        .link-cadastro { text-align: center; margin-top: 1rem; color: var(--cor-texto-secundario); font-size: 0.9rem; }
        .link-cadastro a { color: #0ea5e9; text-decoration: none; font-weight: 600; }
        .link-cadastro a:hover { text-decoration: underline; }
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
            document.body.classList.add('tema-claro');
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
                <div class="hero-desc">Junte-se à plataforma e gerencie seus bots com eficiência.</div>
            </div>
        </div>
        <div class="login-side">
        <div class="login-card">
            <div class="login-header">
                <div class="brand-logo"><i class="fab fa-user-plus"></i></div>
                <div class="brand-text">
                    <span class="brand-title">Criar Conta</span>
                    <span class="brand-sub">Novo Usuário</span>
                </div>
            </div>
            
            <?php if ($erro): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($erro); ?></div>
            <?php endif; ?>
            
            <?php if ($sucesso): ?>
                <div class="alert alert-success">
                    <?php echo htmlspecialchars($sucesso); ?>
                    <br><a href="login.php" style="font-weight:700; color:inherit;">Ir para login</a>
                </div>
            <?php endif; ?>
            
            <?php if (!$sucesso): ?>
            <form method="POST">
                <div class="form-group">
                    <label for="nome">Nome Completo:</label>
                    <div class="input-wrap">
                        <span class="input-icon"><i class="fas fa-user"></i></span>
                        <input type="text" id="nome" name="nome" required 
                               value="<?php echo htmlspecialchars($_POST['nome'] ?? ''); ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label for="email">Email:</label>
                    <div class="input-wrap">
                        <span class="input-icon"><i class="fas fa-envelope"></i></span>
                        <input type="email" id="email" name="email" required 
                               value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="senha">Senha:</label>
                    <div class="input-wrap">
                        <span class="input-icon"><i class="fas fa-lock"></i></span>
                        <input type="password" id="senha" name="senha" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="confirma_senha">Confirmar Senha:</label>
                    <div class="input-wrap">
                        <span class="input-icon"><i class="fas fa-lock"></i></span>
                        <input type="password" id="confirma_senha" name="confirma_senha" required>
                    </div>
                </div>
                
                <button type="submit" class="btn-login">Criar Conta</button>
            </form>
            <?php endif; ?>

            <div class="link-cadastro">
                Já tem uma conta? <a href="login.php">Fazer Login</a>
            </div>
        </div>
        </div>
    </div>
</body>
</html>
