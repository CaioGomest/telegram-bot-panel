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
    <link rel="stylesheet" href="assets/css/coyote.css?v=<?php echo @filemtime(__DIR__.'/assets/css/coyote.css'); ?>">
</head>
<body>
<div class="layout-painel">
    <?php include 'barra_lateral.php'; ?>
    <main class="conteudo-principal">
        <div class="cabecalho-pagina">
            <div>
                <h1>Meus Fluxos</h1>
                <p>Crie e edite os fluxos de conversa dos seus bots.</p>
            </div>
            <div class="acoes-cabecalho">
                <button type="button" class="botao botao-primario" id="btn-abrir-escolha-modo">+ Novo Fluxo</button>
                <button type="button" class="alternador-tema" onclick="alternarTema()" aria-label="Alternar tema">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"></path></svg>
                    Tema
                </button>
            </div>
        </div>

        <div id="lista-fluxos" class="grade-cards">
            <div class="estado-vazio">Carregando fluxos...</div>
        </div>
    </main>
</div>

<div class="sobreposicao-modal" id="modal-escolha-modo">
    <div class="modal-gateway">
        <div class="cabecalho-modal">
            <span class="titulo-modal">Como deseja criar?</span>
            <button type="button" class="fechar-modal" id="btn-fechar-escolha-modo">✕</button>
        </div>
        <div class="corpo-modal">
            <div class="grade-escolha-modo">
                <a href="fluxo" class="cartao-escolha-modo">
                    <span class="icone-escolha-modo">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="7" height="6" rx="1.5"></rect><rect x="15" y="2" width="7" height="6" rx="1.5"></rect><rect x="15" y="14" width="7" height="6" rx="1.5"></rect><path d="M9 7h3a2 2 0 0 1 2 2v0"></path></svg>
                    </span>
                    <strong>Editor Visual</strong>
                    <span class="texto-suave">Monte o fluxo arrastando blocos num canvas — mensagens, condições, pagamentos, upsell/downsell.</span>
                </a>
                <a href="fluxo_basico" class="cartao-escolha-modo">
                    <span class="icone-escolha-modo">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"></path><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path></svg>
                    </span>
                    <strong>Guiado</strong>
                    <span class="texto-suave">Preenche um formulário por seções (boas-vindas, planos, pagamentos) — sem montar fluxograma.</span>
                </a>
            </div>
        </div>
    </div>
</div>

<div id="toast"></div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>if (window.CSRF_TOKEN) { $.ajaxSetup({ headers: { 'X-CSRF-Token': window.CSRF_TOKEN } }); }</script>
<script src="assets/js/tema.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/tema.js'); ?>"></script>
<script src="assets/lista_fluxos.js?v=<?php echo @filemtime(__DIR__ . '/assets/lista_fluxos.js'); ?>"></script>
</body>
</html>
