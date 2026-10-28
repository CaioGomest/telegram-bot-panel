<?php
declare(strict_types=1);

/**
 * Modo manutenção: tira o sistema do ar "com educação" quando for preciso mexer nele (deploy, migração do banco, banco fora do ar).
 * Quem abre o painel vê uma página "Em manutenção" (HTTP 503 + Retry-After, que atualiza sozinha) em vez de erro, e o que fala com o
 * mundo de fora se comporta conforme o modo. Quem liga e desliga é o administrador (Admin > Configurações > Manutenção) ou quem tem SSH
 * (php deploy/manutencao.php ligar|desligar|status).
 *
 * O estado mora num ARQUIVO (storage/manutencao.json; sem o arquivo = desligada), não no banco: a guarda roda cedo em conexao.php, antes
 * de abrir o banco, e por isso vale até com o banco fora do ar ou no meio de uma migração. Defeito no arquivo (ilegível, JSON quebrado)
 * = DESLIGADA: a manutenção nunca fica ligada por engano.
 *
 *   modo "painel"  só o painel, a API e as telas mostram a manutenção. Os bots, os pagamentos e os links /l/ seguem funcionando
 *                  (webhook.php, webhook_omegapayments.php, l.php) e os crons rodam. É o modo de uma atualização visual.
 *   modo "total"   além do painel: o webhook do Telegram e o da gateway respondem 503 (o Telegram e a gateway tentam de novo depois, e o
 *                  cron_verificar_pix confere os pagamentos pendentes quando voltar), os links /l/ mostram a página e TODOS os crons
 *                  pausam. É o modo de uma migração de banco. Atenção: parar os crons por muito tempo acumula Pix expirados, que
 *                  disparam o fluxo "não pago" todos de uma vez quando religam (ver anotacoes/OBSERVACOES.md, laço de Pix).
 *
 * Liberação: com a manutenção ligada, o administrador que a ligou continua enxergando o sistema normal (cookie). Para outros navegadores
 * ou pessoas, existe o link "/?liberar=SEGREDO" (o segredo muda toda vez que a manutenção é ligada; o cookie vale 12 h e só para essa
 * manutenção). A porta /controle (suspensão remota) nunca é fechada. Scripts pela linha de comando passam sempre.
 */

const MANUTENCAO_COOKIE = 'manutencao_liberada';
const MANUTENCAO_COOKIE_HORAS = 12;
const MANUTENCAO_MENSAGEM_MAX = 300;
const MANUTENCAO_VOLTA_MAX_MINUTOS = 10080;   // 7 dias

/** Onde o estado mora. MANUTENCAO_ARQUIVO (constante) ou PAINEL_MANUTENCAO_ARQUIVO (variável de ambiente) existem só para os testes. */
function manutencaoArquivo(): string
{
    if (defined('MANUTENCAO_ARQUIVO')) {
        return (string) MANUTENCAO_ARQUIVO;
    }
    $env = getenv('PAINEL_MANUTENCAO_ARQUIVO');
    if (is_string($env) && $env !== '') {
        return $env;
    }
    return dirname(__DIR__) . '/storage/manutencao.json';
}

function manutencaoEstadoDesligada(): array
{
    return ['ativo' => false, 'modo' => 'painel', 'mensagem' => '', 'volta_em' => 0, 'desde' => 0, 'segredo' => '', 'por' => ''];
}

/**
 * Estado que vale agora. Sem arquivo, arquivo ilegível ou "ativo" falso = desligada.
 * @return array{ativo:bool, modo:string, mensagem:string, volta_em:int, desde:int, segredo:string, por:string}
 */
function manutencaoLer(bool $recarregar = false): array
{
    static $cache = null;
    if ($cache !== null && !$recarregar) {
        return $cache;
    }
    $arq = manutencaoArquivo();
    if (!is_file($arq)) {
        return $cache = manutencaoEstadoDesligada();
    }
    $j = json_decode((string) @file_get_contents($arq), true);
    if (!is_array($j) || empty($j['ativo'])) {
        return $cache = manutencaoEstadoDesligada();
    }
    return $cache = [
        'ativo' => true,
        'modo' => ($j['modo'] ?? '') === 'total' ? 'total' : 'painel',
        'mensagem' => mb_substr(trim((string) ($j['mensagem'] ?? '')), 0, MANUTENCAO_MENSAGEM_MAX),
        'volta_em' => max(0, (int) ($j['volta_em'] ?? 0)),
        'desde' => max(0, (int) ($j['desde'] ?? 0)),
        'segredo' => (string) ($j['segredo'] ?? ''),
        'por' => mb_substr((string) ($j['por'] ?? ''), 0, 80),
    ];
}

function manutencaoAtiva(): bool
{
    return manutencaoLer()['ativo'];
}

function manutencaoModoTotal(): bool
{
    $e = manutencaoLer();
    return $e['ativo'] && $e['modo'] === 'total';
}

function manutencaoLog(string $mensagem): void
{
    $arq = dirname(__DIR__) . '/logs/manutencao.log';
    if (!is_dir(dirname($arq))) {
        return;
    }
    $ip = preg_replace('/[^0-9a-fA-F:.]/', '', (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli')) ?: 'cli';
    @file_put_contents($arq, '[' . date('Y-m-d H:i:s') . "] ip=$ip " . preg_replace('/[\r\n]+/', ' ', $mensagem) . "\n", FILE_APPEND | LOCK_EX);
}

/**
 * Liga (ou atualiza, se já estiver ligada: o segredo e o "desde" são mantidos, então quem já está liberado continua).
 * @param int $volta_em_minutos  previsão de volta, a partir de agora; 0 = sem previsão. É só informativa: a manutenção não desliga sozinha.
 * @throws RuntimeException quando o estado não pode ser gravado
 */
function manutencaoLigar(string $mensagem, string $modo, int $volta_em_minutos, string $por): array
{
    $anterior = manutencaoLer(true);
    $minutos = max(0, min(MANUTENCAO_VOLTA_MAX_MINUTOS, $volta_em_minutos));
    $estado = [
        'ativo' => true,
        'modo' => $modo === 'total' ? 'total' : 'painel',
        'mensagem' => mb_substr(trim($mensagem), 0, MANUTENCAO_MENSAGEM_MAX),
        'volta_em' => $minutos > 0 ? time() + $minutos * 60 : 0,
        'desde' => $anterior['ativo'] ? $anterior['desde'] : time(),
        'segredo' => ($anterior['ativo'] && $anterior['segredo'] !== '') ? $anterior['segredo'] : bin2hex(random_bytes(16)),
        'por' => mb_substr($por, 0, 80),
    ];
    $arq = manutencaoArquivo();
    if (!is_dir(dirname($arq)) && !@mkdir(dirname($arq), 0775, true)) {
        throw new RuntimeException('Não consegui criar a pasta do estado da manutenção.');
    }
    $tmp = $arq . '.tmp' . bin2hex(random_bytes(3));
    if (@file_put_contents($tmp, json_encode($estado, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX) === false) {
        throw new RuntimeException('Não consegui gravar o estado da manutenção (a pasta storage/ precisa aceitar escrita).');
    }
    // Ligada pelo SSH como root, o arquivo nasceria de root e o PHP do site (outro usuário) não conseguiria ler: a manutenção pareceria desligada.
    // Por isso leitura liberada (0664) e, sendo root, o dono e o grupo da pasta storage/.
    @chmod($tmp, 0664);
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        @chown($tmp, (int) @fileowner(dirname($arq)));
        @chgrp($tmp, (int) @filegroup(dirname($arq)));
    }
    if (!@rename($tmp, $arq)) {
        @unlink($tmp);
        throw new RuntimeException('Não consegui gravar o estado da manutenção.');
    }
    manutencaoLog(($anterior['ativo'] ? 'ATUALIZADA' : 'LIGADA') . ': modo=' . $estado['modo'] . ' volta_em=' . $estado['volta_em'] . ' por=' . $por . ($estado['mensagem'] !== '' ? ' mensagem="' . $estado['mensagem'] . '"' : ''));
    return manutencaoLer(true);
}

/** Desliga (apaga o arquivo). Devolve se estava ligada. */
function manutencaoDesligar(string $por): bool
{
    $estava = manutencaoLer(true)['ativo'];
    $arq = manutencaoArquivo();
    if (is_file($arq) && !@unlink($arq)) {
        throw new RuntimeException('Não consegui apagar o estado da manutenção (' . $arq . ').');
    }
    manutencaoLer(true);
    if ($estava) {
        manutencaoLog('DESLIGADA por=' . $por);
    }
    return $estava;
}

// ===== liberação (cookie / link) =====

/** O que vai no cookie: derivado do segredo, não o segredo em si. Vazio = sem liberação possível. */
function manutencaoCookieValor(array $e): string
{
    return ($e['segredo'] ?? '') === '' ? '' : hash('sha256', 'manutencao|' . $e['segredo']);
}

function manutencaoLiberado(array $e, array $cookies): bool
{
    $esperado = manutencaoCookieValor($e);
    $recebido = $cookies[MANUTENCAO_COOKIE] ?? null;
    return $e['ativo'] && $esperado !== '' && is_string($recebido) && hash_equals($esperado, $recebido);
}

function manutencaoSegredoConfere(array $e, string $dado): bool
{
    return $e['ativo'] && $e['segredo'] !== '' && $dado !== '' && hash_equals($e['segredo'], $dado);
}

/** Deixa ESTE navegador enxergar o sistema normal durante a manutenção (12 h). */
function manutencaoLiberarNavegador(array $e): void
{
    $valor = manutencaoCookieValor($e);
    if ($valor === '' || PHP_SAPI === 'cli') {
        return;
    }
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    setcookie(MANUTENCAO_COOKIE, $valor, ['expires' => time() + MANUTENCAO_COOKIE_HORAS * 3600, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
    $_COOKIE[MANUTENCAO_COOKIE] = $valor;
}

// ===== decisão e resposta =====

/**
 * Quem passa e quem vê a manutenção. Pura (sem ler nada do ambiente), por isso testável.
 * @param string $rel  script pedido, relativo à raiz do projeto ("webhook.php", "admin/dashboard.php", "")
 * @return string 'passar' | 'pagina' (HTML) | 'json' | 'texto' (503 sem página: webhooks, que as máquinas do Telegram e da gateway entendem)
 */
function manutencaoDecidir(array $e, string $rel, bool $liberado, bool $quer_json): string
{
    if (!$e['ativo'] || $liberado || $rel === 'controle.php') {
        return 'passar';
    }
    if ($e['modo'] === 'painel' && in_array($rel, ['webhook.php', 'webhook_omegapayments.php', 'l.php'], true)) {
        return 'passar';
    }
    if (in_array($rel, ['webhook.php', 'webhook_omegapayments.php'], true)) {
        return 'texto';
    }
    return $quer_json ? 'json' : 'pagina';
}

function manutencaoQuerJson(string $rel, array $servidor): bool
{
    return $rel === 'api.php' || str_starts_with($rel, 'ajax/')
        || str_contains((string) ($servidor['HTTP_ACCEPT'] ?? ''), 'application/json')
        || strtolower((string) ($servidor['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
}

/** Segundos que o Retry-After manda esperar: até a previsão (entre 30 s e 24 h) ou 5 min quando não há previsão (ou ela já passou). */
function manutencaoRetryAfter(array $e, int $agora): int
{
    return $e['volta_em'] > $agora ? max(30, min(86400, $e['volta_em'] - $agora)) : 300;
}

/** "hoje às 15:30" ou "24/10 às 08:00" (no fuso do PHP, que o conexao.php já deixou em America/Sao_Paulo). */
function manutencaoQuando(int $momento, int $agora): string
{
    return date('Y-m-d', $momento) === date('Y-m-d', $agora) ? 'hoje às ' . date('H:i', $momento) : date('d/m \à\s H:i', $momento);
}

/** A página. Sem banco, sem arquivo externo: tudo inline (a manutenção pode ser exatamente porque algo quebrou). */
function manutencaoPagina(array $e, int $agora): string
{
    $texto = $e['mensagem'] !== '' ? $e['mensagem'] : 'Estamos fazendo uma atualização rápida para deixar tudo ainda melhor. Já voltamos!';
    $texto = nl2br(htmlspecialchars($texto, ENT_QUOTES, 'UTF-8'), false);
    if ($e['volta_em'] > $agora) {
        $previsao = 'Previsão de volta: <strong>' . htmlspecialchars(manutencaoQuando($e['volta_em'], $agora), ENT_QUOTES, 'UTF-8') . '</strong>';
    } elseif ($e['volta_em'] > 0) {
        $previsao = 'Estamos finalizando os últimos ajustes.';
    } else {
        $previsao = '';
    }
    $previsao_html = $previsao !== '' ? '<p class="previsao">' . $previsao . '</p>' : '';
    return '<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<meta name="robots" content="noindex, nofollow">
<meta http-equiv="refresh" content="30">
<title>Em manutenção</title>
<style>
:root { --bg:#0b0d12; --card:#14171f; --bd:rgba(255,255,255,.1); --t:#f3f4f6; --m:#9aa2b1; --or:#ff7a2f; --orsoft:rgba(255,122,47,.14); }
@media (prefers-color-scheme: light) { :root { --bg:#f4f5f8; --card:#ffffff; --bd:rgba(15,20,30,.12); --t:#14171f; --m:#5b6474; --or:#e5601a; --orsoft:rgba(229,96,26,.12); } }
* { box-sizing: border-box; }
html, body { margin: 0; min-height: 100%; }
body { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; background: var(--bg); color: var(--t); font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; -webkit-font-smoothing: antialiased; }
.cartao { width: 100%; max-width: 460px; padding: 40px 32px 32px; text-align: center; background: var(--card); border: 1px solid var(--bd); border-radius: 24px; box-shadow: 0 30px 70px -40px rgba(0,0,0,.7); }
.icone { width: 72px; height: 72px; margin: 0 auto 22px; display: grid; place-items: center; border-radius: 22px; background: var(--orsoft); color: var(--or); }
.icone svg { width: 34px; height: 34px; }
h1 { margin: 0 0 12px; font-size: 26px; line-height: 1.15; letter-spacing: -.01em; }
.msg { margin: 0; color: var(--m); font-size: 15.5px; line-height: 1.6; overflow-wrap: anywhere; }
.previsao { margin: 18px 0 0; padding: 10px 14px; display: inline-block; border-radius: 12px; background: var(--orsoft); color: var(--t); font-size: 14.5px; }
.previsao strong { color: var(--or); }
.auto { margin: 26px 0 0; display: flex; align-items: center; justify-content: center; gap: 9px; color: var(--m); font-size: 13px; }
.ponto { width: 8px; height: 8px; border-radius: 50%; background: var(--or); animation: pulsa 1.6s ease-in-out infinite; }
@keyframes pulsa { 0%, 100% { opacity: .35; transform: scale(.85); } 50% { opacity: 1; transform: scale(1.15); } }
@media (prefers-reduced-motion: reduce) { .ponto { animation: none; } }
a.botao { margin-top: 18px; display: inline-flex; align-items: center; justify-content: center; min-height: 44px; padding: 0 22px; border-radius: 14px; background: var(--or); color: #1b0d04; font-weight: 700; font-size: 14.5px; text-decoration: none; }
@media (max-width: 480px) { .cartao { padding: 32px 22px 26px; border-radius: 20px; } h1 { font-size: 23px; } }
</style>
</head>
<body>
<main class="cartao">
<div class="icone" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18l3 3 6.3-6.3a4 4 0 0 0 5.4-5.4l-2.6 2.6-2.5-.6-.6-2.5z"/></svg></div>
<h1>Em manutenção</h1>
<p class="msg">' . $texto . '</p>
' . $previsao_html . '
<div class="auto"><span class="ponto" aria-hidden="true"></span><span>Esta página confere sozinha se já voltamos.</span></div>
<a class="botao" href="">Tentar de novo</a>
</main>
</body>
</html>';
}

/** Script pedido, relativo à raiz do projeto ("" se não der para saber). */
function manutencaoCaminhoRelativo(): string
{
    $raiz = (string) realpath(dirname(__DIR__));
    $script = (string) realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
    return ($raiz !== '' && str_starts_with($script, $raiz . DIRECTORY_SEPARATOR)) ? str_replace('\\', '/', substr($script, strlen($raiz) + 1)) : '';
}

/** O endereço pedido sem o ?liberar=... (só caminho + consulta, nunca outro site: começa com uma única "/"). */
function manutencaoUrlSemLiberar(string $request_uri): string
{
    $partes = parse_url($request_uri);
    // barra invertida vira "/" (o navegador trata "/\site.com" como "//site.com") e as barras do começo viram uma só: nunca sai do próprio site
    $caminho = '/' . ltrim(str_replace(chr(92), '/', (string) ($partes['path'] ?? '/')), '/');
    $consulta = [];
    parse_str((string) ($partes['query'] ?? ''), $consulta);
    unset($consulta['liberar']);
    return $caminho . ($consulta ? '?' . http_build_query($consulta) : '');
}

function manutencaoResponder(string $acao, array $e): void
{
    $agora = time();
    http_response_code(503);
    header('Retry-After: ' . manutencaoRetryAfter($e, $agora));
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    if ($acao === 'texto') {
        header('Content-Type: text/plain; charset=utf-8');
        exit('Em manutenção. Tente de novo mais tarde.');
    }
    if ($acao === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        exit(json_encode(['sucesso' => false, 'ok' => false, 'manutencao' => true, 'mensagem' => 'Sistema em manutenção. Tente de novo em instantes.'], JSON_UNESCAPED_UNICODE));
    }
    header('Content-Type: text/html; charset=utf-8');
    exit(manutencaoPagina($e, $agora));
}

/**
 * A guarda: chamada por conexao.php, antes de abrir o banco. Com a manutenção desligada não faz nada (uma checagem de arquivo);
 * ligada, deixa passar quem está liberado e os caminhos que o modo preserva, e responde 503 para o resto. Só atua em HTTP.
 */
function manutencaoGuarda(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }
    $e = manutencaoLer();
    if (!$e['ativo']) {
        return;
    }
    // ?liberar=SEGREDO: libera este navegador e volta para o mesmo endereço, sem o segredo na barra
    if (isset($_GET['liberar']) && is_string($_GET['liberar']) && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && manutencaoSegredoConfere($e, $_GET['liberar'])) {
        manutencaoLiberarNavegador($e);
        manutencaoLog('navegador liberado pelo link');
        header('Cache-Control: no-store');
        header('Location: ' . manutencaoUrlSemLiberar((string) ($_SERVER['REQUEST_URI'] ?? '/')), true, 302);
        exit;
    }
    $rel = manutencaoCaminhoRelativo();
    $acao = manutencaoDecidir($e, $rel, manutencaoLiberado($e, $_COOKIE), manutencaoQuerJson($rel, $_SERVER));
    if ($acao !== 'passar') {
        manutencaoResponder($acao, $e);
    }
}
