<?php
// Mede as consultas mais pesadas das telas de Leads e Admin → Transações com a massa do teste de estresse (vendedor grande = cliente1).
// Uso (por SSH, depois apague): php zz_perf.php   — só leitura (SELECT / EXPLAIN).
chdir(__DIR__); require_once 'conexao.php';

function med(PDO $pdo, string $nome, string $sql, array $p = [], int $vezes = 3): void
{
    $ms = [];
    $linhas = null;
    for ($i = 0; $i < $vezes; $i++) {
        $t = microtime(true);
        $st = $pdo->prepare($sql);
        $st->execute($p);
        $linhas = $st->fetchAll(PDO::FETCH_NUM);
        $ms[] = (microtime(true) - $t) * 1000;
    }
    sort($ms);
    printf("%-58s mediana %7.1f ms  (min %6.1f, max %7.1f)  linhas=%d\n", $nome, $ms[(int) floor(count($ms) / 2)], $ms[0], end($ms), count($linhas));
}
function plano(PDO $pdo, string $sql, array $p = []): void
{
    $st = $pdo->prepare('EXPLAIN ' . $sql);
    $st->execute($p);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        printf("      · %-6s tabela=%-12s tipo=%-8s chave=%-24s linhas≈%s extra=%s\n", $r['id'], $r['table'], $r['type'], $r['key'] ?? '-', $r['rows'], $r['Extra'] ?? '');
    }
}

$u = (int) $pdo->query("SELECT id FROM usuarios WHERE email='cliente1@gmial.com'")->fetchColumn();
$bots = $pdo->query("SELECT id FROM bots WHERE id_usuario=$u")->fetchAll(PDO::FETCH_COLUMN);
$ids = implode(',', array_map('intval', $bots));
echo "usuario=$u bots=$ids\n";
echo 'leads do vendedor: ', $pdo->query("SELECT COUNT(*) FROM leads WHERE bot_id IN ($ids)")->fetchColumn(), ' | vendas pagas: ', $pdo->query("SELECT COUNT(*) FROM vendas v JOIN bots b ON v.bot_id=b.id WHERE b.id_usuario=$u AND v.status='pago'")->fetchColumn(), "\n";
echo 'vendas na base inteira: ', $pdo->query("SELECT COUNT(*) FROM vendas")->fetchColumn(), "\n\n";

$campos = "SELECT l.id, l.nome, l.id_telegram, l.telefone, l.criado_em,
  (SELECT COUNT(*) FROM vendas v WHERE v.id_telegram=l.id_telegram AND v.bot_id=l.bot_id AND v.status='pago') c,
  (SELECT SUM(v.valor) FROM vendas v WHERE v.id_telegram=l.id_telegram AND v.bot_id=l.bot_id AND v.status='pago') g,
  (SELECT v.status FROM vendas v WHERE v.id_telegram=l.id_telegram AND v.bot_id=l.bot_id ORDER BY v.criado_em DESC LIMIT 1) us,
  EXISTS (SELECT 1 FROM membros_grupos mg WHERE mg.id_telegram=l.id_telegram AND mg.bot_id=l.bot_id AND mg.status='ativo' AND (mg.data_expiracao IS NULL OR mg.data_expiracao > NOW())) pa";
$base = "FROM leads l JOIN bots b ON l.bot_id=b.id WHERE l.bot_id IN ($ids)";
$pago = " AND EXISTS (SELECT 1 FROM vendas v WHERE v.id_telegram=l.id_telegram AND v.bot_id=l.bot_id AND v.status='pago')";
$npago = " AND NOT EXISTS (SELECT 1 FROM vendas v WHERE v.id_telegram=l.id_telegram AND v.bot_id=l.bot_id AND v.status='pago')";
$busca = " AND (l.nome LIKE ? OR l.id_telegram LIKE ?)";

echo "== Leads (vendedor com muitos leads) ==\n";
foreach ([['padrão', '', []], ['só quem pagou', $pago, []], ['só quem não pagou', $npago, []], ['busca "Ana" (nome/ID)', $busca, ['%Ana%', '%Ana%']], ['busca por ID exato', $busca, ['%100000%', '%100000%']]] as [$n, $w, $p]) {
    med($pdo, "COUNT  — $n", "SELECT COUNT(*) $base$w", $p);
    med($pdo, "PÁGINA — $n", "$campos $base$w ORDER BY l.criado_em DESC LIMIT 25 OFFSET 0", $p);
}
echo "  página profunda (OFFSET 100000):\n";
med($pdo, 'PÁGINA 4001 — padrão', "$campos $base ORDER BY l.criado_em DESC LIMIT 25 OFFSET 100000");
echo "  plano do COUNT 'só quem pagou':\n"; plano($pdo, "SELECT COUNT(*) $base$pago");
echo "  plano da busca:\n"; plano($pdo, "SELECT COUNT(*) $base$busca", ['%Ana%', '%Ana%']);

echo "\n== Admin → Transações ==\n";
$sqlIds = fn($w) => "SELECT v.id FROM vendas v JOIN bots b ON v.bot_id=b.id JOIN usuarios u ON b.id_usuario=u.id WHERE $w ORDER BY v.criado_em DESC LIMIT 25 OFFSET 0";
foreach ([['status=pago', "v.status='pago'"], ['status=pago + período 30 dias', "v.status='pago' AND v.criado_em >= DATE_SUB(NOW(), INTERVAL 30 DAY)"], ['status=expirado', "v.status='expirado'"], ['sem filtro', '1=1']] as [$n, $w]) {
    med($pdo, "IDs da página — $n", $sqlIds($w));
    if ($w !== '1=1') { med($pdo, "COUNT — $n", "SELECT COUNT(*) FROM vendas v WHERE $w"); }
}
echo "  plano do COUNT status=pago:\n"; plano($pdo, "SELECT COUNT(*) FROM vendas v WHERE v.status='pago'");
echo "  plano dos IDs status=pago:\n"; plano($pdo, $sqlIds("v.status='pago'"));
echo "  busca por transação (LIKE %x%):\n"; med($pdo, 'IDs da página — busca "abc"', $sqlIds("(v.transacao_id LIKE '%abc%' OR v.id_telegram LIKE '%abc%')"));
