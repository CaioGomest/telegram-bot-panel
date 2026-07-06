<?php
declare(strict_types=1);

/**
 * Integração com a API de Contas (Cash-Out) da InfoPago.
 * Usada apenas para simular split de pagamento: depois que um Pix de cobrança (Cash-In) é
 * confirmado, o webhook chama esta classe para transferir a parte do split para outra chave Pix.
 *
 * IMPORTANTE: é uma API/produto SEPARADO da cobrança (Cash-In) — tem client_id/client_secret
 * e certificado mTLS próprios, diferentes dos usados em InfopagoBanco (ver
 * docs/infopago/01-api-referencia.md §0 e §7 para o histórico dessa descoberta).
 */
class InfopagoCashout {
    /** Mesma observação de InfopagoBanco: CA própria da ONZ Software, sem certificado raiz disponível. */
    private const VERIFICAR_CERTIFICADO_SERVIDOR = false;

    private string $clientId;
    private string $clientSecret;
    private string $certificadoPath;
    private string $certPassword;
    private string $baseUrl;
    private ?string $accessToken = null;

    public function __construct(string $clientId, string $clientSecret, string $certificadoPath, string $certPassword = '') {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->certificadoPath = $certificadoPath;
        $this->certPassword = $certPassword;
        $this->baseUrl = 'https://cashout.infopago.com.br/api/v2';
    }

    private function writeLog(string $msg): void {
        $logFile = __DIR__ . '/../logs/split_debug.log';
        $line = '[' . date('Y-m-d H:i:s') . '] [InfoPagoCashout] ' . $msg . PHP_EOL;
        file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    }

    private function aplicarCertificado($ch): void {
        $certReal = realpath($this->certificadoPath);
        if (!$certReal || !file_exists($certReal)) {
            return;
        }
        curl_setopt($ch, CURLOPT_SSLCERT, $certReal);
        $ext = strtolower(pathinfo($certReal, PATHINFO_EXTENSION));
        curl_setopt($ch, CURLOPT_SSLCERTTYPE, in_array($ext, ['p12', 'pfx'], true) ? 'P12' : 'PEM');
        if (!empty($this->certPassword)) {
            curl_setopt($ch, CURLOPT_SSLCERTPASSWD, $this->certPassword);
        }
    }

    public function autenticar(): bool {
        $certReal = realpath($this->certificadoPath);
        if (!$certReal || !file_exists($certReal)) {
            $this->writeLog("Certificado mTLS de Cash-Out não encontrado: {$this->certificadoPath}");
            return false;
        }

        $endpoint = $this->baseUrl . '/oauth/token';
        $body = http_build_query([
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type'    => 'client_credentials',
        ]);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $endpoint,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => self::VERIFICAR_CERTIFICADO_SERVIDOR,
            CURLOPT_SSL_VERIFYHOST => self::VERIFICAR_CERTIFICADO_SERVIDOR ? 2 : 0,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
        ]);
        $this->aplicarCertificado($ch);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            $this->writeLog("Erro de conexão na autenticação: $curlError");
            return false;
        }

        $data = json_decode((string)$response, true);

        if (($httpCode === 200 || $httpCode === 201) && isset($data['access_token'])) {
            $this->accessToken = $data['access_token'];
            return true;
        }

        $corpoBruto = is_string($response) ? substr($response, 0, 500) : '(vazio)';
        $this->writeLog("Falha na autenticação de Cash-Out (HTTP $httpCode) | corpo_bruto=" . $corpoBruto);
        return false;
    }

    public function validarCredenciais(): bool {
        return $this->autenticar();
    }

    private function sendRequest(string $method, string $uri, ?array $body = null, array $headersExtra = []): array {
        if (!$this->accessToken) {
            if (!$this->autenticar()) {
                return ['sucesso' => false, 'erro' => 'Falha na autenticação', 'codigo_http' => 0];
            }
        }

        $endpoint = $this->baseUrl . $uri;
        $headers = array_merge([
            'Authorization: Bearer ' . $this->accessToken,
            'Content-Type: application/json',
        ], $headersExtra);

        $ch = curl_init();
        $options = [
            CURLOPT_URL            => $endpoint,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => self::VERIFICAR_CERTIFICADO_SERVIDOR,
            CURLOPT_SSL_VERIFYHOST => self::VERIFICAR_CERTIFICADO_SERVIDOR ? 2 : 0,
            CURLOPT_CUSTOMREQUEST  => $method,
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($body);
        }
        curl_setopt_array($ch, $options);
        $this->aplicarCertificado($ch);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            $this->writeLog("cURL Error ($method $uri): $curlError");
            return ['sucesso' => false, 'erro' => "Erro de conexão: $curlError", 'codigo_http' => 0];
        }

        $data = json_decode((string)$response, true);
        if (!is_array($data)) {
            $data = ['raw' => $response];
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            return ['sucesso' => true, 'dados' => $data, 'codigo_http' => $httpCode];
        }

        $this->writeLog("Requisição falhou | $method $uri | HTTP $httpCode | " . json_encode($data));
        $mensagem = $data['detail'] ?? ($data['title'] ?? 'Erro na requisição');
        return ['sucesso' => false, 'erro' => $mensagem, 'detalhes' => $data, 'codigo_http' => $httpCode];
    }

    /**
     * Transfere um valor via Pix para uma chave (POST /pix/payments/dict).
     * `[A CONFIRMAR]`: se "amount" é em centavos (inteiro) ou reais — assumindo centavos,
     * mesmo padrão de mercado (ver docs/infopago/01-api-referencia.md).
     *
     * @param string $chavePixDestino Chave Pix de destino (CPF, CNPJ, e-mail, telefone ou EVP)
     * @param float  $valor           Valor em reais
     * @param string $descricao       Descrição da transferência (aparece pro destinatário)
     */
    public function transferirPorChavePix(string $chavePixDestino, float $valor, string $descricao = 'Split'): array {
        $idempotencyKey = bin2hex(random_bytes(16));
        $payload = [
            'pixKey'      => $chavePixDestino,
            'priority'    => 'HIGH',
            'description' => substr($descricao, 0, 140),
            'paymentFlow' => 'INSTANT',
            'expiration'  => 600,
            'payment'     => [
                'currency' => 'BRL',
                'amount'   => (int) round($valor * 100),
            ],
        ];

        $resp = $this->sendRequest('POST', '/pix/payments/dict', $payload, ["x-idempotency-key: $idempotencyKey"]);

        if (!($resp['sucesso'] ?? false)) {
            $this->writeLog("transferirPorChavePix FALHOU | chave={$chavePixDestino} valor={$valor} | erro=" . json_encode($resp['erro'] ?? ''));
        } else {
            $this->writeLog("transferirPorChavePix OK | chave={$chavePixDestino} valor={$valor} | endToEndId=" . ($resp['dados']['endToEndId'] ?? '?'));
        }

        return $resp;
    }
}
