// Remarketing direto no endpoint (como um cliente malicioso faria) + cancelar a campanha de teste.
process.env.BASE = 'https://coyotebot.com.br';
const fs = require('fs'), path = require('path');
const ESTADO = process.env.ESTADO || path.join(__dirname, 'estado'); require('fs').mkdirSync(ESTADO, { recursive: true });
const PROJ = path.resolve(__dirname, '../../..');
const { lancar, entrar, autoDialogo } = require(PROJ + '/tests/paginas/pg_lib.js');
const U = JSON.parse(fs.readFileSync(path.join(ESTADO, 'usuario_novo.json'), 'utf8'));
const BASE = 'https://coyotebot.com.br';
const esp = (ms) => new Promise(r => setTimeout(r, ms));
let ok = 0, falha = 0;
const chk = (n, c, x = '') => { c ? ok++ : falha++; console.log((c ? 'OK     ' : 'FALHOU ') + n + (x ? '  -> ' + x : '')); };
(async () => {
  const c = await lancar();
  try {
    autoDialogo(c, true);
    await entrar(c, U.email, U.senha);
    await c.ir(BASE + '/remarketing'); await esp(1200);
    const pedir = async (campos) => JSON.parse(await c.avaliar(`(async function(){
      var fd=new FormData(); var tk=document.querySelector('#form-campanha input[type=hidden]'); fd.append(tk.name, tk.value);
      var o=${JSON.stringify(campos)}; Object.keys(o).forEach(function(k){fd.append(k,o[k])});
      var r=await fetch('/remarketing',{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'},credentials:'include'});
      var t=await r.text(); return JSON.stringify({status:r.status,corpo:t.slice(0,200)}) })()`));
    const futuro = '2026-10-20 10:00:00';
    let r = await pedir({ bot_id: 12168, audiencia: 'nao_comprou', mensagem: 'x', agendado_em: '2026-10-14 10:00:00' });
    chk('data passada: servidor recusa', /j[áa] passou/i.test(r.corpo) && !/"sucesso":true/.test(r.corpo), r.status + ' ' + r.corpo);
    r = await pedir({ bot_id: 12168, audiencia: 'nao_comprou', mensagem: 'a'.repeat(4100), agendado_em: futuro });
    chk('mensagem > 4096: recusada', /4096/.test(r.corpo), r.corpo);
    r = await pedir({ bot_id: 1, audiencia: 'nao_comprou', mensagem: 'x', agendado_em: futuro });
    chk('bot de OUTRO usuário (id 1): recusado', /Bot n[ãa]o encontrado/.test(r.corpo), r.corpo);
    r = await pedir({ bot_id: 12168, audiencia: 'todos', mensagem: 'x', agendado_em: futuro });
    chk('audiência inválida: recusada', /Audi[êe]ncia inv/i.test(r.corpo), r.corpo);
    r = await pedir({ bot_id: 12168, audiencia: 'nao_comprou', mensagem: 'x', agendado_em: 'lixo' });
    chk('data lixo: recusada', /data e a hora/i.test(r.corpo), r.corpo);
    // sem CSRF
    const semCsrf = JSON.parse(await c.avaliar(`(async function(){var fd=new FormData();fd.append('bot_id','12168');var r=await fetch('/remarketing',{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'},credentials:'include'});var t=await r.text();return JSON.stringify({status:r.status,corpo:t.slice(0,160)})})()`));
    chk('POST sem token CSRF: recusado', semCsrf.status >= 400 || /csrf|token|inv/i.test(semCsrf.corpo), semCsrf.status + ' ' + semCsrf.corpo);

    // cancelar a campanha de teste (cancela pelo botão da tela)
    const lista = await c.avaliar(`document.querySelector('main').innerText`);
    const idCamp = await c.avaliar(`(function(){var b=[...document.querySelectorAll('[data-id]')].find(function(e){return /cancel/i.test((e.title||'')+(e.getAttribute('aria-label')||'')+e.className+(e.getAttribute('onclick')||''))});return b?b.getAttribute('data-id'):''})()`);
    console.log('   id da campanha (botão cancelar):', idCamp || '(não achei botão)');
    if (idCamp) {
      r = await pedir({ acao: 'cancelar_campanha', id: idCamp });
      chk('cancelar campanha pendente', /"sucesso":true/.test(r.corpo), r.corpo);
      r = await pedir({ acao: 'cancelar_campanha', id: idCamp });
      chk('cancelar de novo: avisa que não existe mais', /"sucesso":false/.test(r.corpo), r.corpo);
    }
    await c.ir(BASE + '/remarketing'); await esp(1200);
    const fim = await c.avaliar(`document.querySelector('main').innerText`);
    chk('lista de remarketing ficou vazia depois de cancelar', /Nenhuma campanha criada/.test(fim) || !/Teste VPS remarketing/.test(fim), fim.split('\n').filter(Boolean).slice(0, 12).join(' | ').slice(0, 300));
  } catch (e) { console.log('ERRO', e.stack); falha++; }
  finally { try { c.pararDialogo(); } catch (e) {} await c.fechar(); }
  console.log(`\nRESUMO: ${ok} ok, ${falha} falhas`);
})();
