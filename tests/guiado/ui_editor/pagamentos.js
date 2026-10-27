// Teste de tela da aba Pagamentos do Fluxo Guiado (modelo novo) no Chrome de verdade, SEM servidor, banco nem login (mesmo esquema de teste.js: gerar.php monta o
// HTML do fluxo_basico.php real e api_falsa.js simula a API; o que seria salvo fica em window.__salvo). Precisa do Chrome e de internet (jQuery pela CDN).
// Cobre: abas, valores padrão, prévia do Telegram (ordem das mensagens e botões) reagindo a cada opção, variáveis, formatação, mídias, salvar, reabrir fluxo
// novo e fluxo ANTIGO (sem "versao"), texto perigoso, celular e tema claro.
//   node tests/guiado/ui_editor/pagamentos.js      (sai com código 1 se algo falhar; capturas na pasta temporária, caminho impresso no fim)
const fs = require('fs');
const os = require('os');
const { spawnSync } = require('child_process');
const RAIZ = require('path').resolve(__dirname, '../../..').replace(/\\/g, '/');
const { lancar } = require(RAIZ + '/tests/homologacao/ui/cdp.js');
const MIDIA = RAIZ + '/tests/homologacao/ui/midia/';
const SAIDA = (os.tmpdir() + '/editor_guiado_pagamentos').replace(/\\/g, '/');
fs.mkdirSync(SAIDA, { recursive: true });
function gerar(tema) {
    const g = spawnSync('php', [__dirname + '/gerar.php', SAIDA, tema], { encoding: 'utf8' });
    if (g.status !== 0) { console.log('gerar.php falhou:\n' + g.stdout + g.stderr); process.exit(1); }
}
gerar('dark');
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
    async function abrir(fluxo, largura = 1500, altura = 1100) {
        await c.enviar('Emulation.setDeviceMetricsOverride', { width: largura, height: altura, deviceScaleFactor: 1, mobile: largura < 500 });
        await c.enviar('Page.addScriptToEvaluateOnNewDocument', { source: fluxo ? `window.__fluxo_pre = ${JSON.stringify(fluxo)}; window.__xss = 0;` : 'window.__fluxo_pre = null; window.__xss = 0;' });
        await c.ir(URL + (fluxo ? '?id=5' : ''));
        await c.esperar(`typeof jQuery!=='undefined' && jQuery('#pg-previa-corpo').length>0 && typeof PagamentosGuiado!=='undefined'`, 15000);
        await esp(700);
        await js(`${q('.basico-nav-item[data-secao="pagamentos"]')}.get(0).click()`);
        await esp(300);
    }
    async function definir(sel, valor) {
        const r = await js(`(function(){ var el = ${q(sel)}.get(0); if (!el) return 'sem elemento'; el.value = ${JSON.stringify(String(valor))}; el.dispatchEvent(new Event('input',{bubbles:true})); el.dispatchEvent(new Event('change',{bubbles:true})); return 'ok'; })()`);
        if (r !== 'ok') throw new Error('definir: ' + r + ' em ' + sel);
        await esp(120);
    }
    async function marcar(sel, estado) {
        const r = await js(`(function(){ var el = ${q(sel)}.get(0); if (!el) return 'sem elemento'; if (el.checked !== ${!!estado}) el.click(); return 'ok'; })()`);
        if (r !== 'ok') throw new Error('marcar: ' + r + ' em ' + sel);
        await esp(120);
    }
    async function clicar(sel) {
        const r = await js(`(function(){ var el = ${q(sel)}.get(0); if (!el) return 'sem elemento'; el.click(); return 'ok'; })()`);
        if (r !== 'ok') throw new Error('clicar: ' + r + ' em ' + sel);
        await esp(150);
    }
    async function subir(sel, arquivo) {
        const marca = 'zt' + Math.floor(Math.random() * 1e9);
        await js(`${q(sel)}.get(0).setAttribute('data-zt','${marca}')`);
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
    // a conversa da prévia em linhas curtas: DIA | FOTO:legenda | MSG:texto | MIDIAS:n | BOTOES:a|b | DIV:texto
    async function previa() {
        return JSON.parse(await js(`JSON.stringify([].map.call(document.getElementById('pg-previa-corpo').children, function (e) {
            var cl = e.classList, t = function (x) { return (x.textContent || '').replace(/\\s+/g, ' ').trim(); };
            if (cl.contains('pg-tg-dia')) return 'DIA';
            if (cl.contains('pg-tg-divisor')) return 'DIV:' + t(e);
            if (cl.contains('pg-tg-botoes')) return 'BOTOES:' + [].map.call(e.querySelectorAll('.pg-tg-botao'), t).join('|');
            if (cl.contains('pg-tg-bolha-foto')) return 'FOTO:' + t(e.querySelector('.pg-tg-legenda'));
            if (cl.contains('pg-tg-bolha-midia')) return 'MIDIAS:' + e.querySelectorAll('.pg-tg-tile').length;
            return 'MSG:' + t(e.querySelector('.pg-tg-texto'));
        }))`));
    }
    const tipos = (p) => p.map(x => x.split(':')[0]).join(' ');
    const slotsCheios = () => js(`${q('[data-pg-midias="gerado"] .pg-slot-cheio')}.length`);

    try {
        // ===================== fluxo NOVO: padrões e prévia =====================
        await abrir(null);
        chk('ordem dos dois botões: no editor "copiar o código" fica ACIMA de "verificar o pagamento", igual à prévia do Telegram e ao bot (26/10/2026, pedido do dono)',
            await js(`(function(){ var c = document.getElementById('pg-mostrar-copiar'), v = document.getElementById('pg-mostrar-confirmar'); var ordem_editor = (c.compareDocumentPosition(v) & Node.DOCUMENT_POSITION_FOLLOWING) ? 'copiar>verificar' : 'verificar>copiar'; var tg = [].map.call(document.querySelectorAll('.pg-tg-botoes')[0].querySelectorAll('.pg-tg-botao'), function(b){ return b.textContent.trim(); }); var ordem_tg = tg[0].indexOf('Copiar') !== -1 && tg[1].indexOf('Verificar') !== -1 ? 'copiar>verificar' : 'verificar>copiar'; return ordem_editor === 'copiar>verificar' && ordem_tg === 'copiar>verificar'; })()`));
        chk('a aba Pagamentos tem as duas abas e "Pagamento Gerado" aparece ativa', (await js(`${q('.pg-aba')}.length`)) === 2 && (await js(`${q('.pg-aba.ativa')}.text()`)) === 'Pagamento Gerado' && (await js(`${q('[data-pg-painel="aprovado"]')}.is(':visible')`)) === false);
        chk('a coluna da direita mostra a prévia do Telegram (e não o cartão do Suporte)', (await js(`${q('#pg-previa')}.is(':visible')`)) === true && (await js(`${q('.lateral-suporte')}.is(':visible')`)) === false);
        chk('padrões do fluxo novo: layout Padrão, QR antes do texto, mensagens antes do código e dos botões ligadas, os 2 botões ligados, botão de acesso ligado',
            (await js(`${q('input[name="pg-layout"]:checked')}.val()`)) === 'padrao' && (await js(`${q('#pg-qr-posicao')}.val()`)) === 'antes' && (await js(`${q('#pg-antes-codigo-ativo')}.is(':checked')`)) === true
            && (await js(`${q('#pg-antes-botoes-ativo')}.is(':checked')`)) === true && (await js(`${q('#pg-mostrar-copiar')}.is(':checked')`)) === true && (await js(`${q('#pg-mostrar-confirmar')}.is(':checked')`)) === true && (await js(`${q('#pg-botao-acesso-ativo')}.is(':checked')`)) === true);
        let p = await previa();
        chk('a prévia mostra a conversa na ordem do bot: QR, texto do pedido, "antes do código", código, "antes dos botões" + botões, divisor, aprovação + botão de acesso',
            tipos(p) === 'DIA FOTO MSG MSG MSG MSG BOTOES DIV MSG BOTOES' && p[1] === 'FOTO:Escaneie o QR Code para pagar.' && /Pedido Criado com Sucesso.*Produto: Premium VIP.*Valor: R\$ 29,90.*Como realizar o pagamento/.test(p[2])
            && p[3] === 'MSG:Copie o código abaixo:' && p[4].indexOf('MSG:00020126') === 0 && p[5] === 'MSG:Após efetuar o pagamento, clique no botão abaixo' && p[6] === 'BOTOES:📋 Copiar Código|✅ Verificar Status'
            && p[7] === 'DIV:Após pagamento aprovado' && /Parabéns João! Seu pagamento do plano Premium VIP no valor de R\$ 29,90 foi confirmado/.test(p[8]) && p[9] === 'BOTOES:🔓 Acessar Conteúdo', p);
        await c.captura(SAIDA + '/1_gerado_padrao.png');

        // ----- cada opção muda a prévia
        await marcar('#pg-antes-botoes-ativo', false);
        p = await previa();
        chk('"Mensagem antes dos botões" desligada: os botões vão grudados na mensagem do código (sem mensagem à parte)', tipos(p) === 'DIA FOTO MSG MSG MSG BOTOES DIV MSG BOTOES' && p[5].indexOf('BOTOES:') === 0 && !p.some(x => /Após efetuar/.test(x)), p);
        await marcar('#pg-antes-codigo-ativo', false);
        chk('"Mensagem antes do código" desligada: some a mensagem "Copie o código abaixo:"', !(await previa()).some(x => /Copie o código abaixo/.test(x)));
        await marcar('#pg-mostrar-confirmar', false);
        p = await previa();
        chk('botão "Verificar" desligado: só o de copiar fica', p.some(x => x === 'BOTOES:📋 Copiar Código') && !p.some(x => /Verificar/.test(x)) && (await js(`${q('#pg-btn-verificar-texto')}.prop('disabled')`)) === true, p);
        await marcar('#pg-mostrar-confirmar', true);
        await definir('#pg-btn-verificar-texto', 'Já paguei ✔');
        chk('o texto do botão é o que o dono escreve', (await previa()).some(x => x === 'BOTOES:📋 Copiar Código|Já paguei ✔'));
        await marcar('#pg-codigo-na-mensagem', true);
        p = await previa();
        chk('"Código Pix na mesma mensagem": o código entra na mensagem do texto, não há mensagem só do código, e os botões vão nela', tipos(p) === 'DIA FOTO MSG BOTOES DIV MSG BOTOES' && /Como realizar o pagamento.*00020126/.test(p[2]), p);
        chk('com o código na mensagem, a linha "Mensagem antes do código" fica desabilitada', (await js(`${q('#pg-antes-codigo-ativo')}.prop('disabled')`)) === true);
        await marcar('#pg-codigo-na-mensagem', false);
        await definir('#pg-qr-posicao', 'junto');
        p = await previa();
        chk('QR "na mesma mensagem": uma foto só, com o texto do pedido na legenda', tipos(p) === 'DIA FOTO MSG BOTOES DIV MSG BOTOES' && /^FOTO:✅ Pedido Criado/.test(p[1]) && /Como realizar o pagamento/.test(p[1]), p);
        await definir('#pg-qr-posicao', 'depois');
        p = await previa();
        chk('QR "depois do texto": o texto vem primeiro e a foto logo depois', p[1].indexOf('MSG:') === 0 && p[2] === 'FOTO:Escaneie o QR Code para pagar.', p);
        await marcar('#pg-mostrar-qrcode', false);
        chk('sem QR: a foto some e a posição fica desabilitada', !(await previa()).some(x => x.indexOf('FOTO') === 0) && (await js(`${q('#pg-qr-posicao')}.prop('disabled')`)) === true);
        await marcar('#pg-mostrar-qrcode', true);
        await definir('#pg-codigo-formato', 'bloco');
        chk('formato "bloco de código": o código aparece em <pre>', (await js(`document.querySelectorAll('#pg-previa-corpo .pg-tg-texto pre').length`)) === 1);
        await definir('#pg-codigo-formato', 'simples');
        chk('formato "texto simples": o código sai sem <code> nem <pre>', (await js(`document.querySelectorAll('#pg-previa-corpo .pg-tg-texto pre, #pg-previa-corpo .pg-tg-texto code').length`)) === 0);
        await definir('#pg-codigo-formato', 'inline');
        await clicar('input[name="pg-layout"][value="compacto"]');
        p = await previa();
        chk('layout Compacto: some o resumo do pedido (Pedido Criado / Produto / Valor)', !p.some(x => /Pedido Criado|Produto:/.test(x)) && p.some(x => /Como realizar o pagamento/.test(x)), p);
        await clicar('input[name="pg-layout"][value="padrao"]');

        // ----- texto: variáveis, formatação, contador e texto perigoso
        await definir('#pg-msg-instrucoes', '{saudacao}, {nome}! Plano {plano} por {valor}. {uf}');
        p = await previa();
        chk('variáveis do texto são trocadas na prévia ({uf}, que não existe, fica como escrito)', p.some(x => /(Bom dia|Boa tarde|Boa noite), João! Plano Premium VIP por R\$ 29,90\. \{uf\}/.test(x)), p);
        chk('o contador de caracteres acompanha o texto', (await js(`${q('[data-pg-contador="pg-msg-instrucoes"]')}.text()`)) === '51/3000', await js(`${q('[data-pg-contador="pg-msg-instrucoes"]')}.text()`));
        await definir('#pg-msg-instrucoes', 'Pague {nome}');
        await js(`(function(){ var ta = document.getElementById('pg-msg-instrucoes'); ta.focus(); ta.setSelectionRange(0, 5); })()`);
        await clicar('[data-pg-editor="pg-msg-instrucoes"] [data-pg-tag="b"]');
        chk('botão B envolve a seleção em <b></b> e a prévia mostra negrito', (await js(`${q('#pg-msg-instrucoes')}.val()`)) === '<b>Pague</b> {nome}' && (await js(`document.querySelectorAll('#pg-previa-corpo .pg-tg-texto b').length > 0 && [].some.call(document.querySelectorAll('#pg-previa-corpo .pg-tg-texto b'), function(b){return b.textContent === 'Pague'})`)) === true, await js(`${q('#pg-msg-instrucoes')}.val()`));
        await js(`(function(){ var ta = document.getElementById('pg-msg-instrucoes'); ta.focus(); ta.setSelectionRange(ta.value.length, ta.value.length); })()`);
        await clicar('[data-pg-editor="pg-msg-instrucoes"] [data-pg-variavel="plano"]');
        chk('chip de variável insere {plano} na posição do cursor', (await js(`${q('#pg-msg-instrucoes')}.val()`)) === '<b>Pague</b> {nome}{plano}');
        await definir('#pg-msg-instrucoes', '<script>window.__xss=1</script><img src=x onerror="window.__xss=2"> <a href="http://x">link</a> &amp; <b>ok');
        await esp(200);
        const html = await js(`document.getElementById('pg-previa-corpo').innerHTML`);
        chk('texto perigoso: nenhum script, imagem ou link vai para a prévia (vira texto escapado) e nada executa', (await js(`window.__xss`)) === 0 && !/<script|<img src=x|<a href/.test(html) && /&lt;script&gt;/.test(html) && /<b>ok<\/b>/.test(html), html.slice(0, 300));
        await definir('#pg-msg-instrucoes', '<b>Como realizar o pagamento:</b>\n\n1. Abra o app do banco.');

        // ----- mídias (até 3, em álbum)
        for (const arquivo of ['foto1.png', 'video1.mp4', 'foto2.jpg']) {
            await clicar('[data-pg-nova="gerado"]');   // o clique em "+ Mídia" diz em qual aba o arquivo entra
            await subir('#pg-midia-arquivo', arquivo);
        }
        p = await previa();
        chk('3 mídias: 3 espaços cheios, contador 3/3, sem botão de adicionar e um álbum de 3 na prévia antes do resto', (await slotsCheios()) === 3 && (await js(`${q('[data-pg-contagem="gerado"]')}.text()`)) === '3/3 mídias' && (await js(`${q('[data-pg-nova="gerado"]')}.length`)) === 0 && p[1] === 'MIDIAS:3', p);
        await js(`${q('[data-pg-tirar="gerado"][data-i="1"]')}.get(0).click()`);
        await esp(200);
        chk('tirar uma mídia: sobram 2, o botão de adicionar volta e a prévia mostra álbum de 2', (await slotsCheios()) === 2 && (await js(`${q('[data-pg-nova="gerado"]')}.length`)) === 1 && (await previa())[1] === 'MIDIAS:2');
        chk('a aba Aprovado tem as próprias mídias (não herda as do Pix)', (await js(`${q('[data-pg-midias="aprovado"] .pg-slot-cheio')}.length`)) === 0);

        // ----- aba Aprovado
        await clicar('.pg-aba[data-pg-aba="aprovado"]');
        chk('aba Aprovado: aparece a mensagem de aprovação e some o painel do Pix gerado', (await js(`${q('[data-pg-painel="aprovado"]')}.is(':visible')`)) === true && (await js(`${q('[data-pg-painel="gerado"]')}.is(':visible')`)) === false && (await js(`${q('.pg-aba.ativa')}.text()`)) === 'Pagamento Aprovado');
        chk('a prévia continua na tela e rola até a parte "Após pagamento aprovado"', (await js(`document.getElementById('pg-previa-corpo').scrollTop`)) > 50);
        await definir('#pg-msg-confirmado', 'Obrigado, {nome}! ✔');
        await marcar('#pg-botao-acesso-ativo', false);
        p = await previa();
        chk('botão de acesso desligado: some o botão e o link vai escrito na mensagem de aprovação', p[p.length - 1].indexOf('MSG:') === 0 && /Obrigado, João! ✔.*Acesso Liberado.*https:\/\/t\.me\/\+/.test(p[p.length - 1]) && !/Toque no botão/.test(p[p.length - 1]), p[p.length - 1]);
        await marcar('#pg-botao-acesso-ativo', true);
        await definir('#pg-botao-acesso-texto', 'Entrar no grupo');
        p = await previa();
        chk('botão de acesso com texto próprio na prévia', p[p.length - 1] === 'BOTOES:Entrar no grupo' && /Toque no botão abaixo/.test(p[p.length - 2]), p.slice(-2));
        await c.captura(SAIDA + '/2_aprovado.png');

        // ----- salvar
        const s1 = await salvar();
        const pg = (s1 && s1.dados_fluxograma && s1.dados_fluxograma.pagamentos) || {};
        chk('salvar grava o modelo novo (versao 2) com o que está na tela', pg.versao === 2 && pg.layout === 'padrao' && pg.msg_confirmado === 'Obrigado, {nome}! ✔' && pg.msg_instrucoes.indexOf('<b>Como realizar o pagamento:</b>') === 0
            && pg.midias_gerado.length === 2 && pg.midias_gerado[0].tipo === 'image' && pg.botao_acesso.texto === 'Entrar no grupo' && pg.btn_verificar.texto === 'Já paguei ✔' && pg.qr_exibicao === 'depois', pg);   // 'depois': foi a última posição escolhida durante o teste
        chk('e mantém os campos que o resto do sistema ainda lê (mostrar_copiar, mostrar_confirmar, mostrar_qrcode, qrcode_definido) coerentes', pg.mostrar_copiar === true && pg.mostrar_confirmar === true && pg.mostrar_qrcode === true && pg.qrcode_definido === true);
        chk('antes_codigo e antes_botoes ficam salvos como estavam (desligados) com o texto', pg.antes_codigo.ativo === false && pg.antes_botoes.ativo === false && pg.antes_botoes.texto === 'Após efetuar o pagamento, clique no botão abaixo');

        // ===================== reabrir o que foi salvo (modelo novo) =====================
        await abrir({ id: 5, nome: 'Novo', modo: 'basico', dados_fluxograma: Object.assign({ ativo: true, planos: [], ofertas: {} }, s1.dados_fluxograma) });
        chk('reabrir: tudo volta igual (texto, botões, QR, mídias, botão de acesso)', (await js(`${q('#pg-msg-confirmado')}.val()`)) === 'Obrigado, {nome}! ✔' && (await js(`${q('#pg-btn-verificar-texto')}.val()`)) === 'Já paguei ✔' && (await js(`${q('#pg-botao-acesso-texto')}.val()`)) === 'Entrar no grupo'
            && (await js(`${q('#pg-antes-botoes-ativo')}.is(':checked')`)) === false && (await slotsCheios()) === 2 && (await js(`${q('#pg-qr-posicao')}.val()`)) === 'depois');
        chk('reabrir sem mexer: a tela não fica "sem salvar"', (await js(`${q('#status-salvo')}.text()`)) === 'Tudo salvo', await js(`${q('#status-salvo')}.text()`));
        const s2 = await salvar();
        chk('salvar de novo sem mexer grava o mesmo (nada muda sozinho)', JSON.stringify(s2.dados_fluxograma.pagamentos) === JSON.stringify(pg));

        // ===================== fluxo ANTIGO (sem versao): a tela mostra o que ele fazia =====================
        await abrir({ id: 5, nome: 'Antigo', modo: 'basico', dados_fluxograma: { ativo: true, planos: [], ofertas: {}, pagamentos: { msg_instrucoes: 'Pague em 15 min', msg_confirmado: 'Aprovado!', mostrar_copiar: false, mostrar_qrcode: false, qrcode_definido: true, mostrar_confirmar: true } } });
        p = await previa();
        chk('fluxo antigo: a tela mostra o texto dele, QR desligado, só o botão de verificar (com o texto de sempre), sem mensagens antes e sem botão de acesso',
            (await js(`${q('#pg-msg-instrucoes')}.val()`)) === 'Pague em 15 min' && (await js(`${q('#pg-mostrar-qrcode')}.is(':checked')`)) === false && (await js(`${q('#pg-mostrar-copiar')}.is(':checked')`)) === false
            && (await js(`${q('#pg-btn-verificar-texto')}.val()`)) === 'Já fiz o pagamento ✅' && (await js(`${q('#pg-antes-botoes-ativo')}.is(':checked')`)) === false && (await js(`${q('#pg-antes-codigo-ativo')}.is(':checked')`)) === false && (await js(`${q('#pg-botao-acesso-ativo')}.is(':checked')`)) === false);
        chk('fluxo antigo: a prévia é a conversa que o bot de fato faz hoje (texto, código com botão "Já fiz o pagamento", aprovação com o link no texto)', tipos(p) === 'DIA MSG MSG BOTOES DIV MSG' && p[3] === 'BOTOES:Já fiz o pagamento ✅' && /Aprovado!.*Acesso Liberado.*https:\/\/t\.me\/\+/.test(p[5]), p);
        chk('fluxo antigo aberto e não mexido: continua "Tudo salvo"', (await js(`${q('#status-salvo')}.text()`)) === 'Tudo salvo');
        const s3 = await salvar();
        const pa = s3.dados_fluxograma.pagamentos;
        chk('salvar o fluxo antigo passa para o modelo novo SEM mudar o comportamento (QR e copiar desligados, texto igual, link no texto da aprovação)', pa.versao === 2 && pa.msg_instrucoes === 'Pague em 15 min' && pa.msg_confirmado === 'Aprovado!' && pa.mostrar_qrcode === false && pa.qr_exibicao === 'nao' && pa.mostrar_copiar === false
            && pa.btn_verificar.texto === 'Já fiz o pagamento ✅' && pa.botao_acesso.ativo === false && pa.antes_codigo.ativo === false && pa.antes_botoes.ativo === false && pa.layout === 'padrao', pa);

        // ===================== celular e tema claro =====================
        await abrir(null, 390, 900);
        chk('celular (390): sem rolagem lateral e a prévia vem abaixo do formulário', !(await js(`document.documentElement.scrollWidth > window.innerWidth + 2`)) && (await js(`${q('#pg-previa')}.is(':visible')`)) === true
            && (await js(`document.getElementById('pg-previa').getBoundingClientRect().top > document.getElementById('pg-msg-instrucoes').getBoundingClientRect().top`)) === true);
        await c.captura(SAIDA + '/3_celular.png');
        gerar('light');
        await abrir(null);
        chk('tema claro: a tela abre, a prévia continua escura e os campos têm fundo claro', (await js(`getComputedStyle(document.querySelector('.pg-tg')).backgroundColor`)) === 'rgb(14, 22, 33)' && (await js(`getComputedStyle(document.querySelector('.pg-linha')).backgroundColor`)) !== 'rgba(0, 0, 0, 0)');
        await js(`window.scrollTo(0,0)`);
        await c.captura(SAIDA + '/4_claro.png');
        chk('nenhum erro de JavaScript durante todo o teste', c.errosJs().length === 0, c.errosJs());
    } catch (e) {
        falhas++;
        console.log('ERRO NO TESTE: ' + e.message);
    } finally { await c.fechar(); }
    console.log('\n' + ok + ' ok, ' + falhas + ' falha(s)  (capturas em ' + SAIDA + ')');
    process.exit(falhas ? 1 : 0);
})();
