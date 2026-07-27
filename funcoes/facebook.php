<?php

function enviarEventoFacebook($pixel_id, $access_token, $evento, $dados, $user_data = []) {
    if (empty($pixel_id) || empty($access_token)) {
        return ['sucesso' => false, 'erro' => 'Pixel ID ou Access Token não configurados'];
    }

    $url = "https://graph.facebook.com/v19.0/{$pixel_id}/events?access_token={$access_token}";
    
    $timestamp = time();

    $evento_map = [
        'pix_gerado' => 'InitiateCheckout',
        'pix_pago' => 'Purchase',
        'compra' => 'Purchase',
        'cadastro' => 'Lead'
    ];

    $nome_evento = $evento_map[$evento] ?? $evento;

    $user_params = [];
    if (!empty($user_data['email'])) {
        $user_params['em'] = hash('sha256', strtolower(trim($user_data['email'])));
    }
    if (!empty($user_data['telefone'])) {
        $user_params['ph'] = hash('sha256', preg_replace('/[^0-9]/', '', $user_data['telefone']));
    }
    if (!empty($user_data['ip'])) {
        $user_params['client_ip_address'] = $user_data['ip'];
    }
    if (!empty($user_data['user_agent'])) {
        $user_params['client_user_agent'] = $user_data['user_agent'];
    }
    // Telegram ID como External ID é uma boa prática para bots
    if (!empty($user_data['id_telegram'])) {
        $user_params['external_id'] = hash('sha256', (string)$user_data['id_telegram']);
    }

    $payload = [
        'data' => [
            [
                'event_name' => $nome_evento,
                'event_time' => $timestamp,
                'action_source' => 'system_generated', // Como é bot, system_generated ou website se tiver link
                'user_data' => $user_params,
                'custom_data' => [
                    'currency' => 'BRL',
                    'value' => (float)($dados['valor'] ?? 0),
                    'content_name' => $dados['nome_produto'] ?? 'Produto',
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
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
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
