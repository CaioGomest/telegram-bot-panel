// ÁREA 12: Minha Conta (dados, apelido, senha, foto, e-mail) — usa o usuário 2 para poder trocar senha à vontade
const fs = require('fs');
const path = require('path');
const zlib = require('zlib');
const { execFileSync } = require('child_process');
const { lancar, entrar, BASE, db, remoto, autoDialogo } = require('./pg_lib.js');
const { area, t, nota, salvar, esp } = require('./pg_t.js');
const TMP = path.join(process.env.OUT || '.', 'tmp'); fs.mkdirSync(TMP, { recursive: true });

function png(largura = 8) {
  const crc = (b) => { let c, crcT = []; for (let n = 0; n < 256; n++) { c = n; for (let k = 0; k < 8; k++) c = c & 1 ? 0xEDB88320 ^ (c >>> 1) : c >>> 1; crcT[n] = c >>> 0; } let r = 0xFFFFFFFF; for (const x of b) r = crcT[(r ^ x) & 0xFF] ^ (r >>> 8); return (r ^ 0xFFFFFFFF) >>> 0; };
  const chunk = (tipo, dados) => { const len = Buffer.alloc(4); len.writeUInt32BE(dados.length); const td = Buffer.concat([Buffer.from(tipo), dados]); const c = Buffer.alloc(4); c.writeUInt32BE(crc(td)); return Buffer.concat([len, td, c]); };
  const ihdr = Buffer.alloc(13); ihdr.writeUInt32BE(largura, 0); ihdr.writeUInt32BE(largura, 4); ihdr[8] = 8; ihdr[9] = 2;
  const linha = Buffer.concat([Buffer.from([0]), Buffer.alloc(largura * 3, 120)]);
  const raw = Buffer.concat(Array.from({ length: largura }, () => linha));
  return Buffer.concat([Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]), chunk('IHDR', ihdr), chunk('IDAT', zlib.deflateSync(raw)), chunk('IEND', Buffer.alloc(0))]);
}
async function anexar(c, seletor, arquivo) {
  const doc = await c.enviar('DOM.getDocument', {});
  const q = await c.enviar('DOM.querySelector', { nodeId: doc.result.root.nodeId, selector: seletor });
  await c.enviar('DOM.setFileInputFiles', { nodeId: q.result.nodeId, files: [arquivo] });
}

(async () => {
  const c = await lancar();
  autoDialogo(c, true);
  const U2 = JSON.parse(remoto('php zz_pg.php user2').trim());
  const E2 = 'pgteste-user2@example.invalid';
  const u2 = () => db(`SELECT nome, email, apelido_publico, foto_perfil, email_verificado_em, senha FROM usuarios WHERE id=${U2.u2}`)[0];
  const enviar = async (campos) => {
    await c.ir(BASE + '/configuracao_usuario');
    await c.avaliar(`(function(){ var d=${JSON.stringify(campos)}; var f=document.querySelector('form[method=POST]'); Object.keys(d).forEach(function(k){ var e=f.elements[k]; if(e) e.value=d[k]; }); f.submit(); return 1; })()`);
    await c.esperarCarga(); await esp(400);
    return c.avaliar(`JSON.stringify({aviso:(document.querySelector('.aviso')||{}).innerText||'', cls:(document.querySelector('.aviso')||{}).className||''})`).then(JSON.parse);
  };
  try {
    await entrar(c, E2);
    area('12. Minha Conta');
    await c.ir(BASE + '/configuracao_usuario');
    await t('formulário vem preenchido (nome, e-mail, apelido) e tem trocar/remover foto e troca de senha', async () => {
      const r = JSON.parse(await c.avaliar(`JSON.stringify({n:document.getElementById('nome').value, e:document.getElementById('email').value, foto:!!document.getElementById('input-foto-perfil'), senhas:['senha_atual','senha','confirmar_senha'].every(function(n){return !!document.querySelector('[name='+n+']')})})`));
      return { ok: r.n === 'Usuario Dois PG' && r.e === E2 && r.foto && r.senhas, det: JSON.stringify(r) };
    }, 'conta');
    await t('atualizar nome e apelido: salva, mostra "Perfil atualizado" e o cabeçalho passa a saudar o novo nome', async () => {
      const r = await enviar({ nome: 'Maria Teste Silva', email: E2, apelido_publico: 'maria_pg' });
      const b = u2();
      await c.ir(BASE + '/');
      const saud = await c.avaliar(`document.querySelector('.topo-saudacao').innerText`);
      return { ok: /atualizado com sucesso/i.test(r.aviso) && b.nome === 'Maria Teste Silva' && b.apelido_publico === 'maria_pg' && /Maria/.test(saud), det: r.aviso + ' | ' + saud.replace(/\n/g, ' ') };
    }, 'conta');
    await t('nome com HTML é exibido como texto na página e no cabeçalho (sem executar script)', async () => {
      await enviar({ nome: '<img src=x onerror=window.__nm=1>Zé', email: E2, apelido_publico: '<b>ap</b>' });
      await c.ir(BASE + '/');
      const a = await c.avaliar('window.__nm===1');
      await c.ir(BASE + '/configuracao_usuario');
      const b = await c.avaliar('window.__nm===1');
      const imgs = await c.avaliar(`document.querySelectorAll('.topo-saudacao img, .conteudo-principal form img').length`);
      return { ok: !a && !b && imgs === 0, det: '' };
    }, 'segurança');
    await t('apelido maior que 40 caracteres é cortado em 40', async () => {
      await enviar({ nome: 'Maria Teste Silva', email: E2, apelido_publico: 'a'.repeat(60) });
      return { ok: u2().apelido_publico.length === 40, det: 'tamanho ' + u2().apelido_publico.length };
    }, 'conta');
    await t('e-mail inválido e e-mail de outro usuário são recusados', async () => {
      const a = await enviar({ nome: 'Maria', email: 'isso-nao-e-email', apelido_publico: '' });
      const b = await enviar({ nome: 'Maria', email: 'pgteste-user@example.invalid', apelido_publico: '' });
      return { ok: /inválido/i.test(a.aviso) && /em uso/i.test(b.aviso) && u2().email === E2, det: a.aviso + ' | ' + b.aviso };
    }, 'conta');
    await t('nome em branco é recusado (servidor), mesmo ignorando o "required" do navegador', async () => {
      await c.ir(BASE + '/configuracao_usuario');
      const r = await c.avaliar(`fetch('configuracao_usuario',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({csrf_token:window.CSRF_TOKEN,nome:'',email:'${E2}'}).toString()}).then(r=>r.text())`);
      return { ok: /obrigat/i.test(r) && u2().nome !== '', det: '' };
    }, 'conta');

    area('12b. Senha');
    await t('nova senha sem a senha atual → "Informe a senha atual"; senhas diferentes → "não conferem"', async () => {
      const a = await enviar({ nome: 'Maria Teste Silva', email: E2, senha: 'NovaSenha123', confirmar_senha: 'NovaSenha123', senha_atual: '' });
      const b = await enviar({ nome: 'Maria Teste Silva', email: E2, senha: 'NovaSenha123', confirmar_senha: 'Outra123456', senha_atual: '123456' });
      return { ok: /senha atual/i.test(a.aviso) && /não conferem/i.test(b.aviso), det: a.aviso + ' | ' + b.aviso };
    }, 'senha');
    await t('senha atual errada é recusada e a senha não muda', async () => {
      const antes = u2().senha;
      const r = await enviar({ nome: 'Maria Teste Silva', email: E2, senha: 'NovaSenha123', confirmar_senha: 'NovaSenha123', senha_atual: 'errada' });
      return { ok: /incorreta/i.test(r.aviso) && u2().senha === antes, det: r.aviso };
    }, 'senha');
    await t('política de senha: nova senha com menos de 8 caracteres (igual ao cadastro) é recusada', async () => {
      const antes = u2().senha;
      const r = await enviar({ nome: 'Maria Teste Silva', email: E2, senha: '123abc', confirmar_senha: '123abc', senha_atual: '123456' });
      return { ok: /pelo menos 8/i.test(r.aviso) && u2().senha === antes, det: r.aviso };
    }, 'senha');
    await t('trocar a senha corretamente: grava hash novo e o login passa a exigir a nova senha', async () => {
      const antes = u2().senha;
      const r = await enviar({ nome: 'Maria Teste Silva', email: E2, senha: 'NovaSenha123', confirmar_senha: 'NovaSenha123', senha_atual: '123456' });
      const depois = u2().senha;
      await c.ir(BASE + '/logout'); await esp(500);
      await c.ir(BASE + '/login');
      await c.avaliar(`document.getElementById('email').value='${E2}'; document.getElementById('senha').value='123456'; document.querySelector('form[action="login"]').submit(); 1`);
      await c.esperarCarga();
      const antigaFunciona = !(await c.avaliar('location.pathname')).includes('login');
      if (antigaFunciona) await c.ir(BASE + '/logout');
      await c.ir(BASE + '/login');
      await c.avaliar(`document.getElementById('email').value='${E2}'; document.getElementById('senha').value='NovaSenha123'; document.querySelector('form[action="login"]').submit(); 1`);
      await c.esperarCarga();
      const novaFunciona = !(await c.avaliar('location.pathname')).includes('login');
      return { ok: /sucesso/i.test(r.aviso) && depois !== antes && depois.indexOf('NovaSenha') === -1 && /^\$2y\$|^\$argon/.test(depois) && !antigaFunciona && novaFunciona, det: `hash ${depois.slice(0, 7)}…, antiga funciona=${antigaFunciona}, nova funciona=${novaFunciona}` };
    }, 'senha');

    area('12c. E-mail e foto');
    await t('trocar o e-mail invalida a verificação (selo "verificado" não vale para o novo endereço)', async () => {
      const antes = u2();
      const r = await enviar({ nome: 'Maria Teste Silva', email: 'pgteste-user2-novo@example.invalid', apelido_publico: '' });
      const dep = u2();
      return { ok: /sucesso/i.test(r.aviso) && dep.email === 'pgteste-user2-novo@example.invalid' && dep.email_verificado_em === null, det: `antes verificado=${!!antes.email_verificado_em}, depois verificado=${!!dep.email_verificado_em}` };
    }, 'conta');
    await enviar({ nome: 'Maria Teste Silva', email: E2, apelido_publico: '' });
    await c.ir(BASE + '/configuracao_usuario');
    await t('foto: arquivo que não é imagem (texto renomeado .png) é recusado', async () => {
      const f = path.join(TMP, 'falso.png'); fs.writeFileSync(f, 'isto nao e uma imagem');
      await anexar(c, '#input-foto-perfil', f);
      await c.esperarCarga(); await esp(400);
      const tx = await c.avaliar(`(document.querySelector('.aviso')||{}).innerText||''`);
      return { ok: /JPG, PNG ou WebP/i.test(tx) && !u2().foto_perfil, det: tx };
    }, 'foto');
    await t('foto: imagem maior que 2 MB é recusada', async () => {
      await c.ir(BASE + '/configuracao_usuario');
      const grande = Buffer.concat([png(8), Buffer.alloc(2.2 * 1024 * 1024, 0)]);
      const f = path.join(TMP, 'grande.png'); fs.writeFileSync(f, grande);
      await anexar(c, '#input-foto-perfil', f);
      await c.esperarCarga(); await esp(400);
      const tx = await c.avaliar(`(document.querySelector('.aviso')||{}).innerText||''`);
      return { ok: /2 MB/i.test(tx) && !u2().foto_perfil, det: tx };
    }, 'foto');
    await t('foto: SVG e PHP disfarçados são recusados (só JPG/PNG/WebP reais)', async () => {
      const casos = [['x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'], ['x.php.png', '<?php echo 1; ?>'], ['x.png', 'GIF89a<?php echo 1; ?>']];
      const falhas = [];
      for (const [nome, conteudo] of casos) {
        await c.ir(BASE + '/configuracao_usuario');
        const f = path.join(TMP, nome); fs.writeFileSync(f, conteudo);
        await anexar(c, '#input-foto-perfil', f);
          await c.esperarCarga(); await esp(300);
        if (u2().foto_perfil) falhas.push(nome + ' aceito');
      }
      return { ok: !falhas.length, det: falhas.join(', ') };
    }, 'segurança');
    await t('foto PNG válida: é salva em uploads/perfis, aparece no avatar do menu e é servida como imagem', async () => {
      await c.ir(BASE + '/configuracao_usuario');
      const f = path.join(TMP, 'ok.png'); fs.writeFileSync(f, png(16));
      await anexar(c, '#input-foto-perfil', f);
      await c.esperarCarga(); await esp(600);
      const b = u2();
      const src = await c.avaliar(`(document.querySelector('.a-foto .big img')||{}).src||''`);
      const info = src ? execFileSync('curl', ['-s', '-o', 'NUL', '-w', '%{http_code} %{content_type}', src], { encoding: 'utf8' }) : 'sem src';
      global.__foto = b.foto_perfil;
      return { ok: /^perfil_\d+_\d+\.png$/.test(b.foto_perfil) && /^200 image\/png/.test(info), det: `${b.foto_perfil} → ${info}` };
    }, 'foto');
    await t('trocar a foto apaga o arquivo antigo (não acumula lixo em uploads/perfis)', async () => {
      const antiga = global.__foto;
      await esp(1100);
      await c.ir(BASE + '/configuracao_usuario');
      const f = path.join(TMP, 'ok2.png'); fs.writeFileSync(f, png(24));
      await anexar(c, '#input-foto-perfil', f);
      await c.esperarCarga(); await esp(600);
      const nova = u2().foto_perfil;
      const codigoAntiga = execFileSync('curl', ['-s', '-o', 'NUL', '-w', '%{http_code}', `${BASE}/uploads/perfis/${antiga}`], { encoding: 'utf8' });
      return { ok: nova !== antiga && codigoAntiga === '404', det: `antiga → HTTP ${codigoAntiga}` };
    }, 'foto');
    await t('remover foto: limpa o campo e apaga o arquivo; o avatar volta às iniciais', async () => {
      await c.ir(BASE + '/configuracao_usuario');
      const atual = u2().foto_perfil;
      await c.avaliar(`document.querySelector('button[form=form-remover-foto]').click(); 1`);
      await c.esperarCarga(); await esp(600);
      const dep = u2().foto_perfil;
      const cod = execFileSync('curl', ['-s', '-o', 'NUL', '-w', '%{http_code}', `${BASE}/uploads/perfis/${atual}`], { encoding: 'utf8' });
      const inic = await c.avaliar(`document.querySelector('.a-foto .big').innerText.trim()`);
      return { ok: !dep && cod === '404' && inic.length >= 1, det: `iniciais="${inic}"` };
    }, 'foto');
    await t('POST sem token CSRF é recusado e não altera o perfil', async () => {
      const antes = u2().nome;
      const r = await c.avaliar(`fetch('configuracao_usuario',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'nome=HACK&email=${E2}'}).then(r=>r.status)`);
      return { ok: r === 403 && u2().nome === antes, det: 'HTTP ' + r };
    }, 'segurança');
    salvar();
    c.pararDialogo();
  } finally { await c.fechar(); }
})();
