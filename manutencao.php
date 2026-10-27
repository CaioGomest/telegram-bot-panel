<?php
declare(strict_types=1);

/**
 * Liga, desliga e consulta o modo manutenção pelo servidor (SSH), sem precisar do painel nem do banco:
 *
 *   php manutencao.php status
 *   php manutencao.php ligar [--modo=painel|total] [--volta-em=MINUTOS] [--msg="Texto na página"]
 *   php manutencao.php desligar
 *
 * modo "painel" (padrão): só o painel mostra a manutenção; bots, pagamentos, links e crons seguem.
 * modo "total": também o bot, a gateway e os links respondem "em manutenção" e todos os crons pausam (use para mexer no banco).
 * --volta-em é só a previsão mostrada na página (a manutenção não desliga sozinha).
 *
 * Estado e funcionamento: funcoes/manutencao.php. Pela linha de comando a manutenção nunca barra nada, então este script, o
 * "Atualizar Banco" e os outros scripts de SSH continuam funcionando com ela ligada. Rode como o usuário do site ou como root.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/funcoes/manutencao.php';

function manutencaoOpcao(array $argv, string $nome): ?string
{
    foreach ($argv as $a) {
        if (str_starts_with($a, '--' . $nome . '=')) {
            return substr($a, strlen($nome) + 3);
        }
    }
    return null;
}

function manutencaoImprimir(array $e): void
{
    if (!$e['ativo']) {
        echo "Manutenção: DESLIGADA\n";
        return;
    }
    $agora = time();
    echo "Manutenção: LIGADA desde " . date('d/m/Y H:i', $e['desde']) . ($e['por'] !== '' ? " ({$e['por']})" : '') . "\n";
    echo '  modo: ' . ($e['modo'] === 'total' ? 'total (painel, bots, pagamentos, links e crons)' : 'painel (só o painel e a API)') . "\n";
    echo '  previsão de volta: ' . ($e['volta_em'] > 0 ? date('d/m/Y H:i', $e['volta_em']) . ($e['volta_em'] <= $agora ? ' (já passou)' : '') : 'não informada') . "\n";
    if ($e['mensagem'] !== '') {
        echo "  mensagem: {$e['mensagem']}\n";
    }
    echo '  link de liberação: <endereço do painel>/?liberar=' . $e['segredo'] . "\n";
}

$comando = $argv[1] ?? 'status';
try {
    if ($comando === 'status') {
        manutencaoImprimir(manutencaoLer(true));
        exit(0);
    }
    if ($comando === 'ligar') {
        $modo = manutencaoOpcao($argv, 'modo') ?? 'painel';
        if (!in_array($modo, ['painel', 'total'], true)) {
            fwrite(STDERR, "Modo inválido: use --modo=painel ou --modo=total.\n");
            exit(2);
        }
        $volta = manutencaoOpcao($argv, 'volta-em');
        if ($volta !== null && (!ctype_digit($volta) || (int) $volta < 1 || (int) $volta > MANUTENCAO_VOLTA_MAX_MINUTOS)) {
            fwrite(STDERR, 'Previsão inválida: --volta-em é em minutos, de 1 a ' . MANUTENCAO_VOLTA_MAX_MINUTOS . ".\n");
            exit(2);
        }
        $msg = manutencaoOpcao($argv, 'msg') ?? '';
        if (mb_strlen($msg) > MANUTENCAO_MENSAGEM_MAX) {
            fwrite(STDERR, 'Mensagem grande demais: no máximo ' . MANUTENCAO_MENSAGEM_MAX . " caracteres.\n");
            exit(2);
        }
        $usuario = function_exists('posix_getpwuid') && function_exists('posix_geteuid') ? ((posix_getpwuid(posix_geteuid())['name'] ?? '') ?: 'ssh') : 'ssh';
        $estado = manutencaoLigar($msg, $modo, $volta === null ? 0 : (int) $volta, 'ssh:' . $usuario);
        manutencaoImprimir($estado);
        if ($modo === 'total') {
            echo "\nLembrete: no modo total os crons estão pausados. Desligue assim que terminar: php manutencao.php desligar\n";
        }
        exit(0);
    }
    if ($comando === 'desligar') {
        $estava = manutencaoDesligar('ssh');
        echo $estava ? "Manutenção desligada. O sistema voltou ao normal.\n" : "A manutenção já estava desligada.\n";
        exit(0);
    }
    fwrite(STDERR, "Uso: php manutencao.php status | ligar [--modo=painel|total] [--volta-em=MIN] [--msg=\"texto\"] | desligar\n");
    exit(2);
} catch (RuntimeException $e) {
    fwrite(STDERR, 'Erro: ' . $e->getMessage() . "\n");
    exit(1);
}
