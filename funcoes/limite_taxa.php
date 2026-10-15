<?php
declare(strict_types=1);

/**
 * Limite de taxa genérico (janela fixa) para ações que mandam e-mail ou que quem não está logado pode abusar: pedir código de
 * recuperação, reenviar confirmação, cadastrar. Contadores na tabela `limites_taxa` (chave, início da janela, contagem).
 *
 * A chave nunca leva o e-mail em texto (vai o sha1): a tabela não vira uma lista de endereços, nem de quais contas existem.
 * Sem a tabela (banco ainda sem "Atualizar Banco") o limite não bloqueia ninguém: falha aberta, com aviso no log do PHP.
 */

/** IP de quem fez a requisição. Só REMOTE_ADDR: X-Forwarded-For é forjável e o painel não fica atrás de proxy. */
function limiteTaxaIp(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'desconhecido';
}

/** Chave estável para um e-mail (minúsculo, sem espaços), sem guardar o endereço. */
function limiteTaxaChaveEmail(string $prefixo, string $email): string
{
    return $prefixo . ':' . sha1(mb_strtolower(trim($email)));
}

/**
 * Gasta 1 uso de um limite de $max por janela de $janela segundos.
 * Retorna [permitido, segundos_ate_liberar]. Se o limite já estourou, NÃO conta o pedido (insistir não prorroga o bloqueio).
 *
 * @return array{0:bool, 1:int}
 */
function limiteTaxaConsumir(string $chave, int $max, int $janela): array
{
    global $pdo;
    $chave = substr($chave, 0, 120);
    try {
        // Garante a linha (com a janela já vencida) e a trava para decidir sem corrida entre dois pedidos simultâneos.
        $pdo->prepare('INSERT IGNORE INTO limites_taxa (chave, janela_inicio, contagem) VALUES (?, NOW() - INTERVAL ? SECOND, 0)')->execute([$chave, $janela]);
        $pdo->beginTransaction();
        $st = $pdo->prepare('SELECT contagem, TIMESTAMPDIFF(SECOND, janela_inicio, NOW()) AS idade FROM limites_taxa WHERE chave = ? FOR UPDATE');
        $st->execute([$chave]);
        $linha = $st->fetch(PDO::FETCH_ASSOC) ?: ['contagem' => 0, 'idade' => $janela];
        $contagem = (int) $linha['contagem'];
        $idade = max(0, (int) $linha['idade']);

        if ($idade >= $janela) {
            $pdo->prepare('UPDATE limites_taxa SET janela_inicio = NOW(), contagem = 1 WHERE chave = ?')->execute([$chave]);
            $pdo->commit();
            return [true, 0];
        }
        if ($contagem >= $max) {
            $pdo->commit();
            return [false, max(1, $janela - $idade)];
        }
        $pdo->prepare('UPDATE limites_taxa SET contagem = contagem + 1 WHERE chave = ?')->execute([$chave]);
        $pdo->commit();
        return [true, 0];
    } catch (\Throwable $e) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[limite_taxa] sem limite para "' . $chave . '": ' . $e->getMessage());
        return [true, 0];
    }
}

/** Só olha (não gasta): segundos até o limite liberar, ou 0 se ainda há folga. */
function limiteTaxaEspera(string $chave, int $max, int $janela): int
{
    global $pdo;
    try {
        $st = $pdo->prepare('SELECT contagem, TIMESTAMPDIFF(SECOND, janela_inicio, NOW()) AS idade FROM limites_taxa WHERE chave = ?');
        $st->execute([substr($chave, 0, 120)]);
        $linha = $st->fetch(PDO::FETCH_ASSOC);
        if (!$linha) {
            return 0;
        }
        $idade = max(0, (int) $linha['idade']);
        return ($idade < $janela && (int) $linha['contagem'] >= $max) ? max(1, $janela - $idade) : 0;
    } catch (\Throwable $e) {
        return 0;
    }
}

/** Texto curto para "tente de novo em …" a partir de segundos. */
function limiteTaxaTextoEspera(int $segundos): string
{
    if ($segundos < 90) {
        return $segundos . ' segundos';
    }
    $minutos = (int) ceil($segundos / 60);
    return $minutos < 90 ? $minutos . ' minutos' : (int) ceil($minutos / 60) . ' horas';
}

/** Apaga contadores velhos (chamado pela fila de e-mails, que roda a cada minuto). */
function limiteTaxaLimpar(): void
{
    global $pdo;
    try {
        $pdo->exec('DELETE FROM limites_taxa WHERE janela_inicio < NOW() - INTERVAL 2 DAY LIMIT 2000');
    } catch (\Throwable $e) {
        // tabela ainda não existe
    }
}
