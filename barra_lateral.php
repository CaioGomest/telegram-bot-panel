<?php
declare(strict_types=1);

// Páginas dentro de admin/ definem $caminho_base = '../' antes de incluir este
// arquivo, pra todo link/asset abaixo continuar apontando pro lugar certo.
$caminho_base = $caminho_base ?? '';

if (!isset($pdo)) {
    require_once __DIR__ . '/conexao.php';
}

$is_admin = function_exists('ehAdmin') ? ehAdmin() : false;
// Sem o .php: os href dos menus agora são limpos (/bots), mas PHP_SELF continua
// apontando pro arquivo real (bots.php) -- sem normalizar, o item ativo do menu
// nunca casaria. Ver anotacoes/url-sem-php.md.
$pagina_atual = preg_replace('/\.php$/', '', basename($_SERVER['PHP_SELF']));
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

// Sincronizado com icons novos telegram/design_handoff_coyote_bot_panel/icons.js (2026-09-24) --
// mesmas chaves de sempre, paths corrigidos pro traço oficial do design.
$icones = [
    'dashboard' => 'M3 3h7v9H3zM14 3h7v5h-7zM14 12h7v9h-7zM3 16h7v5H3z',
    'bots' => 'M12 8V4H8M6 8h12a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-8a2 2 0 0 1 2-2zM2 14h2M20 14h2M15 13v2M9 13v2',
    'fluxos' => 'M3 3h6v6H3zM15 15h6v6h-6zM6 9v3a3 3 0 0 0 3 3h6',
    'leads' => 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75',
    'ranking' => 'M6 9H4.5a2.5 2.5 0 0 1 0-5H6M18 9h1.5a2.5 2.5 0 0 0 0-5H18M4 22h16M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22M18 2H6v7a6 6 0 0 0 12 0V2z',
    'gateways' => 'M4 5h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2zM2 10h20',
    'conta' => 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM4 21a8 8 0 0 1 16 0',
    'visao_geral' => 'M3 3v18h18M7 14l4-4 4 4 5-5',
    'transacoes' => 'M8 3L4 7l4 4M4 7h16M16 21l4-4-4-4M20 17H4',
    'logs' => 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M16 13H8M16 17H8M10 9H8',
    'remarketing' => 'M3 11l18-5v12L3 14v-3zM11.6 16.8a3 3 0 1 1-5.8-1.6',
    'traqueamento' => 'M22 12h-4l-3 9L9 3l-3 9H2',
    'links' => 'M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71',
    'comunidade' => 'M21 11.5a8.4 8.4 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7a8.4 8.4 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.4 8.4 0 0 1 3.8-.9h.5a8.5 8.5 0 0 1 8 8v.5z',
    'webhooks' => 'M13 2L3 14h9l-1 8 10-12h-9l1-8z',
    'usuarios' => 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM16 11l2 2 4-4',
    'campanhas' => 'M4 22V4a1 1 0 0 1 1-1h13l-2 5 2 5H5',
    'identidade' => 'M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2zM12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z',
    'atualizar_banco' => 'M21 12a9 9 0 1 1-3-6.7L21 8M21 3v5h-5',
    'consultar_venda' => 'M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16zM21 21l-4.3-4.3',
    'debug' => 'M12 8v8M8 12h8M4 4h16v16H4z',
    'sair' => 'M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9',
];

$grupo_operacao = [];
if (!$is_admin) {
    $grupo_operacao[] = ['href' => 'index', 'label' => 'Dashboard', 'icone' => 'dashboard'];
    $grupo_operacao[] = ['href' => 'bots', 'label' => 'Meus Bots', 'icone' => 'bots', 'badge' => $badge_bots];
    $grupo_operacao[] = ['href' => 'fluxos', 'label' => 'Fluxos', 'icone' => 'fluxos'];
    $grupo_operacao[] = ['href' => 'leads', 'label' => 'Leads', 'icone' => 'leads', 'badge' => $badge_leads];
    $grupo_operacao[] = ['href' => 'ranking', 'label' => 'Ranking', 'icone' => 'ranking'];
    $grupo_operacao[] = ['href' => 'remarketing', 'label' => 'Remarketing', 'icone' => 'remarketing'];
    $grupo_operacao[] = ['href' => 'traqueamento', 'label' => 'Rastreamento', 'icone' => 'traqueamento'];
    $grupo_operacao[] = ['href' => 'links_rastreamento', 'label' => 'Links de Rastreamento', 'icone' => 'links'];
    $grupo_operacao[] = ['href' => 'webhooks', 'label' => 'Webhooks', 'icone' => 'webhooks'];
    $grupo_operacao[] = ['href' => 'comunidade', 'label' => 'Comunidade', 'icone' => 'comunidade'];
}
$grupo_operacao[] = ['href' => 'gateways', 'label' => 'Gateways', 'icone' => 'gateways'];
$grupo_operacao[] = ['href' => 'configuracao_usuario', 'label' => 'Minha Conta', 'icone' => 'conta'];

$grupo_admin = [];
if ($is_admin) {
    $grupo_admin[] = ['href' => 'admin/dashboard', 'label' => 'Visão Geral', 'icone' => 'visao_geral'];
    $grupo_admin[] = ['href' => 'admin/transacoes', 'label' => 'Transações', 'icone' => 'transacoes'];
    $grupo_admin[] = ['href' => 'admin/logs', 'label' => 'Logs', 'icone' => 'logs'];
    $grupo_admin[] = ['href' => 'admin/usuarios', 'label' => 'Usuários', 'icone' => 'usuarios'];
    $grupo_admin[] = ['href' => 'admin/ranking', 'label' => 'Campanhas', 'icone' => 'campanhas'];
    $grupo_admin[] = ['href' => 'admin/comunidade', 'label' => 'Comunidade', 'icone' => 'comunidade'];
    $grupo_admin[] = ['href' => 'admin/configuracoes', 'label' => 'Configurações', 'icone' => 'identidade'];
}

$grupo_debug = [];
if ($is_admin) {
    $grupo_debug[] = ['href' => 'admin/atualiza_banco', 'label' => 'Atualizar Banco', 'icone' => 'atualizar_banco'];
    $grupo_debug[] = ['href' => 'admin/consultar_venda', 'label' => 'Consultar Venda', 'icone' => 'consultar_venda'];
}

function itemNavAtivo(string $href, string $pagina_atual): bool
{
    $base = basename($href);
    if ($base === $pagina_atual) {
        return true;
    }
    $filhas = [
        'bots' => ['bot'],
        'fluxos' => ['fluxo', 'fluxo_basico'],
    ];
    return in_array($pagina_atual, $filhas[$base] ?? [], true);
}

function renderizarItemNav(array $item, string $pagina_atual, array $icones, string $caminho_base): void
{
    $ativo = itemNavAtivo($item['href'], $pagina_atual);
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
$foto_usuario = function_exists('fotoPerfilDaSessao') ? fotoPerfilDaSessao() : '';
?>
<aside class="barra-lateral">
    <div class="barra-lateral-topo">
        <img src="<?php echo htmlspecialchars(logoSistema($caminho_base)); ?>" alt="" class="logo-marca" onerror="this.style.display='none'">
        <div class="logo-textos">
            <span class="logo-titulo"><?php echo htmlspecialchars(nomeSistema()); ?></span>
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
            <?php echo htmlAvatarUsuario($iniciais, $foto_usuario, 'usuario-avatar', $caminho_base); ?>
            <div class="usuario-info">
                <span class="usuario-nome"><?php echo htmlspecialchars($nome_usuario); ?></span>
                <span class="usuario-email"><?php echo htmlspecialchars($email_usuario); ?></span>
            </div>
        </div>
        <a href="<?php echo $caminho_base; ?>logout" class="botao-sair" title="Sair" aria-label="Sair">
            <?php echo iconeNav($icones['sair']); ?>
        </a>
    </div>
</aside>

<nav class="barra-mobile">
    <?php
    // As abas seguem o mesmo corte do menu lateral: quem opera bot é o usuário, o admin
    // administra a plataforma. Antes esta lista era fixa e só olhava $is_admin no "Início",
    // então o admin via Bots/Fluxos/Leads no celular enquanto o menu do desktop escondia
    // exatamente esses itens dele -- os dois menus discordavam na mesma sessão.
    $itens_mobile = $is_admin ? [
        ['href' => 'admin/dashboard',      'label' => 'Início',     'icone' => 'dashboard'],
        ['href' => 'admin/transacoes',     'label' => 'Transações', 'icone' => 'transacoes'],
        ['href' => 'admin/usuarios',       'label' => 'Usuários',   'icone' => 'usuarios'],
        ['href' => 'admin/logs',           'label' => 'Logs',       'icone' => 'logs'],
        ['href' => 'configuracao_usuario', 'label' => 'Conta',      'icone' => 'conta'],
    ] : [
        ['href' => 'index',                'label' => 'Início',  'icone' => 'dashboard'],
        ['href' => 'bots',                 'label' => 'Bots',    'icone' => 'bots'],
        ['href' => 'fluxos',               'label' => 'Fluxos',  'icone' => 'fluxos'],
        ['href' => 'leads',                'label' => 'Leads',   'icone' => 'leads'],
        ['href' => 'ranking',              'label' => 'Ranking', 'icone' => 'ranking'],
        ['href' => 'configuracao_usuario', 'label' => 'Conta',   'icone' => 'conta'],
    ];
    foreach ($itens_mobile as $item):
        $ativo = itemNavAtivo($item['href'], $pagina_atual);
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
            <?php echo htmlAvatarUsuario($iniciais, $foto_usuario, 'usuario-avatar', $caminho_base); ?>
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
                <a href="<?php echo $caminho_base; ?>logout" class="nav-item">
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
<?php include __DIR__ . '/parciais/sino_notificacoes.php'; ?>
<script src="<?php echo htmlspecialchars($caminho_base); ?>assets/js/notificacoes.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/notificacoes.js'); ?>"></script>
