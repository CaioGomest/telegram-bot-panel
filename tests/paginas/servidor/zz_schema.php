<?php
// Compara o schema que o INSTALADOR cria (instalacao.php) com o schema real do banco de homologação (instalação antiga + todas as migrações).
// Cria cópias temporárias das tabelas do instalador com prefixo zzi_ (só estrutura), compara colunas, índices e chaves estrangeiras e apaga as cópias.
// Uso (na pasta do painel): php zz_schema.php [dump]   — "dump" grava o SHOW CREATE TABLE do banco real em /tmp/schema_real.sql
chdir(__DIR__); require_once 'conexao.php'; require_once 'funcoes/redirecionadores.php';
$db = $pdo->query('SELECT DATABASE()')->fetchColumn();
$fonte = file_get_contents('instalacao.php');
preg_match_all('/\$pdo->exec\("(CREATE TABLE IF NOT EXISTS ([a-z_]+) \(.*?\) ENGINE=[^;]*;)"\);/s', $fonte, $m, PREG_SET_ORDER);
$stmts = [];
foreach ($m as $x) $stmts[$x[2]] = $x[1];
foreach (esquemaRedirecionamento() as $sql) { if (preg_match('/CREATE TABLE IF NOT EXISTS ([a-z_]+)/', $sql, $mm)) $stmts[$mm[1]] = $sql; }
// "arquivo /tmp/schema.sql": em vez do instalador, valida um arquivo SQL (deploy/schema.sql) contra o banco real
if (($argv[1] ?? '') === 'arquivo') {
    $txt = file_get_contents($argv[2] ?? '/tmp/schema.sql'); $stmts = [];
    foreach (preg_split('/;\s*\n\s*\n/', $txt) as $bloco) {
        if (preg_match('/CREATE TABLE IF NOT EXISTS `([a-z_]+)`/', $bloco, $mm)) { $stmts[$mm[1]] = preg_replace('/^(?:--[^\n]*\n|\s)*/', '', rtrim($bloco, ";\n ")) . ';'; }
    }
}
echo count($stmts), " tabelas no instalador: ", implode(', ', array_keys($stmts)), "\n";
$nomes = array_keys($stmts);
// limpa sobras de execução anterior
foreach ($nomes as $n) { try { $pdo->exec("SET FOREIGN_KEY_CHECKS=0; DROP TABLE IF EXISTS zzi_$n; SET FOREIGN_KEY_CHECKS=1"); } catch (Throwable $e) {} }
$erros = [];
foreach ($stmts as $n => $sql) {
    $novo = preg_replace('/CREATE TABLE IF NOT EXISTS `?' . $n . '`?/', "CREATE TABLE zzi_$n", $sql, 1);
    foreach ($nomes as $ref) $novo = preg_replace('/REFERENCES ' . $ref . '\s*\(/', "REFERENCES zzi_$ref(", $novo);
    $novo = preg_replace('/REFERENCES `' . '([a-z_]+)`/', 'REFERENCES `zzi_$1`', $novo);
    $novo = preg_replace('/CONSTRAINT `([a-z_0-9]+)`/', 'CONSTRAINT `zzi_$1`', $novo);
    try { $pdo->exec($novo); } catch (Throwable $e) { $erros[$n] = $e->getMessage(); }
}
if ($erros) { echo "ERROS ao criar cópias:\n"; foreach ($erros as $n => $e) echo "  $n: $e\n"; }

function cols(PDO $pdo, string $db, string $t): array {
    $s = $pdo->prepare('SELECT COLUMN_NAME n, COLUMN_TYPE t, IS_NULLABLE nl, COLUMN_DEFAULT d, EXTRA e, COLLATION_NAME c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');
    $s->execute([$db, $t]); $o = []; foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $o[$r['n']] = $r; return $o;
}
function idx(PDO $pdo, string $db, string $t): array {
    $s = $pdo->prepare('SELECT INDEX_NAME i, NON_UNIQUE u, SEQ_IN_INDEX q, COLUMN_NAME c, COLLATION o FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY INDEX_NAME, SEQ_IN_INDEX');
    $s->execute([$db, $t]); $o = [];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) { $o[$r['i']]['u'] = (int) $r['u']; $o[$r['i']]['c'][] = $r['c'] . ($r['o'] === 'D' ? ' DESC' : ''); }
    $f = []; foreach ($o as $nome => $v) $f[($v['u'] ? 'idx:' : 'UNIQ:') . implode(',', $v['c'])] = $nome;
    return $f;
}
function fks(PDO $pdo, string $db, string $t, string $pref): array {
    $s = $pdo->prepare('SELECT k.COLUMN_NAME c, k.REFERENCED_TABLE_NAME rt, k.REFERENCED_COLUMN_NAME rc, r.DELETE_RULE dr, r.UPDATE_RULE ur FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME WHERE k.TABLE_SCHEMA=? AND k.TABLE_NAME=? AND k.REFERENCED_TABLE_NAME IS NOT NULL');
    $s->execute([$db, $t]); $o = [];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) { $rt = $pref && strpos($r['rt'], $pref) === 0 ? substr($r['rt'], strlen($pref)) : $r['rt']; $o[$r['c'] . '->' . $rt . '.' . $r['rc']] = $r['dr'] . '/' . $r['ur']; }
    return $o;
}
$tot = 0;
foreach ($nomes as $n) {
    if (isset($erros[$n])) continue;
    $existe = $pdo->query("SHOW TABLES LIKE '$n'")->fetchColumn();
    if (!$existe) { echo "[$n] NÃO EXISTE no banco real\n"; $tot++; continue; }
    $ci = cols($pdo, $db, "zzi_$n"); $cr = cols($pdo, $db, $n);
    foreach ($cr as $c => $r) {
        if (!isset($ci[$c])) { echo "[$n] coluna só no banco real: $c ({$r['t']})\n"; $tot++; continue; }
        $i = $ci[$c];
        foreach (['t' => 'tipo', 'nl' => 'null', 'd' => 'default', 'e' => 'extra'] as $k => $rot) {
            if ((string) $i[$k] !== (string) $r[$k]) { echo "[$n] coluna $c: $rot instalador=" . var_export($i[$k], true) . " real=" . var_export($r[$k], true) . "\n"; $tot++; }
        }
    }
    foreach ($ci as $c => $i) if (!isset($cr[$c])) { echo "[$n] coluna só no instalador: $c\n"; $tot++; }
    $ii = idx($pdo, $db, "zzi_$n"); $ir = idx($pdo, $db, $n);
    foreach ($ir as $k => $nome) if (!isset($ii[$k])) { echo "[$n] índice só no banco real: $nome ($k)\n"; $tot++; }
    foreach ($ii as $k => $nome) if (!isset($ir[$k])) { echo "[$n] índice só no instalador: $nome ($k)\n"; $tot++; }
    $fi = fks($pdo, $db, "zzi_$n", 'zzi_'); $fr = fks($pdo, $db, $n, '');
    foreach ($fr as $k => $v) if (!isset($fi[$k])) { echo "[$n] FK só no banco real: $k ($v)\n"; $tot++; } elseif ($fi[$k] !== $v) { echo "[$n] FK $k: regra instalador={$fi[$k]} real=$v\n"; $tot++; }
    foreach ($fi as $k => $v) if (!isset($fr[$k])) { echo "[$n] FK só no instalador: $k ($v)\n"; $tot++; }
}
// tabelas do banco real que o instalador não cria
$reais = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
$reais = array_values(array_filter($reais, fn($t) => strpos($t, 'zzi_') !== 0 && strpos($t, 'zz_') !== 0));
echo "\nTabelas no banco real que o instalador NÃO cria: ", implode(', ', array_diff($reais, $nomes)) ?: '(nenhuma)', "\n";
echo "Total de diferenças em tabelas comuns: $tot\n";
if (($argv[1] ?? '') === 'dump') {
    $o = ''; foreach ($reais as $t) { $r = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM); $o .= preg_replace('/ AUTO_INCREMENT=\d+/', '', $r[1]) . ";\n\n"; }
    file_put_contents('/tmp/schema_real.sql', $o); echo "dump gravado: /tmp/schema_real.sql (", strlen($o), " bytes)\n";
}
if (($argv[1] ?? '') === 'gerar') {
    // schema do INSTALADOR (fonte da verdade) como SQL puro, na ordem de criação (que já respeita as chaves estrangeiras)
    $o = '';
    foreach ($nomes as $n) {
        $r = $pdo->query("SHOW CREATE TABLE zzi_$n")->fetch(PDO::FETCH_NUM);
        $sql = preg_replace('/ AUTO_INCREMENT=\d+/', '', $r[1]);
        $sql = preg_replace('/CREATE TABLE `zzi_' . $n . '`/', 'CREATE TABLE IF NOT EXISTS `' . $n . '`', $sql);
        foreach ($nomes as $ref) $sql = str_replace('REFERENCES `zzi_' . $ref . '`', 'REFERENCES `' . $ref . '`', $sql);
        $sql = preg_replace('/CONSTRAINT `zzi_([a-z_]+)_ibfk_(\d+)`/', 'CONSTRAINT `$1_ibfk_$2`', $sql);
        $o .= $sql . ";\n\n";
    }
    file_put_contents('/tmp/schema_instalador.sql', $o); echo "schema do instalador gravado: /tmp/schema_instalador.sql (", strlen($o), " bytes)\n";
}
foreach ($nomes as $n) { try { $pdo->exec("SET FOREIGN_KEY_CHECKS=0; DROP TABLE IF EXISTS zzi_$n; SET FOREIGN_KEY_CHECKS=1"); } catch (Throwable $e) {} }
echo "cópias zzi_ removidas\n";
