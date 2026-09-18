<?php
declare(strict_types=1);

// Páginas dentro de admin/ definem $caminho_base = '../' antes de incluir este
// arquivo, pra todo link/asset abaixo continuar apontando pro lugar certo.
$caminho_base = $caminho_base ?? '';

if (!isset($pdo)) {
    require_once __DIR__ . '/conexao.php';
}

$is_admin = function_exists('ehAdmin') ? ehAdmin() : false;
$pagina_atual = basename($_SERVER['PHP_SELF']);
$user_id = $_SESSION['usuario_id'] ?? 0;

$badge_bots = null;
$badge_leads = null;
if (!$is_admin && $user_id) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM bots WHERE id_usuario = ?");
        $stmt->execute([$user_id]);
        $badge_bots = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE bot_id IN (SELECT id FROM bots WHERE id_usuario = ?)");
        $stmt->execute([$user_id]);
        $badge_leads = (int) $stmt->fetchColumn();
    } catch (Exception $e) {
        $badge_bots = null;
        $badge_leads = null;
    }
}

function iconeNav(string $d, string $viewBox = '0 0 24 24'): string
{
    return '<svg width="17" height="17" viewBox="' . $viewBox . '" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="' . $d . '"></path></svg>';
}

$icones = [
    'dashboard' => 'M4 13h6V4H4zM14 20h6V11h-6zM4 20h6v-4H4zM14 8h6V4h-6z',
    'bots' => 'M12 3v3M7 9h10a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-6a2 2 0 0 1 2-2zM9 14h.01M15 14h.01',
    'fluxos' => 'M6 4v6a4 4 0 0 0 4 4h8M18 10l3 4-3 4',
    'leads' => 'M16 19v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2M9.5 8.5a3 3 0 1 0 0-.1zM19 19v-2a4 4 0 0 0-3-3.8',
    'ranking' => 'M8 4h8v4a4 4 0 0 1-8 0zM8 6H5a3 3 0 0 0 3 3M16 6h3a3 3 0 0 1-3 3M10 12v3h4v-3M8 20h8M12 15v5',
    'gateways' => 'M3 7h18v11H3zM3 11h18',
    'conta' => 'M12 15a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM19.4 15a7.5 7.5 0 0 0 .1-1l1.8-1.3-1.9-3.3-2.1.8a7.5 7.5 0 0 0-1.7-1L15.3 6h-3.8l-.3 2.2a7.5 7.5 0 0 0-1.7 1l-2.1-.8-1.9 3.3L7.3 14a7.5 7.5 0 0 0 0 2l-1.8 1.3 1.9 3.3 2.1-.8',
    'visao_geral' => 'M3 3v18h18M7 15l4-5 3 3 4-6',
    'transacoes' => 'M4 7h16l-3-3M20 17H4l3 3',
    'logs' => 'M8 4h9l3 3v13H8zM5 8v12h3M11 12h5M11 16h5',
    'remarketing' => 'M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2M8.5 7a4 4 0 1 0 0 8M17 11l2 2 4-4',
    'traqueamento' => 'M22 12h-4l-3 9L9 3l-3 9H2',
    'links' => 'M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71',
    'usuarios' => 'M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75',
    'debug' => 'M12 8v8M8 12h8M4 4h16v16H4z',
    'sair' => 'M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9',
];

$grupo_operacao = [];
if (!$is_admin) {
    $grupo_operacao[] = ['href' => 'index.php', 'label' => 'Dashboard', 'icone' => 'dashboard'];
    $grupo_operacao[] = ['href' => 'bots.php', 'label' => 'Meus Bots', 'icone' => 'bots', 'badge' => $badge_bots];
    $grupo_operacao[] = ['href' => 'fluxos.php', 'label' => 'Fluxos', 'icone' => 'fluxos'];
    $grupo_operacao[] = ['href' => 'leads.php', 'label' => 'Leads', 'icone' => 'leads', 'badge' => $badge_leads];
    $grupo_operacao[] = ['href' => 'ranking.php', 'label' => 'Ranking', 'icone' => 'ranking', 'badge_texto' => 'NOVO'];
    $grupo_operacao[] = ['href' => 'remarketing.php', 'label' => 'Remarketing', 'icone' => 'remarketing'];
    $grupo_operacao[] = ['href' => 'traqueamento.php', 'label' => 'Traqueamento', 'icone' => 'traqueamento'];
    $grupo_operacao[] = ['href' => 'links_rastreamento.php', 'label' => 'Links de Rastreamento', 'icone' => 'links'];
}
$grupo_operacao[] = ['href' => 'gateways.php', 'label' => 'Gateways', 'icone' => 'gateways'];
$grupo_operacao[] = ['href' => 'configuracao_usuario.php', 'label' => 'Minha Conta', 'icone' => 'conta'];

$grupo_admin = [];
if ($is_admin) {
    $grupo_admin[] = ['href' => 'admin/dashboard.php', 'label' => 'Visão Geral', 'icone' => 'visao_geral'];
    $grupo_admin[] = ['href' => 'admin/transacoes.php', 'label' => 'Transações', 'icone' => 'transacoes'];
    $grupo_admin[] = ['href' => 'admin/logs.php', 'label' => 'Logs', 'icone' => 'logs'];
    $grupo_admin[] = ['href' => 'admin/usuarios.php', 'label' => 'Usuários', 'icone' => 'usuarios'];
    $grupo_admin[] = ['href' => 'admin/ranking.php', 'label' => 'Campanhas de Ranking', 'icone' => 'ranking'];
}

$grupo_debug = [];
if ($is_admin) {
    $grupo_debug[] = ['href' => 'admin/atualiza_banco.php', 'label' => 'Atualizar Banco'];
    $grupo_debug[] = ['href' => 'admin/consultar_venda.php', 'label' => 'Consultar Venda'];
}

function renderizarItemNav(array $item, string $pagina_atual, array $icones, string $caminho_base): void
{
    $ativo = basename($item['href']) === $pagina_atual;
    $icone_svg = isset($item['icone']) ? iconeNav($icones[$item['icone']]) : iconeNav($icones['debug']);
    echo '<a href="' . $caminho_base . htmlspecialchars($item['href']) . '" class="nav-item' . ($ativo ? ' ativo' : '') . '">';
    echo '<span class="nav-icone">' . $icone_svg . '</span>';
    echo '<span class="nav-texto">' . htmlspecialchars($item['label']) . '</span>';
    if (!empty($item['badge_texto'])) {
        echo '<span class="nav-badge">' . htmlspecialchars($item['badge_texto']) . '</span>';
    } elseif (isset($item['badge']) && $item['badge'] !== null) {
        echo '<span class="nav-badge">' . (int) $item['badge'] . '</span>';
    }
    echo '</a>';
}

$nome_usuario = $_SESSION['usuario_nome'] ?? 'Usuário';
$email_usuario = $_SESSION['usuario_email'] ?? '';
$iniciais = mb_strtoupper(mb_substr($nome_usuario, 0, 1), 'UTF-8');
?>
<aside class="barra-lateral">
    <div class="barra-lateral-topo">
        <img src="<?php echo $caminho_base; ?>assets/img/coyote-logo.jpg" alt="" class="logo-marca" onerror="this.style.display='none'">
        <div class="logo-textos">
            <span class="logo-titulo">Coyote Bot</span>
            <span class="logo-subtitulo">Painel de automação</span>
        </div>
    </div>

    <nav class="nav-lateral">
        <div class="nav-grupo">
            <span class="nav-grupo-titulo">Operação</span>
            <?php foreach ($grupo_operacao as $item) {
                renderizarItemNav($item, $pagina_atual, $icones, $caminho_base);
            } ?>
        </div>

        <?php if ($grupo_admin): ?>
        <div class="nav-grupo">
            <span class="nav-grupo-titulo">Administração</span>
            <?php foreach ($grupo_admin as $item) {
                renderizarItemNav($item, $pagina_atual, $icones, $caminho_base);
            } ?>
        </div>
        <?php endif; ?>

        <?php if ($grupo_debug): ?>
        <div class="nav-grupo">
            <span class="nav-grupo-titulo">Debug</span>
            <?php foreach ($grupo_debug as $item) {
                renderizarItemNav($item, $pagina_atual, $icones, $caminho_base);
            } ?>
        </div>
        <?php endif; ?>
    </nav>

    <div class="barra-lateral-rodape">
        <div class="usuario-rodape">
            <span class="usuario-avatar"><?php echo htmlspecialchars($iniciais); ?></span>
            <div class="usuario-info">
                <span class="usuario-nome"><?php echo htmlspecialchars($nome_usuario); ?></span>
                <span class="usuario-email"><?php echo htmlspecialchars($email_usuario); ?></span>
            </div>
        </div>
        <a href="<?php echo $caminho_base; ?>logout.php" class="botao-sair" title="Sair" aria-label="Sair">
            <?php echo iconeNav($icones['sair']); ?>
        </a>
    </div>
</aside>

<nav class="barra-mobile">
    <?php
    $itens_mobile = [
        ['href' => $is_admin ? 'admin/dashboard.php' : 'index.php', 'label' => 'Início', 'icone' => 'dashboard'],
        ['href' => 'bots.php', 'label' => 'Bots', 'icone' => 'bots'],
        ['href' => 'fluxos.php', 'label' => 'Fluxos', 'icone' => 'fluxos'],
        ['href' => 'leads.php', 'label' => 'Leads', 'icone' => 'leads'],
        ['href' => 'ranking.php', 'label' => 'Ranking', 'icone' => 'ranking'],
        ['href' => 'configuracao_usuario.php', 'label' => 'Conta', 'icone' => 'conta'],
    ];
    foreach ($itens_mobile as $item):
        $ativo = basename($item['href']) === $pagina_atual;
    ?>
        <a href="<?php echo $caminho_base . htmlspecialchars($item['href']); ?>" class="mobile-item<?php echo $ativo ? ' ativo' : ''; ?>">
            <span class="mobile-icone"><?php echo iconeNav($icones[$item['icone']]); ?></span>
            <span class="mobile-texto"><?php echo htmlspecialchars($item['label']); ?></span>
        </a>
    <?php endforeach; ?>

    <?php // As 6 abas acima são as do protótipo. "Mais" existe porque a barra lateral (17 links)
          // some no mobile: sem ele, Remarketing/Traqueamento/Links e a área de admin inteira
          // ficavam inalcançáveis no celular -- ver anotacoes/varredura-11-cobertura-mobile.md. ?>
    <button type="button" class="mobile-item" id="abrir-menu-mobile" aria-label="Mais opções" aria-expanded="false">
        <span class="mobile-icone"><?php echo iconeNav('M4 7h16M4 12h16M4 17h16'); ?></span>
        <span class="mobile-texto">Mais</span>
    </button>
</nav>

<div class="folha-menu" id="folha-menu" hidden>
    <div class="folha-menu-fundo" data-fechar-menu></div>
    <div class="folha-menu-caixa" role="dialog" aria-modal="true" aria-label="Menu">
        <div class="folha-menu-topo">
            <span class="usuario-avatar"><?php echo htmlspecialchars($iniciais); ?></span>
            <div class="usuario-info">
                <span class="usuario-nome"><?php echo htmlspecialchars($nome_usuario); ?></span>
                <span class="usuario-email"><?php echo htmlspecialchars($email_usuario); ?></span>
            </div>
            <button type="button" class="fechar-modal" data-fechar-menu aria-label="Fechar">✕</button>
        </div>

        <nav class="folha-menu-nav">
            <div class="nav-grupo">
                <span class="nav-grupo-titulo">Operação</span>
                <?php foreach ($grupo_operacao as $item) {
                    renderizarItemNav($item, $pagina_atual, $icones, $caminho_base);
                } ?>
            </div>

            <?php if ($grupo_admin): ?>
            <div class="nav-grupo">
                <span class="nav-grupo-titulo">Administração</span>
                <?php foreach ($grupo_admin as $item) {
                    renderizarItemNav($item, $pagina_atual, $icones, $caminho_base);
                } ?>
            </div>
            <?php endif; ?>

            <?php if ($grupo_debug): ?>
            <div class="nav-grupo">
                <span class="nav-grupo-titulo">Debug</span>
                <?php foreach ($grupo_debug as $item) {
                    renderizarItemNav($item, $pagina_atual, $icones, $caminho_base);
                } ?>
            </div>
            <?php endif; ?>

            <div class="nav-grupo">
                <a href="<?php echo $caminho_base; ?>logout.php" class="nav-item">
                    <span class="nav-icone"><?php echo iconeNav($icones['sair']); ?></span>
                    <span class="nav-texto">Sair</span>
                </a>
            </div>
        </nav>
    </div>
</div>

<script>
(function () {
    const folha = document.getElementById('folha-menu');
    const botao = document.getElementById('abrir-menu-mobile');
    if (!folha || !botao) return;

    function abrir() {
        folha.hidden = false;
        requestAnimationFrame(() => folha.classList.add('aberta'));
        botao.setAttribute('aria-expanded', 'true');
        document.body.style.overflow = 'hidden';
    }
    function fechar() {
        folha.classList.remove('aberta');
        botao.setAttribute('aria-expanded', 'false');
        document.body.style.overflow = '';
        setTimeout(() => { folha.hidden = true; }, 200);
    }

    botao.addEventListener('click', abrir);
    folha.querySelectorAll('[data-fechar-menu]').forEach(el => el.addEventListener('click', fechar));
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && !folha.hidden) fechar(); });
})();
</script>
<?php if (function_exists('csrfToken')): ?>
<script>
    // Disponível globalmente (sem depender do jQuery já ter carregado) pra qualquer página
    // usar em chamadas a api.php/ajax -- páginas que chamam api.php via $.ajax fazem
    // $.ajaxSetup({headers:{'X-CSRF-Token': window.CSRF_TOKEN}}) logo após carregar o jQuery.
    window.CSRF_TOKEN = <?php echo json_encode(csrfToken()); ?>;
</script>
<?php endif; ?>
