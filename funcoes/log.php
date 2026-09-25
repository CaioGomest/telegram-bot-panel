<?php
declare(strict_types=1);

date_default_timezone_set('America/Sao_Paulo');

require_once __DIR__ . '/../conexao.php';

function registrarAtividade(?int $id_usuario, string $tipo, string $titulo, string $descricao, string $icone = 'default'): bool {
    global $pdo;
    try {
        $criado_em = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare("INSERT INTO atividades (id_usuario, tipo, titulo, descricao, icone, criado_em) VALUES (?, ?, ?, ?, ?, ?)");
        return $stmt->execute([$id_usuario, $tipo, $titulo, $descricao, $icone, $criado_em]);
    } catch (PDOException $e) {
        error_log("Erro ao registrar log: " . $e->getMessage());
        return false;
    }
}

function listarAtividades(array $filtros = [], int $limite = 20, int $offset = 0): array {
    global $pdo;
    
    $sql = "SELECT a.*, u.nome as nome_usuario 
            FROM atividades a 
            LEFT JOIN usuarios u ON a.id_usuario = u.id 
            WHERE 1=1";
    $params = [];

    if (!empty($filtros['id_usuario'])) {
        $sql .= " AND a.id_usuario = ?";
        $params[] = $filtros['id_usuario'];
    }

    if (!empty($filtros['tipo'])) {
        $sql .= " AND a.tipo = ?";
        $params[] = $filtros['tipo'];
    }

    if (!empty($filtros['tipos_in'])) {
        $in_tipos = $filtros['tipos_in'];
        if (is_array($in_tipos) && !empty($in_tipos)) {
            $placeholders = implode(',', array_fill(0, count($in_tipos), '?'));
            $sql .= " AND a.tipo IN ($placeholders)";
            $params = array_merge($params, $in_tipos);
        }
    }

    if (!empty($filtros['excluir_tipos'])) {
        $excluidos = $filtros['excluir_tipos'];
        if (is_array($excluidos) && !empty($excluidos)) {
            $placeholders = implode(',', array_fill(0, count($excluidos), '?'));
            $sql .= " AND a.tipo NOT IN ($placeholders)";
            $params = array_merge($params, $excluidos);
        }
    }

    if (!empty($filtros['apenas_nao_lidas'])) {
        $sql .= " AND a.lido_em IS NULL";
    }

    $sql .= " ORDER BY a.id DESC LIMIT " . (int)$limite . " OFFSET " . (int)$offset;

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Erro ao listar logs: " . $e->getMessage());
        return [];
    }
}

function contarAtividades(array $filtros = []): int {
    global $pdo;
    
    $sql = "SELECT COUNT(*) FROM atividades a WHERE 1=1";
    $params = [];

    if (!empty($filtros['id_usuario'])) {
        $sql .= " AND a.id_usuario = ?";
        $params[] = $filtros['id_usuario'];
    }

    if (!empty($filtros['tipo'])) {
        $sql .= " AND a.tipo = ?";
        $params[] = $filtros['tipo'];
    }

    if (!empty($filtros['tipos_in'])) {
        $in_tipos = $filtros['tipos_in'];
        if (is_array($in_tipos) && !empty($in_tipos)) {
            $placeholders = implode(',', array_fill(0, count($in_tipos), '?'));
            $sql .= " AND a.tipo IN ($placeholders)";
            $params = array_merge($params, $in_tipos);
        }
    }

    if (!empty($filtros['excluir_tipos'])) {
        $excluidos = $filtros['excluir_tipos'];
        if (is_array($excluidos) && !empty($excluidos)) {
            $placeholders = implode(',', array_fill(0, count($excluidos), '?'));
            $sql .= " AND a.tipo NOT IN ($placeholders)";
            $params = array_merge($params, $excluidos);
        }
    }

    if (!empty($filtros['apenas_nao_lidas'])) {
        $sql .= " AND a.lido_em IS NULL";
    }

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Erro ao contar logs: " . $e->getMessage());
        return 0;
    }
}

/** Tipos que o sino do header mostra — o mesmo recorte do dashboard do usuário. */
function tiposNotificacao(): array {
    return ['venda', 'pix_gerado', 'lead'];
}

function tempoRelativoAtividade(string $criado_em): string {
    $ts = strtotime($criado_em);
    if ($ts === false) {
        return '';
    }
    $diff = abs(time() - $ts);
    if ($diff < 60) {
        return 'agora';
    }
    if ($diff < 3600) {
        return (string) ((int) floor($diff / 60)) . 'm';
    }
    if ($diff < 86400) {
        return (string) ((int) floor($diff / 3600)) . 'h';
    }
    return (string) ((int) floor($diff / 86400)) . 'd';
}

function marcarAtividadesLidas(int $id_usuario, ?int $id = null): int {
    global $pdo;

    $tipos = tiposNotificacao();
    $placeholders = implode(',', array_fill(0, count($tipos), '?'));
    $sql = "UPDATE atividades SET lido_em = NOW()
            WHERE id_usuario = ?
              AND tipo IN ($placeholders)
              AND lido_em IS NULL";
    $params = array_merge([$id_usuario], $tipos);

    if ($id !== null && $id > 0) {
        $sql .= " AND id = ?";
        $params[] = $id;
    }

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    } catch (PDOException $e) {
        error_log("Erro ao marcar notificações: " . $e->getMessage());
        return 0;
    }
}
