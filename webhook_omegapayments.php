<?php
declare(strict_types=1);

require_once __DIR__ . '/funcoes/omegapayments_banco.php';
require_once __DIR__ . '/funcoes/pagamento_confirmado.php';

iniciarWebhookGateway('omegapayments');

/**
 * Extrai o id da transação do payload do webhook. Formato real confirmado em 28/09:
 * {"event":"TRANSACTION_CREATED","transaction":{"id":...,"identifier":...,"status":...},
 * "subscription":{"id":...,"cycle":...}|null}. A venda é gravada com transaction.id
 * (o "transactionId" devolvido na criação); transaction.identifier é outro valor e
 * nunca casa, por isso fica por último.
 */
function extrairIdentificadorOmegapayments(array $notificacao): string {
    $candidatos = [
        $notificacao['transaction']['id'] ?? null,
        $notificacao['transactionId'] ?? null,
        $notificacao['data']['transactionId'] ?? null,
        $notificacao['transaction']['transactionId'] ?? null,
        $notificacao['identifier'] ?? null,
        $notificacao['data']['identifier'] ?? null,
        $notificacao['transaction']['identifier'] ?? null,
    ];
    foreach ($candidatos as $candidato) {
        if (!empty($candidato)) {
            return (string)$candidato;
        }
    }
    return '';
}

/**
 * Fim do período de um ciclo de assinatura pelo calendário: startAt + cycle * intervalo.
 * Devolve null se o payload não trouxer o suficiente (nunca chuta).
 */
function fimCicloAssinaturaOmegapayments(array $assinatura): ?int {
    $inicio = strtotime((string)($assinatura['startAt'] ?? ''));
    $ciclo = (int)($assinatura['cycle'] ?? 0);
    $contagem = (int)($assinatura['intervalCount'] ?? 0);
    $unidades = ['DAYS' => 'days', 'WEEKS' => 'weeks', 'MONTHS' => 'months', 'YEARS' => 'years'];
    $unidade = $unidades[strtoupper((string)($assinatura['intervalType'] ?? ''))] ?? null;
    if (!$inicio || $ciclo < 1 || $contagem < 1 || !$unidade) {
        return null;
    }
    $passos = $ciclo * $contagem;
    if ($unidade === 'months' || $unidade === 'years') {
        // strtotime('+1 month') em 31/01 vira 03/03; o calendário do gateway cai em 28/02.
        $meses = $unidade === 'years' ? $passos * 12 : $passos;
        $data = (new DateTimeImmutable('@' . $inicio))->setTimezone(new DateTimeZone('UTC'));
        $primeiro_do_mes = $data->modify('first day of this month')->modify("+$meses months");
        $dia = min((int)$data->format('j'), (int)$primeiro_do_mes->format('t'));
        return $primeiro_do_mes->setDate((int)$primeiro_do_mes->format('Y'), (int)$primeiro_do_mes->format('n'), $dia)->getTimestamp();
    }
    $fim = strtotime('+' . $passos . ' ' . $unidade, $inicio);
    return $fim ?: null;
}

/**
 * Ciclo 2+ de uma assinatura: a OmegaPayments cria uma transação nova a cada ciclo (id
 * que a gente nunca viu). Casa pela assinatura (subscription.id == vendas.id_assinatura)
 * e cria a venda filha, pra o fluxo normal (confirmar -> estender acesso) funcionar.
 * Retorna a venda filha recém-criada, ou null se não há assinatura conhecida/já existia.
 * Não copia id_operador_fluxo de propósito: o funil de vendas não deve rodar de novo a
 * cada renovação.
 */
function criarVendaCicloOmegapayments(array $notificacao, string $txid): ?array {
    global $pdo;

    $id_assinatura = (string)($notificacao['subscription']['id'] ?? '');
    if ($id_assinatura === '') {
        return null;
    }
    $stmt = $pdo->prepare("SELECT * FROM vendas WHERE id_assinatura = ? ORDER BY id ASC LIMIT 1");
    $stmt->execute([$id_assinatura]);
    $pai = $stmt->fetch();
    if (!$pai) {
        return null;
    }

    $expira_ts = strtotime((string)($notificacao['transaction']['pixInformation']['expiresAt'] ?? ''));
    $minutos_expiracao = $expira_ts ? max(1, (int)ceil(($expira_ts - time()) / 60)) : (int)($pai['tempo_expiracao_minutos'] ?? 15);
    $valor = (float)($notificacao['transaction']['amount'] ?? $pai['valor']);

    try {
        $pdo->prepare("
            INSERT INTO vendas (id_telegram, bot_id, valor, status, transacao_id, id_grupo_telegram, dias_acesso,
                tempo_acesso_minutos, id_gateway, tempo_expiracao_minutos, criado_em, tipo_cobranca, id_assinatura,
                venda_pai_id, id_plano, id_fluxo, comissao_admin)
            VALUES (?, ?, ?, 'gerado', ?, ?, ?, ?, ?, ?, ?, 'assinatura', ?, ?, ?, ?, ?)
        ")->execute([
            $pai['id_telegram'], $pai['bot_id'], $valor, $txid, $pai['id_grupo_telegram'], $pai['dias_acesso'],
            $pai['tempo_acesso_minutos'], $pai['id_gateway'], $minutos_expiracao, date('Y-m-d H:i:s'), $id_assinatura,
            $pai['venda_pai_id'] ?: $pai['id'], $pai['id_plano'], $pai['id_fluxo'] ?? null,
            // A Omega repete a cobrança com os mesmos termos da assinatura (inclusive o split): a comissão do ciclo é a da primeira venda.
            (float) ($pai['comissao_admin'] ?? 0),
        ]);
        require_once __DIR__ . '/funcoes/traqueamento.php';
        herdarNomeProdutoVenda($pdo, (string) $txid, (int) $pai['id']);
    } catch (\PDOException $e) {
        // Entrega duplicada/simultânea do mesmo evento: o índice único em transacao_id barra.
        logWebhookGateway("Venda de ciclo para $txid já criada por outra entrega (" . $e->getMessage() . ").");
        return null;
    }

    $stmt = $pdo->prepare("SELECT * FROM vendas WHERE transacao_id = ?");
    $stmt->execute([$txid]);
    return $stmt->fetch() ?: null;
}

/**
 * O e-mail do cliente é sintético (a plataforma não coleta), então a OmegaPayments nunca
 * consegue avisar o assinante da cobrança do próximo ciclo. Manda o PIX pelo Telegram.
 */
function avisarCobrancaCicloOmegapayments(array $venda, array $notificacao): void {
    global $pdo;

    $stmt = $pdo->prepare("SELECT token FROM bots WHERE id = ?");
    $stmt->execute([$venda['bot_id']]);
    $token_bot = $stmt->fetchColumn();
    $codigo = (string)($notificacao['transaction']['pixInformation']['qrCode'] ?? '');
    if (!$token_bot || $codigo === '') {
        logWebhookGateway("Cobrança de ciclo da venda #{$venda['id']}: sem token do bot ou sem código PIX no payload, não avisei o assinante.");
        return;
    }

    $ate = strtotime((string)($notificacao['transaction']['pixInformation']['expiresAt'] ?? ''));
    $valor_fmt = number_format((float)$venda['valor'], 2, ',', '.');
    $msg = "🔄 <b>Renovação da sua assinatura</b>\n\nValor: <b>R$ {$valor_fmt}</b>";
    if ($ate) {
        $msg .= "\nPague até: <b>" . date('d/m/Y H:i', $ate) . "</b>";
    }
    $msg .= "\n\nPIX copia e cola (toque para copiar):\n<code>" . htmlspecialchars($codigo) . "</code>\n\nAssim que o pagamento for confirmado, seu acesso é renovado automaticamente.";

    $resp = requisicaoTelegramGateway($token_bot, 'sendMessage', [
        'chat_id' => $venda['id_telegram'],
        'text' => $msg,
        'parse_mode' => 'HTML',
    ]);
    logWebhookGateway("Cobrança de ciclo enviada ao assinante (venda #{$venda['id']}): " . json_encode($resp));
}

$entrada = file_get_contents('php://input');

if (empty($entrada)) {
    // Ping de validação de URL (corpo vazio) -- responde OK sem processar nada.
    logWebhookGateway("Ping de validação recebido.");
    http_response_code(200);
    exit;
}

logWebhookGateway("Payload recebido: " . resumoPayloadGateway($entrada));

$notificacao = json_decode($entrada, true);

if (!is_array($notificacao)) {
    logWebhookGateway("Payload inválido (não é JSON).");
    http_response_code(200);
    exit;
}

$txid = extrairIdentificadorOmegapayments($notificacao);

if (empty($txid)) {
    logWebhookGateway("Notificação sem identificador de transação reconhecível. Ignorada.");
    http_response_code(200);
    exit;
}

logWebhookGateway("Processando notificação | identificador=$txid");

$status_payload = strtoupper(trim((string)($notificacao['transaction']['status'] ?? '')));
$payload_diz_pago = gatewayStatusPago('omegapayments', $status_payload);
$assinatura_payload = is_array($notificacao['subscription'] ?? null) ? $notificacao['subscription'] : null;

$stmt = $pdo->prepare("SELECT * FROM vendas WHERE transacao_id = ?");
$stmt->execute([$txid]);
$venda = $stmt->fetch();
$venda_ciclo_nova = false;

if (!$venda && $assinatura_payload) {
    $venda = criarVendaCicloOmegapayments($notificacao, $txid);
    if ($venda) {
        $venda_ciclo_nova = true;
        logWebhookGateway("Ciclo {$assinatura_payload['cycle']} da assinatura {$assinatura_payload['id']}: criada venda #{$venda['id']} (transação $txid, status do payload=$status_payload).");
        if (!$payload_diz_pago) {
            avisarCobrancaCicloOmegapayments($venda, $notificacao);
            http_response_code(200);
            exit;
        }
    }
}

// Piso da expiração do acesso em assinatura: fim do ciclo pelo calendário do gateway (null se o payload não trouxer o suficiente).
$expiracao_minima = $assinatura_payload ? fimCicloAssinaturaOmegapayments($assinatura_payload) : null;

processarPagamentoGateway('omegapayments', $txid, $venda ?: null, $payload_diz_pago, $status_payload, $venda_ciclo_nova, $expiracao_minima);
http_response_code(200);
