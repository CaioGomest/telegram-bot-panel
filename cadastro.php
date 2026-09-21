<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/funcoes/usuario.php';
require_once __DIR__ . '/funcoes/google_auth.php';

if (usuarioLogado()) {
    header('Location: index');
    exit;
}

$erro = null;
$sucesso = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificarCsrf();
    $nome = $_POST['nome'] ?? '';
    $email = $_POST['email'] ?? '';
    $senha = $_POST['senha'] ?? '';
    $confirma_senha = $_POST['confirma_senha'] ?? '';

    if ($senha !== $confirma_senha) {
        $erro = 'As senhas não conferem.';
    } else {
        $resultado = criarUsuario($nome, $email, $senha);
        if ($resultado['sucesso']) {
            header('Location: login?sucesso=cadastro');
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
    <title>Cadastro - <?php echo htmlspecialchars(nomeSistema()); ?></title>
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
        <h1 class="login-titulo">Junte-se à plataforma</h1>
        <p class="login-descricao">Crie sua conta e comece a gerenciar seus bots e fluxos de mensagens com eficiência.</p>
    </div>

    <div class="login-formulario">
        <div class="login-formulario-conteudo">
            <div class="login-cabecalho-mobile">
                <img src="<?php echo htmlspecialchars(logoSistema()); ?>" alt="<?php echo htmlspecialchars(nomeSistema()); ?>" class="login-cabecalho-logo">
                <button type="button" class="alternador-tema" onclick="alternarTema()" aria-label="Alternar tema">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"></path></svg>
                </button>
            </div>
            <h2>Criar sua conta</h2>
            <p class="texto-suave" style="margin:8px 0 0;">Leva menos de um minuto. Depois você conecta seu primeiro bot.</p>
            <nav class="login-abas">
                <a href="login" class="login-aba">Entrar</a>
                <a href="cadastro" class="login-aba ativa">Criar conta</a>
            </nav>

            <?php if ($erro): ?>
                <div class="aviso aviso-erro"><?php echo htmlspecialchars($erro); ?></div>
            <?php endif; ?>

            <?php if ($sucesso): ?>
                <div class="aviso aviso-sucesso">
                    <?php echo htmlspecialchars($sucesso); ?>
                    <br><a href="login" class="login-link-esqueci">Ir para login</a>
                </div>
            <?php endif; ?>

            <?php if (!$sucesso && googleLoginConfigurado()): ?>
                <a href="google_login" class="botao botao-google botao-bloco">
                    <svg width="18" height="18" viewBox="0 0 24 24"><path fill="#4285F4" d="M23.52 12.27c0-.85-.08-1.67-.22-2.46H12v4.65h6.47a5.54 5.54 0 0 1-2.4 3.63v3h3.88c2.27-2.09 3.57-5.17 3.57-8.82z"/><path fill="#34A853" d="M12 24c3.24 0 5.96-1.07 7.95-2.91l-3.88-3a7.4 7.4 0 0 1-11-3.89H1.1v3.09A12 12 0 0 0 12 24z"/><path fill="#FBBC05" d="M5.07 14.2a7.2 7.2 0 0 1 0-4.4V6.71H1.1a12 12 0 0 0 0 10.58z"/><path fill="#EA4335" d="M12 4.75c1.76 0 3.35.61 4.6 1.8l3.44-3.44C17.95 1.19 15.24 0 12 0A12 12 0 0 0 1.1 6.71l3.97 3.09A7.16 7.16 0 0 1 12 4.75z"/></svg>
                    Continuar com Google
                </a>
                <div class="login-divisor"><span>ou cadastre-se com e-mail</span></div>
            <?php endif; ?>

            <?php if (!$sucesso): ?>
            <form method="POST">
                <?php echo campoCsrf(); ?>
                <div class="campo">
                    <label for="nome">Nome</label>
                    <input type="text" id="nome" name="nome" required placeholder="Seu nome completo"
                           value="<?php echo htmlspecialchars($_POST['nome'] ?? ''); ?>">
                </div>

                <div class="campo" style="margin-top:16px;">
                    <label for="email">E-mail</label>
                    <input type="email" id="email" name="email" required placeholder="voce@email.com"
                           value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                </div>

                <div class="campo" style="margin-top:16px;">
                    <label for="senha">Senha</label>
                    <input type="password" id="senha" name="senha" required>
                </div>

                <div class="campo" style="margin-top:16px;">
                    <label for="confirma_senha">Confirmar Senha</label>
                    <input type="password" id="confirma_senha" name="confirma_senha" required>
                </div>

                <p class="texto-suave" style="margin:18px 0 0;font-size:12.5px;line-height:1.5;">Ao criar a conta você concorda com os <a href="termos" class="login-link-esqueci">termos de uso</a>.</p>
                <button type="submit" class="botao botao-primario botao-bloco" style="margin-top:14px;padding:15px;">Criar conta</button>
            </form>
            <?php endif; ?>

            <p style="margin:18px 0 0;text-align:center;font-size:12.5px;" class="texto-suave login-rodape-alternar">
                Já tem uma conta? <a href="login" class="login-link-esqueci">Fazer login</a>
            </p>
        </div>
    </div>
</div>

<script src="assets/js/tema.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/tema.js'); ?>"></script>
</body>
</html>
