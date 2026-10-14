<?php
// Teste de ENTREGAS REPETIDAS e CLIQUES SIMULTÂNEOS (o Telegram reenvia a atualização se o servidor demora; cliente impaciente clica várias vezes):
// para cada cliente novo dispara em paralelo: /start ×5 (mesma atualização), "ver planos" ×3 e "escolher plano" ×4. Depois conta no banco
// quantos leads e quantas vendas (Pix) cada cliente ganhou — o ideal é 1 lead e 1 Pix.
//   php duplicados.php --tag=e2 --clientes=100 [--url=http://127.0.0.1:8080]
chdir('/var/www/painel');
require_once 'conexao.php';
$op = [];
foreach (array_slice($argv, 1) as $a) { if (preg_match('/^--([a-z-]+)=(.*)$/', $a, $m)) $op[$m[1]] = $m[2]; }
$URL = rtrim($op['url'] ?? 'http://127.0.0.1:8080', '/');
$tag = preg_replace('/[^a-z0-9]/i', '', $op['tag'] ?? 'e2');
$N = (int) ($op['clientes'] ?? 100);
$RETRY = !empty($op['retry']);   // --retry=1: os cliques repetidos são a MESMA atualização (mesmo update_id e callback id) = reenvio do Telegram
$st = $pdo->prepare("SELECT id, token, webhook_secret FROM bots WHERE token LIKE ? AND id_fluxo_conectado IS NOT NULL ORDER BY id LIMIT 400");
$st->execute(["SIMBOT:$tag-%"]);
$bots = $st->fetchAll(PDO::FETCH_ASSOC);
$base = 30000000000 + (time() - 1700000000) * 1000;
function upd(int $chat, ?string $texto, ?string $cb, int $uid): string
{
    $from = ['id' => $chat, 'is_bot' => false, 'first_name' => 'Dup', 'last_name' => 'Teste'];
    if ($cb === null) return json_encode(['update_id' => $uid, 'message' => ['message_id' => $uid % 1000, 'from' => $from, 'chat' => ['id' => $chat, 'type' => 'private'], 'date' => time(), 'text' => $texto]]);
    return json_encode(['update_id' => $uid, 'callback_query' => ['id' => 'cbq' . $uid, 'from' => $from, 'message' => ['message_id' => 555, 'chat' => ['id' => $chat, 'type' => 'private']], 'data' => $cb, 'chat_instance' => 'x']]);
}
function rajada(array $reqs, string $URL): int
{
    $mh = curl_multi_init();
    $hs = [];
    foreach ($reqs as [$bot, $corpo]) {
        $ch = curl_init($URL . '/webhook.php?token=' . urlencode($bot['token']));
        $h = ['Content-Type: application/json', 'Expect:'];
        if (!empty($bot['webhook_secret'])) $h[] = 'X-Telegram-Bot-Api-Secret-Token: ' . $bot['webhook_secret'];
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $corpo, CURLOPT_HTTPHEADER => $h, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60]);
        curl_multi_add_handle($mh, $ch);
        $hs[] = $ch;
    }
    do { curl_multi_exec($mh, $run); if ($run) curl_multi_select($mh, 0.05); } while ($run);
    $ok = 0;
    foreach ($hs as $ch) { if (curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200) $ok++; curl_multi_remove_handle($mh, $ch); }
    return $ok;
}
$max_lead = (int) $pdo->query("SELECT COALESCE(MAX(id),0) FROM leads")->fetchColumn();
$max_venda = (int) $pdo->query("SELECT COALESCE(MAX(id),0) FROM vendas")->fetchColumn();
$cl = [];
for ($i = 0; $i < $N; $i++) $cl[] = ['chat' => $base + $i, 'bot' => $bots[$i % count($bots)], 'uid' => 1000000 + $i * 20];
$ok = ['start' => 0, 'planos' => 0, 'plano' => 0];
$r = []; foreach ($cl as $c) for ($k = 0; $k < 5; $k++) $r[] = [$c['bot'], upd($c['chat'], '/start', null, $c['uid'])];
shuffle($r); $ok['start'] = rajada($r, $URL);
$r = []; foreach ($cl as $c) for ($k = 0; $k < 3; $k++) $r[] = [$c['bot'], upd($c['chat'], null, 'basico::ver_planos', $c['uid'] + 5 + ($RETRY ? 0 : $k))];
shuffle($r); $ok['planos'] = rajada($r, $URL);
$r = []; foreach ($cl as $c) for ($k = 0; $k < 4; $k++) $r[] = [$c['bot'], upd($c['chat'], null, 'basico::plano::p_mensal', $c['uid'] + 10 + ($RETRY ? 0 : $k))];
shuffle($r); $ok['plano'] = rajada($r, $URL);
sleep(2);
$chats = implode(',', array_map(fn($c) => "'" . $c['chat'] . "'", $cl));
$leads = $pdo->query("SELECT id_telegram, COUNT(*) n FROM leads WHERE id > $max_lead AND id_telegram IN ($chats) GROUP BY id_telegram")->fetchAll(PDO::FETCH_KEY_PAIR);
$vendas = $pdo->query("SELECT id_telegram, COUNT(*) n FROM vendas WHERE id > $max_venda AND id_telegram IN ($chats) GROUP BY id_telegram")->fetchAll(PDO::FETCH_KEY_PAIR);
$dist = function (array $m, int $total) { $d = []; for ($i = 0; $i < $total; $i++) { $n = (int) ($m[(string) ($GLOBALS['base'] + $i)] ?? 0); $d[$n] = ($d[$n] ?? 0) + 1; } ksort($d); return $d; };
echo json_encode(['modo' => $RETRY ? 'REENVIO da mesma atualização (mesmo update_id)' : 'cliques distintos (update_id diferentes)', 'clientes' => $N, 'http_200' => $ok, 'leads_por_cliente (qtd de leads => nº de clientes)' => $dist($leads, $N), 'pix_por_cliente (qtd de vendas => nº de clientes)' => $dist($vendas, $N)], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
$pdo->exec("UPDATE vendas SET status='expirado' WHERE status='gerado' AND transacao_id LIKE 'SIMTX%'");
