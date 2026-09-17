<?php
declare(strict_types=1);

require_once __DIR__ . '/infopago_cashout.php';
require_once __DIR__ . '/criptografia.php';

/**
 * Dispara o(s) split(s) "manuais" da InfoPago via Cash-Out, um pra cada destino configurado
 * pelo dono do bot. Chamado tanto pelo webhook (webhook_infopago.php) quanto pelo cron de
 * fallback (cron_verificar_pix.php) e pelo cron de retentativa (cron_retry_split.php).
 * Falha aqui não deve impedir a liberação de acesso do comprador — só loga o erro.
 *
 * Idempotente por venda: se já existe uma linha 'pago' em vendas_splits pra um destino
 * específico daquela venda, esse destino é pulado (não paga de novo) -- é o que permite
 * chamar essa função de novo com segurança numa venda 'parcial' (alguns destinos pagos,
 * outros não) sem duplicar o repasse dos que já deram certo.
 */
function dispararSplitInfopago(int $id_dono, float $valor_venda, string $txid, int $venda_id = 0): void {
    global $pdo;

    $log_file = __DIR__ . '/../logs/split_debug.log';
    $log = function (string $msg) use ($log_file): void {
        file_put_contents($log_file, '[' . date('Y-m-d H:i:s') . '] [SplitInfoPago] ' . $msg . PHP_EOL, FILE_APPEND | LOCK_EX);
    };

    $marcar_resumo = function (string $status, float $valor_repassado) use ($pdo, $venda_id, $valor_venda): void {
        if ($venda_id <= 0) {
            return;
        }
        $comissao_admin = round($valor_venda - $valor_repassado, 2);
        $pdo->prepare("UPDATE vendas SET split_status = ?, split_valor = ?, comissao_admin = ?, split_em = NOW() WHERE id = ?")
            ->execute([$status, $valor_repassado, $comissao_admin, $venda_id]);
    };

    $registrar_split = function (?int $usuario_split_id, string $chave_pix, ?string $descricao, float $valor, string $status, ?string $erro = null) use ($pdo, $venda_id): void {
        if ($venda_id <= 0) {
            return;
        }
        $pdo->prepare(
            "INSERT INTO vendas_splits (venda_id, usuario_split_id, chave_pix, descricao, valor, status, erro) VALUES (?, ?, ?, ?, ?, ?, ?)"
        )->execute([$venda_id, $usuario_split_id, $chave_pix, $descricao, $valor, $status, $erro]);
    };

    $stmt = $pdo->prepare("SELECT id, chave_pix_split, taxa_split, descricao FROM usuarios_splits WHERE id_usuario = ? AND gateway_nome = 'infopago' ORDER BY ordem, id");
    $stmt->execute([$id_dono]);
    $splits = array_filter($stmt->fetchAll(PDO::FETCH_ASSOC), fn($s) => !empty($s['chave_pix_split']));

    if (empty($splits)) {
        $marcar_resumo('sem_split', 0.00);
        return;
    }

    // Destinos que já foram pagos com sucesso numa tentativa anterior pra essa mesma venda
    // (retentativa depois de 'parcial' ou 'falhou') -- nunca reenvia pra esses.
    $ja_pagos = [];
    if ($venda_id > 0) {
        $stmt_ja_pagos = $pdo->prepare("SELECT usuario_split_id, valor FROM vendas_splits WHERE venda_id = ? AND status = 'pago'");
        $stmt_ja_pagos->execute([$venda_id]);
        foreach ($stmt_ja_pagos->fetchAll(PDO::FETCH_ASSOC) as $linha) {
            $ja_pagos[(int) $linha['usuario_split_id']] = (float) $linha['valor'];
        }
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
    $cred = $cred ? decifrarCamposGateway($cred) : null;

    if (!$cred || empty($cred['cashout_client_id']) || empty($cred['cashout_certificado'])) {
        $log("Split configurado mas o admin ainda não configurou as credenciais de Cash-Out. Ignorando split (venda dono=$id_dono).");
        $marcar_resumo('sem_credenciais', 0.00);
        return;
    }

    $cashout = new InfopagoCashout($cred['cashout_client_id'], $cred['cashout_client_secret'], $cred['cashout_certificado'], $cred['cashout_cert_password'] ?? '');

    $algum_pago = false;
    $algum_falhou = false;
    $total_repassado = 0.00;

    foreach ($splits as $split) {
        $valor_split = round($valor_venda * ((float)$split['taxa_split'] / 100), 2);
        if ($valor_split <= 0) {
            continue;
        }

        if (array_key_exists((int) $split['id'], $ja_pagos)) {
            $log("Split já tinha sido pago numa tentativa anterior, pulando | txid=$txid | destino={$split['chave_pix_split']}");
            $algum_pago = true;
            $total_repassado += $ja_pagos[(int) $split['id']];
            continue;
        }

        $resp = $cashout->transferirPorChavePix($split['chave_pix_split'], $valor_split, "Split venda TXID {$txid}");

        if ($resp['sucesso'] ?? false) {
            $log("Split transferido | txid=$txid | valor=$valor_split | destino={$split['chave_pix_split']}");
            $registrar_split((int)$split['id'], $split['chave_pix_split'], $split['descricao'], $valor_split, 'pago');
            $algum_pago = true;
            $total_repassado += $valor_split;
        } else {
            $erro = json_encode($resp['erro'] ?? '');
            $log("Split FALHOU | txid=$txid | valor=$valor_split | erro=$erro");
            $registrar_split((int)$split['id'], $split['chave_pix_split'], $split['descricao'], $valor_split, 'falhou', $erro);
            $algum_falhou = true;
        }
    }

    if ($algum_pago && $algum_falhou) {
        $marcar_resumo('parcial', $total_repassado);
    } elseif ($algum_falhou) {
        $marcar_resumo('falhou', 0.00);
    } elseif ($algum_pago) {
        $marcar_resumo('pago', $total_repassado);
    } else {
        $marcar_resumo('sem_split', 0.00);
    }
}
