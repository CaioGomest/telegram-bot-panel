<?php
declare(strict_types=1);

// Adiciona coluna invite_link na tabela membros_grupos para permitir revogação de links
require_once __DIR__ . '/funcoes/usuario.php';
verificarAdmin();

echo "<h1>Atualização de Segurança</h1>";

try {
    $stmt = $pdo->query("SHOW COLUMNS FROM membros_grupos LIKE 'invite_link'");
    $coluna = $stmt->fetch();

    if (!$coluna) {
        echo "<p>Adicionando coluna 'invite_link' na tabela 'membros_grupos'...</p>";
        $sql = "ALTER TABLE membros_grupos ADD COLUMN invite_link VARCHAR(255) DEFAULT NULL AFTER venda_id";
        $pdo->exec($sql);
        echo "<p style='color: green'>Coluna adicionada com sucesso!</p>";
    } else {
        echo "<p style='color: blue'>A coluna 'invite_link' já existe.</p>";
    }
    
    echo "<p>Banco de dados atualizado.</p>";
    echo "<p>Agora o sistema irá salvar os links de convite e revogá-los quando o acesso expirar.</p>";
    
} catch (PDOException $e) {
    error_log("Erro em atualizacao_seguranca.php: " . $e->getMessage());
    echo "<p style='color: red'>Erro ao atualizar banco. Verifique o log do servidor para detalhes.</p>";
}
