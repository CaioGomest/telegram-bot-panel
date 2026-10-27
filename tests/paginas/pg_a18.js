// ATENÇÃO (26/10/2026): a parte de REMARKETING deste teste mira a tela ANTIGA (janela "Nova campanha", filtros por bot/audiência, ações cancelar_campanha/contar_destinatarios), que foi trocada pela tela em etapas (tarefas_revisao_final.md, item 14). Esta parte não vale mais; a cobertura nova está em tests/remarketing/ (unitario_remarketing.php, ui/teste.js e integracao/rodar.js).
// ÁREA 8d: Remarketing — ponta a ponta: agenda pela tela, espera vencer, roda a cron (Telegram simulado SIMBOT) e confere o banco.
// Uso: PGPW=... IDS='{"user":..,"bot1":..}' OUT=./pg node pg_a18.js   (precisa do seed: php zz_pg.php seed)
const { lancar, entrar, BASE, db, remoto } = require('./pg_lib.js');
const { area, t, salvar, esp } = require('./pg_t.js');
const IDS = JSON.parse(process.env.IDS || '{}');
(async () => {
  const c = await lancar();
  const p = (n) => String(n).padStart(2, '0');
  const em = (min) => { const d = new Date(Date.now() + min * 60000); return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`; };
  const srv = () => db(`SELECT DATE_FORMAT(NOW(),'%Y-%m-%d %H:%i') a`)[0].a;   // hora do SERVIDOR (o relógio daqui pode estar adiantado)
  const post = (corpo) => c.avaliar(`fetch('remarketing.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json','Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(Object.assign({csrf_token:document.querySelector('input[name=csrf_token]').value},${JSON.stringify(corpo)})).toString()}).then(r=>r.text()).then(t=>({t:t}))`);
  const esperado = (aud) => Number(db(`SELECT COUNT(*) n FROM leads l WHERE l.bot_id=${IDS.bot1} AND ${aud === 'nao_comprou' ? 'NOT ' : ''}EXISTS (SELECT 1 FROM vendas v WHERE v.id_telegram=l.id_telegram AND v.bot_id=l.bot_id AND v.status='pago')`)[0].n);
  try {
    await entrar(c, 'pgteste-user@example.invalid');
    area('8d. Remarketing — envio de verdade pela cron (Telegram simulado)');
    await c.ir(BASE + '/remarketing');
    const alvo = { nao_comprou: esperado('nao_comprou'), comprou: esperado('comprou') };
    console.log('destinatários esperados:', JSON.stringify(alvo));
    const ids = {};
    for (const aud of ['nao_comprou', 'comprou']) {
      const r = JSON.parse((await post({ bot_id: String(IDS.bot1), nome: 'E2E ' + aud, audiencia: aud, mensagem: 'Oi, teste ponta a ponta', agendado_em: srv() })).t);
      ids[aud] = db(`SELECT id FROM remarketing_campanhas WHERE id_usuario=${IDS.user} AND nome='E2E ${aud}'`)[0].id;
      console.log('agendada', aud, JSON.stringify(r));
    }
    await t('cron NÃO envia campanha agendada para o futuro (só as vencidas)', async () => {
      const r = JSON.parse((await post({ bot_id: String(IDS.bot1), nome: 'E2E futura', audiencia: 'nao_comprou', mensagem: 'não pode sair', agendado_em: em(60 * 24 * 3) })).t);
      remoto('php cron/cron_remarketing.php');
      const f = db(`SELECT status, enviados FROM remarketing_campanhas WHERE nome='E2E futura'`)[0];
      return { ok: r.sucesso && f.status === 'pendente' && Number(f.enviados) === 0, det: JSON.stringify(f) };
    }, 'remarketing');
    for (const aud of ['nao_comprou', 'comprou']) {
      await t(`cron conclui a campanha "${aud}": total = leads da audiência, envios gravados, status concluída`, async () => {
        remoto('php cron/cron_remarketing.php');
        const k = db(`SELECT status, total_destinatarios t, entregues e, falhas f FROM remarketing_campanhas WHERE id=${ids[aud]}`)[0];
        const envios = Number(db(`SELECT COUNT(*) n FROM remarketing_envios WHERE campanha_id=${ids[aud]}`)[0].n);
        return { ok: k.status === 'concluida' && Number(k.t) === alvo[aud] && Number(k.e) + Number(k.f) === alvo[aud] && envios === alvo[aud], det: JSON.stringify(k) + ' envios=' + envios };
      }, 'remarketing');
    }
    await t('rodar a cron de novo não reenvia nada (campanha concluída não repete)', async () => {
      const antes = Number(db(`SELECT COUNT(*) n FROM remarketing_envios WHERE campanha_id IN (${ids.nao_comprou},${ids.comprou})`)[0].n);
      remoto('php cron/cron_remarketing.php');
      const depois = Number(db(`SELECT COUNT(*) n FROM remarketing_envios WHERE campanha_id IN (${ids.nao_comprou},${ids.comprou})`)[0].n);
      return { ok: antes === depois && antes > 0, det: `${antes} → ${depois}` };
    }, 'remarketing');
    await t('a lista na tela mostra as campanhas concluídas com os contadores certos e a de cancelar só na pendente', async () => {
      await c.ir(BASE + '/remarketing'); await esp(500);
      const l = JSON.parse(await c.avaliar(`JSON.stringify([...document.querySelectorAll('tbody tr')].map(function(tr){return [tr.children[0].innerText.trim(), tr.children[5].innerText.trim(), tr.children[6].innerText.trim(), tr.children[7].innerText.trim(), tr.children[8].innerText.trim(), !!tr.querySelector('.btn-cancelar-campanha-lista')]}))`));
      const a = l.find(x => x[0] === 'E2E nao_comprou'), f = l.find(x => x[0] === 'E2E futura');
      return { ok: !!a && /conclu/i.test(a[1]) && Number(a[2]) === alvo.nao_comprou && !a[5] && !!f && /pendente/i.test(f[1]) && f[5], det: JSON.stringify([a, f]) };
    }, 'remarketing');
  } catch (e) { console.log('ERRO', e.message); }
  finally { remoto('php zz_pg.php limparemk'); salvar(); await c.fechar(); process.exit(0); }
})();
