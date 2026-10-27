// Assistente "Nova campanha de remarketing" (tela cheia, 5 etapas): Campanha e fluxo -> Público -> Mensagem -> Oferta e entrega -> Revisar e salvar.
// Fala só com remarketing.php (contexto, contar, obter, salvar) e com api.php?action=upload_midia_remarketing (envio da foto/vídeo).
// As regras de verdade ficam no servidor (funcoes/remarketing.php); aqui só a conferência que evita ida e volta e dá o motivo ao lado do botão.
(function ($) {
    const URL_API = 'remarketing';
    const URL_UPLOAD = 'api.php?action=upload_midia_remarketing';
    const TOTAL_PASSOS = 5;
    const MENSAGEM_MIN = 10;
    const MENSAGEM_MAX = 4096;
    const LEGENDA_MAX = 1024;
    const VARIANTES_MAX = 3;
    const PLANOS_MAX = 6;
    const ACESSOS = [['semanal', 'Semanal'], ['mensal', 'Mensal'], ['trimestral', 'Trimestral'], ['semestral', 'Semestral'], ['anual', 'Anual'], ['vitalicio', 'Vitalício'], ['dias', 'Personalizado (dias)']];
    const DIAS_DO_ACESSO = { semanal: 7, mensal: 30, trimestral: 90, semestral: 180, anual: 365, vitalicio: 36500 };
    const SEGMENTOS = { todos: 'Todos os leads', nao_comprou: 'Não compraram', comprou: 'Já compraram', pix_gerado: 'Pix gerado', expirados: 'Expirados', membros_ativos: 'Membros ativos' };
    const PERIODOS = { total: 'Total', hoje: 'Hoje', ontem: 'Ontem', '7d': '7 dias', '30d': '30 dias', custom: 'Escolher' };
    const ROTULOS_PROXIMO = ['Escolher público', 'Criar mensagem', 'Configurar oferta', 'Revisar campanha', 'Criar rascunho'];
    const DICAS = [
        'Dê um nome e escolha o fluxo da campanha.',
        'Defina quem deve receber a campanha e confira o alcance estimado.',
        'Escreva a mensagem e, se quiser, adicione mídia ou variantes.',
        'Planos, redirect e order bump são opcionais.',
        'Confira a campanha completa antes de criar ou salvar as alterações.'
    ];
    const IC = {
        check: '<svg class="ic" viewBox="0 0 24 24"><path d="M20 6L9 17l-5-5"></path></svg>',
        cadeado: '<svg class="ic" viewBox="0 0 24 24"><rect x="4" y="11" width="16" height="10" rx="2"></rect><path d="M8 11V7a4 4 0 0 1 8 0v4"></path></svg>',
        lapis: '<svg class="ic" viewBox="0 0 24 24"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"></path></svg>',
        lixo: '<svg class="ic" viewBox="0 0 24 24"><path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14"></path></svg>',
        mais: '<svg class="ic" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"></path></svg>',
        alerta: '<svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"></circle><path d="M12 8v5M12 16h.01"></path></svg>'
    };

    let ctx = { fluxos: [], minimo_pix: 1.5 };
    let E = novoEstado();
    let icones_originais = {};
    let passo = 1;
    let alcancado = 1;   // etapa mais distante em que o usuário já esteve (campanha em edição: todas)
    let sujo = false;
    let salvando = false;
    let alcance = { total: null, bots: 0, carregando: false };
    let seq_alcance = 0;
    let timer_alcance = null;
    const id_edicao = Number($('#rmw').data('id')) || 0;

    function novoEstado() {
        return {
            id: 0, nome: '', id_fluxo: 0, segmento: 'todos',
            periodo_tipo: 'total', periodo_de: '', periodo_ate: '',
            variantes: [novaVariante()], variante_atual: 0,
            ofertas: {
                planos: [],
                redirect: { ativo: false, texto: 'Ver Prévias', url: '' },
                order_bump: { ativo: false, nome: '', valor: '', descricao: '', midia_caminho: '', midia_tipo: '', _previa: '' }
            }
        };
    }
    function novaVariante() {
        return { mensagem: '', midia_caminho: '', midia_tipo: '', _previa: '' };
    }

    // ---------- utilitários ----------
    function esc(v) {
        return String(v === null || v === undefined ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }
    function brl(v) {
        return 'R$ ' + Number(v || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function num(v) {
        return Number(v || 0).toLocaleString('pt-BR');
    }
    function aviso(msg, tipo) {
        const $t = $('#toast');
        $t.removeClass('sucesso erro visivel').addClass(tipo || 'sucesso').text(msg);
        requestAnimationFrame(function () { $t.addClass('visivel'); });
        clearTimeout(window.__toastTimeout);
        window.__toastTimeout = setTimeout(function () { $t.removeClass('visivel'); }, 3500);
    }
    function fluxoAtual() {
        return ctx.fluxos.find(function (f) { return f.id === E.id_fluxo; }) || null;
    }
    function ehLinkHttp(v) {
        return /^https?:\/\/\S+$/i.test(String(v || '').trim());
    }
    function novoIdPlano() {
        return 'p' + Math.random().toString(36).slice(2, 7);
    }
    function marcarSujo() {
        sujo = true;
    }

    // ---------- conferência de cada etapa (devolve o motivo ou '') ----------
    function erroPlano(p) {
        const nome = (p.nome || '').trim();
        if (!nome) return 'Dê um nome a cada plano.';
        const valor = parseFloat(p.valor);
        if (!(valor >= ctx.minimo_pix)) return 'O preço do plano "' + nome + '" precisa ser pelo menos ' + brl(ctx.minimo_pix) + '.';
        if (p.acesso === 'dias' && !(Number(p.dias) >= 1 && Number(p.dias) <= 3650)) return 'Os dias de acesso do plano "' + nome + '" precisam ficar entre 1 e 3650.';
        if (p.entrega_tipo === 'grupo') {
            const f = fluxoAtual();
            if (!f || !f.grupos.some(function (g) { return g.id === p.id_grupo; })) return 'Escolha o grupo de entrega do plano "' + nome + '".';
        }
        if (p.entrega_tipo === 'link' && !ehLinkHttp(p.link_entrega)) return 'O link de entrega do plano "' + nome + '" precisa começar com https://.';
        if (p.entrega_tipo === 'mensagem' && !(p.mensagem_entrega || '').trim()) return 'Escreva a mensagem de entrega do plano "' + nome + '".';
        return '';
    }
    function erroPasso(n) {
        if (n === 1) {
            if (!E.nome.trim()) return 'Dê um nome à campanha';
            if (!E.id_fluxo) return 'Selecione um fluxo';
            return '';
        }
        if (n === 2) {
            if (E.periodo_tipo === 'custom') {
                if (!E.periodo_de || !E.periodo_ate) return 'Informe a data inicial e a final do período';
                if (E.periodo_de > E.periodo_ate) return 'A data inicial não pode ser depois da final';
            }
            return '';
        }
        if (n === 3) {
            for (let i = 0; i < E.variantes.length; i++) {
                const v = E.variantes[i];
                const quem = E.variantes.length > 1 ? 'da variante ' + String.fromCharCode(65 + i) + ' ' : '';
                const tam = v.mensagem.trim().length;
                if (tam < MENSAGEM_MIN) return 'A mensagem ' + quem + 'deve ter no mínimo ' + MENSAGEM_MIN + ' caracteres';
                const teto = v.midia_caminho ? LEGENDA_MAX : MENSAGEM_MAX;
                if (tam > teto) return 'A mensagem ' + quem + 'passou do limite de ' + num(teto) + ' caracteres';
            }
            return '';
        }
        if (n === 4) {
            for (let i = 0; i < E.ofertas.planos.length; i++) {
                const e = erroPlano(E.ofertas.planos[i]);
                if (e) return e;
            }
            const r = E.ofertas.redirect;
            if (r.ativo && !ehLinkHttp(r.url)) return 'Informe o endereço do botão redirect (começando com https://)';
            const b = E.ofertas.order_bump;
            if (b.ativo) {
                if (!b.nome.trim()) return 'Dê um nome ao order bump';
                if (!(parseFloat(b.valor) > 0)) return 'Informe o preço do order bump';
                if (!E.ofertas.planos.length) return 'O order bump só funciona junto de um plano: adicione um plano';
            }
            return '';
        }
        return '';
    }
    // Até onde o usuário pode ir: no máximo uma etapa além da mais distante que ele já alcançou (as etapas destravam uma a uma, ao "Próximo")
    // e nunca além da primeira etapa com problema.
    function liberado() {
        let limite = TOTAL_PASSOS;
        for (let n = 1; n < TOTAL_PASSOS; n++) {
            if (erroPasso(n)) { limite = n; break; }
        }
        return Math.min(limite, alcancado + 1);
    }

    // ---------- mapa, topo e rodapé ----------
    function resumoPasso(n) {
        const f = fluxoAtual();
        if (n === 1) return E.nome.trim() + (f ? ' · ' + f.nome : '');
        if (n === 2) return SEGMENTOS[E.segmento] + (alcance.total !== null ? ' · ' + num(alcance.total) + ' leads' : '');
        if (n === 3) return E.variantes.length > 1 ? E.variantes.length + ' variantes' : '1 mensagem';
        if (n === 4) {
            const partes = [];
            if (E.ofertas.planos.length) partes.push(E.ofertas.planos.length + (E.ofertas.planos.length === 1 ? ' plano' : ' planos'));
            if (E.ofertas.redirect.ativo) partes.push('redirect ativo');
            if (E.ofertas.order_bump.ativo) partes.push('order bump');
            return partes.length ? partes.join(' · ') : 'Só a mensagem';
        }
        return '';
    }
    function atualizarMapa() {
        const lib = liberado();
        $('#rmw-passos .rmw-passo').each(function () {
            const $p = $(this);
            const n = Number($p.data('passo'));
            const feito = n < TOTAL_PASSOS && n !== passo && n < Math.max(passo, alcancado) && !erroPasso(n);
            const trava = n > lib;
            const $ic = $p.find('.rmw-passo-ic');
            if (!icones_originais[n]) icones_originais[n] = $ic.html();
            if (!$p.data('desc')) $p.data('desc', $p.find('.rmw-passo-desc').text());
            $p.toggleClass('ativo', n === passo).toggleClass('feito', feito && n !== passo && !trava).toggleClass('trava', trava).attr('aria-disabled', trava ? 'true' : null).attr('aria-current', n === passo ? 'step' : null);
            $ic.html(trava ? IC.cadeado : (feito && n !== passo ? IC.check : icones_originais[n]));
            $p.find('.rmw-passo-desc').text(trava ? 'Conclua a etapa atual para continuar' : $p.data('desc'));
            $p.find('.rmw-passo-resumo').text(n < TOTAL_PASSOS && feito ? resumoPasso(n) : '');
        });
        const pct = Math.round(passo / TOTAL_PASSOS * 100);
        $('#rmw-pct').text(pct + '%');
        $('#rmw-barra').css('width', pct + '%');
        $('#rmw-topo-barra').css('width', pct + '%');
        $('#rmw-eyebrow').text('Remarketing · ' + passo + '/' + TOTAL_PASSOS);
    }
    function atualizarRodape() {
        const erro = erroPasso(passo);
        const ultimo = passo === TOTAL_PASSOS;
        $('#rmw-anterior-rot').text(passo === 1 ? 'Cancelar' : 'Voltar');
        $('#rmw-proximo-rot').text(ultimo && id_edicao ? 'Salvar alterações' : ROTULOS_PROXIMO[passo - 1]);
        $('#rmw-proximo').prop('disabled', !!erro || salvando).toggleClass('desativado', !!erro);
        $('#rmw-dica').text(erro || DICAS[passo - 1]).toggleClass('aviso', !!erro);
    }
    function atualizarTudo() {
        atualizarMapa();
        atualizarRodape();
    }

    function ir(n) {
        passo = Math.max(1, Math.min(TOTAL_PASSOS, n));
        alcancado = Math.max(alcancado, passo);
        $('.rmw-etapa').removeClass('ativa').prop('hidden', true);
        $('.rmw-etapa[data-etapa="' + passo + '"]').addClass('ativa').prop('hidden', false);
        if (passo === 2) { desenharPublico(); buscarAlcance(true); }
        if (passo === 3) desenharMensagem();
        if (passo === 4) desenharOferta();
        if (passo === 5) desenharRevisao();
        atualizarTudo();
        $('#rmw-conteudo').scrollTop(0);
        window.scrollTo(0, 0);
    }

    // ---------- etapa 1: nome e fluxo ----------
    function desenharFluxos() {
        const $c = $('#rm-fluxos');
        if (!ctx.fluxos.length) {
            $c.html('<div class="a-empty" style="grid-column:1/-1">Você ainda não tem fluxos. <a href="fluxos">Crie um fluxo</a> e vincule um bot a ele para enviar campanhas.</div>');
            return;
        }
        $c.html(ctx.fluxos.map(function (f) {
            const n = f.bots.length;
            return '<button type="button" class="rmw-fluxo' + (f.id === E.id_fluxo ? ' on' : '') + '" data-fluxo="' + f.id + '"' + (n ? '' : ' disabled') + ' aria-pressed="' + (f.id === E.id_fluxo) + '">' +
                '<b>' + esc(f.nome) + '</b><small>' + (n === 0 ? '0 bots vinculados' : n + (n === 1 ? ' bot vinculado' : ' bots vinculados')) + '</small></button>';
        }).join(''));
        const sem = ctx.fluxos.filter(function (f) { return !f.bots.length; }).length;
        const $av = $('#rm-fluxos-aviso');
        if (sem) {
            $av.prop('hidden', false).find('span').html((sem === 1 ? 'Um fluxo está sem bot vinculado e' : sem + ' fluxos estão sem bot vinculado e') + ' não pode ser escolhido. Vincule um bot a ele em <a href="fluxos">Fluxos</a>.');
        } else {
            $av.prop('hidden', true);
        }
    }
    function desenharNome() {
        $('#rm-nome').val(E.nome);
        $('#rm-nome-cont').text(E.nome.length + '/50 caracteres');
    }

    // ---------- etapa 2: público ----------
    function desenharPublico() {
        $('#rm-periodos .rmw-pill').each(function () { $(this).toggleClass('on', $(this).data('periodo') === E.periodo_tipo).attr('aria-pressed', String($(this).data('periodo') === E.periodo_tipo)); });
        $('#rm-datas').prop('hidden', E.periodo_tipo !== 'custom');
        $('#rm-de').val(E.periodo_de);
        $('#rm-ate').val(E.periodo_ate);
        $('#rm-segs .rmw-seg').each(function () { $(this).toggleClass('on', $(this).data('seg') === E.segmento).attr('aria-pressed', String($(this).data('seg') === E.segmento)); });
        const f = fluxoAtual();
        $('#rm-alcance-fluxo').text(f ? f.nome : '—');
        mostrarAlcance();
    }
    function mostrarAlcance() {
        $('#rm-alcance').text(alcance.carregando ? '...' : (alcance.total === null ? '—' : num(alcance.total) + (alcance.total === 1 ? ' lead' : ' leads')));
    }
    function buscarAlcance(imediato) {
        clearTimeout(timer_alcance);
        const periodo_ok = E.periodo_tipo !== 'custom' || (E.periodo_de && E.periodo_ate && E.periodo_de <= E.periodo_ate);
        if (!E.id_fluxo || !periodo_ok) {
            alcance = { total: null, bots: 0, carregando: false };
            mostrarAlcance();
            return;
        }
        alcance.carregando = true;
        mostrarAlcance();
        timer_alcance = setTimeout(function () {
            const minha = ++seq_alcance;
            $.getJSON(URL_API, { action: 'contar', id_fluxo: E.id_fluxo, segmento: E.segmento, periodo_tipo: E.periodo_tipo, periodo_de: E.periodo_de, periodo_ate: E.periodo_ate }).done(function (r) {
                if (minha !== seq_alcance) return;
                alcance = { total: r.sucesso ? Number(r.total) : null, bots: Number(r.bots) || 0, carregando: false };
            }).fail(function () {
                if (minha !== seq_alcance) return;
                alcance = { total: null, bots: 0, carregando: false };
            }).always(function () {
                if (minha !== seq_alcance) return;
                mostrarAlcance();
                atualizarMapa();
            });
        }, imediato ? 0 : 250);
    }

    // ---------- etapa 3: mensagem, variantes e mídia ----------
    function variante() {
        return E.variantes[E.variante_atual];
    }
    function htmlPrevia(m) {
        const src = m._previa || m.midia_caminho;
        return m.midia_tipo === 'video' ? '<video src="' + esc(src) + '" muted preload="metadata"></video>' : '<img src="' + esc(src) + '" alt="Prévia da mídia">';
    }
    function desenharTile(sel_tile, sel_tirar, m) {
        const $tile = $(sel_tile);
        $tile.find('img, video').remove();
        $tile.toggleClass('cheio', !!m.midia_caminho);
        if (m.midia_caminho) {
            $tile.append(htmlPrevia(m));
            $tile.find('.rmw-midia-rotulo').hide();
        } else {
            $tile.find('.rmw-midia-rotulo').show().html(IC.mais + '<br>' + (sel_tile === '#rm-midia-tile' ? 'Adicionar mídia' : 'Adicionar'));
        }
        $(sel_tirar).prop('hidden', !m.midia_caminho);
    }
    function desenharMensagem() {
        const v = variante();
        const $abas = $('#rm-abas');
        if (E.variantes.length > 1) {
            $abas.prop('hidden', false).html(E.variantes.map(function (_, i) {
                return '<button type="button" class="rmw-aba' + (i === E.variante_atual ? ' on' : '') + '" data-aba="' + i + '">Variante ' + String.fromCharCode(65 + i) +
                    (i > 0 ? ' <span class="x" data-tirar="' + i + '" role="button" aria-label="Remover a variante ' + String.fromCharCode(65 + i) + '" title="Remover variante">✕</span>' : '') + '</button>';
            }).join(''));
            $('#rm-ab-dica').prop('hidden', false);
        } else {
            $abas.prop('hidden', true).empty();
            $('#rm-ab-dica').prop('hidden', true);
        }
        $('#rm-add-variante').prop('disabled', E.variantes.length >= VARIANTES_MAX).attr('title', E.variantes.length >= VARIANTES_MAX ? 'No máximo ' + VARIANTES_MAX + ' variantes' : null);
        $('#rm-texto').val(v.mensagem);
        atualizarContadorTexto();
        desenharTile('#rm-midia-tile', '#rm-midia-tirar', v);
        $('#rm-midia-erro').prop('hidden', true);
    }
    function atualizarContadorTexto() {
        const v = variante();
        $('#rm-texto-cont').text(v.mensagem.length);
        $('#rm-texto-max').text(v.midia_caminho ? LEGENDA_MAX : MENSAGEM_MAX);
    }
    function enviarMidia(arquivo, aoTerminar, sel_erro, $tile) {
        const $erro = $(sel_erro).prop('hidden', true);
        const fd = new FormData();
        fd.append('midia', arquivo);
        fd.append('csrf_token', window.CSRF_TOKEN || '');
        $tile.addClass('enviando').find('.rmw-midia-rotulo').show().text('Enviando...');
        $.ajax({ url: URL_UPLOAD, method: 'POST', data: fd, processData: false, contentType: false, dataType: 'json' }).done(function (r) {
            if (!r || !r.sucesso) {
                $erro.text((r && r.mensagem) || 'Falha ao enviar o arquivo.').prop('hidden', false);
                return;
            }
            aoTerminar({ midia_caminho: r.caminho, midia_tipo: r.tipo, _previa: URL.createObjectURL(arquivo) });
        }).fail(function (xhr) {
            $erro.text((xhr.responseJSON && xhr.responseJSON.mensagem) || 'Falha ao enviar o arquivo.').prop('hidden', false);
        }).always(function () {
            $tile.removeClass('enviando');
        });
    }

    // ---------- etapa 4: planos, redirect e order bump ----------
    function opcoesEntrega(p) {
        const f = fluxoAtual();
        const grupos = f ? f.grupos : [];
        let h = '<option value="nenhuma"' + (p.entrega_tipo === 'nenhuma' ? ' selected' : '') + '>Apenas mensagem (sem entrega)</option>';
        if (grupos.length || p.entrega_tipo === 'grupo') {
            h += '<optgroup label="Grupos do bot">';
            let achou = false;
            grupos.forEach(function (g) {
                const sel = p.entrega_tipo === 'grupo' && p.id_grupo === g.id;
                achou = achou || sel;
                h += '<option value="g:' + esc(g.id) + '"' + (sel ? ' selected' : '') + '>' + esc(g.titulo) + '</option>';
            });
            if (p.entrega_tipo === 'grupo' && !achou) h += '<option value="g:' + esc(p.id_grupo) + '" selected>(grupo indisponível neste fluxo)</option>';
            h += '</optgroup>';
        }
        h += '<option value="link"' + (p.entrega_tipo === 'link' ? ' selected' : '') + '>Link externo</option>';
        h += '<option value="mensagem"' + (p.entrega_tipo === 'mensagem' ? ' selected' : '') + '>Mensagem de entrega (texto)</option>';
        return h;
    }
    function htmlPlano(p, i) {
        return '<div class="rmw-plano" data-idx="' + i + '">' +
            '<div class="rmw-plano-grade">' +
              '<label class="a-fld"><span>Nome</span><input type="text" class="a-in pl-nome" maxlength="40" value="' + esc(p.nome) + '" placeholder="Ex.: VIP mensal"></label>' +
              '<label class="a-fld"><span>Preço (R$)</span><input type="number" class="a-in pl-valor" min="0" step="0.01" inputmode="decimal" value="' + esc(p.valor) + '"></label>' +
              '<label class="a-fld"><span>Período de acesso</span><select class="a-in pl-acesso">' + ACESSOS.map(function (a) { return '<option value="' + a[0] + '"' + (p.acesso === a[0] ? ' selected' : '') + '>' + a[1] + '</option>'; }).join('') + '</select></label>' +
              '<button type="button" class="rmw-ib perigo rmw-plano-apagar pl-apagar" aria-label="Remover o plano ' + esc(p.nome || (i + 1)) + '" title="Remover plano">' + IC.lixo + '</button>' +
            '</div>' +
            '<div class="rmw-plano-entrega">' +
              '<label class="a-fld"><span>Entrega</span><select class="a-in pl-entrega">' + opcoesEntrega(p) + '</select></label>' +
              (p.acesso === 'dias' ? '<label class="a-fld"><span>Dias de acesso</span><input type="number" class="a-in pl-dias" min="1" max="3650" value="' + esc(p.dias) + '"></label>' : '') +
              (p.entrega_tipo === 'link' ? '<label class="a-fld"><span>Link de entrega</span><input type="url" class="a-in pl-link" placeholder="https://..." value="' + esc(p.link_entrega) + '"></label>' : '') +
              (p.entrega_tipo === 'mensagem' ? '<label class="a-fld rmw-plano-mensagem"><span>Mensagem de entrega</span><textarea class="a-in pl-mensagem" rows="4" maxlength="3000" placeholder="Ex.: Seu acesso: https://...">' + esc(p.mensagem_entrega || '') + '</textarea><small>O cliente recebe este texto numa mensagem própria, logo depois do aviso de pagamento aprovado.</small></label>' : '') +
            '</div>' +
          '</div>';
    }
    function desenharPlanos() {
        const $c = $('#rm-planos');
        if (!E.ofertas.planos.length) {
            $c.html('<div class="rmw-plano-vazio"><b>Nenhum plano adicionado</b><span>Adicione planos do fluxo ou crie novos.</span></div>');
        } else {
            $c.html(E.ofertas.planos.map(htmlPlano).join(''));
        }
        const f = fluxoAtual();
        $('#rm-plano-fluxo').prop('hidden', !(f && f.planos.length));
        $('#rm-plano-novo').prop('disabled', E.ofertas.planos.length >= PLANOS_MAX).attr('title', E.ofertas.planos.length >= PLANOS_MAX ? 'No máximo ' + PLANOS_MAX + ' planos' : null);
        $('#rm-menu-planos').prop('hidden', true);
    }
    function definirChave(sel_chave, sel_corpo, ligado) {
        $(sel_chave).attr('aria-checked', String(!!ligado));
        $(sel_corpo).prop('hidden', !ligado);
    }
    function desenharOferta() {
        desenharPlanos();
        const r = E.ofertas.redirect;
        definirChave('#rm-redirect-chave', '#rm-redirect-corpo', r.ativo);
        $('#rm-redirect-texto').val(r.texto);
        $('#rm-redirect-url').val(r.url);
        const b = E.ofertas.order_bump;
        definirChave('#rm-bump-chave', '#rm-bump-corpo', b.ativo);
        $('#rm-bump-nome').val(b.nome);
        $('#rm-bump-valor').val(b.valor);
        $('#rm-bump-desc').val(b.descricao);
        desenharTile('#rm-bump-tile', '#rm-bump-tirar', b);
        $('#rm-bump-erro').prop('hidden', true);
    }
    function planoPadrao() {
        const f = fluxoAtual();
        const g = f && f.grupos.length ? f.grupos[0] : null;
        return { id: novoIdPlano(), nome: '', valor: '', acesso: 'mensal', dias: 30, entrega_tipo: g ? 'grupo' : 'nenhuma', id_grupo: g ? g.id : '', link_entrega: '', mensagem_entrega: '' };
    }
    function adicionarPlano(p) {
        if (E.ofertas.planos.length >= PLANOS_MAX) {
            aviso('No máximo ' + PLANOS_MAX + ' planos por campanha.', 'erro');
            return;
        }
        E.ofertas.planos.push(p);
        marcarSujo();
        desenharPlanos();
        atualizarTudo();
        $('#rm-planos .rmw-plano').last().find('.pl-nome').trigger('focus');
    }

    // ---------- etapa 5: revisão ----------
    function desenharRevisao() {
        const f = fluxoAtual();
        const v0 = E.variantes[0];
        const planos = E.ofertas.planos;
        const partes = [];
        if (planos.length) partes.push(planos.length + (planos.length === 1 ? ' plano' : ' planos'));
        if (E.ofertas.redirect.ativo) partes.push('Botão redirect');
        if (E.ofertas.order_bump.ativo) partes.push('Order bump');
        const periodo = E.periodo_tipo === 'custom' ? E.periodo_de.split('-').reverse().join('/') + ' a ' + E.periodo_ate.split('-').reverse().join('/') : PERIODOS[E.periodo_tipo];
        const leads = alcance.total === null ? '' : num(alcance.total) + (alcance.total === 1 ? ' lead' : ' leads');
        const item = function (n, titulo, destaque, detalhe) {
            return '<div class="rmw-rev-item"><small>' + titulo + '</small><b>' + destaque + '</b>' + detalhe +
                '<button type="button" class="rmw-rev-edit" data-passo="' + n + '" aria-label="Editar ' + esc(titulo) + '" title="Editar">' + IC.lapis + '</button></div>';
        };
        $('#rm-rev').html(
            item(1, 'Nome da campanha', esc(E.nome.trim()), '<span>' + (leads ? leads : '') + '</span>') +
            item(2, 'Segmentação', esc(SEGMENTOS[E.segmento]), '<div class="rmw-chips"><span class="rmw-chip">' + esc(f ? f.nome : '—') + '</span><span class="rmw-chip">' + esc(periodo) + '</span></div>') +
            item(3, 'Conteúdo', E.variantes.length > 1 ? E.variantes.length + ' variantes (A/B)' : 'Mensagem (' + v0.mensagem.trim().length + ' caracteres)',
                '<span>' + esc(v0.mensagem.trim().slice(0, 90)) + (v0.mensagem.trim().length > 90 ? '…' : '') + (v0.midia_caminho ? ' · com ' + (v0.midia_tipo === 'video' ? 'vídeo' : 'foto') : '') + '</span>') +
            item(4, 'Ofertas', partes.length ? esc(partes.join(' + ')) : 'Só a mensagem',
                planos.length ? '<span>' + planos.map(function (p) { return esc(p.nome) + ' — ' + brl(parseFloat(p.valor)); }).join('<br>') + '</span>' : '<span>Sem botão de compra: a campanha envia só a mensagem.</span>')
        );
        $('#rm-prox-texto').text(id_edicao
            ? 'As alterações valem para esta campanha. Nada é enviado até você iniciá-la na lista.'
            : 'O rascunho terá alcance estimado de ' + (alcance.total === null ? 'N' : num(alcance.total)) + ' leads. Nada será enviado agora; você poderá iniciar a campanha depois de revisar o rascunho criado.');
        $('#rm-rev-sub').text(id_edicao ? 'Confira as informações antes de salvar as alterações.' : 'Confira as informações antes de criar o rascunho.');
        $('#rm-salvar-erro').prop('hidden', true);
    }

    // ---------- salvar ----------
    function montarPayload() {
        const o = E.ofertas;
        return {
            acao: 'salvar',
            id: E.id,
            nome: E.nome.trim(),
            id_fluxo: E.id_fluxo,
            segmento: E.segmento,
            periodo_tipo: E.periodo_tipo,
            periodo_de: E.periodo_tipo === 'custom' ? E.periodo_de : '',
            periodo_ate: E.periodo_tipo === 'custom' ? E.periodo_ate : '',
            variantes: E.variantes.map(function (v) { return { mensagem: v.mensagem.trim(), midia_caminho: v.midia_caminho, midia_tipo: v.midia_tipo }; }),
            ofertas: {
                planos: o.planos.map(function (p) {
                    return { id: p.id, nome: p.nome.trim(), valor: parseFloat(p.valor), acesso: p.acesso, dias: p.acesso === 'dias' ? Number(p.dias) : (DIAS_DO_ACESSO[p.acesso] || 30), entrega_tipo: p.entrega_tipo, id_grupo: p.entrega_tipo === 'grupo' ? p.id_grupo : '', link_entrega: p.entrega_tipo === 'link' ? p.link_entrega.trim() : '', mensagem_entrega: p.entrega_tipo === 'mensagem' ? (p.mensagem_entrega || '').trim() : '' };
                }),
                redirect: { ativo: o.redirect.ativo, texto: o.redirect.texto.trim() || 'Ver Prévias', url: o.redirect.url.trim() },
                order_bump: { ativo: o.order_bump.ativo, nome: o.order_bump.nome.trim(), valor: parseFloat(o.order_bump.valor) || 0, descricao: o.order_bump.descricao.trim(), midia_caminho: o.order_bump.midia_caminho, midia_tipo: o.order_bump.midia_tipo }
            }
        };
    }
    function salvar() {
        if (salvando) return;
        salvando = true;
        atualizarRodape();
        $('#rm-salvar-erro').prop('hidden', true);
        $.ajax({ url: URL_API, method: 'POST', contentType: 'application/json', data: JSON.stringify(montarPayload()), dataType: 'json' }).done(function (r) {
            try { sessionStorage.setItem('rm_aviso', r.mensagem || 'Campanha salva.'); } catch (e) {}
            sujo = false;
            window.location.href = 'remarketing';
        }).fail(function (xhr) {
            salvando = false;
            atualizarRodape();
            $('#rm-salvar-erro').text((xhr.responseJSON && (xhr.responseJSON.mensagem || xhr.responseJSON.erro)) || 'Não foi possível salvar a campanha.').prop('hidden', false);
        });
    }

    // ---------- carregar ----------
    function popular(c) {
        alcancado = TOTAL_PASSOS;
        E.id = c.id;
        E.nome = c.nome;
        E.id_fluxo = c.id_fluxo;
        E.segmento = c.segmento;
        E.periodo_tipo = c.periodo_tipo;
        E.periodo_de = c.periodo_de || '';
        E.periodo_ate = c.periodo_ate || '';
        E.variantes = c.variantes.map(function (v) { return { mensagem: v.mensagem, midia_caminho: v.midia_caminho || '', midia_tipo: v.midia_tipo || '', _previa: '' }; });
        E.ofertas.planos = c.ofertas.planos.map(function (p) { return $.extend({ dias: DIAS_DO_ACESSO[p.acesso] || 30, link_entrega: '', id_grupo: '', mensagem_entrega: '' }, p, { valor: String(p.valor) }); });
        E.ofertas.redirect = c.ofertas.redirect;
        E.ofertas.order_bump = $.extend({ _previa: '' }, c.ofertas.order_bump, { valor: c.ofertas.order_bump.valor ? String(c.ofertas.order_bump.valor) : '' });
    }
    function iniciar() {
        const pedidos = [$.getJSON(URL_API, { action: 'contexto' })];
        if (id_edicao) pedidos.push($.getJSON(URL_API, { action: 'obter', id: id_edicao }));
        $.when.apply($, pedidos).done(function (a, b) {
            const c = pedidos.length === 1 ? a : a[0];
            ctx = { fluxos: c.fluxos || [], minimo_pix: Number(c.minimo_pix) || 1.5 };
            if (id_edicao) {
                const resp = b[0];
                if (!resp.sucesso || !resp.campanha.editavel) {
                    $('#rmw-carregando').text(resp.sucesso ? 'Esta campanha já foi enviada e não pode mais ser editada.' : (resp.mensagem || 'Campanha não encontrada.'));
                    $('#rmw-proximo').prop('disabled', true);
                    return;
                }
                popular(resp.campanha);
            }
            $('#rmw-carregando').remove();
            desenharNome();
            desenharFluxos();
            ir(1);
        }).fail(function (xhr) {
            $('#rmw-carregando').text((xhr && xhr.responseJSON && xhr.responseJSON.mensagem) || 'Não foi possível carregar o assistente. Recarregue a página.');
        });
    }

    // ---------- eventos ----------
    $(function () {
        // topo e rodapé
        $('#rmw-passos').on('click', '.rmw-passo', function () {
            const n = Number($(this).data('passo'));
            if (n <= liberado()) ir(n);
        });
        $('#rmw-anterior').on('click', function () {
            if (passo === 1) {
                if (!sujo || window.confirm('Descartar o que você preencheu?')) window.location.href = 'remarketing';
                return;
            }
            ir(passo - 1);
        });
        $('#rmw-proximo').on('click', function () {
            if (erroPasso(passo)) return;
            passo === TOTAL_PASSOS ? salvar() : ir(passo + 1);
        });
        $('#rmw-voltar, #rmw-fechar').on('click', function (e) {
            if (sujo && !window.confirm('Descartar o que você preencheu?')) e.preventDefault();
        });

        // dirty + recálculo do mapa em qualquer digitação
        $('#rmw-conteudo').on('input change', function () { marcarSujo(); atualizarTudo(); });

        // 1. nome e fluxo
        $('#rm-nome').on('input', function () {
            E.nome = $(this).val();
            $('#rm-nome-cont').text(E.nome.length + '/50 caracteres');
        });
        $('#rm-fluxos').on('click', '.rmw-fluxo:not(:disabled)', function () {
            const novo = Number($(this).data('fluxo'));
            if (novo !== E.id_fluxo) {
                E.id_fluxo = novo;
                // Os grupos de entrega pertencem aos bots do fluxo: ao trocar de fluxo, plano que apontava para um grupo do outro volta a "apenas mensagem".
                const f = fluxoAtual();
                E.ofertas.planos.forEach(function (p) {
                    if (p.entrega_tipo === 'grupo' && !(f && f.grupos.some(function (g) { return g.id === p.id_grupo; }))) {
                        p.entrega_tipo = 'nenhuma';
                        p.id_grupo = '';
                    }
                });
                alcance = { total: null, bots: 0, carregando: false };
            }
            desenharFluxos();
            marcarSujo();
            atualizarTudo();
        });

        // 2. público
        $('#rm-periodos').on('click', '.rmw-pill', function () {
            E.periodo_tipo = String($(this).data('periodo'));
            marcarSujo();
            desenharPublico();
            buscarAlcance();
            atualizarTudo();
        });
        $('#rm-de, #rm-ate').on('input change', function () {
            E.periodo_de = $('#rm-de').val();
            E.periodo_ate = $('#rm-ate').val();
            buscarAlcance();
            atualizarTudo();
        });
        $('#rm-segs').on('click', '.rmw-seg', function () {
            E.segmento = String($(this).data('seg'));
            marcarSujo();
            desenharPublico();
            buscarAlcance();
            atualizarTudo();
        });

        // 3. mensagem
        $('#rm-texto').on('input', function () {
            variante().mensagem = $(this).val();
            atualizarContadorTexto();
        });
        $('#rmw-conteudo').on('click', '.rmw-var', function () {
            const el = document.getElementById('rm-texto');
            const v = String($(this).data('var'));
            const ini = el.selectionStart === undefined ? el.value.length : el.selectionStart;
            const fim = el.selectionEnd === undefined ? ini : el.selectionEnd;
            el.value = el.value.slice(0, ini) + v + el.value.slice(fim);
            el.selectionStart = el.selectionEnd = ini + v.length;
            el.focus();
            variante().mensagem = el.value;
            atualizarContadorTexto();
            marcarSujo();
            atualizarTudo();
        });
        $('#rm-add-variante').on('click', function () {
            if (E.variantes.length >= VARIANTES_MAX) return;
            E.variantes.push(novaVariante());
            E.variante_atual = E.variantes.length - 1;
            marcarSujo();
            desenharMensagem();
            atualizarTudo();
        });
        $('#rm-abas').on('click', '.rmw-aba', function (e) {
            const $x = $(e.target).closest('.x');
            if ($x.length) {
                const i = Number($x.data('tirar'));
                E.variantes.splice(i, 1);
                E.variante_atual = Math.min(E.variante_atual, E.variantes.length - 1);
                marcarSujo();
            } else {
                E.variante_atual = Number($(this).data('aba'));
            }
            desenharMensagem();
            atualizarTudo();
        });
        $('#rm-midia-arq').on('change', function () {
            const arquivo = this.files && this.files[0];
            this.value = '';
            if (!arquivo) return;
            const v = variante();
            enviarMidia(arquivo, function (m) { $.extend(v, m); marcarSujo(); if (v === variante()) desenharMensagem(); atualizarTudo(); }, '#rm-midia-erro', $('#rm-midia-tile'));
        });
        $('#rm-midia-tile').on('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); $('#rm-midia-arq').trigger('click'); } });
        $('#rm-midia-tirar').on('click', function () {
            $.extend(variante(), { midia_caminho: '', midia_tipo: '', _previa: '' });
            marcarSujo();
            desenharMensagem();
            atualizarTudo();
        });

        // 4. planos
        $('#rm-plano-novo').on('click', function () { adicionarPlano(planoPadrao()); });
        $('#rm-plano-fluxo').on('click', function (e) {
            e.stopPropagation();
            const f = fluxoAtual();
            const $m = $('#rm-menu-planos');
            if (!$m.prop('hidden')) { $m.prop('hidden', true); return; }
            $m.html(f.planos.map(function (p, i) {
                return '<button type="button" data-i="' + i + '">' + esc(p.nome) + '<small>' + brl(p.valor) + '</small></button>';
            }).join('')).prop('hidden', false);
        });
        $('#rm-menu-planos').on('click', 'button', function () {
            const p = fluxoAtual().planos[Number($(this).data('i'))];
            adicionarPlano({ id: novoIdPlano(), nome: p.nome, valor: String(p.valor), acesso: p.acesso, dias: p.dias || 30, entrega_tipo: p.entrega_tipo, id_grupo: p.id_grupo || '', link_entrega: p.link_entrega || '', mensagem_entrega: p.mensagem_entrega || '' });
        });
        $(document).on('click', function (e) {
            if (!$(e.target).closest('#rm-menu-planos, #rm-plano-fluxo').length) $('#rm-menu-planos').prop('hidden', true);
        });
        function planoDe(el) { return E.ofertas.planos[Number($(el).closest('.rmw-plano').data('idx'))]; }
        $('#rm-planos').on('input', '.pl-nome', function () { planoDe(this).nome = $(this).val(); });
        $('#rm-planos').on('input', '.pl-valor', function () { planoDe(this).valor = $(this).val(); });
        $('#rm-planos').on('input', '.pl-dias', function () { planoDe(this).dias = $(this).val(); });
        $('#rm-planos').on('input', '.pl-link', function () { planoDe(this).link_entrega = $(this).val(); });
        $('#rm-planos').on('input', '.pl-mensagem', function () { planoDe(this).mensagem_entrega = $(this).val(); });
        $('#rm-planos').on('change', '.pl-acesso', function () {
            const p = planoDe(this);
            p.acesso = $(this).val();
            if (p.acesso !== 'dias') p.dias = DIAS_DO_ACESSO[p.acesso];
            desenharPlanos();
        });
        $('#rm-planos').on('change', '.pl-entrega', function () {
            const p = planoDe(this);
            const v = String($(this).val());
            p.entrega_tipo = v === 'link' ? 'link' : (v === 'mensagem' ? 'mensagem' : (v.indexOf('g:') === 0 ? 'grupo' : 'nenhuma'));
            p.id_grupo = p.entrega_tipo === 'grupo' ? v.slice(2) : '';
            desenharPlanos();
        });
        $('#rm-planos').on('click', '.pl-apagar', function () {
            E.ofertas.planos.splice(Number($(this).closest('.rmw-plano').data('idx')), 1);
            marcarSujo();
            desenharPlanos();
            atualizarTudo();
        });
        $('#rm-redirect-chave').on('click', function () {
            E.ofertas.redirect.ativo = !E.ofertas.redirect.ativo;
            definirChave('#rm-redirect-chave', '#rm-redirect-corpo', E.ofertas.redirect.ativo);
            marcarSujo();
            atualizarTudo();
        });
        $('#rm-redirect-texto').on('input', function () { E.ofertas.redirect.texto = $(this).val(); });
        $('#rm-redirect-url').on('input', function () { E.ofertas.redirect.url = $(this).val(); });
        $('#rm-bump-chave').on('click', function () {
            E.ofertas.order_bump.ativo = !E.ofertas.order_bump.ativo;
            definirChave('#rm-bump-chave', '#rm-bump-corpo', E.ofertas.order_bump.ativo);
            marcarSujo();
            atualizarTudo();
        });
        $('#rm-bump-nome').on('input', function () { E.ofertas.order_bump.nome = $(this).val(); });
        $('#rm-bump-valor').on('input', function () { E.ofertas.order_bump.valor = $(this).val(); });
        $('#rm-bump-desc').on('input', function () { E.ofertas.order_bump.descricao = $(this).val(); });
        $('#rm-bump-arq').on('change', function () {
            const arquivo = this.files && this.files[0];
            this.value = '';
            if (!arquivo) return;
            enviarMidia(arquivo, function (m) { $.extend(E.ofertas.order_bump, m); marcarSujo(); desenharTile('#rm-bump-tile', '#rm-bump-tirar', E.ofertas.order_bump); atualizarTudo(); }, '#rm-bump-erro', $('#rm-bump-tile'));
        });
        $('#rm-bump-tile').on('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); $('#rm-bump-arq').trigger('click'); } });
        $('#rm-bump-tirar').on('click', function () {
            $.extend(E.ofertas.order_bump, { midia_caminho: '', midia_tipo: '', _previa: '' });
            marcarSujo();
            desenharTile('#rm-bump-tile', '#rm-bump-tirar', E.ofertas.order_bump);
            atualizarTudo();
        });

        // 5. revisão
        $('#rm-rev').on('click', '.rmw-rev-edit', function () { ir(Number($(this).data('passo'))); });

        iniciar();
    });
})(jQuery);
