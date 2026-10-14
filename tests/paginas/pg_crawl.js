// Passa por TODAS as páginas do perfil e lista o que deu errado. Uso: node pg_crawl.js user|admin [largura]
const { lancar, entrar, inspecionar, resumo } = require('./pg_lib.js');
const perfil = process.argv[2] || 'user';
const largura = parseInt(process.argv[3] || '1500', 10);
const IDS = JSON.parse(process.env.IDS || '{}');
const email = perfil === 'admin' ? 'pgteste-admin@example.invalid' : 'pgteste-user@example.invalid';
const paginasUser = ['/', '/bots', `/bot?id=${IDS.bot1}`, '/bot', '/fluxos', `/fluxo_basico?id=${IDS.fluxo_guiado}`, '/fluxo_basico', `/fluxo?id=${IDS.fluxo_visual}`, '/fluxo', '/leads', '/links_rastreamento', '/redirecionamento', '/remarketing', '/remarketing_detalhes?campanha=' + (IDS.campanha || 1), '/gateways', '/traqueamento', '/webhooks', '/comunidade', '/ranking', '/configuracao_usuario', '/termos', '/pagina-que-nao-existe'];
const paginasAdmin = ['/admin/', '/admin/dashboard', '/admin/usuarios', '/admin/transacoes', '/admin/configuracoes', '/admin/comunidade', '/admin/ranking', '/admin/logs', '/admin/checklist_testes', '/admin/consultar_venda', '/admin/testes_desempenho'];
(async () => {
  const c = await lancar();
  const OUT = process.env.OUT || '.';
  try {
    console.log('login ->', await entrar(c, email));
    if (largura < 800) await c.enviar('Emulation.setDeviceMetricsOverride', { width: largura, height: 800, deviceScaleFactor: 1, mobile: true });
    for (const rota of (perfil === 'admin' ? paginasAdmin : paginasUser)) {
      const r = await inspecionar(c, rota);
      const p = resumo(r);
      console.log((p.length ? 'ATENÇÃO' : 'ok     ') + ' | ' + rota.padEnd(46) + ' | ' + String(r.status).padEnd(3) + ' | ' + (r.h1 || r.titulo).slice(0, 34).padEnd(34) + ' | ' + r.ms + 'ms' + (p.length ? ' | ' + p.join(' ; ') : ''));
      await c.captura(`${OUT}/${perfil}_${largura}_${rota.replace(/[^a-z0-9]+/gi, '_')}.png`);
    }
  } finally { await c.fechar(); }
})();
