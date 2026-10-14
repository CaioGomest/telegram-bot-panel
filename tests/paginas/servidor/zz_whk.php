<?php
// Teste de entrega de webhooks do usuário de teste. Uso: php zz_whk.php setup|fire|fail|limpar
chdir(__DIR__); require_once 'conexao.php'; require_once 'funcoes/webhooks.php';
$u = (int) $pdo->query("SELECT id FROM usuarios WHERE email='pgteste-user@example.invalid'")->fetchColumn();
$acao = $argv[1] ?? '';
$url = 'https://telegram.stackcode.com.br/zz_recv';
$log = __DIR__ . '/zz_recv.log';
if ($acao === 'setup') {
    $pdo->exec("DELETE FROM webhooks WHERE id_usuario = $u");
    @unlink($log); @unlink(__DIR__ . '/zz_recv.modo');
    $ins = $pdo->prepare("INSERT INTO webhooks (id_usuario, nome, url, secret, eventos, bot_id, ativo) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $ins->execute([$u, 'A todos', $url . '?w=a', 'segredo123', 'user_joined,payment_created,payment_approved', null, 1]); $a = (int) $pdo->lastInsertId();
    $ins->execute([$u, 'B so aprovado bot um', $url . '?w=b', null, 'payment_approved', (int) $pdo->query("SELECT id FROM bots WHERE id_usuario=$u AND nome_usuario='pg_bot_um'")->fetchColumn(), 1]); $b = (int) $pdo->lastInsertId();
    $ins->execute([$u, 'C inativo', $url . '?w=c', null, 'user_joined,payment_created,payment_approved', null, 0]); $c = (int) $pdo->lastInsertId();
    echo json_encode(['a' => $a, 'b' => $b, 'c' => $c]), "\n"; exit;
}
$b1 = (int) $pdo->query("SELECT id FROM bots WHERE id_usuario=$u AND nome_usuario='pg_bot_um'")->fetchColumn();
$b2 = (int) $pdo->query("SELECT id FROM bots WHERE id_usuario=$u AND nome_usuario='pg_bot_dois'")->fetchColumn();
$venda = (int) $pdo->query("SELECT id FROM vendas WHERE transacao_id='PGTX1'")->fetchColumn();
if ($acao === 'fire') {
    @unlink($log);
    dispararWebhooks($u, 'user_joined', ['bot_id' => $b1, 'id_telegram' => '9001']);
    dispararWebhooks($u, 'payment_created', ['bot_id' => $b1, 'id_telegram' => '9001', 'venda_id' => $venda, 'pix_code' => '0002PIXCODE', 'plan_name' => 'Mensal', 'gateway' => 'omegapayments']);
    dispararWebhooks($u, 'payment_approved', ['bot_id' => $b1, 'id_telegram' => '9001', 'venda_id' => $venda, 'plan_name' => 'Mensal', 'gateway' => 'omegapayments']);
    dispararWebhooks($u, 'user_joined', ['bot_id' => $b2, 'id_telegram' => '9101']);
    dispararWebhooks($u, 'evento_inventado', ['bot_id' => $b1]);
    $linhas = is_file($log) ? array_map(fn($l) => json_decode($l, true), array_filter(explode("\n", file_get_contents($log)))) : [];
    echo json_encode(['recebidos' => $linhas], JSON_UNESCAPED_UNICODE), "\n"; exit;
}
if ($acao === 'fail') {
    file_put_contents(__DIR__ . '/zz_recv.modo', '500');
    for ($i = 0; $i < 6; $i++) { dispararWebhooks($u, 'payment_approved', ['bot_id' => $b1, 'id_telegram' => '9001', 'venda_id' => $venda]); }
    $r = $pdo->query("SELECT nome, ativo, falhas_consecutivas FROM webhooks WHERE id_usuario=$u ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $e = $pdo->query("SELECT COUNT(*) FROM webhooks_envios WHERE webhook_id IN (SELECT id FROM webhooks WHERE id_usuario=$u) AND sucesso=0")->fetchColumn();
    echo json_encode(['webhooks' => $r, 'envios_com_falha' => (int) $e]), "\n"; exit;
}
if ($acao === 'limpar') { @unlink($log); @unlink(__DIR__ . '/zz_recv.modo'); echo "ok\n"; exit; }
