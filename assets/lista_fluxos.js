(function ($) {
    const url_api = 'api.php';

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

    const icons = {
        edit: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>',
        trash: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>'
    };

    const miniatura_fluxo = '<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="var(--m)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="7" height="6" rx="1.5"></rect><rect x="15" y="2" width="7" height="6" rx="1.5"></rect><rect x="15" y="14" width="7" height="6" rx="1.5"></rect><path d="M9 7h3a2 2 0 0 1 2 2v0"></path><path d="M14 5h1M14 17h1"></path></svg>';

    function carregarFluxos() {
        const $list = $('#lista-fluxos');
        $list.html('<div class="estado-vazio">Carregando...</div>');

        $.getJSON(url_api + '?action=listar_fluxos')
            .done(function (response) {
                if (!response.sucesso) {
                    $list.html(`<div class="estado-vazio erro">${escaparHtml(response.mensagem || 'Erro ao carregar fluxos.')}</div>`);
                    return;
                }

                const fluxos = response.fluxos || [];
                $list.empty();

                if (!fluxos.length) {
                    $list.html('<div class="estado-vazio">Nenhum fluxo encontrado. Crie um novo!</div>');
                    return;
                }

                fluxos.forEach(function (flow) {
                    $list.append(`
                        <div class="cartao-bot-item cartao-fluxo">
                            <div class="miniatura-fluxo">${miniatura_fluxo}</div>
                            <div class="cartao-cabecalho">
                                <h3>${escaparHtml(flow.nome || 'Sem nome')}</h3>
                            </div>
                            <div class="cartao-corpo">
                                <p>${escaparHtml(flow.descricao || 'Sem descrição')}</p>
                                <p class="texto-suave mono">Atualizado: ${escaparHtml(flow.atualizado_em || '-')}</p>
                            </div>
                            <div class="cartao-acoes">
                                <a href="fluxo?id=${encodeURIComponent(flow.id)}" class="botao botao-editar-fluxo">Editar fluxo</a>
                                <button type="button" class="btn-icon excluir btn-excluir-fluxo" data-id="${escaparHtml(flow.id)}" title="Excluir">${icons.trash}</button>
                            </div>
                        </div>
                    `);
                });
            })
            .fail(function () {
                $list.html('<div class="estado-vazio erro">Não foi possível carregar os fluxos.</div>');
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
    });

})(jQuery);
