// Teste de integração do remarketing em etapas, com banco de verdade (MariaDB temporário do WAMP), Telegram falso (HTTP local) e Pix simulado (tests/homologacao/sim.php).
// Nada toca servidor, banco de produção nem Telegram: tudo roda numa cópia do painel em C:/tmp/rm_int/app.
//
//   node tests/remarketing/integracao/rodar.js [--parar]
//
// O que faz: (1) sobe o MariaDB em C:/tmp/rm_int/db (porta 3392; reaproveita se já estiver no ar; --parar derruba no fim); (2) cria o banco com o schema ANTIGO (o do último commit)
// e dados antigos; (3) copia o painel, escreve o config.php e injeta o simulador; (4) liga o Telegram falso; (5) roda integracao.php, que migra o banco pelo "Atualizar Banco" de
// verdade e confere funções, cron e cliques das ofertas. Precisa do WAMP (MariaDB 11.3), do PHP no PATH e do Node 22+.
const fs = require('fs');
const net = require('net');
const path = require('path');
const { spawn, spawnSync } = require('child_process');
const { iniciar } = require('./telegram_falso.js');

const PROJ = path.resolve(__dirname, '../../..').replace(/\\/g, '/');
const RAIZ = 'C:/tmp/rm_int';
const APP = process.env.RM_APP_DIR || (RAIZ + '/app');   // RM_APP_DIR: outro caminho para a cópia do painel (o antivírus do Windows às vezes trava a criação de um arquivo num caminho já usado)
const SIM = RAIZ + '/sim';
const MB = 'C:/wamp64/bin/mariadb/mariadb11.3.2/bin';
const PORTA_DB = 3392;
const PORTA_TG = Number(process.env.RM_PORTA_TG) || 8099;   // RM_PORTA_TG: outra porta para o Telegram falso, se a 8099 estiver ocupada por outro programa
const BANCO = 'rm_teste';
const parar = process.argv.includes('--parar');

const esp = (ms) => new Promise(r => setTimeout(r, ms));
function portaAberta(porta) {
    return new Promise((resolve) => {
        const s = net.connect(porta, '127.0.0.1');
        s.on('connect', () => { s.destroy(); resolve(true); });
        s.on('error', () => resolve(false));
    });
}
function sql(texto, banco) {
    const args = ['-h127.0.0.1', '-P' + PORTA_DB, '-uroot'].concat(banco ? ['-D' + banco] : []);
    const r = spawnSync(MB + '/mariadb.exe', args, { input: texto, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
    if (r.status !== 0) throw new Error('SQL falhou: ' + (r.stderr || '').slice(0, 400));
    return r.stdout;
}
function troca(arquivo, de, para, obrigatorio = true) {
    const f = APP + '/' + arquivo;
    const c = fs.readFileSync(f, 'utf8');
    const n = typeof de === 'string' ? c.replace(de, () => para) : c.replace(de, para);
    if (n === c && obrigatorio) throw new Error('patch sem efeito em ' + arquivo + ': ' + String(de).slice(0, 60));
    fs.writeFileSync(f, n);
}

(async () => {
    fs.mkdirSync(RAIZ, { recursive: true });
    // ---------- banco ----------
    let iniciou_banco = false;
    if (!(await portaAberta(PORTA_DB))) {
        if (!fs.existsSync(RAIZ + '/db/my.ini')) {
            const r = spawnSync(MB + '/mariadb-install-db.exe', ['--datadir=' + RAIZ + '/db', '--password='], { encoding: 'utf8' });
            if (r.status !== 0) throw new Error('mariadb-install-db falhou: ' + r.stdout + r.stderr);
        }
        spawn(MB + '/mariadbd.exe', ['--defaults-file=' + RAIZ + '/db/my.ini', '--port=' + PORTA_DB, '--bind-address=127.0.0.1', '--console'], { detached: true, stdio: ['ignore', fs.openSync(RAIZ + '/mariadb.log', 'a'), fs.openSync(RAIZ + '/mariadb.log', 'a')] }).unref();
        for (let i = 0; i < 60 && !(await portaAberta(PORTA_DB)); i++) await esp(500);
        iniciou_banco = true;
    }
    if (!(await portaAberta(PORTA_DB))) throw new Error('MariaDB não subiu');
    sql(`DROP DATABASE IF EXISTS ${BANCO}; CREATE DATABASE ${BANCO} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`);
    // schema ANTIGO (o do último commit): a migração do "Atualizar Banco" é parte do teste
    const antigo = spawnSync('git', ['-C', PROJ, 'show', (process.env.RM_SCHEMA_ANTIGO_REF || 'HEAD') + ':deploy/schema.sql'], { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
    if (antigo.status !== 0) throw new Error('git show do schema antigo falhou: ' + antigo.stderr);
    sql(antigo.stdout, BANCO);
    fs.writeFileSync(RAIZ + '/schema_novo.sql', fs.readFileSync(PROJ + '/deploy/schema.sql', 'utf8'));

    // ---------- cópia do painel ----------
    fs.rmSync(APP, { recursive: true, force: true });
    const fora = ['.git', 'tests', 'anotacoes', 'uploads', 'storage', 'logs', 'resetar', 'seeds', 'node_modules'];
    fs.cpSync(PROJ, APP, { recursive: true, filter: (origem) => {
        const rel = path.relative(PROJ, origem).replace(/\\/g, '/');
        return !fora.some(p => rel === p || rel.startsWith(p + '/'));
    } });
    for (const d of ['uploads', 'storage', 'storage/cache', 'logs']) fs.mkdirSync(APP + '/' + d, { recursive: true });
    fs.writeFileSync(APP + '/config.php',
        `<?php\ndefine('BANCO_HOST', '127.0.0.1;port=${PORTA_DB}');\ndefine('BANCO_NOME', '${BANCO}');\ndefine('BANCO_USUARIO', 'root');\ndefine('BANCO_SENHA', '');\n` +
        `define('CHAVE_CRIPTOGRAFIA_GATEWAYS', '${'ab'.repeat(32)}');\ndefine('CHAVE_SECRETA_CRON', 'cron-teste');\n`);
    // mídias de teste (o remarketing só aceita caminho que existe dentro de uploads/)
    const midias = PROJ + '/tests/homologacao/ui/midia';
    fs.copyFileSync(midias + '/foto1.png', APP + '/uploads/remarketing_foto_a.png');
    fs.copyFileSync(midias + '/foto2.jpg', APP + '/uploads/remarketing_foto_b.jpg');
    fs.copyFileSync(midias + '/video1.mp4', APP + '/uploads/remarketing_video_a.mp4');

    // ---------- simulador (Telegram dos fluxos e Omega) ----------
    fs.rmSync(SIM, { recursive: true, force: true });
    fs.mkdirSync(SIM, { recursive: true });
    for (const f of ['sim.php', 'sim_eventos.php']) fs.copyFileSync(PROJ + '/tests/homologacao/' + f, SIM + '/' + f);
    const injeta = (nome) => [new RegExp('(function ' + nome + '\\s*\\([^)]*\\)\\s*:\\s*array\\s*\\{)'), '$1 return simTelegram(...func_get_args());'];
    troca('funcoes/fluxo_blocos.php', ...injeta('requisicaoTelegram'));
    troca('funcoes/omegapayments_banco.php', /(private function sendRequest\([^)]*\)\s*:\s*array\s*\{)/, '$1 return simOmegaApi($method, $uri, $body);');
    troca('webhook.php', "file_get_contents('php://input')", "($GLOBALS['SIM_INPUT'] ?? '')");
    troca('remarketing.php', "file_get_contents('php://input')", "($GLOBALS['SIM_INPUT'] ?? '')");
    // cron de remarketing: fala com o Telegram falso, lotes pequenos (para provar o avanço do OFFSET) e quase sem pausa entre os envios
    troca('cron/cron_remarketing.php', "https://api.telegram.org/bot", `http://127.0.0.1:${PORTA_TG}/bot`);
    troca('cron/cron_remarketing.php', 'const BATCH_SIZE    = 1000;', 'const BATCH_SIZE    = 7;');
    troca('cron/cron_remarketing.php', 'const BOT_DELAY_US  = 40000;', 'const BOT_DELAY_US  = 300;');
    troca('cron/cron_remarketing.php', 'const MAX_FALHAS_SEGUIDAS_BOT = 10;', 'const MAX_FALHAS_SEGUIDAS_BOT = 3;');

    // ---------- Telegram falso ----------
    const log_tg = RAIZ + '/telegram_falso.log';
    fs.writeFileSync(log_tg, '');
    const servidor = await iniciar(PORTA_TG, log_tg);

    // ---------- roda o teste ----------
    const env = Object.assign({}, process.env, { SIM_DIR: SIM, RM_APP: APP, RM_TG_LOG: log_tg, RM_TG_PORTA: String(PORTA_TG), RM_SIM: SIM, RM_PROJ: PROJ, RM_SCHEMA_NOVO: RAIZ + '/schema_novo.sql', RM_DB_PORTA: String(PORTA_DB), RM_DB_NOME: BANCO, RM_MB: MB });
    const filho = spawn('php', ['-d', 'auto_prepend_file=' + SIM + '/sim.php', '-d', 'display_errors=1', '-d', 'error_reporting=E_ALL', PROJ + '/tests/remarketing/integracao/integracao.php'], { env, stdio: ['ignore', 'pipe', 'pipe'] });
    let saida = '';
    filho.stdout.on('data', (d) => { saida += d; process.stdout.write(d); });
    filho.stderr.on('data', (d) => { saida += d; process.stdout.write(d); });
    const codigo = await new Promise((r) => filho.on('close', r));
    servidor.close();
    if (parar && iniciou_banco) spawnSync(MB + '/mariadb-admin.exe', ['-h127.0.0.1', '-P' + PORTA_DB, '-uroot', 'shutdown']);
    const falhou = /^FALHOU /m.test(saida) || codigo !== 0;
    console.log(falhou ? '\nINTEGRAÇÃO COM FALHAS (código ' + codigo + ')' : '\nINTEGRAÇÃO OK');
    process.exit(falhou ? 1 : 0);
})().catch((e) => { console.error('ERRO no harness:', e.stack || e); process.exit(2); });
