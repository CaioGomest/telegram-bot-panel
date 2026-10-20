<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        // 'Secure' só quando o acesso já é HTTPS — evita quebrar o cookie em
        // ambiente de desenvolvimento local sem certificado.
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

require_once __DIR__ . '/../conexao.php';
require_once __DIR__ . '/email.php';
require_once __DIR__ . '/verificacao_email.php';
require_once __DIR__ . '/log.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/limite_taxa.php';
require_once __DIR__ . '/configuracoes.php';

function usuarioLogado(): bool {
    return isset($_SESSION['usuario_id']) && $_SESSION['usuario_id'] > 0;
}

function verificarLogin(): void {
    if (!usuarioLogado()) {
        // A home (/) não é uma tentativa de login que falhou: quem abre o site
        // sem sessão só precisa da tela de entrar, sem ?erro=acesso.
        // Página interna protegida continua avisando que o login é obrigatório.
        $caminho = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $caminho = rtrim((string) $caminho, '/');
        $eh_entrada = $caminho === '' || $caminho === '/index' || $caminho === '/index.php';
        header('Location: ' . ($eh_entrada ? '/login' : '/login?erro=acesso'));
        exit;
    }
}

/**
 * Neutraliza "injeção de fórmula" em CSV: uma célula de texto que começa com = + - @ (ou tab/CR) é
 * executada como fórmula pelo Excel/Planilhas ao abrir o arquivo. Nome de lead (vem do Telegram),
 * nome de usuário/bot e TXID são texto livre de terceiros, então o prefixo ' faz virar texto puro.
 */
function csvSeguro($valor)
{
    // "-" sozinho é o marcador de "sem valor" das exportações, não uma fórmula.
    if ($valor === '-') {
        return $valor;
    }
    if (is_string($valor) && $valor !== '' && strpos("=+-@\t\r", $valor[0]) !== false) {
        return "'" . $valor;
    }
    return $valor;
}

function loginEstaBloqueado(string $identificador): bool {
    global $pdo;

    try {
        $stmt = $pdo->prepare("SELECT bloqueado_ate FROM tentativas_login WHERE identificador = ?");
        $stmt->execute([$identificador]);
        $bloqueado_ate = $stmt->fetchColumn();
        return $bloqueado_ate && strtotime((string)$bloqueado_ate) > time();
    } catch (\Throwable $e) {
        // Se a tabela ainda não existir (deploy antigo sem atualizar o banco),
        // não trava o login — só deixa de limitar tentativas até rodar a atualização.
        return false;
    }
}

function registrarTentativaLoginFalha(string $identificador): void {
    global $pdo;

    try {
        $stmt = $pdo->prepare("SELECT tentativas FROM tentativas_login WHERE identificador = ?");
        $stmt->execute([$identificador]);
        $tentativas = (int)$stmt->fetchColumn() + 1;

        $bloqueado_ate = $tentativas >= 5 ? date('Y-m-d H:i:s', time() + 15 * 60) : null;

        $stmt = $pdo->prepare("
            INSERT INTO tentativas_login (identificador, tentativas, bloqueado_ate)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE tentativas = ?, bloqueado_ate = ?
        ");
        $stmt->execute([$identificador, $tentativas, $bloqueado_ate, $tentativas, $bloqueado_ate]);
    } catch (\Throwable $e) {
        // Idem: sem a tabela, só não limita — não derruba o login.
    }
}

function resetarTentativasLogin(string $identificador): void {
    global $pdo;

    try {
        $pdo->prepare("DELETE FROM tentativas_login WHERE identificador = ?")->execute([$identificador]);
    } catch (\Throwable $e) {
    }
}

/**
 * Efetivamente "loga" um usuário já autenticado por qualquer meio (senha, Google) na
 * sessão PHP. Extraído de dentro de fazerLogin() pra login com Google reusar exatamente a
 * mesma proteção contra fixação de sessão, em vez de duplicar código sensível de auth --
 * as duas formas de entrar têm que terminar no mesmo estado de sessão.
 */
function logarUsuarioNaSessao(array $usuario, bool $lembrar = false): void {
    // Troca o ID de sessão no momento do login (mantendo os dados da sessão
    // atual) -- evita fixação de sessão: sem isso, um ID de sessão que o
    // atacante já conhecia antes do login (ex. plantado via link) continuaria
    // válido e autenticado depois do usuário logar.
    session_regenerate_id(true);

    $_SESSION['usuario_id'] = (int)$usuario['id'];
    $_SESSION['usuario_nome'] = $usuario['nome'];
    $_SESSION['usuario_email'] = $usuario['email'];
    $_SESSION['usuario_perfil'] = $usuario['perfil'];

    if ($lembrar) {
        // O cookie da sessão já foi enviado como "cookie de sessão" (lifetime=0,
        // morre ao fechar o navegador) lá em cima, antes da gente saber se o
        // usuário marcou "lembrar de mim" -- por isso reenviamos o mesmo cookie
        // aqui com validade de 30 dias, sobrescrevendo o que o navegador já tem.
        // gc_maxlifetime também precisa subir, senão o PHP apaga os dados da
        // sessão no servidor depois de só ~24min de inatividade (padrão),
        // mesmo com o cookie do navegador ainda válido.
        $trinta_dias = 30 * 24 * 60 * 60;
        ini_set('session.gc_maxlifetime', (string) $trinta_dias);
        $params = session_get_cookie_params();
        setcookie(session_name(), session_id(), [
            'expires' => time() + $trinta_dias,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'],
        ]);
    }

    registrarAtividade((int)$usuario['id'], 'sistema', 'Login', 'Usuário realizou login no sistema.');
}

function fazerLogin(string $email, string $senha, bool $lembrar = false): bool {
    global $pdo;

    if (loginEstaBloqueado($email)) {
        return false;
    }

    try {
        $stmt = $pdo->prepare("SELECT id, nome, email, senha, perfil FROM usuarios WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $usuario = $stmt->fetch();

        if ($usuario && password_verify($senha, $usuario['senha'])) {
            logarUsuarioNaSessao($usuario, $lembrar);
            resetarTentativasLogin($email);
            return true;
        }

        registrarTentativaLoginFalha($email);
    } catch (PDOException $e) {
        error_log("Erro no login: " . $e->getMessage());
    }
    
    return false;
}

function ehAdmin(): bool {
    return isset($_SESSION['usuario_perfil']) && $_SESSION['usuario_perfil'] === 'admin';
}

function colunaFotoPerfilDisponivel(bool $recalcular = false): bool {
    global $pdo;
    static $disponivel = null;
    if ($recalcular) {
        $disponivel = null;
    }
    if ($disponivel !== null) {
        return $disponivel;
    }
    try {
        $pdo->query("SELECT foto_perfil FROM usuarios LIMIT 0");
        $disponivel = true;
    } catch (Throwable $e) {
        $disponivel = false;
    }
    return $disponivel;
}

function garantirColunaFotoPerfil(): void {
    global $pdo;
    if (colunaFotoPerfilDisponivel()) {
        return;
    }
    try {
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN foto_perfil VARCHAR(255) DEFAULT NULL");
    } catch (Throwable $e) {
        error_log('[foto_perfil] ' . $e->getMessage());
    }
    colunaFotoPerfilDisponivel(true);
}

function nomeArquivoFotoPerfil(string $arquivo): string {
    $arquivo = basename($arquivo);
    return preg_match('/^[a-zA-Z0-9._-]+$/', $arquivo) ? $arquivo : '';
}

function buscarCaminhoFotoPerfil(int $id): string {
    global $pdo;
    if ($id <= 0 || !colunaFotoPerfilDisponivel()) {
        return '';
    }
    try {
        $stmt = $pdo->prepare("SELECT foto_perfil FROM usuarios WHERE id = ?");
        $stmt->execute([$id]);
        return nomeArquivoFotoPerfil((string) $stmt->fetchColumn());
    } catch (Throwable $e) {
        return '';
    }
}

function fotoPerfilDaSessao(): string {
    if (!usuarioLogado()) {
        return '';
    }
    if (!array_key_exists('usuario_foto', $_SESSION)) {
        $_SESSION['usuario_foto'] = buscarCaminhoFotoPerfil((int) $_SESSION['usuario_id']);
    }
    return nomeArquivoFotoPerfil((string) $_SESSION['usuario_foto']);
}

function urlFotoPerfil(string $arquivo, string $caminho_base = ''): string {
    $arquivo = nomeArquivoFotoPerfil($arquivo);
    if ($arquivo === '') {
        return '';
    }
    $url = $caminho_base . 'uploads/perfis/' . rawurlencode($arquivo);
    $disco = __DIR__ . '/../uploads/perfis/' . $arquivo;
    if (is_file($disco)) {
        $url .= '?v=' . filemtime($disco);
    }
    return $url;
}

function htmlAvatarUsuario(string $iniciais, string $foto, string $classe, string $caminho_base = ''): string {
    $url = urlFotoPerfil($foto, $caminho_base);
    if ($url !== '') {
        return '<span class="' . htmlspecialchars($classe) . '"><img src="' . htmlspecialchars($url) . '" alt=""></span>';
    }
    return '<span class="' . htmlspecialchars($classe) . '">' . htmlspecialchars($iniciais) . '</span>';
}

function salvarFotoPerfil(int $id, array $arquivo): array {
    global $pdo;
    garantirColunaFotoPerfil();
    if (!colunaFotoPerfilDisponivel()) {
        return ['sucesso' => false, 'erro' => 'Não foi possível preparar o perfil para a foto.'];
    }

    if (($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($arquivo['tmp_name'])) {
        return ['sucesso' => false, 'erro' => 'Nenhuma imagem enviada.'];
    }
    $tamanho = (int) ($arquivo['size'] ?? 0);
    if ($tamanho <= 0 || $tamanho > 2 * 1024 * 1024) {
        return ['sucesso' => false, 'erro' => 'A foto deve ter no máximo 2 MB.'];
    }

    $info = @getimagesize($arquivo['tmp_name']);
    $tipos = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    $extensao = $tipos[$info[2] ?? 0] ?? '';
    if ($extensao === '') {
        return ['sucesso' => false, 'erro' => 'Use uma imagem JPG, PNG ou WebP.'];
    }

    $diretorio = __DIR__ . '/../uploads/perfis';
    if (!is_dir($diretorio) && !mkdir($diretorio, 0755, true) && !is_dir($diretorio)) {
        return ['sucesso' => false, 'erro' => 'Não foi possível salvar a foto.'];
    }

    $nome = 'perfil_' . $id . '_' . time() . '.' . $extensao;
    $destino = $diretorio . '/' . $nome;
    if (!move_uploaded_file($arquivo['tmp_name'], $destino)) {
        return ['sucesso' => false, 'erro' => 'Não foi possível salvar a foto.'];
    }

    $anterior = buscarCaminhoFotoPerfil($id);
    try {
        $stmt = $pdo->prepare("UPDATE usuarios SET foto_perfil = ? WHERE id = ?");
        $stmt->execute([$nome, $id]);
    } catch (Throwable $e) {
        @unlink($destino);
        error_log('[foto_perfil] ' . $e->getMessage());
        return ['sucesso' => false, 'erro' => 'Não foi possível salvar a foto.'];
    }

    if ($anterior !== '' && $anterior !== $nome) {
        $velha = $diretorio . '/' . $anterior;
        if (is_file($velha)) {
            @unlink($velha);
        }
    }
    if (isset($_SESSION['usuario_id']) && (int) $_SESSION['usuario_id'] === $id) {
        $_SESSION['usuario_foto'] = $nome;
    }
    return ['sucesso' => true];
}

function removerFotoPerfil(int $id): array {
    global $pdo;
    if (!colunaFotoPerfilDisponivel()) {
        return ['sucesso' => true];
    }
    $anterior = buscarCaminhoFotoPerfil($id);
    try {
        $stmt = $pdo->prepare("UPDATE usuarios SET foto_perfil = NULL WHERE id = ?");
        $stmt->execute([$id]);
    } catch (Throwable $e) {
        error_log('[foto_perfil] ' . $e->getMessage());
        return ['sucesso' => false, 'erro' => 'Não foi possível remover a foto.'];
    }
    if ($anterior !== '') {
        $velha = __DIR__ . '/../uploads/perfis/' . $anterior;
        if (is_file($velha)) {
            @unlink($velha);
        }
    }
    if (isset($_SESSION['usuario_id']) && (int) $_SESSION['usuario_id'] === $id) {
        $_SESSION['usuario_foto'] = '';
    }
    return ['sucesso' => true];
}

function verificarAdmin(): void {
    verificarLogin();
    if (!ehAdmin()) {
        // Sem aviso: quem não é admin não precisa nem saber que a área existe (o link nunca aparece para ele; só chega aqui por endereço digitado/favorito).
        header('Location: /index');
        exit;
    }
}

/**
 * O contrário de verificarAdmin(): barra o ADMIN nas telas de operação (bots, fluxos, leads,
 * remarketing...). Quem opera bot é o usuário; o admin administra a plataforma.
 *
 * A barra lateral já escondia esses itens do admin desde sempre, mas era só visual -- as
 * páginas continuavam abrindo por URL, e o admin que caísse em /fluxo acabava criando fluxo
 * em nome próprio sem perceber. Ver anotacoes/OBSERVACOES.md.
 */
function bloquearAdmin(): void {
    verificarLogin();
    if (ehAdmin()) {
        header('Location: /admin/dashboard');
        exit;
    }
}
function sistemaJaInstalado(): bool {
    global $pdo;

    if (!isset($pdo)) {
        return false;
    }

    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE perfil = 'admin'");
        return (int)$stmt->fetchColumn() > 0;
    } catch (\Throwable $e) {
        return false;
    }
}

function verificarAdminOuInstalacao(): void {
    if (sistemaJaInstalado()) {
        verificarAdmin();
    }
}

function fazerLogout(): void {
    if (usuarioLogado()) {
        registrarAtividade((int)$_SESSION['usuario_id'], 'sistema', 'Logout', 'Usuário saiu do sistema.');
    }
    session_unset();
    session_destroy();
    header('Location: /login');
    exit;
}

function criarUsuario(string $nome, string $email, string $senha): array {
    global $pdo;
    
    if (empty($nome) || empty($email) || empty($senha)) {
        return ['sucesso' => false, 'erro' => 'Preencha todos os campos.'];
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['sucesso' => false, 'erro' => 'Email inválido.'];
    }
    
    if (strlen($senha) < 8) {
        return ['sucesso' => false, 'erro' => 'A senha deve ter pelo menos 8 caracteres.'];
    }

    try {
        $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            return ['sucesso' => false, 'erro' => 'Este email já está cadastrado.'];
        }

        $senha_hash = password_hash($senha, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO usuarios (nome, email, senha, perfil) VALUES (?, ?, ?, 'usuario')");
        
        if ($stmt->execute([$nome, $email, $senha_hash])) {
            $id_novo_usuario = (int)$pdo->lastInsertId();
            registrarAtividade($id_novo_usuario, 'sistema', 'Cadastro', 'Novo usuário cadastrado.');
            // E-mail de boas-vindas com o link de confirmação (vai pela fila): falha aqui nunca derruba o cadastro.
            try {
                enviarVerificacaoParaUsuario($id_novo_usuario, true);
            } catch (\Throwable $e) {
                error_log('Falha ao enviar e-mail de boas-vindas: ' . $e->getMessage());
            }
            return ['sucesso' => true];
        } else {
            return ['sucesso' => false, 'erro' => 'Erro ao criar usuário. Tente novamente.'];
        }
    } catch (PDOException $e) {
        error_log("Erro ao criar usuário: " . $e->getMessage());
        return ['sucesso' => false, 'erro' => 'Erro interno do sistema.'];
    }
}

function listarTodosUsuarios(int $limite = 20, int $offset = 0): array {
    global $pdo;
    try {
        $sql = "
            SELECT u.id, u.nome, u.email, u.perfil, u.criado_em,
            (SELECT COUNT(*) FROM bots b WHERE b.id_usuario = u.id) as total_bots,
            (SELECT COALESCE(SUM(v.valor), 0) FROM vendas v 
             JOIN bots b ON v.bot_id = b.id 
             WHERE b.id_usuario = u.id AND v.status = 'pago') as total_vendas
            FROM usuarios u 
            ORDER BY u.criado_em DESC
            LIMIT :limite OFFSET :offset
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Erro ao listar usuários: " . $e->getMessage());
        return [];
    }
}

/**
 * $exigir_senha_atual/$senha_atual só fazem sentido quando quem está trocando a própria
 * senha é o dono da conta (configuracao_usuario.php) -- confirma que quem está com a sessão
 * aberta sabe a senha antes de trocar. O admin resetando a senha de outro usuário
 * (ajax/editar_usuario.php) não passa isso: ele tem autorização própria (verificarAdmin())
 * e não tem como saber a senha atual de outra pessoa.
 */
function atualizarPerfilUsuario(int $id, string $nome, string $email, ?string $senha = null, ?string $apelido_publico = null, ?string $senha_atual = null, bool $exigir_senha_atual = false): array {
    global $pdo;

    if (empty($nome) || empty($email)) {
        return ['sucesso' => false, 'erro' => 'Nome e Email são obrigatórios.'];
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['sucesso' => false, 'erro' => 'Email inválido.'];
    }

    // null = quem chamou não está editando o apelido (ex.: o admin editando outro usuário): preserva o que já existe.
    // Antes, null virava '' -> NULL e todo "editar usuário" do admin apagava o apelido do Ranking.
    $alterar_apelido = $apelido_publico !== null;
    $apelido_publico = trim((string) $apelido_publico);
    $apelido_publico = $apelido_publico !== '' ? mb_substr($apelido_publico, 0, 40) : null;

    try {
        $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = ? AND id != ?");
        $stmt->execute([$email, $id]);
        if ($stmt->fetch()) {
            return ['sucesso' => false, 'erro' => 'Este email já está em uso por outro usuário.'];
        }
        $stmt_email_atual = $pdo->prepare("SELECT email FROM usuarios WHERE id = ?");
        $stmt_email_atual->execute([$id]);
        $email_anterior = $stmt_email_atual->fetchColumn();
        $email_anterior = $email_anterior !== false ? (string) $email_anterior : null;

        if (!empty($senha)) {
            if ($exigir_senha_atual) {
                $stmt_hash = $pdo->prepare("SELECT senha FROM usuarios WHERE id = ?");
                $stmt_hash->execute([$id]);
                $hash_atual = (string) $stmt_hash->fetchColumn();
                if ($hash_atual === '' || !password_verify((string) $senha_atual, $hash_atual)) {
                    return ['sucesso' => false, 'erro' => 'Senha atual incorreta.'];
                }
            }
            // Troca feita pela própria pessoa segue a mesma regra do cadastro (8+); o admin definindo senha de
            // outro usuário mantém o mínimo antigo (o ambiente de teste usa senhas curtas de propósito).
            $minimo_senha = $exigir_senha_atual ? 8 : 6;
            if (strlen($senha) < $minimo_senha) {
                return ['sucesso' => false, 'erro' => "A senha deve ter pelo menos $minimo_senha caracteres."];
            }
            $hash = password_hash($senha, PASSWORD_DEFAULT);
            if ($alterar_apelido) {
                $stmt = $pdo->prepare("UPDATE usuarios SET nome = ?, email = ?, senha = ?, apelido_publico = ?, atualizado_em = NOW() WHERE id = ?");
                $executou = $stmt->execute([$nome, $email, $hash, $apelido_publico, $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE usuarios SET nome = ?, email = ?, senha = ?, atualizado_em = NOW() WHERE id = ?");
                $executou = $stmt->execute([$nome, $email, $hash, $id]);
            }
        } else {
            if ($alterar_apelido) {
                $stmt = $pdo->prepare("UPDATE usuarios SET nome = ?, email = ?, apelido_publico = ?, atualizado_em = NOW() WHERE id = ?");
                $executou = $stmt->execute([$nome, $email, $apelido_publico, $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE usuarios SET nome = ?, email = ?, atualizado_em = NOW() WHERE id = ?");
                $executou = $stmt->execute([$nome, $email, $id]);
            }
        }

        if ($executou && $email_anterior !== null && mb_strtolower($email_anterior) !== mb_strtolower($email)) {
            // O selo "verificado" era do endereço antigo: o novo precisa ser confirmado de novo.
            try {
                $pdo->prepare("UPDATE usuarios SET email_verificado_em = NULL WHERE id = ?")->execute([$id]);
                unset($_SESSION['email_verificado_' . $id]);
            } catch (Throwable $e) {
                error_log('[perfil] não foi possível limpar a verificação de e-mail: ' . $e->getMessage());
            }
        }

        if ($executou) {
            if (isset($_SESSION['usuario_id']) && $_SESSION['usuario_id'] == $id) {
                $_SESSION['usuario_nome'] = $nome;
                $_SESSION['usuario_email'] = $email;
            }
            
            registrarAtividade($id, 'sistema', 'Perfil', 'Atualizou os dados do perfil.');

            // Avisos por e-mail (pela fila; nunca derrubam a gravação): senha trocada e e-mail de acesso trocado. O aviso do e-mail vai para o
            // endereço ANTIGO (é ele que avisa se a conta foi invadida) e o novo recebe o link de confirmação.
            try {
                if (!empty($senha)) {
                    // Se o e-mail também mudou, o aviso vai para o endereço ANTIGO: é o dono de verdade quem precisa saber.
                    enviarEmailSenhaAlterada($nome, $email_anterior ?? $email);
                }
                if ($email_anterior !== null && mb_strtolower($email_anterior) !== mb_strtolower($email)) {
                    enviarEmailEmailAlterado($nome, $email_anterior, $email);
                    enviarVerificacaoParaUsuario($id, false);
                }
            } catch (Throwable $e) {
                error_log('[perfil] aviso por e-mail falhou: ' . $e->getMessage());
            }
            return ['sucesso' => true];
        } else {
            return ['sucesso' => false, 'erro' => 'Não foi possível salvar as alterações.'];
        }
        
    } catch (PDOException $e) {
        error_log("Erro ao atualizar perfil: " . $e->getMessage());
        return ['sucesso' => false, 'erro' => 'Erro interno ao atualizar perfil.'];
    }
}

/**
 * WHERE do filtro da lista de usuários do admin: busca por nome, e-mail, apelido ou ID. Tudo com parâmetros: nada do que vem da URL entra solto no SQL.
 * @return array{0:string, 1:array}
 */
function montarFiltroUsuarios(array $f): array {
    $where = ['1 = 1'];
    $params = [];
    $busca = trim((string) ($f['busca'] ?? ''));
    if ($busca !== '') {
        $like = '%' . addcslashes($busca, '%_\\') . '%';
        $cond = '(u.nome LIKE ? OR u.email LIKE ? OR u.apelido_publico LIKE ?';
        array_push($params, $like, $like, $like);
        if (ctype_digit($busca) && strlen($busca) <= 9) {
            $cond .= ' OR u.id = ?';
            $params[] = (int) $busca;
        }
        $where[] = $cond . ')';
    }
    return [implode(' AND ', $where), $params];
}

function contarUsuariosFiltrados(array $f): int {
    global $pdo;
    [$where, $params] = montarFiltroUsuarios($f);
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM usuarios u WHERE $where");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log('Erro ao contar usuários filtrados: ' . $e->getMessage());
        return 0;
    }
}

/** Lista de usuários do admin com filtros e ordem (recentes | antigos | nome). Mesmas colunas de listarTodosUsuarios() + e-mail confirmado e Google. */
function listarUsuariosFiltrados(array $f, int $limite, int $offset): array {
    global $pdo;
    [$where, $params] = montarFiltroUsuarios($f);
    $ordem = ['antigos' => 'u.criado_em ASC, u.id ASC', 'nome' => 'u.nome ASC, u.id ASC'][$f['ordem'] ?? ''] ?? 'u.criado_em DESC, u.id DESC';
    $limite = max(1, $limite);
    $offset = max(0, $offset);
    try {
        $stmt = $pdo->prepare("
            SELECT u.id, u.nome, u.email, u.perfil, u.criado_em, u.email_verificado_em, (u.google_id IS NOT NULL) AS tem_google,
            (SELECT COUNT(*) FROM bots b WHERE b.id_usuario = u.id) AS total_bots,
            (SELECT COALESCE(SUM(v.valor), 0) FROM vendas v
             JOIN bots b ON v.bot_id = b.id
             WHERE b.id_usuario = u.id AND v.status = 'pago') AS total_vendas
            FROM usuarios u
            WHERE $where
            ORDER BY $ordem
            LIMIT $limite OFFSET $offset
        ");
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Erro ao listar usuários filtrados: ' . $e->getMessage());
        return [];
    }
}

function contarTotalUsuarios(): int {
    global $pdo;
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM usuarios");
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Erro ao contar usuários: " . $e->getMessage());
        return 0;
    }
}

function obterDetalhesUsuario(int $id): array {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            SELECT id, nome, email, apelido_publico, perfil, criado_em
            FROM usuarios
            WHERE id = ?
        ");
        $stmt->execute([$id]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$usuario) {
            error_log("Usuario ID $id nao encontrado na tabela usuarios.");
            return [];
        }
        
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as qtd_vendas, 
                COALESCE(SUM(valor), 0) as total_vendas
            FROM vendas v
            JOIN bots b ON v.bot_id = b.id
            WHERE b.id_usuario = ? AND v.status = 'pago'
        ");
        $stmt->execute([$id]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $usuario['qtd_vendas'] = $stats['qtd_vendas'];
        $usuario['total_vendas'] = $stats['total_vendas'];
        
        // Ajustado: Tabela bots usa 'primeiro_nome' e 'nome_usuario', não 'nome'
        $stmt = $pdo->prepare("
            SELECT id, primeiro_nome as nome, token, criado_em 
            FROM bots 
            WHERE id_usuario = ? 
            ORDER BY criado_em DESC
        ");
        $stmt->execute([$id]);
        $usuario['bots'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Nem todo ambiente tem a tabela/coluna 'atividades.id_usuario' pronta; se a consulta falhar,
        // capturamos a exceção abaixo e devolvemos array vazio em vez de erro fatal.
        try {
            $stmt = $pdo->prepare("
                SELECT tipo, titulo, descricao, criado_em as data_hora 
                FROM atividades 
                WHERE id_usuario = ? AND tipo NOT IN ('venda', 'lead')
                ORDER BY criado_em DESC 
                LIMIT 10
            ");
            $stmt->execute([$id]);
            $usuario['logs'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            // Se der erro na tabela atividades (ex: não existe coluna id_usuario), ignoramos logs
            error_log("Erro ao buscar logs do usuario $id: " . $e->getMessage());
            $usuario['logs'] = [];
        }

        return $usuario;
        
    } catch (PDOException $e) {
        error_log("Erro ao obter detalhes do usuário: " . $e->getMessage());
        return [];
    }
}

/**
 * Entrega a resposta JSON AGORA e segue trabalhando (procurar a conta e mandar o e-mail) sem o navegador esperar. Sem isso, "e-mail existe"
 * respondia mais devagar (leva o tempo do SMTP) que "não existe", e dava para descobrir quem tem conta medindo a resposta.
 */
function responderJsonEContinuar(array $dados): void
{
    $corpo = json_encode($dados);
    ignore_user_abort(true);
    header('Content-Length: ' . strlen((string) $corpo));
    echo $corpo;
    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
    @flush();
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } elseif (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
    }
}

/** Gasta uma lista de limites [chave, máximo, janela, texto com %s] e responde o erro (com "espera") no primeiro que estourar. */
function aplicarLimitesOuResponder(array $limites): void
{
    foreach ($limites as [$chave, $max, $janela, $texto]) {
        [$permitido, $espera] = limiteTaxaConsumir($chave, $max, $janela);
        if (!$permitido) {
            echo json_encode(['sucesso' => false, 'erro' => sprintf($texto, limiteTaxaTextoEspera($espera)), 'espera' => $espera]);
            exit;
        }
    }
}

$acao_api = $_GET['acao'] ?? $_POST['acao'] ?? '';
$api_endpoint = $_GET['api'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $acao_api === 'logout') {
    fazerLogout();
}

if ($api_endpoint === 'usuario') {
    header('Content-Type: application/json; charset=utf-8');
    
    $input = file_get_contents('php://input');
    $dados = json_decode($input ?: '{}', true);
    if (!is_array($dados)) $dados = [];
    
    if ($acao_api === 'enviar_codigo_senha_login') {
        $email = mb_strtolower(trim((string) ($dados['email'] ?? '')));
        if ($email === '' || strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['sucesso' => false, 'erro' => 'Informe um e-mail válido.']);
            exit;
        }

        // Limites de PEDIDOS de código. Valem IGUAL para e-mail que existe e que não existe (senão o próprio limite revelaria quem tem conta).
        // Protegem a pessoa dona do e-mail (ninguém lota a caixa dela), o remetente (a cota diária do provedor de SMTP) e o painel.
        $ip = limiteTaxaIp();
        aplicarLimitesOuResponder([
            [limiteTaxaChaveEmail('rec_cd', $email), 1, 60,    'Aguarde %s para pedir outro código.'],
            [limiteTaxaChaveEmail('rec_h', $email),  3, 3600,  'Você já pediu códigos demais para este e-mail. Tente de novo em %s.'],
            [limiteTaxaChaveEmail('rec_d', $email),  6, 86400, 'Limite diário de códigos para este e-mail atingido. Tente de novo em %s.'],
            ['rec_ip_h:' . $ip,                      10, 3600, 'Muitos pedidos deste endereço. Tente de novo em %s.'],
            ['rec_global_h',                         120, 3600, 'Estamos com muitos pedidos agora. Tente de novo em alguns minutos.'],
        ]);

        // A resposta é sempre a mesma (exista a conta ou não) e sai ANTES de procurar a conta e enviar: o tempo de resposta não revela nada.
        responderJsonEContinuar(['sucesso' => true, 'mensagem' => 'Se esse e-mail estiver cadastrado, enviamos um código de 6 dígitos. Ele vale por 15 minutos.', 'espera' => 60]);

        try {
            $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                // O código fica na SESSÃO de quem pediu: só vale nesse navegador, é substituído a cada pedido (o anterior morre) e queima
                // sozinho depois de 5 erros. Falha de envio (SMTP fora do ar) só vai para o log: mudar a resposta entregaria que a conta existe.
                $codigo = sprintf('%06d', random_int(0, 999999));
                $_SESSION['recuperacao_email'] = $email;
                $_SESSION['recuperacao_codigo'] = $codigo;
                $_SESSION['recuperacao_expira'] = time() + (15 * 60);
                $_SESSION['recuperacao_tentativas'] = 0;
                if (!enviarEmailCodigo($email, $codigo)) {
                    error_log('Falha ao enviar código de recuperação (conta existente)');
                }
            }
        } catch (Throwable $e) {
            error_log('Erro ao gerar código de recuperação de senha: ' . $e->getMessage());
        }
        exit;
    }

    if ($acao_api === 'reenviar_verificacao_email') {
        if (!usuarioLogado()) {
            http_response_code(401);
            echo json_encode(['sucesso' => false, 'erro' => 'Faça login novamente.']);
            exit;
        }
        verificarCsrf();
        $id_logado = (int) $_SESSION['usuario_id'];
        if (emailVerificado($id_logado)) {
            echo json_encode(['sucesso' => true, 'mensagem' => 'Seu e-mail já está confirmado.']);
            exit;
        }
        aplicarLimitesOuResponder([
            ['verif_cd:' . $id_logado,             1, 60,   'Aguarde %s para pedir outro e-mail.'],
            ['verif_h:' . $id_logado,              5, 3600, 'Muitos pedidos. Tente de novo em %s.'],
            ['verif_ip_h:' . limiteTaxaIp(),       20, 3600, 'Muitos pedidos deste endereço. Tente de novo em %s.'],
        ]);
        $ok = enviarVerificacaoParaUsuario($id_logado, false);
        echo json_encode($ok
            ? ['sucesso' => true, 'mensagem' => 'Enviamos um novo e-mail de confirmação. Pode levar um minuto para chegar.', 'espera' => 60]
            : ['sucesso' => false, 'erro' => 'Não foi possível enviar agora. Tente de novo em instantes.']);
        exit;
    }

    if ($acao_api === 'trocar_senha_login') {
        $email = mb_strtolower(trim((string) ($dados['email'] ?? '')));
        $codigo = trim((string) ($dados['codigo'] ?? ''));
        $nova_senha = (string) ($dados['nova_senha'] ?? '');

        if (!$email || !$codigo || !$nova_senha) {
            echo json_encode(['sucesso' => false, 'erro' => 'Dados incompletos']);
            exit;
        }
        if (strlen($nova_senha) < 8) {
            echo json_encode(['sucesso' => false, 'erro' => 'A senha deve ter pelo menos 8 caracteres.']);
            exit;
        }
        if (strlen($nova_senha) > 200) {
            echo json_encode(['sucesso' => false, 'erro' => 'A senha é grande demais (máximo de 200 caracteres).']);
            exit;
        }

        // Tentativas de código erradas por ENDEREÇO (IP). Não existe mais trava por e-mail: ela deixava qualquer pessoa trancar a recuperação
        // de outra só errando o código de propósito. O que impede adivinhar é o código queimar depois de 5 erros e cada pedido novo (que é
        // o que gera código) ter limite por e-mail, por IP e global.
        $chave_ip = 'rec_tent_ip:' . limiteTaxaIp();
        $espera_ip = limiteTaxaEspera($chave_ip, 20, 3600);
        if ($espera_ip > 0) {
            echo json_encode(['sucesso' => false, 'erro' => 'Muitas tentativas deste endereço. Tente de novo em ' . limiteTaxaTextoEspera($espera_ip) . '.', 'espera' => $espera_ip]);
            exit;
        }

        $sessao_email = (string) ($_SESSION['recuperacao_email'] ?? '');
        $sessao_codigo = (string) ($_SESSION['recuperacao_codigo'] ?? '');
        $sessao_expira = (int) ($_SESSION['recuperacao_expira'] ?? 0);
        $erros_no_codigo = (int) ($_SESSION['recuperacao_tentativas'] ?? 0);

        $confere = $sessao_codigo !== '' && $email === $sessao_email && time() <= $sessao_expira && $erros_no_codigo < 5 && hash_equals($sessao_codigo, $codigo);
        if (!$confere) {
            limiteTaxaConsumir($chave_ip, 20, 3600);
            if ($sessao_codigo !== '' && $email === $sessao_email) {
                $erros_no_codigo++;
                $_SESSION['recuperacao_tentativas'] = $erros_no_codigo;
                if ($erros_no_codigo >= 5) {
                    unset($_SESSION['recuperacao_email'], $_SESSION['recuperacao_codigo'], $_SESSION['recuperacao_expira'], $_SESSION['recuperacao_tentativas']);
                    echo json_encode(['sucesso' => false, 'erro' => 'Muitos códigos errados: este código foi cancelado. Peça um novo código.']);
                    exit;
                }
            }
            echo json_encode(['sucesso' => false, 'erro' => 'Código inválido ou expirado']);
            exit;
        }

        try {
            $senha_hash = password_hash($nova_senha, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE usuarios SET senha = ? WHERE email = ?");
            $stmt->execute([$senha_hash, $email]);

            // Receber o código no e-mail prova que a pessoa é dona dele: marca como verificado e avisa da troca.
            $stmt = $pdo->prepare("SELECT id, nome FROM usuarios WHERE email = ?");
            $stmt->execute([$email]);
            if ($dono_conta = $stmt->fetch()) {
                marcarEmailVerificado((int) $dono_conta['id']);
                enviarEmailSenhaAlterada((string) $dono_conta['nome'], $email);
            }

            unset($_SESSION['recuperacao_email'], $_SESSION['recuperacao_codigo'], $_SESSION['recuperacao_expira'], $_SESSION['recuperacao_tentativas']);

            echo json_encode(['sucesso' => true]);
        } catch (Exception $e) {
            echo json_encode(['sucesso' => false, 'erro' => 'Erro ao atualizar senha']);
        }
        exit;
    }
}
