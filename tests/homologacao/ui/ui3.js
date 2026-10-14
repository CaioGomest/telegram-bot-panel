// Editor VISUAL (n8n) no Chrome de verdade: solta blocos na tela, digita nos campos de cada bloco, liga, salva, recarrega.
const { lancar } = require('./cdp.js');
const BASE = process.env.HOMOLOG_URL || 'https://telegram.stackcode.com.br';
const resultados = [];
function ok(rotulo, cond, extra) { resultados.push((cond ? 'PASS' : 'FAIL') + ' | ' + rotulo + (cond ? '' : ' | ' + JSON.stringify(extra))); console.log('  ' + resultados[resultados.length - 1]); }
(async () => {
  const c = await lancar();
  try {
    await c.ir(BASE + '/login');
    await c.avaliar(`document.getElementById('email').value='teste-homolog-fluxos@example.invalid'; document.getElementById('senha').value='${process.env.HOMOLOG_SENHA}'; document.querySelector('form[action="login"]').submit(); 1`);
    await c.esperar(`location.pathname.indexOf('login')===-1`, 20000); await c.esperarCarga();
    await c.ir(BASE + '/fluxo');
    ok('editor visual abre com a paleta de 13 blocos', await c.esperar(`typeof jQuery!=='undefined' && jQuery('.adicionar-no').length===13 && jQuery('.conteiner-fluxo').length`, 20000), {});
    await c.avaliar(`window.__drop = function(tipo, x, y){ var $=jQuery; var ev=$.Event('drop'); ev.originalEvent={preventDefault:function(){}, stopPropagation:function(){}, dataTransfer:{getData:function(){return tipo;}}, clientX:x, clientY:y}; $('.conteiner-fluxo').trigger(ev); }; window.__fc = function(){ return jQuery('#area-trabalho-fluxograma'); }; 1`);

    // solta os 13 tipos de bloco (todos os da paleta)
    const tipos = ['message', 'botoes', 'delay', 'upsell', 'pix', 'link', 'message', 'image', 'video', 'audio', 'grupo', 'randomizer', 'downsell', 'order_bump'];
    for (const [i, t] of tipos.entries()) await c.avaliar(`window.__drop(${JSON.stringify(t)}, 260+${(i % 5)}*260, 160+${Math.floor(i / 5)}*250); 1`);
    await new Promise(r => setTimeout(r, 800));
    const qtd = await c.avaliar(`Object.keys(__fc().flowchart('getData').operators).length`);
    ok('14 blocos soltos na tela (13 tipos da paleta + início): todos criados', qtd === 15, { qtd });

    // ids dos operadores por tipo, na ordem em que foram soltos
    const mapa = JSON.parse(await c.avaliar(`(function(){ var d=__fc().flowchart('getData').operators, r={}; Object.keys(d).forEach(function(k){ var t=d[k].properties.type; (r[t]=r[t]||[]).push(k); }); return JSON.stringify(r); })()`));
    console.log('MAPA=' + JSON.stringify(mapa));
    const op = (tipo, n = 0) => mapa[tipo][n];

    // digita nos campos de cada bloco como um usuário (os mesmos seletores da interface)
    const digitar = (tipo, n, campo, valor, ev = 'input') => c.avaliar(`(function(){ var $=jQuery; var el=$('.flowchart-operator.no-${tipo}').eq(${n}).find('${campo}').first(); if(!el.length) return 'sem campo ${campo}'; if(el.is(':checkbox')) el.prop('checked', ${JSON.stringify(valor)}); else el.val(${JSON.stringify(valor)}); el.trigger('${ev}').trigger('change'); return 'ok'; })()`);
    const falhas = [];
    const reg = async (p) => { const r = await p; if (r !== 'ok') falhas.push(r); };
    await reg(digitar('mensagem', 0, '.campo-conteudo', 'Olá! Mensagem criada pela UI visual.'));
    await reg(digitar('botoes', 0, '.campo-texto-botoes', 'Quer comprar agora?'));
    await c.avaliar(`jQuery('.flowchart-operator.no-botoes').eq(0).find('.campo-botao-texto').eq(0).val('Comprar UI').trigger('input').trigger('change'); jQuery('.flowchart-operator.no-botoes').eq(0).find('.campo-botao-texto').eq(1).val('Depois UI').trigger('input').trigger('change'); jQuery('.flowchart-operator.no-botoes').eq(0).find('.campo-botao-cor').eq(0).val('success').trigger('change'); 1`);
    await reg(digitar('botoes', 0, '.campo-sumir-apos-clique', true, 'change'));
    await reg(digitar('delay', 0, '.campo-delay-min', 1)); await reg(digitar('delay', 0, '.campo-delay-max', 1));
    await reg(digitar('delay', 0, '.campo-delay-digitando', false, 'change'));
    await reg(digitar('upsell', 0, '.campo-oferta-mensagem', 'Oferta UI: 25% de desconto')); await reg(digitar('upsell', 0, '.campo-oferta-valor', 25));
    await reg(digitar('upsell', 0, '.campo-oferta-aceitar', 'Quero UI')); await reg(digitar('upsell', 0, '.campo-oferta-recusar', 'Não UI'));
    await reg(digitar('pix', 0, '.campo-pix-nome', 'Produto UI')); await reg(digitar('pix', 0, '.campo-pix-valor', 80));
    await reg(digitar('pix', 0, '.campo-pix-expiracao-minutos', 20)); await reg(digitar('pix', 0, '.campo-pix-dias-acesso', 15));
    await reg(digitar('pix', 0, '.campo-pix-msg-instrucoes', 'Pague pela UI visual')); await reg(digitar('pix', 0, '.campo-pix-msg-confirmado', 'Aprovado pela UI visual!'));
    await reg(digitar('pix', 0, '.campo-pix-mostrar-qrcode', false, 'change'));
    await reg(digitar('link', 0, '.campo-link-url', 'https://exemplo.com/ui')); await reg(digitar('link', 0, '.campo-link-texto', 'Abrir UI'));
    await reg(digitar('mensagem', 1, '.campo-conteudo', 'Expirou pela UI'));
    await reg(digitar('grupo', 0, '.campo-grupo-texto', 'Entrar UI'));
    ok('todos os campos dos blocos aceitaram o preenchimento', falhas.length === 0, falhas);

    // ligações do funil
    const L = (de, cDe, para) => c.avaliar(`__fc().flowchart('createLink', 'l_${de}_${cDe}', {fromOperator:'${de}', fromConnector:'${cDe}', toOperator:'${para}', toConnector:'input_1'}); 1`);
    const inicio = mapa['start'][0];
    await L(inicio, 'output_1', op('message', 0));
    await L(op('message', 0), 'output_1', op('botoes', 0));
    await L(op('botoes', 0), 'output_0', op('delay', 0));
    await L(op('delay', 0), 'output_1', op('upsell', 0));
    await L(op('upsell', 0), 'output_aceito', op('pix', 0));
    await L(op('upsell', 0), 'output_recusado', op('pix', 0));
    await L(op('pix', 0), 'output_pago', op('link', 0));
    await L(op('pix', 0), 'output_nao_pago', op('message', 1));
    await L(op('botoes', 0), 'output_1', op('grupo', 0));

    const salvarTexto = async () => { await c.avaliar(`jQuery('#btn-salvar-fluxo').click(); 1`); };
    await c.avaliar(`jQuery('#nome-fluxo').val('UI Visual ${Date.now()}').trigger('input').trigger('change'); 1`);
    await salvarTexto();
    const salvou = await c.esperar(`jQuery('#id-fluxo').val() && jQuery('#id-fluxo').val().length>0`, 20000);
    ok('salvar pelo botão do editor: recebe o id do fluxo criado', salvou, { toast: await c.avaliar(`jQuery('#toast').text()`) });
    const id = await c.avaliar(`jQuery('#id-fluxo').val()`);
    console.log('FLUXO_VISUAL_ID=' + id);

    // recarrega do banco: nós e campos voltam iguais
    await c.ir(BASE + '/fluxo?id=' + id);
    await c.esperar(`typeof jQuery!=='undefined' && jQuery('.flowchart-operator').length>=14`, 20000);
    await c.avaliar(`window.__fc = function(){ return jQuery('#area-trabalho-fluxograma'); }; 1`);
    const rec = JSON.parse(await c.avaliar(`(function(){ var $=jQuery; var f=function(t,n,s){ var e=$('.flowchart-operator.no-'+t).eq(n).find(s).first(); return e.is(':checkbox')?e.is(':checked'):e.val(); };
      return JSON.stringify({ ops: $('.flowchart-operator').length, links: Object.keys(__fc().flowchart('getData').links).length,
        msg: f('mensagem',0,'.campo-conteudo'), botTxt: f('botoes',0,'.campo-texto-botoes'), b0: $('.flowchart-operator.no-botoes').eq(0).find('.campo-botao-texto').eq(0).val(), b1: $('.flowchart-operator.no-botoes').eq(0).find('.campo-botao-texto').eq(1).val(), sumir: f('botoes',0,'.campo-sumir-apos-clique'),
        dmin: f('delay',0,'.campo-delay-min'), up: f('upsell',0,'.campo-oferta-mensagem'), upv: f('upsell',0,'.campo-oferta-valor'), upa: f('upsell',0,'.campo-oferta-aceitar'),
        pn: f('pix',0,'.campo-pix-nome'), pv: f('pix',0,'.campo-pix-valor'), pe: f('pix',0,'.campo-pix-expiracao-minutos'), pd: f('pix',0,'.campo-pix-dias-acesso'), pi: f('pix',0,'.campo-pix-msg-instrucoes'), pc: f('pix',0,'.campo-pix-msg-confirmado'), pq: f('pix',0,'.campo-pix-mostrar-qrcode'),
        lu: f('link',0,'.campo-link-url'), lt: f('link',0,'.campo-link-texto'), m2: f('mensagem',1,'.campo-conteudo'), gt: f('grupo',0,'.campo-grupo-texto') }); })()`));
    ok('recarregar: 15 blocos e 9 ligações voltam do banco', rec.ops === 15 && rec.links === 9, rec);
    ok('recarregar: mensagem, botões (Comprar UI/Depois UI) e "sumir após clique"', rec.msg === 'Olá! Mensagem criada pela UI visual.' && rec.botTxt === 'Quer comprar agora?' && rec.b0 === 'Comprar UI' && rec.b1 === 'Depois UI' && rec.sumir === true, rec);
    ok('recarregar: delay, oferta, link e segunda mensagem', String(rec.dmin) === '1' && rec.up === 'Oferta UI: 25% de desconto' && String(rec.upv) === '25' && rec.upa === 'Quero UI' && rec.lu === 'https://exemplo.com/ui' && rec.lt === 'Abrir UI' && rec.m2 === 'Expirou pela UI' && rec.gt === 'Entrar UI', rec);
    ok('recarregar: bloco Pix (nome, valor, validade, dias, mensagens, QR desligado)', rec.pn === 'Produto UI' && String(rec.pv) === '80' && String(rec.pe) === '20' && String(rec.pd) === '15' && rec.pi === 'Pague pela UI visual' && rec.pc === 'Aprovado pela UI visual!' && rec.pq === false, rec);

    // exportar -> o botão faz download de um JSON válido (chamada do próprio editor)
    const exp = await c.avaliar(`(async function(){ var r = await fetch('api.php?action=exportar_fluxo&id=${id}', {credentials:'include'}); var j = await r.json(); return JSON.stringify({ok:j.sucesso, modo:j.fluxo && j.fluxo.modo, ops: j.fluxo && Object.keys(j.fluxo.dados_fluxograma.operators).length}); })()`);
    const e = JSON.parse(exp);
    ok('exportar o fluxo recém-criado: JSON com modo avancado e 15 blocos', e.ok && e.modo === 'avancado' && e.ops === 15, e);
    ok('nenhum erro de JavaScript durante todo o uso do editor visual', c.errosJs().length === 0, c.errosJs());
    await c.captura('' + (process.env.HOMOLOG_SAIDA || './') + 'ui_visual.png');
  } catch (e) { console.log('ERRO', e.message); resultados.push('FAIL | exceção: ' + e.message); }
  await c.fechar();
  console.log('RESUMO PASS=' + resultados.filter(r => r.startsWith('PASS')).length + ' FAIL=' + resultados.filter(r => r.startsWith('FAIL')).length);
})();
