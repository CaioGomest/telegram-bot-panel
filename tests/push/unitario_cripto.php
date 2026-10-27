<?php
declare(strict_types=1);

/**
 * Teste unitário da parte criptográfica dos avisos push (funcoes/push_cripto.php). Sem banco, sem rede.
 * Rodar: php tests/push/unitario_cripto.php   (sai com código 1 se algo falhar)
 *   No PHP do Windows (XAMPP/WAMP) o OpenSSL não acha o openssl.cnf sozinho: defina OPENSSL_CONF=<caminho do openssl.cnf> antes (tests/push/rodar_todos.sh faz isso).
 *
 * A prova principal é o EXEMPLO OFICIAL do RFC 8291 (seção 5 e Apêndice A): com as mesmas chaves, o mesmo salt e o mesmo texto, o corpo
 * tem que sair byte a byte igual ao do RFC. Os valores abaixo foram copiados do texto do RFC (rfc-editor.org/rfc/rfc8291), não de memória.
 */

require_once __DIR__ . '/../../funcoes/push_cripto.php';

$ok = 0;
$falhas = 0;
function confere(string $nome, bool $certo, string $detalhe = ''): void
{
    global $ok, $falhas;
    $certo ? $ok++ : $falhas++;
    echo ($certo ? 'OK     ' : 'FALHOU ') . $nome . ($certo || $detalhe === '' ? '' : '  -> ' . $detalhe) . PHP_EOL;
}

if (pushGerarParEc() === null) {
    echo "FALHOU  o OpenSSL não gerou uma chave EC. No Windows defina OPENSSL_CONF=<openssl.cnf> (ex.: C:/xampp/php2/extras/ssl/openssl.cnf)." . PHP_EOL;
    exit(1);
}

// ---------- exemplo oficial do RFC 8291 ----------
$rfc = [
    'texto' => 'When I grow up, I want to be a watermelon',
    'as_privada' => 'yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw',
    'as_publica' => 'BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8',
    'ua_privada' => 'q1dXpw3UpT5VOmu_cf_v6ih07Aems3njxI-JWgLcM94',
    'ua_publica' => 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4',
    'auth' => 'BTBZMqHH6r4Tts7J_aSIgg',
    'salt' => 'DGv6ra1nlYgDCS1FRnbzlw',
    'corpo' => 'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27ml'
        . 'mlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPT'
        . 'pK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN',
    'cifra' => '8pfeW0KbunFT06SuDKoJH9Ql87S1QUrdirN6GcG7sFz1y1sqLgVi1VhjVkHsUoEsbI_0LpXMuGvnzQ',
];
$fixos = ['privada' => $rfc['as_privada'], 'publica' => $rfc['as_publica'], 'salt' => $rfc['salt']];

echo "--- exemplo oficial do RFC 8291\n";
$corpo = pushCriptografarPayload($rfc['texto'], $rfc['ua_publica'], $rfc['auth'], $fixos);
confere('o corpo gerado é IGUAL, byte a byte, ao exemplo da seção 5 do RFC 8291', $corpo !== null && pushB64uCodificar($corpo) === $rfc['corpo'], $corpo === null ? 'null' : pushB64uCodificar($corpo));
confere('o corpo tem 144 bytes (86 de cabeçalho + 41 de texto + 1 de delimitador + 16 de tag; o "Content-Length: 145" do texto do RFC é erro conhecido do RFC, o corpo mostrado nele tem 144)', $corpo !== null && strlen($corpo) === 144, (string) strlen((string) $corpo));
confere('o cabeçalho de 86 bytes: salt, registro de 4096, tamanho da chave 65 e a chave pública do servidor', $corpo !== null && substr($corpo, 0, 16) === pushB64uDecodificar($rfc['salt']) && substr($corpo, 16, 4) === "\x00\x00\x10\x00" && ord($corpo[20]) === 65 && substr($corpo, 21, 65) === pushB64uDecodificar($rfc['as_publica']));
confere('o texto cifrado bate com o do Apêndice A', $corpo !== null && pushB64uCodificar(substr($corpo, 86, -16) . substr($corpo, -16)) === $rfc['cifra']);
confere('o "aparelho" do RFC decifra o corpo do RFC e recupera o texto', pushDecifrarPayloadParaTeste((string) pushB64uDecodificar($rfc['corpo']), $rfc['ua_privada'], $rfc['ua_publica'], $rfc['auth']) === $rfc['texto']);
confere('chave privada montada a mão (DER) corresponde à pública do RFC (o DER de pushPemPrivadaEc está certo)', (function () use ($rfc) {
    $k = openssl_pkey_get_private(pushPemPrivadaEc((string) pushB64uDecodificar($rfc['ua_privada']), (string) pushB64uDecodificar($rfc['ua_publica'])));
    if ($k === false) {
        return false;
    }
    $d = openssl_pkey_get_details($k);
    return "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT) === pushB64uDecodificar($rfc['ua_publica']);
})());

// ---------- ida e volta com chaves aleatórias ----------
echo "\n--- ida e volta com chaves aleatórias\n";
$aparelho = pushGerarParEc();
$ua_pub = pushB64uCodificar($aparelho['publica']);
$auth = pushB64uCodificar(random_bytes(16));
$ua_priv_pem = $aparelho['privada_pem'];
$detalhes = openssl_pkey_get_details($aparelho['chave']);
$ua_priv_b64u = pushB64uCodificar(str_pad($detalhes['ec']['d'], 32, "\0", STR_PAD_LEFT));
foreach ([0, 1, 16, 100, 399, 1000, 3993] as $tamanho) {
    $texto = $tamanho === 0 ? '' : substr(str_repeat('áé' . random_int(0, 9), 4000), 0, $tamanho);
    $c = pushCriptografarPayload($texto, $ua_pub, $auth);
    $volta = $c === null ? null : pushDecifrarPayloadParaTeste($c, $ua_priv_b64u, $ua_pub, $auth);
    confere("payload de $tamanho bytes: cifra e decifra devolve o mesmo texto", $c !== null && $volta === $texto);
}
confere('payload de 3994 bytes (acima do limite de um registro) é recusado', pushCriptografarPayload(str_repeat('a', 3994), $ua_pub, $auth) === null);
$a = pushCriptografarPayload('igual', $ua_pub, $auth);
$b = pushCriptografarPayload('igual', $ua_pub, $auth);
confere('o mesmo texto cifrado duas vezes dá corpos DIFERENTES (chave efêmera e salt novos a cada mensagem)', $a !== null && $b !== null && $a !== $b && substr($a, 21, 65) !== substr($b, 21, 65) && substr($a, 0, 16) !== substr($b, 0, 16));
$adulterado = $a;
$adulterado[100] = $adulterado[100] ^ "\x01";
confere('corpo adulterado (1 bit) não decifra (a tag do GCM pega)', pushDecifrarPayloadParaTeste($adulterado, $ua_priv_b64u, $ua_pub, $auth) === null);
$outro_auth = pushB64uCodificar(random_bytes(16));
confere('com outro segredo de autenticação não decifra', pushDecifrarPayloadParaTeste($a, $ua_priv_b64u, $ua_pub, $outro_auth) === null);

// ---------- chaves inválidas ----------
echo "\n--- chaves inválidas\n";
$ponto_fora_da_curva = pushB64uCodificar("\x04" . random_bytes(64));
confere('chave de 65 bytes que NÃO está na curva é recusada', !pushChavesInscricaoValidas($ponto_fora_da_curva, $auth) && pushCriptografarPayload('x', $ponto_fora_da_curva, $auth) === null);
confere('chave de 64 bytes é recusada', !pushChavesInscricaoValidas(pushB64uCodificar(substr($aparelho['publica'], 1)), $auth));
confere('chave que não começa em 0x04 (comprimida) é recusada', !pushChavesInscricaoValidas(pushB64uCodificar("\x02" . substr($aparelho['publica'], 1)), $auth));
confere('auth de 15 bytes e de 17 bytes são recusados', !pushChavesInscricaoValidas($ua_pub, pushB64uCodificar(random_bytes(15))) && !pushChavesInscricaoValidas($ua_pub, pushB64uCodificar(random_bytes(17))));
confere('texto que não é base64url (espaço, "+", "/", "=") é recusado', !pushChavesInscricaoValidas($ua_pub . '=', $auth) && !pushChavesInscricaoValidas('a b', $auth) && !pushChavesInscricaoValidas(str_replace('-', '+', $ua_pub) . '+', $auth) && !pushChavesInscricaoValidas('', ''));
confere('chave e auth bons passam', pushChavesInscricaoValidas($ua_pub, $auth));

// ---------- base64url ----------
echo "\n--- base64url\n";
$todos = true;
for ($i = 0; $i < 300; $i++) {
    $bin = $i % 70 === 0 ? '' : random_bytes($i % 70);
    $todos = $todos && ($bin === '' || pushB64uDecodificar(pushB64uCodificar($bin)) === $bin) && !preg_match('/[+\/=]/', pushB64uCodificar($bin));
}
confere('ida e volta com 300 tamanhos diferentes, sem "+", "/" nem "="', $todos);
confere('comprimento impossível em base64 (resto 1) é recusado', pushB64uDecodificar('abcde') === null);

// ---------- VAPID ----------
echo "\n--- VAPID (RFC 8292)\n";
$vapid = pushGerarChavesVapid();
confere('as chaves VAPID saem: pública de 65 bytes e privada em PEM', $vapid !== null && strlen((string) pushB64uDecodificar($vapid['publica'])) === 65 && str_contains($vapid['privada_pem'], 'PRIVATE KEY'));
$pem_publica = pushPemPublicaEc((string) pushB64uDecodificar($vapid['publica']));
$agora = 1_800_000_000;

/** R||S (64 bytes) -> DER, só para conferir a assinatura com o openssl_verify. */
function rawParaDer(string $raw): string
{
    $partes = [];
    foreach ([substr($raw, 0, 32), substr($raw, 32)] as $inteiro) {
        $inteiro = ltrim($inteiro, "\0");
        if ($inteiro === '' || (ord($inteiro[0]) & 0x80)) {
            $inteiro = "\0" . $inteiro;
        }
        $partes[] = "\x02" . chr(strlen($inteiro)) . $inteiro;
    }
    $corpo = implode('', $partes);
    return "\x30" . pushDerComprimento(strlen($corpo)) . $corpo;
}

$jwt = pushJwtVapid('https://fcm.googleapis.com', 'https://exemplo.com.br', $agora + 43200, $vapid['privada_pem']);
$p = $jwt === null ? [] : explode('.', $jwt);
confere('o JWT tem 3 partes e o cabeçalho é {"typ":"JWT","alg":"ES256"}', count($p) === 3 && pushB64uDecodificar($p[0]) === '{"typ":"JWT","alg":"ES256"}');
$claims = count($p) === 3 ? json_decode((string) pushB64uDecodificar($p[1]), true) : [];
confere('as claims levam aud, exp e sub corretos', ($claims['aud'] ?? '') === 'https://fcm.googleapis.com' && ($claims['exp'] ?? 0) === $agora + 43200 && ($claims['sub'] ?? '') === 'https://exemplo.com.br');
$assinatura = count($p) === 3 ? (string) pushB64uDecodificar($p[2]) : '';
confere('a assinatura tem 64 bytes (R||S) e CONFERE com a chave pública (openssl_verify)', strlen($assinatura) === 64 && openssl_verify($p[0] . '.' . $p[1], rawParaDer($assinatura), $pem_publica, OPENSSL_ALGO_SHA256) === 1);
$sempre64 = true;
$sempre_confere = true;
for ($i = 0; $i < 300; $i++) {
    $j = pushJwtVapid('https://web.push.apple.com', 'mailto:a@b.co', $agora + $i, $vapid['privada_pem']);
    $q = explode('.', (string) $j);
    $s = (string) pushB64uDecodificar($q[2] ?? '');
    $sempre64 = $sempre64 && strlen($s) === 64;
    $sempre_confere = $sempre_confere && openssl_verify($q[0] . '.' . $q[1], rawParaDer($s), $pem_publica, OPENSSL_ALGO_SHA256) === 1;
}
confere('300 assinaturas seguidas: todas com 64 bytes (mesmo quando R ou S têm 31 ou 33 bytes no DER) e todas conferem', $sempre64 && $sempre_confere);
confere('DER malformado é recusado (vazio, curto, sem SEQUENCE, inteiro truncado, inteiro > 32 bytes)', pushDerParaRaw('') === null && pushDerParaRaw("\x30\x00") === null && pushDerParaRaw("\x31\x06\x02\x01\x01\x02\x01\x01") === null && pushDerParaRaw("\x30\x08\x02\x05\x01\x02") === null && pushDerParaRaw("\x30\x4a\x02\x21\x01" . str_repeat("\x01", 32) . "\x02\x21\x01" . str_repeat("\x01", 32)) === null);
confere('DER de exemplo (R e S curtos) vira 64 bytes com zeros à esquerda', pushDerParaRaw("\x30\x06\x02\x01\x05\x02\x01\x07") === str_repeat("\0", 31) . "\x05" . str_repeat("\0", 31) . "\x07");
confere('chave privada inválida não gera JWT', pushJwtVapid('https://x.com', 'mailto:a@b.co', $agora, 'lixo') === null);

$c1 = pushCabecalhoVapid('https://fcm.googleapis.com/fcm/send/abc', $vapid['publica'], $vapid['privada_pem'], 'https://exemplo.com.br', $agora);
$c2 = pushCabecalhoVapid('https://fcm.googleapis.com/fcm/send/outro', $vapid['publica'], $vapid['privada_pem'], 'https://exemplo.com.br', $agora + 3600);
$c3 = pushCabecalhoVapid('https://web.push.apple.com/xyz', $vapid['publica'], $vapid['privada_pem'], 'https://exemplo.com.br', $agora + 3600);
$c4 = pushCabecalhoVapid('https://fcm.googleapis.com/fcm/send/abc', $vapid['publica'], $vapid['privada_pem'], 'https://exemplo.com.br', $agora + 39601);
confere('o cabeçalho vem no formato "vapid t=<jwt>, k=<chave pública>"', $c1 !== null && preg_match('/^vapid t=[\w-]+\.[\w-]+\.[\w-]+, k=' . preg_quote($vapid['publica'], '/') . '$/', $c1) === 1);
confere('mesma audiência dentro de 11 h reaproveita o JWT (assina uma vez só)', $c1 === $c2);
confere('outra audiência (Apple) usa outro JWT', $c3 !== null && $c3 !== $c1);
confere('depois de 11 h o JWT é renovado', $c4 !== null && $c4 !== $c1);
confere('endereço sem host não gera cabeçalho', pushCabecalhoVapid('lixo', $vapid['publica'], $vapid['privada_pem'], 'mailto:a@b.co') === null);
confere('audiência = esquema://host em minúsculas e sem caminho', pushAudienciaDoEndpoint('https://FCM.googleapis.com/fcm/send/abc?x=1') === 'https://fcm.googleapis.com');

echo PHP_EOL . "RESUMO: $ok ok, $falhas falhas" . PHP_EOL;
exit($falhas > 0 ? 1 : 0);
