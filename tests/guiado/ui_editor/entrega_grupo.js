// Campo "Entrega" do Fluxo Guiado como no n8n (item 31, 26/10/2026, pedido do dono): escolhe o TIPO (Nenhum, Acesso a grupo, Link) e, em "Acesso a grupo",
// aparece o select "Grupo para acesso" com os grupos do bot. Chrome real, sem servidor (gerar.php monta o fluxo_basico.php de verdade; api_falsa.js simula a API).
//   node tests/guiado/ui_editor/entrega_grupo.js      (sai com código 1 se algo falhar; capturas na pasta temporária)
const fs = require('fs');
const os = require('os');
const { spawnSync } = require('child_process');
const RAIZ = require('path').resolve(__dirname, '../../..').replace(/\\/g, '/');
const { lancar } = require(RAIZ + '/tests/homologacao/ui/cdp.js');
const SAIDA = (os.tmpdir() + '/editor_guiado_entrega_grupo').replace(/\\/g, '/');
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
const GRUPOS = [
    { id_telegram: '-1001111111111', titulo: 'VIP A', nome_bot: 'bot_um' },
    { id_telegram: '-1002222222222', titulo: 'VIP B', nome_bot: 'bot_um' },
    { id_telegram: '-1001111111111', titulo: 'VIP A', nome_bot: 'bot_dois' }   // o mesmo grupo com o 2º bot da conta
];

(async () => {
    const c = await lancar();
    const js = (e) => c.avaliar(e);
    const q = (s) => `jQuery(${JSON.stringify(s)})`;
    async function abrir(fluxo, grupos) {
        await c.enviar('Emulation.setDeviceMetricsOverride', { width: 1500, height: 1100, deviceScaleFactor: 1, mobile: false });
        await c.enviar('Page.addScriptToEvaluateOnNewDocument', { source: `window.__fluxo_pre = ${JSON.stringify(fluxo || null)}; window.__grupos = ${JSON.stringify(grupos || [])};` });
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
    async function clicar(sel) {
        const r = await js(`(function(){ var el = ${q(sel)}.get(0); if (!el) return 'sem elemento'; el.click(); return 'ok'; })()`);
        if (r !== 'ok') throw new Error('clicar: ' + r + ' em ' + sel);
        await esp(200);
    }
    async function salvar() {
        await js(`window.__salvo = null`);
        await clicar('#btn-salvar-fluxo-basico');
        await esp(300);
        return JSON.parse(await js(`JSON.stringify(window.__salvo)`));
    }
    const toast = () => js(`${q('#toast')}.text()`);
    const textos = (sel) => async () => JSON.parse(await js(`JSON.stringify(${q(sel)}.map(function(){ return this.textContent.trim(); }).get())`));
    const visivel = (sel) => js(`${q(sel)}.length > 0 && !${q(sel)}.prop('hidden') && ${q(sel)}.is(':visible')`);
    const novoPlano = async () => { await secao('planos'); await clicar('#btn-adicionar-plano'); };
    const P = '#lista-planos .painel-plano:first';
    const tipos = textos(P + ' .entrega-select option');
    const grupos = textos(P + ' .entrega-grupo-select option');
    async function nomear() {
        await js(`${q(P + ' .campo-plano-nome')}.val('Mensal').trigger('input'); ${q(P + ' .campo-plano-valor')}.val(29.9).trigger('input');`);
    }

    try {
        // ============ plano novo, com grupos já registrados ============
        await abrir(null, GRUPOS);
        await novoPlano();
        chk('o campo Entrega tem os 4 tipos: Nenhum, Acesso a grupo, Link e Mensagem (os grupos NÃO ficam misturados aqui)', JSON.stringify(await tipos()) === JSON.stringify(['Nenhum (apenas venda)', '👥 Acesso a grupo', '🔗 Link (enviado ao cliente)', '💬 Mensagem (texto enviado ao cliente)']), await tipos());
        chk('começa em "Nenhum" (com o aviso de que sem entrega ninguém recebe acesso) e o select de grupos ainda não aparece', (await js(`${q(P + ' .entrega-select')}.val()`)) === '' && (await visivel(P + ' .dica-sem-grupo')) && !(await visivel(P + ' .entrega-grupo-area')));

        await escolher(P + ' .entrega-select', '__grupo__');
        chk('escolher "Acesso a grupo": aparece o select "Grupo para acesso" logo abaixo, e o aviso "sem entrega" some', (await visivel(P + ' .entrega-grupo-area')) && !(await visivel(P + ' .dica-sem-grupo')) && (await js(`${q(P + ' .entrega-grupo-area > label')}.text()`)) === 'Grupo para acesso');
        chk('o select lista os grupos do bot pelo nome (o mesmo grupo de 2 bots aparece uma vez só), começando por "Selecione um grupo…"', JSON.stringify(await grupos()) === JSON.stringify(['Selecione um grupo…', 'VIP A', 'VIP B']), await grupos());
        chk('o botão de atualizar (↻) existe e o foco foi para o select', (await js(`${q(P + ' .entrega-grupo-atualizar')}.length`)) === 1 && (await js(`document.activeElement && document.activeElement.className`)) === 'entrega-grupo-select');
        const pos = JSON.parse(await js(`JSON.stringify((function(){ var t = ${q(P + ' .entrega-select')}.get(0).getBoundingClientRect(), s = ${q(P + ' .entrega-grupo-select')}.get(0).getBoundingClientRect(), b = ${q(P + ' .entrega-grupo-atualizar')}.get(0).getBoundingClientRect(); return { t_base: t.bottom, s_topo: s.top, s_esq: s.left, t_esq: t.left, b_esq: b.left, s_dir: s.right, t_dir: t.right, b_dir: b.right }; })())`));
        chk('o select de grupos fica EMBAIXO do de tipo, na mesma coluna, e o botão ↻ à direita dele (terminando na borda do campo)', pos.s_topo >= pos.t_base && Math.abs(pos.s_esq - pos.t_esq) <= 2 && pos.b_esq >= pos.s_dir && Math.abs(pos.b_dir - pos.t_dir) <= 3, pos);
        await js(`${q(P)}.get(0).scrollIntoView({block:'center'})`);
        await esp(200);
        await c.captura(SAIDA + '/1_acesso_a_grupo.png');

        await escolher(P + ' .entrega-grupo-select', '-1002222222222');
        await nomear();
        let dados = await salvar();
        let plano = dados && dados.dados_fluxograma.planos[0];
        chk('salvar com "VIP B": o plano grava entrega_tipo=grupo e o id do grupo, sem link', !!plano && plano.entrega_tipo === 'grupo' && plano.id_grupo === '-1002222222222' && plano.link_entrega === '', plano);

        // voltar e ir: a escolha do grupo é lembrada
        await escolher(P + ' .entrega-select', '__link__');
        chk('trocar para Link esconde o select de grupos e mostra o campo do endereço', !(await visivel(P + ' .entrega-grupo-area')) && (await visivel(P + ' .entrega-link')));
        await escolher(P + ' .entrega-select', '__grupo__');
        chk('voltar para "Acesso a grupo": o grupo escolhido antes (VIP B) continua marcado', (await js(`${q(P + ' .entrega-grupo-select')}.val()`)) === '-1002222222222');
        await escolher(P + ' .entrega-select', '');
        dados = await salvar();
        plano = dados && dados.dados_fluxograma.planos[0];
        chk('"Nenhum": grava entrega_tipo=nenhuma sem grupo e o aviso "sem entrega" volta', !!plano && plano.entrega_tipo === 'nenhuma' && plano.id_grupo === '' && (await visivel(P + ' .dica-sem-grupo')), plano);

        // "Acesso a grupo" sem escolher o grupo: não salva e diz o que falta
        await escolher(P + ' .entrega-select', '__grupo__');
        await escolher(P + ' .entrega-grupo-select', '');
        dados = await salvar();
        chk('"Acesso a grupo" sem grupo escolhido: não salva e avisa qual plano precisa do grupo', dados === null && /Escolha o grupo da entrega do plano "Mensal"/.test(await toast()), await toast());

        // ============ lista vazia + atualizar ============
        await abrir(null, []);
        await novoPlano();
        await escolher(P + ' .entrega-select', '__grupo__');
        chk('sem nenhum grupo registrado: o select só tem "Selecione um grupo…" e NÃO aparece aviso de lista vazia nem o link "Adicionar pelo ID ou @" (retirados a pedido do dono, item 32)', JSON.stringify(await grupos()) === JSON.stringify(['Selecione um grupo…']) && (await js(`${q('.dica-lista-grupos-vazia, .entrega-grupo-abrir, .entrega-grupo-novo, .entrega-grupo-id')}.length`)) === 0 && !/Nenhum grupo encontrado\. Adicione|Adicionar pelo ID/.test(await js(`document.body.innerText`)), await js(`document.body.innerText.slice(0, 80)`));
        await clicar(P + ' .entrega-grupo-atualizar');
        chk('atualizar (↻) sem grupo novo: avisa em vermelho que ainda não achou nenhum', /Nenhum grupo encontrado ainda/.test(await toast()) && (await js(`${q('#toast')}.hasClass('erro')`)) === true, await toast());
        await js(`window.__grupos = [{ id_telegram: '-1003333333333', titulo: 'VIP C', nome_bot: 'bot_um' }]`);
        await clicar(P + ' .entrega-grupo-atualizar');
        chk('depois que o bot entra num grupo, atualizar (↻) traz o grupo para o select, sem F5', JSON.stringify(await grupos()) === JSON.stringify(['Selecione um grupo…', 'VIP C']) && (await toast()) === 'Lista de grupos atualizada.', [await grupos(), await toast()]);
        await escolher(P + ' .entrega-grupo-select', '-1003333333333');
        await js(`window.__grupos = window.__grupos.concat([{ id_telegram: '-1004444444444', titulo: 'VIP D', nome_bot: 'bot_um' }])`);
        await clicar(P + ' .entrega-grupo-atualizar');
        chk('atualizar de novo mantém o grupo já escolhido (VIP C) e acrescenta o novo (VIP D)', (await js(`${q(P + ' .entrega-grupo-select')}.val()`)) === '-1003333333333' && JSON.stringify(await grupos()) === JSON.stringify(['Selecione um grupo…', 'VIP C', 'VIP D']), await grupos());

        // ============ Upsell, Downsell e Pack usam o mesmo campo ============
        await abrir(null, GRUPOS);
        await secao('upsell');
        const U = '.painel[data-oferta="upsell"]';
        await clicar(U + ' .of-ativo');
        const tiposU = await textos(U + ' .entrega-select option')();
        chk('Upsell: "Igual à do plano oferecido" primeiro, depois Nenhum, Acesso a grupo, Link e Mensagem; abre em "Igual à do plano"', JSON.stringify(tiposU) === JSON.stringify(['Igual à do plano oferecido', 'Nenhum (apenas venda)', '👥 Acesso a grupo', '🔗 Link (enviado ao cliente)', '💬 Mensagem (texto enviado ao cliente)']) && (await js(`${q(U + ' .entrega-select')}.val()`)) === '__plano__' && !(await visivel(U + ' .entrega-grupo-area')), tiposU);
        await escolher(U + ' .entrega-select', '__grupo__');
        chk('Upsell: "Acesso a grupo" mostra o select com os mesmos grupos do bot', (await visivel(U + ' .entrega-grupo-area')) && JSON.stringify(await textos(U + ' .entrega-grupo-select option')()) === JSON.stringify(['Selecione um grupo…', 'VIP A', 'VIP B']));
        await secao('downsell');
        const D = '.painel[data-oferta="downsell"]';
        await clicar(D + ' .of-ativo');
        await escolher(D + ' .entrega-select', '__grupo__');
        chk('Downsell: igual ao Upsell (select de grupos aparece, com os mesmos grupos)', (await visivel(D + ' .entrega-grupo-area')) && (await textos(D + ' .entrega-grupo-select option')()).length === 3);
        await secao('packs');
        await js(`${q('#pk-ativo')}.get(0).click()`);
        await esp(150);
        await clicar('#pk-adicionar');
        const K = '#pk-lista .pk-item:first';
        await escolher(K + ' .entrega-select', '__grupo__');
        chk('Pack: o campo "Entrega do pack" também tem o select de grupos', (await visivel(K + ' .entrega-grupo-area')) && (await js(`${q(K + ' .campo-entrega > label')}.first().text()`)) === 'Entrega do pack' && (await textos(K + ' .entrega-grupo-select option')()).length === 3);

        // ============ fluxos salvos: abrem do jeito certo ============
        const base = { ativo: true, boas_vindas: {}, ofertas: {} };
        await abrir({ id: 5, nome: 'Novo formato', modo: 'basico', dados_fluxograma: Object.assign({}, base, { planos: [
            { id: 'p1', nome: 'Com grupo', valor: 20, dias_acesso: 30, unidade_acesso: 'dias', entrega_tipo: 'grupo', id_grupo: '-1002222222222', link_entrega: '' },
            { id: 'p2', nome: 'Com link', valor: 20, dias_acesso: 30, unidade_acesso: 'dias', entrega_tipo: 'link', id_grupo: '', link_entrega: 'https://exemplo.com/x' },
            { id: 'p3', nome: 'Sem entrega', valor: 20, dias_acesso: 30, unidade_acesso: 'dias', entrega_tipo: 'nenhuma', id_grupo: '', link_entrega: '' },
            { id: 'p4', nome: 'Grupo que saiu da lista', valor: 20, dias_acesso: 30, unidade_acesso: 'dias', entrega_tipo: 'grupo', id_grupo: '-1009999999999', link_entrega: '' }
        ] }) }, GRUPOS);
        await secao('planos');
        const pl = (i, s) => `#lista-planos .painel-plano:eq(${i}) ${s}`;
        chk('reaberto: plano com grupo abre em "Acesso a grupo" com o grupo VIP B marcado e o select visível', (await js(`${q(pl(0, '.entrega-select'))}.val()`)) === '__grupo__' && (await js(`${q(pl(0, '.entrega-grupo-select'))}.val()`)) === '-1002222222222' && (await visivel(pl(0, '.entrega-grupo-area'))));
        chk('reaberto: plano com link abre em Link (sem o select de grupos) e o plano sem entrega abre em Nenhum com o aviso', (await js(`${q(pl(1, '.entrega-select'))}.val()`)) === '__link__' && !(await visivel(pl(1, '.entrega-grupo-area'))) && (await js(`${q(pl(2, '.entrega-select'))}.val()`)) === '' && (await visivel(pl(2, '.dica-sem-grupo'))));
        chk('reaberto: grupo salvo que não está mais na lista continua marcado, com o id e "(grupo salvo)" (salvar não apaga a entrega)', (await js(`${q(pl(3, '.entrega-grupo-select'))}.val()`)) === '-1009999999999' && /\(grupo salvo\)/.test(await js(`${q(pl(3, '.entrega-grupo-select option:selected'))}.text()`)));
        dados = await salvar();
        chk('salvar sem mexer: os 4 planos voltam com a mesma entrega (grupo B, link, nenhuma, grupo salvo)', !!dados && dados.dados_fluxograma.planos.map(p => p.entrega_tipo + ':' + (p.id_grupo || p.link_entrega || '')).join('|') === 'grupo:-1002222222222|link:https://exemplo.com/x|nenhuma:|grupo:-1009999999999', dados && dados.dados_fluxograma.planos);

        await abrir({ id: 5, nome: 'Antigo', modo: 'basico', dados_fluxograma: Object.assign({}, base, { planos: [
            { id: 'p1', nome: 'Antigo com grupo', valor: 20, dias_acesso: 30, unidade_acesso: 'dias', id_grupo: '-1001111111111' },
            { id: 'p2', nome: 'Antigo sem grupo', valor: 20, dias_acesso: 30, unidade_acesso: 'dias', id_grupo: '' }
        ] }) }, GRUPOS);
        await secao('planos');
        chk('fluxo ANTIGO (sem entrega_tipo): o plano com id_grupo abre em "Acesso a grupo" com VIP A e o sem grupo abre em "Nenhum"', (await js(`${q(pl(0, '.entrega-select'))}.val()`)) === '__grupo__' && (await js(`${q(pl(0, '.entrega-grupo-select option:selected'))}.text()`)) === 'VIP A' && (await js(`${q(pl(1, '.entrega-select'))}.val()`)) === '', [await js(`${q(pl(0, '.entrega-select'))}.val()`)]);
        dados = await salvar();
        chk('e salvar o fluxo antigo grava o formato novo sem perder o grupo', !!dados && dados.dados_fluxograma.planos[0].entrega_tipo === 'grupo' && dados.dados_fluxograma.planos[0].id_grupo === '-1001111111111' && dados.dados_fluxograma.planos[1].entrega_tipo === 'nenhuma');

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
