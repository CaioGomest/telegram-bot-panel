<?php

/**
 * Bloqueia SSRF no campo "Token ou URL de Postback": recusa qualquer URL que resolva pra
 * um host interno/privado (loopback, rede local, link-local) antes de disparar a requisição.
 * Quem configura esse campo é o próprio dono do bot (não confiável do ponto de vista do
 * servidor) e a requisição é feita pelo próprio servidor da plataforma — sem essa checagem,
 * dava pra usar o servidor como ponte pra alcançar rede interna. Ver
 * anotacoes/varredura-09-xss-admin-ssrf-utmfy.md.
 */
function urlPostbackEhSegura(string $url): bool
{
    $partes = parse_url($url);
    if (!$partes || empty($partes['host']) || empty($partes['scheme'])) {
        return false;
    }
    if (!in_array(strtolower($partes['scheme']), ['http', 'https'], true)) {
        return false;
    }

    $host = trim($partes['host'], '[]');

    // Host já é um literal de IP (ex. "127.0.0.1", "::1")
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    // Resolve o hostname e recusa se QUALQUER IP resolvido cair em faixa privada/reservada
    $ips = [];
    foreach ((@dns_get_record($host, DNS_A) ?: []) as $registro) {
        if (!empty($registro['ip'])) $ips[] = $registro['ip'];
    }
    foreach ((@dns_get_record($host, DNS_AAAA) ?: []) as $registro) {
        if (!empty($registro['ipv6'])) $ips[] = $registro['ipv6'];
    }
    if (empty($ips)) {
        $ipv4 = @gethostbyname($host);
        if ($ipv4 && $ipv4 !== $host) {
            $ips[] = $ipv4;
        }
    }

    if (empty($ips)) {
        return false; // não conseguiu resolver o host — não arrisca deixar passar
    }
    foreach ($ips as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
    }
    return true;
}

function enviarEventoUtmfy($token, $evento, $dados, $user_data = []) {
    if (empty($token)) {
        return ['sucesso' => false, 'erro' => 'Token UTMfy não configurado'];
    }

    $is_url = filter_var($token, FILTER_VALIDATE_URL);

    if ($is_url) {
        $url = $token;
        if (!urlPostbackEhSegura($url)) {
            return ['sucesso' => false, 'erro' => 'URL de postback recusada (aponta para host interno/privado).'];
        }
        // Payload genérico de webhook para quem configurou URL completa
        $payload = [
            'event' => $evento,
            'value' => (float)($dados['valor'] ?? 0),
            'currency' => 'BRL',
            'transaction_id' => $dados['transacao_id'] ?? '',
            'email' => $user_data['email'] ?? '',
            'phone' => $user_data['telefone'] ?? '',
            'ip' => $user_data['ip'] ?? '',
            'user_agent' => $user_data['user_agent'] ?? '',
            'first_name' => $user_data['first_name'] ?? '',
            'utm_source' => $user_data['utm_source'] ?? '',
            'utm_campaign' => $user_data['utm_campaign'] ?? '',
            'utm_medium' => $user_data['utm_medium'] ?? '',
            'utm_content' => $user_data['utm_content'] ?? '',
            'utm_term' => $user_data['utm_term'] ?? '',
        ];
        $headers = ['Content-Type: application/json'];
    } else {
        // Integração Oficial via API Utmify (Usando o Token)
        $url = "https://api.utmify.com.br/api-credentials/orders";
        
        $status_map = [
            'pix_gerado' => 'waiting_payment',
            'pix_pago' => 'paid',
            'compra' => 'paid',
            'cadastro' => 'waiting_payment'
        ];
        
        // Evento que nao esta no mapa NAO vira venda paga. O padrao era 'paid': bastava
        // alguem criar um evento novo e esquecer de mapear pra ele entrar na UTMify como
        // faturamento aprovado. Erro que inventa receita e' o pior tipo num relatorio.
        if (!isset($status_map[$evento])) {
            return ['sucesso' => false, 'erro' => "Evento '$evento' nao mapeado para status da UTMify.", 'codigo' => 0];
        }
        $status = $status_map[$evento];
        $valor_centavos = (int)round((float)($dados['valor'] ?? 0) * 100);
        $comissao_centavos = (int)round((float)($dados['comissao'] ?? 0) * 100);
        $data_atual = gmdate('Y-m-d H:i:s'); // API Utmify exige UTC
        $data_pagamento = !empty($dados['pago_em'])
            ? gmdate('Y-m-d H:i:s', strtotime($dados['pago_em']))
            : null;
        
        $telefone = preg_replace('/[^0-9]/', '', $user_data['telefone'] ?? '');

        // O e-mail provisorio usava '@telegram.com', que e' um dominio real de terceiro:
        // qualquer disparo feito em cima dessa base sairia pra um dominio que nao e' nosso,
        // e o cliente terminava com contato falso no CRM. '.invalid' e' reservado pra isto
        // (RFC 2606) e nunca vai existir de verdade.
        $email = trim($user_data['email'] ?? '');
        if ($email === '' && !empty($user_data['id_telegram'])) {
            $email = 'telegram-' . $user_data['id_telegram'] . '@nao-informado.invalid';
        }
        
        $nome = trim($user_data['first_name'] ?? '');
        if (empty($nome)) {
            $nome = 'Cliente Telegram';
        }

        $payload = [
            'orderId' => (string)($dados['transacao_id'] ?? time()),
            'platform' => 'BotTelegram',
            'paymentMethod' => 'pix',
            'status' => $status,
            'createdAt' => $data_atual,
            // Usa a data real do pagamento quando ela existe; cair sempre em "agora" datava
            // errado todo pagamento processado com atraso (webhook lento, cron de retry).
            'approvedDate' => $status === 'paid' ? ($data_pagamento ?: $data_atual) : null,
            'customer' => [
                'name' => $nome,
                'email' => $email,
                'phone' => $telefone !== '' ? $telefone : null,
                'document' => null
            ],
            'products' => [
                [
                    // id/planId eram '1' fixo pra todo produto, entao um bot com varios planos
                    // virava um produto so' no relatorio da UTMify -- justo a analise de qual
                    // oferta converte melhor.
                    'id' => (string) ($dados['produto_id'] ?? $dados['plano_id'] ?? '1'),
                    'name' => $dados['nome_produto'] ?? 'Produto',
                    'planId' => (string) ($dados['plano_id'] ?? $dados['produto_id'] ?? '1'),
                    'planName' => $dados['nome_produto'] ?? 'Unico',
                    'quantity' => 1,
                    'priceInCents' => $valor_centavos
                ]
            ],
            // Antes declarava taxa zero e 100% do valor pro vendedor, o que inflava o lucro
            // no relatorio. A comissao da plataforma ja existe em vendas.comissao_admin -- o
            // dado estava no banco, so' nao era passado.
            'commission' => [
                'gatewayFeeInCents' => $comissao_centavos,
                'totalPriceInCents' => $valor_centavos,
                'userCommissionInCents' => max(0, $valor_centavos - $comissao_centavos)
            ],
            // Estavam todas fixas em null: a integracao se chama UTMify e nao mandava UTM
            // nenhuma, ou seja, a UTMify recebia uma lista de pedidos sem origem. O ramo de
            // URL de postback (mais acima) ja lia esses mesmos campos de $user_data.
            'trackingParameters' => [
                'utm_campaign' => $user_data['utm_campaign'] ?? null,
                'utm_content'  => $user_data['utm_content']  ?? null,
                'utm_medium'   => $user_data['utm_medium']   ?? null,
                'utm_source'   => $user_data['utm_source']   ?? null,
                'utm_term'     => $user_data['utm_term']     ?? null
            ]
        ];

        $headers = [
            'Content-Type: application/json',
            'x-api-token: ' . trim($token)
        ];
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);  // faltava: isto roda dentro do webhook,
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);        // que precisa responder rapido
    
    $resposta = curl_exec($ch);
    $codigo_http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erro_curl = curl_error($ch);
    curl_close($ch);

    return [
        'sucesso' => $codigo_http >= 200 && $codigo_http < 300,
        'resposta' => $resposta ? json_decode($resposta, true) : ['erro' => $erro_curl],
        'codigo' => $codigo_http,
        'payload' => $payload
    ];
}
