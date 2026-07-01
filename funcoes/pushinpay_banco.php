<?php
declare(strict_types=1);

/**
 * Integração com a PushinPay (Pix)
 * Autenticação: Bearer Token estático (sem OAuth2, sem certificado)
 * Base URL: https://api.pushinpay.com.br/api
 */
class PushinpayBanco {
    /**
     * Assinatura (POST /pix/cashIn/subscription): loga JSON enviado, HTTP e resposta.
     * Desative (false) após os testes com o suporte.
     */
    private const DEBUG_ASSINATURA = true;

    private string $token;
    private string $baseUrl = 'https://api.pushinpay.com.br/api';

    /**
     * @param string $clientId  Token de API da PushinPay (campo "client_id" no banco)
     * @param string $clientSecret  Não utilizado pela PushinPay (mantido para compatibilidade)
     * @param string $certificadoPath  Não utilizado pela PushinPay (mantido para compatibilidade)
     * @param bool   $producao  Não utilizado (PushinPay tem apenas ambiente de produção)
     * @param string $certPassword  Não utilizado pela PushinPay (mantido para compatibilidade)
     */
    public function __construct(string $clientId, string $clientSecret = '', string $certificadoPath = '', bool $producao = true, string $certPassword = '') {
        $this->token = $clientId;
    }

    private function sendRequest(string $method, string $uri, ?array $body = null): array {
        $endpoint = $this->baseUrl . $uri;
        $headers = [
            'Authorization: Bearer ' . $this->token,
            'Accept: application/json',
            'Content-Type: application/json',
        ];

        $ch = curl_init();
        $options = [
            CURLOPT_URL            => $endpoint,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CUSTOMREQUEST  => $method,
        ];

        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($body);
        }

        curl_setopt_array($ch, $options);
        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            error_log("PushinpayBanco cURL Error: $curlError");
            return ['sucesso' => false, 'erro' => "Erro de conexão: $curlError", 'codigo_http' => 0];
        }

        $data = is_string($response) ? json_decode($response, true) : null;
        if (!is_array($data)) {
            $data = ['raw' => $response];
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            return ['sucesso' => true, 'dados' => $data, 'codigo_http' => $httpCode];
        }

        $mensagem = $data['message'] ?? ($data['error'] ?? 'Erro na requisição');
        return ['sucesso' => false, 'erro' => $mensagem, 'detalhes' => $data, 'codigo_http' => $httpCode, 'corpo_bruto' => is_string($response) ? $response : ''];
    }

    /**
     * PushinPay usa token estático — autenticação é sempre válida se o token não estiver vazio.
     */
    public function autenticar(): bool {
        return !empty($this->token);
    }

    /**
     * Valida o token fazendo uma requisição real à API.
     */
    public function validarCredenciais(): bool {
        $result = $this->sendRequest('GET', '/pix/cashIn');
        return ($result['codigo_http'] ?? 0) !== 401 && ($result['codigo_http'] ?? 0) !== 403;
    }

    /**
     * Monta o payload de cobrança no formato PushinPay.
     * Valor em centavos (int), split_rules para divisão de pagamento.
     */
    private function writeLog(string $msg): void {
        $logFile = __DIR__ . '/../logs/split_debug.log';
        $line = '[' . date('Y-m-d H:i:s') . '] [PushinPay] ' . $msg . PHP_EOL;
        file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    }

    public function montaPayloadCobranca(float $valor, string $chavePix, ?array $splitConfig = null, int $expiracaoSegundos = 3600, ?string $webhookUrl = null): array {
        $payload = [
            'value' => (int) round($valor * 100), // centavos
        ];

        if ($webhookUrl) {
            $payload['webhook_url'] = $webhookUrl;
        }

        if ($splitConfig) {
            // Suporta array de splits [['chave'=>...,'valor'=>...,'tipo'=>...], ...] ou split único
            $splits = isset($splitConfig['chave']) ? [$splitConfig] : array_values($splitConfig);
            $splitRules = [];
            foreach ($splits as $s) {
                if (empty($s['chave']) || !isset($s['valor']) || $s['valor'] === null || $s['valor'] === '') continue;
                $tipo = $s['tipo'] ?? 'percentual';
                if ($tipo === 'fixo') {
                    $splitValue = (int) round((float) $s['valor'] * 100);
                } else {
                    $splitValue = (int) round((float) $s['valor'] / 100 * $valor * 100);
                }
                $splitRules[] = ['account_id' => $s['chave'], 'type' => 'fixed', 'value' => $splitValue];
                $this->writeLog("Split configurado | tipo={$tipo} valor_original=" . json_encode($s['valor']) . " valor_enviado={$splitValue} account_id={$s['chave']}");
            }
            if (!empty($splitRules)) {
                $payload['split_rules'] = $splitRules;
            } else {
                $this->writeLog("Split NÃO aplicado | splitConfig=" . json_encode($splitConfig));
            }
        } else {
            $this->writeLog("Split NÃO aplicado | splitConfig=" . json_encode($splitConfig));
        }

        $this->writeLog("Payload montado: " . json_encode($payload));
        return $payload;
    }

    /**
     * Cria uma cobrança Pix única.
     * Retorna ['sucesso' => true, 'dados' => [...]] com qr_code, copy_paste, id, etc.
     */
    public function criarCobranca(array $payload): array {
        $result = $this->sendRequest('POST', '/pix/cashIn', $payload);
        if (!($result['sucesso'] ?? false)) {
            $this->writeLog("criarCobranca FALHOU | erro=" . json_encode($result['erro'] ?? '') . " detalhes=" . json_encode($result['detalhes'] ?? null));
        } else {
            $this->writeLog("criarCobranca OK | id=" . ($result['dados']['id'] ?? $result['dados']['uuid'] ?? 'n/a'));
        }
        return $result;
    }

    /**
     * Consulta o status de uma cobrança pelo ID retornado na criação.
     */
    public function consultarCobranca(string $id): array {
        return $this->sendRequest('GET', '/transactions/' . urlencode($id));
    }

    /**
     * Na PushinPay, o webhook é definido por cobrança via campo webhook_url.
     * Este método existe apenas para compatibilidade com a interface.
     */
    public function configurarWebhook(string $chave, string $urlWebhook): array {
        return ['sucesso' => true, 'info' => 'PushinPay: webhook configurado por cobrança via campo webhook_url.'];
    }

    // ── PIX Recorrente ────────────────────────────────────────────────────────

    /**
     * Cria uma assinatura PIX Recorrente.
     * POST /pix/cashIn/subscription
     *
     * $payload deve conter:
     *   - value        (int)    Valor em centavos (mínimo 50)
     *   - frequency    (int)    2=mensal | 3=semestral | 4=anual
     *   - name         (string) Nome da assinatura
     *   - webhook_url  (string) URL para receber notificações
     *   - customer     (array)  Opcional: dados do pagador
     *   - split_rules  (array)  Opcional: regras de split
     */
    public function criarAssinatura(array $payload): array {
        if (self::DEBUG_ASSINATURA) {
            $this->writeLog('DEBUG assinatura REQUEST  POST /pix/cashIn/subscription | payload=' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        $result = $this->sendRequest('POST', '/pix/cashIn/subscription', $payload);

        if (self::DEBUG_ASSINATURA) {
            $http = $result['codigo_http'] ?? '?';
            $resumo = [
                'sucesso'   => $result['sucesso'] ?? false,
                'http'      => $http,
                'dados'     => $result['dados'] ?? null,
                'erro'      => $result['erro'] ?? null,
                'detalhes'  => $result['detalhes'] ?? null,
            ];
            if (!($result['sucesso'] ?? false) && !empty($result['corpo_bruto'])) {
                $resumo['corpo_bruto'] = $result['corpo_bruto'];
            }
            $this->writeLog('DEBUG assinatura RESPONSE | ' . json_encode($resumo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        if (!($result['sucesso'] ?? false)) {
            $this->writeLog("criarAssinatura FALHOU | erro=" . json_encode($result['erro'] ?? '') . " detalhes=" . json_encode($result['detalhes'] ?? null));
        } else {
            $this->writeLog("criarAssinatura OK | subscription_id=" . ($result['dados']['subscription_id'] ?? 'n/a') . " id=" . ($result['dados']['id'] ?? 'n/a'));
        }
        unset($result['corpo_bruto']);
        return $result;
    }

    /**
     * Cancela uma assinatura PIX Recorrente.
     * GET /pix/cashIn/subscription/{id}/cancel
     */
    public function cancelarAssinatura(string $subscriptionId): array {
        return $this->sendRequest('GET', '/pix/cashIn/subscription/' . urlencode($subscriptionId) . '/cancel');
    }

    /**
     * Consulta uma assinatura PIX Recorrente.
     * GET /pix/cashIn/subscription (filtra pelo id via lista)
     */
    public function consultarAssinatura(string $subscriptionId): array {
        return $this->sendRequest('GET', '/pix/cashIn/subscription/' . urlencode($subscriptionId));
    }

    /**
     * Monta payload para criação de assinatura recorrente.
     */
    public function montaPayloadAssinatura(float $valor, string $frequencia, string $nome, ?array $splitConfig = null, ?string $webhookUrl = null, ?array $customerData = null): array {
        // A API deste endpoint espera frequency numérico.
        $freqMap = [
            'MONTHLY'      => 2,
            'SEMIANNUALLY' => 3,
            'ANNUALLY'     => 4,
            'YEARLY'       => 4,
        ];
        $frequenciaNormalizada = $freqMap[strtoupper($frequencia)] ?? 2;
        $payload = [
            'value'                      => (int) round($valor * 100),
            'frequency'                  => $frequenciaNormalizada,
            'name'                       => $nome,
            'pix_recurring_retry_policy' => 2,
        ];

        if ($webhookUrl) {
            $payload['webhook_url'] = $webhookUrl;
        }

        // DEBUG: Log do customerData recebido
        $this->writeLog("DEBUG: customerData = " . json_encode($customerData));

        // Adiciona dados do cliente (obrigatório pela PushinPay)
        if ($customerData && !empty($customerData['name'])) {
            $payload['customer'] = [
                'name' => $customerData['name'],
            ];
            $this->writeLog("Customer adicionado: " . json_encode($payload['customer']));
            // Adiciona documento se disponível
            if (!empty($customerData['document_type']) && !empty($customerData['document_number'])) {
                $payload['customer']['document'] = [
                    'type'   => $customerData['document_type'], // 'CPF' ou 'CNPJ'
                    'number' => $customerData['document_number'],
                ];
            }
        } else {
            $this->writeLog("AVISO: Customer NÃO adicionado (customerData=" . json_encode($customerData) . ")");
        }

        if ($splitConfig) {
            $splits = isset($splitConfig['chave']) ? [$splitConfig] : array_values($splitConfig);
            $splitRules = [];
            foreach ($splits as $s) {
                if (empty($s['chave']) || !isset($s['valor']) || $s['valor'] === '') continue;
                $tipo = $s['tipo'] ?? 'percentual';
                if ($tipo === 'fixo') {
                    $splitValue = (int) round((float)$s['valor'] * 100);
                } else {
                    $splitValue = (int) round((float)$s['valor'] / 100 * $valor * 100);
                }
                $splitRules[] = ['account_id' => $s['chave'], 'type' => 'fixed', 'value' => $splitValue];
            }
            if (!empty($splitRules)) {
                $payload['split_rules'] = $splitRules;
            }
        }

        return $payload;
    }
}
