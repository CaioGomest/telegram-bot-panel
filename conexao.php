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
    error_log("Erro na conexão com o banco: " . $e->getMessage());

    // Se o banco não existir, o instalador deve lidar com isso.
    // Para uso normal, pode lançar erro ou redirecionar para instalação.
    if (strpos($e->getMessage(), 'Unknown database') !== false) {
    }
}
