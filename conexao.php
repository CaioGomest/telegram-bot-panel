<?php
require_once __DIR__ . '/config.php';

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
