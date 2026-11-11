<?php
declare(strict_types=1);

/**
 * Webhook de pagamento de gateway = webhook_<gateway>.php na raiz (webhook_omegapayments.php, webhook_nxgate.php, webhook_wiinpay.php...). Função pura, sem ler
 * nada do ambiente: suspensão remota (controle.php) e manutenção (manutencao.php) usam a mesma regra, então gateway novo nunca precisa entrar numa lista.
 * O webhook do Telegram (webhook.php) não casa de propósito: tem tratamento próprio nos dois lugares.
 */
function ehWebhookPagamentoGateway(string $rel): bool
{
    return preg_match('/^webhook_[a-z0-9]+\.php$/', $rel) === 1;
}
