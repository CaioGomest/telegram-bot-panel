// A prévia do editor (assets/pagamentos_guiado.js) tem que fazer EXATAMENTE o que o bot faz (funcoes/pagamentos_guiado.php). O PHP gera os resultados de
// referência (tests/guiado/golden_pagamentos.php) e aqui o JavaScript recalcula tudo a partir das mesmas entradas.
//   node tests/guiado/unitario_pagamentos_previa.js   (sai com código 1 se algo diferir)
const { spawnSync } = require('child_process');
const assert = require('assert');
const path = require('path');
const regras = require(path.join(__dirname, '../../assets/pagamentos_guiado.js'));

const r = spawnSync('php', [path.join(__dirname, 'golden_pagamentos.php')], { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
if (r.status !== 0) { console.log('O PHP não gerou a referência:\n' + r.stderr + r.stdout.slice(0, 500)); process.exit(1); }
const ouro = JSON.parse(r.stdout);

let ok = 0, falhas = 0;
function confere(nome, certo, detalhe) {
    certo ? ok++ : falhas++;
    console.log((certo ? 'OK     ' : 'FALHOU ') + nome + (certo || !detalhe ? '' : '  -> ' + String(detalhe).slice(0, 700)));
}
// compara tudo e resume: quantos casos, e o primeiro que difere
function lote(nome, casos, calcula) {
    let primeiro = null, erros = 0;
    casos.forEach(function (c, i) {
        try { assert.deepStrictEqual(calcula(c), c.esperado); }
        catch (e) { erros++; if (!primeiro) primeiro = 'caso ' + i + ': ' + JSON.stringify(c.entrada).slice(0, 300) + '\n   JS : ' + JSON.stringify(calcula(c)).slice(0, 400) + '\n   PHP: ' + JSON.stringify(c.esperado).slice(0, 400); }
    });
    confere(nome + ' (' + casos.length + ' casos)', erros === 0, erros + ' diferem. ' + primeiro);
}

lote('texto seguro: tags, escape, variáveis e corte iguais ao PHP', ouro.textos.map(c => ({ entrada: c.texto + ' | ' + JSON.stringify(c.v), esperado: { html: c.html, curto: c.curto }, c })), function (x) {
    const v = regras.variaveis(x.c.v.nome, x.c.v.plano, x.c.v.valor, x.c.v.hora);
    v.codigo_pix = { html: '<code>COD&amp;123</code>' };
    return { html: regras.htmlSeguro(x.c.texto, v), curto: regras.htmlSeguro(x.c.texto, v, 40) };
});
lote('valor em reais igual ao number_format do PHP', ouro.valores.map(([v, t]) => ({ entrada: v, esperado: t })), x => regras.valorTexto(x.entrada));
lote('configuração normalizada igual (padrões, limites, enums, mídias, campos antigos)', ouro.configs.map(c => ({ entrada: c.bruto, esperado: c.pg })), x => regras.config(x.entrada));
lote('plano do Pix gerado igual (mídias, QR, texto, código, botões, mensagens antes)', ouro.planos_pix.map(c => ({ entrada: { config: c.config, ctx: ouro.ctxs_pix[c.ctx] }, esperado: { passos: c.passos, teclado: c.teclado } })), function (x) {
    const pg = regras.config(ouro.configs[x.entrada.config].bruto);
    return { passos: regras.planoPix(pg, x.entrada.ctx), teclado: regras.teclado(pg, x.entrada.ctx) };
});
lote('plano da aprovação igual (texto, botão de acesso, link do grupo/entrega, validade)', ouro.aprovacoes.map(c => ({ entrada: { config: c.config, ctx: ouro.ctxs_aprovacao[c.ctx] }, esperado: c.plano })), function (x) {
    return regras.planoAprovacao(regras.config(ouro.configs[x.entrada.config].bruto), x.entrada.ctx);
});

console.log('\nRESUMO: ' + ok + ' ok, ' + falhas + ' falhas');
process.exit(falhas ? 1 : 0);
