<?php
// Diagnóstico (SOMENTE LEITURA) do plano da listagem de Leads do vendedor grande. Uso por SSH, depois apague: php zz_plano.php
chdir(__DIR__); require_once 'conexao.php';
echo "== índices de leads ==\n";
foreach ($pdo->query("SHOW INDEX FROM leads")->fetchAll(PDO::FETCH_ASSOC) as $i) {
    printf("%-52s seq=%d col=%-12s card=%s\n", $i['Key_name'], $i['Seq_in_index'], $i['Column_name'], $i['Cardinality']);
}
$ids = '433,434';
$campos = "SELECT l.id, l.nome, l.id_telegram, l.telefone, l.criado_em AS data_inicio, COALESCE(b.primeiro_nome, b.nome_usuario) AS nome_bot,
 (SELECT COUNT(*) FROM vendas v WHERE v.id_telegram = l.id_telegram AND v.bot_id = l.bot_id AND v.status = 'pago') AS total_compras,
 (SELECT SUM(v.valor) FROM vendas v WHERE v.id_telegram = l.id_telegram AND v.bot_id = l.bot_id AND v.status = 'pago') AS total_gasto,
 (SELECT v.status FROM vendas v WHERE v.id_telegram = l.id_telegram AND v.bot_id = l.bot_id ORDER BY v.criado_em DESC LIMIT 1) AS ultimo_status_pagamento,
 EXISTS (SELECT 1 FROM membros_grupos mg WHERE mg.id_telegram = l.id_telegram AND mg.bot_id = l.bot_id AND mg.status = 'ativo' AND (mg.data_expiracao IS NULL OR mg.data_expiracao > NOW())) AS plano_ativo
 FROM leads l JOIN bots b ON l.bot_id = b.id WHERE l.bot_id IN ($ids) ORDER BY l.criado_em DESC LIMIT 25 OFFSET 0";
echo "\n== EXPLAIN da página padrão ==\n";
foreach ($pdo->query("EXPLAIN $campos")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    printf("id=%s %-18s tabela=%-8s tipo=%-6s chave=%-60s linhas=%s extra=%s\n", $r['id'], $r['select_type'], $r['table'], $r['type'], $r['key'] ?? '-', $r['rows'], $r['Extra'] ?? '');
}
$t = microtime(true); $pdo->query($campos)->fetchAll(); printf("\ntempo (plano atual): %.0f ms\n", (microtime(true) - $t) * 1000);
foreach (['idx_leads_bot_criado_em'] as $forca) {
    try {
        $t = microtime(true); $pdo->query(str_replace('FROM leads l JOIN', "FROM leads l FORCE INDEX ($forca) JOIN", $campos))->fetchAll();
        printf("tempo com FORCE INDEX %s: %.0f ms\n", $forca, (microtime(true) - $t) * 1000);
    } catch (Throwable $e) { echo "FORCE INDEX $forca: ", $e->getMessage(), "\n"; }
}
