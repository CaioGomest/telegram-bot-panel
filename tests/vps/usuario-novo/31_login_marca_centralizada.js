// Login e cadastro (18/10): na coluna da esquerda (desktop) o mascote animado (ou a logo), o título e a descrição ficam CENTRALIZADOS na coluna.
// Páginas públicas, só leitura. Mede o centro horizontal de cada elemento contra o centro da coluna (tolerância 2px) em 4 larguras.
// Uso: node 31_login_marca_centralizada.js   (capturas em estado/login_marca_<largura>.png)
process.env.BASE = 'https://coyotebot.com.br';
const fs = require('fs'), path = require('path');
const PROJ = path.resolve(__dirname, '../../..');
const { lancar } = require(PROJ + '/tests/paginas/pg_lib.js');
const ESTADO = process.env.ESTADO || path.join(__dirname, 'estado'); fs.mkdirSync(ESTADO, { recursive: true });
const BASE = 'https://coyotebot.com.br';
const esp = (ms) => new Promise(r => setTimeout(r, ms));
let ok = 0, falha = 0;
const chk = (n, c, x = '') => { c ? ok++ : falha++; console.log((c ? 'OK     ' : 'FALHOU ') + n + (x ? '  -> ' + String(x).slice(0, 260) : '')); };
(async () => {
  const c = await lancar();
  try {
    for (const [larg, alt] of [[1360, 606], [1920, 1080], [1100, 800], [900, 700]]) {
      await c.enviar('Emulation.setDeviceMetricsOverride', { width: larg, height: alt, deviceScaleFactor: 1, mobile: false });
      for (const rota of ['/login', '/cadastro']) {
        await c.ir(BASE + rota); await esp(2200);
        const r = JSON.parse(await c.avaliar(`(function(){
          function caixa(sel){ var e = document.querySelector(sel); if(!e) return null; var b = e.getBoundingClientRect(); return { cx: Math.round((b.left + b.width / 2) * 10) / 10, w: Math.round(b.width), esq: Math.round(b.left) }; }
          var m = document.querySelector('.login-marca');
          var vis = !!m && getComputedStyle(m).display !== 'none';
          return JSON.stringify({
            vis: vis,
            marca: caixa('.login-marca'),
            mascote: caixa('.login-marca .coy-palco') || caixa('.login-marca .login-logo'),
            animado: !!document.querySelector('.login-marca .coy-palco'),
            titulo: caixa('.login-marca h1'),
            desc: caixa('.login-marca .login-descricao'),
            alinTitulo: m ? getComputedStyle(m.querySelector('h1')).textAlign : '',
            alinDesc: m ? getComputedStyle(m.querySelector('.login-descricao')).textAlign : '',
            rolagemH: document.documentElement.scrollWidth > document.documentElement.clientWidth
          });
        })()`));
        const tag = larg + 'px ' + rota;
        if (!r.vis) { chk(tag + ': coluna da marca oculta (≤ 860px: só o mobile aparece)', larg <= 860, 'visível=' + r.vis); continue; }
        const d = (k) => Math.abs(r[k].cx - r.marca.cx);
        chk(tag + ': ' + (r.animado ? 'mascote animado' : 'logo') + ' centralizado na coluna', d('mascote') <= 2, 'desvio ' + d('mascote').toFixed(1) + 'px (centro ' + r.mascote.cx + ' x coluna ' + r.marca.cx + ')');
        chk(tag + ': título centralizado (caixa e texto)', d('titulo') <= 2 && r.alinTitulo === 'center', 'desvio ' + d('titulo').toFixed(1) + 'px, text-align=' + r.alinTitulo);
        chk(tag + ': descrição centralizada (caixa e texto)', d('desc') <= 2 && r.alinDesc === 'center', 'desvio ' + d('desc').toFixed(1) + 'px, text-align=' + r.alinDesc);
        chk(tag + ': sem rolagem horizontal', !r.rolagemH);
        if (rota === '/login') await c.captura(path.join(ESTADO, 'login_marca_' + larg + '.png')).catch(() => {});
      }
    }
    chk('erros de JS', c.errosJs().length === 0, c.errosJs().join(' | '));
  } catch (e) { console.log('ERRO', e.stack); falha++; }
  finally { await c.fechar(); }
  console.log(`\nRESUMO: ${ok} ok, ${falha} falhas`);
})();
