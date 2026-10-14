<?php
// Simulador de Telegram + OmegaPayments para a rodada de testes de homologação.
// Carregado por auto_prepend_file. NUNCA fala com a internet: registra tudo em sim.log.
if (defined('SIM_CARREGADO')) return;
define('SIM_CARREGADO', 1);
define('SIM_DIR', getenv('SIM_DIR') ?: '/tmp/pt');
@date_default_timezone_set('America/Sao_Paulo');

function sim_log(array $e): void {
    $e = ['t' => date('H:i:s')] + $e;
    file_put_contents(SIM_DIR . '/sim.log', json_encode($e, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
}

function sim_contador(string $k): int {
    $f = SIM_DIR . '/contadores.json';
    $fp = fopen($f, 'c+');
    flock($fp, LOCK_EX);
    $d = json_decode((string) stream_get_contents($fp), true) ?: [];
    $d[$k] = ($d[$k] ?? 1000) + 1;
    ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($d));
    flock($fp, LOCK_UN); fclose($fp);
    return $d[$k];
}

/** Falhas injetadas pelo teste: falhas.json = {"sendVoice":"Bad Request: ...", "gw:criar":"erro", "*":"..."} */
function sim_falha(string $chave): ?string {
    $f = SIM_DIR . '/falhas.json';
    if (!is_file($f)) return null;
    $d = json_decode((string) file_get_contents($f), true) ?: [];
    return $d[$chave] ?? null;
}

// ---------- validações que o Telegram real faria ----------
function sim_valida_markdown(string $t): ?string {
    $limpo = preg_replace('/\\\\[_*`\[]/', '', $t);
    foreach (['*', '_', '`'] as $c) {
        if (substr_count($limpo, $c) % 2 !== 0) return "Bad Request: can't parse entities: Can't find end of the entity starting at byte offset " . (int) strpos($limpo, $c);
    }
    $abre = substr_count($limpo, '[');
    $fecha = preg_match_all('/\]\(/', $limpo);
    if ($abre !== (int) $fecha) return "Bad Request: can't parse entities: Can't find end of a url entity";
    return null;
}
function sim_valida_html(string $t): ?string {
    $sem_tags = preg_replace('#</?(b|strong|i|em|u|ins|s|strike|del|code|pre|tg-spoiler|a(\s+href="[^"]*")?)>#i', '', $t);
    if (preg_match('/<|>/', $sem_tags) || preg_match('/&(?!(lt|gt|amp|quot);)/', $sem_tags)) {
        return "Bad Request: can't parse entities: Unsupported start tag or unescaped character";
    }
    foreach (['b', 'i', 'u', 's', 'code', 'pre'] as $tag) {
        if (preg_match_all('#<' . $tag . '>#i', $t) !== preg_match_all('#</' . $tag . '>#i', $t)) return "Bad Request: can't parse entities: unclosed tag $tag";
    }
    return null;
}
function sim_valida_teclado($rm): ?string {
    if ($rm === null || $rm === '') return null;
    $d = is_string($rm) ? json_decode($rm, true) : $rm;
    if (!is_array($d)) return 'Bad Request: reply markup is not valid JSON';
    if (!isset($d['inline_keyboard']) || !is_array($d['inline_keyboard'])) return 'Bad Request: inline_keyboard missing';
    foreach ($d['inline_keyboard'] as $linha) {
        if (!is_array($linha) || !$linha) return 'Bad Request: empty keyboard row';
        if (count($linha) > 8) return 'Bad Request: too many buttons in row';
        foreach ($linha as $b) {
            if (trim((string) ($b['text'] ?? '')) === '') return 'Bad Request: text buttons are unallowed or button text is empty';
            $acoes = array_intersect(['url', 'callback_data', 'copy_text'], array_keys($b));
            if (count($acoes) !== 1) return 'Bad Request: BUTTON_TYPE_INVALID (precisa de exatamente uma ação)';
            if (isset($b['callback_data']) && (strlen((string) $b['callback_data']) < 1 || strlen((string) $b['callback_data']) > 64)) return 'Bad Request: BUTTON_DATA_INVALID (' . strlen((string) $b['callback_data']) . ' bytes)';
            if (isset($b['url']) && !preg_match('#^(https?|tg)://\S+$#i', (string) $b['url'])) return 'Bad Request: BUTTON_URL_INVALID';
            if (isset($b['copy_text']) && (strlen((string) ($b['copy_text']['text'] ?? '')) < 1 || strlen((string) $b['copy_text']['text']) > 256)) return 'Bad Request: copy_text too long';
            if (isset($b['style']) && !in_array($b['style'], ['primary', 'success', 'danger'], true)) return 'Bad Request: BUTTON_STYLE_INVALID';
        }
    }
    return null;
}
function sim_valida(string $metodo, array $p, array $arquivos): ?string {
    if (in_array($metodo, ['sendMessage', 'sendPhoto', 'sendVideo', 'sendVoice', 'sendAudio', 'sendDocument', 'sendChatAction', 'deleteMessage', 'createChatInviteLink', 'exportChatInviteLink', 'banChatMember', 'unbanChatMember', 'revokeChatInviteLink'], true)) {
        if (!isset($p['chat_id']) || (string) $p['chat_id'] === '' || $p['chat_id'] === 0 || $p['chat_id'] === '0') return 'Bad Request: chat_id is empty';
    }
    if ($metodo === 'sendMessage') {
        $texto = (string) ($p['text'] ?? '');
        if (trim($texto) === '') return 'Bad Request: message text is empty';
        if (mb_strlen($texto) > 4096) return 'Bad Request: message is too long';
        $pm = $p['parse_mode'] ?? '';
        if ($pm === 'Markdown' && ($e = sim_valida_markdown($texto))) return $e;
        if ($pm === 'HTML' && ($e = sim_valida_html($texto))) return $e;
        if ($e = sim_valida_teclado($p['reply_markup'] ?? null)) return $e;
    }
    if (in_array($metodo, ['sendPhoto', 'sendVideo', 'sendVoice', 'sendAudio', 'sendDocument'], true)) {
        $campo = ['sendPhoto' => 'photo', 'sendVideo' => 'video', 'sendVoice' => 'voice', 'sendAudio' => 'audio', 'sendDocument' => 'document'][$metodo];
        $tem_arquivo = isset($arquivos[$campo]) && is_file($arquivos[$campo]) && filesize($arquivos[$campo]) > 0;
        $tem_file_id = isset($p[$campo]) && is_string($p[$campo]) && strpos($p[$campo], 'SIMFID') === 0;
        if (!$tem_arquivo && !$tem_file_id) return 'Bad Request: wrong file identifier/HTTP URL specified';
        if (isset($p['caption']) && mb_strlen((string) $p['caption']) > 1024) return 'Bad Request: message caption is too long';
        if (isset($p['caption']) && ($p['parse_mode'] ?? '') === 'Markdown' && ($e = sim_valida_markdown((string) $p['caption']))) return $e;
        if ($e = sim_valida_teclado($p['reply_markup'] ?? null)) return $e;
    }
    if ($metodo === 'createChatInviteLink') {
        if (isset($p['member_limit']) && ((int) $p['member_limit'] < 1 || (int) $p['member_limit'] > 99999)) return 'Bad Request: member_limit invalid';
        if (isset($p['expire_date']) && (int) $p['expire_date'] <= time()) return 'Bad Request: expire_date in the past';
    }
    return null;
}

function simTelegram(string $token, string $metodo, array $p = [], array $arquivos = []): array {
    $arq = [];
    foreach ($arquivos as $campo => $caminho) $arq[$campo] = basename((string) $caminho);
    $resumo = ['tg' => $metodo, 'token' => substr($token, 0, 10), 'p' => $p];
    if ($arq) $resumo['arquivos'] = $arq;
    if (isset($p['reply_markup']) && is_string($p['reply_markup'])) $resumo['p']['reply_markup'] = json_decode($p['reply_markup'], true);

    if ($f = sim_falha($metodo) ?? sim_falha('*')) {
        // "401:Unauthorized" simula o Telegram recusando o token (revogado); sem prefixo é erro 400 como sempre.
        $codigo_falha = 400;
        if (preg_match('/^(\d{3}):(.*)$/s', $f, $mf)) { $codigo_falha = (int) $mf[1]; $f = $mf[2]; }
        sim_log($resumo + ['ok' => false, 'erro' => $f]);
        $resp_falha = ['ok' => false, 'error_code' => $codigo_falha, 'description' => $f];
        // O código real chama isto depois de cada resposta do Telegram (token revogado vira aviso ao dono).
        if (function_exists('registrarRespostaTelegram')) { registrarRespostaTelegram($token, $resp_falha); }
        return $resp_falha;
    }
    if ($metodo === 'sendMessage' && sim_falha('copy_text') && strpos((string) ($p['reply_markup'] ?? ''), 'copy_text') !== false) {
        $f = sim_falha('copy_text');
        sim_log($resumo + ['ok' => false, 'erro' => $f]);
        return ['ok' => false, 'error_code' => 400, 'description' => $f];
    }
    if ($e = sim_valida($metodo, $p, $arquivos)) {
        sim_log($resumo + ['ok' => false, 'erro' => $e]);
        return ['ok' => false, 'error_code' => 400, 'description' => $e];
    }
    $r = ['ok' => true, 'result' => true];
    switch ($metodo) {
        case 'sendMessage':
        case 'sendPhoto':
        case 'sendVideo':
        case 'sendVoice':
        case 'sendAudio':
        case 'sendDocument':
            $id = sim_contador('msg');
            $res = ['message_id' => $id, 'chat' => ['id' => $p['chat_id'] ?? 0]];
            if ($metodo === 'sendPhoto') $res['photo'] = [['file_id' => 'SIMFIDs' . $id], ['file_id' => 'SIMFIDg' . $id]];
            if ($metodo === 'sendVideo') $res['video'] = ['file_id' => 'SIMFIDv' . $id];
            $r = ['ok' => true, 'result' => $res];
            break;
        case 'createChatInviteLink':
            $r = ['ok' => true, 'result' => ['invite_link' => 'https://t.me/+SIM' . sim_contador('link')]];
            break;
        case 'exportChatInviteLink':
            $r = ['ok' => true, 'result' => 'https://t.me/+SIMPERM' . sim_contador('link')];
            break;
        case 'getMe':
            $r = ['ok' => true, 'result' => ['id' => 9000000000 + (int) abs(crc32($token)), 'username' => 'sim_' . substr(md5($token), 0, 6) . '_bot', 'first_name' => 'Bot Sim ' . substr(md5($token), 0, 4), 'is_bot' => true]];
            break;
        case 'getUserProfilePhotos':
            $r = ['ok' => true, 'result' => ['total_count' => 0, 'photos' => []]];
            break;
    }
    sim_log($resumo + ['ok' => true] + (isset($r['result']['message_id']) ? ['mid' => $r['result']['message_id']] : []));
    return $r;
}

// ---------- OmegaPayments simulada (substitui só o HTTP: criar/consultar/normalizar rodam de verdade) ----------
function simOmegaApi(string $metodo, string $uri, ?array $corpo = null): array {
    if ($f = sim_falha('gw:' . $uri) ?? sim_falha('gw:*')) {
        sim_log(['gw' => $metodo . ' ' . $uri, 'ok' => false, 'erro' => $f]);
        return ['sucesso' => false, 'erro' => $f, 'codigo_http' => 400];
    }
    if ($metodo === 'POST' && ($uri === '/gateway/pix/receive' || $uri === '/gateway/pix/subscription')) {
        $valor = (float) ($corpo['amount'] ?? 0);
        $minimo = defined('OMEGAPAYMENTS_PRECO_MINIMO_API') ? OMEGAPAYMENTS_PRECO_MINIMO_API : 1.5;
        $erro = null;
        if ($valor < $minimo) $erro = 'O valor mínimo é R$ ' . number_format($minimo, 2);
        $doc = (string) ($corpo['client']['document'] ?? '');
        if (!$erro && !in_array(strlen($doc), [11, 14], true)) $erro = 'Documento inválido.';
        $soma = 0.0;
        foreach (($corpo['splits'] ?? []) as $s) {
            if (empty($s['producerId'])) $erro = $erro ?: 'split sem producerId';
            $soma += (float) ($s['amount'] ?? 0);
        }
        if (!$erro && $soma > $valor + 0.001) $erro = 'A soma dos splits excede o valor da cobrança.';
        if (!$erro && $uri === '/gateway/pix/receive' && empty($corpo['products'][0]['id'])) $erro = 'products.0.id obrigatório';
        if (!$erro && $uri === '/gateway/pix/subscription' && empty($corpo['subscription']['periodicityType'])) $erro = 'subscription.periodicityType obrigatório';
        $log = ['gw' => $metodo . ' ' . $uri, 'valor' => $valor, 'splits' => $corpo['splits'] ?? null, 'dueDate' => $corpo['dueDate'] ?? null, 'periodicidade' => $corpo['subscription'] ?? null, 'doc' => $doc, 'nome' => $corpo['client']['name'] ?? null, 'produto' => $corpo['product']['name'] ?? null];
        if ($erro) {
            sim_log($log + ['ok' => false, 'erro' => $erro]);
            return ['sucesso' => false, 'erro' => $erro, 'codigo_http' => 400];
        }
        // Gateway lenta (arquivo 'lento' na pasta do simulador): passa do wait_timeout de 20 s do MySQL antes de responder.
        if (is_file(SIM_DIR . '/lento')) sleep(23);
        $n = sim_contador('tx');
        $tx = 'SIMTX' . $n;
        $dados = ['transactionId' => $tx, 'pix' => ['code' => '00020126580014BR.GOV.BCB.PIX0136SIM-' . $tx . '-6304ABCD', 'expiresAt' => gmdate('c', time() + 900)]];
        if ($uri === '/gateway/pix/subscription') $dados['subscription'] = ['id' => 'SUBSIM' . $n];
        sim_log($log + ['ok' => true, 'tx' => $tx]);
        return ['sucesso' => true, 'dados' => $dados, 'codigo_http' => 200];
    }
    if ($metodo === 'GET' && strpos($uri, '/gateway/transactions') === 0) {
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $q);
        $tx = (string) ($q['id'] ?? '');
        $pagos = is_file(SIM_DIR . '/pagos.txt') ? file(SIM_DIR . '/pagos.txt', FILE_IGNORE_NEW_LINES) : [];
        $status = in_array($tx, $pagos, true) ? 'COMPLETED' : 'PENDING';
        sim_log(['gw' => 'consulta ' . $tx, 'status' => $status]);
        return ['sucesso' => true, 'dados' => ['status' => $status], 'codigo_http' => 200];
    }
    return ['sucesso' => false, 'erro' => 'rota não simulada ' . $uri, 'codigo_http' => 404];
}

// Em CLI não existe fastcgi_finish_request; o teste pode ligar SIM_FASTCGI=1 pra exercitar o caminho "solta o Telegram e dorme".
if (getenv('SIM_FASTCGI') === '1' && !function_exists('fastcgi_finish_request')) {
    function fastcgi_finish_request(): bool { sim_log(['evento' => 'fastcgi_finish_request']); return true; }
}

require_once __DIR__ . '/sim_eventos.php';
