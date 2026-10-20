// Refaz os casos de erro de criação de bot esperando o toast anterior sumir. Roda com o bot real já criado.
process.env.BASE = 'https://coyotebot.com.br';
const fs = require('fs'), path = require('path');
const ESTADO = process.env.ESTADO || path.join(__dirname, 'estado'); require('fs').mkdirSync(ESTADO, { recursive: true });
const PROJ = path.resolve(__dirname, '../../..');
const { lancar, entrar, autoDialogo } = require(PROJ + '/tests/paginas/pg_lib.js');
const U = JSON.parse(fs.readFileSync(path.join(ESTADO, 'usuario_novo.json'), 'utf8'));
const esp = (ms) => new Promise(r => setTimeout(r, ms));
const BASE = 'https://coyotebot.com.br';
let ok = 0, falha = 0;
const chk = (n, c, x = '') => { c ? ok++ : falha++; console.log((c ? 'OK     ' : 'FALHOU ') + n + (x ? '  -> ' + x : '')); };
async function toastNovo(c, ms = 15000) { // espera o toast antigo sumir, aciona, e lê só o novo
  const t0 = Date.now();
  while (Date.now() - t0 < ms) { const x = await c.avaliar(`(function(){var t=document.getElementById('toast');return t&&t.classList.contains('visivel')?t.innerText:''})()`).catch(() => ''); if (x) return x; await esp(120); }
  return '';
}
async function agir(c, js) {
  // espera sumir qualquer toast anterior
  for (let i = 0; i < 40; i++) { const v = await c.avaliar(`!!(document.getElementById('toast')&&document.getElementById('toast').classList.contains('visivel'))`); if (!v) break; await esp(200); }
  await c.avaliar(js); return toastNovo(c);
}
const contaBots = (c) => c.avaliar(`fetch('/bots').then(r=>r.text()).then(h=>new Set(h.match(/bot\?id=\d+/g)||[]).size)`);
(async () => {
  const c = await lancar();
  try {
    await c.enviar('Emulation.setDeviceMetricsOverride', { width: 1400, height: 1000, deviceScaleFactor: 1, mobile: false });
    autoDialogo(c, true);
    await entrar(c, U.email, U.senha);
    await c.ir(BASE + '/bot'); await esp(900);
    const antes = await contaBots(c);
    let t = await agir(c, `document.getElementById('token').value='isto-nao-e-um-token'; document.getElementById('btn-salvar-bot').click(); 1`);
    chk('criar com token lixo: mensagem clara', t.length > 3 && !/obrigat/i.test(t) && !/error_code|"ok":false/.test(t), '"' + t + '"');
    t = await agir(c, `document.getElementById('token').value='123456789:AAHfakefakefakefakefakefakefakefakeX'; document.getElementById('btn-salvar-bot').click(); 1`);
    chk('criar com token falso (formato certo): recusado', t.length > 3 && !/obrigat/i.test(t) && !/error_code|"ok":false/.test(t), '"' + t + '"');
    t = await agir(c, `document.getElementById('token').value='123456789:AAHfakefakefakefakefakefakefakefakeX'; document.getElementById('btn-testar-token').click(); 1`);
    chk('"Testar" com token falso: mensagem traduzida', t.length > 3 && !/error_code|"ok":false/.test(t), '"' + t + '"');
    const depois = await contaBots(c);
    chk('erros não criaram bots (continua com ' + antes + ')', antes === depois && antes === 1, antes + ' -> ' + depois);
    // mesmo bot de novo (token real já usado pelo mesmo usuário)
    const H = JSON.parse(fs.readFileSync(path.join(ESTADO, 'cred_homolog.json'), 'utf8'));
    t = await agir(c, `document.getElementById('token').value=${JSON.stringify(H.bots[0].token)}; document.getElementById('btn-salvar-bot').click(); 1`);
    console.log('   recadastrar o mesmo token:', '"' + t.split(H.bots[0].token).join('<TOKEN>') + '"');
    await esp(3000);
    const dep2 = await contaBots(c);
    chk('mesmo token não duplica o bot', dep2 === 1, 'bots: ' + dep2);
    // outro usuário não acessa: id de bot inexistente/alheio
    await c.ir(BASE + '/bot?id=1'); await esp(900);
    const tok = await c.avaliar(`document.getElementById('token').value`);
    chk('/bot?id=1 (bot de outro usuário) não mostra token', tok === '', 'token preenchido=' + (tok !== ''));
  } catch (e) { console.log('ERRO', e.stack); falha++; }
  finally { try { c.pararDialogo(); } catch (e) {} await c.fechar(); }
  console.log(`\nRESUMO: ${ok} ok, ${falha} falhas`);
})();
