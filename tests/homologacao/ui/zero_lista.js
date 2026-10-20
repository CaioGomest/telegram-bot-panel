// Começar a criar pela lista de fluxos (escolha Guiado/Visual), importar e excluir pela lista, e o editor Visual no celular (toque).
const fs = require('fs');
const path = require('path');
const { iniciar } = require('./zero_lib.js');
const q = (s) => 'jQuery(' + JSON.stringify(s) + ')';

(async () => {
    const t = await iniciar('zero_lista', { largura: 1500, altura: 1000 });
    const { c } = t;
    try {
        t.ok('login do usuário de teste', await t.entrar(), {});
        await c.ir(t.BASE + '/fluxos');
        await c.esperar(`typeof jQuery!=='undefined' && jQuery('#btn-abrir-escolha-modo').length && !/Carregando fluxos/.test(jQuery('#lista-fluxos').text())`, 20000);
        await t.esp(800);

        // ---------- escolha do modo
        t.ok('modal de escolha fechado ao abrir a página', !(await t.js(`jQuery('#modal-escolha-modo').hasClass('aberto')`)), {});
        await t.clicar(q('#btn-abrir-escolha-modo'));
        t.ok('"+ Novo Fluxo" abre "Como deseja criar?" com as 2 opções (Editor Visual e Guiado)', (await t.js(`jQuery('#modal-escolha-modo').hasClass('aberto')`)) && (await t.js(`jQuery('#modal-escolha-modo .a-opt').length`)) === 2, {});
        await t.clicar(q('#btn-fechar-escolha-modo'));
        t.ok('o ✕ fecha o modal', !(await t.js(`jQuery('#modal-escolha-modo').hasClass('aberto')`)), {});
        await t.clicar(q('#btn-abrir-escolha-modo'));
        await t.clicar(q('#modal-escolha-modo .a-opt[href="fluxo_basico"]'));
        t.ok('escolher "Guiado" abre o editor guiado de um fluxo novo', await c.esperar(`location.pathname.indexOf('fluxo_basico')>=0 && !location.search && jQuery('#titulo-fluxo').text()==='Novo fluxo'`, 15000), { url: await t.js('location.href') });
        await c.ir(t.BASE + '/fluxos');
        await c.esperar(`jQuery('#btn-abrir-escolha-modo').length>0 && !/Carregando fluxos/.test(jQuery('#lista-fluxos').text())`, 20000); await t.esp(600);
        await t.clicar(q('#btn-abrir-escolha-modo'));
        await t.clicar(q('#modal-escolha-modo .a-opt[href="fluxo"]'));
        t.ok('escolher "Editor Visual" abre o canvas com só o bloco Início', await c.esperar(`location.pathname.indexOf('/fluxo')>=0 && location.pathname.indexOf('fluxo_basico')<0 && jQuery('#area-trabalho-fluxograma .flowchart-operator').length===1`, 20000), { url: await t.js('location.href') });

        // ---------- a lista mostra os fluxos criados e leva ao editor certo
        await c.ir(t.BASE + '/fluxos');
        await c.esperar(`!/Carregando fluxos/.test(jQuery('#lista-fluxos').text())`, 20000); await t.esp(800);
        const cartoes = JSON.parse(await t.js(`JSON.stringify(jQuery('#lista-fluxos > *').map(function(){ var a = jQuery(this).find('a[href*="id="]').first().attr('href') || ''; return { texto: jQuery(this).text().replace(/\\s+/g, ' ').trim().slice(0, 140), editar: a }; }).get())`));
        const guiado = cartoes.find(x => /Zero Guiado/.test(x.texto)), visual = cartoes.find(x => /Zero Visual/.test(x.texto));
        t.ok('a lista mostra um fluxo "Zero Guiado" e um "Zero Visual" criados do zero', !!guiado && !!visual, { n: cartoes.length });
        t.ok('o lápis do Guiado leva a fluxo_basico?id= e o do Visual a fluxo?id=', guiado && /^fluxo_basico\?id=/.test(guiado.editar) && visual && /^fluxo\?id=/.test(visual.editar), { g: guiado && guiado.editar, v: visual && visual.editar });
        await t.captura('lista_fluxos.png');

        // ---------- importar pela lista (arquivo exportado pelo teste do editor visual) e excluir pela lista
        const arq = path.join(t.SAIDA, 'exportado_visual.json');
        t.ok('arquivo exportado pelo teste visual existe', fs.existsSync(arq), {});
        const nAntes = (await t.js(`jQuery('#lista-fluxos > *').length`));
        await t.subir(q('#arquivo-importar-fluxo-lista'), arq);
        const importou = await c.esperar(`jQuery('#lista-fluxos > *').length > ${nAntes}`, 15000);
        t.ok('importar pela lista: o fluxo novo aparece na lista (sem recarregar a página)', importou, { nAntes, nDepois: await t.js(`jQuery('#lista-fluxos > *').length`) });
        await t.js(`window.confirm = function(){ return true; }; 1`);
        await c.ir(t.BASE + '/fluxos'); await c.esperar(`!/Carregando fluxos/.test(jQuery('#lista-fluxos').text())`, 20000); await t.esp(800);
        const total = await t.js(`jQuery('#lista-fluxos .btn-excluir-fluxo').length`);
        // exclui um dos "Zero Visual" repetidos (o importado repete o nome): o último card com esse nome
        const idExcluir = await t.js(`(function(){ var el = jQuery('#lista-fluxos > *').filter(function(){ return /Zero Visual/.test(jQuery(this).text()); }).last().find('.btn-excluir-fluxo'); return el.data('id'); })()`);
        await t.js(`window.confirm = function(){ return true; }; 1`);
        await t.clicar(q(`.btn-excluir-fluxo[data-id="${idExcluir}"]`));
        const sumiu = await c.esperar(`jQuery('#lista-fluxos .btn-excluir-fluxo[data-id="${idExcluir}"]').length===0`, 12000);
        t.ok('excluir pelo ícone da lixeira da lista: o card some', sumiu, { idExcluir });
        t.ok('e o fluxo excluído não existe mais no banco', (await t.api('obter_fluxo', '&id=' + idExcluir)).sucesso === false, {});

        // ---------- celular: lista e editor Visual por toque
        await c.enviar('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 2, mobile: true });
        await c.enviar('Emulation.setTouchEmulationEnabled', { enabled: true, maxTouchPoints: 5 });
        await c.ir(t.BASE + '/fluxos'); await c.esperar(`!/Carregando fluxos/.test(jQuery('#lista-fluxos').text())`, 20000); await t.esp(800);
        t.ok('celular: a lista de fluxos não rola para o lado', !(await t.js(`document.documentElement.scrollWidth > innerWidth + 2`)), { w: await t.js('document.documentElement.scrollWidth') });
        await t.captura('lista_fluxos_celular.png');
        await c.ir(t.BASE + '/fluxo');
        await c.esperar(`typeof jQuery!=='undefined' && jQuery('.adicionar-no').length===13 && jQuery('#area-trabalho-fluxograma .flowchart-operator').length===1`, 25000); await t.esp(1500);
        const tipos = ['message', 'botoes', 'image', 'delay', 'pix'];
        for (const tp of tipos) { await t.clicar(q(`.adicionar-no[data-node-type="${tp}"]`)); await t.esp(300); }
        const nOps = await t.js(`Object.keys(jQuery('#area-trabalho-fluxograma').flowchart('getData').operators).length`);
        t.ok('celular: tocar nos botões da paleta adiciona os blocos no canvas (5 toques = 6 blocos com o Início)', nOps === 6, { nOps });
        const pos = JSON.parse(await t.js(`JSON.stringify(Object.values(jQuery('#area-trabalho-fluxograma').flowchart('getData').operators).map(function(o){ return [o.left, o.top]; }))`));
        const unicas = new Set(pos.map(p => p.join(','))).size;
        t.ok('celular: os blocos novos entram em cascata (posições diferentes, sem empilhar)', unicas === pos.length, { pos });
        await t.digitar(`jQuery('#area-trabalho-fluxograma .flowchart-operator.no-mensagem .campo-conteudo')`, 'Mensagem criada no celular');
        await t.esp(2800);
        const idCel = await t.js(`jQuery('#id-fluxo').val()`);
        t.ok('celular: digitar num bloco salva sozinho (o fluxo ganha id)', !!idCel, {});
        if (idCel) {
            const api = await t.api('obter_fluxo', '&id=' + idCel);
            const ops = Object.values(api.fluxo.dados_fluxograma.operators);
            t.ok('celular: o fluxo salvo tem os 6 blocos e a mensagem digitada', ops.length === 6 && ops.some(o => o.properties.conteudo === 'Mensagem criada no celular'), { n: ops.length });
            t.ok('celular: a página do editor não estoura a largura', !(await t.js(`document.documentElement.scrollWidth > innerWidth + 2`)), {});
            await t.captura('visual_celular.png');
            await t.js(`window.confirm = function(){ return true; }; 1`);
            await t.js(`jQuery('#btn-excluir-fluxo').click(); 1`);
            await c.esperar(`location.pathname.indexOf('fluxos')>=0`, 10000);
        }
        t.ok('nenhum erro de JavaScript', t.errosJs().length === 0, t.errosJs().slice(0, 5));
    } catch (e) { console.log('ERRO', e.message); t.ok('execução sem exceção', false, { erro: e.message }); }
    process.exitCode = (await t.fim()) ? 1 : 0;
})();
