<?php
declare(strict_types=1);

/**
 * Teste unitário de validarPrecosFluxoVisual() (funcoes/gateways.php): bloco PIX do Editor Visual abaixo do mínimo da OmegaPayments é recusado.
 * Sem banco, sem servidor: a função é pura, então o teste lê só o trecho dela do arquivo (o resto de gateways.php precisa de conexão).
 * Rodar: php tests/gateways/unitario_preco_visual.php   (sai com código 1 se algo falhar)
 */

$fonte = str_replace("\r\n", "\n", (string) file_get_contents(__DIR__ . '/../../funcoes/gateways.php'));
if (!preg_match('/function validarPrecosFluxoVisual\(.*?\n}\n/s', $fonte, $m)) {
    echo "FALHOU  função validarPrecosFluxoVisual não encontrada em funcoes/gateways.php\n";
    exit(1);
}
eval($m[0]);
if (!preg_match('/function validarPrecosFluxoGuiado\(.*?\n}\n/s', $fonte, $mg)) {
    echo "FALHOU  função validarPrecosFluxoGuiado não encontrada em funcoes/gateways.php\n";
    exit(1);
}
eval($mg[0]);
require_once __DIR__ . '/../../funcoes/entrega.php';   // sequenciasDownsellBasico() e planosProprioDoDownsellBasico(), usadas por validarPrecosFluxoGuiado

$ok = 0;
$falhas = 0;
function confere(string $nome, bool $certo, string $detalhe = ''): void
{
    global $ok, $falhas;
    $certo ? $ok++ : $falhas++;
    echo ($certo ? 'OK     ' : 'FALHOU ') . $nome . ($certo || $detalhe === '' ? '' : '  -> ' . $detalhe) . PHP_EOL;
}
function pix(float $valor, string $titulo = 'PIX', string $tipo = 'pix'): array
{
    return ['properties' => ['type' => $tipo, 'title' => $titulo, 'valor' => $valor]];
}

$min = 2.10;
$erro = validarPrecosFluxoVisual(['operators' => ['operator_1' => ['properties' => ['type' => 'start']], 'operator_2' => pix(1.0, 'Cobrança VIP')], 'links' => []], $min);
confere('PIX de R$ 1,00 com mínimo de R$ 2,10: recusa com o aviso enxuto "Para a Omega, o valor mínimo é R$ 2,10."', $erro === 'Para a Omega, o valor mínimo é R$ 2,10.', var_export($erro, true));
confere('o aviso NÃO fala da comissão da plataforma nem lista blocos/valores do vendedor', !str_contains((string) $erro, 'comissão') && !str_contains((string) $erro, 'Cobrança VIP') && !str_contains((string) $erro, 'R$ 1,00') && !str_contains((string) $erro, 'Ajuste'), var_export($erro, true));
confere('PIX exatamente no mínimo (R$ 2,10) passa', validarPrecosFluxoVisual(['operators' => ['a' => pix(2.10)]], $min) === null);
confere('PIX acima do mínimo passa', validarPrecosFluxoVisual(['operators' => ['a' => pix(80.0)]], $min) === null);
confere('PIX com valor 0 (bloco ainda sem preço) não é tratado como preço baixo', validarPrecosFluxoVisual(['operators' => ['a' => pix(0.0)]], $min) === null);
confere('blocos que não são PIX (mensagem, oferta com valor baixo) são ignorados', validarPrecosFluxoVisual(['operators' => ['a' => pix(1.0, 'x', 'message'), 'b' => pix(0.5, 'y', 'order_bump')]], $min) === null);
confere('PIX recorrente segue a mesma regra', validarPrecosFluxoVisual(['operators' => ['a' => ['properties' => ['type' => 'pix', 'tipo_cobranca' => 'recorrente', 'valor' => 1.5, 'title' => 'Assinatura']]]], $min) !== null);
confere('bloco PIX sem título e abaixo do mínimo também é recusado', validarPrecosFluxoVisual(['operators' => ['a' => ['properties' => ['type' => 'pix', 'valor' => 1.0]]]], $min) === 'Para a Omega, o valor mínimo é R$ 2,10.');
confere('operador malformado (não é lista) e fluxo sem operators não quebram', validarPrecosFluxoVisual(['operators' => ['a' => 'lixo', 'b' => null]], $min) === null && validarPrecosFluxoVisual([], $min) === null);
$muitos = ['operators' => []];
for ($i = 1; $i <= 6; $i++) {
    $muitos['operators']['op' . $i] = pix(1.0, 'P' . $i);
}
$e = (string) validarPrecosFluxoVisual($muitos, $min);
confere('vários PIX baixos: continua uma frase só, com o mínimo', $e === 'Para a Omega, o valor mínimo é R$ 2,10.', $e);
confere('valor vindo como texto numérico ("1.50") também é checado', validarPrecosFluxoVisual(['operators' => ['a' => ['properties' => ['type' => 'pix', 'valor' => '1.50']]]], $min) !== null);

// ---- Fluxo Guiado: planos, packs e o Downsell novo (vários planos marcados)
$msg_omega = 'Para a Omega, o valor mínimo é R$ 2,10.';
$guiado = ['planos' => [['id' => 'a', 'nome' => 'Mensal', 'valor' => 20.0], ['id' => 'b', 'nome' => 'Semanal', 'valor' => 3.0]], 'ofertas' => [], 'packs' => []];
confere('Guiado: planos acima do mínimo passam', validarPrecosFluxoGuiado($guiado, $min) === null);
$baixo = $guiado;
$baixo['planos'][1]['valor'] = 1.0;
confere('Guiado: plano de R$ 1,00 recusa só com o mínimo da Omega (sem citar o plano nem a comissão)', validarPrecosFluxoGuiado($baixo, $min) === $msg_omega, var_export(validarPrecosFluxoGuiado($baixo, $min), true));
$dn = $guiado;
$dn['ofertas']['downsell'] = ['ativo' => true, 'planos_oferecidos' => ['a', 'b'], 'desconto_percentual' => 50];
confere('Guiado: Downsell com 2 planos marcados — o Semanal com 50% (R$ 1,50) fica abaixo do mínimo e recusa, dizendo que foi o desconto', validarPrecosFluxoGuiado($dn, $min) === $msg_omega . ' O Downsell, com 50% de desconto, sai por R$ 1,50.', var_export(validarPrecosFluxoGuiado($dn, $min), true));
$dn['ofertas']['downsell']['planos_oferecidos'] = ['a'];
confere('Guiado: marcando só o Mensal (R$ 10,00 com 50%) passa', validarPrecosFluxoGuiado($dn, $min) === null);
$dn['ofertas']['downsell']['ativo'] = false;
$dn['ofertas']['downsell']['planos_oferecidos'] = ['a', 'b'];
confere('Guiado: Downsell desligado não é conferido', validarPrecosFluxoGuiado($dn, $min) === null);
$antigo = $guiado;
$antigo['ofertas']['downsell'] = ['ativo' => true, 'id_plano_destino' => 'b', 'desconto_percentual' => 50];
confere('Guiado: Downsell no formato antigo (id_plano_destino) continua sendo conferido', validarPrecosFluxoGuiado($antigo, $min) === $msg_omega . ' O Downsell, com 50% de desconto, sai por R$ 1,50.');
$pack = $guiado;
$pack['packs'] = ['ativo' => true, 'itens' => [['nome' => 'P', 'preco' => 1.0, 'ativo' => true]]];
confere('Guiado: pack de R$ 1,00 recusa', validarPrecosFluxoGuiado($pack, $min) === $msg_omega);
// Caso do dono (21/10/2026): plano de R$ 1,50 com mínimo de R$ 1,50 passa sozinho, mas o Upsell com 1% de desconto sai por R$ 1,49 e a mensagem curta enganava
$msg_150 = 'Para a Omega, o valor mínimo é R$ 1,50.';
$dono = ['planos' => [['id' => 'p', 'nome' => '1', 'valor' => 1.5]], 'ofertas' => ['upsell' => ['ativo' => true, 'id_plano_destino' => 'p', 'desconto_percentual' => 1]], 'packs' => []];
confere('Guiado: plano de R$ 1,50 sozinho (sem oferta) passa com mínimo de R$ 1,50', validarPrecosFluxoGuiado(['planos' => $dono['planos'], 'ofertas' => [], 'packs' => []], 1.5) === null);
confere('Guiado: plano de R$ 1,50 + Upsell com 1% de desconto: diz que o Upsell, com o desconto, sai por R$ 1,49', validarPrecosFluxoGuiado($dono, 1.5) === $msg_150 . ' O Upsell, com 1% de desconto, sai por R$ 1,49.', var_export(validarPrecosFluxoGuiado($dono, 1.5), true));
$dono['ofertas']['upsell']['desconto_percentual'] = 0;
confere('Guiado: o mesmo Upsell sem desconto passa (R$ 1,50 não é abaixo do mínimo)', validarPrecosFluxoGuiado($dono, 1.5) === null);
$plano_baixo = ['planos' => [['id' => 'p', 'nome' => 'x', 'valor' => 1.0]], 'ofertas' => ['upsell' => ['ativo' => true, 'id_plano_destino' => 'p', 'desconto_percentual' => 10]], 'packs' => []];
confere('Guiado: quando o próprio plano já está abaixo, fica só a frase curta (o desconto não é o culpado)', validarPrecosFluxoGuiado($plano_baixo, 1.5) === $msg_150, var_export(validarPrecosFluxoGuiado($plano_baixo, 1.5), true));

// Upsell com plano próprio (item 33): o valor digitado é o preço, sem desconto, e também não pode ficar abaixo do mínimo
$proprio_baixo = ['planos' => [['id' => 'p', 'nome' => 'Mensal', 'valor' => 20.0]], 'packs' => [], 'ofertas' => ['upsell' => ['ativo' => true, 'id_plano_destino' => 'up_exclusivo', 'desconto_percentual' => 50, 'plano_proprio' => ['nome' => 'Pacote', 'valor' => 1.0, 'dias_acesso' => 30, 'unidade_acesso' => 'dias']]]];
confere('Guiado: Upsell com plano próprio de R$ 1,00 recusa só com o mínimo (o desconto que sobrou no JSON não conta)', validarPrecosFluxoGuiado($proprio_baixo, 1.5) === $msg_150, var_export(validarPrecosFluxoGuiado($proprio_baixo, 1.5), true));
$proprio_ok = $proprio_baixo;
$proprio_ok['ofertas']['upsell']['plano_proprio']['valor'] = 1.5;
confere('Guiado: Upsell com plano próprio de R$ 1,50 passa mesmo com 50% de desconto no JSON (o preço é o digitado)', validarPrecosFluxoGuiado($proprio_ok, 1.5) === null, var_export(validarPrecosFluxoGuiado($proprio_ok, 1.5), true));
$proprio_ok['ofertas']['upsell']['plano_proprio']['valor'] = 1.0;
$proprio_ok['ofertas']['upsell']['ativo'] = false;
confere('Guiado: Upsell com plano próprio abaixo do mínimo, mas desligado, não é conferido', validarPrecosFluxoGuiado($proprio_ok, 1.5) === null);

// Downsell em sequência e planos criados no Downsell (item 38): o plano criado vale pelo valor digitado (sem desconto) e a sequência com "Criar planos" ignora os marcados escondidos
$dn_base = ['planos' => [['id' => 'a', 'nome' => 'Mensal', 'valor' => 20.0], ['id' => 'b', 'nome' => 'Semanal', 'valor' => 3.0]], 'packs' => []];
$criado = function (array $downsell) use ($dn_base): array { return $dn_base + ['ofertas' => ['downsell' => $downsell + ['ativo' => true, 'planos_oferecidos' => [], 'quando' => 'depois_pix']]]; };
confere('Guiado: plano criado no Downsell de R$ 1,00 recusa só com o mínimo', validarPrecosFluxoGuiado($criado(['criar_planos' => true, 'planos_proprios' => [['nome' => 'Relâmpago', 'valor' => 1.0]]]), $min) === $msg_omega, var_export(validarPrecosFluxoGuiado($criado(['criar_planos' => true, 'planos_proprios' => [['nome' => 'Relâmpago', 'valor' => 1.0]]]), $min), true));
confere('Guiado: plano criado de R$ 2,50 passa mesmo com 50% de desconto no JSON (o preço é o digitado)', validarPrecosFluxoGuiado($criado(['criar_planos' => true, 'desconto_percentual' => 50, 'planos_proprios' => [['nome' => 'Relâmpago', 'valor' => 2.5]]]), $min) === null);
confere('Guiado: com "Criar planos" ligado, o plano marcado e escondido (Semanal com 50%) não entra na conta', validarPrecosFluxoGuiado($criado(['criar_planos' => true, 'planos_oferecidos' => ['b'], 'desconto_percentual' => 50, 'planos_proprios' => [['nome' => 'X', 'valor' => 9.0]]]), $min) === null);
confere('Guiado: plano criado incompleto (sem nome ou sem valor) não é oferecido e não trava o salvamento', validarPrecosFluxoGuiado($criado(['criar_planos' => true, 'planos_proprios' => [['nome' => '', 'valor' => 1.0], ['nome' => 'Sem valor', 'valor' => 0]]]), $min) === null);
$seq2 = $criado(['planos_oferecidos' => ['a'], 'desconto_percentual' => 10, 'sequencias_extras' => [['planos_oferecidos' => ['b'], 'desconto_percentual' => 50]]]);
confere('Guiado: o Semanal na 2ª sequência com 50% (R$ 1,50) recusa e a frase aponta uma sequência do Downsell', validarPrecosFluxoGuiado($seq2, $min) === $msg_omega . ' Uma sequência do Downsell, com 50% de desconto, sai por R$ 1,50.', var_export(validarPrecosFluxoGuiado($seq2, $min), true));
$seq2['ofertas']['downsell']['sequencias_extras'][0]['desconto_percentual'] = 0;
confere('Guiado: a mesma 2ª sequência sem desconto (Semanal R$ 3,00) passa', validarPrecosFluxoGuiado($seq2, $min) === null);
$seq2['ofertas']['downsell']['sequencias_extras'][0] = ['criar_planos' => true, 'planos_proprios' => [['nome' => 'Última chance', 'valor' => 1.0]]];
confere('Guiado: plano criado na 2ª sequência de R$ 1,00 recusa', validarPrecosFluxoGuiado($seq2, $min) === $msg_omega);
$seq2['ofertas']['downsell']['quando'] = 'antes_pix';
confere('Guiado: sequências extras só valem com "Depois do Pix": em "Antes do Pix" a 2ª sequência é ignorada', validarPrecosFluxoGuiado($seq2, $min) === null);

echo PHP_EOL . "$ok ok, $falhas falha(s)" . PHP_EOL;
exit($falhas > 0 ? 1 : 0);
