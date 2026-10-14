// Tira um retrato de cada bloco do editor visual (cada um com um recorte próprio).
const { lancar } = require('./cdp.js');
const fs = require('fs');
const BASE = process.env.HOMOLOG_URL || 'https://telegram.stackcode.com.br';
const SH = process.env.HOMOLOG_SAIDA || './';
const tema = process.argv[2] || 'dark';
fs.mkdirSync(SH, { recursive: true });
(async () => {
  const c = await lancar();
  try {
    await c.enviar('Emulation.setDeviceMetricsOverride', { width: 1900, height: 2100, deviceScaleFactor: 1, mobile: false });
    await c.ir(BASE + '/login');
    await c.avaliar(`localStorage.setItem('tema','${tema}'); document.getElementById('email').value='teste-homolog-fluxos@example.invalid'; document.getElementById('senha').value='${process.env.HOMOLOG_SENHA}'; document.querySelector('form[action="login"]').submit(); 1`);
    await c.esperar(`location.pathname.indexOf('login')===-1`, 20000); await c.esperarCarga();
    await c.ir(BASE + '/fluxo');
    await c.esperar(`typeof jQuery!=='undefined' && jQuery('.adicionar-no').length>=13`, 20000);
    await c.avaliar(`window.__drop = function(tipo, x, y){ var $=jQuery; var ev=$.Event('drop'); ev.originalEvent={preventDefault:function(){}, stopPropagation:function(){}, dataTransfer:{getData:function(){return tipo;}}, clientX:x, clientY:y}; $('.conteiner-fluxo').trigger(ev); }; 1`);
    const tipos = ['message','image','video','audio','botoes','pix','delay','link','grupo','randomizer','upsell','downsell','order_bump'];
    const col = [330, 680, 1030, 1380, 1730];
    for (const [i, t] of tipos.entries()) await c.avaliar(`window.__drop(${JSON.stringify(t)}, ${col[i % 5]}, ${i < 5 ? 200 : (i < 10 ? 820 : 1440)}); 1`);
    await c.avaliar(`(function(){ var $=jQuery; $('.no-imagem .campo-auto-deletar').click(); $('.no-imagem .campo-spoiler').click(); $('.no-video .campo-auto-deletar').click(); $('.no-botoes .btn-adicionar-botao').click(); $('.no-randomizer .btn-adicionar-caminho').click(); return 1; })()`);
    await new Promise(r => setTimeout(r, 1200));
    const r = await c.enviar('Page.captureScreenshot', { format: 'png' });
    fs.writeFileSync(SH + `tudo_${tema}.png`, Buffer.from(r.result.data, 'base64'));
    const ops = JSON.parse(await c.avaliar(`JSON.stringify(jQuery('.flowchart-operator').map(function(){ var b=this.getBoundingClientRect(); return {cls:this.className.replace(/\s+/g,' '), x:b.left, y:b.top, w:b.width, h:b.height}; }).get())`));
    fs.writeFileSync(SH + 'ops.json', JSON.stringify(ops, null, 1));
    for (const o of ops) {
      const nome = (o.cls.match(/no-([a-z-]+)/) || [0, 'x'])[1];
      const rr = await c.enviar('Page.captureScreenshot', { format: 'png', clip: { x: Math.max(0, o.x - 8), y: Math.max(0, o.y - 8), width: o.w + 16, height: o.h + 16, scale: 2 } });
      fs.writeFileSync(SH + `${nome}_${tema}.png`, Buffer.from(rr.result.data, 'base64'));
    }
    console.log('blocos:', ops.length, '| erros JS:', JSON.stringify(c.errosJs()));
  } catch (e) { console.log('ERRO', e.message); }
  await c.fechar();
})();
