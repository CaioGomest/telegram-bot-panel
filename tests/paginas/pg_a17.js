// ATENÇÃO (26/10/2026): a parte de REMARKETING deste teste mira a tela ANTIGA (janela "Nova campanha", filtros por bot/audiência, ações cancelar_campanha/contar_destinatarios), que foi trocada pela tela em etapas (tarefas_revisao_final.md, item 14). Esta parte não vale mais; a cobertura nova está em tests/remarketing/ (unitario_remarketing.php, ui/teste.js e integracao/rodar.js).
// ÁREA 8b: Remarketing — cancelar campanha pendente, aba "Falha", botão do estado vazio (rodada de 07/10/2026, noite)
// Uso: PGPW=... IDS='{"user":..,"bot1":..}' OUT=./pg node pg_a17.js   (precisa do seed: php zz_pg.php seed / user2)
const { lancar, entrar, BASE, db, remoto, autoDialogo } = require('./pg_lib.js');
const { area, t, salvar, esp } = require('./pg_t.js');
const IDS = JSON.parse(process.env.IDS || '{}');
(async () => {
  const c = await lancar();
  const U2 = JSON.parse(remoto('php zz_pg.php user2').trim());
  const futuro = (dias = 10) => { const d = new Date(Date.now() + dias * 86400000); const p = (n) => String(n).padStart(2, '0'); return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + ' 10:00:00'; };
  const post = (corpo) => c.avaliar(`fetch('remarketing.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json','Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(Object.assign({csrf_token:document.querySelector('input[name=csrf_token]').value},${JSON.stringify(corpo)})).toString()}).then(r=>r.text()).then(t=>({t:t}))`);
  const minhas = (extra = '') => db(`SELECT id, status FROM remarketing_campanhas WHERE id_usuario=${IDS.user} ${extra} ORDER BY id`);
  try {
    await entrar(c, 'pgteste-user@example.invalid');
    autoDialogo(c, true);
    area('8c. Remarketing — cancelar campanha pendente');
    await c.ir(BASE + '/remarketing');
    await post({ bot_id: String(IDS.bot1), nome: 'Para cancelar', audiencia: 'nao_comprou', mensagem: 'cancelar-me', agendado_em: futuro(20) });
    await post({ bot_id: String(IDS.bot1), nome: 'Para manter', audiencia: 'nao_comprou', mensagem: 'manter-me', agendado_em: futuro(21) });
    const cancelar = minhas(`AND nome='Para cancelar'`)[0];
    const manter = minhas(`AND nome='Para manter'`)[0];

    await t('campanha pendente tem botão de cancelar; a concluída não tem', async () => {
      await c.ir(BASE + '/remarketing'); await esp(500);
      const r = JSON.parse(await c.avaliar(`JSON.stringify([...document.querySelectorAll('tbody tr')].map(function(tr){return [tr.children[0].innerText.trim(), tr.children[5].innerText.trim(), !!tr.querySelector('.btn-cancelar-campanha-lista')]}))`));
      const pend = r.filter(x => /pendente/i.test(x[1])), conc = r.filter(x => /conclu/i.test(x[1]));
      return { ok: pend.length >= 2 && pend.every(x => x[2]) && conc.length >= 1 && conc.every(x => !x[2]), det: JSON.stringify(r) };
    }, 'remarketing');

    await t('cancelar na tela: "Cancelar" no aviso mantém a campanha', async () => {
      c.aceitar = false;
      await c.avaliar(`document.querySelector('.btn-cancelar-campanha-lista[data-id="${cancelar.id}"]').click(); 1`);
      await esp(1200);
      return { ok: minhas(`AND id=${cancelar.id}`).length === 1, det: 'continua no banco' };
    }, 'remarketing');

    await t('cancelar na tela: confirmar apaga só aquela campanha e a lista atualiza', async () => {
      c.aceitar = true;
      await c.avaliar(`document.querySelector('.btn-cancelar-campanha-lista[data-id="${cancelar.id}"]').click(); 1`);
      await esp(2500); await c.esperarCarga();
      const nomes = await c.avaliar(`document.querySelector('.tabela-dados').innerText`);
      return { ok: minhas(`AND id=${cancelar.id}`).length === 0 && minhas(`AND id=${manter.id}`).length === 1 && !/Para cancelar/.test(nomes) && /Para manter/.test(nomes), det: nomes.slice(0, 80) };
    }, 'remarketing');

    await t('campanha concluída NÃO pode ser cancelada pela API (continua no banco)', async () => {
      const conc = minhas(`AND status='concluida'`)[0];
      const r = JSON.parse((await post({ acao: 'cancelar_campanha', id: String(conc.id) })).t);
      return { ok: r.sucesso === false && minhas(`AND id=${conc.id}`).length === 1, det: JSON.stringify(r) };
    }, 'segurança');

    await t('SEGURANÇA: cancelar campanha de OUTRO usuário é recusado e ela continua existindo', async () => {
      const r = JSON.parse((await post({ acao: 'cancelar_campanha', id: String(U2.camp) })).t);
      const ainda = db(`SELECT COUNT(*) n FROM remarketing_campanhas WHERE id=${U2.camp}`)[0].n;
      return { ok: r.sucesso === false && Number(ainda) === 1, det: JSON.stringify(r) + ' / existe=' + ainda };
    }, 'segurança');

    await t('cancelar sem token CSRF é recusado (403) e não apaga', async () => {
      const r = await c.avaliar(`fetch('remarketing.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json','Content-Type':'application/x-www-form-urlencoded'},body:'acao=cancelar_campanha&id=${manter.id}'}).then(r=>r.status)`);
      return { ok: r === 403 && minhas(`AND id=${manter.id}`).length === 1, det: 'HTTP ' + r };
    }, 'segurança');

    await t('aba "Falha" existe e filtra (nenhuma campanha em falha → lista vazia sem erro)', async () => {
      await c.ir(BASE + '/remarketing?status=falha'); await esp(400);
      const r = JSON.parse(await c.avaliar(`JSON.stringify({aba:[...document.querySelectorAll('.seletor-periodo a')].map(a=>a.innerText.trim()), ativo:(document.querySelector('.seletor-periodo a.ativo')||{}).innerText, vazio:!!document.querySelector('.estado-vazio')})`));
      return { ok: r.aba.includes('Falha') && /falha/i.test(r.ativo || '') && r.vazio, det: JSON.stringify(r) };
    }, 'remarketing');

    await t('estado vazio mostra o botão "+ Nova campanha" e ele abre o modal', async () => {
      const b = await c.avaliar(`!!document.getElementById('btn-nova-campanha-empty')`);
      await c.avaliar(`document.getElementById('btn-nova-campanha-empty').click(); 1`); await esp(400);
      const aberto = await c.avaliar(`getComputedStyle(document.getElementById('modal-nova-campanha')).display !== 'none'`);
      return { ok: b && aberto, det: `botão=${b} modal=${aberto}` };
    }, 'remarketing');

    await t('data do agendamento tem mínimo = hoje (o calendário não deixa escolher dia passado)', async () => {
      const min = await c.avaliar(`document.getElementById('modal-data').min`);
      const hoje = new Date(); const p = (n) => String(n).padStart(2, '0');
      return { ok: min === `${hoje.getFullYear()}-${p(hoje.getMonth() + 1)}-${p(hoje.getDate())}`, det: min };
    }, 'remarketing');
  } catch (e) { console.log('ERRO', e.message); }
  finally { remoto('php zz_pg.php limparemk'); salvar(); await c.fechar(); process.exit(0); }
})();
