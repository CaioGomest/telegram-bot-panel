<?php
declare(strict_types=1);

// Feature isolada (navbar de stories, tipo Instagram/WhatsApp Status). Nenhuma função
// deste arquivo é chamada por webhook.php/webhook_omegapayments.php ou
// funcoes/gateways.php -- se algo aqui falhar, venda/pagamento não é afetado.
// Ver anotacoes/pendente/plano-recursos-sharkbot.md.

const STORIES_LIMITE_FOTO_BYTES = 5 * 1024 * 1024;
const STORIES_LIMITE_VIDEO_BYTES = 20 * 1024 * 1024;
const STORIES_EXTENSOES_FOTO = ['jpg', 'jpeg', 'png', 'webp'];
const STORIES_EXTENSOES_VIDEO = ['mp4', 'webm'];
const STORIES_MAX_ATIVAS_POR_USUARIO = 20;
const STORIES_DIRETORIO_UPLOADS = __DIR__ . '/../uploads/stories';

/**
 * Monta os grupos (um por usuário com story ativo) pra renderizar a barra no topo do
 * dashboard. O próprio usuário logado sempre vem primeiro (like Instagram/WhatsApp),
 * depois quem tem story não visto, por último quem já viu tudo.
 */
function listarBarraStories(int $usuario_id_atual): array {
    global $pdo;

    try {
        // LIMIT é um teto de segurança contra crescimento patológico (não um limite de produto) --
        // a consulta já é enxuta (metadados apenas, indexada por expira_em) e roda só em index.php.
        // apelido_publico (nunca nome real) -- mesmo critério de funcoes/ranking.php::nomeExibicaoRanking.
        $coluna_foto = function_exists('colunaFotoPerfilDisponivel') && colunaFotoPerfilDisponivel()
            ? 'u.foto_perfil'
            : "'' AS foto_perfil";
        $stmt = $pdo->prepare("
            SELECT s.id, s.id_usuario, s.tipo_midia, s.arquivo, s.criado_em,
                   u.apelido_publico, $coluna_foto,
                   (sv.story_id IS NOT NULL) AS visto
            FROM stories s
            JOIN usuarios u ON u.id = s.id_usuario
            LEFT JOIN stories_visualizacoes sv ON sv.story_id = s.id AND sv.id_usuario = ?
            WHERE s.expira_em > NOW()
            ORDER BY s.criado_em ASC
            LIMIT 500
        ");
        $stmt->execute([$usuario_id_atual]);
        $linhas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // Tabela ainda não existe (atualiza_banco.php não rodou) -- barra fica vazia,
        // dashboard continua. Nunca derruba index.php por causa desta feature.
        error_log('[stories] listarBarraStories: ' . $e->getMessage());
        return [];
    }

    $agrupado = [];
    foreach ($linhas as $linha) {
        $id_dono = (int) $linha['id_usuario'];
        if (!isset($agrupado[$id_dono])) {
            $apelido = trim((string) ($linha['apelido_publico'] ?? ''));
            $foto = function_exists('nomeArquivoFotoPerfil')
                ? nomeArquivoFotoPerfil((string) ($linha['foto_perfil'] ?? ''))
                : '';
            $agrupado[$id_dono] = [
                'id_usuario' => $id_dono,
                'nome' => $apelido !== '' ? $apelido : ('Usuário #' . $id_dono),
                'foto' => $foto,
                'tem_nao_vista' => false,
                'stories' => [],
            ];
        }
        $visto = (bool) $linha['visto'];
        if (!$visto) {
            $agrupado[$id_dono]['tem_nao_vista'] = true;
        }
        $agrupado[$id_dono]['stories'][] = [
            'id' => (int) $linha['id'],
            'tipo_midia' => $linha['tipo_midia'],
            'arquivo' => basename((string) $linha['arquivo']),
            'criado_em' => $linha['criado_em'],
            'visto' => $visto,
        ];
    }

    $grupos = array_values($agrupado);
    usort($grupos, static function (array $a, array $b) use ($usuario_id_atual): int {
        $a_proprio = $a['id_usuario'] === $usuario_id_atual;
        $b_proprio = $b['id_usuario'] === $usuario_id_atual;
        if ($a_proprio !== $b_proprio) {
            return $a_proprio ? -1 : 1;
        }
        if ($a['tem_nao_vista'] !== $b['tem_nao_vista']) {
            return $a['tem_nao_vista'] ? -1 : 1;
        }
        return 0;
    });

    return $grupos;
}

function contarStoriesAtivasUsuario(int $usuario_id): int {
    global $pdo;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM stories WHERE id_usuario = ? AND expira_em > NOW()");
    $stmt->execute([$usuario_id]);
    return (int) $stmt->fetchColumn();
}

/**
 * Valida um item de $_FILES antes de gravar em disco. Extensão + tamanho + checagem real
 * de conteúdo (getimagesize pra foto, mime_content_type pra vídeo) -- mesmo padrão de
 * api.php::upload_imagem_fluxo/upload_video_fluxo, pra um .txt renomeado pra .mp4 não passar.
 */
function validarUploadStory(array $arquivo_enviado): array {
    if (empty($arquivo_enviado['tmp_name']) || ($arquivo_enviado['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['sucesso' => false, 'mensagem' => 'Nenhum arquivo enviado.'];
    }

    $tmp = $arquivo_enviado['tmp_name'];
    $tamanho = (int) ($arquivo_enviado['size'] ?? 0);
    $extensao = strtolower(pathinfo($arquivo_enviado['name'] ?? '', PATHINFO_EXTENSION));

    if (in_array($extensao, STORIES_EXTENSOES_FOTO, true)) {
        if ($tamanho <= 0 || $tamanho > STORIES_LIMITE_FOTO_BYTES) {
            return ['sucesso' => false, 'mensagem' => 'Foto acima do limite de 5MB.'];
        }
        if (!@getimagesize($tmp)) {
            return ['sucesso' => false, 'mensagem' => 'Arquivo de foto inválido.'];
        }
        return ['sucesso' => true, 'tipo_midia' => 'foto', 'extensao' => $extensao === 'jpeg' ? 'jpg' : $extensao];
    }

    if (in_array($extensao, STORIES_EXTENSOES_VIDEO, true)) {
        if ($tamanho <= 0 || $tamanho > STORIES_LIMITE_VIDEO_BYTES) {
            return ['sucesso' => false, 'mensagem' => 'Vídeo acima do limite de 20MB.'];
        }
        // Duração máxima de 30s é validada no navegador (assets/stories.js) -- não existe
        // ffmpeg confirmado no servidor pra checar/transcodificar vídeo sem custo de CPU.
        $mime = function_exists('mime_content_type') ? (mime_content_type($tmp) ?: '') : '';
        if ($mime !== '' && strpos($mime, 'video/') !== 0) {
            return ['sucesso' => false, 'mensagem' => 'O arquivo não parece ser um vídeo de verdade.'];
        }
        return ['sucesso' => true, 'tipo_midia' => 'video', 'extensao' => $extensao];
    }

    return ['sucesso' => false, 'mensagem' => 'Formato não suportado. Use jpg, png, webp (foto) ou mp4, webm (vídeo).'];
}

/** Move o arquivo já validado pra uploads/stories/ com nome único. Sem transcodificação. */
function salvarArquivoStory(array $arquivo_enviado, string $extensao): string {
    if (!is_dir(STORIES_DIRETORIO_UPLOADS)) {
        mkdir(STORIES_DIRETORIO_UPLOADS, 0755, true);
    }
    $nome_arquivo = uniqid('story_', true) . '.' . $extensao;
    $caminho_destino = STORIES_DIRETORIO_UPLOADS . '/' . $nome_arquivo;
    if (!move_uploaded_file($arquivo_enviado['tmp_name'], $caminho_destino) || !file_exists($caminho_destino)) {
        throw new RuntimeException('Falha ao salvar arquivo de story.');
    }
    return $nome_arquivo;
}

function criarStory(int $usuario_id, string $tipo_midia, string $arquivo): array {
    global $pdo;

    if (!in_array($tipo_midia, ['foto', 'video'], true)) {
        return ['sucesso' => false, 'mensagem' => 'Tipo de mídia inválido.'];
    }

    try {
        if (contarStoriesAtivasUsuario($usuario_id) >= STORIES_MAX_ATIVAS_POR_USUARIO) {
            return ['sucesso' => false, 'mensagem' => 'Limite de ' . STORIES_MAX_ATIVAS_POR_USUARIO . ' stories ativos atingido. Aguarde alguns expirarem.'];
        }

        $stmt = $pdo->prepare("
            INSERT INTO stories (id_usuario, tipo_midia, arquivo, expira_em)
            VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))
        ");
        $stmt->execute([$usuario_id, $tipo_midia, $arquivo]);
    } catch (Throwable $e) {
        error_log('[stories] criarStory: ' . $e->getMessage());
        return ['sucesso' => false, 'mensagem' => 'Não foi possível publicar o story. Tente novamente.'];
    }

    return ['sucesso' => true, 'id' => (int) $pdo->lastInsertId(), 'mensagem' => 'Story publicado com sucesso.'];
}

/** Fire-and-forget: chamado pelo visualizador ao abrir cada story, não bloqueia a UI. */
function marcarStoryVista(int $usuario_id, int $story_id): bool {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            INSERT INTO stories_visualizacoes (story_id, id_usuario)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE visto_em = visto_em
        ");
        return $stmt->execute([$story_id, $usuario_id]);
    } catch (PDOException $e) {
        error_log('Erro ao marcar story como vista: ' . $e->getMessage());
        return false;
    }
}

function excluirStory(int $usuario_id, int $story_id): array {
    global $pdo;

    $stmt = $pdo->prepare("SELECT arquivo FROM stories WHERE id = ? AND id_usuario = ?");
    $stmt->execute([$story_id, $usuario_id]);
    $story = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$story) {
        return ['sucesso' => false, 'mensagem' => 'Story não encontrado.'];
    }

    $pdo->prepare("DELETE FROM stories WHERE id = ? AND id_usuario = ?")->execute([$story_id, $usuario_id]);

    $caminho_arquivo = STORIES_DIRETORIO_UPLOADS . '/' . basename($story['arquivo']);
    if (is_file($caminho_arquivo)) {
        @unlink($caminho_arquivo);
    }

    return ['sucesso' => true, 'mensagem' => 'Story excluído com sucesso.'];
}

/**
 * Limpeza best-effort de linhas + arquivos expirados (chamada por cron/cron_limpar_stories.php).
 * Não afeta a visibilidade da barra -- isso já é garantido pelo filtro expira_em > NOW() em
 * toda leitura -- só libera espaço em disco e evita a tabela crescer indefinidamente.
 */
function limparStoriesExpiradas(int $lote = 200): int {
    global $pdo;

    $stmt = $pdo->prepare("SELECT id, arquivo FROM stories WHERE expira_em <= NOW() LIMIT ?");
    $stmt->bindValue(1, $lote, PDO::PARAM_INT);
    $stmt->execute();
    $expiradas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$expiradas) {
        return 0;
    }

    $ids = array_column($expiradas, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $pdo->prepare("DELETE FROM stories WHERE id IN ($placeholders)")->execute($ids);

    foreach ($expiradas as $expirada) {
        $caminho_arquivo = STORIES_DIRETORIO_UPLOADS . '/' . basename($expirada['arquivo']);
        if (is_file($caminho_arquivo)) {
            @unlink($caminho_arquivo);
        }
    }

    return count($expiradas);
}
