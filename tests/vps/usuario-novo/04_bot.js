// Teste 4: criar bot na VPS (erros + token real do @Claude_Gomes_bot). Token lido do arquivo local, nunca impresso.
process.env.BASE = 'https://coyotebot.com.br';
const fs = require('fs'), path = require('path');
const ESTADO = process.env.ESTADO || path.join(__dirname, 'estado'); require('fs').mkdirSync(ESTADO, { recursive: true });
const PROJ = path.resolve(__dirname, '../../..');
const { lancar, entrar, lerToast, autoDialogo } = require(PROJ + '/tests/paginas/pg_lib.js');
const U = JSON.parse(fs.readFileSync(path.join(ESTADO, 'usuario_novo.json'), 'utf8'));
const H = JSON.parse(fs.readFileSync(path.join(ESTADO, 'cred_homolog.json'), 'utf8'));
const TOKEN = H.bots[0].token;
const esp = (ms) => new Promise(r => setTimeout(r, ms));
const mask = (s) => String(s || '').split(TOKEN).join('<TOKEN>');
let ok = 0, falha = 0;
const chk = (n, c, x = '') => { c ? ok++ : falha++; console.log((c ? 'OK     ' : 'FALHOU ') + n + (x ? '  -> ' + mask(x) : '')); };
const BASE = 'https://coyotebot.com.br';
(async () => {
  const c = await lancar();
  try {
    await c.enviar('Emulation.setDeviceMetricsOverride', { width: 1400, height: 1000, deviceScaleFactor: 1, mobile: false });
    autoDialogo(c, true);
    await entrar(c, U.email, U.senha);
    await c.ir(BASE + '/bot'); await esp(900);
    let t;
    await c.avaliar(`document.getElementById('btn-salvar-bot').click(); 1`); t = await lerToast(c);
    chk('criar sem token: avisa', /token/i.test(t), t);
    await c.avaliar(`document.getElementById('token').value='isto-nao-e-um-token'; document.getElementById('btn-salvar-bot').click(); 1`); t = await lerToast(c);
    chk('token lixo: recusado com mensagem', t.length > 3 && !/error_code|"ok":false/.test(t), t);
    await c.avaliar(`document.getElementById('token').value='123456789:AAHfakefakefakefakefakefakefakefakeX'; document.getElementById('btn-salvar-bot').click(); 1`); t = await lerToast(c, 12000);
    chk('token falso no formato certo: recusado', t.length > 3 && !/error_code|"ok":false/.test(t), t);
    let n = await c.avaliar(`fetch('/bots').then(r=>r.text()).then(h=>(h.match(/bot\?id=/g)||[]).length)`);
    chk('nenhum bot foi criado pelos erros', n === 0, 'ocorrências de bot?id= na lista: ' + n);

    // token real: Testar
    await c.ir(BASE + '/bot'); await esp(900);
    await c.avaliar(`document.getElementById('token').value=${JSON.stringify(TOKEN)}; document.getElementById('btn-testar-token').click(); 1`);
    t = await lerToast(c, 12000);
    chk('Testar token real: conexão confirmada', /conect|sucesso|ok|v[áa]lid|@Claude_Gomes_bot|Gomes/i.test(t) && !/inválid|erro/i.test(t), t);
    await esp(1200);
    const prev = await c.avaliar(`JSON.stringify({nome:document.getElementById('nome-preview-criacao')?.value, user:document.getElementById('username-preview-criacao')?.value})`);
    console.log('   prévia:', prev);
    // Criar
    await c.avaliar(`document.getElementById('btn-salvar-bot').click(); 1`);
    t = await lerToast(c, 15000);
    console.log('   toast criar:', mask(t));
    await esp(3500); await c.esperarCarga();
    const url = await c.avaliar('location.pathname + location.search');
    console.log('   url depois:', url);
    await c.captura(path.join(ESTADO, 'bot_criado.png'));
    await c.ir(BASE + '/bots'); await esp(900);
    const lista = await c.avaliar('document.querySelector("main").innerText');
    console.log('   lista de bots:', lista.split('\n').map(s => s.trim()).filter(Boolean).join(' | ').slice(0, 400));
    chk('bot @Claude_Gomes_bot aparece na lista', /Claude_Gomes_bot/i.test(lista));
    const link = await c.avaliar(`(document.querySelector('a[href*="bot?id="]')||{}).getAttribute?.('href')||''`);
    fs.writeFileSync(path.join(ESTADO, 'bot_vps.json'), JSON.stringify({ link }));
    console.log('   link do bot:', link);
  } catch (e) { console.log('ERRO', mask(e.stack)); falha++; }
  finally { try { c.pararDialogo(); } catch (e) {} await c.fechar(); }
  console.log(`\nRESUMO: ${ok} ok, ${falha} falhas`);
})();
