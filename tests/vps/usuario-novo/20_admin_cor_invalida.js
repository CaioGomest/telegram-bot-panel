// Identidade visual (admin) com POST direto — o <input type=color> do navegador troca valor inválido por #000000, então a validação do SERVIDOR só se vê assim.
// Confere: cor inválida é recusada, o nome enviado junto NÃO é gravado (tudo ou nada) e a cor nunca vira CSS arbitrário. Uso: ADMIN_EMAIL=.. ADMIN_SENHA=.. node 20_admin_cor_invalida.js
process.env.BASE = 'https://coyotebot.com.br';
const fs = require('fs'), path = require('path');
const PROJ = path.resolve(__dirname, '../../..');
const { lancar, entrar } = require(PROJ + '/tests/paginas/pg_lib.js');
const BASE = 'https://coyotebot.com.br';
const esp = (ms) => new Promise(r => setTimeout(r, ms));
let ok = 0, falha = 0;
const chk = (n, c, x = '') => { c ? ok++ : falha++; console.log((c ? 'OK     ' : 'FALHOU ') + n + (x ? '  -> ' + String(x).slice(0, 200) : '')); };
(async () => {
  const c = await lancar();
  try {
    await entrar(c, process.env.ADMIN_EMAIL, process.env.ADMIN_SENHA);
    await c.ir(BASE + '/admin/configuracoes'); await esp(900);
    const ler = () => c.avaliar(`JSON.stringify({nome:document.querySelector('#form-identidade [name=nome_sistema]').value, cor:document.querySelector('#form-identidade [name=cor_primaria]').value})`).then(JSON.parse);
    const orig = await ler();
    console.log('   originais: nome="' + orig.nome + '" cor=' + orig.cor);
    const postar = (campos) => c.avaliar(`(async function(){
      var tk=[...document.querySelectorAll('input[type=hidden]')].find(function(i){return /csrf/i.test(i.name)}); var fd=new FormData(); fd.append(tk.name, tk.value); fd.append('acao','identidade');
      var o=${JSON.stringify(campos)}; Object.keys(o).forEach(function(k){fd.append(k,o[k])});
      var r=await fetch('/admin/configuracoes',{method:'POST',body:fd,credentials:'include'}); var t=await r.text(); var d=new DOMParser().parseFromString(t,'text/html');
      return JSON.stringify({status:r.status,url:r.url.replace(location.origin,''),msgs:[...d.querySelectorAll('.aviso')].map(function(e){return e.textContent.trim()}).filter(Boolean).join(' || ')}) })()`).then(JSON.parse);
    for (const cor of ['azul', '#12345', '#gggggg', 'red', '#ff6a1a;background:red', '#ff6a1a</style><script>alert(1)</script>', '']) {
      const r = await postar({ nome_sistema: 'ATOMICO-NAO-DEVE-SALVAR', cor_primaria: cor });
      await c.ir(BASE + '/admin/configuracoes'); await esp(500);
      const agora = await ler();
      chk('cor "' + cor.slice(0, 22) + '": recusada e o nome enviado junto não foi gravado', /Cor inválida/.test(r.msgs) && agora.nome === orig.nome && agora.cor === orig.cor, r.msgs + ' | nome=' + agora.nome + ' cor=' + agora.cor);
    }
    // válida em maiúsculas: aceita e gravada em minúsculas, sem mudar o visual
    const r2 = await postar({ nome_sistema: orig.nome, cor_primaria: orig.cor.toUpperCase() });
    await c.ir(BASE + '/admin/configuracoes'); await esp(500);
    const depois = await ler();
    chk('cor válida em MAIÚSCULAS: aceita e gravada em minúsculas', /atualizada/.test(r2.msgs) && depois.cor === orig.cor.toLowerCase(), r2.msgs + ' | cor=' + depois.cor);
    chk('nome e cor ficaram como no início', depois.nome === orig.nome && depois.cor === orig.cor.toLowerCase());
  } catch (e) { console.log('ERRO', e.stack); falha++; }
  finally { await c.fechar(); }
  console.log(`\nRESUMO: ${ok} ok, ${falha} falhas`);
})();
