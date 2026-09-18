<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/funcoes/usuario.php';

if (usuarioLogado()) {
    header('Location: index.php');
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
    <title>Cadastro - Coyote Bot</title>
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
        <img src="assets/img/coyote-logo.jpg" alt="Coyote Bot" class="login-logo">
        <h1 class="login-titulo">Junte-se à plataforma</h1>
        <p class="login-descricao">Crie sua conta e comece a gerenciar seus bots e fluxos de mensagens com eficiência.</p>
    </div>

    <div class="login-formulario">
        <div class="login-formulario-conteudo">
            <h2>Criar conta</h2>
            <p class="texto-suave" style="margin:6px 0 22px;">Novo usuário do painel.</p>

            <?php if ($erro): ?>
                <div class="aviso aviso-erro"><?php echo htmlspecialchars($erro); ?></div>
            <?php endif; ?>

            <?php if ($sucesso): ?>
                <div class="aviso aviso-sucesso">
                    <?php echo htmlspecialchars($sucesso); ?>
                    <br><a href="login.php" class="login-link-esqueci">Ir para login</a>
                </div>
            <?php endif; ?>

            <?php if (!$sucesso): ?>
            <form method="POST">
                <?php echo campoCsrf(); ?>
                <div class="campo">
                    <label for="nome">Nome Completo</label>
                    <input type="text" id="nome" name="nome" required
                           value="<?php echo htmlspecialchars($_POST['nome'] ?? ''); ?>">
                </div>

                <div class="campo" style="margin-top:16px;">
                    <label for="email">E-mail</label>
                    <input type="email" id="email" name="email" required
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

                <button type="submit" class="botao botao-primario botao-bloco" style="margin-top:20px;padding:15px;">Criar Conta</button>
            </form>
            <?php endif; ?>

            <p style="margin:18px 0 0;text-align:center;font-size:12.5px;" class="texto-suave">
                Já tem uma conta? <a href="login.php" class="login-link-esqueci">Fazer login</a>
            </p>
        </div>
    </div>
</div>

<script src="assets/js/tema.js"></script>
</body>
</html>
