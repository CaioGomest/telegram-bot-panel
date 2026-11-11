<?php
declare(strict_types=1);

/**
 * Webhook da NxGate (cobrança Pix). Eventos: PIX Pendente (status "pending", sem "type") e PIX Pago (type QR_CODE_COPY_AND_PASTE_PAID, data.tx_id).
 * Saque e conversão de moeda não têm venda e são ignorados. A NxGate NÃO reenvia o webhook: se este aviso se perder, o cron de verificação do Pix confirma.
 * Segurança: sem validação de assinatura (o suporte da NxGate, em 10/11/2026, disse que plataforma multi-cliente não deve usar HMAC, e a tela nem tem mais
 * o campo: um segredo antigo salvo recusava todo aviso). O pagamento nunca é liberado só pelo aviso — processarPagamentoGateway() reconsulta a cobrança
 * na API (/v2/consultar) e exige status paid.
 */
require_once __DIR__ . '/funcoes/gateways/nxgate.php';
require_once __DIR__ . '/funcoes/pagamento_confirmado.php';

iniciarWebhookGateway('nxgate');

$entrada = file_get_contents('php://input');

if (empty($entrada)) {
    logWebhookGateway('Ping de validação recebido.');
    http_response_code(200);
    exit;
}

logWebhookGateway('Payload recebido: ' . resumoPayloadGateway($entrada));

$notificacao = json_decode($entrada, true);
if (!is_array($notificacao)) {
    logWebhookGateway('Payload inválido (não é JSON).');
    http_response_code(200);
    exit;
}

$tipo = (string) ($notificacao['type'] ?? '');
$dados = is_array($notificacao['data'] ?? null) ? $notificacao['data'] : [];
$evento_pago = $tipo === 'QR_CODE_COPY_AND_PASTE_PAID';

if ($tipo !== '' && !$evento_pago) {
    logWebhookGateway("Evento $tipo não é de cobrança Pix. Ignorado.");
    http_response_code(200);
    exit;
}

$txid = (string) ($dados['tx_id'] ?? ($dados['qr_code_id'] ?? ($notificacao['transaction_id'] ?? '')));
if ($txid === '') {
    logWebhookGateway('Notificação sem identificador de transação reconhecível. Ignorada.');
    http_response_code(200);
    exit;
}
logWebhookGateway("Processando notificação | identificador=$txid");

$status_payload = strtoupper(trim((string) ($evento_pago ? ($dados['status'] ?? '') : ($notificacao['status'] ?? 'PENDING'))));
$payload_diz_pago = $evento_pago && gatewayStatusPago('nxgate', $status_payload);

$stmt = $pdo->prepare('SELECT * FROM vendas WHERE transacao_id = ?');
$stmt->execute([$txid]);
$venda = $stmt->fetch() ?: null;

processarPagamentoGateway('nxgate', $txid, $venda, $payload_diz_pago, $status_payload);
http_response_code(200);
