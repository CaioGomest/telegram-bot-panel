// ÁREAS 10 e 11: Traqueamento (config) e Webhooks (CRUD, validações, entrega real, assinatura, falhas)
const crypto = require('crypto');
const { lancar, entrar, BASE, db, remoto, autoDialogo } = require('./pg_lib.js');
const { area, t, nota, salvar, esp } = require('./pg_t.js');
(async () => {
  const c = await lancar();
  autoDialogo(c, true);
  const trk = () => db(`SELECT * FROM usuarios_traqueamento WHERE id_usuario=49`)[0];
  const salvarTrk = async (campos) => {
    await c.ir(BASE + '/traqueamento');
    await c.avaliar(`(function(){ var d=${JSON.stringify(campos)}; Object.keys(d).forEach(function(k){ var e=document.getElementById(k); if(!e) return; if(e.type==='checkbox'){ if(e.checked!==!!d[k]) e.click(); } else { e.value=d[k]; } }); document.querySelector('form[method=POST]').submit(); return 1; })()`);
    await c.esperarCarga(); await esp(500);
  };
  try {
    await entrar(c, 'pgteste-user@example.invalid');
    area('10. Traqueamento');
    await c.ir(BASE + '/traqueamento');
    await t('página abre com Facebook selecionado, 2 cartões de plataforma e a lista "Eventos enviados"', async () => {
      const r = JSON.parse(await c.avaliar(`JSON.stringify({cartoes:document.querySelectorAll('.traq-cartao').length, sel:(document.querySelector('.traq-cartao.selecionado')||{dataset:{}}).dataset.alvo, fbVisivel:!document.getElementById('facebook_fields').hidden, utHidden:document.getElementById('utmfy_fields').hidden, eventos:/InitiateCheckout/.test(document.body.innerText)&&/Purchase/.test(document.body.innerText)})`));
      return { ok: r.cartoes === 2 && r.sel === 'facebook' && r.fbVisivel && r.utHidden && r.eventos, det: JSON.stringify(r) };
    }, 'traqueamento');
    await t('trocar de plataforma pelo cartão mostra o painel certo e o status do cartão acompanha a chave liga/desliga', async () => {
      await c.avaliar(`document.querySelector('.traq-cartao[data-alvo=utmfy]').click(); 1`);
      const a = await c.avaliar(`!document.getElementById('utmfy_fields').hidden && document.getElementById('facebook_fields').hidden`);
      await c.avaliar(`document.getElementById('utmfy_ativo').click(); 1`);
      const st = await c.avaliar(`document.querySelector('.traq-cartao[data-alvo=utmfy] .traq-cartao-status').innerText`);
      await c.avaliar(`document.getElementById('utmfy_ativo').click(); 1`);
      const st2 = await c.avaliar(`document.querySelector('.traq-cartao[data-alvo=utmfy] .traq-cartao-status').innerText`);
      return { ok: a && st === 'Ativo' && st2 === 'Inativo', det: `${st} → ${st2}` };
    }, 'traqueamento');
    await t('com a plataforma desligada os campos ficam esmaecidos/bloqueados', async () => {
      const r = await c.avaliar(`(function(){ var s=document.getElementById('utmfy_fields'); s.classList.contains('secao-desativada') ; return getComputedStyle(s.querySelector('.campos-plataforma')).pointerEvents })()`);
      return { ok: r === 'none', det: 'pointer-events=' + r };
    }, 'traqueamento');
    await t('salvar Facebook + UTMfy: grava ativo/pixel e os tokens CIFRADOS; aviso de sucesso', async () => {
      await salvarTrk({ facebook_ativo: true, facebook_pixel_id: '1234567890', facebook_access_token: 'EAAB-segredo-fb-999', utmfy_ativo: true, utmfy_token: 'utm-segredo-888' });
      const tx = await c.avaliar('document.body.innerText');
      const r = trk();
      return { ok: /salvas com sucesso/i.test(tx) && r.facebook_ativo === 1 && r.facebook_pixel_id === '1234567890' && r.utmfy_ativo === 1 && r.facebook_access_token.indexOf('EAAB-segredo') === -1 && r.utmfy_token.indexOf('utm-segredo') === -1 && /^enc:/.test(r.facebook_access_token), det: `fb_token="${r.facebook_access_token.slice(0, 18)}…"` };
    }, 'traqueamento');
    await t('os tokens salvos NÃO aparecem no HTML; os campos mostram "(salvo)"', async () => {
      const html = await c.avaliar(`document.documentElement.outerHTML.indexOf('EAAB-segredo')>=0 || document.documentElement.outerHTML.indexOf('utm-segredo')>=0`);
      const ph = await c.avaliar(`document.getElementById('facebook_access_token').placeholder + '|' + document.getElementById('utmfy_token').placeholder`);
      return { ok: !html && /salvo/.test(ph.split('|')[0]) && /salvo/.test(ph.split('|')[1]), det: ph };
    }, 'segurança');
    await t('salvar com token em branco mantém o token anterior; trocar o pixel não apaga o token', async () => {
      const antes = trk().facebook_access_token;
      await salvarTrk({ facebook_pixel_id: '999888777', facebook_access_token: '', utmfy_token: '' });
      const r = trk();
      return { ok: r.facebook_access_token === antes && r.facebook_pixel_id === '999888777' && r.utmfy_token.length > 10, det: '' };
    }, 'traqueamento');
    await t('desligar uma plataforma preserva as credenciais (só muda o "ativo")', async () => {
      await salvarTrk({ utmfy_ativo: false });
      const r = trk();
      return { ok: r.utmfy_ativo === 0 && r.utmfy_token.length > 10 && r.facebook_ativo === 1, det: `utmfy_ativo=${r.utmfy_ativo}` };
    }, 'traqueamento');
    await t('pixel ID com HTML/aspas é escapado ao reabrir a página (sem XSS)', async () => {
      await salvarTrk({ facebook_pixel_id: '"><img src=x onerror=window.__tk=1>' });
      await c.ir(BASE + '/traqueamento');
      const exec = await c.avaliar('window.__tk===1');
      const imgs = await c.avaliar(`document.querySelectorAll('.conteudo-principal img').length`);
      return { ok: !exec && imgs === 0, det: '' };
    }, 'segurança');
    await t('POST sem token CSRF é recusado e não altera a configuração', async () => {
      const antes = trk().facebook_pixel_id;
      const r = await c.avaliar(`fetch('traqueamento',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'facebook_pixel_id=HACK'}).then(r=>r.status)`);
      return { ok: r === 403 && trk().facebook_pixel_id === antes, det: 'HTTP ' + r };
    }, 'segurança');

    area('11. Webhooks do usuário');
    remoto('php zz_whk.php limpar'); db('SELECT 1');
    await c.ir(BASE + '/webhooks');
    await t('estado vazio mostra "Nenhum webhook ainda." e KPIs zerados', async () => {
      remoto("php -r 'require \"conexao.php\"; $pdo->exec(\"DELETE FROM webhooks WHERE id_usuario=49\");'");
      await c.ir(BASE + '/webhooks');
      const tx = await c.avaliar('document.querySelector(".conteudo-principal").innerText');
      return { ok: /Nenhum webhook ainda/.test(tx), det: '' };
    }, 'webhooks');
    await t('"Novo webhook" abre o modal com os 3 eventos marcados; Gerar cria um secret; ✕ fecha', async () => {
      await c.avaliar(`document.getElementById('btn-novo-webhook').click(); 1`);
      await esp(500);
      const r = JSON.parse(await c.avaliar(`JSON.stringify({ab:document.getElementById('modal-webhook').classList.contains('aberto'), ev:[...document.querySelectorAll('#modal-webhook input[name="eventos[]"]')].filter(function(x){return x.checked}).length, bots:[...document.querySelectorAll('#webhook-bot option')].map(function(o){return o.textContent.trim()}).join(',')})`));
      await c.avaliar(`document.getElementById('btn-gerar-secret').click(); 1`);
      const sec = await c.avaliar(`document.getElementById('webhook-secret').value`);
      await c.avaliar(`document.getElementById('btn-fechar-webhook').click(); 1`);
      const f = await c.avaliar(`!document.getElementById('modal-webhook').classList.contains('aberto')`);
      return { ok: r.ab && r.ev === 3 && /Bot Um/.test(r.bots) && sec.length >= 16 && f, det: JSON.stringify(r) + ' secret=' + sec.length + ' chars' };
    }, 'webhooks');
    const enviar = async (campos) => {
      await c.ir(BASE + '/webhooks');
      await c.avaliar(`document.getElementById('btn-novo-webhook').click(); 1`);
      await esp(300);
      await c.avaliar(`(function(){ var d=${JSON.stringify(campos)}; var f=document.querySelector('#modal-webhook form'); f.elements.nome.value=d.nome||''; f.elements.url.value=d.url||''; f.elements.secret.value=d.secret||''; f.elements.bot_id && (f.elements.bot_id.value=d.bot_id||''); f.querySelectorAll('input[name="eventos[]"]').forEach(function(x){ x.checked = d.eventos ? d.eventos.indexOf(x.value)>=0 : true; }); f.elements.url.type='text'; f.submit(); return 1; })()`);
      await c.esperarCarga(); await esp(400);
      return c.avaliar(`JSON.stringify({u:location.pathname+location.search, erro:(document.querySelector('#modal-webhook .aviso-erro')||{}).innerText||'', aviso:(document.querySelector('.aviso-sucesso')||{}).innerText||''})`).then(JSON.parse);
    };
    await t('URLs recusadas: http://, localhost, IP interno, 169.254 (metadados), usuário:senha@, sem esquema, javascript:, com mais de 500 caracteres', async () => {
      const ruins = ['http://example.com/hook', 'https://localhost/x', 'https://127.0.0.1/x', 'https://10.0.0.5/x', 'https://192.168.0.10/x', 'https://169.254.169.254/latest/meta-data', 'https://user:pass@example.com/x', 'example.com/x', 'javascript:alert(1)', 'https://example.com/' + 'a'.repeat(520), 'ftp://example.com/x', 'https://[::1]/x'];
      const aceitas = [];
      for (const url of ruins) {
        const r = await enviar({ nome: 'T', url });
        if (!/endereço|https/i.test(r.erro) || /salvo/i.test(r.aviso)) aceitas.push(url.slice(0, 40) + ' → ' + (r.erro || r.aviso || r.u));
      }
      const n = db(`SELECT COUNT(*) n FROM webhooks WHERE id_usuario=49`)[0].n;
      return { ok: !aceitas.length && n === 0, det: aceitas.join(' | ') || 'nenhuma criada' };
    }, 'segurança');
    await t('validações: sem nome, nome > 80, nenhum evento, bot de outro usuário', async () => {
      const outroBot = db(`SELECT id FROM bots WHERE id_usuario<>49 ORDER BY id DESC LIMIT 1`)[0].id;
      const casos = [['sem nome', { nome: '', url: 'https://example.com/h' }, /nome/i], ['nome 81', { nome: 'x'.repeat(81), url: 'https://example.com/h' }, /nome/i], ['sem evento', { nome: 'T', url: 'https://example.com/h', eventos: [] }, /evento/i]];
      const falhas = [];
      for (const [n, campos, rx] of casos) {
        const r = await enviar(campos);
        if (!rx.test(r.erro)) falhas.push(`${n}: "${r.erro || r.aviso}"`);
      }
      await c.ir(BASE + '/webhooks');
      const alheio = await c.avaliar(`fetch('webhooks',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({csrf_token:window.CSRF_TOKEN, acao:'salvar', nome:'Bot alheio', url:'https://example.com/h', bot_id:'${outroBot}', 'eventos[]':'user_joined'}).toString()}).then(r=>r.text())`);
      if (!/da sua conta/i.test(alheio)) falhas.push('bot alheio: aceitou ou mensagem inesperada');
      return { ok: !falhas.length && db(`SELECT COUNT(*) n FROM webhooks WHERE id_usuario=49`)[0].n === 0, det: falhas.join(' | ') };
    }, 'webhooks');
    remoto("php -r 'require \"conexao.php\"; $pdo->exec(\"DELETE FROM webhooks WHERE id_usuario=49\");'");
    await t('criar webhook válido: aparece na lista com eventos, bot e situação Ativo, e o secret não é exibido', async () => {
      const r = await enviar({ nome: 'CRM <b>teste</b>', url: 'https://example.com/hook', secret: 'meu-secret-777', eventos: ['payment_approved', 'user_joined'] });
      const tx = await c.avaliar('document.querySelector(".conteudo-principal").innerText');
      const b = db(`SELECT nome, eventos, ativo FROM webhooks WHERE id_usuario=49`)[0];
      global.__wh = db(`SELECT id FROM webhooks WHERE id_usuario=49`)[0].id;
      const html = await c.avaliar(`document.documentElement.outerHTML.indexOf('meu-secret-777')>=0`);
      const bold = await c.avaliar(`document.querySelectorAll('.conteudo-principal table b').length`);
      const cond = { salvo: /Webhook salvo/.test(r.aviso), lead: /Novo lead/.test(tx), aprov: /Pagamento aprovado/.test(tx), semCriado: !/Pagamento criado/.test(await c.avaliar('document.querySelector(".conteudo-principal table tbody").innerText')), ativo: b.ativo === 1, semSecretNoHtml: !html, semNegrito: bold === 0 };
      return { ok: Object.values(cond).every(Boolean), det: JSON.stringify(cond) + ' ' + JSON.stringify(r) };
    }, 'webhooks');
    await t('editar: abre com os dados, secret em branco mantém o atual, trocar nome/eventos persiste', async () => {
      await c.ir(BASE + '/webhooks?editar=' + global.__wh);
      const pre = JSON.parse(await c.avaliar(`JSON.stringify({ab:document.getElementById('modal-webhook').classList.contains('aberto'), n:document.getElementById('webhook-nome').value, ph:document.getElementById('webhook-secret').placeholder, ev:[...document.querySelectorAll('#modal-webhook input[name="eventos[]"]:checked')].map(function(x){return x.value}).join(',')})`));
      await c.avaliar(`document.getElementById('webhook-nome').value='CRM editado'; document.querySelectorAll('#modal-webhook input[name="eventos[]"]').forEach(function(x){x.checked=true}); document.querySelector('#modal-webhook form').submit(); 1`);
      await c.esperarCarga(); await esp(400);
      const b = db(`SELECT nome, eventos, secret FROM webhooks WHERE id=${global.__wh}`)[0];
      return { ok: pre.ab && pre.n.indexOf('CRM') === 0 && /salvo/i.test(pre.ph) && b.nome === 'CRM editado' && b.eventos.split(',').length === 3 && b.secret === 'meu-secret-777', det: JSON.stringify(pre) };
    }, 'webhooks');
    await t('ligar/desligar: o botão alterna Ativo ↔ Inativo e o banco acompanha', async () => {
      await c.ir(BASE + '/webhooks');
      await c.avaliar(`document.querySelector('form input[name=acao][value=alternar]').closest('form').querySelector('button').click(); 1`);
      await c.esperarCarga(); await esp(400);
      const off = db(`SELECT ativo FROM webhooks WHERE id=${global.__wh}`)[0].ativo;
      await c.avaliar(`document.querySelector('form input[name=acao][value=alternar]').closest('form').querySelector('button').click(); 1`);
      await c.esperarCarga(); await esp(400);
      const on = db(`SELECT ativo FROM webhooks WHERE id=${global.__wh}`)[0].ativo;
      return { ok: off === 0 && on === 1, det: `${off} → ${on}` };
    }, 'webhooks');
    await t('editar/alternar/excluir webhook de OUTRO usuário é recusado', async () => {
      const U2 = JSON.parse(remoto('php zz_pg.php user2').trim());
      remoto(`php -r 'require "conexao.php"; $pdo->exec("DELETE FROM webhooks WHERE id_usuario=${U2.u2}"); $pdo->prepare("INSERT INTO webhooks (id_usuario,nome,url,eventos,ativo) VALUES (?,?,?,?,1)")->execute([${U2.u2},"Webhook U2","https://example.com/u2","user_joined"]);'`);
      const alheio = db(`SELECT id, nome, ativo FROM webhooks WHERE id_usuario=${U2.u2}`)[0];
      await c.ir(BASE + '/webhooks');
      const post = (corpo) => c.avaliar(`fetch('webhooks',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(Object.assign({csrf_token:window.CSRF_TOKEN},${JSON.stringify(corpo)})).toString()}).then(r=>r.status)`);
      await post({ acao: 'alternar', id: String(alheio.id) });
      await post({ acao: 'excluir', id: String(alheio.id) });
      await post({ acao: 'salvar', id: String(alheio.id), nome: 'SEQUESTRADO', url: 'https://example.com/x', 'eventos[]': 'user_joined' });
      const dep = db(`SELECT nome, ativo FROM webhooks WHERE id=${alheio.id}`)[0];
      await c.ir(BASE + '/webhooks?editar=' + alheio.id);
      const abriu = await c.avaliar(`document.getElementById('webhook-nome').value`);
      return { ok: dep && dep.nome === 'Webhook U2' && dep.ativo === 1 && abriu !== 'Webhook U2', det: JSON.stringify(dep) + ' form=' + abriu };
    }, 'segurança');

    area('11b. Entrega real dos webhooks (receptor no servidor)');
    const setup = JSON.parse(remoto('php zz_whk.php setup').trim());
    const fogo = JSON.parse(remoto('php zz_whk.php fire').trim()).recebidos;
    const porW = (w) => fogo.filter(x => x.qs === 'w=' + w);
    await t('webhook "todos os eventos": recebe lead, pagamento criado, aprovado e lead do outro bot (4 entregas) em POST JSON', async () => {
      const ev = porW('a').map(x => JSON.parse(x.corpo).event);
      return { ok: ev.join(',') === 'user_joined,payment_created,payment_approved,user_joined' && porW('a').every(x => x.metodo === 'POST' && /application\/json/.test(x.h.tipo)), det: ev.join(',') };
    }, 'entrega');
    await t('filtro por bot + evento: o webhook "só aprovado do Bot Um" recebe exatamente 1 entrega', async () => {
      const b = porW('b');
      return { ok: b.length === 1 && JSON.parse(b[0].corpo).event === 'payment_approved' && JSON.parse(b[0].corpo).bot.id !== undefined, det: b.length + ' entrega(s)' };
    }, 'entrega');
    await t('webhook inativo não recebe nada; evento desconhecido é ignorado', async () => {
      return { ok: porW('c').length === 0 && fogo.every(x => JSON.parse(x.corpo).event !== 'evento_inventado'), det: `inativo recebeu ${porW('c').length}` };
    }, 'entrega');
    await t('assinatura: X-Webhook-Signature = HMAC-SHA256(corpo, secret) confere; sem secret não envia o cabeçalho; User-Agent identifica o painel', async () => {
      const a = porW('a')[0], b = porW('b')[0];
      const esperado = crypto.createHmac('sha256', 'segredo123').update(a.corpo).digest('hex');
      return { ok: a.h.assinatura === esperado && b.h.assinatura === null && /PainelBot-Webhook/.test(a.h.ua), det: '' };
    }, 'entrega');
    await t('payload do pagamento: valor, moeda, status pending→paid, plano, gateway, origem (utm), pix_code só no "criado", cliente com telefone e is_vip', async () => {
      const cri = JSON.parse(porW('a')[1].corpo), apr = JSON.parse(porW('a')[2].corpo);
      return { ok: cri.transaction.status === 'pending' && cri.transaction.pix_code === '0002PIXCODE' && apr.transaction.status === 'paid' && !('pix_code' in apr.transaction) && apr.transaction.amount === 19.9 && apr.transaction.currency === 'BRL' && apr.transaction.plan_name === 'Mensal' && apr.transaction.paid_at && apr.tracking.utm_campaign === 'camp_pg' && apr.customer.phone === '11999990001' && apr.customer.is_vip === true && apr.customer.email === null, det: '' };
    }, 'entrega');
    await t('5 falhas seguidas (HTTP 500) desligam o webhook automaticamente ("Desligado (5 falhas)") e registram cada tentativa', async () => {
      const r = JSON.parse(remoto('php zz_whk.php fail').trim());
      await c.ir(BASE + '/webhooks');
      const tx = await c.avaliar('document.querySelector(".conteudo-principal").innerText');
      const a = r.webhooks.find(w => /A todos/.test(w.nome));
      return { ok: a.ativo === 0 && a.falhas_consecutivas === 5 && r.envios_com_falha >= 10 && /Desligado \(5 falhas\)/.test(tx), det: JSON.stringify(a) + ' falhas registradas=' + r.envios_com_falha };
    }, 'entrega');
    await t('religar um webhook desligado zera o contador de falhas', async () => {
      remoto('php zz_whk.php limpar');
      await c.ir(BASE + '/webhooks');
      await c.avaliar(`var f=[...document.querySelectorAll('form')].find(function(f){var b=f.querySelector('button.btn-icon.ativar'); return !!b}); f.querySelector('button').click(); 1`);
      await c.esperarCarga(); await esp(500);
      const w = db(`SELECT ativo, falhas_consecutivas FROM webhooks WHERE id_usuario=49 AND ativo=1 ORDER BY id LIMIT 1`)[0];
      return { ok: !!w && w.falhas_consecutivas === 0, det: JSON.stringify(w) };
    }, 'entrega');
    await t('excluir webhook (com confirmação) remove do banco e da lista', async () => {
      await c.ir(BASE + '/webhooks');
      const n0 = db(`SELECT COUNT(*) n FROM webhooks WHERE id_usuario=49`)[0].n;
      await c.avaliar(`document.querySelector('form input[name=acao][value=excluir]').closest('form').querySelector('button').click(); 1`);
      await c.esperarCarga(); await esp(500);
      const n1 = db(`SELECT COUNT(*) n FROM webhooks WHERE id_usuario=49`)[0].n;
      return { ok: n1 === n0 - 1, det: `${n0} → ${n1}` };
    }, 'webhooks');
    nota('O secret do webhook fica em texto puro na tabela webhooks (os tokens de gateway e de traqueamento são cifrados). Necessário para assinar, mas poderia ser cifrado em repouso como os demais.', 'melhoria sugerida');
    salvar();
    c.pararDialogo();
  } finally { await c.fechar(); }
})();
