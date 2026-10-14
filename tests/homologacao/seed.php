<?php
// Cria usuário/gateway/bot FICTÍCIOS de teste (tudo apagado no fim por cleanup.php).
require '/tmp/pt/common.php';
require_once '/tmp/pt/app/funcoes/gateways.php';
$email = 'teste-homolog-fluxos@example.invalid';
$ex = q1("SELECT id FROM usuarios WHERE email = ?", [$email]);
if ($ex) { echo "já existe usuario $ex\n"; }
else {
    $pdo->prepare("INSERT INTO usuarios (nome, email, senha, perfil) VALUES (?, ?, ?, 'usuario')")->execute(['TESTE HOMOLOG FLUXOS', $email, password_hash('x-nao-usar-' . bin2hex(random_bytes(6)), PASSWORD_DEFAULT)]);
    $ex = (int) $pdo->lastInsertId();
}
$uid = (int) $ex;
$gw_id = (int) q1("SELECT id FROM gateways WHERE nome = 'omegapayments'");
saveUserGatewayConfig($uid, $gw_id, 'sim_pub_key', 'sim_secret_key', '', '', 'sim-chave-pix', true, 1, 'pj');
$token = 'SIMBOT:AAH-homolog-' . substr(md5((string) $uid), 0, 8);
$b = q1("SELECT id FROM bots WHERE token = ?", [$token]);
if (!$b) {
    $pdo->prepare("INSERT INTO bots (id_usuario, token, id_bot_telegram, nome_usuario, primeiro_nome, descricao, descricao_curta) VALUES (?, ?, 7000001, 'sim_homolog_bot', 'Bot Homolog', 'x', 'x')")->execute([$uid, $token]);
    $b = (int) $pdo->lastInsertId();
}
$d = ['uid' => $uid, 'bot' => (int) $b, 'token' => $token, 'gw' => $gw_id, 'email' => $email, 'chat' => '777000111', 'grupo' => '-1001000000001', 'grupo2' => '-1001000000002', 'cache' => '-1001000000099'];
file_put_contents('/tmp/pt/ids.json', json_encode($d));
echo json_encode($d), "\n";
// mídias de teste (na cópia, não no painel real)
$up = '/tmp/pt/app/uploads';
if (!is_dir($up)) mkdir($up, 0777, true);
$im = imagecreatetruecolor(40, 40); imagepng($im, "$up/t_foto.png"); imagejpeg($im, "$up/t_foto.jpg");
foreach (['t_video.mp4' => 'MP4SIM', 't_audio.mp3' => 'MP3SIM', 't_audio.ogg' => 'OGGSIM', 't_doc.pdf' => '%PDF-sim'] as $n => $c) file_put_contents("$up/$n", $c . str_repeat('0', 64));
echo "midias ok\n";
