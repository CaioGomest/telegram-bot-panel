<?php
declare(strict_types=1);

// POST {id}: manda um aviso de teste para UM aparelho do próprio vendedor. 1 a cada 5 s e 60 por hora.
require_once __DIR__ . '/_push.php';

[$id_usuario, $dados] = pushAjaxInicio('POST');
pushExigirAtivo($pdo);
$id = (int) ($dados['id'] ?? 0);
if ($id <= 0) {
    pushResponder(['sucesso' => false, 'mensagem' => 'Dados inválidos.'], 422);
}
[$ok_curto, $espera_curta] = limiteTaxaConsumir('push_teste_10s:' . $id_usuario, 1, 5);
if (!$ok_curto) {
    pushResponder(['sucesso' => false, 'mensagem' => 'Aguarde ' . limiteTaxaTextoEspera($espera_curta) . ' para enviar outro teste.'], 429);
}
[$ok_hora, $espera_hora] = limiteTaxaConsumir('push_teste_1h:' . $id_usuario, 60, 3600);
if (!$ok_hora) {
    pushResponder(['sucesso' => false, 'mensagem' => 'Limite de testes por hora atingido. Tente de novo em ' . limiteTaxaTextoEspera($espera_hora) . '.'], 429);
}
$resultado = pushEnviarTeste($pdo, $id_usuario, $id);
pushResponder($resultado, $resultado['sucesso'] ? 200 : 422);
