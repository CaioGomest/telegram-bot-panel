<?php
declare(strict_types=1);

// Migração incremental do banco -- idempotente e segura pra rodar quantas vezes quiser
// (CREATE TABLE IF NOT EXISTS + ALTER em try/catch que ignora "coluna já existe"). É a
// ferramenta de ATUALIZAR uma instalação já existente; instalacao.php é a de criar uma
// do zero (e já nasce com o schema completo de hoje, reconferido em 2026-09-25).
//
// Convenção a partir de 2026-09-25: toda coluna/tabela nova entra AQUI primeiro, como um
// novo bloco CREATE/ALTER (nunca editando o schema de instalacao.php direto) -- só depois
// de confirmado que funciona é que o mesmo campo é espelhado em instalacao.php, pra
// instalação nova nascer completa sem depender de rodar este arquivo em seguida. Os ALTERs
// mais antigos abaixo continuam aqui de propósito (não foram "limpos"): mesmo que a coluna
// já apareça no CREATE TABLE logo acima dele nesta mesma função, o CREATE só roda de fato
// numa tabela que ainda não existe -- numa instalação mais antiga, que já tinha a tabela
// antes daquela coluna ser adicionada, é o ALTER (não o CREATE) que efetivamente traz a
// coluna. Apagar esses ALTERs quebraria o caminho de atualização pra qualquer instalação
// que ainda não rodou este arquivo desde então.
require_once __DIR__ . '/../funcoes/usuario.php';
require_once __DIR__ . '/../funcoes/relatorio_debug.php';
require_once __DIR__ . '/../funcoes/gateways.php';
verificarAdminOuInstalacao();

/**
 * O preenchimento de metricas_horarias_admin/usuario lê e escreve nas mesmas linhas que
 * cron/cron_metricas_admin.php toca a cada 5 minutos -- rodar "Atualizar Banco" bem na hora
 * em que o cron dispara colide (achado ao vivo em 02/10: "SQLSTATE[HY000]: General error:
 * 1020 Record has changed since last read ... try restarting transaction", exatamente o
 * texto que o MySQL usa pra pedir retry). Em vez de abortar a migração inteira por uma
 * colisão passageira de alguns milissegundos, tenta de novo algumas vezes antes de desistir.
 */
function execComRetryDeColisao(PDO $pdo, string $sql, int $max_tentativas = 4): void
{
    $tentativas = 0;
    while (true) {
        try {
            $pdo->exec($sql);
            return;
        } catch (PDOException $e) {
            $tentativas++;
            $eh_colisao = str_contains($e->getMessage(), 'Record has changed since last read')
                || str_contains($e->getMessage(), 'Deadlock found')
                || str_contains($e->getMessage(), 'Lock wait timeout');
            if (!$eh_colisao || $tentativas >= $max_tentativas) {
                throw $e;
            }
            usleep(300000); // 300ms
        }
    }
}

ob_start();

try {
    echo "<h2>Atualização Unificada do Banco de Dados</h2>";
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
    try { $pdo->exec("ALTER TABLE fluxos ADD COLUMN modo ENUM('avancado','basico') NOT NULL DEFAULT 'avancado' AFTER link_suporte"); } catch (PDOException $e) {}
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
    try { $pdo->exec("ALTER TABLE bots ADD COLUMN webhook_secret VARCHAR(64) NULL AFTER url_webhook"); } catch (PDOException $e) {}


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
    // Telegram manda o @username em todo /start (message.from.username), mas até aqui
    // nunca era salvo em lugar nenhum -- webhook de saída sempre mandava customer.username
    // = null pra quem integra (achado em rodada de teste, ver
    // anotacoes/OBSERVACOES.md). Username do Telegram tem no máximo
    // 32 caracteres.
    try { $pdo->exec("ALTER TABLE leads ADD COLUMN nome_usuario_telegram VARCHAR(32) DEFAULT NULL AFTER telefone"); } catch (PDOException $e) {}
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
    $tinha_tempo_acesso = colunaExiste('vendas', 'tempo_acesso_minutos');   // antes do ALTER abaixo: a conversão dias -> minutos só vale quando a coluna acaba de nascer

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
        "ADD COLUMN nome_produto VARCHAR(150) DEFAULT NULL", // Nome do plano na venda: vai no evento de compra (Facebook/UTMify)
        "ADD COLUMN link_entrega VARCHAR(500) DEFAULT NULL", // Fluxo Guiado: link enviado ao cliente quando o pagamento é confirmado (entrega por link)
        "ADD COLUMN mensagem_entrega TEXT DEFAULT NULL", // Fluxo Guiado: texto enviado ao cliente quando o pagamento é confirmado (entrega por mensagem)
        "ADD COLUMN ultimo_txid_renovacao VARCHAR(255) DEFAULT NULL" // Idempotência do webhook de PIX Automático (cobsr)
    ];

    foreach ($colunas_vendas as $alter) {
        try {
            $pdo->exec("ALTER TABLE vendas $alter");
            echo "Vendas atualizada: $alter<br>";
        } catch (PDOException $e) {}
    }

    // Índices de busca por identificador de pagamento — sem eles, toda notificação
    // de webhook (InfoPago) faz full table scan em 'vendas', que piora conforme o
    // histórico de vendas cresce. UNIQUE em transacao_id também barra duplicidade
    // de txid a nível de banco (NULLs continuam permitidos em múltiplas linhas).
    try { $pdo->exec("ALTER TABLE vendas ADD UNIQUE INDEX idx_transacao_id (transacao_id)"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE vendas ADD INDEX idx_id_assinatura (id_assinatura)"); } catch (PDOException $e) {}

    // Migração de dados antigos (dias -> minutos): SÓ quando a coluna acaba de ser criada. Antes rodava a cada "Atualizar Banco" e, em banco grande
    // (a VPS tem 1,4 milhão de vendas do teste de carga sem esse campo), reescrevia a tabela inteira numa transação só (26/10/2026). Venda sem minutos
    // continua valendo: o webhook e os crons usam dias_acesso * 1440 quando o campo está vazio.
    if (!$tinha_tempo_acesso) {
        try {
            $pdo->exec("UPDATE vendas SET tempo_acesso_minutos = dias_acesso * 1440 WHERE tempo_acesso_minutos IS NULL AND dias_acesso IS NOT NULL");
        } catch (PDOException $e) {}
    }

    // 'expirado' não existia no ENUM original de status; gravações antigas desse valor foram
    // silenciosamente salvas como '' pelo MySQL. Amplia o ENUM e corrige as linhas afetadas. Só mexe na tabela se o ENUM ainda não é este (tabela grande).
    $tipo_status_vendas = strtolower(str_replace(' ', '', (string) $pdo->query("SELECT column_type FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'vendas' AND column_name = 'status'")->fetchColumn()));
    if ($tipo_status_vendas !== "enum('gerado','pago','cancelado','expirado')") {
        try {
            $pdo->exec("ALTER TABLE vendas MODIFY COLUMN status ENUM('gerado', 'pago', 'cancelado', 'expirado') DEFAULT 'gerado'");
        } catch (PDOException $e) {}
        try {
            $pdo->exec("UPDATE vendas SET status = 'expirado' WHERE status = ''");
        } catch (PDOException $e) {}
    }

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
            lido_em DATETIME DEFAULT NULL,
            FOREIGN KEY (id_usuario) REFERENCES usuarios(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sql_atividades);
    echo "Tabela 'atividades' OK.<br>";

    // 'atividades' cresce mais rápido que qualquer outra tabela (toda venda/split/login
    // gera pelo menos uma linha) e funcoes/log.php filtra por 'tipo' (tipo=?, tipo IN (...),
    // tipo NOT IN (...)) -- sem índice, isso é full table scan. Ver anotacoes/OBSERVACOES.md.
    try { $pdo->exec("ALTER TABLE atividades ADD INDEX idx_atividades_tipo (tipo)"); } catch (PDOException $e) {}

    // Sino de notificações no header: coluna de lido + índice pro contador de não-lidas
    // (id_usuario + lido_em). Sem backfill, o badge estouraria com o histórico inteiro
    // de vendas/leads. O UPDATE só roda na primeira vez que a coluna é criada — nas
    // próximas execuções o ALTER falha e as não-lidas novas ficam intactas.
    // Ver anotacoes/OBSERVACOES.md.
    $coluna_lido_criada = false;
    try {
        $pdo->exec("ALTER TABLE atividades ADD COLUMN lido_em DATETIME DEFAULT NULL");
        $coluna_lido_criada = true;
        echo "Coluna 'atividades.lido_em' criada.<br>";
    } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE atividades ADD INDEX idx_atividades_notif (id_usuario, lido_em)"); } catch (PDOException $e) {}
    if ($coluna_lido_criada) {
        $pdo->exec("UPDATE atividades SET lido_em = criado_em WHERE lido_em IS NULL");
        echo "Histórico de 'atividades' marcado como lido (sino começa zerado).<br>";
    }

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

    // Colunas de Cash-Out (API de Contas/transferência) eram só da InfoPago -- simulava split
    // via transferência manual após o Pix cair (removida do sistema, ver
    // anotacoes/OBSERVACOES.md). Dropa se ainda existirem de uma instalação
    // anterior; instalação nova nunca chega a criar essas colunas.
    foreach (['cashout_client_id', 'cashout_client_secret', 'cashout_certificado', 'cashout_cert_password'] as $coluna_cashout) {
        try {
            $pdo->exec("ALTER TABLE usuarios_gateways DROP COLUMN $coluna_cashout");
            echo "Coluna '$coluna_cashout' removida de 'usuarios_gateways' (era só da InfoPago).<br>";
        } catch (PDOException $e) {}
    }

    // Os campos abaixo passam a guardar valor cifrado (ver funcoes/criptografia.php), que é
    // maior que o texto original — alarga a coluna pra não correr risco de truncar.
    foreach (['client_secret', 'cert_password', 'chave_pix'] as $coluna_cifrada) {
        try {
            $pdo->exec("ALTER TABLE usuarios_gateways MODIFY COLUMN $coluna_cifrada VARCHAR(500) NULL");
        } catch (PDOException $e) {}
    }

    // Migra pra criptografado qualquer credencial que ainda esteja em texto puro (dado salvo
    // antes dessa mudança). Idempotente: se já rodou antes, os valores já cifrados são
    // ignorados (detectados pelo prefixo 'enc:v1:' dentro de criptografarSegredo/decifrar).
    require_once __DIR__ . '/../funcoes/criptografia.php';
    if (chaveCriptografiaDisponivel()) {
        $stmt_gw_cred = $pdo->query("SELECT id, client_secret, cert_password, chave_pix FROM usuarios_gateways");
        $linhas_migradas = 0;
        foreach ($stmt_gw_cred->fetchAll(PDO::FETCH_ASSOC) as $linha_gw) {
            $campos_atualizar = [];
            foreach (['client_secret', 'cert_password', 'chave_pix'] as $campo) {
                $valor_atual = (string)($linha_gw[$campo] ?? '');
                if ($valor_atual !== '' && strpos($valor_atual, 'enc:v1:') !== 0) {
                    $campos_atualizar[$campo] = criptografarSegredo($valor_atual);
                }
            }
            if ($campos_atualizar) {
                $sets = implode(', ', array_map(fn($c) => "$c = ?", array_keys($campos_atualizar)));
                $valores = array_values($campos_atualizar);
                $valores[] = $linha_gw['id'];
                $pdo->prepare("UPDATE usuarios_gateways SET $sets WHERE id = ?")->execute($valores);
                $linhas_migradas++;
            }
        }
        if ($linhas_migradas > 0) {
            echo "Credenciais de $linhas_migradas gateway(s) migradas para o formato criptografado.<br>";
        }
    } else {
        echo "Aviso: CHAVE_CRIPTOGRAFIA_GATEWAYS não configurada em config.php — credenciais de gateway continuam em texto puro até essa chave ser adicionada.<br>";
    }

    echo "Tabela 'usuarios_gateways' OK.<br>";

    // Modelo antigo do split (cash-out do InfoPago: o sistema transferia a comissão depois do Pix cair e anotava o resultado) saiu junto com o InfoPago.
    // Hoje a OmegaPayments divide o dinheiro no pagamento (split nativo na cobrança) e a comissão fica em vendas.comissao_admin, gravada na criação da
    // cobrança. Remove o que ficou sem uso: as tabelas vendas_splits e usuarios_splits (filha primeiro) e as colunas vendas.split_status/split_valor/split_em.
    // Cada passo só roda se ainda existir; a remoção das colunas tenta ALGORITHM=INSTANT (não reescreve a tabela de vendas, que pode ter milhões de linhas)
    // e, se o banco não aceitar, avisa em vez de travar o sistema reescrevendo tudo: nesse caso rode o ALTER do aviso fora do horário de pico.
    foreach (['vendas_splits', 'usuarios_splits'] as $tabela_morta) {
        try {
            $existe = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '$tabela_morta'")->fetchColumn();
            if ($existe) {
                $pdo->exec("DROP TABLE $tabela_morta");
                echo "Tabela '$tabela_morta' removida (era do split do InfoPago).<br>";
            }
        } catch (PDOException $e) {
            echo "Não consegui remover a tabela '$tabela_morta': " . htmlspecialchars($e->getMessage()) . "<br>";
        }
    }
    try {
        $colunas_mortas = [];
        foreach (['split_status', 'split_valor', 'split_em'] as $coluna_morta) {
            $existe = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'vendas' AND column_name = '$coluna_morta'")->fetchColumn();
            if ($existe) {
                $colunas_mortas[] = $coluna_morta;
            }
        }
        if ($colunas_mortas) {
            $alter_colunas = 'ALTER TABLE vendas ' . implode(', ', array_map(static function ($c) { return "DROP COLUMN $c"; }, $colunas_mortas));
            try {
                $pdo->exec($alter_colunas . ', ALGORITHM=INSTANT');
                echo "Colunas removidas de 'vendas' (eram do split do InfoPago): " . implode(', ', $colunas_mortas) . ".<br>";
            } catch (PDOException $e) {
                echo "As colunas " . implode(', ', $colunas_mortas) . " de 'vendas' já não são usadas, mas este banco não as remove na hora (a tabela é grande). Rode fora do horário de pico: <code>" . htmlspecialchars($alter_colunas) . ";</code><br>";
            }
        }
    } catch (PDOException $e) {
        echo "Não consegui conferir as colunas antigas do split: " . htmlspecialchars($e->getMessage()) . "<br>";
    }

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
    // Foto/vídeo opcional na campanha -- "mandar só msg não vinga muito" (pedido direto
    // de cliente). midia_file_id cacheia o file_id que o Telegram devolve no primeiro
    // envio bem-sucedido: os envios seguintes da mesma campanha reusam esse ID em vez de
    // reenviar o arquivo (ver cron_remarketing.php).
    try {
        $pdo->exec("ALTER TABLE remarketing_campanhas ADD COLUMN midia_caminho VARCHAR(255) DEFAULT NULL AFTER mensagem");
        echo "Coluna 'midia_caminho' adicionada em 'remarketing_campanhas'.<br>";
    } catch (PDOException $e) {}
    try {
        $pdo->exec("ALTER TABLE remarketing_campanhas ADD COLUMN midia_tipo ENUM('foto','video') DEFAULT NULL AFTER midia_caminho");
        echo "Coluna 'midia_tipo' adicionada em 'remarketing_campanhas'.<br>";
    } catch (PDOException $e) {}
    try {
        $pdo->exec("ALTER TABLE remarketing_campanhas ADD COLUMN midia_file_id VARCHAR(255) DEFAULT NULL AFTER midia_tipo");
        echo "Coluna 'midia_file_id' adicionada em 'remarketing_campanhas'.<br>";
    } catch (PDOException $e) {}
    try {
        $pdo->exec("ALTER TABLE remarketing_campanhas ADD COLUMN nome VARCHAR(100) DEFAULT NULL AFTER bot_id");
        echo "Coluna 'nome' adicionada em 'remarketing_campanhas'.<br>";
    } catch (PDOException $e) {}
    // Campanha em etapas (25/10/2026): fluxo, bots, período, variantes A/B, ofertas e rascunho. Cada ALTER roda sozinho e repetir é inofensivo.
    // Os valores novos de audiencia e status entram no FIM da lista (o banco aceita sem reconstruir a tabela). bot_id passa a aceitar NULL: a campanha de um fluxo não tem um bot só.
    foreach ([
        "ADD COLUMN id_fluxo INT NULL AFTER id_usuario",
        "ADD COLUMN bots_ids VARCHAR(120) NULL AFTER id_fluxo",
        "ADD COLUMN periodo_tipo VARCHAR(10) NOT NULL DEFAULT 'total' AFTER audiencia",
        "ADD COLUMN periodo_inicio DATETIME NULL AFTER periodo_tipo",
        "ADD COLUMN periodo_fim DATETIME NULL AFTER periodo_inicio",
        "ADD COLUMN midia_file_ids TEXT NULL AFTER midia_file_id",
        "ADD COLUMN variantes MEDIUMTEXT NULL AFTER midia_file_ids",
        "ADD COLUMN ofertas MEDIUMTEXT NULL AFTER variantes",
        "ADD INDEX idx_rm_usuario (id_usuario, criado_em)",
        "MODIFY bot_id INT NULL",
        "MODIFY audiencia ENUM('nao_comprou','comprou','todos','pix_gerado','expirados','membros_ativos') NOT NULL",
        "MODIFY status ENUM('pendente','processando','concluida','falha','rascunho') DEFAULT 'pendente'",
        // Reenvio e recorrência (26/10/2026): o agendamento (diário, horários, a cada X horas, dias da semana), quantas vezes já saiu e o que as execuções anteriores enviaram (os contadores da campanha são os da execução atual).
        "ADD COLUMN recorrencia TEXT NULL AFTER processado_em",
        "ADD COLUMN execucoes INT NOT NULL DEFAULT 0 AFTER recorrencia",
        "ADD COLUMN acum_entregues INT NOT NULL DEFAULT 0 AFTER execucoes",
        "ADD COLUMN acum_falhas INT NOT NULL DEFAULT 0 AFTER acum_entregues",
    ] as $alteracao_rm) {
        try { $pdo->exec("ALTER TABLE remarketing_campanhas $alteracao_rm"); } catch (PDOException $e) {}
    }
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
    try { $pdo->exec("ALTER TABLE remarketing_envios ADD COLUMN variante TINYINT NOT NULL DEFAULT 0 AFTER resposta"); } catch (PDOException $e) {}
    echo "Tabela 'remarketing_envios' OK.<br>";

    // Qual venda veio de qual campanha de remarketing (receita do cartão da campanha).
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS remarketing_vendas (
            venda_id INT NOT NULL PRIMARY KEY,
            campanha_id INT NOT NULL,
            variante TINYINT NOT NULL DEFAULT 0,
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (campanha_id) REFERENCES remarketing_campanhas(id) ON DELETE CASCADE,
            INDEX idx_rmv_campanha (campanha_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo "Tabela 'remarketing_vendas' OK.<br>";

    try { $pdo->exec("ALTER TABLE leads ADD INDEX idx_leads_bot (bot_id, id_telegram)"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE vendas ADD INDEX idx_vendas_lookup (id_telegram, bot_id, status)"); } catch (PDOException $e) {}

    $sql_traqueamento = "
        CREATE TABLE IF NOT EXISTS usuarios_traqueamento (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sql_traqueamento);

    try { $pdo->exec("ALTER TABLE usuarios_traqueamento ADD COLUMN facebook_ativo TINYINT(1) DEFAULT 0 AFTER id_usuario"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE usuarios_traqueamento ADD COLUMN tiktok_ativo TINYINT(1) DEFAULT 0 AFTER facebook_access_token"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE usuarios_traqueamento ADD COLUMN utmfy_ativo TINYINT(1) DEFAULT 0 AFTER tiktok_access_token"); } catch (PDOException $e) {}

    // facebook_access_token/tiktok_access_token/utmfy_token passam a ser cifrados
    // (criptografarSegredo(), mesmo mecanismo do client_secret dos gateways) -- o formato
    // cifrado ("enc:v1:" + base64(iv+texto)) de um token de ~250 caracteres passa de 255,
    // então a coluna precisa virar TEXT antes do próximo salvamento truncar o valor.
    try { $pdo->exec("ALTER TABLE usuarios_traqueamento MODIFY COLUMN facebook_access_token TEXT DEFAULT NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE usuarios_traqueamento MODIFY COLUMN tiktok_access_token TEXT DEFAULT NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE usuarios_traqueamento MODIFY COLUMN utmfy_token TEXT DEFAULT NULL"); } catch (PDOException $e) {}
    
    echo "Tabela 'usuarios_traqueamento' OK.<br>";


    // InfoPago saiu do sistema (ver anotacoes/OBSERVACOES.md) -- não semeia
    // mais essa linha. A linha antiga (se existir de uma instalação anterior) fica só de
    // histórico pras vendas antigas continuarem mostrando o nome do gateway corretamente;
    // gatewaysSuportados() já não inclui 'infopago', então ela some sozinha de toda UI.
    $stmt_gateway_omega = $pdo->query("SELECT COUNT(*) FROM gateways WHERE nome = 'omegapayments'");
    if ($stmt_gateway_omega->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO gateways (nome, titulo, ativo) VALUES ('omegapayments', 'OmegaPayments (Pix)', 0)");
        echo "Gateway 'OmegaPayments' inserido.<br>";
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

    // Redirecionamento (/l/{slug}): link público dos anúncios. Ver funcoes/redirecionadores.php.
    require_once __DIR__ . '/../funcoes/redirecionadores.php';
    foreach (esquemaRedirecionamento() as $sql_redirecionamento) {
        $pdo->exec($sql_redirecionamento);
    }
    echo "Tabelas de redirecionamento OK.<br>";

    // Identidade visual do painel (white-label): nome, logo e favicon por instalação.
    // Ver funcoes/configuracoes.php e admin/configuracoes.php.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS configuracoes (
            chave VARCHAR(50) NOT NULL PRIMARY KEY,
            valor TEXT,
            atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo "Tabela 'configuracoes' OK.<br>";

    $sql_tentativas_login = "
        CREATE TABLE IF NOT EXISTS tentativas_login (
            id INT AUTO_INCREMENT PRIMARY KEY,
            identificador VARCHAR(150) NOT NULL,
            tentativas INT DEFAULT 0,
            bloqueado_ate DATETIME NULL,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_identificador (identificador)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sql_tentativas_login);
    echo "Tabela 'tentativas_login' OK.<br>";

    // Ranking de faturamento por campanha — ver cron_ranking.php (calcula ranking_cache)
    // e admin/ranking.php (CRUD de campanhas/prêmios).
    $sql_campanhas_ranking = "
        CREATE TABLE IF NOT EXISTS campanhas_ranking (
            id INT AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(60) NOT NULL UNIQUE,
            titulo VARCHAR(100) NOT NULL,
            subtitulo VARCHAR(255) DEFAULT NULL,
            tipo ENUM('oficial','mensal') DEFAULT 'oficial',
            data_inicio DATETIME NOT NULL,
            data_fim DATETIME NOT NULL,
            ativa TINYINT(1) DEFAULT 1,
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sql_campanhas_ranking);
    echo "Tabela 'campanhas_ranking' OK.<br>";

    $sql_campanhas_ranking_premios = "
        CREATE TABLE IF NOT EXISTS campanhas_ranking_premios (
            id INT AUTO_INCREMENT PRIMARY KEY,
            campanha_id INT NOT NULL,
            posicao INT NOT NULL,
            titulo VARCHAR(100) NOT NULL,
            descricao VARCHAR(255) DEFAULT NULL,
            FOREIGN KEY (campanha_id) REFERENCES campanhas_ranking(id) ON DELETE CASCADE,
            UNIQUE KEY unico_campanha_posicao (campanha_id, posicao)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sql_campanhas_ranking_premios);
    echo "Tabela 'campanhas_ranking_premios' OK.<br>";

    $sql_ranking_cache = "
        CREATE TABLE IF NOT EXISTS ranking_cache (
            campanha_id INT NOT NULL,
            id_usuario INT NOT NULL,
            faturamento DECIMAL(12,2) NOT NULL DEFAULT 0,
            posicao INT NOT NULL,
            atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (campanha_id, id_usuario),
            KEY idx_ranking_posicao (campanha_id, posicao),
            FOREIGN KEY (campanha_id) REFERENCES campanhas_ranking(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sql_ranking_cache);
    echo "Tabela 'ranking_cache' OK.<br>";

    // Marca que o cron já fechou o placar final desta campanha. Enquanto for NULL e a data
    // tiver passado, o cron ainda deve um último recálculo. Ver cron/cron_ranking.php.
    // Guarda de qual link de rastreamento o lead veio. Antes o identificador do /start so
    // incrementava contador em links_rastreamento e era descartado -- sem isso nao da pra
    // dizer qual campanha gerou qual venda, que e o proposito do traqueamento.
    try {
        $pdo->exec("ALTER TABLE leads ADD COLUMN origem_rastreio VARCHAR(100) DEFAULT NULL AFTER telefone");
        echo "Coluna 'origem_rastreio' adicionada em 'leads'.<br>";
    } catch (PDOException $e) {}

    try {
        $pdo->exec("ALTER TABLE campanhas_ranking ADD COLUMN finalizada_em DATETIME DEFAULT NULL AFTER ativa");
        echo "Coluna 'finalizada_em' adicionada em 'campanhas_ranking'.<br>";
    } catch (PDOException $e) {}

    // Campanha arquivada: sai da lista principal do admin, das telas do usuário e do cron, mas o registro (período, prêmios,
    // placar final e ganhadores) fica guardado e pode voltar. Campanha de ranking nunca é apagada pela tela.
    try {
        $pdo->exec("ALTER TABLE campanhas_ranking ADD COLUMN arquivada_em DATETIME DEFAULT NULL AFTER finalizada_em");
        echo "Coluna 'arquivada_em' adicionada em 'campanhas_ranking'.<br>";
    } catch (PDOException $e) {}

    // O ENUM 'tipo' ('oficial'/'mensal') saiu: a tela só buscava 'oficial', então uma campanha
    // criada como 'mensal' simplesmente nunca aparecia pra ninguém. Agora existe uma campanha
    // em cartaz por vez, escolhida pelas datas.
    try {
        $pdo->exec("ALTER TABLE campanhas_ranking DROP COLUMN tipo");
        echo "Coluna 'tipo' removida de 'campanhas_ranking'.<br>";
    } catch (PDOException $e) {}

    // Banner opcional exibido no topo da tela pública de Ranking (ranking.php). Sem ele, a
    // tela cai no logo geral do sistema, como já fazia antes.
    try {
        $pdo->exec("ALTER TABLE campanhas_ranking ADD COLUMN imagem_banner VARCHAR(255) DEFAULT NULL AFTER subtitulo");
        echo "Coluna 'imagem_banner' adicionada em 'campanhas_ranking'.<br>";
    } catch (PDOException $e) {}

    try {
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN apelido_publico VARCHAR(40) DEFAULT NULL AFTER nome");
        echo "Coluna 'apelido_publico' adicionada em 'usuarios'.<br>";
    } catch (PDOException $e) {}

    try {
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN foto_perfil VARCHAR(255) DEFAULT NULL AFTER apelido_publico");
        echo "Coluna 'foto_perfil' adicionada em 'usuarios'.<br>";
    } catch (PDOException $e) {}

    try {
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN cor_destaque CHAR(7) DEFAULT NULL AFTER foto_perfil");
        echo "Coluna 'cor_destaque' adicionada em 'usuarios'.<br>";
    } catch (PDOException $e) {}

    // Login com Google: guarda o "sub" (ID único e permanente do Google pra aquela conta) e
    // usa pra achar o usuário depois -- e-mail sozinho não serve como chave de identidade
    // permanente porque a pessoa pode trocar o e-mail da conta Google. NULL pra quem nunca
    // usou Google, e UNIQUE pra impedir duas contas nossas ligadas ao mesmo Google.
    try {
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN google_id VARCHAR(255) DEFAULT NULL AFTER email");
        echo "Coluna 'google_id' adicionada em 'usuarios'.<br>";
    } catch (PDOException $e) {}
    try {
        $pdo->exec("ALTER TABLE usuarios ADD UNIQUE KEY idx_usuarios_google_id (google_id)");
        echo "Índice único de 'google_id' adicionado em 'usuarios'.<br>";
    } catch (PDOException $e) {}

    try { $pdo->exec("ALTER TABLE vendas ADD INDEX idx_vendas_ranking (status, criado_em, bot_id)"); } catch (PDOException $e) {}

    // Cache pré-calculado do dashboard de admin (métricas por hora) — ver cron/cron_metricas_admin.php
    // (mantém as últimas 48h em dia) e admin/dashboard.php (só lê daqui pra "todos os bots").
    // Mesmo raciocínio de ranking_cache: nunca agregar 'vendas' inteira ao vivo numa página.
    $sql_metricas_horarias = "
        CREATE TABLE IF NOT EXISTS metricas_horarias_admin (
            data DATE NOT NULL,
            hora TINYINT UNSIGNED NOT NULL,
            faturamento DECIMAL(14,2) NOT NULL DEFAULT 0,
            comissao DECIMAL(14,2) NOT NULL DEFAULT 0,
            quantidade INT NOT NULL DEFAULT 0,
            atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (data, hora)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $metricas_admin_existia = (bool) $pdo->query("SHOW TABLES LIKE 'metricas_horarias_admin'")->fetchColumn();
    $pdo->exec($sql_metricas_horarias);
    echo "Tabela 'metricas_horarias_admin' OK.<br>";

    // Preenchimento único do histórico existente — daqui em diante o cron cuida só das
    // últimas 48h a cada execução (vendas pagas nunca mudam de valor depois de confirmadas,
    // não existe fluxo de estorno neste projeto — ver anotacoes/OBSERVACOES.md).
    // "Único" de verdade (26/10/2026): só quando a tabela acaba de ser criada. Antes refazia o histórico inteiro a cada "Atualizar Banco" (lia todas as
    // vendas; na VPS, 1,5 milhão). Para recalcular um banco que já tem a tabela: php cron/cron_metricas_admin.php --completo.
    if (!$metricas_admin_existia) {
        execComRetryDeColisao($pdo, "
            INSERT INTO metricas_horarias_admin (data, hora, faturamento, comissao, quantidade, atualizado_em)
            SELECT DATE(v.criado_em), HOUR(v.criado_em), SUM(v.valor), SUM(v.comissao_admin), COUNT(*), NOW()
            FROM vendas v
            WHERE v.status = 'pago'
            GROUP BY DATE(v.criado_em), HOUR(v.criado_em)
            ON DUPLICATE KEY UPDATE
                faturamento = VALUES(faturamento),
                comissao = VALUES(comissao),
                quantidade = VALUES(quantidade),
                atualizado_em = VALUES(atualizado_em)
        ");
        echo "Histórico de 'metricas_horarias_admin' preenchido a partir de 'vendas'.<br>";
    }

    // Suporta ORDER BY criado_em DESC em listagens paginadas (admin/transacoes.php,
    // leads.php) sem filesort mesmo com milhões de linhas -- ver
    // anotacoes/OBSERVACOES.md.
    try { $pdo->exec("ALTER TABLE vendas ADD INDEX idx_vendas_criado_em (criado_em DESC)"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE leads ADD INDEX idx_leads_bot_criado_em (bot_id, criado_em DESC)"); } catch (PDOException $e) {}

    // leads.pagou (08/10/2026): "esse lead já pagou?" guardado no próprio lead, com índice (bot, pagou, data). Antes os filtros
    // "Pagou/Não pagou" de Leads e o público do remarketing procuravam venda por lead (EXISTS por linha): com 200 mil leads
    // levava 1 a 5 s e piora junto com o volume. Mantido por aposPagamentoConfirmado() (funcoes/operacao.php) nos 3 caminhos
    // que confirmam pagamento. O preenchimento do histórico roda uma vez, bot a bot (leads sem venda paga nem são tocados).
    $criou_pagou = !colunaExiste('leads', 'pagou');
    if ($criou_pagou) {
        try { $pdo->exec("ALTER TABLE leads ADD COLUMN pagou TINYINT(1) NOT NULL DEFAULT 0 AFTER origem_rastreio"); echo "Coluna 'pagou' adicionada em 'leads'.<br>"; } catch (PDOException $e) { echo "Falha ao criar leads.pagou: " . htmlspecialchars($e->getMessage()) . "<br>"; }
    }
    try { $pdo->exec("ALTER TABLE leads ADD INDEX idx_leads_bot_pagou_criado (bot_id, pagou, criado_em DESC)"); echo "Índice 'idx_leads_bot_pagou_criado' criado em 'leads'.<br>"; } catch (PDOException $e) {}
    if ($criou_pagou && colunaExiste('leads', 'pagou')) {
        @set_time_limit(0);
        $marcados = 0;
        foreach ($pdo->query("SELECT DISTINCT bot_id FROM vendas WHERE status = 'pago' AND bot_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $bot_id_hist) {
            $st = $pdo->prepare("UPDATE leads l SET l.pagou = 1 WHERE l.bot_id = ? AND l.pagou = 0 AND EXISTS (SELECT 1 FROM vendas v WHERE v.bot_id = l.bot_id AND v.id_telegram = l.id_telegram AND v.status = 'pago')");
            $st->execute([(int) $bot_id_hist]);
            $marcados += $st->rowCount();
        }
        echo "Histórico: $marcados lead(s) marcado(s) como pagantes.<br>";
    }

    // bots.token_invalido_em (08/10/2026): preenchido quando o Telegram recusa o token (revogado/trocado); o dono é avisado
    // no sino e o campo é limpo ao salvar um token novo.
    if (!colunaExiste('bots', 'token_invalido_em')) {
        try { $pdo->exec("ALTER TABLE bots ADD COLUMN token_invalido_em DATETIME NULL AFTER webhook_secret"); echo "Coluna 'token_invalido_em' adicionada em 'bots'.<br>"; } catch (PDOException $e) {}
    }

    // Cache pré-calculado do dashboard do cliente (index.php) -- mesmo raciocínio de
    // metricas_horarias_admin, só que por usuário (cada usuário só vê o próprio
    // faturamento, então não faz sentido guardar por bot também). Ver
    // cron/cron_metricas_admin.php (mantém em dia) e anotacoes/OBSERVACOES.md.
    // Um registro por /start (lead novo ou que voltou): base da contagem de "starts" do dashboard (antes contava só leads novos,
    // então vendas de quem já era lead apareciam com 0 starts). Na criação, cada lead existente vira 1 start na data em que nasceu.
    $starts_existia = (bool) $pdo->query("SHOW TABLES LIKE 'leads_starts'")->fetchColumn();
    $pdo->exec("CREATE TABLE IF NOT EXISTS leads_starts (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            lead_id INT NOT NULL,
            bot_id INT NOT NULL,
            criado_em DATETIME NOT NULL,
            INDEX idx_starts_bot_data (bot_id, criado_em),
            INDEX idx_starts_lead (lead_id, criado_em),
            FOREIGN KEY (bot_id) REFERENCES bots(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if (!$starts_existia) {
        $maior_lead = (int) $pdo->query("SELECT COALESCE(MAX(id), 0) FROM leads")->fetchColumn();
        for ($ini = 0; $ini < $maior_lead; $ini += 50000) {
            $pdo->exec("INSERT INTO leads_starts (lead_id, bot_id, criado_em) SELECT id, bot_id, criado_em FROM leads WHERE bot_id IS NOT NULL AND id > $ini AND id <= " . ($ini + 50000));
        }
        echo "Tabela 'leads_starts' criada e preenchida a partir de 'leads'.<br>";
    } else {
        echo "Tabela 'leads_starts' OK.<br>";
    }

    $sql_metricas_usuario = "
        CREATE TABLE IF NOT EXISTS metricas_horarias_usuario (
            id_usuario INT NOT NULL,
            data DATE NOT NULL,
            hora TINYINT UNSIGNED NOT NULL,
            valor_pago DECIMAL(14,2) NOT NULL DEFAULT 0,
            qtd_paga INT NOT NULL DEFAULT 0,
            qtd_gerada INT NOT NULL DEFAULT 0,
            qtd_leads INT NOT NULL DEFAULT 0,
            atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id_usuario, data, hora)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $metricas_usuario_existia = (bool) $pdo->query("SHOW TABLES LIKE 'metricas_horarias_usuario'")->fetchColumn();
    $pdo->exec($sql_metricas_usuario);
    echo "Tabela 'metricas_horarias_usuario' OK.<br>";

    // Preenchimento único do histórico (vendas pagas + geradas por hora/usuário). Só quando a tabela acaba de ser criada (26/10/2026): antes refazia tudo a
    // cada "Atualizar Banco" (lia todas as vendas e todos os starts; na VPS, 1,5 milhão e 7 milhões de linhas). Banco com a tabela já existente:
    // php cron/cron_metricas_admin.php --completo.
    if (!$metricas_usuario_existia) {
        execComRetryDeColisao($pdo, "
            INSERT INTO metricas_horarias_usuario (id_usuario, data, hora, valor_pago, qtd_paga, qtd_gerada, atualizado_em)
            SELECT b.id_usuario, DATE(v.criado_em), HOUR(v.criado_em),
                   SUM(CASE WHEN v.status = 'pago' THEN v.valor ELSE 0 END),
                   SUM(CASE WHEN v.status = 'pago' THEN 1 ELSE 0 END),
                   COUNT(*),
                   NOW()
            FROM vendas v
            JOIN bots b ON v.bot_id = b.id
            GROUP BY b.id_usuario, DATE(v.criado_em), HOUR(v.criado_em)
            ON DUPLICATE KEY UPDATE
                valor_pago = VALUES(valor_pago),
                qtd_paga = VALUES(qtd_paga),
                qtd_gerada = VALUES(qtd_gerada),
                atualizado_em = VALUES(atualizado_em)
        ");
        // Contagem de leads por hora/usuário -- soma em cima do que já foi inserido acima
        // (linhas de hora sem nenhuma venda ainda não existem, então usa INSERT...SELECT
        // com ON DUPLICATE pra somar só o campo de leads sem mexer nos outros).
        execComRetryDeColisao($pdo, "
            INSERT INTO metricas_horarias_usuario (id_usuario, data, hora, qtd_leads, atualizado_em)
            SELECT b.id_usuario, DATE(l.criado_em), HOUR(l.criado_em), COUNT(*), NOW()
            FROM leads_starts l
            JOIN bots b ON l.bot_id = b.id
            GROUP BY b.id_usuario, DATE(l.criado_em), HOUR(l.criado_em)
            ON DUPLICATE KEY UPDATE
                qtd_leads = VALUES(qtd_leads),
                atualizado_em = VALUES(atualizado_em)
        ");
        echo "Histórico de 'metricas_horarias_usuario' preenchido a partir de 'vendas'/'leads'.<br>";
    }

    // Navbar de stories (tipo Instagram/WhatsApp Status) -- feature isolada, não referenciada
    // por nenhuma tabela de venda/pagamento. Expiração de 24h é resolvida por filtro de
    // consulta (expira_em > NOW()) em todo SELECT, sem depender de cron pra "esconder" nada;
    // cron/cron_limpar_stories.php só faz limpeza best-effort de linhas/arquivos antigos.
    // Ver anotacoes/OBSERVACOES.md.
    $sql_stories = "
        CREATE TABLE IF NOT EXISTS stories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            id_usuario INT NOT NULL,
            tipo_midia ENUM('foto', 'video') NOT NULL,
            arquivo VARCHAR(255) NOT NULL,
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            expira_em DATETIME NOT NULL,
            FOREIGN KEY (id_usuario) REFERENCES usuarios(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sql_stories);
    // A barra de stories só lê linhas com expira_em > NOW() -- sem índice nessa coluna, isso
    // vira table scan conforme o histórico cresce (mesmo raciocínio de idx_atividades_tipo).
    try { $pdo->exec("ALTER TABLE stories ADD INDEX idx_stories_expira (expira_em)"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE stories ADD INDEX idx_stories_usuario (id_usuario)"); } catch (PDOException $e) {}
    echo "Tabela 'stories' OK.<br>";

    $sql_stories_visualizacoes = "
        CREATE TABLE IF NOT EXISTS stories_visualizacoes (
            story_id INT NOT NULL,
            id_usuario INT NOT NULL,
            visto_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (story_id, id_usuario),
            FOREIGN KEY (story_id) REFERENCES stories(id) ON DELETE CASCADE,
            FOREIGN KEY (id_usuario) REFERENCES usuarios(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sql_stories_visualizacoes);
    echo "Tabela 'stories_visualizacoes' OK.<br>";

    // Página Comunidade: links institucionais da plataforma (grupos, canais, redes).
    // Quem edita é o admin (admin/comunidade.php); o usuário só vê os ativos em comunidade.php.
    // membros_max = 0 significa sem limite de vagas — o card não mostra contador nem LOTADO.
    // Ver anotacoes/OBSERVACOES.md.
    $sql_comunidade = "
        CREATE TABLE IF NOT EXISTS comunidade_links (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sql_comunidade);
    echo "Tabela 'comunidade_links' OK.<br>";

    // Webhooks de saída do usuário (lead, PIX gerado, pagamento aprovado).
    // bot_id NULL = todos os bots daquele usuário. Secret fica legível porque
    // o envio precisa dele para assinar o corpo. Ver funcoes/webhooks.php.
    $sql_webhooks = "
        CREATE TABLE IF NOT EXISTS webhooks (
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
            FOREIGN KEY (bot_id) REFERENCES bots(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sql_webhooks);
    try { $pdo->exec("ALTER TABLE webhooks ADD INDEX idx_webhooks_usuario_ativo (id_usuario, ativo)"); } catch (PDOException $e) {}
    echo "Tabela 'webhooks' OK.<br>";

    $sql_webhooks_envios = "
        CREATE TABLE IF NOT EXISTS webhooks_envios (
            id INT AUTO_INCREMENT PRIMARY KEY,
            webhook_id INT NOT NULL,
            evento VARCHAR(40) NOT NULL,
            http_status SMALLINT DEFAULT NULL,
            sucesso TINYINT(1) NOT NULL DEFAULT 0,
            erro VARCHAR(180) DEFAULT NULL,
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (webhook_id) REFERENCES webhooks(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sql_webhooks_envios);
    try { $pdo->exec("ALTER TABLE webhooks_envios ADD INDEX idx_webhooks_envios_webhook (webhook_id, criado_em)"); } catch (PDOException $e) {}
    echo "Tabela 'webhooks_envios' OK.<br>";

    $pdo->exec("CREATE TABLE IF NOT EXISTS mensagens_agendadas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        bot_id INT NOT NULL,
        id_telegram VARCHAR(50) NOT NULL,
        message_id BIGINT NOT NULL,
        apagar_em DATETIME NOT NULL,
        apagada TINYINT(1) NOT NULL DEFAULT 0,
        depois_json TEXT DEFAULT NULL,
        INDEX idx_agendadas_pendentes (apagada, apagar_em),
        FOREIGN KEY (bot_id) REFERENCES bots(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    echo "Tabela 'mensagens_agendadas' OK.<br>";

    $pdo->exec("CREATE TABLE IF NOT EXISTS previas_vistas (
        bot_id INT NOT NULL,
        id_telegram VARCHAR(50) NOT NULL,
        visto_em DATETIME NOT NULL,
        PRIMARY KEY (bot_id, id_telegram),
        FOREIGN KEY (bot_id) REFERENCES bots(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    echo "Tabela 'previas_vistas' OK.<br>";
    // Downsell com temporizador (Fluxo Guiado): uma linha por Pix gerado que ainda pode receber a oferta se o lead não pagar.
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
    echo "Tabela 'downsell_agenda' OK.<br>";
    // Sequência de Order Bumps (Fluxo Guiado): uma linha por Pix gerado; `enviados` é a posição do próximo bump a mandar se o lead não pagar.
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
    echo "Tabela 'order_bump_agenda' OK.<br>";
    $pdo->exec("CREATE TABLE IF NOT EXISTS avisos_assinatura_enviados (
        id INT AUTO_INCREMENT PRIMARY KEY,
        membro_id INT NOT NULL,
        marco VARCHAR(40) NOT NULL,
        enviado_em DATETIME NOT NULL,
        UNIQUE KEY unico_aviso (membro_id, marco),
        INDEX idx_avisos_enviado_em (enviado_em),
        FOREIGN KEY (membro_id) REFERENCES membros_grupos(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    echo "Tabela 'avisos_assinatura_enviados' OK.<br>";
    // Fluxo de origem de cada lead/venda: as telas do Fluxo Guiado (Resumo, Conversões) contam por fluxo,
    // não por bot. Tabelas grandes: coluna nullable no fim (INSTANT) e índice online, sem travar escrita.
    foreach (['leads' => 'idx_leads_fluxo', 'vendas' => 'idx_vendas_fluxo'] as $tabela => $indice) {
        try { $pdo->exec("ALTER TABLE $tabela ADD COLUMN id_fluxo INT DEFAULT NULL, ALGORITHM=INSTANT"); echo "Coluna 'id_fluxo' adicionada em '$tabela'.<br>"; } catch (PDOException $e) {}
        try { $pdo->exec("ALTER TABLE $tabela ADD INDEX $indice (id_fluxo, criado_em), ALGORITHM=INPLACE, LOCK=NONE"); echo "Índice '$indice' criado.<br>"; } catch (PDOException $e) {}
    }
    // E-mail: verificação (coluna + tokens) e fila de envio.
    $coluna_nova = false;
    try { $pdo->exec("ALTER TABLE usuarios ADD COLUMN email_verificado_em DATETIME DEFAULT NULL AFTER email"); $coluna_nova = true; echo "Coluna 'email_verificado_em' adicionada em 'usuarios'.<br>"; } catch (PDOException $e) {}
    if ($coluna_nova) {
        // Só na criação da coluna: quem já tinha conta antes do recurso nasce verificado (não vira "pendente" do nada).
        $pdo->exec("UPDATE usuarios SET email_verificado_em = criado_em WHERE email_verificado_em IS NULL");
        echo "Usuários existentes marcados como verificados.<br>";
    }
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
    echo "Tabela 'tokens_email' OK.<br>";
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
    echo "Tabela 'fila_emails' OK.<br>";
    // Histórico de TODA tentativa de envio (enviado/falhou + resposta do servidor SMTP): tela Admin → E-mails. Sem o corpo do e-mail. Guarda 90 dias.
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
    echo "Tabela 'email_envios' OK.<br>";
    // Limite de taxa genérico (recuperação de senha, reenvio de confirmação, cadastro): janela fixa por chave. Ver funcoes/limite_taxa.php.
    $pdo->exec("CREATE TABLE IF NOT EXISTS limites_taxa (
        chave VARCHAR(120) NOT NULL PRIMARY KEY,
        janela_inicio DATETIME NOT NULL,
        contagem INT UNSIGNED NOT NULL DEFAULT 0,
        INDEX idx_limites_janela (janela_inicio)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    echo "Tabela 'limites_taxa' OK.<br>";
    // Editor Visual: esperas do bloco Delay (retomadas pela cron) e ofertas aceitas (aplicadas no próximo Pix).
    $pdo->exec("CREATE TABLE IF NOT EXISTS fluxo_continuacoes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        bot_id INT NOT NULL,
        id_telegram VARCHAR(50) NOT NULL,
        id_fluxo INT NOT NULL,
        id_operador_destino VARCHAR(60) NOT NULL,
        executar_em DATETIME NOT NULL,
        status ENUM('pendente','processando','feito','falhou','cancelado') NOT NULL DEFAULT 'pendente',
        criado_em DATETIME NOT NULL,
        atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_continuacoes_pendentes (status, executar_em),
        INDEX idx_continuacoes_lead (bot_id, id_telegram, status),
        FOREIGN KEY (bot_id) REFERENCES bots(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    echo "Tabela 'fluxo_continuacoes' OK.<br>";
    $pdo->exec("CREATE TABLE IF NOT EXISTS fluxo_ofertas_aceitas (
        bot_id INT NOT NULL,
        id_telegram VARCHAR(50) NOT NULL,
        tipo ENUM('upsell','downsell','order_bump') NOT NULL,
        desconto_percentual DECIMAL(5,2) NOT NULL DEFAULT 0,
        valor_extra DECIMAL(10,2) NOT NULL DEFAULT 0,
        nome VARCHAR(100) DEFAULT NULL,
        criado_em DATETIME NOT NULL,
        PRIMARY KEY (bot_id, id_telegram, tipo),
        FOREIGN KEY (bot_id) REFERENCES bots(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    echo "Tabela 'fluxo_ofertas_aceitas' OK.<br>";

    // Histórico do chat de cada lead (botão "Abrir Chat" em Leads; funcoes/chat.php). Escrita em lote, leitura pelo índice da conversa, retenção de 60 dias.
    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_mensagens (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        bot_id INT NOT NULL,
        id_telegram VARCHAR(50) NOT NULL,
        direcao TINYINT UNSIGNED NOT NULL DEFAULT 0,
        origem TINYINT UNSIGNED NOT NULL DEFAULT 0,
        tipo VARCHAR(12) NOT NULL DEFAULT 'texto',
        texto VARCHAR(1000) NOT NULL DEFAULT '',
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_chat_conversa (bot_id, id_telegram, id),
        INDEX idx_chat_criado (criado_em),
        FOREIGN KEY (bot_id) REFERENCES bots(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    echo "Tabela 'chat_mensagens' OK.<br>";

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
    echo "Tabelas de avisos push (push_inscricoes, push_fila, push_metricas_hora) OK.<br>";
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
    echo "<h2 style='color:var(--da)'>Erro fatal ao atualizar banco: " . htmlspecialchars($e->getMessage()) . "</h2>";
}

exibirRelatorioDebug('Atualizar Banco', ob_get_clean(), '../');
