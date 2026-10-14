<?php
declare(strict_types=1);

/**
 * Dados do cabeçalho superior do painel do cliente (saudação, data, faturamento).
 */

function saudacaoPorHorario(?int $hora = null): string
{
    $hora = $hora ?? (int) date('G');
    if ($hora >= 5 && $hora < 12) {
        return 'Bom dia';
    }
    return $hora < 18 && $hora >= 12 ? 'Boa tarde' : 'Boa noite';
}

function dataExtensaPt(?int $timestamp = null): string
{
    $meses = ['JANEIRO', 'FEVEREIRO', 'MARÇO', 'ABRIL', 'MAIO', 'JUNHO', 'JULHO', 'AGOSTO', 'SETEMBRO', 'OUTUBRO', 'NOVEMBRO', 'DEZEMBRO'];
    $timestamp = $timestamp ?? time();
    return (int) date('j', $timestamp) . ' DE ' . $meses[(int) date('n', $timestamp) - 1] . ' DE ' . date('Y', $timestamp);
}

/**
 * Faturamento acumulado do usuário (vendas pagas), lido do cache pré-calculado
 * por cron_metricas_admin.php -- o mesmo que a dashboard usa em "Total".
 */
function faturamentoTotalUsuario(PDO $pdo, int $id_usuario): float
{
    try {
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(valor_pago), 0) FROM metricas_horarias_usuario WHERE id_usuario = ?');
        $stmt->execute([$id_usuario]);
        return (float) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0.0;
    }
}

/** Próxima meta de faturamento acima do valor atual (10k, 50k, 100k...). */
function metaFaturamento(float $faturamento): float
{
    foreach ([10000, 50000, 100000, 250000, 500000, 1000000, 5000000, 10000000] as $meta) {
        if ($faturamento < $meta) {
            return (float) $meta;
        }
    }
    return (float) (ceil($faturamento / 10000000) * 10000000 + 10000000);
}

function formatarMetaCurta(float $meta): string
{
    if ($meta >= 1000000) {
        return rtrim(rtrim(number_format($meta / 1000000, 1, ',', ''), '0'), ',') . 'M';
    }
    return (int) ($meta / 1000) . 'k';
}
