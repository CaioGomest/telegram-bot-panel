// Valida as páginas do painel contra o MODELO A (Bento) aprovado pelo cliente (artefatos de design, copiados em layout-a-modelo/).
// Para cada página: monta o HTML estático do modelo (modelo-a.css + a casca ShellA + o corpo da página), abre no Chrome ao lado da página REAL da VPS (tema escuro, 1440 px)
// e compara, para cada tipo de elemento do modelo (mesma tag + mesmas classes), os estilos calculados: fonte, tamanho, peso, cor, fundo, borda, raio, espaçamentos, sombra.
// Também compara a estrutura (rótulos de texto do modelo que não existem na página real). Só leitura. Mostra as diferenças; capturas lado a lado em estado/modelo_<pagina>_{modelo,real}.png
// Uso: node 38_layout_a_vs_modelo.js [pagina ...]   páginas: dashboard bots fluxos leads vendas ranking conta
// Conta: a de dados em volume (e2-1@estresse.invalid, senha Estresse#2026, 6 mil vendas) para as telas terem conteúdo; EMAIL/SENHA mudam.
process.env.BASE = 'https://coyotebot.com.br';
const fs = require('fs'), path = require('path');
const PROJ = path.resolve(__dirname, '../../..');
const { lancar, entrar } = require(PROJ + '/tests/paginas/pg_lib.js');
const ESTADO = process.env.ESTADO || path.join(__dirname, 'estado'); fs.mkdirSync(ESTADO, { recursive: true });
const MODELO = path.join(__dirname, 'layout-a-modelo');
const BASE = 'https://coyotebot.com.br';
const LARG = Number(process.env.LARG || 1440);
const SUF = LARG < 600 ? '_m' : '';
const esp = (ms) => new Promise(r => setTimeout(r, ms));
const EMAIL = process.env.EMAIL || 'e2-1@estresse.invalid', SENHA = process.env.SENHA || 'Estresse#2026';
const PAGINAS = { dashboard: ['DashboardA', '/index'], bots: ['BotsA', '/bots'], fluxos: ['FluxosA', '/fluxos'], leads: ['LeadsA', '/leads'], vendas: ['VendasA', '/vendas'], ranking: ['RankingA', '/ranking'], conta: ['ContaA', '/configuracao_usuario'] };
const alvo = process.argv.slice(2).length ? process.argv.slice(2) : Object.keys(PAGINAS);

/** HTML estático do modelo: corpo da página + corpo da casca, sem os componentes do runtime de design. */
function montarModelo(arq) {
    const corpo = (f) => { const s = fs.readFileSync(path.join(MODELO, f), 'utf8'); return s.slice(s.indexOf('<x-dc>') + 6, s.indexOf('</x-dc>')).replace(/<helmet>[\s\S]*?<\/helmet>/, ''); };
    const casca = corpo('ShellA.dc.html').replace(/<dc-import name="Sprite"><\/dc-import>/, '').replace(/\{\{[^}]*\}\}/g, (m) => /tema/.test(m) ? 'dark' : /ativa/.test(m) ? 'dashboard' : /titulo/.test(m) ? 'Dashboard' : '');
    let pagina = corpo(arq + '.dc.html').replace(/<dc-import name="ShellA"[^>]*><\/dc-import>/, casca).replace(/\{\{tema\}\}/g, 'dark').replace(/\{\{[^}]*\}\}/g, '').replace(/defaultChecked=""/g, '');
    return `<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@600;700&family=JetBrains+Mono:wght@500;600&family=Manrope:wght@500;600;700;800&display=swap">
<link rel="stylesheet" href="./modelo-a.css"><style>body{margin:0}</style></head><body>${pagina}</body></html>`;
}

const COLETA = `(function () {
  var PROPS = ['fontFamily', 'fontSize', 'fontWeight', 'lineHeight', 'letterSpacing', 'textTransform', 'color', 'backgroundColor', 'borderTopLeftRadius', 'borderTopWidth', 'borderTopColor',
    'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft', 'marginTop', 'marginBottom', 'gap', 'boxShadow', 'height'];
  function visivel(e) { var r = e.getBoundingClientRect(); var s = getComputedStyle(e); return r.width > 2 && r.height > 2 && s.visibility !== 'hidden' && s.display !== 'none'; }
  function chave(e) { var c = [].slice.call(e.classList).filter(function (x) { return /^(a-|s\\d|ic$|mono|cd$|cf$|on$|ok$|wa$|er$|or$|vi$|pri$|flat|num|sub|c-|big|go|who|tx|ico|pix|lead|nm|vl|pos|av|um|tg|cima|baixo|lab|selo|nome|pp|fat|prog|trilho|cont|cod|perf|when|user|vazio|dots|off|bot|segs|gav|acts|datas|stack|voce|lider|base)/.test(x); }).sort(); return c.length ? e.tagName.toLowerCase() + '.' + c.join('.') : ''; }
  var raiz = document.body;
  var mapa = {};
  [].slice.call(raiz.querySelectorAll('*')).forEach(function (e) {
    var k = chave(e); if (!k || e.closest('svg') && e.tagName.toLowerCase() !== 'svg') return;
    if (mapa[k] || !visivel(e)) return;
    var s = getComputedStyle(e), o = {};
    PROPS.forEach(function (p) { o[p] = p === 'fontFamily' ? s[p].split(',')[0].replace(/["']/g, '').trim() : s[p]; });
    o._texto = (e.innerText || '').replace(/\\s+/g, ' ').trim().slice(0, 40);
    mapa[k] = o;
  });
  var textos = [].slice.call(raiz.querySelectorAll('h1,h2,h3,th,.a-lab,.a-btn,.a-ni,.a-tab,label span,button')).filter(visivel).map(function (e) { return (e.innerText || '').replace(/\\s+/g, ' ').trim(); }).filter(Boolean);
  return JSON.stringify({ mapa: mapa, textos: textos, rolagemH: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1 });
})()`;

// cores: rgb()/rgba()/color(srgb) viram [r,g,b,a]; diferença de até 14 por canal e 0,03 de alfa é a cor de destaque configurável (white-label), não defeito
function cor(v) { var m = /^rgba?(([^)]+))$/.exec(v); if (m) { var p = m[1].split(/[ ,/]+/).map(Number); return [p[0], p[1], p[2], p.length > 3 ? p[3] : 1]; }
  m = /^color(srgb ([^)]+))$/.exec(v); if (m) { var q = m[1].split(/[ /]+/).map(Number); return [q[0] * 255, q[1] * 255, q[2] * 255, q.length > 3 ? q[3] : 1]; } return null; }
function igual(p, a, b) { if (a === b) return true; var ca = cor(a), cb = cor(b); if (ca && cb) return Math.abs(ca[0] - cb[0]) <= 16 && Math.abs(ca[1] - cb[1]) <= 16 && Math.abs(ca[2] - cb[2]) <= 16 && Math.abs(ca[3] - cb[3]) <= 0.03; return false; }
const norm = (p, v) => { v = String(v); if (p === 'lineHeight' && /^[0-9.]+px$/.test(v)) return v; return v.replace(/\s+/g, ' '); };
const IGNORA_ALTURA = /^(a-grid|a-wrap|a-main|a-app|a-rail|a-sheet)/;

(async () => {
    const c = await lancar();
    let difTotal = 0;
    try {
        await entrar(c, EMAIL, SENHA);
        await c.enviar('Emulation.setDeviceMetricsOverride', { width: LARG, height: LARG < 600 ? 844 : 900, deviceScaleFactor: 1, mobile: LARG < 600 });
        await c.avaliar(`try { localStorage.setItem('tema','dark'); } catch (e) {} 1`);
        for (const nome of alvo) {
            const [arq, rota] = PAGINAS[nome];
            const dirMock = path.join(ESTADO, 'modelo_html'); fs.mkdirSync(dirMock, { recursive: true });
            fs.copyFileSync(path.join(MODELO, 'modelo-a.css'), path.join(dirMock, 'modelo-a.css'));
            fs.writeFileSync(path.join(dirMock, nome + '.html'), montarModelo(arq));
            await c.ir('file:///' + path.join(dirMock, nome + '.html').replace(/\\/g, '/')); await esp(1500);
            const M = JSON.parse(await c.avaliar(COLETA));
            await c.captura(path.join(ESTADO, 'modelo_' + nome + SUF + '_modelo.png'));
            await c.ir(BASE + rota); await esp(1800);
            await c.avaliar(`document.documentElement.setAttribute('data-theme','dark'); 1`); await esp(300);
            const R = JSON.parse(await c.avaliar(COLETA));
            await c.captura(path.join(ESTADO, 'modelo_' + nome + SUF + '_real.png'));
            // 1) estilos: mesma chave (tag + classes) nos dois lados
            const diffs = [], semPar = [];
            // a chave real pode ter classes a mais: procura a primeira chave real que CONTÉM todas as do modelo
            const reais = Object.keys(R.mapa);
            Object.keys(M.mapa).forEach(k => {
                const [tag, ...cls] = k.split('.');
                let kr = R.mapa[k] ? k : reais.find(x => x.split('.')[0] === tag && cls.every(cc => x.split('.').slice(1).includes(cc)));
                if (!kr) { semPar.push(k); return; }
                const a = M.mapa[k], b = R.mapa[kr];
                const d = [];
                Object.keys(a).forEach(p => { if (p === '_texto' || p === 'height') return; if (!igual(p, norm(p, a[p]), norm(p, b[p]))) d.push(`${p}: modelo ${norm(p, a[p])} · real ${norm(p, b[p])}`); });
                if (d.length) diffs.push({ k, kr, d, tm: a._texto, tr: b._texto });
            });
            // 2) textos do modelo que não existem na página real (rótulos, títulos, botões)
            const reaisTxt = new Set(R.textos.map(t => t.toLowerCase()));
            const faltam = [...new Set(M.textos)].filter(t => t.length > 2 && t.length < 40 && !reaisTxt.has(t.toLowerCase()) && !R.textos.some(r => r.toLowerCase().includes(t.toLowerCase()))).slice(0, 15);
            difTotal += diffs.length;
            console.log(`\n=== ${nome.toUpperCase()} (${rota}) — ${Object.keys(M.mapa).length} tipos de elemento no modelo · ${diffs.length} com estilo diferente · ${semPar.length} sem equivalente na página real${R.rolagemH ? ' · ROLAGEM LATERAL' : ''}`);
            diffs.slice(0, 40).forEach(x => console.log(`  ~ ${x.k}${x.kr !== x.k ? '  (real: ' + x.kr + ')' : ''}  "${x.tm}" / "${x.tr}"\n      ${x.d.slice(0, 6).join('\n      ')}`));
            if (semPar.length) console.log('  ? sem equivalente: ' + semPar.slice(0, 30).join(', '));
            if (faltam.length) console.log('  ! textos do modelo ausentes na página real: ' + faltam.map(t => '"' + t + '"').join(', '));
        }
    } finally { await c.fechar(); }
    console.log('\nTOTAL de tipos de elemento com estilo diferente do modelo: ' + difTotal);
})();
