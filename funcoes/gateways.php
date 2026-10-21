<?php
declare(strict_types=1);

require_once __DIR__ . '/../conexao.php';
require_once __DIR__ . '/log.php';
require_once __DIR__ . '/criptografia.php';
require_once __DIR__ . '/split_validacao.php';

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
    // Não confia em quem chama: o que o split pode valer é decidido em validarSplitGateway() (funcoes/split_validacao.php). Valor fora da regra
    // não é "consertado" nem cortado — não grava e devolve false.
    [$split, $erro_split] = validarSplitGateway(number_format($taxa_split, 2, '.', ''), $tipo_split, $chave_pix_split);
    if ($split === null) {
        error_log("Split recusado para o gateway ID $gateway_id: $erro_split");
        return false;
    }
    try {
        $stmt = $pdo->prepare("UPDATE gateways SET taxa_split = ?, tipo_split = ?, chave_pix_split = ? WHERE id = ?");
        $sucesso = $stmt->execute([$split['taxa'], $split['tipo'], $split['chave'], $gateway_id]);
        if ($sucesso && isset($_SESSION['usuario_id'])) {
            $descricao_taxa = $split['tipo'] === 'fixo' ? 'R$ ' . number_format($split['taxa'], 2, ',', '.') . ' fixo' : $split['taxa'] . '%';
            registrarAtividade((int)$_SESSION['usuario_id'], 'admin', 'Gateway Admin', "Atualizou split padrão do gateway ID $gateway_id ($descricao_taxa)");
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

/**
 * Limites da OmegaPayments medidos em 06/10/2026 (não estão na documentação pública e podem mudar):
 * a API recusa cobrança abaixo de R$ 1,50, cobra R$ 0,70 por Pix e recusa a cobrança se split + tarifa
 * passar do valor da venda. Usado pra avisar usuário/admin e, futuramente, validar preço de plano/pack.
 */
const OMEGAPAYMENTS_PRECO_MINIMO_API = 1.50;
const OMEGAPAYMENTS_TARIFA_PIX = 0.70;

/** Menor valor de venda aceito com o split informado (linha de getGatewaySplit) — sem split, só o mínimo da API. */
function precoMinimoOmegaPayments(?array $split): float {
    $minimo = OMEGAPAYMENTS_PRECO_MINIMO_API;
    $taxa = (float)($split['taxa_split'] ?? 0);
    if ($taxa <= 0) {
        return $minimo;
    }
    if (($split['tipo_split'] ?? 'percentual') === 'fixo') {
        return max($minimo, ceil(($taxa + OMEGAPAYMENTS_TARIFA_PIX) * 100) / 100);
    }
    if ($taxa >= 100) {
        return PHP_FLOAT_MAX;
    }
    return max($minimo, ceil(OMEGAPAYMENTS_TARIFA_PIX / (1 - $taxa / 100) * 100) / 100);
}

/**
 * Confere se todo preço vendável do Fluxo Guiado (planos, packs e planos de upsell/downsell já com desconto)
 * é aceito pelo gateway com o split atual. Retorna a mensagem de erro (pronta pra mostrar) ou null se está tudo certo.
 */
/**
 * Mesma regra do Guiado para o Editor Visual: o valor de um bloco PIX que a OmegaPayments recusaria com a comissão atual não pode ser salvo
 * (antes só falhava na hora em que um cliente tentava pagar). Valor 0 não entra aqui: é bloco ainda sem preço, não preço baixo demais.
 */
function validarPrecosFluxoVisual(array $dados, float $minimo): ?string {
    $reais = static fn(float $v): string => 'R$ ' . number_format($v, 2, ',', '.');
    $problemas = [];
    foreach ((array)($dados['operators'] ?? []) as $operador) {
        $p = is_array($operador) ? ($operador['properties'] ?? []) : [];
        if (($p['type'] ?? '') !== 'pix') {
            continue;
        }
        $valor = (float)($p['valor'] ?? 0);
        if ($valor > 0 && $valor < $minimo) {
            $problemas[] = 'o bloco PIX "' . trim((string)($p['title'] ?? 'PIX')) . '" (' . $reais($valor) . ')';
        }
    }
    if (!$problemas) {
        return null;
    }
    // Só o valor: o dono do fluxo não precisa saber da comissão da plataforma nem da lista do que está abaixo.
    return 'Para a Omega, o valor mínimo é ' . $reais($minimo) . '.';
}

function validarPrecosFluxoGuiado(array $dados, float $minimo): ?string {
    $reais = static fn(float $v): string => 'R$ ' . number_format($v, 2, ',', '.');
    $problemas = [];
    $planos_por_id = [];
    foreach ((array)($dados['planos'] ?? []) as $p) {
        $valor = (float)($p['valor'] ?? 0);
        $planos_por_id[(string)($p['id'] ?? '')] = $p;
        if ($valor > 0 && $valor < $minimo) {
            $problemas[] = 'o plano "' . trim((string)($p['nome'] ?? 'sem nome')) . '" (' . $reais($valor) . ')';
        }
    }
    $packs = $dados['packs'] ?? [];
    if (!empty($packs['ativo'])) {
        foreach ((array)($packs['itens'] ?? []) as $item) {
            $preco = (float)($item['preco'] ?? 0);
            if (($item['ativo'] ?? true) !== false && $preco > 0 && $preco < $minimo) {
                $problemas[] = 'o pack "' . trim((string)($item['nome'] ?? 'sem nome')) . '" (' . $reais($preco) . ')';
            }
        }
    }
    $derivados = [];
    foreach (['upsell' => 'Upsell', 'downsell' => 'Downsell'] as $tipo => $rotulo) {
        $oferta = $dados['ofertas'][$tipo] ?? [];
        $pior = null;
        $desc_pior = 0.0;
        // Downsell novo: vários planos (planos_oferecidos); Upsell e Downsell antigo: um (id_plano_destino).
        $ids_destino = ($tipo === 'downsell' && is_array($oferta['planos_oferecidos'] ?? null)) ? $oferta['planos_oferecidos'] : [$oferta['id_plano_destino'] ?? ''];
        foreach ($ids_destino as $id_destino) {
            $destino = $planos_por_id[(string)$id_destino] ?? null;
            if (!empty($oferta['ativo']) && $destino) {
                $desconto = max(0.0, min(100.0, (float)($oferta['desconto_percentual'] ?? 0)));
                $final = round((float)($destino['valor'] ?? 0) * (1 - $desconto / 100), 2);
                if ($final > 0 && $final < $minimo) {
                    $problemas[] = 'o ' . $rotulo . ' (plano "' . trim((string)($destino['nome'] ?? '')) . '" com ' . rtrim(rtrim(number_format($desconto, 2, ',', ''), '0'), ',') . '% sai por ' . $reais($final) . ')';
                    // Só o desconto é o culpado (o plano sozinho passa): é o único caso em que os valores digitados parecem certos e a frase curta confunde.
                    if ($desconto > 0 && (float)($destino['valor'] ?? 0) >= $minimo && ($pior === null || $final < $pior)) {
                        $pior = $final;
                        $desc_pior = $desconto;
                    }
                }
            }
        }
        if ($pior !== null) {
            $derivados[] = 'O ' . $rotulo . ', com ' . rtrim(rtrim(number_format($desc_pior, 2, ',', ''), '0'), ',') . '% de desconto, sai por ' . $reais($pior) . '.';
        }
    }
    if (!$problemas) {
        return null;
    }
    // Só o valor (sem a comissão da plataforma nem a lista do que está abaixo); a frase extra só aparece quando o culpado é o desconto de uma oferta.
    return 'Para a Omega, o valor mínimo é ' . $reais($minimo) . '.' . ($derivados ? ' ' . implode(' ', $derivados) : '');
}
