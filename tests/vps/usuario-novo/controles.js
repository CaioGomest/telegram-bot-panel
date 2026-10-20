// Lista os controles interativos de cada rota (como o usuário novo). Uso: node controles.js /rota1 /rota2 ...
process.env.BASE = 'https://coyotebot.com.br';
const fs = require('fs'), path = require('path');
const ESTADO = process.env.ESTADO || path.join(__dirname, 'estado'); require('fs').mkdirSync(ESTADO, { recursive: true });
const PROJ = path.resolve(__dirname, '../../..');
const { lancar, entrar } = require(PROJ + '/tests/paginas/pg_lib.js');
const U = JSON.parse(fs.readFileSync(path.join(ESTADO, 'usuario_novo.json'), 'utf8'));
const linhas = (t) => String(t || '').split('\n').map(s => s.trim()).filter(Boolean);
(async () => {
  const c = await lancar();
  try {
    await c.enviar('Emulation.setDeviceMetricsOverride', { width: 1400, height: 1000, deviceScaleFactor: 1, mobile: false });
    await entrar(c, U.email, U.senha);
    for (const rota of process.argv.slice(2)) {
      await c.ir('https://coyotebot.com.br' + rota); await new Promise(r => setTimeout(r, 1200));
      const j = JSON.parse(await c.avaliar(`JSON.stringify({
        texto: document.querySelector('main') ? document.querySelector('main').innerText : document.body.innerText,
        forms: [...document.querySelectorAll('form')].map(function(f){return {id:f.id, action:f.getAttribute('action'), metodo:f.method, campos:[...f.querySelectorAll('input,select,textarea')].filter(function(i){return i.type!=='hidden'}).map(function(i){return (i.tagName.toLowerCase()+':'+(i.name||i.id)+(i.type?'['+i.type+']':'')+(i.required?'*':''))})}}),
        botoes: [...document.querySelectorAll('main button, main a.botao, .conteudo button')].map(function(b){return (b.id?('#'+b.id+' '):'')+(b.innerText||b.getAttribute('aria-label')||b.title||'').trim().slice(0,40)}).filter(Boolean).slice(0,40),
        modais: [...document.querySelectorAll('.sobreposicao-modal')].map(function(m){return m.id})
      })`));
      console.log('=== ' + rota + ' ===');
      console.log('TEXTO:', linhas(j.texto).join(' | ').slice(0, 1200));
      console.log('FORMS:', JSON.stringify(j.forms));
      console.log('BOTÕES:', JSON.stringify(j.botoes));
      console.log('MODAIS:', JSON.stringify(j.modais));
    }
  } finally { await c.fechar(); }
})();
