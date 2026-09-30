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
 * URL base confirmada direto na doc pública (app.omegapayments.com.br/docs/v1, seção de
 * autenticação) em 28/09/2026. A documentação tem bot-detection agressivo (403) na maior
 * parte das páginas, então outros pontos ainda não confirmados continuam como palpite: o
 * path exato do endpoint de consulta de transação, e o schema completo de cada item do array
 * `splits[]`. Ver `anotacoes/HISTORICO-CONSOLIDADO.md` antes de considerar
 * isso 100% pronto pra produção.
 */
class OmegaPaymentsBanco {
    private string $client_id;
    private string $client_secret;
    private string $base_url;

    public function __construct(string $client_id, string $client_secret) {
        $this->client_id = $client_id;
        $this->client_secret = $client_secret;
        // Confirmado direto na doc (app.omegapayments.com.br/docs/v1, seção de autenticação):
        // "adicione a todas as suas requisições: https://app.omegapayments.com.br/api/v1".
        // O palpite anterior (api.omegapayments.com.br) nem resolvia no DNS.
        $this->base_url = 'https://app.omegapayments.com.br/api/v1';
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
        // A API bloqueia polling (HTTP 429 + retryAfterSeconds) e pede pra usar o webhook.
        // Enquanto o bloqueio vale, nem tenta: martelar só estende o bloqueio.
        $arquivo_bloqueio = __DIR__ . '/../storage/omegapayments_429_' . md5($this->client_id) . '.txt';
        if ($method === 'GET' && is_file($arquivo_bloqueio) && (int)@file_get_contents($arquivo_bloqueio) > time()) {
            return ['sucesso' => false, 'erro' => 'Consulta temporariamente bloqueada pela OmegaPayments (limite de requisições).', 'codigo_http' => 429];
        }

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

        if ($http_code === 429) {
            $espera = (int)($data['error']['retryAfterSeconds'] ?? 300);
            @file_put_contents($arquivo_bloqueio, (string)(time() + max(30, $espera)));
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
        // Nenhum ponto do fluxo hoje coleta CPF/CNPJ do comprador antes de gerar um Pix
        // avulso (documento_cliente sempre chega vazio). Confirmado via erro real da API,
        // em duas tentativas: mandar zeros dá "Documento inválido.", omitir o campo dá
        // "Required document." -- é obrigatório e precisa passar validação de formato.
        // Mesmo CNPJ fixo já usado como fallback pro Pix recorrente (CNPJ_PIX_RECORRENTE_FIXO,
        // webhook.php), aplicado aqui também pra manter consistência.
        $documento_limpo = preg_replace('/\D/', '', $documento_cliente) ?: '65915116000104';

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
            // 'id' obrigatório (confirmado via erro real da API: "invalid_type... path
            // products,0,id" quando ausente) -- não temos SKU de produto real aqui, então
            // geramos um id sintético só pra satisfazer o schema.
            'products'    => [
                [
                    'id'       => bin2hex(random_bytes(8)),
                    'name'     => 'Produto Digital',
                    'quantity' => 1,
                    'price'    => round($valor, 2),
                ],
            ],
            // Formato confirmado via erro real da API: mandar só "Y-m-d" fazia a API
            // recusar com "The due date must be greater than the current date" mesmo
            // pra uma data futura -- ela espera datetime ISO 8601 completo em UTC, não
            // só a data (erro típico de validação Zod, que por padrão exige
            // "YYYY-MM-DDTHH:mm:ss.sssZ").
            'dueDate'     => gmdate('Y-m-d\TH:i:s.000\Z', time() + max($expiracao_segundos, 60)),
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
     * Traduz a periodicidade usada no bloco Pix do editor de fluxo (mensal/trimestral/
     * semestral/anual/semanal) pro par periodicityType+periodicity que a API espera.
     * Confirmado 28/09 direto na doc do endpoint de assinatura: a API aceita WEEKS, então
     * a restrição de "semanal indisponível" que existia em webhook.php era desnecessária
     * -- removida junto com essa implementação.
     */
    private function mapeiaPeriodicidade(string $periodicidade): array {
        return match ($periodicidade) {
            'semanal'    => ['periodicityType' => 'WEEKS', 'periodicity' => 1],
            'trimestral' => ['periodicityType' => 'MONTHS', 'periodicity' => 3],
            'semestral'  => ['periodicityType' => 'MONTHS', 'periodicity' => 6],
            'anual'      => ['periodicityType' => 'YEARS', 'periodicity' => 1],
            default      => ['periodicityType' => 'MONTHS', 'periodicity' => 1], // mensal
        };
    }

    /**
     * Monta o payload de assinatura Pix recorrente (POST /gateway/pix/subscription).
     * Endpoint achado e confirmado em 28/09 (não estava nos itens já mapeados nas
     * pendências) -- diferente do Pix avulso, o campo client.document aqui é OPCIONAL
     * na doc, mas mantemos o mesmo CNPJ fixo de fallback por consistência (nenhum ponto
     * do fluxo coleta documento real do comprador, igual ao avulso).
     */
    public function montaPayloadAssinatura(
        float $valor,
        string $periodicidade,
        string $id_produto,
        string $nome_produto,
        string $nome_cliente = 'Cliente Telegram',
        string $documento_cliente = ''
    ): array {
        $documento_limpo = preg_replace('/\D/', '', $documento_cliente) ?: '65915116000104';
        $periodo = $this->mapeiaPeriodicidade($periodicidade);

        return [
            'identifier' => bin2hex(random_bytes(16)),
            'amount'     => round($valor, 2),
            'product'    => [
                'id'    => $id_produto,
                'name'  => substr($nome_produto !== '' ? $nome_produto : 'Produto', 0, 200),
                'price' => round($valor, 2),
            ],
            'subscription' => [
                'periodicityType' => $periodo['periodicityType'],
                'periodicity'     => $periodo['periodicity'],
            ],
            'client' => [
                'name'     => substr($nome_cliente !== '' ? $nome_cliente : 'Cliente Telegram', 0, 200),
                'email'    => "cliente+{$documento_limpo}@telegrambot.local",
                'phone'    => '11999999999',
                'document' => $documento_limpo,
            ],
            'callbackUrl' => $this->montaCallbackUrl(),
        ];
    }

    /**
     * Cria uma assinatura Pix recorrente (POST /gateway/pix/subscription). Igual ao Pix
     * avulso, a resposta já traz o pixCopiaECola da PRIMEIRA cobrança pronta (sem passo
     * extra) -- as cobranças seguintes do ciclo são geradas automaticamente pela
     * OmegaPayments, não por chamada nossa.
     */
    public function criarAssinatura(array $payload): array {
        $resp = $this->sendRequest('POST', '/gateway/pix/subscription', $payload);

        if (!($resp['sucesso'] ?? false)) {
            $this->writeLog("criarAssinatura FALHOU | identifier=" . ($payload['identifier'] ?? '') . " | erro=" . json_encode($resp['erro'] ?? ''));
            return $resp;
        }

        $resp['dados']['pixCopiaECola']  = $resp['dados']['pix']['code'] ?? '';
        $resp['dados']['txid']           = $resp['dados']['transactionId'] ?? ($payload['identifier'] ?? '');
        $resp['dados']['subscriptionId'] = $resp['dados']['subscription']['id'] ?? '';

        $this->writeLog("criarAssinatura OK | txid=" . $resp['dados']['txid'] . " | subscriptionId=" . $resp['dados']['subscriptionId']);
        return $resp;
    }

    /**
     * Consulta o status de uma cobrança já criada.
     * [CONFIRMADO 28/09] Achado na doc real ("Buscar transações", apiPath
     * "/gateway/transactions", method GET): não é rota por path (`/gateway/pix/{id}`,
     * palpite anterior, dava 404) -- é busca por query string em `/gateway/transactions`,
     * com o id da transação no parâmetro `id`. Enum de status confirmado na mesma doc:
     * PENDING, COMPLETED, FAILED, REFUNDED, CHARGED_BACK, EXPIRED.
     */
    public function consultarCobranca(string $txid): array {
        $resp = $this->sendRequest('GET', '/gateway/transactions?id=' . urlencode($txid));

        if ($resp['sucesso'] ?? false) {
            $resp['dados']['status'] = $resp['dados']['status'] ?? ($resp['dados']['statusCob'] ?? '');
        }

        return $resp;
    }
}
