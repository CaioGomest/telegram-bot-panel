// ÁREA 4g (10/10/2026): Links de Rastreamento, Redirecionamento e Webhooks — conferência de desencargo, conta de estresse cliente5.
// Cria e apaga o que usa (prefixo "zz-teste"). Uso: PGPW=... OUT=./pg node pg_a23.js
const { lancar, entrar, BASE, autoDialogo } = require('./pg_lib.js');
const { area, t, salvar, esp } = require('./pg_t.js');
const SUF = String(Date.now()).slice(-6);

(async () => {
  const c = await lancar();
  try {
    await entrar(c, 'cliente5@gmial.com', '12345678');
    if (autoDialogo) autoDialogo(c, true);
    const api = (acao, corpo) => c.avaliar(`fetch('/api.php?action=${acao}', {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN || ''}, body: JSON.stringify(${JSON.stringify(corpo || {})})}).then(function(r){return r.text()}).then(function(x){try{return JSON.parse(x)}catch(e){return {sucesso:false, bruto:x.slice(0,120)}}})`);
    const apiGet = (q) => c.avaliar(`fetch('/api.php?${q}', {credentials: 'same-origin'}).then(function(r){return r.json()})`);

    // ---------------- Links de rastreamento ----------------
    area('4g.1 Links de Rastreamento');
    const ident = 'zz-teste-' + SUF;
    let idLink = 0;
    await c.ir(BASE + '/links_rastreamento'); await esp(1500);
    await t('página abre sem erro e lista os links existentes', async () => {
      const r = JSON.parse(await c.avaliar(`JSON.stringify({linhas: document.querySelectorAll('tbody tr').length, erro: document.body.innerText.indexOf('Fatal')>=0, contador: document.getElementById('contador-links').innerText})`));
      return { ok: r.linhas >= 1 && !r.erro, det: JSON.stringify(r) };
    }, 'links_rastreamento');
    await t('criar link pelo modal: aparece na tabela com a URL t.me/<bot>?start=<identificador>', async () => {
      await c.avaliar(`document.getElementById('btn-novo-link').click()`); await esp(500);
      const bot = await c.avaliar(`(function(){var s=document.getElementById('link-bot'); return s.options.length>1 ? s.options[1].value : s.options[0].value})()`);
      await c.avaliar(`(function(){document.getElementById('link-titulo').value='ZZ teste ${SUF}'; document.getElementById('link-identificador').value='${ident}'; var s=document.getElementById('link-bot'); s.value=${JSON.stringify(bot)}; })()`);
      await c.avaliar(`document.getElementById('btn-salvar-link').click()`); await esp(1500);
      const r = JSON.parse(await c.avaliar(`JSON.stringify({linha: [].slice.call(document.querySelectorAll('tbody tr')).filter(function(tr){return tr.innerText.indexOf('ZZ teste ${SUF}')>=0}).map(function(tr){return tr.querySelector('.col-link').innerText + '|' + tr.querySelector('.btn-editar-link').getAttribute('data-id')})[0]||''})`));
      const [url, id] = r.linha.split('|'); idLink = Number(id);
      return { ok: /t\.me\/.+\?start=zz-teste-/.test(url) && idLink > 0, det: url };
    }, 'links_rastreamento');
    await t('identificador repetido é recusado', async () => {
      const bot = await c.avaliar(`document.getElementById('link-bot').options.length`);
      const lista = await apiGet('action=listar_links_rastreamento');
      const bot_id = lista.links.find(l => l.id == idLink).bot_id;
      const r = await api('criar_link_rastreamento', { titulo: 'dup', identificador: ident, bot_id: Number(bot_id) });
      return { ok: r.sucesso === false, det: JSON.stringify(r).slice(0, 110) };
    }, 'links_rastreamento');
    await t('identificador com espaço/símbolo é recusado', async () => {
      const lista = await apiGet('action=listar_links_rastreamento');
      const bot_id = Number(lista.links.find(l => l.id == idLink).bot_id);
      const a = await api('criar_link_rastreamento', { titulo: 'x', identificador: 'com espaco', bot_id });
      const b = await api('criar_link_rastreamento', { titulo: 'x', identificador: 'a<b>"c', bot_id });
      return { ok: a.sucesso === false && b.sucesso === false, det: JSON.stringify([a.mensagem, b.mensagem]) };
    }, 'links_rastreamento');
    await t('bot de outro usuário é recusado (isolamento)', async () => {
      const r = await api('criar_link_rastreamento', { titulo: 'x', identificador: 'zz-outro-' + SUF, bot_id: 1 });
      return { ok: r.sucesso === false, det: JSON.stringify(r).slice(0, 110) };
    }, 'links_rastreamento');
    await t('editar o título persiste', async () => {
      const lista = await apiGet('action=listar_links_rastreamento');
      const bot_id = Number(lista.links.find(l => l.id == idLink).bot_id);
      const r = await api('editar_link_rastreamento', { id: idLink, titulo: 'ZZ editado ' + SUF, identificador: ident, bot_id });
      const dep = await apiGet('action=listar_links_rastreamento');
      return { ok: r.sucesso === true && dep.links.some(l => l.id == idLink && l.titulo === 'ZZ editado ' + SUF), det: JSON.stringify(r).slice(0, 90) };
    }, 'links_rastreamento');
    await t('excluir pelo botão (confirmação aceita): some da lista', async () => {
      await c.ir(BASE + '/links_rastreamento'); await esp(1300);
      await c.avaliar(`window.confirm = function(){return true}; document.querySelector('.btn-editar-link[data-id="${idLink}"]').closest('tr').querySelector('.btn-excluir-link').click()`); await esp(1300);
      const lista = await apiGet('action=listar_links_rastreamento');
      return { ok: !lista.links.some(l => l.id == idLink) };
    }, 'links_rastreamento');

    // ---------------- Redirecionamento ----------------
    area('4g.2 Redirecionamento');
    await c.ir(BASE + '/redirecionamento'); await esp(1500);
    const slug = 'zz-teste-' + SUF;
    let idRd = 0, botRd = 0;
    await t('página abre sem erro', async () => {
      const r = JSON.parse(await c.avaliar(`JSON.stringify({erro: document.body.innerText.indexOf('Fatal')>=0, novo: !!document.getElementById('rd-btn-novo')})`));
      return { ok: !r.erro && r.novo, det: JSON.stringify(r) };
    }, 'redirecionamento');
    await t('criar redirecionador (API da tela): vem com slug e fica ativo', async () => {
      const op = await apiGet('action=opcoes_redirecionamento');
      botRd = Number((op.bots || [])[0] && (op.bots[0].id || op.bots[0]));
      const r = await api('salvar_redirecionador', { id: 0, titulo: 'ZZ redir ' + SUF, ativo: 1, dominio: (op.dominios && op.dominios[0] && (op.dominios[0].dominio || op.dominios[0])) || '', formato: 'personalizado', slug, plataforma: 'meta', protecao: 'nenhuma', modo: 'aleatorio', bots: [botRd] });
      const lista = await apiGet('action=listar_redirecionadores');
      const l = (lista.links || lista.redirecionadores || []).find(x => x.slug === slug);
      idRd = l ? Number(l.id) : 0;
      return { ok: r.sucesso !== false && idRd > 0, det: JSON.stringify(r).slice(0, 120) + ' id=' + idRd };
    }, 'redirecionamento');
    await t('endereço inválido (maiúscula/espaço) e curto demais são recusados', async () => {
      const a = await api('salvar_redirecionador', { id: 0, titulo: 'x', ativo: 0, formato: 'personalizado', slug: 'ZZ Ruim', plataforma: 'meta', protecao: 'nenhuma', modo: 'aleatorio', bots: [] });
      const b = await api('salvar_redirecionador', { id: 0, titulo: 'x', ativo: 0, formato: 'personalizado', slug: 'ab', plataforma: 'meta', protecao: 'nenhuma', modo: 'aleatorio', bots: [] });
      return { ok: a.sucesso === false && b.sucesso === false, det: JSON.stringify([a.mensagem, b.mensagem]) };
    }, 'redirecionamento');
    await t('link público /l/<slug> responde 302 para o Telegram com start=rd_<slug>', async () => {
      const res = await fetch(BASE + '/l/' + slug, { redirect: 'manual', headers: { 'User-Agent': 'Mozilla/5.0 (Linux; Android 13) AppleWebKit/537.36 Chrome/120 Mobile Safari/537.36', 'Accept-Language': 'pt-BR' } });
      const loc = res.headers.get('location') || '';
      return { ok: res.status === 302 && /t\.me\/.+\?start=rd_/.test(loc), det: res.status + ' ' + loc };
    }, 'redirecionamento');
    await t('slug que não existe devolve 404 (sem erro de PHP)', async () => {
      const res = await fetch(BASE + '/l/zz-nao-existe-' + SUF, { redirect: 'manual' });
      const txt = await res.text();
      return { ok: res.status === 404 && txt.indexOf('Fatal') < 0, det: String(res.status) };
    }, 'redirecionamento');
    await t('inativo: o link público deixa de redirecionar', async () => {
      const r = await api('alternar_redirecionador', { id: idRd });
      const res = await fetch(BASE + '/l/' + slug, { redirect: 'manual' });
      return { ok: res.status !== 302, det: 'alternar=' + JSON.stringify(r).slice(0, 60) + ' http=' + res.status };
    }, 'redirecionamento');
    await t('excluir remove o redirecionador', async () => {
      const r = await api('excluir_redirecionador', { id: idRd });
      const lista = await apiGet('action=listar_redirecionadores');
      return { ok: !(lista.links || lista.redirecionadores || []).some(x => x.slug === slug), det: JSON.stringify(r).slice(0, 80) };
    }, 'redirecionamento');

    // ---------------- Webhooks ----------------
    area('4g.3 Webhooks');
    await c.ir(BASE + '/webhooks'); await esp(1500);
    const nomeWh = 'ZZ webhook ' + SUF;
    const preencher = (nome, url) => c.avaliar(`(function(){document.getElementById('btn-novo-webhook').click(); document.getElementById('webhook-nome').value=${JSON.stringify(nome)}; document.getElementById('webhook-url').value=${JSON.stringify(url)}; var e=document.querySelectorAll('input[name="eventos[]"]'); for (var i=0;i<e.length;i++) e[i].checked = i===0; document.querySelector('#webhook-form, form[method=POST]:has(#webhook-nome)').requestSubmit(); })()`);
    await t('página abre sem erro', async () => {
      const erro = await c.avaliar(`document.body.innerText.indexOf('Fatal')>=0`);
      return { ok: erro === false };
    }, 'webhooks');
    await t('URL interna (127.0.0.1) é recusada com a mensagem de segurança', async () => {
      await preencher(nomeWh + ' int', 'https://127.0.0.1/hook'); await esp(1500);
      const txt = await c.avaliar(`document.body.innerText`);
      return { ok: txt.indexOf('endereço https:// público') >= 0 || txt.indexOf('https:// público') >= 0, det: 'mensagem de URL' };
    }, 'webhooks');
    await t('URL http (sem TLS) é recusada', async () => {
      await c.ir(BASE + '/webhooks'); await esp(900);
      await preencher(nomeWh + ' http', 'http://example.com/hook'); await esp(1500);
      const txt = await c.avaliar(`document.body.innerText`);
      return { ok: txt.indexOf('https:// público') >= 0 };
    }, 'webhooks');
    await t('criar webhook válido: aparece na lista, ativo', async () => {
      await c.ir(BASE + '/webhooks'); await esp(900);
      await preencher(nomeWh, 'https://example.com/zz-hook-' + SUF); await esp(1800);
      const r = JSON.parse(await c.avaliar(`JSON.stringify({achou: document.body.innerText.indexOf(${JSON.stringify(nomeWh)})>=0, salvo: document.body.innerText.indexOf('Webhook salvo')>=0})`));
      return { ok: r.achou && r.salvo, det: JSON.stringify(r) };
    }, 'webhooks');
    await t('desativar e ativar de novo (alternar)', async () => {
      const antes = await c.avaliar(`(function(){var tr=[].slice.call(document.querySelectorAll('tbody tr')).find(function(x){return x.innerText.indexOf(${JSON.stringify(nomeWh)})>=0}); return tr ? tr.querySelector('button[title="Desativar"], button[title="Ativar"]').title : ''})()`);
      await c.avaliar(`[].slice.call(document.querySelectorAll('tbody tr')).find(function(x){return x.innerText.indexOf(${JSON.stringify(nomeWh)})>=0}).querySelector('button[title="Desativar"], button[title="Ativar"]').click()`); await esp(1500);
      const depois = await c.avaliar(`(function(){var tr=[].slice.call(document.querySelectorAll('tbody tr')).find(function(x){return x.innerText.indexOf(${JSON.stringify(nomeWh)})>=0}); return tr ? tr.querySelector('button[title="Desativar"], button[title="Ativar"]').title : ''})()`);
      return { ok: antes === 'Desativar' && depois === 'Ativar', det: antes + ' → ' + depois };
    }, 'webhooks');
    await t('editar abre o modal preenchido e salva o novo nome', async () => {
      const id = await c.avaliar(`(function(){var tr=[].slice.call(document.querySelectorAll('tbody tr')).find(function(x){return x.innerText.indexOf(${JSON.stringify(nomeWh)})>=0}); return tr.querySelector('a[href*="editar="]').getAttribute('href').split('=')[1]})()`);
      await c.ir(BASE + '/webhooks?editar=' + id); await esp(1200);
      const val = await c.avaliar(`document.getElementById('webhook-nome').value`);
      await c.avaliar(`document.getElementById('webhook-nome').value = ${JSON.stringify(nomeWh + ' ed')}; document.querySelector('form:has(#webhook-nome)').requestSubmit()`); await esp(1800);
      const ok2 = await c.avaliar(`document.body.innerText.indexOf(${JSON.stringify(nomeWh + ' ed')})>=0`);
      return { ok: val === nomeWh && ok2 === true, det: 'preenchido=' + val };
    }, 'webhooks');
    await t('excluir (confirmação aceita) remove da lista', async () => {
      await c.avaliar(`window.confirm = function(){return true}; [].slice.call(document.querySelectorAll('tbody tr')).find(function(x){return x.innerText.indexOf(${JSON.stringify(nomeWh + ' ed')})>=0}).querySelector('button[title="Excluir"]').click()`); await esp(1800);
      const some = await c.avaliar(`document.body.innerText.indexOf(${JSON.stringify(nomeWh)})<0`);
      return { ok: some === true };
    }, 'webhooks');
    console.log('erros JS:', JSON.stringify(c.errosJs()));
  } catch (e) { console.log('ERRO', e.message); }
  finally { salvar(); await c.fechar(); process.exit(0); }
})();
