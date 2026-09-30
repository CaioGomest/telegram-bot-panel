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
// chave certa (?chave=...) — ver anotacoes/HISTORICO-CONSOLIDADO.md.
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

// O bucket é por criado_em, mas o status muda depois: PIX de assinatura fica pagável ~6 dias, então
// uma venda criada há 3 dias pode virar 'pago' hoje. Por isso a janela é de 7 dias (começa sempre à
// meia-noite, então nenhum bucket é recalculado pela metade). --completo (CLI) ou ?completo=1 refaz
// o histórico inteiro, pra reconciliar venda antiga inserida/alterada depois do preenchimento inicial.
$modo_completo = (php_sapi_name() === 'cli' && in_array('--completo', $argv ?? [], true))
    || (php_sapi_name() !== 'cli' && ($_GET['completo'] ?? '') === '1');
$janela_vendas = $modo_completo ? '1=1' : 'v.criado_em >= (CURDATE() - INTERVAL 7 DAY)';
$janela_leads  = $modo_completo ? '1=1' : 'l.criado_em >= (CURDATE() - INTERVAL 1 DAY)';
$rotulo_janela = $modo_completo ? 'histórico completo' : 'últimos 7 dias';
if ($modo_completo) {
    logCronMetricas('Modo --completo: recalculando o histórico inteiro.');
}

try {
    $stmt = $pdo->prepare("
        INSERT INTO metricas_horarias_admin (data, hora, faturamento, comissao, quantidade, atualizado_em)
        SELECT DATE(v.criado_em), HOUR(v.criado_em), SUM(v.valor), SUM(v.comissao_admin), COUNT(*), NOW()
        FROM vendas v
        WHERE v.status = 'pago' AND $janela_vendas
        GROUP BY DATE(v.criado_em), HOUR(v.criado_em)
        ON DUPLICATE KEY UPDATE
            faturamento = VALUES(faturamento),
            comissao = VALUES(comissao),
            quantidade = VALUES(quantidade),
            atualizado_em = VALUES(atualizado_em)
    ");
    $stmt->execute();
    logCronMetricas("Métricas horárias (admin) recalculadas ($rotulo_janela). Linhas afetadas: {$stmt->rowCount()}.");
} catch (Throwable $e) {
    logCronMetricas('Erro (admin): ' . $e->getMessage());
}

try {
    // Mesma ideia acima, mas por usuário -- alimenta o dashboard do cliente (index.php).
    // Feito num try/catch separado pra um erro aqui não impedir a atualização acima.
    $stmt = $pdo->prepare("
        INSERT INTO metricas_horarias_usuario (id_usuario, data, hora, valor_pago, qtd_paga, qtd_gerada, atualizado_em)
        SELECT b.id_usuario, DATE(v.criado_em), HOUR(v.criado_em),
               SUM(CASE WHEN v.status = 'pago' THEN v.valor ELSE 0 END),
               SUM(CASE WHEN v.status = 'pago' THEN 1 ELSE 0 END),
               COUNT(*),
               NOW()
        FROM vendas v
        JOIN bots b ON v.bot_id = b.id
        WHERE $janela_vendas
        GROUP BY b.id_usuario, DATE(v.criado_em), HOUR(v.criado_em)
        ON DUPLICATE KEY UPDATE
            valor_pago = VALUES(valor_pago),
            qtd_paga = VALUES(qtd_paga),
            qtd_gerada = VALUES(qtd_gerada),
            atualizado_em = VALUES(atualizado_em)
    ");
    $stmt->execute();

    $stmt2 = $pdo->prepare("
        INSERT INTO metricas_horarias_usuario (id_usuario, data, hora, qtd_leads, atualizado_em)
        SELECT b.id_usuario, DATE(l.criado_em), HOUR(l.criado_em), COUNT(*), NOW()
        FROM leads l
        JOIN bots b ON l.bot_id = b.id
        WHERE $janela_leads
        GROUP BY b.id_usuario, DATE(l.criado_em), HOUR(l.criado_em)
        ON DUPLICATE KEY UPDATE
            qtd_leads = VALUES(qtd_leads),
            atualizado_em = VALUES(atualizado_em)
    ");
    $stmt2->execute();

    logCronMetricas("Métricas horárias (usuário) recalculadas ($rotulo_janela). Linhas afetadas: {$stmt->rowCount()} + {$stmt2->rowCount()}.");
} catch (Throwable $e) {
    logCronMetricas('Erro (usuário): ' . $e->getMessage());
}

flock($lock, LOCK_UN);
fclose($lock);
