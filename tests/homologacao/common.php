<?php
// Utilidades comuns dos scripts de teste (carrega o painel da CÓPIA).
chdir('/tmp/pt/app');
require_once '/tmp/pt/app/conexao.php';
function q(string $sql, array $p = []): array { global $pdo; $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchAll(PDO::FETCH_ASSOC); }
function q1(string $sql, array $p = []) { $r = q($sql, $p); return $r ? array_values($r[0])[0] : null; }
function ids(): array { return json_decode((string) @file_get_contents('/tmp/pt/ids.json'), true) ?: []; }
