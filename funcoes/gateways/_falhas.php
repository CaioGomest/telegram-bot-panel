<?php
declare(strict_types=1);

/**
 * Registro das falhas de gateway no fallback (tabela gateway_falhas): quem falhou antes de o Pix sair por outro gateway, ou que nenhum atendeu.
 * Aparece no detalhe da venda (cliente e admin) e no dashboard do admin. Nada aqui lança: registrar falha nunca pode atrapalhar a geração do Pix.
 */

const GATEWAY_FALHAS_RETENCAO_DIAS = 60;

/**
 * Grava as falhas de uma geração de Pix. $falhas = lista de ['id_gateway' => int, 'motivo' => string]. $txid_final = transação do Pix que saiu por
 * outro gateway (liga a falha à venda); vazio quando nenhum gateway atendeu.
 */
function registrarFalhasGateway(int $id_usuario, array $falhas, string $txid_final = ''): void {
    if ($id_usuario <= 0 || !$falhas) {
        return;
    }
    try {
        global $pdo;
        $stmt = $pdo->prepare('INSERT INTO gateway_falhas (id_usuario, id_gateway, txid_final, motivo) VALUES (?, ?, ?, ?)');
        foreach ($falhas as $falha) {
            $stmt->execute([$id_usuario, (int) $falha['id_gateway'], mb_substr($txid_final, 0, 255), mb_substr((string) $falha['motivo'], 0, 255)]);
        }
    } catch (\Throwable $e) {
        error_log('[gateway] registrar falha: ' . $e->getMessage());
    }
}

/**
 * Falhas ligadas às vendas pelos txids, para a lista/detalhe de vendas (uma consulta só, pelo índice do txid_final).
 *
 * @param string[] $txids
 * @return array<string, array<int, array{gateway: string, motivo: string, quando: string}>> txid => falhas
 */
function falhasGatewayPorTxid(array $txids): array {
    $txids = array_values(array_unique(array_filter(array_map('strval', $txids), static fn(string $t): bool => $t !== '')));
    if (!$txids) {
        return [];
    }
    try {
        global $pdo;
        $stmt = $pdo->prepare('SELECT f.txid_final, COALESCE(g.titulo, g.nome, \'Gateway\') AS gateway, f.motivo, f.criado_em
            FROM gateway_falhas f LEFT JOIN gateways g ON g.id = f.id_gateway
            WHERE f.txid_final IN (' . implode(',', array_fill(0, count($txids), '?')) . ') ORDER BY f.id');
        $stmt->execute($txids);
        $por_txid = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linha) {
            $por_txid[$linha['txid_final']][] = ['gateway' => (string) $linha['gateway'], 'motivo' => (string) $linha['motivo'], 'quando' => (string) $linha['criado_em']];
        }
        return $por_txid;
    } catch (\Throwable $e) {
        return [];   // tabela ainda não criada (antes do Atualizar Banco): a venda abre normal, só sem o histórico
    }
}

/** Texto curto das falhas de uma venda, para tooltip/linha: "OmegaPayments: Erro de conexão". */
function textoFalhasGateway(array $falhas): string {
    return implode(' · ', array_map(static fn(array $f): string => $f['gateway'] . ': ' . $f['motivo'], $falhas));
}

/** Apaga o histórico antigo (chamado pelo cron diário de rotação de logs). Devolve quantas linhas saíram. */
function limparFalhasGatewayAntigas(): int {
    try {
        global $pdo;
        $stmt = $pdo->prepare('DELETE FROM gateway_falhas WHERE criado_em < (NOW() - INTERVAL ' . GATEWAY_FALHAS_RETENCAO_DIAS . ' DAY) LIMIT 5000');
        $stmt->execute();
        return $stmt->rowCount();
    } catch (\Throwable $e) {
        return 0;
    }
}
