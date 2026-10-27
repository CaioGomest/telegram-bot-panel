<?php
declare(strict_types=1);

// GET: diz se os avisos estão ligados e entrega a chave pública VAPID (o "applicationServerKey" que o navegador usa para se inscrever).
require_once __DIR__ . '/_push.php';

[$id_usuario] = pushAjaxInicio('GET');
$ativo = pushAtivo(true);
$chave = $ativo ? pushVapidPublica($pdo) : '';
// "csrf": o service worker (pushsubscriptionchange) não vê o HTML da página e precisa do token para reenviar a inscrição.
pushResponder(['sucesso' => true, 'ativo' => $ativo && $chave !== '', 'chave_publica' => $chave, 'csrf' => csrfToken(), 'max_aparelhos' => PUSH_MAX_APARELHOS]);
