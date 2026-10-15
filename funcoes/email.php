<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/configuracoes.php';
require_once __DIR__ . '/criptografia.php';
require_once __DIR__ . '/limite_taxa.php';

/**
 * Envio de e-mail do painel (boas-vindas, verificação de e-mail, recuperação de senha, aviso de
 * senha alterada). Sem biblioteca externa: cliente SMTP próprio (SSL direto ou STARTTLS) quando o
 * SMTP está configurado em admin/configuracoes.php; sem SMTP configurado cai no mail() do PHP
 * (na hospedagem compartilhada costuma ir pra caixa de spam — configure o SMTP).
 *
 * Configuração (tabela `configuracoes`): smtp_host, smtp_porta, smtp_seguranca (ssl|tls|nenhuma),
 * smtp_usuario, smtp_senha (cifrada), email_remetente, email_remetente_nome, url_base.
 */

const EMAIL_TIMEOUT_SMTP = 10;

/** Último erro de envio (texto técnico, sem senha) — usado pela tela de teste do admin e pelo log. */
function emailUltimoErro(?string $definir = null): string
{
    static $erro = '';
    if ($definir !== null) {
        $erro = $definir;
    }
    return $erro;
}

/** Resposta do servidor SMTP à última mensagem aceita (ex.: "250 2.0.0 OK: queued as …"): vai para o histórico de envios. */
function emailUltimaResposta(?string $definir = null): string
{
    static $resposta = '';
    if ($definir !== null) {
        $resposta = $definir;
    }
    return $resposta;
}

/**
 * Contexto do PRÓXIMO envio (tipo do e-mail, origem, tentativa, id da fila): quem chama define antes de enviarEmail(); o registro do
 * histórico usa e zera. Sem contexto, o envio entra como tipo "geral", origem "direto".
 */
function emailContextoEnvio(?array $definir = null): array
{
    static $contexto = [];
    if ($definir !== null) {
        $contexto = $definir;
    }
    return $contexto;
}

/**
 * Grava uma tentativa de envio em email_envios (Admin → E-mails): destinatário, assunto, tipo, resultado e a resposta do servidor SMTP.
 * Nunca guarda o corpo do e-mail (tem links e códigos). Falha em silêncio se a tabela ainda não existe: o log em arquivo continua.
 */
function registrarEnvioEmail(string $para, string $assunto, string $de_email, string $via, ?string $erro, string $resposta): void
{
    $contexto = emailContextoEnvio();
    emailContextoEnvio([]);   // o contexto vale para um envio só
    try {
        if (function_exists('garantirConexaoBanco')) {
            garantirConexaoBanco();   // o envio pode ter passado do wait_timeout do MySQL
        }
        $pdo = $GLOBALS['pdo'] ?? null;
        if (!$pdo instanceof PDO) {
            return;
        }
        $pdo->prepare("INSERT INTO email_envios (para, assunto, tipo, origem, via, de_email, status, resposta, tentativa, fila_id)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([
                substr($para, 0, 190), substr(emailLimparCabecalho($assunto), 0, 255), substr((string) ($contexto['tipo'] ?? 'geral'), 0, 30),
                substr((string) ($contexto['origem'] ?? 'direto'), 0, 12), substr($via, 0, 10), substr($de_email, 0, 190),
                $erro === null ? 'enviado' : 'falhou', substr($erro ?? $resposta, 0, 500) ?: null,
                max(1, (int) ($contexto['tentativa'] ?? 1)), isset($contexto['fila_id']) ? (int) $contexto['fila_id'] : null,
            ]);
    } catch (\Throwable $e) {
        // tabela ainda não existe (rode "Atualizar Banco")
    }
}

function emailConfigSmtp(): array
{
    $seguranca = configSistema('smtp_seguranca');
    return [
        'host' => trim(configSistema('smtp_host')),
        'porta' => (int) (configSistema('smtp_porta') ?: 465),
        'seguranca' => in_array($seguranca, ['ssl', 'tls', 'nenhuma'], true) ? $seguranca : 'ssl',
        'usuario' => trim(configSistema('smtp_usuario')),
        'senha' => descriptografarSegredo(configSistema('smtp_senha')),
    ];
}

function emailSmtpConfigurado(): bool
{
    $cfg = emailConfigSmtp();
    return $cfg['host'] !== '' && $cfg['usuario'] !== '' && $cfg['senha'] !== '';
}

/** [endereço, nome] do remetente. Sem configuração: no-reply@<domínio do painel>. */
function emailRemetente(): array
{
    $endereco = trim(configSistema('email_remetente'));
    if (!filter_var($endereco, FILTER_VALIDATE_EMAIL)) {
        $usuario_smtp = emailConfigSmtp()['usuario'];
        $dominio = preg_replace('/^www\./i', '', (string) parse_url(urlBasePainel(), PHP_URL_HOST));
        $endereco = filter_var($usuario_smtp, FILTER_VALIDATE_EMAIL) ? $usuario_smtp : 'no-reply@' . ($dominio ?: 'localhost');
    }
    $nome = trim(configSistema('email_remetente_nome')) ?: nomeSistema();
    return [$endereco, $nome];
}

/**
 * Base dos links enviados por e-mail (verificação etc.). Usa a URL configurada no admin; sem ela,
 * deriva do Host da requisição — que o cliente controla, então configure `url_base` em produção
 * pra ninguém conseguir forjar o link de um e-mail (Host header poisoning).
 */
function urlBasePainel(): string
{
    $configurada = rtrim(trim(configSistema('url_base')), '/');
    if ($configurada !== '' && preg_match('#^https?://[^\s/]+(/[^\s]*)?$#i', $configurada)) {
        return $configurada;
    }
    $esquema = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = preg_match('/^[A-Za-z0-9.\-]+(:\d+)?$/', (string) ($_SERVER['HTTP_HOST'] ?? '')) ? $_SERVER['HTTP_HOST'] : 'localhost';
    $caminho = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    $caminho = preg_replace('#/(admin|ajax|funcoes)$#', '', $caminho);
    return $esquema . '://' . $host . $caminho;
}

function emailLimparCabecalho(string $valor): string
{
    return trim(str_replace(["\r", "\n", "\0"], '', $valor));
}

function emailAssuntoCodificado(string $assunto): string
{
    return '=?UTF-8?B?' . base64_encode(emailLimparCabecalho($assunto)) . '?=';
}

function emailTextoSimples(string $html): string
{
    $html = preg_replace('#<head\b.*?</head>#is', '', $html);
    $html = preg_replace('#<div[^>]*data-preheader[^>]*>.*?</div>#is', '', (string) $html);   // o resumo escondido da caixa de entrada não faz parte do texto
    $texto = preg_replace('#<(br|/p|/h[1-6]|/tr|/td|/div)\s*/?>#i', "\n", (string) $html);
    $texto = preg_replace('#<a [^>]*href="([^"]+)"[^>]*>(.*?)</a>#is', '$2 ($1)', (string) $texto);
    $texto = html_entity_decode(strip_tags((string) $texto), ENT_QUOTES, 'UTF-8');
    return trim(preg_replace("/\n{3,}/", "\n\n", (string) preg_replace('/[ \t]+/', ' ', $texto)));
}

/** Cabeçalhos + corpo MIME multipart/alternative (texto e HTML em base64). */
function emailMontarMime(string $de_email, string $de_nome, string $para, string $assunto, string $html, bool $incluir_para_e_assunto): array
{
    $limite = 'b_' . bin2hex(random_bytes(12));
    $cabecalhos = [
        'Date: ' . date('r'),
        'From: ' . emailAssuntoCodificado($de_nome) . ' <' . emailLimparCabecalho($de_email) . '>',
        'Reply-To: ' . emailLimparCabecalho($de_email),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . (preg_replace('/^.*@/', '', $de_email) ?: 'localhost') . '>',
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $limite . '"',
        'X-Mailer: PainelBots',
    ];
    if ($incluir_para_e_assunto) {
        array_splice($cabecalhos, 1, 0, ['To: ' . emailLimparCabecalho($para), 'Subject: ' . emailAssuntoCodificado($assunto)]);
    }
    $parte = static function (string $tipo, string $conteudo) use ($limite): string {
        return '--' . $limite . "\r\nContent-Type: $tipo; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($conteudo), 76, "\r\n");
    };
    $corpo = $parte('text/plain', emailTextoSimples($html)) . $parte('text/html', $html) . '--' . $limite . "--\r\n";
    return [$cabecalhos, $corpo];
}

/** Lê uma resposta SMTP (inclusive multilinha). Retorna [código, texto]. */
function smtpLerResposta($fp): array
{
    $texto = '';
    while (($linha = fgets($fp, 1024)) !== false) {
        $texto .= $linha;
        if (strlen($linha) < 4 || $linha[3] !== '-') {
            break;
        }
    }
    return [(int) substr($texto, 0, 3), trim($texto)];
}

/** Envia um comando e confere o código esperado. Retorna null se OK, ou o texto do erro. */
function smtpComando($fp, string $comando, array $codigos_ok, bool $ocultar = false): ?string
{
    fwrite($fp, $comando . "\r\n");
    [$codigo, $texto] = smtpLerResposta($fp);
    if (!in_array($codigo, $codigos_ok, true)) {
        return 'SMTP respondeu "' . substr($texto, 0, 160) . '" ao comando ' . ($ocultar ? '(oculto)' : strtok($comando, ' '));
    }
    return null;
}

/** Recusa DEFINITIVA do destinatário (5xx no RCPT TO): tentar de novo não adianta e só queima a reputação do remetente. */
function emailErroDestinatarioPermanente(string $erro): bool
{
    return (bool) preg_match('/respondeu "5\d\d[ -][^"]*" ao comando RCPT/', $erro);
}

/**
 * Problema da configuração/rede do SMTP (login recusado, IP não autorizado, sem conexão, SMTP não configurado): a culpa não é do e-mail,
 * então a fila espera e tenta de novo SEM gastar tentativa (corrigido o SMTP, os e-mails que estavam parados saem sozinhos).
 */
function emailErroDeConfiguracao(string $erro): bool
{
    return (bool) preg_match('/Usuário ou senha do SMTP|bloqueou o IP|Login recusado|Não conectou|recusou a conexão|STARTTLS|Aguardando configuração/i', $erro);
}

/** Entrega uma mensagem MIME já montada. Retorna null se OK, ou a descrição do erro. */
function smtpEnviar(array $cfg, string $remetente, string $destinatario, string $mime): ?string
{
    $prefixo = $cfg['seguranca'] === 'ssl' ? 'ssl://' : 'tcp://';
    // Certificado do servidor SMTP SEMPRE verificado (cadeia e nome do servidor): sem isso quem estivesse no caminho leria o login e a chave do SMTP.
    $contexto = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $cfg['host'], 'SNI_enabled' => true]]);
    $fp = @stream_socket_client($prefixo . $cfg['host'] . ':' . $cfg['porta'], $codigo_erro, $texto_erro, EMAIL_TIMEOUT_SMTP, STREAM_CLIENT_CONNECT, $contexto);
    if (!$fp) {
        return 'Não conectou em ' . $cfg['host'] . ':' . $cfg['porta'] . ' (' . $texto_erro . ')';
    }
    stream_set_timeout($fp, EMAIL_TIMEOUT_SMTP);
    try {
        [$codigo, $texto] = smtpLerResposta($fp);
        if ($codigo !== 220) {
            return 'Servidor SMTP recusou a conexão: ' . substr($texto, 0, 160);
        }
        $nome_local = preg_replace('/[^A-Za-z0-9.\-]/', '', (string) gethostname()) ?: 'localhost';
        if ($erro = smtpComando($fp, 'EHLO ' . $nome_local, [250])) {
            return $erro;
        }
        if ($cfg['seguranca'] === 'tls') {
            if ($erro = smtpComando($fp, 'STARTTLS', [220])) {
                return $erro;
            }
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                return 'Falha ao iniciar a conexão segura (STARTTLS).';
            }
            if ($erro = smtpComando($fp, 'EHLO ' . $nome_local, [250])) {
                return $erro;
            }
        }
        if ($erro = smtpComando($fp, 'AUTH LOGIN', [334])) {
            return $erro;
        }
        if ($erro = smtpComando($fp, base64_encode($cfg['usuario']), [334], true)) {
            return $erro;
        }
        if ($erro = smtpComando($fp, base64_encode($cfg['senha']), [235], true)) {
            // Só o 535 quer dizer "usuário ou senha". Qualquer outra recusa tem outro motivo e o texto do servidor diz qual
            // (ex.: o Brevo devolve "525 Unauthorized IP address" quando o IP deste servidor não está na lista de IPs autorizados da conta).
            if (strpos($erro, '"535') !== false) {
                return 'Usuário ou senha do SMTP recusados.';
            }
            if (stripos($erro, 'unauthorized ip') !== false) {
                return 'O provedor SMTP bloqueou o IP deste servidor ("' . (preg_match('/"([^"]+)"/', $erro, $m) ? $m[1] : 'Unauthorized IP address')
                    . '"): o login está aceito, mas é preciso autorizar o IP do servidor no painel do provedor (no Brevo: Segurança → IPs autorizados).';
            }
            return 'Login recusado pelo servidor SMTP. ' . $erro;
        }
        if ($erro = smtpComando($fp, 'MAIL FROM:<' . $remetente . '>', [250])) {
            return $erro;
        }
        if ($erro = smtpComando($fp, 'RCPT TO:<' . $destinatario . '>', [250, 251])) {
            return $erro;
        }
        if ($erro = smtpComando($fp, 'DATA', [354])) {
            return $erro;
        }
        // Dot-stuffing: linha começando com "." vira ".." pra não encerrar o DATA antes da hora.
        $dados = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], "\n", $mime));
        fwrite($fp, str_replace("\n", "\r\n", $dados) . "\r\n.\r\n");
        [$codigo, $texto] = smtpLerResposta($fp);
        if ($codigo !== 250) {
            return 'Servidor não aceitou a mensagem: ' . substr($texto, 0, 160);
        }
        emailUltimaResposta(substr($texto, 0, 300));   // ex.: "250 2.0.0 OK: queued as …" (serve para achar a mensagem no log do provedor)
        smtpComando($fp, 'QUIT', [221]);
        return null;
    } finally {
        fclose($fp);
    }
}

/**
 * Envia um e-mail HTML. Retorna true se o servidor aceitou a mensagem. O último erro fica em
 * emailUltimoErro() e no log logs/email_debug.log (nunca com senha).
 */
function enviarEmail($para, $assunto, $mensagem_html, $de = null): bool
{
    $para = emailLimparCabecalho((string) $para);
    emailUltimaResposta('');
    if (!filter_var($para, FILTER_VALIDATE_EMAIL)) {
        emailUltimoErro('Destinatário inválido.');
        registrarEnvioEmail($para, (string) $assunto, '', 'smtp', 'Destinatário inválido.', '');
        return false;
    }
    [$de_email, $de_nome] = emailRemetente();
    if (is_string($de) && filter_var($de, FILTER_VALIDATE_EMAIL)) {
        $de_email = $de;
    }

    if (emailSmtpConfigurado()) {
        [$cabecalhos, $corpo] = emailMontarMime($de_email, $de_nome, $para, (string) $assunto, (string) $mensagem_html, true);
        $erro = smtpEnviar(emailConfigSmtp(), $de_email, $para, implode("\r\n", $cabecalhos) . "\r\n\r\n" . $corpo);
        $via = 'smtp';
    } else {
        [$cabecalhos, $corpo] = emailMontarMime($de_email, $de_nome, $para, (string) $assunto, (string) $mensagem_html, false);
        $erro = @mail($para, emailAssuntoCodificado((string) $assunto), $corpo, implode("\r\n", $cabecalhos)) ? null : 'mail() do PHP recusou o envio (configure o SMTP).';
        $via = 'mail()';
        if ($erro === null) {
            emailUltimaResposta('mail() do PHP aceitou a mensagem');
        }
    }

    emailUltimoErro($erro ?? '');
    $linha = '[' . date('Y-m-d H:i:s') . '] ' . ($erro === null ? 'OK ' : 'FALHA ') . $via . ' -> ' . $para . ' | ' . emailLimparCabecalho((string) $assunto) . ($erro ? ' | ' . $erro : '') . "\n";
    @file_put_contents(dirname(__DIR__) . '/logs/email_debug.log', $linha, FILE_APPEND);
    registrarEnvioEmail($para, (string) $assunto, $de_email, $via, $erro, emailUltimaResposta());
    return $erro === null;
}

// ===== Modelos =====

function emailEscapar(string $texto): string
{
    return htmlspecialchars($texto, ENT_QUOTES, 'UTF-8');
}

/**
 * Logo do painel para e-mail: URL pública ABSOLUTA de uma cópia leve (240 px; a original pode ter 1200+ px e 170 KB) e a cor de fundo da
 * faixa do topo, que é a cor do canto da própria logo (assim a imagem e a faixa ficam sem emenda, qualquer que seja a logo).
 * A cópia é gerada uma vez em uploads/ (pasta pública) e refeita sozinha quando a logo é trocada. Sem logo: url vazia (o layout usa o
 * nome do sistema em texto); sem GD: usa a original.
 * @return array{url:string, fundo:string}
 */
function emailLogo(): array
{
    $padrao = ['url' => '', 'fundo' => '#0b0b0d'];
    $caminho = ltrim(trim(configSistema('logo')), '/');
    $arquivo = dirname(__DIR__) . '/' . $caminho;
    if ($caminho === '' || strpos($caminho, '..') !== false || !is_file($arquivo)) {
        return $padrao;
    }
    $original = ['url' => urlBasePainel() . '/' . $caminho, 'fundo' => $padrao['fundo']];
    if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
        return $original;
    }
    $versao = (string) filemtime($arquivo);
    $pasta = dirname(__DIR__) . '/uploads/';
    $achados = glob($pasta . 'email_logo_' . $versao . '_*.jpg') ?: [];
    if (!$achados) {
        $info = @getimagesize($arquivo);
        $dados = $info ? @file_get_contents($arquivo) : false;
        $img = $dados !== false ? @imagecreatefromstring($dados) : false;
        if (!$img || (int) $info[0] < 1 || (int) $info[1] < 1) {
            return $original;
        }
        $larg = min(240, (int) $info[0]);
        $alt = max(1, (int) round($info[1] * $larg / $info[0]));
        $nova = imagecreatetruecolor($larg, $alt);
        imagefill($nova, 0, 0, imagecolorallocate($nova, 255, 255, 255));   // PNG com transparência assenta no branco (JPEG não tem alfa)
        imagecopyresampled($nova, $img, 0, 0, 0, 0, $larg, $alt, (int) $info[0], (int) $info[1]);
        $cor = imagecolorat($nova, 1, 1);
        $destino = $pasta . 'email_logo_' . $versao . '_' . sprintf('%02x%02x%02x', ($cor >> 16) & 255, ($cor >> 8) & 255, $cor & 255) . '.jpg';
        if (!@imagejpeg($nova, $destino, 86)) {
            return $original;
        }
        foreach (glob($pasta . 'email_logo_*.jpg') ?: [] as $velho) {
            if ($velho !== $destino) {
                @unlink($velho);
            }
        }
        $achados = [$destino];
    }
    $nome = basename($achados[0]);
    return ['url' => urlBasePainel() . '/uploads/' . $nome, 'fundo' => '#' . (preg_match('/_([0-9a-f]{6})\.jpg$/', $nome, $m) ? $m[1] : '0b0b0d')];
}

/**
 * Moldura comum dos e-mails: faixa do topo com a logo, filete na cor de destaque, corpo, botão opcional e rodapé. Tabelas e CSS inline
 * (é o que os clientes de e-mail aceitam); o botão é uma tabela, para aparecer igual no Outlook. O texto escondido no começo é o que a
 * caixa de entrada mostra ao lado do assunto.
 */
function emailLayout(string $titulo, string $conteudo_html, string $botao_texto = '', string $botao_url = ''): string
{
    $cor = preg_match('/^#[0-9a-fA-F]{6}$/', corPrimariaSistema()) ? corPrimariaSistema() : '#ff6a1a';
    $nome_sistema = emailEscapar(nomeSistema());
    $logo = emailLogo();
    $topo = $logo['url'] !== ''
        ? '<img src="' . emailEscapar($logo['url']) . '" width="150" alt="' . $nome_sistema . '" style="display:block;margin:0 auto;border:0;outline:none;width:150px;height:auto;">'
        : '<span style="font-size:24px;font-weight:800;letter-spacing:.4px;color:#ffffff;">' . $nome_sistema . '</span>';
    $resumo = emailEscapar($titulo . ' — ' . mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($conteudo_html))), 0, 110));
    $botao = '';
    if ($botao_texto !== '' && $botao_url !== '') {
        $botao = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:28px 0 6px;"><tr>'
            . '<td align="center" bgcolor="' . $cor . '" style="background:' . $cor . ';border-radius:10px;">'
            . '<a href="' . emailEscapar($botao_url) . '" style="display:inline-block;padding:14px 32px;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:10px;">' . emailEscapar($botao_texto) . '</a>'
            . '</td></tr></table>'
            . '<p style="margin:18px 0 0;font-size:12px;line-height:1.5;color:#8b9099;word-break:break-all;">Se o botão não funcionar, copie este endereço no navegador:<br>' . emailEscapar($botao_url) . '</p>';
    }
    return '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"><title>' . emailEscapar($titulo) . '</title></head>'
        . '<body style="margin:0;padding:0;background:#efede9;font-family:\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;color:#15171c;-webkit-text-size-adjust:100%;">'
        . '<div data-preheader="1" style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;font-size:1px;line-height:1px;">' . $resumo . '</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#efede9;"><tr><td align="center" style="padding:32px 12px;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;">'
        . '<tr><td align="center" bgcolor="' . $logo['fundo'] . '" style="background:' . $logo['fundo'] . ';border-radius:18px 18px 0 0;padding:30px 20px 26px;">' . $topo . '</td></tr>'
        . '<tr><td height="4" bgcolor="' . $cor . '" style="background:' . $cor . ';height:4px;line-height:4px;font-size:0;">&nbsp;</td></tr>'
        . '<tr><td style="background:#ffffff;padding:34px 36px 32px;font-size:15px;line-height:1.65;border-left:1px solid #e4e2dd;border-right:1px solid #e4e2dd;">'
        . '<h1 style="margin:0 0 16px;font-size:22px;line-height:1.3;font-weight:800;color:#15171c;">' . emailEscapar($titulo) . '</h1>'
        . $conteudo_html . $botao . '</td></tr>'
        . '<tr><td align="center" style="background:#faf9f7;border:1px solid #e4e2dd;border-radius:0 0 18px 18px;padding:20px 36px;font-size:12px;line-height:1.6;color:#8b9099;">'
        . 'Você recebeu este e-mail porque há uma conta em <strong style="color:#6b707a;">' . $nome_sistema . '</strong> com este endereço.<br>Se não foi você, pode ignorar esta mensagem.'
        . '<br><span style="color:#b0b3ba;">© ' . date('Y') . ' ' . $nome_sistema . '</span></td></tr>'
        . '</table></td></tr></table></body></html>';
}

function enviarEmailBoasVindas(string $nome, string $email, string $url_verificacao = ''): bool
{
    $primeiro = emailEscapar(trim(explode(' ', trim($nome))[0] ?? '') ?: 'por aí');
    $conteudo = '<p style="margin:0 0 12px;">Olá, ' . $primeiro . '! Sua conta em <strong>' . emailEscapar(nomeSistema()) . '</strong> foi criada com sucesso.</p>'
        . '<p style="margin:0 0 12px;">Para começar: cadastre seu bot, monte um fluxo e conecte um gateway de pagamento.</p>';
    if ($url_verificacao !== '') {
        $conteudo .= '<p style="margin:0;">Confirme seu e-mail para proteger sua conta e liberar a recuperação de senha:</p>';
    }
    return emailEnviarOuEnfileirar($email, 'Bem-vindo(a) ao ' . nomeSistema(), emailLayout('Bem-vindo(a)!', $conteudo, $url_verificacao !== '' ? 'Confirmar meu e-mail' : '', $url_verificacao), 'boas_vindas');
}

function enviarEmailVerificacao(string $nome, string $email, string $url_verificacao): bool
{
    $primeiro = emailEscapar(trim(explode(' ', trim($nome))[0] ?? '') ?: 'olá');
    $conteudo = '<p style="margin:0 0 12px;">Oi, ' . $primeiro . '. Clique no botão abaixo para confirmar que este e-mail é seu.</p>'
        . '<p style="margin:0;">O link vale por <strong>48 horas</strong>.</p>';
    return emailEnviarOuEnfileirar($email, 'Confirme seu e-mail', emailLayout('Confirme seu e-mail', $conteudo, 'Confirmar meu e-mail', $url_verificacao), 'verificacao');
}

function enviarEmailCodigo($para, $codigo)
{
    $conteudo = '<p style="margin:0 0 12px;">Use o código abaixo para redefinir sua senha:</p>'
        . '<p style="margin:0 0 12px;font-size:32px;font-weight:800;letter-spacing:8px;font-family:Consolas,monospace;">' . emailEscapar((string) $codigo) . '</p>'
        . '<p style="margin:0;">Ele expira em <strong>15 minutos</strong>. Se você não pediu a redefinição, ignore este e-mail: sua senha continua a mesma.</p>';
    // Direto (a pessoa está esperando o código na tela); se o envio falhar, entra na fila e o cron
    // tenta de novo enquanto o código ainda vale (15 min).
    return emailEnviarOuEnfileirar((string) $para, 'Código para redefinir sua senha', emailLayout('Redefinição de senha', $conteudo), 'redefinicao', true, 15 * 60);
}

function enviarEmailSenhaAlterada(string $nome, string $email): bool
{
    $conteudo = '<p style="margin:0 0 12px;">Olá, ' . emailEscapar(trim(explode(' ', trim($nome))[0] ?? '') ?: 'olá') . '. A senha da sua conta foi alterada em ' . date('d/m/Y \à\s H:i') . '.</p>'
        . '<p style="margin:0;"><strong>Não foi você?</strong> Redefina a senha agora pela opção "Esqueci a senha" na tela de login e avise o suporte.</p>';
    return emailEnviarOuEnfileirar($email, 'Sua senha foi alterada', emailLayout('Senha alterada', $conteudo), 'senha_alterada');
}

/** Aviso ao endereço ANTIGO de que o e-mail de acesso foi trocado (se não foi a pessoa, é o primeiro sinal de conta invadida). */
function enviarEmailEmailAlterado(string $nome, string $email_antigo, string $email_novo): bool
{
    [$usuario_novo, $dominio_novo] = array_pad(explode('@', $email_novo, 2), 2, '');
    $mascarado = mb_substr($usuario_novo, 0, 1) . '***@' . $dominio_novo;
    $conteudo = '<p style="margin:0 0 12px;">Olá, ' . emailEscapar(trim(explode(' ', trim($nome))[0] ?? '') ?: 'olá') . '. O e-mail de acesso da sua conta foi alterado em ' . date('d/m/Y \à\s H:i') . ' para <strong>' . emailEscapar($mascarado) . '</strong>.</p>'
        . '<p style="margin:0;"><strong>Não foi você?</strong> Entre em contato com o suporte agora e redefina a senha pela opção "Esqueci a senha" na tela de login.</p>';
    return emailEnviarOuEnfileirar($email_antigo, 'O e-mail da sua conta foi alterado', emailLayout('E-mail da conta alterado', $conteudo), 'email_alterado');
}

// ===== Fila de e-mails =====
//
// Tudo que não é urgente (boas-vindas, verificação, aviso de senha alterada) entra em `fila_emails`
// e sai pelo cron de 1 minuto: o cadastro não fica preso esperando o SMTP e, se o SMTP cair, nenhum
// e-mail se perde — cada um é tentado até EMAIL_FILA_MAX_TENTATIVAS vezes, com espera crescente.
// A recuperação de senha sai direto (a pessoa está esperando) e só vai pra fila se esse envio falhar.

const EMAIL_FILA_MAX_TENTATIVAS = 5;
const EMAIL_FILA_ESPERAS_MINUTOS = [1, 5, 15, 60];
const EMAIL_FILA_LOTE = 25;
const EMAIL_FILA_ORCAMENTO_SEGUNDOS = 40;

/** Coloca um e-mail na fila. Retorna o id, ou 0 se a fila não estiver disponível (tabela ausente, etc.). */
function enfileirarEmail(string $para, string $assunto, string $html, string $tipo = 'geral', int $validade_segundos = 0, int $tentativas_ja_feitas = 0, string $erro = '', int $espera_segundos = 0): int
{
    global $pdo;
    try {
        $pdo->prepare("INSERT INTO fila_emails (para, assunto, html, tipo, status, tentativas, ultimo_erro, proximo_envio, valido_ate, criado_em)
                       VALUES (?, ?, ?, ?, 'pendente', ?, ?, NOW() + INTERVAL ? SECOND, " . ($validade_segundos > 0 ? 'NOW() + INTERVAL ' . (int) $validade_segundos . ' SECOND' : 'NULL') . ", NOW())")
            ->execute([$para, $assunto, $html, $tipo, $tentativas_ja_feitas, substr($erro, 0, 250), $espera_segundos]);
        return (int) $pdo->lastInsertId();
    } catch (\Throwable $e) {
        error_log('[email] fila indisponível: ' . $e->getMessage());
        return 0;
    }
}

/**
 * $imediato=false: vai pra fila (cron envia). $imediato=true: tenta agora e, se falhar, deixa na fila
 * pra nova tentativa. Sem fila disponível, cai no envio direto. Retorna true se o e-mail foi
 * entregue ao SMTP ou ficou garantido na fila.
 */
function emailEnviarOuEnfileirar(string $para, string $assunto, string $html, string $tipo, bool $imediato = false, int $validade_segundos = 0): bool
{
    if ($imediato) {
        emailContextoEnvio(['tipo' => $tipo, 'origem' => 'imediato']);
        if (enviarEmail($para, $assunto, $html)) {
            return true;
        }
        return enfileirarEmail($para, $assunto, $html, $tipo, $validade_segundos, 1, emailUltimoErro(), 60) > 0;
    }
    if (enfileirarEmail($para, $assunto, $html, $tipo, $validade_segundos) > 0) {
        return true;
    }
    emailContextoEnvio(['tipo' => $tipo, 'origem' => 'direto']);   // sem fila disponível: envia direto
    return enviarEmail($para, $assunto, $html);
}

/** Esvazia a fila (chamado pelo cron). Retorna [enviados, falhas_desta_rodada]. */
function processarFilaEmails(PDO $pdo): array
{
    $inicio = time();
    $enviados = 0;
    $falhas = 0;
    // "processando" esquecido (cron morreu no meio) volta pra fila.
    $pdo->exec("UPDATE fila_emails SET status = 'pendente' WHERE status = 'processando' AND atualizado_em < NOW() - INTERVAL 10 MINUTE");
    $stmt = $pdo->query("SELECT * FROM fila_emails WHERE status = 'pendente' AND proximo_envio <= NOW() ORDER BY id ASC LIMIT " . EMAIL_FILA_LOTE);
    foreach ($stmt->fetchAll() as $item) {
        if (time() - $inicio > EMAIL_FILA_ORCAMENTO_SEGUNDOS) {
            break;
        }
        garantirConexaoBanco(); // o envio anterior pode ter passado do wait_timeout (20 s) do MySQL
        $pdo = $GLOBALS['pdo'];
        $reivindicou = $pdo->prepare("UPDATE fila_emails SET status = 'processando', tentativas = tentativas + 1 WHERE id = ? AND status = 'pendente'");
        $reivindicou->execute([$item['id']]);
        if ($reivindicou->rowCount() === 0) {
            continue;
        }
        if (!empty($item['valido_ate']) && strtotime((string) $item['valido_ate']) < time()) {
            $pdo->prepare("UPDATE fila_emails SET status = 'falhou', ultimo_erro = 'Expirou antes de ser enviado.' WHERE id = ?")->execute([$item['id']]);
            $falhas++;
            continue;
        }
        emailContextoEnvio(['tipo' => (string) $item['tipo'], 'origem' => 'fila', 'tentativa' => (int) $item['tentativas'] + 1, 'fila_id' => (int) $item['id']]);
        if (enviarEmail((string) $item['para'], (string) $item['assunto'], (string) $item['html'])) {
            $pdo->prepare("UPDATE fila_emails SET status = 'enviado', enviado_em = NOW(), ultimo_erro = NULL WHERE id = ?")->execute([$item['id']]);
            $enviados++;
            continue;
        }
        $tentativas = (int) $item['tentativas'] + 1;
        $erro = substr(emailUltimoErro(), 0, 250);
        $smtp_ligado = emailSmtpConfigurado();
        if ((!$smtp_ligado || emailErroDeConfiguracao($erro)) && strtotime((string) $item['criado_em']) > time() - 86400) {
            // Problema de configuração/rede do SMTP, não do e-mail (sem SMTP a Hostinger bloqueia o mail(); login recusado; IP não autorizado):
            // não gasta tentativa, só espera e tenta de novo. Depois de 24 h parado desiste (cai no caso normal abaixo).
            $pdo->prepare("UPDATE fila_emails SET status = 'pendente', tentativas = tentativas - 1, ultimo_erro = ?, proximo_envio = NOW() + INTERVAL 15 MINUTE WHERE id = ?")
                ->execute([substr(($smtp_ligado ? '' : 'Aguardando configuração do SMTP. ') . $erro, 0, 250), $item['id']]);
            $falhas++;
            continue;
        }
        if ($tentativas >= EMAIL_FILA_MAX_TENTATIVAS || emailErroDestinatarioPermanente($erro)) {
            $pdo->prepare("UPDATE fila_emails SET status = 'falhou', ultimo_erro = ? WHERE id = ?")->execute([$erro, $item['id']]);
        } else {
            $espera = EMAIL_FILA_ESPERAS_MINUTOS[min($tentativas - 1, count(EMAIL_FILA_ESPERAS_MINUTOS) - 1)];
            $pdo->prepare("UPDATE fila_emails SET status = 'pendente', ultimo_erro = ?, proximo_envio = NOW() + INTERVAL ? MINUTE WHERE id = ?")->execute([$erro, $espera, $item['id']]);
        }
        $falhas++;
    }
    // Limpeza: enviados somem em 7 dias; falhos ficam 30 dias pra consulta.
    $pdo->exec("DELETE FROM fila_emails WHERE (status = 'enviado' AND criado_em < NOW() - INTERVAL 7 DAY) OR (status = 'falhou' AND criado_em < NOW() - INTERVAL 30 DAY)");
    limiteTaxaLimpar();   // contadores de limite de taxa com mais de 2 dias
    try {
        $pdo->exec("DELETE FROM tokens_email WHERE expira_em < NOW() - INTERVAL 7 DAY LIMIT 2000");   // links de confirmação vencidos há mais de 7 dias
    } catch (\Throwable $e) {
        // tabela ainda não existe
    }
    // Histórico de envios (email_envios): 90 dias, apagado em pedaços pequenos para a limpeza nunca segurar a tabela.
    try {
        $pdo->exec("DELETE FROM email_envios WHERE criado_em < NOW() - INTERVAL 90 DAY LIMIT 2000");
    } catch (\Throwable $e) {
        // tabela ainda não existe
    }
    return [$enviados, $falhas];
}

/**
 * Enviados e falhas das últimas 24 h, contados em email_envios (toda tentativa do painel: direta, na hora, pela fila e teste).
 * É a MESMA conta nas telas Configurações e E-mails; a fila (resumoFilaEmails) só conta o que está esperando.
 * @return array{enviados:int, falhas:int, disponivel:bool}
 */
function resumoEnviosEmail24h(): array
{
    global $pdo;
    $r = ['enviados' => 0, 'falhas' => 0, 'disponivel' => true];
    try {
        $l = $pdo->query("SELECT COALESCE(SUM(status = 'enviado'), 0) AS ok, COALESCE(SUM(status = 'falhou'), 0) AS falha
                          FROM email_envios WHERE criado_em > NOW() - INTERVAL 24 HOUR")->fetch(PDO::FETCH_ASSOC);
        $r['enviados'] = (int) $l['ok'];
        $r['falhas'] = (int) $l['falha'];
    } catch (\Throwable $e) {
        $r['disponivel'] = false;   // tabela ainda não existe (rodar "Atualizar Banco")
    }
    return $r;
}

/** Contagem por status, pra tela do admin. */
function resumoFilaEmails(): array
{
    global $pdo;
    $resumo = ['pendente' => 0, 'processando' => 0, 'enviado' => 0, 'falhou' => 0, 'disponivel' => true];
    try {
        foreach ($pdo->query("SELECT status, COUNT(*) AS qtd FROM fila_emails GROUP BY status")->fetchAll() as $linha) {
            $resumo[$linha['status']] = (int) $linha['qtd'];
        }
    } catch (\Throwable $e) {
        $resumo['disponivel'] = false;
    }
    return $resumo;
}
