// Telegram falso para o teste de integração do remarketing: um servidor HTTP local que responde como a API do Telegram e RECUSA o que o Telegram real recusaria
// (texto vazio ou maior que 4096, legenda maior que 1024, HTML inválido, teclado inválido, callback_data > 64 bytes, foto sem arquivo nem file_id).
// Cada pedido vira uma linha JSON no arquivo de log. Regras de teste: token que começa com "BAD" = 401 Unauthorized; chat_id terminado em "403" = 403 "bot was blocked".
const http = require('http');
const fs = require('fs');
const { Readable } = require('stream');

function htmlValido(t) {
    const semTags = t.replace(/<\/?(b|strong|i|em|u|ins|s|strike|del|code|pre|tg-spoiler|a(\s+href="[^"]*")?)>/gi, '');
    if (/[<>]/.test(semTags) || /&(?!(lt|gt|amp|quot);)/.test(semTags)) return "Bad Request: can't parse entities: Unsupported start tag or unescaped character";
    for (const tag of ['b', 'i', 'u', 's', 'code', 'pre']) {
        const abre = (t.match(new RegExp('<' + tag + '>', 'gi')) || []).length;
        const fecha = (t.match(new RegExp('</' + tag + '>', 'gi')) || []).length;
        if (abre !== fecha) return "Bad Request: can't parse entities: unclosed tag " + tag;
    }
    return null;
}
function tecladoValido(rm) {
    if (rm === undefined || rm === null || rm === '') return null;
    let d;
    try { d = JSON.parse(rm); } catch (e) { return 'Bad Request: reply markup is not valid JSON'; }
    if (!d || !Array.isArray(d.inline_keyboard)) return 'Bad Request: inline_keyboard missing';
    for (const linha of d.inline_keyboard) {
        if (!Array.isArray(linha) || !linha.length) return 'Bad Request: empty keyboard row';
        for (const b of linha) {
            if (!String(b.text || '').trim()) return 'Bad Request: text buttons are unallowed or button text is empty';
            const acoes = ['url', 'callback_data', 'copy_text'].filter(k => k in b);
            if (acoes.length !== 1) return 'Bad Request: BUTTON_TYPE_INVALID';
            if ('callback_data' in b && (Buffer.byteLength(String(b.callback_data)) < 1 || Buffer.byteLength(String(b.callback_data)) > 64)) return 'Bad Request: BUTTON_DATA_INVALID (' + Buffer.byteLength(String(b.callback_data)) + ' bytes)';
            if ('url' in b && !/^(https?|tg):\/\/\S+$/i.test(String(b.url))) return 'Bad Request: BUTTON_URL_INVALID';
        }
    }
    return null;
}
function validar(metodo, p, arquivos) {
    if (['sendMessage', 'sendPhoto', 'sendVideo'].includes(metodo) && !String(p.chat_id || '').trim()) return 'Bad Request: chat_id is empty';
    if (metodo === 'sendMessage') {
        const t = String(p.text || '');
        if (!t.trim()) return 'Bad Request: message text is empty';
        if ([...t].length > 4096) return 'Bad Request: message is too long';
        if (p.parse_mode === 'HTML') { const e = htmlValido(t); if (e) return e; }
        return tecladoValido(p.reply_markup);
    }
    if (metodo === 'sendPhoto' || metodo === 'sendVideo') {
        const campo = metodo === 'sendPhoto' ? 'photo' : 'video';
        const temArquivo = arquivos[campo] && arquivos[campo].tamanho > 0;
        const temFileId = typeof p[campo] === 'string' && p[campo].startsWith('FID');
        if (!temArquivo && !temFileId) return 'Bad Request: wrong file identifier/HTTP URL specified';
        if (p.caption !== undefined && [...String(p.caption)].length > 1024) return 'Bad Request: message caption is too long';
        if (p.caption && p.parse_mode === 'HTML') { const e = htmlValido(String(p.caption)); if (e) return e; }
        return tecladoValido(p.reply_markup);
    }
    return null;
}

function iniciar(porta, arquivoLog) {
    let contador = 1000;
    const servidor = http.createServer(async (req, res) => {
        const resp = (codigo, corpo) => { res.writeHead(codigo, { 'Content-Type': 'application/json' }); res.end(JSON.stringify(corpo)); };
        const m = req.url.match(/^\/bot([^/]+)\/(\w+)$/);
        if (req.method === 'GET' && req.url === '/__vivo') return resp(200, { ok: true });
        if (!m) return resp(404, { ok: false, error_code: 404, description: 'Not Found' });
        const token = decodeURIComponent(m[1]);
        const metodo = m[2];
        const p = {};
        const arquivos = {};
        try {
            const dados = await new Request('http://x/', { method: 'POST', headers: req.headers, body: Readable.toWeb(req), duplex: 'half' }).formData();
            for (const [k, v] of dados.entries()) {
                if (typeof v === 'string') p[k] = v;
                else arquivos[k] = { nome: v.name, tamanho: v.size };
            }
        } catch (e) { /* corpo vazio */ }
        const linha = { t: new Date().toISOString(), token, metodo, chat_id: p.chat_id, parse_mode: p.parse_mode };
        if (p.text !== undefined) linha.text = p.text;
        if (p.caption !== undefined) linha.caption = p.caption;
        if (p.reply_markup) { try { linha.reply_markup = JSON.parse(p.reply_markup); } catch (e) { linha.reply_markup = p.reply_markup; } }
        if (Object.keys(arquivos).length) linha.arquivos = arquivos;
        for (const campo of ['photo', 'video']) if (typeof p[campo] === 'string') linha.file_id = p[campo];
        // chat terminado em "777" = resposta lenta (1,5 s): dá tempo de o teste apagar a campanha enquanto o envio está no ar. Avisa no log que o pedido chegou.
        if (String(p.chat_id || '').endsWith('777')) {
            fs.appendFileSync(arquivoLog, JSON.stringify({ recebido: true, chat_id: p.chat_id }) + '\n');
            await new Promise(r => setTimeout(r, 1500));
        }
        let codigo = 200;
        let corpo;
        if (token.startsWith('BAD')) {
            codigo = 401; corpo = { ok: false, error_code: 401, description: 'Unauthorized' };
        } else if (String(p.chat_id || '').endsWith('403')) {
            codigo = 403; corpo = { ok: false, error_code: 403, description: 'Forbidden: bot was blocked by the user' };
        } else {
            const erro = validar(metodo, p, arquivos);
            if (erro) { codigo = 400; corpo = { ok: false, error_code: 400, description: erro }; }
            else {
                const id = ++contador;
                // como o Telegram de verdade: o chat traz o tipo ("private" para conversa com pessoa), e a mensagem traz data e texto/legenda
                // (o histórico do chat dos leads, funcoes/chat.php, só guarda chat privado e lê tudo isso da resposta)
                const resultado = { message_id: id, date: Math.floor(Date.now() / 1000), chat: { id: Number(p.chat_id), type: Number(p.chat_id) > 0 ? 'private' : 'supergroup' } };
                if (p.text !== undefined) resultado.text = p.text;
                if (p.caption !== undefined) resultado.caption = p.caption;
                if (metodo === 'sendPhoto') resultado.photo = [{ file_id: 'FIDs' + id }, { file_id: 'FIDg' + id }];
                if (metodo === 'sendVideo') resultado.video = { file_id: 'FIDv' + id };
                corpo = { ok: true, result: resultado };
            }
        }
        linha.ok = corpo.ok;
        if (!corpo.ok) linha.erro = corpo.description;
        fs.appendFileSync(arquivoLog, JSON.stringify(linha) + '\n');
        resp(codigo, corpo);
    });
    return new Promise((resolve) => servidor.listen(porta, '127.0.0.1', () => resolve(servidor)));
}

module.exports = { iniciar };

if (require.main === module) {
    const porta = Number(process.argv[2]) || 8099;
    const log = process.argv[3] || 'telegram_falso.log';
    iniciar(porta, log).then(() => console.log('Telegram falso em http://127.0.0.1:' + porta + ' (log: ' + log + ')'));
}
