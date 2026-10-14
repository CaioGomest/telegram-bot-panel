<?php
// Mesma migração de admin/atualiza_banco.php (leads_starts + preenchimento a partir de leads), para o servidor de homologação.
chdir(__DIR__); require_once 'conexao.php';
$existia = (bool) $pdo->query("SHOW TABLES LIKE 'leads_starts'")->fetchColumn();
$pdo->exec("CREATE TABLE IF NOT EXISTS leads_starts (
    id BIGINT AUTO_INCREMENT PRIMARY KEY, lead_id INT NOT NULL, bot_id INT NOT NULL, criado_em DATETIME NOT NULL,
    INDEX idx_starts_bot_data (bot_id, criado_em), INDEX idx_starts_lead (lead_id, criado_em),
    FOREIGN KEY (bot_id) REFERENCES bots(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
if (!$existia) {
    $max = (int) $pdo->query("SELECT COALESCE(MAX(id),0) FROM leads")->fetchColumn();
    for ($ini = 0; $ini < $max; $ini += 50000) {
        $pdo->exec("INSERT INTO leads_starts (lead_id, bot_id, criado_em) SELECT id, bot_id, criado_em FROM leads WHERE bot_id IS NOT NULL AND id > $ini AND id <= " . ($ini + 50000));
    }
}
echo ($existia ? 'tabela ja existia' : 'criada e preenchida'), ' | linhas: ', $pdo->query("SELECT COUNT(*) FROM leads_starts")->fetchColumn(), ' | leads: ', $pdo->query("SELECT COUNT(*) FROM leads WHERE bot_id IS NOT NULL")->fetchColumn(), "\n";
