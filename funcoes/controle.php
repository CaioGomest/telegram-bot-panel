<?php
declare(strict_types=1);

/**
 * Suspensão remota: liga/desliga o sistema POR FORA do servidor, sem depender de nenhuma senha dele.
 *
 * Como funciona: quem administra a VPS guarda uma CHAVE PRIVADA (arquivo no computador dele; nunca vai para o servidor). O servidor
 * guarda só a CHAVE PÚBLICA (CONTROLE_ID e CONTROLE_CHAVE_PUBLICA no config.php). Para suspender ou reativar, deploy/controle/enviar.php
 * assina um comando e o envia por HTTPS para /controle (controle.php). O servidor confere a assinatura; só quem tem a chave privada
 * consegue assinar. Trocar a senha do painel, do banco ou do SSH não impede nada disso.
 *
 *   Comando = JSON {id, estado, modo, emitido_em, valido_ate, nonce, mensagem, assinatura}, assinatura RSA-SHA256 (openssl) sobre
 *   "v1|id|estado|modo|emitido_em|valido_ate|nonce|sha256(mensagem)".
 *
 *   estado   ativo | suspenso | consultar (só devolve o estado atual)
 *   modo     painel  -> painel, API e telas ficam fora do ar (503); webhook do Telegram, webhook de pagamento e links /l/ continuam
 *                       (os bots seguem vendendo; quem já pagou não é prejudicado)
 *            total   -> além do painel, o bot para de responder (webhook.php ignora, 200), os links /l/ saem do ar e os crons de
 *                       mensagens/renovação param. Continuam: webhook de pagamento (webhook_omegapayments.php), cron_verificar_pix e
 *                       cron_verificar_acessos -- quem JÁ pagou recebe o que comprou e os pagamentos continuam sendo registrados.
 *   valido_ate (opcional, 0 = sem prazo)  suspenso + prazo = suspensão temporária (volta a ativo sozinho depois do prazo);
 *                                          ativo + prazo = licença renovável (se ninguém renovar até o prazo, o sistema suspende no "modo")
 *
 * Segurança: anti-repetição (emitido_em tem de ser maior que o do último comando aceito e estar a ±5 min do relógio do servidor),
 * comando preso ao CONTROLE_ID desta instalação, corpo limitado a 4 KB, toda recusa registrada em logs/controle.log.
 * O que NÃO protege: quem tem root na VPS pode remover este arquivo/gatilho. A proteção real contra isso é você manter o root e a
 * conta da hospedagem, e o cliente só o acesso de administrador do painel (ver deploy/PLANO-VPS.md, seção "Suspensão remota").
 *
 * Sem CONTROLE_ID/CONTROLE_CHAVE_PUBLICA o recurso fica desligado (o sistema nunca fica suspenso por engano).
 * A guarda roda cedo em conexao.php, antes de abrir o banco, e só em requisições HTTP: scripts pela linha de comando (manutenção
 * por SSH, atualizar-banco) nunca são barrados -- só os crons de mensagens param no modo "total" (funcoes/cron_guarda.php).
 */

const CONTROLE_JANELA_RELOGIO = 300;
const CONTROLE_MAX_CORPO = 4096;

function controleArquivo(string $nome): string
{
    return dirname(__DIR__) . '/storage/' . $nome;
}

/** @return array{id:string, chave:string}|null  null = recurso desligado */
function controleConfig(): ?array
{
    static $cfg = false;
    if ($cfg !== false) {
        return $cfg;
    }
    $id = defined('CONTROLE_ID') ? (string) CONTROLE_ID : '';
    $chave = defined('CONTROLE_CHAVE_PUBLICA') ? (string) CONTROLE_CHAVE_PUBLICA : '';
    if ($id === '' || $chave === '') {
        // Alternativa só para ambientes em que o config.php não pode ser editado (homologação): storage/controle_chave.json.
        // Na VPS as constantes existem e esta alternativa é ignorada.
        $arq = controleArquivo('controle_chave.json');
        $j = is_file($arq) ? json_decode((string) @file_get_contents($arq), true) : null;
        if (is_array($j)) {
            $id = (string) ($j['id'] ?? '');
            $chave = (string) ($j['chave_publica'] ?? '');
        }
    }
    $cfg = ($id !== '' && $chave !== '') ? ['id' => $id, 'chave' => controleChaveEmPem($chave)] : null;
    return $cfg;
}

/** Aceita a chave pública como PEM completo ou só o base64 do DER (uma linha, fácil de colar no config.php). */
function controleChaveEmPem(string $chave): string
{
    $chave = trim($chave);
    if (str_contains($chave, 'BEGIN')) {
        return $chave;
    }
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(preg_replace('/\s+/', '', $chave) ?? '', 64, "\n") . "-----END PUBLIC KEY-----\n";
}

/** Texto exato que é assinado. Cada campo já foi validado por controleValidarFormato() (sem "|" possível). */
function controleTextoAssinado(array $c): string
{
    return implode('|', ['v1', $c['id'], $c['estado'], $c['modo'], $c['emitido_em'], $c['valido_ate'], $c['nonce'], hash('sha256', (string) $c['mensagem'])]);
}

/** @return array<string,mixed>|null  comando normalizado, ou null se algum campo estiver fora do formato */
function controleValidarFormato($j): ?array
{
    if (!is_array($j)) {
        return null;
    }
    $c = [
        'id' => $j['id'] ?? null,
        'estado' => $j['estado'] ?? null,
        'modo' => $j['modo'] ?? 'painel',
        'emitido_em' => $j['emitido_em'] ?? null,
        'valido_ate' => $j['valido_ate'] ?? 0,
        'nonce' => $j['nonce'] ?? null,
        'mensagem' => $j['mensagem'] ?? '',
        'assinatura' => $j['assinatura'] ?? null,
    ];
    if (!is_string($c['id']) || !preg_match('/^[A-Za-z0-9_-]{8,64}$/', $c['id'])) {
        return null;
    }
    if (!in_array($c['estado'], ['ativo', 'suspenso', 'consultar'], true) || !in_array($c['modo'], ['painel', 'total'], true)) {
        return null;
    }
    if (!is_int($c['emitido_em']) || $c['emitido_em'] <= 0 || !is_int($c['valido_ate']) || $c['valido_ate'] < 0) {
        return null;
    }
    if (!is_string($c['nonce']) || !preg_match('/^[A-Za-z0-9]{8,64}$/', $c['nonce'])) {
        return null;
    }
    if (!is_string($c['mensagem']) || mb_strlen($c['mensagem']) > 140) {
        return null;
    }
    if (!is_string($c['assinatura']) || $c['assinatura'] === '' || strlen($c['assinatura']) > 2048) {
        return null;
    }
    return $c;
}

function controleAssinaturaValida(array $c, string $pem): bool
{
    $sig = base64_decode((string) $c['assinatura'], true);
    if ($sig === false || !function_exists('openssl_verify')) {
        return false;
    }
    $k = @openssl_pkey_get_public($pem);
    if (!$k) {
        return false;
    }
    return openssl_verify(controleTextoAssinado($c), $sig, $k, OPENSSL_ALGO_SHA256) === 1;
}

/** @return array<string,mixed>  o que está gravado (vazio se nunca recebeu comando) */
function controleEstadoGravado(): array
{
    $j = json_decode((string) @file_get_contents(controleArquivo('controle_estado.json')), true);
    return is_array($j) ? $j : [];
}

/**
 * Estado que vale AGORA, já considerando o prazo. Qualquer falha de leitura = ativo (a suspensão nunca acontece por defeito de arquivo).
 * @return array{estado:string, modo:string, valido_ate:int, mensagem:string, motivo:string}
 */
function controleEstadoEfetivo(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $base = ['estado' => 'ativo', 'modo' => 'painel', 'valido_ate' => 0, 'mensagem' => '', 'motivo' => ''];
    if (controleConfig() === null) {
        return $cache = $base;
    }
    $g = controleEstadoGravado();
    if (!$g) {
        return $cache = $base;
    }
    $estado = ($g['estado'] ?? 'ativo') === 'suspenso' ? 'suspenso' : 'ativo';
    $modo = ($g['modo'] ?? 'painel') === 'total' ? 'total' : 'painel';
    $ate = max(0, (int) ($g['valido_ate'] ?? 0));
    $motivo = '';
    if ($ate > 0 && time() > $ate) {
        if ($estado === 'suspenso') {
            $estado = 'ativo';
            $motivo = 'suspensao_expirou';
        } else {
            $estado = 'suspenso';
            $motivo = 'licenca_vencida';
        }
    }
    return $cache = ['estado' => $estado, 'modo' => $modo, 'valido_ate' => $ate, 'mensagem' => (string) ($g['mensagem'] ?? ''), 'motivo' => $motivo];
}

function controleModoTotal(): bool
{
    $e = controleEstadoEfetivo();
    return $e['estado'] === 'suspenso' && $e['modo'] === 'total';
}

function controleLog(string $mensagem): void
{
    $arq = dirname(__DIR__) . '/logs/controle.log';
    if (is_file($arq) && (int) @filesize($arq) > 1048576) {       // 1 MB: guarda só o final
        $fim = (string) @file_get_contents($arq, false, null, -262144);
        @file_put_contents($arq, $fim, LOCK_EX);
    }
    $ip = preg_replace('/[^0-9a-fA-F:.]/', '', (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli')) ?: 'cli';
    @file_put_contents($arq, '[' . date('Y-m-d H:i:s') . "] ip=$ip " . preg_replace('/[\r\n]+/', ' ', $mensagem) . "\n", FILE_APPEND | LOCK_EX);
}

/**
 * Processa o corpo recebido em /controle. Devolve [status HTTP, resposta]. Pura o bastante para teste.
 * Antes de a assinatura ser conferida, nenhuma resposta diz POR QUE foi recusada (só o log guarda o motivo).
 * @return array{0:int, 1:array<string,mixed>}
 */
function controleProcessarComando(string $corpo): array
{
    $cfg = controleConfig();
    if ($cfg === null) {
        return [503, ['ok' => false, 'erro' => 'nao_configurado']];
    }
    if (strlen($corpo) > CONTROLE_MAX_CORPO) {
        controleLog('recusado: corpo grande demais (' . strlen($corpo) . ' bytes)');
        return [413, ['ok' => false, 'erro' => 'recusado']];
    }
    $c = controleValidarFormato(json_decode($corpo, true));
    if ($c === null) {
        controleLog('recusado: formato inválido');
        return [400, ['ok' => false, 'erro' => 'recusado']];
    }
    if (!hash_equals($cfg['id'], $c['id'])) {
        controleLog('recusado: id diferente do desta instalação');
        return [403, ['ok' => false, 'erro' => 'recusado']];
    }
    if (!controleAssinaturaValida($c, $cfg['chave'])) {
        controleLog('recusado: assinatura inválida (estado pedido: ' . $c['estado'] . ')');
        return [403, ['ok' => false, 'erro' => 'recusado']];
    }

    // daqui para baixo quem fala é o dono da chave: as respostas podem explicar o motivo
    $agora = time();
    if (abs($agora - $c['emitido_em']) > CONTROLE_JANELA_RELOGIO) {
        controleLog('recusado: relógio fora da janela (comando ' . $c['emitido_em'] . ', servidor ' . $agora . ')');
        return [403, ['ok' => false, 'erro' => 'relogio', 'hora_servidor' => $agora]];
    }
    if ($c['estado'] === 'consultar') {
        controleLog('consulta de estado');
        return [200, ['ok' => true] + controleResumo($agora)];
    }

    $trava = @fopen(controleArquivo('controle.lock'), 'c');
    if ($trava === false) {
        controleLog('ERRO: não consegui abrir a trava em storage/');
        return [500, ['ok' => false, 'erro' => 'armazenamento']];
    }
    flock($trava, LOCK_EX);
    try {
        $g = controleEstadoGravado();
        if ($c['emitido_em'] <= (int) ($g['emitido_em'] ?? 0)) {
            controleLog('recusado: comando repetido/antigo (emitido_em ' . $c['emitido_em'] . ' <= ' . (int) ($g['emitido_em'] ?? 0) . ')');
            return [409, ['ok' => false, 'erro' => 'antigo', 'ultimo_emitido_em' => (int) ($g['emitido_em'] ?? 0), 'hora_servidor' => $agora]];
        }
        $novo = [
            'estado' => $c['estado'], 'modo' => $c['modo'], 'valido_ate' => $c['valido_ate'], 'mensagem' => $c['mensagem'],
            'emitido_em' => $c['emitido_em'], 'aplicado_em' => $agora,
            'origem_ip' => preg_replace('/[^0-9a-fA-F:.]/', '', (string) ($_SERVER['REMOTE_ADDR'] ?? '')),
        ];
        $tmp = controleArquivo('controle_estado.json.tmp');
        if (@file_put_contents($tmp, json_encode($novo, JSON_UNESCAPED_UNICODE)) === false || !@rename($tmp, controleArquivo('controle_estado.json'))) {
            controleLog('ERRO: não consegui gravar storage/controle_estado.json');
            return [500, ['ok' => false, 'erro' => 'armazenamento']];
        }
    } finally {
        flock($trava, LOCK_UN);
        fclose($trava);
    }
    controleLog('APLICADO: estado=' . $c['estado'] . ' modo=' . $c['modo'] . ' valido_ate=' . $c['valido_ate'] . ($c['mensagem'] !== '' ? ' mensagem="' . $c['mensagem'] . '"' : ''));
    return [200, ['ok' => true] + controleResumo($agora)];
}

/** @return array<string,mixed> */
function controleResumo(int $agora): array
{
    $g = controleEstadoGravado();
    $ate = max(0, (int) ($g['valido_ate'] ?? 0));
    $estado = ($g['estado'] ?? 'ativo') === 'suspenso' ? 'suspenso' : 'ativo';
    $efetivo = 'ativo';
    if ($g) {
        $efetivo = $estado;
        if ($ate > 0 && $agora > $ate) {
            $efetivo = $estado === 'suspenso' ? 'ativo' : 'suspenso';
        }
    }
    return [
        'estado_gravado' => $g ? $estado : 'ativo', 'estado_efetivo' => $efetivo, 'modo' => ($g['modo'] ?? 'painel'), 'valido_ate' => $ate,
        'mensagem' => (string) ($g['mensagem'] ?? ''), 'ultimo_emitido_em' => (int) ($g['emitido_em'] ?? 0), 'hora_servidor' => $agora,
    ];
}

/**
 * A guarda: chamada por conexao.php, antes de abrir o banco. Se o sistema está suspenso, responde 503 e encerra; senão não faz nada.
 * Só atua em requisições HTTP (linha de comando passa sempre).
 */
function controleGuarda(): void
{
    if (PHP_SAPI === 'cli' || controleConfig() === null) {
        return;
    }
    $e = controleEstadoEfetivo();
    if ($e['estado'] !== 'suspenso') {
        return;
    }
    $raiz = (string) realpath(dirname(__DIR__));
    $script = (string) realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $rel = ($raiz !== '' && str_starts_with($script, $raiz . DIRECTORY_SEPARATOR)) ? str_replace('\\', '/', substr($script, strlen($raiz) + 1)) : '';

    if ($rel === 'controle.php') {                   // a porta de reativação nunca pode ser fechada
        return;
    }
    if ($e['modo'] === 'painel' && in_array($rel, ['webhook.php', 'webhook_omegapayments.php', 'l.php'], true)) {
        return;
    }
    if ($e['modo'] === 'total' && $rel === 'webhook_omegapayments.php') {
        return;
    }
    if ($e['modo'] === 'total' && $rel === 'webhook.php') {
        // Telegram: 200 para ele parar de reenviar; a mensagem é descartada (o bot fica mudo enquanto estiver suspenso)
        http_response_code(200);
        header('Content-Type: text/plain; charset=utf-8');
        exit('ok');
    }
    controleResponderSuspenso($e, $rel);
}

function controleResponderSuspenso(array $e, string $rel): void
{
    http_response_code(503);
    header('Retry-After: 3600');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    $quer_json = $rel === 'api.php' || str_starts_with($rel, 'ajax/') || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
        || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    if ($quer_json) {
        header('Content-Type: application/json; charset=utf-8');
        exit(json_encode(['sucesso' => false, 'ok' => false, 'mensagem' => 'Sistema suspenso.'], JSON_UNESCAPED_UNICODE));
    }
    header('Content-Type: text/html; charset=utf-8');
    $msg = trim($e['mensagem']) !== '' ? '<p style="color:#e8e8e8;margin:14px 0 0;line-height:1.6;">' . htmlspecialchars($e['mensagem'], ENT_QUOTES, 'UTF-8') . '</p>' : '';
    echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">'
       . '<meta name="robots" content="noindex"><title>Sistema suspenso</title></head>'
       . '<body style="font-family:system-ui,sans-serif;background:#14161c;color:#e8e8e8;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:24px;text-align:center;">'
       . '<div style="max-width:440px"><h1 style="font-size:20px;margin:0 0 10px;">Sistema suspenso</h1>'
       . '<p style="color:#8b9099;margin:0;line-height:1.6;">Este serviço está temporariamente indisponível.<br>Entre em contato com o responsável pelo sistema.</p>'
       . $msg . '</div></body></html>';
    exit;
}
