<?php
declare(strict_types=1);

/**
 * Trava dos crons: só rodam pela linha de comando (crontab chamando "php cron/arquivo.php", que é como a VPS
 * executa) ou por HTTP com a chave certa (?chave=CHAVE_SECRETA_CRON, para hospedagens cujo agendador só sabe
 * chamar URL). Antes, 5 crons (Pix, remarketing, renovação, aviso de vencimento, verificar acessos) executavam
 * para QUALQUER visitante que soubesse o endereço -- dava pra disparar o processamento à vontade (pendência P1
 * da rodada de 07/10/2026). Mesmo padrão que o cron_ranking.php já usava.
 *
 * Chamada recusada fica registrada em logs/cron_acesso_negado.log (útil pra perceber um agendador configurado
 * por URL sem a chave, que pararia de rodar).
 */
function exigirChamadaDeCron(string $nome_cron): void
{
    // Suspensão remota em modo "total" (funcoes/controle.php): os crons que falam com leads/assinantes param. Seguem só os que
    // cumprem o que quem JÁ pagou comprou: confirmar Pix e controlar o acesso aos grupos.
    if (function_exists('controleModoTotal') && controleModoTotal() && !in_array($nome_cron, ['cron_verificar_pix', 'cron_verificar_acessos'], true)) {
        exit(0);
    }
    // Modo manutenção "total" (funcoes/manutencao.php): todos os crons pausam (migração do banco em andamento). Voltam sozinhos quando ela desliga.
    if (function_exists('manutencaoModoTotal') && manutencaoModoTotal()) {
        exit(0);
    }
    if (PHP_SAPI === 'cli') {
        return;
    }
    $chave = (string) ($_GET['chave'] ?? '');
    if (defined('CHAVE_SECRETA_CRON') && (string) CHAVE_SECRETA_CRON !== '' && hash_equals((string) CHAVE_SECRETA_CRON, $chave)) {
        return;
    }
    @file_put_contents(
        dirname(__DIR__) . '/logs/cron_acesso_negado.log',
        '[' . date('Y-m-d H:i:s') . "] $nome_cron: chamada HTTP sem a chave recusada (IP " . ($_SERVER['REMOTE_ADDR'] ?? '?')
            . ' | ' . substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'sem user-agent'), 0, 80) . ")\n",
        FILE_APPEND
    );
    http_response_code(403);
    exit('Acesso negado.');
}
