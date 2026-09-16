<?php
declare(strict_types=1);

require_once __DIR__ . '/../conexao.php';

date_default_timezone_set('America/Sao_Paulo');
$log_dir = __DIR__ . '/../logs';
if (!is_dir($log_dir)) mkdir($log_dir, 0755, true);
$log_file = $log_dir . '/cron_ranking.log';

function logCronRanking(string $msg): void
{
    global $log_file;
    file_put_contents($log_file, '[' . date('Y-m-d H:i:s') . "] $msg" . PHP_EOL, FILE_APPEND);
}

// Só libera via CLI (crontab chamando "php cron_ranking.php" direto) ou HTTP com a chave
// certa (?chave=...) — sem isso, qualquer um que descubra a URL podia martelar esse script
// e derrubar o recálculo do ranking (ver anotacoes/varredura-10-cron-ranking-segredos-formulario.md).
if (php_sapi_name() !== 'cli') {
    $chave_informada = (string) ($_GET['chave'] ?? '');
    if (!hash_equals(CHAVE_SECRETA_CRON, $chave_informada)) {
        logCronRanking('Acesso HTTP negado (chave ausente ou incorreta).');
        http_response_code(403);
        exit('Acesso negado.');
    }
}

// Trava contra execução concorrente — sem isso, duas execuções simultâneas do DELETE+INSERT
// abaixo podem disputar lock de linha do InnoDB e deadlockar, descartando a atualização do
// ranking de uma campanha (mesmo padrão de cron_verificar_pix.php/cron_remarketing.php).
$lock_file = sys_get_temp_dir() . '/cron_ranking.lock';
$lock = fopen($lock_file, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    logCronRanking('Execução anterior ainda em andamento. Encerrando essa chamada.');
    exit;
}

try {
    $campanhas = $pdo->query("
        SELECT id, data_inicio, data_fim
        FROM campanhas_ranking
        WHERE ativa = 1 AND NOW() BETWEEN data_inicio AND data_fim
    ")->fetchAll(PDO::FETCH_ASSOC);

    if (!$campanhas) {
        logCronRanking('Nenhuma campanha ativa no momento.');
        flock($lock, LOCK_UN);
        fclose($lock);
        exit;
    }

    foreach ($campanhas as $campanha) {
        $campanha_id = (int) $campanha['id'];

        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE FROM ranking_cache WHERE campanha_id = ?")->execute([$campanha_id]);

            $stmt = $pdo->prepare("
                INSERT INTO ranking_cache (campanha_id, id_usuario, faturamento, posicao, atualizado_em)
                SELECT ?, b.id_usuario, SUM(v.valor),
                       RANK() OVER (ORDER BY SUM(v.valor) DESC, MIN(v.criado_em) ASC),
                       NOW()
                FROM vendas v
                JOIN bots b ON v.bot_id = b.id
                WHERE v.status = 'pago' AND v.criado_em BETWEEN ? AND ?
                GROUP BY b.id_usuario
            ");
            $stmt->execute([$campanha_id, $campanha['data_inicio'], $campanha['data_fim']]);

            $pdo->commit();
            logCronRanking("Campanha #$campanha_id: ranking recalculado ({$stmt->rowCount()} participantes).");
        } catch (Throwable $e) {
            $pdo->rollBack();
            logCronRanking("Campanha #$campanha_id: erro ao recalcular — " . $e->getMessage());
        }
    }
} catch (Throwable $e) {
    logCronRanking('Erro geral: ' . $e->getMessage());
}

flock($lock, LOCK_UN);
fclose($lock);
