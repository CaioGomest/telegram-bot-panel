<?php
require_once __DIR__ . '/facebook.php';
require_once __DIR__ . '/tiktok.php';
require_once __DIR__ . '/utmfy.php';
require_once __DIR__ . '/../conexao.php';

function logTraqueamento($plataforma, $evento, $payload, $resposta, $codigo_http) {
    $diretorio_logs = __DIR__ . '/../logs';
    if (!is_dir($diretorio_logs)) {
        mkdir($diretorio_logs, 0777, true);
    }
    
    $arquivo_log = $diretorio_logs . '/traqueamento.log';
    $data_hora = date('Y-m-d H:i:s');
    
    $log = "[$data_hora] PLATAFORMA: $plataforma | EVENTO: $evento | HTTP_CODE: $codigo_http\n";
    $log .= "PAYLOAD ENVIADO: " . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    $log .= "RESPOSTA RECEBIDA: " . (is_string($resposta) ? $resposta : json_encode($resposta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) . "\n";
    $log .= str_repeat("-", 80) . "\n\n";
    
    file_put_contents($arquivo_log, $log, FILE_APPEND);
}

function enviarEventosTraqueamento($id_usuario, $evento, $dados, $user_data = []) {
    global $pdo;

    $stmt = $pdo->prepare("SELECT * FROM usuarios_traqueamento WHERE id_usuario = ?");
    $stmt->execute([$id_usuario]);
    $config = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$config) {
        return ['sucesso' => false, 'erro' => 'Configuração não encontrada'];
    }

    $resultados = [];

    if (!empty($config['facebook_pixel_id']) && !empty($config['facebook_access_token']) && !empty($config['facebook_ativo'])) {
        $resultados['facebook'] = enviarEventoFacebook(
            $config['facebook_pixel_id'],
            $config['facebook_access_token'],
            $evento,
            $dados,
            $user_data
        );
        logTraqueamento('Facebook', $evento, $resultados['facebook']['payload'] ?? [], $resultados['facebook']['resposta'], $resultados['facebook']['codigo']);
    }

    // ===== TikTok Ads: DESATIVADO =====
    // Comentado em 19/09/2026 a pedido do Caio: ninguém tem conta de TikTok Ads pra
    // validar se o evento chega e se a atribuição funciona. Código que dispara pra uma
    // API de terceiro sem nunca ter sido conferido é pior que código ausente -- "funciona"
    // (devolve HTTP 200) sem ninguém saber se o dado está certo do outro lado.
    //
    // funcoes/tiktok.php continua no repositório, intacto. Pra religar: descomentar este
    // bloco e a seção 'tiktok_fields' em traqueamento.php.
    //
    // if (!empty($config['tiktok_pixel_id']) && !empty($config['tiktok_access_token']) && !empty($config['tiktok_ativo'])) {
    //     $resultados['tiktok'] = enviarEventoTikTok(
    //         $config['tiktok_pixel_id'],
    //         $config['tiktok_access_token'],
    //         $evento,
    //         $dados,
    //         $user_data
    //     );
    //     logTraqueamento('TikTok', $evento, $resultados['tiktok']['payload'] ?? [], $resultados['tiktok']['resposta'], $resultados['tiktok']['codigo']);
    // }

    if (!empty($config['utmfy_token']) && !empty($config['utmfy_ativo'])) {
        $resultados['utmfy'] = enviarEventoUtmfy(
            $config['utmfy_token'],
            $evento,
            $dados,
            $user_data
        );
        logTraqueamento('UTMfy', $evento, $resultados['utmfy']['payload'] ?? [], $resultados['utmfy']['resposta'], $resultados['utmfy']['codigo']);
    }

    return $resultados;
}
