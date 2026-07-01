<?php
require 'conexao.php';

try {
    echo "Banco conectado: " . $pdo->query('select database()')->fetchColumn() . "\n";
    
    $stmt = $pdo->query("SHOW COLUMNS FROM vendas");
    $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "Colunas na tabela vendas:\n";
    foreach ($cols as $col) {
        echo "- $col\n";
    }
    
    if (!in_array('tempo_expiracao_minutos', $cols)) {
        echo "\nColuna 'tempo_expiracao_minutos' NÃO ENCONTRADA! Tentando adicionar...\n";
        $pdo->exec("ALTER TABLE vendas ADD COLUMN tempo_expiracao_minutos INT DEFAULT 15 AFTER transacao_id");
        echo "Comando ALTER TABLE executado.\n";
    } else {
        echo "\nColuna 'tempo_expiracao_minutos' JÁ EXISTE.\n";
    }

    if (!in_array('id_operador_fluxo', $cols)) {
        echo "\nColuna 'id_operador_fluxo' NÃO ENCONTRADA! Tentando adicionar...\n";
        $pdo->exec("ALTER TABLE vendas ADD COLUMN id_operador_fluxo VARCHAR(50) NULL AFTER transacao_id");
        echo "Comando ALTER TABLE executado.\n";
    } else {
        echo "\nColuna 'id_operador_fluxo' JÁ EXISTE.\n";
    }

} catch (PDOException $e) {
    echo "Erro: " . $e->getMessage();
}
