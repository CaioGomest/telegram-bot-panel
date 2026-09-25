<?php
declare(strict_types=1);

require_once __DIR__ . '/../conexao.php';
require_once __DIR__ . '/log.php';
require_once __DIR__ . '/criptografia.php';

function getAdminGatewayConfig(string $nome): ?array {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM gateways WHERE nome = ? LIMIT 1");
    $stmt->execute([$nome]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

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
 * Split é uma regra única por gateway (não por usuário) -- todo mundo que usa o mesmo
 * gateway divide pro mesmo destino, configurado pelo admin direto na tela de gateways.
 * Retorna null quando não há split configurado (taxa zerada ou sem chave Pix destino),
 * pra quem chama simplesmente pular o split sem tratar caso especial.
 */
function getGatewaySplit(string $gateway_nome): ?array {
    global $pdo;
    $stmt = $pdo->prepare("SELECT taxa_split, tipo_split, chave_pix_split FROM gateways WHERE nome = ?");
    $stmt->execute([$gateway_nome]);
    $linha = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$linha || (float)$linha['taxa_split'] <= 0 || empty($linha['chave_pix_split'])) {
        return null;
    }
    return $linha;
}

function saveGatewaySplit(int $gateway_id, float $taxa_split, string $tipo_split, string $chave_pix_split): bool {
    global $pdo;
    $tipo_split = in_array($tipo_split, ['percentual', 'fixo'], true) ? $tipo_split : 'percentual';
    try {
        $stmt = $pdo->prepare("UPDATE gateways SET taxa_split = ?, tipo_split = ?, chave_pix_split = ? WHERE id = ?");
        $sucesso = $stmt->execute([$taxa_split, $tipo_split, $chave_pix_split, $gateway_id]);
        if ($sucesso && isset($_SESSION['usuario_id'])) {
            registrarAtividade((int)$_SESSION['usuario_id'], 'admin', 'Gateway Admin', "Atualizou split padrão do gateway ID $gateway_id ($taxa_split% $tipo_split)");
        }
        return $sucesso;
    } catch (PDOException $e) {
        error_log("Erro ao salvar split do gateway: " . $e->getMessage());
        return false;
    }
}

function getUserGatewayConfig(int $user_id, string $gateway_nome): ?array {
    global $pdo;
    $sql = "
        SELECT ug.*, g.nome as gateway_nome
        FROM usuarios_gateways ug
        JOIN gateways g ON ug.id_gateway = g.id
        WHERE ug.id_usuario = ? AND g.nome = ?
        LIMIT 1
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user_id, $gateway_nome]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? decifrarCamposGateway($row) : null;
}

function getUserGateways(int $user_id, bool $somente_ativos = true): array {
    global $pdo;
    $sql = "
        SELECT g.id as gateway_id, g.nome as gateway_nome, g.titulo, g.ativo as admin_ativo,
               ug.ativo AS user_ativo, ug.client_id, ug.client_secret, ug.certificado, ug.cert_password, ug.chave_pix, ug.prioridade,
               COALESCE(ug.tipo_conta, 'pj') as tipo_conta
        FROM gateways g
        LEFT JOIN usuarios_gateways ug ON ug.id_gateway = g.id AND ug.id_usuario = :user_id
        WHERE g.ativo = 1 AND g.nome IN ('" . implode("','", gatewaysSuportados()) . "')";

    if ($somente_ativos) {
        $sql .= " AND (ug.ativo = 1 OR ug.id IS NULL)";
    }

    $sql .= " ORDER BY COALESCE(ug.prioridade, 999), g.nome";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->execute();

    $gateways = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($gateways as &$gw) {
        $gw = decifrarCamposGateway($gw);
        $gw['user_ativo'] = (bool)($gw['user_ativo'] ?? 0);
        $gw['prioridade'] = (int)($gw['prioridade'] ?? 100);
    }

    return $gateways;
}

function getPrimaryUserGatewayConfig(int $user_id): ?array {
    $gateways = getUserGateways($user_id, true);
    return $gateways[0] ?? null;
}

function resolveGatewayProvider(string $gateway_nome, array $config): ?object {
    switch (strtolower($gateway_nome)) {
        case 'omegapayments':
            // Sem certificado/OAuth — só client_id/client_secret (headers x-public-key/x-secret-key).
            if (empty($config['client_id']) || empty($config['client_secret'])) {
                return null;
            }
            require_once __DIR__ . '/omegapayments_banco.php';
            return new OmegaPaymentsBanco($config['client_id'], $config['client_secret']);
        default:
            return null;
    }
}

// Gateways sem provider implementado (ex-EFI, ex-PushinPay, ex-InfoPago) continuam na tabela
// por causa do histórico de vendas, mas não devem aparecer como opção pra ativar/configurar.
function gatewaysSuportados(): array {
    return ['omegapayments'];
}

function saveUserGatewayConfig(int $user_id, int $gateway_id, string $client_id, string $client_secret, string $certificado, string $cert_password, string $chave_pix, bool $ativo, int $prioridade = 100, string $tipo_conta = 'pj'): bool {
    global $pdo;
    $tipo_conta = in_array($tipo_conta, ['pf', 'pj']) ? $tipo_conta : 'pj';

    $client_secret_cifrado = criptografarSegredo($client_secret);
    $cert_password_cifrado = criptografarSegredo($cert_password);
    $chave_pix_cifrada = criptografarSegredo($chave_pix);

    try {
        $stmt = $pdo->prepare("SELECT id FROM usuarios_gateways WHERE id_usuario = ? AND id_gateway = ?");
        $stmt->execute([$user_id, $gateway_id]);
        $exists = $stmt->fetch();

        if ($exists) {
            $sql = "UPDATE usuarios_gateways SET client_id = ?, client_secret = ?, certificado = ?, cert_password = ?, chave_pix = ?, ativo = ?, prioridade = ?, tipo_conta = ? WHERE id = ?";
            $params = [$client_id, $client_secret_cifrado, $certificado, $cert_password_cifrado, $chave_pix_cifrada, $ativo ? 1 : 0, $prioridade, $tipo_conta, $exists['id']];
        } else {
            $sql = "INSERT INTO usuarios_gateways (id_usuario, id_gateway, client_id, client_secret, certificado, cert_password, chave_pix, ativo, prioridade, tipo_conta) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $params = [$user_id, $gateway_id, $client_id, $client_secret_cifrado, $certificado, $cert_password_cifrado, $chave_pix_cifrada, $ativo ? 1 : 0, $prioridade, $tipo_conta];
        }

        $stmt_name = $pdo->prepare("SELECT nome FROM gateways WHERE id = ?");
        $stmt_name->execute([$gateway_id]);
        $gateway_name = $stmt_name->fetchColumn() ?: "Desconhecido";

        $stmt = $pdo->prepare($sql);
        if ($stmt->execute($params)) {
            registrarAtividade($user_id, 'sistema', 'Gateway Usuário', "Atualizou credenciais do gateway $gateway_name (ID $gateway_id)");
            return true;
        }
        return false;
    } catch (PDOException $e) {
        error_log("Erro ao salvar config user gateway: " . $e->getMessage());
        return false;
    }
}

function listarGatewaysAdmin(int $limite = 20, int $offset = 0): array {
    global $pdo;
    $sql = "SELECT * FROM gateways WHERE nome IN ('" . implode("','", gatewaysSuportados()) . "') ORDER BY nome LIMIT :limite OFFSET :offset";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function contarGatewaysAdmin(): int {
    global $pdo;
    return (int)$pdo->query("SELECT COUNT(*) FROM gateways WHERE nome IN ('" . implode("','", gatewaysSuportados()) . "')")->fetchColumn();
}

function listarGatewaysUsuario(int $user_id, int $limite = 20, int $offset = 0): array {
    global $pdo;
    $sql = "
        SELECT g.*, ug.ativo AS user_ativo, ug.client_id, ug.client_secret, ug.certificado, ug.chave_pix, ug.prioridade,
               COALESCE(ug.tipo_conta, 'pj') as tipo_conta
        FROM gateways g
        LEFT JOIN usuarios_gateways ug ON ug.id_gateway = g.id AND ug.id_usuario = :user_id
        WHERE g.ativo = 1 AND g.nome IN ('" . implode("','", gatewaysSuportados()) . "')
        ORDER BY COALESCE(ug.prioridade, 999), g.nome
        LIMIT :limite OFFSET :offset
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $gateways = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($gateways as &$g) {
        $g = decifrarCamposGateway($g);
        $g['user_config'] = [
            'client_id' => $g['client_id'] ?? '',
            'client_secret' => $g['client_secret'] ?? '',
            'certificado' => $g['certificado'] ?? '',
            'chave_pix' => $g['chave_pix'] ?? '',
            'ativo' => (bool)($g['user_ativo'] ?? 0),
            'prioridade' => (int)($g['prioridade'] ?? 100),
            'tipo_conta' => $g['tipo_conta'] ?? 'pj',
        ];

        // Chave Pix não entra aqui -- alguns gateways (OmegaPayments) nem usam esse campo,
        // o recebedor é definido pela própria credencial.
        $g['conectado'] = ($g['user_config']['ativo'] && !empty($g['user_config']['client_id']));
    }

    return $gateways;
}

function contarGatewaysUsuario(): int {
    global $pdo;
    return (int)$pdo->query("SELECT COUNT(*) FROM gateways WHERE ativo = 1 AND nome IN ('" . implode("','", gatewaysSuportados()) . "')")->fetchColumn();
}
