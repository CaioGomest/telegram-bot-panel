<?php
// Gerador de carga do FUNIL DE VENDAS (cliente do Telegram → /start → ver planos → escolhe plano (gera o Pix) → paga → recebe o acesso).
// Roda NA VPS, pela linha de comando, e fala com a cópia de teste (127.0.0.1:8080, Telegram/gateway simulados com latência).
//
//   php funil.php --tag=e1 --conc=100 --dur=60                   laço fechado: mantém 100 clientes ao mesmo tempo, durante 60 s
//   php funil.php --tag=e1 --taxa=40 --dur=120                   laço aberto: chegam 40 clientes novos por segundo, aconteça o que acontecer
//   opções: --conversao=0.25 (quem paga) --pensa=0 (ms entre os passos) --pago-apos=500 (ms entre o Pix e o aviso de pagamento)
//           --dup=0.1 (fração dos pagamentos avisada 2x ao mesmo tempo: o webhook da gateway repete) --dist=pareto|uniforme
//           --max-voando=4000 --saida=/root/res/x.json --url=http://127.0.0.1:8080 --timeout=30 --so-start=1
//
// Ao final confere NO BANCO se cada cliente virou lead, cada plano escolhido virou venda e cada pagamento virou venda paga + acesso
// (nada perdido, nada duplicado) e grava o resumo em JSON.
chdir('/var/www/painel');
require_once 'conexao.php';
set_time_limit(0);
ini_set('memory_limit', '1024M');

$op = [];
foreach (array_slice($argv, 1) as $a) { if (preg_match('/^--([a-z-]+)=(.*)$/', $a, $m)) $op[$m[1]] = $m[2]; }
$URL = rtrim($op['url'] ?? 'http://127.0.0.1:8080', '/');
$tag = preg_replace('/[^a-z0-9]/i', '', $op['tag'] ?? '');
$CONC = (int) ($op['conc'] ?? 0);
$TAXA = (float) ($op['taxa'] ?? 0);
$DUR = (float) ($op['dur'] ?? 30);
$CONV = (float) ($op['conversao'] ?? 0.25);
$PENSA = (int) ($op['pensa'] ?? 0) / 1000;
$PAGO_APOS = (int) ($op['pago-apos'] ?? 500) / 1000;
$DUP = (float) ($op['dup'] ?? 0);
$DIST = $op['dist'] ?? 'pareto';
$MAXV = (int) ($op['max-voando'] ?? 4000);
$TIMEOUT = (int) ($op['timeout'] ?? 30);
$SO_START = !empty($op['so-start']);
$SAIDA = $op['saida'] ?? '';
if ($CONC <= 0 && $TAXA <= 0) { fwrite(STDERR, "informe --conc=N ou --taxa=R\n"); exit(2); }

$like = $tag !== '' ? "SIMBOT:$tag-%" : 'SIMBOT:%';
$st = $pdo->prepare("SELECT b.id, b.token, b.webhook_secret FROM bots b WHERE b.token LIKE ? AND b.id_fluxo_conectado IS NOT NULL ORDER BY b.id");
$st->execute([$like]);
$BOTS = $st->fetchAll(PDO::FETCH_ASSOC);
if (!$BOTS) { fwrite(STDERR, "nenhum bot de teste ($like): rode semear.php antes\n"); exit(2); }
$NB = count($BOTS);
$max_lead = (int) $pdo->query("SELECT COALESCE(MAX(id),0) FROM leads")->fetchColumn();
$max_venda = (int) $pdo->query("SELECT COALESCE(MAX(id),0) FROM vendas")->fetchColumn();
$max_membro = (int) $pdo->query("SELECT COALESCE(MAX(id),0) FROM membros_grupos")->fetchColumn();
$stx = $pdo->prepare("SELECT transacao_id FROM vendas WHERE bot_id = ? AND id_telegram = ? ORDER BY id DESC LIMIT 1");

$BASE = 20000000000 + (time() - 1700000000) * 1000000;   // ids de chat novos a cada execução (cada cliente é um lead novo)
$seq = 0;

function escolhe_bot(): array
{
    global $BOTS, $NB, $DIST;
    if ($DIST === 'uniforme') return $GLOBALS['BOTS'][random_int(0, $NB - 1)];
    // pareto: ~20% dos bots recebem ~80% do tráfego
    $u = mt_rand() / mt_getrandmax();
    $i = (int) floor($NB * pow($u, 3));
    return $BOTS[min($NB - 1, $i)];
}
function upd_msg(int $chat, string $texto): string
{
    return json_encode(['update_id' => random_int(1, 2000000000), 'message' => ['message_id' => random_int(100, 900000), 'from' => ['id' => $chat, 'is_bot' => false, 'first_name' => 'Cli', 'last_name' => 'Teste', 'username' => 'c' . $chat],
        'chat' => ['id' => $chat, 'type' => 'private'], 'date' => time(), 'text' => $texto]]);
}
function upd_cb(int $chat, string $data): string
{
    return json_encode(['update_id' => random_int(1, 2000000000), 'callback_query' => ['id' => 'cbq' . random_int(1, 999999999), 'from' => ['id' => $chat, 'is_bot' => false, 'first_name' => 'Cli'],
        'message' => ['message_id' => 555, 'chat' => ['id' => $chat, 'type' => 'private']], 'data' => $data, 'chat_instance' => 'x']]);
}

$mh = curl_multi_init();
$clientes = [];           // id => estado
$proximos = [];           // id => instante em que o próximo passo deve sair
$ativos = [];             // (int) handle => [cliente, passo]
$M = ['clientes' => 0, 'terminados' => 0, 'req' => [], 'lat' => [], 'http' => [], 'erros' => 0, 'timeouts' => 0, 'tl' => []];
foreach (['start', 'planos', 'plano', 'pago', 'pago_dup'] as $p) { $M['req'][$p] = 0; $M['lat'][$p] = []; }
$esperado = ['leads' => 0, 'pix' => 0, 'pagos' => 0, 'dups' => 0];
$DUPS = [];
$IV = [];   // latências (s) das requisições concluídas no intervalo de 5 s atual

function termina(int $cid, array &$M, array &$clientes): void
{
    $M['terminados']++;
    unset($clientes[$cid]);
}

function dispara(int $cid, string $passo): void
{
    global $mh, $clientes, $ativos, $URL, $TIMEOUT, $BASE;
    $c = &$clientes[$cid];
    $ch = curl_init();
    $hdr = ['Content-Type: application/json', 'Expect:'];
    if ($passo === 'pago' || $passo === 'pago_dup') {
        $url = $URL . '/webhook_omegapayments.php';
        $corpo = json_encode(['event' => 'TRANSACTION_PAID', 'transaction' => ['id' => $c['tx'], 'status' => 'PAID']]);
    } else {
        $url = $URL . '/webhook.php?token=' . urlencode($c['bot']['token']);
        if (!empty($c['bot']['webhook_secret'])) $hdr[] = 'X-Telegram-Bot-Api-Secret-Token: ' . $c['bot']['webhook_secret'];
        $corpo = $passo === 'start' ? upd_msg($c['chat'], '/start') : upd_cb($c['chat'], $passo === 'planos' ? 'basico::ver_planos' : 'basico::plano::p_mensal');
    }
    curl_setopt_array($ch, [CURLOPT_URL => $url, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $corpo, CURLOPT_HTTPHEADER => $hdr, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $TIMEOUT, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TCP_NODELAY => true]);
    curl_multi_add_handle($mh, $ch);
    $ativos[(int) $ch] = [$cid, $passo, $ch];
}

$t0 = microtime(true);
$fim_chegadas = $t0 + $DUR;
$prox_tl = $t0 + 5;
$tl_ant = ['req' => 0, 'term' => 0, 'err' => 0];
$prox_chegada = $t0;
$voando = 0;
$ult_progresso = $t0;
fprintf(STDERR, "funil: %d bots (%s), %s, %ds, conversão %.0f%%, dist %s\n", $NB, $like, $CONC > 0 ? "laço fechado conc=$CONC" : "laço aberto taxa=$TAXA/s", $DUR, $CONV * 100, $DIST);

while (true) {
    $agora = microtime(true);
    // chegada de clientes
    if ($agora < $fim_chegadas) {
        $voando = $M['clientes'] - $M['terminados'];
        if ($CONC > 0) {
            while ($voando < $CONC) { $voando++; $cid = ++$seq; $clientes[$cid] = ['chat' => $BASE + $cid, 'bot' => escolhe_bot(), 'conv' => (mt_rand() / mt_getrandmax()) < $CONV, 'tx' => '', 'i0' => $agora]; $M['clientes']++; $proximos[$cid] = [$agora, 'start']; }
        } else {
            while ($prox_chegada <= $agora && $voando < $MAXV) {
                $cid = ++$seq; $voando++; $prox_chegada += 1 / $TAXA;
                $clientes[$cid] = ['chat' => $BASE + $cid, 'bot' => escolhe_bot(), 'conv' => (mt_rand() / mt_getrandmax()) < $CONV, 'tx' => '', 'i0' => $agora]; $M['clientes']++; $proximos[$cid] = [$agora, 'start'];
            }
            if ($prox_chegada < $agora - 1) $prox_chegada = $agora;   // não acumula atraso se o gerador ficou sem fôlego
        }
    }
    // passos agendados que já podem sair
    foreach ($proximos as $cid => [$quando, $passo]) {
        if ($quando <= $agora) { unset($proximos[$cid]); dispara($cid, $passo); $clientes[$cid]['t_passo'] = $agora; }
    }
    do { $s = curl_multi_exec($mh, $executando); } while ($s === CURLM_CALL_MULTI_PERFORM);
    while ($info = curl_multi_info_read($mh)) {
        $ch = $info['handle'];
        [$cid, $passo] = [$ativos[(int) $ch][0], $ativos[(int) $ch][1]];
        unset($ativos[(int) $ch]);
        $cod = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $lat = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        $erro_curl = $info['result'] !== CURLE_OK;
        $M['req'][$passo]++;
        $M['lat'][$passo][] = $lat;
        $IV[] = $lat;
        $M['http'][$erro_curl ? 'curl_' . $info['result'] : (string) $cod] = ($M['http'][$erro_curl ? 'curl_' . $info['result'] : (string) $cod] ?? 0) + 1;
        if ($erro_curl) { $M['erros']++; if ($info['result'] === CURLE_OPERATION_TIMEDOUT) $M['timeouts']++; }
        elseif ($cod !== 200) $M['erros']++;
        curl_multi_remove_handle($mh, $ch);
        $ok = !$erro_curl && $cod === 200;
        $c = &$clientes[$cid];
        if ($passo === 'pago_dup') { unset($c); unset($clientes[$cid], $DUPS[$cid]); continue; }
        if (!$ok) { unset($c); termina($cid, $M, $clientes); continue; }       // cliente desiste no erro
        switch ($passo) {
            case 'start':
                $esperado['leads']++;
                if ($SO_START) { unset($c); termina($cid, $M, $clientes); } else { $proximos[$cid] = [$agora + $PENSA, 'planos']; }
                break;
            case 'planos': $proximos[$cid] = [$agora + $PENSA, 'plano']; break;
            case 'plano':
                $esperado['pix']++;
                if ($c['conv']) {
                    $stx->execute([$c['bot']['id'], (string) $c['chat']]);
                    $c['tx'] = (string) $stx->fetchColumn();
                    if ($c['tx'] === '') { unset($c); termina($cid, $M, $clientes); break; }
                    @touch('/dev/shm/est/pagos/' . preg_replace('/\W/', '', $c['tx']));   // a gateway (simulada) passa a responder "paga" quando o webhook for conferi-la
                    $proximos[$cid] = [$agora + $PAGO_APOS, 'pago'];
                } else { unset($c); termina($cid, $M, $clientes); }
                break;
            case 'pago':
                $esperado['pagos']++;
                if ($DUP > 0 && (mt_rand() / mt_getrandmax()) < $DUP) { $esperado['dups']++; dispara($cid, 'pago_dup'); $DUPS[$cid] = true; unset($c); $M['terminados']++; break; }
                unset($c);
                termina($cid, $M, $clientes);
                break;
        }
        unset($c);
    }
    $agora = microtime(true);
    if ($agora >= $prox_tl) {
        $req = array_sum($M['req']);
        sort($IV);
        $p = fn(float $q) => $IV ? round($IV[min(count($IV) - 1, (int) floor(count($IV) * $q))] * 1000) : 0;
        $M['tl'][] = ['t' => round($agora - $t0), 'req_s' => round(($req - $tl_ant['req']) / 5, 1), 'clientes_fim_s' => round(($M['terminados'] - $tl_ant['term']) / 5, 1), 'erros' => $M['erros'] - $tl_ant['err'], 'voando' => $M['clientes'] - $M['terminados'], 'p50_ms' => $p(.5), 'p95_ms' => $p(.95)];
        $IV = [];
        $u = end($M['tl']);
        fprintf(STDERR, "  t=%3ds  %6.1f req/s  %6.1f funis/s  voando=%4d  erros(5s)=%d  p50=%dms p95=%dms\n", $agora - $t0, ($req - $tl_ant['req']) / 5, ($M['terminados'] - $tl_ant['term']) / 5, $M['clientes'] - $M['terminados'], $M['erros'] - $tl_ant['err'], $u['p50_ms'], $u['p95_ms']);
        $tl_ant = ['req' => $req, 'term' => $M['terminados'], 'err' => $M['erros']];
        $prox_tl += 5;
    }
    $tudo = ($agora >= $fim_chegadas) && !$proximos && !$ativos;
    if ($tudo) break;
    if ($agora > $fim_chegadas + $TIMEOUT + 60) { fprintf(STDERR, "abortado: clientes pendentes demais após o fim\n"); break; }
    if ($ativos || $proximos) {
        $espera = $proximos ? max(0.0005, min(0.01, min(array_map(fn($p) => $p[0], $proximos)) - microtime(true))) : 0.01;
        if ($ativos) curl_multi_select($mh, $espera); else usleep((int) ($espera * 1e6));
    } else usleep(1000);
}
$total_s = microtime(true) - $t0;

function pct(array $v, float $p): float { if (!$v) return 0; sort($v); return round($v[min(count($v) - 1, (int) floor(count($v) * $p))] * 1000, 1); }
$lat = [];
foreach ($M['lat'] as $p => $v) $lat[$p] = ['n' => count($v), 'p50_ms' => pct($v, .5), 'p95_ms' => pct($v, .95), 'p99_ms' => pct($v, .99), 'max_ms' => $v ? round(max($v) * 1000, 1) : 0];

sleep(2);
$real = [
    'leads' => (int) $pdo->query("SELECT COUNT(*) FROM leads WHERE id > $max_lead")->fetchColumn(),
    'vendas_geradas_total' => (int) $pdo->query("SELECT COUNT(*) FROM vendas WHERE id > $max_venda")->fetchColumn(),
    'vendas_pagas' => (int) $pdo->query("SELECT COUNT(*) FROM vendas WHERE id > $max_venda AND status = 'pago'")->fetchColumn(),
    'vendas_ainda_gerado' => (int) $pdo->query("SELECT COUNT(*) FROM vendas WHERE id > $max_venda AND status = 'gerado'")->fetchColumn(),
    'acessos_criados' => (int) $pdo->query("SELECT COUNT(*) FROM membros_grupos WHERE id > $max_membro")->fetchColumn(),
    'tx_duplicadas' => (int) $pdo->query("SELECT COUNT(*) FROM (SELECT transacao_id FROM vendas WHERE id > $max_venda GROUP BY transacao_id HAVING COUNT(*) > 1) d")->fetchColumn(),
];
$res = [
    'config' => ['tag' => $tag, 'bots' => $NB, 'conc' => $CONC, 'taxa' => $TAXA, 'dur' => $DUR, 'conversao' => $CONV, 'pensa_ms' => $PENSA * 1000, 'pago_apos_ms' => $PAGO_APOS * 1000, 'dup' => $DUP, 'dist' => $DIST, 'lat_sim' => json_decode((string) @file_get_contents('/dev/shm/est/lat.json'), true)],
    'duracao_total_s' => round($total_s, 1),
    'clientes' => $M['clientes'], 'clientes_terminados' => $M['terminados'],
    'requisicoes' => $M['req'], 'req_total' => array_sum($M['req']), 'req_por_s' => round(array_sum($M['req']) / $total_s, 1),
    'funis_por_s' => round($M['terminados'] / $total_s, 2),
    'http' => $M['http'], 'erros' => $M['erros'], 'timeouts' => $M['timeouts'],
    'latencia' => $lat,
    'esperado' => $esperado, 'real_no_banco' => $real,
    'diferenca' => ['leads' => $real['leads'] - $esperado['leads'], 'pix' => $real['vendas_geradas_total'] - $esperado['pix'], 'pagos' => $real['vendas_pagas'] - $esperado['pagos']],
    'linha_do_tempo' => $M['tl'],
];
echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
if ($SAIDA) { @mkdir(dirname($SAIDA), 0755, true); file_put_contents($SAIDA, json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); }
