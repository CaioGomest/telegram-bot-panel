// 0) Cria uma caixa de e-mail descartável (mail.tm) e grava as variáveis da rodada em ../estado/email-e-isolamento/teste_vps.env.
//    Uso: node 00_preparar.js   (depois 01_cadastro_email.js, 02_recuperacao_senha.js, 03_isolamento_contas.js)
const fs = require('fs');
const path = require('path');
const L = require('./lib.js');

(async () => {
  const suf = new Date().toISOString().slice(5, 16).replace(/\D/g, '');
  const r0 = await fetch('https://api.mail.tm/domains');
  const dominio = ((await r0.json())['hydra:member'] || [])[0].domain;
  const email = `vpsteste${suf}@${dominio}`;
  const mailPw = `MailTm#${suf}Aa9`;
  const cr = await fetch('https://api.mail.tm/accounts', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ address: email, password: mailPw }) });
  if (cr.status !== 201) throw new Error('mail.tm recusou a criação da caixa: HTTP ' + cr.status);
  const tk = await (await fetch('https://api.mail.tm/token', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ address: email, password: mailPw }) })).json();
  const linhas = [`EMAIL=${email}`, `MAIL_PW=${mailPw}`, `MAIL_TOKEN=${tk.token}`, `SENHA_PAINEL=VpsTeste#${suf}Zz7`, 'NOME=Teste VPS Claude'];
  fs.writeFileSync(path.join(L.SP, 'teste_vps.env'), linhas.join('\n') + '\n');
  console.log('caixa criada:', email, '| variáveis em', path.join(L.SP, 'teste_vps.env'));
})();
