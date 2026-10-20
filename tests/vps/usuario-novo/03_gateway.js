// Teste 3: configurar a credencial Omega do usuário (lida do arquivo local, nunca impressa) e conferir salvamento/ativação.
process.env.BASE = 'https://coyotebot.com.br';
const fs = require('fs'), path = require('path');
const ESTADO = process.env.ESTADO || path.join(__dirname, 'estado'); require('fs').mkdirSync(ESTADO, { recursive: true });
const PROJ = path.resolve(__dirname, '../../..');
const { lancar, entrar } = require(PROJ + '/tests/paginas/pg_lib.js');
const U = JSON.parse(fs.readFileSync(path.join(ESTADO, 'usuario_novo.json'), 'utf8'));
const H = JSON.parse(fs.readFileSync(path.join(ESTADO, 'cred_homolog.json'), 'utf8'));
const og = H.usuarios_gateways.find(g => g.gateway_nome === 'omegapayments');
const esp = (ms) => new Promise(r => setTimeout(r, ms));
const linhas = (t) => String(t || '').split('\n').map(s => s.trim()).filter(Boolean);
let ok = 0, falha = 0;
const chk = (n, c, x = '') => { c ? ok++ : falha++; console.log((c ? 'OK     ' : 'FALHOU ') + n + (x ? '  -> ' + x : '')); };
(async () => {
  const c = await lancar();
  try {
    await c.enviar('Emulation.setDeviceMetricsOverride', { width: 1400, height: 1000, deviceScaleFactor: 1, mobile: false });
    await entrar(c, U.email, U.senha);
    await c.ir('https://coyotebot.com.br/gateways'); await esp(800);
    const abriu = await c.avaliar(`(function(){var b=document.querySelector('button[onclick*="openModal"]'); if(!b) return 'sem botão'; b.click(); return 'ok'})()`);
    chk('abre o modal da Omega', abriu === 'ok', abriu);
    await esp(600);
    const modal = await c.avaliar(`(function(){var m=[...document.querySelectorAll('.sobreposicao-modal')].find(function(x){return getComputedStyle(x).display!=='none'}); return m? m.innerText : ''})()`);
    console.log('   modal:', linhas(modal).join(' | ').slice(0, 1100));
    const r = await c.avaliar(`(function(){var m=[...document.querySelectorAll('.sobreposicao-modal')].find(function(x){return getComputedStyle(x).display!=='none'}); if(!m) return 'sem modal';
      var f=m.querySelector('form[enctype]'); var set=function(n,v){f.querySelector('[name='+n+']').value=v};
      set('client_id',${JSON.stringify(og.client_id)}); set('client_secret',${JSON.stringify(og.client_secret_claro)});
      var a=f.querySelector('[name=ativo]'); if(a) a.checked=true; f.submit(); return 'enviado'})()`);
    chk('formulário enviado', r === 'enviado', r);
    await esp(3000); await c.esperarCarga();
    const corpo = linhas(await c.avaliar('document.body.innerText'));
    const msg = corpo.find(l => /Suas credenciais|Erro/i.test(l)) || '';
    chk('mensagem de credenciais salvas', /salvas/i.test(msg), msg);
    await c.captura(path.join(ESTADO, 'gateway_pos_salvar.png'));
    await c.ir('https://coyotebot.com.br/gateways'); await esp(800);
    console.log('   página:', linhas(await c.avaliar('document.querySelector("main").innerText')).join(' | ').slice(0, 500));
    const estado = JSON.parse(await c.avaliar(`JSON.stringify({ativos:[...document.querySelectorAll('input[type=checkbox][aria-label]')].map(function(i){return i.getAttribute('aria-label')+':'+i.checked}), placeholder:[...document.querySelectorAll('input[name=client_secret]')].map(function(i){return i.placeholder}), tamClientId:[...document.querySelectorAll('input[name=client_id]')].map(function(i){return i.value.length})})`));
    console.log('   estado:', JSON.stringify(estado));
    chk('credencial salva: client_id preenchido e segredo marcado como salvo', estado.tamClientId.some(n => n === og.client_id.length) && estado.placeholder.some(p => /salvo/.test(p)));
    chk('gateway ficou ativo', estado.ativos.some(a => /true/.test(a)));
  } catch (e) { console.log('ERRO', e.stack); falha++; }
  finally { await c.fechar(); }
  console.log(`\nRESUMO: ${ok} ok, ${falha} falhas`);
})();
