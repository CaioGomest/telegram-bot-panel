(function ($) {
    const api_url = 'api.php';
    let lista_grupos_usuario = [];
    let planos = [];
    let midia_tipo_atual = 'none';
    let midia_path_atual = '';
    let carregando = false;
    const oferta_tipos = ['upsell', 'downsell', 'order_bump'];

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
                '        <option value=""' + (!p.cor ? ' selected' : '') + '>Padrão do Telegram</option>' +
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

    function marcarSujo() {
        if (carregando) return;
        $('#status-salvo').addClass('sujo').text('Alterações não salvas');
    }

    function marcarLimpo() {
        $('#status-salvo').removeClass('sujo').text('Tudo salvo');
    }

    function trocarSecao(nome) {
        sincronizarPlanosDoDom();
        atualizarSelectsOfertas();
        $('.basico-nav-item').removeClass('ativo').filter('[data-secao="' + nome + '"]').addClass('ativo');
        $('.basico-secao').removeClass('ativa').filter('[data-secao="' + nome + '"]').addClass('ativa');
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
        for (const tipo of ['upsell', 'downsell']) {
            const o = d.ofertas[tipo];
            if (o.ativo && !o.id_plano_destino) return 'Escolha o plano oferecido no ' + (tipo === 'upsell' ? 'Upsell' : 'Downsell') + ' ou desative-o.';
        }
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
            $('#lista-bots-vinculados').html(html || '<p class="texto-ajuda">Nenhum bot vinculado a este fluxo ainda.</p>');
            let opts = '';
            (resp.disponiveis || []).forEach(function (b) {
                const nome = (b.primeiro_nome || b.nome_usuario || ('Bot #' + b.id)) + (b.nome_fluxo ? ' (hoje em: ' + b.nome_fluxo + ')' : '');
                opts += '<option value="' + b.id + '">' + escaparHtml(nome) + '</option>';
            });
            $('#select-bot-vincular').html(opts);
            $('#area-vincular').prop('hidden', !opts);
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
        const ofertas = dados.ofertas || {};
        oferta_tipos.forEach(function (tipo) { preencherOferta(tipo, ofertas[tipo]); });
        atualizarSelectsOfertas();

        if (fluxo.id) {
            $('#btn-excluir-fluxo-basico').show();
        }
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
        carregarGruposUsuario().always(function () {
            const url_params = new URLSearchParams(window.location.search);
            const flow_id = url_params.get('id');
            if (flow_id) {
                abrirFluxo(flow_id);
            } else {
                renderPlanos();
                atualizarSelectsOfertas();
                carregarBots();
            }
        });

        $('#basico-nav').on('click', '.basico-nav-item', function () {
            trocarSecao($(this).data('secao'));
        });
        $('#basico-conteudo').on('input change', 'input, select, textarea', marcarSujo);
        $('#fluxo-ativo').on('change', function () { atualizarRotuloAtivo(); marcarSujo(); });
        $('#btn-vincular-bot').on('click', function () {
            const id_bot = parseInt($('#select-bot-vincular').val(), 10);
            if (id_bot) vincularBot(id_bot, parseInt($('#id-fluxo').val(), 10));
        });
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
