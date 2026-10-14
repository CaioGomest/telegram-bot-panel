// ÁREA 9: Gateways de pagamento (credenciais, ativar/desativar, certificado, segredos, segurança)
const { execFileSync } = require('child_process');
const { lancar, entrar, BASE, db, autoDialogo, remoto } = require('./pg_lib.js');
const segredoMd5 = () => remoto('php zz_dec.php').trim();
const { area, t, nota, salvar, esp } = require('./pg_t.js');
(async () => {
  const c = await lancar();
  autoDialogo(c, true);
  const cfg = () => db(`SELECT id_gateway, client_id, client_secret, chave_pix, tipo_conta, ativo, certificado FROM usuarios_gateways WHERE id_usuario=49 AND id_gateway=2`)[0];
  const idModal = () => c.avaliar(`(function(){ var b=document.querySelector('.btn-icon.editar[onclick*=openModal]'); return b.getAttribute('onclick').split(String.fromCharCode(39))[1]; })()`);
  const salvarForm = async (campos) => {
    await c.ir(BASE + '/gateways');
    const M = await idModal();
    await c.avaliar(`openModal('${M}'); 1`);
    await esp(400);
    await c.avaliar(`(function(){ var f=document.querySelector('#${M} form'); var d=${JSON.stringify(campos)}; Object.keys(d).forEach(function(k){ var e=f.elements[k]; if(!e) return; if(e.type==='checkbox') e.checked=!!d[k]; else e.value=d[k]; }); f.requestSubmit ? f.requestSubmit() : f.submit(); return 1; })()`);
    await c.esperarCarga();
    await esp(600);
  };
  try {
    await entrar(c, 'pgteste-user@example.invalid');
    area('9. Gateways de pagamento');
    await c.ir(BASE + '/gateways');
    await t('página lista a OmegaPayments como disponível (Pix) e não mostra gateways globalmente desativados', async () => {
      const tx = await c.avaliar(`document.querySelector('.conteudo-principal').innerText.split(String.fromCharCode(10)).filter(Boolean).join(' | ')`);
      return { ok: /OmegaPayments/.test(tx) && /Dispon/i.test(tx), det: tx.slice(0, 220) };
    }, 'gateways');
    await t('modal de configuração: mostra o aviso de valores mínimos e os campos; ✕ e clique fora fecham', async () => {
      const M = await idModal();
      await c.avaliar(`openModal('${M}'); 1`);
      await esp(300);
      const r = JSON.parse(await c.avaliar(`JSON.stringify({aviso:/Valores mínimos da OmegaPayments/.test(document.getElementById('${M}').innerText), campos:['client_id','client_secret','tipo_conta'].every(function(n){return !!document.querySelector('#${M} [name='+n+']')}), req:document.querySelector('#${M} [name=client_id]').required})`));
      await c.avaliar(`closeModal('${M}'); 1`);
      const fechou = await c.avaliar(`!document.getElementById('${M}').classList.contains('aberto')`);
      return { ok: r.aviso && r.campos && r.req && fechou, det: JSON.stringify(r) };
    }, 'gateways');
    await t('salvar credenciais pela tela: aviso de sucesso, cartão vai para "ativos" e o banco guarda o segredo CIFRADO (não em texto puro)', async () => {
      await salvarForm({ client_id: 'pg-client-id', client_secret: 'pg-secret-ultra-123', ativo: true, tipo_conta: 'pf' });
      const tx = await c.avaliar('document.body.innerText');
      const r = cfg();
      return { ok: /credenciais foram salvas/i.test(tx) && r && r.client_id === 'pg-client-id' && r.client_secret && r.client_secret !== 'pg-secret-ultra-123' && r.client_secret.indexOf('pg-secret') === -1 && r.ativo === 1 && r.tipo_conta === 'pf', det: `ativo=${r && r.ativo} tipo=${r && r.tipo_conta} segredo_gravado="${(r && r.client_secret || '').slice(0, 24)}…"` };
    }, 'gateways');
    await t('o segredo salvo NÃO aparece no HTML da página (nem no código-fonte) e o campo mostra "(salvo)"', async () => {
      const html = await c.avaliar(`document.documentElement.outerHTML.indexOf('pg-secret-ultra-123')>=0`);
      const ph = await c.avaliar(`document.querySelector('.sobreposicao-modal [name=client_secret]').placeholder`);
      const val = await c.avaliar(`document.querySelector('.sobreposicao-modal [name=client_secret]').value`);
      return { ok: !html && /salvo/i.test(ph) && val === '', det: `placeholder="${ph}"` };
    }, 'segurança');
    await t('salvar com segredo em branco MANTÉM o segredo anterior (não apaga a credencial)', async () => {
      const antes = segredoMd5();
      await salvarForm({ client_id: 'pg-client-id-2', client_secret: '', ativo: true, tipo_conta: 'pj' });
      const r = cfg();
      return { ok: antes !== 'sem-segredo' && segredoMd5() === antes && r.client_id === 'pg-client-id-2' && r.tipo_conta === 'pj', det: `client_id=${r.client_id} tipo=${r.tipo_conta}` };
    }, 'gateways');
    await t('trocar o segredo grava um valor novo (cifrado)', async () => {
      const antes = segredoMd5();
      await salvarForm({ client_id: 'pg-client-id-2', client_secret: 'novo-segredo-456', ativo: true });
      const r = cfg();
      return { ok: segredoMd5() !== antes && r.client_secret.indexOf('novo-segredo') === -1, det: '' };
    }, 'gateways');
    await t('desativar (com confirmação) tira o gateway dos meus bots; ativar de novo restaura', async () => {
      await c.ir(BASE + '/gateways');
      c.aceitar = false;
      await c.avaliar(`desativarGateway(2,'OmegaPayments',null); 1`);
      await esp(900);
      const ainda = cfg().ativo;
      c.aceitar = true;
      await c.avaliar(`desativarGateway(2,'OmegaPayments',null); 1`);
      await c.esperarCarga(); await esp(600);
      const off = cfg().ativo;
      await c.avaliar(`ativarGateway(2,null); 1`);
      await c.esperarCarga(); await esp(600);
      const on = cfg().ativo;
      return { ok: ainda === 1 && off === 0 && on === 1, det: `cancelar→${ainda}, desativar→${off}, ativar→${on}` };
    }, 'gateways');
    await t('certificado: extensão inválida (.txt/.php) é recusada e nada é gravado em certificados/', async () => {
      await c.ir(BASE + '/gateways');
      const r = await c.avaliar(`(async function(){ var fd=new FormData(); fd.append('csrf_token', window.CSRF_TOKEN); fd.append('acao','salvar_user'); fd.append('gateway_id','2'); fd.append('client_id','pg-client-id-2'); fd.append('client_secret',''); fd.append('certificado', new Blob(['<?php echo 1; ?>'],{type:'text/plain'}), 'shell.php'); var x=await fetch('gateways',{method:'POST',body:fd}); var t=await x.text(); return {s:x.status, erro:/Apenas arquivos/.test(t)}; })()`);
      const existe = execFileSync('curl', ['-s', '-o', 'NUL', '-w', '%{http_code}', BASE + '/certificados/cert_49_2.php'], { encoding: 'utf8' });
      return { ok: r.erro && existe !== '200', det: `erro exibido=${r.erro}, GET .php → ${existe}` };
    }, 'segurança');
    await t('SEGURANÇA: certificado .pem enviado não pode ser baixado por URL pública (contém chave privada)', async () => {
      const pem = '-----BEGIN PRIVATE KEY-----\\nMIIFAKEFAKEFAKE\\n-----END PRIVATE KEY-----';
      await c.avaliar(`(async function(){ var fd=new FormData(); fd.append('csrf_token', window.CSRF_TOKEN); fd.append('acao','salvar_user'); fd.append('gateway_id','2'); fd.append('client_id','pg-client-id-2'); fd.append('client_secret',''); fd.append('certificado', new Blob([${JSON.stringify(pem)}],{type:'application/x-pem-file'}), 'minha.pem'); await fetch('gateways',{method:'POST',body:fd}); return 1; })()`);
      await esp(800);
      const gravado = cfg().certificado;
      const url = BASE + '/certificados/cert_49_2.pem';
      const codigo = execFileSync('curl', ['-s', '-o', 'NUL', '-w', '%{http_code}', url], { encoding: 'utf8' });
      const corpo = execFileSync('curl', ['-s', url], { encoding: 'utf8' }).slice(0, 60);
      global.__cert = { gravado, codigo, corpo };
      return { ok: codigo !== '200' || !/PRIVATE KEY/.test(corpo), det: `gravado="${gravado}" GET → HTTP ${codigo} ${corpo.replace(/\n/g, ' ')}` };
    }, 'segurança');
    await t('gateway_id inexistente ou de outro tipo não cria linha nem mostra erro PHP', async () => {
      const antes = db(`SELECT COUNT(*) n FROM usuarios_gateways WHERE id_usuario=49`)[0].n;
      const r = await c.avaliar(`(async function(){ var fd=new URLSearchParams({csrf_token:window.CSRF_TOKEN, acao:'salvar_user', gateway_id:'9999', client_id:'x', client_secret:'y'}); var x=await fetch('gateways',{method:'POST',body:fd}); var t=await x.text(); return {s:x.status, php:/Warning|Fatal|Notice:|Uncaught|SQLSTATE/i.test(t)}; })()`);
      const depois = db(`SELECT COUNT(*) n FROM usuarios_gateways WHERE id_usuario=49`)[0].n;
      return { ok: antes === depois && !r.php, det: `linhas ${antes}→${depois}, erro PHP exibido=${r.php}` };
    }, 'segurança');
    await t('POST sem token CSRF é recusado (403) e não altera a configuração', async () => {
      const antes = cfg().client_id;
      const r = await c.avaliar(`fetch('gateways',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'acao=salvar_user&gateway_id=2&client_id=HACK&client_secret=x'}).then(r=>r.status)`);
      return { ok: r === 403 && cfg().client_id === antes, det: 'HTTP ' + r };
    }, 'segurança');
    await t('usuário comum enviando "salvar_admin" não altera a ativação global dos gateways', async () => {
      const antes = JSON.stringify(db(`SELECT id, ativo FROM gateways ORDER BY id`));
      await c.avaliar(`(async function(){ var fd=new URLSearchParams({csrf_token:window.CSRF_TOKEN, acao:'salvar_admin'}); fd.append('ids[]','1'); fd.append('ids[]','2'); fd.append('ativo[1]','1'); fd.append('ativo[2]','0'); fd.append('taxa_split[2]','99'); await fetch('gateways',{method:'POST',body:fd}); return 1; })()`);
      const depois = JSON.stringify(db(`SELECT id, ativo FROM gateways ORDER BY id`));
      const split = db(`SELECT COALESCE(MAX(taxa_split),0) m FROM gateways`)[0].m;
      return { ok: antes === depois && Number(split) < 99, det: `${antes} → ${depois}; maior taxa_split=${split}` };
    }, 'segurança');
    await t('reordenar gateways só mexe nas linhas do próprio usuário', async () => {
      const outros = JSON.stringify(db(`SELECT id, prioridade FROM usuarios_gateways WHERE id_usuario<>49 ORDER BY id`));
      const r = await c.avaliar(`fetch('gateways',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({csrf_token:window.CSRF_TOKEN, acao:'reordenar_gateways','ordem[0]':'2','ordem[1]':'1'}).toString()}).then(r=>r.json())`);
      const depois = JSON.stringify(db(`SELECT id, prioridade FROM usuarios_gateways WHERE id_usuario<>49 ORDER BY id`));
      return { ok: r.sucesso && outros === depois, det: JSON.stringify(r) };
    }, 'segurança');
    await t('credenciais de um usuário não aparecem na tela de outro (client_id alheio ausente na página)', async () => {
      const alheio = db(`SELECT client_id FROM usuarios_gateways WHERE id_usuario<>49 AND client_id<>'' LIMIT 1`)[0];
      await c.ir(BASE + '/gateways');
      const vaza = alheio ? await c.avaliar(`document.documentElement.innerHTML.indexOf(${JSON.stringify(alheio.client_id)})>=0`) : false;
      return { ok: !vaza, det: alheio ? 'client_id alheio testado' : 'sem credencial alheia' };
    }, 'segurança');
    salvar();
    c.pararDialogo();
  } finally { await c.fechar(); }
})();
