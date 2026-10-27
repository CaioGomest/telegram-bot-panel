// A página "Em manutenção" no Chrome real (sem servidor): gera o HTML com funcoes/manutencao.php, abre em desktop, celular e tema claro,
// e confere que cabe na janela (sem rolagem lateral), que o texto e a previsão aparecem e que o botão "Tentar de novo" existe.
//   node tests/manutencao/ui_pagina.js     (sai com código 1 se algo falhar; capturas na pasta temporária, caminho impresso no fim)
const fs = require('fs');
const os = require('os');
const { spawnSync } = require('child_process');
const RAIZ = require('path').resolve(__dirname, '../..').replace(/\\/g, '/');
const { lancar } = require(RAIZ + '/tests/homologacao/ui/cdp.js');

const SAIDA = (os.tmpdir() + '/manutencao_pagina').replace(/\\/g, '/');
fs.mkdirSync(SAIDA, { recursive: true });
const gerador = SAIDA + '/gerar.php';
fs.writeFileSync(gerador, `<?php
date_default_timezone_set('America/Sao_Paulo');
require ${JSON.stringify(RAIZ + '/funcoes/manutencao.php')};
$base = ['ativo' => true, 'modo' => 'painel', 'mensagem' => '', 'volta_em' => 0, 'desde' => time(), 'segredo' => 'x', 'por' => ''];
file_put_contents(${JSON.stringify(SAIDA)} . '/padrao.html', manutencaoPagina($base, time()));
file_put_contents(${JSON.stringify(SAIDA)} . '/completa.html', manutencaoPagina(['mensagem' => "Estamos atualizando o banco de dados para deixar o painel mais rápido.\\nObrigado pela paciência!", 'volta_em' => time() + 1800] + $base, time()));
file_put_contents(${JSON.stringify(SAIDA)} . '/longa.html', manutencaoPagina(['mensagem' => str_repeat('Palavra_muito_longa_sem_espaco_', 12)] + $base, time()));
`);
const g = spawnSync('php', [gerador], { encoding: 'utf8' });
if (g.status !== 0) { console.log('falhou ao gerar:\n' + g.stdout + g.stderr); process.exit(1); }

let ok = 0, falhas = 0;
const chk = (n, c, e) => { c ? ok++ : falhas++; console.log((c ? 'OK     ' : 'FALHOU ') + n + (c || e === undefined ? '' : '  -> ' + JSON.stringify(e))); };
const esp = (ms) => new Promise(r => setTimeout(r, ms));

(async () => {
    const c = await lancar();
    try {
        const casos = [
            ['padrao', 1440, 900, false, 'desktop'],
            ['completa', 1440, 900, false, 'desktop'],
            ['completa', 390, 800, true, 'celular'],
            ['longa', 360, 700, true, 'celular estreito, palavra longa'],
        ];
        for (const [arquivo, largura, altura, mobile, rotulo] of casos) {
            await c.enviar('Emulation.setDeviceMetricsOverride', { width: largura, height: altura, deviceScaleFactor: 1, mobile });
            await c.ir('file:///' + SAIDA + '/' + arquivo + '.html');
            await esp(300);
            const m = JSON.parse(await c.avaliar(`JSON.stringify((function(){ var cart = document.querySelector('.cartao').getBoundingClientRect(); return { rolagem_lateral: document.documentElement.scrollWidth > window.innerWidth, cart_esq: Math.round(cart.left), cart_dir: Math.round(cart.right), janela: window.innerWidth, titulo: document.querySelector('h1').textContent, botao: !!document.querySelector('a.botao'), previsao: !!document.querySelector('.previsao'), msg: document.querySelector('.msg').textContent.slice(0, 40) }; })())`));
            chk(`${arquivo} (${rotulo}): cabe na janela, sem rolagem lateral`, !m.rolagem_lateral && m.cart_esq >= 0 && m.cart_dir <= m.janela, m);
            chk(`${arquivo} (${rotulo}): título "Em manutenção", mensagem e botão "Tentar de novo"`, m.titulo === 'Em manutenção' && m.msg.length > 0 && m.botao, m);
            if (arquivo === 'completa') chk(`completa (${rotulo}): a previsão de volta aparece`, m.previsao, m);
            await c.captura(`${SAIDA}/${arquivo}_${largura}.png`);
        }
        // tema claro
        await c.enviar('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-color-scheme', value: 'light' }] });
        await c.enviar('Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });
        await c.ir('file:///' + SAIDA + '/completa.html');
        await esp(300);
        const fundo = await c.avaliar(`getComputedStyle(document.body).backgroundColor`);
        chk('tema claro do sistema: fundo claro', fundo === 'rgb(244, 245, 248)', fundo);
        await c.captura(`${SAIDA}/completa_claro.png`);
        await c.enviar('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-color-scheme', value: 'dark' }] });
        await c.ir('file:///' + SAIDA + '/completa.html');
        await esp(300);
        const fundo_escuro = await c.avaliar(`getComputedStyle(document.body).backgroundColor`);
        chk('tema escuro do sistema: fundo escuro', fundo_escuro === 'rgb(11, 13, 18)', fundo_escuro);
        await c.captura(`${SAIDA}/completa_escuro.png`);
        chk('sem erro de JavaScript', c.errosJs().length === 0, c.errosJs());
    } finally { await c.fechar(); }
    console.log('\n' + ok + ' ok, ' + falhas + ' falha(s)  (capturas em ' + SAIDA + ')');
    process.exit(falhas ? 1 : 0);
})();
