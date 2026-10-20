// Editor Visual (/fluxo) — lixeira num fluxo NOVO (16/10): antes dizia "Salve o fluxo antes de excluir."; agora descarta o rascunho e volta para a lista.
// Cenários: (A) fluxo vazio, (B) com 1 bloco e o descarte CANCELADO (o salvamento automático depois cria o fluxo e a lixeira exclui de verdade), (C) com 1 bloco e descarte CONFIRMADO
// (nada pode nascer no servidor, nem pelo salvamento automático). Não usa inspecionar() (zera c.eventos e o auto-aceitador de diálogos perde a conta). Usa a conta de teste (estado/usuario_novo.json).
process.env.BASE = 'https://coyotebot.com.br';
const fs = require('fs'), path = require('path');
const PROJ = path.resolve(__dirname, '../../..');
const { lancar, entrar, autoDialogo } = require(PROJ + '/tests/paginas/pg_lib.js');
const ESTADO = process.env.ESTADO || path.join(__dirname, 'estado');
const U = JSON.parse(fs.readFileSync(path.join(ESTADO, 'usuario_novo.json'), 'utf8'));
const BASE = 'https://coyotebot.com.br';
const esp = (ms) => new Promise(r => setTimeout(r, ms));
let ok = 0, falha = 0;
const chk = (n, c, x = '') => { c ? ok++ : falha++; console.log((c ? 'OK     ' : 'FALHOU ') + n + (x ? '  -> ' + String(x).slice(0, 220) : '')); };
(async () => {
  const c = await lancar();
  try {
    autoDialogo(c, true); c.aceitar = true;
    await c.enviar('Emulation.setDeviceMetricsOverride', { width: 1500, height: 900, deviceScaleFactor: 1, mobile: false });
    await entrar(c, U.email, U.senha);
    const qtd = async () => { await c.ir(BASE + '/fluxos'); await esp(1000); return Number(((await c.avaliar(`document.querySelector('main').innerText`)).match(/Novo Fluxo \((\d+)\/50\)/) || [])[1]); };
    const abrirNovo = async () => {
      await c.ir(BASE + '/fluxo');
      await c.esperar(`typeof jQuery!=='undefined' && jQuery('.adicionar-no').length>=13`, 20000);
      await c.avaliar(`window.__drop = function(tipo, x, y){ var $=jQuery; var ev=$.Event('drop'); ev.originalEvent={preventDefault:function(){}, stopPropagation:function(){}, dataTransfer:{getData:function(){return tipo;}}, clientX:x, clientY:y}; $('.conteiner-fluxo').trigger(ev); }; 1`);
      await esp(600);
    };
    const toasts = () => c.avaliar(`(function(){var t=document.getElementById('toast');return t?t.innerText:''})()`);
    const antes = await qtd();
    console.log('   fluxos da conta antes:', antes);

    // ---------- A) fluxo novo VAZIO
    console.log('\n--- A) fluxo novo vazio');
    await abrirNovo();
    c.dialogos = [];
    await c.avaliar(`document.getElementById('btn-excluir-fluxo').click(); 1`); await esp(2500); await c.esperarCarga();
    chk('a lixeira de um fluxo vazio leva para a lista de fluxos', /\/fluxos$/.test(await c.avaliar('location.pathname')), await c.avaliar('location.pathname'));
    chk('sem pergunta nenhuma (não há o que perder)', c.dialogos.length === 0, JSON.stringify(c.dialogos));
    chk('não aparece mais "Salve o fluxo antes de excluir"', !/Salve o fluxo antes de excluir/.test(await c.avaliar('document.body.innerText')));
    chk('nenhum fluxo foi criado', (await qtd()) === antes, 'agora ' + (await qtd()));

    // ---------- B) com 1 bloco, descarte CANCELADO
    console.log('\n--- B) 1 bloco, descartar e CANCELAR');
    await abrirNovo();
    await c.avaliar(`window.__drop('message', 400, 200); 1`); await esp(300);
    c.dialogos = []; c.aceitar = false;
    await c.avaliar(`document.getElementById('btn-excluir-fluxo').click(); 1`); await esp(800);
    chk('pergunta "Descartar este fluxo novo?" mencionando o bloco', c.dialogos.length === 1 && /Descartar este fluxo novo/.test(c.dialogos[0].msg) && /bloco/.test(c.dialogos[0].msg), JSON.stringify(c.dialogos));
    chk('cancelar mantém a pessoa no editor', /\/fluxo$/.test(await c.avaliar('location.pathname')));
    await esp(4500);
    const idCriado = await c.avaliar(`document.getElementById('id-fluxo').value`);
    chk('continuando a editar, o salvamento automático cria o fluxo (agora com id)', !!idCriado, 'id=' + idCriado);
    c.dialogos = []; c.aceitar = true;
    await c.avaliar(`document.getElementById('btn-excluir-fluxo').click(); 1`); await esp(3000); await c.esperarCarga();
    chk('com o fluxo salvo, a lixeira EXCLUI de verdade (pergunta "Deseja excluir este fluxo?") e volta à lista', c.dialogos.length === 1 && /Deseja excluir este fluxo/.test(c.dialogos[0].msg) && /\/fluxos$/.test(await c.avaliar('location.pathname')), JSON.stringify(c.dialogos));
    chk('o fluxo salvo foi apagado (contagem volta ao início)', (await qtd()) === antes, 'agora ' + (await qtd()));

    // ---------- C) com 1 bloco, descarte CONFIRMADO (antes do salvamento automático)
    console.log('\n--- C) 1 bloco, descartar e CONFIRMAR');
    await abrirNovo();
    await c.avaliar(`window.__drop('message', 400, 200); 1`); await esp(300);
    c.dialogos = []; c.aceitar = true;
    await c.avaliar(`document.getElementById('btn-excluir-fluxo').click(); 1`); await esp(2500); await c.esperarCarga();
    chk('confirmou: foi para a lista de fluxos', /\/fluxos$/.test(await c.avaliar('location.pathname')) && c.dialogos.length === 1 && /Descartar este fluxo novo/.test(c.dialogos[0].msg), JSON.stringify(c.dialogos));
    await esp(5000);
    chk('passados 5 s, NENHUM fluxo nasceu no servidor (o salvamento automático foi cancelado)', (await qtd()) === antes, 'agora ' + (await qtd()));
    // 2 blocos: texto no plural
    await abrirNovo();
    await c.avaliar(`window.__drop('message', 400, 200); window.__drop('image', 800, 200); 1`); await esp(300);
    c.dialogos = []; c.aceitar = false;
    await c.avaliar(`document.getElementById('btn-excluir-fluxo').click(); 1`); await esp(800);
    chk('com 2 blocos a pergunta usa o plural ("Os 2 blocos")', c.dialogos.length === 1 && /Os 2 blocos/.test(c.dialogos[0].msg), c.dialogos[0] && c.dialogos[0].msg);
    c.aceitar = true;
    await c.avaliar(`document.getElementById('btn-excluir-fluxo').click(); 1`); await esp(2500); await c.esperarCarga();
    await esp(4500);
    chk('descartado: de novo, nada nasceu', (await qtd()) === antes, 'agora ' + (await qtd()));
    chk('erros de JS durante todo o teste', c.errosJs().length === 0, c.errosJs().join(' | '));
  } catch (e) { console.log('ERRO', e.stack); falha++; }
  finally { try { c.pararDialogo(); } catch (e) {} await c.fechar(); }
  console.log(`\nRESUMO: ${ok} ok, ${falha} falhas`);
})();
