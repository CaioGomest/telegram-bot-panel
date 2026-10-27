<?php
declare(strict_types=1);

/**
 * Layout A ("Bento"): trilho lateral com rótulos + barra de topo no desktop; barra de abas + folha "Mais" no celular.
 *
 * Só as páginas do vendedor (fora de admin/) que não são editores usam a casca nova. admin/, os editores de bot e de
 * fluxo, login, cadastro e a página pública da comunidade seguem com a casca antiga, que continua inteira no painel.css.
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

function layoutAAtivo(string $caminho_base = ''): bool
{
    // Páginas dentro de admin/ definem $caminho_base = '../' antes de incluir o tema e a barra lateral.
    if ($caminho_base !== '') {
        return false;
    }
    $pagina = preg_replace('/\.php$/', '', basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')));
    return in_array($pagina, LAYOUT_A_PAGINAS, true);
}
