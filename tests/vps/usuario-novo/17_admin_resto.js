// Admin na VPS (continuação do 15): Configurações (segredos mascarados, e-mail de teste), Gateways (split, só leitura), E-mails, consultar venda, logs, dashboard, logout.
// Só leitura, exceto o "Enviar teste" de e-mail (1 mensagem para o alias do dono). Uso: ADMIN_EMAIL=.. ADMIN_SENHA=.. [SEM_EMAIL=1] node 17_admin_resto.js
const [DONO_USER, DONO_DOM] = (process.env.EMAIL_DONO || 'dono@exemplo.com').split('@'); // base dos e-mails de teste (aliases +admintest e +vps1510): informe o seu em EMAIL_DONO
process.env.BASE = 'https://coyotebot.com.br';
const fs = require('fs'), path = require('path');
const PROJ = path.resolve(__dirname, '../../..');
const { lancar, entrar, autoDialogo, inspecionar, resumo } = require(PROJ + '/tests/paginas/pg_lib.js');
const BASE = 'https://coyotebot.com.br';
const esp = (ms) => new Promise(r => setTimeout(r, ms));
const linhas = (t) => String(t || '').split('\n').map(s => s.trim()).filter(Boolean);
let ok = 0, falha = 0;
const chk = (n, c, x = '') => { c ? ok++ : falha++; console.log((c ? 'OK     ' : 'FALHOU ') + n + (x ? '  -> ' + x : '')); };
const main = async (c) => linhas(await c.avaliar(`document.querySelector('main').innerText`)).join(' | ');

(async () => {
  const c = await lancar();
  try {
    autoDialogo(c, true);
    await c.enviar('Emulation.setDeviceMetricsOverride', { width: 1500, height: 1000, deviceScaleFactor: 1, mobile: false });
    const dest = await entrar(c, process.env.ADMIN_EMAIL, process.env.ADMIN_SENHA);
    chk('login do admin', /admin/.test(dest), dest);

    // Configurações: segredos não vão para o HTML
    await c.ir(BASE + '/admin/configuracoes'); await esp(1200);
    const cfg = JSON.parse(await c.avaliar(`JSON.stringify({vals:[...document.querySelectorAll('input[type=password]')].map(function(i){return i.name+':'+i.value.length}), enc:document.documentElement.innerHTML.indexOf('enc:v1:')>=0, host:(document.querySelector('[name=smtp_host]')||{}).value||'', porta:(document.querySelector('[name=smtp_porta]')||{}).value||'', seg:(document.querySelector('[name=smtp_seguranca]')||{}).value||'', remetente:(document.querySelector('[name=email_remetente]')||{}).value||'', urlBase:(document.querySelector('[name=url_base]')||{}).value||'', googleId:((document.querySelector('[name=google_client_id]')||{}).value||'').length, dominios:((document.querySelector('[name=dominios_redirecionamento]')||{}).value||'')})`));
    console.log('   SMTP:', cfg.host + ':' + cfg.porta, cfg.seg, '| remetente:', cfg.remetente, '| URL base:', cfg.urlBase, '| domínios de redirecionamento:', JSON.stringify(cfg.dominios), '| google_client_id preenchido:', cfg.googleId > 0);
    chk('Configurações: campos de senha vêm vazios e não há "enc:v1:" no HTML', cfg.vals.every(v => /:0$/.test(v)) && !cfg.enc, JSON.stringify(cfg.vals) + ' enc=' + cfg.enc);
    chk('Configurações: URL base é o domínio novo (links dos e-mails)', /coyotebot\.com\.br/.test(cfg.urlBase), cfg.urlBase);
    // e-mail de teste (1 mensagem real para o alias do dono)
    if (!process.env.SEM_EMAIL) {
      c.dialogos = [];
      await c.avaliar(`(function(){var f=document.getElementById('form-teste-email');f.querySelector('[name=email_teste_destino]').value='${DONO_USER}+admintest@${DONO_DOM}';document.getElementById('btn-teste-email').click();return 1})()`);
      await esp(9000);
      const resp = linhas(await c.avaliar(`document.body.innerText`)).filter(l => /enviad|sucesso|falh|erro|recus|timeout|aceit/i.test(l)).slice(0, 4).join(' | ') + ' ' + (c.dialogos || []).map(d => '[alert] ' + d.msg).join(' ');
      console.log('   "Enviar teste" ->', resp.slice(0, 300));
      chk('e-mail de teste: o servidor SMTP respondeu sem erro', /enviad|sucesso|aceit/i.test(resp) && !/falh|erro|recus/i.test(resp), resp.slice(0, 160));
    }

    // Gateways (admin): split atual, sem salvar
    await c.ir(BASE + '/gateways'); await esp(1000);
    const gw = JSON.parse(await c.avaliar(`JSON.stringify({ativo:document.querySelector('input[name^="ativo["][type=checkbox]').checked, taxa:document.querySelector('[name^="taxa_split["]').value, tipo:document.querySelector('[name^="tipo_split["]').value, tamId:document.querySelector('[name^="chave_pix_split["]').value.length})`));
    console.log('   split da VPS agora:', JSON.stringify(gw), '(tamId = tamanho do producerId gravado; o da homologação tem 25)');
    chk('Gateways (admin): gateway liberado e split legíveis', gw.taxa !== '' && gw.tipo !== '' && gw.ativo === true, JSON.stringify(gw));

    // E-mails
    await inspecionar(c, '/admin/emails?busca=vps1510', { pausa: 500 });
    let t = await main(c);
    chk('E-mails: boas-vindas da conta de teste consta como enviado', /vps1510/.test(t) && /Enviado/.test(t), t.slice(t.indexOf('DATA'), t.indexOf('DATA') + 240));
    const cartoes = t.slice(0, 300);
    console.log('   cartões de e-mail:', cartoes.slice(cartoes.indexOf('ENVIADOS')));

    // consultar venda
    for (const q of ['id=999999999', 'id=abc', 'id=-1', 'id=']) {
      const rr = await inspecionar(c, '/admin/consultar_venda?' + q, { pausa: 400 });
      const p = resumo(rr).filter(x => !/ícone sem title/.test(x));
      const fim = linhas(await c.avaliar(`document.querySelector('main').innerText`)).slice(-2).join(' | ').slice(0, 120);
      chk('consultar venda ?' + q, p.length === 0 && rr.status === 200, p.join(' ; ') + ' | ' + fim);
    }
    // logs
    for (const q of ['/admin/logs?pagina=2', '/admin/logs?pagina=999999', '/admin/logs?pagina=-3', '/admin/emails?pagina=99999']) {
      const rr = await inspecionar(c, q, { pausa: 400 });
      const p = resumo(rr).filter(x => !/ícone sem title/.test(x));
      chk(q, p.length === 0 && rr.status === 200, p.join(' ; ') + ' ' + rr.ms + 'ms');
    }

    // dashboard: períodos
    await c.ir(BASE + '/admin/dashboard'); await esp(1000);
    c.eventos.length = 0;
    for (const p of ['Hoje', 'Ontem', '30 dias', 'Total', '8 dias']) {
      const t0 = Date.now();
      await c.avaliar(`(function(){var b=[...document.querySelectorAll('main button, main a')].find(function(x){return x.innerText.trim()===${JSON.stringify(p)}});if(b)b.click();return 1})()`);
      await esp(1200); await c.esperarCarga();
      console.log('   admin dashboard período', p.padEnd(8), (Date.now() - t0) + ' ms');
    }
    const errosJs = c.errosJs(); const red = c.eventos.filter(x => x.method === 'Network.responseReceived' && x.params.response.status >= 400).map(x => x.params.response.status + ' ' + x.params.response.url.replace(BASE, ''));
    chk('dashboard do admin: 5 períodos sem erro de JS nem requisição com erro', errosJs.length === 0 && red.length === 0, 'js=' + errosJs.length + ' rede=' + JSON.stringify(red));

    // sino do admin
    await c.avaliar(`(function(){var b=[...document.querySelectorAll('button')].find(function(x){return /Notifica/i.test(x.getAttribute('aria-label')||x.title||'')});if(b)b.click();return 1})()`);
    await esp(1200);
    const sino = await c.avaliar(`(function(){var p=[...document.querySelectorAll('[class*=notifica], [id*=notifica]')].filter(function(e){return e.offsetParent!==null && e.innerText.trim().length>0});return p.length?p[0].innerText.trim().slice(0,160):''})()`);
    chk('sino do admin abre um painel', sino.length > 0, linhas(sino).join(' | '));

    // logout
    await c.ir(BASE + '/logout'); await esp(700);
    await c.ir(BASE + '/admin/dashboard'); await esp(500);
    chk('logout do admin encerra a sessão', /login/.test(await c.avaliar('location.pathname')));
  } catch (e) { console.log('ERRO', e.stack); falha++; }
  finally { try { c.pararDialogo(); } catch (e) {} await c.fechar(); }
  console.log(`\nRESUMO: ${ok} ok, ${falha} falhas`);
  process.exit(falha ? 1 : 0);
})();
