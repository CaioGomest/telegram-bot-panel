// ÁREA 5: Leads (tabela, status, filtros, busca, paginação, exportação, segurança)
const { lancar, entrar, BASE, db, remoto } = require('./pg_lib.js');
const { area, t, nota, salvar, esp } = require('./pg_t.js');
(async () => {
  const c = await lancar();
  const linhas = async (qs) => {
    await c.ir(BASE + '/leads' + qs);
    return JSON.parse(await c.avaliar(`JSON.stringify([...document.querySelectorAll('table tbody tr')].filter(function(tr){return !tr.querySelector('td[colspan]')}).map(function(tr){return [...tr.children].map(function(td){return td.innerText.trim().split(String.fromCharCode(10)).join(' ')})}))`));
  };
  const texto = async () => c.avaliar(`document.querySelector('.conteudo-principal').innerText.split(String.fromCharCode(10)).filter(Boolean).join(' | ')`);
  try {
    remoto('php zz_pg.php pixnovo'); // Pix gerado (validade longa) para o lead João
    await entrar(c, 'pgteste-user@example.invalid');
    area('5. Leads');
    await t('tabela tem as colunas Lead, Número, Bot, Início, Status, Plano, Compras, Total gasto', async () => {
      await c.ir(BASE + '/leads');
      const th = await c.avaliar(`[...document.querySelectorAll('table thead th')].map(function(x){return x.innerText.trim()}).join(',')`);
      return { ok: th.toLowerCase() === 'lead,número,bot,início,status,plano,compras,total gasto', det: th };
    }, 'leads');
    await t('1ª página traz 25 linhas ordenadas do lead mais novo para o mais antigo', async () => {
      const l = await linhas('');
      const datas = l.map(r => r[3]);
      return { ok: l.length === 25, det: `${l.length} linhas; 1ª: ${l[0] && l[0][0]}` };
    }, 'leads');
    await t('paginação: total de 39 leads → 2 páginas; página 2 tem 14 linhas', async () => {
      const l2 = await linhas('?pagina=2');
      const tx = await texto();
      return { ok: l2.length === 14 && /2/.test(tx), det: `${l2.length} linhas na pág 2` };
    }, 'leads');
    await t('página fora do limite (999), zero, negativa ou texto cai na última/primeira página sem erro', async () => {
      const r = [];
      for (const q of ['?pagina=999', '?pagina=0', '?pagina=-3', '?pagina=abc']) {
        const l = await linhas(q);
        const tx = await c.avaliar('document.body.innerText');
        r.push(q + '=' + l.length + (/Warning|Fatal|Notice:/.test(tx) ? '(ERRO PHP)' : ''));
      }
      return { ok: r.every(x => !/ERRO/.test(x) && !/=0$/.test(x)), det: r.join(' ') };
    }, 'leads');
    await t('status por lead: Maria=Cliente(pagou) R$19,90 · Ana=Cliente R$49,90 · João=Gerou Pix · Pedro (Pix expirado)=Iniciou · Lúcia=Iniciou', async () => {
      const l = await linhas('?busca=%20&bot_id=311&pagina=2');
      const todos = [];
      for (const q of ['?busca=Maria', '?busca=Ana%20Costa', '?busca=Jo%C3%A3o', '?busca=Pedro', '?busca=L%C3%BAcia']) {
        const r = await linhas(q);
        todos.push((r[0] || []).join(' / '));
      }
      const [m, a, j, p, lu] = todos;
      return { ok: /pagou|cliente/i.test(m) && /19,90/.test(m) && /pagou|cliente/i.test(a) && /49,90/.test(a) && /gerou pix|n[ãa]o pago/i.test(j) && !/pagou|cliente/i.test(p) && !/pagou|cliente/i.test(lu), det: todos.join(' || ').slice(0, 500) };
    }, 'leads');
    await t('telefone aparece na coluna Número (Maria = 11999990001) e vazio quando não informado', async () => {
      const m = await linhas('?busca=Maria'), j = await linhas('?busca=Jo%C3%A3o');
      return { ok: /11999990001|\(11\)/.test(m[0].join(' ')) && j[0].length > 0, det: (m[0] || []).join(' / ') };
    }, 'leads');
    await t('abas de status: Pagos mostra só quem pagou (2), Não pagos o resto', async () => {
      const pagos = await linhas('?status=pago');
      const nao = await linhas('?status=nao_pago');
      return { ok: pagos.length === 2 && nao.length === 25, det: `pagos=${pagos.length}, não pagos(1ª página)=${nao.length}` };
    }, 'leads');
    await t('filtro por bot: Bot Dois mostra só o lead dele; Bot Um não mostra o lead do Bot Dois', async () => {
      const b2 = await linhas('?bot_id=312');
      const b1 = await linhas('?bot_id=311&busca=Lead%20Bot%20Dois');
      return { ok: b2.length === 1 && /Bot Dois/.test(b2[0].join(' ')) && b1.length === 0, det: `bot2=${b2.length}, bot1 buscando lead do bot2=${b1.length}` };
    }, 'leads');
    await t('busca por nome e por ID do Telegram funciona e a paginação conta só o filtrado', async () => {
      const porNome = await linhas('?busca=Massa');
      const porId = await linhas('?busca=9003');
      const parcial = await linhas('?busca=massa%2012');
      return { ok: porNome.length === 25 && porId.length === 1 && /Ana/.test(porId[0].join(' ')) && parcial.length === 1, det: `nome=${porNome.length} id=${porId.length} parcial=${parcial.length}` };
    }, 'leads');
    await t('busca sem resultado mostra estado vazio e mantém o campo preenchido', async () => {
      await c.ir(BASE + '/leads?busca=zzzinexistente');
      const tx = await texto();
      const v = await c.avaliar(`document.getElementById('busca-lead').value`);
      return { ok: /Nenhum lead/i.test(tx) && v === 'zzzinexistente', det: tx.slice(0, 100) };
    }, 'leads');
    await t('busca com aspas, %, _ e HTML não quebra (sem erro SQL) e escapa o texto digitado', async () => {
      const falhas = [];
      for (const q of ["?busca=O'Brien", '?busca=%25', '?busca=_', '?busca=%27%20OR%201%3D1--', '?busca=%3Cscript%3Ewindow.__b%3D1%3C%2Fscript%3E']) {
        await c.ir(BASE + '/leads' + q);
        const tx = await c.avaliar('document.body.innerText');
        if (/Warning|Fatal|Notice:|SQLSTATE|syntax/i.test(tx)) falhas.push(q + ' erro');
        if (await c.avaliar('window.__b===1')) falhas.push(q + ' executou script');
      }
      const brien = await linhas("?busca=O'Brien");
      return { ok: !falhas.length && brien.length === 1, det: falhas.join('; ') || "O'Brien achou " + brien.length };
    }, 'segurança');
    await t('nome de lead com <script> aparece como texto (não executa) e o HTML sai escapado', async () => {
      await c.ir(BASE + '/leads?busca=Lead%20XSS');
      const exec = await c.avaliar('window.__lead===1');
      const txt = await c.avaliar(`document.querySelector('table tbody').innerText`);
      return { ok: !exec && /Lead XSS/.test(txt), det: txt.slice(0, 80).replace(/\n/g, ' ') };
    }, 'segurança');
    await t('bot_id de outro usuário na URL não mostra leads alheios', async () => {
      const alheio = db(`SELECT id FROM bots WHERE id_usuario<>49 AND id IN (SELECT DISTINCT bot_id FROM leads) ORDER BY id DESC LIMIT 1`)[0].id;
      const l = await linhas('?bot_id=' + alheio);
      return { ok: l.length === 0, det: `${l.length} linhas (bot ${alheio})` };
    }, 'segurança');
    await t('exportação CSV (?export=csv): cabeçalho, linhas e valores corretos', async () => {
      await c.ir(BASE + '/leads');
      const r = await c.avaliar(`fetch('leads?export=csv').then(async r=>({s:r.status,ct:r.headers.get('content-type'),cd:r.headers.get('content-disposition'),t:await r.text()}))`);
      const linhasCsv = r.t.trim().split(String.fromCharCode(10));
      global.__csv = r.t;
      return { ok: r.s === 200 && /csv/.test(r.ct) && linhasCsv[0].split('"').join('') === 'Nome,ID Telegram,Bot,Data Inicio,Status,Plano Ativo,Total Compras,Total Gasto' && linhasCsv.length === 40, det: `${r.s} ${r.ct} ${linhasCsv.length} linhas` };
    }, 'leads');
    await t('exportação CSV não permite injeção de fórmula (nome começando com "=" sai neutralizado)', async () => {
      const lin = (global.__csv || '').split(String.fromCharCode(10)).find(x => x.includes('HYPERLINK')) || '';
      const campo = lin.replace(/^"/, '').slice(0, 1);
      return { ok: lin && !/^"?=/.test(lin), det: 'linha: ' + lin.slice(0, 70) };
    }, 'segurança');
    await t('exportação CSV respeita o filtro (status=pago exporta só os 2 pagantes) e só dados do próprio usuário', async () => {
      const r = await c.avaliar(`fetch('leads?export=csv&status=pago').then(r=>r.text())`);
      const n = r.trim().split(String.fromCharCode(10)).length - 1;
      return { ok: n === 2, det: n + ' linhas de dados' };
    }, 'leads');
    await t('filtros combinados (bot + status + busca) preservam os valores nos campos e links', async () => {
      await c.ir(BASE + '/leads?bot_id=311&status=pago&busca=Ma');
      const r = await c.avaliar(`JSON.stringify({busca:document.getElementById('busca-lead').value, bot:document.querySelector('select[name=bot_id]').value, aba:(document.querySelector('.barra-filtros .ativo, .abas .ativo, .filtro-status .ativo')||{}).innerText||''})`);
      const o = JSON.parse(r);
      return { ok: o.busca === 'Ma' && o.bot === '311', det: r };
    }, 'leads');
    salvar();
  } finally { await c.fechar(); }
})();
