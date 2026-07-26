<?php
declare(strict_types=1);

require_once 'conexao.php';
require_once 'funcoes/log.php';

// Configuração de Log
date_default_timezone_set('America/Sao_Paulo');
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) mkdir($logDir, 0755, true);
$logFile = $logDir . '/cron_pix.log';

function logCron(string $msg) {
    global $logFile;
    $date = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$date] $msg" . PHP_EOL, FILE_APPEND);
}

// Replicando funções mínimas para continuidade
function requisicao_telegram_local(string $token, string $metodo, array $parametros = []): array {
    $url = 'https://api.telegram.org/bot' . $token . '/' . $metodo;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($parametros));
    $resposta = curl_exec($ch);
    curl_close($ch);
    return json_decode($resposta ?: '', true) ?: ['ok' => false];
}

function obter_proximo_no_local(array $links, string $idAtual, string $conectorSaida = 'output_1'): ?string {
    foreach ($links as $link) {
        if (($link['fromOperator'] ?? '') === $idAtual && ($link['fromConnector'] ?? '') === $conectorSaida) {
            return $link['toOperator'] ?? null;
        }
    }
    if ($conectorSaida !== 'output_1') {
         return obter_proximo_no_local($links, $idAtual, 'output_1');
    }
    return null;
}

function processar_bloco_local(string $token, $idChat, array $operador, int $idUsuarioDono): void {
    $propriedades = $operador['properties'] ?? [];
    $tipo = $propriedades['type'] ?? '';

    if ($tipo === 'message') {
        $texto = trim((string) ($propriedades['conteudo'] ?? $propriedades['body'] ?? ''));
        if ($texto !== '') {
            requisicao_telegram_local($token, 'sendMessage', ['chat_id' => $idChat, 'text' => $texto]);
        }
    } elseif ($tipo === 'image') {
        $caminho = $propriedades['image_path'] ?? '';
        if ($caminho) {
            if (strpos($caminho, 'uploads/') === 0) {
                 $caminho = __DIR__ . '/' . $caminho;
            }
            $real = realpath($caminho);
            if ($real) {
                requisicao_telegram_local($token, 'sendPhoto', ['chat_id' => $idChat, 'photo' => $real]);
            }
        }
    } elseif ($tipo === 'botoes') {
        $texto = trim((string) ($propriedades['texto'] ?? ''));
        $botoes = $propriedades['botoes'] ?? [];
        $keyboard = [];
        $currentRow = [];
        foreach ($botoes as $btnTexto) {
            $currentRow[] = ['text' => $btnTexto, 'callback_data' => $btnTexto];
            if (count($currentRow) >= 2) { $keyboard[] = $currentRow; $currentRow = []; }
        }
        if (!empty($currentRow)) $keyboard[] = $currentRow;
        
        requisicao_telegram_local($token, 'sendMessage', [
            'chat_id' => $idChat,
            'text' => $texto ?: 'Escolha:',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
    }
}

function executar_fluxo_continuacao(string $token, string $idChat, int $botId, string $idOperadorInicial, int $idUsuarioDono, string $conector = 'output_pago') {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT f.dados_fluxograma FROM bots b JOIN fluxos f ON b.id_fluxo_conectado = f.id WHERE b.id = ?");
    $stmt->execute([$botId]);
    $dadosJson = $stmt->fetchColumn();
    
    if (!$dadosJson) return;
    
    $dadosFluxo = json_decode($dadosJson, true);
    $operadores = $dadosFluxo['operators'] ?? [];
    $links = $dadosFluxo['links'] ?? [];
    
    $proximoId = obter_proximo_no_local($links, $idOperadorInicial, $conector);
    
    if (!$proximoId) {
        logCron("Nenhuma conexão saindo de '$conector' no bloco $idOperadorInicial.");
        return;
    }
    
    logCron("Executando fluxo ($conector) para chat $idChat a partir de $proximoId");
    
    while ($proximoId && isset($operadores[$proximoId])) {
        $operador = $operadores[$proximoId];
        processar_bloco_local($token, $idChat, $operador, $idUsuarioDono);
        
        $tipo = $operador['properties']['type'] ?? '';
        if ($tipo === 'botoes' || $tipo === 'pix') break;
        
        if ($tipo === 'delay') sleep(1);

        $proximoId = obter_proximo_no_local($links, $proximoId, 'output_1');
    }
}

require_once __DIR__ . '/funcoes/gateways.php';
require_once __DIR__ . '/funcoes/infopago_split.php';

// 1. VERIFICAR PAGAMENTOS PENDENTES
$sqlPendentes = "
    SELECT v.*, b.token, b.id_usuario as id_dono 
    FROM vendas v 
    JOIN bots b ON v.bot_id = b.id
    WHERE v.status = 'gerado' 
    AND v.transacao_id IS NOT NULL
";
$stmt = $pdo->query($sqlPendentes);
$vendasPendentes = $stmt->fetchAll();

$pagosCount = 0;

foreach ($vendasPendentes as $venda) {
    $gatewayConfig = null;
    $nomeGateway = null;

    if (!empty($venda['id_gateway'])) {
        $stmtGw = $pdo->prepare("SELECT nome FROM gateways WHERE id = ?");
        $stmtGw->execute([$venda['id_gateway']]);
        $nomeGateway = $stmtGw->fetchColumn();
        if ($nomeGateway) {
            $gatewayConfig = getUserGatewayConfig((int)$venda['id_dono'], $nomeGateway);
        }
    }

    if (!$gatewayConfig) {
        $gatewaysUsuario = getUserGateways((int)$venda['id_dono'], true);
        if (!empty($gatewaysUsuario)) {
            $gatewayConfig = $gatewaysUsuario[0];
            $nomeGateway = $gatewayConfig['gateway_nome'] ?? null;
        }
    }

    if (!$gatewayConfig || !$nomeGateway) {
        logCron("Venda #{$venda['id']} sem gateway válido configurado. Ignorando.");
        continue;
    }

    $provedor = resolveGatewayProvider($nomeGateway, $gatewayConfig);
    if (!$provedor) {
        logCron("Venda #{$venda['id']} gateway $nomeGateway não suportado.");
        continue;
    }

    try {
        $resp = $provedor->consultarCobranca($venda['transacao_id']);
        $statusPagamento = strtoupper(trim($resp['dados']['status'] ?? $resp['dados']['statusCob'] ?? ''));

        if ($resp['sucesso'] && in_array($statusPagamento, ['CONCLUIDA', 'PAGO', 'LIQUIDADO', 'PAID', 'APPROVED', 'COMPLETED'])) {
            logCron("Venda #{$venda['id']} encontrada como PAGA no gateway $nomeGateway.");

            $pagoEm = date('Y-m-d H:i:s');
            $pdo->prepare("UPDATE vendas SET status = 'pago', pago_em = ? WHERE id = ?")->execute([$pagoEm, $venda['id']]);
            $pagosCount++;

            if ($nomeGateway === 'infopago') {
                dispararSplitInfopago((int)$venda['id_dono'], (float)$venda['valor'], (string)$venda['transacao_id']);
            }

            // Libera acesso (Mensagem Padrão)
            $msg = "✅ *Pagamento Confirmado!*\n\nObrigado pela sua compra.";

            if (!empty($venda['id_grupo_telegram'])) {
                $idGrupo = $venda['id_grupo_telegram'];
                $tempoMinutos = (int)($venda['tempo_acesso_minutos'] ?? ($venda['dias_acesso'] * 1440));
                // Revoga link anterior do usuário para evitar compartilhamento/reuso.
                $stmtLinkAnterior = $pdo->prepare("SELECT invite_link FROM membros_grupos WHERE id_telegram = ? AND id_grupo_telegram = ? AND bot_id = ? LIMIT 1");
                $stmtLinkAnterior->execute([$venda['id_telegram'], $idGrupo, $venda['bot_id']]);
                $linkAnterior = (string)($stmtLinkAnterior->fetchColumn() ?: '');
                if ($linkAnterior !== '') {
                    requisicao_telegram_local($venda['token'], 'revokeChatInviteLink', [
                        'chat_id' => $idGrupo,
                        'invite_link' => $linkAnterior
                    ]);
                }
                $invite = requisicao_telegram_local($venda['token'], 'createChatInviteLink', [
                    'chat_id' => $idGrupo,
                    'member_limit' => 1,
                    'expire_date' => time() + (15 * 60),
                    'name' => 'Venda #' . $venda['id']
                ]);

                if (($invite['ok'] ?? false) && isset($invite['result']['invite_link'])) {
                    $link = $invite['result']['invite_link'];
                    $dataExpiracao = date('Y-m-d H:i:s', strtotime("+$tempoMinutos minutes"));

                    // Busca expiração atual para extensão correta em renovações
                    $stmtMembroAtual = $pdo->prepare("SELECT data_expiracao FROM membros_grupos WHERE id_telegram = ? AND id_grupo_telegram = ? AND bot_id = ?");
                    $stmtMembroAtual->execute([$venda['id_telegram'], $idGrupo, $venda['bot_id']]);
                    $expiracaoAtual = $stmtMembroAtual->fetchColumn();
                    if ($expiracaoAtual && strtotime($expiracaoAtual) > time()) {
                        $dataExpiracao = date('Y-m-d H:i:s', strtotime($expiracaoAtual) + ($tempoMinutos * 60));
                    }

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
                    ")->execute([$venda['id_telegram'], $idGrupo, $venda['bot_id'], $venda['id'], $dataExpiracao, $link]);

                    $msg .= "\n\n🚀 *Acesso Liberado!*\nClique no link abaixo para entrar no grupo exclusivo:\n\n$link\n\n⚠️ Este link é válido apenas para você.";
                    if ($tempoMinutos < 60) {
                        $msg .= "\n⏳ *Seu acesso expira em {$tempoMinutos} minutos.*";
                    } elseif ($tempoMinutos < 1440) {
                        $horas = floor($tempoMinutos / 60);
                        $msg .= "\n⏳ *Seu acesso expira em {$horas} horas.*";
                    } else {
                        $dias = floor($tempoMinutos / 1440);
                        $msg .= "\n⏳ *Seu acesso expira em {$dias} dias.*";
                    }
                    $msg .= "\n*(Data exata: " . date('d/m/Y \\à\\s H:i', strtotime($dataExpiracao)) . ")*";
                } else {
                    $msg .= "\n\n⚠️ Não foi possível gerar o link do grupo automaticamente.";
                    $erroLink = $invite['description'] ?? 'Erro desconhecido';
                    logCron("Falha ao gerar link para venda #{$venda['id']}: $erroLink");
                }

                requisicao_telegram_local($venda['token'], 'sendMessage', ['chat_id' => $venda['id_telegram'], 'text' => $msg, 'parse_mode' => 'Markdown']);
            }

            if (!empty($venda['id_operador_fluxo'])) {
                executar_fluxo_continuacao($venda['token'], $venda['id_telegram'], $venda['bot_id'], $venda['id_operador_fluxo'], (int)$venda['id_dono'], 'output_pago');
            }
        }
    } catch (Exception $e) {
        logCron("Erro ao checar Pix {$venda['transacao_id']}: " . $e->getMessage());
    }
}

// 2. EXPIRAR NÃO PAGOS
// Usa horário do PHP (Sao_Paulo) como parâmetro para evitar mismatch de timezone com MySQL
$agoraPhp = date('Y-m-d H:i:s');
$sqlExpiradas = "
    SELECT v.*, b.token, b.id_usuario as id_dono
    FROM vendas v
    JOIN bots b ON v.bot_id = b.id
    WHERE v.status = 'gerado'
    AND TIMESTAMPDIFF(SECOND, v.criado_em, ?) >= v.tempo_expiracao_minutos * 60
    AND v.id_operador_fluxo IS NOT NULL
";
$stmt = $pdo->prepare($sqlExpiradas);
$stmt->execute([$agoraPhp]);
$vendasExpiradas = $stmt->fetchAll();

foreach ($vendasExpiradas as $venda) {
    logCron("Processando expiração venda #{$venda['id']}");
    $pdo->prepare("UPDATE vendas SET status = 'expirado' WHERE id = ?")->execute([$venda['id']]);
    
    if ($venda['token']) {
        executar_fluxo_continuacao($venda['token'], $venda['id_telegram'], $venda['bot_id'], $venda['id_operador_fluxo'], (int)$venda['id_dono'], 'output_nao_pago');
    }
}

echo "Cron Pix executado. Pagos encontrados: $pagosCount. Expirados processados: " . count($vendasExpiradas);
