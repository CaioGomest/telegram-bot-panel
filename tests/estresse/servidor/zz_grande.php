<?php
// Vendedor grande: +200 mil leads e +30 mil vendas no cliente1 (bot 1). Uso: php zz_grande.php
chdir(__DIR__); require_once 'conexao.php'; set_time_limit(0); ini_set('memory_limit', '1024M');
$u = (int) $pdo->query("SELECT id FROM usuarios WHERE email='cliente1@gmial.com'")->fetchColumn();
$b = (int) $pdo->query("SELECT id FROM bots WHERE id_usuario=$u ORDER BY id LIMIT 1")->fetchColumn();
$f = (int) $pdo->query("SELECT id_fluxo_conectado FROM bots WHERE id=$b")->fetchColumn() ?: null;
$t0 = microtime(true);
$base = 50000000;
for ($lote = 0; $lote < 200; $lote++) {
    $rows = []; $p = [];
    for ($i = 0; $i < 1000; $i++) { $n = $base + $lote * 1000 + $i; $rows[] = '(?,?,?,?,?,?,?)'; array_push($p, (string) $n, 'Lead grande ' . $n, null, null, $b, date('Y-m-d H:i:s', time() - random_int(0, 90 * 86400)), $f); }
    $pdo->prepare("INSERT INTO leads (id_telegram, nome, telefone, nome_usuario_telegram, bot_id, criado_em, id_fluxo) VALUES " . implode(',', $rows))->execute($p);
}
$tx = 0;
for ($lote = 0; $lote < 30; $lote++) {
    $rows = []; $p = [];
    for ($i = 0; $i < 1000; $i++) {
        $st = ['pago', 'pago', 'expirado', 'cancelado'][array_rand([0, 1, 2, 3])]; $c = time() - random_int(0, 90 * 86400); $v = [19.9, 49.9, 97, 9.9][random_int(0, 3)];
        $rows[] = '(?,?,?,?,?,?,?,?,?,?)';
        array_push($p, (string) ($base + random_int(0, 199999)), $b, $v, $st, 'ESTG' . $lote . 'x' . $i . 'u' . $u, date('Y-m-d H:i:s', $c), $st === 'pago' ? date('Y-m-d H:i:s', $c + 60) : null, 30, $f, $st === 'pago' ? 0.35 : 0);
    }
    $pdo->prepare("INSERT INTO vendas (id_telegram, bot_id, valor, status, transacao_id, criado_em, pago_em, dias_acesso, id_fluxo, comissao_admin) VALUES " . implode(',', $rows))->execute($p);
}
echo "ok em ", round(microtime(true) - $t0, 1), "s; leads do bot: ", $pdo->query("SELECT COUNT(*) FROM leads WHERE bot_id=$b")->fetchColumn(), "\n";
