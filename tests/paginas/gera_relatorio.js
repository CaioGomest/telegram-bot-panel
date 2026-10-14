// Gera as tabelas por área do relatório a partir dos res_*.json
const fs = require('fs');
const path = require('path');
const dir = path.join(__dirname, 'pg');
const ordem = ['a1', 'a2', 'a3', 'a4', 'a5', 'a6', 'a7', 'a8', 'a9', 'a10', 'a11', 'a12', 'a13', 'a14', 'a15'];
let md = '';
let totalPass = 0, totalFail = 0, totalNota = 0;
const resumo = [];
for (const k of ordem) {
  const f = path.join(dir, `res_pg_${k}.json`);
  if (!fs.existsSync(f)) continue;
  const r = JSON.parse(fs.readFileSync(f, 'utf8'));
  const porArea = new Map();
  for (const x of r) { if (!porArea.has(x.area)) porArea.set(x.area, []); porArea.get(x.area).push(x); }
  for (const [area, itens] of porArea) {
    const p = itens.filter(x => x.st === 'PASS').length, fl = itens.filter(x => x.st === 'FAIL').length;
    totalPass += p; totalFail += fl; totalNota += itens.filter(x => x.st === 'NOTA').length;
    resumo.push([area, p, fl]);
    md += `\n#### ${area}\n\n| Resultado | Teste | Observação |\n|---|---|---|\n`;
    for (const x of itens) {
      const marca = x.st === 'PASS' ? '✅' : x.st === 'FAIL' ? '❌' : 'ℹ️';
      const det = (x.det || '').replace(/\|/g, '\\|').replace(/\n/g, ' ').slice(0, 160);
      md += `| ${marca} | ${x.nome.replace(/\|/g, '\\|')} | ${det} |\n`;
    }
  }
}
fs.writeFileSync(path.join(dir, 'relatorio_tabelas.md'), md);
fs.writeFileSync(path.join(dir, 'relatorio_resumo.json'), JSON.stringify({ resumo, totalPass, totalFail, totalNota }, null, 1));
console.log(JSON.stringify({ totalPass, totalFail, totalNota }), resumo.length + ' áreas');
