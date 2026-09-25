(function ($) {
    const api_url = 'api.php';
    let lista_grupos_usuario = [];
    let planos = [];
    let midia_tipo_atual = 'none';
    let midia_path_atual = '';

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
                id_grupo: $p.find('.campo-plano-grupo').val()
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

    function coletarDados() {
        sincronizarPlanosDoDom();
        return {
            id: $('#id-fluxo').val(),
            nome: $('#nome-fluxo').val().trim() || 'Novo fluxo',
            descricao: $('#descricao-fluxo').val().trim(),
            modo: 'basico',
            dados_fluxograma: {
                boas_vindas: {
                    mensagem: $('#bv-mensagem').val(),
                    midia_tipo: midia_tipo_atual,
                    midia_path: midia_path_atual,
                    texto_cta: $('#bv-cta').val().trim() || 'Ver Planos'
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
        $('#id-fluxo').val(fluxo.id || '');
        $('#nome-fluxo').val(fluxo.nome || '');
        $('#descricao-fluxo').val(fluxo.descricao || '');
        const dados = fluxo.dados_fluxograma || {};
        const bv = dados.boas_vindas || {};
        $('#bv-mensagem').val(bv.mensagem || '');
        $('#bv-cta').val(bv.texto_cta || 'Ver Planos');
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

        if (fluxo.id) {
            $('#btn-excluir-fluxo-basico').show();
        }
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
        if (!(dados.dados_fluxograma.planos || []).length) {
            exibirAviso('Adicione pelo menos 1 plano antes de salvar.', 'erro');
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
            }
        });

        $('#btn-salvar-fluxo-basico').on('click', salvarFluxo);
        $('#btn-excluir-fluxo-basico').on('click', excluirFluxo);

        $('#btn-adicionar-plano').on('click', function () {
            sincronizarPlanosDoDom();
            planos.push({ id: 'plano_' + Date.now(), nome: '', valor: 0, dias_acesso: 30, unidade_acesso: 'dias', id_grupo: '' });
            renderPlanos();
        });
        $(document).on('click', '.remover-plano', function () {
            sincronizarPlanosDoDom();
            const idx = parseInt($(this).closest('.painel-plano').data('index'), 10);
            planos.splice(idx, 1);
            renderPlanos();
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
                exibirAviso('Mídia anexada.');
            }).fail(function (xhr) {
                exibirAviso((xhr.responseJSON && xhr.responseJSON.mensagem) || 'Erro ao enviar mídia.', 'erro');
            });
        });
    });
})(jQuery);
