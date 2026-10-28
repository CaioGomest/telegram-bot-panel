// Aviso de valor mínimo no editor do Fluxo Guiado (assets/edicao_fluxo_basico.js, função validarPrecoMinimo), sem navegador nem servidor.
// Espelha tests/gateways/unitario_preco_visual.php (lado servidor): frase curta quando plano/pack está abaixo; frase extra só quando é o DESCONTO
// do Upsell/Downsell que deixa o preço abaixo do mínimo (caso do dono, 21/10/2026: plano de R$ 1,50 + Upsell com 1% de desconto sai por R$ 1,49).
// Uso: node tests/gateways/unitario_preco_guiado_js.js   (sai com 1 se falhar)
const fs = require('fs');
const path = require('path');
const fonte = fs.readFileSync(path.join(__dirname, '../../assets/edicao_fluxo_basico.js'), 'utf8');
const m = fonte.match(/function validarPrecoMinimo\(d\) \{[\s\S]*?\n    \}\n/);
if (!m) { console.log('FALHOU  função validarPrecoMinimo não encontrada em assets/edicao_fluxo_basico.js'); process.exit(1); }
const montar = (minimo) => new Function('preco_minimo', "const ID_UPSELL_PROPRIO = 'up_exclusivo';\n" + m[0] + '\nreturn validarPrecoMinimo;')(minimo);
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

// Downsell em sequência e planos criados no Downsell (item 38): espelha o lado servidor
const dnb = (downsell) => base([{ id: 'a', nome: 'Mensal', valor: 20 }, { id: 'b', nome: 'Semanal', valor: 3 }], { upsell: {}, downsell: Object.assign({ ativo: true, planos_oferecidos: [], quando: 'depois_pix' }, downsell) });
const MSG21 = 'Para a Omega, o valor mínimo é R$ 2,10.';
confere('plano criado no Downsell de R$ 1,00 recusa só com o mínimo', v21(dnb({ criar_planos: true, planos_proprios: [{ nome: 'Relâmpago', valor: 1 }] })) === MSG21);
confere('plano criado de R$ 2,50 passa mesmo com 50% de desconto no JSON (o preço é o digitado)', v21(dnb({ criar_planos: true, desconto_percentual: 50, planos_proprios: [{ nome: 'R', valor: 2.5 }] })) === '');
confere('com "Criar planos" ligado, o plano marcado e escondido (Semanal com 50%) não entra na conta', v21(dnb({ criar_planos: true, planos_oferecidos: ['b'], desconto_percentual: 50, planos_proprios: [{ nome: 'X', valor: 9 }] })) === '');
const seq2 = dnb({ planos_oferecidos: ['a'], desconto_percentual: 10, sequencias_extras: [{ planos_oferecidos: ['b'], desconto_percentual: 50 }] });
confere('o Semanal na 2ª sequência com 50% (R$ 1,50) recusa e a frase aponta o Downsell', v21(seq2) === MSG21 + ' O Downsell, com 50% de desconto, sai por R$ 1,50.', v21(seq2));
seq2.ofertas.downsell.sequencias_extras[0].desconto_percentual = 0;
confere('a mesma 2ª sequência sem desconto (Semanal R$ 3,00) passa', v21(seq2) === '');
seq2.ofertas.downsell.sequencias_extras[0] = { criar_planos: true, planos_proprios: [{ nome: 'Última chance', valor: 1 }] };
confere('plano criado na 2ª sequência de R$ 1,00 recusa', v21(seq2) === MSG21);

console.log('\n' + ok + ' ok, ' + falhas + ' falha(s)');
process.exit(falhas ? 1 : 0);
