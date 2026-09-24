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

function getUserSplits(int $user_id, string $gateway_nome): array {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM usuarios_splits WHERE id_usuario = ? AND gateway_nome = ? ORDER BY ordem, id");
    $stmt->execute([$user_id, $gateway_nome]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * A InfoPago usa credenciais únicas do admin (compartilhadas por toda a plataforma) — usuários comuns
 * só ligam/desligam o gateway, não configuram client_id/secret/certificado próprios.
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
    $linha = $stmt->fetch(PDO::FETCH_ASSOC);
    return $linha ? decifrarCamposGateway($linha) : null;
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
    $row = $row ? decifrarCamposGateway($row) : null;

    if ($gateway_nome === 'infopago') {
        $credenciais_admin = getInfopagoCredenciaisAdmin();
        if ($credenciais_admin) {
            // Mantém o "ativo"/"prioridade" do próprio usuário (se existir uma linha), mas usa
            // sempre as credenciais compartilhadas do admin para autenticação na API.
            $row = array_merge($credenciais_admin, [
                'ativo' => $row['ativo'] ?? 0,
                'prioridade' => $row['prioridade'] ?? 100,
                'gateway_nome' => 'infopago',
            ]);
        }
    }

    return $row;
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
    $credenciais_admin_infopago = null;
    foreach ($gateways as &$gw) {
        $gw = decifrarCamposGateway($gw);
        $gw['user_ativo'] = (bool)($gw['user_ativo'] ?? 0);
        $gw['prioridade'] = (int)($gw['prioridade'] ?? 100);

        if ($gw['gateway_nome'] === 'infopago') {
            $credenciais_admin_infopago ??= getInfopagoCredenciaisAdmin();
            if ($credenciais_admin_infopago) {
                $gw['client_id'] = $credenciais_admin_infopago['client_id'];
                $gw['client_secret'] = $credenciais_admin_infopago['client_secret'];
                $gw['certificado'] = $credenciais_admin_infopago['certificado'];
                $gw['cert_password'] = $credenciais_admin_infopago['cert_password'] ?? '';
                $gw['chave_pix'] = $credenciais_admin_infopago['chave_pix'];
                $gw['tipo_conta'] = $credenciais_admin_infopago['tipo_conta'] ?? 'pj';
            }
        }
    }

    return $gateways;
}

function getPrimaryUserGatewayConfig(int $user_id): ?array {
    $gateways = getUserGateways($user_id, true);
    return $gateways[0] ?? null;
}

function resolveGatewayProvider(string $gateway_nome, array $config): ?object {
    switch (strtolower($gateway_nome)) {
        case 'infopago':
            // Sem client_id/client_secret não dá pra autenticar — retorna null em vez de deixar
            // o construtor (tipagem estrita) estourar TypeError, que os chamadores não capturam.
            if (empty($config['client_id']) || empty($config['client_secret'])) {
                return null;
            }
            require_once __DIR__ . '/infopago_banco.php';
            return new InfopagoBanco($config['client_id'], $config['client_secret'], $config['certificado'] ?? '', true, $config['cert_password'] ?? '');
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

// Gateways sem provider implementado (ex-EFI, ex-PushinPay) continuam na tabela por causa do
// histórico de vendas, mas não devem aparecer como opção pra ativar/configurar.
function gatewaysSuportados(): array {
    return ['infopago', 'omegapayments'];
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

/**
 * Salva as credenciais de Cash-Out (API de Contas) da InfoPago para um usuário.
 * Usadas para simular split via transferência manual após o Pix cair, já que a API
 * de cobrança da InfoPago não tem split nativo (ver docs/infopago/01-api-referencia.md §5).
 */
function saveInfopagoCashoutConfig(int $user_id, int $gateway_id, string $cashout_client_id, string $cashout_client_secret, ?string $cashout_certificado, string $cashout_cert_password = ''): bool {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT id, cashout_certificado, cashout_cert_password FROM usuarios_gateways WHERE id_usuario = ? AND id_gateway = ?");
        $stmt->execute([$user_id, $gateway_id]);
        $exists = $stmt->fetch();

        if (!$exists) {
            return false; // credenciais de cobrança precisam existir primeiro
        }

        $certificado_final = $cashout_certificado ?: $exists['cashout_certificado'];
        $cert_password_final = $cashout_cert_password !== '' ? criptografarSegredo($cashout_cert_password) : $exists['cashout_cert_password'];
        // Mesma regra do cert_password logo acima: vazio mantém o que já estava gravado.
        $cashout_client_secret_cifrado = $cashout_client_secret !== ''
            ? criptografarSegredo($cashout_client_secret)
            : ($exists['cashout_client_secret'] ?? '');

        $stmt = $pdo->prepare("UPDATE usuarios_gateways SET cashout_client_id = ?, cashout_client_secret = ?, cashout_certificado = ?, cashout_cert_password = ? WHERE id = ?");
        if ($stmt->execute([$cashout_client_id, $cashout_client_secret_cifrado, $certificado_final, $cert_password_final, $exists['id']])) {
            registrarAtividade($user_id, 'sistema', 'Gateway Usuário', "Atualizou credenciais de Cash-Out (split) do gateway ID $gateway_id");
            return true;
        }
        return false;
    } catch (PDOException $e) {
        error_log("Erro ao salvar config cashout: " . $e->getMessage());
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
               COALESCE(ug.tipo_conta, 'pj') as tipo_conta,
               ug.cashout_client_id, ug.cashout_client_secret, ug.cashout_certificado
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

    $eh_admin_atual = isset($_SESSION['usuario_perfil']) && $_SESSION['usuario_perfil'] === 'admin';
    $credenciais_admin_infopago = null;

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
            'cashout_client_id' => $g['cashout_client_id'] ?? '',
            'cashout_client_secret' => $g['cashout_client_secret'] ?? '',
            'cashout_certificado' => $g['cashout_certificado'] ?? '',
        ];

        // InfoPago usa credenciais únicas do admin (compartilhadas) — usuário comum só liga/desliga.
        if ($g['nome'] === 'infopago' && !$eh_admin_atual) {
            $credenciais_admin_infopago ??= (getInfopagoCredenciaisAdmin() ?: []);
            $g['user_config']['client_id'] = $credenciais_admin_infopago['client_id'] ?? '';
            $g['user_config']['chave_pix'] = $credenciais_admin_infopago['chave_pix'] ?? '';
            $g['user_config']['gerenciado_pelo_admin'] = true;
        }

        $g['conectado'] = ($g['user_config']['ativo'] && !empty($g['user_config']['client_id']) && !empty($g['user_config']['chave_pix']));
    }

    return $gateways;
}

function contarGatewaysUsuario(): int {
    global $pdo;
    return (int)$pdo->query("SELECT COUNT(*) FROM gateways WHERE ativo = 1 AND nome IN ('" . implode("','", gatewaysSuportados()) . "')")->fetchColumn();
}
