<?php
declare(strict_types=1);

/**
 * Leitura tolerante de respostas de gateway cujo formato exato não está na documentação pública (WiinPay): procura por nome de campo em qualquer
 * profundidade e reconhece o código Pix copia-e-cola pelo próprio formato (EMV começa com "000201"). Nunca inventa valor: devolve null/'' se não achar.
 */

/** Valor escalar não vazio de $chave em qualquer profundidade de $dados, ou null. */
function gatewayBuscarChave(array $dados, string $chave): ?string {
    if (isset($dados[$chave]) && is_scalar($dados[$chave]) && (string) $dados[$chave] !== '') {
        return (string) $dados[$chave];
    }
    foreach ($dados as $valor) {
        if (is_array($valor) && ($achado = gatewayBuscarChave($valor, $chave)) !== null) {
            return $achado;
        }
    }
    return null;
}

/** Primeiro valor achado entre várias chaves, respeitando a ordem de prioridade delas. */
function gatewayBuscarValor(array $dados, array $chaves): ?string {
    foreach ($chaves as $chave) {
        $achado = gatewayBuscarChave($dados, $chave);
        if ($achado !== null) {
            return $achado;
        }
    }
    return null;
}

/** Código Pix copia-e-cola: o primeiro texto, em qualquer profundidade, que tenha o formato EMV do Pix (começa com 000201 e tem tamanho de código). */
function gatewayBuscarCodigoPix(array $dados): string {
    foreach ($dados as $valor) {
        if (is_string($valor) && str_starts_with(trim($valor), '000201') && strlen(trim($valor)) >= 50) {
            return trim($valor);
        }
        if (is_array($valor) && ($achado = gatewayBuscarCodigoPix($valor)) !== '') {
            return $achado;
        }
    }
    return '';
}

/** UUID v4 determinístico a partir de uma semente (mesma semente = mesmo UUID): serve de chave de idempotência. */
function gatewayUuidV4(string $semente): string {
    $bytes = md5($semente, true);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

/** Todos os valores escalares não vazios sob qualquer das chaves, em qualquer profundidade, sem repetir (ordem: a das chaves). */
function gatewayColetarValores(array $dados, array $chaves): array {
    $achados = [];
    $varrer = static function (array $no, string $chave) use (&$varrer, &$achados): void {
        if (isset($no[$chave]) && is_scalar($no[$chave]) && (string) $no[$chave] !== '') {
            $achados[] = (string) $no[$chave];
        }
        foreach ($no as $valor) {
            if (is_array($valor)) {
                $varrer($valor, $chave);
            }
        }
    };
    foreach ($chaves as $chave) {
        $varrer($dados, $chave);
    }
    return array_values(array_unique($achados));
}
