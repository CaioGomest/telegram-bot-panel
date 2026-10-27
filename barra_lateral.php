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
// nunca casaria. Ver anotacoes/OBSERVACOES.md.
$pagina_atual = preg_replace('/\.php$/', '', basename($_SERVER['PHP_SELF']));
$user_id = $_SESSION['usuario_id'] ?? 0;

$badge_bots = null;
$badge_leads = null;
if (!$is_admin && $user_id) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM bots WHERE id_usuario = ?");
        $stmt->execute([$user_id]);
        $badge_bots = (int) $stmt->fetchColumn();

        // A contagem de leads varre todos os leads do vendedor, e este arquivo roda em toda página: fica 60 s na sessão
        // (o número do menu não precisa ser exato ao segundo; o de bots, barato e que muda por ação do próprio usuário, segue ao vivo).
        $cache_leads = $_SESSION['badge_leads_menu'] ?? null;
        if (is_array($cache_leads) && (int) ($cache_leads['u'] ?? 0) === (int) $user_id && (int) ($cache_leads['t'] ?? 0) > time() - 60) {
            $badge_leads = (int) $cache_leads['n'];
        } else {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE bot_id IN (SELECT id FROM bots WHERE id_usuario = ?)");
            $stmt->execute([$user_id]);
            $badge_leads = (int) $stmt->fetchColumn();
            $_SESSION['badge_leads_menu'] = ['u' => (int) $user_id, 't' => time(), 'n' => $badge_leads];
        }
    } catch (Exception $e) {
        $badge_bots = null;
        $badge_leads = null;
    }
}

function iconeNav(string $d, string $viewBox = '0 0 24 24'): string
{
    return '<svg width="17" height="17" viewBox="' . $viewBox . '" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="' . $d . '"></path></svg>';
}

// Ícones sincronizados com o handoff do redesign (2026-09-24) --
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
    'vendas' => 'M12 2v20M17 6H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6',
    'logs' => 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M16 13H8M16 17H8M10 9H8',
    'emails' => 'M4 4h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2zM22 6l-10 7L2 6',
    'remarketing' => 'M3 11l18-5v12L3 14v-3zM11.6 16.8a3 3 0 1 1-5.8-1.6',
    'traqueamento' => 'M22 12h-4l-3 9L9 3l-3 9H2',
    'links' => 'M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71',
    'redirecionamento' => 'M16 3h5v5M4 20L21 3M21 16v5h-5M15 15l6 6M4 4l5 5',
    'comunidade' => 'M21 11.5a8.4 8.4 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7a8.4 8.4 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.4 8.4 0 0 1 3.8-.9h.5a8.5 8.5 0 0 1 8 8v.5z',
    'webhooks' => 'M13 2L3 14h9l-1 8 10-12h-9l1-8z',
    'usuarios' => 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM16 11l2 2 4-4',
    'campanhas' => 'M4 22V4a1 1 0 0 1 1-1h13l-2 5 2 5H5',
    'identidade' => 'M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2zM12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z',
    'consultar_venda' => 'M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16zM21 21l-4.3-4.3',
    'testes_desempenho' => 'M18 20V10M12 20V4M6 20v-6',
    'debug' => 'M12 8v8M8 12h8M4 4h16v16H4z',
    'sair' => 'M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9',
];

// Menu do vendedor em seções (cada uma com título; recolhida, vira só um traço separador entre os ícones).
// Visão geral = acompanhar o negócio | Automações = o que roda sozinho | Rastreamento = de onde vem e para onde vai o tráfego |
// Comunidade = ranking e grupos | Conta = pagamento e dados do usuário.
$grupo_visao = [];
$grupo_automacoes = [];
$grupo_rastreamento = [];
$grupo_comunidade = [];
if (!$is_admin) {
    $grupo_visao[] = ['href' => 'index', 'label' => 'Dashboard', 'curto' => 'Início', 'sub' => 'Visão geral', 'icone' => 'dashboard'];
    $grupo_visao[] = ['href' => 'vendas', 'label' => 'Vendas', 'curto' => 'Vendas', 'sub' => 'Registro de vendas', 'icone' => 'vendas'];
    $grupo_visao[] = ['href' => 'leads', 'label' => 'Leads', 'curto' => 'Leads', 'sub' => 'Quem interagiu', 'icone' => 'leads', 'badge' => $badge_leads];

    $grupo_automacoes[] = ['href' => 'bots', 'label' => 'Meus Bots', 'curto' => 'Bots', 'sub' => 'Seus bots do Telegram', 'icone' => 'bots', 'badge' => $badge_bots];
    $grupo_automacoes[] = ['href' => 'fluxos', 'label' => 'Fluxos', 'curto' => 'Fluxos', 'sub' => 'Conversas dos bots', 'icone' => 'fluxos'];
    $grupo_automacoes[] = ['href' => 'remarketing', 'label' => 'Remarketing', 'curto' => 'Remarketing', 'sub' => 'Recupere vendas', 'icone' => 'remarketing'];
    $grupo_automacoes[] = ['href' => 'redirecionamento', 'label' => 'Redirecionamento', 'curto' => 'Redirec.', 'sub' => 'Links curtos', 'icone' => 'redirecionamento'];

    $grupo_rastreamento[] = ['href' => 'traqueamento', 'label' => 'Traqueamento', 'curto' => 'Traqueam.', 'sub' => 'Pixels e APIs', 'icone' => 'traqueamento'];
    $grupo_rastreamento[] = ['href' => 'links_rastreamento', 'label' => 'Links de Rastreamento', 'curto' => 'Links', 'sub' => 'Origem do tráfego', 'icone' => 'links'];
    $grupo_rastreamento[] = ['href' => 'webhooks', 'label' => 'Webhooks', 'curto' => 'Webhooks', 'sub' => 'Eventos em tempo real', 'icone' => 'webhooks'];

    $grupo_comunidade[] = ['href' => 'ranking', 'label' => 'Ranking', 'curto' => 'Ranking', 'sub' => 'Concurso de faturamento', 'icone' => 'ranking'];
    $grupo_comunidade[] = ['href' => 'comunidade', 'label' => 'Comunidade', 'curto' => 'Comunidade', 'sub' => 'Grupos e novidades', 'icone' => 'comunidade', 'nova_aba' => true];
}
$grupo_conta = [];
$grupo_conta[] = ['href' => 'gateways', 'label' => 'Gateways', 'curto' => 'Gateways', 'sub' => 'Meios de pagamento', 'icone' => 'gateways'];
$grupo_conta[] = ['href' => 'configuracao_usuario', 'label' => 'Minha Conta', 'curto' => 'Conta', 'sub' => 'Dados e senha', 'icone' => 'conta'];

$grupo_admin = [];
if ($is_admin) {
    $grupo_admin[] = ['href' => 'admin/dashboard', 'label' => 'Visão Geral', 'curto' => 'Visão geral', 'sub' => 'Plataforma', 'icone' => 'visao_geral'];
    $grupo_admin[] = ['href' => 'admin/transacoes', 'label' => 'Transações', 'curto' => 'Transações', 'sub' => 'Vendas e comissão', 'icone' => 'transacoes'];
    $grupo_admin[] = ['href' => 'admin/usuarios', 'label' => 'Usuários', 'curto' => 'Usuários', 'sub' => 'Contas e acessos', 'icone' => 'usuarios'];
    $grupo_admin[] = ['href' => 'admin/ranking', 'label' => 'Campanhas', 'curto' => 'Campanhas', 'sub' => 'Ranking e prêmios', 'icone' => 'campanhas'];
    $grupo_admin[] = ['href' => 'admin/comunidade', 'label' => 'Comunidade', 'curto' => 'Comunidade', 'sub' => 'Links oficiais', 'icone' => 'comunidade'];
    $grupo_admin[] = ['href' => 'admin/logs', 'label' => 'Logs', 'curto' => 'Logs', 'sub' => 'Registros do sistema', 'icone' => 'logs'];
    $grupo_admin[] = ['href' => 'admin/emails', 'label' => 'E-mails', 'curto' => 'E-mails', 'sub' => 'Envios e falhas', 'icone' => 'emails'];
    $grupo_admin[] = ['href' => 'admin/configuracoes', 'label' => 'Configurações', 'curto' => 'Config.', 'sub' => 'Marca e e-mail', 'icone' => 'identidade'];
}

$grupo_debug = [];
if ($is_admin) {
    $grupo_debug[] = ['href' => 'admin/consultar_venda', 'label' => 'Consultar Venda', 'curto' => 'Consultar', 'icone' => 'consultar_venda'];
    $grupo_debug[] = ['href' => 'admin/testes_desempenho', 'label' => 'Testes de Desempenho', 'curto' => 'Desempenho', 'icone' => 'testes_desempenho'];
}

$grupos_menu = $is_admin
    ? ['Administração' => $grupo_admin, 'Conta' => $grupo_conta, 'Debug' => $grupo_debug]
    : ['Visão geral' => $grupo_visao, 'Automações' => $grupo_automacoes, 'Rastreamento' => $grupo_rastreamento, 'Comunidade' => $grupo_comunidade, 'Conta' => $grupo_conta];

function itemNavAtivo(string $href, string $pagina_atual): bool
{
    $base = basename($href);
    if ($base === $pagina_atual) {
        return true;
    }
    $filhas = [
        'bots' => ['bot'],
        'fluxos' => ['fluxo', 'fluxo_basico'],
        'remarketing' => ['remarketing_detalhes', 'remarketing_nova'],
        'ranking' => ['ranking_classificacao'],
    ];
    return in_array($pagina_atual, $filhas[$base] ?? [], true);
}

function renderizarItemNav(array $item, string $pagina_atual, array $icones, string $caminho_base): void
{
    $ativo = itemNavAtivo($item['href'], $pagina_atual);
    $icone_svg = isset($item['icone']) ? iconeNav($icones[$item['icone']]) : iconeNav($icones['debug']);
    $alvo = !empty($item['nova_aba']) ? ' target="_blank" rel="noopener"' : '';
    echo '<a href="' . $caminho_base . htmlspecialchars($item['href']) . '" class="nav-item' . ($ativo ? ' ativo' : '') . '" title="' . htmlspecialchars($item['label']) . '"' . $alvo . '>';
    echo '<span class="nav-icone">' . $icone_svg . '</span>';
    echo '<span class="nav-texto">' . htmlspecialchars($item['label']);
    if (!empty($item['sub'])) {
        echo '<small class="nav-sub">' . htmlspecialchars($item['sub']) . '</small>';
    }
    echo '</span>';
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

// Aviso de e-mail ainda não confirmado (não bloqueia nada): botão reenvia o e-mail de confirmação.
$aviso_email_html = '';
if (usuarioLogado() && function_exists('emailVerificado') && !emailVerificado((int) $_SESSION['usuario_id'])) {
    $aviso_email_html = '<div class="aviso-email" data-aviso-email><span>E-mail não confirmado</span>'
        . '<button type="button" data-reenviar-verificacao>Reenviar</button></div>';
}
$foto_usuario = function_exists('fotoPerfilDaSessao') ? fotoPerfilDaSessao() : '';
?>
<?php
// Casca nova (Layout A) ou antiga: tema_inline.php já decidiu (funcoes/layout_a.php); admin/, editores e demais páginas seguem na antiga.
require_once __DIR__ . '/funcoes/layout_a.php';
$layout_a = $layout_a ?? layoutAAtivo($caminho_base);
if (!$layout_a): ?>
<?php // Recolhida (só ícones) por padrão; abre ao passar o mouse, por cima do conteúdo. "Fixar sidebar" mantém aberta. ?>
<aside class="barra-lateral">
    <div class="barra-lateral-interna">
        <div class="barra-lateral-topo">
            <img src="<?php echo htmlspecialchars(logoSistema($caminho_base)); ?>" alt="" class="logo-marca" onerror="this.style.display='none'">
            <div class="logo-textos">
                <span class="logo-titulo"><?php echo htmlspecialchars(nomeSistema()); ?></span>
                <span class="logo-subtitulo">MENU</span>
            </div>
        </div>

        <div class="usuario-cartao">
            <?php echo htmlAvatarUsuario($iniciais, $foto_usuario, 'usuario-avatar', $caminho_base); ?>
            <div class="usuario-info">
                <span class="usuario-nome"><?php echo htmlspecialchars($nome_usuario); ?></span>
                <span class="usuario-email"><?php echo htmlspecialchars($email_usuario); ?></span>
            </div>
            <a href="<?php echo $caminho_base; ?>logout" class="botao-sair" title="Sair" aria-label="Sair">
                <?php echo iconeNav($icones['sair']); ?>
            </a>
        </div>

        <nav class="nav-lateral">
            <?php foreach ($grupos_menu as $titulo_grupo => $itens_grupo): if (!$itens_grupo) continue; ?>
            <div class="nav-grupo">
                <span class="nav-grupo-titulo"><?php echo $titulo_grupo; ?></span>
                <?php foreach ($itens_grupo as $item) {
                    renderizarItemNav($item, $pagina_atual, $icones, $caminho_base);
                } ?>
            </div>
            <?php endforeach; ?>
        </nav>

        <?php echo $aviso_email_html; ?>
        <?php /* "Fixar sidebar" desativado por enquanto (o JS e o CSS ficam; é só descomentar):
        <div class="barra-lateral-rodape">
            <button type="button" class="botao-fixar-barra" id="botao-fixar-barra" aria-pressed="false">
                <span class="nav-icone"><?php echo iconeNav('M3 3h18v18H3zM9 3v18'); ?></span>
                <span class="nav-texto">Fixar sidebar</span>
            </button>
        </div>
        */ ?>
    </div>
</aside>

<?php if (!$is_admin) {
    require_once __DIR__ . '/funcoes/topo_painel.php';
    $faturamento_topo = faturamentoTotalUsuario($pdo, (int) $user_id);
    $meta_topo = metaFaturamento($faturamento_topo);
    $percentual_topo = (int) min(100, floor($faturamento_topo / $meta_topo * 100));
    $primeiro_nome = trim(explode(' ', trim($nome_usuario))[0]) ?: $nome_usuario;
?>
<header class="topo-painel">
    <div class="topo-faturamento" title="Faturamento total até hoje">
        <span class="topo-faturamento-icone"><?php echo iconeNav('M12 2v20M17 6H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6'); ?></span>
        <div class="topo-faturamento-corpo">
            <div class="topo-faturamento-linha"><span>FATURAMENTO</span><span><?php echo $percentual_topo; ?>%</span></div>
            <div class="topo-faturamento-barra"><i style="width: <?php echo $percentual_topo; ?>%"></i></div>
            <div class="topo-faturamento-linha"><strong>R$ <?php echo number_format($faturamento_topo, 2, ',', '.'); ?></strong><span>/ <?php echo formatarMetaCurta($meta_topo); ?></span></div>
        </div>
    </div>
    <div class="topo-saudacao">
        <strong><?php echo saudacaoPorHorario(); ?>, <em><?php echo htmlspecialchars($primeiro_nome); ?></em></strong>
        <span><?php echo dataExtensaPt(); ?></span>
    </div>
    <div class="topo-acoes">
        <a href="<?php echo $caminho_base; ?>comunidade" target="_blank" rel="noopener" class="topo-botao" title="Comunidade" aria-label="Comunidade">
            <?php echo iconeNav($icones['comunidade']); ?>
        </a>
        <button type="button" class="alternador-tema topo-botao" onclick="alternarTema()" aria-label="Alternar tema" title="Alternar tema">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"></path></svg>
            <span>Tema</span>
        </button>
    </div>
</header>
<?php } ?>

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
          // ficavam inalcançáveis no celular -- ver anotacoes/OBSERVACOES.md. ?>
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

        <?php echo $aviso_email_html; ?>
        <nav class="folha-menu-nav">
            <?php foreach ($grupos_menu as $titulo_grupo => $itens_grupo): if (!$itens_grupo) continue; ?>
            <div class="nav-grupo">
                <span class="nav-grupo-titulo"><?php echo $titulo_grupo; ?></span>
                <?php foreach ($itens_grupo as $item) {
                    renderizarItemNav($item, $pagina_atual, $icones, $caminho_base);
                } ?>
            </div>
            <?php endforeach; ?>

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
    const botao = document.getElementById('botao-fixar-barra');
    if (!botao) return;
    const raiz = document.documentElement;
    const marcar = () => botao.setAttribute('aria-pressed', raiz.classList.contains('barra-fixa') ? 'true' : 'false');
    marcar();
    botao.addEventListener('click', function () {
        raiz.classList.toggle('barra-fixa');
        try { localStorage.setItem('barra_fixa', raiz.classList.contains('barra-fixa') ? '1' : '0'); } catch (e) {}
        marcar();
    });
})();
</script>
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
<?php else: ?>
<?php require __DIR__ . '/parciais/shell_a.php'; ?>
<?php endif; ?>
<?php if (function_exists('csrfToken')): ?>
<script>
    // Disponível globalmente (sem depender do jQuery já ter carregado) pra qualquer página
    // usar em chamadas a api.php/ajax -- páginas que chamam api.php via $.ajax fazem
    // $.ajaxSetup({headers:{'X-CSRF-Token': window.CSRF_TOKEN}}) logo após carregar o jQuery.
    window.CSRF_TOKEN = <?php echo json_encode(csrfToken()); ?>;
</script>
<?php endif; ?>
<?php if ($aviso_email_html !== ''): ?>
<script>
(function () {
    document.addEventListener('click', function (e) {
        var botao = e.target.closest('[data-reenviar-verificacao]');
        if (!botao) return;
        botao.disabled = true;
        var rotulo = botao.textContent;
        botao.textContent = 'Enviando…';
        fetch('<?php echo htmlspecialchars($caminho_base, ENT_QUOTES); ?>funcoes/usuario.php?api=usuario&acao=reenviar_verificacao_email', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN || '' },
            body: '{}'
        }).then(function (r) { return r.json(); }).then(function (j) {
            if (j.sucesso) { botao.textContent = 'Enviado ✓'; return; }
            // Limite de pedidos: mostra só a espera (o texto completo do servidor não cabe no botão)
            botao.textContent = j.espera ? 'Aguarde ' + (j.espera < 90 ? j.espera + 's' : Math.ceil(j.espera / 60) + ' min') : (j.erro || 'Falhou');
            setTimeout(function () { botao.disabled = false; botao.textContent = rotulo; }, j.espera ? Math.min(j.espera, 15) * 1000 : 4000);
        }).catch(function () {
            botao.textContent = 'Falhou';
            setTimeout(function () { botao.disabled = false; botao.textContent = rotulo; }, 4000);
        });
    });
})();
</script>
<?php endif; ?>
<?php if (function_exists('manutencaoAtiva') && manutencaoAtiva()): ?>
<?php // Manutenção ligada: quem chegou até aqui está liberado (cookie ou link); os outros veem a página "Em manutenção". A faixa evita esquecer ligada. ?>
<style>
.faixa-manutencao { position: fixed; right: 16px; bottom: 16px; z-index: 250; display: flex; align-items: center; gap: 12px; max-width: calc(100vw - 32px); padding: 10px 14px; border-radius: 14px; border: 1px solid var(--er, #ff5d5d); background: var(--p, #12151b); color: var(--t, #f3f4f6); font: 600 13px/1.35 'Manrope', system-ui, sans-serif; box-shadow: 0 18px 40px -20px rgba(0, 0, 0, .8); }
.faixa-manutencao a { flex-shrink: 0; color: var(--or, #ff7a2f); font-weight: 800; text-decoration: none; }
@media (max-width: 900px) { .faixa-manutencao { bottom: 84px; } }
</style>
<div class="faixa-manutencao" role="status">
    <span>Manutenção ligada: só você enxerga o sistema.</span>
    <?php if ($is_admin): ?><a href="<?php echo htmlspecialchars($caminho_base); ?>admin/configuracoes#manutencao">Desligar</a><?php endif; ?>
</div>
<?php endif; ?>
<?php include __DIR__ . '/parciais/sino_notificacoes.php'; ?>
<script src="<?php echo htmlspecialchars($caminho_base); ?>assets/js/notificacoes.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/notificacoes.js'); ?>"></script>
<?php if (function_exists('configSistema') && configSistema('push_ativo') === '1'): ?>
<?php // Avisos push ligados pelo administrador: convite no sino, sincronização da inscrição e cancelamento ao sair (assets/js/push.js; o cartão fica em Minha Conta). Desligado, nada disso é carregado. ?>
<link rel="stylesheet" href="<?php echo htmlspecialchars($caminho_base); ?>assets/css/push.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/push.css'); ?>">
<script>window.PUSH_CONFIG = { ativo: true };</script>
<script src="<?php echo htmlspecialchars($caminho_base); ?>assets/js/push.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/push.js'); ?>"></script>
<?php endif; ?>
