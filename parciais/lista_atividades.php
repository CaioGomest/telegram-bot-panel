<?php
/**
 * Lista de atividade/log + paginação. Usada pelos dois dashboards (index.php e
 * admin/dashboard.php), e pelo pedido de bloco do JS, que devolve só isto aqui.
 *
 * Espera de quem inclui:
 *   $atividades        array das linhas
 *   $total_atividades  total pra paginação
 *   $por_pagina        itens por página
 *   $mostrar_usuario   opcional, true no admin (mostra de quem é o evento)
 *   $texto_vazio       opcional, texto do estado vazio
 */
declare(strict_types=1);

$mostrar_usuario = $mostrar_usuario ?? false;
$texto_vazio = $texto_vazio ?? 'Nenhum evento recente.';

// Ícone por tipo. O padrão é o de informação: o admin vê todos os tipos (inclusive
// 'sistema'), o dashboard do usuário filtra só venda/pix_gerado/lead.
$icones_atividade = [
    'padrao'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
    'venda'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
    'pix_gerado' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
    'lead'       => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>',
];
?>
<div class="lista-atividade">
    <?php foreach ($atividades as $ativ): ?>
        <?php
            $icone_svg = $icones_atividade[$ativ['tipo']] ?? $icones_atividade['padrao'];

            $data_criado = strtotime($ativ['criado_em']);
            $diff = abs(time() - $data_criado);
            if ($diff < 60) $tempo = 'agora';
            elseif ($diff < 3600) $tempo = floor($diff / 60) . 'm';
            elseif ($diff < 86400) $tempo = floor($diff / 3600) . 'h';
            else $tempo = floor($diff / 86400) . 'd';
        ?>
        <div class="item-atividade">
            <div class="icone-atividade <?php echo htmlspecialchars($ativ['tipo']); ?>">
                <?php echo $icone_svg; ?>
            </div>
            <div class="atividade-conteudo">
                <p class="atividade-titulo"><?php echo htmlspecialchars($ativ['titulo']); ?><?php
                    if ($mostrar_usuario && !empty($ativ['nome_usuario'])): ?><span class="texto-suave" style="font-weight:500;"> (<?php echo htmlspecialchars($ativ['nome_usuario']); ?>)</span><?php
                    endif; ?></p>
                <p class="atividade-descricao"><?php echo htmlspecialchars($ativ['descricao']); ?></p>
            </div>
            <div class="atividade-tempo">
                <?php echo $tempo; ?><br>
                <?php echo date('d/m, H:i', $data_criado); ?>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if (empty($atividades)): ?>
        <div class="estado-vazio"><?php echo htmlspecialchars($texto_vazio); ?></div>
    <?php endif; ?>
</div>
<?php echo paginador($total_atividades, $por_pagina); ?>
