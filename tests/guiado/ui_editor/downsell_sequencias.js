// Downsell em sequência e planos criados no Downsell (item 38, 27/10/2026, pedido do dono, no jeito das "Sequências de Downsell" da SharkBot): o Downsell 1 é o painel fixo da seção,
// os downsells 2 a 5 são cartões (como os Order Bumps 2 a 5) e, em cada um, a chave "Criar planos" troca os planos marcados da aba Planos por planos criados ali, num cartão
// igual ao da aba Planos. Chrome real, sem servidor (gerar.php monta o fluxo_basico.php de verdade; api_falsa.js simula a API).
//   node tests/guiado/ui_editor/downsell_sequencias.js      (sai com código 1 se algo falhar; capturas na pasta temporária)
const fs = require('fs');
const os = require('os');
const { spawnSync } = require('child_process');
const RAIZ = require('path').resolve(__dirname, '../../..').replace(/\\/g, '/');
const { lancar } = require(RAIZ + '/tests/homologacao/ui/cdp.js');
const SAIDA = (os.tmpdir() + '/editor_guiado_downsell_sequencias').replace(/\\/g, '/');
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
    const D = '.painel[data-oferta="downsell"]';
    const S1 = '#dn-seq-1';
    const P1 = '#dn-planos-1';
    const X = (i) => '#dn-extras .dn-extra:eq(' + i + ')';
    async function planos() {
        await secao('planos');
        await clicar('#btn-adicionar-plano');
        await js(`${q('#lista-planos .painel-plano:eq(0) .campo-plano-nome')}.val('Mensal').trigger('input'); ${q('#lista-planos .painel-plano:eq(0) .campo-plano-valor')}.val(29.9).trigger('input');`);
        await clicar('#btn-adicionar-plano');
        await js(`${q('#lista-planos .painel-plano:eq(1) .campo-plano-nome')}.val('Anual').trigger('input'); ${q('#lista-planos .painel-plano:eq(1) .campo-plano-valor')}.val(99).trigger('input');`);
    }
    async function ligarDownsell() {
        await secao('downsell');
        await clicar(D + ' .of-ativo');
    }

    try {
        // ============ fluxo novo: o Downsell 1 ============
        await abrir(null, GRUPOS);
        await planos();
        await ligarDownsell();
        chk('ao ligar o Downsell (depois do Pix): aparece o título "Downsell 1" e o botão "+ Adicionar downsell"; ainda sem cartões extras',
            (await visivel(D + ' .dn-titulo-1')) && (await texto(D + ' .dn-titulo-1')) === 'Downsell 1' && (await visivel('#dn-adicionar')) && (await texto('#dn-adicionar')) === '+ Adicionar downsell' && (await qtd('#dn-extras .dn-extra')) === 0);
        chk('o bloco "Planos oferecidos" tem a chave "Criar planos" (desligada) e lista os 2 planos que já existem para marcar',
            (await texto(P1 + ' .dn-planos-titulo')) === 'Planos oferecidos' && (await texto(P1 + ' .dn-criar-chave')) === 'Criar planos' && !(await js(`${q(P1 + ' .dn-criar')}.is(':checked')`)) && (await qtd(P1 + ' .of-planos-lista input[type=checkbox]')) === 2);
        chk('com a chave desligada aparecem a lista dos planos marcáveis, o Desconto e a Entrega, e não aparece a área de criar', (await visivel(P1 + ' .dn-planos-marcados')) && (await visivel(S1 + ' .of-desconto')) && (await visivel(S1 + ' .of-entrega .entrega-select')) && !(await visivel(P1 + ' .dn-planos-criados')));
        chk('"Quantos envios" aparece (nenhum downsell extra ainda)', await visivel(D + ' .of-envios'));

        // ---- "Criar planos" no Downsell 1
        await clicar(P1 + ' .dn-criar');
        chk('ligar "Criar planos": some a lista dos planos marcados, o Desconto e a Entrega da oferta, e aparece a área de criar', !(await visivel(P1 + ' .dn-planos-marcados')) && !(await visivel(S1 + ' .of-desconto')) && !(await visivel(S1 + ' .of-entrega .entrega-select')) && (await visivel(P1 + ' .dn-planos-criados')));
        chk('já nasce o 1º plano, vazio, com o cartão da aba Planos (cabeçalho "Plano sem nome", resumo "R$ 0,00 · 30 dias", seta) e o foco no nome',
            (await qtd(P1 + ' .dn-proprio')) === 1 && (await js(`${q(P1 + ' .dn-proprio')}.hasClass('painel-plano')`)) && (await texto(P1 + ' .plano-resumo-nome')) === 'Plano sem nome' && (await texto(P1 + ' .plano-resumo-info')) === 'R$ 0,00 · 30 dias'
            && (await qtd(P1 + ' .plano-alternar')) === 1 && (await js(`document.activeElement && document.activeElement.className`)) === 'dn-p-nome');
        chk('o cartão tem Nome, Valor, Tempo de acesso (30 dias) e a Entrega (Nenhum, grupo, link, mensagem; sem "Igual à do plano oferecido")',
            (await qtd(P1 + ' .dn-p-nome')) === 1 && (await qtd(P1 + ' .dn-p-valor')) === 1 && (await valor(P1 + ' .dn-p-dias')) === '30' && (await valor(P1 + ' .dn-p-unidade')) === 'dias'
            && JSON.stringify(await js(`JSON.stringify(${q(P1 + ' .entrega-select option')}.map(function(){ return this.textContent.trim(); }).get())`)) === JSON.stringify(JSON.stringify(['Nenhum (apenas venda)', '👥 Acesso a grupo', '🔗 Link (enviado ao cliente)', '💬 Mensagem (texto enviado ao cliente)'])));
        // a moldura é a de um cartão da aba Planos
        const estilo = (sel) => js(`(function(){ var el = ${q(sel)}.get(0); var c = getComputedStyle(el); return c.borderTopWidth + ' ' + c.borderTopStyle + ' ' + c.borderTopLeftRadius + ' ' + c.paddingTop; })()`);
        const estiloProprio = await estilo(P1 + ' .dn-proprio');
        await secao('planos');
        const estiloPlano = await estilo('#lista-planos .painel-plano:first');
        await secao('downsell');
        chk('a moldura (borda, canto arredondado e margem interna) é a mesma de um cartão da aba Planos', estiloProprio === estiloPlano && /1px solid/.test(estiloProprio), [estiloProprio, estiloPlano]);
        chk('o plano marcado/criado não vai para a lista de planos do bot (continua só Mensal e Anual)', (await qtd('#lista-planos .painel-plano')) === 2);

        await digitar(P1 + ' .dn-proprio:eq(0) .dn-p-nome', 'Oferta relâmpago');
        await digitar(P1 + ' .dn-proprio:eq(0) .dn-p-valor', 9.9);
        await digitar(P1 + ' .dn-proprio:eq(0) .dn-p-dias', 3);
        await escolher(P1 + ' .dn-proprio:eq(0) .dn-p-unidade', 'horas');
        chk('o resumo do cabeçalho acompanha o que se digita ("Oferta relâmpago", "R$ 9,90 · 3 horas")', (await texto(P1 + ' .dn-proprio:eq(0) .plano-resumo-nome')) === 'Oferta relâmpago' && (await texto(P1 + ' .dn-proprio:eq(0) .plano-resumo-info')) === 'R$ 9,90 · 3 horas');
        await clicar(P1 + ' .dn-proprio:eq(0) .plano-topo');
        chk('clicar no cabeçalho recolhe o cartão (some o formulário, fica o resumo) e clicar de novo expande, sem perder o que foi digitado', (await js(`${q(P1 + ' .dn-proprio:eq(0)')}.hasClass('recolhido')`)) && !(await visivel(P1 + ' .dn-proprio:eq(0) .plano-corpo')));
        await clicar(P1 + ' .dn-proprio:eq(0) .plano-topo');
        chk('…expandido de novo, o nome continua lá', (await visivel(P1 + ' .dn-proprio:eq(0) .plano-corpo')) && (await valor(P1 + ' .dn-proprio:eq(0) .dn-p-nome')) === 'Oferta relâmpago');

        // ---- "+ Criar plano": mais de um plano no mesmo downsell
        await clicar(P1 + ' .dn-proprio-adicionar');
        chk('"+ Criar plano" acrescenta outro cartão (2 planos neste downsell) sem mexer no primeiro', (await qtd(P1 + ' .dn-proprio')) === 2 && (await valor(P1 + ' .dn-proprio:eq(0) .dn-p-nome')) === 'Oferta relâmpago' && (await valor(P1 + ' .dn-proprio:eq(1) .dn-p-nome')) === '');
        await digitar(P1 + ' .dn-proprio:eq(1) .dn-p-nome', 'Bônus');
        await digitar(P1 + ' .dn-proprio:eq(1) .dn-p-valor', 5);
        await js(`${q(P1)}.get(0).scrollIntoView({block:'start'})`);
        await esp(200);
        await c.captura(SAIDA + '/1_criar_planos.png');

        // ---- validações da tela
        let dados = await salvar();
        chk('salvar com plano criado sem entrega: não bloqueia (o aviso "sem entrega" é só um alerta)', dados !== null, await toast());
        await digitar(P1 + ' .dn-proprio:eq(1) .dn-p-nome', '');
        dados = await salvar();
        chk('plano criado sem nome: não salva e diz qual (plano 2 do Downsell)', dados === null && (await toast()) === 'Dê um nome ao plano 2 do Downsell.', await toast());
        await digitar(P1 + ' .dn-proprio:eq(1) .dn-p-nome', 'Bônus');
        await digitar(P1 + ' .dn-proprio:eq(1) .dn-p-valor', 0);
        dados = await salvar();
        chk('plano criado com valor zero: não salva e cita o plano', dados === null && (await toast()) === 'O plano "Bônus" do Downsell precisa ter valor maior que zero.', await toast());
        await digitar(P1 + ' .dn-proprio:eq(1) .dn-p-valor', 1);
        dados = await salvar();
        chk('plano criado abaixo do valor mínimo da Omega (R$ 1,50): não salva', dados === null && /valor mínimo é R\$ 1,50/.test(await toast()), await toast());
        await digitar(P1 + ' .dn-proprio:eq(1) .dn-p-valor', 5);
        await escolher(P1 + ' .dn-proprio:eq(1) .entrega-select', '__link__');
        await digitar(P1 + ' .dn-proprio:eq(1) .entrega-link', 'isto não é um link');
        dados = await salvar();
        chk('plano criado com entrega por link sem endereço válido: não salva e cita o plano', dados === null && /A entrega por link do plano do Downsell "Bônus"/.test(await toast()), await toast());
        await digitar(P1 + ' .dn-proprio:eq(1) .entrega-link', 'https://area.exemplo.com/bonus');
        await escolher(P1 + ' .dn-proprio:eq(0) .entrega-select', '__grupo__');
        await escolher(P1 + ' .dn-proprio:eq(0) .entrega-grupo-select', '-1002222222222');

        // ---- salvar certo
        dados = await salvar();
        const d1 = dados && dados.dados_fluxograma.ofertas.downsell;
        chk('salvar: o Downsell guarda criar_planos e os 2 planos criados, cada um com nome, valor, tempo e a própria entrega', !!d1 && d1.criar_planos === true && d1.planos_proprios.length === 2
            && d1.planos_proprios[0].nome === 'Oferta relâmpago' && d1.planos_proprios[0].valor === 9.9 && d1.planos_proprios[0].dias_acesso === 3 && d1.planos_proprios[0].unidade_acesso === 'horas'
            && d1.planos_proprios[0].entrega_tipo === 'grupo' && d1.planos_proprios[0].id_grupo === '-1002222222222'
            && d1.planos_proprios[1].nome === 'Bônus' && d1.planos_proprios[1].valor === 5 && d1.planos_proprios[1].entrega_tipo === 'link' && d1.planos_proprios[1].link_entrega === 'https://area.exemplo.com/bonus', d1);
        chk('salvar: com "Criar planos" a entrega da oferta vai como "igual ao plano" e sem sequências extras; os planos do bot continuam só Mensal e Anual',
            !!d1 && d1.entrega_tipo === 'plano' && d1.id_grupo === '' && d1.link_entrega === '' && Array.isArray(d1.sequencias_extras) && d1.sequencias_extras.length === 0 && dados.dados_fluxograma.planos.length === 2, d1);

        // ---- remover plano criado e voltar para os marcados
        await clicar(P1 + ' .dn-proprio:eq(1) .dn-proprio-remover');
        chk('remover um plano criado tira só o cartão dele', (await qtd(P1 + ' .dn-proprio')) === 1 && (await valor(P1 + ' .dn-proprio:eq(0) .dn-p-nome')) === 'Oferta relâmpago');
        await clicar(P1 + ' .dn-proprio:eq(0) .dn-proprio-remover');
        chk('sem nenhum plano criado: aparece o aviso "Nenhum plano criado ainda" e o botão para criar continua', (await qtd(P1 + ' .dn-proprio')) === 0 && /Nenhum plano criado ainda/.test(await texto(P1 + ' .dn-proprios')) && (await visivel(P1 + ' .dn-proprio-adicionar')));
        dados = await salvar();
        chk('"Criar planos" ligado e nenhum plano: não salva e pede pelo menos 1', dados === null && (await toast()) === 'Crie pelo menos 1 plano no Downsell (ou desligue "Criar planos" e marque os planos que já existem).', await toast());
        await clicar(P1 + ' .dn-criar');
        chk('desligar "Criar planos" volta a lista dos planos marcados, o Desconto e a Entrega', (await visivel(P1 + ' .dn-planos-marcados')) && (await visivel(S1 + ' .of-desconto')) && (await visivel(S1 + ' .of-entrega .entrega-select')) && !(await visivel(P1 + ' .dn-planos-criados')));
        dados = await salvar();
        chk('desligado e sem plano marcado: pede para marcar um (mensagem de sempre)', dados === null && (await toast()) === 'Marque pelo menos 1 plano oferecido no Downsell ou desative-o.', await toast());
        await marcar(P1 + ' .of-planos-lista input:eq(0)', true);
        dados = await salvar();
        chk('com 1 plano marcado salva e o JSON leva criar_planos falso (os cartões digitados ficam guardados, mas não valem)', !!dados && dados.dados_fluxograma.ofertas.downsell.criar_planos === false && dados.dados_fluxograma.ofertas.downsell.planos_oferecidos.length === 1, await toast());

        // ============ mais de um downsell (sequência) ============
        await abrir(null, GRUPOS);
        await planos();
        await ligarDownsell();
        await marcar(P1 + ' .of-planos-lista input:eq(0)', true);
        await digitar(D + ' .of-mensagem', 'Oferta do Downsell 1');
        await clicar('#dn-adicionar');
        chk('"+ Adicionar downsell" abre o cartão "Downsell 2" com o tempo (30 minutos), o bloco de planos com a chave "Criar planos", desconto, entrega, mensagem e mídia',
            (await qtd('#dn-extras .dn-extra')) === 1 && (await texto(X(0) + ' .bp-item-titulo')) === 'Downsell 2' && (await valor(X(0) + ' .dn-espera')) === '30' && (await texto(X(0) + ' .dn-criar-chave')) === 'Criar planos'
            && (await qtd(X(0) + ' .dn-planos-lista input[type=checkbox]')) === 2 && (await visivel(X(0) + ' .dn-desconto')) && (await visivel(X(0) + ' .dn-entrega .entrega-select')) && (await visivel(X(0) + ' .dn-mensagem')) && (await visivel(X(0) + ' .dn-midia')));
        chk('a entrega do cartão tem "Igual à do plano oferecido" (o padrão) e o "Quantos envios" da 1ª some (cada downsell sai uma vez)',
            (await valor(X(0) + ' .entrega-select')) === '__plano__' && !(await visivel(D + ' .of-envios')) && !(await visivel(D + ' .of-intervalo')));
        await digitar(X(0) + ' .dn-espera', 45);
        await digitar(X(0) + ' .dn-mensagem', 'Última chance!');
        await marcar(X(0) + ' .dn-planos-lista input:eq(1)', true);
        await digitar(X(0) + ' .dn-desconto', 25);
        await escolher(X(0) + ' .entrega-select', '__grupo__');
        await escolher(X(0) + ' .entrega-grupo-select', '-1001111111111');
        await subir(X(0) + ' .dn-midia-arquivo', 'foto1.png');
        chk('a mídia do cartão aparece anexada (foto) e dá para trocar/tirar', (await js(`${q(X(0) + ' .dn-midia')}.attr('data-estado')`)) === 'anexada' && (await qtd(X(0) + ' .dn-midia .midia-trocar')) === 1 && (await qtd(X(0) + ' .dn-midia .midia-tirar')) === 1);
        // ---- várias fotos/vídeos no mesmo downsell (item 39)
        const M0 = X(0) + ' .dn-midias-extras [data-i]';
        chk('com 1 mídia aparecem "+ Adicionar outra foto ou vídeo" e a dica do álbum; a lista das demais está vazia', (await visivel(X(0) + ' .dn-midias-adicionar')) && (await texto(X(0) + ' .dn-midias-adicionar')) === '+ Adicionar outra foto ou vídeo'
            && (await visivel(X(0) + ' .dn-midias-dica')) && (await qtd(M0)) === 0);
        await clicar(X(0) + ' .dn-midias-adicionar');
        chk('"+ Adicionar outra" abre o seletor aceitando vários arquivos de uma vez', (await js(`${q(X(0) + ' .dn-midia-arquivo')}.prop('multiple')`)) === true);
        await subirVarios(X(0) + ' .dn-midia-arquivo', ['foto2.jpg', 'foto3.png', 'video1.mp4'], 1500);
        chk('3 arquivos escolhidos juntos entram na ordem, depois da 1ª: 2. Foto, 3. Foto, 4. Vídeo', JSON.stringify(await rotulos(M0)) === JSON.stringify(['2. Foto', '3. Foto', '4. Vídeo']), await rotulos(M0));
        await clicar(X(0) + ' .dn-midias-extras [data-i="3"] .dn-midia-subir');
        chk('"Subir" no vídeo troca de lugar com a foto de cima: 2. Foto, 3. Vídeo, 4. Foto', JSON.stringify(await rotulos(M0)) === JSON.stringify(['2. Foto', '3. Vídeo', '4. Foto']), await rotulos(M0));
        await clicar(X(0) + ' .dn-midias-extras [data-i="2"] .dn-midia-remover');
        chk('remover um item da lista tira só ele (sobram 2. Foto e 3. Foto) e a 1ª, na caixa grande, continua', JSON.stringify(await rotulos(M0)) === JSON.stringify(['2. Foto', '3. Foto']) && (await js(`${q(X(0) + ' .dn-midia')}.attr('data-estado')`)) === 'anexada');
        await clicar(X(0) + ' .dn-midias-adicionar');
        await subirVarios(X(0) + ' .dn-midia-arquivo', ['foto1.png', 'foto2.png', 'foto3.png', 'foto2.jpg', 'foto1.png', 'foto2.png', 'foto3.png', 'foto2.jpg'], 3500);
        chk('limite: 8 arquivos com 3 já anexadas: entram 7 (fica em 10 no total), o botão vira "Limite de 10 mídias" desabilitado e o aviso diz que 1 ficou de fora',
            (await qtd(M0)) === 9 && (await texto(X(0) + ' .dn-midias-adicionar')) === 'Limite de 10 mídias' && (await js(`${q(X(0) + ' .dn-midias-adicionar')}.prop('disabled')`)) && /1 ficou de fora/.test(await toast()), [await qtd(M0), await toast()]);
        await clicar('#dn-adicionar');
        chk('o 3º downsell (cartão "Downsell 3") nasce vazio e o 2º continua com o que foi digitado (nada se perde ao acrescentar)', (await texto(X(1) + ' .bp-item-titulo')) === 'Downsell 3' && (await valor(X(0) + ' .dn-espera')) === '45' && (await valor(X(0) + ' .dn-mensagem')) === 'Última chance!'
            && (await js(`${q(X(0) + ' .dn-planos-lista input:eq(1)')}.is(':checked')`)) && (await valor(X(0) + ' .dn-desconto')) === '25' && (await valor(X(0) + ' .entrega-grupo-select')) === '-1001111111111');
        await clicar(X(1) + ' .dn-criar');
        chk('"Criar planos" no Downsell 3: some a lista, o desconto e a entrega DAQUELE cartão (o 2º não muda) e nasce o 1º plano criado', !(await visivel(X(1) + ' .dn-planos-marcados')) && !(await visivel(X(1) + ' .dn-desconto')) && !(await visivel(X(1) + ' .dn-entrega .entrega-select'))
            && (await qtd(X(1) + ' .dn-proprio')) === 1 && (await visivel(X(0) + ' .dn-planos-marcados')) && (await visivel(X(0) + ' .dn-desconto')));
        const M1 = X(1) + ' .dn-midias-extras [data-i]';
        chk('o Downsell 3 começa sem mídia (a do Downsell 2 não vaza para ele) e sem o botão "+ Adicionar outra"', (await js(`${q(X(1) + ' .dn-midia')}.attr('data-estado')`)) === 'vazio' && !(await visivel(X(1) + ' .dn-midias-adicionar')) && (await qtd(M1)) === 0);
        await clicar(X(1) + ' .dn-midia .midia-escolher');
        chk('com a caixa vazia o seletor aceita vários arquivos', (await js(`${q(X(1) + ' .dn-midia-arquivo')}.prop('multiple')`)) === true);
        await subirVarios(X(1) + ' .dn-midia-arquivo', ['foto2.jpg', 'video1.mp4'], 1500);
        chk('2 arquivos de uma vez na caixa vazia: o 1º na caixa grande e o 2º na lista (2. Vídeo); o Downsell 2 continua com as 10 dele', (await js(`${q(X(1) + ' .dn-midia')}.attr('data-estado')`)) === 'anexada' && JSON.stringify(await rotulos(M1)) === JSON.stringify(['2. Vídeo']) && (await qtd(M0)) === 9, await rotulos(M1));
        await clicar(X(1) + ' .dn-midia .midia-tirar');
        chk('"Tirar" a 1ª: a seguinte (o vídeo) passa a ser a 1ª e a lista fica vazia', (await js(`${q(X(1) + ' .dn-midia')}.attr('data-tipo')`)) === 'video' && (await qtd(M1)) === 0);
        await clicar(X(1) + ' .dn-midia .midia-trocar');
        chk('"Trocar" a 1ª aceita um arquivo só', (await js(`${q(X(1) + ' .dn-midia-arquivo')}.prop('multiple')`)) === false);
        await subir(X(1) + ' .dn-midia-arquivo', 'foto3.png');
        chk('trocar a 1ª põe a foto no lugar do vídeo, sem acrescentar item (continua 1 mídia)', (await js(`${q(X(1) + ' .dn-midia')}.attr('data-tipo')`)) === 'image' && (await qtd(M1)) === 0 && !(await js(`${q(X(1) + ' .dn-midia')}.html()`)).includes('video'));
        await digitar(X(1) + ' .dn-proprio:eq(0) .dn-p-nome', 'Último plano');
        await digitar(X(1) + ' .dn-proprio:eq(0) .dn-p-valor', 12.5);
        await digitar(X(1) + ' .dn-espera', 60);
        await digitar(X(1) + ' .dn-mensagem', 'Só hoje');
        await js(`${q('#dn-extras')}.get(0).scrollIntoView({block:'start'})`);
        await esp(200);
        await c.captura(SAIDA + '/2_sequencia.png');
        dados = await salvar();
        const dn = dados && dados.dados_fluxograma.ofertas.downsell;
        chk('salvar: o Downsell 1 leva a mensagem e os planos marcados; as sequências extras vão em sequencias_extras, na ordem da tela, com o que foi digitado em cada uma',
            !!dn && dn.mensagem === 'Oferta do Downsell 1' && dn.planos_oferecidos.length === 1 && dn.sequencias_extras.length === 2
            && dn.sequencias_extras[0].mensagem === 'Última chance!' && dn.sequencias_extras[0].espera_minutos === 45 && dn.sequencias_extras[0].desconto_percentual === 25 && dn.sequencias_extras[0].planos_oferecidos.length === 1 && dn.sequencias_extras[0].criar_planos === false
            && dn.sequencias_extras[0].entrega_tipo === 'grupo' && dn.sequencias_extras[0].id_grupo === '-1001111111111' && dn.sequencias_extras[0].midia_tipo === 'image' && /^uploads\/foto\d+\.jpg$/.test(dn.sequencias_extras[0].midia_path), dn);
        chk('salvar: o Downsell 2 leva as 10 mídias em "midias" (na ordem da tela) e a 1ª repetida em midia_tipo/midia_path', !!dn && dn.sequencias_extras[0].midias.length === 10 && dn.sequencias_extras[0].midias.every(function (m) { return (m.tipo === 'image' || m.tipo === 'video') && /^uploads\//.test(m.path); })
            && dn.sequencias_extras[0].midias[0].path === dn.sequencias_extras[0].midia_path && dn.sequencias_extras[0].midias[0].tipo === dn.sequencias_extras[0].midia_tipo, dn && dn.sequencias_extras[0].midias);
        chk('salvar: a 3ª sequência (planos criados) leva criar_planos, o plano com valor digitado e a entrega da sequência como "igual ao plano"',
            !!dn && dn.sequencias_extras[1].criar_planos === true && dn.sequencias_extras[1].espera_minutos === 60 && dn.sequencias_extras[1].planos_proprios.length === 1 && dn.sequencias_extras[1].planos_proprios[0].nome === 'Último plano'
            && dn.sequencias_extras[1].planos_proprios[0].valor === 12.5 && dn.sequencias_extras[1].entrega_tipo === 'plano' && dn.sequencias_extras[1].midias.length === 1 && dn.sequencias_extras[1].midia_tipo === 'image', dn && dn.sequencias_extras[1]);
        chk('salvar: com mais de um downsell o "quantos envios" da 1ª vai como 1 (cada um sai uma vez)', !!dn && dn.envios === 1, dn && dn.envios);

        // remover o do meio: renumera e mantém o resto
        await clicar(X(0) + ' .dn-remover');
        chk('remover o Downsell 2: o 3º vira o "Downsell 2" e guarda o que tinha (60 minutos, "Só hoje", o plano criado)', (await qtd('#dn-extras .dn-extra')) === 1 && (await texto(X(0) + ' .bp-item-titulo')) === 'Downsell 2'
            && (await valor(X(0) + ' .dn-espera')) === '60' && (await valor(X(0) + ' .dn-mensagem')) === 'Só hoje' && (await js(`${q(X(0) + ' .dn-midia')}.attr('data-estado')`)) === 'anexada' && (await valor(X(0) + ' .dn-proprio:eq(0) .dn-p-nome')) === 'Último plano');
        // limite de 5
        for (let i = 0; i < 3; i++) await clicar('#dn-adicionar');
        chk('limite: com 5 downsells no total o botão fica desabilitado e avisa "Limite de 5 downsells"', (await qtd('#dn-extras .dn-extra')) === 4 && (await js(`${q('#dn-adicionar')}.prop('disabled')`)) && (await texto('#dn-adicionar')) === 'Limite de 5 downsells');
        await clicar(X(3) + ' .dn-remover');
        chk('tirando um, o botão volta a "+ Adicionar downsell"', !(await js(`${q('#dn-adicionar')}.prop('disabled')`)) && (await texto('#dn-adicionar')) === '+ Adicionar downsell');

        // validações por cartão
        dados = await salvar();
        chk('cartão sem plano marcado: não salva e diz qual downsell', dados === null && /Marque pelo menos 1 plano oferecido no Downsell 3 ou remova-o\./.test(await toast()), await toast());
        await marcar(X(1) + ' .dn-planos-lista input:eq(0)', true);
        await marcar(X(2) + ' .dn-planos-lista input:eq(0)', true);
        await escolher(X(1) + ' .entrega-select', '__link__');
        await digitar(X(1) + ' .entrega-link', 'sem-endereco');
        dados = await salvar();
        chk('entrega por link inválida no Downsell 3: não salva e cita "do Downsell 3"', dados === null && /A entrega por link do Downsell 3 precisa/.test(await toast()), await toast());
        await digitar(X(1) + ' .entrega-link', 'https://promo.exemplo.com/d3');
        await clicar(X(2) + ' .dn-criar');
        await digitar(X(2) + ' .dn-proprio:eq(0) .dn-p-nome', 'Plano D4');
        await digitar(X(2) + ' .dn-proprio:eq(0) .dn-p-valor', 1);
        dados = await salvar();
        chk('plano criado no Downsell 4 abaixo do mínimo da Omega: não salva', dados === null && /valor mínimo é R\$ 1,50/.test(await toast()), await toast());
        await digitar(X(2) + ' .dn-proprio:eq(0) .dn-p-valor', 8);
        dados = await salvar();
        chk('corrigido, salva: 4 downsells no JSON (1 + 3 extras)', !!dados && dados.dados_fluxograma.ofertas.downsell.sequencias_extras.length === 3, await toast());

        // ---- "Antes do Pix": sem sequência
        await escolher(D + ' .of-quando', 'antes_pix');
        chk('em "Antes do Pix" somem o título "Downsell 1", os cartões e o botão de adicionar (a sequência só existe depois do Pix)', !(await visivel(D + ' .dn-titulo-1')) && !(await visivel('#dn-extras')) && !(await visivel('#dn-adicionar')));
        dados = await salvar();
        chk('salvar em "Antes do Pix": não leva sequências extras', !!dados && dados.dados_fluxograma.ofertas.downsell.quando === 'antes_pix' && dados.dados_fluxograma.ofertas.downsell.sequencias_extras.length === 0, await toast());
        await escolher(D + ' .of-quando', 'depois_pix');
        chk('voltando para "Depois do Pix" os cartões reaparecem com o que estava digitado', (await qtd('#dn-extras .dn-extra')) === 3 && (await visivel('#dn-adicionar')) && (await valor(X(0) + ' .dn-mensagem')) === 'Só hoje');

        // ============ fluxo salvo (round-trip) e fluxo antigo ============
        const base = { ativo: true, boas_vindas: {}, planos: [
            { id: 'p1', nome: 'Mensal', valor: 29.9, dias_acesso: 30, unidade_acesso: 'dias', entrega_tipo: 'nenhuma', id_grupo: '', link_entrega: '' },
            { id: 'p2', nome: 'Anual', valor: 99, dias_acesso: 365, unidade_acesso: 'dias', entrega_tipo: 'nenhuma', id_grupo: '', link_entrega: '' }
        ] };
        const salvoDown = { ativo: true, quando: 'depois_pix', espera_minutos: 15, envios: 1, intervalo_minutos: 30, mensagem: 'A', planos_oferecidos: ['p1'], id_plano_destino: 'p1', desconto_percentual: 10,
            entrega_tipo: 'plano', id_grupo: '', link_entrega: '', mensagem_entrega: '', criar_planos: false, planos_proprios: [],
            sequencias_extras: [
                { mensagem: 'B', espera_minutos: 20, planos_oferecidos: ['p2'], desconto_percentual: 30, criar_planos: false, planos_proprios: [], entrega_tipo: 'link', id_grupo: '', link_entrega: 'https://promo.exemplo.com/b', mensagem_entrega: '', midia_tipo: 'video', midia_path: 'uploads/b.mp4' },
                { mensagem: 'C', espera_minutos: 40, planos_oferecidos: [], desconto_percentual: 0, criar_planos: true, entrega_tipo: 'plano', id_grupo: '', link_entrega: '', mensagem_entrega: '', midia_tipo: 'image', midia_path: 'uploads/c1.jpg', midias: [{ tipo: 'image', path: 'uploads/c1.jpg' }, { tipo: 'video', path: 'uploads/c2.mp4' }, { tipo: 'image', path: 'uploads/c3.jpg' }],
                  planos_proprios: [{ nome: 'Relâmpago', valor: 9.9, dias_acesso: 3, unidade_acesso: 'horas', entrega_tipo: 'mensagem', id_grupo: '', link_entrega: '', mensagem_entrega: 'Oi {nome}' }] }
            ] };
        await abrir({ id: 5, nome: 'Com sequência', modo: 'basico', dados_fluxograma: Object.assign({}, base, { ofertas: { downsell: salvoDown } }) }, GRUPOS);
        await secao('downsell');
        chk('fluxo salvo: abre com o Downsell 1 e os 2 cartões extras, cada um com o seu tempo, mensagem, desconto e planos', (await qtd('#dn-extras .dn-extra')) === 2 && (await valor(D + ' .of-espera')) === '15' && (await valor(D + ' .of-mensagem')) === 'A'
            && (await valor(X(0) + ' .dn-espera')) === '20' && (await valor(X(0) + ' .dn-mensagem')) === 'B' && (await valor(X(0) + ' .dn-desconto')) === '30' && (await js(`${q(X(0) + ' .dn-planos-lista input:eq(1)')}.is(':checked')`)) && !(await js(`${q(X(0) + ' .dn-planos-lista input:eq(0)')}.is(':checked')`))
            && (await valor(X(1) + ' .dn-espera')) === '40');
        chk('fluxo salvo: a entrega por link do Downsell 2 e a mídia (vídeo anexado) voltam', (await valor(X(0) + ' .entrega-select')) === '__link__' && (await valor(X(0) + ' .entrega-link')) === 'https://promo.exemplo.com/b' && (await js(`${q(X(0) + ' .dn-midia')}.attr('data-estado')`)) === 'anexada' && (await js(`${q(X(0) + ' .dn-midia')}.attr('data-tipo')`)) === 'video');
        chk('fluxo salvo: o Downsell 3 abre com "Criar planos" ligado, o plano criado (Relâmpago, 9,90, entrega por mensagem) e sem desconto nem entrega da oferta', (await js(`${q(X(1) + ' .dn-criar')}.is(':checked')`)) && (await qtd(X(1) + ' .dn-proprio')) === 1
            && (await valor(X(1) + ' .dn-proprio:eq(0) .dn-p-nome')) === 'Relâmpago' && (await valor(X(1) + ' .dn-proprio:eq(0) .dn-p-valor')) === '9.9' && (await valor(X(1) + ' .dn-proprio:eq(0) .entrega-select')) === '__mensagem__'
            && (await valor(X(1) + ' .dn-proprio:eq(0) .entrega-mensagem')) === 'Oi {nome}' && !(await visivel(X(1) + ' .dn-desconto')) && !(await visivel(X(1) + ' .dn-entrega .entrega-select')));
        chk('fluxo salvo: o Downsell 3 abre com as 3 mídias (a 1ª na caixa grande, 2. Vídeo e 3. Foto na lista) e o Downsell 2, salvo com 1 mídia só (formato antigo), abre com ela', JSON.stringify(await rotulos(X(1) + ' .dn-midias-extras [data-i]')) === JSON.stringify(['2. Vídeo', '3. Foto'])
            && (await js(`${q(X(1) + ' .dn-midia')}.attr('data-estado')`)) === 'anexada' && (await qtd(X(0) + ' .dn-midias-extras [data-i]')) === 0 && (await visivel(X(0) + ' .dn-midias-adicionar')));
        chk('fluxo salvo: o título do Downsell 1 aparece, "Quantos envios" não (há sequência) e o botão de adicionar está ativo', (await visivel(D + ' .dn-titulo-1')) && !(await visivel(D + ' .of-envios')) && !(await js(`${q('#dn-adicionar')}.prop('disabled')`)));
        dados = await salvar();
        const rt = dados && dados.dados_fluxograma.ofertas.downsell;
        const e0 = rt && rt.sequencias_extras[0], e1 = rt && rt.sequencias_extras[1];
        chk('salvar sem mexer em nada devolve o mesmo (round-trip): 1ª sequência', !!rt && rt.mensagem === 'A' && rt.espera_minutos === 15 && rt.desconto_percentual === 10 && rt.planos_oferecidos.join() === 'p1' && rt.criar_planos === false && rt.envios === 1, rt);
        chk('salvar sem mexer em nada devolve o mesmo (round-trip): 2ª e 3ª sequências, com mídia, entrega e plano criado', !!e0 && e0.mensagem === 'B' && e0.espera_minutos === 20 && e0.desconto_percentual === 30 && e0.planos_oferecidos.join() === 'p2' && e0.entrega_tipo === 'link' && e0.link_entrega === 'https://promo.exemplo.com/b'
            && e0.midia_tipo === 'video' && e0.midia_path === 'uploads/b.mp4' && !!e1 && e1.criar_planos === true && e1.planos_proprios.length === 1 && e1.planos_proprios[0].nome === 'Relâmpago' && e1.planos_proprios[0].valor === 9.9 && e1.planos_proprios[0].dias_acesso === 3
            && e1.midias.length === 3 && e1.midias[1].tipo === 'video' && e1.midias[1].path === 'uploads/c2.mp4' && e1.midia_path === 'uploads/c1.jpg' && e0.midias.length === 1 && e0.midias[0].path === 'uploads/b.mp4'
            && e1.planos_proprios[0].unidade_acesso === 'horas' && e1.planos_proprios[0].entrega_tipo === 'mensagem' && e1.planos_proprios[0].mensagem_entrega === 'Oi {nome}', { e0, e1 });
        // adicionar um plano na aba Planos não desmarca nada nos cartões
        await secao('planos');
        await clicar('#btn-adicionar-plano');
        await js(`${q('#lista-planos .painel-plano:eq(2) .campo-plano-nome')}.val('Semanal').trigger('input'); ${q('#lista-planos .painel-plano:eq(2) .campo-plano-valor')}.val(9.9).trigger('input');`);
        await secao('downsell');
        chk('plano novo na aba Planos aparece nas listas de marcar (Downsell 1 e cartões) sem desmarcar o que já estava marcado', (await qtd(P1 + ' .of-planos-lista input[type=checkbox]')) === 3 && (await qtd(X(0) + ' .dn-planos-lista input[type=checkbox]')) === 3
            && (await js(`${q(P1 + ' .of-planos-lista input:eq(0)')}.is(':checked')`)) && (await js(`${q(X(0) + ' .dn-planos-lista input:eq(1)')}.is(':checked')`)));

        // fluxo antigo: sem criar_planos nem sequências
        await abrir({ id: 5, nome: 'Antigo', modo: 'basico', dados_fluxograma: Object.assign({}, base, { ofertas: { downsell: { ativo: true, planos_oferecidos: ['p1', 'p2'], id_plano_destino: 'p1', desconto_percentual: 20, quando: 'depois_pix', espera_minutos: 10, envios: 3, intervalo_minutos: 40, mensagem: 'Oferta antiga' } } }) }, GRUPOS);
        await secao('downsell');
        chk('fluxo antigo (sem sequências nem planos criados): abre como sempre — 2 planos marcados, 20%, "Quantos envios" 3 a cada 40 min, "Criar planos" desligado, nenhum cartão extra',
            (await qtd('#dn-extras .dn-extra')) === 0 && !(await js(`${q(P1 + ' .dn-criar')}.is(':checked')`)) && (await js(`${q(P1 + ' .of-planos-lista input:checked')}.length`)) === 2 && (await valor(S1 + ' .of-desconto')) === '20'
            && (await valor(D + ' .of-envios')) === '3' && (await valor(D + ' .of-intervalo')) === '40' && (await visivel(D + ' .of-intervalo')));
        dados = await salvar();
        const ant = dados && dados.dados_fluxograma.ofertas.downsell;
        chk('salvar o fluxo antigo: continua com envios 3 e intervalo 40 (sem sequências extras, o repetir de sempre vale) e leva criar_planos falso', !!ant && ant.envios === 3 && ant.intervalo_minutos === 40 && ant.sequencias_extras.length === 0 && ant.criar_planos === false && ant.planos_oferecidos.length === 2, ant);

        // ============ celular ============
        await abrir({ id: 5, nome: 'Com sequência', modo: 'basico', dados_fluxograma: Object.assign({}, base, { ofertas: { downsell: salvoDown } }) }, GRUPOS, 390);
        await secao('downsell');
        const largura = JSON.parse(await js(`JSON.stringify({ pagina: document.documentElement.scrollWidth, janela: window.innerWidth })`));
        chk('celular (390 px): sem rolagem lateral com as sequências e os cartões de plano', largura.pagina <= largura.janela + 1, largura);
        const fora = JSON.parse(await js(`JSON.stringify(${q('#dn-extras input:visible, #dn-extras select:visible, #dn-extras textarea:visible, #dn-extras button:visible')}.filter(function(){ var r = this.getBoundingClientRect(); return r.right > window.innerWidth + 1 || r.left < -1; }).length)`));
        chk('celular: nenhum campo dos cartões passa da borda da tela', fora === 0, fora);
        await js(`${q('#dn-extras')}.get(0).scrollIntoView({block:'start'})`);
        await esp(200);
        await c.captura(SAIDA + '/3_celular.png');

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
