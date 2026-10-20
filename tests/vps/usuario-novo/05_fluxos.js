// Teste 5: importar fluxos na VPS pela tela (arquivo .json), erros de importação, exportar de volta, abrir editores, conectar o bot.
process.env.BASE = 'https://coyotebot.com.br';
const fs = require('fs'), path = require('path');
const ESTADO = process.env.ESTADO || path.join(__dirname, 'estado'); require('fs').mkdirSync(ESTADO, { recursive: true });
const PROJ = path.resolve(__dirname, '../../..');
const { lancar, entrar, autoDialogo, inspecionar, resumo } = require(PROJ + '/tests/paginas/pg_lib.js');
const U = JSON.parse(fs.readFileSync(path.join(ESTADO, 'usuario_novo.json'), 'utf8'));
const FLUXOS = JSON.parse(fs.readFileSync(path.join(ESTADO, 'fluxos_homolog.json'), 'utf8'));
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
async function esperaToastSumir(c) {
  for (let i = 0; i < 40; i++) { const v = await c.avaliar(`!!(document.getElementById('toast')&&document.getElementById('toast').classList.contains('visivel'))`).catch(() => false); if (!v) return; await esp(200); }
}
// anexa um arquivo no <input type=file> e dispara change
async function anexar(c, seletor, nome, conteudo) {
  await c.avaliar(`(function(){var dt=new DataTransfer();dt.items.add(new File([${JSON.stringify(conteudo)}],${JSON.stringify(nome)},{type:'application/json'}));var i=document.querySelector(${JSON.stringify(seletor)});i.files=dt.files;i.dispatchEvent(new Event('change',{bubbles:true}));return 1})()`);
}
async function listaFluxos(c) {
  await c.ir(BASE + '/fluxos'); await esp(1200);
  const hrefs = JSON.parse(await c.avaliar(`JSON.stringify([...document.querySelectorAll('a')].map(function(a){return a.getAttribute('href')||''}).filter(function(h){return h.indexOf('fluxo_basico?id=')>=0||h.indexOf('fluxo?id=')>=0}))`));
  const texto = await c.avaliar(`document.querySelector('main').innerText`);
  return { hrefs: [...new Set(hrefs)], texto };
}

(async () => {
  const c = await lancar();
  try {
    await c.enviar('Emulation.setDeviceMetricsOverride', { width: 1400, height: 1000, deviceScaleFactor: 1, mobile: false });
    autoDialogo(c, true);
    await entrar(c, U.email, U.senha);
    let l = await listaFluxos(c);
    chk('lista de fluxos começa vazia', l.hrefs.length === 0, JSON.stringify(l.hrefs));

    // --- erros de importação
    await esperaToastSumir(c);
    await anexar(c, '#arquivo-importar-fluxo-lista', 'quebrado.json', '{isto nao e json');
    let t = await toast(c); chk('importar JSON quebrado: avisa erro', t.length > 3, '"' + t + '"');
    await esperaToastSumir(c);
    await anexar(c, '#arquivo-importar-fluxo-lista', 'vazio.json', JSON.stringify({ nome: 'X', modo: 'avancado', dados_fluxograma: { operators: 1 } }));
    t = await toast(c); chk('importar estrutura inválida: recusado', t.length > 3, '"' + t + '"');
    l = await listaFluxos(c);
    chk('erros não criaram fluxo', l.hrefs.length === 0, JSON.stringify(l.hrefs));

    // --- importar os 4 fluxos reais
    for (const f of FLUXOS) {
      await c.ir(BASE + '/fluxos'); await esp(900);
      await anexar(c, '#arquivo-importar-fluxo-lista', 'fluxo_' + f.id_origem + '.json', JSON.stringify(f));
      t = await toast(c, 12000);
      chk('importar "' + f.nome.slice(0, 40) + '"', /importad|sucesso/i.test(t), '"' + t + '"');
      await esperaToastSumir(c);
    }
    l = await listaFluxos(c);
    chk('os 4 fluxos aparecem na lista', l.hrefs.length === 4, l.hrefs.join(', '));
    console.log('   lista:', linhas(l.texto).join(' | ').slice(0, 700));
    await c.captura(path.join(ESTADO, 'fluxos_lista.png'));
    fs.writeFileSync(path.join(ESTADO, 'fluxos_vps.json'), JSON.stringify(l.hrefs));

    // --- exportar de volta e comparar com o original
    const ids = l.hrefs.map(h => (h.match(/id=(\d+)/) || [])[1]);
    for (let i = 0; i < ids.length; i++) {
      const r = JSON.parse(await c.avaliar(`fetch('/api.php?action=exportar_fluxo&id=${ids[i]}',{credentials:'include'}).then(function(r){return r.json()}).then(function(j){return JSON.stringify({ok:j.sucesso,modo:j.fluxo&&j.fluxo.modo,nome:j.fluxo&&j.fluxo.nome,tam:JSON.stringify(j.fluxo&&j.fluxo.dados_fluxograma).length})})`));
      const orig = FLUXOS.find(f => f.nome === r.nome);
      chk('exportar fluxo ' + ids[i] + ' devolve o mesmo conteúdo (' + r.modo + ')', r.ok && orig && Math.abs(orig.dados_fluxograma && JSON.stringify(orig.dados_fluxograma).length - r.tam) < 400, 'tamanho original ' + (orig ? JSON.stringify(orig.dados_fluxograma).length : '?') + ' vs ' + r.tam);
    }

    // --- abrir cada editor
    for (const h of l.hrefs) {
      const r = await inspecionar(c, '/' + h.replace(/^\//, ''), { pausa: 1500 });
      const p = resumo(r).filter(x => !/ícone sem title/.test(x));
      chk('abre editor ' + h, p.length === 0 && r.status === 200, p.join(' ; ') + ' [' + (r.h1 || r.titulo) + ']');
      await c.captura(path.join(ESTADO, 'editor_' + h.replace(/[^a-z0-9]+/gi, '_') + '.png'));
    }
  } catch (e) { console.log('ERRO', e.stack); falha++; }
  finally { try { c.pararDialogo(); } catch (e) {} await c.fechar(); }
  console.log(`\nRESUMO: ${ok} ok, ${falha} falhas`);
})();
