<?php
declare(strict_types=1);

require_once __DIR__ . '/../conexao.php';

date_default_timezone_set('America/Sao_Paulo');
$log_dir = __DIR__ . '/../logs';
if (!is_dir($log_dir)) mkdir($log_dir, 0755, true);
$log_file = $log_dir . '/cron_metricas_admin.log';

function logCronMetricas(string $msg): void {
    global $log_file;
    file_put_contents($log_file, '[' . date('Y-m-d H:i:s') . "] $msg" . PHP_EOL, FILE_APPEND);
}

// Só libera via CLI (crontab chamando "php cron_metricas_admin.php" direto) ou HTTP com a
// chave certa (?chave=...) — ver anotacoes/varredura-10-cron-ranking-segredos-formulario.md.
if (php_sapi_name() !== 'cli') {
    $chave_informada = (string) ($_GET['chave'] ?? '');
    if (!hash_equals(CHAVE_SECRETA_CRON, $chave_informada)) {
        logCronMetricas('Acesso HTTP negado (chave ausente ou incorreta).');
        http_response_code(403);
        exit('Acesso negado.');
    }
}

// Trava contra execução concorrente — mesmo padrão de cron_verificar_pix.php/cron_remarketing.php.
$lock_file = sys_get_temp_dir() . '/cron_metricas_admin.lock';
$lock = fopen($lock_file, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    logCronMetricas('Execução anterior ainda em andamento. Encerrando essa chamada.');
    exit;
}

try {
    // Só recalcula as últimas 48h a cada execução — vendas pagas nunca mudam de valor depois de
    // confirmadas (sem fluxo de estorno neste projeto), então o histórico mais antigo já preenchido
    // por admin/atualiza_banco.php nunca precisa ser revisitado. Isso mantém o cron rápido pra sempre,
    // independente de quantos milhões de linhas 'vendas' acumular (ver anotacoes/analise-potencia-e-escala.md).
    $stmt = $pdo->prepare("
        INSERT INTO metricas_horarias_admin (data, hora, faturamento, comissao, quantidade, atualizado_em)
        SELECT DATE(v.criado_em), HOUR(v.criado_em), SUM(v.valor), SUM(v.comissao_admin), COUNT(*), NOW()
        FROM vendas v
        WHERE v.status = 'pago' AND v.criado_em >= (CURDATE() - INTERVAL 1 DAY)
        GROUP BY DATE(v.criado_em), HOUR(v.criado_em)
        ON DUPLICATE KEY UPDATE
            faturamento = VALUES(faturamento),
            comissao = VALUES(comissao),
            quantidade = VALUES(quantidade),
            atualizado_em = VALUES(atualizado_em)
    ");
    $stmt->execute();
    logCronMetricas("Métricas horárias recalculadas (últimas 48h). Linhas afetadas: {$stmt->rowCount()}.");
} catch (Throwable $e) {
    logCronMetricas('Erro: ' . $e->getMessage());
}

flock($lock, LOCK_UN);
fclose($lock);
