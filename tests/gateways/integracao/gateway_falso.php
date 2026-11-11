<?php
declare(strict_types=1);

/**
 * Gateway FALSO só para teste (rodar.js copia para a cópia do painel como funcoes/gateways/falso.php + falso.meta.php): o modo vem de
 * SIM_DIR/falso_modo.txt = ok | erro | excecao | sem_codigo. Serve para provar o fallback sem depender de nenhum gateway de verdade.
 */
class GatewayFalso implements ProvedorGateway {
    public function __construct(private string $chave) {}

    private function modo(): string {
        return trim((string) @file_get_contents(SIM_DIR . '/falso_modo.txt')) ?: 'ok';
    }

    public function montaPayloadCobranca(float $valor, string $chave_pix, ?array $split_config = null, int $expiracao_segundos = 3600, string $nome_cliente = 'Cliente Telegram', string $documento_cliente = ''): array {
        return ['valor' => $valor, 'split' => $split_config];
    }

    public function criarCobranca(array $payload): array {
        $modo = $this->modo();
        sim_log(['gw' => 'falso criar', 'modo' => $modo, 'valor' => $payload['valor']]);
        if ($modo === 'excecao') {
            throw new RuntimeException('falso: explodiu');
        }
        if ($modo === 'erro') {
            return ['sucesso' => false, 'erro' => 'falso: recusado', 'codigo_http' => 500];
        }
        $txid = 'FALSO' . sim_contador('falso');
        return ['sucesso' => true, 'codigo_http' => 200, 'dados' => ['txid' => $txid, 'pixCopiaECola' => $modo === 'sem_codigo' ? '' : '00020126FALSO' . $txid]];
    }

    public function montaPayloadAssinatura(float $valor, string $periodicidade, string $id_produto, string $nome_produto, string $nome_cliente = 'Cliente Telegram', string $documento_cliente = '', ?array $split_config = null): array {
        return ['valor' => $valor];
    }

    public function criarAssinatura(array $payload): array {
        return $this->criarCobranca($payload);
    }

    public function consultarCobranca(string $txid): array {
        $pagos = is_file(SIM_DIR . '/pagos.txt') ? file(SIM_DIR . '/pagos.txt', FILE_IGNORE_NEW_LINES) : [];
        return ['sucesso' => true, 'dados' => ['status' => in_array($txid, $pagos, true) ? 'PAID' : 'WAITING'], 'codigo_http' => 200];
    }
}
