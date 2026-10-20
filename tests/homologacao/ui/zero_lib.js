// Ferramentas dos testes "do zero" dos editores de fluxo (Guiado e Visual), no Chrome de verdade.
// Digita como pessoa (foco, texto, Tab -> evento "change" nativo), escolhe selects, marca caixas, envia arquivos e confere o resultado.
const fs = require('fs');
const path = require('path');
const { lancar } = require('./cdp.js');

const BASE = process.env.HOMOLOG_URL || 'https://telegram.stackcode.com.br';
const MIDIA = process.env.HOMOLOG_MIDIA || path.join(__dirname, 'midia');
const SAIDA = process.env.HOMOLOG_SAIDA || path.join(__dirname, 'saida');
const EMAIL = process.env.HOMOLOG_EMAIL || 'teste-homolog-fluxos@example.invalid';
const esp = (ms) => new Promise(r => setTimeout(r, ms));

async function iniciar(nome, opcoes = {}) {
    fs.mkdirSync(SAIDA, { recursive: true });
    const c = await lancar();
    if (opcoes.largura) await c.enviar('Emulation.setDeviceMetricsOverride', { width: opcoes.largura, height: opcoes.altura || 1000, deviceScaleFactor: 1, mobile: !!opcoes.mobile });
    const resultados = [];
    const t = {
        c, BASE, MIDIA, SAIDA, esp, resultados,
        ok(rotulo, cond, extra) {
            resultados.push({ ok: !!cond, rotulo, extra: cond ? undefined : extra });
            console.log('  ' + (cond ? 'PASS' : 'FAIL') + ' | ' + rotulo + (cond ? '' : ' | ' + JSON.stringify(extra)));
            return !!cond;
        },
        info(msg) { console.log('  .. ' + msg); },
        js: (expr) => c.avaliar(expr),

        async entrar() {
            await c.ir(BASE + '/login');
            await c.esperar(`!!document.getElementById('email')`, 15000);
            await c.avaliar(`document.getElementById('email').value=${JSON.stringify(EMAIL)}; document.getElementById('senha').value=${JSON.stringify(process.env.HOMOLOG_SENHA || '')}; document.querySelector('form[action="login"]').submit(); 1`);
            const entrou = await c.esperar(`location.pathname.indexOf('login')===-1`, 25000);
            await c.esperarCarga();
            return entrou;
        },

        // Foco + seleciona tudo + digita (ou apaga) + Tab: dispara "input" e "change" de verdade, como um usuário.
        async digitar(jq, texto, confirmar = 'Tab') {
            const r = await c.avaliar(`(function(){ var el = ${jq}.get(0); if (!el) return 'sem elemento'; el.scrollIntoView({block:'center'}); el.focus(); if (el.select) el.select(); return 'ok'; })()`);
            if (r !== 'ok') throw new Error('digitar: ' + r + ' em ' + jq);
            if (texto === '' || texto === null || texto === undefined) {
                await c.enviar('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Backspace', code: 'Backspace', windowsVirtualKeyCode: 8 });
                await c.enviar('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Backspace', code: 'Backspace', windowsVirtualKeyCode: 8 });
            } else {
                await c.enviar('Input.insertText', { text: String(texto) });
            }
            if (confirmar) {
                const vk = confirmar === 'Enter' ? 13 : 9;
                await c.enviar('Input.dispatchKeyEvent', { type: 'keyDown', key: confirmar, code: confirmar, windowsVirtualKeyCode: vk });
                await c.enviar('Input.dispatchKeyEvent', { type: 'keyUp', key: confirmar, code: confirmar, windowsVirtualKeyCode: vk });
            }
            await esp(120);
        },
        async escolher(jq, valor) {
            const r = await c.avaliar(`(function(){ var el = ${jq}.get(0); if (!el) return 'sem elemento'; el.scrollIntoView({block:'center'}); el.value = ${JSON.stringify(String(valor))}; if (el.value !== ${JSON.stringify(String(valor))}) return 'opcao inexistente: ' + [].map.call(el.options, function(o){return o.value;}).join('|'); el.dispatchEvent(new Event('input', {bubbles:true})); el.dispatchEvent(new Event('change', {bubbles:true})); return 'ok'; })()`);
            if (r !== 'ok') throw new Error('escolher: ' + r + ' em ' + jq + ' = ' + valor);
            await esp(120);
        },
        async marcar(jq, estado) {
            const r = await c.avaliar(`(function(){ var el = ${jq}.get(0); if (!el) return 'sem elemento'; el.scrollIntoView({block:'center'}); if (el.checked !== ${!!estado}) el.click(); return el.checked === ${!!estado} ? 'ok' : 'nao mudou'; })()`);
            if (r !== 'ok') throw new Error('marcar: ' + r + ' em ' + jq);
            await esp(120);
        },
        async clicar(jq) {
            const r = await c.avaliar(`(function(){ var el = ${jq}.get(0); if (!el) return 'sem elemento'; el.scrollIntoView({block:'center'}); el.click(); return 'ok'; })()`);
            if (r !== 'ok') throw new Error('clicar: ' + r + ' em ' + jq);
            await esp(150);
        },
        // Envia um arquivo para um <input type=file> (o mesmo caminho do seletor de arquivos do navegador).
        async subir(jq, arquivo) {
            const marca = 'zt' + Math.floor(Math.random() * 1e9);
            const r = await c.avaliar(`(function(){ var el = ${jq}.get(0); if (!el) return 'sem elemento'; el.setAttribute('data-zt', '${marca}'); return 'ok'; })()`);
            if (r !== 'ok') throw new Error('subir: ' + r + ' em ' + jq);
            const doc = await c.enviar('DOM.getDocument', { depth: 0 });
            const q = await c.enviar('DOM.querySelector', { nodeId: doc.result.root.nodeId, selector: `[data-zt="${marca}"]` });
            const caminho = path.isAbsolute(arquivo) ? arquivo : path.join(MIDIA, arquivo);
            if (!fs.existsSync(caminho)) throw new Error('arquivo de teste não existe: ' + caminho);
            await c.enviar('DOM.setFileInputFiles', { nodeId: q.result.nodeId, files: [caminho] });
            await c.avaliar(`${jq}.get(0).removeAttribute('data-zt'); 1`);
        },
        async toast() { return (await c.avaliar(`jQuery('#toast').text()`)) || ''; },
        async esperarToast(re, ms = 8000) {
            const t0 = Date.now();
            while (Date.now() - t0 < ms) {
                const tx = await t.toast();
                if (re.test(tx) && (await c.avaliar(`jQuery('#toast').hasClass('visivel') || jQuery('#toast').is(':visible')`))) return tx;
                await esp(150);
            }
            return await t.toast();
        },
        async captura(nomeArquivo) { await c.captura(path.join(SAIDA, nomeArquivo)); },
        async http(urlRelativa) {
            return JSON.parse(await c.avaliar(`fetch(${JSON.stringify(urlRelativa)}, {credentials:'include'}).then(async r => JSON.stringify({status:r.status, tipo:r.headers.get('content-type'), tamanho:(await r.arrayBuffer()).byteLength}))`));
        },
        async api(acao, qs = '') {
            return JSON.parse(await c.avaliar(`fetch('api.php?action=${acao}${qs}', {credentials:'include'}).then(r => r.text())`));
        },
        errosJs() { return c.errosJs(); },
        async fim() {
            const pass = resultados.filter(r => r.ok).length, fail = resultados.length - pass;
            fs.writeFileSync(path.join(SAIDA, nome + '.json'), JSON.stringify(resultados, null, 1));
            console.log('RESUMO ' + nome + ' PASS=' + pass + ' FAIL=' + fail);
            try { await c.fechar(); } catch (e) { /* já fechado */ }
            return fail;
        },
    };
    return t;
}
module.exports = { iniciar, esp, BASE };
