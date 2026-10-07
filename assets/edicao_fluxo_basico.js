(function ($) {
    const api_url = 'api.php';
    let lista_grupos_usuario = [];
    let planos = [];
    let midia_tipo_atual = 'none';
    let midia_path_atual = '';
    let carregando = false;
    let ids_bots_vinculados = [];
    let cache_chat_id_salvo = '';
    const oferta_tipos = ['upsell', 'downsell', 'order_bump'];
    const max_bots = 10;
    let preco_minimo = 0;

    function escaparHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function exibirAviso(message, type = 'sucesso') {
        const $toast = $('#toast');
        $toast.removeClass('sucesso erro visivel').addClass(type).text(message);
        requestAnimationFrame(() => $toast.addClass('visivel'));
        clearTimeout(window.__toastTimeout);
        window.__toastTimeout = setTimeout(() => $toast.removeClass('visivel'), 3000);
    }

    function carregarLimitesPagamento() {
        return $.getJSON(api_url + '?action=limites_pagamento').done(function (resp) {
            if (resp.sucesso) preco_minimo = Number(resp.minimo) || 0;
        });
    }

    // Mesma regra do servidor: Pix abaixo do mínimo aceito pela OmegaPayments (com a comissão da plataforma) falha na hora da venda.
    function validarPrecoMinimo(d) {
        if (!(preco_minimo > 0)) return '';
        const reais = function (v) { return 'R$ ' + (Number(v) || 0).toFixed(2).replace('.', ','); };
        const problemas = [];
        d.planos.forEach(function (p) { if (p.valor > 0 && p.valor < preco_minimo) problemas.push('o plano "' + (p.nome || 'sem nome') + '" (' + reais(p.valor) + ')'); });
        if (d.packs.ativo) d.packs.itens.forEach(function (k) { if (k.ativo !== false && k.preco > 0 && k.preco < preco_minimo) problemas.push('o pack "' + (k.nome || 'sem nome') + '" (' + reais(k.preco) + ')'); });
        ['upsell', 'downsell'].forEach(function (tipo) {
            const o = d.ofertas[tipo];
            const destino = d.planos.filter(function (p) { return String(p.id) === String(o.id_plano_destino); })[0];
            if (o.ativo && destino) {
                const final = Math.round(destino.valor * (1 - (o.desconto_percentual || 0) / 100) * 100) / 100;
                if (final > 0 && final < preco_minimo) problemas.push('o ' + (tipo === 'upsell' ? 'Upsell' : 'Downsell') + ' (' + reais(final) + ' após o desconto)');
            }
        });
        return problemas.length ? 'A OmegaPayments só aceita Pix a partir de ' + reais(preco_minimo) + ' com a comissão atual da plataforma. Ajuste: ' + problemas.slice(0, 4).join('; ') + '.' : '';
    }

    function carregarGruposUsuario() {
        return $.getJSON(api_url + '?action=listar_grupos_usuario').done(function (resp) {
            if (resp.sucesso) {
                lista_grupos_usuario = resp.grupos || [];
            }
        });
    }

    function optsGrupos(id_grupo_selecionado) {
        let html = '<option value="">Nenhum (apenas venda)</option>';
        lista_grupos_usuario.forEach(function (g) {
            const sel = (String(g.id_telegram) === String(id_grupo_selecionado)) ? ' selected' : '';
            html += '<option value="' + escaparHtml(g.id_telegram) + '"' + sel + '>' + escaparHtml(g.titulo) + '</option>';
        });
        return html;
    }

    function renderPlanos() {
        const $lista = $('#lista-planos');
        if (!planos.length) {
            $lista.html('<p class="texto-ajuda">Nenhum plano ainda. Adicione pelo menos um.</p>');
            return;
        }
        let html = '';
        planos.forEach(function (p, i) {
            html += '' +
                '<div class="painel-plano" data-index="' + i + '" style="border:1px solid var(--bd); border-radius:10px; padding:12px; margin-bottom:10px;">' +
                '  <div class="grade grade-2 grade-compacta">' +
                '    <div class="campo"><label>Nome do plano</label><input type="text" class="campo-plano-nome" value="' + escaparHtml(p.nome || '') + '" placeholder="Ex.: Mensal"></div>' +
                '    <div class="campo"><label>Valor (R$)</label><input type="number" step="0.01" min="0" class="campo-plano-valor" value="' + (p.valor != null ? p.valor : 0) + '"></div>' +
                '  </div>' +
                '  <div class="grade grade-2 grade-compacta" style="margin-top:8px;">' +
                '    <div class="campo"><label>Tempo de acesso</label>' +
                '      <div style="display:flex; gap:6px;">' +
                '        <input type="number" min="1" class="campo-plano-dias" value="' + (p.dias_acesso || 30) + '" style="max-width:80px;">' +
                '        <select class="campo-plano-unidade">' +
                '          <option value="dias"' + (( !p.unidade_acesso || p.unidade_acesso === 'dias') ? ' selected' : '') + '>Dias</option>' +
                '          <option value="horas"' + (p.unidade_acesso === 'horas' ? ' selected' : '') + '>Horas</option>' +
                '          <option value="minutos"' + (p.unidade_acesso === 'minutos' ? ' selected' : '') + '>Minutos</option>' +
                '        </select>' +
                '      </div>' +
                '    </div>' +
                '    <div class="campo"><label>Grupo de entrega</label><select class="campo-plano-grupo">' + optsGrupos(p.id_grupo) + '</select></div>' +
                '  </div>' +
                '  <div class="grade grade-2 grade-compacta" style="margin-top:8px;">' +
                '    <div class="campo"><label>Cor do botão</label>' +
                '      <select class="campo-plano-cor">' +
                '        <option value=""' + (!p.cor ? ' selected' : '') + '>Padrão</option>' +
                '        <option value="primary"' + (p.cor === 'primary' ? ' selected' : '') + '>Azul</option>' +
                '        <option value="success"' + (p.cor === 'success' ? ' selected' : '') + '>Verde</option>' +
                '        <option value="danger"' + (p.cor === 'danger' ? ' selected' : '') + '>Vermelho</option>' +
                '      </select>' +
                '    </div>' +
                '  </div>' +
                '  <button type="button" class="botao botao-claro remover-plano" style="margin-top:8px;">Remover plano</button>' +
                '</div>';
        });
        $lista.html(html);
    }

    function sincronizarPlanosDoDom() {
        $('#lista-planos .painel-plano').each(function (i) {
            const $p = $(this);
            planos[i] = {
                id: planos[i].id || ('plano_' + Date.now() + '_' + i),
                nome: $p.find('.campo-plano-nome').val().trim(),
                valor: parseFloat($p.find('.campo-plano-valor').val()) || 0,
                dias_acesso: parseInt($p.find('.campo-plano-dias').val(), 10) || 30,
                unidade_acesso: $p.find('.campo-plano-unidade').val(),
                id_grupo: $p.find('.campo-plano-grupo').val(),
                cor: $p.find('.campo-plano-cor').val() || ''
            };
        });
    }

    function atualizarPreviaMidia() {
        const $previa = $('#bv-midia-previa');
        if (midia_path_atual) {
            $previa.text('Mídia anexada (' + midia_tipo_atual + ') — clique para trocar');
        } else {
            $previa.text('Sem mídia — clique para adicionar');
        }
    }

    // Canais/grupos onde algum bot vinculado a este fluxo já foi adicionado (tabela bot_grupos).
    function renderCacheGrupos() {
        const $sel = $('#cache-chat-id');
        const atual = String($sel.val() || cache_chat_id_salvo || '');
        let html = '<option value="">Nenhum (sem cache)</option>';
        let achou = false;
        lista_grupos_usuario.forEach(function (g) {
            if (ids_bots_vinculados.indexOf(String(g.bot_id)) === -1) return;
            const sel = String(g.id_telegram) === atual;
            achou = achou || sel;
            html += '<option value="' + escaparHtml(g.id_telegram) + '"' + (sel ? ' selected' : '') + '>' + escaparHtml(g.titulo || g.id_telegram) + ' (' + escaparHtml(g.nome_bot || 'bot') + ')</option>';
        });
        if (atual && !achou) {
            html += '<option value="' + escaparHtml(atual) + '" selected>' + escaparHtml(atual) + ' (grupo salvo)</option>';
        }
        $sel.html(html);
        $('#cache-aviso').text(ids_bots_vinculados.length
            ? 'Só aparecem canais/grupos onde um bot deste fluxo é administrador. Não achou? Adicione o bot e clique em atualizar.'
            : 'Vincule um bot ao fluxo para ver os canais/grupos dele.');
    }

    function marcarSujo() {
        if (carregando) return;
        $('#status-salvo').addClass('sujo').text('Alterações não salvas');
    }

    function marcarLimpo() {
        $('#status-salvo').removeClass('sujo').text('Tudo salvo');
    }

    const total_secoes = 11;

    // Só "Bots" fica livre num fluxo novo; as demais liberam depois que o fluxo é salvo.
    function atualizarBloqueioSecoes() {
        const salvo = !!$('#id-fluxo').val();
        $('.basico-nav-item').each(function () {
            const bloquear = !salvo && $(this).data('secao') !== 'bots';
            $(this).toggleClass('bloqueado', bloquear);
        });
        $('#nav-aviso').prop('hidden', salvo);
        atualizarNavegacaoMobile();
        $('#nav-contagem').text((salvo ? total_secoes : 1) + ' de ' + total_secoes + ' seções');
    }

    function atualizarTituloFluxo() {
        $('#titulo-fluxo').text($('#nome-fluxo').val().trim() || 'Novo fluxo');
    }

    // ===== Assinatura (renovação) =====
    const as_msg_antes = 'Olá {nome}! 👋\n\nSua assinatura do plano {plano} expira {prazo}.\n\nRenove agora e continue aproveitando todos os benefícios! ✨';
    const as_msg_exp = '{nome}, sua assinatura expirou! 😢\n\nRenove agora para continuar com acesso ao conteúdo exclusivo.\n\nClique no botão abaixo para renovar com desconto especial! 👇';
    let as_dias = [];
    let as_horarios = [];
    let as_midia = { antes: { tipo: 'none', path: '' }, expirar: { tipo: 'none', path: '' } };
    let as_bloco_upload = '';

    function renderAssinatura() {
        $('#as-dias .as-chip').each(function () { $(this).toggleClass('ativo', as_dias.indexOf(parseInt($(this).data('dia'), 10)) !== -1); });
        const qtd = parseInt($('#as-dia-qtd').val(), 10) || 1;
        while (as_horarios.length > qtd) as_horarios.pop();
        let html = '';
        for (let h = 0; h < 24; h++) {
            const rotulo = (h < 10 ? '0' : '') + h + ':00';
            html += '<button type="button" class="as-hora' + (as_horarios.indexOf(rotulo) !== -1 ? ' ativo' : '') + '" data-hora="' + rotulo + '">' + rotulo + '</button>';
        }
        $('#as-horas').html(html);
        $('#as-dia-contagem').text('(selecione ' + qtd + ' — ' + as_horarios.length + ' escolhido' + (as_horarios.length === 1 ? '' : 's') + ')');
        ['antes', 'expirar'].forEach(function (bloco) {
            $('[data-midia-bloco="' + bloco + '"] .as-chip').each(function () { $(this).toggleClass('ativo', $(this).data('tipo') === as_midia[bloco].tipo); });
            $('[data-midia-nome="' + bloco + '"]').text(as_midia[bloco].path ? 'Arquivo: ' + as_midia[bloco].path.replace('uploads/', '') : '');
        });
    }

    function coletarAssinatura() {
        return {
            avisos_antes: {
                ativo: $('#as-antes-ativo').is(':checked'),
                dias: as_dias.slice().sort(function (a, b) { return b - a; }),
                midia_tipo: as_midia.antes.tipo, midia_path: as_midia.antes.path,
                mensagem: $('#as-antes-msg').val()
            },
            avisos_no_dia: { ativo: $('#as-dia-ativo').is(':checked'), horarios: as_horarios.slice().sort() },
            ao_expirar: {
                ativo: $('#as-exp-ativo').is(':checked'),
                midia_tipo: as_midia.expirar.tipo, midia_path: as_midia.expirar.path,
                mensagem: $('#as-exp-msg').val()
            },
            renovacao: { desconto: parseInt($('#as-desconto').val(), 10) || 0 },
            acoes: { expulsar: $('#as-expulsar').is(':checked') }
        };
    }

    function preencherAssinatura(a) {
        a = a || {};
        const antes = a.avisos_antes || {}, dia = a.avisos_no_dia || {}, exp = a.ao_expirar || {};
        $('#as-antes-ativo').prop('checked', !!antes.ativo);
        $('#as-antes-detalhes').prop('hidden', !antes.ativo);
        as_dias = Array.isArray(antes.dias) ? antes.dias.map(Number) : [7, 3, 1, 0];
        as_midia.antes = { tipo: antes.midia_tipo || 'none', path: antes.midia_path || '' };
        $('#as-antes-msg').val(antes.mensagem || as_msg_antes);
        $('#as-dia-ativo').prop('checked', !!dia.ativo);
        $('#as-dia-detalhes').prop('hidden', !dia.ativo);
        as_horarios = Array.isArray(dia.horarios) ? dia.horarios.slice() : ['09:00', '15:00', '21:00'];
        $('#as-dia-qtd').val(String(Math.min(3, Math.max(1, as_horarios.length || 3))));
        $('#as-exp-ativo').prop('checked', !!exp.ativo);
        $('#as-exp-detalhes').prop('hidden', !exp.ativo);
        as_midia.expirar = { tipo: exp.midia_tipo || 'none', path: exp.midia_path || '' };
        $('#as-exp-msg').val(exp.mensagem || as_msg_exp);
        $('#as-desconto').val(String((a.renovacao && a.renovacao.desconto) || 0));
        $('#as-expulsar').prop('checked', !(a.acoes && a.acoes.expulsar === false));
        renderAssinatura();
    }

    // ===== Packs =====
    let packs_itens = [];
    let pack_upload_indice = -1;
    const packs_max = 20;

    function renderPacks() {
        $('#pk-contagem').text(packs_itens.length + '/' + packs_max);
        $('#pk-adicionar').prop('disabled', packs_itens.length >= packs_max);
        $('#pk-lista').html(packs_itens.map(function (p, i) {
            const midia = p.midia_path
                ? '<span>' + ({ image: 'Foto', video: 'Vídeo', audio: 'Áudio' }[p.midia_tipo] || 'Mídia') + ' anexada</span> <button type="button" class="botao botao-claro pk-tirar-midia">Tirar</button>'
                : '<button type="button" class="botao botao-claro pk-midia">+ Adicionar mídia de prévia</button>';
            return '<div class="painel-plano pk-item" data-i="' + i + '" style="border:1px solid var(--bd); border-radius:10px; padding:12px; margin-bottom:10px;">' +
                '<div class="linha-flex" style="justify-content:space-between; margin-bottom:8px;">' +
                '<label class="basico-interruptor sem-caixa" style="margin:0"><input type="checkbox" class="pk-ativo-item"' + (p.ativo !== false ? ' checked' : '') + '> <span>Pack ' + (i + 1) + '</span></label>' +
                '<button type="button" class="botao botao-claro pk-remover">Remover</button></div>' +
                '<div class="grade grade-2 grade-compacta">' +
                '<div class="campo"><label>Emoji</label><input type="text" class="pk-emoji" maxlength="4" value="' + escaparHtml(p.emoji || '📦') + '"></div>' +
                '<div class="campo"><label>Preço (R$)</label><input type="number" step="0.01" min="0" class="pk-preco" value="' + (p.preco != null ? p.preco : 0) + '"></div>' +
                '</div>' +
                '<div class="campo"><label>Nome do pack</label><input type="text" class="pk-nome" maxlength="60" value="' + escaparHtml(p.nome || '') + '" placeholder="Pack Especial"></div>' +
                '<div class="campo"><label>Descrição (exibida na prévia)</label><textarea class="pk-descricao" rows="2" placeholder="Descrição do pack...">' + escaparHtml(p.descricao || '') + '</textarea></div>' +
                '<div class="campo"><label>Mídia de preview (antes da compra)</label><div class="linha-flex">' + midia + '</div></div>' +
                '<div class="campo"><label>Entrega do pack</label><select class="pk-grupo">' + optsGrupos(p.id_grupo) + '</select></div>' +
                '</div>';
        }).join('') || '<div class="basico-bots-vazio"><strong>Nenhum pack ainda</strong><span>Adicione o primeiro pack</span></div>');
    }

    function sincronizarPacksDoDom() {
        $('#pk-lista .pk-item').each(function (i) {
            const $p = $(this);
            if (!packs_itens[i]) return;
            packs_itens[i].ativo = $p.find('.pk-ativo-item').is(':checked');
            packs_itens[i].emoji = $p.find('.pk-emoji').val().trim() || '📦';
            packs_itens[i].nome = $p.find('.pk-nome').val().trim();
            packs_itens[i].preco = parseFloat($p.find('.pk-preco').val()) || 0;
            packs_itens[i].descricao = $p.find('.pk-descricao').val().trim();
            packs_itens[i].id_grupo = $p.find('.pk-grupo').val();
        });
    }

    function coletarPacks() {
        sincronizarPacksDoDom();
        return {
            ativo: $('#pk-ativo').is(':checked'),
            texto_botao: $('#pk-botao').val().trim() || '📦 Packs Disponíveis',
            mensagem: $('#pk-mensagem').val().trim(),
            itens: packs_itens
        };
    }

    function preencherPacks(k) {
        k = k || {};
        $('#pk-ativo').prop('checked', !!k.ativo);
        $('#pk-detalhes, #pk-painel-lista').prop('hidden', !k.ativo);
        $('#pk-botao').val(k.texto_botao || '');
        $('#pk-mensagem').val(k.mensagem || '');
        packs_itens = Array.isArray(k.itens) ? k.itens : [];
        renderPacks();
    }

    // ===== Prévias que somem =====
    let previas_midias = [];
    const previas_max = 8;
    const previas_rotulo = { image: 'Foto', video: 'Vídeo', audio: 'Áudio' };

    function renderPreviasMidias() {
        $('#pv-contagem').text('(' + previas_midias.length + '/' + previas_max + ')');
        $('#pv-adicionar').prop('disabled', previas_midias.length >= previas_max);
        $('#pv-lista').html(previas_midias.length ? previas_midias.map(function (m, i) {
            return '<div class="basico-bot" data-i="' + i + '"><span>' + (i + 1) + '. ' + previas_rotulo[m.tipo] + ' <small>' + escaparHtml(m.path.replace('uploads/', '')) + '</small></span>' +
                '<span><button type="button" class="botao botao-claro pv-subir" ' + (i === 0 ? 'disabled' : '') + ' aria-label="Subir">↑</button> ' +
                '<button type="button" class="botao botao-claro pv-remover" aria-label="Remover">Remover</button></span></div>';
        }).join('') : '<div class="basico-bots-vazio"><strong>Nenhuma mídia ainda</strong><span>Adicione a primeira prévia</span></div>');
    }

    function coletarPrevias() {
        return {
            ativo: $('#pv-ativo').is(':checked'),
            texto_botao: $('#pv-botao').val().trim() || '🔥 Prévias Grátis',
            midias: previas_midias,
            apagar_apos: parseInt($('#pv-apagar').val(), 10) || 15,
            rever: $('#pv-rever').val() || 'nunca',
            proteger: $('#pv-proteger').is(':checked'),
            mensagem_junto: $('#pv-junto').val(),
            mensagem_depois: $('#pv-depois').val(),
            botao_planos: $('#pv-botao-planos').is(':checked')
        };
    }

    function preencherPrevias(p) {
        p = p || {};
        $('#pv-ativo').prop('checked', !!p.ativo);
        $('#pv-detalhes').prop('hidden', !p.ativo);
        $('#pv-botao').val(p.texto_botao || '');
        previas_midias = Array.isArray(p.midias) ? p.midias.filter(function (m) { return m && m.path; }) : [];
        $('#pv-apagar').val(String(p.apagar_apos || 15));
        $('#pv-rever').val(p.rever || 'nunca');
        $('#pv-proteger').prop('checked', p.proteger !== false);
        $('#pv-junto').val(p.mensagem_junto || '');
        $('#pv-depois').val(p.mensagem_depois || '');
        $('#pv-botao-planos').prop('checked', p.botao_planos !== false);
        renderPreviasMidias();
    }

    let periodo_conversoes = 'tudo';

    function carregarConversoes() {
        const id = $('#id-fluxo').val();
        if (!id) return;
        $.getJSON(api_url + '?action=conversoes_fluxo&id=' + encodeURIComponent(id) + '&periodo=' + encodeURIComponent(periodo_conversoes)).done(function (resp) {
            if (!resp.sucesso) return;
            const c = resp.conversoes;
            const sem_dados = !c.gerados && !c.leads;
            $('#conv-vazio').prop('hidden', !sem_dados || c.gerados > 0);
            $('#conv-conteudo').prop('hidden', sem_dados);
            if (sem_dados) return;
            $('#conv-leads').text(c.leads.toLocaleString('pt-BR'));
            $('#conv-gerados').text(c.gerados.toLocaleString('pt-BR'));
            $('#conv-pagos').text(c.pagos.toLocaleString('pt-BR'));
            $('#conv-receita').text(formatarMoeda(c.receita));
            $('#conv-ticket').text(formatarMoeda(c.ticket_medio));
            $('#conv-taxa-lead-pago').text(String(c.taxa_lead_pago).replace('.', ',') + '%');
            const etapas = [
                ['Leads', c.leads, 100],
                ['Pix gerados', c.gerados, c.taxa_lead_pix],
                ['Pagos', c.pagos, c.leads > 0 ? Math.round(c.pagos / c.leads * 1000) / 10 : 0]
            ];
            const maior = Math.max(c.leads, c.gerados, c.pagos, 1);
            $('#conv-funil').html(etapas.map(function (e) {
                return '<div class="conv-funil-linha"><span>' + e[0] + '</span><div class="conv-barra"><i style="width:' + Math.max(2, Math.round(e[1] / maior * 100)) + '%"></i></div><strong>' + e[1].toLocaleString('pt-BR') + '</strong></div>';
            }).join(''));
            $('#conv-funil-nota').text('Pix gerado → pago: ' + String(c.taxa_pix_pago).replace('.', ',') + '%  ·  ' + c.pendentes + ' aguardando pagamento  ·  ' + c.expirados + ' expirados');
            $('#conv-dias').html((c.dias || []).length ? (c.dias).map(function (d) {
                return '<div class="conv-dia"><span>' + escaparHtml(String(d.dia).split('-').reverse().join('/')) + '</span><span>' + d.vendas + ' venda(s)</span><strong>' + formatarMoeda(d.receita) + '</strong></div>';
            }).join('') : '<p class="texto-ajuda">Nenhuma venda paga neste período.</p>');
        });
    }

    // ===== Navegação mobile (cartão "Seção atual" + folha de baixo pra cima + barra anterior/próxima) =====
    function itensNavegacao() {
        return $('#basico-nav .basico-nav-item').map(function () {
            return { secao: String($(this).data('secao')), nome: $(this).find('.basico-nav-rotulo').text(), icone: $(this).find('svg').first().prop('outerHTML'), bloqueado: $(this).hasClass('bloqueado'), ativo: $(this).hasClass('ativo') };
        }).get();
    }

    function atualizarNavegacaoMobile() {
        const itens = itensNavegacao();
        const i = Math.max(0, itens.findIndex(function (x) { return x.ativo; }));
        const atual = itens[i] || { nome: '', icone: '' };
        $('#secao-atual-icone').html(atual.icone);
        $('#secao-atual-nome').text(atual.nome);
        $('#secao-atual-posicao').text((i + 1) + ' de ' + itens.length);
        $('#barra-posicao').text((i + 1) + '/' + itens.length);
        $('#barra-nome').text(atual.nome);
        $('#barra-anterior').prop('disabled', i === 0);
        $('#barra-anterior-nome').text(i > 0 ? itens[i - 1].nome : 'Início');
        $('#barra-proxima').prop('disabled', i === itens.length - 1);
        $('#barra-proxima-nome').text(i < itens.length - 1 ? itens[i + 1].nome : 'Fim');
        $('#folha-secoes-lista').html(itens.map(function (x, n) {
            return '<button type="button" class="folha-secoes-item' + (x.ativo ? ' ativo' : '') + (x.bloqueado ? ' bloqueado' : '') + '" data-secao="' + x.secao + '">' +
                '<span class="folha-secoes-icone">' + x.icone + '</span><span class="folha-secoes-nome">' + escaparHtml(x.nome) + '</span>' +
                (x.bloqueado ? '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>' : '') +
                '<span class="folha-secoes-numero">' + (n + 1) + '</span></button>';
        }).join(''));
    }

    function abrirFolhaSecoes(abrir) {
        const $folha = $('#folha-secoes');
        if (abrir) {
            atualizarNavegacaoMobile();
            $folha.prop('hidden', false);
            requestAnimationFrame(function () { $folha.addClass('aberta'); });
            $('body').addClass('folha-aberta');
        } else {
            $folha.removeClass('aberta');
            $('body').removeClass('folha-aberta');
            setTimeout(function () { if (!$folha.hasClass('aberta')) $folha.prop('hidden', true); }, 220);
        }
    }

    function irParaSecaoVizinha(passo) {
        const itens = itensNavegacao();
        const i = itens.findIndex(function (x) { return x.ativo; });
        const destino = itens[i + passo];
        if (destino) trocarSecao(destino.secao);
    }

    function trocarSecao(nome) {
        if ($('.basico-nav-item[data-secao="' + nome + '"]').hasClass('bloqueado')) {
            exibirAviso('Salve o fluxo em Bots para liberar esta seção.', 'erro');
            return;
        }
        sincronizarPlanosDoDom();
        atualizarSelectsOfertas();
        $('.basico-nav-item').removeClass('ativo').filter('[data-secao="' + nome + '"]').addClass('ativo');
        $('.basico-secao').removeClass('ativa').filter('[data-secao="' + nome + '"]').addClass('ativa');
        if (nome === 'conversoes') carregarConversoes();
        atualizarNavegacaoMobile();
        window.scrollTo(0, 0);
    }

    function $painelOferta(tipo) {
        return $('.painel[data-oferta="' + tipo + '"]');
    }

    function atualizarSelectsOfertas() {
        ['upsell', 'downsell'].forEach(function (tipo) {
            const $sel = $painelOferta(tipo).find('.of-plano');
            const atual = $sel.data('valor') != null ? String($sel.data('valor')) : String($sel.val() || '');
            let html = '<option value="">Escolha um plano</option>';
            planos.forEach(function (p) {
                html += '<option value="' + escaparHtml(p.id) + '"' + (String(p.id) === atual ? ' selected' : '') + '>' +
                    escaparHtml((p.nome || 'Plano sem nome') + ' — R$ ' + (Number(p.valor) || 0).toFixed(2).replace('.', ',')) + '</option>';
            });
            $sel.html(html).removeData('valor');
        });
    }

    // Os campos da oferta só aparecem com o interruptor "Ativar" ligado.
    function mostrarDetalhesOferta($painel) {
        $painel.find('.of-detalhes').prop('hidden', !$painel.find('.of-ativo').is(':checked'));
    }

    function coletarOferta(tipo) {
        const $p = $painelOferta(tipo);
        const oferta = {
            ativo: $p.find('.of-ativo').is(':checked'),
            mensagem: $p.find('.of-mensagem').val().trim(),
            texto_aceitar: $p.find('.of-aceitar').val().trim(),
            cor_aceitar: $p.find('.of-aceitar-cor').val() || '',
            texto_recusar: $p.find('.of-recusar').val().trim(),
            cor_recusar: $p.find('.of-recusar-cor').val() || ''
        };
        if (tipo === 'order_bump') {
            oferta.nome = $p.find('.of-nome').val().trim();
            oferta.valor_extra = parseFloat($p.find('.of-valor-extra').val()) || 0;
        } else {
            oferta.id_plano_destino = $p.find('.of-plano').val() || '';
            oferta.desconto_percentual = Math.min(100, Math.max(0, parseFloat($p.find('.of-desconto').val()) || 0));
        }
        return oferta;
    }

    function preencherOferta(tipo, oferta) {
        oferta = oferta || {};
        const $p = $painelOferta(tipo);
        $p.find('.of-ativo').prop('checked', !!oferta.ativo);
        mostrarDetalhesOferta($p);
        $p.find('.of-mensagem').val(oferta.mensagem || '');
        $p.find('.of-aceitar').val(oferta.texto_aceitar || '');
        $p.find('.of-aceitar-cor').val(oferta.cor_aceitar || '');
        $p.find('.of-recusar').val(oferta.texto_recusar || '');
        $p.find('.of-recusar-cor').val(oferta.cor_recusar || '');
        if (tipo === 'order_bump') {
            $p.find('.of-nome').val(oferta.nome || '');
            $p.find('.of-valor-extra').val(oferta.valor_extra != null ? oferta.valor_extra : 0);
        } else {
            $p.find('.of-plano').data('valor', oferta.id_plano_destino || '');
            $p.find('.of-desconto').val(oferta.desconto_percentual != null ? oferta.desconto_percentual : 0);
        }
    }

    function validarDados(dados) {
        const d = dados.dados_fluxograma;
        if (!d.planos.length) return 'Adicione pelo menos 1 plano antes de salvar.';
        for (let i = 0; i < d.planos.length; i++) {
            if (!d.planos[i].nome) return 'O plano ' + (i + 1) + ' está sem nome.';
            if (!(d.planos[i].valor > 0)) return 'O plano "' + d.planos[i].nome + '" precisa ter valor maior que zero.';
        }
        const usuario_suporte = d.suporte.replace(/^@/, '').replace(/^(https?:\/\/)?(t\.me|telegram\.me)\//i, '');
        if (d.suporte && !/^[A-Za-z0-9_]{4,32}$/.test(usuario_suporte)) {
            return 'Usuário de suporte inválido. Use algo como @seususuario (4 a 32 letras, números ou _).';
        }
        const erro_minimo = validarPrecoMinimo(d);
        if (erro_minimo) return erro_minimo;
        for (const tipo of ['upsell', 'downsell']) {
            const o = d.ofertas[tipo];
            if (o.ativo && !o.id_plano_destino) return 'Escolha o plano oferecido no ' + (tipo === 'upsell' ? 'Upsell' : 'Downsell') + ' ou desative-o.';
        }
        if (d.assinatura.avisos_antes.ativo && !d.assinatura.avisos_antes.dias.length) return 'Escolha pelo menos 1 dia nos avisos de renovação ou desative-os.';
        if (d.assinatura.avisos_no_dia.ativo && d.assinatura.avisos_no_dia.horarios.length !== (parseInt($('#as-dia-qtd').val(), 10) || 1)) return 'Escolha os horários dos avisos no dia da expiração (' + ($('#as-dia-qtd').val() || 1) + ').';
        if (d.packs.ativo) {
            for (let i = 0; i < d.packs.itens.length; i++) {
                if (d.packs.itens[i].ativo === false) continue;
                if (!d.packs.itens[i].nome) return 'O pack ' + (i + 1) + ' está sem nome.';
                if (!(d.packs.itens[i].preco > 0)) return 'O pack "' + d.packs.itens[i].nome + '" precisa ter preço maior que zero.';
            }
            if (!d.packs.itens.some(function (p) { return p.ativo !== false; })) return 'Ative ou adicione pelo menos 1 pack, ou desative os Packs.';
        }
        if (d.previas.ativo && !d.previas.midias.length) return 'Adicione pelo menos 1 mídia nas Prévias ou desative-as.';
        if (d.ofertas.order_bump.ativo && !(d.ofertas.order_bump.valor_extra > 0)) return 'Informe o valor extra do Order Bump ou desative-o.';
        return '';
    }

    function formatarMoeda(v) {
        return 'R$ ' + (Number(v) || 0).toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    }

    function carregarBots() {
        const id = $('#id-fluxo').val();
        if (!id) {
            $('#bots-aviso-salvar').show();
            $('#area-vincular').prop('hidden', true);
            $('#lista-bots-vinculados').empty();
            $('#bots-contagem').text('0/' + max_bots + ' bots');
            ids_bots_vinculados = [];
            renderCacheGrupos();
            return;
        }
        $('#bots-aviso-salvar').hide();
        $.getJSON(api_url + '?action=bots_do_fluxo&id=' + encodeURIComponent(id)).done(function (resp) {
            if (!resp.sucesso) return;
            let html = '';
            (resp.vinculados || []).forEach(function (b) {
                html += '<div class="basico-bot"><span>' + escaparHtml(b.primeiro_nome || b.nome_usuario || ('Bot #' + b.id)) +
                    (b.nome_usuario ? ' <small>@' + escaparHtml(b.nome_usuario) + '</small>' : '') + '</span>' +
                    '<button type="button" class="botao botao-claro desvincular-bot" data-id="' + b.id + '">Desvincular</button></div>';
            });
            const qtd_vinculados = (resp.vinculados || []).length;
            ids_bots_vinculados = (resp.vinculados || []).map(function (b) { return String(b.id); });
            renderCacheGrupos();
            $('#bots-contagem').text(qtd_vinculados + '/' + max_bots + ' bots');
            $('#lista-bots-vinculados').html(html || '<div class="basico-bots-vazio"><strong>Nenhum bot vinculado</strong><span>Adicione bots para executar este fluxo</span></div>');
            let opts = '<option value="">Selecione um bot para adicionar...</option>';
            (resp.disponiveis || []).forEach(function (b) {
                const nome = (b.primeiro_nome || b.nome_usuario || ('Bot #' + b.id)) + (b.nome_fluxo ? ' (hoje em: ' + b.nome_fluxo + ')' : '');
                opts += '<option value="' + b.id + '">' + escaparHtml(nome) + '</option>';
            });
            $('#select-bot-vincular').html(opts).prop('disabled', qtd_vinculados >= max_bots);
            $('#area-vincular').prop('hidden', false);
        });
        $.getJSON(api_url + '?action=resumo_fluxo&id=' + encodeURIComponent(id)).done(function (resp) {
            if (!resp.sucesso) return;
            $('#resumo-leads').text((resp.resumo.leads || 0).toLocaleString('pt-BR'));
            $('#resumo-vips').text((resp.resumo.vips || 0).toLocaleString('pt-BR'));
            $('#resumo-receita').text(formatarMoeda(resp.resumo.receita));
        });
    }

    function vincularBot(id_bot, id_fluxo) {
        $.ajax({
            url: api_url + '?action=vincular_bot_fluxo',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ id_bot: id_bot, id_fluxo: id_fluxo })
        }).done(function (resp) {
            exibirAviso(resp.mensagem || 'Feito.', resp.sucesso ? 'sucesso' : 'erro');
            carregarBots();
        }).fail(function (xhr) {
            exibirAviso((xhr.responseJSON && xhr.responseJSON.mensagem) || 'Erro ao vincular bot.', 'erro');
        });
    }

    function atualizarRotuloAtivo() {
        $('#fluxo-ativo-rotulo').text($('#fluxo-ativo').is(':checked') ? 'Ativo' : 'Desligado');
    }

    function coletarDados() {
        sincronizarPlanosDoDom();
        atualizarSelectsOfertas();
        return {
            id: $('#id-fluxo').val(),
            nome: $('#nome-fluxo').val().trim() || 'Novo fluxo',
            descricao: $('#descricao-fluxo').val().trim(),
            modo: 'basico',
            dados_fluxograma: {
                ativo: $('#fluxo-ativo').is(':checked'),
                suporte: $('#suporte-usuario').val().trim(),
                suporte_cor: $('#suporte-cor').val() || '',
                cache_chat_id: $('#cache-chat-id').val() || '',
                ofertas: {
                    upsell: coletarOferta('upsell'),
                    downsell: coletarOferta('downsell'),
                    order_bump: coletarOferta('order_bump')
                },
                boas_vindas: {
                    mensagem: $('#bv-mensagem').val(),
                    midia_tipo: midia_tipo_atual,
                    midia_path: midia_path_atual,
                    texto_cta: $('#bv-cta').val().trim() || 'Ver Planos',
                    cor_cta: $('#bv-cta-cor').val() || ''
                },
                previas: coletarPrevias(),
                packs: coletarPacks(),
                assinatura: coletarAssinatura(),
                planos: planos,
                pagamentos: {
                    msg_instrucoes: $('#pg-msg-instrucoes').val(),
                    msg_confirmado: $('#pg-msg-confirmado').val(),
                    mostrar_copiar: $('#pg-mostrar-copiar').is(':checked'),
                    mostrar_qrcode: false,
                    mostrar_confirmar: $('#pg-mostrar-confirmar').is(':checked')
                }
            }
        };
    }

    function preencherForm(fluxo) {
        carregando = true;
        $('#id-fluxo').val(fluxo.id || '');
        $('#nome-fluxo').val(fluxo.nome || '');
        $('#descricao-fluxo').val(fluxo.descricao || '');
        const dados = fluxo.dados_fluxograma || {};
        const bv = dados.boas_vindas || {};
        $('#bv-mensagem').val(bv.mensagem || '');
        $('#bv-cta').val(bv.texto_cta || 'Ver Planos');
        $('#bv-cta-cor').val(bv.cor_cta || '');
        midia_tipo_atual = bv.midia_tipo || 'none';
        midia_path_atual = bv.midia_path || '';
        atualizarPreviaMidia();

        planos = Array.isArray(dados.planos) ? dados.planos : [];
        renderPlanos();

        const pg = dados.pagamentos || {};
        $('#pg-msg-instrucoes').val(pg.msg_instrucoes || 'Copie o código Pix abaixo e pague no app do seu banco.');
        $('#pg-msg-confirmado').val(pg.msg_confirmado || 'Pagamento confirmado! Seu acesso foi liberado.');
        $('#pg-mostrar-copiar').prop('checked', pg.mostrar_copiar !== false);
        $('#pg-mostrar-confirmar').prop('checked', pg.mostrar_confirmar !== false);

        $('#fluxo-ativo').prop('checked', dados.ativo !== false);
        atualizarRotuloAtivo();
        $('#suporte-usuario').val(dados.suporte || '');
        $('#suporte-cor').val(dados.suporte_cor || '');
        cache_chat_id_salvo = String(dados.cache_chat_id || '');
        preencherPrevias(dados.previas);
        preencherPacks(dados.packs);
        preencherAssinatura(dados.assinatura);
        const ofertas = dados.ofertas || {};
        oferta_tipos.forEach(function (tipo) { preencherOferta(tipo, ofertas[tipo]); });
        atualizarSelectsOfertas();

        if (fluxo.id) {
            $('#btn-excluir-fluxo-basico').show();
        }
        atualizarTituloFluxo();
        atualizarBloqueioSecoes();
        carregarBots();
        carregando = false;
        marcarLimpo();
    }

    function abrirFluxo(id) {
        $.getJSON(api_url + '?action=obter_fluxo&id=' + encodeURIComponent(id))
            .done(function (resp) {
                if (!resp.sucesso) {
                    exibirAviso(resp.mensagem || 'Fluxo não encontrado.', 'erro');
                    return;
                }
                if ((resp.fluxo.modo || 'avancado') !== 'basico') {
                    exibirAviso('Este fluxo é do Editor Visual, não do modo Guiado.', 'erro');
                    setTimeout(function () { window.location.href = 'fluxo?id=' + encodeURIComponent(id); }, 1200);
                    return;
                }
                preencherForm(resp.fluxo);
            })
            .fail(function () {
                exibirAviso('Não foi possível abrir o fluxo.', 'erro');
            });
    }

    function salvarFluxo() {
        const dados = coletarDados();
        const erro_validacao = validarDados(dados);
        if (erro_validacao) {
            exibirAviso(erro_validacao, 'erro');
            return;
        }
        $.ajax({
            url: api_url + '?action=salvar_fluxo',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(dados)
        }).done(function (resp) {
            if (!resp.sucesso) {
                exibirAviso(resp.mensagem || 'Erro ao salvar fluxo.', 'erro');
                return;
            }
            $('#id-fluxo').val(resp.fluxo.id || '');
            $('#btn-excluir-fluxo-basico').show();
            marcarLimpo();
            atualizarTituloFluxo();
            atualizarBloqueioSecoes();
            carregarBots();
            exibirAviso(resp.mensagem || 'Fluxo salvo com sucesso.');
            if (window.history.pushState && resp.fluxo && resp.fluxo.id) {
                const new_url = window.location.pathname + '?id=' + resp.fluxo.id;
                window.history.pushState({ path: new_url }, '', new_url);
            }
        }).fail(function (xhr) {
            exibirAviso((xhr.responseJSON && xhr.responseJSON.mensagem) || 'Erro ao salvar fluxo.', 'erro');
        });
    }

    function excluirFluxo() {
        const id = $('#id-fluxo').val();
        if (!id) return;
        if (!window.confirm('Deseja excluir este fluxo?')) return;
        $.ajax({
            url: api_url + '?action=excluir_fluxo',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ id: id })
        }).done(function (resp) {
            if (!resp.sucesso) {
                exibirAviso(resp.mensagem || 'Erro ao excluir fluxo.', 'erro');
                return;
            }
            marcarLimpo();
            window.location.href = 'fluxos';
        }).fail(function (xhr) {
            exibirAviso((xhr.responseJSON && xhr.responseJSON.mensagem) || 'Erro ao excluir fluxo.', 'erro');
        });
    }

    $(function () {
        carregarLimitesPagamento();
        carregarGruposUsuario().always(function () {
            const url_params = new URLSearchParams(window.location.search);
            const flow_id = url_params.get('id');
            if (flow_id) {
                abrirFluxo(flow_id);
            } else {
                renderPlanos();
                atualizarSelectsOfertas();
                preencherPrevias({});
                preencherPacks({});
                preencherAssinatura({});
                atualizarBloqueioSecoes();
                carregarBots();
            }
        });

        $('#basico-nav').on('click', '.basico-nav-item', function () {
            trocarSecao($(this).data('secao'));
        });
        $('#as-antes-ativo').on('change', function () { $('#as-antes-detalhes').prop('hidden', !this.checked); });
        $('#as-dia-ativo').on('change', function () { $('#as-dia-detalhes').prop('hidden', !this.checked); });
        $('#as-exp-ativo').on('change', function () { $('#as-exp-detalhes').prop('hidden', !this.checked); });
        $('#as-dias').on('click', '.as-chip', function () {
            const d = parseInt($(this).data('dia'), 10);
            const i = as_dias.indexOf(d);
            if (i === -1) as_dias.push(d); else as_dias.splice(i, 1);
            renderAssinatura();
            marcarSujo();
        });
        $('#as-dia-qtd').on('change', function () { renderAssinatura(); });
        $('#as-horas').on('click', '.as-hora', function () {
            const h = String($(this).data('hora'));
            const i = as_horarios.indexOf(h);
            const qtd = parseInt($('#as-dia-qtd').val(), 10) || 1;
            if (i !== -1) as_horarios.splice(i, 1);
            else if (as_horarios.length < qtd) as_horarios.push(h);
            else { exibirAviso('Você já escolheu ' + qtd + ' horário(s). Desmarque um ou aumente a quantidade.', 'erro'); return; }
            renderAssinatura();
            marcarSujo();
        });
        $('#basico-conteudo').on('click', '.as-var', function () {
            const campo = $(this).closest('.campo').find('textarea')[0];
            if (!campo) return;
            const ini = campo.selectionStart || 0, fim = campo.selectionEnd || 0, v = $(this).data('var');
            campo.value = campo.value.slice(0, ini) + v + campo.value.slice(fim);
            campo.focus();
            campo.selectionStart = campo.selectionEnd = ini + v.length;
            marcarSujo();
        });
        $('[data-midia-bloco]').on('click', '.as-chip', function () {
            const bloco = String($(this).closest('[data-midia-bloco]').data('midia-bloco'));
            const tipo = String($(this).data('tipo'));
            if (tipo === 'none') { as_midia[bloco] = { tipo: 'none', path: '' }; renderAssinatura(); marcarSujo(); return; }
            if (as_midia[bloco].tipo === tipo && as_midia[bloco].path) return;
            as_bloco_upload = bloco;
            $('#as-arquivo').data('tipo', tipo).attr('accept', { image: 'image/jpeg,image/png', video: 'video/*', audio: 'audio/*' }[tipo]).trigger('click');
        });
        $('#as-arquivo').on('change', function () {
            const arquivo = this.files && this.files[0];
            const tipo = String($(this).data('tipo'));
            const bloco = as_bloco_upload;
            this.value = '';
            if (!arquivo || !bloco) return;
            const fd = new FormData();
            fd.append(tipo, arquivo);
            exibirAviso('Enviando mídia...');
            $.ajax({ url: api_url + '?action=upload_' + (tipo === 'image' ? 'imagem' : tipo) + '_fluxo', method: 'POST', data: fd, processData: false, contentType: false })
                .done(function (resp) {
                    if (!resp.sucesso) { exibirAviso(resp.mensagem || 'Erro ao enviar mídia.', 'erro'); return; }
                    as_midia[bloco] = { tipo: tipo, path: resp.caminho || '' };
                    renderAssinatura();
                    marcarSujo();
                    exibirAviso('Mídia adicionada.');
                })
                .fail(function (xhr) { exibirAviso((xhr.responseJSON && xhr.responseJSON.mensagem) || 'Erro ao enviar mídia.', 'erro'); });
        });
        $('#pk-ativo').on('change', function () { $('#pk-detalhes, #pk-painel-lista').prop('hidden', !this.checked); });
        $('#pk-adicionar').on('click', function () {
            sincronizarPacksDoDom();
            if (packs_itens.length >= packs_max) return;
            packs_itens.push({ id: 'pack_' + Date.now(), ativo: true, emoji: '📦', nome: '', preco: 0, descricao: '', midia_tipo: 'none', midia_path: '', id_grupo: '' });
            renderPacks();
            marcarSujo();
        });
        $('#pk-lista').on('click', '.pk-remover', function () {
            sincronizarPacksDoDom();
            packs_itens.splice(parseInt($(this).closest('.pk-item').data('i'), 10), 1);
            renderPacks();
            marcarSujo();
        }).on('click', '.pk-tirar-midia', function () {
            sincronizarPacksDoDom();
            const p = packs_itens[parseInt($(this).closest('.pk-item').data('i'), 10)];
            p.midia_path = ''; p.midia_tipo = 'none';
            renderPacks();
            marcarSujo();
        }).on('click', '.pk-midia', function () {
            sincronizarPacksDoDom();
            pack_upload_indice = parseInt($(this).closest('.pk-item').data('i'), 10);
            $('#pk-arquivo').trigger('click');
        });
        $('#pk-arquivo').on('change', function () {
            const arquivo = this.files && this.files[0];
            this.value = '';
            if (!arquivo || !packs_itens[pack_upload_indice]) return;
            const indice = pack_upload_indice;
            const tipo = arquivo.type.indexOf('video') === 0 ? 'video' : (arquivo.type.indexOf('audio') === 0 ? 'audio' : 'image');
            const fd = new FormData();
            fd.append(tipo, arquivo);
            exibirAviso('Enviando mídia...');
            $.ajax({ url: api_url + '?action=upload_' + (tipo === 'image' ? 'imagem' : tipo) + '_fluxo', method: 'POST', data: fd, processData: false, contentType: false })
                .done(function (resp) {
                    if (!resp.sucesso) { exibirAviso(resp.mensagem || 'Erro ao enviar mídia.', 'erro'); return; }
                    sincronizarPacksDoDom();
                    packs_itens[indice].midia_path = resp.caminho || '';
                    packs_itens[indice].midia_tipo = tipo;
                    renderPacks();
                    marcarSujo();
                    exibirAviso('Mídia adicionada.');
                })
                .fail(function (xhr) { exibirAviso((xhr.responseJSON && xhr.responseJSON.mensagem) || 'Erro ao enviar mídia.', 'erro'); });
        });
        $('#pv-ativo').on('change', function () { $('#pv-detalhes').prop('hidden', !this.checked); });
        $('#pv-adicionar').on('click', function () { $('#pv-arquivo').trigger('click'); });
        $('#pv-lista').on('click', '.pv-remover', function () {
            previas_midias.splice(parseInt($(this).closest('[data-i]').data('i'), 10), 1);
            renderPreviasMidias();
            marcarSujo();
        }).on('click', '.pv-subir', function () {
            const i = parseInt($(this).closest('[data-i]').data('i'), 10);
            if (i > 0) { previas_midias.splice(i - 1, 0, previas_midias.splice(i, 1)[0]); renderPreviasMidias(); marcarSujo(); }
        });
        $('#pv-arquivo').on('change', function () {
            const arquivo = this.files && this.files[0];
            this.value = '';
            if (!arquivo || previas_midias.length >= previas_max) return;
            const tipo = arquivo.type.indexOf('video') === 0 ? 'video' : (arquivo.type.indexOf('audio') === 0 ? 'audio' : 'image');
            const fd = new FormData();
            fd.append(tipo, arquivo);
            exibirAviso('Enviando mídia...');
            $.ajax({ url: api_url + '?action=upload_' + (tipo === 'image' ? 'imagem' : tipo) + '_fluxo', method: 'POST', data: fd, processData: false, contentType: false })
                .done(function (resp) {
                    if (!resp.sucesso) { exibirAviso(resp.mensagem || 'Erro ao enviar mídia.', 'erro'); return; }
                    previas_midias.push({ tipo: tipo, path: resp.caminho || '' });
                    renderPreviasMidias();
                    marcarSujo();
                    exibirAviso('Mídia adicionada.');
                })
                .fail(function (xhr) { exibirAviso((xhr.responseJSON && xhr.responseJSON.mensagem) || 'Erro ao enviar mídia.', 'erro'); });
        });
        $('#conv-filtros').on('click', '.conv-filtro', function () {
            periodo_conversoes = String($(this).data('periodo'));
            $('.conv-filtro').removeClass('ativo');
            $(this).addClass('ativo');
            carregarConversoes();
        });
        $('#secao-atual').on('click', function () { abrirFolhaSecoes(true); });
        $('#folha-secoes-fundo, #folha-secoes-fechar').on('click', function () { abrirFolhaSecoes(false); });
        $(document).on('keydown', function (e) { if (e.key === 'Escape' && !$('#folha-secoes').prop('hidden')) abrirFolhaSecoes(false); });
        $('#folha-secoes-lista').on('click', '.folha-secoes-item', function () {
            const secao = String($(this).data('secao'));
            abrirFolhaSecoes(false);
            trocarSecao(secao);
        });
        $('#barra-anterior').on('click', function () { irParaSecaoVizinha(-1); });
        $('#barra-proxima').on('click', function () { irParaSecaoVizinha(1); });
        atualizarNavegacaoMobile();
        $('#btn-recolher-nav').on('click', function () {
            $('#basico-nav').toggleClass('recolhida');
        });
        $('#btn-editar-nome').on('click', function () {
            const $campo = $('#nome-fluxo');
            const editando = !$campo.prop('hidden');
            $campo.prop('hidden', editando);
            $('#titulo-fluxo').prop('hidden', !editando);
            if (editando) atualizarTituloFluxo(); else $campo.trigger('focus');
        });
        $('#nome-fluxo').on('keydown', function (e) {
            if (e.key === 'Enter') $('#btn-editar-nome').trigger('click');
        });
        $('#editor-fluxo').on('input change', 'input:not(:disabled), select:not(:disabled), textarea', function () {
            if (this.id !== 'select-bot-vincular') marcarSujo();
        });
        $('#select-bot-vincular').on('change', function () {
            const id_bot = parseInt($(this).val(), 10);
            if (id_bot) vincularBot(id_bot, parseInt($('#id-fluxo').val(), 10));
        });
        $('#basico-conteudo').on('change', '.of-ativo', function () {
            mostrarDetalhesOferta($(this).closest('.painel'));
        });
        $('#btn-atualizar-bots, #btn-atualizar-cache').on('click', function () {
            carregarGruposUsuario().always(carregarBots);
        });
        $('#fluxo-ativo').on('change', function () { atualizarRotuloAtivo(); marcarSujo(); });
        $(document).on('click', '.desvincular-bot', function () {
            vincularBot(parseInt($(this).data('id'), 10), 0);
        });
        $(window).on('beforeunload', function (e) {
            if ($('#status-salvo').hasClass('sujo')) { e.preventDefault(); return ''; }
        });

        $('#btn-salvar-fluxo-basico').on('click', salvarFluxo);
        $('#btn-excluir-fluxo-basico').on('click', excluirFluxo);

        $('#btn-adicionar-plano').on('click', function () {
            sincronizarPlanosDoDom();
            planos.push({ id: 'plano_' + Date.now(), nome: '', valor: 0, dias_acesso: 30, unidade_acesso: 'dias', id_grupo: '' });
            renderPlanos();
            marcarSujo();
        });
        $(document).on('click', '.remover-plano', function () {
            sincronizarPlanosDoDom();
            const idx = parseInt($(this).closest('.painel-plano').data('index'), 10);
            planos.splice(idx, 1);
            renderPlanos();
            marcarSujo();
        });

        $('#bv-midia-previa').on('click', function () {
            $('#bv-midia-arquivo').trigger('click');
        });
        $('#bv-midia-arquivo').on('change', function () {
            const input = this;
            if (!input.files || !input.files[0]) return;
            const arquivo = input.files[0];
            const eh_video = arquivo.type.indexOf('video') === 0;
            const fd = new FormData();
            fd.append(eh_video ? 'video' : 'image', arquivo);
            $.ajax({
                url: api_url + '?action=' + (eh_video ? 'upload_video_fluxo' : 'upload_imagem_fluxo'),
                method: 'POST',
                data: fd,
                processData: false,
                contentType: false
            }).done(function (resp) {
                if (!resp.sucesso) {
                    exibirAviso(resp.mensagem || 'Erro ao enviar mídia.', 'erro');
                    return;
                }
                midia_path_atual = resp.caminho || '';
                midia_tipo_atual = eh_video ? 'video' : 'image';
                atualizarPreviaMidia();
                marcarSujo();
                exibirAviso('Mídia anexada.');
            }).fail(function (xhr) {
                exibirAviso((xhr.responseJSON && xhr.responseJSON.mensagem) || 'Erro ao enviar mídia.', 'erro');
            });
        });
    });
})(jQuery);
