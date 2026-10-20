// Comunidade (admin): mostra a estrutura da linha de um link e, com ACAO=excluir TITULO="..." , exclui SÓ o link com esse título.
// Uso: ADMIN_EMAIL=.. ADMIN_SENHA=.. [ACAO=desativar|excluir] [TITULO="Teste admin VPS (apagar)"] node 16_admin_comunidade.js
process.env.BASE = 'https://coyotebot.com.br';
const path = require('path');
const PROJ = path.resolve(__dirname, '../../..');
const { lancar, entrar, autoDialogo } = require(PROJ + '/tests/paginas/pg_lib.js');
const BASE = 'https://coyotebot.com.br';
const esp = (ms) => new Promise(r => setTimeout(r, ms));
const linhas = (t) => String(t || '').split('\n').map(s => s.trim()).filter(Boolean);
(async () => {
  const c = await lancar();
  try {
    autoDialogo(c, true);
    await c.enviar('Emulation.setDeviceMetricsOverride', { width: 1500, height: 1000, deviceScaleFactor: 1, mobile: false });
    await entrar(c, process.env.ADMIN_EMAIL, process.env.ADMIN_SENHA);
    await c.ir(BASE + '/admin/comunidade'); await esp(1200);
    const titulo = process.env.TITULO || 'Teste admin VPS (apagar)';
    const info = JSON.parse(await c.avaliar(`(function(){
      var rot=function(b){return (b.innerText||b.getAttribute('aria-label')||b.title||'').trim()};var titulo=${JSON.stringify(titulo)};var cand=[...document.querySelectorAll('main *')].filter(function(e){return e.innerText && e.innerText.indexOf(titulo)>=0 && [...e.querySelectorAll('button')].some(function(b){return rot(b)==='Excluir'})});cand.sort(function(a,b){return a.innerText.length-b.innerText.length});var alvo=cand[0];
      if(!alvo) return JSON.stringify({achou:false});
      var linha=alvo;
      return JSON.stringify({achou:true, tag:linha.tagName, classe:linha.className, botoes:[...linha.querySelectorAll('button')].map(function(b){return b.innerText.trim()+'|form='+(b.form?b.form.id+':'+[...b.form.elements].map(function(x){return x.name+'='+x.value}).join(','):'')})});
    })()`));
    console.log('linha do link:', JSON.stringify(info));
    if (info.achou && process.env.ACAO) {
      const rotulo = process.env.ACAO === 'excluir' ? 'Excluir' : 'Desativar';
      await c.avaliar(`(function(){
        var rot=function(b){return (b.innerText||b.getAttribute('aria-label')||b.title||'').trim()};var titulo=${JSON.stringify(titulo)};var cand=[...document.querySelectorAll('main *')].filter(function(e){return e.innerText && e.innerText.indexOf(titulo)>=0 && [...e.querySelectorAll('button')].some(function(b){return rot(b)==='Excluir'})});cand.sort(function(a,b){return a.innerText.length-b.innerText.length});var alvo=cand[0];
        var linha=alvo;
        var b=[...linha.querySelectorAll('button')].find(function(x){return rot(x)===${JSON.stringify(rotulo)}}); b.click(); return 1})()`);
      await esp(2500);
      await c.ir(BASE + '/admin/comunidade'); await esp(1000);
      console.log('depois de', rotulo, ':', linhas(await c.avaliar(`document.querySelector('main').innerText`)).join(' | ').slice(0, 400));
    }
  } finally { try { c.pararDialogo(); } catch (e) {} await c.fechar(); }
})();
