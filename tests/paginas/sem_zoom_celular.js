// Sem zoom no celular (item 37, 27/10/2026): todas as telas do painel pedem "maximum-scale=1.0, user-scalable=no" no <meta viewport>; só o Editor de Fluxos
// (fluxo.php) e o Guiado (fluxo_basico.php) continuam com zoom. O Safari do iPhone ignora user-scalable=no, então o script de tema_inline.php barra a pinça
// (gesturestart) e fixa touch-action: pan-x pan-y, só quando o meta da página pede "sem zoom".
// Parte 1 (arquivos): confere o meta de cada página PHP. Parte 2 (Chrome real, sem servidor/banco/login): roda o script de tema_inline.php, tirado do arquivo,
// numa página com o meta "sem zoom" e noutra com o meta liberado.
//   node tests/paginas/sem_zoom_celular.js                    (sai com código 1 se algo falhar)
const fs = require('fs');
const os = require('os');
const path = require('path');
const RAIZ = path.resolve(__dirname, '../..').replace(/\\/g, '/');
const { lancar } = require(RAIZ + '/tests/homologacao/ui/cdp.js');

const META_SEM_ZOOM = '<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">';
const META_LIBERADO = '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
const COM_ZOOM = ['fluxo.php', 'fluxo_basico.php'];
const IGNORAR = ['tests', 'node_modules', 'anotacoes', 'deploy', 'uploads', 'logs', 'storage', 'resetar', 'certificados'];
const esp = (ms) => new Promise((r) => setTimeout(r, ms));
let falhas = 0, total = 0;
const ok = (cond, msg) => { total++; if (!cond) { falhas++; console.log('FALHA: ' + msg); } };

function paginasPhp(dir, acc = []) {
    for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
        if (e.isDirectory()) { if (!IGNORAR.includes(e.name)) paginasPhp(path.join(dir, e.name), acc); }
        else if (e.name.endsWith('.php')) acc.push(path.join(dir, e.name));
    }
    return acc;
}

// ---- parte 1: o meta de cada página ----
let com_meta = 0;
for (const arq of paginasPhp(RAIZ)) {
    const rel = path.relative(RAIZ, arq).replace(/\\/g, '/');
    const txt = fs.readFileSync(arq, 'utf8');
    if (rel === 'funcoes/email.php') continue; // e-mail: o cliente de e-mail ignora o meta
    if (!/<meta name="viewport"/.test(txt)) continue;
    com_meta++;
    if (COM_ZOOM.includes(rel)) {
        ok(txt.includes(META_LIBERADO) && !txt.includes('user-scalable=no'), rel + ' deveria continuar com zoom');
    } else {
        ok(txt.includes(META_SEM_ZOOM) && !txt.includes(META_LIBERADO), rel + ' deveria estar sem zoom');
    }
}
ok(com_meta >= 40, 'achou poucas páginas com viewport (' + com_meta + ')');
for (const f of COM_ZOOM) ok(fs.existsSync(path.join(RAIZ, f)), f + ' não existe mais (atualizar a lista deste teste)');

// ---- parte 2: o script de tema_inline.php num Chrome de verdade ----
const tema = fs.readFileSync(path.join(RAIZ, 'tema_inline.php'), 'utf8');
const achado = tema.match(/<script>(\(function\(\)\{var m=document\.querySelector\('meta\[name="viewport"\]'\)[\s\S]*?)<\/script>/);
ok(!!achado, 'script de zoom não encontrado em tema_inline.php');
const SCRIPT = achado ? achado[1] : '';

(async () => {
    const pasta = fs.mkdtempSync(path.join(os.tmpdir(), 'semzoom-')).replace(/\\/g, '/');
    const casos = [
        { nome: 'sem_zoom', meta: META_SEM_ZOOM, bloqueia: true },
        { nome: 'com_zoom', meta: META_LIBERADO, bloqueia: false },
    ];
    for (const c of casos) fs.writeFileSync(pasta + '/' + c.nome + '.html', '<!DOCTYPE html><html><head><meta charset="UTF-8">' + c.meta + '<script>' + SCRIPT + '</script></head><body><p>x</p></body></html>');
    const b = await lancar();
    try {
        await b.enviar('Emulation.setDeviceMetricsOverride', { width: 390, height: 800, deviceScaleFactor: 2, mobile: true });
        for (const c of casos) {
            await b.ir('file:///' + pasta + '/' + c.nome + '.html');
            await esp(300);
            const r = JSON.parse(await b.avaliar(`JSON.stringify((function(){
                var ev = []; ['gesturestart','gesturechange','gestureend'].forEach(function(n){ var e = new Event(n, { cancelable: true, bubbles: true }); document.dispatchEvent(e); ev.push(e.defaultPrevented); });
                return { touch: document.documentElement.style.touchAction, eventos: ev, erro: window.__erro || '' };
            })())`));
            if (c.bloqueia) {
                ok(r.touch === 'pan-x pan-y', c.nome + ': touch-action deveria ser "pan-x pan-y" e é "' + r.touch + '"');
                ok(r.eventos.every(Boolean), c.nome + ': os gestos de pinça deveriam ser cancelados (' + r.eventos.join(',') + ')');
            } else {
                ok(r.touch === '', c.nome + ': touch-action não deveria ser mexido e é "' + r.touch + '"');
                ok(r.eventos.every((x) => !x), c.nome + ': os gestos de pinça não deveriam ser cancelados (' + r.eventos.join(',') + ')');
            }
        }
    } finally { await b.fechar(); }
    console.log(total + ' verificações, ' + falhas + ' falha(s)');
    process.exit(falhas ? 1 : 0);
})().catch((e) => { console.log('ERRO: ' + e.message); process.exit(1); });
