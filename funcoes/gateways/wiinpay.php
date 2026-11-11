<?php
declare(strict_types=1);

/**
 * WiinPay — gateway Pix. Base: https://api-v2.wiinpay.com.br (as PDFs antigas ainda citam api.wiinpay.com.br; o dono confirmou que só a URL muda).
 *  - Criar: POST /payment/create, JSON com `api_key` no corpo: value (mín. R$ 3,00), name, email, description, webhook_url, split{user_id, value|percentage}, metadata.
 *  - Consultar: GET /payment/list/{paymentId} com `Authorization: Bearer <api_key>`.
 *  - Webhook: POST em webhook_url; o status de pago agora chega como "PAID" (antes "paid").
 *  - Sem Pix recorrente na documentação.
 * As PDFs NÃO descrevem o corpo das respostas nem do webhook: o id do pagamento e o código Pix são lidos de forma tolerante (nomes de campo conhecidos em
 * qualquer profundidade; o copia-e-cola é reconhecido pelo formato EMV "000201"). Pagamento nunca é liberado só pelo aviso: o webhook reconsulta a API.
 * Credenciais no painel: client_secret = API Key.
 */
require_once __DIR__ . '/_contrato.php';
require_once __DIR__ . '/_http.php';
require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/../split_validacao.php';

class WiinPay implements ProvedorGateway {
    private const URL_BASE = 'https://api-v2.wiinpay.com.br';
    /** Nomes que o id do pagamento pode ter na resposta/webhook, em ordem de prioridade. */
    public const CHAVES_ID = ['paymentId', 'payment_id', 'transaction_id', 'transactionId', 'txid', 'id'];
    private const CHAVES_CODIGO_PIX = ['qr_code', 'qrcode', 'pix_code', 'pixCopiaECola', 'pix_copia_cola', 'copy_paste', 'brcode', 'payload'];

    public function __construct(private string $api_key) {}

    /** split da WiinPay: um único destino (user_id) com o valor em reais — a mesma conta da comissão gravada na venda. */
    private function montaSplit(float $valor, array $split_config): ?array {
        $primeiro = $split_config[0] ?? null;
        if (!is_array($primeiro) || empty($primeiro['chave'])) {
            return null;
        }
        $fatia = valorDaFatiaDoSplit($primeiro, $valor);
        return $fatia > 0 ? ['user_id' => (string) $primeiro['chave'], 'value' => $fatia] : null;
    }

    public function montaPayloadCobranca(float $valor, string $chave_pix, ?array $split_config = null, int $expiracao_segundos = 3600, string $nome_cliente = 'Cliente Telegram', string $documento_cliente = ''): array {
        $referencia = bin2hex(random_bytes(8));
        $payload = [
            'api_key'     => $this->api_key,
            'value'       => round($valor, 2),
            'name'        => mb_substr(trim($nome_cliente) !== '' ? trim($nome_cliente) : 'Cliente Telegram', 0, 100),
            // A plataforma não coleta e-mail do comprador; o campo é obrigatório na API.
            'email'       => "cliente+{$referencia}@telegrambot.local",
            'description' => 'Pagamento',
            'webhook_url' => gatewayUrlWebhook('webhook_wiinpay.php'),
            'metadata'    => ['origem' => 'telegram-bot-panel', 'referencia' => $referencia],
        ];
        $split = !empty($split_config) ? $this->montaSplit($valor, $split_config) : null;
        if ($split !== null) {
            $payload['split'] = $split;
        }
        return $payload;
    }

    public function criarCobranca(array $payload): array {
        $resp = gatewayChamadaJson('wiinpay', 'POST', self::URL_BASE . '/payment/create', $payload, [], 15);
        if (!$resp['sucesso']) {
            return $resp;
        }
        $dados = $resp['dados'];
        $resp['dados']['txid'] = (string) gatewayBuscarValor($dados, self::CHAVES_ID);
        $resp['dados']['pixCopiaECola'] = gatewayBuscarCodigoPix($dados) ?: (string) gatewayBuscarValor($dados, self::CHAVES_CODIGO_PIX);
        gatewayLog('wiinpay', 'criarCobranca OK | txid=' . $resp['dados']['txid']);
        return $resp;
    }

    public function montaPayloadAssinatura(float $valor, string $periodicidade, string $id_produto, string $nome_produto, string $nome_cliente = 'Cliente Telegram', string $documento_cliente = '', ?array $split_config = null): array {
        return [];
    }

    public function criarAssinatura(array $payload): array {
        return ['sucesso' => false, 'erro' => 'A WiinPay não tem Pix recorrente.', 'codigo_http' => 0];
    }

    /** Status em dados.status (maiúsculo): PAID quando pago. */
    public function consultarCobranca(string $txid): array {
        $resp = gatewayChamadaJson('wiinpay', 'GET', self::URL_BASE . '/payment/list/' . rawurlencode($txid), null, ['Authorization: Bearer ' . $this->api_key], 20);
        if ($resp['sucesso']) {
            $resp['dados']['status'] = strtoupper((string) gatewayBuscarValor($resp['dados'], ['status', 'payment_status']));
        }
        return $resp;
    }
}
