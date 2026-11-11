<?php
/**
 * Vendas pagas do período do dashboard do admin (as mesmas que formam os cartões e o gráfico) + paginação. Usada por admin/dashboard.php e pelo pedido de
 * bloco do JS, que devolve só isto aqui.
 *
 * Espera de quem inclui:
 *   $vendas_lista        linhas (id, criado_em, valor, comissao_admin, transacao_id, nome_usuario, nome_bot, titulo_gateway)
 *   $falhas_gateway      txid => falhas anteriores (falhasGatewayPorTxid)
 *   $total_vendas_lista  total para a paginação
 *   $por_pagina_vendas   itens por página
 */
declare(strict_types=1);
?>
<div class="tabela-dados">
    <table>
        <thead>
            <tr>
                <th>Data</th>
                <th>Usuário / Bot</th>
                <th>Valor</th>
                <th class="col-numerica">Comissão</th>
                <th>Gateway</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($vendas_lista as $venda):
                $falhas_venda = $falhas_gateway[(string) ($venda['transacao_id'] ?? '')] ?? [];
            ?>
            <tr>
                <td class="mono texto-suave"><?php echo date('d/m/Y H:i', strtotime((string) $venda['criado_em'])); ?></td>
                <td>
                    <div><?php echo htmlspecialchars((string) $venda['nome_usuario']); ?></div>
                    <div class="celula-sub mono"><?php echo htmlspecialchars((string) ($venda['nome_bot'] ?? '-')); ?></div>
                </td>
                <td class="mono">R$ <?php echo number_format((float) $venda['valor'], 2, ',', '.'); ?></td>
                <td class="col-numerica"><?php echo (float) $venda['comissao_admin'] > 0
                    ? '<span class="mono">R$ ' . number_format((float) $venda['comissao_admin'], 2, ',', '.') . '</span>'
                    : '<span class="badge badge-neutro">Sem split</span>'; ?></td>
                <td>
                    <div class="texto-suave"><?php echo htmlspecialchars((string) ($venda['titulo_gateway'] ?? '-')); ?></div>
                    <?php if ($falhas_venda): ?>
                        <div class="celula-sub" style="color:var(--wa,#f59e0b);" title="<?php echo htmlspecialchars(textoFalhasGateway($falhas_venda)); ?>">Falhou antes: <?php echo htmlspecialchars(implode(', ', array_unique(array_column($falhas_venda, 'gateway')))); ?></div>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($vendas_lista)): ?>
            <tr><td colspan="5" class="estado-vazio">Nenhuma venda paga neste período.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?php echo paginador($total_vendas_lista, $por_pagina_vendas, 'pagina_vendas'); ?>
