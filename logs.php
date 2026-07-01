<?php
declare(strict_types=1);

require_once __DIR__ . '/funcoes/usuario.php';
require_once __DIR__ . '/funcoes/log.php';
require_once __DIR__ . '/funcoes/paginador.php';

verificarAdmin();

$filtros = ['excluir_tipos' => ['venda', 'lead']];

// Configuração da Paginação
$pagina_atual = isset($_GET['pagina']) ? max(1, (int)$_GET['pagina']) : 1;
$por_pagina = 20; // 20 logs por página
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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/app.css">
    <style>
        .table-responsive { overflow-x: auto; }
        .table { width: 100%; border-collapse: collapse; }
        .table th, .table td { padding: 12px 16px; text-align: left; border-bottom: 1px solid var(--border); }
        .table th { font-weight: 600; color: var(--muted); font-size: 13px; background: #f8fafc; }
        .table td { font-size: 14px; color: var(--text); }
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 500; }
        .badge-lead { background: #dbeafe; color: #1e40af; }
        .badge-venda { background: #dcfce7; color: #166534; }
        .badge-user { background: #f3f4f6; color: #374151; }
        .text-muted { color: var(--muted); font-size: 13px; }
    </style>
</head>
<body>
<div class="dashboard-layout">
    <?php include 'sidebar.php'; ?>
    
    <main class="main-content">
        <div class="cabecalho-pagina">
            <div>
                <h1>Logs do Sistema</h1>
                <p>Histórico de atividades e eventos da plataforma.</p>
            </div>
        </div>

        <div class="painel">
            <div class="table-responsive">
                <table class="table">
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
                            <td class="text-muted"><?php echo date('d/m/Y H:i:s', strtotime($log['criado_em'])); ?></td>
                            <td>
                                <?php if ($log['nome_usuario']): ?>
                                    <span style="font-weight: 500;"><?php echo htmlspecialchars($log['nome_usuario']); ?></span>
                                <?php else: ?>
                                    <span class="text-muted">Sistema</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $classe = 'badge-user';
                                if ($log['tipo'] === 'venda') $classe = 'badge-venda';
                                if ($log['tipo'] === 'lead') $classe = 'badge-lead';
                                ?>
                                <span class="badge <?php echo $classe; ?>"><?php echo strtoupper($log['tipo']); ?></span>
                            </td>
                            <td style="font-weight: 500;"><?php echo htmlspecialchars($log['titulo']); ?></td>
                            <td class="text-muted"><?php echo htmlspecialchars($log['descricao']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        
                        <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="5" style="text-align: center; padding: 40px; color: var(--muted);">Nenhum registro encontrado.</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <?php echo paginador($total_logs, $por_pagina); ?>
        </div>
    </main>
</div>
</body>
</html>
