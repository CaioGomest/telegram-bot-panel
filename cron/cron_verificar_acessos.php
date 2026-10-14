<?php
declare(strict_types=1);

require_once __DIR__ . '/../conexao.php';
require_once __DIR__ . '/../funcoes/cron_guarda.php';
exigirChamadaDeCron('cron_verificar_acessos');
require_once __DIR__ . '/../funcoes/log.php';
require_once __DIR__ . '/../funcoes/fluxo_blocos.php';
require_once __DIR__ . '/../funcoes/assinatura.php';

date_default_timezone_set('America/Sao_Paulo');

// Grava toda a saída desta execução em arquivo, para permitir auditoria
// posterior de quando/se este cron rodou e quais membros removeu/manteve.
$log_file_verificacao = __DIR__ . '/../logs/cron_verificar_acessos.log';
ob_start();
register_shutdown_function(function () use ($log_file_verificacao) {
    $conteudo = ob_get_contents();
    // Rodada ociosa (nenhum acesso vencido) não vira linha de log: a cada minuto isso enchia o arquivo à toa.
    if (!preg_match('/Encontrados|Erro|Falha|Removido|Processando|tolerância/i', (string) $conteudo)) {
        return;
    }
    file_put_contents(
        $log_file_verificacao,
        '[' . date('Y-m-d H:i:s') . "] Execução iniciada\n" . $conteudo . str_repeat('-', 60) . "\n",
        FILE_APPEND
    );
});

function telegramRequest(string $token, string $metodo, array $parametros = []): array {
    $url = 'https://api.telegram.org/bot' . $token . '/' . $metodo;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($parametros));
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $resposta = curl_exec($ch);
    $r = json_decode($resposta ?: '', true) ?: ['ok' => false];
    registrarRespostaTelegram($token, $r);
    return $r;
}

// Trava contra execução concorrente -- mesmo padrão de cron_verificar_pix.php/cron_remarketing.php.
$lock_file = sys_get_temp_dir() . '/cron_verificar_acessos.lock';
$lock = fopen($lock_file, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo "Execução anterior ainda em andamento. Encerrando essa chamada.\n";
    exit;
}
register_shutdown_function(function () use ($lock) {
    flock($lock, LOCK_UN);
    fclose($lock);
});

$hoje = date('Y-m-d H:i:s');
echo "Iniciando verificação de acessos em $hoje...\n";

try {
    // Inclui id_assinatura para identificar recorrentes nativos.
    // link_suporte vem do fluxo atualmente conectado ao bot (cada fluxo pode ter o seu).
    $stmt = $pdo->prepare("
        SELECT m.*, b.token, b.nome_usuario, b.id_usuario AS id_dono, v.tipo_cobranca, v.dias_acesso, v.id_assinatura, v.valor, f.link_suporte, f.dados_fluxograma,
               (SELECT l.nome FROM leads l WHERE l.bot_id = m.bot_id AND l.id_telegram = m.id_telegram ORDER BY l.id DESC LIMIT 1) AS nome
        FROM membros_grupos m
        JOIN bots b ON m.bot_id = b.id
        LEFT JOIN vendas v ON m.venda_id = v.id
        LEFT JOIN fluxos f ON f.id = b.id_fluxo_conectado
        WHERE m.data_expiracao < ? AND m.status = 'ativo'
    ");
    $stmt->execute([$hoje]);
    $expirados = $stmt->fetchAll();

    if (empty($expirados)) {
        echo "Nenhum acesso expirado encontrado.\n";
        exit;
    }

    echo "Encontrados " . count($expirados) . " acessos expirados.\n";

    // Tolerância máxima depois do vencimento, pros dois tipos de assinatura (manual e
    // recorrência automática nativa) -- tempo que o cliente ainda precisa ter acesso
    // pra conseguir pagar a renovação. Ajustado a pedido do Caio em 2026-09-17 (era 5
    // dias só pra assinatura manual; unificado em 2 pra ambos).
    $DIAS_CARENCIA = 2;

    foreach ($expirados as $membro) {
        $token = $membro['token'];
        $id_chat = $membro['id_telegram'];
        $id_grupo = $membro['id_grupo_telegram'];
        $tipo_cobranca = $membro['tipo_cobranca'] ?? 'unica';

        echo "Processando usuário $id_chat no grupo $id_grupo (Bot @{$membro['nome_usuario']})...\n";

        $id_assinatura = $membro['id_assinatura'] ?? null;
        $eh_recorrente_nativo = !empty($id_assinatura); // PIX Automático nativo do gateway

        // Botões da mensagem de aviso: "Recomeçar" sempre aparece — o callback_data "/start"
        // é tratado pelo webhook.php exatamente como se o usuário tivesse digitado /start,
        // reiniciando o fluxo (útil pra pagar a renovação de novo). "Falar com Suporte" só
        // aparece se o fluxo conectado ao bot tiver um link configurado.
        $link_suporte = trim((string)($membro['link_suporte'] ?? ''));
        $botoes_aviso = [[['text' => '🔄 Recomeçar / Renovar', 'callback_data' => '/start']]];
        if ($link_suporte !== '') {
            $botoes_aviso[] = [['text' => '💬 Falar com Suporte', 'url' => $link_suporte]];
        }
        $teclado_suporte = ['reply_markup' => json_encode(['inline_keyboard' => $botoes_aviso])];

        // Tolerância:
        // - Recorrente nativo ou assinatura manual: $DIAS_CARENCIA (mesmo teto pros dois)
        // - Único: sem tolerância
        if ($tipo_cobranca === 'assinatura' || $eh_recorrente_nativo) {
            $dias_carencia = $DIAS_CARENCIA;
            $data_expiracao = strtotime($membro['data_expiracao']);
            $data_limite = strtotime("+{$dias_carencia} days", $data_expiracao);
            $agora = time();

            if ($agora < $data_limite) {
                $dias_restantes = ceil(($data_limite - $agora) / 86400);
                echo " - Usuário em período de tolerância (restam $dias_restantes dias). Não removendo.\n";

                // Só manda o aviso uma vez por vencimento — sem isso, o cron (que roda a cada
                // minuto) reenviaria a mesma mensagem repetidamente durante toda a tolerância.
                $ja_avisado = !empty($membro['aviso_enviado']);

                if ($eh_recorrente_nativo) {
                    // Para recorrente nativo a cobrança é automática — apenas avisa se tolerância
                    // estiver quase esgotada e o gateway ainda não renovou
                    if ($dias_restantes <= 1 && !$ja_avisado) {
                        telegramRequest($token, 'sendMessage', array_merge([
                            'chat_id' => $id_chat,
                            'text' => "⚠️ *Atenção: problema na renovação da sua assinatura!*\n\nNão conseguimos confirmar o pagamento automático. Você será removido do grupo em breve caso não seja regularizado.",
                            'parse_mode' => 'Markdown'
                        ], $teclado_suporte));
                        $pdo->prepare("UPDATE membros_grupos SET aviso_enviado = 1 WHERE id = ?")->execute([$membro['id']]);
                    }
                } else {
                    // Assinatura manual: lembra o usuário de pagar o PIX de renovação
                    if ($dias_restantes <= 2 && !$ja_avisado) {
                        telegramRequest($token, 'sendMessage', array_merge([
                            'chat_id' => $id_chat,
                            'text' => "⚠️ *Atenção: Seu acesso venceu!*\n\nVocê tem mais *$dias_restantes dias* de tolerância para renovar sua assinatura antes de ser removido do grupo.\nProcure a mensagem de renovação enviada anteriormente e faça o Pix.",
                            'parse_mode' => 'Markdown'
                        ], $teclado_suporte));
                        $pdo->prepare("UPDATE membros_grupos SET aviso_enviado = 1 WHERE id = ?")->execute([$membro['id']]);
                    }
                }

                continue;
            } else {
                echo " - Tolerância de {$dias_carencia} dias esgotada. Removendo...\n";
            }
        } else {
            echo " - Cobrança única (sem recorrência). Removendo imediatamente.\n";
        }

        // A lista de vencidos foi lida no início da rodada; uma renovação confirmada desde então (webhook/cron de Pix)
        // já estendeu a data. Relê a linha antes de agir pra não remover quem acabou de pagar.
        $stmt_atual = $pdo->prepare("SELECT status, data_expiracao FROM membros_grupos WHERE id = ?");
        $stmt_atual->execute([$membro['id']]);
        $atual = $stmt_atual->fetch();
        if (!$atual || $atual['status'] !== 'ativo' || strtotime((string) $atual['data_expiracao']) >= strtotime($hoje)) {
            echo " - Acesso renovado durante a rodada. Não removendo.\n";
            continue;
        }

        // Aba Assinatura do Fluxo Guiado: mensagem de expiração própria e opção de NÃO remover do grupo.
        $mensagem_expirou = mensagemAoExpirarBasico($membro['dados_fluxograma'] ?? null, $membro);
        $expulsar = (configAssinaturaBasico($membro['dados_fluxograma'] ?? null)['acoes']['expulsar'] ?? true) !== false;
        if (!$expulsar) {
            echo " - Fluxo configurado para não expulsar. Encerrando o acesso sem remover do grupo.\n";
            $pdo->prepare("UPDATE membros_grupos SET status = 'expirado' WHERE id = ?")->execute([$membro['id']]);
            if ($mensagem_expirou) {
                enviarMensagemAssinatura($token, (string) $id_chat, $mensagem_expirou['texto'], $mensagem_expirou['bloco'], tecladoRenovacaoAssinatura());
            }
            continue;
        }

        if (!empty($membro['invite_link'])) {
             $revoke = telegramRequest($token, 'revokeChatInviteLink', [
                 'chat_id' => $id_grupo,
                 'invite_link' => $membro['invite_link']
             ]);
             if ($revoke['ok'] ?? false) {
                 echo " - Link revogado com sucesso.\n";
             } else {
                 echo " - Falha ao revogar link: " . ($revoke['description'] ?? 'Desconhecido') . "\n";
             }
        }

        // unbanChatMember com only_if_banned=true só funciona se estiver banido, mas queremos kickar.
        // O padrão para kickar é banir e desbanir.
        
        $ban = telegramRequest($token, 'banChatMember', [
            'chat_id' => $id_grupo,
            'user_id' => $id_chat,
            'until_date' => time() + 35 // Banido por 35 segundos (mínimo permitido é 30s ou permanente)
        ]);

        if ($ban['ok'] ?? false) {
            echo " - Removido com sucesso.\n";
            
            // Desbanir imediatamente para permitir reentrada futura (se pagar)
            telegramRequest($token, 'unbanChatMember', [
                'chat_id' => $id_grupo,
                'user_id' => $id_chat,
                'only_if_banned' => true
            ]);

            $stmt_update = $pdo->prepare("UPDATE membros_grupos SET status = 'expirado' WHERE id = ? AND data_expiracao < ?");
            $stmt_update->execute([$membro['id'], $hoje]);

            if ($mensagem_expirou) {
                enviarMensagemAssinatura($token, (string) $id_chat, $mensagem_expirou['texto'], $mensagem_expirou['bloco'], tecladoRenovacaoAssinatura());
            } else telegramRequest($token, 'sendMessage', array_merge([
                'chat_id' => $id_chat,
                'text' => "⚠️ *Seu acesso ao grupo expirou!*\n\nVocê foi removido automaticamente pois seu período de acesso acabou.\nPara voltar, realize uma nova assinatura ou compra no bot.",
                'parse_mode' => 'Markdown'
            ], $teclado_suporte));

            registrarAtividade((int) $membro['id_dono'], 'sistema', 'Acesso', "Usuário $id_chat removido do grupo $id_grupo por expiração.");

        } else {
            $erro_desc = $ban['description'] ?? 'Desconhecido';
            echo " - Erro ao remover: " . $erro_desc . "\n";
            
            // Se o erro for porque não pode remover o dono (chat owner) ou o criador do grupo,
            // ou um erro permanente (grupo excluído, bot removido dele, etc) — marca como
            // expirado mesmo assim, senão fica tentando de novo a cada execução do cron pra sempre.
            $erros_permanentes = ["can't remove chat owner", "user is an administrator", "chat not found", "group chat was deactivated", "bot was kicked", "user not found", "bot is not a member"];
            $eh_erro_permanente = false;
            foreach ($erros_permanentes as $padrao) {
                if (stripos($erro_desc, $padrao) !== false) {
                    $eh_erro_permanente = true;
                    break;
                }
            }

            // Bot sem permissão de banir no grupo: não é permanente (o dono pode corrigir), mas sem avisar ninguém o cron
            // tentava a cada minuto pra sempre e a pessoa vencida seguia no grupo sem o dono saber. Avisa o dono no sino,
            // no máximo uma vez por dia por grupo, e continua tentando (assim que o bot virar admin, a remoção acontece).
            if (stripos($erro_desc, 'not enough rights') !== false || stripos($erro_desc, 'CHAT_ADMIN_REQUIRED') !== false) {
                $aviso_dono = "O bot @{$membro['nome_usuario']} não tem permissão para remover membros do grupo $id_grupo, então quem venceu continua lá. Deixe o bot como administrador do grupo com a permissão \"Banir usuários\".";
                $stmt_ja = $pdo->prepare("SELECT 1 FROM atividades WHERE id_usuario = ? AND titulo = 'Remoção do grupo falhou' AND descricao = ? AND criado_em > NOW() - INTERVAL 1 DAY LIMIT 1");
                $stmt_ja->execute([(int) $membro['id_dono'], $aviso_dono]);
                if (!$stmt_ja->fetchColumn()) {
                    registrarAtividade((int) $membro['id_dono'], 'sistema', 'Remoção do grupo falhou', $aviso_dono);
                    echo " - Dono avisado: bot sem permissão de banir no grupo.\n";
                }
            }

            if ($eh_erro_permanente) {
                echo " - Erro permanente ($erro_desc). Marcando como expirado no banco sem tentar de novo.\n";

                $stmt_update = $pdo->prepare("UPDATE membros_grupos SET status = 'expirado' WHERE id = ?");
                $stmt_update->execute([$membro['id']]);
            }
        }
    }

} catch (PDOException $e) {
    echo "Erro de banco: " . $e->getMessage() . "\n";
}
