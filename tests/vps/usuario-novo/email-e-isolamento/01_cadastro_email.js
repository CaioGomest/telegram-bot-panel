// 1) Cadastro (erros e caso feliz), e-mail de boas-vindas, confirmação do e-mail, login (erro e acerto), logout.
const L = require('./lib.js');
const { ok, BASE } = L;
const E = L.env();

async function preencherCadastro(c, nome, email, senha, conf) {
  await c.ir(BASE + '/cadastro');
  await c.avaliar(`(function(){var f=document.querySelector('form[method=POST]'); f.setAttribute('novalidate','');
    document.getElementById('nome').value=${JSON.stringify(nome)}; document.getElementById('email').value=${JSON.stringify(email)};
    document.getElementById('senha').value=${JSON.stringify(senha)}; document.getElementById('confirma_senha').value=${JSON.stringify(conf)}; f.submit(); return 1})()`);
  await new Promise(r => setTimeout(r, 1500));
  await c.esperarCarga();
}
const aviso = (c) => c.avaliar(`(document.querySelector('.aviso')||{}).innerText||''`);

(async () => {
  const c = await L.lancar();
  try {
    // --- página de cadastro
    let r = await L.inspecionar(c, '/cadastro');
    ok('/cadastro abre sem erro (HTTP 200, sem PHP/JS)', r.status === 200 && L.resumo(r).length === 0, r);
    await c.captura(L.SP + '/shots/01_cadastro.png');

    // --- erros
    await preencherCadastro(c, E.NOME, E.EMAIL, 'abc12345', 'diferente1');
    ok('senhas diferentes -> "As senhas não conferem."', /não conferem/i.test(await aviso(c)), await aviso(c));
    await preencherCadastro(c, E.NOME, E.EMAIL, 'curta1', 'curta1');
    ok('senha com menos de 8 caracteres é recusada', /8 caracteres/i.test(await aviso(c)), await aviso(c));
    await preencherCadastro(c, E.NOME, 'isto-nao-e-email', 'abc12345', 'abc12345');
    ok('e-mail inválido é recusado', /inválido/i.test(await aviso(c)), await aviso(c));

    // --- caso feliz
    await preencherCadastro(c, E.NOME, E.EMAIL, E.SENHA_PAINEL, E.SENHA_PAINEL);
    const url = await c.avaliar('location.pathname + location.search');
    ok('cadastro válido redireciona para login?sucesso=cadastro', /login\?sucesso=cadastro/.test(url), url);
    ok('login mostra "Cadastro realizado com sucesso"', /Cadastro realizado/i.test(await aviso(c)), await aviso(c));

    // --- e-mail repetido
    await preencherCadastro(c, E.NOME, E.EMAIL, E.SENHA_PAINEL, E.SENHA_PAINEL);
    ok('e-mail já cadastrado é recusado', /já está cadastrado/i.test(await aviso(c)), await aviso(c));

    // --- login com senha errada
    await c.ir(BASE + '/login');
    await c.avaliar(`document.getElementById('email').value=${JSON.stringify(E.EMAIL)}; document.getElementById('senha').value='senha-errada-123'; document.querySelector('form[action="login"]').submit(); 1`);
    await new Promise(r => setTimeout(r, 1500)); await c.esperarCarga();
    ok('login com senha errada: "Email ou senha incorretos."', /incorretos/i.test(await aviso(c)), await aviso(c));

    // --- login certo
    const dest = await L.entrar(c, E.EMAIL, E.SENHA_PAINEL);
    ok('login certo leva ao painel (/ ou /index)', /^\/(index)?$/.test(dest) || dest === '/', dest);
    await c.captura(L.SP + '/shots/01_painel_inicial.png');
    const corpo = await c.avaliar('document.body.innerText');
    ok('painel mostra o nome da conta', /Teste VPS Claude|Teste VPS/i.test(corpo), corpo.slice(0, 200));
    ok('aviso para confirmar e-mail aparece (conta nova)', /confirm/i.test(corpo), null);

    // --- e-mail de boas-vindas chega? (Brevo -> caixa descartável)
    let msgs = [];
    for (let i = 0; i < 18 && !msgs.length; i++) { msgs = await L.emails(); if (!msgs.length) await L.dorme(5000); }
    ok('e-mail de boas-vindas chegou na caixa (SMTP Brevo funcionando)', msgs.length > 0, msgs);
    if (msgs.length) {
      const m = await L.emailCompleto(msgs[0].id);
      console.log('  assunto:', m.subject, '| de:', m.from && m.from.address);
      const html = (m.html || []).join(' ') + ' ' + (m.text || '');
      const link = (html.match(/https?:\/\/[^\s"'<>]*verificar_email\?token=[a-f0-9]{64}/) || [])[0];
      ok('e-mail traz o link de confirmação (coyotebot.com.br/verificar_email?token=...)', !!link && link.indexOf('coyotebot.com.br') !== -1, link);
      if (link) {
        const alvo = link.replace(/&amp;/g, '&');
        r = await L.inspecionar(c, alvo.replace(BASE, ''));
        const txt = await c.avaliar('document.body.innerText');
        ok('link de confirmação: "E-mail confirmado com sucesso!"', /confirmado com sucesso/i.test(txt), txt.slice(0, 200));
        await c.ir(BASE + '/');
        const t2 = await c.avaliar('document.body.innerText');
        ok('após confirmar, o aviso de confirmação some do painel', !/Confirme seu e-mail|confirmar seu e-mail/i.test(t2), null);
        // link reaproveitado
        await c.ir(alvo);
        ok('reabrir o mesmo link: "já estava confirmado"', /já estava confirmado/i.test(await c.avaliar('document.body.innerText')), null);
      }
    }
    await c.ir(BASE + '/verificar_email?token=' + 'a'.repeat(64));
    ok('token inexistente: mensagem de erro', /inválido/i.test(await c.avaliar('document.body.innerText')), null);

    // --- logout
    await c.ir(BASE + '/logout');
    await c.esperarCarga();
    const p = await c.avaliar('location.pathname');
    ok('logout leva ao login', /login/.test(p), p);
    await c.ir(BASE + '/bots');
    ok('depois do logout, /bots redireciona ao login', /login/.test(await c.avaliar('location.pathname')), await c.avaliar('location.href'));
  } catch (e) { console.log('ERRO', e.stack || e.message); ok('exceção no roteiro', false, e.message); }
  await c.fechar();
  L.salvarResultados('01_cadastro');
})();
