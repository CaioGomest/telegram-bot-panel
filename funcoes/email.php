<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/configuracoes.php';
require_once __DIR__ . '/criptografia.php';

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

/** Entrega uma mensagem MIME já montada. Retorna null se OK, ou a descrição do erro. */
function smtpEnviar(array $cfg, string $remetente, string $destinatario, string $mime): ?string
{
    $prefixo = $cfg['seguranca'] === 'ssl' ? 'ssl://' : 'tcp://';
    $fp = @stream_socket_client($prefixo . $cfg['host'] . ':' . $cfg['porta'], $codigo_erro, $texto_erro, EMAIL_TIMEOUT_SMTP);
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
        if (smtpComando($fp, base64_encode($cfg['senha']), [235], true)) {
            return 'Usuário ou senha do SMTP recusados.';
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
    if (!filter_var($para, FILTER_VALIDATE_EMAIL)) {
        emailUltimoErro('Destinatário inválido.');
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
    }

    emailUltimoErro($erro ?? '');
    $linha = '[' . date('Y-m-d H:i:s') . '] ' . ($erro === null ? 'OK ' : 'FALHA ') . $via . ' -> ' . $para . ' | ' . emailLimparCabecalho((string) $assunto) . ($erro ? ' | ' . $erro : '') . "\n";
    @file_put_contents(dirname(__DIR__) . '/logs/email_debug.log', $linha, FILE_APPEND);
    return $erro === null;
}

// ===== Modelos =====

function emailEscapar(string $texto): string
{
    return htmlspecialchars($texto, ENT_QUOTES, 'UTF-8');
}

/** Moldura comum: nome do painel no topo, conteúdo, botão opcional e rodapé. */
function emailLayout(string $titulo, string $conteudo_html, string $botao_texto = '', string $botao_url = ''): string
{
    $cor = preg_match('/^#[0-9a-fA-F]{6}$/', corPrimariaSistema()) ? corPrimariaSistema() : '#ff6a1a';
    $nome_sistema = emailEscapar(nomeSistema());
    $botao = '';
    if ($botao_texto !== '' && $botao_url !== '') {
        $botao = '<p style="margin:26px 0 8px;"><a href="' . emailEscapar($botao_url) . '" style="display:inline-block;background:' . $cor
            . ';color:#ffffff;text-decoration:none;font-weight:700;padding:13px 26px;border-radius:10px;">' . emailEscapar($botao_texto) . '</a></p>'
            . '<p style="margin:14px 0 0;font-size:12px;color:#8b9099;word-break:break-all;">Se o botão não funcionar, copie este endereço no navegador:<br>' . emailEscapar($botao_url) . '</p>';
    }
    return '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . emailEscapar($titulo) . '</title></head>'
        . '<body style="margin:0;padding:0;background:#f2f1ee;font-family:Segoe UI,Arial,sans-serif;color:#15171c;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f2f1ee;padding:28px 12px;"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e4e2dd;">'
        . '<tr><td style="background:' . $cor . ';padding:20px 28px;color:#ffffff;font-size:18px;font-weight:800;letter-spacing:.3px;">' . $nome_sistema . '</td></tr>'
        . '<tr><td style="padding:28px;font-size:15px;line-height:1.6;"><h1 style="margin:0 0 14px;font-size:21px;line-height:1.3;">' . emailEscapar($titulo) . '</h1>'
        . $conteudo_html . $botao . '</td></tr>'
        . '<tr><td style="padding:16px 28px;background:#faf9f7;font-size:12px;color:#8b9099;border-top:1px solid #eceae5;">Você recebeu este e-mail porque há uma conta em ' . $nome_sistema . ' com este endereço. Se não foi você, pode ignorar esta mensagem.</td></tr>'
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
        if (enviarEmail($para, $assunto, $html)) {
            return true;
        }
        return enfileirarEmail($para, $assunto, $html, $tipo, $validade_segundos, 1, emailUltimoErro(), 60) > 0;
    }
    return enfileirarEmail($para, $assunto, $html, $tipo, $validade_segundos) > 0 || enviarEmail($para, $assunto, $html);
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
        if (enviarEmail((string) $item['para'], (string) $item['assunto'], (string) $item['html'])) {
            $pdo->prepare("UPDATE fila_emails SET status = 'enviado', enviado_em = NOW(), ultimo_erro = NULL WHERE id = ?")->execute([$item['id']]);
            $enviados++;
            continue;
        }
        $tentativas = (int) $item['tentativas'] + 1;
        $erro = substr(emailUltimoErro(), 0, 250);
        if (!emailSmtpConfigurado()) {
            // Sem SMTP o problema é de configuração, não do e-mail: não gasta tentativa, só espera
            // (a Hostinger bloqueia o mail() do PHP, então o envio só funciona depois de configurar o SMTP).
            $pdo->prepare("UPDATE fila_emails SET status = 'pendente', tentativas = tentativas - 1, ultimo_erro = ?, proximo_envio = NOW() + INTERVAL 15 MINUTE WHERE id = ?")
                ->execute(['Aguardando configuração do SMTP. ' . $erro, $item['id']]);
            $falhas++;
            continue;
        }
        if ($tentativas >= EMAIL_FILA_MAX_TENTATIVAS) {
            $pdo->prepare("UPDATE fila_emails SET status = 'falhou', ultimo_erro = ? WHERE id = ?")->execute([$erro, $item['id']]);
        } else {
            $espera = EMAIL_FILA_ESPERAS_MINUTOS[min($tentativas - 1, count(EMAIL_FILA_ESPERAS_MINUTOS) - 1)];
            $pdo->prepare("UPDATE fila_emails SET status = 'pendente', ultimo_erro = ?, proximo_envio = NOW() + INTERVAL ? MINUTE WHERE id = ?")->execute([$erro, $espera, $item['id']]);
        }
        $falhas++;
    }
    // Limpeza: enviados somem em 7 dias; falhos ficam 30 dias pra consulta.
    $pdo->exec("DELETE FROM fila_emails WHERE (status = 'enviado' AND criado_em < NOW() - INTERVAL 7 DAY) OR (status = 'falhou' AND criado_em < NOW() - INTERVAL 30 DAY)");
    return [$enviados, $falhas];
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
