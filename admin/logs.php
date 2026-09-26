<?php
declare(strict_types=1);

require_once __DIR__ . '/../funcoes/usuario.php';
require_once __DIR__ . '/../funcoes/log.php';
require_once __DIR__ . '/../funcoes/paginador.php';

verificarAdmin();
$caminho_base = '../';

$filtros = ['excluir_tipos' => ['venda', 'lead']];

$pagina_atual = isset($_GET['pagina']) ? max(1, (int)$_GET['pagina']) : 1;
$por_pagina = 20;
$offset = ($pagina_atual - 1) * $por_pagina;

$total_logs = contarAtividades($filtros);
$logs = listarAtividades($filtros, $por_pagina, $offset);

// Pedido do JS querendo só a lista: devolve o parcial e para aqui, sem montar
// cabeçalho, menu e o resto da página.
if (pedidoDeBloco('logs')) {
    include __DIR__ . '/../parciais/lista_logs.php';
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logs do Sistema - <?php echo htmlspecialchars(nomeSistema()); ?></title>
    <?php include __DIR__ . '/../tema_inline.php'; ?>
    <link rel="stylesheet" href="../assets/css/coyote.css?v=<?php echo @filemtime(__DIR__.'/../assets/css/coyote.css'); ?>">
</head>
<body>
<div class="layout-painel">
    <?php include __DIR__ . '/../barra_lateral.php'; ?>

    <main class="conteudo-principal">
        <div class="cabecalho-pagina">
            <div>
                <h1>Logs do Sistema</h1>
                <p>Histórico de atividades e eventos da plataforma.</p>
            </div>
            <div class="acoes-cabecalho">
                <button type="button" class="alternador-tema" onclick="alternarTema()" aria-label="Alternar tema">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"></path></svg>
                    Tema
                </button>
            </div>
        </div>


        <div class="painel">
            <?php echo inicioBlocoPaginado('logs'); ?>
            <?php include __DIR__ . '/../parciais/lista_logs.php'; ?>
            <?php echo fimBlocoPaginado(); ?>
        </div>
    </main>
</div>

<script src="../assets/js/tema.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/tema.js'); ?>"></script>
<script src="../assets/js/paginacao.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/paginacao.js'); ?>"></script>
</body>
</html>
