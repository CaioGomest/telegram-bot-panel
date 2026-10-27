<?php
declare(strict_types=1);

/**
 * Validação do split padrão de um gateway (Admin → Gateways → "Split padrão"). Função pura: sem banco, sem sessão.
 *
 * O que vale para o split é decidido AQUI, no servidor. O `<input type="number" min=0 max=100>` da tela só ajuda quem usa o
 * navegador: um POST direto passava por cima dele e o servidor gravava percentual 150 (nenhum preço passa a ser vendável),
 * valor negativo (o split some calado), 1000 (virava 999,99 pelo limite do DECIMAL(5,2)) e texto (virava 0,00).
 */

/** Teto do percentual (% do valor da venda). */
const SPLIT_PERCENTUAL_MAXIMO = 100.0;

/** Teto do valor fixo por venda, em reais. Mesmo número do `max` do campo da tela. */
const SPLIT_FIXO_MAXIMO = 100.0;

/**
 * @param string $taxa_bruta número como veio do formulário (aceita vírgula; vazio = sem split)
 * @param string $tipo       'percentual' ou 'fixo'
 * @param string $chave      ID do recebedor no gateway (producerId); vazio = nenhum split é feito
 * @return array{0: ?array{taxa: float, tipo: string, chave: string}, 1: ?string} [dados prontos para gravar, mensagem de erro]
 */
function validarSplitGateway(string $taxa_bruta, string $tipo, string $chave): array
{
    $tipo = trim($tipo);
    if (!in_array($tipo, ['percentual', 'fixo'], true)) {
        return [null, 'Tipo de split inválido: escolha "% do valor" ou "R$ fixo".'];
    }

    $taxa_texto = str_replace(',', '.', trim($taxa_bruta));
    if ($taxa_texto === '') {
        $taxa_texto = '0';
    }
    // Só número positivo com até 2 casas: sem sinal, sem notação científica, sem texto.
    if (!preg_match('/^\d{1,4}(\.\d{1,2})?$/', $taxa_texto)) {
        return [null, 'Taxa do split inválida: use só números, sem sinal, com no máximo 2 casas decimais (ex.: 1,40).'];
    }
    $taxa = (float) $taxa_texto;
    if ($tipo === 'percentual' && $taxa > SPLIT_PERCENTUAL_MAXIMO) {
        return [null, 'O split em percentual não pode passar de 100%.'];
    }
    if ($tipo === 'fixo' && $taxa > SPLIT_FIXO_MAXIMO) {
        return [null, 'O split fixo não pode passar de R$ 100,00 por venda.'];
    }

    $chave = trim($chave);
    if ($chave !== '') {
        if (!mb_check_encoding($chave, 'UTF-8') || strlen($chave) > 255) {
            return [null, 'ID do recebedor do split inválido: texto de até 255 caracteres.'];
        }
        if (preg_match('/[\s\x00-\x1f\x7f]/u', $chave)) {
            return [null, 'ID do recebedor do split inválido: sem espaços nem caracteres de controle.'];
        }
    }

    return [['taxa' => round($taxa, 2), 'tipo' => $tipo, 'chave' => $chave], null];
}

/**
 * Quanto do valor da venda um item do split leva, em reais (a conta que a OmegaPayments recebe em `splits[].amount`): 'fixo' = a taxa em reais, senão a taxa
 * é um percentual do valor; nunca passa do valor da venda. Fica aqui, sem banco, para a cobrança enviada à gateway e a comissão gravada na venda
 * (comissaoDoSplit) usarem exatamente a mesma conta.
 * @param array{valor?: mixed, tipo?: mixed} $item_split item no formato de montaSplits(): 'valor' (a taxa) e 'tipo' ('percentual' ou 'fixo')
 */
function valorDaFatiaDoSplit(array $item_split, float $valor_venda): float
{
    $taxa = (float) ($item_split['valor'] ?? 0);
    $fatia = ($item_split['tipo'] ?? 'percentual') === 'fixo' ? $taxa : $valor_venda * $taxa / 100;
    return round(max(0.0, min($fatia, $valor_venda)), 2);
}

/**
 * Comissão da plataforma numa venda = soma do que o split mandado na cobrança leva. É o valor que vai em vendas.comissao_admin (gravado na criação da
 * cobrança: a OmegaPayments divide o dinheiro no pagamento e não devolve esse número na consulta da transação, então esta é a única fonte).
 * Sem split (null/vazio) = 0,00.
 * @param array<int, array{valor?: mixed, tipo?: mixed}>|null $split_config
 */
function comissaoDoSplit(?array $split_config, float $valor_venda): float
{
    $total = 0.0;
    foreach ((array) $split_config as $item) {
        if (is_array($item)) {
            $total += valorDaFatiaDoSplit($item, $valor_venda);
        }
    }
    return round($total, 2);
}
