<?php
declare(strict_types=1);

/**
 * Gera uma página estática do editor do Fluxo Guiado (index.html) a partir do fluxo_basico.php de verdade, com stubs no lugar do que depende de
 * sessão e banco. A página usa api_falsa.js como API; nada toca servidor, banco ou Telegram. Só lê o projeto.
 *   php tests/guiado/ui_editor/gerar.php <pasta de saída> [dark|light]        (quem chama é teste.js)
 */

$raiz = str_replace(chr(92), '/', dirname(__DIR__, 3));
$aqui = str_replace(chr(92), '/', __DIR__);
$saida = str_replace(chr(92), '/', $argv[1] ?? sys_get_temp_dir() . '/editor_guiado_ui');
if (!is_dir($saida)) {
    mkdir($saida, 0777, true);
}

$fonte = (string) file_get_contents($raiz . '/fluxo_basico.php');
$fonte = preg_replace('/^<\?php declare\(strict_types=1\);.*?\?>/s', '', $fonte, 1);   // some o require_once e o bloquearAdmin()
$fonte = str_replace("<?php include 'tema_inline.php'; ?>", '', $fonte);
function nomeSistema(): string
{
    return 'Teste';
}
function csrfToken(): string
{
    return '';
}
function iconeAcao(string $nome): string
{
    return '<svg viewBox="0 0 24 24"><path d="M3 6h18"/></svg>';
}
// tema (o painel.css só tem as cores sob html[data-theme]; na página de verdade quem põe isso é o tema_inline.php): escuro, ou claro com o 2º argumento
$tema = ($argv[2] ?? 'dark') === 'light' ? 'light' : 'dark';
$fonte = str_replace('<html lang="pt-BR">', '<html lang="pt-BR" data-theme="' . $tema . '" class="barra-fixa sidebar-modelo">', $fonte);
// <base> faz o CSS e o JS do projeto carregarem de file:// ; a API simulada entra antes do JS do editor
$fonte = str_replace('<head>', '<head><base href="file:///' . $raiz . '/">', $fonte);
$fonte = str_replace('<script src="assets/edicao_fluxo_basico.js', '<script src="file:///' . $aqui . '/api_falsa.js"></script><script src="assets/edicao_fluxo_basico.js', $fonte);
ob_start();
eval('?>' . $fonte);
file_put_contents($saida . '/index.html', ob_get_clean());
echo $saida . '/index.html' . PHP_EOL;
