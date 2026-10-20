// Admin na VPS: SALVAR Configurações (identidade, domínios, Google, e-mail) e SALVAR Split do gateway. Muda valores de verdade e RESTAURA tudo no fim;
// a prova da restauração é a fotografia do banco antes/depois (ver README: vps_snapshot_config.php). Se travar no meio, os originais estão em estado/config_original.json.
// Uso: ADMIN_EMAIL=.. ADMIN_SENHA=.. node 19_admin_salvar_config_split.js
const [DONO_USER, DONO_DOM] = (process.env.EMAIL_DONO || 'dono@exemplo.com').split('@'); // base dos e-mails de teste (aliases +admintest e +vps1510): informe o seu em EMAIL_DONO
process.env.BASE = 'https://coyotebot.com.br';
const fs = require('fs'), path = require('path');
const PROJ = path.resolve(__dirname, '../../..');
const { lancar, entrar, autoDialogo } = require(PROJ + '/tests/paginas/pg_lib.js');
const ESTADO = process.env.ESTADO || path.join(__dirname, 'estado'); fs.mkdirSync(ESTADO, { recursive: true });
const U = JSON.parse(fs.readFileSync(path.join(ESTADO, 'usuario_novo.json'), 'utf8'));
const BASE = 'https://coyotebot.com.br';
const CFG = BASE + '/admin/configuracoes';
const esp = (ms) => new Promise(r => setTimeout(r, ms));
const linhas = (t) => String(t || '').split('\n').map(s => s.trim()).filter(Boolean);
let ok = 0, falha = 0;
const chk = (n, c, x = '') => { c ? ok++ : falha++; console.log((c ? 'OK     ' : 'FALHOU ') + n + (x ? '  -> ' + String(x).slice(0, 230) : '')); };
const info = (n, x) => console.log('INFO   ' + n + '  -> ' + String(x).slice(0, 260));

const msgs = (c) => c.avaliar(`[...document.querySelectorAll('.aviso')].map(function(e){return e.textContent.trim()}).filter(Boolean).join(' || ')`);
// lê os campos (não secretos) de um formulário
const lerForm = (c, id) => c.avaliar(`(function(){var f=document.getElementById(${JSON.stringify(id)});var o={};[...f.elements].forEach(function(e){if(!e.name||e.name==='acao'||/csrf/i.test(e.name)||e.type==='password'||e.type==='file'||e.type==='submit'||e.type==='button')return;o[e.name]=(e.type==='checkbox'||e.type==='radio')?(e.checked?(e.value||'on'):''):e.value});return JSON.stringify(o)})()`).then(JSON.parse);
// abre a página, preenche e envia o formulário (form.submit() não passa pelo modal de confirmação do JS)
async function enviar(c, formId, valores) {
  await c.ir(CFG); await esp(700);
  await c.avaliar(`(function(){var f=document.getElementById(${JSON.stringify(formId)});var o=${JSON.stringify(valores)};Object.keys(o).forEach(function(k){var e=f.elements[k];e.value=o[k]});f.setAttribute('novalidate','');f.submit();return 1})()`);
  await esp(2400); await c.esperarCarga();
  return { url: await c.avaliar('location.pathname + location.search'), msgs: await msgs(c) };
}
// POST direto (como um cliente que ignora a validação do navegador). Devolve os avisos da página de resposta.
const postarCfg = (c, campos, rota = '/admin/configuracoes') => c.avaliar(`(async function(){
  var tk=[...document.querySelectorAll('input[type=hidden]')].find(function(i){return /csrf/i.test(i.name)}); var fd=new FormData(); if(tk) fd.append(tk.name, tk.value);
  var o=${JSON.stringify(campos)}; Object.keys(o).forEach(function(k){fd.append(k,o[k])});
  var r=await fetch(${JSON.stringify(rota)},{method:'POST',body:fd,credentials:'include'}); var t=await r.text();
  var d=new DOMParser().parseFromString(t,'text/html');
  return JSON.stringify({status:r.status,url:r.url.replace(location.origin,''),msgs:[...d.querySelectorAll('.aviso')].map(function(e){return e.textContent.trim()}).filter(Boolean).join(' || ')}) })()`).then(JSON.parse);

(async () => {
  const c = await lancar();
  let origId = null, origSplit = null;
  try {
    autoDialogo(c, true);
    await c.enviar('Emulation.setDeviceMetricsOverride', { width: 1500, height: 1100, deviceScaleFactor: 1, mobile: false });
    chk('login do admin', /admin/.test(await entrar(c, process.env.ADMIN_EMAIL, process.env.ADMIN_SENHA)));

    // ================= originais (guardados em arquivo antes de mexer)
    await c.ir(CFG); await esp(900);
    origId = await lerForm(c, 'form-identidade');
    const origDom = await lerForm(c, 'form-dominios');
    const origGoogle = await lerForm(c, 'form-google');
    const origEmail = await lerForm(c, 'form-email');
    fs.writeFileSync(path.join(ESTADO, 'config_original.json'), JSON.stringify({ origId, origDom, origGoogle, origEmail }, null, 1));
    info('originais', 'nome="' + origId.nome_sistema + '" cor=' + origId.cor_primaria + ' | smtp=' + origEmail.smtp_host + ':' + origEmail.smtp_porta + ' ' + origEmail.smtp_seguranca + ' | url_base=' + origEmail.url_base + ' | domínios=' + JSON.stringify(origDom.dominios_redirecionamento));

    // ================= I. Identidade visual
    console.log('\n--- I. Identidade visual');
    let r = await enviar(c, 'form-identidade', { nome_sistema: origId.nome_sistema, cor_primaria: origId.cor_primaria });
    chk('salvar SEM mudar nada: "Identidade visual atualizada."', /Identidade visual atualizada/.test(r.msgs), r.url + ' | ' + r.msgs);
    chk('valores iguais depois de salvar', JSON.stringify(await lerForm(c, 'form-identidade')) === JSON.stringify(origId));
    r = await enviar(c, 'form-identidade', { nome_sistema: 'CoyoteBot TESTE', cor_primaria: '#3b82f6' });
    const f1 = await lerForm(c, 'form-identidade');
    const tituloAba = await c.avaliar('document.title');
    const nomeBarra = await c.avaliar(`document.body.innerText.indexOf('CoyoteBot TESTE')>=0`);
    chk('mudar nome e cor: salva e já aparece na aba do navegador/tela', f1.nome_sistema === 'CoyoteBot TESTE' && f1.cor_primaria === '#3b82f6' && /CoyoteBot TESTE/.test(tituloAba), 'título da aba: ' + tituloAba + ' | nome na tela: ' + nomeBarra);
    r = await enviar(c, 'form-identidade', { nome_sistema: '', cor_primaria: '#3b82f6' });
    chk('nome vazio: recusado', /não pode ficar vazio/.test(r.msgs), r.msgs);
    r = await enviar(c, 'form-identidade', { nome_sistema: 'ATOMICO-NAO-DEVE-SALVAR', cor_primaria: 'azul' });
    const f2 = await lerForm(c, 'form-identidade');
    chk('cor inválida: recusada e o nome enviado junto NÃO é gravado (tudo ou nada)', /Cor inválida/.test(r.msgs) && f2.nome_sistema === 'CoyoteBot TESTE', r.msgs + ' | nome agora: ' + f2.nome_sistema);
    r = await enviar(c, 'form-identidade', { nome_sistema: 'N'.repeat(75), cor_primaria: '#3b82f6' });
    const f3 = await lerForm(c, 'form-identidade');
    chk('nome com 75 caracteres: truncado em 60', f3.nome_sistema.length === 60, 'tamanho gravado: ' + f3.nome_sistema.length);
    r = await enviar(c, 'form-identidade', { nome_sistema: '<img src=x onerror=alert(1)>Nome XSS', cor_primaria: '#3b82f6' });
    const xssHtml = await c.avaliar(`document.documentElement.innerHTML.indexOf('<img src=x onerror=alert(1)>')>=0`);
    chk('nome com HTML: gravado, mas escapado na tela (não vira tag)', xssHtml === false, 'HTML cru presente: ' + xssHtml);
    // upload inválido no logo
    await c.ir(CFG); await esp(700);
    await c.avaliar(`(function(){var f=document.getElementById('form-identidade');var dt=new DataTransfer();dt.items.add(new File(['<?php echo 1; ?>'],'logo-falso.png',{type:'image/png'}));f.elements['logo'].files=dt.files;f.elements['nome_sistema'].value='CoyoteBot TESTE';f.elements['cor_primaria'].value='#3b82f6';f.submit();return 1})()`);
    await esp(2600); await c.esperarCarga();
    const mLogo = await msgs(c);
    chk('logo com PHP disfarçado de PNG: recusado', !/atualizada\.?$/.test(mLogo.split(' || ')[0] || '') && /(imagem|inválid|formato|arquivo)/i.test(mLogo), mLogo);
    // modal de confirmação
    await c.ir(CFG); await esp(800);
    await c.avaliar(`(function(){var f=document.getElementById('form-identidade');f.elements['nome_sistema'].value='NAO-SALVAR-CANCELADO';[...f.querySelectorAll('button[type=submit]')][0].click();return 1})()`);
    await esp(700);
    const modalAberto = await c.avaliar(`(function(){var m=document.getElementById('modal-confirmar-config');return m.classList.contains('aberto')||getComputedStyle(m).display!=='none'})()`);
    const textoModal = await c.avaliar(`document.getElementById('texto-confirmar-config').textContent`);
    chk('clicar em Salvar abre a confirmação ("Confirmar e salvar")', modalAberto === true && /Confirma/.test(textoModal), textoModal);
    await c.avaliar(`document.getElementById('btn-cancelar-confirmar-config').click(); 1`); await esp(700);
    await c.ir(CFG); await esp(700);
    chk('Cancelar na confirmação NÃO salva', (await lerForm(c, 'form-identidade')).nome_sistema !== 'NAO-SALVAR-CANCELADO');
    await c.avaliar(`(function(){var f=document.getElementById('form-identidade');f.elements['nome_sistema'].value=${JSON.stringify(origId.nome_sistema)};f.elements['cor_primaria'].value=${JSON.stringify(origId.cor_primaria)};[...f.querySelectorAll('button[type=submit]')][0].click();return 1})()`);
    await esp(600);
    await c.avaliar(`document.getElementById('btn-confirmar-confirmar-config').click(); 1`); await esp(2600); await c.esperarCarga();
    chk('"Confirmar e salvar" grava (e restaura o nome/cor originais)', JSON.stringify(await lerForm(c, 'form-identidade')) === JSON.stringify(origId) && /salvo=1/.test(await c.avaliar('location.search')), await c.avaliar('location.search'));

    // ================= II. Domínios do redirecionamento
    console.log('\n--- II. Domínios do redirecionamento');
    r = await enviar(c, 'form-dominios', { dominios_redirecionamento: origDom.dominios_redirecionamento });
    chk('salvar sem mudar: "Domínios do redirecionamento atualizados."', /Domínios do redirecionamento atualizados/.test(r.msgs), r.msgs);
    for (const d of ['http://x.com', 'localhost', 'a_b.com', '999.1.1.1', 'x', 'dominio com espaco.com.br/caminho']) {
      r = await enviar(c, 'form-dominios', { dominios_redirecionamento: d });
      chk('domínio inválido "' + d + '": recusado', /Domínio de redirecionamento inválido/.test(r.msgs), r.msgs);
    }
    chk('nenhum inválido foi gravado', (await lerForm(c, 'form-dominios')).dominios_redirecionamento === origDom.dominios_redirecionamento);
    r = await enviar(c, 'form-dominios', { dominios_redirecionamento: 'links-teste.exemplo.com.br' });
    info('domínio válido "links-teste.exemplo.com.br"', r.msgs + ' | gravado: ' + JSON.stringify((await lerForm(c, 'form-dominios')).dominios_redirecionamento));
    r = await enviar(c, 'form-dominios', { dominios_redirecionamento: origDom.dominios_redirecionamento });
    chk('domínios restaurados', (await lerForm(c, 'form-dominios')).dominios_redirecionamento === origDom.dominios_redirecionamento);

    // ================= III. Login com Google
    console.log('\n--- III. Login com Google');
    const botaoGoogle = async () => { const rr = await fetch(BASE + '/login'); return /Continuar com Google|google_login/.test(await rr.text()); };
    const googleAntes = await botaoGoogle();
    r = await enviar(c, 'form-google', { google_client_id: origGoogle.google_client_id });
    chk('salvar o mesmo Client ID com secret em branco: "Credenciais do Google atualizadas."', /Credenciais do Google atualizadas/.test(r.msgs), r.msgs);
    chk('login com Google continua configurado (secret em branco mantém o atual)', googleAntes === (await botaoGoogle()), 'botão antes=' + googleAntes + ' depois=' + (await botaoGoogle()));
    r = await enviar(c, 'form-google', { google_client_id: '' });
    chk('Client ID vazio: recusado', /Preencha o Client ID/.test(r.msgs), r.msgs);
    chk('Client ID continua o original', (await lerForm(c, 'form-google')).google_client_id === origGoogle.google_client_id);

    // ================= IV. E-mail (SMTP)
    console.log('\n--- IV. E-mail (SMTP)');
    r = await enviar(c, 'form-email', {});
    chk('salvar o formulário de e-mail SEM mudar nada (senha em branco): "Configurações de e-mail salvas."', /Configurações de e-mail salvas/.test(r.msgs), r.url + ' | ' + r.msgs);
    chk('campos iguais depois de salvar', JSON.stringify(await lerForm(c, 'form-email')) === JSON.stringify(origEmail));
    await c.ir(CFG); await esp(800);
    await c.avaliar(`(function(){var f=document.getElementById('form-teste-email');f.elements['email_teste_destino'].value='${DONO_USER}+admintest@${DONO_DOM}';document.getElementById('btn-teste-email').click();return 1})()`);
    await esp(10000);
    const mTeste = await c.avaliar(`(document.getElementById('resultado-email')||{}).textContent||''`);
    chk('"Enviar teste" depois de salvar: SMTP segue funcionando (a senha em branco manteve a antiga)', /E-mail de teste enviado/.test(mTeste), mTeste.trim());
    const casos = [
      ['servidor SMTP com caracteres inválidos', { smtp_host: 'smtp host!', smtp_porta: '587', smtp_seguranca: 'tls', smtp_usuario: 'u', email_remetente: 'a@b.com', url_base: BASE }, /Servidor SMTP inválido/],
      ['porta 99999', { smtp_host: 'smtp.exemplo.com', smtp_porta: '99999', smtp_seguranca: 'tls', smtp_usuario: 'u', email_remetente: 'a@b.com', url_base: BASE }, /Porta inválida/],
      ['segurança "xyz"', { smtp_host: 'smtp.exemplo.com', smtp_porta: '587', smtp_seguranca: 'xyz', smtp_usuario: 'u', email_remetente: 'a@b.com', url_base: BASE }, /Segurança inválida/],
      ['remetente "abc"', { smtp_host: 'smtp.exemplo.com', smtp_porta: '587', smtp_seguranca: 'tls', smtp_usuario: 'u', email_remetente: 'abc', url_base: BASE }, /remetente inválido/],
      ['URL base "ftp://x.com"', { smtp_host: 'smtp.exemplo.com', smtp_porta: '587', smtp_seguranca: 'tls', smtp_usuario: 'u', email_remetente: 'a@b.com', url_base: 'ftp://x.com' }, /URL do painel inválida/],
      ['URL base com javascript:', { smtp_host: 'smtp.exemplo.com', smtp_porta: '587', smtp_seguranca: 'tls', smtp_usuario: 'u', email_remetente: 'a@b.com', url_base: 'javascript:alert(1)' }, /URL do painel inválida/],
    ];
    for (const [nome, campos, esperado] of casos) {
      const p = await postarCfg(c, { acao: 'email', ...campos });
      chk('e-mail: ' + nome + ' recusado', esperado.test(p.msgs), p.msgs);
    }
    await c.ir(CFG); await esp(700);
    chk('nenhuma configuração de e-mail inválida foi gravada', JSON.stringify(await lerForm(c, 'form-email')) === JSON.stringify(origEmail));
    const pf = await postarCfg(c, { acao: 'email_fila_reprocessar' });
    chk('"Reprocessar falhas" da fila responde', /recolocados na fila|indisponível/.test(pf.msgs), pf.msgs);
    // nome do remetente com HTML: escapado
    await enviar(c, 'form-email', { email_remetente_nome: '<b onmouseover=alert(1)>Remetente</b>' });
    chk('nome do remetente com HTML: escapado na tela', (await c.avaliar(`document.documentElement.innerHTML.indexOf('<b onmouseover=alert(1)>')>=0`)) === false);
    await enviar(c, 'form-email', { email_remetente_nome: origEmail.email_remetente_nome });

    // ================= V. Quem pode salvar
    console.log('\n--- V. Autorização e CSRF nas Configurações');
    await c.ir(CFG); await esp(600);
    const semCsrf = await c.avaliar(`(async function(){var fd=new FormData();fd.append('acao','identidade');fd.append('nome_sistema','SEM CSRF');fd.append('cor_primaria','#ff0000');var r=await fetch('/admin/configuracoes',{method:'POST',body:fd,credentials:'include'});return JSON.stringify({status:r.status})})()`).then(JSON.parse);
    chk('POST sem token CSRF: recusado', semCsrf.status >= 400, 'HTTP ' + semCsrf.status);
    chk('o nome não mudou com o POST sem CSRF', (await lerForm(c, 'form-identidade')).nome_sistema === origId.nome_sistema);

    // ================= VI. Split do gateway
    console.log('\n--- VI. Split do gateway (Admin → Gateways)');
    await c.ir(BASE + '/gateways'); await esp(900);
    const lerSplit = () => c.avaliar(`JSON.stringify((function(){var g={};[...document.querySelectorAll('#formAdmin [name]')].forEach(function(e){var m=e.name.match(/^(ativo|taxa_split|tipo_split|chave_pix_split)\\[(\\d+)\\]$/);if(m&&e.type!=='hidden'){g.id=m[2];g[m[1]]=e.type==='checkbox'?e.checked:e.value}});return g})())`).then(JSON.parse);
    origSplit = await lerSplit();
    fs.writeFileSync(path.join(ESTADO, 'split_original.json'), JSON.stringify(origSplit));
    info('split original', 'id=' + origSplit.id + ' ativo=' + origSplit.ativo + ' taxa=' + origSplit.taxa_split + ' tipo=' + origSplit.tipo_split + ' producerId(' + (origSplit.chave_pix_split || '').length + ' caracteres)');
    const salvarSplit = async (v) => {
      await c.ir(BASE + '/gateways'); await esp(700);
      await c.avaliar(`(function(){var id=${JSON.stringify(origSplit.id)};var v=${JSON.stringify(v)};var f=document.getElementById('formAdmin');
        if('taxa' in v) f.elements['taxa_split['+id+']'].value=v.taxa; if('tipo' in v) f.elements['tipo_split['+id+']'].value=v.tipo; if('chave' in v) f.elements['chave_pix_split['+id+']'].value=v.chave;
        f.setAttribute('novalidate','');f.submit();return 1})()`);
      await esp(2200); await c.esperarCarga();
      const caixa = linhas(await c.avaliar(`(function(){var m=document.querySelector('main');return [...m.querySelectorAll('li')].map(function(l){return l.innerText}).join('\\n')})()`)).join(' | ');
      return { split: await lerSplit(), msgs: await msgs(c), caixa };
    };
    let s = await salvarSplit({});
    chk('salvar o split SEM mudar nada: valores idênticos depois', JSON.stringify(s.split) === JSON.stringify(origSplit), JSON.stringify(s.split).replace(origSplit.chave_pix_split, '<producerId>'));
    info('mensagem de sucesso ao salvar o split (admin)', s.msgs || '(nenhuma mensagem aparece na tela depois de salvar)');
    s = await salvarSplit({ taxa: '1.40', tipo: 'fixo' });
    chk('split R$ 1,40 fixo: salvo e a caixa mostra a taxa e o novo preço mínimo (R$ 2,10)', s.split.taxa_split === '1.40' && /1,40 por venda/.test(s.caixa) && /2,10/.test(s.caixa), s.caixa);
    s = await salvarSplit({ taxa: '5', tipo: 'percentual' });
    chk('split 5% do valor: a caixa mostra "5% do valor da venda" e o mínimo continua R$ 1,50', /5% do valor da venda/.test(s.caixa) && /R\$ 1,50/.test(s.caixa), s.caixa);
    s = await salvarSplit({ chave: '' });
    chk('sem producerId (taxa > 0): a caixa diz "nenhuma no momento" (split não é feito)', /nenhuma no momento/.test(s.caixa), s.caixa);
    await salvarSplit({ taxa: origSplit.taxa_split, tipo: origSplit.tipo_split, chave: origSplit.chave_pix_split });
    // valores fora de faixa, direto no servidor (o navegador impediria: min=0 max=100)
    const idg = origSplit.id;
    const tentativas = [['percentual 150', { taxa: '150', tipo: 'percentual' }], ['negativo -5', { taxa: '-5', tipo: 'percentual' }], ['1000 (fora do DECIMAL 5,2)', { taxa: '1000', tipo: 'fixo' }], ['texto "abc"', { taxa: 'abc', tipo: 'fixo' }], ['tipo inválido "hack"', { taxa: '1', tipo: 'hack' }]];
    for (const [nome, v] of tentativas) {
      await c.ir(BASE + '/gateways'); await esp(500);
      await postarCfg(c, { acao: 'salvar_admin', ['ids[]']: idg, ['ativo[' + idg + ']']: '1', ['taxa_split[' + idg + ']']: v.taxa, ['tipo_split[' + idg + ']']: v.tipo, ['chave_pix_split[' + idg + ']']: origSplit.chave_pix_split }, '/gateways');
      await c.ir(BASE + '/gateways'); await esp(500);
      const g = await lerSplit();
      info('split ' + nome + ' — o servidor gravou', 'taxa=' + g.taxa_split + ' tipo=' + g.tipo_split);
      // volta ao original antes do próximo
      await postarCfg(c, { acao: 'salvar_admin', ['ids[]']: idg, ['ativo[' + idg + ']']: '1', ['taxa_split[' + idg + ']']: origSplit.taxa_split, ['tipo_split[' + idg + ']']: origSplit.tipo_split, ['chave_pix_split[' + idg + ']']: origSplit.chave_pix_split }, '/gateways');
    }
    await c.ir(BASE + '/gateways'); await esp(700);
    chk('split restaurado ao original', JSON.stringify(await lerSplit()) === JSON.stringify(origSplit));
    // usuário comum não salva split
    await c.enviar('Network.clearBrowserCookies');
    await entrar(c, U.email, U.senha);
    await c.ir(BASE + '/gateways'); await esp(700);
    const pu = await postarCfg(c, { acao: 'salvar_admin', ['ids[]']: idg, ['ativo[' + idg + ']']: '1', ['taxa_split[' + idg + ']']: '99', ['tipo_split[' + idg + ']']: 'percentual', ['chave_pix_split[' + idg + ']']: 'x' }, '/gateways');
    await c.enviar('Network.clearBrowserCookies');
    await entrar(c, process.env.ADMIN_EMAIL, process.env.ADMIN_SENHA);
    await c.ir(BASE + '/gateways'); await esp(700);
    chk('usuário comum enviando "salvar_admin" não altera o split', JSON.stringify(await lerSplit()) === JSON.stringify(origSplit), 'resposta: ' + pu.status + ' ' + pu.url);
  } catch (e) { console.log('ERRO', e.stack); falha++; }
  finally { try { c.pararDialogo(); } catch (e) {} await c.fechar(); }
  console.log(`\nRESUMO: ${ok} ok, ${falha} falhas`);
})();
