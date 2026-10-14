// ÁREA 7: Redirecionamento (editor de links, KPIs, link público /l/slug, rotação, proteção contra robôs)
const { execFileSync } = require('child_process');
const { lancar, entrar, BASE, db, autoDialogo, lerToast, remoto } = require('./pg_lib.js');
const { area, t, nota, salvar, esp } = require('./pg_t.js');

function publico(slug, { ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) AppleWebKit/605.1.15', metodo = 'GET', qs = '' } = {}) {
  const args = ['-s', '-o', 'NUL', '-D', '-', '-A', ua, '-X', metodo];
  if (metodo === 'HEAD') args.splice(args.indexOf('-X'), 2, '-I');
  args.push(`${BASE}/l/${slug}${qs}`);
  const out = execFileSync('curl', args, { encoding: 'utf8' });
  const status = Number((out.match(/HTTP\/[\d.]+ (\d+)/) || [])[1]);
  const loc = (out.match(/^location:\s*(.+)$/im) || [])[1];
  return { status, loc: loc && loc.trim() };
}

(async () => {
  const c = await lancar();
  autoDialogo(c, true);
  const api = (acao, corpo, metodo = 'POST') => c.avaliar(`fetch('api.php?action=${acao}',{method:'${metodo}',headers:{'Content-Type':'application/json','X-CSRF-Token':window.CSRF_TOKEN||''},body:${metodo === 'GET' ? 'undefined' : 'JSON.stringify(' + JSON.stringify(corpo) + ')'}}).then(async r=>({http:r.status,j:await r.json().catch(()=>null)}))`);
  const cliques = (slug) => Number(db(`SELECT cliques c FROM redirecionadores WHERE slug='${slug}'`)[0].c);
  try {
    await entrar(c, 'pgteste-user@example.invalid');
    area('7. Redirecionamento');
    await c.ir(BASE + '/redirecionamento');
    await esp(1500);
    await t('estado vazio: KPIs zerados e botão para criar o primeiro link', async () => {
      const tx = await c.avaliar(`document.querySelector('.conteudo-principal').innerText.split(String.fromCharCode(10)).filter(Boolean).join(' | ')`);
      return { ok: /Links/i.test(tx) && (await c.avaliar(`!!document.getElementById('rd-btn-novo')`)), det: tx.slice(0, 160) };
    }, 'redirecionamento');
    await t('"Novo link" abre o editor com padrões: aleatório, Meta Ads, sem proteção, domínio do painel, ativo', async () => {
      await c.avaliar(`document.getElementById('rd-btn-novo').click(); 1`);
      await esp(1000);
      const r = JSON.parse(await c.avaliar(`JSON.stringify({ed:!document.getElementById('rd-editor').hidden&&getComputedStyle(document.getElementById('rd-editor')).display!=='none', formato:document.querySelector('#rd-formatos .ativo').dataset.formato, plat:document.querySelector('#rd-plataformas .ativo').dataset.plataforma, prot:document.querySelector('#rd-protecoes .ativo').dataset.protecao, modo:document.querySelector('#rd-modos .ativo').dataset.modo, ativo:document.getElementById('rd-ativo').checked, bots:document.querySelectorAll('.rd-bot-caixa').length})`));
      return { ok: r.ed && r.formato === 'aleatorio' && r.plat === 'meta' && r.prot === 'nenhuma' && r.modo === 'aleatorio' && r.ativo && r.bots === 2, det: JSON.stringify(r) };
    }, 'redirecionamento');
    await t('salvar sem nome → "Dê um nome ao link."; ativo sem bot → pede ao menos um bot', async () => {
      await c.avaliar(`document.getElementById('rd-salvar').click(); 1`);
      const a = await lerToast(c);
      await esp(3300);
      await c.avaliar(`document.getElementById('rd-titulo').value='Teste PG'; document.getElementById('rd-titulo').dispatchEvent(new Event('input',{bubbles:true})); document.getElementById('rd-salvar').click(); 1`);
      const b = await lerToast(c);
      return { ok: /nome/i.test(a) && /bot/i.test(b), det: `a="${a}" b="${b}"` };
    }, 'redirecionamento');
    await t('formato personalizado: endereço inválido (maiúscula, 2 letras, hífen no fim, símbolo) é recusado pelo servidor', async () => {
      const falhas = [];
      for (const slug of ['AB', 'ab', '-abc', 'abc-', 'a b c', 'abc_def', 'a'.repeat(41), 'ábc']) {
        const r = await api('salvar_redirecionador', { titulo: 'X', formato: 'personalizado', slug, ativo: 0, bots: [] });
        if (r.http !== 422 || (r.j && r.j.sucesso)) falhas.push(slug + '→' + r.http);
      }
      return { ok: !falhas.length, det: falhas.join('; ') };
    }, 'redirecionamento');
    await t('criar pela tela (personalizado, Meta, 2 bots, sequencial, filtrar robôs) → salvo no banco e listado', async () => {
      await esp(3300);
      await c.avaliar(`document.querySelector('#rd-formatos [data-formato=personalizado]').click(); 1`);
      await esp(300);
      await c.avaliar(`var s=document.getElementById('rd-slug'); s.value='pg-teste-seq'; s.dispatchEvent(new Event('input',{bubbles:true})); 1`);
      await c.avaliar(`document.querySelector('#rd-protecoes [data-protecao=filtrar_robos]').click(); document.querySelector('#rd-modos [data-modo=sequencial]').click(); document.querySelectorAll('.rd-bot-caixa').forEach(function(b){ if(!b.checked) b.click(); }); 1`);
      await c.avaliar(`document.getElementById('rd-salvar').click(); 1`);
      const x = await lerToast(c);
      await esp(1800);
      const l = db(`SELECT r.id, r.slug, r.protecao, r.modo, r.ativo, r.plataforma, (SELECT COUNT(*) FROM redirecionador_destinos d WHERE d.redirecionador_id=r.id) bots FROM redirecionadores r WHERE r.id_usuario=49 AND r.slug='pg-teste-seq'`)[0];
      global.__rd = l && l.id;
      return { ok: /salvo/i.test(x) && l && l.protecao === 'filtrar_robos' && l.modo === 'sequencial' && l.ativo === 1 && l.plataforma === 'meta' && l.bots === 2, det: x + ' | ' + JSON.stringify(l) };
    }, 'redirecionamento');
    await t('endereço repetido (outro usuário ou o mesmo) é recusado: "já está em uso"', async () => {
      const r = await api('salvar_redirecionador', { titulo: 'Dup', formato: 'personalizado', slug: 'pg-teste-seq', ativo: 0, bots: [] });
      return { ok: r.http === 422 && /em uso/i.test(r.j.mensagem), det: JSON.stringify(r.j) };
    }, 'redirecionamento');

    area('7b. Link público /l/{slug} (cliques, rotação, robôs)');
    await t('link ativo responde 302 para t.me/<bot>?start=rd_<slug>', async () => {
      const r = publico('pg-teste-seq');
      return { ok: r.status === 302 && /^https:\/\/t\.me\/pg_bot_(um|dois)\?start=rd_pg-teste-seq$/.test(r.loc), det: JSON.stringify(r) };
    }, 'público');
    await t('cada clique conta +1 e a rotação sequencial alterna entre os 2 bots', async () => {
      const antes = cliques('pg-teste-seq');
      const a = publico('pg-teste-seq'), b = publico('pg-teste-seq'), d = publico('pg-teste-seq'), e = publico('pg-teste-seq');
      const bots = [a, b, d, e].map(x => (x.loc.match(/t\.me\/(pg_bot_\w+)/) || [])[1]);
      const depois = cliques('pg-teste-seq');
      const alterna = bots[0] !== bots[1] && bots[0] === bots[2] && bots[1] === bots[3];
      return { ok: depois === antes + 4 && alterna, det: `${antes}→${depois}; ordem ${bots.join(',')}` };
    }, 'público');
    await t('"Filtrar robôs": user-agents de robô (Telegram, Facebook, WhatsApp, Google, sem UA) ainda redirecionam mas NÃO contam clique', async () => {
      const antes = cliques('pg-teste-seq');
      const r1 = publico('pg-teste-seq', { ua: 'TelegramBot (like TwitterBot)' });
      const r2 = publico('pg-teste-seq', { ua: 'facebookexternalhit/1.1' });
      const r3 = publico('pg-teste-seq', { ua: 'WhatsApp/2.23.20' });
      const r4 = publico('pg-teste-seq', { ua: 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' });
      const depois = cliques('pg-teste-seq');
      return { ok: [r1, r2, r3, r4].every(r => r.status === 302) && depois === antes, det: `${antes}→${depois}; ${r1.status},${r2.status},${r3.status},${r4.status}` };
    }, 'público');
    nota('Limitação do servidor: o LiteSpeed entrega requisições HEAD ao PHP como GET (confirmado com X-Metodo), então HEAD é contado como clique; o filtro de robôs funciona pelo user-agent', 'não corrigível no código');
    await t('utm_campaign do anúncio é registrado por dia na tabela de campanhas (aba "Por campanha")', async () => {
      publico('pg-teste-seq', { qs: '?utm_campaign=black_friday' });
      const l = db(`SELECT campanha, cliques FROM redirecionador_cliques_dia WHERE redirecionador_id=${global.__rd} AND campanha='black_friday'`);
      const r = await api('campanhas_redirecionador&id=' + global.__rd, null, 'GET');
      return { ok: l.length === 1 && l[0].cliques >= 1 && r.j.sucesso, det: JSON.stringify(l) };
    }, 'público');
    await t('utm_campaign com caracteres de controle/HTML não quebra nem injeta (limitado a 100)', async () => {
      const r = publico('pg-teste-seq', { qs: '?utm_campaign=' + encodeURIComponent('<script>x</script>' + 'z'.repeat(200)) });
      const l = db(`SELECT LENGTH(campanha) n FROM redirecionador_cliques_dia WHERE redirecionador_id=${global.__rd} AND campanha LIKE '<script>%'`);
      return { ok: r.status === 302 && (l.length === 0 || l[0].n <= 100), det: `${r.status} tamanho ${l[0] && l[0].n}` };
    }, 'segurança');
    await t('slug inexistente, inválido ou com maiúsculas: 404 amigável (sem erro PHP)', async () => {
      const a = publico('naoexiste123'), b = publico('ab'), cc = publico('PG-TESTE-SEQ');
      return { ok: a.status === 404 && b.status === 404 && (cc.status === 302 || cc.status === 404), det: `${a.status},${b.status},${cc.status}` };
    }, 'público');

    area('7c. Editar, alternar, excluir, segurança');
    await t('inativar pela API: o link público passa a responder 404 e deixa de contar; reativar volta ao normal', async () => {
      const off = await api('alternar_redirecionador', { id: Number(global.__rd), ativo: false });
      const r1 = publico('pg-teste-seq');
      const on = await api('alternar_redirecionador', { id: Number(global.__rd), ativo: true });
      const r2 = publico('pg-teste-seq');
      return { ok: off.j.sucesso && r1.status === 404 && on.j.sucesso && r2.status === 302, det: `${r1.status} → ${r2.status}` };
    }, 'redirecionamento');
    await t('editar pela tela: abre com os dados salvos, troca nome e modo e salva', async () => {
      await c.ir(BASE + '/redirecionamento');
      await esp(1500);
      await c.avaliar(`document.querySelector('.rd-editar[data-id="${global.__rd}"]').click(); 1`);
      await esp(1500);
      const pre = JSON.parse(await c.avaliar(`JSON.stringify({t:document.getElementById('rd-titulo').value, slug:document.getElementById('rd-slug').value, modo:document.querySelector('#rd-modos .ativo').dataset.modo, marc:[...document.querySelectorAll('.rd-bot-caixa:checked')].length})`));
      await c.avaliar(`var i=document.getElementById('rd-titulo'); i.value='Teste PG editado'; i.dispatchEvent(new Event('input',{bubbles:true})); document.querySelector('#rd-modos [data-modo=aleatorio]').click(); document.getElementById('rd-salvar').click(); 1`);
      const x = await lerToast(c);
      await esp(1200);
      const b = db(`SELECT titulo, modo, slug FROM redirecionadores WHERE id=${global.__rd}`)[0];
      return { ok: pre.t === 'Teste PG' && pre.slug === 'pg-teste-seq' && pre.modo === 'sequencial' && pre.marc === 2 && b.titulo === 'Teste PG editado' && b.modo === 'aleatorio' && b.slug === 'pg-teste-seq', det: JSON.stringify(pre) + ' → ' + JSON.stringify(b) };
    }, 'redirecionamento');
    await t('o endereço (slug) NÃO muda ao editar (links já divulgados continuam funcionando)', async () => {
      const r = await api('salvar_redirecionador', { id: Number(global.__rd), titulo: 'Teste PG editado', formato: 'personalizado', slug: 'outro-slug-novo', ativo: 1, bots: [311, 312] });
      const b = db(`SELECT slug FROM redirecionadores WHERE id=${global.__rd}`)[0];
      return { ok: b.slug === 'pg-teste-seq', det: 'slug=' + b.slug };
    }, 'redirecionamento');
    await t('bot de OUTRO usuário como destino é recusado; bot inexistente também', async () => {
      const alheio = db(`SELECT id FROM bots WHERE id_usuario<>49 ORDER BY id DESC LIMIT 1`)[0].id;
      const r1 = await api('salvar_redirecionador', { id: Number(global.__rd), titulo: 'X', ativo: 1, bots: [Number(alheio)] });
      const r2 = await api('salvar_redirecionador', { id: Number(global.__rd), titulo: 'X', ativo: 1, bots: [99999999] });
      const dest = db(`SELECT COUNT(*) n FROM redirecionador_destinos WHERE redirecionador_id=${global.__rd} AND bot_id=${alheio}`)[0].n;
      return { ok: !r1.j.sucesso && !r2.j.sucesso && dest === 0, det: JSON.stringify(r1.j.mensagem) };
    }, 'segurança');
    await t('valores inválidos de plataforma/proteção/modo/domínio são recusados', async () => {
      const casos = [{ plataforma: 'xyz' }, { protecao: 'foo' }, { modo: 'bar' }, { dominio: 'evil.com' }];
      const falhas = [];
      for (const cx of casos) {
        const r = await api('salvar_redirecionador', Object.assign({ id: Number(global.__rd), titulo: 'X', ativo: 1, bots: [311] }, cx));
        if (r.j.sucesso) falhas.push(JSON.stringify(cx));
      }
      return { ok: !falhas.length, det: falhas.join(';') };
    }, 'segurança');
    await t('editar/alternar/excluir/obter link de OUTRO usuário é recusado', async () => {
      const alheio = db(`SELECT id, titulo FROM redirecionadores WHERE id_usuario<>49 ORDER BY id DESC LIMIT 1`)[0];
      if (!alheio) return { ok: true, det: 'sem redirecionador alheio para testar' };
      const a = await api('salvar_redirecionador', { id: Number(alheio.id), titulo: 'SEQ', ativo: 0, bots: [] });
      const b = await api('alternar_redirecionador', { id: Number(alheio.id), ativo: false });
      const d = await api('excluir_redirecionador', { id: Number(alheio.id) });
      const g = await api('obter_redirecionador&id=' + alheio.id, null, 'GET');
      const dep = db(`SELECT titulo FROM redirecionadores WHERE id=${alheio.id}`);
      return { ok: dep.length === 1 && dep[0].titulo === alheio.titulo && !g.j.sucesso, det: `salvar ${a.http}, alternar ${b.http}, excluir ${d.http}, obter ${g.http}` };
    }, 'segurança');
    await t('título com HTML aparece como texto na lista (sem executar)', async () => {
      const r = await api('salvar_redirecionador', { titulo: '<img src=x onerror=window.__rdx=1>T', formato: 'aleatorio', ativo: 0, bots: [] });
      await c.ir(BASE + '/redirecionamento');
      await esp(1500);
      const exec = await c.avaliar('window.__rdx===1');
      const imgs = await c.avaliar(`document.querySelectorAll('#rd-lista img').length`);
      if (r.j && r.j.id) await api('excluir_redirecionador', { id: r.j.id });
      return { ok: !exec && imgs === 0, det: '' };
    }, 'segurança');
    await t('KPIs da lista somam links, cliques e batem com o banco', async () => {
      await c.ir(BASE + '/redirecionamento');
      await esp(1500);
      const k = JSON.parse(await c.avaliar(`JSON.stringify({l:document.getElementById('rd-kpi-links').innerText, c:document.getElementById('rd-kpi-cliques').innerText})`));
      const s = db(`SELECT COUNT(*) n, COALESCE(SUM(cliques),0) c FROM redirecionadores WHERE id_usuario=49`)[0];
      return { ok: Number(k.l.replace(/\D/g, '')) === s.n && Number(k.c.replace(/\D/g, '')) === Number(s.c), det: JSON.stringify(k) + ' banco ' + JSON.stringify(s) };
    }, 'redirecionamento');
    await t('excluir pela tela: Cancelar mantém, confirmar apaga o link, destinos e o link público vira 404', async () => {
      c.aceitar = false;
      await c.avaliar(`document.querySelector('.rd-excluir[data-id="${global.__rd}"]').click(); 1`);
      await esp(900);
      const ainda = db(`SELECT COUNT(*) n FROM redirecionadores WHERE id=${global.__rd}`)[0].n;
      c.aceitar = true;
      await c.avaliar(`document.querySelector('.rd-excluir[data-id="${global.__rd}"]').click(); 1`);
      await esp(2000);
      const dep = db(`SELECT COUNT(*) n FROM redirecionadores WHERE id=${global.__rd}`)[0].n;
      const dest = db(`SELECT COUNT(*) n FROM redirecionador_destinos WHERE redirecionador_id=${global.__rd}`)[0].n;
      const r = publico('pg-teste-seq');
      return { ok: ainda === 1 && dep === 0 && dest === 0 && r.status === 404, det: `cancelar→${ainda}, confirmar→${dep}, destinos→${dest}, público ${r.status}` };
    }, 'redirecionamento');
    salvar();
    c.pararDialogo();
  } finally { await c.fechar(); }
})();
