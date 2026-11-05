(function ($) {
    const url_api = 'api.php';

    function exibirAviso(message, type = 'sucesso') {
        const $toast = $('#toast');
        $toast.removeClass('sucesso erro visivel').addClass(type).text(message);
        requestAnimationFrame(() => $toast.addClass('visivel'));
        clearTimeout(window.__toastTimeout);
        window.__toastTimeout = setTimeout(() => $toast.removeClass('visivel'), 3000);
    }

    function formatarDataFluxo(valor) {
        if (!valor) return '-';
        const data = new Date(String(valor).replace(' ', 'T'));
        if (Number.isNaN(data.getTime())) return String(valor);
        const p = function (n) { return String(n).padStart(2, '0'); };
        return p(data.getDate()) + '/' + p(data.getMonth() + 1) + '/' + data.getFullYear()
            + ' ' + p(data.getHours()) + ':' + p(data.getMinutes());
    }

    function escaparHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    const icons = {
        edit: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>',
        exportar: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>',
        trash: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>'
    };

    // Mesmos ícones do modal "Como deseja criar?" (fluxos.php): raio = Guiado, ramificação = Editor Visual. O tamanho vem do CSS.
    const icone_modo = {
        basico: "<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M13 2L3 14h9l-1 8 10-12h-9l1-8z\"></path></svg>",
        visual: "<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><line x1=\"6\" y1=\"3\" x2=\"6\" y2=\"15\"></line><circle cx=\"18\" cy=\"6\" r=\"3\"></circle><circle cx=\"6\" cy=\"18\" r=\"3\"></circle><path d=\"M18 9a9 9 0 0 1-9 9\"></path></svg>"
    };

    let limite_fluxos = 50;

    // Contador no botão de criar ("+ Novo Fluxo (2/50)"); no limite, criar e importar ficam desativados.
    function atualizarContadorFluxos(total) {
        const no_limite = total >= limite_fluxos;
        const aviso = 'Limite de ' + limite_fluxos + ' fluxos atingido. Exclua um para criar ou importar outro.';
        $('#btn-abrir-escolha-modo').text('+ Novo Fluxo (' + total + '/' + limite_fluxos + ')');
        $('#btn-abrir-escolha-modo, #btn-importar-fluxo-lista').prop('disabled', no_limite).attr('title', no_limite ? aviso : null);
    }

    function carregarFluxos() {
        const $list = $('#lista-fluxos');
        $list.html('<div class="a-empty s-todos">Carregando...</div>');

        $.getJSON(url_api + '?action=listar_fluxos')
            .done(function (response) {
                if (!response.sucesso) {
                    $list.html(`<div class="a-empty er s-todos">${escaparHtml(response.mensagem || 'Erro ao carregar fluxos.')}</div>`);
                    return;
                }

                const fluxos = response.fluxos || [];
                limite_fluxos = Number(response.limite_fluxos) || limite_fluxos;
                atualizarContadorFluxos(fluxos.length);
                $list.empty();

                if (!fluxos.length) {
                    $list.html('<div class="a-empty s-todos">Nenhum fluxo encontrado. Crie um novo!</div>');
                    return;
                }

                fluxos.forEach(function (flow) {
                    const eh_basico = flow.modo === 'basico';
                    const pagina_editar = eh_basico ? 'fluxo_basico' : 'fluxo';
                    const rotulo_modo = eh_basico ? 'Guiado' : 'Editor Visual';
                    const nome = flow.nome || 'Sem nome';
                    $list.append(`
                        <article class="a-card a-fx js-fluxo-card" data-fluxo-id="${escaparHtml(flow.id)}">
                            <div class="a-fx-top">
                                <span class="a-fx-ic">${eh_basico ? icone_modo.basico : icone_modo.visual}</span>
                                <span class="a-bd ${eh_basico ? 'or' : 'vi'}">${rotulo_modo}</span>
                            </div>
                            <h3>${escaparHtml(nome)}</h3>
                            <p>${escaparHtml(flow.descricao || 'Sem descrição')}</p>
                            <span class="when">Atualizado: ${escaparHtml(formatarDataFluxo(flow.atualizado_em))}</span>
                            <div class="a-fx-foot">
                                <span></span>
                                <div class="a-acts">
                                    <button type="button" class="a-ib btn-exportar-fluxo" data-id="${escaparHtml(flow.id)}" data-nome="${escaparHtml(nome)}" title="Exportar (código)" aria-label="Exportar o fluxo ${escaparHtml(nome)}">${icons.exportar}</button>
                                    <a href="${pagina_editar}?id=${encodeURIComponent(flow.id)}" class="a-ib" title="Editar fluxo" aria-label="Editar o fluxo ${escaparHtml(nome)}">${icons.edit}</a>
                                    <button type="button" class="a-ib btn-excluir-fluxo" data-id="${escaparHtml(flow.id)}" title="Excluir" aria-label="Excluir o fluxo ${escaparHtml(nome)}">${icons.trash}</button>
                                </div>
                            </div>
                        </article>
                    `);
                });
            })
            .fail(function () {
                $list.html('<div class="a-empty er s-todos">Não foi possível carregar os fluxos.</div>');
            });
    }

    function excluirFluxo(id) {
        if (!confirm('Tem certeza que deseja excluir este fluxo? Bots conectados a ele podem parar de funcionar.')) {
            return;
        }

        $.ajax({
            url: url_api + '?action=excluir_fluxo',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ id: id })
        }).done(function (response) {
            if (!response.sucesso) {
                exibirAviso(response.mensagem || 'Erro ao excluir fluxo.', 'erro');
                return;
            }
            exibirAviso('Fluxo excluído com sucesso.');
            carregarFluxos();
        }).fail(function (xhr) {
            exibirAviso((xhr.responseJSON && xhr.responseJSON.mensagem) || 'Erro ao excluir fluxo.', 'erro');
        });
    }

    $(function() {
        carregarFluxos();

        $(document).on('click', '.btn-excluir-fluxo', function() {
            const id = $(this).data('id');
            excluirFluxo(id);
        });

        // Importar e exportar por código (assets/js/fluxo_codigo.js): o fluxo não sai mais como arquivo .json.
        $('#btn-importar-fluxo-lista').on('click', function () {
            FluxoCodigo.abrirImportar({ aoImportar: function (r) { exibirAviso(r.mensagem || 'Fluxo importado com sucesso.'); carregarFluxos(); } });
        });
        $(document).on('click', '.btn-exportar-fluxo', function () {
            FluxoCodigo.abrirExportar($(this).data('id'), $(this).data('nome'));
        });
        // Fluxo.php (Editor Visual) manda para cá com ?importar=1: abre a janela de importar direto.
        if (/[?&]importar=1/.test(location.search)) {
            history.replaceState(null, '', location.pathname);
            $('#btn-importar-fluxo-lista').trigger('click');
        }

        // Modal "Como deseja criar?": abre pelo botão, fecha pelo ✕, clicando fora ou com Esc (o foco volta ao botão que abriu).
        const $modal_modo = $('#modal-escolha-modo');
        function abrirEscolhaModo() {
            $modal_modo.addClass('aberto');
            $('#btn-fechar-escolha-modo').trigger('focus');
        }
        function fecharEscolhaModo() {
            $modal_modo.removeClass('aberto');
            $('#btn-abrir-escolha-modo').trigger('focus');
        }
        $('#btn-abrir-escolha-modo').on('click', abrirEscolhaModo);
        $('#btn-fechar-escolha-modo').on('click', fecharEscolhaModo);
        $modal_modo.on('click', function (e) {
            if (e.target === this) fecharEscolhaModo();
        });
        $(document).on('keydown', function (e) {
            if (e.key === 'Escape' && $modal_modo.hasClass('aberto')) fecharEscolhaModo();
        });
    });

})(jQuery);
