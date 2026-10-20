// Fluxo Guiado novo: o botão de tirar da lista o bot escolhido ("será vinculado ao salvar") usa um X (antes: corrente quebrada). NÃO salva nada.
// Uso: node 28_icone_x_bot_pendente.js   (capturas em estado/bot_pendente_x.png)
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
    await c.enviar('Emulation.setDeviceMetricsOverride', { width: 1360, height: 900, deviceScaleFactor: 2, mobile: false });
    await entrar(c, U.email, U.senha);
    await c.ir(BASE + '/fluxo_basico'); await esp(2500);
    const id_bot = await c.avaliar(`(function(){var o=[...document.querySelectorAll('#select-bot-vincular option')].find(function(x){return x.value});return o?o.value:''})()`);
    chk('há um bot da conta no seletor', !!id_bot, id_bot);
    await c.avaliar(`(function(){var s=document.getElementById('select-bot-vincular');s.value='${id_bot}';s.dispatchEvent(new Event('change',{bubbles:true}));return 1})()`); await esp(600);
    const b = JSON.parse(await c.avaliar(`JSON.stringify((function(){var x=document.querySelector('.tirar-bot-pendente');var s=x.querySelector('svg');return {titulo:x.title, linhas:s.querySelectorAll('line').length, caminhos:[...s.querySelectorAll('path')].map(function(p){return p.getAttribute('d')}), circulos:s.querySelectorAll('circle').length, html:s.innerHTML.slice(0,160)}})())`));
    console.log('   ícone atual:', b.html);
    chk('o botão "Tirar da lista" agora é um X (duas linhas cruzadas, sem corrente)', b.titulo === 'Tirar da lista' && /M18 6|18 6|6 18|x1="18"/.test(b.html) && b.circulos === 0, b.html);
    await c.captura(path.join(ESTADO, 'bot_pendente_x.png'));
    chk('erros de JS', c.errosJs().length === 0, c.errosJs().join(' | '));
  } catch (e) { console.log('ERRO', e.stack); falha++; }
  finally { await c.fechar(); }
  console.log(`\nRESUMO: ${ok} ok, ${falha} falhas`);
})();
