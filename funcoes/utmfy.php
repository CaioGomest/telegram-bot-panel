<?php

function enviarEventoUtmfy($token, $evento, $dados, $user_data = []) {
    if (empty($token)) {
        return ['sucesso' => false, 'erro' => 'Token UTMfy não configurado'];
    }

    $is_url = filter_var($token, FILTER_VALIDATE_URL);

    if ($is_url) {
        $url = $token;
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
        
        $status = $status_map[$evento] ?? 'paid';
        $valor_centavos = (int)round((float)($dados['valor'] ?? 0) * 100);
        $data_atual = gmdate('Y-m-d H:i:s'); // API Utmify exige UTC
        
        $telefone = preg_replace('/[^0-9]/', '', $user_data['telefone'] ?? '');
        if (empty($telefone)) {
            $telefone = '00000000000'; // Fallback para não quebrar a API
        }
        
        $email = $user_data['email'] ?? '';
        if (empty($email)) {
            // Alguns sistemas exigem e-mail. Se não tivermos, geramos um provisório baseado no ID
            $email = 'cliente' . ($user_data['id_telegram'] ?? time()) . '@telegram.com';
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
            'approvedDate' => $status === 'paid' ? $data_atual : null,
            'customer' => [
                'name' => $nome,
                'email' => $email,
                'phone' => $telefone,
                'document' => null
            ],
            'products' => [
                [
                    'id' => '1',
                    'name' => $dados['nome_produto'] ?? 'Produto',
                    'planId' => '1',
                    'planName' => 'Unico',
                    'quantity' => 1,
                    'priceInCents' => $valor_centavos
                ]
            ],
            'commission' => [
                'gatewayFeeInCents' => 0,
                'totalPriceInCents' => $valor_centavos,
                'userCommissionInCents' => $valor_centavos
            ],
            'trackingParameters' => [
                'utm_campaign' => null,
                'utm_content' => null,
                'utm_medium' => null,
                'utm_source' => null,
                'utm_term' => null
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
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    
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
