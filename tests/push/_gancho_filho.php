<?php
declare(strict_types=1);

/**
 * Processo-filho do teste de integração: chama aposPagamentoConfirmado() como o webhook/cron fazem, numa execução NOVA (a configuração
 * push_ativo é lida do banco do zero, sem o cache do processo do teste). Uso: php tests/push/_gancho_filho.php '<json da venda>'
 */

if (php_sapi_name() !== 'cli') {
    exit(2);
}
chdir(dirname(__DIR__, 2));
require_once dirname(__DIR__, 2) . '/conexao.php';
$venda = json_decode((string) ($argv[1] ?? '{}'), true) ?: [];
aposPagamentoConfirmado($venda);
echo "gancho concluido\n";
