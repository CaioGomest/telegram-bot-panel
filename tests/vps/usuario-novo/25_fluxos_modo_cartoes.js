// Tela Meus Fluxos (16/10): modal "Como deseja criar?" com Guiado primeiro, descrições novas e ícones novos; lista com ícone do modo no selo e na miniatura.
// Uso: node 25_fluxos_modo_cartoes.js  (conta de teste; capturas em estado/fluxos_*.png)
process.env.BASE = 'https://coyotebot.com.br';
const fs = require('fs'), path = require('path');
const PROJ = path.resolve(__dirname, '../../..');
const { lancar, entrar } = require(PROJ + '/tests/paginas/pg_lib.js');
const ESTADO = process.env.ESTADO || path.join(__dirname, 'estado'); fs.mkdirSync(ESTADO, { recursive: true });
const U = JSON.parse(fs.readFileSync(path.join(ESTADO, 'usuario_novo.json'), 'utf8'));
const BASE = 'https://coyotebot.com.br';
const esp = (ms) => new Promise(r => setTimeout(r, ms));
let ok = 0, falha = 0;
const chk = (n, c, x = '') => { c ? ok++ : falha++; console.log((c ? 'OK     ' : 'FALHOU ') + n + (x ? '  -> ' + String(x).slice(0, 220) : '')); };
(async () => {
  const c = await lancar();
  try {
    await c.enviar('Emulation.setDeviceMetricsOverride', { width: 1400, height: 900, deviceScaleFactor: 1, mobile: false });
    await entrar(c, U.email, U.senha);
    for (const tema of ['dark', 'light']) {
      await c.avaliar(`try{localStorage.setItem('tema','${tema}')}catch(e){} 1`);
      await c.ir(BASE + '/fluxos'); await esp(1500);
      await c.avaliar(`(function(){document.documentElement.setAttribute('data-theme','${tema}');return 1})()`);
      await c.avaliar(`document.getElementById('btn-abrir-escolha-modo').click(); 1`); await esp(600);
      const m = JSON.parse(await c.avaliar(`JSON.stringify((function(){
        var cs=[...document.querySelectorAll('#modal-escolha-modo .cartao-escolha-modo')];
        return cs.map(function(a){var i=a.querySelector('.icone-escolha-modo');var b=i.getBoundingClientRect();return {href:a.getAttribute('href'),classe:a.className,titulo:a.querySelector('strong').innerText.trim(),texto:a.querySelector('.texto-suave').innerText.trim(),svg:!!i.querySelector('svg'),caixa:[Math.round(b.width),Math.round(b.height)],corIcone:getComputedStyle(i).color,fundoIcone:getComputedStyle(i).backgroundColor}})})())`));
      chk(tema + ': dois cartões, Guiado primeiro e Editor Visual depois', m.length === 2 && m[0].titulo === 'Guiado' && m[1].titulo === 'Editor Visual', m.map(x => x.titulo).join(' / '));
      chk(tema + ': descrições novas (texto do exemplo)', m[0].texto === 'Configuração guiada de mensagens, planos, upsell e entrega.' && m[1].texto === 'Editor visual com nós, condições, esperas e caminhos personalizados.', m.map(x => x.texto).join(' | '));
      chk(tema + ': links certos (fluxo_basico e fluxo)', m[0].href === 'fluxo_basico' && m[1].href === 'fluxo');
      chk(tema + ': ícone em quadrado 44x44 e cores diferentes por modo', m.every(x => x.svg && x.caixa[0] === 44 && x.caixa[1] === 44) && m[0].corIcone !== m[1].corIcone, JSON.stringify(m.map(x => [x.caixa.join('x'), x.corIcone])));
      await c.captura(path.join(ESTADO, 'fluxos_modal_' + tema + '.png'));
      await c.avaliar(`document.getElementById('btn-fechar-escolha-modo').click(); 1`); await esp(400);
    }
    // lista de fluxos
    await c.ir(BASE + '/fluxos'); await esp(1500);
    const l = JSON.parse(await c.avaliar(`JSON.stringify([...document.querySelectorAll('.cartao-fluxo')].map(function(card){var b=card.querySelector('.badge-modo-fluxo');var mi=card.querySelector('.miniatura-fluxo svg');var bs=b.querySelector('svg');return {modo:b.className.indexOf('basico')>=0?'basico':'visual',texto:b.innerText.trim(),svgBadge:!!bs&&Math.round(bs.getBoundingClientRect().width),svgMini:!!mi&&Math.round(mi.getBoundingClientRect().width),caminho:(mi?mi.innerHTML:'').slice(0,40)}}))`));
    console.log('   cartões na lista:', l.length);
    chk('lista: todo selo de modo tem ícone (11 px) e toda miniatura tem ícone (46 px)', l.length > 0 && l.every(x => x.svgBadge === 11 && x.svgMini === 46), JSON.stringify(l.map(x => [x.modo, x.svgBadge, x.svgMini])));
    const b = l.filter(x => x.modo === 'basico'), v = l.filter(x => x.modo === 'visual');
    chk('miniatura do Guiado é diferente da do Editor Visual (antes era o mesmo desenho)', !b.length || !v.length || b[0].caminho !== v[0].caminho);
    chk('selos mantêm o rótulo (GUIADO / EDITOR VISUAL)', l.every(x => /GUIADO|EDITOR VISUAL/i.test(x.texto)), l.map(x => x.texto).join(','));
    await c.captura(path.join(ESTADO, 'fluxos_lista.png'));
    // celular
    await c.enviar('Emulation.setDeviceMetricsOverride', { width: 390, height: 900, deviceScaleFactor: 2, mobile: true });
    await c.ir(BASE + '/fluxos'); await esp(1500);
    await c.avaliar(`document.getElementById('btn-abrir-escolha-modo').click(); 1`); await esp(600);
    chk('celular: modal sem rolagem horizontal e cartões em coluna', (await c.avaliar(`document.documentElement.scrollWidth<=window.innerWidth+2`)) === true && (await c.avaliar(`(function(){var a=[...document.querySelectorAll('#modal-escolha-modo .cartao-escolha-modo')].map(function(x){return x.getBoundingClientRect().top});return a[1]>a[0]})()`)) === true);
    await c.captura(path.join(ESTADO, 'fluxos_modal_390.png'));
    chk('erros de JS', c.errosJs().length === 0, c.errosJs().join(' | '));
  } catch (e) { console.log('ERRO', e.stack); falha++; }
  finally { await c.fechar(); }
  console.log(`\nRESUMO: ${ok} ok, ${falha} falhas`);
})();
