<?php
// Rajada de PAGAMENTOS: dispara o webhook da gateway para vendas "gerado" (criadas pelo funil.php com --conversao=0) e mede quantas confirmações
// por segundo o servidor processa (cada uma: confere a transação na gateway, marca paga, cria o acesso ao grupo, avisa o cliente, notifica o vendedor).
//   php pagamentos.php --n=5000 --conc=64 [--url=http://127.0.0.1:8080] [--dup=0.2] [--saida=/root/res/x.json]
chdir('/var/www/painel');
require_once 'conexao.php';
$op = [];
foreach (array_slice($argv, 1) as $a) { if (preg_match('/^--([a-z-]+)=(.*)$/', $a, $m)) $op[$m[1]] = $m[2]; }
$URL = rtrim($op['url'] ?? 'http://127.0.0.1:8080', '/');
$N = (int) ($op['n'] ?? 1000);
$CONC = (int) ($op['conc'] ?? 32);
$DUP = (float) ($op['dup'] ?? 0);
$st = $pdo->query("SELECT id, transacao_id FROM vendas WHERE status = 'gerado' AND transacao_id LIKE 'SIMTX%' ORDER BY id LIMIT $N");
$vendas = $st->fetchAll(PDO::FETCH_ASSOC);
$N = count($vendas);
if (!$N) { fwrite(STDERR, "sem vendas 'gerado' de teste (rode funil.php --conversao=0 antes)\n"); exit(2); }
$ids = array_column($vendas, 'id');
$max_membro = (int) $pdo->query("SELECT COALESCE(MAX(id),0) FROM membros_grupos")->fetchColumn();
foreach ($vendas as $v) @touch('/dev/shm/est/pagos/' . preg_replace('/\W/', '', $v['transacao_id']));
$mh = curl_multi_init();
$fila = $vendas;
$dups = [];
$ativos = [];
$lat = [];
$http = [];
$feitos = 0;
$t0 = microtime(true);
$lanca = function (array $v, bool $dup) use ($mh, $URL, &$ativos) {
    $ch = curl_init($URL . '/webhook_omegapayments.php');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode(['event' => 'TRANSACTION_PAID', 'transaction' => ['id' => $v['transacao_id'], 'status' => 'PAID']]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Expect:'], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60]);
    curl_multi_add_handle($mh, $ch);
    $ativos[(int) $ch] = $dup;
};
while ($fila || $ativos) {
    while (count($ativos) < $CONC && $fila) {
        $v = array_shift($fila);
        $lanca($v, false);
        if ($DUP > 0 && (mt_rand() / mt_getrandmax()) < $DUP) $lanca($v, true);
    }
    do { $s = curl_multi_exec($mh, $run); } while ($s === CURLM_CALL_MULTI_PERFORM);
    while ($info = curl_multi_info_read($mh)) {
        $ch = $info['handle'];
        $dup = $ativos[(int) $ch]; unset($ativos[(int) $ch]);
        $k = $info['result'] === CURLE_OK ? (string) curl_getinfo($ch, CURLINFO_HTTP_CODE) : 'curl_' . $info['result'];
        $http[$k] = ($http[$k] ?? 0) + 1;
        if (!$dup) { $lat[] = curl_getinfo($ch, CURLINFO_TOTAL_TIME); $feitos++; }
        curl_multi_remove_handle($mh, $ch);
    }
    if ($ativos) curl_multi_select($mh, 0.02);
}
$tt = microtime(true) - $t0;
sleep(1);
$li = implode(',', array_map('intval', $ids));
$pagas = (int) $pdo->query("SELECT COUNT(*) FROM vendas WHERE id IN ($li) AND status = 'pago'")->fetchColumn();
$acessos = (int) $pdo->query("SELECT COUNT(*) FROM membros_grupos WHERE id > $max_membro")->fetchColumn();
sort($lat);
$p = fn(float $q) => round($lat[min(count($lat) - 1, (int) floor(count($lat) * $q))] * 1000, 1);
$res = ['vendas_avisadas' => $N, 'conc' => $CONC, 'dup' => $DUP, 'duracao_s' => round($tt, 2), 'confirmacoes_por_s' => round($N / $tt, 1), 'http' => $http,
        'latencia_ms' => ['p50' => $p(.5), 'p95' => $p(.95), 'p99' => $p(.99), 'max' => round(max($lat) * 1000, 1)], 'pagas_no_banco' => $pagas, 'acessos_criados' => $acessos,
        'perdidas' => $N - $pagas];
echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
if (!empty($op['saida'])) file_put_contents($op['saida'], json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
