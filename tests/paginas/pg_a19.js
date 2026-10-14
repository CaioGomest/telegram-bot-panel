// ÁREA 4c (08/10/2026): editor do Fluxo Guiado → caixa "Enviar também o QR Code (foto)" em Pagamentos, salvando no banco.
// Uso: PGPW=... OUT=./pg FLUXO=<id de um fluxo guiado do cliente5> node pg_a19.js   (conta do teste de estresse: cliente5@gmial.com)
const { lancar, entrar, BASE, db } = require('./pg_lib.js');
const { area, t, salvar, esp } = require('./pg_t.js');
const FLUXO = Number(process.env.FLUXO || 559);
(async () => {
  const c = await lancar();
  const pg = () => JSON.parse(db(`SELECT dados_fluxograma d FROM fluxos WHERE id=${FLUXO}`)[0].d).pagamentos || {};
  const original = pg();
  const salvarTela = async () => { await c.avaliar(`document.getElementById('btn-salvar-fluxo-basico').click(); 1`); await esp(2500); };
  try {
    await entrar(c, 'cliente5@gmial.com', '12345678');
    area('4c. Fluxo Guiado — QR Code do Pix');
    await t('Pagamentos tem a caixa "Enviar também o QR Code (foto)" e ela vem marcada num fluxo que nunca escolheu', async () => {
      await c.ir(BASE + '/fluxo_basico?id=' + FLUXO); await esp(1800);
      const r = JSON.parse(await c.avaliar(`JSON.stringify({existe: !!document.getElementById('pg-mostrar-qrcode'), marcada: !!(document.getElementById('pg-mostrar-qrcode')||{}).checked, rotulo: (document.getElementById('pg-mostrar-qrcode')||{}).nextElementSibling ? document.getElementById('pg-mostrar-qrcode').nextElementSibling.innerText : ''})`));
      return { ok: r.existe && r.marcada === !(original.qrcode_definido && original.mostrar_qrcode === false) && /QR Code/.test(r.rotulo), det: JSON.stringify(r) + ' | antes no banco: ' + JSON.stringify(original) };
    }, 'fluxos');
    await t('desmarcar e salvar grava mostrar_qrcode=false com qrcode_definido=true (escolha explícita)', async () => {
      await c.avaliar(`var q=document.getElementById('pg-mostrar-qrcode'); if(q.checked) q.click(); 1`);
      await salvarTela();
      const p = pg();
      return { ok: p.mostrar_qrcode === false && p.qrcode_definido === true, det: JSON.stringify(p) };
    }, 'fluxos');
    await t('reabrir o editor mantém desmarcada; marcar e salvar volta a mostrar_qrcode=true', async () => {
      await c.ir(BASE + '/fluxo_basico?id=' + FLUXO); await esp(1800);
      const desmarcada = await c.avaliar(`!document.getElementById('pg-mostrar-qrcode').checked`);
      await c.avaliar(`var q=document.getElementById('pg-mostrar-qrcode'); if(!q.checked) q.click(); 1`);
      await salvarTela();
      const p = pg();
      return { ok: desmarcada && p.mostrar_qrcode === true && p.qrcode_definido === true, det: `desmarcada=${desmarcada} | ${JSON.stringify(p)}` };
    }, 'fluxos');
    console.log('erros JS:', JSON.stringify(c.errosJs()));
  } catch (e) { console.log('ERRO', e.message); }
  finally { salvar(); await c.fechar(); process.exit(0); }
})();
