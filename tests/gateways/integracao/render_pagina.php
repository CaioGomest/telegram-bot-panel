<?php
// Renderiza uma página do painel na CÓPIA, logado como usuário comum ou admin, com banco de verdade. Uso:
//   php render_pagina.php <arquivo.php> <id do usuário> <usuario|admin> <saida.html>
// A barra lateral e o tema viram um trilho vazio + layout_a.css (a página de gateways é o que se quer ver, não o menu).
$app = (string) getenv('GW_APP');
chdir($app);
$arquivo = $argv[1];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = '/' . $arquivo;
$_SERVER['REQUEST_URI'] = '/' . basename($arquivo, '.php');
$_SERVER['HTTP_HOST'] = 'localhost';
$_GET = [];
require $app . '/funcoes/usuario.php';
$_SESSION['usuario_id'] = (int) $argv[2];
$_SESSION['usuario_perfil'] = $argv[3];
$fonte = file_get_contents($app . '/' . $arquivo);
$fonte = str_replace(
    ["<?php include 'tema_inline.php'; ?>", "<?php include 'barra_lateral.php'; ?>"],
    ['<script>document.documentElement.classList.add("layout-a");document.documentElement.setAttribute("data-theme","dark");</script><link rel="stylesheet" href="assets/css/layout_a.css">', '<aside class="a-rail"></aside>'],
    $fonte
);
$fonte = preg_replace('/^<\?php\s+declare\(strict_types=1\);/', '<?php ', $fonte, 1);
$tmp = $app . '/__render.php';
file_put_contents($tmp, $fonte);
ob_start();
include $tmp;
$html = ob_get_clean();
@unlink($tmp);
file_put_contents($argv[4], str_replace('<head>', '<head><base href="file:///' . str_replace('\\', '/', $app) . '/">', $html));
echo strlen($html), " bytes\n";
