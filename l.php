<?php
declare(strict_types=1);

// Endpoint público do Redirecionamento (/l/{slug}). Sem sessão e sem login: quem clica no
// anúncio não tem conta. Qualquer falha na contagem NÃO pode impedir o redirect.

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/funcoes/redirecionadores.php';
require_once __DIR__ . '/funcoes/protecao_redirecionamento.php';

function paginaLinkIndisponivel(int $status, string $mensagem): void
{
    http_response_code($status);
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">'
        . '<meta name="robots" content="noindex"><title>Link indisponível</title>'
        . '<style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#0a0b0e;color:#f3f2f0;font:16px system-ui,sans-serif;text-align:center;padding:24px}'
        . 'h1{font-size:22px;margin:0 0 8px}p{margin:0;color:#8b9099}</style></head><body><div>'
        . '<h1>Link indisponível</h1><p>' . htmlspecialchars($mensagem) . '</p></div></body></html>';
    exit;
}

$slug = strtolower((string) ($_GET['s'] ?? ''));
if (!slugRedirecionadorValido($slug)) {
    paginaLinkIndisponivel(404, 'Este endereço não existe.');
}

try {
    $sql_busca = "SELECT id, ativo, plataforma, protecao, modo, bloqueio_tipo, bloqueio_url, bloquear_bots,
                         anti_compartilhamento, filtrar_pais, paises, validar_idioma
                  FROM redirecionadores WHERE slug = ?";
    try {
        $stmt = $pdo->prepare($sql_busca);
    } catch (PDOException $e) {
        // Banco anterior à proteção/segmentação: cria as colunas na hora e tenta de novo.
        garantirTabelasRedirecionamento();
        $stmt = $pdo->prepare($sql_busca);
    }
    $stmt->execute([$slug]);
    $r = $stmt->fetch();
} catch (\Throwable $e) {
    error_log('[redirecionamento] l.php busca: ' . $e->getMessage());
    paginaLinkIndisponivel(503, 'Tente novamente em instantes.');
}

if (!$r || !(int) $r['ativo']) {
    paginaLinkIndisponivel(404, 'Este link não está mais disponível.');
}

$id = (int) $r['id'];
$stmt_d = $pdo->prepare("
    SELECT b.nome_usuario
    FROM redirecionador_destinos d
    JOIN bots b ON b.id = d.bot_id
    WHERE d.redirecionador_id = ?
    ORDER BY d.id
");
$stmt_d->execute([$id]);
$destinos = array_values(array_filter(
    array_map(fn($n) => ltrim((string) $n, '@'), $stmt_d->fetchAll(PDO::FETCH_COLUMN)),
    'nomeUsuarioBotValido'
));
if (!$destinos) {
    paginaLinkIndisponivel(503, 'Este link ainda não tem um destino configurado.');
}

$metodo = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$ip = preg_replace('/[^0-9a-fA-F:.]/', '', (string) ($_SERVER['REMOTE_ADDR'] ?? '')) ?? '';

// Proteção + segmentação: quem não passa vê a resposta de bloqueio e não conta como clique.
$motivo = $metodo === 'HEAD' ? null : avaliarAcessoRedirecionador($r, [
    'ua' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
    'metodo' => $metodo,
    'idioma' => (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''),
    'query' => $_GET,
    'pais' => fn() => paisDoIpRedirecionamento($ip),
]);
if ($motivo !== null) {
    contarBloqueioRedirecionamento($id);
    responderBloqueioRedirecionamento($r);
}

// Cloaker V2: o navegador precisa resolver um desafio em JavaScript antes de seguir.
if ($r['protecao'] === 'cloaker_v2' && $metodo !== 'HEAD') {
    if ($metodo === 'POST') {
        $corpo = json_decode((string) file_get_contents('php://input', false, null, 0, 4096), true);
        $passe_ok = is_array($corpo) && passeDesafioRedirecionamentoValido((string) ($corpo['p'] ?? ''), $slug, $ip)
            && sinaisDesafioRedirecionamentoOk((array) ($corpo['s'] ?? []), (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        if (!$passe_ok) {
            contarBloqueioRedirecionamento($id);
            if ($r['bloqueio_tipo'] === 'redirecionar' && urlBloqueioRedirecionamentoValida((string) $r['bloqueio_url'])) {
                echo json_encode(['u' => $r['bloqueio_url']]);
            } else {
                echo json_encode(['h' => paginaSeguraRedirecionamento()]);
            }
            exit;
        }
        $v2_aprovado = true;
    } else {
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('X-Robots-Tag: noindex, nofollow');
        header('Content-Type: text/html; charset=UTF-8');
        echo paginaDesafioRedirecionamento($r, emitirPasseDesafioRedirecionamento($slug, $ip));
        exit;
    }
}
$v2_aprovado = $v2_aprovado ?? false;

$contar = $metodo !== 'HEAD';

$posicao = null;
if ($contar) {
    try {
        $pdo->prepare("UPDATE redirecionadores SET cliques = LAST_INSERT_ID(cliques + 1) WHERE id = ?")->execute([$id]);
        $posicao = (int) $pdo->query("SELECT LAST_INSERT_ID()")->fetchColumn();

        $campanha = (string) ($_GET['utm_campaign'] ?? '');
        $campanha = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $campanha) ?? ''), 0, 100);
        $pdo->prepare("
            INSERT INTO redirecionador_cliques_dia (redirecionador_id, dia, campanha, cliques)
            VALUES (?, CURDATE(), ?, 1)
            ON DUPLICATE KEY UPDATE cliques = cliques + 1
        ")->execute([$id, $campanha]);
    } catch (Throwable $e) {
        error_log('[redirecionamento] l.php contagem: ' . $e->getMessage());
    }
}

if ($r['modo'] === 'sequencial' && $posicao !== null) {
    $escolhido = $destinos[($posicao - 1) % count($destinos)];
} elseif ($r['modo'] === 'sequencial') {
    $escolhido = $destinos[0];
} else {
    $escolhido = $destinos[random_int(0, count($destinos) - 1)];
}

$url = 'https://t.me/' . $escolhido . '?start=' . REDIRECIONADOR_PREFIXO_START . $slug;

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
if ($v2_aprovado) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['u' => $url]);
    exit;
}
header('Location: ' . $url, true, 302);
exit;
