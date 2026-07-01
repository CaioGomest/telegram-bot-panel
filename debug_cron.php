<?php
require 'conexao.php';
$stmt=$pdo->query("SELECT * FROM vendas ORDER BY id DESC LIMIT 1");
print_r($stmt->fetch(PDO::FETCH_ASSOC));
