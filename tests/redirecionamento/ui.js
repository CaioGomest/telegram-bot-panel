// Tela do editor do Redirecionamento (Proteção + Segmentação) num Chrome de verdade.
// SEM servidor, banco nem login: usa o markup REAL de redirecionamento.php (trechos PHP removidos), o CSS e o JS verdadeiros, e um $.ajax simulado
// que se comporta como api.php (opções, lista, obter, salvar). Precisa de internet só para o jQuery da CDN (como a página de verdade).
//   node tests/redirecionamento/ui.js      (sai com 1 se algo falhar; capturas em %TEMP%/rd_ui/*.png)
const fs = require('fs');
const os = require('os');
const { lancar } = require('../homologacao/ui/cdp.js');

const RAIZ = require('path').resolve(__dirname, '../..').replace(/\\/g, '/');
const SAIDA = (os.tmpdir() + '/rd_ui').replace(/\\/g, '/');
fs.mkdirSync(SAIDA, { recursive: true });

const PAISES = [['BR', 'Brasil'], ['US', 'Estados Unidos'], ['PT', 'Portugal'], ['ES', 'Espanha'], ['MX', 'México'], ['AR', 'Argentina'], ['CO', 'Colômbia'], ['CL', 'Chile'],
    ['PE', 'Peru'], ['FR', 'França'], ['DE', 'Alemanha'], ['IT', 'Itália'], ['GB', 'Reino Unido'], ['JP', 'Japão'], ['IN', 'Índia']].map(([codigo, nome]) => ({ codigo, nome }));

const SERVIDOR = `
window.__salvos = [];
window.__links = [{ id: 7, titulo: 'Campanha antiga', slug: 'antiga', url: 'https://painel.teste/l/antiga', ativo: 1, plataforma: 'meta', protecao: 'cloaker_v2', modo: 'aleatorio',
  bloqueio_tipo: 'redirecionar', bloqueio_url: 'https://exemplo.com.br', bloquear_bots: 1, anti_compartilhamento: 1, filtrar_pais: 1, paises: ['BR', 'PT'], validar_idioma: 1,
  bots: [1], cliques: 120, starts: 80, leads: 70, vendas_qtd: 5, vendas_valor: 250, qtd_destinos: 1, cliques_7d: 40, bloqueados: 33 }];
function resposta(opts) {
  var u = new URL(opts.url, location.href), acao = u.searchParams.get('action');
  if (acao === 'opcoes_redirecionamento') return { sucesso: true, bots: [{ id: 1, nome: 'Bot A', usuario: 'bot_a', fluxo: 'Fluxo 1' }], dominios: [],
    plataformas: { meta: { nome: 'Meta Ads', query: 'utm_source=facebook', onde: 'x' }, tiktok: { nome: 'TikTok Ads', query: 'utm_source=tiktok', onde: 'x' },
      google: { nome: 'Google Ads', query: 'utm_source=google', onde: 'x' }, outra: { nome: 'Outra origem', query: '', onde: 'x' } },
    paises: ${JSON.stringify(PAISES)}, host: 'painel.teste', esquema: 'https', base: '' };
  if (acao === 'listar_redirecionadores') return { sucesso: true, links: window.__links };
  if (acao === 'obter_redirecionador') return { sucesso: true, link: window.__links[0] };
  if (acao === 'salvar_redirecionador') { window.__salvos.push(JSON.parse(opts.data)); return { sucesso: true, mensagem: 'Link salvo.' }; }
  return { sucesso: false, mensagem: 'ação desconhecida' };
}
jQuery.ajax = function (opts) {
  var d = jQuery.Deferred(), r = resposta(opts);
  setTimeout(function () { if (r.sucesso) { if (opts.success) opts.success(r); d.resolve(r); } else { d.reject({ responseJSON: r }); } }, 20);
  return d.promise();
};
window.CSRF_TOKEN = 'tok-ui';
`;

let html = fs.readFileSync(RAIZ + '/redirecionamento.php', 'utf8')
    .replace(/<\?php[\s\S]*?\?>/g, '')
    .replace('<html lang="pt-BR">', '<html lang="pt-BR" class="layout-a" data-theme="dark"><base href="file:///' + RAIZ + '/">')
    .replace('<link rel="stylesheet" href="assets/css/painel.css?v=">', '<link rel="stylesheet" href="assets/css/painel.css"><link rel="stylesheet" href="assets/css/layout_a.css">')
    .replace('<script src="assets/js/tema.js?v="></script>', '')
    .replace('<script src="assets/redirecionamento.js?v="></script>', '<script>' + SERVIDOR + '</script><script src="assets/redirecionamento.js"></script>');

let ok = 0, falhas = 0;
const chk = (n, c, e) => { c ? ok++ : falhas++; console.log((c ? 'OK     ' : 'FALHOU ') + n + (c || e === undefined ? '' : '  -> ' + JSON.stringify(e))); };
const esp = (ms) => new Promise(r => setTimeout(r, ms));

(async () => {
    fs.writeFileSync(SAIDA + '/index.html', html);
    const c = await lancar();
    const av = (expr) => c.avaliar(expr);
    const J = (expr) => av(`JSON.stringify(${expr})`).then(JSON.parse);
    const clicar = (sel) => av(`document.querySelector(${JSON.stringify(sel)}).click(); 1`);
    const visivel = (sel) => av(`(function(){var e=document.querySelector(${JSON.stringify(sel)});return !!e && !e.hidden && e.getClientRects().length>0;})()`);
    const texto = (sel) => av(`document.querySelector(${JSON.stringify(sel)}).textContent`);
    const abrirPagina = async (w, h) => {
        await c.enviar('Emulation.setDeviceMetricsOverride', { width: w, height: h, deviceScaleFactor: 1, mobile: w < 500 });
        await c.ir('file:///' + SAIDA + '/index.html');
        await c.esperar(`document.querySelectorAll('#rd-lista tr').length > 0`, 10000);
    };
    try {
        await abrirPagina(1440, 900);
        chk('a lista mostra "33 bloqueados" no link com proteção', /33 bloqueados/.test(await texto('#rd-lista')));

        // ---------------- novo link
        await clicar('#rd-btn-novo');
        await esp(200);
        chk('editor abre', await av(`document.getElementById('rd-editor').classList.contains('aberto')`));
        chk('menu lateral tem Segmentação', (await av(`[].map.call(document.querySelectorAll('#rd-nav a'),function(a){return a.textContent}).join('|')`)).includes('Segmentação'));
        chk('3 cartões de proteção; "Sem proteção" marcado', (await av(`document.querySelectorAll('#rd-protecoes .rd-cartao').length`)) === 3
            && await av(`document.querySelector('#rd-protecoes .rd-cartao.ativo').dataset.protecao`) === 'nenhuma');
        chk('Cloaker V2 mostra o selo "Avançado"', /Avançado/.test(await texto('#rd-protecoes [data-protecao="cloaker_v2"]')));
        chk('sem proteção: os dois interruptores do cloaker ficam escondidos', !(await visivel('#rd-opcoes-cloaker')));
        chk('jornada: "Todos os cliques..." sem proteção', /Sem filtro/.test(await texto('#rd-j-protecao')));
        chk('jornada: segmentação sem filtro', /Sem filtro de país/.test(await texto('#rd-j-segmentacao')));
        chk('lista de países escondida; 15 países montados', !(await visivel('#rd-bloco-paises')) && (await av(`document.querySelectorAll('#rd-paises .rd-pais').length`)) === 15);

        await clicar('#rd-protecoes [data-protecao="cloaker"]');
        chk('Cloaker: aparecem os interruptores, "Bloquear bots" ligado e anti desligado',
            await visivel('#rd-opcoes-cloaker') && await av(`document.getElementById('rd-bloquear-bots').checked`) && !(await av(`document.getElementById('rd-anti-compartilhamento').checked`)));
        chk('jornada descreve o Cloaker + página segura', /Cloaker \+ AntiClone: Barra robôs de anúncio\. Tráfego bloqueado vê a página segura/.test(await texto('#rd-j-protecao')), await texto('#rd-j-protecao'));
        await av(`(function(){var e=document.getElementById('rd-anti-compartilhamento');e.checked=true;e.dispatchEvent(new Event('change',{bubbles:true}))})()`);
        chk('jornada cita links compartilhados', /links compartilhados/.test(await texto('#rd-j-protecao')));
        await clicar('#rd-protecoes [data-protecao="cloaker_v2"]');
        chk('V2 cita navegador automatizado', /navegador automatizado/.test(await texto('#rd-j-protecao')));

        // resposta ao bloqueio
        chk('campo de URL escondido com "Página segura"', !(await visivel('#rd-campo-bloqueio-url')));
        await clicar('#rd-bloqueios [data-bloqueio="redirecionar"]');
        chk('"Redirecionar" mostra o campo de URL', await visivel('#rd-campo-bloqueio-url'));
        await av(`(function(){var e=document.getElementById('rd-bloqueio-url');e.value='https://www.exemplo.com.br';e.dispatchEvent(new Event('input',{bubbles:true}))})()`);
        chk('jornada cita a URL', /www\.exemplo\.com\.br/.test(await texto('#rd-j-protecao')));

        // "Outra origem" desliga o anti-compartilhamento
        await clicar('#rd-plataformas [data-plataforma="outra"]');
        chk('"Outra origem": anti-compartilhamento desabilitado com explicação', await av(`document.getElementById('rd-anti-compartilhamento').disabled`) && /Indisponível/.test(await texto('#rd-anti-texto')));
        await clicar('#rd-plataformas [data-plataforma="meta"]');
        chk('volta ao Meta: habilita de novo', !(await av(`document.getElementById('rd-anti-compartilhamento').disabled`)));

        // segmentação
        await av(`(function(){var e=document.getElementById('rd-filtrar-pais');e.checked=true;e.dispatchEvent(new Event('change',{bubbles:true}))})()`);
        chk('ligar o filtro mostra a lista e já marca o Brasil', await visivel('#rd-bloco-paises') && await av(`document.querySelector('#rd-paises input[value="BR"]').checked`));
        chk('jornada: "Só passa quem estiver em Brasil"', /Só passa quem estiver em Brasil/.test(await texto('#rd-j-segmentacao')), await texto('#rd-j-segmentacao'));
        await av(`['US','PT','ES','MX'].forEach(function(c){var e=document.querySelector('#rd-paises input[value="'+c+'"]');e.checked=true;e.dispatchEvent(new Event('change',{bubbles:true}))})`);
        chk('jornada abrevia: "Brasil, Estados Unidos, Portugal e mais 2"', /Brasil, Estados Unidos, Portugal e mais 2/.test(await texto('#rd-j-segmentacao')), await texto('#rd-j-segmentacao'));
        await av(`(function(){var e=document.getElementById('rd-validar-idioma');e.checked=true;e.dispatchEvent(new Event('change',{bubbles:true}))})()`);
        chk('jornada cita o idioma', /idioma do navegador conferido/.test(await texto('#rd-j-segmentacao')));
        await c.captura(SAIDA + '/desktop_1440.png');

        // validações do salvar
        await av(`document.getElementById('rd-titulo').value='Meu link';document.getElementById('rd-titulo').dispatchEvent(new Event('input',{bubbles:true}))`);
        await av(`(function(){var e=document.querySelector('#rd-bots input');e.checked=true;e.dispatchEvent(new Event('change',{bubbles:true}))})()`);
        await av(`(function(){var e=document.getElementById('rd-bloqueio-url');e.value='javascript:alert(1)';e.dispatchEvent(new Event('input',{bubbles:true}))})()`);
        await clicar('#rd-salvar'); await esp(200);
        chk('URL de bloqueio inválida: avisa e NÃO envia', (await av(`window.__salvos.length`)) === 0 && /URL válida/.test(await texto('#toast')), await texto('#toast'));
        await av(`(function(){var e=document.getElementById('rd-bloqueio-url');e.value='https://www.exemplo.com.br';e.dispatchEvent(new Event('input',{bubbles:true}))})()`);
        await av(`['BR','US','PT','ES','MX'].forEach(function(c){var e=document.querySelector('#rd-paises input[value="'+c+'"]');e.checked=false;e.dispatchEvent(new Event('change',{bubbles:true}))})`);
        await clicar('#rd-salvar'); await esp(200);
        chk('filtro por país sem nenhum país: avisa e NÃO envia', (await av(`window.__salvos.length`)) === 0 && /ao menos um país/.test(await texto('#toast')), await texto('#toast'));
        await av(`['BR','PT'].forEach(function(c){var e=document.querySelector('#rd-paises input[value="'+c+'"]');e.checked=true;e.dispatchEvent(new Event('change',{bubbles:true}))})`);
        await clicar('#rd-salvar'); await esp(300);
        const s = (await J(`window.__salvos`))[0] || {};
        chk('salvar envia protecao, bloqueio, bots, anti, país e idioma',
            s.protecao === 'cloaker_v2' && s.bloqueio_tipo === 'redirecionar' && s.bloqueio_url === 'https://www.exemplo.com.br' && s.bloquear_bots === 1
            && s.anti_compartilhamento === 1 && s.filtrar_pais === 1 && JSON.stringify(s.paises) === '["BR","PT"]' && s.validar_idioma === 1, s);
        chk('depois de salvar o editor fecha', !(await av(`document.getElementById('rd-editor').classList.contains('aberto')`)));

        // ---------------- editar link existente
        await clicar('.rd-editar'); await esp(400);
        chk('editar carrega a proteção salva (V2) e os países (BR, PT)',
            await av(`document.querySelector('#rd-protecoes .rd-cartao.ativo').dataset.protecao`) === 'cloaker_v2'
            && await av(`[].filter.call(document.querySelectorAll('#rd-paises input'),function(i){return i.checked}).map(function(i){return i.value}).join()`) === 'BR,PT');
        chk('editar carrega "Redirecionar" com a URL, anti e idioma ligados',
            await av(`document.querySelector('#rd-bloqueios .rd-cartao.ativo').dataset.bloqueio`) === 'redirecionar'
            && await av(`document.getElementById('rd-bloqueio-url').value`) === 'https://exemplo.com.br'
            && await av(`document.getElementById('rd-anti-compartilhamento').checked && document.getElementById('rd-validar-idioma').checked && document.getElementById('rd-filtrar-pais').checked`));
        await c.captura(SAIDA + '/editar_1440.png');
        await av(`document.getElementById('rd-sec-segmentacao').scrollIntoView()`);
        await esp(200);
        await c.captura(SAIDA + '/segmentacao_1440.png');
        chk('sem erros de JavaScript no desktop', (await c.errosJs()).length === 0, c.errosJs());

        // ---------------- celular
        await abrirPagina(390, 800);
        await clicar('#rd-btn-novo'); await esp(200);
        await clicar('#rd-protecoes [data-protecao="cloaker"]');
        await av(`(function(){var e=document.getElementById('rd-filtrar-pais');e.checked=true;e.dispatchEvent(new Event('change',{bubbles:true}))})()`);
        chk('celular: sem rolagem horizontal', await av(`document.documentElement.scrollWidth <= innerWidth + 1 && document.getElementById('rd-form-rolagem').scrollWidth <= document.getElementById('rd-form-rolagem').clientWidth + 1`));
        chk('celular: cartões de proteção empilhados (1 coluna)', await av(`(function(){var a=document.querySelectorAll('#rd-protecoes .rd-cartao');return a[0].getBoundingClientRect().left===a[1].getBoundingClientRect().left})()`));
        chk('celular: países em 2 colunas, dentro da tela', await av(`(function(){var a=document.querySelectorAll('#rd-paises .rd-pais');return a[0].getBoundingClientRect().top===a[1].getBoundingClientRect().top && a[1].getBoundingClientRect().right<=innerWidth})()`));
        await av(`document.getElementById('rd-sec-protecao').scrollIntoView()`);
        await esp(200);
        await c.captura(SAIDA + '/celular_390.png');
    } finally {
        console.log(`\n== resultado: ${ok} ok, ${falhas} falha(s)  (capturas em ${SAIDA})`);
        await c.fechar();
    }
    process.exit(falhas ? 1 : 0);
})().catch((e) => { console.error('ERRO: ' + e.message); process.exit(2); });
