// Teste 1 na VPS: cadastro (erros + sucesso) e login (erros + sucesso + logout). Chrome de verdade.
const [DONO_USER, DONO_DOM] = (process.env.EMAIL_DONO || 'dono@exemplo.com').split('@'); // base dos e-mails de teste (aliases +admintest e +vps1510): informe o seu em EMAIL_DONO
const fs = require('fs');
const path = require('path');
const ESTADO = process.env.ESTADO || path.join(__dirname, 'estado'); require('fs').mkdirSync(ESTADO, { recursive: true });
const crypto = require('crypto');
const PROJ = path.resolve(__dirname, '../../..');
const { lancar } = require(PROJ + '/tests/homologacao/ui/cdp.js');
const SP = ESTADO;
const BASE = 'https://coyotebot.com.br';
const esp = (ms) => new Promise(r => setTimeout(r, ms));

const arq = path.join(SP, 'usuario_novo.json');
let U;
if (fs.existsSync(arq)) U = JSON.parse(fs.readFileSync(arq, 'utf8'));
else {
  U = { nome: 'Teste VPS 15/10', email: `${DONO_USER}+vps1510@${DONO_DOM}`, senha: 'Vps#' + crypto.randomBytes(9).toString('base64url') };
  fs.writeFileSync(arq, JSON.stringify(U, null, 1));
}
let ok = 0, falha = 0;
const chk = (nome, cond, extra = '') => { if (cond) ok++; else falha++; console.log((cond ? 'OK     ' : 'FALHOU ') + nome + (extra ? '  -> ' + extra : '')); };

async function textoPagina(c) { return c.avaliar('document.body.innerText'); }
async function preencherCadastro(c, nome, email, s1, s2) {
  await c.ir(BASE + '/cadastro');
  await c.esperar(`!!document.getElementById('nome')`, 10000);
  await c.avaliar(`(function(){var f=function(i,v){document.getElementById(i).value=v};
    f('nome',${JSON.stringify(nome)});f('email',${JSON.stringify(email)});f('senha',${JSON.stringify(s1)});f('confirma_senha',${JSON.stringify(s2)});
    // email invalido: tira a validacao do navegador para provar a do servidor
    document.querySelector('form').setAttribute('novalidate','');
    document.querySelector('form button[type=submit]').click(); return 1})()`);
  await esp(1800);
  await c.esperarCarga();
}

(async () => {
  const c = await lancar();
  try {
    await c.enviar('Emulation.setDeviceMetricsOverride', { width: 1400, height: 900, deviceScaleFactor: 1, mobile: false });

    // ---- páginas públicas
    for (const rota of ['/login', '/cadastro', '/termos']) {
      c.eventos.length = 0; await c.ir(BASE + rota);
      const st = c.eventos.filter(e => e.method === 'Network.responseReceived' && e.params.type === 'Document').map(e => e.params.response.status)[0];
      const t = await textoPagina(c);
      chk(`GET ${rota} abre`, st === 200, 'HTTP ' + st + ' | js=' + c.errosJs().length);
      chk(`GET ${rota} sem erro PHP`, !/(Warning|Notice|Fatal error|Parse error|Uncaught)/i.test(t));
    }

    // ---- cadastro: erros
    await preencherCadastro(c, U.nome, U.email, 'abc12', 'abc12');
    let t = await textoPagina(c);
    chk('cadastro: senha curta é recusada', /pelo menos 8/i.test(t), t.match(/A senha[^\n]*/)?.[0] || '');

    await preencherCadastro(c, U.nome, 'isto-nao-e-email', U.senha, U.senha);
    t = await textoPagina(c);
    chk('cadastro: e-mail inválido é recusado', /Email inválido/i.test(t));

    await preencherCadastro(c, U.nome, U.email, U.senha, U.senha + 'x');
    t = await textoPagina(c);
    chk('cadastro: senhas diferentes são recusadas', /não conferem/i.test(t));

    // ---- cadastro: sucesso
    await preencherCadastro(c, U.nome, U.email, U.senha, U.senha);
    const url = await c.avaliar('location.pathname + location.search');
    t = await textoPagina(c);
    chk('cadastro: conta criada e redireciona para o login', /login/.test(url) && /sucesso=cadastro/.test(url), url);
    console.log('   aviso na tela de login:', (t.match(/(conta|cadastro)[^\n]{0,100}/i) || [''])[0]);

    // ---- cadastro duplicado
    await preencherCadastro(c, U.nome, U.email, U.senha, U.senha);
    t = await textoPagina(c);
    chk('cadastro: e-mail repetido é recusado', /já está cadastrado/i.test(t));

    // ---- login: erros
    async function login(email, senha) {
      await c.enviar('Network.clearBrowserCookies');
      await c.ir(BASE + '/login');
      await c.esperar(`!!document.getElementById('email')`, 10000);
      await c.avaliar(`(function(){document.getElementById('email').value=${JSON.stringify(email)};document.getElementById('senha').value=${JSON.stringify(senha)};document.querySelector('form[action="login"]').submit();return 1})()`);
      await esp(2500); await c.esperarCarga();
      return { url: await c.avaliar('location.pathname'), texto: await textoPagina(c) };
    }
    let r = await login(U.email, 'senha-errada-123');
    chk('login: senha errada é recusada e fica no login', /login/.test(r.url) && /(inválid|incorret|senha)/i.test(r.texto), r.texto.match(/(inválid|incorret)[^\n]*/i)?.[0] || '');
    r = await login('naoexiste@example.invalid', 'qualquer123');
    chk('login: e-mail inexistente é recusado', /login/.test(r.url));
    // login certo
    r = await login(U.email, U.senha);
    chk('login: credenciais corretas entram no painel', !/login/.test(r.url), r.url);
    const corpo = r.texto;
    chk('painel mostra o nome do usuário ou menu', /Teste VPS|Bots|Fluxos|Vendas/i.test(corpo));
    console.log('   aviso de e-mail não verificado?', /confirm|verific/i.test(corpo) ? 'sim' : 'não');
    await c.captura(path.join(SP, 'painel_inicial.png'));

    // logout
    await c.ir(BASE + '/logout');
    await esp(800);
    await c.ir(BASE + '/bots');
    const u2 = await c.avaliar('location.pathname');
    chk('logout encerra a sessão (/bots redireciona para login)', /login/.test(u2), u2);
  } catch (e) { console.log('ERRO', e.stack); falha++; }
  finally { await c.fechar(); }
  console.log(`\nRESUMO: ${ok} ok, ${falha} falhas`);
  process.exit(falha ? 1 : 0);
})();
