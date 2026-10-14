<?php
// Monta o JSON de um update do Telegram.
//   php upd.php msg CHAT TEXTO [first_name] [username]
//   php upd.php cb  CHAT DATA  [message_id] [first_name]
$tipo = $argv[1];
$chat = (int) $argv[2];
if ($tipo === 'msg') {
    $from = ['id' => $chat, 'is_bot' => false, 'first_name' => $argv[4] ?? 'Cliente', 'last_name' => 'Teste'];
    if (!empty($argv[5])) $from['username'] = $argv[5];
    $u = ['update_id' => random_int(1, 9999999), 'message' => ['message_id' => random_int(100, 9999), 'from' => $from, 'chat' => ['id' => $chat, 'type' => 'private'], 'date' => time(), 'text' => $argv[3]]];
} else {
    $from = ['id' => $chat, 'is_bot' => false, 'first_name' => $argv[5] ?? 'Cliente'];
    $u = ['update_id' => random_int(1, 9999999), 'callback_query' => ['id' => 'cbq' . random_int(1, 999999), 'from' => $from, 'message' => ['message_id' => (int) ($argv[4] ?? 555), 'chat' => ['id' => $chat, 'type' => 'private']], 'data' => $argv[3]]];
}
echo json_encode($u, JSON_UNESCAPED_UNICODE);
