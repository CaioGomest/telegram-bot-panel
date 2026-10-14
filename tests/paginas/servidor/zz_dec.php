<?php
// Imprime só o MD5 do segredo decifrado do usuário de teste (nunca o segredo).
chdir(__DIR__); require_once 'conexao.php'; require_once 'funcoes/criptografia.php';
$s = $pdo->query("SELECT client_secret FROM usuarios_gateways WHERE id_usuario = (SELECT id FROM usuarios WHERE email = 'pgteste-user@example.invalid') AND id_gateway = 2")->fetchColumn();
echo $s ? md5(descriptografarSegredo((string) $s)) : 'sem-segredo', "\n";
