(function ($) {
    const url_api = 'api.php';
    let modo_edicao = false;

    const icons = {
        edit: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>',
        copy: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>',
        trash: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>'
    };

    function exibirAviso(message, type = 'sucesso') {
        const $toast = $('#toast');
        $toast.removeClass('sucesso erro visivel').addClass(type).text(message);
        requestAnimationFrame(() => $toast.addClass('visivel'));
        clearTimeout(window.__toastTimeout);
        window.__toastTimeout = setTimeout(() => $toast.removeClass('visivel'), 3000);
    }

    function escaparHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function formatarMoeda(valor) {
        return 'R$ ' + parseFloat(valor || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function carregarLinks() {
        const $corpo = $('#corpo-tabela');
        $corpo.html('<tr><td colspan="9" style="text-align:center;padding:32px;color:var(--muted);">Carregando...</td></tr>');

        $.getJSON(url_api + '?action=listar_links_rastreamento')
            .done(function (res) {
                if (!res.sucesso) {
                    $corpo.html('<tr><td colspan="9" style="text-align:center;padding:32px;color:var(--red);">' + escaparHtml(res.mensagem || 'Erro ao carregar.') + '</td></tr>');
                    return;
                }

                const links = res.links || [];
                $('#contador-links').text(links.length);
                $corpo.empty();

                if (!links.length) {
                    $corpo.html('<tr><td colspan="9" style="text-align:center;padding:40px;color:var(--muted);">Nenhum link cadastrado. Crie o primeiro!</td></tr>');
                    return;
                }

                links.forEach(function (link) {
                    const url_gerada = escaparHtml(link.link_gerado || '');
                    $corpo.append(`
                        <tr>
                            <td>${escaparHtml(link.titulo)}</td>
                            <td>${escaparHtml(link.bot_nome || link.bot_username || '—')}</td>
                            <td>${parseInt(link.vendas_qtd || 0)} Vendas</td>
                            <td>${formatarMoeda(link.vendas_valor)}</td>
                            <td>${parseInt(link.starts || 0)} Starts</td>
                            <td>${parseInt(link.leads || 0)} Leads</td>
                            <td><code style="font-size:12px;color:var(--muted);">${escaparHtml(link.identificador)}</code></td>
                            <td class="col-link" title="${url_gerada}">${url_gerada}</td>
                            <td>
                                <div class="col-acoes">
                                    <button class="btn-icon editar btn-editar-link" data-id="${escaparHtml(link.id)}" title="Editar">${icons.edit}</button>
                                    <button class="btn-icon btn-copiar-link" data-url="${url_gerada}" title="Copiar link">${icons.copy}</button>
                                    <button class="btn-icon excluir btn-excluir-link" data-id="${escaparHtml(link.id)}" title="Excluir">${icons.trash}</button>
                                </div>
                            </td>
                        </tr>
                    `);
                });
            })
            .fail(function () {
                $corpo.html('<tr><td colspan="9" style="text-align:center;padding:32px;color:var(--red);">Não foi possível carregar os links.</td></tr>');
            });
    }

    function carregarBots(bot_id_selecionado) {
        const $select = $('#link-bot');
        $select.html('<option value="">Carregando bots...</option>');

        $.getJSON(url_api + '?action=listar_bots')
            .done(function (res) {
                $select.html('<option value="">Escolha o bot</option>');
                const bots = res.bots || [];
                bots.forEach(function (bot) {
                    const selecionado = bot_id_selecionado && parseInt(bot.id) === parseInt(bot_id_selecionado) ? 'selected' : '';
                    const nomeBot = bot.primeiro_nome || bot.nome_usuario || 'Bot';
                    const usuarioBot = bot.nome_usuario ? ' (@' + bot.nome_usuario + ')' : '';
                    $select.append(`<option value="${escaparHtml(bot.id)}" ${selecionado}>${escaparHtml(nomeBot + usuarioBot)}</option>`);
                });
            })
            .fail(function () {
                $select.html('<option value="">Erro ao carregar bots</option>');
            });
    }

    function abrirModalNovo() {
        modo_edicao = false;
        $('#modal-titulo-texto').text('Crie um link de rastreamento');
        $('#btn-salvar-link').text('Gerar link');
        $('#link-id').val('');
        $('#link-titulo').val('');
        $('#link-identificador').val('');
        carregarBots(null);
        $('#modal-link').addClass('aberto');
        $('#link-titulo').focus();
    }

    function abrirModalEdicao(id) {
        $.getJSON(url_api + '?action=obter_link_rastreamento&id=' + id)
            .done(function (res) {
                if (!res.sucesso || !res.link) {
                    exibirAviso(res.mensagem || 'Erro ao carregar link.', 'erro');
                    return;
                }
                modo_edicao = true;
                const link = res.link;
                $('#modal-titulo-texto').text('Editar link de rastreamento');
                $('#btn-salvar-link').text('Salvar alterações');
                $('#link-id').val(link.id);
                $('#link-titulo').val(link.titulo);
                $('#link-identificador').val(link.identificador);
                carregarBots(link.bot_id);
                $('#modal-link').addClass('aberto');
                $('#link-titulo').focus();
            })
            .fail(function () {
                exibirAviso('Não foi possível carregar o link.', 'erro');
            });
    }

    function fecharModal() {
        $('#modal-link').removeClass('aberto');
    }

    function salvarLink() {
        const titulo = $('#link-titulo').val().trim();
        const identificador = $('#link-identificador').val().trim();
        const bot_id = $('#link-bot').val();
        const id = $('#link-id').val();

        if (!titulo) { exibirAviso('Informe o título.', 'erro'); return; }
        if (!identificador) { exibirAviso('Informe o identificador.', 'erro'); return; }
        if (!/^[a-zA-Z0-9_\-]+$/.test(identificador)) { exibirAviso('Identificador inválido. Use apenas letras, números, _ e -.', 'erro'); return; }
        if (!bot_id) { exibirAviso('Selecione um bot.', 'erro'); return; }

        const action = modo_edicao ? 'editar_link_rastreamento' : 'criar_link_rastreamento';
        const payload = { titulo, identificador, bot_id: parseInt(bot_id) };
        if (modo_edicao) payload.id = parseInt(id);

        const $btn = $('#btn-salvar-link').prop('disabled', true).text('Salvando...');

        $.ajax({
            url: url_api + '?action=' + action,
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(payload)
        }).done(function (res) {
            if (!res.sucesso) {
                exibirAviso(res.mensagem || 'Erro ao salvar.', 'erro');
                return;
            }
            exibirAviso(res.mensagem || 'Salvo com sucesso.');
            fecharModal();
            carregarLinks();
        }).fail(function (xhr) {
            exibirAviso((xhr.responseJSON && xhr.responseJSON.mensagem) || 'Erro ao salvar.', 'erro');
        }).always(function () {
            $btn.prop('disabled', false).text(modo_edicao ? 'Salvar alterações' : 'Gerar link');
        });
    }

    function excluirLink(id) {
        if (!confirm('Tem certeza que deseja excluir este link? Esta ação não pode ser desfeita.')) return;

        $.ajax({
            url: url_api + '?action=excluir_link_rastreamento',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ id: parseInt(id) })
        }).done(function (res) {
            if (!res.sucesso) { exibirAviso(res.mensagem || 'Erro ao excluir.', 'erro'); return; }
            exibirAviso('Link excluído com sucesso.');
            carregarLinks();
        }).fail(function (xhr) {
            exibirAviso((xhr.responseJSON && xhr.responseJSON.mensagem) || 'Erro ao excluir.', 'erro');
        });
    }

    function copiarLink(url) {
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(url).then(function () {
                exibirAviso('Link copiado!');
            }).catch(function () {
                copiarLinkFallback(url);
            });
        } else {
            copiarLinkFallback(url);
        }
    }

    function copiarLinkFallback(url) {
        const $tmp = $('<textarea>').val(url).appendTo('body').select();
        document.execCommand('copy');
        $tmp.remove();
        exibirAviso('Link copiado!');
    }

    $(function () {
        carregarLinks();

        $('#btn-novo-link').on('click', abrirModalNovo);
        $('#btn-fechar-modal, #btn-cancelar-modal').on('click', fecharModal);

        $('#modal-link').on('click', function (e) {
            if ($(e.target).is('#modal-link')) fecharModal();
        });

        $('#form-link').on('submit', function (e) {
            e.preventDefault();
            salvarLink();
        });

        $(document).on('click', '.btn-editar-link', function () {
            abrirModalEdicao($(this).data('id'));
        });

        $(document).on('click', '.btn-excluir-link', function () {
            excluirLink($(this).data('id'));
        });

        $(document).on('click', '.btn-copiar-link', function () {
            copiarLink($(this).data('url'));
        });
    });

})(jQuery);
