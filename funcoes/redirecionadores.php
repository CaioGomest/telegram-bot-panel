<?php
declare(strict_types=1);

// Redirecionamento: o usuário publica um link curto (/l/{slug}) nos anúncios; ao clicar, o
// visitante cai em l.php, que conta o clique e manda pro t.me do bot escolhido. O start do
// bot leva "rd_{slug}", então o lead nasce com origem_rastreio = rd_{slug} e as vendas dele
// voltam pro redirecionador (ver webhook.php e listarRedirecionadores()).

require_once __DIR__ . '/configuracoes.php';

const REDIRECIONADOR_PREFIXO_START = 'rd_';
const REDIRECIONADOR_LIMITE_POR_USUARIO = 50;
const REDIRECIONADOR_MAX_BOTS = 20;

const REDIRECIONADOR_PLATAFORMAS = [
    'meta' => [
        'nome' => 'Meta Ads',
        'query' => 'utm_source=facebook&utm_medium=paid&utm_campaign={{campaign.name}}&utm_content={{ad.name}}&utm_term={{adset.name}}',
        'onde' => 'No anúncio, cole os parâmetros no campo "Parâmetros de URL" (em Rastreamento) e a URL do link no campo "URL do site".',
    ],
    'tiktok' => [
        'nome' => 'TikTok Ads',
        'query' => 'utm_source=tiktok&utm_medium=paid&utm_campaign=__CAMPAIGN_NAME__&utm_content=__CID_NAME__&utm_term=__AID_NAME__',
        'onde' => 'No anúncio, use a URL completa como página de destino. O TikTok troca as variáveis __NOME__ pelos nomes reais.',
    ],
    'google' => [
        'nome' => 'Google Ads',
        'query' => 'utm_source=google&utm_medium=cpc&utm_campaign={campaignid}&utm_content={creative}&utm_term={keyword}',
        'onde' => 'Cole os parâmetros em "Modelo de acompanhamento" (ou use a URL completa como URL final).',
    ],
    'outra' => [
        'nome' => 'Outra origem',
        'query' => '',
        'onde' => 'Use a URL do link direto, no perfil, bio ou mensagem. Sem UTMs automáticas.',
    ],
];

const REDIRECIONADOR_PROTECOES = ['nenhuma', 'filtrar_robos'];
const REDIRECIONADOR_MODOS = ['aleatorio', 'sequencial'];

function esquemaRedirecionamento(): array
{
    return [
        "CREATE TABLE IF NOT EXISTS redirecionadores (
            id INT AUTO_INCREMENT PRIMARY KEY,
            id_usuario INT NOT NULL,
            titulo VARCHAR(120) NOT NULL,
            slug VARCHAR(40) NOT NULL,
            dominio VARCHAR(190) DEFAULT NULL,
            ativo TINYINT(1) NOT NULL DEFAULT 1,
            plataforma VARCHAR(20) NOT NULL DEFAULT 'meta',
            protecao VARCHAR(20) NOT NULL DEFAULT 'nenhuma',
            modo VARCHAR(20) NOT NULL DEFAULT 'aleatorio',
            cliques INT NOT NULL DEFAULT 0,
            starts INT NOT NULL DEFAULT 0,
            leads INT NOT NULL DEFAULT 0,
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_slug (slug),
            KEY idx_redirecionadores_usuario (id_usuario),
            FOREIGN KEY (id_usuario) REFERENCES usuarios(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS redirecionador_destinos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            redirecionador_id INT NOT NULL,
            bot_id INT NOT NULL,
            UNIQUE KEY unique_destino (redirecionador_id, bot_id),
            FOREIGN KEY (redirecionador_id) REFERENCES redirecionadores(id) ON DELETE CASCADE,
            FOREIGN KEY (bot_id) REFERENCES bots(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS redirecionador_cliques_dia (
            redirecionador_id INT NOT NULL,
            dia DATE NOT NULL,
            campanha VARCHAR(100) NOT NULL DEFAULT '',
            cliques INT NOT NULL DEFAULT 0,
            PRIMARY KEY (redirecionador_id, dia, campanha),
            FOREIGN KEY (redirecionador_id) REFERENCES redirecionadores(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
}

/** Cria as tabelas se faltarem, pra tela funcionar mesmo antes de rodar "Atualizar Banco". */
function garantirTabelasRedirecionamento(): bool
{
    static $ok = null;
    global $pdo;
    if ($ok !== null) {
        return $ok;
    }
    try {
        $existe = $pdo->query("SHOW TABLES LIKE 'redirecionador_cliques_dia'")->fetchColumn();
        if (!$existe) {
            foreach (esquemaRedirecionamento() as $sql) {
                $pdo->exec($sql);
            }
        }
        $ok = true;
    } catch (\Throwable $e) {
        error_log('[redirecionamento] falha ao criar tabelas: ' . $e->getMessage());
        $ok = false;
    }
    return $ok;
}

function slugRedirecionadorValido(string $slug): bool
{
    return strlen($slug) >= 3 && strlen($slug) <= 40
        && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) === 1;
}

function gerarSlugAleatorio(int $tamanho = 8): string
{
    $alfabeto = 'abcdefghijklmnopqrstuvwxyz0123456789';
    $slug = '';
    for ($i = 0; $i < $tamanho; $i++) {
        $slug .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
    }
    return $slug;
}

function slugRedirecionadorLivre(string $slug): bool
{
    global $pdo;
    $stmt = $pdo->prepare("SELECT 1 FROM redirecionadores WHERE slug = ?");
    $stmt->execute([$slug]);
    return !$stmt->fetchColumn();
}

/** Domínios extras (um por linha) que o admin apontou pro mesmo servidor, em Configurações. */
function dominiosRedirecionamento(): array
{
    $bruto = configSistema('dominios_redirecionamento');
    $lista = [];
    foreach (preg_split('/[\r\n,;\s]+/', $bruto) ?: [] as $item) {
        $item = strtolower(trim($item));
        if ($item !== '' && dominioRedirecionamentoValido($item)) {
            $lista[$item] = $item;
        }
    }
    return array_values($lista);
}

function dominioRedirecionamentoValido(string $dominio): bool
{
    return strlen($dominio) <= 190
        && preg_match('/^(?=.{1,190}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}$/', $dominio) === 1;
}

function contextoUrlRedirecionamento(): array
{
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $base = rtrim(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '')), '/\\');
    return [
        'esquema' => $https ? 'https' : 'http',
        'host' => $host,
        'base' => $base,
    ];
}

function urlPublicaRedirecionador(array $r, array $ctx): string
{
    $host = ($r['dominio'] ?? '') !== '' ? (string) $r['dominio'] : $ctx['host'];
    return $ctx['esquema'] . '://' . $host . $ctx['base'] . '/l/' . $r['slug'];
}

function nomeUsuarioBotValido(?string $nome): bool
{
    return $nome !== null && preg_match('/^[A-Za-z0-9_]{4,32}$/', ltrim($nome, '@')) === 1;
}

function opcoesRedirecionamento(int $usuario_id): array
{
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT b.id, b.nome_usuario, b.primeiro_nome, f.nome AS nome_fluxo
        FROM bots b
        LEFT JOIN fluxos f ON f.id = b.id_fluxo_conectado
        WHERE b.id_usuario = ?
        ORDER BY b.atualizado_em DESC
    ");
    $stmt->execute([$usuario_id]);
    $bots = [];
    foreach ($stmt->fetchAll() as $b) {
        if (!nomeUsuarioBotValido($b['nome_usuario'])) {
            continue;
        }
        $bots[] = [
            'id' => (int) $b['id'],
            'nome' => (string) ($b['primeiro_nome'] ?: $b['nome_usuario']),
            'usuario' => ltrim((string) $b['nome_usuario'], '@'),
            'fluxo' => (string) ($b['nome_fluxo'] ?? ''),
        ];
    }

    $plataformas = [];
    foreach (REDIRECIONADOR_PLATAFORMAS as $chave => $p) {
        $plataformas[$chave] = ['nome' => $p['nome'], 'query' => $p['query'], 'onde' => $p['onde']];
    }

    $ctx = contextoUrlRedirecionamento();
    return [
        'bots' => $bots,
        'dominios' => dominiosRedirecionamento(),
        'plataformas' => $plataformas,
        'host' => $ctx['host'],
        'esquema' => $ctx['esquema'],
        'base' => $ctx['base'],
    ];
}

function listarRedirecionadores(int $usuario_id): array
{
    global $pdo;
    $ctx = contextoUrlRedirecionamento();

    $stmt = $pdo->prepare("
        SELECT r.*,
               (SELECT COUNT(*) FROM redirecionador_destinos d WHERE d.redirecionador_id = r.id) AS qtd_destinos,
               (SELECT COALESCE(SUM(c.cliques), 0) FROM redirecionador_cliques_dia c
                 WHERE c.redirecionador_id = r.id AND c.dia >= CURDATE() - INTERVAL 6 DAY) AS cliques_7d
        FROM redirecionadores r
        WHERE r.id_usuario = ?
        ORDER BY r.criado_em DESC
    ");
    $stmt->execute([$usuario_id]);
    $linhas = $stmt->fetchAll();
    if (!$linhas) {
        return [];
    }

    // Uma consulta só pra todas as vendas: o LIKE de prefixo só olha os leads que vieram de
    // um redirecionador, então não varre a base inteira de leads do usuário.
    $vendas = [];
    try {
        $stmt_v = $pdo->prepare("
            SELECT l.origem_rastreio AS origem, COUNT(*) AS qtd, COALESCE(SUM(v.valor), 0) AS valor
            FROM leads l
            JOIN vendas v ON v.id_telegram = l.id_telegram AND v.bot_id = l.bot_id
            WHERE l.origem_rastreio LIKE 'rd\\_%'
              AND l.bot_id IN (SELECT id FROM bots WHERE id_usuario = ?)
              AND v.status = 'pago'
            GROUP BY l.origem_rastreio
        ");
        $stmt_v->execute([$usuario_id]);
        foreach ($stmt_v->fetchAll() as $v) {
            $vendas[$v['origem']] = $v;
        }
    } catch (\Throwable $e) {
        error_log('[redirecionamento] vendas: ' . $e->getMessage());
    }

    foreach ($linhas as &$r) {
        $origem = REDIRECIONADOR_PREFIXO_START . $r['slug'];
        $r['id'] = (int) $r['id'];
        $r['ativo'] = (int) $r['ativo'];
        $r['cliques'] = (int) $r['cliques'];
        $r['starts'] = (int) $r['starts'];
        $r['leads'] = (int) $r['leads'];
        $r['qtd_destinos'] = (int) $r['qtd_destinos'];
        $r['cliques_7d'] = (int) $r['cliques_7d'];
        $r['vendas_qtd'] = (int) ($vendas[$origem]['qtd'] ?? 0);
        $r['vendas_valor'] = (float) ($vendas[$origem]['valor'] ?? 0);
        $r['url'] = urlPublicaRedirecionador($r, $ctx);
    }
    unset($r);
    return $linhas;
}

function obterRedirecionador(int $usuario_id, int $id): array|false
{
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM redirecionadores WHERE id = ? AND id_usuario = ?");
    $stmt->execute([$id, $usuario_id]);
    $r = $stmt->fetch();
    if (!$r) {
        return false;
    }
    $stmt_d = $pdo->prepare("SELECT bot_id FROM redirecionador_destinos WHERE redirecionador_id = ? ORDER BY id");
    $stmt_d->execute([$id]);
    $r['id'] = (int) $r['id'];
    $r['ativo'] = (int) $r['ativo'];
    $r['bots'] = array_map('intval', $stmt_d->fetchAll(PDO::FETCH_COLUMN));
    $r['url'] = urlPublicaRedirecionador($r, contextoUrlRedirecionamento());
    return $r;
}

/**
 * Cria ou atualiza. O slug só é definido na criação: trocar depois quebraria os anúncios
 * já publicados e o vínculo dos leads antigos (origem_rastreio = rd_{slug}).
 */
function salvarRedirecionador(int $usuario_id, array $d): array
{
    global $pdo;

    $id = (int) ($d['id'] ?? 0);
    $titulo = trim((string) ($d['titulo'] ?? ''));
    $titulo = mb_substr(preg_replace('/\s+/u', ' ', $titulo) ?? '', 0, 120);
    if ($titulo === '') {
        return ['sucesso' => false, 'mensagem' => 'Dê um nome ao link.'];
    }

    $ativo = !empty($d['ativo']) ? 1 : 0;
    $plataforma = (string) ($d['plataforma'] ?? 'meta');
    $protecao = (string) ($d['protecao'] ?? 'nenhuma');
    $modo = (string) ($d['modo'] ?? 'aleatorio');
    if (!isset(REDIRECIONADOR_PLATAFORMAS[$plataforma]) || !in_array($protecao, REDIRECIONADOR_PROTECOES, true) || !in_array($modo, REDIRECIONADOR_MODOS, true)) {
        return ['sucesso' => false, 'mensagem' => 'Configuração inválida.'];
    }

    $dominio = strtolower(trim((string) ($d['dominio'] ?? '')));
    if ($dominio !== '' && !in_array($dominio, dominiosRedirecionamento(), true)) {
        return ['sucesso' => false, 'mensagem' => 'Domínio não disponível.'];
    }

    $bots_pedidos = array_values(array_unique(array_filter(array_map('intval', (array) ($d['bots'] ?? [])), fn($b) => $b > 0)));
    if (count($bots_pedidos) > REDIRECIONADOR_MAX_BOTS) {
        return ['sucesso' => false, 'mensagem' => 'Selecione no máximo ' . REDIRECIONADOR_MAX_BOTS . ' bots.'];
    }
    $bots = [];
    if ($bots_pedidos) {
        $marcas = implode(',', array_fill(0, count($bots_pedidos), '?'));
        $stmt_b = $pdo->prepare("SELECT id, nome_usuario FROM bots WHERE id_usuario = ? AND id IN ($marcas)");
        $stmt_b->execute(array_merge([$usuario_id], $bots_pedidos));
        foreach ($stmt_b->fetchAll() as $b) {
            if (nomeUsuarioBotValido($b['nome_usuario'])) {
                $bots[] = (int) $b['id'];
            }
        }
        if (count($bots) !== count($bots_pedidos)) {
            return ['sucesso' => false, 'mensagem' => 'Um dos bots escolhidos não existe ou não tem @usuário.'];
        }
    }
    if ($ativo && !$bots) {
        return ['sucesso' => false, 'mensagem' => 'Escolha ao menos um bot de destino ou deixe o link inativo.'];
    }

    try {
        $pdo->beginTransaction();

        if ($id > 0) {
            $stmt = $pdo->prepare("
                UPDATE redirecionadores
                SET titulo = ?, dominio = ?, ativo = ?, plataforma = ?, protecao = ?, modo = ?
                WHERE id = ? AND id_usuario = ?
            ");
            $stmt->execute([$titulo, $dominio !== '' ? $dominio : null, $ativo, $plataforma, $protecao, $modo, $id, $usuario_id]);
            $stmt_existe = $pdo->prepare("SELECT 1 FROM redirecionadores WHERE id = ? AND id_usuario = ?");
            $stmt_existe->execute([$id, $usuario_id]);
            if (!$stmt_existe->fetchColumn()) {
                $pdo->rollBack();
                return ['sucesso' => false, 'mensagem' => 'Link não encontrado.'];
            }
        } else {
            $stmt_n = $pdo->prepare("SELECT COUNT(*) FROM redirecionadores WHERE id_usuario = ?");
            $stmt_n->execute([$usuario_id]);
            if ((int) $stmt_n->fetchColumn() >= REDIRECIONADOR_LIMITE_POR_USUARIO) {
                $pdo->rollBack();
                return ['sucesso' => false, 'mensagem' => 'Limite de ' . REDIRECIONADOR_LIMITE_POR_USUARIO . ' links atingido.'];
            }

            $formato = (string) ($d['formato'] ?? 'aleatorio');
            $slug = strtolower(trim((string) ($d['slug'] ?? '')));
            if ($formato === 'personalizado') {
                if (!slugRedirecionadorValido($slug)) {
                    $pdo->rollBack();
                    return ['sucesso' => false, 'mensagem' => 'Endereço inválido. Use 3 a 40 letras minúsculas, números e hífens (sem hífen no começo ou fim).'];
                }
                if (!slugRedirecionadorLivre($slug)) {
                    $pdo->rollBack();
                    return ['sucesso' => false, 'mensagem' => 'Esse endereço já está em uso. Escolha outro.'];
                }
            } else {
                if (!slugRedirecionadorValido($slug) || !slugRedirecionadorLivre($slug)) {
                    $slug = gerarSlugAleatorio();
                    for ($i = 0; $i < 5 && !slugRedirecionadorLivre($slug); $i++) {
                        $slug = gerarSlugAleatorio();
                    }
                }
            }

            $stmt = $pdo->prepare("
                INSERT INTO redirecionadores (id_usuario, titulo, slug, dominio, ativo, plataforma, protecao, modo)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$usuario_id, $titulo, $slug, $dominio !== '' ? $dominio : null, $ativo, $plataforma, $protecao, $modo]);
            $id = (int) $pdo->lastInsertId();
        }

        $pdo->prepare("DELETE FROM redirecionador_destinos WHERE redirecionador_id = ?")->execute([$id]);
        if ($bots) {
            $stmt_i = $pdo->prepare("INSERT INTO redirecionador_destinos (redirecionador_id, bot_id) VALUES (?, ?)");
            foreach ($bots as $bot_id) {
                $stmt_i->execute([$id, $bot_id]);
            }
        }

        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof PDOException && ($e->errorInfo[1] ?? 0) === 1062) {
            return ['sucesso' => false, 'mensagem' => 'Esse endereço já está em uso. Escolha outro.'];
        }
        error_log('[redirecionamento] salvar: ' . $e->getMessage());
        return ['sucesso' => false, 'mensagem' => 'Não foi possível salvar o link.'];
    }

    return ['sucesso' => true, 'id' => $id, 'mensagem' => 'Link salvo.'];
}

function alternarRedirecionador(int $usuario_id, int $id, bool $ativo): array
{
    global $pdo;
    if ($ativo) {
        $stmt_d = $pdo->prepare("
            SELECT COUNT(*) FROM redirecionador_destinos d
            JOIN redirecionadores r ON r.id = d.redirecionador_id
            WHERE r.id = ? AND r.id_usuario = ?
        ");
        $stmt_d->execute([$id, $usuario_id]);
        if ((int) $stmt_d->fetchColumn() === 0) {
            return ['sucesso' => false, 'mensagem' => 'Escolha ao menos um bot de destino antes de ativar.'];
        }
    }
    $stmt = $pdo->prepare("UPDATE redirecionadores SET ativo = ? WHERE id = ? AND id_usuario = ?");
    $stmt->execute([$ativo ? 1 : 0, $id, $usuario_id]);
    $stmt_e = $pdo->prepare("SELECT 1 FROM redirecionadores WHERE id = ? AND id_usuario = ?");
    $stmt_e->execute([$id, $usuario_id]);
    if (!$stmt_e->fetchColumn()) {
        return ['sucesso' => false, 'mensagem' => 'Link não encontrado.'];
    }
    return ['sucesso' => true, 'mensagem' => $ativo ? 'Link ativado.' : 'Link desativado.'];
}

function excluirRedirecionador(int $usuario_id, int $id): array
{
    global $pdo;
    $stmt = $pdo->prepare("DELETE FROM redirecionadores WHERE id = ? AND id_usuario = ?");
    $stmt->execute([$id, $usuario_id]);
    if ($stmt->rowCount() === 0) {
        return ['sucesso' => false, 'mensagem' => 'Link não encontrado.'];
    }
    return ['sucesso' => true, 'mensagem' => 'Link excluído.'];
}

/** Cliques dos últimos 30 dias agrupados pelo utm_campaign que veio no clique. */
function campanhasRedirecionador(int $usuario_id, int $id): array|false
{
    global $pdo;
    $stmt_e = $pdo->prepare("SELECT 1 FROM redirecionadores WHERE id = ? AND id_usuario = ?");
    $stmt_e->execute([$id, $usuario_id]);
    if (!$stmt_e->fetchColumn()) {
        return false;
    }
    $stmt = $pdo->prepare("
        SELECT campanha, SUM(cliques) AS cliques
        FROM redirecionador_cliques_dia
        WHERE redirecionador_id = ? AND dia >= CURDATE() - INTERVAL 29 DAY
        GROUP BY campanha
        ORDER BY cliques DESC
        LIMIT 100
    ");
    $stmt->execute([$id]);
    return array_map(fn($l) => ['campanha' => (string) $l['campanha'], 'cliques' => (int) $l['cliques']], $stmt->fetchAll());
}

function ehRoboRedirecionamento(string $user_agent, string $metodo): bool
{
    if ($metodo === 'HEAD' || trim($user_agent) === '') {
        return true;
    }
    return preg_match(
        '/bot\b|bot\/|crawl|spider|slurp|facebookexternalhit|facebookcatalog|meta-externalagent|whatsapp\/|preview|curl\/|wget|python-|go-http|java\/|headless|lighthouse|pingdom|monitor|scanner/i',
        $user_agent
    ) === 1;
}
