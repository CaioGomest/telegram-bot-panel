<?php
declare(strict_types=1);

/**
 * Envolve a saida (ja gerada via echo/print_r) de uma rotina de manutencao/debug
 * no layout novo do painel, sem mudar nada do que a rotina calcula ou grava no banco.
 */
function exibirRelatorioDebug(string $titulo, string $conteudo_html, string $caminho_base = ''): void
{
    ?>
    <!DOCTYPE html>
    <html lang="pt-BR">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo htmlspecialchars($titulo); ?></title>
        <?php include __DIR__ . '/../tema_inline.php'; ?>
        <link rel="stylesheet" href="<?php echo $caminho_base; ?>assets/css/coyote.css">
    </head>
    <body>
    <div class="layout-painel">
        <?php include __DIR__ . '/../barra_lateral.php'; ?>
        <main class="conteudo-principal">
            <div class="cabecalho-pagina">
                <div>
                    <h1><?php echo htmlspecialchars($titulo); ?></h1>
                    <p>Saída da rotina de manutenção.</p>
                </div>
            </div>
            <div class="painel">
                <div class="saida-debug"><?php echo $conteudo_html; ?></div>
            </div>
        </main>
    </div>
    </body>
    </html>
    <?php
}
