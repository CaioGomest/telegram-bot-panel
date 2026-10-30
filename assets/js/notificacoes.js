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

        // O sino vai para a barra de cima que estiver visível (.topo-acoes: no Layout A o desktop e o celular têm cada um a sua);
        // sem nenhuma visível, cai no cabeçalho da página (.acoes-cabecalho, casca antiga no celular).
        function destino() {
            var barras = document.querySelectorAll('.topo-acoes');
            for (var i = 0; i < barras.length; i++) {
                if (barras[i].offsetParent !== null && barras[i].querySelector('.alternador-tema')) return barras[i];
            }
            return document.querySelector('.acoes-cabecalho');
        }
        var acoes = destino();
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
            var topo = rect.bottom + 8;
            painel.style.position = 'fixed';
            painel.style.top = topo + 'px';
            painel.style.left = left + 'px';
            painel.style.right = 'auto';
            painel.style.width = largura + 'px';
            // Antepassado com backdrop-filter/filter/transform (a barra de cima do Layout A tem blur) vira o "bloco de contenção" do position:fixed:
            // left/top deixam de contar a partir da janela e o painel sai deslocado (23/10/2026: 272 px para a direita, cortado na borda).
            // Mede onde ele caiu de verdade e compensa, seja qual for o antepassado.
            var real = painel.getBoundingClientRect();
            var dx = real.left - left;
            var dy = real.top - topo;
            if (Math.abs(dx) > 0.5 || Math.abs(dy) > 0.5) {
                painel.style.left = (left - dx) + 'px';
                painel.style.top = (topo - dy) + 'px';
            }
            // Janela baixa: o painel não pode passar da borda de baixo, senão o rodapé ("Marcar todas como lidas") some.
            // Limita a altura ao espaço que sobra; a lista rola por dentro (.sino-lista) e o rodapé fica visível.
            var caixa = painel.getBoundingClientRect();
            painel.style.maxHeight = Math.max(200, window.innerHeight - caixa.top - 16) + 'px';
        }

        var fundo = document.createElement('div');
        fundo.className = 'sino-fundo';
        fundo.hidden = true;
        document.body.appendChild(fundo);

        // Com o sino aberto a página atrás não rola. Antes isso era `overflow: hidden` no <html>, que tirava a barra de rolagem
        // e empurrava a tela toda para a direita; barrando só a roda/toque, a barra continua lá e nada se mexe.
        function barrarRolagem(e) {
            if (lista.contains(e.target) && lista.scrollHeight > lista.clientHeight) return;
            e.preventDefault();
        }

        function abrir() {
            fundo.hidden = false;
            document.addEventListener('wheel', barrarRolagem, { passive: false });
            document.addEventListener('touchmove', barrarRolagem, { passive: false });
            document.documentElement.classList.add('sino-aberto');
            raiz.classList.add('aberto');
            painel.hidden = false;
            botao.setAttribute('aria-expanded', 'true');
            posicionarPainel();
        }

        function fechar() {
            fundo.hidden = true;
            document.removeEventListener('wheel', barrarRolagem, { passive: false });
            document.removeEventListener('touchmove', barrarRolagem, { passive: false });
            document.documentElement.classList.remove('sino-aberto');
            raiz.classList.remove('aberto');
            painel.hidden = true;
            botao.setAttribute('aria-expanded', 'false');
        }

        botao.addEventListener('click', function (e) {
            e.stopPropagation();
            if (painel.hidden) abrir();
            else fechar();
        });

        var timerRolagem = null;
        lista.addEventListener('scroll', function () {
            fundo.classList.add('rolando');
            clearTimeout(timerRolagem);
            timerRolagem = setTimeout(function () { fundo.classList.remove('rolando'); }, 180);
        }, { passive: true });

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
            // girar o aparelho ou redimensionar troca a barra de cima visível: o sino acompanha
            var novo = destino();
            if (novo && novo !== raiz.parentNode) {
                var t = novo.querySelector('.alternador-tema');
                if (t) novo.insertBefore(raiz, t);
            }
            if (!painel.hidden) posicionarPainel();
        });

        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible') carregar();
        });

        carregar();
        setInterval(carregar, INTERVALO_MS);
    });
})();
