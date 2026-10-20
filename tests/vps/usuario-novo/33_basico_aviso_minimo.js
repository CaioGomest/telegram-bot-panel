// Aviso de valor abaixo do mínimo da Omega (20/10/2026, dono: "está falando informação demais para o cliente, tem que ser mais enxuta: só fala que para a Omega o mínimo é X").
// Prova, na tela e direto no servidor, que o aviso é UMA frase com só o mínimo — sem citar comissão da plataforma, nome do plano nem a lista do que está abaixo.
// Não grava nada (o servidor recusa o salvar). Uso: node 33_basico_aviso_minimo.js
process.env.BASE = 'https://coyotebot.com.br';
const fs = require('fs'), path = require('path');
const PROJ = path.resolve(__dirname, '../../..');
const { lancar, entrar, autoDialogo } = require(PROJ + '/tests/paginas/pg_lib.js');
const ESTADO = process.env.ESTADO || path.join(__dirname, 'estado'); fs.mkdirSync(ESTADO, { recursive: true });
const U = JSON.parse(fs.readFileSync(path.join(ESTADO, 'usuario_novo.json'), 'utf8'));
const BASE = 'https://coyotebot.com.br';
const esp = (ms) => new Promise(r => setTimeout(r, ms));
let ok = 0, falha = 0;
const chk = (n, c, x = '') => { c ? ok++ : falha++; console.log((c ? 'OK     ' : 'FALHOU ') + n + (x ? '  -> ' + String(x).slice(0, 240) : '')); };
const FRASE = /^Para a Omega, o valor mínimo é R\$ \d{1,3}(\.\d{3})*,\d{2}\.$/;
(async () => {
  const c = await lancar();
  try {
    autoDialogo(c, true); c.aceitar = true;
    await c.enviar('Emulation.setDeviceMetricsOverride', { width: 1360, height: 900, deviceScaleFactor: 1, mobile: false });
    await entrar(c, U.email, U.senha);
    await c.ir(BASE + '/fluxo_basico'); await esp(2500);
    const toast = () => c.avaliar(`(function(){var t=document.getElementById('toast');return t&&t.classList.contains('visivel')?t.innerText:''})()`);

    console.log('--- na tela (o navegador confere antes de enviar)');
    await c.avaliar(`document.querySelector('.basico-nav-item[data-secao="planos"]').click(); 1`); await esp(400);
    await c.avaliar(`document.getElementById('btn-adicionar-plano').click(); 1`); await esp(500);
    await c.avaliar(`(function(){var p=document.querySelector('#lista-planos .campo-plano-nome');p.value='1';p.dispatchEvent(new Event('input',{bubbles:true}));var v=document.querySelector('#lista-planos .campo-plano-valor');v.value='1';v.dispatchEvent(new Event('input',{bubbles:true}));return 1})()`);
    await c.avaliar(`document.getElementById('btn-salvar-fluxo-basico').click(); 1`); await esp(900);
    const t1 = await toast();
    chk('plano de R$ 1,00: o aviso é uma frase só, "Para a Omega, o valor mínimo é R$ X,XX."', FRASE.test(t1), t1);
    chk('não cita comissão da plataforma, "OmegaPayments", "Ajuste" nem o nome do plano', !/comiss|OmegaPayments|Ajuste|plano "/i.test(t1), t1);
    chk('nada foi salvo (o fluxo continua sem id)', (await c.avaliar(`document.getElementById('id-fluxo').value`)) === '');
    await c.captura(path.join(ESTADO, 'aviso_minimo.png'));

    console.log('\n--- direto no servidor (POST sem passar pela tela)');
    const post = (valor) => c.avaliar(`fetch('api.php?action=salvar_fluxo',{method:'POST',credentials:'include',headers:{'Content-Type':'application/json','X-CSRF-Token':window.CSRF_TOKEN},body:JSON.stringify({id:'',nome:'Teste mínimo',descricao:'',modo:'basico',dados_fluxograma:{ativo:true,planos:[{id:'p1',nome:'Plano secreto',valor:${valor},dias_acesso:30,unidade_acesso:'dias',id_grupo:''}],ofertas:{},boas_vindas:{},packs:{},previas:{},assinatura:{},pagamentos:{}}})}).then(function(r){return r.json()}).then(function(j){return JSON.stringify(j)})`).then(JSON.parse);
    const r1 = await post(1);
    chk('servidor recusa o plano de R$ 1,00 com a mesma frase enxuta', r1.sucesso === false && FRASE.test(r1.mensagem || ''), JSON.stringify(r1));
    chk('a resposta do servidor não traz o nome do plano nem a comissão', !/Plano secreto|comiss|OmegaPayments/i.test(JSON.stringify(r1)), JSON.stringify(r1));
    const r2 = await post(0.01);
    chk('R$ 0,01 também recebe só a frase do mínimo', r2.sucesso === false && FRASE.test(r2.mensagem || ''), JSON.stringify(r2));
    chk('erros de JS', c.errosJs().length === 0, c.errosJs().join(' | '));
  } catch (e) { console.log('ERRO', e.stack); falha++; }
  finally { try { c.pararDialogo(); } catch (e) {} await c.fechar(); }
  console.log(`\nRESUMO: ${ok} ok, ${falha} falhas`);
})();
