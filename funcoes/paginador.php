<?php
declare(strict_types=1);

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
    $query_string = http_build_query($params);
    $prefixo = $query_string ? "?{$query_string}&pagina=" : "?pagina=";

    $html = '<div class="paginacao-container">';
    $html .= '<ul class="paginacao-lista">';

    if ($atual > 1) {
        $prev = $atual - 1;
        $html .= "<li><a href='{$prefixo}{$prev}' class='paginacao-link'>&laquo; Anterior</a></li>";
    } else {
        $html .= "<li><span class='paginacao-link disabled'>&laquo; Anterior</span></li>";
    }

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

    if ($atual < $paginas) {
        $next = $atual + 1;
        $html .= "<li><a href='{$prefixo}{$next}' class='paginacao-link'>Próximo &raquo;</a></li>";
    } else {
        $html .= "<li><span class='paginacao-link disabled'>Próximo &raquo;</span></li>";
    }

    $html .= '</ul>';
    $html .= '</div>';
    
    return $html;
}

/**
 * Abre o container de um bloco paginado. O JS de assets/js/paginacao.js procura por
 * [data-bloco] pra saber o que trocar quando você clica numa página.
 *
 * Existe porque virar página recarregava a tela inteira: no dashboard isso refazia as ~20
 * consultas de KPI e redesenhava os gráficos só pra trocar 10 linhas de uma lista que fica
 * no rodapé -- e ainda jogava o scroll de volta pro topo.
 */
function inicioBlocoPaginado(string $nome): string
{
    return '<div class="bloco-paginado" data-bloco="' . htmlspecialchars($nome) . '">';
}

function fimBlocoPaginado(): string
{
    return '</div>';
}

/**
 * O pedido é do JS querendo só este bloco, em vez da página inteira?
 *
 * Exige o cabeçalho de XHR de propósito: abrir a URL na mão continua devolvendo a página
 * completa, então o link de paginação segue funcionando sem JS e ninguém cai num pedaço
 * solto de HTML sem menu.
 */
function pedidoDeBloco(string $nome): bool
{
    return ($_GET['bloco'] ?? '') === $nome
        && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}
