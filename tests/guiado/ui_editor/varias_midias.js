// Escolher VÁRIAS fotos/vídeos de uma vez nos lugares do Fluxo Guiado que têm lista de mídias (26/10/2026, pedido do dono): Boas-vindas, Upsell, Downsell,
// Prévias e a aba Pagamentos. Chrome real, sem servidor (gerar.php monta o fluxo_basico.php de verdade; api_falsa.js simula a API e os envios,
// inclusive recusa de um arquivo e envio lento para ver o andamento).
//   node tests/guiado/ui_editor/varias_midias.js      (sai com código 1 se algo falhar; capturas na pasta temporária)
const fs = require('fs');
const os = require('os');
const { spawnSync } = require('child_process');
const RAIZ = require('path').resolve(__dirname, '../../..').replace(/\\/g, '/');
const { lancar } = require(RAIZ + '/tests/homologacao/ui/cdp.js');
const MIDIA = RAIZ + '/tests/homologacao/ui/midia/';
const SAIDA = (os.tmpdir() + '/editor_guiado_varias_midias').replace(/\\/g, '/');
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
    const q = (s) => `jQuery(${JSON.stringify(s)})`;
    async function abrir() {
        await c.enviar('Emulation.setDeviceMetricsOverride', { width: 1500, height: 1000, deviceScaleFactor: 1, mobile: false });
        await c.enviar('Page.addScriptToEvaluateOnNewDocument', { source: 'window.__fluxo_pre = null;' });
        await c.ir(URL);
        await c.esperar(`typeof jQuery!=='undefined' && jQuery('#bp-adicionar').length>0 && typeof PagamentosGuiado!=='undefined'`, 15000);
        await esp(600);
    }
    async function secao(nome) { await js(`${q('.basico-nav-item[data-secao="' + nome + '"]')}.get(0).click()`); await esp(200); }
    async function clicar(sel) {
        const r = await js(`(function(){ var el = ${q(sel)}.get(0); if (!el) return 'sem elemento'; el.click(); return 'ok'; })()`);
        if (r !== 'ok') throw new Error('clicar: ' + r + ' em ' + sel);
        await esp(150);
    }
    async function marcar(sel, estado) {
        await js(`(function(){ var el = ${q(sel)}.get(0); if (el.checked !== ${!!estado}) el.click(); })()`);
        await esp(100);
    }
    // escolhe VÁRIOS arquivos de uma vez no campo de arquivo (o mesmo que o diálogo do sistema faria) e espera os envios acabarem
    async function subirVarios(sel, arquivos, espera = 700) {
        const marca = 'zt' + Math.floor(Math.random() * 1e9);
        const r = await js(`(function(){ var el = ${q(sel)}.get(0); if (!el) return 'sem elemento'; el.setAttribute('data-zt','${marca}'); return 'ok'; })()`);
        if (r !== 'ok') throw new Error('subir: ' + r + ' em ' + sel);
        const doc = await c.enviar('DOM.getDocument', { depth: 0 });
        const no = await c.enviar('DOM.querySelector', { nodeId: doc.result.root.nodeId, selector: `[data-zt="${marca}"]` });
        await c.enviar('DOM.setFileInputFiles', { nodeId: no.result.nodeId, files: arquivos.map(a => MIDIA + a) });
        await esp(espera);
    }
    const toast = () => js(`${q('#toast')}.text()`);
    const enviados = async () => JSON.parse(await js(`JSON.stringify(window.__arquivos_enviados || [])`));
    const rotulos = (sel) => async () => JSON.parse(await js(`JSON.stringify(${q(sel)}.map(function(){ return $(this).find('span').first().text().replace(/\\s+/g,' ').trim().split(' ').slice(0, 2).join(' '); }).get())`));
    const multiple = (sel) => js(`${q(sel)}.prop('multiple')`);

    try {
        // =============== BOAS-VINDAS ===============
        await abrir();
        await secao('boasvindas');
        const linhasBv = rotulos('#bv-midias-extras .basico-bot');
        await clicar('#bv-midia-previa .midia-escolher');
        chk('Boas-vindas, caixa vazia: o seletor de arquivo aceita VÁRIOS de uma vez', (await multiple('#bv-midia-arquivo')) === true);
        await subirVarios('#bv-midia-arquivo', ['foto1.png', 'video1.mp4', 'foto2.jpg']);
        chk('3 arquivos de uma vez: o 1º vai para a caixa grande e os outros 2 para a lista, na ordem escolhida', (await js(`${q('#bv-midia-previa')}.attr('data-tipo')`)) === 'image' && JSON.stringify(await linhasBv()) === JSON.stringify(['2. Vídeo', '3. Foto']), await linhasBv());
        chk('foram enviados exatamente os 3 arquivos, um de cada vez, na ordem escolhida', JSON.stringify(await enviados()) === JSON.stringify(['foto1.png', 'video1.mp4', 'foto2.jpg']), await enviados());
        chk('o aviso diz "3 mídias anexadas" e lembra de Salvar alterações', (await toast()) === '3 mídias anexadas. Clique em Salvar alterações para aplicar.', await toast());
        chk('terminou: o botão "+ Adicionar outra foto ou vídeo" voltou habilitado', (await js(`${q('#bv-midias-adicionar')}.prop('disabled')`)) === false && (await js(`${q('#bv-midias-adicionar')}.text()`)) === '+ Adicionar outra foto ou vídeo');

        await clicar('#bv-midias-adicionar');
        chk('"+ Adicionar outra foto ou vídeo" também aceita vários', (await multiple('#bv-midia-arquivo')) === true);
        await subirVarios('#bv-midia-arquivo', ['foto3.png', 'video1.mp4']);
        chk('mais 2 de uma vez: vão para o fim da lista (5 no total, 2. Vídeo, 3. Foto, 4. Foto, 5. Vídeo)', JSON.stringify(await linhasBv()) === JSON.stringify(['2. Vídeo', '3. Foto', '4. Foto', '5. Vídeo']), await linhasBv());

        await clicar('#bv-midia-previa .midia-trocar');
        chk('"Trocar" é para UM arquivo só (o seletor não aceita vários)', (await multiple('#bv-midia-arquivo')) === false);
        const antes = (await enviados()).length;
        await subirVarios('#bv-midia-arquivo', ['foto2.png']);
        chk('trocar a 1ª substitui só ela: continuam 5 mídias e foi enviado só 1 arquivo', JSON.stringify(await linhasBv()) === JSON.stringify(['2. Vídeo', '3. Foto', '4. Foto', '5. Vídeo']) && (await enviados()).length === antes + 1, await linhasBv());
        chk('trocar com 1 arquivo mantém a frase de sempre no aviso', (await toast()) === 'Mídia anexada. Clique em Salvar alterações para aplicar.', await toast());

        // limite de 10: escolhe 7 com 5 vagas
        const antes2 = (await enviados()).length;
        await clicar('#bv-midias-adicionar');
        await subirVarios('#bv-midia-arquivo', ['foto1.png', 'foto2.jpg', 'foto3.png', 'video1.mp4', 'foto2.png', 'foto1.png', 'foto2.jpg'], 1000);
        chk('7 arquivos com 5 vagas: sobem só 5 (a lista fecha em 10) e os 2 últimos nem são enviados', (await linhasBv()).length === 9 && (await enviados()).length === antes2 + 5, [(await linhasBv()).length, (await enviados()).length - antes2]);
        chk('o aviso conta o que ficou de fora: "5 mídias anexadas" e "2 ficaram de fora: o limite é de 10 mídias", em vermelho', /5 mídias anexadas\./.test(await toast()) && /2 ficaram de fora: o limite é de 10 mídias\./.test(await toast()) && (await js(`${q('#toast')}.hasClass('erro')`)) === true, await toast());
        chk('lista cheia: botão desabilitado, "Limite de 10 mídias"', (await js(`${q('#bv-midias-adicionar')}.prop('disabled')`)) === true && (await js(`${q('#bv-midias-adicionar')}.text()`)) === 'Limite de 10 mídias');
        await js(`window.scrollTo(0, 0)`);
        await c.captura(SAIDA + '/1_boasvindas_cheia.png');

        // um dos arquivos é recusado pelo servidor: os outros entram, e o aviso diz qual falhou
        await abrir();
        await secao('boasvindas');
        await js(`window.__falhar_arquivo = 'foto2.jpg'`);
        await clicar('#bv-midia-previa .midia-escolher');
        await subirVarios('#bv-midia-arquivo', ['foto1.png', 'foto2.jpg', 'video1.mp4']);
        chk('um arquivo recusado pelo servidor: os outros 2 entram (foto na caixa grande + "2. Vídeo")', (await js(`${q('#bv-midia-previa')}.attr('data-tipo')`)) === 'image' && JSON.stringify(await linhasBv()) === JSON.stringify(['2. Vídeo']), await linhasBv());
        chk('o aviso diz "2 mídias anexadas" e qual não subiu, com o motivo, em vermelho', /2 mídias anexadas\./.test(await toast()) && /1 não subiu: foto2\.jpg \(Formato não aceito\.\)\./.test(await toast()) && (await js(`${q('#toast')}.hasClass('erro')`)) === true, await toast());

        // só 1 arquivo e ele é recusado: a frase de erro de sempre (a do servidor)
        await abrir();
        await secao('boasvindas');
        await js(`window.__falhar_arquivo = 'foto1.png'`);
        await clicar('#bv-midia-previa .midia-escolher');
        await subirVarios('#bv-midia-arquivo', ['foto1.png']);
        chk('1 arquivo só e recusado: o aviso é a mensagem do servidor e a caixa continua vazia', (await toast()) === 'Formato não aceito.' && (await js(`${q('#bv-midia-previa')}.attr('data-estado')`)) === 'vazio', await toast());

        // andamento: "Enviando mídia 1 de 3…"
        await abrir();
        await secao('boasvindas');
        await clicar('#bv-midia-previa .midia-escolher');
        await subirVarios('#bv-midia-arquivo', ['foto1.png']);
        await js(`window.__atraso_upload = 250; window.__textos = []; (function(){ var b = document.getElementById('bv-midias-adicionar'); new MutationObserver(function(){ window.__textos.push(b.textContent + '|' + b.disabled); }).observe(b, { childList: true, characterData: true, attributes: true, subtree: true }); })();`);
        await clicar('#bv-midias-adicionar');
        await subirVarios('#bv-midia-arquivo', ['foto2.jpg', 'foto3.png', 'video1.mp4'], 1500);
        const textos = JSON.parse(await js(`JSON.stringify(window.__textos)`));
        chk('durante o envio o botão mostra o andamento e fica desabilitado: "Enviando mídia 1 de 3…", "2 de 3…", "3 de 3…"', ['1 de 3', '2 de 3', '3 de 3'].every(t => textos.indexOf('Enviando mídia ' + t + '…|true') >= 0), textos);
        chk('no fim o botão volta ao normal e a lista tem as 4 mídias', (await js(`${q('#bv-midias-adicionar')}.prop('disabled')`)) === false && (await linhasBv()).length === 3, [await js(`${q('#bv-midias-adicionar')}.text()`), await linhasBv()]);
        await js(`window.__atraso_upload = 0`);

        // =============== UPSELL e DOWNSELL ===============
        for (const oferta of ['upsell', 'downsell']) {
            const P = '.painel[data-oferta="' + oferta + '"]';
            const linhasOf = rotulos(P + ' .of-midias-extras .basico-bot');
            await abrir();
            await secao(oferta);
            await marcar(P + ' .of-ativo', true);
            await clicar(P + ' .of-midia .midia-escolher');
            chk(oferta + ', caixa vazia: o seletor aceita VÁRIOS de uma vez', (await multiple(P + ' .of-midia-arquivo')) === true);
            await subirVarios(P + ' .of-midia-arquivo', ['video1.mp4', 'foto1.png', 'foto2.jpg']);
            chk(oferta + ': 3 de uma vez: o 1º (vídeo) na caixa grande e "2. Foto", "3. Foto" na lista', (await js(`${q(P + ' .of-midia')}.attr('data-tipo')`)) === 'video' && JSON.stringify(await linhasOf()) === JSON.stringify(['2. Foto', '3. Foto']), await linhasOf());
            chk(oferta + ': o aviso é "3 mídias anexadas" e pede para Salvar alterações', (await toast()) === '3 mídias anexadas. Clique em Salvar alterações para aplicar.', await toast());
            await clicar(P + ' .of-midias-adicionar');
            chk(oferta + ': "+ Adicionar outra foto ou vídeo" aceita vários', (await multiple(P + ' .of-midia-arquivo')) === true);
            await subirVarios(P + ' .of-midia-arquivo', ['foto3.png', 'video1.mp4']);
            chk(oferta + ': mais 2 de uma vez vão para o fim (5 no total)', (await linhasOf()).length === 4, await linhasOf());
            await clicar(P + ' .of-midia .midia-trocar');
            chk(oferta + ': "Trocar" é para 1 arquivo só', (await multiple(P + ' .of-midia-arquivo')) === false);
            chk(oferta + ': a lista final tem as 5 mídias na ordem escolhida (vídeo na caixa grande, depois foto, foto, foto, vídeo)', (await js(`${q(P + ' .of-midia')}.attr('data-tipo')`)) === 'video' && JSON.stringify(await linhasOf()) === JSON.stringify(['2. Foto', '3. Foto', '4. Foto', '5. Vídeo']), await linhasOf());
        }
        // Order Bump: 1 mídia só, então nunca aceita vários
        await abrir();
        await secao('orderbump');
        await marcar('.painel[data-oferta="order_bump"] .of-ativo', true);
        await clicar('.painel[data-oferta="order_bump"] .of-midia .midia-escolher');
        chk('Order Bump (1 mídia só): o seletor NÃO aceita vários', (await multiple('.painel[data-oferta="order_bump"] .of-midia-arquivo')) === false);

        // =============== PRÉVIAS ===============
        await abrir();
        await secao('previas');
        await marcar('#pv-ativo', true);
        await clicar('#pv-adicionar');
        chk('Prévias: o seletor aceita VÁRIOS de uma vez', (await multiple('#pv-arquivo')) === true);
        await subirVarios('#pv-arquivo', ['foto1.png', 'video1.mp4', 'audio1.mp3', 'foto2.jpg']);
        const linhasPv = rotulos('#pv-lista .basico-bot');
        chk('Prévias: 4 de uma vez (foto, vídeo, ÁUDIO, foto), contador (4/8)', JSON.stringify(await linhasPv()) === JSON.stringify(['1. Foto', '2. Vídeo', '3. Áudio', '4. Foto']) && (await js(`${q('#pv-contagem')}.text()`)) === '(4/8)', await linhasPv());
        chk('Prévias: o aviso é "4 mídias anexadas"', /^4 mídias anexadas\./.test(await toast()), await toast());
        const antesPv = (await enviados()).length;
        await clicar('#pv-adicionar');
        await subirVarios('#pv-arquivo', ['foto1.png', 'foto2.jpg', 'foto3.png', 'video1.mp4', 'foto2.png', 'foto1.png'], 1000);
        chk('Prévias: 6 arquivos com 4 vagas: a lista fecha em 8/8, só 4 foram enviados e o aviso conta os 2 que ficaram de fora', (await js(`${q('#pv-contagem')}.text()`)) === '(8/8)' && (await enviados()).length === antesPv + 4 && /2 ficaram de fora: o limite é de 8 mídias\./.test(await toast()), [await js(`${q('#pv-contagem')}.text()`), (await enviados()).length - antesPv, await toast()]);
        chk('Prévias: lista cheia, botão de adicionar desabilitado e com o texto de sempre', (await js(`${q('#pv-adicionar')}.prop('disabled')`)) === true && (await js(`${q('#pv-adicionar')}.text()`)) === '+ Adicionar mídia');

        // =============== PAGAMENTOS (3 espaços por aba) ===============
        await abrir();
        await secao('pagamentos');
        await clicar('[data-pg-nova="gerado"]');
        chk('Pagamentos: o seletor aceita VÁRIOS de uma vez', (await multiple('#pg-midia-arquivo')) === true);
        await subirVarios('#pg-midia-arquivo', ['foto1.png', 'foto2.jpg', 'video1.mp4', 'foto3.png', 'foto2.png'], 1200);
        chk('Pagamentos: 5 arquivos com 3 espaços: enchem os 3 espaços (3/3 mídias) e só 3 foram enviados', (await js(`${q('[data-pg-midias="gerado"] .pg-slot-cheio')}.length`)) === 3 && (await js(`${q('[data-pg-contagem="gerado"]')}.text()`)) === '3/3 mídias' && (await enviados()).length === 3, [await js(`${q('[data-pg-contagem="gerado"]')}.text()`), await enviados()]);
        chk('Pagamentos: o aviso diz "3 mídias anexadas" e "2 ficaram de fora: o limite é de 3 mídias"', /3 mídias anexadas\./.test(await toast()) && /2 ficaram de fora: o limite é de 3 mídias\./.test(await toast()), await toast());
        chk('Pagamentos: a prévia do Telegram mostra o álbum de 3', (await js(`document.querySelectorAll('#pg-previa-corpo .pg-tg-bolha-midia')[0].querySelectorAll('.pg-tg-tile').length`)) === 3);
        await c.captura(SAIDA + '/2_pagamentos_3_midias.png');

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
