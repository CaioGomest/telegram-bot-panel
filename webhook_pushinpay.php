<?php
declare(strict_types=1);

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/funcoes/log.php';

date_default_timezone_set('America/Sao_Paulo');

$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) mkdir($logDir, 0755, true);
$logFile = $logDir . '/webhook_pushinpay.log';

function logWebhookPush(string $msg): void {
    global $logFile;
    file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL, FILE_APPEND);
}

function requisicao_telegram_push(string $token, string $metodo, array $parametros = []): array {
    $url = 'https://api.telegram.org/bot' . $token . '/' . $metodo;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($parametros));
    $resposta = curl_exec($ch);
    curl_close($ch);
    return json_decode($resposta ?: '', true) ?: ['ok' => false];
}

function obter_proximo_no_push(array $links, string $idAtual, string $conector = 'output_1'): ?string {
    foreach ($links as $link) {
        if (($link['fromOperator'] ?? '') === $idAtual && ($link['fromConnector'] ?? '') === $conector) {
            return $link['toOperator'] ?? null;
        }
    }
    if ($conector !== 'output_1') {
        return obter_proximo_no_push($links, $idAtual, 'output_1');
    }
    return null;
}

function processar_bloco_push(string $token, string $idChat, array $operador, int $idUsuarioDono): void {
    $propriedades = $operador['properties'] ?? [];
    $tipo = $propriedades['type'] ?? '';

    if ($tipo === 'message') {
        $texto = trim((string)($propriedades['conteudo'] ?? $propriedades['body'] ?? ''));
        if ($texto !== '') {
            requisicao_telegram_push($token, 'sendMessage', ['chat_id' => $idChat, 'text' => $texto]);
        }
    } elseif ($tipo === 'image') {
        $caminho = $propriedades['image_path'] ?? '';
        if ($caminho) {
            if (strpos($caminho, 'uploads/') === 0) {
                $caminho = __DIR__ . '/' . $caminho;
            }
            $real = realpath($caminho);
            if ($real) {
                requisicao_telegram_push($token, 'sendPhoto', ['chat_id' => $idChat, 'photo' => $real]);
            }
        }
    } elseif ($tipo === 'botoes') {
        $texto = trim((string)($propriedades['texto'] ?? ''));
        $botoes = $propriedades['botoes'] ?? [];
        $keyboard = [];
        $currentRow = [];
        foreach ($botoes as $btnTexto) {
            $currentRow[] = ['text' => $btnTexto, 'callback_data' => $btnTexto];
            if (count($currentRow) >= 2) { $keyboard[] = $currentRow; $currentRow = []; }
        }
        if (!empty($currentRow)) $keyboard[] = $currentRow;
        requisicao_telegram_push($token, 'sendMessage', [
            'chat_id'      => $idChat,
            'text'         => $texto ?: 'Escolha:',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
    }
}

function executar_fluxo_push(string $token, string $idChat, int $botId, string $idOperadorInicial, int $idUsuarioDono, string $conector = 'output_pago'): void {
    global $pdo;
    $stmt = $pdo->prepare("SELECT f.dados_fluxograma FROM bots b JOIN fluxos f ON b.id_fluxo_conectado = f.id WHERE b.id = ?");
    $stmt->execute([$botId]);
    $dadosJson = $stmt->fetchColumn();
    if (!$dadosJson) return;

    $dadosFluxo = json_decode($dadosJson, true);
    $operadores = $dadosFluxo['operators'] ?? [];
    $links      = $dadosFluxo['links'] ?? [];

    $proximoId = obter_proximo_no_push($links, $idOperadorInicial, $conector);
    if (!$proximoId) {
        logWebhookPush("Nenhuma conexão saindo de '$conector' no bloco $idOperadorInicial.");
        return;
    }

    logWebhookPush("Executando fluxo ($conector) para chat $idChat a partir de $proximoId");

    while ($proximoId && isset($operadores[$proximoId])) {
        $operador = $operadores[$proximoId];
        processar_bloco_push($token, $idChat, $operador, $idUsuarioDono);
        $tipo = $operador['properties']['type'] ?? '';
        if ($tipo === 'botoes' || $tipo === 'pix') break;
        if ($tipo === 'delay') sleep(1);
        $proximoId = obter_proximo_no_push($links, $proximoId, 'output_1');
    }
}

/**
 * Libera ou renova acesso ao grupo. Estende da expiração atual se ainda ativo.
 */
function liberarAcessoGrupoPush(array $venda, string $tokenBot): ?string {
    global $pdo;

    $idGrupo = $venda['id_grupo_telegram'] ?? '';
    if (empty($idGrupo)) return null;

    $tempoMinutos = (int)($venda['tempo_acesso_minutos'] ?? ($venda['dias_acesso'] * 1440));

    $stmtM = $pdo->prepare("SELECT data_expiracao FROM membros_grupos WHERE id_telegram = ? AND id_grupo_telegram = ? AND bot_id = ?");
    $stmtM->execute([$venda['id_telegram'], $idGrupo, $venda['bot_id']]);
    $expiracaoAtual = $stmtM->fetchColumn();

    if ($expiracaoAtual && strtotime($expiracaoAtual) > time()) {
        $dataExpiracao = date('Y-m-d H:i:s', strtotime($expiracaoAtual) + ($tempoMinutos * 60));
    } else {
        $dataExpiracao = date('Y-m-d H:i:s', time() + ($tempoMinutos * 60));
    }

    // Revoga convite anterior para impedir reuso do mesmo link entre pessoas.
    $stmtLinkAnterior = $pdo->prepare("SELECT invite_link FROM membros_grupos WHERE id_telegram = ? AND id_grupo_telegram = ? AND bot_id = ? LIMIT 1");
    $stmtLinkAnterior->execute([$venda['id_telegram'], $idGrupo, $venda['bot_id']]);
    $linkAnterior = (string)($stmtLinkAnterior->fetchColumn() ?: '');
    if ($linkAnterior !== '') {
        requisicao_telegram_push($tokenBot, 'revokeChatInviteLink', [
            'chat_id' => $idGrupo,
            'invite_link' => $linkAnterior
        ]);
    }

    $invite = requisicao_telegram_push($tokenBot, 'createChatInviteLink', [
        'chat_id'      => $idGrupo,
        'member_limit' => 1,
        'expire_date' => time() + (15 * 60),
        'name'         => 'Venda #' . $venda['id']
    ]);

    $link = null;
    if (($invite['ok'] ?? false) && isset($invite['result']['invite_link'])) {
        $link = $invite['result']['invite_link'];
    }

    $pdo->prepare("
        INSERT INTO membros_grupos
            (id_telegram, id_grupo_telegram, bot_id, venda_id, data_expiracao, invite_link, status, criado_em)
        VALUES (?, ?, ?, ?, ?, ?, 'ativo', NOW())
        ON DUPLICATE KEY UPDATE
            status        = 'ativo',
            data_expiracao = VALUES(data_expiracao),
            venda_id      = VALUES(venda_id),
            invite_link   = COALESCE(VALUES(invite_link), invite_link),
            aviso_enviado = 0,
            em_renovacao  = 0
    ")->execute([
        $venda['id_telegram'],
        $idGrupo,
        $venda['bot_id'],
        $venda['id'],
        $dataExpiracao,
        $link
    ]);

    return $link;
}

/**
 * Remove membro do grupo por falha de pagamento recorrente.
 */
function removerMembroGrupoPush(string $idTelegram, string $idGrupo, string $token, int $membroId): void {
    global $pdo;

    requisicao_telegram_push($token, 'banChatMember', [
        'chat_id'    => $idGrupo,
        'user_id'    => $idTelegram,
        'until_date' => time() + 35
    ]);
    requisicao_telegram_push($token, 'unbanChatMember', [
        'chat_id'       => $idGrupo,
        'user_id'       => $idTelegram,
        'only_if_banned' => true
    ]);

    $pdo->prepare("UPDATE membros_grupos SET status = 'expirado' WHERE id = ?")->execute([$membroId]);

    requisicao_telegram_push($token, 'sendMessage', [
        'chat_id'    => $idTelegram,
        'text'       => "⚠️ *Seu acesso ao grupo foi encerrado.*\n\nNão conseguimos processar o pagamento da sua assinatura. Para voltar, inicie uma nova assinatura no bot.",
        'parse_mode' => 'Markdown'
    ]);
}

// ── Entrada ──────────────────────────────────────────────────────────────────

$entrada = file_get_contents('php://input');
logWebhookPush("Payload recebido: " . $entrada);

if (empty($entrada)) {
    http_response_code(200);
    exit;
}

$notificacao = json_decode($entrada, true);
if (!is_array($notificacao)) {
    // Tenta form-encoded (PushinPay envia nesse formato em alguns casos)
    parse_str($entrada, $formData);
    if (!empty($formData['id'])) {
        $notificacao = $formData;
        logWebhookPush("Payload interpretado como form-encoded.");
    } else {
        logWebhookPush("Payload inválido (não é JSON nem form-encoded).");
        http_response_code(200);
        exit;
    }
}

$status             = strtolower(trim($notificacao['status'] ?? ''));
$subscriptionStatus = strtolower(trim($notificacao['subscription_status'] ?? ''));
$transacaoId        = (string)($notificacao['id'] ?? '');
$subscriptionId     = (string)($notificacao['subscription_id'] ?? '');

if (empty($transacaoId)) {
    logWebhookPush("Payload sem 'id'. Ignorado.");
    http_response_code(200);
    exit;
}

logWebhookPush("Processando | id=$transacaoId | subscription_id=$subscriptionId | status=$status");

// ── PIX Recorrente: cobrança automática periódica ─────────────────────────────
// Quando a PushinPay debita automaticamente na recorrência, envia webhook com
// subscription_id preenchido. Precisamos encontrar a venda original pelo
// id_assinatura e renovar o acesso.
if (!empty($subscriptionId) && $status === 'paid') {
    logWebhookPush("Cobrança recorrente paga | subscription_id=$subscriptionId");

    $stmt = $pdo->prepare("SELECT v.*, b.token, b.id_usuario FROM vendas v JOIN bots b ON v.bot_id = b.id WHERE v.id_assinatura = ? ORDER BY v.id DESC LIMIT 1");
    $stmt->execute([$subscriptionId]);
    $venda = $stmt->fetch();

    if (!$venda) {
        logWebhookPush("Nenhuma venda encontrada para subscription_id=$subscriptionId.");
        http_response_code(200);
        exit;
    }

    $tokenBot   = $venda['token'];
    $idDono     = (int)$venda['id_usuario'];
    $idTelegram = $venda['id_telegram'];
    $idGrupo    = $venda['id_grupo_telegram'] ?? '';

    $link = liberarAcessoGrupoPush($venda, $tokenBot);

    $msgRenovacao = "✅ *Assinatura Renovada!*\n\nSua assinatura foi renovada automaticamente com sucesso.";
    if ($link) {
        $msgRenovacao .= "\n\nCaso tenha sido removido do grupo, use o link abaixo para voltar:\n$link";
    }
    requisicao_telegram_push($tokenBot, 'sendMessage', [
        'chat_id'    => $idTelegram,
        'text'       => $msgRenovacao,
        'parse_mode' => 'Markdown'
    ]);

    registrarAtividade($idDono, 'venda', 'Renovação Automática', "PIX Recorrente renovado para usuário $idTelegram (subscription_id=$subscriptionId).");

    http_response_code(200);
    exit;
}

// ── PIX Recorrente: cobrança automática FALHOU ou assinatura cancelada ────────
// A PushinPay sinaliza tanto pelo status da transação (status=failed/canceled/expired)
// quanto pelo status da própria recorrência (subscription_status=INACTIVE/CANCELED/EXPIRED).
// Precisamos checar os dois campos, pois nem sempre vêm juntos (ex.: já vimos
// status=paid acompanhado de subscription_status=INACTIVE quando a recorrência se encerra).
$statusFalhaTransacao    = in_array($status, ['failed', 'canceled', 'expired'], true);
$statusFalhaAssinatura   = in_array($subscriptionStatus, ['inactive', 'canceled', 'cancelled', 'expired'], true);

if (!empty($subscriptionId) && ($statusFalhaTransacao || $statusFalhaAssinatura)) {
    logWebhookPush("Cobrança recorrente FALHOU/CANCELADA (status=$status, subscription_status=$subscriptionStatus) | subscription_id=$subscriptionId");

    $stmt = $pdo->prepare("SELECT v.*, b.token, b.id_usuario FROM vendas v JOIN bots b ON v.bot_id = b.id WHERE v.id_assinatura = ? ORDER BY v.id DESC LIMIT 1");
    $stmt->execute([$subscriptionId]);
    $venda = $stmt->fetch();

    if (!$venda) {
        logWebhookPush("Nenhuma venda para subscription_id=$subscriptionId.");
        http_response_code(200);
        exit;
    }

    $tokenBot   = $venda['token'];
    $idDono     = (int)$venda['id_usuario'];
    $idTelegram = $venda['id_telegram'];
    $idGrupo    = $venda['id_grupo_telegram'] ?? '';

    // Busca membro ativo
    $stmtM = $pdo->prepare("SELECT * FROM membros_grupos WHERE id_telegram = ? AND id_grupo_telegram = ? AND bot_id = ? AND status = 'ativo'");
    $stmtM->execute([$idTelegram, $idGrupo, $venda['bot_id']]);
    $membro = $stmtM->fetch();

    if ($membro) {
        removerMembroGrupoPush($idTelegram, $idGrupo, $tokenBot, (int)$membro['id']);
    }

    registrarAtividade($idDono, 'sistema', 'Remoção por Falha', "PIX Recorrente falhou ($status) para usuário $idTelegram (subscription_id=$subscriptionId).");

    http_response_code(200);
    exit;
}

// ── PIX Único (sem subscription_id) ──────────────────────────────────────────
if ($status !== 'paid') {
    logWebhookPush("Status '$status' para transação $transacaoId — aguardando 'paid'.");
    http_response_code(200);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM vendas WHERE transacao_id = ?");
$stmt->execute([$transacaoId]);
$venda = $stmt->fetch();

if (!$venda) {
    logWebhookPush("Venda não encontrada para transacao_id=$transacaoId");
    http_response_code(200);
    exit;
}

if ($venda['status'] === 'pago') {
    logWebhookPush("Venda #{$venda['id']} já paga. Ignorando duplicata.");
    http_response_code(200);
    exit;
}

// Marca como pago
try {
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE vendas SET status = 'pago', pago_em = NOW() WHERE id = ?")->execute([$venda['id']]);
    $pdo->commit();
    logWebhookPush("Venda #{$venda['id']} marcada como PAGO.");
} catch (Exception $e) {
    $pdo->rollBack();
    logWebhookPush("Erro ao atualizar venda #{$venda['id']}: " . $e->getMessage());
    http_response_code(500);
    exit;
}

$stmtBot = $pdo->prepare("SELECT token, id_usuario FROM bots WHERE id = ?");
$stmtBot->execute([$venda['bot_id']]);
$botData  = $stmtBot->fetch();
$tokenBot = $botData['token'] ?? null;
$idDono   = (int)($botData['id_usuario'] ?? 0);

if ($idDono) {
    $valorFormatado = number_format((float)$venda['valor'], 2, ',', '.');
    registrarAtividade($idDono, 'venda', 'Venda Aprovada', "PIX PushinPay R$ {$valorFormatado} pago.");

    require_once __DIR__ . '/funcoes/traqueamento.php';
    $nomeLead = '';
    try {
        $r = $pdo->prepare("SELECT nome FROM leads WHERE id_telegram = ? AND bot_id = ?");
        $r->execute([$venda['id_telegram'], $venda['bot_id']]);
        $nomeLead = $r->fetchColumn() ?: '';
    } catch (Exception $e) {}

    enviarEventosTraqueamento($idDono, 'compra', [
        'valor'        => (float)$venda['valor'],
        'transacao_id' => $transacaoId,
        'event_id'     => $transacaoId
    ], ['id_telegram' => $venda['id_telegram'], 'first_name' => $nomeLead]);
}

if (!$tokenBot) {
    logWebhookPush("Token do bot não encontrado para venda #{$venda['id']}");
    http_response_code(200);
    exit;
}

$msg = "✅ <b>Pagamento Confirmado!</b>\n\nObrigado pela sua compra.";

if (!empty($venda['id_grupo_telegram'])) {
    $link = liberarAcessoGrupoPush($venda, $tokenBot);
    $tempoMinutos = (int)($venda['tempo_acesso_minutos'] ?? ($venda['dias_acesso'] * 1440));

    if ($link) {
        $msg .= "\n\n🚀 <b>Acesso Liberado!</b>\nClique no link abaixo para entrar no grupo exclusivo:\n\n{$link}\n\n⚠️ Este link é válido apenas para você.";
        if ($tempoMinutos < 60) {
            $msg .= "\n⏳ <b>Seu acesso expira em {$tempoMinutos} minutos.</b>";
        } elseif ($tempoMinutos < 1440) {
            $msg .= "\n⏳ <b>Seu acesso expira em " . floor($tempoMinutos / 60) . " horas.</b>";
        } else {
            $msg .= "\n⏳ <b>Seu acesso expira em " . floor($tempoMinutos / 1440) . " dias.</b>";
        }
    } else {
        $msg .= "\n\n⚠️ Não foi possível gerar o link do grupo automaticamente. O administrador entrará em contato.";
        logWebhookPush("Falha ao gerar link para venda #{$venda['id']}");
        if ($idDono) registrarAtividade($venda['bot_id'], 'sistema', 'Erro Grupo', "Falha convite PushinPay venda #{$venda['id']}");
    }
}

$respMsg = requisicao_telegram_push($tokenBot, 'sendMessage', [
    'chat_id'    => $venda['id_telegram'],
    'text'       => $msg,
    'parse_mode' => 'HTML'
]);
logWebhookPush("Mensagem enviada: " . json_encode($respMsg));

if (!empty($venda['id_operador_fluxo']) && $idDono) {
    logWebhookPush("Retomando fluxo {$venda['id_operador_fluxo']} para venda #{$venda['id']}");
    executar_fluxo_push($tokenBot, (string)$venda['id_telegram'], (int)$venda['bot_id'], $venda['id_operador_fluxo'], $idDono, 'output_pago');
}

http_response_code(200);
