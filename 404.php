<?php
declare(strict_types=1);

http_response_code(404);

$nome = 'Painel';
$tema = __DIR__ . '/tema_inline.php';
if (is_file(__DIR__ . '/funcoes/configuracoes.php') && is_file(__DIR__ . '/config.php')) {
    try {
        require_once __DIR__ . '/funcoes/configuracoes.php';
        if (function_exists('nomeSistema')) {
            $nome = nomeSistema();
        }
    } catch (Throwable $e) {
        $nome = 'Painel';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Página não encontrada - <?php echo htmlspecialchars($nome); ?></title>
    <?php if (is_file($tema)) { include $tema; } ?>
    <?php if (is_file(__DIR__ . '/assets/css/coyote.css')): ?>
    <link rel="stylesheet" href="/assets/css/coyote.css?v=<?php echo (int) @filemtime(__DIR__ . '/assets/css/coyote.css'); ?>">
    <?php endif; ?>
</head>
<body>
    <div class="painel" style="max-width:480px;margin:12vh auto;padding:28px;">
        <h1 style="margin:0 0 8px;font-size:28px;">Página não encontrada</h1>
        <p class="texto-suave" style="margin:0 0 20px;">Esse endereço não existe neste painel.</p>
        <a class="botao botao-primario" href="/login">Ir para o login</a>
    </div>
</body>
</html>
