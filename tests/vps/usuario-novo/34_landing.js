// Landing page pública (/landing, 20/10/2026): carrega sem erro, sem rolagem lateral (desktop e celular), mascote e imagens carregam, todos os links levam a algum lugar
// (âncoras existem; /cadastro, /login e /termos respondem), abas de recursos funcionam com mouse e teclado, vendas "ao vivo" andam, e não depende de login.
// Se a landing estiver ESCONDIDA (LANDING_PUBLICA = false), confere só que /landing é um 404 igual aos outros. Só leitura. Uso: node 34_landing.js   (capturas em estado/landing_<largura>.png, de cima a baixo)
process.env.BASE = 'https://coyotebot.com.br';
const fs = require('fs'), path = require('path');
const PROJ = path.resolve(__dirname, '../../..');
const { lancar } = require(PROJ + '/tests/paginas/pg_lib.js');
const ESTADO = process.env.ESTADO || path.join(__dirname, 'estado'); fs.mkdirSync(ESTADO, { recursive: true });
const BASE = 'https://coyotebot.com.br';
const esp = (ms) => new Promise(r => setTimeout(r, ms));
let ok = 0, falha = 0;
const chk = (n, c, x = '') => { c ? ok++ : falha++; console.log((c ? 'OK     ' : 'FALHOU ') + n + (x ? '  -> ' + String(x).slice(0, 240) : '')); };
(async () => {
  // Enquanto LANDING_PUBLICA = false em landing.php (escondida até o cliente aprovar), a página tem que parecer inexistente: o teste confere isso e para por aqui.
  const resp = await fetch(BASE + '/landing');
  if (resp.status === 404) {
    const html = await resp.text();
    const inex = await fetch(BASE + '/nao-existe-' + Date.now());
    const htmlInex = await inex.text();
    console.log('--- landing ESCONDIDA (LANDING_PUBLICA = false)');
    chk('/landing responde 404, como uma página que não existe', resp.status === 404 && inex.status === 404);
    chk('o corpo não tem nada da landing (nem o título, nem o mascote, nem o menu)', !/Venda no Telegram|Capacidade testada|coyote-animado|Criar minha conta/.test(html), html.slice(0, 120));
    chk('a resposta é a mesma página 404 das outras rotas (mesmo tamanho e título)', html.length === htmlInex.length && /Página não encontrada/.test(html), html.length + ' x ' + htmlInex.length);
    const lp = await fetch(BASE + '/landing.php');
    chk('/landing.php também termina em 404', lp.status === 404, String(lp.status));
    const home = await (await fetch(BASE + '/login')).text();
    chk('a página de login não tem link para a landing', !/href="\/?landing/.test(home));
    console.log('\nPara testar a página em si: mudar LANDING_PUBLICA para true em landing.php e publicar.');
    console.log(`\nRESUMO: ${ok} ok, ${falha} falhas`);
    process.exit(falha ? 1 : 0);
  }
  const c = await lancar();
  try {
    for (const [larg, alt, mobile] of [[1360, 900, false], [390, 844, true]]) {
      console.log('\n--- ' + larg + 'px');
      await c.enviar('Emulation.setDeviceMetricsOverride', { width: larg, height: alt, deviceScaleFactor: mobile ? 2 : 1, mobile });
      await c.ir(BASE + '/landing'); await esp(3200);
      const r = JSON.parse(await c.avaliar(`(function(){
        var d = document.documentElement;
        var imgs = [].map.call(document.images, function(i){ return {src: i.getAttribute('src'), ok: i.complete && i.naturalWidth > 0, lazy: i.loading === 'lazy'}; });
        var ancoras = [].map.call(document.querySelectorAll('a[href^="#"]'), function(a){ var id = a.getAttribute('href').slice(1); return {id: id, existe: id === 'top' || !!document.getElementById(id)}; });
        var externos = [].map.call(document.querySelectorAll('a:not([href^="#"])'), function(a){ return a.getAttribute('href'); });
        var h1 = document.querySelectorAll('h1');
        return JSON.stringify({
          titulo: document.title, rolagemH: d.scrollWidth > d.clientWidth + 1, largura: d.scrollWidth, h1: h1.length, h1txt: h1[0] ? h1[0].innerText : '',
          imgs: imgs, ancoras: ancoras, externos: externos, fonte: getComputedStyle(document.querySelector('h1')).fontFamily,
          altura: d.scrollHeight, semAlt: [].filter.call(document.images, function(i){ return !i.hasAttribute('alt'); }).length,
          botoesSemNome: [].filter.call(document.querySelectorAll('a,button'), function(e){ return !(e.innerText || e.getAttribute('aria-label') || '').trim(); }).length,
          menorAlvo: Math.min.apply(null, [].map.call(document.querySelectorAll('.btn, .tab'), function(e){ var b = e.getBoundingClientRect(); return b.height; }))
        });
      })()`));
      chk('título da aba: "' + r.titulo + '"', /Venda no Telegram no automático/.test(r.titulo));
      chk('sem rolagem lateral (largura ' + r.largura + 'px)', !r.rolagemH);
      chk('um único H1 e com o texto do herói', r.h1 === 1 && /Venda no Telegram no\s+automático/i.test(r.h1txt), r.h1txt);
      chk('as imagens carregaram (mascote do topo, do nav e do fim)', r.imgs.length >= 3 && r.imgs.every(i => i.ok || i.lazy), JSON.stringify(r.imgs.map(i => i.ok)));
      chk('todas as âncoras (#editor, #como, #recursos, #mobile, #seguranca, #top…) apontam para uma seção que existe', r.ancoras.length >= 10 && r.ancoras.every(a => a.existe), JSON.stringify(r.ancoras.filter(a => !a.existe)));
      chk('os links de saída são só cadastro, login e termos', r.externos.every(h => /^(cadastro|login|termos)$/.test(h)) && r.externos.indexOf('cadastro') >= 0, JSON.stringify([...new Set(r.externos)]));
      chk('fonte dos títulos é a Sora (carregou do Google Fonts)', /Sora/.test(r.fonte), r.fonte);
      chk('imagens todas com atributo alt, links e botões todos com nome acessível', r.semAlt === 0 && r.botoesSemNome === 0, 'semAlt=' + r.semAlt + ' semNome=' + r.botoesSemNome);
      chk('alvos de toque (botões e abas) com pelo menos 44 px de altura', r.menorAlvo >= 43.5, String(r.menorAlvo));

      // abas de recursos
      const aba = () => c.avaliar(`document.getElementById('aba-titulo').innerText + ' | ' + document.querySelector('[role=tab][aria-selected=true]').innerText + ' | tags=' + document.querySelectorAll('#aba-tags .tag').length`);
      chk('aba 1 (Fluxo Guiado) começa selecionada', /Monte o funil preenchendo um formulário \| Fluxo Guiado \| tags=2/.test(await aba()), await aba());
      await c.avaliar(`document.getElementById('aba-1').click(); 1`); await esp(300);
      chk('clicar em "Blocos de oferta" troca título e as 4 etiquetas', /Aumente o ticket médio em cada venda \| Blocos de oferta \| tags=4/.test(await aba()), await aba());
      await c.avaliar(`document.getElementById('aba-1').focus(); 1`);
      await c.enviar('Input.dispatchKeyEvent', { type: 'keyDown', key: 'ArrowRight', code: 'ArrowRight', windowsVirtualKeyCode: 39 });
      await c.enviar('Input.dispatchKeyEvent', { type: 'keyUp', key: 'ArrowRight', code: 'ArrowRight', windowsVirtualKeyCode: 39 }); await esp(300);
      chk('seta → no teclado vai para "Ranking" e o foco acompanha', /Gamifique a venda com placar ao vivo \| Ranking/.test(await aba()) && (await c.avaliar(`document.activeElement.id`)) === 'aba-2', await aba());
      chk('só uma aba fica com tabindex 0 (navegação por setas)', (await c.avaliar(`document.querySelectorAll('[role=tab][tabindex="0"]').length`)) === 1);
      await c.avaliar(`document.getElementById('aba-0').click(); 1`);

      // vendas ao vivo
      const n1 = Number(await c.avaliar(`document.getElementById('vendas-num').innerText`)); await esp(6200);
      const n2 = Number(await c.avaliar(`document.getElementById('vendas-num').innerText`));
      chk('o contador de vendas "ao vivo · exemplo" anda sozinho (' + n1 + ' → ' + n2 + ') e a lista tem sempre 3 linhas', n2 > n1 && (await c.avaliar(`document.querySelectorAll('#feed .row').length`)) === 3);

      // captura de cima a baixo
      const altura = await c.avaliar(`document.documentElement.scrollHeight`);
      await c.enviar('Emulation.setDeviceMetricsOverride', { width: larg, height: Math.min(altura, 7000), deviceScaleFactor: 1, mobile });
      await esp(1500);
      await c.captura(path.join(ESTADO, 'landing_' + larg + '.png'));
      await c.enviar('Emulation.setDeviceMetricsOverride', { width: larg, height: alt, deviceScaleFactor: mobile ? 2 : 1, mobile });
    }

    console.log('\n--- movimento reduzido e pagamento da fonte');
    await c.enviar('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'reduce' }] });
    await c.ir(BASE + '/landing'); await esp(1500);
    chk('com "reduzir movimento" o mascote e os olhos já aparecem (nada fica invisível esperando animação)', (await c.avaliar(`getComputedStyle(document.querySelector('.eye.l')).opacity`)) === '1' && (await c.avaliar(`getComputedStyle(document.querySelector('.stage .logo')).animationName`)) === 'none');
    await c.enviar('Emulation.setEmulatedMedia', { features: [] });

    console.log('\n--- links de saída respondem');
    for (const rota of ['/cadastro', '/login', '/termos']) {
      const s = await c.avaliar(`fetch(${JSON.stringify(BASE + rota)}).then(function(r){return r.status})`);
      chk(rota + ' responde 200', s === 200, String(s));
    }
    chk('erros de JS', c.errosJs().length === 0, c.errosJs().join(' | '));
  } catch (e) { console.log('ERRO', e.stack); falha++; }
  finally { await c.fechar(); }
  console.log(`\nRESUMO: ${ok} ok, ${falha} falhas`);
})();
