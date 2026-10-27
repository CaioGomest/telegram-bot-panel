// Seta de recolher/expandir de cada plano (Fluxo Guiado > Planos): fica à DIREITA do cabeçalho (26/10/2026, pedido do dono). Chrome real, sem servidor
// (mesmo esquema de teste.js: gerar.php monta o fluxo_basico.php de verdade e api_falsa.js simula a API).
//   node tests/guiado/ui_editor/planos_seta.js      (sai com código 1 se algo falhar; capturas na pasta temporária)
const fs = require('fs');
const os = require('os');
const { spawnSync } = require('child_process');
const RAIZ = require('path').resolve(__dirname, '../../..').replace(/\\/g, '/');
const { lancar } = require(RAIZ + '/tests/homologacao/ui/cdp.js');
const SAIDA = (os.tmpdir() + '/editor_guiado_planos_seta').replace(/\\/g, '/');
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

(async () => {
    const c = await lancar();
    const js = (e) => c.avaliar(e);
    try {
        async function abrir(largura, altura) {
            await c.enviar('Emulation.setDeviceMetricsOverride', { width: largura, height: altura, deviceScaleFactor: 1, mobile: largura < 500 });
            await c.ir(URL);
            await c.esperar(`typeof jQuery!=='undefined' && jQuery('#btn-adicionar-plano').length>0`, 15000);
            await esp(600);
            await js(`jQuery('.basico-nav-item[data-secao="planos"]').get(0).click()`);
            await esp(200);
            for (const [nome, valor] of [['Mensal', '29.9'], ['Trimestral', '79.9'], ['Vitalício', '197']]) {
                await js(`jQuery('#btn-adicionar-plano').get(0).click()`);
                await esp(120);
                await js(`(function(){ var $p = jQuery('#lista-planos .painel-plano').last(); $p.find('.campo-plano-nome').val(${JSON.stringify(nome)}).trigger('input'); $p.find('.campo-plano-valor').val(${valor}).trigger('input'); })()`);
            }
        }
        const caixa = (i, sel) => JSON.parse(js_sync(`(function(){ var e = jQuery('#lista-planos .painel-plano').eq(${i}).find(${JSON.stringify(sel)}).get(0), b = e.getBoundingClientRect(); return JSON.stringify({ l: b.left, r: b.right, t: b.top, b: b.bottom, w: b.width, h: b.height }); })()`));
        function js_sync(e) { return e; }
        const medir = async (i, sel) => JSON.parse(await js(`(function(){ var e = jQuery('#lista-planos .painel-plano').eq(${i}).find(${JSON.stringify(sel)}).get(0), b = e.getBoundingClientRect(); return JSON.stringify({ l: b.left, r: b.right, t: b.top, b: b.bottom, w: b.width, h: b.height }); })()`));
        const giro = (i) => js(`getComputedStyle(jQuery('#lista-planos .painel-plano').eq(${i}).find('.plano-alternar svg').get(0)).transform`);

        await abrir(1500, 900);
        const topo = await medir(0, '.plano-topo'), seta = await medir(0, '.plano-alternar'), resumo = await medir(0, '.plano-resumo-nome'), cartao = await medir(0, '.plano-topo');
        chk('a seta está à DIREITA do nome do plano (e não mais à esquerda)', seta.l > resumo.r, { seta, resumo });
        chk('a seta encosta na ponta direita do cabeçalho (até 4 px de folga)', Math.abs(topo.r - seta.r) <= 4, { topo_dir: topo.r, seta_dir: seta.r });
        chk('o nome e o resumo (valor · tempo) continuam à esquerda, no começo do cabeçalho', resumo.l - topo.l <= 6, { resumo_esq: resumo.l, topo_esq: topo.l });
        chk('a seta e o nome ficam alinhados na vertical (centros a até 6 px)', Math.abs((seta.t + seta.b) / 2 - (resumo.t + resumo.b) / 2) <= 6);
        chk('aberto: a seta aponta para CIMA (girada 180°), pronta para recolher', (await giro(0)) === 'matrix(-1, 0, 0, -1, 0, 0)', await giro(0));
        const estilo = JSON.parse(await js(`JSON.stringify((function(){ var cs = getComputedStyle(jQuery('#lista-planos .painel-plano').eq(0).find('.plano-alternar').get(0)); return { b: cs.borderTopWidth + ' ' + cs.borderRightWidth + ' ' + cs.borderBottomWidth + ' ' + cs.borderLeftWidth, bg: cs.backgroundColor, cursor: cs.cursor }; })())`));
        chk('a seta NÃO tem borda (nenhum dos 4 lados) nem fundo em repouso: só o desenho da seta', estilo.b === '0px 0px 0px 0px' && /^rgba\(0, 0, 0, 0\)$|^transparent$/.test(estilo.bg), estilo);
        chk('a seta continua com o cursor de mão (clicável)', estilo.cursor === 'pointer', estilo);
        await js(`jQuery('#lista-planos .painel-plano').eq(1).find('.plano-alternar').get(0).click()`);
        await esp(300);
        chk('recolher pela seta: o corpo some, a linha fica só com nome, valor e tempo, e a seta aponta para BAIXO', (await js(`jQuery('#lista-planos .painel-plano').eq(1).find('.plano-corpo').is(':visible')`)) === false && (await js(`jQuery('#lista-planos .painel-plano').eq(1).find('.plano-resumo-info').text()`)) === 'R$ 79,90 · 30 dias' && (await giro(1)) === 'none', await giro(1));
        const seta2 = await medir(1, '.plano-alternar'), topo2 = await medir(1, '.plano-topo');
        chk('recolhido: a seta continua na ponta direita da linha', Math.abs(topo2.r - seta2.r) <= 4, { topo: topo2.r, seta: seta2.r });
        await c.captura(SAIDA + '/1_desktop.png');
        await js(`jQuery('#lista-planos .painel-plano').eq(1).find('.plano-topo').get(0).click()`);
        await esp(300);
        chk('clicar em qualquer ponto do cabeçalho expande de volta', (await js(`jQuery('#lista-planos .painel-plano').eq(1).find('.plano-corpo').is(':visible')`)) === true && (await giro(1)) === 'matrix(-1, 0, 0, -1, 0, 0)');
        chk('o botão tem rótulo para leitor de tela e aria-expanded coerentes', (await js(`jQuery('#lista-planos .painel-plano').eq(1).find('.plano-alternar').attr('aria-expanded')`)) === 'true' && (await js(`jQuery('#lista-planos .painel-plano').eq(1).find('.plano-alternar').attr('aria-label')`)) === 'Recolher plano');
        await js(`jQuery('#lista-planos .painel-plano').eq(1).find('.plano-alternar').get(0).click()`);
        await esp(250);
        chk('recolher não perde o que foi digitado (o campo continua no formulário)', (await js(`jQuery('#lista-planos .painel-plano').eq(1).find('.campo-plano-nome').val()`)) === 'Trimestral');

        // celular
        await abrir(390, 844);
        await js(`jQuery('#lista-planos .painel-plano').eq(1).find('.plano-alternar').get(0).click()`);
        await esp(300);
        const tm = await medir(0, '.plano-topo'), sm = await medir(0, '.plano-alternar'), rm = await medir(0, '.plano-resumo');
        chk('celular (390): sem rolagem lateral', (await js(`document.documentElement.scrollWidth <= window.innerWidth + 1`)) === true);
        chk('celular: a seta fica à direita, dentro do cartão, e o cabeçalho tem 44 px ou mais para o toque', sm.l > rm.l && sm.r <= tm.r + 1 && tm.h >= 44 && sm.w >= 32, { sm, tm: { r: tm.r, h: tm.h }, rm: { l: rm.l } });
        await c.captura(SAIDA + '/2_celular.png');
        chk('nenhum erro de JavaScript', c.errosJs().length === 0, c.errosJs());
    } catch (e) {
        falhas++;
        console.log('ERRO NO TESTE: ' + e.message);
    } finally { await c.fechar(); }
    console.log('\n' + ok + ' ok, ' + falhas + ' falha(s)  (capturas em ' + SAIDA + ')');
    process.exit(falhas ? 1 : 0);
})();
