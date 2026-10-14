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

    // Duas letras, como no protótipo: iniciais de duas palavras ("Sítio Monte Luca" -> SM,
    // "katii_gamer" -> KG) ou as duas primeiras letras quando é uma palavra só.
    function iniciaisBot(nome) {
        const partes = String(nome).split(/[^\p{L}\p{N}]+/u).filter(Boolean);
        if (!partes.length) return '?';
        const letras = partes.length > 1 ? partes[0].charAt(0) + partes[1].charAt(0) : partes[0].slice(0, 2);
        return letras.toUpperCase();
    }

    const icones_config = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h.01a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51h.01a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v.01a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>';
    const icone_robo ='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8V4H8M6 8h12a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-8a2 2 0 0 1 2-2zM2 14h2M20 14h2M15 13v2M9 13v2"></path></svg>';
    const icone_servidor = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="7" rx="2"></rect><rect x="3" y="13" width="18" height="7" rx="2"></rect></svg>';
    const icone_terminal = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 8l5 4-5 4M13 17h6"></path></svg>';
    const icone_mais = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"></path></svg>';

    let limite_bots = 50;

    // Contador no botão ("+ Novo Bot (1/50)"); no limite, o botão e o card de adicionar ficam bloqueados.
    function atualizarContadorBots(total) {
        const no_limite = total >= limite_bots;
        $('#btn-novo-bot').text('+ Novo Bot (' + total + '/' + limite_bots + ')')
            .toggleClass('desativado', no_limite)
            .attr('title', no_limite ? 'Limite de ' + limite_bots + ' bots atingido. Exclua um para conectar outro.' : null);
    }

    function carregarBots() {
        const $list = $('#lista-bots');
        $list.html('<div class="estado-vazio">Carregando...</div>');

        $.getJSON(url_api + '?action=listar_bots')
            .done(function (response) {
                if (!response.sucesso) {
                    $list.html(`<div class="estado-vazio erro">${escaparHtml(response.mensagem || 'Erro ao carregar bots.')}</div>`);
                    return;
                }

                const bots = response.bots || [];
                limite_bots = Number(response.limite_bots) || limite_bots;
                atualizarContadorBots(bots.length);
                $list.empty();

                bots.forEach(function (bot) {
                    const nome = bot.primeiro_nome || bot.nome_usuario || 'Sem nome';
                    const tem_fluxo = Boolean(bot.id_fluxo_conectado);
                    const nome_fluxo = tem_fluxo ? (bot.nome_fluxo || 'Fluxo #' + bot.id_fluxo_conectado) : '— definir';
                    const leads_7d = Number(bot.leads_7d || 0).toLocaleString('pt-BR');
                    const vendas_7d = Number(bot.vendas_7d || 0).toLocaleString('pt-BR');
                    // Online = webhook registrado no Telegram (o bot já recebe mensagens).
                    const online = Boolean(bot.url_webhook);
                    // Token recusado pelo Telegram (revogado/trocado): o bot parou de atender; o dono também recebe aviso no sino.
                    const token_recusado = Boolean(bot.token_invalido_em);
                    const avatar = bot.caminho_foto
                        ? `<img src="${escaparHtml(bot.caminho_foto)}" alt="" onerror="this.replaceWith(Object.assign(document.createElement('span'), {className: 'bot-avatar-icone', innerHTML: this.dataset.icone}))" data-icone="${escaparHtml(icone_robo)}">`
                        : `<span class="bot-avatar-icone">${icone_robo}</span>`;

                    $list.append(`
                        <div class="cartao-bot-item cartao-bot">
                            <div class="bot-selos">
                                ${token_recusado ? '<span class="bot-selo alerta" title="O Telegram recusou o token deste bot (revogado ou trocado). Cole o token novo em Configurar."><i></i>Token recusado</span>' : `<span class="bot-selo ${online ? 'ok' : 'alerta'}"><i></i>${online ? 'Online' : 'Offline'}</span>`}
                                <span class="bot-selo ${tem_fluxo ? 'ok' : 'alerta'}" title="${tem_fluxo ? 'Este bot tem um fluxo conectado' : 'Este bot não tem fluxo conectado e não responde aos leads'}"><i></i>${tem_fluxo ? 'Com fluxo' : 'Sem fluxo'}</span>
                            </div>
                            <div class="bot-avatar">${avatar}</div>
                            <h3 class="bot-nome">${escaparHtml(nome)}</h3>
                            <span class="bot-username mono">@${escaparHtml(bot.nome_usuario || 'sem_username')}</span>
                            <div class="bot-numeros">
                                <div><span class="bot-numeros-rotulo">Leads 8d</span><strong>${leads_7d}</strong></div>
                                <div><span class="bot-numeros-rotulo">Vendas 8d</span><strong>${vendas_7d}</strong></div>
                            </div>
                            <div class="bot-fluxo-linha${tem_fluxo ? '' : ' vazio'}">${icone_terminal}<span>${escaparHtml(tem_fluxo ? nome_fluxo : 'Sem fluxo conectado')}</span></div>
                            <div class="cartao-acoes">
                                <a href="bot?id=${encodeURIComponent(bot.id)}" class="btn-icon editar" title="Configurar bot" aria-label="Configurar bot">${icones_config}</a>
                                <button type="button" class="btn-icon excluir btn-excluir-bot" data-id="${escaparHtml(bot.id)}" title="Excluir">${icons.trash}</button>
                            </div>
                        </div>
                    `);
                });

                if (bots.length >= limite_bots) return;
                $list.append(`
                    <a href="bot" class="cartao-bot-novo">
                        <span class="cartao-bot-novo-icone">${icone_mais}</span>
                        <strong>Conectar Novo Bot</strong>
                    </a>
                `);
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
            url: url_api + '?action=excluir_bot',
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
        
        $(document).on('click', '.desativado', function (e) { e.preventDefault(); exibirAviso($(this).attr('title') || 'Limite atingido.', 'erro'); });

        $(document).on('click', '.btn-excluir-bot', function() {
            const id = $(this).data('id');
            excluirBot(id);
        });
    });

})(jQuery);
