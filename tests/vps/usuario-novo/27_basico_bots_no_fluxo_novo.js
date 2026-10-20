// Editor Guiado, fluxo NOVO (16/10): o seletor de bots já aparece; a escolha fica como "será vinculado ao salvar" e é vinculada no 1º "Salvar alterações".
// Mexe no vínculo do bot da conta de teste e RESTAURA o fluxo original no fim; cria 1 fluxo e APAGA. Não usa inspecionar(). Uso: node 27_basico_bots_no_fluxo_novo.js
process.env.BASE = 'https://coyotebot.com.br';
const fs = require('fs'), path = require('path');
const PROJ = path.resolve(__dirname, '../../..');
const { lancar, entrar, autoDialogo } = require(PROJ + '/tests/paginas/pg_lib.js');
const ESTADO = process.env.ESTADO || path.join(__dirname, 'estado'); fs.mkdirSync(ESTADO, { recursive: true });
const U = JSON.parse(fs.readFileSync(path.join(ESTADO, 'usuario_novo.json'), 'utf8'));
const BASE = 'https://coyotebot.com.br';
const esp = (ms) => new Promise(r => setTimeout(r, ms));
let ok = 0, falha = 0;
const chk = (n, c, x = '') => { c ? ok++ : falha++; console.log((c ? 'OK     ' : 'FALHOU ') + n + (x ? '  -> ' + String(x).slice(0, 230) : '')); };
(async () => {
  const c = await lancar();
  let original = null;
  try {
    autoDialogo(c, true); c.aceitar = true;
    await c.enviar('Emulation.setDeviceMetricsOverride', { width: 1360, height: 900, deviceScaleFactor: 1, mobile: false });
    await entrar(c, U.email, U.senha);
    const bots = () => c.avaliar(`fetch('api.php?action=listar_bots',{credentials:'include'}).then(function(r){return r.json()}).then(function(j){return JSON.stringify((j.bots||[]).map(function(b){return {id:b.id,fluxo:b.id_fluxo_conectado,nome_fluxo:b.nome_fluxo,nome:b.primeiro_nome}}))})`).then(JSON.parse);
    await c.ir(BASE + '/fluxos'); await esp(1200);
    const antesBots = await bots();
    const bot = antesBots[0];
    original = { id: bot.id, fluxo: bot.fluxo };
    fs.writeFileSync(path.join(ESTADO, 'bot_vinculo_original.json'), JSON.stringify(original));
    console.log('   bot da conta:', bot.nome, '| fluxo conectado antes:', bot.nome_fluxo, '(id ' + bot.fluxo + ')');
    const nFluxos = async () => { await c.ir(BASE + '/fluxos'); await esp(900); return Number(((await c.avaliar(`document.querySelector('main').innerText`)).match(/Novo Fluxo \((\d+)\/50\)/) || [])[1]); };
    const antes = await nFluxos();

    await c.ir(BASE + '/fluxo_basico'); await esp(2500);
    const txt = (sel) => c.avaliar(`(function(){var e=document.querySelector(${JSON.stringify(sel)});return e?e.innerText.trim():''})()`);
    const visivel = (sel) => c.avaliar(`(function(){var e=document.querySelector(${JSON.stringify(sel)});return !!e && e.offsetParent!==null && !e.hidden})()`);
    const opcoes = () => c.avaliar(`JSON.stringify([...document.querySelectorAll('#select-bot-vincular option')].map(function(o){return o.value+':'+o.textContent.trim()}))`).then(JSON.parse);

    console.log('\n--- fluxo novo: escolher o bot antes de salvar');
    chk('o seletor de bots JÁ aparece num fluxo novo', await visivel('#area-vincular') && await visivel('#select-bot-vincular'));
    let o = await opcoes();
    chk('o seletor lista o bot da conta (com "hoje em: <fluxo>")', o.some(x => x.indexOf(String(bot.id) + ':') === 0) && /hoje em:/.test(o.join('|')), o.join(' | '));
    chk('o texto diz que o bot é vinculado ao clicar em Salvar alterações', /vinculado quando você clicar em Salvar alterações/.test(await txt('#bots-aviso-salvar')), await txt('#bots-aviso-salvar'));
    chk('sem escolha: "Nenhum bot escolhido" e 0/10', /Nenhum bot escolhido/.test(await txt('#lista-bots-vinculados')) && /0\/10/.test(await txt('#bots-contagem')));
    await c.avaliar(`(function(){var s=document.getElementById('select-bot-vincular');s.value='${bot.id}';s.dispatchEvent(new Event('change',{bubbles:true}));return 1})()`); await esp(500);
    chk('escolher o bot: aparece na lista como "será vinculado ao salvar", contagem 1/10', /será vinculado ao salvar/.test(await txt('#lista-bots-vinculados')) && /1\/10/.test(await txt('#bots-contagem')), await txt('#lista-bots-vinculados'));
    chk('o bot escolhido sai das opções do seletor (não escolhe 2x)', !(await opcoes()).some(x => x.indexOf(String(bot.id) + ':') === 0));
    chk('escolher o bot marca "Alterações não salvas"', /Alterações não salvas/.test(await txt('#status-salvo')), await txt('#status-salvo'));
    chk('NADA foi vinculado de verdade ainda (o bot segue no fluxo de antes)', (await bots())[0].fluxo === original.fluxo);
    await c.avaliar(`document.querySelector('.tirar-bot-pendente').click(); 1`); await esp(400);
    chk('tirar da lista: volta a "Nenhum bot escolhido" e o bot volta ao seletor', /Nenhum bot escolhido/.test(await txt('#lista-bots-vinculados')) && (await opcoes()).some(x => x.indexOf(String(bot.id) + ':') === 0));
    await c.avaliar(`(function(){var s=document.getElementById('select-bot-vincular');s.value='${bot.id}';s.dispatchEvent(new Event('change',{bubbles:true}));return 1})()`); await esp(400);
    await c.captura(path.join(ESTADO, 'basico_bot_pendente.png'));

    console.log('\n--- salvar: o vínculo acontece sozinho');
    await c.avaliar(`document.querySelector('.basico-nav-item[data-secao="planos"]').click(); 1`); await esp(300);
    await c.avaliar(`document.getElementById('btn-adicionar-plano').click(); 1`); await esp(500);
    await c.avaliar(`(function(){var p=document.querySelector('#lista-planos .campo-plano-nome');p.value='Plano do teste';p.dispatchEvent(new Event('input',{bubbles:true}));var v=document.querySelector('#lista-planos .campo-plano-valor');v.value='5';v.dispatchEvent(new Event('input',{bubbles:true}));return 1})()`);
    await c.avaliar(`document.getElementById('btn-salvar-fluxo-basico').click(); 1`); await esp(4500);
    const id = await c.avaliar(`document.getElementById('id-fluxo').value`);
    chk('salvou o fluxo (id)', !!id, 'id=' + id);
    await c.avaliar(`document.querySelector('.basico-nav-item[data-secao="bots"]').click(); 1`); await esp(800);
    const lista = await txt('#lista-bots-vinculados');
    chk('na seção Bots o bot aparece vinculado (sem "será vinculado") e 1/10', !/será vinculado ao salvar/.test(lista) && new RegExp(String(bot.nome)).test(lista) && /1\/10/.test(await txt('#bots-contagem')), lista);
    const depois = (await bots())[0];
    chk('no servidor o bot agora está ligado ao fluxo novo', String(depois.fluxo) === String(id), 'bot.fluxo=' + depois.fluxo + ' fluxo novo=' + id);
    chk('o aviso "será vinculado" sumiu e o seletor é o de fluxo salvo', (await visivel('#bots-aviso-salvar')) === false && (await visivel('#area-vincular')) === true);

    console.log('\n--- limpeza e restauração do vínculo original');
    c.dialogos = [];
    await c.avaliar(`document.getElementById('btn-excluir-fluxo-basico').click(); 1`); await esp(3000); await c.esperarCarga();
    chk('fluxo de teste excluído', /\/fluxos$/.test(await c.avaliar('location.pathname')));
    if (original.fluxo) {
      await c.avaliar(`jQuery.ajax({url:'api.php?action=vincular_bot_fluxo',method:'POST',contentType:'application/json',data:JSON.stringify({id_bot:${original.id},id_fluxo:${original.fluxo}})}); 1`); await esp(1500);
    }
    const final = (await bots())[0];
    chk('o bot voltou ao fluxo que tinha no início', String(final.fluxo) === String(original.fluxo), 'antes ' + original.fluxo + ' → agora ' + final.fluxo);
    chk('a conta ficou com o mesmo número de fluxos', (await nFluxos()) === antes);
    chk('erros de JS', c.errosJs().length === 0, c.errosJs().join(' | '));
  } catch (e) { console.log('ERRO', e.stack); falha++; }
  finally { try { c.pararDialogo(); } catch (e) {} await c.fechar(); }
  console.log(`\nRESUMO: ${ok} ok, ${falha} falhas` + (original ? '' : ' (vínculo original não lido)'));
})();
