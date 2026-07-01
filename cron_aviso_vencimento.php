<?php
/**
 * Script para enviar aviso de vencimento próximo.
 * Deve rodar a cada 1 minuto.
 */

declare(strict_types=1);

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/funcoes/log.php';
require_once __DIR__ . '/funcoes/gateways.php';
require_once __DIR__ . '/funcoes/efi_banco.php';

date_default_timezone_set('America/Sao_Paulo');

$logFileAviso = __DIR__ . '/logs/cron_aviso.log';
function logAviso(string $msg): void {
    global $logFileAviso;
    $date = date('Y-m-d H:i:s');
    file_put_contents($logFileAviso, "[$date] $msg" . PHP_EOL, FILE_APPEND);
}

// Função auxiliar para Telegram (reutilizada para evitar duplicidade de código se possível, mas aqui definimos novamente para ser standalone)
function telegram_request_aviso(string $token, string $metodo, array $parametros = []): array {
    $url = 'https://api.telegram.org/bot' . $token . '/' . $metodo;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($parametros));
    $resposta = curl_exec($ch);
    curl_close($ch);
    return json_decode($resposta ?: '', true) ?: ['ok' => false];
}

$agora = date('Y-m-d H:i:s');
logAviso("Iniciando verificação de avisos de vencimento em $agora...");
echo "Iniciando verificação de avisos de vencimento em $agora...\n";

try {
    // Busca membros ativos que ainda não venceram e não foram avisados
    // Apenas pagamentos ÚNICOS (não recorrentes)
    $sql = "
        SELECT 
            m.id, m.id_telegram, m.id_grupo_telegram, m.data_expiracao, m.bot_id,
            b.token, b.nome_usuario, b.id_usuario,
            v.tipo_cobranca, v.dias_acesso, v.tempo_acesso_minutos, v.valor, v.id_operador_fluxo
        FROM membros_grupos m
        JOIN bots b ON m.bot_id = b.id
        LEFT JOIN vendas v ON m.venda_id = v.id
        WHERE 
            m.status = 'ativo' 
            AND m.data_expiracao > ? 
            AND m.aviso_enviado = 0
            AND (v.tipo_cobranca IS NULL OR v.tipo_cobranca != 'assinatura')
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$agora]);
    $membros = $stmt->fetchAll();

    if (empty($membros)) {
        logAviso("Nenhum membro precisando de aviso no momento.");
        exit;
    }

    logAviso("Encontrados " . count($membros) . " membros para análise.");

    foreach ($membros as $membro) {
        $idMembro = $membro['id'];
        $idTelegram = $membro['id_telegram'];
        $tokenBot = $membro['token'];
        $dataExpiracao = strtotime($membro['data_expiracao']);
        $tempoRestante = $dataExpiracao - time();

        $diasAcesso = (int)($membro['dias_acesso'] ?? 0);
        $minutosAcesso = (int)($membro['tempo_acesso_minutos'] ?? 0);

        logAviso("Analisando membro #$idMembro (user=$idTelegram) | data_expiracao={$membro['data_expiracao']} | tempoRestante={$tempoRestante}s | dias=$diasAcesso | minutos=$minutosAcesso");

        $deveAvisar = false;
        $mensagem = "";

        // Lógica de Aviso
        if ($diasAcesso >= 1) {
            // Se o plano é de 1 dia ou mais, avisa se faltar 24h (86400s) ou menos
            if ($tempoRestante <= 86400) {
                $deveAvisar = true;
                $horasRestantes = ceil($tempoRestante / 3600);
                $mensagem = "⚠️ *Atenção: Seu acesso vence em breve!*\n\nFaltam menos de *{$horasRestantes} horas* para seu acesso expirar.";
            }
        } elseif ($minutosAcesso > 0 || $diasAcesso == 0) {
            // Se o plano é de minutos (ou dias=0), avisa se faltar 15 minutos (900s) ou menos
            // Mas apenas se o tempo total for maior que 15 min, senão avisa com 5 min
            $limiteAviso = ($minutosAcesso > 15) ? 900 : 300; // 15 min ou 5 min
            logAviso("  -> limiteAviso={$limiteAviso}s | deveAvisar=" . ($tempoRestante <= $limiteAviso ? 'SIM' : 'NAO'));

            if ($tempoRestante <= $limiteAviso) {
                $deveAvisar = true;
                $minutosRestantes = ceil($tempoRestante / 60);
                $mensagem = "⚠️ *Atenção: Seu tempo está acabando!*\n\nFaltam apenas *{$minutosRestantes} minutos* para seu acesso expirar.";
            }
        } else {
            logAviso("  -> Nenhuma regra de aviso se aplicou (dias=$diasAcesso, minutos=$minutosAcesso)");
        }

        if ($deveAvisar) {
            logAviso("Avisando usuário $idTelegram (Restam " . round($tempoRestante/60) . " min)...");
            echo "Avisando usuário $idTelegram (Restam " . round($tempoRestante/60) . " min)...\n";
            
            $mensagem .= "\nRenove agora para não ser removido do grupo.";

            // --- Lógica de Geração de Pix para Renovação ---
            $valor = (float)($membro['valor'] ?? 0);
            $pixCopiaCola = '';
            $txid = '';
            $teclado = null;

            if ($valor > 0) {
                // Tenta gerar o Pix
                $idUsuarioDono = $membro['id_usuario'];
            $userGateways = getUserGateways((int)$idUsuarioDono, true);
            if (empty($userGateways)) {
                echo " - Erro: Nenhum gateway ativo para dono do bot.\n";
                continue;
            }

            $gatewayConfig = $userGateways[0];
            $nomeGateway = $gatewayConfig['gateway_nome'] ?? null;
            $provedor = resolveGatewayProvider($nomeGateway, $gatewayConfig);

            if ($provedor && !empty($gatewayConfig['client_id']) && !empty($gatewayConfig['client_secret']) && !empty($gatewayConfig['chave_pix'])) {
                
                    try {
                        $efi = $provedor;
                        // Configuração de Split (Admin)
                        $splitData = null;
                        $adminConfig = getAdminGatewayConfig('efi');
                        if ($adminConfig && !empty($adminConfig['chave_pix_split']) && !empty($adminConfig['taxa_split'])) {
                            $splitData = [
                                'chave' => $adminConfig['chave_pix_split'],
                                'valor' => $adminConfig['taxa_split'],
                                'tipo' => $adminConfig['tipo_split'] ?? 'percentual'
                            ];
                        }

                        // Payload com validade de 5 minutos (300 segundos)
                        $payload = $provedor->montaPayloadCobranca($valor, $gatewayConfig['chave_pix'], $splitData, 300);
                        $respPix = $provedor->criarCobranca($payload);

                        if ($respPix['sucesso']) {
                            $pixCopiaCola = $respPix['dados']['pixCopiaECola'] ?? '';
                            $txid = $respPix['dados']['txid'] ?? '';
                            
                            // Registra a nova venda "gerada" no banco para o webhook identificar
                            $dataCriacao = date('Y-m-d H:i:s');
                            
                            // Mantém os mesmos parâmetros de acesso da venda original
                            // Precisamos calcular os minutos corretamente se vier de dias
                            if ($minutosAcesso > 0) {
                                $tempoMinutosInsert = $minutosAcesso;
                                $diasAcessoInsert = 0;
                            } else {
                                $tempoMinutosInsert = $diasAcesso * 1440;
                                $diasAcessoInsert = $diasAcesso;
                            }

                            $stmtVenda = $pdo->prepare("INSERT INTO vendas (id_telegram, bot_id, valor, status, transacao_id, id_grupo_telegram, dias_acesso, tempo_acesso_minutos, id_operador_fluxo, tempo_expiracao_minutos, criado_em, tipo_cobranca) VALUES (?, ?, ?, 'gerado', ?, ?, ?, ?, ?, ?, ?, 'unica')");
                            
                            $stmtVenda->execute([
                                $idTelegram,
                                $membro['bot_id'],
                                $valor,
                                $txid,
                                $membro['id_grupo_telegram'],
                                $diasAcessoInsert,
                                $tempoMinutosInsert,
                                $membro['id_operador_fluxo'] ?? null,
                                5, // 5 minutos de expiração para pagamento
                                $dataCriacao
                            ]);

                            $mensagem .= "\n\n🔄 *Renovação Rápida*\nValor: R$ " . number_format($valor, 2, ',', '.') . "\nEste código é válido por *5 minutos*.";
                            $mensagem .= "\n\n👇 Copie o código abaixo e pague no seu banco:";
                            
                            $teclado = [
                                'inline_keyboard' => [
                                    [
                                        ['text' => 'Já fiz o pagamento ✅', 'callback_data' => 'verificar_pagamento_' . $txid]
                                    ]
                                ]
                            ];

                        } else {
                            echo " - Erro ao gerar Pix: " . ($respPix['erro'] ?? 'Desconhecido') . "\n";
                        }

                    } catch (Exception $e) {
                        echo " - Exceção ao gerar Pix: " . $e->getMessage() . "\n";
                    }
                }
            }
            // ------------------------------------------------

            // Envia mensagem principal
            $paramsMsg = [
                'chat_id' => $idTelegram,
                'text' => $mensagem,
                'parse_mode' => 'Markdown'
            ];
            
            // Se não tiver Pix Copia e Cola, o teclado vai na mensagem principal (se houvesse outro botão, mas aqui o botão é atrelado ao Pix)
            // Na verdade, vamos mandar o Pix em mensagem separada para facilitar a cópia
            
            $resp = telegram_request_aviso($tokenBot, 'sendMessage', $paramsMsg);

            if ($resp['ok'] ?? false) {
                // Se gerou Pix, manda o código Copia e Cola
                if (!empty($pixCopiaCola)) {
                    $paramsPix = [
                        'chat_id' => $idTelegram,
                        'text' => "<code>$pixCopiaCola</code>",
                        'parse_mode' => 'HTML'
                    ];
                    if ($teclado) {
                        $paramsPix['reply_markup'] = json_encode($teclado);
                    }
                    telegram_request_aviso($tokenBot, 'sendMessage', $paramsPix);
                }

                // Marca como avisado
                $upd = $pdo->prepare("UPDATE membros_grupos SET aviso_enviado = 1 WHERE id = ?");
                $upd->execute([$idMembro]);
                
                logAviso("Aviso enviado com sucesso para $idTelegram.");
                echo " - Aviso enviado com sucesso.\n";
                
                // Log
                registrarAtividade(
                    (int)$membro['bot_id'], 
                    'sistema', 
                    'Aviso de Vencimento', 
                    "Aviso enviado para usuário $idTelegram. Expira em: {$membro['data_expiracao']}"
                );
            } else {
                echo " - Falha ao enviar aviso: " . ($resp['description'] ?? 'Erro desconhecido') . "\n";
                // Se falhou (ex: bloqueou o bot), talvez devêssemos marcar como avisado para não tentar eternamente?
                $erro = $resp['description'] ?? '';
                if (strpos($erro, 'blocked') !== false || strpos($erro, 'deactivated') !== false) {
                    $pdo->prepare("UPDATE membros_grupos SET aviso_enviado = 1 WHERE id = ?")->execute([$idMembro]);
                    echo " - Usuário bloqueou o bot. Marcado como avisado para não tentar novamente.\n";
                }
            }
        }
    }

} catch (PDOException $e) {
    echo "Erro no banco de dados: " . $e->getMessage() . "\n";
}
