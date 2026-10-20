// Teste 2 na VPS: todas as páginas do usuário (desktop + celular) + rotas de admin/cron bloqueadas. Uso: node t2_paginas.js [rotulo]
process.env.BASE = 'https://coyotebot.com.br';
const fs = require('fs');
const path = require('path');
const ESTADO = process.env.ESTADO || path.join(__dirname, 'estado'); require('fs').mkdirSync(ESTADO, { recursive: true });
const PROJ = path.resolve(__dirname, '../../..');
const { lancar, entrar, inspecionar, resumo, BASE } = require(PROJ + '/tests/paginas/pg_lib.js');
const SP = ESTADO;
const U = JSON.parse(fs.readFileSync(path.join(SP, 'usuario_novo.json'), 'utf8'));
const ROT = process.argv[2] || 'vazia';
const OUT = path.join(SP, 'pg_vps_' + ROT); fs.mkdirSync(OUT, { recursive: true });
const esp = (ms) => new Promise(r => setTimeout(r, ms));

const PUBLICAS = ['/login', '/cadastro', '/termos', '/pagina-que-nao-existe'];
const USER = ['/', '/index', '/vendas', '/vendas?aba=pagas', '/vendas?aba=pendentes&periodo=7', '/bots', '/bot', '/fluxos', '/fluxo_basico', '/fluxo', '/leads', '/leads?status=pago',
  '/leads?status=nao_pago', '/links_rastreamento', '/redirecionamento', '/remarketing', '/gateways', '/traqueamento', '/webhooks', '/ranking', '/ranking?aba=mensal', '/configuracao_usuario', '/comunidade'];
const ADMIN = ['/admin/', '/admin/dashboard', '/admin/usuarios', '/admin/transacoes', '/admin/configuracoes', '/admin/comunidade', '/admin/ranking', '/admin/logs', '/admin/consultar_venda', '/admin/testes_desempenho', '/admin/atualiza_banco', '/seeds/popular_banco'];

let total = 0, problemas = 0;
async function varrer(c, rotulo, rotas, tela) {
  for (const rota of rotas) {
    let r;
    try { r = await inspecionar(c, rota); } catch (e) { console.log(`FALHA   | ${rotulo} | ${rota} | ${e.message}`); problemas++; total++; continue; }
    total++;
    let p = resumo(r);
    if (rota === '/pagina-que-nao-existe') { p = p.filter(x => !/^HTTP 404/.test(x)); if (r.status !== 404) p.push('devia ser 404, veio ' + r.status); }
    if (r.ms > 6000) p.push('LENTO ' + r.ms + ' ms');
    if (p.length) problemas++;
    console.log((p.length ? 'ATENÇÃO ' : 'ok      ') + `| ${rotulo.padEnd(9)} | ${rota.padEnd(36)} | ${String(r.status).padEnd(3)} | ${String(r.ms).padStart(5)} ms | ${(r.h1 || r.titulo || '').slice(0, 30).padEnd(30)} | ${r.url}` + (p.length ? ' | ' + p.join(' ; ') : ''));
    if (tela === 'desktop' && rotulo === 'usuário') await c.captura(path.join(OUT, rota.replace(/[^a-z0-9]+/gi, '_') + '.png')).catch(() => {});
  }
}

(async () => {
  const c = await lancar();
  try {
    for (const [largura, tela] of [[1500, 'desktop'], [390, 'celular']]) {
      console.log(`\n######## ${tela} (${largura}px) — conta ${ROT}`);
      await c.enviar('Network.clearBrowserCookies');
      await c.enviar('Emulation.setDeviceMetricsOverride', largura < 800 ? { width: largura, height: 800, deviceScaleFactor: 2, mobile: true } : { width: largura, height: 900, deviceScaleFactor: 1, mobile: false });
      await varrer(c, 'público', PUBLICAS, tela);
      console.log('login ->', await entrar(c, U.email, U.senha));
      const din = [];
      const links = async (rota, sel) => { await c.ir(BASE + rota); await esp(900); return JSON.parse(await c.avaliar(`JSON.stringify([...new Set([...document.querySelectorAll(${JSON.stringify(sel)})].map(function(a){return a.getAttribute('href')}))].slice(0,3))`)); };
      for (const h of await links('/bots', 'a[href*="bot?id="], a[href*="bot.php?id="]')) din.push('/' + h.replace(/^\//, ''));
      for (const h of await links('/fluxos', 'a[href*="fluxo_basico?id="], a[href*="fluxo?id="]')) din.push('/' + h.replace(/^\//, ''));
      for (const h of await links('/remarketing', 'a[href*="remarketing_detalhes"]')) din.push('/' + h.replace(/^\//, ''));
      await varrer(c, 'usuário', USER.concat([...new Set(din)]), tela);
      // área de admin: usuário comum NÃO pode ver nada
      if (tela === 'desktop') {
        for (const rota of ADMIN) {
          c.eventos.length = 0;
          await c.ir(BASE + rota); await esp(300);
          const fim = await c.avaliar('location.pathname');
          const txt = (await c.avaliar('document.body.innerText')).slice(0, 80).replace(/\s+/g, ' ');
          const barrado = !/^\/admin\/?(dashboard|usuarios|transacoes|configuracoes|comunidade|ranking|logs|consultar_venda|testes_desempenho|atualiza_banco)?$/.test(fim) && !/seeds\/popular/.test(fim);
          total++; if (!barrado) problemas++;
          console.log((barrado ? 'ok      ' : 'ATENÇÃO ') + `| admin-barrado | ${rota.padEnd(30)} | foi parar em ${fim} | ${txt}`);
        }
      }
    }
    // anônimo em rotas de usuário/admin
    await c.enviar('Network.clearBrowserCookies');
    for (const rota of ['/', '/bots', '/vendas', '/gateways', '/configuracao_usuario', '/admin/dashboard', '/ajax/bots_lista']) {
      await c.ir(BASE + rota); await esp(200);
      const fim = await c.avaliar('location.pathname');
      const corpo = await c.avaliar('document.body.innerText');
      const barrado = /login/.test(fim) || /404|403|n[ãa]o encontrad|acesso negado/i.test((await c.avaliar('document.title')) + corpo.slice(0,200));
      total++; if (!barrado) problemas++;
      console.log((barrado ? 'ok      ' : 'ATENÇÃO ') + `| anônimo       | ${rota.padEnd(30)} | foi parar em ${fim}`);
    }
    console.log(`\nRESUMO: ${total} verificações, ${problemas} com atenção`);
  } catch (e) { console.log('ERRO', e.stack); problemas++; }
  finally { await c.fechar(); process.exit(problemas ? 1 : 0); }
})();
