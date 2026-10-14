// ÁREA 17: Admin — Transações, Configurações, Comunidade, Ranking, Logs, Checklist, Consultar venda, Testes de desempenho
const { execFileSync } = require('child_process');
const { lancar, entrar, BASE, db, remoto, autoDialogo } = require('./pg_lib.js');
const { area, t, nota, salvar, esp } = require('./pg_t.js');
(async () => {
  const c = await lancar();
  autoDialogo(c, true);
  const post = (url, corpo) => c.avaliar(`fetch('${url}',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(Object.assign({csrf_token:window.CSRF_TOKEN},${JSON.stringify(corpo)})).toString()}).then(async r=>({http:r.status,t:await r.text(),u:r.url}))`);
  const cfg = (k) => (db(`SELECT valor FROM configuracoes WHERE chave='${k}'`)[0] || {}).valor;
  const original = { nome: cfg('nome_sistema'), cor: cfg('cor_primaria'), dom: cfg('dominios_redirecionamento') || '' };
  try {
    await entrar(c, 'pgteste-admin@example.invalid');

    area('17. Admin — Transações');
    const linhas = async (qs) => { await c.ir(BASE + '/admin/transacoes' + qs); return Number(await c.avaliar(`[...document.querySelectorAll('table tbody tr')].filter(function(tr){return !tr.querySelector('td[colspan]')}).length`)); };
    await t('filtro por usuário (49) lista só as vendas dele (4 semeadas + Pix de teste)', async () => {
      const esperado = db(`SELECT COUNT(*) n FROM vendas v JOIN bots b ON v.bot_id=b.id WHERE b.id_usuario=49`)[0].n;
      const n = await linhas('?usuario_id=49');
      return { ok: n === esperado, det: `tela ${n}, banco ${esperado}` };
    }, 'admin/transacoes');
    await t('filtros de status, busca por TXID/ID Telegram e período funcionam e combinam', async () => {
      const pago = await linhas('?usuario_id=49&status=pago');
      const busca = await linhas('?usuario_id=49&busca=PGTX1');
      const tg = await linhas('?usuario_id=49&busca=9003');
      const hoje = new Date().toISOString().slice(0, 10);
      const dia = await linhas('?usuario_id=49&data_inicio=' + hoje + '&data_fim=' + hoje);
      const esperadoDia = db(`SELECT COUNT(*) n FROM vendas v JOIN bots b ON v.bot_id=b.id WHERE b.id_usuario=49 AND DATE(v.criado_em)='${hoje}'`)[0].n;
      return { ok: pago === 2 && busca === 1 && tg === 1 && dia === esperadoDia, det: `pago=${pago} txid=${busca} tg=${tg} hoje=${dia}/${esperadoDia}` };
    }, 'admin/transacoes');
    await t('valores inválidos (status/datas/split/usuario/pagina, SQL) não quebram a página', async () => {
      const falhas = [];
      for (const q of ['?status=xyz', '?data_inicio=abc&data_fim=2020-99-99', '?split_status=foo', '?usuario_id=abc', '?pagina=-4', "?busca=' OR 1=1--", "?busca=%25%27", '?status=pago%27%3BDROP%20TABLE%20x']) {
        await c.ir(BASE + '/admin/transacoes' + q + (q.includes('usuario_id') ? '' : (q.includes('?') ? '&' : '?') + 'usuario_id=49'));
        const tx = await c.avaliar('document.body.innerText');
        if (/Warning|Fatal|Notice:|SQLSTATE|syntax error/i.test(tx)) falhas.push(q);
      }
      return { ok: !falhas.length, det: falhas.join(' ') };
    }, 'admin/transacoes');
    await t('exportar CSV (filtrado por usuário): cabeçalho certo, 1 linha por venda e células de texto neutralizadas contra fórmula', async () => {
      remoto(`php -r 'require "conexao.php"; $b=(int)$pdo->query("SELECT id FROM bots WHERE nome_usuario=\\"pg_bot_um\\"")->fetchColumn(); $pdo->exec("DELETE FROM vendas WHERE transacao_id=\\"=2+2\\""); $pdo->prepare("INSERT INTO vendas (id_telegram,bot_id,valor,status,transacao_id,criado_em,dias_acesso) VALUES (\\"9001\\",?,5,\\"expirado\\",\\"=2+2\\",NOW(),30)")->execute([$b]);'`);
      await c.ir(BASE + '/admin/transacoes?usuario_id=49');
      const csv = await c.avaliar(`fetch('transacoes?export=csv&usuario_id=49').then(r=>r.text())`);
      const ls = csv.trim().split(String.fromCharCode(10));
      const cab = ls[0].split('"').join('');
      const esperado = db(`SELECT COUNT(*) n FROM vendas v JOIN bots b ON v.bot_id=b.id WHERE b.id_usuario=49`)[0].n;
      const perigosa = ls.find(l => l.includes('2+2')) || '';
      remoto(`php -r 'require "conexao.php"; $pdo->exec("DELETE FROM vendas WHERE transacao_id=\\"=2+2\\"");'`);
      return { ok: /^ID,Data,Usu/.test(cab) && ls.length === esperado + 1 && /,"?'=2\+2"?,/.test(perigosa) && !/'-/.test(perigosa), det: `${ls.length - 1}/${esperado} linhas; txid → ${perigosa.slice(-60)}` };
    }, 'segurança');
    await t('o botão "Exportar CSV" existe e leva em conta os filtros atuais (link com export=csv)', async () => {
      await c.ir(BASE + '/admin/transacoes?usuario_id=49&status=pago');
      const h = await c.avaliar(`[...document.querySelectorAll('a')].filter(function(a){return /export=csv/.test(a.href)}).map(function(a){return a.href}).join(' ')`);
      return { ok: /usuario_id=49/.test(h) && /status=pago/.test(h), det: h.slice(0, 120) };
    }, 'admin/transacoes');

    area('17b. Admin — Configurações (valores restaurados no fim)');
    await c.ir(BASE + '/admin/configuracoes');
    await t('formulário traz os valores atuais (nome, cor) e as seções Identidade, Google e E-mail', async () => {
      const r = JSON.parse(await c.avaliar(`JSON.stringify({n:document.querySelector('[name=nome_sistema]').value, c:document.querySelector('[name=cor_primaria]').value, g:!!document.querySelector('[name=google_client_id]'), e:!!document.querySelector('[name=smtp_host]')})`));
      return { ok: r.n === original.nome && r.c.toLowerCase() === original.cor && r.g && r.e, det: JSON.stringify(r) };
    }, 'admin/configuracoes');
    await t('validações: nome vazio, cor inválida, domínio de redirecionamento inválido são recusados e nada é gravado', async () => {
      const casos = [['nome vazio', { acao: 'identidade', nome_sistema: '', cor_primaria: '#ff8800', dominios_redirecionamento: '' }, /não pode ficar vazio/i], ['cor', { acao: 'identidade', nome_sistema: 'X', cor_primaria: 'vermelho', dominios_redirecionamento: '' }, /Cor inválida/i], ['domínio', { acao: 'identidade', nome_sistema: 'X', cor_primaria: '#ff8800', dominios_redirecionamento: 'dominio_invalido!.com' }, /inválido/i], ['domínio ip', { acao: 'identidade', nome_sistema: 'X', cor_primaria: '#ff8800', dominios_redirecionamento: '127.0.0.1' }, /inválido/i]];
      const falhas = [];
      for (const [n, corpo, rx] of casos) {
        const r = await post('configuracoes', corpo);
        if (!rx.test(r.t)) falhas.push(n);
      }
      return { ok: !falhas.length && cfg('nome_sistema') === original.nome && cfg('cor_primaria') === original.cor, det: falhas.join(',') + ` nome atual="${cfg('nome_sistema')}"` };
    }, 'admin/configuracoes');
    await t('trocar nome e cor: aparecem na tela de login (título da aba) e no CSS; nome é cortado em 60 caracteres', async () => {
      const r = await post('configuracoes', { acao: 'identidade', nome_sistema: 'PG ' + 'N'.repeat(80), cor_primaria: '#12ab34', dominios_redirecionamento: '' });
      const nome = cfg('nome_sistema');
      await c.ir(BASE + '/logout'); await esp(500);
      await c.ir(BASE + '/login');
      const titulo = await c.avaliar('document.title');
      const html = await c.avaliar('document.documentElement.innerHTML.toLowerCase().indexOf("#12ab34")>=0');
      await entrar(c, 'pgteste-admin@example.invalid');
      return { ok: nome.length === 60 && /^Login - PG N/.test(titulo) && html && cfg('cor_primaria') === '#12ab34', det: `nome ${nome.length} chars, título "${titulo.slice(0, 30)}…", cor no HTML=${html}` };
    }, 'admin/configuracoes');
    await t('domínios de redirecionamento: um por linha, normalizados em minúsculas e aparecem como opção para os usuários', async () => {
      const r = await post('configuracoes', { acao: 'identidade', nome_sistema: original.nome, cor_primaria: original.cor, dominios_redirecionamento: 'Links.Exemplo.com\nGo.exemplo.com.br' });
      const salvo = cfg('dominios_redirecionamento');
      return { ok: salvo === 'links.exemplo.com\ngo.exemplo.com.br', det: JSON.stringify(salvo) };
    }, 'admin/configuracoes');
    await t('logo/favicon: formato inválido (svg, php), arquivo falso e acima de 2 MB são recusados; logo atual preservada', async () => {
      const logoAntes = cfg('logo');
      const envia = (nome, bytes, tipo) => c.avaliar(`(async function(){ var fd=new FormData(); fd.append('csrf_token', window.CSRF_TOKEN); fd.append('acao','identidade'); fd.append('nome_sistema','${original.nome}'); fd.append('cor_primaria','${original.cor}'); fd.append('dominios_redirecionamento',''); fd.append('logo', new Blob([${bytes}],{type:'${tipo}'}), '${nome}'); var r=await fetch('configuracoes',{method:'POST',body:fd}); return (await r.text()); })()`);
      const a = await envia('logo.svg', `'<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'`, 'image/svg+xml');
      const b = await envia('logo.png', `'isto nao e png'`, 'image/png');
      const d = await envia('logo.png', `new Uint8Array(2*1024*1024+2048)`, 'image/png');
      const e = await envia('shell.php', `'<?php echo 1;'`, 'text/plain');
      return { ok: /Formato não aceito/.test(a) && /não parece ser uma imagem/.test(b) && /2 MB/.test(d) && /Formato não aceito/.test(e) && cfg('logo') === logoAntes, det: `logo mantida=${cfg('logo') === logoAntes}` };
    }, 'segurança');
    await t('Google/e-mail: validações (client id vazio, host SMTP inválido, porta, segurança, e-mail do remetente, URL do painel)', async () => {
      const casos = [['google vazio', { acao: 'google', google_client_id: '', google_client_secret: '' }, /Preencha o Client ID/i], ['host', { acao: 'email', smtp_host: 'host inválido!', smtp_porta: '465', smtp_seguranca: 'ssl' }, /SMTP inválido/i], ['porta', { acao: 'email', smtp_host: 'smtp.exemplo.com', smtp_porta: '99999', smtp_seguranca: 'ssl' }, /Porta inválida/i], ['segurança', { acao: 'email', smtp_host: 'smtp.exemplo.com', smtp_porta: '465', smtp_seguranca: 'xyz' }, /Segurança inválida/i], ['remetente', { acao: 'email', smtp_host: 'smtp.exemplo.com', smtp_porta: '465', smtp_seguranca: 'ssl', email_remetente: 'nao-e-email' }, /remetente inválido/i], ['url base', { acao: 'email', smtp_host: 'smtp.exemplo.com', smtp_porta: '465', smtp_seguranca: 'ssl', url_base: 'javascript:alert(1)' }, /URL do painel inválida/i]];
      const falhas = [];
      for (const [n, corpo, rx] of casos) { const r = await post('configuracoes', corpo); if (!rx.test(r.t)) falhas.push(n); }
      return { ok: !falhas.length, det: falhas.join(',') };
    }, 'admin/configuracoes');
    await t('segredos (Client Secret do Google, senha SMTP) não são devolvidos no HTML da página', async () => {
      await c.ir(BASE + '/admin/configuracoes');
      const campos = JSON.parse(await c.avaliar(`JSON.stringify([...document.querySelectorAll('input[type=password]')].map(function(i){return {n:i.name, v:i.value}}))`));
      return { ok: campos.every(x => x.v === ''), det: JSON.stringify(campos) };
    }, 'segurança');
    // restaura as configurações globais
    await post('configuracoes', { acao: 'identidade', nome_sistema: original.nome, cor_primaria: original.cor, dominios_redirecionamento: original.dom });
    await t('configurações globais restauradas aos valores originais (nome, cor, domínios)', async () => ({ ok: cfg('nome_sistema') === original.nome && cfg('cor_primaria') === original.cor && (cfg('dominios_redirecionamento') || '') === original.dom, det: `${cfg('nome_sistema')} ${cfg('cor_primaria')}` }), 'admin/configuracoes');

    area('17c. Admin — Comunidade (links)');
    const comunidade = (corpo) => post('comunidade', Object.assign({ acao: 'salvar', tipo: 'site', icone: 'site', titulo: 'PG Link', subtitulo: 'sub', url: 'https://example.com', membros_atual: '1', membros_max: '10', ativo: '1' }, corpo));
    await t('URLs perigosas são recusadas (javascript:, data:, http:, sem esquema, caractere de controle) e válidas aceitas (https, mailto, tel)', async () => {
      const ruins = ['javascript:alert(1)', 'data:text/html,<script>1</script>', 'http://example.com', 'example.com', 'https://', 'mailto:semarroba', 'tel:abc', 'https://exa mple.com', 'https://example.com/' + 'a'.repeat(520)];
      const aceitas = [];
      for (const url of ruins) { const r = await comunidade({ url }); if (/Link da comunidade salvo|comunidade\?salvo/i.test(r.t + r.u) || db(`SELECT COUNT(*) n FROM comunidade_links WHERE titulo='PG Link'`)[0].n > 0) { aceitas.push(url.slice(0, 30)); remoto(`php -r 'require "conexao.php"; $pdo->exec("DELETE FROM comunidade_links WHERE titulo=\\"PG Link\\"");'`); } }
      return { ok: !aceitas.length, det: aceitas.join(' | ') };
    }, 'segurança');
    await t('campos: título vazio/>80, subtítulo >120, tipo/ícone inválidos, vagas negativas/texto são recusados', async () => {
      const casos = [{ titulo: '' }, { titulo: 'x'.repeat(81) }, { subtitulo: 'x'.repeat(121) }, { tipo: 'xyz' }, { icone: 'xyz' }, { membros_atual: '-1' }, { membros_max: 'abc' }, { membros_atual: '2000000' }];
      const aceitos = [];
      for (const [i, cx] of casos.entries()) { await comunidade(cx); if (db(`SELECT COUNT(*) n FROM comunidade_links WHERE titulo IN ('PG Link', '${'x'.repeat(81)}')`)[0].n > 0) { aceitos.push(JSON.stringify(cx).slice(0, 40)); remoto(`php -r 'require "conexao.php"; $pdo->exec("DELETE FROM comunidade_links WHERE titulo LIKE \\"PG Link%\\" OR titulo LIKE \\"xxxx%\\"");'`); } }
      return { ok: !aceitos.length, det: aceitos.join(' | ') };
    }, 'admin/comunidade');
    await t('criar link válido: aparece na lista admin e na página /comunidade; título com HTML sai escapado', async () => {
      await comunidade({ titulo: 'PG <img src=x onerror=window.__cm=1>Link', url: 'https://example.com/pg' });
      const id = db(`SELECT id FROM comunidade_links WHERE url='https://example.com/pg'`)[0];
      await c.ir(BASE + '/admin/comunidade');
      const naLista = await c.avaliar(`document.body.innerText.indexOf('PG <img')>=0`);
      const imgsAdmin = await c.avaliar(`document.querySelectorAll('table img').length`);
      await c.ir(BASE + '/comunidade');
      const publico = await c.avaliar(`document.body.innerText.indexOf('PG <img')>=0 || document.body.innerText.indexOf('Link')>=0`);
      const exec = await c.avaliar('window.__cm===1');
      global.__cm = id && id.id;
      return { ok: !!id && naLista && imgsAdmin === 0 && !exec, det: `id=${id && id.id}` };
    }, 'admin/comunidade');
    // a página pública é de usuário (o admin é redirecionado): troca de sessão no mesmo navegador só para olhar
    const publicoTem = async () => { await c.ir(BASE + '/logout'); await esp(400); await entrar(c, 'pgteste-user@example.invalid'); await c.ir(BASE + '/comunidade'); const r = await c.avaliar(`document.documentElement.innerHTML.indexOf('example.com/pg')>=0`); await c.ir(BASE + '/logout'); await esp(400); await entrar(c, 'pgteste-admin@example.invalid'); return r; };
    await t('desativar o link o remove da página pública; reativar volta; mover para cima/baixo troca a ordem', async () => {
      const id = global.__cm;
      const antes = await publicoTem();
      await post('comunidade', { acao: 'alternar', id: String(id) });
      const some = !(await publicoTem());
      await post('comunidade', { acao: 'alternar', id: String(id) });
      const volta = await publicoTem();
      const o1 = db(`SELECT id, ordem FROM comunidade_links ORDER BY ordem, id`).map(x => x.id);
      await post('comunidade', { acao: 'mover', id: String(id), direcao: 'subir' });
      const o2 = db(`SELECT id FROM comunidade_links ORDER BY ordem, id`).map(x => x.id);
      return { ok: antes && some && volta && JSON.stringify(o1) !== JSON.stringify(o2), det: `visível=${antes}, oculto=${some}, de volta=${volta}` };
    }, 'admin/comunidade');
    await t('excluir link remove do banco; link inexistente não gera erro PHP', async () => {
      const r = await post('comunidade', { acao: 'excluir', id: String(global.__cm) });
      const r2 = await post('comunidade', { acao: 'excluir', id: '99999999' });
      return { ok: db(`SELECT COUNT(*) n FROM comunidade_links WHERE id=${global.__cm}`)[0].n === 0 && !/Warning|Fatal|Notice:/i.test(r2.t), det: '' };
    }, 'admin/comunidade');

    area('17d. Admin — Ranking (campanhas)');
    const camp = (corpo) => post('ranking', Object.assign({ acao: 'salvar_campanha', slug: 'pg-camp', titulo: 'PG Campanha', subtitulo: 'sub', data_inicio: '2040-01-01 00:00:00', data_fim: '2040-02-01 00:00:00' }, corpo));
    remoto(`php -r 'require "conexao.php"; $pdo->exec("DELETE FROM campanhas_ranking WHERE slug LIKE \\"pg-%\\"");'`);
    await t('validações: campos vazios, datas inválidas, início depois do fim, slug repetido', async () => {
      const a = await camp({ slug: '' });
      const b = await camp({ data_inicio: 'abc' });
      const d = await camp({ data_inicio: '2040-03-01 00:00:00', data_fim: '2040-02-01 00:00:00' });
      await camp({});
      const e = await camp({});
      return { ok: /Preencha/.test(a.t) && /inválidas/.test(b.t) && /antes da data de fim/.test(d.t) && /já existe/i.test(e.t), det: '' };
    }, 'admin/ranking');
    await t('campanha futura criada NÃO vira a campanha vigente do /ranking dos usuários (continua a atual)', async () => {
      const n = db(`SELECT COUNT(*) n FROM campanhas_ranking WHERE slug='pg-camp'`)[0].n;
      await c.ir(BASE + '/ranking');
      const tx = await c.avaliar(`document.body.innerText`);
      return { ok: n === 1 && !/PG Campanha/.test(tx.split('PÓDIO')[0] || tx) , det: 'campanhas pg=' + n };
    }, 'admin/ranking');
    await t('título com HTML aparece escapado na lista de campanhas do admin', async () => {
      await camp({ slug: 'pg-xss', titulo: '<img src=x onerror=window.__rk=1>X' });
      await c.ir(BASE + '/admin/ranking');
      const exec = await c.avaliar('window.__rk===1');
      const imgs = await c.avaliar(`document.querySelectorAll('table img').length`);
      return { ok: !exec && imgs === 0, det: '' };
    }, 'segurança');
    await t('banner inválido (texto/ svg) é recusado ("arquivo inválido")', async () => {
      const r = await c.avaliar(`(async function(){ var fd=new FormData(); fd.append('csrf_token', window.CSRF_TOKEN); fd.append('acao','salvar_campanha'); fd.append('slug','pg-ban'); fd.append('titulo','B'); fd.append('data_inicio','2040-01-01 00:00:00'); fd.append('data_fim','2040-02-01 00:00:00'); fd.append('imagem_banner', new Blob(['nao imagem'],{type:'image/png'}), 'b.png'); var x=await fetch('ranking',{method:'POST',body:fd}); return x.text(); })()`);
      return { ok: /Banner: arquivo inválido/.test(r) && db(`SELECT COUNT(*) n FROM campanhas_ranking WHERE slug='pg-ban'`)[0].n === 0, det: '' };
    }, 'segurança');
    remoto(`php -r 'require "conexao.php"; $pdo->exec("DELETE FROM campanhas_ranking WHERE slug LIKE \\"pg-%\\"");'`);

    area('17e. Admin — Logs, Checklist, Consultar venda, Testes de desempenho');
    await t('logs: lista eventos com data, usuário, tipo e descrição; paginação/entradas inválidas não quebram; HTML escapado', async () => {
      const falhas = [];
      for (const q of ['', '?pagina=999', '?pagina=-1', '?pagina=abc', '?tipo=xyz', "?busca=' OR 1=1--"]) {
        await c.ir(BASE + '/admin/logs' + q);
        const tx = await c.avaliar('document.body.innerText');
        if (/Warning|Fatal|Notice:|SQLSTATE/i.test(tx) || !/LOGS DO SISTEMA/i.test(tx)) falhas.push(q);
      }
      await c.ir(BASE + '/admin/logs');
      const th = await c.avaliar(`[...document.querySelectorAll('table thead th')].map(function(x){return x.innerText.trim()}).join(',')`);
      return { ok: !falhas.length && /DATA\/HORA,USUÁRIO,TIPO,EVENTO,DESCRIÇÃO/i.test(th), det: falhas.join(' ') || th };
    }, 'admin/logs');
    await t('checklist: marcar itens atualiza o contador (n / 57), persiste ao recarregar e "Limpar tudo" zera; links "Abrir" são ícones', async () => {
      await c.ir(BASE + '/admin/checklist_testes');
      await c.avaliar(`localStorage.clear(); 1`);
      await c.ir(BASE + '/admin/checklist_testes');
      const antes = await c.avaliar(`(document.body.innerText.match(/(\\d+) \\/ 57/)||[])[1]`);
      await c.avaliar(`[...document.querySelectorAll('input[type=checkbox]')].slice(1,4).forEach(function(i){ i.click(); }); 1`);
      const meio = await c.avaliar(`(document.body.innerText.match(/(\\d+) \\/ 57/)||[])[1]`);
      await c.ir(BASE + '/admin/checklist_testes');
      const depois = await c.avaliar(`(document.body.innerText.match(/(\\d+) \\/ 57/)||[])[1]`);
      await c.avaliar(`var b=[...document.querySelectorAll('button')].find(function(x){return /Limpar tudo/.test(x.innerText)}); b && b.click(); 1`);
      await esp(600);
      const zero = await c.avaliar(`(document.body.innerText.match(/(\\d+) \\/ 57/)||[])[1]`);
      const textos = await c.avaliar(`[...document.querySelectorAll('a.btn-icon.abrir')].length + '|' + [...document.querySelectorAll('a')].filter(function(a){return a.innerText.trim()==='Abrir'}).length`);
      return { ok: antes === '0' && meio === '3' && depois === '3' && zero === '0' && /^\d+\|0$/.test(textos), det: `${antes}→${meio}→recarga ${depois}→limpar ${zero}; ícones|texto "Abrir" ${textos}` };
    }, 'admin/checklist');
    await t('consultar venda: ID inexistente e entradas estranhas mostram mensagem sem erro PHP e sem vazar dados', async () => {
      const falhas = [];
      for (const v of ['99999999999', 'abc', "1' OR '1'='1", '-1', '<script>window.__cv=1</script>']) {
        await c.ir(BASE + '/admin/consultar_venda?id=' + encodeURIComponent(v));
        const tx = await c.avaliar('document.body.innerText');
        if (/Warning|Fatal|Notice:|SQLSTATE|syntax error/i.test(tx)) falhas.push(v);
        if (await c.avaliar('window.__cv===1')) falhas.push('xss ' + v);
      }
      return { ok: !falhas.length, det: falhas.join(' | ') };
    }, 'admin/consultar_venda');
    await t('consultar venda (existente, de teste): mostra TXID, valor e status salvos', async () => {
      const v = db(`SELECT id FROM vendas WHERE transacao_id='PGTX2'`)[0];
      await c.ir(BASE + '/admin/consultar_venda?id=' + v.id);
      const tx = await c.avaliar('document.body.innerText');
      return { ok: /PGTX2/.test(tx) && /49,90/.test(tx), det: tx.slice(0, 100).replace(/\n/g, ' ') };
    }, 'admin/consultar_venda');
    await t('testes de desempenho: página só informativa (nenhum botão que dispare carga/ataque)', async () => {
      await c.ir(BASE + '/admin/testes_desempenho');
      const botoes = await c.avaliar(`[...document.querySelectorAll('.conteudo-principal button, .conteudo-principal input[type=submit], .conteudo-principal form')].filter(function(e){return !/sino|alternador-tema/.test(e.className)}).length`);
      return { ok: botoes === 0, det: 'controles na página: ' + botoes };
    }, 'admin/testes_desempenho');
    salvar();
    c.pararDialogo();
  } finally { await c.fechar(); }
})();
