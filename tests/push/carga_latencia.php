<?php
declare(strict_types=1);

/**
 * Latência, vazão e custo dos avisos push (plano deploy/PLANO-PUSH-NOTIFICACOES.md, seção 13 "Latência" e "Carga"). Roda NA VPS, com o banco real, contra o
 * serviço de push FALSO (tests/push/servidor_falso.php, 127.0.0.1) — nada sai para a internet. Cria usuários/aparelhos "push-carga-*@estresse.invalid" e APAGA tudo no fim
 * (restaura também as configurações push_*).
 *
 *   sudo -u www-data env PUSH_MODO_TESTE=1 php tests/push/carga_latencia.php <modo> [--opcao=valor ...]
 *
 * Modos:
 *   latencia_a   caminho A (trabalhador vivo): --eventos=600 --ritmo=20   evento -> chegada ao serviço de push; metas p50 < 150 ms, p95 < 500 ms
 *   latencia_b   caminho B (sem trabalhador, envia depois da resposta; cada evento num processo PHP novo): --eventos=300 --conc=4   meta p95 < 800 ms
 *   estavel      carga contínua com o trabalhador: --ritmos=50,100,200 --duracao=30   latência por ritmo, fila máxima, CPU/memória do trabalhador
 *   backlog      fila grande drenada por 1 e depois 2 trabalhadores: --itens=200000 --aparelhos=2000 --rtt=150 --trabalhadores=1,2   vazão, CPU, memória, banco,
 *                e o site (GET /login) medido antes/durante/depois para provar que não piora
 *   gancho       custo no caminho quente do site: aposPagamentoConfirmado() com os avisos desligados x ligados (sem aparelho, 3 aparelhos, fila grande)
 *   gancho_filho (interno) uso: gancho_filho <n> <id_usuario>
 */

if (php_sapi_name() !== 'cli' || getenv('PUSH_MODO_TESTE') !== '1') {
    fwrite(STDERR, "Rode pela linha de comando com PUSH_MODO_TESTE=1.\n");
    exit(2);
}
$raiz = dirname(__DIR__, 2);
chdir($raiz);
require_once $raiz . '/conexao.php';
require_once $raiz . '/funcoes/push.php';
date_default_timezone_set('America/Sao_Paulo');

$modo = (string) ($argv[1] ?? '');
$opc = [];
foreach (array_slice($argv, 2) as $a) {
    if (preg_match('/^--([a-z0-9-]+)=(.*)$/', (string) $a, $m)) {
        $opc[$m[1]] = $m[2];
    }
}
$opt = fn(string $k, $padrao) => $opc[$k] ?? $padrao;

// ---------------------------------------------------------------- utilidades
$ok = 0;
$falhas = 0;
function confere(string $nome, bool $certo, string $detalhe = ''): void
{
    global $ok, $falhas;
    $certo ? $ok++ : $falhas++;
    echo ($certo ? 'OK     ' : 'FALHOU ') . $nome . ($detalhe === '' ? '' : '  -> ' . $detalhe) . PHP_EOL;
}
function info(string $nome, string $detalhe): void
{
    echo 'INFO   ' . $nome . '  -> ' . $detalhe . PHP_EOL;
}
function secao(string $t): void
{
    echo PHP_EOL . '--- ' . $t . PHP_EOL;
}
/** Percentil (0-100) de uma lista de números, em ordem crescente interpolada. */
function pct(array $v, float $p): float
{
    if (!$v) {
        return 0.0;
    }
    sort($v);
    $i = ($p / 100) * (count($v) - 1);
    $a = (int) floor($i);
    $b = (int) ceil($i);
    return $v[$a] + ($v[$b] - $v[$a]) * ($i - $a);
}
function ms(float $s): string
{
    return number_format($s * 1000, 1, ',', '') . ' ms';
}
function resumoLat(array $seg): string
{
    return 'n=' . count($seg) . ' · p50 ' . ms(pct($seg, 50)) . ' · p95 ' . ms(pct($seg, 95)) . ' · p99 ' . ms(pct($seg, 99)) . ' · máx ' . ms($seg ? max($seg) : 0.0);
}

if ($modo === 'gancho_filho') {
    // processo-filho do modo "gancho": mede cada chamada de aposPagamentoConfirmado() com as configurações do banco como estão agora
    $n = (int) ($argv[2] ?? 500);
    $uid = (int) ($argv[3] ?? 0);
    $tempos = [];
    $base = 900000000 + random_int(1, 90000000);
    for ($i = 0; $i < $n; $i++) {
        $t = microtime(true);
        aposPagamentoConfirmado(['id' => $base + $i, 'bot_id' => 0, 'id_telegram' => '0', 'id_dono' => $uid, 'nome_produto' => 'Teste', 'valor' => 10.0, 'id_grupo_telegram' => 'x']);
        $tempos[] = microtime(true) - $t;
    }
    echo json_encode($tempos) . PHP_EOL;
    exit(0);
}
if (!in_array($modo, ['latencia_a', 'latencia_b', 'estavel', 'backlog', 'gancho'], true)) {
    fwrite(STDERR, "Modo inválido. Veja o cabeçalho do arquivo.\n");
    exit(2);
}

// ---------------------------------------------------------------- ambiente: serviço falso, configuração, limpeza
$sufixo = bin2hex(random_bytes(3));
$dir_falso = sys_get_temp_dir() . '/push_carga_' . getmypid();
mkdir($dir_falso, 0777, true);
file_put_contents($dir_falso . '/controle.json', '{}');
$porta = 0;
for ($t = 0; $t < 30 && !$porta; $t++) {
    $p = random_int(20000, 45000);
    $s = @fsockopen('127.0.0.1', $p, $e1, $e2, 0.2);
    if ($s === false) {
        $porta = $p;
    } else {
        fclose($s);
    }
}
$BASE = "http://127.0.0.1:$porta";
$servidor = proc_open([PHP_BINARY, '-S', "127.0.0.1:$porta", __DIR__ . '/servidor_falso.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $dir_falso . '/servidor.log', 'w'], 2 => ['file', $dir_falso . '/servidor.log', 'a']], $pipes,
    __DIR__, ['PUSH_FALSO_DIR' => $dir_falso, 'PHP_CLI_SERVER_WORKERS' => (string) (int) $opt('servidor-workers', 96), 'PATH' => (string) getenv('PATH')]);
for ($t = 0; $t < 80; $t++) {
    $s = @fsockopen('127.0.0.1', $porta, $e1, $e2, 0.2);
    if ($s !== false) {
        fclose($s);
        break;
    }
    usleep(100000);
}

$chaves_config = ['push_ativo', 'push_vapid_publica', 'push_vapid_privada', 'push_contato', 'push_icone_v'];
$config_original = [];
foreach ($chaves_config as $c) {
    $st = $pdo->prepare('SELECT valor FROM configuracoes WHERE chave = ?');
    $st->execute([$c]);
    $v = $st->fetchColumn();
    $config_original[$c] = $v === false ? null : (string) $v;
}
$hora_inicio = (string) $pdo->query("SELECT DATE_FORMAT(NOW(), '%Y-%m-%d %H:00:00')")->fetchColumn();
$metricas_antes = $pdo->prepare('SELECT * FROM push_metricas_hora WHERE hora >= ?');
$metricas_antes->execute([$hora_inicio]);
$metricas_antes = $metricas_antes->fetchAll(PDO::FETCH_ASSOC);
$daemons = [];
$padrao_email = "push-carga-$sufixo-%@estresse.invalid";

$limpeza = function () use (&$servidor, &$daemons, $dir_falso, $config_original, $chaves_config, $hora_inicio, $metricas_antes, $padrao_email, $pdo) {
    foreach ($daemons as $d) {
        if (is_resource($d['proc'])) {
            @proc_terminate($d['proc'], 9);
            @proc_close($d['proc']);
        }
    }
    $daemons = [];
    try {
        $pdo->prepare('DELETE FROM usuarios WHERE email LIKE ?')->execute([$padrao_email]);   // aparelhos e fila caem junto (CASCADE)
        foreach ($chaves_config as $c) {
            if ($config_original[$c] === null) {
                $pdo->prepare('DELETE FROM configuracoes WHERE chave = ?')->execute([$c]);
            } else {
                $pdo->prepare('INSERT INTO configuracoes (chave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)')->execute([$c, $config_original[$c]]);
            }
        }
        $pdo->prepare('DELETE FROM push_metricas_hora WHERE hora >= ?')->execute([$hora_inicio]);
        foreach ($metricas_antes as $m) {
            $cols = array_keys($m);
            $pdo->prepare('INSERT INTO push_metricas_hora (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($m));
        }
    } catch (\Throwable $e) {
        echo 'AVISO: limpeza incompleta: ' . $e->getMessage() . PHP_EOL;
    }
    @unlink(PUSH_ARQUIVO_BATIMENTO);
    if (is_resource($servidor)) {
        proc_terminate($servidor);
        proc_close($servidor);
    }
    foreach (glob($dir_falso . '/*') ?: [] as $a) {
        @unlink($a);
    }
    @rmdir($dir_falso);
};
register_shutdown_function($limpeza);

function controle(array $c): void
{
    global $dir_falso;
    file_put_contents($dir_falso . '/controle.json', json_encode($c));
}
function limparRecebidos(): void
{
    global $dir_falso;
    @unlink($dir_falso . '/recebidos.jsonl');
}
function contarRecebidos(): int
{
    global $dir_falso;
    $arq = $dir_falso . '/recebidos.jsonl';
    if (!is_file($arq)) {
        return 0;
    }
    $n = 0;
    $f = fopen($arq, 'rb');
    while (!feof($f)) {
        $bloco = fread($f, 1 << 20);
        $n += substr_count((string) $bloco, "\n");
    }
    fclose($f);
    return $n;
}
/** Lê os registros do serviço falso: lista de ['ini','fim','caminho','codigo', ...]. */
function lerRecebidos(): array
{
    global $dir_falso;
    $arq = $dir_falso . '/recebidos.jsonl';
    $saida = [];
    if (!is_file($arq)) {
        return $saida;
    }
    $f = fopen($arq, 'rb');
    while (($l = fgets($f)) !== false) {
        $j = json_decode($l, true);
        if (is_array($j)) {
            $saida[] = $j;
        }
    }
    fclose($f);
    return $saida;
}

/** Par de chaves de aparelho (UM só para todos os aparelhos de teste: o teste decifra qualquer aviso com ele). */
function parAparelho(): array
{
    $par = pushGerarParEc();
    $d = openssl_pkey_get_details($par['chave']);
    $auth = random_bytes(16);
    return ['pub' => pushB64uCodificar($par['publica']), 'priv' => pushB64uCodificar(str_pad($d['ec']['d'], 32, "\0", STR_PAD_LEFT)), 'auth' => pushB64uCodificar($auth)];
}
/** Cria $n usuários (1 aparelho cada) em massa. Devolve ['usuarios' => [ids], 'aparelhos' => [ids]]. */
function criarEmMassa(int $n, string $cenario, string $prefixo, array $par): array
{
    global $pdo, $BASE, $sufixo;
    $usuarios = [];
    for ($ini = 0; $ini < $n; $ini += 500) {
        $lin = [];
        $val = [];
        for ($i = $ini; $i < min($n, $ini + 500); $i++) {
            $lin[] = "(?, ?, 'x', 'usuario')";
            array_push($val, 'Push Carga', "push-carga-$sufixo-$prefixo$i@estresse.invalid");
        }
        $pdo->prepare('INSERT INTO usuarios (nome, email, senha, perfil) VALUES ' . implode(',', $lin))->execute($val);
    }
    $st = $pdo->prepare('SELECT id, email FROM usuarios WHERE email LIKE ? ORDER BY id');
    $st->execute(["push-carga-$sufixo-$prefixo%@estresse.invalid"]);
    $porIndice = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) {
        if (preg_match('/-' . preg_quote($prefixo, '/') . '(\d+)@/', (string) $u['email'], $mm)) {
            $porIndice[(int) $mm[1]] = (int) $u['id'];
        }
    }
    ksort($porIndice);
    $usuarios = array_values($porIndice);
    $aparelhos = [];
    for ($ini = 0; $ini < $n; $ini += 500) {
        $lin = [];
        $val = [];
        for ($i = $ini; $i < min($n, $ini + 500); $i++) {
            $endpoint = "$BASE/$cenario/$prefixo$i";
            $lin[] = "(?, ?, ?, ?, ?, 'desktop', 'Carga', NOW())";
            array_push($val, $usuarios[$i], hash('sha256', $endpoint), $endpoint, $par['pub'], $par['auth']);
        }
        $pdo->prepare('INSERT INTO push_inscricoes (id_usuario, endpoint_hash, endpoint, p256dh, auth, plataforma, apelido, ultimo_visto) VALUES ' . implode(',', $lin))->execute($val);
    }
    $st = $pdo->prepare('SELECT i.id FROM push_inscricoes i JOIN usuarios u ON u.id = i.id_usuario WHERE u.email LIKE ? ORDER BY i.id');
    $st->execute(["push-carga-$sufixo-$prefixo%@estresse.invalid"]);
    $aparelhos = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    return ['usuarios' => $usuarios, 'aparelhos' => $aparelhos];
}

// ---- trabalhador (processo de verdade, como o serviço do systemd: Nice=10)
function abrirDaemon(): array
{
    global $raiz, $dir_falso, $daemons;
    $proc = proc_open(['nice', '-n', '10', PHP_BINARY, $raiz . '/cron/push_worker.php', '--loop'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $dir_falso . '/worker.err', 'a']],
        $pipes, $raiz, ['PUSH_MODO_TESTE' => '1', 'PATH' => (string) getenv('PATH')]);
    $pid = (int) (proc_get_status($proc)['pid'] ?? 0);
    $d = ['proc' => $proc, 'pid' => $pid, 'inicio' => microtime(true)];
    $daemons[$pid] = $d;
    return $d;
}
function esperarViva(float $max = 10.0): bool
{
    $t = microtime(true);
    while (microtime(true) - $t < $max) {
        if (pushTrabalhadorVivo()) {
            return true;
        }
        usleep(100000);
    }
    return false;
}
function statsProc(int $pid): array
{
    $r = ['cpu_s' => 0.0, 'rss_mb' => 0.0, 'pico_mb' => 0.0];
    $stat = @file_get_contents("/proc/$pid/stat");
    if ($stat !== false && preg_match('/\)\s+\S+\s+(?:\S+\s+){10}(\d+)\s+(\d+)/', $stat, $m)) {
        $r['cpu_s'] = ((int) $m[1] + (int) $m[2]) / 100.0;
    }
    $st = @file_get_contents("/proc/$pid/status");
    if ($st !== false) {
        if (preg_match('/VmRSS:\s+(\d+) kB/', $st, $m)) {
            $r['rss_mb'] = (int) $m[1] / 1024;
        }
        if (preg_match('/VmHWM:\s+(\d+) kB/', $st, $m)) {
            $r['pico_mb'] = (int) $m[1] / 1024;
        }
    }
    return $r;
}
function pararDaemon(array $d): array
{
    global $daemons;
    $s = statsProc($d['pid']);
    @proc_terminate($d['proc'], 15);
    for ($i = 0; $i < 60; $i++) {
        if (!(proc_get_status($d['proc'])['running'] ?? false)) {
            break;
        }
        usleep(250000);
    }
    if (proc_get_status($d['proc'])['running'] ?? false) {
        @proc_terminate($d['proc'], 9);
    }
    @proc_close($d['proc']);
    unset($daemons[$d['pid']]);
    @unlink(PUSH_ARQUIVO_BATIMENTO);
    return $s;
}
function statusBanco(): array
{
    global $pdo;
    $r = [];
    foreach ($pdo->query("SHOW GLOBAL STATUS WHERE Variable_name IN ('Questions','Threads_running','Innodb_rows_inserted','Innodb_rows_updated','Innodb_rows_deleted','Innodb_rows_read')")->fetchAll(PDO::FETCH_NUM) as $l) {
        $r[$l[0]] = (int) $l[1];
    }
    return $r;
}
function cpuGeral(): array
{
    $l = explode(' ', preg_replace('/\s+/', ' ', trim((string) strtok((string) file_get_contents('/proc/stat'), "\n"))));
    $v = array_map('intval', array_slice($l, 1, 8));
    return ['total' => array_sum($v), 'ocioso' => $v[3] + $v[4]];
}
function cpuBanco(): float
{
    $pid = (int) trim((string) @shell_exec('pgrep -x mariadbd | head -1'));
    if ($pid <= 0) {
        return 0.0;
    }
    $stat = @file_get_contents("/proc/$pid/stat");
    return $stat !== false && preg_match('/\)\s+\S+\s+(?:\S+\s+){10}(\d+)\s+(\d+)/', $stat, $m) ? ((int) $m[1] + (int) $m[2]) / 100.0 : 0.0;
}

// ---------------------------------------------------------------- preparo comum
pushGarantirChaves($pdo);
definirConfigSistema('push_ativo', '1');
definirConfigSistema('push_icone_v', 'vcarga');
definirConfigSistema('push_contato', 'https://exemplo-teste.invalid');
pushAtivo(true);
confere('serviço falso no ar (porta ' . $porta . ') e modo de teste ligado', $porta > 0 && pushModoTeste() && pushAtivo());
$par = parAparelho();
$tag_de = function (string $corpo) use ($par): ?string {
    $t = pushDecifrarPayloadParaTeste($corpo, $par['priv'], $par['pub'], $par['auth']);
    $j = $t === null ? null : json_decode($t, true);
    return is_array($j) ? (string) ($j['g'] ?? '') : null;
};
$carga_antes = sys_getloadavg();
info('máquina', trim((string) shell_exec('nproc')) . ' CPUs · ' . trim((string) shell_exec("free -m | awk 'NR==2{print $2\" MB (livre \"$7\" MB)\"}'")) . ' · carga média ' . implode(' ', array_map(fn($x) => number_format($x, 2), $carga_antes)));
$venda_base = 800000000 + random_int(1, 90000000);

// ================================================================ latencia_a
if ($modo === 'latencia_a') {
    $n = (int) $opt('eventos', 600);
    $ritmo = (float) $opt('ritmo', 20);
    secao("Caminho A — trabalhador vivo · $n eventos a $ritmo/s");
    controle(['latencia_global_ms' => 0, 'log_minimo' => true]);
    $mass = criarEmMassa($n, 'ok', 'k', $par);
    confere("$n usuários com 1 aparelho cada criados", count($mass['usuarios']) === $n && count($mass['aparelhos']) === $n);
    $d = abrirDaemon();
    confere('trabalhador (nice 10) no ar com batimento', esperarViva(), 'pid ' . $d['pid']);
    sleep(1);
    $t0 = [];
    $custo = [];
    $inicio = microtime(true);
    for ($i = 0; $i < $n; $i++) {
        $alvo = $inicio + $i / $ritmo;
        $agora = microtime(true);
        if ($alvo > $agora) {
            usleep((int) (($alvo - $agora) * 1e6));
        }
        $t0[$i] = microtime(true);
        enfileirarPush($mass['usuarios'][$i], PUSH_TIPO_PIX_PAGO, $venda_base + $i);
        $custo[] = microtime(true) - $t0[$i];
    }
    $limite = microtime(true) + 20;
    while (contarRecebidos() < $n && microtime(true) < $limite) {
        usleep(200000);
    }
    $rec = lerRecebidos();
    $ini = [];
    $fim = [];
    $vistos = [];
    foreach ($rec as $r) {
        if (preg_match('#^/ok/k(\d+)$#', (string) $r['caminho'], $mm)) {
            $i = (int) $mm[1];
            $vistos[$i] = ($vistos[$i] ?? 0) + 1;
            $ini[] = $r['ini'] - $t0[$i];
            $fim[] = $r['fim'] - $t0[$i];
        }
    }
    $s = pararDaemon($d);
    confere("todos os $n avisos chegaram ao serviço de push, exatamente 1 vez cada", count($vistos) === $n && max($vistos ?: [0]) === 1, count($vistos) . ' de ' . $n . ', duplicados: ' . count(array_filter($vistos, fn($x) => $x > 1)));
    info('custo de enfileirarPush no caminho quente', resumoLat($custo));
    info('latência evento -> chegada ao serviço de push', resumoLat($ini));
    confere('meta caminho A: p50 < 150 ms e p95 < 500 ms', pct($ini, 50) < 0.150 && pct($ini, 95) < 0.500, 'p50 ' . ms(pct($ini, 50)) . ' · p95 ' . ms(pct($ini, 95)));
    info('trabalhador', sprintf('CPU %.1f s em %.0f s (%.1f%% de 1 núcleo) · memória %.0f MB (pico %.0f MB)', $s['cpu_s'], microtime(true) - $d['inicio'], 100 * $s['cpu_s'] / max(1, microtime(true) - $d['inicio']), $s['rss_mb'], $s['pico_mb']));
}

// ================================================================ latencia_b
if ($modo === 'latencia_b') {
    $n = (int) $opt('eventos', 300);
    $conc = (int) $opt('conc', 4);
    secao("Caminho B — sem trabalhador (cada evento é um processo PHP novo que envia depois da resposta) · $n eventos, $conc em paralelo");
    @unlink(PUSH_ARQUIVO_BATIMENTO);
    confere('sem trabalhador vivo', !pushTrabalhadorVivo());
    controle(['latencia_global_ms' => 0, 'log_minimo' => true]);
    $mass = criarEmMassa($n, 'ok', 'k', $par);
    $t0 = [];
    $ativos = [];
    $proximo = 0;
    $feitos = 0;
    $env = ['PUSH_MODO_TESTE' => '1', 'PATH' => (string) getenv('PATH')];
    while ($feitos < $n) {
        while ($proximo < $n && count($ativos) < $conc) {
            $proc = proc_open([PHP_BINARY, __DIR__ . '/_evento_filho.php', (string) $mass['usuarios'][$proximo], '2', (string) ($venda_base + $proximo)], [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $raiz, $env);
            $ativos[$proximo] = [$proc, $pipes];
            $proximo++;
        }
        foreach ($ativos as $i => [$proc, $pipes]) {
            if (!(proc_get_status($proc)['running'] ?? false)) {
                $t0[$i] = (float) trim((string) stream_get_contents($pipes[1]));
                fclose($pipes[1]);
                proc_close($proc);
                unset($ativos[$i]);
                $feitos++;
            }
        }
        usleep(2000);
    }
    $limite = microtime(true) + 15;
    while (contarRecebidos() < $n && microtime(true) < $limite) {
        usleep(200000);
    }
    $ini = [];
    $vistos = [];
    foreach (lerRecebidos() as $r) {
        if (preg_match('#^/ok/k(\d+)$#', (string) $r['caminho'], $mm) && isset($t0[(int) $mm[1]])) {
            $vistos[(int) $mm[1]] = ($vistos[(int) $mm[1]] ?? 0) + 1;
            $ini[] = $r['ini'] - $t0[(int) $mm[1]];
        }
    }
    confere("todos os $n avisos chegaram exatamente 1 vez, só pelo caminho B (a fila ficou vazia)", count($vistos) === $n && max($vistos ?: [0]) === 1
        && (int) $pdo->query("SELECT COUNT(*) FROM push_fila f JOIN push_inscricoes i ON i.id = f.id_inscricao JOIN usuarios u ON u.id = i.id_usuario WHERE u.email LIKE '" . "push-carga-$sufixo-%" . "@estresse.invalid'")->fetchColumn() === 0,
        count($vistos) . ' de ' . $n);
    info('latência evento -> chegada ao serviço de push (inclui os ~2 SELECT/UPDATE de reserva, criptografia e o POST)', resumoLat($ini));
    confere('meta caminho B: p95 < 800 ms', pct($ini, 95) < 0.800, 'p95 ' . ms(pct($ini, 95)));
}

// ================================================================ estavel
if ($modo === 'estavel') {
    $ritmos = array_map('intval', explode(',', (string) $opt('ritmos', '50,100,200')));
    $dur = (int) $opt('duracao', 30);
    $pool = (int) $opt('aparelhos', 2000);
    secao("Carga contínua com o trabalhador (nice 10) · ritmos " . implode('/', $ritmos) . " por segundo · $dur s cada · $pool aparelhos");
    controle(['latencia_global_ms' => (int) $opt('rtt', 100), 'log_minimo' => false]);
    $mass = criarEmMassa($pool, 'ok', 'k', $par);
    $d = abrirDaemon();
    confere('trabalhador no ar', esperarViva());
    sleep(1);
    $contador = 0;
    foreach ($ritmos as $ritmo) {
        limparRecebidos();
        $total = $ritmo * $dur;
        $t0 = [];
        $fila_max = 0;
        $ultima_amostra = 0.0;
        $cpu0 = statsProc($d['pid'])['cpu_s'];
        $geral0 = cpuGeral();
        $inicio = microtime(true);
        for ($i = 0; $i < $total; $i++) {
            $alvo = $inicio + $i / $ritmo;
            $agora = microtime(true);
            if ($alvo > $agora) {
                usleep((int) (($alvo - $agora) * 1e6));
            }
            $idv = $venda_base + (++$contador);
            $t0[$idv] = microtime(true);
            enfileirarPush($mass['usuarios'][$i % $pool], PUSH_TIPO_PIX_PAGO, $idv);
            if ($t0[$idv] - $ultima_amostra >= 1.0) {
                $ultima_amostra = $t0[$idv];
                $fila_max = max($fila_max, (int) $pdo->query("SELECT COUNT(*) FROM push_fila WHERE estado IN ('pendente','enviando')")->fetchColumn());
            }
        }
        $fim_prod = microtime(true);
        $limite = microtime(true) + 60;
        while (contarRecebidos() < $total && microtime(true) < $limite) {
            usleep(250000);
        }
        $tempo_total = microtime(true) - $inicio;
        $cpu = statsProc($d['pid'])['cpu_s'] - $cpu0;
        $geral1 = cpuGeral();
        $lat = [];
        $vistos = [];
        foreach (lerRecebidos() as $r) {
            $tag = $tag_de((string) base64_decode((string) ($r['corpo_b64'] ?? '')));
            if ($tag !== null && preg_match('/^pp-(\d+)$/', $tag, $mm) && isset($t0[(int) $mm[1]])) {
                $vistos[(int) $mm[1]] = ($vistos[(int) $mm[1]] ?? 0) + 1;
                $lat[] = $r['ini'] - $t0[(int) $mm[1]];
            }
        }
        $perdidos = $total - count($vistos);
        $dupl = count(array_filter($vistos, fn($x) => $x > 1));
        confere("ritmo $ritmo/s: $total avisos, nenhum perdido nem duplicado", $perdidos === 0 && $dupl === 0, "perdidos $perdidos · duplicados $dupl");
        info("ritmo $ritmo/s · latência evento -> serviço de push", resumoLat($lat));
        info("ritmo $ritmo/s · recursos", sprintf('fila máx %d · trabalhador %.1f%% de 1 núcleo (CPU %.1f s) · máquina %.0f%% ocupada · produtor levou %.1f s para %d eventos', $fila_max,
            100 * $cpu / max(1, $tempo_total), $cpu, 100 * (1 - ($geral1['ocioso'] - $geral0['ocioso']) / max(1, $geral1['total'] - $geral0['total'])), $fim_prod - $inicio, $total));
        sleep(2);
    }
    $s = pararDaemon($d);
    info('trabalhador ao final', sprintf('memória %.0f MB (pico %.0f MB)', $s['rss_mb'], $s['pico_mb']));
}

// ================================================================ backlog
if ($modo === 'backlog') {
    $itens = (int) $opt('itens', 200000);
    $pool = (int) $opt('aparelhos', 2000);
    $por_aparelho = (int) ceil($itens / $pool);
    $rtt = (int) $opt('rtt', 150);
    $trabs = array_map('intval', explode(',', (string) $opt('trabalhadores', '1,2')));
    secao("Fila grande: $itens avisos (Pix pago) para $pool aparelhos · serviço de push falso com $rtt ms de resposta · trabalhadores: " . implode(' e ', $trabs));
    $mass = criarEmMassa($pool, 'ok', 'k', $par);
    $ids_aparelhos = implode(',', $mass['aparelhos']);
    $sonda = function (int $n): array {
        // GET /login do próprio site (via 127.0.0.1, certificado real), 1 a cada 250 ms
        $lat = [];
        for ($i = 0; $i < $n; $i++) {
            $ch = curl_init('https://coyotebot.com.br/login');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_RESOLVE => ['coyotebot.com.br:443:127.0.0.1'], CURLOPT_TIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => true]);
            curl_exec($ch);
            $lat[] = (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME);
            curl_close($ch);
            usleep(250000);
        }
        return $lat;
    };
    $base_site = $sonda(30);
    info('site (GET /login) sem o trabalhador', resumoLat($base_site));
    foreach ($trabs as $W) {
        limparRecebidos();
        controle(['latencia_global_ms' => $rtt, 'log_minimo' => true]);
        $pdo->exec("DELETE FROM push_fila WHERE id_inscricao IN ($ids_aparelhos)");
        // semeia: um "venda" por vez para TODOS os aparelhos -> um lote de 300 pega 300 aparelhos diferentes (sem resumo)
        $t = microtime(true);
        $st = $pdo->prepare("INSERT INTO push_fila (id_inscricao, tipo, id_venda, disponivel_em, expira_em) SELECT id, 2, ?, NOW(), NOW() + INTERVAL 1 DAY FROM push_inscricoes WHERE id IN ($ids_aparelhos)");
        for ($v = 0; $v < $por_aparelho; $v++) {
            $st->execute([$venda_base + $v]);
        }
        $total = (int) $pdo->query("SELECT COUNT(*) FROM push_fila WHERE id_inscricao IN ($ids_aparelhos)")->fetchColumn();
        info("semeado ($W trabalhador" . ($W > 1 ? 'es' : '') . ')', "$total itens em " . number_format(microtime(true) - $t, 1, ',', '') . ' s');
        $db0 = statusBanco();
        $cpuDb0 = cpuBanco();
        $geral0 = cpuGeral();
        $ds = [];
        for ($k = 0; $k < $W; $k++) {
            $ds[] = abrirDaemon();
        }
        $t_ini = microtime(true);
        $amostras = [];
        $sonda_dur = [];
        $ultimo = 0;
        $vazao_pico = 0.0;
        $prazo = $t_ini + (float) $opt('limite-s', 1500);
        $ultimo_t = $t_ini;
        while (microtime(true) < $prazo) {
            $x = microtime(true);
            $sonda_dur = array_merge($sonda_dur, $sonda(4));   // ~1 s de sondas do site
            $pend = (int) $pdo->query("SELECT COUNT(*) FROM push_fila WHERE id_inscricao IN ($ids_aparelhos)")->fetchColumn();
            $env = contarRecebidos();
            $agora = microtime(true);
            if ($agora - $ultimo_t >= 5.0) {
                $vz = ($env - $ultimo) / ($agora - $ultimo_t);
                $vazao_pico = max($vazao_pico, $vz);
                $amostras[] = ['t' => $agora - $t_ini, 'fila' => $pend, 'env' => $env, 'vz' => $vz];
                echo sprintf("       t=%4.0fs fila=%7d entregues=%7d vazão=%6.1f/s\n", $agora - $t_ini, $pend, $env, $vz);
                $ultimo = $env;
                $ultimo_t = $agora;
            }
            if ($pend === 0) {
                break;
            }
        }
        $dur = microtime(true) - $t_ini;
        $env = contarRecebidos();
        $restante = (int) $pdo->query("SELECT COUNT(*) FROM push_fila WHERE id_inscricao IN ($ids_aparelhos)")->fetchColumn();
        $db1 = statusBanco();
        $cpuDb1 = cpuBanco();
        $geral1 = cpuGeral();
        $cpu_trab = 0.0;
        $pico = 0.0;
        foreach ($ds as $d) {
            $s = pararDaemon($d);
            $cpu_trab += $s['cpu_s'];
            $pico = max($pico, $s['pico_mb']);
        }
        confere("$W trabalhador(es): a fila de $total esvaziou", $restante === 0, "restante $restante em " . number_format($dur, 0, ',', '') . ' s');
        confere("$W trabalhador(es): $total entregues ao serviço de push, nenhum a mais (sem duplicata) nem a menos", $env === $total, "entregues $env de $total");
        info("$W trabalhador(es): vazão média", sprintf('%.0f avisos/s (pico em janela de 5 s: %.0f/s) · %s em %.0f s', $env / max(1, $dur), $vazao_pico, number_format($env, 0, ',', '.'), $dur));
        info("$W trabalhador(es): CPU", sprintf('trabalhadores %.1f s no total (%.2f ms por aviso) · MariaDB %.1f s · máquina %.0f%% ocupada em média (2 CPUs)', $cpu_trab, 1000 * $cpu_trab / max(1, $env), $cpuDb1 - $cpuDb0,
            100 * (1 - ($geral1['ocioso'] - $geral0['ocioso']) / max(1, $geral1['total'] - $geral0['total']))));
        info("$W trabalhador(es): memória por trabalhador (pico)", sprintf('%.0f MB', $pico));
        info("$W trabalhador(es): banco", sprintf('%.0f consultas/s · linhas inseridas %d, alteradas %d, apagadas %d', ($db1['Questions'] - $db0['Questions']) / max(1, $dur),
            $db1['Innodb_rows_inserted'] - $db0['Innodb_rows_inserted'], $db1['Innodb_rows_updated'] - $db0['Innodb_rows_updated'], $db1['Innodb_rows_deleted'] - $db0['Innodb_rows_deleted']));
        info("$W trabalhador(es): site (GET /login) durante a drenagem", resumoLat($sonda_dur));
        $sem_falha = count(array_filter($sonda_dur, fn($x) => $x > 5.0)) === 0;
        confere("$W trabalhador(es): o site não piora (p50 durante ≤ 2× o p50 sem o trabalhador + 50 ms; nenhuma sonda acima de 5 s)", pct($sonda_dur, 50) <= 2 * pct($base_site, 50) + 0.050 && $sem_falha,
            'p50 ' . ms(pct($sonda_dur, 50)) . ' contra ' . ms(pct($base_site, 50)) . ' · p95 ' . ms(pct($sonda_dur, 95)) . ' contra ' . ms(pct($base_site, 95)));
        sleep(3);
    }
    $depois = $sonda(30);
    info('site (GET /login) depois', resumoLat($depois));
}

// ================================================================ gancho (custo no caminho quente)
if ($modo === 'gancho') {
    $n = (int) $opt('chamadas', 500);
    secao("Custo no caminho quente: aposPagamentoConfirmado() · $n chamadas por cenário");
    $mass = criarEmMassa(1, 'ok', 'g', $par);                        // 1 usuário com 1 aparelho (usado também como "3 aparelhos" abaixo)
    $u_sem = criarEmMassa(1, 'ok', 'h', $par)['usuarios'][0];
    $pdo->prepare('DELETE FROM push_inscricoes WHERE id_usuario = ?')->execute([$u_sem]);   // usuário SEM aparelho
    $u_tres = $mass['usuarios'][0];
    for ($k = 1; $k <= 2; $k++) {
        $endpoint = "$BASE/ok/extra$k";
        $pdo->prepare("INSERT INTO push_inscricoes (id_usuario, endpoint_hash, endpoint, p256dh, auth, plataforma, apelido, ultimo_visto) VALUES (?, ?, ?, ?, ?, 'desktop', 'Carga', NOW())")
            ->execute([$u_tres, hash('sha256', $endpoint), $endpoint, $par['pub'], $par['auth']]);
    }
    controle(['latencia_global_ms' => 0, 'log_minimo' => true]);
    $medir = function (string $rotulo, string $ativo, int $uid) use ($n, $pdo, $raiz): array {
        definirConfigSistema('push_ativo', $ativo);
        pushBatimento();   // "trabalhador vivo": o caminho B não dispara dentro da medição (a fila fica parada)
        $saida = shell_exec('PUSH_MODO_TESTE=1 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' gancho_filho ' . $n . ' ' . $uid);
        $t = json_decode((string) $saida, true) ?: [];
        info($rotulo, resumoLat($t));
        $pdo->exec("DELETE f FROM push_fila f JOIN push_inscricoes i ON i.id = f.id_inscricao JOIN usuarios u ON u.id = i.id_usuario WHERE u.email LIKE 'push-carga-%'");
        return $t;
    };
    // aquecimento (opcache/conexão) e depois cada cenário
    $medir('(aquecimento)', '0', $u_sem);
    $off = $medir('avisos DESLIGADOS', '0', $u_tres);
    $on0 = $medir('avisos ligados · vendedor SEM aparelho', '1', $u_sem);
    $on3 = $medir('avisos ligados · vendedor com 3 aparelhos', '1', $u_tres);
    // fila grande (200 mil itens parados) e de novo
    $pool = 1000;
    $m2 = criarEmMassa($pool, 'ok', 'q', $par);
    $ids = implode(',', $m2['aparelhos']);
    $st = $pdo->prepare("INSERT INTO push_fila (id_inscricao, tipo, id_venda, disponivel_em, expira_em, estado, reservado_ate) SELECT id, 2, ?, NOW() + INTERVAL 1 DAY, NOW() + INTERVAL 2 DAY, 'pendente', NULL FROM push_inscricoes WHERE id IN ($ids)");
    $t = microtime(true);
    for ($v = 0; $v < (int) $opt('fila-grande', 200); $v++) {
        $st->execute([$venda_base + 1000000 + $v]);
    }
    $grande = (int) $pdo->query("SELECT COUNT(*) FROM push_fila WHERE id_inscricao IN ($ids)")->fetchColumn();
    info('fila grande semeada', "$grande itens parados em " . number_format(microtime(true) - $t, 1, ',', '') . ' s');
    definirConfigSistema('push_ativo', '1');
    pushBatimento();
    $saida = shell_exec('PUSH_MODO_TESTE=1 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' gancho_filho ' . $n . ' ' . $u_tres);
    $on3g = json_decode((string) $saida, true) ?: [];
    info("avisos ligados · 3 aparelhos · fila com $grande itens", resumoLat($on3g));
    $pdo->exec("DELETE f FROM push_fila f JOIN push_inscricoes i ON i.id = f.id_inscricao JOIN usuarios u ON u.id = i.id_usuario WHERE u.email LIKE 'push-carga-%'");
    $d = fn(array $a, array $b, float $p) => (pct($a, $p) - pct($b, $p)) * 1000;
    info('diferença ligado(sem aparelho) − desligado', sprintf('p50 %+.2f ms · p95 %+.2f ms · p99 %+.2f ms', $d($on0, $off, 50), $d($on0, $off, 95), $d($on0, $off, 99)));
    info('diferença ligado(3 aparelhos) − desligado', sprintf('p50 %+.2f ms · p95 %+.2f ms · p99 %+.2f ms', $d($on3, $off, 50), $d($on3, $off, 95), $d($on3, $off, 99)));
    info('diferença ligado(3 aparelhos, fila grande) − desligado', sprintf('p50 %+.2f ms · p95 %+.2f ms · p99 %+.2f ms', $d($on3g, $off, 50), $d($on3g, $off, 95), $d($on3g, $off, 99)));
    confere('vendedor sem aparelho: custo extra do gancho p95 ≤ 1,5 ms', $d($on0, $off, 95) <= 1.5);
    confere('vendedor com 3 aparelhos: custo extra p95 ≤ 5 ms', $d($on3, $off, 95) <= 5.0);
    confere('com 3 aparelhos e fila grande: custo extra p95 ≤ 8 ms', $d($on3g, $off, 95) <= 8.0);
}

echo PHP_EOL . "RESUMO: $ok ok, $falhas falhas" . PHP_EOL;
exit($falhas > 0 ? 1 : 0);
