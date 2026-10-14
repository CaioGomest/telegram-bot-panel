<?php
// Consulta SOMENTE LEITURA para conferir o banco durante o teste. Uso: php zz_q.php <sql em base64>
chdir(__DIR__); require_once 'conexao.php';
$sql = base64_decode($argv[1] ?? '');
if (!preg_match('/^\s*(SELECT|SHOW)\b/i', $sql)) { echo json_encode(['erro' => 'so SELECT']); exit; }
echo json_encode($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
