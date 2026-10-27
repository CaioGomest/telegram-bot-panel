// Ranking com foto de perfil (22/10/2026): pódio, lista, "sua posição" e mini-ranking do Início mostram a foto do usuário
// (aro de medalha no top 3; sem foto, as iniciais). Chrome real, banco da homologação, SÓ LEITURA no navegador.
// Pré-requisito: usuários do top do ranking mensal com `foto_perfil` preenchida (o teste não grava nada — quem prepara/limpa é o `zz_ranking_fotos.php`).
// Uso: EMAIL=cliente170@gmial.com SENHA=12345678 TOP='{"1":"teste_ranking_f1.jpg","2":"teste_ranking_f2.png","3":"teste_ranking_f3.jpg","4":"teste_ranking_f4.png","5":"teste_ranking_f5.jpg"}' OUT=./pg node pg_ranking_fotos.js
//      (capturas em OUT/ranking_fotos_<largura>_<tema>.png; EMAIL = um usuário QUE NÃO TEM foto, para ver o fallback em "Sua posição")
const fs = require('fs'), path = require('path');
const { lancar, entrar, BASE } = require('./pg_lib.js');
const EMAIL = process.env.EMAIL, SENHA = process.env.SENHA || '123456', TOP = JSON.parse(process.env.TOP || '{}');
const OUT = process.env.OUT || path.join(__dirname, 'pg'); fs.mkdirSync(OUT, { recursive: true });
if (!EMAIL) { console.log('Informe EMAIL=<usuário comum que está no ranking mensal>'); process.exit(2); }
const esp = (ms) => new Promise(r => setTimeout(r, ms));
let ok = 0, falha = 0;
const chk = (n, c, x = '') => { c ? ok++ : falha++; console.log((c ? 'OK     ' : 'FALHOU ') + n + (x ? '  -> ' + String(x).slice(0, 300) : '')); };

// Lê o que a tela desenhou: para cada avatar, o arquivo da foto (ou as iniciais), se carregou, as classes de medalha, a cor do aro e a geometria.
const LER_AVATARES = (seletor) => `(function(){
  return JSON.stringify([...document.querySelectorAll(${JSON.stringify(seletor)})].map(function(a){
    var img = a.querySelector('img'), cs = getComputedStyle(a), r = a.getBoundingClientRect();
    return { cls: a.className, foto: img ? decodeURIComponent(img.src.split('/').pop().split('?')[0]) : null,
      carregou: img ? (img.complete && img.naturalWidth > 0) : null, objFit: img ? getComputedStyle(img).objectFit : null,
      ig: img ? Math.round(img.getBoundingClientRect().width) + 'x' + Math.round(img.getBoundingClientRect().height) : null,
      texto: img ? '' : a.textContent.trim(), aro: cs.borderTopColor, aroPx: cs.borderTopWidth, raio: cs.borderTopLeftRadius, corte: cs.overflow,
      w: Math.round(r.width), h: Math.round(r.height) };
  }));
})()`;
const rgb = (s) => (String(s).match(/\d+/g) || []).slice(0, 3).map(Number);
const perto = (a, b, t = 4) => a.length === 3 && b.length === 3 && a.every((v, i) => Math.abs(v - b[i]) <= t);

(async () => {
  const c = await lancar();
  try {
    await c.enviar('Emulation.setDeviceMetricsOverride', { width: 1360, height: 1000, deviceScaleFactor: 1, mobile: false });
    await entrar(c, EMAIL, SENHA);
    chk('login do usuário de teste', !/login/.test(await c.avaliar('location.pathname')), await c.avaliar('location.pathname'));

    // ---------- /ranking (mensal), desktop escuro ----------
    await c.ir(BASE + '/ranking?aba=mensal'); await esp(1800);
    // cores das medalhas como o CSS as resolve (tema escuro)
    const med = JSON.parse(await c.avaliar(`(function(){var s=getComputedStyle(document.documentElement);return JSON.stringify([s.getPropertyValue('--med-ouro'),s.getPropertyValue('--med-prata'),s.getPropertyValue('--med-bronze')].map(function(v){var d=document.createElement('i');d.style.color=v.trim();document.body.appendChild(d);var c=getComputedStyle(d).color;d.remove();return c}))})()`));
    const pod = JSON.parse(await c.avaliar(LER_AVATARES('.a-pod .av')));
    chk('pódio: 3 avatares (ordem visual 2º, 1º, 3º)', pod.length === 3, pod.map(p => p.cls).join(' | '));
    if (pod.length === 3) {
      chk('pódio: 1º no meio com a foto do 1º', pod[1].foto === TOP['1'] && pod[1].carregou === true, pod[1].foto);
      chk('pódio: 2º à esquerda com a foto do 2º', pod[0].foto === TOP['2'] && pod[0].carregou === true, pod[0].foto);
      chk('pódio: 3º à direita com a foto do 3º', pod[2].foto === TOP['3'] && pod[2].carregou === true, pod[2].foto);
      chk('pódio: classes de medalha m1/m2/m3', /\bm1\b/.test(pod[1].cls) && /\bm2\b/.test(pod[0].cls) && /\bm3\b/.test(pod[2].cls), pod.map(p => p.cls).join(' | '));
      chk('pódio: aro ouro/prata/bronze com a cor da medalha', perto(rgb(pod[1].aro), rgb(med[0])) && perto(rgb(pod[0].aro), rgb(med[1])) && perto(rgb(pod[2].aro), rgb(med[2])), pod.map(p => p.aro).join(' | ') + ' vs ' + med.join(' | '));
      chk('pódio: círculo perfeito (largura = altura, raio 50%, recorta a foto)', pod.every(p => p.w === p.h && p.corte === 'hidden' && parseFloat(p.raio) >= p.w / 2 - 1), pod.map(p => `${p.w}x${p.h} r${p.raio}`).join(' | '));
      chk('pódio: a foto cobre o círculo sem esticar (object-fit: cover; foto larga/alta cabe no quadrado)', pod.every(p => p.objFit === 'cover' && p.ig && p.ig.split('x')[0] === p.ig.split('x')[1]), pod.map(p => p.ig + ' ' + p.objFit).join(' | '));
      chk('pódio: 1º é maior que o 2º e o 3º', pod[1].w > pod[0].w && pod[1].w > pod[2].w, pod.map(p => p.w).join(' / '));
    }
    const lista = JSON.parse(await c.avaliar(LER_AVATARES('.a-rank .av')));
    // a lista tem as posições 4–10 e, por último, a linha "Sua posição"
    const nLinhas = JSON.parse(await c.avaliar(`JSON.stringify([...document.querySelectorAll('.a-rank li')].map(function(li){return {pos:(li.querySelector('.pos')||{}).textContent, voce: li.classList.contains('voce')}}))`));
    chk('lista: uma foto/iniciais por linha', lista.length === nLinhas.length && lista.length >= 2, lista.length + ' avatares, ' + nLinhas.length + ' linhas');
    const por = (pos) => { const i = nLinhas.findIndex(l => l.pos === String(pos).padStart(2, '0') && !l.voce); return i >= 0 ? lista[i] : null; };
    if (TOP['4']) chk('lista: 4º com foto', por(4) && por(4).foto === TOP['4'] && por(4).carregou === true, por(4) && por(4).foto);
    if (TOP['5']) chk('lista: 5º com foto', por(5) && por(5).foto === TOP['5'] && por(5).carregou === true, por(5) && por(5).foto);
    const semFoto = lista.filter((l, i) => !nLinhas[i].voce && l.foto === null);
    chk('lista: quem não tem foto mostra as iniciais (1–2 letras), sem imagem quebrada', semFoto.length >= 1 && semFoto.every(l => /^\S{1,2}$/.test(l.texto)), semFoto.map(l => l.texto).join(' ,'));
    chk('lista: 4º em diante sem aro de medalha', lista.filter((l, i) => !nLinhas[i].voce).every(l => !/\bm[123]\b/.test(l.cls)), lista.map(l => l.cls).join(' | '));
    const minha = lista[nLinhas.findIndex(l => l.voce)];
    chk('"Sua posição": avatar do próprio usuário (iniciais, pois ele não tem foto)', !!minha && minha.foto === null && /^\S{1,2}$/.test(minha.texto), minha && (minha.texto || minha.foto));
    const tag = JSON.parse(await c.avaliar(`(function(){var t=document.querySelector('.a-rank .a-tag'); if(!t) return 'null'; var s=getComputedStyle(t); return JSON.stringify({cor:s.color, fundo:s.backgroundColor})})()`));
    chk('etiqueta "você": texto escuro sobre o laranja (nunca cinza)', !!tag && perto(rgb(tag.cor), [27, 13, 4], 6), tag && tag.cor + ' sobre ' + tag.fundo);
    chk('avatares da lista: círculo 42 px com borda sutil', lista.every(l => l.w === l.h && l.w >= 38), lista.map(l => l.w + 'x' + l.h).join(' | '));
    const tem404 = await c.avaliar(`[...document.images].filter(function(i){return i.complete && i.naturalWidth===0}).length`);
    chk('/ranking: nenhuma imagem quebrada', Number(tem404) === 0, tem404);
    chk('/ranking: sem rolagem horizontal (1360)', !(await c.avaliar(`document.documentElement.scrollWidth > window.innerWidth + 2`)));
    await c.captura(path.join(OUT, 'ranking_fotos_1360_escuro.png'));

    // ---------- tema claro ----------
    await c.avaliar(`document.documentElement.setAttribute('data-theme','light'); 1`); await esp(500);
    const podClaro = JSON.parse(await c.avaliar(LER_AVATARES('.a-pod .av')));
    const medClaro = JSON.parse(await c.avaliar(`(function(){var s=getComputedStyle(document.documentElement);return JSON.stringify([s.getPropertyValue('--med-ouro'),s.getPropertyValue('--med-prata'),s.getPropertyValue('--med-bronze')].map(function(v){var d=document.createElement('i');d.style.color=v.trim();document.body.appendChild(d);var c=getComputedStyle(d).color;d.remove();return c}))})()`));
    chk('tema claro: aros com as cores da medalha do tema claro (legíveis no fundo branco)', podClaro.length === 3 && perto(rgb(podClaro[1].aro), rgb(medClaro[0])) && perto(rgb(podClaro[0].aro), rgb(medClaro[1])) && perto(rgb(podClaro[2].aro), rgb(medClaro[2])), podClaro.map(p => p.aro).join(' | '));
    await c.captura(path.join(OUT, 'ranking_fotos_1360_claro.png'));
    await c.avaliar(`document.documentElement.setAttribute('data-theme','dark'); 1`);

    // ---------- mini-ranking do Início ----------
    await c.ir(BASE + '/'); await esp(2000);
    await c.avaliar(`(function(){var b=document.querySelector('[data-aba-mini="mensal"]'); if(b) b.click(); return 1})()`); await esp(400);
    const mini = JSON.parse(await c.avaliar(LER_AVATARES('[data-aba-painel="mensal"] .a-mr .av, .a-mr [data-aba-painel="mensal"] .av')));
    const miniTodos = mini.length ? mini : JSON.parse(await c.avaliar(LER_AVATARES('#mini-ranking .av')));
    const doMensal = miniTodos.filter(m => m.w > 0);
    chk('Início/mini-ranking (mensal): 5 linhas, cada uma com avatar', doMensal.length >= 5, doMensal.length);
    if (doMensal.length >= 5) {
      chk('Início/mini-ranking: fotos do 1º ao 5º', [1, 2, 3, 4, 5].every(i => !TOP[i] || doMensal[i - 1].foto === TOP[i]), doMensal.slice(0, 5).map(m => m.foto || m.texto).join(' | '));
      chk('Início/mini-ranking: aro de medalha só no top 3', /\bm1\b/.test(doMensal[0].cls) && /\bm2\b/.test(doMensal[1].cls) && /\bm3\b/.test(doMensal[2].cls) && !/\bm[123]\b/.test(doMensal[3].cls + doMensal[4].cls), doMensal.slice(0, 5).map(m => m.cls).join(' | '));
      chk('Início/mini-ranking: círculo perfeito', doMensal.every(m => m.w === m.h && m.w >= 30), doMensal.map(m => m.w + 'x' + m.h).join(' | '));
    }
    chk('Início: sem rolagem horizontal (1360)', !(await c.avaliar(`document.documentElement.scrollWidth > window.innerWidth + 2`)));
    await c.avaliar(`(function(){var e=document.getElementById('mini-ranking'); if(e) e.scrollIntoView({block:'center'}); return 1})()`); await esp(400);
    await c.captura(path.join(OUT, 'inicio_ranking_fotos_1360.png'));

    // ---------- celular ----------
    await c.enviar('Emulation.setDeviceMetricsOverride', { width: 390, height: 900, deviceScaleFactor: 2, mobile: true });
    await c.ir(BASE + '/ranking?aba=mensal'); await esp(1800);
    const podM = JSON.parse(await c.avaliar(LER_AVATARES('.a-pod .av')));
    const listaM = JSON.parse(await c.avaliar(LER_AVATARES('.a-rank .av')));
    chk('celular: pódio com as 3 fotos carregadas e em círculo', podM.length === 3 && podM.every(p => p.foto && p.carregou && p.w === p.h), podM.map(p => `${p.foto} ${p.w}x${p.h}`).join(' | '));
    chk('celular: avatares da lista em círculo (40 px)', listaM.every(l => l.w === l.h && l.w >= 36 && l.w <= 44), listaM.map(l => l.w + 'x' + l.h).join(' | '));
    chk('celular: sem rolagem horizontal', !(await c.avaliar(`document.documentElement.scrollWidth > window.innerWidth + 2`)), await c.avaliar(`document.documentElement.scrollWidth + ' / ' + window.innerWidth`));
    await c.captura(path.join(OUT, 'ranking_fotos_390_escuro.png'));
    await c.ir(BASE + '/'); await esp(1800);
    chk('celular: Início sem rolagem horizontal', !(await c.avaliar(`document.documentElement.scrollWidth > window.innerWidth + 2`)), await c.avaliar(`document.documentElement.scrollWidth + ' / ' + window.innerWidth`));
    await c.avaliar(`(function(){var b=document.querySelector('[data-aba-mini="mensal"]'); if(b) b.click(); var e=document.getElementById('mini-ranking'); if(e) e.scrollIntoView({block:'start'}); return 1})()`); await esp(500);
    await c.captura(path.join(OUT, 'inicio_ranking_fotos_390.png'));

    // ---------- aba Concurso (campanha) ----------
    await c.enviar('Emulation.setDeviceMetricsOverride', { width: 1360, height: 1000, deviceScaleFactor: 1, mobile: false });
    await c.ir(BASE + '/ranking?aba=concurso'); await esp(1500);
    const erroPhp = await c.avaliar(`(document.body.innerText.match(/(Warning|Notice|Fatal error|Deprecated|Parse error|Uncaught)[^\\n]{0,140}/i)||[''])[0]`);
    chk('aba Concurso: abre sem erro de PHP', !erroPhp, erroPhp);

    chk('erros de JS', c.errosJs().length === 0, c.errosJs().join(' | '));
  } catch (e) { console.log('ERRO', e.stack); falha++; }
  finally { await c.fechar(); }
  console.log(`\nRESUMO: ${ok} ok, ${falha} falhas`);
  process.exit(falha ? 1 : 0);
})();
