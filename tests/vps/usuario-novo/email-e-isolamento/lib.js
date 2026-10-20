// Utilitários dos testes de e-mail e isolamento na VPS (coyotebot.com.br). Reaproveita o cliente CDP do repositório.
// Variáveis da rodada (e-mail descartável, senha) e capturas ficam em ../estado/email-e-isolamento/ (ignorado pelo git).
const fs = require('fs');
const path = require('path');
const { lancar: lancarBruto } = require(path.join(__dirname, '..', '..', '..', 'homologacao', 'ui', 'cdp.js'));
const SP = path.join(__dirname, '..', 'estado', 'email-e-isolamento');
fs.mkdirSync(path.join(SP, 'shots'), { recursive: true });

// Navegar para a MESMA url de uma página de resposta POST vira "recarregar" no Chrome e REENVIA o formulário
// (gastava o limite de cadastros e contava logins errados em dobro). Passar por about:blank evita isso.
async function lancar() {
  const c = await lancarBruto();
  const irBruto = c.ir;
  c.ir = async (url) => { if (url !== 'about:blank') await irBruto('about:blank'); return irBruto(url); };
  return c;
}
const BASE = process.env.BASE || 'https://coyotebot.com.br';

function env() {
  const o = {};
  fs.readFileSync(path.join(SP, 'teste_vps.env'), 'utf8').split(/\r?\n/).forEach(l => { const i = l.indexOf('='); if (i > 0) o[l.slice(0, i)] = l.slice(i + 1); });
  return o;
}

const resultados = [];
function ok(rotulo, cond, extra) {
  const linha = (cond ? 'PASS' : 'FAIL') + ' | ' + rotulo + (cond ? '' : ' | ' + JSON.stringify(extra));
  resultados.push(linha); console.log('  ' + linha);
  return !!cond;
}
function salvarResultados(nome) {
  fs.writeFileSync(path.join(SP, 'res_' + nome + '.txt'), resultados.join('\n') + '\n');
  console.log('RESUMO ' + nome + ' PASS=' + resultados.filter(r => r.startsWith('PASS')).length + ' FAIL=' + resultados.filter(r => r.startsWith('FAIL')).length);
}

async function entrar(c, email, senha) {
  await c.ir(BASE + '/login');
  await c.esperar(`!!document.getElementById('email')`, 10000);
  await c.avaliar(`document.getElementById('email').value=${JSON.stringify(email)}; document.getElementById('senha').value=${JSON.stringify(senha)}; document.querySelector('form[action="login"]').submit(); 1`);
  await c.esperar(`location.pathname.indexOf('login')===-1`, 20000);
  await c.esperarCarga();
  return c.avaliar('location.pathname');
}

// Abre uma página e devolve tudo que deu errado nela (mesma ideia do tests/paginas/pg_lib.js).
async function inspecionar(c, rota, opt = {}) {
  c.eventos.length = 0;
  const t0 = Date.now();
  await c.ir(BASE + rota);
  if (opt.esperar) await c.esperar(opt.esperar, 8000);
  await new Promise(r => setTimeout(r, opt.pausa || 700));
  const doc = c.eventos.filter(e => e.method === 'Network.responseReceived' && e.params.type === 'Document').map(e => e.params.response.status);
  const http = c.eventos.filter(e => e.method === 'Network.responseReceived' && e.params.response.status >= 400 && e.params.type !== 'Document')
    .map(e => e.params.response.status + ' ' + e.params.response.url.replace(BASE, ''));
  const falhas = c.eventos.filter(e => e.method === 'Network.loadingFailed' && !e.params.canceled).map(e => e.params.errorText);
  const info = await c.avaliar(`(function(){
    var t = document.body ? document.body.innerText : '';
    var php = (t.match(/(Warning|Notice|Fatal error|Deprecated|Parse error|Uncaught|Stack trace)[^\\n]{0,140}/i) || [''])[0];
    var imgs = [...document.images].filter(function(i){return i.complete && i.naturalWidth===0 && i.src && !/^data:/.test(i.src)}).map(function(i){return i.src.slice(0,100)});
    return JSON.stringify({ url: location.pathname + location.search, titulo: document.title, h1: (document.querySelector('h1')||{}).innerText||'', php: php, imgs: imgs,
      overflowX: document.documentElement.scrollWidth > window.innerWidth + 2, larg: document.documentElement.scrollWidth, janela: window.innerWidth,
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

// Caixa de entrada descartável (mail.tm)
async function emails() {
  const e = env();
  const r = await fetch('https://api.mail.tm/messages', { headers: { Authorization: 'Bearer ' + e.MAIL_TOKEN } });
  const j = await r.json();
  return j['hydra:member'] || [];
}
async function emailCompleto(id) {
  const e = env();
  const r = await fetch('https://api.mail.tm/messages/' + id, { headers: { Authorization: 'Bearer ' + e.MAIL_TOKEN } });
  return r.json();
}
const dorme = (ms) => new Promise(r => setTimeout(r, ms));

module.exports = { lancar, env, ok, resultados, salvarResultados, entrar, inspecionar, resumo, emails, emailCompleto, dorme, BASE, SP };

// Lê o aviso (#toast) enquanto está visível. Devolve o texto ou ''.
async function lerToast(c, ms = 8000) {
  const t0 = Date.now();
  while (Date.now() - t0 < ms) {
    const x = await c.avaliar(`(function(){var t=document.getElementById('toast');return t&&t.classList.contains('visivel')?t.innerText:''})()`).catch(() => '');
    if (x) return x;
    await new Promise(r => setTimeout(r, 120));
  }
  return '';
}
module.exports.lerToast = lerToast;
// Responde sozinho a alert/confirm (aceitar=true => OK).
function autoDialogo(c, aceitar = true) {
  c.dialogos = []; c.aceitar = aceitar; let visto = 0;
  const iv = setInterval(async () => {
    const novos = c.eventos.filter(e => e.method === 'Page.javascriptDialogOpening');
    while (visto < novos.length) {
      const d = novos[visto++]; c.dialogos.push({ tipo: d.params.type, msg: d.params.message });
      try { await c.enviar('Page.handleJavaScriptDialog', { accept: !!c.aceitar, promptText: '' }); } catch (e) {}
    }
  }, 150);
  c.pararDialogo = () => clearInterval(iv);
}
module.exports.autoDialogo = autoDialogo;
