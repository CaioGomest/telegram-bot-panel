<?php
declare(strict_types=1);

require_once __DIR__ . '/_contrato.php';

/**
 * Registro dos gateways: cada arquivo <nome>.meta.php desta pasta devolve um array com
 *   nome, titulo, status_pagos (status em maiúsculas que significam pago), arquivo (caminho da classe), fabrica (fn(array $config): ProvedorGateway),
 *   credenciais_obrigatorias (colunas de usuarios_gateways que precisam estar preenchidas),
 *   aceita_recorrencia, usa_chave_pix, letra (ícone), icone_classe, subtitulo.
 * Gateway novo = classe + um .meta.php; nenhuma lista central para editar.
 *
 * @return array<string, array<string, mixed>> indexado pelo nome do gateway
 */
function registroGateways(): array {
    static $registro = null;
    if ($registro === null) {
        $registro = [];
        foreach (glob(__DIR__ . '/*.meta.php') ?: [] as $arquivo) {
            $meta = require $arquivo;
            $registro[(string) $meta['nome']] = $meta;
        }
        ksort($registro);
    }
    return $registro;
}

function registroGateway(string $nome): ?array {
    return registroGateways()[strtolower($nome)] ?? null;
}

/** Credencial completa = todas as colunas exigidas pelo gateway preenchidas (Omega: client_id + client_secret). */
function gatewayCredenciaisCompletas(string $nome, array $config): bool {
    $gateway = registroGateway($nome);
    if ($gateway === null) {
        return false;
    }
    foreach ($gateway['credenciais_obrigatorias'] as $coluna) {
        if (empty($config[$coluna])) {
            return false;
        }
    }
    return true;
}

/** O status devolvido pelo gateway (webhook ou consulta) significa "pago"? Lista por gateway em 'status_pagos' (sem diferenciar maiúscula). */
function gatewayStatusPago(string $nome, string $status): bool {
    $gateway = registroGateway($nome);
    return $gateway !== null && in_array(strtoupper(trim($status)), $gateway['status_pagos'], true);
}
