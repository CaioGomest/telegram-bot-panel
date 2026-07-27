<?php
declare(strict_types=1);

require_once __DIR__ . '/infopago_cashout.php';

/**
 * Dispara o split "manual" da InfoPago via Cash-Out, se o dono do bot tiver configurado um destino.
 * Chamado tanto pelo webhook (webhook_infopago.php) quanto pelo cron de fallback (cron_verificar_pix.php).
 * Falha aqui não deve impedir a liberação de acesso do comprador — só loga o erro.
 */
function dispararSplitInfopago(int $id_dono, float $valor_venda, string $txid, int $venda_id = 0): void {
    global $pdo;

    $log_file = __DIR__ . '/../logs/split_debug.log';
    $log = function (string $msg) use ($log_file): void {
        file_put_contents($log_file, '[' . date('Y-m-d H:i:s') . '] [SplitInfoPago] ' . $msg . PHP_EOL, FILE_APPEND | LOCK_EX);
    };

    $marcar_split = function (string $status, ?float $valor = null) use ($pdo, $venda_id): void {
        if ($venda_id <= 0) {
            return;
        }
        $pdo->prepare("UPDATE vendas SET split_status = ?, split_valor = ?, split_em = NOW() WHERE id = ?")
            ->execute([$status, $valor, $venda_id]);
    };

    $stmt = $pdo->prepare("SELECT chave_pix_split, taxa_split, tipo_split FROM usuarios_splits WHERE id_usuario = ? AND gateway_nome = 'infopago' LIMIT 1");
    $stmt->execute([$id_dono]);
    $split = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$split || empty($split['chave_pix_split'])) {
        $marcar_split('sem_split');
        return;
    }

    // Cash-Out usa as credenciais compartilhadas do admin — é a conta dele que recebe via
    // InfoPago e repassa a parte de cada usuário, não uma conta por usuário.
    $stmt = $pdo->prepare("
        SELECT ug.cashout_client_id, ug.cashout_client_secret, ug.cashout_certificado, ug.cashout_cert_password
        FROM usuarios_gateways ug
        JOIN usuarios u ON ug.id_usuario = u.id
        JOIN gateways g ON ug.id_gateway = g.id
        WHERE g.nome = 'infopago' AND u.perfil = 'admin'
        ORDER BY ug.atualizado_em DESC
        LIMIT 1
    ");
    $stmt->execute();
    $cred = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$cred || empty($cred['cashout_client_id']) || empty($cred['cashout_certificado'])) {
        $log("Split configurado mas o admin ainda não configurou as credenciais de Cash-Out. Ignorando split (venda dono=$id_dono).");
        $marcar_split('sem_credenciais');
        return;
    }

    $valor_split = ($split['tipo_split'] === 'fixo')
        ? (float)$split['taxa_split']
        : round($valor_venda * ((float)$split['taxa_split'] / 100), 2);

    if ($valor_split <= 0) {
        $marcar_split('sem_split');
        return;
    }

    $cashout = new InfopagoCashout($cred['cashout_client_id'], $cred['cashout_client_secret'], $cred['cashout_certificado'], $cred['cashout_cert_password'] ?? '');
    $resp = $cashout->transferirPorChavePix($split['chave_pix_split'], $valor_split, "Split venda TXID {$txid}");

    if ($resp['sucesso'] ?? false) {
        $log("Split transferido | txid=$txid | valor=$valor_split | destino={$split['chave_pix_split']}");
        $marcar_split('pago', $valor_split);
    } else {
        $log("Split FALHOU | txid=$txid | valor=$valor_split | erro=" . json_encode($resp['erro'] ?? ''));
        $marcar_split('falhou', $valor_split);
    }
}
