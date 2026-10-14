// Biblioteca do teste página por página (Chrome DevTools Protocol, sem dependências).
const { lancar } = require('../homologacao/ui/cdp.js');
const BASE = process.env.BASE || 'https://telegram.stackcode.com.br';

async function entrar(c, email, senha = '123456') {
  await c.ir(BASE + '/login');
  await c.esperar(`!!document.getElementById('email')`, 10000);
  await c.avaliar(`document.getElementById('email').value=${JSON.stringify(email)}; document.getElementById('senha').value=${JSON.stringify(senha)}; document.querySelector('form[action="login"]').submit(); 1`);
  await c.esperar(`location.pathname.indexOf('login')===-1`, 20000);
  await c.esperarCarga();
  return c.avaliar('location.pathname');
}

/** Abre uma página e devolve tudo que deu errado nela. */
async function inspecionar(c, rota, opt = {}) {
  c.eventos.length = 0;
  const t0 = Date.now();
  await c.ir(BASE + rota);
  if (opt.esperar) await c.esperar(opt.esperar, 8000);
  await new Promise(r => setTimeout(r, opt.pausa || 600));
  const doc = c.eventos.filter(e => e.method === 'Network.responseReceived' && e.params.type === 'Document').map(e => e.params.response.status);
  const http = c.eventos.filter(e => e.method === 'Network.responseReceived' && e.params.response.status >= 400 && e.params.type !== 'Document')
    .map(e => e.params.response.status + ' ' + e.params.response.url.replace(BASE, ''));
  const falhas = c.eventos.filter(e => e.method === 'Network.loadingFailed' && !e.params.canceled).map(e => e.params.errorText + ' ' + (e.params.requestId));
  const info = await c.avaliar(`(function(){
    var t = document.body ? document.body.innerText : '';
    var php = (t.match(/(Warning|Notice|Fatal error|Deprecated|Parse error|Uncaught|Stack trace)[^\\n]{0,140}/i) || [''])[0];
    var imgs = [...document.images].filter(function(i){return i.complete && i.naturalWidth===0 && i.src && !/^data:/.test(i.src)}).map(function(i){return i.src.slice(0,100)});
    return JSON.stringify({ url: location.pathname + location.search, titulo: document.title, h1: (document.querySelector('h1')||{}).innerText||'', php: php, imgs: imgs,
      overflowX: document.documentElement.scrollWidth > window.innerWidth + 2, larg: document.documentElement.scrollWidth, janela: window.innerWidth,
      botoes: document.querySelectorAll('button, a.botao, .btn-icon').length, tabelas: document.querySelectorAll('table').length, inputs: document.querySelectorAll('input,select,textarea').length,
      semRotulo: [...document.querySelectorAll('.btn-icon')].filter(function(b){return !b.getAttribute('title') && !b.getAttribute('aria-label')}).length,
      textoCurto: t.trim().length });
  })()`);
  const r = JSON.parse(info);
  r.status = doc[0]; r.http = http; r.falhas = falhas; r.errosJs = c.errosJs(); r.ms = Date.now() - t0;
  return r;
}

function resumo(r) {
  const p = [];
  if (r.status && r.status >= 400) p.push('HTTP ' + r.status);
  if (r.php) p.push('PHP: ' + r.php);
  if (r.errosJs.length) p.push('JS: ' + r.errosJs.slice(0, 2).join(' | ').slice(0, 200));
  if (r.http.length) p.push('REQ: ' + r.http.slice(0, 3).join(', '));
  if (r.falhas.length) p.push('FALHA REDE: ' + r.falhas.length);
  if (r.imgs.length) p.push('IMG QUEBRADA: ' + r.imgs[0]);
  if (r.overflowX) p.push('OVERFLOW-X ' + r.larg + '>' + r.janela);
  if (r.semRotulo) p.push('ícone sem title/aria-label: ' + r.semRotulo);
  return p;
}

module.exports = { lancar, entrar, inspecionar, resumo, BASE };

// Consulta de leitura ao banco do servidor (via zz_q.php). Devolve array de linhas.
const { execFileSync } = require('child_process');
function db(sql) {
  const b64 = Buffer.from(sql).toString('base64');
  const out = execFileSync('plink', ['-batch', '-P', '65002', '-pw', process.env.PGPW, 'u214219698@185.213.81.10', `cd domains/stackcode.com.br/public_html/telegram && php zz_q.php ${b64}`], { encoding: 'utf8' });
  return JSON.parse(out);
}
module.exports.db = db;

// Roda um script do painel no servidor (cwd = pasta do painel). Ex.: remoto('php zz_pg.php act')
function remoto(cmd) {
  return execFileSync('plink', ['-batch', '-P', '65002', '-pw', process.env.PGPW, 'u214219698@185.213.81.10', `cd domains/stackcode.com.br/public_html/telegram && ${cmd}`], { encoding: 'utf8' });
}
module.exports.remoto = remoto;

// Responde sozinho a alert/confirm/prompt (confirm bloqueia o JS até alguém responder). aceitar=true => OK.
function autoDialogo(c, aceitar = true) {
  c.dialogos = [];
  let visto = 0;
  const iv = setInterval(async () => {
    const novos = c.eventos.filter(e => e.method === 'Page.javascriptDialogOpening');
    while (visto < novos.length) {
      const d = novos[visto++];
      c.dialogos.push({ tipo: d.params.type, msg: d.params.message });
      try { await c.enviar('Page.handleJavaScriptDialog', { accept: typeof c.aceitar === 'boolean' ? c.aceitar : aceitar, promptText: '' }); } catch (e) {}
    }
  }, 150);
  c.pararDialogo = () => clearInterval(iv);
}
module.exports.autoDialogo = autoDialogo;

// Lê o aviso (#toast) enquanto está visível (ele some em 3 s). Devolve o texto ou ''.
async function lerToast(c, ms = 7000) {
  const t0 = Date.now();
  while (Date.now() - t0 < ms) {
    const x = await c.avaliar(`(function(){var t=document.getElementById('toast');return t&&t.classList.contains('visivel')?t.innerText:''})()`).catch(() => '');
    if (x) return x;
    await new Promise(r => setTimeout(r, 120));
  }
  return '';
}
module.exports.lerToast = lerToast;
