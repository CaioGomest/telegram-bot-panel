<?php
declare(strict_types=1);

/**
 * Prévias que somem (Fluxo Guiado): o lead clica no botão, recebe as mídias protegidas
 * (sem encaminhar/salvar) e elas são apagadas sozinhas depois do tempo escolhido; no lugar
 * entra uma mensagem de conversão.
 *
 * Apagar é sempre via fila (`mensagens_agendadas`): quem envia agenda cada message_id com
 * `apagar_em`. Até 60s o próprio webhook espera (depois de responder 200 pro Telegram) e
 * processa a fila daquele chat; acima disso, ou se a hospedagem não deixar segurar a
 * requisição, a fila é esvaziada por `apagarMensagensAgendadasVencidas()`, chamada pelo cron
 * de 1 minuto. Cada linha é "reivindicada" com UPDATE antes de apagar, então webhook e cron
 * nunca apagam/enviam a mesma mensagem duas vezes.
 */

const PREVIAS_MAX_MIDIAS = 8;
const PREVIAS_ESPERA_MAXIMA_NO_WEBHOOK = 60;

function previasTiposMidia(): array
{
    return ['image' => ['sendPhoto', 'photo'], 'video' => ['sendVideo', 'video'], 'audio' => ['sendAudio', 'audio']];
}

/** Mídias configuradas que existem de verdade em uploads/ (caminho absoluto já resolvido). */
function previasMidiasEnviaveis(array $previas): array
{
    $validas = [];
    foreach (array_slice((array) ($previas['midias'] ?? []), 0, PREVIAS_MAX_MIDIAS) as $m) {
        $tipo = (string) ($m['tipo'] ?? '');
        $caminho = function_exists('resolverCaminhoUploadSeguro') ? resolverCaminhoUploadSeguro((string) ($m['path'] ?? '')) : null;
        if (isset(previasTiposMidia()[$tipo]) && $caminho) {
            $validas[] = ['tipo' => $tipo, 'caminho' => $caminho];
        }
    }
    return $validas;
}

function previasAtivas(array $dados_basico): bool
{
    $previas = $dados_basico['previas'] ?? [];
    return !empty($previas['ativo']) && previasMidiasEnviaveis($previas) !== [];
}

/** 'nunca' = 1 visualização por lead; 'diario' = 1 a cada 24h; 'sempre' = sem limite. */
function previaLiberadaParaLead(PDO $pdo, int $bot_id, string $id_telegram, string $regra): bool
{
    if ($regra === 'sempre') {
        return true;
    }
    $stmt = $pdo->prepare("SELECT visto_em FROM previas_vistas WHERE bot_id = ? AND id_telegram = ?");
    $stmt->execute([$bot_id, $id_telegram]);
    $visto_em = $stmt->fetchColumn();
    if ($visto_em === false) {
        return true;
    }
    return $regra === 'diario' && strtotime((string) $visto_em) <= time() - 86400;
}

function registrarPreviaVista(PDO $pdo, int $bot_id, string $id_telegram): void
{
    $pdo->prepare("INSERT INTO previas_vistas (bot_id, id_telegram, visto_em) VALUES (?, ?, NOW())
                   ON DUPLICATE KEY UPDATE visto_em = NOW()")->execute([$bot_id, $id_telegram]);
}

function agendarApagarMensagem(PDO $pdo, int $bot_id, string $id_telegram, int $id_mensagem, int $segundos, ?array $depois = null): int
{
    $pdo->prepare("INSERT INTO mensagens_agendadas (bot_id, id_telegram, message_id, apagar_em, depois_json)
                   VALUES (?, ?, ?, NOW() + INTERVAL ? SECOND, ?)")
        ->execute([$bot_id, $id_telegram, $id_mensagem, $segundos, $depois ? json_encode($depois, JSON_UNESCAPED_UNICODE) : null]);
    return (int) $pdo->lastInsertId();
}

/** Apaga uma mensagem da fila (e manda a "mensagem de depois", se houver). Retorna false se outro processo já pegou. */
function processarMensagemAgendada(PDO $pdo, array $linha, string $token): bool
{
    $reivindicou = $pdo->prepare("UPDATE mensagens_agendadas SET apagada = 1 WHERE id = ? AND apagada = 0");
    $reivindicou->execute([$linha['id']]);
    if ($reivindicou->rowCount() === 0) {
        return false;
    }
    requisicaoTelegram($token, 'deleteMessage', ['chat_id' => $linha['id_telegram'], 'message_id' => $linha['message_id']]);
    if (!empty($linha['depois_json'])) {
        $depois = json_decode((string) $linha['depois_json'], true);
        if (is_array($depois)) {
            requisicaoTelegram($token, 'sendMessage', $depois);
        }
    }
    return true;
}

/** Usado pelo cron de 1 minuto: apaga o que já venceu e o webhook não chegou a apagar. */
function apagarMensagensAgendadasVencidas(PDO $pdo, int $limite = 200): int
{
    $stmt = $pdo->prepare("SELECT m.*, b.token FROM mensagens_agendadas m JOIN bots b ON b.id = m.bot_id
                           WHERE m.apagada = 0 AND m.apagar_em <= NOW() ORDER BY m.apagar_em ASC LIMIT " . (int) $limite);
    $stmt->execute();
    $feitas = 0;
    foreach ($stmt->fetchAll() as $linha) {
        garantirConexaoBanco();
        $pdo = $GLOBALS['pdo'];
        if (processarMensagemAgendada($pdo, $linha, (string) $linha['token'])) {
            $feitas++;
        }
    }
    $pdo->exec("DELETE FROM mensagens_agendadas WHERE apagada = 1 AND apagar_em < NOW() - INTERVAL 1 DAY");
    return $feitas;
}

function enviarMidiaPrevia(string $token, string $id_chat, array $midia, bool $proteger): ?int
{
    [$metodo, $campo] = previasTiposMidia()[$midia['tipo']];
    $resposta = requisicaoTelegram($token, $metodo, ['chat_id' => $id_chat, 'protect_content' => $proteger ? 'true' : 'false'], [$campo => $midia['caminho']]);
    return isset($resposta['result']['message_id']) ? (int) $resposta['result']['message_id'] : null;
}

function enviarPreviasBasico(PDO $pdo, string $token, array $bot, string $id_chat, array $dados_basico): void
{
    $previas = $dados_basico['previas'] ?? [];
    $midias = previasMidiasEnviaveis($previas);
    if (empty($previas['ativo']) || !$midias) {
        requisicaoTelegram($token, 'sendMessage', ['chat_id' => $id_chat, 'text' => 'As prévias não estão disponíveis no momento.']);
        return;
    }
    $bot_id = (int) $bot['id'];
    if (!previaLiberadaParaLead($pdo, $bot_id, $id_chat, (string) ($previas['rever'] ?? 'nunca'))) {
        $botoes = [[['text' => 'Ver Planos', 'callback_data' => 'basico::ver_planos']]];
        requisicaoTelegram($token, 'sendMessage', ['chat_id' => $id_chat, 'text' => 'Você já viu as prévias. O conteúdo completo está nos planos 👇', 'reply_markup' => json_encode(['inline_keyboard' => $botoes])]);
        return;
    }
    $segundos = max(5, min(3600, (int) ($previas['apagar_apos'] ?? 15)));
    $proteger = !empty($previas['proteger']);
    registrarPreviaVista($pdo, $bot_id, $id_chat);

    $ids = [];
    $texto_junto = trim((string) ($previas['mensagem_junto'] ?? ''));
    if ($texto_junto !== '') {
        $resposta = requisicaoTelegram($token, 'sendMessage', ['chat_id' => $id_chat, 'text' => $texto_junto, 'protect_content' => $proteger ? 'true' : 'false']);
        if (isset($resposta['result']['message_id'])) {
            $ids[] = (int) $resposta['result']['message_id'];
        }
    }
    foreach ($midias as $midia) {
        $id_mensagem = enviarMidiaPrevia($token, $id_chat, $midia, $proteger);
        if ($id_mensagem) {
            $ids[] = $id_mensagem;
        }
    }
    if (!$ids) {
        return;
    }

    $depois = null;
    $texto_depois = trim((string) ($previas['mensagem_depois'] ?? ''));
    if ($texto_depois !== '') {
        $depois = ['chat_id' => $id_chat, 'text' => $texto_depois];
        if (!empty($previas['botao_planos'])) {
            $depois['reply_markup'] = json_encode(['inline_keyboard' => [[['text' => '👑 Ver Planos', 'callback_data' => 'basico::ver_planos']]]]);
        }
    }
    $ultima = count($ids) - 1;
    $ids_fila = [];
    foreach ($ids as $i => $id_mensagem) {
        // A "mensagem de depois" vai presa só na última linha da fila, pra sair uma vez só.
        $ids_fila[] = agendarApagarMensagem($pdo, $bot_id, $id_chat, $id_mensagem, $segundos, $i === $ultima ? $depois : null);
    }

    if ($segundos > PREVIAS_ESPERA_MAXIMA_NO_WEBHOOK) {
        return; // o cron de 1 minuto apaga
    }
    // Libera o Telegram (200) e segura só este processo até a hora de apagar.
    ignore_user_abort(true);
    @set_time_limit($segundos + 30);
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } elseif (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
    } else {
        return; // sem como soltar a requisição: deixa pro cron
    }
    sleep($segundos);
    garantirConexaoBanco();
    $pdo = $GLOBALS['pdo'];
    $stmt = $pdo->prepare("SELECT * FROM mensagens_agendadas WHERE id = ?");
    foreach ($ids_fila as $id_fila) {
        $stmt->execute([$id_fila]);
        if ($linha = $stmt->fetch()) {
            processarMensagemAgendada($pdo, $linha, $token);
        }
    }
}
