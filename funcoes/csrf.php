<?php
declare(strict_types=1);

// Proteção CSRF por sessão -- um token gerado uma vez por sessão (não por
// formulário), reaproveitado em todos os forms/requisições enquanto durar.
// Requer sessão já iniciada (funcoes/usuario.php faz isso antes de incluir
// este arquivo).

function csrfToken(): string {
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Campo oculto pronto pra colar dentro de qualquer <form method="POST">. */
function campoCsrf(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken(), ENT_QUOTES) . '">';
}

/**
 * Valida o token recebido -- campo de formulário (csrf_token) OU header
 * X-CSRF-Token (pros endpoints chamados via AJAX/fetch com FormData/JSON) --
 * contra o da sessão atual. Encerra a requisição com 403 se não bater, então
 * quem chama não precisa lembrar de checar o retorno.
 */
function verificarCsrf(): void {
    $esperado = $_SESSION['csrf_token'] ?? '';
    $recebido = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($esperado === '' || !is_string($recebido) || $recebido === '' || !hash_equals($esperado, $recebido)) {
        http_response_code(403);
        $eh_json = stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false
            || stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;
        if ($eh_json) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['sucesso' => false, 'erro' => 'Sessão expirada ou requisição inválida. Recarregue a página e tente novamente.']);
        } else {
            echo 'Sessão expirada ou requisição inválida (token de segurança ausente/incorreto). Recarregue a página e tente novamente.';
        }
        exit;
    }
}
