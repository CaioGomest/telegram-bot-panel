(function ($) {
    // Ícones das ações (editar/remover/desvincular...): toda ação de lista é botão de ícone, nunca texto.
    const ACAO_SVG = {"excluir":"<svg viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><polyline points=\"3 6 5 6 21 6\"></polyline><path d=\"M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2\"></path><line x1=\"10\" y1=\"11\" x2=\"10\" y2=\"17\"></line><line x1=\"14\" y1=\"11\" x2=\"14\" y2=\"17\"></line></svg>","fechar":"<svg viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><line x1=\"18\" y1=\"6\" x2=\"6\" y2=\"18\"></line><line x1=\"6\" y1=\"6\" x2=\"18\" y2=\"18\"></line></svg>","subir":"<svg viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><polyline points=\"18 15 12 9 6 15\"></polyline></svg>","desvincular":"<svg viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M18.84 12.25l1.72-1.71a5 5 0 0 0-7.07-7.07l-1.72 1.71\"></path><path d=\"M5.17 11.75l-1.71 1.71a5 5 0 0 0 7.07 7.07l1.71-1.71\"></path><line x1=\"8\" y1=\"2\" x2=\"8\" y2=\"5\"></line><line x1=\"2\" y1=\"8\" x2=\"5\" y2=\"8\"></line><line x1=\"16\" y1=\"19\" x2=\"16\" y2=\"22\"></line><line x1=\"19\" y1=\"16\" x2=\"22\" y2=\"16\"></line></svg>"};
    const api_url = 'api.php';
    // Assinatura recorrente: o motor (backend) continua funcionando, mas a opção fica oculta na tela por enquanto (decisão de 09/10/2026, ainda sem teste real).
    // Para liberar de novo, troque para true. Fluxos que JÁ têm recorrência continuam mostrando a opção, para o editor não trocar o tipo sem querer.
    const RECORRENCIA_LIBERADA_NA_TELA = false;
    let lista_grupos_usuario = [];
    let planos = [];
    // Mídias das boas-vindas, na ordem de envio: a 1ª aparece na caixa grande; as demais, na lista logo abaixo (até 10, viram um álbum no Telegram).
    let bv_midias = [];
    const bv_midias_max = 10;
    let bv_lote = null;   // { n, total } enquanto sobe um grupo de arquivos escolhidos juntos (n = o que está subindo agora)
    // Order Bumps 2 a 5 (o 1º é o painel fixo da seção): cada um é { nome, valor_extra, espera_minutos, mensagem, texto_aceitar, cor_aceitar, texto_recusar, cor_recusar, midia_tipo, midia_path }.
    let bump_extras = [];
    const bump_max = 5;
    let downsell_extras = [];   // Downsell 2 a 5 (cartões); o Downsell 1 é o painel fixo da seção
    const downsell_max = 5;     // o mesmo limite do servidor (DOWNSELL_MAXIMO_SEQUENCIAS em funcoes/entrega.php)
    const downsell_proprios_max = 10;   // planos criados por downsell (DOWNSELL_PLANOS_PROPRIOS_MAXIMO)
    const downsell_midias_max = 10;     // fotos/vídeos por downsell: viram um álbum no Telegram (o mesmo limite do Downsell 1)
    let downsell_uid = 0;               // identifica cada cartão enquanto sobe arquivos (o número da posição muda ao remover um cartão)
    const downsell_lotes = {};          // uid -> { n, total } enquanto sobe arquivos escolhidos juntos
    const downsell_destino_upload = {}; // uid -> posição que o próximo arquivo ocupa (0 = caixa grande, comprimento da lista = no fim)
    let carregando = false;
    let ids_bots_vinculados = [];
    // Fluxo novo: bots que a pessoa já escolheu e que serão vinculados no primeiro "Salvar alterações" (o vínculo precisa do id do fluxo).
    let bots_pendentes = [];
    let bots_do_usuario = [];
    let bots_disponiveis = []; // fluxo salvo: bots que ainda não estão neste fluxo (com nome_fluxo = fluxo em que estão hoje)
    let cache_chat_id_salvo = '';
    const oferta_tipos = ['upsell', 'downsell', 'order_bump'];
    const max_bots = 10;
    let preco_minimo = 0;

    function escaparHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function exibirAviso(message, type = 'sucesso') {
        const $toast = $('#toast');
        $toast.removeClass('sucesso erro visivel').addClass(type).text(message);
        requestAnimationFrame(() => $toast.addClass('visivel'));
        clearTimeout(window.__toastTimeout);
        window.__toastTimeout = setTimeout(() => $toast.removeClass('visivel'), message.length > 90 ? 7000 : 3000);   // aviso comprido (ex.: resultado de vários envios) fica mais tempo
    }

    function carregarLimitesPagamento() {
        return $.getJSON(api_url + '?action=limites_pagamento').done(function (resp) {
            if (resp.sucesso) preco_minimo = Number(resp.minimo) || 0;
        });
    }

    // Mesma regra do servidor: Pix abaixo do mínimo aceito pela OmegaPayments (com a comissão da plataforma) falha na hora da venda.
    function validarPrecoMinimo(d) {
        if (!(preco_minimo > 0)) return '';
        const reais = function (v) { return 'R$ ' + (Number(v) || 0).toFixed(2).replace('.', ','); };
        const problemas = [];
        const derivados = [];
        d.planos.forEach(function (p) { if (p.valor > 0 && p.valor < preco_minimo) problemas.push('o plano "' + (p.nome || 'sem nome') + '" (' + reais(p.valor) + ')'); });
        if (d.packs.ativo) d.packs.itens.forEach(function (k) { if (k.ativo !== false && k.preco > 0 && k.preco < preco_minimo) problemas.push('o pack "' + (k.nome || 'sem nome') + '" (' + reais(k.preco) + ')'); });
        ['upsell', 'downsell'].forEach(function (tipo) {
            const o = d.ofertas[tipo];
            const rotulo = tipo === 'upsell' ? 'Upsell' : 'Downsell';
            // Downsell em sequência: cada sequência tem os próprios planos e desconto; com "Criar planos", valem os planos criados (valor digitado, sem desconto).
            const sequencias = tipo === 'downsell' ? [o].concat(o.sequencias_extras || []) : [o];
            sequencias.forEach(function (s) {
                const destinos = [];   // { valor, desconto }
                if (tipo === 'downsell' && s.criar_planos) {
                    (s.planos_proprios || []).forEach(function (p) { destinos.push({ valor: p.valor, desconto: 0 }); });
                } else {
                    const ids = tipo === 'downsell' ? (s.planos_oferecidos || []) : [s.id_plano_destino];
                    ids.forEach(function (id) {
                        let destino = d.planos.filter(function (p) { return String(p.id) === String(id); })[0];
                        if (tipo === 'upsell' && id === ID_UPSELL_PROPRIO && s.plano_proprio) destino = { valor: s.plano_proprio.valor };   // plano só do Upsell: preço digitado, sem desconto
                        if (destino) destinos.push({ valor: destino.valor, desconto: s.desconto_percentual || 0 });
                    });
                }
                destinos.forEach(function (destino) {
                    if (!o.ativo) return;
                    const final = Math.round(destino.valor * (1 - destino.desconto / 100) * 100) / 100;
                    if (final > 0 && final < preco_minimo) {
                        problemas.push('o ' + rotulo + ' (' + reais(final) + ' após o desconto)');
                        // Só o desconto é o culpado (o plano sozinho passa): aí a frase curta confunde, então diz qual oferta e quanto ela sai.
                        if (destino.desconto > 0 && destino.valor >= preco_minimo) {
                            const atual = derivados.filter(function (x) { return x.rotulo === rotulo; })[0];
                            if (!atual) derivados.push({ rotulo: rotulo, desconto: destino.desconto, final: final });
                            else if (final < atual.final) { atual.final = final; }
                        }
                    }
                });
            });
        });
        // Só o valor (sem comissão da plataforma nem a lista do que está abaixo); a frase extra só aparece quando o culpado é o desconto de uma oferta.
        const extra = derivados.map(function (x) { return ' O ' + x.rotulo + ', com ' + String(x.desconto).replace('.', ',') + '% de desconto, sai por ' + reais(x.final) + '.'; }).join('');
        return problemas.length ? 'Para a Omega, o valor mínimo é ' + reais(preco_minimo) + '.' + extra : '';
    }

    function carregarGruposUsuario() {
        return $.getJSON(api_url + '?action=listar_grupos_usuario').done(function (resp) {
            if (resp.sucesso) {
                lista_grupos_usuario = resp.grupos || [];
            }
        });
    }

    // Lista de grupos do bot para o select "Grupo para acesso" (a mesma do n8n: tabela bot_grupos). Sem escolha, "Selecione um grupo…".
    function optsGrupos(id_grupo_selecionado) {
        let html = '<option value="">Selecione um grupo…</option>';
        let achou = false;
        const vistos = {};
        lista_grupos_usuario.forEach(function (g) {
            if (vistos[g.id_telegram]) return;   // o mesmo grupo registrado para 2 bots da conta aparece uma vez só (o que se salva é o id do grupo)
            vistos[g.id_telegram] = true;
            const sel = (String(g.id_telegram) === String(id_grupo_selecionado));
            achou = achou || sel;
            html += '<option value="' + escaparHtml(g.id_telegram) + '"' + (sel ? ' selected' : '') + '>' + escaparHtml(g.titulo || g.id_telegram) + '</option>';
        });
        // Grupo já salvo que não está na lista (bot saiu do grupo, lista que não carregou): sem esta opção o select mostraria "Selecione" e salvar apagaria a entrega.
        if (id_grupo_selecionado && !achou) {
            html += '<option value="' + escaparHtml(id_grupo_selecionado) + '" selected>' + escaparHtml(id_grupo_selecionado) + ' (grupo salvo)</option>';
        }
        return html;
    }

    // ===== Entrega (plano, pack, Upsell e Downsell) =====
    // Como no n8n: um select do TIPO de entrega (nenhuma, acesso a grupo, link, mensagem ou, só na oferta, "igual à do plano oferecido") e, escolhendo "Acesso a grupo",
    // um segundo select com os grupos do bot. No JSON cada item guarda entrega_tipo ('nenhuma' | 'grupo' | 'link' | 'mensagem' | 'plano'), id_grupo, link_entrega
    // e mensagem_entrega (ver funcoes/entrega.php). Item antigo, sem entrega_tipo, só tem o id_grupo.
    const ENTREGA_GRUPO = '__grupo__';
    const ENTREGA_LINK = '__link__';
    const ENTREGA_MENSAGEM = '__mensagem__';
    const ENTREGA_MENSAGEM_MAX = 3000;   // o mesmo limite do servidor (funcoes/entrega.php)
    const ENTREGA_PLANO = '__plano__';
    const ICONE_ATUALIZAR = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 0 1-15 6.7L3 16M3 12a9 9 0 0 1 15-6.7L21 8M21 3v5h-5M3 21v-5h5"/></svg>';

    function valorEntregaDoItem(item, eh_oferta) {
        const tipo = item.entrega_tipo || '';
        if (eh_oferta && (tipo === 'plano' || tipo === '')) return ENTREGA_PLANO;   // oferta sem entrega própria usa a do plano oferecido
        if (tipo === 'link') return ENTREGA_LINK;
        if (tipo === 'mensagem') return ENTREGA_MENSAGEM;
        if (tipo === 'nenhuma') return '';
        return (tipo === 'grupo' || item.id_grupo) ? ENTREGA_GRUPO : '';   // item antigo, sem entrega_tipo, só tem o id_grupo
    }

    function optsEntrega(valor, eh_oferta) {
        return (eh_oferta ? '<option value="' + ENTREGA_PLANO + '"' + (valor === ENTREGA_PLANO ? ' selected' : '') + '>Igual à do plano oferecido</option>' : '') +
            '<option value=""' + (valor === '' ? ' selected' : '') + '>Nenhum (apenas venda)</option>' +
            '<option value="' + ENTREGA_GRUPO + '"' + (valor === ENTREGA_GRUPO ? ' selected' : '') + '>👥 Acesso a grupo</option>' +
            '<option value="' + ENTREGA_LINK + '"' + (valor === ENTREGA_LINK ? ' selected' : '') + '>🔗 Link (enviado ao cliente)</option>' +
            '<option value="' + ENTREGA_MENSAGEM + '"' + (valor === ENTREGA_MENSAGEM ? ' selected' : '') + '>💬 Mensagem (texto enviado ao cliente)</option>';
    }

    // Item sem entrega é permitido (venda só com mensagem de confirmação), mas quase sempre é esquecimento: o cliente paga e
    // não recebe nenhum acesso. Aviso fixo embaixo do campo, que some quando um grupo ou um link é escolhido.
    // o = { rotulo, quem ("deste plano"), classe (classe própria do seletor, usada pelos testes), oferta (true no Upsell/Downsell),
    //       sem_igual (true no Upsell com plano próprio: não existe "plano oferecido" para copiar a entrega) }
    function htmlCampoEntrega(item, o) {
        const igual_ao_plano = !!o.oferta && !o.sem_igual;   // a opção "Igual à do plano oferecido"
        const valor = valorEntregaDoItem(item, igual_ao_plano);
        return '<div class="campo campo-entrega">' +
            '<label>' + o.rotulo + '</label>' +
            '<select class="entrega-select' + (o.classe ? ' ' + o.classe : '') + '">' + optsEntrega(valor, igual_ao_plano) + '</select>' +
            '<div class="entrega-grupo-area"' + (valor === ENTREGA_GRUPO ? '' : ' hidden') + '>' +
            '<label>Grupo para acesso</label>' +
            '<div class="entrega-grupo-seletor"><select class="entrega-grupo-select" aria-label="Grupo para acesso">' + optsGrupos(valor === ENTREGA_GRUPO ? String(item.id_grupo || '') : '') + '</select>' +
            '<button type="button" class="editor-fluxo-icone-btn quadrado entrega-grupo-atualizar" title="Atualizar a lista de grupos" aria-label="Atualizar a lista de grupos">' + ICONE_ATUALIZAR + '</button></div>' +
            '<small class="texto-ajuda">O bot precisa ser administrador do grupo, com a permissão de convidar usuários, para gerar o link de entrada e remover quem vence.</small>' +
            '</div>' +
            '<input type="text" inputmode="url" autocomplete="off" class="entrega-link" maxlength="500" placeholder="https://..." value="' + escaparHtml(item.link_entrega || '') + '"' + (valor === ENTREGA_LINK ? '' : ' hidden') + '>' +
            '<small class="texto-ajuda dica-entrega-link"' + (valor === ENTREGA_LINK ? '' : ' hidden') + '>O cliente recebe este link na mensagem de pagamento aprovado, só depois de pagar.</small>' +
            '<div class="entrega-mensagem-area"' + (valor === ENTREGA_MENSAGEM ? '' : ' hidden') + '>' +
            '<label>Mensagem de entrega</label>' +
            '<textarea class="entrega-mensagem" rows="4" maxlength="' + ENTREGA_MENSAGEM_MAX + '" placeholder="Ex.: Seu acesso: https://... Qualquer dúvida, responda aqui.">' + escaparHtml(item.mensagem_entrega || '') + '</textarea>' +
            '<small class="texto-ajuda">O cliente recebe este texto numa mensagem própria, logo depois do aviso de pagamento aprovado. Aceita as mesmas formatações e variáveis da mensagem de aprovação (como {nome}, {plano} e {valor}).</small>' +
            '</div>' +
            (igual_ao_plano ? '<small class="texto-ajuda dica-entrega-plano"' + (valor === ENTREGA_PLANO ? '' : ' hidden') + '>Quem aceitar recebe o mesmo acesso do plano oferecido.</small>' : '') +
            '<small class="dica-sem-grupo"' + (valor === '' ? '' : ' hidden') + '>⚠ Sem entrega: quem pagar ' + o.quem +
            ' só recebe a mensagem de pagamento aprovado, sem nenhum acesso automático. Escolha um grupo ou um link, ou entregue o conteúdo nessa mensagem.</small>' +
            '</div>';
    }

    function atualizarCampoEntrega($campo) {
        const v = $campo.find('.entrega-select').val() || '';
        $campo.find('.entrega-grupo-area').prop('hidden', v !== ENTREGA_GRUPO);
        $campo.find('.entrega-link, .dica-entrega-link').prop('hidden', v !== ENTREGA_LINK);
        $campo.find('.entrega-mensagem-area').prop('hidden', v !== ENTREGA_MENSAGEM);
        $campo.find('.dica-entrega-plano').prop('hidden', v !== ENTREGA_PLANO);
        $campo.find('.dica-sem-grupo').prop('hidden', v !== '');
    }

    // Lê o campo de entrega e devolve os 4 campos que vão no JSON do plano/pack/oferta.
    function coletarEntrega($campo) {
        const v = $campo.find('.entrega-select').val() || '';
        if (v === ENTREGA_PLANO) return { entrega_tipo: 'plano', id_grupo: '', link_entrega: '', mensagem_entrega: '' };
        if (v === ENTREGA_LINK) return { entrega_tipo: 'link', id_grupo: '', link_entrega: $campo.find('.entrega-link').val().trim(), mensagem_entrega: '' };
        if (v === ENTREGA_GRUPO) return { entrega_tipo: 'grupo', id_grupo: $campo.find('.entrega-grupo-select').val() || '', link_entrega: '', mensagem_entrega: '' };
        if (v === ENTREGA_MENSAGEM) return { entrega_tipo: 'mensagem', id_grupo: '', link_entrega: '', mensagem_entrega: $campo.find('.entrega-mensagem').val().trim() };
        return { entrega_tipo: 'nenhuma', id_grupo: '', link_entrega: '', mensagem_entrega: '' };
    }

    // Mesma regra do servidor (funcoes/entrega.php): só http/https, sem espaço, até 500 caracteres.
    function linkEntregaValido(valor) {
        if (!valor || valor.length > 500 || /\s/.test(valor) || !/^https?:\/\//i.test(valor)) return false;
        try { return !!new URL(valor).hostname; } catch (e) { return false; }
    }

    $(document).on('change', '.entrega-select', function () {
        const $campo = $(this).closest('.campo-entrega');
        atualizarCampoEntrega($campo);
        if ($(this).val() === ENTREGA_LINK) $campo.find('.entrega-link').trigger('focus');
        if ($(this).val() === ENTREGA_GRUPO) $campo.find('.entrega-grupo-select').trigger('focus');
        if ($(this).val() === ENTREGA_MENSAGEM) $campo.find('.entrega-mensagem').trigger('focus');
    });

    // Refaz a lista de grupos de TODOS os campos de entrega (depois de atualizar a lista), mantendo o que cada um tinha escolhido.
    function recarregarGruposNosCampos() {
        $('.entrega-grupo-select').each(function () {
            const $s = $(this);
            $s.html(optsGrupos($s.val() || ''));
        });
    }

    $(document).on('click', '.entrega-grupo-atualizar', function () {
        const $botao = $(this).prop('disabled', true);
        carregarGruposUsuario().always(function () {
            recarregarGruposNosCampos();
            $botao.prop('disabled', false);
            if (lista_grupos_usuario.length) exibirAviso('Lista de grupos atualizada.');
            else exibirAviso('Nenhum grupo encontrado ainda: adicione o bot ao grupo como administrador e atualize de novo.', 'erro');
        });
    });

    $(document).on('change', '.campo-plano-cobranca', function () {
        const rec = this.value === 'recorrente', $p = $(this).closest('.painel-plano');
        $p.find('.campo-tempo-unico').prop('hidden', rec);
        $p.find('.campo-plano-periodo, .campo-plano-rec-dica').prop('hidden', !rec);
    });

    // Cada plano pode ser recolhido (some o formulário, fica só o cabeçalho com nome, valor e tempo). É só da tela: não vai no salvar
    // e sobrevive a adicionar/remover plano porque a chave é o id do plano.
    const SETA_PLANO = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>';
    const UNIDADE_PLANO = { dias: ['dia', 'dias'], horas: ['hora', 'horas'], minutos: ['minuto', 'minutos'] };
    const PERIODO_PLANO = { semanal: 'por semana', mensal: 'por mês', trimestral: 'por trimestre', semestral: 'por semestre', anual: 'por ano' };
    const planos_recolhidos = {};

    // Resumo do cabeçalho, lido do que está na tela agora (assim acompanha o que a pessoa digita).
    function resumoPlano($p) {
        const valor = parseFloat($p.find('.campo-plano-valor').val()) || 0;
        const partes = ['R$ ' + valor.toFixed(2).replace('.', ',')];
        if ($p.find('.campo-plano-cobranca').val() === 'recorrente') {
            partes.push(PERIODO_PLANO[$p.find('.campo-plano-periodo').val()] || 'recorrente');
        } else {
            const qtd = parseInt($p.find('.campo-plano-dias').val(), 10) || 30;
            const un = UNIDADE_PLANO[$p.find('.campo-plano-unidade').val()] || UNIDADE_PLANO.dias;
            partes.push(qtd + ' ' + un[qtd === 1 ? 0 : 1]);
        }
        return { nome: $p.find('.campo-plano-nome').val().trim(), info: partes.join(' · ') };
    }

    function atualizarResumoPlano($p) {
        const r = resumoPlano($p);
        $p.find('.plano-resumo-nome').text(r.nome || 'Plano sem nome').toggleClass('sem-nome', !r.nome);
        $p.find('.plano-resumo-info').text(r.info);
    }

    function definirPlanoRecolhido($p, recolhido) {
        const id = planos[parseInt($p.data('index'), 10)].id;
        if (recolhido) planos_recolhidos[id] = true; else delete planos_recolhidos[id];
        $p.toggleClass('recolhido', recolhido);
        $p.find('.plano-alternar').attr({ 'aria-expanded': String(!recolhido), title: recolhido ? 'Expandir plano' : 'Recolher plano', 'aria-label': recolhido ? 'Expandir plano' : 'Recolher plano' });
    }

    function renderPlanos() {
        const $lista = $('#lista-planos');
        if (!planos.length) {
            $lista.html('<p class="texto-ajuda">Nenhum plano ainda. Adicione pelo menos um.</p>');
            return;
        }
        let html = '';
        planos.forEach(function (p, i) {
            const rec = p.cobranca === 'recorrente';
            if (!p.id) p.id = 'plano_' + Date.now() + '_' + i;
            const recolhido = !!planos_recolhidos[p.id];
            const rotulo = recolhido ? 'Expandir plano' : 'Recolher plano';
            html += '' +
                '<div class="painel-plano' + (recolhido ? ' recolhido' : '') + '" data-index="' + i + '" style="border:1px solid var(--bd); border-radius:10px; padding:12px; margin-bottom:10px;">' +
                '  <div class="plano-topo">' +
                '    <span class="plano-resumo"><strong class="plano-resumo-nome"></strong><small class="plano-resumo-info"></small></span>' +
                '    <button type="button" class="btn-icon plano-alternar" aria-expanded="' + (!recolhido) + '" title="' + rotulo + '" aria-label="' + rotulo + '">' + SETA_PLANO + '</button>' +   // seta à DIREITA do cabeçalho (26/10/2026, pedido do dono)
                '  </div>' +
                '  <div class="plano-corpo">' +
                '  <div class="grade grade-2 grade-compacta">' +
                '    <div class="campo"><label>Nome do plano</label><input type="text" class="campo-plano-nome" value="' + escaparHtml(p.nome || '') + '" placeholder="Ex.: Mensal"></div>' +
                '    <div class="campo"><label>Valor (R$)</label><input type="number" step="0.01" min="0" class="campo-plano-valor" value="' + (p.valor != null ? p.valor : 0) + '"></div>' +
                '  </div>' +
                '  <div class="grade grade-2 grade-compacta" style="margin-top:8px;">' +
                '    <div class="campo campo-tempo-unico"' + (rec ? ' hidden' : '') + '><label>Tempo de acesso</label>' +
                '      <div style="display:flex; gap:6px;">' +
                '        <input type="number" min="1" class="campo-plano-dias" value="' + (p.dias_acesso || 30) + '" style="max-width:80px;">' +
                '        <select class="campo-plano-unidade">' +
                '          <option value="dias"' + (( !p.unidade_acesso || p.unidade_acesso === 'dias') ? ' selected' : '') + '>Dias</option>' +
                '          <option value="horas"' + (p.unidade_acesso === 'horas' ? ' selected' : '') + '>Horas</option>' +
                '          <option value="minutos"' + (p.unidade_acesso === 'minutos' ? ' selected' : '') + '>Minutos</option>' +
                '        </select>' +
                '      </div>' +
                '    </div>' +
                '    ' + htmlCampoEntrega(p, { rotulo: 'Entrega', quem: 'deste plano', classe: 'campo-plano-grupo' }) +
                '  </div>' +
                '  <div class="grade grade-2 grade-compacta" style="margin-top:8px;">' +
                '    <div class="campo"><label>Cor do botão</label>' +
                '      <select class="campo-plano-cor">' +
                '        <option value=""' + (!p.cor ? ' selected' : '') + '>Padrão</option>' +
                '        <option value="primary"' + (p.cor === 'primary' ? ' selected' : '') + '>Azul</option>' +
                '        <option value="success"' + (p.cor === 'success' ? ' selected' : '') + '>Verde</option>' +
                '        <option value="danger"' + (p.cor === 'danger' ? ' selected' : '') + '>Vermelho</option>' +
                '      </select>' +
                '    </div>' +
                (rec || RECORRENCIA_LIBERADA_NA_TELA ? '    <div class="campo"><label>Tipo de cobrança</label>' +
                '      <select class="campo-plano-cobranca">' +
                '        <option value="unica"' + (!rec ? ' selected' : '') + '>Pagamento único (Pix)</option>' +
                '        <option value="recorrente"' + (rec ? ' selected' : '') + '>Assinatura recorrente (Pix automático)</option>' +
                '      </select>' +
                '      <select class="campo-plano-periodo" style="margin-top:6px;"' + (rec ? '' : ' hidden') + '>' +
                ['semanal:Semanal', 'mensal:Mensal', 'trimestral:Trimestral', 'semestral:Semestral', 'anual:Anual'].map(function (o) { const [v, t] = o.split(':'); return '<option value="' + v + '"' + ((p.periodicidade || 'mensal') === v ? ' selected' : '') + '>Cobrar todo período: ' + t + '</option>'; }).join('') +
                '      </select>' +
                '      <small class="texto-ajuda campo-plano-rec-dica"' + (rec ? '' : ' hidden') + '>O cliente paga o 1º Pix agora e o gateway gera a próxima cobrança a cada período. O acesso dura o período e é renovado a cada pagamento. O order bump não vale para assinatura.</small>' +
                '    </div>' : '') +
                '  </div>' +
                '  <div style="display:flex; justify-content:flex-end; margin-top:8px;"><button type="button" class="btn-icon excluir remover-plano" title="Remover plano" aria-label="Remover plano">' + ACAO_SVG.excluir + '</button></div>' +
                '  </div>' +
                '</div>';
        });
        $lista.html(html);
        $lista.find('.painel-plano').each(function () { atualizarResumoPlano($(this)); });
    }

    function sincronizarPlanosDoDom() {
        $('#lista-planos .painel-plano').each(function (i) {
            const $p = $(this);
            planos[i] = Object.assign({
                id: planos[i].id || ('plano_' + Date.now() + '_' + i),
                nome: $p.find('.campo-plano-nome').val().trim(),
                valor: parseFloat($p.find('.campo-plano-valor').val()) || 0,
                dias_acesso: parseInt($p.find('.campo-plano-dias').val(), 10) || 30,
                unidade_acesso: $p.find('.campo-plano-unidade').val(),
                cor: $p.find('.campo-plano-cor').val() || '',
                cobranca: $p.find('.campo-plano-cobranca').val() || 'unica',
                periodicidade: $p.find('.campo-plano-periodo').val() || 'mensal'
            }, coletarEntrega($p.find('.campo-entrega')));
        });
    }

    // Mídia das boas-vindas: 3 estados bem diferentes na tela (vazio, enviando, anexada com miniatura/nome/Trocar/Tirar), para dar para ver que algo mudou.
    const MIDIA_ICONES = {
        imagem: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="3"></rect><circle cx="9" cy="9" r="1.8"></circle><path d="M21 15l-5-5L5 21"></path></svg>',
        video: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="3"></rect><path d="M10 9.5v5l4.5-2.5z"></path></svg>',
        ok: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"></path></svg>'
    };

    function desenharMidia($caixa, tipo, caminho, enviando, destino) {
        if (enviando) {
            $caixa.attr({ 'data-estado': 'enviando', 'data-tipo': tipo || 'none' }).html('<span class="midia-basico-giro"></span><span>Enviando mídia…</span>');
            return;
        }
        if (!caminho) {
            $caixa.attr({ 'data-estado': 'vazio', 'data-tipo': 'none' }).html(
                '<button type="button" class="midia-basico-escolher midia-escolher">' +
                '<span class="midia-basico-icone">' + MIDIA_ICONES.imagem + '</span>' +
                '<span class="midia-basico-info"><strong>Adicionar foto ou vídeo</strong><small>Vai junto com ' + destino + '</small></span>' +
                '</button>');
            return;
        }
        const eh_video = tipo === 'video';
        const nome = caminho.replace('uploads/', '');
        $caixa.attr({ 'data-estado': 'anexada', 'data-tipo': eh_video ? 'video' : 'image' }).html(
            '<span class="midia-basico-mini">' + (eh_video ? MIDIA_ICONES.video : '<img src="' + escaparHtml(caminho) + '" alt="">') + '</span>' +
            '<span class="midia-basico-info"><strong>' + MIDIA_ICONES.ok + (eh_video ? 'Vídeo anexado' : 'Foto anexada') + '</strong><small title="' + escaparHtml(nome) + '">' + escaparHtml(nome) + '</small></span>' +
            '<span class="midia-basico-acoes"><button type="button" class="botao botao-claro midia-trocar">Trocar</button>' +
            '<button type="button" class="btn-icon excluir midia-tirar" title="Tirar a mídia" aria-label="Tirar a mídia">' + ACAO_SVG.fechar + '</button></span>');
        // arquivo que não abre como imagem (apagado do servidor etc.): cai no ícone em vez de mostrar um quadrado quebrado
        $caixa.find('.midia-basico-mini img').on('error', function () { $(this).replaceWith(MIDIA_ICONES.imagem); });
    }

    // Sobe a foto/vídeo escolhido (mesmo envio das boas-vindas, das ofertas e dos packs) e devolve o tipo e o caminho pelo callback.
    // com_audio: só as Prévias aceitam áudio além de foto e vídeo.
    function enviarMidiaBasico(arquivo, aoTerminar, com_audio) {
        const campo = arquivo.type.indexOf('video') === 0 ? 'video' : (com_audio && arquivo.type.indexOf('audio') === 0 ? 'audio' : 'image');
        const fd = new FormData();
        fd.append(campo, arquivo);
        $.ajax({
            url: api_url + '?action=upload_' + (campo === 'image' ? 'imagem' : campo) + '_fluxo',
            method: 'POST',
            data: fd,
            processData: false,
            contentType: false
        }).done(function (resp) {
            if (!resp.sucesso) { aoTerminar(null, resp.mensagem || 'Erro ao enviar mídia.'); return; }
            aoTerminar({ tipo: campo, caminho: resp.caminho || '' }, '');
        }).fail(function (xhr) {
            aoTerminar(null, (xhr.responseJSON && xhr.responseJSON.mensagem) || 'Erro ao enviar mídia.');
        });
    }

    // Vários arquivos escolhidos de uma vez (Ctrl/Shift no computador, várias da galeria no celular): sobem um de cada vez, na ordem em que vieram,
    // até o que ainda cabe na lista (vagas); o que passar disso fica de fora e é contado no aviso final.
    // o = { vagas, enviar(arquivo, cb), aoProgredir(n, total), aoSubir(midia, k), aoFim({ total, enviadas, erros: [{ nome, erro }], descartadas }) }
    function enviarMidiasEmSequencia(arquivos, o) {
        const fila = arquivos.slice(0, Math.max(0, o.vagas));
        const r = { total: arquivos.length, enviadas: 0, erros: [], descartadas: arquivos.length - fila.length };
        (function proximo(k) {
            if (k >= fila.length) { o.aoFim(r); return; }
            o.aoProgredir(k + 1, fila.length);
            o.enviar(fila[k], function (midia, erro) {
                if (midia) { o.aoSubir(midia, k); r.enviadas++; }
                else r.erros.push({ nome: fila[k].name || 'arquivo ' + (k + 1), erro: erro });
                proximo(k + 1);
            });
        })(0);
    }

    // Aviso do fim do envio. Um arquivo só mantém as frases de sempre; vários dizem quantos entraram, o que falhou e o que passou do limite da lista.
    function avisarResultadoLote(r, frase_um, limite) {
        if (r.total === 1) {
            if (r.enviadas) exibirAviso(frase_um);
            else if (r.erros.length) exibirAviso(r.erros[0].erro, 'erro');
            else if (r.descartadas) exibirAviso('A lista já está no limite de ' + limite + ' mídias.', 'erro');
            return;
        }
        const partes = [];
        if (r.enviadas) partes.push(r.enviadas === 1 ? '1 mídia anexada.' : r.enviadas + ' mídias anexadas.');
        if (r.erros.length) partes.push((r.erros.length === 1 ? '1 não subiu: ' : r.erros.length + ' não subiram: ') + r.erros.map(function (e) { return e.nome + ' (' + e.erro + ')'; }).join('; ') + '.');
        if (r.descartadas) partes.push((r.descartadas === 1 ? '1 ficou de fora' : r.descartadas + ' ficaram de fora') + ': o limite é de ' + limite + ' mídias.');
        if (r.enviadas) partes.push('Clique em Salvar alterações para aplicar.');
        exibirAviso(partes.join(' '), r.erros.length || r.descartadas ? 'erro' : 'sucesso');
    }

    // Texto do botão de adicionar outra mídia: o andamento enquanto sobe, o limite quando a lista enche, ou o convite de sempre.
    function textoBotaoMidia(cheio, lote, maximo) {
        if (lote) return lote.total > 1 ? 'Enviando mídia ' + lote.n + ' de ' + lote.total + '…' : 'Enviando mídia…';
        return cheio ? 'Limite de ' + maximo + ' mídias' : '+ Adicionar outra foto ou vídeo';
    }

    // Boas-vindas: com "Mostrar botão (CTA)" desligado, o texto e a cor do botão somem da tela (o bot manda a lista de planos logo depois da mensagem).
    function mostrarCamposCta() {
        const mostra = $('#bv-mostrar-cta').is(':checked');
        $('.bv-cta-campo').toggle(mostra);
        $('#bv-cta-dica').prop('hidden', mostra);
    }

    // enviando: true enquanto sobe um arquivo novo. Com a caixa grande vazia a lista de extras some; o botão de adicionar só aparece com a 1ª mídia anexada.
    function atualizarPreviaMidia(enviando) {
        const primeira = bv_midias[0] || { tipo: 'none', path: '' };
        desenharMidia($('#bv-midia-previa'), primeira.tipo, primeira.path, enviando, 'a mensagem de boas-vindas');
        $('#bv-midias-extras').html(bv_midias.slice(1).map(function (m, k) {
            const i = k + 1;
            return '<div class="basico-bot" data-i="' + i + '"><span>' + (i + 1) + '. ' + (m.tipo === 'video' ? 'Vídeo' : 'Foto') + ' <small>' + escaparHtml(m.path.replace('uploads/', '')) + '</small></span>' +
                '<span class="col-acoes"><button type="button" class="btn-icon bv-subir" title="Subir" aria-label="Subir">' + ACAO_SVG.subir + '</button> ' +
                '<button type="button" class="btn-icon excluir bv-remover" title="Remover mídia" aria-label="Remover mídia">' + ACAO_SVG.excluir + '</button></span></div>';
        }).join(''));
        $('#bv-midias-adicionar').toggle(bv_midias.length > 0).prop('disabled', bv_midias.length >= bv_midias_max || !!bv_lote).text(textoBotaoMidia(bv_midias.length >= bv_midias_max, bv_lote, bv_midias_max));
        $('#bv-midias-dica').toggle(bv_midias.length > 0);
    }

    // Mídias de cada oferta (Upsell, Downsell, Order Bump), na ordem de envio: ficam aqui e vão no JSON junto com o resto da oferta. A 1ª aparece na caixa grande;
    // no Upsell e no Downsell as demais ficam numa lista logo abaixo (até 10, viram um álbum no Telegram). O Order Bump aceita 1.
    const ofertas_midias = { upsell: [], downsell: [], order_bump: [] };
    const ofertas_midias_max = { upsell: 10, downsell: 10, order_bump: 1 };
    const ofertas_lote = { upsell: null, downsell: null, order_bump: null };   // { n, total } enquanto sobe arquivos escolhidos juntos
    // Posição que o próximo arquivo escolhido numa oferta vai ocupar: 0 = caixa grande (anexa ou troca a 1ª), comprimento da lista = no fim.
    const ofertas_destino_upload = { upsell: 0, downsell: 0, order_bump: 0 };
    function atualizarMidiaOferta(tipo, enviando) {
        const lista = ofertas_midias[tipo];
        const primeira = lista[0] || { tipo: 'none', path: '' };
        const $p = $('.painel[data-oferta="' + tipo + '"]');
        desenharMidia($p.find('.of-midia'), primeira.tipo, primeira.path, enviando, 'a mensagem da oferta');
        $p.find('.of-midias-extras').html(lista.slice(1).map(function (m, k) {
            const i = k + 1;
            return '<div class="basico-bot" data-i="' + i + '"><span>' + (i + 1) + '. ' + (m.tipo === 'video' ? 'Vídeo' : 'Foto') + ' <small>' + escaparHtml(m.path.replace('uploads/', '')) + '</small></span>' +
                '<span class="col-acoes"><button type="button" class="btn-icon of-subir" title="Subir" aria-label="Subir">' + ACAO_SVG.subir + '</button> ' +
                '<button type="button" class="btn-icon excluir of-remover" title="Remover mídia" aria-label="Remover mídia">' + ACAO_SVG.excluir + '</button></span></div>';
        }).join(''));
        const maximo = ofertas_midias_max[tipo];
        $p.find('.of-midias-adicionar').toggle(lista.length > 0).prop('disabled', lista.length >= maximo || !!ofertas_lote[tipo]).text(textoBotaoMidia(lista.length >= maximo, ofertas_lote[tipo], maximo));
        $p.find('.of-midias-dica').toggle(lista.length > 0 && $p.find('.of-midias-adicionar').length > 0);
    }

    // Canais/grupos onde algum bot vinculado a este fluxo já foi adicionado (tabela bot_grupos).
    function renderCacheGrupos() {
        const $sel = $('#cache-chat-id');
        const atual = String($sel.val() || cache_chat_id_salvo || '');
        let html = '<option value="">Nenhum (sem cache)</option>';
        let achou = false;
        lista_grupos_usuario.forEach(function (g) {
            if (ids_bots_vinculados.indexOf(String(g.bot_id)) === -1) return;
            const sel = String(g.id_telegram) === atual;
            achou = achou || sel;
            html += '<option value="' + escaparHtml(g.id_telegram) + '"' + (sel ? ' selected' : '') + '>' + escaparHtml(g.titulo || g.id_telegram) + ' (' + escaparHtml(g.nome_bot || 'bot') + ')</option>';
        });
        if (atual && !achou) {
            html += '<option value="' + escaparHtml(atual) + '" selected>' + escaparHtml(atual) + ' (grupo salvo)</option>';
        }
        $sel.html(html);
        $('#cache-aviso').text(ids_bots_vinculados.length
            ? 'Só aparecem canais/grupos onde um bot deste fluxo é administrador. Não achou? Adicione o bot e clique em atualizar.'
            : 'Vincule um bot ao fluxo para ver os canais/grupos dele.');
    }

    function marcarSujo() {
        if (carregando) return;
        $('#status-salvo').addClass('sujo').text('Alterações não salvas');
    }

    function marcarLimpo() {
        // Fluxo novo ainda não existe no servidor: "Tudo salvo" enganava quem acabou de abrir a tela.
        $('#status-salvo').removeClass('sujo').text($('#id-fluxo').val() ? 'Tudo salvo' : 'Fluxo ainda não salvo');
    }

    // Nenhuma seção fica trancada. O fluxo só salva com pelo menos 1 plano e o plano se cria na seção Planos: trancar as seções até salvar prendia a pessoa
    // (e "salvar para ir para a próxima aba" não faz sentido). O que depende de o fluxo existir (vincular bot, números de Conversões) explica isso na própria seção.
    // O aviso do menu só orienta o primeiro passo e some quando o fluxo é salvo.
    function atualizarAvisoNovoFluxo() {
        $('#nav-aviso').prop('hidden', !!$('#id-fluxo').val());
        atualizarNavegacaoMobile();
    }

    function atualizarTituloFluxo() {
        $('#titulo-fluxo').text($('#nome-fluxo').val().trim() || 'Novo fluxo');
    }

    // ===== Assinatura (renovação) =====
    const as_msg_antes = 'Olá {nome}! 👋\n\nSua assinatura do plano {plano} expira {prazo}.\n\nRenove agora e continue aproveitando todos os benefícios! ✨';
    const as_msg_exp = '{nome}, sua assinatura expirou! 😢\n\nRenove agora para continuar com acesso ao conteúdo exclusivo.\n\nClique no botão abaixo para renovar com desconto especial! 👇';
    let as_dias = [];
    let as_horarios = [];
    let as_midia = { antes: { tipo: 'none', path: '' }, expirar: { tipo: 'none', path: '' } };
    let as_bloco_upload = '';

    function renderAssinatura() {
        $('#as-dias .as-chip').each(function () { $(this).toggleClass('ativo', as_dias.indexOf(parseInt($(this).data('dia'), 10)) !== -1); });
        const qtd = parseInt($('#as-dia-qtd').val(), 10) || 1;
        while (as_horarios.length > qtd) as_horarios.pop();
        let html = '';
        for (let h = 0; h < 24; h++) {
            const rotulo = (h < 10 ? '0' : '') + h + ':00';
            html += '<button type="button" class="as-hora' + (as_horarios.indexOf(rotulo) !== -1 ? ' ativo' : '') + '" data-hora="' + rotulo + '">' + rotulo + '</button>';
        }
        $('#as-horas').html(html);
        $('#as-dia-contagem').text('(selecione ' + qtd + ' — ' + as_horarios.length + ' escolhido' + (as_horarios.length === 1 ? '' : 's') + ')');
        ['antes', 'expirar'].forEach(function (bloco) {
            $('[data-midia-bloco="' + bloco + '"] .as-chip').each(function () { $(this).toggleClass('ativo', $(this).data('tipo') === as_midia[bloco].tipo); });
            $('[data-midia-nome="' + bloco + '"]').text(as_midia[bloco].path ? 'Arquivo: ' + as_midia[bloco].path.replace('uploads/', '') : '');
        });
    }

    function coletarAssinatura() {
        return {
            avisos_antes: {
                ativo: $('#as-antes-ativo').is(':checked'),
                dias: as_dias.slice().sort(function (a, b) { return b - a; }),
                midia_tipo: as_midia.antes.tipo, midia_path: as_midia.antes.path,
                mensagem: $('#as-antes-msg').val()
            },
            avisos_no_dia: { ativo: $('#as-dia-ativo').is(':checked'), horarios: as_horarios.slice().sort() },
            ao_expirar: {
                ativo: $('#as-exp-ativo').is(':checked'),
                midia_tipo: as_midia.expirar.tipo, midia_path: as_midia.expirar.path,
                mensagem: $('#as-exp-msg').val()
            },
            renovacao: { desconto: parseInt($('#as-desconto').val(), 10) || 0 },
            acoes: { expulsar: $('#as-expulsar').is(':checked') }
        };
    }

    function preencherAssinatura(a) {
        a = a || {};
        const antes = a.avisos_antes || {}, dia = a.avisos_no_dia || {}, exp = a.ao_expirar || {};
        $('#as-antes-ativo').prop('checked', !!antes.ativo);
        $('#as-antes-detalhes').prop('hidden', !antes.ativo);
        $('#as-antes-padrao').prop('hidden', !!antes.ativo);
        as_dias = Array.isArray(antes.dias) ? antes.dias.map(Number) : [7, 3, 1, 0];
        as_midia.antes = { tipo: antes.midia_tipo || 'none', path: antes.midia_path || '' };
        $('#as-antes-msg').val(antes.mensagem || as_msg_antes);
        $('#as-dia-ativo').prop('checked', !!dia.ativo);
        $('#as-dia-detalhes').prop('hidden', !dia.ativo);
        as_horarios = Array.isArray(dia.horarios) ? dia.horarios.slice() : ['09:00', '15:00', '21:00'];
        $('#as-dia-qtd').val(String(Math.min(3, Math.max(1, as_horarios.length || 3))));
        $('#as-exp-ativo').prop('checked', !!exp.ativo);
        $('#as-exp-detalhes').prop('hidden', !exp.ativo);
        $('#as-exp-padrao').prop('hidden', !!exp.ativo);
        as_midia.expirar = { tipo: exp.midia_tipo || 'none', path: exp.midia_path || '' };
        $('#as-exp-msg').val(exp.mensagem || as_msg_exp);
        $('#as-desconto').val(String((a.renovacao && a.renovacao.desconto) || 0));
        $('#as-expulsar').prop('checked', !(a.acoes && a.acoes.expulsar === false));
        renderAssinatura();
    }

    // ===== Packs =====
    let packs_itens = [];
    let pack_upload_indice = -1;
    const packs_max = 20;

    function renderPacks() {
        $('#pk-contagem').text(packs_itens.length + '/' + packs_max);
        $('#pk-adicionar').prop('disabled', packs_itens.length >= packs_max);
        $('#pk-lista').html(packs_itens.map(function (p, i) {
            const midia = p.midia_path
                ? '<span>' + ({ image: 'Foto', video: 'Vídeo', audio: 'Áudio' }[p.midia_tipo] || 'Mídia') + ' anexada</span> <button type="button" class="btn-icon excluir pk-tirar-midia" title="Tirar a mídia" aria-label="Tirar a mídia">' + ACAO_SVG.fechar + '</button>'
                : '<button type="button" class="botao botao-claro pk-midia">+ Adicionar mídia de prévia</button>';
            return '<div class="painel-plano pk-item" data-i="' + i + '" style="border:1px solid var(--bd); border-radius:10px; padding:12px; margin-bottom:10px;">' +
                '<div class="linha-flex" style="justify-content:space-between; margin-bottom:8px;">' +
                '<label class="basico-interruptor sem-caixa" style="margin:0"><input type="checkbox" class="pk-ativo-item"' + (p.ativo !== false ? ' checked' : '') + '> <span>Pack ' + (i + 1) + '</span></label>' +
                '<button type="button" class="btn-icon excluir pk-remover" title="Remover pack" aria-label="Remover pack">' + ACAO_SVG.excluir + '</button></div>' +
                '<div class="grade grade-2 grade-compacta">' +
                '<div class="campo"><label>Emoji</label><input type="text" class="pk-emoji" maxlength="4" value="' + escaparHtml(p.emoji || '📦') + '"></div>' +
                '<div class="campo"><label>Preço (R$)</label><input type="number" step="0.01" min="0" class="pk-preco" value="' + (p.preco != null ? p.preco : 0) + '"></div>' +
                '</div>' +
                '<div class="campo"><label>Nome do pack</label><input type="text" class="pk-nome" maxlength="60" value="' + escaparHtml(p.nome || '') + '" placeholder="Pack Especial"></div>' +
                '<div class="campo"><label>Descrição (exibida na prévia)</label><textarea class="pk-descricao" rows="2" placeholder="Descrição do pack...">' + escaparHtml(p.descricao || '') + '</textarea></div>' +
                '<div class="campo"><label>Mídia de preview (antes da compra)</label><div class="linha-flex">' + midia + '</div></div>' +
                htmlCampoEntrega(p, { rotulo: 'Entrega do pack', quem: 'deste pack', classe: 'pk-grupo' }) +
                '</div>';
        }).join('') || '<div class="basico-bots-vazio"><strong>Nenhum pack ainda</strong><span>Adicione o primeiro pack</span></div>');
    }

    function sincronizarPacksDoDom() {
        $('#pk-lista .pk-item').each(function (i) {
            const $p = $(this);
            if (!packs_itens[i]) return;
            packs_itens[i].ativo = $p.find('.pk-ativo-item').is(':checked');
            packs_itens[i].emoji = $p.find('.pk-emoji').val().trim() || '📦';
            packs_itens[i].nome = $p.find('.pk-nome').val().trim();
            packs_itens[i].preco = parseFloat($p.find('.pk-preco').val()) || 0;
            packs_itens[i].descricao = $p.find('.pk-descricao').val().trim();
            Object.assign(packs_itens[i], coletarEntrega($p.find('.campo-entrega')));
        });
    }

    function coletarPacks() {
        sincronizarPacksDoDom();
        return {
            ativo: $('#pk-ativo').is(':checked'),
            texto_botao: $('#pk-botao').val().trim() || '📦 Packs Disponíveis',
            mensagem: $('#pk-mensagem').val().trim(),
            itens: packs_itens
        };
    }

    function preencherPacks(k) {
        k = k || {};
        $('#pk-ativo').prop('checked', !!k.ativo);
        $('#pk-detalhes, #pk-painel-lista').prop('hidden', !k.ativo);
        $('#pk-botao').val(k.texto_botao || '');
        $('#pk-mensagem').val(k.mensagem || '');
        packs_itens = Array.isArray(k.itens) ? k.itens : [];
        renderPacks();
    }

    // ===== Prévias que somem =====
    let previas_midias = [];
    const previas_max = 8;
    let previas_lote = null;   // { n, total } enquanto sobe arquivos escolhidos juntos
    const previas_rotulo = { image: 'Foto', video: 'Vídeo', audio: 'Áudio' };

    function renderPreviasMidias() {
        $('#pv-contagem').text('(' + previas_midias.length + '/' + previas_max + ')');
        $('#pv-adicionar').prop('disabled', previas_midias.length >= previas_max || !!previas_lote)
            .text(previas_lote ? (previas_lote.total > 1 ? 'Enviando mídia ' + previas_lote.n + ' de ' + previas_lote.total + '…' : 'Enviando mídia…') : '+ Adicionar mídia');
        $('#pv-lista').html(previas_midias.length ? previas_midias.map(function (m, i) {
            return '<div class="basico-bot" data-i="' + i + '"><span>' + (i + 1) + '. ' + previas_rotulo[m.tipo] + ' <small>' + escaparHtml(m.path.replace('uploads/', '')) + '</small></span>' +
                '<span class="col-acoes"><button type="button" class="btn-icon pv-subir" title="Subir" aria-label="Subir" ' + (i === 0 ? 'disabled' : '') + '>' + ACAO_SVG.subir + '</button> ' +
                '<button type="button" class="btn-icon excluir pv-remover" title="Remover mídia" aria-label="Remover mídia">' + ACAO_SVG.excluir + '</button></span></div>';
        }).join('') : '<div class="basico-bots-vazio"><strong>Nenhuma mídia ainda</strong><span>Adicione a primeira prévia</span></div>');
    }

    function coletarPrevias() {
        return {
            ativo: $('#pv-ativo').is(':checked'),
            texto_botao: $('#pv-botao').val().trim() || '🔥 Prévias Grátis',
            midias: previas_midias,
            apagar_apos: parseInt($('#pv-apagar').val(), 10) || 5,
            rever: $('#pv-rever').val() || 'nunca',
            proteger: $('#pv-proteger').is(':checked'),
            mensagem_junto: $('#pv-junto').val(),
            mensagem_depois: $('#pv-depois').val(),
            botao_planos: $('#pv-botao-planos').is(':checked')
        };
    }

    function preencherPrevias(p) {
        p = p || {};
        $('#pv-ativo').prop('checked', !!p.ativo);
        $('#pv-detalhes').prop('hidden', !p.ativo);
        $('#pv-botao').val(p.texto_botao || '');
        previas_midias = Array.isArray(p.midias) ? p.midias.filter(function (m) { return m && m.path; }) : [];
        $('#pv-apagar').val(String(p.apagar_apos || 5));
        $('#pv-rever').val(p.rever || 'nunca');
        $('#pv-proteger').prop('checked', p.proteger !== false);
        $('#pv-junto').val(p.mensagem_junto || '');
        $('#pv-depois').val(p.mensagem_depois || '');
        $('#pv-botao-planos').prop('checked', p.botao_planos !== false);
        renderPreviasMidias();
    }

    let periodo_conversoes = 'tudo';

    function carregarConversoes() {
        const id = $('#id-fluxo').val();
        if (!id) {
            // Fluxo novo: os números vêm do banco, então ainda não há o que mostrar. Em vez de trancar a seção, explica o que fazer.
            $('#conv-vazio strong').text('Salve o fluxo para acompanhar as conversões.');
            $('#conv-vazio span').text('Quando o fluxo estiver salvo e começar a vender, os números aparecem aqui.');
            $('#conv-vazio').prop('hidden', false);
            $('#conv-conteudo').prop('hidden', true);
            return;
        }
        $.getJSON(api_url + '?action=conversoes_fluxo&id=' + encodeURIComponent(id) + '&periodo=' + encodeURIComponent(periodo_conversoes)).done(function (resp) {
            if (!resp.sucesso) return;
            const c = resp.conversoes;
            const sem_dados = !c.gerados && !c.leads;
            $('#conv-vazio strong').text('Nenhuma venda registrada neste fluxo ainda.');
            $('#conv-vazio span').text('Os dados aparecerão quando houver pagamentos.');
            $('#conv-vazio').prop('hidden', !sem_dados || c.gerados > 0);
            $('#conv-conteudo').prop('hidden', sem_dados);
            if (sem_dados) return;
            $('#conv-leads').text(c.leads.toLocaleString('pt-BR'));
            $('#conv-gerados').text(c.gerados.toLocaleString('pt-BR'));
            $('#conv-pagos').text(c.pagos.toLocaleString('pt-BR'));
            $('#conv-receita').text(formatarMoeda(c.receita));
            $('#conv-ticket').text(formatarMoeda(c.ticket_medio));
            $('#conv-taxa-lead-pago').text(String(c.taxa_lead_pago).replace('.', ',') + '%');
            const etapas = [
                ['Leads', c.leads, 100],
                ['Pix gerados', c.gerados, c.taxa_lead_pix],
                ['Pagos', c.pagos, c.leads > 0 ? Math.round(c.pagos / c.leads * 1000) / 10 : 0]
            ];
            const maior = Math.max(c.leads, c.gerados, c.pagos, 1);
            $('#conv-funil').html(etapas.map(function (e) {
                return '<div class="conv-funil-linha"><span>' + e[0] + '</span><div class="conv-barra"><i style="width:' + Math.max(2, Math.round(e[1] / maior * 100)) + '%"></i></div><strong>' + e[1].toLocaleString('pt-BR') + '</strong></div>';
            }).join(''));
            $('#conv-funil-nota').text('Pix gerado → pago: ' + String(c.taxa_pix_pago).replace('.', ',') + '%  ·  ' + c.pendentes + ' aguardando pagamento  ·  ' + c.expirados + ' expirados');
            $('#conv-dias').html((c.dias || []).length ? (c.dias).map(function (d) {
                return '<div class="conv-dia"><span>' + escaparHtml(String(d.dia).split('-').reverse().join('/')) + '</span><span>' + d.vendas + ' venda(s)</span><strong>' + formatarMoeda(d.receita) + '</strong></div>';
            }).join('') : '<p class="texto-ajuda">Nenhuma venda paga neste período.</p>');
        });
    }

    // ===== Navegação mobile (cartão "Seção atual" + folha de baixo pra cima + barra anterior/próxima) =====
    function itensNavegacao() {
        return $('#basico-nav .basico-nav-item').map(function () {
            return { secao: String($(this).data('secao')), nome: $(this).find('.basico-nav-rotulo').text(), icone: $(this).find('svg').first().prop('outerHTML'), ativo: $(this).hasClass('ativo') };
        }).get();
    }

    function atualizarNavegacaoMobile() {
        const itens = itensNavegacao();
        const i = Math.max(0, itens.findIndex(function (x) { return x.ativo; }));
        const atual = itens[i] || { nome: '', icone: '' };
        $('#secao-atual-icone').html(atual.icone);
        $('#secao-atual-nome').text(atual.nome);
        $('#secao-atual-posicao').text((i + 1) + ' de ' + itens.length);
        $('#barra-posicao').text((i + 1) + '/' + itens.length);
        $('#barra-nome').text(atual.nome);
        $('#barra-anterior').prop('disabled', i === 0);
        $('#barra-anterior-nome').text(i > 0 ? itens[i - 1].nome : 'Início');
        $('#barra-proxima').prop('disabled', i === itens.length - 1);
        $('#barra-proxima-nome').text(i < itens.length - 1 ? itens[i + 1].nome : 'Fim');
        $('#folha-secoes-lista').html(itens.map(function (x, n) {
            return '<button type="button" class="folha-secoes-item' + (x.ativo ? ' ativo' : '') + '" data-secao="' + x.secao + '">' +
                '<span class="folha-secoes-icone">' + x.icone + '</span><span class="folha-secoes-nome">' + escaparHtml(x.nome) + '</span>' +
                '<span class="folha-secoes-numero">' + (n + 1) + '</span></button>';
        }).join(''));
    }

    function abrirFolhaSecoes(abrir) {
        const $folha = $('#folha-secoes');
        if (abrir) {
            atualizarNavegacaoMobile();
            $folha.prop('hidden', false);
            requestAnimationFrame(function () { $folha.addClass('aberta'); });
            $('body').addClass('folha-aberta');
        } else {
            $folha.removeClass('aberta');
            $('body').removeClass('folha-aberta');
            setTimeout(function () { if (!$folha.hasClass('aberta')) $folha.prop('hidden', true); }, 220);
        }
    }

    function irParaSecaoVizinha(passo) {
        const itens = itensNavegacao();
        const i = itens.findIndex(function (x) { return x.ativo; });
        const destino = itens[i + passo];
        if (destino) trocarSecao(destino.secao);
    }

    function trocarSecao(nome) {
        sincronizarPlanosDoDom();
        atualizarSelectsOfertas();
        $('.basico-nav-item').removeClass('ativo').filter('[data-secao="' + nome + '"]').addClass('ativo');
        $('.basico-secao').removeClass('ativa').filter('[data-secao="' + nome + '"]').addClass('ativa');
        $('.editor-fluxo-corpo').attr('data-secao-ativa', nome);   // a coluna da direita mostra a prévia do Telegram só em Pagamentos
        if (nome === 'pagamentos') PagamentosGuiado.aoMostrar();
        if (nome === 'conversoes') carregarConversoes();
        atualizarNavegacaoMobile();
        window.scrollTo(0, 0);
    }

    function $painelOferta(tipo) {
        return $('.painel[data-oferta="' + tipo + '"]');
    }

    function atualizarSelectsOfertas() {
        // Upsell: um plano só (lista suspensa): um dos planos da seção Planos ou um plano criado só para o Upsell (ID_UPSELL_PROPRIO).
        const $sel = $painelOferta('upsell').find('.of-plano');
        const atual = $sel.data('valor') != null ? String($sel.data('valor')) : String($sel.val() || '');
        let html = '<option value="">Escolha um plano</option>' +
            '<option value="' + ID_UPSELL_PROPRIO + '"' + (atual === ID_UPSELL_PROPRIO ? ' selected' : '') + '>＋ Criar um plano só para o Upsell</option>';
        planos.forEach(function (p) {
            html += '<option value="' + escaparHtml(p.id) + '"' + (String(p.id) === atual ? ' selected' : '') + '>' +
                escaparHtml((p.nome || 'Plano sem nome') + ' — R$ ' + (Number(p.valor) || 0).toFixed(2).replace('.', ',')) + '</option>';
        });
        $sel.html(html).removeData('valor');
        // Downsell: vários planos (caixa de marcar); cada plano marcado vira um botão no bot. Mantém o que já estava marcado.
        const $lista = $painelOferta('downsell').find('.of-planos-lista');
        const marcados = $lista.data('valor') != null ? $lista.data('valor') : $lista.find('input:checked').map(function () { return String(this.value); }).get();
        $lista.html(htmlPlanosMarcaveis(marcados)).removeData('valor');
        // Downsell 2 a 5: a mesma lista, mantendo o que cada um já tinha marcado.
        $('#dn-extras .dn-planos-lista').each(function () {
            const $l = $(this);
            $l.html(htmlPlanosMarcaveis($l.find('input:checked').map(function () { return String(this.value); }).get()));
        });
    }

    // Upsell com plano próprio (27/10/2026, pedido do dono): em vez de escolher um plano da seção Planos, o dono digita nome, valor e tempo de acesso ali mesmo.
    // O plano não entra na lista de planos do bot (só é oferecido a quem acabou de pagar), então não tem desconto: o valor digitado já é o preço. A entrega é a do
    // próprio Upsell e "Igual à do plano oferecido" não existe nesse caso. O id é o mesmo do servidor (ID_PLANO_UPSELL_PROPRIO_BASICO em funcoes/entrega.php).
    const ID_UPSELL_PROPRIO = 'up_exclusivo';

    // O plano próprio é um cartão igual aos da aba Planos (item 35): cabeçalho com o resumo ("R$ 0,00 · 30 dias", que acompanha o que se digita) e a seta de recolher.
    function atualizarResumoPlanoProprio() {
        const $c = $painelOferta('upsell').find('.of-plano-proprio');
        const nome = $c.find('.of-proprio-nome').val().trim();
        const valor = parseFloat($c.find('.of-proprio-valor').val()) || 0;
        const qtd = parseInt($c.find('.of-proprio-dias').val(), 10) || 30;
        const un = UNIDADE_PLANO[$c.find('.of-proprio-unidade').val()] || UNIDADE_PLANO.dias;
        $c.find('.plano-resumo-nome').text(nome || 'Plano sem nome').toggleClass('sem-nome', !nome);
        $c.find('.plano-resumo-info').text('R$ ' + valor.toFixed(2).replace('.', ',') + ' · ' + qtd + ' ' + un[qtd === 1 ? 0 : 1]);
    }

    $(document).on('input change', '.of-plano-proprio', atualizarResumoPlanoProprio);
    $(document).on('click', '.of-plano-proprio .plano-topo', function () {
        const $c = $(this).closest('.of-plano-proprio');
        const recolhido = !$c.hasClass('recolhido');
        $c.toggleClass('recolhido', recolhido);
        $c.find('.plano-alternar').attr({ 'aria-expanded': String(!recolhido), title: recolhido ? 'Expandir plano' : 'Recolher plano', 'aria-label': recolhido ? 'Expandir plano' : 'Recolher plano' });
    });

    // Com o plano próprio escolhido, o campo Entrega fica DENTRO do cartão, ao lado do tempo de acesso (como num plano da aba Planos); senão volta para o lugar de sempre, logo abaixo.
    function levarEntregaDoUpsell(proprio) {
        const $p = $painelOferta('upsell');
        const $entrega = $p.find('.of-entrega');
        if (proprio) $entrega.appendTo($p.find('.of-proprio-linha'));
        else $entrega.insertAfter($p.find('.of-plano-proprio'));
    }

    // Mostra/esconde o cartão do plano próprio. Ao trocar de um modo para o outro, refaz o campo de entrega mantendo grupo/link escolhidos:
    // "Igual à do plano oferecido" vira "Nenhum" (o plano é o próprio Upsell) e, na volta, "Nenhum" vira "Igual ao plano oferecido" de novo.
    function atualizarPlanoProprioUpsell(refazer_entrega) {
        const $p = $painelOferta('upsell');
        const proprio = $p.find('.of-plano').val() === ID_UPSELL_PROPRIO;
        const era_proprio = $p.data('proprio') === true;
        $p.data('proprio', proprio);
        $p.find('.of-plano-proprio').prop('hidden', !proprio);
        $p.find('.of-desconto-campo').prop('hidden', proprio);
        levarEntregaDoUpsell(proprio);
        atualizarResumoPlanoProprio();
        if (refazer_entrega && proprio !== era_proprio) {
            const entrega = coletarEntrega($p.find('.campo-entrega'));
            if (proprio && entrega.entrega_tipo === 'plano') entrega.entrega_tipo = 'nenhuma';
            if (!proprio && entrega.entrega_tipo === 'nenhuma') entrega.entrega_tipo = 'plano';
            desenharEntregaOferta('upsell', entrega, proprio);
        }
    }

    $(document).on('change', '.painel[data-oferta="upsell"] .of-plano', function () {
        atualizarPlanoProprioUpsell(true);
        if ($(this).val() === ID_UPSELL_PROPRIO) $painelOferta('upsell').find('.of-proprio-nome').trigger('focus');
    });

    // ===== Downsell em sequência e planos criados dentro do Downsell (item 38, 27/10/2026) =====
    // Pedido do dono, no jeito das "Sequências de Downsell" da SharkBot: dá para ter vários downsells, cada um com o próprio tempo, mensagem, mídia, desconto, planos e entrega.
    // O Downsell 1 é o painel fixo da seção; os 2 a 5 são cartões (downsell_extras), como os Order Bumps 2 a 5, e só valem com "Depois do Pix" (o Pix não pago dispara a
    // sequência; o tempo de cada um conta do envio anterior). Em cada um, a chave "Criar planos" troca os planos marcados da aba Planos por planos criados ali mesmo
    // (cartão igual ao da aba Planos): valem pelo valor digitado, sem desconto, não aparecem em "Ver Planos" e cada um tem a própria entrega. Ver funcoes/entrega.php.
    function htmlPlanosMarcaveis(marcados) {
        let caixas = '';
        planos.forEach(function (p) {
            caixas += '<label class="of-plano-opcao"><input type="checkbox" value="' + escaparHtml(p.id) + '"' + (marcados.indexOf(String(p.id)) !== -1 ? ' checked' : '') + '> <span>' +
                escaparHtml((p.nome || 'Plano sem nome') + ' — R$ ' + (Number(p.valor) || 0).toFixed(2).replace('.', ',')) + '</span></label>';
        });
        return caixas || '<div class="of-planos-vazio">Crie pelo menos 1 plano na seção Planos para oferecê-lo aqui.</div>';
    }

    // O bloco "Planos oferecidos" (marcar os que já existem, ou "Criar planos"): o mesmo no Downsell 1 (classe_lista = of-planos-lista) e nos cartões (dn-planos-lista).
    function htmlBlocoPlanosDownsell(classe_lista) {
        return '<div class="dn-planos-cabecalho"><span class="dn-planos-titulo">Planos oferecidos</span>' +
            '<label class="basico-interruptor sem-caixa dn-criar-chave"><input type="checkbox" class="dn-criar"> <span>Criar planos</span></label></div>' +
            '<div class="dn-planos-marcados"><div class="' + classe_lista + '"></div>' +
            '<small class="texto-ajuda">Só os planos marcados aparecem para o cliente (o plano que ele já escolheu antes não é repetido), com o desconto abaixo.</small></div>' +
            '<div class="dn-planos-criados" hidden><div class="dn-proprios"></div>' +
            '<button type="button" class="botao botao-claro dn-proprio-adicionar">+ Criar plano</button>' +
            '<small class="texto-ajuda">Estes planos existem só aqui: não aparecem em "Ver Planos". Cada um vira um botão da oferta, pelo valor que você digitar (sem o desconto) e com a própria entrega.</small></div>';
    }

    function planoProprioDownsellVazio() {
        return { nome: '', valor: 0, dias_acesso: 30, unidade_acesso: 'dias', entrega_tipo: 'nenhuma', id_grupo: '', link_entrega: '', mensagem_entrega: '' };
    }

    // Cartão de um plano criado no Downsell: a moldura, o cabeçalho com o resumo e a seta de recolher são os da aba Planos; dentro, Nome, Valor, Tempo de acesso e Entrega.
    function htmlPlanoProprioDownsell(p) {
        const unidades = [['dias', 'Dias'], ['horas', 'Horas'], ['minutos', 'Minutos']].map(function (u) {
            return '<option value="' + u[0] + '"' + ((p.unidade_acesso || 'dias') === u[0] ? ' selected' : '') + '>' + u[1] + '</option>';
        }).join('');
        return '<div class="dn-proprio painel-plano">' +
            '<div class="plano-topo"><span class="plano-resumo"><strong class="plano-resumo-nome sem-nome">Plano sem nome</strong><small class="plano-resumo-info">R$ 0,00 · 30 dias</small></span>' +
            '<button type="button" class="btn-icon plano-alternar" aria-expanded="true" title="Recolher plano" aria-label="Recolher plano">' + SETA_PLANO + '</button></div>' +
            '<div class="plano-corpo">' +
            '<div class="grade grade-2 grade-compacta">' +
            '<div class="campo"><label>Nome do plano</label><input type="text" class="dn-p-nome" maxlength="80" placeholder="Ex.: Oferta relâmpago" value="' + escaparHtml(p.nome) + '"></div>' +
            '<div class="campo"><label>Valor (R$)</label><input type="number" step="0.01" min="0" class="dn-p-valor" value="' + (p.valor != null ? p.valor : 0) + '"></div>' +
            '</div>' +
            '<div class="grade grade-2 grade-compacta" style="margin-top:8px;">' +
            '<div class="campo"><label>Tempo de acesso</label><div style="display:flex; gap:6px;">' +
            '<input type="number" min="1" class="dn-p-dias" value="' + (p.dias_acesso || 30) + '" style="max-width:80px;"><select class="dn-p-unidade">' + unidades + '</select></div></div>' +
            htmlCampoEntrega(p, { rotulo: 'Entrega', quem: 'deste plano', classe: 'dn-p-entrega-select' }) +
            '</div>' +
            '<div class="dn-proprio-acoes"><button type="button" class="btn-icon excluir dn-proprio-remover" title="Remover plano" aria-label="Remover plano">' + ACAO_SVG.excluir + '</button></div>' +
            '</div></div>';
    }

    function resumoPlanoProprioDownsell($c) {
        const nome = $c.find('.dn-p-nome').val().trim();
        const valor = parseFloat($c.find('.dn-p-valor').val()) || 0;
        const qtd = parseInt($c.find('.dn-p-dias').val(), 10) || 30;
        const un = UNIDADE_PLANO[$c.find('.dn-p-unidade').val()] || UNIDADE_PLANO.dias;
        $c.find('.plano-resumo-nome').text(nome || 'Plano sem nome').toggleClass('sem-nome', !nome);
        $c.find('.plano-resumo-info').text('R$ ' + valor.toFixed(2).replace('.', ',') + ' · ' + qtd + ' ' + un[qtd === 1 ? 0 : 1]);
    }

    // Aviso de "nenhum plano" quando a lista está vazia e o botão de criar (que trava no limite).
    function atualizarVaziosProprios($bloco) {
        const $lista = $bloco.find('.dn-proprios');
        const n = $lista.find('.dn-proprio').length;
        $lista.find('.of-planos-vazio').remove();
        if (!n) $lista.append('<div class="of-planos-vazio">Nenhum plano criado ainda. Toque em "+ Criar plano".</div>');
        const cheio = n >= downsell_proprios_max;
        $bloco.find('.dn-proprio-adicionar').prop('disabled', cheio).text(cheio ? 'Limite de ' + downsell_proprios_max + ' planos' : '+ Criar plano');
    }

    function desenharPlanosProprios($bloco, lista) {
        $bloco.find('.dn-proprios').html(lista.slice(0, downsell_proprios_max).map(function (p) { return htmlPlanoProprioDownsell(Object.assign(planoProprioDownsellVazio(), p)); }).join(''));
        $bloco.find('.dn-proprio').each(function () { resumoPlanoProprioDownsell($(this)); });
        atualizarVaziosProprios($bloco);
    }

    function adicionarPlanoProprioDownsell($bloco) {
        if ($bloco.find('.dn-proprio').length >= downsell_proprios_max) return;
        $bloco.find('.dn-proprios .of-planos-vazio').remove();
        const $novo = $(htmlPlanoProprioDownsell(planoProprioDownsellVazio())).appendTo($bloco.find('.dn-proprios'));
        resumoPlanoProprioDownsell($novo);
        atualizarVaziosProprios($bloco);
        $novo.find('.dn-p-nome').trigger('focus');
    }

    function coletarPlanosProprios($bloco) {
        return $bloco.find('.dn-proprio').map(function () {
            const $c = $(this);
            return Object.assign({
                nome: $c.find('.dn-p-nome').val().trim(),
                valor: parseFloat($c.find('.dn-p-valor').val()) || 0,
                dias_acesso: parseInt($c.find('.dn-p-dias').val(), 10) || 30,
                unidade_acesso: $c.find('.dn-p-unidade').val() || 'dias'
            }, coletarEntrega($c.find('.campo-entrega')));
        }).get();
    }

    // "Criar planos" ligado: some a lista dos planos marcados, o desconto e a entrega da sequência (cada plano criado tem a sua) e aparecem os cartões.
    function atualizarModoCriarPlanos($seq) {
        const criar = $seq.find('.dn-criar').first().is(':checked');
        $seq.find('.dn-planos-marcados').first().prop('hidden', criar);
        $seq.find('.dn-planos-criados').first().prop('hidden', !criar);
        $seq.find('.dn-desconto-campo').first().prop('hidden', criar);
        $seq.find('.dn-entrega-campo').first().prop('hidden', criar);
    }

    function itemDownsellVazio() {
        return { uid: ++downsell_uid, mensagem: '', midias: [], espera_minutos: 30, desconto_percentual: 0, planos_oferecidos: [], criar_planos: false, planos_proprios: [],
            entrega_tipo: 'plano', id_grupo: '', link_entrega: '', mensagem_entrega: '' };
    }

    function htmlDownsellExtra(it, i) {
        return '<div class="bp-item dn-seq dn-extra" data-i="' + i + '">' +
            '<div class="bp-item-cabecalho"><h3 class="bp-item-titulo">Downsell ' + (i + 2) + '</h3>' +
            '<button type="button" class="btn-icon excluir dn-remover" title="Remover downsell" aria-label="Remover downsell">' + ACAO_SVG.excluir + '</button></div>' +
            '<div class="campo"><label>Enviar quantos minutos depois do anterior</label><input type="number" min="1" max="1440" step="1" class="dn-espera" value="' + (it.espera_minutos || 30) + '"></div>' +
            '<div class="dn-planos">' + htmlBlocoPlanosDownsell('dn-planos-lista') + '</div>' +
            '<div class="campo dn-desconto-campo"><label>Desconto (%)</label><input type="number" min="0" max="100" step="1" class="dn-desconto" value="' + (it.desconto_percentual != null ? it.desconto_percentual : 0) + '"></div>' +
            '<div class="dn-entrega dn-entrega-campo">' + htmlCampoEntrega(it, { rotulo: 'Entrega', quem: 'desta oferta', classe: 'dn-entrega-select', oferta: true }) + '</div>' +
            '<div class="campo"><label>Mensagem da oferta</label><textarea class="dn-mensagem" rows="3" placeholder="Última chance! Esta condição vale só por hoje.">' + escaparHtml(it.mensagem) + '</textarea></div>' +
            '<div class="campo"><label>Mídia (opcional)</label><div class="area-previa-basico dn-midia" data-estado="vazio" data-tipo="none">Sem mídia</div>' +
            '<input type="file" class="dn-midia-arquivo" accept="image/*,video/*" style="display:none">' +
            '<div class="dn-midias-extras"></div>' +
            '<button type="button" class="botao botao-claro dn-midias-adicionar" style="display:none">+ Adicionar outra foto ou vídeo</button>' +
            '<small class="texto-ajuda dn-midias-dica" style="display:none">Com mais de uma foto/vídeo (até 10), elas vão juntas num álbum, na ordem da lista, e o texto com os botões chega logo depois.</small></div>' +
            '</div>';
    }

    // Mídias de um cartão, na ordem de envio: a 1ª na caixa grande e as demais numa lista logo abaixo (até 10, viram um álbum), como no Downsell 1.
    function midiasSalvasDoItem(item) {
        return (Array.isArray(item.midias) && item.midias.length ? item.midias : (item.midia_path ? [{ tipo: item.midia_tipo === 'video' ? 'video' : 'image', path: item.midia_path }] : []))
            .filter(function (m) { return m && m.path && (m.tipo === 'image' || m.tipo === 'video'); })
            .slice(0, downsell_midias_max)
            .map(function (m) { return { tipo: m.tipo, path: m.path }; });
    }

    function atualizarMidiasDoExtra($c, it, enviando) {
        const lista = it.midias, primeira = lista[0] || { tipo: 'none', path: '' };
        desenharMidia($c.find('.dn-midia'), primeira.tipo, primeira.path, enviando, 'a mensagem da oferta');
        $c.find('.dn-midias-extras').html(lista.slice(1).map(function (m, k) {
            const j = k + 1;
            return '<div class="basico-bot" data-i="' + j + '"><span>' + (j + 1) + '. ' + (m.tipo === 'video' ? 'Vídeo' : 'Foto') + ' <small>' + escaparHtml(m.path.replace('uploads/', '')) + '</small></span>' +
                '<span class="col-acoes"><button type="button" class="btn-icon dn-midia-subir" title="Subir" aria-label="Subir">' + ACAO_SVG.subir + '</button> ' +
                '<button type="button" class="btn-icon excluir dn-midia-remover" title="Remover mídia" aria-label="Remover mídia">' + ACAO_SVG.excluir + '</button></span></div>';
        }).join(''));
        const lote = downsell_lotes[it.uid] || null;
        $c.find('.dn-midias-adicionar').toggle(lista.length > 0).prop('disabled', lista.length >= downsell_midias_max || !!lote).text(textoBotaoMidia(lista.length >= downsell_midias_max, lote, downsell_midias_max));
        $c.find('.dn-midias-dica').toggle(lista.length > 0);
    }

    // O envio de arquivos demora e o dono pode mexer nos cartões enquanto isso: o cartão é achado pelo uid, não pela posição.
    function atualizarMidiasDoExtraPorUid(uid, enviando) {
        const i = downsell_extras.findIndex(function (e) { return e.uid === uid; });
        if (i >= 0) atualizarMidiasDoExtra($('#dn-extras .dn-extra').eq(i), downsell_extras[i], enviando);
    }

    function renderDownsellExtras() {
        $('#dn-extras').html(downsell_extras.map(function (it, i) { return htmlDownsellExtra(it, i); }).join(''));
        $('#dn-extras .dn-extra').each(function (i) {
            const it = downsell_extras[i], $c = $(this);
            atualizarMidiasDoExtra($c, it);
            $c.find('.dn-planos-lista').html(htmlPlanosMarcaveis(it.planos_oferecidos));
            $c.find('.dn-criar').prop('checked', !!it.criar_planos);
            desenharPlanosProprios($c.find('.dn-planos'), it.planos_proprios);
            atualizarModoCriarPlanos($c);
        });
        const cheio = downsell_extras.length + 1 >= downsell_max;
        $('#dn-adicionar').prop('disabled', cheio).text(cheio ? 'Limite de ' + downsell_max + ' downsells' : '+ Adicionar downsell');
        mostrarTemporizadorDownsell($painelOferta('downsell'));
    }

    // Copia o que está digitado nos cartões para downsell_extras (antes de redesenhar a lista e na hora de salvar).
    function sincronizarDownsellExtrasDoDom() {
        $('#dn-extras .dn-extra').each(function (i) {
            if (!downsell_extras[i]) return;
            const $c = $(this);
            Object.assign(downsell_extras[i], {
                espera_minutos: numeroNoIntervalo($c.find('.dn-espera').val(), 1, 1440, 30),
                mensagem: $c.find('.dn-mensagem').val().trim(),
                desconto_percentual: Math.min(100, Math.max(0, parseFloat($c.find('.dn-desconto').val()) || 0)),
                planos_oferecidos: $c.find('.dn-planos-lista input:checked').map(function () { return String(this.value); }).get(),
                criar_planos: $c.find('.dn-criar').is(':checked'),
                planos_proprios: coletarPlanosProprios($c.find('.dn-planos'))
            }, coletarEntrega($c.find('.dn-entrega .campo-entrega')));
        });
    }

    // O que vai no JSON de um cartão. Com "Criar planos", a entrega da sequência não vale (cada plano criado entrega pela sua).
    function saidaSequenciaDownsell(e) {
        const s = {
            mensagem: e.mensagem, midias: e.midias.map(function (m) { return { tipo: m.tipo, path: m.path }; }), midia_tipo: e.midias.length ? e.midias[0].tipo : 'none', midia_path: e.midias.length ? e.midias[0].path : '',
            espera_minutos: e.espera_minutos, desconto_percentual: e.desconto_percentual,
            planos_oferecidos: e.planos_oferecidos.slice(), criar_planos: !!e.criar_planos, planos_proprios: e.planos_proprios.map(function (p) { return Object.assign({}, p); }),
            entrega_tipo: e.entrega_tipo, id_grupo: e.id_grupo, link_entrega: e.link_entrega, mensagem_entrega: e.mensagem_entrega
        };
        if (s.criar_planos) Object.assign(s, { entrega_tipo: 'plano', id_grupo: '', link_entrega: '', mensagem_entrega: '' });
        return s;
    }

    // Só "Depois do Pix" tem sequência: os cartões extras e o botão de adicionar somem em "Antes do Pix" (o Downsell 1 continua, é o próprio downsell).
    function atualizarModoDownsell() {
        const $p = $painelOferta('downsell');
        $p.find('#dn-extras, #dn-adicionar').toggle($p.find('.of-quando').val() === 'depois_pix');
        mostrarTemporizadorDownsell($p);
    }

    // Os campos da oferta só aparecem com o interruptor "Ativar" ligado.
    function mostrarDetalhesOferta($painel) {
        $painel.find('.of-detalhes').prop('hidden', !$painel.find('.of-ativo').is(':checked'));
    }

    // Upsell: com "Esconder o botão de recusar" ligado, só o botão aceitar vai para o cliente (os campos do Recusar somem da tela).
    function mostrarCamposRecusar($painel) {
        const esconder = $painel.find('.of-esconder-recusar').is(':checked');
        $painel.find('.of-recusar-campo').toggle(!esconder);
        $painel.find('.of-esconder-recusar-dica').prop('hidden', !esconder);
    }

    // Downsell: o temporizador só faz sentido "Depois do Pix"; o intervalo só aparece com mais de 1 envio.
    function mostrarTemporizadorDownsell($painel) {
        $painel.find('.of-temporizador').toggle($painel.find('.of-quando').val() === 'depois_pix');
        const em_sequencia = downsell_extras.length > 0;   // com mais de um downsell, cada um é enviado uma vez: "repetir" some (o que se repete é a sequência)
        $painel.find('.of-envios-campo').toggle(!em_sequencia);
        $painel.find('.of-intervalo-campo').toggle(!em_sequencia && (parseInt($painel.find('.of-envios').val(), 10) || 1) > 1);
    }

    function numeroNoIntervalo(valor, minimo, maximo, padrao) {
        const n = parseInt(valor, 10);
        return isNaN(n) ? padrao : Math.min(maximo, Math.max(minimo, n));
    }

    // ===== Order Bump em sequência =====
    // O painel fixo da seção é o Order Bump 1; os bumps 2 a 5 são cartões desenhados aqui (lista bump_extras). No modo "depois do pagamento" só vale o 1º.
    function itemBumpVazio() {
        return { nome: '', valor_extra: 0, espera_minutos: 10, mensagem: '', texto_aceitar: '', cor_aceitar: '', texto_recusar: '', cor_recusar: '', midia_tipo: 'none', midia_path: '' };
    }

    function htmlBumpExtra(it, i) {
        const cores = function (atual) {
            return [['', 'Padrão'], ['primary', 'Azul'], ['success', 'Verde'], ['danger', 'Vermelho']].map(function (c) {
                return '<option value="' + c[0] + '"' + (atual === c[0] ? ' selected' : '') + '>' + c[1] + '</option>';
            }).join('');
        };
        return '<div class="bp-item" data-i="' + i + '">' +
            '<div class="bp-item-cabecalho"><h3 class="bp-item-titulo">Order Bump ' + (i + 2) + '</h3>' +
            '<button type="button" class="btn-icon excluir bp-remover" title="Remover order bump" aria-label="Remover order bump">' + ACAO_SVG.excluir + '</button></div>' +
            '<div class="campo"><label>Nome do extra</label><input type="text" class="bp-nome" maxlength="60" placeholder="Ex.: Pack bônus 2" value="' + escaparHtml(it.nome) + '"></div>' +
            '<div class="grade grade-2 grade-compacta">' +
            '<div class="campo"><label>Enviar quantos minutos depois do anterior</label><input type="number" min="1" max="1440" step="1" class="bp-espera" value="' + (it.espera_minutos || 10) + '"></div>' +
            '<div class="campo"><label>Valor extra (R$)</label><input type="number" min="0" step="0.01" class="bp-valor" value="' + (it.valor_extra != null ? it.valor_extra : 0) + '"></div>' +
            '</div>' +
            '<div class="campo"><label>Mensagem da oferta</label><textarea class="bp-mensagem" rows="3" placeholder="Quer adicionar mais este bônus por só mais R$ 9,90?">' + escaparHtml(it.mensagem) + '</textarea></div>' +
            '<div class="campo"><label>Mídia (opcional)</label><div class="area-previa-basico bp-midia" data-estado="vazio" data-tipo="none">Sem mídia</div>' +
            '<input type="file" class="bp-midia-arquivo" accept="image/*,video/*" style="display:none"></div>' +
            '<div class="grade grade-2 grade-compacta">' +
            '<div class="campo"><label>Botão aceitar</label><input type="text" class="bp-aceitar" placeholder="Sim, quero!" value="' + escaparHtml(it.texto_aceitar) + '"></div>' +
            '<div class="campo"><label>Cor do botão aceitar</label><select class="bp-aceitar-cor">' + cores(it.cor_aceitar || '') + '</select></div>' +
            '<div class="campo"><label>Botão recusar</label><input type="text" class="bp-recusar" placeholder="Não, obrigado" value="' + escaparHtml(it.texto_recusar) + '"></div>' +
            '<div class="campo"><label>Cor do botão recusar</label><select class="bp-recusar-cor">' + cores(it.cor_recusar || '') + '</select></div>' +
            '</div></div>';
    }

    function renderBumpsExtras() {
        $('#bp-extras').html(bump_extras.map(function (it, i) { return htmlBumpExtra(it, i); }).join(''));
        $('#bp-extras .bp-item').each(function (i) {
            desenharMidia($(this).find('.bp-midia'), bump_extras[i].midia_tipo, bump_extras[i].midia_path, false, 'a mensagem da oferta');
        });
        const cheio = bump_extras.length + 1 >= bump_max;
        $('#bp-adicionar').prop('disabled', cheio).text(cheio ? 'Limite de ' + bump_max + ' order bumps' : '+ Adicionar order bump');
    }

    // Copia o que está digitado nos cartões para bump_extras (antes de redesenhar a lista e na hora de salvar).
    function sincronizarBumpsDoDom() {
        $('#bp-extras .bp-item').each(function (i) {
            if (!bump_extras[i]) return;
            const $b = $(this);
            Object.assign(bump_extras[i], {
                nome: $b.find('.bp-nome').val().trim(),
                valor_extra: parseFloat($b.find('.bp-valor').val()) || 0,
                espera_minutos: numeroNoIntervalo($b.find('.bp-espera').val(), 1, 1440, 10),
                mensagem: $b.find('.bp-mensagem').val().trim(),
                texto_aceitar: $b.find('.bp-aceitar').val().trim(),
                cor_aceitar: $b.find('.bp-aceitar-cor').val() || '',
                texto_recusar: $b.find('.bp-recusar').val().trim(),
                cor_recusar: $b.find('.bp-recusar-cor').val() || ''
            });
        });
    }

    // "Depois do pagamento" é um order bump só e sem tempo: esconde o tempo, os títulos, os cartões e o botão de adicionar.
    function atualizarModoBump() {
        const $p = $painelOferta('order_bump');
        const sequencia = $p.find('.of-quando').val() !== 'depois_pagamento';
        $p.find('.of-espera-campo, .bp-sequencia-dica, .of-detalhes > .bp-item-titulo, #bp-extras, #bp-adicionar').toggle(sequencia);
        $p.find('.bp-quando-campo').css('grid-column', sequencia ? '' : '1 / -1');
    }

    function coletarOferta(tipo) {
        const $p = $painelOferta(tipo);
        const oferta = {
            ativo: $p.find('.of-ativo').is(':checked'),
            mensagem: $p.find('.of-mensagem').val().trim(),
            // midias = lista completa (só no Upsell e no Downsell, até 10); midia_tipo/midia_path repetem a 1ª para quem lê o formato de uma mídia só.
            midia_tipo: ofertas_midias[tipo].length ? ofertas_midias[tipo][0].tipo : 'none',
            midia_path: ofertas_midias[tipo].length ? ofertas_midias[tipo][0].path : ''
        };
        if ($p.find('.of-midias-adicionar').length) {
            oferta.midias = ofertas_midias[tipo].map(function (m) { return { tipo: m.tipo, path: m.path }; });
        }
        // O Downsell não tem botões de aceitar/recusar: o cliente vê um botão por plano marcado.
        if (tipo !== 'downsell') {
            oferta.texto_aceitar = $p.find('.of-aceitar').val().trim();
            oferta.cor_aceitar = $p.find('.of-aceitar-cor').val() || '';
            oferta.texto_recusar = $p.find('.of-recusar').val().trim();
            oferta.cor_recusar = $p.find('.of-recusar-cor').val() || '';
        }
        if ($p.find('.of-esconder-recusar').length) oferta.esconder_recusar = $p.find('.of-esconder-recusar').is(':checked');
        if (tipo === 'order_bump') {
            oferta.nome = $p.find('.of-nome').val().trim();
            oferta.valor_extra = parseFloat($p.find('.of-valor-extra').val()) || 0;
            oferta.quando = $p.find('.of-quando').val() === 'depois_pagamento' ? 'depois_pagamento' : 'depois_pix';
            oferta.espera_minutos = numeroNoIntervalo($p.find('.of-espera').val(), 1, 1440, 5);
            // `itens` = a sequência inteira (até 5). O 1º repete os campos do próprio order bump (o jeito de um bump só, depois do pagamento, segue lendo eles); os outros vêm dos cartões.
            sincronizarBumpsDoDom();
            const primeiro = {};
            ['nome', 'valor_extra', 'espera_minutos', 'mensagem', 'texto_aceitar', 'cor_aceitar', 'texto_recusar', 'cor_recusar', 'midia_tipo', 'midia_path'].forEach(function (k) { primeiro[k] = oferta[k]; });
            oferta.itens = [primeiro].concat(bump_extras.map(function (e) { return Object.assign({}, e); }));
        } else if (tipo === 'downsell') {
            oferta.planos_oferecidos = $p.find('.of-planos-lista input:checked').map(function () { return String(this.value); }).get();
            oferta.id_plano_destino = oferta.planos_oferecidos[0] || '';   // 1º marcado: mantém compatível com quem lê o formato antigo
            oferta.desconto_percentual = Math.min(100, Math.max(0, parseFloat($p.find('.of-desconto').val()) || 0));
            oferta.quando = $p.find('.of-quando').val() === 'antes_pix' ? 'antes_pix' : 'depois_pix';
            oferta.espera_minutos = numeroNoIntervalo($p.find('.of-espera').val(), 1, 1440, 10);
            oferta.envios = numeroNoIntervalo($p.find('.of-envios').val(), 1, 5, 1);
            oferta.intervalo_minutos = numeroNoIntervalo($p.find('.of-intervalo').val(), 1, 1440, 30);
            const $seq1 = $('#dn-seq-1');
            oferta.criar_planos = $seq1.find('.dn-criar').is(':checked');
            oferta.planos_proprios = coletarPlanosProprios($seq1.find('.dn-planos'));
            sincronizarDownsellExtrasDoDom();
            oferta.sequencias_extras = oferta.quando === 'depois_pix' ? downsell_extras.map(saidaSequenciaDownsell) : [];
            if (oferta.sequencias_extras.length) oferta.envios = 1;   // com mais de um downsell, cada um sai uma vez
        } else {
            oferta.id_plano_destino = $p.find('.of-plano').val() || '';
            if (oferta.id_plano_destino === ID_UPSELL_PROPRIO) {
                oferta.plano_proprio = {
                    nome: $p.find('.of-proprio-nome').val().trim(),
                    valor: parseFloat($p.find('.of-proprio-valor').val()) || 0,
                    dias_acesso: parseInt($p.find('.of-proprio-dias').val(), 10) || 30,
                    unidade_acesso: $p.find('.of-proprio-unidade').val() || 'dias'
                };
                oferta.desconto_percentual = 0;   // o preço é o digitado
            } else {
                oferta.desconto_percentual = Math.min(100, Math.max(0, parseFloat($p.find('.of-desconto').val()) || 0));
            }
        }
        // Upsell e Downsell escolhem como entregar (por padrão, igual ao plano oferecido). O Order Bump segue sem entrega própria.
        if ($p.find('.of-entrega').length) Object.assign(oferta, coletarEntrega($p.find('.of-entrega').first().find('.campo-entrega')));
        // Downsell com "Criar planos": a entrega da oferta não vale (cada plano criado entrega pela sua) e fica como "igual ao plano" para não guardar um valor escondido.
        if (tipo === 'downsell' && oferta.criar_planos) Object.assign(oferta, { entrega_tipo: 'plano', id_grupo: '', link_entrega: '', mensagem_entrega: '' });
        return oferta;
    }

    // Campo "Entrega" do Upsell e do Downsell (as opções dependem dos grupos carregados, então é desenhado aqui e não no PHP).
    function desenharEntregaOferta(tipo, oferta, plano_proprio) {
        $painelOferta(tipo).find('.of-entrega').html(htmlCampoEntrega(oferta || {}, { rotulo: 'Entrega', quem: 'desta oferta', classe: 'of-entrega-select', oferta: true, sem_igual: !!plano_proprio }));
    }

    function preencherOferta(tipo, oferta) {
        oferta = oferta || {};
        const $p = $painelOferta(tipo);
        desenharEntregaOferta(tipo, oferta, tipo === 'upsell' && oferta.id_plano_destino === ID_UPSELL_PROPRIO);
        $p.find('.of-ativo').prop('checked', !!oferta.ativo);
        mostrarDetalhesOferta($p);
        $p.find('.of-mensagem').val(oferta.mensagem || '');
        $p.find('.of-aceitar').val(oferta.texto_aceitar || '');
        $p.find('.of-aceitar-cor').val(oferta.cor_aceitar || '');
        $p.find('.of-recusar').val(oferta.texto_recusar || '');
        $p.find('.of-recusar-cor').val(oferta.cor_recusar || '');
        // Oferta salva antes das várias mídias só tem midia_tipo/midia_path: vira uma lista de 1.
        ofertas_midias[tipo] = (Array.isArray(oferta.midias) && oferta.midias.length ? oferta.midias : (oferta.midia_path ? [{ tipo: oferta.midia_tipo === 'video' ? 'video' : 'image', path: oferta.midia_path }] : []))
            .filter(function (m) { return m && m.path && (m.tipo === 'image' || m.tipo === 'video'); })
            .slice(0, ofertas_midias_max[tipo])
            .map(function (m) { return { tipo: m.tipo, path: m.path }; });
        atualizarMidiaOferta(tipo);
        $p.find('.of-esconder-recusar').prop('checked', !!oferta.esconder_recusar);
        mostrarCamposRecusar($p);
        if (tipo === 'order_bump') {
            $p.find('.of-nome').val(oferta.nome || '');
            $p.find('.of-valor-extra').val(oferta.valor_extra != null ? oferta.valor_extra : 0);
            // Order bump já ligado e salvo sem "quando" é do tempo de um bump só, depois do pagamento: mantém assim. Desligado ou novo começa na sequência (depois do Pix).
            const quando_bump = oferta.quando || (oferta.ativo ? 'depois_pagamento' : 'depois_pix');
            $p.find('.of-quando').val(quando_bump === 'depois_pagamento' ? 'depois_pagamento' : 'depois_pix');
            const itens_bump = Array.isArray(oferta.itens) ? oferta.itens : [];
            $p.find('.of-espera').val(oferta.espera_minutos || (itens_bump[0] && itens_bump[0].espera_minutos) || 5);
            bump_extras = itens_bump.slice(1, bump_max).map(function (it) {
                return Object.assign(itemBumpVazio(), it, { midia_tipo: it.midia_path ? (it.midia_tipo || 'image') : 'none', midia_path: it.midia_path || '' });
            });
            renderBumpsExtras();
            atualizarModoBump();
        } else if (tipo === 'downsell') {
            // formato novo: planos_oferecidos; fluxo antigo: só id_plano_destino (vira 1 plano marcado)
            const ids = Array.isArray(oferta.planos_oferecidos) ? oferta.planos_oferecidos : (oferta.id_plano_destino ? [oferta.id_plano_destino] : []);
            $p.find('.of-planos-lista').data('valor', ids.map(String));
            $p.find('.of-desconto').val(oferta.desconto_percentual != null ? oferta.desconto_percentual : 0);
            // Downsell já salvo sem "quando" é do tempo em que só existia "antes do Pix": mantém assim. Downsell novo começa em "depois do Pix".
            $p.find('.of-quando').val(oferta.quando || (Array.isArray(oferta.planos_oferecidos) ? 'antes_pix' : 'depois_pix'));
            $p.find('.of-espera').val(oferta.espera_minutos || 10);
            $p.find('.of-envios').val(oferta.envios || 1);
            $p.find('.of-intervalo').val(oferta.intervalo_minutos || 30);
            mostrarTemporizadorDownsell($p);
            const $seq1 = $('#dn-seq-1');
            $seq1.find('.dn-criar').prop('checked', !!oferta.criar_planos);
            desenharPlanosProprios($seq1.find('.dn-planos'), Array.isArray(oferta.planos_proprios) ? oferta.planos_proprios : []);
            atualizarModoCriarPlanos($seq1);
            downsell_extras = (Array.isArray(oferta.sequencias_extras) ? oferta.sequencias_extras : []).slice(0, downsell_max - 1).map(function (it) {
                return Object.assign(itemDownsellVazio(), it, {
                    midias: midiasSalvasDoItem(it),
                    planos_oferecidos: (Array.isArray(it.planos_oferecidos) ? it.planos_oferecidos : []).map(String),
                    planos_proprios: Array.isArray(it.planos_proprios) ? it.planos_proprios : [], criar_planos: !!it.criar_planos
                });
            });
            renderDownsellExtras();
            atualizarModoDownsell();
        } else {
            $p.find('.of-plano').data('valor', oferta.id_plano_destino || '');
            $p.find('.of-desconto').val(oferta.desconto_percentual != null ? oferta.desconto_percentual : 0);
            const proprio = oferta.plano_proprio || {};
            $p.find('.of-proprio-nome').val(proprio.nome || '');
            $p.find('.of-proprio-valor').val(proprio.valor != null ? proprio.valor : 0);
            $p.find('.of-proprio-dias').val(proprio.dias_acesso || 30);
            $p.find('.of-proprio-unidade').val(['dias', 'horas', 'minutos'].indexOf(proprio.unidade_acesso) !== -1 ? proprio.unidade_acesso : 'dias');
            // As opções do seletor só são desenhadas em atualizarSelectsOfertas (depois): o formulário do plano próprio é mostrado pelo que está salvo.
            const eh_proprio = oferta.id_plano_destino === ID_UPSELL_PROPRIO;
            $p.data('proprio', eh_proprio);
            $p.find('.of-plano-proprio').prop('hidden', !eh_proprio).removeClass('recolhido');
            $p.find('.of-desconto-campo').prop('hidden', eh_proprio);
            levarEntregaDoUpsell(eh_proprio);
            atualizarResumoPlanoProprio();
        }
    }

    // Entrega por link precisa de um endereço válido, por grupo de um grupo escolhido e por mensagem de um texto (mesma regra do servidor). Pack desligado e oferta desligada não entram: não vendem nada.
    function validarEntregaPorLink(d) {
        const itens = d.planos.map(function (p) { return ['do plano', p]; });
        if (d.packs.ativo) d.packs.itens.forEach(function (k) { if (k.ativo !== false) itens.push(['do pack', k]); });
        if (d.ofertas.upsell.ativo) itens.push(['do Upsell', d.ofertas.upsell]);
        if (d.ofertas.downsell.ativo) {
            const sequencias = [d.ofertas.downsell].concat(d.ofertas.downsell.sequencias_extras || []);
            sequencias.forEach(function (s, k) {
                if (s.criar_planos) (s.planos_proprios || []).forEach(function (p) { itens.push(['do plano do Downsell', p]); });
                else itens.push(['do Downsell' + (sequencias.length > 1 ? ' ' + (k + 1) : ''), s]);
            });
        }
        for (let i = 0; i < itens.length; i++) {
            const item = itens[i][1];
            if (item.entrega_tipo === 'link' && !linkEntregaValido(item.link_entrega)) {
                return 'A entrega por link ' + itens[i][0] + (item.nome ? ' "' + item.nome + '"' : '') + ' precisa de um endereço completo, começando com https:// (sem espaços).';
            }
            if (item.entrega_tipo === 'grupo' && !item.id_grupo) {
                return 'Escolha o grupo da entrega ' + itens[i][0] + (item.nome ? ' "' + item.nome + '"' : '') + ' (ou mude a entrega para outra opção).';
            }
            if (item.entrega_tipo === 'mensagem' && !item.mensagem_entrega) {
                return 'Escreva a mensagem da entrega ' + itens[i][0] + (item.nome ? ' "' + item.nome + '"' : '') + ' (ou mude a entrega para outra opção).';
            }
        }
        return '';
    }

    function validarDados(dados) {
        const d = dados.dados_fluxograma;
        // Fluxo sem plano pode ser salvo (rascunho) e pode ter bot vinculado: o que muda é só o aviso (quem chama o bot vê "Nenhum plano disponível").
        for (let i = 0; i < d.planos.length; i++) {
            if (!d.planos[i].nome) return 'O plano ' + (i + 1) + ' está sem nome.';
            if (!(d.planos[i].valor > 0)) return 'O plano "' + d.planos[i].nome + '" precisa ter valor maior que zero.';
        }
        const usuario_suporte = d.suporte.replace(/^@/, '').replace(/^(https?:\/\/)?(t\.me|telegram\.me)\//i, '');
        if (d.suporte && !/^[A-Za-z0-9_]{4,32}$/.test(usuario_suporte)) {
            return 'Usuário de suporte inválido. Use algo como @seususuario (4 a 32 letras, números ou _).';
        }
        const erro_minimo = validarPrecoMinimo(d);
        if (erro_minimo) return erro_minimo;
        if (d.ofertas.upsell.ativo && !d.ofertas.upsell.id_plano_destino) return 'Escolha o plano oferecido no Upsell ou desative-o.';
        if (d.ofertas.upsell.ativo && d.ofertas.upsell.id_plano_destino === ID_UPSELL_PROPRIO) {
            const proprio = d.ofertas.upsell.plano_proprio;
            if (!proprio.nome) return 'Dê um nome ao plano do Upsell (ou escolha um plano que já existe).';
            if (!(proprio.valor > 0)) return 'O plano do Upsell "' + proprio.nome + '" precisa ter valor maior que zero.';
        }
        if (d.ofertas.downsell.ativo) {
            const sequencias = [d.ofertas.downsell].concat(d.ofertas.downsell.sequencias_extras || []);
            for (let i = 0; i < sequencias.length; i++) {
                const s = sequencias[i];
                const rotulo = 'Downsell' + (sequencias.length > 1 ? ' ' + (i + 1) : '');
                if (s.criar_planos) {
                    if (!s.planos_proprios.length) return 'Crie pelo menos 1 plano no ' + rotulo + ' (ou desligue "Criar planos" e marque os planos que já existem).';
                    for (let j = 0; j < s.planos_proprios.length; j++) {
                        const p = s.planos_proprios[j];
                        if (!p.nome) return 'Dê um nome ao plano ' + (j + 1) + ' do ' + rotulo + '.';
                        if (!(p.valor > 0)) return 'O plano "' + p.nome + '" do ' + rotulo + ' precisa ter valor maior que zero.';
                    }
                } else if (!s.planos_oferecidos.length) {
                    return i === 0 ? 'Marque pelo menos 1 plano oferecido no Downsell ou desative-o.' : 'Marque pelo menos 1 plano oferecido no ' + rotulo + ' ou remova-o.';
                }
            }
        }
        const erro_entrega = validarEntregaPorLink(d);
        if (erro_entrega) return erro_entrega;
        if (d.assinatura.avisos_antes.ativo && !d.assinatura.avisos_antes.dias.length) return 'Escolha pelo menos 1 dia nos avisos de renovação ou desative-os.';
        if (d.assinatura.avisos_no_dia.ativo && d.assinatura.avisos_no_dia.horarios.length !== (parseInt($('#as-dia-qtd').val(), 10) || 1)) return 'Escolha os horários dos avisos no dia da expiração (' + ($('#as-dia-qtd').val() || 1) + ').';
        if (d.packs.ativo) {
            for (let i = 0; i < d.packs.itens.length; i++) {
                if (d.packs.itens[i].ativo === false) continue;
                if (!d.packs.itens[i].nome) return 'O pack ' + (i + 1) + ' está sem nome.';
                if (!(d.packs.itens[i].preco > 0)) return 'O pack "' + d.packs.itens[i].nome + '" precisa ter preço maior que zero.';
            }
            if (!d.packs.itens.some(function (p) { return p.ativo !== false; })) return 'Ative ou adicione pelo menos 1 pack, ou desative os Packs.';
        }
        if (d.previas.ativo && !d.previas.midias.length) return 'Adicione pelo menos 1 mídia nas Prévias ou desative-as.';
        const bump = d.ofertas.order_bump;
        if (bump.ativo && !(bump.valor_extra > 0)) return 'Informe o valor extra do Order Bump ou desative-o.';
        if (bump.ativo && bump.quando === 'depois_pix') {
            for (let i = 1; i < bump.itens.length; i++) {
                if (!(bump.itens[i].valor_extra > 0)) return 'Informe o valor extra do Order Bump ' + (i + 1) + ' ou remova-o.';
            }
        }
        return '';
    }

    function formatarMoeda(v) {
        return 'R$ ' + (Number(v) || 0).toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    }

    // Só pergunta quando há risco real: o bot está hoje em OUTRO fluxo (que funciona) e este fluxo ainda não tem plano.
    function avisoBotSemPlano(b, acao) {
        return 'O bot ' + nomeBot(b) + ' hoje está no fluxo "' + b.nome_fluxo + '". ' + acao + ' tira ele de lá, e este fluxo ainda não tem plano: ' +
            'quem chamar o bot vai ver "Nenhum plano disponível" até você adicionar um. Continuar mesmo assim?';
    }

    function nomeBot(b) {
        return b.primeiro_nome || b.nome_usuario || ('Bot #' + b.id);
    }

    // Seção Bots de um fluxo ainda não salvo: lista o que foi escolhido ("será vinculado ao salvar") e oferece o restante no seletor.
    function renderBotsPendentes() {
        let html = '';
        bots_pendentes.forEach(function (b) {
            html += '<div class="basico-bot"><span>' + escaparHtml(nomeBot(b)) + (b.nome_usuario ? ' <small>@' + escaparHtml(b.nome_usuario) + '</small>' : '') +
                ' <small>· será vinculado ao salvar</small></span>' +
                '<button type="button" class="btn-icon desativar tirar-bot-pendente" data-id="' + b.id + '" title="Tirar da lista" aria-label="Tirar da lista">' + ACAO_SVG.fechar + '</button></div>';
        });
        $('#bots-contagem').text(bots_pendentes.length + '/' + max_bots + ' bots');
        $('#lista-bots-vinculados').html(html || '<div class="basico-bots-vazio"><strong>Nenhum bot escolhido</strong><span>Escolha abaixo o bot que vai executar este fluxo</span></div>');
        const escolhidos = bots_pendentes.map(function (b) { return String(b.id); });
        let opts = '<option value="">Selecione um bot para adicionar...</option>';
        bots_do_usuario.filter(function (b) { return escolhidos.indexOf(String(b.id)) === -1; }).forEach(function (b) {
            opts += '<option value="' + b.id + '">' + escaparHtml(nomeBot(b) + (b.nome_fluxo ? ' (hoje em: ' + b.nome_fluxo + ')' : '')) + '</option>';
        });
        $('#select-bot-vincular').html(opts).prop('disabled', bots_pendentes.length >= max_bots);
        $('#area-vincular').prop('hidden', false);
    }

    // Primeiro salvar de um fluxo novo: vincula os bots que a pessoa já tinha escolhido.
    function vincularBotsPendentes(id_fluxo, sem_plano) {
        const lote = bots_pendentes.slice();
        bots_pendentes = [];
        const chamadas = lote.map(function (b) {
            return $.ajax({ url: api_url + '?action=vincular_bot_fluxo', method: 'POST', contentType: 'application/json', data: JSON.stringify({ id_bot: b.id, id_fluxo: id_fluxo }) });
        });
        $.when.apply($, chamadas)
            .done(function () {
                exibirAviso('Fluxo salvo e ' + lote.length + (lote.length === 1 ? ' bot vinculado.' : ' bots vinculados.') +
                    (sem_plano ? ' Atenção: o fluxo ainda não tem plano, então quem chamar o bot vai ver "Nenhum plano disponível" até você adicionar um.' : ''), sem_plano ? 'erro' : 'sucesso');
            })
            .fail(function () { exibirAviso('Fluxo salvo, mas não consegui vincular o bot. Escolha-o de novo na seção Bots.', 'erro'); })
            .always(carregarBots);
    }

    function carregarBots() {
        const id = $('#id-fluxo').val();
        if (!id) {
            // Fluxo novo: o bot já pode ser escolhido aqui; ele é vinculado quando o fluxo for salvo pela primeira vez.
            $('#bots-aviso-salvar').show();
            ids_bots_vinculados = [];
            renderCacheGrupos();
            renderBotsPendentes();
            $.getJSON(api_url + '?action=listar_bots').done(function (resp) {
                if (!resp.sucesso) return;
                bots_do_usuario = resp.bots || [];
                renderBotsPendentes();
            });
            return;
        }
        $('#bots-aviso-salvar').hide();
        $.getJSON(api_url + '?action=bots_do_fluxo&id=' + encodeURIComponent(id)).done(function (resp) {
            if (!resp.sucesso) return;
            let html = '';
            (resp.vinculados || []).forEach(function (b) {
                html += '<div class="basico-bot"><span>' + escaparHtml(b.primeiro_nome || b.nome_usuario || ('Bot #' + b.id)) +
                    (b.nome_usuario ? ' <small>@' + escaparHtml(b.nome_usuario) + '</small>' : '') + '</span>' +
                    '<button type="button" class="btn-icon desativar desvincular-bot" data-id="' + b.id + '" title="Desvincular bot" aria-label="Desvincular bot">' + ACAO_SVG.desvincular + '</button></div>';
            });
            const qtd_vinculados = (resp.vinculados || []).length;
            ids_bots_vinculados = (resp.vinculados || []).map(function (b) { return String(b.id); });
            renderCacheGrupos();
            $('#bots-contagem').text(qtd_vinculados + '/' + max_bots + ' bots');
            $('#lista-bots-vinculados').html(html || '<div class="basico-bots-vazio"><strong>Nenhum bot vinculado</strong><span>Adicione bots para executar este fluxo</span></div>');
            let opts = '<option value="">Selecione um bot para adicionar...</option>';
            (resp.disponiveis || []).forEach(function (b) {
                const nome = (b.primeiro_nome || b.nome_usuario || ('Bot #' + b.id)) + (b.nome_fluxo ? ' (hoje em: ' + b.nome_fluxo + ')' : '');
                opts += '<option value="' + b.id + '">' + escaparHtml(nome) + '</option>';
            });
            bots_disponiveis = resp.disponiveis || [];
            $('#select-bot-vincular').html(opts).prop('disabled', qtd_vinculados >= max_bots);
            $('#area-vincular').prop('hidden', false);
        });
        $.getJSON(api_url + '?action=resumo_fluxo&id=' + encodeURIComponent(id)).done(function (resp) {
            if (!resp.sucesso) return;
            $('#resumo-leads').text((resp.resumo.leads || 0).toLocaleString('pt-BR'));
            $('#resumo-vips').text((resp.resumo.vips || 0).toLocaleString('pt-BR'));
            $('#resumo-receita').text(formatarMoeda(resp.resumo.receita));
        });
    }

    function vincularBot(id_bot, id_fluxo) {
        $.ajax({
            url: api_url + '?action=vincular_bot_fluxo',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ id_bot: id_bot, id_fluxo: id_fluxo })
        }).done(function (resp) {
            exibirAviso(resp.mensagem || 'Feito.', resp.sucesso ? 'sucesso' : 'erro');
            carregarBots();
        }).fail(function (xhr) {
            exibirAviso((xhr.responseJSON && xhr.responseJSON.mensagem) || 'Erro ao vincular bot.', 'erro');
        });
    }

    function atualizarRotuloAtivo() {
        $('#fluxo-ativo-rotulo').text($('#fluxo-ativo').is(':checked') ? 'Ativo' : 'Desligado');
    }

    function coletarDados() {
        sincronizarPlanosDoDom();
        atualizarSelectsOfertas();
        return {
            id: $('#id-fluxo').val(),
            nome: $('#nome-fluxo').val().trim() || 'Novo fluxo',
            descricao: $('#descricao-fluxo').val().trim(),
            modo: 'basico',
            dados_fluxograma: {
                ativo: $('#fluxo-ativo').is(':checked'),
                suporte: $('#suporte-usuario').val().trim(),
                suporte_cor: $('#suporte-cor').val() || '',
                // O campo "Cache de mídia" saiu da tela em 13/10; sem ele, salvar não pode apagar o canal que o fluxo já tinha (importado ou de antes).
                cache_chat_id: $('#cache-chat-id').length ? ($('#cache-chat-id').val() || '') : cache_chat_id_salvo,
                ofertas: {
                    upsell: coletarOferta('upsell'),
                    downsell: coletarOferta('downsell'),
                    order_bump: coletarOferta('order_bump')
                },
                boas_vindas: {
                    mensagem: $('#bv-mensagem').val(),
                    // midias = lista completa (até 10); midia_tipo/midia_path repetem a 1ª para quem lê o formato de uma mídia só.
                    midias: bv_midias.map(function (m) { return { tipo: m.tipo, path: m.path }; }),
                    midia_tipo: bv_midias.length ? bv_midias[0].tipo : 'none',
                    midia_path: bv_midias.length ? bv_midias[0].path : '',
                    mostrar_cta: $('#bv-mostrar-cta').is(':checked'),
                    texto_cta: $('#bv-cta').val().trim() || 'Ver Planos',
                    cor_cta: $('#bv-cta-cor').val() || ''
                },
                previas: coletarPrevias(),
                packs: coletarPacks(),
                assinatura: coletarAssinatura(),
                planos: planos,
                // Aba Pagamentos (modelo novo, versao 2): ver assets/pagamentos_guiado.js e funcoes/pagamentos_guiado.php.
                pagamentos: PagamentosGuiado.coletar()
            }
        };
    }

    function preencherForm(fluxo) {
        carregando = true;
        $('#id-fluxo').val(fluxo.id || '');
        $('#nome-fluxo').val(fluxo.nome || '');
        $('#descricao-fluxo').val(fluxo.descricao || '');
        const dados = fluxo.dados_fluxograma || {};
        const bv = dados.boas_vindas || {};
        $('#bv-mensagem').val(bv.mensagem || '');
        $('#bv-mostrar-cta').prop('checked', bv.mostrar_cta !== false);   // fluxo antigo (sem o campo) = botão ligado
        mostrarCamposCta();
        $('#bv-cta').val(bv.texto_cta || 'Ver Planos');
        $('#bv-cta-cor').val(bv.cor_cta || '');
        // Fluxo salvo antes das várias mídias só tem midia_tipo/midia_path: vira uma lista de 1.
        bv_midias = (Array.isArray(bv.midias) && bv.midias.length ? bv.midias : (bv.midia_path ? [{ tipo: bv.midia_tipo, path: bv.midia_path }] : []))
            .filter(function (m) { return m && m.path && (m.tipo === 'image' || m.tipo === 'video'); })
            .slice(0, bv_midias_max)
            .map(function (m) { return { tipo: m.tipo, path: m.path }; });
        atualizarPreviaMidia();

        planos = Array.isArray(dados.planos) ? dados.planos : [];
        renderPlanos();

        // Fluxo salvo antes do modelo novo (sem "versao") abre mostrando o que ele fazia; salvar passa para o modelo novo mantendo esse comportamento.
        PagamentosGuiado.preencher(dados.pagamentos || {});

        $('#fluxo-ativo').prop('checked', dados.ativo !== false);
        atualizarRotuloAtivo();
        $('#suporte-usuario').val(dados.suporte || '');
        $('#suporte-cor').val(dados.suporte_cor || '');
        cache_chat_id_salvo = String(dados.cache_chat_id || '');
        preencherPrevias(dados.previas);
        preencherPacks(dados.packs);
        preencherAssinatura(dados.assinatura);
        const ofertas = dados.ofertas || {};
        oferta_tipos.forEach(function (tipo) { preencherOferta(tipo, ofertas[tipo]); });
        atualizarSelectsOfertas();

        if (fluxo.id) {
            $('#btn-excluir-fluxo-basico').css('display', 'inline-flex');
        }
        atualizarTituloFluxo();
        atualizarAvisoNovoFluxo();
        carregarBots();
        carregando = false;
        marcarLimpo();
    }

    function abrirFluxo(id) {
        $.getJSON(api_url + '?action=obter_fluxo&id=' + encodeURIComponent(id))
            .done(function (resp) {
                if (!resp.sucesso) {
                    exibirAviso(resp.mensagem || 'Fluxo não encontrado.', 'erro');
                    return;
                }
                if ((resp.fluxo.modo || 'avancado') !== 'basico') {
                    exibirAviso('Este fluxo é do Editor Visual, não do modo Guiado.', 'erro');
                    setTimeout(function () { window.location.href = 'fluxo?id=' + encodeURIComponent(id); }, 1200);
                    return;
                }
                preencherForm(resp.fluxo);
            })
            .fail(function () {
                exibirAviso('Não foi possível abrir o fluxo.', 'erro');
            });
    }

    function salvarFluxo() {
        const dados = coletarDados();
        const erro_validacao = validarDados(dados);
        if (erro_validacao) {
            exibirAviso(erro_validacao, 'erro');
            return;
        }
        const sem_plano = !dados.dados_fluxograma.planos.length;
        const bot_em_uso = bots_pendentes.filter(function (b) { return b.nome_fluxo; })[0];
        if (sem_plano && bot_em_uso && !window.confirm(avisoBotSemPlano(bot_em_uso, 'Salvar e vincular'))) return;
        $.ajax({
            url: api_url + '?action=salvar_fluxo',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(dados)
        }).done(function (resp) {
            if (!resp.sucesso) {
                exibirAviso(resp.mensagem || 'Erro ao salvar fluxo.', 'erro');
                return;
            }
            const era_novo = !$('#id-fluxo').val();
            $('#id-fluxo').val(resp.fluxo.id || '');
            $('#btn-excluir-fluxo-basico').css('display', 'inline-flex');
            marcarLimpo();
            atualizarTituloFluxo();
            atualizarAvisoNovoFluxo();
            if (era_novo && bots_pendentes.length && resp.fluxo && resp.fluxo.id) {
                vincularBotsPendentes(resp.fluxo.id, sem_plano);
            } else {
                const com_bot = ids_bots_vinculados.length > 0;
                carregarBots();
                if (sem_plano && com_bot) exibirAviso('Salvo. Atenção: o fluxo ainda não tem plano, então quem chamar o bot vai ver "Nenhum plano disponível" até você adicionar um.', 'erro');
                else if (sem_plano) exibirAviso('Rascunho salvo. Adicione pelo menos 1 plano em Planos para o fluxo poder vender.');
                else exibirAviso(resp.mensagem || 'Fluxo salvo com sucesso.');
            }
            if (window.history.pushState && resp.fluxo && resp.fluxo.id) {
                const new_url = window.location.pathname + '?id=' + resp.fluxo.id;
                window.history.pushState({ path: new_url }, '', new_url);
            }
        }).fail(function (xhr) {
            exibirAviso((xhr.responseJSON && xhr.responseJSON.mensagem) || 'Erro ao salvar fluxo.', 'erro');
        });
    }

    function excluirFluxo() {
        const id = $('#id-fluxo').val();
        if (!id) return;
        if (!window.confirm('Deseja excluir este fluxo?')) return;
        $.ajax({
            url: api_url + '?action=excluir_fluxo',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ id: id })
        }).done(function (resp) {
            if (!resp.sucesso) {
                exibirAviso(resp.mensagem || 'Erro ao excluir fluxo.', 'erro');
                return;
            }
            marcarLimpo();
            window.location.href = 'fluxos';
        }).fail(function (xhr) {
            exibirAviso((xhr.responseJSON && xhr.responseJSON.mensagem) || 'Erro ao excluir fluxo.', 'erro');
        });
    }

    $(function () {
        $('#dn-planos-1').html(htmlBlocoPlanosDownsell('of-planos-lista'));
        PagamentosGuiado.iniciar({ marcarSujo: marcarSujo, exibirAviso: exibirAviso, enviarMidia: enviarMidiaBasico, enviarMidias: enviarMidiasEmSequencia, avisarLote: avisarResultadoLote, escaparHtml: escaparHtml });
        carregarLimitesPagamento();
        carregarGruposUsuario().always(function () {
            const url_params = new URLSearchParams(window.location.search);
            const flow_id = url_params.get('id');
            if (flow_id) {
                abrirFluxo(flow_id);
            } else {
                atualizarPreviaMidia();
                ['upsell', 'downsell', 'order_bump'].forEach(function (tipo) { atualizarMidiaOferta(tipo); });
                ['upsell', 'downsell'].forEach(function (tipo) { desenharEntregaOferta(tipo, {}); });
                renderBumpsExtras();
                atualizarModoBump();
                renderDownsellExtras();
                atualizarModoDownsell();
                renderPlanos();
                atualizarSelectsOfertas();
                preencherPrevias({});
                preencherPacks({});
                preencherAssinatura({});
                PagamentosGuiado.padraoNovo();   // fluxo novo: texto sugerido e botões ligados
                atualizarAvisoNovoFluxo();
                carregarBots();
            }
        });

        $('#basico-nav').on('click', '.basico-nav-item', function () {
            trocarSecao($(this).data('secao'));
        });
        $('#as-antes-ativo').on('change', function () { $('#as-antes-detalhes').prop('hidden', !this.checked); $('#as-antes-padrao').prop('hidden', this.checked); });
        $('#as-dia-ativo').on('change', function () { $('#as-dia-detalhes').prop('hidden', !this.checked); });
        $('#as-exp-ativo').on('change', function () { $('#as-exp-detalhes').prop('hidden', !this.checked); $('#as-exp-padrao').prop('hidden', this.checked); });
        $('#as-dias').on('click', '.as-chip', function () {
            const d = parseInt($(this).data('dia'), 10);
            const i = as_dias.indexOf(d);
            if (i === -1) as_dias.push(d); else as_dias.splice(i, 1);
            renderAssinatura();
            marcarSujo();
        });
        $('#as-dia-qtd').on('change', function () { renderAssinatura(); });
        $('#as-horas').on('click', '.as-hora', function () {
            const h = String($(this).data('hora'));
            const i = as_horarios.indexOf(h);
            const qtd = parseInt($('#as-dia-qtd').val(), 10) || 1;
            if (i !== -1) as_horarios.splice(i, 1);
            else if (as_horarios.length < qtd) as_horarios.push(h);
            else { exibirAviso('Você já escolheu ' + qtd + ' horário(s). Desmarque um ou aumente a quantidade.', 'erro'); return; }
            renderAssinatura();
            marcarSujo();
        });
        $('#basico-conteudo').on('click', '.as-var', function () {
            const campo = $(this).closest('.campo').find('textarea')[0];
            if (!campo) return;
            const ini = campo.selectionStart || 0, fim = campo.selectionEnd || 0, v = $(this).data('var');
            campo.value = campo.value.slice(0, ini) + v + campo.value.slice(fim);
            campo.focus();
            campo.selectionStart = campo.selectionEnd = ini + v.length;
            marcarSujo();
        });
        $('[data-midia-bloco]').on('click', '.as-chip', function () {
            const bloco = String($(this).closest('[data-midia-bloco]').data('midia-bloco'));
            const tipo = String($(this).data('tipo'));
            if (tipo === 'none') { as_midia[bloco] = { tipo: 'none', path: '' }; renderAssinatura(); marcarSujo(); return; }
            if (as_midia[bloco].tipo === tipo && as_midia[bloco].path) return;
            as_bloco_upload = bloco;
            $('#as-arquivo').data('tipo', tipo).attr('accept', { image: 'image/jpeg,image/png', video: 'video/*', audio: 'audio/*' }[tipo]).trigger('click');
        });
        $('#as-arquivo').on('change', function () {
            const arquivo = this.files && this.files[0];
            const tipo = String($(this).data('tipo'));
            const bloco = as_bloco_upload;
            this.value = '';
            if (!arquivo || !bloco) return;
            const fd = new FormData();
            fd.append(tipo, arquivo);
            exibirAviso('Enviando mídia...');
            $.ajax({ url: api_url + '?action=upload_' + (tipo === 'image' ? 'imagem' : tipo) + '_fluxo', method: 'POST', data: fd, processData: false, contentType: false })
                .done(function (resp) {
                    if (!resp.sucesso) { exibirAviso(resp.mensagem || 'Erro ao enviar mídia.', 'erro'); return; }
                    as_midia[bloco] = { tipo: tipo, path: resp.caminho || '' };
                    renderAssinatura();
                    marcarSujo();
                    exibirAviso('Mídia adicionada.');
                })
                .fail(function (xhr) { exibirAviso((xhr.responseJSON && xhr.responseJSON.mensagem) || 'Erro ao enviar mídia.', 'erro'); });
        });
        $('#pk-ativo').on('change', function () { $('#pk-detalhes, #pk-painel-lista').prop('hidden', !this.checked); });
        $('#pk-adicionar').on('click', function () {
            sincronizarPacksDoDom();
            if (packs_itens.length >= packs_max) return;
            packs_itens.push({ id: 'pack_' + Date.now(), ativo: true, emoji: '📦', nome: '', preco: 0, descricao: '', midia_tipo: 'none', midia_path: '', id_grupo: '' });
            renderPacks();
            marcarSujo();
        });
        $('#pk-lista').on('click', '.pk-remover', function () {
            sincronizarPacksDoDom();
            packs_itens.splice(parseInt($(this).closest('.pk-item').data('i'), 10), 1);
            renderPacks();
            marcarSujo();
        }).on('click', '.pk-tirar-midia', function () {
            sincronizarPacksDoDom();
            const p = packs_itens[parseInt($(this).closest('.pk-item').data('i'), 10)];
            p.midia_path = ''; p.midia_tipo = 'none';
            renderPacks();
            marcarSujo();
        }).on('click', '.pk-midia', function () {
            sincronizarPacksDoDom();
            pack_upload_indice = parseInt($(this).closest('.pk-item').data('i'), 10);
            $('#pk-arquivo').trigger('click');
        });
        $('#pk-arquivo').on('change', function () {
            const arquivo = this.files && this.files[0];
            this.value = '';
            if (!arquivo || !packs_itens[pack_upload_indice]) return;
            const indice = pack_upload_indice;
            const tipo = arquivo.type.indexOf('video') === 0 ? 'video' : (arquivo.type.indexOf('audio') === 0 ? 'audio' : 'image');
            const fd = new FormData();
            fd.append(tipo, arquivo);
            exibirAviso('Enviando mídia...');
            $.ajax({ url: api_url + '?action=upload_' + (tipo === 'image' ? 'imagem' : tipo) + '_fluxo', method: 'POST', data: fd, processData: false, contentType: false })
                .done(function (resp) {
                    if (!resp.sucesso) { exibirAviso(resp.mensagem || 'Erro ao enviar mídia.', 'erro'); return; }
                    sincronizarPacksDoDom();
                    packs_itens[indice].midia_path = resp.caminho || '';
                    packs_itens[indice].midia_tipo = tipo;
                    renderPacks();
                    marcarSujo();
                    exibirAviso('Mídia adicionada.');
                })
                .fail(function (xhr) { exibirAviso((xhr.responseJSON && xhr.responseJSON.mensagem) || 'Erro ao enviar mídia.', 'erro'); });
        });
        $('#pv-ativo').on('change', function () { $('#pv-detalhes').prop('hidden', !this.checked); });
        $('#pv-adicionar').on('click', function () {
            if (previas_lote || previas_midias.length >= previas_max) return;
            $('#pv-arquivo').prop('multiple', true).trigger('click');
        });
        $('#pv-lista').on('click', '.pv-remover', function () {
            previas_midias.splice(parseInt($(this).closest('[data-i]').data('i'), 10), 1);
            renderPreviasMidias();
            marcarSujo();
        }).on('click', '.pv-subir', function () {
            const i = parseInt($(this).closest('[data-i]').data('i'), 10);
            if (i > 0) { previas_midias.splice(i - 1, 0, previas_midias.splice(i, 1)[0]); renderPreviasMidias(); marcarSujo(); }
        });
        $('#pv-arquivo').on('change', function () {
            const arquivos = Array.prototype.slice.call(this.files || []);   // copiar antes de limpar: limpar o campo esvazia a lista
            this.value = '';
            if (!arquivos.length || previas_lote) return;
            enviarMidiasEmSequencia(arquivos, {
                vagas: previas_max - previas_midias.length,
                enviar: function (arquivo, aoTerminar) { enviarMidiaBasico(arquivo, aoTerminar, true); },
                aoProgredir: function (n, total) { previas_lote = { n: n, total: total }; renderPreviasMidias(); },
                aoSubir: function (midia) {
                    previas_midias.push({ tipo: midia.tipo, path: midia.caminho });
                    renderPreviasMidias();
                    marcarSujo();
                },
                aoFim: function (r) {
                    previas_lote = null;
                    renderPreviasMidias();
                    avisarResultadoLote(r, 'Mídia adicionada.', previas_max);
                }
            });
        });
        $('#conv-filtros').on('click', '.conv-filtro', function () {
            periodo_conversoes = String($(this).data('periodo'));
            $('.conv-filtro').removeClass('ativo');
            $(this).addClass('ativo');
            carregarConversoes();
        });
        $('#secao-atual').on('click', function () { abrirFolhaSecoes(true); });
        $('#folha-secoes-fundo, #folha-secoes-fechar').on('click', function () { abrirFolhaSecoes(false); });
        $(document).on('keydown', function (e) { if (e.key === 'Escape' && !$('#folha-secoes').prop('hidden')) abrirFolhaSecoes(false); });
        $('#folha-secoes-lista').on('click', '.folha-secoes-item', function () {
            const secao = String($(this).data('secao'));
            abrirFolhaSecoes(false);
            trocarSecao(secao);
        });
        $('#barra-anterior').on('click', function () { irParaSecaoVizinha(-1); });
        $('#barra-proxima').on('click', function () { irParaSecaoVizinha(1); });
        atualizarNavegacaoMobile();
        $('#btn-recolher-nav').on('click', function () {
            $('#basico-nav').toggleClass('recolhida');
        });
        $('#btn-editar-nome').on('click', function () {
            const $campo = $('#nome-fluxo');
            const editando = !$campo.prop('hidden');
            $campo.prop('hidden', editando);
            $('#titulo-fluxo').prop('hidden', !editando);
            if (editando) atualizarTituloFluxo(); else $campo.trigger('focus');
        });
        $('#nome-fluxo').on('keydown', function (e) {
            if (e.key === 'Enter') $('#btn-editar-nome').trigger('click');
        });
        $('#editor-fluxo').on('input change', 'input:not(:disabled), select:not(:disabled), textarea', function () {
            if (this.id !== 'select-bot-vincular') marcarSujo();
        });
        $('#select-bot-vincular').on('change', function () {
            const id_bot = parseInt($(this).val(), 10);
            if (!id_bot) return;
            const id_fluxo = parseInt($('#id-fluxo').val(), 10);
            if (id_fluxo) {
                const bot_escolhido = bots_disponiveis.filter(function (b) { return String(b.id) === String(id_bot); })[0];
                if (!$('#lista-planos .campo-plano-nome').length && bot_escolhido && bot_escolhido.nome_fluxo && !window.confirm(avisoBotSemPlano(bot_escolhido, 'Vincular aqui'))) {
                    $(this).val('');
                    return;
                }
                vincularBot(id_bot, id_fluxo);
                return;
            }
            // Fluxo novo: guarda a escolha; o vínculo acontece no primeiro "Salvar alterações".
            const bot = bots_do_usuario.filter(function (b) { return String(b.id) === String(id_bot); })[0];
            if (bot && !bots_pendentes.some(function (b) { return String(b.id) === String(id_bot); })) { bots_pendentes.push(bot); marcarSujo(); }
            renderBotsPendentes();
        });
        $(document).on('click', '.tirar-bot-pendente', function () {
            const id_bot = String($(this).data('id'));
            bots_pendentes = bots_pendentes.filter(function (b) { return String(b.id) !== id_bot; });
            renderBotsPendentes();
        });
        $('#basico-conteudo').on('change', '.of-ativo', function () {
            mostrarDetalhesOferta($(this).closest('.painel'));
        }).on('change', '.of-esconder-recusar', function () {
            mostrarCamposRecusar($(this).closest('.painel'));
        }).on('change input', '.of-quando, .of-envios', function () {
            mostrarTemporizadorDownsell($(this).closest('.painel'));
        }).on('change', '.painel[data-oferta="order_bump"] .of-quando', atualizarModoBump)
          .on('change', '.painel[data-oferta="downsell"] .of-quando', atualizarModoDownsell);

        // Downsell 2 a 5: adicionar, remover e a mídia de cada um.
        $('#dn-adicionar').on('click', function () {
            sincronizarDownsellExtrasDoDom();
            if (downsell_extras.length + 1 >= downsell_max) return;
            downsell_extras.push(itemDownsellVazio());
            renderDownsellExtras();
            marcarSujo();
        });
        $('#dn-extras').on('click', '.dn-remover', function () {
            sincronizarDownsellExtrasDoDom();
            downsell_extras.splice(parseInt($(this).closest('.dn-extra').data('i'), 10), 1);
            renderDownsellExtras();
            marcarSujo();
        }).on('click', '.dn-midia .midia-escolher, .dn-midia .midia-trocar', function () {
            const $c = $(this).closest('.dn-extra'), it = downsell_extras[parseInt($c.data('i'), 10)];
            if (!it || downsell_lotes[it.uid]) return;
            downsell_destino_upload[it.uid] = 0;
            // trocar a 1ª é 1 arquivo só; com a caixa vazia pode marcar vários
            $c.find('.dn-midia-arquivo').prop('multiple', it.midias.length === 0).trigger('click');
        }).on('click', '.dn-midia .midia-tirar', function () {
            const $c = $(this).closest('.dn-extra'), it = downsell_extras[parseInt($c.data('i'), 10)];
            if (!it) return;
            it.midias.shift();   // a seguinte (se houver) passa a ser a 1ª
            atualizarMidiasDoExtra($c, it);
            marcarSujo();
            exibirAviso('Mídia tirada da oferta. Clique em Salvar alterações para aplicar.');
        }).on('click', '.dn-midias-adicionar', function () {
            const $c = $(this).closest('.dn-extra'), it = downsell_extras[parseInt($c.data('i'), 10)];
            if (!it || it.midias.length >= downsell_midias_max || downsell_lotes[it.uid]) return;
            downsell_destino_upload[it.uid] = it.midias.length;
            $c.find('.dn-midia-arquivo').prop('multiple', true).trigger('click');
        }).on('click', '.dn-midias-extras .dn-midia-remover', function () {
            const $c = $(this).closest('.dn-extra'), it = downsell_extras[parseInt($c.data('i'), 10)];
            if (!it) return;
            it.midias.splice(parseInt($(this).closest('[data-i]').data('i'), 10), 1);
            atualizarMidiasDoExtra($c, it);
            marcarSujo();
        }).on('click', '.dn-midias-extras .dn-midia-subir', function () {
            const $c = $(this).closest('.dn-extra'), it = downsell_extras[parseInt($c.data('i'), 10)];
            const j = parseInt($(this).closest('[data-i]').data('i'), 10);
            if (it && j > 0) { it.midias.splice(j - 1, 0, it.midias.splice(j, 1)[0]); atualizarMidiasDoExtra($c, it); marcarSujo(); }
        }).on('change', '.dn-midia-arquivo', function () {
            const input = this;
            const arquivos = Array.prototype.slice.call(input.files || []);   // copiar antes de limpar: limpar o campo esvazia a lista
            input.value = '';
            const it = downsell_extras[parseInt($(input).closest('.dn-extra').data('i'), 10)];
            if (!arquivos.length || !it || downsell_lotes[it.uid]) return;
            const uid = it.uid;
            // Destino 0 = a 1ª mídia entra (ou troca) na caixa grande; as demais e o botão "+ Adicionar outra" vão sempre para o fim da lista.
            const na_caixa_grande = Math.min(downsell_destino_upload[uid] || 0, it.midias.length) === 0;
            enviarMidiasEmSequencia(arquivos, {
                vagas: na_caixa_grande ? (it.midias.length ? 1 : downsell_midias_max) : downsell_midias_max - it.midias.length,
                enviar: enviarMidiaBasico,
                aoProgredir: function (n, total) { downsell_lotes[uid] = { n: n, total: total }; atualizarMidiasDoExtraPorUid(uid, na_caixa_grande && n === 1); },
                aoSubir: function (midia, k) {
                    const alvo = downsell_extras.filter(function (e) { return e.uid === uid; })[0];
                    if (!alvo) return;   // o cartão foi removido enquanto o arquivo subia
                    const nova = { tipo: midia.tipo, path: midia.caminho };
                    if (na_caixa_grande && k === 0) alvo.midias[0] = nova; else alvo.midias.push(nova);
                    atualizarMidiasDoExtraPorUid(uid);
                    marcarSujo();
                },
                aoFim: function (r) {
                    delete downsell_lotes[uid];
                    atualizarMidiasDoExtraPorUid(uid);
                    avisarResultadoLote(r, 'Mídia anexada à oferta. Clique em Salvar alterações para aplicar.', downsell_midias_max);
                }
            });
        });
        // "Criar planos" e os planos criados dentro do Downsell (valem para o Downsell 1 e para os cartões 2 a 5).
        $('#basico-conteudo').on('change', '.dn-criar', function () {
            atualizarModoCriarPlanos($(this).closest('.dn-seq'));
            const $bloco = $(this).closest('.dn-planos');
            if ($(this).is(':checked') && !$bloco.find('.dn-proprio').length) adicionarPlanoProprioDownsell($bloco);
        }).on('click', '.dn-proprio-adicionar', function () {
            adicionarPlanoProprioDownsell($(this).closest('.dn-planos'));
            marcarSujo();
        }).on('click', '.dn-proprio-remover', function () {
            const $bloco = $(this).closest('.dn-planos');
            $(this).closest('.dn-proprio').remove();
            atualizarVaziosProprios($bloco);
            marcarSujo();
        }).on('click', '.dn-proprio .plano-topo', function () {
            const $c = $(this).closest('.dn-proprio');
            const recolhido = !$c.hasClass('recolhido');
            $c.toggleClass('recolhido', recolhido);
            $c.find('.plano-alternar').attr({ 'aria-expanded': String(!recolhido), title: recolhido ? 'Expandir plano' : 'Recolher plano', 'aria-label': recolhido ? 'Expandir plano' : 'Recolher plano' });
        }).on('input change', '.dn-proprio', function () {
            resumoPlanoProprioDownsell($(this));
        });

        // Order Bumps 2 a 5: adicionar, remover e a mídia de cada um.
        $('#bp-adicionar').on('click', function () {
            sincronizarBumpsDoDom();
            if (bump_extras.length + 1 >= bump_max) return;
            bump_extras.push(itemBumpVazio());
            renderBumpsExtras();
            marcarSujo();
        });
        $('#bp-extras').on('click', '.bp-remover', function () {
            sincronizarBumpsDoDom();
            bump_extras.splice(parseInt($(this).closest('.bp-item').data('i'), 10), 1);
            renderBumpsExtras();
            marcarSujo();
        }).on('click', '.bp-midia .midia-escolher, .bp-midia .midia-trocar', function () {
            $(this).closest('.bp-item').find('.bp-midia-arquivo').trigger('click');
        }).on('click', '.bp-midia .midia-tirar', function () {
            sincronizarBumpsDoDom();
            const it = bump_extras[parseInt($(this).closest('.bp-item').data('i'), 10)];
            if (it) { it.midia_tipo = 'none'; it.midia_path = ''; }
            renderBumpsExtras();
            marcarSujo();
            exibirAviso('Mídia tirada da oferta. Clique em Salvar alterações para aplicar.');
        }).on('change', '.bp-midia-arquivo', function () {
            const input = this;
            if (!input.files || !input.files[0]) return;
            const arquivo = input.files[0];
            input.value = '';
            const $item = $(input).closest('.bp-item');
            const i = parseInt($item.data('i'), 10);
            desenharMidia($item.find('.bp-midia'), 'none', '', true, '');
            enviarMidiaBasico(arquivo, function (midia, erro) {
                sincronizarBumpsDoDom();
                if (!midia) {
                    renderBumpsExtras();
                    exibirAviso(erro, 'erro');
                    return;
                }
                if (bump_extras[i]) { bump_extras[i].midia_tipo = midia.tipo; bump_extras[i].midia_path = midia.caminho; }
                renderBumpsExtras();
                marcarSujo();
                exibirAviso('Mídia anexada à oferta. Clique em Salvar alterações para aplicar.');
            });
        });
        $('#btn-atualizar-bots, #btn-atualizar-cache').on('click', function () {
            carregarGruposUsuario().always(carregarBots);
        });
        $('#fluxo-ativo').on('change', function () { atualizarRotuloAtivo(); marcarSujo(); });
        $(document).on('click', '.desvincular-bot', function () {
            vincularBot(parseInt($(this).data('id'), 10), 0);
        });
        $(window).on('beforeunload', function (e) {
            if ($('#status-salvo').hasClass('sujo')) { e.preventDefault(); return ''; }
        });

        $('#btn-salvar-fluxo-basico').on('click', salvarFluxo);
        $('#btn-excluir-fluxo-basico').on('click', excluirFluxo);

        $('#btn-adicionar-plano').on('click', function () {
            sincronizarPlanosDoDom();
            planos.push({ id: 'plano_' + Date.now(), nome: '', valor: 0, dias_acesso: 30, unidade_acesso: 'dias', id_grupo: '' });
            renderPlanos();
            marcarSujo();
        });
        $('#lista-planos').on('click', '.plano-topo', function () {
            const $p = $(this).closest('.painel-plano');
            definirPlanoRecolhido($p, !$p.hasClass('recolhido'));
        }).on('input change', '.painel-plano', function () {
            atualizarResumoPlano($(this));
        });
        $(document).on('click', '.remover-plano', function () {
            sincronizarPlanosDoDom();
            const idx = parseInt($(this).closest('.painel-plano').data('index'), 10);
            planos.splice(idx, 1);
            renderPlanos();
            marcarSujo();
        });

        $('#bv-mostrar-cta').on('change', mostrarCamposCta);
        // Posição que o próximo arquivo escolhido vai ocupar: 0 = caixa grande (anexa ou troca a 1ª), bv_midias.length = no fim da lista.
        let bv_destino_upload = 0;
        $('#bv-midia-previa').on('click', '.midia-escolher, .midia-trocar', function () {
            if (bv_lote) return;
            bv_destino_upload = 0;
            $('#bv-midia-arquivo').prop('multiple', bv_midias.length === 0).trigger('click');   // trocar a 1ª é 1 arquivo só; com a caixa vazia pode marcar vários
        }).on('click', '.midia-tirar', function () {
            bv_midias.shift();   // a seguinte (se houver) passa a ser a 1ª
            atualizarPreviaMidia();
            marcarSujo();
            exibirAviso('Mídia tirada das boas-vindas. Clique em Salvar alterações para aplicar.');
        });
        $('#bv-midias-adicionar').on('click', function () {
            if (bv_midias.length >= bv_midias_max || bv_lote) return;
            bv_destino_upload = bv_midias.length;
            $('#bv-midia-arquivo').prop('multiple', true).trigger('click');
        });
        $('#bv-midias-extras').on('click', '.bv-remover', function () {
            bv_midias.splice(parseInt($(this).closest('[data-i]').data('i'), 10), 1);
            atualizarPreviaMidia();
            marcarSujo();
        }).on('click', '.bv-subir', function () {
            const i = parseInt($(this).closest('[data-i]').data('i'), 10);
            if (i > 0) { bv_midias.splice(i - 1, 0, bv_midias.splice(i, 1)[0]); atualizarPreviaMidia(); marcarSujo(); }
        });
        $('#bv-midia-arquivo').on('change', function () {
            const input = this;
            const arquivos = Array.prototype.slice.call(input.files || []);   // copiar antes de limpar: limpar o campo esvazia a lista
            input.value = '';  // permite escolher o mesmo arquivo de novo depois (sem isso o "change" não dispara)
            if (!arquivos.length || bv_lote) return;
            // Destino 0 = a 1ª mídia entra (ou troca) na caixa grande; as demais e o botão "+ Adicionar outra" vão sempre para o fim da lista.
            const na_caixa_grande = Math.min(bv_destino_upload, bv_midias.length) === 0;
            enviarMidiasEmSequencia(arquivos, {
                vagas: na_caixa_grande ? (bv_midias.length ? 1 : bv_midias_max) : bv_midias_max - bv_midias.length,
                enviar: enviarMidiaBasico,
                aoProgredir: function (n, total) { bv_lote = { n: n, total: total }; atualizarPreviaMidia(na_caixa_grande && n === 1); },
                aoSubir: function (midia, k) {
                    const nova = { tipo: midia.tipo, path: midia.caminho };
                    if (na_caixa_grande && k === 0) bv_midias[0] = nova; else bv_midias.push(nova);
                    atualizarPreviaMidia();
                    marcarSujo();
                },
                aoFim: function (r) {
                    bv_lote = null;
                    atualizarPreviaMidia();
                    avisarResultadoLote(r, 'Mídia anexada. Clique em Salvar alterações para aplicar.', bv_midias_max);
                }
            });
        });

        // Mídia das ofertas (Upsell, Downsell e Order Bump): um único conjunto de handlers para as 3.
        const tipoDaOferta = function (el) { return $(el).closest('.painel[data-oferta]').data('oferta'); };
        $('#basico-conteudo').on('click', '.of-midia .midia-escolher, .of-midia .midia-trocar', function () {
            const tipo = tipoDaOferta(this);
            if (ofertas_lote[tipo]) return;
            ofertas_destino_upload[tipo] = 0;
            // trocar a 1ª é 1 arquivo só; com a caixa vazia pode marcar vários (o Order Bump aceita 1 mídia, então nunca)
            $(this).closest('.painel[data-oferta]').find('.of-midia-arquivo').prop('multiple', ofertas_midias_max[tipo] > 1 && ofertas_midias[tipo].length === 0).trigger('click');
        }).on('click', '.of-midia .midia-tirar', function () {
            const tipo = tipoDaOferta(this);
            ofertas_midias[tipo].shift();   // a seguinte (se houver) passa a ser a 1ª
            atualizarMidiaOferta(tipo);
            marcarSujo();
            exibirAviso('Mídia tirada da oferta. Clique em Salvar alterações para aplicar.');
        }).on('click', '.of-midias-adicionar', function () {
            const tipo = tipoDaOferta(this);
            if (ofertas_midias[tipo].length >= ofertas_midias_max[tipo] || ofertas_lote[tipo]) return;
            ofertas_destino_upload[tipo] = ofertas_midias[tipo].length;
            $(this).closest('.painel[data-oferta]').find('.of-midia-arquivo').prop('multiple', true).trigger('click');
        }).on('click', '.of-midias-extras .of-remover', function () {
            const tipo = tipoDaOferta(this);
            ofertas_midias[tipo].splice(parseInt($(this).closest('[data-i]').data('i'), 10), 1);
            atualizarMidiaOferta(tipo);
            marcarSujo();
        }).on('click', '.of-midias-extras .of-subir', function () {
            const tipo = tipoDaOferta(this);
            const i = parseInt($(this).closest('[data-i]').data('i'), 10);
            if (i > 0) { ofertas_midias[tipo].splice(i - 1, 0, ofertas_midias[tipo].splice(i, 1)[0]); atualizarMidiaOferta(tipo); marcarSujo(); }
        }).on('change', '.of-midia-arquivo', function () {
            const input = this;
            const arquivos = Array.prototype.slice.call(input.files || []);   // copiar antes de limpar: limpar o campo esvazia a lista
            input.value = '';
            const tipo = tipoDaOferta(input);
            if (!arquivos.length || ofertas_lote[tipo]) return;
            const lista = ofertas_midias[tipo], maximo = ofertas_midias_max[tipo];
            // Destino 0 = a 1ª mídia entra (ou troca) na caixa grande; as demais e o botão "+ Adicionar outra" vão sempre para o fim da lista.
            const na_caixa_grande = Math.min(ofertas_destino_upload[tipo] || 0, lista.length) === 0;
            enviarMidiasEmSequencia(arquivos, {
                vagas: na_caixa_grande ? (lista.length ? 1 : maximo) : maximo - lista.length,
                enviar: enviarMidiaBasico,
                aoProgredir: function (n, total) { ofertas_lote[tipo] = { n: n, total: total }; atualizarMidiaOferta(tipo, na_caixa_grande && n === 1); },
                aoSubir: function (midia, k) {
                    const nova = { tipo: midia.tipo, path: midia.caminho };
                    if (na_caixa_grande && k === 0) ofertas_midias[tipo][0] = nova; else ofertas_midias[tipo].push(nova);
                    atualizarMidiaOferta(tipo);
                    marcarSujo();
                },
                aoFim: function (r) {
                    ofertas_lote[tipo] = null;
                    atualizarMidiaOferta(tipo);
                    avisarResultadoLote(r, 'Mídia anexada à oferta. Clique em Salvar alterações para aplicar.', maximo);
                }
            });
        });
    });
})(jQuery);
