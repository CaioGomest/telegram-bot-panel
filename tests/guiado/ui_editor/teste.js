// Teste de tela do editor do Fluxo Guiado no Chrome de verdade, SEM servidor, banco nem login: gerar.php monta o HTML do fluxo_basico.php real e a API
// é simulada por api_falsa.js (o que seria salvo fica em window.__salvo). Precisa do Chrome instalado e de internet (jQuery pela CDN, como a página real).
// Cobre: Boas-vindas com várias mídias (álbum) e Order Bump em sequência.
//   node tests/guiado/ui_editor/teste.js      (sai com código 1 se algo falhar; capturas de tela na pasta temporária, caminho impresso no fim)
const fs = require('fs');
const os = require('os');
const { spawnSync } = require('child_process');
const RAIZ = require('path').resolve(__dirname, '../../..').replace(/\\/g, '/');
const { lancar } = require(RAIZ + '/tests/homologacao/ui/cdp.js');
const MIDIA = RAIZ + '/tests/homologacao/ui/midia/';
const SAIDA = (os.tmpdir() + '/editor_guiado_ui').replace(/\\/g, '/');
fs.mkdirSync(SAIDA, { recursive: true });
const gerado = spawnSync('php', [__dirname + '/gerar.php', SAIDA], { encoding: 'utf8' });
if (gerado.status !== 0) { console.log('gerar.php falhou:\n' + gerado.stdout + gerado.stderr); process.exit(1); }
const URL = 'file:///' + SAIDA + '/index.html';

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
    async function abrir(fluxo) {
        await c.enviar('Page.addScriptToEvaluateOnNewDocument', { source: fluxo ? `window.__fluxo_pre = ${JSON.stringify(fluxo)};` : 'window.__fluxo_pre = null;' });
        await c.ir(URL + (fluxo ? '?id=5' : ''));
        await c.esperar(`typeof jQuery!=='undefined' && jQuery('#bp-adicionar').length>0`, 15000);
        await esp(600);
    }
    async function secao(nome) { await js(`${q('.basico-nav-item[data-secao="' + nome + '"]')}.get(0).click()`); await esp(200); }
    async function definir(sel, valor) {
        const r = await js(`(function(){ var el = ${q(sel)}.get(0); if (!el) return 'sem elemento'; el.value = ${JSON.stringify(String(valor))}; el.dispatchEvent(new Event('input',{bubbles:true})); el.dispatchEvent(new Event('change',{bubbles:true})); return 'ok'; })()`);
        if (r !== 'ok') throw new Error('definir: ' + r + ' em ' + sel);
    }
    async function clicar(sel) {
        const r = await js(`(function(){ var el = ${q(sel)}.get(0); if (!el) return 'sem elemento'; el.click(); return 'ok'; })()`);
        if (r !== 'ok') throw new Error('clicar: ' + r + ' em ' + sel);
        await esp(150);
    }
    async function marcar(sel, estado) {
        await js(`(function(){ var el = ${q(sel)}.get(0); if (el.checked !== ${!!estado}) el.click(); })()`);
        await esp(100);
    }
    async function subir(sel, arquivo) {
        const marca = 'zt' + Math.floor(Math.random() * 1e9);
        const r = await js(`(function(){ var el = ${q(sel)}.get(0); if (!el) return 'sem elemento'; el.setAttribute('data-zt','${marca}'); return 'ok'; })()`);
        if (r !== 'ok') throw new Error('subir: ' + r + ' em ' + sel);
        const doc = await c.enviar('DOM.getDocument', { depth: 0 });
        const no = await c.enviar('DOM.querySelector', { nodeId: doc.result.root.nodeId, selector: `[data-zt="${marca}"]` });
        await c.enviar('DOM.setFileInputFiles', { nodeId: no.result.nodeId, files: [MIDIA + arquivo] });
        await esp(350);
    }
    async function salvar() {
        await js(`window.__salvo = null`);
        await clicar('#btn-salvar-fluxo-basico');
        await esp(300);
        return JSON.parse(await js(`JSON.stringify(window.__salvo)`));
    }
    const toast = () => js(`${q('#toast')}.text()`);
    async function captura(nome, seletor) {
        await js(`${q(seletor)}.get(0).scrollIntoView({block:'start'})`);
        await esp(200);
        await c.captura(SAIDA + '/' + nome);
    }

    try {
        // =============== BOAS-VINDAS ===============
        await abrir(null);
        await secao('boasvindas');
        chk('fluxo novo: sem mídia, o botão de adicionar outra mídia fica escondido', !(await js(`${q('#bv-midias-adicionar')}.is(':visible')`)) && (await js(`${q('#bv-midia-previa')}.attr('data-estado')`)) === 'vazio');
        await subir('#bv-midia-arquivo', 'foto1.png');
        chk('1ª mídia na caixa grande (foto anexada), botão de adicionar outra aparece à direita', (await js(`${q('#bv-midia-previa')}.attr('data-estado')`)) === 'anexada' && (await js(`${q('#bv-midia-previa')}.attr('data-tipo')`)) === 'image' && (await js(`${q('#bv-midias-adicionar')}.is(':visible')`)));
        chk('o botão de adicionar está encostado na direita do cartão', await js(`(function(){ var b = ${q('#bv-midias-adicionar')}.get(0).getBoundingClientRect(), p = ${q('#bv-midias-adicionar')}.closest('.painel').get(0).getBoundingClientRect(); return p.right - b.right < 40 && b.left > p.left + p.width/2; })()`));
        await clicar('#bv-midias-adicionar');
        await subir('#bv-midia-arquivo', 'video1.mp4');
        await clicar('#bv-midias-adicionar');
        await subir('#bv-midia-arquivo', 'foto2.jpg');
        await clicar('#bv-midias-adicionar');
        await subir('#bv-midia-arquivo', 'foto3.png');
        const linhas = async () => JSON.parse(await js(`JSON.stringify(${q('#bv-midias-extras .basico-bot')}.map(function(){ return $(this).find('span').first().text().replace(/\\s+/g,' ').trim(); }).get())`));
        let l = await linhas();
        chk('3 mídias extras listadas em ordem: 2. Vídeo, 3. Foto, 4. Foto', l.length === 3 && /^2\. Vídeo/.test(l[0]) && /^3\. Foto/.test(l[1]) && /^4\. Foto/.test(l[2]), l);
        await captura('boasvindas_4_midias.png', '#bv-mensagem');
        chk('Boas-vindas: a seta "Subir" da lista não tem borda (a lixeira ao lado mantém a dela)', (await js(`JSON.stringify((function(){ var cs = getComputedStyle(jQuery("#bv-midias-extras .basico-bot:eq(1) .bv-subir").get(0)); return cs.borderTopWidth + ' ' + cs.borderRightWidth + ' ' + cs.borderBottomWidth + ' ' + cs.borderLeftWidth; })())`)) === '"0px 0px 0px 0px"' && (await js(`JSON.stringify((function(){ var cs = getComputedStyle(jQuery("#bv-midias-extras .basico-bot:eq(1) .btn-icon.excluir").get(0)); return cs.borderTopWidth + ' ' + cs.borderRightWidth + ' ' + cs.borderBottomWidth + ' ' + cs.borderLeftWidth; })())`)) !== '"0px 0px 0px 0px"');
        // subir a 4ª para antes da 3ª
        await js(`${q('#bv-midias-extras .basico-bot:eq(2) .bv-subir')}.get(0).click()`);
        l = await linhas();
        chk('"Subir" troca a ordem (a foto3 passa a ser a 3ª)', /foto3|^3\. Foto/.test(l[1]) && l.length === 3, l);
        // remover a do meio
        await js(`${q('#bv-midias-extras .basico-bot:eq(1) .bv-remover')}.get(0).click()`);
        l = await linhas();
        chk('remover tira da lista e renumera (2. Vídeo, 3. Foto)', l.length === 2 && /^2\. Vídeo/.test(l[0]) && /^3\. Foto/.test(l[1]), l);
        // trocar a 1ª
        const nome_antes = await js(`${q('#bv-midia-previa small')}.text()`);
        await clicar('#bv-midia-previa .midia-trocar');
        await subir('#bv-midia-arquivo', 'foto2.png');
        l = await linhas();
        const nome_depois = await js(`${q('#bv-midia-previa small')}.text()`);
        chk('"Trocar" na caixa grande troca só a 1ª (as extras ficam: continuam 2)', l.length === 2 && nome_depois !== nome_antes && /^foto\d+\.jpg$/.test(nome_depois), [l, nome_antes, nome_depois]);
        let dados = await salvar();
        let bv = dados.dados_fluxograma.boas_vindas;
        chk('salvar: boas_vindas.midias com 3 itens, tipos em ordem', Array.isArray(bv.midias) && bv.midias.length === 3 && bv.midias.map(m => m.tipo).join() === 'image,video,image', bv.midias);
        chk('salvar: midia_tipo/midia_path repetem a 1ª mídia (compatível com o formato antigo)', bv.midia_path === bv.midias[0].path && bv.midia_tipo === 'image', bv);
        // tirar a 1ª: a seguinte vira a 1ª
        const segunda = bv.midias[1].path;
        await clicar('#bv-midia-previa .midia-tirar');
        dados = await salvar();
        bv = dados.dados_fluxograma.boas_vindas;
        chk('"Tirar" a 1ª: a 2ª sobe para a caixa grande e fica 2 no total', bv.midias.length === 2 && bv.midias[0].path === segunda && bv.midia_path === segunda && bv.midia_tipo === 'video', bv);
        // limite de 10
        for (let i = 0; i < 9; i++) { await clicar('#bv-midias-adicionar'); await subir('#bv-midia-arquivo', 'foto1.png'); }
        chk('limite de 10 mídias: o botão fica desabilitado e diz "Limite de 10 mídias"', (await js(`${q('#bv-midias-adicionar')}.prop('disabled')`)) && /Limite de 10/.test(await js(`${q('#bv-midias-adicionar')}.text()`)) && (await linhas()).length === 9);
        // fluxo antigo (uma mídia só) abre como lista de 1
        await abrir({ id: 5, nome: 'Antigo', modo: 'basico', dados_fluxograma: { ativo: true, boas_vindas: { mensagem: 'Oi', midia_tipo: 'video', midia_path: 'uploads/velho.mp4', mostrar_cta: true }, planos: [], ofertas: {} } });
        await secao('boasvindas');
        chk('fluxo salvo antes (só midia_tipo/midia_path): abre com 1 mídia na caixa grande e nenhuma extra', (await js(`${q('#bv-midia-previa')}.attr('data-tipo')`)) === 'video' && (await linhas()).length === 0 && (await js(`${q('#bv-midias-adicionar')}.is(':visible')`)), [await js(`${q('#bv-midia-previa')}.attr('data-tipo')`), await linhas(), c.errosJs()]);
        dados = await salvar();
        chk('e salvar de novo grava midias com a mesma mídia', JSON.stringify(dados.dados_fluxograma.boas_vindas.midias) === JSON.stringify([{ tipo: 'video', path: 'uploads/velho.mp4' }]), dados.dados_fluxograma.boas_vindas);

        // =============== ORDER BUMP ===============
        await abrir(null);
        await secao('orderbump');
        chk('fluxo novo: o "Quando enviar" começa em "Depois do Pix" e o tempo do 1º bump aparece', (await js(`${q('.painel[data-oferta="order_bump"] .of-quando')}.val()`)) === 'depois_pix');
        await marcar('.painel[data-oferta="order_bump"] .of-ativo', true);
        chk('ligado: aparecem o tempo, o título "Order Bump 1" e o botão de adicionar', (await js(`${q('.of-espera-campo')}.is(':visible')`)) && (await js(`${q('.of-detalhes > .bp-item-titulo')}.is(':visible')`)) && (await js(`${q('#bp-adicionar')}.is(':visible')`)));
        await definir('.of-nome', 'Bônus A'); await definir('.of-valor-extra', '9.9'); await definir('.of-espera', '5'); await definir('.painel[data-oferta="order_bump"] .of-mensagem', 'Leve o bônus A!');
        await clicar('#bp-adicionar'); await clicar('#bp-adicionar');
        const titulos = async () => JSON.parse(await js(`JSON.stringify(${q('#bp-extras .bp-item-titulo')}.map(function(){ return $(this).text(); }).get())`));
        chk('2 cartões novos: "Order Bump 2" e "Order Bump 3"', JSON.stringify(await titulos()) === JSON.stringify(['Order Bump 2', 'Order Bump 3']));
        const cartao = (i, s) => `#bp-extras .bp-item:eq(${i}) ${s}`;
        await definir(cartao(0, '.bp-nome'), 'Bônus B'); await definir(cartao(0, '.bp-valor'), '14.5'); await definir(cartao(0, '.bp-espera'), '15'); await definir(cartao(0, '.bp-mensagem'), 'Leve o B');
        await definir(cartao(1, '.bp-nome'), 'Bônus C'); await definir(cartao(1, '.bp-valor'), '4'); await definir(cartao(1, '.bp-espera'), '10'); await definir(cartao(1, '.bp-aceitar'), 'Quero C');
        await subir(cartao(1, '.bp-midia-arquivo'), 'foto1.png');
        chk('mídia do 3º order bump anexada no cartão dele', (await js(`${q(cartao(1, '.bp-midia'))}.attr('data-estado')`)) === 'anexada');
        chk('os campos digitados nos cartões sobrevivem a redesenhar a lista (adicionar outro)', await (async () => { await clicar('#bp-adicionar'); return (await js(`${q(cartao(0, '.bp-nome'))}.val()`)) === 'Bônus B' && (await js(`${q(cartao(1, '.bp-aceitar'))}.val()`)) === 'Quero C' && (await js(`${q(cartao(1, '.bp-midia'))}.attr('data-estado')`)) === 'anexada'; })());
        await clicar('#bp-adicionar');
        chk('limite de 5 order bumps: botão desabilitado, "Limite de 5 order bumps"', (await js(`${q('#bp-adicionar')}.prop('disabled')`)) && /Limite de 5/.test(await js(`${q('#bp-adicionar')}.text()`)) && (await titulos()).length === 4);
        await captura('orderbump_5_itens.png', '.bp-quando-grade');
        // remover o 4º (vazio) e o 5º (vazio)
        await js(`${q(cartao(3, '.bp-remover'))}.get(0).click()`); await js(`${q(cartao(2, '.bp-remover'))}.get(0).click()`);
        chk('remover tira o cartão e renumera os títulos (sobram 2 e 3)', JSON.stringify(await titulos()) === JSON.stringify(['Order Bump 2', 'Order Bump 3']) && !(await js(`${q('#bp-adicionar')}.prop('disabled')`)));
        dados = await salvar();
        let bump = dados.dados_fluxograma.ofertas.order_bump;
        chk('salvar: quando=depois_pix, ativo, 3 itens na ordem com nome, valor e tempo de cada um',
            bump.ativo === true && bump.quando === 'depois_pix' && bump.itens.length === 3 && bump.itens.map(i => i.nome).join() === 'Bônus A,Bônus B,Bônus C'
            && bump.itens.map(i => i.valor_extra).join() === '9.9,14.5,4' && bump.itens.map(i => i.espera_minutos).join() === '5,15,10', bump);
        chk('salvar: o 1º item repete os campos do próprio order bump (nome, valor, mensagem, tempo) e a mídia do 3º item vai nele', bump.nome === 'Bônus A' && bump.valor_extra === 9.9 && bump.mensagem === 'Leve o bônus A!' && bump.espera_minutos === 5 && bump.itens[0].mensagem === 'Leve o bônus A!' && /^uploads\/foto/.test(bump.itens[2].midia_path) && bump.itens[2].midia_tipo === 'image' && bump.itens[2].texto_aceitar === 'Quero C', bump);
        // valor extra zerado num extra
        await definir(cartao(0, '.bp-valor'), '0');
        dados = await salvar();
        chk('order bump extra sem valor: não salva e avisa "Informe o valor extra do Order Bump 2 ou remova-o."', dados === null && /Order Bump 2 ou remova/.test(await toast()), await toast());
        await definir(cartao(0, '.bp-valor'), '14.5');
        // "Depois do pagamento": esconde tempo, títulos, cartões e botão
        await definir('.painel[data-oferta="order_bump"] .of-quando', 'depois_pagamento');
        chk('"Depois que o cliente paga": somem o tempo, o título, os cartões e o botão de adicionar', !(await js(`${q('.of-espera-campo')}.is(':visible')`)) && !(await js(`${q('#bp-extras')}.is(':visible')`)) && !(await js(`${q('#bp-adicionar')}.is(':visible')`)) && !(await js(`${q('.of-detalhes > .bp-item-titulo')}.is(':visible')`)));
        await captura('orderbump_depois_pagamento.png', '.bp-quando-grade');
        dados = await salvar();
        bump = dados.dados_fluxograma.ofertas.order_bump;
        chk('salvar em "depois do pagamento": quando=depois_pagamento e o 1º bump segue nos campos de sempre (a sequência fica guardada, sem valer)', bump.quando === 'depois_pagamento' && bump.nome === 'Bônus A' && bump.valor_extra === 9.9 && bump.itens.length === 3, bump);
        await definir('.painel[data-oferta="order_bump"] .of-quando', 'depois_pix');
        chk('voltar para "Depois do Pix" traz de volta os cartões já preenchidos', (await titulos()).length === 2 && (await js(`${q(cartao(0, '.bp-nome'))}.val()`)) === 'Bônus B');

        // fluxo salvo antes da sequência: bump ligado e sem "quando" = depois do pagamento (não muda o comportamento em produção)
        await abrir({ id: 5, nome: 'Antigo', modo: 'basico', dados_fluxograma: { ativo: true, boas_vindas: {}, planos: [{ id: 'p1', nome: 'Mensal', valor: 20, dias_acesso: 30, unidade_acesso: 'dias' }], ofertas: { order_bump: { ativo: true, nome: 'Velho', valor_extra: 7.5, mensagem: 'Leve', texto_aceitar: 'Sim', texto_recusar: 'Não', midia_tipo: 'image', midia_path: 'uploads/bump.jpg' } } } });
        await secao('orderbump');
        chk('order bump antigo ligado (sem "quando"): abre em "Depois que o cliente paga", sem cartões', (await js(`${q('.painel[data-oferta="order_bump"] .of-quando')}.val()`)) === 'depois_pagamento' && (await titulos()).length === 0 && (await js(`${q('.painel[data-oferta="order_bump"] .of-nome')}.val()`)) === 'Velho' && (await js(`${q('.painel[data-oferta="order_bump"] .of-midia')}.attr('data-estado')`)) === 'anexada');
        dados = await salvar();
        bump = dados.dados_fluxograma.ofertas.order_bump;
        chk('salvar sem mexer: continua "depois_pagamento", mesmo bump, 1 item na lista', bump.quando === 'depois_pagamento' && bump.valor_extra === 7.5 && bump.nome === 'Velho' && bump.itens.length === 1 && bump.midia_path === 'uploads/bump.jpg', bump);
        await abrir({ id: 5, nome: 'Antigo2', modo: 'basico', dados_fluxograma: { ativo: true, boas_vindas: {}, planos: [], ofertas: { order_bump: { ativo: false, mensagem: '' } } } });
        await secao('orderbump');
        chk('order bump antigo DESLIGADO (nada a preservar): abre já em "Depois do Pix"', (await js(`${q('.painel[data-oferta="order_bump"] .of-quando')}.val()`)) === 'depois_pix');
        // fluxo novo salvo e reaberto: restaura os cartões
        await abrir({ id: 5, nome: 'Novo', modo: 'basico', dados_fluxograma: { ativo: true, boas_vindas: {}, planos: [], ofertas: { order_bump: { ativo: true, quando: 'depois_pix', nome: 'A', valor_extra: 1.5, espera_minutos: 3, itens: [
            { nome: 'A', valor_extra: 1.5, espera_minutos: 3 }, { nome: 'B', valor_extra: 2.5, espera_minutos: 20, mensagem: 'MB', midia_tipo: 'video', midia_path: 'uploads/b.mp4', texto_recusar: 'Nem' } ] } } } });
        await secao('orderbump');
        chk('reabrir um fluxo com sequência: tempo do 1º = 3, 1 cartão com nome B, 20 min, vídeo anexado e botão recusar "Nem"',
            (await js(`${q('.painel[data-oferta="order_bump"] .of-espera')}.val()`)) === '3' && (await titulos()).length === 1 && (await js(`${q(cartao(0, '.bp-nome'))}.val()`)) === 'B' && (await js(`${q(cartao(0, '.bp-espera'))}.val()`)) === '20'
            && (await js(`${q(cartao(0, '.bp-midia'))}.attr('data-tipo')`)) === 'video' && (await js(`${q(cartao(0, '.bp-recusar'))}.val()`)) === 'Nem');
        await captura('orderbump_reaberto.png', '.bp-quando-grade');

        // =============== UPSELL e DOWNSELL com várias mídias ===============
        for (const oferta of ['upsell', 'downsell']) {
            const P = '.painel[data-oferta="' + oferta + '"]';
            const linhasOf = async () => JSON.parse(await js(`JSON.stringify(${q(P + ' .of-midias-extras .basico-bot')}.map(function(){ return $(this).find('span').first().text().replace(/\\s+/g,' ').trim(); }).get())`));
            await abrir(null);
            await secao(oferta);
            await marcar(P + ' .of-ativo', true);
            chk(oferta + ': sem mídia, o botão de adicionar outra fica escondido', !(await js(`${q(P + ' .of-midias-adicionar')}.is(':visible')`)) && (await js(`${q(P + ' .of-midia')}.attr('data-estado')`)) === 'vazio');
            await subir(P + ' .of-midia-arquivo', 'foto1.png');
            chk(oferta + ': 1ª mídia na caixa grande e o botão "+ Adicionar outra foto ou vídeo" aparece à direita', (await js(`${q(P + ' .of-midia')}.attr('data-estado')`)) === 'anexada' && (await js(`${q(P + ' .of-midias-adicionar')}.is(':visible')`))
                && (await js(`(function(){ var b = ${q(P + ' .of-midias-adicionar')}.get(0).getBoundingClientRect(), p = ${q(P)}.get(0).getBoundingClientRect(); return p.right - b.right < 60 && b.left > p.left + p.width/2; })()`)));
            await clicar(P + ' .of-midias-adicionar'); await subir(P + ' .of-midia-arquivo', 'video1.mp4');
            await clicar(P + ' .of-midias-adicionar'); await subir(P + ' .of-midia-arquivo', 'foto2.jpg');
            await clicar(P + ' .of-midias-adicionar'); await subir(P + ' .of-midia-arquivo', 'foto3.png');
            let l = await linhasOf();
            chk(oferta + ': 3 mídias extras em ordem: 2. Vídeo, 3. Foto, 4. Foto', l.length === 3 && /^2\. Vídeo/.test(l[0]) && /^3\. Foto/.test(l[1]) && /^4\. Foto/.test(l[2]), l);
            chk(oferta + ': a seta "Subir" da lista não tem borda', (await js(`JSON.stringify((function(){ var cs = getComputedStyle(jQuery(${JSON.stringify(P + ' .of-midias-extras .basico-bot:eq(1) .of-subir')}).get(0)); return cs.borderTopWidth + ' ' + cs.borderRightWidth + ' ' + cs.borderBottomWidth + ' ' + cs.borderLeftWidth; })())`)) === '"0px 0px 0px 0px"');
            await js(`${q(P + ' .of-midias-extras .basico-bot:eq(2) .of-subir')}.get(0).click()`);
            await js(`${q(P + ' .of-midias-extras .basico-bot:eq(1) .of-remover')}.get(0).click()`);
            l = await linhasOf();
            chk(oferta + ': "Subir" e "Remover" mexem só nesta oferta e renumeram (restam 2. Vídeo e 3. Foto)', l.length === 2 && /^2\. Vídeo/.test(l[0]) && /^3\. Foto/.test(l[1]), l);
            const outra = oferta === 'upsell' ? 'downsell' : 'upsell';
            chk(oferta + ': a outra oferta (' + outra + ') não ganhou mídia nenhuma', (await js(`${q('.painel[data-oferta="' + outra + '"] .of-midia')}.attr('data-estado')`)) === 'vazio');
            await captura(oferta + '_midias.png', P + ' .of-mensagem');
            // preencher o que a validação exige para poder salvar
            let dados = await salvar();
            let o = dados && dados.dados_fluxograma.ofertas[oferta];
            if (!dados) {
                // Upsell exige plano oferecido e o Downsell exige plano marcado: cria 1 plano e escolhe, como a pessoa faria
                await secao('planos');
                await clicar('#btn-adicionar-plano');
                await definir('#lista-planos .campo-plano-nome', 'Mensal'); await definir('#lista-planos .campo-plano-valor', '30');
                await secao(oferta);
                if (oferta === 'upsell') { await js(`(function(){ var el = ${q(P + ' .of-plano')}.get(0); el.selectedIndex = el.options.length - 1; el.dispatchEvent(new Event('change',{bubbles:true})); })()`); }
                else { await js(`${q(P + ' .of-planos-lista input')}.first().prop('checked', true).trigger('change')`); }
                dados = await salvar();
                o = dados && dados.dados_fluxograma.ofertas[oferta];
            }
            chk(oferta + ' salvar: midias com os 3 itens (foto, vídeo, foto) e midia_tipo/midia_path repetem a 1ª', !!o && Array.isArray(o.midias) && o.midias.length === 3 && o.midias.map(m => m.tipo).join() === 'image,video,image' && o.midia_tipo === 'image' && o.midia_path === o.midias[0].path, o);
            // tirar a 1ª: a 2ª sobe
            const segunda = o.midias[1].path;
            await clicar(P + ' .of-midia .midia-tirar');
            dados = await salvar();
            o = dados.dados_fluxograma.ofertas[oferta];
            chk(oferta + ': "Tirar" a 1ª: a 2ª sobe para a caixa grande e ficam 2 mídias (o vídeo vira a 1ª)', o.midias.length === 2 && o.midias[0].path === segunda && o.midia_path === segunda && o.midia_tipo === 'video' && (await linhasOf()).length === 1);
            // limite de 10
            for (let i = 0; i < 9; i++) { await clicar(P + ' .of-midias-adicionar'); await subir(P + ' .of-midia-arquivo', 'foto1.png'); }
            chk(oferta + ': limite de 10: botão desabilitado com "Limite de 10 mídias"', (await js(`${q(P + ' .of-midias-adicionar')}.prop('disabled')`)) && /Limite de 10/.test(await js(`${q(P + ' .of-midias-adicionar')}.text()`)));
            // oferta salva antes (só midia_tipo/midia_path)
            const ofertaAntiga = oferta === 'upsell' ? { ativo: true, id_plano_destino: 'p1', mensagem: 'x', midia_tipo: 'video', midia_path: 'uploads/velho.mp4' } : { ativo: true, planos_oferecidos: ['p1'], id_plano_destino: 'p1', mensagem: 'x', midia_tipo: 'video', midia_path: 'uploads/velho.mp4' };
            await abrir({ id: 5, nome: 'Antigo', modo: 'basico', dados_fluxograma: { ativo: true, boas_vindas: {}, planos: [{ id: 'p1', nome: 'Mensal', valor: 20, dias_acesso: 30, unidade_acesso: 'dias' }], ofertas: { [oferta]: ofertaAntiga } } });
            await secao(oferta);
            chk(oferta + ' salvo antes (só midia_tipo/midia_path): abre com 1 mídia (vídeo) na caixa grande e nenhuma extra', (await js(`${q(P + ' .of-midia')}.attr('data-tipo')`)) === 'video' && (await linhasOf()).length === 0 && (await js(`${q(P + ' .of-midias-adicionar')}.is(':visible')`)));
            dados = await salvar();
            chk(oferta + ' salvo antes: salvar de novo grava a lista com a mesma mídia', !!dados && JSON.stringify(dados.dados_fluxograma.ofertas[oferta].midias) === JSON.stringify([{ tipo: 'video', path: 'uploads/velho.mp4' }]), dados && dados.dados_fluxograma.ofertas[oferta]);
        }
        // o Order Bump segue com 1 mídia só (não ganhou a lista)
        await abrir(null);
        await secao('orderbump');
        await marcar('.painel[data-oferta="order_bump"] .of-ativo', true);
        await subir('.painel[data-oferta="order_bump"] .of-midia-arquivo', 'foto1.png');
        chk('Order Bump: 1 mídia na caixa e SEM botão de adicionar outra (segue com 1 só)', (await js(`${q('.painel[data-oferta="order_bump"] .of-midia')}.attr('data-estado')`)) === 'anexada' && (await js(`${q('.painel[data-oferta="order_bump"] .of-midias-adicionar')}.length`)) === 0);

        await secao('previas');
        await marcar('#pv-ativo', true);
        await clicar('#pv-adicionar');
        await subir('#pv-arquivo', 'foto1.png');
        await clicar('#pv-adicionar');
        await subir('#pv-arquivo', 'foto2.jpg');
        chk('Prévias: a seta "Subir" da lista não tem borda (e a da 1ª linha, sem nada acima, fica desabilitada)', (await js(`${q('#pv-lista .pv-subir')}.length`)) === 2 && (await js(`JSON.stringify((function(){ var cs = getComputedStyle(jQuery("#pv-lista .pv-subir:eq(1)").get(0)); return cs.borderTopWidth + ' ' + cs.borderRightWidth + ' ' + cs.borderBottomWidth + ' ' + cs.borderLeftWidth; })())`)) === '"0px 0px 0px 0px"' && (await js(`${q('#pv-lista .pv-subir:eq(0)')}.prop('disabled')`)) === true);
        await captura('previas_subir_sem_borda.png', '#pv-lista');
        const erros = c.errosJs();
        chk('nenhum erro de JavaScript na página durante todo o teste', erros.length === 0, erros);
    } catch (e) {
        falhas++;
        console.log('ERRO NO TESTE: ' + (e && e.stack || e));
    } finally {
        await c.fechar();
    }
    console.log('\n' + ok + ' ok, ' + falhas + ' falha(s)');
    process.exit(falhas ? 1 : 0);
})();
