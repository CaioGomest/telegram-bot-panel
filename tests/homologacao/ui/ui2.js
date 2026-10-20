// Editor GUIADO no Chrome de verdade: preenche tudo, salva, recarrega e confere que o que foi digitado volta igual.
const { lancar } = require('./cdp.js');
const BASE = process.env.HOMOLOG_URL || 'https://telegram.stackcode.com.br';
const resultados = [];
function ok(rotulo, cond, extra) { resultados.push((cond ? 'PASS' : 'FAIL') + ' | ' + rotulo + (cond ? '' : ' | ' + JSON.stringify(extra))); console.log('  ' + resultados[resultados.length - 1]); }
(async () => {
  const c = await lancar();
  try {
    await c.ir(BASE + '/login');
    await c.avaliar(`document.getElementById('email').value='teste-homolog-fluxos@example.invalid'; document.getElementById('senha').value='${process.env.HOMOLOG_SENHA}'; document.querySelector('form[action="login"]').submit(); 1`);
    await c.esperar(`location.pathname.indexOf('login')===-1`, 20000);
    await c.esperarCarga();

    await c.ir(BASE + '/fluxo_basico');
    ok('editor guiado abre (jQuery e botão de plano disponíveis)', await c.esperar(`typeof jQuery!=='undefined' && jQuery('#btn-adicionar-plano').length>0`), {});
    const nome = 'UI Guiado ' + Date.now();
    await c.avaliar(`(function(){ var $=jQuery;
      $('#nome-fluxo').val(${JSON.stringify(nome)}).trigger('input').trigger('change');
      $('#bv-mensagem').val('Olá pela UI! Bem-vindo ao curso de teste.').trigger('input');
      $('#bv-cta').val('Quero entrar').trigger('input');
      $('#bv-cta-cor').val('success').trigger('change');
      $('#btn-adicionar-plano').click(); $('#btn-adicionar-plano').click();
      var p = $('#lista-planos .painel-plano');
      p.eq(0).find('.campo-plano-nome').val('Mensal UI').trigger('input');
      p.eq(0).find('.campo-plano-valor').val('19.90').trigger('input');
      p.eq(0).find('.campo-plano-dias').val('30').trigger('input');
      p.eq(0).find('.campo-plano-cor').val('primary').trigger('change');
      p.eq(1).find('.campo-plano-nome').val('Passe 2h UI').trigger('input');
      p.eq(1).find('.campo-plano-valor').val('5.00').trigger('input');
      p.eq(1).find('.campo-plano-dias').val('2').trigger('input');
      p.eq(1).find('.campo-plano-unidade').val('horas').trigger('change');
      return 1; })()`);
    await c.avaliar(`jQuery('#btn-salvar-fluxo-basico').click(); 1`);
    const salvou = await c.esperar(`jQuery('#id-fluxo').val() && jQuery('#id-fluxo').val().length>0`, 15000);
    ok('salvar pela interface: o editor recebe o id do fluxo criado', salvou, { toast: await c.avaliar(`jQuery('#toast').text()`) });
    const id = await c.avaliar(`jQuery('#id-fluxo').val()`);
    console.log('FLUXO_GUIADO_ID=' + id);
    ok('toast de sucesso na tela', /sucesso|salvo/i.test(await c.avaliar(`jQuery('#toast').text()`) || ''), {});

    // 2ª etapa: com o fluxo salvo as outras seções destravam (como na tela de verdade)
    ok('seções (Upsell, Packs...) livres no fluxo novo e continuam livres depois do 1º salvamento', await c.avaliar(`jQuery('.basico-nav-item[data-secao="upsell"]').hasClass('bloqueado')===false`), {});
    await c.avaliar(`jQuery('.basico-nav-item[data-secao="pagamentos"]').click(); 1`);
    await c.avaliar(`(function(){ var $=jQuery;
      $('#pg-msg-confirmado').val('Aprovado pela UI!').trigger('input');
      $('#pg-msg-instrucoes').val('Pague agora pela UI').trigger('input');
      $('#suporte-usuario').val('@suporte_ui').trigger('input'); return 1; })()`);
    await c.avaliar(`jQuery('.basico-nav-item[data-secao="upsell"]').click(); 1`);
    await c.avaliar(`(function(){ var $=jQuery; var up = $('[data-oferta="upsell"]'); up.find('.of-ativo').prop('checked', true).trigger('change');
      up.find('.of-plano').val(up.find('.of-plano option').last().val()).trigger('change');
      up.find('.of-desconto').val('15').trigger('input'); up.find('.of-mensagem').val('Upsell da UI').trigger('input'); return 1; })()`);
    await c.avaliar(`jQuery('.basico-nav-item[data-secao="orderbump"]').click(); 1`);
    await c.avaliar(`(function(){ var $=jQuery; var bp = $('[data-oferta="order_bump"]'); bp.find('.of-ativo').prop('checked', true).trigger('change');
      bp.find('.of-nome').val('Bônus UI').trigger('input'); bp.find('.of-valor-extra').val('7.5').trigger('input'); bp.find('.of-mensagem').val('Bump da UI').trigger('input'); return 1; })()`);
    await c.avaliar(`jQuery('.basico-nav-item[data-secao="assinatura"]').click(); 1`);
    await c.avaliar(`(function(){ var $=jQuery; $('#as-antes-ativo').prop('checked', true).trigger('change'); $('#as-dias .as-chip').first().click(); $('#as-desconto').val('10').trigger('input'); return 1; })()`);
    await c.avaliar(`jQuery('#btn-salvar-fluxo-basico').click(); 1`);
    await new Promise(r => setTimeout(r, 3000));
    ok('2º salvamento pela interface com todas as seções preenchidas: sucesso', /sucesso|salvo/i.test(await c.avaliar(`jQuery('#toast').text()`) || ''), { toast: await c.avaliar(`jQuery('#toast').text()`) });
    // recarrega do banco e confere os campos
    await c.ir(BASE + '/fluxo_basico?id=' + id);
    await c.esperar(`jQuery('#lista-planos .painel-plano').length>=2`, 15000);
    const v = await c.avaliar(`(function(){ var $=jQuery; var p=$('#lista-planos .painel-plano'); return JSON.stringify({
      nome: $('#nome-fluxo').val(), msg: $('#bv-mensagem').val(), cta: $('#bv-cta').val(), cor: $('#bv-cta-cor').val(),
      p0: [p.eq(0).find('.campo-plano-nome').val(), p.eq(0).find('.campo-plano-valor').val(), p.eq(0).find('.campo-plano-dias').val(), p.eq(0).find('.campo-plano-cor').val()],
      p1: [p.eq(1).find('.campo-plano-nome').val(), p.eq(1).find('.campo-plano-valor').val(), p.eq(1).find('.campo-plano-dias').val(), p.eq(1).find('.campo-plano-unidade').val()],
      conf: $('#pg-msg-confirmado').val(), instr: $('#pg-msg-instrucoes').val(), sup: $('#suporte-usuario').val(),
      upAtivo: $('[data-oferta="upsell"] .of-ativo').is(':checked'), upDesc: $('[data-oferta="upsell"] .of-desconto').val(), upMsg: $('[data-oferta="upsell"] .of-mensagem').val(),
      bpAtivo: $('[data-oferta="order_bump"] .of-ativo').is(':checked'), bpValor: $('[data-oferta="order_bump"] .of-valor-extra').val(), bpNome: $('[data-oferta="order_bump"] .of-nome').val(),
      asAntes: $('#as-antes-ativo').is(':checked'), asDesc: $('#as-desconto').val() }); })()`);
    const o = JSON.parse(v);
    ok('recarregar: nome, mensagem, CTA e cor voltam iguais', o.nome === nome && o.msg === 'Olá pela UI! Bem-vindo ao curso de teste.' && o.cta === 'Quero entrar' && o.cor === 'success', o);
    ok('recarregar: plano Mensal (R$ 19,90 / 30 / azul)', o.p0[0] === 'Mensal UI' && parseFloat(o.p0[1]) === 19.9 && o.p0[2] === '30' && o.p0[3] === 'primary', o.p0);
    ok('recarregar: plano em horas (Passe 2h, R$ 5,00, 2, horas)', o.p1[0] === 'Passe 2h UI' && parseFloat(o.p1[1]) === 5 && o.p1[2] === '2' && o.p1[3] === 'horas', o.p1);
    ok('recarregar: pagamentos e suporte', o.conf === 'Aprovado pela UI!' && o.instr === 'Pague agora pela UI' && o.sup === '@suporte_ui', o);
    ok('recarregar: upsell (15%) e order bump (R$ 7,50) ativos com seus textos', o.upAtivo && parseFloat(o.upDesc) === 15 && o.upMsg === 'Upsell da UI' && o.bpAtivo && parseFloat(o.bpValor) === 7.5 && o.bpNome === 'Bônus UI', o);
    ok('recarregar: avisos de renovação ligados e desconto de renovação 10%', o.asAntes === true && o.asDesc === '10', o);

    // validações do editor
    await c.avaliar(`(function(){ var $=jQuery; $('#lista-planos .painel-plano').eq(0).find('.campo-plano-valor').val('1.00').trigger('input'); $('[data-oferta="upsell"] .of-ativo').prop('checked', false).trigger('change'); $('#btn-salvar-fluxo-basico').click(); return 1; })()`);
    await new Promise(r => setTimeout(r, 2500));
    const toast = await c.avaliar(`jQuery('#toast').text()`);
    ok('plano de R$ 1,00 (abaixo do mínimo): o editor recusa e mostra o aviso do mínimo', /2,10|mínimo|OmegaPayments/i.test(toast || ''), { toast });
    ok('nenhum erro de JavaScript durante todo o uso do editor', c.errosJs().length === 0, c.errosJs());
    await c.captura('' + (process.env.HOMOLOG_SAIDA || './') + 'ui_guiado.png');
  } catch (e) { console.log('ERRO', e.message); resultados.push('FAIL | exceção: ' + e.message); }
  await c.fechar();
  console.log('RESUMO PASS=' + resultados.filter(r => r.startsWith('PASS')).length + ' FAIL=' + resultados.filter(r => r.startsWith('FAIL')).length);
})();
