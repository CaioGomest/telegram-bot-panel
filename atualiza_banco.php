<?php
declare(strict_types=1);

require_once __DIR__ . '/funcoes/usuario.php';
verificarAdminOuInstalacao();

try {
    echo "<h1>Atualização Unificada do Banco de Dados</h1>";
    echo "Iniciando verificação e atualização das tabelas...<br>";

    $sql_usuarios = "
        CREATE TABLE IF NOT EXISTS usuarios (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nome VARCHAR(100) NOT NULL,
            email VARCHAR(150) NOT NULL UNIQUE,
            senha VARCHAR(255) NOT NULL,
            perfil ENUM('admin', 'usuario') DEFAULT 'usuario',
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sql_usuarios);

    try {
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN perfil ENUM('admin', 'usuario') DEFAULT 'usuario' AFTER senha");
        echo "Coluna 'perfil' verificada em 'usuarios'.<br>";
    } catch (PDOException $e) {}
    echo "Tabela 'usuarios' OK.<br>";


    $sql_fluxos = "
        CREATE TABLE IF NOT EXISTS fluxos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            id_usuario INT NOT NULL,
            nome VARCHAR(120) NOT NULL,
            descricao VARCHAR(500),
            dados_fluxograma LONGTEXT,
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (id_usuario) REFERENCES usuarios(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sql_fluxos);
    try { $pdo->exec("ALTER TABLE fluxos ADD COLUMN link_suporte VARCHAR(255) DEFAULT NULL AFTER descricao"); } catch (PDOException $e) {}
    echo "Tabela 'fluxos' OK.<br>";


    $sql_bots = "
        CREATE TABLE IF NOT EXISTS bots (
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
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (id_usuario) REFERENCES usuarios(id) ON DELETE CASCADE,
            FOREIGN KEY (id_fluxo_conectado) REFERENCES fluxos(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sql_bots);
    echo "Tabela 'bots' OK.<br>";


    $sql_leads = "
        CREATE TABLE IF NOT EXISTS leads (
            id INT AUTO_INCREMENT PRIMARY KEY,
            id_telegram VARCHAR(50) NOT NULL,
            nome VARCHAR(100),
            bot_id INT,
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (bot_id) REFERENCES bots(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sql_leads);
    try { $pdo->exec("ALTER TABLE leads ADD COLUMN telefone VARCHAR(30) DEFAULT NULL AFTER nome"); } catch (PDOException $e) {}
    echo "Tabela 'leads' OK.<br>";


    $sql_vendas = "
        CREATE TABLE IF NOT EXISTS vendas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            id_telegram VARCHAR(50) NOT NULL,
            bot_id INT,
            valor DECIMAL(10,2) NOT NULL,
            status ENUM('gerado', 'pago', 'cancelado', 'expirado') DEFAULT 'gerado',
            transacao_id VARCHAR(255),
            id_grupo_telegram VARCHAR(50),
            dias_acesso INT DEFAULT 30,
            tempo_acesso_minutos BIGINT DEFAULT NULL,
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            pago_em DATETIME NULL,
            comissao_admin DECIMAL(10,2) DEFAULT 0.00,
            FOREIGN KEY (bot_id) REFERENCES bots(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sql_vendas);

    $colunas_vendas = [
        "ADD COLUMN bot_id INT AFTER id_telegram",
        "ADD CONSTRAINT fk_vendas_bot FOREIGN KEY (bot_id) REFERENCES bots(id) ON DELETE SET NULL",
        "ADD COLUMN comissao_admin DECIMAL(10,2) DEFAULT 0.00 AFTER pago_em",
        "ADD COLUMN id_grupo_telegram VARCHAR(50) AFTER bot_id",
        "ADD COLUMN dias_acesso INT DEFAULT 30 AFTER id_grupo_telegram",
        "ADD COLUMN tempo_acesso_minutos BIGINT DEFAULT NULL AFTER dias_acesso",
        "ADD COLUMN transacao_id VARCHAR(255) AFTER status",
        "ADD COLUMN id_operador_fluxo VARCHAR(50) NULL AFTER transacao_id",
        "ADD COLUMN id_gateway INT DEFAULT NULL AFTER id_operador_fluxo",
        "ADD COLUMN tempo_expiracao_minutos INT DEFAULT 15 AFTER id_gateway",
        "ADD COLUMN tipo_cobranca ENUM('unica', 'assinatura', 'recorrente') DEFAULT 'unica'",
        "ADD COLUMN status_renovacao ENUM('pendente', 'renovada', 'cancelada') DEFAULT 'pendente'",
        "ADD COLUMN venda_pai_id INT DEFAULT NULL",
        "ADD COLUMN id_assinatura VARCHAR(100) DEFAULT NULL", // Novo nome (ex-subscription_id)
        "ADD COLUMN id_plano INT DEFAULT NULL",      // Novo nome (ex-plan_id)
        "ADD COLUMN ultimo_txid_renovacao VARCHAR(255) DEFAULT NULL", // Idempotência do webhook de PIX Automático (cobsr)
        "ADD COLUMN split_status ENUM('sem_split', 'sem_credenciais', 'pago', 'falhou') DEFAULT NULL AFTER comissao_admin",
        "ADD COLUMN split_valor DECIMAL(10,2) DEFAULT NULL AFTER split_status",
        "ADD COLUMN split_em DATETIME DEFAULT NULL AFTER split_valor"
    ];

    foreach ($colunas_vendas as $alter) {
        try {
            $pdo->exec("ALTER TABLE vendas $alter");
            echo "Vendas atualizada: $alter<br>";
        } catch (PDOException $e) {}
    }

    // Migração de dados antigos (dias -> minutos)
    try {
        $pdo->exec("UPDATE vendas SET tempo_acesso_minutos = dias_acesso * 1440 WHERE tempo_acesso_minutos IS NULL AND dias_acesso IS NOT NULL");
    } catch (PDOException $e) {}

    // 'expirado' não existia no ENUM original de status; gravações antigas desse valor foram
    // silenciosamente salvas como '' pelo MySQL. Amplia o ENUM e corrige as linhas afetadas.
    try {
        $pdo->exec("ALTER TABLE vendas MODIFY COLUMN status ENUM('gerado', 'pago', 'cancelado', 'expirado') DEFAULT 'gerado'");
    } catch (PDOException $e) {}
    try {
        $pdo->exec("UPDATE vendas SET status = 'expirado' WHERE status = ''");
    } catch (PDOException $e) {}

    echo "Tabela 'vendas' OK.<br>";


    $sql_atividades = "
        CREATE TABLE IF NOT EXISTS atividades (
            id INT AUTO_INCREMENT PRIMARY KEY,
            id_usuario INT NOT NULL,
            tipo VARCHAR(50) NOT NULL, 
            titulo VARCHAR(100),
            descricao TEXT,
            icone VARCHAR(50), 
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (id_usuario) REFERENCES usuarios(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sql_atividades);
    echo "Tabela 'atividades' OK.<br>";


    $sql_grupos = "
        CREATE TABLE IF NOT EXISTS bot_grupos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            bot_id INT NOT NULL,
            id_telegram VARCHAR(50) NOT NULL,
            titulo VARCHAR(255),
            tipo VARCHAR(50), 
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (bot_id) REFERENCES bots(id) ON DELETE CASCADE,
            UNIQUE KEY unique_bot_grupo (bot_id, id_telegram)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sql_grupos);
    echo "Tabela 'bot_grupos' OK.<br>";


    $sql_membros = "
        CREATE TABLE IF NOT EXISTS membros_grupos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            id_telegram VARCHAR(50) NOT NULL,
            id_grupo_telegram VARCHAR(50) NOT NULL,
            bot_id INT NOT NULL,
            venda_id INT,
            data_expiracao DATETIME NULL,
            status ENUM('ativo', 'expirado', 'banido') DEFAULT 'ativo',
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (bot_id) REFERENCES bots(id) ON DELETE CASCADE,
            FOREIGN KEY (venda_id) REFERENCES vendas(id) ON DELETE SET NULL,
            UNIQUE KEY unico_membro (id_telegram, id_grupo_telegram, bot_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sql_membros);

    $colunas_membros = [
        "ADD COLUMN invite_link VARCHAR(255) DEFAULT NULL AFTER venda_id",
        "ADD COLUMN data_tolerancia DATETIME DEFAULT NULL",
        "ADD COLUMN em_renovacao TINYINT(1) DEFAULT 0",
        "ADD COLUMN aviso_enviado TINYINT(1) DEFAULT 0"
    ];

    foreach ($colunas_membros as $alter) {
        try {
            $pdo->exec("ALTER TABLE membros_grupos $alter");
            echo "Membros atualizada: $alter<br>";
        } catch (PDOException $e) {}
    }
    echo "Tabela 'membros_grupos' OK.<br>";


    $sql_gateways = "
        CREATE TABLE IF NOT EXISTS gateways (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nome VARCHAR(50) NOT NULL,
            titulo VARCHAR(100) NOT NULL,
            ativo TINYINT(1) DEFAULT 0,
            taxa_split DECIMAL(5,2) DEFAULT 0.00,
            chave_pix_split VARCHAR(255),
            tipo_split ENUM('percentual', 'fixo') DEFAULT 'percentual',
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sql_gateways);
    
    try {
        $pdo->exec("ALTER TABLE gateways ADD COLUMN tipo_split ENUM('percentual', 'fixo') DEFAULT 'percentual' AFTER chave_pix_split");
    } catch (PDOException $e) {}
    echo "Tabela 'gateways' OK.<br>";

    $sql_usuarios_gateways = "
        CREATE TABLE IF NOT EXISTS usuarios_gateways (
            id INT AUTO_INCREMENT PRIMARY KEY,
            id_usuario INT NOT NULL,
            id_gateway INT NOT NULL,
            client_id VARCHAR(255),
            client_secret VARCHAR(255),
            certificado VARCHAR(255),
            chave_pix VARCHAR(255),
            ativo TINYINT(1) DEFAULT 0,
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (id_usuario) REFERENCES usuarios(id) ON DELETE CASCADE,
            FOREIGN KEY (id_gateway) REFERENCES gateways(id) ON DELETE CASCADE,
            UNIQUE KEY unique_user_gateway (id_usuario, id_gateway)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sql_usuarios_gateways);

    try {
        $pdo->exec("ALTER TABLE usuarios_gateways ADD COLUMN chave_pix VARCHAR(255) AFTER certificado");
    } catch (PDOException $e) {}

    try {
        $pdo->exec("ALTER TABLE usuarios_gateways ADD COLUMN prioridade INT DEFAULT 100 AFTER ativo");
    } catch (PDOException $e) {}

    try {
        $pdo->exec("ALTER TABLE usuarios_gateways ADD COLUMN cert_password VARCHAR(255) NULL AFTER certificado");
    } catch (PDOException $e) {}

    try {
        $pdo->exec("ALTER TABLE usuarios_gateways ADD COLUMN tipo_conta ENUM('pf', 'pj') DEFAULT 'pj' AFTER chave_pix");
        echo "Coluna 'tipo_conta' adicionada em 'usuarios_gateways'.<br>";
    } catch (PDOException $e) {}

    // Credenciais de Cash-Out (API de Contas/transferência) — usadas pela InfoPago para simular
    // split via transferência manual após o Pix cair (a API de cobrança dela não tem split nativo).
    try {
        $pdo->exec("ALTER TABLE usuarios_gateways ADD COLUMN cashout_client_id VARCHAR(255) NULL AFTER tipo_conta");
    } catch (PDOException $e) {}
    try {
        $pdo->exec("ALTER TABLE usuarios_gateways ADD COLUMN cashout_client_secret VARCHAR(255) NULL AFTER cashout_client_id");
    } catch (PDOException $e) {}
    try {
        $pdo->exec("ALTER TABLE usuarios_gateways ADD COLUMN cashout_certificado VARCHAR(255) NULL AFTER cashout_client_secret");
        echo "Colunas de Cash-Out adicionadas em 'usuarios_gateways'.<br>";
    } catch (PDOException $e) {}
    try {
        $pdo->exec("ALTER TABLE usuarios_gateways ADD COLUMN cashout_cert_password VARCHAR(255) NULL AFTER cashout_certificado");
        echo "Coluna 'cashout_cert_password' adicionada em 'usuarios_gateways'.<br>";
    } catch (PDOException $e) {}

    echo "Tabela 'usuarios_gateways' OK.<br>";

    $sql_usuarios_splits = "
        CREATE TABLE IF NOT EXISTS usuarios_splits (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sql_usuarios_splits);

    // Splits salvos com gateway_nome de gateways já removidos (ex-pushinpay) nunca foram
    // usados de fato pelo motor de split (que só olha 'infopago') — remove esse lixo.
    try {
        $pdo->exec("DELETE FROM usuarios_splits WHERE gateway_nome <> 'infopago'");
    } catch (PDOException $e) {}

    echo "Tabela 'usuarios_splits' OK.<br>";

    $sql_vendas_splits = "
        CREATE TABLE IF NOT EXISTS vendas_splits (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sql_vendas_splits);
    echo "Tabela 'vendas_splits' OK.<br>";

    try {
        $pdo->exec("ALTER TABLE vendas MODIFY COLUMN split_status ENUM('sem_split', 'sem_credenciais', 'pago', 'falhou', 'parcial') DEFAULT NULL");
    } catch (PDOException $e) {}

    $sql_remarketing_campanhas = "
        CREATE TABLE IF NOT EXISTS remarketing_campanhas (
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
            processado_em DATETIME NULL,
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (id_usuario) REFERENCES usuarios(id) ON DELETE CASCADE,
            FOREIGN KEY (bot_id) REFERENCES bots(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sql_remarketing_campanhas);
    try { $pdo->exec("ALTER TABLE remarketing_campanhas ADD COLUMN offset_envio INT DEFAULT 0 AFTER falhas"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE remarketing_campanhas ADD INDEX idx_status_agendado (status, agendado_em)"); } catch (PDOException $e) {}
    echo "Tabela 'remarketing_campanhas' OK.<br>";

    $sql_remarketing_envios = "
        CREATE TABLE IF NOT EXISTS remarketing_envios (
            id INT AUTO_INCREMENT PRIMARY KEY,
            campanha_id INT NOT NULL,
            id_telegram VARCHAR(50) NOT NULL,
            resultado ENUM('sucesso','falha') NOT NULL,
            resposta TEXT,
            enviado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (campanha_id) REFERENCES remarketing_campanhas(id) ON DELETE CASCADE,
            INDEX idx_campanha (campanha_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sql_remarketing_envios);
    echo "Tabela 'remarketing_envios' OK.<br>";

    try { $pdo->exec("ALTER TABLE leads ADD INDEX idx_leads_bot (bot_id, id_telegram)"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE vendas ADD INDEX idx_vendas_lookup (id_telegram, bot_id, status)"); } catch (PDOException $e) {}

    $sql_traqueamento = "
        CREATE TABLE IF NOT EXISTS usuarios_traqueamento (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sql_traqueamento);

    try { $pdo->exec("ALTER TABLE usuarios_traqueamento ADD COLUMN facebook_ativo TINYINT(1) DEFAULT 0 AFTER id_usuario"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE usuarios_traqueamento ADD COLUMN tiktok_ativo TINYINT(1) DEFAULT 0 AFTER facebook_access_token"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE usuarios_traqueamento ADD COLUMN utmfy_ativo TINYINT(1) DEFAULT 0 AFTER tiktok_access_token"); } catch (PDOException $e) {}
    
    echo "Tabela 'usuarios_traqueamento' OK.<br>";


    $stmt_gateway = $pdo->query("SELECT COUNT(*) FROM gateways WHERE nome = 'infopago'");
    if ($stmt_gateway->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO gateways (nome, titulo, ativo) VALUES ('infopago', 'InfoPago (Pix)', 0)");
        echo "Gateway 'InfoPago' inserido.<br>";
    }

    $sql_links_rastreamento = "
        CREATE TABLE IF NOT EXISTS links_rastreamento (
            id INT AUTO_INCREMENT PRIMARY KEY,
            id_usuario INT NOT NULL,
            titulo VARCHAR(255) NOT NULL,
            identificador VARCHAR(100) NOT NULL,
            bot_id INT NOT NULL,
            leads INT DEFAULT 0,
            vendas_qtd INT DEFAULT 0,
            vendas_valor DECIMAL(10,2) DEFAULT 0.00,
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (id_usuario) REFERENCES usuarios(id) ON DELETE CASCADE,
            FOREIGN KEY (bot_id) REFERENCES bots(id) ON DELETE CASCADE,
            UNIQUE KEY unique_identificador_usuario (id_usuario, identificador)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sql_links_rastreamento);
    try { $pdo->exec("ALTER TABLE links_rastreamento ADD COLUMN starts INT DEFAULT 0 AFTER bot_id"); } catch (PDOException $e) {}
    echo "Tabela 'links_rastreamento' OK.<br>";

    $stmt = $pdo->query("SELECT COUNT(*) FROM usuarios");
    $total = $stmt->fetchColumn();

    if ($total == 0) {
        $senha_hash = password_hash('admin123', PASSWORD_DEFAULT);
        $stmt_insert = $pdo->prepare("INSERT INTO usuarios (nome, email, senha, perfil) VALUES (?, ?, ?, 'admin')");
        $stmt_insert->execute(['Administrador', 'admin@admin.com', $senha_hash]);
        echo "Usuário padrão criado. Email: admin@admin.com | Senha: admin123<br>";
    } else {
        $stmt_admin = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE perfil = 'admin'");
        if ($stmt_admin->fetchColumn() == 0) {
            $pdo->exec("UPDATE usuarios SET perfil = 'admin' WHERE id = (SELECT id FROM (SELECT id FROM usuarios ORDER BY id ASC LIMIT 1) as t)");
            echo "Primeiro usuário definido como admin.<br>";
        }
    }

    echo "<br><strong>Banco de dados atualizado com sucesso! Todas as tabelas e colunas estão sincronizadas.</strong>";

} catch (PDOException $e) {
    echo "<h2>Erro fatal ao atualizar banco: " . $e->getMessage() . "</h2>";
}
?>
