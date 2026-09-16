<?php
declare(strict_types=1);
require_once __DIR__ . '/../conexao.php';

const BATCH_SIZE    = 1000;  // leads por campanha por rodada
const MAX_CAMPANHAS = 20;    // campanhas simultâneas por rodada
const BOT_DELAY_US  = 40000; // 40ms entre ticks por bot = ~25 msg/s (limite: 30/s)
const CURL_TIMEOUT  = 8;
const MAX_EXEC_SEC  = 240;   // 4 min de margem (Hostinger permite ~5 min no cron)

$lock_file = sys_get_temp_dir() . '/remarketing_cron.lock';
$lock = fopen($lock_file, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo "Outra instância já está rodando." . PHP_EOL;
    exit;
}

$start_time = microtime(true);

function audienciaFiltro(string $audiencia): string
{
    return $audiencia === 'nao_comprou'
        ? " AND NOT EXISTS (SELECT 1 FROM vendas v WHERE v.id_telegram = l.id_telegram AND v.bot_id = l.bot_id AND v.status = 'pago')"
        : " AND EXISTS    (SELECT 1 FROM vendas v WHERE v.id_telegram = l.id_telegram AND v.bot_id = l.bot_id AND v.status = 'pago')";
}

function makeCurlHandle(string $token, string $chat_id, string $text): CurlHandle
{
    $ch = curl_init('https://api.telegram.org/bot' . $token . '/sendMessage');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'chat_id'    => $chat_id,
            'text'       => $text,
            'parse_mode' => 'HTML',
        ]),
        CURLOPT_TIMEOUT        => CURL_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    return $ch;
}

try {
    $pdo->beginTransaction();
    $campanhas = $pdo->query("
        SELECT c.id, c.bot_id, c.audiencia, c.mensagem, c.offset_envio,
               c.entregues, c.falhas, c.total_destinatarios, b.token
        FROM remarketing_campanhas c
        JOIN bots b ON c.bot_id = b.id
        WHERE c.status IN ('pendente','processando')
          AND c.agendado_em <= NOW()
        ORDER BY c.agendado_em ASC
        LIMIT " . MAX_CAMPANHAS . "
        FOR UPDATE SKIP LOCKED
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($campanhas as $c) {
        $pdo->prepare("UPDATE remarketing_campanhas SET status = 'processando' WHERE id = ?")
            ->execute([$c['id']]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flock($lock, LOCK_UN);
    fclose($lock);
    echo "Erro ao buscar campanhas: " . $e->getMessage() . PHP_EOL;
    exit;
}

if (empty($campanhas)) {
    flock($lock, LOCK_UN);
    fclose($lock);
    echo "Nenhuma campanha pendente." . PHP_EOL;
    exit;
}

// Carrega leads de cada campanha via LIMIT/OFFSET, para não carregar tudo em memória.
$stmt_log = $pdo->prepare(
    "INSERT INTO remarketing_envios (campanha_id, id_telegram, resultado, resposta) VALUES (?, ?, ?, ?)"
);

// $filas[campanha_id] = ['token', 'mensagem', 'bot_id', 'offset', 'sucesso', 'falhas', 'total', 'fila' => [...leads...]]
$filas = [];
foreach ($campanhas as $c) {
    $cid    = (int)$c['id'];
    $total  = (int)$c['total_destinatarios'];
    $offset = (int)$c['offset_envio'];

    // Calcula total na primeira rodada
    if ($total === 0 && $offset === 0) {
        $sql_cnt = "SELECT COUNT(*) FROM leads l WHERE l.bot_id = ?" . audienciaFiltro($c['audiencia']);
        $s = $pdo->prepare($sql_cnt);
        $s->execute([$c['bot_id']]);
        $total = (int)$s->fetchColumn();
        $pdo->prepare("UPDATE remarketing_campanhas SET total_destinatarios = ? WHERE id = ?")
            ->execute([$total, $cid]);
    }

    $sql_leads = "SELECT l.id_telegram FROM leads l WHERE l.bot_id = ?"
        . audienciaFiltro($c['audiencia'])
        . " LIMIT " . BATCH_SIZE . " OFFSET " . $offset;
    $s = $pdo->prepare($sql_leads);
    $s->execute([$c['bot_id']]);

    $filas[$cid] = [
        'token'   => $c['token'],
        'mensagem'=> $c['mensagem'],
        'bot_id'  => (int)$c['bot_id'],
        'offset'  => $offset,
        'sucesso' => (int)$c['entregues'],
        'falhas'  => (int)$c['falhas'],
        'total'   => $total,
        'fila'    => $s->fetchAll(PDO::FETCH_COLUMN), // array de id_telegram
        'enviados_batch' => 0,
    ];
}

// Estratégia: a cada tick, enviamos 1 mensagem por bot (bots diferentes em paralelo).
// Isso respeita o limite do Telegram por bot (~30/s) e maximiza throughput
// quando há campanhas de múltiplos usuários com bots distintos.
//
// Exemplo: 5 campanhas com bots diferentes → 5 envios paralelos por tick
//          5 campanhas com o mesmo bot     → 1 envio por tick (throttling correto)

$mh = curl_multi_init();

// $handles[(int)$ch] = ['campanha_id' => int, 'chatId' => string]
$handles = [];

// $bot_em_uso[bot_id] = true (bot já tem um handle pendente neste tick)
$bot_em_uso = [];

$despachar = function () use (&$filas, &$handles, &$bot_em_uso, $mh): int {
    $despachados = 0;
    foreach ($filas as $cid => &$f) {
        if (empty($f['fila'])) continue;
        if (isset($bot_em_uso[$f['bot_id']])) continue; // já tem envio deste bot em voo

        $chat_id = array_shift($f['fila']);
        $f['enviados_batch']++;
        $bot_em_uso[$f['bot_id']] = true;

        $ch = makeCurlHandle($f['token'], (string)$chat_id, (string)$f['mensagem']);
        curl_multi_add_handle($mh, $ch);
        $handles[(int)$ch] = ['campanha_id' => $cid, 'chatId' => $chat_id];
        $despachados++;
    }
    unset($f);
    return $despachados;
};

while (true) {
    if ((microtime(true) - $start_time) >= MAX_EXEC_SEC) {
        echo "Limite de tempo atingido — progresso salvo para próxima rodada." . PHP_EOL;
        break;
    }

    $tem_fila = false;
    foreach ($filas as $f) {
        if (!empty($f['fila'])) { $tem_fila = true; break; }
    }
    if (!$tem_fila && empty($handles)) break;

    $bot_em_uso = [];
    $despachar();

    if (empty($handles)) break;

    $tick_start = microtime(true);
    do {
        curl_multi_exec($mh, $active);
        if ($active > 0) curl_multi_select($mh, 0.005); // poll a cada 5ms
    } while ($active > 0);

    $rate_hit = 0;
    while ($info = curl_multi_info_read($mh)) {
        $ch  = $info['handle'];
        $key = (int)$ch;

        if (!isset($handles[$key])) {
            curl_multi_remove_handle($mh, $ch);
            continue;
        }

        $meta   = $handles[$key];
        $cid    = $meta['campanha_id'];
        $chat_id = $meta['chatId'];
        $res    = curl_multi_getcontent($ch) ?: '';
        $json   = json_decode($res, true);
        $ok     = (bool)($json['ok'] ?? false);

        // Tratamento de rate limit (429): registra para pausar após este tick
        if (!$ok && isset($json['parameters']['retry_after'])) {
            $rate_hit = max($rate_hit, (int)$json['parameters']['retry_after']);
        }

        $stmt_log->execute([$cid, $chat_id, $ok ? 'sucesso' : 'falha', substr($res, 0, 500)]);
        if ($ok) { $filas[$cid]['sucesso']++; } else { $filas[$cid]['falhas']++; }

        curl_multi_remove_handle($mh, $ch);
        unset($handles[$key]);
    }

    // Rate limit atingido: pausa o tempo indicado pelo Telegram
    if ($rate_hit > 0) {
        echo "Rate limit: aguardando {$rate_hit}s..." . PHP_EOL;
        sleep($rate_hit + 1);
    }

    // Delay mínimo entre ticks para respeitar o limite por bot (~25 msg/s)
    $elapsed = (microtime(true) - $tick_start) * 1_000_000;
    $restante = BOT_DELAY_US - $elapsed;
    if ($restante > 0) usleep((int)$restante);
}

curl_multi_close($mh);

foreach ($filas as $cid => $f) {
    $new_offset  = $f['offset'] + $f['enviados_batch'];
    // Concluída: processou menos que BATCH_SIZE (último batch) e fila vazia
    $concluida  = empty($f['fila']) && $f['enviados_batch'] < BATCH_SIZE;

    $pdo->prepare("
        UPDATE remarketing_campanhas
        SET offset_envio = ?, enviados = ?, entregues = ?, falhas = ?,
            status = ?,
            processado_em = IF(?, NOW(), processado_em)
        WHERE id = ?
    ")->execute([
        $new_offset,
        $f['sucesso'] + $f['falhas'],
        $f['sucesso'],
        $f['falhas'],
        $concluida ? 'concluida' : 'processando',
        $concluida ? 1 : 0,
        $cid,
    ]);

    $pct = $f['total'] > 0 ? round($new_offset / $f['total'] * 100) . '%' : '?';
    $label = $concluida ? 'Concluída' : "Parcial ($pct)";
    echo "$label  campanha #$cid | offset=$new_offset ok={$f['sucesso']} falhas={$f['falhas']}" . PHP_EOL;
}

flock($lock, LOCK_UN);
fclose($lock);

$duracao = round(microtime(true) - $start_time, 2);
echo "Rodada finalizada em {$duracao}s | Campanhas: " . count($filas) . PHP_EOL;
