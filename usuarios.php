<?php declare(strict_types=1);
require_once __DIR__ . '/funcoes/usuario.php';
require_once __DIR__ . '/funcoes/paginador.php';
verificarAdmin();

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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="dashboard-layout">
    <?php include 'sidebar.php'; ?>
    
    <main class="main-content">
        <div class="cabecalho-pagina">
            <div>
                <h1>Usuários</h1>
                <p>Gerencie os usuários da plataforma.</p>
            </div>
            <div class="acoes">
                <button class="botao botao-primario" onclick="abrirModalAdicionar()">Adicionar Usuário</button>
            </div>
        </div>

        <div class="painel">
            <div class="table-responsive">
                <table class="table">
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
                            <td>#<?php echo $u['id']; ?></td>
                            <td>
                                <div class="user-info">
                                    <div class="user-avatar"><?php echo strtoupper(substr($u['nome'], 0, 1)); ?></div>
                                    <span><?php echo htmlspecialchars($u['nome']); ?></span>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($u['email']); ?></td>
                            <td>
                                <span class="badge <?php echo $u['perfil'] === 'admin' ? 'badge-admin' : 'badge-user'; ?>">
                                    <?php echo ucfirst($u['perfil']); ?>
                                </span>
                            </td>
                            <td><?php echo $u['total_bots']; ?></td>
                            <td>R$ <?php echo number_format((float)$u['total_vendas'], 2, ',', '.'); ?></td>
                            <td><?php echo date('d/m/Y H:i', strtotime($u['criado_em'])); ?></td>
                            <td>
                                <div class="actions">
                                    <button class="btn-icon ver-detalhes" onclick="abrirDetalhes(<?php echo $u['id']; ?>)" title="Ver Detalhes">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                    </button>
                                    <button class="btn-icon editar" onclick="abrirModalEditar(<?php echo $u['id']; ?>, '<?php echo addslashes(htmlspecialchars($u['nome'])); ?>', '<?php echo addslashes(htmlspecialchars($u['email'])); ?>', '<?php echo $u['perfil']; ?>')" title="Editar"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg></button>
                                    <button class="btn-icon excluir" onclick="confirmarExcluir(<?php echo $u['id']; ?>, '<?php echo addslashes(htmlspecialchars($u['nome'])); ?>')" title="Excluir"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg></button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($usuarios)): ?>
                        <tr>
                            <td colspan="6" class="text-center">Nenhum usuário encontrado.</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <?php echo paginador($total_usuarios, $por_pagina); ?>
        </div>
    </main>
</div>

<!-- Modal Adicionar Usuário -->
<div id="modalAdicionar" class="modal-overlay" style="display: none;">
    <div class="modal-content" style="max-width: 460px;">
        <div class="modal-header">
            <h2>Adicionar Usuário</h2>
            <button class="modal-close" onclick="fecharModalAdicionar()">&times;</button>
        </div>
        <div class="modal-body">
            <div id="msgAdicionar" class="msg-box" style="display:none;"></div>
            <form id="formAdicionar" onsubmit="salvarNovoUsuario(event)">
                <div class="form-group">
                    <label>Nome</label>
                    <input type="text" name="nome" class="form-input" required placeholder="Nome completo">
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" class="form-input" required placeholder="email@exemplo.com">
                </div>
                <div class="form-group">
                    <label>Senha</label>
                    <input type="password" name="senha" class="form-input" required minlength="6" placeholder="Mínimo 6 caracteres">
                </div>
                <div class="form-group">
                    <label>Perfil</label>
                    <select name="perfil" class="form-input">
                        <option value="usuario">Usuário</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:20px;">
                    <button type="button" class="botao botao-secundario" onclick="fecharModalAdicionar()">Cancelar</button>
                    <button type="submit" class="botao botao-primario">Criar Usuário</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Editar Usuário -->
<div id="modalEditar" class="modal-overlay" style="display: none;">
    <div class="modal-content" style="max-width: 620px;">
        <div class="modal-header">
            <h2>Editar Usuário</h2>
            <button class="modal-close" onclick="fecharModalEditar()">&times;</button>
        </div>
        <div class="modal-body">
            <div id="msgEditar" class="msg-box" style="display:none;"></div>
            <form id="formEditar" onsubmit="salvarEdicaoUsuario(event)">
                <input type="hidden" name="id" id="editarId">
                <div class="form-group">
                    <label>Nome</label>
                    <input type="text" name="nome" id="editarNome" class="form-input" required>
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" id="editarEmail" class="form-input" required>
                </div>
                <div class="form-group">
                    <label>Nova Senha <small style="color:#94a3b8;">(deixe em branco para manter)</small></label>
                    <input type="password" name="senha" class="form-input" minlength="6" placeholder="Nova senha">
                </div>
                <div class="form-group">
                    <label>Perfil</label>
                    <select name="perfil" id="editarPerfil" class="form-input">
                        <option value="usuario">Usuário</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>

                <!-- Seção de Splits -->
                <div style="margin-top:24px; padding-top:20px; border-top:1px solid #e2e8f0;">
                    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:14px;">
                        <div>
                            <div style="font-size:13px; font-weight:700; color:#374151; text-transform:uppercase; letter-spacing:.5px;">Splits de Pagamento</div>
                            <div style="font-size:12px; color:#94a3b8; margin-top:2px;">Percentual/valor repassado automaticamente a cada venda</div>
                        </div>
                        <button type="button" class="botao botao-secundario" style="font-size:12px; padding:6px 12px;" onclick="adicionarLinhasSplit()">+ Adicionar Split</button>
                    </div>
                    <div id="splitCarregando" style="color:#94a3b8; font-size:13px; font-style:italic; display:none;">Carregando splits...</div>
                    <div id="splitLista"></div>
                    <div id="splitVazio" style="color:#94a3b8; font-size:13px; font-style:italic; display:none;">Nenhum split configurado.</div>
                </div>

                <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:24px;">
                    <button type="button" class="botao botao-secundario" onclick="fecharModalEditar()">Cancelar</button>
                    <button type="submit" class="botao botao-primario">Salvar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal de Detalhes -->
<div id="modalDetalhes" class="modal-overlay" style="display: none;">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="modalNome">Carregando...</h2>
            <button class="modal-close" onclick="fecharModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div id="modalLoading" class="text-center p-4">
                <div class="spinner"></div>
                <p>Buscando informações...</p>
            </div>
            
            <div id="modalDados" style="display: none;">
                <div class="info-grid">
                    <div class="info-card">
                        <small>Email</small>
                        <p id="modalEmail">-</p>
                    </div>
                    <div class="info-card">
                        <small>Cadastrado em</small>
                        <p id="modalData">-</p>
                    </div>
                    <div class="info-card highlight">
                        <small>Total Transacionado</small>
                        <p id="modalVendas">R$ 0,00</p>
                    </div>
                    <div class="info-card">
                        <small>Qtd. Vendas</small>
                        <p id="modalQtdVendas">0</p>
                    </div>
                </div>

                <div class="section-title">Meus Bots</div>
                <div id="listaBots" class="lista-simples">
                    <!-- JS preenche -->
                </div>

                <div class="section-title">Últimas Atividades</div>
                <ul id="listaLogs" class="timeline-logs">
                    <!-- JS preenche -->
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
    // === Modal Adicionar ===
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

        fetch('ajax/adicionar_usuario.php', { method: 'POST', body: data })
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

    // === Modal Editar ===
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
        const data = new FormData(form);

        fetch('ajax/editar_usuario.php', { method: 'POST', body: data })
            .then(r => r.json())
            .then(res => {
                if (!res.sucesso) {
                    msg.textContent = res.erro || 'Erro ao salvar.';
                    msg.className = 'msg-box msg-erro';
                    msg.style.display = 'block';
                    return;
                }
                // Salvar splits em seguida
                const userId = document.getElementById('editarId').value;
                const splits = coletarSplits();
                const fd = new FormData();
                fd.append('id_usuario', userId);
                fd.append('splits', JSON.stringify(splits));
                return fetch('ajax/salvar_splits_usuario.php', { method: 'POST', body: fd })
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

    // === Splits ===
    function carregarSplits(userId) {
        const lista    = document.getElementById('splitLista');
        const vazio    = document.getElementById('splitVazio');
        const loading  = document.getElementById('splitCarregando');
        lista.innerHTML = '';
        vazio.style.display    = 'none';
        loading.style.display  = 'block';

        fetch('ajax/listar_splits_usuario.php?id=' + userId)
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

        const gw   = dados?.gateway_nome  || 'efi';
        const tipo = dados?.tipo_split     || 'percentual';
        const taxa = dados?.taxa_split     || '';
        const desc  = dados?.descricao      || '';
        const isEfi = gw === 'efi';

        // Para EFI: chave_pix_split é JSON {"conta":"...","cpf":"..."}, para PushinPay: string simples
        let contaEfi = '', cpfEfi = '', chaveSimples = '';
        const raw = dados?.chave_pix_split || '';
        if (isEfi) {
            try {
                const parsed = JSON.parse(raw);
                contaEfi = parsed.conta || '';
                cpfEfi   = parsed.cpf   || '';
            } catch(e) {
                contaEfi = raw; // fallback: valor direto
            }
        } else {
            chaveSimples = raw;
        }

        const row = document.createElement('div');
        row.className = 'split-row';
        row.innerHTML = `
            <div class="split-row-fields">
                <div class="split-field">
                    <label class="split-label">Gateway</label>
                    <select class="form-input split-gateway" onchange="alternarCamposGateway(this)">
                        <option value="efi"       ${gw === 'efi'       ? 'selected' : ''}>Efí Bank</option>
                        <option value="pushinpay" ${gw === 'pushinpay' ? 'selected' : ''}>PushinPay</option>
                    </select>
                </div>
                <div class="split-field">
                    <label class="split-label">Tipo</label>
                    <select class="form-input split-tipo">
                        <option value="percentual" ${tipo === 'percentual' ? 'selected' : ''}>% Percentual</option>
                        <option value="fixo"       ${tipo === 'fixo'       ? 'selected' : ''}>R$ Fixo</option>
                    </select>
                </div>
                <div class="split-field split-field-sm">
                    <label class="split-label">Valor</label>
                    <input type="number" step="0.01" min="0" class="form-input split-taxa" value="${taxa}" placeholder="Ex: 10">
                </div>
                <!-- Campos EFI -->
                <div class="split-field split-field-lg split-campos-efi" style="${isEfi ? '' : 'display:none;'}">
                    <label class="split-label">Nº Conta EFI</label>
                    <input type="text" class="form-input split-conta-efi" value="${contaEfi}" placeholder="Ex: 8901031">
                </div>
                <div class="split-field split-campo-cpf" style="${isEfi ? '' : 'display:none;'}">
                    <label class="split-label">CPF Titular EFI</label>
                    <input type="text" class="form-input split-cpf-efi" value="${cpfEfi}" placeholder="Só números">
                </div>
                <!-- Campo PushinPay -->
                <div class="split-field split-field-lg split-campos-pushinpay" style="${isEfi ? 'display:none;' : ''}">
                    <label class="split-label">Account ID <span style="color:#94a3b8;font-weight:400;">(PushinPay)</span></label>
                    <input type="text" class="form-input split-chave-pushinpay" value="${chaveSimples}" placeholder="Seu account_id">
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

    function alternarCamposGateway(sel) {
        const row      = sel.closest('.split-row');
        const isEfi    = sel.value === 'efi';
        row.querySelectorAll('.split-campos-efi, .split-campo-cpf').forEach(el => el.style.display = isEfi ? '' : 'none');
        row.querySelectorAll('.split-campos-pushinpay').forEach(el => el.style.display = isEfi ? 'none' : '');
    }

    function coletarSplits() {
        const rows = document.querySelectorAll('#splitLista .split-row');
        const result = [];
        rows.forEach(row => {
            const gw = row.querySelector('.split-gateway').value;
            let chave;
            if (gw === 'efi') {
                const conta = row.querySelector('.split-conta-efi').value.replace(/\D/g, '');
                const cpf   = row.querySelector('.split-cpf-efi').value.replace(/\D/g, '');
                chave = JSON.stringify({ conta, cpf });
            } else {
                chave = row.querySelector('.split-chave-pushinpay').value;
            }
            result.push({
                gateway_nome:    gw,
                tipo_split:      row.querySelector('.split-tipo').value,
                taxa_split:      row.querySelector('.split-taxa').value,
                chave_pix_split: chave,
                descricao:       row.querySelector('.split-desc').value,
            });
        });
        return result;
    }

    // === Excluir ===
    function confirmarExcluir(id, nome) {
        if (!confirm('Tem certeza que deseja excluir o usuário "' + nome + '"? Esta ação não pode ser desfeita.')) return;
        const data = new FormData();
        data.append('id', id);
        fetch('ajax/deletar_usuario.php', { method: 'POST', body: data })
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

    // Fechar modais adicionais ao clicar fora
    document.getElementById('modalAdicionar').addEventListener('click', function(e) { if (e.target === this) fecharModalAdicionar(); });
    document.getElementById('modalEditar').addEventListener('click', function(e) { if (e.target === this) fecharModalEditar(); });

    // ESC fecha todos os modais
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
        
        // Limpar dados anteriores
        document.getElementById('modalNome').innerText = 'Carregando...';
        
        fetch(`ajax/detalhes_usuario.php?id=${id}`)
            .then(response => response.json())
            .then(data => {
                if (data.erro) {
                    alert(data.erro);
                    fecharModal();
                    return;
                }
                
                // Preencher dados básicos
                document.getElementById('modalNome').innerText = data.nome;
                document.getElementById('modalEmail').innerText = data.email;
                
                const dataCriacao = new Date(data.criado_em);
                document.getElementById('modalData').innerText = dataCriacao.toLocaleDateString('pt-BR') + ' ' + dataCriacao.toLocaleTimeString('pt-BR');
                
                // Formatar moeda
                const total = parseFloat(data.total_vendas).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
                document.getElementById('modalVendas').innerText = total;
                document.getElementById('modalQtdVendas').innerText = data.qtd_vendas;
                
                // Bots
                const listaBots = document.getElementById('listaBots');
                listaBots.innerHTML = '';
                if (data.bots && data.bots.length > 0) {
                    data.bots.forEach(bot => {
                        const div = document.createElement('div');
                        div.className = 'bot-item';
                        div.innerHTML = `
                            <div class="bot-icon">🤖</div>
                            <div class="bot-info">
                                <strong>${bot.nome}</strong>
                                <span>Criado em: ${new Date(bot.criado_em).toLocaleDateString('pt-BR')}</span>
                            </div>
                        `;
                        listaBots.appendChild(div);
                    });
                } else {
                    listaBots.innerHTML = '<p class="text-muted">Nenhum bot cadastrado.</p>';
                }
                
                // Logs
                const listaLogs = document.getElementById('listaLogs');
                listaLogs.innerHTML = '';
                if (data.logs && data.logs.length > 0) {
                    data.logs.forEach(log => {
                        const li = document.createElement('li');
                        li.innerHTML = `
                            <span class="log-date">${new Date(log.data_hora).toLocaleString('pt-BR')}</span>
                            <span class="log-action">${log.titulo || log.tipo}</span>
                            <span class="log-desc">${log.descricao || ''}</span>
                        `;
                        listaLogs.appendChild(li);
                    });
                } else {
                    listaLogs.innerHTML = '<li class="text-muted">Nenhuma atividade recente.</li>';
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

    // Fechar ao clicar fora
     document.getElementById('modalDetalhes').addEventListener('click', function(e) {
         if (e.target === this) {
             fecharModal();
         }
     });
     
 </script>
 
 <style>
    /* Estilos Gerais da Tabela */
     .table-responsive { overflow-x: auto; }
     .table { width: 100%; border-collapse: collapse; }
     .table th, .table td { padding: 12px 16px; text-align: left; border-bottom: 1px solid var(--border); }
     .table th { font-weight: 600; color: var(--muted); font-size: 13px; background: #f8fafc; }
     .table td { font-size: 14px; color: var(--text); }
     .user-info { display: flex; align-items: center; gap: 10px; }
     .user-avatar { width: 32px; height: 32px; border-radius: 50%; background: var(--primary); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 14px; }
     .badge-admin { background: #ede9fe; color: #7c3aed; padding: 2px 8px; border-radius: 99px; font-size: 11px; font-weight: 600; }
     .badge-user { background: #e0f2fe; color: #0284c7; padding: 2px 8px; border-radius: 99px; font-size: 11px; font-weight: 600; }
     .text-center { text-align: center; }
     
     /* Modal Styles Renovado */
     .modal-overlay {
         position: fixed; top: 0; left: 0; right: 0; bottom: 0;
         background: rgba(15, 23, 42, 0.6); /* Fundo escuro semi-transparente */
         display: flex; align-items: center; justify-content: center;
         z-index: 9999;
         backdrop-filter: blur(4px);
         padding: 20px;
         opacity: 0; animation: fadeIn 0.2s forwards;
     }
     @keyframes fadeIn { to { opacity: 1; } }

     .modal-content {
         background: #ffffff; /* Garante fundo branco */
         width: 100%; max-width: 600px;
         border-radius: 16px;
         box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
         max-height: 90vh;
         overflow-y: auto;
         animation: slideUp 0.3s cubic-bezier(0.16, 1, 0.3, 1);
         position: relative;
         border: 1px solid var(--border);
         display: flex; flex-direction: column;
     }
     @keyframes slideUp {
         from { transform: translateY(30px) scale(0.95); opacity: 0; }
         to { transform: translateY(0) scale(1); opacity: 1; }
     }

     .modal-header {
         padding: 20px 24px;
         border-bottom: 1px solid var(--border);
         display: flex; justify-content: space-between; align-items: center;
         background: #f8fafc;
         border-radius: 16px 16px 0 0;
         flex-shrink: 0;
     }
     .modal-header h2 { 
         margin: 0; font-size: 18px; font-weight: 600; color: #1e293b; 
     }
     .modal-close {
         background: transparent; border: none; font-size: 24px; cursor: pointer; color: #94a3b8;
         width: 32px; height: 32px; display: flex; align-items: center; justify-content: center;
         border-radius: 50%; transition: 0.2s;
     }
     .modal-close:hover { background: #fee2e2; color: #ef4444; }
     
     .modal-body { padding: 24px; overflow-y: auto; }
     
     .info-grid {
         display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 32px;
     }
     .info-card {
         background: #ffffff; 
         padding: 16px; 
         border-radius: 12px; 
         border: 1px solid #e2e8f0;
         transition: 0.2s;
         box-shadow: 0 1px 2px rgba(0,0,0,0.05);
     }
     .info-card:hover { border-color: var(--primary); transform: translateY(-2px); box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
     
     .info-card small { 
         display: block; color: #64748b; 
         font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;
         margin-bottom: 6px; 
     }
     .info-card p { 
         margin: 0; font-weight: 600; font-size: 15px; color: #0f172a; 
         word-break: break-all;
     }
     
     /* Card Destaque */
     .info-card.highlight {
         background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
         border-color: #bfdbfe;
     }
     .info-card.highlight small { color: #1d4ed8; }
     .info-card.highlight p { color: #1e3a8a; font-size: 20px; font-weight: 700; }
     
     .section-title {
         font-size: 12px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 1px;
         margin-bottom: 16px; padding-bottom: 8px; border-bottom: 2px solid #f1f5f9;
     }
     
     .bot-item {
         display: flex; align-items: center; gap: 16px; padding: 12px;
         background: #f8fafc; border-radius: 10px; margin-bottom: 12px;
         border: 1px solid transparent; transition: 0.2s;
     }
     .bot-item:hover { background: #fff; border-color: #e2e8f0; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
     
     .bot-icon { 
        font-size: 20px; background: #e0f2fe; color: #0284c7; width: 40px; height: 40px; 
        display: flex; align-items: center; justify-content: center; border-radius: 8px; flex-shrink: 0;
     }
     .bot-info strong { display: block; font-size: 14px; color: #0f172a; margin-bottom: 2px; }
     .bot-info span { font-size: 12px; color: #64748b; }
     
     .timeline-logs {
         list-style: none; padding: 0; margin: 0; position: relative;
     }
     .timeline-logs::before {
         content: ''; position: absolute; left: 7px; top: 10px; bottom: 10px;
         width: 2px; background: #e2e8f0; z-index: 0;
     }
     .timeline-logs li {
         padding: 0 0 24px 28px; position: relative;
     }
     .timeline-logs li::before {
         content: ''; position: absolute; left: 0; top: 4px;
         width: 16px; height: 16px; border-radius: 50%;
         background: #fff; border: 4px solid var(--primary);
         z-index: 1; box-shadow: 0 0 0 2px #fff;
     }
     .timeline-logs li:last-child { padding-bottom: 0; }
     
     .log-date { display: block; color: #94a3b8; font-size: 11px; margin-bottom: 4px; font-weight: 500; }
     .log-action { display: block; font-weight: 600; color: #334155; font-size: 14px; margin-bottom: 2px; }
     .log-desc { color: #64748b; font-size: 13px; line-height: 1.4; }
     
     .form-group { margin-bottom: 16px; }
     .form-group label { display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px; }
     .form-input { width: 100%; padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; color: #111827; box-sizing: border-box; transition: border-color 0.2s; }
     .form-input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(37,99,235,0.1); }
     .botao-secundario { background: #f1f5f9; color: #374151; border: 1px solid #e2e8f0; }
     .botao-secundario:hover { background: #e2e8f0; }
     .msg-box { padding: 10px 14px; border-radius: 8px; font-size: 13px; margin-bottom: 12px; }
     .msg-erro { background: #fee2e2; color: #dc2626; border: 1px solid #fca5a5; }
     .msg-sucesso { background: #dcfce7; color: #16a34a; border: 1px solid #86efac; }

     .spinner {
         border: 3px solid rgba(0,0,0,0.1); border-left-color: var(--primary);
         border-radius: 50%; width: 40px; height: 40px;
         animation: spin 1s linear infinite; margin: 0 auto 16px;
     }
     @keyframes spin { 100% { transform: rotate(360deg); } }
     .text-muted { color: #94a3b8; font-style: italic; font-size: 13px; text-align: center; display: block; padding: 20px 0; }

    /* Split rows */
    .split-row { display: flex; align-items: flex-end; gap: 8px; margin-bottom: 10px; padding: 12px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; }
    .split-row-fields { display: flex; flex-wrap: wrap; gap: 8px; flex: 1; }
    .split-field { display: flex; flex-direction: column; min-width: 90px; }
    .split-field-sm { max-width: 88px; }
    .split-campo-cpf { min-width: 110px; max-width: 140px; }
    .split-field-lg { flex: 1; min-width: 120px; }
    .split-field-desc { flex: 1; min-width: 100px; }
    .split-label { font-size: 11px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: .4px; margin-bottom: 4px; }
    .split-chave-hint { font-weight: 400; color: #94a3b8; text-transform: none; letter-spacing: 0; }
    .split-remove { flex-shrink: 0; background: #fee2e2; border: none; color: #dc2626; width: 30px; height: 30px; border-radius: 7px; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: background .15s; }
    .split-remove:hover { background: #fecaca; }
 </style>
