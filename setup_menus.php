<?php
require_once 'conexao.php';

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS menus (
            id INT AUTO_INCREMENT PRIMARY KEY,
            titulo VARCHAR(64) NOT NULL,
            link VARCHAR(128) NOT NULL,
            icone VARCHAR(64) DEFAULT NULL,
            ordem INT DEFAULT 0,
            ativo TINYINT(1) DEFAULT 1,
            apenas_admin TINYINT(1) DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Adicionar a coluna se ela não existir
    try {
        $pdo->exec("ALTER TABLE menus ADD COLUMN apenas_admin TINYINT(1) DEFAULT 0");
    } catch (PDOException $e) {
        // Ignora se já existir
    }

    // Limpa menus existentes para evitar duplicatas na reinicialização (opcional, mas bom para garantir estado)
    $pdo->exec("TRUNCATE TABLE menus");

    $menus = [
        ['titulo' => 'Painel Admin', 'link' => 'admin_dashboard.php', 'icone' => 'dashboard', 'ordem' => 0, 'apenas_admin' => 1],
        ['titulo' => 'Dashboard', 'link' => 'index.php', 'icone' => 'dashboard', 'ordem' => 1, 'apenas_admin' => 0],
        ['titulo' => 'Meus Bots', 'link' => 'bots.php', 'icone' => 'bot', 'ordem' => 2, 'apenas_admin' => 0],
        ['titulo' => 'Fluxos', 'link' => 'fluxos.php', 'icone' => 'flow', 'ordem' => 3, 'apenas_admin' => 0],
        ['titulo' => 'Usuários', 'link' => 'usuarios.php', 'icone' => 'users', 'ordem' => 4, 'apenas_admin' => 1],
        ['titulo' => 'Gateways', 'link' => 'gateways.php', 'icone' => 'payment', 'ordem' => 5, 'apenas_admin' => 0],
        ['titulo' => 'Configurações', 'link' => 'configuracoes.php', 'icone' => 'settings', 'ordem' => 99, 'apenas_admin' => 1]
    ];

    $stmt = $pdo->prepare("INSERT INTO menus (titulo, link, icone, ordem, apenas_admin) VALUES (?, ?, ?, ?, ?)");
    
    foreach ($menus as $m) {
        $stmt->execute([$m['titulo'], $m['link'], $m['icone'], $m['ordem'], $m['apenas_admin']]);
    }

    echo "Tabela de menus criada e populada com sucesso.";

} catch (PDOException $e) {
    die("Erro ao configurar menus: " . $e->getMessage());
}
