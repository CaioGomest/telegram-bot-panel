<?php
declare(strict_types=1);

/**
 * Porta da suspensão remota (POST /controle). Recebe um comando JSON assinado; a lógica e a explicação completa estão em
 * funcoes/controle.php. Não usa banco nem sessão de propósito: tem de funcionar com o sistema suspenso e até com o banco fora do ar.
 * Quem assina os comandos: deploy/controle/enviar.php (na máquina de quem administra a VPS).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/funcoes/controle.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('{"ok":false,"erro":"metodo"}');
}

$corpo = (string) file_get_contents('php://input', false, null, 0, CONTROLE_MAX_CORPO + 1);
[$status, $resposta] = controleProcessarComando($corpo);
http_response_code($status);
echo json_encode($resposta, JSON_UNESCAPED_UNICODE);
