<?php
// Gerador de carga que roda DENTRO do servidor (curl_multi, um processo, sessões reais). Evita o firewall de borda da hospedagem,
// que bloqueia um IP externo que passa de ~60 req/s. Uso:
//   php zz_carga.php fase=mistura usuarios=300 degraus=10,20,40 duracao=20 [pensa=0] [parar_erro=5] [parar_p95=8000] [prefixo=cliente] [email_fmt=cliente%d@gmial.com]
// fases: login | mistura | paginas | publico (slug=...) | admin (email=...) | soak
set_time_limit(0);
ini_set('memory_limit', '1024M');
$a = ['fase' => 'mistura', 'usuarios' => 300, 'degraus' => '10,20,40', 'duracao' => 20, 'pensa' => 0, 'parar_erro' => 5, 'parar_p95' => 8000, 'base' => 'https://telegram.stackcode.com.br', 'slug' => 'estresse-link', 'email' => '', 'timeout' => 30];
foreach (array_slice($argv, 1) as $kv) { if (strpos($kv, '=') !== false) { [$k, $v] = explode('=', $kv, 2); $a[$k] = $v; } }
$BASE = rtrim($a['base'], '/');
$degraus = array_map('intval', explode(',', (string) $a['degraus']));
$MISTURA = [
    [14, '/'], [8, '/?periodo=30dias'], [8, '/bots'], [8, '/fluxos'], [10, '/leads'], [4, '/leads?busca=Ana'], [4, '/leads?status=pago'],
    [5, '/links_rastreamento'], [4, '/remarketing'], [6, '/ranking'], [3, '/ranking?aba=mensal'], [3, '/webhooks'], [2, '/configuracao_usuario'], [2, '/gateways'],
    [10, '/api?action=listar_bots'], [6, '/api?action=listar_fluxos'], [12, '/ajax/notificacoes'], [3, '/api?action=listar_links_rastreamento'],
];
function sorteia(array $l): string { $t = array_sum(array_column($l, 0)); $r = mt_rand() / mt_getrandmax() * $t; foreach ($l as [$p, $c]) { $r -= $p; if ($r <= 0) return $c; } return $l[0][1]; }
function pct(array $v, float $p): float { if (!$v) return 0; sort($v); return $v[min(count($v) - 1, (int) floor($p / 100 * count($v)))]; }

function novo_handle(): CurlHandle
{
    $ch = curl_init();
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => '', CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 teste-estresse', CURLOPT_SSL_VERIFYPEER => true, CURLOPT_ENCODING => '']);
    return $ch;
}

/**
 * Roda $vus usuários virtuais em paralelo por $seg segundos. Cada VU tem o seu handle (cookie próprio).
 * $inicio($i, $ch): prepara a sessão (login) e devolve false se falhar; $proximo($i): caminho da próxima requisição.
 */
function roda_degrau(string $base, int $vus, int $seg, callable $proximo, ?callable $pos_login, array $emails, float $pensa = 0): array
{
    $mh = curl_multi_init();
    $lat = []; $cod = []; $erros = []; $rotas = [];
    $estado = []; // por handle (id): ['i'=>, 'rota'=>, 't'=>, 'fase'=>]
    $fim = microtime(true) + $seg;
    $t0 = microtime(true);
    $agenda = []; // VUs esperando o "pensa"
    $lancar = function (int $i, CurlHandle $ch) use ($mh, $base, $proximo, &$estado) {
        $rota = $proximo($i);
        curl_setopt($ch, CURLOPT_URL, $base . $rota);
        curl_setopt($ch, CURLOPT_HTTPGET, true);
        $estado[spl_object_id($ch)] = ['i' => $i, 'rota' => $rota, 't' => microtime(true), 'ch' => $ch];
        curl_multi_add_handle($mh, $ch);
    };
    $handles = [];
    for ($i = 0; $i < $vus; $i++) {
        $ch = novo_handle();
        if ($pos_login && !$pos_login($i, $ch, $base, $emails)) { $erros['login'] = ($erros['login'] ?? 0) + 1; curl_close($ch); continue; }
        $handles[$i] = $ch;
        if ($pensa > 0) { $agenda[$i] = microtime(true) + mt_rand() / mt_getrandmax() * $pensa; } // humanos não chegam todos no mesmo instante
        else $lancar($i, $ch);
    }
    do {
        curl_multi_exec($mh, $ativos);
        while ($info = curl_multi_info_read($mh)) {
            $ch = $info['handle'];
            $e = $estado[spl_object_id($ch)] ?? null;
            curl_multi_remove_handle($mh, $ch);
            if (!$e) continue;
            $ms = (microtime(true) - $e['t']) * 1000;
            $rota = explode('?', $e['rota'])[0] . (strpos($e['rota'], 'action=') !== false ? '?' . explode('&', explode('action=', $e['rota'])[1])[0] : '');
            $r = &$rotas[$rota]; if (!isset($r)) $r = ['lat' => [], 'ruins' => 0, 'n' => 0]; $r['n']++;
            if ($info['result'] !== CURLE_OK) { $erros[curl_strerror($info['result'])] = ($erros[curl_strerror($info['result'])] ?? 0) + 1; $r['ruins']++; }
            else {
                $st = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $lat[] = $ms; $cod[] = $st; $r['lat'][] = $ms;
                $loc = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
                if ($st >= 500 || $st === 429 || $st === 403 || ($st === 302 && strpos($loc, 'login') !== false)) $r['ruins']++;
            }
            unset($r);
            if (microtime(true) < $fim) {
                if ($pensa > 0) { $agenda[$e['i']] = microtime(true) + $pensa * (0.5 + mt_rand() / mt_getrandmax()); }
                else $lancar($e['i'], $ch);
            }
        }
        foreach ($agenda as $i => $quando) { if (microtime(true) >= $quando) { unset($agenda[$i]); if (microtime(true) < $fim) $lancar($i, $handles[$i]); } }
        if ($ativos) curl_multi_select($mh, 0.05); elseif ($agenda) usleep(20000);
    } while ($ativos || $agenda);
    $dur = microtime(true) - $t0;
    $ruins = count(array_filter($cod, fn($c) => $c >= 500 || $c === 429 || $c === 403)) + array_sum($erros);
    $total = count($lat) + array_sum($erros);
    $codes = array_count_values($cod);
    foreach ($rotas as $k => $v) { $rotas[$k] = ['n' => $v['n'], 'p50' => (int) pct($v['lat'], 50), 'p95' => (int) pct($v['lat'], 95), 'ruins' => $v['ruins']]; }
    foreach ($handles as $ch) curl_close($ch);
    curl_multi_close($mh);
    return ['vus' => $vus, 'requisicoes' => $total, 'rps' => round($total / max($dur, .001), 1), 'p50' => (int) pct($lat, 50), 'p95' => (int) pct($lat, 95), 'p99' => (int) pct($lat, 99), 'max' => (int) (max($lat ?: [0])),
        'erros' => $ruins, 'pct_erro' => $total ? round($ruins / $total * 100, 1) : 0, 'status' => $codes, 'falhas' => $erros, 'rotas' => $rotas];
}

function linha(string $nome, array $r): string
{
    return str_pad($nome, 24) . str_pad((string) $r['requisicoes'], 7, ' ', STR_PAD_LEFT) . ' req ' . str_pad((string) $r['rps'], 7, ' ', STR_PAD_LEFT) . ' req/s  p50 ' . str_pad((string) $r['p50'], 5, ' ', STR_PAD_LEFT)
        . '  p95 ' . str_pad((string) $r['p95'], 5, ' ', STR_PAD_LEFT) . '  p99 ' . str_pad((string) $r['p99'], 5, ' ', STR_PAD_LEFT) . '  máx ' . str_pad((string) $r['max'], 6, ' ', STR_PAD_LEFT)
        . ' ms  erros ' . str_pad((string) $r['erros'], 4, ' ', STR_PAD_LEFT) . " ({$r['pct_erro']}%) " . json_encode($r['status']) . ($r['falhas'] ? ' falhas:' . json_encode($r['falhas']) : '');
}

// login serial/paralelo simples (cada VU faz o seu): GET /login -> csrf -> POST
$logar = function (int $i, CurlHandle $ch, string $base, array $emails): bool {
    $email = $emails[$i % count($emails)];
    curl_setopt($ch, CURLOPT_URL, $base . '/login'); curl_setopt($ch, CURLOPT_HTTPGET, true);
    $html = curl_exec($ch);
    if (!$html || !preg_match('/name="csrf_token" value="([^"]+)"/', $html, $m)) return false;
    curl_setopt($ch, CURLOPT_URL, $base . '/login');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['acao' => 'login', 'email' => $email, 'senha' => '12345678', 'csrf_token' => $m[1]]));
    curl_exec($ch);
    $ok = curl_getinfo($ch, CURLINFO_RESPONSE_CODE) === 302 && strpos((string) curl_getinfo($ch, CURLINFO_REDIRECT_URL), 'login') === false;
    curl_setopt($ch, CURLOPT_HTTPGET, true);
    return $ok;
};

$n = (int) $a['usuarios'];
$emails = [];
$off = (int) ($a['offset'] ?? 1);
for ($i = 0; $i < $n; $i++) $emails[] = "cliente" . ($i + $off) . "@gmial.com";
$resultados = [];
$fase = $a['fase'];
echo "# fase=$fase base=$BASE usuarios=$n degraus=" . implode(',', $degraus) . " duracao={$a['duracao']}s\n";

if ($fase === 'login') {
    // tempestade de logins: N logins em paralelo (cada VU loga 1 vez e sai)
    foreach ($degraus as $conc) {
        $t0 = microtime(true); $ok = 0; $falhas = 0; $lat = [];
        $lotes = array_chunk(range(0, min($n, 150) - 1), $conc);
        foreach ($lotes as $lote) {
            // paralelismo real com multi: cada login são 2 requisições; aproximação com handles em lote usando curl_multi manual
            $mh = curl_multi_init(); $hs = [];
            foreach ($lote as $i) { $ch = novo_handle(); curl_setopt_array($ch, [CURLOPT_URL => $BASE . '/login']); curl_multi_add_handle($mh, $ch); $hs[$i] = [$ch, microtime(true)]; }
            do { curl_multi_exec($mh, $at); if ($at) curl_multi_select($mh, 0.05); } while ($at);
            $post = [];
            foreach ($hs as $i => [$ch, $ti]) {
                $html = curl_multi_getcontent($ch); curl_multi_remove_handle($mh, $ch);
                if (!preg_match('/name="csrf_token" value="([^"]+)"/', (string) $html, $m)) { $falhas++; continue; }
                curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['acao' => 'login', 'email' => $emails[$i], 'senha' => '12345678', 'csrf_token' => $m[1]])]);
                curl_multi_add_handle($mh, $ch); $post[$i] = $ti;
            }
            do { curl_multi_exec($mh, $at); if ($at) curl_multi_select($mh, 0.05); } while ($at);
            foreach ($hs as $i => [$ch, $ti]) {
                if (!isset($post[$i])) { curl_close($ch); continue; }
                $st = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                if ($st === 302) { $ok++; $lat[] = (microtime(true) - $ti) * 1000; } else $falhas++;
                curl_multi_remove_handle($mh, $ch); curl_close($ch);
            }
            curl_multi_close($mh);
        }
        $dur = microtime(true) - $t0;
        $r = ['requisicoes' => $ok + $falhas, 'rps' => round(($ok + $falhas) / $dur, 1), 'p50' => (int) pct($lat, 50), 'p95' => (int) pct($lat, 95), 'p99' => (int) pct($lat, 99), 'max' => (int) max($lat ?: [0]), 'erros' => $falhas, 'pct_erro' => round($falhas / max(1, $ok + $falhas) * 100, 1), 'status' => ['302' => $ok], 'falhas' => []];
        echo linha("login x" . ($ok + $falhas) . " conc=$conc", $r), "\n";
        $resultados[] = ['conc' => $conc] + $r;
        if ($r['pct_erro'] > (float) $a['parar_erro'] || $r['p95'] > (float) $a['parar_p95']) { echo ">> parada\n"; break; }
        sleep(3);
    }
} elseif ($fase === 'mistura' || $fase === 'soak' || $fase === 'paginas') {
    $rotas_unicas = array_values(array_unique(array_column($MISTURA, 1)));
    if (!empty($a['rotas'])) $rotas_unicas = explode(',', (string) $a['rotas']);
    $lista = $fase === 'paginas' ? $rotas_unicas : $degraus;
    foreach ($lista as $item) {
        if ($fase === 'paginas') { $vus = $degraus[0]; $nome = $item; $prox = fn($i) => $item; $seg = min((int) $a['duracao'], 10); }
        else { $vus = (int) $item; $nome = "$fase $vus VUs"; $prox = fn($i) => sorteia($MISTURA); $seg = (int) $a['duracao']; }
        $r = roda_degrau($BASE, $vus, $seg, $prox, $logar, $emails, (float) $a['pensa']);
        echo linha($nome, $r), "\n";
        $resultados[] = ['nome' => $nome] + $r;
        if ($fase !== 'paginas' && ($r['pct_erro'] > (float) $a['parar_erro'] || $r['p95'] > (float) $a['parar_p95'])) { echo ">> parando a rampa: erro {$r['pct_erro']}% p95 {$r['p95']}ms\n"; break; }
        sleep(3);
    }
} elseif ($fase === 'publico') {
    foreach ($degraus as $vus) {
        $r = roda_degrau($BASE, $vus, (int) $a['duracao'], fn($i) => '/l/' . $a['slug'] . '?utm_campaign=estresse', null, [], 0);
        echo linha("/l/{$a['slug']} $vus VUs", $r), "\n";
        $resultados[] = ['vus' => $vus] + $r;
        if ($r['pct_erro'] > (float) $a['parar_erro'] || $r['p95'] > (float) $a['parar_p95']) { echo ">> parando\n"; break; }
        sleep(3);
    }
} elseif ($fase === 'admin') {
    $adm = [$a['email'] ?: 'clienteadm@gmial.com'];
    $rotas = ['/admin/dashboard', '/admin/usuarios', '/admin/transacoes', '/admin/transacoes?status=pago', '/admin/logs', '/admin/configuracoes', '/admin/comunidade', '/admin/ranking'];
    foreach ($rotas as $rota) {
        $r = roda_degrau($BASE, max(1, $degraus[0]), min((int) $a['duracao'], 10), fn($i) => $rota, $logar, $adm, 0);
        echo linha($rota, $r), "\n";
        $resultados[] = ['nome' => $rota] + $r;
        sleep(2);
    }
}
file_put_contents(__DIR__ . "/zz_carga_{$fase}.json", json_encode($resultados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
