// Teste 10: menu lateral, dashboard (períodos), sino, tema, filtros de vendas/leads/ranking, comunidade.
process.env.BASE = 'https://coyotebot.com.br';
const fs = require('fs'), path = require('path');
const ESTADO = process.env.ESTADO || path.join(__dirname, 'estado'); require('fs').mkdirSync(ESTADO, { recursive: true });
const PROJ = path.resolve(__dirname, '../../..');
const { lancar, entrar, autoDialogo, inspecionar, resumo } = require(PROJ + '/tests/paginas/pg_lib.js');
const U = JSON.parse(fs.readFileSync(path.join(ESTADO, 'usuario_novo.json'), 'utf8'));
const BASE = 'https://coyotebot.com.br';
const esp = (ms) => new Promise(r => setTimeout(r, ms));
const linhas = (t) => String(t || '').split('\n').map(s => s.trim()).filter(Boolean);
let ok = 0, falha = 0;
const chk = (n, c, x = '') => { c ? ok++ : falha++; console.log((c ? 'OK     ' : 'FALHOU ') + n + (x ? '  -> ' + x : '')); };
const main = async (c) => linhas(await c.avaliar(`document.querySelector('main').innerText`)).join(' | ');
const problemasRede = (c) => c.eventos.filter(e => e.method === 'Network.responseReceived' && e.params.response.status >= 400).map(e => e.params.response.status + ' ' + e.params.response.url.replace(BASE, ''));

(async () => {
  const c = await lancar();
  try {
    await c.enviar('Emulation.setDeviceMetricsOverride', { width: 1400, height: 1000, deviceScaleFactor: 1, mobile: false });
    autoDialogo(c, true);
    await entrar(c, U.email, U.senha);

    // ---- menu lateral: segue cada link
    console.log('\n--- Menu lateral');
    await c.ir(BASE + '/index'); await esp(800);
    const links = JSON.parse(await c.avaliar(`JSON.stringify([...document.querySelectorAll('aside a, nav a, .barra-lateral a')].map(function(a){return {h:a.getAttribute('href'),t:(a.getAttribute('title')||a.getAttribute('aria-label')||a.innerText||'').trim()}}).filter(function(x){return x.h && x.h.charAt(0)!=='#' && x.h.indexOf('javascript')<0}))`));
    const unicos = [...new Map(links.map(l => [l.h, l])).values()];
    console.log('   itens:', unicos.map(l => l.t + '→' + l.h).join(' ; '));
    for (const l of unicos) {
      if (/logout/.test(l.h)) continue;
      const r = await inspecionar(c, '/' + l.h.replace(/^\//, '').replace(/^\.\//, ''));
      const p = resumo(r).filter(x => !/ícone sem title/.test(x));
      chk('menu "' + (l.t || l.h) + '" → ' + l.h, p.length === 0 && r.status === 200, p.join(' ; ') + ' [' + (r.h1 || r.titulo) + ']');
    }

    // ---- dashboard: períodos
    console.log('\n--- Dashboard');
    await c.ir(BASE + '/index'); await esp(1000);
    c.eventos.length = 0;
    const botoesPeriodo = JSON.parse(await c.avaliar(`JSON.stringify([...document.querySelectorAll('main button, main a')].map(function(b){return b.innerText.trim()}).filter(function(t){return ['Hoje','Ontem','8 dias','30 dias','Total'].indexOf(t)>=0}))`));
    chk('dashboard tem os 5 filtros de período', botoesPeriodo.length === 5, JSON.stringify(botoesPeriodo));
    for (const p of ['Hoje', 'Ontem', '30 dias', 'Total', '8 dias']) {
      await c.avaliar(`(function(){var b=[...document.querySelectorAll('main button, main a')].find(function(x){return x.innerText.trim()===${JSON.stringify(p)}});if(b)b.click();return 1})()`);
      await esp(1500);
      await c.esperarCarga();
    }
    const errosJs = c.errosJs(); const red = problemasRede(c);
    chk('trocar período 5x sem erro de JS nem requisição com erro', errosJs.length === 0 && red.length === 0, 'js=' + errosJs.length + ' rede=' + JSON.stringify(red));

    // ---- sino e tema
    console.log('\n--- Sino, tema');
    await c.ir(BASE + '/index'); await esp(1000);
    await c.avaliar(`(function(){var b=[...document.querySelectorAll('button')].find(function(x){return /Notifica/i.test(x.getAttribute('aria-label')||x.title||'')});if(b)b.click();return 1})()`);
    await esp(1500);
    const painelSino = await c.avaliar(`(function(){var p=[...document.querySelectorAll('[class*=notifica], [id*=notifica]')].filter(function(e){return e.offsetParent!==null && e.innerText.trim().length>0});return p.length?p[0].innerText.trim().slice(0,300):''})()`);
    console.log('   painel do sino:', linhas(painelSino).join(' | '));
    chk('sino abre um painel', painelSino.length > 0);
    await c.captura(path.join(ESTADO, 'sino.png'));
    const t0 = await c.avaliar(`document.documentElement.getAttribute('data-theme')||document.documentElement.className`);
    await c.avaliar(`(function(){var b=[...document.querySelectorAll('button')].find(function(x){return /tema/i.test(x.getAttribute('aria-label')||x.title||x.innerText||'')});if(b)b.click();return 1})()`);
    await esp(500);
    const t1 = await c.avaliar(`document.documentElement.getAttribute('data-theme')||document.documentElement.className`);
    chk('alternar tema muda o tema', t0 !== t1, t0 + ' → ' + t1);
    await c.ir(BASE + '/vendas'); await esp(800);
    const t2 = await c.avaliar(`document.documentElement.getAttribute('data-theme')||document.documentElement.className`);
    chk('tema escolhido persiste em outra página', t2 === t1, t2);
    await c.captura(path.join(ESTADO, 'tema_claro.png'));
    await c.avaliar(`(function(){var b=[...document.querySelectorAll('button')].find(function(x){return /tema/i.test(x.getAttribute('aria-label')||x.title||x.innerText||'')});if(b)b.click();return 1})()`);

    // ---- vendas / leads / ranking: filtros
    console.log('\n--- Filtros');
    for (const rota of ['/vendas?aba=pagas', '/vendas?aba=pendentes', '/vendas?aba=expiradas&periodo=30', '/vendas?bot_id=12168&busca=teste&data_inicio=2026-10-01&data_fim=2026-10-15', '/leads?busca=teste', '/leads?bot_id=12168&status=pago', '/leads?bot_id=99999999', '/ranking?aba=mensal', '/ranking?aba=concurso', '/vendas?pagina=999', '/leads?pagina=-5', "/vendas?busca=%27%20OR%201%3D1--", '/leads?busca=%3Cscript%3Ealert(1)%3C%2Fscript%3E']) {
      const r = await inspecionar(c, rota);
      const p = resumo(r).filter(x => !/ícone sem title/.test(x));
      const xss = await c.avaliar(`document.body.innerHTML.indexOf('<script>alert(1)')>=0`);
      chk('GET ' + rota, p.length === 0 && r.status === 200 && !xss, p.join(' ; ') + (xss ? ' XSS REFLETIDO' : ''));
    }
    // botões do ranking
    await c.ir(BASE + '/ranking'); await esp(1000);
    const rk = await main(c);
    chk('ranking: mostra a campanha e a posição do usuário novo (sem pontuação)', /CAMPANHA DE OUTUBRO/i.test(rk) && /ainda n[ãa]o pontuou/i.test(rk));
    // comunidade
    await c.ir(BASE + '/comunidade'); await esp(1000);
    const com = JSON.parse(await c.avaliar(`JSON.stringify({texto:document.body.innerText.slice(0,300),links:[...document.querySelectorAll('a')].map(function(a){return a.getAttribute('href')+'|'+a.innerText.trim()}).filter(function(x){return x.indexOf('http')>=0}).slice(0,12)})`));
    console.log('   comunidade:', linhas(com.texto).join(' | '), '| links externos:', JSON.stringify(com.links));
    await c.captura(path.join(ESTADO, 'comunidade.png'));
  } catch (e) { console.log('ERRO', e.stack); falha++; }
  finally { try { c.pararDialogo(); } catch (e) {} await c.fechar(); }
  console.log(`\nRESUMO: ${ok} ok, ${falha} falhas`);
})();
