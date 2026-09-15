<?php
declare(strict_types=1);

require_once __DIR__ . '/funcoes/usuario.php';
verificarAdmin();

$stmt = $pdo->query("SELECT * FROM vendas ORDER BY id DESC LIMIT 1");
print_r($stmt->fetch(PDO::FETCH_ASSOC));
