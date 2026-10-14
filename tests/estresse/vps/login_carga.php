<?php
// Carga no LOGIN (que usa bcrypt, caro de propósito). Roda NA VPS contra a cópia de teste (127.0.0.1:8080).
//   php login_carga.php --tag=e1 --conc=8 --dur=20 [--certo=1|0] [--url=http://127.0.0.1:8080]
//   --certo=1 (padrão): senha certa → login completo (302 + sessão).  --certo=0: senha ERRADA de e-mails que existem (o servidor gasta o bcrypt à toa:
//   é o que um atacante faria) — o bloqueio por tentativas é por e-mail, então cada e-mail só leva algumas.
chdir('/var/www/painel');
require_once 'conexao.php';
$op = [];
foreach (array_slice($argv, 1) as $a) { if (preg_match('/^--([a-z-]+)=(.*)$/', $a, $m)) $op[$m[1]] = $m[2]; }
$URL = rtrim($op['url'] ?? 'http://127.0.0.1:8080', '/');
$tag = preg_replace('/[^a-z0-9]/i', '', $op['tag'] ?? 'e1');
$CONC = (int) ($op['conc'] ?? 4);
$DUR = (float) ($op['dur'] ?? 20);
$CERTO = ($op['certo'] ?? '1') !== '0';
$st = $pdo->prepare("SELECT email FROM usuarios WHERE email LIKE ? ORDER BY id LIMIT 5000");
$st->execute(["$tag-%@estresse.invalid"]);
$emails = $st->fetchAll(PDO::FETCH_COLUMN);
if (!$emails) { fwrite(STDERR, "sem usuários $tag-*\n"); exit(2); }
$mh = curl_multi_init();
$ativos = [];
$res = ['logins_ok' => 0, 'recusados' => 0, 'bloqueados' => 0, 'erros' => 0, 'lat' => [], 'lat_get' => []];
$idx = 0;
$t0 = microtime(true);
function novo(string $fase, array $dados): array
{
    global $URL, $jar_dir;
    $ch = curl_init();
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_HEADER => true];
    if (!empty($dados['cookie'])) $o[CURLOPT_COOKIE] = $dados['cookie'];
    if ($fase === 'get') { $o[CURLOPT_URL] = $URL . '/login'; }
    else { $o[CURLOPT_URL] = $URL . '/login'; $o[CURLOPT_POST] = true; $o[CURLOPT_POSTFIELDS] = http_build_query(['csrf_token' => $dados['token'], 'acao' => 'login', 'email' => $dados['email'], 'senha' => $dados['senha']]); }
    curl_setopt_array($ch, $o);
    return [$ch, $fase, $dados];
}
$n = 0;
$lanca = function () use (&$ativos, &$n, $emails, &$idx, $CERTO, $mh) {
    $email = $emails[$idx++ % count($emails)];
    $d = ['n' => ++$n, 'email' => $email, 'senha' => $CERTO ? 'Estresse#2026' : 'senha-errada-' . $n];
    [$ch, $f, $dd] = novo('get', $d);
    curl_multi_add_handle($mh, $ch);
    $ativos[(int) $ch] = [$ch, $f, $dd];
};
for ($i = 0; $i < $CONC; $i++) $lanca();
while ($ativos) {
    do { $s = curl_multi_exec($mh, $run); } while ($s === CURLM_CALL_MULTI_PERFORM);
    while ($info = curl_multi_info_read($mh)) {
        $ch = $info['handle'];
        [, $fase, $dd] = $ativos[(int) $ch];
        unset($ativos[(int) $ch]);
        $corpo = (string) curl_multi_getcontent($ch);
        $cod = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $lat = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        curl_multi_remove_handle($mh, $ch);
        if ($info['result'] !== CURLE_OK) { $res['erros']++; if (microtime(true) - $t0 < $DUR) $lanca(); continue; }
        if ($fase === 'get') {
            $res['lat_get'][] = $lat;
            if (!preg_match('/name="csrf_token" value="([^"]*)"/', $corpo, $m)) { $res['erros']++; if (microtime(true) - $t0 < $DUR) $lanca(); continue; }
            $dd['token'] = $m[1];
            if (!preg_match('/Set-Cookie:\s*(PHPSESSID=[^;\s]+)/i', $corpo, $mc)) { $res['erros']++; if (microtime(true) - $t0 < $DUR) $lanca(); continue; }
            $dd['cookie'] = $mc[1];
            [$c2, $f2, $d2] = novo('post', $dd);
            curl_multi_add_handle($mh, $c2);
            $ativos[(int) $c2] = [$c2, $f2, $d2];
        } else {
            $res['lat'][] = $lat;
            if ($cod === 302 && stripos($corpo, 'location:') !== false && !stripos($corpo, 'erro=')) $res['logins_ok']++;
            elseif (stripos($corpo, 'bloquead') !== false) $res['bloqueados']++;
            else $res['recusados']++;
            if (microtime(true) - $t0 < $DUR) $lanca();
        }
    }
    if ($ativos) curl_multi_select($mh, 0.05);
}
$tt = microtime(true) - $t0;
function pct(array $v, float $p): float { if (!$v) return 0; sort($v); return round($v[min(count($v) - 1, (int) floor(count($v) * $p))] * 1000, 1); }
$tot = $res['logins_ok'] + $res['recusados'] + $res['bloqueados'];
echo json_encode(['modo' => $CERTO ? 'senha certa' : 'senha ERRADA (e-mails existentes)', 'conc' => $CONC, 'duracao_s' => round($tt, 1), 'tentativas' => $tot, 'tentativas_por_s' => round($tot / $tt, 2),
    'logins_ok' => $res['logins_ok'], 'recusados' => $res['recusados'], 'bloqueados' => $res['bloqueados'], 'erros' => $res['erros'],
    'post_login_ms' => ['p50' => pct($res['lat'], .5), 'p95' => pct($res['lat'], .95), 'p99' => pct($res['lat'], .99)], 'get_login_ms' => ['p50' => pct($res['lat_get'], .5), 'p95' => pct($res['lat_get'], .95)]], JSON_UNESCAPED_UNICODE), "\n";
