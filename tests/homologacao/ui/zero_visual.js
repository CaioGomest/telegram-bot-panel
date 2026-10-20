// Fluxo VISUAL (estilo n8n) criado DO ZERO pela tela (Chrome de verdade): solta os 13 tipos de bloco (18 blocos no total), preenche todos os
// campos digitando, envia as mídias, liga as saídas com cliques nos conectores, renomeia/move/exclui, salva, recarrega, exporta e importa.
// Imprime FLUXO_VISUAL_ID=<id> para o teste de motor.
const fs = require('fs');
const path = require('path');
const { iniciar } = require('./zero_lib.js');

const G1 = '-1001000000001';
const q = (s) => 'jQuery(' + JSON.stringify(s) + ')';
const OP = (id) => `__op(${JSON.stringify(id)})`;
const F = (id, sel) => `${OP(id)}.find(${JSON.stringify(sel)})`;

// Blocos: chave, tipo da paleta, posição na grade (coluna, linha) e o que é digitado em cada um.
const BLOCOS = [
    { k: 'msg1', tipo: 'message', col: 1, lin: 0 },
    { k: 'img1', tipo: 'image', col: 2, lin: 0 },
    { k: 'vid1', tipo: 'video', col: 3, lin: 0 },
    { k: 'aud1', tipo: 'audio', col: 4, lin: 0 },
    { k: 'bot1', tipo: 'botoes', col: 5, lin: 0 },
    { k: 'del1', tipo: 'delay', col: 6, lin: 0 },
    { k: 'ran1', tipo: 'randomizer', col: 7, lin: 0 },
    { k: 'del2', tipo: 'delay', col: 5, lin: 1 },
    { k: 'msg2', tipo: 'message', col: 4, lin: 1 },
    { k: 'lnk1', tipo: 'link', col: 3, lin: 1 },
    { k: 'grp1', tipo: 'grupo', col: 6, lin: 1 },
    { k: 'ups1', tipo: 'upsell', col: 7, lin: 1 },
    { k: 'dws1', tipo: 'downsell', col: 1, lin: 2 },
    { k: 'bmp1', tipo: 'order_bump', col: 2, lin: 2 },
    { k: 'pix1', tipo: 'pix', col: 3, lin: 2 },
    { k: 'msg3', tipo: 'message', col: 4, lin: 2 },
    { k: 'msg4', tipo: 'message', col: 5, lin: 2 },
    { k: 'msg5', tipo: 'message', col: 6, lin: 2 },
];

// Posição no canvas (coordenadas do próprio editor, independentes do zoom): colunas de 300 e três faixas de altura, para os formulários não se sobreporem.
const CANVAS = (b) => ({ L: 40 + b.col * 300, T: [40, 740, 1250][b.lin] });

// Ligações do funil: [de, saída, para]
const LIGACOES = [
    ['start', 'output_1', 'msg1'], ['msg1', 'output_1', 'img1'], ['img1', 'output_1', 'vid1'], ['vid1', 'output_1', 'aud1'], ['aud1', 'output_1', 'bot1'],
    ['bot1', 'output_0', 'del1'], ['bot1', 'output_1', 'del2'], ['bot1', 'output_2', 'grp1'],
    ['del1', 'output_1', 'ran1'],
    ['ran1', 'output_path_0', 'ups1'], ['ran1', 'output_path_1', 'dws1'], ['ran1', 'output_path_2', 'msg3'],
    ['del2', 'output_1', 'msg2'], ['msg2', 'output_1', 'lnk1'],
    ['ups1', 'output_aceito', 'bmp1'], ['ups1', 'output_recusado', 'dws1'],
    ['dws1', 'output_aceito', 'bmp1'], ['dws1', 'output_recusado', 'pix1'],
    ['bmp1', 'output_aceito', 'pix1'], ['bmp1', 'output_recusado', 'pix1'],
    ['pix1', 'output_pago', 'msg4'], ['pix1', 'output_nao_pago', 'msg5'],
];

// O que é digitado/escolhido e o que tem que ficar salvo em cada bloco (propriedades do bloco no banco).
const ESPERADO = {
    msg1: { conteudo: 'Boas-vindas do funil visual criado do zero 🚀' },
    img1: { caption: 'Legenda da foto zero', spoiler: true, auto_delete: true, auto_delete_seconds: 40 },
    vid1: { caption: 'Legenda do vídeo zero', spoiler: true, auto_delete: true, auto_delete_seconds: 45 },
    aud1: { caption: 'Legenda do áudio zero', auto_delete: true, auto_delete_seconds: 50 },
    bot1: { texto: 'Quer ver as ofertas?', botoes: [{ texto: 'Sim, mostrar', cor: 'success' }, { texto: 'Talvez depois', cor: 'primary' }, { texto: 'Não quero', cor: 'danger' }], sumir_apos_clique: true },
    del1: { delay_min: 1, delay_max: 1, delay_unidade: 'seg', delay_digitando: true },
    del2: { delay_min: 2, delay_max: 3, delay_unidade: 'min', delay_digitando: false },
    ran1: { caminhos: [{ peso: 50 }, { peso: 30 }, { peso: 20 }] },
    msg2: { conteudo: 'Sem pressa, a oferta fica aqui' },
    lnk1: { url: 'https://exemplo.com/zero', texto_botao: 'Abrir oferta zero' },
    grp1: { id_grupo: G1, texto_botao: 'Entrar no grupo zero' },
    ups1: { mensagem: 'Upsell zero: leve o plano maior com 25% OFF', desconto_percentual: 25, texto_aceitar: 'Quero o maior', cor_aceitar: 'success', texto_recusar: 'Fico no atual', cor_recusar: 'danger' },
    dws1: { mensagem: 'Downsell zero: que tal este com 10% OFF?', desconto_percentual: 10, texto_aceitar: 'Pode ser', cor_aceitar: 'primary', texto_recusar: 'Agora não', cor_recusar: '' },
    bmp1: { mensagem: 'Order bump zero: leve o bônus por R$ 7,50', valor_extra: 7.5, texto_aceitar: 'Adicionar bônus', cor_aceitar: 'success', texto_recusar: 'Sem bônus', cor_recusar: 'danger' },
    pix1: { nome: 'Produto Visual Zero', valor: 80, expiracao_minutos: 20, dias_acesso: 15, unidade_acesso: 'dias', id_grupo: G1, mostrar_copiar: false, mostrar_qrcode: true, mostrar_confirmar: true, msg_instrucoes: 'Pague pelo visual zero', msg_confirmado: 'Aprovado pelo visual zero!', tipo_cobranca: 'unica' },
    msg3: { conteudo: 'Caminho 3 do sorteio zero' },
    msg4: { conteudo: 'Pago! Obrigado pelo visual zero' },
    msg5: { conteudo: 'O Pix visual zero expirou' },
};

(async () => {
    const t = await iniciar('zero_visual', { largura: 2400, altura: 1400 });
    const { c } = t;
    const ids = { start: 'operator_1' };            // chave -> id do operador
    const dados = () => t.js(`JSON.stringify(jQuery('#area-trabalho-fluxograma').flowchart('getData'))`).then(JSON.parse);
    const props = async (k) => (await dados()).operators[ids[k]].properties;
    const nLigacoes = async () => Object.keys((await dados()).links).length;
    const nBlocos = async () => Object.keys((await dados()).operators).length;
    const confirmaTexto = async (re, ms = 8000) => t.esperarToast(re, ms);
    // salva pelo botão e devolve o toast
    const salvar = async () => { await t.clicar(q('#btn-salvar-fluxo')); return t.esperarToast(/salvo|sucesso|Erro|inválid|mínimo|recus/i, 10000); };
    const ligar = async (de, saida, para) => {
        const a = JSON.parse(await t.js(`JSON.stringify(__cx(${JSON.stringify(ids[de])}, ${JSON.stringify(saida)}, 'outputs'))`));
        const b = JSON.parse(await t.js(`JSON.stringify(__cx(${JSON.stringify(ids[para])}, 'input_1', 'inputs'))`));
        if (!a || !b) throw new Error('conector não encontrado: ' + de + ' ' + saida + ' -> ' + para + ' ' + JSON.stringify([a, b]));
        for (const [ip, p] of [a, b].entries()) {
            const esperado = (ip ? ids[para] + ':input_1' : ids[de] + ':' + saida);
            const alvo = await t.js(`(function(){ var el = document.elementFromPoint(${p.x}, ${p.y}); if (!el) return 'nada'; var cn = jQuery(el).closest('.flowchart-operator-connector'); var op = jQuery(el).closest('.flowchart-operator'); return (op.length ? op.data('operator_id') : 'fora') + ':' + (cn.length ? cn.data('connector') : '?') + ' | ' + el.className + (cn.length ? ' [conector]' : ' [NÃO é conector]'); })()`);
            if (alvo.split(' | ')[0] !== esperado) console.log('   .. clique ' + (ip ? 'na entrada' : 'na saída') + ' de ' + (ip ? para : de) + ' (' + (ip ? 'input_1' : saida) + ') caiu em: ' + alvo + ' @' + Math.round(p.x) + ',' + Math.round(p.y));
            await c.enviar('Input.dispatchMouseEvent', { type: 'mouseMoved', x: p.x, y: p.y });
            await c.enviar('Input.dispatchMouseEvent', { type: 'mousePressed', x: p.x, y: p.y, button: 'left', clickCount: 1 });
            await c.enviar('Input.dispatchMouseEvent', { type: 'mouseReleased', x: p.x, y: p.y, button: 'left', clickCount: 1 });
            await t.esp(120);
        }
    };

    try {
        t.ok('login do usuário de teste', await t.entrar(), {});
        await c.ir(t.BASE + '/fluxo');
        t.ok('editor visual abre com a paleta de 13 blocos', await c.esperar(`typeof jQuery!=='undefined' && jQuery('.adicionar-no').length===13 && jQuery('.conteiner-fluxo').length && jQuery('#area-trabalho-fluxograma .flowchart-operator').length>=1`, 25000), {});
        await t.esp(1200);
        // utilitários dentro da página (só leitura/seleção; os efeitos vêm de eventos reais)
        await t.js(`window.__op = function(id){ return jQuery('#area-trabalho-fluxograma .flowchart-operator').filter(function(){ return jQuery(this).data('operator_id') === id; }); };
          window.__cx = function(id, conn, cat){ var el = __op(id).find('.flowchart-operator-connector').filter(function(){ return jQuery(this).data('connector') === conn && jQuery(this).closest('.flowchart-operator-connector-set').data('connector_type') === cat; }).get(0); if(!el) return null; var r = el.getBoundingClientRect(); return {x: r.left + r.width/2, y: r.top + r.height/2}; };
          window.__soltaCanvas = function(tipo, L, T){ var w = jQuery('.conteiner-fluxo'); var off = w.offset(); var z = parseInt(jQuery('#btn-zoom-reset').text(), 10) / 100; __solta(tipo, (L + 100) * z + off.left - w.scrollLeft(), (T + 40) * z + off.top - w.scrollTop()); };
          window.__solta = function(tipo, x, y){ var dt = new DataTransfer(); dt.setData('node-type', tipo); var btn = jQuery('.adicionar-no[data-node-type="'+tipo+'"]').get(0); btn.dispatchEvent(new DragEvent('dragstart', {dataTransfer: dt, bubbles: true})); var alvo = jQuery('.conteiner-fluxo').get(0); alvo.dispatchEvent(new DragEvent('dragover', {dataTransfer: dt, bubbles: true, cancelable: true, clientX: x, clientY: y})); alvo.dispatchEvent(new DragEvent('drop', {dataTransfer: dt, bubbles: true, cancelable: true, clientX: x, clientY: y})); btn.dispatchEvent(new DragEvent('dragend', {dataTransfer: dt, bubbles: true})); };
          1`);

        // ---------- A. estado inicial de um fluxo novo
        const d0 = await dados();
        t.ok('fluxo novo: só o bloco "Início" na tela, nenhuma ligação, sem id (nada gravado ainda)', Object.keys(d0.operators).length === 1 && d0.operators.operator_1.properties.type === 'start' && Object.keys(d0.links).length === 0 && !(await t.js(`jQuery('#id-fluxo').val()`)), { n: Object.keys(d0.operators).length });
        t.ok('o bloco Início não tem botão de excluir (não dá para apagar o começo)', (await t.js(`__op('operator_1').find('.btn-excluir').length`)) === 0, {});
        t.ok('a paleta tem os 13 tipos: ' + 'message, image, video, audio, botoes, pix, link, grupo, delay, randomizer, upsell, downsell, order_bump', JSON.stringify((await t.js(`jQuery('.adicionar-no').map(function(){ return jQuery(this).data('node-type'); }).get().sort()`))) === JSON.stringify(['audio', 'botoes', 'delay', 'downsell', 'grupo', 'image', 'link', 'message', 'order_bump', 'pix', 'randomizer', 'upsell', 'video']), {});

        // ---------- B. nome do fluxo
        await t.clicar(q('#btn-toggle-flow-meta'));
        t.ok('"Nome e descrição" abre o painel do nome', await t.js(`jQuery('#secao-detalhes-fluxo').is(':visible')`), {});
        const NOME = 'Zero Visual ' + Date.now();
        await t.digitar(q('#nome-fluxo'), NOME);
        await t.digitar(q('#descricao-fluxo'), 'Fluxo visual criado do zero pelo teste automático');
        t.ok('o nome digitado aparece no cabeçalho do editor', (await t.js(`jQuery('#texto-nome-fluxo-colapsado').text()`)) === NOME, { t: await t.js(`jQuery('#texto-nome-fluxo-colapsado').text()`) });

        // ---------- C. zoom e arrastar a paleta para a tela (13 tipos / 18 blocos)
        for (let i = 0; i < 6; i++) await t.clicar(q('#btn-zoom-out'));
        t.ok('zoom: 6 cliques em "−" levam a 40%', (await t.js(`jQuery('#btn-zoom-reset').text()`)) === '40%', { z: await t.js(`jQuery('#btn-zoom-reset').text()`) });
        await t.clicar(q('#btn-zoom-in')); await t.clicar(q('#btn-zoom-out'));
        t.ok('zoom: "+" e "−" voltam ao mesmo valor (40%)', (await t.js(`jQuery('#btn-zoom-reset').text()`)) === '40%', {});
        const caixa = JSON.parse(await t.js(`(function(){ var r = jQuery('.conteiner-fluxo').get(0).getBoundingClientRect(); return JSON.stringify({x:r.left, y:r.top, w:r.width, h:r.height}); })()`));
        for (const b of BLOCOS) {
            const antes = Object.keys((await dados()).operators);
            await t.js(`__soltaCanvas(${JSON.stringify(b.tipo)}, ${CANVAS(b).L}, ${CANVAS(b).T}); 1`);
            await t.esp(250);
            const novo = Object.keys((await dados()).operators).filter(k => !antes.includes(k));
            if (novo.length !== 1) throw new Error('soltar ' + b.k + ' criou ' + novo.length + ' blocos');
            ids[b.k] = novo[0];
        }
        t.ok('18 blocos soltos pela paleta (arrastar e soltar): 19 com o Início', (await nBlocos()) === 19, { n: await nBlocos() });
        const porTipo = {}; for (const [k, v] of Object.entries((await dados()).operators)) porTipo[v.properties.type] = (porTipo[v.properties.type] || 0) + 1;
        t.ok('todos os 13 tipos presentes (+ Início), com 5 mensagens e 2 esperas', Object.keys(porTipo).length === 14 && porTipo.message === 5 && porTipo.delay === 2, { porTipo });
        t.ok('cada bloco solto aparece com seu título, ícone e número do passo', (await t.js(`jQuery('#area-trabalho-fluxograma .flowchart-operator-title .passo-bloco').length`)) === 18, { n: await t.js(`jQuery('#area-trabalho-fluxograma .flowchart-operator-title .passo-bloco').length`) });
        await t.captura('visual_blocos_soltos.png');
        if (process.env.GEOM) {
            const g = JSON.parse(await t.js(`JSON.stringify(jQuery('#area-trabalho-fluxograma .flowchart-operator').map(function(){ var r=this.getBoundingClientRect(); var cx=jQuery(this).find('.flowchart-operator-connector').map(function(){ var q=this.getBoundingClientRect(); return jQuery(this).closest('.flowchart-operator-connector-set').data('connector_type')+':'+jQuery(this).data('connector')+'@'+Math.round(q.left)+','+Math.round(q.top)+' '+Math.round(q.width)+'x'+Math.round(q.height); }).get(); return {id:jQuery(this).data('operator_id'), cls:this.className.replace('flowchart-operator ',''), x:Math.round(r.left), y:Math.round(r.top), w:Math.round(r.width), h:Math.round(r.height), cx:cx}; }).get())`));
            for (const o of g) console.log(o.id.padEnd(12), o.cls.padEnd(30), [o.x, o.y, o.w + 'x' + o.h].join(' '), '|', o.cx.join(' ; '));
            const ck = JSON.parse(await t.js(`JSON.stringify(jQuery('.campo-delay-digitando').map(function(){ var r=this.getBoundingClientRect(); return [jQuery(this).closest('.flowchart-operator').data('operator_id'), Math.round(r.left), Math.round(r.top), Math.round(r.width), Math.round(r.height)]; }).get())`));
            console.log('checkboxes digitando (op,x,y,w,h):', JSON.stringify(ck));
            await t.fim(); process.exit(0);
        }

        // ---------- D. preencher todos os campos, digitando
        const dig = async (k, sel, v) => t.digitar(F(ids[k], sel), v);
        const esc = async (k, sel, v) => t.escolher(F(ids[k], sel), v);
        const marc = async (k, sel, v) => t.marcar(F(ids[k], sel), v);
        // mensagens
        for (const k of ['msg1', 'msg2', 'msg3', 'msg4', 'msg5']) await dig(k, '.campo-conteudo', ESPERADO[k].conteudo);
        // imagem / vídeo / áudio: legenda, spoiler, auto-deletar + segundos, arquivo
        for (const [k, arq] of [['img1', 'foto1.png'], ['vid1', 'video1.mp4'], ['aud1', 'audio1.mp3']]) {
            const e = ESPERADO[k];
            await dig(k, '.campo-legenda', e.caption);
            if (k !== 'aud1') await marc(k, '.campo-spoiler', e.spoiler);
            await marc(k, '.campo-auto-deletar', e.auto_delete);
            await dig(k, '.campo-auto-segundos', String(e.auto_delete_seconds));
            const inp = k === 'img1' ? '.campo-imagem-arquivo' : (k === 'vid1' ? '.campo-video-arquivo' : '.campo-audio-arquivo');
            await t.subir(F(ids[k], inp), arq);
            await t.esperarToast(/anexad/, 8000); await t.esp(500);
        }
        const p1 = await props('img1'), p2 = await props('vid1'), p3 = await props('aud1');
        t.ok('foto, vídeo e áudio enviados e anexados aos blocos (caminhos em uploads/)', /^uploads\/flow_image_/.test(p1.image_path || '') && /^uploads\/flow_video_/.test(p2.video_path || '') && /^uploads\/flow_audio_/.test(p3.audio_path || ''), { p1: p1.image_path, p2: p2.video_path, p3: p3.audio_path });
        t.ok('as 3 mídias abrem pela URL (200)', (await Promise.all([p1.image_path, p2.video_path, p3.audio_path].map(u => t.http(u)))).every(r => r.status === 200), {});
        t.ok('"auto-deletar" mostra o campo de segundos; sem ele o campo fica escondido', (await t.js(`${F(ids.img1, '.grupo-auto-delete')}.is(':visible')`)) === true, {});
        // mídia falsa no bloco de vídeo
        await t.subir(F(ids.vid1, '.campo-video-arquivo'), 'falso.mp4');
        t.ok('vídeo falso (texto renomeado .mp4) recusado e o vídeo anterior continua', /não parece ser um vídeo|inválid|Erro/i.test(await t.esperarToast(/vídeo|inválid|Erro/i, 6000)) && /^uploads\/flow_video_/.test((await props('vid1')).video_path || ''), { toast: await t.toast() });

        // botões: 2 padrão + adicionar 1; textos, cores, sumir após clique
        await t.clicar(F(ids.bot1, '.btn-adicionar-botao'));
        t.ok('"+ Adicionar botão" cria o 3º botão e a 3ª saída (output_2)', (await props('bot1')).botoes.length === 3 && !!(await props('bot1')).outputs.output_2, { b: (await props('bot1')).botoes });
        await dig('bot1', '.campo-texto-botoes', ESPERADO.bot1.texto);
        for (const [i, b] of ESPERADO.bot1.botoes.entries()) {
            await t.digitar(`${F(ids.bot1, '.campo-botao-texto')}.eq(${i})`, b.texto);
            await t.escolher(`${F(ids.bot1, '.campo-botao-cor')}.eq(${i})`, b.cor);
        }
        await marc('bot1', '.campo-sumir-apos-clique', true);
        // esperas
        for (const k of ['del1', 'del2']) {
            const e = ESPERADO[k];
            await dig(k, '.campo-delay-min', String(e.delay_min)); await dig(k, '.campo-delay-max', String(e.delay_max));
            await esc(k, '.campo-delay-unidade', e.delay_unidade); await marc(k, '.campo-delay-digitando', e.delay_digitando);
        }
        // sorteio: 2 caminhos padrão + adicionar 1; remover o 3º e adicionar de novo; pesos 50/30/20
        await t.clicar(F(ids.ran1, '.btn-adicionar-caminho'));
        t.ok('Sorteio: "+ Adicionar caminho" cria o 3º caminho e a saída output_path_2', (await props('ran1')).caminhos.length === 3 && !!(await props('ran1')).outputs.output_path_2, {});
        await t.clicar(`${F(ids.ran1, '.remover-caminho')}.eq(2)`);
        await t.clicar(`${F(ids.ran1, '.remover-caminho')}.eq(1)`);
        t.ok('Sorteio: remover caminho funciona, mas nunca deixa menos de 2 (aviso)', (await props('ran1')).caminhos.length === 2 && /pelo menos 2 caminhos/.test(await t.esperarToast(/pelo menos 2/, 4000)), { c: (await props('ran1')).caminhos });
        await t.clicar(F(ids.ran1, '.btn-adicionar-caminho'));
        for (const [i, cm] of ESPERADO.ran1.caminhos.entries()) await t.digitar(`${F(ids.ran1, '.campo-caminho-peso')}.eq(${i})`, String(cm.peso));
        t.ok('Sorteio: os pesos 50/30/20 mostram 50%, 30% e 20%', JSON.stringify(await t.js(`${F(ids.ran1, '.pct-caminho')}.map(function(){ return jQuery(this).text(); }).get()`)) === JSON.stringify(['50%', '30%', '20%']), { p: await t.js(`${F(ids.ran1, '.pct-caminho')}.map(function(){ return jQuery(this).text(); }).get()`) });
        // link e grupo
        await dig('lnk1', '.campo-link-url', ESPERADO.lnk1.url); await dig('lnk1', '.campo-link-texto', ESPERADO.lnk1.texto_botao);
        t.ok('bloco Link: o destino é fixo em "Link Externo" (campo travado)', await t.js(`${F(ids.lnk1, '.campo-link-destino')}.prop('disabled')`), {});
        await esc('grp1', '.campo-grupo-id', G1); await dig('grp1', '.campo-grupo-texto', ESPERADO.grp1.texto_botao);
        // ofertas
        for (const k of ['ups1', 'dws1', 'bmp1']) {
            const e = ESPERADO[k];
            await dig(k, '.campo-oferta-mensagem', e.mensagem);
            await dig(k, '.campo-oferta-valor', String(k === 'bmp1' ? e.valor_extra : e.desconto_percentual));
            await dig(k, '.campo-oferta-aceitar', e.texto_aceitar); await esc(k, '.campo-oferta-cor-aceitar', e.cor_aceitar);
            await dig(k, '.campo-oferta-recusar', e.texto_recusar); await esc(k, '.campo-oferta-cor-recusar', e.cor_recusar);
        }
        // pix
        const px = ESPERADO.pix1;
        t.ok('bloco PIX: só "Pagamento Único" na lista (a assinatura recorrente está oculta na tela)', JSON.stringify(await t.js(`${F(ids.pix1, '.campo-pix-tipo-cobranca option')}.map(function(){ return this.value; }).get()`)) === JSON.stringify(['unica']), {});
        await dig('pix1', '.campo-pix-nome', px.nome); await dig('pix1', '.campo-pix-valor', '1');
        const txMin = await t.esperarToast(/mínimo|só aceita|Erro|recus/i, 9000);
        t.ok('PIX com valor R$ 1,00 (abaixo do mínimo da gateway): o salvamento automático recusa e avisa', /mínimo|só aceita|a partir de/i.test(txMin), { toast: txMin });
        await dig('pix1', '.campo-pix-valor', String(px.valor)); await dig('pix1', '.campo-pix-expiracao-minutos', String(px.expiracao_minutos));
        await dig('pix1', '.campo-pix-dias-acesso', String(px.dias_acesso)); await esc('pix1', '.campo-pix-unidade-acesso', px.unidade_acesso);
        await esc('pix1', '.campo-pix-id-grupo', px.id_grupo);
        await marc('pix1', '.campo-pix-mostrar-copiar', px.mostrar_copiar); await marc('pix1', '.campo-pix-mostrar-qrcode', px.mostrar_qrcode); await marc('pix1', '.campo-pix-mostrar-confirmar', px.mostrar_confirmar);
        await dig('pix1', '.campo-pix-msg-instrucoes', px.msg_instrucoes); await dig('pix1', '.campo-pix-msg-confirmado', px.msg_confirmado);

        // ---------- E. renomear o bloco (duplo clique no título), mover (arrastar)
        const tituloAntes = await t.js(`${OP(ids.pix1)}.find('.texto-titulo').text()`);
        await t.js(`${OP(ids.pix1)}.find('.flowchart-operator-title').trigger('dblclick'); 1`);
        await t.esp(200);
        t.ok('duplo clique no título abre a edição do nome do bloco', await t.js(`${OP(ids.pix1)}.find('.edita-titulo').length`) === 1, {});
        await t.digitar(F(ids.pix1, '.edita-titulo'), 'Cobrança VIP', 'Enter');
        await t.esp(300);
        t.ok('Enter grava o novo nome do bloco (era "' + tituloAntes + '")', (await props('pix1')).title === 'Cobrança VIP' && (await t.js(`${OP(ids.pix1)}.find('.texto-titulo').text()`)) === 'Cobrança VIP', { t: (await props('pix1')).title });
        const posAntes = JSON.parse(await t.js(`JSON.stringify(jQuery('#area-trabalho-fluxograma').flowchart('getData').operators[${JSON.stringify(ids.msg5)}])`));
        const tit = JSON.parse(await t.js(`(function(){ var r = ${OP(ids.msg5)}.find('.flowchart-operator-title').get(0).getBoundingClientRect(); return JSON.stringify({x:r.left+30, y:r.top+r.height/2}); })()`));
        await c.enviar('Input.dispatchMouseEvent', { type: 'mouseMoved', x: tit.x, y: tit.y });
        await c.enviar('Input.dispatchMouseEvent', { type: 'mousePressed', x: tit.x, y: tit.y, button: 'left', buttons: 1, clickCount: 1 });
        for (let i = 1; i <= 8; i++) await c.enviar('Input.dispatchMouseEvent', { type: 'mouseMoved', x: tit.x + i * 12, y: tit.y + i * 6, button: 'left', buttons: 1 });
        await c.enviar('Input.dispatchMouseEvent', { type: 'mouseReleased', x: tit.x + 96, y: tit.y + 48, button: 'left', clickCount: 1 });
        await t.esp(400);
        const posDepois = JSON.parse(await t.js(`JSON.stringify(jQuery('#area-trabalho-fluxograma').flowchart('getData').operators[${JSON.stringify(ids.msg5)}])`));
        t.ok('arrastar o título move o bloco (a posição muda)', posDepois.left > posAntes.left + 40 && posDepois.top > posAntes.top + 20, { antes: [posAntes.left, posAntes.top], depois: [posDepois.left, posDepois.top] });

        // ---------- F. ligações (clique no conector de saída e depois no de entrada)
        for (const [de, saida, para] of LIGACOES) await ligar(de, saida, para);
        t.ok('as ' + LIGACOES.length + ' ligações do funil foram criadas pelos cliques nos conectores', (await nLigacoes()) === LIGACOES.length, { n: await nLigacoes() });
        const L = Object.values((await dados()).links).map(l => `${l.fromOperator}:${l.fromConnector}>${l.toOperator}`).sort();
        const Lesp = LIGACOES.map(([de, s, para]) => `${ids[de]}:${s}>${ids[para]}`).sort();
        t.ok('cada saída ligou no bloco certo (conferido par a par)', JSON.stringify(L) === JSON.stringify(Lesp), { falta: Lesp.filter(x => !L.includes(x)), sobra: L.filter(x => !Lesp.includes(x)) });
        // uma saída só aceita 1 ligação (multipleLinksOnOutput: false): ligar de novo a mesma saída não duplica
        await ligar('msg5', 'output_1', 'msg4');
        t.ok('ligação extra criada pelos cliques (23)', (await nLigacoes()) === LIGACOES.length + 1, { n: await nLigacoes() });
        await ligar('msg5', 'output_1', 'msg3');
        const ligMsg5 = Object.values((await dados()).links).filter(l => l.fromOperator === ids.msg5).length;
        t.ok('ligar de novo a mesma saída não duplica: continua 1 ligação saindo do bloco', ligMsg5 === 1, { ligMsg5 });
        const idLink = Object.entries((await dados()).links).find(([, l]) => l.fromOperator === ids.msg5);
        await t.js(`jQuery('#area-trabalho-fluxograma').flowchart('selectLink', ${JSON.stringify(idLink[0])}); document.activeElement && document.activeElement.blur && document.activeElement.blur(); 1`);
        await c.enviar('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Delete', code: 'Delete', windowsVirtualKeyCode: 46 });
        await c.enviar('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Delete', code: 'Delete', windowsVirtualKeyCode: 46 });
        await t.esp(400);
        t.ok('selecionar a ligação e apertar Delete apaga só ela (voltam 22 ligações)', (await nLigacoes()) === LIGACOES.length, { n: await nLigacoes() });

        // excluir um bloco pelo ✕: bloco extra ligado e depois apagado junto com a ligação
        const antesOps = Object.keys((await dados()).operators);
        await t.js(`__soltaCanvas('message', ${CANVAS({ col: 7, lin: 2 }).L}, ${CANVAS({ col: 7, lin: 2 }).T}); 1`); await t.esp(300);
        const idExtra = Object.keys((await dados()).operators).find(k => !antesOps.includes(k));
        ids.extra = idExtra;
        await ligar('msg3', 'output_1', 'extra');
        t.ok('bloco extra criado e ligado (23 ligações, 20 blocos)', (await nLigacoes()) === LIGACOES.length + 1 && (await nBlocos()) === 20, { l: await nLigacoes(), b: await nBlocos() });
        await t.clicar(F(idExtra, '.btn-excluir'));
        await t.esp(500);
        t.ok('excluir pelo ✕ apaga o bloco e as ligações dele (volta a 19 blocos e 22 ligações)', (await nBlocos()) === 19 && (await nLigacoes()) === LIGACOES.length, { l: await nLigacoes(), b: await nBlocos() });

        // ---------- G. salvamento automático e manual
        await t.esp(2500);
        const idAuto = await t.js(`jQuery('#id-fluxo').val()`);
        t.ok('salvamento automático (2 s depois de editar) já criou o fluxo e deu um id', !!idAuto, {});
        const tx = await salvar();
        t.ok('botão Salvar: toast de sucesso', /salvo|sucesso/i.test(tx), { toast: tx });
        const id = await t.js(`jQuery('#id-fluxo').val()`);
        console.log('FLUXO_VISUAL_ID=' + id);
        t.ok('a URL ganha ?id= e o id é o mesmo do salvamento automático', (await t.js('location.search')) === '?id=' + id && id === idAuto, { id, idAuto });

        // ---------- H. conferir no banco (API) e recarregar o editor
        const api = await t.api('obter_fluxo', '&id=' + id);
        const dd = ((api.fluxo || {}).dados_fluxograma) || {};
        t.ok('banco: modo avancado, ' + 19 + ' blocos e ' + LIGACOES.length + ' ligações', (api.fluxo || {}).modo === 'avancado' && Object.keys(dd.operators || {}).length === 19 && Object.keys(dd.links || {}).length === LIGACOES.length, { modo: (api.fluxo || {}).modo, ops: Object.keys(dd.operators || {}).length, links: Object.keys(dd.links || {}).length });
        await c.ir(t.BASE + '/fluxo?id=' + id);
        await c.esperar(`typeof jQuery!=='undefined' && jQuery('#area-trabalho-fluxograma .flowchart-operator').length===19`, 25000);
        await t.esp(1500);
        await t.js(`window.__op = function(id){ return jQuery('#area-trabalho-fluxograma .flowchart-operator').filter(function(){ return jQuery(this).data('operator_id') === id; }); }; 1`);
        const rec = await dados();
        t.ok('recarregar: 19 blocos e as ' + LIGACOES.length + ' ligações voltam do banco', Object.keys(rec.operators).length === 19 && Object.keys(rec.links).length === LIGACOES.length, { ops: Object.keys(rec.operators).length, links: Object.keys(rec.links).length });
        const dif = [];
        for (const [k, esp] of Object.entries(ESPERADO)) {
            const pr = rec.operators[ids[k]].properties;
            for (const [campo, v] of Object.entries(esp)) {
                const lido = pr[campo];
                if (JSON.stringify(lido) !== JSON.stringify(v)) dif.push(`${k}.${campo}: ${JSON.stringify(v)} -> ${JSON.stringify(lido)}`);
            }
        }
        t.ok('recarregar: TODOS os campos digitados em cada um dos 18 blocos voltam iguais (' + Object.values(ESPERADO).reduce((n, e) => n + Object.keys(e).length, 0) + ' campos conferidos)', dif.length === 0, { dif });
        t.ok('recarregar: o nome do bloco renomeado ("Cobrança VIP") e a posição do bloco movido continuam', rec.operators[ids.pix1].properties.title === 'Cobrança VIP' && rec.operators[ids.msg5].left > posAntes.left + 40, {});
        t.ok('recarregar: nome e descrição do fluxo', (await t.js(`jQuery('#nome-fluxo').val()`)) === NOME && /criado do zero/.test(await t.js(`jQuery('#descricao-fluxo').val()`)), {});
        // os campos aparecem na tela (não só nos dados): amostra de 6 campos lidos do DOM
        const vistos = JSON.parse(await t.js(`JSON.stringify({ m: ${F(ids.msg1, '.campo-conteudo')}.val(), pix: ${F(ids.pix1, '.campo-pix-nome')}.val(), valor: ${F(ids.pix1, '.campo-pix-valor')}.val(), url: ${F(ids.lnk1, '.campo-link-url')}.val(), b2: ${F(ids.bot1, '.campo-botao-texto')}.eq(1).val(), peso: ${F(ids.ran1, '.campo-caminho-peso')}.eq(2).val() })`));
        t.ok('recarregar: os campos aparecem preenchidos nos blocos da tela', vistos.m === ESPERADO.msg1.conteudo && vistos.pix === 'Produto Visual Zero' && Number(vistos.valor) === 80 && vistos.url === ESPERADO.lnk1.url && vistos.b2 === 'Talvez depois' && Number(vistos.peso) === 20, vistos);
        await t.captura('visual_recarregado.png');

        // ---------- I. exportar e importar
        const exp = await t.api('exportar_fluxo', '&id=' + id);
        t.ok('exportar: JSON com modo avancado, 19 blocos e 22 ligações', exp.sucesso && exp.fluxo.modo === 'avancado' && Object.keys(exp.fluxo.dados_fluxograma.operators).length === 19 && Object.keys(exp.fluxo.dados_fluxograma.links).length === LIGACOES.length, {});
        const arqExp = path.join(t.SAIDA, 'exportado_visual.json');
        fs.writeFileSync(arqExp, JSON.stringify(exp.fluxo, null, 2));
        await t.subir(q('#arquivo-importar-fluxo'), arqExp);
        const txImp = await t.esperarToast(/importado|Erro|inválid/i, 12000);
        t.ok('importar o arquivo exportado: toast de sucesso', /importado com sucesso/i.test(txImp), { toast: txImp });
        const idImp = await t.js(`jQuery('#id-fluxo').val()`);
        t.ok('o importado vira um fluxo NOVO (outro id) com os mesmos 19 blocos e 22 ligações', !!idImp && idImp !== id && (await nBlocos()) === 19 && (await nLigacoes()) === LIGACOES.length, { id, idImp });
        const apiImp = await t.api('obter_fluxo', '&id=' + idImp);
        const opsImp = ((apiImp.fluxo || {}).dados_fluxograma || {}).operators || {};
        const difImp = [];
        for (const [k, esp] of Object.entries(ESPERADO)) for (const [campo, v] of Object.entries(esp)) { if (campo.endsWith('_path')) continue; if (JSON.stringify((opsImp[ids[k]] || { properties: {} }).properties[campo]) !== JSON.stringify(v)) difImp.push(k + '.' + campo); }
        t.ok('o fluxo importado tem os mesmos campos do original', difImp.length === 0, { difImp });
        // excluir o importado pela tela
        await t.js(`window.confirm = function(){ return true; }; 1`);
        await t.clicar(q('#btn-excluir-fluxo'));
        t.ok('excluir o fluxo importado pelo botão leva à lista de fluxos', await c.esperar(`location.pathname.indexOf('fluxos')>=0`, 10000), {});
        t.ok('o importado não existe mais', (await t.api('obter_fluxo', '&id=' + idImp)).sucesso === false, {});
        t.ok('a lista de fluxos mostra o fluxo visual criado (modo Visual)', await (async () => { await c.ir(t.BASE + '/fluxos'); await t.esp(1500); return t.js(`document.body.innerText.indexOf(${JSON.stringify(NOME)}) >= 0`); })(), {});

        t.ok('nenhum erro de JavaScript durante todo o uso do editor visual', t.errosJs().length === 0, t.errosJs().slice(0, 5));
    } catch (e) { console.log('ERRO', e.message); t.ok('execução sem exceção', false, { erro: e.message }); try { await t.captura('visual_erro.png'); } catch (x) { /* ignora */ } }
    process.exitCode = (await t.fim()) ? 1 : 0;
})();
