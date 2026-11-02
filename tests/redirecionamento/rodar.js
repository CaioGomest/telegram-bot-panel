// Teste de integração da Proteção + Segmentação do Redirecionamento (l.php, funcoes/protecao_redirecionamento.php, salvar/migração), com banco de verdade.
//
//   node tests/redirecionamento/rodar.js [--parar]
//
// Sobe (ou reaproveita) o MariaDB temporário do WAMP em C:/tmp/rd_int/db (porta 3394), cria o banco com o deploy/schema.sql ATUAL, copia o painel para
// C:/tmp/rd_int/app e roda integracao.php (cada requisição a l.php é um processo php-cgi à parte). Nada toca servidor nem rede (o país vem do cache semeado).
const fs = require('fs');
const net = require('net');
const http = require('http');
const path = require('path');
const { spawn, spawnSync } = require('child_process');

const PROJ = path.resolve(__dirname, '../..').replace(/\\/g, '/');
const RAIZ = 'C:/tmp/rd_int';
const APP = RAIZ + '/app';
const MB = 'C:/wamp64/bin/mariadb/mariadb11.3.2/bin';
const PORTA_DB = 3394;
const PORTA_GEO = 8098;
const BANCO = 'rd_teste';
const parar = process.argv.includes('--parar');

const esp = (ms) => new Promise(r => setTimeout(r, ms));
const portaAberta = (porta) => new Promise((resolve) => {
    const s = net.connect(porta, '127.0.0.1');
    s.on('connect', () => { s.destroy(); resolve(true); });
    s.on('error', () => resolve(false));
});
function sql(texto, banco) {
    const args = ['-h127.0.0.1', '-P' + PORTA_DB, '-uroot'].concat(banco ? ['-D' + banco] : []);
    const r = spawnSync(MB + '/mariadb.exe', args, { input: texto, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
    if (r.status !== 0) throw new Error('SQL falhou: ' + (r.stderr || '').slice(0, 400));
    return r.stdout;
}

// Serviço de localização falso (o PHP local não valida o certificado do ipwho.is; o formato da resposta é o mesmo):
// 9.9.9.9 -> US, 3.3.3.3 -> BR, 7.7.7.7 -> falha do serviço (HTTP 500), 6.6.6.6 -> lento (3 s, estoura o prazo). /_hits devolve quantas consultas chegaram.
const consultas = {};
function geoFalso() {
    const tabela = { '9.9.9.9': 'US', '3.3.3.3': 'BR' };
    const servidor = http.createServer((req, res) => {
        if (req.url === '/_hits') { res.end(JSON.stringify(consultas)); return; }
        const ip = decodeURIComponent(req.url.split('?')[0].slice(1));
        consultas[ip] = (consultas[ip] || 0) + 1;
        if (ip === '7.7.7.7') { res.statusCode = 500; res.end('erro'); return; }
        if (ip === '6.6.6.6') { setTimeout(() => res.end(JSON.stringify({ success: true, country_code: 'US' })), 3000); return; }
        res.setHeader('Content-Type', 'application/json');
        res.end(JSON.stringify(tabela[ip] ? { success: true, country_code: tabela[ip] } : { success: false }));
    });
    return new Promise((resolve) => servidor.listen(PORTA_GEO, '127.0.0.1', () => resolve(servidor)));
}

(async () => {
    const geo = await geoFalso();
    fs.mkdirSync(RAIZ, { recursive: true });
    if (!(await portaAberta(PORTA_DB))) {
        if (!fs.existsSync(RAIZ + '/db/my.ini')) {
            const r = spawnSync(MB + '/mariadb-install-db.exe', ['--datadir=' + RAIZ + '/db', '--password='], { encoding: 'utf8' });
            if (r.status !== 0) throw new Error('mariadb-install-db falhou: ' + r.stdout + r.stderr);
        }
        spawn(MB + '/mariadbd.exe', ['--defaults-file=' + RAIZ + '/db/my.ini', '--port=' + PORTA_DB, '--bind-address=127.0.0.1', '--console'],
            { detached: true, stdio: ['ignore', fs.openSync(RAIZ + '/mariadb.log', 'a'), fs.openSync(RAIZ + '/mariadb.log', 'a')] }).unref();
        for (let i = 0; i < 60 && !(await portaAberta(PORTA_DB)); i++) await esp(500);
    }
    if (!(await portaAberta(PORTA_DB))) throw new Error('MariaDB não subiu');
    sql(`DROP DATABASE IF EXISTS ${BANCO}; CREATE DATABASE ${BANCO} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`);
    sql(fs.readFileSync(PROJ + '/deploy/schema.sql', 'utf8'), BANCO);

    fs.rmSync(APP, { recursive: true, force: true });
    const fora = ['.git', 'tests', 'anotacoes', 'uploads', 'storage', 'logs', 'resetar', 'seeds', 'node_modules'];
    fs.cpSync(PROJ, APP, { recursive: true, filter: (origem) => {
        const rel = path.relative(PROJ, origem).replace(/\\/g, '/');
        return !fora.some(p => rel === p || rel.startsWith(p + '/'));
    } });
    for (const d of ['uploads', 'storage', 'storage/cache', 'logs', '_teste']) fs.mkdirSync(APP + '/' + d, { recursive: true });
    fs.writeFileSync(APP + '/config.php',
        `<?php\ndefine('BANCO_HOST', '127.0.0.1;port=${PORTA_DB}');\ndefine('BANCO_NOME', '${BANCO}');\ndefine('BANCO_USUARIO', 'root');\ndefine('BANCO_SENHA', '');\n` +
        `define('CHAVE_CRIPTOGRAFIA_GATEWAYS', '${'ab'.repeat(32)}');\ndefine('CHAVE_SECRETA_CRON', 'cron-teste');\n` +
        `define('GEOIP_URL', 'http://127.0.0.1:${PORTA_GEO}');\n`);
    for (const f of ['integracao.php']) fs.copyFileSync(PROJ + '/tests/redirecionamento/' + f, APP + '/_teste/rd_' + f);

    const codigo = await new Promise((resolve) => {
        const p = spawn('php', ['-d', 'display_errors=1', '-d', 'error_reporting=E_ALL', APP + '/_teste/rd_integracao.php'], { stdio: ['ignore', 'pipe', 'pipe'] });
        p.stdout.on('data', (d) => process.stdout.write(d));
        p.stderr.on('data', (d) => process.stdout.write(d));
        const limite = setTimeout(() => { console.log('FALHOU tempo esgotado (5 min)'); p.kill(); }, 300000);
        // Windows: o sistema às vezes congela o php no meio do teste (ver retomar.ps1). O vigia retoma e AVISA (nunca esconde).
        let vigiando = false;
        const vigia = process.platform === 'win32' ? setInterval(() => {
            if (vigiando) return;
            vigiando = true;
            const v = spawn('powershell', ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', PROJ + '/tests/redirecionamento/retomar.ps1'], { stdio: ['ignore', 'pipe', 'ignore'] });
            let saida = '';
            v.stdout.on('data', (d) => { saida += d; });
            v.on('close', () => { vigiando = false; if (saida.trim()) console.log('   (vigia) ' + saida.trim()); });
        }, 4000) : null;
        p.on('close', (c) => { clearTimeout(limite); if (vigia) clearInterval(vigia); resolve(c); });
    });

    // As 3 fontes de esquema têm de produzir as MESMAS tabelas: schema.sql (já no BANCO), esquemaRedirecionamento() (instalador e Atualizar Banco)
    // e a migração de um banco antigo (colunas acrescentadas por ALTER).
    let falhou = codigo !== 0;
    const chk = (nome, c) => { console.log((c ? 'OK     ' : 'FALHOU ') + nome); if (!c) falhou = true; };
    const normalizar = (s) => s.replace(/AUTO_INCREMENT=\d+ /g, '').replace(/\r/g, '').trim();
    const tabela = (banco, nome) => normalizar(sql('SHOW CREATE TABLE ' + nome + ';', banco));
    const dump = spawnSync('php', [PROJ + '/tests/redirecionamento/esquema.php'], { encoding: 'utf8' });
    sql(`DROP DATABASE IF EXISTS ${BANCO}_esq; CREATE DATABASE ${BANCO}_esq CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`);
    sql('CREATE TABLE usuarios (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB; CREATE TABLE bots (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB;', BANCO + '_esq');
    for (const cmd of String(dump.stdout).split('\n;;\n')) { if (cmd.trim()) sql(cmd, BANCO + '_esq'); }
    for (const nome of ['redirecionadores', 'geo_ip_cache']) {
        chk('esquema: ' + nome + ' do esquemaRedirecionamento() é idêntica à do deploy/schema.sql', tabela(BANCO + '_esq', nome) === tabela(BANCO, nome));
    }
    sql(`DROP DATABASE IF EXISTS ${BANCO}_esq;`);
    const codigo_final = falhou ? 1 : 0;
    if (parar) {
        spawnSync(MB + '/mariadb-admin.exe', ['-h127.0.0.1', '-P' + PORTA_DB, '-uroot', 'shutdown']);
    }
    geo.close();
    process.exit(codigo_final);
})().catch((e) => { console.error('ERRO: ' + e.message); process.exit(2); });
