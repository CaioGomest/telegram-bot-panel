(function ($) {
    const url_api = 'api.php';
    const ICONE_COPIAR = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>';
    const ICONE_EDITAR = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>';
    const ICONE_LIXO = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>';

    let opcoes = { bots: [], dominios: [], plataformas: {}, host: location.host, esquema: location.protocol.replace(':', ''), base: '' };
    let links = [];
    let ed = null;          // estado do editor
    let ed_original = '';   // snapshot pra detectar alteração
    let salvando = false;

    function exibirAviso(msg, tipo) {
        const $t = $('#toast');
        $t.removeClass('sucesso erro visivel').addClass(tipo || 'sucesso').text(msg);
        requestAnimationFrame(function () { $t.addClass('visivel'); });
        clearTimeout(window.__toastTimeout);
        window.__toastTimeout = setTimeout(function () { $t.removeClass('visivel'); }, 3200);
    }

    function esc(v) {
        return String(v == null ? '' : v)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    function moeda(v) {
        return 'R$ ' + (parseFloat(v) || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function numero(v) {
        return (parseInt(v, 10) || 0).toLocaleString('pt-BR');
    }

    function copiar(texto) {
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(texto).then(function () { exibirAviso('Copiado!'); }, function () { copiarAlternativo(texto); });
        } else {
            copiarAlternativo(texto);
        }
    }

    function copiarAlternativo(texto) {
        const $tmp = $('<textarea>').val(texto).appendTo('body').select();
        try { document.execCommand('copy'); exibirAviso('Copiado!'); } catch (e) { exibirAviso('Não foi possível copiar.', 'erro'); }
        $tmp.remove();
    }

    function mensagemErro(xhr, padrao) {
        return (xhr && xhr.responseJSON && xhr.responseJSON.mensagem) || padrao;
    }

    function urlBase(dominio) {
        return opcoes.esquema + '://' + (dominio || opcoes.host) + opcoes.base + '/l/';
    }

    /* ========== Lista ========== */

    function carregarOpcoes() {
        return $.getJSON(url_api + '?action=opcoes_redirecionamento').done(function (res) {
            if (res.sucesso) {
                opcoes = $.extend(opcoes, res);
            }
        });
    }

    function carregarLinks() {
        $('#rd-lista').html('<p class="texto-suave" style="padding:32px;text-align:center;">Carregando...</p>');
        return $.getJSON(url_api + '?action=listar_redirecionadores').done(function (res) {
            if (!res.sucesso) {
                $('#rd-lista').html('<p style="padding:32px;text-align:center;color:var(--da);">' + esc(res.mensagem || 'Erro ao carregar.') + '</p>');
                return;
            }
            links = res.links || [];
            renderizarLista();
            renderizarPreparar();
        }).fail(function (xhr) {
            $('#rd-lista').html('<p style="padding:32px;text-align:center;color:var(--da);">' + esc(mensagemErro(xhr, 'Não foi possível carregar os links.')) + '</p>');
        });
    }

    function renderizarLista() {
        let cliques = 0, starts = 0, vendas = 0;
        links.forEach(function (l) { cliques += l.cliques; starts += l.starts; vendas += l.vendas_valor; });
        $('#rd-kpi-links').text(numero(links.length));
        $('#rd-kpi-cliques').text(numero(cliques));
        $('#rd-kpi-starts').text(numero(starts));
        $('#rd-kpi-vendas').text(moeda(vendas));
        $('#rd-contador').text(links.length);

        if (!links.length) {
            $('#rd-lista').html(
                '<div class="rd-vazio">' +
                '<div class="rd-vazio-icone"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 3h5v5M4 20L21 3M21 16v5h-5M15 15l6 6M4 4l5 5"></path></svg></div>' +
                '<h3>Nenhum redirecionador criado</h3>' +
                '<p class="texto-suave">Crie um link curto, publique nos seus anúncios e acompanhe cliques, starts e vendas de cada campanha.</p>' +
                '<button type="button" class="botao botao-primario" id="rd-btn-primeiro">Criar Primeiro Link</button>' +
                '</div>'
            );
            return;
        }

        let html = '<div class="tabela-dados"><table><thead><tr>' +
            '<th>Link</th><th>Destino</th><th>Cliques</th><th>Starts</th><th>Leads</th><th>Vendas</th><th>Ativo</th><th>Ações</th>' +
            '</tr></thead><tbody>';
        links.forEach(function (l) {
            const plat = (opcoes.plataformas[l.plataforma] || {}).nome || '';
            const destino = l.qtd_destinos
                ? l.qtd_destinos + (l.qtd_destinos === 1 ? ' bot' : ' bots · ' + (l.modo === 'sequencial' ? 'sequencial' : 'aleatório'))
                : '<span class="texto-suave">Sem bot</span>';
            html += '<tr>' +
                '<td><div class="rd-celula-titulo"><strong>' + esc(l.titulo) + '</strong>' +
                '<span class="rd-celula-url" title="' + esc(l.url) + '">' + esc(l.url.replace(/^https?:\/\//, '')) + '</span>' +
                (plat ? '<span class="badge badge-neutro">' + esc(plat) + '</span>' : '') + '</div></td>' +
                '<td>' + destino + '</td>' +
                '<td>' + numero(l.cliques) + '<div class="texto-suave rd-sub">' + numero(l.cliques_7d) + ' em 7 dias</div></td>' +
                '<td>' + numero(l.starts) + '</td>' +
                '<td>' + numero(l.leads) + '</td>' +
                '<td>' + numero(l.vendas_qtd) + '<div class="texto-suave rd-sub">' + moeda(l.vendas_valor) + '</div></td>' +
                '<td><label class="basico-interruptor rd-mini"><input type="checkbox" class="rd-toggle" data-id="' + l.id + '"' + (l.ativo ? ' checked' : '') + '></label></td>' +
                '<td><div class="col-acoes">' +
                '<button type="button" class="btn-icon rd-copiar" data-url="' + esc(l.url) + '" title="Copiar link">' + ICONE_COPIAR + '</button>' +
                '<button type="button" class="btn-icon editar rd-editar" data-id="' + l.id + '" title="Editar">' + ICONE_EDITAR + '</button>' +
                '<button type="button" class="btn-icon excluir rd-excluir" data-id="' + l.id + '" title="Excluir">' + ICONE_LIXO + '</button>' +
                '</div></td></tr>';
        });
        html += '</tbody></table></div>';
        $('#rd-lista').html(html);
    }

    function alternar(id, ativo, $caixa) {
        $.ajax({
            url: url_api + '?action=alternar_redirecionador', method: 'POST', contentType: 'application/json',
            data: JSON.stringify({ id: id, ativo: ativo })
        }).done(function (res) {
            exibirAviso(res.mensagem || (ativo ? 'Link ativado.' : 'Link desativado.'));
            const l = links.find(function (x) { return x.id === id; });
            if (l) { l.ativo = ativo ? 1 : 0; }
        }).fail(function (xhr) {
            $caixa.prop('checked', !ativo);
            exibirAviso(mensagemErro(xhr, 'Não foi possível alterar.'), 'erro');
        });
    }

    function excluir(id) {
        const l = links.find(function (x) { return x.id === id; });
        if (!confirm('Excluir o link "' + (l ? l.titulo : '') + '"? Os anúncios que usam esse endereço vão parar de funcionar. Não dá pra desfazer.')) { return; }
        $.ajax({
            url: url_api + '?action=excluir_redirecionador', method: 'POST', contentType: 'application/json',
            data: JSON.stringify({ id: id })
        }).done(function () {
            exibirAviso('Link excluído.');
            carregarLinks();
        }).fail(function (xhr) {
            exibirAviso(mensagemErro(xhr, 'Não foi possível excluir.'), 'erro');
        });
    }

    /* ========== Editor ========== */

    function estadoVazio() {
        return {
            id: 0, titulo: '', ativo: true, dominio: '', formato: 'aleatorio', slug: '',
            plataforma: 'meta', protecao: 'nenhuma', modo: 'aleatorio', bots: [], url: ''
        };
    }

    function fotoEstado() {
        return JSON.stringify(ed);
    }

    function abrirEditor(estado) {
        ed = estado;
        ed_original = fotoEstado();
        const edicao = ed.id > 0;

        $('#rd-editor-titulo').text(edicao ? 'Editar link' : 'Criar novo link');
        $('.rd-editor-sobre').text(edicao ? 'Redirecionador' : 'Novo redirecionador');
        $('#rd-salvar').text(edicao ? 'Salvar alterações' : 'Criar redirecionador').prop('disabled', false);
        $('#rd-titulo').val(ed.titulo);
        $('#rd-ativo').prop('checked', ed.ativo);
        $('#rd-slug').val(ed.slug).prop('readonly', edicao);
        $('#rd-campo-formato').prop('hidden', edicao);
        $('#rd-aviso-slug').prop('hidden', !edicao);
        $('#rd-editor').removeClass('ver-jornada');
        $('#rd-ver-jornada').text('Ver jornada');

        renderizarDominios();
        renderizarFormatos();
        renderizarPlataformas();
        renderizarBots();
        marcarCartoes('#rd-protecoes', 'protecao', ed.protecao);
        marcarCartoes('#rd-modos', 'modo', ed.modo);
        atualizarTudo();

        $('#rd-editor').addClass('aberto').attr('aria-hidden', 'false');
        $('body').addClass('rd-sem-rolagem');
        $('.rd-editor-corpo').scrollTop(0);
        $('#rd-nav a').removeClass('ativo').first().addClass('ativo');
        if (!edicao) { $('#rd-titulo').trigger('focus'); }
    }

    function fecharEditor(forcar) {
        if (!forcar && ed && fotoEstado() !== ed_original && !confirm('Descartar as alterações deste link?')) { return; }
        $('#rd-editor').removeClass('aberto').attr('aria-hidden', 'true');
        $('body').removeClass('rd-sem-rolagem');
        ed = null;
    }

    function marcarCartoes(seletor, atributo, valor) {
        $(seletor + ' .rd-cartao').each(function () {
            $(this).toggleClass('ativo', $(this).data(atributo) === valor);
        });
    }

    function renderizarDominios() {
        const lista = [''].concat(opcoes.dominios || []);
        if (ed.dominio && lista.indexOf(ed.dominio) === -1) { lista.push(ed.dominio); }
        let html = '';
        lista.forEach(function (d) {
            html += '<button type="button" class="rd-cartao' + (d === ed.dominio ? ' ativo' : '') + '" data-dominio="' + esc(d) + '">' +
                '<strong>' + esc(d || opcoes.host) + '</strong><span>' + (d ? 'Domínio próprio' : 'Endereço padrão do painel') + '</span></button>';
        });
        $('#rd-dominios').html(html);
        $('#rd-campo-dominio').prop('hidden', lista.length < 2 && !ed.dominio);
    }

    function renderizarFormatos() {
        marcarCartoes('#rd-formatos', 'formato', ed.formato);
        $('#rd-campo-slug').prop('hidden', !(ed.id > 0 || ed.formato === 'personalizado'));
    }

    function renderizarPlataformas() {
        let html = '';
        Object.keys(opcoes.plataformas).forEach(function (k) {
            html += '<button type="button" class="rd-cartao' + (k === ed.plataforma ? ' ativo' : '') + '" data-plataforma="' + esc(k) + '"><strong>' + esc(opcoes.plataformas[k].nome) + '</strong></button>';
        });
        $('#rd-plataformas').html(html);
    }

    function renderizarBots() {
        if (!opcoes.bots.length) {
            $('#rd-bots').html('<p class="texto-suave">Você ainda não tem bots com @usuário. <a href="bots" style="color:var(--or);">Cadastre um bot</a> primeiro.</p>');
            return;
        }
        let html = '';
        opcoes.bots.forEach(function (b) {
            const marcado = ed.bots.indexOf(b.id) !== -1;
            html += '<label class="rd-bot' + (marcado ? ' ativo' : '') + '">' +
                '<input type="checkbox" class="rd-bot-caixa" value="' + b.id + '"' + (marcado ? ' checked' : '') + '>' +
                '<span class="rd-bot-info"><strong>' + esc(b.nome) + '</strong><span>@' + esc(b.usuario) + '</span></span>' +
                '<span class="badge ' + (b.fluxo ? 'badge-sucesso' : 'badge-neutro') + '">' + esc(b.fluxo || 'Sem fluxo') + '</span></label>';
        });
        $('#rd-bots').html(html);
    }

    function slugPrevia() {
        if (ed.id > 0) { return ed.slug; }
        if (ed.formato === 'personalizado') { return ed.slug || 'seu-endereco'; }
        return '••••••••';
    }

    function atualizarTudo() {
        const slug = slugPrevia();
        const url = urlBase(ed.dominio) + slug;
        $('#rd-slug-prefixo').text(urlBase(ed.dominio).replace(/^https?:\/\//, ''));
        $('#rd-previa-slug').text('/l/' + slug);
        $('#rd-previa-url').text(url);
        $('#rd-previa-status').text(ed.ativo ? 'Ativo' : 'Inativo').toggleClass('sujo', !ed.ativo);

        $('#rd-j-entrada').text(url.replace(/^https?:\/\//, ''));
        const plat = opcoes.plataformas[ed.plataforma] || {};
        $('#rd-j-atribuicao').text((plat.query ? plat.nome + ': UTMs de campanha, anúncio e conjunto' : 'Sem UTMs automáticas') + '. Quem chegar fica marcado como rd_' + (ed.id > 0 || ed.formato === 'personalizado' ? slug : 'código') + '.');
        $('#rd-j-protecao').text(ed.protecao === 'filtrar_robos' ? 'Robôs e pré-visualizações não entram na contagem.' : 'Todos os cliques são contados.');

        const nomes = opcoes.bots.filter(function (b) { return ed.bots.indexOf(b.id) !== -1; }).map(function (b) { return '@' + b.usuario; });
        let destino;
        if (!nomes.length) { destino = 'Escolha ao menos um bot.'; }
        else if (nomes.length === 1) { destino = nomes[0] + ' no Telegram.'; }
        else { destino = nomes.length + ' bots (' + (ed.modo === 'sequencial' ? 'revezamento' : 'sorteio') + '): ' + nomes.slice(0, 3).join(', ') + (nomes.length > 3 ? '…' : '') + '.'; }
        $('#rd-j-destino').text(destino);

        $('#rd-campo-modo').prop('hidden', ed.bots.length < 2);
        atualizarUrlsOrigem();
    }

    function atualizarUrlsOrigem() {
        const plat = opcoes.plataformas[ed.plataforma] || {};
        if (ed.id <= 0) {
            $('#rd-origem-urls').html('<p class="texto-suave rd-dica">As URLs prontas com as UTMs aparecem aqui depois de criar o link (e também na aba "Preparar campanha").</p>');
            return;
        }
        $('#rd-origem-urls').html(linhasCopiaveis(ed.url, plat) + '<p class="texto-suave rd-dica">' + esc(plat.onde || '') + '</p>');
    }

    function linhasCopiaveis(url, plat) {
        const query = plat.query || '';
        let html = linhaCopia('URL do link', url);
        if (query) {
            html += linhaCopia('Parâmetros de URL', query);
            html += linhaCopia('URL completa', url + '?' + query);
        }
        return html;
    }

    function linhaCopia(rotulo, valor) {
        return '<div class="rd-copia"><span class="rd-copia-rotulo">' + esc(rotulo) + '</span>' +
            '<code>' + esc(valor) + '</code>' +
            '<button type="button" class="btn-icon rd-copiar" data-url="' + esc(valor) + '" title="Copiar" aria-label="Copiar ' + esc(rotulo) + '">' + ICONE_COPIAR + '</button></div>';
    }

    function salvar() {
        if (salvando || !ed) { return; }
        const titulo = $.trim(ed.titulo);
        if (!titulo) { exibirAviso('Dê um nome ao link.', 'erro'); irPara('#rd-sec-ident'); $('#rd-titulo').trigger('focus'); return; }
        if (ed.id <= 0 && ed.formato === 'personalizado' && !/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(ed.slug || '')) {
            exibirAviso('Endereço inválido: use letras minúsculas, números e hífens.', 'erro'); irPara('#rd-sec-endereco'); return;
        }
        if (ed.id <= 0 && ed.formato === 'personalizado' && (ed.slug.length < 3 || ed.slug.length > 40)) {
            exibirAviso('O endereço precisa ter de 3 a 40 caracteres.', 'erro'); irPara('#rd-sec-endereco'); return;
        }
        if (ed.ativo && !ed.bots.length) {
            exibirAviso('Escolha ao menos um bot de destino ou deixe o link inativo.', 'erro'); irPara('#rd-sec-destino'); return;
        }

        salvando = true;
        const $btn = $('#rd-salvar').prop('disabled', true).text('Salvando...');
        $.ajax({
            url: url_api + '?action=salvar_redirecionador', method: 'POST', contentType: 'application/json',
            data: JSON.stringify({
                id: ed.id, titulo: titulo, ativo: ed.ativo ? 1 : 0, dominio: ed.dominio, formato: ed.formato, slug: ed.slug,
                plataforma: ed.plataforma, protecao: ed.protecao, modo: ed.modo, bots: ed.bots
            })
        }).done(function (res) {
            exibirAviso(res.mensagem || 'Link salvo.');
            fecharEditor(true);
            carregarLinks();
        }).fail(function (xhr) {
            exibirAviso(mensagemErro(xhr, 'Não foi possível salvar.'), 'erro');
            $btn.prop('disabled', false).text(ed && ed.id > 0 ? 'Salvar alterações' : 'Criar redirecionador');
        }).always(function () {
            salvando = false;
        });
    }

    function irPara(seletor) {
        const el = document.querySelector(seletor);
        if (el) { el.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
    }

    function espiarRolagem() {
        const corpo = document.querySelector('.rd-editor-corpo');
        if (!corpo) { return; }
        const topo = corpo.getBoundingClientRect().top;
        let atual = null;
        document.querySelectorAll('.rd-sec').forEach(function (s) {
            if (s.getBoundingClientRect().top - topo <= 120) { atual = s.id; }
        });
        if (atual) {
            $('#rd-nav a').each(function () { $(this).toggleClass('ativo', $(this).attr('href') === '#' + atual); });
        }
    }

    function novoLink() {
        abrirEditor(estadoVazio());
    }

    function editarLink(id) {
        $.getJSON(url_api + '?action=obter_redirecionador&id=' + id).done(function (res) {
            if (!res.sucesso) { exibirAviso(res.mensagem || 'Link não encontrado.', 'erro'); return; }
            const l = res.link || res;
            abrirEditor({
                id: parseInt(l.id, 10), titulo: l.titulo || '', ativo: !!parseInt(l.ativo, 10), dominio: l.dominio || '',
                formato: 'personalizado', slug: l.slug || '', plataforma: l.plataforma || 'meta',
                protecao: l.protecao || 'nenhuma', modo: l.modo || 'aleatorio',
                bots: (l.bots || []).map(function (b) { return parseInt(b, 10); }), url: l.url || ''
            });
        }).fail(function (xhr) {
            exibirAviso(mensagemErro(xhr, 'Não foi possível carregar o link.'), 'erro');
        });
    }

    /* ========== Preparar campanha ========== */

    function renderizarPreparar() {
        const $link = $('#rd-prep-link');
        const $plat = $('#rd-prep-plataforma');
        const atualLink = parseInt($link.val(), 10) || 0;
        const atualPlat = $plat.val();

        if (!links.length) {
            $link.html('<option value="">Nenhum link criado</option>');
            $plat.html('');
            $('#rd-prep-resultado').html('<p class="texto-suave">Crie um link primeiro na aba "Meus links".</p>');
            return;
        }
        $link.html(links.map(function (l) { return '<option value="' + l.id + '">' + esc(l.titulo) + '</option>'; }).join(''));
        if (atualLink && links.some(function (l) { return l.id === atualLink; })) { $link.val(String(atualLink)); }
        $plat.html(Object.keys(opcoes.plataformas).map(function (k) { return '<option value="' + esc(k) + '">' + esc(opcoes.plataformas[k].nome) + '</option>'; }).join(''));
        const l = linkSelecionado();
        $plat.val(atualPlat && opcoes.plataformas[atualPlat] ? atualPlat : (l && l.plataforma) || 'meta');
        renderizarResultadoPreparar();
    }

    function linkSelecionado() {
        const id = parseInt($('#rd-prep-link').val(), 10);
        return links.find(function (x) { return x.id === id; }) || null;
    }

    function renderizarResultadoPreparar() {
        const l = linkSelecionado();
        if (!l) { return; }
        const plat = opcoes.plataformas[$('#rd-prep-plataforma').val()] || {};
        $('#rd-prep-resultado').html(
            '<div class="rd-origem-urls">' + linhasCopiaveis(l.url, plat) + '</div>' +
            '<p class="texto-suave rd-dica">' + esc(plat.onde || '') + '</p>' +
            '<h3 style="margin:22px 0 10px;font-size:15px;">Cliques por campanha (últimos 30 dias)</h3>' +
            '<div id="rd-prep-campanhas"><p class="texto-suave">Carregando...</p></div>'
        );
        $.getJSON(url_api + '?action=campanhas_redirecionador&id=' + l.id).done(function (res) {
            if (linkSelecionado() !== l) { return; }
            const c = res.campanhas || [];
            if (!c.length) {
                $('#rd-prep-campanhas').html('<p class="texto-suave">Ainda sem cliques com campanha identificada. Use a URL completa com as UTMs nos anúncios.</p>');
                return;
            }
            let h = '<div class="tabela-dados"><table><thead><tr><th>Campanha</th><th>Cliques</th></tr></thead><tbody>';
            c.forEach(function (x) {
                h += '<tr><td>' + (x.campanha ? esc(x.campanha) : '<span class="texto-suave">Sem campanha</span>') + '</td><td>' + numero(x.cliques) + '</td></tr>';
            });
            $('#rd-prep-campanhas').html(h + '</tbody></table></div>');
        }).fail(function () {
            $('#rd-prep-campanhas').html('<p style="color:var(--da);">Não foi possível carregar as campanhas.</p>');
        });
    }

    /* ========== Eventos ========== */

    $(function () {
        carregarOpcoes().always(carregarLinks);

        $('.aba-status').on('click', function () {
            const aba = $(this).data('aba');
            $('.aba-status').removeClass('ativa');
            $(this).addClass('ativa');
            $('#rd-aba-links').prop('hidden', aba !== 'links');
            $('#rd-aba-campanha').prop('hidden', aba !== 'campanha');
            if (aba === 'campanha') { renderizarPreparar(); }
        });

        $('#rd-btn-novo').on('click', novoLink);
        $(document).on('click', '#rd-btn-primeiro', novoLink);
        $(document).on('click', '.rd-copiar', function () { copiar($(this).attr('data-url')); });
        $(document).on('click', '.rd-editar', function () { editarLink(parseInt($(this).data('id'), 10)); });
        $(document).on('click', '.rd-excluir', function () { excluir(parseInt($(this).data('id'), 10)); });
        $(document).on('change', '.rd-toggle', function () {
            alternar(parseInt($(this).data('id'), 10), this.checked, $(this));
        });

        $('#rd-prep-link').on('change', renderizarResultadoPreparar);
        $('#rd-prep-plataforma').on('change', renderizarResultadoPreparar);

        $('#rd-voltar').on('click', function () { fecharEditor(false); });
        $('#rd-salvar').on('click', salvar);
        $(document).on('keydown', function (e) {
            if (e.key === 'Escape' && $('#rd-editor').hasClass('aberto')) { fecharEditor(false); }
        });

        $('#rd-ver-jornada').on('click', function () {
            const aberto = $('#rd-editor').toggleClass('ver-jornada').hasClass('ver-jornada');
            $(this).text(aberto ? 'Voltar ao formulário' : 'Ver jornada');
        });
        $('#rd-previa-copiar').on('click', function () { copiar($('#rd-previa-url').text()); });

        $('#rd-titulo').on('input', function () { ed.titulo = this.value; });
        $('#rd-ativo').on('change', function () { ed.ativo = this.checked; atualizarTudo(); });
        $('#rd-slug').on('input', function () {
            this.value = this.value.toLowerCase().replace(/[^a-z0-9-]/g, '');
            ed.slug = this.value;
            atualizarTudo();
        });

        $('#rd-dominios').on('click', '.rd-cartao', function () {
            ed.dominio = String($(this).data('dominio') || '');
            renderizarDominios();
            atualizarTudo();
        });
        $('#rd-formatos').on('click', '.rd-cartao', function () {
            ed.formato = $(this).data('formato');
            renderizarFormatos();
            atualizarTudo();
            if (ed.formato === 'personalizado') { $('#rd-slug').trigger('focus'); }
        });
        $('#rd-plataformas').on('click', '.rd-cartao', function () {
            ed.plataforma = $(this).data('plataforma');
            renderizarPlataformas();
            atualizarTudo();
        });
        $('#rd-protecoes').on('click', '.rd-cartao', function () {
            ed.protecao = $(this).data('protecao');
            marcarCartoes('#rd-protecoes', 'protecao', ed.protecao);
            atualizarTudo();
        });
        $('#rd-modos').on('click', '.rd-cartao', function () {
            ed.modo = $(this).data('modo');
            marcarCartoes('#rd-modos', 'modo', ed.modo);
            atualizarTudo();
        });
        $('#rd-bots').on('change', '.rd-bot-caixa', function () {
            const id = parseInt(this.value, 10);
            if (this.checked) {
                if (ed.bots.length >= 20) { this.checked = false; exibirAviso('Máximo de 20 bots por link.', 'erro'); return; }
                ed.bots.push(id);
            } else {
                ed.bots = ed.bots.filter(function (b) { return b !== id; });
            }
            $(this).closest('.rd-bot').toggleClass('ativo', this.checked);
            atualizarTudo();
        });

        $('#rd-nav').on('click', 'a', function (e) {
            e.preventDefault();
            irPara($(this).attr('href'));
        });
        $('.rd-editor-corpo').on('scroll', espiarRolagem);
    });
})(jQuery);
