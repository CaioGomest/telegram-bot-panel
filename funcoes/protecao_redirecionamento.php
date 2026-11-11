<?php
declare(strict_types=1);

// Proteção e segmentação do Redirecionamento (/l/{slug}), no modelo da SharkBot:
//  - Proteção: "nenhuma", "cloaker" (robôs de anúncio + anti-compartilhamento) ou "cloaker_v2"
//    (o mesmo, mais um desafio em JavaScript que barra navegador automatizado);
//  - Segmentação: só deixa passar os países escolhidos e, se pedido, confere o idioma do navegador;
//  - Tráfego bloqueado vê uma página neutra (200) ou é mandado para outra URL.
// Tudo aqui é função pura ou recebe o que precisa por parâmetro, para o teste não depender de HTTP.

require_once __DIR__ . '/redirecionadores.php';

const REDIRECIONADOR_PROTECOES_NOVAS = ['nenhuma', 'cloaker', 'cloaker_v2'];
const REDIRECIONADOR_BLOQUEIOS = ['pagina_segura', 'redirecionar'];
const REDIRECIONADOR_DESAFIO_VALIDADE = 120;   // segundos que o desafio do Cloaker V2 vale
const REDIRECIONADOR_GEO_VALIDADE_DIAS = 30;

/** País => [nome, idiomas do navegador (subtag principal) que fazem sentido para ele]. */
const REDIRECIONADOR_PAISES = [
    'BR' => ['Brasil', ['pt']],
    'US' => ['Estados Unidos', ['en', 'es']],
    'PT' => ['Portugal', ['pt']],
    'ES' => ['Espanha', ['es', 'ca', 'gl', 'eu']],
    'MX' => ['México', ['es']],
    'AR' => ['Argentina', ['es']],
    'CO' => ['Colômbia', ['es']],
    'CL' => ['Chile', ['es']],
    'PE' => ['Peru', ['es']],
    'FR' => ['França', ['fr']],
    'DE' => ['Alemanha', ['de']],
    'IT' => ['Itália', ['it']],
    'GB' => ['Reino Unido', ['en']],
    'JP' => ['Japão', ['ja']],
    'IN' => ['Índia', ['en', 'hi']],
];

/** Lista no formato que a tela usa: [{codigo, nome}]. */
function paisesRedirecionamentoParaTela(): array
{
    $lista = [];
    foreach (REDIRECIONADOR_PAISES as $codigo => $p) {
        $lista[] = ['codigo' => $codigo, 'nome' => $p[0]];
    }
    return $lista;
}

/** "br, US,xx" -> ['BR','US'] só com códigos conhecidos, sem repetir, na ordem da tabela. */
function normalizarPaisesRedirecionamento(array|string $entrada): array
{
    $itens = is_array($entrada) ? $entrada : (preg_split('/[\s,;]+/', $entrada) ?: []);
    $pedidos = [];
    foreach ($itens as $item) {
        $pedidos[strtoupper(trim((string) $item))] = true;
    }
    return array_values(array_filter(array_keys(REDIRECIONADOR_PAISES), fn($c) => isset($pedidos[$c])));
}

/** Códigos pedidos que não existem na tabela (para recusar o salvamento em vez de ignorar em silêncio). */
function paisesDesconhecidosRedirecionamento(array $pedidos): array
{
    $desconhecidos = [];
    foreach ($pedidos as $item) {
        $codigo = strtoupper(trim((string) $item));
        if ($codigo !== '' && !isset(REDIRECIONADOR_PAISES[$codigo])) {
            $desconhecidos[$codigo] = $codigo;
        }
    }
    return array_values($desconhecidos);
}

function idiomasDosPaisesRedirecionamento(array $codigos): array
{
    $idiomas = [];
    foreach ($codigos as $c) {
        foreach (REDIRECIONADOR_PAISES[$c][1] ?? [] as $i) {
            $idiomas[$i] = $i;
        }
    }
    return array_values($idiomas);
}

/** "pt-BR,pt;q=0.9,en;q=0.8" -> ['pt','en'] (só a subtag principal, em minúsculas, sem repetir). */
function idiomasDoNavegadorRedirecionamento(string $cabecalho): array
{
    $idiomas = [];
    foreach (explode(',', substr($cabecalho, 0, 300)) as $parte) {
        $tag = strtolower(trim(explode(';', $parte)[0]));
        $principal = explode('-', $tag)[0];
        if (preg_match('/^[a-z]{2,3}$/', $principal) === 1) {
            $idiomas[$principal] = $principal;
        }
    }
    return array_values($idiomas);
}

/** URL de destino do bloqueio: só http(s), com domínio, sem usuário/senha. */
function urlBloqueioRedirecionamentoValida(string $url): bool
{
    if ($url === '' || strlen($url) > 500 || preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
        return false;
    }
    $p = parse_url($url);
    if (!is_array($p) || !in_array(strtolower((string) ($p['scheme'] ?? '')), ['http', 'https'], true)) {
        return false;
    }
    $host = strtolower((string) ($p['host'] ?? ''));
    return $host !== '' && !isset($p['user']) && !isset($p['pass'])
        && (dominioRedirecionamentoValido($host) || filter_var($host, FILTER_VALIDATE_IP) !== false);
}

/** Robô de anúncio/rastreador conhecido, ou cliente que não parece navegador de pessoa. */
function ehRoboDeAnuncioRedirecionamento(string $user_agent, string $metodo, string $accept_language): bool
{
    if (ehRoboRedirecionamento($user_agent, $metodo)) {
        return true;
    }
    if (trim($accept_language) === '') {
        return true;   // navegador de verdade sempre manda idioma; script e crawler quase nunca
    }
    return preg_match(
        '/adsbot|mediapartners|googleother|google-adstxt|storebot|apis-google|bingpreview|facebot|facebookbot|bytespider|tiktokspider|'
        . 'pinterest|linkedinbot|twitterbot|telegrambot|slackbot|discordbot|skypeuripreview|semrush|ahrefs|mj12|dotbot|petalbot|yandex|baidu|'
        . 'phantomjs|selenium|puppeteer|playwright|chrome-lighthouse|libwww|httpclient|node-fetch|axios|okhttp\/|requests|guzzle|postman|insomnia/i',
        $user_agent
    ) === 1;
}

/** O clique veio de um anúncio da plataforma do link? (click id da plataforma ou utm_source dela). */
function cliqueVeioDoAnuncioRedirecionamento(string $plataforma, array $query): bool
{
    $tem = fn(string $k) => isset($query[$k]) && is_string($query[$k]) && trim($query[$k]) !== '';
    $fonte = strtolower(trim((string) ($query['utm_source'] ?? '')));
    switch ($plataforma) {
        case 'meta':
            return $tem('fbclid') || in_array($fonte, ['facebook', 'instagram', 'fb', 'ig', 'meta'], true);
        case 'tiktok':
            return $tem('ttclid') || in_array($fonte, ['tiktok', 'tt'], true);
        case 'google':
            return $tem('gclid') || $tem('gbraid') || $tem('wbraid') || $fonte === 'google';
        default:
            return true;   // "Outra origem" não tem sinal confiável: o anti-compartilhamento não se aplica
    }
}

/**
 * Decide se este acesso passa. Devolve null (passa) ou o motivo do bloqueio:
 * 'robo' | 'compartilhamento' | 'pais' | 'idioma'.
 *
 * $req: ['ua','metodo','idioma' (Accept-Language), 'query' (array do $_GET), 'pais' (callable que devolve
 * o código do país do visitante ou null quando não deu para saber)].
 * País desconhecido passa de propósito: uma falha do serviço de localização não pode derrubar venda.
 */
function avaliarAcessoRedirecionador(array $r, array $req): ?string
{
    $protecao = (string) ($r['protecao'] ?? 'nenhuma');
    $com_cloaker = in_array($protecao, ['cloaker', 'cloaker_v2'], true);

    if ($com_cloaker && !empty($r['bloquear_bots'])
        && ehRoboDeAnuncioRedirecionamento((string) $req['ua'], (string) $req['metodo'], (string) $req['idioma'])) {
        return 'robo';
    }
    if ($com_cloaker && !empty($r['anti_compartilhamento'])
        && !cliqueVeioDoAnuncioRedirecionamento((string) ($r['plataforma'] ?? 'meta'), (array) $req['query'])) {
        return 'compartilhamento';
    }

    if (!empty($r['filtrar_pais'])) {
        $permitidos = normalizarPaisesRedirecionamento((string) ($r['paises'] ?? ''));
        if ($permitidos) {
            $pais = ($req['pais'])();
            if ($pais !== null && !in_array($pais, $permitidos, true)) {
                return 'pais';
            }
            if (!empty($r['validar_idioma'])) {
                $aceitos = idiomasDosPaisesRedirecionamento($permitidos);
                if (!array_intersect(idiomasDoNavegadorRedirecionamento((string) $req['idioma']), $aceitos)) {
                    return 'idioma';
                }
            }
        }
    }
    return null;
}

/* ========== País do visitante (com cache) ========== */

function ipPublicoRedirecionamento(string $ip): bool
{
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

/**
 * País (ISO-2) do IP. Primeiro o cache do banco; se faltar, consulta country.is (reserva: geojs.io;
 * GEOIP_URL no config.php entra na frente, se definido) com prazo curto e guarda. null = não deu para saber.
 */
function paisDoIpRedirecionamento(string $ip): ?string
{
    global $pdo;
    $ip = preg_replace('/[^0-9a-fA-F:.]/', '', $ip) ?? '';
    if ($ip === '' || strlen($ip) > 45) {
        return null;
    }

    try {
        $stmt = $pdo->prepare("SELECT pais FROM geo_ip_cache WHERE ip = ? AND consultado_em >= NOW() - INTERVAL " . REDIRECIONADOR_GEO_VALIDADE_DIAS . " DAY");
        $stmt->execute([$ip]);
        $pais = $stmt->fetchColumn();
        if (is_string($pais) && preg_match('/^[A-Z]{2}$/', $pais) === 1) {
            return $pais;
        }
    } catch (\Throwable $e) {
        error_log('[redirecionamento] geo cache: ' . $e->getMessage());
        return null;
    }

    if (!ipPublicoRedirecionamento($ip) || !function_exists('curl_init')) {
        return null;
    }
    // Provedores gratuitos em ordem: country.is, depois geojs.io de reserva (ambos sem chave).
    // GEOIP_URL no config.php, se existir, entra na frente (formato do ipwho.is: {success, country_code}).
    $provedores = [];
    if (defined('GEOIP_URL') && (string) GEOIP_URL !== '') {
        $provedores[] = [rtrim((string) GEOIP_URL, '/') . '/' . rawurlencode($ip) . '?fields=success,country_code', 'country_code'];
    }
    $provedores[] = ['https://api.country.is/' . rawurlencode($ip), 'country'];
    $provedores[] = ['https://get.geojs.io/v1/ip/country/' . rawurlencode($ip) . '.json', 'country'];

    $pais = '';
    foreach ($provedores as [$url, $campo]) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_TIMEOUT => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        $corpo = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $d = is_string($corpo) && $http === 200 ? json_decode($corpo, true) : null;
        $candidato = is_array($d) && ($d['success'] ?? true) !== false ? strtoupper((string) ($d[$campo] ?? '')) : '';
        if (preg_match('/^[A-Z]{2}$/', $candidato) === 1) {
            $pais = $candidato;
            break;
        }
    }
    if ($pais === '') {
        return null;
    }

    try {
        $pdo->prepare("REPLACE INTO geo_ip_cache (ip, pais, consultado_em) VALUES (?, ?, NOW())")->execute([$ip, $pais]);
        if (random_int(1, 200) === 1) {   // faxina barata, sem precisar de cron
            $pdo->exec("DELETE FROM geo_ip_cache WHERE consultado_em < NOW() - INTERVAL " . (REDIRECIONADOR_GEO_VALIDADE_DIAS * 2) . " DAY LIMIT 500");
        }
    } catch (\Throwable $e) {
        error_log('[redirecionamento] geo gravar: ' . $e->getMessage());
    }
    return $pais;
}

/* ========== Resposta ao tráfego bloqueado ========== */

/** Página neutra (200), sem nada que lembre o painel ou o produto. */
function paginaSeguraRedirecionamento(): string
{
    return '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
        . '<meta name="robots" content="noindex, nofollow"><title>Em breve</title>'
        . '<style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f6f7f9;color:#1d2330;font:16px/1.6 system-ui,sans-serif;text-align:center;padding:24px}'
        . 'main{max-width:460px}h1{font-size:26px;margin:0 0 10px}p{margin:0;color:#5a6270}</style></head>'
        . '<body><main><h1>Estamos preparando novidades</h1>'
        . '<p>Este endereço ainda não tem conteúdo publicado. Volte em breve.</p></main></body></html>';
}

/** Manda a resposta do bloqueio e encerra: página segura (200) ou redirecionamento 302. */
function responderBloqueioRedirecionamento(array $r): never
{
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow');
    if (($r['bloqueio_tipo'] ?? '') === 'redirecionar' && urlBloqueioRedirecionamentoValida((string) ($r['bloqueio_url'] ?? ''))) {
        header('Location: ' . $r['bloqueio_url'], true, 302);
        exit;
    }
    http_response_code(200);
    header('Content-Type: text/html; charset=UTF-8');
    echo paginaSeguraRedirecionamento();
    exit;
}

function contarBloqueioRedirecionamento(int $id): void
{
    global $pdo;
    try {
        $pdo->prepare("UPDATE redirecionadores SET bloqueados = bloqueados + 1 WHERE id = ?")->execute([$id]);
    } catch (\Throwable $e) {
        error_log('[redirecionamento] contar bloqueio: ' . $e->getMessage());
    }
}

/* ========== Desafio do Cloaker V2 ========== */

function chaveDesafioRedirecionamento(): string
{
    return hash('sha256', 'rd-desafio|' . (defined('BANCO_SENHA') ? BANCO_SENHA : '') . '|' . (defined('BANCO_NOME') ? BANCO_NOME : ''));
}

/** Passe do desafio: amarrado ao link, ao IP e ao momento, para não servir de um visitante para outro. */
function emitirPasseDesafioRedirecionamento(string $slug, string $ip, ?int $agora = null): string
{
    $agora ??= time();
    return $agora . '.' . hash_hmac('sha256', $slug . '|' . $ip . '|' . $agora, chaveDesafioRedirecionamento());
}

function passeDesafioRedirecionamentoValido(string $passe, string $slug, string $ip, ?int $agora = null): bool
{
    $agora ??= time();
    if (preg_match('/^(\d{9,11})\.([0-9a-f]{64})$/', $passe, $m) !== 1) {
        return false;
    }
    $emitido = (int) $m[1];
    if ($emitido > $agora + 5 || $agora - $emitido > REDIRECIONADOR_DESAFIO_VALIDADE) {
        return false;
    }
    return hash_equals(hash_hmac('sha256', $slug . '|' . $ip . '|' . $emitido, chaveDesafioRedirecionamento()), $m[2]);
}

/**
 * Sinais que o JavaScript do desafio manda. Devolve true se parece um navegador de pessoa.
 * Cada sinal reprovado soma ponto; 2 ou mais barram (um só pode ser extensão ou navegador esquisito).
 */
function sinaisDesafioRedirecionamentoOk(array $s, string $user_agent): bool
{
    $reprovados = 0;
    if (!empty($s['webdriver'])) {
        $reprovados += 2;   // navegador dirigido por automação: sozinho já barra
    }
    if ((int) ($s['tela_l'] ?? 0) < 100 || (int) ($s['tela_a'] ?? 0) < 100) {
        $reprovados++;
    }
    if ((int) ($s['idiomas'] ?? 0) < 1) {
        $reprovados++;
    }
    $chrome = preg_match('/chrome\//i', $user_agent) === 1 && preg_match('/edg|opr|crios|samsungbrowser/i', $user_agent) !== 1;
    $celular = preg_match('/android|iphone|ipad|mobile/i', $user_agent) === 1;
    if ($chrome && !$celular && empty($s['chrome_obj'])) {
        $reprovados++;      // Chrome de computador de verdade tem window.chrome
    }
    if (preg_match('/headlesschrome|phantomjs/i', $user_agent) === 1) {
        $reprovados += 2;
    }
    if (preg_match('/swiftshader|llvmpipe|software/i', (string) ($s['gpu'] ?? '')) === 1) {
        $reprovados++;      // renderizador por software: típico de servidor sem placa de vídeo
    }
    if (empty($s['canvas'])) {
        $reprovados++;
    }
    return $reprovados < 2;
}

/** HTML do desafio. Sem JavaScript, quem abre vê a página segura (ou é mandado ao destino de bloqueio). */
function paginaDesafioRedirecionamento(array $r, string $passe): string
{
    $noscript = ($r['bloqueio_tipo'] ?? '') === 'redirecionar' && urlBloqueioRedirecionamentoValida((string) ($r['bloqueio_url'] ?? ''))
        ? '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars((string) $r['bloqueio_url'], ENT_QUOTES) . '"></noscript>'
        : '<style>body>.rd-v2{display:none}</style>' . paginaSeguraNoscriptRedirecionamento();
    $js = <<<'JS'
(function(){
var s={webdriver:!!navigator.webdriver,tela_l:screen.width||0,tela_a:screen.height||0,idiomas:(navigator.languages||[]).length,chrome_obj:!!window.chrome,gpu:'',canvas:false};
try{var c=document.createElement('canvas');c.width=60;c.height=20;var x=c.getContext('2d');x.textBaseline='top';x.font='14px Arial';x.fillText('rd2',2,2);s.canvas=c.toDataURL().length>200;
var g=document.createElement('canvas').getContext('webgl');if(g){var e=g.getExtension('WEBGL_debug_renderer_info');if(e){s.gpu=String(g.getParameter(e.UNMASKED_RENDERER_WEBGL)||'')}}}catch(_){}
var t=document.body.getAttribute('data-p');
setTimeout(function(){
fetch(location.href,{method:'POST',credentials:'omit',headers:{'Content-Type':'application/json'},body:JSON.stringify({p:t,s:s})})
.then(function(r){return r.json()})
.then(function(d){if(d&&d.u){location.replace(d.u)}else if(d&&d.h){document.open();document.write(d.h);document.close()}})
.catch(function(){});
},350);
})();
JS;
    return '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
        . '<meta name="robots" content="noindex, nofollow"><title>Carregando…</title>'
        . '<style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#0a0b0e;color:#8b9099;font:15px system-ui,sans-serif}'
        . '.rd-v2{width:28px;height:28px;border:3px solid #2a2d35;border-top-color:#8b9099;border-radius:50%;animation:g .8s linear infinite}'
        . '@keyframes g{to{transform:rotate(360deg)}}</style>' . $noscript . '</head>'
        . '<body data-p="' . htmlspecialchars($passe, ENT_QUOTES) . '"><div class="rd-v2" aria-label="Carregando"></div>'
        . '<script>' . $js . '</script></body></html>';
}

/** O mesmo da página segura, mas como trecho para ficar dentro do <head> do desafio (<noscript>). */
function paginaSeguraNoscriptRedirecionamento(): string
{
    return '<noscript><style>body{background:#f6f7f9!important;color:#1d2330!important;font:16px/1.6 system-ui,sans-serif!important}'
        . 'body::before{content:"Estamos preparando novidades. Este endereço ainda não tem conteúdo publicado. Volte em breve.";max-width:460px;text-align:center;padding:24px}</style></noscript>';
}
