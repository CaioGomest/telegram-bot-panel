<?php
declare(strict_types=1);

/**
 * Processo-filho do teste de latência do caminho B (sem trabalhador): faz o que uma requisição do site faz quando um Pix é gerado/pago.
 * Escreve o horário T0 (antes de enfileirar) na saída; ao terminar o script, o gancho de fim de requisição (register_shutdown_function) envia o aviso.
 * Uso: PUSH_MODO_TESTE=1 php tests/push/_evento_filho.php <id_usuario> <tipo 1|2> <id_venda>
 */

if (php_sapi_name() !== 'cli') {
    exit(2);
}
chdir(dirname(__DIR__, 2));
require_once dirname(__DIR__, 2) . '/conexao.php';
require_once dirname(__DIR__, 2) . '/funcoes/push.php';
$t0 = microtime(true);
enfileirarPush((int) ($argv[1] ?? 0), (int) ($argv[2] ?? 2), (int) ($argv[3] ?? 0));
echo sprintf('%.6f', $t0) . "\n";
