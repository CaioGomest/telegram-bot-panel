// ÁREA 1: autenticação (login, cadastro, logout, recuperação, controle de acesso)
const { lancar, entrar, BASE } = require('./pg_lib.js');
const { area, t, nota, salvar, esp } = require('./pg_t.js');
(async () => {
  const c = await lancar();
  try {
    area('1. Autenticação e controle de acesso');
    await c.ir(BASE + '/login');
    await t('login: página mostra e-mail, senha, lembrar-me, esqueci a senha e aba Criar conta', async () => c.avaliar(`!!document.getElementById('email') && !!document.getElementById('senha') && !!document.querySelector('[name=lembrar]') && !!document.querySelector('.login-link-esqueci') && !!document.querySelector('a[href="cadastro"]')`), 'login');
    await t('login: senha errada mostra "Email ou senha incorretos." e não entra', async () => {
      await c.avaliar(`document.getElementById('email').value='pgteste-user@example.invalid'; document.getElementById('senha').value='errada'; document.querySelector('form[action="login"]').submit(); 1`);
      await c.esperar(`document.querySelector('.aviso-erro')`);
      return (await c.avaliar(`location.pathname`)).includes('login') && /incorretos/.test(await c.avaliar(`document.querySelector('.aviso-erro').innerText`));
    }, 'login');
    await t('login: e-mail inexistente dá a MESMA mensagem (não revela se a conta existe)', async () => {
      await c.ir(BASE + '/login');
      await c.avaliar(`document.getElementById('email').value='pgteste-naoexiste@example.invalid'; document.getElementById('senha').value='123456'; document.querySelector('form[action="login"]').submit(); 1`);
      await c.esperar(`document.querySelector('.aviso-erro')`);
      return /Email ou senha incorretos/.test(await c.avaliar(`document.querySelector('.aviso-erro').innerText`));
    }, 'login');
    await t('login: campos vazios são barrados pelo navegador (required)', async () => {
      await c.ir(BASE + '/login');
      return c.avaliar(`document.getElementById('email').required && document.getElementById('senha').required`);
    }, 'login');
    await t('login: POST sem token CSRF é recusado', async () => {
      await c.ir(BASE + '/login');
      const r = await c.avaliar(`fetch('login',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'acao=login&email=pgteste-user%40example.invalid&senha=123456'}).then(async r=>({u:r.url,s:r.status,t:(await r.text()).slice(0,200)}))`);
      return { ok: !/\/index/.test(r.u) && !/Bem-vindo|dashboard/i.test(r.t), det: r.s + ' ' + r.u };
    }, 'login');
    await t('login: bloqueio após várias tentativas erradas (e-mail de teste exclusivo)', async () => {
      let ultimo = '';
      for (let i = 0; i < 8; i++) {
        await c.ir(BASE + '/login');
        await c.avaliar(`document.getElementById('email').value='pgteste-bloqueio@example.invalid'; document.getElementById('senha').value='x'+${i}; document.querySelector('form[action="login"]').submit(); 1`);
        await c.esperar(`document.querySelector('.aviso-erro')`);
        ultimo = await c.avaliar(`document.querySelector('.aviso-erro').innerText`);
        if (/Muitas tentativas/.test(ultimo)) return { ok: true, det: 'bloqueou na tentativa ' + (i + 1) };
      }
      return { ok: false, det: 'nunca bloqueou: ' + ultimo };
    }, 'login');
    await t('rotas protegidas deslogado redirecionam ao login (7 páginas + API)', async () => {
      const rotas = ['bots', 'leads', 'gateways', 'fluxos', 'configuracao_usuario', 'admin/usuarios', 'remarketing'];
      const falhas = [];
      for (const r of rotas) {
        const x = await c.avaliar(`fetch('${r}',{redirect:'follow'}).then(async r=>({u:r.url}))`);
        if (!/login/.test(x.u)) falhas.push(r + ' -> ' + x.u);
      }
      const api = await c.avaliar(`fetch('api.php?action=listar_bots').then(async r=>({s:r.status,t:(await r.text()).slice(0,200)}))`);
      if (/"sucesso":true/.test(api.t)) falhas.push('api listar_bots respondeu sem login');
      return { ok: !falhas.length, det: falhas.join('; ') };
    }, 'segurança');
    await t('login correto (usuário comum) abre o dashboard do usuário', async () => {
      await entrar(c, 'pgteste-user@example.invalid');
      return (await c.avaliar('location.pathname')).endsWith('/index');
    }, 'login');
    await t('usuário comum NÃO acessa páginas de admin (11 rotas)', async () => {
      const rotas = ['dashboard', 'usuarios', 'transacoes', 'configuracoes', 'comunidade', 'ranking', 'logs', 'checklist_testes', 'consultar_venda', 'testes_desempenho', 'atualiza_banco'];
      const falhas = [];
      for (const r of rotas) {
        const x = await c.avaliar(`fetch('admin/${r}',{redirect:'follow'}).then(async r=>({u:r.url,s:r.status}))`);
        if (/\/admin\//.test(x.u) && x.s === 200) falhas.push(r + ' abriu (' + x.u + ')');
      }
      return { ok: !falhas.length, det: falhas.join('; ') };
    }, 'segurança');
    await t('logado, abrir /login leva direto ao painel', async () => {
      await c.ir(BASE + '/login');
      return !(await c.avaliar('location.pathname')).includes('login');
    }, 'login');
    await t('logout encerra a sessão e as páginas voltam a pedir login', async () => {
      await c.ir(BASE + '/logout');
      await esp(500);
      const x = await c.avaliar(`fetch('bots',{redirect:'follow'}).then(r=>r.url)`);
      return { ok: /login/.test(x), det: x };
    }, 'logout');
    await t('login de administrador leva ao /admin/dashboard', async () => {
      await entrar(c, 'pgteste-admin@example.invalid');
      return (await c.avaliar('location.pathname')).includes('admin/dashboard');
    }, 'login');
    await t('administrador é redirecionado ao tentar abrir telas de usuário (bots)', async () => {
      const x = await c.avaliar(`fetch('../bots',{redirect:'follow'}).then(r=>r.url)`);
      return { ok: /admin/.test(x) || /login/.test(x), det: x };
    }, 'segurança');
    await c.ir(BASE + '/logout'); await esp(400);

    await c.ir(BASE + '/cadastro');
    await t('cadastro: formulário tem nome, e-mail, senha e confirmação, todos obrigatórios', async () => c.avaliar(`['nome','email','senha','confirma_senha'].every(function(i){var e=document.getElementById(i);return e&&e.required})`), 'cadastro');
    const enviarCadastro = async (n, e, s, s2) => {
      await c.ir(BASE + '/cadastro');
      await c.avaliar(`document.getElementById('nome').value=${JSON.stringify(n)}; document.getElementById('email').value=${JSON.stringify(e)}; document.getElementById('senha').value=${JSON.stringify(s)}; document.getElementById('confirma_senha').value=${JSON.stringify(s2)}; document.querySelector('form').submit(); 1`);
      await esp(1800);
      return { url: await c.avaliar('location.pathname + location.search'), erro: await c.avaliar(`(document.querySelector('.aviso-erro')||{}).innerText||''`), ok: await c.avaliar(`(document.querySelector('.aviso-sucesso')||{}).innerText||''`) };
    };
    await t('cadastro: senhas diferentes → "As senhas não conferem."', async () => { const r = await enviarCadastro('Teste PG', 'pgteste-novo1@example.invalid', 'abc12345', 'outra1234'); return { ok: /não conferem/.test(r.erro), det: r.erro }; }, 'cadastro');
    await t('cadastro: senha curta é recusada com mensagem', async () => { const r = await enviarCadastro('Teste PG', 'pgteste-novo2@example.invalid', '123', '123'); return { ok: !!r.erro && !/sucesso/.test(r.url), det: r.erro || r.url }; }, 'cadastro');
    await t('cadastro: e-mail já usado é recusado', async () => { const r = await enviarCadastro('Teste PG', 'pgteste-user@example.invalid', 'abc12345', 'abc12345'); return { ok: !!r.erro && !/sucesso/.test(r.url), det: r.erro || r.url }; }, 'cadastro');
    await t('cadastro: e-mail inválido é recusado', async () => { const r = await enviarCadastro('Teste PG', 'isso-nao-e-email', 'abc12345', 'abc12345'); return { ok: !/sucesso/.test(r.url), det: r.erro || r.url }; }, 'cadastro');
    await t('cadastro: dados válidos criam a conta e voltam ao login com mensagem de sucesso', async () => { const r = await enviarCadastro('Teste PG Novo', 'pgteste-novo3-' + Date.now() + '@example.invalid', 'Abc12345!', 'Abc12345!'); return { ok: /sucesso=cadastro/.test(r.url), det: r.url + ' | ' + (r.erro || r.ok) }; }, 'cadastro');
    await t('cadastro: nome com HTML não executa script na tela (XSS)', async () => {
      await enviarCadastro('<script>window.__x=1</script>Fulano', 'pgteste-novo4-' + Date.now() + '@example.invalid', 'Abc12345!', 'Abc12345!');
      
      await esp(500);
      const xss = await c.avaliar('window.__x === 1').catch(() => false);
      return { ok: !xss, det: 'url ' + (await c.avaliar('location.pathname')) };
    }, 'segurança');
    await c.ir(BASE + '/logout'); await esp(300);

    await c.ir(BASE + '/login');
    await t('esqueci a senha: o modal abre', async () => {
      await c.avaliar(`abrirEsqueciSenha(); 1`);
      const aberto = await c.avaliar(`document.getElementById('modal-esqueci-senha').classList.contains('aberto') || getComputedStyle(document.getElementById('modal-esqueci-senha')).display!=='none'`);
      return { ok: aberto, det: 'modal aberto=' + aberto };
    }, 'recuperação');
    await t('verificar_email com token inválido não quebra (sem erro PHP)', async () => {
      await c.ir(BASE + '/verificar_email?token=invalido123');
      const txt = await c.avaliar('document.body.innerText');
      return { ok: !/Warning|Fatal|Notice|Uncaught/.test(txt) && txt.length > 10, det: txt.slice(0, 120).replace(/\n/g, ' ') };
    }, 'verificar_email');
    await t('página 404 amigável (sem vazar caminho/erro)', async () => {
      await c.ir(BASE + '/qualquer-coisa-xyz');
      const txt = await c.avaliar('document.body.innerText');
      return { ok: /não encontrada/i.test(txt) && !/\/home\/|xampp|stack trace/i.test(txt), det: txt.slice(0, 60).replace(/\n/g, ' ') };
    }, '404');
    await t('arquivos sensíveis não são servidos (config.php, .env, CLAUDE.md, logs, .git, funcoes, anotacoes, tests, storage)', async () => {
      const caminhos = ['config.php', '.env', 'CLAUDE.md', 'README.md', 'logs/traqueamento.log', 'logs/conexao_falhou.log', '.git/config', 'funcoes/usuario.php', 'anotacoes/OBSERVACOES.md', 'tests/homologacao/README.md', 'storage/cache_midia.json'];
      const vazou = [];
      for (const p of caminhos) {
        const r = await c.avaliar(`fetch('${p}').then(async r=>({s:r.status,t:(await r.text()).slice(0,300)}))`);
        if (r.s === 200 && !/não encontrada/i.test(r.t) && r.t.trim().length > 0 && !/<html/i.test(r.t.slice(0, 80))) vazou.push(p + ' (' + r.s + ')');
      }
      return { ok: !vazou.length, det: vazou.join(', ') };
    }, 'segurança');
    salvar();
  } finally { await c.fechar(); }
})();
