// Fluxo GUIADO criado DO ZERO pela tela (Chrome de verdade): todas as seções, todos os campos, com as validações da tela, uploads de
// mídia, salvar, recarregar do banco e conferir que tudo voltou igual. Imprime FLUXO_GUIADO_ID=<id> para os testes de motor.
const { iniciar } = require('./zero_lib.js');

const G1 = '-1001000000001', G2 = '-1001000000002', GC = '-1001000000099';
const q = (s) => 'jQuery(' + JSON.stringify(s) + ')';
const plano = (i, s) => `jQuery('#lista-planos .painel-plano').eq(${i}).find(${JSON.stringify(s)})`;
const oferta = (tipo, s) => `jQuery('.painel[data-oferta="${tipo}"]').find(${JSON.stringify(s)})`;
const pack = (i, s) => `jQuery('#pk-lista .pk-item').eq(${i}).find(${JSON.stringify(s)})`;
// Entrega em 2 passos (item 31, 26/10/2026): o select de TIPO ("Acesso a grupo") e, em seguida, o select "Grupo para acesso"; grupo vazio = "Nenhum".
async function escolherEntrega(t, achar, seletor_tipo, grupo) {
    if (grupo) { await t.escolher(achar(seletor_tipo), '__grupo__'); await t.escolher(achar('.entrega-grupo-select'), grupo); }
    else await t.escolher(achar(seletor_tipo), '');
}

const NOME = 'Zero Guiado ' + Date.now();
// O que a pessoa digita/escolhe na tela (e que tem que voltar igual do banco).
const E = {
    nome: NOME, desc: 'Fluxo guiado criado do zero pelo teste automático', ativo: true,
    bv: { msg: 'Olá! Bem-vindo ao ZERO 🚀\nSegunda linha da mensagem', cta: 'Quero entrar agora', cor: 'success', midia: 'image' },
    planos: [
        { nome: 'Mensal Zero', valor: 19.9, dias: 30, unidade: 'dias', grupo: G1, cor: 'primary' },
        { nome: 'Passe 2h Zero', valor: 5, dias: 2, unidade: 'horas', grupo: G2, cor: 'success' },
        { nome: 'Teste 90min Zero', valor: 3.5, dias: 90, unidade: 'minutos', grupo: '', cor: 'danger' },
        { nome: 'Anual Zero', valor: 199, dias: 365, unidade: 'dias', grupo: G1, cor: '' },
    ],
    upsell: { ativo: true, plano: 'Anual Zero', desc: 20, msg: 'Leve o Anual com 20% OFF!', aceitar: 'Quero o Anual', corA: 'success', recusar: 'Prefiro o Mensal', corR: 'danger' },
    downsell: { ativo: true, plano: 'Teste 90min Zero', desc: 10, msg: 'Que tal só 90 minutos por menos?', aceitar: 'Pode ser', corA: 'primary', recusar: 'Agora não', corR: '' },
    bump: { ativo: true, nome: 'Bônus VIP Zero', extra: 7.5, msg: 'Adicione o Bônus VIP Zero por R$ 7,50?', aceitar: 'Adicionar bônus', corA: 'success', recusar: 'Sem bônus', corR: 'danger' },
    packs: {
        ativo: true, botao: '📦 Meus Packs Zero', msg: 'Escolha seu pack Zero:',
        itens: [
            { ativo: true, emoji: '🔥', nome: 'Pack Hot Zero', preco: 29.9, desc: 'Descrição do pack hot', midia: 'Foto', grupo: G1 },
            { ativo: true, emoji: '🎬', nome: 'Pack Vídeo Zero', preco: 39.9, desc: 'Descrição do pack vídeo', midia: 'Vídeo', grupo: G2 },
            { ativo: false, emoji: '🎧', nome: 'Pack Áudio Zero', preco: 9.9, desc: 'Descrição do pack áudio', midia: 'Áudio', grupo: '' },
        ],
    },
    previas: { ativo: true, botao: '👀 Prévias Zero', midias: ['Áudio', 'Foto', 'Vídeo'], apagar: 30, rever: 'diario', proteger: true, junto: 'Corre! Somem em 30 segundos', depois: 'As prévias expiraram, o VIP te espera', botaoPlanos: false },
    pag: { instr: 'Instruções Zero: copie o código e pague', conf: 'Confirmado Zero: acesso liberado!', copiar: false, qr: true, confirmar: true },
    ass: {
        antes: { ativo: true, dias: [14, 7, 3, 0], midia: 'image', msg: 'Oi {nome}, o plano {plano} expira {prazo}. Renove com {dias} dias de folga' },
        dia: { ativo: true, qtd: 2, horas: ['09:00', '18:00'] },
        exp: { ativo: true, midia: 'audio', msg: '{nome}, expirou! Renove agora.' },
        desconto: 25, expulsar: false,
    },
    suporte: '@suporte_zero', suporteCor: 'primary',
};

const LER = `(function(){ var $=jQuery; var num=function(x){ var n=parseFloat(x); return isNaN(n) ? x : n; };
  var oft=function(t){ var p=$('.painel[data-oferta="'+t+'"]'); var o={ ativo:p.find('.of-ativo').is(':checked'), msg:p.find('.of-mensagem').val(), aceitar:p.find('.of-aceitar').val(), corA:p.find('.of-aceitar-cor').val(), recusar:p.find('.of-recusar').val(), corR:p.find('.of-recusar-cor').val() };
    if (t==='order_bump') { o.nome=p.find('.of-nome').val(); o.extra=num(p.find('.of-valor-extra').val()); } else { o.plano=p.find('.of-plano option:selected').text().replace(/ — R\\$.*$/,''); o.desc=num(p.find('.of-desconto').val()); } return o; };
  var tipoMidiaBv=$('#bv-midia-previa').attr('data-tipo')||'none';
  return JSON.stringify({
    nome:$('#nome-fluxo').val(), desc:$('#descricao-fluxo').val(), ativo:$('#fluxo-ativo').is(':checked'),
    bv:{ msg:$('#bv-mensagem').val(), cta:$('#bv-cta').val(), cor:$('#bv-cta-cor').val(), midia:tipoMidiaBv },
    planos:$('#lista-planos .painel-plano').map(function(){ var p=$(this); return { nome:p.find('.campo-plano-nome').val(), valor:num(p.find('.campo-plano-valor').val()), dias:num(p.find('.campo-plano-dias').val()), unidade:p.find('.campo-plano-unidade').val(), grupo:p.find('.campo-plano-grupo').val(), cor:p.find('.campo-plano-cor').val() }; }).get(),
    upsell:oft('upsell'), downsell:oft('downsell'), bump:oft('order_bump'),
    packs:{ ativo:$('#pk-ativo').is(':checked'), botao:$('#pk-botao').val(), msg:$('#pk-mensagem').val(), itens:$('#pk-lista .pk-item').map(function(){ var p=$(this); return { ativo:p.find('.pk-ativo-item').is(':checked'), emoji:p.find('.pk-emoji').val(), nome:p.find('.pk-nome').val(), preco:num(p.find('.pk-preco').val()), desc:p.find('.pk-descricao').val(), midia:(/^(Foto|Vídeo|Áudio)/.exec(p.find('.linha-flex').last().text().trim())||[])[1]||'nenhuma', grupo:p.find('.pk-grupo').val() }; }).get() },
    previas:{ ativo:$('#pv-ativo').is(':checked'), botao:$('#pv-botao').val(), midias:$('#pv-lista .basico-bot').map(function(){ return (/^\\d+\\. (Foto|Vídeo|Áudio)/.exec($(this).text().trim())||[])[1]; }).get(), apagar:num($('#pv-apagar').val()), rever:$('#pv-rever').val(), proteger:$('#pv-proteger').is(':checked'), junto:$('#pv-junto').val(), depois:$('#pv-depois').val(), botaoPlanos:$('#pv-botao-planos').is(':checked') },
    pag:{ instr:$('#pg-msg-instrucoes').val(), conf:$('#pg-msg-confirmado').val(), copiar:$('#pg-mostrar-copiar').is(':checked'), qr:$('#pg-mostrar-qrcode').is(':checked'), confirmar:$('#pg-mostrar-confirmar').is(':checked') },
    ass:{ antes:{ ativo:$('#as-antes-ativo').is(':checked'), dias:$('#as-dias .as-chip.ativo').map(function(){ return parseInt($(this).data('dia'),10); }).get().sort(function(a,b){return b-a;}), midia:$('[data-midia-bloco="antes"] .as-chip.ativo').data('tipo'), msg:$('#as-antes-msg').val() },
          dia:{ ativo:$('#as-dia-ativo').is(':checked'), qtd:num($('#as-dia-qtd').val()), horas:$('#as-horas .as-hora.ativo').map(function(){ return $(this).data('hora'); }).get().sort() },
          exp:{ ativo:$('#as-exp-ativo').is(':checked'), midia:$('[data-midia-bloco="expirar"] .as-chip.ativo').data('tipo'), msg:$('#as-exp-msg').val() },
          desconto:num($('#as-desconto').val()), expulsar:$('#as-expulsar').is(':checked') },
    suporte:$('#suporte-usuario').val(), suporteCor:$('#suporte-cor').val()
  }); })()`;

// compara E com o que a tela mostra; devolve as diferenças (caminho: esperado -> lido)
function diferencas(esp, lido, caminho = '', out = []) {
    if (esp !== null && typeof esp === 'object') {
        for (const k of Object.keys(esp)) diferencas(esp[k], lido == null ? undefined : lido[k], caminho + '.' + k, out);
        if (Array.isArray(esp) && Array.isArray(lido) && esp.length !== lido.length) out.push(caminho + '.length: ' + esp.length + ' -> ' + lido.length);
    } else if (esp !== lido) out.push(caminho + ': ' + JSON.stringify(esp) + ' -> ' + JSON.stringify(lido));
    return out;
}

(async () => {
    const t = await iniciar('zero_guiado', { largura: 1500, altura: 1000 });
    const { c } = t;
    const secao = async (nome) => { await t.clicar(q(`.basico-nav-item[data-secao="${nome}"]`)); await t.esp(200); };
    const nomeFluxo = async (texto) => {
        await t.clicar(q('#btn-editar-nome'));
        await t.digitar(q('#nome-fluxo'), texto, 'Enter');
        await t.esp(200);
    };
    const salvarEErro = async (re, rotulo) => {
        await t.clicar(q('#btn-salvar-fluxo-basico'));
        const tx = await t.esperarToast(re, 5000);
        t.ok(rotulo, re.test(tx), { toast: tx });
    };
    try {
        t.ok('login do usuário de teste', await t.entrar(), {});
        await c.ir(t.BASE + '/fluxo_basico');
        t.ok('editor guiado (novo) abre', await c.esperar(`typeof jQuery!=='undefined' && jQuery('#btn-adicionar-plano').length>0 && jQuery('.basico-nav-item').length===11`, 20000), {});
        await t.esp(1200);

        // ---------- A. estado inicial de um fluxo novo
        const bloqueadas = JSON.parse(await t.js(`JSON.stringify(jQuery('.basico-nav-item.bloqueado').map(function(){ return jQuery(this).data('secao'); }).get())`));
        t.ok('fluxo novo: só "Conversões" fica travada (as outras seções, inclusive Planos, estão livres)', bloqueadas.length === 1 && bloqueadas[0] === 'conversoes', { bloqueadas });
        t.ok('título "Novo fluxo", botão Excluir escondido, aviso de como começar visível', (await t.js(`jQuery('#titulo-fluxo').text()`)) === 'Novo fluxo' && (await t.js(`jQuery('#btn-excluir-fluxo-basico').css('display')`)) === 'none' && (await t.js(`jQuery('#nav-aviso').is(':visible')`)), {});
        await t.clicar(q('.basico-nav-item[data-secao="conversoes"]'));
        t.ok('clicar em Conversões antes de salvar: aviso e a seção não abre', /Salve o fluxo/.test(await t.esperarToast(/Salve/, 3000)) && (await t.js(`jQuery('.basico-secao.ativa').data('secao')`)) === 'bots', {});

        // ---------- B. nome e descrição
        await t.clicar(q('#btn-editar-nome'));
        t.ok('lápis do título abre o campo do nome', await t.js(`jQuery('#nome-fluxo').is(':visible')`), {});
        await t.digitar(q('#nome-fluxo'), E.nome, 'Enter');
        await t.esp(200);
        t.ok('Enter fecha o campo e o título mostra o nome digitado', (await t.js(`jQuery('#titulo-fluxo').text()`)) === E.nome && !(await t.js(`jQuery('#nome-fluxo').is(':visible')`)), { titulo: await t.js(`jQuery('#titulo-fluxo').text()`) });
        await t.digitar(q('#descricao-fluxo'), E.desc);
        t.ok('status "Alterações não salvas" aparece ao editar', /não salvas/i.test(await t.js(`jQuery('#status-salvo').text()`)), {});

        // ---------- C. validações dos planos (antes de qualquer plano existir)
        await salvarEErro(/Adicione pelo menos 1 plano/, 'salvar sem plano: a tela recusa e explica');
        await secao('planos');
        await t.clicar(q('#btn-adicionar-plano'));
        await salvarEErro(/está sem nome/, 'plano sem nome: recusado');
        await t.digitar(plano(0, '.campo-plano-nome'), 'Plano teste');
        await salvarEErro(/maior que zero/, 'plano com valor 0: recusado');
        await t.digitar(plano(0, '.campo-plano-valor'), '1.00');
        await salvarEErro(/Para a Omega, o valor mínimo é R\$/, 'plano abaixo do mínimo da gateway (R$ 1,00): recusado com o valor mínimo');

        // ---------- D. os 4 planos de verdade
        for (let i = 1; i < E.planos.length; i++) await t.clicar(q('#btn-adicionar-plano'));
        t.ok('4 planos na lista', (await t.js(`jQuery('#lista-planos .painel-plano').length`)) === 4, {});
        for (const [i, p] of E.planos.entries()) {
            await t.digitar(plano(i, '.campo-plano-nome'), p.nome);
            await t.digitar(plano(i, '.campo-plano-valor'), String(p.valor));
            await t.digitar(plano(i, '.campo-plano-dias'), String(p.dias));
            await t.escolher(plano(i, '.campo-plano-unidade'), p.unidade);
            await escolherEntrega(t, (s) => plano(i, s), '.campo-plano-grupo', p.grupo);
            await t.escolher(plano(i, '.campo-plano-cor'), p.cor);
        }
        t.ok('plano sem grupo mostra o aviso "Sem grupo"; com grupo o aviso some', (await t.js(`!jQuery('#lista-planos .painel-plano').eq(2).find('.dica-sem-grupo').prop('hidden')`)) && (await t.js(`jQuery('#lista-planos .painel-plano').eq(0).find('.dica-sem-grupo').prop('hidden')`)), {});
        // remover um plano e recolocar (botão da lixeira)
        await t.clicar(plano(3, '.remover-plano'));
        t.ok('remover plano pela lixeira: sobram 3', (await t.js(`jQuery('#lista-planos .painel-plano').length`)) === 3, {});
        await t.clicar(q('#btn-adicionar-plano'));
        const p3 = E.planos[3];
        await t.digitar(plano(3, '.campo-plano-nome'), p3.nome); await t.digitar(plano(3, '.campo-plano-valor'), String(p3.valor)); await t.digitar(plano(3, '.campo-plano-dias'), String(p3.dias));
        await t.escolher(plano(3, '.campo-plano-unidade'), p3.unidade); await escolherEntrega(t, (s) => plano(3, s), '.campo-plano-grupo', p3.grupo); await t.escolher(plano(3, '.campo-plano-cor'), p3.cor);
        t.ok('o plano removido e refeito mantém os 3 anteriores intactos', (await t.js(`jQuery('#lista-planos .painel-plano').eq(1).find('.campo-plano-nome').val()`)) === E.planos[1].nome, {});
        await t.captura('guiado_planos.png');

        // ---------- E. boas-vindas
        await secao('boasvindas');
        await t.digitar(q('#bv-mensagem'), E.bv.msg);
        await t.digitar(q('#bv-cta'), E.bv.cta);
        await t.escolher(q('#bv-cta-cor'), E.bv.cor);
        await t.subir(q('#bv-midia-arquivo'), 'falso.mp4');
        t.ok('mídia falsa (.txt renomeado para .mp4) é recusada pelo servidor', /não parece ser um vídeo|inválid|Erro/i.test(await t.esperarToast(/vídeo|inválid|Erro/i, 6000)), { toast: await t.toast() });
        t.ok('a mídia recusada não fica anexada', (await t.js(`jQuery('#bv-midia-previa').attr('data-estado')`)) === 'vazio', {});
        await t.subir(q('#bv-midia-arquivo'), 'video1.mp4');
        await t.esperarToast(/anexada/, 8000);
        t.ok('vídeo anexado às boas-vindas', (await t.js(`jQuery('#bv-midia-previa').attr('data-tipo')`)) === 'video', { tipo: await t.js(`jQuery('#bv-midia-previa').attr('data-tipo')`) });
        await t.subir(q('#bv-midia-arquivo'), 'foto2.jpg');
        await t.esp(2500);
        t.ok('trocar por uma foto: a mídia passa a ser imagem', (await t.js(`jQuery('#bv-midia-previa').attr('data-tipo')`)) === 'image', { tipo: await t.js(`jQuery('#bv-midia-previa').attr('data-tipo')`) });
        await t.captura('guiado_boasvindas.png');

        // ---------- F. pagamentos
        await secao('pagamentos');
        await t.digitar(q('#pg-msg-instrucoes'), E.pag.instr);
        await t.digitar(q('#pg-msg-confirmado'), E.pag.conf);
        await t.marcar(q('#pg-mostrar-copiar'), E.pag.copiar);
        await t.marcar(q('#pg-mostrar-qrcode'), E.pag.qr);
        await t.marcar(q('#pg-mostrar-confirmar'), E.pag.confirmar);

        // ---------- G. upsell / downsell / order bump
        await secao('upsell');
        await t.marcar(oferta('upsell', '.of-ativo'), true);
        t.ok('ligar o Upsell mostra os campos da oferta', await t.js(`jQuery('.painel[data-oferta="upsell"] .of-detalhes').is(':visible')`), {});
        await salvarEErro(/Escolha o plano oferecido no Upsell/, 'Upsell ligado sem plano: recusado');
        const optPlano = async (tipo, nomePlano) => t.escolher(oferta(tipo, '.of-plano'), await t.js(`jQuery('.painel[data-oferta="${tipo}"] .of-plano option').filter(function(){ return jQuery(this).text().indexOf(${JSON.stringify(nomePlano)}) === 0; }).val()`));
        t.ok('a lista do Upsell traz "Escolha um plano", a opção de criar um plano só dele e os planos criados (nome — preço)', (await t.js(`jQuery('.painel[data-oferta="upsell"] .of-plano option').length`)) === 6, { n: await t.js(`jQuery('.painel[data-oferta="upsell"] .of-plano option').length`) });
        for (const tipo of ['upsell', 'downsell']) {
            const o = E[tipo];
            if (tipo === 'downsell') { await secao('downsell'); await t.marcar(oferta(tipo, '.of-ativo'), true); }
            await optPlano(tipo, o.plano);
            await t.digitar(oferta(tipo, '.of-desconto'), String(o.desc));
            await t.digitar(oferta(tipo, '.of-mensagem'), o.msg);
            await t.digitar(oferta(tipo, '.of-aceitar'), o.aceitar); await t.escolher(oferta(tipo, '.of-aceitar-cor'), o.corA);
            await t.digitar(oferta(tipo, '.of-recusar'), o.recusar); await t.escolher(oferta(tipo, '.of-recusar-cor'), o.corR);
        }
        await secao('orderbump');
        await t.marcar(oferta('order_bump', '.of-ativo'), true);
        // Fluxo novo começa em "Depois do Pix" (sequência de bumps, 23/10/2026); esta bateria testa o jeito de sempre, UM bump oferecido depois do pagamento (zero_motor_guiado.sh).
        await t.escolher(oferta('order_bump', '.of-quando'), 'depois_pagamento');
        await salvarEErro(/valor extra do Order Bump/, 'Order Bump ligado com valor 0: recusado');
        await t.digitar(oferta('order_bump', '.of-nome'), E.bump.nome);
        await t.digitar(oferta('order_bump', '.of-valor-extra'), String(E.bump.extra));
        await t.digitar(oferta('order_bump', '.of-mensagem'), E.bump.msg);
        await t.digitar(oferta('order_bump', '.of-aceitar'), E.bump.aceitar); await t.escolher(oferta('order_bump', '.of-aceitar-cor'), E.bump.corA);
        await t.digitar(oferta('order_bump', '.of-recusar'), E.bump.recusar); await t.escolher(oferta('order_bump', '.of-recusar-cor'), E.bump.corR);
        t.ok('desligar e religar a oferta guarda o que foi digitado (campos só escondem)', await (async () => { await t.marcar(oferta('order_bump', '.of-ativo'), false); const escondeu = !(await t.js(`jQuery('.painel[data-oferta="order_bump"] .of-detalhes').is(':visible')`)); await t.marcar(oferta('order_bump', '.of-ativo'), true); return escondeu && (await t.js(`jQuery('.painel[data-oferta="order_bump"] .of-nome').val()`)) === E.bump.nome; })(), {});
        await t.captura('guiado_orderbump.png');

        // ---------- H. packs
        await secao('packs');
        await t.marcar(q('#pk-ativo'), true);
        await salvarEErro(/pelo menos 1 pack/, 'Packs ligados sem nenhum pack: recusado');
        await t.digitar(q('#pk-botao'), E.packs.botao);
        await t.digitar(q('#pk-mensagem'), E.packs.msg);
        for (let i = 0; i < 3; i++) await t.clicar(q('#pk-adicionar'));
        await salvarEErro(/pack 1 está sem nome/, 'pack sem nome: recusado');
        await t.digitar(pack(0, '.pk-nome'), 'Pack tmp');
        await salvarEErro(/preço maior que zero/, 'pack com preço 0: recusado');
        const midiaPack = ['foto3.png', 'video1.mp4', 'audio1.mp3'];
        for (const [i, k] of E.packs.itens.entries()) {
            await t.digitar(pack(i, '.pk-emoji'), k.emoji);
            await t.digitar(pack(i, '.pk-nome'), k.nome);
            await t.digitar(pack(i, '.pk-preco'), String(k.preco));
            await t.digitar(pack(i, '.pk-descricao'), k.desc);
            await escolherEntrega(t, (s) => pack(i, s), '.pk-grupo', k.grupo);
            await t.clicar(pack(i, '.pk-midia'));                         // o clique abre o seletor de arquivos; o teste entrega o arquivo ao <input>
            await t.subir(q('#pk-arquivo'), midiaPack[i]);
            await t.esperarToast(/adicionada/, 8000);
            await t.esp(400);
            if (!k.ativo) await t.marcar(pack(i, '.pk-ativo-item'), false);
        }
        t.ok('3 packs com mídia (foto, vídeo, áudio) anexada', JSON.stringify(JSON.parse(await t.js(LER)).packs.itens.map(x => x.midia)) === JSON.stringify(['Foto', 'Vídeo', 'Áudio']), { lido: JSON.parse(await t.js(LER)).packs.itens.map(x => x.midia) });
        // limite de 20 packs
        for (let i = 3; i < 20; i++) await t.js(`jQuery('#pk-adicionar').click(); 1`);
        t.ok('limite de 20 packs: contador 20/20 e botão "+ Adicionar pack" desligado', (await t.js(`jQuery('#pk-contagem').text()`)) === '20/20' && (await t.js(`jQuery('#pk-adicionar').prop('disabled')`)), { c: await t.js(`jQuery('#pk-contagem').text()`) });
        for (let i = 19; i >= 3; i--) await t.js(`jQuery('#pk-lista .pk-item').eq(${i}).find('.pk-remover').click(); 1`);
        t.ok('removendo os extras voltam 3 packs e o botão reabre', (await t.js(`jQuery('#pk-lista .pk-item').length`)) === 3 && !(await t.js(`jQuery('#pk-adicionar').prop('disabled')`)), {});
        await t.clicar(pack(2, '.pk-tirar-midia'));
        t.ok('"tirar a mídia" do pack volta o botão de adicionar', /Adicionar mídia/.test(await t.js(`jQuery('#pk-lista .pk-item').eq(2).text()`)), {});
        await t.clicar(pack(2, '.pk-midia')); await t.subir(q('#pk-arquivo'), 'audio2.wav'); await t.esperarToast(/adicionada/, 8000); await t.esp(400);
        await t.captura('guiado_packs.png');

        // ---------- I. prévias
        await secao('previas');
        await t.marcar(q('#pv-ativo'), true);
        await salvarEErro(/pelo menos 1 mídia nas Prévias/, 'Prévias ligadas sem mídia: recusado');
        await t.digitar(q('#pv-botao'), E.previas.botao);
        const midiaPrev = ['video1.mp4', 'foto1.png', 'audio1.mp3'];
        for (const f of midiaPrev) { await t.subir(q('#pv-arquivo'), f); await t.esperarToast(/adicionada/, 8000); await t.esp(500); }
        t.ok('3 mídias nas prévias, na ordem de envio (Vídeo, Foto, Áudio)', JSON.stringify(JSON.parse(await t.js(LER)).previas.midias) === JSON.stringify(['Vídeo', 'Foto', 'Áudio']), { lido: JSON.parse(await t.js(LER)).previas.midias });
        await t.clicar(q('#pv-lista .pv-subir') + '.eq(2)');                                         // Áudio sobe uma posição
        await t.clicar(q('#pv-lista .pv-subir') + '.eq(1)');                                         // e mais uma: Áudio, Vídeo, Foto
        t.ok('subir mídia reordena a lista (Áudio, Vídeo, Foto)', JSON.stringify(JSON.parse(await t.js(LER)).previas.midias) === JSON.stringify(['Áudio', 'Vídeo', 'Foto']), { lido: JSON.parse(await t.js(LER)).previas.midias });
        await t.clicar(q('#pv-lista .pv-remover') + '.eq(1)');                                       // remove o Vídeo
        await t.subir(q('#pv-arquivo'), 'video1.mp4'); await t.esperarToast(/adicionada/, 8000); await t.esp(500);
        // ordem final esperada: Áudio, Foto, Vídeo
        t.ok('remover uma mídia e adicionar outra: lista Áudio, Foto, Vídeo', JSON.stringify(JSON.parse(await t.js(LER)).previas.midias) === JSON.stringify(['Áudio', 'Foto', 'Vídeo']), { lido: JSON.parse(await t.js(LER)).previas.midias });
        for (let i = 3; i < 8; i++) { await t.subir(q('#pv-arquivo'), 'foto1.png'); await t.esperarToast(/adicionada/, 8000); await t.esp(450); }
        t.ok('limite de 8 mídias: contador (8/8) e botão de adicionar desligado', (await t.js(`jQuery('#pv-contagem').text()`)) === '(8/8)' && (await t.js(`jQuery('#pv-adicionar').prop('disabled')`)), { c: await t.js(`jQuery('#pv-contagem').text()`) });
        for (let i = 7; i >= 3; i--) await t.js(`jQuery('#pv-lista .pv-remover').eq(${i}).click(); 1`);
        t.ok('removendo as extras volta para 3 mídias', (await t.js(`jQuery('#pv-lista .basico-bot').length`)) === 3, {});
        await t.escolher(q('#pv-apagar'), E.previas.apagar);
        await t.escolher(q('#pv-rever'), E.previas.rever);
        await t.marcar(q('#pv-proteger'), E.previas.proteger);
        await t.digitar(q('#pv-junto'), E.previas.junto);
        await t.digitar(q('#pv-depois'), E.previas.depois);
        await t.marcar(q('#pv-botao-planos'), E.previas.botaoPlanos);
        await t.captura('guiado_previas.png');

        // ---------- J. assinatura (renovação)
        await secao('assinatura');
        await t.marcar(q('#as-antes-ativo'), true);
        // padrão ao ligar: 7, 3, 1 e "no dia"; troca para 14, 7, 3, 0
        await t.clicar(q('#as-dias .as-chip[data-dia="1"]'));                                  // tira o 1
        await t.clicar(q('#as-dias .as-chip[data-dia="14"]'));                                 // põe o 14
        for (const d of [14, 7, 5, 3, 2, 1, 0]) { const ativo = await t.js(`jQuery('#as-dias .as-chip[data-dia="${d}"]').hasClass('ativo')`); if (ativo && ![14, 7, 3, 0].includes(d)) await t.clicar(q(`#as-dias .as-chip[data-dia="${d}"]`)); }
        // sem nenhum dia: recusado
        for (const d of [14, 7, 3, 0]) await t.clicar(q(`#as-dias .as-chip[data-dia="${d}"]`));
        await salvarEErro(/pelo menos 1 dia/, 'avisos antes de expirar ligados sem nenhum dia: recusado');
        for (const d of E.ass.antes.dias) await t.clicar(q(`#as-dias .as-chip[data-dia="${d}"]`));
        // mensagem: apaga o texto padrão, digita e usa os botões de variável
        await t.digitar(q('#as-antes-msg'), 'Oi ');
        await t.js(`(function(){ var el=jQuery('#as-antes-msg').get(0); el.focus(); el.selectionStart = el.selectionEnd = el.value.length; })()`);
        await t.clicar(q('#as-vars-antes .as-var[data-var="{nome}"]'));
        await t.js(`(function(){ var el=jQuery('#as-antes-msg').get(0); el.value = el.value + ', o plano '; })()`);
        await t.clicar(q('#as-vars-antes .as-var[data-var="{plano}"]'));
        t.ok('botões de variável inserem {nome} e {plano} no texto', /Oi \{nome\}, o plano \{plano\}/.test(await t.js(`jQuery('#as-antes-msg').val()`)), { v: await t.js(`jQuery('#as-antes-msg').val()`) });
        await t.digitar(q('#as-antes-msg'), E.ass.antes.msg);
        await t.clicar(q('[data-midia-bloco="antes"] .as-chip[data-tipo="image"]')); await t.subir(q('#as-arquivo'), 'foto3.png'); await t.esperarToast(/adicionada/, 8000); await t.esp(400);
        t.ok('mídia do aviso: chip "Imagem" fica ativo e mostra o nome do arquivo', (await t.js(`jQuery('[data-midia-bloco="antes"] .as-chip.ativo').data('tipo')`)) === 'image' && /Arquivo:/.test(await t.js(`jQuery('[data-midia-nome="antes"]').text()`)), {});
        await t.clicar(q('[data-midia-bloco="antes"] .as-chip[data-tipo="none"]'));
        t.ok('"Nenhuma" tira a mídia do aviso', (await t.js(`jQuery('[data-midia-nome="antes"]').text()`)) === '', {});
        await t.clicar(q('[data-midia-bloco="antes"] .as-chip[data-tipo="image"]')); await t.subir(q('#as-arquivo'), 'foto3.png'); await t.esperarToast(/adicionada/, 8000); await t.esp(400);

        await t.marcar(q('#as-dia-ativo'), true);
        await t.escolher(q('#as-dia-qtd'), E.ass.dia.qtd);
        t.ok('ao ligar "avisos no dia" já vêm 2 horários marcados (09:00 e 15:00)', JSON.stringify(JSON.parse(await t.js(LER)).ass.dia.horas) === JSON.stringify(['09:00', '15:00']), { h: JSON.parse(await t.js(LER)).ass.dia.horas });
        await t.clicar(q('#as-horas .as-hora[data-hora="15:00"]'));                              // tira um: sobra 1 de 2
        await salvarEErro(/Escolha os horários/, 'avisos no dia com só 1 dos 2 horários: recusado');
        await t.clicar(q('#as-horas .as-hora[data-hora="18:00"]'));                              // 09:00 + 18:00
        await t.clicar(q('#as-horas .as-hora[data-hora="21:00"]'));
        t.ok('escolher mais horários que a quantidade: aviso e o terceiro não entra', /já escolheu 2/.test(await t.esperarToast(/já escolheu/, 3000)) && !(await t.js(`jQuery('#as-horas .as-hora[data-hora="21:00"]').hasClass('ativo')`)), {});

        await t.marcar(q('#as-exp-ativo'), true);
        await t.digitar(q('#as-exp-msg'), E.ass.exp.msg);
        await t.clicar(q('[data-midia-bloco="expirar"] .as-chip[data-tipo="audio"]')); await t.subir(q('#as-arquivo'), 'audio1.mp3'); await t.esperarToast(/adicionada/, 8000); await t.esp(400);
        await t.escolher(q('#as-desconto'), E.ass.desconto);
        await t.marcar(q('#as-expulsar'), E.ass.expulsar);
        await t.captura('guiado_assinatura.png');

        // ---------- K. suporte, cache e interruptor "Ativo" (barra lateral/topo)
        await t.digitar(q('#suporte-usuario'), '@ab');
        await salvarEErro(/Usuário de suporte inválido/, 'suporte com nome curto demais (@ab): recusado');
        await t.digitar(q('#suporte-usuario'), E.suporte);
        await t.escolher(q('#suporte-cor'), E.suporteCor);
        await t.marcar(q('#fluxo-ativo'), false);
        t.ok('interruptor do fluxo mostra "Desligado"', (await t.js(`jQuery('#fluxo-ativo-rotulo').text()`)) === 'Desligado', {});
        await t.marcar(q('#fluxo-ativo'), true);
        t.ok('e volta para "Ativo"', (await t.js(`jQuery('#fluxo-ativo-rotulo').text()`)) === 'Ativo', {});

        // ---------- L. primeiro salvamento
        await t.clicar(q('#btn-salvar-fluxo-basico'));
        const txSalvou = await t.esperarToast(/sucesso|salvo/i, 10000);
        t.ok('primeiro salvamento: toast de sucesso', /sucesso|salvo/i.test(txSalvou), { toast: txSalvou });
        const id = await t.js(`jQuery('#id-fluxo').val()`);
        t.ok('o editor recebe o id e a URL passa a ter ?id=', !!id && (await t.js(`location.search`)) === '?id=' + id, { id });
        console.log('FLUXO_GUIADO_ID=' + id);
        t.ok('depois de salvar: "Tudo salvo", Excluir aparece, aviso de início some, Conversões abre', /Tudo salvo/.test(await t.js(`jQuery('#status-salvo').text()`)) && (await t.js(`jQuery('#btn-excluir-fluxo-basico').css('display')`)) !== 'none' && !(await t.js(`jQuery('#nav-aviso').is(':visible')`)) && !(await t.js(`jQuery('.basico-nav-item[data-secao="conversoes"]').hasClass('bloqueado')`)), {});

        // ---------- M. bots e cache
        await secao('bots');
        const opBots = JSON.parse(await t.js(`JSON.stringify(jQuery('#select-bot-vincular option').map(function(){ return [[this.value, jQuery(this).text()]]; }).get())`));
        const botTeste = opBots.find(o => o[0]);
        t.ok('a lista de bots do usuário aparece para vincular', !!botTeste, { opBots });
        await t.escolher(q('#select-bot-vincular'), botTeste ? botTeste[0] : '');
        t.ok('vincular o bot: toast e o bot entra na lista (1/10)', (await c.esperar(`jQuery('#bots-contagem').text().indexOf('1/10')===0`, 8000)), { c: await t.js(`jQuery('#bots-contagem').text()`) });
        await t.clicar(q('#btn-atualizar-bots')); await t.esp(1500);
        t.ok('cache de mídia: não há campo na tela (foi retirado em 13/10); o valor salvo antes é preservado ao salvar', (await t.js(`jQuery('#cache-chat-id').length`)) === 0, {});
        await t.clicar(q('.desvincular-bot'));
        t.ok('desvincular: volta a 0/10 bots', await c.esperar(`jQuery('#bots-contagem').text().indexOf('0/10')===0`, 8000), {});
        await t.escolher(q('#select-bot-vincular'), botTeste[0]);
        t.ok('vincular de novo: 1/10', await c.esperar(`jQuery('#bots-contagem').text().indexOf('1/10')===0`, 8000), {});
        await t.clicar(q('.basico-nav-item[data-secao="conversoes"]')); await t.esp(1200);
        t.ok('Conversões abre (sem vendas: aviso de "ainda sem dados")', (await t.js(`jQuery('.basico-secao.ativa').data('secao')`)) === 'conversoes', {});

        // ---------- N. salvar de novo (com o cache escolhido) e conferir no banco
        await t.clicar(q('#btn-salvar-fluxo-basico'));
        t.ok('segundo salvamento (com bot e cache): sucesso', /sucesso|salvo/i.test(await t.esperarToast(/sucesso|salvo/i, 10000)), { toast: await t.toast() });
        const api = await t.api('obter_fluxo', '&id=' + id);
        const d = (api.fluxo || {}).dados_fluxograma || {};
        t.ok('banco: modo basico, 4 planos, upsell/downsell/bump/packs/prévias/assinatura salvos', (api.fluxo || {}).modo === 'basico' && (d.planos || []).length === 4 && d.ofertas && d.ofertas.upsell.ativo === true && d.ofertas.downsell.ativo === true && d.ofertas.order_bump.valor_extra === 7.5 && d.packs.itens.length === 3 && d.previas.midias.length === 3 && d.assinatura.avisos_antes.dias.length === 4, { modo: (api.fluxo || {}).modo, planos: (d.planos || []).length, bump: d.ofertas && d.ofertas.order_bump, packs: d.packs && d.packs.itens.length, previas: d.previas && d.previas.midias.length, dias: d.assinatura && d.assinatura.avisos_antes.dias });
        t.ok('banco: horários 09:00/18:00, desconto de renovação 25 e expulsar desligado', JSON.stringify(d.assinatura.avisos_no_dia.horarios) === JSON.stringify(['09:00', '18:00']) && d.assinatura.renovacao.desconto === 25 && d.assinatura.acoes.expulsar === false, { a: d.assinatura });
        // arquivos de mídia salvos existem e são servidos
        const caminhos = [d.boas_vindas.midia_path, ...d.previas.midias.map(m => m.path), ...d.packs.itens.map(p => p.midia_path), d.assinatura.avisos_antes.midia_path, d.assinatura.ao_expirar.midia_path].filter(Boolean);
        const resp = [];
        for (const cm of caminhos) resp.push([cm, (await t.http(cm)).status]);
        t.ok('todas as ' + caminhos.length + ' mídias enviadas (boas-vindas, prévias, packs, avisos) abrem pela URL (200)', resp.length === 9 && resp.every(r => r[1] === 200), { resp });

        // ---------- O. recarregar do banco: tudo volta igual
        await c.ir(t.BASE + '/fluxo_basico?id=' + id);
        await c.esperar(`jQuery('#lista-planos .painel-plano').length>=3 && jQuery('#pk-lista .pk-item').length>=1`, 20000);
        await t.esp(1200);
        const lido = JSON.parse(await t.js(LER));
        const dif = diferencas(E, lido);
        t.ok('recarregar: os ' + Object.keys(E).length + ' blocos de campos digitados voltam IGUAIS (nome, boas-vindas, planos, ofertas, packs, prévias, pagamentos, assinatura, suporte)', dif.length === 0, { dif });
        t.ok('recarregar: título e status', (await t.js(`jQuery('#titulo-fluxo').text()`)) === E.nome && /Tudo salvo/.test(await t.js(`jQuery('#status-salvo').text()`)), {});
        await t.captura('guiado_recarregado.png');

        // ---------- O2. canal de cache que o fluxo já tinha (importado/antigo) sobrevive a uma edição pela tela
        await t.js(`(async function(){ var r = await fetch('api.php?action=obter_fluxo&id=${id}', {credentials:'include'}); var j = await r.json(); var f = j.fluxo; f.dados_fluxograma.cache_chat_id = ${JSON.stringify(GC)};
          var p = await fetch('api.php?action=salvar_fluxo', {method:'POST', credentials:'include', headers:{'Content-Type':'application/json','X-CSRF-Token': window.CSRF_TOKEN||''}, body: JSON.stringify({id:f.id, nome:f.nome, descricao:f.descricao, modo:'basico', dados_fluxograma:f.dados_fluxograma})}); return (await p.json()).sucesso; })()`);
        await c.ir(t.BASE + '/fluxo_basico?id=' + id); await c.esperar(`jQuery('#lista-planos .painel-plano').length>=3`, 20000); await t.esp(800);
        await t.digitar(q('#descricao-fluxo'), E.desc + ' (editada)');
        await t.clicar(q('#btn-salvar-fluxo-basico')); await t.esperarToast(/sucesso|salvo/i, 10000);
        const apiCache = await t.api('obter_fluxo', '&id=' + id);
        t.ok('canal de cache salvo antes (' + GC + ') continua no fluxo depois de editar e salvar pela tela', ((apiCache.fluxo || {}).dados_fluxograma || {}).cache_chat_id === GC, { cache: ((apiCache.fluxo || {}).dados_fluxograma || {}).cache_chat_id });
        await t.digitar(q('#descricao-fluxo'), E.desc);
        await t.clicar(q('#btn-salvar-fluxo-basico')); await t.esperarToast(/sucesso|salvo/i, 10000);

        // ---------- P. desligar o fluxo, salvar, recarregar; ligar de novo
        await t.marcar(q('#fluxo-ativo'), false);
        await t.clicar(q('#btn-salvar-fluxo-basico')); await t.esperarToast(/sucesso|salvo/i, 10000);
        await c.ir(t.BASE + '/fluxo_basico?id=' + id); await c.esperar(`jQuery('#lista-planos .painel-plano').length>=3`, 20000); await t.esp(800);
        t.ok('fluxo desligado e salvo volta desligado (rótulo "Desligado")', !(await t.js(`jQuery('#fluxo-ativo').is(':checked')`)) && (await t.js(`jQuery('#fluxo-ativo-rotulo').text()`)) === 'Desligado', {});
        await t.marcar(q('#fluxo-ativo'), true);
        await t.clicar(q('#btn-salvar-fluxo-basico')); await t.esperarToast(/sucesso|salvo/i, 10000);

        // ---------- Q. lista de fluxos mostra o fluxo e excluir pela tela
        await c.ir(t.BASE + '/fluxos'); await t.esp(1500);
        t.ok('a lista de fluxos mostra o fluxo recém-criado', (await t.js(`document.body.innerText.indexOf(${JSON.stringify(E.nome)}) >= 0`)), {});
        await c.ir(t.BASE + '/fluxo_basico'); await c.esperar(`jQuery('#btn-adicionar-plano').length>0`, 15000); await t.esp(800);
        await nomeFluxo('Descartável Zero');
        await secao('planos'); await t.clicar(q('#btn-adicionar-plano'));
        await t.digitar(plano(0, '.campo-plano-nome'), 'Plano descartável'); await t.digitar(plano(0, '.campo-plano-valor'), '9.9');
        await t.clicar(q('#btn-salvar-fluxo-basico')); await t.esperarToast(/sucesso|salvo/i, 10000);
        const idDesc = await t.js(`jQuery('#id-fluxo').val()`);
        await t.js(`window.confirm = function(){ return true; }; 1`);
        await t.clicar(q('#btn-excluir-fluxo-basico'));
        t.ok('excluir pelo botão da lixeira leva para a lista de fluxos', await c.esperar(`location.pathname.indexOf('fluxos')>=0`, 10000), { onde: await t.js('location.pathname') });
        const apiDesc = await t.api('obter_fluxo', '&id=' + idDesc);
        t.ok('o fluxo descartável não existe mais no banco', apiDesc.sucesso === false, { apiDesc });

        // ---------- R. celular
        await c.enviar('Emulation.setDeviceMetricsOverride', { width: 390, height: 900, deviceScaleFactor: 1, mobile: true });
        await c.ir(t.BASE + '/fluxo_basico?id=' + id); await c.esperar(`jQuery('#lista-planos .painel-plano').length>=3`, 20000); await t.esp(900);
        t.ok('celular: sem rolagem lateral e o cartão "Seção atual" aparece', !(await t.js(`document.documentElement.scrollWidth > innerWidth + 2`)) && (await t.js(`jQuery('#secao-atual').is(':visible')`)), {});
        await t.clicar(q('#secao-atual')); await t.esp(500);
        t.ok('celular: a folha de seções abre com as 11 seções', (await t.js(`jQuery('#folha-secoes-lista .folha-secoes-item').length`)) === 11, {});
        await t.captura('guiado_celular.png');

        t.ok('nenhum erro de JavaScript durante todo o uso do editor guiado', t.errosJs().length === 0, t.errosJs());
    } catch (e) { console.log('ERRO', e.message); t.ok('execução sem exceção', false, { erro: e.message }); }
    process.exitCode = (await t.fim()) ? 1 : 0;
})();
