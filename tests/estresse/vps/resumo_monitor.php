<?php
// Resume o CSV do monitor.php (médias e picos da rodada). Uso: php resumo_monitor.php arquivo.csv [inicio_epoch fim_epoch]
$f = fopen($argv[1] ?? '', 'r');
if (!$f) { echo "sem CSV\n"; exit(1); }
$cab = fgetcsv($f);
$linhas = [];
while (($l = fgetcsv($f)) !== false) { if (count($l) === count($cab)) $linhas[] = array_combine($cab, $l); }
if (!$linhas) { echo "CSV vazio\n"; exit(1); }
$col = fn(string $c) => array_map('floatval', array_column($linhas, $c));
$media = fn(array $v) => $v ? array_sum($v) / count($v) : 0;
// ignora a 1ª e a última amostra (assentamento)
$core = array_slice($linhas, 1, max(1, count($linhas) - 2));
$c2 = fn(string $c) => array_map('floatval', array_column($core, $c));
printf("monitor: %d amostras de 2 s\n", count($linhas));
printf("CPU usuário %.0f%% (pico %.0f)  sistema %.0f%% (pico %.0f)  iowait médio %.1f%% (pico %.1f)  ocioso médio %.0f%% (mín %.0f)\n",
    $media($c2('cpu_user')), max($c2('cpu_user')), $media($c2('cpu_sys')), max($c2('cpu_sys')), $media($c2('cpu_iowait')), max($c2('cpu_iowait')), $media($c2('cpu_idle')), min($c2('cpu_idle')));
printf("load médio %.1f (pico %.1f)   memória usada pico %d MB   swap pico %d MB\n", $media($c2('load1')), max($c2('load1')), max($col('mem_usada_mb')), max($col('swap_usada_mb')));
printf("PHP-FPM processos (pico): painel %d  estresse %d   conexões web (pico): 443=%d 8080=%d\n", max($col('fpm_painel')), max($col('fpm_estresse')), max($col('conexoes_443')), max($col('conexoes_8080')));
printf("MariaDB: conexões pico %d  executando pico %d  consultas/s médio %d (pico %d)  row-lock-waits/s pico %.1f  slow/s pico %.2f  leituras de disco/s pico %d  log-waits/s pico %.2f\n",
    max($col('db_conexoes')), max($col('db_executando')), $media($c2('db_consultas_s')), max($col('db_consultas_s')), max($col('db_row_lock_waits_s')), max($col('db_slow_s')), max($col('db_leituras_disco_s')), max($col('db_log_waits_s')));
printf("disco: leitura média %.1f MB/s (pico %.1f)  escrita média %.1f MB/s (pico %.1f)\n", $media($c2('disco_leitura_mb_s')), max($col('disco_leitura_mb_s')), $media($c2('disco_escrita_mb_s')), max($col('disco_escrita_mb_s')));
