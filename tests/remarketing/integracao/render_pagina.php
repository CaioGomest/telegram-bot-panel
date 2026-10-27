<?php
// Renderiza uma página do painel (remarketing_detalhes) na CÓPIA, logado como um usuário comum, com banco de verdade e a barra lateral trocada por um trilho vazio.
// Uso: php render_pagina.php <arquivo.php> <id do usuário> '<query em JSON>' <saida.html>
$app = getenv('RM_APP');
function arg(string $v): string { return ($v !== '' && $v[0] === '@') ? (string) file_get_contents(substr($v, 1)) : $v; }   // no Windows o escapeshellarg troca aspas por espaço: JSON vai por arquivo (@caminho)
chdir($app);
$arquivo = $argv[1];
$uid = (int) $argv[2];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = '/' . $arquivo;
$_SERVER['REQUEST_URI'] = '/' . basename($arquivo, '.php');
$_SERVER['HTTP_HOST'] = 'localhost';
$_GET = json_decode(arg($argv[3] ?? '{}'), true) ?: [];
require $app . '/funcoes/usuario.php';
$_SESSION['usuario_id'] = $uid;
$_SESSION['usuario_perfil'] = 'usuario';
$fonte = file_get_contents($app . '/' . $arquivo);
if (($argv[5] ?? '') !== 'real') {   // 'real' = com a barra lateral e o tema_inline de verdade
$fonte = str_replace(
    ["<?php include 'tema_inline.php'; ?>", "<?php include 'barra_lateral.php'; ?>"],
    ['<script>document.documentElement.classList.add("layout-a");document.documentElement.setAttribute("data-theme","dark");</script><link rel="stylesheet" href="assets/css/layout_a.css">', '<aside class="a-rail"></aside>'],
    $fonte
);
}
$fonte = preg_replace('/^<\?php\s+declare\(strict_types=1\);/', '<?php ', $fonte, 1);
$tmp = $app . '/__render.php';
file_put_contents($tmp, $fonte);
ob_start();
include $tmp;
$html = ob_get_clean();
@unlink($tmp);
file_put_contents($argv[4], str_replace('<head>', '<head><base href="file:///' . str_replace('\\', '/', $app) . '/">', $html));
echo strlen($html), " bytes\n";
