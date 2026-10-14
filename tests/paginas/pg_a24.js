// ÁREA 4h (10/10/2026): bloco "Vendas" no rodapé do dashboard (mesmo filtro dos cards) — conta de estresse cliente5.
// Uso: PGPW=... OUT=./pg node pg_a24.js
const { lancar, entrar, BASE } = require('./pg_lib.js');
const { area, t, salvar, esp } = require('./pg_t.js');
(async () => {
  const c = await lancar();
  try {
    await entrar(c, 'cliente5@gmial.com', '12345678');
    area('4h. Vendas no dashboard');
    const ler = async () => JSON.parse(await c.avaliar(`JSON.stringify((function(){
      var p = document.getElementById('vendas-dashboard'); if (!p) return {existe: false};
      var cab = p.querySelector('.painel-cabecalho .texto-suave'); var linhas = [].slice.call(p.querySelectorAll('tbody tr'));
      var vazio = linhas.length === 1 && linhas[0].innerText.indexOf('Nenhuma venda paga') >= 0;
      var link = p.querySelector('.mini-ranking-rodape a');
      return {existe: true, cab: cab ? cab.innerText : '', n: vazio ? 0 : linhas.length, vazio: vazio, href: link ? link.getAttribute('href') : '', erro: document.body.innerText.indexOf('Fatal') >= 0 || document.body.innerText.indexOf('Warning:') >= 0};
    })())`));
    const kpiAprov = async () => c.avaliar(`(function(){var x=document.body.innerText.match(/(\\d[\\d.]*) aprova/); return x ? Number(x[1].replace(/\\./g,'')) : -1})()`);
    for (const [per, rotulo] of [['hoje', 'Hoje'], ['ontem', 'Ontem'], ['7dias', 'Últimos 7 dias'], ['30dias', 'Últimos 30 dias'], ['total', 'Todo o período']]) {
      await t(`período "${per}": bloco existe, rótulo certo, até 10 linhas e link "Ver todas" com o mesmo recorte`, async () => {
        await c.ir(BASE + '/index?periodo=' + per); await esp(1500);
        const r = await ler();
        const okLink = /^vendas\?aba=pagas/.test(r.href) && (per === 'total' ? r.href.indexOf('data_inicio') < 0 : r.href.indexOf('data_inicio=') > 0);
        return { ok: r.existe && !r.erro && r.cab.indexOf(rotulo) === 0 && r.n <= 10 && okLink, det: JSON.stringify(r).slice(0, 220) };
      }, 'index');
    }
    await t('as linhas são só vendas pagas e batem com a página Vendas (aba Pagas, mesmo período)', async () => {
      await c.ir(BASE + '/index?periodo=total'); await esp(1500);
      const r = await ler();
      const primeiras = await c.avaliar(`[].slice.call(document.querySelectorAll('#tabela-vendas-dashboard tbody tr')).slice(0,3).map(function(tr){return tr.cells[1].innerText.split(String.fromCharCode(10))[0]}).join('|')`);
      await c.ir(BASE + '/' + r.href); await esp(1500);
      const naPagina = await c.avaliar(`[].slice.call(document.querySelectorAll('#tabela-vendas tbody tr')).slice(0,3).map(function(tr){return tr.cells[1].innerText.split(String.fromCharCode(10))[0]}).join('|')`);
      return { ok: primeiras === naPagina && primeiras.length > 0, det: primeiras + ' == ' + naPagina };
    }, 'index');
    await t('contagem do cabeçalho do bloco = aprovações do card (7 dias)', async () => {
      await c.ir(BASE + '/index?periodo=7dias'); await esp(1500);
      const r = await ler(); const aprov = await kpiAprov();
      const n = Number((r.cab.match(/(\d[\d.]*) venda/) || [])[1].replace(/\./g, ''));
      return { ok: aprov === n, det: `card=${aprov} bloco=${n}` };
    }, 'index');
    await t('período personalizado e datas inválidas não quebram', async () => {
      await c.ir(BASE + '/index?periodo=personalizado&data_inicio=2026-09-01&data_fim=2026-10-31'); await esp(1500);
      const a = await ler();
      await c.ir(BASE + '/index?periodo=personalizado&data_inicio=xx&data_fim=yy'); await esp(1500);
      const b = await ler();
      return { ok: a.existe && !a.erro && /\d\d\/\d\d a \d\d\/\d\d/.test(a.cab) && b.existe && !b.erro, det: a.cab + ' | ' + b.cab };
    }, 'index');
    await t('filtro por bot: o bloco acompanha e o link leva o bot_id', async () => {
      await c.ir(BASE + '/index?periodo=total'); await esp(1200);
      const bot = await c.avaliar(`(function(){var s=document.querySelector('select[name=bot_id]'); if(!s) return ''; var o=[].slice.call(s.options).find(function(x){return x.value && x.value!=='todos'}); return o ? o.value : ''})()`);
      if (!bot) return { ok: true, det: 'conta sem filtro de bot na tela (1 bot)' };
      await c.ir(BASE + '/index?periodo=total&bot_id=' + bot); await esp(1500);
      const r = await ler();
      return { ok: r.existe && r.href.indexOf('bot_id=' + bot) > 0, det: r.href };
    }, 'index');
    await t('dashboard continua abrindo sem erro de JS e a paginação da Atividade segue funcionando', async () => {
      await c.ir(BASE + '/index'); await esp(1500);
      const r = await c.avaliar(`document.querySelectorAll('.item-atividade').length`);
      return { ok: r > 0 && c.errosJs().length === 0, det: 'itens=' + r + ' errosJs=' + JSON.stringify(c.errosJs()).slice(0, 80) };
    }, 'index');
  } catch (e) { console.log('ERRO', e.message); }
  finally { salvar(); await c.fechar(); process.exit(0); }
})();
