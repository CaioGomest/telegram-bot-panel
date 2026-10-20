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
        // Cada cadastro manda e-mail (boas-vindas + confirmação) para o endereço digitado: sem limite, qualquer pessoa usaria o painel para
        // encher a caixa de terceiros e gastar a cota de envio. 5 cadastros por hora por endereço de internet.
        [$permitido, $espera] = limiteTaxaConsumir('cad_ip_h:' . limiteTaxaIp(), 5, 3600);
        if (!$permitido) {
            $erro = 'Muitos cadastros deste endereço. Tente de novo em ' . limiteTaxaTextoEspera($espera) . '.';
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
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastro - <?php echo htmlspecialchars(nomeSistema()); ?></title>
    <?php include 'tema_inline.php'; ?>
    <link rel="stylesheet" href="assets/css/painel.css?v=<?php echo @filemtime(__DIR__.'/assets/css/painel.css'); ?>">
    <?php if (marcaAnimadaLogin()): ?><link rel="stylesheet" href="assets/css/coyote_animado.css?v=<?php echo @filemtime(__DIR__.'/assets/css/coyote_animado.css'); ?>"><?php endif; ?>
</head>
<body>
<button type="button" class="alternador-tema alternador-tema-flutuante" onclick="alternarTema()" aria-label="Alternar tema" style="position:absolute;top:20px;right:20px;z-index:2;">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"></path></svg>
    Tema
</button>

<div class="tela-login">
    <div class="login-marca">
        <?php if (marcaAnimadaLogin()): ?>
            <?php echo htmlMarcaAnimada(nomeSistema(), 'coy-desktop'); ?>
        <?php else: ?>
            <img src="<?php echo htmlspecialchars(logoSistema()); ?>" alt="<?php echo htmlspecialchars(nomeSistema()); ?>" class="login-logo">
        <?php endif; ?>
        <h1 class="login-titulo">Junte-se à plataforma</h1>
        <p class="login-descricao">Crie sua conta e comece a gerenciar seus bots e fluxos de mensagens com eficiência.</p>
    </div>

    <div class="login-formulario">
        <div class="login-formulario-conteudo">
            <div class="login-cabecalho-mobile">
                <?php if (marcaAnimadaLogin()): ?>
                    <?php echo htmlMarcaAnimada(nomeSistema(), 'coy-mobile'); ?>
                <?php else: ?>
                    <img src="<?php echo htmlspecialchars(logoSistema()); ?>" alt="<?php echo htmlspecialchars(nomeSistema()); ?>" class="login-cabecalho-logo">
                    <span class="login-cabecalho-nome"><?php echo htmlspecialchars(nomeSistema()); ?></span>
                <?php endif; ?>
                <span class="login-cabecalho-lema">Seus bots vendendo no automático</span>
                <button type="button" class="alternador-tema login-tema-mobile" onclick="alternarTema()" aria-label="Alternar tema">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"></path></svg>
                </button>
            </div>
            <div class="login-cartao">
            <h2>Criar sua conta</h2>
            <p class="texto-suave login-subtitulo">Leva menos de um minuto. Depois você conecta seu primeiro bot.</p>

            <?php if ($erro): ?>
                <div class="aviso aviso-erro"><?php echo htmlspecialchars($erro); ?></div>
            <?php endif; ?>

            <?php if ($sucesso): ?>
                <div class="aviso aviso-sucesso">
                    <?php echo htmlspecialchars($sucesso); ?>
                    <br><a href="login" class="login-link-esqueci">Ir para login</a>
                </div>
            <?php endif; ?>

            <?php if (!$sucesso): ?>
            <form method="POST">
                <?php echo campoCsrf(); ?>
                <div class="campo">
                    <label for="nome">Nome</label>
                    <div class="campo-icone">
                        <span class="campo-icone-simbolo" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"></circle><path d="M4 21a8 8 0 0 1 16 0"></path></svg></span>
                        <input type="text" id="nome" name="nome" required placeholder="Seu nome completo" autocomplete="name"
                               value="<?php echo htmlspecialchars($_POST['nome'] ?? ''); ?>">
                    </div>
                </div>

                <div class="campo" style="margin-top:16px;">
                    <label for="email">E-mail</label>
                    <div class="campo-icone">
                        <span class="campo-icone-simbolo" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"></circle><path d="M16 8v5a3 3 0 0 0 6 0v-1a10 10 0 1 0-3.92 7.94"></path></svg></span>
                        <input type="email" id="email" name="email" required placeholder="voce@email.com" autocomplete="email" autocapitalize="none"
                               value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                    </div>
                </div>

                <div class="campo" style="margin-top:16px;">
                    <label for="senha">Senha</label>
                    <div class="campo-icone">
                        <span class="campo-icone-simbolo" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="10" rx="2"></rect><path d="M8 11V7a4 4 0 0 1 8 0v4"></path></svg></span>
                        <input type="password" id="senha" name="senha" required placeholder="Crie uma senha" autocomplete="new-password">
                    </div>
                </div>

                <div class="campo" style="margin-top:16px;">
                    <label for="confirma_senha">Confirmar Senha</label>
                    <div class="campo-icone">
                        <span class="campo-icone-simbolo" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="10" rx="2"></rect><path d="M8 11V7a4 4 0 0 1 8 0v4"></path></svg></span>
                        <input type="password" id="confirma_senha" name="confirma_senha" required placeholder="Repita a senha" autocomplete="new-password">
                    </div>
                </div>

                <p class="texto-suave" style="margin:18px 0 0;font-size:12.5px;line-height:1.5;">Ao criar a conta você concorda com os <a href="termos" class="login-link-esqueci">termos de uso</a>.</p>
                <button type="submit" class="botao botao-primario botao-bloco login-botao-entrar">Criar conta <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"></path><path d="M13 6l6 6-6 6"></path></svg></button>
            </form>

            <?php if (googleLoginConfigurado()): ?>
                <div class="login-divisor"><span>ou</span></div>
                <a href="google_login" class="botao botao-google botao-bloco">
                    <svg width="18" height="18" viewBox="0 0 24 24"><path fill="#4285F4" d="M23.52 12.27c0-.85-.08-1.67-.22-2.46H12v4.65h6.47a5.54 5.54 0 0 1-2.4 3.63v3h3.88c2.27-2.09 3.57-5.17 3.57-8.82z"/><path fill="#34A853" d="M12 24c3.24 0 5.96-1.07 7.95-2.91l-3.88-3a7.4 7.4 0 0 1-11-3.89H1.1v3.09A12 12 0 0 0 12 24z"/><path fill="#FBBC05" d="M5.07 14.2a7.2 7.2 0 0 1 0-4.4V6.71H1.1a12 12 0 0 0 0 10.58z"/><path fill="#EA4335" d="M12 4.75c1.76 0 3.35.61 4.6 1.8l3.44-3.44C17.95 1.19 15.24 0 12 0A12 12 0 0 0 1.1 6.71l3.97 3.09A7.16 7.16 0 0 1 12 4.75z"/></svg>
                    Continuar com Google
                </a>
            <?php endif; ?>
            <?php endif; ?>
            </div>

            <p class="texto-suave login-rodape-alternar">
                Já tem uma conta? <a href="login" class="login-link-esqueci">Fazer login</a>
            </p>
            <p class="login-copyright">© <?php echo date('Y'); ?> <?php echo htmlspecialchars(nomeSistema()); ?>. Todos os direitos reservados.</p>
        </div>
    </div>
</div>

<script src="assets/js/tema.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/tema.js'); ?>"></script>
<?php if (marcaAnimadaLogin()): ?><script src="assets/js/coyote_animado.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/coyote_animado.js'); ?>" defer></script><?php endif; ?>
</body>
</html>
