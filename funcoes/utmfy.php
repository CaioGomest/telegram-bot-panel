<?php

function enviarEventoUtmfy($token, $evento, $dados, $userData = []) {
    if (empty($token)) {
        return ['sucesso' => false, 'erro' => 'Token UTMfy não configurado'];
    }

    $isUrl = filter_var($token, FILTER_VALIDATE_URL);

    if ($isUrl) {
        $url = $token;
        // Payload genérico de webhook para quem configurou URL completa
        $payload = [
            'event' => $evento, // pix_gerado, pix_pago
            'value' => (float)($dados['valor'] ?? 0),
            'currency' => 'BRL',
            'transaction_id' => $dados['transacao_id'] ?? '',
            'email' => $userData['email'] ?? '',
            'phone' => $userData['telefone'] ?? '',
            'ip' => $userData['ip'] ?? '',
            'user_agent' => $userData['user_agent'] ?? '',
            'first_name' => $userData['first_name'] ?? '',
            'utm_source' => $userData['utm_source'] ?? '',
            'utm_campaign' => $userData['utm_campaign'] ?? '',
            'utm_medium' => $userData['utm_medium'] ?? '',
            'utm_content' => $userData['utm_content'] ?? '',
            'utm_term' => $userData['utm_term'] ?? '',
        ];
        $headers = ['Content-Type: application/json'];
    } else {
        // Integração Oficial via API Utmify (Usando o Token)
        $url = "https://api.utmify.com.br/api-credentials/orders";
        
        // Mapeia nossos eventos para o status da API da Utmify
        $statusMap = [
            'pix_gerado' => 'waiting_payment',
            'pix_pago' => 'paid',
            'compra' => 'paid',
            'cadastro' => 'waiting_payment'
        ];
        
        $status = $statusMap[$evento] ?? 'paid';
        $valorCentavos = (int)round((float)($dados['valor'] ?? 0) * 100);
        $dataAtual = gmdate('Y-m-d H:i:s'); // API Utmify exige UTC
        
        $telefone = preg_replace('/[^0-9]/', '', $userData['telefone'] ?? '');
        if (empty($telefone)) {
            $telefone = '00000000000'; // Fallback para não quebrar a API
        }
        
        $email = $userData['email'] ?? '';
        if (empty($email)) {
            // Alguns sistemas exigem e-mail. Se não tivermos, geramos um provisório baseado no ID
            $email = 'cliente' . ($userData['id_telegram'] ?? time()) . '@telegram.com';
        }
        
        $nome = trim($userData['first_name'] ?? '');
        if (empty($nome)) {
            $nome = 'Cliente Telegram';
        }

        $payload = [
            'orderId' => (string)($dados['transacao_id'] ?? time()),
            'platform' => 'BotTelegram',
            'paymentMethod' => 'pix',
            'status' => $status,
            'createdAt' => $dataAtual,
            'approvedDate' => $status === 'paid' ? $dataAtual : null,
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
                    'priceInCents' => $valorCentavos
                ]
            ],
            'commission' => [
                'gatewayFeeInCents' => 0,
                'totalPriceInCents' => $valorCentavos,
                'userCommissionInCents' => $valorCentavos
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
    $codigoHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erroCurl = curl_error($ch);
    curl_close($ch);

    return [
        'sucesso' => $codigoHttp >= 200 && $codigoHttp < 300,
        'resposta' => $resposta ? json_decode($resposta, true) : ['erro' => $erroCurl],
        'codigo' => $codigoHttp,
        'payload' => $payload
    ];
}
