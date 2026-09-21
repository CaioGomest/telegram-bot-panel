<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/funcoes/usuario.php';
require_once __DIR__ . '/funcoes/google_auth.php';

if (usuarioLogado()) {
    header('Location: ' . (ehAdmin() ? 'admin/dashboard' : 'index'));
    exit;
}

// A pessoa clicou "Cancelar" na tela de consentimento do Google, ou negou algum escopo --
// isso volta como ?error=... em vez de ?code=..., e não é um ataque nem um erro de sistema,
// é só "desistiu". Volta pro login calado, sem token de erro assustador.
if (isset($_GET['error'])) {
    header('Location: login');
    exit;
}

$state_recebido = $_GET['state'] ?? '';
$state_esperado = $_SESSION['google_oauth_state'] ?? '';
unset($_SESSION['google_oauth_state']); // uso único, vale numa tentativa só

if (!googleLoginConfigurado() || $state_recebido === '' || !hash_equals($state_esperado, $state_recebido)) {
    // state não bate: ou a sessão expirou entre sair e voltar do Google, ou é uma tentativa
    // de CSRF nesse fluxo (plantar um code de outra pessoa na sessão de quem clicou aqui).
    // Os dois casos têm a mesma resposta segura: recomeça do zero.
    header('Location: login?erro=google_sessao_expirada');
    exit;
}

$code = $_GET['code'] ?? '';
if ($code === '') {
    header('Location: login?erro=google_falhou');
    exit;
}

$token_dados = googleTrocarCodigoPorToken($code);
if (!$token_dados) {
    header('Location: login?erro=google_falhou');
    exit;
}

$dados_google = googleObterDadosUsuario($token_dados['access_token']);
if (!$dados_google) {
    header('Location: login?erro=google_falhou');
    exit;
}

$google_id = (string) $dados_google['sub'];
$email = trim((string) $dados_google['email']);
$nome = mb_substr(trim((string) ($dados_google['name'] ?? '')), 0, 100) ?: $email;

global $pdo;

try {
    // 1) já usou Google aqui antes -- acha direto pelo id do Google (não pelo e-mail: o
    //    e-mail da conta Google pode ter sido trocado, o "sub" nunca muda).
    $stmt = $pdo->prepare("SELECT id, nome, email, perfil FROM usuarios WHERE google_id = ? LIMIT 1");
    $stmt->execute([$google_id]);
    $usuario = $stmt->fetch();

    if (!$usuario) {
        // 2) primeira vez com Google, mas já existe conta com esse e-mail (criada por
        //    senha) -- liga as duas. Seguro porque o Google já confirmou (email_verified)
        //    que quem está aqui é dono de fato desse e-mail, não é uma reivindicação sem
        //    prova vinda de um campo qualquer.
        $stmt = $pdo->prepare("SELECT id, nome, email, perfil FROM usuarios WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $usuario = $stmt->fetch();

        if ($usuario) {
            $pdo->prepare("UPDATE usuarios SET google_id = ? WHERE id = ?")->execute([$google_id, $usuario['id']]);
        } else {
            // 3) ninguém com esse e-mail nem esse Google -- cria conta nova. Senha aleatória
            //    e inutilizável (ninguém sabe, login por senha nunca vai bater) só pra
            //    satisfazer a coluna NOT NULL -- não muda o schema existente por causa disso.
            //    Perfil sempre 'usuario': nenhuma conta admin nasce por aqui.
            $senha_inutilizavel = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO usuarios (nome, email, google_id, senha, perfil) VALUES (?, ?, ?, ?, 'usuario')");
            $stmt->execute([$nome, $email, $google_id, $senha_inutilizavel]);
            $usuario = [
                'id' => (int) $pdo->lastInsertId(),
                'nome' => $nome,
                'email' => $email,
                'perfil' => 'usuario',
            ];
        }
    }
} catch (\Throwable $e) {
    // Ex: dois cliques simultâneos no mesmo instante criando a mesma conta nova em paralelo
    // -- colide na UNIQUE de email/google_id. Raro, mas não pode virar tela em branco.
    error_log('[google_callback] falha ao localizar/criar usuário: ' . $e->getMessage());
    header('Location: login?erro=google_falhou');
    exit;
}

// Login social é tratado como "lembrar de mim" por padrão -- pedir pra refazer o consentimento
// do Google toda vez que o navegador fechar seria mais fricção do que a senha com "lembrar"
// já evita hoje.
logarUsuarioNaSessao($usuario, true);

header('Location: ' . (($usuario['perfil'] ?? '') === 'admin' ? 'admin/dashboard' : 'index'));
exit;
