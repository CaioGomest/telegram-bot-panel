<?php declare(strict_types=1);
require_once __DIR__ . '/funcoes/usuario.php';
bloquearAdmin();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editor de Fluxos</title>
    <?php include 'tema_inline.php'; ?>
    <link rel="stylesheet" href="https://code.jquery.com/ui/1.13.3/themes/base/jquery-ui.css">
    <link rel="stylesheet" href="assets/vendor/jquery.flowchart.css">
    <link rel="stylesheet" href="assets/css/coyote.css?v=<?php echo @filemtime(__DIR__.'/assets/css/coyote.css'); ?>">
    <link rel="stylesheet" href="assets/fluxograma_tema.css?v=<?= (int) filemtime(__DIR__ . '/assets/fluxograma_tema.css') ?>">
</head>
<body>
<div class="layout-painel" id="pagina-edicao-fluxo">
    <?php include 'barra_lateral.php'; ?>

    <main class="conteudo-principal">
        <div class="cabecalho-pagina">
            <div>
                <h1>Editor de Fluxos</h1>
                <p id="subtitulo-editor-fluxo">Monte a conversa do bot arrastando blocos para o canvas.</p>
            </div>
            <div class="acoes-cabecalho">
                <a class="botao" href="fluxos">Voltar</a>
                <button type="button" class="alternador-tema" onclick="alternarTema()" aria-label="Alternar tema">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"></path></svg>
                    Tema
                </button>
            </div>
        </div>


        <div>
        <div class="painel painel-meta-fluxo">
            <div class="linha-meta-fluxo">
                <button type="button" class="btn-toggle-meta-fluxo" id="btn-toggle-flow-meta">
                    <span class="texto-nome-fluxo-colapsado" id="texto-nome-fluxo-colapsado">Novo fluxo</span>
                    <span class="texto-hint-flow-meta" id="texto-hint-flow-meta">Nome e descrição</span>
                    <svg class="chevron-flow-meta" id="chevron-flow-meta" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 9l6 6 6-6"></path></svg>
                </button>
                <div class="acoes-flow-meta">
                    <button class="botao" id="btn-importar-fluxo" title="Importar">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"></path></svg>
                        Importar
                    </button>
                    <input type="file" id="arquivo-importar-fluxo" accept="application/json" style="display:none">
                    <button class="botao" id="btn-exportar-fluxo" title="Exportar">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"></path></svg>
                        Exportar
                    </button>
                    <button class="btn-icon excluir" id="btn-excluir-fluxo" title="Excluir fluxo">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14"></path></svg>
                    </button>
                    <button class="botao botao-primario" id="btn-salvar-fluxo">Salvar fluxo</button>
                </div>
            </div>
            <div class="secao-detalhes-fluxo" id="secao-detalhes-fluxo" hidden>
                <div class="grade grade-2 grade-compacta">
                    <div class="campo">
                        <label for="nome-fluxo">Nome do fluxo</label>
                        <input type="text" id="nome-fluxo" placeholder="Ex.: Atendimento inicial">
                    </div>
                    <div class="campo">
                        <label for="descricao-fluxo">Descrição</label>
                        <input type="text" id="descricao-fluxo" placeholder="Uso interno do fluxo">
                    </div>
                </div>
            </div>
            <input type="hidden" id="id-fluxo">
            <input type="hidden" id="link-suporte-fluxo">
        </div>

        <div class="paleta-blocos">
            <span class="paleta-blocos-titulo">Arraste um bloco</span>
            <button class="botao adicionar-no" data-node-type="message">Mensagem</button>
            <button class="botao adicionar-no" data-node-type="image">Imagem</button>
            <button class="botao adicionar-no" data-node-type="video">Vídeo</button>
            <button class="botao adicionar-no" data-node-type="audio">Áudio</button>
            <button class="botao adicionar-no" data-node-type="botoes">Botões</button>
            <button class="botao adicionar-no" data-node-type="pix">PIX</button>
            <button class="botao adicionar-no" data-node-type="link">Links</button>
            <button class="botao adicionar-no" data-node-type="grupo">Grupo</button>
            <button class="botao adicionar-no" data-node-type="delay">Delay</button>
            <button class="botao adicionar-no" data-node-type="randomizer">Randomizer</button>
            <button class="botao adicionar-no" data-node-type="upsell">Upsell</button>
            <button class="botao adicionar-no" data-node-type="downsell">Downsell</button>
            <button class="botao adicionar-no" data-node-type="order_bump">Order Bump</button>
        </div>

        <div class="conteiner-fluxo">
            <div id="area-trabalho-fluxograma"></div>
        </div>
        </div>
    </main>
</div>

<div id="toast"></div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>if (window.CSRF_TOKEN) { $.ajaxSetup({ headers: { 'X-CSRF-Token': window.CSRF_TOKEN } }); }</script>
<script src="https://code.jquery.com/ui/1.13.3/jquery-ui.min.js"></script>
<script src="assets/vendor/jquery.flowchart.js?v=<?php echo @filemtime(__DIR__ . '/assets/vendor/jquery.flowchart.js'); ?>"></script>
<script src="assets/js/tema.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/tema.js'); ?>"></script>
<script src="assets/edicao_fluxo.js?v=<?= (int) filemtime(__DIR__ . '/assets/edicao_fluxo.js') ?>"></script>
</body>
</html>
