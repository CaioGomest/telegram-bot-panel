<?php
if (!isset($pdo)) {
    require_once 'conexao.php';
}

try {
    $stmt = $pdo->query("SELECT * FROM menus WHERE ativo = 1 ORDER BY ordem ASC");
    $todos_menus = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $is_admin = function_exists('ehAdmin') ? ehAdmin() : false;
    $menus = [];
    foreach ($todos_menus as $m) {
        if ($m['apenas_admin'] && !$is_admin) {
            continue;
        }
        $menus[] = $m;
    }
} catch (Exception $e) {
    $menus = [];
}

$pagina_atual = basename($_SERVER['PHP_SELF']);

function getIcone($nome) {
    $icones = [
        'dashboard' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9"></rect><rect x="14" y="3" width="7" height="5"></rect><rect x="14" y="12" width="7" height="9"></rect><rect x="3" y="16" width="7" height="5"></rect></svg>',
        'bot' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="2" width="20" height="8" rx="2" ry="2"></rect><rect x="2" y="14" width="20" height="8" rx="2" ry="2"></rect><line x1="6" y1="6" x2="6.01" y2="6"></line><line x1="6" y1="18" x2="6.01" y2="18"></line></svg>',
        'flow' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>',
        'users' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>',
        'leads' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="8.5" cy="7" r="4"></circle><polyline points="17 11 19 13 23 9"></polyline></svg>',
        'payment' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2" ry="2"></rect><line x1="2" y1="10" x2="22" y2="10"></line></svg>',
        'tracking' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>',
        'settings' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>',
        'logout' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>'
    ];
    return $icones[$nome] ?? '';
}
?>

<aside class="sidebar">
    <div class="sidebar-header">
        <h2>Painel</h2>
    </div>
    <nav class="sidebar-nav">
        <ul>
            <?php
            $dashboard_link = 'index.php';
            if (ehAdmin()) {
                $dashboard_link = 'admin_dashboard.php';
            }
            ?>
            <li>
                <a href="<?php echo $dashboard_link; ?>" class="<?php echo basename($_SERVER['PHP_SELF']) == basename($dashboard_link) ? 'active' : ''; ?>">
                    <span class="icon"><?php echo getIcone('dashboard'); ?></span>
                    <span class="text">Dashboard</span>
                </a>
            </li>
            
            <?php if (!ehAdmin()): ?>
            <li>
                <a href="bots.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'bots.php' ? 'active' : ''; ?>">
                    <span class="icon"><?php echo getIcone('bot'); ?></span>
                    <span class="text">Meus Bots</span>
                </a>
            </li>
            
            <li>
                <a href="fluxos.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'fluxos.php' ? 'active' : ''; ?>">
                    <span class="icon"><?php echo getIcone('flow'); ?></span>
                    <span class="text">Fluxos</span>
                </a>
            </li>
            
            <li>
                <a href="leads.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'leads.php' ? 'active' : ''; ?>">
                    <span class="icon"><?php echo getIcone('leads'); ?></span>
                    <span class="text">Leads</span>
                </a>
            </li>
            <li>
                <a href="remarketing.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'remarketing.php' ? 'active' : ''; ?>">
                    <span class="icon"><?php echo getIcone('leads'); ?></span>
                    <span class="text">Remarketing</span>
                </a>
            </li>
            <li>
                <a href="traqueamento.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'traqueamento.php' ? 'active' : ''; ?>">
                    <span class="icon"><?php echo getIcone('tracking'); ?></span>
                    <span class="text">Traqueamento</span>
                </a>
            </li>
            <li>
                <a href="links_rastreamento.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'links_rastreamento.php' ? 'active' : ''; ?>">
                    <span class="icon"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg></span>
                    <span class="text">Links de Rastreamento</span>
                </a>
            </li>
            <?php endif; ?>

            <!-- Exibir Gateways para todos (Admin vê global, User vê config pessoal) -->
            <li>
                <a href="gateways.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'gateways.php' ? 'active' : ''; ?>">
                    <span class="icon"><?php echo getIcone('payment'); ?></span>
                    <span class="text">Gateways</span>
                </a>
            </li>

            <?php if (ehAdmin()): ?>
                <li>
                    <a href="usuarios.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'usuarios.php' ? 'active' : ''; ?>">
                        <span class="icon"><?php echo getIcone('users'); ?></span>
                        <span class="text">Usuários</span>
                    </a>
                </li>
                <li>
                    <a href="admin_testes_pagamento.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'admin_testes_pagamento.php' ? 'active' : ''; ?>">
                        <span class="icon"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"></path><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path></svg></span>
                        <span class="text">Testes de Pagamento</span>
                    </a>
                </li>
                <li>
                    <a href="logs.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'logs.php' ? 'active' : ''; ?>">
                        <span class="icon"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg></span>
                        <span class="text">Logs</span>
                    </a>
                </li>
            <?php endif; ?>

            <li>
                <a href="configuracao_usuario.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'configuracao_usuario.php' ? 'active' : ''; ?>">
                    <span class="icon"><?php echo getIcone('settings'); ?></span>
                    <span class="text">Minha Conta</span>
                </a>
            </li>
        </ul>
    </nav>
    
    <div class="sidebar-footer">
        <a href="logout.php" class="logout-link">
            <span class="icon"><?php echo getIcone('logout'); ?></span>
            <span class="text">Sair</span>
        </a>
    </div>
</aside>
