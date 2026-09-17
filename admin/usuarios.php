<?php declare(strict_types=1);
require_once __DIR__ . '/../funcoes/usuario.php';
require_once __DIR__ . '/../funcoes/paginador.php';
verificarAdmin();
$caminho_base = '../';

$pagina_atual = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
$por_pagina = 15;
$offset = ($pagina_atual - 1) * $por_pagina;

$total_usuarios = contarTotalUsuarios();
$usuarios = listarTodosUsuarios($por_pagina, $offset);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Usuários da Plataforma</title>
    <?php include __DIR__ . '/../tema_inline.php'; ?>
    <link rel="stylesheet" href="../assets/css/coyote.css?v=<?php echo @filemtime(__DIR__.'/../assets/css/coyote.css'); ?>">
</head>
<body>
<div class="layout-painel">
    <?php include __DIR__ . '/../barra_lateral.php'; ?>

    <main class="conteudo-principal">
        <div class="cabecalho-pagina">
            <div>
                <h1>Usuários</h1>
                <p>Gerencie os usuários da plataforma.</p>
            </div>
            <div class="acoes-cabecalho">
                <button class="botao botao-primario" onclick="abrirModalAdicionar()">+ Adicionar Usuário</button>
                <button type="button" class="alternador-tema" onclick="alternarTema()" aria-label="Alternar tema">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"></path></svg>
                    Tema
                </button>
            </div>
        </div>

        <div class="painel">
            <div class="tabela-dados">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nome</th>
                            <th>Email</th>
                            <th>Perfil</th>
                            <th>Bots</th>
                            <th>Vendas (R$)</th>
                            <th>Cadastrado em</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        foreach ($usuarios as $u):
                        ?>
                        <tr>
                            <td class="mono texto-suave">#<?php echo $u['id']; ?></td>
                            <td>
                                <div class="celula-principal">
                                    <span class="avatar-item"><?php echo strtoupper(substr($u['nome'], 0, 1)); ?></span>
                                    <span><?php echo htmlspecialchars($u['nome']); ?></span>
                                </div>
                            </td>
                            <td class="texto-suave"><?php echo htmlspecialchars($u['email']); ?></td>
                            <td>
                                <span class="badge <?php echo $u['perfil'] === 'admin' ? 'badge-sucesso' : 'badge-neutro'; ?>">
                                    <?php echo ucfirst($u['perfil']); ?>
                                </span>
                            </td>
                            <td><?php echo $u['total_bots']; ?></td>
                            <td class="mono">R$ <?php echo number_format((float)$u['total_vendas'], 2, ',', '.'); ?></td>
                            <td class="mono texto-suave"><?php echo date('d/m/Y H:i', strtotime($u['criado_em'])); ?></td>
                            <td>
                                <div style="display:flex;gap:6px;">
                                    <button class="btn-icon" onclick="abrirDetalhes(<?php echo $u['id']; ?>)" title="Ver Detalhes">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                    </button>
                                    <button class="btn-icon editar" onclick="abrirModalEditar(<?php echo $u['id']; ?>, '<?php echo addslashes(htmlspecialchars($u['nome'])); ?>', '<?php echo addslashes(htmlspecialchars($u['email'])); ?>', '<?php echo $u['perfil']; ?>')" title="Editar"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg></button>
                                    <button class="btn-icon excluir" onclick="confirmarExcluir(<?php echo $u['id']; ?>, '<?php echo addslashes(htmlspecialchars($u['nome'])); ?>')" title="Excluir"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg></button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($usuarios)): ?>
                        <tr>
                            <td colspan="8" style="text-align:center;padding:32px;" class="texto-suave">Nenhum usuário encontrado.</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php echo paginador($total_usuarios, $por_pagina); ?>
        </div>
    </main>
</div>

<div id="modalAdicionar" class="sobreposicao-modal" style="display: none;">
    <div class="modal-gateway" style="max-width: 460px;">
        <div class="cabecalho-modal">
            <div class="titulo-modal">Adicionar Usuário</div>
            <button class="fechar-modal" onclick="fecharModalAdicionar()">✕</button>
        </div>
        <div class="corpo-modal">
            <div id="msgAdicionar" class="msg-box" style="display:none;"></div>
            <form id="formAdicionar" onsubmit="salvarNovoUsuario(event)">
                <div class="campo">
                    <label>Nome</label>
                    <input type="text" name="nome" required placeholder="Nome completo">
                </div>
                <div class="campo" style="margin-top:14px;">
                    <label>Email</label>
                    <input type="email" name="email" required placeholder="email@exemplo.com">
                </div>
                <div class="campo" style="margin-top:14px;">
                    <label>Senha</label>
                    <input type="password" name="senha" required minlength="6" placeholder="Mínimo 6 caracteres">
                </div>
                <div class="campo" style="margin-top:14px;">
                    <label>Perfil</label>
                    <select name="perfil">
                        <option value="usuario">Usuário</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                <div class="linha-acoes" style="justify-content:flex-end; margin-top:20px;">
                    <button type="button" class="botao" onclick="fecharModalAdicionar()">Cancelar</button>
                    <button type="submit" class="botao botao-primario">Criar Usuário</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div id="modalEditar" class="sobreposicao-modal" style="display: none;">
    <div class="modal-gateway" style="max-width: 620px;">
        <div class="cabecalho-modal">
            <div class="titulo-modal">Editar Usuário</div>
            <button class="fechar-modal" onclick="fecharModalEditar()">✕</button>
        </div>
        <div class="corpo-modal">
            <div id="msgEditar" class="msg-box" style="display:none;"></div>
            <form id="formEditar" onsubmit="salvarEdicaoUsuario(event)">
                <input type="hidden" name="id" id="editarId">
                <div class="campo">
                    <label>Nome</label>
                    <input type="text" name="nome" id="editarNome" required>
                </div>
                <div class="campo" style="margin-top:14px;">
                    <label>Email</label>
                    <input type="email" name="email" id="editarEmail" required>
                </div>
                <div class="campo" style="margin-top:14px;">
                    <label>Nova Senha <span class="texto-suave">(deixe em branco para manter)</span></label>
                    <input type="password" name="senha" minlength="6" placeholder="Nova senha">
                </div>
                <div class="campo" style="margin-top:14px;">
                    <label>Perfil</label>
                    <select name="perfil" id="editarPerfil">
                        <option value="usuario">Usuário</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>

                <div class="divisor-secao">
                    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:14px;">
                        <div>
                            <div class="rotulo-kpi">Splits de Pagamento</div>
                            <div class="texto-suave" style="margin-top:2px;">Percentual/valor repassado automaticamente a cada venda</div>
                        </div>
                        <button type="button" class="botao" style="font-size:12px; padding:6px 12px;" onclick="adicionarLinhasSplit()">+ Adicionar Split</button>
                    </div>
                    <div id="splitCarregando" class="texto-suave" style="font-style:italic; display:none;">Carregando splits...</div>
                    <div id="splitLista"></div>
                    <div id="splitVazio" class="texto-suave" style="font-style:italic; display:none;">Nenhum split configurado.</div>
                </div>

                <div class="linha-acoes" style="justify-content:flex-end; margin-top:24px;">
                    <button type="button" class="botao" onclick="fecharModalEditar()">Cancelar</button>
                    <button type="submit" class="botao botao-primario">Salvar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div id="modalDetalhes" class="sobreposicao-modal" style="display: none;">
    <div class="modal-gateway">
        <div class="cabecalho-modal">
            <div class="titulo-modal" id="modalNome">Carregando...</div>
            <button class="fechar-modal" onclick="fecharModal()">✕</button>
        </div>
        <div class="corpo-modal">
            <div id="modalLoading" style="text-align:center;padding:24px;">
                <div class="spinner"></div>
                <p class="texto-suave">Buscando informações...</p>
            </div>

            <div id="modalDados" style="display: none;">
                <div class="grade grade-2 grade-compacta" style="margin-bottom:24px;">
                    <div class="cartao-kpi">
                        <span class="rotulo-kpi">Email</span>
                        <p id="modalEmail" style="margin:8px 0 0;font-weight:700;word-break:break-all;">-</p>
                    </div>
                    <div class="cartao-kpi">
                        <span class="rotulo-kpi">Cadastrado em</span>
                        <p id="modalData" class="mono" style="margin:8px 0 0;font-weight:700;">-</p>
                    </div>
                    <div class="cartao-kpi">
                        <span class="rotulo-kpi">Total Transacionado</span>
                        <p id="modalVendas" class="mono" style="margin:8px 0 0;font-weight:700;color:var(--or);font-size:18px;">R$ 0,00</p>
                    </div>
                    <div class="cartao-kpi">
                        <span class="rotulo-kpi">Qtd. Vendas</span>
                        <p id="modalQtdVendas" style="margin:8px 0 0;font-weight:700;">0</p>
                    </div>
                </div>

                <p class="rotulo-kpi" style="margin-bottom:10px;">Meus Bots</p>
                <div id="listaBots" style="margin-bottom:24px;">
                    <!-- JS preenche -->
                </div>

                <p class="rotulo-kpi" style="margin-bottom:10px;">Últimas Atividades</p>
                <ul id="listaLogs" class="timeline-logs">
                    <!-- JS preenche -->
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
    function escaparHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }
    function abrirModalAdicionar() {
        document.getElementById('formAdicionar').reset();
        document.getElementById('msgAdicionar').style.display = 'none';
        document.getElementById('modalAdicionar').style.display = 'flex';
    }
    function fecharModalAdicionar() {
        document.getElementById('modalAdicionar').style.display = 'none';
    }
    function salvarNovoUsuario(e) {
        e.preventDefault();
        const form = document.getElementById('formAdicionar');
        const msg = document.getElementById('msgAdicionar');
        const data = new FormData(form);

        fetch('../ajax/adicionar_usuario.php', { method: 'POST', body: data })
            .then(r => r.json())
            .then(res => {
                if (res.sucesso) {
                    location.reload();
                } else {
                    msg.textContent = res.erro || 'Erro ao criar usuário.';
                    msg.className = 'msg-box msg-erro';
                    msg.style.display = 'block';
                }
            })
            .catch(() => {
                msg.textContent = 'Erro de conexão.';
                msg.className = 'msg-box msg-erro';
                msg.style.display = 'block';
            });
    }

    function abrirModalEditar(id, nome, email, perfil) {
        document.getElementById('editarId').value = id;
        document.getElementById('editarNome').value = nome;
        document.getElementById('editarEmail').value = email;
        document.getElementById('editarPerfil').value = perfil;
        document.getElementById('formEditar').querySelector('[name=senha]').value = '';
        document.getElementById('msgEditar').style.display = 'none';
        document.getElementById('modalEditar').style.display = 'flex';
        carregarSplits(id);
    }
    function fecharModalEditar() {
        document.getElementById('modalEditar').style.display = 'none';
    }
    function salvarEdicaoUsuario(e) {
        e.preventDefault();
        const form = document.getElementById('formEditar');
        const msg  = document.getElementById('msgEditar');

        const somaPercentual = coletarSplits().reduce((soma, s) => soma + (parseFloat(s.taxa_split) || 0), 0);
        if (somaPercentual > 100) {
            msg.textContent = `A soma dos percentuais de split não pode passar de 100% (atual: ${somaPercentual.toFixed(2)}%).`;
            msg.className = 'msg-box msg-erro';
            msg.style.display = 'block';
            return;
        }

        const data = new FormData(form);

        fetch('../ajax/editar_usuario.php', { method: 'POST', body: data })
            .then(r => r.json())
            .then(res => {
                if (!res.sucesso) {
                    msg.textContent = res.erro || 'Erro ao salvar.';
                    msg.className = 'msg-box msg-erro';
                    msg.style.display = 'block';
                    return;
                }
                const user_id = document.getElementById('editarId').value;
                const splits = coletarSplits();
                const fd = new FormData();
                fd.append('id_usuario', user_id);
                fd.append('splits', JSON.stringify(splits));
                return fetch('../ajax/salvar_splits_usuario.php', { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(rs => {
                        if (rs.sucesso) {
                            location.reload();
                        } else {
                            msg.textContent = rs.erro || 'Usuário salvo, mas erro ao salvar splits.';
                            msg.className = 'msg-box msg-erro';
                            msg.style.display = 'block';
                        }
                    });
            })
            .catch(() => {
                msg.textContent = 'Erro de conexão.';
                msg.className = 'msg-box msg-erro';
                msg.style.display = 'block';
            });
    }

    function carregarSplits(user_id) {
        const lista    = document.getElementById('splitLista');
        const vazio    = document.getElementById('splitVazio');
        const loading  = document.getElementById('splitCarregando');
        lista.innerHTML = '';
        vazio.style.display    = 'none';
        loading.style.display  = 'block';

        fetch('../ajax/listar_splits_usuario.php?id=' + user_id)
            .then(r => r.json())
            .then(splits => {
                loading.style.display = 'none';
                if (!Array.isArray(splits) || splits.length === 0) {
                    vazio.style.display = 'block';
                } else {
                    splits.forEach(s => adicionarLinhasSplit(s));
                }
            })
            .catch(() => { loading.style.display = 'none'; vazio.style.display = 'block'; });
    }

    function adicionarLinhasSplit(dados) {
        const lista = document.getElementById('splitLista');
        const vazio = document.getElementById('splitVazio');
        vazio.style.display = 'none';

        const taxa = dados?.taxa_split     || '';
        const desc  = dados?.descricao      || '';
        const chave_simples = dados?.chave_pix_split || '';

        const row = document.createElement('div');
        row.className = 'split-row';
        row.innerHTML = `
            <div class="split-row-fields">
                <div class="split-field split-field-sm">
                    <label class="split-label">Percentual (%)</label>
                    <input type="number" step="0.01" min="0" max="100" class="form-input split-taxa" value="${taxa}" placeholder="Ex: 10">
                </div>
                <div class="split-field split-field-lg">
                    <label class="split-label">Chave Pix de destino (InfoPago)</label>
                    <input type="text" class="form-input split-chave-pix" value="${chave_simples}" placeholder="CPF, CNPJ, e-mail, EVP, ou telefone c/ +55 (ex: +5511999999999)">
                </div>
                <div class="split-field split-field-desc">
                    <label class="split-label">Descrição</label>
                    <input type="text" class="form-input split-desc" value="${desc}" placeholder="Opcional">
                </div>
            </div>
            <button type="button" class="split-remove" onclick="removerLinhasSplit(this)" title="Remover">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        `;
        lista.appendChild(row);
    }

    function removerLinhasSplit(btn) {
        const row   = btn.closest('.split-row');
        const lista = document.getElementById('splitLista');
        row.remove();
        if (lista.children.length === 0) {
            document.getElementById('splitVazio').style.display = 'block';
        }
    }

    function coletarSplits() {
        const rows = document.querySelectorAll('#splitLista .split-row');
        const result = [];
        rows.forEach(row => {
            result.push({
                gateway_nome:    'infopago',
                tipo_split:      'percentual',
                taxa_split:      row.querySelector('.split-taxa').value,
                chave_pix_split: row.querySelector('.split-chave-pix').value,
                descricao:       row.querySelector('.split-desc').value,
            });
        });
        return result;
    }

    function confirmarExcluir(id, nome) {
        if (!confirm('Tem certeza que deseja excluir o usuário "' + nome + '"? Esta ação não pode ser desfeita.')) return;
        const data = new FormData();
        data.append('id', id);
        fetch('../ajax/deletar_usuario.php', { method: 'POST', body: data })
            .then(r => r.json())
            .then(res => {
                if (res.sucesso) {
                    location.reload();
                } else {
                    alert(res.erro || 'Erro ao excluir usuário.');
                }
            })
            .catch(() => alert('Erro de conexão.'));
    }

    document.getElementById('modalAdicionar').addEventListener('click', function(e) { if (e.target === this) fecharModalAdicionar(); });
    document.getElementById('modalEditar').addEventListener('click', function(e) { if (e.target === this) fecharModalEditar(); });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            fecharModal();
            fecharModalAdicionar();
            fecharModalEditar();
        }
    });

    function abrirDetalhes(id) {
        const modal = document.getElementById('modalDetalhes');
        const loading = document.getElementById('modalLoading');
        const dados = document.getElementById('modalDados');
        
        modal.style.display = 'flex';
        loading.style.display = 'block';
        dados.style.display = 'none';

        document.getElementById('modalNome').innerText = 'Carregando...';
        
        fetch(`../ajax/detalhes_usuario.php?id=${id}`)
            .then(response => response.json())
            .then(data => {
                if (data.erro) {
                    alert(data.erro);
                    fecharModal();
                    return;
                }

                document.getElementById('modalNome').innerText = data.nome;
                document.getElementById('modalEmail').innerText = data.email;
                
                const data_criacao = new Date(data.criado_em);
                document.getElementById('modalData').innerText = data_criacao.toLocaleDateString('pt-BR') + ' ' + data_criacao.toLocaleTimeString('pt-BR');

                const total = parseFloat(data.total_vendas).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
                document.getElementById('modalVendas').innerText = total;
                document.getElementById('modalQtdVendas').innerText = data.qtd_vendas;

                const lista_bots = document.getElementById('listaBots');
                lista_bots.innerHTML = '';
                if (data.bots && data.bots.length > 0) {
                    data.bots.forEach(bot => {
                        const div = document.createElement('div');
                        div.className = 'bot-item';
                        div.innerHTML = `
                            <div class="bot-icon">🤖</div>
                            <div class="bot-info">
                                <strong>${escaparHtml(bot.nome)}</strong>
                                <span>Criado em: ${new Date(bot.criado_em).toLocaleDateString('pt-BR')}</span>
                            </div>
                        `;
                        lista_bots.appendChild(div);
                    });
                } else {
                    lista_bots.innerHTML = '<p class="text-muted">Nenhum bot cadastrado.</p>';
                }

                const lista_logs = document.getElementById('listaLogs');
                lista_logs.innerHTML = '';
                if (data.logs && data.logs.length > 0) {
                    data.logs.forEach(log => {
                        const li = document.createElement('li');
                        li.innerHTML = `
                            <span class="log-date">${new Date(log.data_hora).toLocaleString('pt-BR')}</span>
                            <span class="log-action">${escaparHtml(log.titulo || log.tipo)}</span>
                            <span class="log-desc">${escaparHtml(log.descricao || '')}</span>
                        `;
                        lista_logs.appendChild(li);
                    });
                } else {
                    lista_logs.innerHTML = '<li class="text-muted">Nenhuma atividade recente.</li>';
                }
                
                loading.style.display = 'none';
                dados.style.display = 'block';
            })
            .catch(err => {
                console.error(err);
                alert('Erro ao carregar detalhes.');
                fecharModal();
            });
    }

    function fecharModal() {
        document.getElementById('modalDetalhes').style.display = 'none';
    }

     document.getElementById('modalDetalhes').addEventListener('click', function(e) {
         if (e.target === this) {
             fecharModal();
         }
     });
     
 </script>
 
 <style>
    /* O grosso do visual reaproveita assets/css/coyote.css; aqui só o que é específico desta página */
    /* .form-input e .text-muted são usados nos templates HTML montados pelo <script> abaixo */
    .form-input { width: 100%; padding: 9px 11px; border: 1px solid var(--bd); border-radius: 8px; background: var(--p2); color: var(--t); font: 600 12.5px 'Manrope', sans-serif; }
    .form-input:focus { outline: none; border-color: var(--or); }
    .text-muted { color: var(--m); font-style: italic; font-size: 12.5px; text-align: center; display: block; padding: 16px 0; }
    .msg-box { padding: 10px 14px; border-radius: 10px; font-size: 12.5px; margin-bottom: 12px; border: 1px solid transparent; }
    .msg-erro { background: var(--dasoft); color: var(--da); border-color: var(--da); }
    .msg-sucesso { background: var(--oksoft); color: var(--ok); border-color: var(--ok); }

    .spinner {
        border: 3px solid var(--bd); border-left-color: var(--or);
        border-radius: 50%; width: 36px; height: 36px;
        animation: girar 1s linear infinite; margin: 0 auto 16px;
    }
    @keyframes girar { 100% { transform: rotate(360deg); } }

    .bot-item {
        display: flex; align-items: center; gap: 14px; padding: 11px;
        background: var(--p2); border-radius: 10px; margin-bottom: 10px;
        border: 1px solid var(--bd);
    }
    .bot-icon {
        font-size: 18px; background: var(--orsoft); color: var(--or); width: 38px; height: 38px;
        display: flex; align-items: center; justify-content: center; border-radius: 9px; flex-shrink: 0;
    }
    .bot-info strong { display: block; font-size: 13px; margin-bottom: 2px; }
    .bot-info span { font-size: 11.5px; color: var(--m); }

    .timeline-logs { list-style: none; padding: 0; margin: 0; position: relative; }
    .timeline-logs::before { content: ''; position: absolute; left: 7px; top: 10px; bottom: 10px; width: 2px; background: var(--bd); z-index: 0; }
    .timeline-logs li { padding: 0 0 20px 26px; position: relative; }
    .timeline-logs li::before {
        content: ''; position: absolute; left: 0; top: 4px;
        width: 14px; height: 14px; border-radius: 50%;
        background: var(--p); border: 3px solid var(--or);
        z-index: 1;
    }
    .timeline-logs li:last-child { padding-bottom: 0; }
    .log-date { display: block; color: var(--m); font-size: 11px; margin-bottom: 3px; }
    .log-action { display: block; font-weight: 700; font-size: 13px; margin-bottom: 2px; }
    .log-desc { color: var(--m); font-size: 12.5px; line-height: 1.4; }

    .split-row { display: flex; align-items: flex-end; gap: 8px; margin-bottom: 10px; padding: 12px; background: var(--p2); border: 1px solid var(--bd); border-radius: 10px; }
    .split-row-fields { display: flex; flex-wrap: wrap; gap: 8px; flex: 1; }
    .split-field { display: flex; flex-direction: column; min-width: 90px; }
    .split-field-sm { max-width: 88px; }
    .split-field-lg { flex: 1; min-width: 120px; }
    .split-field-desc { flex: 1; min-width: 100px; }
    .split-label { font: 700 10.5px 'Manrope', sans-serif; color: var(--m); text-transform: uppercase; letter-spacing: .04em; margin-bottom: 4px; }
    .split-remove { flex-shrink: 0; background: var(--dasoft); border: none; color: var(--da); width: 30px; height: 30px; border-radius: 8px; cursor: pointer; display: flex; align-items: center; justify-content: center; }
    .split-remove:hover { background: var(--da); color: #fff; }
 </style>

<script src="../assets/js/tema.js"></script>
