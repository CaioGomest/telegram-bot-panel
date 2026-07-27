<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../conexao.php';
require_once __DIR__ . '/email.php';
require_once __DIR__ . '/log.php';

function usuarioLogado(): bool {
    return isset($_SESSION['usuario_id']) && $_SESSION['usuario_id'] > 0;
}

function verificarLogin(): void {
    if (!usuarioLogado()) {
        header('Location: login.php?erro=acesso');
        exit;
    }
}

function fazerLogin(string $email, string $senha): bool {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("SELECT id, nome, email, senha, perfil FROM usuarios WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $usuario = $stmt->fetch();
        
        if ($usuario && password_verify($senha, $usuario['senha'])) {
            $_SESSION['usuario_id'] = (int)$usuario['id'];
            $_SESSION['usuario_nome'] = $usuario['nome'];
            $_SESSION['usuario_email'] = $usuario['email'];
            $_SESSION['usuario_perfil'] = $usuario['perfil'];
            
            registrarAtividade((int)$usuario['id'], 'sistema', 'Login', 'Usuário realizou login no sistema.');
            return true;
        }
    } catch (PDOException $e) {
        error_log("Erro no login: " . $e->getMessage());
    }
    
    return false;
}

function ehAdmin(): bool {
    return isset($_SESSION['usuario_perfil']) && $_SESSION['usuario_perfil'] === 'admin';
}

function verificarAdmin(): void {
    verificarLogin();
    if (!ehAdmin()) {
        header('Location: index.php?erro=sem_permissao');
        exit;
    }
}

function fazerLogout(): void {
    if (usuarioLogado()) {
        registrarAtividade((int)$_SESSION['usuario_id'], 'sistema', 'Logout', 'Usuário saiu do sistema.');
    }
    session_unset();
    session_destroy();
    header('Location: login.php');
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
    
    if (strlen($senha) < 6) {
        return ['sucesso' => false, 'erro' => 'A senha deve ter pelo menos 6 caracteres.'];
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

function atualizarPerfilUsuario(int $id, string $nome, string $email, ?string $senha = null): array {
    global $pdo;
    
    if (empty($nome) || empty($email)) {
        return ['sucesso' => false, 'erro' => 'Nome e Email são obrigatórios.'];
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['sucesso' => false, 'erro' => 'Email inválido.'];
    }
    
    try {
        $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = ? AND id != ?");
        $stmt->execute([$email, $id]);
        if ($stmt->fetch()) {
            return ['sucesso' => false, 'erro' => 'Este email já está em uso por outro usuário.'];
        }

        if (!empty($senha)) {
            if (strlen($senha) < 6) {
                return ['sucesso' => false, 'erro' => 'A senha deve ter pelo menos 6 caracteres.'];
            }
            $hash = password_hash($senha, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE usuarios SET nome = ?, email = ?, senha = ?, atualizado_em = NOW() WHERE id = ?");
            $executou = $stmt->execute([$nome, $email, $hash, $id]);
        } else {
            $stmt = $pdo->prepare("UPDATE usuarios SET nome = ?, email = ?, atualizado_em = NOW() WHERE id = ?");
            $executou = $stmt->execute([$nome, $email, $id]);
        }

        if ($executou) {
            if (isset($_SESSION['usuario_id']) && $_SESSION['usuario_id'] == $id) {
                $_SESSION['usuario_nome'] = $nome;
                $_SESSION['usuario_email'] = $email;
            }
            
            registrarAtividade($id, 'sistema', 'Perfil', 'Atualizou os dados do perfil.');
            return ['sucesso' => true];
        } else {
            return ['sucesso' => false, 'erro' => 'Não foi possível salvar as alterações.'];
        }
        
    } catch (PDOException $e) {
        error_log("Erro ao atualizar perfil: " . $e->getMessage());
        return ['sucesso' => false, 'erro' => 'Erro interno ao atualizar perfil.'];
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
            SELECT id, nome, email, perfil, criado_em 
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
        $email = trim($dados['email'] ?? '');
        if (!$email) {
            echo json_encode(['sucesso' => false, 'erro' => 'Email não informado']);
            exit;
        }
        
        try {
            $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = ?");
            $stmt->execute([$email]);
            if (!$stmt->fetch()) {
                // Não revelar que email não existe por segurança, mas retorna sucesso falso
                echo json_encode(['sucesso' => false, 'erro' => 'Email não encontrado']);
                exit;
            }
            
            $codigo = sprintf('%06d', mt_rand(0, 999999));
            $_SESSION['recuperacao_email'] = $email;
            $_SESSION['recuperacao_codigo'] = $codigo;
            $_SESSION['recuperacao_expira'] = time() + (15 * 60);
            
            $enviado = enviarEmailCodigo($email, $codigo);
            if ($enviado) {
                echo json_encode(['sucesso' => true]);
            } else {
                echo json_encode(['sucesso' => false, 'erro' => 'Falha ao enviar email']);
            }
        } catch (Exception $e) {
            echo json_encode(['sucesso' => false, 'erro' => 'Erro interno']);
        }
        exit;
    }
    
    if ($acao_api === 'trocar_senha_login') {
        $email = trim($dados['email'] ?? '');
        $codigo = trim($dados['codigo'] ?? '');
        $nova_senha = $dados['nova_senha'] ?? '';
        
        if (!$email || !$codigo || !$nova_senha) {
            echo json_encode(['sucesso' => false, 'erro' => 'Dados incompletos']);
            exit;
        }
        
        $sessao_email = $_SESSION['recuperacao_email'] ?? '';
        $sessao_codigo = $_SESSION['recuperacao_codigo'] ?? '';
        $sessao_expira = $_SESSION['recuperacao_expira'] ?? 0;
        
        if ($email !== $sessao_email || $codigo !== $sessao_codigo || time() > $sessao_expira) {
            echo json_encode(['sucesso' => false, 'erro' => 'Código inválido ou expirado']);
            exit;
        }
        
        try {
            $senha_hash = password_hash($nova_senha, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE usuarios SET senha = ? WHERE email = ?");
            $stmt->execute([$senha_hash, $email]);
            
            unset($_SESSION['recuperacao_email']);
            unset($_SESSION['recuperacao_codigo']);
            unset($_SESSION['recuperacao_expira']);
            
            echo json_encode(['sucesso' => true]);
        } catch (Exception $e) {
            echo json_encode(['sucesso' => false, 'erro' => 'Erro ao atualizar senha']);
        }
        exit;
    }
}
