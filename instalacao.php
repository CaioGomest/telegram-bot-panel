<?php
declare(strict_types=1);

/**
 * Verifica, de forma isolada e sem depender do resto da app, se já existe
 * um admin cadastrado no banco configurado em config.php. Se qualquer coisa
 * falhar (banco ainda não configurado, tabela não existe, etc.), considera
 * que o sistema ainda não foi instalado e libera o acesso — é o cenário de
 * primeiro deploy, onde ainda não há ninguém pra fazer login.
 */
function instaladorJaTemAdmin(): bool {
    $config_path = __DIR__ . '/config.php';
    if (!file_exists($config_path)) {
        return false;
    }

    try {
        require_once $config_path;
        if (!defined('BANCO_HOST') || !defined('BANCO_NOME')) {
            return false;
        }
        $dsn = "mysql:host=" . BANCO_HOST . ";dbname=" . BANCO_NOME . ";charset=utf8mb4";
        $pdo_check = new PDO($dsn, BANCO_USUARIO, BANCO_SENHA, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 3,
        ]);
        $stmt = $pdo_check->query("SELECT COUNT(*) FROM usuarios WHERE perfil = 'admin'");
        return (int)$stmt->fetchColumn() > 0;
    } catch (\Throwable $e) {
        return false;
    }
}

// Se já existe admin cadastrado, o instalador só pode ser reaberto por um
// admin já logado (evita que qualquer visitante recrie/reconfigure o banco).
if (instaladorJaTemAdmin()) {
    require_once __DIR__ . '/funcoes/usuario.php';
    verificarAdmin();
}

$mensagem = '';
$tipo_mensagem = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $banco_host = $_POST['banco_host'] ?? 'localhost';
    $usuario_banco = $_POST['usuario_banco'] ?? 'root';
    $senha_banco = $_POST['senha_banco'] ?? '';
    $nome_banco = $_POST['nome_banco'] ?? 'telegram_bot_saas';

    try {
        $pdo = new PDO("mysql:host=$banco_host;charset=utf8mb4", $usuario_banco, $senha_banco, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);

        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$nome_banco` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `$nome_banco`");

        $sql_usuarios = "CREATE TABLE IF NOT EXISTS usuarios (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nome VARCHAR(100) NOT NULL,
            email VARCHAR(100) NOT NULL UNIQUE,
            senha VARCHAR(255) NOT NULL,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB;";
        $pdo->exec($sql_usuarios);

        $sql_fluxos = "CREATE TABLE IF NOT EXISTS fluxos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            id_usuario INT NOT NULL,
            nome VARCHAR(120) NOT NULL,
            descricao VARCHAR(500),
            dados_fluxograma LONGTEXT,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (id_usuario) REFERENCES usuarios(id) ON DELETE CASCADE
        ) ENGINE=InnoDB;";
        $pdo->exec($sql_fluxos);

        $sql_bots = "CREATE TABLE IF NOT EXISTS bots (
            id INT AUTO_INCREMENT PRIMARY KEY,
            id_usuario INT NOT NULL,
            token VARCHAR(255) NOT NULL,
            id_bot_telegram BIGINT,
            nome_usuario VARCHAR(100),
            primeiro_nome VARCHAR(100),
            descricao TEXT,
            descricao_curta VARCHAR(255),
            id_fluxo_conectado INT NULL,
            caminho_foto VARCHAR(255),
            url_webhook VARCHAR(255),
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (id_usuario) REFERENCES usuarios(id) ON DELETE CASCADE,
            FOREIGN KEY (id_fluxo_conectado) REFERENCES fluxos(id) ON DELETE SET NULL
        ) ENGINE=InnoDB;";
        $pdo->exec($sql_bots);

        $senha_hash = password_hash('123456', PASSWORD_DEFAULT);
        $sql_user_padrao = "INSERT IGNORE INTO usuarios (id, nome, email, senha) VALUES (1, 'Admin', 'admin@exemplo.com', '$senha_hash')";
        $pdo->exec($sql_user_padrao);

        $config_content = "<?php\n";
        $config_content .= "// config.php\n";
        $config_content .= "define('BANCO_HOST', '" . addslashes($banco_host) . "');\n";
        $config_content .= "define('BANCO_NOME', '" . addslashes($nome_banco) . "');\n";
        $config_content .= "define('BANCO_USUARIO', '" . addslashes($usuario_banco) . "');\n";
        $config_content .= "define('BANCO_SENHA', '" . addslashes($senha_banco) . "');\n";
        // Chave própria e aleatória desta instalação, usada só pra criptografar
        // client_secret/cert_password/chave_pix dos gateways de pagamento no banco.
        $config_content .= "define('CHAVE_CRIPTOGRAFIA_GATEWAYS', '" . bin2hex(random_bytes(32)) . "');\n";

        file_put_contents('config.php', $config_content);

        $mensagem = "Instalação concluída com sucesso! Banco de dados criado e configurado.";
        $tipo_mensagem = "sucesso";

    } catch (PDOException $e) {
        $mensagem = "Erro na instalação: " . $e->getMessage();
        $tipo_mensagem = "erro";
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalação - Coyote Bot</title>
    <?php include __DIR__ . '/tema_inline.php'; ?>
    <link rel="stylesheet" href="assets/css/coyote.css">
    <style>body { display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }</style>
</head>
<body>
    <div class="painel" style="width: 100%; max-width: 400px;">
        <div class="painel-cabecalho">
            <h2>Instalação</h2>
        </div>
        <?php if ($mensagem): ?>
            <div class="aviso aviso-<?= $tipo_mensagem ?>"><?= $mensagem ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="campo">
                <label for="banco_host">Servidor do Banco de Dados (Host)</label>
                <input type="text" id="banco_host" name="banco_host" value="localhost" required placeholder="Ex: localhost">
            </div>
            <div class="campo" style="margin-top:14px;">
                <label for="nome_banco">Nome do Banco de Dados</label>
                <input type="text" id="nome_banco" name="nome_banco" value="telegram_bot_saas" required placeholder="Ex: telegram_saas">
            </div>
            <div class="campo" style="margin-top:14px;">
                <label for="usuario_banco">Usuário do Banco</label>
                <input type="text" id="usuario_banco" name="usuario_banco" value="root" required placeholder="Ex: root">
            </div>
            <div class="campo" style="margin-top:14px;">
                <label for="senha_banco">Senha do Banco</label>
                <input type="password" id="senha_banco" name="senha_banco" placeholder="Deixe em branco se não houver senha">
            </div>
            <button type="submit" class="botao botao-primario botao-bloco" style="margin-top:20px;">Instalar e Criar Banco</button>
        </form>
    </div>
</body>
</html>
