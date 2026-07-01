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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/app.css">
    <style>
        .grid-bot-layout {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 24px;
            align-items: start;
        }
        .bot-profile-card {
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 12px;
        }
        .bot-avatar {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: #f1f5f9;
            object-fit: cover;
            border: 4px solid #fff;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
        }
        .bot-avatar-placeholder {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #94a3b8;
            font-size: 40px;
        }
        .bot-status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: 99px;
            font-size: 13px;
            font-weight: 600;
            background: #f1f5f9;
            color: #64748b;
        }
        .bot-status-badge.online { background: #dcfce7; color: #166534; }
        .bot-status-badge.offline { background: #fee2e2; color: #991b1b; }
        .status-dot { width: 8px; height: 8px; border-radius: 50%; background: currentColor; }
        
        .tools-list { display: flex; flex-direction: column; gap: 8px; }
        .tools-list button { text-align: left; justify-content: flex-start; }
        
        @media (max-width: 900px) {
            .grid-bot-layout { grid-template-columns: 1fr; }
            .painel-lateral { order: -1; } /* Info no topo em mobile */
        }
    </style>
</head>
<body>
<div class="dashboard-layout">
    <?php include 'sidebar.php'; ?>
    <main class="main-content">
        <div class="cabecalho-pagina">
            <div>
                <h1>Configuração do Bot</h1>
                <p>Gerencie a conexão e o perfil do seu bot.</p>
            </div>
            <div class="acoes-cabecalho">
                <a class="botao botao-claro" href="bots.php">Voltar</a>
            </div>
        </div>

        <div class="grid-bot-layout">
            <!-- Coluna Principal -->
            <div class="coluna-principal">
                <!-- Configurações Gerais -->
                <div class="painel margem-baixo-16">
                    <div class="painel-cabecalho">
                        <h2>Configurações Gerais</h2>
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

                <!-- Perfil do Bot -->
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
                                <button type="submit" class="botao botao-claro">Atualizar Perfil no Telegram</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Coluna Lateral -->
            <div class="painel-lateral">
                <!-- Status Card -->
                <div class="painel margem-baixo-16">
                    <div class="bot-profile-card">
                        <div id="status-avatar-container">
                            <div class="bot-avatar-placeholder">?</div>
                        </div>
                        <div>
                            <h3 id="status-nome" style="margin:0; font-size:18px;">Novo Bot</h3>
                            <p id="status-username" style="margin:4px 0 0; color:var(--muted); font-size:14px;">@...</p>
                        </div>
                        <div id="status-badge" class="bot-status-badge">
                            <span class="status-dot"></span> <span class="status-text">Desconhecido</span>
                        </div>
                    </div>
                </div>

                <!-- Ferramentas -->
                <div class="painel">
                    <div class="painel-cabecalho">
                        <h2>Ferramentas</h2>
                    </div>
                    <div class="tools-list">
                        <button type="button" class="botao botao-claro botao-bloco" id="btn-info-webhook">
                            <span class="icone-svg"><!-- info icon -->
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
                            </span>
                            Verificar Webhook
                        </button>
                        <button type="button" class="botao botao-claro botao-bloco" id="btn-reiniciar-webhook">
                            <span class="icone-svg"><!-- refresh icon -->
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"></path><path d="M3 3v5h5"></path><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"></path><path d="M16 21h5v-5"></path></svg>
                            </span>
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
<script src="assets/edicao_bot.js"></script>
</body>
</html>
