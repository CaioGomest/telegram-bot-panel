<?php declare(strict_types=1);
require_once __DIR__ . '/funcoes/usuario.php';
bloquearAdmin();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meus Fluxos - <?php echo htmlspecialchars(nomeSistema()); ?></title>
    <?php include 'tema_inline.php'; ?>
    <link rel="stylesheet" href="assets/css/painel.css?v=<?php echo @filemtime(__DIR__.'/assets/css/painel.css'); ?>">
</head>
<body>
<div class="layout-painel">
    <?php include 'barra_lateral.php'; ?>
    <main class="conteudo-principal">
        <header class="a-ph">
            <div>
                <h1 class="a-h1">Meus Fluxos</h1>
                <p class="a-sub">Crie e edite os fluxos de conversa dos seus bots.</p>
            </div>
            <div class="a-acts">
                <button type="button" class="a-btn" id="btn-importar-fluxo-lista" title="Importar um fluxo exportado (.json)">
                    <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
                    Importar Fluxo
                </button>
                <input type="file" id="arquivo-importar-fluxo-lista" accept="application/json,.json" hidden>
                <button type="button" class="a-btn pri" id="btn-abrir-escolha-modo">+ Novo Fluxo</button>
            </div>
        </header>

        <section id="lista-fluxos" class="a-cards" aria-label="Lista de fluxos" aria-live="polite">
            <div class="a-empty s-todos">Carregando fluxos...</div>
        </section>
    </main>
</div>

<div class="a-over" id="modal-escolha-modo" role="dialog" aria-modal="true" aria-labelledby="titulo-escolha-modo">
    <div class="a-modal" style="width: min(640px, 100%)">
        <div class="a-mh">
            <h2 id="titulo-escolha-modo">Como deseja criar?</h2>
            <button type="button" class="a-ib" id="btn-fechar-escolha-modo" aria-label="Fechar"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6L6 18M6 6l12 12"></path></svg></button>
        </div>
        <div class="a-opts">
            <a href="fluxo_basico" class="a-opt">
                <span class="a-ki"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"></path></svg></span>
                <b>Guiado</b>
                <span class="d">Configuração guiada de mensagens, planos, upsell e entrega.</span>
            </a>
            <a href="fluxo" class="a-opt">
                <span class="a-ki"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><line x1="6" y1="3" x2="6" y2="15"></line><circle cx="18" cy="6" r="3"></circle><circle cx="6" cy="18" r="3"></circle><path d="M18 9a9 9 0 0 1-9 9"></path></svg></span>
                <b>Editor Visual</b>
                <span class="d">Editor visual com nós, condições, esperas e caminhos personalizados.</span>
            </a>
        </div>
    </div>
</div>

<div id="toast" role="status" aria-live="polite"></div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>if (window.CSRF_TOKEN) { $.ajaxSetup({ headers: { 'X-CSRF-Token': window.CSRF_TOKEN } }); }</script>
<script src="assets/js/tema.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/tema.js'); ?>"></script>
<script src="assets/lista_fluxos.js?v=<?php echo @filemtime(__DIR__ . '/assets/lista_fluxos.js'); ?>"></script>
</body>
</html>
