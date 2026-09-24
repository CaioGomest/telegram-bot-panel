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
} elseif (session_status() === PHP_SESSION_NONE) {
    // Primeiro deploy (sem admin ainda) -- funcoes/usuario.php não é carregado nesse
    // ramo (depende de banco configurado), então inicia a sessão aqui mesmo só pra
    // poder ter um token CSRF no formulário de instalação.
    session_start();
}
require_once __DIR__ . '/funcoes/csrf.php';
require_once __DIR__ . '/funcoes/configuracoes.php';

$mensagem = '';
$tipo_mensagem = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificarCsrf();
    $banco_host = $_POST['banco_host'] ?? 'localhost';
    $usuario_banco = $_POST['usuario_banco'] ?? 'root';
    $senha_banco = $_POST['senha_banco'] ?? '';
    $nome_banco = $_POST['nome_banco'] ?? 'telegram_bot_saas';
    $nome_sistema = trim($_POST['nome_sistema'] ?? '');
    $aviso_marca = '';

    try {
        $pdo = new PDO("mysql:host=$banco_host;charset=utf8mb4", $usuario_banco, $senha_banco, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);

        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$nome_banco` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `$nome_banco`");

        // Schema completo (22 tabelas), consolidado a partir de admin/atualiza_banco.php em
        // 2026-09-17 -- antes disso, um install novo só criava usuarios/fluxos/bots e dependia
        // de rodar atualiza_banco.php manualmente depois pra chegar no schema atual (o que
        // também já tinha ficado lento demais pra rodar de uma vez com muito dado acumulado,
        // ver anotacoes/analise-potencia-e-escala.md). Conferido campo a campo contra
        // `SHOW CREATE TABLE` do ambiente de teste em produção pra garantir que bate exatamente
        // com o schema real depois de anos de ALTER TABLE incrementais.
        $pdo->exec("CREATE TABLE IF NOT EXISTS usuarios (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nome VARCHAR(100) NOT NULL,
            apelido_publico VARCHAR(40) DEFAULT NULL,
            email VARCHAR(150) NOT NULL UNIQUE,
            google_id VARCHAR(255) DEFAULT NULL UNIQUE,
            senha VARCHAR(255) NOT NULL,
            perfil ENUM('admin', 'usuario') DEFAULT 'usuario',
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS fluxos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            id_usuario INT NOT NULL,
            nome VARCHAR(120) NOT NULL,
            descricao VARCHAR(500),
            link_suporte VARCHAR(255) DEFAULT NULL,
            dados_fluxograma LONGTEXT,
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (id_usuario) REFERENCES usuarios(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS bots (
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
            webhook_secret VARCHAR(64) NULL,
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (id_usuario) REFERENCES usuarios(id) ON DELETE CASCADE,
            FOREIGN KEY (id_fluxo_conectado) REFERENCES fluxos(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS leads (
            id INT AUTO_INCREMENT PRIMARY KEY,
            id_telegram VARCHAR(50) NOT NULL,
            nome VARCHAR(100),
            telefone VARCHAR(30) DEFAULT NULL,
            origem_rastreio VARCHAR(100) DEFAULT NULL,
            bot_id INT,
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (bot_id) REFERENCES bots(id) ON DELETE SET NULL,
            INDEX idx_leads_bot (bot_id, id_telegram),
            INDEX idx_leads_bot_criado_em (bot_id, criado_em DESC)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS vendas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            id_telegram VARCHAR(50) NOT NULL,
            bot_id INT,
            valor DECIMAL(10,2) NOT NULL,
            status ENUM('gerado', 'pago', 'cancelado', 'expirado') DEFAULT 'gerado',
            transacao_id VARCHAR(255),
            id_operador_fluxo VARCHAR(50) NULL,
            id_gateway INT DEFAULT NULL,
            tempo_expiracao_minutos INT DEFAULT 15,
            id_grupo_telegram VARCHAR(50),
            dias_acesso INT DEFAULT 30,
            tempo_acesso_minutos BIGINT DEFAULT NULL,
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            pago_em DATETIME NULL,
            comissao_admin DECIMAL(10,2) DEFAULT 0.00,
            split_status ENUM('sem_split', 'sem_credenciais', 'pago', 'falhou', 'parcial') DEFAULT NULL,
            split_valor DECIMAL(10,2) DEFAULT NULL,
            split_em DATETIME DEFAULT NULL,
            tipo_cobranca ENUM('unica', 'assinatura', 'recorrente') DEFAULT 'unica',
            status_renovacao ENUM('pendente', 'renovada', 'cancelada') DEFAULT 'pendente',
            venda_pai_id INT DEFAULT NULL,
            id_assinatura VARCHAR(100) DEFAULT NULL,
            id_plano INT DEFAULT NULL,
            ultimo_txid_renovacao VARCHAR(255) DEFAULT NULL,
            FOREIGN KEY (bot_id) REFERENCES bots(id) ON DELETE SET NULL,
            UNIQUE INDEX idx_transacao_id (transacao_id),
            INDEX idx_id_assinatura (id_assinatura),
            INDEX idx_vendas_lookup (id_telegram, bot_id, status),
            INDEX idx_vendas_ranking (status, criado_em, bot_id),
            INDEX idx_vendas_criado_em (criado_em DESC)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS atividades (
            id INT AUTO_INCREMENT PRIMARY KEY,
            id_usuario INT NOT NULL,
            tipo VARCHAR(50) NOT NULL,
            titulo VARCHAR(100),
            descricao TEXT,
            icone VARCHAR(50),
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (id_usuario) REFERENCES usuarios(id) ON DELETE CASCADE,
            INDEX idx_atividades_tipo (tipo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS bot_grupos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            bot_id INT NOT NULL,
            id_telegram VARCHAR(50) NOT NULL,
            titulo VARCHAR(255),
            tipo VARCHAR(50),
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (bot_id) REFERENCES bots(id) ON DELETE CASCADE,
            UNIQUE KEY unique_bot_grupo (bot_id, id_telegram)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS membros_grupos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            id_telegram VARCHAR(50) NOT NULL,
            id_grupo_telegram VARCHAR(50) NOT NULL,
            bot_id INT NOT NULL,
            venda_id INT,
            invite_link VARCHAR(255) DEFAULT NULL,
            data_expiracao DATETIME NULL,
            status ENUM('ativo', 'expirado', 'banido') DEFAULT 'ativo',
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            data_tolerancia DATETIME DEFAULT NULL,
            em_renovacao TINYINT(1) DEFAULT 0,
            aviso_enviado TINYINT(1) DEFAULT 0,
            FOREIGN KEY (bot_id) REFERENCES bots(id) ON DELETE CASCADE,
            FOREIGN KEY (venda_id) REFERENCES vendas(id) ON DELETE SET NULL,
            UNIQUE KEY unico_membro (id_telegram, id_grupo_telegram, bot_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS gateways (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nome VARCHAR(50) NOT NULL,
            titulo VARCHAR(100) NOT NULL,
            ativo TINYINT(1) DEFAULT 0,
            taxa_split DECIMAL(5,2) DEFAULT 0.00,
            chave_pix_split VARCHAR(255),
            tipo_split ENUM('percentual', 'fixo') DEFAULT 'percentual',
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS usuarios_gateways (
            id INT AUTO_INCREMENT PRIMARY KEY,
            id_usuario INT NOT NULL,
            id_gateway INT NOT NULL,
            client_id VARCHAR(255),
            client_secret VARCHAR(500),
            certificado VARCHAR(255),
            cert_password VARCHAR(500) NULL,
            chave_pix VARCHAR(500),
            tipo_conta ENUM('pf', 'pj') DEFAULT 'pj',
            cashout_client_id VARCHAR(255) NULL,
            cashout_client_secret VARCHAR(500) NULL,
            cashout_certificado VARCHAR(255) NULL,
            cashout_cert_password VARCHAR(500) NULL,
            ativo TINYINT(1) DEFAULT 0,
            prioridade INT DEFAULT 100,
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (id_usuario) REFERENCES usuarios(id) ON DELETE CASCADE,
            FOREIGN KEY (id_gateway) REFERENCES gateways(id) ON DELETE CASCADE,
            UNIQUE KEY unique_user_gateway (id_usuario, id_gateway)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS usuarios_splits (
            id INT AUTO_INCREMENT PRIMARY KEY,
            id_usuario INT NOT NULL,
            gateway_nome VARCHAR(50) NOT NULL,
            tipo_split ENUM('percentual', 'fixo') DEFAULT 'percentual',
            taxa_split DECIMAL(10,2) DEFAULT 0.00,
            chave_pix_split VARCHAR(255) NOT NULL,
            descricao VARCHAR(100) DEFAULT NULL,
            ordem INT DEFAULT 0,
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (id_usuario) REFERENCES usuarios(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS vendas_splits (
            id INT AUTO_INCREMENT PRIMARY KEY,
            venda_id INT NOT NULL,
            usuario_split_id INT DEFAULT NULL,
            chave_pix VARCHAR(255) NOT NULL,
            descricao VARCHAR(100) DEFAULT NULL,
            valor DECIMAL(10,2) NOT NULL,
            status ENUM('pago', 'falhou') NOT NULL,
            erro TEXT DEFAULT NULL,
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (venda_id) REFERENCES vendas(id) ON DELETE CASCADE,
            FOREIGN KEY (usuario_split_id) REFERENCES usuarios_splits(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS remarketing_campanhas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            id_usuario INT NOT NULL,
            bot_id INT NOT NULL,
            audiencia ENUM('nao_comprou','comprou') NOT NULL,
            mensagem TEXT NOT NULL,
            agendado_em DATETIME NOT NULL,
            status ENUM('pendente','processando','concluida','falha') DEFAULT 'pendente',
            total_destinatarios INT DEFAULT 0,
            enviados INT DEFAULT 0,
            entregues INT DEFAULT 0,
            falhas INT DEFAULT 0,
            offset_envio INT DEFAULT 0,
            processado_em DATETIME NULL,
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (id_usuario) REFERENCES usuarios(id) ON DELETE CASCADE,
            FOREIGN KEY (bot_id) REFERENCES bots(id) ON DELETE CASCADE,
            INDEX idx_status_agendado (status, agendado_em)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS remarketing_envios (
            id INT AUTO_INCREMENT PRIMARY KEY,
            campanha_id INT NOT NULL,
            id_telegram VARCHAR(50) NOT NULL,
            resultado ENUM('sucesso','falha') NOT NULL,
            resposta TEXT,
            enviado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (campanha_id) REFERENCES remarketing_campanhas(id) ON DELETE CASCADE,
            INDEX idx_campanha (campanha_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS usuarios_traqueamento (
            id INT AUTO_INCREMENT PRIMARY KEY,
            id_usuario INT NOT NULL,
            facebook_ativo TINYINT(1) DEFAULT 0,
            facebook_pixel_id VARCHAR(255) DEFAULT NULL,
            facebook_access_token VARCHAR(255) DEFAULT NULL,
            tiktok_ativo TINYINT(1) DEFAULT 0,
            tiktok_pixel_id VARCHAR(255) DEFAULT NULL,
            tiktok_access_token VARCHAR(255) DEFAULT NULL,
            utmfy_ativo TINYINT(1) DEFAULT 0,
            utmfy_token VARCHAR(255) DEFAULT NULL,
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_user (id_usuario),
            FOREIGN KEY (id_usuario) REFERENCES usuarios(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS links_rastreamento (
            id INT AUTO_INCREMENT PRIMARY KEY,
            id_usuario INT NOT NULL,
            titulo VARCHAR(255) NOT NULL,
            identificador VARCHAR(100) NOT NULL,
            bot_id INT NOT NULL,
            starts INT DEFAULT 0,
            leads INT DEFAULT 0,
            vendas_qtd INT DEFAULT 0,
            vendas_valor DECIMAL(10,2) DEFAULT 0.00,
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (id_usuario) REFERENCES usuarios(id) ON DELETE CASCADE,
            FOREIGN KEY (bot_id) REFERENCES bots(id) ON DELETE CASCADE,
            UNIQUE KEY unique_identificador_usuario (id_usuario, identificador)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS stories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            id_usuario INT NOT NULL,
            tipo_midia ENUM('foto', 'video') NOT NULL,
            arquivo VARCHAR(255) NOT NULL,
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            expira_em DATETIME NOT NULL,
            FOREIGN KEY (id_usuario) REFERENCES usuarios(id) ON DELETE CASCADE,
            INDEX idx_stories_expira (expira_em),
            INDEX idx_stories_usuario (id_usuario)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS stories_visualizacoes (
            story_id INT NOT NULL,
            id_usuario INT NOT NULL,
            visto_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (story_id, id_usuario),
            FOREIGN KEY (story_id) REFERENCES stories(id) ON DELETE CASCADE,
            FOREIGN KEY (id_usuario) REFERENCES usuarios(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS configuracoes (
            chave VARCHAR(50) NOT NULL PRIMARY KEY,
            valor TEXT,
            atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS tentativas_login (
            id INT AUTO_INCREMENT PRIMARY KEY,
            identificador VARCHAR(150) NOT NULL,
            tentativas INT DEFAULT 0,
            bloqueado_ate DATETIME NULL,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_identificador (identificador)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS campanhas_ranking (
            id INT AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(60) NOT NULL UNIQUE,
            titulo VARCHAR(100) NOT NULL,
            subtitulo VARCHAR(255) DEFAULT NULL,
            data_inicio DATETIME NOT NULL,
            data_fim DATETIME NOT NULL,
            ativa TINYINT(1) DEFAULT 1,
            finalizada_em DATETIME DEFAULT NULL,
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS campanhas_ranking_premios (
            id INT AUTO_INCREMENT PRIMARY KEY,
            campanha_id INT NOT NULL,
            posicao INT NOT NULL,
            titulo VARCHAR(100) NOT NULL,
            descricao VARCHAR(255) DEFAULT NULL,
            FOREIGN KEY (campanha_id) REFERENCES campanhas_ranking(id) ON DELETE CASCADE,
            UNIQUE KEY unico_campanha_posicao (campanha_id, posicao)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS ranking_cache (
            campanha_id INT NOT NULL,
            id_usuario INT NOT NULL,
            faturamento DECIMAL(12,2) NOT NULL DEFAULT 0,
            posicao INT NOT NULL,
            atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (campanha_id, id_usuario),
            KEY idx_ranking_posicao (campanha_id, posicao),
            FOREIGN KEY (campanha_id) REFERENCES campanhas_ranking(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS metricas_horarias_admin (
            data DATE NOT NULL,
            hora TINYINT UNSIGNED NOT NULL,
            faturamento DECIMAL(14,2) NOT NULL DEFAULT 0,
            comissao DECIMAL(14,2) NOT NULL DEFAULT 0,
            quantidade INT NOT NULL DEFAULT 0,
            atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (data, hora)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS metricas_horarias_usuario (
            id_usuario INT NOT NULL,
            data DATE NOT NULL,
            hora TINYINT UNSIGNED NOT NULL,
            valor_pago DECIMAL(14,2) NOT NULL DEFAULT 0,
            qtd_paga INT NOT NULL DEFAULT 0,
            qtd_gerada INT NOT NULL DEFAULT 0,
            qtd_leads INT NOT NULL DEFAULT 0,
            atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id_usuario, data, hora)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // Seed: gateway InfoPago (único gateway suportado hoje -- gateways.php espera essa
        // linha existir pra aparecer na tela de configuração).
        $pdo->exec("INSERT INTO gateways (nome, titulo, ativo) VALUES ('infopago', 'InfoPago (Pix)', 0)");

        // Identidade visual (white-label): o que o instalador recebeu vira a marca desta
        // instalação. Vai num try/catch próprio porque um upload ruim não pode derrubar uma
        // instalação que já criou o banco inteiro -- o admin troca depois em admin/configuracoes.
        try {
            if ($nome_sistema !== '') {
                definirConfigSistema('nome_sistema', mb_substr($nome_sistema, 0, 60));
            }
            if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
                definirConfigSistema('logo', salvarArquivoMarca($_FILES['logo'], 'marca_logo'));
            }
            if (isset($_FILES['favicon']) && $_FILES['favicon']['error'] === UPLOAD_ERR_OK) {
                definirConfigSistema('favicon', salvarArquivoMarca($_FILES['favicon'], 'marca_favicon'));
            }
        } catch (Throwable $e) {
            $aviso_marca = ' (a identidade visual não pôde ser salva: ' . $e->getMessage() . ' — ajuste depois em Configurações)';
        }
        $senha_hash = password_hash('123456', PASSWORD_DEFAULT);
        $sql_user_padrao = "INSERT IGNORE INTO usuarios (id, nome, email, senha, perfil) VALUES (1, 'Admin', 'admin@exemplo.com', '$senha_hash', 'admin')";
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

        $mensagem = "Instalação concluída com sucesso! Banco de dados criado e configurado." . $aviso_marca;
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
    <title>Instalação</title>
    <?php include __DIR__ . '/tema_inline.php'; ?>
    <link rel="stylesheet" href="assets/css/coyote.css?v=<?php echo @filemtime(__DIR__.'/assets/css/coyote.css'); ?>">
    <style>body { display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; padding: 24px 16px; box-sizing: border-box; }</style>
</head>
<body>
    <div class="painel" style="width: 100%; max-width: 400px;">
        <div class="painel-cabecalho">
            <h2>Instalação</h2>
        </div>
        <?php if ($mensagem): ?>
            <div class="aviso aviso-<?= $tipo_mensagem ?>"><?= $mensagem ?></div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">
            <?php echo campoCsrf(); ?>
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

            <div style="margin-top:22px;padding-top:16px;border-top:1px solid var(--bd);">
                <strong style="font-size:13px;">Identidade do painel</strong>
                <p class="texto-suave" style="margin:4px 0 0;font-size:12px;">Opcional — dá pra trocar depois em Configurações.</p>
            </div>
            <div class="campo" style="margin-top:14px;">
                <label for="nome_sistema">Nome do sistema</label>
                <input type="text" id="nome_sistema" name="nome_sistema" maxlength="60" placeholder="Ex: Painel de Bots">
            </div>
            <div class="campo" style="margin-top:14px;">
                <label for="logo">Logo</label>
                <input type="file" id="logo" name="logo" accept=".png,.jpg,.jpeg,.webp">
            </div>
            <div class="campo" style="margin-top:14px;">
                <label for="favicon">Favicon</label>
                <input type="file" id="favicon" name="favicon" accept=".png,.ico,.jpg,.jpeg,.webp">
            </div>
            <button type="submit" class="botao botao-primario botao-bloco" style="margin-top:20px;">Instalar e Criar Banco</button>
        </form>
    </div>
</body>
</html>
