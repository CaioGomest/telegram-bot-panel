<?php
declare(strict_types=1);

// POST {subscription:{endpoint,keys:{p256dh,auth}}, plataforma?, pref_pix_gerado?, pref_pix_pago?}: registra o aparelho do vendedor logado.
// O mesmo aparelho entrando em outra conta troca de dono. No máximo 20 inscrições por hora por usuário.
require_once __DIR__ . '/_push.php';

[$id_usuario, $dados] = pushAjaxInicio('POST');
pushExigirAtivo($pdo);
[$permitido, $espera] = limiteTaxaConsumir('push_inscrever:' . $id_usuario, 60, 3600);
if (!$permitido) {
    pushResponder(['sucesso' => false, 'mensagem' => 'Muitas tentativas. Tente de novo em ' . limiteTaxaTextoEspera($espera) . '.'], 429);
}
$resultado = pushInscrever($pdo, $id_usuario, $dados, (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
pushResponder($resultado, $resultado['sucesso'] ? 200 : 422);
