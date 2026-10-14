<?php
// Aplica o simulador na CÓPIA do painel (/tmp/pt/app). Nada do painel real é tocado.
$app = '/tmp/pt/app';
function patch(string $arq, callable $fn): void {
    global $app;
    $c = file_get_contents("$app/$arq");
    $n = $fn($c);
    if ($n === $c) { echo "SEM MUDANCA: $arq\n"; exit(1); }
    file_put_contents("$app/$arq", $n);
    echo "ok: $arq\n";
}
$injeta = static function (string $nome) {
    return static function (string $c) use ($nome): string {
        return preg_replace('/(function ' . $nome . '\s*\([^)]*\)\s*:\s*array\s*\{)/', '$1 return simTelegram(...func_get_args());', $c, 1);
    };
};
patch('funcoes/fluxo_blocos.php', $injeta('requisicaoTelegram'));
patch('api.php', $injeta('requisicaoTelegram'));
patch('cron/cron_verificar_pix.php', $injeta('requisicaoTelegramLocal'));
patch('cron/cron_verificar_acessos.php', $injeta('telegramRequest'));
patch('cron/cron_renovacao.php', $injeta('telegramRequest'));
patch('cron/cron_aviso_vencimento.php', $injeta('telegramRequestAviso'));
patch('webhook_omegapayments.php', $injeta('requisicaoTelegramOmegapayments'));
patch('webhook_omegapayments.php', fn($c) => str_replace("file_get_contents('php://input')", "(\$GLOBALS['SIM_INPUT'] ?? '')", $c));
patch('webhook.php', fn($c) => str_replace("file_get_contents('php://input')", "(\$GLOBALS['SIM_INPUT'] ?? '')", $c));
patch('funcoes/omegapayments_banco.php', fn($c) => preg_replace('/(private function sendRequest\([^)]*\)\s*:\s*array\s*\{)/', '$1 return simOmegaApi($method, $uri, $body);', $c, 1));
patch('cron/cron_verificar_pix.php', fn($c) => str_replace("((int)date('i')) % 5 !== 0", 'false', $c));
echo "PATCH COMPLETO\n";
// Instrumentação: registra eventos de rastreio e webhooks do usuário no sim.log (a lógica real continua rodando)
patch('funcoes/traqueamento.php', fn($c) => preg_replace('/(function enviarEventosTraqueamento\([^)]*\)\s*\{)/', '$1 sim_log(["track" => $evento, "user" => $id_usuario, "dados" => $dados, "user_data" => $user_data]);', $c, 1));
patch('funcoes/webhooks.php', fn($c) => preg_replace('/(function dispararWebhooks\([^)]*\)\s*:\s*void\s*\{)/', '$1 sim_log(["whk" => $evento, "ctx" => $contexto]);', $c, 1));
echo "INSTRUMENTACAO OK\n";

// ISOLAMENTO: as crons da cópia só enxergam o bot de teste (nunca processam, com Telegram simulado, dado de outro usuário).
$ids = json_decode((string) @file_get_contents('/tmp/pt/ids.json'), true);
$B = (int) ($ids['bot'] ?? 0);
if ($B <= 0) { echo "SEM bot de teste em ids.json (rode seed.php antes do patch)\n"; exit(1); }
$restringe = static function (array $pares) {
    return static function (string $c) use ($pares): string {
        $c = str_replace(chr(13) . chr(10), chr(10), $c);
        $c = str_replace("
", "
", $c);
        foreach ($pares as [$de, $para, $vezes]) {
            $n = substr_count($c, $de);
            if ($n !== $vezes) { echo "ISOLAMENTO: esperava $vezes de [$de], achei $n\n"; exit(1); }
            $c = str_replace($de, $para, $c);
        }
        return $c;
    };
};
patch('cron/cron_verificar_pix.php', $restringe([
    ["v.status = 'gerado'", "v.status = 'gerado' AND v.bot_id = $B", 2],
    ["WHERE status = 'gerado' AND", "WHERE status = 'gerado' AND bot_id = $B AND", 2],
]));
patch('cron/cron_verificar_acessos.php', $restringe([["WHERE m.data_expiracao < ? AND m.status = 'ativo'", "WHERE m.data_expiracao < ? AND m.status = 'ativo' AND m.bot_id = $B", 1]]));
patch('cron/cron_renovacao.php', $restringe([["WHERE m.status = 'ativo'\n          AND m.data_expiracao <= ?", "WHERE m.status = 'ativo' AND m.bot_id = $B\n          AND m.data_expiracao <= ?", 1]]));
patch('cron/cron_aviso_vencimento.php', $restringe([
    ["m.status = 'ativo'\n            AND m.data_expiracao > ?", "m.status = 'ativo' AND m.bot_id = $B\n            AND m.data_expiracao > ?", 1],
    ["processarFilaEmails(\$pdo)", "[0, 0]", 1],
]));
patch('funcoes/previas.php', $restringe([["WHERE m.apagada = 0 AND m.apagar_em <= NOW()", "WHERE m.apagada = 0 AND m.bot_id = $B AND m.apagar_em <= NOW()", 1]]));
patch('funcoes/execucao_fluxo.php', $restringe([["WHERE c.status = 'pendente' AND c.executar_em <= NOW()", "WHERE c.status = 'pendente' AND c.bot_id = $B AND c.executar_em <= NOW()", 1]]));
patch('funcoes/assinatura.php', $restringe([["WHERE m.status = 'ativo' AND m.data_expiracao > NOW()", "WHERE m.status = 'ativo' AND m.bot_id = $B AND m.data_expiracao > NOW()", 1]]));
echo "ISOLAMENTO OK (bot $B)\n";
patch('api.php', fn($c) => str_replace("file_get_contents('php://input')", "(\$GLOBALS['SIM_INPUT'] ?? '')", $c));
echo "API OK\n";

// Facebook/UTMify: só o HTTP é simulado (curl_exec e o código HTTP); o resto da função roda de verdade.
patch('funcoes/facebook.php', fn($c) => str_replace(['$resposta = curl_exec($ch);', '$codigo_http = curl_getinfo($ch, CURLINFO_HTTP_CODE);'], ["\$resposta = simEventoHttp('facebook', \$url, \$payload);", "\$codigo_http = \$GLOBALS['SIM_HTTP_EVENTO'] ?? 0;"], $c));
patch('funcoes/utmfy.php', fn($c) => str_replace(['$resposta = curl_exec($ch);', '$codigo_http = curl_getinfo($ch, CURLINFO_HTTP_CODE);'], ["\$resposta = simEventoHttp('utmfy', \$url, \$payload, \$headers);", "\$codigo_http = \$GLOBALS['SIM_HTTP_EVENTO'] ?? 0;"], $c));
echo "EVENTOS HTTP OK\n";
