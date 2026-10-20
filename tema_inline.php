<?php
// Layout A (casca nova): só nas páginas listadas em funcoes/layout_a.php. A classe vai no <html> antes de o corpo ser
// desenhado (sem piscar a casca antiga) e o CSS entra aqui porque este é o único include presente no <head> de todas as páginas.
require_once __DIR__ . '/funcoes/layout_a.php';
$layout_a = layoutAAtivo($caminho_base ?? '');
?>
<script>(function(){<?php if ($layout_a): ?>document.documentElement.classList.add('layout-a');<?php endif; ?>try{var t=localStorage.getItem('tema')||'dark';document.documentElement.setAttribute('data-theme',t);if(localStorage.getItem('barra_fixa')==='1'){document.documentElement.classList.add('barra-fixa');}}catch(e){}})();</script>
<?php if ($layout_a): ?>
<link rel="stylesheet" href="<?php echo htmlspecialchars($caminho_base ?? ''); ?>assets/css/layout_a.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/layout_a.css'); ?>">
<?php endif; ?>

<?php
// Identidade visual vem do banco (white-label). Aqui no <head> porque é o único include
// presente em todas as páginas -- e porque o CSS não consegue ler o banco: a logo do
// cabeçalho mobile é aplicada por var(--logo-url). $caminho_base já vale "../" nas
// páginas dentro de admin/.
$marca_base = $caminho_base ?? '';
if (function_exists('faviconSistema')):
?>
<link rel="icon" href="<?php echo htmlspecialchars(urlMarca(faviconSistema($marca_base))); ?>">
<style>:root { --logo-url: url("<?php echo htmlspecialchars(urlMarca(logoSistema($marca_base))); ?>"); }</style>
<?php
// Cor de destaque (white-label): --or/--or2/--orsoft já existem no painel.css, dentro de
// html[data-theme="dark"]/[="light"] -- que é carregado DEPOIS deste include no <head>. Com
// a mesma especificidade, quem vem depois no documento vence, então um :root simples perderia
// pro painel.css. html[data-theme]:root tem especificidade maior que os dois blocos de tema,
// então sobrescreve os dois de uma vez, independente da ordem de carregamento.
$cor_primaria = corPrimariaSistema();
?>
<style>
html[data-theme]:root {
    --or: <?php echo htmlspecialchars($cor_primaria); ?>;
    --or2: <?php echo htmlspecialchars(corClareada($cor_primaria)); ?>;
    --orsoft: <?php echo htmlspecialchars(corSuave($cor_primaria)); ?>;
}
</style>
<?php endif; ?>
