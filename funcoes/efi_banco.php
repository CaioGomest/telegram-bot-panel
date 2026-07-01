<?php
declare(strict_types=1);

class EfiBanco {
    private string $clientId;
    private string $clientSecret;
    private string $certificadoPath;
    private bool $producao;
    private string $baseUrl;
    private ?string $accessToken = null;
    private string $certPassword;

    public function __construct(string $clientId, string $clientSecret, string $certificadoPath, bool $producao = true, string $certPassword = '') {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->certificadoPath = $certificadoPath;
        $this->producao = $producao;
        $this->certPassword = $certPassword;
        $this->baseUrl = $producao ? 'https://pix.api.efipay.com.br' : 'https://pix-h.api.efipay.com.br';
    }

    private function getAuthHeader(): string {
        return 'Authorization: Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret);
    }

    /**
     * Configura o cURL exatamente como no teste que funcionou
     */
    private function configurarCurl($ch, string $url, array $headers, ?string $body = null): void {
        // Opções base idênticas ao efiBuildCurl do teste
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        // Certificado
        $certReal = realpath($this->certificadoPath);
        if ($certReal && file_exists($certReal)) {
            $options[CURLOPT_SSLCERT] = $certReal;
            
            // Detecção de tipo idêntica ao teste (se .p12 usa P12, senão PEM)
            $ext = strtolower(pathinfo($certReal, PATHINFO_EXTENSION));
            $options[CURLOPT_SSLCERTTYPE] = ($ext === 'p12') ? 'P12' : 'PEM';
            
            // Senha do certificado
            if (!empty($this->certPassword)) {
                $options[CURLOPT_SSLCERTPASSWD] = $this->certPassword;
            }
        }

        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($ch, $options);
    }

    /**
     * Autentica na API e obtém o token de acesso
     */
    public function autenticar(): bool {
        // Correção de caminho para evitar problemas no Windows/XAMPP
        $certReal = realpath($this->certificadoPath);
        if (!$certReal || !file_exists($certReal)) {
             $_SESSION['efi_debug_error'] = "Arquivo de certificado não encontrado pelo realpath: " . $this->certificadoPath;
             return false;
        }

        $endpoint = $this->baseUrl . '/oauth/token';
        $headers = [
            $this->getAuthHeader(),
            'Content-Type: application/json'
        ];
        $body = json_encode(['grant_type' => 'client_credentials']);

        $ch = curl_init();
        $this->configurarCurl($ch, $endpoint, $headers, $body);
        curl_setopt($ch, CURLOPT_POST, true); // Forçar POST para autenticação

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch); 
        $curlErrno = curl_errno($ch); 
        curl_close($ch);

        if ($curlError) {
             $errorMsg = "EfiBanco Auth Error ($curlErrno): $curlError | Cert: $certReal";
             $_SESSION['efi_debug_error'] = $errorMsg; 
             error_log($errorMsg);
             return false; 
        }

        if ($httpCode === 200) {
            $data = json_decode($response, true);
            if (isset($data['access_token'])) {
                $this->accessToken = $data['access_token'];
                return true;
            }
        } else {
             $errorMsg = "EfiBanco Auth HTTP Error ($httpCode): $response";
             $_SESSION['efi_debug_error'] = $errorMsg; 
             error_log($errorMsg);
        }

        return false;
    }

    /**
     * Cria uma Location para Recorrência Pix Automático (POST /v2/locrec)
     */
    public function criarLocationRecorrencia(): array {
        return $this->sendRequest('POST', '/v2/locrec', ['tipoCob' => 'rec']);
    }

    /**
     * Cria uma Recorrência Pix Automático (POST /v2/rec)
     *
     * Payload esperado:
     * {
     *   "vinculo": { "objeto": "...", "devedor": { "cpf": "...", "nome": "..." } },
     *   "calendario": { "dataInicial": "YYYY-MM-DD", "periodicidade": "MENSAL" },
     *   "valor": { "valorRec": "35.00" },
     *   "politicaRetentativa": "NAO_PERMITE",
     *   "loc": { "id": 123 }
     * }
     */
    public function criarRecorrencia(array $payload): array {
        return $this->sendRequest('POST', '/v2/rec', $payload);
    }

    /**
     * Consulta Recorrência Pix Automático (GET /v2/rec/:idRec)
     * Retorna dados da recorrência incluindo cobranças associadas.
     */
    public function consultarRecorrencia(string $idRec): array {
        return $this->sendRequest('GET', "/v2/rec/{$idRec}");
    }

    /**
     * Cancela uma Recorrência Pix Automático (PATCH /v2/rec/:idRec)
     * Encerra todos os débitos futuros desta recorrência.
     */
    public function cancelarRecorrencia(string $idRec): array {
        return $this->sendRequest('PATCH', "/v2/rec/{$idRec}", ['status' => 'CANCELADA']);
    }

    /**
     * Busca o QR Code / Pix Copia e Cola de uma Location (GET /v2/loc/:id/qrcode)
     * Usado para obter o QR da primeira cobrança após criar a recorrência.
     */
    public function obterQrCodeLoc(int $locId): array {
        return $this->sendRequest('GET', "/v2/loc/{$locId}/qrcode");
    }

    /**
     * Monta o payload completo para criar uma recorrência EFI.
     */
    public function montaPayloadRecorrencia(float $valor, int $locId, string $periodicidade, string $nomeCliente, string $cpfCliente, string $objeto = 'Assinatura'): array {
        $enumPeriodicidade = 'MENSAL';
        $mapa = ['semanal' => 'SEMANAL', 'mensal' => 'MENSAL', 'trimestral' => 'TRIMESTRAL', 'semestral' => 'SEMESTRAL', 'anual' => 'ANUAL'];
        if (isset($mapa[strtolower($periodicidade)])) {
            $enumPeriodicidade = $mapa[strtolower($periodicidade)];
        }

        // CPF: apenas dígitos, 11 caracteres
        $cpfLimpo = preg_replace('/\D/', '', $cpfCliente);

        return [
            'vinculo' => [
                'objeto' => substr($objeto, 0, 140),
                'devedor' => [
                    'cpf'  => $cpfLimpo ?: '00000000000',
                    'nome' => substr($nomeCliente ?: 'Cliente', 0, 200),
                ],
            ],
            'calendario' => [
                'dataInicial'   => date('Y-m-d'),
                'periodicidade' => $enumPeriodicidade,
            ],
            'valor' => [
                'valorRec' => number_format($valor, 2, '.', ''),
            ],
            'politicaRetentativa' => 'NAO_PERMITE',
            'loc' => ['id' => $locId],
        ];
    }
    private function writeLog(string $msg): void {
        $logFile = __DIR__ . '/../logs/split_debug.log';
        $line = '[' . date('Y-m-d H:i:s') . '] [EFI] ' . $msg . PHP_EOL;
        file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    }

    public function montaPayloadCobranca(float $valor, string $chavePix, ?array $splitConfig = null, int $expiracaoSegundos = 3600): array {
        $payload = [
            'calendario' => [
                'expiracao' => $expiracaoSegundos
            ],
            'valor' => [
                'original' => number_format($valor, 2, '.', '')
            ],
            'chave' => $chavePix
        ];

        // Passa o splitConfig para criarCobranca detectar e usar o endpoint correto
        // Suporta split único ['chave'=>...] ou array de splits [['chave'=>...], ...]
        $splits = $splitConfig ? (isset($splitConfig['chave']) ? [$splitConfig] : array_values($splitConfig)) : [];
        $splitsValidos = array_filter($splits, fn($s) => !empty($s['chave']) && !empty($s['valor']));
        if (!empty($splitsValidos)) {
            $payload['_splitConfig'] = $splitConfig;
            $this->writeLog("Split será aplicado via /v2/gn/split/cob | splitConfig=" . json_encode($splitConfig));
        } else {
            $this->writeLog("Cobrança sem split | valor={$valor} | chave={$chavePix}");
        }

        return $payload;
    }

    /**
     * Método genérico para requisições na API Pix
     */
    private function sendRequest(string $method, string $uri, ?array $body = null): array {
        if (!$this->accessToken) {
            if (!$this->autenticar()) {
                return ['erro' => 'Falha na autenticação'];
            }
        }

        $endpoint = $this->baseUrl . $uri;
        $headers = [
            'Authorization: Bearer ' . $this->accessToken,
            'Content-Type: application/json'
        ];

        $ch = curl_init();
        
        // Prepara body se houver
        $bodyStr = $body ? json_encode($body) : null;
        
        // Usa a mesma configuração centralizada
        $this->configurarCurl($ch, $endpoint, $headers, $bodyStr);
        
        // Método específico
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);

        if ($httpCode >= 200 && $httpCode < 300) {
            return ['sucesso' => true, 'dados' => $data];
        }

        $this->writeLog("Requisição falhou | method={$method} uri={$uri} http={$httpCode} resposta=" . json_encode($data));
        return ['sucesso' => false, 'erro' => $data['mensagem'] ?? 'Erro na requisição', 'detalhes' => $data, 'codigo_http' => $httpCode];
    }

    /**
     * Cria uma cobrança imediata (Cob), com split se configurado.
     * Se o payload contiver '_splitConfig', usa o endpoint /v2/gn/split/cob/{txid}.
     */
    public function criarCobranca(array $payload): array {
        $splitConfig = $payload['_splitConfig'] ?? null;
        unset($payload['_splitConfig']);

        if ($splitConfig) {
            return $this->criarCobrancaComSplit($payload, $splitConfig);
        }
        return $this->sendRequest('POST', '/v2/cob', $payload);
    }

    /**
     * Cria cobrança com split EFI — fluxo de 3 passos:
     * 1. POST /v2/gn/split/config  → cria a configuração de split
     * 2. PUT  /v2/cob/{txid}       → cria a cobrança normal
     * 3. PUT  /v2/gn/split/cob/{txid}/vinculo/{splitConfigId} → vincula os dois
     *
     * Requer escopo gn.split.write na API key.
     * Split só funciona entre contas EFI — favorecido.conta deve ser o nº de conta EFI.
     */
    private function criarCobrancaComSplit(array $chargePayload, array $splitConfig): array {
        // Normaliza para array de splits
        $splits = isset($splitConfig['chave']) ? [$splitConfig] : array_values($splitConfig);

        $repasses = [];
        $totalPercentual = 0.0;
        foreach ($splits as $s) {
            $tipo = $s['tipo'] ?? 'percentual';
            $valorSplit = number_format((float)$s['valor'], 2, '.', '');
            // EFI Pix Split exige favorecido.conta + cpf (não chave Pix).
            // chave_pix_split armazena JSON {"conta":"...","cpf":"..."} para EFI.
            $efiData = json_decode($s['chave'], true);
            $repasses[] = [
                'tipo'       => ($tipo === 'fixo') ? 'fixo' : 'porcentagem',
                'valor'      => $valorSplit,
                'favorecido' => [
                    'conta' => preg_replace('/\D/', '', $efiData['conta'] ?? $s['chave']),
                    'cpf'   => preg_replace('/\D/', '', $efiData['cpf'] ?? ''),
                ],
            ];
            if ($tipo !== 'fixo') {
                $totalPercentual += (float)$s['valor'];
            }
        }

        $splitBody = [
            'lancamento' => ['imediato' => true],
            'split' => [
                'divisaoTarifa' => 'assumir_total',
                'repasses' => $repasses,
            ]
        ];

        $temPercentual = !empty(array_filter($splits, fn($s) => ($s['tipo'] ?? 'percentual') !== 'fixo'));
        if ($temPercentual) {
            $minhaParte = number_format(100.0 - $totalPercentual, 2, '.', '');
            $splitBody['split']['minhaParte'] = ['tipo' => 'porcentagem', 'valor' => $minhaParte];
        }

        // Passo 1: cria config de split
        $this->writeLog("Passo 1 - Criando config split | body=" . json_encode($splitBody));
        $respConfig = $this->sendRequest('POST', '/v2/gn/split/config', $splitBody);

        if (!($respConfig['sucesso'] ?? false)) {
            $this->writeLog("Passo 1 FALHOU | " . json_encode($respConfig['detalhes'] ?? $respConfig['erro'] ?? ''));
            // Fallback: cobrança sem split
            return $this->sendRequest('PUT', '/v2/cob/' . bin2hex(random_bytes(17)), $chargePayload);
        }

        $splitConfigId = $respConfig['dados']['id'] ?? null;
        if (!$splitConfigId) {
            $this->writeLog("splitConfigId ausente | resposta=" . json_encode($respConfig['dados'] ?? []));
            return $this->sendRequest('PUT', '/v2/cob/' . bin2hex(random_bytes(17)), $chargePayload);
        }
        $this->writeLog("Passo 1 OK | splitConfigId={$splitConfigId}");

        // Passo 2: cria cobrança normal
        $txid = bin2hex(random_bytes(17)); // 34 chars hex, alphanumerico
        $this->writeLog("Passo 2 - Criando cobrança | txid={$txid}");
        $respCob = $this->sendRequest('PUT', "/v2/cob/{$txid}", $chargePayload);

        if (!($respCob['sucesso'] ?? false)) {
            $this->writeLog("Passo 2 FALHOU | " . json_encode($respCob['detalhes'] ?? $respCob['erro'] ?? ''));
            return $respCob;
        }
        $this->writeLog("Passo 2 OK | txid={$txid}");

        // Passo 3: vincula cobrança ao split
        $this->writeLog("Passo 3 - Vinculando | txid={$txid} splitConfigId={$splitConfigId}");
        $respVinculo = $this->sendRequest('PUT', "/v2/gn/split/cob/{$txid}/vinculo/{$splitConfigId}", null);

        if (!($respVinculo['sucesso'] ?? false)) {
            $this->writeLog("Passo 3 FALHOU (vínculo) | " . json_encode($respVinculo['detalhes'] ?? $respVinculo['erro'] ?? ''));
            // Cobrança foi criada — retorna mesmo sem split
        } else {
            $this->writeLog("Passo 3 OK — split vinculado com sucesso!");
        }

        return $respCob;
    }

    /**
     * Cria uma cobrança com vencimento (CobV) - Útil para recorrência manual
     */
    public function criarCobrancaVencimento(string $txid, array $payload): array {
        return $this->sendRequest('PUT', "/v2/cobv/{$txid}", $payload);
    }

    /**
     * Consulta uma cobrança pelo txid
     */
    public function consultarCobranca(string $txid): array {
        return $this->sendRequest('GET', "/v2/cob/{$txid}");
    }




    /**
     * Valida se as credenciais estão funcionando
     */
    public function validarCredenciais(): bool {
        return $this->autenticar();
    }

    /**
     * Configura o Webhook Pix
     */
    public function configurarWebhook(string $chave, string $urlWebhook): array {
        $body = ['webhookUrl' => $urlWebhook];
        // Envia o header x-skip-mtls-checking: true para forçar a Efí a ignorar a exigência do mTLS
        // Esse é o método oficial da Efí mais recente para ignorar mTLS no sandbox e em algumas contas de produção
        
        // Como o sendRequest usa o método base, precisamos adaptar ou fazer a requisição direto aqui
        $endpoint = $this->baseUrl . "/v2/webhook/{$chave}";
        $headers = [
            'Authorization: Bearer ' . $this->accessToken,
            'Content-Type: application/json',
            'x-skip-mtls-checking: true'
        ];

        $ch = curl_init();
        $this->configurarCurl($ch, $endpoint, $headers, json_encode($body));
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);

        if ($httpCode >= 200 && $httpCode < 300) {
            return ['sucesso' => true, 'dados' => $data];
        }

        return ['sucesso' => false, 'erro' => $data['mensagem'] ?? 'Erro na requisição', 'detalhes' => $data, 'codigo_http' => $httpCode];
    }
}