// Admin na VPS: login, menu, todas as páginas de admin em desktop e celular, e as telas de OPERAÇÃO do vendedor barradas para o admin.
// Só leitura (não clica em nada que grave). Uso: ADMIN_EMAIL=... ADMIN_SENHA=... node 13_admin_paginas.js
process.env.BASE = 'https://coyotebot.com.br';
const fs = require('fs'), path = require('path');
const PROJ = path.resolve(__dirname, '../../..');
const { lancar, entrar, inspecionar, resumo } = require(PROJ + '/tests/paginas/pg_lib.js');
const ESTADO = process.env.ESTADO || path.join(__dirname, 'estado'); fs.mkdirSync(ESTADO, { recursive: true });
const BASE = 'https://coyotebot.com.br';
const esp = (ms) => new Promise(r => setTimeout(r, ms));
let total = 0, problemas = 0;
const linhas = (t) => String(t || '').split('\n').map(s => s.trim()).filter(Boolean);

(async () => {
  const c = await lancar();
  try {
    for (const [largura, tela] of [[1500, 'desktop'], [390, 'celular']]) {
      console.log(`\n######## admin — ${tela} (${largura}px)`);
      await c.enviar('Network.clearBrowserCookies');
      await c.enviar('Emulation.setDeviceMetricsOverride', largura < 800 ? { width: largura, height: 800, deviceScaleFactor: 2, mobile: true } : { width: largura, height: 1000, deviceScaleFactor: 1, mobile: false });
      const destino = await entrar(c, process.env.ADMIN_EMAIL, process.env.ADMIN_SENHA);
      console.log('login ->', destino);
      total++; if (/login/.test(destino)) { problemas++; console.log('FALHOU login do admin'); break; }
      if (tela === 'desktop') {
        await c.ir(BASE + '/admin/dashboard'); await esp(1500);
        const menu = JSON.parse(await c.avaliar(`JSON.stringify([...new Map([...document.querySelectorAll('aside a, nav a')].map(function(a){return [a.getAttribute('href'),(a.getAttribute('title')||a.getAttribute('aria-label')||a.innerText||'').trim()]})).entries()])`));
        console.log('menu do admin:', menu.map(m => m[1] + '→' + m[0]).join(' ; '));
        fs.writeFileSync(path.join(ESTADO, 'menu_admin.json'), JSON.stringify(menu));
      }
      const menu = JSON.parse(fs.readFileSync(path.join(ESTADO, 'menu_admin.json'), 'utf8'));
      const rotas = [...new Set(menu.map(m => m[0]).filter(h => h && !/logout|^#|javascript/.test(h)).map(h => '/' + h.replace(/^\.?\//, '')))];
      for (const rota of rotas) {
        let r;
        try { r = await inspecionar(c, rota, { pausa: 1000 }); } catch (e) { console.log(`FALHA   | ${rota} | ${e.message}`); problemas++; total++; continue; }
        total++;
        const p = resumo(r).filter(x => !/ícone sem title/.test(x));
        if (r.ms > 8000) p.push('LENTO ' + r.ms + ' ms');
        if (p.length) problemas++;
        console.log((p.length ? 'ATENÇÃO ' : 'ok      ') + `| ${rota.padEnd(34)} | ${String(r.status).padEnd(3)} | ${String(r.ms).padStart(5)} ms | ${(r.h1 || r.titulo || '').slice(0, 30).padEnd(30)}` + (p.length ? ' | ' + p.join(' ; ') : ''));
        if (tela === 'desktop') await c.captura(path.join(ESTADO, 'admin_' + rota.replace(/[^a-z0-9]+/gi, '_') + '.png')).catch(() => {});
      }
      if (tela === 'desktop') {
        // o admin administra a plataforma: telas de OPERAÇÃO do vendedor devem redirecionar para o painel de admin
        for (const rota of ['/bots', '/fluxos', '/fluxo', '/leads', '/vendas', '/gateways', '/remarketing']) {
          await c.ir(BASE + rota); await esp(400);
          const fim = await c.avaliar('location.pathname');
          const ehAdmin = /^\/admin|gateways$/.test(fim);
          total++;
          console.log(`info    | admin abrindo ${rota.padEnd(14)} -> ${fim}`);
        }
      }
    }
    console.log(`\nRESUMO: ${total} verificações, ${problemas} com atenção`);
  } catch (e) { console.log('ERRO', e.stack); problemas++; }
  finally { await c.fechar(); process.exit(problemas ? 1 : 0); }
})();
