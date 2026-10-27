// Varredura de ESPAÇAMENTO: acha elementos vizinhos (botão, campo, título, parágrafo, aviso, cartão) colados um no outro (folga < 6 px) nas páginas do vendedor,
// no desktop (1360) e no celular (390). Só leitura. Uso: node 37_espacamento_colado.js [rota ...]   (sem rota: todas as páginas do vendedor)
process.env.BASE = 'https://coyotebot.com.br';
const fs = require('fs'), path = require('path');
const PROJ = path.resolve(__dirname, '../../..');
const { lancar, entrar } = require(PROJ + '/tests/paginas/pg_lib.js');
const ESTADO = process.env.ESTADO || path.join(__dirname, 'estado'); fs.mkdirSync(ESTADO, { recursive: true });
const U = JSON.parse(fs.readFileSync(path.join(ESTADO, 'usuario_novo.json'), 'utf8'));
const BASE = 'https://coyotebot.com.br';
const esp = (ms) => new Promise(r => setTimeout(r, ms));
const ROTAS = process.argv.length > 2 ? process.argv.slice(2) : ['/index', '/vendas', '/bots', '/fluxos', '/fluxo_basico', '/leads', '/links_rastreamento', '/redirecionamento', '/remarketing', '/gateways',
    '/traqueamento', '/webhooks', '/ranking', '/configuracao_usuario', '/comunidade'];
const LIMITE = Number(process.env.LIMITE || 6);

const VARREDURA = `(function (LIMITE) {
  var SEL = 'button, a.a-btn, a.botao, .botao, .a-btn, input:not([type=hidden]):not([type=checkbox]):not([type=radio]), select, textarea, h1, h2, h3, h4, p, .aviso, .a-aviso2, .push-aviso, label, .a-card, .painel, ul, ol, dl, table, div, section, form, fieldset, header, footer, article';
  function visivel(e) { var r = e.getBoundingClientRect(); var s = getComputedStyle(e); return r.width > 4 && r.height > 4 && s.visibility !== 'hidden' && s.display !== 'none' && e.offsetParent !== null; }
  function caminho(e) { var p = []; while (e && e.nodeType === 1 && p.length < 4) { p.unshift(e.tagName.toLowerCase() + (e.id ? '#' + e.id : '') + (e.className && typeof e.className === 'string' ? '.' + e.className.trim().split(/\\s+/).slice(0, 2).join('.') : '')); e = e.parentElement; } return p.join(' > '); }
  function rotulo(e) { return (e.innerText || e.value || e.placeholder || '').replace(/\\s+/g, ' ').trim().slice(0, 28); }
  var achados = [], vistos = {};
  var todos = [].slice.call(document.querySelectorAll(SEL)).filter(visivel);
  todos.forEach(function (a) {
    var pa = a.parentElement; if (!pa) return;
    var b = a.nextElementSibling;
    while (b && !visivel(b)) b = b.nextElementSibling;
    if (!b || !b.matches(SEL)) return;
    var ra = a.getBoundingClientRect(), rb = b.getBoundingClientRect();
    var sobre = Math.min(ra.right, rb.right) - Math.max(ra.left, rb.left);
    var folga = rb.top - ra.bottom;
    var lado = rb.left - ra.right;
    var temConteudo = function (e) { return (e.innerText || '').trim().length > 0 || e.matches('input,select,textarea,button'); };
    if (!temConteudo(a) || !temConteudo(b)) return;
    var vertical = folga > -1 && folga < LIMITE && sobre > Math.min(ra.width, rb.width) * 0.3;
    var sobreV = Math.min(ra.bottom, rb.bottom) - Math.max(ra.top, rb.top);
    var horizontal = lado > -1 && lado < 3 && sobreV > Math.min(ra.height, rb.height) * 0.5 && ra.width > 40 && rb.width > 40;
    if (!vertical && !horizontal) return;
    // pares de design: ícone+texto dentro de um botão não entram (só irmãos diretos); rótulo logo acima do campo (folga 4–6) é normal
    if (a.tagName === 'LABEL' && /^(input|select|textarea)$/i.test(b.tagName) && folga >= 3) return;
    var chave = caminho(a) + '|' + caminho(b);
    if (vistos[chave]) return; vistos[chave] = 1;
    achados.push({ tipo: vertical ? 'vertical' : 'lado a lado', folga: Math.round((vertical ? folga : lado) * 10) / 10, a: caminho(a), ta: rotulo(a), b: caminho(b), tb: rotulo(b) });
  });
  return JSON.stringify({ n: achados.length, rolagemH: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1, achados: achados.slice(0, 25) });
})(${LIMITE})`;

(async () => {
    const c = await lancar();
    let total = 0;
    try {
        await entrar(c, U.email, U.senha);
        for (const [larg, alt, nome] of [[1360, 900, 'desktop'], [390, 844, 'celular']]) {
            await c.enviar('Emulation.setDeviceMetricsOverride', { width: larg, height: alt, deviceScaleFactor: 1, mobile: larg < 600 });
            for (const rota of ROTAS) {
                await c.ir(BASE + rota); await esp(900);
                let r; try { r = JSON.parse(await c.avaliar(VARREDURA)); } catch (e) { console.log(`ERRO  ${nome} ${rota}: ${e.message.slice(0, 120)}`); continue; }
                total += r.n;
                console.log(`${r.n ? 'COLADO ' : 'ok     '} ${nome.padEnd(7)} ${rota.padEnd(24)} ${r.n} par(es)${r.rolagemH ? ' · ROLAGEM LATERAL' : ''}`);
                r.achados.forEach(x => console.log(`           ${x.tipo} ${x.folga}px · "${x.ta}" [${x.a}]  ↔  "${x.tb}" [${x.b}]`));
            }
        }
    } finally { await c.fechar(); }
    console.log('\nTOTAL de pares colados: ' + total);
})();
