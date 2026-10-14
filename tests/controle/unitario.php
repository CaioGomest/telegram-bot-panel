<?php
declare(strict_types=1);

/**
 * Teste da suspensão remota (funcoes/controle.php + deploy/controle/*.php), sem servidor e sem banco:
 *   php tests/controle/unitario.php
 * Monta uma cópia isolada em pasta temporária (a guarda grava em storage/ e logs/ relativos ao arquivo), gera um par de chaves e passa
 * comandos assinados -- bons e ruins -- por controleProcessarComando(). Mostra PASSOU/FALHOU por caso e sai com código 1 se algo falhou.
 * (A guarda HTTP -- 503 nas páginas, webhooks liberados -- só dá para testar com um servidor web: ver anotacoes/TESTES.md.)
 */

$raiz = dirname(__DIR__, 2);
$cfg_ssl = null;
foreach (array_filter([getenv('OPENSSL_CONF') ?: null, dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf', 'C:/Program Files/Git/usr/ssl/openssl.cnf']) as $c) {
    if (is_file($c)) { $cfg_ssl = $c; break; }   // PHP do Windows (XAMPP) muitas vezes não acha o openssl.cnf sozinho
}

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ctl_' . bin2hex(random_bytes(4));
mkdir($tmp . '/funcoes', 0777, true);
mkdir($tmp . '/storage');
mkdir($tmp . '/logs');
copy($raiz . '/funcoes/controle.php', $tmp . '/funcoes/controle.php');

$passou = 0;
$falhou = 0;
function confere(string $nome, bool $ok, string $detalhe = ''): void
{
    global $passou, $falhou;
    if ($ok) { $passou++; echo "  PASSOU  $nome\n"; } else { $falhou++; echo "  FALHOU  $nome" . ($detalhe !== '' ? " -- $detalhe" : '') . "\n"; }
}
function novoPar(?string $cfg_ssl): array
{
    $o = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA] + ($cfg_ssl ? ['config' => $cfg_ssl] : []);
    $r = openssl_pkey_new($o);
    openssl_pkey_export($r, $priv, null, $cfg_ssl ? ['config' => $cfg_ssl] : []);
    return [$priv, openssl_pkey_get_details($r)['key']];
}

[$priv, $pub] = novoPar($cfg_ssl);
[$priv_outra, ] = novoPar($cfg_ssl);
$id = 'inst-teste-0001';
$pub_b64 = preg_replace('/-----[A-Z ]+-----|\s+/', '', $pub);

/** Mesma função do enviar.php, extraída do próprio arquivo (garante que as duas montagens do texto assinado são idênticas). */
$fonte_enviar = file_get_contents($raiz . '/deploy/controle/enviar.php');
preg_match('/function textoAssinado\(array \$c\): string\s*\{.*?\n\}/s', $fonte_enviar, $m);
eval($m[0]);

function assina(array $c, $privada): array
{
    openssl_sign(textoAssinado($c), $sig, $privada, OPENSSL_ALGO_SHA256);
    $c['assinatura'] = base64_encode($sig);
    return $c;
}
function cmd(string $estado, int $emitido, array $extra = []): array
{
    global $id;
    return array_merge(['id' => $id, 'estado' => $estado, 'modo' => 'painel', 'emitido_em' => $emitido, 'valido_ate' => 0, 'nonce' => bin2hex(random_bytes(8)), 'mensagem' => ''], $extra);
}

// ---- 1. recurso desligado (sem constantes e sem arquivo de chave)
require $tmp . '/funcoes/controle.php';
[$st, $r] = controleProcessarComando('{}');
confere('sem CONTROLE_ID/CHAVE: responde 503 nao_configurado', $st === 503 && ($r['erro'] ?? '') === 'nao_configurado');
confere('sem configuração: estado efetivo é ativo', controleEstadoEfetivo()['estado'] === 'ativo');

// ---- configura (o arquivo de storage faz o papel das constantes; as constantes do config.php têm a mesma semântica)
file_put_contents($tmp . '/storage/controle_chave.json', json_encode(['id' => $id, 'chave_publica' => $pub_b64]));
// controleConfig() guarda em static: roda os próximos casos em processos novos
file_put_contents($tmp . '/driver.php', <<<'PHP'
<?php
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
require __DIR__ . '/funcoes/controle.php';
if (($argv[1] ?? '') === 'processa') {
    [$s, $r] = controleProcessarComando((string) file_get_contents(__DIR__ . '/corpo.json'));
    echo json_encode([$s, $r]);
} else {
    echo json_encode(controleEstadoEfetivo());
}
PHP);
function processa(string $tmp, string $corpo): array
{
    file_put_contents($tmp . '/corpo.json', $corpo);
    return json_decode((string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmp . '/driver.php') . ' processa'), true) ?: [0, []];
}
function efetivo(string $tmp): array
{
    return json_decode((string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmp . '/driver.php') . ' efetivo'), true) ?: [];
}

$agora = time();
$c1 = assina(cmd('suspenso', $agora, ['modo' => 'total', 'mensagem' => 'Contrato em atraso']), $priv);
[$st, $r] = processa($tmp, json_encode($c1));
confere('comando válido de suspender é aplicado (200)', $st === 200 && ($r['ok'] ?? false) === true && $r['estado_efetivo'] === 'suspenso' && $r['modo'] === 'total', json_encode($r));
$e = efetivo($tmp);
confere('estado em vigor passa a suspenso/total com a mensagem', $e['estado'] === 'suspenso' && $e['modo'] === 'total' && $e['mensagem'] === 'Contrato em atraso');

[$st, $r] = processa($tmp, json_encode($c1));
confere('MESMO comando de novo (repetição) é recusado: 409 antigo', $st === 409 && ($r['erro'] ?? '') === 'antigo');

[$st, $r] = processa($tmp, json_encode(assina(cmd("ativo", $agora - 20), $priv)));
confere('comando assinado mais ANTIGO que o último é recusado (409), informando a hora do servidor para o operador saber quanto esperar', $st === 409 && isset($r['hora_servidor'], $r['ultimo_emitido_em']));
confere('...e o estado continua suspenso', efetivo($tmp)['estado'] === 'suspenso');

$adulterado = $c1;
$adulterado['emitido_em'] = $agora + 5;
[$st, ] = processa($tmp, json_encode($adulterado));
confere('campo alterado depois de assinar (emitido_em) é recusado (403)', $st === 403);
$adulterado = assina(cmd('suspenso', $agora + 1, ['mensagem' => 'a']), $priv);
$adulterado['mensagem'] = 'b';
[$st, ] = processa($tmp, json_encode($adulterado));
confere('mensagem trocada depois de assinar é recusada (403)', $st === 403);
$adulterado = assina(cmd('suspenso', $agora + 1), $priv);
$adulterado['estado'] = 'ativo';
[$st, ] = processa($tmp, json_encode($adulterado));
confere('estado trocado de suspenso para ativo depois de assinar é recusado (403)', $st === 403);

[$st, ] = processa($tmp, json_encode(assina(cmd('ativo', $agora + 1), $priv_outra)));
confere('assinado com OUTRA chave privada é recusado (403)', $st === 403);
[$st, ] = processa($tmp, json_encode(assina(cmd('ativo', $agora + 1, ['id' => 'outra-instalacao-9']), $priv)));
confere('assinatura certa mas ID de OUTRA instalação é recusado (403)', $st === 403);
[$st, ] = processa($tmp, json_encode(assina(cmd('ativo', $agora + 1), $priv) + ['x' => 1]) );
confere('campo extra não assinado é ignorado (não muda nada): comando segue válido (200)', $st === 200);

[$st, $r] = processa($tmp, json_encode(assina(cmd('ativo', $agora - 900), $priv)));
confere('assinatura boa mas relógio 15 min atrás: 403 relogio', $st === 403 && ($r['erro'] ?? '') === 'relogio');
[$st, $r] = processa($tmp, json_encode(assina(cmd('ativo', $agora + 900), $priv)));
confere('assinatura boa mas relógio 15 min à frente: 403 relogio', $st === 403 && ($r['erro'] ?? '') === 'relogio');

[$st, ] = processa($tmp, str_repeat('a', 5000));
confere('corpo acima de 4 KB: 413', $st === 413);
[$st, ] = processa($tmp, 'isto não é json');
confere('lixo no corpo: 400', $st === 400);
[$st, ] = processa($tmp, json_encode(['estado' => 'suspenso']));
confere('JSON sem campos obrigatórios: 400', $st === 400);
[$st, ] = processa($tmp, json_encode(assina(cmd('apagar-tudo', $agora + 2), $priv)));
confere('estado desconhecido é recusado (400)', $st === 400);
[$st, ] = processa($tmp, json_encode(assina(cmd('ativo', $agora + 2, ['modo' => 'x|y']), $priv)));
confere('modo com "|" (tentativa de embaralhar o texto assinado) é recusado (400)', $st === 400);

processa($tmp, json_encode(assina(cmd('suspenso', $agora + 2), $priv)));   // o caso anterior deixou o sistema ativo: suspende de novo para consultar
$ant = efetivo($tmp);
[$st, $r] = processa($tmp, json_encode(assina(cmd('consultar', $agora + 30), $priv)));
confere('consultar devolve o estado sem alterar nada (200)', $st === 200 && $r['estado_efetivo'] === 'suspenso' && efetivo($tmp) === $ant);
[$st, ] = processa($tmp, json_encode(assina(cmd('consultar', $agora + 30), $priv_outra)));
confere('consultar com chave errada: 403', $st === 403);

[$st, $r] = processa($tmp, json_encode(assina(cmd('ativo', $agora + 3), $priv)));
confere('reativar (comando mais novo) volta a ativo', $st === 200 && $r['estado_efetivo'] === 'ativo' && efetivo($tmp)['estado'] === 'ativo');

// ---- prazos
processa($tmp, json_encode(assina(cmd('suspenso', $agora + 4, ['valido_ate' => $agora - 5]), $priv)));
$e = efetivo($tmp);
confere('suspensão com prazo vencido volta a ATIVO sozinha', $e['estado'] === 'ativo' && $e['motivo'] === 'suspensao_expirou', json_encode($e));
processa($tmp, json_encode(assina(cmd('suspenso', $agora + 5, ['valido_ate' => $agora + 3600]), $priv)));
confere('suspensão com prazo no futuro continua suspensa', efetivo($tmp)['estado'] === 'suspenso');
processa($tmp, json_encode(assina(cmd('ativo', $agora + 6, ['valido_ate' => $agora - 5, 'modo' => 'painel']), $priv)));
$e = efetivo($tmp);
confere('licença (ativo com prazo) vencida SUSPENDE sozinha', $e['estado'] === 'suspenso' && $e['motivo'] === 'licenca_vencida' && $e['modo'] === 'painel', json_encode($e));
processa($tmp, json_encode(assina(cmd('ativo', $agora + 7, ['valido_ate' => $agora + 86400]), $priv)));
confere('licença renovada (prazo no futuro) fica ativa', efetivo($tmp)['estado'] === 'ativo');

// ---- arquivo de estado corrompido não pode suspender o sistema
file_put_contents($tmp . '/storage/controle_estado.json', '{lixo');
confere('arquivo de estado corrompido => ativo (nunca suspende por defeito)', efetivo($tmp)['estado'] === 'ativo');
unlink($tmp . '/storage/controle_estado.json');
confere('sem arquivo de estado => ativo', efetivo($tmp)['estado'] === 'ativo');

// ---- log
$log = (string) file_get_contents($tmp . '/logs/controle.log');
confere('log registra os comandos aplicados e as recusas (com o IP)', str_contains($log, 'APLICADO') && str_contains($log, 'recusado: assinatura inválida') && str_contains($log, 'ip=203.0.113.7'));
confere('log não contém a assinatura nem a chave', !str_contains($log, 'assinatura":') && !str_contains($log, $pub_b64));

// ---- limpeza
foreach (glob($tmp . '/{,*/}*', GLOB_BRACE) ?: [] as $f) { if (is_file($f)) { @unlink($f); } }
@rmdir($tmp . '/funcoes'); @rmdir($tmp . '/storage'); @rmdir($tmp . '/logs'); @rmdir($tmp);

echo "\nRESUMO: $passou passou, $falhou falhou\n";
exit($falhou === 0 ? 0 : 1);
