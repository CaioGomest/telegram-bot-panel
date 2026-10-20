// Admin na VPS: (A) usuário comum NÃO consegue usar os endpoints de admin; (B) admin: usuários (filtro, detalhes, criar, editar, excluir, auto-exclusão),
// transações, comunidade (cria/desativa/exclui um link de teste), segredos mascarados em Configurações, e-mails, consultar venda, logs, dashboard.
// Só mexe no que cria. Uso: ADMIN_EMAIL=.. ADMIN_SENHA=.. node 15_admin_acoes.js   (usa estado/usuario_novo.json para a parte A)
const [DONO_USER, DONO_DOM] = (process.env.EMAIL_DONO || 'dono@exemplo.com').split('@'); // base dos e-mails de teste (aliases +admintest e +vps1510): informe o seu em EMAIL_DONO
process.env.BASE = 'https://coyotebot.com.br';
const fs = require('fs'), path = require('path');
const PROJ = path.resolve(__dirname, '../../..');
const { lancar, entrar, autoDialogo, inspecionar, resumo } = require(PROJ + '/tests/paginas/pg_lib.js');
const ESTADO = process.env.ESTADO || path.join(__dirname, 'estado'); fs.mkdirSync(ESTADO, { recursive: true });
const U = JSON.parse(fs.readFileSync(path.join(ESTADO, 'usuario_novo.json'), 'utf8'));
const BASE = 'https://coyotebot.com.br';
const esp = (ms) => new Promise(r => setTimeout(r, ms));
const linhas = (t) => String(t || '').split('\n').map(s => s.trim()).filter(Boolean);
let ok = 0, falha = 0;
const chk = (n, c, x = '') => { c ? ok++ : falha++; console.log((c ? 'OK     ' : 'FALHOU ') + n + (x ? '  -> ' + x : '')); };
const main = async (c) => linhas(await c.avaliar(`document.querySelector('main').innerText`)).join(' | ');
const EMAIL_NOVO = `${DONO_USER}+admintest@${DONO_DOM}`;

// POST em /ajax/<arq> com o token CSRF da página aberta; devolve {status, url, corpo}
const postar = (c, arq, campos) => c.avaliar(`(async function(){
  var tk=[...document.querySelectorAll('input[type=hidden]')].find(function(i){return /csrf/i.test(i.name)});
  var fd=new FormData(); if(tk) fd.append(tk.name, tk.value);
  var o=${JSON.stringify(campos)}; Object.keys(o).forEach(function(k){fd.append(k,o[k])});
  var r=await fetch('/ajax/'+${JSON.stringify(arq)},{method:'POST',body:fd,credentials:'include'});
  var t=await r.text(); return JSON.stringify({status:r.status,url:r.url.replace(location.origin,''),corpo:t.slice(0,160).split(String.fromCharCode(10)).join(' ')}) })()`).then(JSON.parse);

(async () => {
  const c = await lancar();
  try {
    autoDialogo(c, true);
    await c.enviar('Emulation.setDeviceMetricsOverride', { width: 1500, height: 1000, deviceScaleFactor: 1, mobile: false });

    // ============ A. usuário comum x endpoints de admin
    console.log('\n--- A. usuário comum tentando usar endpoints de admin');
    await entrar(c, U.email, U.senha);
    await c.ir(BASE + '/configuracao_usuario'); await esp(800);
    const tentativas = [
      ['adicionar_usuario.php', { nome: 'Invasor', email: 'invasor+x@example.com', senha: 'Invasor#12345', perfil: 'admin' }],
      ['editar_usuario.php', { id: '1', nome: 'Hackeado', email: `${DONO_USER}@${DONO_DOM}`, perfil: 'admin', senha: 'x1234567' }],
      ['deletar_usuario.php', { id: '99999999' }],
      ['detalhes_usuario.php?id=1', {}],
    ];
    for (const [arq, campos] of tentativas) {
      const r = await postar(c, arq, campos);
      chk('usuário comum em ajax/' + arq.split('?')[0] + ': barrado', !/"sucesso":\s*true/.test(r.corpo) && !/"email"|"nome"/.test(r.corpo), r.status + ' ' + r.url + ' ' + r.corpo.slice(0, 80));
    }
    // GET do detalhes do admin (id 1) como usuário comum não pode vazar dados
    const d1 = await c.avaliar(`fetch('/ajax/detalhes_usuario.php?id=1',{credentials:'include'}).then(function(r){return r.text()}).then(function(t){return t.indexOf('${DONO_USER}@${DONO_DOM}')>=0})`);
    chk('detalhes do admin (id 1) não vazam para usuário comum', d1 === false);

    // ============ B. admin
    console.log('\n--- B. admin');
    await c.enviar('Network.clearBrowserCookies');
    const dest = await entrar(c, process.env.ADMIN_EMAIL, process.env.ADMIN_SENHA);
    chk('login do admin com 123456', /admin/.test(dest), dest);

    // B1 usuários: filtros e detalhes
    await c.ir(BASE + '/admin/usuarios'); await esp(1500);
    // As listas de perfil/e-mail/bots saíram da tela em 16/10: esses parâmetros agora são IGNORADOS (o teste confirma que não quebram a página). Só a busca e a ordem filtram.
    const opc = { email: ['', 'confirmado'], bots: ['', 'com'], ordem: ['', 'antigos', 'nome'] };
    console.log('   opções dos filtros:', JSON.stringify(opc));
    for (const q of ['busca=' + DONO_USER, 'busca=' + encodeURIComponent("' OR 1=1--"), 'busca=%3Cscript%3Ealert(1)%3C%2Fscript%3E', 'busca=6062', 'bots=' + (opc.bots[1] || ''), 'email=' + (opc.email[1] || ''), 'ordem=' + (opc.ordem[1] || ''), 'ordem=' + (opc.ordem[2] || ''), 'pagina=99999', 'perfil=admin']) {
      const r = await inspecionar(c, '/admin/usuarios?' + q, { pausa: 500 });
      const xss = await c.avaliar(`document.body.innerHTML.indexOf('<script>alert(1)')>=0`);
      const p = resumo(r).filter(x => !/ícone sem title/.test(x));
      chk('usuários ?' + q.slice(0, 40), p.length === 0 && r.status === 200 && !xss, p.join(' ; ') + ' ' + r.ms + 'ms');
    }
    await c.ir(BASE + '/admin/usuarios?busca=' + DONO_USER + '%2Bvps1510'); await esp(1200);
    let t = await main(c);
    chk('busca por e-mail acha a conta de teste (#6062) e só ela', /6062/.test(t) && /Teste VPS Renomeado/.test(t) && !/Vendedor 1 /.test(t), t.slice(t.indexOf('ID'), t.indexOf('ID') + 200));
    await c.avaliar(`(function(){var tr=[...document.querySelectorAll('tbody tr')].find(function(x){return x.innerText.indexOf('6062')>=0});var b=[...tr.querySelectorAll('button')].find(function(x){return /Detalhes/i.test(x.innerText+x.title+x.getAttribute('aria-label'))});b.click();return 1})()`);
    await esp(1500);
    const det = await c.avaliar(`(function(){var m=document.getElementById('modalDetalhes');return m?m.innerText:''})()`);
    chk('modal "Ver Detalhes" abre com dados da conta', new RegExp('Teste VPS|' + DONO_USER).test(det), linhas(det).join(' | ').slice(0, 260));

    // B2 criar usuário
    const abrirAdd = async (nome, email, senha, perfil) => {
      await c.ir(BASE + '/admin/usuarios'); await esp(1200);
      c.dialogos = [];
      await c.avaliar(`abrirModalAdicionar(); 1`); await esp(400);
      await c.avaliar(`(function(){var f=document.querySelector('#modalAdicionar form');var s=function(n,v){f.querySelector('[name='+n+']').value=v};s('nome',${JSON.stringify(nome)});s('email',${JSON.stringify(email)});s('senha',${JSON.stringify(senha)});s('perfil',${JSON.stringify(perfil)});f.setAttribute('novalidate','');f.requestSubmit();return 1})()`);
      await esp(2500);
      const naTela = linhas(await c.avaliar(`document.body.innerText`)).filter(l => /sucesso|inv[áa]lid|j[áa] est[áa]|pelo menos|erro|preencha/i.test(l));
      return naTela.concat((c.dialogos || []).map(d => '[alert] ' + d.msg)).join(' | ');
    };
    let r = await abrirAdd('Curta', EMAIL_NOVO, '12345', 'usuario');
    chk('criar usuário com senha de 5 caracteres: recusado pelo servidor', /pelo menos 8/.test(r), 'resposta: "' + r + '"');
    r = await abrirAdd('Seis', EMAIL_NOVO, '123456', 'usuario');
    console.log('   senha de 6 caracteres (o modal diz "mínimo 6"):', '"' + r + '"');
    const aceitou6 = !/pelo menos 8/.test(r) && /sucesso|^$/.test(r);
    await c.ir(BASE + '/admin/usuarios?busca=admintest'); await esp(1200);
    t = await main(c);
    const criouCom6 = /admintest/.test(t);
    chk('modal diz "mínimo 6", servidor exige 8 (senha de 6 não cria a conta)', !criouCom6, criouCom6 ? 'A CONTA FOI CRIADA COM 6 CARACTERES' : 'não criou (servidor exige 8 — o texto do modal está errado)');
    if (criouCom6) await esp(0);
    r = await abrirAdd('Admin Teste Criado', EMAIL_NOVO, 'Teste#12345', 'usuario');
    await c.ir(BASE + '/admin/usuarios?busca=admintest'); await esp(1200);
    t = await main(c);
    chk('criar usuário válido pelo admin', /Admin Teste Criado/.test(t) && /admintest/.test(t), t.slice(t.indexOf('ID'), t.indexOf('ID') + 220));
    r = await abrirAdd('Duplicado', EMAIL_NOVO, 'Teste#12345', 'usuario');
    chk('criar com e-mail repetido: recusado', /j[áa] est[áa] cadastrado/i.test(r), '"' + r + '"');

    // B3 editar e excluir o usuário criado
    const idNovo = await (async () => {
      await c.ir(BASE + '/admin/usuarios?busca=admintest'); await esp(1000);
      return c.avaliar(`(function(){var tr=[...document.querySelectorAll('tbody tr')].find(function(x){return x.innerText.indexOf('admintest')>=0});if(!tr) return '';var m=tr.innerText.match(/#(\\d+)/);return m?m[1]:''})()`);
    })();
    console.log('   id do usuário criado:', idNovo);
    if (idNovo) {
      await c.avaliar(`(function(){var tr=[...document.querySelectorAll('tbody tr')].find(function(x){return x.innerText.indexOf('admintest')>=0});var b=[...tr.querySelectorAll('button')].find(function(x){return /Editar/i.test(x.innerText+x.title+x.getAttribute('aria-label'))});b.click();return 1})()`);
      await esp(600);
      await c.avaliar(`(function(){var f=document.querySelector('#modalEditar form');f.querySelector('[name=nome]').value='Admin Teste Editado';f.requestSubmit();return 1})()`);
      await esp(2500);
      await c.ir(BASE + '/admin/usuarios?busca=admintest'); await esp(1000);
      t = await main(c);
      chk('editar nome do usuário', /Admin Teste Editado/.test(t), t.slice(t.indexOf('ID'), t.indexOf('ID') + 160));
      // promover a admin e voltar (perfil)
      const rp = await postar(c, 'editar_usuario.php', { id: idNovo, nome: 'Admin Teste Editado', email: EMAIL_NOVO, perfil: 'admin' });
      console.log('   promover a admin ->', rp.corpo.slice(0, 100));
      const rp2 = await postar(c, 'editar_usuario.php', { id: idNovo, nome: 'Admin Teste Editado', email: EMAIL_NOVO, perfil: 'usuario' });
      console.log('   voltar a usuário ->', rp2.corpo.slice(0, 100));
      // não pode excluir a própria conta
      const self = await postar(c, 'deletar_usuario.php', { id: '1' });
      let msgSelf = self.corpo; try { msgSelf = JSON.parse(self.corpo).erro || msgSelf; } catch (e) { /* corpo não é JSON puro */ }
      chk('admin não consegue excluir a própria conta', /pr[óo]pria conta/i.test(msgSelf), msgSelf);
      // excluir o criado (confirm() é aceito pelo autoDialogo)
      await c.avaliar(`(function(){var tr=[...document.querySelectorAll('tbody tr')].find(function(x){return x.innerText.indexOf('admintest')>=0});var b=[...tr.querySelectorAll('button')].find(function(x){return /Excluir/i.test(x.innerText+x.title+x.getAttribute('aria-label'))});b.click();return 1})()`);
      await esp(1500);
      await c.avaliar(`(function(){var b=[...document.querySelectorAll('button')].find(function(x){return x.offsetParent!==null && /^(Excluir|Confirmar|Sim)/i.test(x.innerText.trim()) && x.closest('.sobreposicao-modal')});if(b)b.click();return 1})()`);
      await esp(2500);
      await c.ir(BASE + '/admin/usuarios?busca=admintest'); await esp(1000);
      t = await main(c);
      chk('excluir o usuário criado pelo teste', !/admintest/.test(t), t.slice(0, 200));
    }

    // B4 transações
    for (const q of ['status=pago', 'status=pendente', 'split_status=pendente', 'data_inicio=2026-10-01&data_fim=2026-10-15', 'busca=SIMTX1', 'busca=%27%20OR%201%3D1--', 'usuario_id=6062', 'pagina=99999']) {
      const rr = await inspecionar(c, '/admin/transacoes?' + q, { pausa: 400 });
      const p = resumo(rr).filter(x => !/ícone sem title/.test(x));
      if (rr.ms > 12000) p.push('LENTO ' + rr.ms + ' ms');
      chk('transações ?' + q, p.length === 0 && rr.status === 200, p.join(' ; ') + ' ' + rr.ms + 'ms');
    }

    // B5 comunidade: cria/desativa/exclui um link de teste (não mexe no "123")
    await c.ir(BASE + '/admin/comunidade'); await esp(1000);
    await c.avaliar(`document.querySelector('button[onclick*="modal"], main button').click(); 1`); await esp(500);
    const vis = await c.avaliar(`[...document.querySelectorAll('.sobreposicao-modal')].filter(function(m){return getComputedStyle(m).display!=='none'}).length`);
    if (!vis) { await c.avaliar(`[...document.querySelectorAll('button')].find(function(b){return /Novo link/.test(b.innerText)}).click(); 1`); await esp(500); }
    await c.avaliar(`(function(){var f=document.querySelector('#modal-form form');var s=function(n,v){var e=f.querySelector('[name='+n+']');e.value=v};s('titulo','Teste admin VPS (apagar)');s('subtitulo','criado pelo teste');s('url','https://example.com/teste-admin-vps');f.requestSubmit();return 1})()`);
    await esp(2500);
    await c.ir(BASE + '/admin/comunidade'); await esp(1000);
    t = await main(c);
    chk('comunidade: link de teste criado', /Teste admin VPS/.test(t), t.slice(0, 260));
    await c.avaliar(`(function(){var tr=[...document.querySelectorAll('tbody tr, main tr')].find(function(x){return x.innerText.indexOf('Teste admin VPS')>=0});var b=[...tr.querySelectorAll('button')].find(function(x){return /Desativar/i.test(x.innerText+x.title+(x.getAttribute('aria-label')||''))});b.click();return 1})()`);
    await esp(2200); await c.ir(BASE + '/admin/comunidade'); await esp(800);
    t = await main(c);
    chk('comunidade: desativar o link de teste', /Teste admin VPS[^|]*\|?[^|]*\|?[^|]*Inativo|Inativo/i.test(t) || /Ativar/.test(t), t.slice(t.indexOf('ORDEM'), t.indexOf('ORDEM') + 320));
    const usuarioVe = await (async () => { return null; })();
    await c.avaliar(`(function(){var tr=[...document.querySelectorAll('tbody tr, main tr')].find(function(x){return x.innerText.indexOf('Teste admin VPS')>=0});var b=[...tr.querySelectorAll('button')].find(function(x){return /Excluir/i.test(x.innerText+x.title+(x.getAttribute('aria-label')||''))});b.click();return 1})()`);
    await esp(2500); await c.ir(BASE + '/admin/comunidade'); await esp(800);
    t = await main(c);
    chk('comunidade: excluir o link de teste (o "123" original fica)', !/Teste admin VPS/.test(t) && /123/.test(t), t.slice(t.indexOf('ORDEM'), t.indexOf('ORDEM') + 200));

    // B6 configurações: segredos não vazam no HTML
    const htmlCfg = await (async () => { await c.ir(BASE + '/admin/configuracoes'); await esp(1000); return c.avaliar(`JSON.stringify({vals:[...document.querySelectorAll('input[type=password]')].map(function(i){return i.name+':'+i.value.length}), enc:document.documentElement.innerHTML.indexOf('enc:v1:')>=0, smtpUsuario:(document.querySelector('[name=smtp_usuario]')||{}).value||'', host:(document.querySelector('[name=smtp_host]')||{}).value||''})`).then(JSON.parse); })();
    console.log('   campos de senha em Configurações (nome:tamanho do valor):', JSON.stringify(htmlCfg.vals), '| SMTP host:', htmlCfg.host);
    chk('Configurações: campos de senha vêm vazios e nada "enc:v1:" no HTML', htmlCfg.vals.every(v => /:0$/.test(v)) && !htmlCfg.enc, JSON.stringify(htmlCfg.vals) + ' enc=' + htmlCfg.enc);

    // B7 gateways (admin): lê o split atual sem salvar
    await c.ir(BASE + '/gateways'); await esp(1000);
    const gw = JSON.parse(await c.avaliar(`JSON.stringify({ativo:document.querySelector('input[name="ativo[1]"], input[name^="ativo["][type=checkbox]').checked, taxa:document.querySelector('[name^="taxa_split["]').value, tipo:document.querySelector('[name^="tipo_split["]').value, tamId:document.querySelector('[name^="chave_pix_split["]').value.length})`));
    console.log('   split da VPS agora:', JSON.stringify(gw), '(tamId = tamanho do producerId gravado; o da homologação tem 25)');
    chk('Gateways (admin): split e liberação do gateway legíveis', gw.taxa !== '' && gw.tipo !== '');

    // B8 e-mails, consultar venda, logs, desempenho
    const e = await inspecionar(c, '/admin/emails?busca=vps1510', { pausa: 500 });
    t = await main(c);
    chk('E-mails: o de boas-vindas da conta de teste consta como enviado', /vps1510/.test(t) && /Enviado/.test(t), t.slice(t.indexOf('DATA'), t.indexOf('DATA') + 260));
    for (const q of ['id=999999999', 'id=abc', 'id=-1', 'id=']) {
      const rr = await inspecionar(c, '/admin/consultar_venda?' + q, { pausa: 400 });
      const p = resumo(rr).filter(x => !/ícone sem title/.test(x));
      chk('consultar venda ?' + q, p.length === 0 && rr.status === 200, p.join(' ; ') + ' | ' + linhas(await c.avaliar(`document.querySelector('main').innerText`)).slice(-2).join(' | ').slice(0, 120));
    }
    for (const q of ['/admin/logs?pagina=2', '/admin/logs?pagina=999999', '/admin/logs?pagina=-3']) {
      const rr = await inspecionar(c, q, { pausa: 400 });
      const p = resumo(rr).filter(x => !/ícone sem title/.test(x));
      chk('logs ' + q, p.length === 0 && rr.status === 200, p.join(' ; ') + ' ' + rr.ms + 'ms');
    }

    // B9 dashboard do admin: períodos
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

    // logout
    await c.ir(BASE + '/logout'); await esp(700);
    await c.ir(BASE + '/admin/dashboard'); await esp(500);
    chk('logout do admin encerra a sessão', /login/.test(await c.avaliar('location.pathname')));
  } catch (e) { console.log('ERRO', e.stack); falha++; }
  finally { try { c.pararDialogo(); } catch (e) {} await c.fechar(); }
  console.log(`\nRESUMO: ${ok} ok, ${falha} falhas`);
})();
