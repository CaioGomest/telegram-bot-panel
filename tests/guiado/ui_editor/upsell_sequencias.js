// Upsell em sequência (06/11/2026, pedido do Lima: "sequência de upsell, só está dando para colocar um … pagou um, vai pro outro"): o Upsell 1 é o painel fixo da seção e do 2 em diante
// são cartões (como os Downsells 2 em diante). Cada cartão escolhe um plano da lista (com desconto) ou cria um plano só para ele, e tem entrega, mensagem, mídia e botões próprios.
// Chrome real, sem servidor (gerar.php monta o fluxo_basico.php de verdade; api_falsa.js simula a API).
//   node tests/guiado/ui_editor/upsell_sequencias.js      (sai com código 1 se algo falhar; capturas na pasta temporária)
const fs = require('fs');
const os = require('os');
const { spawnSync } = require('child_process');
const RAIZ = require('path').resolve(__dirname, '../../..').replace(/\\/g, '/');
const { lancar } = require(RAIZ + '/tests/homologacao/ui/cdp.js');
const SAIDA = (os.tmpdir() + '/editor_guiado_upsell_sequencias').replace(/\\/g, '/');
fs.mkdirSync(SAIDA, { recursive: true });
const g = spawnSync('php', [__dirname + '/gerar.php', SAIDA], { encoding: 'utf8' });
if (g.status !== 0) { console.log('gerar.php falhou:\n' + g.stdout + g.stderr); process.exit(1); }
const URL = 'file:///' + SAIDA + '/index.html';
const MIDIA = RAIZ + '/tests/homologacao/ui/midia/';

let ok = 0, falhas = 0;
function chk(nome, cond, extra) {
    cond ? ok++ : falhas++;
    console.log((cond ? 'OK     ' : 'FALHOU ') + nome + (cond || extra === undefined ? '' : '  -> ' + (typeof extra === 'string' ? extra : JSON.stringify(extra))));
}
const esp = (ms) => new Promise(r => setTimeout(r, ms));
const GRUPOS = [
    { id_telegram: '-1001111111111', titulo: 'VIP A', nome_bot: 'bot_um' },
    { id_telegram: '-1002222222222', titulo: 'VIP B', nome_bot: 'bot_um' }
];

(async () => {
    const c = await lancar();
    const js = (e) => c.avaliar(e);
    const q = (s) => `jQuery(${JSON.stringify(s)})`;
    async function abrir(fluxo, grupos, largura) {
        await c.enviar('Emulation.setDeviceMetricsOverride', { width: largura || 1500, height: 1100, deviceScaleFactor: 1, mobile: (largura || 1500) < 500 });
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
    async function digitar(sel, valor) {
        const r = await js(`(function(){ var $e = ${q(sel)}; if (!$e.length) return 'sem elemento'; $e.val(${JSON.stringify(String(valor))}).trigger('input'); return 'ok'; })()`);
        if (r !== 'ok') throw new Error('digitar: ' + r + ' em ' + sel);
    }
    async function clicar(sel) {
        const r = await js(`(function(){ var el = ${q(sel)}.get(0); if (!el) return 'sem elemento'; el.click(); return 'ok'; })()`);
        if (r !== 'ok') throw new Error('clicar: ' + r + ' em ' + sel);
        await esp(200);
    }
    async function marcar(sel, estado) {
        await js(`(function(){ var el = ${q(sel)}.get(0); if (el.checked !== ${!!estado}) el.click(); })()`);
        await esp(120);
    }
    async function subir(sel, arquivo) {
        const marca = 'zt' + Math.floor(Math.random() * 1e9);
        const r = await js(`(function(){ var el = ${q(sel)}.get(0); if (!el) return 'sem elemento'; el.setAttribute('data-zt','${marca}'); return 'ok'; })()`);
        if (r !== 'ok') throw new Error('subir: ' + r + ' em ' + sel);
        const doc = await c.enviar('DOM.getDocument', { depth: 0 });
        const no = await c.enviar('DOM.querySelector', { nodeId: doc.result.root.nodeId, selector: `[data-zt="${marca}"]` });
        await c.enviar('DOM.setFileInputFiles', { nodeId: no.result.nodeId, files: [MIDIA + arquivo] });
        await esp(700);
    }
    // escolhe VÁRIOS arquivos de uma vez no campo de arquivo (o mesmo que o diálogo do sistema faria) e espera os envios acabarem
    async function subirVarios(sel, arquivos, espera) {
        const marca = 'zt' + Math.floor(Math.random() * 1e9);
        const r = await js(`(function(){ var el = ${q(sel)}.get(0); if (!el) return 'sem elemento'; el.setAttribute('data-zt','${marca}'); return 'ok'; })()`);
        if (r !== 'ok') throw new Error('subirVarios: ' + r + ' em ' + sel);
        const doc = await c.enviar('DOM.getDocument', { depth: 0 });
        const no = await c.enviar('DOM.querySelector', { nodeId: doc.result.root.nodeId, selector: `[data-zt="${marca}"]` });
        await c.enviar('DOM.setFileInputFiles', { nodeId: no.result.nodeId, files: arquivos.map(a => MIDIA + a) });
        await esp(espera || 1200);
    }
    const rotulos = async (sel) => JSON.parse(await js(`JSON.stringify(${q(sel)}.map(function(){ return $(this).find('span').first().text().replace(/\\s+/g,' ').trim().split(' ').slice(0, 2).join(' '); }).get())`));
    async function salvar() {
        await js(`window.__salvo = null; ${q('#toast')}.text('')`);
        await clicar('#btn-salvar-fluxo-basico');
        await esp(300);
        return JSON.parse(await js(`JSON.stringify(window.__salvo)`));
    }
    const toast = () => js(`${q('#toast')}.text()`);
    const visivel = (sel) => js(`${q(sel)}.length > 0 && ${q(sel)}.is(':visible')`);
    const qtd = (sel) => js(`${q(sel)}.length`);
    const texto = (sel) => js(`${q(sel)}.first().text().replace(/\\s+/g, ' ').trim()`);
    const valor = (sel) => js(`${q(sel)}.first().val()`);
    const U = '.painel[data-oferta="upsell"]';
    const X = (i) => '#up-extras .up-extra:eq(' + i + ')';
    async function planos() {
        await secao('planos');
        await clicar('#btn-adicionar-plano');
        await js(`${q('#lista-planos .painel-plano:eq(0) .campo-plano-nome')}.val('Mensal').trigger('input'); ${q('#lista-planos .painel-plano:eq(0) .campo-plano-valor')}.val(29.9).trigger('input');`);
        await clicar('#btn-adicionar-plano');
        await js(`${q('#lista-planos .painel-plano:eq(1) .campo-plano-nome')}.val('Anual').trigger('input'); ${q('#lista-planos .painel-plano:eq(1) .campo-plano-valor')}.val(99).trigger('input');`);
        await clicar('#btn-adicionar-plano');
        await js(`${q('#lista-planos .painel-plano:eq(2) .campo-plano-nome')}.val('Trimestral').trigger('input'); ${q('#lista-planos .painel-plano:eq(2) .campo-plano-valor')}.val(49.9).trigger('input');`);
    }
    const opcoes = async (sel) => JSON.parse(await js(`JSON.stringify(${q(sel + ' option')}.map(function(){ return this.textContent.trim(); }).get())`));

    try {
        // ============ fluxo novo: só o Upsell 1 ============
        await abrir(null, GRUPOS);
        await planos();
        await secao('upsell');
        const IDS = JSON.parse(await js(`JSON.stringify((function(){ var m = {}; jQuery('.painel[data-oferta="upsell"] .of-plano option').each(function(){ var n = this.textContent.split(' — ')[0].trim(); if (this.value && this.value !== 'up_exclusivo') m[n] = this.value; }); return m; })())`));
        await clicar(U + ' .of-ativo');
        chk('ao ligar o Upsell: aparece o botão "+ Adicionar upsell" e ainda não há cartões extras (o título "Upsell 1" só aparece com mais de um)',
            (await visivel('#up-adicionar')) && (await texto('#up-adicionar')) === '+ Adicionar upsell' && (await qtd('#up-extras .up-extra')) === 0 && !(await visivel(U + ' .up-titulo-1')));
        await escolher(U + ' .of-plano', IDS.Anual);
        await digitar(U + ' .of-mensagem', 'Leve o plano anual');

        // ============ Upsell 2: plano da lista ============
        await clicar('#up-adicionar');
        chk('"+ Adicionar upsell" cria o cartão "Upsell 2" e o título do primeiro passa a aparecer ("Upsell 1")',
            (await qtd('#up-extras .up-extra')) === 1 && (await texto(X(0) + ' .bp-item-titulo')) === 'Upsell 2' && (await visivel(U + ' .up-titulo-1')) && (await texto(U + ' .up-titulo-1')) === 'Upsell 1');
        const ops = await opcoes(X(0) + ' .up-plano');
        chk('o seletor do cartão lista "Escolha um plano", "Criar um plano só para este upsell" e os 3 planos', ops.length === 5 && ops[0] === 'Escolha um plano' && /Criar um plano só para este upsell/.test(ops[1]) && /Mensal/.test(ops[2]), ops);
        chk('o botão de adicionar fica à direita', await js(`(function(){ var b = document.getElementById('up-adicionar').getBoundingClientRect(), p = document.getElementById('up-extras').getBoundingClientRect(); return b.right > p.right - 4; })()`));
        await escolher(X(0) + ' .up-plano', IDS.Mensal);
        await digitar(X(0) + ' .up-desconto', 25);
        await digitar(X(0) + ' .up-mensagem', 'Mais um para você');
        await digitar(X(0) + ' .up-aceitar', 'Adicionar');
        await digitar(X(0) + ' .up-recusar', 'Agora não');
        chk('plano da lista: o campo Desconto aparece e a Entrega tem "Igual à do plano oferecido"', (await visivel(X(0) + ' .up-desconto')) && !(await visivel(X(0) + ' .up-proprio-bloco')) && /Igual à do plano/.test((await opcoes(X(0) + ' .up-entrega .entrega-select'))[0]));

        // ============ Upsell 3: plano criado só para ele ============
        await clicar('#up-adicionar');
        chk('mais um cartão: "Upsell 3"', (await qtd('#up-extras .up-extra')) === 2 && (await texto(X(1) + ' .bp-item-titulo')) === 'Upsell 3');
        await escolher(X(1) + ' .up-plano', 'up_exclusivo');
        chk('plano criado só para o upsell: aparecem Nome, Valor e Tempo de acesso, some o Desconto e a Entrega perde "Igual à do plano oferecido"',
            (await visivel(X(1) + ' .up-proprio-bloco')) && !(await visivel(X(1) + ' .up-desconto-campo')) && !/Igual à do plano/.test((await opcoes(X(1) + ' .up-entrega .entrega-select')).join('|')), await opcoes(X(1) + ' .up-entrega .entrega-select'));
        chk('o foco vai para o nome do plano', (await js(`document.activeElement && document.activeElement.className`)) === 'up-proprio-nome');
        // entrega ficou "Nenhum" (o plano é o próprio upsell)
        chk('a Entrega começa em "Nenhum (apenas venda)"', (await valor(X(1) + ' .up-entrega .entrega-select')) === '');
        await digitar(X(1) + ' .up-proprio-nome', 'Pacote Hot');
        await digitar(X(1) + ' .up-proprio-valor', 39.9);
        await digitar(X(1) + ' .up-proprio-dias', 15);
        await escolher(X(1) + ' .up-proprio-unidade', 'dias');
        await escolher(X(1) + ' .up-entrega .entrega-select', '__link__');
        await digitar(X(1) + ' .up-entrega .entrega-link', 'https://area.exemplo.com/hot');
        await digitar(X(1) + ' .up-mensagem', 'Pacote Hot só para quem comprou');
        // mídia: 2 arquivos juntos
        await js(`${q(X(1) + ' .up-midia-arquivo')}.prop('multiple', true)`);
        await subirVarios(X(1) + ' .up-midia-arquivo', ['foto1.png', 'foto2.jpg'], 1500);
        chk('mídia do cartão: 2 arquivos ficam (a 1ª na caixa grande e a 2ª na lista), com o botão de adicionar outra', (await js(`${q(X(1) + ' .up-midia')}.attr('data-estado')`)) !== 'vazio' && (await qtd(X(1) + ' .up-midias-extras .basico-bot')) === 1 && (await visivel(X(1) + ' .up-midias-adicionar')), await js(`${q(X(1) + ' .up-midia')}.attr('data-estado')`));

        // ============ validações ============
        let dados = await salvar();
        chk('fluxo completo salva', dados !== null, await toast());
        const sq = dados && dados.dados_fluxograma.ofertas.upsell;
        chk('o JSON leva o Upsell 1 e 2 extras (sequencias_extras)', !!sq && sq.id_plano_destino === IDS.Anual && Array.isArray(sq.sequencias_extras) && sq.sequencias_extras.length === 2, sq);
        const e1 = sq && sq.sequencias_extras[0], e2 = sq && sq.sequencias_extras[1];
        chk('Upsell 2 no JSON: plano p1, 25% de desconto, mensagem e botões', !!e1 && e1.id_plano_destino === IDS.Mensal && e1.desconto_percentual === 25 && e1.mensagem === 'Mais um para você' && e1.texto_aceitar === 'Adicionar' && e1.texto_recusar === 'Agora não' && !e1.plano_proprio && e1.entrega_tipo === 'plano', e1);
        chk('Upsell 3 no JSON: plano próprio (nome, valor, 15 dias), sem desconto, entrega por link e 2 mídias', !!e2 && e2.id_plano_destino === 'up_exclusivo' && e2.plano_proprio.nome === 'Pacote Hot' && e2.plano_proprio.valor === 39.9 && e2.plano_proprio.dias_acesso === 15
            && e2.desconto_percentual === 0 && e2.entrega_tipo === 'link' && e2.link_entrega === 'https://area.exemplo.com/hot' && e2.midias.length === 2 && e2.midia_path === e2.midias[0].path, e2);

        await escolher(X(0) + ' .up-plano', '');
        dados = await salvar();
        chk('Upsell 2 sem plano escolhido: não salva e diz qual', dados === null && /^Escolha o plano oferecido no Upsell 2/.test(await toast()), await toast());
        await escolher(X(0) + ' .up-plano', IDS.Mensal);
        await digitar(X(1) + ' .up-proprio-nome', '');
        dados = await salvar();
        chk('plano próprio do Upsell 3 sem nome: não salva e diz qual', dados === null && /Upsell 3/.test(await toast()) && /nome/.test(await toast()), await toast());
        await digitar(X(1) + ' .up-proprio-nome', 'Pacote Hot');
        await digitar(X(1) + ' .up-proprio-valor', 0);
        dados = await salvar();
        chk('plano próprio do Upsell 3 com valor zero: não salva', dados === null && /Upsell 3/.test(await toast()) && /valor maior que zero/.test(await toast()), await toast());
        await digitar(X(1) + ' .up-proprio-valor', 39.9);
        await digitar(X(1) + ' .up-entrega .entrega-link', 'isso nao e link');
        dados = await salvar();
        chk('entrega por link inválida no Upsell 3: não salva e cita o Upsell 3', dados === null && /Upsell 3/.test(await toast()), await toast());
        await digitar(X(1) + ' .up-entrega .entrega-link', 'https://area.exemplo.com/hot');

        // ============ trocar o plano do cartão e remover ============
        await escolher(X(1) + ' .up-plano', IDS.Trimestral);
        chk('trocar de plano criado para plano da lista: o Desconto volta, o bloco do plano some e a entrega vira "Igual à do plano oferecido" ou segue o que foi escolhido',
            (await visivel(X(1) + ' .up-desconto-campo')) && !(await visivel(X(1) + ' .up-proprio-bloco')), await valor(X(1) + ' .up-entrega .entrega-select'));
        await escolher(X(1) + ' .up-plano', 'up_exclusivo');
        chk('…e de volta ao plano criado: o nome digitado continua lá', (await valor(X(1) + ' .up-proprio-nome')) === 'Pacote Hot');
        await clicar(X(0) + ' .up-remover');
        chk('remover o Upsell 2: sobra 1 cartão, que passa a ser o "Upsell 2" e mantém o que foi digitado', (await qtd('#up-extras .up-extra')) === 1 && (await texto(X(0) + ' .bp-item-titulo')) === 'Upsell 2' && (await valor(X(0) + ' .up-proprio-nome')) === 'Pacote Hot');
        await clicar(X(0) + ' .up-remover');
        chk('sem cartões o título "Upsell 1" some de novo', (await qtd('#up-extras .up-extra')) === 0 && !(await visivel(U + ' .up-titulo-1')));
        dados = await salvar();
        chk('sem extras o JSON leva sequencias_extras vazio', !!dados && dados.dados_fluxograma.ofertas.upsell.sequencias_extras.length === 0, await toast());

        // ============ limite ============
        for (let i = 0; i < 12; i++) { await js(`${q('#up-adicionar')}.get(0).click()`); }
        chk('limite de 10 upsells (1 fixo + 9 cartões); o botão mostra o limite e fica desativado', (await qtd('#up-extras .up-extra')) === 9 && (await texto('#up-adicionar')) === 'Limite de 10 upsells' && (await js(`${q('#up-adicionar')}.prop('disabled')`)));

        // ============ fluxo salvo reaberto ============
        const base = { ativo: true, boas_vindas: {}, planos: [
            { id: 'p1', nome: 'Mensal', valor: 29.9, dias_acesso: 30, unidade_acesso: 'dias', entrega_tipo: 'nenhuma', id_grupo: '', link_entrega: '' },
            { id: 'p2', nome: 'Anual', valor: 99, dias_acesso: 365, unidade_acesso: 'dias', entrega_tipo: 'nenhuma', id_grupo: '', link_entrega: '' }
        ] };
        const salvoUp = { ativo: true, id_plano_destino: 'p2', desconto_percentual: 10, mensagem: 'U1', texto_aceitar: 'Quero', texto_recusar: 'Nao', entrega_tipo: 'plano', id_grupo: '', link_entrega: '',
            sequencias_extras: [
                { id_plano_destino: 'p1', desconto_percentual: 30, mensagem: 'U2', texto_aceitar: 'A2', texto_recusar: 'R2', entrega_tipo: 'link', link_entrega: 'https://promo.exemplo.com/x', id_grupo: '', midia_tipo: 'none', midia_path: '' },
                { id_plano_destino: 'up_exclusivo', plano_proprio: { nome: 'Combo', valor: 59, dias_acesso: 3, unidade_acesso: 'horas' }, desconto_percentual: 0, mensagem: 'U3', texto_aceitar: '', texto_recusar: '', entrega_tipo: 'mensagem', mensagem_entrega: 'Seu acesso: x', id_grupo: '', link_entrega: '', midia_tipo: 'none', midia_path: '' }
            ] };
        await abrir({ id: 5, nome: 'Com sequência de upsell', modo: 'basico', dados_fluxograma: Object.assign({}, base, { ofertas: { upsell: salvoUp } }) }, GRUPOS);
        await secao('upsell');
        chk('fluxo salvo: o Upsell 1 e os 2 cartões voltam preenchidos', (await qtd('#up-extras .up-extra')) === 2 && (await valor(U + ' .of-plano')) === 'p2' && (await valor(X(0) + ' .up-plano')) === 'p1' && (await valor(X(0) + ' .up-desconto')) === '30'
            && (await valor(X(0) + ' .up-mensagem')) === 'U2' && (await valor(X(0) + ' .up-aceitar')) === 'A2' && (await valor(X(1) + ' .up-plano')) === 'up_exclusivo' && (await valor(X(1) + ' .up-proprio-nome')) === 'Combo' && (await valor(X(1) + ' .up-proprio-valor')) === '59'
            && (await valor(X(1) + ' .up-proprio-unidade')) === 'horas');
        chk('fluxo salvo: a entrega de cada cartão voltou (link no 2; mensagem no 3, sem "Igual…")', (await valor(X(0) + ' .up-entrega .entrega-link')) === 'https://promo.exemplo.com/x' && (await valor(X(1) + ' .up-entrega .entrega-select')) !== '' && (await valor(X(1) + ' .up-entrega .entrega-mensagem')) === 'Seu acesso: x');
        dados = await salvar();
        const rf = dados && dados.dados_fluxograma.ofertas.upsell;
        chk('salvar de novo sem mexer devolve o mesmo (planos, descontos, entregas, plano próprio)', !!rf && rf.sequencias_extras.length === 2 && rf.sequencias_extras[0].id_plano_destino === 'p1' && rf.sequencias_extras[0].desconto_percentual === 30 && rf.sequencias_extras[0].link_entrega === 'https://promo.exemplo.com/x'
            && rf.sequencias_extras[1].plano_proprio.nome === 'Combo' && rf.sequencias_extras[1].plano_proprio.dias_acesso === 3 && rf.sequencias_extras[1].plano_proprio.unidade_acesso === 'horas' && rf.sequencias_extras[1].entrega_tipo === 'mensagem' && rf.sequencias_extras[1].mensagem_entrega === 'Seu acesso: x', rf);
        // fluxo antigo (sem sequencias_extras): continua com 1 upsell
        await abrir({ id: 6, nome: 'Antigo', modo: 'basico', dados_fluxograma: Object.assign({}, base, { ofertas: { upsell: { ativo: true, id_plano_destino: 'p2', desconto_percentual: 10, mensagem: 'so um' } } }) }, GRUPOS);
        await secao('upsell');
        chk('fluxo antigo: sem cartões extras e o título "Upsell 1" não aparece', (await qtd('#up-extras .up-extra')) === 0 && !(await visivel(U + ' .up-titulo-1')));

        // ============ celular ============
        await abrir({ id: 5, nome: 'Com sequência de upsell', modo: 'basico', dados_fluxograma: Object.assign({}, base, { ofertas: { upsell: salvoUp } }) }, GRUPOS, 390);
        await secao('upsell');
        const largura = JSON.parse(await js(`JSON.stringify({ pagina: document.documentElement.scrollWidth, janela: window.innerWidth })`));
        chk('celular (390 px): sem rolagem lateral com os cartões do upsell', largura.pagina <= largura.janela + 1, largura);
        const fora = JSON.parse(await js(`JSON.stringify(${q('#up-extras input:visible, #up-extras select:visible, #up-extras textarea:visible, #up-extras button:visible')}.filter(function(){ var r = this.getBoundingClientRect(); return r.right > window.innerWidth + 1; }).length)`));
        chk('celular: nenhum campo dos cartões passa da borda da tela', fora === 0, fora);
        await js(`${q('#up-extras')}.get(0).scrollIntoView({block:'start'})`);
        await esp(200);
        await c.captura(SAIDA + '/up_celular.png');
        await abrir({ id: 5, nome: 'Com sequência de upsell', modo: 'basico', dados_fluxograma: Object.assign({}, base, { ofertas: { upsell: salvoUp } }) }, GRUPOS);
        await secao('upsell');
        await js(`${q(U)}.get(0).scrollIntoView({block:'start'})`);
        await esp(200);
        await c.captura(SAIDA + '/up_desktop.png');

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
