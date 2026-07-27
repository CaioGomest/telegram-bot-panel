<?php
declare(strict_types=1);
require_once __DIR__ . '/funcoes/usuario.php';
verificarLogin();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Links de Rastreamento</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/app.css">
    <style>
        .tabela-rastreamento {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        .tabela-rastreamento th {
            text-align: left;
            padding: 10px 14px;
            font-weight: 600;
            color: var(--muted);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-bottom: 1px solid var(--border);
        }
        .tabela-rastreamento td {
            padding: 12px 14px;
            border-bottom: 1px solid var(--border);
            color: var(--text);
            vertical-align: middle;
        }
        .tabela-rastreamento tr:last-child td {
            border-bottom: none;
        }
        .tabela-rastreamento tr:hover td {
            background-color: var(--bg);
        }
        .col-link {
            font-family: monospace;
            font-size: 12px;
            color: var(--muted);
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .col-acoes {
            display: flex;
            gap: 6px;
            align-items: center;
        }

        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.45);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        .modal-overlay.aberto {
            display: flex;
        }
        .modal-caixa {
            background: var(--panel);
            border-radius: 16px;
            padding: 32px;
            width: 100%;
            max-width: 480px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.15);
            position: relative;
        }
        .modal-fechar {
            position: absolute;
            top: 16px;
            right: 16px;
            background: none;
            border: 1px solid var(--border);
            border-radius: 50%;
            width: 30px;
            height: 30px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--muted);
            font-size: 16px;
            line-height: 1;
        }
        .modal-fechar:hover {
            background: var(--bg);
        }
        .modal-titulo {
            font-size: 20px;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 4px;
        }
        .modal-subtitulo {
            font-size: 13px;
            color: var(--muted);
            margin-bottom: 24px;
        }
        .modal-secao {
            font-size: 13px;
            font-weight: 600;
            color: var(--muted);
            margin-bottom: 16px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .modal-campo {
            margin-bottom: 16px;
        }
        .modal-campo label {
            display: block;
            font-size: 13px;
            font-weight: 500;
            color: var(--text);
            margin-bottom: 6px;
        }
        .modal-campo label span.obrigatorio {
            color: var(--primary);
        }
        .modal-campo input,
        .modal-campo select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-family: inherit;
            font-size: 14px;
            color: var(--text);
            background: var(--panel);
            box-sizing: border-box;
            transition: border-color 0.2s;
        }
        .modal-campo input:focus,
        .modal-campo select:focus {
            outline: none;
            border-color: var(--primary);
        }
        .modal-campo .dica {
            font-size: 11px;
            color: var(--muted);
            margin-top: 4px;
        }
        .modal-rodape {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 24px;
        }
        .cabecalho-pagina-acoes {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .painel-cabecalho-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .contador-badge {
            background: var(--primary);
            color: white;
            border-radius: 50%;
            width: 22px;
            height: 22px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: 700;
        }
    </style>
</head>
<body>
<div class="dashboard-layout">
    <?php include 'sidebar.php'; ?>
    <main class="main-content">
        <div class="cabecalho-pagina">
            <div>
                <h1>Links de Rastreamento</h1>
                <p>Crie e gerencie links de rastreamento para seus bots.</p>
            </div>
        </div>

        <div class="painel">
            <div class="painel-cabecalho">
                <div class="painel-cabecalho-info">
                    <h2>Todos os links cadastrados</h2>
                    <span class="contador-badge" id="contador-links">0</span>
                </div>
                <button class="botao botao-primario" id="btn-novo-link">Gerar novo link</button>
            </div>

            <div id="tabela-container">
                <table class="tabela-rastreamento">
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
                        <tr><td colspan="9" style="text-align:center;padding:32px;color:var(--muted);">Carregando...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>

<div class="modal-overlay" id="modal-link">
    <div class="modal-caixa">
        <button class="modal-fechar" id="btn-fechar-modal">&times;</button>
        <div class="modal-titulo" id="modal-titulo-texto">Crie um link trackeado</div>
        <div class="modal-subtitulo">Gere um link personalizado e acompanhe o desempenho dele em tempo real</div>

        <div class="modal-secao">Preencha as informações da sua conta</div>

        <form id="form-link" autocomplete="off">
            <input type="hidden" id="link-id" value="">

            <div class="modal-campo">
                <label for="link-titulo">Título <span class="obrigatorio">*</span></label>
                <input type="text" id="link-titulo" placeholder="Ex: Link do Instagram" maxlength="255">
            </div>

            <div class="modal-campo">
                <label for="link-identificador">Identificador <span class="obrigatorio">*</span></label>
                <input type="text" id="link-identificador" placeholder="Insira um identificador para o seu rastreamento" maxlength="100">
                <span class="dica">Somente letras, números e hifens. Será usado como parâmetro UTM.</span>
            </div>

            <div class="modal-campo">
                <label for="link-bot">Escolha seu bot <span class="obrigatorio">*</span></label>
                <select id="link-bot">
                    <option value="">Escolha o bot</option>
                </select>
            </div>

            <div class="modal-rodape">
                <button type="button" class="botao" id="btn-cancelar-modal">Cancelar</button>
                <button type="submit" class="botao botao-primario" id="btn-salvar-link">Gerar link</button>
            </div>
        </form>
    </div>
</div>

<div id="toast"></div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="assets/links_rastreamento.js"></script>
</body>
</html>
