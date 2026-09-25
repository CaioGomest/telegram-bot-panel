<?php
declare(strict_types=1);

/**
 * Criptografia simétrica pra campos sensíveis salvos no banco (credenciais de gateway
 * de pagamento: client_secret, cert_password, chave_pix). A chave fica em config.php
 * (CHAVE_CRIPTOGRAFIA_GATEWAYS), fora do banco — se o banco vazar sozinho, os valores
 * cifrados não servem pra nada sem essa chave.
 *
 * Formato salvo: "enc:v1:" + base64(iv + texto_cifrado).
 * Valor sem esse prefixo é tratado como texto puro legado (dado salvo antes dessa
 * mudança) — descriptografarSegredo() devolve ele como está, sem quebrar nada que
 * já estava salvo.
 */

const CRIPTO_PREFIXO_SEGREDO = 'enc:v1:';
const CRIPTO_METODO_SEGREDO = 'aes-256-cbc';

function chaveCriptografiaDisponivel(): bool
{
    return defined('CHAVE_CRIPTOGRAFIA_GATEWAYS') && CHAVE_CRIPTOGRAFIA_GATEWAYS !== '';
}

function obterChaveCriptografiaGateways(): string
{
    // sha256 deriva uma chave de exatamente 32 bytes (AES-256) a partir do que estiver
    // em config.php, seja qual for o tamanho/formato que a pessoa colocou lá.
    return hash('sha256', CHAVE_CRIPTOGRAFIA_GATEWAYS, true);
}

function criptografarSegredo(?string $valor): string
{
    $valor = (string) $valor;
    if ($valor === '' || !chaveCriptografiaDisponivel()) {
        // Sem chave configurada ainda: mantém o comportamento antigo (texto puro) em vez
        // de travar o salvamento. Assim que a chave for adicionada em config.php, os
        // próximos salvamentos já saem cifrados.
        return $valor;
    }

    try {
        $chave = obterChaveCriptografiaGateways();
        $iv = random_bytes(openssl_cipher_iv_length(CRIPTO_METODO_SEGREDO));
        $cifrado = openssl_encrypt($valor, CRIPTO_METODO_SEGREDO, $chave, OPENSSL_RAW_DATA, $iv);
        if ($cifrado === false) {
            error_log('criptografarSegredo: openssl_encrypt falhou, salvando em texto puro.');
            return $valor;
        }
        return CRIPTO_PREFIXO_SEGREDO . base64_encode($iv . $cifrado);
    } catch (\Throwable $e) {
        error_log('criptografarSegredo: erro (' . $e->getMessage() . '), salvando em texto puro.');
        return $valor;
    }
}

function descriptografarSegredo(?string $valor): string
{
    $valor = (string) $valor;
    if ($valor === '' || strpos($valor, CRIPTO_PREFIXO_SEGREDO) !== 0) {
        return $valor; // Vazio ou texto puro legado — devolve como está.
    }

    if (!chaveCriptografiaDisponivel()) {
        error_log('descriptografarSegredo: valor cifrado encontrado mas CHAVE_CRIPTOGRAFIA_GATEWAYS não está configurada.');
        return '';
    }

    try {
        $chave = obterChaveCriptografiaGateways();
        $bruto = base64_decode(substr($valor, strlen(CRIPTO_PREFIXO_SEGREDO)), true);
        if ($bruto === false) {
            return '';
        }
        $tamanho_iv = openssl_cipher_iv_length(CRIPTO_METODO_SEGREDO);
        $iv = substr($bruto, 0, $tamanho_iv);
        $cifrado = substr($bruto, $tamanho_iv);
        $decifrado = openssl_decrypt($cifrado, CRIPTO_METODO_SEGREDO, $chave, OPENSSL_RAW_DATA, $iv);
        return $decifrado !== false ? $decifrado : '';
    } catch (\Throwable $e) {
        error_log('descriptografarSegredo: erro ao decifrar (' . $e->getMessage() . ').');
        return '';
    }
}

/**
 * Decifra, num array associativo (uma linha de usuarios_gateways), todos os campos
 * conhecidos como sensíveis que estiverem presentes. Usado logo após buscar do banco.
 */
function decifrarCamposGateway(array $linha): array
{
    foreach (['client_secret', 'cert_password', 'chave_pix'] as $campo) {
        if (array_key_exists($campo, $linha)) {
            $linha[$campo] = descriptografarSegredo($linha[$campo]);
        }
    }
    return $linha;
}
