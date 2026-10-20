<?php
declare(strict_types=1);

/**
 * Casca do Layout A. Incluída por barra_lateral.php (que já preparou $grupos_menu, $icones, $pagina_atual, $nome_usuario,
 * $aviso_email_html, $foto_usuario etc.) quando a página usa a casca nova (funcoes/layout_a.php).
 *
 * Desktop: trilho fixo com rótulos (a-rail) + barra de cima (a-top). Celular (<= 900 px): barra de cima própria (a-mtop),
 * faixa da meta (a-mmeta), barra de abas (a-tabbar) e a folha "Mais" (a-sheet) com todos os destinos.
 * O CSS é o assets/css/layout_a.css. Os ganchos de JS e de teste (#folha-menu, #abrir-menu-mobile, [data-fechar-menu],
 * .topo-acoes, .alternador-tema, [data-aviso-email]) são os mesmos da casca antiga.
 */
require_once __DIR__ . '/../funcoes/topo_painel.php';

if (!function_exists('iconeA')) {
    function iconeA(string $d): string
    {
        return '<svg class="ic" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="' . $d . '"></path></svg>';
    }

    /** Número curto para o selo: 1 a 999 como está; de mil em diante "1k", "12k". Zero e vazio não mostram selo. */
    function seloA($n): string
    {
        if ($n === null || (int) $n <= 0) {
            return '';
        }
        $n = (int) $n;
        return '<span class="a-nb">' . ($n >= 1000 ? (string) intdiv($n, 1000) . 'k' : (string) $n) . '</span>';
    }

    function itemRailA(array $item, string $pagina_atual, array $icones, string $caminho_base): string
    {
        $ativo = itemNavAtivo($item['href'], $pagina_atual);
        $nova_aba = !empty($item['nova_aba']);
        $titulo = $item['label'] . ($nova_aba ? ' (abre em nova aba)' : '');
        return '<a class="a-ni' . ($ativo ? ' ativo' : '') . '" href="' . $caminho_base . htmlspecialchars($item['href']) . '"'
            . ' title="' . htmlspecialchars($titulo) . '"' . ($ativo ? ' aria-current="page"' : '')
            . ($nova_aba ? ' target="_blank" rel="noopener"' : '') . '>'
            . iconeA($icones[$item['icone'] ?? 'debug'] ?? $icones['debug'])
            . htmlspecialchars($item['curto'] ?? $item['label'])
            . seloA($item['badge'] ?? null)
            . '</a>';
    }

    function itemTileA(array $item, string $pagina_atual, array $icones, string $caminho_base): string
    {
        $ativo = itemNavAtivo($item['href'], $pagina_atual);
        $nova_aba = !empty($item['nova_aba']);
        return '<a class="a-tile' . ($ativo ? ' ativo' : '') . '" href="' . $caminho_base . htmlspecialchars($item['href']) . '"'
            . ($ativo ? ' aria-current="page"' : '') . ($nova_aba ? ' target="_blank" rel="noopener" title="Abre em nova aba"' : '') . '>'
            . iconeA($icones[$item['icone'] ?? 'debug'] ?? $icones['debug'])
            . htmlspecialchars($item['label'])
            . seloA($item['badge'] ?? null)
            . '</a>';
    }
}

$a_base = $caminho_base;
$a_home = $a_base . ($is_admin ? 'admin/dashboard' : 'index');
$a_nome_sistema = nomeSistema();
$a_logo = logoSistema($a_base);

// Título da barra do celular: o destino do menu que está aberto.
$a_titulo = $a_nome_sistema;
foreach ($grupos_menu as $a_itens) {
    foreach ($a_itens as $a_item) {
        if (itemNavAtivo($a_item['href'], $pagina_atual)) {
            $a_titulo = $a_item['label'];
            break 2;
        }
    }
}

// Meta de faturamento (só vendedor): vem do cache das métricas, uma leitura por página.
$a_meta = null;
if (!$is_admin) {
    $a_fat = faturamentoTotalUsuario($pdo, (int) $user_id);
    $a_meta_valor = metaFaturamento($a_fat);
    $a_meta = [
        'pct' => (int) min(100, floor($a_fat / $a_meta_valor * 100)),
        'fat' => 'R$ ' . number_format($a_fat, 2, ',', '.'),
        'meta' => formatarMetaCurta($a_meta_valor),
    ];
}
$a_primeiro_nome = trim(explode(' ', trim($nome_usuario))[0]) ?: $nome_usuario;

// Abas do celular: os 4 destinos mais usados + "Mais" (que abre a folha com todos os links).
$a_abas = $is_admin ? [
    ['href' => 'admin/dashboard',  'curto' => 'Início',     'icone' => 'dashboard'],
    ['href' => 'admin/transacoes', 'curto' => 'Transações', 'icone' => 'transacoes'],
    ['href' => 'admin/usuarios',   'curto' => 'Usuários',   'icone' => 'usuarios'],
    ['href' => 'admin/logs',       'curto' => 'Logs',       'icone' => 'logs'],
] : [
    ['href' => 'index',  'curto' => 'Início', 'icone' => 'dashboard'],
    ['href' => 'vendas', 'curto' => 'Vendas', 'icone' => 'vendas'],
    ['href' => 'leads',  'curto' => 'Leads',  'icone' => 'leads', 'badge' => $badge_leads],
    ['href' => 'bots',   'curto' => 'Bots',   'icone' => 'bots'],
];
$a_aba_ativa = false;
foreach ($a_abas as $a_aba) {
    if (itemNavAtivo($a_aba['href'], $pagina_atual)) {
        $a_aba_ativa = true;
    }
}
$a_icone_sol = '<svg class="ic" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"></path></svg>';
?>
<aside class="a-rail" aria-label="Navegação principal">
    <a class="a-logo" href="<?php echo htmlspecialchars($a_home); ?>" aria-label="<?php echo htmlspecialchars($a_nome_sistema); ?>, início"><img src="<?php echo htmlspecialchars($a_logo); ?>" alt="" onerror="this.style.display='none'"></a>
    <?php
    $a_primeiro_grupo = true;
    foreach ($grupos_menu as $a_titulo_grupo => $a_itens_grupo) {
        // "Minha Conta" fica no avatar, no fim do trilho.
        $a_itens_grupo = array_values(array_filter($a_itens_grupo, static fn($i) => $i['href'] !== 'configuracao_usuario'));
        if (!$a_itens_grupo) {
            continue;
        }
        if (!$a_primeiro_grupo) {
            echo '<span class="a-nsep" aria-hidden="true"></span>';
        }
        $a_primeiro_grupo = false;
        foreach ($a_itens_grupo as $a_item) {
            echo itemRailA($a_item, $pagina_atual, $icones, $a_base);
        }
    }
    ?>
    <div class="a-nfim">
        <a class="a-avatar<?php echo $pagina_atual === 'configuracao_usuario' ? ' ativo' : ''; ?>" href="<?php echo $a_base; ?>configuracao_usuario" title="Minha Conta" aria-label="Minha Conta"<?php echo $pagina_atual === 'configuracao_usuario' ? ' aria-current="page"' : ''; ?>><?php echo htmlAvatarUsuario($iniciais, $foto_usuario, 'a-av', $a_base); ?></a>
        <a class="a-ni" href="<?php echo $a_base; ?>logout" title="Sair" aria-label="Sair da conta"><?php echo iconeA($icones['sair']); ?>Sair</a>
    </div>
</aside>

<header class="a-top">
    <div class="a-hi">
        <b><?php echo saudacaoPorHorario(); ?>, <em><?php echo htmlspecialchars($a_primeiro_nome); ?></em></b>
        <span><?php echo dataExtensaPt(); ?></span>
    </div>
    <?php if ($a_meta): ?>
    <div class="a-meta" title="Faturamento total até hoje">
        <span class="a-meta-ic"><?php echo iconeA($icones['vendas']); ?></span>
        <div class="a-meta-c">
            <div class="a-meta-l"><span>FATURAMENTO</span><span><?php echo $a_meta['pct']; ?>%</span></div>
            <div class="a-bar"><i style="width: <?php echo $a_meta['pct']; ?>%"></i></div>
            <div class="a-meta-l"><strong><?php echo $a_meta['fat']; ?></strong><span>/ <?php echo $a_meta['meta']; ?></span></div>
        </div>
    </div>
    <?php endif; ?>
    <div class="a-acts-top topo-acoes">
        <a class="a-ib" href="<?php echo $a_base; ?>comunidade" target="_blank" rel="noopener" title="Comunidade" aria-label="Comunidade (nova aba)"><?php echo iconeA($icones['comunidade']); ?></a>
        <button type="button" class="a-ib alternador-tema" onclick="alternarTema()" aria-label="Alternar tema" title="Alternar tema"><?php echo $a_icone_sol; ?></button>
    </div>
</header>

<header class="a-mtop">
    <a class="a-logo" href="<?php echo htmlspecialchars($a_home); ?>" aria-label="<?php echo htmlspecialchars($a_nome_sistema); ?>, início"><img src="<?php echo htmlspecialchars($a_logo); ?>" alt="" onerror="this.style.display='none'"></a>
    <div class="a-mt"><b><?php echo htmlspecialchars($a_titulo); ?></b><span><?php echo dataCurtaPt(); ?></span></div>
    <div class="a-acts-top topo-acoes">
        <button type="button" class="a-ib alternador-tema" onclick="alternarTema()" aria-label="Alternar tema" title="Alternar tema"><?php echo $a_icone_sol; ?></button>
    </div>
</header>
<?php if ($a_meta): ?>
<div class="a-mmeta">
    <div class="a-meta-l"><span>FATURAMENTO · <?php echo $a_meta['pct']; ?>%</span><span><strong><?php echo $a_meta['fat']; ?></strong> / <?php echo $a_meta['meta']; ?></span></div>
    <div class="a-bar"><i style="width: <?php echo $a_meta['pct']; ?>%"></i></div>
</div>
<?php endif; ?>

<nav class="a-tabbar" aria-label="Navegação principal">
    <?php foreach ($a_abas as $a_aba): $a_ativa = itemNavAtivo($a_aba['href'], $pagina_atual); ?>
        <a class="a-tab<?php echo $a_ativa ? ' ativo' : ''; ?>" href="<?php echo $a_base . htmlspecialchars($a_aba['href']); ?>"<?php echo $a_ativa ? ' aria-current="page"' : ''; ?>><?php echo iconeA($icones[$a_aba['icone']]) . htmlspecialchars($a_aba['curto']) . seloA($a_aba['badge'] ?? null); ?></a>
    <?php endforeach; ?>
    <button type="button" class="a-tab<?php echo $a_aba_ativa ? '' : ' ativo'; ?>" id="abrir-menu-mobile" aria-label="Mais opções" aria-expanded="false" aria-controls="folha-menu"><?php echo iconeA('M4 7h16M4 12h16M4 17h16'); ?>Mais</button>
</nav>

<div class="a-sheet" id="folha-menu" hidden>
    <div class="a-sheet-fundo" data-fechar-menu></div>
    <div class="a-sheet-caixa" role="dialog" aria-modal="true" aria-label="Menu">
        <div class="a-sheet-usr">
            <?php echo htmlAvatarUsuario($iniciais, $foto_usuario, 'a-av', $a_base); ?>
            <div>
                <b><?php echo htmlspecialchars($nome_usuario); ?></b>
                <span><?php echo htmlspecialchars($email_usuario); ?></span>
            </div>
            <button type="button" class="a-ib" data-fechar-menu aria-label="Fechar o menu"><?php echo iconeA('M18 6L6 18M6 6l12 12'); ?></button>
        </div>
        <?php echo $aviso_email_html; ?>
        <?php foreach ($grupos_menu as $a_titulo_grupo => $a_itens_grupo): if (!$a_itens_grupo) continue; ?>
        <div class="a-gt"><?php echo htmlspecialchars($a_titulo_grupo); ?></div>
        <div class="a-sheet-g">
            <?php foreach ($a_itens_grupo as $a_item) { echo itemTileA($a_item, $pagina_atual, $icones, $a_base); } ?>
            <?php if ($a_titulo_grupo === 'Conta'): ?>
            <a class="a-tile er" href="<?php echo $a_base; ?>logout"><?php echo iconeA($icones['sair']); ?>Sair</a>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
(function () {
    var folha = document.getElementById('folha-menu');
    var botao = document.getElementById('abrir-menu-mobile');
    if (!folha || !botao) return;

    function abrir() {
        folha.hidden = false;
        requestAnimationFrame(function () { folha.classList.add('aberta'); });
        botao.setAttribute('aria-expanded', 'true');
        document.body.style.overflow = 'hidden';
        var fechar = folha.querySelector('.a-sheet-usr .a-ib');
        if (fechar) fechar.focus();
    }
    function fechar() {
        folha.classList.remove('aberta');
        botao.setAttribute('aria-expanded', 'false');
        document.body.style.overflow = '';
        setTimeout(function () { folha.hidden = true; }, 200);
        botao.focus();
    }

    botao.addEventListener('click', abrir);
    folha.querySelectorAll('[data-fechar-menu]').forEach(function (el) { el.addEventListener('click', fechar); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !folha.hidden) fechar(); });
    // Girar o aparelho ou alargar a janela para o desktop com a folha aberta: fecha e devolve a rolagem.
    window.matchMedia('(min-width: 901px)').addEventListener('change', function (e) {
        if (e.matches && !folha.hidden) { folha.classList.remove('aberta'); folha.hidden = true; document.body.style.overflow = ''; botao.setAttribute('aria-expanded', 'false'); }
    });
})();
</script>
