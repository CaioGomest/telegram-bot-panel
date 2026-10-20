// Editor Guiado (/fluxo_basico) de um fluxo NOVO (16/10): nenhuma aba trancada, orientação do 1º passo, "Conversões" explicando em vez de travar, status honesto,
// e depois de salvar (1 plano) tudo muda. Cria 1 fluxo de teste e APAGA no fim. Não usa inspecionar() (zera c.eventos e o auto-aceitador de diálogos trava).
// Uso: node 26_basico_sem_trava.js   (conta de teste de estado/usuario_novo.json)
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
  try {
    autoDialogo(c, true); c.aceitar = true;
    await c.enviar('Emulation.setDeviceMetricsOverride', { width: 1360, height: 900, deviceScaleFactor: 1, mobile: false });
    await entrar(c, U.email, U.senha);
    const qtd = async () => { await c.ir(BASE + '/fluxos'); await esp(1000); return Number(((await c.avaliar(`document.querySelector('main').innerText`)).match(/Novo Fluxo \((\d+)\/50\)/) || [])[1]); };
    const antes = await qtd();
    await c.ir(BASE + '/fluxo_basico'); await esp(2200);
    const txt = (sel) => c.avaliar(`(function(){var e=document.querySelector(${JSON.stringify(sel)});return e?e.innerText.trim():''})()`);
    const visivel = (sel) => c.avaliar(`(function(){var e=document.querySelector(${JSON.stringify(sel)});return !!e && e.offsetParent!==null && !e.hidden})()`);

    console.log('--- fluxo novo (ainda não salvo)');
    chk('status do topo diz "Fluxo ainda não salvo" (não "Tudo salvo")', /Fluxo ainda não salvo/.test(await txt('#status-salvo')), await txt('#status-salvo'));
    chk('nenhuma aba com cadeado / marcada como bloqueada', (await c.avaliar(`document.querySelectorAll('.basico-nav-item.bloqueado, .basico-nav-item .editor-fluxo-cadeado').length`)) === 0);
    chk('o contador do menu diz "11 seções" (e não "1 de 11")', (await txt('#nav-contagem')) === '11 seções', await txt('#nav-contagem'));
    const aviso = await txt('#nav-aviso');
    chk('o aviso do menu orienta o 1º passo (Boas-vindas, Planos, Salvar alterações, Bots)', await visivel('#nav-aviso') && /Por onde começar/.test(aviso) && /Planos/.test(aviso) && /Salvar alterações/.test(aviso), aviso);
    const secoes = JSON.parse(await c.avaliar(`JSON.stringify([...document.querySelectorAll('.basico-nav-item')].map(function(b){return b.getAttribute('data-secao')}))`));
    chk('o menu tem as 11 seções', secoes.length === 11, secoes.join(','));
    const falhas = [];
    for (const s of secoes) {
      await c.avaliar(`document.querySelector('.basico-nav-item[data-secao="${s}"]').click(); 1`); await esp(350);
      const ativa = await c.avaliar(`(function(){var a=document.querySelector('.basico-secao.ativa');return a?a.getAttribute('data-secao'):''})()`);
      const toast = await c.avaliar(`(function(){var t=document.getElementById('toast');return t&&t.classList.contains('visivel')?t.innerText:''})()`);
      if (ativa !== s || /Salve o fluxo/.test(toast)) falhas.push(s + '→' + ativa + ' toast="' + toast + '"');
    }
    chk('clicar em cada uma das 11 abas abre a aba, sem nenhum aviso de "Salve o fluxo"', falhas.length === 0, falhas.join(' | '));
    await c.avaliar(`document.querySelector('.basico-nav-item[data-secao="conversoes"]').click(); 1`); await esp(500);
    chk('"Conversões" num fluxo novo explica em vez de travar', /Salve o fluxo para acompanhar as conversões/.test(await txt('#conv-vazio')), await txt('#conv-vazio'));
    await c.avaliar(`document.querySelector('.basico-nav-item[data-secao="bots"]').click(); 1`); await esp(400);
    const botsAviso = await txt('#bots-aviso-salvar');
    chk('em "Bots" o aviso diz o que fazer (escolher o bot; ele é vinculado ao clicar em Salvar alterações)', /Salvar alterações/.test(botsAviso) && /vinculado/.test(botsAviso), botsAviso);
    chk('a mensagem confusa "Salve o fluxo em Bots para liberar esta seção" sumiu da página', !/em Bots para liberar/.test(await c.avaliar('document.documentElement.innerHTML')));
    await c.captura(path.join(ESTADO, 'basico_novo.png'));

    console.log('\n--- salvar com 1 plano');
    await c.avaliar(`document.querySelector('.basico-nav-item[data-secao="planos"]').click(); 1`); await esp(400);
    await c.avaliar(`document.getElementById('btn-adicionar-plano').click(); 1`); await esp(500);
    await c.avaliar(`(function(){var p=document.querySelector('#lista-planos .campo-plano-nome');p.value='Plano do teste';p.dispatchEvent(new Event('input',{bubbles:true}));var v=document.querySelector('#lista-planos .campo-plano-valor');v.value='5';v.dispatchEvent(new Event('input',{bubbles:true}));return 1})()`);
    await c.avaliar(`document.getElementById('btn-salvar-fluxo-basico').click(); 1`); await esp(3500);
    const toast = await c.avaliar(`(function(){var t=document.getElementById('toast');return t?t.innerText:''})()`);
    const id = await c.avaliar(`document.getElementById('id-fluxo').value`);
    chk('salvou o fluxo (o servidor devolveu um id)', !!id, 'id=' + id + ' toast="' + toast + '"');
    chk('status do topo passa a "Tudo salvo"', /Tudo salvo/.test(await txt('#status-salvo')), await txt('#status-salvo'));
    chk('o aviso do menu some depois de salvar', (await visivel('#nav-aviso')) === false);
    await c.avaliar(`document.querySelector('.basico-nav-item[data-secao="bots"]').click(); 1`); await esp(700);
    chk('em "Bots" o aviso de salvar some e aparece o seletor para vincular', (await visivel('#bots-aviso-salvar')) === false && (await visivel('#area-vincular')) === true);
    await c.avaliar(`document.querySelector('.basico-nav-item[data-secao="conversoes"]').click(); 1`); await esp(1500);
    chk('"Conversões" de um fluxo salvo mostra "Nenhuma venda registrada" (dados reais)', /Nenhuma venda registrada neste fluxo ainda/.test(await txt('#conv-vazio')), await txt('#conv-vazio'));

    console.log('\n--- limpeza');
    if (id) {
      c.dialogos = [];
      await c.avaliar(`document.getElementById('btn-excluir-fluxo-basico').click(); 1`); await esp(3000); await c.esperarCarga();
      chk('fluxo de teste excluído (volta à lista)', /\/fluxos$/.test(await c.avaliar('location.pathname')), JSON.stringify(c.dialogos));
    }
    chk('a conta ficou com o mesmo número de fluxos do início', (await qtd()) === antes, 'antes ' + antes + ', agora ' + (await qtd()));
    chk('erros de JS', c.errosJs().length === 0, c.errosJs().join(' | '));
  } catch (e) { console.log('ERRO', e.stack); falha++; }
  finally { try { c.pararDialogo(); } catch (e) {} await c.fechar(); }
  console.log(`\nRESUMO: ${ok} ok, ${falha} falhas`);
})();
