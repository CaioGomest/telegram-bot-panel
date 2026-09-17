<?php
declare(strict_types=1);

require_once __DIR__ . '/../conexao.php';
require_once __DIR__ . '/../funcoes/infopago_split.php';

date_default_timezone_set('America/Sao_Paulo');
$log_dir = __DIR__ . '/../logs';
if (!is_dir($log_dir)) mkdir($log_dir, 0755, true);
$log_file = $log_dir . '/cron_retry_split.log';

function logRetrySplit(string $msg): void {
    global $log_file;
    file_put_contents($log_file, '[' . date('Y-m-d H:i:s') . "] $msg" . PHP_EOL, FILE_APPEND);
}

// Só libera via CLI (crontab chamando "php cron_retry_split.php" direto) ou HTTP com a
// chave certa (?chave=...) — mesmo padrão de cron_ranking.php/cron_metricas_admin.php.
if (php_sapi_name() !== 'cli') {
    $chave_informada = (string) ($_GET['chave'] ?? '');
    if (!hash_equals(CHAVE_SECRETA_CRON, $chave_informada)) {
        logRetrySplit('Acesso HTTP negado (chave ausente ou incorreta).');
        http_response_code(403);
        exit('Acesso negado.');
    }
}

// Trava contra execução concorrente — mesmo padrão de cron_verificar_pix.php/cron_remarketing.php.
$lock_file = sys_get_temp_dir() . '/cron_retry_split.lock';
$lock = fopen($lock_file, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    logRetrySplit('Execução anterior ainda em andamento. Encerrando essa chamada.');
    exit;
}

try {
    // Só reprocessa vendas pagas nos últimos 7 dias -- depois disso, assume que o problema é
    // permanente (ex. chave Pix de split configurada errada pelo dono do bot) e para de tentar
    // sozinho; fica visível pro admin no filtro "Falhou"/"Incompleto" de admin/transacoes.php
    // pra investigação manual. Sem esse limite de tempo, uma venda com destino de split
    // permanentemente inválido seria retentada pra sempre, consumindo chamada de API à toa
    // a cada execução do cron.
    //
    // dispararSplitInfopago() agora é idempotente por venda (pula destino que já foi pago
    // numa tentativa anterior) -- seguro chamar de novo mesmo em vendas 'parcial'.
    $stmt = $pdo->prepare("
        SELECT v.id, v.valor, v.transacao_id, b.id_usuario AS id_dono
        FROM vendas v
        JOIN bots b ON v.bot_id = b.id
        WHERE v.status = 'pago'
          AND v.split_status IN ('falhou', 'parcial', 'sem_credenciais')
          AND v.pago_em >= (NOW() - INTERVAL 7 DAY)
        ORDER BY v.pago_em ASC
        LIMIT 200
    ");
    $stmt->execute();
    $vendas_pendentes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$vendas_pendentes) {
        logRetrySplit('Nenhum split pendente de retentativa no momento.');
    } else {
        logRetrySplit('Encontradas ' . count($vendas_pendentes) . ' vendas com split pendente de retentativa.');
        foreach ($vendas_pendentes as $venda) {
            logRetrySplit("Retentando split da venda #{$venda['id']} (dono={$venda['id_dono']}, valor={$venda['valor']}).");
            dispararSplitInfopago((int) $venda['id_dono'], (float) $venda['valor'], (string) $venda['transacao_id'], (int) $venda['id']);
        }
        logRetrySplit('Retentativas concluídas.');
    }
} catch (Throwable $e) {
    logRetrySplit('Erro: ' . $e->getMessage());
}

flock($lock, LOCK_UN);
fclose($lock);
