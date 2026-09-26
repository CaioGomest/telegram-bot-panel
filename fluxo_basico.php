<?php declare(strict_types=1);
require_once __DIR__ . '/funcoes/usuario.php';
bloquearAdmin();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fluxo Guiado - <?php echo htmlspecialchars(nomeSistema()); ?></title>
    <?php include 'tema_inline.php'; ?>
    <link rel="stylesheet" href="assets/css/coyote.css?v=<?php echo @filemtime(__DIR__.'/assets/css/coyote.css'); ?>">
</head>
<body>
<div class="layout-painel">
    <?php include 'barra_lateral.php'; ?>

    <main class="conteudo-principal">
        <div class="cabecalho-pagina">
            <div>
                <h1>Fluxo Guiado</h1>
                <p>Preencha as seções abaixo — sem montar fluxograma.</p>
            </div>
            <div class="acoes-cabecalho">
                <a class="botao" href="fluxos">Voltar</a>
                <button class="botao" id="btn-excluir-fluxo-basico" style="display:none;">Excluir</button>
                <button class="botao botao-primario" id="btn-salvar-fluxo-basico">Salvar fluxo</button>
                <button type="button" class="alternador-tema" onclick="alternarTema()" aria-label="Alternar tema">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"></path></svg>
                    Tema
                </button>
            </div>
        </div>

        <div>
            <div class="painel">
                <div class="grade grade-2 grade-compacta">
                    <div class="campo">
                        <label for="nome-fluxo">Nome do fluxo</label>
                        <input type="text" id="nome-fluxo" placeholder="Ex.: Funil VIP">
                    </div>
                    <div class="campo">
                        <label for="descricao-fluxo">Descrição</label>
                        <input type="text" id="descricao-fluxo" placeholder="Uso interno do fluxo">
                    </div>
                </div>
                <input type="hidden" id="id-fluxo">
            </div>

            <div class="painel">
                <div class="painel-cabecalho"><h2>Boas-vindas</h2></div>
                <p class="texto-ajuda">Mensagem enviada assim que alguém dá /start no bot.</p>
                <div class="campo">
                    <label for="bv-mensagem">Mensagem inicial</label>
                    <textarea id="bv-mensagem" rows="3" placeholder="Olá! Bem-vindo(a)..."></textarea>
                </div>
                <div class="grade grade-2 grade-compacta">
                    <div class="campo">
                        <label>Mídia (opcional)</label>
                        <div class="area-previa-basico" id="bv-midia-previa" title="Clique para adicionar/trocar">Sem mídia</div>
                        <input type="file" id="bv-midia-arquivo" accept="image/*,video/*" style="display:none">
                    </div>
                    <div class="campo">
                        <label for="bv-cta">Texto do botão (CTA)</label>
                        <input type="text" id="bv-cta" placeholder="Ver Planos" value="Ver Planos">
                    </div>
                </div>
            </div>

            <div class="painel">
                <div class="painel-cabecalho"><h2>Planos</h2></div>
                <p class="texto-ajuda">O cliente escolhe um destes planos pra pagar. Pelo menos 1 é obrigatório pra vender.</p>
                <div id="lista-planos"></div>
                <button type="button" class="botao botao-claro" id="btn-adicionar-plano">+ Adicionar plano</button>
            </div>

            <div class="painel">
                <div class="painel-cabecalho"><h2>Pagamentos</h2></div>
                <div class="grade grade-2 grade-compacta">
                    <div class="campo">
                        <label for="pg-msg-instrucoes">Mensagem no Pix gerado</label>
                        <textarea id="pg-msg-instrucoes" rows="2">Copie o código Pix abaixo e pague no app do seu banco.</textarea>
                    </div>
                    <div class="campo">
                        <label for="pg-msg-confirmado">Mensagem no pagamento aprovado</label>
                        <textarea id="pg-msg-confirmado" rows="2">Pagamento confirmado! Seu acesso foi liberado.</textarea>
                    </div>
                </div>
                <div class="linha-flex">
                    <input type="checkbox" id="pg-mostrar-copiar" checked> <label style="margin:0">Botão Copiar código</label>
                </div>
                <div class="linha-flex">
                    <input type="checkbox" id="pg-mostrar-confirmar" checked> <label style="margin:0">Botão "Já fiz o pagamento"</label>
                </div>
            </div>

            <div class="painel">
                <div class="painel-cabecalho"><h2>Bots vinculados</h2></div>
                <p class="texto-ajuda">Salve o fluxo primeiro. Depois, vá em <a href="bots">Meus Bots</a> → editar o bot → campo "Fluxo Conectado" → selecione este fluxo.</p>
            </div>
        </div>
    </main>
</div>

<div id="toast"></div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>if (window.CSRF_TOKEN) { $.ajaxSetup({ headers: { 'X-CSRF-Token': window.CSRF_TOKEN } }); }</script>
<script src="assets/js/tema.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/tema.js'); ?>"></script>
<script src="assets/edicao_fluxo_basico.js?v=<?php echo @filemtime(__DIR__ . '/assets/edicao_fluxo_basico.js'); ?>"></script>
</body>
</html>
