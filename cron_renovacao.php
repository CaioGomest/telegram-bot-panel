<?php
declare(strict_types=1);

require_once 'conexao.php';
require_once 'funcoes/log.php';
require_once 'funcoes/gateways.php';

date_default_timezone_set('America/Sao_Paulo');

// Grava toda a saída desta execução em arquivo, para permitir auditoria
// posterior de quando/se este cron rodou e o que decidiu para cada venda.
$logFileRenovacao = __DIR__ . '/logs/cron_renovacao.log';
ob_start();
register_shutdown_function(function () use ($logFileRenovacao) {
    $conteudo = ob_get_contents();
    file_put_contents(
        $logFileRenovacao,
        '[' . date('Y-m-d H:i:s') . "] Execução iniciada\n" . $conteudo . str_repeat('-', 60) . "\n",
        FILE_APPEND
    );
});

// Configurações
$DIAS_ANTECEDENCIA_RENOVACAO = 3; // Gera cobrança 3 dias antes de vencer

function telegram_request(string $token, string $metodo, array $parametros = []): array {
    $url = 'https://api.telegram.org/bot' . $token . '/' . $metodo;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($parametros));
    $resposta = curl_exec($ch);
    curl_close($ch);
    return json_decode($resposta ?: '', true) ?: ['ok' => false];
}

echo "Iniciando processamento de renovações automáticas (" . date('Y-m-d H:i:s') . ")...\n";

try {
    // 1. Busca assinaturas ativas que vencem em breve (ou já venceram e ainda não têm renovação)
    // Precisamos olhar para a tabela 'membros_grupos' para saber a expiração real,
    // e cruzar com 'vendas' para saber se é assinatura.
    
    // Data alvo: Hoje + X dias
    $dataAlvo = date('Y-m-d H:i:s', strtotime("+$DIAS_ANTECEDENCIA_RENOVACAO days"));
    
    // Busca membros que:
    // - Estão ativos
    // - Vencem nos próximos X dias (data_expiracao <= dataAlvo)
    // - Venda original é do tipo 'assinatura'
    // - Ainda não têm uma renovação pendente (venda filha com status 'gerado' ou 'pago' criada recentemente)
    
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
    $stmt->execute([$dataAlvo]);
    $assinaturasParaRenovar = $stmt->fetchAll();
    
    echo "Encontradas " . count($assinaturasParaRenovar) . " assinaturas para renovar.\n";
    
    foreach ($assinaturasParaRenovar as $item) {
        echo "Processando renovação para usuário {$item['id_telegram']} (Venda Pai: {$item['venda_id']})...\n";
        
        // 2. Gerar nova cobrança Pix
        $idUsuarioDono = $item['dono_id'];
        $userGateways = getUserGateways((int)$idUsuarioDono, true);
        if (empty($userGateways)) {
            echo " - Erro: Nenhum gateway ativo configurado para o dono do bot.\n";
            continue;
        }

        $gatewayConfig = $userGateways[0];
        $nomeGateway = $gatewayConfig['gateway_nome'] ?? null;
        $provedor = resolveGatewayProvider($nomeGateway, $gatewayConfig);
        if (!$provedor) {
            echo " - Erro: Gateway $nomeGateway não suportado.\n";
            continue;
        }

        
        $valor = (float)$item['valor'];
        $chavePix = $gatewayConfig['chave_pix'];
        
        // Expiração do Pix: Até a data de vencimento da assinatura + Tolerância?
        // Vamos colocar 3 dias de validade para o Pix
        $expiracaoSegundos = 3 * 86400; 
        
        // Split por usuário
        $splitData = null;
        $userSplits = getUserSplits((int)$idUsuarioDono, $nomeGateway);
        if (!empty($userSplits)) {
            $splitData = array_map(fn($s) => [
                'chave' => $s['chave_pix_split'],
                'valor' => $s['taxa_split'],
                'tipo'  => $s['tipo_split'] ?? 'percentual'
            ], $userSplits);
        }

        $payload = $provedor->montaPayloadCobranca($valor, $chavePix, $splitData, $expiracaoSegundos);
        $resp = $provedor->criarCobranca($payload);

        $txid = $resp['dados']['txid'] ?? '';
        $pixCopiaCola = $resp['dados']['pixCopiaECola'] ?? '';

        if ($resp['sucesso']) {
            
            // 3. Salvar nova venda no banco (Venda Filha)
            $stmtInsert = $pdo->prepare("
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
            $stmtInsert->execute([
                $item['id_telegram'],
                $item['bot_id'],
                $valor,
                $txid,
                $item['id_grupo_telegram'],
                $item['dias_acesso'],
                $item['tempo_acesso_minutos'],
                $item['venda_id'], // venda_pai_id
                ($expiracaoSegundos / 60)
            ]);
            
            // 4. Enviar mensagem para o usuário
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
            
            telegram_request($item['token'], 'sendMessage', [
                'chat_id' => $item['id_telegram'],
                'text' => $msg,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($teclado)
            ]);
            
            telegram_request($item['token'], 'sendMessage', [
                'chat_id' => $item['id_telegram'],
                'text' => "<code>$pixCopiaCola</code>",
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
