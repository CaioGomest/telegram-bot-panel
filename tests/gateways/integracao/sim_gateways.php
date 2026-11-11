<?php
declare(strict_types=1);

/**
 * APIs simuladas da NxGate e da WiinPay (substitui só o HTTP: montar payload, ler resposta, normalizar e fallback rodam de verdade). Valida o que as
 * documentações dizem que a API recusa (chave, valor mínimo, teto do split da NxGate, campos obrigatórios) e registra no sim.log sem a chave de API.
 *   falhas.json: {"nx:*": "msg"} derruba a NxGate; {"wiin:*": "msg"} derruba a WiinPay; {"nx:timeout": "1"} simula cURL sem resposta.
 *   pagos.txt: ids pagos (a consulta devolve paid / PAID).
 *   wiin_formato.txt: a | b — dois formatos diferentes de resposta da WiinPay (prova a leitura tolerante, já que a doc não define o corpo).
 */
function simResposta(int $codigo, array $corpo): array
{
    return ['codigo_http' => $codigo, 'corpo' => json_encode($corpo, JSON_UNESCAPED_UNICODE), 'erro_curl' => ''];
}

function simGatewayHttp(string $metodo, string $url, ?string $corpo, array $cabecalhos, int $timeout = 15): array
{
    $dados = $corpo !== null ? (json_decode($corpo, true) ?: []) : [];
    $cab = [];
    foreach ($cabecalhos as $c) {
        [$n, $v] = array_pad(explode(':', $c, 2), 2, '');
        $cab[strtolower(trim($n))] = trim($v);
    }
    $host = (string) parse_url($url, PHP_URL_HOST);
    $caminho = (string) parse_url($url, PHP_URL_PATH);
    $pagos = is_file(SIM_DIR . '/pagos.txt') ? file(SIM_DIR . '/pagos.txt', FILE_IGNORE_NEW_LINES) : [];
    $sem_chave = $dados;
    unset($sem_chave['api_key']);

    // ------------------------------------------------------------------ NxGate
    if (str_contains($host, 'nxgate.com.br')) {
        if (sim_falha('nx:timeout')) {
            sim_log(['gw' => 'nx timeout', 'url' => $url]);
            return ['codigo_http' => 0, 'corpo' => '', 'erro_curl' => 'Operation timed out'];
        }
        if ($f = sim_falha('nx:*')) {
            sim_log(['gw' => 'nx ' . $caminho, 'ok' => false, 'erro' => $f]);
            return simResposta(500, ['error' => $f]);
        }
        $chave = (string) ($dados['api_key'] ?? '');
        if (!preg_match('/^(nx_live_|nxg_test_)/', $chave) || $chave === 'nx_live_invalida') {
            return simResposta(401, ['error' => 'API Key invalida']);
        }
        $sandbox = str_contains($host, 'sandbox');
        if ($sandbox !== str_starts_with($chave, 'nxg_test_')) {
            return simResposta(401, ['error' => 'API Key invalida']);
        }
        if ($caminho === '/v2/cobranca') {
            $valor = (float) ($dados['valor'] ?? 0);
            if ($valor < 1.0) {
                return simResposta(422, ['error' => 'Valor invalido']);
            }
            if (empty($dados['webhook']) || !preg_match('#^https?://#', (string) $dados['webhook'])) {
                return simResposta(422, ['error' => 'URL de webhook invalida ou nao permitida']);
            }
            $soma_split = 0;
            foreach (($dados['split_users'] ?? []) as $s) {
                if (empty($s['username']) || !in_array($s['type'] ?? '', ['percentage', 'fixed'], true) || ($s['type'] === 'fixed' && (!is_int($s['value']) || $s['value'] <= 0))) {
                    return simResposta(422, ['error' => 'Dados invalidos', 'detalhes' => ['split_users']]);
                }
                $soma_split += $s['type'] === 'fixed' ? $s['value'] / 100 : $valor * $s['value'] / 100;
            }
            if ($soma_split > $valor * 0.9 + 0.0001) {
                return simResposta(422, ['error' => 'Split excede o teto de 90% do valor']);
            }
            $n = sim_contador('nx');
            $tx = sprintf('e3f1c6a2-58b4-4d0e-9a7c-%012d', $n);
            sim_log(['gw' => 'nx criar', 'valor' => $valor, 'split_users' => $dados['split_users'] ?? null, 'webhook' => $dados['webhook'] ?? null, 'magic_id' => $dados['magic_id'] ?? null, 'doc' => $dados['documento_pagador'] ?? null, 'idem' => $cab['x-idempotency-key'] ?? null, 'sandbox' => $sandbox, 'tx' => $tx, 'ok' => true]);
            $codigo = '00020101021226850014br.gov.bcb.pix' . $tx . '6304A1B2';
            return simResposta(200, ['status' => 'success', 'message' => 'ok', 'paymentCode' => $codigo, 'idTransaction' => $tx, 'paymentCodeBase64' => 'iVBOR', 'currency' => 'BRL', 'paymentMethod' => 'PIX', 'paymentInstruction' => ['type' => 'pix', 'pixCopyAndPaste' => $codigo]]);
        }
        if ($caminho === '/v2/consultar') {
            $tx = (string) ($dados['transaction_id'] ?? '');
            $pago = in_array($tx, $pagos, true);
            sim_log(['gw' => 'nx consulta ' . $tx, 'status' => $pago ? 'paid' : 'waiting']);
            return simResposta(200, ['status' => $pago ? 'paid' : 'waiting', 'transaction_id' => $tx, 'currency' => 'BRL', 'amount' => 29.9, 'payment_method' => 'PIX', 'paid_at' => $pago ? gmdate('c') : null]);
        }
        return simResposta(404, ['error' => 'rota não simulada ' . $caminho]);
    }

    // ------------------------------------------------------------------ WiinPay
    if (str_contains($host, 'wiinpay.com.br')) {
        if ($host !== 'api-v2.wiinpay.com.br') {
            return simResposta(404, ['error' => 'host antigo: a WiinPay agora é api-v2']);
        }
        if ($f = sim_falha('wiin:*')) {
            sim_log(['gw' => 'wiin ' . $caminho, 'ok' => false, 'erro' => $f]);
            return simResposta(500, ['message' => $f]);
        }
        $formato = trim((string) @file_get_contents(SIM_DIR . '/wiin_formato.txt')) ?: 'a';
        if ($caminho === '/payment/create' && $metodo === 'POST') {
            if (($dados['api_key'] ?? '') === '' || ($dados['api_key'] ?? '') === 'wiin_invalida') {
                return simResposta(401, ['message' => 'Chave API inválida']);
            }
            foreach (['value', 'name', 'email', 'description', 'webhook_url'] as $campo) {
                if (empty($dados[$campo])) {
                    return simResposta(422, ['message' => "Campo vazio ou inválido: $campo"]);
                }
            }
            if ((float) $dados['value'] < 3.0) {
                return simResposta(422, ['message' => 'O valor mínimo é R$ 3,00']);
            }
            if (isset($dados['split']) && (empty($dados['split']['user_id']) || !is_string($dados['split']['user_id']))) {
                return simResposta(422, ['message' => 'split.user_id é obrigatório (string)']);
            }
            $n = sim_contador('wiin');
            $id = 'WIINPAY-' . $n;
            sim_log(['gw' => 'wiin criar', 'valor' => $dados['value'], 'split' => $dados['split'] ?? null, 'webhook_url' => $dados['webhook_url'], 'email' => $dados['email'], 'tx' => $id, 'ok' => true, 'sem_chave' => array_keys($sem_chave)]);
            $codigo = '00020126580014BR.GOV.BCB.PIX0136' . $id . '5204000053039865802BR6304ABCD';
            $corpo_resp = $formato === 'a'
                ? ['message' => 'Pagamento gerado com sucesso', 'data' => ['paymentId' => $id, 'qr_code' => $codigo, 'qr_code_base64' => 'iVBOR', 'status' => 'PENDING']]
                : ['id' => $id, 'pix' => ['copia_e_cola' => $codigo], 'status' => 'pending'];
            return simResposta(201, $corpo_resp);
        }
        if (str_starts_with($caminho, '/payment/list/') && $metodo === 'GET') {
            if (($cab['authorization'] ?? '') === '' || !str_starts_with($cab['authorization'], 'Bearer ') || substr($cab['authorization'], 7) === 'wiin_invalida') {
                return simResposta(401, ['message' => 'Não autorizado']);
            }
            $id = rawurldecode(substr($caminho, strlen('/payment/list/')));
            $pago = in_array($id, $pagos, true);
            sim_log(['gw' => 'wiin consulta ' . $id, 'status' => $pago ? 'PAID' : 'PENDING']);
            $registro = ['id' => $id, 'status' => $pago ? ($formato === 'a' ? 'PAID' : 'paid') : 'PENDING', 'value' => 29.9];
            return simResposta(200, $formato === 'a' ? ['data' => $registro] : $registro);
        }
        return simResposta(404, ['message' => 'rota não simulada ' . $caminho]);
    }

    return simResposta(404, ['error' => 'host não simulado ' . $host]);
}
