<?php
require_once __DIR__ . '/config.php';

// PHP não tinha fuso definido (ficava no default do servidor, UTC) enquanto o MySQL já
// era fixado em -03:00 logo abaixo -- num período de ~3h por dia (21h-23:59 em Brasília,
// que já é madrugada do dia seguinte em UTC), date()/strtotime() no PHP calculavam "hoje"
// um dia à frente do CURDATE() do MySQL. Isso desalinhava qualquer filtro de período que
// combina data calculada em PHP com dado buscado por data no banco (ex. os gráficos "8
// dias"/"30 dias" de index.php/admin/dashboard.php, que geram os rótulos dos dias em PHP
// e buscam os valores em MySQL) -- achado ao conferir a correção do filtro "8 dias" (ver
// anotacoes/pedido-filtro-periodo-dashboard.md): o total do card não batia com a soma dos
// pontos do gráfico, e o motivo era esse desalinhamento de fuso, não um erro de cálculo.
date_default_timezone_set('America/Sao_Paulo');

try {
    $dsn = "mysql:host=" . BANCO_HOST . ";dbname=" . BANCO_NOME . ";charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    $pdo = new PDO($dsn, BANCO_USUARIO, BANCO_SENHA, $options);
    $pdo->exec("SET time_zone = '-03:00'");
} catch (PDOException $e) {
    // Antes isto só logava e seguia em frente, deixando $pdo indefinido. O efeito era que o
    // erro não aparecia aqui: estourava lá adiante como "Call to a member function prepare()
    // on null", num arquivo qualquer, sem dizer que o problema era o banco. Foi exatamente o
    // que mascarou o incidente de 19/09 (ver anotacoes/urgente/incidente-config-php-sobrescrito.md).
    //
    // Agora para aqui. O detalhe vai pro log; quem está do outro lado recebe uma mensagem
    // honesta e nenhum dado de conexão (usuário/host já vazaram pra tela uma vez).
    error_log('Erro na conexão com o banco: ' . $e->getMessage());

    // log_errors está Off neste servidor, então o error_log acima pode não ir a lugar nenhum.
    // Este arquivo é a garantia de que sobra rastro.
    @file_put_contents(
        __DIR__ . '/logs/conexao_falhou.log',
        '[' . date('Y-m-d H:i:s') . '] ' . $e->getMessage() . "\n",
        FILE_APPEND
    );

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Erro: sem conexão com o banco de dados.\n");
        exit(1);
    }

    http_response_code(503);
    header('Retry-After: 120');
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
       . '<title>Fora do ar</title></head>'
       . '<body style="font-family:system-ui,sans-serif;background:#14161c;color:#e8e8e8;'
       . 'display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:24px;text-align:center;">'
       . '<div><h1 style="font-size:20px;margin:0 0 10px;">Sistema temporariamente fora do ar</h1>'
       . '<p style="color:#8b9099;margin:0;line-height:1.6;">Não foi possível conectar ao banco de dados.<br>'
       . 'Tente de novo em alguns minutos.</p></div></body></html>';
    exit;
}
