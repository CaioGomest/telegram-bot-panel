<?php
declare(strict_types=1);

require_once __DIR__ . '/../conexao.php';
require_once __DIR__ . '/../funcoes/log.php';
require_once __DIR__ . '/../funcoes/gateways.php';

date_default_timezone_set('America/Sao_Paulo');

// Grava toda a saída desta execução em arquivo, para permitir auditoria
// posterior de quando/se este cron rodou e o que decidiu para cada venda.
$log_file_renovacao = __DIR__ . '/../logs/cron_renovacao.log';
ob_start();
register_shutdown_function(function () use ($log_file_renovacao) {
    $conteudo = ob_get_contents();
    file_put_contents(
        $log_file_renovacao,
        '[' . date('Y-m-d H:i:s') . "] Execução iniciada\n" . $conteudo . str_repeat('-', 60) . "\n",
        FILE_APPEND
    );
});

$DIAS_ANTECEDENCIA_RENOVACAO = 3;

function telegramRequest(string $token, string $metodo, array $parametros = []): array {
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

// Trava contra execução concorrente -- mesmo padrão de cron_verificar_pix.php/cron_remarketing.php.
// Libera automaticamente ao final (inclusive em erro fatal), junto com o shutdown function do log acima.
$lock_file = sys_get_temp_dir() . '/cron_renovacao.lock';
$lock = fopen($lock_file, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo "Execução anterior ainda em andamento. Encerrando essa chamada.\n";
    exit;
}
register_shutdown_function(function () use ($lock) {
    flock($lock, LOCK_UN);
    fclose($lock);
});

echo "Iniciando processamento de renovações automáticas (" . date('Y-m-d H:i:s') . ")...\n";

try {
    $data_alvo = date('Y-m-d H:i:s', strtotime("+$DIAS_ANTECEDENCIA_RENOVACAO days"));

    // Cron de renovação manual por PIX: APENAS para assinaturas SEM id_assinatura.
    // Assinaturas com id_assinatura (PIX Automático nativo) são renovadas
    // automaticamente pelo gateway — não precisam de PIX manual.
    $sql = "
        SELECT m.*, v.valor, v.tipo_cobranca, v.dias_acesso, v.tempo_acesso_minutos, v.bot_id as venda_bot_id,
               b.token, b.id_usuario as dono_id
        FROM membros_grupos m
        JOIN vendas v ON m.venda_id = v.id
        JOIN bots b ON m.bot_id = b.id
        WHERE m.status = 'ativo'
          AND m.data_expiracao <= ?
          AND (v.id_assinatura IS NULL OR v.id_assinatura = '')
          AND v.tipo_cobranca = 'assinatura'
          AND NOT EXISTS (
              SELECT 1 FROM vendas v2
              WHERE v2.id_telegram = m.id_telegram
                AND v2.bot_id = m.bot_id
                AND v2.venda_pai_id = v.id
                AND v2.status IN ('gerado', 'pago')
                AND v2.criado_em > DATE_SUB(NOW(), INTERVAL 5 DAY)
          )
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$data_alvo]);
    $assinaturas_para_renovar = $stmt->fetchAll();
    
    echo "Encontradas " . count($assinaturas_para_renovar) . " assinaturas para renovar.\n";
    
    foreach ($assinaturas_para_renovar as $item) {
        echo "Processando renovação para usuário {$item['id_telegram']} (Venda Pai: {$item['venda_id']})...\n";

        $id_usuario_dono = $item['dono_id'];
        $user_gateways = getUserGateways((int)$id_usuario_dono, true);
        if (empty($user_gateways)) {
            echo " - Erro: Nenhum gateway ativo configurado para o dono do bot.\n";
            continue;
        }

        $gateway_config = $user_gateways[0];
        $nome_gateway = $gateway_config['gateway_nome'] ?? null;
        $provedor = resolveGatewayProvider($nome_gateway, $gateway_config);
        if (!$provedor) {
            echo " - Erro: Gateway $nome_gateway não suportado.\n";
            continue;
        }

        
        $valor = (float)$item['valor'];
        $chave_pix = $gateway_config['chave_pix'];
        
        // Expiração do Pix: Até a data de vencimento da assinatura + Tolerância?
        // Vamos colocar 3 dias de validade para o Pix
        $expiracao_segundos = 3 * 86400;

        $split_data = null;
        $split_gateway = getGatewaySplit($nome_gateway);
        if ($split_gateway) {
            $split_data = [[
                'chave' => $split_gateway['chave_pix_split'],
                'valor' => $split_gateway['taxa_split'],
                'tipo'  => $split_gateway['tipo_split'] ?? 'percentual',
            ]];
        }

        $payload = $provedor->montaPayloadCobranca($valor, $chave_pix, $split_data, $expiracao_segundos);
        $resp = $provedor->criarCobranca($payload);

        $txid = $resp['dados']['txid'] ?? '';
        $pix_copia_cola = $resp['dados']['pixCopiaECola'] ?? '';

        if ($resp['sucesso']) {

            $stmt_insert = $pdo->prepare("
                INSERT INTO vendas (
                    id_telegram, bot_id, valor, status, transacao_id, 
                    id_grupo_telegram, dias_acesso, tempo_acesso_minutos, 
                    tipo_cobranca, venda_pai_id, criado_em, 
                    tempo_expiracao_minutos
                ) VALUES (
                    ?, ?, ?, 'gerado', ?, 
                    ?, ?, ?, 
                    'assinatura', ?, NOW(), 
                    ?
                )
            ");
            
            // Mantém os mesmos parâmetros de acesso da venda pai
            $stmt_insert->execute([
                $item['id_telegram'],
                $item['bot_id'],
                $valor,
                $txid,
                $item['id_grupo_telegram'],
                $item['dias_acesso'],
                $item['tempo_acesso_minutos'],
                $item['venda_id'], // venda_pai_id
                ($expiracao_segundos / 60)
            ]);

            $msg = "🔄 *Renovação de Assinatura*\n\n";
            $msg .= "Olá! Sua assinatura do grupo vence em breve.\n";
            $msg .= "Para continuar com seu acesso ininterrupto, realize o pagamento da renovação abaixo:\n\n";
            $msg .= "💰 *Valor:* R$ " . number_format($valor, 2, ',', '.') . "\n";
            $msg .= "📅 *Vencimento:* " . date('d/m/Y', strtotime($item['data_expiracao'])) . "\n\n";
            $msg .= "👇 Copie e cole o código Pix no seu banco:";
            
            $teclado = [
                'inline_keyboard' => [[
                    ['text' => 'Já paguei a renovação ✅', 'callback_data' => 'verificar_pagamento_' . $txid]
                ]]
            ];
            
            telegramRequest($item['token'], 'sendMessage', [
                'chat_id' => $item['id_telegram'],
                'text' => $msg,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($teclado)
            ]);
            
            telegramRequest($item['token'], 'sendMessage', [
                'chat_id' => $item['id_telegram'],
                'text' => "<code>$pix_copia_cola</code>",
                'parse_mode' => 'HTML'
            ]);
            
            echo " - Renovação gerada e enviada com sucesso (TXID: $txid).\n";
            
            // Marca flag 'em_renovacao' no membro para controle
            // Se a coluna não existir, vai dar erro, mas o script continua
            try {
                $pdo->prepare("UPDATE membros_grupos SET em_renovacao = 1 WHERE id = ?")->execute([$item['id']]);
            } catch (Exception $e) {
                // Ignora se coluna não existir
            }
            
        } else {
            echo " - Erro ao gerar Pix: " . ($resp['erro'] ?? 'Desconhecido') . "\n";
        }
    }
    
} catch (Exception $e) {
    echo "Erro fatal: " . $e->getMessage() . "\n";
}
