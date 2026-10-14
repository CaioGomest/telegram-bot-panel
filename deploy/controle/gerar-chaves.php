<?php
declare(strict_types=1);

/**
 * gerar-chaves.php — cria o par de chaves da SUSPENSÃO REMOTA de uma instalação. Rode no SEU computador (PHP 8.1+ com openssl).
 *
 *   php deploy/controle/gerar-chaves.php NOME_DO_CLIENTE [--url=https://painel.cliente.com.br/controle] [--pasta=DIRETORIO]
 *
 * Cria a pasta  ~/.painel-controle/NOME_DO_CLIENTE/  (ou a de --pasta) com:
 *   privada.pem   a chave que ASSINA os comandos -- é o seu poder sobre o sistema. NUNCA vai para a VPS, para o GitHub nem para o
 *                 cliente. Guarde também uma cópia num cofre de senhas: sem ela você não consegue reativar um sistema suspenso.
 *   publica.txt   a chave que o servidor usa para CONFERIR os comandos (pode ser mostrada; ela não assina nada)
 *   id.txt        identificador desta instalação (o comando assinado só vale para ela)
 *   perfil.json   url do /controle + caminhos, lidos por enviar.php
 *
 * No fim imprime as duas linhas para o config.php do servidor (ou a opção do instalar.sh). Para refazer o par de um cliente que já
 * tem chave é preciso apagar a pasta dele de propósito (o script não sobrescreve).
 */

if (PHP_SAPI !== 'cli') {
    exit("Somente pela linha de comando.\n");
}
if (!function_exists('openssl_pkey_new')) {
    fwrite(STDERR, "A extensão openssl do PHP não está ativa.\n");
    exit(1);
}

$nome = '';
$url = '';
$pasta = '';
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--url=(.+)$/', $a, $m)) {
        $url = $m[1];
    } elseif (preg_match('/^--pasta=(.+)$/', $a, $m)) {
        $pasta = $m[1];
    } elseif ($a !== '' && $a[0] !== '-' && $nome === '') {
        $nome = $a;
    }
}
if ($nome === '' || !preg_match('/^[A-Za-z0-9._-]{1,40}$/', $nome)) {
    fwrite(STDERR, "Uso: php gerar-chaves.php NOME_DO_CLIENTE [--url=https://.../controle] [--pasta=DIR]\n(o nome só aceita letras, números, ponto, hífen e _)\n");
    exit(2);
}
$home = getenv('HOME') ?: getenv('USERPROFILE') ?: '.';
$dir = $pasta !== '' ? rtrim($pasta, '/\\') : rtrim($home, '/\\') . DIRECTORY_SEPARATOR . '.painel-controle' . DIRECTORY_SEPARATOR . $nome;
if (is_file($dir . DIRECTORY_SEPARATOR . 'privada.pem')) {
    fwrite(STDERR, "Já existe uma chave em $dir -- não vou sobrescrever (apague a pasta se for mesmo refazer; o servidor precisará da chave nova).\n");
    exit(1);
}

$cfg = ['private_key_bits' => 3072, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
$res = @openssl_pkey_new($cfg);
if (!$res) {
    // PHP do Windows (XAMPP) às vezes não acha o openssl.cnf
    foreach (array_filter([getenv('OPENSSL_CONF') ?: null, dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf', 'C:/xampp/php/extras/ssl/openssl.cnf', 'C:/Program Files/Git/usr/ssl/openssl.cnf']) as $c) {
        if (is_file($c) && ($res = @openssl_pkey_new($cfg + ['config' => $c]))) {
            $cfg['config'] = $c;
            break;
        }
    }
}
if (!$res) {
    fwrite(STDERR, "Não consegui gerar a chave: " . (openssl_error_string() ?: 'erro do openssl') . "\n(No Windows, aponte OPENSSL_CONF para um openssl.cnf e tente de novo.)\n");
    exit(1);
}
openssl_pkey_export($res, $privada, null, isset($cfg['config']) ? ['config' => $cfg['config']] : []);
$det = openssl_pkey_get_details($res);
$publica_pem = (string) $det['key'];
$publica_b64 = preg_replace('/-----[A-Z ]+-----|\s+/', '', $publica_pem);
$id = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');

if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
    fwrite(STDERR, "Não consegui criar $dir\n");
    exit(1);
}
file_put_contents($dir . DIRECTORY_SEPARATOR . 'privada.pem', $privada);
@chmod($dir . DIRECTORY_SEPARATOR . 'privada.pem', 0600);
file_put_contents($dir . DIRECTORY_SEPARATOR . 'publica.txt', $publica_b64 . "\n");
file_put_contents($dir . DIRECTORY_SEPARATOR . 'id.txt', $id . "\n");
file_put_contents($dir . DIRECTORY_SEPARATOR . 'perfil.json', json_encode(['nome' => $nome, 'url' => $url, 'id' => $id], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

echo "\nChaves criadas em: $dir\n";
echo "  privada.pem  -> SECRETA. Faça uma cópia de segurança num cofre de senhas. Nunca envie ao servidor/GitHub/cliente.\n";
echo "  (no Windows o chmod 600 não existe: deixe a pasta dentro do seu perfil de usuário)\n\n";
echo "Coloque estas duas linhas no config.php do servidor (o instalar.sh faz isso com --controle-id e --controle-chave-publica):\n\n";
echo "define('CONTROLE_ID', '$id');\n";
echo "define('CONTROLE_CHAVE_PUBLICA', '$publica_b64');\n\n";
echo "Para o instalar.sh:\n  --controle-id '$id' --controle-chave-publica '$publica_b64'\n\n";
echo "Depois, para suspender/reativar:\n  php deploy/controle/enviar.php $nome suspender\n  php deploy/controle/enviar.php $nome reativar\n  php deploy/controle/enviar.php $nome consultar\n";
if ($url === '') {
    echo "\n(Falta a URL: edite $dir" . DIRECTORY_SEPARATOR . "perfil.json e preencha \"url\" com https://SEU-DOMINIO/controle)\n";
}
