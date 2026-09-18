<?php declare(strict_types=1);
require_once __DIR__ . '/funcoes/usuario.php';
verificarLogin();
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
                <p>Monte a conversa do bot arrastando blocos para o canvas.</p>
            </div>
            <div class="acoes-cabecalho">
                <a class="botao" href="fluxos.php">Voltar</a>
                <button class="botao" id="btn-excluir-fluxo">Excluir</button>
                <button class="botao" id="btn-exportar-fluxo">Exportar</button>
                <button class="botao" id="btn-importar-fluxo">Importar</button>
                <input type="file" id="arquivo-importar-fluxo" accept="application/json" style="display:none">
                <button class="botao botao-primario" id="btn-salvar-fluxo">Salvar fluxo</button>
                <button type="button" class="alternador-tema" onclick="alternarTema()" aria-label="Alternar tema">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"></path></svg>
                    Tema
                </button>
            </div>
        </div>

        <div class="aviso aviso-alerta oculto-desktop" style="margin-bottom:14px;">
            Editor de fluxo no celular: toque num bloco da paleta para adicionar e arraste o bloco no canvas para mover. Para fluxos grandes, o desktop continua mais confortável.
        </div>

        <div>
        <div class="painel">
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
            <div class="grade grade-2 grade-compacta" style="margin-top:12px;">
                <div class="campo">
                    <label for="link-suporte-fluxo">Link de Suporte (opcional)</label>
                    <input type="text" id="link-suporte-fluxo" placeholder="https://t.me/seu_usuario">
                    <p class="texto-ajuda">Usado nas mensagens automáticas de aviso/expiração de acesso enviadas pelo sistema (fora do fluxo).</p>
                </div>
            </div>
            <input type="hidden" id="id-fluxo">
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
