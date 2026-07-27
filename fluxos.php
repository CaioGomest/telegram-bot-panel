<?php declare(strict_types=1);
require_once __DIR__ . '/funcoes/usuario.php';
verificarLogin();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meus Fluxos</title>
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
                <h1>Meus Fluxos</h1>
                <p>Crie e edite os fluxos de conversa dos seus bots.</p>
            </div>
        </div>

        <div class="painel">
            <div class="painel-cabecalho">
                <h2>Listagem</h2>
                <a class="botao botao-primario" href="fluxo.php">Novo Fluxo</a>
            </div>
            <div id="lista-fluxos" class="grade-cards">
                <div class="estado-vazio">Carregando fluxos...</div>
            </div>
        </div>
    </main>
</div>

<div id="toast"></div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="assets/lista_fluxos.js"></script>
</body>
</html>
