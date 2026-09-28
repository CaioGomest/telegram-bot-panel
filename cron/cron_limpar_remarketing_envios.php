<?php
declare(strict_types=1);

require_once __DIR__ . '/../conexao.php';

date_default_timezone_set('America/Sao_Paulo');
$log_dir = __DIR__ . '/../logs';
if (!is_dir($log_dir)) mkdir($log_dir, 0755, true);
$log_file = $log_dir . '/cron_limpar_remarketing_envios.log';

function logLimparRemarketingEnvios(string $msg): void {
    global $log_file;
    file_put_contents($log_file, '[' . date('Y-m-d H:i:s') . "] $msg" . PHP_EOL, FILE_APPEND);
}

// remarketing_envios é log operacional (um envio de mensagem em massa pode gerar dezenas de
// milhares de linhas por campanha) — só serve pra debugar entrega recente, não é histórico
// de negócio como vendas/leads. Sem limpeza, campanha recorrente grande (ex. "quem não
// comprou" toda semana) acumula sem limite e pode competir de verdade pela cota de banco
// (ver anotacoes/capacidade.md). Os contadores agregados (enviados/entregues/falhas) ficam
// na própria campanha pra sempre — só o log linha-a-linha expira. Mesmo padrão de acesso
// (CLI livre, HTTP exige chave) de cron_limpar_stories.php.
const RETENCAO_DIAS = 45;

if (php_sapi_name() !== 'cli') {
    $chave_informada = (string) ($_GET['chave'] ?? '');
    if (!hash_equals(CHAVE_SECRETA_CRON, $chave_informada)) {
        logLimparRemarketingEnvios('Acesso HTTP negado (chave ausente ou incorreta).');
        http_response_code(403);
        exit('Acesso negado.');
    }
}

$lock_file = sys_get_temp_dir() . '/cron_limpar_remarketing_envios.lock';
$lock = fopen($lock_file, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    logLimparRemarketingEnvios('Execução anterior ainda em andamento. Encerrando essa chamada.');
    exit;
}

try {
    $stmt = $pdo->prepare("DELETE FROM remarketing_envios WHERE enviado_em < (NOW() - INTERVAL " . RETENCAO_DIAS . " DAY) LIMIT 500");
    $total_removidas = 0;
    // Em lotes de 500 até esvaziar -- evita um DELETE gigante numa instalação que ficou
    // muito tempo sem rodar este cron (mesmo padrão de cron_limpar_stories.php).
    do {
        $stmt->execute();
        $removidas_no_lote = $stmt->rowCount();
        $total_removidas += $removidas_no_lote;
    } while ($removidas_no_lote > 0);

    logLimparRemarketingEnvios($total_removidas > 0
        ? "Removidas $total_removidas linha(s) de remarketing_envios com mais de " . RETENCAO_DIAS . " dias."
        : 'Nada pra limpar.');
} catch (Throwable $e) {
    logLimparRemarketingEnvios('Erro: ' . $e->getMessage());
}

flock($lock, LOCK_UN);
fclose($lock);
