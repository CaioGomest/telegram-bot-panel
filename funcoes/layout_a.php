<?php
declare(strict_types=1);

/**
 * Layout A ("Bento"): trilho lateral com rótulos + barra de topo no desktop; barra de abas + folha "Mais" no celular.
 *
 * Só as páginas do vendedor que não são editores e as de admin/ (desde 05/11/2026, pedido do dono: "a sidebar tem que seguir o padrão das outras, não
 * tem que ficar mudando") usam a casca nova. As de admin/ trazem o conteúdo no estilo antigo dentro da casca nova (ver LAYOUT_A_PAGINAS_ADMIN). Os editores
 * de bot e de fluxo, login, cadastro e a página pública da comunidade seguem com a casca antiga, que continua inteira no painel.css.
 * Exceção: remarketing_nova (assistente de campanha em tela cheia, sem barra lateral) está na lista só para usar as cores e a
 * fonte do Layout A; a página não inclui a barra lateral.
 *
 * Esta é a única chave: tema_inline.php (presente no <head> de todas as páginas) liga a classe html.layout-a e carrega o
 * assets/css/layout_a.css; barra_lateral.php escolhe o markup da casca. Para tirar uma página da casca nova, é só
 * tirá-la da lista abaixo.
 */
const LAYOUT_A_PAGINAS = [
    'index', 'bots', 'fluxos', 'leads', 'vendas', 'ranking', 'configuracao_usuario', 'gateways',
    'remarketing', 'remarketing_detalhes', 'remarketing_nova', 'redirecionamento', 'traqueamento', 'links_rastreamento', 'webhooks',
];

/** Páginas de admin/ (nome do arquivo, sem .php) que usam a casca nova. */
const LAYOUT_A_PAGINAS_ADMIN = [
    'dashboard', 'transacoes', 'usuarios', 'ranking', 'ranking_classificacao', 'comunidade', 'logs', 'emails', 'configuracoes', 'consultar_venda', 'testes_desempenho',
];

function layoutAAtivo(string $caminho_base = ''): bool
{
    $pagina = preg_replace('/\.php$/', '', basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')));
    // Páginas dentro de admin/ definem $caminho_base = '../' antes de incluir o tema e a barra lateral.
    if ($caminho_base === '../') {
        return in_array($pagina, LAYOUT_A_PAGINAS_ADMIN, true);
    }
    if ($caminho_base !== '') {
        return false;
    }
    return in_array($pagina, LAYOUT_A_PAGINAS, true);
}
