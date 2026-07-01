<?php declare(strict_types=1);
require_once __DIR__ . '/funcoes/usuario.php';
verificarAdmin();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configurações</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="dashboard-layout">
    <?php include 'sidebar.php'; ?>
    
    <main class="main-content">
        <div class="cabecalho-pagina">
            <div>
                <h1>Configurações</h1>
                <p>Gerencie suas preferências.</p>
            </div>
        </div>

        <div class="painel">
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
                    <div class="campo completo" style="display: none;">
                        <label>Token Padrão (Telegram)</label>
                        <input type="text" placeholder="Token global opcional...">
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
</body>
</html>
