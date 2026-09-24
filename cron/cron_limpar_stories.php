<?php
declare(strict_types=1);

require_once __DIR__ . '/../conexao.php';
require_once __DIR__ . '/../funcoes/stories.php';

date_default_timezone_set('America/Sao_Paulo');
$log_dir = __DIR__ . '/../logs';
if (!is_dir($log_dir)) mkdir($log_dir, 0755, true);
$log_file = $log_dir . '/cron_limpar_stories.log';

function logLimparStories(string $msg): void {
    global $log_file;
    file_put_contents($log_file, '[' . date('Y-m-d H:i:s') . "] $msg" . PHP_EOL, FILE_APPEND);
}

// Best-effort: a barra de stories já filtra expira_em > NOW() em toda leitura, então este
// cron não é obrigatório pra "esconder" nada -- só libera espaço em disco. Mesmo padrão de
// acesso (CLI livre, HTTP exige chave) de cron_ranking.php/cron_retry_split.php.
if (php_sapi_name() !== 'cli') {
    $chave_informada = (string) ($_GET['chave'] ?? '');
    if (!hash_equals(CHAVE_SECRETA_CRON, $chave_informada)) {
        logLimparStories('Acesso HTTP negado (chave ausente ou incorreta).');
        http_response_code(403);
        exit('Acesso negado.');
    }
}

$lock_file = sys_get_temp_dir() . '/cron_limpar_stories.lock';
$lock = fopen($lock_file, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    logLimparStories('Execução anterior ainda em andamento. Encerrando essa chamada.');
    exit;
}

try {
    $total_removidas = 0;
    // Em lotes de 200 até esvaziar -- evita um DELETE gigante numa instalação que ficou
    // muito tempo sem rodar este cron.
    do {
        $removidas_no_lote = limparStoriesExpiradas(200);
        $total_removidas += $removidas_no_lote;
    } while ($removidas_no_lote > 0);

    logLimparStories($total_removidas > 0
        ? "Removidas $total_removidas story(s) expirada(s) (linha + arquivo)."
        : 'Nenhuma story expirada pendente de limpeza.');
} catch (Throwable $e) {
    logLimparStories('Erro: ' . $e->getMessage());
}

flock($lock, LOCK_UN);
fclose($lock);
