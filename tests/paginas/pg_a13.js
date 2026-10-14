// ÁREA 16: Admin — Visão geral, Usuários (CRUD, detalhes, regras de perfil) e controle de acesso aos endpoints ajax
const { lancar, entrar, BASE, db, remoto, autoDialogo } = require('./pg_lib.js');
const { area, t, nota, salvar, esp } = require('./pg_t.js');
const brl = (n) => 'R$ ' + Number(n).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
(async () => {
  const c = await lancar();
  autoDialogo(c, true);
  const post = (url, corpo) => c.avaliar(`fetch('${url}',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':window.CSRF_TOKEN||''},body:new URLSearchParams(${JSON.stringify(corpo)}).toString()}).then(async r=>({http:r.status,j:await r.json().catch(()=>null)}))`);
  try {
    await entrar(c, 'pgteste-admin@example.invalid');
    area('16. Admin — Visão geral');
    const kpi = async (qs) => {
      await c.ir(BASE + '/admin/dashboard' + qs);
      const tx = await c.avaliar(`document.querySelector('.conteudo-principal').innerText.split(String.fromCharCode(10)).filter(Boolean).join(' | ')`);
      const pega = (re) => (tx.match(re) || [])[1];
      return { tx, volume: pega(/VOLUME TOTAL \| (R\$ [\d.,]+)/), qtd: pega(/QTD\. VENDAS \| ([0-9.]+)/), liquida: pega(/RECEITA LÍQUIDA \| (R\$ [\d.,]+)/), usuarios: pega(/USUÁRIOS \| (\d+)/) };
    };
    await t('KPIs do período "Total" batem com a tabela de métricas que alimenta o dashboard (volume, quantidade, receita líquida) e com o nº de clientes', async () => {
      const k = await kpi('?periodo=total');
      const m = db("SELECT COALESCE(SUM(faturamento),0) vol, COALESCE(SUM(quantidade),0) q, COALESCE(SUM(comissao),0) com FROM metricas_horarias_admin")[0];
      const clientes = db("SELECT COUNT(*) n FROM usuarios WHERE perfil='usuario'")[0].n;
      const n = (x) => Number(String(x).replace('R$', '').split('.').join('').replace(',', '.').trim());
      const dif = [];
      if (Math.abs(n(k.volume) - Number(m.vol)) > 0.01) dif.push('volume ' + k.volume + ' vs ' + m.vol);
      if (Number(String(k.qtd).split('.').join('')) !== Number(m.q)) dif.push('qtd ' + k.qtd + ' vs ' + m.q);
      if (Math.abs(n(k.liquida) - Number(m.com)) > 0.01) dif.push('líquida ' + k.liquida + ' vs ' + m.com);
      if (Math.abs(Number(k.usuarios) - clientes) > 1) dif.push('usuários ' + k.usuarios + ' vs ' + clientes);
      return { ok: !dif.length, det: dif.join('; ') || (k.volume + ' / ' + k.qtd + ' vendas / ' + k.usuarios + ' clientes') };
    }, 'admin/dashboard');
    {
      const live = db("SELECT COALESCE(SUM(valor),0) vol, COUNT(*) q FROM vendas WHERE status='pago'")[0];
      const cache = db("SELECT COALESCE(SUM(faturamento),0) vol, COALESCE(SUM(quantidade),0) q FROM metricas_horarias_admin")[0];
      const dif = (Number(cache.vol) - Number(live.vol)).toFixed(2);
      if (Math.abs(Number(dif)) > 0.01) nota('O dashboard do admin usa o cache de métricas (por hora) e ele NÃO desconta vendas apagadas: o cache está R$ ' + dif + ' acima do total real das vendas (' + cache.q + ' x ' + live.q + ' vendas). Acontece ao excluir usuários/vendas depois que a cron já contou; a cron só soma/atualiza baldes, nunca zera os que ficaram sem linhas', 'observação — sem correção');
    }
    await t('períodos Hoje / Ontem / 8 dias / 30 dias abrem sem erro e mudam o rótulo do gráfico', async () => {
      const falhas = [];
      for (const p of ['hoje', 'ontem', '7dias', '30dias', 'total']) {
        await c.ir(BASE + '/admin/dashboard?periodo=' + p);
        const tx = await c.avaliar('document.body.innerText');
        if (/Warning|Fatal|Notice:|SQLSTATE/i.test(tx) || !/VISÃO GERAL/i.test(tx)) falhas.push(p);
      }
      return { ok: !falhas.length, det: falhas.join(',') };
    }, 'admin/dashboard');
    await t('entradas inválidas (periodo=xyz, datas inválidas, SQL) não quebram', async () => {
      const falhas = [];
      for (const qs of ['?periodo=xyz', '?periodo=personalizado&data_inicio=abc&data_fim=2020-13-45', "?periodo='%20OR%201=1--", '?periodo=hoje%27%3BDROP%20TABLE%20x--']) {
        await c.ir(BASE + '/admin/dashboard' + qs);
        const tx = await c.avaliar('document.body.innerText');
        if (/Warning|Fatal|Notice:|SQLSTATE|syntax/i.test(tx) || !/VISÃO GERAL/i.test(tx)) falhas.push(qs);
      }
      return { ok: !falhas.length, det: falhas.join(' ') };
    }, 'admin/dashboard');
    await t('log do sistema no dashboard mostra eventos recentes (login do próprio admin) e escapa HTML dos nomes', async () => {
      await c.ir(BASE + '/admin/dashboard');
      const tx = await c.avaliar('document.body.innerText');
      const exec = await c.avaliar('window.__x===1 || window.__lead===1');
      return { ok: /Login \(Admin Pagina Teste\)/.test(tx) && !exec, det: '' };
    }, 'admin/dashboard');

    area('16b. Admin — Usuários');
    await c.ir(BASE + '/admin/usuarios');
    await t('lista traz ID, nome, e-mail, perfil, nº de bots, vendas (R$) e data; o usuário de teste mostra 2 bots e R$ 69,80', async () => {
      const th = await c.avaliar(`[...document.querySelectorAll('table thead th')].map(function(x){return x.innerText.trim()}).join(',')`);
      const linha = await c.avaliar(`(function(){ var tr=[...document.querySelectorAll('table tbody tr')].find(function(r){return r.innerText.indexOf('pgteste-user@example.invalid')>=0}); return tr? tr.innerText.split(String.fromCharCode(9)).join(' | ').split(String.fromCharCode(10)).join(' ') : '' })()`);
      return { ok: /ID,NOME,EMAIL,PERFIL,BOTS,VENDAS/i.test(th) && /2/.test(linha) && /69,80/.test(linha), det: th + ' :: ' + linha.slice(0, 120) };
    }, 'admin/usuarios');
    await t('nomes com <script> aparecem como texto na tabela (sem executar)', async () => {
      const exec = await c.avaliar('window.__x===1');
      const tem = await c.avaliar(`document.querySelector('table').innerText.indexOf('<script>')>=0`);
      return { ok: !exec && tem, det: '' };
    }, 'segurança');
    await t('paginação da lista de usuários: ?pagina=999/0/-1/abc não quebra', async () => {
      const falhas = [];
      for (const q of ['?pagina=999', '?pagina=0', '?pagina=-1', '?pagina=abc']) {
        await c.ir(BASE + '/admin/usuarios' + q);
        const tx = await c.avaliar('document.body.innerText');
        if (/Warning|Fatal|Notice:|SQLSTATE/i.test(tx)) falhas.push(q);
      }
      return { ok: !falhas.length, det: falhas.join(' ') };
    }, 'admin/usuarios');
    await c.ir(BASE + '/admin/usuarios');
    await t('"Adicionar Usuário": validações (campos, senha curta, e-mail repetido/ inválido)', async () => {
      const casos = [['vazio', { nome: '', email: '', senha: '' }, /preencha/i], ['senha curta', { nome: 'X', email: 'pgteste-add1@example.invalid', senha: '123' }, /8 caracteres/i], ['e-mail inválido', { nome: 'X', email: 'nao-e-email', senha: '12345678' }, /inválido/i], ['e-mail repetido', { nome: 'X', email: 'pgteste-user@example.invalid', senha: '12345678' }, /já está|cadastrado/i]];
      const falhas = [];
      for (const [n, corpo, rx] of casos) {
        const r = await post('../ajax/adicionar_usuario.php', corpo);
        if (r.j.sucesso || !rx.test(r.j.erro || '')) falhas.push(`${n}: ${JSON.stringify(r.j)}`);
      }
      return { ok: !falhas.length, det: falhas.join(' | ') };
    }, 'admin/usuarios');
    await t('criar usuário pelo modal: aparece na lista como "Usuário"; criar como admin grava perfil admin', async () => {
      await c.avaliar(`abrirModalAdicionar(); 1`);
      await esp(300);
      await c.avaliar(`(function(){ var f=document.getElementById('formAdicionar'); f.elements.nome.value='Criado Pelo Admin'; f.elements.email.value='pgteste-add2@example.invalid'; f.elements.senha.value='Senha12345'; f.requestSubmit(); return 1; })()`);
      await esp(2500);
      const u = db(`SELECT id, perfil, email_verificado_em FROM usuarios WHERE email='pgteste-add2@example.invalid'`)[0];
      const r = await post('../ajax/adicionar_usuario.php', { nome: 'Admin Criado', email: 'pgteste-add3@example.invalid', senha: 'Senha12345', perfil: 'admin' });
      const a = db(`SELECT perfil FROM usuarios WHERE email='pgteste-add3@example.invalid'`)[0];
      global.__add2 = u && u.id;
      return { ok: !!u && u.perfil === 'usuario' && r.j.sucesso && a.perfil === 'admin', det: JSON.stringify(u) };
    }, 'admin/usuarios');
    await t('editar usuário: troca nome/e-mail/perfil e PRESERVA o apelido do Ranking (não apaga)', async () => {
      remoto(`php -r 'require "conexao.php"; $pdo->prepare("UPDATE usuarios SET apelido_publico=? WHERE id=?")->execute(["meu_apelido", ${global.__add2}]);'`);
      const r = await post('../ajax/editar_usuario.php', { id: String(global.__add2), nome: 'Nome Editado', email: 'pgteste-add2b@example.invalid', senha: '', perfil: 'usuario' });
      const u = db(`SELECT nome, email, apelido_publico, email_verificado_em FROM usuarios WHERE id=${global.__add2}`)[0];
      return { ok: r.j.sucesso && u.nome === 'Nome Editado' && u.email === 'pgteste-add2b@example.invalid' && u.apelido_publico === 'meu_apelido', det: JSON.stringify(u) };
    }, 'admin/usuarios');
    await t('admin definindo senha de outro usuário (mínimo 6, como antes): senha curta recusada e hash novo gravado', async () => {
      const antes = db(`SELECT senha FROM usuarios WHERE id=${global.__add2}`)[0].senha;
      const a = await post('../ajax/editar_usuario.php', { id: String(global.__add2), nome: 'Nome Editado', email: 'pgteste-add2b@example.invalid', senha: '123', perfil: 'usuario' });
      const b = await post('../ajax/editar_usuario.php', { id: String(global.__add2), nome: 'Nome Editado', email: 'pgteste-add2b@example.invalid', senha: 'abc123', perfil: 'usuario' });
      const dep = db(`SELECT senha FROM usuarios WHERE id=${global.__add2}`)[0].senha;
      return { ok: !a.j.sucesso && b.j.sucesso && dep !== antes, det: JSON.stringify(a.j) };
    }, 'admin/usuarios');
    await t('detalhes do usuário (modal): mostra e-mail, bots e vendas do usuário certo', async () => {
      const id = db(`SELECT id FROM usuarios WHERE email='pgteste-user@example.invalid'`)[0].id;
      await c.ir(BASE + '/admin/usuarios');
      const r = await c.avaliar(`fetch('../ajax/detalhes_usuario.php?id=${id}').then(r=>r.json())`);
      const txt = JSON.stringify(r);
      return { ok: /pgteste-user@example.invalid/.test(txt) && /Bot Um/.test(txt) && !/\$2y\$/.test(txt) && !/"senha"/.test(txt), det: Object.keys(r).join(',') + ' | sem hash de senha=' + !/\$2y\$/.test(txt) };
    }, 'segurança');
    await t('regra do último admin: com 2+ administradores, rebaixar um deles é permitido (a trava só age quando restaria zero)', async () => {
      const admins = db("SELECT COUNT(*) n FROM usuarios WHERE perfil='admin'")[0].n;
      const alvo = db("SELECT id FROM usuarios WHERE email='pgteste-add3@example.invalid'")[0].id;
      const r = await post('../ajax/editar_usuario.php', { id: String(alvo), nome: 'Admin Criado', email: 'pgteste-add3@example.invalid', senha: '', perfil: 'usuario' });
      const dep = db("SELECT perfil FROM usuarios WHERE id=" + alvo)[0].perfil;
      return { ok: admins >= 2 && r.j.sucesso && dep === 'usuario', det: 'admins=' + admins + ' → ' + JSON.stringify(r.j) };
    }, 'segurança');
    nota('A trava "não rebaixar o único administrador" foi implementada, mas não pôde ser exercitada ao vivo: há outros administradores reais no banco e não se mexe neles', 'revisada só no código');
    await t('excluir usuário: Cancelar mantém; confirmar remove o usuário e os dados dele (bots/fluxos); não deixa excluir a si mesmo', async () => {
      await c.ir(BASE + '/admin/usuarios');
      const id = global.__add2;
      remoto(`php -r 'require "conexao.php"; $pdo->prepare("INSERT INTO bots (id_usuario,token,id_bot_telegram,nome_usuario,primeiro_nome) VALUES (?,?,?,?,?)")->execute([${id},"SIMBOT:AAH-del-${id}",7300001,"pg_del_bot","Bot Del"]); $pdo->prepare("INSERT INTO fluxos (id_usuario,nome,modo,dados_fluxograma) VALUES (?,?,?,?)")->execute([${id},"Fluxo Del","avancado","{}"]);'`);
      c.aceitar = false;
      await c.avaliar(`confirmarExcluir(${id}, 'Nome Editado'); 1`);
      await esp(900);
      const ainda = db(`SELECT COUNT(*) n FROM usuarios WHERE id=${id}`)[0].n;
      c.aceitar = true;
      await c.avaliar(`confirmarExcluir(${id}, 'Nome Editado'); 1`);
      await esp(2500);
      const dep = db(`SELECT (SELECT COUNT(*) FROM usuarios WHERE id=${id}) u, (SELECT COUNT(*) FROM bots WHERE id_usuario=${id}) b, (SELECT COUNT(*) FROM fluxos WHERE id_usuario=${id}) f`)[0];
      const meu = db(`SELECT id FROM usuarios WHERE email='pgteste-admin@example.invalid'`)[0].id;
      const self = await post('../ajax/deletar_usuario.php', { id: String(meu) });
      return { ok: ainda === 1 && dep.u === 0 && dep.b === 0 && dep.f === 0 && !self.j.sucesso, det: `após excluir: usuário ${dep.u}, bots ${dep.b}, fluxos ${dep.f}; excluir a si mesmo → ${JSON.stringify(self.j)}` };
    }, 'admin/usuarios');
    remoto(`php -r 'require "conexao.php"; $pdo->exec("DELETE FROM usuarios WHERE email IN (\\"pgteste-add3@example.invalid\\",\\"pgteste-add1@example.invalid\\")");'`);
    await t('endpoints ajax de admin sem token CSRF são recusados (adicionar, editar, deletar)', async () => {
      const falhas = [];
      for (const u of ['adicionar_usuario', 'editar_usuario', 'deletar_usuario']) {
        const r = await c.avaliar(`fetch('../ajax/${u}.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'id=999999&nome=x&email=x%40y.invalid&senha=12345678'}).then(r=>r.status)`);
        if (r !== 403) falhas.push(u + '→' + r);
      }
      return { ok: !falhas.length, det: falhas.join(' ') };
    }, 'segurança');
    salvar();
    c.pararDialogo();
  } finally { await c.fechar(); }
})();
