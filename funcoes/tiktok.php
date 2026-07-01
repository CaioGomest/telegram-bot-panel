<?php

function enviarEventoTikTok($pixelId, $accessToken, $evento, $dados, $userData = []) {
    if (empty($pixelId) || empty($accessToken)) {
        return ['sucesso' => false, 'erro' => 'Pixel ID ou Access Token não configurados'];
    }

    $url = "https://business-api.tiktok.com/open_api/v1.3/event/track/";
    
    $timestamp = time();
    
    // Mapeamento de eventos comuns
    $eventoMap = [
        'pix_gerado' => 'InitiateCheckout',
        'pix_pago' => 'CompletePayment', // Ou 'PlaceAnOrder'
        'compra' => 'CompletePayment'
    ];
    
    $nomeEvento = $eventoMap[$evento] ?? $evento;

    $userParams = [];
    if (!empty($userData['email'])) {
        $userParams['email'] = hash('sha256', strtolower(trim($userData['email'])));
    }
    if (!empty($userData['telefone'])) {
        $userParams['phone'] = hash('sha256', preg_replace('/[^0-9]/', '', $userData['telefone']));
    }
    if (!empty($userData['ip'])) {
        $userParams['ip'] = $userData['ip'];
    }
    if (!empty($userData['user_agent'])) {
        $userParams['user_agent'] = $userData['user_agent'];
    }
    if (!empty($userData['id_telegram'])) {
        $userParams['tt_external_id'] = hash('sha256', (string)$userData['id_telegram']);
    }

    $payload = [
        'event_source' => 'web', // Webhook server-side ainda é considerado 'web' ou 'offline'
        'event_source_id' => $pixelId,
        'data' => [
            [
                'event' => $nomeEvento,
                'event_time' => $timestamp,
                'user' => $userParams,
                'properties' => [
                    'currency' => 'BRL',
                    'value' => (float)($dados['valor'] ?? 0),
                    'contents' => [
                        [
                            'content_id' => $dados['produto_id'] ?? '1',
                            'content_name' => $dados['nome_produto'] ?? 'Produto',
                            'quantity' => 1,
                            'price' => (float)($dados['valor'] ?? 0)
                        ]
                    ],
                    'order_id' => $dados['transacao_id'] ?? null
                ],
                'event_id' => $dados['event_id'] ?? ($dados['transacao_id'] ?? null)
            ]
        ]
    ];
    
    // Test Event Code (Opcional, deve ser removido em produção ou configurável)
    // if (!empty($dados['test_event_code'])) {
    //     $payload['test_event_code'] = $dados['test_event_code'];
    // }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Access-Token: ' . $accessToken
    ]);
    
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
