// Tira capturas de tela das páginas renderizadas pela integração (detalhes da campanha com dados reais do banco temporário).
//   node tests/remarketing/integracao/capturas.js     (roda depois de rodar.js; salva PNGs em C:/tmp/rm_int)
const { lancar } = require(require('path').resolve(__dirname, '../../homologacao/ui/cdp.js'));
(async () => {
    const c = await lancar();
    try {
        await c.enviar('Emulation.setDeviceMetricsOverride', { width: 1500, height: 1700, deviceScaleFactor: 1, mobile: false });
        for (const nome of ['detalhes_c1', 'detalhes_da']) {
            const arq = 'C:/tmp/rm_int/' + nome + '.html';
            if (!require('fs').existsSync(arq)) { console.log('sem ' + arq); continue; }
            await c.ir('file:///' + arq);
            await new Promise(r => setTimeout(r, 600));
            await c.captura('C:/tmp/rm_int/' + nome + '.png');
            console.log('ok ' + nome);
        }
    } finally { await c.fechar(); }
})();
