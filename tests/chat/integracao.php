<?php
declare(strict_types=1);

// Roda DENTRO da cópia do painel (tests/chat/rodar.js a copia para <cópia>/_teste/ e liga o banco e o Telegram falso).
// Cobre funcoes/chat.php e ajax/chat_*.php contra um MariaDB de verdade.
chdir(__DIR__ . '/..');
date_default_timezone_set('America/Sao_Paulo');
require_once 'conexao.php';
require_once 'funcoes/chat.php';
require_once 'funcoes/limite_taxa.php';

$ok = 0;
$falhas = 0;
function chk(string $nome, bool $condicao, $detalhe = null): void
{
    global $ok, $falhas;
    $condicao ? $ok++ : $falhas++;
    echo ($condicao ? 'OK     ' : 'FALHOU ') . $nome . ($condicao || $detalhe === null ? '' : '  -> ' . json_encode($detalhe, JSON_UNESCAPED_UNICODE)) . "\n";
    flush(); // no Windows a saída redirecionada fica em buffer: sem isto, matar o processo no meio perde as últimas linhas
}
function q1(string $sql, array $p = [])
{
    global $pdo;
    $s = $pdo->prepare($sql);
    $s->execute($p);
    return $s->fetchColumn();
}
function linhas(int $bot, string $tg): array
{
    global $pdo;
    $s = $pdo->prepare('SELECT id, direcao, origem, tipo, texto, criado_em FROM chat_mensagens WHERE bot_id = ? AND id_telegram = ? ORDER BY id');
    $s->execute([$bot, $tg]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}
function inserts(): int
{
    global $pdo;
    return (int) $pdo->query("SHOW GLOBAL STATUS LIKE 'Com_insert'")->fetch(PDO::FETCH_NUM)[1];
}
function texto(int $chat, string $t, string $tipo_chat = 'private', ?int $data = null): array
{
    return ['ok' => true, 'result' => ['message_id' => random_int(1, 99999), 'chat' => ['id' => $chat, 'type' => $tipo_chat], 'date' => $data ?? time(), 'text' => $t]];
}
function limpar(): void
{
    global $pdo;
    $pdo->exec('DELETE FROM chat_mensagens');
}
function endpoint(array $cfg): array
{
    // proc_open com a saída num ARQUIVO (sem cano e sem cmd.exe) e tempo limite: o exec() do PHP no Windows podia ficar esperando
    // um cano que não fechava (o pai parava sem nenhum filho vivo), travando a bateria inteira.
    $base = sys_get_temp_dir() . '/chat_ep_' . bin2hex(random_bytes(4));
    file_put_contents($base . '.json', json_encode($cfg));
    $proc = proc_open([PHP_BINARY, __DIR__ . '/endpoint.php', $base . '.json'], [0 => ['file', 'NUL', 'r'], 1 => ['file', $base . '.out', 'w'], 2 => ['redirect', 1]], $canos);
    $limite = microtime(true) + 60;
    $estourou = false;
    while (is_resource($proc) && proc_get_status($proc)['running']) {
        if (microtime(true) > $limite) {
            proc_terminate($proc);
            $estourou = true;
            break;
        }
        usleep(20000);
    }
    if (is_resource($proc)) {
        proc_close($proc);
    }
    $bruto = $estourou ? 'TEMPO ESGOTADO (60 s)' : trim((string) @file_get_contents($base . '.out'));
    @unlink($base . '.json');
    @unlink($base . '.out');
    [$codigo, $corpo] = array_pad(explode("\n", $bruto, 2), 2, '');
    return ['http' => (int) $codigo, 'json' => json_decode($corpo, true), 'bruto' => $bruto];
}

// ---------------------------------------------------------------------------------------------------- dados
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0; DELETE FROM chat_mensagens; DELETE FROM leads; DELETE FROM bots; DELETE FROM usuarios; DELETE FROM limites_taxa; SET FOREIGN_KEY_CHECKS = 1;');
$u = [];
foreach (['a', 'b', 'd'] as $l) {
    $pdo->prepare('INSERT INTO usuarios (nome, email, senha) VALUES (?, ?, ?)')->execute(['Usuario ' . $l, $l . '@teste.local', 'x']);
    $u[$l] = (int) $pdo->lastInsertId();
}
$bot = [];
foreach (['a' => 'TOKEN_A', 'b' => 'TOKEN_B', 'd' => 'TOKEN_D'] as $l => $token) {
    $pdo->prepare('INSERT INTO bots (id_usuario, token, nome_usuario) VALUES (?, ?, ?)')->execute([$u[$l], $token, 'bot_' . $l]);
    $bot[$l] = (int) $pdo->lastInsertId();
}
$lead = $pdo->prepare('INSERT INTO leads (id_telegram, nome, bot_id) VALUES (?, ?, ?)');
$lead->execute(['900001', 'Bruno', $bot['a']]);
$lead->execute(['900403', 'Bloqueado', $bot['a']]);
$lead->execute(['900001', 'Bruno do B', $bot['b']]);
$lead->execute(['900001', 'Bruno do D', $bot['d']]);
echo "== dados: usuários {$u['a']}/{$u['b']}/{$u['d']}, bots {$bot['a']}/{$bot['b']}/{$bot['d']}\n";

// ---------------------------------------------------------------------------------------------------- A. gancho de saída
echo "\n== A. Saída: registrarRespostaTelegram() com o nome do método\n";
$data_fixa = 1760000000; // 2025-10-09 ~09:33 UTC
registrarRespostaTelegram('TOKEN_A', texto(900001, 'Olá, tudo bem?', 'private', $data_fixa), 'sendMessage');
chatDescarregar();
$l = linhas($bot['a'], '900001');
chk('sendMessage aceito vira 1 linha: bot, lead, direção "enviada", origem automática, tipo texto', count($l) === 1 && (int) $l[0]['direcao'] === 1 && (int) $l[0]['origem'] === 0 && $l[0]['tipo'] === 'texto' && $l[0]['texto'] === 'Olá, tudo bem?', $l);
chk('o horário gravado é o da resposta do Telegram (date), não o da gravação', $l && $l[0]['criado_em'] === date('Y-m-d H:i:s', $data_fixa), $l);

limpar();
registrarRespostaTelegram('TOKEN_A', ['ok' => true, 'result' => ['message_id' => 1, 'chat' => ['id' => 900001, 'type' => 'private'], 'date' => time(), 'caption' => 'Olha essa foto', 'photo' => [['file_id' => 'a'], ['file_id' => 'b']]]], 'sendPhoto');
registrarRespostaTelegram('TOKEN_A', ['ok' => true, 'result' => ['message_id' => 2, 'chat' => ['id' => 900001, 'type' => 'private'], 'date' => time(), 'caption' => 'Vídeo!', 'video' => ['file_id' => 'v']]], 'sendVideo');
registrarRespostaTelegram('TOKEN_A', ['ok' => true, 'result' => ['message_id' => 3, 'chat' => ['id' => 900001, 'type' => 'private'], 'date' => time(), 'voice' => ['file_id' => 'a']]], 'sendVoice');
registrarRespostaTelegram('TOKEN_A', ['ok' => true, 'result' => ['message_id' => 4, 'chat' => ['id' => 900001, 'type' => 'private'], 'date' => time(), 'document' => ['file_id' => 'd']]], 'sendDocument');
chatDescarregar();
$l = linhas($bot['a'], '900001');
chk('foto, vídeo, áudio e documento: tipo certo e legenda guardada', array_column($l, 'tipo') === ['foto', 'video', 'audio', 'documento'] && $l[0]['texto'] === 'Olha essa foto' && $l[1]['texto'] === 'Vídeo!' && $l[2]['texto'] === '', $l);

limpar();
registrarRespostaTelegram('TOKEN_A', ['ok' => true, 'result' => [
    ['message_id' => 1, 'chat' => ['id' => 900001, 'type' => 'private'], 'date' => time(), 'photo' => [['file_id' => 'a']], 'caption' => 'um'],
    ['message_id' => 2, 'chat' => ['id' => 900001, 'type' => 'private'], 'date' => time(), 'photo' => [['file_id' => 'b']]],
]], 'sendMediaGroup');
chatDescarregar();
chk('sendMediaGroup (lista de mensagens) vira uma linha por mensagem', count(linhas($bot['a'], '900001')) === 2);

limpar();
registrarRespostaTelegram('TOKEN_A', texto(900001, 'edição'), 'editMessageReplyMarkup');
registrarRespostaTelegram('TOKEN_A', ['ok' => true, 'result' => true], 'answerCallbackQuery');
registrarRespostaTelegram('TOKEN_A', ['ok' => true, 'result' => true], 'sendChatAction');
registrarRespostaTelegram('TOKEN_A', texto(-100123, 'no grupo', 'supergroup'), 'sendMessage');
registrarRespostaTelegram('TOKEN_A', ['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: chat not found'], 'sendMessage');
registrarRespostaTelegram('TOKEN_INEXISTENTE', texto(900001, 'bot desconhecido'), 'sendMessage');
registrarRespostaTelegram('TOKEN_A', texto(900001, 'sem método informado'));
chatDescarregar();
chk('NÃO registra: edição de botões, answerCallbackQuery, sendChatAction, mensagem em grupo, resposta com erro, token desconhecido, chamada sem método', (int) q1('SELECT COUNT(*) FROM chat_mensagens') === 0, q1('SELECT COUNT(*) FROM chat_mensagens'));

limpar();
registrarRespostaTelegram('TOKEN_A', texto(900001, str_repeat('ã', 3000)), 'sendMessage');
chatDescarregar();
$l = linhas($bot['a'], '900001');
chk('texto longo é cortado em ' . CHAT_TEXTO_MAX . ' caracteres sem quebrar a acentuação', $l && mb_strlen($l[0]['texto']) === CHAT_TEXTO_MAX && $l[0]['texto'] === str_repeat('ã', CHAT_TEXTO_MAX), $l ? mb_strlen($l[0]['texto']) : null);

// ---------------------------------------------------------------------------------------------------- B. entrada
echo "\n== B. Entrada: chatRegistrarRecebida() com a atualização crua do Telegram\n";
limpar();
$priv = ['id' => 900001, 'type' => 'private'];
chatRegistrarRecebida($bot['a'], ['message' => ['message_id' => 1, 'chat' => $priv, 'date' => $data_fixa, 'text' => 'Oi']]);
chatRegistrarRecebida($bot['a'], ['message' => ['message_id' => 2, 'chat' => $priv, 'date' => $data_fixa + 5, 'photo' => [['file_id' => 'x']], 'caption' => 'meu comprovante']]);
chatRegistrarRecebida($bot['a'], ['message' => ['message_id' => 3, 'chat' => $priv, 'date' => $data_fixa + 9, 'contact' => ['phone_number' => '5511999999999']]]);
chatRegistrarRecebida($bot['a'], ['message' => ['message_id' => 4, 'chat' => $priv, 'date' => $data_fixa + 12, 'sticker' => ['file_id' => 's']]]);
chatRegistrarRecebida($bot['a'], ['callback_query' => ['id' => 'c1', 'data' => 'plano_1', 'message' => ['message_id' => 9, 'chat' => $priv, 'reply_markup' => ['inline_keyboard' => [[['text' => 'Ver planos', 'callback_data' => 'ver'], ['text' => 'Quero o plano VIP', 'callback_data' => 'plano_1']]]]]]]);
chatRegistrarRecebida($bot['a'], ['callback_query' => ['id' => 'c2', 'data' => 'saida::x', 'message' => ['message_id' => 10, 'chat' => $priv]]]);
chatRegistrarRecebida($bot['a'], ['message' => ['message_id' => 5, 'chat' => ['id' => -100200, 'type' => 'group'], 'text' => 'mensagem de grupo']]);
chatRegistrarRecebida($bot['a'], ['my_chat_member' => ['chat' => $priv]]);
chatRegistrarRecebida($bot['a'], []);
chatDescarregar();
$l = linhas($bot['a'], '900001');
chk('mensagem do lead: direção "recebida", tipo texto, horário da mensagem', count($l) >= 1 && (int) $l[0]['direcao'] === 0 && $l[0]['tipo'] === 'texto' && $l[0]['texto'] === 'Oi' && $l[0]['criado_em'] === date('Y-m-d H:i:s', $data_fixa), $l);
chk('foto com legenda, contato e figurinha: tipos foto / contato / outro', array_column($l, 'tipo') === ['texto', 'foto', 'contato', 'outro', 'botao', 'botao'] && $l[1]['texto'] === 'meu comprovante', array_column($l, 'tipo'));
chk('toque em botão: o rótulo vem do teclado da mensagem clicada ("Quero o plano VIP")', ($l[4]['texto'] ?? null) === 'Quero o plano VIP', $l[4] ?? null);
chk('toque em botão sem teclado conhecido: guarda o tipo botão com texto vazio (a tela mostra "Clicou em um botão")', ($l[5]['texto'] ?? 'x') === '' && ($l[5]['tipo'] ?? '') === 'botao', $l[5] ?? null);
chk('mensagem de grupo, my_chat_member e atualização vazia não entram', count($l) === 6 && (int) q1('SELECT COUNT(*) FROM chat_mensagens') === 6);

// ---------------------------------------------------------------------------------------------------- C. escrita em lote
echo "\n== C. Escrita em lote\n";
limpar();
$antes = inserts();
for ($i = 1; $i <= 120; $i++) {
    chatEnfileirar($bot['a'], '900001', CHAT_DIRECAO_ENVIADA, CHAT_ORIGEM_AUTOMATICA, 'texto', "lote $i", $data_fixa + $i);
}
chatDescarregar();
$comandos = inserts() - $antes;
chk('120 mensagens gravadas com poucos INSERTs (lotes de ' . CHAT_LOTE . '): ' . $comandos . ' comando(s), esperado 3', $comandos === 3 && (int) q1('SELECT COUNT(*) FROM chat_mensagens') === 120, $comandos);
$l = linhas($bot['a'], '900001');
chk('a ordem de gravação é a ordem de chegada (id crescente = lote 1..120)', $l[0]['texto'] === 'lote 1' && $l[119]['texto'] === 'lote 120');
chatDescarregar();
chk('descarregar com a fila vazia não faz nada', inserts() - $antes === $comandos);

// ---------------------------------------------------------------------------------------------------- D. leitura
echo "\n== D. Leitura: página, rolar para cima, tempo real e posse\n";
limpar();
for ($i = 1; $i <= 130; $i++) {
    chatEnfileirar($bot['a'], '900001', $i % 2, CHAT_ORIGEM_AUTOMATICA, 'texto', "m$i", $data_fixa + $i);
}
chatEnfileirar($bot['b'], '900001', CHAT_DIRECAO_RECEBIDA, CHAT_ORIGEM_AUTOMATICA, 'texto', 'segredo do B', $data_fixa);
chatDescarregar();
$p1 = chatListarMensagens($u['a'], $bot['a'], '900001');
chk('primeira página: as últimas ' . CHAT_PAGINA . ', da mais antiga para a mais nova, e avisa que há mais', count($p1['mensagens']) === CHAT_PAGINA && $p1['mensagens'][0]['texto'] === 'm91' && $p1['mensagens'][CHAT_PAGINA - 1]['texto'] === 'm130' && $p1['tem_mais'] === true, [count($p1['mensagens']), $p1['tem_mais']]);
$p2 = chatListarMensagens($u['a'], $bot['a'], '900001', $p1['mensagens'][0]['id']);
chk('rolar para cima (antes = primeiro id): vem m51..m90, sem repetir nem pular', count($p2['mensagens']) === CHAT_PAGINA && $p2['mensagens'][0]['texto'] === 'm51' && $p2['mensagens'][CHAT_PAGINA - 1]['texto'] === 'm90' && $p2['tem_mais'] === true);
$p4 = chatListarMensagens($u['a'], $bot['a'], '900001', chatListarMensagens($u['a'], $bot['a'], '900001', $p2['mensagens'][0]['id'])['mensagens'][0]['id']);
chk('última página (m1..m10): tem_mais falso', $p4['tem_mais'] === false && $p4['mensagens'][0]['texto'] === 'm1' && count($p4['mensagens']) === 10, [count($p4['mensagens']), $p4['tem_mais']]);
$penultimo = $p1['mensagens'][CHAT_PAGINA - 3]['id'];
$novas = chatListarMensagens($u['a'], $bot['a'], '900001', 0, $penultimo);
chk('tempo real (depois = id): só as mensagens novas, em ordem', array_column($novas['mensagens'], 'texto') === ['m129', 'm130'], array_column($novas['mensagens'], 'texto'));
chk('tempo real sem novidade devolve lista vazia', chatListarMensagens($u['a'], $bot['a'], '900001', 0, $p1['mensagens'][CHAT_PAGINA - 1]['id'])['mensagens'] === []);
chk('POSSE: outro usuário (B) lendo a conversa do bot do A recebe lista vazia', chatListarMensagens($u['b'], $bot['a'], '900001')['mensagens'] === []);
chk('POSSE: o A lendo o bot do B também recebe vazio, e o B vê só o que é dele', chatListarMensagens($u['a'], $bot['b'], '900001')['mensagens'] === [] && array_column(chatListarMensagens($u['b'], $bot['b'], '900001')['mensagens'], 'texto') === ['segredo do B']);
chk('conversa de outro lead do mesmo bot não mistura (id_telegram diferente)', chatListarMensagens($u['a'], $bot['a'], '900403')['mensagens'] === []);

// ---------------------------------------------------------------------------------------------------- E. índice
echo "\n== E. Plano de execução\n";
// a mesma consulta que o código usa (chatSqlLeitura), para o teste não divergir dela
$explicar = $pdo->prepare('EXPLAIN ' . chatSqlLeitura() . ' ORDER BY m.id DESC LIMIT ' . (CHAT_PAGINA + 1));
$explicar->execute([$u['a'], $bot['a'], '900001']);
$plano = $explicar->fetchAll(PDO::FETCH_ASSOC);
$linha_m = array_values(array_filter($plano, static fn ($r) => $r['table'] === 'm'))[0] ?? [];
chk('a leitura usa o índice idx_chat_conversa', ($linha_m['key'] ?? '') === 'idx_chat_conversa', $linha_m);
chk('e ordena pelo próprio índice (sem filesort)', strpos((string) ($linha_m['Extra'] ?? ''), 'filesort') === false, $linha_m['Extra'] ?? null);

// ---------------------------------------------------------------------------------------------------- F. envio manual
echo "\n== F. Envio manual (Telegram falso)\n";
limpar();
$r = chatEnviarManual($u['a'], $bot['a'], '900001', "  Olá, aqui é o suporte!  ");
$l = linhas($bot['a'], '900001');
chk('envio manual ok: sucesso, 1 linha "enviada" com origem manual e o texto aparado', $r['sucesso'] === true && count($l) === 1 && (int) $l[0]['origem'] === 1 && (int) $l[0]['direcao'] === 1 && $l[0]['texto'] === 'Olá, aqui é o suporte!', [$r, $l]);
chk('depois do envio manual a origem volta ao normal (as próximas mensagens do processo não saem marcadas como manuais)', chatOrigemAtual() === CHAT_ORIGEM_AUTOMATICA);
$r = chatEnviarManual($u['a'], $bot['a'], '900403', 'oi');
chk('lead que bloqueou o bot: erro amigável, sem detalhe técnico e nada gravado', $r['sucesso'] === false && strpos($r['mensagem'], 'bloqueou') !== false && count(linhas($bot['a'], '900403')) === 0, $r);
$r = chatEnviarManual($u['b'], $bot['a'], '900001', 'invasão');
chk('POSSE: B não consegue enviar pelo bot do A (404) e nada é gravado', $r['sucesso'] === false && ($r['http'] ?? 0) === 404 && count(linhas($bot['a'], '900001')) === 1, $r);
$r = chatEnviarManual($u['a'], $bot['a'], '123', 'oi');
chk('lead que não existe no bot: 404 (não dá para mandar mensagem a qualquer id)', $r['sucesso'] === false && ($r['http'] ?? 0) === 404, $r);
chk('mensagem vazia: 422', (chatEnviarManual($u['a'], $bot['a'], '900001', "   \n "))['http'] === 422);
chk('mensagem acima de 4096 caracteres: 422', (chatEnviarManual($u['a'], $bot['a'], '900001', str_repeat('x', 4097)))['http'] === 422);

// ---------------------------------------------------------------------------------------------------- G. retenção
echo "\n== G. Retenção\n";
limpar();
$pdo->exec("INSERT INTO chat_mensagens (bot_id, id_telegram, direcao, origem, tipo, texto, criado_em) SELECT {$bot['a']}, '900001', 1, 0, 'texto', CONCAT('velha ', seq), NOW() - INTERVAL 70 DAY FROM seq_1_to_2500");
$pdo->exec("INSERT INTO chat_mensagens (bot_id, id_telegram, direcao, origem, tipo, texto, criado_em) SELECT {$bot['a']}, '900001', 1, 0, 'texto', CONCAT('recente ', seq), NOW() - INTERVAL 5 DAY FROM seq_1_to_5");
$removidas = chatLimparAntigas();
chk('apaga em lotes tudo que passou de ' . CHAT_RETENCAO_DIAS . ' dias (2500 linhas, mais de um lote de 1000) e guarda o resto', $removidas === 2500 && (int) q1('SELECT COUNT(*) FROM chat_mensagens') === 5, [$removidas, q1('SELECT COUNT(*) FROM chat_mensagens')]);
chk('rodar de novo não apaga nada', chatLimparAntigas() === 0);

// ---------------------------------------------------------------------------------------------------- H. chave estrangeira
echo "\n== H. Chave estrangeira\n";
limpar();
chatEnfileirar($bot['d'], '900001', 0, 0, 'texto', 'do bot D');
chatDescarregar();
$pdo->prepare('DELETE FROM bots WHERE id = ?')->execute([$bot['d']]);
chk('apagar o bot apaga as conversas dele (ON DELETE CASCADE)', (int) q1('SELECT COUNT(*) FROM chat_mensagens') === 0);
$pdo->prepare('INSERT INTO bots (id_usuario, token, nome_usuario) VALUES (?, ?, ?)')->execute([$u['d'], 'TOKEN_D', 'bot_d']);
$bot['d'] = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO leads (id_telegram, nome, bot_id) VALUES (?, ?, ?)')->execute(['900001', 'Bruno do D', $bot['d']]);
chatRegistrarBot('TOKEN_D', $bot['d']);

// ---------------------------------------------------------------------------------------------------- I. endpoints
echo "\n== I. Endpoints (ajax/chat_mensagens.php e ajax/chat_enviar.php), como o servidor os executa\n";
limpar();
for ($i = 1; $i <= 3; $i++) {
    chatEnfileirar($bot['a'], '900001', $i % 2, 0, 'texto', "ep$i", $data_fixa + $i);
}
chatDescarregar();
$get = static fn (?int $uid, array $q) => endpoint(['script' => 'ajax/chat_mensagens.php', 'method' => 'GET', 'get' => $q, 'usuario_id' => $uid]);
$post = static fn (?int $uid, array $body, ?string $csrf = 'tok-teste', string $metodo = 'POST') => endpoint(['script' => 'ajax/chat_enviar.php', 'method' => $metodo, 'body' => $body, 'usuario_id' => $uid, 'csrf' => $csrf]);

$r = $get(null, ['bot_id' => $bot['a'], 'id_telegram' => '900001']);
chk('leitura sem login: 401', $r['http'] === 401 && ($r['json']['sucesso'] ?? true) === false, $r['bruto']);
$r = $get($u['a'], ['bot_id' => $bot['a'], 'id_telegram' => '900001']);
chk('leitura do dono: 200 com as 3 mensagens e tem_mais falso', $r['http'] === 200 && ($r['json']['sucesso'] ?? false) === true && count($r['json']['mensagens'] ?? []) === 3 && $r['json']['tem_mais'] === false, $r['bruto']);
chk('a resposta traz só os campos da tela (id, direcao, origem, tipo, texto, criado_em)', array_keys($r['json']['mensagens'][0] ?? []) === ['id', 'direcao', 'origem', 'tipo', 'texto', 'criado_em'], $r['json']['mensagens'][0] ?? null);
$r = $get($u['b'], ['bot_id' => $bot['a'], 'id_telegram' => '900001']);
chk('POSSE: leitura por outro usuário: 200 com lista vazia (nada vaza)', $r['http'] === 200 && ($r['json']['mensagens'] ?? null) === [], $r['bruto']);
chk('id_telegram inválido (não numérico): 422', $get($u['a'], ['bot_id' => $bot['a'], 'id_telegram' => '9; DROP TABLE x'])['http'] === 422);
chk('bot_id ausente: 422', $get($u['a'], ['id_telegram' => '900001'])['http'] === 422);
chk('injeção de SQL em bot_id/antes/depois não passa (vira inteiro)', $get($u['a'], ['bot_id' => $bot['a'] . ' OR 1=1', 'id_telegram' => '900001', 'antes' => '1 UNION SELECT 1'])['http'] === 200);

$r = $post(null, ['bot_id' => $bot['a'], 'id_telegram' => '900001', 'texto' => 'oi']);
chk('envio sem login: 401', $r['http'] === 401, $r['bruto']);
$r = $post($u['a'], ['bot_id' => $bot['a'], 'id_telegram' => '900001', 'texto' => 'oi'], null);
chk('envio sem o token CSRF: 403', $r['http'] === 403, $r['bruto']);
$r = $post($u['a'], ['bot_id' => $bot['a'], 'id_telegram' => '900001', 'texto' => 'oi'], 'token-errado');
chk('envio com token CSRF errado: 403', $r['http'] === 403, $r['bruto']);
$r = $post($u['a'], ['bot_id' => $bot['a'], 'id_telegram' => '900001', 'texto' => 'oi'], 'tok-teste', 'GET');
chk('envio por GET: 405', $r['http'] === 405, $r['bruto']);
$antes_msgs = (int) q1('SELECT COUNT(*) FROM chat_mensagens');
$r = $post($u['a'], ['bot_id' => $bot['a'], 'id_telegram' => '900001', 'texto' => 'Mensagem pelo endpoint']);
chk('envio válido: 200 sucesso, e a mensagem aparece no histórico como manual', $r['http'] === 200 && ($r['json']['sucesso'] ?? false) === true && (int) q1('SELECT COUNT(*) FROM chat_mensagens') === $antes_msgs + 1 && (int) q1("SELECT origem FROM chat_mensagens WHERE texto = 'Mensagem pelo endpoint'") === 1, $r['bruto']);
$r = $post($u['b'], ['bot_id' => $bot['a'], 'id_telegram' => '900001', 'texto' => 'invasão']);
chk('POSSE: envio de outro usuário pelo bot alheio: 404 e nada gravado', $r['http'] === 404 && (int) q1("SELECT COUNT(*) FROM chat_mensagens WHERE texto = 'invasão'") === 0, $r['bruto']);
$r = $post($u['a'], ['bot_id' => $bot['a'], 'id_telegram' => '900403', 'texto' => 'oi']);
chk('lead que bloqueou o bot: 422 com mensagem amigável', $r['http'] === 422 && strpos((string) ($r['json']['mensagem'] ?? ''), 'bloqueou') !== false, $r['bruto']);
chk('texto vazio: 422', $post($u['a'], ['bot_id' => $bot['a'], 'id_telegram' => '900001', 'texto' => '  '])['http'] === 422);

// Limite de envio (40 por minuto por usuário). A rajada vai direto no contador (instantâneo, dentro da janela de 60 s mesmo numa máquina lenta);
// o endpoint só confere a fiação com UMA chamada: antes eram 42 processos filhos, e a janela virava no meio se a máquina engasgasse.
$estourou = 0;
$liberadas = 0;
for ($i = 1; $i <= 42; $i++) {
    [$permitido] = limiteTaxaConsumir('chat_enviar:' . $u['d'], 40, 60);
    $permitido ? $liberadas++ : $estourou++;
}
chk('limite de envio: 40 mensagens por minuto passam e o resto é barrado (' . $liberadas . ' liberadas, ' . $estourou . ' barradas)', $liberadas === 40 && $estourou === 2);
$r = $post($u['d'], ['bot_id' => $bot['d'], 'id_telegram' => '900001', 'texto' => 'depois do limite']);
chk('endpoint com o limite estourado: 429 e mensagem com o tempo de espera (nada é enviado)', $r['http'] === 429 && strpos((string) ($r['json']['mensagem'] ?? ''), 'Tente de novo em') !== false && (int) q1("SELECT COUNT(*) FROM chat_mensagens WHERE texto = 'depois do limite'") === 0, $r['bruto']);
$r = $post($u['a'], ['bot_id' => $bot['a'], 'id_telegram' => '900001', 'texto' => 'outro usuário não é afetado']);
chk('o limite é por usuário: outro usuário continua enviando (200)', $r['http'] === 200, $r['bruto']);

// ---------------------------------------------------------------------------------------------------- J. desempenho
echo "\n== J. Desempenho com 300 mil mensagens\n";
limpar();
$t0 = microtime(true);
$pdo->exec("INSERT INTO chat_mensagens (bot_id, id_telegram, direcao, origem, tipo, texto, criado_em)
            SELECT {$bot['a']}, CONCAT('1', LPAD(seq % 500, 6, '0')), seq % 2, 0, 'texto', CONCAT('mensagem de teste número ', seq, ' com um texto de tamanho razoável para parecer de verdade'), NOW() - INTERVAL (seq % 50) DAY FROM seq_1_to_300000");
printf("   (carga de 300 mil linhas: %.1f s)\n", microtime(true) - $t0); flush();
flush();
$total = (int) q1('SELECT COUNT(*) FROM chat_mensagens');
chk('300 mil mensagens na tabela (500 conversas de ~600 mensagens)', $total === 300000, $total);

$conv = '1000007';
$tempos = [];
for ($i = 0; $i < 60; $i++) {
    $t = microtime(true);
    $res = chatListarMensagens($u['a'], $bot['a'], $conv);
    $tempos[] = (microtime(true) - $t) * 1000;
}
sort($tempos);
$media = array_sum($tempos) / count($tempos);
$p95 = $tempos[(int) floor(count($tempos) * 0.95) - 1];
printf("   abrir uma conversa (últimas 40 de ~600): média %.2f ms, p95 %.2f ms\n", $media, $p95); flush();
chk('abrir a conversa devolve 40 mensagens e demora menos de 25 ms em média', count($res['mensagens']) === CHAT_PAGINA && $media < 25, $media);

$ultimo = $res['mensagens'][CHAT_PAGINA - 1]['id'];
$tempos = [];
for ($i = 0; $i < 100; $i++) {
    $t = microtime(true);
    chatListarMensagens($u['a'], $bot['a'], $conv, 0, $ultimo);
    $tempos[] = (microtime(true) - $t) * 1000;
}
$media = array_sum($tempos) / count($tempos);
printf("   consulta de tempo real sem novidade (a cada 3 s com o chat aberto): média %.2f ms\n", $media); flush();
chk('a consulta de tempo real demora menos de 10 ms em média', $media < 10, $media);

$t = microtime(true);
for ($i = 1; $i <= 50; $i++) {
    chatEnfileirar($bot['a'], $conv, 1, 0, 'texto', "novo $i");
}
chatDescarregar();
$ms_lote = (microtime(true) - $t) * 1000;
printf("   gravar 50 mensagens num lote, com 300 mil já na tabela: %.2f ms (%.3f ms por mensagem)\n", $ms_lote, $ms_lote / 50); flush();
chk('um lote de 50 mensagens grava em menos de 50 ms', $ms_lote < 50, $ms_lote);

$t = microtime(true);
// as 200 mil mais antigas pelo id (o auto_increment não começa em 1: os testes anteriores já gastaram ids)
$pdo->exec('UPDATE chat_mensagens SET criado_em = NOW() - INTERVAL 90 DAY WHERE id < ' . ((int) q1('SELECT MIN(id) FROM chat_mensagens') + 200000));
$apagadas = chatLimparAntigas();
printf("   retenção: apagar %d linhas antigas de %d: %.1f s\n", $apagadas, $total, microtime(true) - $t); flush();
chk('a limpeza apaga as 200 mil antigas e deixa as 100 mil recentes', $apagadas === 200000 && (int) q1('SELECT COUNT(*) FROM chat_mensagens') === 100050, [$apagadas, q1('SELECT COUNT(*) FROM chat_mensagens')]);

limpar();
echo "\n$ok ok, $falhas falha(s)\n";
exit($falhas ? 1 : 0);
