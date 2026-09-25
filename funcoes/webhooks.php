<?php
declare(strict_types=1);

/**
 * Webhooks de saída do painel (lead, PIX gerado, pagamento aprovado).
 *
 * O payload segue o schema do plano, mas só com colunas que existem de verdade:
 * leads tem um nome só (vira first_name), telefone e origem_rastreio. Não há
 * e-mail, sobrenome, username do Telegram nem IP — esses campos saem null.
 * O código Pix só existe na memória na hora de criar a cobrança, então só vai
 * em payment_created. plan_name e contact_capture_status também não são salvos.
 *
 * dispararWebhooks() nunca deixa exceção escapar: um endpoint do cliente fora
 * do ar não pode derrubar o webhook do Telegram nem o cron de PIX.
 */

function eventosWebhook(): array
{
    return [
        'user_joined' => 'Novo lead',
        'payment_created' => 'Pagamento criado',
        'payment_approved' => 'Pagamento aprovado',
    ];
}

function webhooksDisponivel(): bool
{
    global $pdo;
    try {
        $pdo->query('SELECT 1 FROM webhooks LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function webhookEscutaEvento(string $eventos_csv, string $evento): bool
{
    $lista = array_filter(array_map('trim', explode(',', $eventos_csv)));
    return in_array($evento, $lista, true);
}

function urlWebhookValida(string $url): bool
{
    if ($url === '' || strlen($url) > 500) {
        return false;
    }
    if (!preg_match('#^https://#i', $url)) {
        return false;
    }
    if (filter_var($url, FILTER_VALIDATE_URL) === false) {
        return false;
    }
    $partes = parse_url($url);
    if (!$partes || !empty($partes['user']) || !empty($partes['pass'])) {
        return false;
    }
    require_once __DIR__ . '/utmfy.php';
    return urlPostbackEhSegura($url);
}

function listarWebhooksUsuario(int $id_usuario): array
{
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT w.*, b.primeiro_nome AS bot_nome, b.nome_usuario AS bot_usuario
        FROM webhooks w
        LEFT JOIN bots b ON b.id = w.bot_id AND b.id_usuario = w.id_usuario
        WHERE w.id_usuario = ?
        ORDER BY w.id DESC
    ");
    $stmt->execute([$id_usuario]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function buscarWebhookDoUsuario(int $id, int $id_usuario): ?array
{
    global $pdo;
    $stmt = $pdo->prepare('SELECT * FROM webhooks WHERE id = ? AND id_usuario = ? LIMIT 1');
    $stmt->execute([$id, $id_usuario]);
    $linha = $stmt->fetch(PDO::FETCH_ASSOC);
    return $linha ?: null;
}

function estatisticasWebhooks(int $id_usuario): array
{
    global $pdo;
    $stmt = $pdo->prepare('SELECT bot_id, ativo FROM webhooks WHERE id_usuario = ?');
    $stmt->execute([$id_usuario]);
    $ativos = 0;
    $especificos = [];
    $cobre_todos = false;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linha) {
        if (!(int) $linha['ativo']) {
            continue;
        }
        $ativos++;
        if ($linha['bot_id'] === null) {
            $cobre_todos = true;
        } else {
            $especificos[(int) $linha['bot_id']] = true;
        }
    }
    if ($cobre_todos) {
        $stmt_bots = $pdo->prepare('SELECT COUNT(*) FROM bots WHERE id_usuario = ?');
        $stmt_bots->execute([$id_usuario]);
        $monitorados = (int) $stmt_bots->fetchColumn();
    } else {
        $monitorados = count($especificos);
    }
    $stmt_envios = $pdo->prepare("
        SELECT COUNT(*)
        FROM webhooks_envios e
        INNER JOIN webhooks w ON w.id = e.webhook_id
        WHERE w.id_usuario = ? AND e.sucesso = 1
    ");
    $stmt_envios->execute([$id_usuario]);
    return [
        'ativos' => $ativos,
        'bots_monitorados' => $monitorados,
        'enviados' => (int) $stmt_envios->fetchColumn(),
    ];
}

function salvarWebhook(int $id_usuario, ?int $id, array $dados): void
{
    global $pdo;
    $nome = trim((string) ($dados['nome'] ?? ''));
    $url = trim((string) ($dados['url'] ?? ''));
    $secret_informado = trim((string) ($dados['secret'] ?? ''));
    $eventos_post = $dados['eventos'] ?? [];
    $bot_id = isset($dados['bot_id']) && $dados['bot_id'] !== '' ? (int) $dados['bot_id'] : null;
    $ativo = !empty($dados['ativo']) ? 1 : 0;

    if ($nome === '' || strlen($nome) > 80) {
        throw new RuntimeException('Informe um nome de até 80 caracteres.');
    }
    if (!urlWebhookValida($url)) {
        throw new RuntimeException('Use um endereço https:// público. Endereço interno, sem HTTPS ou que não resolve não é aceito.');
    }
    if (!is_array($eventos_post)) {
        $eventos_post = [];
    }
    $permitidos = array_keys(eventosWebhook());
    $eventos = [];
    foreach ($eventos_post as $evento) {
        $evento = (string) $evento;
        if (in_array($evento, $permitidos, true) && !in_array($evento, $eventos, true)) {
            $eventos[] = $evento;
        }
    }
    if (!$eventos) {
        throw new RuntimeException('Marque pelo menos um evento.');
    }
    if ($bot_id !== null) {
        $stmt_bot = $pdo->prepare('SELECT id FROM bots WHERE id = ? AND id_usuario = ? LIMIT 1');
        $stmt_bot->execute([$bot_id, $id_usuario]);
        if (!$stmt_bot->fetchColumn()) {
            throw new RuntimeException('Escolha um bot da sua conta.');
        }
    }
    if ($secret_informado !== '' && strlen($secret_informado) > 128) {
        throw new RuntimeException('O secret pode ter no máximo 128 caracteres.');
    }

    $eventos_csv = implode(',', $eventos);
    if ($id) {
        $atual = buscarWebhookDoUsuario($id, $id_usuario);
        if (!$atual) {
            throw new RuntimeException('Webhook não encontrado.');
        }
        $secret = $secret_informado !== '' ? $secret_informado : ($atual['secret'] ?? null);
        $stmt = $pdo->prepare('
            UPDATE webhooks
            SET nome = ?, url = ?, secret = ?, eventos = ?, bot_id = ?, ativo = ?, falhas_consecutivas = IF(? = 1 AND ativo = 0, 0, falhas_consecutivas)
            WHERE id = ? AND id_usuario = ?
        ');
        $stmt->execute([$nome, $url, $secret !== '' ? $secret : null, $eventos_csv, $bot_id, $ativo, $ativo, $id, $id_usuario]);
        return;
    }

    $stmt = $pdo->prepare('
        INSERT INTO webhooks (id_usuario, nome, url, secret, eventos, bot_id, ativo)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $id_usuario,
        $nome,
        $url,
        $secret_informado !== '' ? $secret_informado : null,
        $eventos_csv,
        $bot_id,
        $ativo,
    ]);
}

function excluirWebhook(int $id, int $id_usuario): void
{
    global $pdo;
    $stmt = $pdo->prepare('DELETE FROM webhooks WHERE id = ? AND id_usuario = ?');
    $stmt->execute([$id, $id_usuario]);
}

function alternarWebhook(int $id, int $id_usuario): void
{
    global $pdo;
    $atual = buscarWebhookDoUsuario($id, $id_usuario);
    if (!$atual) {
        throw new RuntimeException('Webhook não encontrado.');
    }
    $ligar = (int) $atual['ativo'] ? 0 : 1;
    if ($ligar && !urlWebhookValida((string) $atual['url'])) {
        throw new RuntimeException('Esse endereço não passa mais na checagem de segurança. Edite a URL antes de ligar.');
    }
    $stmt = $pdo->prepare('
        UPDATE webhooks
        SET ativo = ?, falhas_consecutivas = IF(? = 1, 0, falhas_consecutivas)
        WHERE id = ? AND id_usuario = ?
    ');
    $stmt->execute([$ligar, $ligar, $id, $id_usuario]);
}

function dispararWebhooks(int $id_usuario, string $evento, array $contexto): void
{
    try {
        if (!isset(eventosWebhook()[$evento]) || $id_usuario < 1) {
            return;
        }
        $bot_id = (int) ($contexto['bot_id'] ?? 0);
        if ($bot_id < 1) {
            return;
        }
        global $pdo;
        if (!isset($pdo)) {
            return;
        }

        $stmt = $pdo->prepare('
            SELECT id, url, secret, eventos, falhas_consecutivas
            FROM webhooks
            WHERE id_usuario = ? AND ativo = 1 AND (bot_id IS NULL OR bot_id = ?)
        ');
        $stmt->execute([$id_usuario, $bot_id]);
        $webhooks = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linha) {
            if (webhookEscutaEvento((string) $linha['eventos'], $evento)) {
                $webhooks[] = $linha;
            }
        }
        if (!$webhooks) {
            return;
        }

        $payload = montarPayloadWebhook($pdo, $evento, $contexto);
        if ($payload === null) {
            return;
        }
        $corpo = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($corpo === false) {
            return;
        }

        foreach (enviarLoteWebhooks($webhooks, $corpo) as $resultado) {
            registrarResultadoWebhook($pdo, $resultado, $evento);
        }
    } catch (Throwable $e) {
        error_log('[webhooks] ' . $e->getMessage());
    }
}

function montarPayloadWebhook(PDO $pdo, string $evento, array $contexto): ?array
{
    $bot_id = (int) $contexto['bot_id'];
    $id_telegram = (string) ($contexto['id_telegram'] ?? '');

    $stmt = $pdo->prepare('
        SELECT b.id, b.primeiro_nome, b.nome_usuario, f.id AS fluxo_id, f.nome AS fluxo_nome
        FROM bots b
        LEFT JOIN fluxos f ON f.id = b.id_fluxo_conectado
        WHERE b.id = ?
        LIMIT 1
    ');
    $stmt->execute([$bot_id]);
    $bot = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$bot) {
        return null;
    }

    $lead = null;
    if (!empty($contexto['lead_id'])) {
        $stmt = $pdo->prepare('SELECT id, id_telegram, nome, telefone, origem_rastreio, criado_em FROM leads WHERE id = ? AND bot_id = ? LIMIT 1');
        $stmt->execute([(int) $contexto['lead_id'], $bot_id]);
        $lead = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$lead && $id_telegram !== '') {
        $stmt = $pdo->prepare('SELECT id, id_telegram, nome, telefone, origem_rastreio, criado_em FROM leads WHERE id_telegram = ? AND bot_id = ? LIMIT 1');
        $stmt->execute([$id_telegram, $bot_id]);
        $lead = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    $telegram = (string) ($lead['id_telegram'] ?? $id_telegram);
    $is_vip = false;
    if ($telegram !== '') {
        $stmt = $pdo->prepare("SELECT 1 FROM vendas WHERE id_telegram = ? AND bot_id = ? AND status = 'pago' LIMIT 1");
        $stmt->execute([$telegram, $bot_id]);
        $is_vip = (bool) $stmt->fetchColumn();
    }

    $origem = trim((string) ($lead['origem_rastreio'] ?? ''));
    $origem = $origem !== '' ? $origem : null;
    $nome_lead = trim((string) ($lead['nome'] ?? ''));
    $telefone = trim((string) ($lead['telefone'] ?? ''));
    $nome_bot = trim((string) ($bot['primeiro_nome'] ?? ''));
    if ($nome_bot === '') {
        $nome_bot = trim((string) ($bot['nome_usuario'] ?? ''));
    }
    $usuario_bot = ltrim(trim((string) ($bot['nome_usuario'] ?? '')), '@');

    $payload = [
        'event' => $evento,
        'customer' => [
            'id' => $lead ? (int) $lead['id'] : null,
            'telegram_id' => $telegram !== '' ? $telegram : null,
            'first_name' => $nome_lead !== '' ? $nome_lead : null,
            'last_name' => null,
            'username' => null,
            'phone' => $telefone !== '' ? $telefone : null,
            'email' => null,
            'is_vip' => $is_vip,
        ],
        'bot' => [
            'id' => (int) $bot['id'],
            'name' => $nome_bot !== '' ? $nome_bot : null,
            'username' => $usuario_bot !== '' ? $usuario_bot : null,
        ],
        'flow' => [
            'id' => !empty($bot['fluxo_id']) ? (int) $bot['fluxo_id'] : null,
            'name' => trim((string) ($bot['fluxo_nome'] ?? '')) !== '' ? $bot['fluxo_nome'] : null,
        ],
        'tracking' => [
            'utm_source' => $origem ? 'telegram' : null,
            'utm_campaign' => $origem,
            'ip' => null,
        ],
        'joined_at' => formatarDataWebhook($lead['criado_em'] ?? null),
    ];

    if ($evento === 'user_joined') {
        return $payload;
    }

    $venda_id = (int) ($contexto['venda_id'] ?? 0);
    if ($venda_id < 1) {
        return null;
    }
    $stmt = $pdo->prepare('
        SELECT v.id, v.transacao_id, v.valor, v.tipo_cobranca, v.criado_em, v.pago_em, g.nome AS gateway_nome
        FROM vendas v
        LEFT JOIN gateways g ON g.id = v.id_gateway
        WHERE v.id = ? AND v.bot_id = ?
        LIMIT 1
    ');
    $stmt->execute([$venda_id, $bot_id]);
    $venda = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$venda) {
        return null;
    }

    $transacao = trim((string) ($contexto['transacao_id'] ?? $venda['transacao_id'] ?? ''));
    $gateway = trim((string) ($contexto['gateway'] ?? $venda['gateway_nome'] ?? ''));
    $plano = trim((string) ($contexto['plan_name'] ?? ''));
    $pago = $evento === 'payment_approved';
    $pago_em = $pago ? ($contexto['pago_em'] ?? $venda['pago_em'] ?? null) : null;

    $transaction = [
        'id' => (int) $venda['id'],
        'external_id' => $transacao !== '' ? $transacao : null,
        'status' => $pago ? 'paid' : 'pending',
        'amount' => round((float) $venda['valor'], 2),
        'currency' => 'BRL',
        'gateway' => $gateway !== '' ? $gateway : null,
        'plan_name' => $plano !== '' ? $plano : null,
        'type' => trim((string) ($venda['tipo_cobranca'] ?? '')) !== '' ? $venda['tipo_cobranca'] : 'unica',
        'payment_method' => 'pix',
        'sales_code' => $origem,
        'created_at' => formatarDataWebhook($venda['criado_em'] ?? null),
    ];
    if ($evento === 'payment_created') {
        $pix = trim((string) ($contexto['pix_code'] ?? ''));
        $transaction['pix_code'] = $pix !== '' ? $pix : null;
    }
    if ($pago) {
        $transaction['paid_at'] = formatarDataWebhook(is_string($pago_em) ? $pago_em : null);
        $payload['contact_capture_status'] = null;
    }
    $payload['transaction'] = $transaction;
    return $payload;
}

function formatarDataWebhook(?string $data): ?string
{
    $data = trim((string) $data);
    if ($data === '') {
        return null;
    }
    try {
        $dt = new DateTime($data, new DateTimeZone('America/Sao_Paulo'));
        return $dt->format('c');
    } catch (Throwable $e) {
        return null;
    }
}

function enviarLoteWebhooks(array $webhooks, string $corpo): array
{
    $multi = curl_multi_init();
    $handles = [];
    $resultados = [];

    foreach ($webhooks as $indice => $webhook) {
        $url = (string) $webhook['url'];
        if (!urlWebhookValida($url)) {
            $resultados[$indice] = resultadoEnvioWebhook($webhook, null, false, 'URL recusada na hora do envio');
            continue;
        }
        $headers = ['Content-Type: application/json', 'User-Agent: PainelBot-Webhook/1'];
        $secret = (string) ($webhook['secret'] ?? '');
        if ($secret !== '') {
            $headers[] = 'X-Webhook-Signature: ' . hash_hmac('sha256', $corpo, $secret);
        }
        $ch = curl_init($url);
        $opcoes = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $corpo,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 3,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_FOLLOWLOCATION => false,
        ];
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $opcoes[CURLOPT_PROTOCOLS_STR] = 'https';
        } elseif (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            $opcoes[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        }
        curl_setopt_array($ch, $opcoes);
        curl_multi_add_handle($multi, $ch);
        $handles[$indice] = $ch;
    }

    if ($handles) {
        $rodando = null;
        do {
            $status = curl_multi_exec($multi, $rodando);
            if ($rodando) {
                curl_multi_select($multi, 1.0);
            }
        } while ($rodando && $status === CURLM_OK);

        foreach ($handles as $indice => $ch) {
            $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $erro_curl = curl_error($ch);
            $ok = $erro_curl === '' && $http >= 200 && $http < 300;
            $erro = null;
            if (!$ok) {
                $erro = $erro_curl !== '' ? $erro_curl : ('HTTP ' . $http);
            }
            $resultados[$indice] = resultadoEnvioWebhook($webhooks[$indice], $http ?: null, $ok, $erro);
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
        }
    }
    curl_multi_close($multi);
    return $resultados;
}

function resultadoEnvioWebhook(array $webhook, ?int $http, bool $ok, ?string $erro): array
{
    return [
        'webhook' => $webhook,
        'http' => $http,
        'ok' => $ok,
        'erro' => $erro !== null ? substr($erro, 0, 180) : null,
    ];
}

function registrarResultadoWebhook(PDO $pdo, array $resultado, string $evento): void
{
    $webhook = $resultado['webhook'];
    $id = (int) $webhook['id'];
    try {
        $stmt = $pdo->prepare('INSERT INTO webhooks_envios (webhook_id, evento, http_status, sucesso, erro) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$id, $evento, $resultado['http'], $resultado['ok'] ? 1 : 0, $resultado['erro']]);
    } catch (Throwable $e) {
        error_log('[webhooks] log de envio: ' . $e->getMessage());
    }

    if ($resultado['ok']) {
        $pdo->prepare('UPDATE webhooks SET falhas_consecutivas = 0 WHERE id = ?')->execute([$id]);
        return;
    }
    $falhas = (int) $webhook['falhas_consecutivas'] + 1;
    $ativo = $falhas >= 5 ? 0 : 1;
    $pdo->prepare('UPDATE webhooks SET falhas_consecutivas = ?, ativo = ? WHERE id = ?')->execute([$falhas, $ativo, $id]);
}

function exemplosPayloadWebhook(): array
{
    $base = [
        'event' => 'user_joined',
        'customer' => [
            'id' => 1204,
            'telegram_id' => '123456789',
            'first_name' => 'Maria',
            'last_name' => null,
            'username' => null,
            'phone' => null,
            'email' => null,
            'is_vip' => false,
        ],
        'bot' => ['id' => 8, 'name' => 'Bot de Vendas', 'username' => 'vendas_bot'],
        'flow' => ['id' => 3, 'name' => 'Fluxo principal'],
        'tracking' => ['utm_source' => 'telegram', 'utm_campaign' => 'campanha_maio', 'ip' => null],
        'joined_at' => '2026-09-24T10:15:00-03:00',
    ];
    $transacao = [
        'id' => 551,
        'external_id' => 'txid-exemplo',
        'status' => 'pending',
        'amount' => 37.9,
        'currency' => 'BRL',
        'gateway' => 'infopago',
        'plan_name' => 'Acesso 30 dias',
        'type' => 'unica',
        'payment_method' => 'pix',
        'sales_code' => 'campanha_maio',
        'created_at' => '2026-09-24T10:16:00-03:00',
        'pix_code' => '00020126...',
    ];
    $criado = $base;
    $criado['event'] = 'payment_created';
    $criado['transaction'] = $transacao;
    $aprovado = $base;
    $aprovado['event'] = 'payment_approved';
    $aprovado['customer']['is_vip'] = true;
    $aprovado['transaction'] = $transacao;
    $aprovado['transaction']['status'] = 'paid';
    $aprovado['transaction']['paid_at'] = '2026-09-24T10:18:00-03:00';
    unset($aprovado['transaction']['pix_code']);
    $aprovado['contact_capture_status'] = null;
    return [
        'user_joined' => $base,
        'payment_created' => $criado,
        'payment_approved' => $aprovado,
    ];
}
