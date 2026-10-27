// Fluxo Guiado > Planos: cada plano tem uma seta que recolhe a janela (fica só nome, valor e tempo).
// Usa o editor de fluxo NOVO (nada é salvo). Variáveis: HOMOLOG_EMAIL, HOMOLOG_SENHA (e HOMOLOG_URL, se não for a homologação).
//   HOMOLOG_EMAIL=admin@admin.com HOMOLOG_SENHA=... node tests/homologacao/ui/plano_recolher.js
const { iniciar } = require('./zero_lib.js');

const q = (s) => `jQuery(${JSON.stringify(s)})`;
const plano = (i, s) => `jQuery('#lista-planos .painel-plano').eq(${i}).find(${JSON.stringify(s)})`;

(async () => {
    const t = await iniciar('plano_recolher', { largura: 1500, altura: 1100 });
    const c = t.c;
    const corpoVisivel = (i) => t.js(`jQuery('#lista-planos .painel-plano').eq(${i}).find('.plano-corpo').is(':visible')`);
    const resumo = async (i) => JSON.parse(await t.js(`JSON.stringify({ nome: jQuery('#lista-planos .painel-plano').eq(${i}).find('.plano-resumo-nome').text(), info: jQuery('#lista-planos .painel-plano').eq(${i}).find('.plano-resumo-info').text() })`));
    const alternar = (i) => t.clicar(plano(i, '.plano-alternar'));
    try {
        t.ok('login', await t.entrar(), {});
        await c.ir(t.BASE + '/fluxo_basico');
        t.ok('editor guiado (novo) abre', await c.esperar(`typeof jQuery!=='undefined' && jQuery('#btn-adicionar-plano').length>0`, 20000), {});
        await t.esp(1200);
        await t.clicar(q('.basico-nav-item[data-secao="planos"]'));

        // ---------- 2 planos digitados como pessoa
        await t.clicar(q('#btn-adicionar-plano'));
        await t.clicar(q('#btn-adicionar-plano'));
        t.ok('2 planos na lista, os dois abertos, com a seta de recolher', (await t.js(`jQuery('#lista-planos .painel-plano').length`)) === 2 && (await t.js(`jQuery('#lista-planos .plano-alternar').length`)) === 2 && (await corpoVisivel(0)) && (await corpoVisivel(1)), {});
        t.ok('plano novo sem nome mostra "Plano sem nome" no cabeçalho', (await resumo(0)).nome === 'Plano sem nome', await resumo(0));
        await t.digitar(plano(0, '.campo-plano-nome'), 'Mensal');
        await t.digitar(plano(0, '.campo-plano-valor'), '29.9');
        await t.digitar(plano(0, '.campo-plano-dias'), '30');
        await t.digitar(plano(1, '.campo-plano-nome'), 'Diário');
        await t.digitar(plano(1, '.campo-plano-valor'), '5');
        await t.digitar(plano(1, '.campo-plano-dias'), '1');
        const r0 = await resumo(0), r1 = await resumo(1);
        t.ok('cabeçalho acompanha o que se digita: "Mensal · R$ 29,90 · 30 dias"', r0.nome === 'Mensal' && r0.info === 'R$ 29,90 · 30 dias', r0);
        t.ok('singular quando é 1: "R$ 5,00 · 1 dia"', r1.nome === 'Diário' && r1.info === 'R$ 5,00 · 1 dia', r1);
        await t.escolher(plano(1, '.campo-plano-unidade'), 'horas');
        t.ok('trocar a unidade atualiza o cabeçalho ("1 hora")', (await resumo(1)).info === 'R$ 5,00 · 1 hora', await resumo(1));
        await t.escolher(plano(1, '.campo-plano-unidade'), 'dias');
        await t.digitar(plano(1, '.campo-plano-dias'), '1');
        await t.captura('plano_recolher_1_abertos.png');

        // ---------- recolher só o primeiro
        await alternar(0);
        t.ok('recolher o plano 1: o formulário some, o plano 2 continua aberto', !(await corpoVisivel(0)) && (await corpoVisivel(1)), {});
        t.ok('recolhido: classe "recolhido", aria-expanded=false e rótulo "Expandir plano"', (await t.js(`jQuery('#lista-planos .painel-plano').eq(0).hasClass('recolhido')`)) && (await t.js(`${plano(0, '.plano-alternar')}.attr('aria-expanded')`)) === 'false' && (await t.js(`${plano(0, '.plano-alternar')}.attr('aria-label')`)) === 'Expandir plano', {});
        t.ok('recolhido: o cabeçalho continua visível com nome, valor e tempo', (await t.js(`${plano(0, '.plano-resumo')}.is(':visible')`)) && (await resumo(0)).info === 'R$ 29,90 · 30 dias', await resumo(0));
        t.ok('o botão Remover (lixeira) some junto com o formulário recolhido', !(await t.js(`${plano(0, '.remover-plano')}.is(':visible')`)), {});
        await t.captura('plano_recolher_2_um_recolhido.png');

        // ---------- o que está escondido continua valendo
        t.ok('campos do plano recolhido guardam o que foi digitado', (await t.js(`${plano(0, '.campo-plano-nome')}.val()`)) === 'Mensal' && (await t.js(`${plano(0, '.campo-plano-valor')}.val()`)) === '29.9', {});

        // ---------- adicionar plano refaz a lista: o recolhido continua recolhido, o novo vem aberto
        await t.clicar(q('#btn-adicionar-plano'));
        t.ok('adicionar plano: 3 planos, o 1º continua recolhido, o novo vem aberto', (await t.js(`jQuery('#lista-planos .painel-plano').length`)) === 3 && !(await corpoVisivel(0)) && (await corpoVisivel(1)) && (await corpoVisivel(2)), {});
        t.ok('adicionar plano não perde nem troca o que estava digitado', (await resumo(0)).nome === 'Mensal' && (await resumo(1)).nome === 'Diário' && (await t.js(`${plano(0, '.campo-plano-valor')}.val()`)) === '29.9', { r0: await resumo(0), r1: await resumo(1) });

        // ---------- remover o plano do meio: o recolhido (1º) continua recolhido
        await t.clicar(plano(2, '.remover-plano'));
        t.ok('remover o 3º plano: sobram 2, o 1º continua recolhido', (await t.js(`jQuery('#lista-planos .painel-plano').length`)) === 2 && !(await corpoVisivel(0)) && (await corpoVisivel(1)), {});
        await t.clicar(plano(0, '.plano-resumo'));
        t.ok('clicar no cabeçalho (não só na seta) expande de volta', (await corpoVisivel(0)) && (await t.js(`${plano(0, '.plano-alternar')}.attr('aria-expanded')`)) === 'true', {});
        await t.clicar(plano(1, '.plano-resumo'));
        t.ok('clicar no cabeçalho do plano 2 recolhe só ele', !(await corpoVisivel(1)) && (await corpoVisivel(0)), {});
        await alternar(1);
        t.ok('seta de novo expande', await corpoVisivel(1), {});

        // ---------- o salvar lê os campos do DOM: com o plano recolhido (escondido) os valores têm que continuar lá
        await alternar(0);
        const planosSalvar = JSON.parse(await t.js(`JSON.stringify(jQuery('#lista-planos .painel-plano').map(function(){ var p=jQuery(this); return { nome:p.find('.campo-plano-nome').val(), valor:parseFloat(p.find('.campo-plano-valor').val()) }; }).get())`));
        t.ok('com o 1º recolhido, os valores que seriam salvos continuam íntegros', planosSalvar[0].nome === 'Mensal' && planosSalvar[0].valor === 29.9 && planosSalvar[1].nome === 'Diário', planosSalvar);

        // ---------- celular
        await c.enviar('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 2, mobile: true });
        await t.esp(500);
        t.ok('celular: sem rolagem horizontal na página', (await t.js(`document.documentElement.scrollWidth <= window.innerWidth + 1`)), { sw: await t.js(`document.documentElement.scrollWidth`), iw: await t.js(`window.innerWidth`) });
        t.ok('celular: a linha do cabeçalho (toda clicável) tem pelo menos 44px de altura para o toque', (await t.js(`${plano(0, '.plano-topo')}.outerHeight()`)) >= 44, { h: await t.js(`${plano(0, '.plano-topo')}.outerHeight()`) });
        await t.js(`${plano(0, '.plano-alternar')}.get(0).scrollIntoView({block:'center'}); 1`);
        await t.esp(200);
        await t.captura('plano_recolher_3_celular.png');

        const erros = t.errosJs();
        t.ok('nenhum erro de JavaScript na tela', erros.length === 0, erros);
    } catch (e) {
        t.ok('teste terminou sem exceção', false, { erro: e.message });
    }
    process.exitCode = (await t.fim()) ? 1 : 0;
})();
