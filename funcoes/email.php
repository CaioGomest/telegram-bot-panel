<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

function enviar_email($para, $assunto, $mensagem_html, $de = 'no-reply@local.test') {
    $headers = [];
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-type: text/html; charset=UTF-8';
    $headers[] = 'From: '.$de;
    $headers[] = 'Reply-To: '.$de;
    $headers[] = 'X-Mailer: PHP/'.phpversion();
    $headers_str = implode("\r\n", $headers);
    return @mail($para, $assunto, $mensagem_html, $headers_str);
}

function enviar_email_codigo($para, $codigo) {
    $assunto = 'Código de verificação';
    $mensagem = '<div style="font-family:Segoe UI,Arial,sans-serif;font-size:16px;color:#111;">'
        .'<p>Seu código de verificação é:</p>'
        .'<h2 style="letter-spacing:4px;">'.htmlspecialchars((string)$codigo).'</h2>'
        .'<p>Ele expira em 15 minutos.</p>'
        .'</div>';
    return enviar_email($para, $assunto, $mensagem);
}
