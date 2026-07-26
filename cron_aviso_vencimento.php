<?php
/**
 * Script para enviar aviso de vencimento próximo (compra única, não assinatura).
 * Deve rodar a cada 1 minuto.
 */

declare(strict_types=1);

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/funcoes/log.php';

date_default_timezone_set('America/Sao_Paulo');

$logFileAviso = __DIR__ . '/logs/cron_aviso.log';
function logAviso(string $msg): void {
    global $logFileAviso;
    $date = date('Y-m-d H:i:s');
    file_put_contents($logFileAviso, "[$date] $msg" . PHP_EOL, FILE_APPEND);
}

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
    // Busca membros ativos que ainda não venceram e não foram avisados.
    // Apenas pagamentos ÚNICOS (não recorrentes) — assinaturas têm seu próprio
    // aviso de tolerância em cron_verificar_acessos.php.
    // link_suporte vem do fluxo atualmente conectado ao bot (cada fluxo pode ter o seu).
    $sql = "
        SELECT
            m.id, m.id_telegram, m.id_grupo_telegram, m.data_expiracao, m.bot_id,
            b.token, b.nome_usuario, b.id_usuario,
            v.tipo_cobranca, v.dias_acesso, v.tempo_acesso_minutos, f.link_suporte
        FROM membros_grupos m
        JOIN bots b ON m.bot_id = b.id
        LEFT JOIN vendas v ON m.venda_id = v.id
        LEFT JOIN fluxos f ON f.id = b.id_fluxo_conectado
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

            // Botões: "Recomeçar" sempre aparece — o callback_data "/start" é tratado pelo
            // webhook.php exatamente como se o usuário tivesse digitado /start, reiniciando
            // o fluxo (onde ele consegue gerar um novo Pix normalmente). "Falar com Suporte"
            // só aparece se o fluxo conectado ao bot tiver um link configurado.
            $linkSuporte = trim((string)($membro['link_suporte'] ?? ''));
            $botoesAviso = [[['text' => '🔄 Recomeçar / Renovar', 'callback_data' => '/start']]];
            if ($linkSuporte !== '') {
                $botoesAviso[] = [['text' => '💬 Falar com Suporte', 'url' => $linkSuporte]];
            }

            $resp = telegram_request_aviso($tokenBot, 'sendMessage', [
                'chat_id' => $idTelegram,
                'text' => $mensagem,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode(['inline_keyboard' => $botoesAviso])
            ]);

            if ($resp['ok'] ?? false) {
                // Marca como avisado
                $upd = $pdo->prepare("UPDATE membros_grupos SET aviso_enviado = 1 WHERE id = ?");
                $upd->execute([$idMembro]);

                logAviso("Aviso enviado com sucesso para $idTelegram.");
                echo " - Aviso enviado com sucesso.\n";

                registrarAtividade(
                    (int)$membro['bot_id'],
                    'sistema',
                    'Aviso de Vencimento',
                    "Aviso enviado para usuário $idTelegram. Expira em: {$membro['data_expiracao']}"
                );
            } else {
                echo " - Falha ao enviar aviso: " . ($resp['description'] ?? 'Erro desconhecido') . "\n";
                // Se falhou (ex: bloqueou o bot), marca como avisado para não tentar eternamente.
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
