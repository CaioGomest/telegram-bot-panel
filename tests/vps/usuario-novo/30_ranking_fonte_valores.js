// Ranking (17/10): os valores em R$ (mini ranking do Início, pódio e lista de /ranking) usam a MESMA fonte do resto do painel (Manrope), não mais a monoespaçada.
// Só leitura (não grava nada). Se a conta de teste não tem campanha ativa, mede os estilos num elemento de amostra criado na página com as mesmas classes.
// Uso: node 30_ranking_fonte_valores.js    (capturas em estado/ranking_fonte_<largura>.png)
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
const CLASSES = ['mini-ranking-valor', 'linha-ranking-valor', 'podio-valor'];
(async () => {
  const c = await lancar();
  try {
    await entrar(c, U.email, U.senha);
    for (const [larg, mobile] of [[1360, false], [390, true]]) {
      await c.enviar('Emulation.setDeviceMetricsOverride', { width: larg, height: 900, deviceScaleFactor: mobile ? 2 : 1, mobile });
      for (const rota of ['/', '/ranking']) {
        await c.ir(BASE + rota); await esp(1800);
        // mede o elemento real quando existe; senão, uma amostra com a mesma classe (o CSS é o mesmo)
        const r = JSON.parse(await c.avaliar(`(function(){
          var out = {};
          ${JSON.stringify(CLASSES)}.forEach(function (k) {
            var e = document.querySelector('.' + k), real = !!e;
            if (!e) { e = document.createElement('div'); e.className = k; e.textContent = 'R$ 36,3 mil'; document.body.appendChild(e); }
            var s = getComputedStyle(e);
            out[k] = { real: real, fam: s.fontFamily, peso: s.fontWeight, tam: s.fontSize, num: s.fontVariantNumeric };
            if (!real) e.remove();
          });
          var corpo = getComputedStyle(document.body).fontFamily;
          out.corpo = corpo;
          return JSON.stringify(out);
        })()`));
        const tag = (larg) + ' ' + rota;
        for (const k of CLASSES) {
          const x = r[k];
          chk(tag + ' .' + k + ': fonte do sistema (Manrope), não monoespaçada', /Manrope/i.test(x.fam) && !/Mono|monospace/i.test(x.fam) && x.fam === r.corpo, (x.real ? 'real' : 'amostra') + ' | ' + x.fam + ' | ' + x.peso + ' ' + x.tam + ' | ' + x.num);
        }
        if (rota === '/') await c.avaliar(`(function(){var e=document.getElementById('mini-ranking-link');if(e)e.scrollIntoView({block:'end'});return 1})()`).then(() => esp(500));
        if (rota === '/') await c.captura(path.join(ESTADO, 'ranking_fonte_' + larg + '.png')).catch(() => {});
      }
    }
    chk('erros de JS', c.errosJs().length === 0, c.errosJs().join(' | '));
  } catch (e) { console.log('ERRO', e.stack); falha++; }
  finally { await c.fechar(); }
  console.log(`\nRESUMO: ${ok} ok, ${falha} falhas`);
})();
