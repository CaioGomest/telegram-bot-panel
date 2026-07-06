<?php
declare(strict_types=1);

/**
 * Integração com a InfoPago (Pix)
 * Autenticação: OAuth2 Client Credentials (POST /oauth/token, form-urlencoded) + certificado mTLS obrigatório
 * em toda requisição (igual à Efí). Payload/resposta de cobrança segue o padrão Bacen (calendario, valor,
 * chave, txid, loc, pixCopiaECola) — e a resposta de criação já retorna o pixCopiaECola diretamente,
 * sem precisar de um segundo request de QR code.
 *
 * Base URL confirmada pelo suporte da InfoPago (2026-07-03): https://api.pix.infopago.com.br
 * (a doc pública mostrava "pix.infopago.com.br" só como exemplo genérico — a URL real de produção
 * é específica por conta e não fica documentada publicamente).
 */
class InfopagoBanco {
    /**
     * O servidor mTLS da InfoPago (api.pix.infopago.com.br) usa um certificado assinado por uma CA
     * própria da ONZ Software ("onz.software"), não por uma autoridade pública. Sem o certificado raiz
     * dessa CA (não disponibilizado junto com o certificado do cliente), não dá pra validar o certificado
     * do servidor — por isso a verificação fica desligada por enquanto. O mTLS ainda garante que É a
     * InfoPago quem está autenticando NÓS (nosso certificado de cliente), só não confirmamos a identidade
     * deles nessa ponta. Ideal: pedir o certificado da CA ao suporte da InfoPago e usar CURLOPT_CAINFO.
     */
    private const VERIFICAR_CERTIFICADO_SERVIDOR = false;

    private string $clientId;
    private string $clientSecret;
    private string $certificadoPath;
    private string $certPassword;
    private bool $producao;
    private string $baseUrl;
    private ?string $accessToken = null;

    public function __construct(string $clientId, string $clientSecret, string $certificadoPath, bool $producao = true, string $certPassword = '') {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->certificadoPath = $certificadoPath;
        $this->certPassword = $certPassword;
        $this->producao = $producao;
        // TODO: confirmar URL de homologação/sandbox (diferente da de produção — pedir ao suporte).
        $this->baseUrl = 'https://api.pix.infopago.com.br';
    }

    private function writeLog(string $msg): void {
        $logFile = __DIR__ . '/../logs/split_debug.log';
        $line = '[' . date('Y-m-d H:i:s') . '] [InfoPago] ' . $msg . PHP_EOL;
        file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Aplica o certificado mTLS na requisição cURL — exigido pela InfoPago em toda chamada, inclusive na autenticação.
     */
    private function aplicarCertificado($ch): void {
        $certReal = realpath($this->certificadoPath);
        if (!$certReal || !file_exists($certReal)) {
            return;
        }
        curl_setopt($ch, CURLOPT_SSLCERT, $certReal);
        $ext = strtolower(pathinfo($certReal, PATHINFO_EXTENSION));
        // .pfx e .p12 são o mesmo formato (PKCS#12) — a InfoPago distribui os certificados em .pfx.
        curl_setopt($ch, CURLOPT_SSLCERTTYPE, in_array($ext, ['p12', 'pfx'], true) ? 'P12' : 'PEM');
        if (!empty($this->certPassword)) {
            curl_setopt($ch, CURLOPT_SSLCERTPASSWD, $this->certPassword);
        }
    }

    /**
     * Autentica na API e obtém o token de acesso.
     * POST /oauth/token, Content-Type: application/x-www-form-urlencoded, campos snake_case.
     */
    public function autenticar(): bool {
        $certReal = realpath($this->certificadoPath);
        if (!$certReal || !file_exists($certReal)) {
            $this->writeLog("Certificado mTLS não encontrado: {$this->certificadoPath}");
            return false;
        }
        $this->writeLog("Autenticando | certificado=$certReal (" . filesize($certReal) . " bytes) | endpoint={$this->baseUrl}/oauth/token");

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
        $this->writeLog("Falha na autenticação (HTTP $httpCode) | corpo_bruto=" . $corpoBruto);
        return false;
    }

    /**
     * Valida se as credenciais (e o certificado) estão funcionando.
     */
    public function validarCredenciais(): bool {
        return $this->autenticar();
    }

    /**
     * Método genérico para requisições autenticadas na API. Certificado mTLS vai em toda chamada.
     */
    private function sendRequest(string $method, string $uri, ?array $body = null): array {
        if (!$this->accessToken) {
            if (!$this->autenticar()) {
                return ['sucesso' => false, 'erro' => 'Falha na autenticação', 'codigo_http' => 0];
            }
        }

        $endpoint = $this->baseUrl . $uri;
        $headers = [
            'Authorization: Bearer ' . $this->accessToken,
            'Content-Type: application/json',
        ];

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
        $mensagem = $data['message'] ?? ($data['erro'] ?? 'Erro na requisição');
        return ['sucesso' => false, 'erro' => $mensagem, 'detalhes' => $data, 'codigo_http' => $httpCode];
    }

    /**
     * Monta o payload de cobrança imediata no formato Bacen (igual à Efí).
     */
    public function montaPayloadCobranca(float $valor, string $chavePix, ?array $splitConfig = null, int $expiracaoSegundos = 3600): array {
        $payload = [
            'calendario' => ['expiracao' => $expiracaoSegundos],
            'valor'      => ['original' => number_format($valor, 2, '.', '')],
            'chave'      => $chavePix,
        ];

        // TODO: split de pagamento ainda não confirmado na API da InfoPago (ver docs/infopago/01-api-referencia.md §5).
        if ($splitConfig) {
            $this->writeLog("Split recebido mas ainda não suportado pela integração InfoPago | splitConfig=" . json_encode($splitConfig));
        }

        return $payload;
    }

    /**
     * Cria uma cobrança imediata (PUT /cob/{txid}). O txid é gerado localmente, no mesmo padrão da Efí.
     * A resposta já inclui "pixCopiaECola" diretamente — não é preciso um segundo request de QR code.
     */
    public function criarCobranca(array $payload): array {
        $txid = bin2hex(random_bytes(17)); // 34 chars hex, alfanumérico (padrão Bacen)
        $resp = $this->sendRequest('PUT', "/cob/{$txid}", $payload);

        if (!($resp['sucesso'] ?? false)) {
            $this->writeLog("criarCobranca FALHOU | txid={$txid} | erro=" . json_encode($resp['erro'] ?? ''));
            return $resp;
        }

        // Garante que o txid usado na criação esteja disponível na resposta, mesmo que a API não o repita.
        $resp['dados']['txid'] = $resp['dados']['txid'] ?? $txid;
        $this->writeLog("criarCobranca OK | txid={$txid}");
        return $resp;
    }

    /**
     * Consulta uma cobrança pelo txid (GET /cob/{txid}).
     */
    public function consultarCobranca(string $txid): array {
        return $this->sendRequest('GET', "/cob/{$txid}");
    }

    /**
     * Configura o Webhook Pix (PUT /webhook/{chave}).
     */
    public function configurarWebhook(string $chave, string $urlWebhook): array {
        return $this->sendRequest('PUT', '/webhook/' . urlencode($chave), ['webhookUrl' => $urlWebhook]);
    }
}
