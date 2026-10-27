<?php
declare(strict_types=1);

/**
 * Gera páginas estáticas (lista.html, assistente.html e assistente_edicao.html) a partir de remarketing.php e remarketing_nova.php de verdade, com stubs no lugar de
 * sessão, banco e barra lateral. As páginas usam api_falsa.js como API; nada toca servidor, banco ou Telegram. Só lê o projeto.
 *   php tests/remarketing/ui/gerar.php <pasta de saída>        (quem chama é teste.js)
 */

$raiz = str_replace(chr(92), '/', dirname(__DIR__, 3));
$aqui = str_replace(chr(92), '/', __DIR__);
$saida = str_replace(chr(92), '/', $argv[1] ?? sys_get_temp_dir() . '/remarketing_ui');
if (!is_dir($saida)) {
    mkdir($saida, 0777, true);
}

require_once $raiz . '/funcoes/remarketing.php';   // REMARKETING_DIAS_SEMANA (os dias do modal "Agendar campanha")

function nomeSistema(): string
{
    return 'Teste';
}
function csrfToken(): string
{
    return 'token-de-teste';
}

$cabeca_tema = '<script>document.documentElement.classList.add("layout-a");try{document.documentElement.setAttribute("data-theme", localStorage.getItem("tema") || "dark");}catch(e){document.documentElement.setAttribute("data-theme","dark");}</script>'
    . '<link rel="stylesheet" href="assets/css/layout_a.css">'
    . '<style>html[data-theme]:root{--or:#ff7a1a;--or2:#ffa04d;--orsoft:rgba(255,122,26,.16);}</style>';
$trilho_falso = '<aside class="a-rail" aria-label="menu de teste"></aside>';

// O JS de cada página sai com o redirecionamento trocado por uma marca: o teste lê window.__navegou em vez de sair da página.
foreach (['remarketing.js', 'remarketing_nova.js'] as $js) {
    $codigo = (string) file_get_contents($raiz . '/assets/' . $js);
    $codigo = str_replace("window.location.href = 'remarketing';", "window.__navegou = 'remarketing';", $codigo);
    file_put_contents($saida . '/' . $js, $codigo);
}

/** Roda o PHP/HTML da página (já sem os includes) e devolve o HTML pronto para abrir em file://. */
function renderizar(string $php, string $arquivo_js, string $aqui, string $saida, string $raiz): string
{
    $php = preg_replace('#<script src="assets/' . preg_quote($arquivo_js, '#') . '\?v=[^"]*"></script>#', '<script src="file:///' . $aqui . '/api_falsa.js"></script><script src="file:///' . $saida . '/' . $arquivo_js . '"></script>', $php);
    ob_start();
    eval('?>' . $php);
    return str_replace('<head>', '<head><base href="file:///' . $raiz . '/">', (string) ob_get_clean());
}

// --- lista: o HTML de remarketing.php (depois da API), com $ic definido ---
$fonte = (string) file_get_contents($raiz . '/remarketing.php');
$pos = strpos($fonte, '$ic = static function');
if ($pos === false) {
    fwrite(STDERR, "não achei o início do HTML em remarketing.php\n");
    exit(1);
}
$lista = str_replace(["<?php include 'tema_inline.php'; ?>", "<?php include 'barra_lateral.php'; ?>"], [$cabeca_tema, $trilho_falso], '<?php ' . substr($fonte, $pos));
file_put_contents($saida . '/lista.html', renderizar($lista, 'remarketing.js', $aqui, $saida, $raiz));

// --- assistente (novo e edição): remarketing_nova.php sem o cabeçalho de sessão ---
$fonte = (string) file_get_contents($raiz . '/remarketing_nova.php');
$nova = preg_replace('/^<\?php\s+declare\(strict_types=1\);.*?bloquearAdmin\(\);/s', '<?php ', $fonte, 1);
$nova = str_replace("<?php include 'tema_inline.php'; ?>", $cabeca_tema, $nova);
foreach (['assistente.html' => 0, 'assistente_edicao.html' => 5] as $arquivo => $id) {
    $_GET['id'] = (string) $id;
    file_put_contents($saida . '/' . $arquivo, renderizar($nova, 'remarketing_nova.js', $aqui, $saida, $raiz));
}

echo $saida . PHP_EOL;
