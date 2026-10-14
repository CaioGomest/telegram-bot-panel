<?php
chdir(__DIR__); require_once 'conexao.php';
foreach ($pdo->query("SELECT id,nome,audiencia,status,agendado_em,total_destinatarios t,entregues e,falhas f FROM remarketing_campanhas WHERE id_usuario=36 ORDER BY id DESC LIMIT 3") as $r) echo json_encode($r, JSON_UNESCAPED_UNICODE), "\n";
foreach ($pdo->query("SELECT titulo,LEFT(descricao,90) d,criado_em FROM atividades WHERE id_usuario=36 AND tipo IN ('sistema') ORDER BY id DESC LIMIT 5") as $r) echo json_encode($r, JSON_UNESCAPED_UNICODE), "\n";
