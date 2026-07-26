<?php
declare(strict_types=1);

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/funcoes/log.php';
require_once __DIR__ . '/funcoes/infopago_banco.php';
require_once __DIR__ . '/funcoes/infopago_split.php';
require_once __DIR__ . '/funcoes/gateways.php';

date_default_timezone_set('America/Sao_Paulo');

$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) mkdir($logDir, 0755, true);
$logFile = $logDir . '/webhook_infopago.log';

function logWebhookInfopago(string $msg): void {
    global $logFile;
    file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL, FILE_APPEND);
}

function requisicao_telegram_infopago(string $token, string $metodo, array $parametros = []): array {
    $url = 'https://api.telegram.org/bot' . $token . '/' . $metodo;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($parametros));
    $resposta = curl_exec($ch);
    curl_close($ch);
    return json_decode($resposta ?: '', true) ?: ['ok' => false];
}

function obter_proximo_no_infopago(array $links, string $idAtual, string $conectorSaida = 'output_1'): ?string {
    foreach ($links as $link) {
        if (($link['fromOperator'] ?? '') === $idAtual && ($link['fromConnector'] ?? '') === $conectorSaida) {
            return $link['toOperator'] ?? null;
        }
    }
    if ($conectorSaida !== 'output_1') {
        return obter_proximo_no_infopago($links, $idAtual, 'output_1');
    }
    return null;
}

function processar_bloco_infopago(string $token, $idChat, array $operador, int $idUsuarioDono): void {
    $propriedades = $operador['properties'] ?? [];
    $tipo = $propriedades['type'] ?? '';

    if ($tipo === 'message') {
        $texto = trim((string)($propriedades['conteudo'] ?? $propriedades['body'] ?? ''));
        if ($texto !== '') {
            requisicao_telegram_infopago($token, 'sendMessage', ['chat_id' => $idChat, 'text' => $texto]);
        }
    } elseif ($tipo === 'image') {
        $caminho = $propriedades['image_path'] ?? '';
        if ($caminho) {
            if (strpos($caminho, 'uploads/') === 0) {
                $caminho = __DIR__ . '/' . $caminho;
            }
            $real = realpath($caminho);
            if ($real) {
                requisicao_telegram_infopago($token, 'sendPhoto', ['chat_id' => $idChat, 'photo' => $real]);
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
        requisicao_telegram_infopago($token, 'sendMessage', [
            'chat_id'      => $idChat,
            'text'         => $texto ?: 'Escolha:',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
    }
}

function executar_fluxo_infopago(string $token, string $idChat, int $botId, string $idOperadorInicial, int $idUsuarioDono, string $conector = 'output_pago'): void {
    global $pdo;
    $stmt = $pdo->prepare("SELECT f.dados_fluxograma FROM bots b JOIN fluxos f ON b.id_fluxo_conectado = f.id WHERE b.id = ?");
    $stmt->execute([$botId]);
    $dadosJson = $stmt->fetchColumn();
    if (!$dadosJson) return;

    $dadosFluxo = json_decode($dadosJson, true);
    $operadores = $dadosFluxo['operators'] ?? [];
    $links      = $dadosFluxo['links'] ?? [];

    $proximoId = obter_proximo_no_infopago($links, $idOperadorInicial, $conector);
    if (!$proximoId) {
        logWebhookInfopago("Nenhuma conexão saindo de '$conector' no bloco $idOperadorInicial.");
        return;
    }

    logWebhookInfopago("Executando fluxo ($conector) para chat $idChat a partir de $proximoId");

    while ($proximoId && isset($operadores[$proximoId])) {
        $operador = $operadores[$proximoId];
        processar_bloco_infopago($token, $idChat, $operador, $idUsuarioDono);
        $tipo = $operador['properties']['type'] ?? '';
        if ($tipo === 'botoes' || $tipo === 'pix') break;
        if ($tipo === 'delay') sleep(1);
        $proximoId = obter_proximo_no_infopago($links, $proximoId, 'output_1');
    }
}

/**
 * Libera ou renova acesso ao grupo. Estende da expiração atual se ainda ativo.
 */
function liberarAcessoGrupoInfopago(array $venda, string $tokenBot): ?string {
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
        requisicao_telegram_infopago($tokenBot, 'revokeChatInviteLink', [
            'chat_id' => $idGrupo,
            'invite_link' => $linkAnterior
        ]);
    }

    $invite = requisicao_telegram_infopago($tokenBot, 'createChatInviteLink', [
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

// ── Entrada ──────────────────────────────────────────────────────────────────

$entrada = file_get_contents('php://input');

if (empty($entrada)) {
    // Ping de validação de URL (padrão comum entre PSPs Bacen antes de aceitar o cadastro do webhook).
    logWebhookInfopago("Ping de validação recebido.");
    http_response_code(200);
    exit;
}

logWebhookInfopago("Payload recebido: " . $entrada);

$notificacao = json_decode($entrada, true);

if (!is_array($notificacao)) {
    logWebhookInfopago("Payload inválido (não é JSON).");
    http_response_code(200);
    exit;
}

// ── PIX Automático (Recorrente) — campo 'cobsr' ──────────
// [A CONFIRMAR] formato exato ainda não testado com uma cobrança recorrente real.
if (isset($notificacao['cobsr'])) {
    foreach ($notificacao['cobsr'] as $cobsr) {
        $idRec  = $cobsr['idRec'] ?? '';
        $status = strtoupper(trim($cobsr['status'] ?? ''));

        if (empty($idRec)) {
            logWebhookInfopago("cobsr sem idRec. Ignorado.");
            continue;
        }

        logWebhookInfopago("PIX Automático | idRec=$idRec | status=$status");

        $stmt = $pdo->prepare("SELECT v.*, b.token, b.id_usuario FROM vendas v JOIN bots b ON v.bot_id = b.id WHERE v.id_assinatura = ? ORDER BY v.id DESC LIMIT 1");
        $stmt->execute([$idRec]);
        $venda = $stmt->fetch();

        if (!$venda) {
            logWebhookInfopago("Nenhuma venda encontrada para idRec=$idRec.");
            continue;
        }

        $tokenBot   = $venda['token'];
        $idDono     = (int)$venda['id_usuario'];
        $idTelegram = $venda['id_telegram'];
        $idGrupo    = $venda['id_grupo_telegram'] ?? '';

        $stmtMembro = $pdo->prepare("SELECT * FROM membros_grupos WHERE id_telegram = ? AND id_grupo_telegram = ? AND bot_id = ?");
        $stmtMembro->execute([$idTelegram, $idGrupo, $venda['bot_id']]);
        $membro = $stmtMembro->fetch();

        if ($status === 'ATIVA') {
            $txidRenovacao = (string)($cobsr['txid'] ?? $idRec);

            if (!empty($venda['ultimo_txid_renovacao']) && $venda['ultimo_txid_renovacao'] === $txidRenovacao) {
                logWebhookInfopago("Renovação idRec=$idRec txid=$txidRenovacao já processada. Ignorando duplicata (reenvio de webhook).");
                continue;
            }

            logWebhookInfopago("Cobrança recorrente PAGA para idRec=$idRec. Renovando acesso do usuário $idTelegram.");

            $pdo->prepare("UPDATE vendas SET ultimo_txid_renovacao = ? WHERE id = ?")->execute([$txidRenovacao, $venda['id']]);

            $link = liberarAcessoGrupoInfopago($venda, $tokenBot);
            dispararSplitInfopago($idDono, (float)$venda['valor'], $txidRenovacao);

            $msgRenovacao = "✅ *Assinatura Renovada!*\n\nSua assinatura foi renovada automaticamente com sucesso.";
            if ($link) {
                $msgRenovacao .= "\n\nCaso tenha sido removido do grupo, use o link abaixo para voltar:\n$link";
            }
            requisicao_telegram_infopago($tokenBot, 'sendMessage', [
                'chat_id' => $idTelegram,
                'text' => $msgRenovacao,
                'parse_mode' => 'Markdown'
            ]);

            registrarAtividade($idDono, 'venda', 'Renovação Automática', "PIX Automático InfoPago renovado para usuário $idTelegram (idRec=$idRec).");

        } elseif (in_array($status, ['REJEITADA', 'CANCELADA', 'EXPIRADA'])) {
            logWebhookInfopago("Cobrança recorrente FALHOU ($status) para idRec=$idRec. Removendo usuário $idTelegram.");

            if ($membro && $membro['status'] === 'ativo') {
                requisicao_telegram_infopago($tokenBot, 'banChatMember', ['chat_id' => $idGrupo, 'user_id' => $idTelegram, 'until_date' => time() + 35]);
                requisicao_telegram_infopago($tokenBot, 'unbanChatMember', ['chat_id' => $idGrupo, 'user_id' => $idTelegram, 'only_if_banned' => true]);
                $pdo->prepare("UPDATE membros_grupos SET status = 'expirado' WHERE id = ?")->execute([$membro['id']]);
                requisicao_telegram_infopago($tokenBot, 'sendMessage', [
                    'chat_id' => $idTelegram,
                    'text' => "⚠️ *Seu acesso ao grupo foi encerrado.*\n\nNão conseguimos processar o pagamento da sua assinatura. Para voltar, inicie uma nova assinatura no bot.",
                    'parse_mode' => 'Markdown'
                ]);
            }

            registrarAtividade($idDono, 'sistema', 'Remoção por Falha', "PIX Automático InfoPago falhou ($status) para usuário $idTelegram (idRec=$idRec).");
        } else {
            logWebhookInfopago("Status '$status' para idRec=$idRec não requer ação imediata.");
        }
    }

    http_response_code(200);
    exit;
}

// ── PIX comum (pago via webhook) — campo 'pix', padrão Bacen ──────────────────
if (!isset($notificacao['pix'])) {
    logWebhookInfopago("Payload sem 'pix' nem 'cobsr'. Ignorado.");
    http_response_code(200);
    exit;
}

foreach ($notificacao['pix'] as $pix) {
    $txid       = $pix['txid'] ?? '';
    $valor      = $pix['valor'] ?? 0;
    $endToEndId = $pix['endToEndId'] ?? '';

    if (empty($txid)) {
        logWebhookInfopago("PIX sem txid. Ignorado.");
        continue;
    }

    logWebhookInfopago("Processando PIX TXID=$txid | Valor=$valor | endToEndId=$endToEndId");

    $stmt = $pdo->prepare("SELECT * FROM vendas WHERE transacao_id = ?");
    $stmt->execute([$txid]);
    $venda = $stmt->fetch();

    if (!$venda) {
        logWebhookInfopago("Venda não encontrada para TXID=$txid.");
        continue;
    }

    if ($venda['status'] === 'pago') {
        logWebhookInfopago("Venda #{$venda['id']} já paga. Ignorando duplicata.");
        continue;
    }

    // Marca como pago
    try {
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE vendas SET status = 'pago', pago_em = NOW() WHERE id = ?")->execute([$venda['id']]);
        $pdo->commit();
        logWebhookInfopago("Venda #{$venda['id']} marcada como PAGO.");
    } catch (Exception $e) {
        $pdo->rollBack();
        logWebhookInfopago("Erro ao atualizar venda #{$venda['id']}: " . $e->getMessage());
        continue;
    }

    $stmtBot = $pdo->prepare("SELECT token, id_usuario FROM bots WHERE id = ?");
    $stmtBot->execute([$venda['bot_id']]);
    $botData  = $stmtBot->fetch();
    $tokenBot = $botData['token'] ?? null;
    $idDono   = (int)($botData['id_usuario'] ?? 0);

    if ($idDono) {
        $valorFmt = number_format((float)$venda['valor'], 2, ',', '.');
        registrarAtividade($idDono, 'venda', 'Venda Aprovada', "PIX InfoPago R$ {$valorFmt} pago (TXID=$txid).");

        dispararSplitInfopago($idDono, (float)$venda['valor'], $txid);

        require_once __DIR__ . '/funcoes/traqueamento.php';
        $nomeLead = '';
        try {
            $r = $pdo->prepare("SELECT nome FROM leads WHERE id_telegram = ? AND bot_id = ?");
            $r->execute([$venda['id_telegram'], $venda['bot_id']]);
            $nomeLead = $r->fetchColumn() ?: '';
        } catch (Exception $e) {}

        enviarEventosTraqueamento($idDono, 'compra', [
            'valor'        => (float)$venda['valor'],
            'transacao_id' => $txid,
            'event_id'     => $txid
        ], ['id_telegram' => $venda['id_telegram'], 'first_name' => $nomeLead]);
    }

    if (!$tokenBot) {
        logWebhookInfopago("Token do bot não encontrado para venda #{$venda['id']}.");
        continue;
    }

    $msg = "✅ <b>Pagamento Confirmado!</b>\n\nObrigado pela sua compra.";

    if (!empty($venda['id_grupo_telegram'])) {
        $link = liberarAcessoGrupoInfopago($venda, $tokenBot);
        $tempoMinutos = (int)($venda['tempo_acesso_minutos'] ?? ($venda['dias_acesso'] * 1440));

        if ($link) {
            $msg .= "\n\n🚀 <b>Acesso Liberado!</b>\nClique no link abaixo para entrar no grupo exclusivo:\n\n$link\n\n⚠️ Este link é válido apenas para você.";
            if ($tempoMinutos < 60) {
                $msg .= "\n⏳ <b>Seu acesso expira em {$tempoMinutos} minutos.</b>";
            } elseif ($tempoMinutos < 1440) {
                $msg .= "\n⏳ <b>Seu acesso expira em " . floor($tempoMinutos / 60) . " horas.</b>";
            } else {
                $msg .= "\n⏳ <b>Seu acesso expira em " . floor($tempoMinutos / 1440) . " dias.</b>";
            }
        } else {
            $msg .= "\n\n⚠️ Não foi possível gerar o link do grupo automaticamente. O administrador entrará em contato.";
            logWebhookInfopago("Falha ao gerar link para venda #{$venda['id']}.");
        }
    }

    $respMsg = requisicao_telegram_infopago($tokenBot, 'sendMessage', [
        'chat_id'    => $venda['id_telegram'],
        'text'       => $msg,
        'parse_mode' => 'HTML'
    ]);
    logWebhookInfopago("Mensagem enviada: " . json_encode($respMsg));

    if (!empty($venda['id_operador_fluxo']) && $idDono) {
        executar_fluxo_infopago($tokenBot, (string)$venda['id_telegram'], (int)$venda['bot_id'], $venda['id_operador_fluxo'], $idDono, 'output_pago');
    }
}

http_response_code(200);
