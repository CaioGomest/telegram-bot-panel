<?php
// Integração da Proteção + Segmentação do Redirecionamento (banco de verdade, l.php de verdade).
// Roda dentro da cópia do painel montada por rodar.js (config.php apontando para o MariaDB temporário).
chdir(__DIR__ . '/..');
require_once 'conexao.php';
require_once 'funcoes/redirecionadores.php';

$ok = 0; $falhas = [];
function confere(string $nome, bool $cond, string $detalhe = ''): void
{
    global $ok, $falhas;
    if ($cond) { $ok++; echo "  ok   $nome\n"; return; }
    $falhas[] = $nome; echo "  FALHOU $nome" . ($detalhe !== '' ? " -> $detalhe" : '') . "\n";
}

function req(array $q): array
{
    $q += ['metodo' => 'GET', 'ua' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
        'idioma' => 'pt-BR,pt;q=0.9', 'query' => [], 'ip' => '200.1.1.1', 'corpo' => ''];
    // php-cgi roda o l.php como o servidor roda: cabeçalhos, status e corpo do POST de verdade.
    $env = array_merge(getenv(), [
        'REDIRECT_STATUS' => '1', 'GATEWAY_INTERFACE' => 'CGI/1.1', 'SERVER_PROTOCOL' => 'HTTP/1.1',
        'SCRIPT_FILENAME' => dirname(__DIR__) . '/l.php', 'SCRIPT_NAME' => '/l.php',
        'REQUEST_METHOD' => $q['metodo'], 'QUERY_STRING' => http_build_query($q['query'] + ['s' => $q['slug']]),
        'REMOTE_ADDR' => $q['ip'], 'HTTP_USER_AGENT' => $q['ua'], 'HTTP_HOST' => 'painel.teste',
        'CONTENT_TYPE' => 'application/json', 'CONTENT_LENGTH' => (string) strlen($q['corpo']),
    ]);
    if ($q['idioma'] !== '') { $env['HTTP_ACCEPT_LANGUAGE'] = $q['idioma']; }
    $cgi = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'php-cgi.exe';
    $proc = proc_open('"' . $cgi . '" -d display_errors=1', [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    fwrite($pipes[0], $q['corpo']); fclose($pipes[0]);
    $saida = stream_get_contents($pipes[1]); stream_get_contents($pipes[2]);
    proc_close($proc);
    [$cab, $corpo] = array_pad(preg_split('/\r?\n\r?\n/', $saida, 2), 2, '');
    $r = ['status' => 200, 'headers' => [], 'body' => $corpo, 'location' => ''];
    foreach (preg_split('/\r?\n/', $cab) as $h) {
        if (stripos($h, 'Status:') === 0) { $r['status'] = (int) trim(substr($h, 7)); }
        elseif ($h !== '') { $r['headers'][] = $h; }
        if (stripos($h, 'Location:') === 0) { $r['location'] = trim(substr($h, 9)); }
    }
    return $r;
}

function contadores(string $slug): array
{
    global $pdo;
    $s = $pdo->prepare("SELECT cliques, bloqueados FROM redirecionadores WHERE slug = ?");
    $s->execute([$slug]);
    return $s->fetch();
}

function criar(int $uid, array $d): array
{
    $res = salvarRedirecionador($uid, $d + ['titulo' => 'T', 'ativo' => 1, 'plataforma' => 'meta', 'protecao' => 'nenhuma',
        'modo' => 'aleatorio', 'formato' => 'personalizado', 'bots' => $GLOBALS['bots']]);
    if (empty($res['sucesso'])) { return $res; }
    return $res + ['slug' => $d['slug']];
}

// ---------- preparo ----------
$pdo->exec("INSERT INTO usuarios (nome, email, senha) VALUES ('Teste', 'rd@teste.local', 'x')");
$uid = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO bots (id_usuario, token, nome_usuario) VALUES (?, 'tok:rd', '@bot_teste')")->execute([$uid]);
$bots = [(int) $pdo->lastInsertId()];
$pdo->exec("REPLACE INTO geo_ip_cache (ip, pais) VALUES ('200.1.1.1', 'BR'), ('8.8.8.8', 'US'), ('2.2.2.2', 'PT')");
echo "== preparo ok (usuario $uid, bot {$bots[0]})\n";

$browser_en = 'en-US,en;q=0.9';

echo "== 1. sem proteção\n";
confere('cria link', criar($uid, ['slug' => 'livre'])['sucesso'] ?? false);
$r = req(['slug' => 'livre']);
confere('navegador: 302 para o bot', $r['status'] === 302 && str_contains($r['location'], 't.me/bot_teste?start=rd_livre'), json_encode($r));
$r = req(['slug' => 'livre', 'ua' => 'facebookexternalhit/1.1']);
confere('robô sem proteção: segue (como antes)', $r['status'] === 302);
confere('cliques contados (2) e 0 bloqueados', contadores('livre') === ['cliques' => 2, 'bloqueados' => 0]);
$r = req(['slug' => 'livre', 'metodo' => 'HEAD']);
confere('HEAD não conta', contadores('livre')['cliques'] === 2);

echo "== 2. Cloaker: bots\n";
confere('cria', criar($uid, ['slug' => 'cloak', 'protecao' => 'cloaker', 'bloquear_bots' => 1])['sucesso'] ?? false);
$r = req(['slug' => 'cloak']);
confere('navegador passa', $r['status'] === 302);
foreach (['facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)', 'Mozilla/5.0 (compatible; AdsBot-Google; +http://www.google.com/adsbot.html)', 'curl/8.4.0', 'python-requests/2.31', 'Bytespider', 'HeadlessChrome/120'] as $ua) {
    $r = req(['slug' => 'cloak', 'ua' => $ua]);
    confere("robô bloqueado: " . substr($ua, 0, 24), $r['status'] === 200 && str_contains($r['body'], 'Estamos preparando novidades'), $r['status'] . ' ' . substr($r['body'], 0, 60));
}
$r = req(['slug' => 'cloak', 'idioma' => '']);
confere('sem Accept-Language: bloqueado', $r['status'] === 200 && str_contains($r['body'], 'novidades'));
$c = contadores('cloak');
confere('1 clique contado e 7 bloqueados', $c === ['cliques' => 1, 'bloqueados' => 7], json_encode($c));
$r = req(['slug' => 'cloak', 'ua' => 'curl/8']);
confere('página segura sem marca do painel', !preg_match('/painel|\bbot\b|telegram|coyote/i', $r['body']));
confere('página segura com noindex', in_array('X-Robots-Tag: noindex, nofollow', $r['headers'], true));

echo "== 3. bloquear_bots desligado\n";
criar($uid, ['slug' => 'semb', 'protecao' => 'cloaker', 'bloquear_bots' => 0]);
confere('robô passa quando o filtro está desligado', req(['slug' => 'semb', 'ua' => 'curl/8'])['status'] === 302);

echo "== 4. resposta: redirecionar\n";
confere('URL inválida recusada', !(criar($uid, ['slug' => 'rjsa', 'protecao' => 'cloaker', 'bloqueio_tipo' => 'redirecionar', 'bloqueio_url' => 'javascript:alert(1)'])['sucesso'] ?? true));
confere('URL vazia recusada', !(criar($uid, ['slug' => 'rjsb', 'protecao' => 'cloaker', 'bloqueio_tipo' => 'redirecionar', 'bloqueio_url' => ''])['sucesso'] ?? true));
confere('URL com usuário recusada', !(criar($uid, ['slug' => 'rjsc', 'protecao' => 'cloaker', 'bloqueio_tipo' => 'redirecionar', 'bloqueio_url' => 'https://a:b@x.com'])['sucesso'] ?? true));
confere('cria com URL boa', criar($uid, ['slug' => 'redir', 'protecao' => 'cloaker', 'bloqueio_tipo' => 'redirecionar', 'bloqueio_url' => 'https://www.exemplo.com.br/blog'])['sucesso'] ?? false);
$r = req(['slug' => 'redir', 'ua' => 'curl/8']);
confere('robô vai para a URL de bloqueio', $r['status'] === 302 && $r['location'] === 'https://www.exemplo.com.br/blog', $r['location']);
confere('navegador vai para o bot', str_contains(req(['slug' => 'redir'])['location'], 't.me/bot_teste'));

echo "== 5. Anti-compartilhamento\n";
criar($uid, ['slug' => 'anti', 'protecao' => 'cloaker', 'anti_compartilhamento' => 1]);
confere('sem click id: bloqueado', req(['slug' => 'anti'])['status'] === 200);
confere('com fbclid: passa', req(['slug' => 'anti', 'query' => ['fbclid' => 'IwAR123']])['status'] === 302);
confere('com utm_source=facebook: passa', req(['slug' => 'anti', 'query' => ['utm_source' => 'facebook', 'utm_campaign' => 'x']])['status'] === 302);
confere('utm_source=google num link Meta: bloqueado', req(['slug' => 'anti', 'query' => ['utm_source' => 'google']])['status'] === 200);
confere('fbclid vazio: bloqueado', req(['slug' => 'anti', 'query' => ['fbclid' => '']])['status'] === 200);
criar($uid, ['slug' => 'anti-tt', 'protecao' => 'cloaker', 'anti_compartilhamento' => 1, 'plataforma' => 'tiktok']);
confere('TikTok: ttclid passa', req(['slug' => 'anti-tt', 'query' => ['ttclid' => 'abc']])['status'] === 302);
confere('TikTok: fbclid bloqueado', req(['slug' => 'anti-tt', 'query' => ['fbclid' => 'abc']])['status'] === 200);
criar($uid, ['slug' => 'anti-g', 'protecao' => 'cloaker', 'anti_compartilhamento' => 1, 'plataforma' => 'google']);
confere('Google: gclid passa', req(['slug' => 'anti-g', 'query' => ['gclid' => 'abc']])['status'] === 302);
criar($uid, ['slug' => 'anti-o', 'protecao' => 'cloaker', 'anti_compartilhamento' => 1, 'plataforma' => 'outra']);
$o = obterRedirecionador($uid, (int) $pdo->query("SELECT id FROM redirecionadores WHERE slug='anti-o'")->fetchColumn());
confere('"Outra origem": anti-compartilhamento fica desligado ao salvar', $o['anti_compartilhamento'] === 0);
confere('"Outra origem": link abre normal', req(['slug' => 'anti-o'])['status'] === 302);

echo "== 6. Segmentação por país\n";
confere('país desconhecido recusado', !(criar($uid, ['slug' => 'pzsa', 'filtrar_pais' => 1, 'paises' => ['BR', 'ZZ']])['sucesso'] ?? true));
confere('filtro sem país recusado', !(criar($uid, ['slug' => 'pzsb', 'filtrar_pais' => 1, 'paises' => []])['sucesso'] ?? true));
confere('cria só Brasil', criar($uid, ['slug' => 'brasil', 'filtrar_pais' => 1, 'paises' => ['br']])['sucesso'] ?? false);
confere('IP do Brasil passa', req(['slug' => 'brasil', 'ip' => '200.1.1.1'])['status'] === 302);
$r = req(['slug' => 'brasil', 'ip' => '8.8.8.8']);
confere('IP dos EUA bloqueado (página segura)', $r['status'] === 200 && str_contains($r['body'], 'novidades'));
confere('IP sem país conhecido passa (falha aberta)', req(['slug' => 'brasil', 'ip' => '10.0.0.5'])['status'] === 302);
confere('bloqueio por país conta em bloqueados, não em cliques', contadores('brasil') === ['cliques' => 2, 'bloqueados' => 1], json_encode(contadores('brasil')));
confere('cria Brasil + Portugal', criar($uid, ['slug' => 'brpt', 'filtrar_pais' => 1, 'paises' => ['BR', 'PT']])['sucesso'] ?? false);
confere('Portugal passa', req(['slug' => 'brpt', 'ip' => '2.2.2.2'])['status'] === 302);
confere('EUA bloqueado', req(['slug' => 'brpt', 'ip' => '8.8.8.8'])['status'] === 200);
criar($uid, ['slug' => 'brred', 'filtrar_pais' => 1, 'paises' => ['BR'], 'bloqueio_tipo' => 'redirecionar', 'bloqueio_url' => 'https://exemplo.com']);
confere('país bloqueado + redirecionar (sem Cloaker)', req(['slug' => 'brred', 'ip' => '8.8.8.8'])['location'] === 'https://exemplo.com');

echo "== 6b. País consultado no serviço (cache)\n";
$hits = fn() => json_decode((string) file_get_contents('http://127.0.0.1:8098/_hits'), true) ?: [];
confere('IP dos EUA fora do cache: consulta o serviço e bloqueia', req(['slug' => 'brasil', 'ip' => '9.9.9.9'])['status'] === 200 && ($hits()['9.9.9.9'] ?? 0) === 1);
req(['slug' => 'brasil', 'ip' => '9.9.9.9']);
confere('segunda visita do mesmo IP vem do cache (1 consulta só)', ($hits()['9.9.9.9'] ?? 0) === 1);
confere('país gravado no cache', $pdo->query("SELECT pais FROM geo_ip_cache WHERE ip='9.9.9.9'")->fetchColumn() === 'US');
confere('IP do Brasil novo: consulta, passa e grava BR', req(['slug' => 'brasil', 'ip' => '3.3.3.3'])['status'] === 302 && $pdo->query("SELECT pais FROM geo_ip_cache WHERE ip='3.3.3.3'")->fetchColumn() === 'BR');
confere('serviço fora do ar (HTTP 500): passa e não grava', req(['slug' => 'brasil', 'ip' => '7.7.7.7'])['status'] === 302 && !$pdo->query("SELECT 1 FROM geo_ip_cache WHERE ip='7.7.7.7'")->fetchColumn());
$t0 = microtime(true);
$rl = req(['slug' => 'brasil', 'ip' => '6.6.6.6']);
confere('serviço lento: estoura em ~2 s, passa e não grava', $rl['status'] === 302 && (microtime(true) - $t0) < 3.5 && !$pdo->query("SELECT 1 FROM geo_ip_cache WHERE ip='6.6.6.6'")->fetchColumn(), round(microtime(true) - $t0, 1) . ' s');
$pdo->exec("UPDATE geo_ip_cache SET consultado_em = NOW() - INTERVAL 40 DAY WHERE ip='9.9.9.9'");
req(['slug' => 'brasil', 'ip' => '9.9.9.9']);
confere('cache vencido (40 dias) consulta de novo', ($hits()['9.9.9.9'] ?? 0) === 2);

echo "== 7. Idioma\n";
criar($uid, ['slug' => 'idi', 'filtrar_pais' => 1, 'paises' => ['BR'], 'validar_idioma' => 1]);
confere('pt-BR de IP brasileiro passa', req(['slug' => 'idi'])['status'] === 302);
confere('en-US de IP brasileiro bloqueado', req(['slug' => 'idi', 'idioma' => $browser_en])['status'] === 200);
confere('en-US,pt;q=0.5 passa (um idioma bate)', req(['slug' => 'idi', 'idioma' => 'en-US,en;q=0.9,pt;q=0.5'])['status'] === 302);
confere('sem idioma bloqueado', req(['slug' => 'idi', 'idioma' => ''])['status'] === 200);
criar($uid, ['slug' => 'idi-off', 'filtrar_pais' => 1, 'paises' => ['BR'], 'validar_idioma' => 0]);
confere('idioma desligado: en-US de IP brasileiro passa', req(['slug' => 'idi-off', 'idioma' => $browser_en])['status'] === 302);
criar($uid, ['slug' => 'idi-sem-pais', 'filtrar_pais' => 0, 'paises' => ['BR'], 'validar_idioma' => 1]);
$o = obterRedirecionador($uid, (int) $pdo->query("SELECT id FROM redirecionadores WHERE slug='idi-sem-pais'")->fetchColumn());
confere('idioma sem filtro de país não vale', $o['validar_idioma'] === 0 && req(['slug' => 'idi-sem-pais', 'idioma' => $browser_en])['status'] === 302);

echo "== 8. Cloaker V2 (desafio)\n";
confere('cria', criar($uid, ['slug' => 'vdois', 'protecao' => 'cloaker_v2'])['sucesso'] ?? false);
$g = req(['slug' => 'vdois']);
confere('GET devolve a página do desafio (200, sem Location)', $g['status'] === 200 && $g['location'] === '' && str_contains($g['body'], 'data-p='), (string) $g['status']);
confere('desafio não conta clique', contadores('vdois')['cliques'] === 0);
preg_match('/data-p="([^"]+)"/', $g['body'], $m);
$passe = html_entity_decode($m[1] ?? '');
$bom = ['webdriver' => false, 'tela_l' => 1920, 'tela_a' => 1080, 'idiomas' => 2, 'chrome_obj' => true, 'gpu' => 'ANGLE (NVIDIA)', 'canvas' => true];
$post = fn(string $p, array $s, array $extra = []) => req($extra + ['slug' => 'vdois', 'metodo' => 'POST', 'corpo' => json_encode(['p' => $p, 's' => $s])]);
$r = $post($passe, $bom);
$j = json_decode(preg_replace('/^[\s\S]*?(\{.*)$/s', '$1', $r['body']), true);
confere('POST com passe e sinais bons devolve a URL do bot', is_array($j) && str_contains($j['u'] ?? '', 't.me/bot_teste?start=rd_vdois'), $r['body']);
confere('...e conta 1 clique', contadores('vdois')['cliques'] === 1);
$r = $post($passe, ['webdriver' => true] + $bom);
$j = json_decode($r['body'], true);
confere('webdriver ligado: recebe a página segura', is_array($j) && isset($j['h']) && str_contains($j['h'], 'novidades'), $r['body']);
$r = $post($passe, ['gpu' => 'Google SwiftShader', 'canvas' => false] + $bom);
confere('SwiftShader + sem canvas: barrado', isset(json_decode($r['body'], true)['h']));
$r = $post($passe, ['gpu' => 'Google SwiftShader'] + $bom);
confere('só um sinal ruim (SwiftShader): passa', isset(json_decode($r['body'], true)['u']));
$r = $post('123.' . str_repeat('a', 64), $bom);
confere('passe forjado: barrado', isset(json_decode($r['body'], true)['h']));
$r = $post($passe, $bom, ['ip' => '8.8.8.8']);
confere('passe de outro IP: barrado', isset(json_decode($r['body'], true)['h']));
$r = $post('', $bom);
confere('sem passe: barrado', isset(json_decode($r['body'], true)['h']));
confere('robô nem recebe o desafio (página segura direta)', str_contains(req(['slug' => 'vdois', 'ua' => 'curl/8'])['body'], 'novidades'));
criar($uid, ['slug' => 'vdoisr', 'protecao' => 'cloaker_v2', 'bloqueio_tipo' => 'redirecionar', 'bloqueio_url' => 'https://exemplo.com']);
$g = req(['slug' => 'vdoisr']); preg_match('/data-p="([^"]+)"/', $g['body'], $m);
$r = req(['slug' => 'vdoisr', 'metodo' => 'POST', 'corpo' => json_encode(['p' => html_entity_decode($m[1]), 's' => ['webdriver' => true] + $bom])]);
confere('V2 + redirecionar: JSON manda para a URL', (json_decode($r['body'], true)['u'] ?? '') === 'https://exemplo.com', $r['body']);
confere('sem JavaScript: <noscript>/meta refresh só com redirecionar', str_contains($g['body'], 'http-equiv="refresh"') && str_contains(req(['slug' => 'vdois'])['body'], '<noscript>'));
// Defeito achado na VPS (02/11): o meta refresh fora do <noscript> mandava TAMBÉM quem tem JavaScript para a URL de bloqueio, sem nunca chegar ao bot.
confere('meta refresh só vale dentro do <noscript> (com JavaScript o visitante não é mandado embora)',
    substr_count($g['body'], 'http-equiv="refresh"') === 1
    && substr_count(preg_replace('#<noscript>.*?</noscript>#s', '', $g['body']), 'http-equiv="refresh"') === 0);
confere('passe vencido (função)', !passeDesafioRedirecionamentoValido(emitirPasseDesafioRedirecionamento('vdois', '1.1.1.1', time() - 500), 'vdois', '1.1.1.1'));
confere('passe válido (função)', passeDesafioRedirecionamentoValido(emitirPasseDesafioRedirecionamento('vdois', '1.1.1.1'), 'vdois', '1.1.1.1'));
confere('passe de outro link (função)', !passeDesafioRedirecionamentoValido(emitirPasseDesafioRedirecionamento('vdois', '1.1.1.1'), 'outro', '1.1.1.1'));

echo "== 9. Compatibilidade e migração\n";
confere('valor antigo filtrar_robos vira cloaker ao salvar', (criar($uid, ['slug' => 'velho', 'protecao' => 'filtrar_robos'])['sucesso'] ?? false)
    && $pdo->query("SELECT protecao FROM redirecionadores WHERE slug='velho'")->fetchColumn() === 'cloaker');
confere('proteção inválida recusada', !(criar($uid, ['slug' => 'inv', 'protecao' => 'xyz'])['sucesso'] ?? true));
$pdo->exec("UPDATE redirecionadores SET protecao='filtrar_robos' WHERE slug='velho'");
foreach (['bloqueio_tipo', 'bloqueio_url', 'bloquear_bots', 'anti_compartilhamento', 'filtrar_pais', 'paises', 'validar_idioma', 'bloqueados'] as $col) {
    $pdo->exec("ALTER TABLE redirecionadores DROP COLUMN $col");
}
migrarProtecaoRedirecionamento();
$cols = $pdo->query("SHOW COLUMNS FROM redirecionadores")->fetchAll(PDO::FETCH_COLUMN);
confere('migração recria as 8 colunas', count(array_intersect($cols, ['bloqueio_tipo', 'bloqueio_url', 'bloquear_bots', 'anti_compartilhamento', 'filtrar_pais', 'paises', 'validar_idioma', 'bloqueados'])) === 8);
confere('migração converte filtrar_robos em cloaker', $pdo->query("SELECT protecao FROM redirecionadores WHERE slug='velho'")->fetchColumn() === 'cloaker');
migrarProtecaoRedirecionamento();
confere('migração é idempotente', true);
confere('link antigo (colunas novas com padrão) continua funcionando', req(['slug' => 'livre'])['status'] === 302);
// l.php num banco que ainda não migrou: cria sozinho
foreach (['bloqueados', 'paises'] as $col) { $pdo->exec("ALTER TABLE redirecionadores DROP COLUMN $col"); }
$pdo->exec("DROP TABLE geo_ip_cache");
$r = req(['slug' => 'livre']);
confere('l.php num banco sem as colunas migra sozinho e responde', $r['status'] === 302, (string) $r['status'] . substr($r['body'], 0, 80));

echo "== 10. Listagem e tela\n";
// (a migração do teste apagou as colunas e os contadores: recomeça de um bloqueio e de um link novos)
req(['slug' => 'cloak', 'ua' => 'curl/8']);
criar($uid, ['slug' => 'brpt-novo', 'filtrar_pais' => 1, 'paises' => ['PT', 'BR']]);
$lista = listarRedirecionadores($uid);
$cloak = array_values(array_filter($lista, fn($l) => $l['slug'] === 'cloak'))[0] ?? [];
confere('lista traz bloqueados', isset($cloak['bloqueados']) && $cloak['bloqueados'] === 1, json_encode($cloak['bloqueados'] ?? null));
$op = opcoesRedirecionamento($uid);
confere('opções trazem os 15 países', count($op['paises']) === 15 && $op['paises'][0] === ['codigo' => 'BR', 'nome' => 'Brasil']);
confere('obter devolve países em lista e inteiros', ($x = obterRedirecionador($uid, (int) $pdo->query("SELECT id FROM redirecionadores WHERE slug='brpt-novo'")->fetchColumn()))
    && $x['paises'] === ['BR', 'PT'] && $x['filtrar_pais'] === 1);

echo "\n== resultado: $ok ok, " . count($falhas) . " falha(s)\n";
foreach ($falhas as $f) { echo "   - $f\n"; }
exit($falhas ? 1 : 0);
