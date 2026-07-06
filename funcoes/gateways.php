<?php
declare(strict_types=1);

require_once __DIR__ . '/../conexao.php';
require_once __DIR__ . '/log.php';

/**
 * Obtém a configuração global (Admin) de um gateway
 */
function getAdminGatewayConfig(string $nome): ?array {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM gateways WHERE nome = ? LIMIT 1");
    $stmt->execute([$nome]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Salva a configuração global (Admin) de um gateway — apenas ativo/inativo
 */
function saveAdminGatewayConfig(int $id, bool $ativo): bool {
    global $pdo;
    try {
        $stmt = $pdo->prepare("UPDATE gateways SET ativo = ? WHERE id = ?");
        $sucesso = $stmt->execute([$ativo ? 1 : 0, $id]);
        if ($sucesso && isset($_SESSION['usuario_id'])) {
            registrarAtividade((int)$_SESSION['usuario_id'], 'admin', 'Gateway Admin', "Atualizou status gateway ID $id: " . ($ativo ? 'ativo' : 'inativo'));
        }
        return $sucesso;
    } catch (PDOException $e) {
        error_log("Erro ao salvar config admin gateway: " . $e->getMessage());
        return false;
    }
}

/**
 * Lista splits configurados para um usuário em um gateway específico
 */
function getUserSplits(int $userId, string $gatewayNome): array {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM usuarios_splits WHERE id_usuario = ? AND gateway_nome = ? ORDER BY ordem, id");
    $stmt->execute([$userId, $gatewayNome]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Obtém a configuração do usuário para um gateway específico
 */
/**
 * A InfoPago usa credenciais únicas do admin (compartilhadas por toda a plataforma) — usuários comuns
 * só ligam/desligam o gateway, não configuram client_id/secret/certificado próprios. Isso é diferente
 * do modelo da EFI/PushinPay (por usuário). Ver conversa/decisão de produto — sem doc formal ainda.
 */
function getInfopagoCredenciaisAdmin(): ?array {
    global $pdo;
    $sql = "
        SELECT ug.*
        FROM usuarios_gateways ug
        JOIN usuarios u ON ug.id_usuario = u.id
        JOIN gateways g ON ug.id_gateway = g.id
        WHERE g.nome = 'infopago' AND u.perfil = 'admin'
        ORDER BY ug.atualizado_em DESC
        LIMIT 1
    ";
    $stmt = $pdo->query($sql);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function getUserGatewayConfig(int $userId, string $gatewayNome): ?array {
    global $pdo;
    $sql = "
        SELECT ug.*, g.nome as gateway_nome
        FROM usuarios_gateways ug
        JOIN gateways g ON ug.id_gateway = g.id
        WHERE ug.id_usuario = ? AND g.nome = ?
        LIMIT 1
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId, $gatewayNome]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($gatewayNome === 'infopago') {
        $credenciaisAdmin = getInfopagoCredenciaisAdmin();
        if ($credenciaisAdmin) {
            // Mantém o "ativo"/"prioridade" do próprio usuário (se existir uma linha), mas usa
            // sempre as credenciais compartilhadas do admin para autenticação na API.
            $row = array_merge($credenciaisAdmin, [
                'ativo' => $row['ativo'] ?? 0,
                'prioridade' => $row['prioridade'] ?? 100,
                'gateway_nome' => 'infopago',
            ]);
        }
    }

    return $row;
}

function getUserGateways(int $userId, bool $somenteAtivos = true): array {
    global $pdo;
    $sql = "
        SELECT g.id as gateway_id, g.nome as gateway_nome, g.titulo, g.ativo as admin_ativo,
               ug.ativo AS user_ativo, ug.client_id, ug.client_secret, ug.certificado, ug.chave_pix, ug.prioridade,
               COALESCE(ug.tipo_conta, 'pj') as tipo_conta
        FROM gateways g
        LEFT JOIN usuarios_gateways ug ON ug.id_gateway = g.id AND ug.id_usuario = :user_id
        WHERE g.ativo = 1";

    if ($somenteAtivos) {
        $sql .= " AND (ug.ativo = 1 OR ug.id IS NULL)";
    }

    $sql .= " ORDER BY COALESCE(ug.prioridade, 999), g.nome";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $stmt->execute();

    $gateways = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $credenciaisAdminInfopago = null;
    foreach ($gateways as &$gw) {
        $gw['user_ativo'] = (bool)($gw['user_ativo'] ?? 0);
        $gw['prioridade'] = (int)($gw['prioridade'] ?? 100);

        if ($gw['gateway_nome'] === 'infopago') {
            $credenciaisAdminInfopago ??= getInfopagoCredenciaisAdmin();
            if ($credenciaisAdminInfopago) {
                $gw['client_id'] = $credenciaisAdminInfopago['client_id'];
                $gw['client_secret'] = $credenciaisAdminInfopago['client_secret'];
                $gw['certificado'] = $credenciaisAdminInfopago['certificado'];
                $gw['chave_pix'] = $credenciaisAdminInfopago['chave_pix'];
                $gw['tipo_conta'] = $credenciaisAdminInfopago['tipo_conta'] ?? 'pj';
            }
        }
    }

    return $gateways;
}

function getPrimaryUserGatewayConfig(int $userId): ?array {
    $gateways = getUserGateways($userId, true);
    return $gateways[0] ?? null;
}

function resolveGatewayProvider(string $gatewayNome, array $config): ?object {
    switch (strtolower($gatewayNome)) {
        case 'efi':
            require_once __DIR__ . '/efi_banco.php';
            return new EfiBanco($config['client_id'], $config['client_secret'], $config['certificado'] ?? '', true, $config['cert_password'] ?? '');
        case 'pushinpay':
            require_once __DIR__ . '/pushinpay_banco.php';
            return new PushinpayBanco($config['client_id'], $config['client_secret'], $config['certificado'] ?? '', true, $config['cert_password'] ?? '');
        case 'infopago':
            require_once __DIR__ . '/infopago_banco.php';
            return new InfopagoBanco($config['client_id'], $config['client_secret'], $config['certificado'] ?? '', true, $config['cert_password'] ?? '');
        default:
            return null;
    }
}

/**
 * Salva a configuração do usuário para um gateway
 */
function saveUserGatewayConfig(int $userId, int $gatewayId, string $clientId, string $clientSecret, string $certificado, string $certPassword, string $chavePix, bool $ativo, int $prioridade = 100, string $tipoConta = 'pj'): bool {
    global $pdo;
    $tipoConta = in_array($tipoConta, ['pf', 'pj']) ? $tipoConta : 'pj';
    try {
        // Verifica se já existe
        $stmt = $pdo->prepare("SELECT id FROM usuarios_gateways WHERE id_usuario = ? AND id_gateway = ?");
        $stmt->execute([$userId, $gatewayId]);
        $exists = $stmt->fetch();

        if ($exists) {
            $sql = "UPDATE usuarios_gateways SET client_id = ?, client_secret = ?, certificado = ?, cert_password = ?, chave_pix = ?, ativo = ?, prioridade = ?, tipo_conta = ? WHERE id = ?";
            $params = [$clientId, $clientSecret, $certificado, $certPassword, $chavePix, $ativo ? 1 : 0, $prioridade, $tipoConta, $exists['id']];
        } else {
            $sql = "INSERT INTO usuarios_gateways (id_usuario, id_gateway, client_id, client_secret, certificado, cert_password, chave_pix, ativo, prioridade, tipo_conta) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $params = [$userId, $gatewayId, $clientId, $clientSecret, $certificado, $certPassword, $chavePix, $ativo ? 1 : 0, $prioridade, $tipoConta];
        }

        // Busca nome do gateway para log
        $stmtName = $pdo->prepare("SELECT nome FROM gateways WHERE id = ?");
        $stmtName->execute([$gatewayId]);
        $gatewayName = $stmtName->fetchColumn() ?: "Desconhecido";

        $stmt = $pdo->prepare($sql);
        if ($stmt->execute($params)) {
            registrarAtividade($userId, 'sistema', 'Gateway Usuário', "Atualizou credenciais do gateway $gatewayName (ID $gatewayId)");
            return true;
        }
        return false;
    } catch (PDOException $e) {
        error_log("Erro ao salvar config user gateway: " . $e->getMessage());
        return false;
    }
}

/**
 * Salva as credenciais de Cash-Out (API de Contas) da InfoPago para um usuário.
 * Usadas para simular split via transferência manual após o Pix cair, já que a API
 * de cobrança da InfoPago não tem split nativo (ver docs/infopago/01-api-referencia.md §5).
 */
function saveInfopagoCashoutConfig(int $userId, int $gatewayId, string $cashoutClientId, string $cashoutClientSecret, ?string $cashoutCertificado): bool {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT id, cashout_certificado FROM usuarios_gateways WHERE id_usuario = ? AND id_gateway = ?");
        $stmt->execute([$userId, $gatewayId]);
        $exists = $stmt->fetch();

        if (!$exists) {
            return false; // credenciais de cobrança precisam existir primeiro
        }

        $certificadoFinal = $cashoutCertificado ?: $exists['cashout_certificado'];

        $stmt = $pdo->prepare("UPDATE usuarios_gateways SET cashout_client_id = ?, cashout_client_secret = ?, cashout_certificado = ? WHERE id = ?");
        if ($stmt->execute([$cashoutClientId, $cashoutClientSecret, $certificadoFinal, $exists['id']])) {
            registrarAtividade($userId, 'sistema', 'Gateway Usuário', "Atualizou credenciais de Cash-Out (split) do gateway ID $gatewayId");
            return true;
        }
        return false;
    } catch (PDOException $e) {
        error_log("Erro ao salvar config cashout: " . $e->getMessage());
        return false;
    }
}

/**
 * Lista todos os gateways disponíveis (para admin) com paginação
 */
function listarGatewaysAdmin(int $limite = 20, int $offset = 0): array {
    global $pdo;
    $sql = "SELECT * FROM gateways ORDER BY nome LIMIT :limite OFFSET :offset";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Conta total de gateways (admin)
 */
function contarGatewaysAdmin(): int {
    global $pdo;
    return (int)$pdo->query("SELECT COUNT(*) FROM gateways")->fetchColumn();
}

/**
 * Lista gateways ativos para o usuário (mostra status de conexão) com paginação
 */
function listarGatewaysUsuario(int $userId, int $limite = 20, int $offset = 0): array {
    global $pdo;
    $sql = "
        SELECT g.*, ug.ativo AS user_ativo, ug.client_id, ug.client_secret, ug.certificado, ug.chave_pix, ug.prioridade,
               COALESCE(ug.tipo_conta, 'pj') as tipo_conta,
               ug.cashout_client_id, ug.cashout_client_secret, ug.cashout_certificado
        FROM gateways g
        LEFT JOIN usuarios_gateways ug ON ug.id_gateway = g.id AND ug.id_usuario = :user_id
        WHERE g.ativo = 1
        ORDER BY COALESCE(ug.prioridade, 999), g.nome
        LIMIT :limite OFFSET :offset
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $gateways = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $ehAdminAtual = isset($_SESSION['usuario_perfil']) && $_SESSION['usuario_perfil'] === 'admin';
    $credenciaisAdminInfopago = null;

    foreach ($gateways as &$g) {
        $g['user_config'] = [
            'client_id' => $g['client_id'] ?? '',
            'client_secret' => $g['client_secret'] ?? '',
            'certificado' => $g['certificado'] ?? '',
            'chave_pix' => $g['chave_pix'] ?? '',
            'ativo' => (bool)($g['user_ativo'] ?? 0),
            'prioridade' => (int)($g['prioridade'] ?? 100),
            'tipo_conta' => $g['tipo_conta'] ?? 'pj',
            'cashout_client_id' => $g['cashout_client_id'] ?? '',
            'cashout_client_secret' => $g['cashout_client_secret'] ?? '',
            'cashout_certificado' => $g['cashout_certificado'] ?? '',
        ];

        // InfoPago usa credenciais únicas do admin (compartilhadas) — usuário comum só liga/desliga.
        if ($g['nome'] === 'infopago' && !$ehAdminAtual) {
            $credenciaisAdminInfopago ??= (getInfopagoCredenciaisAdmin() ?: []);
            $g['user_config']['client_id'] = $credenciaisAdminInfopago['client_id'] ?? '';
            $g['user_config']['chave_pix'] = $credenciaisAdminInfopago['chave_pix'] ?? '';
            $g['user_config']['gerenciado_pelo_admin'] = true;
        }

        if ($g['nome'] === 'pushinpay') {
            $g['conectado'] = ($g['user_config']['ativo'] && !empty($g['user_config']['client_id']));
        } else {
            $g['conectado'] = ($g['user_config']['ativo'] && !empty($g['user_config']['client_id']) && !empty($g['user_config']['chave_pix']));
        }
    }

    return $gateways;
}

/**
 * Conta total de gateways ativos (usuário)
 */
function contarGatewaysUsuario(): int {
    global $pdo;
    return (int)$pdo->query("SELECT COUNT(*) FROM gateways WHERE ativo = 1")->fetchColumn();
}
