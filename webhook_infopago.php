<?php
declare(strict_types=1);

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/funcoes/log.php';
require_once __DIR__ . '/funcoes/infopago_banco.php';
require_once __DIR__ . '/funcoes/infopago_split.php';
require_once __DIR__ . '/funcoes/gateways.php';

date_default_timezone_set('America/Sao_Paulo');

$log_dir = __DIR__ . '/logs';
if (!is_dir($log_dir)) mkdir($log_dir, 0755, true);
$log_file = $log_dir . '/webhook_infopago.log';

function logWebhookInfopago(string $msg): void {
    global $log_file;
    file_put_contents($log_file, '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL, FILE_APPEND);
}

function requisicaoTelegramInfopago(string $token, string $metodo, array $parametros = []): array {
    $url = 'https://api.telegram.org/bot' . $token . '/' . $metodo;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($parametros));
    $resposta = curl_exec($ch);
    curl_close($ch);
    return json_decode($resposta ?: '', true) ?: ['ok' => false];
}

function obterProximoNoInfopago(array $links, string $id_atual, string $conector_saida = 'output_1'): ?string {
    foreach ($links as $link) {
        if (($link['fromOperator'] ?? '') === $id_atual && ($link['fromConnector'] ?? '') === $conector_saida) {
            return $link['toOperator'] ?? null;
        }
    }
    if ($conector_saida !== 'output_1') {
        return obterProximoNoInfopago($links, $id_atual, 'output_1');
    }
    return null;
}

/**
 * Resolve um caminho de mídia salvo em dados_fluxograma garantindo que o resultado fica
 * dentro de uploads/ — mesma proteção de webhook.php::resolverCaminhoUploadSeguro(). Sem
 * isso, um image_path gravado fora do padrão de upload (ex. "config.php") seria aceito
 * sem checagem nenhuma.
 */
function resolverCaminhoUploadSeguroInfopago(string $caminho): ?string {
    if ($caminho === '' || strpos($caminho, 'uploads/') !== 0) {
        return null;
    }
    $base_real = realpath(__DIR__ . '/uploads');
    if ($base_real === false) {
        return null;
    }
    $real = realpath(__DIR__ . '/' . $caminho);
    if ($real === false) {
        return null;
    }
    if ($real !== $base_real && strpos($real, $base_real . DIRECTORY_SEPARATOR) !== 0) {
        return null;
    }
    return $real;
}

function processarBlocoInfopago(string $token, $id_chat, array $operador, int $id_usuario_dono): void {
    $propriedades = $operador['properties'] ?? [];
    $tipo = $propriedades['type'] ?? '';

    if ($tipo === 'message') {
        $texto = trim((string)($propriedades['conteudo'] ?? $propriedades['body'] ?? ''));
        if ($texto !== '') {
            requisicaoTelegramInfopago($token, 'sendMessage', ['chat_id' => $id_chat, 'text' => $texto]);
        }
    } elseif ($tipo === 'image') {
        $real = resolverCaminhoUploadSeguroInfopago((string)($propriedades['image_path'] ?? ''));
        if ($real) {
            requisicaoTelegramInfopago($token, 'sendPhoto', ['chat_id' => $id_chat, 'photo' => $real]);
        }
    } elseif ($tipo === 'botoes') {
        $texto = trim((string)($propriedades['texto'] ?? ''));
        $botoes = $propriedades['botoes'] ?? [];
        $keyboard = [];
        $current_row = [];
        foreach ($botoes as $btn_texto) {
            $current_row[] = ['text' => $btn_texto, 'callback_data' => $btn_texto];
            if (count($current_row) >= 2) { $keyboard[] = $current_row; $current_row = []; }
        }
        if (!empty($current_row)) $keyboard[] = $current_row;
        requisicaoTelegramInfopago($token, 'sendMessage', [
            'chat_id'      => $id_chat,
            'text'         => $texto ?: 'Escolha:',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
    }
}

function executarFluxoInfopago(string $token, string $id_chat, int $bot_id, string $id_operador_inicial, int $id_usuario_dono, string $conector = 'output_pago'): void {
    global $pdo;
    $stmt = $pdo->prepare("SELECT f.dados_fluxograma FROM bots b JOIN fluxos f ON b.id_fluxo_conectado = f.id WHERE b.id = ?");
    $stmt->execute([$bot_id]);
    $dados_json = $stmt->fetchColumn();
    if (!$dados_json) return;

    $dados_fluxo = json_decode($dados_json, true);
    $operadores = $dados_fluxo['operators'] ?? [];
    $links      = $dados_fluxo['links'] ?? [];

    $proximo_id = obterProximoNoInfopago($links, $id_operador_inicial, $conector);
    if (!$proximo_id) {
        logWebhookInfopago("Nenhuma conexão saindo de '$conector' no bloco $id_operador_inicial.");
        return;
    }

    logWebhookInfopago("Executando fluxo ($conector) para chat $id_chat a partir de $proximo_id");

    while ($proximo_id && isset($operadores[$proximo_id])) {
        $operador = $operadores[$proximo_id];
        processarBlocoInfopago($token, $id_chat, $operador, $id_usuario_dono);
        $tipo = $operador['properties']['type'] ?? '';
        if ($tipo === 'botoes' || $tipo === 'pix') break;
        if ($tipo === 'delay') sleep(1);
        $proximo_id = obterProximoNoInfopago($links, $proximo_id, 'output_1');
    }
}

/**
 * Libera ou renova acesso ao grupo. Estende da expiração atual se ainda ativo.
 */
function liberarAcessoGrupoInfopago(array $venda, string $token_bot): ?string {
    global $pdo;

    $id_grupo = $venda['id_grupo_telegram'] ?? '';
    if (empty($id_grupo)) return null;

    $tempo_minutos = (int)($venda['tempo_acesso_minutos'] ?? ($venda['dias_acesso'] * 1440));

    $stmt_m = $pdo->prepare("SELECT data_expiracao FROM membros_grupos WHERE id_telegram = ? AND id_grupo_telegram = ? AND bot_id = ?");
    $stmt_m->execute([$venda['id_telegram'], $id_grupo, $venda['bot_id']]);
    $expiracao_atual = $stmt_m->fetchColumn();

    if ($expiracao_atual && strtotime($expiracao_atual) > time()) {
        $data_expiracao = date('Y-m-d H:i:s', strtotime($expiracao_atual) + ($tempo_minutos * 60));
    } else {
        $data_expiracao = date('Y-m-d H:i:s', time() + ($tempo_minutos * 60));
    }

    // Revoga convite anterior para impedir reuso do mesmo link entre pessoas.
    $stmt_link_anterior = $pdo->prepare("SELECT invite_link FROM membros_grupos WHERE id_telegram = ? AND id_grupo_telegram = ? AND bot_id = ? LIMIT 1");
    $stmt_link_anterior->execute([$venda['id_telegram'], $id_grupo, $venda['bot_id']]);
    $link_anterior = (string)($stmt_link_anterior->fetchColumn() ?: '');
    if ($link_anterior !== '') {
        requisicaoTelegramInfopago($token_bot, 'revokeChatInviteLink', [
            'chat_id' => $id_grupo,
            'invite_link' => $link_anterior
        ]);
    }

    $invite = requisicaoTelegramInfopago($token_bot, 'createChatInviteLink', [
        'chat_id'      => $id_grupo,
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
        $id_grupo,
        $venda['bot_id'],
        $venda['id'],
        $data_expiracao,
        $link
    ]);

    return $link;
}

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
        $id_rec  = $cobsr['idRec'] ?? '';
        $status = strtoupper(trim($cobsr['status'] ?? ''));

        if (empty($id_rec)) {
            logWebhookInfopago("cobsr sem idRec. Ignorado.");
            continue;
        }

        logWebhookInfopago("PIX Automático | idRec=$id_rec | status=$status");

        $stmt = $pdo->prepare("SELECT v.*, b.token, b.id_usuario FROM vendas v JOIN bots b ON v.bot_id = b.id WHERE v.id_assinatura = ? ORDER BY v.id DESC LIMIT 1");
        $stmt->execute([$id_rec]);
        $venda = $stmt->fetch();

        if (!$venda) {
            logWebhookInfopago("Nenhuma venda encontrada para idRec=$id_rec.");
            continue;
        }

        $token_bot   = $venda['token'];
        $id_dono     = (int)$venda['id_usuario'];
        $id_telegram = $venda['id_telegram'];
        $id_grupo    = $venda['id_grupo_telegram'] ?? '';

        $stmt_membro = $pdo->prepare("SELECT * FROM membros_grupos WHERE id_telegram = ? AND id_grupo_telegram = ? AND bot_id = ?");
        $stmt_membro->execute([$id_telegram, $id_grupo, $venda['bot_id']]);
        $membro = $stmt_membro->fetch();

        if ($status === 'ATIVA') {
            $txid_renovacao = (string)($cobsr['txid'] ?? $id_rec);

            // Transição atômica: o UPDATE só afeta a linha se esse txid de renovação
            // ainda não tinha sido gravado. Protege contra reenvio de webhook do
            // provedor (retry) chegando em paralelo e renovando/splitando duas vezes.
            $stmt_marca_renov = $pdo->prepare("
                UPDATE vendas SET ultimo_txid_renovacao = ? WHERE id = ?
                AND (ultimo_txid_renovacao IS NULL OR ultimo_txid_renovacao != ?)
            ");
            $stmt_marca_renov->execute([$txid_renovacao, $venda['id'], $txid_renovacao]);
            if ($stmt_marca_renov->rowCount() === 0) {
                logWebhookInfopago("Renovação idRec=$id_rec txid=$txid_renovacao já processada. Ignorando duplicata (reenvio de webhook).");
                continue;
            }

            logWebhookInfopago("Cobrança recorrente PAGA para idRec=$id_rec. Renovando acesso do usuário $id_telegram.");

            $link = liberarAcessoGrupoInfopago($venda, $token_bot);
            dispararSplitInfopago($id_dono, (float)$venda['valor'], $txid_renovacao, (int)$venda['id']);

            $msg_renovacao = "✅ *Assinatura Renovada!*\n\nSua assinatura foi renovada automaticamente com sucesso.";
            if ($link) {
                $msg_renovacao .= "\n\nCaso tenha sido removido do grupo, use o link abaixo para voltar:\n$link";
            }
            requisicaoTelegramInfopago($token_bot, 'sendMessage', [
                'chat_id' => $id_telegram,
                'text' => $msg_renovacao,
                'parse_mode' => 'Markdown'
            ]);

            registrarAtividade($id_dono, 'venda', 'Renovação Automática', "PIX Automático InfoPago renovado para usuário $id_telegram (idRec=$id_rec).");

        } elseif (in_array($status, ['REJEITADA', 'CANCELADA', 'EXPIRADA'])) {
            logWebhookInfopago("Cobrança recorrente FALHOU ($status) para idRec=$id_rec. Removendo usuário $id_telegram.");

            if ($membro && $membro['status'] === 'ativo') {
                requisicaoTelegramInfopago($token_bot, 'banChatMember', ['chat_id' => $id_grupo, 'user_id' => $id_telegram, 'until_date' => time() + 35]);
                requisicaoTelegramInfopago($token_bot, 'unbanChatMember', ['chat_id' => $id_grupo, 'user_id' => $id_telegram, 'only_if_banned' => true]);
                $pdo->prepare("UPDATE membros_grupos SET status = 'expirado' WHERE id = ?")->execute([$membro['id']]);
                requisicaoTelegramInfopago($token_bot, 'sendMessage', [
                    'chat_id' => $id_telegram,
                    'text' => "⚠️ *Seu acesso ao grupo foi encerrado.*\n\nNão conseguimos processar o pagamento da sua assinatura. Para voltar, inicie uma nova assinatura no bot.",
                    'parse_mode' => 'Markdown'
                ]);
            }

            registrarAtividade($id_dono, 'sistema', 'Remoção por Falha', "PIX Automático InfoPago falhou ($status) para usuário $id_telegram (idRec=$id_rec).");
        } else {
            logWebhookInfopago("Status '$status' para idRec=$id_rec não requer ação imediata.");
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
    $end_to_end_id = $pix['endToEndId'] ?? '';

    if (empty($txid)) {
        logWebhookInfopago("PIX sem txid. Ignorado.");
        continue;
    }

    logWebhookInfopago("Processando PIX TXID=$txid | Valor=$valor | endToEndId=$end_to_end_id");

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

    // ── Confirmação direta na API do InfoPago ──────────────────────────
    // Nunca confiar só no payload recebido pelo webhook (qualquer um pode
    // forjar um POST pra essa URL) — antes de liberar, consulta a cobrança
    // de verdade na InfoPago e só marca como paga se a fonte confirmar.
    $stmt_bot_pre = $pdo->prepare("SELECT token, id_usuario FROM bots WHERE id = ?");
    $stmt_bot_pre->execute([$venda['bot_id']]);
    $bot_data_pre = $stmt_bot_pre->fetch();
    $id_dono_pre  = (int)($bot_data_pre['id_usuario'] ?? 0);

    $pagamento_confirmado = false;
    if ($id_dono_pre) {
        $nome_gateway_pre = null;
        $gateway_config_pre = null;

        if (!empty($venda['id_gateway'])) {
            $stmt_gw_pre = $pdo->prepare("SELECT nome FROM gateways WHERE id = ?");
            $stmt_gw_pre->execute([$venda['id_gateway']]);
            $nome_gateway_pre = $stmt_gw_pre->fetchColumn();
            if ($nome_gateway_pre) {
                $gateway_config_pre = getUserGatewayConfig($id_dono_pre, $nome_gateway_pre);
            }
        }
        if (!$gateway_config_pre) {
            $gateway_config_pre = getUserGatewayConfig($id_dono_pre, 'infopago');
            $nome_gateway_pre = 'infopago';
        }

        $provedor_pre = $gateway_config_pre ? resolveGatewayProvider($nome_gateway_pre, $gateway_config_pre) : null;

        if ($provedor_pre) {
            try {
                $resp_confirmacao = $provedor_pre->consultarCobranca($txid);
                $status_confirmado = strtoupper(trim($resp_confirmacao['dados']['status'] ?? ''));
                if (($resp_confirmacao['sucesso'] ?? false) && in_array($status_confirmado, ['CONCLUIDA', 'PAGO', 'LIQUIDADO', 'PAID', 'APPROVED', 'COMPLETED'])) {
                    $pagamento_confirmado = true;
                } else {
                    logWebhookInfopago("Venda #{$venda['id']}: notificação recebida, mas a consulta direta na InfoPago não confirma pagamento (status='$status_confirmado'). Ignorando notificação — o cron de verificação vai pegar quando/se realmente for pago.");
                }
            } catch (\Throwable $e) {
                logWebhookInfopago("Venda #{$venda['id']}: erro ao confirmar na API InfoPago (" . $e->getMessage() . "). Não vou marcar como pago só pelo webhook — aguardando confirmação pelo cron.");
            }
        } else {
            logWebhookInfopago("Venda #{$venda['id']}: sem credenciais de gateway pra confirmar o pagamento. Ignorando notificação — aguardando cron.");
        }
    }

    if (!$pagamento_confirmado) {
        continue;
    }

    // Transição atômica: o UPDATE só afeta a linha se ela ainda não estava paga.
    // Se o botão manual "Já fiz o pagamento" ou o cron de fallback confirmarem a
    // mesma venda ao mesmo tempo, só um dos dois ganha a corrida (rowCount() = 1)
    // e segue adiante — evita disparar o split duas vezes pra mesma venda.
    try {
        $stmt_marca = $pdo->prepare("UPDATE vendas SET status = 'pago', pago_em = NOW() WHERE id = ? AND status != 'pago'");
        $stmt_marca->execute([$venda['id']]);
    } catch (Exception $e) {
        logWebhookInfopago("Erro ao atualizar venda #{$venda['id']}: " . $e->getMessage());
        continue;
    }
    if ($stmt_marca->rowCount() === 0) {
        logWebhookInfopago("Venda #{$venda['id']} já foi marcada como paga por outra requisição simultânea (botão/cron). Ignorando duplicata.");
        continue;
    }
    logWebhookInfopago("Venda #{$venda['id']} marcada como PAGO (confirmado direto na API InfoPago).");

    $stmt_bot = $pdo->prepare("SELECT token, id_usuario FROM bots WHERE id = ?");
    $stmt_bot->execute([$venda['bot_id']]);
    $bot_data  = $stmt_bot->fetch();
    $token_bot = $bot_data['token'] ?? null;
    $id_dono   = (int)($bot_data['id_usuario'] ?? 0);

    if ($id_dono) {
        $valor_fmt = number_format((float)$venda['valor'], 2, ',', '.');
        registrarAtividade($id_dono, 'venda', 'Venda Aprovada', "PIX InfoPago R$ {$valor_fmt} pago (TXID=$txid).");

        dispararSplitInfopago($id_dono, (float)$venda['valor'], $txid, (int)$venda['id']);

        require_once __DIR__ . '/funcoes/traqueamento.php';
        $nome_lead = '';
        try {
            $r = $pdo->prepare("SELECT nome FROM leads WHERE id_telegram = ? AND bot_id = ?");
            $r->execute([$venda['id_telegram'], $venda['bot_id']]);
            $nome_lead = $r->fetchColumn() ?: '';
        } catch (Exception $e) {}

        enviarEventosTraqueamento($id_dono, 'compra', [
            'valor'        => (float)$venda['valor'],
            'transacao_id' => $txid,
            'event_id'     => $txid
        ], ['id_telegram' => $venda['id_telegram'], 'first_name' => $nome_lead]);
    }

    if (!$token_bot) {
        logWebhookInfopago("Token do bot não encontrado para venda #{$venda['id']}.");
        continue;
    }

    $msg = "✅ <b>Pagamento Confirmado!</b>\n\nObrigado pela sua compra.";

    if (!empty($venda['id_grupo_telegram'])) {
        $link = liberarAcessoGrupoInfopago($venda, $token_bot);
        $tempo_minutos = (int)($venda['tempo_acesso_minutos'] ?? ($venda['dias_acesso'] * 1440));

        if ($link) {
            $msg .= "\n\n🚀 <b>Acesso Liberado!</b>\nClique no link abaixo para entrar no grupo exclusivo:\n\n$link\n\n⚠️ Este link é válido apenas para você.";
            if ($tempo_minutos < 60) {
                $msg .= "\n⏳ <b>Seu acesso expira em {$tempo_minutos} minutos.</b>";
            } elseif ($tempo_minutos < 1440) {
                $msg .= "\n⏳ <b>Seu acesso expira em " . floor($tempo_minutos / 60) . " horas.</b>";
            } else {
                $msg .= "\n⏳ <b>Seu acesso expira em " . floor($tempo_minutos / 1440) . " dias.</b>";
            }
        } else {
            $msg .= "\n\n⚠️ Não foi possível gerar o link do grupo automaticamente. O administrador entrará em contato.";
            logWebhookInfopago("Falha ao gerar link para venda #{$venda['id']}.");
        }
    }

    $resp_msg = requisicaoTelegramInfopago($token_bot, 'sendMessage', [
        'chat_id'    => $venda['id_telegram'],
        'text'       => $msg,
        'parse_mode' => 'HTML'
    ]);
    logWebhookInfopago("Mensagem enviada: " . json_encode($resp_msg));

    if (!empty($venda['id_operador_fluxo']) && $id_dono) {
        executarFluxoInfopago($token_bot, (string)$venda['id_telegram'], (int)$venda['bot_id'], $venda['id_operador_fluxo'], $id_dono, 'output_pago');
    }
}

http_response_code(200);
