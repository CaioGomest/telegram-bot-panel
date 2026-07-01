(function ($) {
    const urlApi = 'api.php';

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

    // Ícones SVG
    const icons = {
        edit: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>',
        trash: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>'
    };

    function carregarBots() {
        const $list = $('#lista-bots');
        $list.html('<div class="estado-vazio">Carregando...</div>');

        $.getJSON(urlApi + '?action=listar_bots')
            .done(function (response) {
                if (!response.sucesso) {
                    $list.html(`<div class="estado-vazio erro">${escaparHtml(response.mensagem || 'Erro ao carregar bots.')}</div>`);
                    return;
                }

                const bots = response.bots || [];
                $list.empty();

                if (!bots.length) {
                    $list.html('<div class="estado-vazio">Nenhum bot encontrado. Crie um novo!</div>');
                    return;
                }

                bots.forEach(function (bot) {
                    const flowText = bot.id_fluxo_conectado ? `Fluxo: ${escaparHtml(bot.id_fluxo_conectado)}` : 'Sem fluxo ligado';
                    
                    $list.append(`
                        <div class="cartao-bot-item">
                            <div class="cartao-cabecalho">
                                <h3>${escaparHtml(bot.primeiro_nome || 'Sem nome')}</h3>
                                <span class="badge">@${escaparHtml(bot.nome_usuario || 'sem_username')}</span>
                            </div>
                            <div class="cartao-corpo">
                                <p>${flowText}</p>
                                <p class="texto-suave">${escaparHtml(bot.descricao_curta || 'Sem descrição')}</p>
                            </div>
                            <div class="cartao-acoes">
                                <a href="bot.php?id=${encodeURIComponent(bot.id)}" class="btn-icon editar" title="Editar">${icons.edit}</a>
                                <button type="button" class="btn-icon excluir btn-excluir-bot" data-id="${escaparHtml(bot.id)}" title="Excluir">${icons.trash}</button>
                            </div>
                        </div>
                    `);
                });
            })
            .fail(function () {
                $list.html('<div class="estado-vazio erro">Não foi possível carregar os bots.</div>');
            });
    }

    function excluirBot(id) {
        if (!confirm('Tem certeza que deseja excluir este bot? Esta ação não pode ser desfeita.')) {
            return;
        }

        $.ajax({
            url: urlApi + '?action=excluir_bot',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ id: id })
        }).done(function (response) {
            if (!response.sucesso) {
                exibirAviso(response.mensagem || 'Erro ao excluir bot.', 'erro');
                return;
            }
            exibirAviso('Bot excluído com sucesso.');
            carregarBots();
        }).fail(function (xhr) {
            exibirAviso((xhr.responseJSON && xhr.responseJSON.mensagem) || 'Erro ao excluir bot.', 'erro');
        });
    }

    $(function() {
        carregarBots();
        
        $(document).on('click', '.btn-excluir-bot', function() {
            const id = $(this).data('id');
            excluirBot(id);
        });
    });

})(jQuery);
