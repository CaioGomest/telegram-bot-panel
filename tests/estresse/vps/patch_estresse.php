<?php
// Aplica o simulador rápido na CÓPIA do painel usada no teste de carga (nunca no painel de verdade).
//   php patch_estresse.php /var/www/painel-estresse
// Só troca as funções que falam com a internet (Telegram, OmegaPayments); toda a lógica do painel roda de verdade, no mesmo banco.
$app = rtrim($argv[1] ?? '/var/www/painel-estresse', '/');
if ($app === '/var/www/painel') { fwrite(STDERR, "recusado: este script nunca toca o painel real\n"); exit(2); }
function patch(string $arq, callable $fn): void
{
    global $app;
    $c = file_get_contents("$app/$arq");
    $n = $fn($c);
    if ($n === $c) { echo "SEM MUDANCA: $arq\n"; exit(1); }
    file_put_contents("$app/$arq", $n);
    echo "ok: $arq\n";
}
$injeta = static function (string $nome) {
    return static function (string $c) use ($nome): string {
        return preg_replace('/(function ' . $nome . '\s*\([^)]*\)\s*:\s*array\s*\{)/', '$1 return simTelegramRapido(...func_get_args());', $c, 1);
    };
};
patch('funcoes/fluxo_blocos.php', $injeta('requisicaoTelegram'));
patch('api.php', $injeta('requisicaoTelegram'));
patch('cron/cron_verificar_pix.php', $injeta('requisicaoTelegramLocal'));
patch('cron/cron_verificar_acessos.php', $injeta('telegramRequest'));
patch('cron/cron_renovacao.php', $injeta('telegramRequest'));
patch('cron/cron_aviso_vencimento.php', $injeta('telegramRequestAviso'));
patch('webhook_omegapayments.php', $injeta('requisicaoTelegramOmegapayments'));
patch('funcoes/omegapayments_banco.php', fn($c) => preg_replace('/(private function sendRequest\([^)]*\)\s*:\s*array\s*\{)/', '$1 return simOmegaRapido($method, $uri, $body);', $c, 1));
echo "PATCH ESTRESSE COMPLETO ($app)\n";
