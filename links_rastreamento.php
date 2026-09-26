<?php
declare(strict_types=1);
require_once __DIR__ . '/funcoes/usuario.php';
bloquearAdmin();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Links de Rastreamento - <?php echo htmlspecialchars(nomeSistema()); ?></title>
    <?php include 'tema_inline.php'; ?>
    <link rel="stylesheet" href="assets/css/coyote.css?v=<?php echo @filemtime(__DIR__.'/assets/css/coyote.css'); ?>">
</head>
<body>
<div class="layout-painel">
    <?php include 'barra_lateral.php'; ?>
    <main class="conteudo-principal">
        <div class="cabecalho-pagina">
            <div>
                <h1>Links de Rastreamento</h1>
                <p>Crie e gerencie links de rastreamento para seus bots.</p>
            </div>
            <div class="acoes-cabecalho">
                <button type="button" class="alternador-tema" onclick="alternarTema()" aria-label="Alternar tema">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"></path></svg>
                    Tema
                </button>
            </div>
        </div>

        <div class="painel">
            <div class="painel-cabecalho">
                <div style="display:flex;align-items:center;gap:10px;">
                    <h2>Todos os links cadastrados</h2>
                    <span class="badge badge-neutro" id="contador-links">0</span>
                </div>
                <button class="botao botao-primario" id="btn-novo-link">+ Gerar novo link</button>
            </div>

            <div id="tabela-container" class="tabela-dados">
                <table>
                    <thead>
                        <tr>
                            <th>Título</th>
                            <th>Usuário bot</th>
                            <th>Vendas (quant.)</th>
                            <th>Vendas (valor)</th>
                            <th>Starts</th>
                            <th>Leads</th>
                            <th>Identificador</th>
                            <th>Link gerado</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody id="corpo-tabela">
                        <tr><td colspan="9" style="text-align:center;padding:32px;" class="texto-suave">Carregando...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>

<div class="sobreposicao-modal" id="modal-link">
    <div class="modal-gateway">
        <div class="cabecalho-modal">
            <div>
                <div class="titulo-modal" id="modal-titulo-texto">Crie um link trackeado</div>
                <p class="texto-suave" style="margin:4px 0 0;">Gere um link personalizado e acompanhe o desempenho dele em tempo real</p>
            </div>
            <button class="fechar-modal" id="btn-fechar-modal">✕</button>
        </div>
        <div class="corpo-modal">
            <p class="rotulo-kpi" style="margin:0 0 14px;">Preencha as informações da sua conta</p>

            <form id="form-link" autocomplete="off">
                <input type="hidden" id="link-id" value="">

                <div class="campo">
                    <label for="link-titulo">Título *</label>
                    <input type="text" id="link-titulo" placeholder="Ex: Link do Instagram" maxlength="255">
                </div>

                <div class="campo" style="margin-top:14px;">
                    <label for="link-identificador">Identificador *</label>
                    <input type="text" id="link-identificador" placeholder="Insira um identificador para o seu rastreamento" maxlength="100">
                    <small>Somente letras, números e hifens. Será usado como parâmetro UTM.</small>
                </div>

                <div class="campo" style="margin-top:14px;">
                    <label for="link-bot">Escolha seu bot *</label>
                    <select id="link-bot">
                        <option value="">Escolha o bot</option>
                    </select>
                </div>

                <div class="linha-acoes" style="margin-top:20px;">
                    <button type="button" class="botao" id="btn-cancelar-modal">Cancelar</button>
                    <button type="submit" class="botao botao-primario" id="btn-salvar-link">Gerar link</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div id="toast"></div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>if (window.CSRF_TOKEN) { $.ajaxSetup({ headers: { 'X-CSRF-Token': window.CSRF_TOKEN } }); }</script>
<script src="assets/js/tema.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/tema.js'); ?>"></script>
<script src="assets/links_rastreamento.js?v=<?php echo @filemtime(__DIR__ . '/assets/links_rastreamento.js'); ?>"></script>
</body>
</html>
