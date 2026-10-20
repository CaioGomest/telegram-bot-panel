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
