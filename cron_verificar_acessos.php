<?php
declare(strict_types=1);

require_once 'conexao.php';
require_once 'funcoes/log.php';

date_default_timezone_set('America/Sao_Paulo');

// Grava toda a saída desta execução em arquivo, para permitir auditoria
// posterior de quando/se este cron rodou e quais membros removeu/manteve.
$logFileVerificacao = __DIR__ . '/logs/cron_verificar_acessos.log';
ob_start();
register_shutdown_function(function () use ($logFileVerificacao) {
    $conteudo = ob_get_contents();
    file_put_contents(
        $logFileVerificacao,
        '[' . date('Y-m-d H:i:s') . "] Execução iniciada\n" . $conteudo . str_repeat('-', 60) . "\n",
        FILE_APPEND
    );
});

// Função auxiliar para Telegram
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

$hoje = date('Y-m-d H:i:s');
echo "Iniciando verificação de acessos em $hoje...\n";

try {
    // Busca acessos ativos vencidos.
    // Inclui id_assinatura para identificar recorrentes nativos (EFI/PushinPay).
    $stmt = $pdo->prepare("
        SELECT m.*, b.token, b.nome_usuario, v.tipo_cobranca, v.dias_acesso, v.id_assinatura
        FROM membros_grupos m
        JOIN bots b ON m.bot_id = b.id
        LEFT JOIN vendas v ON m.venda_id = v.id
        WHERE m.data_expiracao < ? AND m.status = 'ativo'
    ");
    $stmt->execute([$hoje]);
    $expirados = $stmt->fetchAll();

    if (empty($expirados)) {
        echo "Nenhum acesso expirado encontrado.\n";
        exit;
    }

    echo "Encontrados " . count($expirados) . " acessos expirados.\n";

    // Configuração de Tolerância (Carência) em dias
    $DIAS_CARENCIA = 5;

    foreach ($expirados as $membro) {
        $token = $membro['token'];
        $idChat = $membro['id_telegram'];
        $idGrupo = $membro['id_grupo_telegram'];
        $tipoCobranca = $membro['tipo_cobranca'] ?? 'unica';

        echo "Processando usuário $idChat no grupo $idGrupo (Bot @{$membro['nome_usuario']})...\n";

        $idAssinatura = $membro['id_assinatura'] ?? null;
        $ehRecorrenteNativo = !empty($idAssinatura); // EFI PIX Automático ou PushinPay Recorrente

        // Tolerância:
        // - Recorrente nativo: 2 dias (gateway tenta cobrar automaticamente; webhook pode demorar)
        // - Assinatura manual sem id_assinatura: 5 dias (usuário precisa pagar manualmente)
        // - Único: sem tolerância
        if ($tipoCobranca === 'assinatura' || $ehRecorrenteNativo) {
            $diasCarencia = $ehRecorrenteNativo ? 2 : $DIAS_CARENCIA;
            $dataExpiracao = strtotime($membro['data_expiracao']);
            $dataLimite = strtotime("+{$diasCarencia} days", $dataExpiracao);
            $agora = time();

            if ($agora < $dataLimite) {
                $diasRestantes = ceil(($dataLimite - $agora) / 86400);
                echo " - Usuário em período de tolerância (restam $diasRestantes dias). Não removendo.\n";

                if ($ehRecorrenteNativo) {
                    // Para recorrente nativo a cobrança é automática — apenas avisa se tolerância
                    // estiver quase esgotada e o gateway ainda não renovou
                    if ($diasRestantes <= 1) {
                        telegram_request($token, 'sendMessage', [
                            'chat_id' => $idChat,
                            'text' => "⚠️ *Atenção: problema na renovação da sua assinatura!*\n\nNão conseguimos confirmar o pagamento automático. Você será removido do grupo em breve caso não seja regularizado.",
                            'parse_mode' => 'Markdown'
                        ]);
                    }
                } else {
                    // Assinatura manual: lembra o usuário de pagar o PIX de renovação
                    if ($diasRestantes <= 2) {
                        telegram_request($token, 'sendMessage', [
                            'chat_id' => $idChat,
                            'text' => "⚠️ *Atenção: Seu acesso venceu!*\n\nVocê tem mais *$diasRestantes dias* de tolerância para renovar sua assinatura antes de ser removido do grupo.\nProcure a mensagem de renovação enviada anteriormente e faça o Pix.",
                            'parse_mode' => 'Markdown'
                        ]);
                    }
                }

                continue;
            } else {
                echo " - Tolerância de {$diasCarencia} dias esgotada. Removendo...\n";
            }
        } else {
            echo " - Cobrança única (sem recorrência). Removendo imediatamente.\n";
        }

        // Tenta revogar o link de convite se houver
        if (!empty($membro['invite_link'])) {
             $revoke = telegram_request($token, 'revokeChatInviteLink', [
                 'chat_id' => $idGrupo,
                 'invite_link' => $membro['invite_link']
             ]);
             if ($revoke['ok'] ?? false) {
                 echo " - Link revogado com sucesso.\n";
             } else {
                 echo " - Falha ao revogar link: " . ($revoke['description'] ?? 'Desconhecido') . "\n";
             }
        }

        // 1. Remove do grupo (Banir e Desbanir = Kick)
        // unbanChatMember com only_if_banned=true só funciona se estiver banido, mas queremos kickar.
        // O padrão para kickar é banir e desbanir.
        
        $ban = telegram_request($token, 'banChatMember', [
            'chat_id' => $idGrupo,
            'user_id' => $idChat,
            'until_date' => time() + 35 // Banido por 35 segundos (mínimo permitido é 30s ou permanente)
        ]);

        if ($ban['ok'] ?? false) {
            // Sucesso ao remover
            echo " - Removido com sucesso.\n";
            
            // Desbanir imediatamente para permitir reentrada futura (se pagar)
            telegram_request($token, 'unbanChatMember', [
                'chat_id' => $idGrupo,
                'user_id' => $idChat,
                'only_if_banned' => true
            ]);

            // Atualiza status no banco
            $stmtUpdate = $pdo->prepare("UPDATE membros_grupos SET status = 'expirado' WHERE id = ?");
            $stmtUpdate->execute([$membro['id']]);

            // Avisa o usuário
            telegram_request($token, 'sendMessage', [
                'chat_id' => $idChat,
                'text' => "⚠️ *Seu acesso ao grupo expirou!*\n\nVocê foi removido automaticamente pois seu período de acesso acabou.\nPara voltar, realize uma nova assinatura ou compra no bot.",
                'parse_mode' => 'Markdown'
            ]);

            registrarAtividade($membro['bot_id'], 'sistema', 'Acesso', "Usuário $idChat removido do grupo $idGrupo por expiração.");

        } else {
            $erroDesc = $ban['description'] ?? 'Desconhecido';
            echo " - Erro ao remover: " . $erroDesc . "\n";
            
            // Se o erro for porque não pode remover o dono (chat owner) ou o criador do grupo
            if (strpos($erroDesc, "can't remove chat owner") !== false || strpos($erroDesc, "user is an administrator") !== false) {
                echo " - Ignorando remoção pois o usuário é o dono ou admin do grupo. Marcando como expirado no banco apenas.\n";
                
                // Atualiza status no banco para não ficar tentando remover o dono infinitamente
                $stmtUpdate = $pdo->prepare("UPDATE membros_grupos SET status = 'expirado' WHERE id = ?");
                $stmtUpdate->execute([$membro['id']]);
            }
        }
    }

} catch (PDOException $e) {
    echo "Erro de banco: " . $e->getMessage() . "\n";
}
