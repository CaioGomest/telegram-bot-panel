<?php
declare(strict_types=1);
require_once __DIR__ . '/conexao.php';

// ── Configurações ────────────────────────────────────────────────────────────
const BATCH_SIZE    = 1000;  // leads por campanha por rodada
const MAX_CAMPANHAS = 20;    // campanhas simultâneas por rodada
const BOT_DELAY_US  = 40000; // 40ms entre ticks por bot = ~25 msg/s (limite: 30/s)
const CURL_TIMEOUT  = 8;
const MAX_EXEC_SEC  = 240;   // 4 min de margem (Hostinger permite ~5 min no cron)

// ── Garante execução única ───────────────────────────────────────────────────
$lockFile = sys_get_temp_dir() . '/remarketing_cron.lock';
$lock = fopen($lockFile, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo "Outra instância já está rodando." . PHP_EOL;
    exit;
}

$startTime = microtime(true);

// ── Helpers ──────────────────────────────────────────────────────────────────
function audienciaFiltro(string $audiencia): string
{
    return $audiencia === 'nao_comprou'
        ? " AND NOT EXISTS (SELECT 1 FROM vendas v WHERE v.id_telegram = l.id_telegram AND v.bot_id = l.bot_id AND v.status = 'pago')"
        : " AND EXISTS    (SELECT 1 FROM vendas v WHERE v.id_telegram = l.id_telegram AND v.bot_id = l.bot_id AND v.status = 'pago')";
}

function makeCurlHandle(string $token, string $chatId, string $text): CurlHandle
{
    $ch = curl_init('https://api.telegram.org/bot' . $token . '/sendMessage');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'HTML',
        ]),
        CURLOPT_TIMEOUT        => CURL_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    return $ch;
}

// ── Busca campanhas com lock transacional ────────────────────────────────────
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

// ── Carrega leads de cada campanha (LIMIT/OFFSET — não tudo em memória) ──────
$stmtLog = $pdo->prepare(
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
        $sqlCnt = "SELECT COUNT(*) FROM leads l WHERE l.bot_id = ?" . audienciaFiltro($c['audiencia']);
        $s = $pdo->prepare($sqlCnt);
        $s->execute([$c['bot_id']]);
        $total = (int)$s->fetchColumn();
        $pdo->prepare("UPDATE remarketing_campanhas SET total_destinatarios = ? WHERE id = ?")
            ->execute([$total, $cid]);
    }

    // Busca apenas o batch desta rodada
    $sqlLeads = "SELECT l.id_telegram FROM leads l WHERE l.bot_id = ?"
        . audienciaFiltro($c['audiencia'])
        . " LIMIT " . BATCH_SIZE . " OFFSET " . $offset;
    $s = $pdo->prepare($sqlLeads);
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

// ── Loop principal com curl_multi ────────────────────────────────────────────
//
// Estratégia: a cada tick, enviamos 1 mensagem por bot (bots diferentes em paralelo).
// Isso respeita o limite do Telegram por bot (~30/s) e maximiza throughput
// quando há campanhas de múltiplos usuários com bots distintos.
//
// Exemplo: 5 campanhas com bots diferentes → 5 envios paralelos por tick
//          5 campanhas com o mesmo bot     → 1 envio por tick (throttling correto)

$mh = curl_multi_init();

// $handles[(int)$ch] = ['campanha_id' => int, 'chatId' => string]
$handles = [];

// $botEmUso[bot_id] = true (bot já tem um handle pendente neste tick)
$botEmUso = [];

// Função: adiciona ao multi-handle um envio por bot disponível
$despachar = function () use (&$filas, &$handles, &$botEmUso, $mh): int {
    $despachados = 0;
    foreach ($filas as $cid => &$f) {
        if (empty($f['fila'])) continue;
        if (isset($botEmUso[$f['bot_id']])) continue; // já tem envio deste bot em voo

        $chatId = array_shift($f['fila']);
        $f['enviados_batch']++;
        $botEmUso[$f['bot_id']] = true;

        $ch = makeCurlHandle($f['token'], (string)$chatId, (string)$f['mensagem']);
        curl_multi_add_handle($mh, $ch);
        $handles[(int)$ch] = ['campanha_id' => $cid, 'chatId' => $chatId];
        $despachados++;
    }
    unset($f);
    return $despachados;
};

// Loop até esvaziar todas as filas ou atingir limite de tempo
while (true) {
    // Verifica tempo restante
    if ((microtime(true) - $startTime) >= MAX_EXEC_SEC) {
        echo "Limite de tempo atingido — progresso salvo para próxima rodada." . PHP_EOL;
        break;
    }

    // Verifica se há algo para enviar
    $temFila = false;
    foreach ($filas as $f) {
        if (!empty($f['fila'])) { $temFila = true; break; }
    }
    if (!$temFila && empty($handles)) break;

    // Despacha novos envios (1 por bot)
    $botEmUso = [];
    $despachar();

    if (empty($handles)) break;

    // Executa curl_multi e aguarda conclusão
    $tickStart = microtime(true);
    do {
        curl_multi_exec($mh, $active);
        if ($active > 0) curl_multi_select($mh, 0.005); // poll a cada 5ms
    } while ($active > 0);

    // Coleta resultados
    $rateHit = 0;
    while ($info = curl_multi_info_read($mh)) {
        $ch  = $info['handle'];
        $key = (int)$ch;

        if (!isset($handles[$key])) {
            curl_multi_remove_handle($mh, $ch);
            continue;
        }

        $meta   = $handles[$key];
        $cid    = $meta['campanha_id'];
        $chatId = $meta['chatId'];
        $res    = curl_multi_getcontent($ch) ?: '';
        $json   = json_decode($res, true);
        $ok     = (bool)($json['ok'] ?? false);

        // Tratamento de rate limit (429): registra para pausar após este tick
        if (!$ok && isset($json['parameters']['retry_after'])) {
            $rateHit = max($rateHit, (int)$json['parameters']['retry_after']);
        }

        $stmtLog->execute([$cid, $chatId, $ok ? 'sucesso' : 'falha', substr($res, 0, 500)]);
        if ($ok) { $filas[$cid]['sucesso']++; } else { $filas[$cid]['falhas']++; }

        curl_multi_remove_handle($mh, $ch);
        unset($handles[$key]);
    }

    // Rate limit atingido: pausa o tempo indicado pelo Telegram
    if ($rateHit > 0) {
        echo "Rate limit: aguardando {$rateHit}s..." . PHP_EOL;
        sleep($rateHit + 1);
    }

    // Delay mínimo entre ticks para respeitar o limite por bot (~25 msg/s)
    $elapsed = (microtime(true) - $tickStart) * 1_000_000;
    $restante = BOT_DELAY_US - $elapsed;
    if ($restante > 0) usleep((int)$restante);
}

curl_multi_close($mh);

// ── Salva progresso de cada campanha ─────────────────────────────────────────
foreach ($filas as $cid => $f) {
    $newOffset  = $f['offset'] + $f['enviados_batch'];
    // Concluída: processou menos que BATCH_SIZE (último batch) e fila vazia
    $concluida  = empty($f['fila']) && $f['enviados_batch'] < BATCH_SIZE;

    $pdo->prepare("
        UPDATE remarketing_campanhas
        SET offset_envio = ?, enviados = ?, entregues = ?, falhas = ?,
            status = ?,
            processado_em = IF(?, NOW(), processado_em)
        WHERE id = ?
    ")->execute([
        $newOffset,
        $f['sucesso'] + $f['falhas'],
        $f['sucesso'],
        $f['falhas'],
        $concluida ? 'concluida' : 'processando',
        $concluida ? 1 : 0,
        $cid,
    ]);

    $pct = $f['total'] > 0 ? round($newOffset / $f['total'] * 100) . '%' : '?';
    $label = $concluida ? 'Concluída' : "Parcial ($pct)";
    echo "$label  campanha #$cid | offset=$newOffset ok={$f['sucesso']} falhas={$f['falhas']}" . PHP_EOL;
}

flock($lock, LOCK_UN);
fclose($lock);

$duracao = round(microtime(true) - $startTime, 2);
echo "Rodada finalizada em {$duracao}s | Campanhas: " . count($filas) . PHP_EOL;
