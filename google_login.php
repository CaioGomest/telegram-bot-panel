<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/funcoes/usuario.php';
require_once __DIR__ . '/funcoes/google_auth.php';

if (usuarioLogado()) {
    header('Location: ' . (ehAdmin() ? 'admin/dashboard' : 'index'));
    exit;
}

if (!googleLoginConfigurado()) {
    // Não deveria ser alcançável normalmente -- o botão "Continuar com Google" só aparece
    // quando está configurado -- mas alguém pode digitar a URL direto, então falha com
    // aviso em vez de estourar erro de credencial vazia lá na frente.
    header('Location: login?erro=google_nao_configurado');
    exit;
}

// State de uso único: é o equivalente ao token CSRF pra este fluxo. Guardado na sessão e
// conferido de volta em google_callback.php.
$state = bin2hex(random_bytes(32));
$_SESSION['google_oauth_state'] = $state;

header('Location: ' . googleUrlAutorizacao($state));
exit;
