<?php
// Simulador RÁPIDO de Telegram + OmegaPayments para o teste de carga na VPS (carregado por auto_prepend_file na cópia
// /var/www/painel-estresse). Diferente do simulador da bateria de homologação (sim.php): não valida nem registra em arquivo
// (isso distorceria a medida), mas ESPERA o tempo que a chamada real levaria — é a espera pelo Telegram e pela gateway que
// prende os processos do PHP-FPM e define quantos webhooks por segundo o servidor aguenta.
//
// Latências (ms) em /dev/shm/est/lat.json: {"tg":150,"gw":700,"gw_consulta":250}  (±30% de variação). Troca sem recarregar nada.
// Contadores: cada chamada acrescenta 1 byte em /dev/shm/est/n_<tipo> (o tamanho do arquivo é a contagem).
if (defined('SIMR_CARREGADO')) return;
define('SIMR_CARREGADO', 1);

function simr_cfg(): array
{
    static $c = null;
    if ($c === null) {
        $c = json_decode((string) @file_get_contents('/dev/shm/est/lat.json'), true) ?: [];
    }
    return $c;
}
function simr_dorme(string $chave, int $padrao): void
{
    $ms = (int) (simr_cfg()[$chave] ?? $padrao);
    if ($ms > 0) {
        $j = max(1, (int) ($ms * 0.3));
        usleep(max(0, $ms + random_int(-$j, $j)) * 1000);
    }
}
function simr_conta(string $tipo): void
{
    @file_put_contents('/dev/shm/est/n_' . $tipo, '.', FILE_APPEND);
}

function simTelegramRapido(string $token, string $metodo, array $p = [], array $arquivos = []): array
{
    simr_dorme('tg', 150);
    simr_conta('tg_' . $metodo);
    switch ($metodo) {
        case 'sendMessage':
        case 'sendPhoto':
        case 'sendVideo':
        case 'sendVoice':
        case 'sendAudio':
        case 'sendDocument':
            $res = ['message_id' => random_int(1000, 2000000000), 'chat' => ['id' => $p['chat_id'] ?? 0]];
            if ($metodo === 'sendPhoto') $res['photo'] = [['file_id' => 'SIMFIDs'], ['file_id' => 'SIMFIDg']];
            if ($metodo === 'sendVideo') $res['video'] = ['file_id' => 'SIMFIDv'];
            return ['ok' => true, 'result' => $res];
        case 'createChatInviteLink':
            return ['ok' => true, 'result' => ['invite_link' => 'https://t.me/+SIM' . bin2hex(random_bytes(6))]];
        case 'exportChatInviteLink':
            return ['ok' => true, 'result' => 'https://t.me/+SIMPERM' . bin2hex(random_bytes(6))];
        case 'getMe':
            return ['ok' => true, 'result' => ['id' => 9000000000 + abs(crc32($token)), 'username' => 'sim_' . substr(md5($token), 0, 6) . '_bot', 'first_name' => 'Bot Sim']];
        case 'getUserProfilePhotos':
            return ['ok' => true, 'result' => ['total_count' => 0, 'photos' => []]];
    }
    return ['ok' => true, 'result' => true];
}

function simOmegaRapido(string $metodo, string $uri, ?array $corpo = null): array
{
    if ($metodo === 'POST' && ($uri === '/gateway/pix/receive' || $uri === '/gateway/pix/subscription')) {
        simr_dorme('gw', 700);
        simr_conta('gw_criar');
        $tx = 'SIMTX' . bin2hex(random_bytes(7));
        $dados = ['transactionId' => $tx, 'pix' => ['code' => '00020126580014BR.GOV.BCB.PIX0136SIM-' . $tx . '-6304ABCD', 'expiresAt' => gmdate('c', time() + 900)]];
        if ($uri === '/gateway/pix/subscription') $dados['subscription'] = ['id' => 'SUBSIM' . $tx];
        return ['sucesso' => true, 'dados' => $dados, 'codigo_http' => 200];
    }
    if ($metodo === 'GET' && strpos($uri, '/gateway/transactions') === 0) {
        simr_dorme('gw_consulta', 250);
        simr_conta('gw_consulta');
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $q);
        $pago = is_file('/dev/shm/est/pagos/' . preg_replace('/\W/', '', (string) ($q['id'] ?? '')));
        return ['sucesso' => true, 'dados' => ['status' => $pago ? 'COMPLETED' : 'PENDING'], 'codigo_http' => 200];
    }
    return ['sucesso' => false, 'erro' => 'rota não simulada ' . $uri, 'codigo_http' => 404];
}
