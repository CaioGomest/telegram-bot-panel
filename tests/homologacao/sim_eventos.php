<?php
// Facebook (Conversions API) e UTMify simulados. Carregado por sim.php.
// Substitui só o HTTP: montagem do payload, mapeamento de eventos, criptografia dos tokens,
// log e a escolha de quem recebe o quê rodam de verdade. O simulador recusa o que as APIs recusariam.
// Falhas injetadas (falhas.json): {"evento:facebook":"500", "evento:utmfy":"400", "evento:*":"500"}.

function sim_hex64($v): bool { return is_string($v) && preg_match('/^[a-f0-9]{64}$/', $v) === 1; }

function sim_valida_facebook(string $url, array $p): ?string {
    if (!preg_match('#^https://graph\.facebook\.com/v\d+\.\d+/[A-Za-z0-9]+/events$#', $url)) return 'URL inesperada: ' . $url;
    if (empty($p['access_token'])) return '(#190) access_token ausente';
    if (empty($p['data'][0])) return 'data vazio';
    $e = $p['data'][0];
    if (!in_array($e['event_name'] ?? '', ['InitiateCheckout', 'Purchase', 'Lead'], true)) return 'event_name inválido';
    if (!is_int($e['event_time'] ?? null) || abs(time() - $e['event_time']) > 7 * 86400) return 'event_time inválido';
    if (!in_array($e['action_source'] ?? '', ['email', 'website', 'app', 'phone_call', 'chat', 'physical_store', 'system_generated', 'business_messaging', 'other'], true)) return 'action_source inválido';
    $u = $e['user_data'] ?? null;
    if (!is_array($u) || !$u) return 'user_data vazio (nada para casar o evento)';
    foreach (['em', 'ph', 'fn', 'external_id'] as $k) {
        if (isset($u[$k]) && !sim_hex64($u[$k])) return "user_data.$k não está em SHA-256";
    }
    $c = $e['custom_data'] ?? [];
    if (($c['currency'] ?? '') !== 'BRL') return 'currency deve ser BRL';
    if (!is_float($c['value'] ?? null) && !is_int($c['value'] ?? null)) return 'value precisa ser número';
    if ($e['event_name'] === 'Purchase' && $c['value'] <= 0) return 'Purchase exige value > 0';
    foreach ($c as $k => $v) {
        if ($v === null) return "custom_data.$k é null";
    }
    if (array_key_exists('event_id', $e) && !is_string($e['event_id'])) return 'event_id deve ser string';
    return null;
}

function sim_valida_utmify(array $h, array $p): ?string {
    $tem = false;
    foreach ($h as $l) {
        if (stripos($l, 'x-api-token:') === 0 && trim(substr($l, 12)) !== '') $tem = true;
    }
    if (!$tem) return 'x-api-token ausente';
    foreach (['orderId', 'platform', 'paymentMethod', 'status', 'createdAt', 'customer', 'products', 'commission', 'trackingParameters'] as $k) {
        if (!array_key_exists($k, $p)) return "campo $k ausente";
    }
    if (!is_string($p['orderId']) || $p['orderId'] === '') return 'orderId inválido';
    if (!in_array($p['status'], ['waiting_payment', 'paid', 'refused', 'refunded', 'chargedback'], true)) return 'status inválido';
    $fmt = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/';
    if (!preg_match($fmt, (string) $p['createdAt'])) return 'createdAt fora do formato UTC Y-m-d H:i:s';
    if ($p['status'] === 'paid' && !preg_match($fmt, (string) ($p['approvedDate'] ?? ''))) return 'paid exige approvedDate';
    if ($p['status'] !== 'paid' && ($p['approvedDate'] ?? null) !== null) return 'approvedDate só existe em pedido pago';
    $c = $p['customer'];
    if (empty($c['name']) || empty($c['email']) || !filter_var($c['email'], FILTER_VALIDATE_EMAIL)) return 'customer.name/email inválidos';
    $pr = $p['products'][0] ?? null;
    if (!$pr || !is_int($pr['priceInCents'] ?? null) || $pr['priceInCents'] <= 0) return 'products[0].priceInCents inválido';
    foreach (['id', 'name', 'planId', 'planName'] as $k) {
        if (!is_string($pr[$k] ?? null) || $pr[$k] === '') return "products[0].$k inválido";
    }
    $m = $p['commission'];
    foreach (['gatewayFeeInCents', 'totalPriceInCents', 'userCommissionInCents'] as $k) {
        if (!is_int($m[$k] ?? null) || $m[$k] < 0) return "commission.$k inválido";
    }
    if ($m['totalPriceInCents'] !== $pr['priceInCents']) return 'commission.totalPriceInCents difere do preço';
    if ($m['userCommissionInCents'] + $m['gatewayFeeInCents'] !== $m['totalPriceInCents']) return 'comissão não fecha com o total';
    foreach (['utm_campaign', 'utm_content', 'utm_medium', 'utm_source', 'utm_term'] as $k) {
        if (!array_key_exists($k, $p['trackingParameters'])) return "trackingParameters.$k ausente";
    }
    return null;
}

/** Devolve o corpo (JSON) que a API devolveria e deixa o HTTP em $GLOBALS['SIM_HTTP_EVENTO']. */
function simEventoHttp(string $plataforma, string $url, array $payload, array $headers = []): string {
    $falha = sim_falha('evento:' . $plataforma) ?? sim_falha('evento:*');
    $erro = null;
    $http = 200;
    if ($falha !== null) {
        $http = (int) $falha ?: 500;
        $erro = 'falha injetada HTTP ' . $http;
    } elseif ($plataforma === 'facebook') {
        $erro = sim_valida_facebook($url, $payload);
    } elseif (strpos($url, 'api.utmify.com.br/api-credentials/orders') !== false) {
        $erro = sim_valida_utmify($headers, $payload);
    }
    if ($erro !== null && $http === 200) $http = 400;
    $GLOBALS['SIM_HTTP_EVENTO'] = $http;
    $copia = $payload;
    if (isset($copia['access_token'])) $copia['access_token'] = '***';
    sim_log(['evt' => $plataforma, 'url' => $url, 'ok' => $erro === null, 'http' => $http, 'erro' => $erro, 'payload' => $copia, 'token' => $payload['access_token'] ?? null, 'headers' => $headers]);
    return $erro === null ? json_encode(['events_received' => 1, 'ok' => true]) : json_encode(['error' => ['message' => $erro]]);
}
