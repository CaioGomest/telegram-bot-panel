<?php
declare(strict_types=1);

/**
 * Pós-notificação de pagamento, comum a todos os gateways (OmegaPayments, NxGate, WiinPay...). Cada webhook_<gateway>.php (raiz) só lê o
 * payload do seu gateway e chama processarPagamentoGateway(); aqui ficam: confirmar na API do gateway, marcar a venda como paga de forma
 * atômica e tudo que vem depois (atividade, rastreio, webhooks do usuário, acesso ao grupo, mensagem, ofertas, fluxo PAGO).
 */
require_once __DIR__ . '/../conexao.php';
require_once __DIR__ . '/log.php';
require_once __DIR__ . '/gateways.php';
require_once __DIR__ . '/webhooks.php';
require_once __DIR__ . '/fluxo_blocos.php';
require_once __DIR__ . '/execucao_fluxo.php';
require_once __DIR__ . '/guiado_ofertas.php';

date_default_timezone_set('America/Sao_Paulo');

/** Define qual gateway está atendendo esta requisição (escolhe o arquivo de log: logs/webhook_<gateway>.log). */
function iniciarWebhookGateway(string $gateway): void {
    $GLOBALS['webhook_gateway_atual'] = $gateway;
    $diretorio = __DIR__ . '/../logs';
    if (!is_dir($diretorio)) mkdir($diretorio, 0755, true);
}

function logWebhookGateway(string $msg): void {
    $gateway = (string) ($GLOBALS['webhook_gateway_atual'] ?? 'gateway');
    file_put_contents(__DIR__ . '/../logs/webhook_' . $gateway . '.log', '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL, FILE_APPEND);
}

function requisicaoTelegramGateway(string $token, string $metodo, array $parametros = []): array {
    $url = 'https://api.telegram.org/bot' . $token . '/' . $metodo;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($parametros));
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $resposta = curl_exec($ch);
    $r = json_decode($resposta ?: '', true) ?: ['ok' => false];
    registrarRespostaTelegram($token, $r, $metodo);
    return $r;
}

function obterProximoNoFluxoPagamento(array $links, string $id_atual, string $conector_saida = 'output_1'): ?string {
    foreach ($links as $link) {
        if (($link['fromOperator'] ?? '') === $id_atual && ($link['fromConnector'] ?? '') === $conector_saida) {
            return $link['toOperator'] ?? null;
        }
    }
    if ($conector_saida !== 'output_1') {
        return obterProximoNoFluxoPagamento($links, $id_atual, 'output_1');
    }
    return null;
}

// Envio/mídia/Pix do fluxo pós-pagamento usam o mesmo executor de webhook.php
// (processarEEnviarBloco, em funcoes/fluxo_blocos.php) -- antes esta função tinha uma cópia
// reduzida própria que só tratava message/image/botoes e ignorava vídeo/áudio/grupo/link/Pix,
// então o fluxo "PAGO" ficava incompleto quando disparado pela confirmação de pagamento do
// gateway em vez de por uma mensagem do usuário no Telegram.

function executarFluxoPosPagamento(string $token, string $id_chat, int $bot_id, string $id_operador_inicial, int $id_usuario_dono, string $conector = 'output_pago'): void {
    global $pdo;
    $stmt = $pdo->prepare("SELECT f.id, f.dados_fluxograma FROM bots b JOIN fluxos f ON b.id_fluxo_conectado = f.id WHERE b.id = ?");
    $stmt->execute([$bot_id]);
    $fluxo_linha = $stmt->fetch();
    $dados_json = $fluxo_linha['dados_fluxograma'] ?? '';
    if (!$dados_json) return;

    $dados_fluxo = json_decode($dados_json, true);
    $operadores = $dados_fluxo['operators'] ?? [];
    $links      = $dados_fluxo['links'] ?? [];

    $proximo_id = obterProximoNoFluxoPagamento($links, $id_operador_inicial, $conector);
    if (!$proximo_id) {
        logWebhookGateway("Nenhuma conexão saindo de '$conector' no bloco $id_operador_inicial.");
        return;
    }

    logWebhookGateway("Executando fluxo ($conector) para chat $id_chat a partir de $proximo_id");

    executarFluxoDesde($token, $id_chat, $bot_id, (int) ($fluxo_linha['id'] ?? 0), $dados_fluxo, $proximo_id);
}

/**
 * Libera ou renova acesso ao grupo. Estende da expiração atual se ainda ativo.
 */
function liberarAcessoGrupoPagamento(array $venda, string $token_bot, ?int $expiracao_minima_ts = null): ?string {
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
        requisicaoTelegramGateway($token_bot, 'revokeChatInviteLink', [
            'chat_id' => $id_grupo,
            'invite_link' => $link_anterior
        ]);
    }

    $invite = requisicaoTelegramGateway($token_bot, 'createChatInviteLink', [
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

/** Pagamento aprovado sem venda correspondente (ex.: venda não gravada por queda do banco): avisa os admins no sino. */
function avisarPagamentoSemVenda(string $txid): void {
    global $pdo;
    try {
        foreach ($pdo->query("SELECT id FROM usuarios WHERE perfil = 'admin'")->fetchAll(PDO::FETCH_COLUMN) as $id_admin) {
            registrarAtividade((int) $id_admin, 'sistema', 'Pagamento sem venda', "A gateway confirmou um pagamento (código $txid) que não tem venda no sistema. Confira o cliente e libere o acesso manualmente.");
        }
    } catch (Throwable $e) {
        logWebhookGateway('Falha ao avisar admins do pagamento sem venda: ' . $e->getMessage());
    }
}

/**
 * Processa uma notificação de pagamento já decodificada pelo webhook do gateway.
 *
 * @param string     $gateway            nome do gateway no registro (omegapayments, nxgate, wiinpay)
 * @param array|null $venda              linha de vendas casada pelo identificador da transação (null = não achou)
 * @param bool       $payload_diz_pago   o payload afirma que está pago (nunca basta: a confirmação é na API)
 * @param string     $status_payload     status do payload, só para log/filtro ('' = o gateway não informa)
 * @param bool       $venda_ciclo_nova   a venda foi criada agora por um ciclo de assinatura (só OmegaPayments)
 * @param int|null   $expiracao_minima_ts piso da expiração do acesso (fim do ciclo da assinatura), se houver
 */
function processarPagamentoGateway(string $gateway, string $txid, ?array $venda, bool $payload_diz_pago, string $status_payload, bool $venda_ciclo_nova = false, ?int $expiracao_minima_ts = null): void {
    global $pdo;

    if (!$venda) {
        logWebhookGateway("Venda não encontrada para identificador=$txid.");
        if ($payload_diz_pago) {
            avisarPagamentoSemVenda($txid);
        }
        return;
    }

    if ($venda['status'] === 'pago') {
        logWebhookGateway("Venda #{$venda['id']} já paga. Ignorando duplicata.");
        return;
    }

    // Só evento de pagamento merece reconsulta na API: a OmegaPayments bloqueia polling
    // (HTTP 429, retryAfterSeconds=300), e TRANSACTION_CREATED/expiração não liberam nada.
    if ($status_payload !== '' && !$payload_diz_pago) {
        logWebhookGateway("Venda #{$venda['id']}: evento com status '$status_payload' (não é pagamento). Nada a fazer.");
        return;
    }

    // ── Confirmação direta na API do gateway ──────────────────────────
    // Nunca confiar só no payload recebido pelo webhook (qualquer um pode forjar um POST
    // pra essa URL) — antes de liberar, consulta a cobrança de verdade no gateway e
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
            $gateway_config_pre = getUserGatewayConfig($id_dono_pre, $gateway);
            $nome_gateway_pre = $gateway;
        }

        $provedor_pre = $gateway_config_pre ? resolveGatewayProvider($nome_gateway_pre, $gateway_config_pre) : null;

        if ($provedor_pre) {
            try {
                $resp_confirmacao = $provedor_pre->consultarCobranca($txid);
                $status_confirmado = strtoupper(trim($resp_confirmacao['dados']['status'] ?? ''));
                if (($resp_confirmacao['sucesso'] ?? false) && gatewayStatusPago($gateway, $status_confirmado)) {
                    $pagamento_confirmado = true;
                } else {
                    logWebhookGateway("Venda #{$venda['id']}: notificação recebida, mas a consulta direta no gateway ($gateway) não confirma pagamento (status='$status_confirmado'). Ignorando notificação — o cron de verificação vai pegar quando/se realmente for pago.");
                    // Payload forjado (API respondeu e diz que não pagou): não deixa venda filha órfã.
                    // Se a API só falhou (429/rede), a venda fica pro cron confirmar depois.
                    if ($venda_ciclo_nova && ($resp_confirmacao['sucesso'] ?? false)) {
                        $pdo->prepare("DELETE FROM vendas WHERE id = ? AND status = 'gerado'")->execute([$venda['id']]);
                        logWebhookGateway("Venda de ciclo #{$venda['id']} removida (pagamento não confirmado na API).");
                    }
                }
            } catch (\Throwable $e) {
                logWebhookGateway("Venda #{$venda['id']}: erro ao confirmar na API do gateway ($gateway) (" . $e->getMessage() . "). Não vou marcar como pago só pelo webhook — aguardando confirmação pelo cron.");
            }
        } else {
            logWebhookGateway("Venda #{$venda['id']}: sem credenciais de gateway pra confirmar o pagamento. Ignorando notificação — aguardando cron.");
        }
    }

    if (!$pagamento_confirmado) {
        return;
    }

    // Transição atômica: o UPDATE só afeta a linha se ela ainda não estava paga. Se o botão
    // manual "Já fiz o pagamento" ou o cron de fallback confirmarem a mesma venda ao mesmo
    // tempo, só um dos dois ganha a corrida (rowCount() = 1) e segue adiante.
    try {
        $stmt_marca = $pdo->prepare("UPDATE vendas SET status = 'pago', pago_em = NOW() WHERE id = ? AND status != 'pago'");
        $stmt_marca->execute([$venda['id']]);
    } catch (Exception $e) {
        logWebhookGateway("Erro ao atualizar venda #{$venda['id']}: " . $e->getMessage());
        return;
    }
    if ($stmt_marca->rowCount() === 0) {
        logWebhookGateway("Venda #{$venda['id']} já foi marcada como paga por outra requisição simultânea (botão/cron). Ignorando duplicata.");
        return;
    }
    logWebhookGateway("Venda #{$venda['id']} marcada como PAGO (confirmado direto na API do $gateway).");
    aposPagamentoConfirmado($venda);

    // Sem chamada de split pós-pagamento aqui: o split do gateway já acontece dentro
    // da própria cobrança (split no payload de criação), não tem passo separado depois.

    $stmt_bot = $pdo->prepare("SELECT token, id_usuario FROM bots WHERE id = ?");
    $stmt_bot->execute([$venda['bot_id']]);
    $bot_data  = $stmt_bot->fetch();
    $token_bot = $bot_data['token'] ?? null;
    $id_dono   = (int)($bot_data['id_usuario'] ?? 0);

    if ($id_dono) {
        $valor_fmt = number_format((float)$venda['valor'], 2, ',', '.');
        registrarAtividade($id_dono, 'venda', 'Venda Aprovada', "PIX de R$ {$valor_fmt} foi pago.");

        require_once __DIR__ . '/traqueamento.php';
        enviarEventosTraqueamento($id_dono, 'compra', [
            'valor'        => (float)$venda['valor'],
            'comissao'     => (float)($venda['comissao_admin'] ?? 0),
            'plano_id'     => $venda['id_plano'] ?? null,
            'nome_produto' => $venda['nome_produto'] ?? null,
            'pago_em'      => date('Y-m-d H:i:s'),
            'transacao_id' => $txid,
            'event_id'     => $txid,
            'criado_em'    => $venda['criado_em'] ?? null
        ], montarUserDataTraqueamento($pdo, $venda['id_telegram'], $venda['bot_id']));
        dispararWebhooks($id_dono, 'payment_approved', [
            'bot_id' => (int) $venda['bot_id'],
            'id_telegram' => (string) $venda['id_telegram'],
            'venda_id' => (int) $venda['id'],
            'transacao_id' => $txid,
            'gateway' => $gateway,
        ]);
    }

    if (!$token_bot) {
        logWebhookGateway("Token do bot não encontrado para venda #{$venda['id']}.");
        return;
    }

    $msg = $venda_ciclo_nova || !empty($venda['venda_pai_id'])
        ? "✅ <b>Renovação confirmada!</b>\n\nObrigado, sua assinatura continua ativa."
        : (($texto_confirmado = textoConfirmacaoPagamentoConfigurado($pdo, $venda)) !== null
            ? htmlspecialchars($texto_confirmado)
            : "✅ <b>Pagamento Confirmado!</b>\n\nObrigado pela sua compra.");

    $link_aprovado = null;   // link do grupo desta venda (para a mensagem da aba Pagamentos, modelo novo)
    $tempo_aprovado = 0;
    if (!empty($venda['id_grupo_telegram'])) {
        $link = liberarAcessoGrupoPagamento($venda, $token_bot, $expiracao_minima_ts);
        $tempo_minutos = (int)($venda['tempo_acesso_minutos'] ?? ($venda['dias_acesso'] * 1440));
        $link_aprovado = $link ?: null;
        $tempo_aprovado = $tempo_minutos;

        if ($link) {
            $msg .= "\n\n🚀 <b>Acesso Liberado!</b>\nClique no link abaixo para entrar no grupo exclusivo:\n\n$link\n\n⚠️ Este link é válido apenas para você.";
            if (acessoVitalicio($tempo_minutos)) {
                $msg .= "\n♾️ <b>Acesso vitalício.</b>";
            } elseif ($tempo_minutos < 60) {
                $msg .= "\n⏳ <b>Seu acesso expira em {$tempo_minutos} minutos.</b>";
            } elseif ($tempo_minutos < 1440) {
                $msg .= "\n⏳ <b>Seu acesso expira em " . floor($tempo_minutos / 60) . " horas.</b>";
            } else {
                $msg .= "\n⏳ <b>Seu acesso expira em " . floor($tempo_minutos / 1440) . " dias.</b>";
            }
        } else {
            $msg .= "\n\n⚠️ Não foi possível gerar o link do grupo automaticamente. O administrador entrará em contato.";
            logWebhookGateway("Falha ao gerar link para venda #{$venda['id']}.");
        }
    }

    // Guiado no modelo novo da aba Pagamentos: mensagem, mídias e botão de acesso escolhidos pelo dono (funcoes/pagamentos_guiado.php). Renovação/ciclo de assinatura
    // e qualquer outro caso (Editor Visual, fluxo antigo) seguem com o texto de sempre.
    $aprovacao_nova = !$venda_ciclo_nova && empty($venda['venda_pai_id'])
        && pagamentoEnviarAprovacao($pdo, $token_bot, $venda, $link_aprovado, !empty($venda['id_grupo_telegram']) && $link_aprovado === null, $tempo_aprovado);
    if ($aprovacao_nova) {
        logWebhookGateway("Mensagem de aprovação enviada (modelo novo da aba Pagamentos).");
    } else {
        $msg .= textoEntregaLink($venda, 'html');   // Fluxo Guiado com entrega por link
        $resp_msg = requisicaoTelegramGateway($token_bot, 'sendMessage', [
            'chat_id'    => $venda['id_telegram'],
            'text'       => $msg,
            'parse_mode' => 'HTML'
        ]);
        logWebhookGateway("Mensagem enviada: " . json_encode($resp_msg));
    }
    ofertasPosPagamentoBasico($pdo, $token_bot, $venda);   // Guiado: Upsell/Order Bump DEPOIS de pagar

    if (!empty($venda['id_operador_fluxo']) && $id_dono) {
        executarFluxoPosPagamento($token_bot, (string)$venda['id_telegram'], (int)$venda['bot_id'], $venda['id_operador_fluxo'], $id_dono, 'output_pago');
    }
}
