<?php
require_once __DIR__ . '/facebook.php';
require_once __DIR__ . '/tiktok.php';
require_once __DIR__ . '/utmfy.php';
require_once __DIR__ . '/../conexao.php';

function logTraqueamento($plataforma, $evento, $payload, $resposta, $codigoHttp) {
    $diretorioLogs = __DIR__ . '/../logs';
    if (!is_dir($diretorioLogs)) {
        mkdir($diretorioLogs, 0777, true);
    }
    
    $arquivoLog = $diretorioLogs . '/traqueamento.log';
    $dataHora = date('Y-m-d H:i:s');
    
    $log = "[$dataHora] PLATAFORMA: $plataforma | EVENTO: $evento | HTTP_CODE: $codigoHttp\n";
    $log .= "PAYLOAD ENVIADO: " . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    $log .= "RESPOSTA RECEBIDA: " . (is_string($resposta) ? $resposta : json_encode($resposta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) . "\n";
    $log .= str_repeat("-", 80) . "\n\n";
    
    file_put_contents($arquivoLog, $log, FILE_APPEND);
}

function enviarEventosTraqueamento($idUsuario, $evento, $dados, $userData = []) {
    global $pdo;

    // Buscar configurações de traqueamento do usuário
    $stmt = $pdo->prepare("SELECT * FROM usuarios_traqueamento WHERE id_usuario = ?");
    $stmt->execute([$idUsuario]);
    $config = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$config) {
        return ['sucesso' => false, 'erro' => 'Configuração não encontrada'];
    }

    $resultados = [];

    // Facebook Pixel / CAPI
    if (!empty($config['facebook_pixel_id']) && !empty($config['facebook_access_token']) && !empty($config['facebook_ativo'])) {
        $resultados['facebook'] = enviarEventoFacebook(
            $config['facebook_pixel_id'],
            $config['facebook_access_token'],
            $evento,
            $dados,
            $userData
        );
        logTraqueamento('Facebook', $evento, $resultados['facebook']['payload'] ?? [], $resultados['facebook']['resposta'], $resultados['facebook']['codigo']);
    }

    // TikTok Pixel / Events API
    if (!empty($config['tiktok_pixel_id']) && !empty($config['tiktok_access_token']) && !empty($config['tiktok_ativo'])) {
        $resultados['tiktok'] = enviarEventoTikTok(
            $config['tiktok_pixel_id'],
            $config['tiktok_access_token'],
            $evento,
            $dados,
            $userData
        );
        logTraqueamento('TikTok', $evento, $resultados['tiktok']['payload'] ?? [], $resultados['tiktok']['resposta'], $resultados['tiktok']['codigo']);
    }

    // UTMfy
    if (!empty($config['utmfy_token']) && !empty($config['utmfy_ativo'])) {
        $resultados['utmfy'] = enviarEventoUtmfy(
            $config['utmfy_token'],
            $evento,
            $dados,
            $userData
        );
        logTraqueamento('UTMfy', $evento, $resultados['utmfy']['payload'] ?? [], $resultados['utmfy']['resposta'], $resultados['utmfy']['codigo']);
    }

    return $resultados;
}
