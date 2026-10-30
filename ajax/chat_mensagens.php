<?php
declare(strict_types=1);

// GET ?bot_id=&id_telegram=[&antes=<id>|&depois=<id>]: mensagens da conversa de um lead do vendedor logado.
//   sem antes/depois -> as últimas 40; antes -> página anterior (rolar para cima); depois -> só as novas (atualização em tempo real).
// Uma consulta por índice (bot_id, id_telegram, id); o JOIN com bots garante que o bot é do usuário. Sem COUNT, sem escrita.
require_once __DIR__ . '/_chat.php';

[$id_usuario] = chatAjaxInicio('GET');
[$bot_id, $id_telegram] = chatConversaDaRequisicao($_GET);

$antes = max(0, (int) ($_GET['antes'] ?? 0));
$depois = max(0, (int) ($_GET['depois'] ?? 0));

try {
    $resultado = chatListarMensagens($id_usuario, $bot_id, $id_telegram, $antes, $depois);
} catch (\Throwable $e) {
    error_log('[chat] leitura falhou: ' . $e->getMessage());
    chatResponder(['sucesso' => false, 'mensagem' => 'Não foi possível carregar a conversa.'], 500);
}

chatResponder(['sucesso' => true] + $resultado);
