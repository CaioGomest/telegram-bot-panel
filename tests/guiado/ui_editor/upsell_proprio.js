// Upsell com plano próprio (item 33, 27/10/2026, pedido do dono): além de escolher um plano que já existe, o Upsell deixa criar um plano só dele (nome, valor e
// tempo de acesso), que não entra na lista de planos do bot. Chrome real, sem servidor (gerar.php monta o fluxo_basico.php de verdade; api_falsa.js simula a API).
//   node tests/guiado/ui_editor/upsell_proprio.js      (sai com código 1 se algo falhar; capturas na pasta temporária)
const fs = require('fs');
const os = require('os');
const { spawnSync } = require('child_process');
const RAIZ = require('path').resolve(__dirname, '../../..').replace(/\\/g, '/');
const { lancar } = require(RAIZ + '/tests/homologacao/ui/cdp.js');
const SAIDA = (os.tmpdir() + '/editor_guiado_upsell_proprio').replace(/\\/g, '/');
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
const GRUPOS = [
    { id_telegram: '-1001111111111', titulo: 'VIP A', nome_bot: 'bot_um' },
    { id_telegram: '-1002222222222', titulo: 'VIP B', nome_bot: 'bot_um' }
];
const OPCAO_PROPRIO = '＋ Criar um plano só para o Upsell';

(async () => {
    const c = await lancar();
    const js = (e) => c.avaliar(e);
    const q = (s) => `jQuery(${JSON.stringify(s)})`;
    async function abrir(fluxo, grupos) {
        await c.enviar('Emulation.setDeviceMetricsOverride', { width: 1500, height: 1100, deviceScaleFactor: 1, mobile: false });
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
    async function salvar() {
        await js(`window.__salvo = null; ${q('#toast')}.text('')`);
        await clicar('#btn-salvar-fluxo-basico');
        await esp(300);
        return JSON.parse(await js(`JSON.stringify(window.__salvo)`));
    }
    const toast = () => js(`${q('#toast')}.text()`);
    const textos = (sel) => async () => JSON.parse(await js(`JSON.stringify(${q(sel)}.map(function(){ return this.textContent.trim(); }).get())`));
    const visivel = (sel) => js(`${q(sel)}.length > 0 && !${q(sel)}.prop('hidden') && ${q(sel)}.is(':visible')`);
    const U = '.painel[data-oferta="upsell"]';
    const opcoes = textos(U + ' .of-plano option');
    const tiposEntrega = textos(U + ' .entrega-select option');
    const IGUAL = 'Igual à do plano oferecido';
    async function planoMensal() {
        await secao('planos');
        await clicar('#btn-adicionar-plano');
        await js(`${q('#lista-planos .painel-plano:first .campo-plano-nome')}.val('Mensal').trigger('input'); ${q('#lista-planos .painel-plano:first .campo-plano-valor')}.val(29.9).trigger('input');`);
    }

    try {
        // ============ fluxo novo: escolher o plano próprio ============
        await abrir(null, GRUPOS);
        await planoMensal();
        await secao('upsell');
        await clicar(U + ' .of-ativo');
        chk('o seletor "Plano oferecido" tem: "Escolha um plano", a opção de criar um plano só para o Upsell e depois os planos que já existem', JSON.stringify(await opcoes()) === JSON.stringify(['Escolha um plano', OPCAO_PROPRIO, 'Mensal — R$ 29,90']), await opcoes());
        chk('sem escolher o plano próprio, o formulário dele não aparece e o Desconto aparece', !(await visivel(U + ' .of-plano-proprio')) && (await visivel(U + ' .of-desconto')));
        chk('o Upsell começa com a entrega "Igual à do plano oferecido"', (await js(`${q(U + ' .entrega-select')}.val()`)) === '__plano__' && (await tiposEntrega())[0] === IGUAL, await tiposEntrega());

        await escolher(U + ' .of-plano', 'up_exclusivo');
        chk('escolher "Criar um plano só para o Upsell": aparecem Nome, Valor e Tempo de acesso (30 dias) e o foco vai para o nome', (await visivel(U + ' .of-proprio-nome')) && (await visivel(U + ' .of-proprio-valor')) && (await visivel(U + ' .of-proprio-dias')) && (await visivel(U + ' .of-proprio-unidade')) && (await js(`${q(U + ' .of-proprio-dias')}.val()`)) === '30' && (await js(`document.activeElement.className`)) === 'of-proprio-nome');
        chk('com plano próprio o Desconto some (o valor digitado já é o preço)', !(await visivel(U + ' .of-desconto')));
        // cartão igual ao da aba Planos (item 35)
        const CARTAO = U + ' .of-plano-proprio';
        const estilo = (sel) => js(`(function(){ var el = ${q(sel)}.get(0); var c = getComputedStyle(el); return JSON.stringify({ borda: c.borderTopWidth + ' ' + c.borderTopStyle, raio: c.borderTopLeftRadius, pad: c.paddingTop + ' ' + c.paddingLeft }); })()`);
        chk('o plano próprio é um cartão (classe painel-plano) com cabeçalho "Plano sem nome" e o resumo "R$ 0,00 · 30 dias"', (await js(`${q(CARTAO)}.hasClass('painel-plano')`)) === true && (await js(`${q(CARTAO + ' .plano-resumo-nome')}.text()`)) === 'Plano sem nome' && (await js(`${q(CARTAO + ' .plano-resumo-info')}.text()`)) === 'R$ 0,00 · 30 dias' && (await visivel(CARTAO + ' .plano-alternar')));
        const estiloProprio = await estilo(CARTAO);
        await js(`${q('.basico-nav-item[data-secao="planos"]')}.get(0).click()`);
        await esp(250);
        const estiloPlano = await estilo('#lista-planos .painel-plano:first');
        chk('a moldura (borda, canto arredondado e margem interna) é a mesma de um cartão da aba Planos', estiloProprio === estiloPlano && /1px solid/.test(estiloProprio), [estiloProprio, estiloPlano]);
        await secao('upsell');
        chk('Nome, Valor, Tempo de acesso e Entrega ficam DENTRO do cartão', (await js(`${q(CARTAO + ' .of-proprio-nome')}.length + ${q(CARTAO + ' .of-proprio-valor')}.length + ${q(CARTAO + ' .of-proprio-dias')}.length + ${q(CARTAO + ' .entrega-select')}.length`)) === 4);
        const pos = JSON.parse(await js(`JSON.stringify((function(){ var t = ${q(CARTAO + ' .of-proprio-dias')}.closest('.campo').get(0).getBoundingClientRect(), e = ${q(CARTAO + ' .campo-entrega')}.get(0).getBoundingClientRect(); return { t_topo: Math.round(t.top), e_topo: Math.round(e.top), t_dir: Math.round(t.right), e_esq: Math.round(e.left) }; })())`));
        chk('a Entrega fica ao lado do Tempo de acesso, na mesma linha (como nos planos da aba Planos)', Math.abs(pos.t_topo - pos.e_topo) <= 2 && pos.e_esq >= pos.t_dir, pos);
        await digitar(U + ' .of-proprio-nome', 'Pacote VIP');
        await digitar(U + ' .of-proprio-valor', 49.9);
        await digitar(U + ' .of-proprio-dias', 60);
        chk('o resumo do cabeçalho acompanha o que se digita ("Pacote VIP", "R$ 49,90 · 60 dias")', (await js(`${q(CARTAO + ' .plano-resumo-nome')}.text()`)) === 'Pacote VIP' && (await js(`${q(CARTAO + ' .plano-resumo-info')}.text()`)) === 'R$ 49,90 · 60 dias' && !(await js(`${q(CARTAO + ' .plano-resumo-nome')}.hasClass('sem-nome')`)));
        await digitar(U + ' .of-proprio-dias', 1);
        await escolher(U + ' .of-proprio-unidade', 'horas');
        chk('no singular: "1 hora"', (await js(`${q(CARTAO + ' .plano-resumo-info')}.text()`)) === 'R$ 49,90 · 1 hora');
        await clicar(CARTAO + ' .plano-topo');
        chk('clicar no cabeçalho recolhe o cartão (some o formulário, fica o resumo) e a seta avisa que está recolhido', (await js(`${q(CARTAO)}.hasClass('recolhido')`)) === true && !(await visivel(CARTAO + ' .plano-corpo')) && (await visivel(CARTAO + ' .plano-resumo-nome')) && (await js(`${q(CARTAO + ' .plano-alternar')}.attr('aria-expanded')`)) === 'false');
        await clicar(CARTAO + ' .plano-topo');
        chk('clicar de novo expande e o que foi digitado continua lá', !(await js(`${q(CARTAO)}.hasClass('recolhido')`)) && (await visivel(CARTAO + ' .plano-corpo')) && (await js(`${q(CARTAO + ' .of-proprio-nome')}.val()`)) === 'Pacote VIP' && (await js(`${q(CARTAO + ' .plano-alternar')}.attr('aria-expanded')`)) === 'true');
        await digitar(U + ' .of-proprio-nome', '');
        await digitar(U + ' .of-proprio-valor', 0);
        await digitar(U + ' .of-proprio-dias', 30);
        await escolher(U + ' .of-proprio-unidade', 'dias');
        chk('com plano próprio a entrega NÃO tem "Igual à do plano oferecido" (não há plano para copiar): só Nenhum, Acesso a grupo, Link e Mensagem, e abre em Nenhum com o aviso de que sem entrega ninguém recebe acesso',
            JSON.stringify(await tiposEntrega()) === JSON.stringify(['Nenhum (apenas venda)', '👥 Acesso a grupo', '🔗 Link (enviado ao cliente)', '💬 Mensagem (texto enviado ao cliente)']) && (await js(`${q(U + ' .entrega-select')}.val()`)) === '' && (await visivel(U + ' .dica-sem-grupo')), await tiposEntrega());
        await js(`${q(U)}.get(0).scrollIntoView({block:'start'})`);
        await esp(200);
        await c.captura(SAIDA + '/1_plano_proprio.png');

        // validações da tela
        let dados = await salvar();
        chk('plano próprio sem nome: não salva e diz o que falta', dados === null && /Dê um nome ao plano do Upsell/.test(await toast()), await toast());
        await digitar(U + ' .of-proprio-nome', 'Pacote VIP');
        dados = await salvar();
        chk('plano próprio com valor zero: não salva e cita o plano', dados === null && /O plano do Upsell "Pacote VIP" precisa ter valor maior que zero/.test(await toast()), await toast());
        await digitar(U + ' .of-proprio-valor', 1);
        dados = await salvar();
        chk('plano próprio abaixo do valor mínimo da Omega (R$ 1,50): não salva', dados === null && /valor mínimo é R\$ 1,50/.test(await toast()), await toast());

        // salvar certo, com entrega por grupo
        await digitar(U + ' .of-proprio-valor', 49.9);
        await digitar(U + ' .of-proprio-dias', 60);
        await escolher(U + ' .entrega-select', '__grupo__');
        await escolher(U + ' .entrega-grupo-select', '-1002222222222');
        dados = await salvar();
        const up = dados && dados.dados_fluxograma.ofertas.upsell;
        chk('salvar: o Upsell grava id_plano_destino "up_exclusivo" e o plano_proprio com nome, valor, 60 dias e a unidade', !!up && up.ativo === true && up.id_plano_destino === 'up_exclusivo' && !!up.plano_proprio && up.plano_proprio.nome === 'Pacote VIP' && up.plano_proprio.valor === 49.9 && up.plano_proprio.dias_acesso === 60 && up.plano_proprio.unidade_acesso === 'dias', up);
        chk('salvar: sem desconto, com a entrega por grupo do próprio Upsell', !!up && up.desconto_percentual === 0 && up.entrega_tipo === 'grupo' && up.id_grupo === '-1002222222222' && up.link_entrega === '', up);
        chk('o plano próprio NÃO entra na lista de planos do bot (continua só o Mensal)', !!dados && dados.dados_fluxograma.planos.length === 1 && dados.dados_fluxograma.planos[0].nome === 'Mensal', dados && dados.dados_fluxograma.planos);

        // continua escolhido quando a lista de planos muda
        await secao('planos');
        await clicar('#btn-adicionar-plano');
        await js(`${q('#lista-planos .painel-plano:eq(1) .campo-plano-nome')}.val('Anual').trigger('input'); ${q('#lista-planos .painel-plano:eq(1) .campo-plano-valor')}.val(99).trigger('input');`);
        await secao('upsell');
        chk('adicionar outro plano na seção Planos não tira o plano próprio do seletor do Upsell nem apaga o que foi digitado', (await js(`${q(U + ' .of-plano')}.val()`)) === 'up_exclusivo' && (await js(`${q(U + ' .of-proprio-nome')}.val()`)) === 'Pacote VIP' && (await opcoes()).length === 4, await opcoes());

        // ============ trocar para um plano que já existe e voltar ============
        await escolher(U + ' .of-plano', dados.dados_fluxograma.planos[0].id);
        chk('trocar para um plano que já existe: o cartão do plano próprio some e o Desconto volta', !(await visivel(U + ' .of-plano-proprio')) && (await visivel(U + ' .of-desconto')));
        chk('e a Entrega volta para o lugar de sempre, FORA do cartão (logo abaixo)', (await js(`${q(U + ' .entrega-select')}.closest('.of-plano-proprio').length`)) === 0 && (await visivel(U + ' .entrega-select')));
        chk('e a entrega escolhida (grupo VIP B) é mantida, agora com a opção "Igual à do plano oferecido" de volta', (await js(`${q(U + ' .entrega-select')}.val()`)) === '__grupo__' && (await js(`${q(U + ' .entrega-grupo-select')}.val()`)) === '-1002222222222' && (await tiposEntrega())[0] === IGUAL, await tiposEntrega());
        dados = await salvar();
        const up2 = dados && dados.dados_fluxograma.ofertas.upsell;
        chk('salvar com plano que já existe: grava o id do plano e NÃO leva plano_proprio', !!up2 && up2.id_plano_destino === dados.dados_fluxograma.planos[0].id && up2.plano_proprio === undefined, up2);
        await escolher(U + ' .of-plano', 'up_exclusivo');
        chk('voltar para o plano próprio: a Entrega volta para dentro do cartão e o resumo mostra "Pacote VIP"', (await js(`${q(U + ' .entrega-select')}.closest('.of-plano-proprio').length`)) === 1 && (await js(`${q(U + ' .of-plano-proprio .plano-resumo-nome')}.text()`)) === 'Pacote VIP');
        chk('voltar para o plano próprio: o que foi digitado (Pacote VIP, 49,9, 60 dias) continua lá', (await js(`${q(U + ' .of-proprio-nome')}.val()`)) === 'Pacote VIP' && (await js(`${q(U + ' .of-proprio-valor')}.val()`)) === '49.9' && (await js(`${q(U + ' .of-proprio-dias')}.val()`)) === '60');

        // "Igual ao plano" vira "Nenhum" ao ir para o plano próprio e volta
        await abrir(null, GRUPOS);
        await planoMensal();
        await secao('upsell');
        await clicar(U + ' .of-ativo');
        await escolher(U + ' .of-plano', 'up_exclusivo');
        chk('de "Igual à do plano oferecido" para o plano próprio a entrega vira "Nenhum"', (await js(`${q(U + ' .entrega-select')}.val()`)) === '');
        await escolher(U + ' .of-plano', '');
        chk('e ao voltar para "Escolha um plano" (sem escolher nada) a entrega volta a ser "Igual à do plano oferecido"', (await js(`${q(U + ' .entrega-select')}.val()`)) === '__plano__');

        // ============ fluxos salvos ============
        const base = { ativo: true, boas_vindas: {}, planos: [{ id: 'p1', nome: 'Mensal', valor: 29.9, dias_acesso: 30, unidade_acesso: 'dias', entrega_tipo: 'nenhuma', id_grupo: '', link_entrega: '' }] };
        await abrir({ id: 5, nome: 'Com upsell próprio', modo: 'basico', dados_fluxograma: Object.assign({}, base, { ofertas: { upsell: {
            ativo: true, id_plano_destino: 'up_exclusivo', desconto_percentual: 0, mensagem: 'Leve o pacote!',
            plano_proprio: { nome: 'Pacote VIP', valor: 49.9, dias_acesso: 12, unidade_acesso: 'horas' },
            entrega_tipo: 'link', id_grupo: '', link_entrega: 'https://exemplo.com/vip'
        } } }) }, GRUPOS);
        await secao('upsell');
        chk('reaberto: o seletor está em "Criar um plano só para o Upsell" com o formulário preenchido (Pacote VIP, 49,9, 12 horas) e sem o Desconto',
            (await js(`${q(U + ' .of-plano')}.val()`)) === 'up_exclusivo' && (await visivel(U + ' .of-plano-proprio')) && !(await visivel(U + ' .of-desconto')) &&
            (await js(`${q(U + ' .of-proprio-nome')}.val()`)) === 'Pacote VIP' && (await js(`${q(U + ' .of-proprio-valor')}.val()`)) === '49.9' && (await js(`${q(U + ' .of-proprio-dias')}.val()`)) === '12' && (await js(`${q(U + ' .of-proprio-unidade')}.val()`)) === 'horas');
        chk('reaberto: o cabeçalho do cartão mostra "Pacote VIP" e "R$ 49,90 · 12 horas", e a Entrega está dentro dele', (await js(`${q(U + ' .of-plano-proprio .plano-resumo-nome')}.text()`)) === 'Pacote VIP' && (await js(`${q(U + ' .of-plano-proprio .plano-resumo-info')}.text()`)) === 'R$ 49,90 · 12 horas' && (await js(`${q(U + ' .entrega-select')}.closest('.of-plano-proprio').length`)) === 1);
        chk('reaberto: a entrega por link do Upsell aparece com o endereço e sem a opção "Igual à do plano oferecido"', (await js(`${q(U + ' .entrega-select')}.val()`)) === '__link__' && (await js(`${q(U + ' .entrega-link')}.val()`)) === 'https://exemplo.com/vip' && !(await tiposEntrega()).includes(IGUAL), await tiposEntrega());
        dados = await salvar();
        const up3 = dados && dados.dados_fluxograma.ofertas.upsell;
        chk('salvar sem mexer: o plano próprio volta igual (nome, valor, 12 horas, link) e a mensagem da oferta não se perde', !!up3 && up3.id_plano_destino === 'up_exclusivo' && up3.plano_proprio.nome === 'Pacote VIP' && up3.plano_proprio.valor === 49.9 && up3.plano_proprio.dias_acesso === 12 && up3.plano_proprio.unidade_acesso === 'horas' && up3.entrega_tipo === 'link' && up3.link_entrega === 'https://exemplo.com/vip' && up3.mensagem === 'Leve o pacote!', up3);

        // Upsell salvo antes (plano que já existe) não muda
        await abrir({ id: 5, nome: 'Upsell antigo', modo: 'basico', dados_fluxograma: Object.assign({}, base, { ofertas: { upsell: { ativo: true, id_plano_destino: 'p1', desconto_percentual: 10, mensagem: 'x' } } }) }, GRUPOS);
        await secao('upsell');
        chk('Upsell salvo com um plano que já existe: abre nesse plano, com o Desconto (10), sem o cartão do plano próprio e com a Entrega fora de qualquer cartão', (await js(`${q(U + ' .of-plano')}.val()`)) === 'p1' && (await js(`${q(U + ' .of-desconto')}.val()`)) === '10' && (await visivel(U + ' .of-desconto')) && !(await visivel(U + ' .of-plano-proprio')) && (await js(`${q(U + ' .entrega-select')}.val()`)) === '__plano__' && (await js(`${q(U + ' .entrega-select')}.closest('.of-plano-proprio').length`)) === 0);
        dados = await salvar();
        const up4 = dados && dados.dados_fluxograma.ofertas.upsell;
        chk('e salvar esse Upsell grava o mesmo de antes (plano p1, 10% de desconto, sem plano_proprio)', !!up4 && up4.id_plano_destino === 'p1' && up4.desconto_percentual === 10 && up4.plano_proprio === undefined, up4);

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
