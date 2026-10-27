// Fluxo Guiado > Entrega (22/10/2026): plano, pack, Upsell e Downsell escolhem como entregar (grupo do Telegram, link ou nada; a oferta também
// "igual ao plano"). Cria um fluxo NOVO pela tela, como pessoa, confere os campos, a validação do link, salva, reabre e confere o que voltou.
// Variáveis: HOMOLOG_EMAIL, HOMOLOG_SENHA (usuário comum com um bot que tenha 2 grupos em bot_grupos) e HOMOLOG_URL (se não for a homologação).
//   HOMOLOG_URL=http://127.0.0.1:8091 HOMOLOG_EMAIL=... HOMOLOG_SENHA=... node tests/homologacao/ui/entrega_link.js
const { iniciar } = require('./zero_lib.js');

const LINK = 'https://area.exemplo.com.br/aulas_vip*2026/entrar?x=1&y=2';
const LINK2 = 'https://bonus.exemplo.com.br/oferta_extra';
const LINK3 = 'https://packs.exemplo.com.br/pack-a';
const G1 = '-1001000000001';
const G2 = '-1001000000002';

const q = (s) => `jQuery(${JSON.stringify(s)})`;
const plano = (i, s) => `jQuery('#lista-planos .painel-plano').eq(${i}).find(${JSON.stringify(s)})`;
const oferta = (tipo, s) => `jQuery('.painel[data-oferta="${tipo}"]').find(${JSON.stringify(s)})`;
const pack = (i, s) => `jQuery('#pk-lista .pk-item').eq(${i}).find(${JSON.stringify(s)})`;

(async () => {
    const t = await iniciar('entrega_link', { largura: 1500, altura: 1100 });
    const c = t.c;
    const opcoes = async (jq) => JSON.parse(await t.js(`JSON.stringify(${jq}.find('option').map(function(){ return [[this.value, jQuery(this).text()]]; }).get())`));
    const visivel = (jq) => t.js(`${jq}.length > 0 && !${jq}.prop('hidden') && ${jq}.is(':visible')`);
    const valor = (jq) => t.js(`${jq}.val()`);
    const secao = (nome) => t.clicar(q(`.basico-nav-item[data-secao="${nome}"]`));
    try {
        if (process.env.HOMOLOG_SESSAO) {
            // Ambiente local no Windows: o servidor embutido do PHP trava no session_regenerate_id() do login; entra com uma sessão criada antes.
            await c.enviar('Network.setCookie', { name: 'PHPSESSID', value: process.env.HOMOLOG_SESSAO, url: t.BASE });
            await c.ir(t.BASE + '/index');
            t.ok('login (sessão pronta)', (await t.js(`location.pathname.indexOf('login') === -1`)) === true, {});
        } else {
            t.ok('login', await t.entrar(), {});
        }
        await c.ir(t.BASE + '/fluxo_basico');
        t.ok('editor guiado (novo) abre', await c.esperar(`typeof jQuery!=='undefined' && jQuery('#btn-adicionar-plano').length>0`, 20000), {});
        await t.esp(1200);

        // ---------- PLANOS
        await secao('planos');
        await t.clicar(q('#btn-adicionar-plano'));
        await t.clicar(q('#btn-adicionar-plano'));
        const sel0 = plano(0, '.entrega-select');
        const o0 = await opcoes(sel0);
        t.ok('plano: o campo agora se chama "Entrega" (não mais "Grupo de entrega")', (await t.js(`${plano(0, '.campo-entrega > label')}.text()`)) === 'Entrega', await t.js(`${plano(0, '.campo-entrega > label')}.text()`));
        t.ok('plano: o campo Entrega tem 3 tipos: Nenhum, Acesso a grupo e Link por último (os grupos vão no select "Grupo para acesso", item 31)', o0.length === 3 && o0[0][0] === '' && /Nenhum/.test(o0[0][1]) && o0[1][0] === '__grupo__' && /Acesso a grupo/.test(o0[1][1]) && o0[2][0] === '__link__' && /Link/.test(o0[2][1]), o0);
        t.ok('plano novo: "Nenhum" escolhido, aviso de "Sem entrega" visível e campo do link escondido', (await valor(sel0)) === '' && (await visivel(plano(0, '.dica-sem-grupo'))) && !(await visivel(plano(0, '.entrega-link'))), {});
        await t.captura('entrega_1_plano_sem_entrega.png');
        await t.escolher(sel0, '__link__');
        t.ok('escolher Link: o campo do endereço aparece (com a dica), o aviso some e o foco vai para o campo', (await visivel(plano(0, '.entrega-link'))) && (await visivel(plano(0, '.dica-entrega-link'))) && !(await visivel(plano(0, '.dica-sem-grupo'))) && (await t.js(`document.activeElement === ${plano(0, '.entrega-link')}.get(0)`)), {});
        await t.escolher(sel0, '__grupo__');
        const oG = await opcoes(plano(0, '.entrega-grupo-select'));
        t.ok('Acesso a grupo: aparece o select "Grupo para acesso" com "Selecione um grupo…" e os 2 grupos do bot (por título)', (await visivel(plano(0, '.entrega-grupo-area'))) && oG.length === 3 && oG[0][0] === '' && [oG[1][0], oG[2][0]].sort().join() === [G1, G2].sort().join(), oG);
        await t.escolher(plano(0, '.entrega-grupo-select'), G1);
        t.ok('com o grupo escolhido: o campo do link some e o aviso continua escondido', !(await visivel(plano(0, '.entrega-link'))) && !(await visivel(plano(0, '.dica-sem-grupo'))), {});
        await t.escolher(sel0, '__link__');
        await t.digitar(plano(0, '.campo-plano-nome'), 'Mensal');
        await t.digitar(plano(0, '.campo-plano-valor'), '29.9');
        await t.digitar(plano(0, '.campo-plano-dias'), '30');
        await t.digitar(plano(1, '.campo-plano-nome'), 'Anual');
        await t.digitar(plano(1, '.campo-plano-valor'), '149');
        await t.digitar(plano(1, '.campo-plano-dias'), '365');
        await t.escolher(plano(1, '.entrega-select'), '__grupo__');
        await t.escolher(plano(1, '.entrega-grupo-select'), G1);

        // ---------- validação: link vazio e link sem https:// não deixam salvar
        await t.clicar(q('#btn-salvar-fluxo-basico'));
        let aviso = await t.esperarToast(/entrega por link/i);
        t.ok('Link escolhido e vazio: o salvar é recusado citando o plano', /entrega por link do plano "Mensal"/.test(aviso) && (await valor(q('#id-fluxo'))) === '', aviso);
        await t.digitar(plano(0, '.entrega-link'), 'meusite.com/area');
        await t.clicar(q('#btn-salvar-fluxo-basico'));
        aviso = await t.esperarToast(/entrega por link/i);
        t.ok('Link sem https:// também é recusado (e nada é salvo)', /https:\/\//.test(aviso) && (await valor(q('#id-fluxo'))) === '', aviso);
        await t.digitar(plano(0, '.entrega-link'), 'https://com espaco.com');
        await t.clicar(q('#btn-salvar-fluxo-basico'));
        aviso = await t.esperarToast(/entrega por link/i);
        t.ok('Link com espaço é recusado', /entrega por link/.test(aviso) && (await valor(q('#id-fluxo'))) === '', aviso);
        await t.digitar(plano(0, '.entrega-link'), LINK);

        // ---------- UPSELL
        await secao('upsell');
        await t.marcar(oferta('upsell', '.of-ativo'), true);
        const selU = oferta('upsell', '.entrega-select');
        const oU = await opcoes(selU);
        t.ok('Upsell: o campo Entrega existe, com "Igual à do plano oferecido" primeiro, depois Nenhum, Acesso a grupo e Link por último', oU.length === 4 && oU[0][0] === '__plano__' && /Igual/.test(oU[0][1]) && oU[1][0] === '' && oU[2][0] === '__grupo__' && oU[3][0] === '__link__', oU);
        t.ok('Upsell novo: já vem em "Igual à do plano oferecido" (nada muda para quem não mexer) com a dica explicando', (await valor(selU)) === '__plano__' && (await visivel(oferta('upsell', '.dica-entrega-plano'))) && !(await visivel(oferta('upsell', '.dica-sem-grupo'))) && !(await visivel(oferta('upsell', '.entrega-link'))), {});
        await t.escolher(oferta('upsell', '.of-plano'), await t.js(`${oferta('upsell', '.of-plano option')}.eq(3).val()`));   // o 2º plano (Anual): as 2 primeiras opções são "Escolha um plano" e "Criar um plano só para o Upsell"
        await t.captura('entrega_2_upsell_igual_ao_plano.png');
        await t.escolher(selU, '__link__');
        t.ok('Upsell com Link: campo do endereço aparece e a dica "igual ao plano" some', (await visivel(oferta('upsell', '.entrega-link'))) && !(await visivel(oferta('upsell', '.dica-entrega-plano'))), {});
        await t.digitar(oferta('upsell', '.entrega-link'), LINK2);
        await t.escolher(selU, '');
        t.ok('Upsell em "Nenhum": aparece o aviso de venda sem entrega', await visivel(oferta('upsell', '.dica-sem-grupo')), {});
        await t.escolher(selU, '__link__');

        // ---------- DOWNSELL
        await secao('downsell');
        await t.marcar(oferta('downsell', '.of-ativo'), true);
        const selD = oferta('downsell', '.entrega-select');
        const oD = await opcoes(selD);
        t.ok('Downsell: mesmo campo Entrega, igual ao do Upsell', oD.length === 4 && oD[0][0] === '__plano__' && oD[3][0] === '__link__' && (await valor(selD)) === '__plano__', oD);
        await t.marcar(`${oferta('downsell', '.of-planos-lista input')}.first()`, true);
        await t.escolher(selD, '__grupo__');
        await t.escolher(oferta('downsell', '.entrega-grupo-select'), G2);
        t.ok('Downsell com grupo próprio: sem campo de link e sem aviso', !(await visivel(oferta('downsell', '.entrega-link'))) && !(await visivel(oferta('downsell', '.dica-sem-grupo'))), {});

        // ---------- ORDER BUMP: sem entrega própria
        await secao('orderbump');
        t.ok('Order Bump continua sem o campo de entrega', (await t.js(`${oferta('order_bump', '.entrega-select')}.length`)) === 0, {});

        // ---------- PACKS
        await secao('packs');
        await t.marcar(q('#pk-ativo'), true);
        await t.clicar(q('#pk-adicionar'));
        const selP = pack(0, '.entrega-select');
        const oP = await opcoes(selP);
        t.ok('Pack: campo "Entrega do pack" com Nenhum, Acesso a grupo e Link', oP.length === 3 && oP[2][0] === '__link__' && (await t.js(`${pack(0, '.campo-entrega > label')}.text()`)) === 'Entrega do pack', oP);
        await t.digitar(pack(0, '.pk-nome'), 'Pack A');
        await t.digitar(pack(0, '.pk-preco'), '19.9');
        await t.escolher(selP, '__link__');
        await t.digitar(pack(0, '.entrega-link'), LINK3);
        await t.captura('entrega_3_pack_link.png');

        // ---------- SALVAR e REABRIR
        await secao('planos');
        t.ok('antes de salvar, o endereço do plano 1 continua no campo (trocar de seção não apaga)', (await valor(plano(0, '.entrega-link'))) === LINK, await valor(plano(0, '.entrega-link')));
        await t.clicar(q('#btn-salvar-fluxo-basico'));
        aviso = await t.esperarToast(/salvo/i);
        const id = await valor(q('#id-fluxo'));
        t.ok('com os links certos o fluxo salva', !!id && /salvo/i.test(aviso), { id, aviso });
        const salvo = (await t.api('obter_fluxo', '&id=' + id)).fluxo.dados_fluxograma;
        t.ok('salvo: plano 1 = link; plano 2 = grupo 1 (e sem link guardado)', salvo.planos[0].entrega_tipo === 'link' && salvo.planos[0].link_entrega === LINK && salvo.planos[0].id_grupo === '' && salvo.planos[1].entrega_tipo === 'grupo' && salvo.planos[1].id_grupo === G1 && salvo.planos[1].link_entrega === '', salvo.planos);
        t.ok('salvo: Upsell = link próprio', salvo.ofertas.upsell.entrega_tipo === 'link' && salvo.ofertas.upsell.link_entrega === LINK2, salvo.ofertas.upsell);
        t.ok('salvo: Downsell = grupo 2 próprio', salvo.ofertas.downsell.entrega_tipo === 'grupo' && salvo.ofertas.downsell.id_grupo === G2, salvo.ofertas.downsell);
        t.ok('salvo: Order Bump sem campos de entrega', salvo.ofertas.order_bump.entrega_tipo === undefined, salvo.ofertas.order_bump);
        t.ok('salvo: pack = link', salvo.packs.itens[0].entrega_tipo === 'link' && salvo.packs.itens[0].link_entrega === LINK3 && salvo.packs.itens[0].id_grupo === '', salvo.packs.itens[0]);

        await c.ir(t.BASE + '/fluxo_basico?id=' + id);
        t.ok('reabre o fluxo salvo', await c.esperar(`typeof jQuery!=='undefined' && jQuery('#lista-planos .painel-plano').length===2`, 20000), {});
        await t.esp(800);
        await secao('planos');
        t.ok('reaberto: plano 1 em Link com o endereço de volta; plano 2 no grupo 1', (await valor(plano(0, '.entrega-select'))) === '__link__' && (await valor(plano(0, '.entrega-link'))) === LINK && (await visivel(plano(0, '.entrega-link'))) && (await valor(plano(1, '.entrega-select'))) === '__grupo__' && (await valor(plano(1, '.entrega-grupo-select'))) === G1 && !(await visivel(plano(1, '.entrega-link'))), {});
        t.ok('reaberto: Upsell em Link e Downsell no grupo 2', (await valor(oferta('upsell', '.entrega-select'))) === '__link__' && (await valor(oferta('upsell', '.entrega-link'))) === LINK2 && (await valor(oferta('downsell', '.entrega-select'))) === '__grupo__' && (await valor(oferta('downsell', '.entrega-grupo-select'))) === G2, {});
        await secao('packs');
        t.ok('reaberto: pack em Link com o endereço', (await valor(pack(0, '.entrega-select'))) === '__link__' && (await valor(pack(0, '.entrega-link'))) === LINK3, {});
        t.ok('reaberto: nada aparece como "alterações não salvas"', (await t.js(`jQuery('#status-salvo').hasClass('sujo')`)) === false, {});

        // ---------- fluxo ANTIGO (sem entrega_tipo): plano mostra o grupo e as ofertas ficam em "Igual à do plano oferecido"
        const antigo = { nome: 'Fluxo antigo entrega', modo: 'basico', dados_fluxograma: {
            ativo: true, suporte: '', suporte_cor: '', cache_chat_id: '',
            boas_vindas: { mensagem: 'Oi', midia_tipo: 'none', midia_path: '', mostrar_cta: true, texto_cta: 'Ver Planos', cor_cta: '' },
            planos: [{ id: 'pa', nome: 'Antigo', valor: 29.9, dias_acesso: 30, unidade_acesso: 'dias', id_grupo: G2, cor: '' }, { id: 'pb', nome: 'Outro', valor: 59.9, dias_acesso: 30, unidade_acesso: 'dias', id_grupo: '', cor: '' }],
            ofertas: { upsell: { ativo: true, id_plano_destino: 'pa', desconto_percentual: 10, mensagem: '', texto_aceitar: '', cor_aceitar: '', texto_recusar: '', cor_recusar: '' }, downsell: { ativo: true, id_plano_destino: 'pb', planos_oferecidos: ['pb'], desconto_percentual: 5, mensagem: '' }, order_bump: { ativo: false } },
            previas: { ativo: false, midias: [] }, packs: { ativo: true, texto_botao: '', mensagem: '', itens: [{ id: 'k1', ativo: true, emoji: '📦', nome: 'Pack antigo', preco: 9.9, descricao: '', midia_tipo: 'none', midia_path: '', id_grupo: G1 }] },
            assinatura: {}, pagamentos: { msg_instrucoes: '', msg_confirmado: '', mostrar_copiar: true, mostrar_qrcode: true, qrcode_definido: true, mostrar_confirmar: true } } };
        const resp = JSON.parse(await t.js(`new Promise(function (res) { jQuery.ajax({ url: 'api.php?action=salvar_fluxo', method: 'POST', contentType: 'application/json', data: ${JSON.stringify(JSON.stringify(antigo))} }).always(function (a, b, c) { res(JSON.stringify(a && a.fluxo ? { id: a.fluxo.id } : { erro: (a && a.responseJSON) || b })); }); })`));
        t.ok('fluxo antigo criado para a conferência', !!resp.id, resp);
        await c.ir(t.BASE + '/fluxo_basico?id=' + resp.id);
        await c.esperar(`typeof jQuery!=='undefined' && jQuery('#lista-planos .painel-plano').length===2`, 20000);
        await t.esp(800);
        await secao('planos');
        t.ok('antigo: o plano com id_grupo continua mostrando o grupo, e o sem grupo mostra "Nenhum" com o aviso', (await valor(plano(0, '.entrega-select'))) === '__grupo__' && (await valor(plano(0, '.entrega-grupo-select'))) === G2 && (await valor(plano(1, '.entrega-select'))) === '' && (await visivel(plano(1, '.dica-sem-grupo'))), {});
        t.ok('antigo: Upsell e Downsell (sem entrega própria) abrem em "Igual à do plano oferecido"', (await valor(oferta('upsell', '.entrega-select'))) === '__plano__' && (await valor(oferta('downsell', '.entrega-select'))) === '__plano__', {});
        await secao('packs');
        t.ok('antigo: pack com id_grupo continua no grupo 1', (await valor(pack(0, '.entrega-select'))) === '__grupo__' && (await valor(pack(0, '.entrega-grupo-select'))) === G1, {});
        await t.clicar(q('#btn-salvar-fluxo-basico'));
        await t.esperarToast(/salvo/i);
        const regravado = (await t.api('obter_fluxo', '&id=' + resp.id)).fluxo.dados_fluxograma;
        t.ok('antigo, salvo de novo sem mexer: nada se perde (plano no grupo 2, ofertas "igual ao plano", pack no grupo 1)', regravado.planos[0].entrega_tipo === 'grupo' && regravado.planos[0].id_grupo === G2 && regravado.planos[1].entrega_tipo === 'nenhuma' && regravado.ofertas.upsell.entrega_tipo === 'plano' && regravado.ofertas.downsell.entrega_tipo === 'plano' && regravado.packs.itens[0].entrega_tipo === 'grupo' && regravado.packs.itens[0].id_grupo === G1, regravado);

        // ---------- celular
        await c.ir(t.BASE + '/fluxo_basico?id=' + id);
        await c.esperar(`typeof jQuery!=='undefined' && jQuery('#lista-planos .painel-plano').length===2`, 20000);
        await c.enviar('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 2, mobile: true });
        await t.esp(600);
        await secao('planos');
        t.ok('celular: sem rolagem horizontal com o campo de link aberto', (await t.js(`document.documentElement.scrollWidth <= window.innerWidth + 1`)), { sw: await t.js(`document.documentElement.scrollWidth`), iw: await t.js(`window.innerWidth`) });
        t.ok('celular: o campo do link ocupa a largura do formulário (não fica cortado)', (await t.js(`(function(){ var e = ${plano(0, '.entrega-link')}.get(0).getBoundingClientRect(); return e.left >= 0 && e.right <= window.innerWidth; })()`)), {});
        await t.js(`${plano(0, '.campo-entrega')}.get(0).scrollIntoView({block:'center'}); 1`);
        await t.esp(250);
        await t.captura('entrega_4_celular.png');

        const erros = t.errosJs();
        t.ok('nenhum erro de JavaScript na tela', erros.length === 0, erros);
    } catch (e) {
        t.ok('teste terminou sem exceção', false, { erro: e.message });
    }
    process.exitCode = (await t.fim()) ? 1 : 0;
})();
