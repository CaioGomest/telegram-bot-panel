<?php
declare(strict_types=1);

require_once __DIR__ . '/funcoes/usuario.php';
verificarAdmin();

try {
    $stmt = $pdo->query('DESCRIBE membros_grupos');
    $colunas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($colunas as $col) {
        echo $col['Field'] . "\n";
    }
} catch (PDOException $e) {
    error_log("Erro em debug_colunas_grupos.php: " . $e->getMessage());
    echo "Ocorreu um erro ao consultar a tabela. Verifique o log do servidor para detalhes.";
}
