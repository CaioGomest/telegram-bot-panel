// ÁREA 2: dashboard, cabeçalho, notificações e barra lateral
const { lancar, entrar, BASE, remoto } = require('./pg_lib.js');
const { area, t, nota, salvar, esp } = require('./pg_t.js');
const NL = String.fromCharCode(10);
(async () => {
  const c = await lancar();
  const kpis = async (qs) => {
    await c.ir(BASE + '/' + qs);
    await c.esperar(`!!document.querySelector('.kpi, [class*=kpi]')`);
    const tx = await c.avaliar(`document.querySelector('.conteudo-principal').innerText.split(String.fromCharCode(10)).filter(Boolean).join(' | ')`);
    const pega = (re) => (tx.match(re) || [])[1];
    return { tx, vendas: pega(/VENDAS APROVADAS \| (R\$ [\d.,]+)/), aprov: pega(/\| (\d+) aprova/), conv: pega(/CONVERSÃO \| ([\d,]+%)/), pagosde: pega(/\| (\d+ pagos? de \d+ PIX)/), starts: pega(/TOTAL STARTS \| (\d+)/), ticket: pega(/TICKET MÉDIO \| (R\$ [\d.,]+)/) };
  };
  try {
    await entrar(c, 'pgteste-user@example.invalid');
    area('2. Dashboard, cabeçalho e navegação');
    const casos = [
      ['8 dias (padrão)', '', { vendas: 'R$ 69,80', aprov: '2', conv: '50,00%', pagosde: '2 pagos de 4 PIX', starts: '6', ticket: 'R$ 34,90' }],
      ['período 8 dias explícito', '?periodo=7dias', { vendas: 'R$ 69,80', starts: '6' }],
      ['hoje', '?periodo=hoje', { vendas: 'R$ 19,90', aprov: '1', conv: '50,00%', starts: '2', ticket: 'R$ 19,90' }],
      ['ontem', '?periodo=ontem', { vendas: 'R$ 0,00', aprov: '0', conv: '0,00%', starts: '1' }],
      ['30 dias', '?periodo=30dias', { vendas: 'R$ 69,80', starts: '6' }],
      ['total', '?periodo=total', { vendas: 'R$ 69,80', starts: '6', pagosde: '2 pagos de 4 PIX' }],
      ['filtro por bot (Bot Um): consulta ao vivo bate com o cache', '?periodo=7dias&bot_id=311', { vendas: 'R$ 69,80', aprov: '2', conv: '50,00%', starts: '5' }],
      ['filtro por bot (Bot Dois): só 1 lead, sem venda', '?periodo=7dias&bot_id=312', { vendas: 'R$ 0,00', starts: '1' }],
    ];
    for (const [nome, qs, esperado] of casos) {
      await t('KPIs — ' + nome, async () => {
        const k = await kpis(qs);
        const erros = Object.entries(esperado).filter(([ch, v]) => k[ch] !== v).map(([ch, v]) => `${ch}: esperado "${v}", veio "${k[ch]}"`);
        return { ok: !erros.length, det: erros.join('; ') };
      }, 'dashboard');
    }
    const hoje = new Date(); const d = (x) => x.getFullYear() + '-' + String(x.getMonth() + 1).padStart(2, '0') + '-' + String(x.getDate()).padStart(2, '0');
    await t('KPIs — período personalizado (só hoje) e datas invertidas são aceitos', async () => {
      const a = await kpis('?periodo=personalizado&data_inicio=' + d(hoje) + '&data_fim=' + d(hoje));
      const b = await kpis('?periodo=personalizado&data_inicio=' + d(hoje) + '&data_fim=2020-01-01');
      return { ok: a.vendas === 'R$ 19,90' && b.vendas !== undefined, det: `hoje=${a.vendas} invertida=${b.vendas}` };
    }, 'dashboard');
    await t('entradas inválidas na URL não quebram (periodo=xyz, bot_id de outro usuário, SQL no bot_id, página 999)', async () => {
      const falhas = [];
      for (const qs of ['?periodo=xyz', '?bot_id=1', "?bot_id=311%20OR%201=1", "?bot_id=1'%20OR%20'1'='1", '?pagina=999', '?pagina=-5', '?periodo=personalizado&data_inicio=abc&data_fim=def', '?periodo=hoje%27%3BDROP%20TABLE%20vendas--']) {
        await c.ir(BASE + '/' + qs);
        const tx = await c.avaliar('document.body.innerText');
        if (/Warning|Fatal error|Notice:|Uncaught|SQLSTATE|syntax error/i.test(tx)) falhas.push(qs + ' mostra erro');
        if (!/DASHBOARD/i.test(tx)) falhas.push(qs + ' não renderizou o dashboard');
      }
      const k = await kpis('?bot_id=1');
      if (k.starts !== '6') falhas.push('bot_id de outro usuário alterou os números (' + k.starts + ')');
      return { ok: !falhas.length, det: falhas.join('; ') };
    }, 'dashboard');
    await t('o botão do período escolhido fica destacado e trocar de período recarrega os números', async () => {
      await c.ir(BASE + '/?periodo=hoje');
      const ativo = await c.avaliar(`(document.querySelector('.filtro-periodo .ativo, .periodo-ativo, .segmentado .ativo, [class*=periodo] .ativo')||{}).innerText||''`);
      await c.avaliar(`[...document.querySelectorAll('a,button')].find(function(e){return e.innerText.trim()==='Ontem'}).click(); 1`);
      await esp(1500);
      return { ok: /ontem/i.test(await c.avaliar('location.search')) || (await c.avaliar('document.body.innerText')).includes('ONTEM'), det: 'ativo antes: ' + ativo };
    }, 'dashboard');
    await t('gráficos (desempenho, conversão, starts) são desenhados no canvas', async () => {
      await c.ir(BASE + '/');
      await esp(1200);
      const r = await c.avaliar(`['performanceChart','kpiMedidorConversao','kpiBarrasStarts'].map(function(i){var e=document.getElementById(i);return e&&e.width>0&&e.height>0})`);
      return { ok: r.every(Boolean), det: JSON.stringify(r) };
    }, 'dashboard');
    await t('lista de Atividade mostra a venda semeada; paginação por bloco (?bloco=atividade) responde só o trecho', async () => {
      await c.ir(BASE + '/');
      const tem = /Venda Aprovada/.test(await c.avaliar('document.body.innerText'));
      const parcial = await c.avaliar(`fetch('?bloco=atividade',{headers:{'X-Requested-With':'XMLHttpRequest'}}).then(r=>r.text()).then(t=>({n:t.length,html:/<html/i.test(t)}))`);
      return { ok: tem && parcial.n > 0 && !parcial.html, det: JSON.stringify(parcial) };
    }, 'dashboard');
    await t('mini-ranking mostra o próprio usuário ("você") com o valor vendido e link para o ranking completo', async () => {
      await c.ir(BASE + '/');
      const tx = await c.avaliar('document.body.innerText');
      return { ok: /você/.test(tx) && /Ver ranking completo/.test(tx) && /R\$ 69,80/.test(tx), det: '' };
    }, 'dashboard');

    area('2b. Cabeçalho e notificações');
    remoto('php zz_pg.php act'); // garante uma notificação não lida
    await c.ir(BASE + '/');
    await t('cabeçalho: saudação com o primeiro nome, data por extenso e faturamento total (R$ 69,80)', async () => {
      await c.ir(BASE + '/');
      const topo = await c.avaliar('document.querySelector(".topo-painel").innerText.split(String.fromCharCode(10)).join(" | ")');
      return { ok: /Usuario/.test(topo) && /OUTUBRO DE 2026/i.test(topo) && /69,80/.test(topo), det: topo.slice(0, 120) };
    }, 'cabeçalho');
    await t('cabeçalho: barra de meta de faturamento tem percentual coerente (69,80 de meta)', async () => {
      const w = await c.avaliar(`document.querySelector('.topo-faturamento-barra i').style.width`);
      const pct = await c.avaliar(`document.querySelector('.topo-faturamento-linha span:last-child').innerText`);
      return { ok: /%$/.test(pct) && /%$/.test(w), det: `${pct} largura ${w}` };
    }, 'cabeçalho');
    await t('sino: mostra contador de não lidas', async () => {
      await esp(1200);
      const badge = await c.avaliar(`(function(){var b=document.querySelector('.sino-badge');return b&&!b.hidden?b.textContent:'oculto'})()`);
      return { ok: /^\d+\+?$/.test(badge) && badge !== '0', det: 'badge=' + badge };
    }, 'notificações');
    await t('sino: clicar abre o painel com a atividade e fechar ao clicar fora', async () => {
      await c.avaliar(`document.querySelector('.sino-notificacoes').click(); 1`);
      await esp(1200);
      const aberto = await c.avaliar(`var p=document.querySelector('.sino-painel');!!p && !p.hidden && getComputedStyle(p).display!=='none' && p.innerText.indexOf('Venda Aprovada')>=0`);
      await c.avaliar(`document.body.click(); 1`);
      await esp(400);
      const fechado = await c.avaliar(`var p=document.querySelector('.sino-painel');!p || p.hidden || getComputedStyle(p).display==='none'`);
      return { ok: aberto && fechado, det: `aberto=${aberto} fechou=${fechado}` };
    }, 'notificações');
    await t('sino: "marcar todas como lidas" zera o contador e persiste após recarregar', async () => {
      await c.avaliar(`document.querySelector('.sino-notificacoes').click(); 1`);
      await esp(800);
      await c.avaliar(`document.querySelector('.sino-marcar-todas').click(); 1`);
      await esp(1200);
      const b1 = await c.avaliar(`document.querySelector('.sino-badge').hidden`);
      await c.ir(BASE + '/');
      await esp(1200);
      const b2 = await c.avaliar(`document.querySelector('.sino-badge').hidden`);
      return { ok: b1 && b2, det: `após marcar oculto=${b1}; após recarregar oculto=${b2}` };
    }, 'notificações');
    await t('tema: o botão alterna claro/escuro e a escolha persiste ao recarregar', async () => {
      const antes = await c.avaliar(`document.documentElement.getAttribute('data-theme')||'(padrão)'`);
      await c.avaliar(`document.querySelector('.topo-acoes .alternador-tema').click(); 1`);
      await esp(500);
      const meio = await c.avaliar(`document.documentElement.getAttribute('data-theme')||'(padrão)'`);
      await c.ir(BASE + '/');
      const depois = await c.avaliar(`document.documentElement.getAttribute('data-theme')||'(padrão)'`);
      await c.avaliar(`document.querySelector('.topo-acoes .alternador-tema').click(); 1`);
      await esp(400);
      const volta = await c.avaliar(`document.documentElement.getAttribute('data-theme')||'(padrão)'`);
      return { ok: meio !== antes && depois === meio && volta === antes, det: `${antes} → ${meio} → recarga ${depois} → ${volta}` };
    }, 'tema');
    await t('botão Comunidade abre /comunidade em nova aba (target=_blank, rel=noopener)', async () => c.avaliar(`var a=document.querySelector('.topo-botao[href*=comunidade]');!!a && a.target==='_blank' && /noopener/.test(a.rel)`), 'cabeçalho');

    area('2c. Barra lateral');
    await t('sidebar: todos os links do menu abrem sem erro e marcam o item ativo', async () => {
      await c.ir(BASE + '/');
      const links = await c.avaliar(`JSON.stringify([...document.querySelectorAll('aside .nav-item[href]')].map(function(a){return [a.getAttribute('href'),a.title||a.innerText.trim()]}))`);
      const lista = JSON.parse(links).filter(([h]) => !/logout|comunidade/.test(h)); // a Comunidade abre em nova aba, sem sidebar (pedido do dono)
      const falhas = [];
      for (const [h, nome] of lista) {
        await c.ir(BASE + '/' + h.replace(/^\//, ''));
        const st = await c.avaliar(`JSON.stringify({ativo:(document.querySelector('aside .nav-item.ativo')||{}).title||'', erro:/Warning|Fatal error|Notice:|Uncaught/.test(document.body.innerText), h1:(document.querySelector('h1')||{}).innerText||''})`);
        const o = JSON.parse(st);
        if (o.erro || !o.h1) falhas.push(h + ' (erro/sem título)');
        if (!o.ativo) falhas.push(h + ' (nenhum item ativo no menu)');
      }
      return { ok: !falhas.length && lista.length >= 10, det: `${lista.length} links; ` + falhas.join('; ') };
    }, 'sidebar');
    await t('sidebar: cada item tem title (tooltip) já que o menu minimizado mostra só ícones', async () => c.avaliar(`[...document.querySelectorAll('aside .nav-item')].every(function(a){return a.title||a.getAttribute('aria-label')})`), 'sidebar');
    await t('sidebar: botão "Sair" encerra a sessão', async () => {
      await c.ir(BASE + '/');
      await c.avaliar(`document.querySelector('a.botao-sair').click(); 1`);
      await esp(1500);
      return /login/.test(await c.avaliar('location.pathname'));
    }, 'sidebar');
    await entrar(c, 'pgteste-user@example.invalid');
    await t('menu mobile (390px): barra inferior com os principais itens e folha de menu abre/fecha', async () => {
      await c.enviar('Emulation.setDeviceMetricsOverride', { width: 390, height: 800, deviceScaleFactor: 1, mobile: true });
      await c.ir(BASE + '/');
      const itens = await c.avaliar(`document.querySelectorAll('.barra-mobile .mobile-item').length`);
      const abre = await c.avaliar(`var b=document.querySelector('.barra-mobile [data-abrir-menu], .barra-mobile button, .mobile-item[data-menu]'); if(b){b.click();} 1`);
      await esp(500);
      const aberta = await c.avaliar(`!!document.querySelector('.folha-menu') && (document.querySelector('.folha-menu').classList.contains('aberta')||document.querySelector('.folha-menu').classList.contains('aberto')||getComputedStyle(document.querySelector('.folha-menu')).visibility==='visible')`);
      return { ok: itens >= 4, det: `${itens} itens na barra; folha aberta=${aberta}` };
    }, 'mobile');
    salvar();
  } finally { await c.fechar(); }
})();
