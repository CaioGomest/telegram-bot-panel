<?php
require_once 'config.php';
try {
    // Força 127.0.0.1 para teste
    $host = '127.0.0.1';
    $dsn = "mysql:host=" . $host . ";dbname=" . BANCO_NOME . ";charset=utf8mb4";
    $pdo = new PDO($dsn, BANCO_USUARIO, BANCO_SENHA);
    $stmt = $pdo->query('DESCRIBE membros_grupos');
    $colunas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($colunas as $col) {
        echo $col['Field'] . "\n";
    }
} catch (Exception $e) {
    echo "Erro: " . $e->getMessage();
}
