<?php
declare(strict_types=1);

/**
 * Contrato que todo gateway de pagamento implementa. Quem consome (fluxo_blocos.php, crons, webhooks) só conhece estes métodos;
 * para ligar um gateway novo: criar a classe implementando isto + um arquivo <nome>.meta.php nesta pasta (ver _registro.php).
 *
 * Retornos seguem o mesmo formato de todos os gateways: ['sucesso' => bool, 'dados' => [...], 'erro' => string, 'codigo_http' => int].
 * criarCobranca/criarAssinatura devolvem em dados: pixCopiaECola e txid (e subscriptionId na assinatura); consultarCobranca devolve dados.status.
 */
interface ProvedorGateway {
    public function montaPayloadCobranca(
        float $valor,
        string $chave_pix,
        ?array $split_config = null,
        int $expiracao_segundos = 3600,
        string $nome_cliente = 'Cliente Telegram',
        string $documento_cliente = ''
    ): array;

    public function criarCobranca(array $payload): array;

    public function montaPayloadAssinatura(
        float $valor,
        string $periodicidade,
        string $id_produto,
        string $nome_produto,
        string $nome_cliente = 'Cliente Telegram',
        string $documento_cliente = '',
        ?array $split_config = null
    ): array;

    public function criarAssinatura(array $payload): array;

    public function consultarCobranca(string $txid): array;
}
