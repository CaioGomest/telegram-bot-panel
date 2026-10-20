// Guarda barata contra um erro silencioso: uma "}" a mais (ou a menos) num CSS faz o navegador DESCARTAR a regra seguinte sem avisar
// (em 20/10/2026 foi o que deixou os rótulos do editor Guiado colados no campo de cima). Confere, em cada assets/css/*.css, as chaves fora de comentários e de strings.
// Rodar: node tests/css_chaves_balanceadas.js   (sai com código 1 se algum arquivo estiver desbalanceado)
const fs = require('fs'), path = require('path');
const dir = path.join(__dirname, '..', 'assets', 'css');
let ruim = 0;
for (const nome of fs.readdirSync(dir).filter(n => n.endsWith('.css'))) {
  const css = fs.readFileSync(path.join(dir, nome), 'utf8')
    .replace(/\/\*[\s\S]*?\*\//g, m => m.replace(/[^\n]/g, ' '))   // comentários (mantém as quebras de linha para o número da linha)
    .replace(/"(?:\\.|[^"\\\n])*"|'(?:\\.|[^'\\\n])*'/g, m => m.replace(/[^\n]/g, ' '));   // strings (um "}" dentro de content: "}" não conta)
  let nivel = 0, linha = 1;
  const sobras = [];
  for (const ch of css) {
    if (ch === '\n') linha++;
    else if (ch === '{') nivel++;
    else if (ch === '}') { nivel--; if (nivel < 0) { sobras.push(linha); nivel = 0; } }
  }
  const certo = sobras.length === 0 && nivel === 0;
  if (!certo) ruim++;
  console.log((certo ? 'OK     ' : 'FALHOU ') + nome + (certo ? '' : '  -> "}" a mais na(s) linha(s) ' + (sobras.join(', ') || '—') + ' | chaves abertas sem fechar: ' + nivel));
}
console.log(ruim ? `\n${ruim} arquivo(s) com chaves desbalanceadas` : '\nTodos os CSS com as chaves balanceadas');
process.exit(ruim ? 1 : 0);
