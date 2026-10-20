// Editor Guiado (16/10): NADA bloqueia por falta de plano — dá para salvar rascunho, vincular bot e salvar com bot vinculado sem plano. A única coisa extra é uma PERGUNTA
// quando o bot está hoje em OUTRO fluxo e este ainda não tem plano (vincular tira o bot de lá), e um AVISO depois de salvar/vincular sem plano.
// Mexe no vínculo do bot da conta de teste e RESTAURA; cria fluxos e APAGA todos. Não usa inspecionar(). Uso: node 29_basico_rascunho_sem_plano.js
process.env.BASE = 'https://coyotebot.com.br';
const fs = require('fs'), path = require('path');
const PROJ = path.resolve(__dirname, '../../..');
const { lancar, entrar, autoDialogo } = require(PROJ + '/tests/paginas/pg_lib.js');
const ESTADO = process.env.ESTADO || path.join(__dirname, 'estado'); fs.mkdirSync(ESTADO, { recursive: true });
const U = JSON.parse(fs.readFileSync(path.join(ESTADO, 'usuario_novo.json'), 'utf8'));
const BASE = 'https://coyotebot.com.br';
const esp = (ms) => new Promise(r => setTimeout(r, ms));
let ok = 0, falha = 0;
const chk = (n, c, x = '') => { c ? ok++ : falha++; console.log((c ? 'OK     ' : 'FALHOU ') + n + (x ? '  -> ' + String(x).slice(0, 260) : '')); };
(async () => {
  const c = await lancar();
  let original = null; const criados = [];
  try {
    autoDialogo(c, true); c.aceitar = true;
    await c.enviar('Emulation.setDeviceMetricsOverride', { width: 1360, height: 900, deviceScaleFactor: 1, mobile: false });
    await entrar(c, U.email, U.senha);
    const bots = () => c.avaliar(`fetch('api.php?action=listar_bots',{credentials:'include'}).then(function(r){return r.json()}).then(function(j){return JSON.stringify((j.bots||[]).map(function(b){return {id:b.id,fluxo:b.id_fluxo_conectado,nome:b.primeiro_nome}}))})`).then(JSON.parse);
    const ligar = (id_fluxo) => c.avaliar(`jQuery.ajax({url:'api.php?action=vincular_bot_fluxo',method:'POST',contentType:'application/json',data:JSON.stringify({id_bot:${bot_id},id_fluxo:${id_fluxo}})}); 1`);
    await c.ir(BASE + '/fluxos'); await esp(1200);
    const b0 = (await bots())[0]; const bot_id = b0.id; original = { id: bot_id, fluxo: b0.fluxo };
    fs.writeFileSync(path.join(ESTADO, 'bot_vinculo_original.json'), JSON.stringify(original));
    var bot_id_g = bot_id;
    const nFluxos = async () => { await c.ir(BASE + '/fluxos'); await esp(900); return Number(((await c.avaliar(`document.querySelector('main').innerText`)).match(/Novo Fluxo \((\d+)\/50\)/) || [])[1]); };
    const antes = await nFluxos();
    const toast = () => c.avaliar(`(function(){var t=document.getElementById('toast');return t&&t.classList.contains('visivel')?t.innerText:''})()`);
    const toastNovo = async () => { for (let i = 0; i < 45; i++) { await esp(150); const x = await toast(); if (x) return x; } return ''; };
    const esperaToastSumir = async () => { for (let i = 0; i < 40; i++) { if (!(await toast())) return; await esp(150); } };
    const idAtual = () => c.avaliar(`document.getElementById('id-fluxo').value`);
    const txt = (sel) => c.avaliar(`(function(){var e=document.querySelector(${JSON.stringify(sel)});return e?e.innerText.trim():''})()`);
    const escolher = async () => { await c.avaliar(`(function(){var s=document.getElementById('select-bot-vincular');s.value='${bot_id}';s.dispatchEvent(new Event('change',{bubbles:true}));return 1})()`); await esp(900); };
    const salvar = async () => { await esperaToastSumir(); await c.avaliar(`document.getElementById('btn-salvar-fluxo-basico').click(); 1`); };
    const novo = async () => { await c.ir(BASE + '/fluxo_basico'); await esp(2500); };
    const irBots = async () => { await c.avaliar(`document.querySelector('.basico-nav-item[data-secao="bots"]').click(); 1`); await esp(900); };
    const msgConfirm = () => (c.dialogos[c.dialogos.length - 1] || {}).msg || '';

    console.log('--- 1) rascunho sem plano e sem bot');
    await novo(); await salvar(); let t = await toastNovo(); const id1 = await idAtual(); criados.push(id1);
    chk('salvar sem plano é aceito (cria o fluxo)', !!id1 && /Rascunho salvo/.test(t), 'id=' + id1 + ' | ' + t);

    console.log('\n--- 2) vincular bot a fluxo salvo SEM plano, bot hoje em OUTRO fluxo: PERGUNTA (não bloqueia)');
    await irBots(); c.dialogos = []; c.aceitar = false; await escolher();
    chk('aparece a pergunta explicando o risco', c.dialogos.length === 1 && /hoje está no fluxo/.test(msgConfirm()) && /Nenhum plano disponível/.test(msgConfirm()), msgConfirm());
    chk('cancelando: nada é vinculado e o seletor volta ao vazio', (await bots())[0].fluxo === original.fluxo && (await c.avaliar(`document.getElementById('select-bot-vincular').value`)) === '');
    c.dialogos = []; c.aceitar = true; await escolher(); await esp(1500);
    chk('aceitando: o bot é vinculado mesmo sem plano', String((await bots())[0].fluxo) === String(id1), 'bot.fluxo=' + (await bots())[0].fluxo);

    console.log('\n--- 3) salvar um fluxo SEM plano que já tem bot vinculado: não bloqueia, avisa');
    await salvar(); t = await toastNovo();
    chk('salvou (não foi recusado) e avisou que ainda não há plano', /Salvo\. Atenção: o fluxo ainda não tem plano/.test(t) && !/mantenha pelo menos 1 plano/.test(t), t);

    console.log('\n--- 4) bot sem nenhum fluxo: vincula direto, sem pergunta');
    await ligar(0); await esp(1000);
    await c.ir(BASE + '/fluxo_basico?id=' + id1); await esp(2500); await irBots();
    c.dialogos = []; await escolher(); await esp(1500);
    chk('sem fluxo atual não há pergunta e o bot é vinculado', c.dialogos.length === 0 && String((await bots())[0].fluxo) === String(id1), 'diálogos=' + c.dialogos.length);

    console.log('\n--- 5) fluxo novo + bot escolhido (bot sem fluxo) + salvar sem plano: salva e VINCULA, com aviso');
    await ligar(0); await esp(800);
    await novo(); await escolher();
    chk('o bot aparece como "será vinculado ao salvar"', /será vinculado ao salvar/.test(await txt('#lista-bots-vinculados')));
    c.dialogos = []; await salvar(); t = await toastNovo(); const id2 = await idAtual(); criados.push(id2);
    chk('salvou, vinculou e avisou da falta de plano (sem pergunta, o bot não estava em outro fluxo)', !!id2 && /Fluxo salvo e 1 bot vinculado/.test(t) && /ainda não tem plano/.test(t) && c.dialogos.length === 0, t);
    chk('no servidor o bot está no fluxo novo', String((await bots())[0].fluxo) === String(id2), 'bot.fluxo=' + (await bots())[0].fluxo);

    console.log('\n--- 6) fluxo novo + bot que está em OUTRO fluxo + salvar sem plano: pergunta ANTES de salvar');
    await ligar(original.fluxo || 0); await esp(1000);
    await novo(); await escolher();
    c.dialogos = []; c.aceitar = false; await salvar(); await esp(1500);
    chk('aparece a pergunta "Salvar e vincular" com o risco', c.dialogos.length === 1 && /Salvar e vincular/.test(msgConfirm()) && /Nenhum plano disponível/.test(msgConfirm()), msgConfirm());
    chk('cancelando: o fluxo NÃO é criado (sem id) e o bot não mudou', !(await idAtual()) && String((await bots())[0].fluxo) === String(original.fluxo));
    c.aceitar = true; c.dialogos = []; await salvar(); t = await toastNovo(); const id3 = await idAtual(); if (id3) criados.push(id3);
    chk('aceitando: salva e vincula', !!id3 && /bot vinculado/.test(t) && String((await bots())[0].fluxo) === String(id3), t);

    console.log('\n--- limpeza e restauração');
    await c.ir(BASE + '/fluxos'); await esp(1000);
    for (const id of criados) { await c.avaliar(`jQuery.ajax({url:'api.php?action=excluir_fluxo',method:'POST',contentType:'application/json',data:JSON.stringify({id:${Number(id)}})}); 1`); await esp(700); }
    if (original.fluxo) { await ligar(original.fluxo); await esp(1000); }
    chk('o bot voltou ao fluxo original', String((await bots())[0].fluxo) === String(original.fluxo), 'antes ' + original.fluxo + ' → agora ' + (await bots())[0].fluxo);
    chk('a conta ficou com o mesmo número de fluxos', (await nFluxos()) === antes);
    chk('erros de JS', c.errosJs().length === 0, c.errosJs().join(' | '));
  } catch (e) { console.log('ERRO', e.stack); falha++; }
  finally { try { c.pararDialogo(); } catch (e) {} await c.fechar(); }
  console.log(`\nRESUMO: ${ok} ok, ${falha} falhas`);
})();
