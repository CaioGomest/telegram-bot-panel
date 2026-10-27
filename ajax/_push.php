<?php
declare(strict_types=1);

/**
 * Base dos endpoints dos avisos push (ajax/push_*.php): login, método, CSRF e corpo JSON em um lugar só.
 * Plano: deploy/PLANO-PUSH-NOTIFICACOES.md (seção 6).
 */

require_once __DIR__ . '/../funcoes/usuario.php';
require_once __DIR__ . '/../funcoes/push.php';
require_once __DIR__ . '/../funcoes/limite_taxa.php';

function pushResponder(array $dados, int $http = 200): void
{
    http_response_code($http);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Exige login e o método certo ('GET' ou 'POST'; POST também exige o token CSRF). Devolve [id do usuário logado, corpo JSON como array].
 */
function pushAjaxInicio(string $metodo): array
{
    if (!usuarioLogado()) {
        pushResponder(['sucesso' => false, 'mensagem' => 'Não autorizado.'], 401);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== $metodo) {
        pushResponder(['sucesso' => false, 'mensagem' => 'Método inválido.'], 405);
    }
    $dados = [];
    if ($metodo === 'POST') {
        verificarCsrf();
        if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
            $bruto = file_get_contents('php://input');
            $json = json_decode($bruto === false || $bruto === '' ? '{}' : $bruto, true);
            $dados = is_array($json) ? $json : [];
        } else {
            $dados = $_POST;
        }
    }
    return [(int) $_SESSION['usuario_id'], $dados];
}

/** O vendedor só pode mexer nos avisos se o administrador ligou o interruptor geral e as chaves existem. */
function pushExigirAtivo(PDO $pdo): void
{
    if (!pushAtivo(true) || pushVapidPublica($pdo) === '') {
        pushResponder(['sucesso' => false, 'mensagem' => 'Os avisos no celular ainda não estão disponíveis.', 'ativo' => false], 409);
    }
}
