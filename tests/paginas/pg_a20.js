// ATENÇÃO (26/10/2026): a parte de REMARKETING deste teste mira a tela ANTIGA (janela "Nova campanha", filtros por bot/audiência, ações cancelar_campanha/contar_destinatarios), que foi trocada pela tela em etapas (tarefas_revisao_final.md, item 14). Esta parte não vale mais; a cobertura nova está em tests/remarketing/ (unitario_remarketing.php, ui/teste.js e integracao/rodar.js).
// ÁREA 4d (08/10/2026): textos e avisos da rodada "operação em escala" — conta de estresse cliente5 / clienteadm.
// Uso: PGPW=... OUT=./pg FLUXO_GUIADO=<id> FLUXO_VISUAL=<id> node pg_a20.js
const { lancar, entrar, BASE } = require('./pg_lib.js');
const { area, t, salvar, esp } = require('./pg_t.js');
const FG = Number(process.env.FLUXO_GUIADO || 559), FV = Number(process.env.FLUXO_VISUAL || 560);
(async () => {
  const c = await lancar();
  try {
    await entrar(c, 'cliente5@gmial.com', '12345678');
    area('4d. Textos e avisos (operação em escala)');
    await t('editor Guiado: plano sem grupo mostra o aviso "Sem grupo" e ele some ao escolher um grupo', async () => {
      await c.ir(BASE + '/fluxo_basico?id=' + FG); await esp(1800);
      // acha um plano e esvazia o grupo
      const r1 = JSON.parse(await c.avaliar(`(()=>{const s=document.querySelector('.campo-plano-grupo'); if(!s) return JSON.stringify({sem:'sem plano'}); s.value=''; s.dispatchEvent(new Event('change',{bubbles:true})); const d=s.closest('.campo').querySelector('.dica-sem-grupo'); return JSON.stringify({opcoes:s.options.length, visivel: !!d && !d.hidden, texto: d? d.innerText.slice(0,70):''})})()`));
      let ok = r1.visivel && /Sem grupo/.test(r1.texto);
      let det = JSON.stringify(r1);
      if (r1.opcoes > 1) {
        const r2 = JSON.parse(await c.avaliar(`(()=>{const s=document.querySelector('.campo-plano-grupo'); s.selectedIndex=1; s.dispatchEvent(new Event('change',{bubbles:true})); const d=s.closest('.campo').querySelector('.dica-sem-grupo'); return JSON.stringify({escondido: d.hidden})})()`));
        ok = ok && r2.escondido; det += ' / com grupo: ' + JSON.stringify(r2);
      } else { det += ' (conta sem grupos cadastrados: só conferi o aviso)'; }
      return { ok, det };
    }, 'fluxos');
    await t('editor Visual: paleta usa "Espera / Upsell / Downsell / Order bump" (sem "Delay")', async () => {
      await c.ir(BASE + '/fluxo?id=' + FV); await esp(2000);
      const r = JSON.parse(await c.avaliar(`JSON.stringify([...document.querySelectorAll('.paleta-blocos .adicionar-no')].map(b=>b.innerText.trim()))`));
      return { ok: r.includes('Espera') && r.includes('Upsell') && r.includes('Downsell') && r.includes('Order bump') && !r.includes('Delay') && !r.includes('Oferta extra'), det: r.join(' | ') };
    }, 'fluxos');
    await t('editor Visual: blocos já salvos com título antigo ("Delay") aparecem como "Espera"', async () => {
      const tem = "(não consulta o banco)";
      const vis = await c.avaliar(`[...document.querySelectorAll('.flowchart-operator-title')].map(e=>e.innerText.trim()).join('|')`);
      return { ok: !/Delay/i.test(vis), det: 'fluxo tinha "Delay" salvo: ' + tem + ' | títulos na tela: ' + String(vis).slice(0, 120) };
    }, 'fluxos');
    await t('Remarketing: filtro, tabela e formulário usam "Não comprou / Comprou"', async () => {
      await c.ir(BASE + '/remarketing'); await esp(800);
      const r = JSON.parse(await c.avaliar(`JSON.stringify({filtro:[...document.querySelectorAll('#form-filtro-audiencia option')].map(o=>o.innerText.trim()), modal:[...document.querySelectorAll('#modal-audiencia option')].map(o=>o.innerText.trim()), ajuda:/nunca pagou/i.test(document.getElementById('modal-nova-campanha').innerText)})`));
      return { ok: r.filtro.includes('Não comprou') && r.filtro.includes('Comprou') && r.modal.join('|') === 'Não comprou|Comprou' && r.ajuda && !JSON.stringify(r).includes('Acessou'), det: JSON.stringify(r) };
    }, 'remarketing');
    await t('Meus Bots: o card continua aparecendo (selo Online/Offline) e a rota da lista responde', async () => {
      await c.ir(BASE + '/bots'); await esp(1500);
      const r = JSON.parse(await c.avaliar(`JSON.stringify({cards: document.querySelectorAll('.js-bot-card').length, selos:[...document.querySelectorAll('.a-bot-bds .a-bd')].slice(0,3).map(e=>e.innerText.trim()), tokenRecusado: !!document.querySelector('.a-bot-bds .a-bd[title*="token"]')})`));
      return { ok: r.cards >= 1 && r.selos.length >= 1, det: JSON.stringify(r) };
    }, 'bots');
    await c.enviar('Network.clearBrowserCookies');
    await entrar(c, 'clienteadm@gmial.com', '12345678');
    await t('Admin: paginação mostra milhar com ponto (ex.: 1.690) em Logs e no dashboard', async () => {
      const achados = [];
      for (const r of ['/admin/logs', '/admin/dashboard']) {
        await c.ir(BASE + r); await esp(900);
        achados.push(r + ' → ' + await c.avaliar(`(()=>{const a=[...document.querySelectorAll('.paginacao-link')].map(x=>x.innerText.trim()).filter(x=>/^[\\d.]+$/.test(x)); return a[a.length-1]||''})()`));
      }
      return { ok: achados.every(x => /\d\.\d{3}/.test(x)), det: achados.join(' | ') };
    }, 'admin');
    console.log('erros JS:', JSON.stringify(c.errosJs()));
  } catch (e) { console.log('ERRO', e.message); }
  finally { salvar(); await c.fechar(); process.exit(0); }
})();
