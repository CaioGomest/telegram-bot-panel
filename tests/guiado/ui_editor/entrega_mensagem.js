// Entrega por mensagem de texto no Fluxo Guiado (item 34, 27/10/2026, pedido do dono: "em upsell, down, em todas as entregas, coloque a opção de mensagem, texto"):
// o campo Entrega ganha "Mensagem" e, ao escolher, aparece a caixa "Mensagem de entrega". Chrome real, sem servidor (gerar.php monta o fluxo_basico.php de verdade;
// api_falsa.js simula a API).
//   node tests/guiado/ui_editor/entrega_mensagem.js      (sai com código 1 se algo falhar; capturas na pasta temporária)
const fs = require('fs');
const os = require('os');
const { spawnSync } = require('child_process');
const RAIZ = require('path').resolve(__dirname, '../../..').replace(/\\/g, '/');
const { lancar } = require(RAIZ + '/tests/homologacao/ui/cdp.js');
const SAIDA = (os.tmpdir() + '/editor_guiado_entrega_mensagem').replace(/\\/g, '/');
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
const OPCAO_MENSAGEM = '💬 Mensagem (texto enviado ao cliente)';

(async () => {
    const c = await lancar();
    const js = (e) => c.avaliar(e);
    const q = (s) => `jQuery(${JSON.stringify(s)})`;
    async function abrir(fluxo) {
        await c.enviar('Emulation.setDeviceMetricsOverride', { width: 1500, height: 1100, deviceScaleFactor: 1, mobile: false });
        await c.enviar('Page.addScriptToEvaluateOnNewDocument', { source: `window.__fluxo_pre = ${JSON.stringify(fluxo || null)}; window.__grupos = ${JSON.stringify(GRUPOS)};` });
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
    async function novoPlano(nome) {
        await secao('planos');
        await clicar('#btn-adicionar-plano');
        await js(`${q(P + ' .campo-plano-nome')}.val(${JSON.stringify(nome)}).trigger('input'); ${q(P + ' .campo-plano-valor')}.val(29.9).trigger('input');`);
    }
    const TEXTO = 'Olá {nome}! <b>Seu acesso:</b> https://exemplo.com/a?x=1&y=2';

    try {
        // ============ plano ============
        await abrir(null);
        await novoPlano('Mensal');
        chk('o campo Entrega do plano tem Nenhum, Acesso a grupo, Link e Mensagem (nessa ordem)', JSON.stringify(await textos(P + ' .entrega-select option')()) === JSON.stringify(['Nenhum (apenas venda)', '👥 Acesso a grupo', '🔗 Link (enviado ao cliente)', OPCAO_MENSAGEM]), await textos(P + ' .entrega-select option')());
        chk('antes de escolher, a caixa da mensagem não aparece', !(await visivel(P + ' .entrega-mensagem')));
        await escolher(P + ' .entrega-select', '__mensagem__');
        chk('escolher "Mensagem": aparece a caixa "Mensagem de entrega" (com limite de 3000 caracteres), o foco vai para ela e o aviso de "sem entrega" some',
            (await visivel(P + ' .entrega-mensagem')) && (await js(`${q(P + ' .entrega-mensagem-area > label')}.text()`)) === 'Mensagem de entrega' && (await js(`${q(P + ' .entrega-mensagem')}.attr('maxlength')`)) === '3000'
            && (await js(`document.activeElement && document.activeElement.className`)) === 'entrega-mensagem' && !(await visivel(P + ' .dica-sem-grupo')) && !(await visivel(P + ' .entrega-grupo-area')) && !(await visivel(P + ' .entrega-link')));
        await js(`${q(P)}.get(0).scrollIntoView({block:'center'})`);
        await esp(200);
        await c.captura(SAIDA + '/1_mensagem.png');

        let dados = await salvar();
        chk('mensagem escolhida mas vazia: não salva e diz qual plano precisa do texto', dados === null && /Escreva a mensagem da entrega do plano "Mensal"/.test(await toast()), await toast());
        await digitar(P + ' .entrega-mensagem', '   ' + TEXTO + '  ');
        dados = await salvar();
        let plano = dados && dados.dados_fluxograma.planos[0];
        chk('salvar: o plano grava entrega_tipo=mensagem e o texto sem as pontas (com <b>, & e {nome} intactos), sem grupo e sem link', !!plano && plano.entrega_tipo === 'mensagem' && plano.mensagem_entrega === TEXTO && plano.id_grupo === '' && plano.link_entrega === '', plano);

        await escolher(P + ' .entrega-select', '__link__');
        chk('trocar para Link esconde a caixa da mensagem', !(await visivel(P + ' .entrega-mensagem')) && (await visivel(P + ' .entrega-link')));
        await escolher(P + ' .entrega-select', '__mensagem__');
        chk('voltar para Mensagem (sem salvar entre as trocas): o texto digitado antes continua na caixa', (await js(`${q(P + ' .entrega-mensagem')}.val().trim()`)) === TEXTO);
        await escolher(P + ' .entrega-select', '__link__');
        await digitar(P + ' .entrega-link', 'https://exemplo.com/acesso');
        dados = await salvar();
        plano = dados && dados.dados_fluxograma.planos[0];
        chk('com Link escolhido, o texto da mensagem NÃO vai no JSON (mensagem_entrega vazia)', !!plano && plano.entrega_tipo === 'link' && plano.mensagem_entrega === '' && plano.link_entrega === 'https://exemplo.com/acesso', plano);
        await escolher(P + ' .entrega-select', '');
        dados = await salvar();
        plano = dados && dados.dados_fluxograma.planos[0];
        chk('"Nenhum": grava nenhuma e mensagem vazia, e o aviso "sem entrega" volta', !!plano && plano.entrega_tipo === 'nenhuma' && plano.mensagem_entrega === '' && (await visivel(P + ' .dica-sem-grupo')), plano);

        // ============ Upsell, Downsell e Pack ============
        await abrir(null);
        await novoPlano('Mensal');
        await secao('upsell');
        const U = '.painel[data-oferta="upsell"]';
        await clicar(U + ' .of-ativo');
        const tiposU = await textos(U + ' .entrega-select option')();
        chk('Upsell: "Igual à do plano oferecido", Nenhum, Acesso a grupo, Link e Mensagem', JSON.stringify(tiposU) === JSON.stringify(['Igual à do plano oferecido', 'Nenhum (apenas venda)', '👥 Acesso a grupo', '🔗 Link (enviado ao cliente)', OPCAO_MENSAGEM]), tiposU);
        await escolher(U + ' .entrega-select', '__mensagem__');
        chk('Upsell: escolher Mensagem mostra a caixa', await visivel(U + ' .entrega-mensagem'));
        await escolher(U + ' .of-plano', await js(`${q(U + ' .of-plano option')}.eq(2).val()`));
        await digitar(U + ' .entrega-mensagem', 'Seu upsell foi liberado!');
        await secao('downsell');
        const D = '.painel[data-oferta="downsell"]';
        await clicar(D + ' .of-ativo');
        await js(`${q(D + ' .of-planos-lista input')}.first().prop('checked', true)`);
        const tiposD = await textos(D + ' .entrega-select option')();
        chk('Downsell: também tem a opção Mensagem', tiposD.includes(OPCAO_MENSAGEM), tiposD);
        await escolher(D + ' .entrega-select', '__mensagem__');
        await digitar(D + ' .entrega-mensagem', 'Seu downsell foi liberado!');
        await secao('packs');
        await js(`${q('#pk-ativo')}.get(0).click()`);
        await esp(150);
        await clicar('#pk-adicionar');
        const K = '#pk-lista .pk-item:first';
        await js(`${q(K + ' .pk-nome')}.val('Pack A').trigger('input'); ${q(K + ' .pk-preco')}.val(19.9).trigger('input');`);
        chk('Pack: o campo "Entrega do pack" também tem a opção Mensagem', (await textos(K + ' .entrega-select option')()).includes(OPCAO_MENSAGEM));
        await escolher(K + ' .entrega-select', '__mensagem__');
        await digitar(K + ' .entrega-mensagem', 'Seu pack foi liberado!');
        dados = await salvar();
        const d = dados && dados.dados_fluxograma;
        chk('salvar: Upsell, Downsell e Pack gravam entrega_tipo=mensagem com cada texto', !!d && d.ofertas.upsell.entrega_tipo === 'mensagem' && d.ofertas.upsell.mensagem_entrega === 'Seu upsell foi liberado!' && d.ofertas.downsell.entrega_tipo === 'mensagem' && d.ofertas.downsell.mensagem_entrega === 'Seu downsell foi liberado!' && d.packs.itens[0].entrega_tipo === 'mensagem' && d.packs.itens[0].mensagem_entrega === 'Seu pack foi liberado!', d && { up: d.ofertas.upsell.mensagem_entrega, dn: d.ofertas.downsell.mensagem_entrega, pk: d.packs.itens[0] });
        await secao('downsell');
        await digitar(D + ' .entrega-mensagem', '');
        dados = await salvar();
        chk('Downsell com Mensagem e texto vazio: não salva e cita o Downsell', dados === null && /Escreva a mensagem da entrega do Downsell/.test(await toast()), await toast());

        // ============ plano próprio do Upsell (item 33) ============
        await abrir(null);
        await novoPlano('Mensal');
        await secao('upsell');
        await clicar(U + ' .of-ativo');
        await escolher(U + ' .of-plano', 'up_exclusivo');
        await digitar(U + ' .of-proprio-nome', 'Pacote VIP');
        await digitar(U + ' .of-proprio-valor', 49.9);
        chk('Upsell com plano próprio: a entrega também tem a opção Mensagem (e continua sem "Igual à do plano oferecido")', JSON.stringify(await textos(U + ' .entrega-select option')()) === JSON.stringify(['Nenhum (apenas venda)', '👥 Acesso a grupo', '🔗 Link (enviado ao cliente)', OPCAO_MENSAGEM]), await textos(U + ' .entrega-select option')());
        await escolher(U + ' .entrega-select', '__mensagem__');
        await digitar(U + ' .entrega-mensagem', 'Pacote VIP liberado!');
        dados = await salvar();
        const up = dados && dados.dados_fluxograma.ofertas.upsell;
        chk('salvar: plano próprio + mensagem de entrega no JSON do Upsell', !!up && up.id_plano_destino === 'up_exclusivo' && up.plano_proprio.nome === 'Pacote VIP' && up.entrega_tipo === 'mensagem' && up.mensagem_entrega === 'Pacote VIP liberado!', up);
        await escolher(U + ' .of-plano', await js(`${q(U + ' .of-plano option')}.eq(2).val()`));
        chk('trocar para um plano que já existe mantém a mensagem escolhida e devolve "Igual à do plano oferecido"', (await js(`${q(U + ' .entrega-select')}.val()`)) === '__mensagem__' && (await js(`${q(U + ' .entrega-mensagem')}.val()`)) === 'Pacote VIP liberado!' && (await textos(U + ' .entrega-select option')())[0] === 'Igual à do plano oferecido');

        // ============ fluxo salvo reaberto ============
        const base = { ativo: true, boas_vindas: {}, ofertas: {} };
        await abrir({ id: 5, nome: 'Com mensagem', modo: 'basico', dados_fluxograma: Object.assign({}, base, { planos: [
            { id: 'p1', nome: 'Com mensagem', valor: 20, dias_acesso: 30, unidade_acesso: 'dias', entrega_tipo: 'mensagem', id_grupo: '', link_entrega: '', mensagem_entrega: TEXTO },
            { id: 'p2', nome: 'Com grupo', valor: 20, dias_acesso: 30, unidade_acesso: 'dias', entrega_tipo: 'grupo', id_grupo: '-1001111111111', link_entrega: '' }
        ] }) });
        await secao('planos');
        const pl = (i, s) => `#lista-planos .painel-plano:eq(${i}) ${s}`;
        chk('reaberto: o plano com mensagem abre em "Mensagem", com a caixa visível e o texto exato (<b>, & e {nome} sem se perderem)', (await js(`${q(pl(0, '.entrega-select'))}.val()`)) === '__mensagem__' && (await visivel(pl(0, '.entrega-mensagem'))) && (await js(`${q(pl(0, '.entrega-mensagem'))}.val()`)) === TEXTO);
        chk('reaberto: o plano com grupo continua abrindo em "Acesso a grupo", sem a caixa de mensagem', (await js(`${q(pl(1, '.entrega-select'))}.val()`)) === '__grupo__' && !(await visivel(pl(1, '.entrega-mensagem'))));
        dados = await salvar();
        chk('salvar sem mexer: o texto volta idêntico no JSON e o plano com grupo não ganha mensagem', !!dados && dados.dados_fluxograma.planos[0].mensagem_entrega === TEXTO && dados.dados_fluxograma.planos[1].mensagem_entrega === '' && dados.dados_fluxograma.planos[1].id_grupo === '-1001111111111', dados && dados.dados_fluxograma.planos);

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
