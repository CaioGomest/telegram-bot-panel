<?php

function enviarEventoTikTok($pixel_id, $access_token, $evento, $dados, $user_data = []) {
    if (empty($pixel_id) || empty($access_token)) {
        return ['sucesso' => false, 'erro' => 'Pixel ID ou Access Token não configurados'];
    }

    $url = "https://business-api.tiktok.com/open_api/v1.3/event/track/";
    
    $timestamp = time();
    
    $evento_map = [
        'pix_gerado' => 'InitiateCheckout',
        'pix_pago' => 'CompletePayment', // Ou 'PlaceAnOrder'
        'compra' => 'CompletePayment'
    ];
    
    $nome_evento = $evento_map[$evento] ?? $evento;

    $user_params = [];
    if (!empty($user_data['email'])) {
        $user_params['email'] = hash('sha256', strtolower(trim($user_data['email'])));
    }
    if (!empty($user_data['telefone'])) {
        $user_params['phone'] = hash('sha256', preg_replace('/[^0-9]/', '', $user_data['telefone']));
    }
    if (!empty($user_data['ip'])) {
        $user_params['ip'] = $user_data['ip'];
    }
    if (!empty($user_data['user_agent'])) {
        $user_params['user_agent'] = $user_data['user_agent'];
    }
    if (!empty($user_data['id_telegram'])) {
        $user_params['tt_external_id'] = hash('sha256', (string)$user_data['id_telegram']);
    }

    $payload = [
        'event_source' => 'web', // Webhook server-side ainda é considerado 'web' ou 'offline'
        'event_source_id' => $pixel_id,
        'data' => [
            [
                'event' => $nome_evento,
                'event_time' => $timestamp,
                'user' => $user_params,
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

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Access-Token: ' . $access_token
    ]);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

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
