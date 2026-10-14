// Mini cliente Chrome DevTools Protocol (sem dependências): usa o WebSocket nativo do Node.
const { spawn } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');

const CHROME = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const PORT = 9333 + Math.floor(Math.random() * 300);

async function lancar() {
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'cdp-'));
    const proc = spawn(CHROME, ['--headless=new', '--disable-gpu', '--no-first-run', `--remote-debugging-port=${PORT}`, `--user-data-dir=${dir}`, '--window-size=1500,1000', 'about:blank'], { stdio: 'ignore' });
    let alvos = null;
    for (let i = 0; i < 60; i++) {
        try {
            alvos = await (await fetch(`http://127.0.0.1:${PORT}/json`)).json();
            if (alvos.find(a => a.type === 'page')) break;
        } catch (e) { /* ainda subindo */ }
        await new Promise(r => setTimeout(r, 250));
    }
    const alvo = alvos.find(a => a.type === 'page');
    const ws = new WebSocket(alvo.webSocketDebuggerUrl);
    await new Promise((res, rej) => { ws.onopen = res; ws.onerror = rej; });
    let id = 0;
    const pend = new Map();
    const eventos = [];
    ws.onmessage = (m) => {
        const d = JSON.parse(m.data);
        if (d.id && pend.has(d.id)) { pend.get(d.id)(d); pend.delete(d.id); }
        else if (d.method) eventos.push(d);
    };
    const enviar = (method, params = {}) => new Promise((res) => { const i = ++id; pend.set(i, res); ws.send(JSON.stringify({ id: i, method, params })); });
    await enviar('Page.enable'); await enviar('Runtime.enable'); await enviar('Network.enable');
    const api = {
        eventos,
        enviar,
        async ir(url) {
            await enviar('Page.navigate', { url });
            await api.esperarCarga();
        },
        async esperarCarga(ms = 15000) {
            const t0 = Date.now();
            while (Date.now() - t0 < ms) {
                const r = await api.avaliar('document.readyState');
                if (r === 'complete') { await new Promise(r2 => setTimeout(r2, 800)); return; }
                await new Promise(r2 => setTimeout(r2, 200));
            }
        },
        async avaliar(expr) {
            const r = await enviar('Runtime.evaluate', { expression: expr, awaitPromise: true, returnByValue: true });
            if (r.result && r.result.exceptionDetails) throw new Error('JS: ' + JSON.stringify(r.result.exceptionDetails.exception || r.result.exceptionDetails).slice(0, 400));
            return r.result && r.result.result ? r.result.result.value : undefined;
        },
        async esperar(expr, ms = 15000) {
            const t0 = Date.now();
            while (Date.now() - t0 < ms) {
                try { if (await api.avaliar(expr)) return true; } catch (e) { /* página trocando */ }
                await new Promise(r => setTimeout(r, 250));
            }
            return false;
        },
        errosJs() {
            return eventos.filter(e => (e.method === 'Runtime.exceptionThrown') || (e.method === 'Runtime.consoleAPICalled' && e.params.type === 'error'))
                .map(e => e.method === 'Runtime.exceptionThrown' ? (e.params.exceptionDetails.exception?.description || e.params.exceptionDetails.text) : e.params.args.map(a => a.value || a.description).join(' '));
        },
        async captura(arquivo) {
            const r = await enviar('Page.captureScreenshot', { format: 'png' });
            fs.writeFileSync(arquivo, Buffer.from(r.result.data, 'base64'));
        },
        async fechar() { try { ws.close(); } catch (e) {} proc.kill(); },
    };
    return api;
}
module.exports = { lancar };
