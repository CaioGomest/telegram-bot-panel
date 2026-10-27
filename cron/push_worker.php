<?php
declare(strict_types=1);

/**
 * Trabalhador dos avisos push (Pix gerado / Pix pago). Plano: deploy/PLANO-PUSH-NOTIFICACOES.md.
 *
 *   php cron/push_worker.php --loop      serviço (systemd, deploy/vps/painel-push.service): roda para sempre, olha a fila a cada 100 ms ociosos e
 *                                        envia por conexões HTTP/2 abertas. Recicla a si mesmo a cada 1 h (o systemd sobe de novo).
 *   php cron/push_worker.php             modo cron (a cada minuto, com flock): faz o mesmo por ~58 s e sai; se o serviço estiver vivo, nem começa.
 *   php cron/push_worker.php --uma-vez   esvazia o que estiver pronto na fila, imprime os números e sai (teste manual).
 *
 * O arquivo storage/push_batimento é tocado a cada ciclo: o site lê o horário dele para saber se há trabalhador vivo (caminho A) ou se deve enviar
 * por conta própria depois de responder (caminho B). Só roda pela linha de comando ou com a chave do cron (funcoes/cron_guarda.php).
 */

require_once __DIR__ . '/../conexao.php';
require_once __DIR__ . '/../funcoes/cron_guarda.php';
require_once __DIR__ . '/../funcoes/push.php';

date_default_timezone_set('America/Sao_Paulo');
exigirChamadaDeCron('push_worker');

$argumentos = $argv ?? [];
$modo_servico = in_array('--loop', $argumentos, true);
$modo_uma_vez = in_array('--uma-vez', $argumentos, true);
$tamanho_lote = PUSH_LOTE_TAMANHO;
foreach ($argumentos as $a) {
    if (preg_match('/^--lote=([0-9]{1,4})$/', (string) $a, $mm)) {
        $tamanho_lote = max(1, (int) $mm[1]);   // testes de concorrência usam lotes pequenos para os trabalhadores se alternarem
    }
}
$log_dir = __DIR__ . '/../logs';
if (!is_dir($log_dir)) {
    @mkdir($log_dir, 0755, true);
}
$log_arquivo = $log_dir . '/push_worker.log';
function logPushWorker(string $msg): void
{
    global $log_arquivo;
    @file_put_contents($log_arquivo, '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL, FILE_APPEND);
}

if ($modo_uma_vez) {
    $total = 0;
    $soma = ['enviados' => 0, 'falhas_temporarias' => 0, 'inscricoes_removidas' => 0, 'descartados' => 0, 'agrupados' => 0, 'erros_fatais' => 0, 'devolvidos' => 0];
    pushManutencao($pdo);
    for ($i = 0; $i < 1000; $i++) {
        $lote = pushReservarLote($pdo, $tamanho_lote);
        if ($lote === null) {
            break;
        }
        $s = pushProcessarLoteReservado($pdo, $lote);
        $total += $s['itens'];
        foreach ($soma as $k => $v) {
            $soma[$k] += $s[$k];
        }
    }
    echo json_encode(['itens' => $total] + $soma) . PHP_EOL;
    exit(0);
}

if (!$modo_servico) {
    // Modo cron: se o serviço estiver vivo, não duplica; senão trava contra outra execução do cron e trabalha ~58 s.
    $lock = fopen(sys_get_temp_dir() . '/cron_push_worker.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        exit(0);
    }
    register_shutdown_function(function () use ($lock) {
        flock($lock, LOCK_UN);
        fclose($lock);
    });
    if (pushTrabalhadorVivo()) {
        exit(0);
    }
    $prazo = microtime(true) + 58;
} else {
    $prazo = microtime(true) + 3600;   // recicla a cada 1 h (o systemd reinicia): sem vazamento acumulado
    if (function_exists('pcntl_async_signals')) {
        pcntl_async_signals(true);
        $parar = static function () {
            $GLOBALS['push_parar'] = true;
        };
        pcntl_signal(SIGTERM, $parar);
        pcntl_signal(SIGINT, $parar);
    }
    logPushWorker('serviço iniciado (pid ' . getmypid() . ')');
}

$proxima_manutencao = 0.0;
$erros_seguidos = 0;
while (microtime(true) < $prazo && empty($GLOBALS['push_parar'])) {
    try {
        pushBatimento();
        garantirConexaoBanco();
        $pdo = $GLOBALS['pdo'];
        if (pushLerConfig($pdo, 'push_ativo') !== '1') {
            // Desligado no admin: o trabalhador fica de pé mas não envia (nem consome a fila). Confere de novo em 2 s.
            sleep(2);
            continue;
        }
        if (microtime(true) >= $proxima_manutencao) {
            $m = pushManutencao($pdo);
            $proxima_manutencao = microtime(true) + 30;
            if ($m['sobrecarga']) {
                logPushWorker('SOBRECARGA: fila acima de ' . PUSH_SOBRECARGA_PENDENTES . '; ' . $m['sobrecarga_descartados'] . ' Pix gerados antigos descartados');
            }
        }
        $feitos = pushCicloTrabalhador($pdo, ['lote' => $tamanho_lote]);
        $erros_seguidos = 0;
        if ($feitos === 0) {
            usleep(100000);   // fila vazia: 100 ms (consulta barata no índice)
        }
    } catch (\Throwable $e) {
        $erros_seguidos++;
        logPushWorker('erro no ciclo: ' . $e->getMessage());
        sleep(min(30, 1 + $erros_seguidos * 2));
    }
}
if ($modo_servico) {
    logPushWorker('serviço encerrando (' . (empty($GLOBALS['push_parar']) ? 'reciclagem de 1 h' : 'sinal de parada') . ')');
}
