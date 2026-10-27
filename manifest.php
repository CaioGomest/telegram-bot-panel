<?php
declare(strict_types=1);

/**
 * Manifest do app (rota /manifest.webmanifest, ver .htaccess). Só serve para o painel poder ser adicionado à Tela de Início:
 * no iPhone/iPad os avisos push só funcionam com o painel instalado. Nome, cor e ícones vêm da identidade do sistema (white-label).
 * Plano: deploy/PLANO-PUSH-NOTIFICACOES.md (seção 9.1). Sem sessão e sem dado do vendedor: é igual para todos.
 */
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/funcoes/configuracoes.php';
require_once __DIR__ . '/funcoes/push_icones.php';

if (configSistema('push_ativo') !== '1') {
    http_response_code(404);
    exit;
}

// Os ícones são gerados na primeira vez em que alguém pede o manifest (e quando a logo muda); se não houver imagem utilizável, o manifest segue sem ícones.
if (!pushIconesCompletos()) {
    try {
        pushIconesGerar();
    } catch (\Throwable $e) {
        error_log('[push_icones] ' . $e->getMessage());
    }
}

$base = rtrim(str_replace(chr(92), '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';
$v = (string) @configSistema('push_icone_v');
$sufixo = $v !== '' ? '?v=' . rawurlencode($v) : '';
$nome = nomeSistema();

$manifest = [
    'id' => $base,
    'name' => $nome,
    'short_name' => mb_substr($nome, 0, 12, 'UTF-8'),
    'start_url' => $base . 'index',
    'scope' => $base,
    'display' => 'standalone',
    'background_color' => '#0c0d11',
    'theme_color' => corPrimariaSistema(),
    'lang' => 'pt-BR',
];
if (pushIconesCompletos()) {
    $manifest['icons'] = [
        ['src' => $base . 'uploads/pwa/icone-192.png' . $sufixo, 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => $base . 'uploads/pwa/icone-512.png' . $sufixo, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => $base . 'uploads/pwa/icone-512-maskable.png' . $sufixo, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ];
}

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: no-cache');
echo json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
