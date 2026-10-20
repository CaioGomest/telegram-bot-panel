// Split do gateway (Admin → Gateways) DEPOIS da correção de validação (15/10/2026): valor fora da regra é recusado e NADA é gravado; sucesso mostra mensagem.
// Muda o split de verdade por instantes e restaura; prova: fotografia do banco antes/depois (vps_snapshot_config.php). Uso: ADMIN_EMAIL=.. ADMIN_SENHA=.. node 21_admin_split_validacao.js
process.env.BASE = 'https://coyotebot.com.br';
const fs = require('fs'), path = require('path');
const PROJ = path.resolve(__dirname, '../../..');
const { lancar, entrar, autoDialogo } = require(PROJ + '/tests/paginas/pg_lib.js');
const ESTADO = process.env.ESTADO || path.join(__dirname, 'estado'); fs.mkdirSync(ESTADO, { recursive: true });
const U = JSON.parse(fs.readFileSync(path.join(ESTADO, 'usuario_novo.json'), 'utf8'));
const BASE = 'https://coyotebot.com.br';
const esp = (ms) => new Promise(r => setTimeout(r, ms));
const linhas = (t) => String(t || '').split('\n').map(s => s.trim()).filter(Boolean);
let ok = 0, falha = 0;
const chk = (n, c, x = '') => { c ? ok++ : falha++; console.log((c ? 'OK     ' : 'FALHOU ') + n + (x ? '  -> ' + String(x).slice(0, 240) : '')); };
const mascara = (o, chave) => JSON.stringify(o).split(chave).join('<producerId>');

(async () => {
  const c = await lancar();
  let orig = null;
  try {
    autoDialogo(c, true);
    await c.enviar('Emulation.setDeviceMetricsOverride', { width: 1500, height: 1100, deviceScaleFactor: 1, mobile: false });
    chk('login do admin', /admin/.test(await entrar(c, process.env.ADMIN_EMAIL, process.env.ADMIN_SENHA)));
    const abrir = async () => { await c.ir(BASE + '/gateways'); await esp(600); };
    const lerSplit = () => c.avaliar(`JSON.stringify((function(){var g={};[...document.querySelectorAll('#formAdmin [name]')].forEach(function(e){var m=e.name.match(/^(ativo|taxa_split|tipo_split|chave_pix_split)\\[(\\d+)\\]$/);if(m&&e.type!=='hidden'){g.id=m[2];g[m[1]]=e.type==='checkbox'?e.checked:e.value}});return g})())`).then(JSON.parse);
    const avisos = () => c.avaliar(`[...document.querySelectorAll('main .aviso')].map(function(e){return e.innerText.trim()}).filter(Boolean).join(' || ')`);
    const caixa = async () => linhas(await c.avaliar(`[...document.querySelectorAll('main li')].map(function(l){return l.innerText}).join(String.fromCharCode(10))`)).join(' | ');
    await abrir();
    orig = await lerSplit();
    fs.writeFileSync(path.join(ESTADO, 'split_original.json'), JSON.stringify(orig));
    const id = orig.id;
    console.log('   split original: taxa=' + orig.taxa_split + ' tipo=' + orig.tipo_split + ' ativo=' + orig.ativo + ' producerId(' + orig.chave_pix_split.length + ' caracteres)');

    // POST direto, como um cliente que ignora o min/max do navegador
    const postar = (campos, ids = [id]) => c.avaliar(`(async function(){
      var tk=[...document.querySelectorAll('input[type=hidden]')].find(function(i){return /csrf/i.test(i.name)}); var fd=new FormData(); fd.append(tk.name, tk.value); fd.append('acao','salvar_admin');
      ${JSON.stringify(ids)}.forEach(function(i){fd.append('ids[]',i)});
      var o=${JSON.stringify(campos)}; Object.keys(o).forEach(function(k){fd.append(k,o[k])});
      var r=await fetch('/gateways',{method:'POST',body:fd,credentials:'include'}); var t=await r.text(); var d=new DOMParser().parseFromString(t,'text/html');
      return JSON.stringify({status:r.status,url:r.url.replace(location.origin,''),avisos:[...d.querySelectorAll('main .aviso')].map(function(e){return e.textContent.trim()}).filter(Boolean).join(' || ')}) })()`).then(JSON.parse);
    const campos = (taxa, tipo, chave = orig.chave_pix_split, ativo = '1') => ({ ['ativo[' + id + ']']: ativo, ['taxa_split[' + id + ']']: taxa, ['tipo_split[' + id + ']']: tipo, ['chave_pix_split[' + id + ']']: chave });
    const igualOrig = async () => { await abrir(); return JSON.stringify(await lerSplit()) === JSON.stringify(orig); };

    // ---- 1) salvar igual pela tela: mensagem de sucesso aparece
    console.log('\n--- 1) salvar pela tela sem mudar nada');
    await abrir();
    await c.avaliar(`document.getElementById('formAdmin').submit(); 1`); await esp(2500); await c.esperarCarga();
    const av1 = await avisos();
    chk('depois de "Salvar Split" aparece a mensagem de sucesso (antes não aparecia nada)', /salvas com sucesso/.test(av1), av1);
    chk('valores iguais ao original', JSON.stringify(await lerSplit()) === JSON.stringify(orig));
    await abrir();
    chk('a mensagem não fica grudada (some ao recarregar)', !/salvas com sucesso/.test(await avisos()));

    // ---- 2) valores fora da regra: recusados e nada gravado
    console.log('\n--- 2) valores fora da regra (POST direto)');
    const invalidos = [
      ['percentual 150', campos('150', 'percentual'), /não pode passar de 100%/],
      ['percentual 100,01', campos('100.01', 'percentual'), /não pode passar de 100%/],
      ['negativo -5', campos('-5', 'percentual'), /Taxa do split inválida/],
      ['fixo 1000 (virava 999,99)', campos('1000', 'fixo'), /R\$ 100,00/],
      ['fixo 140 (digitou 140 em vez de 1,40)', campos('140', 'fixo'), /R\$ 100,00/],
      ['texto "abc" (virava 0,00)', campos('abc', 'fixo'), /Taxa do split inválida/],
      ['notação 1e3', campos('1e3', 'fixo'), /Taxa do split inválida/],
      ['3 casas decimais 1,234', campos('1,234', 'fixo'), /Taxa do split inválida/],
      ['tipo "hack" (virava percentual)', campos('1', 'hack'), /Tipo de split inválido/],
      ['producerId com espaço', campos('1', 'fixo', 'abc def'), /ID do recebedor/],
      ['producerId com 300 caracteres', campos('1', 'fixo', 'a'.repeat(300)), /ID do recebedor/],
    ];
    for (const [nome, cmp, esperado] of invalidos) {
      await abrir();
      const r = await postar(cmp);
      chk(nome + ': recusado com "Nada foi salvo" e mensagem clara', /Nada foi salvo/.test(r.avisos) && esperado.test(r.avisos), r.avisos);
      chk(nome + ': o banco continua com o valor original', await igualOrig());
    }
    // tudo ou nada: ativação do gateway junto com split inválido
    await abrir();
    let r = await postar(campos('150', 'percentual', orig.chave_pix_split, '0'));
    await abrir();
    const depoisAtomico = await lerSplit();
    chk('tudo ou nada: desligar o gateway + split inválido NÃO desliga o gateway', depoisAtomico.ativo === true && /Nada foi salvo/.test(r.avisos), 'ativo=' + depoisAtomico.ativo + ' | ' + r.avisos.slice(0, 80));
    // id de gateway inexistente junto
    await abrir();
    r = await postar({ ...campos('2', 'fixo'), ['taxa_split[999999]']: '1', ['tipo_split[999999]']: 'fixo', ['chave_pix_split[999999]']: 'x', ['ativo[999999]']: '1' }, [id, 999999]);
    chk('id de gateway inexistente junto: recusado e o split válido do outro NÃO é gravado', /não existe/.test(r.avisos) && /Nada foi salvo/.test(r.avisos) && await igualOrig(), r.avisos);
    // sem lista de gateways
    await abrir();
    r = await postar({}, []);
    chk('sem nenhum gateway na lista: "Nenhum gateway encontrado para salvar."', /Nenhum gateway encontrado/.test(r.avisos), r.avisos);

    // ---- 3) valores válidos continuam funcionando
    console.log('\n--- 3) valores válidos');
    const valido = async (nome, cmp, aceita) => {
      await abrir();
      const rr = await postar(cmp);
      await abrir();
      const g = await lerSplit();
      chk(nome + ': aceito e gravado', aceita(g), JSON.stringify({ taxa: g.taxa_split, tipo: g.tipo_split }) + ' | resposta: ' + rr.avisos.slice(0, 90));
      return caixa();
    };
    let cx = await valido('R$ 1,40 fixo com vírgula', campos('1,40', 'fixo'), g => g.taxa_split === '1.40' && g.tipo_split === 'fixo');
    chk('a caixa de limites passa a dizer R$ 1,40 por venda e mínimo R$ 2,10', /1,40 por venda/.test(cx) && /2,10/.test(cx), cx.slice(cx.indexOf('Taxa da plataforma'), cx.indexOf('Taxa da plataforma') + 130));
    cx = await valido('5% do valor', campos('5', 'percentual'), g => g.taxa_split === '5.00' && g.tipo_split === 'percentual');
    chk('a caixa diz "5% do valor da venda"', /5% do valor da venda/.test(cx));
    await valido('100% (limite do percentual)', campos('100', 'percentual'), g => g.taxa_split === '100.00');
    cx = await caixa();
    chk('com 100% a caixa avisa "inviável (split de 100%)"', /inviável/.test(cx), cx.slice(cx.indexOf('Menor preço'), cx.indexOf('Menor preço') + 80));
    await valido('R$ 100,00 fixo (limite do fixo)', campos('100', 'fixo'), g => g.taxa_split === '100.00' && g.tipo_split === 'fixo');
    await valido('zero (sem split)', campos('0', 'fixo'), g => g.taxa_split === '0.00');
    cx = await caixa();
    chk('com taxa 0 a caixa diz "nenhuma no momento"', /nenhuma no momento/.test(cx));
    await valido('taxa vazia vira 0', campos('', 'fixo'), g => g.taxa_split === '0.00');

    // ---- 4) restaura o original pela tela
    console.log('\n--- 4) restaurar');
    await abrir();
    await c.avaliar(`(function(){var f=document.getElementById('formAdmin');var id=${JSON.stringify(id)};f.elements['taxa_split['+id+']'].value=${JSON.stringify(orig.taxa_split)};f.elements['tipo_split['+id+']'].value=${JSON.stringify(orig.tipo_split)};f.elements['chave_pix_split['+id+']'].value=${JSON.stringify(orig.chave_pix_split)};f.submit();return 1})()`);
    await esp(2500); await c.esperarCarga();
    chk('restaurado ao original pela tela (e com mensagem de sucesso)', JSON.stringify(await lerSplit()) === JSON.stringify(orig) && /salvas com sucesso/.test(await avisos()), mascara(await lerSplit(), orig.chave_pix_split));

    // ---- 5) quem não é admin
    await c.enviar('Network.clearBrowserCookies');
    await entrar(c, U.email, U.senha);
    await c.ir(BASE + '/gateways'); await esp(600);
    const pu = await c.avaliar(`(async function(){var tk=[...document.querySelectorAll('input[type=hidden]')].find(function(i){return /csrf/i.test(i.name)});var fd=new FormData();fd.append(tk.name,tk.value);fd.append('acao','salvar_admin');fd.append('ids[]','${id}');fd.append('ativo[${id}]','1');fd.append('taxa_split[${id}]','99');fd.append('tipo_split[${id}]','percentual');fd.append('chave_pix_split[${id}]','x');var r=await fetch('/gateways',{method:'POST',body:fd,credentials:'include'});return r.status})()`);
    await c.enviar('Network.clearBrowserCookies');
    await entrar(c, process.env.ADMIN_EMAIL, process.env.ADMIN_SENHA);
    chk('usuário comum enviando "salvar_admin" não altera o split', await igualOrig(), 'HTTP ' + pu);
  } catch (e) { console.log('ERRO', e.stack); falha++; }
  finally { try { c.pararDialogo(); } catch (e) {} await c.fechar(); }
  console.log(`\nRESUMO: ${ok} ok, ${falha} falhas` + (orig ? '' : ' (não leu o split original)'));
})();
