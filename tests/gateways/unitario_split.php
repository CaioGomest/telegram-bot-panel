<?php
declare(strict_types=1);

/**
 * Teste unitário da validação do split do gateway (funcoes/split_validacao.php). Sem banco, sem servidor.
 * Rodar: php tests/gateways/unitario_split.php   (sai com código 1 se algo falhar)
 *
 * Os casos "absurdos" são os que o servidor ACEITAVA antes da correção (teste na VPS em 15/10/2026): percentual 150, negativo, 1000, texto, tipo inválido.
 */

require_once __DIR__ . '/../../funcoes/split_validacao.php';

$ok = 0;
$falhas = 0;

function esperaOk(string $nome, string $taxa, string $tipo, string $chave, float $taxa_esperada, string $tipo_esperado, string $chave_esperada): void
{
    global $ok, $falhas;
    [$dados, $erro] = validarSplitGateway($taxa, $tipo, $chave);
    $certo = $erro === null && $dados !== null && abs($dados['taxa'] - $taxa_esperada) < 0.0001 && $dados['tipo'] === $tipo_esperado && $dados['chave'] === $chave_esperada;
    $certo ? $ok++ : $falhas++;
    echo ($certo ? 'OK     ' : 'FALHOU ') . $nome . ($certo ? '' : '  -> erro=' . var_export($erro, true) . ' dados=' . json_encode($dados)) . PHP_EOL;
}

function esperaErro(string $nome, string $taxa, string $tipo, string $chave, string $trecho_do_erro): void
{
    global $ok, $falhas;
    [$dados, $erro] = validarSplitGateway($taxa, $tipo, $chave);
    $certo = $dados === null && $erro !== null && str_contains($erro, $trecho_do_erro);
    $certo ? $ok++ : $falhas++;
    echo ($certo ? 'OK     ' : 'FALHOU ') . $nome . ($certo ? '' : '  -> erro=' . var_export($erro, true) . ' dados=' . json_encode($dados)) . PHP_EOL;
}

echo "--- o que deve ser aceito" . PHP_EOL;
esperaOk('R$ 0,25 fixo (o split atual da VPS)', '0.25', 'fixo', 'abcd1234', 0.25, 'fixo', 'abcd1234');
esperaOk('R$ 1,40 fixo com vírgula', '1,40', 'fixo', 'cmuo0000000000000000000sr3', 1.40, 'fixo', 'cmuo0000000000000000000sr3');
esperaOk('5% do valor', '5', 'percentual', 'x', 5.0, 'percentual', 'x');
esperaOk('100% (limite do percentual)', '100', 'percentual', 'x', 100.0, 'percentual', 'x');
esperaOk('R$ 100,00 fixo (limite do fixo)', '100.00', 'fixo', 'x', 100.0, 'fixo', 'x');
esperaOk('zero = sem split', '0', 'percentual', '', 0.0, 'percentual', '');
esperaOk('campo vazio vira 0 (sem split)', '', 'fixo', '', 0.0, 'fixo', '');
esperaOk('espaços em volta são aparados', '  2,5  ', ' percentual ', '  abc  ', 2.5, 'percentual', 'abc');
esperaOk('chave Pix de e-mail (outros gateways)', '1', 'fixo', 'caio@exemplo.com.br', 1.0, 'fixo', 'caio@exemplo.com.br');
esperaOk('chave Pix de telefone', '1', 'fixo', '+5511999999999', 1.0, 'fixo', '+5511999999999');
esperaOk('sem producerId com taxa > 0 é aceito (a tela diz "nenhum split é feito")', '1.40', 'fixo', '', 1.40, 'fixo', '');
esperaOk('chave de 255 caracteres (limite)', '1', 'fixo', str_repeat('a', 255), 1.0, 'fixo', str_repeat('a', 255));

echo PHP_EOL . "--- o que o servidor aceitava e agora é recusado" . PHP_EOL;
esperaErro('percentual 150', '150', 'percentual', 'x', 'não pode passar de 100%');
esperaErro('percentual 100,01', '100.01', 'percentual', 'x', 'não pode passar de 100%');
esperaErro('negativo -5', '-5', 'percentual', 'x', 'Taxa do split inválida');
esperaErro('negativo fixo -0,01', '-0.01', 'fixo', 'x', 'Taxa do split inválida');
esperaErro('1000 (virava 999,99)', '1000', 'fixo', 'x', 'não pode passar de R$ 100,00');
esperaErro('fixo 140 (digitou 140 em vez de 1,40)', '140', 'fixo', 'x', 'não pode passar de R$ 100,00');
esperaErro('fixo 100,01', '100.01', 'fixo', 'x', 'não pode passar de R$ 100,00');
esperaErro('texto "abc" (virava 0,00)', 'abc', 'fixo', 'x', 'Taxa do split inválida');
esperaErro('notação científica 1e3', '1e3', 'fixo', 'x', 'Taxa do split inválida');
esperaErro('3 casas decimais', '1.234', 'fixo', 'x', 'Taxa do split inválida');
esperaErro('número com unidade "5%"', '5%', 'percentual', 'x', 'Taxa do split inválida');
esperaErro('vários pontos "1.2.3"', '1.2.3', 'fixo', 'x', 'Taxa do split inválida');
esperaErro('número enorme', '99999999999', 'fixo', 'x', 'Taxa do split inválida');
esperaErro('tipo inválido "hack" (virava percentual)', '1', 'hack', 'x', 'Tipo de split inválido');
esperaErro('tipo vazio', '1', '', 'x', 'Tipo de split inválido');
esperaErro('tipo com maiúscula "Fixo"', '1', 'Fixo', 'x', 'Tipo de split inválido');

echo PHP_EOL . "--- chave do recebedor" . PHP_EOL;
esperaErro('chave com espaço no meio', '1', 'fixo', 'abc def', 'ID do recebedor');
esperaErro('chave com quebra de linha', '1', 'fixo', "abc\ndef", 'ID do recebedor');
esperaErro('chave com byte nulo', '1', 'fixo', "abc\0def", 'ID do recebedor');
esperaErro('chave com 256 caracteres', '1', 'fixo', str_repeat('a', 256), 'ID do recebedor');
esperaErro('chave com UTF-8 inválido', '1', 'fixo', "abc\xC3\x28", 'ID do recebedor');

// ---------- comissão gravada na venda (comissaoDoSplit) = o que a cobrança manda à OmegaPayments (splits[].amount)
echo PHP_EOL . "--- comissão da venda x split enviado à gateway" . PHP_EOL;
function confereComissao(string $nome, bool $certo, string $detalhe = ''): void
{
    global $ok, $falhas;
    $certo ? $ok++ : $falhas++;
    echo ($certo ? 'OK     ' : 'FALHOU ') . $nome . ($certo || $detalhe === '' ? '' : '  -> ' . $detalhe) . PHP_EOL;
}
$fixo_035 = [['chave' => 'prod123', 'valor' => '0.35', 'tipo' => 'fixo']];
$pct_5 = [['chave' => 'prod123', 'valor' => '5.00', 'tipo' => 'percentual']];
confereComissao('split fixo de R$ 0,35 em venda de R$ 1,60: comissão 0,35 (não 1,60 x 0,35%)', comissaoDoSplit($fixo_035, 1.60) === 0.35 && comissaoDoSplit($fixo_035, 2.10) === 0.35 && comissaoDoSplit($fixo_035, 197.0) === 0.35);
confereComissao('split percentual de 5%: 5% do valor, em centavos arredondados', comissaoDoSplit($pct_5, 19.90) === 1.0 && comissaoDoSplit($pct_5, 1.60) === 0.08 && comissaoDoSplit($pct_5, 2.10) === 0.11);
confereComissao('split nunca passa do valor da venda', comissaoDoSplit([['valor' => '5.00', 'tipo' => 'fixo']], 1.60) === 1.60 && comissaoDoSplit([['valor' => '150', 'tipo' => 'percentual']], 10.0) === 10.0);
confereComissao('sem split (null, vazio, taxa 0) = 0,00', comissaoDoSplit(null, 10.0) === 0.0 && comissaoDoSplit([], 10.0) === 0.0 && comissaoDoSplit([['valor' => '0', 'tipo' => 'fixo']], 10.0) === 0.0);
confereComissao('tipo ausente conta como percentual (padrão da gateway); item que não é lista é ignorado', comissaoDoSplit([['valor' => '10']], 20.0) === 2.0 && comissaoDoSplit(['lixo', ['valor' => '1', 'tipo' => 'fixo']], 20.0) === 1.0);
confereComissao('vários itens somam (2 recebedores)', comissaoDoSplit([['valor' => '0.35', 'tipo' => 'fixo'], ['valor' => '10', 'tipo' => 'percentual']], 10.0) === 1.35);
require_once __DIR__ . '/../../funcoes/omegapayments_banco.php';
$omega = new OmegaPaymentsBanco('pub', 'sec');
foreach ([[$fixo_035, 1.60], [$fixo_035, 2.10], [$pct_5, 19.90], [$pct_5, 1.60], [[['chave' => 'a', 'valor' => '0.35', 'tipo' => 'fixo'], ['chave' => 'b', 'valor' => '10', 'tipo' => 'percentual']], 10.0], [[['chave' => 'a', 'valor' => '5', 'tipo' => 'fixo']], 1.60]] as [$cfg, $valor_venda]) {
    $payload = $omega->montaPayloadCobranca($valor_venda, '', $cfg, 900, 'Fulano', '12345678909');
    $enviado = round(array_sum(array_column($payload['splits'] ?? [], 'amount')), 2);
    confereComissao('a comissão gravada = a soma dos amount que a cobrança manda (' . json_encode($cfg) . ' em R$ ' . $valor_venda . ')', $enviado === comissaoDoSplit($cfg, $valor_venda), "enviado $enviado, comissão " . comissaoDoSplit($cfg, $valor_venda));
}

echo PHP_EOL . "RESUMO: $ok ok, $falhas falhas" . PHP_EOL;
exit($falhas > 0 ? 1 : 0);
