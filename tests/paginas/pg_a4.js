// ÁREA 4: Meus Fluxos (lista, escolha de modo, importar/exportar, excluir, vínculo com bots)
const fs = require('fs');
const path = require('path');
const { lancar, entrar, BASE, db, autoDialogo, lerToast } = require('./pg_lib.js');
const { area, t, nota, salvar, esp } = require('./pg_t.js');
const TMP = path.join(process.env.OUT || '.', 'tmp'); fs.mkdirSync(TMP, { recursive: true });

async function anexar(c, seletor, arquivo) {
  const doc = await c.enviar('DOM.getDocument', {});
  const q = await c.enviar('DOM.querySelector', { nodeId: doc.result.root.nodeId, selector: seletor });
  await c.enviar('DOM.setFileInputFiles', { nodeId: q.result.nodeId, files: [arquivo] });
}
(async () => {
  const c = await lancar();
  autoDialogo(c, true);
  const api = (acao, corpo, metodo = 'POST') => c.avaliar(`fetch('api.php?action=${acao}',{method:'${metodo}',headers:{'Content-Type':'application/json','X-CSRF-Token':window.CSRF_TOKEN||''},body:${metodo === 'GET' ? 'undefined' : 'JSON.stringify(' + JSON.stringify(corpo) + ')'}}).then(async r=>({http:r.status,j:await r.json().catch(()=>null)}))`);
  try {
    await entrar(c, 'pgteste-user@example.invalid');
    area('4. Meus Fluxos');
    await c.ir(BASE + '/fluxos');
    await c.esperar(`document.querySelectorAll('.js-fluxo-card').length>0`);
    await t('lista: 2 fluxos com nome, descrição, selo do modo e data "Atualizado: dd/mm/aaaa hh:mm"', async () => {
      const r = JSON.parse(await c.avaliar(`JSON.stringify([...document.querySelectorAll('.js-fluxo-card')].map(function(e){return {n:e.querySelector('h3').innerText, m:e.querySelector('.a-fx-top .a-bd').innerText, d:(e.querySelector('p').innerText + String.fromCharCode(10) + e.querySelector('.when').innerText).split(String.fromCharCode(10)).join(' | ')}}))`));
      const g = r.find(x => /Guiado PG/.test(x.n)), v = r.find(x => /Visual PG/.test(x.n));
      return { ok: r.length === 2 && g && /guiado/i.test(g.m) && v && /visual/i.test(v.m) && /Atualizado: \d{2}\/\d{2}\/\d{4} \d{2}:\d{2}/.test(g.d), det: JSON.stringify(r).slice(0, 240) };
    }, 'fluxos');
    await t('botão "+ Novo Fluxo (2/50)" e "Importar Fluxo" à esquerda dele', async () => {
      const r = await c.avaliar(`JSON.stringify({novo:document.getElementById('btn-abrir-escolha-modo').innerText, imp:document.getElementById('btn-importar-fluxo-lista').innerText.trim(), ordem:document.getElementById('btn-importar-fluxo-lista').compareDocumentPosition(document.getElementById('btn-abrir-escolha-modo'))&4})`);
      const o = JSON.parse(r);
      return { ok: /2\/50/.test(o.novo) && /Importar/.test(o.imp) && o.ordem === 4, det: r };
    }, 'fluxos');
    await t('lápis leva ao editor certo de cada modo (Guiado → fluxo_basico, Visual → fluxo)', async () => {
      const r = JSON.parse(await c.avaliar(`JSON.stringify([...document.querySelectorAll('.js-fluxo-card')].map(function(e){return [e.querySelector('h3').innerText, e.querySelector('a.a-ib[title="Editar fluxo"]').getAttribute('href')]}))`));
      const g = r.find(x => /Guiado/.test(x[0])), v = r.find(x => /Visual/.test(x[0]));
      return { ok: /^fluxo_basico\?id=\d+/.test(g[1]) && /^fluxo\?id=\d+/.test(v[1]), det: JSON.stringify(r) };
    }, 'fluxos');
    await t('"+ Novo Fluxo" abre o modal de escolha (Guiado / Editor Visual) e fecha pelo X e clicando fora', async () => {
      await c.avaliar(`document.getElementById('btn-abrir-escolha-modo').click(); 1`);
      const aberto = await c.avaliar(`document.getElementById('modal-escolha-modo').classList.contains('aberto')`);
      const links = await c.avaliar(`JSON.stringify([...document.querySelectorAll('#modal-escolha-modo a')].map(function(a){return a.getAttribute('href')}))`);
      await c.avaliar(`document.getElementById('btn-fechar-escolha-modo').click(); 1`);
      const f1 = await c.avaliar(`!document.getElementById('modal-escolha-modo').classList.contains('aberto')`);
      await c.avaliar(`document.getElementById('btn-abrir-escolha-modo').click(); document.getElementById('modal-escolha-modo').click(); 1`);
      const f2 = await c.avaliar(`!document.getElementById('modal-escolha-modo').classList.contains('aberto')`);
      return { ok: aberto && f1 && f2 && /fluxo_basico/.test(links) && /"fluxo"/.test(links), det: links };
    }, 'fluxos');

    area('4b. Importar e exportar');
    const exp = await api('exportar_fluxo&id=472', null, 'GET');
    nota('exportar_fluxo devolve o envelope', JSON.stringify(Object.keys((exp.j || {}).fluxo || {})));
    await t('importar: arquivo que não é JSON → "Arquivo JSON inválido."', async () => {
      const f = path.join(TMP, 'lixo.json'); fs.writeFileSync(f, 'isto nao e json {{{');
      await anexar(c, '#arquivo-importar-fluxo-lista', f);
      const x = await lerToast(c);
      return { ok: /inválido/i.test(x), det: x };
    }, 'fluxos');
    await t('importar: JSON válido mas que não é de fluxo → "não é um fluxo exportado"', async () => {
      const f = path.join(TMP, 'outro.json'); fs.writeFileSync(f, JSON.stringify({ a: 1 }));
      await esp(3200);
      await anexar(c, '#arquivo-importar-fluxo-lista', f);
      const x = await lerToast(c);
      return { ok: /não é um fluxo/i.test(x), det: x };
    }, 'fluxos');
    await t('importar: estrutura do fluxo inválida é recusada pelo servidor (422) sem criar registro', async () => {
      const antes = db(`SELECT COUNT(*) n FROM fluxos WHERE id_usuario=49`)[0].n;
      const r = await api('importar_fluxo', { nome: 'X', modo: 'avancado', dados_fluxograma: { operators: 1 } });
      const depois = db(`SELECT COUNT(*) n FROM fluxos WHERE id_usuario=49`)[0].n;
      return { ok: r.http === 422 && antes === depois, det: JSON.stringify(r.j).slice(0, 100) };
    }, 'fluxos');
    await t('importar: arquivo exportado do próprio sistema recria o fluxo (mesmo nome, modo e conteúdo) e o contador sobe', async () => {
      await c.ir(BASE + '/fluxos');
      const e = (await api('exportar_fluxo&id=472', null, 'GET')).j.fluxo;
      const f = path.join(TMP, 'visual.json'); fs.writeFileSync(f, JSON.stringify(e));
      const antes = db(`SELECT COUNT(*) n FROM fluxos WHERE id_usuario=49`)[0].n;
      await anexar(c, '#arquivo-importar-fluxo-lista', f);
      const x = await lerToast(c);
      await esp(800);
      const linhas = db(`SELECT id, nome, modo, dados_fluxograma FROM fluxos WHERE id_usuario=49 AND nome='${e.nome.replace(/'/g, "''")}' ORDER BY id DESC`);
      const depois = db(`SELECT COUNT(*) n FROM fluxos WHERE id_usuario=49`)[0].n;
      const cont = await c.avaliar(`document.getElementById('btn-abrir-escolha-modo').innerText`);
      global.__importado = linhas[0] && linhas[0].id;
      return { ok: /importado/i.test(x) && depois === antes + 1 && linhas[0].modo === 'avancado' && linhas[0].dados_fluxograma.length > 5 && cont.indexOf('(' + depois + '/50)') !== -1, det: `${x} | ${antes}→${depois} | ${cont}` };
    }, 'fluxos');
    await t('importar com nome contendo HTML/script: o nome aparece escapado na lista (sem executar)', async () => {
      const r = await api('importar_fluxo', { nome: '<img src=x onerror=window.__pwn=1>Fluxo XSS', modo: 'avancado', dados_fluxograma: { operators: {}, links: {} } });
      await c.ir(BASE + '/fluxos');
      await esp(1200);
      const pwn = await c.avaliar(`window.__pwn===1`);
      const imgs = await c.avaliar(`document.querySelectorAll('.js-fluxo-card img').length`);
      const nome = db(`SELECT id, nome FROM fluxos WHERE id_usuario=49 AND nome LIKE '%XSS%'`)[0];
      global.__xss = nome && nome.id;
      return { ok: !pwn && imgs === 0, det: 'nome salvo: ' + (nome && nome.nome) };
    }, 'segurança');

    area('4c. Excluir e vínculo com bots');
    await t('excluir fluxo: "Cancelar" mantém, confirmar remove do banco e da lista', async () => {
      await c.ir(BASE + '/fluxos');
      await c.esperar(`document.querySelectorAll('.js-fluxo-card').length>0`);
      const id = global.__xss;
      c.aceitar = false;
      await c.avaliar(`document.querySelector('.btn-excluir-fluxo[data-id="${id}"]').click(); 1`);
      await esp(1000);
      const ainda = db(`SELECT COUNT(*) n FROM fluxos WHERE id=${id}`)[0].n;
      c.aceitar = true;
      await c.avaliar(`document.querySelector('.btn-excluir-fluxo[data-id="${id}"]').click(); 1`);
      await esp(2000);
      const some = db(`SELECT COUNT(*) n FROM fluxos WHERE id=${id}`)[0].n;
      const naLista = await c.avaliar(`!!document.querySelector('.btn-excluir-fluxo[data-id="${id}"]')`);
      return { ok: ainda === 1 && some === 0 && !naLista, det: `cancelar→${ainda}, confirmar→${some}` };
    }, 'fluxos');
    await t('vincular bot ao fluxo pela API: ok para fluxo e bot do usuário; excluir o fluxo desvincula o bot', async () => {
      const id = global.__importado;
      const v = await api('vincular_bot_fluxo', { id_bot: 312, id_fluxo: Number(id) });
      const ligado = db(`SELECT id_fluxo_conectado f FROM bots WHERE id=312`)[0].f;
      await c.avaliar(`document.querySelector('.btn-excluir-fluxo[data-id="${id}"]').click(); 1`);
      await esp(2000);
      const depois = db(`SELECT id_fluxo_conectado f FROM bots WHERE id=312`)[0].f;
      return { ok: v.j.sucesso && Number(ligado) === Number(id) && depois === null, det: `ligado=${ligado}, após excluir=${depois}` };
    }, 'fluxos');
    await t('vincular com fluxo de OUTRO usuário é recusado (404) e não altera o bot', async () => {
      const alheio = db(`SELECT id FROM fluxos WHERE id_usuario<>49 ORDER BY id DESC LIMIT 1`)[0].id;
      const r = await api('vincular_bot_fluxo', { id_bot: 312, id_fluxo: Number(alheio) });
      const f = db(`SELECT id_fluxo_conectado f FROM bots WHERE id=312`)[0].f;
      return { ok: r.http === 404 && f === null, det: `http ${r.http}, bot312→${f}` };
    }, 'segurança');
    await t('excluir fluxo de OUTRO usuário é recusado e o fluxo continua existindo', async () => {
      const alheio = db(`SELECT id FROM fluxos WHERE id_usuario<>49 ORDER BY id DESC LIMIT 1`)[0].id;
      const r = await api('excluir_fluxo', { id: Number(alheio) });
      const n = db(`SELECT COUNT(*) n FROM fluxos WHERE id=${alheio}`)[0].n;
      return { ok: r.http === 404 && n === 1, det: `http ${r.http}` };
    }, 'segurança');
    await t('obter/exportar fluxo de OUTRO usuário é recusado (nada do conteúdo vaza)', async () => {
      const alheio = db(`SELECT id FROM fluxos WHERE id_usuario<>49 ORDER BY id DESC LIMIT 1`)[0].id;
      const a = await api('obter_fluxo&id=' + alheio, null, 'GET');
      const b = await api('exportar_fluxo&id=' + alheio, null, 'GET');
      return { ok: a.http >= 400 && b.http >= 400 && !(a.j && a.j.fluxo) && !(b.j && b.j.fluxo), det: `obter ${a.http}, exportar ${b.http}` };
    }, 'segurança');
    await t('salvar_fluxo: editar fluxo de OUTRO usuário não altera nada (404)', async () => {
      const alheio = db(`SELECT id, nome FROM fluxos WHERE id_usuario<>49 ORDER BY id DESC LIMIT 1`)[0];
      const r = await api('salvar_fluxo', { id: Number(alheio.id), nome: 'SEQUESTRADO', modo: 'avancado', dados_fluxograma: { operators: {}, links: {} } });
      const nome = db(`SELECT nome FROM fluxos WHERE id=${alheio.id}`)[0].nome;
      return { ok: nome === alheio.nome && nome !== 'SEQUESTRADO', det: `http ${r.http}` };
    }, 'segurança');
    await t('nome do fluxo vazio/enorme: salva com padrão/limite de 120 caracteres, sem erro', async () => {
      const r1 = await api('salvar_fluxo', { nome: 'A'.repeat(300), modo: 'avancado', dados_fluxograma: { operators: {}, links: {} } });
      const tam = db(`SELECT LENGTH(nome) n FROM fluxos WHERE id=${r1.j.fluxo.id}`)[0].n;
      await api('excluir_fluxo', { id: r1.j.fluxo.id });
      return { ok: r1.j.sucesso && tam <= 120, det: `tamanho salvo ${tam}` };
    }, 'fluxos');
    await t('lista de fluxos não deixa sobrar lixo do teste (contagem volta a 2)', async () => {
      const n = db(`SELECT COUNT(*) n FROM fluxos WHERE id_usuario=49`)[0].n;
      return { ok: n === 2, det: 'fluxos=' + n };
    }, 'fluxos');
    salvar();
    c.pararDialogo();
  } finally { await c.fechar(); }
})();
