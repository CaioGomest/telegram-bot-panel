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
    <title>Redirecionamento - <?php echo htmlspecialchars(nomeSistema()); ?></title>
    <?php include 'tema_inline.php'; ?>
    <link rel="stylesheet" href="assets/css/painel.css?v=<?php echo @filemtime(__DIR__.'/assets/css/painel.css'); ?>">
</head>
<body>
<div class="layout-painel">
    <?php include 'barra_lateral.php'; ?>
    <main class="conteudo-principal">
        <div class="cabecalho-pagina">
            <div>
                <h1>Redirecionamento</h1>
                <p>Links curtos pros seus anúncios: contam cada clique e mandam o público pro bot certo.</p>
            </div>
            <div class="acoes-cabecalho">
                <button type="button" class="alternador-tema" onclick="alternarTema()" aria-label="Alternar tema">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"></path></svg>
                    Tema
                </button>
                <button type="button" class="botao botao-primario" id="rd-btn-novo">+ Criar link</button>
            </div>
        </div>

        <div class="abas-status" style="margin-bottom:14px;">
            <button type="button" class="aba-status ativa" data-aba="links">Meus links</button>
            <button type="button" class="aba-status" data-aba="campanha">Preparar campanha</button>
        </div>

        <section id="rd-aba-links">
            <div class="grade-kpi" id="rd-kpis">
                <div class="cartao-kpi"><div class="rotulo-kpi">Links</div><div class="valor-kpi" id="rd-kpi-links">0</div></div>
                <div class="cartao-kpi"><div class="rotulo-kpi">Cliques</div><div class="valor-kpi" id="rd-kpi-cliques">0</div></div>
                <div class="cartao-kpi"><div class="rotulo-kpi">Starts no bot</div><div class="valor-kpi" id="rd-kpi-starts">0</div></div>
                <div class="cartao-kpi"><div class="rotulo-kpi">Vendas</div><div class="valor-kpi" id="rd-kpi-vendas">R$ 0,00</div></div>
            </div>

            <div class="painel">
                <div class="painel-cabecalho">
                    <div style="display:flex;align-items:center;gap:10px;">
                        <h2>Links e campanhas</h2>
                        <span class="badge badge-neutro" id="rd-contador">0</span>
                    </div>
                </div>
                <div id="rd-lista"></div>
            </div>
        </section>

        <section id="rd-aba-campanha" hidden>
            <div class="painel">
                <div class="painel-cabecalho"><h2>Preparar campanha</h2></div>
                <div class="rd-prep">
                    <div class="rd-prep-linha">
                        <div class="campo">
                            <label for="rd-prep-link">Link</label>
                            <select id="rd-prep-link"></select>
                        </div>
                        <div class="campo">
                            <label for="rd-prep-plataforma">Plataforma do anúncio</label>
                            <select id="rd-prep-plataforma"></select>
                        </div>
                    </div>
                    <div id="rd-prep-resultado"></div>
                </div>
            </div>
        </section>
    </main>
</div>

<div class="rd-editor" id="rd-editor" aria-hidden="true">
    <header class="rd-editor-topo">
        <button type="button" class="botao" id="rd-voltar">← Voltar</button>
        <div class="rd-editor-titulo">
            <span class="rd-editor-sobre">Novo redirecionador</span>
            <strong id="rd-editor-titulo">Criar novo link</strong>
        </div>
        <div class="rd-editor-acoes">
            <button type="button" class="botao rd-ver-jornada" id="rd-ver-jornada">Ver jornada</button>
            <button type="button" class="botao botao-primario" id="rd-salvar">Criar redirecionador</button>
        </div>
    </header>

    <div class="rd-editor-corpo">
        <nav class="rd-nav" id="rd-nav">
            <a href="#rd-sec-ident" class="ativo">Identificação</a>
            <a href="#rd-sec-endereco">Endereço</a>
            <a href="#rd-sec-origem">Origem e UTMs</a>
            <a href="#rd-sec-protecao">Proteção</a>
            <a href="#rd-sec-destino">Destino</a>
        </nav>

        <div class="rd-form" id="rd-form-rolagem">
            <section class="painel rd-sec" id="rd-sec-ident">
                <h3>Identificação</h3>
                <div class="campo">
                    <label for="rd-titulo">Nome do link *</label>
                    <input type="text" id="rd-titulo" maxlength="120" placeholder="Ex: Campanha Black Friday" autocomplete="off">
                </div>
                <label class="basico-interruptor" style="margin-top:14px;">
                    <input type="checkbox" id="rd-ativo" checked>
                    <span>Link ativo</span>
                </label>
                <p class="texto-suave rd-dica">Link inativo mostra uma página de "link indisponível" pra quem clicar.</p>
            </section>

            <section class="painel rd-sec" id="rd-sec-endereco">
                <h3>Endereço</h3>
                <div class="campo" id="rd-campo-dominio">
                    <label>Domínio público</label>
                    <div class="rd-cartoes" id="rd-dominios"></div>
                </div>
                <div class="campo" style="margin-top:16px;" id="rd-campo-formato">
                    <label>Formato do endereço</label>
                    <div class="rd-cartoes rd-cartoes-2" id="rd-formatos">
                        <button type="button" class="rd-cartao ativo" data-formato="aleatorio"><strong>Aleatório</strong><span>Gerado pra você (ex: k3m9x2ab)</span></button>
                        <button type="button" class="rd-cartao" data-formato="personalizado"><strong>Personalizado</strong><span>Você escolhe o nome</span></button>
                    </div>
                </div>
                <div class="campo" style="margin-top:16px;" id="rd-campo-slug" hidden>
                    <label for="rd-slug">Endereço</label>
                    <div class="rd-slug-linha">
                        <span class="rd-slug-prefixo" id="rd-slug-prefixo">/l/</span>
                        <input type="text" id="rd-slug" maxlength="40" placeholder="minha-campanha" autocomplete="off" spellcheck="false">
                    </div>
                    <small>3 a 40 caracteres: letras minúsculas, números e hífens.</small>
                </div>
                <p class="texto-suave rd-dica" id="rd-aviso-slug" hidden>O endereço não pode ser alterado depois de criado, pra não quebrar anúncios já publicados.</p>
            </section>

            <section class="painel rd-sec" id="rd-sec-origem">
                <h3>Origem e UTMs</h3>
                <p class="texto-suave rd-dica" style="margin-top:0;">Escolha onde o link vai rodar. As UTMs ajudam a ver, por campanha, quantos cliques cada anúncio trouxe.</p>
                <div class="rd-cartoes rd-cartoes-4" id="rd-plataformas"></div>
                <div id="rd-origem-urls" class="rd-origem-urls"></div>
            </section>

            <section class="painel rd-sec" id="rd-sec-protecao">
                <h3>Proteção</h3>
                <div class="rd-cartoes rd-cartoes-2" id="rd-protecoes">
                    <button type="button" class="rd-cartao ativo" data-protecao="nenhuma"><strong>Nenhuma</strong><span>Conta todos os cliques.</span></button>
                    <button type="button" class="rd-cartao" data-protecao="filtrar_robos"><strong>Filtrar robôs</strong><span>Não conta cliques de robôs e pré-visualizações (eles ainda chegam ao destino).</span></button>
                </div>
            </section>

            <section class="painel rd-sec" id="rd-sec-destino">
                <h3>Destino</h3>
                <p class="texto-suave rd-dica" style="margin-top:0;">Escolha os bots que recebem o público. Com mais de um, o clique é dividido entre eles.</p>
                <div class="rd-lista-bots" id="rd-bots"></div>
                <div class="campo" style="margin-top:16px;" id="rd-campo-modo" hidden>
                    <label>Como dividir os cliques</label>
                    <div class="rd-cartoes rd-cartoes-2" id="rd-modos">
                        <button type="button" class="rd-cartao ativo" data-modo="aleatorio"><strong>Aleatório</strong><span>Sorteia um bot a cada clique.</span></button>
                        <button type="button" class="rd-cartao" data-modo="sequencial"><strong>Sequencial</strong><span>Revezamento: um bot depois do outro.</span></button>
                    </div>
                </div>
            </section>
        </div>

        <aside class="rd-previa" id="rd-previa">
            <div class="painel">
                <div class="rd-previa-topo">
                    <span class="rotulo-kpi">Prévia da jornada</span>
                    <span class="basico-status" id="rd-previa-status">Ativo</span>
                </div>
                <div class="rd-previa-slug" id="rd-previa-slug">/l/…</div>
                <div class="rd-previa-url">
                    <code id="rd-previa-url"></code>
                    <button type="button" class="btn-icon" id="rd-previa-copiar" title="Copiar link" aria-label="Copiar link">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
                    </button>
                </div>
                <ol class="rd-jornada">
                    <li><b>01</b><div><strong>Entrada</strong><span id="rd-j-entrada"></span></div></li>
                    <li><b>02</b><div><strong>Atribuição</strong><span id="rd-j-atribuicao"></span></div></li>
                    <li><b>03</b><div><strong>Proteção</strong><span id="rd-j-protecao"></span></div></li>
                    <li class="final"><b>→</b><div><strong>Destino final</strong><span id="rd-j-destino"></span></div></li>
                </ol>
            </div>
        </aside>
    </div>
</div>

<div id="toast"></div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>if (window.CSRF_TOKEN) { $.ajaxSetup({ headers: { 'X-CSRF-Token': window.CSRF_TOKEN } }); }</script>
<script src="assets/js/tema.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/tema.js'); ?>"></script>
<script src="assets/redirecionamento.js?v=<?php echo @filemtime(__DIR__ . '/assets/redirecionamento.js'); ?>"></script>
</body>
</html>
