// Teste do campo de token do bot: o token real com espaço/quebra de linha no meio (copiar do BotFather no celular) tem que validar. Precisa de uma conta com um bot REAL.
// Uso: HOMOLOG_URL=... HOMOLOG_EMAIL=... HOMOLOG_SENHA=... TOKEN_BOT_ID=<id do bot real> node zero_token.js
const { lancar } = require('./cdp.js');
const BASE = process.env.HOMOLOG_URL || 'https://telegram.stackcode.com.br';
const EMAIL = process.env.HOMOLOG_EMAIL || '';
const SENHA = process.env.HOMOLOG_SENHA || '';
const ID_BOT = process.env.TOKEN_BOT_ID || '';
const esp = (ms) => new Promise(r => setTimeout(r, ms));
(async () => {
  const c = await lancar();
  const ok = (r, cond, x) => console.log((cond ? 'PASS' : 'FAIL') + ' | ' + r + (cond ? '' : ' | ' + JSON.stringify(x)));
  try {
    await c.enviar('Emulation.setDeviceMetricsOverride', { width: 1400, height: 900, deviceScaleFactor: 1, mobile: false });
    await c.ir(BASE + '/login');
    await c.avaliar(`document.getElementById('email').value=${JSON.stringify(EMAIL)}; document.getElementById('senha').value=${JSON.stringify(SENHA)}; document.querySelector('form[action="login"]').submit(); 1`);
    await c.esperar(`location.pathname.indexOf('login')===-1`, 20000); await c.esperarCarga();
    const post = (acao, corpo) => `fetch('api.php?action=${acao}', {method:'POST', credentials:'include', headers:{'Content-Type':'application/json','X-CSRF-Token': window.CSRF_TOKEN||''}, body: JSON.stringify(${corpo})})`;
    // 1) API: o token REAL do bot do usuário com espaço e quebra de linha no meio (o token nunca é impresso)
    const r1 = JSON.parse(await c.avaliar(`(async function(){
      var b = await (await fetch('api.php?action=obter_bot&id=${ID_BOT}', {credentials:'include'})).json();
      var t = b.bot.token; var meio = Math.floor(t.length / 2);
      var sujo = t.slice(0, 11) + ' ' + t.slice(11, meio) + String.fromCharCode(10) + t.slice(meio);
      var p = await ${post('testar_bot', '{token: sujo}')};
      var j = await p.json();
      return JSON.stringify({status: p.status, sucesso: j.sucesso, mensagem: j.mensagem, user: j.info_bot && j.info_bot.username, temEspaco: sujo.indexOf(' ') >= 0 && sujo.indexOf(String.fromCharCode(10)) >= 0});
    })()`));
    ok('API: token real com espaço e quebra de linha no meio agora valida (antes: "Não foi possível validar o token")', r1.sucesso === true && !!r1.user && r1.temEspaco, r1);
    // 2) API: token inexistente devolve mensagem de token
    const r2 = JSON.parse(await c.avaliar(`(async function(){ var p = await ${post('testar_bot', "{token: '123456789:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'}")}; var j = await p.json(); return JSON.stringify({status:p.status, mensagem:j.mensagem}); })()`));
    ok('API: token que não existe devolve mensagem de token ("inválido"/"não encontrado")', /Token inválido|não encontrado/i.test(r2.mensagem), r2);
    // 3) tela: colar com espaço no campo do token e clicar em Testar
    await c.ir(BASE + '/bot'); await c.esperar(`!!document.getElementById('token')`, 15000); await esp(800);
    const sujoTela = await c.avaliar(`(async function(){ var b = await (await fetch('api.php?action=obter_bot&id=${ID_BOT}', {credentials:'include'})).json(); var t = b.bot.token; return t.slice(0,11) + ' ' + t.slice(11); })()`);
    await c.avaliar(`document.getElementById('token').focus(); 1`);
    await c.enviar('Input.insertText', { text: sujoTela });
    await esp(300);
    const lenCampo = await c.avaliar(`document.getElementById('token').value.length`);
    ok('colar com espaço: o campo tira o espaço na hora', (await c.avaliar(`document.getElementById('token').value.indexOf(' ')`)) === -1 && lenCampo === sujoTela.length - 1, { lenCampo, esperado: sujoTela.length - 1 });
    await c.avaliar(`document.getElementById('btn-testar-token').click(); 1`);
    await c.esperar(`/conectado|Não foi possível|inválido|encontrado|falar com o Telegram/i.test(document.getElementById('toast').innerText)`, 15000);
    const toast = await c.avaliar(`document.getElementById('toast').innerText`);
    ok('Testar na tela: "Conexão bem sucedida (Bot Online)"', /bem sucedida|Online/i.test(toast), { toast });
    ok('sem erros de JavaScript', c.errosJs().length === 0, c.errosJs());
  } finally { await c.fechar(); }
})();
