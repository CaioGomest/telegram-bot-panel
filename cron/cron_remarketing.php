<?php
declare(strict_types=1);
require_once __DIR__ . '/../conexao.php';
require_once __DIR__ . '/../funcoes/cron_guarda.php';
exigirChamadaDeCron('cron_remarketing');
require_once __DIR__ . '/../funcoes/remarketing.php';

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
// Depois de N falhas seguidas pro MESMO bot sem nenhum sucesso, para de insistir com esse bot --
// sintoma de bot com token inválido/revogado (ex.: bot de demonstração com token falso),
// não tem por que tentar todos os milhares de destinatários pra só depois descobrir isso.
const MAX_FALHAS_SEGUIDAS_BOT = 10;

/**
 * A falha é "do destinatário" (ele bloqueou o bot, desativou a conta, o chat não existe mais) e NÃO do bot/campanha?
 * Confirmado ao vivo no Telegram real: envio a chat inexistente devolve 400 "chat not found"; usuário que bloqueou o bot devolve
 * 403. Esse tipo de falha é normal em remarketing (lead antigo) e não pode contar como "bot com problema", senão 10 bloqueios
 * seguidos abortavam a campanha inteira e descartavam todos os destinatários restantes. Token inválido (401/404), texto
 * inválido (400 de formatação) e erro de rede continuam contando.
 */
function falhaDoDestinatarioTelegram($json): bool
{
    if (!is_array($json) || ($json['ok'] ?? false)) {
        return false;
    }
    $codigo = (int) ($json['error_code'] ?? 0);
    $descricao = (string) ($json['description'] ?? '');
    if ($codigo === 403) {
        return true;
    }
    return $codigo === 400 && (bool) preg_match('/chat not found|user is deactivated|PEER_ID_INVALID|can\'t initiate conversation|have no rights to send|group chat was upgraded/i', $descricao);
}

$lock_file = sys_get_temp_dir() . '/remarketing_cron.lock';
$lock = fopen($lock_file, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo "Outra instância já está rodando." . PHP_EOL;
    exit;
}

$start_time = microtime(true);

// Banco que ainda não rodou o Atualizar Banco: a campanha antiga continua saindo (sem variante no registro de envios).
$grava_variante = colunaExiste('remarketing_envios', 'variante');
// Idem para o reenvio e a recorrência (colunas execucoes, acum_*, recorrencia): sem elas a campanha sai uma vez, como antes.
$tem_reenvio = colunaExiste('remarketing_campanhas', 'acum_falhas') && colunaExiste('remarketing_campanhas', 'recorrencia');

function curlTelegram(string $token, string $metodo, $campos, int $timeout): CurlHandle
{
    $ch = curl_init('https://api.telegram.org/bot' . $token . '/' . $metodo);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => is_array($campos) && !array_filter($campos, fn($v) => $v instanceof CURLFile) ? http_build_query($campos) : $campos,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    return $ch;
}

/**
 * Monta o handle certo pra este destinatário: texto puro (sem mídia), mídia via file_id
 * já conhecido (barato, igual texto) ou mídia via upload multipart (só acontece uma vez
 * por bot e variante -- a resposta traz o file_id, cacheado depois no loop que lê as respostas).
 * Cada lead recebe o texto da variante dele (A, B ou C) com {nome}, {saudacao} e {usuario} preenchidos e, se a campanha tem oferta, os botões.
 */
function makeCurlHandle(array $f, array $lead, int $variante): CurlHandle
{
    $token = (string) $lead['token'];
    $v = $f['variantes'][$variante];
    $teclado = $f['teclados'][$variante];
    $texto = remarketingAplicarVariaveis((string) $v['mensagem'], $lead);
    $chat_id = (string) $lead['id_telegram'];
    $botoes = $teclado !== null ? ['reply_markup' => $teclado] : [];

    if ($v['midia_caminho'] === '') {
        return curlTelegram($token, 'sendMessage', ['chat_id' => $chat_id, 'text' => mb_substr($texto, 0, REMARKETING_MENSAGEM_MAX), 'parse_mode' => 'HTML'] + $botoes, CURL_TIMEOUT);
    }

    $campo = $v['midia_tipo'] === 'video' ? 'video' : 'photo';
    $metodo = $v['midia_tipo'] === 'video' ? 'sendVideo' : 'sendPhoto';
    $parametros = ['chat_id' => $chat_id, 'caption' => mb_substr($texto, 0, REMARKETING_LEGENDA_MAX), 'parse_mode' => 'HTML'] + $botoes;

    $file_id = $f['file_ids'][$lead['bot_id'] . ':' . $variante] ?? '';
    if ($file_id !== '') {
        return curlTelegram($token, $metodo, $parametros + [$campo => $file_id], CURL_TIMEOUT);
    }

    $caminho_absoluto = remarketingCaminhoUploadSeguro((string) $v['midia_caminho']);
    if ($caminho_absoluto === null) {
        // Caminho salvo não resolve dentro de uploads/ -- não trava o envio, cai pra
        // texto puro em vez de quebrar a campanha inteira.
        return curlTelegram($token, 'sendMessage', ['chat_id' => $chat_id, 'text' => mb_substr($texto, 0, REMARKETING_MENSAGEM_MAX), 'parse_mode' => 'HTML'] + $botoes, CURL_TIMEOUT);
    }

    $mime = function_exists('mime_content_type') ? (mime_content_type($caminho_absoluto) ?: 'application/octet-stream') : 'application/octet-stream';
    $parametros[$campo] = new CURLFile($caminho_absoluto, $mime, basename($caminho_absoluto));
    // upload de mídia precisa de mais tempo que texto
    return curlTelegram($token, $metodo, $parametros, 30);
}

try {
    $pdo->beginTransaction();
    // Um fluxo (nas campanhas antigas, um bot) só envia remarketing a cada REMARKETING_INTERVALO_MINIMO_MIN minutos: a campanha agendada que chegou na hora
    // espera enquanto outra do mesmo fluxo estiver em envio ou tiver terminado há menos que isso. Ela segue "pendente" e sai sozinha quando o fluxo liberar.
    // (A mesma conta que a tela usa em remarketingBloqueioDoGrupo; aqui no SQL para campanha retida não ocupar as vagas do LIMIT.)
    $campanhas = $pdo->query("
        SELECT c.*
        FROM remarketing_campanhas c
        WHERE c.status IN ('pendente','processando')
          AND c.agendado_em <= NOW()
          AND (c.status = 'processando' OR NOT EXISTS (
                SELECT 1 FROM remarketing_campanhas o
                WHERE o.id_usuario = c.id_usuario
                  AND o.id_fluxo <=> c.id_fluxo
                  AND (c.id_fluxo IS NOT NULL OR o.bot_id <=> c.bot_id)
                  AND ((o.status = 'processando' AND o.id <> c.id)
                       OR (o.processado_em > NOW() - INTERVAL " . REMARKETING_INTERVALO_MINIMO_MIN . " MINUTE AND o.entregues > 0))
          ))
        ORDER BY c.agendado_em ASC
        LIMIT " . MAX_CAMPANHAS . "
        FOR UPDATE SKIP LOCKED
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Duas campanhas do mesmo fluxo na mesma hora: só a primeira sai agora (a outra espera as 3 horas), e nenhuma começa se o fluxo já tem uma em envio neste lote.
    $grupos_ocupados = [];
    foreach ($campanhas as $c) {
        if ($c['status'] === 'processando') {
            $grupos_ocupados[remarketingChaveGrupo($c)] = true;
        }
    }
    foreach ($campanhas as $i => $c) {
        if ($c['status'] !== 'pendente') {
            continue;
        }
        $chave = remarketingChaveGrupo($c);
        if (isset($grupos_ocupados[$chave])) {
            unset($campanhas[$i]);
            continue;
        }
        $grupos_ocupados[$chave] = true;
    }
    $campanhas = array_values($campanhas);

    foreach ($campanhas as &$c) {
        if ($c['status'] !== 'pendente') {
            continue;   // já estava saindo: só continua do ponto em que parou
        }
        if (!$tem_reenvio) {
            $pdo->prepare("UPDATE remarketing_campanhas SET status = 'processando' WHERE id = ?")->execute([$c['id']]);
        } elseif (remarketingTemExecucaoAnterior($c)) {
            // Reenvio ou repetição: guarda o que a execução anterior enviou, zera os contadores desta e deixa o período relativo (hoje, 7 dias...) fechar de novo no primeiro envio.
            $relativo = !in_array($c['periodo_tipo'] ?? 'total', ['total', 'custom'], true);
            // (A campanha que saiu antes desta versão tem execucoes = 0 mas já saiu uma vez: GREATEST(execucoes, 1) conta a anterior.)
            $pdo->prepare("UPDATE remarketing_campanhas SET status = 'processando', execucoes = GREATEST(execucoes, 1) + 1,
                    acum_entregues = acum_entregues + entregues, acum_falhas = acum_falhas + falhas,
                    total_destinatarios = 0, offset_envio = 0, enviados = 0, entregues = 0, falhas = 0, processado_em = NULL,
                    periodo_inicio = IF(?, NULL, periodo_inicio), periodo_fim = IF(?, NULL, periodo_fim)
                WHERE id = ?")->execute([$relativo ? 1 : 0, $relativo ? 1 : 0, $c['id']]);
            foreach (['total_destinatarios', 'offset_envio', 'enviados', 'entregues', 'falhas'] as $campo) {
                $c[$campo] = 0;
            }
            $c['processado_em'] = null;
            if ($relativo) {
                $c['periodo_inicio'] = $c['periodo_fim'] = null;
            }
        } else {
            $pdo->prepare("UPDATE remarketing_campanhas SET status = 'processando', execucoes = execucoes + 1 WHERE id = ?")->execute([$c['id']]);
        }
    }
    unset($c);
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
    $grava_variante
        ? "INSERT INTO remarketing_envios (campanha_id, id_telegram, resultado, resposta, variante) VALUES (?, ?, ?, ?, ?)"
        : "INSERT INTO remarketing_envios (campanha_id, id_telegram, resultado, resposta) VALUES (?, ?, ?, ?)"
);
$stmt_file_ids = $pdo->prepare("UPDATE remarketing_campanhas SET midia_file_ids = ? WHERE id = ?");

// $filas[campanha_id]: a campanha, as variantes, os teclados, os bots dela, a fila de leads do lote e os contadores da rodada.
$filas = [];
foreach ($campanhas as $c) {
    $cid    = (int)$c['id'];
    $total  = (int)$c['total_destinatarios'];
    $offset = (int)$c['offset_envio'];

    // Bots da campanha: os congelados ao iniciar; campanha antiga, o bot dela; campanha de fluxo sem a lista, os bots do fluxo agora.
    $bots_ids = remarketingIdsInteiros((string) ($c['bots_ids'] ?? ''));
    if (!$bots_ids && !empty($c['bot_id'])) {
        $bots_ids = [(int) $c['bot_id']];
    }
    if (!$bots_ids && !empty($c['id_fluxo'])) {
        $bots_ids = array_column(remarketingBotsDoFluxo($pdo, (int) $c['id_usuario'], (int) $c['id_fluxo']), 'id');
    }

    // Período relativo (hoje, ontem, 7 dias, 30 dias): fecha a janela no primeiro envio e ela não muda mais nas rodadas seguintes.
    $inicio = $c['periodo_inicio'] ?? null;
    $fim = $c['periodo_fim'] ?? null;
    if (($inicio === null || $fim === null) && !in_array($c['periodo_tipo'] ?? 'total', ['total', 'custom'], true)) {
        $janela = remarketingIntervaloPeriodo((string) $c['periodo_tipo']);
        if ($janela) {
            [$inicio, $fim] = $janela;
            $pdo->prepare("UPDATE remarketing_campanhas SET periodo_inicio = ?, periodo_fim = ? WHERE id = ?")->execute([$inicio, $fim, $cid]);
        }
    }
    [$onde, $params_publico] = remarketingCondicaoPublico((string) $c['audiencia'], $bots_ids, $inicio, $fim);

    // Calcula total na primeira rodada
    if ($total === 0 && $offset === 0) {
        $s = $pdo->prepare("SELECT COUNT(*) FROM leads l WHERE $onde");
        $s->execute($params_publico);
        $total = (int)$s->fetchColumn();
        $pdo->prepare("UPDATE remarketing_campanhas SET total_destinatarios = ? WHERE id = ?")
            ->execute([$total, $cid]);
    }

    // ORDER BY obrigatorio: o envio pagina com LIMIT/OFFSET entre execucoes do cron, e sem
    // ordem definida o MySQL nao garante a mesma sequencia de uma rodada pra outra. Na
    // pratica isso manda mensagem repetida pra uns leads e pula outros -- justo o que nao
    // pode acontecer num disparo em massa. l.id e imutavel, entao serve de ordem estavel.
    // O JOIN com bots (do dono da campanha) traz o token de cada lead: um fluxo pode ter vários bots.
    $s = $pdo->prepare("SELECT l.id_telegram, l.nome, l.nome_usuario_telegram, l.bot_id, b.token
        FROM leads l JOIN bots b ON b.id = l.bot_id AND b.id_usuario = :rm_dono
        WHERE $onde
        ORDER BY l.id LIMIT " . BATCH_SIZE . " OFFSET " . $offset);
    $s->execute($params_publico + ['rm_dono' => (int) $c['id_usuario']]);
    $leads = $s->fetchAll(PDO::FETCH_ASSOC);

    $variantes = remarketingVariantesDaCampanha($c);
    $ofertas = remarketingOfertasDaCampanha($c);
    $teclados = [];
    foreach (array_keys($variantes) as $i) {
        $t = remarketingTeclado($cid, $i, $ofertas);
        $teclados[$i] = $t === null ? null : json_encode($t);
    }
    // file_id da mídia por bot e variante (o file_id de um bot não vale em outro). Campanha antiga: o midia_file_id único é do bot dela.
    $file_ids = json_decode((string) ($c['midia_file_ids'] ?? ''), true);
    $file_ids = is_array($file_ids) ? $file_ids : [];
    if (!empty($c['midia_file_id']) && !empty($c['bot_id'])) {
        $file_ids += [((int) $c['bot_id']) . ':0' => (string) $c['midia_file_id']];
    }

    $filas[$cid] = [
        'variantes' => $variantes,
        'teclados'  => $teclados,
        'file_ids'  => $file_ids,
        'bots'      => $bots_ids,
        'offset'    => $offset,
        'sucesso'   => (int)$c['entregues'],
        'falhas'    => (int)$c['falhas'],
        'enviados'  => (int)$c['enviados'],
        'total'     => $total,
        'fila'      => $leads,
        'buscados'  => count($leads),
        'consumidos' => 0,           // leads do lote já despachados ou descartados (anda o OFFSET da próxima rodada)
        'falhas_seguidas' => [],     // por bot
        'bots_bloqueados' => [],     // por bot
    ];
}

// Estratégia: a cada tick, enviamos 1 mensagem por bot (bots diferentes em paralelo).
// Isso respeita o limite do Telegram por bot (~30/s) e maximiza throughput
// quando há campanhas de múltiplos usuários com bots distintos. A ordem da fila de cada
// campanha nunca é trocada (o OFFSET da próxima rodada depende dela): a campanha despacha
// os próximos leads enquanto os bots deles estiverem livres e, quando o bot do próximo lead
// já está ocupado neste tick, espera o tick seguinte.
//
// Exemplo: 5 campanhas com bots diferentes → 5 envios paralelos por tick
//          5 campanhas com o mesmo bot     → 1 envio por tick (throttling correto)
//          1 campanha de fluxo com 3 bots  → até 3 envios por tick (um por bot)

$mh = curl_multi_init();

// $handles[(int)$ch] = ['campanha_id' => int, 'chatId' => string, 'bot_id' => int, 'variante' => int]
$handles = [];

// $bot_em_uso[bot_id] = true (bot já tem um handle pendente neste tick)
$bot_em_uso = [];

$despachar = function () use (&$filas, &$handles, &$bot_em_uso, $mh): int {
    $despachados = 0;
    foreach ($filas as $cid => &$f) {
        while (!empty($f['fila'])) {
            $lead = $f['fila'][0];
            $bot_id = (int) $lead['bot_id'];
            if (isset($f['bots_bloqueados'][$bot_id])) {
                // Bot com token recusado nesta campanha: o lead dele fica sem a mensagem (não adianta insistir).
                array_shift($f['fila']);
                $f['consumidos']++;
                continue;
            }
            if (isset($bot_em_uso[$bot_id])) break;   // já tem envio deste bot em voo

            array_shift($f['fila']);
            $variante = remarketingVarianteDaPosicao($f['offset'] + $f['consumidos'], count($f['variantes']));
            $f['consumidos']++;
            $bot_em_uso[$bot_id] = true;

            $ch = makeCurlHandle($f, $lead, $variante);
            curl_multi_add_handle($mh, $ch);
            $handles[(int)$ch] = ['campanha_id' => $cid, 'chatId' => (string) $lead['id_telegram'], 'bot_id' => $bot_id, 'variante' => $variante, 'token' => (string) $lead['token']];
            $despachados++;
        }
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
        $bot_id = $meta['bot_id'];
        $variante = $meta['variante'];
        $res    = curl_multi_getcontent($ch) ?: '';
        $json   = json_decode($res, true);
        $ok     = (bool)($json['ok'] ?? false);
        if (!$ok) { registrarRespostaTelegram($meta['token'], $json); }

        // Tratamento de rate limit (429): registra para pausar após este tick
        if (!$ok && isset($json['parameters']['retry_after'])) {
            $rate_hit = max($rate_hit, (int)$json['parameters']['retry_after']);
        }

        // Primeiro envio bem-sucedido de mídia deste bot e variante sem file_id: cacheia
        // pra não reenviar o arquivo pros próximos destinatários (mesma rodada e
        // rodadas futuras, já que fica salvo no banco).
        $chave_file = $bot_id . ':' . $variante;
        if ($ok && $filas[$cid]['variantes'][$variante]['midia_caminho'] !== '' && empty($filas[$cid]['file_ids'][$chave_file])) {
            $file_id = null;
            if (!empty($json['result']['photo']) && is_array($json['result']['photo'])) {
                $file_id = end($json['result']['photo'])['file_id'] ?? null; // Telegram lista do menor pro maior
            } elseif (!empty($json['result']['video']['file_id'])) {
                $file_id = $json['result']['video']['file_id'];
            }
            if ($file_id) {
                $filas[$cid]['file_ids'][$chave_file] = $file_id;
                $stmt_file_ids->execute([json_encode($filas[$cid]['file_ids']), $cid]);
            }
        }

        // Resposta completa só tem valor real quando falha (é o que ajuda a entender por
        // quê); pra sucesso o contador agregado já basta, guardar o JSON inteiro é gasto de
        // banco à toa numa tabela que já cresce rápido (ver anotacoes/OBSERVACOES.md).
        $resposta_salva = $ok ? null : substr($res, 0, 500);
        $parametros_log = [$cid, $chat_id, $ok ? 'sucesso' : 'falha', $resposta_salva];
        if ($grava_variante) $parametros_log[] = $variante;
        try {
            $stmt_log->execute($parametros_log);
        } catch (PDOException $e_log) {
            // O dono excluiu a campanha enquanto ela saía: a chave estrangeira recusa o registro (SQLSTATE 23000). Para esta campanha e segue com as outras
            // (sem isso, a exceção derrubava a rodada inteira e as outras campanhas perdiam o progresso).
            if ($e_log->getCode() !== '23000') {
                throw $e_log;
            }
            $filas[$cid]['fila'] = [];
            $filas[$cid]['apagada'] = true;
            curl_multi_remove_handle($mh, $ch);
            unset($handles[$key]);
            continue;
        }
        if ($ok) {
            $filas[$cid]['sucesso']++;
            $filas[$cid]['falhas_seguidas'][$bot_id] = 0;
        } elseif (falhaDoDestinatarioTelegram($json)) {
            // Conta como falha da campanha (aparece nos totais e no detalhe), mas não é sinal de bot quebrado.
            $filas[$cid]['falhas']++;
        } else {
            $filas[$cid]['falhas']++;
            $filas[$cid]['falhas_seguidas'][$bot_id] = ($filas[$cid]['falhas_seguidas'][$bot_id] ?? 0) + 1;
            // N falhas seguidas sem nenhum sucesso no meio = sintoma de bot com token
            // inválido/revogado (achado ao vivo: bot de demonstração com token falso
            // derrubou 536 tentativas seguidas, todas com o mesmo erro estrutural do
            // Telegram, antes do processo travar). Não faz sentido insistir pros
            // milhares de destinatários restantes DESSE bot -- os leads dele saem da fila
            // (os dos outros bots do fluxo seguem normalmente).
            if ($filas[$cid]['falhas_seguidas'][$bot_id] >= MAX_FALHAS_SEGUIDAS_BOT && empty($filas[$cid]['bots_bloqueados'][$bot_id])) {
                $filas[$cid]['bots_bloqueados'][$bot_id] = true;
                $descartados = 0;
                foreach ($filas[$cid]['fila'] as $lead_fila) {
                    if ((int) $lead_fila['bot_id'] === $bot_id) $descartados++;
                }
                echo "Campanha #$cid: bot #$bot_id parado após " . MAX_FALHAS_SEGUIDAS_BOT . " falhas seguidas (token provavelmente inválido); $descartados destinatário(s) dele neste lote ficam sem a mensagem. Último erro: " . substr($res, 0, 200) . PHP_EOL;
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
    if (!empty($f['apagada'])) {
        echo "Campanha #$cid excluída durante o envio: interrompida." . PHP_EOL;
        continue;
    }
    // Leads que nem chegaram a ser despachados (bot parado) contam como consumidos: o OFFSET da próxima rodada precisa passar por eles.
    foreach ($f['fila'] as $lead_restante) {
        if (!empty($f['bots_bloqueados'][(int) $lead_restante['bot_id']])) {
            $f['consumidos']++;
        } else {
            break;
        }
    }
    $new_offset  = $f['offset'] + $f['consumidos'];
    $todos_bots_parados = $f['bots'] && count(array_intersect_key(array_flip($f['bots']), $f['bots_bloqueados'])) === count($f['bots']);
    // Concluída: pegou menos que BATCH_SIZE leads (último lote) e a fila esvaziou -- mas se TODOS os bots da campanha foram parados
    // por falhas seguidas, não foi sucesso: é falha (a fila foi descartada, não enviada).
    $concluida = !$todos_bots_parados && empty($f['fila']) && $f['buscados'] < BATCH_SIZE;
    $status = $todos_bots_parados ? 'falha' : ($concluida ? 'concluida' : 'processando');

    // Terminou e a campanha tem recorrência (lida de novo agora: o dono pode ter desligado no meio do envio): volta a ficar agendada para a próxima
    // ocorrência, que nunca cai antes de o fluxo liberar (3 horas depois deste fim). "A cada X horas" conta do começo desta execução.
    $proxima = null;
    if ($tem_reenvio && $status === 'concluida') {
        $agora_db = $pdo->prepare("SELECT recorrencia, agendado_em FROM remarketing_campanhas WHERE id = ?");
        $agora_db->execute([$cid]);
        $fresca = $agora_db->fetch(PDO::FETCH_ASSOC);
        $rec = $fresca ? remarketingRecorrenciaDaCampanha($fresca) : null;
        if ($rec !== null) {
            $proxima = remarketingProximaExecucao($rec, time() + REMARKETING_INTERVALO_MINIMO_MIN * 60, strtotime((string) $fresca['agendado_em']) ?: null);
            if ($proxima !== null) {
                $status = 'pendente';
            }
        }
    }

    $parametros = [
        $new_offset,
        $f['sucesso'] + $f['falhas'],
        $f['sucesso'],
        $f['falhas'],
        $status,
        ($concluida || $todos_bots_parados) ? 1 : 0,
    ];
    if ($tem_reenvio) {
        $parametros[] = $proxima !== null ? date('Y-m-d H:i:s', $proxima) : null;
        $parametros[] = $todos_bots_parados ? 1 : 0;   // campanha com falha não repete sozinha
    }
    $parametros[] = $cid;
    $pdo->prepare("
        UPDATE remarketing_campanhas
        SET offset_envio = ?, enviados = ?, entregues = ?, falhas = ?,
            status = ?,
            processado_em = IF(?, NOW(), processado_em)" . ($tem_reenvio ? ",
            agendado_em = COALESCE(?, agendado_em),
            recorrencia = IF(?, NULL, recorrencia)" : '') . "
        WHERE id = ?
    ")->execute($parametros);

    $pct = $f['total'] > 0 ? round($new_offset / $f['total'] * 100) . '%' : '?';
    $label = $todos_bots_parados ? 'Bloqueada (bot com falha)' : ($concluida ? 'Concluída' : "Parcial ($pct)");
    echo "$label  campanha #$cid | offset=$new_offset ok={$f['sucesso']} falhas={$f['falhas']}" . ($proxima !== null ? ' | repete em ' . date('d/m/Y H:i', $proxima) : '') . PHP_EOL;
}

flock($lock, LOCK_UN);
fclose($lock);

$duracao = round(microtime(true) - $start_time, 2);
echo "Rodada finalizada em {$duracao}s | Campanhas: " . count($filas) . PHP_EOL;
