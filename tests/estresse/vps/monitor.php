<?php
// Monitor de recursos durante o teste de carga. Roda NA VPS; escreve uma linha CSV a cada INTERVALO segundos até receber SIGTERM.
//   php monitor.php /root/res/monitor_x.csv [intervalo=2] &
// Colunas: t, load1, cpu_user, cpu_sys, cpu_iowait, cpu_steal, cpu_idle (%), mem_usada_mb, swap_usada_mb, fpm_painel, fpm_estresse, conexoes_443, conexoes_8080,
//          db_conexoes, db_executando, db_consultas_s, db_row_lock_waits_s, db_slow_s, db_leituras_disco_s, db_log_waits_s, disco_leitura_mb_s, disco_escrita_mb_s
chdir('/var/www/painel');
require_once 'conexao.php';
$saida = $argv[1] ?? '/root/res/monitor.csv';
$int = max(1, (int) ($argv[2] ?? 2));
@mkdir(dirname($saida), 0755, true);
$f = fopen($saida, 'w');
fputcsv($f, ['t', 'load1', 'cpu_user', 'cpu_sys', 'cpu_iowait', 'cpu_steal', 'cpu_idle', 'mem_usada_mb', 'swap_usada_mb', 'fpm_painel', 'fpm_estresse', 'conexoes_443', 'conexoes_8080',
    'db_conexoes', 'db_executando', 'db_consultas_s', 'db_row_lock_waits_s', 'db_slow_s', 'db_leituras_disco_s', 'db_log_waits_s', 'disco_leitura_mb_s', 'disco_escrita_mb_s']);
$parar = false;
if (function_exists('pcntl_signal')) { pcntl_async_signals(true); pcntl_signal(SIGTERM, function () use (&$parar) { $parar = true; }); }

function cpu(): array { $l = explode(' ', preg_replace('/\s+/', ' ', trim(explode("\n", (string) file_get_contents('/proc/stat'))[0]))); return array_map('floatval', array_slice($l, 1, 8)); }
function disco(): array
{
    $r = $w = 0;
    foreach (explode("\n", (string) file_get_contents('/proc/diskstats')) as $l) {
        $c = preg_split('/\s+/', trim($l));
        if (isset($c[2]) && $c[2] === 'sda') { $r = (float) $c[5]; $w = (float) $c[9]; }
    }
    return [$r * 512 / 1048576, $w * 512 / 1048576];
}
function status(PDO $pdo): array
{
    $v = [];
    foreach ($pdo->query("SHOW GLOBAL STATUS WHERE Variable_name IN ('Threads_connected','Threads_running','Questions','Innodb_row_lock_waits','Slow_queries','Innodb_buffer_pool_reads','Innodb_log_waits')") as $r) $v[$r['Variable_name']] = (float) $r['Value'];
    return $v;
}
function contar_proc(string $padrao): int { return (int) trim((string) shell_exec("pgrep -fc " . escapeshellarg($padrao))); }
function conexoes(int $porta): int { return (int) trim((string) shell_exec("ss -H -tn state established '( sport = :$porta )' 2>/dev/null | wc -l")); }

$c0 = cpu(); [$dr0, $dw0] = disco(); $s0 = status($pdo); $t0 = microtime(true); $inicio = $t0;
while (!$parar) {
    sleep($int);
    $t1 = microtime(true); $dt = $t1 - $t0;
    $c1 = cpu(); $d = array_map(fn($a, $b) => $b - $a, $c0, $c1); $tot = max(1, array_sum($d));
    [$dr1, $dw1] = disco();
    try { $s1 = status($pdo); } catch (Throwable $e) { $s1 = $s0; }
    $mem = []; foreach (explode("\n", (string) file_get_contents('/proc/meminfo')) as $l) { if (preg_match('/^(\w+):\s+(\d+)/', $l, $m)) $mem[$m[1]] = (int) $m[2]; }
    fputcsv($f, [round($t1 - $inicio), round(sys_getloadavg()[0], 2),
        round(($d[0] + $d[1]) / $tot * 100, 1), round(($d[2] + $d[5] + $d[6]) / $tot * 100, 1), round($d[4] / $tot * 100, 1), round($d[7] / $tot * 100, 1), round($d[3] / $tot * 100, 1),
        round(($mem['MemTotal'] - $mem['MemAvailable']) / 1024), round(($mem['SwapTotal'] - $mem['SwapFree']) / 1024),
        contar_proc('php-fpm: pool painel'), contar_proc('php-fpm: pool estresse'), conexoes(443), conexoes(8080),
        (int) $s1['Threads_connected'], (int) $s1['Threads_running'], round(($s1['Questions'] - $s0['Questions']) / $dt), round(($s1['Innodb_row_lock_waits'] - $s0['Innodb_row_lock_waits']) / $dt, 1),
        round(($s1['Slow_queries'] - $s0['Slow_queries']) / $dt, 2), round(($s1['Innodb_buffer_pool_reads'] - $s0['Innodb_buffer_pool_reads']) / $dt), round(($s1['Innodb_log_waits'] - $s0['Innodb_log_waits']) / $dt, 2),
        round(($dr1 - $dr0) / $dt, 1), round(($dw1 - $dw0) / $dt, 1)]);
    fflush($f);
    $c0 = $c1; $dr0 = $dr1; $dw0 = $dw1; $s0 = $s1; $t0 = $t1;
}
fclose($f);
