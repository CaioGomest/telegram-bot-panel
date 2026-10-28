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

// Se já existe admin, a tela só abre para quem é admin e não oferece
// reinstalar: o POST reescrevia o config.php. Visitante cai no login.
$ja_instalado = instaladorJaTemAdmin();
if ($ja_instalado) {
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $ja_instalado) {
    $mensagem = 'O painel já está instalado. Esta tela não recria o banco.';
    $tipo_mensagem = 'erro';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

        // Schema completo (39 tabelas; conferido de novo em 11/10/2026 contra o banco de homologação com tests/paginas/servidor/zz_schema.php), consolidado a partir de admin/atualiza_banco.php em
        // 2026-09-17 e reconferido em 2026-09-25 (fechada a lacuna de comunidade_links/
        // webhooks/webhooks_envios, que só o atualiza_banco.php criava até então) -- antes
        // disso, um install novo só criava usuarios/fluxos/bots e dependia de rodar
        // atualiza_banco.php manualmente depois pra chegar no schema atual (o que também já
        // tinha ficado lento demais pra rodar de uma vez com muito dado acumulado, ver
        // anotacoes/OBSERVACOES.md). Conferido campo a campo contra
        // `SHOW CREATE TABLE` do ambiente de teste em produção pra garantir que bate exatamente
        // com o schema real depois de anos de ALTER TABLE incrementais. Convenção: toda
        // mudança de schema nova entra primeiro em admin/atualiza_banco.php (como ALTER
        // incremental, pra quem já tem o banco criado poder atualizar) e, quando o schema
        // "assentar", vem espelhada aqui pra instalação nova nascer completa de uma vez.
        $pdo->exec("CREATE TABLE IF NOT EXISTS usuarios (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nome VARCHAR(100) NOT NULL,
            apelido_publico VARCHAR(40) DEFAULT NULL,
            foto_perfil VARCHAR(255) DEFAULT NULL,
            email VARCHAR(150) NOT NULL UNIQUE,
            email_verificado_em DATETIME DEFAULT NULL,
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
            modo ENUM('avancado','basico') NOT NULL DEFAULT 'avancado',
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
            token_invalido_em DATETIME NULL,
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
            nome_usuario_telegram VARCHAR(32) DEFAULT NULL,
            origem_rastreio VARCHAR(100) DEFAULT NULL,
            pagou TINYINT(1) NOT NULL DEFAULT 0,
            bot_id INT,
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            id_fluxo INT DEFAULT NULL,
            FOREIGN KEY (bot_id) REFERENCES bots(id) ON DELETE SET NULL,
            INDEX idx_leads_bot (bot_id, id_telegram),
            INDEX idx_leads_bot_criado_em (bot_id, criado_em DESC),
            INDEX idx_leads_bot_pagou_criado (bot_id, pagou, criado_em DESC),
            INDEX idx_leads_fluxo (id_fluxo, criado_em)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // Um registro por /start (lead novo ou que voltou): base da contagem de "starts" do dashboard.
        $pdo->exec("CREATE TABLE IF NOT EXISTS leads_starts (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            lead_id INT NOT NULL,
            bot_id INT NOT NULL,
            criado_em DATETIME NOT NULL,
            INDEX idx_starts_bot_data (bot_id, criado_em),
            INDEX idx_starts_lead (lead_id, criado_em),
            FOREIGN KEY (bot_id) REFERENCES bots(id) ON DELETE CASCADE
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
            tipo_cobranca ENUM('unica', 'assinatura', 'recorrente') DEFAULT 'unica',
            status_renovacao ENUM('pendente', 'renovada', 'cancelada') DEFAULT 'pendente',
            venda_pai_id INT DEFAULT NULL,
            id_assinatura VARCHAR(100) DEFAULT NULL,
            id_plano INT DEFAULT NULL,
            ultimo_txid_renovacao VARCHAR(255) DEFAULT NULL,
            id_fluxo INT DEFAULT NULL,
            nome_produto VARCHAR(150) DEFAULT NULL,
            link_entrega VARCHAR(500) DEFAULT NULL,
            mensagem_entrega TEXT DEFAULT NULL,
            FOREIGN KEY (bot_id) REFERENCES bots(id) ON DELETE SET NULL,
            UNIQUE INDEX idx_transacao_id (transacao_id),
            INDEX idx_id_assinatura (id_assinatura),
            INDEX idx_vendas_lookup (id_telegram, bot_id, status),
            INDEX idx_vendas_ranking (status, criado_em, bot_id),
            INDEX idx_vendas_criado_em (criado_em DESC),
            INDEX idx_vendas_fluxo (id_fluxo, criado_em),
            INDEX idx_vendas_bot_criado (bot_id, criado_em)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS atividades (
            id INT AUTO_INCREMENT PRIMARY KEY,
            id_usuario INT NOT NULL,
            tipo VARCHAR(50) NOT NULL,
            titulo VARCHAR(100),
            descricao TEXT,
            icone VARCHAR(50),
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            lido_em DATETIME DEFAULT NULL,
            FOREIGN KEY (id_usuario) REFERENCES usuarios(id) ON DELETE CASCADE,
            INDEX idx_atividades_tipo (tipo),
            INDEX idx_atividades_notif (id_usuario, lido_em)
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
            UNIQUE KEY unico_membro (id_telegram, id_grupo_telegram, bot_id),
            INDEX idx_membros_plano_ativo (id_telegram, bot_id, status, data_expiracao)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // Tabelas do motor de fluxos, avisos de assinatura e prévias: antes só o Atualizar Banco criava (instalação nova ficava sem elas).
        $pdo->exec("CREATE TABLE IF NOT EXISTS avisos_assinatura_enviados (
            id INT AUTO_INCREMENT PRIMARY KEY,
            membro_id INT NOT NULL,
            marco VARCHAR(40) NOT NULL,
            enviado_em DATETIME NOT NULL,
            UNIQUE KEY unico_aviso (membro_id, marco),
            INDEX idx_avisos_enviado_em (enviado_em),
            FOREIGN KEY (membro_id) REFERENCES membros_grupos(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS fluxo_continuacoes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            bot_id INT NOT NULL,
            id_telegram VARCHAR(50) NOT NULL,
            id_fluxo INT NOT NULL,
            id_operador_destino VARCHAR(60) NOT NULL,
            executar_em DATETIME NOT NULL,
            status ENUM('pendente', 'processando', 'feito', 'falhou', 'cancelado') NOT NULL DEFAULT 'pendente',
            criado_em DATETIME NOT NULL,
            atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_continuacoes_pendentes (status, executar_em),
            INDEX idx_continuacoes_lead (bot_id, id_telegram, status),
            FOREIGN KEY (bot_id) REFERENCES bots(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS fluxo_ofertas_aceitas (
            bot_id INT NOT NULL,
            id_telegram VARCHAR(50) NOT NULL,
            tipo ENUM('upsell', 'downsell', 'order_bump') NOT NULL,
            desconto_percentual DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            valor_extra DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            nome VARCHAR(100) DEFAULT NULL,
            criado_em DATETIME NOT NULL,
            PRIMARY KEY (bot_id, id_telegram, tipo),
            FOREIGN KEY (bot_id) REFERENCES bots(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // Avisos push (Web Push) de Pix gerado e Pix pago — plano em deploy/PLANO-PUSH-NOTIFICACOES.md. Fila e aparelhos; interruptor geral em configuracoes.push_ativo (padrão desligado).
        $pdo->exec("CREATE TABLE IF NOT EXISTS push_inscricoes (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            id_usuario INT NOT NULL,
            endpoint_hash CHAR(64) NOT NULL,
            endpoint VARCHAR(600) NOT NULL,
            p256dh VARCHAR(100) NOT NULL,
            auth VARCHAR(40) NOT NULL,
            plataforma ENUM('ios','android','desktop','outro') NOT NULL DEFAULT 'outro',
            apelido VARCHAR(80) NOT NULL DEFAULT '',
            pref_pix_gerado TINYINT(1) NOT NULL DEFAULT 1,
            pref_pix_pago TINYINT(1) NOT NULL DEFAULT 1,
            ativo TINYINT(1) NOT NULL DEFAULT 1,
            falhas_seguidas SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            primeira_falha_em DATETIME NULL,
            ultimo_envio_ok DATETIME NULL,
            ultimo_visto DATETIME NULL,
            ultimo_aviso_gerado_em DATETIME NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_push_endpoint (endpoint_hash),
            KEY idx_push_usuario (id_usuario, ativo),
            FOREIGN KEY (id_usuario) REFERENCES usuarios(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $pdo->exec("CREATE TABLE IF NOT EXISTS push_fila (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            id_inscricao BIGINT UNSIGNED NOT NULL,
            tipo TINYINT UNSIGNED NOT NULL,
            id_venda INT NOT NULL,
            estado ENUM('pendente','enviando') NOT NULL DEFAULT 'pendente',
            tentativas TINYINT UNSIGNED NOT NULL DEFAULT 0,
            disponivel_em DATETIME NOT NULL,
            expira_em DATETIME NOT NULL,
            reservado_ate DATETIME NULL,
            lote CHAR(16) NULL,
            criado_em DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            UNIQUE KEY uq_push_item (id_inscricao, tipo, id_venda),
            KEY idx_push_pronto (estado, disponivel_em, id),
            KEY idx_push_lote (lote),
            FOREIGN KEY (id_inscricao) REFERENCES push_inscricoes(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $pdo->exec("CREATE TABLE IF NOT EXISTS push_metricas_hora (
            hora DATETIME NOT NULL PRIMARY KEY,
            enviados INT UNSIGNED NOT NULL DEFAULT 0,
            falhas_temporarias INT UNSIGNED NOT NULL DEFAULT 0,
            inscricoes_removidas INT UNSIGNED NOT NULL DEFAULT 0,
            descartados INT UNSIGNED NOT NULL DEFAULT 0,
            agrupados INT UNSIGNED NOT NULL DEFAULT 0,
            erros_fatais INT UNSIGNED NOT NULL DEFAULT 0,
            latencia_soma_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,
            latencia_n INT UNSIGNED NOT NULL DEFAULT 0,
            latencia_max_ms INT UNSIGNED NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS mensagens_agendadas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            bot_id INT NOT NULL,
            id_telegram VARCHAR(50) NOT NULL,
            message_id BIGINT NOT NULL,
            apagar_em DATETIME NOT NULL,
            apagada TINYINT(1) NOT NULL DEFAULT 0,
            depois_json TEXT DEFAULT NULL,
            INDEX idx_agendadas_pendentes (apagada, apagar_em),
            INDEX bot_id (bot_id),
            FOREIGN KEY (bot_id) REFERENCES bots(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS previas_vistas (
            bot_id INT NOT NULL,
            id_telegram VARCHAR(50) NOT NULL,
            visto_em DATETIME NOT NULL,
            PRIMARY KEY (bot_id, id_telegram),
            FOREIGN KEY (bot_id) REFERENCES bots(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS downsell_agenda (
            id INT AUTO_INCREMENT PRIMARY KEY,
            bot_id INT NOT NULL,
            id_telegram VARCHAR(50) NOT NULL,
            venda_id INT NOT NULL,
            id_plano VARCHAR(64) NOT NULL,
            enviados TINYINT UNSIGNED NOT NULL DEFAULT 0,
            proximo_em DATETIME NOT NULL,
            status ENUM('pendente', 'processando', 'concluido', 'cancelado') NOT NULL DEFAULT 'pendente',
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unico_downsell_venda (venda_id),
            INDEX idx_downsell_pendentes (status, proximo_em),
            INDEX idx_downsell_lead (bot_id, id_telegram, status),
            FOREIGN KEY (bot_id) REFERENCES bots(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS order_bump_agenda (
            id INT AUTO_INCREMENT PRIMARY KEY,
            bot_id INT NOT NULL,
            id_telegram VARCHAR(50) NOT NULL,
            venda_id INT NOT NULL,
            id_plano VARCHAR(64) NOT NULL,
            enviados TINYINT UNSIGNED NOT NULL DEFAULT 0,
            proximo_em DATETIME NOT NULL,
            status ENUM('pendente', 'processando', 'concluido', 'cancelado') NOT NULL DEFAULT 'pendente',
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unico_order_bump_venda (venda_id),
            INDEX idx_order_bump_pendentes (status, proximo_em),
            INDEX idx_order_bump_lead (bot_id, id_telegram, status),
            FOREIGN KEY (bot_id) REFERENCES bots(id) ON DELETE CASCADE
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
            ativo TINYINT(1) DEFAULT 0,
            prioridade INT DEFAULT 100,
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (id_usuario) REFERENCES usuarios(id) ON DELETE CASCADE,
            FOREIGN KEY (id_gateway) REFERENCES gateways(id) ON DELETE CASCADE,
            UNIQUE KEY unique_user_gateway (id_usuario, id_gateway)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // Campanha em etapas (25/10/2026): pertence a um fluxo (id_fluxo) e vai pelos bots dele (bots_ids, congelados ao iniciar); campanha antiga tem só bot_id.
        // Os valores novos de audiencia e status ficam no FIM da lista de propósito (assim o banco antigo aceita a mudança sem reconstruir a tabela).
        $pdo->exec("CREATE TABLE IF NOT EXISTS remarketing_campanhas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            id_usuario INT NOT NULL,
            bot_id INT NULL,
            id_fluxo INT NULL,
            bots_ids VARCHAR(120) NULL,
            nome VARCHAR(100) DEFAULT NULL,
            audiencia ENUM('nao_comprou','comprou','todos','pix_gerado','expirados','membros_ativos') NOT NULL,
            periodo_tipo VARCHAR(10) NOT NULL DEFAULT 'total',
            periodo_inicio DATETIME NULL,
            periodo_fim DATETIME NULL,
            mensagem TEXT NOT NULL,
            midia_caminho VARCHAR(255) DEFAULT NULL,
            midia_tipo ENUM('foto','video') DEFAULT NULL,
            midia_file_id VARCHAR(255) DEFAULT NULL,
            midia_file_ids TEXT NULL,
            variantes MEDIUMTEXT NULL,
            ofertas MEDIUMTEXT NULL,
            agendado_em DATETIME NOT NULL,
            status ENUM('pendente','processando','concluida','falha','rascunho') DEFAULT 'pendente',
            total_destinatarios INT DEFAULT 0,
            enviados INT DEFAULT 0,
            entregues INT DEFAULT 0,
            falhas INT DEFAULT 0,
            offset_envio INT DEFAULT 0,
            processado_em DATETIME NULL,
            recorrencia TEXT NULL,
            execucoes INT NOT NULL DEFAULT 0,
            acum_entregues INT NOT NULL DEFAULT 0,
            acum_falhas INT NOT NULL DEFAULT 0,
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (id_usuario) REFERENCES usuarios(id) ON DELETE CASCADE,
            FOREIGN KEY (bot_id) REFERENCES bots(id) ON DELETE CASCADE,
            INDEX idx_status_agendado (status, agendado_em),
            INDEX idx_rm_usuario (id_usuario, criado_em)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS remarketing_envios (
            id INT AUTO_INCREMENT PRIMARY KEY,
            campanha_id INT NOT NULL,
            id_telegram VARCHAR(50) NOT NULL,
            resultado ENUM('sucesso','falha') NOT NULL,
            resposta TEXT,
            variante TINYINT NOT NULL DEFAULT 0,
            enviado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (campanha_id) REFERENCES remarketing_campanhas(id) ON DELETE CASCADE,
            INDEX idx_campanha (campanha_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // Qual venda veio de qual campanha (a receita do cartão da campanha soma só as vendas pagas daqui).
        $pdo->exec("CREATE TABLE IF NOT EXISTS remarketing_vendas (
            venda_id INT NOT NULL PRIMARY KEY,
            campanha_id INT NOT NULL,
            variante TINYINT NOT NULL DEFAULT 0,
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (campanha_id) REFERENCES remarketing_campanhas(id) ON DELETE CASCADE,
            INDEX idx_rmv_campanha (campanha_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS usuarios_traqueamento (
            id INT AUTO_INCREMENT PRIMARY KEY,
            id_usuario INT NOT NULL,
            facebook_ativo TINYINT(1) DEFAULT 0,
            facebook_pixel_id VARCHAR(255) DEFAULT NULL,
            facebook_access_token TEXT DEFAULT NULL,
            tiktok_ativo TINYINT(1) DEFAULT 0,
            tiktok_pixel_id VARCHAR(255) DEFAULT NULL,
            tiktok_access_token TEXT DEFAULT NULL,
            utmfy_ativo TINYINT(1) DEFAULT 0,
            utmfy_token TEXT DEFAULT NULL,
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

        require_once __DIR__ . '/funcoes/redirecionadores.php';
        foreach (esquemaRedirecionamento() as $sql_redirecionamento) {
            $pdo->exec($sql_redirecionamento);
        }

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

        // Página Comunidade: links institucionais (grupos, canais, redes) — editado só pelo
        // admin (admin/comunidade.php), exibido em comunidade.php. membros_max = 0 = sem limite.
        $pdo->exec("CREATE TABLE IF NOT EXISTS comunidade_links (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tipo VARCHAR(30) NOT NULL DEFAULT 'outro',
            titulo VARCHAR(80) NOT NULL,
            subtitulo VARCHAR(120) DEFAULT NULL,
            url VARCHAR(500) NOT NULL,
            icone VARCHAR(30) NOT NULL DEFAULT 'link',
            membros_atual INT UNSIGNED NOT NULL DEFAULT 0,
            membros_max INT UNSIGNED NOT NULL DEFAULT 0,
            ordem INT NOT NULL DEFAULT 0,
            ativo TINYINT(1) NOT NULL DEFAULT 1,
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // Webhooks de saída do usuário (lead, PIX gerado, pagamento aprovado). bot_id NULL =
        // todos os bots daquele usuário. Ver funcoes/webhooks.php.
        $pdo->exec("CREATE TABLE IF NOT EXISTS webhooks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            id_usuario INT NOT NULL,
            nome VARCHAR(80) NOT NULL,
            url VARCHAR(500) NOT NULL,
            secret VARCHAR(128) DEFAULT NULL,
            eventos VARCHAR(120) NOT NULL,
            bot_id INT DEFAULT NULL,
            ativo TINYINT(1) NOT NULL DEFAULT 1,
            falhas_consecutivas INT NOT NULL DEFAULT 0,
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (id_usuario) REFERENCES usuarios(id) ON DELETE CASCADE,
            FOREIGN KEY (bot_id) REFERENCES bots(id) ON DELETE CASCADE,
            INDEX idx_webhooks_usuario_ativo (id_usuario, ativo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS webhooks_envios (
            id INT AUTO_INCREMENT PRIMARY KEY,
            webhook_id INT NOT NULL,
            evento VARCHAR(40) NOT NULL,
            http_status SMALLINT DEFAULT NULL,
            sucesso TINYINT(1) NOT NULL DEFAULT 0,
            erro VARCHAR(180) DEFAULT NULL,
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (webhook_id) REFERENCES webhooks(id) ON DELETE CASCADE,
            INDEX idx_webhooks_envios_webhook (webhook_id, criado_em)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS configuracoes (
            chave VARCHAR(50) NOT NULL PRIMARY KEY,
            valor TEXT,
            atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS tokens_email (
            id INT AUTO_INCREMENT PRIMARY KEY,
            id_usuario INT NOT NULL,
            tipo VARCHAR(20) NOT NULL,
            token_hash CHAR(64) NOT NULL,
            expira_em DATETIME NOT NULL,
            usado_em DATETIME DEFAULT NULL,
            criado_em DATETIME NOT NULL,
            UNIQUE KEY unico_token (token_hash),
            INDEX idx_tokens_usuario (id_usuario, tipo),
            FOREIGN KEY (id_usuario) REFERENCES usuarios(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS fila_emails (
            id INT AUTO_INCREMENT PRIMARY KEY,
            para VARCHAR(190) NOT NULL,
            assunto VARCHAR(255) NOT NULL,
            html MEDIUMTEXT NOT NULL,
            tipo VARCHAR(30) NOT NULL DEFAULT 'geral',
            status ENUM('pendente','processando','enviado','falhou') NOT NULL DEFAULT 'pendente',
            tentativas TINYINT NOT NULL DEFAULT 0,
            ultimo_erro VARCHAR(255) DEFAULT NULL,
            proximo_envio DATETIME NOT NULL,
            valido_ate DATETIME DEFAULT NULL,
            criado_em DATETIME NOT NULL,
            enviado_em DATETIME DEFAULT NULL,
            atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_fila_pendentes (status, proximo_envio)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS email_envios (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            para VARCHAR(190) NOT NULL,
            assunto VARCHAR(255) NOT NULL DEFAULT '',
            tipo VARCHAR(30) NOT NULL DEFAULT 'geral',
            origem VARCHAR(12) NOT NULL DEFAULT 'direto',
            via VARCHAR(10) NOT NULL DEFAULT 'smtp',
            de_email VARCHAR(190) NOT NULL DEFAULT '',
            status VARCHAR(10) NOT NULL,
            resposta VARCHAR(500) DEFAULT NULL,
            tentativa TINYINT UNSIGNED NOT NULL DEFAULT 1,
            fila_id INT DEFAULT NULL,
            INDEX idx_email_envios_data (criado_em),
            INDEX idx_email_envios_status (status, criado_em),
            INDEX idx_email_envios_para (para, criado_em)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS limites_taxa (
            chave VARCHAR(120) NOT NULL PRIMARY KEY,
            janela_inicio DATETIME NOT NULL,
            contagem INT UNSIGNED NOT NULL DEFAULT 0,
            INDEX idx_limites_janela (janela_inicio)
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
            imagem_banner VARCHAR(255) DEFAULT NULL,
            data_inicio DATETIME NOT NULL,
            data_fim DATETIME NOT NULL,
            ativa TINYINT(1) DEFAULT 1,
            finalizada_em DATETIME DEFAULT NULL,
            arquivada_em DATETIME DEFAULT NULL,
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

        // Seed: gateway OmegaPayments (único gateway suportado hoje -- gateways.php espera essa
        // linha existir pra aparecer na tela de configuração).
        $pdo->exec("INSERT INTO gateways (nome, titulo, ativo) VALUES ('omegapayments', 'OmegaPayments (Pix)', 0)");

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
        // Chave dos crons chamados por URL (?chave=...). Sem ela, o cron pela web é recusado (403); na VPS os crons rodam pela linha de comando e não usam.
        $config_content .= "define('CHAVE_SECRETA_CRON', '" . bin2hex(random_bytes(16)) . "');\n";

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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Instalação</title>
    <?php include __DIR__ . '/tema_inline.php'; ?>
    <link rel="stylesheet" href="assets/css/painel.css?v=<?php echo @filemtime(__DIR__.'/assets/css/painel.css'); ?>">
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

        <?php if ($ja_instalado): ?>
            <p>O painel já está instalado. Alterações de banco não passam por esta tela.</p>
            <a href="admin/dashboard" class="botao botao-primario botao-bloco" style="margin-top:16px;">Ir para o painel</a>
        <?php else: ?>
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
        <?php endif; ?>
    </div>
</body>
</html>
