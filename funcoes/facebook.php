<?php

function enviarEventoFacebook($pixelId, $accessToken, $evento, $dados, $userData = []) {
    if (empty($pixelId) || empty($accessToken)) {
        return ['sucesso' => false, 'erro' => 'Pixel ID ou Access Token não configurados'];
    }

    $url = "https://graph.facebook.com/v19.0/{$pixelId}/events?access_token={$accessToken}";
    
    $timestamp = time();

    // Mapeamento de eventos padrão do Facebook
    $eventoMap = [
        'pix_gerado' => 'InitiateCheckout',
        'pix_pago' => 'Purchase',
        'compra' => 'Purchase',
        'cadastro' => 'Lead'
    ];
    
    // Se não houver mapeamento, usa o nome original (Custom Event)
    $nomeEvento = $eventoMap[$evento] ?? $evento;
    
    // Preparar dados do usuário (hashing necessário para alguns campos)
    $userParams = [];
    if (!empty($userData['email'])) {
        $userParams['em'] = hash('sha256', strtolower(trim($userData['email'])));
    }
    if (!empty($userData['telefone'])) {
        $userParams['ph'] = hash('sha256', preg_replace('/[^0-9]/', '', $userData['telefone']));
    }
    if (!empty($userData['ip'])) {
        $userParams['client_ip_address'] = $userData['ip'];
    }
    if (!empty($userData['user_agent'])) {
        $userParams['client_user_agent'] = $userData['user_agent'];
    }
    // Telegram ID como External ID é uma boa prática para bots
    if (!empty($userData['id_telegram'])) {
        $userParams['external_id'] = hash('sha256', (string)$userData['id_telegram']);
    }

    $payload = [
        'data' => [
            [
                'event_name' => $nomeEvento,
                'event_time' => $timestamp,
                'action_source' => 'system_generated', // Como é bot, system_generated ou website se tiver link
                'user_data' => $userParams,
                'custom_data' => [
                    'currency' => 'BRL',
                    'value' => (float)($dados['valor'] ?? 0),
                    'content_name' => $dados['nome_produto'] ?? 'Produto',
                    'order_id' => $dados['transacao_id'] ?? null
                ],
                'event_id' => $dados['event_id'] ?? ($dados['transacao_id'] ?? null)
            ]
        ]
        // 'test_event_code' => 'TEST12345' // Útil para testes
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
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
