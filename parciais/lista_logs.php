<?php
/**
 * Lista de logs + paginação.
 *
 * Fica num parcial porque é renderizada em dois caminhos: pela página inteira
 * (admin/logs.php) e pelo pedido de bloco do JS, que devolve só isto aqui.
 *
 * Espera $logs, $total_logs e $por_pagina já preenchidos por quem inclui.
 */
declare(strict_types=1);
?>
<div class="tabela-dados">
    <table>
        <thead>
            <tr>
                <th>Data/Hora</th>
                <th>Usuário</th>
                <th>Tipo</th>
                <th>Evento</th>
                <th>Descrição</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($logs as $log): ?>
            <tr>
                <td class="mono texto-suave"><?php echo date('d/m/Y H:i:s', strtotime($log['criado_em'])); ?></td>
                <td>
                    <?php if ($log['nome_usuario']): ?>
                        <?php echo htmlspecialchars($log['nome_usuario']); ?>
                    <?php else: ?>
                        <span class="texto-suave">Sistema</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php
                    $classe = 'badge-neutro';
                    if ($log['tipo'] === 'venda') $classe = 'badge-sucesso';
                    if ($log['tipo'] === 'lead') $classe = 'badge-alerta';
                    ?>
                    <span class="badge <?php echo $classe; ?>"><?php echo strtoupper($log['tipo']); ?></span>
                </td>
                <td><?php echo htmlspecialchars($log['titulo']); ?></td>
                <td class="texto-suave"><?php echo htmlspecialchars($log['descricao']); ?></td>
            </tr>
            <?php endforeach; ?>

            <?php if (empty($logs)): ?>
            <tr>
                <td colspan="5" style="text-align: center; padding: 40px; color: var(--m);">Nenhum registro encontrado.</td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php echo paginador($total_logs, $por_pagina); ?>
