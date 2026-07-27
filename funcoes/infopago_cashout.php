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

    private string $client_id;
    private string $client_secret;
    private string $certificado_path;
    private string $cert_password;
    private string $base_url;
    private ?string $access_token = null;

    public function __construct(string $client_id, string $client_secret, string $certificado_path, string $cert_password = '') {
        $this->clientId = $client_id;
        $this->clientSecret = $client_secret;
        $this->certificadoPath = $certificado_path;
        $this->certPassword = $cert_password;
        $this->baseUrl = 'https://cashout.infopago.com.br/api/v2';
    }

    private function writeLog(string $msg): void {
        $log_file = __DIR__ . '/../logs/split_debug.log';
        $line = '[' . date('Y-m-d H:i:s') . '] [InfoPagoCashout] ' . $msg . PHP_EOL;
        file_put_contents($log_file, $line, FILE_APPEND | LOCK_EX);
    }

    private function aplicarCertificado($ch): void {
        $cert_real = realpath($this->certificadoPath);
        if (!$cert_real || !file_exists($cert_real)) {
            return;
        }
        curl_setopt($ch, CURLOPT_SSLCERT, $cert_real);
        $ext = strtolower(pathinfo($cert_real, PATHINFO_EXTENSION));
        curl_setopt($ch, CURLOPT_SSLCERTTYPE, in_array($ext, ['p12', 'pfx'], true) ? 'P12' : 'PEM');
        if (!empty($this->certPassword)) {
            curl_setopt($ch, CURLOPT_SSLCERTPASSWD, $this->certPassword);
        }
    }

    public function autenticar(): bool {
        $cert_real = realpath($this->certificadoPath);
        if (!$cert_real || !file_exists($cert_real)) {
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
        $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($curl_error) {
            $this->writeLog("Erro de conexão na autenticação: $curl_error");
            return false;
        }

        $data = json_decode((string)$response, true);

        if (($http_code === 200 || $http_code === 201 || $http_code === 202) && isset($data['access_token'])) {
            $this->accessToken = $data['access_token'];
            return true;
        }

        $corpo_bruto = is_string($response) ? substr($response, 0, 500) : '(vazio)';
        $this->writeLog("Falha na autenticação de Cash-Out (HTTP $http_code) | corpo_bruto=" . $corpo_bruto);
        return false;
    }

    public function validarCredenciais(): bool {
        return $this->autenticar();
    }

    private function sendRequest(string $method, string $uri, ?array $body = null, array $headers_extra = []): array {
        if (!$this->accessToken) {
            if (!$this->autenticar()) {
                return ['sucesso' => false, 'erro' => 'Falha na autenticação', 'codigo_http' => 0];
            }
        }

        $endpoint = $this->baseUrl . $uri;
        $headers = array_merge([
            'Authorization: Bearer ' . $this->accessToken,
            'Content-Type: application/json',
        ], $headers_extra);

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
        $mensagem = $data['detail'] ?? ($data['title'] ?? 'Erro na requisição');
        return ['sucesso' => false, 'erro' => $mensagem, 'detalhes' => $data, 'codigo_http' => $http_code];
    }

    /**
     * Chave Pix do tipo telefone precisa vir no formato E.164 (+5511999999999) — a InfoPago
     * rejeita ("Invalid Pix Entry") se faltar o "+" ou o DDI 55. CPF, CNPJ, e-mail e EVP (UUID)
     * não são mexidos, só o caso de 10 ou 11 dígitos que parece DDD+telefone brasileiro.
     */
    private static function normalizarChaveTelefone(string $chave): string {
        $chave = trim($chave);
        if (str_contains($chave, '@') || str_contains($chave, '-')) {
            return $chave; // e-mail ou EVP (UUID) — não é telefone
        }

        $somente_digitos = preg_replace('/\D/', '', $chave);

        if (str_starts_with($chave, '+55') && strlen($somente_digitos) === 13) {
            return $chave;
        }
        // Celular brasileiro: DDD (2) + 9 fixo + 8 dígitos = 11 dígitos, com '9' na 3ª posição.
        // Esse terceiro dígito distingue de CPF (também 11 dígitos, mas sem esse padrão fixo).
        if (strlen($somente_digitos) === 11 && $somente_digitos[2] === '9') {
            return '+55' . $somente_digitos;
        }
        if (strlen($somente_digitos) === 13 && str_starts_with($somente_digitos, '55') && $somente_digitos[4] === '9') {
            return '+' . $somente_digitos; // DDI + DDD + celular, sem o "+"
        }

        return $chave; // CPF, CNPJ, e-mail ou EVP: deixa como está
    }

    /**
     * Transfere um valor via Pix para uma chave (POST /pix/payments/dict).
     * "amount" é em REAIS (não centavos) — confirmado pelo suporte InfoPago em 2026-07-21:
     * um envio com amount=30 foi cobrado como R$30,00, e amount=1 como R$1,00.
     *
     * @param string $chave_pix_destino Chave Pix de destino (CPF, CNPJ, e-mail, telefone ou EVP)
     * @param float  $valor           Valor em reais
     * @param string $descricao       Descrição da transferência (aparece pro destinatário)
     */
    public function transferirPorChavePix(string $chave_pix_destino, float $valor, string $descricao = 'Split'): array {
        $chave_pix_destino = self::normalizarChaveTelefone($chave_pix_destino);
        $idempotency_key = bin2hex(random_bytes(16));
        $payload = [
            'pixKey'      => $chave_pix_destino,
            // NORM (não HIGH) — HIGH exige creditorDocument (CPF/CNPJ do destinatário), que não coletamos.
            'priority'    => 'NORM',
            'description' => substr($descricao, 0, 140),
            'paymentFlow' => 'INSTANT',
            'expiration'  => 600,
            'payment'     => [
                'currency' => 'BRL',
                'amount'   => round($valor, 2),
            ],
        ];

        $resp = $this->sendRequest('POST', '/pix/payments/dict', $payload, ["x-idempotency-key: $idempotency_key"]);

        if (!($resp['sucesso'] ?? false)) {
            $this->writeLog("transferirPorChavePix FALHOU | chave={$chave_pix_destino} valor={$valor} | erro=" . json_encode($resp['erro'] ?? ''));
        } else {
            $this->writeLog("transferirPorChavePix OK | chave={$chave_pix_destino} valor={$valor} | endToEndId=" . ($resp['dados']['endToEndId'] ?? '?'));
        }

        return $resp;
    }
}
