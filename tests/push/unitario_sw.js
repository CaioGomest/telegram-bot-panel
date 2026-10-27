// Teste do service worker dos avisos (sw.js) SEM navegador: carrega o arquivo num sandbox do Node com um "self" falso e dispara os eventos.
// Confere: instala/ativa, o aviso SEMPRE aparece (mesmo com conteúdo ilegível: o iOS revoga a inscrição se chegar push sem notificação), ícone/badge com a versão,
// tag/renotify, clique (foca aba do painel, abre janela, nunca sai da pasta do painel) e renovação da inscrição (pushsubscriptionchange).
// Uso: node tests/push/unitario_sw.js
const vm = require('vm');
const fs = require('fs');
const path = require('path');

const CODIGO = fs.readFileSync(path.join(__dirname, '../../sw.js'), 'utf8');
let ok = 0, falha = 0;
const chk = (n, c, x = '') => { c ? ok++ : falha++; console.log((c ? 'OK     ' : 'FALHOU ') + n + (x ? '  -> ' + String(x).slice(0, 240) : '')); };

function criar(opc = {}) {
    const escopo = opc.escopo || 'https://painel.exemplo.com.br/';
    const ouvintes = {};
    const reg = {
        scope: escopo,
        mostradas: [],
        showNotification(titulo, opcoes) { reg.mostradas.push({ titulo, opcoes }); return Promise.resolve(); },
        pushManager: { chamadas: [], subscribe(o) { reg.pushManager.chamadas.push(o); return Promise.resolve({ toJSON: () => ({ endpoint: 'https://fcm.googleapis.com/fcm/send/NOVO', keys: { p256dh: 'P', auth: 'A' } }) }); } },
    };
    const clients = {
        claimed: false, abertas: [], lista: opc.abas || [],
        claim() { clients.claimed = true; return Promise.resolve(); },
        matchAll() { return Promise.resolve(clients.lista); },
        openWindow(u) { clients.abertas.push(u); return Promise.resolve({}); },
    };
    const requisicoes = [];
    const sw = {
        registration: reg, clients, skipped: false,
        skipWaiting() { sw.skipped = true; },
        addEventListener(nome, fn) { ouvintes[nome] = fn; },
    };
    const fetchFalso = (url, o) => {
        requisicoes.push({ url, o });
        const resp = opc.respostas && opc.respostas[url.replace(escopo, '')];
        if (resp === 'erro') { return Promise.reject(new Error('rede')); }
        return Promise.resolve({ json: () => Promise.resolve(resp || {}) });
    };
    vm.runInNewContext(CODIGO, { self: sw, URL, atob, fetch: fetchFalso, encodeURIComponent, Uint8Array, JSON, Date, Promise, String });
    // dispara um evento e espera o que o service worker pediu para aguardar (waitUntil)
    async function disparar(nome, extra = {}) {
        let espera = Promise.resolve();
        const evento = Object.assign({ waitUntil(p) { espera = p; } }, extra);
        ouvintes[nome](evento);
        await espera;
        return evento;
    }
    return { sw, reg, clients, requisicoes, ouvintes, disparar };
}
const dado = (obj) => ({ json: () => obj });

(async () => {
    // ----- instalar/ativar
    let s = criar();
    await s.disparar('install');
    chk('instalar: ativa na hora (skipWaiting)', s.sw.skipped === true);
    await s.disparar('activate');
    chk('ativar: assume as abas abertas (clients.claim)', s.clients.claimed === true);
    chk('só escuta os eventos esperados (sem "fetch": nunca serve página velha)', Object.keys(s.ouvintes).sort().join(',') === 'activate,install,notificationclick,push,pushsubscriptionchange', Object.keys(s.ouvintes).join(','));

    // ----- push: conteúdo completo
    s = criar({ escopo: 'https://painel.exemplo.com.br/' });
    await s.disparar('push', { data: dado({ k: 'pp', t: 'Pix pago · R$ 49,90', b: 'Curso X · @minhaloja_bot', g: 'pp-123', u: '/vendas', ts: 1760900000000, n: 1, v: 'ab12cd34' }) });
    let n = s.reg.mostradas[0];
    chk('push: mostra o título e o corpo do aviso', n && n.titulo === 'Pix pago · R$ 49,90' && n.opcoes.body === 'Curso X · @minhaloja_bot', JSON.stringify(n));
    chk('push: ícone e badge vêm de uploads/pwa/ com a versão da logo (?v=)', n.opcoes.icon === 'https://painel.exemplo.com.br/uploads/pwa/icone-192.png?v=ab12cd34' && n.opcoes.badge === 'https://painel.exemplo.com.br/uploads/pwa/badge-96.png?v=ab12cd34', n.opcoes.icon + ' ' + n.opcoes.badge);
    chk('push: a tag é a do servidor (um aviso por venda) e o horário é o do evento', n.opcoes.tag === 'pp-123' && n.opcoes.timestamp === 1760900000000);
    chk('push: aviso individual não renotifica; guarda o destino para o clique', n.opcoes.renotify === false && n.opcoes.data.u === '/vendas');

    // ----- push: resumos renotificam
    for (const k of ['rg', 'rp']) {
        s = criar();
        await s.disparar('push', { data: dado({ k, t: 'x', b: 'y', g: k === 'rg' ? 'pg-resumo' : 'pp-resumo', u: '/vendas', ts: 1, n: 5, v: '' }) });
        chk('push resumo "' + k + '": renotify ligado (a rajada toca de novo no mesmo aviso)', s.reg.mostradas[0].opcoes.renotify === true && s.reg.mostradas[0].opcoes.tag.endsWith('resumo'));
    }

    // ----- push: sem versão do ícone não põe "?v="
    s = criar();
    await s.disparar('push', { data: dado({ k: 'pg', t: 'Pix gerado · R$ 10,00', b: 'Aguardando pagamento', g: 'pg-1', u: '/vendas', ts: 1, n: 1, v: '' }) });
    chk('push sem versão: o ícone vem sem "?v="', s.reg.mostradas[0].opcoes.icon === 'https://painel.exemplo.com.br/uploads/pwa/icone-192.png');

    // ----- push: SEMPRE mostra algo
    s = criar();
    await s.disparar('push', { data: { json() { throw new Error('json inválido'); } } });
    chk('push com conteúdo ilegível: mostra o aviso genérico (nunca fica sem notificação)', s.reg.mostradas.length === 1 && s.reg.mostradas[0].titulo === 'Nova atividade no painel' && s.reg.mostradas[0].opcoes.body === 'Abra o painel para ver.', JSON.stringify(s.reg.mostradas));
    s = criar();
    await s.disparar('push', {});
    chk('push sem conteúdo nenhum: mostra o aviso genérico', s.reg.mostradas.length === 1 && s.reg.mostradas[0].titulo === 'Nova atividade no painel');
    s = criar();
    await s.disparar('push', { data: dado({}) });
    chk('push com JSON vazio: mostra o aviso genérico com o destino padrão (/vendas)', s.reg.mostradas.length === 1 && s.reg.mostradas[0].opcoes.data.u === '/vendas' && s.reg.mostradas[0].opcoes.tag === 'painel');

    // ----- clique
    const aba = (url) => { const a = { url, focada: false, navegou: null, focus() { a.focada = true; return Promise.resolve(a); }, navigate(u) { a.navegou = u; return Promise.resolve(a); } }; return a; };
    let a1 = aba('https://painel.exemplo.com.br/bots');
    s = criar({ abas: [aba('https://outro-site.com/x'), a1] });
    let fechou = false;
    await s.disparar('notificationclick', { notification: { close() { fechou = true; }, data: { u: '/vendas' } } });
    chk('clique: fecha o aviso', fechou);
    chk('clique: foca a aba do painel e vai para a página do aviso (ignora abas de outros sites)', a1.focada && a1.navegou === 'https://painel.exemplo.com.br/vendas' && s.clients.abertas.length === 0, a1.navegou);
    s = criar({ abas: [] });
    await s.disparar('notificationclick', { notification: { close() {}, data: { u: '/vendas' } } });
    chk('clique sem aba aberta: abre uma janela nova na página do aviso', s.clients.abertas.length === 1 && s.clients.abertas[0] === 'https://painel.exemplo.com.br/vendas', s.clients.abertas.join());
    s = criar({ abas: [] });
    await s.disparar('notificationclick', { notification: { close() {}, data: null } });
    chk('clique em aviso sem destino: abre /vendas', s.clients.abertas[0] === 'https://painel.exemplo.com.br/vendas');
    s = criar({ escopo: 'https://exemplo.com.br/painel/', abas: [] });
    await s.disparar('notificationclick', { notification: { close() {}, data: { u: '/vendas' } } });
    chk('painel numa subpasta: o destino respeita a pasta (…/painel/vendas)', s.clients.abertas[0] === 'https://exemplo.com.br/painel/vendas', s.clients.abertas[0]);
    s = criar({ abas: [] });
    await s.disparar('notificationclick', { notification: { close() {}, data: { u: 'https://golpe.com/x' } } });
    chk('clique com destino de outro site: continua dentro do painel (abre /vendas)', s.clients.abertas.length === 1 && s.clients.abertas[0] === 'https://painel.exemplo.com.br/vendas', s.clients.abertas[0]);

    // ----- pushsubscriptionchange
    const chavePublica = Buffer.alloc(65, 7).toString('base64url');
    s = criar({ respostas: { 'ajax/push_chave.php': { sucesso: true, ativo: true, chave_publica: chavePublica, csrf: 'TOKEN123' } } });
    await s.disparar('pushsubscriptionchange', {});
    const post = s.requisicoes.find(r => r.url.endsWith('ajax/push_inscrever.php'));
    chk('renovação: busca a chave pública e reinscreve com userVisibleOnly', s.reg.pushManager.chamadas.length === 1 && s.reg.pushManager.chamadas[0].userVisibleOnly === true && s.reg.pushManager.chamadas[0].applicationServerKey.length === 65);
    chk('renovação: reenvia a inscrição nova ao servidor com o token CSRF e "renovacao"', !!post && post.o.method === 'POST' && post.o.headers['X-CSRF-Token'] === 'TOKEN123' && JSON.parse(post.o.body).renovacao === true && JSON.parse(post.o.body).subscription.endpoint.endsWith('/NOVO'), post && post.o.body);
    chk('renovação: o POST leva o cookie de sessão (credentials same-origin)', !!post && post.o.credentials === 'same-origin');
    s = criar({ respostas: { 'ajax/push_chave.php': { sucesso: true, ativo: false, chave_publica: '' } } });
    await s.disparar('pushsubscriptionchange', {});
    chk('renovação com os avisos desligados: não inscreve nem envia nada', s.reg.pushManager.chamadas.length === 0 && !s.requisicoes.some(r => r.url.endsWith('push_inscrever.php')));
    s = criar({ respostas: { 'ajax/push_chave.php': 'erro' } });
    let quebrou = false;
    try { await s.disparar('pushsubscriptionchange', {}); } catch (e) { quebrou = true; }
    chk('renovação sem rede/sessão: termina em silêncio (o push.js refaz na próxima abertura do painel)', !quebrou && s.reg.pushManager.chamadas.length === 0);

    console.log(`\nRESUMO: ${ok} ok, ${falha} falhas`);
    process.exitCode = falha ? 1 : 0;
})();
