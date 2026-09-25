<?php
declare(strict_types=1);

/**
 * Integração com a OmegaPayments (OmegaPay) — gateway Pix com split nativo.
 * Autenticação simples via headers (sem OAuth2, sem certificado mTLS):
 *   x-public-key: <client_id>
 *   x-secret-key: <client_secret>
 *
 * Cada dono de bot usa as próprias credenciais aqui (não é conta compartilhada entre usuários).
 *
 * [A CONFIRMAR EM SANDBOX] A documentação pública (app.omegapayments.com.br/docs/v1) bloqueou
 * o levantamento com bot-detection (403) antes de confirmar: a URL base real de produção, o
 * path exato do endpoint de consulta de transação, e o schema completo de cada item do array
 * `splits[]`. Os valores usados abaixo são o melhor palpite com base no que foi confirmado
 * (endpoint de criação de cobrança e formato da resposta) — ver anotações em
 * `anotacoes/pendencias-sandbox-omegapayments.md` antes de considerar isso pronto pra produção.
 */
class OmegaPaymentsBanco {
    private string $client_id;
    private string $client_secret;
    private string $base_url;

    public function __construct(string $client_id, string $client_secret) {
        $this->client_id = $client_id;
        $this->client_secret = $client_secret;
        // [A CONFIRMAR] URL base real não confirmada — ajustar aqui assim que confirmado com o
        // suporte da OmegaPayments ou testando em sandbox real.
        $this->base_url = 'https://api.omegapayments.com.br';
    }

    private function writeLog(string $msg): void {
        $log_file = __DIR__ . '/../logs/omegapayments_debug.log';
        $line = '[' . date('Y-m-d H:i:s') . '] [OmegaPayments] ' . $msg . PHP_EOL;
        file_put_contents($log_file, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Método genérico para requisições autenticadas na API. Sem OAuth/mTLS — só os headers de
     * chave pública/secreta em toda chamada.
     */
    private function sendRequest(string $method, string $uri, ?array $body = null): array {
        $endpoint = $this->base_url . $uri;
        $headers = [
            'x-public-key: ' . $this->client_id,
            'x-secret-key: ' . $this->client_secret,
            'Content-Type: application/json',
        ];

        $ch = curl_init();
        $options = [
            CURLOPT_URL            => $endpoint,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CUSTOMREQUEST  => $method,
        ];
        if ($body !== null) {
            // json_encode([]) gera "[]"; garante que sempre manda objeto ("{}") quando o
            // corpo estiver vazio.
            $options[CURLOPT_POSTFIELDS] = json_encode(empty($body) ? new stdClass() : $body);
        }
        curl_setopt_array($ch, $options);

        $response   = curl_exec($ch);
        $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($curl_error) {
            $this->writeLog("cURL Error ($method $uri): $curl_error");
            return ['sucesso' => false, 'erro' => "Erro de conexão: $curl_error", 'codigo_http' => 0];
        }

        $data = json_decode((string)$response, true);
        if (!is_array($data)) {
            $data = ['raw' => $response];
        }

        if ($http_code >= 200 && $http_code < 300) {
            return ['sucesso' => true, 'dados' => $data, 'codigo_http' => $http_code];
        }

        $this->writeLog("Requisição falhou | $method $uri | HTTP $http_code | " . json_encode($data));
        $mensagem = $data['message'] ?? ($data['erro'] ?? 'Erro na requisição');
        return ['sucesso' => false, 'erro' => $mensagem, 'detalhes' => $data, 'codigo_http' => $http_code];
    }

    /**
     * Monta a URL de callback fixa do webhook — a doc avisa que existe um limite de 20
     * webhooks por integração e que mandar uma URL diferente por transação estoura esse
     * limite, então essa URL tem que ser sempre a mesma (nunca gerada por venda).
     */
    private function montaCallbackUrl(): string {
        $esquema = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $caminho_base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
        return $esquema . '://' . $host . $caminho_base . '/webhook_omegapayments.php';
    }

    /**
     * Monta o payload de cobrança Pix avulsa (POST /gateway/pix/receive).
     *
     * $chave_pix não é usado no payload em si (a OmegaPayments não pede a chave Pix do
     * recebedor — quem recebe já é definido pelas credenciais da conta). O parâmetro
     * continua na assinatura só pra manter o mesmo "contrato" de provedor usado por
     * webhook.php/cron_verificar_pix.php pra qualquer gateway.
     *
     * $nome_cliente/$documento_cliente: nome do comprador (lead do Telegram) e CPF/CNPJ,
     * já resolvidos por quem chama (webhook.php).
     *
     * client.email/client.phone: a plataforma não coleta e-mail nem telefone do comprador
     * em nenhum ponto do fluxo — usa valores sintéticos determinísticos.
     * [A CONFIRMAR EM SANDBOX] se a OmegaPayments exige formato "válido" de e-mail/telefone
     * de verdade (não só não-vazio), e se usa esse e-mail pra mandar algo pro comprador
     * (nesse caso, o placeholder seria um problema real, não só cosmético).
     */
    public function montaPayloadCobranca(
        float $valor,
        string $chave_pix,
        ?array $split_config = null,
        int $expiracao_segundos = 3600,
        string $nome_cliente = 'Cliente Telegram',
        string $documento_cliente = ''
    ): array {
        $documento_limpo = preg_replace('/\D/', '', $documento_cliente) ?: '00000000000';

        $payload = [
            'identifier'  => bin2hex(random_bytes(16)),
            'amount'      => round($valor, 2),
            'client'      => [
                'name'     => substr($nome_cliente !== '' ? $nome_cliente : 'Cliente Telegram', 0, 200),
                'email'    => "cliente+{$documento_limpo}@telegrambot.local",
                'phone'    => '11999999999',
                'document' => $documento_limpo,
            ],
            // [A CONFIRMAR] schema de products[] não confirmado — um único item genérico
            // cobrindo o valor total da cobrança, padrão comum em gateways Pix com split.
            'products'    => [
                [
                    'name'     => 'Produto Digital',
                    'quantity' => 1,
                    'price'    => round($valor, 2),
                ],
            ],
            'dueDate'     => date('Y-m-d', time() + max($expiracao_segundos, 60)),
            'callbackUrl' => $this->montaCallbackUrl(),
            'metadata'    => ['origem' => 'telegram-bot-panel'],
        ];

        // splits[] — schema exato de cada item [A CONFIRMAR EM SANDBOX]. Mapeamento abaixo é
        // o palpite mais provável (chave Pix do destino + valor), a validar contra uma
        // chamada real antes de habilitar em produção.
        if (!empty($split_config)) {
            $payload['splits'] = array_map(static function (array $s): array {
                return [
                    'pixKey' => $s['chave'] ?? '',
                    'value'  => (float)($s['valor'] ?? 0),
                ];
            }, $split_config);
        }

        return $payload;
    }

    /**
     * Cria uma cobrança Pix avulsa (POST /gateway/pix/receive).
     */
    public function criarCobranca(array $payload): array {
        $resp = $this->sendRequest('POST', '/gateway/pix/receive', $payload);

        if (!($resp['sucesso'] ?? false)) {
            $this->writeLog("criarCobranca FALHOU | identifier=" . ($payload['identifier'] ?? '') . " | erro=" . json_encode($resp['erro'] ?? ''));
            return $resp;
        }

        // Normaliza pro mesmo formato que webhook.php/cron_verificar_pix.php esperam de
        // qualquer provedor (dados.pixCopiaECola / dados.txid) — mantém os campos originais
        // (pix.image, pix.base64, pix.expiresAt, webhookToken, order) também disponíveis.
        $resp['dados']['pixCopiaECola'] = $resp['dados']['pix']['code'] ?? '';
        $resp['dados']['txid'] = $resp['dados']['transactionId'] ?? ($payload['identifier'] ?? '');

        $this->writeLog("criarCobranca OK | txid=" . $resp['dados']['txid']);
        return $resp;
    }

    /**
     * Consulta o status de uma cobrança já criada.
     * [A CONFIRMAR EM SANDBOX] path exato do endpoint "Buscar transação" — a doc bloqueou
     * com 403 antes de confirmar. Palpite abaixo segue o mesmo padrão REST do endpoint de
     * criação (`/gateway/pix/receive`), ajustar assim que confirmado.
     */
    public function consultarCobranca(string $txid): array {
        $resp = $this->sendRequest('GET', '/gateway/pix/' . urlencode($txid));

        if ($resp['sucesso'] ?? false) {
            $resp['dados']['status'] = $resp['dados']['status'] ?? ($resp['dados']['statusCob'] ?? '');
        }

        return $resp;
    }
}
