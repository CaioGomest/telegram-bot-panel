<?php
declare(strict_types=1);

// Garante fuso horário correto
date_default_timezone_set('America/Sao_Paulo');

require_once __DIR__ . '/../conexao.php';

/**
 * Registra uma nova atividade no sistema
 */
function registrarAtividade(?int $idUsuario, string $tipo, string $titulo, string $descricao, string $icone = 'default'): bool {
    global $pdo;
    try {
        // Define data de criação explicitamente com o fuso horário correto
        $criadoEm = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare("INSERT INTO atividades (id_usuario, tipo, titulo, descricao, icone, criado_em) VALUES (?, ?, ?, ?, ?, ?)");
        return $stmt->execute([$idUsuario, $tipo, $titulo, $descricao, $icone, $criadoEm]);
    } catch (PDOException $e) {
        error_log("Erro ao registrar log: " . $e->getMessage());
        return false;
    }
}

/**
 * Lista atividades do sistema com filtros opcionais
 */
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
        $inTipos = $filtros['tipos_in'];
        if (is_array($inTipos) && !empty($inTipos)) {
            $placeholders = implode(',', array_fill(0, count($inTipos), '?'));
            $sql .= " AND a.tipo IN ($placeholders)";
            $params = array_merge($params, $inTipos);
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

/**
 * Conta atividades do sistema com filtros opcionais
 */
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
        $inTipos = $filtros['tipos_in'];
        if (is_array($inTipos) && !empty($inTipos)) {
            $placeholders = implode(',', array_fill(0, count($inTipos), '?'));
            $sql .= " AND a.tipo IN ($placeholders)";
            $params = array_merge($params, $inTipos);
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

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Erro ao contar logs: " . $e->getMessage());
        return 0;
    }
}
