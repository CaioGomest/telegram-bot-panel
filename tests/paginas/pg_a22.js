// ÁREA 4f (09/10/2026): página Vendas (registro de todas as vendas do usuário) — conta de estresse cliente5.
// Uso: PGPW=... OUT=./pg node pg_a22.js
const { lancar, entrar, BASE } = require('./pg_lib.js');
const { area, t, salvar, esp } = require('./pg_t.js');
(async () => {
  const c = await lancar();
  const texto = () => c.avaliar(`document.body.innerText`);
  const linhas = async () => Number(await c.avaliar(`document.querySelectorAll('#tabela-vendas tbody tr').length`));
  const kpi = async (nome) => c.avaliar(`(function(){var l=document.body.innerText.split(String.fromCharCode(10)).map(function(x){return x.trim()}).filter(Boolean); var i=l.findIndex(function(x){return x.toUpperCase()===${JSON.stringify(nome.toUpperCase())}}); return l[i+1]})()`);
  try {
    await entrar(c, 'cliente5@gmial.com', '12345678');
    area('4f. Vendas');
    await t('menu lateral tem "Vendas" e a página abre sem erro', async () => {
      const t0 = Date.now(); await c.ir(BASE + '/vendas'); await esp(1200);
      const ok = (await c.avaliar(`!!document.querySelector('.nav-item[href$="vendas"]')`)) === true && (await texto()).indexOf('Fatal error') < 0;
      return { ok, det: (Date.now() - t0) + ' ms' };
    }, 'vendas');
    await t('cards de resumo e 25 linhas na primeira página', async () => {
      const n = await linhas(); const f = await kpi('Faturado'); const g = await kpi('Pix gerados'); const cv = await kpi('Conversão');
      return { ok: n === 25 && /^R\$/.test(f) && /\d/.test(g) && /%$/.test(cv), det: `linhas=${n} faturado=${f} gerados=${g} conversão=${cv}` };
    }, 'vendas');
    await t('aba "Pagas": só linhas com selo Pago', async () => {
      await c.ir(BASE + '/vendas?aba=pagas'); await esp(900);
      const r = JSON.parse(await c.avaliar(`JSON.stringify({n: document.querySelectorAll('#tabela-vendas tbody tr').length, naoPagas: [...document.querySelectorAll('#tabela-vendas tbody tr')].filter(function(tr){return tr.innerText.indexOf('Aguardando')>=0 || tr.innerText.indexOf('Expirado')>=0}).length})`));
      return { ok: r.n > 0 && r.naoPagas === 0, det: JSON.stringify(r) };
    }, 'vendas');
    await t('aba "Aguardando Pix" e "Expiradas" trazem só o status certo', async () => {
      await c.ir(BASE + '/vendas?aba=pendentes'); await esp(800);
      const a = await c.avaliar(`[...document.querySelectorAll('#tabela-vendas tbody tr')].filter(function(tr){return tr.innerText.indexOf('Pago')>=0 && tr.innerText.indexOf('Aguardando')<0}).length`);
      await c.ir(BASE + '/vendas?aba=expiradas'); await esp(800);
      const b = await c.avaliar(`[...document.querySelectorAll('#tabela-vendas tbody tr')].filter(function(tr){return tr.innerText.indexOf('Aguardando')>=0}).length`);
      return { ok: a === 0 && b === 0, det: `pagas entre pendentes=${a}, pendentes entre expiradas=${b}` };
    }, 'vendas');
    await t('período "Hoje" e datas inválidas não quebram a página', async () => {
      await c.ir(BASE + '/vendas?periodo=hoje'); await esp(700);
      const a = (await texto()).indexOf('Fatal error') < 0;
      await c.ir(BASE + '/vendas?data_inicio=xxxx&data_fim=2026-99-99&pagina=999&aba=zzz'); await esp(700);
      const b = (await texto()).indexOf('Fatal error') < 0 && (await texto()).indexOf('Warning') < 0;
      return { ok: a && b };
    }, 'vendas');
    await t('busca por nome do cliente filtra no banco (todas as páginas)', async () => {
      await c.ir(BASE + '/vendas'); await esp(700);
      const nome = await c.avaliar(`(document.querySelector('#tabela-vendas tbody tr td:nth-child(2) div')||{}).innerText||''`);
      await c.ir(BASE + '/vendas?busca=' + encodeURIComponent(nome.split(' ')[0])); await esp(900);
      const n = await linhas();
      return { ok: n > 0 && (await texto()).indexOf('Nenhuma venda encontrada') < 0, det: `busca="${nome.split(' ')[0]}" → ${n} linhas` };
    }, 'vendas');
    await t('isolamento: bot de outro usuário é ignorado (0 vendas, sem vazar)', async () => {
      await c.ir(BASE + '/vendas?bot_id=1'); await esp(800);
      const txt = await texto();
      return { ok: txt.indexOf('Nenhuma venda') >= 0 && (await linhas()) === 1, det: 'bot_id=1' };
    }, 'vendas');
    await t('lista enxuta (6 colunas) e botão de detalhes abre o modal com os dados e fecha com Esc', async () => {
      await c.ir(BASE + '/vendas'); await esp(900);
      const cols = await c.avaliar(`document.querySelectorAll('#tabela-vendas thead th').length`);
      await c.avaliar(`document.querySelector('.btn-detalhes-venda').click()`); await esp(400);
      const r = JSON.parse(await c.avaliar(`JSON.stringify({aberto: document.getElementById('modal-venda').classList.contains('aberto'), linhas: document.querySelectorAll('#mv-lista .venda-campo').length, titulo: document.getElementById('modal-venda-titulo').innerText})`));
      await c.avaliar(`document.dispatchEvent(new KeyboardEvent('keydown', {key: 'Escape'}))`); await esp(300);
      const fechou = await c.avaliar(`!document.getElementById('modal-venda').classList.contains('aberto')`);
      return { ok: cols === 6 && r.aberto && r.linhas === 8 && /^Venda #/.test(r.titulo) && fechou === true, det: JSON.stringify({ cols, ...r, fechou }) };
    }, 'vendas');
    await t('não há opção de exportar (botão nem rota CSV)', async () => {
      await c.ir(BASE + '/vendas'); await esp(700);
      const botao = await c.avaliar(`document.body.innerText.toLowerCase().indexOf('exportar') >= 0`);
      const csv = await c.avaliar(`fetch('/vendas?export=csv').then(function(r){return r.text()}).then(function(t){return t.slice(0,60)})`);
      return { ok: botao === false && csv.indexOf('<!DOCTYPE') === 0, det: 'resposta: ' + csv.slice(0, 40) };
    }, 'vendas');
    await t('usuário não logado é mandado para o login', async () => {
      await c.enviar('Network.clearBrowserCookies');
      await c.ir(BASE + '/vendas'); await esp(800);
      const p = await c.avaliar('location.pathname');
      return { ok: /login/.test(String(p)), det: String(p) };
    }, 'vendas');
    console.log('erros JS:', JSON.stringify(c.errosJs()));
  } catch (e) { console.log('ERRO', e.message); }
  finally { salvar(); await c.fechar(); process.exit(0); }
})();
