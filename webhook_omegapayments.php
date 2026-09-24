<?php
declare(strict_types=1);

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/funcoes/log.php';
require_once __DIR__ . '/funcoes/omegapayments_banco.php';
require_once __DIR__ . '/funcoes/gateways.php';

date_default_timezone_set('America/Sao_Paulo');

$log_dir = __DIR__ . '/logs';
if (!is_dir($log_dir)) mkdir($log_dir, 0755, true);
$log_file = $log_dir . '/webhook_omegapayments.log';

function logWebhookOmegapayments(string $msg): void {
    global $log_file;
    file_put_contents($log_file, '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL, FILE_APPEND);
}

function requisicaoTelegramOmegapayments(string $token, string $metodo, array $parametros = []): array {
    $url = 'https://api.telegram.org/bot' . $token . '/' . $metodo;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($parametros));
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $resposta = curl_exec($ch);
    curl_close($ch);
    return json_decode($resposta ?: '', true) ?: ['ok' => false];
}

function obterProximoNoOmegapayments(array $links, string $id_atual, string $conector_saida = 'output_1'): ?string {
    foreach ($links as $link) {
        if (($link['fromOperator'] ?? '') === $id_atual && ($link['fromConnector'] ?? '') === $conector_saida) {
            return $link['toOperator'] ?? null;
        }
    }
    if ($conector_saida !== 'output_1') {
        return obterProximoNoOmegapayments($links, $id_atual, 'output_1');
    }
    return null;
}

/**
 * Resolve um caminho de mídia salvo em dados_fluxograma garantindo que o resultado fica
 * dentro de uploads/ — mesma proteção de webhook.php::resolverCaminhoUploadSeguro().
 */
function resolverCaminhoUploadSeguroOmegapayments(string $caminho): ?string {
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

function processarBlocoOmegapayments(string $token, $id_chat, array $operador, int $id_usuario_dono): void {
    $propriedades = $operador['properties'] ?? [];
    $tipo = $propriedades['type'] ?? '';

    if ($tipo === 'message') {
        $texto = trim((string)($propriedades['conteudo'] ?? $propriedades['body'] ?? ''));
        if ($texto !== '') {
            requisicaoTelegramOmegapayments($token, 'sendMessage', ['chat_id' => $id_chat, 'text' => $texto]);
        }
    } elseif ($tipo === 'image') {
        $real = resolverCaminhoUploadSeguroOmegapayments((string)($propriedades['image_path'] ?? ''));
        if ($real) {
            requisicaoTelegramOmegapayments($token, 'sendPhoto', ['chat_id' => $id_chat, 'photo' => $real]);
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
        requisicaoTelegramOmegapayments($token, 'sendMessage', [
            'chat_id'      => $id_chat,
            'text'         => $texto ?: 'Escolha:',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
    }
}

function executarFluxoOmegapayments(string $token, string $id_chat, int $bot_id, string $id_operador_inicial, int $id_usuario_dono, string $conector = 'output_pago'): void {
    global $pdo;
    $stmt = $pdo->prepare("SELECT f.dados_fluxograma FROM bots b JOIN fluxos f ON b.id_fluxo_conectado = f.id WHERE b.id = ?");
    $stmt->execute([$bot_id]);
    $dados_json = $stmt->fetchColumn();
    if (!$dados_json) return;

    $dados_fluxo = json_decode($dados_json, true);
    $operadores = $dados_fluxo['operators'] ?? [];
    $links      = $dados_fluxo['links'] ?? [];

    $proximo_id = obterProximoNoOmegapayments($links, $id_operador_inicial, $conector);
    if (!$proximo_id) {
        logWebhookOmegapayments("Nenhuma conexão saindo de '$conector' no bloco $id_operador_inicial.");
        return;
    }

    logWebhookOmegapayments("Executando fluxo ($conector) para chat $id_chat a partir de $proximo_id");

    while ($proximo_id && isset($operadores[$proximo_id])) {
        $operador = $operadores[$proximo_id];
        processarBlocoOmegapayments($token, $id_chat, $operador, $id_usuario_dono);
        $tipo = $operador['properties']['type'] ?? '';
        if ($tipo === 'botoes' || $tipo === 'pix') break;
        if ($tipo === 'delay') sleep(1);
        $proximo_id = obterProximoNoOmegapayments($links, $proximo_id, 'output_1');
    }
}

/**
 * Libera ou renova acesso ao grupo. Estende da expiração atual se ainda ativo.
 * Espelha liberarAcessoGrupoInfopago() de webhook_infopago.php.
 */
function liberarAcessoGrupoOmegapayments(array $venda, string $token_bot): ?string {
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
        requisicaoTelegramOmegapayments($token_bot, 'revokeChatInviteLink', [
            'chat_id' => $id_grupo,
            'invite_link' => $link_anterior
        ]);
    }

    $invite = requisicaoTelegramOmegapayments($token_bot, 'createChatInviteLink', [
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

/**
 * Tenta extrair o identificador da transação do payload do webhook. Schema exato do
 * corpo do webhook [A CONFIRMAR EM SANDBOX] (doc bloqueou com 403 antes de confirmar) —
 * tenta os nomes de campo mais prováveis com base na resposta já confirmada de criação
 * (transactionId/identifier), aninhados ou não. Não é um problema de segurança se o
 * palpite errar o nome do campo: a venda só é marcada como paga depois da reconsulta
 * direta na API (ver abaixo), nunca só por causa do payload recebido aqui.
 */
function extrairIdentificadorOmegapayments(array $notificacao): string {
    $candidatos = [
        $notificacao['transactionId'] ?? null,
        $notificacao['identifier'] ?? null,
        $notificacao['data']['transactionId'] ?? null,
        $notificacao['data']['identifier'] ?? null,
        $notificacao['transaction']['transactionId'] ?? null,
        $notificacao['transaction']['identifier'] ?? null,
    ];
    foreach ($candidatos as $candidato) {
        if (!empty($candidato)) {
            return (string)$candidato;
        }
    }
    return '';
}

$entrada = file_get_contents('php://input');

if (empty($entrada)) {
    // Ping de validação de URL (mesmo padrão já usado pelo webhook InfoPago).
    logWebhookOmegapayments("Ping de validação recebido.");
    http_response_code(200);
    exit;
}

logWebhookOmegapayments("Payload recebido: " . $entrada);

$notificacao = json_decode($entrada, true);

if (!is_array($notificacao)) {
    logWebhookOmegapayments("Payload inválido (não é JSON).");
    http_response_code(200);
    exit;
}

$txid = extrairIdentificadorOmegapayments($notificacao);

if (empty($txid)) {
    logWebhookOmegapayments("Notificação sem identificador de transação reconhecível. Ignorada.");
    http_response_code(200);
    exit;
}

logWebhookOmegapayments("Processando notificação | identificador=$txid");

$stmt = $pdo->prepare("SELECT * FROM vendas WHERE transacao_id = ?");
$stmt->execute([$txid]);
$venda = $stmt->fetch();

if (!$venda) {
    logWebhookOmegapayments("Venda não encontrada para identificador=$txid.");
    http_response_code(200);
    exit;
}

if ($venda['status'] === 'pago') {
    logWebhookOmegapayments("Venda #{$venda['id']} já paga. Ignorando duplicata.");
    http_response_code(200);
    exit;
}

// ── Confirmação direta na API da OmegaPayments ──────────────────────────
// Nunca confiar só no payload recebido pelo webhook (qualquer um pode forjar um POST
// pra essa URL) — antes de liberar, consulta a cobrança de verdade na OmegaPayments e
// só marca como paga se a fonte confirmar. Mesmo padrão já auditado em webhook_infopago.php.
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
        $gateway_config_pre = getUserGatewayConfig($id_dono_pre, 'omegapayments');
        $nome_gateway_pre = 'omegapayments';
    }

    $provedor_pre = $gateway_config_pre ? resolveGatewayProvider($nome_gateway_pre, $gateway_config_pre) : null;

    if ($provedor_pre) {
        try {
            $resp_confirmacao = $provedor_pre->consultarCobranca($txid);
            $status_confirmado = strtoupper(trim($resp_confirmacao['dados']['status'] ?? ''));
            if (($resp_confirmacao['sucesso'] ?? false) && in_array($status_confirmado, ['CONCLUIDA', 'PAGO', 'LIQUIDADO', 'PAID', 'APPROVED', 'COMPLETED'])) {
                $pagamento_confirmado = true;
            } else {
                logWebhookOmegapayments("Venda #{$venda['id']}: notificação recebida, mas a consulta direta na OmegaPayments não confirma pagamento (status='$status_confirmado'). Ignorando notificação — o cron de verificação vai pegar quando/se realmente for pago.");
            }
        } catch (\Throwable $e) {
            logWebhookOmegapayments("Venda #{$venda['id']}: erro ao confirmar na API OmegaPayments (" . $e->getMessage() . "). Não vou marcar como pago só pelo webhook — aguardando confirmação pelo cron.");
        }
    } else {
        logWebhookOmegapayments("Venda #{$venda['id']}: sem credenciais de gateway pra confirmar o pagamento. Ignorando notificação — aguardando cron.");
    }
}

if (!$pagamento_confirmado) {
    http_response_code(200);
    exit;
}

// Transição atômica: o UPDATE só afeta a linha se ela ainda não estava paga. Se o botão
// manual "Já fiz o pagamento" ou o cron de fallback confirmarem a mesma venda ao mesmo
// tempo, só um dos dois ganha a corrida (rowCount() = 1) e segue adiante.
try {
    $stmt_marca = $pdo->prepare("UPDATE vendas SET status = 'pago', pago_em = NOW() WHERE id = ? AND status != 'pago'");
    $stmt_marca->execute([$venda['id']]);
} catch (Exception $e) {
    logWebhookOmegapayments("Erro ao atualizar venda #{$venda['id']}: " . $e->getMessage());
    http_response_code(200);
    exit;
}
if ($stmt_marca->rowCount() === 0) {
    logWebhookOmegapayments("Venda #{$venda['id']} já foi marcada como paga por outra requisição simultânea (botão/cron). Ignorando duplicata.");
    http_response_code(200);
    exit;
}
logWebhookOmegapayments("Venda #{$venda['id']} marcada como PAGO (confirmado direto na API OmegaPayments).");

// Sem chamada de split pós-pagamento aqui: o split da OmegaPayments já aconteceu dentro
// da própria cobrança (splits[] no payload de criação), não tem passo separado depois.

$stmt_bot = $pdo->prepare("SELECT token, id_usuario FROM bots WHERE id = ?");
$stmt_bot->execute([$venda['bot_id']]);
$bot_data  = $stmt_bot->fetch();
$token_bot = $bot_data['token'] ?? null;
$id_dono   = (int)($bot_data['id_usuario'] ?? 0);

if ($id_dono) {
    $valor_fmt = number_format((float)$venda['valor'], 2, ',', '.');
    registrarAtividade($id_dono, 'venda', 'Venda Aprovada', "PIX OmegaPayments R$ {$valor_fmt} pago (identificador=$txid).");

    require_once __DIR__ . '/funcoes/traqueamento.php';
    enviarEventosTraqueamento($id_dono, 'compra', [
        'valor'        => (float)$venda['valor'],
        'comissao'     => (float)($venda['comissao_admin'] ?? 0),
        'plano_id'     => $venda['id_plano'] ?? null,
        'nome_produto' => $venda['nome_produto'] ?? null,
        'pago_em'      => $venda['pago_em'] ?? null,
        'transacao_id' => $txid,
        'event_id'     => $txid
    ], montarUserDataTraqueamento($pdo, $venda['id_telegram'], $venda['bot_id']));
}

if (!$token_bot) {
    logWebhookOmegapayments("Token do bot não encontrado para venda #{$venda['id']}.");
    http_response_code(200);
    exit;
}

$msg = "✅ <b>Pagamento Confirmado!</b>\n\nObrigado pela sua compra.";

if (!empty($venda['id_grupo_telegram'])) {
    $link = liberarAcessoGrupoOmegapayments($venda, $token_bot);
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
        logWebhookOmegapayments("Falha ao gerar link para venda #{$venda['id']}.");
    }
}

$resp_msg = requisicaoTelegramOmegapayments($token_bot, 'sendMessage', [
    'chat_id'    => $venda['id_telegram'],
    'text'       => $msg,
    'parse_mode' => 'HTML'
]);
logWebhookOmegapayments("Mensagem enviada: " . json_encode($resp_msg));

if (!empty($venda['id_operador_fluxo']) && $id_dono) {
    executarFluxoOmegapayments($token_bot, (string)$venda['id_telegram'], (int)$venda['bot_id'], $venda['id_operador_fluxo'], $id_dono, 'output_pago');
}

http_response_code(200);
