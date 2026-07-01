(function ($) {
    const urlApi = 'api.php';
    let botAtual = null;

    function exibirAviso(message, type = 'sucesso') {
        const $toast = $('#toast');
        $toast.removeClass('sucesso erro visivel').addClass(type).text(message);
        requestAnimationFrame(() => $toast.addClass('visivel'));
        clearTimeout(window.__toastTimeout);
        // Se for erro, deixa mais tempo (6s), se sucesso 3s
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

    function atualizarCardStatus(botInfo, isOnline) {
        // Se não tiver botInfo, reseta para estado inicial
        if (!botInfo) {
            $('#status-avatar-container').html('<div class="bot-avatar-placeholder">?</div>');
            $('#status-nome').text('Novo Bot');
            $('#status-username').text('@...');
            $('#status-badge').removeClass('online offline').html('<span class="status-dot"></span> <span class="status-text">Desconhecido</span>');
            return;
        }

        // Avatar
        if (botInfo.caminho_foto) {
             $('#status-avatar-container').html(`<img src="${escaparHtml(botInfo.caminho_foto)}?t=${Date.now()}" class="bot-avatar" alt="Bot Avatar">`);
        } else {
             const inicial = (botInfo.first_name || 'B').charAt(0).toUpperCase();
             $('#status-avatar-container').html(`<div class="bot-avatar-placeholder">${inicial}</div>`);
        }

        // Textos
        $('#status-nome').text(botInfo.first_name || 'Sem nome');
        $('#status-username').text(botInfo.username ? `@${botInfo.username}` : 'Sem username');

        // Badge
        const $badge = $('#status-badge');
        if (isOnline) {
            $badge.removeClass('offline').addClass('online').html('<span class="status-dot"></span> <span class="status-text">Online</span>');
        } else {
            $badge.removeClass('online').addClass('offline').html('<span class="status-dot"></span> <span class="status-text">Offline</span>');
        }
    }

    function preencherFormularioBot(bot) {
        if (!bot) return;
        
        botAtual = bot;
        
        // Logs para debug
        console.log('Preenchendo bot:', bot);
        
        // Garante que os valores existam
        $('#id-bot').val(bot.id || '');
        $('#token').val(bot.token || '');
        
        // O select de fluxos pode não estar carregado ainda
        const $selectFluxo = $('#id-fluxo-conectado');
        if ($selectFluxo.find('option').length <= 1) {
            // Se ainda não carregou, agenda o preenchimento
            setTimeout(() => {
                $selectFluxo.val(bot.id_fluxo_conectado || '');
            }, 1000);
        } else {
            $selectFluxo.val(bot.id_fluxo_conectado || '');
        }

        $('#name').val(bot.primeiro_nome || '');
        $('#description').val(bot.descricao || '');
        $('#descricao-curta').val(bot.descricao_curta || '');

        // Atualiza card lateral
        atualizarCardStatus({
            first_name: bot.primeiro_nome,
            username: bot.nome_usuario,
            caminho_foto: bot.caminho_foto
        }, !!bot.url_webhook);
    }

    function limparFormularioBot() {
        botAtual = null;
        $('#id-bot').val('');
        $('#token').val('');
        $('#name').val('');
        $('#description').val('');
        $('#descricao-curta').val('');
        $('#id-fluxo-conectado').val('');
        $('#photo').val('');
        
        atualizarCardStatus(null, false);

        // Atualiza a URL
        if (window.history.pushState) {
            const newUrl = window.location.protocol + "//" + window.location.host + window.location.pathname;
            window.history.pushState({path:newUrl},'',newUrl);
        }
    }

    function carregarFluxos() {
        return $.getJSON(urlApi + '?action=listar_fluxos')
            .done(function (response) {
                if (!response.sucesso) return;
                const $select = $('#id-fluxo-conectado');
                const currentValue = $select.val();
                $select.html('<option value="">Selecione um fluxo...</option>');
                (response.fluxos || []).forEach(function (flow) {
                    $select.append(`<option value="${escaparHtml(flow.id)}">${escaparHtml(flow.nome)}</option>`);
                });
                if (currentValue) $select.val(currentValue);
            });
    }

    function carregarBotPeloId(id) {
        $.getJSON(urlApi + '?action=obter_bot&id=' + encodeURIComponent(id))
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
        
        // Estado de loading visual
        const $btn = $('#btn-testar-token');
        const originalText = $btn.text();
        $btn.text('Testando...').prop('disabled', true);

        $.ajax({
            url: urlApi + '?action=testar_bot',
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
            // Preenche campos de perfil se estiverem vazios
            if (!$('#name').val()) $('#name').val(info.first_name || '');
            
            // Atualiza Card Lateral
            atualizarCardStatus({
                first_name: info.first_name,
                username: info.username,
                // foto não vem no getMe simples geralmente, mas ok
            }, true);
            
            exibirAviso('Conexão bem sucedida! Bot Online.');
        }).fail(function () {
            exibirAviso('Erro ao testar conexão.', 'erro');
        }).always(function() {
            $btn.text(originalText).prop('disabled', false);
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
        const originalText = $btn.text();
        $btn.text('Salvando...').prop('disabled', true);

        $.ajax({
            url: urlApi + '?action=salvar_bot',
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
            
            // Atualiza URL se for novo
            if (!dados.id && response.bot && response.bot.id) {
                const newUrl = window.location.pathname + '?id=' + response.bot.id;
                window.history.pushState({path:newUrl},'',newUrl);
            }
        }).fail(function () {
            exibirAviso('Erro ao salvar bot.', 'erro');
        }).always(function() {
            $btn.text(originalText).prop('disabled', false);
        });
    }

    function atualizarPerfilTelegram(event) {
        event.preventDefault();
        
        const $form = $(this);
        const $btn = $form.find('button[type="submit"]');
        const originalText = $btn.text();
        $btn.text('Atualizando...').prop('disabled', true);

        const formData = new FormData();
        formData.append('id', $('#id-bot').val());
        formData.append('token', $('#token').val().trim());
        formData.append('nome', $('#name').val().trim());
        formData.append('descricao', $('#description').val().trim());
        formData.append('descricao_curta', $('#descricao-curta').val().trim());
        // Enviamos o fluxo tbm pra garantir consistência, mas o foco é perfil
        formData.append('id_fluxo_conectado', $('#id-fluxo-conectado').val());

        const photoInput = $('#photo')[0];
        if (photoInput.files && photoInput.files[0]) {
            formData.append('photo', photoInput.files[0]);
        }

        $.ajax({
            url: urlApi + '?action=atualizar_perfil_bot',
            method: 'POST',
            data: formData,
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
            $('#photo').val(''); // Limpa input file
            exibirAviso(response.mensagem || 'Perfil atualizado no Telegram!');
        }).fail(function () {
            exibirAviso('Erro de conexão ao atualizar perfil.', 'erro');
        }).always(function() {
            $btn.text(originalText).prop('disabled', false);
        });
    }

    // Listeners
    $('#btn-testar-token').on('click', testarConexaoBot);
    $('#btn-salvar-bot').on('click', salvarConfiguracoes);

    $('#formulario-perfil').on('submit', atualizarPerfilTelegram);

    // Tools
    $('#btn-info-webhook').on('click', function () {
        const token = $('#token').val().trim();
        if (!token) return exibirAviso('Token necessário.', 'erro');

        $.ajax({
            url: urlApi + '?action=obter_info_webhook',
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
        const idBot = $('#id-bot').val();
        
        if (!token) return exibirAviso('Token necessário.', 'erro');
        if (!confirm('Isso irá limpar todas as mensagens pendentes e reiniciar a conexão. Use se o bot estiver travado.\n\nDeseja continuar?')) return;

        const $btn = $(this);
        const originalText = $btn.html();
        $btn.prop('disabled', true).text('Processando...');

        $.ajax({
            url: urlApi + '?action=reiniciar_webhook',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ token: token, id: idBot })
        }).done(function (response) {
            if (response.sucesso) {
                exibirAviso(response.mensagem);
            } else {
                exibirAviso(response.mensagem || 'Erro ao reiniciar.', 'erro');
            }
        }).fail(function () {
            exibirAviso('Erro de conexão.', 'erro');
        }).always(function() {
            $btn.prop('disabled', false).html(originalText);
        });
    });

    // Init
    // Verifica parâmetro ID na URL
    const urlParams = new URLSearchParams(window.location.search);
    const botId = urlParams.get('id');

    // Carrega fluxos primeiro e só depois carrega o bot (se houver ID)
    carregarFluxos().always(function() {
        if (botId) {
            carregarBotPeloId(botId);
        } else {
            limparFormularioBot();
        }
    });

})(jQuery);
