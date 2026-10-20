/* Mascote animado do login/cadastro: inclinação 3D seguindo o mouse/dedo e brasas subindo atrás da logo (canvas dentro do palco).
   Sem dependências. Para as brasas quando a aba some ou o palco sai da tela (ou está escondido pelo CSS), e nem liga se a pessoa pediu menos
   movimento. A página tem dois palcos (coluna da esquerda no desktop e cabeçalho no celular); o CSS esconde um deles, então cada um se vira sozinho. */
(function () {
    'use strict';
    var sem_movimento = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function iniciar(palco) {
        var inclina = palco.querySelector('.coy-inclina');

        // inclinação: o ponto do ponteiro dentro da janela vira um giro de até ±12° em cada eixo
        function mirar(x, y) {
            var rx = x / window.innerWidth - 0.5;
            var ry = y / window.innerHeight - 0.5;
            inclina.style.transform = 'rotateY(' + (rx * 24) + 'deg) rotateX(' + (-ry * 24) + 'deg)';
        }
        function repousar() { inclina.style.transform = ''; }
        if (!sem_movimento) {
            window.addEventListener('pointermove', function (e) { if (e.pointerType === 'mouse') { mirar(e.clientX, e.clientY); } });
            document.addEventListener('pointerleave', repousar);
            var tocar = function (e) { var t = e.touches[0]; if (t) { mirar(t.clientX, t.clientY); } };
            palco.addEventListener('touchstart', tocar, { passive: true });
            palco.addEventListener('touchmove', tocar, { passive: true });  // sem preventDefault: a página continua rolando no celular
            palco.addEventListener('touchend', repousar);
            palco.addEventListener('touchcancel', repousar);
        }

        // brasas
        var tela = palco.querySelector('.coy-brasas');
        if (!tela || sem_movimento || !tela.getContext) { return; }
        var g = tela.getContext('2d');
        var largura = 1, altura = 1, dpr = 1, brasas = [], rodando = false, visivel = false;

        function medir() {
            dpr = Math.min(window.devicePixelRatio || 1, 2);
            var r = tela.getBoundingClientRect();
            largura = tela.width = Math.max(1, Math.round(r.width * dpr));
            altura = tela.height = Math.max(1, Math.round(r.height * dpr));
        }
        function nascer() {
            // o canvas é maior que o palco (124% x 144%); a logo ocupa o miolo, então nasce na metade de baixo dela
            return {
                x: largura * (0.26 + Math.random() * 0.48), y: altura * (0.52 + Math.random() * 0.30),
                vx: (Math.random() - 0.5) * 0.5 * dpr, vy: -(0.4 + Math.random() * 1.3) * dpr,
                r: (0.6 + Math.random() * 1.8) * dpr, vida: 0, max: 120 + Math.random() * 160
            };
        }
        function quadro() {
            rodando = false;
            if (!visivel || document.hidden) { return; }
            g.clearRect(0, 0, largura, altura);
            if (brasas.length < 46) { brasas.push(nascer()); }
            brasas = brasas.filter(function (q) { return q.vida < q.max; });
            g.shadowColor = '#ff7a12';
            g.shadowBlur = 8 * dpr;
            for (var i = 0; i < brasas.length; i++) {
                var q = brasas[i];
                q.vida++;
                q.x += q.vx + Math.sin(q.vida / 18) * 0.3 * dpr;
                q.y += q.vy;
                g.beginPath();
                g.fillStyle = 'rgba(255,' + (120 + Math.random() * 60 | 0) + ',30,' + (Math.sin(Math.PI * q.vida / q.max) * 0.85) + ')';
                g.arc(q.x, q.y, q.r, 0, 6.283);
                g.fill();
            }
            rodando = true;
            window.requestAnimationFrame(quadro);
        }
        function retomar() {
            if (!rodando && visivel && !document.hidden) { rodando = true; window.requestAnimationFrame(quadro); }
        }

        window.addEventListener('resize', function () { if (visivel) { medir(); } });
        document.addEventListener('visibilitychange', retomar);
        if (!('IntersectionObserver' in window)) { return; }
        var entrou_em = 0;
        new IntersectionObserver(function (entradas) {
            visivel = entradas[0].isIntersecting;
            if (visivel) {
                medir();
                // as brasas entram depois da revelação da logo (1,7 s), como no original
                var espera = Math.max(0, 1500 - (Date.now() - entrou_em));
                setTimeout(retomar, espera);
            }
        }).observe(palco);
        entrou_em = Date.now();
    }

    Array.prototype.forEach.call(document.querySelectorAll('.coy-palco'), iniciar);
})();
