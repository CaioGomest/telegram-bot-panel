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
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logs do Sistema</title>
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

        <div class="somente-desktop-aviso ativo-mobile">
            <h2>Melhor no desktop</h2>
            <p>A tabela de logs funciona melhor em uma tela maior.</p>
        </div>

        <div class="painel oculto-mobile">
            <div class="tabela-dados">
                <table>
                    <thead>
                        <tr>
                            <th>Data/Hora</th>
                            <th>Usuário</th>
                            <th>Tipo</th>
                            <th>Evento</th>
                            <th>Descrição</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                        <tr>
                            <td class="mono texto-suave"><?php echo date('d/m/Y H:i:s', strtotime($log['criado_em'])); ?></td>
                            <td>
                                <?php if ($log['nome_usuario']): ?>
                                    <?php echo htmlspecialchars($log['nome_usuario']); ?>
                                <?php else: ?>
                                    <span class="texto-suave">Sistema</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $classe = 'badge-neutro';
                                if ($log['tipo'] === 'venda') $classe = 'badge-sucesso';
                                if ($log['tipo'] === 'lead') $classe = 'badge-alerta';
                                ?>
                                <span class="badge <?php echo $classe; ?>"><?php echo strtoupper($log['tipo']); ?></span>
                            </td>
                            <td><?php echo htmlspecialchars($log['titulo']); ?></td>
                            <td class="texto-suave"><?php echo htmlspecialchars($log['descricao']); ?></td>
                        </tr>
                        <?php endforeach; ?>

                        <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="5" style="text-align: center; padding: 40px; color: var(--m);">Nenhum registro encontrado.</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php echo paginador($total_logs, $por_pagina); ?>
        </div>
    </main>
</div>

<script src="../assets/js/tema.js"></script>
</body>
</html>
