// Admin na VPS: 1 tentativa de login (o limite é 5 erros/15 min por e-mail — não insistir). Uso: ADMIN_EMAIL=... ADMIN_SENHA=... node 12_admin_login.js
process.env.BASE = 'https://coyotebot.com.br';
const fs = require('fs'), path = require('path');
const PROJ = path.resolve(__dirname, '../../..');
const { lancar, entrar } = require(PROJ + '/tests/paginas/pg_lib.js');
const ESTADO = process.env.ESTADO || path.join(__dirname, 'estado'); fs.mkdirSync(ESTADO, { recursive: true });
(async () => {
  const c = await lancar();
  try {
    await c.enviar('Emulation.setDeviceMetricsOverride', { width: 1500, height: 1000, deviceScaleFactor: 1, mobile: false });
    await c.ir('https://coyotebot.com.br/login');
    await c.esperar(`!!document.getElementById('email')`, 10000);
    await c.avaliar(`(function(){document.getElementById('email').value=${JSON.stringify(process.env.ADMIN_EMAIL)};document.getElementById('senha').value=${JSON.stringify(process.env.ADMIN_SENHA)};document.querySelector('form[action="login"]').submit();return 1})()`);
    await new Promise(r => setTimeout(r, 3000)); await c.esperarCarga();
    const url = await c.avaliar('location.pathname');
    const texto = (await c.avaliar('document.body.innerText')).split('\n').map(s => s.trim()).filter(Boolean).join(' | ').slice(0, 250);
    console.log('depois do login:', url, '|', texto);
  } finally { await c.fechar(); }
})();
