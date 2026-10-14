<?php
chdir(__DIR__); require_once 'conexao.php';
foreach ([3, 36] as $u) {
    $ids = implode(',', array_map('intval', $pdo->query("SELECT id FROM bots WHERE id_usuario = $u")->fetchAll(PDO::FETCH_COLUMN)));
    foreach (['total' => '', 'hoje' => "AND DATE(v.criado_em) = CURDATE()", '7dias' => "AND v.criado_em >= CURDATE() - INTERVAL 7 DAY", '30dias' => "AND v.criado_em >= CURDATE() - INTERVAL 29 DAY"] as $rot => $w) {
        $a = microtime(true);
        $r = $pdo->query("SELECT v.id FROM vendas v WHERE v.bot_id IN ($ids) AND v.status = 'pago' $w ORDER BY v.criado_em DESC, v.id DESC LIMIT 10")->fetchAll(PDO::FETCH_COLUMN);
        printf("usuario %d  %-6s %5.0f ms  (%d linhas)\n", $u, $rot, (microtime(true) - $a) * 1000, count($r));
    }
}
