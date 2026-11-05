/*
 * Exportar e importar fluxo por código (04/11/2026). O fluxo não sai mais como arquivo .json: exportar mostra um código (CYT-XXXXXXXX) e importar cria uma
 * cópia na conta de quem colou, opcionalmente já ligada a um bot. Servidor: api.php (gerar_codigo_fluxo / importar_fluxo_codigo) e funcoes/fluxo_codigo.php.
 * Usado em Meus Fluxos (fluxos.php), no Editor Visual (fluxo.php) e na página do bot (bot.php). Precisa do jQuery com o X-CSRF-Token no $.ajaxSetup.
 *   FluxoCodigo.abrirExportar(id_fluxo, nome_fluxo)
 *   FluxoCodigo.abrirImportar({ aoImportar: function (resposta) { ... } })   // resposta: { id, modo, mensagem }
 */
(function ($) {
    const api = 'api.php';
    // O estilo vem junto: a janela abre em páginas com a casca nova e com a antiga, então não pode depender do CSS de nenhuma das duas.
    (function carregarEstilo() {
        const atual = document.currentScript && document.currentScript.src;
        const href = atual ? atual.replace('/js/fluxo_codigo.js', '/css/fluxo_codigo.css') : 'assets/css/fluxo_codigo.css';
        if (!document.querySelector('link[data-fc-css]')) {
            $('<link rel="stylesheet" data-fc-css>').attr('href', href).appendTo('head');
        }
    })();
    const ic = {
        subir: '<svg class="fc-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>',
        descer: '<svg class="fc-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>',
        fechar: '<svg class="fc-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6L6 18M6 6l12 12"></path></svg>',
        copiar: '<svg class="fc-ic" viewBox="0 0 24 24" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>'
    };

    function esc(v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c]; });
    }
    function mensagemErro(xhr, padrao) {
        return (xhr && xhr.responseJSON && xhr.responseJSON.mensagem) || padrao;
    }

    /** Monta a janela, fecha pelo ✕, pelo fundo e pelo Esc; o foco volta para quem abriu. */
    function abrirJanela(id, html) {
        $('#' + id).remove();
        const origem = document.activeElement;
        const $over = $('<div class="fc-over" role="dialog" aria-modal="true"></div>').attr('id', id).append('<div class="fc-janela">' + html + '</div>');
        $('body').append($over);
        function fechar() {
            $over.remove();
            $(document).off('keydown.' + id);
            if (origem && origem.focus) origem.focus();
        }
        $over.on('click', function (e) { if (e.target === this) fechar(); });
        $over.on('click', '[data-fc-fechar]', fechar);
        $(document).on('keydown.' + id, function (e) { if (e.key === 'Escape') fechar(); });
        return { $el: $over, fechar: fechar };
    }

    function cabecalho(icone, titulo) {
        return '<div class="fc-mh"><div class="fc-tit"><span class="fc-tit-ic">' + icone + '</span><h2>' + esc(titulo) + '</h2></div>'
            + '<button type="button" class="fc-x" data-fc-fechar aria-label="Fechar">' + ic.fechar + '</button></div>';
    }

    function copiarTexto(texto) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(texto);
        }
        return new Promise(function (ok, falha) {
            const $t = $('<textarea readonly style="position:fixed;opacity:0"></textarea>').val(texto).appendTo('body');
            $t[0].select();
            try { document.execCommand('copy') ? ok() : falha(); } catch (e) { falha(e); }
            $t.remove();
        });
    }

    function abrirExportar(id_fluxo, nome_fluxo) {
        const j = abrirJanela('fc-exportar', cabecalho(ic.descer, 'Exportar Fluxo')
            + '<p class="fc-txt">Copie o código e cole em <b>Importar Fluxo</b> em outra conta da plataforma. Vai uma cópia de <b>' + esc(nome_fluxo || 'este fluxo') + '</b> como está agora.</p>'
            + '<div class="fc-rot">Código de exportação</div>'
            + '<div class="fc-codigo-caixa"><code class="fc-codigo" aria-live="polite">Gerando...</code>'
            + '<button type="button" class="fc-btn fc-copiar" disabled>' + ic.copiar + '<span>Copiar</span></button></div>'
            + '<p class="fc-erro" hidden></p>'
            + '<p class="fc-dica">Não vão junto: grupos, links e mensagens de entrega, suporte e canal de mídias. Quem importar configura os dele. O código só funciona dentro da plataforma; se você mudar o fluxo depois, exporte de novo.</p>'
            + '<div class="fc-pe"><button type="button" class="fc-btn" data-fc-fechar>Fechar</button></div>');
        const $el = j.$el;
        $el.find('[data-fc-fechar]').last().trigger('focus');
        $.ajax({ url: api + '?action=gerar_codigo_fluxo', method: 'POST', contentType: 'application/json', data: JSON.stringify({ id: id_fluxo }) })
            .done(function (r) {
                if (!r.sucesso || !r.codigo) {
                    $el.find('.fc-codigo').text('—');
                    $el.find('.fc-erro').text(r.mensagem || 'Não foi possível gerar o código.').prop('hidden', false);
                    return;
                }
                $el.find('.fc-codigo').text(r.codigo);
                $el.find('.fc-copiar').prop('disabled', false).on('click', function () {
                    const $b = $(this);
                    copiarTexto(r.codigo).then(function () {
                        $b.find('span').text('Copiado!');
                        setTimeout(function () { $b.find('span').text('Copiar'); }, 2000);
                    }).catch(function () {
                        $el.find('.fc-erro').text('Não deu para copiar sozinho: selecione o código e copie.').prop('hidden', false);
                    });
                });
            })
            .fail(function (xhr) {
                $el.find('.fc-codigo').text('—');
                $el.find('.fc-erro').text(mensagemErro(xhr, 'Não foi possível gerar o código.')).prop('hidden', false);
            });
    }

    function abrirImportar(opcoes) {
        opcoes = opcoes || {};
        const j = abrirJanela('fc-importar', cabecalho(ic.subir, 'Importar Fluxo')
            + '<p class="fc-txt">Cole o código que você recebeu. O fluxo é copiado para a sua conta e, se quiser, já fica ligado a um bot.</p>'
            + '<form class="fc-form" novalidate>'
            + '<label class="fc-rot" for="fc-imp-codigo">Código de exportação</label>'
            + '<input class="fc-campo fc-campo-codigo" id="fc-imp-codigo" type="text" required autocomplete="off" autocapitalize="characters" spellcheck="false" maxlength="40" placeholder="CYT-XXXXXXXX">'
            + '<label class="fc-rot" for="fc-imp-nome">Nome do fluxo <span>(opcional)</span></label>'
            + '<input class="fc-campo" id="fc-imp-nome" type="text" maxlength="120" placeholder="Em branco, usa o nome original">'
            + '<small class="fc-dica">Sem nome, fica o nome original + "(Importado)".</small>'
            + '<label class="fc-rot" for="fc-imp-bot">Ligar a um bot <span>(opcional)</span></label>'
            + '<select class="fc-campo" id="fc-imp-bot"><option value="">Carregando bots...</option></select>'
            + '<small class="fc-dica fc-dica-bot"></small>'
            + '<p class="fc-erro" hidden></p>'
            + '<div class="fc-pe"><button type="button" class="fc-btn" data-fc-fechar>Cancelar</button>'
            + '<button type="submit" class="fc-btn fc-btn-pri fc-importar">' + ic.subir + '<span>Importar Fluxo</span></button></div>'
            + '</form>');
        const $el = j.$el;
        const $bot = $el.find('#fc-imp-bot');
        $el.find('#fc-imp-codigo').trigger('focus');

        $.getJSON(api + '?action=listar_bots').done(function (r) {
            const bots = (r && r.bots) || [];
            $bot.html('<option value="">Não ligar a nenhum bot agora</option>');
            bots.forEach(function (b) {
                const nome = b.nome_usuario ? '@' + b.nome_usuario : (b.primeiro_nome || 'Bot');
                const extra = b.nome_fluxo ? ' · troca o fluxo "' + b.nome_fluxo + '"' : ' · sem fluxo';
                $bot.append($('<option></option>').val(b.id).text(nome + extra));
            });
            if (!bots.length) $el.find('.fc-dica-bot').text('Você ainda não tem bot. Dá para ligar o fluxo depois, na página do bot.');
        }).fail(function () {
            $bot.html('<option value="">Não ligar a nenhum bot agora</option>');
        });
        $bot.on('change', function () {
            const texto = $bot.find('option:selected').text();
            $el.find('.fc-dica-bot').text(texto.indexOf('troca o fluxo') >= 0 ? 'Esse bot passa a usar o fluxo importado no lugar do atual.' : '');
        });

        $el.find('.fc-form').on('submit', function (e) {
            e.preventDefault();
            const codigo = $.trim($el.find('#fc-imp-codigo').val());
            const $erro = $el.find('.fc-erro');
            if (!codigo) {
                $erro.text('Cole o código de exportação.').prop('hidden', false);
                $el.find('#fc-imp-codigo').trigger('focus');
                return;
            }
            const $btn = $el.find('.fc-importar').prop('disabled', true);
            $btn.find('span').text('Importando...');
            $erro.prop('hidden', true);
            $.ajax({
                url: api + '?action=importar_fluxo_codigo', method: 'POST', contentType: 'application/json',
                data: JSON.stringify({ codigo: codigo, nome: $.trim($el.find('#fc-imp-nome').val()), id_bot: Number($bot.val()) || 0 })
            }).done(function (r) {
                if (!r.sucesso) {
                    $erro.text(r.mensagem || 'Não foi possível importar.').prop('hidden', false);
                    return;
                }
                j.fechar();
                if (typeof opcoes.aoImportar === 'function') opcoes.aoImportar(r);
            }).fail(function (xhr) {
                $erro.text(mensagemErro(xhr, 'Não foi possível importar.')).prop('hidden', false);
            }).always(function () {
                $btn.prop('disabled', false).find('span').text('Importar Fluxo');
            });
        });
    }

    window.FluxoCodigo = { abrirExportar: abrirExportar, abrirImportar: abrirImportar };
})(jQuery);
