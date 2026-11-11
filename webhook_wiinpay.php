<?php
declare(strict_types=1);

/**
 * Webhook da WiinPay. O status de pago chega como "PAID" (maiúsculo; antes era "paid"). A documentação não descreve o corpo do aviso, então o id do
 * pagamento e o status são lidos de forma tolerante (funcoes/gateways/_util.php) e cada id candidato é procurado em vendas.transacao_id.
 * O aviso não traz assinatura para validar: o pagamento só é liberado depois de reconsultar a cobrança na API (processarPagamentoGateway).
 */
require_once __DIR__ . '/funcoes/gateways/wiinpay.php';
require_once __DIR__ . '/funcoes/pagamento_confirmado.php';

iniciarWebhookGateway('wiinpay');

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

$candidatos = gatewayColetarValores($notificacao, WiinPay::CHAVES_ID);
if (!$candidatos) {
    logWebhookGateway('Notificação sem identificador de pagamento reconhecível. Ignorada.');
    http_response_code(200);
    exit;
}

$status_payload = strtoupper(trim((string) gatewayBuscarValor($notificacao, ['status', 'payment_status'])));
$payload_diz_pago = gatewayStatusPago('wiinpay', $status_payload);

$venda = null;
$txid = $candidatos[0];
$stmt = $pdo->prepare('SELECT * FROM vendas WHERE transacao_id = ?');
foreach ($candidatos as $candidato) {
    $stmt->execute([$candidato]);
    $achada = $stmt->fetch();
    if ($achada) {
        $venda = $achada;
        $txid = $candidato;
        break;
    }
}
logWebhookGateway("Processando notificação | identificador=$txid | status=$status_payload");

processarPagamentoGateway('wiinpay', $txid, $venda, $payload_diz_pago, $status_payload);
http_response_code(200);
