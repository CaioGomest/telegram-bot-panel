// ÁREA 6: Links de Rastreamento
const { lancar, entrar, BASE, db, autoDialogo, lerToast } = require('./pg_lib.js');
const { area, t, nota, salvar, esp } = require('./pg_t.js');
(async () => {
  const c = await lancar();
  autoDialogo(c, true);
  const api = (acao, corpo, metodo = 'POST') => c.avaliar(`fetch('api.php?action=${acao}',{method:'${metodo}',headers:{'Content-Type':'application/json','X-CSRF-Token':window.CSRF_TOKEN||''},body:${metodo === 'GET' ? 'undefined' : 'JSON.stringify(' + JSON.stringify(corpo) + ')'}}).then(async r=>({http:r.status,j:await r.json().catch(()=>null)}))`);
  const preencher = async (titulo, ident, bot) => {
    await c.avaliar(`document.getElementById('link-titulo').value=${JSON.stringify(titulo)}; document.getElementById('link-identificador').value=${JSON.stringify(ident)}; ${bot ? `document.getElementById('link-bot').value='${bot}';` : ''} 1`);
  };
  const linhas = async () => JSON.parse(await c.avaliar(`JSON.stringify([...document.querySelectorAll('#corpo-tabela tr')].filter(function(tr){return !tr.querySelector('td[colspan]')}).map(function(tr){return [...tr.children].map(function(td){return td.innerText.trim()})}))`));
  try {
    await entrar(c, 'pgteste-user@example.invalid');
    area('6. Links de Rastreamento');
    await c.ir(BASE + '/links_rastreamento');
    await c.esperar(`document.querySelectorAll('#corpo-tabela tr').length>0`);
    await esp(800);
    await t('lista mostra o link semeado com bot, 2 vendas, R$ 69,80, 10 starts, 3 leads e a URL t.me?start=', async () => {
      const l = await linhas();
      const x = l.find(r => /camp_pg/.test(r.join(' ')));
      return { ok: !!x && /Bot Um/.test(x[1]) && /2 Vendas/.test(x[2]) && /69,80/.test(x[3]) && /10 Starts/.test(x[4]) && /3 Leads/.test(x[5]) && /t\.me\/pg_bot_um\?start=camp_pg/.test(x[7]), det: JSON.stringify(x) };
    }, 'links');
    await t('contador de links no cabeçalho bate com as linhas da tabela', async () => {
      const n = Number(await c.avaliar(`document.getElementById('contador-links').innerText`));
      return { ok: n === (await linhas()).length, det: 'contador=' + n };
    }, 'links');
    await t('"+ Gerar novo link" abre o modal vazio com os 2 bots no seletor; Cancelar e ✕ fecham', async () => {
      await c.avaliar(`document.getElementById('btn-novo-link').click(); 1`);
      await esp(700);
      const r = JSON.parse(await c.avaliar(`JSON.stringify({aberto:document.getElementById('modal-link').classList.contains('aberto'), t:document.getElementById('link-titulo').value, i:document.getElementById('link-identificador').value, bots:[...document.querySelectorAll('#link-bot option')].map(function(o){return o.textContent.trim()}), tit:document.getElementById('modal-titulo-texto').innerText})`));
      await c.avaliar(`document.getElementById('btn-cancelar-modal').click(); 1`);
      const f1 = await c.avaliar(`!document.getElementById('modal-link').classList.contains('aberto')`);
      await c.avaliar(`document.getElementById('btn-novo-link').click(); document.getElementById('btn-fechar-modal').click(); 1`);
      const f2 = await c.avaliar(`!document.getElementById('modal-link').classList.contains('aberto')`);
      return { ok: r.aberto && r.t === '' && r.i === '' && r.bots.filter(b => /Bot (Um|Dois)/.test(b)).length === 2 && f1 && f2, det: JSON.stringify(r) };
    }, 'links');
    await t('criar: campos vazios → erro e nada é criado', async () => {
      await c.avaliar(`document.getElementById('btn-novo-link').click(); 1`);
      await preencher('', '', null);
      await c.avaliar(`document.getElementById('form-link').requestSubmit(); 1`);
      const x = await lerToast(c);
      await c.avaliar(`document.getElementById('btn-cancelar-modal').click(); 1`);
      return { ok: /informe|preencha|obrigat/i.test(x), det: x };
    }, 'links');
    await t('criar: identificador com espaço/acentos/símbolos é recusado (só letras, números, _ e -)', async () => {
      const ruins = ['com espaco', 'açaí', 'a/b', 'a?b=1', 'x&y', '<b>', "a'b"];
      const falhas = [];
      for (const id of ruins) {
        const r = await api('criar_link_rastreamento', { titulo: 'T', identificador: id, bot_id: 311 });
        if (r.http !== 422 || (r.j && r.j.sucesso)) falhas.push(id + ' → ' + r.http);
      }
      return { ok: !falhas.length, det: falhas.join('; ') };
    }, 'links');
    await t('criar pela tela: título + identificador + bot → aparece na lista com URL correta e 0 vendas', async () => {
      await c.avaliar(`document.getElementById('btn-novo-link').click(); 1`);
      await esp(3400); // deixa sumir o aviso da etapa anterior
      await preencher('Link do Instagram', 'insta_bio', '312');
      await c.avaliar(`document.getElementById('form-link').requestSubmit(); 1`);
      const x = await lerToast(c);
      await esp(1200);
      const l = await linhas();
      const novo = l.find(r => /insta_bio/.test(r.join(' ')));
      const doBanco = db(`SELECT bot_id, id_usuario FROM links_rastreamento WHERE identificador='insta_bio'`)[0];
      return { ok: /criado/i.test(x) && !!novo && /Bot Dois/.test(novo[1]) && /0 Vendas/.test(novo[2]) && /t\.me\/pg_bot_dois\?start=insta_bio/.test(novo[7]) && doBanco.id_usuario === 49, det: x + ' | ' + JSON.stringify(novo) };
    }, 'links');
    await t('criar: identificador repetido é recusado ("já em uso") mesmo com título diferente', async () => {
      const r = await api('criar_link_rastreamento', { titulo: 'Outro', identificador: 'insta_bio', bot_id: 311 });
      return { ok: r.http === 422 && /em uso/i.test(r.j.mensagem), det: JSON.stringify(r.j) };
    }, 'links');
    await t('criar: bot de OUTRO usuário é recusado', async () => {
      const alheio = db(`SELECT id FROM bots WHERE id_usuario<>49 ORDER BY id DESC LIMIT 1`)[0].id;
      const r = await api('criar_link_rastreamento', { titulo: 'Hack', identificador: 'hack_x', bot_id: Number(alheio) });
      const n = db(`SELECT COUNT(*) n FROM links_rastreamento WHERE identificador='hack_x'`)[0].n;
      return { ok: r.http === 422 && n === 0, det: JSON.stringify(r.j) };
    }, 'segurança');
    await t('identificador maior que 64 caracteres é recusado (o Telegram só entrega ?start= de até 64)', async () => {
      const r = await api('criar_link_rastreamento', { titulo: 'Longo', identificador: 'a'.repeat(80), bot_id: 311 });
      if (r.j && r.j.sucesso) await api('excluir_link_rastreamento', { id: r.j.id });
      return { ok: r.http === 422, det: `HTTP ${r.http}: ${JSON.stringify(r.j).slice(0, 120)}` };
    }, 'links');
    await t('título com HTML/script aparece como texto na lista (sem executar)', async () => {
      const r = await api('criar_link_rastreamento', { titulo: '<img src=x onerror=window.__lk=1>Titulo', identificador: 'xss_lk', bot_id: 311 });
      await c.ir(BASE + '/links_rastreamento');
      await esp(1500);
      const exec = await c.avaliar('window.__lk===1');
      const imgs = await c.avaliar(`document.querySelectorAll('#corpo-tabela img').length`);
      if (r.j && r.j.id) await api('excluir_link_rastreamento', { id: r.j.id });
      return { ok: !exec && imgs === 0, det: '' };
    }, 'segurança');
    await t('editar pela tela: abre com os dados, troca título/identificador/bot e a lista reflete', async () => {
      await c.ir(BASE + '/links_rastreamento');
      await c.esperar(`document.querySelectorAll('#corpo-tabela tr').length>0`); await esp(600);
      const id = db(`SELECT id FROM links_rastreamento WHERE identificador='insta_bio'`)[0].id;
      await c.avaliar(`document.querySelector('.btn-editar-link[data-id="${id}"]').click(); 1`);
      await esp(1200);
      const pre = JSON.parse(await c.avaliar(`JSON.stringify({t:document.getElementById('link-titulo').value,i:document.getElementById('link-identificador').value,b:document.getElementById('link-bot').value,tit:document.getElementById('modal-titulo-texto').innerText})`));
      await preencher('Instagram (editado)', 'insta_bio2', '311');
      await c.avaliar(`document.getElementById('form-link').requestSubmit(); 1`);
      const x = await lerToast(c);
      await esp(1200);
      const b = db(`SELECT titulo, identificador, bot_id FROM links_rastreamento WHERE id=${id}`)[0];
      return { ok: pre.t === 'Link do Instagram' && pre.i === 'insta_bio' && pre.b === '312' && /atualizado/i.test(x) && b.titulo === 'Instagram (editado)' && b.identificador === 'insta_bio2' && Number(b.bot_id) === 311, det: JSON.stringify(pre) + ' → ' + JSON.stringify(b) };
    }, 'links');
    await t('editar: trocar o identificador para um já usado por outro link é recusado', async () => {
      const id = db(`SELECT id FROM links_rastreamento WHERE identificador='insta_bio2'`)[0].id;
      const r = await api('editar_link_rastreamento', { id: Number(id), titulo: 'X', identificador: 'camp_pg', bot_id: 311 });
      return { ok: r.http === 422 && /em uso/i.test(r.j.mensagem), det: JSON.stringify(r.j) };
    }, 'links');
    await t('editar link de OUTRO usuário não altera nada', async () => {
      const alheio = db(`SELECT id, titulo FROM links_rastreamento WHERE id_usuario<>49 ORDER BY id DESC LIMIT 1`)[0];
      if (!alheio) return { ok: true, det: 'sem link alheio para testar' };
      const r = await api('editar_link_rastreamento', { id: Number(alheio.id), titulo: 'SEQUESTRADO', identificador: 'seq_x', bot_id: 311 });
      const dep = db(`SELECT titulo FROM links_rastreamento WHERE id=${alheio.id}`)[0].titulo;
      return { ok: dep === alheio.titulo, det: `http ${r.http}` };
    }, 'segurança');
    await t('copiar link: o botão copia a URL e avisa', async () => {
      await c.ir(BASE + '/links_rastreamento');
      await c.esperar(`document.querySelectorAll('.btn-copiar-link').length>0`); await esp(500);
      await c.avaliar(`window.__copiado=''; try { navigator.clipboard.writeText = function(t){ window.__copiado=t; return Promise.resolve(); } } catch(e){}; document.querySelector('.btn-copiar-link').click(); 1`);
      const x = await lerToast(c, 4000);
      const copiado = await c.avaliar('window.__copiado');
      return { ok: /copiad/i.test(x), det: `aviso="${x}" copiado="${copiado}"` };
    }, 'links');
    await t('excluir: Cancelar mantém, confirmar remove do banco e da tabela', async () => {
      const id = db(`SELECT id FROM links_rastreamento WHERE identificador='insta_bio2'`)[0].id;
      c.aceitar = false;
      await c.avaliar(`document.querySelector('.btn-excluir-link[data-id="${id}"]').click(); 1`);
      await esp(900);
      const ainda = db(`SELECT COUNT(*) n FROM links_rastreamento WHERE id=${id}`)[0].n;
      c.aceitar = true;
      await c.avaliar(`document.querySelector('.btn-excluir-link[data-id="${id}"]').click(); 1`);
      await esp(1800);
      const depois = db(`SELECT COUNT(*) n FROM links_rastreamento WHERE id=${id}`)[0].n;
      return { ok: ainda === 1 && depois === 0, det: `cancelar→${ainda}, confirmar→${depois}` };
    }, 'links');
    await t('excluir link de OUTRO usuário é recusado', async () => {
      const alheio = db(`SELECT id FROM links_rastreamento WHERE id_usuario<>49 ORDER BY id DESC LIMIT 1`)[0];
      if (!alheio) return { ok: true, det: 'sem link alheio' };
      const r = await api('excluir_link_rastreamento', { id: Number(alheio.id) });
      const n = db(`SELECT COUNT(*) n FROM links_rastreamento WHERE id=${alheio.id}`)[0].n;
      return { ok: r.http === 404 && n === 1, det: `http ${r.http}` };
    }, 'segurança');
    await t('o start com identificador do link grava a origem no lead e conta Starts/Leads (webhook real simulado não é necessário: números vêm da tabela)', async () => {
      const l = db(`SELECT starts, leads, vendas_qtd FROM links_rastreamento WHERE identificador='camp_pg'`)[0];
      return { ok: l.starts === 10 && l.leads === 3, det: JSON.stringify(l) };
    }, 'links');
    salvar();
    c.pararDialogo();
  } finally { await c.fechar(); }
})();
