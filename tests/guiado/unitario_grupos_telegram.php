<?php
declare(strict_types=1);

/**
 * Registro de grupo pelo ID/@ (funcoes/grupos_telegram.php): o texto digitado vira chat_id, o bot só conta se for dono ou administrador com
 * "convidar usuários", e o grupo entra em bot_grupos para cada bot que passa. SQLite em memória e Telegram falso (sem rede, sem MySQL).
 *   php tests/guiado/unitario_grupos_telegram.php
 */

$ok = 0;
$falhas = 0;
function confere(string $nome, bool $certo, string $detalhe = ''): void
{
    global $ok, $falhas;
    $certo ? $ok++ : $falhas++;
    echo ($certo ? 'OK     ' : 'FALHOU ') . $nome . ($certo || $detalhe === '' ? '' : '  -> ' . $detalhe) . PHP_EOL;
}

require_once dirname(__DIR__, 2) . '/funcoes/grupos_telegram.php';

// ===== normalizarIdentificadorGrupo =====
$casos = [
    ['-1001234567890', '-1001234567890'],
    ['  -1001234567890  ', '-1001234567890'],
    ['-4567890123', '-4567890123'],
    ['@meucanal', '@meucanal'],
    ['https://t.me/meucanal', '@meucanal'],
    ['t.me/meucanal/12', '@meucanal'],
    ['http://telegram.me/Meu_Canal?start=1', '@Meu_Canal'],
    ['https://t.me/c/1234567890/5', '-1001234567890'],
    ['https://t.me/c/1234567890', '-1001234567890'],
    ['1234567890', ''],            // ID positivo é de pessoa, não de grupo
    ['123', ''],
    ['meucanal', ''],              // sem @ não dá para saber se é @ ou lixo
    ['@ab', ''],
    ['@1abcde', ''],
    ['https://t.me/+AbCdEfGh', ''],
    ['https://t.me/joinchat/AAAAAEabc', ''],
    ['', ''],
    ['   ', ''],
    ["-100123\n4567890", ''],
    ['-100 1234567890', ''],
    [str_repeat('a', 300), ''],
];
foreach ($casos as [$entrada, $esperado]) {
    $r = normalizarIdentificadorGrupo((string) $entrada);
    confere('normalizar ' . json_encode(strlen($entrada) > 40 ? substr($entrada, 0, 40) . '…' : $entrada, JSON_UNESCAPED_UNICODE) . ' => ' . json_encode($esperado), $r === $esperado, json_encode($r));
}

// ===== avaliarBotNoGrupo =====
$avaliacoes = [
    [['status' => 'creator'], true, 'ok'],
    [['status' => 'administrator', 'can_invite_users' => true], true, 'ok'],
    [['status' => 'administrator', 'can_invite_users' => false], false, 'sem_convite'],
    [['status' => 'administrator'], false, 'sem_convite'],
    [['status' => 'member'], false, 'sem_admin'],
    [['status' => 'restricted'], false, 'sem_admin'],
    [['status' => 'left'], false, 'fora'],
    [['status' => 'kicked'], false, 'fora'],
    [[], false, 'fora'],
];
foreach ($avaliacoes as [$membro, $ok_esperado, $motivo_esperado]) {
    $r = avaliarBotNoGrupo($membro);
    confere('avaliar ' . json_encode($membro) . ' => ' . ($ok_esperado ? 'aceita' : 'recusa (' . $motivo_esperado . ')'), $r['ok'] === $ok_esperado && $r['motivo'] === $motivo_esperado, json_encode($r));
}

// ===== registrarGrupoPorIdentificador =====
function novoBanco(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('CREATE TABLE bots (id INTEGER PRIMARY KEY, id_usuario INT, nome_usuario TEXT, token TEXT, id_fluxo_conectado INT NULL)');
    $pdo->exec('CREATE TABLE bot_grupos (id INTEGER PRIMARY KEY AUTOINCREMENT, bot_id INT NOT NULL, id_telegram TEXT NOT NULL, titulo TEXT, tipo TEXT, UNIQUE (bot_id, id_telegram))');
    return $pdo;
}
function bot(PDO $pdo, int $id, int $usuario, string $nome, ?int $fluxo = null): string
{
    $token = (9000 + $id) . ':SEGREDO' . $id . 'xyz';   // o ID do bot no Telegram (antes dos dois pontos) é 9000 + o id da linha
    $pdo->prepare('INSERT INTO bots (id, id_usuario, nome_usuario, token, id_fluxo_conectado) VALUES (?, ?, ?, ?, ?)')->execute([$id, $usuario, $nome, $token, $fluxo]);
    return $token;
}
/** Telegram falso: $respostas["<id da linha do bot>|<método>"] = resposta; o que não está combinado volta como "chat not found". Anota todas as chamadas. */
function telegramFalso(array $respostas, array &$chamadas): callable
{
    return function (string $token, string $metodo, array $parametros = []) use ($respostas, &$chamadas): array {
        $id_bot = (string) ((int) strtok($token, ':') - 9000);
        $chamadas[] = ['bot' => $id_bot, 'metodo' => $metodo, 'parametros' => $parametros];
        return $respostas[$id_bot . '|' . $metodo] ?? ['ok' => false, 'description' => 'Bad Request: chat not found'];
    };
}
$grupo_ok = ['ok' => true, 'result' => ['id' => -1001234567890, 'title' => 'VIP do Gomes', 'type' => 'supergroup']];
$admin = ['ok' => true, 'result' => ['status' => 'administrator', 'can_invite_users' => true]];
function linhas(PDO $pdo): array
{
    return $pdo->query('SELECT bot_id, id_telegram, titulo, tipo FROM bot_grupos ORDER BY bot_id, id_telegram')->fetchAll();
}

// texto que não dá para usar: nem chega ao Telegram
$pdo = novoBanco();
bot($pdo, 1, 10, 'meubot');
$chamadas = [];
$r = registrarGrupoPorIdentificador($pdo, 10, 'abc', telegramFalso([], $chamadas));
confere('texto inválido: recusa com a explicação dos formatos e sem falar com o Telegram', $r['sucesso'] === false && str_contains($r['mensagem'], '-1001234567890') && !$chamadas && !linhas($pdo), json_encode($r));

// sem bot
$pdo = novoBanco();
bot($pdo, 5, 99, 'bot_de_outro');
$chamadas = [];
$r = registrarGrupoPorIdentificador($pdo, 10, '-1001234567890', telegramFalso([], $chamadas));
confere('conta sem bot: avisa que precisa criar um bot (e não usa o bot de outra conta)', $r['sucesso'] === false && str_contains($r['mensagem'], 'ainda não tem nenhum bot') && !$chamadas);

// caminho feliz
$pdo = novoBanco();
$token = bot($pdo, 1, 10, 'meubot');
$chamadas = [];
$r = registrarGrupoPorIdentificador($pdo, 10, ' -1001234567890 ', telegramFalso(['1|getChat' => $grupo_ok, '1|getChatMember' => $admin], $chamadas));
confere('bot administrador com convite: sucesso, com o título e o bot na mensagem', $r['sucesso'] === true && $r['grupo']['titulo'] === 'VIP do Gomes' && $r['bots'] === ['@meubot'] && str_contains($r['mensagem'], 'VIP do Gomes'), json_encode($r));
confere('grava em bot_grupos: bot, id do chat, título e tipo', linhas($pdo) === [['bot_id' => 1, 'id_telegram' => '-1001234567890', 'titulo' => 'VIP do Gomes', 'tipo' => 'supergroup']], json_encode(linhas($pdo)));
confere('pergunta ao Telegram: getChat com o ID digitado e getChatMember com o ID do PRÓPRIO bot (o número antes dos dois pontos do token)', count($chamadas) === 2 && $chamadas[0]['metodo'] === 'getChat' && $chamadas[0]['parametros']['chat_id'] === '-1001234567890' && $chamadas[1]['metodo'] === 'getChatMember' && $chamadas[1]['parametros']['user_id'] === 9001, json_encode($chamadas));
confere('a resposta nunca carrega o token do bot', !str_contains(json_encode($r), 'SEGREDO') && !str_contains(json_encode($r), $token));

// de novo, com o título mudado: atualiza, não duplica
$grupo_novo_titulo = ['ok' => true, 'result' => ['id' => -1001234567890, 'title' => 'VIP renomeado', 'type' => 'supergroup']];
$r = registrarGrupoPorIdentificador($pdo, 10, '-1001234567890', telegramFalso(['1|getChat' => $grupo_novo_titulo, '1|getChatMember' => $admin], $chamadas));
confere('repetir o mesmo grupo atualiza o título e NÃO duplica a linha', $r['sucesso'] === true && count(linhas($pdo)) === 1 && linhas($pdo)[0]['titulo'] === 'VIP renomeado', json_encode(linhas($pdo)));

// link de mensagem de grupo privado vira -100…
$pdo = novoBanco();
bot($pdo, 1, 10, 'meubot');
$chamadas = [];
registrarGrupoPorIdentificador($pdo, 10, 'https://t.me/c/1234567890/77', telegramFalso(['1|getChat' => $grupo_ok, '1|getChatMember' => $admin], $chamadas));
confere('link de mensagem t.me/c/1234567890/77 consulta o chat -1001234567890', ($chamadas[0]['parametros']['chat_id'] ?? '') === '-1001234567890', json_encode($chamadas[0] ?? null));

// canal público por @
$pdo = novoBanco();
bot($pdo, 1, 10, 'meubot');
$chamadas = [];
$canal = ['ok' => true, 'result' => ['id' => -1009876543210, 'title' => 'Canal VIP', 'type' => 'channel']];
$r = registrarGrupoPorIdentificador($pdo, 10, '@canalvip', telegramFalso(['1|getChat' => $canal, '1|getChatMember' => ['ok' => true, 'result' => ['status' => 'creator']]], $chamadas));
confere('canal por @canalvip, bot dono: registra com o ID numérico real e tipo channel', $r['sucesso'] === true && ($chamadas[0]['parametros']['chat_id'] ?? '') === '@canalvip' && linhas($pdo) === [['bot_id' => 1, 'id_telegram' => '-1009876543210', 'titulo' => 'Canal VIP', 'tipo' => 'channel']], json_encode(linhas($pdo)));

// motivos de recusa
$cenarios = [
    ['bot só membro', ['status' => 'member'], 'não é administrador'],
    ['administrador sem convidar', ['status' => 'administrator', 'can_invite_users' => false], 'permissão de convidar'],
    ['bot saiu/foi banido', ['status' => 'left'], 'não está nesse grupo'],
];
foreach ($cenarios as [$nome, $resultado_membro, $trecho]) {
    $pdo = novoBanco();
    bot($pdo, 1, 10, 'meubot');
    $chamadas = [];
    $r = registrarGrupoPorIdentificador($pdo, 10, '-1001234567890', telegramFalso(['1|getChat' => $grupo_ok, '1|getChatMember' => ['ok' => true, 'result' => $resultado_membro]], $chamadas));
    confere("recusa ($nome): nada é gravado e a mensagem cita @meubot e \"$trecho\"", $r['sucesso'] === false && !linhas($pdo) && str_contains($r['mensagem'], '@meubot') && str_contains($r['mensagem'], $trecho), json_encode($r));
}

// o Telegram não acha o grupo (bot nunca esteve lá / ID errado)
$pdo = novoBanco();
bot($pdo, 1, 10, 'meubot');
$chamadas = [];
$r = registrarGrupoPorIdentificador($pdo, 10, '-1001234567890', telegramFalso([], $chamadas));
confere('grupo que o Telegram não conhece: mensagem geral (confira o ID, adicione o bot como administrador) e nada gravado', $r['sucesso'] === false && str_contains($r['mensagem'], 'Não encontrei esse grupo') && !linhas($pdo), json_encode($r));

// getChat ok mas getChatMember falha (bot fora do grupo público)
$pdo = novoBanco();
bot($pdo, 1, 10, 'meubot');
$chamadas = [];
$r = registrarGrupoPorIdentificador($pdo, 10, '@grupopublico', telegramFalso(['1|getChat' => $grupo_ok], $chamadas));
confere('grupo público existe mas o bot não está nele (getChatMember falha): "não está nesse grupo"', $r['sucesso'] === false && str_contains($r['mensagem'], '@meubot') && str_contains($r['mensagem'], 'não está nesse grupo'), json_encode($r));

// ID de conversa privada
$pdo = novoBanco();
bot($pdo, 1, 10, 'meubot');
$chamadas = [];
$r = registrarGrupoPorIdentificador($pdo, 10, '-12345678', telegramFalso(['1|getChat' => ['ok' => true, 'result' => ['id' => 123456789, 'type' => 'private', 'first_name' => 'Ana']]], $chamadas));
confere('ID que o Telegram diz ser de uma pessoa: recusa dizendo que não é grupo e não pergunta mais nada', $r['sucesso'] === false && str_contains($r['mensagem'], 'não de um grupo') && count($chamadas) === 1 && !linhas($pdo), json_encode($r));

// vários bots: só entra o que é administrador; o bot do fluxo aberto é consultado primeiro; bot de outra conta nunca
$pdo = novoBanco();
bot($pdo, 1, 10, 'primeiro');
bot($pdo, 2, 10, 'do_fluxo', 77);
bot($pdo, 3, 10, 'so_membro');
bot($pdo, 4, 99, 'de_outra_conta');
$chamadas = [];
$r = registrarGrupoPorIdentificador($pdo, 10, '-1001234567890', telegramFalso([
    '1|getChat' => $grupo_ok, '1|getChatMember' => $admin,
    '2|getChat' => $grupo_ok, '2|getChatMember' => $admin,
    '3|getChat' => $grupo_ok, '3|getChatMember' => ['ok' => true, 'result' => ['status' => 'member']],
    '4|getChat' => $grupo_ok, '4|getChatMember' => $admin,
], $chamadas), 77);
confere('vários bots: registra o grupo para os 2 que são administradores e não para o que é só membro', $r['sucesso'] === true && array_column(linhas($pdo), 'bot_id') === [1, 2] && $r['bots'] === ['@do_fluxo', '@primeiro'], json_encode([linhas($pdo), $r['bots'] ?? null]));
confere('o bot ligado ao fluxo aberto (77) é o primeiro a ser consultado', ($chamadas[0]['bot'] ?? '') === '2', json_encode($chamadas[0] ?? null));
confere('bot de outra conta nunca é consultado', !in_array('4', array_column($chamadas, 'bot'), true), json_encode(array_column($chamadas, 'bot')));

// limite de bots consultados (cada um custa 1 a 2 chamadas à rede)
$pdo = novoBanco();
for ($i = 1; $i <= 14; $i++) {
    bot($pdo, $i, 10, 'b' . $i);
}
$chamadas = [];
registrarGrupoPorIdentificador($pdo, 10, '-1001234567890', telegramFalso([], $chamadas));
confere('conta com 14 bots: consulta no máximo ' . GRUPO_BOTS_CONSULTADOS_MAX . ' (limite para não demorar)', count($chamadas) === GRUPO_BOTS_CONSULTADOS_MAX, (string) count($chamadas));

// prioridade das mensagens de falha
confere('mensagemFalhaGrupo: sem convite vence sem admin, que vence fora', str_contains(mensagemFalhaGrupo(['@a' => 'fora', '@b' => 'sem_admin', '@c' => 'sem_convite']), '@c') && str_contains(mensagemFalhaGrupo(['@a' => 'fora', '@b' => 'sem_admin']), '@b') && str_contains(mensagemFalhaGrupo(['@a' => 'fora']), '@a'));

echo PHP_EOL . $ok . ' ok, ' . $falhas . ' falha(s)' . PHP_EOL;
exit($falhas ? 1 : 0);
