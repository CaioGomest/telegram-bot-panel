<?php
// Grava a configuração de traqueamento do usuário de teste (tokens cifrados como o painel faz).
// Uso: php trkcfg.php '{"fb":1,"pixel":"123","fbtoken":"EAAB...","ut":1,"uttoken":"..."}'   |   php trkcfg.php limpar
require '/tmp/pt/common.php';
require_once '/tmp/pt/app/funcoes/criptografia.php';
$uid = (int) ids()['uid'];
$pdo->prepare("DELETE FROM usuarios_traqueamento WHERE id_usuario = ?")->execute([$uid]);
if (($argv[1] ?? '') === 'limpar') { echo "limpo\n"; exit; }
$c = json_decode($argv[1] ?? '{}', true) ?: [];
$pdo->prepare("INSERT INTO usuarios_traqueamento (id_usuario, facebook_ativo, facebook_pixel_id, facebook_access_token, utmfy_ativo, utmfy_token, tiktok_ativo, tiktok_pixel_id, tiktok_access_token) VALUES (?,?,?,?,?,?,0,'','')")
    ->execute([$uid, (int) ($c['fb'] ?? 0), $c['pixel'] ?? '', isset($c['fbtoken']) ? criptografarSegredo($c['fbtoken']) : '', (int) ($c['ut'] ?? 0), isset($c['uttoken']) ? criptografarSegredo($c['uttoken']) : '']);
echo "config gravada\n";
