// Recria a CONTA DE TESTE da VPS quando a anterior foi apagada (20/10/2026: o e-mail ...+vps1510 não existia mais e o login dele travou por 5 falhas).
// Faz UM cadastro pela tela e grava e-mail/senha em estado/usuario_novo.json (gitignored), que os outros scripts leem. O cadastro tem limite de 5/hora por IP.
// A conta nova NÃO tem bot nem gateway: só serve aos testes de tela (Guiado, Visual, listas). Uso: node 00_nova_conta.js [sufixo, ex.: vps2010]
const [DONO_USER, DONO_DOM] = (process.env.EMAIL_DONO || 'dono@exemplo.com').split('@'); // base dos e-mails de teste (aliases +admintest e +vps1510): informe o seu em EMAIL_DONO
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const PROJ = path.resolve(__dirname, '../../..');
const { lancar } = require(PROJ + '/tests/homologacao/ui/cdp.js');
const ESTADO = process.env.ESTADO || path.join(__dirname, 'estado'); fs.mkdirSync(ESTADO, { recursive: true });
const BASE = 'https://coyotebot.com.br';
const esp = (ms) => new Promise(r => setTimeout(r, ms));
const sufixo = process.argv[2] || 'vps' + new Date().toISOString().slice(8, 10) + new Date().toISOString().slice(5, 7);
const U = { nome: 'Teste VPS ' + sufixo, email: `${DONO_USER}+${sufixo}@${DONO_DOM}`, senha: 'Vps#' + crypto.randomBytes(9).toString('base64url') };
(async () => {
  const c = await lancar();
  try {
    await c.ir(BASE + '/cadastro');
    await c.esperar(`!!document.getElementById('nome')`, 10000);
    await c.avaliar(`(function(){var f=function(i,v){document.getElementById(i).value=v};
      f('nome',${JSON.stringify(U.nome)});f('email',${JSON.stringify(U.email)});f('senha',${JSON.stringify(U.senha)});f('confirma_senha',${JSON.stringify(U.senha)});
      document.querySelector('form button[type=submit]').click(); return 1})()`);
    await esp(2000); await c.esperarCarga();
    const url = await c.avaliar('location.pathname + location.search');
    const criou = /login/.test(url) && /sucesso=cadastro/.test(url);
    console.log((criou ? 'OK     ' : 'FALHOU ') + 'cadastro: conta criada e redirecionada para o login  -> ' + url);
    if (criou) {
      fs.writeFileSync(path.join(ESTADO, 'usuario_novo.json'), JSON.stringify(U, null, 1));
      console.log('conta de teste gravada em estado/usuario_novo.json: ' + U.email + ' (senha só no arquivo)');
    } else {
      console.log((await c.avaliar('document.body.innerText')).slice(0, 400));
      process.exitCode = 1;
    }
  } finally { await c.fechar(); }
})();
