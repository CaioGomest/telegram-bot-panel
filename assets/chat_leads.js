/*
 * Chat dos leads (botão "Abrir chat" em leads.php). Desenho para não pesar o painel:
 *  - O modal só é montado no primeiro clique; a lista lateral sai dos botões que a página já tem (nenhuma consulta para listar).
 *  - Só a conversa aberta é buscada (últimas 40 mensagens); rolar para cima pede a página anterior.
 *  - Tempo real = uma consulta leve (?depois=<último id>) a cada 3 s, só com o chat aberto e a aba visível; sem novidade por
 *    2 minutos, passa para 6 s. Fechou o chat ou escondeu a aba: nada é consultado.
 *  - Resposta velha (trocou de conversa no meio) é descartada; as conversas já abertas ficam em memória para trocar na hora.
 *  - Todo texto do lead entra pelo textContent (nunca innerHTML).
 */
(function () {
    'use strict';

    var botoes = document.querySelectorAll('.chat-abrir');
    if (!botoes.length) { return; }

    var URL_MENSAGENS = 'ajax/chat_mensagens.php';
    var URL_ENVIAR = 'ajax/chat_enviar.php';
    var POLL_RAPIDO = 3000;
    var POLL_LENTO = 6000;
    var APOS_SEM_NOVIDADE = 120000;
    var LARGURA_CELULAR = 720;

    var ROTULOS = {
        foto: '📷 Foto',
        video: '🎬 Vídeo',
        audio: '🎧 Áudio',
        documento: '📎 Documento',
        contato: '👤 Contato',
        botao: '🔘 Clicou no botão',
        outro: '💬 Mensagem'
    };

    var ICONE_FECHAR = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6L6 18M6 6l12 12"></path></svg>';
    var ICONE_BUSCA = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="M20 20l-4-4"></path></svg>';
    var ICONE_ESQ = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 18l-6-6 6-6"></path></svg>';
    var ICONE_DIR = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 18l6-6-6-6"></path></svg>';
    var ICONE_ENVIAR = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"></path></svg>';
    var ICONE_VAZIO = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>';
    var ICONE_OK = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6L9 17l-5-5"></path></svg>';

    var conversas = Array.prototype.map.call(botoes, function (b) {
        return {
            chave: b.getAttribute('data-bot-id') + ':' + b.getAttribute('data-tg'),
            bot_id: b.getAttribute('data-bot-id'),
            tg: b.getAttribute('data-tg'),
            nome: b.getAttribute('data-nome') || 'Sem nome',
            bot: b.getAttribute('data-bot') || '',
            inicio: b.getAttribute('data-inicio') || '',
            botao: b
        };
    });

    var estado = {
        raiz: null,
        el: {},
        ativa: -1,          // índice em `conversas`
        visiveis: [],       // índices que passam no filtro da busca
        cache: {},          // chave da conversa -> { msgs, temMais, ultimo, carregado }
        timer: null,
        controle: null,     // AbortController da carga em andamento
        ultimaNovidade: 0,
        carregando: false,
        enviando: false,
        ultimoDiaDesenhado: '',
        origemFoco: null
    };

    function criar(tag, classe, texto) {
        var e = document.createElement(tag);
        if (classe) { e.className = classe; }
        if (texto !== undefined && texto !== null) { e.textContent = texto; }
        return e;
    }

    function celular() { return window.innerWidth <= LARGURA_CELULAR; }

    function inicial(nome) { return (nome || '?').trim().charAt(0).toUpperCase() || '?'; }

    function dataCurta(iso) { // 'YYYY-MM-DD' -> 'dd/mm'
        var p = (iso || '').split('-');
        return p.length === 3 ? p[2] + '/' + p[1] : '';
    }

    function hoje() {
        var d = new Date();
        function z(n) { return (n < 10 ? '0' : '') + n; }
        return d.getFullYear() + '-' + z(d.getMonth() + 1) + '-' + z(d.getDate());
    }

    function ontem() {
        var d = new Date();
        d.setDate(d.getDate() - 1);
        function z(n) { return (n < 10 ? '0' : '') + n; }
        return d.getFullYear() + '-' + z(d.getMonth() + 1) + '-' + z(d.getDate());
    }

    function rotuloDoDia(dia) { // 'YYYY-MM-DD'
        if (dia === hoje()) { return 'Hoje'; }
        if (dia === ontem()) { return 'Ontem'; }
        var p = dia.split('-');
        return p[2] + '/' + p[1] + '/' + p[0];
    }

    function cabecalhosJson() {
        var h = { 'Accept': 'application/json' };
        if (window.CSRF_TOKEN) { h['X-CSRF-Token'] = window.CSRF_TOKEN; }
        return h;
    }

    // ------------------------------------------------------------------ montagem do modal (uma vez)
    function montar() {
        if (estado.raiz) { return; }
        var fundo = criar('div', 'chat-fundo');
        fundo.hidden = true;
        fundo.innerHTML =
            '<div class="chat-modal" role="dialog" aria-modal="true" aria-labelledby="chat-titulo">' +
              '<aside class="chat-lista">' +
                '<header class="chat-lista-topo"><h2 id="chat-titulo">Chats</h2><span class="chat-contagem"></span>' +
                  '<button type="button" class="chat-icone chat-fechar" aria-label="Fechar">' + ICONE_FECHAR + '</button></header>' +
                '<label class="chat-busca">' + ICONE_BUSCA +
                  '<input type="search" class="chat-busca-campo" placeholder="Buscar..." aria-label="Buscar conversa" autocomplete="off"></label>' +
                '<div class="chat-itens" role="list"></div>' +
              '</aside>' +
              '<section class="chat-conversa">' +
                '<header class="chat-cabecalho">' +
                  '<button type="button" class="chat-icone chat-voltar" aria-label="Voltar para a lista">' + ICONE_ESQ + '</button>' +
                  '<span class="chat-avatar"></span>' +
                  '<div class="chat-quem"><b></b><span></span></div>' +
                  '<div class="chat-nav">' +
                    '<button type="button" class="chat-icone chat-anterior" aria-label="Conversa anterior">' + ICONE_ESQ + '</button>' +
                    '<span class="chat-posicao" aria-live="polite"></span>' +
                    '<button type="button" class="chat-icone chat-proxima" aria-label="Próxima conversa">' + ICONE_DIR + '</button>' +
                  '</div>' +
                  '<button type="button" class="chat-icone chat-fechar" aria-label="Fechar">' + ICONE_FECHAR + '</button>' +
                '</header>' +
                '<div class="chat-mensagens" role="log" aria-live="polite" aria-label="Mensagens da conversa" tabindex="0"></div>' +
                '<button type="button" class="chat-novas" hidden>Novas mensagens ↓</button>' +
                '<p class="chat-erro" role="alert" hidden></p>' +
                '<form class="chat-envio" autocomplete="off">' +
                  '<textarea class="chat-campo" rows="1" maxlength="4096" placeholder="Mensagem" aria-label="Mensagem"></textarea>' +
                  '<button type="submit" class="chat-icone chat-enviar" aria-label="Enviar mensagem" disabled>' + ICONE_ENVIAR + '</button>' +
                '</form>' +
              '</section>' +
            '</div>';
        document.body.appendChild(fundo);
        fundo.querySelector('.chat-modal').setAttribute('tabindex', '-1');

        var q = function (s) { return fundo.querySelector(s); };
        estado.raiz = fundo;
        estado.el = {
            modal: q('.chat-modal'),
            contagem: q('.chat-contagem'),
            busca: q('.chat-busca-campo'),
            itens: q('.chat-itens'),
            avatar: q('.chat-cabecalho .chat-avatar'),
            nome: q('.chat-quem b'),
            sub: q('.chat-quem span'),
            posicao: q('.chat-posicao'),
            anterior: q('.chat-anterior'),
            proxima: q('.chat-proxima'),
            voltar: q('.chat-voltar'),
            mensagens: q('.chat-mensagens'),
            novas: q('.chat-novas'),
            erro: q('.chat-erro'),
            form: q('.chat-envio'),
            campo: q('.chat-campo'),
            enviar: q('.chat-enviar')
        };
        estado.el.contagem.textContent = String(conversas.length);

        Array.prototype.forEach.call(fundo.querySelectorAll('.chat-fechar'), function (b) { b.addEventListener('click', fechar); });
        fundo.addEventListener('mousedown', function (e) { if (e.target === fundo) { fechar(); } });
        // com o chat aberto a página de trás não rola: só as áreas do próprio chat
        fundo.addEventListener('wheel', function (e) { if (!e.target.closest('.chat-itens, .chat-mensagens')) { e.preventDefault(); } }, { passive: false });
        fundo.addEventListener('touchmove', function (e) { if (!e.target.closest('.chat-itens, .chat-mensagens, .chat-campo')) { e.preventDefault(); } }, { passive: false });
        fundo.addEventListener('keydown', aoTeclar);

        estado.el.busca.addEventListener('input', function () { filtrar(); });
        estado.el.anterior.addEventListener('click', function () { vizinha(-1); });
        estado.el.proxima.addEventListener('click', function () { vizinha(1); });
        estado.el.voltar.addEventListener('click', function () { estado.el.modal.classList.remove('chat-mostra-conversa'); });
        estado.el.mensagens.addEventListener('scroll', aoRolar, { passive: true });
        estado.el.novas.addEventListener('click', function () { descerAteOFim(); });
        estado.el.form.addEventListener('submit', function (e) { e.preventDefault(); enviar(); });
        estado.el.campo.addEventListener('input', aoDigitar);
        estado.el.campo.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); enviar(); }
        });
        document.addEventListener('visibilitychange', function () {
            if (!estado.raiz.hidden && !document.hidden) { buscarNovas(); }
        });
    }

    function aoTeclar(e) {
        if (e.key === 'Escape') { e.preventDefault(); fechar(); return; }
        if (e.key !== 'Tab') { return; }
        var foco = Array.prototype.filter.call(
            estado.raiz.querySelectorAll('button:not([disabled]), input, textarea, [tabindex="0"]'),
            function (n) { return n.offsetParent !== null; }
        );
        if (!foco.length) { return; }
        var primeiro = foco[0], ultimo = foco[foco.length - 1];
        if (e.shiftKey && document.activeElement === primeiro) { e.preventDefault(); ultimo.focus(); }
        else if (!e.shiftKey && document.activeElement === ultimo) { e.preventDefault(); primeiro.focus(); }
    }

    // ------------------------------------------------------------------ abrir / fechar
    function abrir(indice) {
        montar();
        estado.origemFoco = document.activeElement;
        estado.raiz.hidden = false;
        document.documentElement.classList.add('chat-aberto');
        estado.el.busca.value = '';
        filtrar();
        selecionar(indice);
        if (celular()) {
            estado.el.modal.classList.add('chat-mostra-conversa');
            estado.el.modal.focus(); // sem abrir o teclado do celular, mas o Esc e o Tab passam a valer dentro do chat
        } else {
            estado.el.campo.focus();
        }
    }

    function fechar() {
        if (!estado.raiz || estado.raiz.hidden) { return; }
        estado.raiz.hidden = true;
        document.documentElement.classList.remove('chat-aberto');
        clearTimeout(estado.timer);
        if (estado.controle) { estado.controle.abort(); estado.controle = null; }
        estado.ativa = -1;
        if (estado.origemFoco && estado.origemFoco.focus) { estado.origemFoco.focus(); }
    }

    // ------------------------------------------------------------------ lista lateral
    function filtrar() {
        var termo = estado.el.busca.value.trim().toLowerCase();
        estado.visiveis = [];
        conversas.forEach(function (c, i) {
            if (!termo || (c.nome + ' ' + c.tg + ' ' + c.bot).toLowerCase().indexOf(termo) !== -1) { estado.visiveis.push(i); }
        });
        desenharLista();
        atualizarNavegacao();
    }

    function desenharLista() {
        var lista = estado.el.itens;
        lista.textContent = '';
        if (!estado.visiveis.length) {
            lista.appendChild(criar('p', 'chat-lista-vazia', 'Nenhuma conversa encontrada.'));
            return;
        }
        var frag = document.createDocumentFragment();
        estado.visiveis.forEach(function (i) {
            var c = conversas[i];
            var item = criar('button', 'chat-item' + (i === estado.ativa ? ' ativo' : ''));
            item.type = 'button';
            item.setAttribute('role', 'listitem');
            item.setAttribute('data-indice', String(i));
            item.appendChild(criar('span', 'chat-avatar', inicial(c.nome)));
            var corpo = criar('span', 'chat-item-corpo');
            var topo = criar('span', 'chat-item-linha');
            topo.appendChild(criar('b', null, c.nome));
            topo.appendChild(criar('span', 'chat-item-data', dataCurta(c.inicio)));
            corpo.appendChild(topo);
            corpo.appendChild(criar('span', 'chat-item-id', '#' + c.tg));
            if (c.bot) { corpo.appendChild(criar('span', 'chat-item-bot', '@' + c.bot)); }
            item.appendChild(corpo);
            item.addEventListener('click', function () {
                selecionar(i);
                if (celular()) { estado.el.modal.classList.add('chat-mostra-conversa'); }
            });
            frag.appendChild(item);
        });
        lista.appendChild(frag);
    }

    function marcarAtiva() {
        Array.prototype.forEach.call(estado.el.itens.querySelectorAll('.chat-item'), function (n) {
            var ativo = Number(n.getAttribute('data-indice')) === estado.ativa;
            n.classList.toggle('ativo', ativo);
            if (ativo) { n.setAttribute('aria-current', 'true'); n.scrollIntoView({ block: 'nearest' }); }
            else { n.removeAttribute('aria-current'); }
        });
    }

    function atualizarNavegacao() {
        var pos = estado.visiveis.indexOf(estado.ativa);
        estado.el.posicao.textContent = pos === -1 ? '' : (pos + 1) + '/' + estado.visiveis.length;
        estado.el.anterior.disabled = pos <= 0;
        estado.el.proxima.disabled = pos === -1 || pos >= estado.visiveis.length - 1;
    }

    function vizinha(passo) {
        var pos = estado.visiveis.indexOf(estado.ativa);
        var alvo = estado.visiveis[pos + passo];
        if (alvo !== undefined) { selecionar(alvo); }
    }

    // ------------------------------------------------------------------ conversa
    function dadosDaConversa(c) {
        if (!estado.cache[c.chave]) { estado.cache[c.chave] = { msgs: [], temMais: false, ultimo: 0, carregado: false }; }
        return estado.cache[c.chave];
    }

    function selecionar(indice) {
        var c = conversas[indice];
        if (!c) { return; }
        clearTimeout(estado.timer);
        if (estado.controle) { estado.controle.abort(); estado.controle = null; }
        estado.ativa = indice;
        estado.carregando = false;
        estado.el.erro.hidden = true;
        estado.el.novas.hidden = true;
        estado.el.campo.value = '';
        aoDigitar();
        estado.el.avatar.textContent = inicial(c.nome);
        estado.el.nome.textContent = c.nome;
        estado.el.sub.textContent = 'ID: ' + c.tg + (c.bot ? ' · via @' + c.bot : '');
        marcarAtiva();
        atualizarNavegacao();

        var d = dadosDaConversa(c);
        if (d.carregado) {
            desenharTudo(c, d);
            descerAteOFim();
            buscarNovas();
        } else {
            estado.el.mensagens.textContent = '';
            estado.el.mensagens.appendChild(criar('p', 'chat-carregando', 'Carregando…'));
            carregarInicial(c, d);
        }
    }

    function pedir(url, opcoes) {
        return fetch(url, Object.assign({ credentials: 'same-origin', headers: cabecalhosJson() }, opcoes || {}))
            .then(function (r) { return r.json().then(function (j) { return { http: r.status, json: j }; }); });
    }

    function carregarInicial(c, d) {
        var controle = new AbortController();
        estado.controle = controle;
        var url = URL_MENSAGENS + '?bot_id=' + encodeURIComponent(c.bot_id) + '&id_telegram=' + encodeURIComponent(c.tg);
        pedir(url, { signal: controle.signal }).then(function (r) {
            if (estado.ativa === -1 || conversas[estado.ativa] !== c) { return; }
            estado.controle = null;
            if (!r.json || !r.json.sucesso) { mostrarFalhaDeCarga(c, d); return; }
            d.msgs = r.json.mensagens;
            d.temMais = !!r.json.tem_mais;
            d.ultimo = d.msgs.length ? d.msgs[d.msgs.length - 1].id : 0;
            d.carregado = true;
            desenharTudo(c, d);
            descerAteOFim();
            estado.ultimaNovidade = Date.now();
            agendar();
        }).catch(function (e) {
            if (e && e.name === 'AbortError') { return; }
            if (estado.ativa !== -1 && conversas[estado.ativa] === c) { mostrarFalhaDeCarga(c, d); }
        });
    }

    function mostrarFalhaDeCarga(c, d) {
        var area = estado.el.mensagens;
        area.textContent = '';
        var caixa = criar('div', 'chat-vazio');
        caixa.appendChild(criar('b', null, 'Não foi possível carregar a conversa'));
        var b = criar('button', 'a-btn', 'Tentar de novo');
        b.type = 'button';
        b.addEventListener('click', function () {
            area.textContent = '';
            area.appendChild(criar('p', 'chat-carregando', 'Carregando…'));
            carregarInicial(c, d);
        });
        caixa.appendChild(b);
        area.appendChild(caixa);
    }

    // ------------------------------------------------------------------ desenho das mensagens
    function criarBolha(m) {
        var enviada = m.direcao === 1;
        var linha = criar('div', 'chat-linha ' + (enviada ? 'enviada' : 'recebida'));
        var bolha = criar('div', 'chat-bolha');

        var rotulo = m.tipo === 'texto' ? '' : (ROTULOS[m.tipo] || ROTULOS.outro);
        var corpo = m.texto;
        if (m.tipo === 'botao') {
            rotulo = '';
            corpo = m.texto ? '🔘 ' + m.texto : '🔘 Clicou em um botão';
        }
        if (rotulo) { bolha.appendChild(criar('span', 'chat-tipo', rotulo)); }
        if (corpo) { bolha.appendChild(criar('span', 'chat-texto', corpo)); }

        var meta = criar('span', 'chat-meta');
        if (m.origem === 2) { meta.appendChild(criar('em', null, 'Remarketing')); }
        var hora = criar('time', null, (m.criado_em || '').slice(11, 16));
        hora.setAttribute('datetime', (m.criado_em || '').replace(' ', 'T'));
        meta.appendChild(hora);
        if (enviada) {
            var ok = criar('span', 'chat-ok');
            ok.innerHTML = ICONE_OK;
            meta.appendChild(ok);
        }
        bolha.appendChild(meta);
        linha.appendChild(bolha);
        return linha;
    }

    function acrescentar(frag, m) {
        var dia = (m.criado_em || '').slice(0, 10);
        if (dia && dia !== estado.ultimoDiaDesenhado) {
            frag.appendChild(criar('div', 'chat-dia', rotuloDoDia(dia)));
            estado.ultimoDiaDesenhado = dia;
        }
        frag.appendChild(criarBolha(m));
    }

    function desenharTudo(c, d) {
        var area = estado.el.mensagens;
        area.textContent = '';
        estado.ultimoDiaDesenhado = '';
        if (!d.msgs.length) {
            var vazio = criar('div', 'chat-vazio');
            var ic = criar('span', 'chat-vazio-icone');
            ic.innerHTML = ICONE_VAZIO;
            vazio.appendChild(ic);
            vazio.appendChild(criar('b', null, 'Nenhuma mensagem'));
            vazio.appendChild(criar('span', null, 'O histórico aparecerá aqui'));
            vazio.appendChild(criar('small', null, 'As conversas são guardadas a partir de agora, por 60 dias.'));
            area.appendChild(vazio);
            return;
        }
        var frag = document.createDocumentFragment();
        d.msgs.forEach(function (m) { acrescentar(frag, m); });
        area.appendChild(frag);
    }

    function pertoDoFim() {
        var a = estado.el.mensagens;
        return a.scrollHeight - a.scrollTop - a.clientHeight < 80;
    }

    function descerAteOFim() {
        var a = estado.el.mensagens;
        a.scrollTop = a.scrollHeight;
        estado.el.novas.hidden = true;
    }

    // rolar até o topo traz a página anterior, sem perder o ponto de leitura
    function aoRolar() {
        if (pertoDoFim()) { estado.el.novas.hidden = true; }
        var a = estado.el.mensagens;
        if (a.scrollTop > 40 || estado.carregando || estado.ativa === -1) { return; }
        var c = conversas[estado.ativa];
        var d = dadosDaConversa(c);
        if (!d.carregado || !d.temMais || !d.msgs.length) { return; }
        estado.carregando = true;
        var url = URL_MENSAGENS + '?bot_id=' + encodeURIComponent(c.bot_id) + '&id_telegram=' + encodeURIComponent(c.tg) + '&antes=' + d.msgs[0].id;
        pedir(url).then(function (r) {
            estado.carregando = false;
            if (conversas[estado.ativa] !== c || !r.json || !r.json.sucesso) { return; }
            var altura = a.scrollHeight;
            d.msgs = r.json.mensagens.concat(d.msgs);
            d.temMais = !!r.json.tem_mais;
            desenharTudo(c, d);
            a.scrollTop = a.scrollHeight - altura;
        }).catch(function () { estado.carregando = false; });
    }

    // ------------------------------------------------------------------ tempo real
    function agendar() {
        clearTimeout(estado.timer);
        if (!estado.raiz || estado.raiz.hidden) { return; }
        var parado = Date.now() - estado.ultimaNovidade > APOS_SEM_NOVIDADE;
        estado.timer = setTimeout(buscarNovas, parado ? POLL_LENTO : POLL_RAPIDO);
    }

    function buscarNovas() {
        clearTimeout(estado.timer);
        if (!estado.raiz || estado.raiz.hidden || estado.ativa === -1) { return Promise.resolve(); }
        if (document.hidden) { return Promise.resolve(); } // volta pelo visibilitychange
        var c = conversas[estado.ativa];
        var d = dadosDaConversa(c);
        if (!d.carregado) { return Promise.resolve(); }
        var url = URL_MENSAGENS + '?bot_id=' + encodeURIComponent(c.bot_id) + '&id_telegram=' + encodeURIComponent(c.tg) + '&depois=' + d.ultimo;
        return pedir(url).then(function (r) {
            if (r.http === 401) { return; } // sessão acabou: para de consultar
            if (conversas[estado.ativa] === c && r.json && r.json.sucesso && r.json.mensagens.length) {
                var estavaNoFim = pertoDoFim();
                var eraVazia = !d.msgs.length;
                r.json.mensagens.forEach(function (m) { d.msgs.push(m); });
                d.ultimo = d.msgs[d.msgs.length - 1].id;
                estado.ultimaNovidade = Date.now();
                if (eraVazia) {
                    desenharTudo(c, d);
                } else {
                    var frag = document.createDocumentFragment();
                    r.json.mensagens.forEach(function (m) { acrescentar(frag, m); });
                    estado.el.mensagens.appendChild(frag);
                }
                if (estavaNoFim || eraVazia) { descerAteOFim(); } else { estado.el.novas.hidden = false; }
            }
            agendar();
        }).catch(function () { agendar(); });
    }

    // ------------------------------------------------------------------ envio
    function aoDigitar() {
        var campo = estado.el.campo;
        campo.style.height = 'auto';
        campo.style.height = Math.min(campo.scrollHeight, 120) + 'px';
        estado.el.enviar.disabled = estado.enviando || campo.value.trim() === '';
    }

    function mostrarErro(texto) {
        estado.el.erro.textContent = texto;
        estado.el.erro.hidden = !texto;
    }

    function enviar() {
        var c = conversas[estado.ativa];
        var texto = estado.el.campo.value.trim();
        if (!c || !texto || estado.enviando) { return; }
        estado.enviando = true;
        mostrarErro('');
        aoDigitar();
        var cabecalhos = cabecalhosJson();
        cabecalhos['Content-Type'] = 'application/json';
        pedir(URL_ENVIAR, {
            method: 'POST',
            headers: cabecalhos,
            body: JSON.stringify({ bot_id: Number(c.bot_id), id_telegram: c.tg, texto: texto })
        }).then(function (r) {
            estado.enviando = false;
            if (r.json && r.json.sucesso) {
                if (conversas[estado.ativa] === c) {
                    estado.el.campo.value = '';
                    aoDigitar();
                    estado.ultimaNovidade = Date.now();
                    buscarNovas().then(function () { descerAteOFim(); });
                }
                return;
            }
            mostrarErro((r.json && r.json.mensagem) || 'Não foi possível enviar agora. Tente de novo.');
            aoDigitar();
        }).catch(function () {
            estado.enviando = false;
            mostrarErro('Sem conexão com o servidor. Tente de novo.');
            aoDigitar();
        });
    }

    conversas.forEach(function (c, i) {
        c.botao.addEventListener('click', function () { abrir(i); });
    });
})();
