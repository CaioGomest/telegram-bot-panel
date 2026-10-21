// Aviso de valor mínimo no editor do Fluxo Guiado (assets/edicao_fluxo_basico.js, função validarPrecoMinimo), sem navegador nem servidor.
// Espelha tests/gateways/unitario_preco_visual.php (lado servidor): frase curta quando plano/pack está abaixo; frase extra só quando é o DESCONTO
// do Upsell/Downsell que deixa o preço abaixo do mínimo (caso do dono, 21/10/2026: plano de R$ 1,50 + Upsell com 1% de desconto sai por R$ 1,49).
// Uso: node tests/gateways/unitario_preco_guiado_js.js   (sai com 1 se falhar)
const fs = require('fs');
const path = require('path');
const fonte = fs.readFileSync(path.join(__dirname, '../../assets/edicao_fluxo_basico.js'), 'utf8');
const m = fonte.match(/function validarPrecoMinimo\(d\) \{[\s\S]*?\n    \}\n/);
if (!m) { console.log('FALHOU  função validarPrecoMinimo não encontrada em assets/edicao_fluxo_basico.js'); process.exit(1); }
const montar = (minimo) => new Function('preco_minimo', m[0] + '\nreturn validarPrecoMinimo;')(minimo);
let ok = 0, falhas = 0;
const confere = (nome, cond, det) => { if (cond) { ok++; console.log('OK     ' + nome); } else { falhas++; console.log('FALHOU ' + nome + (det !== undefined ? '  -> ' + JSON.stringify(det) : '')); } };
const base = (planos, ofertas, packs) => ({ planos: planos, ofertas: ofertas || { upsell: {}, downsell: {} }, packs: packs || { ativo: false, itens: [] } });

const v21 = montar(2.1), v15 = montar(1.5);
confere('planos acima do mínimo passam', v21(base([{ id: 'a', nome: 'Mensal', valor: 20 }, { id: 'b', nome: 'Semanal', valor: 3 }])) === '');
confere('plano de R$ 1,00 com mínimo de R$ 2,10: frase única, só com o mínimo', v21(base([{ id: 'a', nome: 'X', valor: 1 }])) === 'Para a Omega, o valor mínimo é R$ 2,10.');
confere('pack de R$ 1,00 recusa com a frase única', v21(base([{ id: 'a', nome: 'X', valor: 20 }], null, { ativo: true, itens: [{ nome: 'P', preco: 1, ativo: true }] })) === 'Para a Omega, o valor mínimo é R$ 2,10.');
confere('mínimo ainda não carregado (0): não bloqueia', montar(0)(base([{ id: 'a', nome: 'X', valor: 1 }])) === '');
// caso do dono: plano 1,50 passa sozinho, o Upsell com 1% sai por 1,49
const dono = base([{ id: 'p', nome: '1', valor: 1.5 }], { upsell: { ativo: true, id_plano_destino: 'p', desconto_percentual: 1 }, downsell: {} });
confere('plano de R$ 1,50 sozinho passa com mínimo de R$ 1,50', v15(base([{ id: 'p', nome: '1', valor: 1.5 }])) === '');
confere('plano de R$ 1,50 + Upsell com 1%: a mensagem diz que o Upsell, com o desconto, sai por R$ 1,49', v15(dono) === 'Para a Omega, o valor mínimo é R$ 1,50. O Upsell, com 1% de desconto, sai por R$ 1,49.', v15(dono));
dono.ofertas.upsell.desconto_percentual = 0;
confere('o mesmo Upsell sem desconto passa', v15(dono) === '');
// Downsell com vários planos: mostra o pior (menor preço) uma vez só
const dn = base([{ id: 'a', nome: 'Mensal', valor: 20 }, { id: 'b', nome: 'Semanal', valor: 3 }], { upsell: {}, downsell: { ativo: true, planos_oferecidos: ['a', 'b'], desconto_percentual: 50 } });
confere('Downsell com 2 planos: só o Semanal com 50% (R$ 1,50) fica abaixo; frase extra com o desconto e o valor', v21(dn) === 'Para a Omega, o valor mínimo é R$ 2,10. O Downsell, com 50% de desconto, sai por R$ 1,50.', v21(dn));
// plano já abaixo + desconto: só a frase curta (o desconto não é o culpado)
const baixo = base([{ id: 'p', nome: 'x', valor: 1 }], { upsell: { ativo: true, id_plano_destino: 'p', desconto_percentual: 10 }, downsell: {} });
confere('plano já abaixo do mínimo + Upsell com desconto: só a frase curta', v15(baixo) === 'Para a Omega, o valor mínimo é R$ 1,50.', v15(baixo));

console.log('\n' + ok + ' ok, ' + falhas + ' falha(s)');
process.exit(falhas ? 1 : 0);
