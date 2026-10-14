<?php
// Aplica por linha de comando a migração de 08/10/2026 (leads.pagou + índice + bots.token_invalido_em) e confere o resultado
// contra a consulta antiga (EXISTS por venda). Mesmo SQL do admin/atualiza_banco.php, mas sem limite de tempo da web.
// Uso por SSH, depois apague: php zz_migra.php
chdir(__DIR__); require_once 'conexao.php';
set_time_limit(0);
$t0 = microtime(true);
$passo = function (string $nome, callable $f) use (&$t0) { $t = microtime(true); try { $f(); printf("%-52s ok em %.1f s\n", $nome, microtime(true) - $t); } catch (Throwable $e) { printf("%-52s %s\n", $nome, substr($e->getMessage(), 0, 90)); } };

$criou = !colunaExiste('leads', 'pagou');
echo $criou ? "leads.pagou ainda não existe: criando\n" : "leads.pagou já existe\n";
if ($criou) { $passo('ALTER leads ADD pagou', fn() => $pdo->exec("ALTER TABLE leads ADD COLUMN pagou TINYINT(1) NOT NULL DEFAULT 0 AFTER origem_rastreio")); }
$passo('ALTER leads ADD INDEX (bot, pagou, criado_em)', fn() => $pdo->exec("ALTER TABLE leads ADD INDEX idx_leads_bot_pagou_criado (bot_id, pagou, criado_em DESC)"));
$passo('ALTER bots ADD token_invalido_em', fn() => $pdo->exec("ALTER TABLE bots ADD COLUMN token_invalido_em DATETIME NULL AFTER webhook_secret"));
if ($criou) {
    $marcados = 0; $bots = $pdo->query("SELECT DISTINCT bot_id FROM vendas WHERE status = 'pago' AND bot_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
    $passo('histórico: ' . count($bots) . ' bots com venda paga', function () use ($pdo, $bots, &$marcados) {
        foreach ($bots as $b) {
            $st = $pdo->prepare("UPDATE leads l SET l.pagou = 1 WHERE l.bot_id = ? AND l.pagou = 0 AND EXISTS (SELECT 1 FROM vendas v WHERE v.bot_id = l.bot_id AND v.id_telegram = l.id_telegram AND v.status = 'pago')");
            $st->execute([(int) $b]); $marcados += $st->rowCount();
        }
    });
    echo "leads marcados como pagantes: $marcados\n";
}
// conferência: o novo filtro dá o mesmo número que o antigo, nos bots do vendedor grande (cliente1) e do dono
$u = (int) $pdo->query("SELECT id FROM usuarios WHERE email='cliente1@gmial.com'")->fetchColumn();
foreach ([['vendedor grande', $u], ['conta do dono', 36]] as [$nome, $uid]) {
    $ids = implode(',', array_map('intval', $pdo->query("SELECT id FROM bots WHERE id_usuario = $uid")->fetchAll(PDO::FETCH_COLUMN)));
    if ($ids === '') { continue; }
    $velho = (int) $pdo->query("SELECT COUNT(*) FROM leads l WHERE l.bot_id IN ($ids) AND EXISTS (SELECT 1 FROM vendas v WHERE v.id_telegram = l.id_telegram AND v.bot_id = l.bot_id AND v.status = 'pago')")->fetchColumn();
    $novo = (int) $pdo->query("SELECT COUNT(*) FROM leads l WHERE l.bot_id IN ($ids) AND l.pagou = 1")->fetchColumn();
    printf("%-16s pagantes: antigo(EXISTS)=%d | novo(pagou)=%d | %s\n", $nome, $velho, $novo, $velho === $novo ? 'IGUAL' : 'DIFERENTE!');
}
printf("total %.1f s\n", microtime(true) - $t0);
