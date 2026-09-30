<?php
declare(strict_types=1);

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/funcoes/log.php';
require_once __DIR__ . '/funcoes/omegapayments_banco.php';
require_once __DIR__ . '/funcoes/gateways.php';
require_once __DIR__ . '/funcoes/webhooks.php';
require_once __DIR__ . '/funcoes/fluxo_blocos.php';

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

// Envio/mídia/Pix do fluxo pós-pagamento usam o mesmo executor de webhook.php
// (processarEEnviarBloco, em funcoes/fluxo_blocos.php) -- antes esta função tinha uma cópia
// reduzida própria que só tratava message/image/botoes e ignorava vídeo/áudio/grupo/link/Pix,
// então o fluxo "PAGO" ficava incompleto quando disparado pela confirmação de pagamento da
// OmegaPayments em vez de por uma mensagem do usuário no Telegram.

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
        processarEEnviarBloco($token, $id_chat, $operador, $proximo_id);
        $tipo = $operador['properties']['type'] ?? '';
        if (in_array($tipo, ['botoes', 'pix', 'upsell', 'downsell', 'order_bump'], true)) break;
        if ($tipo === 'delay') sleep(1);
        $proximo_id = obterProximoNoOmegapayments($links, $proximo_id, 'output_1');
    }
}

/**
 * Libera ou renova acesso ao grupo. Estende da expiração atual se ainda ativo.
 */
function liberarAcessoGrupoOmegapayments(array $venda, string $token_bot, ?int $expiracao_minima_ts = null): ?string {
    global $pdo;

    $id_grupo = $venda['id_grupo_telegram'] ?? '';
    if (empty($id_grupo)) return null;

    $tempo_minutos = (int)($venda['tempo_acesso_minutos'] ?? ($venda['dias_acesso'] * 1440));

    $stmt_m = $pdo->prepare("SELECT data_expiracao FROM membros_grupos WHERE id_telegram = ? AND id_grupo_telegram = ? AND bot_id = ?");
    $stmt_m->execute([$venda['id_telegram'], $id_grupo, $venda['bot_id']]);
    $expiracao_atual = $stmt_m->fetchColumn();

    if ($expiracao_atual && strtotime($expiracao_atual) > time()) {
        $expiracao_ts = strtotime($expiracao_atual) + ($tempo_minutos * 60);
    } else {
        $expiracao_ts = time() + ($tempo_minutos * 60);
    }
    // Assinatura: o gateway cobra por calendário (mês de 28-31 dias), mas o acesso é
    // contado em minutos fixos — sem esse piso, o acesso fica atrasado ~0,4 dia por ciclo
    // mensal e estoura a carência de 2 dias por volta do 5º mês.
    if ($expiracao_minima_ts !== null && $expiracao_minima_ts > $expiracao_ts) {
        $expiracao_ts = $expiracao_minima_ts;
    }
    $data_expiracao = date('Y-m-d H:i:s', $expiracao_ts);

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
 * Extrai o id da transação do payload do webhook. Formato real confirmado em 28/09:
 * {"event":"TRANSACTION_CREATED","transaction":{"id":...,"identifier":...,"status":...},
 * "subscription":{"id":...,"cycle":...}|null}. A venda é gravada com transaction.id
 * (o "transactionId" devolvido na criação); transaction.identifier é outro valor e
 * nunca casa, por isso fica por último.
 */
function extrairIdentificadorOmegapayments(array $notificacao): string {
    $candidatos = [
        $notificacao['transaction']['id'] ?? null,
        $notificacao['transactionId'] ?? null,
        $notificacao['data']['transactionId'] ?? null,
        $notificacao['transaction']['transactionId'] ?? null,
        $notificacao['identifier'] ?? null,
        $notificacao['data']['identifier'] ?? null,
        $notificacao['transaction']['identifier'] ?? null,
    ];
    foreach ($candidatos as $candidato) {
        if (!empty($candidato)) {
            return (string)$candidato;
        }
    }
    return '';
}

const STATUS_PAGOS_OMEGAPAYMENTS = ['CONCLUIDA', 'PAGO', 'LIQUIDADO', 'PAID', 'APPROVED', 'COMPLETED'];

/**
 * Fim do período de um ciclo de assinatura pelo calendário: startAt + cycle * intervalo.
 * Devolve null se o payload não trouxer o suficiente (nunca chuta).
 */
function fimCicloAssinaturaOmegapayments(array $assinatura): ?int {
    $inicio = strtotime((string)($assinatura['startAt'] ?? ''));
    $ciclo = (int)($assinatura['cycle'] ?? 0);
    $contagem = (int)($assinatura['intervalCount'] ?? 0);
    $unidades = ['DAYS' => 'days', 'WEEKS' => 'weeks', 'MONTHS' => 'months', 'YEARS' => 'years'];
    $unidade = $unidades[strtoupper((string)($assinatura['intervalType'] ?? ''))] ?? null;
    if (!$inicio || $ciclo < 1 || $contagem < 1 || !$unidade) {
        return null;
    }
    $passos = $ciclo * $contagem;
    if ($unidade === 'months' || $unidade === 'years') {
        // strtotime('+1 month') em 31/01 vira 03/03; o calendário do gateway cai em 28/02.
        $meses = $unidade === 'years' ? $passos * 12 : $passos;
        $data = (new DateTimeImmutable('@' . $inicio))->setTimezone(new DateTimeZone('UTC'));
        $primeiro_do_mes = $data->modify('first day of this month')->modify("+$meses months");
        $dia = min((int)$data->format('j'), (int)$primeiro_do_mes->format('t'));
        return $primeiro_do_mes->setDate((int)$primeiro_do_mes->format('Y'), (int)$primeiro_do_mes->format('n'), $dia)->getTimestamp();
    }
    $fim = strtotime('+' . $passos . ' ' . $unidade, $inicio);
    return $fim ?: null;
}

/**
 * Ciclo 2+ de uma assinatura: a OmegaPayments cria uma transação nova a cada ciclo (id
 * que a gente nunca viu). Casa pela assinatura (subscription.id == vendas.id_assinatura)
 * e cria a venda filha, pra o fluxo normal (confirmar -> estender acesso) funcionar.
 * Retorna a venda filha recém-criada, ou null se não há assinatura conhecida/já existia.
 * Não copia id_operador_fluxo de propósito: o funil de vendas não deve rodar de novo a
 * cada renovação.
 */
function criarVendaCicloOmegapayments(array $notificacao, string $txid): ?array {
    global $pdo;

    $id_assinatura = (string)($notificacao['subscription']['id'] ?? '');
    if ($id_assinatura === '') {
        return null;
    }
    $stmt = $pdo->prepare("SELECT * FROM vendas WHERE id_assinatura = ? ORDER BY id ASC LIMIT 1");
    $stmt->execute([$id_assinatura]);
    $pai = $stmt->fetch();
    if (!$pai) {
        return null;
    }

    $expira_ts = strtotime((string)($notificacao['transaction']['pixInformation']['expiresAt'] ?? ''));
    $minutos_expiracao = $expira_ts ? max(1, (int)ceil(($expira_ts - time()) / 60)) : (int)($pai['tempo_expiracao_minutos'] ?? 15);
    $valor = (float)($notificacao['transaction']['amount'] ?? $pai['valor']);

    try {
        $pdo->prepare("
            INSERT INTO vendas (id_telegram, bot_id, valor, status, transacao_id, id_grupo_telegram, dias_acesso,
                tempo_acesso_minutos, id_gateway, tempo_expiracao_minutos, criado_em, tipo_cobranca, id_assinatura,
                venda_pai_id, id_plano)
            VALUES (?, ?, ?, 'gerado', ?, ?, ?, ?, ?, ?, ?, 'assinatura', ?, ?, ?)
        ")->execute([
            $pai['id_telegram'], $pai['bot_id'], $valor, $txid, $pai['id_grupo_telegram'], $pai['dias_acesso'],
            $pai['tempo_acesso_minutos'], $pai['id_gateway'], $minutos_expiracao, date('Y-m-d H:i:s'), $id_assinatura,
            $pai['venda_pai_id'] ?: $pai['id'], $pai['id_plano'],
        ]);
    } catch (\PDOException $e) {
        // Entrega duplicada/simultânea do mesmo evento: o índice único em transacao_id barra.
        logWebhookOmegapayments("Venda de ciclo para $txid já criada por outra entrega (" . $e->getMessage() . ").");
        return null;
    }

    $stmt = $pdo->prepare("SELECT * FROM vendas WHERE transacao_id = ?");
    $stmt->execute([$txid]);
    return $stmt->fetch() ?: null;
}

/**
 * O e-mail do cliente é sintético (a plataforma não coleta), então a OmegaPayments nunca
 * consegue avisar o assinante da cobrança do próximo ciclo. Manda o PIX pelo Telegram.
 */
function avisarCobrancaCicloOmegapayments(array $venda, array $notificacao): void {
    global $pdo;

    $stmt = $pdo->prepare("SELECT token FROM bots WHERE id = ?");
    $stmt->execute([$venda['bot_id']]);
    $token_bot = $stmt->fetchColumn();
    $codigo = (string)($notificacao['transaction']['pixInformation']['qrCode'] ?? '');
    if (!$token_bot || $codigo === '') {
        logWebhookOmegapayments("Cobrança de ciclo da venda #{$venda['id']}: sem token do bot ou sem código PIX no payload, não avisei o assinante.");
        return;
    }

    $ate = strtotime((string)($notificacao['transaction']['pixInformation']['expiresAt'] ?? ''));
    $valor_fmt = number_format((float)$venda['valor'], 2, ',', '.');
    $msg = "🔄 <b>Renovação da sua assinatura</b>\n\nValor: <b>R$ {$valor_fmt}</b>";
    if ($ate) {
        $msg .= "\nPague até: <b>" . date('d/m/Y H:i', $ate) . "</b>";
    }
    $msg .= "\n\nPIX copia e cola (toque para copiar):\n<code>" . htmlspecialchars($codigo) . "</code>\n\nAssim que o pagamento for confirmado, seu acesso é renovado automaticamente.";

    $resp = requisicaoTelegramOmegapayments($token_bot, 'sendMessage', [
        'chat_id' => $venda['id_telegram'],
        'text' => $msg,
        'parse_mode' => 'HTML',
    ]);
    logWebhookOmegapayments("Cobrança de ciclo enviada ao assinante (venda #{$venda['id']}): " . json_encode($resp));
}

$entrada = file_get_contents('php://input');

if (empty($entrada)) {
    // Ping de validação de URL (corpo vazio) -- responde OK sem processar nada.
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

$status_payload = strtoupper(trim((string)($notificacao['transaction']['status'] ?? '')));
$payload_diz_pago = in_array($status_payload, STATUS_PAGOS_OMEGAPAYMENTS, true);
$assinatura_payload = is_array($notificacao['subscription'] ?? null) ? $notificacao['subscription'] : null;

$stmt = $pdo->prepare("SELECT * FROM vendas WHERE transacao_id = ?");
$stmt->execute([$txid]);
$venda = $stmt->fetch();
$venda_ciclo_nova = false;

if (!$venda && $assinatura_payload) {
    $venda = criarVendaCicloOmegapayments($notificacao, $txid);
    if ($venda) {
        $venda_ciclo_nova = true;
        logWebhookOmegapayments("Ciclo {$assinatura_payload['cycle']} da assinatura {$assinatura_payload['id']}: criada venda #{$venda['id']} (transação $txid, status do payload=$status_payload).");
        if (!$payload_diz_pago) {
            avisarCobrancaCicloOmegapayments($venda, $notificacao);
            http_response_code(200);
            exit;
        }
    }
}

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

// Só evento de pagamento merece reconsulta na API: a OmegaPayments bloqueia polling
// (HTTP 429, retryAfterSeconds=300), e TRANSACTION_CREATED/expiração não liberam nada.
if ($status_payload !== '' && !$payload_diz_pago) {
    logWebhookOmegapayments("Venda #{$venda['id']}: evento com status '$status_payload' (não é pagamento). Nada a fazer.");
    http_response_code(200);
    exit;
}

// ── Confirmação direta na API da OmegaPayments ──────────────────────────
// Nunca confiar só no payload recebido pelo webhook (qualquer um pode forjar um POST
// pra essa URL) — antes de liberar, consulta a cobrança de verdade na OmegaPayments e
// só marca como paga se a fonte confirmar.
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
            if (($resp_confirmacao['sucesso'] ?? false) && in_array($status_confirmado, STATUS_PAGOS_OMEGAPAYMENTS, true)) {
                $pagamento_confirmado = true;
            } else {
                logWebhookOmegapayments("Venda #{$venda['id']}: notificação recebida, mas a consulta direta na OmegaPayments não confirma pagamento (status='$status_confirmado'). Ignorando notificação — o cron de verificação vai pegar quando/se realmente for pago.");
                // Payload forjado (API respondeu e diz que não pagou): não deixa venda filha órfã.
                // Se a API só falhou (429/rede), a venda fica pro cron confirmar depois.
                if ($venda_ciclo_nova && ($resp_confirmacao['sucesso'] ?? false)) {
                    $pdo->prepare("DELETE FROM vendas WHERE id = ? AND status = 'gerado'")->execute([$venda['id']]);
                    logWebhookOmegapayments("Venda de ciclo #{$venda['id']} removida (pagamento não confirmado na API).");
                }
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
    dispararWebhooks($id_dono, 'payment_approved', [
        'bot_id' => (int) $venda['bot_id'],
        'id_telegram' => (string) $venda['id_telegram'],
        'venda_id' => (int) $venda['id'],
        'transacao_id' => $txid,
        'gateway' => 'omegapayments',
    ]);
}

if (!$token_bot) {
    logWebhookOmegapayments("Token do bot não encontrado para venda #{$venda['id']}.");
    http_response_code(200);
    exit;
}

$msg = $venda_ciclo_nova || !empty($venda['venda_pai_id'])
    ? "✅ <b>Renovação confirmada!</b>\n\nObrigado, sua assinatura continua ativa."
    : "✅ <b>Pagamento Confirmado!</b>\n\nObrigado pela sua compra.";

if (!empty($venda['id_grupo_telegram'])) {
    $expiracao_minima = $assinatura_payload ? fimCicloAssinaturaOmegapayments($assinatura_payload) : null;
    $link = liberarAcessoGrupoOmegapayments($venda, $token_bot, $expiracao_minima);
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
