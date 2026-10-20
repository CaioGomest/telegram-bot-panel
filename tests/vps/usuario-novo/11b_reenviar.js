// Aciona "Reenviar" (e-mail de confirmação) uma vez e mostra a resposta do servidor.
process.env.BASE = 'https://coyotebot.com.br';
const fs = require('fs'), path = require('path');
const ESTADO = process.env.ESTADO || path.join(__dirname, 'estado'); require('fs').mkdirSync(ESTADO, { recursive: true });
const PROJ = path.resolve(__dirname, '../../..');
const { lancar, entrar, autoDialogo } = require(PROJ + '/tests/paginas/pg_lib.js');
const U = JSON.parse(fs.readFileSync(path.join(ESTADO, 'usuario_novo.json'), 'utf8'));
const esp = (ms) => new Promise(r => setTimeout(r, ms));
(async () => {
  const c = await lancar();
  try {
    await c.enviar('Emulation.setDeviceMetricsOverride', { width: 1400, height: 1000, deviceScaleFactor: 1, mobile: false });
    autoDialogo(c, true);
    await entrar(c, U.email, U.senha);
    await c.ir('https://coyotebot.com.br/index'); await esp(1200);
    c.eventos.length = 0;
    await c.avaliar(`document.querySelector('[data-reenviar-verificacao]').click(); 1`);
    await esp(6000);
    const resp = c.eventos.filter(e => e.method === 'Network.responseReceived' && /reenviar_verificacao/.test(e.params.response.url)).map(e => e.params.response.status);
    const corpo = await c.avaliar(`(function(){var b=document.querySelector('[data-reenviar-verificacao]');var p=b&&b.parentElement;return p?p.innerText.trim().slice(0,200):''})()`);
    const toast = await c.avaliar(`(function(){var t=document.getElementById('toast');return t?t.innerText:''})()`);
    console.log('status HTTP:', JSON.stringify(resp), '| texto do aviso:', JSON.stringify(corpo), '| toast:', JSON.stringify(toast));
  } finally { try { c.pararDialogo(); } catch (e) {} await c.fechar(); }
})();
