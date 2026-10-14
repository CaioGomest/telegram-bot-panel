// Registro dos resultados do teste página por página. Cada script grava res_<script>.json em $OUT.
const fs = require('fs');
const path = require('path');
const OUT = process.env.OUT || '.';
const resultados = [];
let areaAtual = '';
function area(n) { areaAtual = n; console.log('\n== ' + n); }
async function t(nome, fn, pagina = '') {
  let st = 'PASS', det = '';
  try {
    const r = await fn();
    if (r === false) st = 'FAIL';
    else if (r && typeof r === 'object' && 'ok' in r) { if (!r.ok) st = 'FAIL'; det = r.det ? String(r.det) : ''; }
    else if (typeof r === 'string') det = r;
  } catch (e) { st = 'FAIL'; det = 'EXCEÇÃO: ' + String(e.message).slice(0, 220); }
  resultados.push({ area: areaAtual, pagina, nome, st, det });
  console.log('  ' + st + ' | ' + nome + (det ? ' | ' + det : ''));
}
function nota(texto, det = '') { resultados.push({ area: areaAtual, pagina: '', nome: texto, st: 'NOTA', det }); console.log('  NOTA | ' + texto + (det ? ' | ' + det : '')); }
function salvar() {
  fs.writeFileSync(path.join(OUT, 'res_' + path.basename(process.argv[1], '.js') + '.json'), JSON.stringify(resultados, null, 1));
  const f = resultados.filter(x => x.st === 'FAIL').length;
  console.log('\n>> ' + resultados.filter(x => x.st === 'PASS').length + ' PASS, ' + f + ' FAIL');
}
const esp = (ms) => new Promise(r => setTimeout(r, ms));
module.exports = { area, t, nota, salvar, esp };
