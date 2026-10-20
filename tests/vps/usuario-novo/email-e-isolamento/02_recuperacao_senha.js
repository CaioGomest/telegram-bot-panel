// 3b) Recuperação de senha por e-mail (só a parte do código), escolhendo o e-mail certo pelo assunto.
const fs = require('fs'), path = require('path');
const L = require('./lib.js');
const { ok, BASE, dorme } = L;
const E = L.env();
(async () => {
  const c = await L.lancar();
  try {
    const antes = (await L.emails()).map(m => m.id);
    await c.ir(BASE + '/login');
    await c.avaliar(`abrirEsqueciSenha(); document.getElementById('rec-email').value=${JSON.stringify(E.EMAIL)}; document.getElementById('rec-enviar').click(); 1`);
    await c.esperar(`!document.getElementById('rec-passo-codigo').hidden`, 15000);
    ok('"Esqueci a senha": mostra o passo do código', await c.avaliar(`!document.getElementById('rec-passo-codigo').hidden`), await c.avaliar(`(document.getElementById('rec-aviso')||{}).innerText`));
    let codigo = null, assunto = '';
    for (let i = 0; i < 24 && !codigo; i++) {
      const novos = (await L.emails()).filter(m => !antes.includes(m.id) && !/alterada/i.test(m.subject));
      for (const m of novos) {
        const full = await L.emailCompleto(m.id); const t = (full.text || '') + ' ' + (full.html || []).join(' ');
        const mm = t.match(/(?:^|[^\d])(\d{6})(?!\d)/); if (mm) { codigo = mm[1]; assunto = full.subject; break; }
      }
      if (!codigo) await dorme(5000);
    }
    console.log('  e-mail do código — assunto:', assunto);
    ok('e-mail com o código de 6 dígitos chega (SMTP Brevo)', !!codigo, null);
    if (codigo) {
      const recuperada = 'RecupVps#2026Rr'; fs.writeFileSync(path.join(L.SP, 'teste_vps.env'), fs.readFileSync(path.join(L.SP, 'teste_vps.env'), 'utf8').replace(/SENHA_PAINEL=.*/, 'SENHA_PAINEL=' + recuperada));
      await c.avaliar(`document.getElementById('rec-codigo').value='000000'; document.getElementById('rec-nova').value=${JSON.stringify(recuperada)}; document.getElementById('rec-confirmar').value=${JSON.stringify(recuperada)}; document.getElementById('rec-salvar').click(); 1`);
      await dorme(2500);
      ok('código errado é recusado', /inválid|incorret|expirou/i.test(await c.avaliar(`document.getElementById('rec-aviso').innerText`)), await c.avaliar(`document.getElementById('rec-aviso').innerText`));
      await c.avaliar(`document.getElementById('rec-codigo').value=${JSON.stringify(codigo)}; document.getElementById('rec-salvar').click(); 1`);
      await dorme(2500);
      const av = await c.avaliar(`document.getElementById('rec-aviso').innerText`);
      ok('código certo: "Senha atualizada"', /atualizada/i.test(av), av);
      const d = await L.entrar(c, E.EMAIL, recuperada);
      ok('login com a senha recuperada funciona', !/login/.test(d), d);
    }
  } catch (e) { console.log('ERRO', e.stack || e.message); ok('exceção no roteiro', false, e.message); }
  await c.fechar();
  L.salvarResultados('03b_recuperacao');
})();
