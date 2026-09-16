(function ($) {
    const url_api = 'api.php';
    let bot_atual = null;

    function exibirAviso(message, type = 'sucesso') {
        const $toast = $('#toast');
        $toast.removeClass('sucesso erro visivel').addClass(type).text(message);
        requestAnimationFrame(() => $toast.addClass('visivel'));
        clearTimeout(window.__toastTimeout);
        const tempo = type === 'erro' ? 6000 : 3000;
        window.__toastTimeout = setTimeout(() => $toast.removeClass('visivel'), tempo);
    }

    function escaparHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function atualizarCardStatus(bot_info, is_online) {
        if (!bot_info) {
            $('#status-avatar-container').html('<div class="avatar-preview-bot-placeholder">?</div>');
            $('#status-nome').text('Novo Bot');
            $('#status-username').text('@...');
            $('#status-badge').removeClass('ligado desligado').html('<span class="ponto-status"></span> <span class="texto-status">Desconhecido</span>');
            return;
        }

        if (bot_info.caminho_foto) {
             $('#status-avatar-container').html(`<img src="${escaparHtml(bot_info.caminho_foto)}?t=${Date.now()}" class="avatar-preview-bot" alt="Bot Avatar">`);
        } else {
             const inicial = (bot_info.first_name || 'B').charAt(0).toUpperCase();
             $('#status-avatar-container').html(`<div class="avatar-preview-bot-placeholder">${inicial}</div>`);
        }

        $('#status-nome').text(bot_info.first_name || 'Sem nome');
        $('#status-username').text(bot_info.username ? `@${bot_info.username}` : 'Sem username');

        const $badge = $('#status-badge');
        if (is_online) {
            $badge.removeClass('desligado').addClass('ligado').html('<span class="ponto-status"></span> <span class="texto-status">Online</span>');
        } else {
            $badge.removeClass('ligado').addClass('desligado').html('<span class="ponto-status"></span> <span class="texto-status">Offline</span>');
        }
    }

    function preencherFormularioBot(bot) {
        if (!bot) return;
        
        bot_atual = bot;

        console.log('Preenchendo bot:', bot);

        $('#id-bot').val(bot.id || '');
        $('#token').val(bot.token || '');
        
        // O select de fluxos pode não estar carregado ainda
        const $select_fluxo = $('#id-fluxo-conectado');
        if ($select_fluxo.find('option').length <= 1) {
            setTimeout(() => {
                $select_fluxo.val(bot.id_fluxo_conectado || '');
            }, 1000);
        } else {
            $select_fluxo.val(bot.id_fluxo_conectado || '');
        }

        $('#name').val(bot.primeiro_nome || '');
        $('#description').val(bot.descricao || '');
        $('#descricao-curta').val(bot.descricao_curta || '');

        atualizarCardStatus({
            first_name: bot.primeiro_nome,
            username: bot.nome_usuario,
            caminho_foto: bot.caminho_foto
        }, !!bot.url_webhook);
    }

    function limparFormularioBot() {
        bot_atual = null;
        $('#id-bot').val('');
        $('#token').val('');
        $('#name').val('');
        $('#description').val('');
        $('#descricao-curta').val('');
        $('#id-fluxo-conectado').val('');
        $('#photo').val('');
        
        atualizarCardStatus(null, false);

        if (window.history.pushState) {
            const new_url = window.location.protocol + "//" + window.location.host + window.location.pathname;
            window.history.pushState({path:new_url},'',new_url);
        }
    }

    function carregarFluxos() {
        return $.getJSON(url_api + '?action=listar_fluxos')
            .done(function (response) {
                if (!response.sucesso) return;
                const $select = $('#id-fluxo-conectado');
                const current_value = $select.val();
                $select.html('<option value="">Selecione um fluxo...</option>');
                (response.fluxos || []).forEach(function (flow) {
                    $select.append(`<option value="${escaparHtml(flow.id)}">${escaparHtml(flow.nome)}</option>`);
                });
                if (current_value) $select.val(current_value);
            });
    }

    function carregarBotPeloId(id) {
        $.getJSON(url_api + '?action=obter_bot&id=' + encodeURIComponent(id))
            .done(function (response) {
                if (!response.sucesso) {
                    exibirAviso(response.mensagem || 'Bot não encontrado.', 'erro');
                    return;
                }
                preencherFormularioBot(response.bot);
            })
            .fail(function () {
                exibirAviso('Erro ao abrir o bot.', 'erro');
            });
    }

    function testarConexaoBot() {
        const token = $('#token').val().trim();
        if (!token) {
            exibirAviso('Informe o token do bot.', 'erro');
            return;
        }

        const $btn = $('#btn-testar-token');
        const original_text = $btn.text();
        $btn.text('Testando...').prop('disabled', true);

        $.ajax({
            url: url_api + '?action=testar_bot',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ token: token })
        }).done(function (response) {
            if (!response.sucesso) {
                exibirAviso(response.mensagem || 'Falha ao conectar.', 'erro');
                atualizarCardStatus({ first_name: 'Erro de Conexão' }, false);
                return;
            }
            const info = response.info_bot || {};
            if (!$('#name').val()) $('#name').val(info.first_name || '');

            atualizarCardStatus({
                first_name: info.first_name,
                username: info.username,
                // foto não vem no getMe simples geralmente, mas ok
            }, true);
            
            exibirAviso('Conexão bem sucedida! Bot Online.');
        }).fail(function () {
            exibirAviso('Erro ao testar conexão.', 'erro');
        }).always(function() {
            $btn.text(original_text).prop('disabled', false);
        });
    }

    function salvarConfiguracoes() {
        const dados = {
            id: $('#id-bot').val(),
            token: $('#token').val().trim(),
            id_fluxo_conectado: $('#id-fluxo-conectado').val()
        };

        if (!dados.token) {
            exibirAviso('O token é obrigatório.', 'erro');
            return;
        }

        const $btn = $('#btn-salvar-bot');
        const original_text = $btn.text();
        $btn.text('Salvando...').prop('disabled', true);

        $.ajax({
            url: url_api + '?action=salvar_bot',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(dados)
        }).done(function (response) {
            if (!response.sucesso) {
                exibirAviso(response.mensagem || 'Erro ao salvar.', 'erro');
                return;
            }
            preencherFormularioBot(response.bot);
            exibirAviso('Configurações salvas com sucesso.');

            if (!dados.id && response.bot && response.bot.id) {
                const new_url = window.location.pathname + '?id=' + response.bot.id;
                window.history.pushState({path:new_url},'',new_url);
            }
        }).fail(function () {
            exibirAviso('Erro ao salvar bot.', 'erro');
        }).always(function() {
            $btn.text(original_text).prop('disabled', false);
        });
    }

    function atualizarPerfilTelegram(event) {
        event.preventDefault();

        const $form = $(this);
        const $btn = $form.find('button[type="submit"]');
        const original_text = $btn.text();
        $btn.text('Atualizando...').prop('disabled', true);

        const form_data = new FormData();
        form_data.append('id', $('#id-bot').val());
        form_data.append('token', $('#token').val().trim());
        form_data.append('nome', $('#name').val().trim());
        form_data.append('descricao', $('#description').val().trim());
        form_data.append('descricao_curta', $('#descricao-curta').val().trim());
        // Enviamos o fluxo tbm pra garantir consistência, mas o foco é perfil
        form_data.append('id_fluxo_conectado', $('#id-fluxo-conectado').val());

        const photo_input = $('#photo')[0];
        if (photo_input.files && photo_input.files[0]) {
            form_data.append('photo', photo_input.files[0]);
        }

        $.ajax({
            url: url_api + '?action=atualizar_perfil_bot',
            method: 'POST',
            data: form_data,
            processData: false,
            contentType: false
        }).done(function (response) {
            // Se sucesso for false, mas tivermos uma mensagem, mostramos como erro
            // Se sucesso for true, mostramos como sucesso (verde)
            // Se houver avisos parciais, o backend pode mandar sucesso=true com msg de aviso?
            // Vamos confiar na flag sucesso por enquanto, mas garantir que a mensagem apareça.
            
            if (response.bot) {
                preencherFormularioBot(response.bot);
            }

            if (!response.sucesso) {
                exibirAviso(response.mensagem || 'Erro ao atualizar perfil.', 'erro');
                return;
            }
            $('#photo').val('');
            exibirAviso(response.mensagem || 'Perfil atualizado no Telegram!');
        }).fail(function () {
            exibirAviso('Erro de conexão ao atualizar perfil.', 'erro');
        }).always(function() {
            $btn.text(original_text).prop('disabled', false);
        });
    }

    $('#btn-testar-token').on('click', testarConexaoBot);
    $('#btn-salvar-bot').on('click', salvarConfiguracoes);

    $('#formulario-perfil').on('submit', atualizarPerfilTelegram);

    $('#btn-info-webhook').on('click', function () {
        const token = $('#token').val().trim();
        if (!token) return exibirAviso('Token necessário.', 'erro');

        $.ajax({
            url: url_api + '?action=obter_info_webhook',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ token: token })
        }).done(function (response) {
            if (response.sucesso) {
                const info = response.info;
                let mensagem = `✅ Webhook Ativo!\n\n`;
                mensagem += `URL: ${info.url || 'Não definida'}\n`;
                mensagem += `Atualizações Pendentes: ${info.pending_update_count}\n`;
                
                alert(mensagem);
            } else {
                exibirAviso('Erro ao obter info.', 'erro');
            }
        });
    });

    $('#btn-reiniciar-webhook').on('click', function () {
        const token = $('#token').val().trim();
        const id_bot = $('#id-bot').val();

        if (!token) return exibirAviso('Token necessário.', 'erro');
        if (!confirm('Isso irá limpar todas as mensagens pendentes e reiniciar a conexão. Use se o bot estiver travado.\n\nDeseja continuar?')) return;

        const $btn = $(this);
        const original_text = $btn.html();
        $btn.prop('disabled', true).text('Processando...');

        $.ajax({
            url: url_api + '?action=reiniciar_webhook',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ token: token, id: id_bot })
        }).done(function (response) {
            if (response.sucesso) {
                exibirAviso(response.mensagem);
            } else {
                exibirAviso(response.mensagem || 'Erro ao reiniciar.', 'erro');
            }
        }).fail(function () {
            exibirAviso('Erro de conexão.', 'erro');
        }).always(function() {
            $btn.prop('disabled', false).html(original_text);
        });
    });

    const url_params = new URLSearchParams(window.location.search);
    const bot_id = url_params.get('id');

    // Carrega fluxos primeiro e só depois carrega o bot (se houver ID)
    carregarFluxos().always(function() {
        if (bot_id) {
            carregarBotPeloId(bot_id);
        } else {
            limparFormularioBot();
        }
    });

})(jQuery);
