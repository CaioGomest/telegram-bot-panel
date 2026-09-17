<?php declare(strict_types=1);
require_once __DIR__ . '/funcoes/usuario.php';
verificarLogin();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configurar Bot</title>
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
                <p>Gerencie a conexão e o perfil do seu bot.</p>
            </div>
            <div class="acoes-cabecalho">
                <a class="botao" href="bots.php">Voltar</a>
                <button type="button" class="alternador-tema" onclick="alternarTema()" aria-label="Alternar tema">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"></path></svg>
                    Tema
                </button>
            </div>
        </div>

        <div class="somente-desktop-aviso ativo-mobile">
            <h2>Melhor no desktop</h2>
            <p>A configuração do bot funciona melhor em uma tela maior.</p>
            <a href="bots.php" class="botao botao-primario" style="margin-top:14px;display:inline-flex;">Voltar aos meus bots</a>
        </div>

        <div class="grade-config-bot oculto-mobile">
            <div class="coluna-principal">
                <div class="painel">
                    <div class="painel-cabecalho">
                        <h2>Conexão</h2>
                    </div>
                    <form id="formulario-bot">
                        <input type="hidden" name="id" id="id-bot">
                        <div class="grade grade-1">
                            <div class="campo">
                                <label for="token">Token do Bot (BotFather)</label>
                                <div class="campo-com-acao">
                                    <input type="text" id="token" name="token" placeholder="Ex: 123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11">
                                    <button type="button" class="botao botao-claro" id="btn-testar-token">Testar</button>
                                </div>
                                <p class="texto-ajuda">Obtenha este token com o @BotFather no Telegram.</p>
                            </div>
                            
                            <div class="campo">
                                <label for="id-fluxo-conectado">Fluxo de Conversa</label>
                                <div class="campo-com-acao">
                                    <select id="id-fluxo-conectado" name="connected_flow_id">
                                        <option value="">Selecione um fluxo...</option>
                                    </select>
                                    <a class="botao botao-claro" href="fluxo.php">Novo Fluxo</a>
                                </div>
                                <p class="texto-ajuda">Este fluxo será iniciado quando alguém mandar mensagem para o bot.</p>
                            </div>

                            <div class="linha-acoes" style="margin-top: 10px;">
                                <button type="button" class="botao botao-primario" id="btn-salvar-bot">Salvar Configurações</button>
                            </div>
                        </div>
                    </form>
                </div>

                <div class="painel">
                    <div class="painel-cabecalho">
                        <h2>Perfil no Telegram</h2>
                    </div>
                    <form id="formulario-perfil" enctype="multipart/form-data">
                        <div class="grade grade-2">
                            <div class="campo">
                                <label for="name">Nome de Exibição</label>
                                <input type="text" id="name" name="name" maxlength="64" placeholder="Nome do Bot">
                            </div>
                            <div class="campo">
                                <label for="descricao-curta">Descrição Curta (Bio)</label>
                                <input type="text" id="descricao-curta" name="short_description" maxlength="120" placeholder="Aparece no perfil do bot">
                            </div>
                            <div class="campo completo">
                                <label for="description">Descrição (O que o bot faz?)</label>
                                <textarea id="description" name="description" rows="4" maxlength="512" placeholder="Aparece quando o usuário abre o bot pela primeira vez"></textarea>
                            </div>
                            <div class="campo completo">
                                <label for="photo">Foto de Perfil</label>
                                <input type="file" id="photo" name="photo" accept="image/*">
                            </div>
                            
                            <div class="campo completo linha-acoes">
                                <button type="submit" class="botao">Atualizar Perfil no Telegram</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <div class="painel-lateral">
                <div class="painel">
                    <div class="cartao-preview-bot">
                        <div id="status-avatar-container">
                            <div class="avatar-preview-bot-placeholder">?</div>
                        </div>
                        <div>
                            <h3 id="status-nome" style="margin:0; font-size:16px; text-transform:none; font-family:'Manrope',sans-serif;">Novo Bot</h3>
                            <p id="status-username" style="margin:4px 0 0; color:var(--m); font-size:12.5px;">@...</p>
                        </div>
                        <div id="status-badge" class="status-bot">
                            <span class="ponto-status"></span> <span class="texto-status">Desconhecido</span>
                        </div>
                    </div>
                </div>

                <div class="painel">
                    <div class="painel-cabecalho">
                        <h2>Ferramentas</h2>
                    </div>
                    <div class="lista-ferramentas">
                        <button type="button" class="botao" id="btn-info-webhook">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
                            Verificar Webhook
                        </button>
                        <button type="button" class="botao" id="btn-reiniciar-webhook">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"></path><path d="M3 3v5h5"></path><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"></path><path d="M16 21h5v-5"></path></svg>
                            Reiniciar / Destravar Fila
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<div id="toast"></div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="assets/js/tema.js"></script>
<script src="assets/edicao_bot.js"></script>
</body>
</html>
