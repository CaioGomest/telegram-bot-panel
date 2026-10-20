<?php declare(strict_types=1);
require_once __DIR__ . '/funcoes/usuario.php';
bloquearAdmin();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meus Bots - <?php echo htmlspecialchars(nomeSistema()); ?></title>
    <?php include 'tema_inline.php'; ?>
    <link rel="stylesheet" href="assets/css/painel.css?v=<?php echo @filemtime(__DIR__.'/assets/css/painel.css'); ?>">
</head>
<body>
<div class="layout-painel">
    <?php include 'barra_lateral.php'; ?>
    <main class="conteudo-principal">
        <header class="a-ph">
            <div>
                <h1 class="a-h1">Meus Bots</h1>
                <p class="a-sub">Gerencie seus bots do Telegram aqui.</p>
            </div>
            <div class="a-acts">
                <a class="a-btn pri" href="bot" id="btn-novo-bot">+ Novo Bot</a>
            </div>
        </header>

        <section id="lista-bots" class="a-cards" aria-label="Lista de bots" aria-live="polite">
            <div class="a-empty s-todos">Carregando bots...</div>
        </section>
    </main>
</div>

<div id="toast" role="status" aria-live="polite"></div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>if (window.CSRF_TOKEN) { $.ajaxSetup({ headers: { 'X-CSRF-Token': window.CSRF_TOKEN } }); }</script>
<script src="assets/js/tema.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/tema.js'); ?>"></script>
<script src="assets/lista_bots.js?v=<?php echo @filemtime(__DIR__ . '/assets/lista_bots.js'); ?>"></script>
</body>
</html>
