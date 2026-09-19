/**
 * Paginação sem recarregar a página.
 *
 * Os links de paginação continuam sendo <a href="?pagina=2"> de verdade -- isto aqui só
 * intercepta o clique. Sem JS, ou se o fetch falhar, o link navega como sempre navegou.
 *
 * O servidor devolve apenas o bloco quando o pedido vem com X-Requested-With (ver
 * pedidoDeBloco() em funcoes/paginador.php).
 */
(function () {
    'use strict';

    function trocarBloco(bloco, href) {
        var url = new URL(href, window.location.href);
        url.searchParams.set('bloco', bloco.dataset.bloco);

        bloco.setAttribute('aria-busy', 'true');
        bloco.style.opacity = '.5';

        return fetch(url.toString(), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        })
            .then(function (r) {
                if (!r.ok) { throw new Error('HTTP ' + r.status); }
                return r.text();
            })
            .then(function (html) {
                bloco.innerHTML = html;
                bloco.style.opacity = '';
                bloco.removeAttribute('aria-busy');

                // A URL da barra de endereço acompanha, sem o ?bloco= (que é detalhe interno):
                // assim atualizar a página ou usar o botão voltar continua caindo no lugar certo.
                var limpa = new URL(href, window.location.href);
                limpa.searchParams.delete('bloco');
                history.pushState({ bloco: bloco.dataset.bloco }, '', limpa.toString());

                // Se o topo do bloco ficou acima da janela, leva o olho pra lá -- mas sem o
                // salto pro topo da página que o recarregamento dava.
                var topo = bloco.getBoundingClientRect().top;
                if (topo < 0) {
                    window.scrollTo({ top: window.scrollY + topo - 16, behavior: 'smooth' });
                }
            })
            .catch(function () {
                // Qualquer problema: deixa o navegador fazer o que faria sem JS.
                window.location.href = href;
            });
    }

    document.addEventListener('click', function (e) {
        var link = e.target.closest('.paginacao-link[href]');
        if (!link) { return; }
        var bloco = link.closest('.bloco-paginado');
        if (!bloco || !bloco.dataset.bloco) { return; }
        if (e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) { return; }

        e.preventDefault();
        trocarBloco(bloco, link.getAttribute('href'));
    });

    // Botão voltar/avançar: recarrega de verdade, porque a página pode ter outros blocos
    // cujo estado não guardamos.
    window.addEventListener('popstate', function (ev) {
        if (ev.state && ev.state.bloco) { window.location.reload(); }
    });
})();
