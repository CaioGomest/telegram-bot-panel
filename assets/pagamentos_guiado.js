/**
 * Aba Pagamentos do Fluxo Guiado (modelo novo, 26/10/2026): formulário em duas abas (Pagamento Gerado / Pagamento Aprovado) e a prévia da conversa no Telegram.
 *
 * A parte de cima são as MESMAS regras de funcoes/pagamentos_guiado.php escritas em JavaScript (texto seguro com variáveis, configuração normalizada e o PLANO do
 * que o cliente recebe), sem DOM: é o que faz a prévia mostrar o que o bot realmente vai mandar. tests/guiado/unitario_pagamentos_previa.js roda os dois lados
 * com as mesmas entradas e exige o mesmo resultado, então mexeu num, mexa no outro.
 * A parte de baixo (só no navegador, precisa de jQuery) é a tela. O editor (edicao_fluxo_basico.js) chama iniciar(api), coletar() e preencher().
 */
(function (global) {
    'use strict';

    const MIDIAS_MAX = 3;
    const TEXTO_MAX = 3000;
    const CURTO_MAX = 300;
    const BOTAO_MAX = 40;
    const LEGENDA_MAX = 1024;
    const LEGENDA_QR = 'Escaneie o QR Code para pagar.';
    const TEXTO_PIX_PADRAO = '⏳ <b>Aguardando pagamento...</b>\nSeu acesso será liberado automaticamente em até 1 minuto após a confirmação do pagamento.\n\n👇 <b>Toque no código abaixo para copiar e pague no app do seu banco:</b>';
    const TEXTO_APROVADO_PADRAO = '✅ <b>Pagamento Confirmado!</b>\n\nObrigado pela sua compra.';
    const TAGS = { b: 'b', strong: 'b', i: 'i', em: 'i', u: 'u', ins: 'u', s: 's', strike: 's', del: 's', code: 'code', pre: 'pre', blockquote: 'blockquote', 'tg-spoiler': 'tg-spoiler' };
    const QR_POSICOES = ['antes', 'junto', 'depois', 'nao'];
    const FORMATOS = ['inline', 'bloco', 'simples'];
    const LAYOUTS = ['padrao', 'compacto'];

    // ============ regras (espelho do PHP) ============
    function pontos(s) { return Array.from(String(s)); }
    function compr(s) { return pontos(s).length; }
    function cortar(s, n) { const a = pontos(s); return a.length > n ? a.slice(0, n).join('') : String(s); }
    function aparar(s) { return String(s == null ? '' : s).replace(/^[\s\u0000]+|[\s\u0000]+$/g, ''); }
    function escapar(t) { return String(t).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
    // para valores dentro de atributo HTML (src, title): além do escape comum, as aspas
    function escaparAtributo(t) { return escapar(t).replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }

    function saudacao(hora) {
        return hora < 12 ? 'Bom dia' : (hora < 18 ? 'Boa tarde' : 'Boa noite');
    }

    function valorTexto(valor) {
        const [inteiro, dec] = (Math.round(Number(valor) * 100) / 100).toFixed(2).split('.');
        return 'R$ ' + inteiro.replace(/\B(?=(\d{3})+(?!\d))/g, '.') + ',' + dec;
    }

    function variaveis(nome, plano, valor, hora) {
        const primeiro = aparar(aparar(nome).split(/\s+/)[0]);
        return { nome: primeiro !== '' ? primeiro : 'Cliente', plano: plano, valor: valorTexto(valor), saudacao: saudacao(hora == null ? new Date().getHours() : hora) };
    }

    // Texto do dono -> HTML que o Telegram aceita (tags permitidas balanceadas, resto escapado, {variável} trocada e escapada). Valor {html: ...} entra como está.
    function htmlSeguro(texto, vars, maximo) {
        vars = vars || {};
        texto = cortar(String(texto).replace(/\r\n?/g, '\n'), maximo == null ? TEXTO_MAX : maximo);
        const partes = texto.split(/(<\/?[A-Za-z][A-Za-z0-9-]*\s*>)/);
        let saida = '';
        let pilha = [];
        partes.forEach(function (parte, i) {
            if (parte === '') return;
            const m = i % 2 === 1 ? parte.match(/^<(\/?)([A-Za-z][A-Za-z0-9-]*)\s*>$/) : null;
            if (m && Object.prototype.hasOwnProperty.call(TAGS, m[2].toLowerCase())) {
                const tag = TAGS[m[2].toLowerCase()];
                if (m[1] === '') {
                    saida += '<' + tag + '>';
                    pilha.push(tag);
                    return;
                }
                let achou = -1;
                for (let k = pilha.length - 1; k >= 0; k--) {
                    if (pilha[k] === tag) { achou = k; break; }
                }
                if (achou === -1) return;   // fechamento sem abertura: some
                for (let k = pilha.length - 1; k >= achou; k--) saida += '</' + pilha[k] + '>';
                pilha = pilha.slice(0, achou);
                return;
            }
            parte.split(/(\{[a-z_]+\})/).forEach(function (pedaco, j) {
                const nome = pedaco.replace(/^\{|\}$/g, '');
                if (j % 2 === 1 && Object.prototype.hasOwnProperty.call(vars, nome)) {
                    const v = vars[nome];
                    saida += (v !== null && typeof v === 'object') ? String(v.html == null ? '' : v.html) : escapar(String(v));
                } else {
                    saida += escapar(pedaco);
                }
            });
        });
        for (let k = pilha.length - 1; k >= 0; k--) saida += '</' + pilha[k] + '>';
        return saida;
    }

    function htmlParaTexto(html) {
        return String(html).replace(/<[^>]*>/g, '').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&#0?39;/g, "'").replace(/&amp;/g, '&');
    }

    function midias(bruto) {
        const lista = [];
        (Array.isArray(bruto) ? bruto : []).forEach(function (m) {
            if (m && typeof m === 'object' && (m.tipo === 'image' || m.tipo === 'video') && String(m.path || '') !== '') lista.push({ tipo: m.tipo, path: String(m.path) });
        });
        return lista.slice(0, MIDIAS_MAX);
    }

    function textoCurto(valor, padrao, maximo) {
        const t = cortar(aparar(valor), maximo);
        return t !== '' ? t : padrao;
    }

    function config(p) {
        p = p && typeof p === 'object' ? p : {};
        const item = function (bruto, textoPadrao, max, ativoPadrao) {
            bruto = bruto && typeof bruto === 'object' ? bruto : {};
            return { ativo: Object.prototype.hasOwnProperty.call(bruto, 'ativo') ? !!bruto.ativo : ativoPadrao, texto: textoCurto(bruto.texto, textoPadrao, max) };
        };
        const qr = QR_POSICOES.indexOf(p.qr_exibicao) !== -1 ? p.qr_exibicao : 'depois';
        const copiar = item(p.btn_copiar, '📋 Copiar Código', BOTAO_MAX, true);
        const verificar = item(p.btn_verificar, '✅ Verificar Status', BOTAO_MAX, true);
        return {
            versao: 2,
            layout: LAYOUTS.indexOf(p.layout) !== -1 ? p.layout : 'padrao',
            midias_gerado: midias(p.midias_gerado),
            msg_instrucoes: cortar(aparar(p.msg_instrucoes), TEXTO_MAX),
            qr_exibicao: qr,
            qrcode_definido: true,
            mostrar_qrcode: qr !== 'nao',
            codigo_formato: FORMATOS.indexOf(p.codigo_formato) !== -1 ? p.codigo_formato : 'inline',
            codigo_na_mensagem: !!p.codigo_na_mensagem,
            antes_codigo: item(p.antes_codigo, 'Copie o código abaixo:', CURTO_MAX, false),
            antes_botoes: item(p.antes_botoes, 'Após efetuar o pagamento, clique no botão abaixo', CURTO_MAX, false),
            btn_copiar: copiar,
            btn_verificar: verificar,
            mostrar_copiar: copiar.ativo,
            mostrar_confirmar: verificar.ativo,
            msg_confirmado: cortar(aparar(p.msg_confirmado), TEXTO_MAX),
            midias_aprovado: midias(p.midias_aprovado),
            botao_acesso: item(p.botao_acesso, '🔓 Acessar Conteúdo', BOTAO_MAX, true)
        };
    }

    function codigoHtml(codigo, formato) {
        const c = escapar(codigo);
        return formato === 'bloco' ? '<pre>' + c + '</pre>' : (formato === 'simples' ? c : '<code>' + c + '</code>');
    }

    function teclado(pg, ctx) {
        const linhas = [];
        const codigo = String(ctx.codigo || '');
        if (pg.btn_copiar.ativo && codigo !== '' && new TextEncoder().encode(codigo).length <= 256) {
            linhas.push([{ text: pg.btn_copiar.texto, copy_text: { text: codigo } }]);
        }
        if (pg.btn_verificar.ativo && String(ctx.txid || '') !== '') {
            linhas.push([{ text: pg.btn_verificar.texto, callback_data: 'verificar_pagamento_' + ctx.txid }]);
        }
        return linhas;
    }

    function planoPix(pg, ctx) {
        const codigo = String(ctx.codigo || '');
        const vars = variaveis(String(ctx.nome || ''), String(ctx.plano || ''), Number(ctx.valor || 0), ctx.hora);
        const codigoH = codigo !== '' ? codigoHtml(codigo, pg.codigo_formato) : '';
        vars.codigo_pix = { html: codigoH };

        const modelo = pg.msg_instrucoes;
        const variavelNoTexto = codigo !== '' && modelo.indexOf('{codigo_pix}') !== -1;
        const codigoNoTexto = codigo !== '' && (variavelNoTexto || pg.codigo_na_mensagem);

        const corpo = htmlSeguro(modelo !== '' ? modelo : TEXTO_PIX_PADRAO, vars);
        const cabecalho = pg.layout === 'compacto' ? '' : '✅ <b>Pedido Criado com Sucesso!</b>\n\n🛒 <b>Produto:</b> ' + escapar(String(ctx.plano || '')) + '\n💲 <b>Valor:</b> ' + escapar(valorTexto(Number(ctx.valor || 0))) + '\n\n';
        let texto = cabecalho + corpo;
        if (codigoNoTexto && !variavelNoTexto) texto += '\n\n' + codigoH;

        const temBotoes = teclado(pg, ctx).length > 0;
        const botoesOnde = !temBotoes ? 'nenhum' : (pg.antes_botoes.ativo ? 'final' : (codigoNoTexto ? 'texto' : (codigo !== '' ? 'codigo' : 'texto')));

        const passos = [];
        if (pg.midias_gerado.length) passos.push({ t: 'midias', midias: pg.midias_gerado });
        const qr = ctx.tem_qr ? pg.qr_exibicao : 'nao';
        const foto = function () { return { t: 'foto_qr', html: LEGENDA_QR, botoes: false }; };
        if (qr === 'antes') passos.push(foto());
        if (qr === 'junto' && compr(htmlParaTexto(texto)) <= LEGENDA_MAX) {
            passos.push({ t: 'foto_qr', html: texto, botoes: botoesOnde === 'texto' });
        } else {
            if (qr === 'junto') passos.push(foto());
            passos.push({ t: 'texto', html: texto, botoes: botoesOnde === 'texto' });
        }
        if (qr === 'depois') passos.push(foto());
        if (!codigoNoTexto && codigo !== '') {
            if (pg.antes_codigo.ativo) passos.push({ t: 'texto', html: htmlSeguro(pg.antes_codigo.texto, vars, CURTO_MAX), botoes: false });
            passos.push({ t: 'texto', html: codigoH, botoes: botoesOnde === 'codigo' });
        }
        if (botoesOnde === 'final') passos.push({ t: 'texto', html: htmlSeguro(pg.antes_botoes.texto, vars, CURTO_MAX), botoes: true });
        return passos;
    }

    function linhasValidade(minutos) {
        if (minutos >= 36500 * 1440) return '\n♾️ <b>Acesso vitalício.</b>';
        if (minutos < 60) return '\n⏳ <b>Seu acesso expira em ' + minutos + ' minutos.</b>';
        if (minutos < 1440) return '\n⏳ <b>Seu acesso expira em ' + Math.floor(minutos / 60) + ' horas.</b>';
        return '\n⏳ <b>Seu acesso expira em ' + Math.floor(minutos / 1440) + ' dias.</b>';
    }

    function planoAprovacao(pg, ctx) {
        const vars = variaveis(String(ctx.nome || ''), String(ctx.plano || ''), Number(ctx.valor || 0), ctx.hora);
        const modelo = pg.msg_confirmado;
        let html = htmlSeguro(modelo !== '' ? modelo : TEXTO_APROVADO_PADRAO, vars);
        const botaoAtivo = !!pg.botao_acesso.ativo;
        const linkGrupo = aparar(ctx.link_grupo);
        const linkEntrega = aparar(ctx.link_entrega);
        let url = null;
        if (linkGrupo !== '') {
            url = linkGrupo;
            html += '\n\n🚀 <b>Acesso Liberado!</b>\n' + (botaoAtivo
                ? 'Toque no botão abaixo para entrar no grupo exclusivo.\n\n⚠️ Este link é válido apenas para você.'
                : 'Clique no link abaixo para entrar no grupo exclusivo:\n\n' + escapar(linkGrupo) + '\n\n⚠️ Este link é válido apenas para você.');
            html += linhasValidade(Math.floor(Number(ctx.tempo_minutos || 0)));
        } else if (ctx.grupo_falhou) {
            html += '\n\n⚠️ Não foi possível gerar o link do grupo automaticamente. O administrador entrará em contato.';
        }
        if (linkEntrega !== '') {
            url = url === null ? linkEntrega : url;
            if (!botaoAtivo) html += '\n\n🔗 <b>Seu acesso:</b>\n' + escapar(linkEntrega);
            else if (url === linkEntrega) html += '\n\n🔗 <b>Seu acesso está no botão abaixo.</b>';
        }
        return { midias: pg.midias_aprovado, html: html, botao: (botaoAtivo && url !== null) ? { texto: pg.botao_acesso.texto, url: url } : null };
    }

    const regras = { htmlSeguro, htmlParaTexto, variaveis, config, teclado, planoPix, planoAprovacao, valorTexto, saudacao, codigoHtml };

    // ============ padrões ============
    // Fluxo NOVO (sem nada salvo): o texto sugerido e os botões ligados. Fluxo salvo antes do modelo novo é preenchido com o que ele fazia (ver preencher()).
    const PADRAO_NOVO = {
        layout: 'padrao',
        msg_instrucoes: '<b>Como realizar o pagamento:</b>\n\n1. Abra o aplicativo do seu banco.\n2. Selecione a opção "Pagar" ou "PIX".\n3. Escolha "PIX Copia e Cola".\n4. Cole o código que está abaixo e finalize o pagamento com segurança.',
        qr_exibicao: 'antes', codigo_formato: 'inline', codigo_na_mensagem: false,
        antes_codigo: { ativo: true, texto: 'Copie o código abaixo:' },
        antes_botoes: { ativo: true, texto: 'Após efetuar o pagamento, clique no botão abaixo' },
        btn_copiar: { ativo: true, texto: '📋 Copiar Código' },
        btn_verificar: { ativo: true, texto: '✅ Verificar Status' },
        msg_confirmado: '✅ <b>Pagamento Aprovado!</b>\n\nParabéns {nome}! Seu pagamento do plano <b>{plano}</b> no valor de <b>{valor}</b> foi confirmado.\n\n🎉 Você já tem acesso ao conteúdo!',
        botao_acesso: { ativo: true, texto: '🔓 Acessar Conteúdo' },
        midias_gerado: [], midias_aprovado: []
    };
    // O que um fluxo salvo ANTES do modelo novo fazia (e continua fazendo até alguém salvar): é o que a tela mostra ao abri-lo.
    function padraoLegado(p) {
        const qrLigado = p.qrcode_definido ? p.mostrar_qrcode !== false : true;
        return {
            layout: 'padrao',
            msg_instrucoes: p.msg_instrucoes || 'Copie o código Pix abaixo e pague no app do seu banco.',
            qr_exibicao: qrLigado ? 'depois' : 'nao', codigo_formato: 'inline', codigo_na_mensagem: false,
            antes_codigo: { ativo: false, texto: 'Copie o código abaixo:' },
            antes_botoes: { ativo: false, texto: 'Após efetuar o pagamento, clique no botão abaixo' },
            btn_copiar: { ativo: p.mostrar_copiar !== false, texto: '📋 Copiar código Pix' },
            btn_verificar: { ativo: p.mostrar_confirmar !== false, texto: 'Já fiz o pagamento ✅' },
            msg_confirmado: p.msg_confirmado || 'Pagamento confirmado! Seu acesso foi liberado.',
            botao_acesso: { ativo: false, texto: '🔓 Acessar Conteúdo' },
            midias_gerado: [], midias_aprovado: []
        };
    }

    // ============ tela (só no navegador) ============
    if (typeof jQuery !== 'undefined' && typeof document !== 'undefined') {
        const $ = jQuery;
        const CODIGO_EXEMPLO = '00020126580014br.gov.bcb.pix2572qrcode.exemplo.com.br/v2/qr/cob/abc123-def456-ghi789520400005303986540529905802BR5925EXEMPLO6009SAO PAULO62070503***6304ABCD';
        const FORMATACOES = [
            { tag: 'b', rotulo: 'B', titulo: 'Negrito' }, { tag: 'i', rotulo: 'I', titulo: 'Itálico' }, { tag: 'u', rotulo: 'U', titulo: 'Sublinhado' },
            { tag: 's', rotulo: 'S', titulo: 'Tachado' }, { tag: 'code', rotulo: '</>', titulo: 'Código (toque para copiar)' },
            { tag: 'tg-spoiler', rotulo: '👁', titulo: 'Spoiler (texto escondido)' }, { tag: 'blockquote', rotulo: '❝', titulo: 'Citação' }
        ];
        const st = { api: null, midias: { gerado: [], aprovado: [] }, subindo: { gerado: false, aprovado: false }, aba: 'gerado', agendado: false };
        const SECAO = '[data-secao="pagamentos"]';

        function api() { return st.api || { marcarSujo: function () {}, exibirAviso: function () {}, enviarMidia: function (a, cb) { cb(null, 'Envio indisponível.'); }, enviarMidias: function (as, o) { o.aoFim({ total: as.length, enviadas: 0, erros: [], descartadas: as.length }); }, avisarLote: function () {}, escaparHtml: escapar }; }

        function lerBruto() {
            const qrLigado = $('#pg-mostrar-qrcode').is(':checked');
            return {
                layout: $('input[name="pg-layout"]:checked').val(),
                midias_gerado: st.midias.gerado,
                msg_instrucoes: $('#pg-msg-instrucoes').val(),
                qr_exibicao: qrLigado ? $('#pg-qr-posicao').val() : 'nao',
                codigo_formato: $('#pg-codigo-formato').val(),
                codigo_na_mensagem: $('#pg-codigo-na-mensagem').is(':checked'),
                antes_codigo: { ativo: $('#pg-antes-codigo-ativo').is(':checked'), texto: $('#pg-antes-codigo').val() },
                antes_botoes: { ativo: $('#pg-antes-botoes-ativo').is(':checked'), texto: $('#pg-antes-botoes').val() },
                btn_copiar: { ativo: $('#pg-mostrar-copiar').is(':checked'), texto: $('#pg-btn-copiar-texto').val() },
                btn_verificar: { ativo: $('#pg-mostrar-confirmar').is(':checked'), texto: $('#pg-btn-verificar-texto').val() },
                msg_confirmado: $('#pg-msg-confirmado').val(),
                midias_aprovado: st.midias.aprovado,
                botao_acesso: { ativo: $('#pg-botao-acesso-ativo').is(':checked'), texto: $('#pg-botao-acesso-texto').val() }
            };
        }

        function coletar() { return config(lerBruto()); }

        function preencher(p) {
            p = p && typeof p === 'object' ? p : {};
            const v = Number(p.versao || 0) >= 2 ? config(p) : config(padraoLegado(p));
            $('input[name="pg-layout"]').prop('checked', false).filter('[value="' + v.layout + '"]').prop('checked', true);
            st.midias.gerado = v.midias_gerado.slice();
            st.midias.aprovado = v.midias_aprovado.slice();
            $('#pg-msg-instrucoes').val(v.msg_instrucoes);
            $('#pg-mostrar-qrcode').prop('checked', v.qr_exibicao !== 'nao');
            $('#pg-qr-posicao').val(v.qr_exibicao === 'nao' ? 'depois' : v.qr_exibicao);
            $('#pg-codigo-formato').val(v.codigo_formato);
            $('#pg-codigo-na-mensagem').prop('checked', v.codigo_na_mensagem);
            $('#pg-antes-codigo-ativo').prop('checked', v.antes_codigo.ativo);
            $('#pg-antes-codigo').val(v.antes_codigo.texto);
            $('#pg-antes-botoes-ativo').prop('checked', v.antes_botoes.ativo);
            $('#pg-antes-botoes').val(v.antes_botoes.texto);
            $('#pg-mostrar-copiar').prop('checked', v.btn_copiar.ativo);
            $('#pg-btn-copiar-texto').val(v.btn_copiar.texto);
            $('#pg-mostrar-confirmar').prop('checked', v.btn_verificar.ativo);
            $('#pg-btn-verificar-texto').val(v.btn_verificar.texto);
            $('#pg-msg-confirmado').val(v.msg_confirmado);
            $('#pg-botao-acesso-ativo').prop('checked', v.botao_acesso.ativo);
            $('#pg-botao-acesso-texto').val(v.botao_acesso.texto);
            atualizarTela();
        }

        function padraoNovo() { preencher(Object.assign({ versao: 2 }, PADRAO_NOVO)); }

        // ----- mídias (3 espaços por aba)
        function desenharMidias(aba) {
            const $caixa = $('[data-pg-midias="' + aba + '"]');
            const lista = st.midias[aba];
            let html = '';
            for (let i = 0; i < MIDIAS_MAX; i++) {
                const m = lista[i];
                if (m) {
                    const nome = escaparAtributo(m.path.replace('uploads/', ''));
                    html += '<div class="pg-slot pg-slot-cheio" data-i="' + i + '" title="' + nome + '">' +
                        (m.tipo === 'video' ? '<span class="pg-slot-video" aria-hidden="true">▶</span>' : '<img src="' + escaparAtributo(m.path) + '" alt="">') +
                        '<button type="button" class="pg-slot-tirar" data-pg-tirar="' + aba + '" data-i="' + i + '" aria-label="Tirar a mídia ' + (i + 1) + '">×</button></div>';
                } else if (i === lista.length) {
                    html += st.subindo[aba]
                        ? '<div class="pg-slot pg-slot-enviando" aria-live="polite"><span class="midia-basico-giro"></span></div>'
                        : '<button type="button" class="pg-slot pg-slot-novo" data-pg-nova="' + aba + '"><span aria-hidden="true">+</span><small>Mídia</small></button>';
                } else {
                    html += '<div class="pg-slot pg-slot-vazio" aria-hidden="true"></div>';
                }
            }
            $caixa.html(html);
            $('[data-pg-contagem="' + aba + '"]').text(lista.length + '/' + MIDIAS_MAX + ' mídias');
        }

        // ----- tela
        function atualizarContadores() {
            $('[data-pg-contador]').each(function () {
                const alvo = document.getElementById($(this).data('pg-contador'));
                if (alvo) $(this).text(compr(alvo.value) + '/' + TEXTO_MAX);
            });
        }

        // Campo de texto de uma opção só vale com o interruptor dela ligado; "código na mesma mensagem" tira a mensagem antes do código; sem QR a posição some.
        function atualizarDependencias() {
            const codigoNaMsg = $('#pg-codigo-na-mensagem').is(':checked');
            $('#pg-antes-codigo-ativo').prop('disabled', codigoNaMsg);
            $('#pg-linha-antes-codigo').toggleClass('pg-desabilitada', codigoNaMsg);
            $('#pg-qr-posicao').prop('disabled', !$('#pg-mostrar-qrcode').is(':checked'));
            $('[data-pg-liga]').each(function () {
                const $caixa = $('#' + $(this).data('pg-liga'));
                $(this).prop('disabled', !$caixa.is(':checked') || $caixa.is(':disabled'));
            });
        }

        function atualizarTela() {
            desenharMidias('gerado');
            desenharMidias('aprovado');
            atualizarContadores();
            atualizarDependencias();
            renderizarPrevia();
        }

        function agendarPrevia() {
            if (st.agendado) return;
            st.agendado = true;
            requestAnimationFrame(function () { st.agendado = false; atualizarContadores(); atualizarDependencias(); renderizarPrevia(); });
        }

        // ----- prévia do Telegram
        const ICONE_COPIAR = '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V6a2 2 0 0 1 2-2h9"/></svg>';
        const ICONE_LINK = '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 17 17 7M8 7h9v9"/></svg>';
        function qrSvg() {
            // desenho fixo, só ilustrativo (o bot manda o QR de verdade)
            let r = '';
            const seed = [1, 1, 1, 0, 1, 0, 1, 1, 0, 1, 0, 0, 1, 1, 0, 1, 1, 0, 0, 1, 0, 1, 1, 0, 1];
            for (let y = 0; y < 21; y++) for (let x = 0; x < 21; x++) {
                const canto = (x < 7 && y < 7) || (x > 13 && y < 7) || (x < 7 && y > 13);
                if (canto) continue;
                if (seed[(x * 7 + y * 3 + x * y) % seed.length]) r += '<rect x="' + x + '" y="' + y + '" width="1" height="1"/>';
            }
            const olho = function (ox, oy) { return '<path fill-rule="evenodd" d="M' + ox + ' ' + oy + 'h7v7h-7zM' + (ox + 1) + ' ' + (oy + 1) + 'v5h5v-5z"/><rect x="' + (ox + 2) + '" y="' + (oy + 2) + '" width="3" height="3"/>'; };
            return '<svg viewBox="0 0 21 21" class="pg-tg-qr" aria-label="QR Code de exemplo" shape-rendering="crispEdges" fill="#111">' + r + olho(0, 0) + olho(14, 0) + olho(0, 14) + '</svg>';
        }
        function hora() { const d = new Date(); return ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2); }
        function botoesHtml(linhas) {
            if (!linhas.length) return '';
            return '<div class="pg-tg-botoes">' + linhas.map(function (l) {
                return '<div class="pg-tg-botao">' + (l[0].copy_text ? ICONE_COPIAR : (l[0].url ? ICONE_LINK : '')) + '<span>' + escapar(l[0].text) + '</span></div>';
            }).join('') + '</div>';
        }
        function midiasHtml(lista) {
            return '<div class="pg-tg-bolha pg-tg-bolha-midia"><div class="pg-tg-album pg-tg-album-' + lista.length + '">' + lista.map(function (m) {
                return m.tipo === 'video' ? '<div class="pg-tg-tile pg-tg-tile-video"><span>▶</span></div>' : '<div class="pg-tg-tile"><img src="' + escaparAtributo(m.path) + '" alt=""></div>';
            }).join('') + '</div><span class="pg-tg-hora">' + hora() + '</span></div>';
        }
        function bolhaHtml(html, botoes, extra) {
            return '<div class="pg-tg-bolha' + (extra ? ' ' + extra : '') + '"><div class="pg-tg-texto">' + html + '</div><span class="pg-tg-hora">' + hora() + '</span></div>' + botoesHtml(botoes || []);
        }
        function passoHtml(passo, tecladoPix) {
            if (passo.t === 'midias') return midiasHtml(passo.midias);
            const botoes = passo.botoes ? tecladoPix : [];
            if (passo.t === 'foto_qr') {
                return '<div class="pg-tg-bolha pg-tg-bolha-foto">' + qrSvg() + '<div class="pg-tg-texto pg-tg-legenda">' + passo.html + '</div><span class="pg-tg-hora">' + hora() + '</span></div>' + botoesHtml(botoes);
            }
            return bolhaHtml(passo.html, botoes);
        }

        function renderizarPrevia() {
            const $corpo = $('#pg-previa-corpo');
            if (!$corpo.length) return;
            const pg = coletar();
            const h = new Date().getHours();
            const ctxPix = { nome: 'João Silva', plano: 'Premium VIP', valor: 29.9, codigo: CODIGO_EXEMPLO, txid: 'EXEMPLO', tem_qr: true, hora: h };
            const tecladoPix = teclado(pg, ctxPix);
            let html = '<div class="pg-tg-dia">Hoje</div>';
            html += planoPix(pg, ctxPix).map(function (p) { return passoHtml(p, tecladoPix); }).join('');
            html += '<div class="pg-tg-divisor" id="pg-previa-aprovado"><span>Após pagamento aprovado</span></div>';
            const ap = planoAprovacao(pg, { nome: 'João Silva', plano: 'Premium VIP', valor: 29.9, link_grupo: 'https://t.me/+AbCdEfGh1234', tempo_minutos: 30 * 1440, hora: h });
            if (ap.midias.length) html += midiasHtml(ap.midias);
            html += bolhaHtml(ap.html, ap.botao ? [[{ text: ap.botao.texto, url: ap.botao.url }]] : []);
            $corpo.html(html);
            if (st.aba === 'aprovado') {
                const alvo = document.getElementById('pg-previa-aprovado');
                if (alvo) $corpo[0].scrollTop = Math.max(0, alvo.offsetTop - 8);
            }
        }

        function trocarAba(aba) {
            st.aba = aba === 'aprovado' ? 'aprovado' : 'gerado';
            $('.pg-aba').each(function () {
                const ativa = $(this).data('pg-aba') === st.aba;
                $(this).toggleClass('ativa', ativa).attr('aria-selected', ativa ? 'true' : 'false');
            });
            $('[data-pg-painel]').each(function () { $(this).prop('hidden', $(this).data('pg-painel') !== st.aba); });
            const $corpo = $('#pg-previa-corpo');
            if ($corpo.length) {
                if (st.aba === 'aprovado') {
                    const alvo = document.getElementById('pg-previa-aprovado');
                    if (alvo) $corpo[0].scrollTop = Math.max(0, alvo.offsetTop - 8);
                } else {
                    $corpo[0].scrollTop = 0;
                }
            }
        }

        // ----- formatação e variáveis no texto
        function envolver(ta, tag) {
            const ini = ta.selectionStart || 0;
            const fim = ta.selectionEnd || 0;
            const v = ta.value;
            const sel = v.slice(ini, fim);
            ta.value = v.slice(0, ini) + '<' + tag + '>' + sel + '</' + tag + '>' + v.slice(fim);
            const pos = ini + tag.length + 2 + (sel ? sel.length + tag.length + 3 : 0);
            ta.focus();
            ta.setSelectionRange(pos, pos);
            $(ta).trigger('input');
        }
        function inserir(ta, texto) {
            const ini = ta.selectionStart || 0;
            ta.value = ta.value.slice(0, ini) + texto + ta.value.slice((ta.selectionEnd || ini));
            ta.focus();
            ta.setSelectionRange(ini + texto.length, ini + texto.length);
            $(ta).trigger('input');
        }

        function ligar() {
            const $secao = $(SECAO);
            $secao.on('click', '.pg-aba', function () { trocarAba($(this).data('pg-aba')); });
            $secao.on('click', '[data-pg-tag]', function () { envolver(document.getElementById($(this).closest('[data-pg-editor]').data('pg-editor')), $(this).data('pg-tag')); });
            $secao.on('click', '[data-pg-variavel]', function () { inserir(document.getElementById($(this).closest('[data-pg-editor]').data('pg-editor')), '{' + $(this).data('pg-variavel') + '}'); });
            $secao.on('input change', 'input, textarea, select', function () { agendarPrevia(); });
            $secao.on('click', '[data-pg-nova]', function () { st.alvoUpload = $(this).data('pg-nova'); $('#pg-midia-arquivo').prop('multiple', true).trigger('click'); });
            $secao.on('click', '[data-pg-tirar]', function () {
                const aba = $(this).data('pg-tirar');
                st.midias[aba].splice(parseInt($(this).data('i'), 10), 1);
                api().marcarSujo();
                atualizarTela();
            });
            // Vários arquivos de uma vez (Ctrl/Shift ou várias da galeria): sobem um de cada vez, na ordem, até os 3 espaços da aba.
            $('#pg-midia-arquivo').on('change', function () {
                const arquivos = Array.prototype.slice.call(this.files || []);   // copiar antes de limpar: limpar o campo esvazia a lista
                this.value = '';
                const aba = st.alvoUpload;
                if (!arquivos.length || !aba || st.subindo[aba]) return;
                api().enviarMidias(arquivos, {
                    vagas: MIDIAS_MAX - st.midias[aba].length,
                    enviar: api().enviarMidia,
                    aoProgredir: function () { st.subindo[aba] = true; desenharMidias(aba); },
                    aoSubir: function (midia) {
                        st.midias[aba].push({ tipo: midia.tipo, path: midia.caminho });
                        api().marcarSujo();
                        atualizarTela();
                    },
                    aoFim: function (r) {
                        st.subindo[aba] = false;
                        atualizarTela();
                        api().avisarLote(r, 'Mídia anexada. Clique em Salvar alterações para aplicar.', MIDIAS_MAX);
                    }
                });
            });
        }

        function iniciar(apiEditor) {
            st.api = apiEditor;
            if (!$(SECAO).length) return;
            ligar();
            atualizarTela();
        }
        // (a tela é desenhada pelo HTML de fluxo_basico.php; aqui só a vida dela)
        function aoMostrar() { atualizarTela(); trocarAba(st.aba); }
        regras.iniciar = iniciar;
        regras.coletar = coletar;
        regras.preencher = preencher;
        regras.padraoNovo = padraoNovo;
        regras.aoMostrar = aoMostrar;
        regras.FORMATACOES = FORMATACOES;
    }

    if (typeof module !== 'undefined' && module.exports) module.exports = regras;
    global.PagamentosGuiado = regras;
})(typeof window !== 'undefined' ? window : globalThis);
