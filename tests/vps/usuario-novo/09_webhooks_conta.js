// Teste 9: webhooks do usuário + Minha Conta (perfil, senha, foto). A senha nova fica gravada em usuario_novo.json ANTES de ser enviada.
process.env.BASE = 'https://coyotebot.com.br';
const fs = require('fs'), path = require('path'), crypto = require('crypto');
const ESTADO = process.env.ESTADO || path.join(__dirname, 'estado'); require('fs').mkdirSync(ESTADO, { recursive: true });
const PROJ = path.resolve(__dirname, '../../..');
const { lancar, entrar, autoDialogo } = require(PROJ + '/tests/paginas/pg_lib.js');
const arqU = path.join(ESTADO, 'usuario_novo.json');
const U = JSON.parse(fs.readFileSync(arqU, 'utf8'));
const BASE = 'https://coyotebot.com.br';
const esp = (ms) => new Promise(r => setTimeout(r, ms));
const linhas = (t) => String(t || '').split('\n').map(s => s.trim()).filter(Boolean);
let ok = 0, falha = 0;
const chk = (n, c, x = '') => { c ? ok++ : falha++; console.log((c ? 'OK     ' : 'FALHOU ') + n + (x ? '  -> ' + x : '')); };
const main = async (c) => linhas(await c.avaliar(`document.querySelector('main').innerText`)).join(' | ');
async function toast(c, ms = 9000) {
  const t0 = Date.now();
  while (Date.now() - t0 < ms) {
    const x = await c.avaliar(`(function(){var t=document.getElementById('toast');return t&&t.classList.contains('visivel')?t.innerText:''})()`).catch(() => '');
    if (x) return x; await esp(120);
  }
  return '';
}
async function aviso(c) { return await c.avaliar(`(function(){var a=[...document.querySelectorAll('.aviso, .alerta, [role=alert], #toast.visivel')].map(function(e){return e.innerText.trim()}).filter(Boolean);return a.join(' || ')})()`); }

(async () => {
  const c = await lancar();
  try {
    await c.enviar('Emulation.setDeviceMetricsOverride', { width: 1400, height: 1100, deviceScaleFactor: 1, mobile: false });
    autoDialogo(c, true);
    await entrar(c, U.email, U.senha);

    // ================= Webhooks
    console.log('\n--- Webhooks do usuário');
    const novoWebhook = async (nome, url, secret, marcar = true) => {
      await c.ir(BASE + '/webhooks'); await esp(1000);
      await c.avaliar(`document.getElementById('btn-novo-webhook').click(); 1`); await esp(500);
      await c.avaliar(`(function(){var f=document.querySelector('#modal-webhook form');var s=function(n,v){f.querySelector('[name='+n+']').value=v};s('nome',${JSON.stringify(nome)});s('url',${JSON.stringify(url)});s('secret',${JSON.stringify(secret)});
        if(${marcar}){[...f.querySelectorAll('[name="eventos[]"]')].forEach(function(i){i.checked=true})}; f.setAttribute('novalidate',''); var b=f.querySelector('button[type=submit]'); b.click(); return 1})()`);
      let t = await toast(c, 9000); if (!t) { await esp(2000); t = await aviso(c); }
      return t;
    };
    let t = await novoWebhook('Teste http', 'http://example.com/hook', '');
    chk('webhook com http (sem TLS): recusado', /https/i.test(t), '"' + t + '"');
    t = await novoWebhook('Teste loopback', 'https://127.0.0.1/hook', '');
    chk('webhook para 127.0.0.1: recusado (SSRF)', /p[úu]blic|inv|n[ãa]o permit|privad|interno/i.test(t), '"' + t + '"');
    t = await novoWebhook('Teste rede interna', 'https://10.0.0.5/hook', '');
    chk('webhook para 10.0.0.5: recusado (SSRF)', /p[úu]blic|inv|n[ãa]o permit|privad|interno/i.test(t), '"' + t + '"');
    t = await novoWebhook('Teste metadata', 'https://169.254.169.254/latest', '');
    chk('webhook para 169.254.169.254 (metadados de nuvem): recusado', /p[úu]blic|inv|n[ãa]o permit|privad|interno/i.test(t), '"' + t + '"');
    t = await novoWebhook('Teste localhost', 'https://localhost/hook', '');
    chk('webhook para localhost: recusado', /p[úu]blic|inv|n[ãa]o permit|privad|interno|resolv/i.test(t), '"' + t + '"');
    t = await novoWebhook('Webhook Teste VPS', 'https://example.com/webhook-teste-vps', 'segredo-do-teste-vps');
    console.log('   criar válido ->', '"' + t + '"');
    await c.ir(BASE + '/webhooks'); await esp(1200);
    let lista = await main(c);
    chk('webhook válido aparece na lista', /Webhook Teste VPS/.test(lista) && /example\.com/.test(lista), lista.slice(lista.indexOf('NOME'), lista.indexOf('NOME') + 260));
    const htmlTemSecret = await c.avaliar(`document.documentElement.innerHTML.indexOf('segredo-do-teste-vps')>=0`);
    chk('o secret do webhook não vaza no HTML da lista', !htmlTemSecret);
    const acoes = JSON.parse(await c.avaliar(`JSON.stringify([...document.querySelectorAll('main table button, main table a')].map(function(b){return (b.title||b.getAttribute('aria-label')||b.innerText||'').trim()+'|'+(b.getAttribute('data-id')||'')}))`));
    console.log('   ações na linha:', JSON.stringify(acoes));
    await c.captura(path.join(ESTADO, 'webhooks_lista.png'));

    // ================= Minha Conta
    console.log('\n--- Minha Conta');
    const enviarConta = async (valores) => {
      await c.ir(BASE + '/configuracao_usuario'); await esp(900);
      await c.avaliar(`(function(){var f=[...document.querySelectorAll('form')].find(function(x){return x.querySelector('[name=nome]')});var o=${JSON.stringify(valores)};Object.keys(o).forEach(function(k){f.querySelector('[name='+k+']').value=o[k]});f.setAttribute('novalidate','');f.submit();return 1})()`);
      await esp(2500); await c.esperarCarga();
      return aviso(c);
    };
    t = await enviarConta({ nome: 'Teste VPS Renomeado', apelido_publico: 'TesteVPS' });
    chk('atualizar nome e apelido', /sucesso|atualizad/i.test(t), '"' + t + '"');
    const dados = JSON.parse(await c.avaliar(`JSON.stringify({nome:document.querySelector('[name=nome]').value, apelido:document.querySelector('[name=apelido_publico]').value, email:document.querySelector('[name=email]').value})`));
    chk('dados persistem ao recarregar', dados.nome === 'Teste VPS Renomeado' && dados.apelido === 'TesteVPS' && dados.email === U.email, JSON.stringify(dados));
    t = await enviarConta({ nome: '<script>alert(1)</script>Nome XSS', apelido_publico: 'TesteVPS' });
    const xss = await c.avaliar(`document.documentElement.innerHTML.indexOf('<script>alert(1)</script>')>=0`);
    chk('nome com <script>: gravado sem executar/vazar HTML cru', !xss, 'aviso: "' + t + '"');
    await enviarConta({ nome: 'Teste VPS Renomeado', apelido_publico: 'TesteVPS' });
    t = await enviarConta({ email: 'isto-nao-e-email' });
    chk('e-mail inválido: recusado', /inv[áa]lid|e-?mail/i.test(t), '"' + t + '"');
    const eu = JSON.parse(await c.avaliar(`JSON.stringify({email:document.querySelector('[name=email]').value})`));

    // senha
    t = await enviarConta({ senha: 'NovaSenha#2026', confirmar_senha: 'Outra#2026', senha_atual: U.senha });
    chk('nova senha com confirmação diferente: recusada', /n[ãa]o conferem/i.test(t), '"' + t + '"');
    t = await enviarConta({ senha: 'NovaSenha#2026', confirmar_senha: 'NovaSenha#2026', senha_atual: '' });
    chk('nova senha sem informar a atual: recusada', /senha atual/i.test(t), '"' + t + '"');
    t = await enviarConta({ senha: 'NovaSenha#2026', confirmar_senha: 'NovaSenha#2026', senha_atual: 'errada-errada' });
    chk('nova senha com a atual errada: recusada', /incorret|atual|inv[áa]lid/i.test(t), '"' + t + '"');
    t = await enviarConta({ senha: 'abc', confirmar_senha: 'abc', senha_atual: U.senha });
    chk('nova senha curta (3 caracteres): recusada', /8|curta|m[ií]nim/i.test(t), '"' + t + '"');
    // senha certa: grava no arquivo ANTES
    const nova = 'Vps#' + crypto.randomBytes(9).toString('base64url');
    const antiga = U.senha;
    fs.writeFileSync(arqU, JSON.stringify({ ...U, senha: nova, senha_anterior: antiga }, null, 1));
    t = await enviarConta({ senha: nova, confirmar_senha: nova, senha_atual: antiga });
    console.log('   trocar senha ->', '"' + t + '"');
    const trocou = /sucesso|atualizad/i.test(t);
    chk('troca de senha com a atual correta', trocou, '"' + t + '"');
    if (!trocou) fs.writeFileSync(arqU, JSON.stringify(U, null, 1)); // não trocou: volta o arquivo
    else {
      // login com a nova e recusa da antiga
      await c.enviar('Network.clearBrowserCookies');
      const tenta = async (senha) => {
        await c.ir(BASE + '/login'); await c.esperar(`!!document.getElementById('email')`, 10000);
        await c.avaliar(`(function(){document.getElementById('email').value=${JSON.stringify(U.email)};document.getElementById('senha').value=${JSON.stringify(senha)};document.querySelector('form[action="login"]').submit();return 1})()`);
        await esp(2500); await c.esperarCarga(); return c.avaliar('location.pathname');
      };
      let p = await tenta(antiga);
      chk('login com a senha ANTIGA é recusado', /login/.test(p), p);
      p = await tenta(nova);
      chk('login com a senha NOVA entra', !/login/.test(p), p);
    }

    // foto de perfil
    console.log('\n--- Foto de perfil');
    const anexarFoto = async (nome, tipo, conteudoJs) => {
      await c.ir(BASE + '/configuracao_usuario'); await esp(900);
      await c.avaliar(`(function(){var i=document.querySelector('input[name=foto]');var dt=new DataTransfer();dt.items.add(new File([${conteudoJs}],${JSON.stringify(nome)},{type:${JSON.stringify(tipo)}}));i.files=dt.files;i.dispatchEvent(new Event('change',{bubbles:true}));return 1})()`);
      await esp(3000); await c.esperarCarga();
      return aviso(c);
    };
    const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
    t = await anexarFoto('falsa.png', 'image/png', `'<?php echo 1; ?>'`);
    chk('arquivo "falsa.png" com código PHP dentro: recusado', /inv|n[ãa]o|imagem|formato/i.test(t) && !/atualizada/i.test(t), '"' + t + '"');
    t = await anexarFoto('shell.php', 'image/png', `'<?php echo 1; ?>'`);
    chk('arquivo .php: recusado', !/atualizada/i.test(t), '"' + t + '"');
    t = await anexarFoto('ok.png', 'image/png', `Uint8Array.from(atob('${PNG}'),function(ch){return ch.charCodeAt(0)})`);
    console.log('   foto válida ->', '"' + t + '"');
    chk('PNG válido: foto atualizada', /atualizada/i.test(t), '"' + t + '"');
    const img = await c.avaliar(`(function(){var i=document.querySelector('.avatar img, img[src*="uploads"]');return i?i.getAttribute('src'):''})()`);
    console.log('   caminho da foto:', img);
    if (img) {
      const r = await fetch(BASE + '/' + img.replace(/^\//, ''));
      chk('a foto é servida como imagem', r.status === 200 && /image/.test(r.headers.get('content-type') || ''), r.status + ' ' + r.headers.get('content-type'));
    }
    await c.ir(BASE + '/configuracao_usuario'); await esp(800);
    await c.avaliar(`document.querySelector('#form-remover-foto') ? document.querySelector('#form-remover-foto').submit() : 0; 1`);
    await esp(2500); await c.esperarCarga();
    t = await aviso(c);
    chk('remover foto', /removida/i.test(t), '"' + t + '"');
    await c.captura(path.join(ESTADO, 'conta.png'));
  } catch (e) { console.log('ERRO', e.stack); falha++; }
  finally { try { c.pararDialogo(); } catch (e) {} await c.fechar(); }
  console.log(`\nRESUMO: ${ok} ok, ${falha} falhas`);
})();
