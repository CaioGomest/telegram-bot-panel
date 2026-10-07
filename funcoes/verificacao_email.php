<?php
declare(strict_types=1);

require_once __DIR__ . '/email.php';

/**
 * Verificação de e-mail. O link enviado carrega um token aleatório de 256 bits; no banco fica só o
 * hash SHA-256 (`tokens_email.token_hash`), então um vazamento da tabela não entrega links válidos.
 * Verificar não bloqueia o uso do painel — mostra um aviso com "Reenviar" no menu lateral.
 * Contas anteriores ao recurso (e logins pelo Google, que já vêm verificados) nascem verificadas.
 */

const VERIFICACAO_EMAIL_VALIDADE_SEGUNDOS = 172800; // 48h

/** True se o e-mail já foi verificado. Se a coluna ainda não existe (banco sem atualizar), não incomoda ninguém. */
function emailVerificado(int $id_usuario): bool
{
    global $pdo;
    if (!empty($_SESSION['email_verificado_' . $id_usuario])) {
        return true;
    }
    try {
        $stmt = $pdo->prepare("SELECT email_verificado_em FROM usuarios WHERE id = ?");
        $stmt->execute([$id_usuario]);
        $verificado = $stmt->fetchColumn();
    } catch (\Throwable $e) {
        return true;
    }
    if ($verificado) {
        $_SESSION['email_verificado_' . $id_usuario] = true;
        return true;
    }
    return false;
}

function marcarEmailVerificado(int $id_usuario): void
{
    global $pdo;
    try {
        $pdo->prepare("UPDATE usuarios SET email_verificado_em = COALESCE(email_verificado_em, NOW()) WHERE id = ?")->execute([$id_usuario]);
        $_SESSION['email_verificado_' . $id_usuario] = true;
    } catch (\Throwable $e) {
        error_log('[verificacao_email] ' . $e->getMessage());
    }
}

/** Gera um token novo (invalida os anteriores ainda não usados) e devolve o valor em texto — só vai por e-mail. */
function criarTokenVerificacaoEmail(int $id_usuario): ?string
{
    global $pdo;
    try {
        $pdo->prepare("DELETE FROM tokens_email WHERE id_usuario = ? AND tipo = 'verificacao' AND usado_em IS NULL")->execute([$id_usuario]);
        $token = bin2hex(random_bytes(32));
        $pdo->prepare("INSERT INTO tokens_email (id_usuario, tipo, token_hash, expira_em, criado_em) VALUES (?, 'verificacao', ?, NOW() + INTERVAL ? SECOND, NOW())")
            ->execute([$id_usuario, hash('sha256', $token), VERIFICACAO_EMAIL_VALIDADE_SEGUNDOS]);
        return $token;
    } catch (\Throwable $e) {
        error_log('[verificacao_email] não criou token: ' . $e->getMessage());
        return null;
    }
}

function urlVerificacaoEmail(string $token): string
{
    return urlBasePainel() . '/verificar_email?token=' . $token;
}

/**
 * Cria o token e manda o e-mail (pela fila). $boas_vindas=true usa o e-mail de cadastro (que já traz o
 * botão de confirmação); false manda só o pedido de confirmação (reenvio).
 */
function enviarVerificacaoParaUsuario(int $id_usuario, bool $boas_vindas = false): bool
{
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT nome, email FROM usuarios WHERE id = ?");
        $stmt->execute([$id_usuario]);
        $usuario = $stmt->fetch();
    } catch (\Throwable $e) {
        return false;
    }
    if (!$usuario) {
        return false;
    }
    $token = criarTokenVerificacaoEmail($id_usuario);
    $url = $token ? urlVerificacaoEmail($token) : '';
    if ($boas_vindas) {
        return enviarEmailBoasVindas((string) $usuario['nome'], (string) $usuario['email'], $url);
    }
    return $url !== '' && enviarEmailVerificacao((string) $usuario['nome'], (string) $usuario['email'], $url);
}

/** Confirma o e-mail a partir do token do link. Retorna ['sucesso' => bool, 'mensagem' => string]. */
function confirmarEmailPorToken(string $token): array
{
    global $pdo;
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return ['sucesso' => false, 'mensagem' => 'Link inválido.'];
    }
    try {
        $stmt = $pdo->prepare("SELECT id, id_usuario, expira_em, usado_em FROM tokens_email WHERE token_hash = ? AND tipo = 'verificacao'");
        $stmt->execute([hash('sha256', $token)]);
        $registro = $stmt->fetch();
        if (!$registro) {
            return ['sucesso' => false, 'mensagem' => 'Link inválido ou já substituído por um mais novo.'];
        }
        if (!empty($registro['usado_em'])) {
            return ['sucesso' => true, 'mensagem' => 'Este e-mail já estava confirmado.'];
        }
        if (strtotime((string) $registro['expira_em']) < time()) {
            return ['sucesso' => false, 'mensagem' => 'Este link expirou. Entre no painel e peça um novo e-mail de confirmação.'];
        }
        $pdo->prepare("UPDATE tokens_email SET usado_em = NOW() WHERE id = ? AND usado_em IS NULL")->execute([$registro['id']]);
        marcarEmailVerificado((int) $registro['id_usuario']);
        registrarAtividade((int) $registro['id_usuario'], 'sistema', 'E-mail verificado', 'E-mail da conta confirmado pelo link.');
        return ['sucesso' => true, 'mensagem' => 'E-mail confirmado com sucesso!'];
    } catch (\Throwable $e) {
        error_log('[verificacao_email] ' . $e->getMessage());
        return ['sucesso' => false, 'mensagem' => 'Não foi possível confirmar agora. Tente de novo em instantes.'];
    }
}
