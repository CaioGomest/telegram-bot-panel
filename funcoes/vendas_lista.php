<?php
declare(strict_types=1);

/**
 * Lista de vendas do vendedor (página Vendas e bloco "Vendas" do dashboard): o SELECT dos campos mostrados e os rótulos.
 * Use sempre em duas etapas (ids da página primeiro, depois "WHERE v.id IN (...)"), como em leads.php.
 */
function sqlCamposVendasLista(bool $tem_nome_produto, bool $tem_id_fluxo): string
{
    return "
    SELECT v.id, v.id_telegram, v.valor, v.status, v.tipo_cobranca, v.transacao_id, v.criado_em, v.pago_em, v.id_grupo_telegram,
           " . ($tem_nome_produto ? 'v.nome_produto' : 'NULL') . " AS nome_produto,
           COALESCE(b.primeiro_nome, b.nome_usuario) AS nome_bot,
           (SELECT l.nome FROM leads l WHERE l.bot_id = v.bot_id AND l.id_telegram = v.id_telegram ORDER BY l.id DESC LIMIT 1) AS nome_cliente,
           (SELECT l.nome_usuario_telegram FROM leads l WHERE l.bot_id = v.bot_id AND l.id_telegram = v.id_telegram ORDER BY l.id DESC LIMIT 1) AS username,
           v.venda_pai_id,
           -- Cliente que já tinha uma compra paga ANTES desta (índice idx_vendas_lookup): mesma coisa de novo = renovação; outra coisa = recompra.
           EXISTS (SELECT 1 FROM vendas p WHERE p.id_telegram = v.id_telegram AND p.bot_id = v.bot_id AND p.status = 'pago' AND p.id < v.id) AS ja_comprou,
           EXISTS (SELECT 1 FROM vendas p WHERE p.id_telegram = v.id_telegram AND p.bot_id = v.bot_id AND p.status = 'pago' AND p.id < v.id
                   AND (" . ($tem_nome_produto ? '(v.nome_produto IS NOT NULL AND p.nome_produto = v.nome_produto) OR ' : '') . "(v.id_grupo_telegram IS NOT NULL AND p.id_grupo_telegram = v.id_grupo_telegram))) AS mesmo_plano,
           " . ($tem_id_fluxo ? 'v.id_fluxo, f.nome AS nome_fluxo, f.modo AS modo_fluxo' : 'NULL AS id_fluxo, NULL AS nome_fluxo, NULL AS modo_fluxo') . "
    FROM vendas v JOIN bots b ON v.bot_id = b.id" . ($tem_id_fluxo ? ' LEFT JOIN fluxos f ON f.id = v.id_fluxo' : '');
}

function rotuloStatusVenda(string $status): string
{
    $mapa = [
        'pago' => ['Pago', 'badge-sucesso'],
        'gerado' => ['Aguardando Pix', 'badge-alerta'],
        'expirado' => ['Expirado', 'badge-neutro'],
        'cancelado' => ['Cancelado', 'badge-neutro'],
    ];
    [$texto, $classe] = $mapa[$status] ?? [htmlspecialchars(ucfirst($status)), 'badge-neutro'];
    return "<span class=\"badge $classe\">$texto</span>";
}
/** 'primeira' | 'renovacao' | 'recompra' | 'ciclo' (cobrança seguinte de uma assinatura recorrente). */
function tipoDaVenda(array $v): string
{
    if (!empty($v['venda_pai_id'])) return 'ciclo';
    if (empty($v['ja_comprou'])) return 'primeira';
    return !empty($v['mesmo_plano']) ? 'renovacao' : 'recompra';
}
function rotuloTipoDaVenda(string $tipo): string
{
    return ['primeira' => 'Primeira compra', 'renovacao' => 'Renovação', 'recompra' => 'Recompra', 'ciclo' => 'Renovação (ciclo da assinatura)'][$tipo] ?? '';
}
function rotuloTipoCobranca(?string $tipo): string
{
    return in_array($tipo, ['assinatura', 'recorrente'], true) ? 'Assinatura' : 'Pagamento único';
}
