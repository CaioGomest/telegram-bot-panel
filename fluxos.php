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
        <div class="cabecalho-pagina">
            <div>
                <h1>Meus Fluxos</h1>
                <p>Crie e edite os fluxos de conversa dos seus bots.</p>
            </div>
            <div class="acoes-cabecalho">
                <button type="button" class="botao" id="btn-importar-fluxo-lista" title="Importar um fluxo exportado (.json)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="15" height="15"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
                    Importar Fluxo
                </button>
                <input type="file" id="arquivo-importar-fluxo-lista" accept="application/json,.json" hidden>
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
                <a href="fluxo_basico" class="cartao-escolha-modo basico">
                    <span class="icone-escolha-modo">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"></path></svg>
                    </span>
                    <strong>Guiado</strong>
                    <span class="texto-suave">Configuração guiada de mensagens, planos, upsell e entrega.</span>
                </a>
                <a href="fluxo" class="cartao-escolha-modo visual">
                    <span class="icone-escolha-modo">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="6" y1="3" x2="6" y2="15"></line><circle cx="18" cy="6" r="3"></circle><circle cx="6" cy="18" r="3"></circle><path d="M18 9a9 9 0 0 1-9 9"></path></svg>
                    </span>
                    <strong>Editor Visual</strong>
                    <span class="texto-suave">Editor visual com nós, condições, esperas e caminhos personalizados.</span>
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
