// Aviso de e-mail não confirmado + Reenviar + link de confirmação inválido.
process.env.BASE = 'https://coyotebot.com.br';
const fs = require('fs'), path = require('path');
const ESTADO = process.env.ESTADO || path.join(__dirname, 'estado'); require('fs').mkdirSync(ESTADO, { recursive: true });
const PROJ = path.resolve(__dirname, '../../..');
const { lancar, entrar, autoDialogo } = require(PROJ + '/tests/paginas/pg_lib.js');
const U = JSON.parse(fs.readFileSync(path.join(ESTADO, 'usuario_novo.json'), 'utf8'));
const BASE = 'https://coyotebot.com.br';
const esp = (ms) => new Promise(r => setTimeout(r, ms));
(async () => {
  const c = await lancar();
  try {
    await c.enviar('Emulation.setDeviceMetricsOverride', { width: 1400, height: 1000, deviceScaleFactor: 1, mobile: false });
    autoDialogo(c, true);
    await entrar(c, U.email, U.senha);
    await c.ir(BASE + '/index'); await esp(1200);
    const j = JSON.parse(await c.avaliar(`JSON.stringify({
      achados:[...document.querySelectorAll('*')].filter(function(e){return e.children.length===0 && /reenviar|confirm[ea]r? (seu )?e-?mail|e-?mail n[ãa]o (confirmado|verificado)|verifique seu e-?mail/i.test(e.textContent||'')}).map(function(e){return (e.tagName+' '+(e.id||e.className||'')+': '+e.textContent.trim().slice(0,100)+' | visível='+(e.offsetParent!==null))}).slice(0,6),
      temFuncao: typeof window.reenviarVerificacao
    })`));
    console.log('aviso de e-mail:', JSON.stringify(j));
    // link de confirmação inválido
    await c.ir(BASE + '/verificar_email?token=' + 'a'.repeat(64)); await esp(600);
    console.log('token inválido ->', (await c.avaliar(`document.body.innerText`)).split('\n').map(s => s.trim()).filter(Boolean).join(' | ').slice(0, 200));
    await c.ir(BASE + '/verificar_email'); await esp(600);
    console.log('sem token      ->', (await c.avaliar(`document.body.innerText`)).split('\n').map(s => s.trim()).filter(Boolean).join(' | ').slice(0, 200));
  } finally { try { c.pararDialogo(); } catch (e) {} await c.fechar(); }
})();
