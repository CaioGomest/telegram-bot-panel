#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * ssh-acessos.php (instalado como /usr/local/sbin/painel-ssh-acessos) — auditoria dos logins SSH (Linux) da VPS.
 *
 * Registra QUEM entrou na VPS, de QUE IP, DE ONDE (país/cidade/operadora) e COMO (senha ou chave), e as tentativas que falharam.
 * Não mexe no SSH nem no PAM (não há como travar o seu próprio acesso): só lê o journal do sshd (journalctl).
 *
 *   painel-ssh-acessos                 logins que deram certo nos últimos 30 dias + quem está dentro agora
 *   painel-ssh-acessos relatorio [--dias=N] [--todos]    idem, escolhendo o período (--todos inclui as tentativas que falharam)
 *   painel-ssh-acessos agora           sessões abertas neste momento
 *   painel-ssh-acessos ips [--dias=N]  um resumo por IP (quantos logins, primeiro/último, onde fica)
 *   painel-ssh-acessos falhas [--dias=N]  quem mais tentou entrar sem conseguir (força bruta)
 *   painel-ssh-acessos localizar IP    mostra onde fica um IP (e diagnostica se a consulta de localização não está funcionando)
 *   painel-ssh-acessos testar-alerta   manda uma mensagem de teste pelo Telegram (precisa configurar abaixo)
 *   painel-ssh-acessos seguir          (serviço) acompanha o journal em tempo real — roda sozinho via systemd
 *
 * Configuração opcional em /etc/painel/ssh-acessos.conf (CHAVE=valor, # comenta):
 *   TELEGRAM_BOT_TOKEN=123:ABC...   bot que vai avisar você (crie um no @BotFather)
 *   TELEGRAM_CHAT_ID=123456789      seu chat (mande /start ao bot e veja em https://api.telegram.org/bot<TOKEN>/getUpdates)
 *   ALERTAR=novo_ip                 novo_ip (padrão: avisa quando um IP que nunca entrou com sucesso consegue entrar) | sempre | nunca
 *   IPS_CONHECIDOS=200.1.2.3 2804:14c::1   IPs que você já conhece (não geram aviso, mas continuam registrados)
 *
 * Onde fica: /var/log/painel-ssh/acessos.jsonl (um evento JSON por linha) | /var/lib/painel-ssh (cache de localização, cursor).
 * LIMITE: quem tem root na VPS consegue apagar o registro local. O aviso por Telegram é a cópia que fica FORA da VPS.
 */

if (PHP_SAPI !== 'cli') {
    exit("Somente pela linha de comando.\n");
}
date_default_timezone_set('America/Sao_Paulo');
mb_internal_encoding('UTF-8');

final class SshAcessos
{
    private const GEO_TTL = 2592000;       // 30 dias: a localização de um IP quase não muda
    private const GEO_TTL_ERRO = 3600;     // consulta que falhou: tenta de novo em 1 h
    private const ALERTA_JANELA = 180;     // só avisa de evento "de agora" (não do histórico lido na primeira execução)

    private string $dirLog;
    private string $dirDados;
    private string $arqConf;
    private string $arqLog;
    private string $arqCursor;
    private string $arqGeo;
    private string $arqIpsOk;
    /** @var array<string,string> */
    private array $conf = [];
    /** @var array<string,array<string,mixed>> */
    private array $geo = [];
    /** @var array<string,int> ip => primeiro login com sucesso */
    private array $ipsOk = [];
    /** @var array<string,int> chaves "ip:porta" de tentativas já registradas (evita contar o mesmo erro em duas linhas do sshd) */
    private array $recentes = [];
    /** @var array<string,bool> eventos já gravados (ts|ip|porta|usuario|res) — evita duplicar ao reprocessar o journal */
    private array $gravados = [];
    private bool $geoSujo = false;
    private int $ultimoCursorSalvo = 0;

    public function __construct()
    {
        $this->dirLog = rtrim((string) (getenv('PAINEL_SSH_LOG') ?: '/var/log/painel-ssh'), '/');
        $this->dirDados = rtrim((string) (getenv('PAINEL_SSH_DADOS') ?: '/var/lib/painel-ssh'), '/');
        $this->arqConf = (string) (getenv('PAINEL_SSH_CONF') ?: '/etc/painel/ssh-acessos.conf');
        $this->arqLog = $this->dirLog . '/acessos.jsonl';
        $this->arqCursor = $this->dirDados . '/cursor';
        $this->arqGeo = $this->dirDados . '/geoip.json';
        $this->arqIpsOk = $this->dirDados . '/ips_ok.json';
        $this->carregarConf();
        $this->geo = $this->lerJson($this->arqGeo);
        $this->ipsOk = array_map('intval', $this->lerJson($this->arqIpsOk));
    }

    // ---------------------------------------------------------------------------------------------------------------
    // entrada
    // ---------------------------------------------------------------------------------------------------------------
    public function executar(array $argv): int
    {
        $cmd = $argv[1] ?? 'relatorio';
        $op = [];
        foreach (array_slice($argv, 2) as $a) {
            if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) {
                $op[$m[1]] = $m[2] ?? '1';
            }
        }
        $dias = max(1, min(3650, (int) ($op['dias'] ?? 30)));
        switch ($cmd) {
            case 'seguir':
                return $this->seguir($op['arquivo'] ?? null);
            case 'relatorio':
                return $this->relatorio($dias, isset($op['todos']));
            case 'agora':
                return $this->agora();
            case 'ips':
                return $this->resumoIps($dias);
            case 'falhas':
                return $this->falhas($dias);
            case 'localizar':
                return $this->localizarIp((string) ($argv[2] ?? '8.8.8.8'));
            case 'testar-alerta':
                return $this->testarAlerta();
            case 'ajuda':
            case '-h':
            case '--help':
            case '--ajuda':
                $this->ajuda();
                return 0;
        }
        fwrite(STDERR, "Comando desconhecido: $cmd\n\n");
        $this->ajuda();
        return 2;
    }

    private function ajuda(): void
    {
        $texto = (string) file_get_contents(__FILE__, false, null, 0, 6000);
        if (preg_match('/\/\*\*(.*?)\*\//s', $texto, $m)) {
            echo preg_replace('/^[ ]*\* ?/m', '', trim($m[1])), "\n";
        }
    }

    // ---------------------------------------------------------------------------------------------------------------
    // coleta em tempo real (serviço)
    // ---------------------------------------------------------------------------------------------------------------
    public function seguir(?string $arquivo): int
    {
        $this->garantirPastas();
        $this->carregarGravadosRecentes();

        if ($arquivo !== null) {            // modo de teste: lê entradas do journal (JSON, uma por linha) de um arquivo
            $h = @fopen($arquivo, 'rb');
            if (!$h) {
                fwrite(STDERR, "Não consegui abrir $arquivo\n");
                return 1;
            }
            while (($l = fgets($h)) !== false) {
                $this->processarEntrada($l, false);
            }
            fclose($h);
            $this->salvarGeo();
            return 0;
        }

        $cursor = trim((string) @file_get_contents($this->arqCursor));
        $cmd = ['journalctl', '-f', '-o', 'json', '--no-pager', '-t', 'sshd', '-t', 'sshd-session'];
        if ($cursor !== '') {
            $cmd[] = '--after-cursor=' . $cursor;
        } else {                             // primeira execução: traz o histórico recente que o journal já tem
            array_push($cmd, '--since', '7 days ago');
        }
        $proc = @proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => STDERR], $pipes);
        if (!is_resource($proc)) {
            fwrite(STDERR, "Não consegui iniciar o journalctl.\n");
            return 1;
        }
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            $sair = function () use ($proc): void {
                $this->salvarGeo();
                proc_terminate($proc);
                exit(0);
            };
            pcntl_signal(SIGTERM, $sair);
            pcntl_signal(SIGINT, $sair);
        }
        $inicio = time();
        while (($linha = fgets($pipes[1])) !== false) {
            $this->processarEntrada($linha, true);
        }
        $status = proc_close($proc);
        $this->salvarGeo();
        if ($status !== 0 && $cursor !== '' && time() - $inicio < 10) {
            @unlink($this->arqCursor);       // cursor inválido (journal limpo?): na próxima volta recomeça do histórico recente
            fwrite(STDERR, "journalctl terminou com $status logo após iniciar; descartei o cursor.\n");
        }
        return $status === 0 ? 0 : 1;
    }

    /** Processa uma entrada do journal (JSON). Devolve o evento gravado, ou null se a linha não interessa. */
    public function processarEntrada(string $json, bool $salvarCursor): ?array
    {
        $e = json_decode($json, true);
        if (!is_array($e)) {
            return null;
        }
        $msg = $e['MESSAGE'] ?? '';
        if (is_array($msg)) {                // o journal manda como lista de bytes quando há caracteres fora do UTF-8
            $msg = pack('C*', ...array_map('intval', $msg));
        }
        $ts = isset($e['__REALTIME_TIMESTAMP']) ? intdiv((int) $e['__REALTIME_TIMESTAMP'], 1000000) : time();

        $ev = self::interpretar((string) $msg);
        $registrado = null;
        if ($ev !== null) {
            $registrado = $this->registrar($ev, $ts);
        }
        if ($salvarCursor && isset($e['__CURSOR']) && ($registrado !== null || time() - $this->ultimoCursorSalvo >= 10)) {
            @file_put_contents($this->arqCursor, (string) $e['__CURSOR'], LOCK_EX);
            $this->ultimoCursorSalvo = time();
        }
        return $registrado;
    }

    /** Reconhece as linhas do sshd que importam. Público e estático para poder ser testado sozinho. */
    public static function interpretar(string $msg): ?array
    {
        $msg = trim($msg);
        if (preg_match('/^Accepted (\S+) for (\S+) from (\S+) port (\d+) ssh2(?:: (.+))?$/', $msg, $m)) {
            return ['res' => 'ok', 'user' => $m[2], 'met' => $m[1], 'ip' => $m[3], 'porta' => (int) $m[4], 'chave' => $m[5] ?? ''];
        }
        if (preg_match('/^Failed (\S+) for (invalid user )?(\S+) from (\S+) port (\d+) ssh2/', $msg, $m)) {
            return ['res' => 'falha', 'user' => $m[3], 'met' => $m[1], 'ip' => $m[4], 'porta' => (int) $m[5], 'inexistente' => $m[2] !== ''];
        }
        if (preg_match('/^Invalid user (\S*) from (\S+) port (\d+)/', $msg, $m)) {
            return ['res' => 'falha', 'user' => $m[1], 'met' => 'usuario_inexistente', 'ip' => $m[2], 'porta' => (int) $m[3], 'inexistente' => true];
        }
        return null;
    }

    private function registrar(array $ev, int $ts): ?array
    {
        if (!filter_var($ev['ip'], FILTER_VALIDATE_IP)) {
            return null;
        }
        $ev['user'] = mb_substr(preg_replace('/[^\p{L}\p{N}._@\- ]/u', '?', (string) $ev['user']), 0, 64);   // o nome vem da internet: nunca confiar
        $ev['chave'] = mb_substr((string) ($ev['chave'] ?? ''), 0, 120);

        $chaveTentativa = $ev['ip'] . ':' . $ev['porta'];
        if ($ev['res'] === 'falha') {
            // a 1ª tentativa com usuário inexistente gera 2 linhas ("Invalid user" e "Failed password for invalid user"): conta uma só
            if ($ev['met'] === 'usuario_inexistente') {
                $this->recentes[$chaveTentativa] = $ts;
                if (count($this->recentes) > 2000) {
                    $this->recentes = array_slice($this->recentes, -500, null, true);
                }
            } elseif (!empty($ev['inexistente']) && isset($this->recentes[$chaveTentativa])) {
                unset($this->recentes[$chaveTentativa]);
                return null;
            }
        }
        $dedupe = $ts . '|' . $chaveTentativa . '|' . $ev['user'] . '|' . $ev['res'];
        if (isset($this->gravados[$dedupe])) {
            return null;
        }
        $this->gravados[$dedupe] = true;
        if (count($this->gravados) > 20000) {
            $this->gravados = array_slice($this->gravados, -5000, null, true);
        }

        $registro = ['t' => date('c', $ts), 'ts' => $ts] + $ev;
        $novo = false;
        if ($ev['res'] === 'ok') {
            $novo = !isset($this->ipsOk[$ev['ip']]);
            if ($novo) {
                $this->ipsOk[$ev['ip']] = $ts;
                $this->salvarJson($this->arqIpsOk, $this->ipsOk);
            }
            $loc = $this->localizar($ev['ip']);
            $registro += ['pais' => $loc['pais'] ?? '', 'pais_nome' => $loc['pais_nome'] ?? '', 'cidade' => $loc['cidade'] ?? '', 'regiao' => $loc['regiao'] ?? '', 'isp' => $loc['isp'] ?? '', 'novo_ip' => $novo];
            $this->salvarGeo();
        }
        $linha = json_encode($registro, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $this->garantirPastas();
        @file_put_contents($this->arqLog, $linha . "\n", FILE_APPEND | LOCK_EX);

        if ($ev['res'] === 'ok' && time() - $ts <= self::ALERTA_JANELA) {
            $this->alertar($registro, $novo);
        }
        return $registro;
    }

    // ---------------------------------------------------------------------------------------------------------------
    // localização do IP (cache em disco; só IPs públicos)
    // ---------------------------------------------------------------------------------------------------------------
    /** @return array<string,mixed> pais, pais_nome, regiao, cidade, isp, rotulo (vazio se não achou) */
    public function localizar(string $ip, bool $consultar = true): array
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return ['pais' => '', 'cidade' => '', 'regiao' => '', 'isp' => '', 'rotulo' => 'rede local / reservado'];
        }
        $c = $this->geo[$ip] ?? null;
        $ttl = ($c && isset($c['erro'])) ? self::GEO_TTL_ERRO : self::GEO_TTL;
        if ($c && time() - (int) ($c['em'] ?? 0) < $ttl) {
            return $c;
        }
        if (!$consultar) {
            return $c ?? ['rotulo' => ''];
        }
        $r = $this->consultarGeo($ip);
        $this->geo[$ip] = $r;
        $this->geoSujo = true;
        return $r;
    }

    private function consultarGeo(string $ip): array
    {
        $base = (string) ($this->conf['GEOIP_URL'] ?? 'https://ipwho.is');
        $erros = [];
        $d = $this->http($base . '/' . rawurlencode($ip) . '?lang=pt-BR&fields=success,message,country,country_code,region,region_code,city,connection');
        $erros[] = $this->erroHttp !== '' ? $this->erroHttp : (is_array($d) ? (string) ($d['message'] ?? 'resposta sem sucesso') : 'sem resposta');
        if (is_array($d) && ($d['success'] ?? false) === true) {
            $con = is_array($d['connection'] ?? null) ? $d['connection'] : [];
            return $this->montarGeo((string) ($d['country_code'] ?? ''), (string) ($d['country'] ?? ''), (string) ($d['region_code'] ?? $d['region'] ?? ''), (string) ($d['city'] ?? ''), (string) ($con['isp'] ?? $con['org'] ?? ''));
        }
        if (!isset($this->conf['GEOIP_URL'])) {   // reserva (só quando o provedor padrão falhou)
            $d = $this->http('https://ipinfo.io/' . rawurlencode($ip) . '/json');
            if (is_array($d) && empty($d['error']) && !empty($d['country'])) {
                $cc = (string) $d['country'];
                $nome = class_exists('Locale') ? (string) Locale::getDisplayRegion('-' . $cc, 'pt_BR') : '';
                return $this->montarGeo($cc, $nome !== '' && $nome !== $cc ? $nome : '', (string) ($d['region'] ?? ''), (string) ($d['city'] ?? ''), preg_replace('/^ASd+s+/', '', (string) ($d['org'] ?? '')));
            }
            $erros[] = $this->erroHttp !== '' ? $this->erroHttp : 'sem resposta';
        }
        $this->erroGeo = implode(' | ', $erros);
        return ['erro' => true, 'em' => time(), 'pais' => '', 'cidade' => '', 'regiao' => '', 'isp' => '', 'rotulo' => 'localização indisponível'];
    }

    private function montarGeo(string $cc, string $pais, string $regiao, string $cidade, string $isp): array
    {
        $lugar = trim($cidade . ($regiao !== '' ? ($cidade !== '' ? '/' : '') . $regiao : ''), '/ ');
        $partes = array_filter([$lugar, $pais !== '' ? $pais : $cc]);
        $rotulo = implode(', ', $partes);
        if ($isp !== '') {
            $rotulo .= ($rotulo !== '' ? ' — ' : '') . $isp;
        }
        return ['em' => time(), 'pais' => $cc, 'pais_nome' => $pais, 'regiao' => $regiao, 'cidade' => $cidade, 'isp' => $isp, 'rotulo' => $rotulo];
    }

    private string $erroHttp = '';
    private string $erroGeo = '';

    private function http(string $url, ?array $post = null): ?array
    {
        $this->erroHttp = '';
        $ch = curl_init($url);
        $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_FOLLOWLOCATION => false,
              CURLOPT_USERAGENT => 'painel-ssh-acessos/1.0', CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS];
        if ($post !== null) {
            $o[CURLOPT_POST] = true;
            $o[CURLOPT_POSTFIELDS] = http_build_query($post);
        }
        curl_setopt_array($ch, $o);
        $r = curl_exec($ch);
        if (!is_string($r)) {
            $this->erroHttp = curl_error($ch);
        }
        if (!is_string($r)) {
            return null;
        }
        $j = json_decode($r, true);
        return is_array($j) ? $j : null;
    }

    // ---------------------------------------------------------------------------------------------------------------
    // aviso por Telegram (cópia fora da VPS)
    // ---------------------------------------------------------------------------------------------------------------
    private function alertar(array $ev, bool $novo): void
    {
        $modo = strtolower($this->conf['ALERTAR'] ?? 'novo_ip');
        if ($modo === 'nunca' || in_array($ev['ip'], ['127.0.0.1', '::1'], true)) {
            return;
        }
        $conhecidos = preg_split('/[\s,]+/', (string) ($this->conf['IPS_CONHECIDOS'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (in_array($ev['ip'], $conhecidos, true)) {
            return;
        }
        if ($modo !== 'sempre' && !$novo) {
            return;
        }
        $loc = $this->localizar($ev['ip'], false);
        $texto = ($novo ? "⚠️ Login SSH de IP NOVO" : "🔐 Login SSH") . " na VPS " . gethostname() . "\n"
            . "Usuário: {$ev['user']} ({$ev['met']})\n"
            . "IP: {$ev['ip']}\n"
            . "Local: " . (($loc['rotulo'] ?? '') !== '' ? $loc['rotulo'] : 'desconhecido') . "\n"
            . "Quando: " . date('d/m/Y H:i:s', (int) $ev['ts']);
        $this->enviarTelegram($texto);
    }

    private function enviarTelegram(string $texto): ?string
    {
        $token = trim((string) ($this->conf['TELEGRAM_BOT_TOKEN'] ?? ''));
        $chat = trim((string) ($this->conf['TELEGRAM_CHAT_ID'] ?? ''));
        if ($token === '' || $chat === '') {
            return 'TELEGRAM_BOT_TOKEN/TELEGRAM_CHAT_ID não configurados em ' . $this->arqConf;
        }
        $api = rtrim((string) ($this->conf['TELEGRAM_API_URL'] ?? 'https://api.telegram.org'), '/');
        $r = $this->http($api . '/bot' . $token . '/sendMessage', ['chat_id' => $chat, 'text' => $texto]);
        if (is_array($r) && ($r['ok'] ?? false) === true) {
            return null;
        }
        return is_array($r) ? (string) ($r['description'] ?? 'resposta inesperada do Telegram') : 'sem resposta do Telegram (rede?)';
    }

    private function localizarIp(string $ip): int
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            fwrite(STDERR, "IP inválido: $ip
");
            return 2;
        }
        unset($this->geo[$ip]);
        $r = $this->localizar($ip);
        if (!empty($r['erro'])) {
            fwrite(STDERR, "Não consegui localizar $ip" . ($this->erroGeo !== '' ? ': ' . $this->erroGeo : '') . "
");
            return 1;
        }
        echo "$ip → " . ($r['rotulo'] !== '' ? $r['rotulo'] : '(sem dados)') . "
";
        $this->salvarGeo();
        return 0;
    }

    private function testarAlerta(): int
    {
        $erro = $this->enviarTelegram("✅ Teste do painel-ssh-acessos na VPS " . gethostname() . " — " . date('d/m/Y H:i:s'));
        if ($erro === null) {
            echo "Mensagem enviada. Confira o Telegram.\n";
            return 0;
        }
        fwrite(STDERR, "Falhou: $erro\n");
        return 1;
    }

    // ---------------------------------------------------------------------------------------------------------------
    // relatórios
    // ---------------------------------------------------------------------------------------------------------------
    /** Lê os eventos dos últimos N dias (arquivo atual e o rotacionado) e entrega um a um — sem carregar tudo na memória. */
    private function varrer(int $dias, callable $fn): void
    {
        $desde = time() - $dias * 86400;
        foreach ([$this->arqLog . '.1', $this->arqLog] as $arq) {
            $h = @fopen($arq, 'rb');
            if (!$h) {
                continue;
            }
            while (($l = fgets($h)) !== false) {
                $e = json_decode($l, true);
                if (is_array($e) && ($e['ts'] ?? 0) >= $desde) {
                    $fn($e);
                }
            }
            fclose($h);
        }
    }

    private function relatorio(int $dias, bool $todos): int
    {
        $this->agora();
        echo "\n", $this->negrito("LOGINS NA VPS — últimos {$dias} dias" . ($todos ? ' (inclui falhas)' : '')), "\n";
        $linhas = [];
        $falhas = 0;
        $ipsFalha = [];
        $this->varrer($dias, function (array $e) use (&$linhas, &$falhas, &$ipsFalha, $todos): void {
            if (($e['res'] ?? '') === 'ok' || $todos) {
                $linhas[] = $e;
            }
            if (($e['res'] ?? '') === 'falha') {
                $falhas++;
                $ipsFalha[$e['ip']] = true;
            }
        });
        if (!$linhas) {
            echo "  (nenhum registro no período — o serviço 'painel-ssh-acessos' está ativo? systemctl status painel-ssh-acessos)\n";
        } else {
            $this->tabela(['QUANDO', 'RESULTADO', 'USUÁRIO', 'MÉTODO', 'IP', 'LOCAL'], array_map(function (array $e): array {
                $loc = ($e['res'] ?? '') === 'ok' ? $this->rotuloDoEvento($e) : ($this->localizar((string) $e['ip'], false)['rotulo'] ?? '');
                return [date('d/m/Y H:i', (int) $e['ts']), ($e['res'] ?? '') === 'ok' ? 'entrou' : 'FALHOU', (string) $e['user'], (string) ($e['met'] ?? ''),
                        (string) $e['ip'], $loc . (!empty($e['novo_ip']) ? '  [IP NOVO]' : '')];
            }, $linhas));
        }
        if (!$todos && $falhas > 0) {
            echo "\n  + {$falhas} tentativa(s) que falharam, vindas de " . count($ipsFalha) . " IP(s) — veja:  painel-ssh-acessos falhas\n";
        }
        echo "\n";
        return 0;
    }

    private function rotuloDoEvento(array $e): string
    {
        $r = trim(implode(', ', array_filter([trim(($e['cidade'] ?? '') . (($e['regiao'] ?? '') !== '' ? '/' . $e['regiao'] : ''), '/'), ($e['pais_nome'] ?? '') !== '' ? $e['pais_nome'] : ($e['pais'] ?? '')])));
        if (($e['isp'] ?? '') !== '') {
            $r .= ($r !== '' ? ' — ' : '') . $e['isp'];
        }
        return $r !== '' ? $r : ($this->localizar((string) $e['ip'], false)['rotulo'] ?? '');
    }

    private function agora(): int
    {
        echo $this->negrito("SESSÕES ABERTAS AGORA"), "\n";
        $saida = [];
        @exec('who -u 2>/dev/null', $saida);
        $linhas = [];
        foreach ($saida as $s) {
            if (!preg_match('/^(\S+)\s+(\S+)\s+(\d{4}-\d{2}-\d{2} \d{2}:\d{2})\s.*?(?:\(([^)]+)\))?\s*$/', $s, $m)) {
                continue;
            }
            $ip = $m[4] ?? '';
            $linhas[] = [$m[1], $m[2], $m[3], $ip !== '' ? $ip : '(console local)', $ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) ? ($this->localizar($ip)['rotulo'] ?? '') : ''];
        }
        $this->salvarGeo();
        if (!$linhas) {
            echo "  (ninguém conectado agora)\n";
        } else {
            $this->tabela(['USUÁRIO', 'TERMINAL', 'DESDE', 'IP', 'LOCAL'], $linhas);
        }
        return 0;
    }

    private function resumoIps(int $dias): int
    {
        $ips = [];
        $this->varrer($dias, function (array $e) use (&$ips): void {
            $ip = (string) $e['ip'];
            $ips[$ip] ??= ['ok' => 0, 'falha' => 0, 'primeiro' => (int) $e['ts'], 'ultimo' => (int) $e['ts'], 'usuarios' => []];
            $ips[$ip][($e['res'] ?? '') === 'ok' ? 'ok' : 'falha']++;
            $ips[$ip]['ultimo'] = max($ips[$ip]['ultimo'], (int) $e['ts']);
            $ips[$ip]['primeiro'] = min($ips[$ip]['primeiro'], (int) $e['ts']);
            if (($e['res'] ?? '') === 'ok') {
                $ips[$ip]['usuarios'][(string) $e['user']] = true;
            }
        });
        $ips = array_filter($ips, fn(array $i): bool => $i['ok'] > 0);
        uasort($ips, fn($a, $b) => $b['ultimo'] <=> $a['ultimo']);
        echo "\n", $this->negrito("IPs QUE ENTRARAM NA VPS — últimos {$dias} dias"), "\n";
        if (!$ips) {
            echo "  (nenhum)\n\n";
            return 0;
        }
        $linhas = [];
        foreach ($ips as $ip => $i) {
            $linhas[] = [$ip, (string) $i['ok'], date('d/m/Y H:i', $i['primeiro']), date('d/m/Y H:i', $i['ultimo']), implode(',', array_keys($i['usuarios'])), $this->localizar($ip)['rotulo'] ?? ''];
        }
        $this->salvarGeo();
        $this->tabela(['IP', 'LOGINS', 'PRIMEIRO', 'ÚLTIMO', 'USUÁRIOS', 'LOCAL'], $linhas);
        echo "\n";
        return 0;
    }

    private function falhas(int $dias): int
    {
        $ips = [];
        $total = 0;
        $this->varrer($dias, function (array $e) use (&$ips, &$total): void {
            if (($e['res'] ?? '') !== 'falha') {
                return;
            }
            $total++;
            $ip = (string) $e['ip'];
            $ips[$ip] ??= ['n' => 0, 'ultimo' => 0, 'usuarios' => []];
            $ips[$ip]['n']++;
            $ips[$ip]['ultimo'] = max($ips[$ip]['ultimo'], (int) $e['ts']);
            $u = (string) $e['user'];
            $ips[$ip]['usuarios'][$u] = ($ips[$ip]['usuarios'][$u] ?? 0) + 1;
        });
        echo "\n", $this->negrito("TENTATIVAS DE LOGIN QUE FALHARAM — últimos {$dias} dias"), "\n";
        if (!$ips) {
            echo "  (nenhuma)\n\n";
            return 0;
        }
        uasort($ips, fn($a, $b) => $b['n'] <=> $a['n']);
        $linhas = [];
        $consultas = 0;
        foreach (array_slice($ips, 0, 20, true) as $ip => $i) {
            arsort($i['usuarios']);
            $loc = $this->localizar($ip, $consultas < 20)['rotulo'] ?? '';
            $consultas++;
            $linhas[] = [$ip, (string) $i['n'], date('d/m/Y H:i', $i['ultimo']), implode(',', array_slice(array_keys($i['usuarios']), 0, 3)), $loc];
        }
        $this->salvarGeo();
        $this->tabela(['IP', 'TENTATIVAS', 'ÚLTIMA', 'USUÁRIOS TESTADOS', 'LOCAL'], $linhas);
        echo "\n  Total: {$total} tentativa(s) de " . count($ips) . " IP(s). O fail2ban bane os insistentes (fail2ban-client status sshd).\n\n";
        return 0;
    }

    // ---------------------------------------------------------------------------------------------------------------
    // utilidades
    // ---------------------------------------------------------------------------------------------------------------
    private function tabela(array $cab, array $linhas): void
    {
        $larg = array_map('mb_strlen', $cab);
        foreach ($linhas as $l) {
            foreach ($l as $i => $c) {
                $larg[$i] = max($larg[$i], min(mb_strlen((string) $c), $i === count($cab) - 1 ? 70 : 40));
            }
        }
        $fmt = function (array $cols) use ($larg): string {
            $out = [];
            foreach ($cols as $i => $c) {
                $c = (string) $c;
                if (mb_strlen($c) > $larg[$i]) {
                    $c = mb_substr($c, 0, $larg[$i] - 1) . '…';
                }
                $out[] = $c . str_repeat(' ', $larg[$i] - mb_strlen($c));
            }
            return '  ' . rtrim(implode('  ', $out));
        };
        echo $this->negrito($fmt($cab)), "\n";
        foreach ($linhas as $l) {
            echo $fmt($l), "\n";
        }
    }

    private function negrito(string $s): string
    {
        return (function_exists('stream_isatty') && @stream_isatty(STDOUT)) ? "\033[1m{$s}\033[0m" : $s;
    }

    private function carregarConf(): void
    {
        $txt = @file_get_contents($this->arqConf);
        if ($txt === false) {
            return;
        }
        foreach (preg_split('/\R/', $txt) ?: [] as $l) {
            if (preg_match('/^\s*([A-Z_]+)\s*=\s*(.*?)\s*$/', $l, $m) && $m[1] !== '') {
                $this->conf[$m[1]] = trim($m[2], " \t\"'");
            }
        }
    }

    private function garantirPastas(): void
    {
        foreach ([$this->dirLog, $this->dirDados] as $d) {
            if (!is_dir($d)) {
                @mkdir($d, 0750, true);
            }
        }
    }

    private function carregarGravadosRecentes(): void
    {
        $h = @fopen($this->arqLog, 'rb');
        if (!$h) {
            return;
        }
        $tam = (int) fstat($h)['size'];
        fseek($h, max(0, $tam - 262144));
        while (($l = fgets($h)) !== false) {
            $e = json_decode($l, true);
            if (is_array($e) && isset($e['ts'], $e['ip'], $e['porta'], $e['user'], $e['res'])) {
                $this->gravados[$e['ts'] . '|' . $e['ip'] . ':' . $e['porta'] . '|' . $e['user'] . '|' . $e['res']] = true;
            }
        }
        fclose($h);
    }

    /** @return array<string,mixed> */
    private function lerJson(string $arq): array
    {
        $j = json_decode((string) @file_get_contents($arq), true);
        return is_array($j) ? $j : [];
    }

    private function salvarJson(string $arq, array $dados): void
    {
        $this->garantirPastas();
        $tmp = $arq . '.tmp';
        if (@file_put_contents($tmp, json_encode($dados, JSON_UNESCAPED_UNICODE), LOCK_EX) !== false) {
            @rename($tmp, $arq);
        }
    }

    private function salvarGeo(): void
    {
        if (!$this->geoSujo) {
            return;
        }
        if (count($this->geo) > 5000) {      // cache de IPs: mantém os mais recentes
            uasort($this->geo, fn($a, $b) => ($b['em'] ?? 0) <=> ($a['em'] ?? 0));
            $this->geo = array_slice($this->geo, 0, 3000, true);
        }
        $this->salvarJson($this->arqGeo, $this->geo);
        $this->geoSujo = false;
    }
}

exit((new SshAcessos())->executar($argv));
