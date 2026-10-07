<?php
declare(strict_types=1);

// Página pública aberta pelo link do e-mail de confirmação (não exige login).
require_once __DIR__ . '/funcoes/usuario.php';
require_once __DIR__ . '/funcoes/verificacao_email.php';

$token = is_string($_GET['token'] ?? null) ? $_GET['token'] : '';
$resultado = confirmarEmailPorToken($token);
$destino = usuarioLogado() ? 'index' : 'login';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>Confirmar e-mail - <?php echo htmlspecialchars(nomeSistema()); ?></title>
    <?php include 'tema_inline.php'; ?>
    <link rel="stylesheet" href="assets/css/painel.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/painel.css'); ?>">
</head>
<body>
<div style="min-height:100vh;display:grid;place-items:center;padding:24px;">
    <div class="painel" style="max-width:440px;width:100%;text-align:center;padding:36px 28px;">
        <img src="<?php echo htmlspecialchars(logoSistema()); ?>" alt="<?php echo htmlspecialchars(nomeSistema()); ?>" style="max-height:44px;margin-bottom:20px;">
        <div style="width:56px;height:56px;margin:0 auto 16px;border-radius:50%;display:grid;place-items:center;font-size:28px;background:var(<?php echo $resultado['sucesso'] ? '--oksoft' : '--dasoft'; ?>);color:var(<?php echo $resultado['sucesso'] ? '--ok' : '--da'; ?>);">
            <?php echo $resultado['sucesso'] ? '✓' : '!'; ?>
        </div>
        <h1 style="font-size:24px;margin-bottom:10px;"><?php echo $resultado['sucesso'] ? 'Tudo certo' : 'Não deu certo'; ?></h1>
        <p style="margin:0 0 24px;line-height:1.6;"><?php echo htmlspecialchars($resultado['mensagem']); ?></p>
        <a href="<?php echo $destino; ?>" class="botao botao-primario"><?php echo usuarioLogado() ? 'Ir para o painel' : 'Entrar'; ?></a>
    </div>
</div>
</body>
</html>
