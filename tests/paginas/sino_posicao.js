// Painel do sino de notificações: tem de abrir inteiro dentro da janela, com a borda direita alinhada à do sino, em janela larga, tela cheia, ultrawide e celular.
// Regressão de 23/10/2026: no Layout A a barra de cima tem `backdrop-filter`, que vira o bloco de contenção do `position: fixed` do painel; o JS calculava a
// posição pela janela e o painel caía 272 px (a largura da barra lateral) à direita, cortado na borda ("precisa puxar para o lado").
// Chrome real, SEM servidor, banco nem login: monta páginas mínimas com o HTML do cabeçalho copiado das páginas reais (parciais/shell_a.php e barra_lateral.php),
// o CSS (painel.css, layout_a.css, push.css) e o JS (notificacoes.js) de verdade. Três casos: Layout A, casca antiga no desktop (.topo-painel) e casca antiga no
// celular (o sino cai no .acoes-cabecalho da página).
//   node tests/paginas/sino_posicao.js                       (sai com código 1 se algo falhar)
//   JS_SINO=C:/caminho/notificacoes.js node tests/paginas/sino_posicao.js   (roda com outro JS, ex.: uma cópia baixada do site no ar, para reproduzir o defeito)
const fs = require('fs');
const os = require('os');
const RAIZ = require('path').resolve(__dirname, '../..').replace(/\\/g, '/');
const { lancar } = require(RAIZ + '/tests/homologacao/ui/cdp.js');
const JS_SINO = process.env.JS_SINO ? 'file:///' + process.env.JS_SINO.replace(/\\/g, '/').replace(/^\/+/, '') : 'assets/js/notificacoes.js';

const ICONE = '<svg class="ic" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 2v20M17 6H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>';
const SOL = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M2 12h2M20 12h2"></path></svg>';

// o markup do sino é o de parciais/sino_notificacoes.php; fica no fim do <body> e o JS o leva para a barra de cima
const SINO = `<div id="sino-notificacoes-raiz" class="sino-notificacoes-wrap" hidden data-url-lista="lista.json" data-url-ler="ler.json">
  <button type="button" class="sino-notificacoes" aria-label="Notificações" aria-expanded="false" aria-haspopup="true">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
    <span class="sino-badge" hidden>0</span>
  </button>
  <div class="sino-painel" hidden>
    <div class="sino-painel-cabecalho"><h3>Notificações</h3><span class="sino-ao-vivo"><span class="ponto-vivo"></span> AO VIVO</span></div>
    <div class="sino-lista"></div>
    <button type="button" class="sino-marcar-todas">Marcar todas como lidas</button>
  </div>
</div>
<script>
window.fetch = function () { return Promise.resolve({ json: function () { return Promise.resolve({ sucesso: true, nao_lidas: 1, itens: [{ id: 1, tipo: 'lead', titulo: 'Novo Lead', descricao: 'rf iniciou conversa', tempo: '13h', lido: false }] }); } }); };
</script>
<script src="${JS_SINO}"></script>`;

const MONTAR_PAGINA = (classe_html, corpo) => `<!DOCTYPE html>
<html lang="pt-BR" class="${classe_html}" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<base href="file:///${RAIZ}/">
<link rel="stylesheet" href="assets/css/painel.css">
${classe_html ? '<link rel="stylesheet" href="assets/css/layout_a.css">' : ''}
<link rel="stylesheet" href="assets/css/push.css">
</head>
<body style="background:#0b0c10;color:#eee">
${corpo}
${SINO}
</body>
</html>`;

// Layout A (parciais/shell_a.php): trilho + barra de cima do desktop (.a-top) + barra de cima do celular (.a-mtop)
const BOTAO_TEMA_A = `<button type="button" class="a-ib alternador-tema" aria-label="Alternar tema" title="Alternar tema">${SOL}</button>`;
const PAGINA_LAYOUT_A = MONTAR_PAGINA('layout-a', `<aside class="a-rail"></aside>
<header class="a-top">
  <div class="a-hi"><b>Boa noite, <em>Caio</em></b><span>sábado, 25 de outubro</span></div>
  <div class="a-meta" title="Faturamento total até hoje"><span class="a-meta-ic">${ICONE}</span><div class="a-meta-c"><div class="a-meta-l"><span>FATURAMENTO</span><span>0%</span></div><div class="a-bar"><i style="width:0%"></i></div><div class="a-meta-l"><strong>R$ 0,00</strong><span>/ R$ 10 mil</span></div></div></div>
  <div class="a-acts-top topo-acoes">
    <a class="a-ib" href="#" target="_blank" rel="noopener" title="Comunidade" aria-label="Comunidade (nova aba)">${ICONE}</a>
    ${BOTAO_TEMA_A}
  </div>
</header>
<header class="a-mtop">
  <a class="a-logo" href="#" aria-label="Início"></a>
  <div class="a-mt"><b>Início</b><span>25 out</span></div>
  <div class="a-acts-top topo-acoes">${BOTAO_TEMA_A}</div>
</header>
<main style="margin-left:var(--a-barra);height:2000px;padding:40px">conteúdo</main>`);

// Casca antiga (barra_lateral.php + páginas dos editores): .topo-painel no desktop; no celular ele some e o sino cai no .acoes-cabecalho da página
const PAGINA_CASCA_ANTIGA = MONTAR_PAGINA('', `<div class="layout-painel">
<nav class="barra-lateral"><div class="barra-lateral-interna"></div></nav>
<main class="conteudo-principal">
  <header class="topo-painel">
    <div class="topo-faturamento" title="Faturamento total até hoje"><span class="topo-faturamento-icone">${ICONE}</span><div class="topo-faturamento-corpo"><div class="topo-faturamento-linha"><span>FATURAMENTO</span><span>0%</span></div><div class="topo-faturamento-barra"><i style="width:0%"></i></div><div class="topo-faturamento-linha"><strong>R$ 0,00</strong><span>/ R$ 10 mil</span></div></div></div>
    <div class="topo-saudacao"><strong>Boa noite, <em>Caio</em></strong><span>sábado, 25 de outubro</span></div>
    <div class="topo-acoes">
      <a href="#" target="_blank" rel="noopener" class="topo-botao" title="Comunidade" aria-label="Comunidade">${ICONE}</a>
      <button type="button" class="alternador-tema topo-botao" aria-label="Alternar tema" title="Alternar tema">${SOL}<span>Tema</span></button>
    </div>
  </header>
  <div class="cabecalho-pagina"><div><h1>Bots</h1><p>Seus bots</p></div><div class="acoes-cabecalho"><button type="button" class="alternador-tema" aria-label="Alternar tema">${SOL}<span>Tema</span></button></div></div>
  <div style="height:2000px"></div>
</main>
</div>`);

const CASOS = [
    { nome: 'Layout A', html: PAGINA_LAYOUT_A, janelas: [[1600, 900, 'janela 1600'], [1280, 800, 'janela 1280'], [1920, 1080, 'tela cheia 1920'], [2560, 1300, 'ultrawide 2560'], [1000, 800, 'janela estreita 1000'], [390, 800, 'celular 390']], redimensionar: true },
    // a barra de cima é sticky: com a página rolada ela fica no topo e o painel tem de continuar ao lado do sino
    { nome: 'Layout A, página rolada', html: PAGINA_LAYOUT_A, janelas: [[1600, 900, 'janela 1600'], [390, 800, 'celular 390']], rolar: 600 },
    { nome: 'casca antiga, desktop', html: PAGINA_CASCA_ANTIGA, janelas: [[1600, 900, 'janela 1600'], [1280, 800, 'janela 1280'], [900, 800, 'janela estreita 900']] },
    { nome: 'casca antiga, celular', html: PAGINA_CASCA_ANTIGA, janelas: [[390, 800, 'celular 390'], [768, 900, 'tablet 768']] },
    // janela baixa com a lista cheia: o painel tem de caber na altura e o rodapé "Marcar todas como lidas" tem de continuar visível (29/10/2026: ficava cortado)
    { nome: 'casca antiga, janela baixa com lista cheia', html: PAGINA_CASCA_ANTIGA, janelas: [[1280, 520, 'janela 1280x520'], [390, 600, 'celular 390x600']], encher: true },
];

let ok = 0, falhas = 0;
const chk = (n, c, e) => { c ? ok++ : falhas++; console.log((c ? 'OK     ' : 'FALHOU ') + n + (c || e === undefined ? '' : '  -> ' + JSON.stringify(e))); };
const esp = (ms) => new Promise(r => setTimeout(r, ms));

(async () => {
    const pasta = (os.tmpdir() + '/sino_posicao').replace(/\\/g, '/');
    fs.mkdirSync(pasta, { recursive: true });
    const c = await lancar();
    try {
        for (const caso of CASOS) {
            fs.writeFileSync(pasta + '/index.html', caso.html);
            for (const [largura, altura, rotulo] of caso.janelas) {
                const nome = caso.nome + ' / ' + rotulo;
                await c.enviar('Emulation.setDeviceMetricsOverride', { width: largura, height: altura, deviceScaleFactor: 1, mobile: largura < 500 });
                await c.ir('file:///' + pasta + '/index.html');
                await esp(500);
                if (caso.rolar) await c.avaliar(`window.scrollTo(0, ${caso.rolar})`);
                await esp(100);
                const larg_antes = Number(await c.avaliar(`document.documentElement.scrollWidth`));
                if (caso.encher) {
                    // enche a lista com 12 itens antes de abrir (o painel mede a altura na hora de abrir)
                    await c.avaliar(`(function(){ var l = document.querySelector('.sino-lista'); for (var i = 0; i < 12; i++) { var d = document.createElement('div'); d.className = 'item-atividade'; d.textContent = 'Pix gerado ' + i; l.appendChild(d); } })()`);
                }
                await c.avaliar(`document.querySelector('.sino-notificacoes').click()`);
                await esp(300);
                const rod = JSON.parse(await c.avaliar(`JSON.stringify((function(){ var r = document.querySelector('.sino-marcar-todas').getBoundingClientRect(); return { baixo: Math.round(r.bottom), altura: window.innerHeight, itens: document.querySelectorAll('.sino-lista > *').length }; })())`));
                chk(nome + ': o rodapé "Marcar todas como lidas" fica inteiro dentro da janela', rod.baixo <= rod.altura, rod);
                if (caso.encher) chk(nome + ': a lista cheia está no painel (12 itens)', rod.itens >= 12, rod);
                const m = JSON.parse(await c.avaliar(`JSON.stringify((function(){ var b = document.querySelector('.sino-notificacoes').getBoundingClientRect(), p = document.querySelector('.sino-painel').getBoundingClientRect(); return { sino_dir: Math.round(b.right), sino_baixo: Math.round(b.bottom), p_esq: Math.round(p.left), p_dir: Math.round(p.right), p_topo: Math.round(p.top), p_larg: Math.round(p.width), janela: window.innerWidth, sino_visivel: b.width > 0, pagina_larg: document.documentElement.scrollWidth, rolado: Math.round(window.scrollY) }; })())`));
                chk(nome + ': o sino está visível na barra de cima', m.sino_visivel, m);
                if (caso.rolar) chk(nome + ': a página estava mesmo rolada quando o sino abriu', m.rolado > 0, m);
                chk(nome + ': abrir o painel não faz a página rolar para o lado ("precisa puxar para o lado")', m.pagina_larg <= larg_antes, { antes: larg_antes, depois: m.pagina_larg });
                chk(nome + ': o painel cabe inteiro na janela (esq >= 0, dir <= largura)', m.p_esq >= 0 && m.p_dir <= m.janela, m);
                chk(nome + ': a borda direita do painel fica alinhada com a do sino (ou encostada na margem de 16 px)', Math.abs(m.p_dir - m.sino_dir) <= 2 || (m.p_dir >= m.janela - 17 && m.p_dir <= m.janela), m);
                chk(nome + ': o painel abre logo abaixo do sino (8 px)', Math.abs(m.p_topo - (m.sino_baixo + 8)) <= 2, m);
                if (caso.redimensionar && largura === 1600) {
                    // redimensionar com o painel aberto: reposiciona e continua inteiro
                    await c.enviar('Emulation.setDeviceMetricsOverride', { width: 1100, height: 800, deviceScaleFactor: 1, mobile: false });
                    await esp(300);
                    const r = JSON.parse(await c.avaliar(`JSON.stringify((function(){ var p = document.querySelector('.sino-painel').getBoundingClientRect(); return { esq: Math.round(p.left), dir: Math.round(p.right), janela: window.innerWidth }; })())`));
                    chk(nome + ' reduzida para 1100 com o painel aberto: ele se reposiciona e continua inteiro', r.esq >= 0 && r.dir <= r.janela, r);
                }
                await c.avaliar(`document.querySelector('.sino-notificacoes').click()`);
                await esp(100);
            }
        }
        chk('sem erro de JavaScript', c.errosJs().length === 0, c.errosJs());
    } finally { await c.fechar(); }
    console.log('\n' + ok + ' ok, ' + falhas + ' falha(s)');
    process.exit(falhas ? 1 : 0);
})();
