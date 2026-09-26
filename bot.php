<?php declare(strict_types=1);
require_once __DIR__ . '/funcoes/usuario.php';
bloquearAdmin();
$eh_edicao = isset($_GET['id']) && $_GET['id'] !== '';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configurar Bot - <?php echo htmlspecialchars(nomeSistema()); ?></title>
    <?php include 'tema_inline.php'; ?>
    <link rel="stylesheet" href="assets/css/coyote.css?v=<?php echo @filemtime(__DIR__.'/assets/css/coyote.css'); ?>">
</head>
<body>
<div class="layout-painel">
    <?php include 'barra_lateral.php'; ?>
    <main class="conteudo-principal">
        <div class="cabecalho-pagina">
            <div>
                <h1>Configuração do Bot</h1>
                <p>Conexão, fluxo padrão e perfil público no Telegram.</p>
            </div>
            <div class="acoes-cabecalho">
                <a class="botao" href="bots">‹ Meus Bots</a>
                <button type="button" class="alternador-tema" onclick="alternarTema()" aria-label="Alternar tema">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"></path></svg>
                    Tema
                </button>
            </div>
        </div>

        <!-- Barra de preview, só existe depois que o bot já foi salvo (tem id na URL). -->
        <div class="barra-preview-bot" id="barra-preview-bot"<?php echo $eh_edicao ? '' : ' hidden'; ?>>
            <div id="status-avatar-container">
                <div class="avatar-preview-bot-placeholder">?</div>
            </div>
            <div class="preview-bot-identidade">
                <div class="linha-preview-bot-nome">
                    <h2 id="status-nome">Novo Bot</h2>
                    <span id="status-badge" class="status-bot">
                        <span class="ponto-status"></span> <span class="texto-status">Desconhecido</span>
                    </span>
                </div>
                <p id="status-username" class="texto-suave mono">@...</p>
            </div>
            <a class="botao" id="btn-abrir-telegram" href="#" target="_blank" rel="noopener" hidden>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg>
                Abrir no Telegram
            </a>
        </div>

        <!-- Card "Criar automaticamente", só existe pra bot novo -->
        <div class="painel cartao-criar-automatico" id="cartao-criar-automatico"<?php echo $eh_edicao ? ' hidden' : ''; ?>>
            <div class="linha-criar-automatico">
                <div class="icone-criar-automatico">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>
                </div>
                <div class="texto-criar-automatico">
                    <div class="titulo-criar-automatico">Criar automaticamente <span class="nav-badge">NOVO</span></div>
                    <p class="texto-suave">Abra o @BotFather no Telegram e siga o passo a passo ao lado — leva menos de um minuto. Depois é só colar o token abaixo.</p>
                </div>
                <a class="botao botao-primario" href="https://t.me/BotFather" target="_blank" rel="noopener">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg>
                    Abrir BotFather
                </a>
            </div>
            <p class="texto-suave" style="margin:14px 0 0;">Ou preencha o token manualmente abaixo se já tiver um bot criado.</p>
        </div>

        <div class="grade-config-bot">
            <div class="coluna-principal">
                <div class="painel">
                    <div class="painel-cabecalho">
                        <h2>Conexão</h2>
                    </div>
                    <p class="texto-suave" id="dica-conexao" style="margin:-8px 0 16px;">
                        <?php echo $eh_edicao ? 'Token gerado pelo @BotFather e fluxo que o bot executa.' : 'Cole o token gerado pelo @BotFather. Nome e username são preenchidos automaticamente.'; ?>
                    </p>
                    <form id="formulario-bot">
                        <input type="hidden" name="id" id="id-bot">
                        <div class="grade grade-1">
                            <div class="campo">
                                <label for="token">Token do Bot</label>
                                <div class="campo-com-acao">
                                    <input type="text" id="token" name="token" class="mono" placeholder="123456789:AAH-xxxxxxxxxxxxxxxxxxxx">
                                    <button type="button" class="botao botao-claro" id="btn-testar-token">Testar</button>
                                </div>
                                <p class="texto-ajuda">Obtenha este token com o @BotFather no Telegram.</p>
                            </div>

                            <div class="grade grade-2 grade-compacta" id="linha-preview-criacao"<?php echo $eh_edicao ? ' hidden' : ''; ?>>
                                <div class="campo">
                                    <label for="nome-preview-criacao">Nome</label>
                                    <input type="text" id="nome-preview-criacao" placeholder="Automático" readonly>
                                </div>
                                <div class="campo">
                                    <label for="username-preview-criacao">Username</label>
                                    <input type="text" id="username-preview-criacao" class="mono" placeholder="@automatico_bot" readonly>
                                </div>
                            </div>

                            <div class="campo">
                                <label for="id-fluxo-conectado">Fluxo <?php echo $eh_edicao ? 'de conversa' : 'padrão'; ?></label>
                                <div class="campo-com-acao">
                                    <select id="id-fluxo-conectado" name="connected_flow_id">
                                        <option value="">Selecione um fluxo...</option>
                                    </select>
                                    <a class="botao botao-claro" href="fluxo" id="link-fluxo-secundario"><?php echo $eh_edicao ? 'Editar fluxo' : 'Novo Fluxo'; ?></a>
                                </div>
                                <p class="texto-ajuda">Inicia quando alguém envia qualquer mensagem ao bot.</p>
                            </div>

                            <div class="aviso aviso-alerta" id="aviso-token-principal" style="margin-top:6px;"<?php echo $eh_edicao ? ' hidden' : ''; ?>>
                                <strong>⚠ Mantenha seu token seguro!</strong><br>
                                Nunca compartilhe o token do seu bot com terceiros. Com ele, qualquer pessoa pode controlar seu bot completamente.
                            </div>

                            <div class="linha-acoes" style="margin-top: 10px;">
                                <a class="botao" href="bots">Cancelar</a>
                                <button type="button" class="botao botao-primario" id="btn-salvar-bot"><?php echo $eh_edicao ? 'Salvar alterações' : 'Criar bot'; ?></button>
                            </div>
                        </div>
                    </form>
                </div>

                <div class="painel" id="painel-perfil-telegram"<?php echo $eh_edicao ? '' : ' hidden'; ?>>
                    <div class="painel-cabecalho">
                        <h2>Perfil no Telegram</h2>
                    </div>
                    <p class="texto-suave" style="margin:-8px 0 16px;">Como o bot aparece para o lead.</p>
                    <form id="formulario-perfil" enctype="multipart/form-data">
                        <div class="grade grade-2">
                            <div class="campo completo linha-foto-perfil-bot">
                                <div id="preview-foto-perfil-bot"><div class="avatar-preview-bot-placeholder">?</div></div>
                                <div>
                                    <label for="photo" class="botao botao-claro">Trocar</label>
                                    <input type="file" id="photo" name="photo" accept="image/*" style="display:none">
                                    <p class="texto-ajuda" style="margin:6px 0 0;">PNG ou JPG, mínimo 512×512.</p>
                                </div>
                            </div>
                            <div class="campo">
                                <label for="name">Nome de Exibição</label>
                                <input type="text" id="name" name="name" maxlength="64" placeholder="Nome do Bot">
                            </div>
                            <div class="campo">
                                <label for="descricao-curta">Bio Curta</label>
                                <input type="text" id="descricao-curta" name="short_description" maxlength="120" placeholder="Aparece no perfil do bot">
                            </div>
                            <div class="campo completo">
                                <label for="description">Descrição</label>
                                <textarea id="description" name="description" rows="4" maxlength="512" placeholder="Aparece quando o usuário abre o bot pela primeira vez"></textarea>
                            </div>

                            <div class="campo completo linha-acoes">
                                <button type="button" class="botao" id="btn-descartar-perfil">Descartar</button>
                                <button type="submit" class="botao botao-primario">Salvar alterações</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <div class="painel-lateral">
                <!-- Tutorial, só existe pra bot novo -->
                <div class="painel" id="cartao-tutorial-botfather"<?php echo $eh_edicao ? ' hidden' : ''; ?>>
                    <div class="painel-cabecalho">
                        <h2>Tutorial: criar bot no Telegram</h2>
                    </div>
                    <p class="texto-suave" style="margin:-8px 0 16px;">Siga estes passos para criar seu bot no Telegram:</p>
                    <ol class="lista-passos-tutorial">
                        <li>
                            <span class="numero-passo">1</span>
                            <div>
                                <strong>Abra o BotFather no Telegram</strong>
                                <p class="texto-suave">Clique no link abaixo ou procure por @BotFather no Telegram:</p>
                                <a href="https://t.me/BotFather" target="_blank" rel="noopener" class="login-link-esqueci">telegram.me/BotFather ↗</a>
                            </div>
                        </li>
                        <li>
                            <span class="numero-passo">2</span>
                            <div>
                                <strong>Envie o comando /newbot</strong>
                                <p class="texto-suave">No chat com o BotFather aberto, digite "/start" e depois envie o seguinte comando:</p>
                                <span class="chip-codigo">
                                    <code>/newbot</code>
                                    <button type="button" class="btn-copiar-chip" data-copiar="/newbot" title="Copiar">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
                                    </button>
                                </span>
                            </div>
                        </li>
                        <li>
                            <span class="numero-passo">3</span>
                            <div>
                                <strong>Defina o nome do bot</strong>
                                <p class="texto-suave">O BotFather perguntará: "Alright, a new bot. How are we going to call it?" Digite o nome completo do seu bot (ex: "Meu Bot de Vendas").</p>
                            </div>
                        </li>
                        <li>
                            <span class="numero-passo">4</span>
                            <div>
                                <strong>Defina o username do bot</strong>
                                <p class="texto-suave">O BotFather pedirá um username único que termine com "bot". Exemplo válido: <code>empresa123_bot</code></p>
                            </div>
                        </li>
                        <li>
                            <span class="numero-passo">5</span>
                            <div>
                                <strong>Copie o token</strong>
                                <p class="texto-suave">O BotFather enviará uma mensagem com o token do bot. Copie e cole no campo solicitado.</p>
                                <p style="margin:6px 0 0;color:var(--ok);font-weight:700;font-size:12px;">✓ Teste o token abaixo pra confirmar antes de criar.</p>
                            </div>
                        </li>
                    </ol>
                </div>

                <div class="painel" id="painel-ferramentas"<?php echo $eh_edicao ? '' : ' hidden'; ?>>
                    <div class="painel-cabecalho">
                        <h2>Ferramentas</h2>
                    </div>
                    <p class="texto-suave" style="margin:-8px 0 16px;">Ações de manutenção do bot.</p>
                    <div class="lista-ferramentas">
                        <button type="button" class="botao" id="btn-info-webhook">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
                            Verificar Webhook
                        </button>
                        <button type="button" class="botao" id="btn-reiniciar-webhook">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"></path><path d="M3 3v5h5"></path><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"></path><path d="M16 21h5v-5"></path></svg>
                            Reiniciar / Destravar Fila
                        </button>
                        <button type="button" class="botao" id="btn-exportar-fluxo-bot">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"></path></svg>
                            Exportar Fluxo (JSON)
                        </button>
                    </div>
                </div>

                <div class="aviso aviso-alerta" id="aviso-token-lateral"<?php echo $eh_edicao ? '' : ' hidden'; ?>>
                    <strong>⚠ Mantenha seu token seguro!</strong><br>
                    Nunca compartilhe o token do seu bot com terceiros. Com ele, qualquer pessoa pode controlar seu bot completamente.
                </div>
            </div>
        </div>
    </main>
</div>

<div id="toast"></div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>if (window.CSRF_TOKEN) { $.ajaxSetup({ headers: { 'X-CSRF-Token': window.CSRF_TOKEN } }); }</script>
<script src="assets/js/tema.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/tema.js'); ?>"></script>
<script src="assets/edicao_bot.js?v=<?php echo @filemtime(__DIR__ . '/assets/edicao_bot.js'); ?>"></script>
</body>
</html>
