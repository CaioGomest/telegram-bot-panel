// Teste 8: ações reais em links de rastreamento, redirecionamento (link curto), remarketing e traqueamento.
process.env.BASE = 'https://coyotebot.com.br';
const fs = require('fs'), path = require('path');
const ESTADO = process.env.ESTADO || path.join(__dirname, 'estado'); require('fs').mkdirSync(ESTADO, { recursive: true });
const PROJ = path.resolve(__dirname, '../../..');
const { lancar, entrar, autoDialogo } = require(PROJ + '/tests/paginas/pg_lib.js');
const U = JSON.parse(fs.readFileSync(path.join(ESTADO, 'usuario_novo.json'), 'utf8'));
const BASE = 'https://coyotebot.com.br';
const esp = (ms) => new Promise(r => setTimeout(r, ms));
const linhas = (t) => String(t || '').split('\n').map(s => s.trim()).filter(Boolean);
let ok = 0, falha = 0;
const chk = (n, c, x = '') => { c ? ok++ : falha++; console.log((c ? 'OK     ' : 'FALHOU ') + n + (x ? '  -> ' + x : '')); };
async function toast(c, ms = 9000) {
  const t0 = Date.now();
  while (Date.now() - t0 < ms) {
    const x = await c.avaliar(`(function(){var t=document.getElementById('toast');return t&&t.classList.contains('visivel')?t.innerText:''})()`).catch(() => '');
    if (x) return x; await esp(120);
  }
  return '';
}
async function toastSumir(c) { for (let i = 0; i < 40; i++) { const v = await c.avaliar(`!!(document.getElementById('toast')&&document.getElementById('toast').classList.contains('visivel'))`).catch(() => false); if (!v) return; await esp(200); } }
const setv = (id, v) => `(function(){var e=document.getElementById(${JSON.stringify(id)});e.value=${JSON.stringify(v)};e.dispatchEvent(new Event('input',{bubbles:true}));e.dispatchEvent(new Event('change',{bubbles:true}));return 1})()`;
const main = async (c) => linhas(await c.avaliar(`document.querySelector('main').innerText`)).join(' | ');

(async () => {
  const c = await lancar();
  try {
    await c.enviar('Emulation.setDeviceMetricsOverride', { width: 1400, height: 1100, deviceScaleFactor: 1, mobile: false });
    autoDialogo(c, true);
    await entrar(c, U.email, U.senha);

    // ================= A. Links de rastreamento
    console.log('\n--- A. Links de rastreamento');
    await c.ir(BASE + '/links_rastreamento'); await esp(1000);
    await c.avaliar(`document.getElementById('btn-novo-link').click(); 1`); await esp(500);
    await c.avaliar(setv('link-titulo', 'Teste VPS link'));
    await c.avaliar(setv('link-identificador', 'com espaço e áccento!'));
    await c.avaliar(setv('link-bot', '12168'));
    await c.avaliar(`document.getElementById('btn-salvar-link').click(); 1`);
    let t = await toast(c, 10000);
    console.log('   identificador inválido ->', '"' + t + '"');
    const valorId = await c.avaliar(`document.getElementById('link-identificador').value`);
    chk('identificador com espaço/acento/símbolo: recusado ou normalizado', /inv|apenas|somente|letras|n[ãa]o/i.test(t) || /^[A-Za-z0-9-]+$/.test(valorId), 'toast="' + t + '" valor="' + valorId + '"');
    await toastSumir(c);
    await c.avaliar(setv('link-identificador', 'teste-vps'));
    await c.avaliar(`document.getElementById('btn-salvar-link').click(); 1`);
    t = await toast(c, 10000); await esp(1500);
    chk('gerar link de rastreamento', /sucesso|criad|gerad/i.test(t), '"' + t + '"');
    let lista = await main(c);
    chk('link aparece na lista com o t.me do bot', /Teste VPS link/.test(lista) && /Claude_Gomes_bot\?start=/.test(lista + ' ' + await c.avaliar(`document.querySelector('main').innerHTML`)), lista.slice(0, 400));
    await toastSumir(c);
    // duplicado
    await c.avaliar(`document.getElementById('btn-novo-link').click(); 1`); await esp(500);
    await c.avaliar(setv('link-titulo', 'Duplicado')); await c.avaliar(setv('link-identificador', 'teste-vps')); await c.avaliar(setv('link-bot', '12168'));
    await c.avaliar(`document.getElementById('btn-salvar-link').click(); 1`);
    t = await toast(c, 10000);
    chk('identificador repetido: recusado', /j[áa]|exist|uso|duplic|repet/i.test(t), '"' + t + '"');
    await c.captura(path.join(ESTADO, 'links_lista.png'));

    // ================= B. Redirecionamento (link curto)
    console.log('\n--- B. Redirecionamento');
    await c.ir(BASE + '/redirecionamento'); await esp(1000);
    await c.avaliar(`document.getElementById('rd-btn-novo').click(); 1`); await esp(800);
    await c.avaliar(`document.getElementById('rd-salvar').click(); 1`);
    t = await toast(c, 8000);
    chk('criar redirecionador sem nome/destino: avisa', t.length > 3, '"' + t + '"');
    await toastSumir(c);
    await c.avaliar(setv('rd-titulo', 'Campanha Teste VPS'));
    // marca o bot de destino (checkbox sem nome)
    await c.avaliar(`(function(){var cb=[...document.querySelectorAll('input[type=checkbox]')].filter(function(i){return i.id!=='rd-ativo'});cb.forEach(function(i){if(!i.checked)i.click()});return cb.length})()`);
    await c.avaliar(`document.getElementById('rd-salvar').click(); 1`);
    t = await toast(c, 10000); await esp(2000);
    console.log('   criar ->', '"' + t + '"');
    chk('redirecionador criado', /sucesso|criad/i.test(t) || /Campanha Teste VPS/.test(await main(c)), '"' + t + '"');
    await c.ir(BASE + '/redirecionamento'); await esp(1500);
    lista = await main(c);
    chk('redirecionador aparece em "Meus links"', /Campanha Teste VPS/.test(lista), lista.slice(0, 350));
    const slug = await c.avaliar(`(function(){var h=document.querySelector('main').innerHTML;var i=h.indexOf('/l/');return i<0?'':h.substr(i+3,40)})()`);
    const codigo = (String(slug).match(/^[A-Za-z0-9_-]+/) || [''])[0];
    console.log('   código do link curto:', codigo);
    if (codigo) {
      const r = await fetch(BASE + '/l/' + codigo, { redirect: 'manual', headers: { 'User-Agent': 'Mozilla/5.0 (Linux; Android 13) Chrome/120 Mobile' } });
      const loc = r.headers.get('location') || '';
      chk('link curto /l/<código> redireciona para o bot no Telegram', r.status >= 300 && r.status < 400 && /t\.me\/Claude_Gomes_bot/i.test(loc), r.status + ' ' + loc.replace(/start=.*/, 'start=…'));
      await esp(1500);
      await c.ir(BASE + '/redirecionamento'); await esp(1500);
      const cab = await main(c);
      chk('clique foi contado no painel', /CLIQUES \| 1/.test(cab) || /Cliques.{0,6}1/i.test(cab), cab.slice(0, 260));
    }
    const rinv = await fetch(BASE + '/l/codigo-que-nao-existe', { redirect: 'manual' });
    chk('link curto inexistente: sem erro 500', rinv.status < 500, String(rinv.status));
    await c.captura(path.join(ESTADO, 'redirecionamento_lista.png'));

    // ================= C. Remarketing
    console.log('\n--- C. Remarketing');
    await c.ir(BASE + '/remarketing'); await esp(1000);
    const amanha = new Date(Date.now() + 36 * 3600 * 1000); const ontem = new Date(Date.now() - 24 * 3600 * 1000);
    const iso = (d) => d.toISOString().slice(0, 10);
    const preencher = async (dataStr, msg) => {
      await c.avaliar(`document.getElementById('btn-nova-campanha-empty') ? document.getElementById('btn-nova-campanha-empty').click() : document.getElementById('btn-nova-campanha').click(); 1`); await esp(600);
      await c.avaliar(`(function(){var f=document.getElementById('form-campanha');var s=function(n,v){var e=f.querySelector('[name='+n+']')||document.getElementById(n);e.value=v;e.dispatchEvent(new Event('input',{bubbles:true}));e.dispatchEvent(new Event('change',{bubbles:true}))};
        s('nome','Teste VPS remarketing');s('bot_id','12168');s('audiencia','nao_comprou');document.getElementById('modal-data').value=${JSON.stringify(dataStr)};document.getElementById('modal-hora').value='10:00';s('mensagem',${JSON.stringify(msg)});return 1})()`);
    };
    await preencher(iso(ontem), 'Mensagem de teste');
    await c.avaliar(`document.querySelector('#form-campanha button[type=submit], #modal-nova-campanha button.botao-primario').click(); 1`);
    t = await toast(c, 8000);
    chk('agendar no passado: recusado', /passad|futuro|inv|data|hor/i.test(t), '"' + t + '"');
    await toastSumir(c);
    await c.avaliar(`document.getElementById('modal-data').value=${JSON.stringify(iso(amanha))}; 1`);
    await c.avaliar(`document.querySelector('#form-campanha button[type=submit], #modal-nova-campanha button.botao-primario').click(); 1`);
    t = await toast(c, 10000); await esp(2000);
    console.log('   agendar ->', '"' + t + '"');
    await c.ir(BASE + '/remarketing'); await esp(1200);
    lista = await main(c);
    chk('campanha agendada aparece como Pendente', /Teste VPS remarketing/.test(lista) && /Pendente/i.test(lista), lista.slice(0, 420));
    await c.captura(path.join(ESTADO, 'remarketing_lista.png'));

    // ================= D. Traqueamento
    console.log('\n--- D. Traqueamento');
    await c.ir(BASE + '/traqueamento'); await esp(1000);
    await c.avaliar(`(function(){var f=document.querySelector('form[method=post]');f.querySelector('[name=facebook_ativo]').checked=true;f.querySelector('[name=facebook_pixel_id]').value='123456789012345';f.querySelector('[name=facebook_access_token]').value='TOKEN_FALSO_DE_TESTE_VPS';f.submit();return 1})()`);
    await esp(2500); await c.esperarCarga();
    let tx = await main(c);
    console.log('   após salvar:', tx.slice(0, 300));
    const est = JSON.parse(await c.avaliar(`JSON.stringify({ativo:document.querySelector('[name=facebook_ativo]').checked, pixel:document.querySelector('[name=facebook_pixel_id]').value, tokenNoCampo:document.querySelector('[name=facebook_access_token]').value, ph:document.querySelector('[name=facebook_access_token]').placeholder, htmlTemToken:document.documentElement.innerHTML.indexOf('TOKEN_FALSO_DE_TESTE_VPS')>=0})`));
    chk('traqueamento salvo: pixel mantido e token NÃO volta no HTML', est.pixel === '123456789012345' && !est.htmlTemToken && est.tokenNoCampo === '', JSON.stringify(est));
    // limpa: desativa e apaga o que gravei
    await c.avaliar(`(function(){var f=document.querySelector('form[method=post]');f.querySelector('[name=facebook_ativo]').checked=false;f.querySelector('[name=facebook_pixel_id]').value='';f.submit();return 1})()`);
    await esp(2500); await c.esperarCarga();
    const lim = JSON.parse(await c.avaliar(`JSON.stringify({ativo:document.querySelector('[name=facebook_ativo]').checked, pixel:document.querySelector('[name=facebook_pixel_id]').value, ph:document.querySelector('[name=facebook_access_token]').placeholder})`));
    console.log('   após limpar:', JSON.stringify(lim));
  } catch (e) { console.log('ERRO', e.stack); falha++; }
  finally { try { c.pararDialogo(); } catch (e) {} await c.fechar(); }
  console.log(`\nRESUMO: ${ok} ok, ${falha} falhas`);
})();
