(function ($) {
    const api_url = 'api.php';
    const $flowchart = $('#area-trabalho-fluxograma');
    let current_flow = null;
    let operator_index = 1;
    let fluxo_sujo = false;
    let suprimir_auto_salvar = false;
    let timer_auto_salvar = null;
    let intervalo_backup = null;
    let zoom_level = 1;
    let lista_grupos_usuario = [];
    let gateway_suporta_recorrente = false;
    let ultimo_salvo_em = null;

    function tempoRelativoSalvo() {
        if (!ultimo_salvo_em) return 'ainda não salvo';
        const diffMin = Math.floor((Date.now() - ultimo_salvo_em.getTime()) / 60000);
        if (diffMin < 1) return 'salvo agora';
        if (diffMin < 60) return 'salvo há ' + diffMin + ' min';
        const diffH = Math.floor(diffMin / 60);
        return 'salvo há ' + diffH + 'h';
    }

    function atualizarCabecalhoFluxo() {
        const nome = ($('#nome-fluxo').val() || '').trim() || 'Novo fluxo';
        const data = getChartData();
        const total_blocos = Math.max(0, Object.keys(data.operators || {}).length - 1); // exclui o Início
        $('#texto-nome-fluxo-colapsado').text(nome);
        $('#subtitulo-editor-fluxo').text(nome + ' · ' + total_blocos + ' bloco' + (total_blocos === 1 ? '' : 's') + ' · ' + tempoRelativoSalvo());
    }
    setInterval(function () {
        if ($('#id-fluxo').val()) atualizarCabecalhoFluxo();
    }, 30000);

    function carregarGruposUsuario() {
        return $.getJSON(api_url + '?action=listar_grupos_usuario')
            .done(function(resp) {
                if (resp.sucesso) {
                    lista_grupos_usuario = resp.grupos || [];
                }
            });
    }

    function carregarGatewayInfo() {
        return $.getJSON(api_url + '?action=gateway_info')
            .done(function(resp) {
                if (resp.sucesso) {
                    gateway_suporta_recorrente = resp.suporta_recorrente === true;
                }
            });
    }

    function exibirAviso(message, type = 'sucesso') {
        const $toast = $('#toast');
        $toast.removeClass('sucesso erro visivel').addClass(type).text(message);
        requestAnimationFrame(() => $toast.addClass('visivel'));
        clearTimeout(window.__toastTimeout);
        window.__toastTimeout = setTimeout(() => $toast.removeClass('visivel'), 3000);
    }
    const showToast = exibirAviso;

    function escaparHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }
    const escapeHtml = escaparHtml;

    // Extrai o texto de um botão — fluxos antigos (ou testes anteriores) podem ter
    // salvo o botão como objeto {texto,...} em vez de string simples; aceita os dois.
    function textoBotao(b) {
        return (b && typeof b === 'object') ? (b.texto || '') : (b || '');
    }

    const block_icons = {
        start: '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg>',
        message: '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>',
        image: '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>',
        botoes: '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 4h14a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2zM5 14h8a2 2 0 0 1 2 2v2a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-2a2 2 0 0 1 2-2z"></path></svg>',
        pix: '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l10 10-10 10L2 12zM8 12l4-4 4 4-4 4z"></path></svg>',
        video: '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 7l-7 5 7 5V7zM3 5h11a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2z"></path></svg>',
        audio: '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"></path><path d="M19 10v2a7 7 0 0 1-14 0v-2"></path><line x1="12" y1="19" x2="12" y2="23"></line><line x1="8" y1="23" x2="16" y2="23"></line></svg>',
        link: '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>',
        grupo: '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>',
        delay: '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>',
        randomizer: '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 3h5v5M4 20L21 3M21 16v5h-5M15 15l6 6M4 4l5 5"></path></svg>',
        upsell: '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 7l-8.5 8.5-5-5L2 17M16 7h6v6"></path></svg>',
        downsell: '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 17l-8.5-8.5-5 5L2 7M16 17h6v-6"></path></svg>',
        order_bump: '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21a1 1 0 1 0 0-2 1 1 0 0 0 0 2zM20 21a1 1 0 1 0 0-2 1 1 0 0 0 0 2zM1 1h4l2.7 13.4a2 2 0 0 0 2 1.6h9.7a2 2 0 0 0 2-1.6L23 6H6"></path></svg>'
    };

    function defaultChartData() {
        return {
            operators: {
                operator_1: {
                    top: 400,
                    left: 400,
                    properties: {
                        title: 'Início',
                        body: 'Primeiro passo do fluxo',
                        type: 'start',
                        class: 'no-inicio',
                        inputs: {},
                        outputs: {
                            output_1: { label: 'Próximo' }
                        }
                    }
                }
            },
            links: {}
        };
    }

    function renderCorpoDoBloco(props) {
        const tipo = props.type || 'message';
        function opt(selected) { return selected ? ' selected' : ''; }
        function chk(checked) { return checked ? ' checked' : ''; }
        if (tipo === 'start') {
            return '<div class="bloco-config bloco-inicio">Primeiro passo do fluxo</div>';
        }
        if (tipo === 'image') {
            const caminho = props.image_path || '';
            const preview = caminho
                ? '<img class="previa-imagem" src="' + escaparHtml(caminho) + '?t=' + Date.now() + '" alt="Imagem">'
                : '<div class="sem-imagem">Sem imagem (Clique para adicionar)</div>';
            return '' +
                '<div class="bloco-config bloco-imagem">' +
                '  <div class="area-previa" title="Clique para trocar">' + preview + '</div>' +
                '  <label>Legenda</label>' +
                '  <input class="campo-legenda" type="text" value="' + escaparHtml(props.caption || '') + '" placeholder="Digite uma legenda...">' +
                '  <div class="linha-flex"><input class="campo-spoiler" type="checkbox"' + chk(!!props.spoiler) + '> <label style="margin:0">Spoiler</label></div>' +
                '  <div class="linha-flex"><input class="campo-auto-deletar" type="checkbox"' + chk(!!props.auto_delete) + '> <label style="margin:0">Auto-deletar</label></div>' +
                '  <div class="grupo-auto-delete" style="' + (!props.auto_delete ? 'display:none;' : '') + '">' +
                '    <label>Segundos</label>' +
                '    <input class="campo-auto-segundos" type="number" min="0" value="' + (props.auto_delete_seconds || 0) + '">' +
                '  </div>' +
                '  <input class="campo-imagem-arquivo" type="file" accept="image/*" style="display:none">' +
                '</div>';
        }
        if (tipo === 'video') {
            const caminho = props.video_path || '';
            const preview = caminho
                ? '<video class="previa-video" src="' + escaparHtml(caminho) + '?t=' + Date.now() + '" controls style="width:100%; max-height:100px; object-fit:contain;"></video>'
                : '<div class="sem-imagem">Sem vídeo (Clique para adicionar)</div>';
            return '' +
                '<div class="bloco-config bloco-video">' +
                '  <div class="area-previa" title="Clique para trocar">' + preview + '</div>' +
                '  <label>Legenda</label>' +
                '  <input class="campo-legenda" type="text" value="' + escaparHtml(props.caption || '') + '" placeholder="Digite uma legenda...">' +
                '  <div class="linha-flex"><input class="campo-spoiler" type="checkbox"' + chk(!!props.spoiler) + '> <label style="margin:0">Spoiler</label></div>' +
                '  <div class="linha-flex"><input class="campo-auto-deletar" type="checkbox"' + chk(!!props.auto_delete) + '> <label style="margin:0">Auto-deletar</label></div>' +
                '  <div class="grupo-auto-delete" style="' + (!props.auto_delete ? 'display:none;' : '') + '">' +
                '    <label>Segundos</label>' +
                '    <input class="campo-auto-segundos" type="number" min="0" value="' + (props.auto_delete_seconds || 0) + '">' +
                '  </div>' +
                '  <input class="campo-video-arquivo" type="file" accept="video/*" style="display:none">' +
                '</div>';
        }
        if (tipo === 'audio') {
            const caminho = props.audio_path || '';
            const preview = caminho
                ? '<audio class="previa-audio" src="' + escaparHtml(caminho) + '?t=' + Date.now() + '" controls style="width:100%;"></audio>'
                : '<div class="sem-imagem">Sem áudio (Clique para adicionar)</div>';
            return '' +
                '<div class="bloco-config bloco-audio">' +
                '  <div class="area-previa" title="Clique para trocar">' + preview + '</div>' +
                '  <label>Legenda</label>' +
                '  <input class="campo-legenda" type="text" value="' + escaparHtml(props.caption || '') + '" placeholder="Digite uma legenda...">' +
                '  <div class="linha-flex"><input class="campo-auto-deletar" type="checkbox"' + chk(!!props.auto_delete) + '> <label style="margin:0">Auto-deletar</label></div>' +
                '  <div class="grupo-auto-delete" style="' + (!props.auto_delete ? 'display:none;' : '') + '">' +
                '    <label>Segundos</label>' +
                '    <input class="campo-auto-segundos" type="number" min="0" value="' + (props.auto_delete_seconds || 0) + '">' +
                '  </div>' +
                '  <input class="campo-audio-arquivo" type="file" accept="audio/*" style="display:none">' +
                '</div>';
        }
        if (tipo === 'botoes') {
            const lista = (props.botoes || []).map(function (t, i) {
                return '' +
                    '<div class="item-botao" data-index="' + i + '">' +
                    '  <input type="text" class="campo-botao-texto" value="' + escaparHtml(textoBotao(t)) + '" placeholder="Texto do botão">' +
                    '  <button type="button" class="remover-botao" title="Remover">✕</button>' +
                    '</div>';
            }).join('');
            return '' +
                '<div class="bloco-config bloco-botoes">' +
                '  <label>Texto da mensagem</label>' +
                '  <textarea class="campo-texto-botoes" rows="2" placeholder="Digite a mensagem...">' + escaparHtml(props.texto || '') + '</textarea>' +
                '  <label>Botões</label>' +
                '  <div class="lista-botoes">' + lista + '</div>' +
                '  <button type="button" class="botao botao-claro btn-adicionar-botao">+ Adicionar botão</button>' +
                '  <div class="linha-flex"><input type="checkbox" class="campo-sumir-apos-clique"' + chk(!!props.sumir_apos_clique) + '> <label style="margin:0">Sumir após clique</label></div>' +
                '</div>';
        }
        if (tipo === 'pix') {
            const eh_recorrente = props.tipo_cobranca === 'recorrente';
            const display_recorrente = eh_recorrente ? '' : 'display:none;';
            const display_unico = !eh_recorrente ? '' : 'display:none;';
            const periodicidade_selecionada = props.periodicidade === 'semanal' ? 'mensal' : (props.periodicidade || 'mensal');

            return '' +
                '<div class="bloco-config bloco-pix">' +
                '  <div class="campo"><label>Tipo de Cobrança</label>' +
                '    <select class="campo-pix-tipo-cobranca">' +
                '      <option value="unica"' + opt(!eh_recorrente) + '>Pagamento Único</option>' +
                (gateway_suporta_recorrente ? '      <option value="recorrente"' + opt(eh_recorrente) + '>Assinatura (Recorrente)</option>' : '') +
                '    </select>' +
                (!gateway_suporta_recorrente ? '    <p style="font-size:10px;color:#f59e0b;margin-top:4px;">⚠️ PIX Recorrente disponível apenas para contas PJ. Configure o tipo de conta em <a href="gateways" target="_blank">Gateways de Pagamento</a>.</p>' : '') +
                '  </div>' +
                '  <div class="campo"><label>Nome do Produto/Plano</label><input type="text" class="campo-pix-nome" value="' + escaparHtml(props.nome || '') + '"></div>' +
                '  <div class="campo"><label>Valor (R$)</label><input type="number" step="0.01" min="0" class="campo-pix-valor" value="' + (props.valor || 0) + '"></div>' +
                '  <div class="campo"><label>Expiração do PIX (minutos)</label><input type="number" min="1" class="campo-pix-expiracao-minutos" value="' + (props.expiracao_minutos !== undefined ? props.expiracao_minutos : 15) + '"><p style="font-size:10px; color:#666; margin-top:2px;">Define a validade do código PIX e quando o fluxo segue para "NÃO PAGO".</p></div>' +
                
                '  <div class="grupo-recorrente" style="' + display_recorrente + '">' +
                '    <div class="grade grade-2 grade-compacta">' +
                '      <div class="campo"><label>Periodicidade</label>' +
                '        <select class="campo-pix-periodicidade">' +
                '          <option value="mensal"' + opt(periodicidade_selecionada === 'mensal') + '>Mensal</option>' +
                '          <option value="trimestral"' + opt(periodicidade_selecionada === 'trimestral') + '>Trimestral</option>' +
                '          <option value="semestral"' + opt(periodicidade_selecionada === 'semestral') + '>Semestral</option>' +
                '          <option value="anual"' + opt(periodicidade_selecionada === 'anual') + '>Anual</option>' +
                '        </select>' +
                '      </div>' +
                '    </div>' +
                '  </div>' +

                '  <div class="grupo-unico" style="' + display_unico + '">' +
                '      <div class="campo"><label>Tempo de Acesso</label><input type="number" min="1" class="campo-pix-dias-acesso" value="' + (props.dias_acesso || 30) + '"></div>' +
                '      <div class="campo"><label>Unidade de Tempo</label>' +
                '        <select class="campo-pix-unidade-acesso">' +
                '          <option value="dias"' + opt(!props.unidade_acesso || props.unidade_acesso === 'dias') + '>Dias</option>' +
                '          <option value="horas"' + opt(props.unidade_acesso === 'horas') + '>Horas</option>' +
                '          <option value="minutos"' + opt(props.unidade_acesso === 'minutos') + '>Minutos</option>' +
                '        </select>' +
                '      </div>' +
                '  </div>' +

                '  <div class="campo"><label>Grupo para Acesso (Opcional)</label>' +
                '    <select class="campo-pix-id-grupo">' +
                '      <option value="">Nenhum (Apenas Venda)</option>' +
                lista_grupos_usuario.map(function(g) {
                    return '<option value="' + escaparHtml(g.id_telegram) + '"' + (g.id_telegram == props.id_grupo ? ' selected' : '') + '>' + escaparHtml(g.titulo) + '</option>';
                }).join('') +
                '    </select>' +
                '    <p style="font-size:10px; color:#666; margin-top:2px;">O bot deve ser admin do grupo para gerar link e remover membros.</p>' +
                '  </div>' +

                '  <div class="linha-flex"><input type="checkbox" class="campo-pix-mostrar-copiar"' + chk(!!props.mostrar_copiar) + '> <label style="margin:0">Botão Copiar</label></div>' +
                '  <div class="linha-flex"><input type="checkbox" class="campo-pix-mostrar-qrcode"' + chk(!!props.mostrar_qrcode) + '> <label style="margin:0">Botão QR Code</label></div>' +
                '  <div class="linha-flex"><input type="checkbox" class="campo-pix-mostrar-confirmar"' + chk(!!props.mostrar_confirmar) + '> <label style="margin:0">Botão Confirmar</label></div>' +
                '  <label>Instruções</label>' +
                '  <textarea class="campo-pix-msg-instrucoes" rows="1">' + escaparHtml(props.msg_instrucoes || '') + '</textarea>' +
                '  <label>Msg Confirmação</label>' +
                '  <textarea class="campo-pix-msg-confirmado" rows="1">' + escaparHtml(props.msg_confirmado || '') + '</textarea>' +
                '</div>';
        }
        if (tipo === 'delay') {
            return '' +
                '<div class="bloco-config bloco-delay">' +
                '  <div class="campo"><label>Tempo Mínimo</label><input type="number" min="0" class="campo-delay-min" value="' + (props.delay_min || 0) + '"></div>' +
                '  <div class="campo"><label>Tempo Máximo</label><input type="number" min="0" class="campo-delay-max" value="' + (props.delay_max || 0) + '"></div>' +
                '  <div class="campo"><label>Unidade de Tempo</label><select class="campo-delay-unidade">' +
                '    <option value="seg"' + opt((props.delay_unidade || 'seg') === 'seg') + '>Segundos</option>' +
                '    <option value="min"' + opt((props.delay_unidade || '') === 'min') + '>Minutos</option>' +
                '  </select></div>' +
                '  <div class="linha-flex"><input type="checkbox" class="campo-delay-digitando"' + chk(!!props.delay_digitando) + '> <label style="margin:0">Mostrar "digitando..."</label></div>' +
                '</div>';
        }
        if (tipo === 'link') {
            return '' +
                '<div class="bloco-config bloco-link">' +
                '  <div class="campo"><label>Destino da Entrega</label>' +
                '    <select class="campo-link-destino" disabled>' +
                '      <option value="externo" selected>Link Externo</option>' +
                '    </select>' +
                '  </div>' +
                '  <label>URL</label>' +
                '  <input class="campo-link-url" type="text" value="' + escaparHtml(props.url || '') + '" placeholder="https://...">' +
                '  <label>Texto do Botão</label>' +
                '  <input class="campo-link-texto" type="text" value="' + escaparHtml(props.texto_botao || '') + '" placeholder="Acessar">' +
                '</div>';
        }
        if (tipo === 'grupo') {
            const opcoes = lista_grupos_usuario.map(function(g) {
                const sel = (g.id_telegram == props.id_grupo) ? ' selected' : '';
                return '<option value="' + escaparHtml(g.id_telegram) + '"' + sel + '>' + escaparHtml(g.titulo) + ' (@' + escaparHtml(g.nome_bot) + ')</option>';
            }).join('');
            
            return '' +
                '<div class="bloco-config bloco-grupo">' +
                '  <label>Selecione um grupo</label>' +
                '  <select class="campo-grupo-id">' +
                '    <option value="">Selecione...</option>' +
                opcoes +
                '  </select>' +
                '  <p style="font-size:10px; color:#666; margin-top:4px;">Se o grupo não aparecer, adicione o bot ao grupo novamente.</p>' +
                '  <label>Texto do Botão</label>' +
                '  <input class="campo-grupo-texto" type="text" value="' + escaparHtml(props.texto_botao || '') + '" placeholder="Entrar no Grupo">' +
                '</div>';
        }
        if (tipo === 'randomizer') {
            const caminhos = props.caminhos || [];
            const soma = caminhos.reduce(function (acc, c) { return acc + (parseFloat(c.peso) || 0); }, 0) || 1;
            const lista = caminhos.map(function (c, i) {
                const pct = Math.round(((parseFloat(c.peso) || 0) / soma) * 100);
                return '' +
                    '<div class="item-caminho" data-index="' + i + '">' +
                    '  <span class="rotulo-caminho">Caminho ' + (i + 1) + '</span>' +
                    '  <input type="number" min="0" class="campo-caminho-peso" value="' + (c.peso != null ? c.peso : 50) + '">' +
                    '  <span class="pct-caminho">' + pct + '%</span>' +
                    '  <button type="button" class="remover-caminho" title="Remover">✕</button>' +
                    '</div>';
            }).join('');
            return '' +
                '<div class="bloco-config bloco-randomizer">' +
                '  <p style="font-size:10px; color:#666; margin:0 0 6px;">Sorteia um caminho a cada execução, conforme o peso de cada um.</p>' +
                '  <div class="lista-caminhos">' + lista + '</div>' +
                '  <button type="button" class="botao botao-claro btn-adicionar-caminho">+ Adicionar caminho</button>' +
                '</div>';
        }
        if (tipo === 'upsell' || tipo === 'downsell' || tipo === 'order_bump') {
            const rotulo_valor = tipo === 'order_bump' ? 'Valor extra (R$)' : 'Desconto (%)';
            return '' +
                '<div class="bloco-config bloco-oferta" data-tipo-oferta="' + tipo + '">' +
                '  <label>Mensagem da oferta</label>' +
                '  <textarea class="campo-oferta-mensagem" rows="3" placeholder="Digite a mensagem...">' + escaparHtml(props.mensagem || '') + '</textarea>' +
                '  <label>' + rotulo_valor + '</label>' +
                '  <input type="number" step="0.01" min="0" class="campo-oferta-valor" value="' + (props.valor_extra != null ? props.valor_extra : (props.desconto_percentual != null ? props.desconto_percentual : 0)) + '">' +
                '  <div class="grade grade-2 grade-compacta">' +
                '    <div class="campo"><label>Texto (aceitar)</label><input type="text" class="campo-oferta-aceitar" value="' + escaparHtml(props.texto_aceitar || '') + '"></div>' +
                '    <div class="campo"><label>Texto (recusar)</label><input type="text" class="campo-oferta-recusar" value="' + escaparHtml(props.texto_recusar || '') + '"></div>' +
                '  </div>' +
                '</div>';
        }
        // mensagem, pergunta e ação compartilham editor simples
        const conteudo = props.conteudo != null ? props.conteudo : (props.body || '');
        return '' +
            '<div class="bloco-config">' +
            '  <label>Texto da mensagem</label>' +
            '  <textarea class="campo-conteudo" rows="4" placeholder="Digite aqui...">' + escaparHtml(conteudo || '') + '</textarea>' +
            '</div>';
    }

    function initChart() {
        $flowchart.flowchart({
            data: defaultChartData(),
            grid: 0,
            multipleLinksOnInput: true,
            multipleLinksOnOutput: false,
            canUserMoveOperators: true,
            canUserEditLinks: true,
            onOperatorCreate: function (operator_id, operator_data, full_element) {
                // Mapeamento de tipos para classes do CSS (garantia para fluxos antigos)
                const tipo = operator_data.properties.type || 'message';
                const mapa_classes = {
                    'start': 'no-inicio',
                    'message': 'no-mensagem',
                    'image': 'no-imagem',
                    'video': 'no-video',
                    'audio': 'no-audio',
                    'botoes': 'no-botoes',
                    'pix': 'no-pix',
                    'delay': 'no-delay',
                    'link': 'no-link',
                    'grupo': 'no-grupo',
                    'randomizer': 'no-randomizer',
                    'upsell': 'no-upsell',
                    'downsell': 'no-downsell',
                    'order_bump': 'no-order-bump'
                };
                const classe_extra = mapa_classes[tipo] || 'no-mensagem';
                full_element.operator.addClass(classe_extra);

                const $title = full_element.title;
                const icone_svg = block_icons[tipo] || block_icons['message'];

                const titulo_texto = operator_data.properties.title || 'Sem título';
                $title.empty();

                $title.css({
                    'display': 'flex',
                    'align-items': 'center',
                    'justify-content': 'space-between',
                    'gap': '8px'
                });

                const $esquerda = $('<div style="display:flex; align-items:center; gap:8px; overflow:hidden;"></div>');
                $esquerda.append(`<span class="icone-bloco" style="display:flex; align-items:center;">${icone_svg}</span>`);
                $esquerda.append(`<span class="texto-titulo" style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${escaparHtml(titulo_texto)}</span>`);
                $title.append($esquerda);

                const numero_passo = (operator_id.match(/(\d+)$/) || [])[1];
                if (numero_passo && tipo !== 'start') {
                    $title.append(`<span class="passo-bloco">${numero_passo.padStart(2, '0')}</span>`);
                }

                if (tipo !== 'start') {
                    const $btn_excluir = $('<button type="button" class="btn-excluir" title="Excluir">✕</button>');
                    $title.append($btn_excluir);
                }

                return true;
            },
            onOperatorSelect: function (operator_id) {
                fillOperatorForm(operator_id);
                return true;
            },
            onOperatorUnselect: function () {
                clearOperatorForm();
                return true;
            },
            onLinkSelect: function (link_id) {
                $('#btn-excluir-link').show().data('link-id', link_id);
                return true;
            },
            onLinkUnselect: function () {
                $('#btn-excluir-link').hide();
                return true;
            },
            onAfterChange: function (tipo_mudanca) {
                syncOperatorIndex();
                atualizarCabecalhoFluxo();
                // setData() ao abrir o fluxo dispara change. Sem este freio, só
                // entrar na página gravava de novo e enchia o log.
                if (suprimir_auto_salvar) return;
                if (tipo_mudanca === 'operator_delete') {
                    atualizaFluxo(true);
                } else {
                    agendarAutoSalvar();
                }
            }
        });
        syncOperatorIndex();
    }

    function getChartData() {
        return $flowchart.flowchart('getData');
    }

    function setChartData(data, agendar) {
        if (agendar === undefined) agendar = true;
        const d = data || defaultChartData();
        const ops = d.operators || {};
        const links = d.links || {};

        Object.keys(ops).forEach(function (id) {
            const props = ops[id].properties || {};

            // Migração PIX: output_1 -> output_pago + output_nao_pago
            if (props.type === 'pix') {
                props.outputs = props.outputs || {};

                if (!props.outputs.output_pago || props.outputs.output_1) {
                    props.outputs.output_pago = { label: 'PAGO' };
                    props.outputs.output_nao_pago = { label: 'NÃO PAGO' };

                    // Migra conexões existentes de output_1 para output_pago
                    if (props.outputs.output_1) {
                        Object.keys(links).forEach(function(link_id) {
                            if (links[link_id].fromOperator === id && links[link_id].fromConnector === 'output_1') {
                                links[link_id].fromConnector = 'output_pago';
                            }
                        });
                        delete props.outputs.output_1;
                    }
                }
            }

            // Garante que o body seja renderizado com o novo HTML
            props.body = renderCorpoDoBloco(props);
            ops[id].properties = props;
        });
        
        suprimir_auto_salvar = true;
        try {
            $flowchart.flowchart('setData', d);
        } finally {
            suprimir_auto_salvar = false;
        }
        syncOperatorIndex();
        if (agendar) {
            agendarAutoSalvar();
        } else {
            fluxo_sujo = false;
            clearTimeout(timer_auto_salvar);
        }
    }

    const $btn_delete_link = $('<div class="btn-delete-link-hover">✕</div>').appendTo('body');
    $btn_delete_link.css({
        'display': 'none',
        'position': 'absolute',
        'z-index': '9999',
        'background': '#ff4444',
        'color': 'white',
        'width': '20px',
        'height': '20px',
        'border-radius': '50%',
        'text-align': 'center',
        'line-height': '20px',
        'font-size': '12px',
        'cursor': 'pointer',
        'box-shadow': '0 2px 4px rgba(0,0,0,0.2)',
        'pointer-events': 'auto'
    });

    let current_link_hover = null;
    let hide_timeout = null;

    $(document).on('mouseenter', '.flowchart-link', function(e) {
        current_link_hover = $(this).data('link_id');
        clearTimeout(hide_timeout);
        $btn_delete_link.css({
            top: e.pageY - 20,
            left: e.pageX + 10
        }).show();
    });

    $(document).on('mousemove', '.flowchart-link', function(e) {
        if (!$btn_delete_link.is(':hover')) {
            $btn_delete_link.css({
                top: e.pageY - 20,
                left: e.pageX + 10
            });
        }
    });

    $(document).on('mouseleave', '.flowchart-link', function() {
        hide_timeout = setTimeout(function() {
            if (!$btn_delete_link.is(':hover')) {
                $btn_delete_link.hide();
            }
        }, 100);
    });

    $btn_delete_link.on('mouseenter', function() {
        clearTimeout(hide_timeout);
    });

    $btn_delete_link.on('mouseleave', function() {
        $(this).hide();
    });

    $btn_delete_link.on('click', function(e) {
        e.stopPropagation();
        e.preventDefault();
        if (current_link_hover != null) {
            $flowchart.flowchart('deleteLink', current_link_hover);
            atualizaFluxo(true);
            $btn_delete_link.hide();
        }
    });

    $(document).keydown(function(e) {
        if (e.key === 'Delete' || e.key === 'Backspace') {
            if ($(e.target).is('input, textarea')) return;
            const link_id = $flowchart.flowchart('getSelectedLinkId');
            if (link_id) {
                e.preventDefault();
                $flowchart.flowchart('deleteLink', link_id);
                atualizaFluxo(true);
                $btn_delete_link.hide();
            }
        }
    });

    function syncOperatorIndex() {
        const data = getChartData();
        const ids = Object.keys(data.operators || {});
        let max = 0;
        ids.forEach(function (id) {
            const match = id.match(/(\d+)$/);
            if (match) {
                max = Math.max(max, parseInt(match[1], 10));
            }
        });
        operator_index = max + 1;
    }

    function nodeTemplate(type) {
        const base = {
            top: 80 + (operator_index * 20),
            left: 80 + (operator_index * 20),
            properties: {
                title: 'Novo bloco',
                body: 'Conteúdo do bloco',
                type: type,
                inputs: {
                    input_1: { label: 'Entrada' }
                },
                outputs: {
                    output_1: { label: 'Saída' }
                },
                class: 'no-mensagem'
            }
        };

        if (type === 'start') {
            base.properties.title = 'Início';
            base.properties.body = 'Primeiro passo do fluxo';
            base.properties.inputs = {};
            base.properties.outputs = { output_1: { label: 'Próximo' } };
            base.properties.class = 'no-inicio';
        }

        if (type === 'message') {
            base.properties.title = 'Mensagem';
            base.properties.conteudo = '';
            base.properties.class = 'no-mensagem';
        }

        if (type === 'image') {
            base.properties.title = 'Imagem';
            base.properties.class = 'no-imagem';
            base.properties.image_path = '';
            base.properties.caption = '';
            base.properties.mode = 'foto';
            base.properties.spoiler = false;
            base.properties.auto_delete = false;
            base.properties.auto_delete_seconds = 0;
        }
        if (type === 'video') {
            base.properties.title = 'Vídeo';
            base.properties.class = 'no-video';
            base.properties.video_path = '';
            base.properties.caption = '';
            base.properties.spoiler = false;
            base.properties.auto_delete = false;
            base.properties.auto_delete_seconds = 0;
        }
        if (type === 'audio') {
            base.properties.title = 'Áudio';
            base.properties.class = 'no-audio';
            base.properties.audio_path = '';
            base.properties.caption = '';
            base.properties.auto_delete = false;
            base.properties.auto_delete_seconds = 0;
        }
        if (type === 'botoes') {
            base.properties.title = 'Botões';
            base.properties.class = 'no-botoes';
            base.properties.texto = 'Escolha uma opção:';
            base.properties.botoes = ['Opção 1', 'Opção 2'];
            base.properties.sumir_apos_clique = false;
            base.properties.outputs = {};
            base.properties.botoes.forEach((btn, idx) => {
                base.properties.outputs['output_' + idx] = { label: btn };
            });
        }
        if (type === 'pix') {
            base.properties.title = 'PIX';
            base.properties.class = 'no-pix';
            base.properties.nome = 'Produto';
            base.properties.valor = 10.00;
            base.properties.tipo_cobranca = 'unica'; // unica, recorrente
            base.properties.periodicidade = 'mensal'; // mensal, trimestral, semestral, anual.
            base.properties.expiracao_minutos = 15;
            base.properties.mostrar_copiar = true;
            base.properties.mostrar_qrcode = true;
            base.properties.mostrar_confirmar = true;
            base.properties.msg_instrucoes = 'Copie e pague.';
            base.properties.msg_confirmado = 'Recebido!';
            base.properties.outputs = {
                output_pago: { label: 'PAGO' },
                output_nao_pago: { label: 'NÃO PAGO' }
            };
        }
        if (type === 'delay') {
            base.properties.title = 'Delay';
            base.properties.class = 'no-delay';
            base.properties.delay_min = 1;
            base.properties.delay_max = 2;
            base.properties.delay_unidade = 'seg';
            base.properties.delay_digitando = true;
        }
        if (type === 'link') {
            base.properties.title = 'Entrega';
            base.properties.class = 'no-link';
            base.properties.url = '';
            base.properties.texto_botao = 'Acessar';
        }
        if (type === 'grupo') {
            base.properties.title = 'Grupo';
            base.properties.class = 'no-grupo';
            base.properties.id_grupo = '';
            base.properties.texto_botao = 'Entrar no Grupo';
        }
        if (type === 'randomizer') {
            base.properties.title = 'Sorteio';
            base.properties.class = 'no-randomizer';
            base.properties.caminhos = [{ peso: 50 }, { peso: 50 }];
            base.properties.outputs = {
                output_path_0: { label: 'Caminho 1' },
                output_path_1: { label: 'Caminho 2' }
            };
        }
        if (type === 'upsell' || type === 'downsell' || type === 'order_bump') {
            const rotulos = { upsell: 'Oferta extra', downsell: 'Oferta menor', order_bump: 'Extra no pagamento' };
            base.properties.title = rotulos[type];
            base.properties.class = 'no-' + type.replace('_', '-');
            base.properties.mensagem = '';
            base.properties.desconto_percentual = 0;
            base.properties.valor_extra = 0;
            base.properties.texto_aceitar = type === 'order_bump' ? 'Sim, adicionar' : 'Sim, quero! 🔥';
            base.properties.texto_recusar = 'Não, obrigado';
            base.properties.outputs = {
                output_aceito: { label: 'ACEITO' },
                output_recusado: { label: 'RECUSADO' }
            };
        }

        base.properties.body = renderCorpoDoBloco(base.properties);
        return base;
    }

    function addNode(type, position) {
        const id = 'operator_' + operator_index;
        const template = nodeTemplate(type);
        
        if (position) {
            template.left = position.left;
            template.top = position.top;
        }
        
        $flowchart.flowchart('createOperator', id, template);
        $flowchart.flowchart('selectOperator', id);
        syncOperatorIndex();
        agendarAutoSalvar();
    }

    function fillOperatorForm(operator_id) {
        // Form lateral removido/escondido na nova UI focada nos blocos
        // Mantido vazio para compatibilidade se reativar a barra lateral
    }

    function clearOperatorForm() {
    }

    function centralizarVisao() {
        const data = getChartData();
        if (!data || !data.operators) return;
        
        let target_op = data.operators['operator_1'];
        if (!target_op) {
            const keys = Object.keys(data.operators);
            if (keys.length > 0) target_op = data.operators[keys[0]];
        }
        
        if (target_op) {
            const $wrapper = $('.conteiner-fluxo');
            const wrapper_width = $wrapper.width() || 800;
            const wrapper_height = $wrapper.height() || 600;
            
            // Centraliza: Posição do bloco - metade da tela
            // Assumindo bloco ~250px largura, 100px altura
            const s_left = (target_op.left * zoom_level) - (wrapper_width / 2) + 125;
            const s_top = (target_op.top * zoom_level) - (wrapper_height / 2) + 50;

            $wrapper.animate({
                scrollLeft: Math.max(0, s_left),
                scrollTop: Math.max(0, s_top)
            }, 500);
        }
    }

    function newFlow() {
        current_flow = null;
        $('#id-fluxo').val('');
        $('#nome-fluxo').val('Novo fluxo');
        $('#descricao-fluxo').val('');
        $('#link-suporte-fluxo').val('');
        setChartData(defaultChartData(), false);
        if (window.history.pushState) {
            const new_url = window.location.protocol + "//" + window.location.host + window.location.pathname;
            window.history.pushState({path:new_url},'',new_url);
        }
        ultimo_salvo_em = null;
        atualizarCabecalhoFluxo();
        setTimeout(centralizarVisao, 100);
    }

    function openFlow(id) {
        $.getJSON(api_url + '?action=obter_fluxo&id=' + encodeURIComponent(id))
            .done(function (response) {
                if (!response.sucesso) {
                    showToast(response.mensagem || 'Fluxo não encontrado.', 'erro');
                    return;
                }

                current_flow = response.fluxo;
                $('#id-fluxo').val(current_flow.id || '');
                $('#nome-fluxo').val(current_flow.nome || '');
                $('#descricao-fluxo').val(current_flow.descricao || '');
                $('#link-suporte-fluxo').val(current_flow.link_suporte || '');

                if (window.history.pushState) {
                    const new_url = window.location.pathname + '?id=' + current_flow.id;
                    window.history.pushState({path:new_url},'',new_url);
                }

                setChartData(current_flow.dados_fluxograma || defaultChartData(), false);
                ultimo_salvo_em = current_flow.atualizado_em ? new Date(String(current_flow.atualizado_em).replace(' ', 'T')) : new Date();
                atualizarCabecalhoFluxo();
                setTimeout(centralizarVisao, 100);
            })
            .fail(function () {
                showToast('Não foi possível abrir o fluxo.', 'erro');
            });
    }

    function fluxogramaJsonParaBase64Utf8(obj) {
        const json = JSON.stringify(obj);
        const bytes = new TextEncoder().encode(json);
        let bin = '';
        for (let i = 0; i < bytes.length; i++) {
            bin += String.fromCharCode(bytes[i]);
        }
        return btoa(bin);
    }

    function atualizaFluxo(silencioso) {
        const dados_fluxo = {
            id: $('#id-fluxo').val(),
            nome: $('#nome-fluxo').val().trim() || 'Novo fluxo',
            descricao: $('#descricao-fluxo').val().trim(),
            link_suporte: $('#link-suporte-fluxo').val().trim(),
            dados_fluxograma_b64: fluxogramaJsonParaBase64Utf8(getChartData())
        };

        $.ajax({
            url: api_url + '?action=salvar_fluxo',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(dados_fluxo)
        }).done(function (response) {
            if (!response.sucesso) {
                showToast(response.mensagem || 'Erro ao salvar fluxo.', 'erro');
                return;
            }
            current_flow = response.fluxo;
            $('#id-fluxo').val(current_flow.id || '');
            fluxo_sujo = false;
            ultimo_salvo_em = new Date();
            atualizarCabecalhoFluxo();
            if (!silencioso) {
                showToast(response.mensagem || 'Fluxo salvo com sucesso.');
            }

            if (!dados_fluxo.id && response.fluxo && response.fluxo.id) {
                const new_url = window.location.pathname + '?id=' + response.fluxo.id;
                window.history.pushState({path:new_url},'',new_url);
            }
        }).fail(function (xhr) {
            let msg = (xhr.responseJSON && xhr.responseJSON.mensagem) || '';
            if (!msg && xhr.status === 403) {
                msg = 'Servidor recusou o salvamento (403). Firewall da hospedagem pode estar bloqueando; tente de novo após atualizar os arquivos ou peça liberação em api.php.';
            }
            showToast(msg || 'Erro ao salvar fluxo.', 'erro');
        });
    }

    function deleteFlow() {
        const id = $('#id-fluxo').val();
        if (!id) {
            showToast('Salve o fluxo antes de excluir.', 'erro');
            return;
        }

        if (!window.confirm('Deseja excluir este fluxo?')) {
            return;
        }

        $.ajax({
            url: api_url + '?action=excluir_fluxo',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ id: id })
        }).done(function (response) {
            if (!response.sucesso) {
                showToast(response.mensagem || 'Erro ao excluir fluxo.', 'erro');
                return;
            }
            window.location.href = 'fluxos';
        }).fail(function (xhr) {
            showToast((xhr.responseJSON && xhr.responseJSON.mensagem) || 'Erro ao excluir fluxo.', 'erro');
        });
    }

    $(document).off('click', '.adicionar-no'); // Remove clique direto para adicionar

    function setZoom(scale) {
        zoom_level = Math.min(Math.max(0.2, scale), 3); // Limites 20% a 300%
        $flowchart.css({
            'transform': `scale(${zoom_level})`,
            'transform-origin': '0 0'
        });
        $flowchart.flowchart('setPositionRatio', zoom_level);
        $('#btn-zoom-reset').text(Math.round(zoom_level * 100) + '%');
    }

    const container_fluxo = document.querySelector('.conteiner-fluxo');
    if (container_fluxo) {
        container_fluxo.addEventListener('wheel', function(e) {
            if (e.ctrlKey) {
                e.preventDefault();
                const delta = e.deltaY;
                const step = 0.1;
                if (delta > 0) {
                    setZoom(zoom_level - step);
                } else {
                    setZoom(zoom_level + step);
                }
            }
        }, { passive: false });
    }

    $(document).off('click', '#btn-zoom-in').on('click', '#btn-zoom-in', function() { setZoom(zoom_level + 0.1); });
    $(document).off('click', '#btn-zoom-out').on('click', '#btn-zoom-out', function() { setZoom(zoom_level - 0.1); });
    $(document).off('click', '#btn-zoom-reset').on('click', '#btn-zoom-reset', function() { setZoom(1); });
    $(document).off('click', '#btn-zoom-fit').on('click', '#btn-zoom-fit', function() { setZoom(1); centralizarVisao(); });

    $('#btn-toggle-flow-meta').on('click', function () {
        const $secao = $('#secao-detalhes-fluxo');
        const abrindo = $secao.attr('hidden') !== undefined;
        if (abrindo) {
            $secao.removeAttr('hidden');
        } else {
            $secao.attr('hidden', true);
        }
        $('#chevron-flow-meta').toggleClass('aberto', abrindo);
        $('#texto-hint-flow-meta').text(abrindo ? 'Ocultar detalhes' : 'Nome e descrição');
    });
    $('#nome-fluxo').on('input', function () {
        $('#texto-nome-fluxo-colapsado').text($(this).val().trim() || 'Novo fluxo');
    });

    $('#btn-salvar-fluxo').on('click', function () { atualizaFluxo(false); });
    $('#btn-excluir-fluxo').on('click', deleteFlow);
    $('#btn-exportar-fluxo').on('click', function () {
        const id = $('#id-fluxo').val();
        if (!id) {
            showToast('Salve o fluxo antes de exportar.', 'erro');
            return;
        }
        $.getJSON(api_url + '?action=exportar_fluxo&id=' + encodeURIComponent(id))
            .done(function(resp) {
                if (!resp.sucesso || !resp.fluxo) {
                    showToast(resp.mensagem || 'Erro ao exportar fluxo.', 'erro');
                    return;
                }
                const conteudo = JSON.stringify(resp.fluxo, null, 2);
                const blob = new Blob([conteudo], { type: 'application/json' });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = 'fluxo_' + id + '.json';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);
                showToast('Fluxo exportado.');
            })
            .fail(function() {
                showToast('Falha ao exportar fluxo.', 'erro');
            });
    });
    $('#btn-importar-fluxo').on('click', function () {
        $('#arquivo-importar-fluxo').click();
    });
    $('#arquivo-importar-fluxo').on('change', function (e) {
        const arquivo = e.target.files && e.target.files[0];
        if (!arquivo) return;
        const leitor = new FileReader();
        leitor.onload = function (ev) {
            let json;
            try {
                json = JSON.parse(ev.target.result);
            } catch (err) {
                showToast('Arquivo JSON inválido.', 'erro');
                return;
            }
            $.ajax({
                url: api_url + '?action=importar_fluxo',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    nome: json.nome,
                    descricao: json.descricao,
                    link_suporte: json.link_suporte || '',
                    dados_fluxograma_b64: fluxogramaJsonParaBase64Utf8(json.dados_fluxograma)
                })
            }).done(function (resp) {
                if (!resp.sucesso || !resp.fluxo) {
                    showToast(resp.mensagem || 'Erro ao importar fluxo.', 'erro');
                    return;
                }
                current_flow = resp.fluxo;
                $('#id-fluxo').val(current_flow.id || '');
                $('#nome-fluxo').val(current_flow.nome || '');
                $('#descricao-fluxo').val(current_flow.descricao || '');
                $('#link-suporte-fluxo').val(current_flow.link_suporte || '');
                setChartData(current_flow.dados_fluxograma || defaultChartData(), false);
                showToast('Fluxo importado com sucesso.');
                if (window.history.pushState && current_flow.id) {
                    const new_url = window.location.pathname + '?id=' + current_flow.id;
                    window.history.pushState({path:new_url},'',new_url);
                }
            }).fail(function (xhr) {
                showToast((xhr.responseJSON && xhr.responseJSON.mensagem) || 'Falha ao importar fluxo.', 'erro');
            });
        };
        leitor.readAsText(arquivo);
        $(this).val('');
    });

    $flowchart.on('change', '.campo-conteudo', function () {
        const $op = $(this).closest('.flowchart-operator');
        const id = $op.data('operator_id');
        const texto = $(this).val();
        const data = getChartData();
        if (data.operators[id]) {
            data.operators[id].properties.conteudo = texto;
            setChartData(data);
            $flowchart.flowchart('selectOperator', id);
            agendarAutoSalvar();
        }
    });
    $flowchart.on('change', '.campo-legenda, .campo-spoiler, .campo-auto-deletar, .campo-auto-segundos', function () {
        const $op = $(this).closest('.flowchart-operator');
        const id = $op.data('operator_id');
        const data = getChartData();
        const props = data.operators[id] && data.operators[id].properties;
        if (!props || !['image', 'video', 'audio'].includes(props.type)) return;
        
        props.caption = $op.find('.campo-legenda').val().trim();
        if (props.type === 'image') props.mode = 'foto';

        if (props.type !== 'audio') {
            props.spoiler = $op.find('.campo-spoiler').is(':checked');
        }
        
        props.auto_delete = $op.find('.campo-auto-deletar').is(':checked');
        if (props.auto_delete) {
            $op.find('.grupo-auto-delete').show();
            props.auto_delete_seconds = parseInt($op.find('.campo-auto-segundos').val(), 10) || 0;
        } else {
            $op.find('.grupo-auto-delete').hide();
            props.auto_delete_seconds = 0;
        }
        props.body = renderCorpoDoBloco(props);
        $flowchart.flowchart('setOperatorBody', id, props.body);
        setChartData(data);
        $flowchart.flowchart('selectOperator', id);
        agendarAutoSalvar();
    });
    $flowchart.on('change', '.bloco-botoes .campo-texto-botoes', function () {
        const $op = $(this).closest('.flowchart-operator');
        const id = $op.data('operator_id');
        const data = getChartData();
        const props = data.operators[id] && data.operators[id].properties;
        if (!props || props.type !== 'botoes') return;
        props.texto = $(this).val();
        props.body = renderCorpoDoBloco(props);
        $flowchart.flowchart('setOperatorBody', id, props.body);
        setChartData(data);
        $flowchart.flowchart('selectOperator', id);
        agendarAutoSalvar();
    });
    $flowchart.on('click', '.bloco-botoes .btn-adicionar-botao', function () {
        const $op = $(this).closest('.flowchart-operator');
        const id = $op.data('operator_id');
        const data = getChartData();
        const props = data.operators[id] && data.operators[id].properties;
        if (!props || props.type !== 'botoes') return;
        props.botoes = props.botoes || [];
        const novo_nome = 'Novo botão';
        props.botoes.push(novo_nome);

        props.outputs = props.outputs || {};
        props.outputs['output_' + (props.botoes.length - 1)] = { label: novo_nome };

        props.body = renderCorpoDoBloco(props);
        $flowchart.flowchart('setOperatorBody', id, props.body);

        // É necessário setar os dados completos para recriar os conectores
        setChartData(data);
        $flowchart.flowchart('selectOperator', id);
        agendarAutoSalvar();
    });
    $flowchart.on('click', '.bloco-botoes .remover-botao', function () {
        const $item = $(this).closest('.item-botao');
        const idx = parseInt($item.data('index'), 10);
        const $op = $(this).closest('.flowchart-operator');
        const id = $op.data('operator_id');
        const data = getChartData();
        const props = data.operators[id] && data.operators[id].properties;
        if (!props || props.type !== 'botoes') return;

        props.botoes = (props.botoes || []).filter(function (_t, i) { return i !== idx; });

        props.outputs = {};
        props.botoes.forEach((btn, i) => {
            props.outputs['output_' + i] = { label: textoBotao(btn) };
        });

        props.body = renderCorpoDoBloco(props);
        $flowchart.flowchart('setOperatorBody', id, props.body);
        setChartData(data);
        $flowchart.flowchart('selectOperator', id);
        agendarAutoSalvar();
    });
    $flowchart.on('change', '.bloco-botoes .campo-botao-texto', function () {
        const $item = $(this).closest('.item-botao');
        const idx = parseInt($item.data('index'), 10);
        const $op = $(this).closest('.flowchart-operator');
        const id = $op.data('operator_id');
        const data = getChartData();
        const props = data.operators[id] && data.operators[id].properties;
        if (!props || props.type !== 'botoes') return;
        props.botoes = props.botoes || [];
        if (idx >= 0 && idx < props.botoes.length) {
            const novo_texto = $(this).val();
            props.botoes[idx] = novo_texto;
            if (props.outputs && props.outputs['output_' + idx]) {
                props.outputs['output_' + idx].label = novo_texto;
            }
        }
        props.body = renderCorpoDoBloco(props);
        $flowchart.flowchart('setOperatorBody', id, props.body);
        setChartData(data);
        $flowchart.flowchart('selectOperator', id);
        agendarAutoSalvar();
    });
    $flowchart.on('change', '.bloco-botoes .campo-sumir-apos-clique', function () {
        const $op = $(this).closest('.flowchart-operator');
        const id = $op.data('operator_id');
        const data = getChartData();
        const props = data.operators[id] && data.operators[id].properties;
        if (!props || props.type !== 'botoes') return;
        props.sumir_apos_clique = $(this).is(':checked');
        props.body = renderCorpoDoBloco(props);
        $flowchart.flowchart('setOperatorBody', id, props.body);
        setChartData(data);
        $flowchart.flowchart('selectOperator', id);
        agendarAutoSalvar();
    });
    $flowchart.on('change', '.bloco-pix .campo-pix-nome, .bloco-pix .campo-pix-valor, .bloco-pix .campo-pix-expiracao-minutos, .bloco-pix .campo-pix-dias-acesso, .bloco-pix .campo-pix-unidade-acesso, .bloco-pix .campo-pix-id-grupo, .bloco-pix .campo-pix-msg-instrucoes, .bloco-pix .campo-pix-msg-confirmado, .bloco-pix .campo-pix-mostrar-copiar, .bloco-pix .campo-pix-mostrar-qrcode, .bloco-pix .campo-pix-mostrar-confirmar, .bloco-pix .campo-pix-tipo-cobranca, .bloco-pix .campo-pix-periodicidade', function () {
        const $op = $(this).closest('.flowchart-operator');
        const id = $op.data('operator_id');
        const data = getChartData();
        const props = data.operators[id] && data.operators[id].properties;
        if (!props || props.type !== 'pix') return;
        props.nome = $op.find('.campo-pix-nome').val().trim();
        props.valor = parseFloat($op.find('.campo-pix-valor').val()) || 0;
        props.expiracao_minutos = parseInt($op.find('.campo-pix-expiracao-minutos').val(), 10) || 15;
        // Sincroniza tempo_nao_pago com a expiração do PIX
        props.tempo_nao_pago = props.expiracao_minutos;
        props.dias_acesso = parseInt($op.find('.campo-pix-dias-acesso').val(), 10) || 30;
        props.unidade_acesso = $op.find('.campo-pix-unidade-acesso').val();
        props.id_grupo = $op.find('.campo-pix-id-grupo').val();

        props.outputs = props.outputs || {};
        if (!props.outputs.output_pago) props.outputs.output_pago = { label: 'PAGO' };
        if (!props.outputs.output_nao_pago) props.outputs.output_nao_pago = { label: 'NÃO PAGO' };
        
        props.tipo_cobranca = $op.find('.campo-pix-tipo-cobranca').val();
        props.periodicidade = $op.find('.campo-pix-periodicidade').val();
        props.mostrar_copiar = $op.find('.campo-pix-mostrar-copiar').is(':checked');
        props.mostrar_qrcode = $op.find('.campo-pix-mostrar-qrcode').is(':checked');
        props.mostrar_confirmar = $op.find('.campo-pix-mostrar-confirmar').is(':checked');
        props.msg_instrucoes = $op.find('.campo-pix-msg-instrucoes').val();
        props.msg_confirmado = $op.find('.campo-pix-msg-confirmado').val();
        props.body = renderCorpoDoBloco(props);
        $flowchart.flowchart('setOperatorBody', id, props.body);
        setChartData(data);
        $flowchart.flowchart('selectOperator', id);
        agendarAutoSalvar();
    });
    $flowchart.on('change', '.bloco-delay .campo-delay-min, .bloco-delay .campo-delay-max, .bloco-delay .campo-delay-unidade, .bloco-delay .campo-delay-digitando', function () {
        const $op = $(this).closest('.flowchart-operator');
        const id = $op.data('operator_id');
        const data = getChartData();
        const props = data.operators[id] && data.operators[id].properties;
        if (!props || props.type !== 'delay') return;
        props.delay_min = parseInt($op.find('.campo-delay-min').val(), 10) || 0;
        props.delay_max = parseInt($op.find('.campo-delay-max').val(), 10) || 0;
        props.delay_unidade = $op.find('.campo-delay-unidade').val();
        props.delay_digitando = $op.find('.campo-delay-digitando').is(':checked');
        props.body = renderCorpoDoBloco(props);
        $flowchart.flowchart('setOperatorBody', id, props.body);
        setChartData(data);
        $flowchart.flowchart('selectOperator', id);
        agendarAutoSalvar();
    });
    $flowchart.on('click', '.bloco-imagem .area-previa', function () {
        const $op = $(this).closest('.flowchart-operator');
        const input = $op.find('.campo-imagem-arquivo').get(0);
        if (input) input.click();
    });
    $flowchart.on('click', '.bloco-video .area-previa', function () {
        const $op = $(this).closest('.flowchart-operator');
        const input = $op.find('.campo-video-arquivo').get(0);
        if (input) input.click();
    });
    $flowchart.on('click', '.bloco-audio .area-previa', function () {
        const $op = $(this).closest('.flowchart-operator');
        const input = $op.find('.campo-audio-arquivo').get(0);
        if (input) input.click();
    });
    $flowchart.on('change', '.bloco-link .campo-link-url, .bloco-link .campo-link-texto', function () {
        const $op = $(this).closest('.flowchart-operator');
        const id = $op.data('operator_id');
        const data = getChartData();
        const props = data.operators[id] && data.operators[id].properties;
        if (!props || props.type !== 'link') return;
        props.url = $op.find('.campo-link-url').val();
        props.texto_botao = $op.find('.campo-link-texto').val();
        props.body = renderCorpoDoBloco(props);
        $flowchart.flowchart('setOperatorBody', id, props.body);
        setChartData(data);
        $flowchart.flowchart('selectOperator', id);
        agendarAutoSalvar();
    });
    $flowchart.on('change', '.bloco-grupo .campo-grupo-id, .bloco-grupo .campo-grupo-texto', function () {
        const $op = $(this).closest('.flowchart-operator');
        const id = $op.data('operator_id');
        const data = getChartData();
        const props = data.operators[id] && data.operators[id].properties;
        if (!props || props.type !== 'grupo') return;
        props.id_grupo = $op.find('.campo-grupo-id').val();
        props.texto_botao = $op.find('.campo-grupo-texto').val();
        props.body = renderCorpoDoBloco(props);
        $flowchart.flowchart('setOperatorBody', id, props.body);
        setChartData(data);
        $flowchart.flowchart('selectOperator', id);
        agendarAutoSalvar();
    });
    function sincronizarOutputsCaminhos(props) {
        props.outputs = {};
        (props.caminhos || []).forEach(function (c, i) {
            props.outputs['output_path_' + i] = { label: 'Caminho ' + (i + 1) };
        });
    }
    $flowchart.on('change', '.bloco-randomizer .campo-caminho-peso', function () {
        const $item = $(this).closest('.item-caminho');
        const idx = parseInt($item.data('index'), 10);
        const $op = $(this).closest('.flowchart-operator');
        const id = $op.data('operator_id');
        const data = getChartData();
        const props = data.operators[id] && data.operators[id].properties;
        if (!props || props.type !== 'randomizer') return;
        props.caminhos = props.caminhos || [];
        if (idx >= 0 && idx < props.caminhos.length) {
            props.caminhos[idx].peso = Math.max(0, parseFloat($(this).val()) || 0);
        }
        props.body = renderCorpoDoBloco(props);
        $flowchart.flowchart('setOperatorBody', id, props.body);
        setChartData(data);
        $flowchart.flowchart('selectOperator', id);
        agendarAutoSalvar();
    });
    $flowchart.on('click', '.bloco-randomizer .btn-adicionar-caminho', function () {
        const $op = $(this).closest('.flowchart-operator');
        const id = $op.data('operator_id');
        const data = getChartData();
        const props = data.operators[id] && data.operators[id].properties;
        if (!props || props.type !== 'randomizer') return;
        props.caminhos = props.caminhos || [];
        props.caminhos.push({ peso: 50 });
        sincronizarOutputsCaminhos(props);
        props.body = renderCorpoDoBloco(props);
        $flowchart.flowchart('setOperatorBody', id, props.body);
        setChartData(data);
        $flowchart.flowchart('selectOperator', id);
        agendarAutoSalvar();
    });
    $flowchart.on('click', '.bloco-randomizer .remover-caminho', function () {
        const $item = $(this).closest('.item-caminho');
        const idx = parseInt($item.data('index'), 10);
        const $op = $(this).closest('.flowchart-operator');
        const id = $op.data('operator_id');
        const data = getChartData();
        const props = data.operators[id] && data.operators[id].properties;
        if (!props || props.type !== 'randomizer') return;
        if ((props.caminhos || []).length <= 2) {
            showToast('O randomizer precisa de pelo menos 2 caminhos.', 'erro');
            return;
        }
        props.caminhos = props.caminhos.filter(function (_c, i) { return i !== idx; });
        sincronizarOutputsCaminhos(props);
        props.body = renderCorpoDoBloco(props);
        $flowchart.flowchart('setOperatorBody', id, props.body);
        setChartData(data);
        $flowchart.flowchart('selectOperator', id);
        agendarAutoSalvar();
    });
    $flowchart.on('change', '.bloco-oferta .campo-oferta-mensagem, .bloco-oferta .campo-oferta-valor, .bloco-oferta .campo-oferta-aceitar, .bloco-oferta .campo-oferta-recusar', function () {
        const $op = $(this).closest('.flowchart-operator');
        const id = $op.data('operator_id');
        const data = getChartData();
        const props = data.operators[id] && data.operators[id].properties;
        if (!props || !['upsell', 'downsell', 'order_bump'].includes(props.type)) return;
        props.mensagem = $op.find('.campo-oferta-mensagem').val();
        const valor = Math.max(0, parseFloat($op.find('.campo-oferta-valor').val()) || 0);
        if (props.type === 'order_bump') {
            props.valor_extra = valor;
        } else {
            props.desconto_percentual = valor;
        }
        props.texto_aceitar = $op.find('.campo-oferta-aceitar').val();
        props.texto_recusar = $op.find('.campo-oferta-recusar').val();
        props.body = renderCorpoDoBloco(props);
        $flowchart.flowchart('setOperatorBody', id, props.body);
        setChartData(data);
        $flowchart.flowchart('selectOperator', id);
        agendarAutoSalvar();
    });
    $flowchart.on('change', '.campo-imagem-arquivo', function () {
        const $op = $(this).closest('.flowchart-operator');
        const id = $op.data('operator_id');
        const data = getChartData();
        const props = data.operators[id] && data.operators[id].properties;
        if (!props || props.type !== 'image') {
            showToast('Selecione um bloco de imagem.', 'erro');
            return;
        }
        const input = this;
        if (!input.files || !input.files[0]) return;
        const fd = new FormData();
        fd.append('image', input.files[0]);
        $.ajax({
            url: api_url + '?action=upload_imagem_fluxo',
            method: 'POST',
            data: fd,
            processData: false,
            contentType: false
        }).done(function (response) {
            if (!response.sucesso) {
                showToast(response.mensagem || 'Erro ao enviar imagem.', 'erro');
                return;
            }
            const path = response.caminho || '';
            props.image_path = path;
            props.body = renderCorpoDoBloco(props);
            $flowchart.flowchart('setOperatorBody', id, props.body);
            setChartData(data);
            $flowchart.flowchart('selectOperator', id);
            showToast('Imagem anexada ao bloco.');
            agendarAutoSalvar();
        }).fail(function (xhr) {
            showToast((xhr.responseJSON && xhr.responseJSON.mensagem) || 'Erro ao enviar imagem.', 'erro');
        });
    });

    $flowchart.on('change', '.campo-video-arquivo', function () {
        const $op = $(this).closest('.flowchart-operator');
        const id = $op.data('operator_id');
        const data = getChartData();
        const props = data.operators[id] && data.operators[id].properties;
        if (!props || props.type !== 'video') {
            showToast('Selecione um bloco de vídeo.', 'erro');
            return;
        }
        const input = this;
        if (!input.files || !input.files[0]) return;
        const fd = new FormData();
        fd.append('video', input.files[0]);
        $.ajax({
            url: api_url + '?action=upload_video_fluxo',
            method: 'POST',
            data: fd,
            processData: false,
            contentType: false
        }).done(function (response) {
            if (!response.sucesso) {
                showToast(response.mensagem || 'Erro ao enviar vídeo.', 'erro');
                return;
            }
            const path = response.caminho || '';
            props.video_path = path;
            props.body = renderCorpoDoBloco(props);
            $flowchart.flowchart('setOperatorBody', id, props.body);
            setChartData(data);
            $flowchart.flowchart('selectOperator', id);
            showToast('Vídeo anexado ao bloco.');
            agendarAutoSalvar();
        }).fail(function (xhr) {
            showToast((xhr.responseJSON && xhr.responseJSON.mensagem) || 'Erro ao enviar vídeo.', 'erro');
        });
    });

    $flowchart.on('change', '.campo-audio-arquivo', function () {
        const $op = $(this).closest('.flowchart-operator');
        const id = $op.data('operator_id');
        const data = getChartData();
        const props = data.operators[id] && data.operators[id].properties;
        if (!props || props.type !== 'audio') {
            showToast('Selecione um bloco de áudio.', 'erro');
            return;
        }
        const input = this;
        if (!input.files || !input.files[0]) return;
        const fd = new FormData();
        fd.append('audio', input.files[0]);
        $.ajax({
            url: api_url + '?action=upload_audio_fluxo',
            method: 'POST',
            data: fd,
            processData: false,
            contentType: false
        }).done(function (response) {
            if (!response.sucesso) {
                showToast(response.mensagem || 'Erro ao enviar áudio.', 'erro');
                return;
            }
            const path = response.caminho || '';
            props.audio_path = path;
            props.body = renderCorpoDoBloco(props);
            $flowchart.flowchart('setOperatorBody', id, props.body);
            setChartData(data);
            $flowchart.flowchart('selectOperator', id);
            showToast('Áudio anexado ao bloco.');
            agendarAutoSalvar();
        }).fail(function (xhr) {
            showToast((xhr.responseJSON && xhr.responseJSON.mensagem) || 'Erro ao enviar áudio.', 'erro');
        });
    });

    $(function () {
        // Carrega grupos antes de iniciar o gráfico para popular os selects
        $.when(carregarGruposUsuario(), carregarGatewayInfo()).always(function() {
            initChart();

            $('.adicionar-no').attr('draggable', 'true').on('dragstart', function(e) {
                e.originalEvent.dataTransfer.setData('node-type', $(this).data('node-type'));
                $(this).css('opacity', '0.5');
            }).on('dragend', function() {
                $(this).css('opacity', '1');
            });

            // ===== Suporte a toque (celular/tablet) =====
            // 1) Adicionar bloco: o caminho normal é drag-and-drop HTML5, que não dispara em
            //    toque. No touch, o toque simples adiciona o bloco no canto visível do canvas,
            //    em cascata pra não empilhar um em cima do outro.
            const tem_toque = window.matchMedia('(pointer: coarse)').matches || 'ontouchstart' in window;
            if (tem_toque) {
                let cascata = 0;
                $('.adicionar-no').on('click', function (e) {
                    e.preventDefault();
                    const type = $(this).data('node-type');
                    if (!type) return;
                    const wrapper = $('.conteiner-fluxo');
                    const x = (wrapper.scrollLeft() + 24) / zoom_level + (cascata % 5) * 22;
                    const y = (wrapper.scrollTop() + 24) / zoom_level + (cascata % 5) * 22;
                    cascata++;
                    addNode(type, { left: Math.max(10, x), top: Math.max(10, y) });
                });

                // 2) Mover bloco: o jQuery UI draggable só escuta eventos de mouse. Esta ponte
                //    traduz o toque em mousedown/mousemove/mouseup apenas em cima da alça de
                //    arraste do bloco -- não toca em nada do desktop (esses eventos de toque
                //    simplesmente não existem lá) nem no pan do canvas (que tem lógica própria).
                const ALCA = '.flowchart-operator-title, .flowchart-operator-body';
                let arrastando_toque = false;

                function repassarComoMouse(t, tipo) {
                    if (!t) return;
                    t.target.dispatchEvent(new MouseEvent(tipo, {
                        bubbles: true, cancelable: true, view: window,
                        clientX: t.clientX, clientY: t.clientY,
                        screenX: t.screenX, screenY: t.screenY,
                        button: 0, buttons: tipo === 'mouseup' ? 0 : 1
                    }));
                }

                // Listener nativo em fase de CAPTURA, não delegação do jQuery: a lib do canvas
                // escuta touchstart no .flowchart-operator e interrompe a propagação, então um
                // handler delegado no document nunca chega a rodar (medido: direto no elemento
                // dispara, delegado não). Captura roda de cima pra baixo, antes disso.
                document.addEventListener('touchstart', function (ev) {
                    // Chegou um segundo dedo no meio de um arraste: o gesto é pinça, não
                    // arraste. Solta o bloco onde está, senão ele seguiria um dos dedos
                    // enquanto o canvas muda de escala.
                    if (ev.touches.length > 1 && arrastando_toque) {
                        arrastando_toque = false;
                        repassarComoMouse(ev.changedTouches[0], 'mouseup');
                    }
                    if (ev.touches.length !== 1) return;
                    if (!ev.target.closest || !ev.target.closest(ALCA)) return;
                    arrastando_toque = true;
                    repassarComoMouse(ev.changedTouches[0], 'mousedown');
                }, true);

                document.addEventListener('touchmove', function (ev) {
                    if (!arrastando_toque) return;
                    if (ev.cancelable) ev.preventDefault();
                    repassarComoMouse(ev.changedTouches[0], 'mousemove');
                }, { capture: true, passive: false });

                document.addEventListener('touchend', function (ev) {
                    if (!arrastando_toque) return;
                    arrastando_toque = false;
                    repassarComoMouse(ev.changedTouches[0], 'mouseup');
                }, true);

                document.addEventListener('touchcancel', function (ev) {
                    if (!arrastando_toque) return;
                    arrastando_toque = false;
                    repassarComoMouse(ev.changedTouches[0], 'mouseup');
                }, true);
            }
            $('.conteiner-fluxo').on('dragover', function(e) {
                e.preventDefault(); // Permite drop
                e.originalEvent.dataTransfer.dropEffect = 'copy';
            }).on('drop', function(e) {
                e.preventDefault();
                const type = e.originalEvent.dataTransfer.getData('node-type');
                if (type) {
                    const wrapper = $(this);
                    const offset = wrapper.offset();
                    const scroll_left = wrapper.scrollLeft();
                    const scroll_top = wrapper.scrollTop();

                    const mouse_x = e.originalEvent.clientX - offset.left;
                    const mouse_y = e.originalEvent.clientY - offset.top;

                    // Converte para coordenadas do canvas (considerando scroll e zoom)
                    // Canvas (0,0) está em wrapper(0,0) se scroll=0
                    // CoordCanvas = (MousePos + Scroll) / Zoom
                    const x = (mouse_x + scroll_left) / zoom_level;
                    const y = (mouse_y + scroll_top) / zoom_level;

                    // Centraliza o bloco no mouse (aprox 120x40 é metade de um bloco padrão)
                    // Garante que não fique negativo (fora da área visível superior/esquerda)
                    const final_x = Math.max(10, x - 100);
                    const final_y = Math.max(10, y - 40);

                    addNode(type, { left: final_x, top: final_y });
                }
            });

        const $zoom_controls = $(`
            <div class="controles-zoom">
                <button type="button" id="btn-zoom-out" title="Diminuir Zoom">－</button>
                <button type="button" id="btn-zoom-reset" title="Resetar Zoom">100%</button>
                <button type="button" id="btn-zoom-in" title="Aumentar Zoom">＋</button>
                <div class="divisor-zoom"></div>
                <button type="button" id="btn-zoom-fit" title="Ajustar à tela">Ajustar</button>
            </div>
        `);
        // Remove controles anteriores se existirem para não duplicar
        $('.controles-zoom').remove();
        // Adiciona dentro do container principal (área cinza) mas fora do scroll
        $('.conteiner-fluxo').parent().css('position', 'relative').append($zoom_controls);

        $('.adicionar-no').each(function() {
            const type = $(this).data('node-type');
            if (block_icons[type]) {
                $(this).prepend(block_icons[type]);
            }
        });

        const url_params = new URLSearchParams(window.location.search);
        const flow_id = url_params.get('id');
        
        if (flow_id) {
            openFlow(flow_id);
        } else {
            newFlow();
        }
        
        const $wrapper = $('.conteiner-fluxo');
        let arrastando = false;
        let inicio = { x: 0, y: 0 };
        let scroll_inicial = { x: 0, y: 0 };
        
        $flowchart.on('mousedown', function (e) {
            if (e.button !== 0) return;
            if ($(e.target).closest('.flowchart-operator, .flowchart-link, .botao, input, textarea, select').length) return;
            arrastando = true;
            inicio = { x: e.pageX, y: e.pageY };
            scroll_inicial = { x: $wrapper.scrollLeft(), y: $wrapper.scrollTop() };
            $flowchart.css('cursor', 'grabbing');
            e.preventDefault();
        });
        $(document).on('mousemove', function (e) {
            if (!arrastando) return;
            const dx = e.pageX - inicio.x;
            const dy = e.pageY - inicio.y;
            $wrapper.scrollLeft(scroll_inicial.x - dx);
            $wrapper.scrollTop(scroll_inicial.y - dy);
        });
        $(document).on('mouseup', function () {
            if (!arrastando) return;
            arrastando = false;
            $flowchart.css('cursor', '');
        });

        // ===== Gestos de toque no canvas: arrastar com 1 dedo, zoom de pinça com 2 =====
        // O pan acima é só de mouse. No celular nada disso acontecia: o .conteiner-fluxo tem
        // overflow:hidden (o pan é feito por scrollLeft/scrollTop via script), então o navegador
        // não considera ele rolável e mandava o gesto pra página -- medido: arrastar não movia o
        // canvas e a pinça dava zoom na página inteira (visualViewport.scale ia a 5).
        // Com touch-action:none no container, todo gesto chega aqui e nós decidimos o que fazer.
        const el_wrapper = $wrapper.get(0);
        if (el_wrapper) {
            const IGNORAR = '.flowchart-operator, .flowchart-link, .botao, input, textarea, select, button';
            let gesto = null;              // null | 'pan' | 'pinca'
            let ini = null;

            const distancia = (a, b) => Math.hypot(a.clientX - b.clientX, a.clientY - b.clientY);
            // Ponto médio em coordenadas de dentro do container, não da janela.
            function meio(a, b) {
                const r = el_wrapper.getBoundingClientRect();
                return { x: (a.clientX + b.clientX) / 2 - r.left, y: (a.clientY + b.clientY) / 2 - r.top };
            }

            function iniciarPan(t) {
                gesto = 'pan';
                ini = { x: t.clientX, y: t.clientY, sl: el_wrapper.scrollLeft, st: el_wrapper.scrollTop };
            }

            el_wrapper.addEventListener('touchstart', function (ev) {
                if (ev.touches.length >= 2) {
                    const m = meio(ev.touches[0], ev.touches[1]);
                    gesto = 'pinca';
                    ini = {
                        dist: distancia(ev.touches[0], ev.touches[1]),
                        zoom: zoom_level,
                        // Ponto do canvas que está sob os dedos agora. É ele que tem que ficar
                        // parado enquanto a escala muda -- senão o zoom "foge" pro canto.
                        cx: (m.x + el_wrapper.scrollLeft) / zoom_level,
                        cy: (m.y + el_wrapper.scrollTop) / zoom_level
                    };
                    if (ev.cancelable) ev.preventDefault();
                    return;
                }
                if (ev.touches.length === 1) {
                    const t = ev.touches[0];
                    // Em cima de um bloco/link/botão o toque é deles: mover o bloco e editar
                    // continuam funcionando (a ponte de arraste cuida disso).
                    if (t.target.closest && t.target.closest(IGNORAR)) { gesto = null; return; }
                    iniciarPan(t);
                }
            }, { passive: false });

            el_wrapper.addEventListener('touchmove', function (ev) {
                if (gesto === 'pinca' && ev.touches.length >= 2) {
                    if (ev.cancelable) ev.preventDefault();
                    const d = distancia(ev.touches[0], ev.touches[1]);
                    if (!ini.dist) return;
                    setZoom(ini.zoom * (d / ini.dist));
                    // setZoom limita entre 20% e 300%: lê zoom_level de volta em vez de confiar
                    // na conta, senão nos limites o canvas continuaria deslizando sem escalar.
                    const m = meio(ev.touches[0], ev.touches[1]);
                    el_wrapper.scrollLeft = ini.cx * zoom_level - m.x;
                    el_wrapper.scrollTop  = ini.cy * zoom_level - m.y;
                    return;
                }
                if (gesto === 'pan' && ev.touches.length === 1) {
                    if (ev.cancelable) ev.preventDefault();
                    const t = ev.touches[0];
                    el_wrapper.scrollLeft = ini.sl - (t.clientX - ini.x);
                    el_wrapper.scrollTop  = ini.st - (t.clientY - ini.y);
                }
            }, { passive: false });

            function encerrarGesto(ev) {
                if (ev.touches.length === 0) { gesto = null; ini = null; return; }
                // Tirou um dedo da pinça: reancora o arraste no dedo que sobrou, senão o canvas
                // daria um salto na primeira mexida seguinte.
                if (ev.touches.length === 1) {
                    const t = ev.touches[0];
                    if (t.target.closest && t.target.closest(IGNORAR)) { gesto = null; ini = null; return; }
                    iniciarPan(t);
                }
            }
            el_wrapper.addEventListener('touchend', encerrarGesto);
            el_wrapper.addEventListener('touchcancel', encerrarGesto);
        }
        $flowchart.on('dblclick', '.flowchart-operator-title', function (e) {
            if ($(e.target).closest('.btn-excluir').length) return;
            const $title = $(this);
            const $op = $title.closest('.flowchart-operator');
            const id = $op.data('operator_id');
            if (!id) return;

            const atual = $title.find('.texto-titulo').text().trim() || 'Sem título';
            
            const $input = $('<input type="text" class="edita-titulo" style="flex:1; min-width:0; margin:0;">').val(atual);
            
            // Substitui apenas o texto pelo input, mantendo ícone e botão
            const $texto_span = $title.find('.texto-titulo');
            $texto_span.hide();
            $texto_span.after($input);
            
            $input.focus().select();
            
            function finalizar(salvar) {
                const novo = ($input.val().trim() || 'Sem título');
                if (salvar) {
                    const data = getChartData();
                    if (data.operators[id]) {
                        data.operators[id].properties.title = novo;
                    }
                    setChartData(data); // Isso recria o operador usando onOperatorCreate
                    $flowchart.flowchart('selectOperator', id);
                    agendarAutoSalvar();
                } else {
                    $input.remove();
                    $texto_span.show();
                }
            }
            $input.on('keydown', function (ev) {
                if (ev.key === 'Enter') finalizar(true);
                if (ev.key === 'Escape') finalizar(false);
            });
            $input.on('blur', function () { finalizar(true); });
        });

        if (intervalo_backup) { clearInterval(intervalo_backup); }
        intervalo_backup = setInterval(function () {
            if (fluxo_sujo) {
                atualizaFluxo(true);
            }
        }, 10000);
    });
    });

    function agendarAutoSalvar() {
        fluxo_sujo = true;
        clearTimeout(timer_auto_salvar);
        timer_auto_salvar = setTimeout(function () {
            if (fluxo_sujo) {
                atualizaFluxo(true);
            }
        }, 2000);
    }
    
    $flowchart.on('click', '.btn-excluir', function (e) {
        e.stopPropagation();
        const $op = $(this).closest('.flowchart-operator');
        const id = $op.data('operator_id');
        if (!id) return;
        $flowchart.flowchart('deleteOperator', id);
        atualizaFluxo(true);
    });
})(jQuery);
