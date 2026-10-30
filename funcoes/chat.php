<?php
declare(strict_types=1);

/**
 * Histórico de conversa do bot com cada lead (botão "Abrir Chat" da tela de Leads).
 *
 * O Telegram não devolve o histórico: o painel guarda o que passa por ele, a partir da ativação. Desenho para não pesar:
 *  - Escrita em LOTE: cada mensagem entra numa fila em memória e vai ao banco num único INSERT de várias linhas (a cada
 *    CHAT_LOTE mensagens ou no fim do processo). Webhook = 1 INSERT por requisição; remarketing com milhares de envios =
 *    1 INSERT a cada 50. Nenhuma consulta a mais por mensagem (o bot_id sai de um cache por token).
 *  - Gancho único na saída: registrarRespostaTelegram() (funcoes/operacao.php) já é chamada por todo ponto que fala com o
 *    Telegram, e a resposta do Telegram traz o chat, o texto e o tipo da mensagem enviada. Na entrada: webhook.php.
 *  - Só chat privado (o lead). Grupo e canal ficam de fora.
 *  - Leitura por índice (bot_id, id_telegram, id): 40 mensagens por vez, só quando o chat é aberto na tela.
 *  - Retenção de CHAT_RETENCAO_DIAS dias (cron/cron_limpar_remarketing_envios.php).
 * Nunca derruba quem chamou: toda falha vira error_log e o processo segue.
 */

const CHAT_RETENCAO_DIAS = 60;
const CHAT_TEXTO_MAX = 1000;
const CHAT_LOTE = 50;
const CHAT_PAGINA = 40;

const CHAT_DIRECAO_RECEBIDA = 0;
const CHAT_DIRECAO_ENVIADA = 1;

const CHAT_ORIGEM_AUTOMATICA = 0;
const CHAT_ORIGEM_MANUAL = 1;
const CHAT_ORIGEM_REMARKETING = 2;

/** Fila de mensagens ainda não gravadas (uma por processo). */
function &chatFila(): array
{
    static $fila = [];
    return $fila;
}

/** Origem das mensagens enviadas neste processo (automática, manual pelo painel ou remarketing). Sem argumento, só lê. */
function chatOrigemAtual(?int $definir = null): int
{
    static $origem = CHAT_ORIGEM_AUTOMATICA;
    if ($definir !== null) {
        $origem = $definir;
    }
    return $origem;
}

/** bot_id de um token, com cache por processo. Com $bot_id, só ensina o cache (o webhook já tem o bot na mão). */
function chatBotIdPorToken(string $token, ?int $bot_id = null): ?int
{
    static $cache = [];
    if ($bot_id !== null) {
        $cache[$token] = $bot_id;
        return $bot_id;
    }
    if (array_key_exists($token, $cache)) {
        return $cache[$token];
    }
    global $pdo;
    try {
        $stmt = $pdo->prepare('SELECT id FROM bots WHERE token = ? LIMIT 1');
        $stmt->execute([$token]);
        $id = $stmt->fetchColumn();
    } catch (\Throwable $e) {
        return null;
    }
    return $cache[$token] = ($id === false ? null : (int) $id);
}

function chatRegistrarBot(string $token, int $bot_id): void
{
    chatBotIdPorToken($token, $bot_id);
}

function chatEnfileirar(int $bot_id, string $id_telegram, int $direcao, int $origem, string $tipo, string $texto, ?int $quando = null): void
{
    $fila = &chatFila();
    $fila[] = [$bot_id, $id_telegram, $direcao, $origem, $tipo, mb_substr($texto, 0, CHAT_TEXTO_MAX), date('Y-m-d H:i:s', $quando ?? time())];

    static $gancho = false;
    if (!$gancho) {
        register_shutdown_function('chatDescarregar');
        $gancho = true;
    }
    if (count($fila) >= CHAT_LOTE) {
        chatDescarregar();
    }
}

/** Grava a fila inteira num INSERT só. Se a conexão caiu (wait_timeout), reabre e tenta uma vez mais. */
function chatDescarregar(): void
{
    $fila = &chatFila();
    if (!$fila) {
        return;
    }
    $linhas = $fila;
    $fila = [];

    global $pdo;
    $sql = 'INSERT INTO chat_mensagens (bot_id, id_telegram, direcao, origem, tipo, texto, criado_em) VALUES '
        . implode(',', array_fill(0, count($linhas), '(?,?,?,?,?,?,?)'));
    $parametros = array_merge(...$linhas);

    for ($tentativa = 0; $tentativa < 2; $tentativa++) {
        try {
            $pdo->prepare($sql)->execute($parametros);
            return;
        } catch (\Throwable $e) {
            if ($tentativa === 0 && function_exists('garantirConexaoBanco')) {
                try {
                    garantirConexaoBanco();
                } catch (\Throwable $e2) {
                    // sem banco: cai no log abaixo
                }
                continue;
            }
            error_log('[chat] histórico não gravado (' . count($linhas) . ' mensagem(ns)): ' . $e->getMessage());
        }
    }
}

/**
 * Tipo e texto de uma mensagem do Telegram (a do lead ou a que o bot enviou).
 *
 * @return array{0:string,1:string} [tipo, texto ou legenda]
 */
function chatTipoETexto(array $mensagem): array
{
    $texto = (string) ($mensagem['text'] ?? $mensagem['caption'] ?? '');
    if (isset($mensagem['photo'])) {
        return ['foto', $texto];
    }
    if (isset($mensagem['video']) || isset($mensagem['video_note']) || isset($mensagem['animation'])) {
        return ['video', $texto];
    }
    if (isset($mensagem['audio']) || isset($mensagem['voice'])) {
        return ['audio', $texto];
    }
    if (isset($mensagem['document'])) {
        return ['documento', $texto];
    }
    if (isset($mensagem['contact'])) {
        return ['contato', $texto];
    }
    if (isset($mensagem['text'])) {
        return ['texto', $texto];
    }
    return ['outro', $texto];
}

/**
 * Gancho de saída: chamado por registrarRespostaTelegram() depois de uma chamada send* que o Telegram aceitou.
 * O tipo e o texto saem da própria resposta; sendMediaGroup devolve uma lista de mensagens.
 */
function chatRegistrarEnvio(string $token, array $resposta, string $metodo): void
{
    try {
        if (strncmp($metodo, 'send', 4) !== 0 || ($resposta['ok'] ?? false) !== true) {
            return;
        }
        $resultado = $resposta['result'] ?? null;
        if (!is_array($resultado)) {
            return;
        }
        $mensagens = isset($resultado['message_id']) ? [$resultado] : (isset($resultado[0]) ? $resultado : []);
        if (!$mensagens) {
            return;
        }
        $bot_id = chatBotIdPorToken($token);
        if ($bot_id === null) {
            return;
        }
        foreach ($mensagens as $mensagem) {
            if (!is_array($mensagem) || ($mensagem['chat']['type'] ?? '') !== 'private' || !isset($mensagem['chat']['id'])) {
                continue;
            }
            [$tipo, $texto] = chatTipoETexto($mensagem);
            chatEnfileirar($bot_id, (string) $mensagem['chat']['id'], CHAT_DIRECAO_ENVIADA, chatOrigemAtual(), $tipo, $texto, isset($mensagem['date']) ? (int) $mensagem['date'] : null);
        }
    } catch (\Throwable $e) {
        error_log('[chat] envio não registrado: ' . $e->getMessage());
    }
}

/** Texto do botão que o lead tocou: procura o callback_data no teclado da mensagem clicada (o Telegram o reenvia junto). */
function chatRotuloDoBotao(array $mensagem, string $dado): string
{
    foreach ((array) ($mensagem['reply_markup']['inline_keyboard'] ?? []) as $linha) {
        foreach ((array) $linha as $botao) {
            if (is_array($botao) && ($botao['callback_data'] ?? null) === $dado) {
                return (string) ($botao['text'] ?? '');
            }
        }
    }
    return '';
}

/** Gancho de entrada: o que o lead mandou (mensagem ou toque em botão). Chamado pelo webhook.php com a atualização crua. */
function chatRegistrarRecebida(int $bot_id, array $atualizacao): void
{
    try {
        if (isset($atualizacao['callback_query']) && is_array($atualizacao['callback_query'])) {
            $clique = $atualizacao['callback_query'];
            $mensagem = is_array($clique['message'] ?? null) ? $clique['message'] : [];
            if (($mensagem['chat']['type'] ?? '') === 'private' && isset($mensagem['chat']['id'])) {
                chatEnfileirar($bot_id, (string) $mensagem['chat']['id'], CHAT_DIRECAO_RECEBIDA, CHAT_ORIGEM_AUTOMATICA, 'botao', chatRotuloDoBotao($mensagem, (string) ($clique['data'] ?? '')));
            }
            return;
        }
        $mensagem = $atualizacao['message'] ?? null;
        if (is_array($mensagem) && ($mensagem['chat']['type'] ?? '') === 'private' && isset($mensagem['chat']['id'])) {
            [$tipo, $texto] = chatTipoETexto($mensagem);
            chatEnfileirar($bot_id, (string) $mensagem['chat']['id'], CHAT_DIRECAO_RECEBIDA, CHAT_ORIGEM_AUTOMATICA, $tipo, $texto, isset($mensagem['date']) ? (int) $mensagem['date'] : null);
        }
    } catch (\Throwable $e) {
        error_log('[chat] mensagem recebida não registrada: ' . $e->getMessage());
    }
}

/**
 * Base da consulta de leitura (parâmetros: id do usuário, bot_id, id_telegram). FORCE INDEX porque, com a tabela pequena, o
 * otimizador preferia varrer a chave primária de trás para frente (ORDER BY id DESC) filtrando linha a linha; numa tabela
 * grande isso viraria uma varredura longa para uma conversa antiga. Pelo índice da conversa, ele vai direto às linhas dela.
 */
function chatSqlLeitura(): string
{
    return 'SELECT m.id, m.direcao, m.origem, m.tipo, m.texto, m.criado_em
            FROM chat_mensagens m FORCE INDEX (idx_chat_conversa)
            JOIN bots b ON b.id = m.bot_id AND b.id_usuario = ?
            WHERE m.bot_id = ? AND m.id_telegram = ?';
}

/**
 * Mensagens de uma conversa, da mais antiga para a mais nova. O bot tem de ser do usuário (o JOIN com bots faz a checagem na
 * própria consulta: bot alheio devolve lista vazia, sem consulta extra).
 *  - sem $antes/$depois: as últimas CHAT_PAGINA;
 *  - $antes: página anterior a esse id (rolar para cima);
 *  - $depois: só o que chegou depois desse id (atualização em tempo real).
 *
 * @return array{mensagens:array<int,array<string,mixed>>,tem_mais:bool}
 */
function chatListarMensagens(int $id_usuario, int $bot_id, string $id_telegram, int $antes = 0, int $depois = 0): array
{
    global $pdo;
    $sql = chatSqlLeitura();
    $parametros = [$id_usuario, $bot_id, $id_telegram];

    if ($depois > 0) {
        $sql .= ' AND m.id > ? ORDER BY m.id ASC LIMIT 100';
        $parametros[] = $depois;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($parametros);
        return ['mensagens' => chatFormatarLinhas($stmt->fetchAll(PDO::FETCH_ASSOC)), 'tem_mais' => false];
    }

    if ($antes > 0) {
        $sql .= ' AND m.id < ?';
        $parametros[] = $antes;
    }
    // pede uma a mais: se vier, há página anterior (sem COUNT)
    $sql .= ' ORDER BY m.id DESC LIMIT ' . (CHAT_PAGINA + 1);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($parametros);
    $linhas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $tem_mais = count($linhas) > CHAT_PAGINA;
    if ($tem_mais) {
        array_pop($linhas);
    }
    return ['mensagens' => chatFormatarLinhas(array_reverse($linhas)), 'tem_mais' => $tem_mais];
}

function chatFormatarLinhas(array $linhas): array
{
    return array_map(static fn (array $l): array => [
        'id'        => (int) $l['id'],
        'direcao'   => (int) $l['direcao'],
        'origem'    => (int) $l['origem'],
        'tipo'      => (string) $l['tipo'],
        'texto'     => (string) $l['texto'],
        'criado_em' => (string) $l['criado_em'],
    ], $linhas);
}

/** Mensagem de erro amigável para uma resposta de erro do Telegram (nunca expõe token nem detalhe técnico). */
function chatMensagemDeErro(array $resposta): string
{
    $codigo = (int) ($resposta['error_code'] ?? 0);
    $descricao = strtolower((string) ($resposta['description'] ?? ''));
    if ($codigo === 403 || strpos($descricao, 'blocked') !== false) {
        return 'Este lead bloqueou o bot, então a mensagem não pode ser entregue.';
    }
    if ($codigo === 400 && strpos($descricao, 'chat not found') !== false) {
        return 'O Telegram não encontrou esta conversa.';
    }
    if ($codigo === 429) {
        return 'O Telegram pediu para esperar um pouco. Tente de novo em instantes.';
    }
    return 'Não foi possível enviar agora. Tente de novo.';
}

/**
 * Envio manual pelo painel (caixa de mensagem do chat). Só para lead que existe no bot do próprio usuário. Plain text, sem
 * formatação. A mensagem entra no histórico pelo mesmo gancho de saída, marcada como manual.
 *
 * @return array{sucesso:bool,mensagem?:string,http?:int}
 */
function chatEnviarManual(int $id_usuario, int $bot_id, string $id_telegram, string $texto): array
{
    global $pdo;
    $texto = trim($texto);
    if ($texto === '') {
        return ['sucesso' => false, 'mensagem' => 'Escreva uma mensagem.', 'http' => 422];
    }
    if (mb_strlen($texto) > 4096) {
        return ['sucesso' => false, 'mensagem' => 'A mensagem passa de 4096 caracteres.', 'http' => 422];
    }

    $stmt = $pdo->prepare('SELECT b.token FROM bots b WHERE b.id = ? AND b.id_usuario = ?
        AND EXISTS (SELECT 1 FROM leads l WHERE l.bot_id = b.id AND l.id_telegram = ?) LIMIT 1');
    $stmt->execute([$bot_id, $id_usuario, $id_telegram]);
    $token = $stmt->fetchColumn();
    if (!is_string($token) || $token === '') {
        return ['sucesso' => false, 'mensagem' => 'Conversa não encontrada.', 'http' => 404];
    }

    $ch = curl_init('https://api.telegram.org/bot' . $token . '/sendMessage');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['chat_id' => $id_telegram, 'text' => $texto]));
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $bruto = curl_exec($ch);
    $resposta = json_decode(is_string($bruto) ? $bruto : '', true);
    if (!is_array($resposta)) {
        return ['sucesso' => false, 'mensagem' => 'Não foi possível falar com o Telegram agora. Tente de novo.', 'http' => 502];
    }

    chatBotIdPorToken($token, $bot_id);
    $origem_antes = chatOrigemAtual();
    chatOrigemAtual(CHAT_ORIGEM_MANUAL);
    registrarRespostaTelegram($token, $resposta, 'sendMessage');
    chatOrigemAtual($origem_antes);
    chatDescarregar(); // já grava: a tela busca a mensagem logo em seguida

    if (($resposta['ok'] ?? false) !== true) {
        return ['sucesso' => false, 'mensagem' => chatMensagemDeErro($resposta), 'http' => 422];
    }
    return ['sucesso' => true];
}

/** Apaga o histórico mais velho que a retenção, em lotes (usado pelo cron diário). Devolve quantas linhas saíram. */
function chatLimparAntigas(int $dias = CHAT_RETENCAO_DIAS): int
{
    global $pdo;
    $stmt = $pdo->prepare('DELETE FROM chat_mensagens WHERE criado_em < (NOW() - INTERVAL ' . max(1, $dias) . ' DAY) LIMIT 1000');
    $total = 0;
    do {
        $stmt->execute();
        $lote = $stmt->rowCount();
        $total += $lote;
    } while ($lote > 0);
    return $total;
}
