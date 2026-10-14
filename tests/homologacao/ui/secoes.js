const { lancar } = require('./cdp.js');
const fs = require('fs');
const BASE = process.env.HOMOLOG_URL || 'https://telegram.stackcode.com.br';
const SH = process.env.HOMOLOG_SAIDA || './';
const id = process.argv[2];
(async () => {
  const c = await lancar();
  try {
    await c.enviar('Emulation.setDeviceMetricsOverride', { width: 1500, height: 1250, deviceScaleFactor: 1, mobile: false });
    await c.ir(BASE + '/login');
    await c.avaliar(`localStorage.setItem('tema','dark'); document.getElementById('email').value='teste-homolog-fluxos@example.invalid'; document.getElementById('senha').value='${process.env.HOMOLOG_SENHA}'; document.querySelector('form[action="login"]').submit(); 1`);
    await c.esperar(`location.pathname.indexOf('login')===-1`, 20000); await c.esperarCarga();
    await c.ir(BASE + '/fluxo_basico?id=' + id);
    await c.esperar(`typeof jQuery!=='undefined' && jQuery('#lista-planos .painel-plano').length>=1`, 20000);
    for (const s of ['boasvindas','planos','pagamentos','upsell','orderbump','previas','packs','assinatura']) {
      await c.avaliar(`jQuery('.basico-nav-item[data-secao="${s}"]').click(); 1`);
      await new Promise(r => setTimeout(r, 600));
      if (s === 'previas') await c.avaliar(`jQuery('#pv-ativo').prop('checked', true).trigger('change'); 1`);
      if (s === 'packs') await c.avaliar(`jQuery('#pk-ativo').prop('checked', true).trigger('change'); 1`);
      if (s === 'assinatura') await c.avaliar(`jQuery('#as-antes-ativo').prop('checked', true).trigger('change'); jQuery('#as-dia-ativo').prop('checked', true).trigger('change'); jQuery('#as-exp-ativo').prop('checked', true).trigger('change'); 1`);
      await new Promise(r => setTimeout(r, 500));
      const r = await c.enviar('Page.captureScreenshot', { format: 'png' });
      fs.writeFileSync(SH + `secao_${s}.png`, Buffer.from(r.result.data, 'base64'));
    }
    console.log('ok; erros JS:', JSON.stringify(c.errosJs()));
  } catch (e) { console.log('ERRO', e.message); }
  await c.fechar();
})();
