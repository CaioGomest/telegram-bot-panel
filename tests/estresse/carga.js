// Gerador de carga HTTP (sem dependências, Node 22+). Fala com o painel como navegadores de verdade: login, cookie de sessão, CSRF.
// Uso: node carga.js <fase> [opções]    fases: login | mistura | paginas | publico | admin | soak
//   BASE=https://telegram.stackcode.com.br  USUARIOS=300  DEGRAUS=5,10,20,40  DURACAO=20  PARAR_ERRO=5  PARAR_P95=8000  SAIDA=./saida
const fs = require('fs');
const BASE = process.env.BASE || 'https://telegram.stackcode.com.br';
const USUARIOS = parseInt(process.env.USUARIOS || '300', 10);
const DURACAO = parseInt(process.env.DURACAO || '20', 10);
const DEGRAUS = (process.env.DEGRAUS || '5,10,20,40').split(',').map(Number);
const PARAR_ERRO = parseFloat(process.env.PARAR_ERRO || '5');   // % de erro que interrompe a rampa
const PARAR_P95 = parseFloat(process.env.PARAR_P95 || '8000');   // ms
const TIMEOUT = parseInt(process.env.TIMEOUT || '30000', 10);
const SAIDA = process.env.SAIDA || '.';
const dorme = (ms) => new Promise(r => setTimeout(r, ms));

function pct(arr, p) { if (!arr.length) return 0; const s = [...arr].sort((a, b) => a - b); return s[Math.min(s.length - 1, Math.floor(p / 100 * s.length))]; }
function resumo(nome, lat, codigos, erros, seg) {
  const total = lat.length + erros.length;
  const ruins = codigos.filter(c => c >= 500 || c === 429 || c === 403).length + erros.length;
  return { nome, requisicoes: total, rps: +(total / seg).toFixed(1), p50: Math.round(pct(lat, 50)), p95: Math.round(pct(lat, 95)), p99: Math.round(pct(lat, 99)), max: Math.round(Math.max(0, ...lat)), erros: ruins, pctErro: total ? +(ruins / total * 100).toFixed(1) : 0, status: contar(codigos), falhasRede: erros.length };
}
function contar(a) { const o = {}; for (const x of a) o[x] = (o[x] || 0) + 1; return o; }

async function req(sessao, caminho, { metodo = 'GET', corpo = null, headers = {} } = {}) {
  const ac = new AbortController();
  const timer = setTimeout(() => ac.abort(), TIMEOUT);
  const t0 = performance.now();
  try {
    const h = Object.assign({ 'User-Agent': 'Mozilla/5.0 teste-estresse' }, headers);
    if (sessao && sessao.cookie) h.Cookie = sessao.cookie;
    const r = await fetch(BASE + caminho, { method: metodo, body: corpo, headers: h, redirect: 'manual', signal: ac.signal });
    const texto = await r.text();
    const sc = r.headers.getSetCookie ? r.headers.getSetCookie() : [];
    for (const c of sc) { const m = c.match(/^(PHPSESSID=[^;]+)/); if (m && sessao) sessao.cookie = m[1]; }
    return { ms: performance.now() - t0, status: r.status, texto, loc: r.headers.get('location') };
  } catch (e) {
    return { ms: performance.now() - t0, erro: e.name === 'AbortError' ? 'timeout' : String(e.cause && e.cause.code || e.message) };
  } finally { clearTimeout(timer); }
}

async function login(i) {
  const s = { cookie: '', csrf: '', i };
  const g = await req(s, '/login');
  if (g.erro) return { ok: false, erro: g.erro, ms: g.ms };
  const m = g.texto.match(/name="csrf_token" value="([^"]+)"/);
  if (!m) return { ok: false, erro: 'sem csrf', ms: g.ms };
  const corpo = new URLSearchParams({ acao: 'login', email: `cliente${i}@gmial.com`, senha: '12345678', csrf_token: m[1] }).toString();
  const p = await req(s, '/login', { metodo: 'POST', corpo, headers: { 'Content-Type': 'application/x-www-form-urlencoded' } });
  if (p.erro) return { ok: false, erro: p.erro, ms: p.ms + g.ms };
  const ok = p.status === 302 && /index|dashboard|\/$/.test(p.loc || '');
  return { ok, sessao: s, ms: g.ms + p.ms, status: p.status, loc: p.loc };
}

async function pegaCsrf(s) {
  const r = await req(s, '/bots');
  const m = r.texto && r.texto.match(/window\.CSRF_TOKEN\s*=\s*"([^"]+)"/);
  s.csrf = m ? m[1] : '';
}

async function loginTodos(n, conc) {
  const sessoes = [], lat = [], erros = [], cods = [];
  let prox = 1;
  const t0 = performance.now();
  await Promise.all(Array.from({ length: conc }, async () => {
    while (prox <= n) {
      const i = prox++;
      const r = await login(i);
      if (r.ok) { sessoes.push(r.sessao); lat.push(r.ms); cods.push(302); }
      else { erros.push(r.erro || ('HTTP ' + r.status)); cods.push(r.status || 0); }
    }
  }));
  return { sessoes, res: resumo(`login x${n} conc=${conc}`, lat, cods.filter(c => c), erros.filter(e => typeof e === 'string' && !/^HTTP/.test(e)), (performance.now() - t0) / 1000), motivos: contar(erros) };
}

// ---- mistura de páginas (peso, caminho). {i} = id do usuário
const MISTURA = [
  [14, '/'], [8, '/?periodo=30dias'], [8, '/bots'], [8, '/fluxos'], [10, '/leads'], [4, '/leads?busca=Ana'], [4, '/leads?status=pago'],
  [5, '/links_rastreamento'], [4, '/remarketing'], [6, '/ranking'], [3, '/ranking?aba=mensal'], [3, '/webhooks'], [2, '/configuracao_usuario'], [2, '/gateways'],
  [10, '/api?action=listar_bots'], [6, '/api?action=listar_fluxos'], [12, '/ajax/notificacoes'], [3, '/api?action=listar_links_rastreamento'],
];
function sorteia(lista) { const total = lista.reduce((a, [p]) => a + p, 0); let r = Math.random() * total; for (const [p, c] of lista) { r -= p; if (r <= 0) return c; } return lista[0][1]; }

async function rodaDegrau(nome, sessoes, vus, seg, escolhe, pensa = 0) {
  const lat = [], cods = [], erros = [], porRota = {};
  const fim = performance.now() + seg * 1000;
  const t0 = performance.now();
  await Promise.all(Array.from({ length: vus }, async (_, k) => {
    const s = sessoes[k % sessoes.length];
    while (performance.now() < fim) {
      const caminho = escolhe(s);
      const r = await req(s, caminho);
      const rota = caminho.split('?')[0] + (caminho.includes('action=') ? '?' + caminho.split('action=')[1].split('&')[0] : '');
      (porRota[rota] = porRota[rota] || { lat: [], ruins: 0, n: 0 });
      porRota[rota].n++;
      if (r.erro) { erros.push(r.erro); porRota[rota].ruins++; }
      else { lat.push(r.ms); cods.push(r.status); porRota[rota].lat.push(r.ms); if (r.status >= 500 || r.status === 429 || r.status === 403 || r.status === 302 && /login/.test(r.loc || '')) porRota[rota].ruins++; }
      if (pensa) await dorme(pensa * (0.5 + Math.random()));
    }
  }));
  const seg2 = (performance.now() - t0) / 1000;
  const res = resumo(nome, lat, cods, erros, seg2);
  res.sessaoPerdida = Object.values(porRota).reduce((a, x) => a, 0);
  res.rotas = Object.fromEntries(Object.entries(porRota).map(([k, v]) => [k, { n: v.n, p50: Math.round(pct(v.lat, 50)), p95: Math.round(pct(v.lat, 95)), ruins: v.ruins }]));
  res.motivosErro = contar(erros);
  return res;
}

function linha(r) { return `${String(r.nome).padEnd(26)} ${String(r.requisicoes).padStart(6)} req  ${String(r.rps).padStart(6)} req/s  p50 ${String(r.p50).padStart(5)}  p95 ${String(r.p95).padStart(5)}  p99 ${String(r.p99).padStart(5)}  máx ${String(r.max).padStart(6)} ms  erros ${String(r.erros).padStart(4)} (${r.pctErro}%)  ${JSON.stringify(r.status)}${r.falhasRede ? ' rede:' + JSON.stringify(r.motivosErro || '') : ''}`; }

(async () => {
  const fase = process.argv[2] || 'mistura';
  const resultados = [];
  fs.mkdirSync(SAIDA, { recursive: true });
  const salva = () => fs.writeFileSync(`${SAIDA}/resultado_${fase}.json`, JSON.stringify(resultados, null, 1));
  console.log(`# fase=${fase} base=${BASE} usuarios=${USUARIOS} duracao=${DURACAO}s degraus=${DEGRAUS.join(',')}`);

  if (fase === 'login') {
    for (const conc of DEGRAUS) {
      const r = await loginTodos(Math.min(USUARIOS, 150), conc);
      console.log(linha(r.res), JSON.stringify(r.motivos));
      resultados.push(Object.assign(r.res, { motivos: r.motivos }));
      salva();
      if (r.res.pctErro > PARAR_ERRO || r.res.p95 > PARAR_P95) { console.log('>> critério de parada atingido'); break; }
      await dorme(3000);
    }
    return;
  }

  // demais fases precisam de sessões: loga em lote moderado
  console.log('logando', USUARIOS, 'usuários (conc 8)...');
  const lg = await loginTodos(USUARIOS, 8);
  console.log(linha(lg.res), JSON.stringify(lg.motivos));
  const sessoes = lg.sessoes;
  if (sessoes.length < 10) { console.log('poucas sessões; abortando'); return; }
  await Promise.all(sessoes.map(pegaCsrf));

  if (fase === 'mistura' || fase === 'soak') {
    const graus = fase === 'soak' ? [DEGRAUS[0]] : DEGRAUS;
    for (const vus of graus) {
      const seg = fase === 'soak' ? parseInt(process.env.DURACAO || '180', 10) : DURACAO;
      const r = await rodaDegrau(`${fase} ${vus} VUs`, sessoes, vus, seg, () => sorteia(MISTURA), parseInt(process.env.PENSA || '0', 10));
      console.log(linha(r));
      resultados.push(r); salva();
      if (r.pctErro > PARAR_ERRO || r.p95 > PARAR_P95) { console.log(`>> parando a rampa: erro ${r.pctErro}% p95 ${r.p95}ms`); break; }
      await dorme(4000);
    }
  } else if (fase === 'paginas') {
    // cada rota sozinha, mesma concorrência, pra achar a mais pesada
    const vus = DEGRAUS[0];
    for (const [, caminho] of MISTURA) {
      const r = await rodaDegrau(caminho.replace('/api?action=', 'api:').replace('/ajax/', 'ajax:'), sessoes, vus, Math.min(DURACAO, 10), () => caminho);
      console.log(linha(r)); resultados.push(r); salva();
      await dorme(1500);
    }
  } else if (fase === 'publico') {
    // link público de redirecionamento (sem login): precisa de um slug criado antes (SLUG=...)
    const slug = process.env.SLUG || 'estresse-link';
    for (const vus of DEGRAUS) {
      const r = await rodaDegrau(`/l/${slug} ${vus} VUs`, [{ cookie: '' }], vus, DURACAO, () => `/l/${slug}?utm_campaign=estresse`);
      console.log(linha(r)); resultados.push(r); salva();
      if (r.pctErro > PARAR_ERRO || r.p95 > PARAR_P95) { console.log('>> parando'); break; }
      await dorme(3000);
    }
  }
  salva();
})();
