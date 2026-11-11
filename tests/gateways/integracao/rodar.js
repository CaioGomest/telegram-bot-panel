// Integração dos gateways (OmegaPayments, NxGate, WiinPay), do fallback e dos webhooks, com banco de verdade (MariaDB temporário do WAMP),
// Telegram simulado e APIs dos gateways simuladas. Nada toca servidor, banco de produção, Telegram nem gateway de verdade.
//
//   node tests/gateways/integracao/rodar.js [--parar]
//
// Sobe o MariaDB em C:/tmp/gw_int/db (porta 3393; reaproveita se já estiver no ar; --parar derruba no fim), cria o banco com o deploy/schema.sql do
// diretório de trabalho, copia o painel para C:/tmp/gw_int/app com config.php local e injeta os simuladores, e roda integracao.php.
// Precisa do WAMP (MariaDB 11.3), do PHP no PATH e do Node 22+.
const fs = require('fs');
const net = require('net');
const path = require('path');
const { spawn, spawnSync } = require('child_process');

const PROJ = path.resolve(__dirname, '../../..').replace(/\\/g, '/');
const RAIZ = 'C:/tmp/gw_int';
const APP = process.env.GW_APP_DIR || (RAIZ + '/app');
const SIM = RAIZ + '/sim';
const MB = 'C:/wamp64/bin/mariadb/mariadb11.3.2/bin';
const PORTA_DB = 3393;
const BANCO = 'gw_teste';
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
    if (!fs.existsSync(f)) { if (obrigatorio) throw new Error('arquivo não existe na cópia: ' + arquivo); return; }
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
    sql(fs.readFileSync(PROJ + '/deploy/schema.sql', 'utf8'), BANCO);

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

    // gateway FALSO de teste (prova o fallback sem depender de gateway de verdade)
    fs.copyFileSync(__dirname + '/gateway_falso.php', APP + '/funcoes/gateways/falso.php');
    fs.copyFileSync(__dirname + '/gateway_falso.meta.php', APP + '/funcoes/gateways/falso.meta.php');

    // ---------- simuladores ----------
    fs.rmSync(SIM, { recursive: true, force: true });
    fs.mkdirSync(SIM, { recursive: true });
    fs.copyFileSync(PROJ + '/tests/homologacao/sim.php', SIM + '/sim.php');
    fs.copyFileSync(PROJ + '/tests/homologacao/sim_eventos.php', SIM + '/sim_eventos.php');
    fs.copyFileSync(__dirname + '/prepend.php', SIM + '/prepend.php');
    if (fs.existsSync(__dirname + '/sim_gateways.php')) fs.copyFileSync(__dirname + '/sim_gateways.php', SIM + '/sim_gateways.php');
    const injeta = (nome) => [new RegExp('(function ' + nome + '\\s*\\([^)]*\\)\\s*:\\s*array\\s*\\{)'), '$1 return simTelegram(...func_get_args());'];
    troca('funcoes/fluxo_blocos.php', ...injeta('requisicaoTelegram'));
    troca('funcoes/pagamento_confirmado.php', ...injeta('requisicaoTelegramGateway'));
    troca('funcoes/omegapayments_banco.php', /(private function sendRequest\([^)]*\)\s*:\s*array\s*\{)/, '$1 return simOmegaApi($method, $uri, $body);');
    // HTTP dos gateways novos (existe a partir da fase 4): a função única de requisição vira o simulador
    troca('funcoes/gateways/_http.php', /(function gatewayRequisicaoHttp\([^)]*\)\s*:\s*array\s*\{)/, '$1 return simGatewayHttp(...func_get_args());', false);
    for (const f of fs.readdirSync(APP).filter(n => /^webhook_.*\.php$/.test(n))) {
        troca(f, "file_get_contents('php://input')", "($GLOBALS['SIM_INPUT'] ?? '')", false);
    }
    troca('cron/cron_renovacao.php', ...injeta('telegramRequest'));
    troca('cron/cron_verificar_pix.php', ...injeta('requisicaoTelegramLocal'));
    // o cron de verificação consulta de verdade a cada minuto na cópia (sem a trava de 5 min da Omega)
    troca('cron/cron_verificar_pix.php', "((int)date('i')) % 5 !== 0", 'false', false);

    // ---------- roda o teste ----------
    const env = Object.assign({}, process.env, { SIM_DIR: SIM, GW_APP: APP, GW_DB_PORTA: String(PORTA_DB), GW_DB_NOME: BANCO, GW_PROJ: PROJ });
    const filho = spawn('php', ['-d', 'auto_prepend_file=' + SIM + '/prepend.php', '-d', 'display_errors=1', '-d', 'error_reporting=E_ALL', PROJ + '/tests/gateways/integracao/integracao.php'], { env, stdio: ['ignore', 'pipe', 'pipe'] });
    let saida = '';
    filho.stdout.on('data', (d) => { saida += d; process.stdout.write(d); });
    filho.stderr.on('data', (d) => { saida += d; process.stdout.write(d); });
    // Windows: o sistema às vezes congela o php.exe no meio do teste (ver retomar.ps1). O vigia retoma e AVISA (nunca esconde).
    let vigiando = false;
    const vigia = process.platform === 'win32' ? setInterval(() => {
        if (vigiando) return;
        vigiando = true;
        const v = spawn('powershell', ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', __dirname + '/retomar.ps1'], { stdio: ['ignore', 'pipe', 'ignore'] });
        let saida_vigia = '';
        v.stdout.on('data', (d) => { saida_vigia += d; });
        v.on('close', () => { vigiando = false; if (saida_vigia.trim()) console.log('   (vigia) ' + saida_vigia.trim()); });
    }, 15000) : null;
    const codigo = await new Promise((r) => filho.on('close', r));
    if (vigia) clearInterval(vigia);
    if (parar && iniciou_banco) spawnSync(MB + '/mariadb-admin.exe', ['-h127.0.0.1', '-P' + PORTA_DB, '-uroot', 'shutdown']);
    const falhou = /^FALHOU /m.test(saida) || codigo !== 0;
    console.log(falhou ? '\nINTEGRAÇÃO COM FALHAS (código ' + codigo + ')' : '\nINTEGRAÇÃO OK');
    process.exit(falhou ? 1 : 0);
})().catch((e) => { console.error('ERRO no harness:', e.stack || e); process.exit(2); });
