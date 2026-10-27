<?php
declare(strict_types=1);

/**
 * Integração dos avisos push com um "navegador" SIMULADO e um serviço de push FALSO (tests/push/servidor_falso.php).
 * Roda com o banco REAL do ambiente (na VPS, que não é produção): cria usuários, aparelhos, bot e vendas de teste, e APAGA tudo no fim
 * (restaura também as configurações push_*). Não fala com a internet: os endereços dos aparelhos apontam para 127.0.0.1.
 *
 *   sudo -u www-data env PUSH_MODO_TESTE=1 php tests/push/integracao_navegador_simulado.php        (Linux/VPS)
 *
 * O "aparelho" gera as próprias chaves P-256 e o segredo de autenticação, inscreve-se, e o teste DECIFRA o que o servidor mandou
 * (RFC 8291) e confere título, corpo, tag, cabeçalhos (TTL, Urgency, Topic, VAPID com assinatura verificada) e que nada de cliente vaza.
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

$ok = 0;
$falhas = 0;
function confere(string $nome, bool $certo, string $detalhe = ''): void
{
    global $ok, $falhas;
    $certo ? $ok++ : $falhas++;
    echo ($certo ? 'OK     ' : 'FALHOU ') . $nome . ($certo || $detalhe === '' ? '' : '  -> ' . $detalhe) . PHP_EOL;
}
function secao(string $titulo): void
{
    echo PHP_EOL . '--- ' . $titulo . PHP_EOL;
}

// ---------- ambiente: servidor falso, configurações, usuários ----------
$dir_falso = sys_get_temp_dir() . '/push_falso_' . getmypid();
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
$servidor = proc_open([PHP_BINARY, '-S', "127.0.0.1:$porta", __DIR__ . '/servidor_falso.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $dir_falso . '/servidor.log', 'w'], 2 => ['file', $dir_falso . '/servidor.log', 'a']], $pipes, __DIR__,
    ['PUSH_FALSO_DIR' => $dir_falso, 'PHP_CLI_SERVER_WORKERS' => '24', 'PATH' => (string) getenv('PATH')]);
for ($t = 0; $t < 50; $t++) {
    $s = @fsockopen('127.0.0.1', $porta, $e1, $e2, 0.2);
    if ($s !== false) {
        fclose($s);
        break;
    }
    usleep(100000);
}
$BASE = "http://127.0.0.1:$porta";

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

$usuarios = [];
$bots = [];
$sufixo = bin2hex(random_bytes(3));
function criarUsuario(string $rotulo): int
{
    global $pdo, $usuarios, $sufixo;
    $pdo->prepare("INSERT INTO usuarios (nome, email, senha, perfil) VALUES (?, ?, 'x', 'usuario')")->execute(['Push Teste ' . $rotulo, "push-teste-$rotulo-$sufixo@estresse.invalid"]);
    $id = (int) $pdo->lastInsertId();
    $usuarios[] = $id;
    return $id;
}
function criarBot(int $id_usuario): int
{
    global $pdo, $bots, $sufixo;
    $pdo->prepare('INSERT INTO bots (id_usuario, token, nome_usuario, primeiro_nome) VALUES (?, ?, ?, ?)')->execute([$id_usuario, 'PUSHTESTE:' . bin2hex(random_bytes(8)), 'bot_push_teste', 'Bot Push Teste']);
    $id = (int) $pdo->lastInsertId();
    $bots[] = $id;
    return $id;
}
function criarVenda(int $bot, float $valor, string $produto = 'Plano Teste'): int
{
    global $pdo;
    $pdo->prepare("INSERT INTO vendas (id_telegram, bot_id, valor, status, transacao_id, nome_produto) VALUES ('777000111', ?, ?, 'gerado', ?, ?)")
        ->execute([$bot, $valor, 'TXSECRETO' . bin2hex(random_bytes(6)), $produto]);
    return (int) $pdo->lastInsertId();
}
/** Aparelho simulado: gera as chaves, inscreve pelo mesmo caminho da tela e devolve o que o teste precisa para decifrar. */
function criarAparelho(int $id_usuario, string $cenario, string $nome, string $ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1'): array
{
    global $pdo, $BASE;
    $par = pushGerarParEc();
    $d = openssl_pkey_get_details($par['chave']);
    $auth = random_bytes(16);
    $endpoint = "$BASE/$cenario/$nome";
    $r = pushInscrever($pdo, $id_usuario, ['subscription' => ['endpoint' => $endpoint, 'keys' => ['p256dh' => pushB64uCodificar($par['publica']), 'auth' => pushB64uCodificar($auth)]]], $ua);
    return ['id' => (int) ($r['id'] ?? 0), 'r' => $r, 'endpoint' => $endpoint, 'pub' => pushB64uCodificar($par['publica']), 'priv' => pushB64uCodificar(str_pad($d['ec']['d'], 32, "\0", STR_PAD_LEFT)), 'auth' => pushB64uCodificar($auth)];
}
function recebidos(): array
{
    global $dir_falso;
    $arq = $dir_falso . '/recebidos.jsonl';
    if (!is_file($arq)) {
        return [];
    }
    $saida = [];
    foreach (file($arq, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
        $j = json_decode($l, true);
        if (is_array($j)) {
            $j['corpo'] = (string) base64_decode((string) $j['corpo_b64']);
            $saida[] = $j;
        }
    }
    return $saida;
}
function recebidosPara(string $caminho): array
{
    return array_values(array_filter(recebidos(), fn($r) => $r['caminho'] === $caminho));
}
function decifrado(array $rec, array $ap): ?array
{
    $texto = pushDecifrarPayloadParaTeste($rec['corpo'], $ap['priv'], $ap['pub'], $ap['auth']);
    $j = $texto === null ? null : json_decode($texto, true);
    return is_array($j) ? $j : null;
}
function limparFila(): void
{
    global $pdo;
    $pdo->exec('DELETE FROM push_fila');
}
function limparRecebidos(): void
{
    global $dir_falso;
    @unlink($dir_falso . '/recebidos.jsonl');
}
function itensDo(int $id_inscricao): array
{
    global $pdo;
    $st = $pdo->prepare('SELECT *, TIMESTAMPDIFF(MICROSECOND, NOW(3), disponivel_em) / 1000000 AS em_s FROM push_fila WHERE id_inscricao = ? ORDER BY id');
    $st->execute([$id_inscricao]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
function controle(array $c): void
{
    global $dir_falso;
    file_put_contents($dir_falso . '/controle.json', json_encode($c));
}
/** Roda cron/push_worker.php --uma-vez --lote=N em outro processo. Devolve o que ele imprimiu. */
function abrirTrabalhador(int $lote): array
{
    global $raiz;
    $proc = proc_open([PHP_BINARY, $raiz . '/cron/push_worker.php', '--uma-vez', '--lote=' . $lote], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $raiz, ['PUSH_MODO_TESTE' => '1', 'PATH' => (string) getenv('PATH')]);
    return ['proc' => $proc, 'pipes' => $pipes];
}
function fecharTrabalhador(array $t): array
{
    $saida = stream_get_contents($t['pipes'][1]);
    $erro = stream_get_contents($t['pipes'][2]);
    proc_close($t['proc']);
    return ['saida' => trim((string) $saida), 'erro' => trim((string) $erro)];
}

register_shutdown_function(function () use (&$servidor, $dir_falso, &$usuarios, &$bots, $config_original, $chaves_config, $hora_inicio, $metricas_antes) {
    global $pdo;
    try {
        $pdo->exec("RENAME TABLE push_fila_x TO push_fila");   // caso uma falha tenha deixado a tabela renomeada
    } catch (\Throwable $e) {
    }
    try {
        foreach ($bots as $b) {
            $pdo->prepare('DELETE FROM vendas WHERE bot_id = ?')->execute([$b]);
        }
        foreach ($usuarios as $u) {
            $pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$u]);   // bots, aparelhos e fila caem junto (CASCADE)
        }
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
});

// ---------- preparo ----------
pushGarantirChaves($pdo);
definirConfigSistema('push_ativo', '1');
definirConfigSistema('push_icone_v', 'v123');
definirConfigSistema('push_contato', 'https://exemplo-teste.invalid');
pushAtivo(true);
confere('o serviço falso respondeu na porta ' . $porta . ' e o modo de teste está ligado', $porta > 0 && pushModoTeste() && pushAtivo());
$publica_vapid = pushVapidPublica($pdo);
confere('as chaves VAPID existem (pública de 65 bytes, privada decifrável)', strlen((string) pushB64uDecodificar($publica_vapid)) === 65 && str_contains(pushVapidPrivadaPem($pdo), 'PRIVATE KEY'));

$u1 = criarUsuario('a');
$bot1 = criarBot($u1);
$ap = criarAparelho($u1, 'ok', 'dev1');
confere('o aparelho simulado se inscreveu pelo mesmo caminho da tela (iPhone · Safari, plataforma ios)', $ap['id'] > 0 && ($ap['r']['apelido'] ?? '') === 'iPhone · Safari' && ($ap['r']['plataforma'] ?? '') === 'ios', json_encode($ap['r']));

// ---------- S1: caminho B (sem trabalhador) ----------
secao('S1  Pix pago, SEM trabalhador vivo (caminho B): envia depois de responder');
@unlink(PUSH_ARQUIVO_BATIMENTO);
limparFila();
limparRecebidos();
$v1 = criarVenda($bot1, 49.90);
$GLOBALS['push_envio_imediato'] = [];
enfileirarPush($u1, PUSH_TIPO_PIX_PAGO, $v1);
$itens = itensDo($ap['id']);
confere('o item nasceu na fila com 2 s de folga (o trabalhador/cron não pega antes do envio imediato)', count($itens) === 1 && $itens[0]['estado'] === 'pendente' && (float) $itens[0]['em_s'] > 0.5 && (float) $itens[0]['em_s'] <= 2.1, json_encode($itens));
confere('a validade do Pix pago é de 2 h', (function () use ($pdo, $itens) {
    $d = (int) $pdo->query('SELECT TIMESTAMPDIFF(SECOND, NOW(), expira_em) FROM push_fila WHERE id = ' . (int) $itens[0]['id'])->fetchColumn();
    return $d > 7190 && $d <= 7200;
})());
confere('nada foi enviado ainda (o envio só acontece depois da resposta ao webhook)', !recebidos());
confere('a requisição registrou o envio imediato para o fim', count($GLOBALS['push_envio_imediato']) === 1);
enviarPushesPosResposta();
$rec = recebidosPara('/ok/dev1');
confere('o envio imediato entregou 1 requisição ao serviço de push e a fila ficou vazia', count($rec) === 1 && !itensDo($ap['id']), 'requisições=' . count($rec));
$m = $rec ? decifrado($rec[0], $ap) : null;
confere('o aparelho DECIFRA o aviso: "Nova Venda!" · "Você recebeu R$ 49,90"', $m !== null && $m['t'] === '💰 Nova Venda!' && $m['b'] === 'Você recebeu R$ 49,90' && $m['k'] === 'pp' && $m['g'] === 'pp-' . $v1 && $m['u'] === '/vendas' && $m['n'] === 1 && $m['v'] === 'v123', json_encode($m, JSON_UNESCAPED_UNICODE));
confere('o aviso só tem as chaves previstas e o horário é recente', $m !== null && array_keys($m) === ['k', 't', 'b', 'g', 'u', 'ts', 'n', 'v'] && abs($m['ts'] / 1000 - time()) < 30);
$todo_texto = json_encode($m, JSON_UNESCAPED_UNICODE);
confere('NADA do cliente vaza: nem o ID do Telegram nem o código da transação da venda', !str_contains($todo_texto, '777000111') && !str_contains($todo_texto, 'TXSECRETO'));
$r0 = $rec[0] ?? [];
confere('cabeçalhos: TTL 7200, Urgency high, aes128gcm, octet-stream', ($r0['ttl'] ?? '') === '7200' && ($r0['urgencia'] ?? '') === 'high' && ($r0['codificacao'] ?? '') === 'aes128gcm' && ($r0['tipo'] ?? '') === 'application/octet-stream' && ($r0['topico'] ?? 'x') === '', json_encode($r0 ? array_diff_key($r0, ['corpo' => 1, 'corpo_b64' => 1]) : []));
preg_match('/^vapid t=([\w-]+)\.([\w-]+)\.([\w-]+), k=([\w-]+)$/', (string) ($r0['autorizacao'] ?? ''), $mv);
$claims = $mv ? json_decode((string) pushB64uDecodificar($mv[2]), true) : [];
$assin = $mv ? (string) pushB64uDecodificar($mv[3]) : '';
$der = (function (string $raw) {
    $p = [];
    foreach ([substr($raw, 0, 32), substr($raw, 32)] as $i) {
        $i = ltrim($i, "\0");
        if ($i === '' || (ord($i[0]) & 0x80)) {
            $i = "\0" . $i;
        }
        $p[] = "\x02" . chr(strlen($i)) . $i;
    }
    $c = implode('', $p);
    return "\x30" . pushDerComprimento(strlen($c)) . $c;
})($assin);
confere('o VAPID vem com chave pública, "aud" do serviço, "sub" do contato configurado e assinatura que CONFERE', $mv && $mv[4] === $publica_vapid && ($claims['aud'] ?? '') === $BASE && ($claims['sub'] ?? '') === 'https://exemplo-teste.invalid' && ($claims['exp'] ?? 0) > time() + 40000 && openssl_verify($mv[1] . '.' . $mv[2], $der, pushPemPublicaEc((string) pushB64uDecodificar($publica_vapid)), OPENSSL_ALGO_SHA256) === 1);
$linha = $pdo->query('SELECT ultimo_envio_ok, falhas_seguidas FROM push_inscricoes WHERE id = ' . $ap['id'])->fetch(PDO::FETCH_ASSOC);
confere('o aparelho registra "último envio ok" e zero falhas', $linha['ultimo_envio_ok'] !== null && (int) $linha['falhas_seguidas'] === 0);

// ---------- S2: caminho A ----------
secao('S2  Pix pago, COM trabalhador vivo (caminho A): só enfileira e o ciclo do trabalhador entrega');
limparRecebidos();
pushBatimento();
confere('com o arquivo de batimento fresco o site reconhece o trabalhador vivo', pushTrabalhadorVivo());
$v2 = criarVenda($bot1, 120.00, 'Pack VIP');
$GLOBALS['push_envio_imediato'] = [];
enfileirarPush($u1, PUSH_TIPO_PIX_PAGO, $v2);
$itens = itensDo($ap['id']);
confere('o item nasce já disponível (sem a folga de 2 s) e o processo NÃO registra envio por conta própria', count($itens) === 1 && (float) $itens[0]['em_s'] <= 0.2 && !$GLOBALS['push_envio_imediato'], json_encode($itens));
$t0 = microtime(true);
$feitos = pushCicloTrabalhador($pdo);
$rec = recebidosPara('/ok/dev1');
$m = $rec ? decifrado($rec[0], $ap) : null;
confere('um ciclo do trabalhador entregou o aviso (Pack VIP, R$ 120,00) e esvaziou a fila, em ' . round((microtime(true) - $t0) * 1000) . ' ms', $feitos === 1 && count($rec) === 1 && $m !== null && $m['t'] === 'Pix pago · R$ 120,00' && $m['b'] === 'Pack VIP · @bot_push_teste' && !itensDo($ap['id']));
confere('a segunda entrega reaproveitou o mesmo aparelho sem erro (tag única por venda)', $m !== null && $m['g'] === 'pp-' . $v2);

// ---------- S3: Pix gerado ----------
secao('S3  Pix gerado: 1 aviso a cada 30 s por aparelho; dentro da janela acumula e vira resumo');
limparRecebidos();
$v3 = criarVenda($bot1, 19.90);
enfileirarPush($u1, PUSH_TIPO_PIX_GERADO, $v3);
pushCicloTrabalhador($pdo);
$rec = recebidosPara('/ok/dev1');
$m = $rec ? decifrado($rec[0], $ap) : null;
confere('o 1º Pix gerado sai na hora: "Pix gerado · R$ 19,90" · "Aguardando pagamento · Plano Teste", urgência normal, TTL 600', $m !== null && $m['k'] === 'pg' && $m['t'] === 'Pix gerado · R$ 19,90' && $m['b'] === 'Aguardando pagamento · Plano Teste' && ($rec[0]['urgencia'] ?? '') === 'normal' && ($rec[0]['ttl'] ?? '') === '600');
$v4 = criarVenda($bot1, 10.00);
$v5 = criarVenda($bot1, 25.50);
enfileirarPush($u1, PUSH_TIPO_PIX_GERADO, $v4);
enfileirarPush($u1, PUSH_TIPO_PIX_GERADO, $v5);
limparRecebidos();
pushCicloTrabalhador($pdo);
$itens = itensDo($ap['id']);
confere('mais 2 Pix gerados dentro da janela de 30 s: NADA é enviado e os 2 itens voltam à fila para o fim da janela', !recebidos() && count($itens) === 2 && $itens[0]['estado'] === 'pendente' && (float) $itens[0]['em_s'] > 20 && (float) $itens[0]['em_s'] <= 31, json_encode(array_map(fn($i) => $i['em_s'], $itens)));
$pdo->exec('UPDATE push_inscricoes SET ultimo_aviso_gerado_em = NOW() - INTERVAL 31 SECOND WHERE id = ' . $ap['id']);
$pdo->exec('UPDATE push_fila SET disponivel_em = NOW() WHERE id_inscricao = ' . $ap['id']);
pushCicloTrabalhador($pdo);
$rec = recebidosPara('/ok/dev1');
$m = $rec ? decifrado($rec[0], $ap) : null;
confere('passada a janela, os 2 viram UM resumo "2 Pix gerados · R$ 35,50", tag fixa e cabeçalho Topic para o serviço substituir o anterior', count($rec) === 1 && $m !== null && $m['k'] === 'rg' && $m['t'] === '2 Pix gerados · R$ 35,50' && $m['g'] === 'pg-resumo' && ($rec[0]['topico'] ?? '') === 'rg' && !itensDo($ap['id']), json_encode($m, JSON_UNESCAPED_UNICODE));

// ---------- S4: rajada de Pix pago ----------
secao('S4  Rajada: 25 Pix pagos no mesmo aparelho (todos saem individuais)');
limparRecebidos();
$vendas = [];
for ($i = 0; $i < 25; $i++) {
    $vendas[] = criarVenda($bot1, 10.0 + $i);
}
foreach ($vendas as $v) {
    enfileirarPush($u1, PUSH_TIPO_PIX_PAGO, $v);
}
confere('os 25 itens entraram na fila', count(itensDo($ap['id'])) === 25);
pushCicloTrabalhador($pdo);
$rec = recebidosPara('/ok/dev1');
$kinds = array_count_values(array_map(fn($r) => (decifrado($r, $ap)['k'] ?? '?'), $rec));
confere('saíram 25 avisos individuais, nenhum resumo, nenhuma venda some e a fila zerou', ($kinds['pp'] ?? 0) === 25 && ($kinds['rp'] ?? 0) === 0 && !itensDo($ap['id']), json_encode($kinds));

// ---------- S5: respostas do serviço de push ----------
secao('S5  Respostas do serviço de push: 410, 403, 500 (esperas e descarte), 429 (disjuntor), timeout');
$ap_morta = criarAparelho($u1, 'morta', 'd1');
$ap_ass = criarAparelho($u1, 'assinatura', 'd1');
$pdo->exec('UPDATE push_inscricoes SET ativo = 0 WHERE id_usuario = ' . $u1 . ' AND id NOT IN (' . $ap_morta['id'] . ')');
limparFila();
limparRecebidos();
$v = criarVenda($bot1, 15.00);
enfileirarPush($u1, PUSH_TIPO_PIX_PAGO, $v);
pushCicloTrabalhador($pdo);
$existe = (int) $pdo->query('SELECT COUNT(*) FROM push_inscricoes WHERE id = ' . $ap_morta['id'])->fetchColumn();
confere('410: o aparelho morto é APAGADO e a fila dele cai junto', $existe === 0 && !itensDo($ap_morta['id']));
$pdo->exec('UPDATE push_inscricoes SET ativo = 1 WHERE id = ' . $ap_ass['id']);
limparFila();
$v = criarVenda($bot1, 16.00);
enfileirarPush($u1, PUSH_TIPO_PIX_PAGO, $v);
pushCicloTrabalhador($pdo);
$existe = (int) $pdo->query('SELECT COUNT(*) FROM push_inscricoes WHERE id = ' . $ap_ass['id'])->fetchColumn();
confere('403 (assinatura): o item é descartado SEM repetir (a inscrição fica) e conta como erro fatal nas métricas', !itensDo($ap_ass['id']) && $existe === 1 && (int) $pdo->query("SELECT COALESCE(SUM(erros_fatais),0) FROM push_metricas_hora WHERE hora >= '$hora_inicio'")->fetchColumn() >= 1);
$pdo->prepare('DELETE FROM push_inscricoes WHERE id = ?')->execute([$ap_ass['id']]);

$ap_500 = criarAparelho($u1, 'erro500', 'd1');
limparFila();
$v = criarVenda($bot1, 17.00);
enfileirarPush($u1, PUSH_TIPO_PIX_PAGO, $v);
$esperas = [];
for ($n = 1; $n <= 5; $n++) {
    pushCicloTrabalhador($pdo);
    $it = itensDo($ap_500['id']);
    if ($n < 5) {
        $esperas[] = $it ? round((float) $it[0]['em_s'], 1) . '/t=' . $it[0]['tentativas'] : 'sumiu';
        $pdo->exec('UPDATE push_fila SET disponivel_em = NOW() WHERE id_inscricao = ' . $ap_500['id']);
    }
}
$falhas_ap = (int) $pdo->query('SELECT falhas_seguidas FROM push_inscricoes WHERE id = ' . $ap_500['id'])->fetchColumn();
confere('500: reagenda 4 vezes com a espera da escada do Pix pago (≈2 s, 10 s, 60 s, 300 s, com ±20%) e na 5ª falha DESCARTA', !itensDo($ap_500['id']) && count($esperas) === 4 && str_ends_with($esperas[0], 't=1') && str_ends_with($esperas[3], 't=4') && (float) explode('/', $esperas[0])[0] >= 0.8 && (float) explode('/', $esperas[0])[0] <= 2.5 && (float) explode('/', $esperas[1])[0] >= 7 && (float) explode('/', $esperas[1])[0] <= 12.5 && (float) explode('/', $esperas[2])[0] >= 45 && (float) explode('/', $esperas[2])[0] <= 75 && (float) explode('/', $esperas[3])[0] >= 230 && (float) explode('/', $esperas[3])[0] <= 375, json_encode($esperas));
confere('cada falha soma em falhas_seguidas do aparelho (5 falhas, nenhuma desativação antes de 20 e de 24 h)', $falhas_ap === 5 && (int) $pdo->query('SELECT ativo FROM push_inscricoes WHERE id = ' . $ap_500['id'])->fetchColumn() === 1, 'falhas=' . $falhas_ap);
// Pix gerado: máximo de 3 tentativas
limparFila();
$v = criarVenda($bot1, 18.00);
enfileirarPush($u1, PUSH_TIPO_PIX_GERADO, $v);
$pdo->exec('UPDATE push_inscricoes SET ultimo_aviso_gerado_em = NULL WHERE id = ' . $ap_500['id']);
$tentou = 0;
for ($n = 1; $n <= 4; $n++) {
    $pdo->exec('UPDATE push_fila SET disponivel_em = NOW() WHERE id_inscricao = ' . $ap_500['id']);
    $pdo->exec('UPDATE push_inscricoes SET ultimo_aviso_gerado_em = NULL WHERE id = ' . $ap_500['id']);
    if (itensDo($ap_500['id'])) {
        pushCicloTrabalhador($pdo);
        $tentou++;
    }
}
confere('500 no Pix gerado: tenta no máximo 3 vezes e descarta', $tentou === 3 && !itensDo($ap_500['id']), 'tentativas=' . $tentou);

$ap_lim = criarAparelho($u1, 'limite', 'd1');
$ap_ok2 = criarAparelho($u1, 'ok', 'dev2');
$pdo->exec('UPDATE push_inscricoes SET ativo = 0 WHERE id_usuario = ' . $u1 . ' AND id NOT IN (' . $ap_lim['id'] . ',' . $ap_ok2['id'] . ')');
limparFila();
limparRecebidos();
controle(['retry_after' => 7]);
$v = criarVenda($bot1, 21.00);
enfileirarPush($u1, PUSH_TIPO_PIX_PAGO, $v);
pushCicloTrabalhador($pdo);
$it_lim = itensDo($ap_lim['id']);
$it_ok2 = itensDo($ap_ok2['id']);
confere('429: respeita o Retry-After de 7 s (item volta à fila para ≈7 s depois, sem gastar tentativa)', count($it_lim) === 1 && $it_lim[0]['estado'] === 'pendente' && (int) $it_lim[0]['tentativas'] === 0 && (float) $it_lim[0]['em_s'] > 5 && (float) $it_lim[0]['em_s'] <= 7.5, json_encode($it_lim));
$enviados_dev2 = recebidosPara('/ok/dev2');
$pdo->exec('DELETE FROM push_fila');
$v = criarVenda($bot1, 22.00);
enfileirarPush($u1, PUSH_TIPO_PIX_PAGO, $v);
limparRecebidos();
pushCicloTrabalhador($pdo);
confere('disjuntor: depois do 429 o host fica PAUSADO e os avisos dos outros aparelhos do mesmo serviço esperam (nada novo é enviado)', !recebidos() && count(itensDo($ap_ok2['id'])) === 1 && (float) itensDo($ap_ok2['id'])[0]['em_s'] > 1);
pushHostPausadoAte('127.0.0.1', 0);
controle([]);

$ap_len = criarAparelho($u1, 'lento', 'd1');
$pdo->exec('UPDATE push_inscricoes SET ativo = 0 WHERE id_usuario = ' . $u1 . ' AND id <> ' . $ap_len['id']);
limparFila();
controle(['lento_s' => 4]);
$v = criarVenda($bot1, 23.00);
enfileirarPush($u1, PUSH_TIPO_PIX_PAGO, $v);
$lote = pushReservarLote($pdo);
$t0 = microtime(true);
pushProcessarLoteReservado($pdo, (string) $lote, ['timeout' => 1, 'conexao_timeout' => 1]);
$gasto = microtime(true) - $t0;
$it = itensDo($ap_len['id']);
confere('timeout (serviço lento): corta em ~1 s, conta como falha temporária e agenda nova tentativa', $gasto < 2.5 && count($it) === 1 && (int) $it[0]['tentativas'] === 1 && (float) $it[0]['em_s'] > 0.5, 'levou ' . round($gasto, 1) . ' s');
controle([]);

// ---------- S6: dois trabalhadores ao mesmo tempo ----------
secao('S6  Vários trabalhadores ao mesmo tempo: cada aviso sai UMA vez só');
$u2 = criarUsuario('b');
$bot2 = criarBot($u2);
$aps = [];
$donos6 = [$u2];
for ($k = 1; $k < 6; $k++) {
    $donos6[] = criarUsuario('b' . $k);
}
$n_ap = 0;
foreach ($donos6 as $dono6) {
    for ($i = 1; $i <= 10; $i++) {   // 10 aparelhos por vendedor é o limite do sistema
        $aps[] = criarAparelho($dono6, 'ok', 'par' . (++$n_ap));
    }
}
$pdo->exec('DELETE FROM push_fila');
limparRecebidos();
$vp = criarVenda($bot2, 33.00);
foreach ($donos6 as $dono6) {
    enfileirarPush($dono6, PUSH_TIPO_PIX_PAGO, $vp);
}
confere('60 aparelhos, 60 itens na fila', (int) $pdo->query('SELECT COUNT(*) FROM push_fila')->fetchColumn() === 60);
$ts = [abrirTrabalhador(7), abrirTrabalhador(7), abrirTrabalhador(7)];
$saidas = array_map('fecharTrabalhador', $ts);
$somados = 0;
foreach ($saidas as $s) {
    $j = json_decode($s['saida'], true);
    $somados += (int) ($j['itens'] ?? 0);
}
$rec = recebidos();
$por_caminho = array_count_values(array_map(fn($r) => $r['caminho'], $rec));
confere('os 3 trabalhadores somaram exatamente 60 itens tratados e o serviço recebeu 60 requisições', $somados === 60 && count($rec) === 60, "somados=$somados recebidas=" . count($rec) . ' erro=' . $saidas[0]['erro']);
confere('NENHUM aviso duplicado (60 aparelhos distintos, 1 requisição cada) e a fila ficou vazia', count($por_caminho) === 60 && max($por_caminho) === 1 && (int) $pdo->query('SELECT COUNT(*) FROM push_fila')->fetchColumn() === 0);

// ---------- S7: recuperação ----------
secao('S7  Processo que morreu no meio do lote, aviso vencido e aparelho desativado');
$pdo->exec('DELETE FROM push_fila');
limparRecebidos();
$ap7 = $aps[0];
$v7 = criarVenda($bot2, 44.00);
$pdo->prepare("INSERT INTO push_fila (id_inscricao, tipo, id_venda, estado, tentativas, disponivel_em, expira_em, reservado_ate, lote) VALUES (?, 2, ?, 'enviando', 0, NOW(), NOW() + INTERVAL 1 HOUR, NOW() - INTERVAL 5 SECOND, 'morreu0000000000')")->execute([$ap7['id'], $v7]);
$man = pushManutencao($pdo);
confere('a manutenção devolve à fila o item que ficou "enviando" com a reserva vencida (+1 tentativa)', $man['devolvidos'] === 1 && ($it = itensDo($ap7['id'])) && $it[0]['estado'] === 'pendente' && (int) $it[0]['tentativas'] === 1);
pushCicloTrabalhador($pdo);
confere('e o aviso é entregue depois da recuperação (nada se perdeu)', count(recebidosPara('/ok/par1')) === 1 && !itensDo($ap7['id']));
$pdo->prepare("INSERT INTO push_fila (id_inscricao, tipo, id_venda, estado, disponivel_em, expira_em) VALUES (?, 1, ?, 'pendente', NOW(), NOW() - INTERVAL 1 SECOND)")->execute([$ap7['id'], $v7]);
limparRecebidos();
$man = pushManutencao($pdo);
pushCicloTrabalhador($pdo);
confere('aviso VENCIDO (passou da validade) é descartado e nunca enviado', $man['vencidos'] === 1 && !recebidos() && !itensDo($ap7['id']));
$pdo->prepare("INSERT INTO push_fila (id_inscricao, tipo, id_venda, estado, disponivel_em, expira_em) VALUES (?, 2, ?, 'pendente', NOW(), NOW() + INTERVAL 1 HOUR)")->execute([$ap7['id'], $v7]);
$pdo->exec('UPDATE push_inscricoes SET ativo = 0 WHERE id = ' . $ap7['id']);
limparRecebidos();
pushCicloTrabalhador($pdo);
confere('aviso de aparelho DESATIVADO depois de entrar na fila é descartado sem enviar', !recebidos() && !itensDo($ap7['id']));
$pdo->exec('UPDATE push_inscricoes SET ativo = 1, falhas_seguidas = 20, primeira_falha_em = NOW() - INTERVAL 25 HOUR WHERE id = ' . $aps[1]['id']);
$pdo->exec('UPDATE push_inscricoes SET ativo = 1, falhas_seguidas = 20, primeira_falha_em = NOW() - INTERVAL 2 HOUR WHERE id = ' . $aps[2]['id']);
$man = pushManutencao($pdo);
confere('20 falhas seguidas por mais de 24 h desativam o aparelho; as mesmas 20 falhas em 2 h NÃO', (int) $pdo->query('SELECT ativo FROM push_inscricoes WHERE id = ' . $aps[1]['id'])->fetchColumn() === 0 && (int) $pdo->query('SELECT ativo FROM push_inscricoes WHERE id = ' . $aps[2]['id'])->fetchColumn() === 1 && $man['desativados'] === 1);
$pdo->exec('UPDATE push_inscricoes SET ativo = 1 WHERE id_usuario IN (' . implode(',', $donos6) . ')');

// ---------- S8: ganchos ----------
secao('S8  Ganchos: aposPagamentoConfirmado enfileira só quando o interruptor está ligado, e NUNCA atrapalha a confirmação');
$pdo->exec('DELETE FROM push_fila');
$u3 = criarUsuario('c');
$bot3 = criarBot($u3);
$ap3 = criarAparelho($u3, 'ok', 'gancho1');
$ap3b = criarAparelho($u3, 'ok', 'gancho2');
$pdo->exec('UPDATE push_inscricoes SET pref_pix_pago = 0 WHERE id = ' . $ap3b['id']);
$v8 = criarVenda($bot3, 77.70);
$json_venda = json_encode(['id' => $v8, 'bot_id' => $bot3, 'id_telegram' => '777000111', 'valor' => 77.70, 'nome_produto' => 'Plano Teste']);
function rodarGancho(string $json): array
{
    global $raiz;
    $proc = proc_open([PHP_BINARY, __DIR__ . '/_gancho_filho.php', $json], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $raiz, ['PUSH_MODO_TESTE' => '1', 'PATH' => (string) getenv('PATH')]);
    $saida = stream_get_contents($pipes[1]);
    $erro = stream_get_contents($pipes[2]);
    $codigo = proc_close($proc);
    return ['saida' => trim((string) $saida), 'erro' => trim((string) $erro), 'codigo' => $codigo];
}
$r = rodarGancho($json_venda);
confere('com o interruptor LIGADO, a confirmação do pagamento cria o item só para o aparelho com "Pix pago" ligado', $r['codigo'] === 0 && count(itensDo($ap3['id'])) === 1 && count(itensDo($ap3b['id'])) === 0, json_encode($r));
$pdo->exec('DELETE FROM push_fila');
definirConfigSistema('push_ativo', '0');
$r = rodarGancho($json_venda);
confere('com o interruptor DESLIGADO o gancho não faz nada (a confirmação segue normal)', $r['codigo'] === 0 && !(int) $pdo->query('SELECT COUNT(*) FROM push_fila')->fetchColumn(), json_encode($r));
definirConfigSistema('push_ativo', '1');
$pdo->exec('RENAME TABLE push_fila TO push_fila_x');
try {
    $r = rodarGancho($json_venda);
} finally {
    $pdo->exec('RENAME TABLE push_fila_x TO push_fila');
}
confere('com a tabela da fila QUEBRADA a confirmação do pagamento termina normalmente (o erro de push é engolido)', $r['codigo'] === 0 && !str_contains($r['erro'], 'Fatal') && !str_contains($r['saida'], 'Fatal'), json_encode($r));
$u_sem = criarUsuario('d');
$bot_sem = criarBot($u_sem);
$vs = criarVenda($bot_sem, 5.0);
$pdo->exec('DELETE FROM push_fila');
rodarGancho(json_encode(['id' => $vs, 'bot_id' => $bot_sem, 'id_telegram' => '1', 'valor' => 5.0, 'nome_produto' => 'x']));
confere('vendedor SEM aparelho inscrito: nenhum item na fila (0 linhas)', (int) $pdo->query('SELECT COUNT(*) FROM push_fila')->fetchColumn() === 0);
$fonte_blocos = (string) file_get_contents($raiz . '/funcoes/fluxo_blocos.php');
confere('o gancho do Pix gerado está no ponto certo (logo depois do registro da atividade, dentro de try/catch)', preg_match("/registrarAtividade\\(\\\$id_usuario_dono_insert, 'pix_gerado'.*?enfileirarPush\\(\\(int\\) \\\$id_usuario_dono_insert, PUSH_TIPO_PIX_GERADO, \\\$id_venda_criada\\)/s", $fonte_blocos) === 1);

// ---------- S9: aparelhos ----------
secao('S9  Inscrição de aparelhos: validação, troca de dono, limite de 10, remoção');
$ruim = pushInscrever($pdo, $u3, ['subscription' => ['endpoint' => 'https://evil.com/x', 'keys' => ['p256dh' => $ap3['pub'], 'auth' => $ap3['auth']]]], 'x');
confere('endereço fora da lista dos serviços de push é recusado', $ruim['sucesso'] === false);
$ruim = pushInscrever($pdo, $u3, ['subscription' => ['endpoint' => "$BASE/ok/zz", 'keys' => ['p256dh' => 'lixo', 'auth' => $ap3['auth']]]], 'x');
confere('chave do aparelho inválida é recusada', $ruim['sucesso'] === false);
$troca = pushInscrever($pdo, $u2, ['subscription' => ['endpoint' => $ap3['endpoint'], 'keys' => ['p256dh' => $ap3['pub'], 'auth' => $ap3['auth']]]], 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36');
$dono = (int) $pdo->query('SELECT id_usuario FROM push_inscricoes WHERE id = ' . $ap3['id'])->fetchColumn();
confere('o MESMO aparelho entrando em outra conta troca de dono (sem duplicar) e vira "Windows · Chrome"', $troca['sucesso'] && $troca['id'] === $ap3['id'] && $dono === $u2 && $troca['apelido'] === 'Windows · Chrome');
$pdo->exec('DELETE FROM push_fila');
$u5 = criarUsuario('e');
$ids5 = [];
for ($i = 1; $i <= 11; $i++) {
    $a = criarAparelho($u5, 'ok', 'lim' . $i);
    $ids5[] = $a['id'];
    $pdo->exec('UPDATE push_inscricoes SET ultimo_visto = NOW() - INTERVAL ' . (100 - $i) . ' SECOND WHERE id = ' . $a['id']);
    if ($i === 10) {
        // com os 10 já marcados com horários distintos, o 11º (visto agora) empurra o mais antigo para fora
    }
}
$restantes = $pdo->query('SELECT id FROM push_inscricoes WHERE id_usuario = ' . $u5)->fetchAll(PDO::FETCH_COLUMN);
confere('no máximo 10 aparelhos por vendedor: o mais antigo sai quando entra o 11º', count($restantes) === 10, 'restaram ' . count($restantes));
$apu = criarAparelho($u5, 'ok', 'remover');
$rem = pushRemoverAparelho($pdo, $u5, null, $apu['endpoint']);
confere('remover pelo endereço (é o que o "sair" faz) apaga o aparelho do próprio usuário e NÃO o de outro usuário', $rem === 1 && pushRemoverAparelho($pdo, $u5, null, $ap['endpoint']) === 0 && (int) $pdo->query('SELECT COUNT(*) FROM push_inscricoes WHERE id = ' . $ap['id'])->fetchColumn() === 1);
$restantes = $pdo->query('SELECT id FROM push_inscricoes WHERE id_usuario = ' . $u5 . ' ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
confere('preferências: desligar "Pix gerado" de um aparelho do próprio usuário funciona e de outro usuário NÃO', pushAtualizarPreferencias($pdo, $u5, (int) $restantes[0], false, null) && (int) $pdo->query('SELECT pref_pix_gerado FROM push_inscricoes WHERE id = ' . (int) $restantes[0])->fetchColumn() === 0 && !pushAtualizarPreferencias($pdo, $u1, (int) $restantes[0], true, null));

// ---------- S10: aviso de teste ----------
secao('S10  Botão "Enviar aviso de teste"');
limparRecebidos();
$res = pushEnviarTeste($pdo, $u1, $ap_ok2['id']);
$rec = recebidosPara('/ok/dev2');
$m = $rec ? decifrado($rec[0], $ap_ok2) : null;
confere('o aviso de teste chega ao aparelho, decifrado como "Aviso de teste"', $res['sucesso'] && $m !== null && $m['k'] === 'teste' && $m['t'] === 'Aviso de teste' && $m['g'] === 'teste', json_encode($res));
$res = pushEnviarTeste($pdo, $u2, $ap_ok2['id']);
confere('teste para o aparelho de OUTRO usuário é recusado', $res['sucesso'] === false);
$ap_morto = criarAparelho($u1, 'morta', 'teste');
$res = pushEnviarTeste($pdo, $u1, $ap_morto['id']);
confere('teste para aparelho que o serviço diz estar morto (410) apaga o aparelho, marca "morta" (a tela cancela a inscrição local) e explica', $res['sucesso'] === false && ($res['morta'] ?? false) === true && (int) $pdo->query('SELECT COUNT(*) FROM push_inscricoes WHERE id = ' . $ap_morto['id'])->fetchColumn() === 0 && str_contains($res['mensagem'], 'Ative de novo'));

// ---------- S11: métricas e resumo do admin ----------
secao('S11  Métricas e resumo do admin');
$res = pushResumoAdmin($pdo);
confere('o resumo do admin mostra envios das últimas 24 h, latência e contagem de aparelhos por plataforma', (int) $res['hora24']['enviados'] > 50 && (int) $res['hora24']['lat_n'] > 50 && (int) $res['hora24']['agrupados'] >= 2 && $res['aparelhos']['ios'] >= 1 && $res['aparelhos']['desktop'] >= 1, json_encode($res['hora24']));
confere('a latência média medida (fila → entrega) é baixa neste teste', (int) $res['hora24']['lat_n'] > 0 && ((int) $res['hora24']['lat_soma'] / max(1, (int) $res['hora24']['lat_n'])) < 1500, 'média=' . round((int) $res['hora24']['lat_soma'] / max(1, (int) $res['hora24']['lat_n'])) . ' ms');

// ---------- S12: alerta de saúde para o admin ----------
secao('S12  Alerta no sino do admin (pushVerificarSaude) — roda a cada 5 min pelo cron de métricas');
$inicio_s12 = (string) $pdo->query('SELECT NOW()')->fetchColumn();
$pdo->exec('DELETE FROM push_fila');
$pdo->exec("UPDATE push_inscricoes SET ativo = 1 WHERE id_usuario = $u1");
$n_admins = (int) $pdo->query("SELECT COUNT(*) FROM usuarios WHERE perfil = 'admin'")->fetchColumn();
$contar_alertas = function () use ($pdo, $inicio_s12): int {
    $st = $pdo->prepare("SELECT COUNT(*) FROM atividades WHERE titulo = 'Avisos push com problema' AND criado_em >= ?");
    $st->execute([$inicio_s12]);
    return (int) $st->fetchColumn();
};
confere('fila saudável (nada esperando) e sem falhas: nenhum alerta', pushVerificarSaude($pdo) === null && $contar_alertas() === 0);
$pdo->exec("INSERT INTO push_fila (id_inscricao, tipo, id_venda, disponivel_em, expira_em) SELECT id, 2, 990000001, NOW() - INTERVAL 5 MINUTE, NOW() + INTERVAL 1 DAY FROM push_inscricoes WHERE id_usuario = $u1 LIMIT 1");
$txt = pushVerificarSaude($pdo);
confere('aviso pronto esperando há mais de 2 min (ninguém enviando): devolve o problema e avisa os admins no sino', $txt !== null && str_contains($txt, 'esperando há mais de 2 minutos') && $contar_alertas() === $n_admins, (string) $txt . ' · alertas ' . $contar_alertas() . ' de ' . $n_admins . ' admin(s)');
$txt2 = pushVerificarSaude($pdo);
confere('de novo logo em seguida: no máximo 1 alerta por hora por admin (não duplica)', $txt2 !== null && $contar_alertas() === $n_admins, 'alertas ' . $contar_alertas());
$pdo->exec('DELETE FROM push_fila');
confere('sem item parado, o problema some', pushVerificarSaude($pdo) === null);
$pdo->exec("INSERT INTO push_metricas_hora (hora, enviados, falhas_temporarias) VALUES (DATE_FORMAT(NOW(), '%Y-%m-%d %H:00:00'), 10, 40) ON DUPLICATE KEY UPDATE enviados = enviados + 10, falhas_temporarias = falhas_temporarias + 40");
$txt3 = pushVerificarSaude($pdo);
confere('mais de 20% de falhas na última hora (com ao menos 20 tentativas): devolve o problema', $txt3 !== null && str_contains($txt3, 'das tentativas de envio falharam'), (string) $txt3);
$pdo->exec("UPDATE push_metricas_hora SET erros_fatais = erros_fatais + 6 WHERE hora = DATE_FORMAT(NOW(), '%Y-%m-%d %H:00:00')");
$txt4 = pushVerificarSaude($pdo);
confere('5 ou mais erros de assinatura/chaves na hora: devolve o problema das chaves', $txt4 !== null && str_contains($txt4, 'erros de assinatura/chaves'), (string) $txt4);
definirConfigSistema('push_ativo', '0');
confere('com os avisos desligados nunca alerta', pushVerificarSaude($pdo) === null);
definirConfigSistema('push_ativo', '1');
$pdo->exec('UPDATE push_inscricoes SET ativo = 0');
confere('sem nenhum aparelho ativo nunca alerta', pushVerificarSaude($pdo) === null);
$pdo->prepare("DELETE FROM atividades WHERE titulo = 'Avisos push com problema' AND criado_em >= ?")->execute([$inicio_s12]);

echo PHP_EOL . "RESUMO: $ok ok, $falhas falhas" . PHP_EOL;
exit($falhas > 0 ? 1 : 0);
