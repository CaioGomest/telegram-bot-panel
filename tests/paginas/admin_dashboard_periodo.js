// Admin > Visão Geral: os botões do período (Hoje / Ontem / 8 dias / 30 dias / Total) têm de trocar o período ao clicar.
// Regressão de 25/10/2026 ("quando clico nada acontece"): a tela perdeu o bloco das datas "De/Até" (.grupo-data-personalizada), mas o script do clique
// continuava a usá-lo; `date_group` virava null, o clique dava erro em `date_group.classList` DEPOIS do preventDefault e o filtro nunca era enviado.
// Chrome real, SEM servidor, banco nem login: monta uma página mínima com o MESMO trecho de HTML (seletor + formulário) e o MESMO script que estão no
// admin/dashboard.php (lidos do arquivo na hora), e clica em cada botão.
//   node tests/paginas/admin_dashboard_periodo.js     (sai com código 1 se algo falhar)
const fs = require('fs');
const os = require('os');
const { spawnSync } = require('child_process');
const RAIZ = require('path').resolve(__dirname, '../..').replace(/\\/g, '/');
const { lancar } = require(RAIZ + '/tests/homologacao/ui/cdp.js');

const PASTA = (os.tmpdir() + '/admin_dashboard_periodo').replace(/\\/g, '/');
fs.mkdirSync(PASTA, { recursive: true });
fs.writeFileSync(PASTA + '/gerar.php', `<?php
$raiz = ${JSON.stringify(RAIZ)};
$fonte = file_get_contents($raiz . '/admin/dashboard.php');
preg_match('/function montarUrlFiltroAdminDashboard.*?\\n}\\n/s', $fonte, $m_funcao);
eval($m_funcao[0]);
$ini = strpos($fonte, '<div class="seletor-periodo">');
$fim = strpos($fonte, '</form>', $ini) + strlen('</form>');
$periodo = '7dias'; $data_inicio = ''; $data_fim = '';
ob_start();
eval('?>' . substr($fonte, $ini, $fim - $ini));
$marcacao = ob_get_clean();
$si = strpos($fonte, "document.addEventListener('DOMContentLoaded', function() {");
$sf = strpos($fonte, '</script>', $si);
$script = preg_replace('/<\\?php.*?\\?>/s', 'null', substr($fonte, $si, $sf - $si));
file_put_contents(${JSON.stringify(PASTA)} . '/index.html', '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body><div class="acoes-cabecalho">' . $marcacao . '</div><script>' . $script . '</script></body></html>');
echo 'ok';
`);
const g = spawnSync('php', [PASTA + '/gerar.php'], { encoding: 'utf8' });
if (g.status !== 0 || g.stdout !== 'ok') { console.log('falhou ao gerar a página:\n' + g.stdout + g.stderr); process.exit(1); }

let ok = 0, falhas = 0;
const chk = (n, c, e) => { c ? ok++ : falhas++; console.log((c ? 'OK     ' : 'FALHOU ') + n + (c || e === undefined ? '' : '  -> ' + JSON.stringify(e))); };
const esp = (ms) => new Promise(r => setTimeout(r, ms));

(async () => {
    const c = await lancar();
    try {
        const URL = 'file:///' + PASTA + '/index.html';
        await c.ir(URL);
        chk('a página tem os 5 botões de período (Hoje, Ontem, 8 dias, 30 dias, Total), "8 dias" ativo', JSON.stringify(JSON.parse(await c.avaliar(`JSON.stringify([].map.call(document.querySelectorAll('.seletor-periodo a'), function(a){ return a.textContent.trim() + (a.classList.contains('ativo') ? '*' : ''); }))`))) === JSON.stringify(['Hoje', 'Ontem', '8 dias*', '30 dias', 'Total']));
        for (const [rotulo, periodo] of [['Hoje', 'hoje'], ['Ontem', 'ontem'], ['30 dias', '30dias'], ['Total', 'total'], ['8 dias', '7dias']]) {
            await c.ir(URL);
            const antes = await c.avaliar(`location.search`);
            await c.avaliar(`[].filter.call(document.querySelectorAll('.seletor-periodo a'), function(a){ return a.textContent.trim() === ${JSON.stringify(rotulo)}; })[0].click()`);
            await esp(700);
            const depois = await c.avaliar(`location.search`);
            chk(`clicar em "${rotulo}" muda a página para ?periodo=${periodo}`, new URLSearchParams(depois).get('periodo') === periodo, { antes, depois });
        }
        chk('sem erro de JavaScript durante os cliques', c.errosJs().length === 0, c.errosJs());
    } finally { await c.fechar(); }
    console.log('\n' + ok + ' ok, ' + falhas + ' falha(s)');
    process.exit(falhas ? 1 : 0);
})();
