<?php
declare(strict_types=1);

require_once __DIR__ . '/funcoes/usuario.php';
require_once __DIR__ . '/funcoes/comunidade.php';
bloquearAdmin();

$links = [];
$disponivel = comunidadeDisponivel();
if ($disponivel) {
    $links = listarLinksComunidade(true);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Comunidade</title>
    <?php include 'tema_inline.php'; ?>
    <link rel="stylesheet" href="assets/css/coyote.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/coyote.css'); ?>">
</head>
<body>
<div class="layout-painel">
    <?php include 'barra_lateral.php'; ?>

    <main class="conteudo-principal">
        <div class="cabecalho-pagina">
            <div>
                <h1>Comunidade</h1>
                <p>Grupos, canais e redes oficiais da plataforma.</p>
            </div>
            <div class="acoes-cabecalho">
                <button type="button" class="alternador-tema" onclick="alternarTema()" aria-label="Alternar tema">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"></path></svg>
                    Tema
                </button>
            </div>
        </div>

        <div class="comunidade-coluna">
            <div class="comunidade-hero">
                <img src="<?php echo htmlspecialchars(logoSistema()); ?>" alt="" onerror="this.style.display='none'">
                <p>Canais oficiais de <?php echo htmlspecialchars(nomeSistema()); ?></p>
            </div>

            <?php if (!$disponivel || !$links): ?>
                <div class="estado-vazio">
                    <h3>Nenhum link publicado</h3>
                    <p>Quando a equipe publicar os canais da comunidade, eles aparecem aqui.</p>
                </div>
            <?php else: ?>
                <?php renderizarCardsComunidade($links); ?>
            <?php endif; ?>
        </div>
    </main>
</div>

<script src="assets/js/tema.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/tema.js'); ?>"></script>
</body>
</html>
