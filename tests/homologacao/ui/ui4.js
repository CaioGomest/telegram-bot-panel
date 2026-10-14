const { lancar } = require('./cdp.js');
const BASE = process.env.HOMOLOG_URL || 'https://telegram.stackcode.com.br';
const SH = process.env.HOMOLOG_SAIDA || './';
const resultados = [];
function ok(rotulo, cond, extra) { resultados.push((cond ? 'PASS' : 'FAIL') + ' | ' + rotulo + (cond ? '' : ' | ' + JSON.stringify(extra))); console.log('  ' + resultados[resultados.length - 1]); }
(async () => {
  const c = await lancar();
  try {
    await c.ir(BASE + '/login');
    await c.avaliar(`document.getElementById('email').value='teste-homolog-fluxos@example.invalid'; document.getElementById('senha').value='${process.env.HOMOLOG_SENHA}'; document.querySelector('form[action="login"]').submit(); 1`);
    await c.esperar(`location.pathname.indexOf('login')===-1`, 20000); await c.esperarCarga();

    await c.ir(BASE + '/fluxos');
    await c.esperar(`jQuery('#lista-fluxos .cartao-fluxo').length>0`, 15000);
    const n0 = await c.avaliar(`jQuery('#lista-fluxos .cartao-fluxo').length`);
    const rot0 = await c.avaliar(`jQuery('#btn-abrir-escolha-modo').text().trim()`);
    ok('Meus Fluxos: botão mostra o contador (N/50) e há botão Importar Fluxo à esquerda', /\+ Novo Fluxo \(\d+\/50\)/.test(rot0) && await c.avaliar(`jQuery('#btn-importar-fluxo-lista').length===1 && jQuery('#btn-importar-fluxo-lista').index() < jQuery('#btn-abrir-escolha-modo').index()`), { rot0 });
    ok('contador bate com os cartões da lista', rot0.indexOf('(' + n0 + '/50)') !== -1, { rot0, n0 });
    // importa um arquivo .json pela interface (mesmo caminho do clique do usuário)
    const arq = await c.avaliar(`(async function(){ var h={'Content-Type':'application/json','X-CSRF-Token':window.CSRF_TOKEN}; var dados={operators:{start:{top:40,left:20,properties:{type:'start',title:'Início',inputs:{},outputs:{output_1:{label:'Próximo'}}}}},links:[]}; var c1=await fetch('api.php?action=salvar_fluxo',{method:'POST',credentials:'include',headers:h,body:JSON.stringify({nome:'Base do teste de importação',modo:'avancado',dados_fluxograma:dados})}); var id=(await c1.json()).fluxo.id; var r = await fetch('api.php?action=exportar_fluxo&id='+id,{credentials:'include'}); var j=await r.json(); return JSON.stringify(j.fluxo); })()`);
    await c.avaliar(`(function(){ var dt = new DataTransfer(); dt.items.add(new File([${JSON.stringify(arq)}], 'fluxo_235.json', {type:'application/json'})); var inp = document.getElementById('arquivo-importar-fluxo-lista'); inp.files = dt.files; inp.dispatchEvent(new Event('change', {bubbles:true})); return 1; })()`);
    await c.esperar(`jQuery('#lista-fluxos .cartao-fluxo').length===${n0 + 2}`, 15000);
    const n1 = await c.avaliar(`jQuery('#lista-fluxos .cartao-fluxo').length`);
    ok('importar pelo botão: novo cartão aparece e o contador sobe', n1 === n0 + 2 && (await c.avaliar(`jQuery('#btn-abrir-escolha-modo').text()`)).indexOf('(' + n1 + '/50)') !== -1, { n0, n1 });
    ok('toast "Fluxo importado com sucesso."', /importado/i.test(await c.avaliar(`jQuery('#toast').text()`) || ''), {});
    await c.captura(SH + 'ui_fluxos.png');

    await c.ir(BASE + '/bots');
    await c.esperar(`jQuery('#lista-bots .cartao-bot').length>0`, 15000);
    const bot = JSON.parse(await c.avaliar(`(function(){ var $=jQuery; var b=$('#lista-bots .cartao-bot').first(); return JSON.stringify({selos:b.find('.bot-selo').length, avatar:b.find('.bot-avatar').length, nome:b.find('.bot-nome').text(), user:b.find('.bot-username').text(), numeros:b.find('.bot-numeros strong').map(function(){return $(this).text();}).get(), fluxo:b.find('.bot-fluxo-linha span').text(), novo:$('#lista-bots .cartao-bot-novo').length, botao:$('#btn-novo-bot').text().trim()}); })()`));
    ok('Meus Bots: card com selos, avatar, @username, leads/vendas, fluxo conectado e card "Conectar Novo Bot"', bot.selos === 2 && bot.avatar === 1 && /^@/.test(bot.user) && bot.numeros.length === 2 && bot.novo === 1 && /\+ Novo Bot \(\d+\/50\)/.test(bot.botao), bot);
    await c.captura(SH + 'ui_bots.png');

    await c.ir(BASE + '/leads');
    ok('Leads: barra de busca nova (um campo só, sem retângulo dentro de retângulo) acima da tabela', await c.avaliar(`(function(){ var f=document.querySelector('.barra-filtros'); var i=document.querySelector('.campo-busca input[type=text]'); var cs=getComputedStyle(i); return !!f && !f.closest('.painel') && cs.borderTopWidth==='0px' && cs.backgroundColor==='rgba(0, 0, 0, 0)'; })()`), {});
    ok('Leads: sem botão Exportar CSV', await c.avaliar(`!document.body.innerText.includes('Exportar CSV')`), {});
    await c.captura(SH + 'ui_leads.png');

    await c.ir(BASE + '/comunidade');
    ok('Comunidade: página própria sem barra lateral nem cabeçalho do painel', await c.avaliar(`!document.querySelector('.barra-lateral') && !document.querySelector('.topo-painel') && !!document.querySelector('.pagina-comunidade')`), {});
    ok('nenhum erro de JavaScript nas telas visitadas', c.errosJs().length === 0, c.errosJs());
  } catch (e) { console.log('ERRO', e.message); resultados.push('FAIL | exceção: ' + e.message); }
  await c.fechar();
  console.log('RESUMO PASS=' + resultados.filter(r => r.startsWith('PASS')).length + ' FAIL=' + resultados.filter(r => r.startsWith('FAIL')).length);
})();
