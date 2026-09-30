<?php
declare(strict_types=1);
require_once __DIR__ . '/../conexao.php';

const BATCH_SIZE    = 1000;  // leads por campanha por rodada
const MAX_CAMPANHAS = 20;    // campanhas simultâneas por rodada
const BOT_DELAY_US  = 40000; // 40ms entre ticks por bot = ~25 msg/s (limite: 30/s)
const CURL_TIMEOUT  = 8;
const MAX_EXEC_SEC  = 240;   // 4 min de margem (Hostinger permite ~5 min no cron)
// Teto pra UM tick do curl_multi (não pro script todo). O MAX_EXEC_SEC só é conferido
// ENTRE ticks -- se um tick nunca voltar $active a 0 (travou de verdade, achado ao vivo:
// 28/09, campanha com mídia ficou presa ~3h, precisou matar o processo manualmente), o
// script inteiro fica preso, ignorando o limite de 4min. Isso força saída do tick mesmo
// se algum handle nunca terminar.
const MAX_TICK_SEC  = 40;
// Depois de N falhas seguidas pro MESMO bot sem nenhum sucesso, para de insistir --
// sintoma de bot com token inválido/revogado (ex.: bot de demonstração com token falso),
// não tem por que tentar todos os milhares de destinatários pra só depois descobrir isso.
const MAX_FALHAS_SEGUIDAS_BOT = 10;

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

function curlSendMessage(string $token, string $chat_id, string $text): CurlHandle
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

/**
 * Mesma proteção de webhook.php::resolverCaminhoUploadSeguro(), copiada localmente --
 * este cron não inclui webhook.php. Garante que só caminho de verdade dentro de
 * uploads/ é aceito antes de montar o upload multipart.
 */
function resolverCaminhoUploadSeguroRemarketing(string $caminho): ?string
{
    if ($caminho === '' || strpos($caminho, 'uploads/') !== 0) {
        return null;
    }
    $base_real = realpath(__DIR__ . '/../uploads');
    if ($base_real === false) {
        return null;
    }
    $real = realpath(__DIR__ . '/../' . $caminho);
    if ($real === false) {
        return null;
    }
    if ($real !== $base_real && strpos($real, $base_real . DIRECTORY_SEPARATOR) !== 0) {
        return null;
    }
    return $real;
}

/**
 * Monta o handle certo pra este destinatário: texto puro (sem mídia), mídia via file_id
 * já conhecido (barato, igual texto) ou mídia via upload multipart (só acontece uma vez
 * por campanha -- a resposta traz o file_id, cacheado depois no loop que lê as respostas).
 */
function makeCurlHandle(array $f, string $chat_id): CurlHandle
{
    $token = (string) $f['token'];
    $texto = (string) $f['mensagem'];

    if (empty($f['midia_caminho'])) {
        return curlSendMessage($token, $chat_id, $texto);
    }

    $campo = $f['midia_tipo'] === 'video' ? 'video' : 'photo';
    $metodo = $f['midia_tipo'] === 'video' ? 'sendVideo' : 'sendPhoto';
    $parametros = ['chat_id' => $chat_id, 'caption' => $texto, 'parse_mode' => 'HTML'];

    if (!empty($f['midia_file_id'])) {
        $parametros[$campo] = $f['midia_file_id'];
        $ch = curl_init('https://api.telegram.org/bot' . $token . '/' . $metodo);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($parametros),
            CURLOPT_TIMEOUT        => CURL_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        return $ch;
    }

    $caminho_absoluto = resolverCaminhoUploadSeguroRemarketing((string) $f['midia_caminho']);
    if ($caminho_absoluto === null) {
        // Caminho salvo não resolve dentro de uploads/ -- não trava o envio, cai pra
        // texto puro em vez de quebrar a campanha inteira.
        return curlSendMessage($token, $chat_id, $texto);
    }

    $mime = function_exists('mime_content_type') ? (mime_content_type($caminho_absoluto) ?: 'application/octet-stream') : 'application/octet-stream';
    $parametros[$campo] = new CURLFile($caminho_absoluto, $mime, basename($caminho_absoluto));
    $ch = curl_init('https://api.telegram.org/bot' . $token . '/' . $metodo);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $parametros,
        CURLOPT_TIMEOUT        => 30, // upload de mídia precisa de mais tempo que texto
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    return $ch;
}

try {
    $pdo->beginTransaction();
    $campanhas = $pdo->query("
        SELECT c.id, c.bot_id, c.audiencia, c.mensagem, c.offset_envio,
               c.entregues, c.falhas, c.total_destinatarios, b.token,
               c.midia_caminho, c.midia_tipo, c.midia_file_id
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
$stmt_file_id = $pdo->prepare("UPDATE remarketing_campanhas SET midia_file_id = ? WHERE id = ?");

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

    // ORDER BY obrigatorio: o envio pagina com LIMIT/OFFSET entre execucoes do cron, e sem
    // ordem definida o MySQL nao garante a mesma sequencia de uma rodada pra outra. Na
    // pratica isso manda mensagem repetida pra uns leads e pula outros -- justo o que nao
    // pode acontecer num disparo em massa. l.id e imutavel, entao serve de ordem estavel.
    $sql_leads = "SELECT l.id_telegram FROM leads l WHERE l.bot_id = ?"
        . audienciaFiltro($c['audiencia'])
        . " ORDER BY l.id LIMIT " . BATCH_SIZE . " OFFSET " . $offset;
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
        'midia_caminho' => $c['midia_caminho'],
        'midia_tipo'    => $c['midia_tipo'],
        'midia_file_id' => $c['midia_file_id'],
        'falhas_seguidas' => 0,
        'bot_bloqueado' => false,
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

        $ch = makeCurlHandle($f, (string)$chat_id);
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
    $tick_travado = false;
    do {
        curl_multi_exec($mh, $active);
        if ($active > 0) curl_multi_select($mh, 0.005); // poll a cada 5ms
        if ($active > 0 && (microtime(true) - $tick_start) >= MAX_TICK_SEC) {
            // Nunca deveria acontecer (CURLOPT_TIMEOUT já limita cada request), mas se
            // acontecer o script inteiro travaria pra sempre sem isso -- MAX_EXEC_SEC só é
            // conferido ENTRE ticks, nunca dentro de um. Encerra a rodada aqui: o progresso
            // já feito fica salvo (offset_envio) e o cron tenta de novo na próxima chamada.
            echo "Tick travado (>" . MAX_TICK_SEC . "s, algum request nunca terminou) -- encerrando a rodada." . PHP_EOL;
            $tick_travado = true;
            break;
        }
    } while ($active > 0);
    if ($tick_travado) break;

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

        // Primeiro envio bem-sucedido de campanha com mídia ainda sem file_id: cacheia
        // pra não reenviar o arquivo pros próximos destinatários (mesma rodada e
        // rodadas futuras, já que fica salvo no banco).
        if ($ok && !empty($filas[$cid]['midia_caminho']) && empty($filas[$cid]['midia_file_id'])) {
            $file_id = null;
            if (!empty($json['result']['photo']) && is_array($json['result']['photo'])) {
                $file_id = end($json['result']['photo'])['file_id'] ?? null; // Telegram lista do menor pro maior
            } elseif (!empty($json['result']['video']['file_id'])) {
                $file_id = $json['result']['video']['file_id'];
            }
            if ($file_id) {
                $filas[$cid]['midia_file_id'] = $file_id;
                $stmt_file_id->execute([$file_id, $cid]);
            }
        }

        // Resposta completa só tem valor real quando falha (é o que ajuda a entender por
        // quê); pra sucesso o contador agregado já basta, guardar o JSON inteiro é gasto de
        // banco à toa numa tabela que já cresce rápido (ver anotacoes/HISTORICO-CONSOLIDADO.md).
        $resposta_salva = $ok ? null : substr($res, 0, 500);
        $stmt_log->execute([$cid, $chat_id, $ok ? 'sucesso' : 'falha', $resposta_salva]);
        if ($ok) {
            $filas[$cid]['sucesso']++;
            $filas[$cid]['falhas_seguidas'] = 0;
        } else {
            $filas[$cid]['falhas']++;
            $filas[$cid]['falhas_seguidas']++;
            // N falhas seguidas sem nenhum sucesso no meio = sintoma de bot com token
            // inválido/revogado (achado ao vivo: bot de demonstração com token falso
            // derrubou 536 tentativas seguidas, todas com o mesmo erro estrutural do
            // Telegram, antes do processo travar). Não faz sentido insistir pros
            // milhares de destinatários restantes -- esvazia a fila e para com essa
            // campanha nesta rodada.
            if ($filas[$cid]['falhas_seguidas'] >= MAX_FALHAS_SEGUIDAS_BOT && !$filas[$cid]['bot_bloqueado']) {
                $filas[$cid]['bot_bloqueado'] = true;
                $descartados = count($filas[$cid]['fila']);
                $filas[$cid]['fila'] = [];
                echo "Campanha #$cid: $descartados destinatário(s) restantes descartados após " . MAX_FALHAS_SEGUIDAS_BOT . " falhas seguidas (bot provavelmente com token inválido). Último erro: " . substr($res, 0, 200) . PHP_EOL;
            }
        }

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
    // Concluída: processou menos que BATCH_SIZE (último batch) e fila vazia -- mas só
    // conta como concluída de verdade se a fila esvaziou por ter processado todo mundo,
    // não porque o circuit breaker descartou o resto (aí é falha, não sucesso).
    $concluida  = !$f['bot_bloqueado'] && empty($f['fila']) && $f['enviados_batch'] < BATCH_SIZE;
    $status = $f['bot_bloqueado'] ? 'falha' : ($concluida ? 'concluida' : 'processando');

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
        $status,
        ($concluida || $f['bot_bloqueado']) ? 1 : 0,
        $cid,
    ]);

    $pct = $f['total'] > 0 ? round($new_offset / $f['total'] * 100) . '%' : '?';
    $label = $f['bot_bloqueado'] ? 'Bloqueada (bot com falha)' : ($concluida ? 'Concluída' : "Parcial ($pct)");
    echo "$label  campanha #$cid | offset=$new_offset ok={$f['sucesso']} falhas={$f['falhas']}" . PHP_EOL;
}

flock($lock, LOCK_UN);
fclose($lock);

$duracao = round(microtime(true) - $start_time, 2);
echo "Rodada finalizada em {$duracao}s | Campanhas: " . count($filas) . PHP_EOL;
