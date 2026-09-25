(function () {
    var INTERVALO_MS = 30000;
    var ICONES = {
        venda: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
        pix_gerado: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
        lead: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>',
        padrao: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>'
    };

    function escapar(texto) {
        return String(texto == null ? '' : texto)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function headersJson() {
        var headers = { 'Accept': 'application/json' };
        if (window.CSRF_TOKEN) {
            headers['X-CSRF-Token'] = window.CSRF_TOKEN;
        }
        return headers;
    }

    document.addEventListener('DOMContentLoaded', function () {
        var raiz = document.getElementById('sino-notificacoes-raiz');
        if (!raiz) return;

        var acoes = document.querySelector('.acoes-cabecalho');
        var tema = acoes && acoes.querySelector('.alternador-tema');
        if (!acoes || !tema) return;

        acoes.insertBefore(raiz, tema);
        raiz.hidden = false;

        var botao = raiz.querySelector('.sino-notificacoes');
        var painel = raiz.querySelector('.sino-painel');
        var lista = raiz.querySelector('.sino-lista');
        var badge = raiz.querySelector('.sino-badge');
        var btnMarcar = raiz.querySelector('.sino-marcar-todas');
        var urlLista = raiz.getAttribute('data-url-lista');
        var urlLer = raiz.getAttribute('data-url-ler');
        var ultimoNaoLidas = 0;
        var carregando = false;

        function atualizarBadge(qtd) {
            ultimoNaoLidas = qtd;
            if (qtd > 0) {
                badge.hidden = false;
                badge.textContent = qtd > 99 ? '99+' : String(qtd);
                botao.classList.add('tem-nao-lidas');
            } else {
                badge.hidden = true;
                badge.textContent = '0';
                botao.classList.remove('tem-nao-lidas');
            }
        }

        function desenharLista(itens) {
            if (!itens || !itens.length) {
                lista.innerHTML = '<div class="estado-vazio">Nenhuma notificação recente.</div>';
                return;
            }

            lista.innerHTML = itens.map(function (item) {
                var icone = ICONES[item.tipo] || ICONES.padrao;
                var classeLido = item.lido ? '' : ' nao-lida';
                return '<div class="item-atividade' + classeLido + '" data-id="' + escapar(item.id) + '">' +
                    '<div class="icone-atividade ' + escapar(item.tipo) + '">' + icone + '</div>' +
                    '<div class="atividade-conteudo">' +
                        '<p class="atividade-titulo">' + escapar(item.titulo) + '</p>' +
                        '<p class="atividade-descricao">' + escapar(item.descricao) + '</p>' +
                    '</div>' +
                    '<div class="atividade-tempo">' + escapar(item.tempo) + '</div>' +
                '</div>';
            }).join('');
        }

        function carregar() {
            if (carregando || !urlLista) return;
            if (document.visibilityState === 'hidden') return;
            carregando = true;

            fetch(urlLista, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                .then(function (res) { return res.json(); })
                .then(function (dados) {
                    if (!dados || !dados.sucesso) return;
                    atualizarBadge(Number(dados.nao_lidas) || 0);
                    desenharLista(dados.itens || []);
                })
                .catch(function () { /* silêncio: o próximo poll tenta de novo */ })
                .then(function () { carregando = false; });
        }

        function marcarLidas(id) {
            if (!urlLer) return Promise.resolve();
            var corpo = id ? JSON.stringify({ id: id }) : JSON.stringify({});
            return fetch(urlLer, {
                method: 'POST',
                credentials: 'same-origin',
                headers: Object.assign({ 'Content-Type': 'application/json' }, headersJson()),
                body: corpo
            }).then(function (res) { return res.json(); }).catch(function () { return null; });
        }

        function posicionarPainel() {
            var rect = botao.getBoundingClientRect();
            var largura = Math.min(380, window.innerWidth - 32);
            var left = rect.right - largura;
            if (left < 16) left = 16;
            if (left + largura > window.innerWidth - 16) {
                left = window.innerWidth - 16 - largura;
            }
            painel.style.position = 'fixed';
            painel.style.top = (rect.bottom + 8) + 'px';
            painel.style.left = left + 'px';
            painel.style.right = 'auto';
            painel.style.width = largura + 'px';
        }

        function abrir() {
            painel.hidden = false;
            botao.setAttribute('aria-expanded', 'true');
            posicionarPainel();
            if (ultimoNaoLidas > 0) {
                marcarLidas().then(function () {
                    atualizarBadge(0);
                    lista.querySelectorAll('.item-atividade.nao-lida').forEach(function (el) {
                        el.classList.remove('nao-lida');
                    });
                });
            }
        }

        function fechar() {
            painel.hidden = true;
            botao.setAttribute('aria-expanded', 'false');
        }

        botao.addEventListener('click', function (e) {
            e.stopPropagation();
            if (painel.hidden) abrir();
            else fechar();
        });

        btnMarcar.addEventListener('click', function (e) {
            e.stopPropagation();
            marcarLidas().then(function () {
                atualizarBadge(0);
                lista.querySelectorAll('.item-atividade.nao-lida').forEach(function (el) {
                    el.classList.remove('nao-lida');
                });
            });
        });

        document.addEventListener('click', function (e) {
            if (!raiz.contains(e.target)) fechar();
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') fechar();
        });

        window.addEventListener('resize', function () {
            if (!painel.hidden) posicionarPainel();
        });

        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible') carregar();
        });

        carregar();
        setInterval(carregar, INTERVALO_MS);
    });
})();
