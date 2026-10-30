<?php
declare(strict_types=1);

// POST JSON {bot_id, id_telegram, texto}: o vendedor manda uma mensagem de texto ao lead pelo bot (caixa do chat em Leads).
// Só para lead que existe no bot do próprio vendedor. No máximo 40 mensagens por minuto por usuário.
require_once __DIR__ . '/_chat.php';

[$id_usuario, $dados] = chatAjaxInicio('POST');
[$bot_id, $id_telegram] = chatConversaDaRequisicao($dados);

[$permitido, $espera] = limiteTaxaConsumir('chat_enviar:' . $id_usuario, 40, 60);
if (!$permitido) {
    chatResponder(['sucesso' => false, 'mensagem' => 'Muitas mensagens seguidas. Tente de novo em ' . limiteTaxaTextoEspera($espera) . '.'], 429);
}

try {
    $resultado = chatEnviarManual($id_usuario, $bot_id, $id_telegram, (string) ($dados['texto'] ?? ''));
} catch (\Throwable $e) {
    error_log('[chat] envio manual falhou: ' . $e->getMessage());
    chatResponder(['sucesso' => false, 'mensagem' => 'Não foi possível enviar agora. Tente de novo.'], 500);
}

chatResponder(
    ['sucesso' => $resultado['sucesso']] + (isset($resultado['mensagem']) ? ['mensagem' => $resultado['mensagem']] : []),
    $resultado['sucesso'] ? 200 : (int) ($resultado['http'] ?? 422)
);
