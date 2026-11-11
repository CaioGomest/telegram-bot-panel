// Tela de gateways no Chrome de verdade: as setas ↑/↓ de cada card reordenam a fila (Principal / Fallback N), desabilitam nas pontas, mandam a nova ordem
// ao servidor e cabem no card em desktop e celular. Usa o HTML real que a fase F de integracao.php gera (gateways_usuario_<id>.html), então rode antes:
//   node tests/gateways/integracao/rodar.js && node tests/gateways/integracao/ui_gateways.js
// (sai com código 1 se algo falhar; capturas na pasta temporária, caminho impresso no fim). Precisa do Chrome.
const fs = require('fs');
const os = require('os');
const RAIZ = require('path').resolve(__dirname, '../../..').replace(/\\/g, '/');
const { lancar } = require(RAIZ + '/tests/homologacao/ui/cdp.js');
const SIM = 'C:/tmp/gw_int/sim';
const arquivo = fs.existsSync(SIM) ? fs.readdirSync(SIM).filter(n => /^gateways_usuario_\d+\.html$/.test(n)).sort().pop() : null;
if (!arquivo) { console.log('Nenhum gateways_usuario_*.html em ' + SIM + ': rode tests/gateways/integracao/rodar.js antes.'); process.exit(1); }
const SAIDA = (os.tmpdir() + '/gateways_ui').replace(/\\/g, '/');
fs.mkdirSync(SAIDA, { recursive: true });

let ok = 0, falhas = 0;
const chk = (n, c, e) => { c ? ok++ : falhas++; console.log((c ? 'OK     ' : 'FALHOU ') + n + (c || e === undefined ? '' : '  -> ' + (typeof e === 'string' ? e : JSON.stringify(e)))); };
const esp = (ms) => new Promise(r => setTimeout(r, ms));

const ESTADO = `JSON.stringify((function () {
    return [...document.querySelectorAll('#gw-sortable .cartao-gateway-ativo')].map(function (c) {
        var b = c.getBoundingClientRect();
        return {
            id: c.dataset.gwId,
            nome: c.querySelector('.nome-gateway').textContent.trim(),
            selo: c.querySelector('.selo-prioridade').textContent.replace(/\\s+/g, ' ').trim(),
            principal: c.classList.contains('principal'),
            sobe_off: c.querySelector('[data-mover="-1"]').disabled,
            desce_off: c.querySelector('[data-mover="1"]').disabled,
            cabe: c.scrollWidth <= c.clientWidth + 1 && b.right <= window.innerWidth + 1 && b.left >= -1,
            seta_larg: Math.round(c.querySelector('[data-mover="1"]').getBoundingClientRect().width)
        };
    });
})())`;

(async () => {
    const c = await lancar();
    try {
        for (const [largura, altura, mobile, rotulo] of [[1440, 900, false, 'desktop'], [390, 844, true, 'celular']]) {
            await c.enviar('Emulation.setDeviceMetricsOverride', { width: largura, height: altura, deviceScaleFactor: 1, mobile });
            await c.ir('file:///' + SIM + '/' + arquivo);
            await esp(500);
            await c.avaliar(`window.__req = []; window.fetch = function (u, o) { window.__req.push({ url: u, dados: Array.from(o.body.entries()) }); return Promise.resolve({ ok: true }); }; true`);

            let e = JSON.parse(await c.avaliar(ESTADO));
            chk(`[${rotulo}] 3 cards ativos: NxGate, WiinPay, OmegaPayments, com Principal / Fallback 1 / Fallback 2`, e.length === 3 && /NxGate/.test(e[0].nome) && /WiinPay/.test(e[1].nome) && /Omega/.test(e[2].nome) && /Principal/.test(e[0].selo) && /Fallback 1/.test(e[1].selo) && /Fallback 2/.test(e[2].selo), e);
            chk(`[${rotulo}] o primeiro não sobe e o último não desce`, e[0].sobe_off && !e[0].desce_off && !e[2].sobe_off && e[2].desce_off && !e[1].sobe_off && !e[1].desce_off, e.map(x => [x.sobe_off, x.desce_off]));
            chk(`[${rotulo}] cada card (com selo, PIX e setas) cabe na tela sem estourar`, e.every(x => x.cabe), e.map(x => x.cabe));
            chk(`[${rotulo}] botões de seta com tamanho de toque razoável (>= 26 px)`, e.every(x => x.seta_larg >= 26), e.map(x => x.seta_larg));
            await c.captura(`${SAIDA}/gateways_${rotulo}_antes.png`);

            // desce o principal: NxGate vira Fallback 1, WiinPay vira Principal
            await c.avaliar(`document.querySelector('#gw-sortable .cartao-gateway-ativo [data-mover="1"]').click(); true`);
            await esp(200);
            e = JSON.parse(await c.avaliar(ESTADO));
            chk(`[${rotulo}] ↓ no principal: WiinPay vira Principal e NxGate Fallback 1`, /WiinPay/.test(e[0].nome) && /Principal/.test(e[0].selo) && /NxGate/.test(e[1].nome) && /Fallback 1/.test(e[1].selo) && e[0].principal && !e[1].principal, e);
            chk(`[${rotulo}] ↓ no principal: as setas das pontas acompanham (o novo primeiro não sobe)`, e[0].sobe_off && !e[1].sobe_off && e[2].desce_off, e.map(x => [x.sobe_off, x.desce_off]));
            let req = JSON.parse(await c.avaliar(`JSON.stringify(window.__req)`));
            const ordem = (req[0] ? req[0].dados.filter(d => /^ordem\[/.test(d[0])).map(d => d[1]) : []);
            chk(`[${rotulo}] a nova ordem foi enviada ao servidor (acao=reordenar_gateways, 3 ids na ordem da tela, com csrf)`, req.length === 1 && req[0].dados.some(d => d[0] === 'acao' && d[1] === 'reordenar_gateways') && req[0].dados.some(d => d[0] === 'csrf_token' && d[1].length > 10) && JSON.stringify(ordem) === JSON.stringify(e.map(x => x.id)), req);

            // sobe o último: Omega vira Fallback 1
            await c.avaliar(`document.querySelectorAll('#gw-sortable .cartao-gateway-ativo')[2].querySelector('[data-mover="-1"]').click(); true`);
            await esp(200);
            e = JSON.parse(await c.avaliar(ESTADO));
            chk(`[${rotulo}] ↑ no último: Omega sobe para Fallback 1 e NxGate vira Fallback 2`, /Omega/.test(e[1].nome) && /Fallback 1/.test(e[1].selo) && /NxGate/.test(e[2].nome) && /Fallback 2/.test(e[2].selo), e);
            // seta desabilitada não faz nada
            await c.avaliar(`document.querySelector('#gw-sortable .cartao-gateway-ativo [data-mover="-1"]').click(); true`);
            await esp(150);
            req = JSON.parse(await c.avaliar(`JSON.stringify(window.__req)`));
            chk(`[${rotulo}] clicar na seta desabilitada do primeiro card não envia nada`, req.length === 2, req.length);
            await c.captura(`${SAIDA}/gateways_${rotulo}_depois.png`);
            chk(`[${rotulo}] sem rolagem lateral na página`, !(await c.avaliar(`document.documentElement.scrollWidth > window.innerWidth + 1`)));
        }
        chk('sem erro de JavaScript', c.errosJs().filter(e => !/net::|Failed to load resource|fonts\.g/i.test(JSON.stringify(e))).length === 0, c.errosJs());
    } finally { await c.fechar(); }
    console.log('\n' + ok + ' ok, ' + falhas + ' falha(s)  (capturas em ' + SAIDA + ')');
    process.exit(falhas ? 1 : 0);
})();
