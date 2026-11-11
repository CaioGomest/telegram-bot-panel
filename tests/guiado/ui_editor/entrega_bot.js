// Entrega "Bot/Taxa" no Fluxo Guiado (07/11/2026, pedido do dono, igual à Shark): o campo Entrega ganha "Bot/Taxa", que mostra o aviso e o select "Fluxo de destino"
// (fluxos da conta com o @ do bot de cada um). Chrome real, sem servidor.
//   node tests/guiado/ui_editor/entrega_bot.js      (sai com código 1 se algo falhar)
const fs = require('fs');
const os = require('os');
const { spawnSync } = require('child_process');
const RAIZ = require('path').resolve(__dirname, '../../..').replace(/\\/g, '/');
const { lancar } = require(RAIZ + '/tests/homologacao/ui/cdp.js');
const SAIDA = (os.tmpdir() + '/editor_guiado_entrega_bot').replace(/\\/g, '/');
fs.mkdirSync(SAIDA, { recursive: true });
const g = spawnSync('php', [__dirname + '/gerar.php', SAIDA], { encoding: 'utf8' });
if (g.status !== 0) { console.log('gerar.php falhou:\n' + g.stdout + g.stderr); process.exit(1); }
const URL = 'file:///' + SAIDA + '/index.html';

let ok = 0, falhas = 0;
function chk(nome, cond, extra) {
    cond ? ok++ : falhas++;
    console.log((cond ? 'OK     ' : 'FALHOU ') + nome + (cond || extra === undefined ? '' : '  -> ' + (typeof extra === 'string' ? extra : JSON.stringify(extra))));
}
const esp = (ms) => new Promise(r => setTimeout(r, ms));
const GRUPOS = [{ id_telegram: '-1001111111111', titulo: 'VIP A', nome_bot: 'bot_um' }];
const FLUXOS = [{ id: 11, nome: 'TAXA 1', bot: 'taxa1_bot' }, { id: 12, nome: 'TAXA 2', bot: '' }];
const OPCAO_MENSAGEM = '💬 Mensagem (texto enviado ao cliente)';

(async () => {
    const c = await lancar();
    const js = (e) => c.avaliar(e);
    const q = (s) => `jQuery(${JSON.stringify(s)})`;
    async function abrir(fluxo) {
        await c.enviar('Emulation.setDeviceMetricsOverride', { width: 1500, height: 1100, deviceScaleFactor: 1, mobile: false });
        await c.enviar('Page.addScriptToEvaluateOnNewDocument', { source: `window.__fluxo_pre = ${JSON.stringify(fluxo || null)}; window.__grupos = ${JSON.stringify(GRUPOS)}; window.__fluxos_destino = ${JSON.stringify(FLUXOS)};` });
        await c.ir(URL + (fluxo ? '?id=5' : ''));
        await c.esperar(`typeof jQuery!=='undefined' && jQuery('#btn-adicionar-plano').length>0`, 15000);
        await esp(600);
    }
    async function secao(nome) { await js(`${q('.basico-nav-item[data-secao="' + nome + '"]')}.get(0).click()`); await esp(250); }
    async function escolher(sel, valor) {
        const r = await js(`(function(){ var $e = ${q(sel)}; if (!$e.length) return 'sem elemento'; $e.val(${JSON.stringify(valor)}).trigger('change'); return 'ok'; })()`);
        if (r !== 'ok') throw new Error('escolher: ' + r + ' em ' + sel);
        await esp(150);
    }
    async function digitar(sel, valor) {
        const r = await js(`(function(){ var $e = ${q(sel)}; if (!$e.length) return 'sem elemento'; $e.val(${JSON.stringify(String(valor))}).trigger('input'); return 'ok'; })()`);
        if (r !== 'ok') throw new Error('digitar: ' + r + ' em ' + sel);
    }
    async function clicar(sel) {
        const r = await js(`(function(){ var el = ${q(sel)}.get(0); if (!el) return 'sem elemento'; el.click(); return 'ok'; })()`);
        if (r !== 'ok') throw new Error('clicar: ' + r + ' em ' + sel);
        await esp(200);
    }
    async function salvar() {
        await js(`window.__salvo = null; ${q('#toast')}.text('')`);
        await clicar('#btn-salvar-fluxo-basico');
        await esp(300);
        return JSON.parse(await js(`JSON.stringify(window.__salvo)`));
    }
    const toast = () => js(`${q('#toast')}.text()`);
    const textos = (sel) => async () => JSON.parse(await js(`JSON.stringify(${q(sel)}.map(function(){ return this.textContent.trim(); }).get())`));
    const visivel = (sel) => js(`${q(sel)}.length > 0 && !${q(sel)}.prop('hidden') && ${q(sel)}.is(':visible')`);
    const P = '#lista-planos .painel-plano:first';
    const PE = P + ' .plano-corpo > .grade';   // o campo Entrega do PLANO (o order bump do plano tem outro, dentro de .plano-bump)
    async function novoPlano(nome) {
        await secao('planos');
        await clicar('#btn-adicionar-plano');
        await js(`${q(P + ' .campo-plano-nome')}.val(${JSON.stringify(nome)}).trigger('input'); ${q(P + ' .campo-plano-valor')}.val(29.9).trigger('input');`);
    }
    const TEXTO = 'Olá {nome}! <b>Seu acesso:</b> https://exemplo.com/a?x=1&y=2';


    try {
        await abrir(null);
        await novoPlano('Mensal');
        chk('o campo Entrega do plano tem a opção "Bot/Taxa" por último', (await textos(PE + ' .entrega-select option')()).slice(-1)[0] === '🤖 Bot/Taxa (leva o cliente para outro bot)');
        chk('antes de escolher, o fluxo de destino não aparece', !(await visivel(PE + ' .entrega-bot-select')));
        await escolher(PE + ' .entrega-select', '__bot__');
        chk('escolher "Bot/Taxa": aparece o aviso e o select do fluxo de destino, o aviso de "sem entrega" some', (await visivel(PE + ' .entrega-bot-select')) && (await visivel(PE + ' .entrega-bot-aviso')) && !(await visivel(PE + ' .dica-sem-grupo')) && !(await visivel(PE + ' .entrega-mensagem-area')));
        chk('o select lista os fluxos com o bot de cada um (ou "sem bot conectado")', JSON.stringify(await textos(PE + ' .entrega-bot-select option')()) === JSON.stringify(['Selecione um fluxo…', 'TAXA 1 (@taxa1_bot)', 'TAXA 2 (sem bot conectado)']), await textos(PE + ' .entrega-bot-select option')());
        await js(`${q(P)}.get(0).scrollIntoView({block:'center'})`);
        await esp(200);
        await c.captura(SAIDA + '/1_bot.png');
        let dados = await salvar();
        chk('Bot/Taxa sem fluxo escolhido: não salva e diz qual plano precisa do fluxo', dados === null && /Escolha o fluxo de destino da entrega Bot\/Taxa do plano "Mensal"/.test(await toast()), await toast());
        await escolher(PE + ' .entrega-bot-select', '11');
        chk('o campo "Mensagem para o cliente" aparece junto do fluxo de destino', await visivel(PE + ' .entrega-bot-mensagem'));
        await digitar(PE + ' .entrega-bot-mensagem', '  Falta só a verificação final!  ');
        dados = await salvar();
        let plano = dados && dados.dados_fluxograma.planos[0];
        chk('salvar: entrega_tipo=bot e id_fluxo_destino=11, sem grupo, link nem mensagem', !!plano && plano.entrega_tipo === 'bot' && plano.id_fluxo_destino === 11 && plano.id_grupo === '' && plano.link_entrega === '' && plano.mensagem_entrega === 'Falta só a verificação final!', plano);
        await escolher(PE + ' .entrega-select', '__link__');
        await digitar(PE + ' .entrega-link', 'https://exemplo.com/a');
        dados = await salvar();
        plano = dados && dados.dados_fluxograma.planos[0];
        chk('trocar para Link: o fluxo de destino some do JSON (id_fluxo_destino 0)', !!plano && plano.entrega_tipo === 'link' && !plano.id_fluxo_destino, plano);

        // reaberto
        const base = { ativo: true, boas_vindas: {}, ofertas: {} };
        await abrir({ id: 5, nome: 'Com bot', modo: 'basico', dados_fluxograma: Object.assign({}, base, { planos: [
            { id: 'p1', nome: 'Com bot', valor: 20, dias_acesso: 30, unidade_acesso: 'dias', entrega_tipo: 'bot', id_fluxo_destino: 11, mensagem_entrega: 'Toque abaixo' }
        ] }) });
        await secao('planos');
        chk('reaberto: o plano abre em Bot/Taxa com o fluxo escolhido', (await js(`${q('#lista-planos .painel-plano:eq(0) .plano-corpo > .grade .entrega-select')}.val()`)) === '__bot__' && (await js(`${q('#lista-planos .painel-plano:eq(0) .plano-corpo > .grade .entrega-bot-select')}.val()`)) === '11' && (await js(`${q('#lista-planos .painel-plano:eq(0) .plano-corpo > .grade .entrega-bot-mensagem')}.val()`)) === 'Toque abaixo');
        dados = await salvar();
        chk('salvar sem mexer: continua bot + fluxo 11', !!dados && dados.dados_fluxograma.planos[0].entrega_tipo === 'bot' && dados.dados_fluxograma.planos[0].id_fluxo_destino === 11);

        // Upsell
        await abrir(null);
        await novoPlano('Mensal');
        await secao('upsell');
        const U = '.painel[data-oferta="upsell"]';
        await clicar(U + ' .of-ativo');
        await escolher(U + ' .of-plano', await js(`${q(U + ' .of-plano option')}.eq(2).val()`));
        await escolher(U + ' .entrega-select', '__bot__');
        await escolher(U + ' .entrega-bot-select', '12');
        dados = await salvar();
        const up = dados && dados.dados_fluxograma.ofertas.upsell;
        chk('Upsell também entrega por Bot/Taxa (id_fluxo_destino no JSON)', !!up && up.entrega_tipo === 'bot' && up.id_fluxo_destino === 12, up);

        const erros = c.errosJs();
        chk('nenhum erro de JavaScript na página durante todo o teste', erros.length === 0, erros);
    } catch (e) {
        falhas++;
        console.log('ERRO NO TESTE: ' + (e && e.stack || e));
    } finally {
        await c.fechar();
    }
    console.log('\n' + ok + ' ok, ' + falhas + ' falha(s)   (capturas em ' + SAIDA + ')');
    process.exit(falhas ? 1 : 0);
})();
