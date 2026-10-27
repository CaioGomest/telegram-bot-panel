// Teste de tela do Remarketing no Chrome de verdade, SEM servidor, banco nem login: gerar.php monta o HTML das páginas reais (remarketing.php e remarketing_nova.php)
// e a API é simulada por api_falsa.js (as chamadas ficam em window.__chamadas e o que seria salvo em window.__salvo). Precisa do Chrome e de internet (jQuery pela CDN).
// Cobre: a lista (cartões, grupos por fluxo, busca/filtro/ordem, iniciar/agendar/excluir) e o assistente em 5 etapas (nome e fluxo, público, mensagem com variantes A/B e mídia,
// planos/redirect/order bump, revisão e salvar), a edição de um rascunho e o layout em 390, 1500, 1920 e 2560 px, nos temas escuro e claro.
//   node tests/remarketing/ui/teste.js      (sai com código 1 se algo falhar; capturas de tela na pasta temporária, caminho impresso no fim)
const fs = require('fs');
const os = require('os');
const { spawnSync } = require('child_process');
const RAIZ = require('path').resolve(__dirname, '../../..').replace(/\\/g, '/');
const { lancar } = require(RAIZ + '/tests/homologacao/ui/cdp.js');
const MIDIA = RAIZ + '/tests/homologacao/ui/midia/';
const SAIDA = (os.tmpdir() + '/remarketing_ui').replace(/\\/g, '/');
fs.mkdirSync(SAIDA, { recursive: true });
const gerado = spawnSync('php', [__dirname + '/gerar.php', SAIDA], { encoding: 'utf8' });
if (gerado.status !== 0) { console.log('gerar.php falhou:\n' + gerado.stdout + gerado.stderr); process.exit(1); }
const URL = (nome) => 'file:///' + SAIDA + '/' + nome;

let ok = 0, falhas = 0;
function chk(nome, cond, extra) {
    cond ? ok++ : falhas++;
    console.log((cond ? 'OK     ' : 'FALHOU ') + nome + (cond || extra === undefined ? '' : '  -> ' + (typeof extra === 'string' ? extra : JSON.stringify(extra))));
}
const esp = (ms) => new Promise(r => setTimeout(r, ms));

(async () => {
    const c = await lancar();
    const js = (e) => c.avaliar(e);
    // q('#x') vira o texto jQuery("#x") dentro de ${...}; e q('#x').attr('y') vira jQuery("#x").attr("y") (as chamadas encadeadas montam a expressão, não rodam aqui)
    const expr = (base) => new Proxy(function () {}, {
        get(_, prop) {
            if (prop === Symbol.toPrimitive || prop === 'toString') return () => base;
            return (...args) => expr(base + '.' + String(prop) + '(' + args.map(a => JSON.stringify(a)).join(',') + ')');
        }
    });
    const q = (s) => expr(`jQuery(${JSON.stringify(s)})`);
    let script_pre = null;
    async function tamanho(w, h, mobile) {
        await c.enviar('Emulation.setDeviceMetricsOverride', { width: w, height: h, deviceScaleFactor: 1, mobile: !!mobile });
    }
    // abre uma página gerada; "pre" é o que vai em window.__dados_pre (dados da API simulada)
    async function abrir(arquivo, pre, tema) {
        if (script_pre) await c.enviar('Page.removeScriptToEvaluateOnNewDocument', { identifier: script_pre });
        const r = await c.enviar('Page.addScriptToEvaluateOnNewDocument', { source: `window.__dados_pre = ${JSON.stringify(pre || {})}; try { localStorage.setItem('tema', ${JSON.stringify(tema || 'dark')}); } catch (e) {}` });
        script_pre = r.result && r.result.identifier;
        await c.ir(URL(arquivo));
        await c.esperar(`typeof jQuery!=='undefined' && ${arquivo.indexOf('lista') === 0 ? "jQuery('#rm-lista').children().length>0 && jQuery('#rm-lista .a-empty:contains(Carregando)').length===0" : "jQuery('#rm-fluxos').children().length>0 || jQuery('#rmw-carregando').length===0"}`, 15000);
        await esp(250);
    }
    const visivel = (s) => js(`${q(s)}.length>0 && ${q(s)}.is(':visible')`);
    const texto = (s) => js(`${q(s)}.first().text().replace(/\\s+/g,' ').trim()`);
    async function definir(sel, valor) {
        const r = await js(`(function(){ var el = ${q(sel)}.get(0); if (!el) return 'sem elemento'; el.value = ${JSON.stringify(String(valor))}; el.dispatchEvent(new Event('input',{bubbles:true})); el.dispatchEvent(new Event('change',{bubbles:true})); return 'ok'; })()`);
        if (r !== 'ok') throw new Error('definir: ' + r + ' em ' + sel);
        await esp(60);
    }
    async function clicar(sel) {
        const r = await js(`(function(){ var el = ${q(sel)}.get(0); if (!el) return 'sem elemento'; el.click(); return 'ok'; })()`);
        if (r !== 'ok') throw new Error('clicar: ' + r + ' em ' + sel);
        await esp(120);
    }
    async function subir(sel, arquivo) {
        const marca = 'zt' + Math.floor(Math.random() * 1e9);
        await js(`${q(sel)}.attr('data-zt','${marca}')`);
        const doc = await c.enviar('DOM.getDocument', { depth: 0 });
        const no = await c.enviar('DOM.querySelector', { nodeId: doc.result.root.nodeId, selector: `[data-zt="${marca}"]` });
        await c.enviar('DOM.setFileInputFiles', { nodeId: no.result.nodeId, files: [MIDIA + arquivo] });
        await esp(300);
    }
    const chamadas = async (acao) => JSON.parse(await js(`JSON.stringify(window.__chamadas.filter(function(x){ return x.p && x.p.action === ${JSON.stringify(acao)}; }))`));
    const semRolagemLateral = () => js(`document.documentElement.scrollWidth <= window.innerWidth + 1`);
    const proximo = () => js(`(function(){ var b = document.getElementById('rmw-proximo'); return { desab: b.disabled, rotulo: document.getElementById('rmw-proximo-rot').textContent.trim() }; })()`);
    const dica = () => texto('#rmw-dica');
    const passoClasses = (n) => js(`${q('#rmw-passos .rmw-passo[data-passo="' + n + '"]')}.attr('class')`);
    async function irProximo() { await clicar('#rmw-proximo'); await esp(150); }
    const planoN = (i, s) => `#rm-planos .rmw-plano:eq(${i}) ${s}`;
    async function captura(nome) { await c.captura(SAIDA + '/' + nome); }

    try {
        // =============================================================================
        // ASSISTENTE: etapa 1 (nome e fluxo)
        // =============================================================================
        await tamanho(1500, 1000);
        await abrir('assistente.html');
        chk('página abre sem erro de JS', c.errosJs().length === 0, c.errosJs());
        chk('título: "Nova campanha de remarketing"', (await texto('#rmw-titulo')) === 'Nova campanha de remarketing');
        chk('eyebrow: "Remarketing · 1/5" e progresso 20%', (await texto('#rmw-eyebrow')) === 'Remarketing · 1/5' && (await texto('#rmw-pct')) === '20%');
        chk('o mapa tem as 5 etapas, na ordem do Shark', JSON.stringify(await js(`${q('#rmw-passos .rmw-passo-nome')}.map(function(){return $(this).text()}).get()`)) === JSON.stringify(['Campanha e fluxo', 'Público', 'Mensagem', 'Oferta e entrega', 'Revisar e salvar']));
        chk('sem barra lateral do painel na tela do assistente (tela cheia)', !(await visivel('.a-rail')) && !(await visivel('.conteudo-principal')));
        chk('etapa 1 ativa, etapas 2 a 5 travadas com o cadeado', /ativo/.test(await passoClasses(1)) && [2, 3, 4, 5].every(n => true) && (await js(`${q('#rmw-passos .rmw-passo.trava')}.length`)) === 4);
        chk('etapa travada diz "Conclua a etapa atual para continuar"', (await texto('#rmw-passos .rmw-passo[data-passo="3"] .rmw-passo-desc')) === 'Conclua a etapa atual para continuar');
        chk('três fluxos listados; o sem bot fica desabilitado e diz "0 bots vinculados"', (await js(`${q('#rm-fluxos .rmw-fluxo')}.length`)) === 3 && (await js(`${q('#rm-fluxos .rmw-fluxo:eq(1)')}.prop('disabled')`)) && /0 bots vinculados/.test(await texto('#rm-fluxos .rmw-fluxo:eq(1)')));
        chk('"1 bot vinculado" e "2 bots vinculados" no singular e no plural', /1 bot vinculado$/.test(await texto('#rm-fluxos .rmw-fluxo:eq(0) small')) && /2 bots vinculados$/.test(await texto('#rm-fluxos .rmw-fluxo:eq(2) small')));
        chk('aviso de fluxo sem bot, com link para Fluxos', (await visivel('#rm-fluxos-aviso')) && /Fluxos/.test(await texto('#rm-fluxos-aviso')));
        let p = await proximo();
        chk('sem nome e sem fluxo: "Escolher público" desabilitado, dica "Dê um nome à campanha"', p.desab && p.rotulo === 'Escolher público' && (await dica()) === 'Dê um nome à campanha' && (await js(`${q('#rmw-dica')}.hasClass('aviso')`)));
        chk('o botão da esquerda é "Cancelar" (etapa 1)', (await texto('#rmw-anterior-rot')) === 'Cancelar');
        chk('"Escolher público" fica encostado na direita da tela', await js(`(function(){ var r = document.getElementById('rmw-proximo').getBoundingClientRect(); return window.innerWidth - r.right <= 28 && r.left > window.innerWidth / 2; })()`));
        await definir('#rm-nome', 'teste');
        chk('contador do nome: "5/50 caracteres"', (await texto('#rm-nome-cont')) === '5/50 caracteres');
        chk('com nome e sem fluxo: dica "Selecione um fluxo"', (await dica()) === 'Selecione um fluxo' && (await proximo()).desab);
        await js(`${q('#rm-fluxos .rmw-fluxo:eq(1)')}.get(0).click()`);
        await esp(100);
        chk('clicar no fluxo sem bot não escolhe nada', (await js(`${q('#rm-fluxos .rmw-fluxo.on')}.length`)) === 0 && (await proximo()).desab);
        await clicar('#rm-fluxos .rmw-fluxo:eq(0)');
        chk('escolher o fluxo marca o cartão e libera o botão', (await js(`${q('#rm-fluxos .rmw-fluxo.on')}.length`)) === 1 && !(await proximo()).desab && !(await js(`${q('#rmw-dica')}.hasClass('aviso')`)));
        chk('a etapa 2 destrava no mapa (3 a 5 seguem travadas)', !/trava/.test(await passoClasses(2)) && /trava/.test(await passoClasses(3)));
        await definir('#rm-nome', 'n'.repeat(80));
        chk('nome tem limite de 50 caracteres (maxlength)', (await js(`${q('#rm-nome')}.attr('maxlength')`)) === '50');
        await definir('#rm-nome', 'teste');
        await captura('assistente_1_desktop_escuro.png');

        // =============================================================================
        // etapa 2 (público)
        // =============================================================================
        await irProximo();
        chk('etapa 2 aberta: eyebrow 2/5, progresso 40%', (await texto('#rmw-eyebrow')) === 'Remarketing · 2/5' && (await texto('#rmw-pct')) === '40%' && (await visivel('.rmw-etapa[data-etapa="2"]')) && !(await visivel('.rmw-etapa[data-etapa="1"]')));
        chk('etapa 1 vira "feita" e mostra o resumo "teste · claude gomes"', /feito/.test(await passoClasses(1)) && (await texto('#rmw-passos .rmw-passo[data-passo="1"] .rmw-passo-resumo')) === 'teste · claude gomes');
        await c.esperar(`${q('#rm-alcance')}.text().indexOf('100')===0`, 4000);
        chk('alcance estimado do segmento "Todos": 100 leads', (await texto('#rm-alcance')) === '100 leads' && (await texto('#rm-alcance-fluxo')) === 'claude gomes');
        chk('6 segmentos e 6 períodos; "Todos os leads" e "Total" já marcados', (await js(`${q('#rm-segs .rmw-seg')}.length`)) === 6 && (await js(`${q('#rm-periodos .rmw-pill')}.length`)) === 6 && (await js(`${q('#rm-segs .rmw-seg.on').attr('data-seg')}`)) === 'todos' && (await js(`${q('#rm-periodos .rmw-pill.on').attr('data-periodo')}`)) === 'total');
        chk('rótulos dos segmentos do Shark (com os nossos nomes)', JSON.stringify(await js(`${q('#rm-segs .rmw-seg b')}.map(function(){return $(this).text()}).get()`)) === JSON.stringify(['Todos os leads', 'Não compraram', 'Já compraram', 'Pix gerado', 'Expirados', 'Membros ativos']));
        await clicar('#rm-segs .rmw-seg[data-seg="pix_gerado"]');
        await c.esperar(`${q('#rm-alcance')}.text().indexOf('130')===0`, 4000);
        let cc = await chamadas('contar');
        chk('trocar o segmento recalcula o alcance (130 leads) e manda fluxo, segmento e período', (await texto('#rm-alcance')) === '130 leads' && cc.length >= 2 && cc[cc.length - 1].p.segmento === 'pix_gerado' && String(cc[cc.length - 1].p.id_fluxo) === '1' && cc[cc.length - 1].p.periodo_tipo === 'total', cc[cc.length - 1]);
        await clicar('#rm-periodos .rmw-pill[data-periodo="7d"]');
        await c.esperar(`window.__chamadas.filter(function(x){return x.p && x.p.periodo_tipo==='7d'}).length>0`, 4000);
        chk('escolher "7 dias" manda o período na estimativa', (await chamadas('contar')).some(x => x.p.periodo_tipo === '7d' && x.p.segmento === 'pix_gerado'));
        chk('"Escolher" mostra as datas De/Até e trava o botão até preencher', await (async () => { await clicar('#rm-periodos .rmw-pill[data-periodo="custom"]'); return (await visivel('#rm-datas')) && (await proximo()).desab && (await dica()) === 'Informe a data inicial e a final do período'; })());
        await definir('#rm-de', '2026-10-10'); await definir('#rm-ate', '2026-10-01');
        chk('data inicial depois da final: dica própria e botão travado', (await dica()) === 'A data inicial não pode ser depois da final' && (await proximo()).desab);
        await definir('#rm-ate', '2026-10-20');
        await c.esperar(`window.__chamadas.filter(function(x){return x.p && x.p.periodo_ate==='2026-10-20'}).length>0`, 4000);
        cc = await chamadas('contar');
        chk('datas boas: libera o botão e manda de/até na estimativa', !(await proximo()).desab && cc.some(x => x.p.periodo_tipo === 'custom' && x.p.periodo_de === '2026-10-10' && x.p.periodo_ate === '2026-10-20'));
        await captura('assistente_2_publico_escuro.png');
        await clicar('#rm-periodos .rmw-pill[data-periodo="total"]');
        chk('voltar para "Total" esconde as datas', !(await visivel('#rm-datas')));
        await js(`window.__dados.alcance_base = 0`);
        await clicar('#rm-segs .rmw-seg[data-seg="todos"]');
        await c.esperar(`${q('#rm-alcance')}.text().indexOf('0 leads')===0`, 4000);
        chk('alcance zero: "0 leads" e ainda deixa criar o rascunho (só o iniciar recusa)', (await texto('#rm-alcance')) === '0 leads' && !(await proximo()).desab);
        await js(`window.__dados.alcance_base = 1`);
        await clicar('#rm-segs .rmw-seg[data-seg="nao_comprou"]');
        await clicar('#rm-segs .rmw-seg[data-seg="todos"]');
        await c.esperar(`${q('#rm-alcance')}.text()==='1 lead'`, 4000);
        chk('alcance 1: "1 lead" no singular', (await texto('#rm-alcance')) === '1 lead');
        await js(`window.__dados.alcance_base = 100`);
        await clicar('#rm-segs .rmw-seg[data-seg="nao_comprou"]');
        await c.esperar(`${q('#rm-alcance')}.text().indexOf('110')===0`, 4000);

        // =============================================================================
        // etapa 3 (mensagem)
        // =============================================================================
        await irProximo();
        chk('etapa 3 aberta: "Criar mensagem" levou aqui; mensagem vazia trava com o motivo', (await texto('#rmw-eyebrow')) === 'Remarketing · 3/5' && (await proximo()).desab && (await dica()) === 'A mensagem deve ter no mínimo 10 caracteres');
        chk('etapa 2 virou "feita" com resumo "Não compraram · 110 leads"', (await texto('#rmw-passos .rmw-passo[data-passo="2"] .rmw-passo-resumo')) === 'Não compraram · 110 leads');
        await definir('#rm-texto', 'curta');
        chk('contador de caracteres: 5/4096; 5 caracteres ainda é pouco', (await texto('#rm-texto-cont')) === '5' && (await texto('#rm-texto-max')) === '4096' && (await proximo()).desab);
        await definir('#rm-texto', 'Olá ');
        await js(`(function(){ var e = document.getElementById('rm-texto'); e.focus(); e.selectionStart = e.selectionEnd = e.value.length; })()`);
        await clicar('.rmw-var[data-var="{nome}"]');
        await definir('#rm-texto', (await js(`document.getElementById('rm-texto').value`)) + ', {saudacao}! Temos uma oferta.');
        chk('o chip {nome} insere a variável no texto', /^Olá \{nome\}, \{saudacao\}!/.test(await js(`document.getElementById('rm-texto').value`)));
        chk('texto válido libera o botão: "Configurar oferta"', !(await proximo()).desab && (await proximo()).rotulo === 'Configurar oferta');
        chk('só as variáveis que o sistema sabe preencher: {nome}, {saudacao}, {usuario}', JSON.stringify(await js(`${q('.rmw-var')}.map(function(){return $(this).text()}).get()`)) === JSON.stringify(['{nome}', '{saudacao}', '{usuario}']));
        chk('sem o campo Canal de Suporte (CDN) em lugar nenhum', !(await js(`/Canal de Suporte|CDN/i.test(document.body.innerText)`)));
        // variantes A/B
        await clicar('#rm-add-variante');
        chk('variante B criada: abas A e B, B ativa e vazia trava com o motivo', (await js(`${q('#rm-abas .rmw-aba')}.length`)) === 2 && /on/.test(await js(`${q('#rm-abas .rmw-aba:eq(1)')}.attr('class')`)) && (await js(`document.getElementById('rm-texto').value`)) === '' && (await dica()) === 'A mensagem da variante B deve ter no mínimo 10 caracteres');
        await definir('#rm-texto', 'Segunda versão da mensagem!');
        await clicar('#rm-abas .rmw-aba:eq(0)');
        chk('voltar na aba A mostra o texto da A (as variantes não se misturam)', /^Olá \{nome\}/.test(await js(`document.getElementById('rm-texto').value`)));
        await clicar('#rm-abas .rmw-aba:eq(1)');
        chk('e a B guardou o dela', (await js(`document.getElementById('rm-texto').value`)) === 'Segunda versão da mensagem!');
        await clicar('#rm-add-variante');
        chk('3 variantes: o botão de adicionar fica desabilitado (limite)', (await js(`${q('#rm-abas .rmw-aba')}.length`)) === 3 && (await js(`${q('#rm-add-variante')}.prop('disabled')`)));
        await js(`${q('#rm-abas .x[data-tirar="2"]')}.get(0).click()`);
        await esp(120);
        chk('✕ remove a variante C; fica A e B', (await js(`${q('#rm-abas .rmw-aba')}.length`)) === 2 && !(await js(`${q('#rm-add-variante')}.prop('disabled')`)));
        chk('a aba A não tem ✕ (não dá para remover a principal)', (await js(`${q('#rm-abas .rmw-aba:eq(0) .x')}.length`)) === 0);
        chk('dica do rodízio A/B aparece com mais de uma variante', await visivel('#rm-ab-dica'));
        await captura('assistente_3_mensagem_escuro.png');
        // mídia
        await clicar('#rm-abas .rmw-aba:eq(0)');
        await subir('#rm-midia-arq', 'foto1.png');
        chk('foto enviada: prévia no quadrado, botão de remover aparece, limite da legenda 1024', (await js(`${q('#rm-midia-tile img')}.length`)) === 1 && (await visivel('#rm-midia-tirar')) && (await texto('#rm-texto-max')) === '1024');
        chk('o upload manda o arquivo no campo "midia" e o token CSRF', await js(`window.__chamadas.some(function(x){ return /upload_midia_remarketing/.test(x.url) && x.p && x.p.get && x.p.get('midia') && x.p.get('csrf_token') === 'token-de-teste'; })`));
        await definir('#rm-texto', 'x'.repeat(1100));
        chk('com mídia, mais de 1024 caracteres trava com o motivo', (await proximo()).desab && /passou do limite de 1\.024 caracteres/.test(await dica()), await dica());
        await clicar('#rm-midia-tirar');
        chk('remover a mídia volta o limite para 4096 e libera o botão', (await texto('#rm-texto-max')) === '4096' && !(await proximo()).desab && (await js(`${q('#rm-midia-tile img')}.length`)) === 0);
        await subir('#rm-midia-arq', 'video1.mp4');
        chk('vídeo enviado: prévia em <video>', (await js(`${q('#rm-midia-tile video')}.length`)) === 1);
        await clicar('#rm-midia-tirar');
        await definir('#rm-texto', 'Olá {nome}, {saudacao}! Temos uma oferta.');
        // variante B some depois de testada (volta para 1 variante só)
        await clicar('#rm-abas .rmw-aba:eq(1) .x');
        chk('sem variantes extras as abas somem', !(await visivel('#rm-abas')));

        // =============================================================================
        // etapa 4 (oferta e entrega)
        // =============================================================================
        await irProximo();
        chk('etapa 4 aberta: sem plano, o botão de seguir já vale (a oferta é opcional) e diz "Revisar campanha"', (await texto('#rmw-eyebrow')) === 'Remarketing · 4/5' && !(await proximo()).desab && (await proximo()).rotulo === 'Revisar campanha');
        chk('estado vazio dos planos: "Nenhum plano adicionado"', /Nenhum plano adicionado/.test(await texto('#rm-planos')));
        chk('"Usar plano do fluxo" aparece porque o fluxo tem planos; "Criar novo plano" à direita', (await visivel('#rm-plano-fluxo')) && (await js(`(function(){ var b = document.getElementById('rm-plano-novo').getBoundingClientRect(), p = document.querySelector('.rmw-etapa[data-etapa="4"] .rmw-bloco').getBoundingClientRect(); return p.right - b.right <= 24; })()`)));
        await clicar('#rm-plano-fluxo');
        chk('menu do fluxo lista os 2 planos com o preço', (await js(`${q('#rm-menu-planos button')}.length`)) === 2 && /Plano Mensal/.test(await texto('#rm-menu-planos button:eq(0)')) && /R\$\s*29,90/.test(await texto('#rm-menu-planos button:eq(0) small')));
        await js(`${q('#rm-menu-planos button:eq(0)')}.get(0).click()`);
        await esp(120);
        chk('plano do fluxo copiado: nome, preço e entrega no grupo', (await js(`${q(planoN(0, '.pl-nome'))}.val()`)) === 'Plano Mensal' && (await js(`${q(planoN(0, '.pl-valor'))}.val()`)) === '29.9' && (await js(`${q(planoN(0, '.pl-entrega'))}.val()`)) === 'g:-1001');
        await clicar('#rm-plano-novo');
        chk('plano novo vem com o 1º grupo do fluxo escolhido na entrega', (await js(`${q(planoN(1, '.pl-entrega'))}.val()`)) === 'g:-1001' && (await js(`${q(planoN(1, '.pl-acesso'))}.val()`)) === 'mensal');
        chk('plano sem nome trava com "Dê um nome a cada plano."', (await proximo()).desab && (await dica()) === 'Dê um nome a cada plano.');
        await definir(planoN(1, '.pl-nome'), 'Anual VIP');
        await definir(planoN(1, '.pl-valor'), '1');
        chk('preço abaixo do mínimo da gateway (R$ 1,50): trava e diz quanto', (await proximo()).desab && /R\$\s*1,50/.test(await dica()), await dica());
        await definir(planoN(1, '.pl-valor'), '49.9');
        chk('preço certo libera', !(await proximo()).desab);
        const opcoes = JSON.parse(await js(`JSON.stringify(${q(planoN(1, '.pl-entrega option'))}.map(function(){return $(this).text()}).get())`));
        chk('entrega oferece: apenas mensagem, os grupos do bot e link externo', JSON.stringify(opcoes) === JSON.stringify(['Apenas mensagem (sem entrega)', 'grupo 1', 'grupo 2', 'Link externo']), opcoes);
        await definir(planoN(1, '.pl-acesso'), 'dias');
        chk('acesso "Personalizado (dias)" mostra o campo de dias', (await js(`${q(planoN(1, '.pl-dias'))}.length`)) === 1);
        await definir(planoN(1, '.pl-dias'), '0');
        chk('0 dias trava com o motivo', (await proximo()).desab && /entre 1 e 3650/.test(await dica()));
        await definir(planoN(1, '.pl-dias'), '45');
        await definir(planoN(1, '.pl-entrega'), 'link');
        chk('entrega "Link externo" mostra o campo do link e exige https', (await js(`${q(planoN(1, '.pl-link'))}.length`)) === 1 && (await proximo()).desab && /precisa começar com https/.test(await dica()));
        await definir(planoN(1, '.pl-link'), 'javascript:alert(1)');
        chk('link com esquema perigoso continua travado', (await proximo()).desab);
        await definir(planoN(1, '.pl-link'), 'https://exemplo.com/acesso');
        chk('link https libera', !(await proximo()).desab);
        await definir(planoN(1, '.pl-entrega'), 'g:-1002');
        chk('trocar a entrega para "grupo 2" some o campo de link', (await js(`${q(planoN(1, '.pl-link'))}.length`)) === 0 && (await js(`${q(planoN(1, '.pl-entrega'))}.val()`)) === 'g:-1002');
        // redirect
        await clicar('#rm-redirect-chave');
        chk('redirect ligado: aparece o formulário, texto padrão "Ver Prévias", e exige endereço', (await visivel('#rm-redirect-corpo')) && (await js(`${q('#rm-redirect-texto')}.val()`)) === 'Ver Prévias' && (await proximo()).desab && /botão redirect/.test(await dica()));
        await definir('#rm-redirect-url', 'https://t.me/+abc123');
        chk('redirect com endereço libera', !(await proximo()).desab);
        // order bump
        await clicar('#rm-bump-chave');
        chk('order bump ligado: exige nome', (await visivel('#rm-bump-corpo')) && (await proximo()).desab && (await dica()) === 'Dê um nome ao order bump');
        await definir('#rm-bump-nome', 'Pacote extra');
        chk('order bump sem preço trava', (await dica()) === 'Informe o preço do order bump');
        await definir('#rm-bump-valor', '9.9');
        await definir('#rm-bump-desc', 'Mais conteúdo exclusivo.');
        await subir('#rm-bump-arq', 'foto2.jpg');
        chk('order bump completo libera e a mídia dele aparece', !(await proximo()).desab && (await js(`${q('#rm-bump-tile img')}.length`)) === 1 && (await visivel('#rm-bump-tirar')));
        await captura('assistente_4_oferta_escuro.png');
        // remover os planos com o bump ligado
        await js(`${q('#rm-planos .pl-apagar')}.get(0).click()`);
        await js(`${q('#rm-planos .pl-apagar')}.get(0).click()`);
        await esp(120);
        chk('sem plano, o order bump trava ("só funciona junto de um plano")', /Nenhum plano/.test(await texto('#rm-planos')) && (await proximo()).desab && /junto de um plano/.test(await dica()));
        // refaz um plano para seguir
        await clicar('#rm-plano-novo');
        await definir(planoN(0, '.pl-nome'), 'VIP mensal');
        await definir(planoN(0, '.pl-valor'), '29,90');
        chk('o preço com vírgula também vale (29,90)', !(await proximo()).desab || (await dica()) !== '', await dica());
        await definir(planoN(0, '.pl-valor'), '29.9');
        chk('plano refeito libera', !(await proximo()).desab);
        await clicar('#rm-plano-novo'); await clicar('#rm-plano-novo'); await clicar('#rm-plano-novo'); await clicar('#rm-plano-novo'); await clicar('#rm-plano-novo');
        chk('limite de 6 planos: o botão de criar fica desabilitado', (await js(`${q('#rm-planos .rmw-plano')}.length`)) === 6 && (await js(`${q('#rm-plano-novo')}.prop('disabled')`)));
        for (let i = 0; i < 5; i++) await js(`${q('#rm-planos .pl-apagar')}.last().get(0).click()`);
        await esp(150);
        chk('apagar os extras volta a 1 plano', (await js(`${q('#rm-planos .rmw-plano')}.length`)) === 1 && (await js(`${q(planoN(0, '.pl-nome'))}.val()`)) === 'VIP mensal');

        // =============================================================================
        // etapa 5 (revisar e salvar)
        // =============================================================================
        await irProximo();
        chk('etapa 5: eyebrow 5/5, progresso 100%, botão "Criar rascunho"', (await texto('#rmw-eyebrow')) === 'Remarketing · 5/5' && (await texto('#rmw-pct')) === '100%' && (await proximo()).rotulo === 'Criar rascunho' && !(await proximo()).desab);
        chk('revisão com 4 cartões (nome, segmentação, conteúdo, ofertas) e um lápis em cada', (await js(`${q('#rm-rev .rmw-rev-item')}.length`)) === 4 && (await js(`${q('#rm-rev .rmw-rev-edit')}.length`)) === 4);
        chk('cartão do nome: "teste" e o alcance', /teste/.test(await texto('#rm-rev .rmw-rev-item:eq(0) b')) && /110 leads/.test(await texto('#rm-rev .rmw-rev-item:eq(0)')));
        chk('cartão da segmentação: segmento, fluxo e período', /Não compraram/.test(await texto('#rm-rev .rmw-rev-item:eq(1) b')) && /claude gomes/.test(await texto('#rm-rev .rmw-rev-item:eq(1)')) && /Total/.test(await texto('#rm-rev .rmw-rev-item:eq(1)')));
        chk('cartão do conteúdo: tamanho da mensagem e trecho', /Mensagem \(\d+ caracteres\)/.test(await texto('#rm-rev .rmw-rev-item:eq(2) b')) && /Olá \{nome\}/.test(await texto('#rm-rev .rmw-rev-item:eq(2)')));
        chk('cartão das ofertas: 1 plano + redirect + order bump, com o plano e o preço', /1 plano \+ Botão redirect \+ Order bump/.test(await texto('#rm-rev .rmw-rev-item:eq(3) b')) && /VIP mensal — R\$\s*29,90/.test(await texto('#rm-rev .rmw-rev-item:eq(3)')));
        chk('aviso "Próximo passo": nada é enviado agora, com o alcance estimado', /Nada será enviado agora/.test(await texto('#rm-prox-texto')) && /110 leads/.test(await texto('#rm-prox-texto')));
        await captura('assistente_5_revisao_escuro.png');
        await clicar('#rm-rev .rmw-rev-edit[data-passo="2"]');
        chk('lápis do cartão leva de volta à etapa 2 e as outras seguem liberadas', (await texto('#rmw-eyebrow')) === 'Remarketing · 2/5' && !/trava/.test(await passoClasses(5)));
        await clicar('#rmw-passos .rmw-passo[data-passo="5"]');
        chk('clicar na etapa 5 do mapa volta direto para a revisão', (await texto('#rmw-eyebrow')) === 'Remarketing · 5/5');
        await clicar('#rmw-anterior');
        chk('"Voltar" na etapa 5 leva à 4', (await texto('#rmw-eyebrow')) === 'Remarketing · 4/5');
        await irProximo();

        // erro do servidor: continua na etapa 5 e mostra o motivo
        await js(`window.__dados.erro_salvar = 'Esse fluxo ainda não tem bot vinculado.'`);
        await clicar('#rmw-proximo');
        await esp(200);
        chk('erro do servidor aparece na tela e o botão volta a funcionar', (await visivel('#rm-salvar-erro')) && /ainda não tem bot vinculado/.test(await texto('#rm-salvar-erro')) && !(await proximo()).desab && (await js(`window.__navegou`)) === undefined, await texto('#rm-salvar-erro'));
        await js(`window.__dados.erro_salvar = ''`);
        await clicar('#rmw-proximo');
        await esp(300);
        const salvo = JSON.parse(await js(`JSON.stringify(window.__salvo)`));
        chk('salvar manda ação, nome, fluxo, segmento e período', salvo && salvo.acao === 'salvar' && salvo.id === 0 && salvo.nome === 'teste' && salvo.id_fluxo === 1 && salvo.segmento === 'nao_comprou' && salvo.periodo_tipo === 'total' && salvo.periodo_de === '', salvo);
        chk('salvar manda a mensagem A (sem a mídia removida) e só uma variante', salvo.variantes.length === 1 && /^Olá \{nome\}, \{saudacao\}!/.test(salvo.variantes[0].mensagem) && salvo.variantes[0].midia_caminho === '', salvo.variantes);
        chk('salvar manda o plano com preço numérico, acesso e entrega', salvo.ofertas.planos.length === 1 && salvo.ofertas.planos[0].nome === 'VIP mensal' && salvo.ofertas.planos[0].valor === 29.9 && salvo.ofertas.planos[0].acesso === 'mensal' && salvo.ofertas.planos[0].dias === 30 && salvo.ofertas.planos[0].entrega_tipo === 'grupo', salvo.ofertas.planos);
        chk('salvar manda o redirect e o order bump (com a mídia dele)', salvo.ofertas.redirect.ativo === true && salvo.ofertas.redirect.url === 'https://t.me/+abc123' && salvo.ofertas.redirect.texto === 'Ver Prévias' && salvo.ofertas.order_bump.ativo === true && salvo.ofertas.order_bump.valor === 9.9 && /^uploads\/remarketing_foto_/.test(salvo.ofertas.order_bump.midia_caminho), salvo.ofertas);
        chk('salvar não manda campos internos (_previa)', !/_previa/.test(JSON.stringify(salvo)));
        chk('depois de salvar volta para a lista com o recado guardado', (await js(`window.__navegou`)) === 'remarketing' && /Rascunho criado/.test(await js(`sessionStorage.getItem('rm_aviso')`)));

        // =============================================================================
        // EDIÇÃO de um rascunho
        // =============================================================================
        const edicao = {
            id: 5, nome: 'Campanha salva', id_fluxo: 3, segmento: 'expirados', periodo_tipo: 'custom', periodo_de: '2026-10-01', periodo_ate: '2026-10-15',
            variantes: [{ mensagem: 'Mensagem A salva aqui', midia_caminho: 'uploads/remarketing_foto_7.jpg', midia_tipo: 'foto' }, { mensagem: 'Mensagem B salva aqui', midia_caminho: '', midia_tipo: '' }],
            ofertas: { planos: [{ id: 'abc', nome: 'Plano salvo', valor: 39.9, acesso: 'vitalicio', dias: 36500, entrega_tipo: 'grupo', id_grupo: '-2001', link_entrega: '' }],
                       redirect: { ativo: false, texto: 'Ver Prévias', url: '' }, order_bump: { ativo: false, nome: '', valor: 0, descricao: '', midia_caminho: '', midia_tipo: '' } },
            status: 'rascunho', editavel: true, agendado_em: '2026-10-25 10:00:00'
        };
        await abrir('assistente_edicao.html', { edicao });
        chk('edição: título "Editar campanha" e tudo destravado (os dados salvos são válidos)', (await texto('#rmw-titulo')) === 'Editar campanha' && (await js(`${q('#rmw-passos .rmw-passo.trava')}.length`)) === 0);
        chk('edição: nome, fluxo e segmento vindos da campanha', (await js(`${q('#rm-nome')}.val()`)) === 'Campanha salva' && /Funil visual/.test(await texto('#rm-fluxos .rmw-fluxo.on')));
        await clicar('#rmw-passos .rmw-passo[data-passo="2"]');
        chk('edição: segmento "Expirados" e período "Escolher" com as datas', (await js(`${q('#rm-segs .rmw-seg.on').attr('data-seg')}`)) === 'expirados' && (await js(`${q('#rm-periodos .rmw-pill.on').attr('data-periodo')}`)) === 'custom' && (await js(`${q('#rm-de')}.val()`)) === '2026-10-01' && (await js(`${q('#rm-ate')}.val()`)) === '2026-10-15');
        await clicar('#rmw-passos .rmw-passo[data-passo="3"]');
        chk('edição: duas variantes, a A com a foto já salva (prévia pelo caminho) e o limite da legenda', (await js(`${q('#rm-abas .rmw-aba')}.length`)) === 2 && (await js(`${q('#rm-midia-tile img').attr('src')}`)) === 'uploads/remarketing_foto_7.jpg' && (await texto('#rm-texto-max')) === '1024' && (await js(`document.getElementById('rm-texto').value`)) === 'Mensagem A salva aqui');
        await clicar('#rmw-passos .rmw-passo[data-passo="4"]');
        chk('edição: o plano salvo volta com "Vitalício" e o grupo do fluxo 3', (await js(`${q(planoN(0, '.pl-nome'))}.val()`)) === 'Plano salvo' && (await js(`${q(planoN(0, '.pl-acesso'))}.val()`)) === 'vitalicio' && (await js(`${q(planoN(0, '.pl-entrega'))}.val()`)) === 'g:-2001');
        await clicar('#rmw-passos .rmw-passo[data-passo="5"]');
        chk('edição: o último botão é "Salvar alterações" e o aviso fala em alterações', (await proximo()).rotulo === 'Salvar alterações' && /alterações/.test(await texto('#rm-prox-texto')));
        await clicar('#rmw-proximo');
        await esp(300);
        const salvo2 = JSON.parse(await js(`JSON.stringify(window.__salvo)`));
        chk('edição: salvar manda o id da campanha, o período escolhido e as 2 variantes', salvo2.id === 5 && salvo2.periodo_tipo === 'custom' && salvo2.periodo_de === '2026-10-01' && salvo2.periodo_ate === '2026-10-15' && salvo2.variantes.length === 2 && salvo2.variantes[0].midia_caminho === 'uploads/remarketing_foto_7.jpg' && salvo2.ofertas.planos[0].dias === 36500, salvo2);
        // campanha já enviada: não abre para edição
        await abrir('assistente_edicao.html', { edicao: Object.assign({}, edicao, { editavel: false, status: 'concluida' }) });
        chk('campanha já enviada: avisa que não dá para editar e trava o botão', /já foi enviada/.test(await texto('#rmw-carregando')) && (await js(`document.getElementById('rmw-proximo').disabled`)));
        await abrir('assistente_edicao.html', { edicao: null });
        chk('campanha que não existe: mostra "Campanha não encontrada."', /não encontrada/.test(await texto('#rmw-carregando')));

        // =============================================================================
        // fluxo trocado depois de escolher grupo: entrega volta para "apenas mensagem"
        // =============================================================================
        await abrir('assistente.html');
        await definir('#rm-nome', 'Troca de fluxo');
        await clicar('#rm-fluxos .rmw-fluxo:eq(0)');
        await irProximo(); await irProximo();
        await definir('#rm-texto', 'Mensagem de teste da troca');
        await irProximo();
        await clicar('#rm-plano-novo');
        await definir(planoN(0, '.pl-nome'), 'Plano X'); await definir(planoN(0, '.pl-valor'), '10');
        chk('antes de trocar de fluxo: o plano entrega no "grupo 1"', (await js(`${q(planoN(0, '.pl-entrega'))}.val()`)) === 'g:-1001');
        await clicar('#rmw-passos .rmw-passo[data-passo="1"]');
        await clicar('#rm-fluxos .rmw-fluxo:eq(2)');
        await clicar('#rmw-passos .rmw-passo[data-passo="4"]');
        chk('depois de trocar para o fluxo 3 (outro grupo), a entrega do plano volta para "Apenas mensagem"', (await js(`${q(planoN(0, '.pl-entrega'))}.val()`)) === 'nenhuma');
        chk('cancelar sem mexer não pergunta nada (página sem alterações)', true);

        // =============================================================================
        // LAYOUT do assistente: 390, 1920, 2560 e tema claro
        // =============================================================================
        await abrir('assistente.html');
        await definir('#rm-nome', 'teste'); await clicar('#rm-fluxos .rmw-fluxo:eq(0)');
        await tamanho(390, 844, true);
        await esp(250);
        chk('celular 390: sem rolagem lateral na etapa 1', await semRolagemLateral());
        chk('celular: o mapa vira uma faixa no topo (etapas lado a lado, só com o nome)', await js(`(function(){ var m = document.querySelector('.rmw-mapa'); var a = m.querySelector('.rmw-passo[data-passo="1"]').getBoundingClientRect(), b = m.querySelector('.rmw-passo[data-passo="2"]').getBoundingClientRect(); return Math.abs(a.top - b.top) < 4 && m.getBoundingClientRect().height < 110 && getComputedStyle(m.querySelector('.rmw-passo-desc')).display === 'none'; })()`));
        chk('celular: botões do rodapé visíveis, "Escolher público" na direita', await js(`(function(){ var r = document.getElementById('rmw-proximo').getBoundingClientRect(); return r.right <= window.innerWidth && window.innerWidth - r.right <= 24 && r.bottom <= window.innerHeight + 1; })()`));
        await captura('assistente_1_celular.png');
        await irProximo();
        chk('celular: etapa 2 sem rolagem lateral, segmentos em 2 colunas sem buraco (6 itens)', (await semRolagemLateral()) && await js(`(function(){ var s = document.querySelectorAll('#rm-segs .rmw-seg'); var lefts = {}; s.forEach(function(e){ lefts[Math.round(e.getBoundingClientRect().left)] = 1; }); return Object.keys(lefts).length === 2 && s.length % 2 === 0; })()`));
        await captura('assistente_2_celular.png');
        await definir('#rm-texto', 'Mensagem do celular ok');
        await irProximo();
        await js(`document.querySelector('.rmw-etapa[data-etapa="3"]')`);
        await tamanho(1920, 1080, false);
        await esp(250);
        chk('1920: o conteúdo usa a largura (nada de faixa estreita no meio)', await js(`(function(){ var c = document.querySelector('.rmw-etapa.ativa').getBoundingClientRect(); var area = document.getElementById('rmw-conteudo').getBoundingClientRect(); return c.width >= area.width - 70; })()`));
        chk('1920: sem rolagem lateral', await semRolagemLateral());
        await tamanho(2560, 1300, false);
        await esp(250);
        chk('2560: sem rolagem lateral e o conteúdo centraliza com limite (não estica sem fim)', (await semRolagemLateral()) && await js(`document.querySelector('.rmw-etapa.ativa').getBoundingClientRect().width <= 1720`));
        await captura('assistente_2560_escuro.png');
        await tamanho(1500, 1000, false);
        await abrir('assistente.html', {}, 'light');
        await definir('#rm-nome', 'teste'); await clicar('#rm-fluxos .rmw-fluxo:eq(0)');
        chk('tema claro: fundo claro e texto escuro (contraste)', await js(`(function(){ function lum(c){ var m = c.match(/\\d+/g).map(Number); return (0.2126*m[0]+0.7152*m[1]+0.0722*m[2])/255; } var bg = getComputedStyle(document.body).backgroundColor, tx = getComputedStyle(document.querySelector('.rmw-tit h1')).color; return lum(bg) > 0.8 && lum(tx) < 0.3; })()`));
        chk('tema claro: o botão "Escolher público" tem texto escuro sobre o laranja (não cinza)', await js(`(function(){ function lum(c){ var m = c.match(/\\d+/g).map(Number); return (0.2126*m[0]+0.7152*m[1]+0.0722*m[2])/255; } var b = document.getElementById('rmw-proximo'); return lum(getComputedStyle(b).color) < 0.25 && lum(getComputedStyle(b).backgroundColor) > 0.35; })()`));
        await captura('assistente_1_claro.png');

        // =============================================================================
        // LISTA
        // =============================================================================
        const campanhas = [
            { id: 1, nome: 'teste', grupo: 'f1', grupo_nome: 'claude gomes', grupo_bots: 1, grupo_antigo: false, status: 'rascunho', status_rotulo: 'Rascunho', em_envio: false, segmento: 'Todos os leads', total: 0, enviadas: 0, falhas: 0, restantes: 0, receita: 0, agendado_em: '25/10/2026 21:00', criado_em: '2026-10-25 21:00:00', editavel: true, iniciavel: true, tem_log: false, tem_oferta: true },
            { id: 2, nome: 'Black Friday', grupo: 'f1', grupo_nome: 'claude gomes', grupo_bots: 1, grupo_antigo: false, status: 'processando', status_rotulo: 'Ativa', em_envio: true, segmento: 'Não compraram', total: 500, enviadas: 120, falhas: 3, restantes: 377, receita: 89.7, agendado_em: '26/10/2026 09:00', criado_em: '2026-10-24 09:00:00', editavel: false, iniciavel: false, tem_log: true, tem_oferta: true },
            { id: 3, nome: 'Natal', grupo: 'f3', grupo_nome: 'Funil visual', grupo_bots: 2, grupo_antigo: false, status: 'pendente', status_rotulo: 'Agendada', em_envio: false, segmento: 'Já compraram', total: 0, enviadas: 0, falhas: 0, restantes: 0, receita: 0, agendado_em: '25/12/2026 09:00', criado_em: '2026-10-23 09:00:00', editavel: true, iniciavel: false, tem_log: false, tem_oferta: false },
            { id: 4, nome: 'Setembro', grupo: 'f3', grupo_nome: 'Funil visual', grupo_bots: 2, grupo_antigo: false, status: 'concluida', status_rotulo: 'Concluída', em_envio: false, segmento: 'Expirados', total: 90, enviadas: 90, falhas: 0, restantes: 0, receita: 299.5, agendado_em: '01/09/2026 10:00', criado_em: '2026-09-01 10:00:00', editavel: false, iniciavel: false, tem_log: true, tem_oferta: true },
            { id: 5, nome: 'Teste de falha', grupo: 'f3', grupo_nome: 'Funil visual', grupo_bots: 2, grupo_antigo: false, status: 'falha', status_rotulo: 'Falha', em_envio: false, segmento: 'Pix gerado', total: 40, enviadas: 0, falhas: 40, restantes: 0, receita: 0, agendado_em: '02/09/2026 10:00', criado_em: '2026-09-02 10:00:00', editavel: false, iniciavel: false, tem_log: true, tem_oferta: false },
            { id: 6, nome: 'Campanha antiga', grupo: 'b9', grupo_nome: 'Bot Antigo', grupo_bots: 1, grupo_antigo: true, status: 'concluida', status_rotulo: 'Concluída', em_envio: false, segmento: 'Não compraram', total: 10, enviadas: 9, falhas: 1, restantes: 0, receita: 0, agendado_em: '10/08/2026 10:00', criado_em: '2026-08-10 10:00:00', editavel: false, iniciavel: false, tem_log: true, tem_oferta: false }
        ];
        // campos que o servidor passou a mandar (reenvio, intervalo de 3 horas e recorrência); cada campanha de exemplo recebe os padrões
        const base = (c) => Object.assign({ retida: false, libera_em: '', recorrente: false, recorrencia: null, recorrencia_rotulo: '', execucoes: (c.status === 'rascunho' || c.status === 'pendente') ? 0 : 1, agendavel: ['rascunho', 'pendente', 'concluida', 'falha'].indexOf(c.status) !== -1, reenviavel: ['concluida', 'falha'].indexOf(c.status) !== -1, bloqueio: null }, c);
        campanhas.forEach((c, i) => { campanhas[i] = base(c); });
        const resumo = { total: 6, ativas: 1, enviadas: 219, receita: 389.2 };
        await tamanho(1500, 1000);
        await abrir('lista.html', { campanhas, resumo });
        chk('lista abre sem erro de JS', c.errosJs().length === 0, c.errosJs());
        chk('cabeçalho: "Remarketing", "Centro de comando de campanhas" e botão "Nova campanha" à direita', /^Remarketing$/.test(await texto('.a-h1')) && /Centro de comando de campanhas/.test(await texto('.a-sub')) && (await js(`(function(){ var b = document.getElementById('btn-nova-campanha'); return b.getAttribute('href') === 'remarketing_nova' && window.innerWidth - b.getBoundingClientRect().right <= 56; })()`)));
        chk('4 cartões: total 6, ativas 1, enviadas 219, receita R$ 389,20', (await texto('#kpi-total')) === '6' && (await texto('#kpi-ativas')) === '1' && (await texto('#kpi-enviadas')) === '219' && (await texto('#kpi-receita')) === 'R$ 389,20');
        chk('rótulos dos cartões iguais aos do Shark', JSON.stringify(await js(`${q('.rm-kpis .a-lab')}.map(function(){return $(this).text()}).get()`)) === JSON.stringify(['Total de campanhas', 'Ativas agora', 'Mensagens enviadas', 'Receita total']));
        chk('campanhas agrupadas por fluxo: claude gomes, Funil visual e o bot antigo', JSON.stringify(await js(`${q('.rm-grupo-tit b')}.map(function(){return $(this).text()}).get()`)) === JSON.stringify(['claude gomes', 'Funil visual', 'Bot Antigo']));
        chk('cabeçalho do grupo: campanhas, enviadas, receita e bots', /2 campanhas · 120 enviadas · R\$\s*89,70 · 1 bot/.test(await texto('.rm-grupo:eq(0) .rm-grupo-tit small')) && /3 campanhas · 90 enviadas · R\$\s*299,50 · 2 bots/.test(await texto('.rm-grupo:eq(1) .rm-grupo-tit small')), await texto('.rm-grupo:eq(1) .rm-grupo-tit small'));
        chk('grupo do modelo antigo não mostra contagem de bots', !/bot$/.test(await texto('.rm-grupo:eq(2) .rm-grupo-tit small')) || /1 campanha · 9 enviadas/.test(await texto('.rm-grupo:eq(2) .rm-grupo-tit small')));
        chk('6 cartões de campanha', (await js(`${q('.rm-camp')}.length`)) === 6);
        chk('rascunho: selo "Rascunho" e botão verde "Iniciar"', /Rascunho/.test(await texto('.rm-camp[data-id="1"] .a-bd')) && (await js(`${q('.rm-camp[data-id="1"] .rm-iniciar')}.length`)) === 1);
        chk('em envio: selo "Ativa" e "Em execução" (sem botão de iniciar)', /Ativa/.test(await texto('.rm-camp[data-id="2"] .a-bd')) && /Em execução/.test(await texto('.rm-camp[data-id="2"] .rm-estado')) && (await js(`${q('.rm-camp[data-id="2"] .rm-iniciar')}.length`)) === 0);
        chk('agendada: mostra a data do envio', /25\/12\/2026 09:00/.test(await texto('.rm-camp[data-id="3"] .rm-estado')) && /Agendada/.test(await texto('.rm-camp[data-id="3"] .a-bd')));
        chk('concluída e com falha: o selo diz a situação e o botão verde é "Reenviar" (também na campanha do modelo antigo)', /Concluída/.test(await texto('.rm-camp[data-id="4"] .a-bd')) && /Falha/.test(await texto('.rm-camp[data-id="5"] .a-bd')) && (await js(`${q('.rm-camp[data-id="4"] .rm-reenviar, .rm-camp[data-id="5"] .rm-reenviar, .rm-camp[data-id="6"] .rm-reenviar')}.length`)) === 3 && /Reenviar/.test(await texto('.rm-camp[data-id="4"] .rm-estado')) && (await js(`${q('.rm-camp[data-id="4"] .rm-iniciar')}.length`)) === 0);
        chk('números do cartão: total, enviadas, receita', /500/.test(await texto('.rm-camp[data-id="2"] .rm-nums div:eq(0) b')) && /120/.test(await texto('.rm-camp[data-id="2"] .rm-nums div:eq(1) b')) && /R\$\s*89,70/.test(await texto('.rm-camp[data-id="2"] .rm-nums div:eq(2) b')));
        chk('linha de restantes e falhas', /377 restantes/.test(await texto('.rm-camp[data-id="2"] .rm-meta')) && /3 falhas/.test(await texto('.rm-camp[data-id="2"] .rm-meta')) && /1 falha$/.test(await texto('.rm-camp[data-id="6"] .rm-meta span:eq(1)')));
        chk('cartões: o estado e os ícones nunca se sobrepõem (lado a lado ou o grupo de ícones desce)', await js(`(function(){ var ok = true; document.querySelectorAll('.rm-camp-acts').forEach(function(a){ var e = a.querySelector('.rm-estado').getBoundingClientRect(), i = a.querySelector('.rm-icones').getBoundingClientRect(); if (!(e.right <= i.left + 1 || e.bottom <= i.top + 1)) ok = false; }); return ok; })()`));
        chk('cartões: os 5 ícones ficam encostados na direita do cartão', await js(`(function(){ var ok = true; document.querySelectorAll('.rm-camp').forEach(function(c){ var i = c.querySelector('.rm-icones').getBoundingClientRect(), r = c.getBoundingClientRect(); if (r.right - i.right > 22 || r.right - i.right < 8) ok = false; }); return ok; })()`));
        chk('valores em R$ do cartão cabem numa linha só', await js(`(function(){ var ok = true; document.querySelectorAll('.rm-nums b').forEach(function(b){ if (b.getBoundingClientRect().height > 34) ok = false; }); return ok; })()`));
        chk('o texto do estado ("Em execução") cabe inteiro no botão', await js(`(function(){ var e = document.querySelector('.rm-camp[data-id="2"] .rm-estado'); return e.scrollWidth <= e.clientWidth + 1; })()`));
        chk('engrenagem só abre campanha editável; a enviada fica desabilitada com o motivo', (await js(`${q('.rm-camp[data-id="1"] .rm-editar').attr('href')}`)) === 'remarketing_nova?id=1' && (await js(`${q('.rm-camp[data-id="2"] .rm-editar')}.length`)) === 0 && /Já enviada/.test(await js(`${q('.rm-camp[data-id="2"] .rm-ib.off:eq(1)')}.attr('title') || ''`)) );
        chk('campanha do modelo antigo diz que não dá para editar', /modelo antigo/.test(await js(`${q('.rm-camp[data-id="6"] .rm-ib.off')}.map(function(){return $(this).attr('title')||''}).get().join(' ')`)));
        chk('baixar (CSV) só habilitado com envios; aponta para a exportação', (await js(`${q('.rm-camp[data-id="2"] .rm-baixar').attr('href')}`)) === 'remarketing?action=exportar&id=2' && (await js(`${q('.rm-camp[data-id="1"] .rm-baixar')}.length`)) === 0);
        chk('estatísticas leva para os detalhes da campanha', (await js(`${q('.rm-camp[data-id="4"] a[href^="remarketing_detalhes"]').attr('href')}`)) === 'remarketing_detalhes?campanha=4');
        chk('sem rolagem lateral na lista', await semRolagemLateral());
        await captura('lista_desktop_escuro.png');
        // busca, filtro e ordem
        await definir('#rm-busca', 'black');
        chk('busca por nome: só a Black Friday', (await js(`${q('.rm-camp')}.length`)) === 1 && (await js(`${q('.rm-camp').attr('data-id')}`)) === '2');
        await definir('#rm-busca', 'funil');
        chk('busca também acha pelo nome do fluxo (3 campanhas do Funil visual)', (await js(`${q('.rm-camp')}.length`)) === 3);
        await definir('#rm-busca', 'xyz inexistente');
        chk('busca sem resultado: mensagem própria', /Nenhuma campanha encontrada/.test(await texto('#rm-lista')));
        await definir('#rm-busca', '');
        await definir('#rm-status', 'concluida');
        chk('filtro "Concluída": 2 campanhas (a do fluxo e a antiga)', (await js(`${q('.rm-camp')}.length`)) === 2);
        await definir('#rm-status', 'processando');
        chk('filtro "Ativa": só a em envio', (await js(`${q('.rm-camp')}.length`)) === 1 && (await js(`${q('.rm-camp').attr('data-id')}`)) === '2');
        await definir('#rm-status', 'pendente');
        chk('filtro "Agendada": só a agendada (a em envio não conta)', (await js(`${q('.rm-camp')}.length`)) === 1 && (await js(`${q('.rm-camp').attr('data-id')}`)) === '3');
        await definir('#rm-status', '');
        await definir('#rm-ordem', 'receita');
        chk('ordenar por receita: a de R$ 299,50 vem primeiro', (await js(`${q('.rm-camp:first').attr('data-id')}`)) === '4');
        await definir('#rm-ordem', 'nome');
        chk('ordenar por nome (A–Z): "Black Friday" no topo', (await js(`${q('.rm-camp:first').attr('data-id')}`)) === '2');
        await definir('#rm-ordem', 'antigas');
        chk('ordenar por mais antigas: a de 2026-08 primeiro', (await js(`${q('.rm-camp:first').attr('data-id')}`)) === '6');
        await definir('#rm-ordem', 'recentes');
        // grupo recolhe
        await clicar('.rm-grupo:eq(0) .rm-grupo-cab');
        chk('clicar no cabeçalho do grupo recolhe as campanhas dele', !(await visivel('.rm-grupo:eq(0) .rm-grupo-corpo')) && (await js(`${q('.rm-grupo:eq(0) .rm-grupo-cab').attr('aria-expanded')}`)) === 'false');
        await clicar('.rm-grupo:eq(0) .rm-grupo-cab');
        chk('e clicar de novo abre', await visivel('.rm-grupo:eq(0) .rm-grupo-corpo'));
        // iniciar
        await clicar('.rm-camp[data-id="1"] .rm-iniciar');
        chk('iniciar abre a confirmação com o nome da campanha', (await js(`${q('#rm-confirma')}.hasClass('aberto')`)) && /teste/.test(await texto('#rm-confirma-texto')) && (await texto('#rm-confirma-sim')) === 'Iniciar agora');
        await clicar('#rm-confirma-nao');
        chk('cancelar a confirmação não manda nada ao servidor', !(await js(`${q('#rm-confirma')}.hasClass('aberto')`)) && (await js(`window.__acoes.length`)) === 0);
        await clicar('.rm-camp[data-id="1"] .rm-iniciar');
        await js(`window.__dados.erro_acao = 'Esse público não tem ninguém agora.'`);
        await clicar('#rm-confirma-sim');
        await esp(200);
        chk('iniciar com erro do servidor: aviso vermelho com o motivo', /não tem ninguém/.test(await texto('#toast')) && /erro/.test(await js(`${q('#toast')}.attr('class')`)));
        await js(`window.__dados.erro_acao = ''`);
        await clicar('.rm-camp[data-id="1"] .rm-iniciar');
        await clicar('#rm-confirma-sim');
        await esp(250);
        chk('confirmar manda "iniciar" com o id e recarrega a lista', (await js(`window.__acoes.filter(function(a){return a.acao==='iniciar' && a.id===1}).length`)) >= 1 && (await chamadas('listar')).length >= 2 && /Campanha iniciada/.test(await texto('#toast')));
        // agendar
        await clicar('.rm-camp[data-id="1"] .rm-agendar');
        chk('agendar abre a janela com data e hora já preenchidas (amanhã 09:00)', (await js(`${q('#rm-agenda')}.hasClass('aberto')`)) && /^\d{4}-\d{2}-\d{2}$/.test(await js(`${q('#rm-agenda-data')}.val()`)) && (await js(`${q('#rm-agenda-hora')}.val()`)) === '09:00' && /teste/.test(await texto('#rm-agenda-nome')));
        await definir('#rm-agenda-data', '');
        await clicar('#rm-agenda-sim');
        chk('agendar sem data: mostra o erro na própria janela', (await visivel('#rm-agenda-erro')) && /Informe a data e a hora/.test(await texto('#rm-agenda-erro')));
        await definir('#rm-agenda-data', '2030-01-15'); await definir('#rm-agenda-hora', '18:30');
        await clicar('#rm-agenda-sim');
        await esp(250);
        chk('agendar "uma vez" manda a frequência com 2030-01-15 18:30 e fecha a janela', (await js(`window.__acoes.filter(function(a){return a.acao==='agendar' && a.id===1 && a.frequencia && a.frequencia.tipo==='uma_vez' && a.frequencia.data==='2030-01-15' && a.frequencia.hora==='18:30'}).length`)) === 1 && !(await js(`${q('#rm-agenda')}.hasClass('aberto')`)));
        chk('o calendário abre para rascunho, agendada e concluída (reenvio agendado); não para a que está em envio e não repete', (await js(`${q('.rm-camp[data-id="3"] .rm-agendar')}.length`)) === 1 && (await js(`${q('.rm-camp[data-id="4"] .rm-agendar')}.length`)) === 1 && (await js(`${q('.rm-camp[data-id="2"] .rm-agendar')}.length`)) === 0);
        // excluir
        await clicar('.rm-camp[data-id="2"] .rm-excluir');
        chk('excluir uma campanha em envio avisa que interrompe o envio', /interrompe o envio/.test(await texto('#rm-confirma-texto')) && (await texto('#rm-confirma-sim')) === 'Excluir' && (await js(`${q('#rm-confirma-sim')}.hasClass('dg')`)));
        await clicar('#rm-confirma-nao');
        await clicar('.rm-camp[data-id="4"] .rm-excluir');
        chk('excluir campanha comum: texto sobre apagar o registro, sem falar em interromper', /não pode ser desfeito/.test(await texto('#rm-confirma-texto')) && !/interrompe/.test(await texto('#rm-confirma-texto')));
        await clicar('#rm-confirma-sim');
        await esp(250);
        chk('confirmar manda "excluir" com o id', (await js(`window.__acoes.filter(function(a){return a.acao==='excluir' && a.id===4}).length`)) === 1);
        // Esc fecha
        await clicar('.rm-camp[data-id="1"] .rm-iniciar');
        await js(`document.dispatchEvent(new KeyboardEvent('keydown', {key:'Escape', bubbles:true}))`);
        await esp(100);
        chk('Esc fecha a confirmação', !(await js(`${q('#rm-confirma')}.hasClass('aberto')`)));

        // =============================================================================
        // REENVIO, INTERVALO DE 3 HORAS E RECORRÊNCIA (26/10/2026)
        // =============================================================================
        const hoje_dm = (function () { const d = new Date(); return String(d.getDate()).padStart(2, '0') + '/' + String(d.getMonth() + 1).padStart(2, '0'); })();
        const mk = (id, extra) => base(Object.assign({ id: id, nome: 'Campanha ' + id, grupo: 'f1', grupo_nome: 'claude gomes', grupo_bots: 1, grupo_antigo: false, status: 'concluida', status_rotulo: 'Concluída', em_envio: false, segmento: 'Todos os leads', total: 10, enviadas: 10, falhas: 0, restantes: 0, receita: 0, agendado_em: '26/10/2026 10:00', criado_em: '2026-10-2' + (id % 10) + ' 10:00:00', editavel: false, iniciavel: false, tem_log: true, tem_oferta: false }, extra));
        const msg3h = 'Este fluxo enviou o remarketing "X" em 26/10/2026 14:00. Para não incomodar os mesmos leads, um novo envio do mesmo fluxo só é liberado 3 horas depois, a partir de 26/10/2026 17:00. Campanhas de outros fluxos não são afetadas.';
        const camp2 = [
            mk(20, { nome: 'Reenviável' }),
            mk(21, { nome: 'Bloqueada por intervalo', bloqueio: { motivo: 'intervalo', mensagem: msg3h, libera_em: hoje_dm + ' 23:59' } }),
            mk(22, { nome: 'Retida', status: 'pendente', status_rotulo: 'Agendada', retida: true, libera_em: hoje_dm + ' 23:58', agendado_em: '26/10/2026 12:00', enviadas: 0, total: 0 }),
            mk(23, { nome: 'Rascunho de fluxo ocupado', status: 'rascunho', status_rotulo: 'Rascunho', iniciavel: true, editavel: true, tem_log: false, bloqueio: { motivo: 'em_envio', mensagem: 'Este fluxo já tem a campanha "Y" em envio.', libera_em: '' } }),
            mk(24, { nome: 'Repete todo dia', status: 'pendente', status_rotulo: 'Agendada', recorrente: true, recorrencia: { tipo: 'diario', hora: '21:00' }, recorrencia_rotulo: 'Todo dia às 21:00', execucoes: 2, agendado_em: '27/10/2026 21:00', enviadas: 20 }),
            mk(25, { nome: 'Em envio e repete', status: 'processando', status_rotulo: 'Ativa', em_envio: true, recorrente: true, recorrencia: { tipo: 'semana', dias: [1, 3], hora: '08:00' }, recorrencia_rotulo: 'Seg e Qua às 08:00', execucoes: 3, agendavel: false }),
            mk(26, { nome: 'Repete seg e qua', status: 'pendente', status_rotulo: 'Agendada', recorrente: true, recorrencia: { tipo: 'semana', dias: [1, 3], hora: '08:00' }, recorrencia_rotulo: 'Seg e Qua às 08:00', agendado_em: '28/10/2026 08:00' }),
            mk(27, { nome: 'Repete em horários', status: 'pendente', status_rotulo: 'Agendada', recorrente: true, recorrencia: { tipo: 'horarios', horarios: ['08:00', '14:00'] }, recorrencia_rotulo: 'Todo dia às 08:00 e 14:00', agendado_em: '27/10/2026 14:00' }),
            mk(28, { nome: 'Repete a cada 12 h', status: 'pendente', status_rotulo: 'Agendada', recorrente: true, recorrencia: { tipo: 'a_cada', horas: 12 }, recorrencia_rotulo: 'A cada 12 horas', agendado_em: '27/10/2026 02:00' })
        ];
        await tamanho(1500, 1000);
        await abrir('lista.html', { campanhas: camp2, resumo: { total: 9, ativas: 1, enviadas: 60, receita: 0 } });
        chk('reenvio: sem erro de JS', c.errosJs().length === 0, c.errosJs());
        chk('concluída sem bloqueio: botão verde "Reenviar" (o do Iniciar não aparece)', /Reenviar/.test(await texto('.rm-camp[data-id="20"] .rm-reenviar')) && (await js(`${q('.rm-camp[data-id="20"] .rm-iniciar')}.length`)) === 0 && (await js(`${q('.rm-camp[data-id="20"] .rm-reenviar')}.hasClass('iniciar')`)));
        chk('concluída com o fluxo bloqueado: no lugar do botão, "Libera às 23:59" (hoje só a hora) e o motivo no title', /Libera às 23:59/.test(await texto('.rm-camp[data-id="21"] .rm-bloqueado')) && /3 horas/.test(await js(`${q('.rm-camp[data-id="21"] .rm-bloqueado').attr('title')}`)) && (await js(`${q('.rm-camp[data-id="21"] .rm-reenviar')}.length`)) === 0);
        chk('agendada que espera o fluxo liberar: selo "Agendada" (não "Ativa") e "Sai às 23:58"', /Agendada/.test(await texto('.rm-camp[data-id="22"] .a-bd')) && /Sai às 23:58/.test(await texto('.rm-camp[data-id="22"] .rm-estado')) && !/Ativa/.test(await texto('.rm-camp[data-id="22"] .a-bd')));
        chk('rascunho com outra campanha do fluxo em envio: "Fluxo em envio" no lugar do Iniciar', /Fluxo em envio/.test(await texto('.rm-camp[data-id="23"] .rm-bloqueado')) && (await js(`${q('.rm-camp[data-id="23"] .rm-iniciar')}.length`)) === 0);
        chk('campanha que repete: "Repete: Todo dia às 21:00", "2 execuções" e a próxima data no estado', /Repete: Todo dia às 21:00/.test(await texto('.rm-camp[data-id="24"] .rm-repete')) && /2 execuções/.test(await texto('.rm-camp[data-id="24"] .rm-repete')) && /27\/10\/2026 21:00/.test(await texto('.rm-camp[data-id="24"] .rm-estado')) && /Repete: Todo dia/.test(await js(`${q('.rm-camp[data-id="24"] .rm-estado').attr('title')}`)));
        chk('campanha sem recorrência e com 1 execução: sem a linha "Repete"', (await js(`${q('.rm-camp[data-id="20"] .rm-repete')}.length`)) === 0);
        chk('o calendário abre também na campanha em envio que repete (para desativar a recorrência)', (await js(`${q('.rm-camp[data-id="25"] .rm-agendar')}.length`)) === 1);
        chk('reenvio: os estados nunca ficam cortados, nem se sobrepõem aos ícones', await js(`(function(){ var ok = true; document.querySelectorAll('.rm-estado').forEach(function(e){ if (e.scrollWidth > e.clientWidth + 1) ok = false; }); document.querySelectorAll('.rm-camp-acts').forEach(function(a){ var e = a.querySelector('.rm-estado').getBoundingClientRect(), i = a.querySelector('.rm-icones').getBoundingClientRect(); if (!(e.right <= i.left + 1 || e.bottom <= i.top + 1)) ok = false; }); return ok; })()`));
        chk('reenvio: sem rolagem lateral', await semRolagemLateral());
        await captura('lista_reenvio_escuro.png');
        await js(`document.querySelector('.rm-camp[data-id="21"]').scrollIntoView()`);
        await esp(150);
        await captura('lista_reenvio_bloqueios_escuro.png');
        await js(`window.scrollTo(0, 0)`);
        // Reenviar: confirmação com o aviso das 3 horas, cancelar não manda nada, confirmar manda "iniciar"
        await clicar('.rm-camp[data-id="20"] .rm-reenviar');
        chk('Reenviar abre a confirmação: título, aviso das 3 horas do fluxo e botão "Reenviar agora"', (await js(`${q('#rm-confirma')}.hasClass('aberto')`)) && (await texto('#rm-confirma-titulo')) === 'Reenviar a campanha?' && /3 horas/.test(await texto('#rm-confirma-texto')) && /Reenviável/.test(await texto('#rm-confirma-texto')) && (await texto('#rm-confirma-sim')) === 'Reenviar agora');
        await clicar('#rm-confirma-nao');
        chk('cancelar o reenvio não manda nada', (await js(`window.__acoes.filter(function(a){return a.id===20}).length`)) === 0);
        await clicar('.rm-camp[data-id="20"] .rm-reenviar');
        await js(`window.__dados.erro_acao = 'Este fluxo enviou o remarketing "X" há pouco.'`);
        await clicar('#rm-confirma-sim');
        await esp(250);
        chk('reenviar com o servidor recusando (intervalo): aviso vermelho com o motivo e a lista recarrega', /enviou o remarketing/.test(await texto('#toast')) && /erro/.test(await js(`${q('#toast')}.attr('class')`)));
        await js(`window.__dados.erro_acao = ''`);
        await clicar('.rm-camp[data-id="20"] .rm-reenviar');
        await clicar('#rm-confirma-sim');
        await esp(250);
        chk('confirmar o reenvio manda "iniciar" com o id da campanha', (await js(`window.__acoes.filter(function(a){return a.acao==='iniciar' && a.id===20}).length`)) >= 1);

        // modal "Agendar campanha" com a frequência
        const painelAtivo = () => js(`${q('#rm-agenda .rm-painel')}.filter(function(){ return !this.hidden; }).map(function(){ return $(this).data('painel'); }).get().join(',')`);
        const acoesAgendar = () => js(`JSON.stringify(window.__acoes.filter(function(a){return a.acao==='agendar'}))`).then(JSON.parse);
        await clicar('.rm-camp[data-id="24"] .rm-agendar');
        chk('modal: título "Agendar campanha" e o nome da campanha', /Agendar campanha/.test(await texto('#rm-agenda-titulo')) && /Repete todo dia/.test(await texto('#rm-agenda-nome')));
        chk('modal: as 5 frequências do Shark, na ordem', JSON.stringify(await js(`${q('#rm-agenda .rm-freq')}.map(function(){return $(this).text()}).get()`)) === JSON.stringify(['Uma vez', 'Diário', 'Horários personalizados', 'A cada X horas', 'Dias da semana']));
        chk('modal de campanha que já repete abre na frequência dela (Diário às 21:00), com o botão "Ativar recorrência" e "Desativar recorrência"', (await js(`${q('#rm-agenda .rm-freq.on').data('freq')}`)) === 'diario' && (await painelAtivo()) === 'diario' && (await js(`${q('#rm-ag-diario')}.val()`)) === '21:00' && /Ativar recorrência/.test(await texto('#rm-agenda-sim')) && (await visivel('#rm-agenda-parar')) && /Desativar recorrência/.test(await texto('#rm-agenda-parar')));
        chk('modal: os botões são radio (aria-checked) e só um está marcado', (await js(`${q('#rm-agenda .rm-freq[aria-checked="true"]')}.length`)) === 1);
        await captura('agendar_diario_escuro.png');
        for (const [freq, rotulo_sim] of [['uma_vez', 'Agendar'], ['diario', 'Ativar recorrência'], ['horarios', 'Ativar recorrência'], ['a_cada', 'Ativar recorrência'], ['semana', 'Ativar recorrência']]) {
            await clicar('#rm-agenda .rm-freq[data-freq="' + freq + '"]');
            chk('frequência "' + freq + '": só o painel dela aparece e o botão principal diz "' + rotulo_sim + '"', (await painelAtivo()) === freq && (await texto('#rm-agenda-sim')) === rotulo_sim && (await js(`${q('#rm-agenda .rm-freq.on').data('freq')}`)) === freq);
        }
        chk('"Dias da semana" sem nenhum dia: a dica vira o aviso do Shark (em destaque)', (await texto('#rm-agenda-dica-txt')) === 'Selecione pelo menos um dia da semana.' && (await js(`${q('#rm-agenda-dica')}.hasClass('alerta')`)));
        const antes = (await acoesAgendar()).length;
        await clicar('#rm-agenda-sim');
        chk('ativar sem dia marcado: o erro aparece na janela e nada vai ao servidor', (await visivel('#rm-agenda-erro')) && (await texto('#rm-agenda-erro')) === 'Selecione pelo menos um dia da semana.' && (await acoesAgendar()).length === antes);
        await clicar('#rm-ag-dias .rm-dia[data-dia="1"]'); await clicar('#rm-ag-dias .rm-dia[data-dia="3"]');
        chk('marcar 2 dias: os dois ficam ligados (aria-pressed) e a dica volta ao normal', (await js(`${q('#rm-ag-dias .rm-dia.on')}.length`)) === 2 && (await js(`${q('#rm-ag-dias .rm-dia[data-dia="1"]').attr('aria-pressed')}`)) === 'true' && (await texto('#rm-agenda-dica-txt')) === 'Envia nos dias marcados, neste horário.' && !(await js(`${q('#rm-agenda-dica')}.hasClass('alerta')`)));
        chk('os 7 dias na ordem Dom a Sáb', JSON.stringify(await js(`${q('#rm-ag-dias .rm-dia')}.map(function(){return $(this).text()}).get()`)) === JSON.stringify(['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb']));
        await captura('agendar_semana_escuro.png');
        await definir('#rm-ag-semana', '07:30');
        await clicar('#rm-agenda-sim');
        await esp(250);
        const ult = (await acoesAgendar()).pop();
        chk('dias da semana manda {tipo, dias [1,3], hora} e a janela fecha com o aviso', ult && ult.id === 24 && ult.frequencia.tipo === 'semana' && JSON.stringify(ult.frequencia.dias) === '[1,3]' && ult.frequencia.hora === '07:30' && !(await js(`${q('#rm-agenda')}.hasClass('aberto')`)) && /Recorrência ativada/.test(await texto('#toast')), ult);
        // horários personalizados
        await clicar('.rm-camp[data-id="20"] .rm-agendar');
        chk('campanha sem recorrência abre em "Uma vez", sem o botão de desativar', (await js(`${q('#rm-agenda .rm-freq.on').data('freq')}`)) === 'uma_vez' && (await texto('#rm-agenda-sim')) === 'Agendar' && !(await visivel('#rm-agenda-parar')));
        await clicar('#rm-agenda .rm-freq[data-freq="horarios"]');
        chk('horários: começa com 3 linhas (09:00, 15:00, 21:00)', JSON.stringify(await js(`${q('#rm-ag-horarios .rm-ag-horario')}.map(function(){return $(this).val()}).get()`)) === JSON.stringify(['09:00', '15:00', '21:00']));
        await clicar('#rm-ag-horario-mais');
        chk('"Adicionar horário": nasce uma linha vazia, à direita', (await js(`${q('#rm-ag-horarios .rm-horario')}.length`)) === 4 && (await js(`${q('#rm-ag-horarios .rm-ag-horario:last').val()}`)) === '' && (await js(`(function(){ var b = document.getElementById('rm-ag-horario-mais').getBoundingClientRect(), m = document.querySelector('.rm-modal-agenda').getBoundingClientRect(); return m.right - b.right < 40; })()`)));
        await definir('#rm-ag-horarios .rm-horario:eq(3) .rm-ag-horario', '23:30');
        await clicar('#rm-ag-horarios .rm-horario:eq(0) .rm-ag-horario-del');
        chk('remover a primeira linha: sobram 3', JSON.stringify(await js(`${q('#rm-ag-horarios .rm-ag-horario')}.map(function(){return $(this).val()}).get()`)) === JSON.stringify(['15:00', '21:00', '23:30']));
        await clicar('#rm-agenda-sim');
        await esp(250);
        const ult2 = (await acoesAgendar()).pop();
        chk('horários manda a lista como está na janela', ult2 && ult2.id === 20 && ult2.frequencia.tipo === 'horarios' && JSON.stringify(ult2.frequencia.horarios) === '["15:00","21:00","23:30"]', ult2);
        await clicar('.rm-camp[data-id="20"] .rm-agendar');
        await clicar('#rm-agenda .rm-freq[data-freq="horarios"]');
        for (let i = 0; i < 3; i++) await clicar('#rm-ag-horario-mais');
        await clicar('#rm-ag-horario-mais');
        chk('no máximo 6 horários: o 7º é recusado com o aviso na janela', (await js(`${q('#rm-ag-horarios .rm-horario')}.length`)) === 6 && /no máximo 6/.test(await texto('#rm-agenda-erro')));
        await clicar('#rm-ag-horarios .rm-horario:eq(5) .rm-ag-horario-del');
        // a cada X horas
        await clicar('#rm-agenda .rm-freq[data-freq="a_cada"]');
        chk('"A cada X horas": 6 por padrão, mínimo 3 e máximo 168 no campo, e data/hora do 1º envio (amanhã 09:00)', (await js(`${q('#rm-ag-horas')}.val()`)) === '6' && (await js(`${q('#rm-ag-horas').attr('min')}`)) === '3' && (await js(`${q('#rm-ag-horas').attr('max')}`)) === '168' && /^\d{4}-\d{2}-\d{2}$/.test(await js(`${q('#rm-ag-cada-data')}.val()`)) && (await js(`${q('#rm-ag-cada-hora')}.val()`)) === '09:00');
        await definir('#rm-ag-cada-data', '');
        await clicar('#rm-agenda-sim');
        chk('"A cada X horas" sem data do 1º envio: o erro aparece na janela', /primeiro envio/.test(await texto('#rm-agenda-erro')));
        await definir('#rm-ag-cada-data', '2030-02-02'); await definir('#rm-ag-cada-hora', '10:15'); await definir('#rm-ag-horas', '8');
        await clicar('#rm-agenda-sim');
        await esp(250);
        const ult3 = (await acoesAgendar()).pop();
        chk('"A cada X horas" manda {horas 8, data, hora}', ult3 && ult3.frequencia.tipo === 'a_cada' && ult3.frequencia.horas === 8 && ult3.frequencia.data === '2030-02-02' && ult3.frequencia.hora === '10:15', ult3);
        // diário e recusa do servidor
        await clicar('.rm-camp[data-id="20"] .rm-agendar');
        await clicar('#rm-agenda .rm-freq[data-freq="diario"]');
        await definir('#rm-ag-diario', '');
        await clicar('#rm-agenda-sim');
        chk('diário sem horário: erro "Informe o horário de execução."', (await texto('#rm-agenda-erro')) === 'Informe o horário de execução.');
        await definir('#rm-ag-diario', '22:00');
        await js(`window.__dados.erro_acao = 'Este fluxo enviou o remarketing "X" em 26/10/2026 14:00. Um novo envio só é liberado 3 horas depois.'`);
        await clicar('#rm-agenda-sim');
        await esp(250);
        chk('o servidor recusa (intervalo do fluxo): a mensagem aparece na janela, que continua aberta', /3 horas depois/.test(await texto('#rm-agenda-erro')) && (await js(`${q('#rm-agenda')}.hasClass('aberto')`)));
        await js(`window.__dados.erro_acao = ''`);
        await clicar('#rm-agenda-sim');
        await esp(250);
        const ult4 = (await acoesAgendar()).pop();
        chk('diário manda {tipo diario, hora 22:00}', ult4 && ult4.frequencia.tipo === 'diario' && ult4.frequencia.hora === '22:00', ult4);
        // abrir com a recorrência que a campanha já tem
        await clicar('.rm-camp[data-id="26"] .rm-agendar');
        chk('campanha que repete em dias da semana abre com Seg e Qua marcados e o horário dela', (await js(`${q('#rm-agenda .rm-freq.on').data('freq')}`)) === 'semana' && (await js(`${q('#rm-ag-dias .rm-dia.on')}.map(function(){return $(this).data('dia')}).get().join(',')`)) === '1,3' && (await js(`${q('#rm-ag-semana')}.val()`)) === '08:00');
        await clicar('#rm-agenda-nao');
        await clicar('.rm-camp[data-id="27"] .rm-agendar');
        chk('campanha com horários abre com as linhas dela (08:00 e 14:00)', (await js(`${q('#rm-agenda .rm-freq.on').data('freq')}`)) === 'horarios' && JSON.stringify(await js(`${q('#rm-ag-horarios .rm-ag-horario')}.map(function(){return $(this).val()}).get()`)) === JSON.stringify(['08:00', '14:00']));
        await clicar('#rm-agenda-nao');
        await clicar('.rm-camp[data-id="28"] .rm-agendar');
        chk('campanha "a cada 12 horas" abre com 12 no campo', (await js(`${q('#rm-agenda .rm-freq.on').data('freq')}`)) === 'a_cada' && (await js(`${q('#rm-ag-horas')}.val()`)) === '12');
        await clicar('#rm-agenda-nao');
        // desativar
        await clicar('.rm-camp[data-id="24"] .rm-agendar');
        await clicar('#rm-agenda-parar');
        await esp(250);
        chk('"Desativar recorrência" manda "parar" com o id e fecha a janela', (await js(`window.__acoes.filter(function(a){return a.acao==='parar' && a.id===24}).length`)) === 1 && !(await js(`${q('#rm-agenda')}.hasClass('aberto')`)) && /Agendamento cancelado/.test(await texto('#toast')));
        await clicar('.rm-camp[data-id="22"] .rm-agendar');
        chk('campanha agendada (sem repetir): o botão é "Cancelar agendamento"', (await visivel('#rm-agenda-parar')) && /Cancelar agendamento/.test(await texto('#rm-agenda-parar')));
        await clicar('#rm-agenda-nao');
        // em envio e repetindo: só desativar
        await clicar('.rm-camp[data-id="25"] .rm-agendar');
        chk('campanha EM ENVIO que repete: sem formulário nem "Ativar", só a explicação e "Desativar recorrência"', !(await visivel('#rm-agenda-form')) && (await visivel('#rm-agenda-so-parar')) && !(await visivel('#rm-agenda-sim')) && (await visivel('#rm-agenda-parar')) && /Desativar recorrência/.test(await texto('#rm-agenda-parar')) && /depois deste envio/.test(await texto('#rm-agenda-so-parar')));
        await clicar('#rm-agenda-nao');
        await clicar('.rm-camp[data-id="20"] .rm-agendar');
        chk('e ao abrir outra campanha o formulário volta (não fica escondido)', (await visivel('#rm-agenda-form')) && (await visivel('#rm-agenda-sim')) && !(await visivel('#rm-agenda-so-parar')));
        chk('layout do modal (1500 px): "Agendar" é o botão mais à direita e o modal não passa de 540 px', await js(`(function(){ var s = document.getElementById('rm-agenda-sim').getBoundingClientRect(), n = document.getElementById('rm-agenda-nao').getBoundingClientRect(), m = document.querySelector('.rm-modal-agenda').getBoundingClientRect(); return s.right > n.right && m.width <= 541; })()`));
        await js(`document.dispatchEvent(new KeyboardEvent('keydown', {key:'Escape', bubbles:true}))`);
        await esp(100);
        chk('Esc fecha o modal de agendamento', !(await js(`${q('#rm-agenda')}.hasClass('aberto')`)));
        // celular
        await tamanho(390, 800, true);
        await abrir('lista.html', { campanhas: camp2, resumo: { total: 9, ativas: 1, enviadas: 60, receita: 0 } });
        chk('celular: lista com os novos estados sem rolagem lateral, estados inteiros e sem sobreposição', (await semRolagemLateral()) && await js(`(function(){ var ok = true; document.querySelectorAll('.rm-estado').forEach(function(e){ if (e.scrollWidth > e.clientWidth + 1) ok = false; }); return ok; })()`));
        await js(`document.querySelector('.rm-camp[data-id="21"]').scrollIntoView()`);
        await esp(150);
        await captura('lista_reenvio_390.png');
        await js(`document.querySelector('.rm-camp[data-id="23"]').scrollIntoView()`);
        await esp(150);
        await captura('lista_reenvio_390_b.png');
        await js(`window.scrollTo(0, 0)`);
        await clicar('.rm-camp[data-id="20"] .rm-agendar');
        await clicar('#rm-agenda .rm-freq[data-freq="semana"]');
        await clicar('#rm-ag-dias .rm-dia[data-dia="2"]');
        chk('celular: o modal cabe na tela, sem rolagem lateral, e os 7 dias ficam todos visíveis e clicáveis', (await semRolagemLateral()) && await js(`(function(){ var m = document.querySelector('.rm-modal-agenda').getBoundingClientRect(); var ok = m.left >= 0 && m.right <= window.innerWidth + 1 && m.bottom <= window.innerHeight + 1; document.querySelectorAll('#rm-ag-dias .rm-dia').forEach(function(d){ var r = d.getBoundingClientRect(); if (r.width < 34 || r.right > window.innerWidth) ok = false; }); return ok; })()`));
        chk('celular: os textos das 5 frequências cabem nos botões', await js(`(function(){ var ok = true; document.querySelectorAll('#rm-agenda .rm-freq').forEach(function(b){ if (b.scrollWidth > b.clientWidth + 1) ok = false; }); return ok; })()`));
        chk('celular: os botões Cancelar e "Ativar recorrência" cabem e o principal fica à direita', await js(`(function(){ var s = document.getElementById('rm-agenda-sim').getBoundingClientRect(), n = document.getElementById('rm-agenda-nao').getBoundingClientRect(); return s.right <= window.innerWidth && s.right >= n.right && s.left >= 0; })()`));
        await captura('agendar_semana_390.png');
        await clicar('#rm-agenda .rm-freq[data-freq="horarios"]');
        await captura('agendar_horarios_390.png');
        await clicar('#rm-agenda-nao');
        // tema claro
        await tamanho(1500, 1000, false);
        await abrir('lista.html', { campanhas: camp2, resumo: { total: 9, ativas: 1, enviadas: 60, receita: 0 } }, 'light');
        await clicar('.rm-camp[data-id="26"] .rm-agendar');
        chk('tema claro: a frequência marcada e os dias marcados têm texto escuro (nada de cinza sobre laranja) e o modal é claro', await js(`(function(){ function lum(c){ var m = c.match(/\\d+/g).map(Number); return (0.2126*m[0]+0.7152*m[1]+0.0722*m[2])/255; } var on = document.querySelector('#rm-agenda .rm-freq.on'), dia = document.querySelector('#rm-ag-dias .rm-dia.on'), modal = document.querySelector('.rm-modal-agenda'); return lum(getComputedStyle(on).color) < 0.3 && lum(getComputedStyle(dia).color) < 0.3 && lum(getComputedStyle(modal).backgroundColor) > 0.8; })()`));
        await captura('agendar_semana_claro.png');
        await clicar('#rm-agenda-nao');
        await captura('lista_reenvio_claro.png');
        await tamanho(1500, 1000, false);
        // vazio
        await abrir('lista.html', { campanhas: [], resumo: { total: 0, ativas: 0, enviadas: 0, receita: 0 } });
        chk('sem campanhas: "Pronto para começar?", com a orientação de usar o botão do topo', /Pronto para começar/.test(await texto('.rm-vazio h3')) && /Nova campanha/.test(await texto('.rm-vazio p')));
        chk('sem campanhas: SÓ UM botão de criar campanha na tela (o do topo, à direita); o estado vazio não repete', (await js(`[].filter.call(document.querySelectorAll('a[href="remarketing_nova"], .a-btn.pri'), function(e){ return e.offsetParent !== null && !e.closest('.a-over'); }).length`)) === 1 && (await js(`${q('.rm-vazio a, .rm-vazio button')}.length`)) === 0 && (await js(`(function(){ var b = document.getElementById('btn-nova-campanha'); return b && window.innerWidth - b.getBoundingClientRect().right <= 56; })()`)));
        await captura('lista_vazia_escuro.png');
        // recado vindo do assistente
        await c.enviar('Page.addScriptToEvaluateOnNewDocument', { source: `try { sessionStorage.setItem('rm_aviso', 'Rascunho criado. Nada foi enviado.'); } catch (e) {}` });
        await abrir('lista.html', { campanhas, resumo });
        chk('recado guardado pelo assistente aparece como aviso na lista e some depois', /Rascunho criado/.test(await texto('#toast')) && (await js(`sessionStorage.getItem('rm_aviso')`)) === null);
        // layouts da lista
        await tamanho(390, 844, true);
        await abrir('lista.html', { campanhas, resumo });
        chk('celular 390: lista sem rolagem lateral', await semRolagemLateral());
        chk('celular: os 4 cartões em 2 colunas (sem buraco)', await js(`(function(){ var k = document.querySelectorAll('.rm-kpis .a-card'); var lefts = {}; k.forEach(function(e){ lefts[Math.round(e.getBoundingClientRect().left)] = 1; }); return Object.keys(lefts).length === 2 && k.length % 2 === 0; })()`));
        chk('celular: as ações do cartão cabem (nenhum botão passa da tela)', await js(`(function(){ var ok = true; document.querySelectorAll('.rm-camp-acts > *').forEach(function(e){ if (e.getBoundingClientRect().right > window.innerWidth) ok = false; }); return ok; })()`));
        await captura('lista_celular_escuro.png');
        await tamanho(1920, 1080, false);
        await abrir('lista.html', { campanhas, resumo }, 'light');
        chk('1920 claro: sem rolagem lateral', await semRolagemLateral());
        await captura('lista_1920_claro.png');
        await tamanho(2560, 1300, false);
        await esp(200);
        chk('2560: sem rolagem lateral', await semRolagemLateral());
    } catch (e) {
        console.log('ERRO no teste:', e.stack || e);
        falhas++;
    } finally {
        try { await c.fechar(); } catch (e) { /* já fechado */ }
    }
    console.log('\nCapturas em: ' + SAIDA);
    console.log(`RESUMO: ${ok} ok, ${falhas} falhas`);
    process.exit(falhas > 0 ? 1 : 0);
})();
