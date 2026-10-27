<?php
declare(strict_types=1);

/**
 * Teste unitário do validador de endereço do aparelho (pushEndpointValido, funcoes/push_cripto.php). Sem banco, sem rede.
 * O servidor faz POST para o endereço que o usuário informa na inscrição: se aceitasse qualquer um, um vendedor poderia apontar para a rede
 * interna da VPS (SSRF). Só podem passar os serviços de push conhecidos, por https, sem porta, sem usuário e sem IP.
 * Rodar: php tests/push/unitario_ssrf.php   (sai com código 1 se algo falhar)
 */

require_once __DIR__ . '/../../funcoes/push_cripto.php';

$ok = 0;
$falhas = 0;
function aceita(string $url): void
{
    global $ok, $falhas;
    $certo = pushEndpointValido($url);
    $certo ? $ok++ : $falhas++;
    echo ($certo ? 'OK     ' : 'FALHOU ') . 'aceita:  ' . substr($url, 0, 90) . PHP_EOL;
}
function recusa(string $url, string $motivo): void
{
    global $ok, $falhas;
    $certo = !pushEndpointValido($url);
    $certo ? $ok++ : $falhas++;
    echo ($certo ? 'OK     ' : 'FALHOU ') . 'recusa:  ' . $motivo . '  [' . substr(str_replace(["\n", "\r", "\0"], ['\n', '\r', '\0'], $url), 0, 70) . ']' . PHP_EOL;
}

echo "--- endereços reais dos serviços de push (têm que passar)\n";
aceita('https://fcm.googleapis.com/fcm/send/dXNlcjpwYXNz:APA91bH-exemplo');
aceita('https://updates.push.services.mozilla.com/wpush/v2/gAAAAABexemplo');
aceita('https://updates-autopush.stage.mozaws.net.push.services.mozilla.com/wpush/v2/x');
aceita('https://web.push.apple.com/QGwB5-exemplo-de-token');
aceita('https://api.push.apple.com/3/device/abc');
aceita('https://wns2-par02p.notify.windows.com/w/?token=BQYAAAexemplo');
aceita('https://db5p.notify.windows.com/w/?token=abc');

echo "\n--- o que NÃO pode passar\n";
recusa('http://fcm.googleapis.com/fcm/send/abc', 'http (sem TLS)');
recusa('HTTP://fcm.googleapis.com/fcm/send/abc', 'http em maiúsculas');
recusa('ftp://fcm.googleapis.com/x', 'outro esquema (ftp)');
recusa('file:///etc/passwd', 'file://');
recusa('gopher://fcm.googleapis.com/_x', 'gopher://');
recusa('javascript:alert(1)', 'javascript:');
recusa('//fcm.googleapis.com/fcm/send/abc', 'sem esquema');
recusa('fcm.googleapis.com/fcm/send/abc', 'sem esquema nem barras');
recusa('https://127.0.0.1/x', 'IP de loopback');
recusa('https://localhost/x', 'localhost');
recusa('https://169.254.169.254/latest/meta-data/', 'metadados da nuvem (IP link-local)');
recusa('https://10.0.0.5/x', 'IP da rede privada');
recusa('https://192.168.1.10/x', 'IP da rede privada 192.168');
recusa('https://[::1]/x', 'IPv6 de loopback');
recusa('https://[fe80::1]/x', 'IPv6 link-local');
recusa('https://2130706433/x', 'IP em decimal (127.0.0.1)');
recusa('https://0x7f000001/x', 'IP em hexadecimal');
recusa('https://fcm.googleapis.com:8443/fcm/send/abc', 'porta explícita');
recusa('https://fcm.googleapis.com:443/fcm/send/abc', 'porta 443 explícita (a lista não usa porta)');
recusa('https://user@fcm.googleapis.com/x', 'usuário no endereço');
recusa('https://user:senha@fcm.googleapis.com/x', 'usuário e senha');
recusa('https://fcm.googleapis.com@evil.com/x', 'truque do @ (o host de verdade é evil.com)');
recusa('https://evil.com\\@fcm.googleapis.com/x', 'barra invertida e @');
recusa('https://evil.com/fcm.googleapis.com', 'host fora da lista com o nome no caminho');
recusa('https://fcm.googleapis.com.evil.com/x', 'a lista como prefixo do host do atacante');
recusa('https://evilfcm.googleapis.com.br/x', 'domínio parecido');
recusa('https://notfcm.googleapis.com/x', 'subdomínio do googleapis que não é o fcm');
recusa('https://push.services.mozilla.com/x', 'o sufixo sem nenhum rótulo antes');
recusa('https://x.push.services.mozilla.com.evil.com/x', 'sufixo no meio do host');
recusa('https://evil-push.services.mozilla.com/x', 'sufixo colado sem ponto (evil-push.services…)');
recusa('https://notify.windows.com/x', 'notify.windows.com puro (sem rótulo antes)');
recusa('https://FCM.googleapis.com/fcm/send/abc', 'host em maiúsculas (não normalizado)');
recusa('https://fcm.googleapis.com./fcm/send/abc', 'ponto no fim do host');
recusa('https://fcm..googleapis.com/x', 'dois pontos seguidos');
recusa('https://fcm.googleapis.com', 'sem caminho');
recusa('https://fcm.googleapis.com/fcm/send/abc def', 'espaço no caminho');
recusa("https://fcm.googleapis.com/fcm/send/abc\r\nHost: evil.com", 'quebra de linha (injeção de cabeçalho)');
recusa("https://fcm.googleapis.com/x\0.evil.com", 'byte nulo');
recusa('https://fcm.googleapis.com/x#frag', 'fragmento');
recusa('https://fcm.googleapis.cöm/x', 'caractere não ASCII (IDN)');
recusa('https://xn--fcm-googleapis.com/x', 'punycode fora da lista');
recusa('https://fcm_googleapis.com/x', 'sublinhado no host');
recusa('', 'vazio');
recusa('https://', 'só o esquema');
recusa('https://fcm.googleapis.com/' . str_repeat('a', 600), 'mais de 600 caracteres');
recusa('lixo qualquer', 'texto que não é URL');
recusa('https://example.com/x', 'site comum');
recusa('https://webhook.site/abc', 'site de captura de requisições');

echo "\n--- modo de teste só existe na linha de comando com PUSH_MODO_TESTE=1\n";
recusa('http://127.0.0.1:8099/push/1', 'servidor falso SEM o modo de teste ligado');
putenv('PUSH_MODO_TESTE=1');
aceita('http://127.0.0.1:8099/push/1');
aceita('http://localhost:8099/push/1');
recusa('http://10.0.0.5:8099/push/1', 'no modo de teste só loopback passa (IP interno continua recusado)');
recusa('http://evil.com:8099/push/1', 'no modo de teste host de fora continua recusado');
putenv('PUSH_MODO_TESTE');
recusa('http://127.0.0.1:8099/push/1', 'de volta ao normal: recusa de novo');

echo PHP_EOL . "RESUMO: $ok ok, $falhas falhas" . PHP_EOL;
exit($falhas > 0 ? 1 : 0);
