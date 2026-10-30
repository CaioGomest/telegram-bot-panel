<?php
declare(strict_types=1);

/**
 * Base dos endpoints do chat dos leads (ajax/chat_*.php): login, método, CSRF e corpo JSON num lugar só.
 * Libera a trava da sessão do PHP logo depois de validar (session_write_close): a tela de chat consulta a cada poucos
 * segundos e o envio espera o Telegram responder (até 10 s); sem isso, as outras requisições do mesmo usuário ficariam na fila.
 */

require_once __DIR__ . '/../funcoes/usuario.php';
require_once __DIR__ . '/../funcoes/chat.php';
require_once __DIR__ . '/../funcoes/limite_taxa.php';

function chatResponder(array $dados, int $http = 200): void
{
    http_response_code($http);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Exige login e o método certo ('GET' ou 'POST'; POST também exige o token CSRF).
 *
 * @return array{0:int,1:array} [id do usuário logado, corpo JSON como array]
 */
function chatAjaxInicio(string $metodo): array
{
    if (!usuarioLogado()) {
        chatResponder(['sucesso' => false, 'mensagem' => 'Não autorizado.'], 401);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== $metodo) {
        chatResponder(['sucesso' => false, 'mensagem' => 'Método inválido.'], 405);
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
    $id_usuario = (int) $_SESSION['usuario_id'];
    session_write_close();
    return [$id_usuario, $dados];
}

/** Identificadores da conversa vindos da requisição: bot (inteiro) e id do Telegram (só dígitos). Responde 422 se inválidos. */
function chatConversaDaRequisicao(array $dados): array
{
    $bot_id = (int) ($dados['bot_id'] ?? 0);
    $id_telegram = (string) ($dados['id_telegram'] ?? '');
    if ($bot_id <= 0 || !preg_match('/^\d{1,20}$/', $id_telegram)) {
        chatResponder(['sucesso' => false, 'mensagem' => 'Conversa inválida.'], 422);
    }
    return [$bot_id, $id_telegram];
}
