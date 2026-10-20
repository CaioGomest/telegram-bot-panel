// Teste 6: vincular o bot ao fluxo Guiado (editor), conferir na lista de bots e na tela do bot, desvincular/revincular pelo outro lado.
process.env.BASE = 'https://coyotebot.com.br';
const fs = require('fs'), path = require('path');
const ESTADO = process.env.ESTADO || path.join(__dirname, 'estado'); require('fs').mkdirSync(ESTADO, { recursive: true });
const PROJ = path.resolve(__dirname, '../../..');
const { lancar, entrar, autoDialogo } = require(PROJ + '/tests/paginas/pg_lib.js');
const U = JSON.parse(fs.readFileSync(path.join(ESTADO, 'usuario_novo.json'), 'utf8'));
const BASE = 'https://coyotebot.com.br';
const esp = (ms) => new Promise(r => setTimeout(r, ms));
const linhas = (t) => String(t || '').split('\n').map(s => s.trim()).filter(Boolean);
let ok = 0, falha = 0;
const chk = (n, c, x = '') => { c ? ok++ : falha++; console.log((c ? 'OK     ' : 'FALHOU ') + n + (x ? '  -> ' + x : '')); };
async function toast(c, ms = 9000) {
  const t0 = Date.now();
  while (Date.now() - t0 < ms) {
    const x = await c.avaliar(`(function(){var t=document.getElementById('toast');return t&&t.classList.contains('visivel')?t.innerText:''})()`).catch(() => '');
    if (x) return x; await esp(120);
  }
  return '';
}
(async () => {
  const c = await lancar();
  try {
    await c.enviar('Emulation.setDeviceMetricsOverride', { width: 1400, height: 1000, deviceScaleFactor: 1, mobile: false });
    autoDialogo(c, true);
    await entrar(c, U.email, U.senha);

    // vincular pelo editor Guiado (fluxo 6144)
    await c.ir(BASE + '/fluxo_basico?id=6144'); await esp(1500);
    const opcoes = JSON.parse(await c.avaliar(`JSON.stringify([...document.querySelectorAll('#select-bot-vincular option')].map(function(o){return o.value+':'+o.textContent.trim()}))`));
    console.log('   opções do seletor:', JSON.stringify(opcoes));
    chk('seletor oferece o bot do usuário', opcoes.some(o => /Gomes/.test(o)));
    await c.avaliar(`(function(){var s=document.getElementById('select-bot-vincular');s.value='12168';s.dispatchEvent(new Event('change',{bubbles:true}));return 1})()`);
    let t = await toast(c);
    chk('vincular bot ao fluxo: mensagem de sucesso', /vincul|sucesso|feito|conect/i.test(t), '"' + t + '"');
    await esp(1500);
    let txt = linhas(await c.avaliar(`document.getElementById('lista-bots-vinculados').innerText`)).join(' | ');
    chk('bot aparece em "Bots vinculados" (1/10)', /Gomes/.test(txt) && /1\/10/.test(await c.avaliar(`document.getElementById('bots-contagem').innerText`)), txt);

    // tela do bot e lista de bots mostram o fluxo
    await c.ir(BASE + '/bots'); await esp(1200);
    txt = linhas(await c.avaliar(`document.querySelector('main').innerText`)).join(' | ');
    chk('lista de bots mostra o fluxo conectado', /TESTE REAL 1/i.test(txt) && !/SEM FLUXO/i.test(txt), txt.slice(0, 300));
    await c.ir(BASE + '/bot?id=12168'); await esp(1500);
    const sel = await c.avaliar(`(function(){var s=document.getElementById('id-fluxo-conectado');return s? s.options[s.selectedIndex].textContent.trim():''})()`);
    chk('tela do bot seleciona o mesmo fluxo', /TESTE REAL 1/i.test(sel), sel);

    // trocar o fluxo pela tela do bot (para o visual 6146) e voltar
    await c.avaliar(`(function(){var s=document.getElementById('id-fluxo-conectado');s.value='6146';s.dispatchEvent(new Event('change',{bubbles:true}));document.getElementById('btn-salvar-bot').click();return 1})()`);
    t = await toast(c, 15000);
    chk('trocar fluxo pela tela do bot salva', /salv|sucesso/i.test(t), '"' + t + '"');
    await esp(2500);
    await c.ir(BASE + '/bots'); await esp(1200);
    txt = linhas(await c.avaliar(`document.querySelector('main').innerText`)).join(' | ');
    chk('lista agora mostra o fluxo Visual (TESTE REAL 3)', /TESTE REAL 3/i.test(txt), txt.slice(0, 260));
    // volta ao Guiado pelo editor (desvincular + vincular)
    await c.ir(BASE + '/fluxo_basico?id=6144'); await esp(1500);
    await c.avaliar(`(function(){var s=document.getElementById('select-bot-vincular');s.value='12168';s.dispatchEvent(new Event('change',{bubbles:true}));return 1})()`);
    t = await toast(c); await esp(1500);
    await c.ir(BASE + '/fluxo?id=6146'); await esp(1500);
    await c.ir(BASE + '/bots'); await esp(1200);
    txt = linhas(await c.avaliar(`document.querySelector('main').innerText`)).join(' | ');
    chk('bot voltou ao Guiado (TESTE REAL 1) — vincular tira o bot do fluxo anterior', /TESTE REAL 1/i.test(txt), txt.slice(0, 260));
    await c.captura(path.join(ESTADO, 'bots_vinculado.png'));
  } catch (e) { console.log('ERRO', e.stack); falha++; }
  finally { try { c.pararDialogo(); } catch (e) {} await c.fechar(); }
  console.log(`\nRESUMO: ${ok} ok, ${falha} falhas`);
})();
