// Lista de campanhas de remarketing: cartões, campanhas agrupadas por fluxo, busca/filtro/ordem e as ações (iniciar, agendar, excluir).
// Os dados vêm de remarketing.php?action=listar; as ações vão por POST em JSON (o token CSRF segue no cabeçalho, via $.ajaxSetup da página).
(function ($) {
    const URL_API = 'remarketing';
    let campanhas = [];
    let fechados = {};   // grupos recolhidos (só nesta visita)

    const ic = {
        alvo: '<svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"></circle><circle cx="12" cy="12" r="5"></circle><circle cx="12" cy="12" r="1"></circle></svg>',
        megafone: '<svg class="ic" viewBox="0 0 24 24"><path d="M3 11l18-5v12L3 14v-3zM11.6 16.8a3 3 0 1 1-5.8-1.6"></path></svg>',
        fluxo: '<svg class="ic" viewBox="0 0 24 24"><circle cx="6" cy="18" r="3"></circle><circle cx="18" cy="6" r="3"></circle><path d="M18 9a9 9 0 0 1-9 9"></path></svg>',
        play: '<svg class="ic" viewBox="0 0 24 24"><polygon points="6 4 20 12 6 20 6 4"></polygon></svg>',
        giro: '<svg class="ic rm-giro" viewBox="0 0 24 24"><path d="M21 12a9 9 0 1 1-6.2-8.55"></path></svg>',
        relogio: '<svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"></circle><path d="M12 7v5l3 2"></path></svg>',
        ok: '<svg class="ic" viewBox="0 0 24 24"><path d="M20 6L9 17l-5-5"></path></svg>',
        alerta: '<svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"></circle><path d="M12 8v5M12 16h.01"></path></svg>',
        calendario: '<svg class="ic" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"></rect><path d="M16 2v4M8 2v4M3 10h18"></path></svg>',
        grafico: '<svg class="ic" viewBox="0 0 24 24"><path d="M4 20V10M10 20V4M16 20v-8M22 20H2"></path></svg>',
        engrenagem: '<svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"></path></svg>',
        baixar: '<svg class="ic" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"></path></svg>',
        lixo: '<svg class="ic" viewBox="0 0 24 24"><path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14"></path></svg>',
        seta: '<svg class="ic" viewBox="0 0 24 24"><path d="M6 9l6 6 6-6"></path></svg>',
        refazer: '<svg class="ic" viewBox="0 0 24 24"><path d="M21 12a9 9 0 0 1-15 6.7L3 16M3 12a9 9 0 0 1 15-6.7L21 8M21 3v5h-5M3 21v-5h5"></path></svg>',
        bloqueio: '<svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"></circle><path d="M5.6 5.6l12.8 12.8"></path></svg>',
        mais: '<svg class="ic" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"></path></svg>'
    };

    function esc(v) {
        return String(v === null || v === undefined ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }
    function brl(v) {
        return 'R$ ' + Number(v || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function num(v) {
        return Number(v || 0).toLocaleString('pt-BR');
    }
    function plural(n, um, varios) {
        return num(n) + ' ' + (Number(n) === 1 ? um : varios);
    }
    function aviso(msg, tipo) {
        const $t = $('#toast');
        $t.removeClass('sucesso erro visivel').addClass(tipo || 'sucesso').text(msg);
        requestAnimationFrame(function () { $t.addClass('visivel'); });
        clearTimeout(window.__toastTimeout);
        window.__toastTimeout = setTimeout(function () { $t.removeClass('visivel'); }, 3500);
    }
    function erroDe(xhr, padrao) {
        return (xhr && xhr.responseJSON && (xhr.responseJSON.mensagem || xhr.responseJSON.erro)) || padrao;
    }
    function acao(dados) {
        return $.ajax({ url: URL_API, method: 'POST', contentType: 'application/json', data: JSON.stringify(dados), dataType: 'json' });
    }

    // ---------- desenho ----------
    function badge(c) {
        const mapa = { rascunho: 'or', pendente: 'wa', processando: 'ok', concluida: '', falha: 'er' };
        const classe = c.em_envio ? 'ok' : (mapa[c.status] || '');
        return '<span class="a-bd ' + classe + '">' + (c.em_envio ? '<i></i>' : '') + esc(c.em_envio ? 'Ativa' : c.status_rotulo) + '</span>';
    }
    // "26/10 17:12" vira "17:12" quando é hoje (cabe no botão do cartão).
    function quando(dm_hm) {
        const d = new Date();
        const hoje = String(d.getDate()).padStart(2, '0') + '/' + String(d.getMonth() + 1).padStart(2, '0') + ' ';
        return String(dm_hm).indexOf(hoje) === 0 ? String(dm_hm).slice(hoje.length) : String(dm_hm);
    }
    function estado(c) {
        if (c.em_envio) return '<span class="rm-estado ativa">' + ic.giro + ' Em execução</span>';
        if (c.retida) {
            return '<span class="rm-estado espera" title="Outra campanha deste fluxo está em envio ou enviou há pouco. Esta sai sozinha quando o fluxo liberar (um fluxo só envia remarketing a cada 3 horas).">' +
                ic.relogio + ' ' + (c.libera_em ? 'Sai às ' + esc(quando(c.libera_em)) : 'Espera o fluxo') + '</span>';
        }
        if (c.status === 'pendente') return '<span class="rm-estado agendada" title="' + (c.recorrente ? 'Repete: ' + esc(c.recorrencia_rotulo) : 'Envio agendado') + '">' + ic.relogio + ' ' + esc(c.agendado_em) + '</span>';
        if (c.status === 'rascunho' || c.reenviavel) {
            const rascunho = c.status === 'rascunho';
            const rotulo = rascunho ? 'Iniciar' : 'Reenviar';
            // Um fluxo só envia remarketing a cada 3 horas: com o fluxo ocupado ou envio recente, o botão vira um aviso de quando libera.
            if (c.bloqueio) {
                return '<span class="rm-estado espera rm-bloqueado" title="' + esc(c.bloqueio.mensagem) + '">' + ic.relogio + ' ' +
                    (c.bloqueio.libera_em ? 'Libera às ' + esc(quando(c.bloqueio.libera_em)) : 'Fluxo em envio') + '</span>';
            }
            return '<button type="button" class="rm-estado iniciar ' + (rascunho ? 'rm-iniciar' : 'rm-reenviar') + '" data-id="' + c.id + '">' + (rascunho ? ic.play : ic.refazer) + ' ' + rotulo + '</button>';
        }
        return '<span class="rm-estado ok">' + ic.ok + ' Concluída</span>';
    }
    function cartao(c) {
        const pode_agendar = c.agendavel || (c.status === 'processando' && c.recorrente);
        const repete = (c.recorrente || c.execucoes > 1)
            ? '<div class="rm-meta rm-repete">' + (c.recorrente ? '<span>' + ic.refazer + ' Repete: ' + esc(c.recorrencia_rotulo) + '</span>' : '') +
              (c.execucoes > 1 ? '<span>' + plural(c.execucoes, 'execução', 'execuções') + '</span>' : '') + '</div>'
            : '';
        const editar = c.editavel
            ? '<a class="rm-ib rm-editar" href="remarketing_nova?id=' + c.id + '" title="Configurar campanha" aria-label="Configurar a campanha ' + esc(c.nome) + '">' + ic.engrenagem + '</a>'
            : '<span class="rm-ib off" title="' + (c.grupo_antigo ? 'Campanha criada no modelo antigo: não dá para editar' : 'Já enviada: não dá mais para editar') + '">' + ic.engrenagem + '</span>';
        const baixar = c.tem_log
            ? '<a class="rm-ib rm-baixar" href="remarketing?action=exportar&id=' + c.id + '" title="Baixar o registro de envios (CSV)" aria-label="Baixar os envios da campanha ' + esc(c.nome) + '">' + ic.baixar + '</a>'
            : '<span class="rm-ib off" title="Ainda não há envios para baixar">' + ic.baixar + '</span>';
        return '' +
            '<div class="rm-camp" data-id="' + c.id + '" data-status="' + esc(c.status) + '">' +
              '<div class="rm-camp-top">' +
                '<span class="rm-camp-ic">' + ic.megafone + '</span>' +
                '<div class="rm-camp-id"><h3>' + esc(c.nome) + '</h3>' + badge(c) + '<span class="rm-camp-seg">' + esc(c.segmento) + '</span></div>' +
              '</div>' +
              '<div class="rm-nums">' +
                '<div><small>Total</small><b>' + num(c.total) + '</b></div>' +
                '<div><small>Enviadas</small><b class="ok">' + num(c.enviadas) + '</b></div>' +
                '<div><small>Receita</small><b>' + brl(c.receita) + '</b></div>' +
              '</div>' +
              '<div class="rm-meta"><span>' + ic.refazer + ' ' + plural(c.restantes, 'restante', 'restantes') + '</span><span>' + ic.bloqueio + ' ' + plural(c.falhas, 'falha', 'falhas') + '</span></div>' +
              repete +
              '<div class="rm-camp-acts">' + estado(c) + '<div class="rm-icones">' +
                (pode_agendar ? '<button type="button" class="rm-ib rm-agendar" data-id="' + c.id + '" title="' + (c.recorrente ? 'Agendamento e recorrência' : 'Agendar o envio') + '" aria-label="Agendar a campanha ' + esc(c.nome) + '">' + ic.calendario + '</button>' : '<span class="rm-ib off">' + ic.calendario + '</span>') +
                '<a class="rm-ib" href="remarketing_detalhes?campanha=' + c.id + '" title="Estatísticas" aria-label="Estatísticas da campanha ' + esc(c.nome) + '">' + ic.grafico + '</a>' +
                editar + baixar +
                '<button type="button" class="rm-ib perigo rm-excluir" data-id="' + c.id + '" title="Excluir campanha" aria-label="Excluir a campanha ' + esc(c.nome) + '">' + ic.lixo + '</button>' +
              '</div></div>' +
            '</div>';
    }

    function filtradas() {
        const termo = $('#rm-busca').val().trim().toLowerCase();
        const situacao = $('#rm-status').val();
        const ordem = $('#rm-ordem').val();
        let lista = campanhas.filter(function (c) {
            if (situacao) {
                const chave = c.em_envio ? 'processando' : c.status;
                if (chave !== situacao) return false;
            }
            return !termo || (c.nome + ' ' + c.grupo_nome + ' ' + c.segmento).toLowerCase().indexOf(termo) !== -1;
        });
        const por = {
            recentes: function (a, b) { return String(b.criado_em).localeCompare(String(a.criado_em)) || b.id - a.id; },
            antigas: function (a, b) { return String(a.criado_em).localeCompare(String(b.criado_em)) || a.id - b.id; },
            receita: function (a, b) { return b.receita - a.receita || b.id - a.id; },
            enviadas: function (a, b) { return b.enviadas - a.enviadas || b.id - a.id; },
            nome: function (a, b) { return a.nome.localeCompare(b.nome, 'pt-BR'); }
        };
        return lista.sort(por[ordem] || por.recentes);
    }

    function desenhar() {
        const $lista = $('#rm-lista');
        if (!campanhas.length) {
            $lista.html('<div class="rm-vazio"><span class="rm-vazio-ic">' + ic.alvo + '</span><h3>Pronto para começar?</h3>' +
                '<p>Use o botão <b>Nova campanha</b>, no topo da página, para criar a primeira e recuperar quem não comprou ou engajar quem já é cliente.</p></div>');
            return;
        }
        const lista = filtradas();
        if (!lista.length) {
            $lista.html('<div class="a-empty">Nenhuma campanha encontrada com esses filtros.</div>');
            return;
        }
        const grupos = [];
        const indice = {};
        lista.forEach(function (c) {
            if (!(c.grupo in indice)) {
                indice[c.grupo] = grupos.length;
                grupos.push({ chave: c.grupo, nome: c.grupo_nome, bots: c.grupo_bots, antigo: c.grupo_antigo, campanhas: [] });
            }
            grupos[indice[c.grupo]].campanhas.push(c);
        });
        $lista.html(grupos.map(function (g) {
            const enviadas = g.campanhas.reduce(function (s, c) { return s + c.enviadas; }, 0);
            const receita = g.campanhas.reduce(function (s, c) { return s + c.receita; }, 0);
            const aberto = !fechados[g.chave];
            return '<article class="a-card rm-grupo' + (aberto ? '' : ' fechado') + '" data-grupo="' + esc(g.chave) + '">' +
                '<button type="button" class="rm-grupo-cab" aria-expanded="' + aberto + '">' +
                  '<span class="rm-grupo-ic">' + ic.fluxo + '</span>' +
                  '<span class="rm-grupo-tit"><b>' + esc(g.nome) + '</b><small>' + plural(g.campanhas.length, 'campanha', 'campanhas') + ' · ' + plural(enviadas, 'enviada', 'enviadas') + ' · ' + brl(receita) + (g.antigo ? '' : ' · ' + plural(g.bots, 'bot', 'bots')) + '</small></span>' +
                  '<span class="rm-chev">' + ic.seta + '</span>' +
                '</button>' +
                '<div class="rm-grupo-corpo"><div class="rm-cards">' + g.campanhas.map(cartao).join('') + '</div></div>' +
              '</article>';
        }).join(''));
    }

    function resumo(r) {
        $('#kpi-total').text(num(r.total));
        $('#kpi-ativas').text(num(r.ativas));
        $('#kpi-enviadas').text(num(r.enviadas));
        $('#kpi-receita').text(brl(r.receita));
    }

    function carregar() {
        $.getJSON(URL_API + '?action=listar').done(function (resp) {
            if (!resp.sucesso) {
                $('#rm-lista').html('<div class="a-empty er">' + esc(resp.mensagem || 'Não foi possível carregar as campanhas.') + '</div>');
                return;
            }
            campanhas = resp.campanhas || [];
            resumo(resp.resumo || {});
            desenhar();
        }).fail(function () {
            $('#rm-lista').html('<div class="a-empty er">Não foi possível carregar as campanhas. Recarregue a página.</div>');
        });
    }

    // ---------- confirmação e agendamento ----------
    function confirmar(titulo, texto, rotulo_sim, perigo) {
        const defer = $.Deferred();
        $('#rm-confirma-titulo').text(titulo);
        $('#rm-confirma-texto').text(texto);
        $('#rm-confirma-sim').text(rotulo_sim).toggleClass('dg', !!perigo).toggleClass('pri', !perigo);
        const $m = $('#rm-confirma').addClass('aberto');
        function fechar(valor) {
            $m.removeClass('aberto');
            $('#rm-confirma-sim, #rm-confirma-nao').off('.rmc');
            $m.off('.rmc');
            $(document).off('keydown.rmc');
            valor ? defer.resolve() : defer.reject();
        }
        $('#rm-confirma-sim').on('click.rmc', function () { fechar(true); }).trigger('focus');
        $('#rm-confirma-nao').on('click.rmc', function () { fechar(false); });
        $m.on('click.rmc', function (e) { if (e.target === this) fechar(false); });
        $(document).on('keydown.rmc', function (e) { if (e.key === 'Escape') fechar(false); });
        return defer.promise();
    }

    // ----- Agendar campanha: uma vez, diário, horários personalizados, a cada X horas ou dias da semana -----
    let agendando = null;     // a campanha aberta no modal
    let frequencia = 'uma_vez';
    const DICAS = {
        uma_vez: 'Envia uma vez, na data e na hora escolhidas.',
        diario: 'Envia todos os dias, neste horário.',
        horarios: 'Envia todos os dias, em cada horário da lista. Deixe pelo menos 3 horas entre eles.',
        a_cada: 'Envia de X em X horas, contando do primeiro envio (no mínimo 3 horas).',
        semana: 'Envia nos dias marcados, neste horário.'
    };
    function p2(n) { return String(n).padStart(2, '0'); }
    function dataIso(d) { return d.getFullYear() + '-' + p2(d.getMonth() + 1) + '-' + p2(d.getDate()); }
    function linhaHorario(valor) {
        return '<div class="rm-horario"><input type="time" class="a-in rm-ag-horario" value="' + esc(valor || '') + '" aria-label="Horário de execução">' +
            '<button type="button" class="rm-ib perigo rm-ag-horario-del" title="Remover horário" aria-label="Remover horário">' + ic.lixo + '</button></div>';
    }
    function atualizarDica() {
        const sem_dia = frequencia === 'semana' && !$('#rm-ag-dias .rm-dia.on').length;
        $('#rm-agenda-dica-txt').text(sem_dia ? 'Selecione pelo menos um dia da semana.' : DICAS[frequencia]);
        $('#rm-agenda-dica').toggleClass('alerta', sem_dia);
    }
    function ocultarErroAgenda() {
        $('#rm-agenda-erro').prop('hidden', true).text('');
    }
    function definirFrequencia(f) {
        frequencia = f;
        ocultarErroAgenda();
        $('#rm-agenda .rm-freq').each(function () {
            const ligado = $(this).data('freq') === f;
            $(this).toggleClass('on', ligado).attr('aria-checked', String(ligado));
        });
        $('#rm-agenda .rm-painel').each(function () { $(this).prop('hidden', $(this).data('painel') !== f); });
        $('#rm-agenda-sim').html(f === 'uma_vez' ? 'Agendar' : ic.refazer + ' Ativar recorrência');
        atualizarDica();
    }
    function abrirAgenda(c) {
        agendando = c;
        const amanha = dataIso(new Date(Date.now() + 24 * 3600 * 1000));
        const rec = c.recorrencia || null;
        $('#rm-agenda-nome').text('Agendar a campanha "' + c.nome + '"');
        $('#rm-agenda-data, #rm-ag-cada-data').val(amanha);
        $('#rm-agenda-hora, #rm-ag-cada-hora, #rm-ag-diario, #rm-ag-semana').val('09:00');
        $('#rm-ag-horas').val(6);
        $('#rm-ag-horarios').html(linhaHorario('09:00') + linhaHorario('15:00') + linhaHorario('21:00'));
        $('#rm-ag-dias .rm-dia').removeClass('on').attr('aria-pressed', 'false');
        // Campanha que já repete abre com o agendamento dela.
        if (rec) {
            if (rec.tipo === 'diario') $('#rm-ag-diario').val(rec.hora);
            if (rec.tipo === 'semana') {
                $('#rm-ag-semana').val(rec.hora);
                (rec.dias || []).forEach(function (d) { $('#rm-ag-dias .rm-dia[data-dia="' + d + '"]').addClass('on').attr('aria-pressed', 'true'); });
            }
            if (rec.tipo === 'horarios') $('#rm-ag-horarios').html((rec.horarios || []).map(linhaHorario).join(''));
            if (rec.tipo === 'a_cada') $('#rm-ag-horas').val(rec.horas);
        }
        definirFrequencia(rec ? rec.tipo : 'uma_vez');
        // Em envio e repetindo: só dá para desativar a recorrência.
        const so_parar = c.status === 'processando';
        $('#rm-agenda-form').prop('hidden', so_parar);
        $('#rm-agenda-so-parar').prop('hidden', !so_parar);
        $('#rm-agenda-sim').prop('hidden', so_parar);
        $('#rm-agenda-parar').prop('hidden', !(c.status === 'pendente' || c.recorrente)).text(c.recorrente ? 'Desativar recorrência' : 'Cancelar agendamento');
        $('#rm-agenda-erro').prop('hidden', true).text('');
        $('#rm-agenda').addClass('aberto');
        $(so_parar ? '#rm-agenda-parar' : '#rm-agenda .rm-freq.on').trigger('focus');
    }
    function fecharAgenda() {
        $('#rm-agenda').removeClass('aberto');
    }
    // O que o modal manda ao servidor, ou { erro } quando falta alguma coisa (o servidor confere o resto: horários, intervalo, datas).
    function frequenciaEscolhida() {
        const f = { tipo: frequencia };
        if (frequencia === 'uma_vez') {
            f.data = $('#rm-agenda-data').val();
            f.hora = $('#rm-agenda-hora').val();
            if (!f.data || !f.hora) return { erro: 'Informe a data e a hora do envio.' };
        } else if (frequencia === 'diario') {
            f.hora = $('#rm-ag-diario').val();
            if (!f.hora) return { erro: 'Informe o horário de execução.' };
        } else if (frequencia === 'horarios') {
            f.horarios = $('#rm-ag-horarios .rm-ag-horario').map(function () { return $(this).val(); }).get().filter(Boolean);
            if (!f.horarios.length) return { erro: 'Informe pelo menos um horário.' };
        } else if (frequencia === 'a_cada') {
            f.horas = Number($('#rm-ag-horas').val());
            f.data = $('#rm-ag-cada-data').val();
            f.hora = $('#rm-ag-cada-hora').val();
            if (!f.data || !f.hora) return { erro: 'Informe a data e a hora do primeiro envio.' };
        } else {
            f.dias = $('#rm-ag-dias .rm-dia.on').map(function () { return Number($(this).data('dia')); }).get();
            f.hora = $('#rm-ag-semana').val();
            if (!f.dias.length) return { erro: 'Selecione pelo menos um dia da semana.' };
            if (!f.hora) return { erro: 'Informe o horário de execução.' };
        }
        return f;
    }

    $(function () {
        carregar();
        // Recado do assistente (campanha criada ou salva) que veio junto do redirecionamento para a lista.
        try {
            const recado = sessionStorage.getItem('rm_aviso');
            if (recado) { sessionStorage.removeItem('rm_aviso'); aviso(recado); }
        } catch (e) { /* sem sessionStorage: sem recado, a lista aparece igual */ }

        $('#rm-busca').on('input', desenhar);
        $('#rm-status, #rm-ordem').on('change', desenhar);

        $('#rm-lista').on('click', '.rm-grupo-cab', function () {
            const $g = $(this).closest('.rm-grupo');
            const chave = $g.data('grupo');
            const fechar = !$g.hasClass('fechado');
            fechados[chave] = fechar;
            $g.toggleClass('fechado', fechar);
            $(this).attr('aria-expanded', String(!fechar));
        });

        $('#rm-lista').on('click', '.rm-iniciar', function () {
            const id = Number($(this).data('id'));
            const c = campanhas.find(function (x) { return x.id === id; });
            confirmar('Iniciar a campanha?', 'A campanha "' + c.nome + '" começa a ser enviada agora, no próximo minuto, para o público escolhido. Depois de iniciada, ela não pode mais ser editada.', 'Iniciar agora').done(function () {
                acao({ acao: 'iniciar', id: id }).done(function (r) {
                    aviso(r.mensagem || 'Campanha iniciada.');
                    carregar();
                }).fail(function (xhr) {
                    aviso(erroDe(xhr, 'Não foi possível iniciar a campanha.'), 'erro');
                });
            });
        });

        $('#rm-lista').on('click', '.rm-reenviar', function () {
            const id = Number($(this).data('id'));
            const c = campanhas.find(function (x) { return x.id === id; });
            confirmar('Reenviar a campanha?', 'A campanha "' + c.nome + '" será enviada de novo, a partir do próximo minuto, para quem se encaixa no público escolhido agora. Depois deste envio, o fluxo só libera outro remarketing 3 horas depois.', 'Reenviar agora').done(function () {
                acao({ acao: 'iniciar', id: id }).done(function (r) {
                    aviso(r.mensagem || 'Campanha reenviada.');
                    carregar();
                }).fail(function (xhr) {
                    aviso(erroDe(xhr, 'Não foi possível reenviar a campanha.'), 'erro');
                    carregar();
                });
            });
        });

        $('#rm-lista').on('click', '.rm-agendar', function () {
            const id = Number($(this).data('id'));
            abrirAgenda(campanhas.find(function (x) { return x.id === id; }));
        });
        $('#rm-agenda-nao').on('click', fecharAgenda);
        $('#rm-agenda').on('click', function (e) { if (e.target === this) fecharAgenda(); });
        $(document).on('keydown', function (e) { if (e.key === 'Escape') fecharAgenda(); });
        $('#rm-agenda').on('click', '.rm-freq', function () { definirFrequencia($(this).data('freq')); });
        $('#rm-agenda').on('click', '.rm-dia', function () {
            const ligado = !$(this).hasClass('on');
            $(this).toggleClass('on', ligado).attr('aria-pressed', String(ligado));
            ocultarErroAgenda();
            atualizarDica();
        });
        // O aviso de erro some assim que o dono mexe em qualquer campo (senão ficava o "Selecione pelo menos um dia" com o dia já marcado).
        $('#rm-agenda-form').on('input change', 'input', ocultarErroAgenda);
        $('#rm-ag-horario-mais').on('click', function () {
            if ($('#rm-ag-horarios .rm-horario').length >= 6) {
                $('#rm-agenda-erro').text('Use no máximo 6 horários por dia.').prop('hidden', false);
                return;
            }
            $('#rm-ag-horarios').append(linhaHorario(''));
            $('#rm-ag-horarios .rm-ag-horario').last().trigger('focus');
        });
        $('#rm-ag-horarios').on('click', '.rm-ag-horario-del', function () { $(this).closest('.rm-horario').remove(); });
        $('#rm-agenda-sim').on('click', function () {
            const f = frequenciaEscolhida();
            if (f.erro) {
                $('#rm-agenda-erro').text(f.erro).prop('hidden', false);
                return;
            }
            acao({ acao: 'agendar', id: agendando.id, frequencia: f }).done(function (r) {
                fecharAgenda();
                aviso(r.mensagem || 'Campanha agendada.');
                carregar();
            }).fail(function (xhr) {
                $('#rm-agenda-erro').text(erroDe(xhr, 'Não foi possível agendar a campanha.')).prop('hidden', false);
            });
        });
        $('#rm-agenda-parar').on('click', function () {
            acao({ acao: 'parar', id: agendando.id }).done(function (r) {
                fecharAgenda();
                aviso(r.mensagem || 'Agendamento cancelado.');
                carregar();
            }).fail(function (xhr) {
                $('#rm-agenda-erro').text(erroDe(xhr, 'Não foi possível cancelar o agendamento.')).prop('hidden', false);
            });
        });

        $('#rm-lista').on('click', '.rm-excluir', function () {
            const id = Number($(this).data('id'));
            const c = campanhas.find(function (x) { return x.id === id; });
            const em_envio = c.em_envio;
            confirmar('Excluir a campanha?', em_envio
                ? 'A campanha "' + c.nome + '" está sendo enviada. Excluir agora interrompe o envio, e o registro de envios e de vendas dela é apagado.'
                : 'A campanha "' + c.nome + '" será excluída, junto com o registro de envios e de vendas dela. Isso não pode ser desfeito.', 'Excluir', true).done(function () {
                acao({ acao: 'excluir', id: id }).done(function (r) {
                    aviso(r.mensagem || 'Campanha excluída.');
                    carregar();
                }).fail(function (xhr) {
                    aviso(erroDe(xhr, 'Não foi possível excluir a campanha.'), 'erro');
                });
            });
        });
    });
})(jQuery);
