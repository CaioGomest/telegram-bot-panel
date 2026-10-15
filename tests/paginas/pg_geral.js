// Varredura geral (10/10/2026): passa por TODAS as páginas do vendedor (conta de estresse cliente5), do admin (clienteadm) e as públicas,
// em desktop e celular, e lista tudo que deu errado: HTTP >= 400, erro de PHP no texto, erro de JS, requisição falha, imagem quebrada,
// rolagem horizontal da página e botão de ícone sem rótulo. Descobre sozinha os ids (bot, fluxos, campanha) pelas listas.
// Uso: PGPW=... node pg_geral.js        (exit code 1 se algo falhar)
const { lancar, entrar, inspecionar, resumo, BASE } = require('./pg_lib.js');
const esp = (ms) => new Promise(r => setTimeout(r, ms));

const PUBLICAS = ['/login', '/cadastro', '/termos', '/pagina-que-nao-existe'];
const USER_FIXAS = ['/', '/index', '/vendas', '/vendas?aba=pagas', '/vendas?aba=pendentes&periodo=7', '/bots', '/bot', '/fluxos', '/fluxo_basico', '/fluxo', '/leads', '/leads?status=pago',
  '/leads?status=nao_pago', '/links_rastreamento', '/redirecionamento', '/remarketing', '/gateways', '/traqueamento', '/webhooks', '/ranking', '/ranking?aba=mensal', '/configuracao_usuario', '/comunidade'];
const ADMIN_FIXAS = ['/admin/', '/admin/dashboard', '/admin/usuarios', '/admin/transacoes', '/admin/transacoes?status=pago', '/admin/configuracoes', '/admin/comunidade', '/admin/ranking', '/admin/logs',
  '/admin/consultar_venda', '/admin/testes_desempenho'];

let total = 0, problemas = 0;
async function varrer(c, rotulo, rotas) {
  for (const rota of rotas) {
    let r;
    try { r = await inspecionar(c, rota); } catch (e) { console.log(`FALHA   | ${rotulo} | ${rota} | ${e.message}`); problemas++; total++; continue; }
    total++;
    let p = resumo(r);
    // 404 da rota inexistente é o esperado; sem rótulo em ícone só vale reclamar fora das páginas de fluxo (editor tem botões de ferramenta próprios)
    if (rota === '/pagina-que-nao-existe') p = p.filter(x => !/^HTTP 404/.test(x));
    if (rota === '/pagina-que-nao-existe' && r.status !== 404) p.push('devia ser 404, veio ' + r.status);
    const lento = r.ms > 6000 ? ['LENTO ' + r.ms + ' ms'] : [];
    const todos = p.concat(lento);
    if (todos.length) problemas++;
    console.log((todos.length ? 'ATENÇÃO ' : 'ok      ') + `| ${rotulo.padEnd(14)} | ${rota.padEnd(44)} | ${String(r.status).padEnd(3)} | ${String(r.ms).padStart(5)} ms | ${(r.h1 || r.titulo || '').slice(0, 28).padEnd(28)}` + (todos.length ? ' | ' + todos.join(' ; ') : ''));
  }
}
const links = (c, sel) => c.avaliar(`JSON.stringify([...new Set([...document.querySelectorAll(${JSON.stringify(sel)})].map(function(a){return a.getAttribute('href')}))].slice(0,3))`).then(JSON.parse);

(async () => {
  const c = await lancar();
  try {
    for (const [largura, rotuloTela] of [[1500, 'desktop'], [390, 'celular']]) {
      console.log(`\n######## ${rotuloTela} (${largura}px)`);
      await c.enviar('Network.clearBrowserCookies');
      if (largura < 800) await c.enviar('Emulation.setDeviceMetricsOverride', { width: largura, height: 800, deviceScaleFactor: 2, mobile: true });
      else await c.enviar('Emulation.setDeviceMetricsOverride', { width: largura, height: 900, deviceScaleFactor: 1, mobile: false });
      await varrer(c, 'público', PUBLICAS);

      await entrar(c, 'cliente5@gmial.com', '12345678');
      // ids descobertos pelas listas
      const dinamicas = [];
      await c.ir(BASE + '/bots'); await esp(900);
      for (const h of await links(c, 'a[href*="bot?id="], a[href*="bot.php?id="]')) dinamicas.push('/' + h.replace(/^\//, ''));
      await c.ir(BASE + '/fluxos'); await esp(900);
      for (const h of await links(c, 'a[href*="fluxo_basico?id="], a[href*="fluxo?id="]')) dinamicas.push('/' + h.replace(/^\//, ''));
      await c.ir(BASE + '/remarketing'); await esp(900);
      for (const h of await links(c, 'a[href*="remarketing_detalhes"]')) dinamicas.push('/' + h.replace(/^\//, ''));
      await varrer(c, 'vendedor', USER_FIXAS.concat([...new Set(dinamicas)]));

      await c.enviar('Network.clearBrowserCookies');
      await entrar(c, 'clienteadm@gmial.com', '12345678');
      await varrer(c, 'admin', ADMIN_FIXAS);
    }
    console.log(`\nRESUMO: ${total} páginas verificadas, ${problemas} com atenção`);
  } catch (e) { console.log('ERRO', e.message); problemas++; }
  finally { await c.fechar(); process.exit(problemas ? 1 : 0); }
})();
