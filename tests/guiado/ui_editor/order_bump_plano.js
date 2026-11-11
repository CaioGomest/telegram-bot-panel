// Order Bump PERSONALIZADO por plano (item 79, 05/11/2026, pedido do dono, igual ao da Shark): dentro de cada plano, o interruptor "Order Bump personalizado" abre
// Nome, Preço, Descrição, Botão aceitar/recusar, Mensagem CTA, Entrega ("Mesmo delivery do plano") e até 3 mídias. Ligado, substitui o order bump do fluxo para aquele
// plano. Chrome real, sem servidor (gerar.php monta o fluxo_basico.php de verdade; api_falsa.js simula a API).
//   node tests/guiado/ui_editor/order_bump_plano.js      (sai com código 1 se algo falhar; capturas na pasta temporária)
const fs = require('fs');
const os = require('os');
const { spawnSync } = require('child_process');
const RAIZ = require('path').resolve(__dirname, '../../..').replace(/\\/g, '/');
const { lancar } = require(RAIZ + '/tests/homologacao/ui/cdp.js');
const SAIDA = (os.tmpdir() + '/editor_guiado_order_bump_plano').replace(/\\/g, '/');
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

(async () => {
    const c = await lancar();
    const js = (e) => c.avaliar(e);
    const q = (s) => `jQuery(${JSON.stringify(s)})`;
    async function abrir(fluxo, largura) {
        await c.enviar('Emulation.setDeviceMetricsOverride', { width: largura || 1500, height: 1100, deviceScaleFactor: 1, mobile: (largura || 1500) < 500 });
        await c.enviar('Page.addScriptToEvaluateOnNewDocument', { source: `window.__fluxo_pre = ${JSON.stringify(fluxo || null)}; window.__grupos = [];` });
        await c.ir(URL + (fluxo ? '?id=5' : ''));
        await c.esperar(`typeof jQuery!=='undefined' && jQuery('#btn-adicionar-plano').length>0`, 15000);
        await esp(600);
    }
    async function secao(nome) { await js(`${q('.basico-nav-item[data-secao="' + nome + '"]')}.get(0).click()`); await esp(250); }
    async function digitar(sel, valor) {
        const r = await js(`(function(){ var $e = ${q(sel)}; if (!$e.length) return 'sem elemento'; $e.val(${JSON.stringify(String(valor))}).trigger('input'); return 'ok'; })()`);
        if (r !== 'ok') throw new Error('digitar: ' + r + ' em ' + sel);
    }
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
    async function subir(sel, arquivos) {
        const marca = 'zt' + Math.floor(Math.random() * 1e9);
        await js(`${q(sel)}.get(0).setAttribute('data-zt','${marca}')`);
        const doc = await c.enviar('DOM.getDocument', { depth: 0 });
        const no = await c.enviar('DOM.querySelector', { nodeId: doc.result.root.nodeId, selector: `[data-zt="${marca}"]` });
        await c.enviar('DOM.setFileInputFiles', { nodeId: no.result.nodeId, files: arquivos.map(a => MIDIA + a) });
        await esp(900);
    }
    async function salvar() {
        await js(`window.__salvo = null; ${q('#toast')}.text('')`);
        await clicar('#btn-salvar-fluxo-basico');
        await esp(300);
        return JSON.parse(await js(`JSON.stringify(window.__salvo)`));
    }
    const toast = () => js(`${q('#toast')}.text()`);
    const visivel = (sel) => js(`${q(sel)}.length > 0 && !${q(sel)}.prop('hidden') && ${q(sel)}.is(':visible')`);
    const P = '#lista-planos .painel-plano:first';
    const B = P + ' .plano-bump';
    const FLUXO = {
        id: 5, nome: 'Fluxo do bump', modo: 'basico',
        dados_fluxograma: {
            ativo: true, boas_vindas: { mensagem: 'Oi', midias: [] },
            planos: [
                { id: 'p1', nome: 'Mensal', valor: 30, dias_acesso: 30, unidade_acesso: 'dias', entrega_tipo: 'nenhuma' },
                { id: 'p2', nome: 'Assinatura', valor: 20, cobranca: 'recorrente', periodicidade: 'mensal', entrega_tipo: 'nenhuma' }
            ],
            ofertas: { upsell: { ativo: false }, downsell: { ativo: false }, order_bump: { ativo: false } }
        }
    };

    try {
        await abrir(FLUXO);
        await secao('planos');
        chk('cada plano (não recorrente) mostra o bloco "Order Bump personalizado", desligado, com o aviso de que vale o do fluxo',
            (await visivel(B)) && !(await js(`${q(B + ' .pb-ativo')}.is(':checked')`)) && !(await visivel(B + ' .pb-detalhes')) && /order bump do fluxo/i.test(await js(`${q(B + ' .pb-dica')}.text()`)));
        chk('plano de assinatura recorrente NÃO mostra o order bump personalizado', !(await visivel('#lista-planos .painel-plano:eq(1) .plano-bump')));

        await clicar(B + ' .pb-ativo');
        chk('ligar abre o formulário e avisa que substitui o do fluxo e que aparece ao escolher o plano, antes do Pix', (await visivel(B + ' .pb-detalhes')) && /substitui o do fluxo/i.test(await js(`${q(B + ' .pb-dica')}.text()`)) && /antes do Pix/i.test(await js(`${q(B + ' .pb-dica')}.text()`)));
        const rotulos = JSON.parse(await js(`JSON.stringify(${q(B + ' .pb-detalhes label')}.map(function(){ return this.textContent.trim(); }).get())`));
        chk('campos como na Shark: Nome, Preço, Descrição, Botão aceitar/recusar, CTA, Entrega e Mídias',
            ['Nome', 'Preço (R$)', 'Descrição', 'Botão aceitar', 'Botão recusar', 'Mensagem CTA (opcional)', 'Entrega do order bump', 'Mídias (até 3)'].every(r => rotulos.includes(r)), rotulos);
        chk('botões com os textos padrão como sugestão (✅ ADICIONAR / ❌ NÃO QUERO)', (await js(`${q(B + ' .pb-aceitar')}.attr('placeholder')`)) === '✅ ADICIONAR' && (await js(`${q(B + ' .pb-recusar')}.attr('placeholder')`)) === '❌ NÃO QUERO');
        const opcoes = JSON.parse(await js(`JSON.stringify(${q(B + ' .entrega-select option')}.map(function(){ return this.textContent.trim(); }).get())`));
        chk('Entrega do bump: "Mesmo delivery do plano" primeiro, sem opção de grupo', opcoes[0] === 'Mesmo delivery do plano' && !opcoes.some(o => /grupo/i.test(o)) && (await js(`${q(B + ' .entrega-select')}.val()`)) === '__plano__', opcoes);
        chk('o campo Entrega do PLANO continua separado (o do bump não o atrapalha)', (await js(`${q(P + ' .plano-corpo > .grade .entrega-select')}.length`)) === 1);

        // salvar com preço zero: recusa
        let dados = await salvar();
        chk('ligado sem preço: não salva e pede o preço do order bump daquele plano', dados === null && /preço do order bump do plano "Mensal"/.test(await toast()), await toast());

        await digitar(B + ' .pb-nome', 'Pack extra');
        await digitar(B + ' .pb-valor', '12.5');
        await digitar(B + ' .pb-mensagem', 'Leve também o pack extra');
        await digitar(B + ' .pb-cta', '👆 CLIQUE ANTES QUE TIREM DO AR');
        await subir(B + ' .pb-midia-arquivo', ['foto1.png', 'video1.mp4']);
        chk('duas mídias anexadas aparecem na lista e o botão segue ativo (cabe mais 1)', (await js(`${q(B + ' .pb-midias .basico-bot')}.length`)) === 2 && !(await js(`${q(B + ' .pb-midia-adicionar')}.prop('disabled')`)));
        await subir(B + ' .pb-midia-arquivo', ['foto2.jpg', 'foto3.png']);
        chk('o limite é 3 mídias: a 4ª fica de fora e o botão desabilita', (await js(`${q(B + ' .pb-midias .basico-bot')}.length`)) === 3 && (await js(`${q(B + ' .pb-midia-adicionar')}.prop('disabled')`)));
        await clicar(B + ' .pb-midias .basico-bot:eq(1) .pb-midia-remover');
        chk('tirar uma mídia libera a vaga', (await js(`${q(B + ' .pb-midias .basico-bot')}.length`)) === 2 && !(await js(`${q(B + ' .pb-midia-adicionar')}.prop('disabled')`)));
        await js(`${q(P)}.get(0).scrollIntoView({block:'start'})`);
        await esp(200);
        await c.captura(SAIDA + '/1_order_bump_plano.png');

        dados = await salvar();
        let p1 = dados && dados.dados_fluxograma.planos[0];
        chk('salvar: o plano grava order_bump com tudo (nome, valor, descrição, CTA, entrega "plano", 2 mídias)',
            !!p1 && !!p1.order_bump && p1.order_bump.ativo === true && p1.order_bump.nome === 'Pack extra' && p1.order_bump.valor_extra === 12.5 && p1.order_bump.mensagem === 'Leve também o pack extra'
            && p1.order_bump.cta === '👆 CLIQUE ANTES QUE TIREM DO AR' && p1.order_bump.entrega_tipo === 'plano' && p1.order_bump.midias.length === 2, p1 && p1.order_bump);
        chk('a entrega do PLANO não foi trocada pela do bump (continua "nenhuma")', !!p1 && p1.entrega_tipo === 'nenhuma', p1 && p1.entrega_tipo);
        const p2 = dados && dados.dados_fluxograma.planos[1];
        chk('o plano recorrente não grava order bump ligado', !!p2 && (!p2.order_bump || p2.order_bump.ativo === false), p2);

        // entrega por mensagem do bump
        await escolher(B + ' .entrega-select', '__mensagem__');
        dados = await salvar();
        chk('entrega do bump por mensagem sem texto: não salva e cita o order bump do plano', dados === null && /order bump do plano "Mensal"/.test(await toast()), await toast());
        await digitar(B + ' .entrega-mensagem', 'Seu extra: https://x.com/extra');
        dados = await salvar();
        p1 = dados && dados.dados_fluxograma.planos[0];
        chk('com mensagem: grava entrega_tipo=mensagem e o texto no bump (a do plano segue "nenhuma")', !!p1 && p1.order_bump.entrega_tipo === 'mensagem' && p1.order_bump.mensagem_entrega === 'Seu extra: https://x.com/extra' && p1.entrega_tipo === 'nenhuma', p1);

        // desligar mantém os dados, mas ativo=false
        await clicar(B + ' .pb-ativo');
        chk('desligar esconde o formulário e volta o aviso do fluxo', !(await visivel(B + ' .pb-detalhes')) && /order bump do fluxo/i.test(await js(`${q(B + ' .pb-dica')}.text()`)));
        dados = await salvar();
        p1 = dados && dados.dados_fluxograma.planos[0];
        chk('desligado: salva ativo=false (o do fluxo volta a valer) e não exige preço', !!p1 && p1.order_bump && p1.order_bump.ativo === false);

        // reabrir um fluxo que já tem bump no plano
        const fluxo2 = JSON.parse(JSON.stringify(FLUXO));
        fluxo2.dados_fluxograma.planos[0].order_bump = { ativo: true, nome: 'Guia', valor_extra: 7, mensagem: 'Descrição', cta: 'CTA', texto_aceitar: 'QUERO', texto_recusar: 'NÃO', entrega_tipo: 'plano', midias: [{ tipo: 'image', path: 'uploads/a.jpg' }] };
        await abrir(fluxo2);
        await secao('planos');
        chk('reaberto: o bloco abre ligado com os dados salvos (nome, preço, descrição, CTA, botões e 1 mídia)',
            (await js(`${q(B + ' .pb-ativo')}.is(':checked')`)) && (await visivel(B + ' .pb-detalhes')) && (await js(`${q(B + ' .pb-nome')}.val()`)) === 'Guia' && (await js(`${q(B + ' .pb-valor')}.val()`)) === '7'
            && (await js(`${q(B + ' .pb-mensagem')}.val()`)) === 'Descrição' && (await js(`${q(B + ' .pb-cta')}.val()`)) === 'CTA' && (await js(`${q(B + ' .pb-aceitar')}.val()`)) === 'QUERO'
            && (await js(`${q(B + ' .pb-midias .basico-bot')}.length`)) === 1);
        dados = await salvar();
        p1 = dados && dados.dados_fluxograma.planos[0];
        chk('reabrir e salvar sem mexer mantém tudo (inclusive a mídia)', !!p1 && p1.order_bump.nome === 'Guia' && p1.order_bump.valor_extra === 7 && p1.order_bump.midias.length === 1 && p1.order_bump.midias[0].path === 'uploads/a.jpg', p1 && p1.order_bump);

        // adicionar e remover plano não perde o bump dos outros
        await clicar('#btn-adicionar-plano');
        const nomes = JSON.parse(await js(`JSON.stringify(${q('#lista-planos .plano-bump .pb-nome')}.map(function(){ return this.value; }).get())`));
        chk('adicionar um plano novo não apaga o order bump do plano que já tinha', nomes.includes('Guia'), nomes);

        // celular
        await abrir(fluxo2, 390);
        await secao('planos');
        const largura = JSON.parse(await js(`JSON.stringify({ doc: document.documentElement.scrollWidth, jan: window.innerWidth })`));
        chk('no celular (390 px) o bloco cabe sem rolar para o lado', largura.doc <= largura.jan + 1, largura);
        await js(`${q(B)}.get(0).scrollIntoView({block:'start'})`);
        await esp(200);
        await c.captura(SAIDA + '/2_celular.png');
    } catch (e) {
        falhas++;
        console.log('ERRO no teste: ' + (e && e.stack || e));
    }
    console.log('\n' + ok + ' ok, ' + falhas + ' falha(s)   (capturas em ' + SAIDA + ')');
    await c.fechar();
    process.exit(falhas ? 1 : 0);
})();
