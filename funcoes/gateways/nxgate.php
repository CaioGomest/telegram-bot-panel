<?php
declare(strict_types=1);

/**
 * NxGate — gateway Pix. Documentação: https://nxgate.com.br/doc (lida em 08/11/2026).
 *  - Autenticação: `api_key` no CORPO de toda requisição (sem header). Chave de teste `nxg_test_...` usa o sandbox.
 *  - Criar: POST /v2/cobranca  -> idTransaction + paymentCode (copia-e-cola). Consultar: POST /v2/consultar -> status paid|waiting.
 *  - Webhook: POST na URL informada em `webhook`; assinatura HMAC-SHA256 (X-NXGate-Signature) com o "segredo de webhook" do painel; sem reenvio automático.
 *  - Split: split_users[{username, type, value}]; aqui sempre `fixed` em centavos (a mesma conta da comissão gravada na venda).
 *  - Sem Pix recorrente.
 * Credenciais no painel: client_secret = API Key; cert_password = segredo do webhook (opcional; com ele o webhook exige assinatura válida).
 */
require_once __DIR__ . '/_contrato.php';
require_once __DIR__ . '/_http.php';
require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/../split_validacao.php';

class NxGate implements ProvedorGateway {
    private const URL_PRODUCAO = 'https://api.nxgate.com.br';
    private const URL_SANDBOX  = 'https://sandbox.nxgate.com.br';

    public function __construct(private string $api_key) {}

    private function urlBase(): string {
        return str_starts_with($this->api_key, 'nxg_test_') ? self::URL_SANDBOX : self::URL_PRODUCAO;
    }

    /** split_users no formato da NxGate: fatia em centavos (type fixed), igual ao que comissaoDoSplit() grava na venda. */
    private function montaSplitUsers(float $valor, array $split_config): array {
        $itens = [];
        foreach ($split_config as $s) {
            $centavos = (int) round(valorDaFatiaDoSplit($s, $valor) * 100);
            if ($centavos > 0) {
                $itens[] = ['username' => (string) ($s['chave'] ?? ''), 'type' => 'fixed', 'value' => $centavos];
            }
        }
        return $itens;
    }

    public function montaPayloadCobranca(float $valor, string $chave_pix, ?array $split_config = null, int $expiracao_segundos = 3600, string $nome_cliente = 'Cliente Telegram', string $documento_cliente = ''): array {
        $payload = [
            'api_key'      => $this->api_key,
            'valor'        => round($valor, 2),
            'nome_pagador' => mb_substr(trim($nome_cliente) !== '' ? trim($nome_cliente) : 'Cliente Telegram', 0, 255),
            'descricao'    => 'Pagamento',
            'webhook'      => gatewayUrlWebhook('webhook_nxgate.php'),
            'magic_id'     => bin2hex(random_bytes(12)),   // nosso identificador; volta em data.magic_id no webhook
        ];
        // Sem documento a NxGate usa o da conta; só manda se for um CPF/CNPJ de tamanho válido.
        $documento = preg_replace('/\D/', '', $documento_cliente);
        if (in_array(strlen((string) $documento), [11, 14], true)) {
            $payload['documento_pagador'] = $documento;
        }
        if (!empty($split_config)) {
            $split_users = $this->montaSplitUsers($valor, $split_config);
            if ($split_users) {
                $payload['split_users'] = $split_users;
            }
        }
        return $payload;
    }

    public function criarCobranca(array $payload): array {
        // A mesma cobrança repetida em até 5 min devolve a mesma transação (idempotência), nunca uma cobrança em dobro.
        $chave_idempotencia = gatewayUuidV4((string) ($payload['magic_id'] ?? random_bytes(8)));

        $resp = gatewayChamadaJson('nxgate', 'POST', $this->urlBase() . '/v2/cobranca', $payload, ['X-Idempotency-Key: ' . $chave_idempotencia], 15);
        if (!$resp['sucesso']) {
            return $resp;
        }
        $dados = $resp['dados'];
        $codigo = (string) ($dados['paymentCode'] ?? ($dados['paymentInstruction']['pixCopyAndPaste'] ?? ''));
        $resp['dados']['pixCopiaECola'] = $codigo;
        $resp['dados']['txid'] = (string) ($dados['idTransaction'] ?? '');
        gatewayLog('nxgate', 'criarCobranca OK | txid=' . $resp['dados']['txid']);
        return $resp;
    }

    public function montaPayloadAssinatura(float $valor, string $periodicidade, string $id_produto, string $nome_produto, string $nome_cliente = 'Cliente Telegram', string $documento_cliente = '', ?array $split_config = null): array {
        return [];
    }

    public function criarAssinatura(array $payload): array {
        return ['sucesso' => false, 'erro' => 'A NxGate não tem Pix recorrente.', 'codigo_http' => 0];
    }

    /** Status em dados.status: PAID (pago) ou WAITING. */
    public function consultarCobranca(string $txid): array {
        $resp = gatewayChamadaJson('nxgate', 'POST', $this->urlBase() . '/v2/consultar', ['api_key' => $this->api_key, 'transaction_id' => $txid], [], 15);
        if ($resp['sucesso']) {
            $resp['dados']['status'] = strtoupper((string) ($resp['dados']['status'] ?? ''));
        }
        return $resp;
    }

    /**
     * Confere a assinatura do webhook: "sha256=" + HMAC-SHA256(segredo, timestamp + "." + corpo bruto), com tolerância de 300 s no timestamp.
     * Compara em tempo constante e sobre o corpo exatamente como chegou (nunca o JSON já decodificado).
     */
    public static function assinaturaValida(string $corpo_bruto, string $timestamp, string $assinatura, string $segredo, ?int $agora = null): bool {
        if ($segredo === '' || $assinatura === '' || !ctype_digit($timestamp)) {
            return false;
        }
        if (abs(($agora ?? time()) - (int) $timestamp) > 300) {
            return false;
        }
        return hash_equals('sha256=' . hash_hmac('sha256', $timestamp . '.' . $corpo_bruto, $segredo), $assinatura);
    }
}
