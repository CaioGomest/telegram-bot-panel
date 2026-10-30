<?php
declare(strict_types=1);

require_once __DIR__ . '/../conexao.php';
require_once __DIR__ . '/../funcoes/cron_guarda.php';
exigirChamadaDeCron('cron_verificar_pix');
require_once __DIR__ . '/../funcoes/log.php';
require_once __DIR__ . '/../funcoes/webhooks.php';

date_default_timezone_set('America/Sao_Paulo');
$log_dir = __DIR__ . '/../logs';
if (!is_dir($log_dir)) mkdir($log_dir, 0755, true);
$log_file = $log_dir . '/cron_pix.log';

function logCron(string $msg) {
    global $log_file;
    $date = date('Y-m-d H:i:s');
    file_put_contents($log_file, "[$date] $msg" . PHP_EOL, FILE_APPEND);
}

// Trava contra execução concorrente: se a rodada anterior ainda estiver processando
// (ex. lote grande de vendas pendentes deixou o cron mais lento que o intervalo do
// agendador), essa nova chamada desiste em vez de reprocessar as mesmas vendas em
// paralelo — mesmo padrão já usado em cron_remarketing.php.
$lock_file = sys_get_temp_dir() . '/cron_verificar_pix.lock';
$lock = fopen($lock_file, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    logCron("Execução anterior ainda em andamento. Encerrando essa chamada.");
    exit;
}

// Replicando funções mínimas para continuidade
function requisicaoTelegramLocal(string $token, string $metodo, array $parametros = []): array {
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

function obterProximoNoLocal(array $links, string $id_atual, string $conector_saida = 'output_1'): ?string {
    foreach ($links as $link) {
        if (($link['fromOperator'] ?? '') === $id_atual && ($link['fromConnector'] ?? '') === $conector_saida) {
            return $link['toOperator'] ?? null;
        }
    }
    if ($conector_saida !== 'output_1') {
         return obterProximoNoLocal($links, $id_atual, 'output_1');
    }
    return null;
}

// Envio/mídia/Pix do fluxo pós-pagamento usam o mesmo executor de webhook.php
// (processarEEnviarBloco, em funcoes/fluxo_blocos.php) -- antes esta função tinha uma cópia
// reduzida própria que só tratava message/image/botoes e ignorava vídeo/áudio/grupo/link/Pix,
// então o fluxo "PAGO" ficava incompleto quando disparado pelo cron em vez de por uma
// mensagem do usuário no Telegram.

function executarFluxoContinuacao(string $token, string $id_chat, int $bot_id, string $id_operador_inicial, int $id_usuario_dono, string $conector = 'output_pago') {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT f.id, f.dados_fluxograma FROM bots b JOIN fluxos f ON b.id_fluxo_conectado = f.id WHERE b.id = ?");
    $stmt->execute([$bot_id]);
    $fluxo_linha = $stmt->fetch();
    $dados_json = $fluxo_linha['dados_fluxograma'] ?? '';
    
    if (!$dados_json) return;
    
    $dados_fluxo = json_decode($dados_json, true);
    $operadores = $dados_fluxo['operators'] ?? [];
    $links = $dados_fluxo['links'] ?? [];
    
    $proximo_id = obterProximoNoLocal($links, $id_operador_inicial, $conector);
    
    if (!$proximo_id) {
        logCron("Nenhuma conexão saindo de '$conector' no bloco $id_operador_inicial.");
        return;
    }
    
    logCron("Executando fluxo ($conector) para chat $id_chat a partir de $proximo_id");
    
    executarFluxoDesde($token, $id_chat, $bot_id, (int) ($fluxo_linha['id'] ?? 0), $dados_fluxo, $proximo_id);
}

require_once __DIR__ . '/../funcoes/gateways.php';
require_once __DIR__ . '/../funcoes/fluxo_blocos.php';
require_once __DIR__ . '/../funcoes/execucao_fluxo.php';
require_once __DIR__ . '/../funcoes/guiado_ofertas.php';

// LIMIT mantém cada rodada rápida e previsível mesmo com muitas vendas pendentes
// de uma vez — o que sobrar fica pra próxima execução (roda de novo em instantes).
$sql_pendentes = "
    SELECT v.*, b.token, b.id_usuario as id_dono
    FROM vendas v
    JOIN bots b ON v.bot_id = b.id
    WHERE v.status = 'gerado'
    AND v.transacao_id IS NOT NULL
    ORDER BY v.criado_em ASC
    LIMIT 200
";
$stmt = $pdo->query($sql_pendentes);
$vendas_pendentes = $stmt->fetchAll();

$pagos_count = 0;

foreach ($vendas_pendentes as $venda) {
    $gateway_config = null;
    $nome_gateway = null;

    if (!empty($venda['id_gateway'])) {
        $stmt_gw = $pdo->prepare("SELECT nome FROM gateways WHERE id = ?");
        $stmt_gw->execute([$venda['id_gateway']]);
        $nome_gateway = $stmt_gw->fetchColumn();
        if ($nome_gateway) {
            $gateway_config = getUserGatewayConfig((int)$venda['id_dono'], $nome_gateway);
        }
    }

    if (!$gateway_config) {
        $gateways_usuario = getUserGateways((int)$venda['id_dono'], true);
        if (!empty($gateways_usuario)) {
            $gateway_config = $gateways_usuario[0];
            $nome_gateway = $gateway_config['gateway_nome'] ?? null;
        }
    }

    if (!$gateway_config || !$nome_gateway) {
        logCron("Venda #{$venda['id']} sem gateway válido configurado. Ignorando.");
        continue;
    }

    $provedor = resolveGatewayProvider($nome_gateway, $gateway_config);
    if (!$provedor) {
        logCron("Venda #{$venda['id']} gateway $nome_gateway não suportado.");
        continue;
    }

    // OmegaPayments bloqueia polling frequente (429) e manda usar o webhook, que é o caminho
    // principal. Aqui é só rede de segurança: consulta a cada 5 min em vez de todo minuto.
    if ($nome_gateway === 'omegapayments' && ((int)date('i')) % 5 !== 0) {
        continue;
    }

    try {
        $resp = $provedor->consultarCobranca($venda['transacao_id']);
        $status_pagamento = strtoupper(trim($resp['dados']['status'] ?? $resp['dados']['statusCob'] ?? ''));

        if ($resp['sucesso'] && in_array($status_pagamento, ['CONCLUIDA', 'PAGO', 'LIQUIDADO', 'PAID', 'APPROVED', 'COMPLETED'])) {
            // Transição atômica: só segue quem realmente ganha a corrida contra o
            // webhook do gateway ou o botão manual "Já fiz o pagamento" confirmando
            // a mesma venda ao mesmo tempo — evita processar a confirmação duas vezes.
            $pago_em = date('Y-m-d H:i:s');
            $stmt_marca = $pdo->prepare("UPDATE vendas SET status = 'pago', pago_em = ? WHERE id = ? AND status != 'pago'");
            $stmt_marca->execute([$pago_em, $venda['id']]);
            if ($stmt_marca->rowCount() === 0) {
                logCron("Venda #{$venda['id']} já foi marcada como paga por outra requisição simultânea (webhook/botão). Ignorando duplicata.");
                continue;
            }

            logCron("Venda #{$venda['id']} encontrada como PAGA no gateway $nome_gateway.");
            $pagos_count++;
            aposPagamentoConfirmado($venda);

            // Mesmo registro que o webhook da gateway faz (antes, pagamento achado só pelo cron não
            // aparecia em Atividade/notificações e não enviava o evento de compra pro Facebook/TikTok).
            registrarAtividade((int) $venda['id_dono'], 'venda', 'Venda Aprovada', 'PIX de R$ ' . number_format((float) $venda['valor'], 2, ',', '.') . ' foi pago.');
            require_once __DIR__ . '/../funcoes/traqueamento.php';
            enviarEventosTraqueamento((int) $venda['id_dono'], 'compra', [
                'valor'        => (float) $venda['valor'],
                'comissao'     => (float) ($venda['comissao_admin'] ?? 0),
                'plano_id'     => $venda['id_plano'] ?? null,
                'nome_produto' => $venda['nome_produto'] ?? null,
                'pago_em'      => $pago_em,
                'transacao_id' => (string) $venda['transacao_id'],
                'event_id'     => (string) $venda['transacao_id'],
                'criado_em'    => $venda['criado_em'] ?? null,
            ], montarUserDataTraqueamento($pdo, $venda['id_telegram'], $venda['bot_id']));

            dispararWebhooks((int) $venda['id_dono'], 'payment_approved', [
                'bot_id' => (int) $venda['bot_id'],
                'id_telegram' => (string) $venda['id_telegram'],
                'venda_id' => (int) $venda['id'],
                'transacao_id' => (string) $venda['transacao_id'],
                'pago_em' => $pago_em,
                'gateway' => (string) $nome_gateway,
            ]);

            $texto_confirmado = textoConfirmacaoPagamentoConfigurado($pdo, $venda);
            $msg = $texto_confirmado !== null
                ? escaparMarkdownTelegram($texto_confirmado)
                : "✅ *Pagamento Confirmado!*\n\nObrigado pela sua compra.";

            $link_aprovado = null;   // link do grupo desta venda (zerado a cada venda do laço) para a mensagem da aba Pagamentos, modelo novo
            $tempo_aprovado = 0;
            if (!empty($venda['id_grupo_telegram'])) {
                $id_grupo = $venda['id_grupo_telegram'];
                $tempo_minutos = (int)($venda['tempo_acesso_minutos'] ?? ($venda['dias_acesso'] * 1440));
                $tempo_aprovado = $tempo_minutos;
                // Revoga link anterior do usuário para evitar compartilhamento/reuso.
                $stmt_link_anterior = $pdo->prepare("SELECT invite_link FROM membros_grupos WHERE id_telegram = ? AND id_grupo_telegram = ? AND bot_id = ? LIMIT 1");
                $stmt_link_anterior->execute([$venda['id_telegram'], $id_grupo, $venda['bot_id']]);
                $link_anterior = (string)($stmt_link_anterior->fetchColumn() ?: '');
                if ($link_anterior !== '') {
                    requisicaoTelegramLocal($venda['token'], 'revokeChatInviteLink', [
                        'chat_id' => $id_grupo,
                        'invite_link' => $link_anterior
                    ]);
                }
                $invite = requisicaoTelegramLocal($venda['token'], 'createChatInviteLink', [
                    'chat_id' => $id_grupo,
                    'member_limit' => 1,
                    'expire_date' => time() + (15 * 60),
                    'name' => 'Venda #' . $venda['id']
                ]);
                $link = (($invite['ok'] ?? false) && isset($invite['result']['invite_link'])) ? $invite['result']['invite_link'] : null;
                $link_aprovado = $link;

                $data_expiracao = date('Y-m-d H:i:s', strtotime("+$tempo_minutos minutes"));

                // Busca expiração atual para extensão correta em renovações
                $stmt_membro_atual = $pdo->prepare("SELECT data_expiracao FROM membros_grupos WHERE id_telegram = ? AND id_grupo_telegram = ? AND bot_id = ?");
                $stmt_membro_atual->execute([$venda['id_telegram'], $id_grupo, $venda['bot_id']]);
                $expiracao_atual = $stmt_membro_atual->fetchColumn();
                if ($expiracao_atual && strtotime($expiracao_atual) > time()) {
                    $data_expiracao = date('Y-m-d H:i:s', strtotime($expiracao_atual) + ($tempo_minutos * 60));
                }

                // Roda incondicionalmente -- mesmo se a criação do link falhar, a venda já foi
                // marcada 'pago' e não será reprocessada por nenhum outro caminho, então o
                // acesso/expiração precisa ser gravado de qualquer forma (mesmo padrão de
                // webhook.php e webhook_omegapayments.php::liberarAcessoGrupoOmegapayments()).
                $pdo->prepare("
                    INSERT INTO membros_grupos
                        (id_telegram, id_grupo_telegram, bot_id, venda_id, data_expiracao, invite_link, status, criado_em)
                    VALUES (?, ?, ?, ?, ?, ?, 'ativo', NOW())
                    ON DUPLICATE KEY UPDATE
                        status         = 'ativo',
                        data_expiracao = VALUES(data_expiracao),
                        venda_id       = VALUES(venda_id),
                        invite_link    = COALESCE(VALUES(invite_link), invite_link),
                        aviso_enviado  = 0,
                        em_renovacao   = 0
                ")->execute([$venda['id_telegram'], $id_grupo, $venda['bot_id'], $venda['id'], $data_expiracao, $link]);

                if ($link) {
                    $msg .= "\n\n🚀 *Acesso Liberado!*\nClique no link abaixo para entrar no grupo exclusivo:\n\n$link\n\n⚠️ Este link é válido apenas para você.";
                    if (acessoVitalicio($tempo_minutos)) {
                        $msg .= "\n♾️ *Acesso vitalício.*";
                    } elseif ($tempo_minutos < 60) {
                        $msg .= "\n⏳ *Seu acesso expira em {$tempo_minutos} minutos.*";
                    } elseif ($tempo_minutos < 1440) {
                        $horas = floor($tempo_minutos / 60);
                        $msg .= "\n⏳ *Seu acesso expira em {$horas} horas.*";
                    } else {
                        $dias = floor($tempo_minutos / 1440);
                        $msg .= "\n⏳ *Seu acesso expira em {$dias} dias.*";
                    }
                    if (!acessoVitalicio($tempo_minutos)) {
                        $msg .= "\n*(Data exata: " . date('d/m/Y \\à\\s H:i', strtotime($data_expiracao)) . ")*";
                    }
                } else {
                    $msg .= "\n\n⚠️ Não foi possível gerar o link do grupo automaticamente. O administrador entrará em contato.";
                    $erro_link = $invite['description'] ?? 'Erro desconhecido';
                    logCron("Falha ao gerar link para venda #{$venda['id']}: $erro_link (acesso/expiração gravados mesmo assim)");
                }
            }

            // Fora do "if" do grupo: venda sem grupo (entrega por link ou só a mensagem) também precisa receber o aviso de pagamento confirmado,
            // como já acontece no webhook da gateway e no botão "Já fiz o pagamento".
            // Guiado no modelo novo da aba Pagamentos: mensagem, mídias e botão de acesso escolhidos pelo dono (funcoes/pagamentos_guiado.php); senão, o texto de sempre.
            if (!pagamentoEnviarAprovacao($pdo, (string) $venda['token'], $venda, $link_aprovado, !empty($venda['id_grupo_telegram']) && $link_aprovado === null, $tempo_aprovado)) {
                $msg .= textoEntregaLink($venda, 'markdown');
                requisicaoTelegramLocal($venda['token'], 'sendMessage', ['chat_id' => $venda['id_telegram'], 'text' => $msg, 'parse_mode' => 'Markdown']);
            }

            ofertasPosPagamentoBasico($pdo, (string) $venda['token'], $venda);   // Guiado: Upsell/Order Bump DEPOIS de pagar
            if (!empty($venda['id_operador_fluxo'])) {
                executarFluxoContinuacao($venda['token'], $venda['id_telegram'], $venda['bot_id'], $venda['id_operador_fluxo'], (int)$venda['id_dono'], 'output_pago');
            }
        }
    } catch (Exception $e) {
        logCron("Erro ao checar Pix {$venda['transacao_id']}: " . $e->getMessage());
    }
}

// Usa horário do PHP (Sao_Paulo) como parâmetro para evitar mismatch de timezone com MySQL
$agora_php = date('Y-m-d H:i:s');
$sql_expiradas = "
    SELECT v.*, b.token, b.id_usuario as id_dono
    FROM vendas v
    JOIN bots b ON v.bot_id = b.id
    WHERE v.status = 'gerado'
    AND TIMESTAMPDIFF(SECOND, v.criado_em, ?) >= v.tempo_expiracao_minutos * 60
    AND v.id_operador_fluxo IS NOT NULL
";
$stmt = $pdo->prepare($sql_expiradas);
$stmt->execute([$agora_php]);
$vendas_expiradas = $stmt->fetchAll();

foreach ($vendas_expiradas as $venda) {
    // AND status = 'gerado' evita expirar por engano uma venda que acabou de ser
    // confirmada como paga por outro caminho (webhook/botão) bem nesse instante.
    $stmt_expira = $pdo->prepare("UPDATE vendas SET status = 'expirado' WHERE id = ? AND status = 'gerado'");
    $stmt_expira->execute([$venda['id']]);
    if ($stmt_expira->rowCount() === 0) {
        logCron("Venda #{$venda['id']} foi paga bem antes de expirar. Ignorando expiração.");
        continue;
    }

    logCron("Processando expiração venda #{$venda['id']}");
    if ($venda['token']) {
        executarFluxoContinuacao($venda['token'], $venda['id_telegram'], $venda['bot_id'], $venda['id_operador_fluxo'], (int)$venda['id_dono'], 'output_nao_pago');
    }
}

// Cobranças de ciclo de assinatura (criadas pelo webhook, sem bloco de fluxo) que o
// assinante não pagou: expira em silêncio pra não ficarem 'gerado' pra sempre e ocuparem o
// LIMIT do polling. O acesso em si é cortado pelo cron_verificar_acessos.
// Também cobre o Pix de renovação manual do cron_renovacao (tem venda_pai_id mas não id_assinatura): antes nunca
// expirava, era consultado no gateway a cada minuto pra sempre e, por ordem de criação, ocupava o LIMIT do polling.
$stmt_ciclos = $pdo->prepare("
    UPDATE vendas SET status = 'expirado'
    WHERE status = 'gerado' AND venda_pai_id IS NOT NULL
    AND id_operador_fluxo IS NULL
    AND TIMESTAMPDIFF(SECOND, criado_em, ?) >= tempo_expiracao_minutos * 60
");
$stmt_ciclos->execute([$agora_php]);

// Vendas sem bloco de fluxo (Fluxo Guiado: planos, packs e renovação; e vendas avulsas): não há caminho
// "não pago" pra executar, então só expiram em silêncio. Sem isso ficavam 'gerado' pra sempre — inflando
// "aguardando pagamento" em Conversões e sendo consultadas na OmegaPayments a cada minuto.
$stmt_sem_fluxo = $pdo->prepare("
    UPDATE vendas SET status = 'expirado'
    WHERE status = 'gerado' AND id_operador_fluxo IS NULL AND venda_pai_id IS NULL
    AND TIMESTAMPDIFF(SECOND, criado_em, ?) >= tempo_expiracao_minutos * 60
");
$stmt_sem_fluxo->execute([$agora_php]);

flock($lock, LOCK_UN);
fclose($lock);

echo "Cron Pix executado. Pagos encontrados: $pagos_count. Expirados processados: " . count($vendas_expiradas);
