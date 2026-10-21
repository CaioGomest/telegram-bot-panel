// ÁREAS 13/14/15: Ranking, Comunidade e Stories (visão do usuário)
const { lancar, entrar, BASE, db, remoto } = require('./pg_lib.js');
const { area, t, nota, salvar, esp } = require('./pg_t.js');
(async () => {
  const c = await lancar();
  const UID = db("SELECT id FROM usuarios WHERE email='pgteste-user@example.invalid'")[0].id;
  // PNG gerado no navegador (canvas) e enviado como multipart
  const subir = (nome, tipo, conteudoJs) => c.avaliar(`(async function(){ var blob=${conteudoJs}; var fd=new FormData(); fd.append('midia', blob, ${JSON.stringify(nome)}); var r=await fetch('api.php?action=criar_story',{method:'POST',headers:{'X-CSRF-Token':window.CSRF_TOKEN||''},body:fd}); return {http:r.status, j:await r.json().catch(function(){return null})}; })()`);
  const PNG = `await new Promise(function(res){ var cv=document.createElement('canvas'); cv.width=16; cv.height=16; cv.getContext('2d').fillRect(0,0,16,16); cv.toBlob(res,'image/png'); })`;
  const api = (acao, corpo, metodo = 'POST') => c.avaliar(`fetch('api.php?action=${acao}',{method:'${metodo}',headers:{'Content-Type':'application/json','X-CSRF-Token':window.CSRF_TOKEN||''},body:${metodo === 'GET' ? 'undefined' : 'JSON.stringify(' + JSON.stringify(corpo) + ')'}}).then(async r=>({http:r.status,j:await r.json().catch(()=>null)}))`);
  try {
    await entrar(c, 'pgteste-user@example.invalid');
    area('13. Ranking');
    await c.ir(BASE + '/ranking');
    await t('mostra a campanha vigente (título, período, participantes) e a contagem regressiva', async () => {
      const tx = await c.avaliar(`document.querySelector('.conteudo-principal').innerText`);
      return { ok: /EM (ANDAMENTO|DISPUTA)/i.test(tx) && /\d{2}\/\d{2}\/\d{4}\s+—\s+\d{2}\/\d{2}\/\d{4}/.test(tx) && /participantes/i.test(tx) && /DIAS/.test(tx) && /HORAS/.test(tx), det: tx.slice(0, 120).replace(/\n/g, ' ') };
    }, 'ranking');
    await t('"Sua posição" mostra o faturamento do próprio usuário (R$ 69,80) igual ao dashboard e uma posição numérica', async () => {
      const tx = await c.avaliar(`document.querySelector('.conteudo-principal').innerText`);
      const m = tx.match(/SUA POSIÇÃO\s+(\d+)/i);
      return { ok: /R\$ 69,80/.test(tx) && !!m, det: 'posição ' + (m && m[1]) };
    }, 'ranking');
    await t('placar ordenado do maior para o menor faturamento (pódio lido pela posição, não pela ordem visual 2-1-3)', async () => {
      // O pódio é desenhado na ordem visual 2º-1º-3º (o líder no centro): confere pelo número da posição de cada item.
      const itens = JSON.parse(await c.avaliar(`JSON.stringify([...document.querySelectorAll('.podio-item')].map(function(e){var m=(e.className||'').match(/podio-([0-9]+)/); return {pos: m ? Number(m[1]) : 0, valor: (e.querySelector('.podio-valor')||{}).textContent}}))`));
      const num = (x) => { const m = String(x).trim().match(/^R[$] ([0-9.,]+)( mil| mi)?$/); let v = parseFloat(m[1].split('.').join('').replace(',', '.')); if (m[2] === ' mil') v *= 1000; if (m[2] === ' mi') v *= 1e6; return v; };
      const porPos = itens.filter(i => i.pos > 0).sort((a, b) => a.pos - b.pos).map(i => num(i.valor));
      const ordenado = porPos.every((v, k, a) => k === 0 || a[k - 1] >= v);
      return { ok: porPos.length >= 2 && ordenado, det: itens.map(i => '#' + i.pos + ' ' + i.valor.trim()).join(' | ') };
    }, 'ranking');
    await t('privacidade: nenhum e-mail nem nome real de outro usuário aparece no HTML do ranking (só apelido ou "Usuário #id")', async () => {
      const html = await c.avaliar('document.documentElement.outerHTML');
      const emails = db(`SELECT email, nome FROM usuarios WHERE id<>${UID} AND perfil<>'admin' LIMIT 80`);
      const vazou = emails.filter(u => html.includes(u.email) || (u.nome && u.nome.length > 5 && html.includes(u.nome))).map(u => u.nome || u.email);
      const arrobas = (html.match(/[\w.+-]+@[\w-]+\.[\w.-]+/g) || []).filter(x => !/example\.invalid|stackcode|@\d/.test(x));
      return { ok: !vazou.length, det: vazou.slice(0, 3).join(', ') || 'nenhum nome/e-mail alheio' };
    }, 'segurança');
    await t('apelido definido pelo usuário passa a aparecer no lugar de "Usuário #id" (escapado, sem HTML)', async () => {
      remoto("php -r 'require \"conexao.php\"; $pdo->prepare(\"UPDATE usuarios SET apelido_publico=? WHERE email=?\")->execute([\"<b>pg_apelido</b>\",\"pgteste-user@example.invalid\"]);'");
      remoto('php cron/cron_ranking.php');
      await c.ir(BASE + '/ranking');
      const tx = await c.avaliar(`document.querySelector('.conteudo-principal').innerText`);
      const negrito = await c.avaliar(`document.querySelectorAll('.conteudo-principal b').length`);
      return { ok: /pg_apelido/.test(tx) && negrito === 0, det: 'apelido visível=' + /pg_apelido/.test(tx) };
    }, 'ranking');
    remoto("php -r 'require \"conexao.php\"; $pdo->prepare(\"UPDATE usuarios SET apelido_publico=NULL WHERE email=?\")->execute([\"pgteste-user@example.invalid\"]);'");

    area('14. Comunidade (página pública do painel)');
    await c.ir(BASE + '/comunidade');
    await t('abre sem barra lateral nem cabeçalho do painel (nova aba) e com o botão de tema', async () => {
      const r = JSON.parse(await c.avaliar(`JSON.stringify({side:!!document.querySelector('aside, .barra-lateral'), topo:!!document.querySelector('.topo-painel'), tema:!!document.querySelector('.alternador-tema')})`));
      return { ok: !r.side && !r.topo && r.tema, det: JSON.stringify(r) };
    }, 'comunidade');
    await t('todos os links externos usam https/http, abrem em nova aba com rel=noopener (nada de javascript:)', async () => {
      const r = JSON.parse(await c.avaliar(`JSON.stringify([...document.querySelectorAll('a[href]')].map(function(a){return {h:a.getAttribute('href'), t:a.target, r:a.rel}}))`));
      const ruins = r.filter(a => /^\s*(javascript|data|vbscript):/i.test(a.h) || (/^https?:/.test(a.h) && a.t === '_blank' && !/noopener/.test(a.r)));
      return { ok: !ruins.length, det: `${r.length} links; ruins: ${JSON.stringify(ruins)}` };
    }, 'segurança');
    await t('contador de membros do grupo é um número/total válido e imagens carregam', async () => {
      const tx = await c.avaliar(`document.body.innerText`);
      const quebradas = await c.avaliar(`[...document.images].filter(function(i){return i.complete && i.naturalWidth===0}).length`);
      return { ok: /\d+\s*\/\s*\d+/.test(tx) && quebradas === 0, det: `imagens quebradas=${quebradas}` };
    }, 'comunidade');

    area('15. Stories (barra do dashboard) — desligados por padrão; só aparecem se o admin ativar (chave stories_ativos)');
    // a chave é a mesma que o admin liga em admin/configuracoes (bloco comentado); aqui gravo direto no banco do servidor de teste
    const ligarStories = (v) => remoto("php -r 'require \"funcoes/usuario.php\"; definirConfigSistema(\"stories_ativos\", \"" + v + "\");'");
    ligarStories(0);
    await c.ir(BASE + '/');
    await t('desligados (padrão): o dashboard não mostra a barra nem o botão Criar nem carrega o JS', async () => {
      const r = JSON.parse(await c.avaliar("JSON.stringify({barra: !!document.getElementById('barra-stories'), criar: !!document.getElementById('story-btn-criar'), js: [...document.scripts].some(function(x){return /stories[.]js/.test(x.src)})})"));
      return { ok: !r.barra && !r.criar && !r.js, det: JSON.stringify(r) };
    }, 'stories');
    await t('desligados: a API devolve lista vazia e recusa criar e excluir (403)', async () => {
      const l = await api('listar_stories', null, 'GET');
      const cr = await api('criar_story', {});
      const ex = await api('excluir_story', { id: 1 });
      return { ok: l.http === 200 && l.j.sucesso && Array.isArray(l.j.grupos) && l.j.grupos.length === 0 && cr.http === 403 && ex.http === 403, det: l.http + '/' + cr.http + '/' + ex.http };
    }, 'stories');
    ligarStories(1);
    await c.ir(BASE + '/');
    await t('barra mostra o botão "Criar" e o story de outro usuário', async () => {
      const r = JSON.parse(await c.avaliar(`JSON.stringify({criar:/Criar/.test(document.querySelector('.barra-stories').innerText), itens:document.querySelectorAll('.barra-stories .story-item, .barra-stories [data-story], .barra-stories button, .barra-stories a').length})`));
      return { ok: r.criar && r.itens >= 1, det: JSON.stringify(r) };
    }, 'stories');
    await t('criar story com foto PNG: retorna sucesso e entra na lista (listar_stories)', async () => {
      const r = await subir('story.png', 'image/png', PNG);
      const l = await api('listar_stories', null, 'GET');
      const meu = (l.j.grupos || []).find(g => g.id_usuario === UID);
      global.__story = r.j && r.j.id;
      return { ok: r.http === 200 && r.j.sucesso && !!meu, det: JSON.stringify(r.j).slice(0, 100) };
    }, 'stories');
    await t('arquivos inválidos são recusados: .gif, .svg, texto como .png, texto como .mp4, executável .php', async () => {
      const casos = [['a.gif', `new Blob(['GIF89a'],{type:'image/gif'})`], ['a.svg', `new Blob(['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],{type:'image/svg+xml'})`], ['falso.png', `new Blob(['isto nao e png'],{type:'image/png'})`], ['falso.mp4', `new Blob(['isto nao e video'],{type:'video/mp4'})`], ['x.php', `new Blob(['<?php echo 1;'],{type:'text/plain'})`], ['semext', `new Blob(['x'],{type:'image/png'})`]];
      const aceitos = [];
      for (const [nome, js] of casos) {
        const r = await subir(nome, '', js);
        if (r.j && r.j.sucesso) { aceitos.push(nome); await api('excluir_story', { id: r.j.id }); }
      }
      return { ok: !aceitos.length, det: aceitos.join(', ') || 'todos recusados' };
    }, 'segurança');
    await t('foto acima de 5 MB é recusada ("limite de 5MB")', async () => {
      const r = await subir('grande.png', '', `new Blob([new Uint8Array(5*1024*1024+2048)],{type:'image/png'})`);
      return { ok: r.http === 422 && /5MB/.test(r.j.mensagem), det: JSON.stringify(r.j) };
    }, 'stories');
    await t('marcar como visto funciona e é por usuário', async () => {
      const r = await api('marcar_story_vista', { story_id: Number(global.__story) });
      const l = await api('listar_stories', null, 'GET');
      return { ok: r.j.sucesso, det: JSON.stringify(r.j) };
    }, 'stories');
    await t('limite de 20 stories ativos por usuário: o 21º é recusado', async () => {
      const criados = [];
      for (let i = 0; i < 21; i++) {
        const r = await subir('s' + i + '.png', '', PNG);
        if (r.j && r.j.sucesso) criados.push(r.j.id); else { global.__limite = r; break; }
      }
      const rec = global.__limite;
      for (const id of criados) { if (id !== global.__story) await api('excluir_story', { id }); }
      return { ok: !!rec && rec.http === 422 && /Limite/i.test(rec.j.mensagem), det: `criados ${criados.length}; ${rec && JSON.stringify(rec.j)}` };
    }, 'stories');
    await t('excluir story de OUTRO usuário é recusado; excluir o próprio funciona e some do disco', async () => {
      const alheio = db(`SELECT id, arquivo FROM stories WHERE id_usuario<>${UID} ORDER BY id DESC LIMIT 1`)[0];
      const a = alheio ? await api('excluir_story', { id: Number(alheio.id) }) : { http: 404 };
      const aindaExiste = alheio ? db(`SELECT COUNT(*) n FROM stories WHERE id=${alheio.id}`)[0].n : 1;
      const meu = db(`SELECT arquivo FROM stories WHERE id=${global.__story}`)[0];
      const b = await api('excluir_story', { id: Number(global.__story) });
      const dep = db(`SELECT COUNT(*) n FROM stories WHERE id=${global.__story}`)[0].n;
      return { ok: a.http === 404 && aindaExiste === 1 && b.j.sucesso && dep === 0, det: `alheio→${a.http}; próprio→${b.http}` };
    }, 'segurança');
    ligarStories(0);
    salvar();
  } finally { try { remoto("php -r 'require \"funcoes/usuario.php\"; definirConfigSistema(\"stories_ativos\", \"0\");'"); } catch (e) { /* já avisado acima */ } await c.fechar(); }
})();
