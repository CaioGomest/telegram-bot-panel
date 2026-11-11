<?php
declare(strict_types=1);

/**
 * Camada HTTP comum dos gateways (NxGate, WiinPay...). Nenhum gateway abre cURL por conta própria: tudo passa por gatewayRequisicaoHttp() (a única função
 * que fala com a rede — o teste de integração a troca por um simulador) e por gatewayChamadaJson() (monta o JSON, interpreta a resposta, padroniza o
 * retorno e escreve o log sem segredo).
 */

/** Requisição HTTP crua. @return array{codigo_http: int, corpo: string, erro_curl: string} */
function gatewayRequisicaoHttp(string $metodo, string $url, ?string $corpo, array $cabecalhos, int $timeout = 15): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_CUSTOMREQUEST  => $metodo,
        CURLOPT_HTTPHEADER     => $cabecalhos,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => $timeout,
    ]);
    if ($corpo !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $corpo);
    }
    $resposta = curl_exec($ch);
    return ['codigo_http' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE), 'corpo' => is_string($resposta) ? $resposta : '', 'erro_curl' => curl_error($ch)];
}

function gatewayLog(string $gateway, string $msg): void {
    @file_put_contents(dirname(__DIR__, 2) . '/logs/' . $gateway . '_debug.log', '[' . date('Y-m-d H:i:s') . "] [$gateway] $msg" . PHP_EOL, FILE_APPEND | LOCK_EX);
}

/** Texto de erro de uma resposta de gateway (cada um usa uma chave: error, message, erro, mensagem, detail). */
function gatewayMensagemErro(array $dados, string $padrao = 'Erro na requisição'): string {
    foreach (['error', 'message', 'erro', 'mensagem', 'detail', 'msg'] as $chave) {
        if (isset($dados[$chave]) && $dados[$chave] !== '' && $dados[$chave] !== null) {
            return mb_substr(is_scalar($dados[$chave]) ? (string) $dados[$chave] : json_encode($dados[$chave], JSON_UNESCAPED_UNICODE), 0, 300);
        }
    }
    return $padrao;
}

/**
 * Chamada JSON a um gateway. O corpo da requisição NUNCA vai para o log (alguns gateways levam a chave de API no corpo).
 * @return array{sucesso: bool, dados?: array, erro?: string, codigo_http: int}
 */
function gatewayChamadaJson(string $gateway, string $metodo, string $url, ?array $corpo = null, array $cabecalhos = [], int $timeout = 15): array {
    $json = $corpo === null ? null : json_encode($corpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $bruto = gatewayRequisicaoHttp($metodo, $url, $json, array_merge(['Accept: application/json', 'Content-Type: application/json'], $cabecalhos), $timeout);

    if ($bruto['erro_curl'] !== '') {
        gatewayLog($gateway, "cURL ($metodo " . strtok($url, '?') . '): ' . $bruto['erro_curl']);
        return ['sucesso' => false, 'erro' => 'Erro de conexão: ' . $bruto['erro_curl'], 'codigo_http' => 0];
    }

    $dados = json_decode($bruto['corpo'], true);
    if (!is_array($dados)) {
        $dados = ['raw' => mb_substr($bruto['corpo'], 0, 300)];
    }
    $codigo = $bruto['codigo_http'];
    // Alguns gateways respondem HTTP 200 com {"status":"error"} ou {"success":false}: isso também é falha.
    $recusou = (($dados['status'] ?? '') === 'error') || (($dados['success'] ?? true) === false);
    if ($codigo >= 200 && $codigo < 300 && !$recusou) {
        return ['sucesso' => true, 'dados' => $dados, 'codigo_http' => $codigo];
    }

    $erro = gatewayMensagemErro($dados, "HTTP $codigo");
    gatewayLog($gateway, "Requisição falhou | $metodo " . strtok($url, '?') . " | HTTP $codigo | $erro");
    return ['sucesso' => false, 'erro' => $erro, 'detalhes' => $dados, 'codigo_http' => $codigo];
}

/**
 * URL fixa do webhook de um gateway (webhook_<gateway>.php na raiz do painel). Usa a "URL do painel" configurada (Admin → Configurações), que vale também
 * para cobranças criadas por cron (sem HTTP_HOST); sem ela, deriva da requisição.
 */
function gatewayUrlWebhook(string $arquivo_webhook): string {
    try {
        require_once __DIR__ . '/../configuracoes.php';
        $configurada = rtrim(trim(configSistema('url_base')), '/');
        if ($configurada !== '' && preg_match('#^https?://[^\s/]+(/[^\s]*)?$#i', $configurada)) {
            return $configurada . '/' . $arquivo_webhook;
        }
    } catch (\Throwable $e) {
        // sem banco/configuração: cai na URL derivada da requisição, abaixo
    }
    $esquema = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $caminho_base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
    return $esquema . '://' . $host . $caminho_base . '/' . $arquivo_webhook;
}
