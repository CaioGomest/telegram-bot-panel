// ATENÇÃO (26/10/2026): a parte de REMARKETING deste teste mira a tela ANTIGA (janela "Nova campanha", filtros por bot/audiência, ações cancelar_campanha/contar_destinatarios), que foi trocada pela tela em etapas (tarefas_revisao_final.md, item 14). Esta parte não vale mais; a cobertura nova está em tests/remarketing/ (unitario_remarketing.php, ui/teste.js e integracao/rodar.js).
// ÁREA 8: Remarketing (lista, filtros, nova campanha, validações, detalhes, segurança)
const { lancar, entrar, BASE, db, remoto } = require('./pg_lib.js');
const { area, t, nota, salvar, esp } = require('./pg_t.js');
(async () => {
  const c = await lancar();
  remoto('php zz_pg.php limparemk');
  const U2 = JSON.parse(remoto('php zz_pg.php user2').trim());
  const futuro = (dias = 10) => { const d = new Date(Date.now() + dias * 86400000); const p = (n) => String(n).padStart(2, '0'); return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + ' 10:00:00'; };
  const post = (corpo) => c.avaliar(`fetch('remarketing.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json','Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':window.CSRF_TOKEN||''},body:new URLSearchParams(${JSON.stringify(corpo)}).toString()}).then(async r=>({http:r.status,t:await r.text()}))`);
  const campanhas = () => db(`SELECT id, nome, bot_id, id_usuario, status, LEFT(mensagem,30) m, agendado_em FROM remarketing_campanhas WHERE id_usuario=49 ORDER BY id`);
  try {
    await entrar(c, 'pgteste-user@example.invalid');
    area('8. Remarketing');
    await c.ir(BASE + '/remarketing');
    await t('lista mostra a campanha semeada com nome, bot, audiência, mensagem, agendamento, status e contadores 10/8/2', async () => {
      const l = JSON.parse(await c.avaliar(`JSON.stringify([...document.querySelectorAll('table tbody tr')].map(function(tr){return [...tr.children].map(function(td){return td.innerText.trim()})}))`));
      const x = l.find(r => /Campanha Remarketing PG/.test(r[0]));
      return { ok: !!x && /Bot Um/.test(x[1]) && /não comprou/i.test(x[2]) && /Oi!/.test(x[3]) && /01\/01\/2030 10:00/.test(x[4]) && /conclu/i.test(x[5]) && x[6] === '10' && x[7] === '8' && x[8] === '2', det: JSON.stringify(x) };
    }, 'remarketing');
    await t('ícone de olho abre os detalhes da campanha certa; campanha de outro usuário mostra "não encontrada" sem dados', async () => {
      const id = db(`SELECT id FROM remarketing_campanhas WHERE id_usuario=49 LIMIT 1`)[0].id;
      await c.ir(BASE + '/remarketing_detalhes?campanha=' + id);
      const meu = await c.avaliar(`document.querySelector('.conteudo-principal').innerText`);
      await c.ir(BASE + '/remarketing_detalhes?campanha=' + U2.camp);
      const alheio = await c.avaliar(`document.querySelector('.conteudo-principal').innerText`);
      return { ok: /Campanha Remarketing PG/i.test(meu) && !/Campanha U2/i.test(alheio) && /não encontrad|inexistente|não existe/i.test(alheio), det: alheio.slice(0, 80).replace(/\n/g, ' ') };
    }, 'segurança');
    await t('detalhes: filtros resultado=sucesso/falha/todos e valor inválido não quebram; página 999 é ajustada', async () => {
      const id = db(`SELECT id FROM remarketing_campanhas WHERE id_usuario=49 LIMIT 1`)[0].id;
      const falhas = [];
      for (const q of ['&resultado=sucesso', '&resultado=falha', '&resultado=todos', '&resultado=xyz', '&pagina=999', '&pagina=-1', "&resultado=' OR 1=1--"]) {
        await c.ir(BASE + '/remarketing_detalhes?campanha=' + id + q);
        const tx = await c.avaliar('document.body.innerText');
        if (/Warning|Fatal|Notice:|SQLSTATE|syntax error/i.test(tx)) falhas.push(q);
      }
      return { ok: !falhas.length, det: falhas.join(' ') };
    }, 'remarketing');
    await t('filtros: bot, audiência, status e itens por página preservam os valores e filtram a lista', async () => {
      const casos = [['?audiencia=comprou', 0], ['?audiencia=nao_comprou', 1], ['?status=concluida', 1], ['?status=pendente', 0], ['?bot_id=312', 0], ['?bot_id=311', 1], ['?limite=50&status=concluida', 1]];
      const falhas = [];
      for (const [q, esperado] of casos) {
        await c.ir(BASE + '/remarketing' + q);
        const n = await c.avaliar(`[...document.querySelectorAll('table tbody tr')].filter(function(tr){return !tr.querySelector('td[colspan]')}).length`);
        if (n !== esperado) falhas.push(`${q}: esperado ${esperado}, veio ${n}`);
      }
      return { ok: !falhas.length, det: falhas.join('; ') };
    }, 'remarketing');
    await t('filtros com valores inválidos (bot de outro usuário, limite absurdo, status estranho) não quebram nem mostram campanhas alheias', async () => {
      await c.ir(BASE + '/remarketing?bot_id=' + U2.bot + '&limite=99999&status=%27%20OR%201=1--&audiencia=xyz');
      const tx = await c.avaliar('document.body.innerText');
      const alheia = /Campanha U2/.test(tx);
      return { ok: !/Warning|Fatal|Notice:|SQLSTATE/i.test(tx) && !alheia, det: alheia ? 'MOSTROU campanha alheia' : '' };
    }, 'segurança');

    area('8b. Nova campanha');
    await c.ir(BASE + '/remarketing');
    await t('"+ Nova campanha" abre o modal com bot, audiência, mensagem, mídia, data e hora; Esc e ✕ fecham', async () => {
      await c.avaliar(`document.getElementById('btn-nova-campanha').click(); 1`);
      await esp(600);
      const r = JSON.parse(await c.avaliar(`JSON.stringify({vis:getComputedStyle(document.getElementById('modal-nova-campanha')).display!=='none', campos:['modal-bot_id','modal-audiencia','modal-mensagem','modal-midia','modal-data','modal-hora','modal-nome'].every(function(i){return !!document.getElementById(i)}), bots:[...document.querySelectorAll('#modal-bot_id option')].map(function(o){return o.textContent.trim()}).join(',')})`));
      await c.avaliar(`document.dispatchEvent(new KeyboardEvent('keydown',{key:'Escape'})); 1`);
      await esp(300);
      const f1 = await c.avaliar(`getComputedStyle(document.getElementById('modal-nova-campanha')).display==='none'`);
      await c.avaliar(`document.getElementById('btn-nova-campanha').click(); document.getElementById('btn-fechar-modal').click(); 1`);
      const f2 = await c.avaliar(`getComputedStyle(document.getElementById('modal-nova-campanha')).display==='none'`);
      return { ok: r.vis && r.campos && /Bot Um/.test(r.bots) && /Bot Dois/.test(r.bots) && f1 && f2, det: JSON.stringify(r) };
    }, 'remarketing');
    await t('contador de destinatários estima certo: Bot Um/"não comprou" e Bot Um/"comprou" batem com o banco', async () => {
      const esperadoNao = db(`SELECT COUNT(*) n FROM leads l WHERE l.bot_id=311 AND NOT EXISTS (SELECT 1 FROM vendas v WHERE v.id_telegram=l.id_telegram AND v.bot_id=l.bot_id AND v.status='pago')`)[0].n;
      const a = await c.avaliar(`fetch('remarketing.php?action=contar_destinatarios&bot_id=311&audiencia=nao_comprou').then(r=>r.json())`);
      const b = await c.avaliar(`fetch('remarketing.php?action=contar_destinatarios&bot_id=311&audiencia=comprou').then(r=>r.json())`);
      return { ok: a.total === esperadoNao && b.total === 2, det: `não comprou ${a.total} (banco ${esperadoNao}), comprou ${b.total}` };
    }, 'remarketing');
    await t('contar_destinatarios de bot de OUTRO usuário é recusado', async () => {
      const r = await c.avaliar(`fetch('remarketing.php?action=contar_destinatarios&bot_id=${U2.bot}&audiencia=nao_comprou').then(r=>r.json())`);
      return { ok: r.sucesso === false && r.total === undefined, det: JSON.stringify(r) };
    }, 'segurança');
    await t('criar sem mensagem → "Informe bot e mensagem."; sem data → pede data; data passada → "horário já passou"; audiência inválida → recusada', async () => {
      const base = { bot_id: '311', audiencia: 'nao_comprou', mensagem: 'Oi', agendado_em: futuro() };
      const rs = [];
      for (const [nome, corpo, rx] of [['sem msg', { mensagem: '' }, /Informe bot e mensagem/], ['sem data', { agendado_em: '' }, /data e a hora/], ['passada', { agendado_em: '2020-01-01 10:00:00' }, /já passou/], ['audiencia', { audiencia: 'todos' }, /Audiência inválida/], ['sem bot', { bot_id: '0' }, /Informe bot/]]) {
        const r = await post(Object.assign({}, base, corpo));
        const j = (() => { try { return JSON.parse(r.t); } catch (e) { return { mensagem: r.t.slice(0, 80) }; } })();
        if (!rx.test(j.mensagem || '')) rs.push(`${nome}: ${JSON.stringify(j).slice(0, 80)}`);
      }
      return { ok: !rs.length, det: rs.join('; ') };
    }, 'remarketing');
    await t('criar campanha válida: agenda para a data escolhida, grava como pendente e aparece na lista', async () => {
      const antes = campanhas().length;
      const r = await post({ bot_id: '311', nome: 'Campanha criada no teste', audiencia: 'nao_comprou', mensagem: 'Mensagem de teste *negrito*', agendado_em: futuro(10) });
      const j = JSON.parse(r.t);
      const dep = campanhas();
      await c.ir(BASE + '/remarketing');
      const naLista = await c.avaliar(`document.body.innerText.indexOf('Campanha criada no teste')>=0`);
      global.__camp = dep[dep.length - 1];
      return { ok: j.sucesso && /agendada/i.test(j.mensagem) && dep.length === antes + 1 && global.__camp.status === 'pendente' && global.__camp.id_usuario === 49 && naLista, det: j.mensagem };
    }, 'remarketing');
    await t('SEGURANÇA: agendar campanha usando o bot de OUTRO usuário deve ser recusado', async () => {
      const antes = db(`SELECT COUNT(*) n FROM remarketing_campanhas WHERE bot_id=${U2.bot}`)[0].n;
      const r = await post({ bot_id: String(U2.bot), nome: 'Via bot alheio', audiencia: 'nao_comprou', mensagem: 'spam', agendado_em: futuro(20) });
      const j = (() => { try { return JSON.parse(r.t); } catch (e) { return {}; } })();
      const dep = db(`SELECT COUNT(*) n FROM remarketing_campanhas WHERE bot_id=${U2.bot}`)[0].n;
      return { ok: !j.sucesso && dep === antes, det: `resposta=${JSON.stringify(j).slice(0, 100)} campanhas no bot alheio ${antes}→${dep}` };
    }, 'segurança');
    await t('mensagem com mais de 4096 caracteres (limite do Telegram) é recusada na criação', async () => {
      const r = await post({ bot_id: '311', nome: 'Longa', audiencia: 'nao_comprou', mensagem: 'x'.repeat(5000), agendado_em: futuro(11) });
      const j = (() => { try { return JSON.parse(r.t); } catch (e) { return {}; } })();
      return { ok: !j.sucesso, det: JSON.stringify(j).slice(0, 120) };
    }, 'remarketing');
    await t('mídia com caminho fora de uploads/ (../config.php) ou tipo inválido é recusada', async () => {
      const a = JSON.parse((await post({ bot_id: '311', audiencia: 'nao_comprou', mensagem: 'oi', agendado_em: futuro(12), midia_caminho: 'uploads/../conexao.php', midia_tipo: 'foto' })).t);
      const b = JSON.parse((await post({ bot_id: '311', audiencia: 'nao_comprou', mensagem: 'oi', agendado_em: futuro(12), midia_caminho: '/etc/passwd', midia_tipo: 'foto' })).t);
      const d = JSON.parse((await post({ bot_id: '311', audiencia: 'nao_comprou', mensagem: 'oi', agendado_em: futuro(12), midia_caminho: 'uploads/qualquer.png', midia_tipo: 'documento' })).t);
      return { ok: !a.sucesso && !b.sucesso && !d.sucesso, det: [a, b, d].map(x => x.mensagem).join(' | ') };
    }, 'segurança');
    await t('POST sem token CSRF é recusado', async () => {
      const r = await c.avaliar(`fetch('remarketing.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json','Content-Type':'application/x-www-form-urlencoded'},body:'bot_id=311&audiencia=nao_comprou&mensagem=x&agendado_em=${encodeURIComponent(futuro(13))}'}).then(async r=>({s:r.status,t:(await r.text()).slice(0,100)}))`);
      const n = db(`SELECT COUNT(*) n FROM remarketing_campanhas WHERE id_usuario=49 AND mensagem='x'`)[0].n;
      return { ok: n === 0, det: `HTTP ${r.s} ${r.t}` };
    }, 'segurança');
    await t('nome da campanha com HTML aparece como texto na lista (sem executar)', async () => {
      await post({ bot_id: '311', nome: '<img src=x onerror=window.__rm=1>Camp', audiencia: 'nao_comprou', mensagem: 'oi', agendado_em: futuro(14) });
      await c.ir(BASE + '/remarketing?limite=50');
      await esp(800);
      const exec = await c.avaliar('window.__rm===1');
      const imgs = await c.avaliar(`document.querySelectorAll('table img').length`);
      return { ok: !exec && imgs === 0, det: '' };
    }, 'segurança');
    nota('Não há botão de excluir/cancelar campanha pendente na interface (só ver detalhes); campanhas de teste foram removidas pela limpeza');
    salvar();
  } finally { await c.fechar(); }
})();
