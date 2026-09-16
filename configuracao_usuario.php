<?php
declare(strict_types=1);
require_once __DIR__ . '/funcoes/usuario.php';

verificarLogin();

$usuario_id = $_SESSION['usuario_id'];
$mensagem = '';
$tipo_mensagem = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome = trim($_POST['nome'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $senha = $_POST['senha'] ?? '';
    $confirmar_senha = $_POST['confirmar_senha'] ?? '';
    
    if (!empty($senha) && $senha !== $confirmar_senha) {
        $mensagem = 'As senhas não conferem.';
        $tipo_mensagem = 'erro';
    } else {
        $resultado = atualizarPerfilUsuario($usuario_id, $nome, $email, empty($senha) ? null : $senha);
        if ($resultado['sucesso']) {
            $mensagem = 'Perfil atualizado com sucesso!';
            $tipo_mensagem = 'sucesso';
            $dados_usuario = obterDetalhesUsuario($usuario_id);
        } else {
            $mensagem = $resultado['erro'];
            $tipo_mensagem = 'erro';
            // Manter dados antigos em caso de erro, mas talvez o user queira ver o que digitou?
            // Por simplicidade, recarregamos do banco, o usuário terá que redigitar se errou algo (exceto senha)
            $dados_usuario = obterDetalhesUsuario($usuario_id);
        }
    }
} else {
    $dados_usuario = obterDetalhesUsuario($usuario_id);
}

if (empty($dados_usuario)) {
    // Caso raro onde o usuário da sessão não existe mais no banco
    fazerLogout();
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Minha Conta - Configurações</title>
    <?php include 'tema_inline.php'; ?>
    <link rel="stylesheet" href="assets/css/coyote.css">
</head>
<body>
<div class="layout-painel">
    <?php include 'barra_lateral.php'; ?>

    <main class="conteudo-principal">
        <div class="cabecalho-pagina">
            <div>
                <h1>Minha Conta</h1>
                <p>Gerencie seus dados pessoais e senha.</p>
            </div>
            <div class="acoes-cabecalho">
                <button type="button" class="alternador-tema" onclick="alternarTema()" aria-label="Alternar tema">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"></path></svg>
                    Tema
                </button>
            </div>
        </div>

        <div class="painel" style="max-width: 600px;">
            <?php if ($mensagem): ?>
                <div class="aviso aviso-<?php echo $tipo_mensagem; ?>">
                    <?php echo htmlspecialchars($mensagem); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="grade grade-compacta">
                    <div class="campo">
                        <label for="nome">Nome Completo</label>
                        <input type="text" id="nome" name="nome" required
                               value="<?php echo htmlspecialchars($dados_usuario['nome'] ?? ''); ?>">
                    </div>

                    <div class="campo">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" required
                               value="<?php echo htmlspecialchars($dados_usuario['email'] ?? ''); ?>">
                    </div>

                    <div class="divisor-secao">
                        <h2 style="font-size: 16px; margin-bottom: 14px;">Alterar Senha</h2>

                        <div class="grade grade-compacta">
                            <div class="campo">
                                <label for="senha">Nova Senha</label>
                                <input type="password" id="senha" name="senha" placeholder="Deixe em branco para manter a atual">
                                <span class="texto-ajuda">Mínimo de 6 caracteres.</span>
                            </div>

                            <div class="campo">
                                <label for="confirmar_senha">Confirmar Nova Senha</label>
                                <input type="password" id="confirmar_senha" name="confirmar_senha" placeholder="Repita a nova senha">
                            </div>
                        </div>
                    </div>

                    <div class="linha-acoes" style="margin-top: 6px;">
                        <button type="submit" class="botao botao-primario">Salvar Alterações</button>
                    </div>
                </div>
            </form>
        </div>
    </main>
</div>

<script src="assets/js/tema.js"></script>
</body>
</html>
