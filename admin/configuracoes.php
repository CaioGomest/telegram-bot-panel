<?php declare(strict_types=1);
require_once __DIR__ . '/../funcoes/usuario.php';
verificarAdmin();
$caminho_base = '../';
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
                <p>Gerencie suas preferências.</p>
            </div>
            <div class="acoes-cabecalho">
                <button type="button" class="alternador-tema" onclick="alternarTema()" aria-label="Alternar tema">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"></path></svg>
                    Tema
                </button>
            </div>
        </div>

        <div class="painel" style="max-width: 700px;">
            <form>
                <div class="grade grade-2">
                    <div class="campo">
                        <label>Nome do Usuário</label>
                        <input type="text" value="Administrador" disabled>
                    </div>
                    <div class="campo">
                        <label>E-mail</label>
                        <input type="email" value="admin@admin.com" disabled>
                    </div>
                    <div class="campo completo">
                        <label>Nova Senha</label>
                        <input type="password" placeholder="Digite para alterar a senha...">
                    </div>
                </div>
                <div class="linha-acoes" style="margin-top: 20px;">
                    <button type="button" class="botao botao-primario" onclick="alert('Funcionalidade em desenvolvimento!')">Salvar Alterações</button>
                </div>
            </form>
        </div>
    </main>
</div>

<script src="../assets/js/tema.js"></script>
</body>
</html>
