<?php
// Esvazia a fila de e-mails UMA vez e mostra o estado dos e-mails do dono (sem imprimir conteúdo/código). Uso por SSH, depois apague: php zz_mail.php
chdir(__DIR__); require_once 'conexao.php'; require_once 'funcoes/usuario.php'; require_once 'funcoes/email.php';
echo 'SMTP configurado: ', emailSmtpConfigurado() ? 'sim' : 'NÃO (usa mail() do PHP)', "\n";
[$env, $fal] = processarFilaEmails($pdo);
echo "fila processada: enviados=$env falhas=$fal\n";
foreach ($pdo->query("SELECT id, tipo, status, tentativas, LEFT(COALESCE(ultimo_erro,''),120) erro, criado_em FROM fila_emails WHERE destinatario LIKE 'caiogomesteodoro21%' ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo json_encode($r, JSON_UNESCAPED_UNICODE), "\n";
}
