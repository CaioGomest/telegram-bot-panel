<?php
// Somente leitura. Roda na homologacao (cwd = pasta do painel), via stdin do plink. Nada e gravado no servidor.
chdir(getenv('HOME') . '/domains/stackcode.com.br/public_html/telegram');
require_once 'conexao.php';
require_once 'funcoes/criptografia.php';

$pdo = $GLOBALS['pdo'];
$out = [];

$u_st = $pdo->prepare("SELECT id, nome, email, perfil, apelido_publico, email_verificado_em, criado_em FROM usuarios WHERE email LIKE ?"); $u_st->execute([explode('@', getenv('EMAIL_DONO') ?: 'dono@exemplo.com')[0] . '%']); $u = $u_st->fetchAll();
$out['usuarios'] = $u;
if (!$u) { echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT); exit; }
$uid = (int)$u[0]['id'];

$out['bots'] = $pdo->query("SELECT id, token, id_bot_telegram, nome_usuario, primeiro_nome, id_fluxo_conectado, url_webhook, token_invalido_em, criado_em FROM bots WHERE id_usuario = $uid")->fetchAll();

$out['gateways_globais'] = $pdo->query("SELECT * FROM gateways")->fetchAll();

$ug = $pdo->query("SELECT ug.*, g.nome AS gateway_nome FROM usuarios_gateways ug JOIN gateways g ON g.id = ug.id_gateway WHERE ug.id_usuario = $uid")->fetchAll();
foreach ($ug as &$l) {
    foreach (['client_secret', 'cert_password', 'chave_pix'] as $c) {
        $l[$c . '_claro'] = descriptografarSegredo($l[$c] ?? '');
        unset($l[$c]);
    }
}
unset($l);
$out['usuarios_gateways'] = $ug;


$cfg = $pdo->query("SELECT * FROM configuracoes")->fetchAll();
$out['configuracoes_chaves'] = array_map(fn($r) => array_keys($r), array_slice($cfg, 0, 1));
$out['configuracoes'] = $cfg;

$out['fluxos'] = $pdo->query("SELECT id, nome, modo, CHAR_LENGTH(dados_fluxograma) AS tam FROM fluxos WHERE id_usuario = $uid")->fetchAll();

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
