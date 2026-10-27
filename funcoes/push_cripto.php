<?php
declare(strict_types=1);

/**
 * Avisos push (Web Push): parte PURA, sem banco e sem rede, para poder ser testada sozinha (tests/push/).
 * Plano completo: deploy/PLANO-PUSH-NOTIFICACOES.md. A parte que fala com o banco e com os serviços de push é funcoes/push.php.
 *
 *   - base64url, chaves EC P-256 (PEM/DER) e DER -> R||S da assinatura ES256;
 *   - conteúdo do aviso criptografado (RFC 8291, "aes128gcm"/RFC 8188) só com openssl/hash_hkdf nativos (a VPS não tem gmp nem bcmath);
 *   - cabeçalho VAPID (RFC 8292);
 *   - validação do endereço do aparelho (SSRF: o servidor faz POST para um endereço que o usuário informa) e das chaves;
 *   - montagem do texto, agrupamento em rajada, espera entre tentativas e classificação da resposta do serviço de push.
 */

const PUSH_TIPO_PIX_GERADO = 1;
const PUSH_TIPO_PIX_PAGO = 2;
/** Validade do aviso (segundos): Pix gerado 10 min, Pix pago 2 h. Depois disso o aviso perdeu o sentido e é descartado. */
const PUSH_VALIDADE_SEGUNDOS = [PUSH_TIPO_PIX_GERADO => 600, PUSH_TIPO_PIX_PAGO => 7200];
const PUSH_MAX_TENTATIVAS = [PUSH_TIPO_PIX_GERADO => 3, PUSH_TIPO_PIX_PAGO => 5];
/** Espera (segundos) antes da 1ª, 2ª, 3ª… nova tentativa. */
const PUSH_ESPERAS_SEGUNDOS = [PUSH_TIPO_PIX_GERADO => [5, 30, 120], PUSH_TIPO_PIX_PAGO => [2, 10, 60, 300, 1200]];
const PUSH_MAX_APARELHOS = 10;
/** Serviços de push aceitos (hosts exatos e sufixos). Qualquer outro endereço é recusado. */
const PUSH_HOSTS_EXATOS = ['fcm.googleapis.com', 'web.push.apple.com'];
const PUSH_HOSTS_SUFIXOS = ['.push.services.mozilla.com', '.push.apple.com', '.notify.windows.com'];

// ===================== base64url =====================

function pushB64uCodificar(string $binario): string
{
    return rtrim(strtr(base64_encode($binario), '+/', '-_'), '=');
}

/** null quando o texto não é base64url válido (só aceita A-Z a-z 0-9 - _ ; sem espaço e sem "="). */
function pushB64uDecodificar(string $texto): ?string
{
    if ($texto === '' || strlen($texto) > 4096 || !preg_match('/^[A-Za-z0-9_-]+$/', $texto)) {
        return null;
    }
    $binario = base64_decode(strtr($texto, '-_', '+/') . str_repeat('=', (4 - strlen($texto) % 4) % 4), true);
    return $binario === false ? null : $binario;
}

// ===================== chaves EC P-256 =====================

/** Tamanho DER (curto ou longo) para comprimentos até 65535. */
function pushDerComprimento(int $n): string
{
    return $n < 128 ? chr($n) : ($n < 256 ? "\x81" . chr($n) : "\x82" . chr($n >> 8) . chr($n & 255));
}

function pushPemDeDer(string $der, string $rotulo): string
{
    return "-----BEGIN {$rotulo}-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END {$rotulo}-----\n";
}

/** Chave pública P-256 (65 bytes, 0x04||X||Y) como PEM SubjectPublicKeyInfo. O OpenSSL recusa ponto que não está na curva. */
function pushPemPublicaEc(string $publica65): string
{
    return pushPemDeDer(hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $publica65, 'PUBLIC KEY');
}

/** Chave privada P-256 crua (32 bytes) + pública (65) como PEM "EC PRIVATE KEY" (usado nos testes com os valores do RFC e no aparelho simulado). */
function pushPemPrivadaEc(string $privada32, string $publica65): string
{
    $corpo = "\x02\x01\x01" . "\x04\x20" . $privada32
        . "\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"
        . "\xa1\x44\x03\x42\x00" . $publica65;
    return pushPemDeDer("\x30" . pushDerComprimento(strlen($corpo)) . $corpo, 'EC PRIVATE KEY');
}

/** Par novo. Devolve ['chave' => recurso OpenSSL, 'publica' => 65 bytes, 'privada_pem' => PEM] ou null se o OpenSSL falhar. */
function pushGerarParEc(): ?array
{
    $chave = @openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if ($chave === false) {
        return null;
    }
    $detalhes = openssl_pkey_get_details($chave);
    if (!is_array($detalhes) || !isset($detalhes['ec']['x'], $detalhes['ec']['y'])) {
        return null;
    }
    // x e y vêm sem os zeros à esquerda: sempre 32 bytes cada.
    $publica = "\x04" . str_pad($detalhes['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($detalhes['ec']['y'], 32, "\0", STR_PAD_LEFT);
    if (!openssl_pkey_export($chave, $pem)) {
        return null;
    }
    return ['chave' => $chave, 'publica' => $publica, 'privada_pem' => $pem];
}

/** Par de chaves VAPID: ['publica' => base64url (65 bytes, é o applicationServerKey do navegador), 'privada_pem' => PEM]. */
function pushGerarChavesVapid(): ?array
{
    $par = pushGerarParEc();
    return $par ? ['publica' => pushB64uCodificar($par['publica']), 'privada_pem' => $par['privada_pem']] : null;
}

/** Valida as duas chaves que o navegador entrega: p256dh (65 bytes, começa em 0x04, ponto na curva) e auth (16 bytes). */
function pushChavesInscricaoValidas(string $p256dh_b64u, string $auth_b64u): bool
{
    $p256dh = pushB64uDecodificar($p256dh_b64u);
    $auth = pushB64uDecodificar($auth_b64u);
    if ($p256dh === null || $auth === null || strlen($p256dh) !== 65 || $p256dh[0] !== "\x04" || strlen($auth) !== 16) {
        return false;
    }
    return @openssl_pkey_get_public(pushPemPublicaEc($p256dh)) !== false;
}

// ===================== conteúdo criptografado (RFC 8291 / RFC 8188, aes128gcm) =====================

/**
 * Corpo da requisição de push: salt(16) || rs(4096, 4 bytes) || idlen(65) || chave pública efêmera(65) || cifra || tag(16).
 * $teste (só nos testes): ['privada' => b64u 32 bytes, 'publica' => b64u 65 bytes, 'salt' => b64u 16 bytes] para reproduzir o exemplo do RFC.
 * Devolve null se a chave do aparelho for inválida ou o OpenSSL falhar.
 */
function pushCriptografarPayload(string $payload, string $ua_publica_b64u, string $auth_b64u, ?array $teste = null): ?string
{
    $ua_publica = pushB64uDecodificar($ua_publica_b64u);
    $auth = pushB64uDecodificar($auth_b64u);
    if ($ua_publica === null || $auth === null || strlen($ua_publica) !== 65 || $ua_publica[0] !== "\x04" || strlen($auth) !== 16 || strlen($payload) > 3993) {
        return null;
    }
    $ua_chave = @openssl_pkey_get_public(pushPemPublicaEc($ua_publica));
    if ($ua_chave === false) {
        return null;
    }

    if ($teste !== null) {
        $as_publica = (string) pushB64uDecodificar((string) $teste['publica']);
        $as_chave = openssl_pkey_get_private(pushPemPrivadaEc((string) pushB64uDecodificar((string) $teste['privada']), $as_publica));
        $salt = (string) pushB64uDecodificar((string) $teste['salt']);
    } else {
        $par = pushGerarParEc();
        if ($par === null) {
            return null;
        }
        $as_publica = $par['publica'];
        $as_chave = $par['chave'];
        $salt = random_bytes(16);
    }
    if ($as_chave === false || strlen($as_publica) !== 65 || strlen($salt) !== 16) {
        return null;
    }

    $ecdh = openssl_pkey_derive($ua_chave, $as_chave, 32);
    if ($ecdh === false || strlen($ecdh) !== 32) {
        return null;
    }
    $key_info = "WebPush: info\0" . $ua_publica . $as_publica;
    $ikm = hash_hkdf('sha256', $ecdh, 32, $key_info, $auth);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

    $tag = '';
    $cifra = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if ($cifra === false || strlen($tag) !== 16) {
        return null;
    }
    return $salt . pack('N', 4096) . chr(65) . $as_publica . $cifra . $tag;
}

/** Operação inversa, só para os testes (o "aparelho" simulado): devolve o texto original ou null. */
function pushDecifrarPayloadParaTeste(string $corpo, string $ua_privada_b64u, string $ua_publica_b64u, string $auth_b64u): ?string
{
    $ua_privada = pushB64uDecodificar($ua_privada_b64u);
    $ua_publica = pushB64uDecodificar($ua_publica_b64u);
    $auth = pushB64uDecodificar($auth_b64u);
    if ($ua_privada === null || $ua_publica === null || $auth === null || strlen($corpo) < 86 + 17) {
        return null;
    }
    $salt = substr($corpo, 0, 16);
    $rs = unpack('N', substr($corpo, 16, 4))[1];
    $idlen = ord($corpo[20]);
    if ($rs < 18 || $idlen !== 65) {
        return null;
    }
    $as_publica = substr($corpo, 21, 65);
    $registro = substr($corpo, 86);
    $tag = substr($registro, -16);
    $cifra = substr($registro, 0, -16);

    $ua_chave = openssl_pkey_get_private(pushPemPrivadaEc($ua_privada, $ua_publica));
    $as_chave = @openssl_pkey_get_public(pushPemPublicaEc($as_publica));
    if ($ua_chave === false || $as_chave === false) {
        return null;
    }
    $ecdh = openssl_pkey_derive($as_chave, $ua_chave, 32);
    if ($ecdh === false) {
        return null;
    }
    $ikm = hash_hkdf('sha256', $ecdh, 32, "WebPush: info\0" . $ua_publica . $as_publica, $auth);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
    $aberto = openssl_decrypt($cifra, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($aberto === false) {
        return null;
    }
    $fim = strrpos($aberto, "\x02");
    return $fim === false ? null : substr($aberto, 0, $fim);
}

// ===================== VAPID (RFC 8292) =====================

/** Assinatura ECDSA em DER (SEQUENCE de dois INTEGER) -> 64 bytes R||S (o formato do JWT ES256). */
function pushDerParaRaw(string $der): ?string
{
    if (strlen($der) < 8 || $der[0] !== "\x30") {
        return null;
    }
    $pos = 1;
    $tamanho = ord($der[$pos++]);
    if ($tamanho & 0x80) {
        $bytes = $tamanho & 0x7f;
        if ($bytes < 1 || $bytes > 2) {
            return null;
        }
        $pos += $bytes;
    }
    $partes = [];
    for ($i = 0; $i < 2; $i++) {
        if (!isset($der[$pos]) || $der[$pos] !== "\x02") {
            return null;
        }
        $n = ord($der[$pos + 1]);
        $inteiro = substr($der, $pos + 2, $n);
        if (strlen($inteiro) !== $n) {
            return null;
        }
        $inteiro = ltrim($inteiro, "\0");
        if (strlen($inteiro) > 32) {
            return null;
        }
        $partes[] = str_pad($inteiro, 32, "\0", STR_PAD_LEFT);
        $pos += 2 + $n;
    }
    return $partes[0] . $partes[1];
}

/** JWT ES256 do VAPID. $audiencia = esquema://host do endereço do aparelho; $assunto = https://… ou mailto:… (a Apple recusa com 403 se for inválido). */
function pushJwtVapid(string $audiencia, string $assunto, int $expira_em, string $privada_pem): ?string
{
    $cabecalho = pushB64uCodificar('{"typ":"JWT","alg":"ES256"}');
    $claims = pushB64uCodificar(json_encode(['aud' => $audiencia, 'exp' => $expira_em, 'sub' => $assunto], JSON_UNESCAPED_SLASHES));
    $chave = @openssl_pkey_get_private($privada_pem);
    if ($chave === false) {
        return null;
    }
    $assinatura_der = '';
    if (!openssl_sign($cabecalho . '.' . $claims, $assinatura_der, $chave, OPENSSL_ALGO_SHA256)) {
        return null;
    }
    $raw = pushDerParaRaw($assinatura_der);
    return $raw === null ? null : $cabecalho . '.' . $claims . '.' . pushB64uCodificar($raw);
}

/** esquema://host (sem porta) do endereço de push, que é o "aud" do JWT. */
function pushAudienciaDoEndpoint(string $endpoint): ?string
{
    $partes = parse_url($endpoint);
    if (!is_array($partes) || empty($partes['scheme']) || empty($partes['host'])) {
        return null;
    }
    return strtolower($partes['scheme']) . '://' . strtolower($partes['host']) . (isset($partes['port']) ? ':' . $partes['port'] : '');
}

/**
 * Valor do cabeçalho "Authorization: vapid t=<jwt>, k=<chave pública>". O JWT vale 12 h e é reaproveitado por 11 h por audiência
 * (assinar é a parte mais cara do envio). Cache na memória do processo.
 */
function pushCabecalhoVapid(string $endpoint, string $publica_b64u, string $privada_pem, string $assunto, ?int $agora = null): ?string
{
    static $cache = [];
    $agora = $agora ?? time();
    $audiencia = pushAudienciaDoEndpoint($endpoint);
    if ($audiencia === null) {
        return null;
    }
    $chave_cache = sha1($audiencia . '|' . $assunto . '|' . $publica_b64u);
    if (!isset($cache[$chave_cache]) || $cache[$chave_cache]['renovar_em'] <= $agora) {
        $jwt = pushJwtVapid($audiencia, $assunto, $agora + 43200, $privada_pem);
        if ($jwt === null) {
            return null;
        }
        $cache[$chave_cache] = ['jwt' => $jwt, 'renovar_em' => $agora + 39600];
    }
    return 'vapid t=' . $cache[$chave_cache]['jwt'] . ', k=' . $publica_b64u;
}

// ===================== endereço do aparelho (SSRF) =====================

/** true só dentro dos testes de linha de comando (PUSH_MODO_TESTE=1): nunca por configuração, banco ou HTTP. */
function pushModoTeste(): bool
{
    return php_sapi_name() === 'cli' && getenv('PUSH_MODO_TESTE') === '1';
}

/**
 * O servidor faz POST para este endereço, e quem escolhe é o usuário: aceita só https, até 600 caracteres, sem usuário/senha/porta,
 * host que não seja IP, só letras/números/ponto/hífen e que esteja na lista de serviços de push. (Redirecionamento é desligado no envio.)
 */
function pushEndpointValido(string $url): bool
{
    if (strlen($url) > 600 || $url === '' || preg_match('/[\x00-\x20\x7f\\\\@]/', $url)) {
        return false;
    }
    $partes = parse_url($url);
    if (!is_array($partes) || !isset($partes['scheme'], $partes['host'])) {
        return false;
    }
    if (pushModoTeste() && in_array(strtolower($partes['host']), ['127.0.0.1', 'localhost'], true) && in_array(strtolower($partes['scheme']), ['http', 'https'], true)) {
        return true;
    }
    if (strtolower($partes['scheme']) !== 'https' || isset($partes['user']) || isset($partes['pass']) || isset($partes['port']) || isset($partes['fragment'])) {
        return false;
    }
    $host = $partes['host'];
    if ($host !== strtolower($host) || !preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $host) || strpos($host, '..') !== false || filter_var($host, FILTER_VALIDATE_IP) !== false) {
        return false;
    }
    if (empty($partes['path']) || $partes['path'][0] !== '/') {
        return false;
    }
    if (in_array($host, PUSH_HOSTS_EXATOS, true)) {
        return true;
    }
    foreach (PUSH_HOSTS_SUFIXOS as $sufixo) {
        if (strlen($host) > strlen($sufixo) + 1 && substr($host, -strlen($sufixo)) === $sufixo) {
            return true;
        }
    }
    return false;
}

/** Plataforma e apelido do aparelho a partir do user-agent ("iPhone · Safari", "Android · Chrome", "Windows · Edge"). */
function pushDescreverAparelho(string $user_agent, string $dica_plataforma = ''): array
{
    $ua = $user_agent;
    if (preg_match('/iPhone|iPad|iPod/i', $ua) || $dica_plataforma === 'ios') {
        $plataforma = 'ios';
        // iPadOS 13+ se apresenta como Mac no user-agent: a dica 'ios' vinda do JavaScript (Mac com tela de toque) é o que revela o iPad.
        $sistema = preg_match('/iPhone|iPod/i', $ua) ? 'iPhone' : 'iPad';
    } elseif (preg_match('/Android/i', $ua)) {
        $plataforma = 'android';
        $sistema = 'Android';
    } elseif (preg_match('/Windows/i', $ua)) {
        $plataforma = 'desktop';
        $sistema = 'Windows';
    } elseif (preg_match('/Macintosh|Mac OS X/i', $ua)) {
        $plataforma = 'desktop';
        $sistema = 'Mac';
    } elseif (preg_match('/Linux|X11|CrOS/i', $ua)) {
        $plataforma = 'desktop';
        $sistema = 'Linux';
    } else {
        $plataforma = 'outro';
        $sistema = 'Aparelho';
    }
    if (preg_match('/Edg(e|A|iOS)?\//i', $ua)) {
        $navegador = 'Edge';
    } elseif (preg_match('/OPR\/|Opera/i', $ua)) {
        $navegador = 'Opera';
    } elseif (preg_match('/SamsungBrowser/i', $ua)) {
        $navegador = 'Samsung Internet';
    } elseif (preg_match('/Firefox|FxiOS/i', $ua)) {
        $navegador = 'Firefox';
    } elseif (preg_match('/Chrome|CriOS/i', $ua)) {
        $navegador = 'Chrome';
    } elseif (preg_match('/Safari/i', $ua)) {
        $navegador = 'Safari';
    } else {
        $navegador = 'navegador';
    }
    return ['plataforma' => $plataforma, 'apelido' => $sistema . ' · ' . $navegador];
}

// ===================== texto do aviso =====================

function pushDinheiro(float $valor): string
{
    return 'R$ ' . number_format($valor, 2, ',', '.');
}

function pushCortar(string $texto, int $max): string
{
    $texto = trim(preg_replace('/\s+/u', ' ', $texto) ?? '');
    return mb_strlen($texto, 'UTF-8') > $max ? rtrim(mb_substr($texto, 0, $max - 1, 'UTF-8')) . '…' : $texto;
}

/**
 * Mensagem do aviso (vira JSON de poucas centenas de bytes). SEM nome de cliente, e-mail, ID do Telegram ou código da transação.
 * Individual: $dados = ['tipo' => 1|2, 'id_venda', 'valor', 'produto', 'bot', 'criado_ts']. Resumo: ['kind' => 'rg'|'rp', 'n', 'total'].
 * k: pp pago, pg gerado, rg resumo de gerados, rp resumo de pagos, teste (botão "Enviar aviso de teste").
 */
function pushMontarMensagem(array $dados, string $versao_icone = '', ?int $agora = null): array
{
    $agora = $agora ?? time();
    $kind = (string) ($dados['kind'] ?? ((int) ($dados['tipo'] ?? 0) === PUSH_TIPO_PIX_PAGO ? 'pp' : 'pg'));
    $produto = pushCortar((string) ($dados['produto'] ?? ''), 60);
    $bot = trim((string) ($dados['bot'] ?? ''));
    $bot = $bot !== '' ? '@' . ltrim(pushCortar($bot, 40), '@') : '';

    if ($kind === 'teste') {
        $n = 1;
        $titulo = 'Aviso de teste';
        $corpo = 'Tudo certo: os avisos estão funcionando neste aparelho.';
        $tag = 'teste';
    } elseif ($kind === 'rg') {
        $n = max(2, (int) ($dados['n'] ?? 2));
        $titulo = $n . ' Pix gerados · ' . pushDinheiro((float) ($dados['total'] ?? 0));
        $corpo = 'nos últimos 30 s';
        $tag = 'pg-resumo';
    } elseif ($kind === 'rp') {
        $n = max(2, (int) ($dados['n'] ?? 2));
        $titulo = '💰 +' . $n . ' Novas Vendas!';
        $corpo = 'Você recebeu ' . pushDinheiro((float) ($dados['total'] ?? 0));
        $tag = 'pp-resumo';
    } elseif ($kind === 'pp') {
        $n = 1;
        $titulo = '💰 Nova Venda!';
        $corpo = 'Você recebeu ' . pushDinheiro((float) ($dados['valor'] ?? 0));
        $tag = 'pp-' . (int) ($dados['id_venda'] ?? 0);
    } else {
        $n = 1;
        $titulo = 'Pix gerado · ' . pushDinheiro((float) ($dados['valor'] ?? 0));
        $corpo = implode(' · ', array_filter(['Aguardando pagamento', $produto], 'strlen'));
        $tag = 'pg-' . (int) ($dados['id_venda'] ?? 0);
    }
    $ts = (int) ($dados['criado_ts'] ?? $agora);
    return ['k' => $kind, 't' => $titulo, 'b' => $corpo, 'g' => $tag, 'u' => '/vendas', 'ts' => $ts * 1000, 'n' => $n, 'v' => $versao_icone];
}

function pushJson(array $mensagem): string
{
    return (string) json_encode($mensagem, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

// ===================== agrupamento em rajada =====================

/**
 * Decide o que sai de um lote já reservado. POR APARELHO:
 *   - Pix pago: SEMPRE individual (decisão do dono, 21/10/2026: cada venda é um aviso). $limite_pagos só existe para teste/ajuste futuro: se um dia for finito, o excedente vira um resumo "+N Novas Vendas!".
 *   - Pix gerado: no máximo 1 aviso a cada $janela segundos. Dentro da janela os itens voltam à fila (disponíveis só no fim dela) e acumulam;
 *     fora dela, 1 item sai individual e 2 ou mais viram um resumo "N Pix gerados".
 * $itens: cada um com id, id_inscricao, tipo, id_venda, valor, ultimo_aviso_gerado_ts (int|null) e o que mais a mensagem usar.
 * Devolve ['enviar' => [itens], 'resumos' => [['id_inscricao','kind','ids','n','total','item_ref']], 'devolver' => [['ids','disponivel_ts']],
 *          'avisos_gerado' => [id_inscricao => ts]].
 */
function pushAgruparLote(array $itens, int $agora, int $janela = 30, int $limite_pagos = PHP_INT_MAX): array
{
    $por_aparelho = [];
    foreach ($itens as $item) {
        $por_aparelho[(int) $item['id_inscricao']][] = $item;
    }
    $saida = ['enviar' => [], 'resumos' => [], 'devolver' => [], 'avisos_gerado' => []];
    foreach ($por_aparelho as $id_inscricao => $lista) {
        usort($lista, fn($a, $b) => (int) $a['id'] <=> (int) $b['id']);
        $pagos = array_values(array_filter($lista, fn($i) => (int) $i['tipo'] === PUSH_TIPO_PIX_PAGO));
        $gerados = array_values(array_filter($lista, fn($i) => (int) $i['tipo'] === PUSH_TIPO_PIX_GERADO));

        if (count($pagos) <= $limite_pagos) {
            foreach ($pagos as $item) {
                $saida['enviar'][] = $item;
            }
        } else {
            foreach (array_slice($pagos, 0, $limite_pagos - 1) as $item) {
                $saida['enviar'][] = $item;
            }
            $resto = array_slice($pagos, $limite_pagos - 1);
            $saida['resumos'][] = [
                'id_inscricao' => $id_inscricao, 'kind' => 'rp', 'ids' => array_map(fn($i) => (int) $i['id'], $resto), 'n' => count($resto),
                'total' => array_sum(array_map(fn($i) => (float) ($i['valor'] ?? 0), $resto)), 'item_ref' => $resto[count($resto) - 1],
            ];
        }

        if ($gerados) {
            $ultimo = $gerados[0]['ultimo_aviso_gerado_ts'] ?? null;
            if ($ultimo !== null && $agora < (int) $ultimo + $janela) {
                $saida['devolver'][] = ['ids' => array_map(fn($i) => (int) $i['id'], $gerados), 'disponivel_ts' => (int) $ultimo + $janela];
            } elseif (count($gerados) === 1) {
                $saida['enviar'][] = $gerados[0];
                $saida['avisos_gerado'][$id_inscricao] = $agora;
            } else {
                $saida['resumos'][] = [
                    'id_inscricao' => $id_inscricao, 'kind' => 'rg', 'ids' => array_map(fn($i) => (int) $i['id'], $gerados), 'n' => count($gerados),
                    'total' => array_sum(array_map(fn($i) => (float) ($i['valor'] ?? 0), $gerados)), 'item_ref' => $gerados[count($gerados) - 1],
                ];
                $saida['avisos_gerado'][$id_inscricao] = $agora;
            }
        }
    }
    return $saida;
}

// ===================== tentativas e respostas =====================

/** Segundos até a próxima tentativa (com ±20% de variação para não bater todo mundo junto). $tentativas_feitas = quantas já foram (1ª espera = 1). */
function pushEsperaSegundos(int $tipo, int $tentativas_feitas, ?float $aleatorio = null): int
{
    $escada = PUSH_ESPERAS_SEGUNDOS[$tipo] ?? PUSH_ESPERAS_SEGUNDOS[PUSH_TIPO_PIX_GERADO];
    $base = $escada[min(max($tentativas_feitas, 1), count($escada)) - 1];
    $r = $aleatorio ?? (mt_rand() / mt_getrandmax());
    return max(1, (int) round($base * (0.8 + 0.4 * $r)));
}

function pushPodeTentarDeNovo(int $tipo, int $tentativas_feitas): bool
{
    return $tentativas_feitas < (PUSH_MAX_TENTATIVAS[$tipo] ?? 3);
}

/**
 * ok | morta (404/410: apagar a inscrição) | assinatura (400/401/403: não repetir, avisar o admin) | grande (413) |
 * limite (429: respeitar Retry-After e pausar o host) | temporario (5xx, 408, timeout, erro de rede: tentar de novo).
 */
function pushClassificarResposta(int $http, string $erro_curl = ''): string
{
    if ($erro_curl !== '' || $http === 0) {
        return 'temporario';
    }
    if ($http >= 200 && $http < 300) {
        return 'ok';
    }
    return match (true) {
        $http === 404, $http === 410 => 'morta',
        $http === 400, $http === 401, $http === 403 => 'assinatura',
        $http === 413 => 'grande',
        $http === 429 => 'limite',
        default => 'temporario',
    };
}

/** Segundos do cabeçalho Retry-After (número ou data HTTP); 0 se não houver. Limitado a 1 h. */
function pushRetryAfterSegundos(string $valor, ?int $agora = null): int
{
    $valor = trim($valor);
    if ($valor === '') {
        return 0;
    }
    if (ctype_digit($valor)) {
        return min(3600, (int) $valor);
    }
    $ts = strtotime($valor);
    return $ts === false ? 0 : max(0, min(3600, $ts - ($agora ?? time())));
}
