<?php
declare(strict_types=1);

/**
 * Gera os links de paginação para listagens
 * 
 * @param int $total Registros totais
 * @param int $por_pagina Registros por página
 * @return string HTML da paginação
 */
function paginador(int $total, int $por_pagina): string {
    if ($total <= $por_pagina) {
        return '';
    }

    $paginas = ceil($total / $por_pagina);
    $atual = isset($_GET['pagina']) ? max(1, (int)$_GET['pagina']) : 1;
    $atual = min($atual, $paginas);

    // Preservar outros parâmetros da URL (filtros, buscas)
    $params = $_GET;
    unset($params['pagina']);
    $queryString = http_build_query($params);
    $prefixo = $queryString ? "?{$queryString}&pagina=" : "?pagina=";

    $html = '<div class="paginacao-container">';
    $html .= '<ul class="paginacao-lista">';

    // Botão Anterior
    if ($atual > 1) {
        $prev = $atual - 1;
        $html .= "<li><a href='{$prefixo}{$prev}' class='paginacao-link'>&laquo; Anterior</a></li>";
    } else {
        $html .= "<li><span class='paginacao-link disabled'>&laquo; Anterior</span></li>";
    }

    // Links numéricos
    $inicio = max(1, $atual - 2);
    $fim = min($paginas, $atual + 2);

    if ($inicio > 1) {
        $html .= "<li><a href='{$prefixo}1' class='paginacao-link'>1</a></li>";
        if ($inicio > 2) {
            $html .= "<li><span class='paginacao-dots'>...</span></li>";
        }
    }

    for ($i = $inicio; $i <= $fim; $i++) {
        $classe = $i === $atual ? 'active' : '';
        $html .= "<li><a href='{$prefixo}{$i}' class='paginacao-link {$classe}'>{$i}</a></li>";
    }

    if ($fim < $paginas) {
        if ($fim < $paginas - 1) {
            $html .= "<li><span class='paginacao-dots'>...</span></li>";
        }
        $html .= "<li><a href='{$prefixo}{$paginas}' class='paginacao-link'>{$paginas}</a></li>";
    }

    // Botão Próximo
    if ($atual < $paginas) {
        $next = $atual + 1;
        $html .= "<li><a href='{$prefixo}{$next}' class='paginacao-link'>Próximo &raquo;</a></li>";
    } else {
        $html .= "<li><span class='paginacao-link disabled'>Próximo &raquo;</span></li>";
    }

    $html .= '</ul>';
    $html .= '</div>';
    
    // CSS inline removido em favor de assets/app.css

    return $html;
}
