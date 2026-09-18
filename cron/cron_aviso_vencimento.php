<?php
/**
 * Script para enviar aviso de vencimento próximo (compra única, não assinatura).
 * Deve rodar a cada 1 minuto.
 */

declare(strict_types=1);

require_once __DIR__ . '/../conexao.php';
require_once __DIR__ . '/../funcoes/log.php';

date_default_timezone_set('America/Sao_Paulo');

$log_file_aviso = __DIR__ . '/../logs/cron_aviso.log';
function logAviso(string $msg): void {
    global $log_file_aviso;
    $date = date('Y-m-d H:i:s');
    file_put_contents($log_file_aviso, "[$date] $msg" . PHP_EOL, FILE_APPEND);
}

function telegramRequestAviso(string $token, string $metodo, array $parametros = []): array {
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
$lock_file = sys_get_temp_dir() . '/cron_aviso_vencimento.lock';
$lock = fopen($lock_file, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    logAviso("Execução anterior ainda em andamento. Encerrando essa chamada.");
    exit;
}
// Libera a trava em qualquer saída (inclusive os "exit" antecipados abaixo), sem precisar
// duplicar flock(LOCK_UN)/fclose() em cada ponto de saída do script.
register_shutdown_function(function () use ($lock) {
    flock($lock, LOCK_UN);
    fclose($lock);
});

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
        $id_membro = $membro['id'];
        $id_telegram = $membro['id_telegram'];
        $token_bot = $membro['token'];
        $data_expiracao = strtotime($membro['data_expiracao']);
        $tempo_restante = $data_expiracao - time();

        $dias_acesso = (int)($membro['dias_acesso'] ?? 0);
        $minutos_acesso = (int)($membro['tempo_acesso_minutos'] ?? 0);

        logAviso("Analisando membro #$id_membro (user=$id_telegram) | data_expiracao={$membro['data_expiracao']} | tempoRestante={$tempo_restante}s | dias=$dias_acesso | minutos=$minutos_acesso");

        $deve_avisar = false;
        $mensagem = "";

        if ($dias_acesso >= 1) {
            if ($tempo_restante <= 86400) {
                $deve_avisar = true;
                $horas_restantes = ceil($tempo_restante / 3600);
                $mensagem = "⚠️ *Atenção: Seu acesso vence em breve!*\n\nFaltam menos de *{$horas_restantes} horas* para seu acesso expirar.";
            }
        } elseif ($minutos_acesso > 0 || $dias_acesso == 0) {
            $limite_aviso = ($minutos_acesso > 15) ? 900 : 300;
            logAviso("  -> limiteAviso={$limite_aviso}s | deveAvisar=" . ($tempo_restante <= $limite_aviso ? 'SIM' : 'NAO'));

            if ($tempo_restante <= $limite_aviso) {
                $deve_avisar = true;
                $minutos_restantes = ceil($tempo_restante / 60);
                $mensagem = "⚠️ *Atenção: Seu tempo está acabando!*\n\nFaltam apenas *{$minutos_restantes} minutos* para seu acesso expirar.";
            }
        } else {
            logAviso("  -> Nenhuma regra de aviso se aplicou (dias=$dias_acesso, minutos=$minutos_acesso)");
        }

        if ($deve_avisar) {
            logAviso("Avisando usuário $id_telegram (Restam " . round($tempo_restante/60) . " min)...");
            echo "Avisando usuário $id_telegram (Restam " . round($tempo_restante/60) . " min)...\n";

            $mensagem .= "\nRenove agora para não ser removido do grupo.";

            // Botões: "Recomeçar" sempre aparece — o callback_data "/start" é tratado pelo
            // webhook.php exatamente como se o usuário tivesse digitado /start, reiniciando
            // o fluxo (onde ele consegue gerar um novo Pix normalmente). "Falar com Suporte"
            // só aparece se o fluxo conectado ao bot tiver um link configurado.
            $link_suporte = trim((string)($membro['link_suporte'] ?? ''));
            $botoes_aviso = [[['text' => '🔄 Recomeçar / Renovar', 'callback_data' => '/start']]];
            if ($link_suporte !== '') {
                $botoes_aviso[] = [['text' => '💬 Falar com Suporte', 'url' => $link_suporte]];
            }

            $resp = telegramRequestAviso($token_bot, 'sendMessage', [
                'chat_id' => $id_telegram,
                'text' => $mensagem,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode(['inline_keyboard' => $botoes_aviso])
            ]);

            if ($resp['ok'] ?? false) {
                $upd = $pdo->prepare("UPDATE membros_grupos SET aviso_enviado = 1 WHERE id = ?");
                $upd->execute([$id_membro]);

                logAviso("Aviso enviado com sucesso para $id_telegram.");
                echo " - Aviso enviado com sucesso.\n";

                registrarAtividade(
                    (int)$membro['bot_id'],
                    'sistema',
                    'Aviso de Vencimento',
                    "Aviso enviado para usuário $id_telegram. Expira em: {$membro['data_expiracao']}"
                );
            } else {
                echo " - Falha ao enviar aviso: " . ($resp['description'] ?? 'Erro desconhecido') . "\n";
                // Se falhou (ex: bloqueou o bot), marca como avisado para não tentar eternamente.
                $erro = $resp['description'] ?? '';
                if (strpos($erro, 'blocked') !== false || strpos($erro, 'deactivated') !== false) {
                    $pdo->prepare("UPDATE membros_grupos SET aviso_enviado = 1 WHERE id = ?")->execute([$id_membro]);
                    echo " - Usuário bloqueou o bot. Marcado como avisado para não tentar novamente.\n";
                }
            }
        }
    }

} catch (PDOException $e) {
    echo "Erro no banco de dados: " . $e->getMessage() . "\n";
}
