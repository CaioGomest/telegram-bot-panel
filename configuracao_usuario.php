<?php
declare(strict_types=1);
require_once __DIR__ . '/funcoes/usuario.php';

verificarLogin();

$usuario_id = $_SESSION['usuario_id'];
$mensagem = '';
$tipo_mensagem = '';

// Processar formulário
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome = trim($_POST['nome'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $senha = $_POST['senha'] ?? '';
    $confirmar_senha = $_POST['confirmar_senha'] ?? '';
    
    if (!empty($senha) && $senha !== $confirmar_senha) {
        $mensagem = 'As senhas não conferem.';
        $tipo_mensagem = 'erro';
    } else {
        $resultado = atualizarPerfilUsuario($usuario_id, $nome, $email, empty($senha) ? null : $senha);
        if ($resultado['sucesso']) {
            $mensagem = 'Perfil atualizado com sucesso!';
            $tipo_mensagem = 'sucesso';
            // Atualizar dados na variável local para refletir na tela imediatamente
            $dados_usuario = obterDetalhesUsuario($usuario_id);
        } else {
            $mensagem = $resultado['erro'];
            $tipo_mensagem = 'erro';
            // Manter dados antigos em caso de erro, mas talvez o user queira ver o que digitou?
            // Por simplicidade, recarregamos do banco, o usuário terá que redigitar se errou algo (exceto senha)
            $dados_usuario = obterDetalhesUsuario($usuario_id);
        }
    }
} else {
    // Buscar dados atuais
    $dados_usuario = obterDetalhesUsuario($usuario_id);
}

if (empty($dados_usuario)) {
    // Caso raro onde o usuário da sessão não existe mais no banco
    fazerLogout();
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Minha Conta - Configurações</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/app.css">
    <style>
        .form-container {
            max-width: 600px;
            background: #fff;
            padding: 24px;
            border-radius: 12px;
            border: 1px solid var(--border);
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--text);
        }
        .form-control {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-family: inherit;
            font-size: 14px;
            color: var(--text);
            transition: border-color 0.2s;
            box-sizing: border-box; /* Importante para width 100% */
        }
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-light);
        }
        .btn-salvar {
            background-color: var(--primary);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.2s;
            font-size: 14px;
        }
        .btn-salvar:hover {
            background-color: var(--primary-dark);
        }
        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .alert-sucesso {
            background-color: #ecfdf5;
            color: #047857;
            border: 1px solid #a7f3d0;
        }
        .alert-erro {
            background-color: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }
        .form-hint {
            display: block;
            margin-top: 6px;
            font-size: 12px;
            color: var(--muted);
        }
    </style>
</head>
<body>
<div class="dashboard-layout">
    <?php include 'sidebar.php'; ?>
    
    <main class="main-content">
        <div class="cabecalho-pagina">
            <div>
                <h1>Minha Conta</h1>
                <p>Gerencie seus dados pessoais e senha.</p>
            </div>
        </div>

        <div class="painel">
            <div class="form-container">
                <?php if ($mensagem): ?>
                    <div class="alert alert-<?php echo $tipo_mensagem; ?>">
                        <?php echo htmlspecialchars($mensagem); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="">
                    <div class="form-group">
                        <label for="nome" class="form-label">Nome Completo</label>
                        <input type="text" id="nome" name="nome" class="form-control" required 
                               value="<?php echo htmlspecialchars($dados_usuario['nome'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" id="email" name="email" class="form-control" required 
                               value="<?php echo htmlspecialchars($dados_usuario['email'] ?? ''); ?>">
                    </div>

                    <div style="margin-top: 32px; margin-bottom: 20px; border-top: 1px solid var(--border); padding-top: 20px;">
                        <h3 style="font-size: 16px; font-weight: 600; margin-bottom: 16px; color: var(--text);">Alterar Senha</h3>
                        
                        <div class="form-group">
                            <label for="senha" class="form-label">Nova Senha</label>
                            <input type="password" id="senha" name="senha" class="form-control" placeholder="Deixe em branco para manter a atual">
                            <span class="form-hint">Mínimo de 6 caracteres.</span>
                        </div>

                        <div class="form-group">
                            <label for="confirmar_senha" class="form-label">Confirmar Nova Senha</label>
                            <input type="password" id="confirmar_senha" name="confirmar_senha" class="form-control" placeholder="Repita a nova senha">
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn-salvar">Salvar Alterações</button>
                    </div>
                </form>
            </div>
        </div>
    </main>
</div>
</body>
</html>
