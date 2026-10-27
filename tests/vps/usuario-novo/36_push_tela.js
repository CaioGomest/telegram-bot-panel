// Avisos push na VPS — TELAS e front (plano deploy/PLANO-PUSH-NOTIFICACOES.md, F3), com Chrome de verdade:
//   A) avisos DESLIGADOS: cartão em Minha Conta diz "desligado pelo administrador", nada de manifest/ícone/push.js nas páginas, endpoints recusam;
//   B) avisos LIGADOS: <head> (manifest, theme-color, apple-touch-icon), manifest e 5 ícones PNG nos tamanhos certos, sw.js sem cache,
//      ativar/desativar o aparelho, lista com "Este aparelho", interruptores, sincronização diária, convite no sino, sair cancela o aparelho,
//      aviso de teste (e o limite de 1 a cada 10 s), iPhone fora da Tela de Início (guia) e instalado, permissão negada, sem suporte, erro do Brave;
//   C) aba "Notificações" do admin (sessão de admin criada só para o teste), contato inválido/válido, REGENERAR com frase errada, ligar/desligar, celular sem rolagem lateral.
// O navegador de teste não fala com o FCM de verdade: o PushManager é SUBSTITUÍDO por um falso (endereço fcm.googleapis.com fictício + chaves P-256 reais), o resto
// (service worker, push.js, endpoints, banco) é o real. A entrega ao aparelho real fica no roteiro do dono (tests/push/README.md).
// Liga os avisos só durante o teste e DEVOLVE tudo no fim (aparelhos de teste, chaves criadas pelo teste, sessão de admin, push_ativo).
// Uso: VPS_PW=<senha root> node 36_push_tela.js   (captura em estado/push_*.png)
process.env.BASE = 'https://coyotebot.com.br';
const fs = require('fs'), path = require('path'), crypto = require('crypto');
const { execFileSync } = require('child_process');
const PROJ = path.resolve(__dirname, '../../..');
const { lancar, entrar } = require(PROJ + '/tests/paginas/pg_lib.js');
const ESTADO = process.env.ESTADO || path.join(__dirname, 'estado'); fs.mkdirSync(ESTADO, { recursive: true });
const U = JSON.parse(fs.readFileSync(path.join(ESTADO, 'usuario_novo.json'), 'utf8'));
const BASE = 'https://coyotebot.com.br';
const HK = 'SHA256:slsUOXpHdrkbQQOXV7csLOgfAb8BXaNkl/j7o/og3Rg', IP = '179.236.76.180';
const esp = (ms) => new Promise(r => setTimeout(r, ms));
let ok = 0, falha = 0;
const chk = (n, c, x = '') => { c ? ok++ : falha++; console.log((c ? 'OK     ' : 'FALHOU ') + n + (x !== '' ? '  -> ' + String(x).slice(0, 260) : '')); };
const info = (n, x) => console.log('INFO   ' + n + '  -> ' + String(x).slice(0, 260));
if (!process.env.VPS_PW) { console.log('Falta VPS_PW (senha root da VPS).'); process.exit(2); }

// ---- roda push_preparar.php na VPS (como www-data) e devolve o JSON da última linha
function prep(cmd, arg = '') {
    const saida = execFileSync('plink', ['-batch', '-hostkey', HK, '-pw', process.env.VPS_PW, 'root@' + IP, `cd /var/www/painel && sudo -u www-data php -- ${cmd} '${arg}'`],
        { input: fs.readFileSync(path.join(__dirname, 'push_preparar.php')), encoding: 'utf8', maxBuffer: 1 << 24 });
    return JSON.parse(saida.trim().split('\n').pop());
}

// ---- aparelho FALSO: endereço do FCM fictício + chaves P-256 reais (o servidor valida o formato)
function aparelhoFalso() {
    const ecdh = crypto.createECDH('prime256v1'); ecdh.generateKeys();
    return {
        endpoint: 'https://fcm.googleapis.com/fcm/send/TESTE-PUSH-' + crypto.randomBytes(12).toString('hex'),
        p256dh: ecdh.getPublicKey().toString('base64url'),
        auth: crypto.randomBytes(16).toString('base64url'),
    };
}
const hashEndpoint = (e) => crypto.createHash('sha256').update(e).digest('hex');

// Injetado ANTES de qualquer script da página: troca Notification/PushManager por versões falsas que guardam o estado no localStorage.
function stub(fake, opc = {}) {
    return `(function(){
  try {
    ${opc.semSuporte ? "delete window.PushManager; return;" : ''}
    var FAKE = ${JSON.stringify(fake)}, K = '__fake_sub';
    Object.defineProperty(Notification, 'permission', { get: function () { return localStorage.getItem('__fake_perm') || 'default'; }, configurable: true });
    Notification.requestPermission = function () { var p = localStorage.getItem('__fake_perm_resp') || 'granted'; localStorage.setItem('__fake_perm', p); return Promise.resolve(p); };
    function mk(o) { var kb = localStorage.getItem('__fake_keybytes'); var obj = { endpoint: o.endpoint, toJSON: function () { return { endpoint: o.endpoint, expirationTime: null, keys: { p256dh: o.p256dh, auth: o.auth } }; },
      unsubscribe: function () { localStorage.removeItem(K); localStorage.setItem('__fake_unsub', String(1 + parseInt(localStorage.getItem('__fake_unsub') || '0', 10))); return Promise.resolve(true); } };
      if (kb) { obj.options = { applicationServerKey: new Uint8Array(JSON.parse(kb)).buffer }; } return obj; }
    PushManager.prototype.getSubscription = function () { var j = localStorage.getItem(K); return Promise.resolve(j ? mk(JSON.parse(j)) : null); };
    PushManager.prototype.subscribe = function (o) {
      localStorage.setItem('__fake_subscribe_opts', JSON.stringify({ uvo: o && o.userVisibleOnly, len: o && o.applicationServerKey ? o.applicationServerKey.length : -1, b0: o && o.applicationServerKey ? o.applicationServerKey[0] : -1 }));
      var erro = localStorage.getItem('__fake_subscribe_err'); if (erro) { return Promise.reject(new Error(erro)); }
      if (o && o.applicationServerKey) { localStorage.setItem('__fake_keybytes', JSON.stringify(Array.from(o.applicationServerKey))); }
      localStorage.setItem(K, JSON.stringify(FAKE)); return Promise.resolve(mk(FAKE));
    };
  } catch (e) { window.__stub_erro = String(e); }
})();`;
}

const lsGet = (c, k) => c.avaliar(`localStorage.getItem(${JSON.stringify(k)})`);
const lsSet = (c, k, v) => c.avaliar(`localStorage.setItem(${JSON.stringify(k)}, ${JSON.stringify(v)}); 1`);
const estadoVisivel = (c) => c.avaliar(`[...document.querySelectorAll('#push-cartao [data-push-estado]')].filter(function(e){return !e.hidden}).map(function(e){return e.getAttribute('data-push-estado')}).join(',')`);
const esperarEstado = (c, nome, ms = 9000) => c.esperar(`[...document.querySelectorAll('#push-cartao [data-push-estado]')].filter(function(e){return !e.hidden}).map(function(e){return e.getAttribute('data-push-estado')}).join(',')===${JSON.stringify(nome)}`, ms);
const textoCartao = (c) => c.avaliar(`document.getElementById('push-cartao') ? document.getElementById('push-cartao').innerText : ''`);
const semRolagemLateral = (c) => c.avaliar(`document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1`);
const reqs = (c, trecho) => c.eventos.filter(e => e.method === 'Network.requestWillBeSent' && e.params.request.url.includes(trecho)).map(e => e.params.request);
const respostaDe = (c, trecho) => c.eventos.filter(e => e.method === 'Network.responseReceived' && e.params.response.url.includes(trecho)).map(e => e.params.response);

async function novoNavegador(fake, opc = {}) {
    const c = await lancar();
    if (opc.ios) {
        await c.enviar('Emulation.setUserAgentOverride', { userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1', platform: 'iPhone' });
        await c.enviar('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 2, mobile: true });
    } else if (opc.celular) {
        await c.enviar('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 2, mobile: true });
    }
    if (fake) { await c.enviar('Page.addScriptToEvaluateOnNewDocument', { source: stub(fake, opc) }); }
    return c;
}

(async () => {
    const fake = aparelhoFalso();
    let c = null;
    let sidAdmin = '';
    try {
        // ------------------------------------------------------------------ estado inicial
        const cfg0 = prep('config');
        if (cfg0.push_ativo === '1') { console.log('ABORTADO: os avisos já estão LIGADOS na VPS (o teste só roda com eles desligados, para poder devolver tudo como estava).'); process.exit(2); }
        prep('limpar', U.email);
        info('configuração antes', JSON.stringify(cfg0));

        // ================================================================== A) DESLIGADO
        c = await novoNavegador(null);
        await entrar(c, U.email, U.senha);
        await c.ir(BASE + '/configuracao_usuario'); await esp(900);
        chk('A: o cartão "Avisos no celular e no navegador" existe em Minha Conta', await c.avaliar(`!!document.querySelector('#push-cartao') && /Avisos no celular e no navegador/i.test(document.body.innerText)`));
        chk('A: desligado, o cartão mostra só "ainda não estão disponíveis"', (await estadoVisivel(c)) === 'admin-desligado' && /não estão disponíveis/.test(await textoCartao(c)), await estadoVisivel(c));
        chk('A: nenhuma tag de app no <head> (sem manifest, theme-color nem apple-touch-icon)', await c.avaliar(`!document.querySelector('link[rel=manifest]') && !document.querySelector('meta[name=theme-color]') && !document.querySelector('link[rel=apple-touch-icon]')`));
        chk('A: o push.js nem é carregado (nada de convite no sino, nada de gancho no "Sair")', await c.avaliar(`typeof window.PUSH_CONFIG === 'undefined' && typeof window.__pushCarregado === 'undefined' && !document.querySelector('script[src*="push.js"]') && !document.querySelector('.sino-convite-push')`));
        const man0 = await c.avaliar(`fetch('/manifest.webmanifest').then(function(r){return r.status})`);
        chk('A: /manifest.webmanifest responde 404 com os avisos desligados', man0 === 404, man0);
        const ch0 = JSON.parse(await c.avaliar(`fetch('/ajax/push_chave.php',{credentials:'same-origin'}).then(function(r){return r.json()}).then(JSON.stringify)`));
        chk('A: push_chave.php diz ativo=false e não entrega chave', ch0.ativo === false && ch0.chave_publica === '', JSON.stringify(ch0));
        const st409 = await c.avaliar(`fetch('/ajax/push_inscrever.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':window.CSRF_TOKEN},body:JSON.stringify({subscription:{endpoint:'https://fcm.googleapis.com/fcm/send/x',keys:{p256dh:'a',auth:'b'}}})}).then(function(r){return r.status})`);
        chk('A: inscrever aparelho com os avisos desligados é recusado (409)', st409 === 409, st409);
        chk('A: A página não gera erro de JS', c.errosJs().length === 0, c.errosJs().join(' | '));
        await c.fechar(); c = null;

        // ================================================================== B) LIGADO
        const lig = prep('ligar');
        chk('B: avisos ligados no teste, chaves geradas e ícones criados a partir da logo', lig.chaves === true && lig.icones === true, JSON.stringify(lig));
        info('ícones', 'origem=' + lig.origem + ' v=' + lig.versao + (lig.avisos.length ? ' avisos=' + lig.avisos.join(' / ') : ''));

        // ---------- B1: computador (Chrome), fluxo completo
        c = await novoNavegador(fake);
        await entrar(c, U.email, U.senha);
        await c.ir(BASE + '/configuracao_usuario'); await esp(1200);
        chk('B1: com a permissão ainda não pedida, o cartão mostra "Ativar avisos neste aparelho"', await esperarEstado(c, 'ativar') && /Ativar avisos neste aparelho/.test(await textoCartao(c)), await estadoVisivel(c));
        chk('B1: push.js carregado pela barra lateral (PUSH_CONFIG.ativo)', await c.avaliar(`window.PUSH_CONFIG && window.PUSH_CONFIG.ativo === true && window.__pushCarregado === true`));
        // <head>
        const head = JSON.parse(await c.avaliar(`JSON.stringify({ manifest: (document.querySelector('link[rel=manifest]')||{}).href||'', tema: (document.querySelector('meta[name=theme-color]')||{}).content||'',
            apple: (document.querySelector('link[rel=apple-touch-icon]')||{}).href||'', capable: (document.querySelector('meta[name=apple-mobile-web-app-capable]')||{}).content||'', titulo: (document.querySelector('meta[name=apple-mobile-web-app-title]')||{}).content||'' })`));
        chk('B1: <head> com o manifest do app', head.manifest === BASE + '/manifest.webmanifest', head.manifest);
        chk('B1: <head> com theme-color (cor de destaque do sistema)', /^#[0-9a-f]{6}$/i.test(head.tema), head.tema);
        chk('B1: <head> com apple-touch-icon da logo, com a versão (?v=)', /\/uploads\/pwa\/apple-touch-180\.png\?v=[0-9a-f]{8}$/.test(head.apple), head.apple);
        chk('B1: <head> com apple-mobile-web-app-capable e o nome do app', head.capable === 'yes' && head.titulo.length > 0, head.capable + ' / ' + head.titulo);
        // manifest
        const man = JSON.parse(await c.avaliar(`fetch('/manifest.webmanifest').then(function(r){return r.json().then(function(j){return JSON.stringify({status:r.status,tipo:r.headers.get('content-type'),cache:r.headers.get('cache-control'),j:j})})})`));
        chk('B1: manifest com status 200, tipo application/manifest+json e sem cache', man.status === 200 && /application\/manifest\+json/.test(man.tipo) && /no-cache/.test(man.cache), man.tipo + ' ' + man.cache);
        chk('B1: manifest: nome, modo "standalone", início /index, idioma pt-BR', man.j.name && man.j.display === 'standalone' && man.j.start_url === '/index' && man.j.scope === '/' && man.j.lang === 'pt-BR', JSON.stringify({ n: man.j.name, d: man.j.display, s: man.j.start_url }));
        chk('B1: manifest: cor do tema igual à do <head> e fundo escuro', String(man.j.theme_color).toLowerCase() === head.tema.toLowerCase() && man.j.background_color === '#0c0d11', man.j.theme_color);
        chk('B1: manifest: 3 ícones (192, 512 e 512 "maskable")', Array.isArray(man.j.icons) && man.j.icons.length === 3 && man.j.icons.some(i => i.sizes === '192x192') && man.j.icons.filter(i => i.sizes === '512x512').length === 2 && man.j.icons.some(i => i.purpose === 'maskable'), JSON.stringify(man.j.icons));
        // ícones: baixa e mede cada PNG
        const medir = async (url) => JSON.parse(await c.avaliar(`fetch(${JSON.stringify(url)}).then(function(r){var t=r.headers.get('content-type');return r.blob().then(function(b){return createImageBitmap(b).then(function(im){return JSON.stringify({status:r.status,tipo:t,w:im.width,h:im.height,bytes:b.size})})})})`));
        const base = '/uploads/pwa/';
        const q = '?v=' + (head.apple.split('?v=')[1] || '');
        for (const [arq, w] of [['icone-192.png', 192], ['icone-512.png', 512], ['icone-512-maskable.png', 512], ['apple-touch-180.png', 180], ['badge-96.png', 96]]) {
            const m = await medir(BASE + base + arq + q);
            chk('B1: ícone ' + arq + ' é PNG ' + w + '×' + w + ' (' + m.bytes + ' bytes)', m.status === 200 && /image\/png/.test(m.tipo) && m.w === w && m.h === w, JSON.stringify(m));
        }
        // service worker
        const swh = JSON.parse(await c.avaliar(`fetch('/sw.js').then(function(r){return r.text().then(function(t){return JSON.stringify({status:r.status,tipo:r.headers.get('content-type'),cache:r.headers.get('cache-control'),tem:t.indexOf("addEventListener('push'")>-1})})})`));
        chk('B1: sw.js servido como JavaScript, com Cache-Control: no-cache', swh.status === 200 && /javascript/.test(swh.tipo) && /no-cache/.test(swh.cache) && swh.tem, JSON.stringify(swh));
        const ch1 = JSON.parse(await c.avaliar(`fetch('/ajax/push_chave.php',{credentials:'same-origin'}).then(function(r){return r.json()}).then(JSON.stringify)`));
        chk('B1: push_chave.php: ativo, chave pública P-256 (87 caracteres base64url) e token CSRF', ch1.ativo === true && /^[A-Za-z0-9_-]{87}$/.test(ch1.chave_publica) && typeof ch1.csrf === 'string' && ch1.csrf.length >= 16, JSON.stringify(ch1).slice(0, 200));

        // ativar (a permissão e a inscrição só acontecem dentro do clique)
        chk('B1: ANTES do clique não existe inscrição nem pedido de permissão (nada no servidor)', prep('estado', U.email).aparelhos.length === 0 && (await lsGet(c, '__fake_sub')) === null);
        await c.avaliar(`document.getElementById('push-ativar').click(); 1`);
        chk('B1: depois do clique em "Ativar", o cartão passa a "ativo"', await esperarEstado(c, 'ativo', 12000), await estadoVisivel(c));
        const opts = JSON.parse(await lsGet(c, '__fake_subscribe_opts') || '{}');
        chk('B1: subscribe() com userVisibleOnly e a chave pública de 65 bytes (ponto não comprimido 0x04)', opts.uvo === true && opts.len === 65 && opts.b0 === 4, JSON.stringify(opts));
        let est = prep('estado', U.email);
        const ap = est.aparelhos[0] || {};
        chk('B1: o servidor guardou 1 aparelho ativo, com as duas preferências ligadas', est.aparelhos.length === 1 && ap.ativo === 1 && ap.pref_pix_gerado === 1 && ap.pref_pix_pago === 1, JSON.stringify(ap).slice(0, 220));
        chk('B1: o aparelho é identificado pelo hash do endereço e a plataforma é "desktop"', ap.endpoint_hash === hashEndpoint(fake.endpoint) && ap.plataforma === 'desktop', ap.plataforma);
        chk('B1: apelido legível derivado do navegador (ex.: "Windows · Chrome")', typeof ap.apelido === 'string' && / · /.test(ap.apelido), ap.apelido);
        const lista1 = JSON.parse(await c.avaliar(`JSON.stringify([...document.querySelectorAll('#push-dispositivos li.push-dispositivo')].map(function(li){return {este: li.classList.contains('este'), txt: li.innerText.replace(/\\s+/g,' ').trim(), caixas: li.querySelectorAll('input[type=checkbox]').length, botoes: [...li.querySelectorAll('button')].map(function(b){return b.textContent.trim()})}}))`));
        chk('B1: a lista mostra 1 aparelho marcado como "Este aparelho", com 2 interruptores e os botões de teste e remover', lista1.length === 1 && lista1[0].este && /este aparelho/i.test(lista1[0].txt) && lista1[0].caixas === 2 && lista1[0].botoes.join('|') === 'Enviar aviso de teste|Remover', JSON.stringify(lista1).slice(0, 260));
        chk('B1: "visto agora há pouco" no aparelho recém-ativado', /Visto agora há pouco/.test(lista1[0] ? lista1[0].txt : ''), lista1[0] && lista1[0].txt);
        chk('B1: o navegador lembra que este aparelho está inscrito (localStorage push_inscrito=1)', (await lsGet(c, 'push_inscrito')) === '1');
        // preferências
        await c.avaliar(`document.querySelectorAll('#push-dispositivos li input[type=checkbox]')[0].click(); 1`); await esp(1300);
        est = prep('estado', U.email);
        chk('B1: desligar o interruptor "Pix gerado" grava no servidor (gerado=0, pago=1)', est.aparelhos[0].pref_pix_gerado === 0 && est.aparelhos[0].pref_pix_pago === 1, JSON.stringify([est.aparelhos[0].pref_pix_gerado, est.aparelhos[0].pref_pix_pago]));
        await c.avaliar(`document.querySelectorAll('#push-dispositivos li input[type=checkbox]')[0].click(); 1`); await esp(1300);
        est = prep('estado', U.email);
        chk('B1: ligar de novo volta para gerado=1', est.aparelhos[0].pref_pix_gerado === 1);
        await c.captura(path.join(ESTADO, 'push_cartao_desktop.png'));
        // recarregar: continua ativo sem inscrever de novo
        const subsAntes = est.aparelhos.length;
        await c.ir(BASE + '/configuracao_usuario'); await esp(1200);
        chk('B1: recarregar a página mantém o cartão em "ativo" e o aparelho na lista', await esperarEstado(c, 'ativo') && (await c.avaliar(`document.querySelectorAll('#push-dispositivos li.push-dispositivo.este').length`)) === 1);
        chk('B1: recarregar não duplica o aparelho no servidor', prep('estado', U.email).aparelhos.length === subsAntes);
        // sincronização diária (em qualquer página): com o carimbo antigo, reenvia a inscrição
        await lsSet(c, 'push_sync_em', '1000');
        await c.ir(BASE + '/index'); await esp(2500);
        const sync = parseInt(await lsGet(c, 'push_sync_em') || '0', 10);
        chk('B1: sincronização diária: fora de Minha Conta, com o carimbo vencido, o push.js reenvia a inscrição e renova o carimbo', sync > Date.now() - 60000, sync);
        chk('B1: sincronização não duplica o aparelho', prep('estado', U.email).aparelhos.length === 1);
        chk('B1: com o aparelho ativo, o convite no sino NÃO aparece', await c.avaliar(`!document.querySelector('.sino-convite-push')`));
        // desativar
        await c.ir(BASE + '/configuracao_usuario'); await esp(1000);
        await c.avaliar(`document.getElementById('push-desativar').click(); 1`);
        chk('B1: "Desativar neste aparelho" volta o cartão para "ativar"', await esperarEstado(c, 'ativar', 9000));
        chk('B1: desativar cancela a inscrição no navegador (unsubscribe) e remove o aparelho do servidor', (await lsGet(c, '__fake_unsub')) === '1' && prep('estado', U.email).aparelhos.length === 0, await lsGet(c, '__fake_unsub'));
        chk('B1: a lista de aparelhos some do cartão (nenhum ativado)', await c.avaliar(`/Nenhum aparelho ativado/.test(document.getElementById('push-dispositivos').innerText)`));

        // ativar de novo e SAIR da conta: o gancho cancela o aparelho antes de sair
        await c.avaliar(`document.getElementById('push-ativar').click(); 1`);
        chk('B1: ativar de novo funciona', await esperarEstado(c, 'ativo', 12000));
        c.eventos.length = 0;
        await c.avaliar(`document.querySelector('a[href$="logout"]').click(); 1`);
        chk('B1: sair da conta leva ao login', await c.esperar(`location.pathname.indexOf('login') > -1`, 15000), await c.avaliar('location.pathname'));
        const remover = reqs(c, '/ajax/push_remover.php');
        chk('B1: ao clicar em "Sair", o push.js chamou push_remover.php (POST) ANTES de sair', remover.length === 1 && remover[0].method === 'POST', remover.length);
        chk('B1: sair cancelou o aparelho no servidor (esta conta não recebe mais neste navegador)', prep('estado', U.email).aparelhos.length === 0);
        chk('B1: sair também cancelou a inscrição no navegador e limpou a marca local', (await lsGet(c, '__fake_sub')) === null && (await lsGet(c, 'push_inscrito')) === null);

        // convite no sino (conta entrou de novo, sem aparelho)
        await entrar(c, U.email, U.senha);
        await c.ir(BASE + '/index'); await esp(1500);
        const conv = JSON.parse(await c.avaliar(`(function(){var e=document.querySelector('#sino-notificacoes-raiz .sino-painel .sino-convite-push'); return JSON.stringify(e?{txt:e.innerText.replace(/\\s+/g,' ').trim(), href:(e.querySelector('a')||{}).href||'', dentro: e.nextElementSibling && e.nextElementSibling.classList.contains('sino-lista')}:null)})()`));
        chk('B1: sem aparelho ativado, o sino mostra o convite "Receba os avisos de Pix neste aparelho" com link para Minha Conta', conv && /Receba os avisos de Pix/.test(conv.txt) && /configuracao_usuario#avisos$/.test(conv.href), JSON.stringify(conv));
        chk('B1: o convite fica entre o cabeçalho e a lista do sino', conv && conv.dentro === true);
        await c.avaliar(`document.querySelector('.sino-convite-push button').click(); 1`); await esp(300);
        chk('B1: "Agora não" some com o convite e lembra por 30 dias', (await c.avaliar(`!document.querySelector('.sino-convite-push')`)) && parseInt(await lsGet(c, 'push_convite_ate') || '0', 10) > Date.now() + 29 * 864e5);
        await c.ir(BASE + '/vendas'); await esp(1200);
        chk('B1: depois do "Agora não", o convite não volta em outra página', await c.avaliar(`!document.querySelector('.sino-convite-push')`));
        await c.avaliar(`localStorage.removeItem('push_convite_ate'); 1`);

        // aviso de teste
        await c.ir(BASE + '/configuracao_usuario'); await esp(1000);
        await c.avaliar(`document.getElementById('push-ativar').click(); 1`);
        chk('B1: (3ª ativação) cartão "ativo"', await esperarEstado(c, 'ativo', 12000));
        await c.esperar(`document.querySelectorAll('#push-dispositivos li button').length >= 2`, 8000);   // a lista chega logo depois do cartão
        // (a) caminho feliz da TELA: a resposta do servidor é simulada (o envio de verdade fica no roteiro do aparelho real)
        await c.avaliar(`window.__fetchOriginal = window.fetch; window.fetch = function (u, o) { return /push_teste/.test(String(u)) ? Promise.resolve({ json: function () { return Promise.resolve({ sucesso: true, mensagem: 'ok' }); } }) : window.__fetchOriginal(u, o); }; 1`);
        await c.avaliar(`document.querySelectorAll('#push-dispositivos li button')[0].click(); 1`);
        chk('B1: "Enviar aviso de teste": com sucesso, o botão confirma "Enviado ✓" e some o erro', await c.esperar(`/Enviado/.test(document.querySelector('#push-dispositivos li button').textContent) && document.getElementById('push-erro').hidden`, 6000));
        await c.avaliar(`window.fetch = window.__fetchOriginal; 1`);
        await esp(3300);
        // (b) endereço fictício de verdade no FCM real: o FCM responde que o aparelho não existe -> o servidor apaga e a tela volta para "ativar"
        await c.avaliar(`document.querySelectorAll('#push-dispositivos li button')[0].click(); 1`);
        chk('B1: teste num aparelho que o serviço de push desconhece: avisa "não recebe mais avisos", apaga o aparelho e o cartão volta para "ativar"',
            await esperarEstado(c, 'ativar', 15000) && await c.esperar(`/não recebe mais avisos/.test(document.getElementById('push-erro').textContent)`, 5000) && prep('estado', U.email).aparelhos.length === 0,
            await estadoVisivel(c) + ' | ' + await c.avaliar(`document.getElementById('push-erro').textContent`));
        chk('B1: ...e a inscrição local deste navegador também foi cancelada (para ativar de novo com uma limpa)', (await lsGet(c, '__fake_sub')) === null && (await lsGet(c, 'push_inscrito')) === null);
        // (c) limite: outro teste logo em seguida (menos de 10 s) é barrado com 429
        const lim = JSON.parse(await c.avaliar(`fetch('/ajax/push_teste.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':window.CSRF_TOKEN},body:JSON.stringify({id:1})}).then(function(r){return r.json().then(function(j){return JSON.stringify({status:r.status,msg:j.mensagem})})})`));
        chk('B1: outro teste em menos de 10 s é barrado pelo servidor (429 "Aguarde…")', lim.status === 429 && /Aguarde/.test(lim.msg), JSON.stringify(lim));

        // reconciliação: o servidor perdeu o aparelho (ex.: apagado por erro) mas o navegador ainda tem a inscrição -> o push.js reinscreve sozinho
        await c.avaliar(`document.getElementById('push-ativar').click(); 1`);
        chk('B1: ativar de novo (4ª) funciona', await esperarEstado(c, 'ativo', 12000));
        prep('limpar', U.email);
        await c.ir(BASE + '/configuracao_usuario'); await esp(2500);
        chk('B1: servidor sem o aparelho, navegador com a inscrição (mesma chave): o push.js reinscreve sozinho e o cartão segue "ativo"', (await estadoVisivel(c)) === 'ativo' && prep('estado', U.email).aparelhos.length === 1, await estadoVisivel(c));
        // chaves trocadas pelo administrador: a inscrição do navegador é da chave antiga -> some do cartão ("ativar") em vez de ficar 'ativa' sem receber nada
        await c.avaliar(`localStorage.setItem('__fake_keybytes', JSON.stringify(new Array(65).fill(9))); 1`);
        prep('limpar', U.email);
        const unsubAntes = parseInt(await lsGet(c, '__fake_unsub') || '0', 10);
        await c.ir(BASE + '/configuracao_usuario'); await esp(2500);
        chk('B1: inscrição feita com OUTRA chave (administrador regenerou): o cartão volta para "ativar" e a inscrição velha é cancelada', (await estadoVisivel(c)) === 'ativar' && parseInt(await lsGet(c, '__fake_unsub') || '0', 10) === unsubAntes + 1 && (await lsGet(c, '__fake_sub')) === null);
        // ativar com uma inscrição velha (outra chave) ainda no navegador: cancela a velha e inscreve de novo
        await c.avaliar(`localStorage.setItem('__fake_sub', JSON.stringify(${JSON.stringify(fake)})); localStorage.setItem('__fake_keybytes', JSON.stringify(new Array(65).fill(9))); localStorage.setItem('__fake_perm','granted'); 1`);
        const unsubAntes2 = parseInt(await lsGet(c, '__fake_unsub') || '0', 10);
        await c.avaliar(`document.getElementById('push-ativar').click(); 1`);
        chk('B1: ativar com inscrição antiga de outra chave no navegador: cancela a antiga e inscreve com a chave atual', await esperarEstado(c, 'ativo', 12000) && parseInt(await lsGet(c, '__fake_unsub') || '0', 10) === unsubAntes2 + 1 && JSON.parse(await lsGet(c, '__fake_keybytes')).length === 65 && JSON.parse(await lsGet(c, '__fake_keybytes'))[0] === 4);
        chk('B1: sem erros de JS', c.errosJs().length === 0, c.errosJs().join(' | '));
        await c.fechar(); c = null;
        prep('limpar', U.email);

        // ---------- B2: celular, tamanhos e rolagem lateral
        c = await novoNavegador(fake, { celular: true });
        await entrar(c, U.email, U.senha);
        await c.ir(BASE + '/configuracao_usuario#avisos'); await esp(1500);
        await esperarEstado(c, 'ativar');
        await c.avaliar(`document.getElementById('push-ativar').click(); 1`);
        await esperarEstado(c, 'ativo', 12000); await esp(500);
        chk('B2: celular 390 px: o cartão com a lista não cria rolagem lateral', await semRolagemLateral(c));
        const alturas = JSON.parse(await c.avaliar(`JSON.stringify([...document.querySelectorAll('#push-cartao button, #push-cartao .push-interruptor')].filter(function(e){return e.offsetParent !== null}).map(function(e){return Math.round(e.getBoundingClientRect().height)}))`));
        chk('B2: botões e interruptores com altura de toque de pelo menos 40 px (' + alturas.join(',') + ')', alturas.length >= 4 && alturas.every(h => h >= 40), alturas.join(','));
        const ancora = await c.avaliar(`Math.round(document.getElementById('avisos').getBoundingClientRect().top)`);
        chk('B2: o endereço #avisos leva até o cartão (convite do sino)', ancora < 400, ancora);
        await c.captura(path.join(ESTADO, 'push_cartao_celular.png'));
        await c.fechar(); c = null;
        prep('limpar', U.email);

        // ---------- B3: iPhone fora da Tela de Início -> guia; instalado -> ativar
        c = await novoNavegador(fake, { ios: true });
        await entrar(c, U.email, U.senha);
        await c.ir(BASE + '/configuracao_usuario'); await esp(1500);
        chk('B3: iPhone no Safari (fora da Tela de Início): mostra o guia "Adicionar à Tela de Início"', await esperarEstado(c, 'ios-instalar') && /Adicionar à Tela de Início/.test(await textoCartao(c)) && /Compartilhar/.test(await textoCartao(c)) && /Safari/.test(await textoCartao(c)), await estadoVisivel(c));
        chk('B3: o guia tem 4 passos numerados e NÃO oferece o botão de ativar', (await c.avaliar(`document.querySelectorAll('[data-push-estado=ios-instalar] .push-passos li').length`)) === 4 && (await c.avaliar(`document.querySelector('#push-ativar').closest('[data-push-estado]').hidden`)));
        chk('B3: o guia avisa a versão mínima (iOS/iPadOS 16.4)', /16\.4/.test(await textoCartao(c)));
        chk('B3: o guia cabe na tela do iPhone sem rolagem lateral', await semRolagemLateral(c));
        await c.captura(path.join(ESTADO, 'push_cartao_ios_guia.png'));
        chk('B3: o convite do sino no iPhone fora da Tela de Início leva ao guia ("Como ativar")', await (async () => { await c.ir(BASE + '/index'); await esp(1500); return c.avaliar(`/Como ativar/.test((document.querySelector('.sino-convite-push')||{}).innerText||'')`); })());
        // o app aberto pelo ícone da Tela de Início: no Safari do iPhone isso aparece como navigator.standalone === true
        await c.enviar('Page.addScriptToEvaluateOnNewDocument', { source: "Object.defineProperty(Navigator.prototype, 'standalone', { get: function () { return true; }, configurable: true });" });
        await c.ir(BASE + '/configuracao_usuario'); await esp(1500);
        chk('B3: iPhone com o app instalado (modo standalone): volta o botão "Ativar avisos"', await esperarEstado(c, 'ativar'), await estadoVisivel(c));
        await c.avaliar(`document.getElementById('push-ativar').click(); 1`);
        chk('B3: ativar no iPhone instalado funciona', await esperarEstado(c, 'ativo', 12000));
        est = prep('estado', U.email);
        chk('B3: o servidor registrou a plataforma "ios" e o apelido de iPhone', est.aparelhos.length === 1 && est.aparelhos[0].plataforma === 'ios' && /iPhone/.test(est.aparelhos[0].apelido), JSON.stringify([est.aparelhos[0] && est.aparelhos[0].plataforma, est.aparelhos[0] && est.aparelhos[0].apelido]));
        chk('B3: sem erros de JS', c.errosJs().length === 0, c.errosJs().join(' | '));
        await c.fechar(); c = null;
        prep('limpar', U.email);

        // ---------- B4: permissão negada
        c = await novoNavegador(fake);
        await entrar(c, U.email, U.senha);
        await c.ir(BASE + '/configuracao_usuario'); await c.avaliar(`localStorage.setItem('__fake_perm','denied'); 1`);
        await c.ir(BASE + '/configuracao_usuario'); await esp(1200);
        chk('B4: permissão negada: o cartão explica como liberar (e cita o Brave)', await esperarEstado(c, 'negada') && /bloqueados/.test(await textoCartao(c)) && /Brave/.test(await textoCartao(c)), await estadoVisivel(c));
        chk('B4: com a permissão negada não há botão de ativar à vista', await c.avaliar(`document.querySelector('#push-ativar').closest('[data-push-estado]').hidden`));
        // pedir permissão e o usuário negar no diálogo
        await c.avaliar(`localStorage.setItem('__fake_perm','default'); localStorage.setItem('__fake_perm_resp','denied'); 1`);
        await c.ir(BASE + '/configuracao_usuario'); await esp(1200);
        await esperarEstado(c, 'ativar');
        await c.avaliar(`document.getElementById('push-ativar').click(); 1`);
        chk('B4: negar o diálogo de permissão leva o cartão a "negada" e nada é registrado', await esperarEstado(c, 'negada', 9000) && prep('estado', U.email).aparelhos.length === 0, await estadoVisivel(c));
        await c.fechar(); c = null;

        // ---------- B5: erro do serviço de push (Brave sem "serviços do Google")
        c = await novoNavegador(fake);
        await entrar(c, U.email, U.senha);
        await c.ir(BASE + '/configuracao_usuario'); await c.avaliar(`localStorage.setItem('__fake_subscribe_err','Registration failed - push service error'); 1`);
        await c.ir(BASE + '/configuracao_usuario'); await esp(1200);
        await esperarEstado(c, 'ativar');
        await c.avaliar(`document.getElementById('push-ativar').click(); 1`);
        const brave = await c.esperar(`!document.getElementById('push-erro').hidden && /Brave/.test(document.getElementById('push-erro').textContent)`, 9000);
        chk('B5: "push service error" mostra a orientação do Brave e o cartão continua em "ativar"', brave && (await estadoVisivel(c)) === 'ativar', await c.avaliar(`document.getElementById('push-erro').textContent`));
        chk('B5: o botão volta a ficar utilizável (sem travar em "Ativando…")', await c.avaliar(`!document.getElementById('push-ativar').disabled && document.getElementById('push-ativar').textContent.indexOf('Ativando') === -1`));
        chk('B5: nada foi registrado no servidor', prep('estado', U.email).aparelhos.length === 0);
        await c.fechar(); c = null;

        // ---------- B6: navegador sem suporte
        c = await novoNavegador(fake, { semSuporte: true });
        await entrar(c, U.email, U.senha);
        await c.ir(BASE + '/configuracao_usuario'); await esp(1500);
        chk('B6: navegador sem PushManager: o cartão diz que o navegador não recebe avisos', await esperarEstado(c, 'sem-suporte') && /não recebe avisos/.test(await textoCartao(c)), await estadoVisivel(c));
        await c.ir(BASE + '/index'); await esp(1200);
        chk('B6: sem suporte e fora do iPhone: sem convite no sino (não adianta convidar)', await c.avaliar(`!document.querySelector('.sino-convite-push')`));
        chk('B6: sem erros de JS', c.errosJs().length === 0, c.errosJs().join(' | '));
        await c.fechar(); c = null;

        // ================================================================== C) ADMIN
        const sa = prep('sessao_admin');
        sidAdmin = sa.sid;
        chk('C: sessão de admin de teste criada na pasta de sessões do PHP-FPM', !!sidAdmin && sa.arquivo_existe === true);
        c = await novoNavegador(null);
        await c.enviar('Network.setCookie', { name: 'PHPSESSID', value: sidAdmin, domain: 'coyotebot.com.br', path: '/', secure: true, httpOnly: true, sameSite: 'Lax' });
        await c.ir(BASE + '/admin/configuracoes#notificacoes'); await esp(1500);
        const admin = JSON.parse(await c.avaliar(`JSON.stringify({ aba: !!document.querySelector('a[data-aba=notificacoes]'), visivel: (function(){var s=document.getElementById('aba-notificacoes'); return !!s && s.offsetParent !== null})(),
            ligado: !!document.querySelector('#form-push input[name=push_ativo]') && document.querySelector('#form-push input[name=push_ativo]').checked, badge: ((document.querySelector('#aba-notificacoes .badge')||{}).textContent||'').trim(),
            impressao: ((document.getElementById('push-impressao')||{}).textContent||'').trim(), linhasSaude: document.querySelectorAll('.push-saude > div').length,
            icone: (function(){var i=document.querySelector('.push-icones img'); return i ? i.naturalWidth : -1})(), titulo: ((document.querySelector('.push-chaves h2')||{}).textContent||'').trim() })`));
        chk('C: a aba "Notificações" existe e abre pelo endereço #notificacoes', admin.aba && admin.visivel, JSON.stringify(admin));
        chk('C: o interruptor geral aparece LIGADO e o selo diz "Ligado"', admin.ligado && admin.badge === 'Ligado', admin.badge);
        chk('C: mostra a impressão digital da chave (12 caracteres hex) e a "Saúde do envio" com 9 linhas', /^[0-9a-f]{12}$/.test(admin.impressao) && admin.linhasSaude === 9, admin.impressao + ' / ' + admin.linhasSaude);
        chk('C: mostra o ícone gerado da logo (imagem carregada) e a origem', admin.icone > 0 && /Ícone dos avisos/.test(admin.titulo) && /logo do sistema/.test(await c.avaliar(`document.querySelector('.push-icones p').innerText`)), admin.icone);
        chk('C: a chave privada NÃO aparece em lugar nenhum da página', await c.avaliar(`!/BEGIN (EC )?PRIVATE KEY/.test(document.documentElement.outerHTML)`));
        // contato inválido
        const salvarPush = async (contato, ligar) => {
            await c.ir(BASE + '/admin/configuracoes#notificacoes'); await esp(900);
            await c.avaliar(`(function(){var f=document.getElementById('form-push'); f.elements['push_contato'].value=${JSON.stringify(contato)}; f.elements['push_ativo'].checked=${ligar ? 'true' : 'false'}; f.setAttribute('novalidate','');f.submit(); return 1})()`);
            await esp(2600); await c.esperarCarga();
            return { url: await c.avaliar('location.pathname + location.search + location.hash'), msg: await c.avaliar(`[...document.querySelectorAll('#aba-notificacoes .aviso')].map(function(e){return e.textContent.trim()}).join(' || ')`) };
        };
        let r = await salvarPush('abc', true);
        chk('C: contato inválido ("abc") é recusado com explicação e não grava', /Contato inválido/.test(r.msg) && prep('config').push_contato === '', r.msg);
        r = await salvarPush('mailto:teste@exemplo.com.br', true);
        chk('C: contato válido (mailto:) é salvo e a página volta com salvo_push=1', /salvo_push=1/.test(r.url) && prep('config').push_contato === 'mailto:teste@exemplo.com.br', r.url);
        // regenerar com frase errada: nada muda
        const imp0 = admin.impressao;
        await c.ir(BASE + '/admin/configuracoes#notificacoes'); await esp(900);
        await c.avaliar(`(function(){var f=document.querySelector('.push-regenerar form'); f.elements['confirmacao'].value='quero'; f.submit(); return 1})()`);
        await esp(2600); await c.esperarCarga();
        const apos = JSON.parse(await c.avaliar(`JSON.stringify({msg:[...document.querySelectorAll('#aba-notificacoes .aviso')].map(function(e){return e.textContent.trim()}).join(' || '), imp:((document.getElementById('push-impressao')||{}).textContent||'').trim()})`));
        chk('C: "Regenerar chaves" com frase errada pede REGENERAR e NÃO muda a chave', /REGENERAR/.test(apos.msg) && apos.imp === imp0, apos.msg);
        // desligar e ligar
        r = await salvarPush('', false);
        chk('C: desligar o interruptor e salvar: avisos desligados no banco e selo "Desligado"', prep('config').push_ativo === '0' && /salvo_push=1/.test(r.url), r.url);
        const man1 = await c.avaliar(`fetch('/manifest.webmanifest').then(function(r){return r.status})`);
        chk('C: desligado, o manifest volta a 404', man1 === 404, man1);
        r = await salvarPush('', true);
        chk('C: ligar de novo e salvar: push_ativo=1', prep('config').push_ativo === '1');
        await c.captura(path.join(ESTADO, 'push_admin_desktop.png'));
        await c.enviar('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 2, mobile: true });
        await c.ir(BASE + '/admin/configuracoes#notificacoes'); await esp(1300);
        chk('C: celular 390 px: a aba Notificações não cria rolagem lateral', await semRolagemLateral(c));
        await c.captura(path.join(ESTADO, 'push_admin_celular.png'));
        chk('C: sem erros de JS no admin', c.errosJs().length === 0, c.errosJs().join(' | '));
        await c.fechar(); c = null;
    } catch (e) {
        console.log('ERRO', e.stack); falha++;
    } finally {
        try { if (c) { await c.fechar(); } } catch (e) { /* já fechado */ }
        // devolve tudo como estava
        try {
            prep('limpar', U.email);
            const d = prep('desligar');
            const fim = prep('config');
            chk('FIM: avisos desligados de novo, sessão de teste apagada e chaves do teste removidas', fim.push_ativo !== '1' && d.sessoes_apagadas >= (sidAdmin ? 1 : 0), JSON.stringify(d) + ' ' + JSON.stringify(fim));
        } catch (e) { console.log('ERRO ao devolver o estado — rode na VPS: sudo -u www-data php -- desligar < push_preparar.php (ver README)', e.message); falha++; }
        console.log(`\nRESUMO: ${ok} ok, ${falha} falhas`);
        process.exitCode = falha ? 1 : 0;
    }
})();
