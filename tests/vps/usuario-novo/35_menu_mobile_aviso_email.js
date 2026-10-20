// Menu "Mais" do celular (20/10/2026, dono: "o e-mail não confirmado está torto esse layout"): o aviso "E-mail não confirmado + Reenviar" ficava colado nas bordas da folha
// (sem margem lateral, enquanto o resto do menu tem 10 px) e com o botão minúsculo. Confere alinhamento com os itens do menu, respiros, texto/botão na mesma linha e alvo de toque.
// A conta de teste tem o e-mail NÃO confirmado (é o que faz o aviso aparecer). Só leitura. Uso: node 35_menu_mobile_aviso_email.js   (captura em estado/menu_mobile_aviso.png)
process.env.BASE = 'https://coyotebot.com.br';
const fs = require('fs'), path = require('path');
const PROJ = path.resolve(__dirname, '../../..');
const { lancar, entrar } = require(PROJ + '/tests/paginas/pg_lib.js');
const ESTADO = process.env.ESTADO || path.join(__dirname, 'estado'); fs.mkdirSync(ESTADO, { recursive: true });
const U = JSON.parse(fs.readFileSync(path.join(ESTADO, 'usuario_novo.json'), 'utf8'));
const BASE = 'https://coyotebot.com.br';
const esp = (ms) => new Promise(r => setTimeout(r, ms));
let ok = 0, falha = 0;
const chk = (n, c, x = '') => { c ? ok++ : falha++; console.log((c ? 'OK     ' : 'FALHOU ') + n + (x ? '  -> ' + String(x).slice(0, 260) : '')); };
(async () => {
  const c = await lancar();
  try {
    await entrar(c, U.email, U.senha);
    for (const [larg, alt] of [[390, 844], [360, 740], [430, 932]]) {
      await c.enviar('Emulation.setDeviceMetricsOverride', { width: larg, height: alt, deviceScaleFactor: 2, mobile: true });
      await c.ir(BASE + '/index'); await esp(2200);
      await c.avaliar(`document.getElementById('abrir-menu-mobile').click(); 1`); await esp(900);
      const m = JSON.parse(await c.avaliar(`(function(){
        function r(e){ var b = e.getBoundingClientRect(); return {l: b.left, r: b.right, t: b.top, b: b.bottom, w: b.width, h: b.height}; }
        var caixa = document.querySelector('.folha-menu-caixa'), av = document.querySelector('.folha-menu-caixa .aviso-email');
        if (!av) return JSON.stringify({semAviso: true});
        var txt = av.querySelector('span'), btn = av.querySelector('button'), item = document.querySelector('.folha-menu-nav .nav-item'), topo = document.querySelector('.folha-menu-topo'), grupo = document.querySelector('.folha-menu-nav .nav-grupo-titulo');
        var C = r(caixa), A = r(av), I = r(item), T = r(topo), G = r(grupo), X = r(txt), B = r(btn);
        return JSON.stringify({
          insetAvisoEsq: A.l - C.l, insetAvisoDir: C.r - A.r, insetItemEsq: I.l - C.l, insetItemDir: C.r - I.r,
          folgaAcima: A.t - T.b, folgaAbaixo: G.t - A.b,
          desalinhoVertical: Math.abs((X.t + X.b) / 2 - (B.t + B.b) / 2), alturaBotao: B.h, alturaAviso: A.h,
          transborda: av.scrollWidth > av.clientWidth + 1, rolagemH: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
          textoLinhas: Math.round(X.h / parseFloat(getComputedStyle(txt).lineHeight === 'normal' ? 16 : getComputedStyle(txt).lineHeight))
        });
      })()`));
      const tag = larg + 'px';
      if (m.semAviso) { chk(tag + ': o aviso de e-mail aparece no menu "Mais" (a conta de teste não confirmou o e-mail)', false, 'sem .aviso-email'); continue; }
      const f = (n) => Math.round(n * 10) / 10;
      chk(tag + ': o aviso tem margem dos dois lados (não encosta nas bordas da folha): ' + f(m.insetAvisoEsq) + ' / ' + f(m.insetAvisoDir) + ' px', m.insetAvisoEsq >= 8 && m.insetAvisoDir >= 8);
      chk(tag + ': as bordas do aviso alinham com as dos itens do menu (esq ' + f(m.insetItemEsq) + ', dir ' + f(m.insetItemDir) + ')', Math.abs(m.insetAvisoEsq - m.insetItemEsq) <= 2 && Math.abs(m.insetAvisoDir - m.insetItemDir) <= 2);
      chk(tag + ': respiro de cima (' + f(m.folgaAcima) + ' px) e de baixo (' + f(m.folgaAbaixo) + ' px) de pelo menos 8 px', m.folgaAcima >= 8 && m.folgaAbaixo >= 8);
      chk(tag + ': texto e botão "Reenviar" na mesma linha, centralizados (desvio ' + f(m.desalinhoVertical) + ' px) e o texto numa linha só', m.desalinhoVertical <= 2 && m.textoLinhas <= 1);
      chk(tag + ': botão "Reenviar" com altura de toque de pelo menos 32 px (' + f(m.alturaBotao) + ' px)', m.alturaBotao >= 32);
      chk(tag + ': nada transborda nem cria rolagem lateral', !m.transborda && !m.rolagemH);
      if (larg === 390) await c.captura(path.join(ESTADO, 'menu_mobile_aviso.png'));
    }
    chk('erros de JS', c.errosJs().length === 0, c.errosJs().join(' | '));
  } catch (e) { console.log('ERRO', e.stack); falha++; }
  finally { await c.fechar(); }
  console.log(`\nRESUMO: ${ok} ok, ${falha} falhas`);
})();
