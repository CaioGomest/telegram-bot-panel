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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://code.jquery.com/ui/1.13.3/themes/base/jquery-ui.css">
    <link rel="stylesheet" href="assets/vendor/jquery.flowchart.css">
    <link rel="stylesheet" href="assets/fluxograma_tema.css?v=<?= (int) filemtime(__DIR__ . '/assets/fluxograma_tema.css') ?>">
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="dashboard-layout" id="pagina-edicao-fluxo">
    <?php include 'sidebar.php'; ?>
    
    <main class="main-content">
        <div class="painel margem-baixo-16">
            <div class="painel-cabecalho">
                <h1>Editor de Fluxos</h1>
                <div class="linha-acoes">
                    <a class="botao botao-claro" href="fluxos.php">Voltar</a>
                    <button class="botao botao-claro" id="btn-excluir-fluxo">Excluir</button>
                    <button class="botao botao-primario" id="btn-salvar-fluxo">Salvar</button>
                    <button class="botao botao-claro" id="btn-exportar-fluxo">Exportar</button>
                    <button class="botao botao-claro" id="btn-importar-fluxo">Importar</button>
                    <input type="file" id="arquivo-importar-fluxo" accept="application/json" style="display:none">
                </div>
            </div>
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
                    <p style="font-size:11px;color:#64748b;margin-top:4px;">Usado nas mensagens automáticas de aviso/expiração de acesso enviadas pelo sistema (fora do fluxo).</p>
                </div>
            </div>
            <input type="hidden" id="id-fluxo">
        </div>

        <div class="painel margem-baixo-16">
            <div class="painel-cabecalho">
                <h2>Blocos</h2>
            </div>
            <div class="linha-acoes quebrar">
                <button class="botao botao-claro adicionar-no" data-node-type="message">Mensagem</button>
                <button class="botao botao-claro adicionar-no" data-node-type="image">Imagem</button>
                <button class="botao botao-claro adicionar-no" data-node-type="video">Vídeo</button>
                <button class="botao botao-claro adicionar-no" data-node-type="audio">Áudio</button>
                <button class="botao botao-claro adicionar-no" data-node-type="botoes">Botões</button>
                <button class="botao botao-claro adicionar-no" data-node-type="pix">PIX</button>
                <button class="botao botao-claro adicionar-no" data-node-type="link">Links</button>
                <button class="botao botao-claro adicionar-no" data-node-type="grupo">Grupo</button>
                <button class="botao botao-claro adicionar-no" data-node-type="delay">Delay</button>
            </div>
        </div>

        <div class="painel conteiner-fluxo">
            <div id="area-trabalho-fluxograma"></div>
        </div>
    </main>

    <aside class="barra-direita" style="display:none">
        <div class="painel fixo">
            <div class="painel-cabecalho">
                <h2>Bloco selecionado</h2>
            </div>
            <form id="formulario-operador">
                <div class="campo">
                    <label for="view-id-operador">ID</label>
                    <input type="text" id="view-id-operador" disabled>
                </div>
                <div class="campo">
                    <label for="tipo-operador">Tipo</label>
                    <input type="text" id="tipo-operador" disabled>
                </div>
                <div class="campo">
                    <label for="titulo-operador">Título</label>
                    <input type="text" id="titulo-operador" placeholder="Título do bloco">
                </div>
                <div class="campo">
                    <label for="corpo-operador">Conteúdo</label>
                    <textarea id="corpo-operador" rows="8" placeholder="Texto da mensagem, condição ou ação"></textarea>
                </div>
                <div id="props-imagem" style="display:none">
                    <div class="campo">
                        <label for="arquivo-imagem">Imagem</label>
                        <input type="file" id="arquivo-imagem" accept="image/*">
                    </div>
                    <div class="campo">
                        <label>Prévia</label>
                        <div id="previa-imagem-container" class="previa-imagem texto-suave">Nenhuma imagem</div>
                    </div>
                    <div class="grade grade-2 grade-compacta">
                        <div class="campo">
                            <label for="modo-imagem">Modo de envio</label>
                            <select id="modo-imagem">
                                <option value="foto">Foto</option>
                                <option value="documento">Documento</option>
                            </select>
                        </div>
                        <div class="campo">
                            <label for="legenda-imagem">Legenda (opcional)</label>
                            <input type="text" id="legenda-imagem" placeholder="Legenda">
                        </div>
                    </div>
                    <div class="grade grade-2 grade-compacta">
                        <div class="campo">
                            <label><input type="checkbox" id="spoiler-imagem"> Spoiler</label>
                        </div>
                        <div class="campo">
                            <label><input type="checkbox" id="auto-deletar-imagem"> Auto-deletar</label>
                            <input type="number" id="segundos-auto-deletar-imagem" min="0" value="0" placeholder="Segundos">
                        </div>
                    </div>
                    <hr>
                    <div class="grade grade-2 grade-compacta">
                        <div class="campo">
                            <label for="token-teste-imagem">Token do bot (teste)</label>
                            <input type="text" id="token-teste-imagem" placeholder="123:ABC">
                        </div>
                        <div class="campo">
                            <label for="chat-id-teste-imagem">Chat ID (teste)</label>
                            <div class="campo-com-acao">
                                <input type="text" id="chat-id-teste-imagem" placeholder="ID do chat">
                                <button type="button" class="botao botao-claro" id="btn-descobrir-chat-id">Descobrir</button>
                            </div>
                        </div>
                    </div>
                    <div class="campo linha-acoes">
                        <button type="button" class="botao botao-claro" id="btn-testar-envio-imagem">Enviar imagem de teste</button>
                    </div>
                </div>
                <div class="campo linha-acoes">
                    <button type="button" class="botao botao-primario" id="btn-aplicar-operador">Aplicar no bloco</button>
                </div>
            </form>
        </div>
    </aside>
</div>

<div id="toast"></div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://code.jquery.com/ui/1.13.3/jquery-ui.min.js"></script>
<script src="assets/vendor/jquery.flowchart.js"></script>
<script src="assets/edicao_fluxo.js?v=<?= (int) filemtime(__DIR__ . '/assets/edicao_fluxo.js') ?>"></script>
</body>
</html>
