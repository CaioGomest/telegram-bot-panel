// Clica num botão e lista o que apareceu (campos). Uso: node modal.js /rota "#seletor-do-botao"
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
    await c.enviar('Emulation.setDeviceMetricsOverride', { width: 1400, height: 1100, deviceScaleFactor: 1, mobile: false });
    await entrar(c, U.email, U.senha);
    await c.ir('https://coyotebot.com.br' + process.argv[2]); await new Promise(r => setTimeout(r, 1200));
    await c.avaliar(`document.querySelector(${JSON.stringify(process.argv[3])}).click(); 1`);
    await new Promise(r => setTimeout(r, 1200));
    const j = JSON.parse(await c.avaliar(`JSON.stringify({
      campos: [...document.querySelectorAll('input,select,textarea')].filter(function(i){return i.type!=='hidden' && i.offsetParent!==null}).map(function(i){return i.tagName.toLowerCase()+':'+(i.name||i.id)+(i.type?'['+i.type+']':'')+(i.required?'*':'')+(i.tagName==='SELECT'?' {'+[...i.options].map(function(o){return o.value+'='+o.text.trim()}).join(';').slice(0,160)+'}':'')}),
      botoes: [...document.querySelectorAll('button')].filter(function(b){return b.offsetParent!==null}).map(function(b){return (b.id?('#'+b.id+' '):'')+(b.innerText||b.getAttribute('aria-label')||'').trim().slice(0,30)}).filter(Boolean).slice(0,30),
      visivel: [...document.querySelectorAll('.sobreposicao-modal')].filter(function(m){return getComputedStyle(m).display!=='none'}).map(function(m){return m.id+': '+m.innerText.slice(0,500)})
    })`));
    console.log('CAMPOS:', JSON.stringify(j.campos, null, 0));
    console.log('BOTÕES:', JSON.stringify(j.botoes));
    console.log('MODAL VISÍVEL:', linhas(j.visivel.join('\n')).join(' | ').slice(0, 900));
    await c.captura(path.join(ESTADO, 'modal_' + process.argv[2].replace(/[^a-z0-9]+/gi, '_') + '.png'));
  } finally { await c.fechar(); }
})();
