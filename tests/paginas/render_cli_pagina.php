<?php
declare(strict_types=1);

/**
 * Gera o HTML de UMA página do painel como um usuário logado, direto pela linha de comando (sem navegador, sem senha). Só leitura: serve para conferir, no
 * servidor, o que a página entrega (menu, período padrão, classes). NÃO use em página que grava ao abrir (ex.: admin/atualiza_banco.php roda a migração).
 *   sudo -u www-data php tests/paginas/render_cli_pagina.php <pagina, ex.: index.php ou admin/transacoes.php> <id do usuário> <admin|usuario> [consulta, ex.: periodo=30dias] [saida.html]
 * A raiz é a pasta do painel (dois níveis acima deste arquivo) ou a da variável RAIZ_PAINEL. Sessões ficam em /tmp/sess_render_cli.
 */
$pagina = $argv[1] ?? '';
$id_usuario = (int) ($argv[2] ?? 0);
$perfil = ($argv[3] ?? 'usuario') === 'admin' ? 'admin' : 'usuario';
parse_str((string) ($argv[4] ?? ''), $_GET);
$saida = $argv[5] ?? '';
if ($pagina === '' || $id_usuario <= 0) {
    fwrite(STDERR, "uso: php render_cli_pagina.php <pagina> <id do usuario> <admin|usuario> [saida.html]\n");
    exit(2);
}
$raiz = rtrim((string) (getenv("RAIZ_PAINEL") ?: dirname(__DIR__, 2)), "/");
@mkdir('/tmp/sess_render_cli', 0700, true);
ini_set('session.save_path', '/tmp/sess_render_cli');
ini_set('display_errors', '0');
$_SERVER['HTTPS'] = 'on';
$_SERVER['HTTP_HOST'] = 'coyotebot.com.br';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['SCRIPT_NAME'] = '/' . $pagina;
$_SERVER['PHP_SELF'] = '/' . $pagina;
$_SERVER['REQUEST_URI'] = '/' . $pagina;
$_SERVER['SCRIPT_FILENAME'] = $raiz . '/' . $pagina;
ob_start();
session_start();
$_SESSION['usuario_id'] = $id_usuario;
$_SESSION['usuario_nome'] = 'Teste';
$_SESSION['usuario_email'] = 'teste@teste.invalid';
$_SESSION['usuario_perfil'] = $perfil;
chdir(dirname($raiz . '/' . $pagina));
try {
    include $raiz . '/' . $pagina;
} catch (Throwable $e) {
    echo "\n<!-- ERRO: " . $e->getMessage() . ' em ' . basename($e->getFile()) . ':' . $e->getLine() . " -->\n";
}
$html = ob_get_clean();
if ($saida !== '') {
    file_put_contents($saida, $html);
}
echo $html;
