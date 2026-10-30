// Teste de integração do chat dos leads (funcoes/chat.php, ajax/chat_*.php, tabela chat_mensagens), com banco de verdade (MariaDB temporário do WAMP) e Telegram falso (HTTP local).
// Nada toca servidor, banco de produção nem Telegram: tudo roda numa cópia do painel em C:/tmp/chat_int/app.
//
//   node tests/chat/rodar.js [--parar]
//
// (1) sobe o MariaDB em C:/tmp/chat_int/db (porta 3393; reaproveita se já estiver no ar; --parar derruba no fim); (2) cria o banco com o deploy/schema.sql ATUAL;
// (3) copia o painel, escreve o config.php, aponta o envio manual para o Telegram falso; (4) roda integracao.php (funções, endpoints, retenção, índice, desempenho);
// (5) apaga a tabela e roda a migração do servidor (deploy/vps/atualizar-banco.php) duas vezes: tem de recriar e ser idempotente.
// Precisa do WAMP (MariaDB 11.3), do PHP no PATH e do Node 22+.
const fs = require('fs');
const net = require('net');
const http = require('http');
const path = require('path');
const { spawn, spawnSync } = require('child_process');

const PROJ = path.resolve(__dirname, '../..').replace(/\\/g, '/');
const RAIZ = 'C:/tmp/chat_int';
const APP = process.env.CHAT_APP_DIR || (RAIZ + '/app');
const MB = 'C:/wamp64/bin/mariadb/mariadb11.3.2/bin';
const PORTA_DB = 3393;
const PORTA_TG = Number(process.env.CHAT_PORTA_TG) || 8097;
const BANCO = 'chat_teste';
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
function troca(arquivo, de, para) {
    const f = APP + '/' + arquivo;
    const c = fs.readFileSync(f, 'utf8');
    const n = c.replace(de, () => para);
    if (n === c) throw new Error('patch sem efeito em ' + arquivo + ': ' + String(de).slice(0, 60));
    fs.writeFileSync(f, n);
}

// Telegram falso: sendMessage devolve a mensagem enviada (como o de verdade); o chat 900403 "bloqueou o bot" (403).
function telegramFalso() {
    let n = 1000;
    const servidor = http.createServer((req, res) => {
        let corpo = '';
        req.on('data', (d) => { corpo += d; });
        req.on('end', () => {
            const p = new URLSearchParams(corpo);
            if (process.env.CHAT_DEPURAR) fs.appendFileSync(RAIZ + '/tg.log', new Date().toISOString() + ' ' + req.method + ' ' + req.url.replace(/bot[^/]+/, 'bot***') + ' chat=' + p.get('chat_id') + '\n');
            res.setHeader('Content-Type', 'application/json');
            if (p.get('chat_id') === '900403') {
                res.statusCode = 403;
                res.end(JSON.stringify({ ok: false, error_code: 403, description: 'Forbidden: bot was blocked by the user' }));
                return;
            }
            res.end(JSON.stringify({ ok: true, result: { message_id: ++n, chat: { id: Number(p.get('chat_id')), type: 'private' }, date: Math.floor(Date.now() / 1000), text: p.get('text') } }));
        });
    });
    return new Promise((resolve) => servidor.listen(PORTA_TG, '127.0.0.1', () => resolve(servidor)));
}

(async () => {
    fs.mkdirSync(RAIZ, { recursive: true });
    let iniciou_banco = false;
    if (!(await portaAberta(PORTA_DB))) {
        if (!fs.existsSync(RAIZ + '/db/my.ini')) {
            const r = spawnSync(MB + '/mariadb-install-db.exe', ['--datadir=' + RAIZ + '/db', '--password='], { encoding: 'utf8' });
            if (r.status !== 0) throw new Error('mariadb-install-db falhou: ' + r.stdout + r.stderr);
        }
        spawn(MB + '/mariadbd.exe', ['--defaults-file=' + RAIZ + '/db/my.ini', '--port=' + PORTA_DB, '--bind-address=127.0.0.1', '--console'],
            { detached: true, stdio: ['ignore', fs.openSync(RAIZ + '/mariadb.log', 'a'), fs.openSync(RAIZ + '/mariadb.log', 'a')] }).unref();
        for (let i = 0; i < 60 && !(await portaAberta(PORTA_DB)); i++) await esp(500);
        iniciou_banco = true;
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
        `define('CHAVE_CRIPTOGRAFIA_GATEWAYS', '${'ab'.repeat(32)}');\ndefine('CHAVE_SECRETA_CRON', 'cron-teste');\n`);
    troca('funcoes/chat.php', 'https://api.telegram.org/bot', `http://127.0.0.1:${PORTA_TG}/bot`);
    troca('ajax/_chat.php', "file_get_contents('php://input')", "($GLOBALS['SIM_INPUT'] ?? '')");
    for (const f of ['integracao.php', 'endpoint.php']) fs.copyFileSync(PROJ + '/tests/chat/' + f, APP + '/_teste/' + f);

    const tg = await telegramFalso();
    let falhou = false;
    try {
        // PHP em processo à parte e ASSÍNCRONO: o Telegram falso mora neste processo do Node e precisa responder enquanto o PHP espera (com spawnSync o laço de eventos
        // ficava parado e cada envio esperava os 10 s do curl)
        const codigo = await new Promise((resolve) => {
            const p = spawn('php', ['-d', 'display_errors=1', '-d', 'error_reporting=E_ALL', APP + '/_teste/integracao.php'], { stdio: ['ignore', 'pipe', 'pipe'] });
            p.stdout.on('data', (d) => process.stdout.write(d));
            p.stderr.on('data', (d) => process.stdout.write(d));
            const limite = setTimeout(() => { console.log('FALHOU tempo esgotado (10 min)'); p.kill(); }, 600000);
            // Windows: o sistema às vezes congela o php.exe no meio do teste (ver retomar.ps1). O vigia retoma e AVISA (nunca esconde).
            let vigia = null, vigiando = false;
            if (process.platform === 'win32') {
                vigia = setInterval(() => {
                    if (vigiando) return;
                    vigiando = true;
                    const v = spawn('powershell', ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', PROJ + '/tests/chat/retomar.ps1'], { stdio: ['ignore', 'pipe', 'ignore'] });
                    let saida = '';
                    v.stdout.on('data', (d) => { saida += d; });
                    v.on('close', () => { vigiando = false; if (saida.trim()) console.log('   (vigia) ' + saida.trim()); });
                }, 5000);
            }
            p.on('close', (c) => { clearTimeout(limite); if (vigia) clearInterval(vigia); resolve(c); });
        });
        if (codigo !== 0) falhou = true;

        // migração do servidor: recria a tabela apagada e é idempotente (a migração exige um administrador cadastrado, como no servidor)
        sql("INSERT INTO usuarios (nome, email, senha, perfil) VALUES ('Admin', 'admin@teste.local', 'x', 'admin');", BANCO);
        // As 3 fontes de esquema têm de produzir a MESMA tabela: schema.sql (já carregado no BANCO), migração do servidor (abaixo) e instalacao.php (banco à parte)
        const normalizar = (s) => s.replace(/AUTO_INCREMENT=\d+ /g, '').replace(/\r/g, '').trim();
        const criarTabela = (banco) => normalizar(sql('SHOW CREATE TABLE chat_mensagens;', banco));
        const da_schema = criarTabela(BANCO);
        const instalacao = fs.readFileSync(PROJ + '/instalacao.php', 'utf8').match(/CREATE TABLE IF NOT EXISTS chat_mensagens \([\s\S]*?\) ENGINE=InnoDB[^;]*;/);
        sql(`DROP DATABASE IF EXISTS ${BANCO}_inst; CREATE DATABASE ${BANCO}_inst CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; USE ${BANCO}_inst; CREATE TABLE bots (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB;`);
        if (instalacao) sql(instalacao[0], BANCO + '_inst');
        const da_instalacao = instalacao ? criarTabela(BANCO + '_inst') : '';
        sql(`DROP DATABASE IF EXISTS ${BANCO}_inst;`);
        sql('DROP TABLE chat_mensagens;', BANCO);
        const migrar = () => spawnSync('php', [APP + '/deploy/vps/atualizar-banco.php', APP], { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024, timeout: 300000 });
        const m1 = migrar();
        const existe1 = sql("SHOW TABLES LIKE 'chat_mensagens';", BANCO).trim() !== '';
        const m2 = migrar();
        const existe2 = sql("SHOW TABLES LIKE 'chat_mensagens';", BANCO).trim() !== '';
        const indices = sql("SHOW INDEX FROM chat_mensagens;", BANCO);
        const chk = (nome, c) => { console.log((c ? 'OK     ' : 'FALHOU ') + nome); if (!c) falhou = true; };
        chk('migração do servidor recria a tabela chat_mensagens apagada (código ' + m1.status + ')', m1.status === 0 && existe1);
        chk('migração rodada de novo (idempotente) continua ok (código ' + m2.status + ')', m2.status === 0 && existe2);
        chk('a tabela migrada tem os índices idx_chat_conversa e idx_chat_criado', /idx_chat_conversa/.test(indices) && /idx_chat_criado/.test(indices));
        const da_migracao = criarTabela(BANCO);
        chk('esquema: a tabela criada pela migração do servidor é idêntica à do deploy/schema.sql', da_migracao !== '' && da_migracao === da_schema);
        chk('esquema: a tabela criada pelo instalacao.php é idêntica à do deploy/schema.sql', da_instalacao !== '' && da_instalacao === da_schema);
    } finally {
        tg.close();
        if (parar && iniciou_banco) { spawnSync(MB + '/mariadb-admin.exe', ['-h127.0.0.1', '-P' + PORTA_DB, '-uroot', 'shutdown']); }
    }
    process.exit(falhou ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
