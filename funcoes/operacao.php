<?php
declare(strict_types=1);

/**
 * Peças de operação em escala (08/10/2026). Só funções; carregado por conexao.php, então existe em toda página, webhook e cron.
 *
 *  - colunaExiste():            confere (uma vez por processo) se uma coluna nova já foi criada pela migração; o código novo
 *                               convive com banco ainda sem a migração (cai no jeito antigo).
 *  - aposPagamentoConfirmado(): chamado pelos 3 caminhos que confirmam pagamento (webhook da gateway, botão "Já fiz o
 *                               pagamento" e cron). Marca o lead como pagante (leads.pagou) e avisa o dono se a venda de
 *                               Fluxo Guiado não tem grupo para entregar.
 *  - condicaoLeadPagou():       condição SQL "lead pagou / não pagou" (coluna indexada; EXISTS por venda se a migração não rodou).
 *  - registrarInicioConversa(): uma linha em leads_starts por /start (lead novo ou quem voltou); é o que o dashboard conta como "starts".
 *  - contagemEmCache():         COUNT caro com resultado guardado por alguns segundos (paginadores de tabelas enormes).
 *  - registrarRespostaTelegram(): chamado por todo ponto que fala com a API do Telegram; token recusado (401/404) vira
 *                               aviso ao dono, uma vez. Com o nome do método (send*), a mensagem aceita entra no histórico
 *                               do chat do lead (funcoes/chat.php).
 *  - resumoPayloadGateway():    versão enxuta (sem dados do pagador nem código Pix) do payload do webhook, para o log.
 */

function colunaExiste(string $tabela, string $coluna): bool
{
    static $cache = [];
    $chave = $tabela . '.' . $coluna;
    if (array_key_exists($chave, $cache)) {
        return $cache[$chave];
    }
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
        $stmt->execute([$tabela, $coluna]);
        $cache[$chave] = (bool) $stmt->fetchColumn();
    } catch (\Throwable $e) {
        $cache[$chave] = false;
    }
    return $cache[$chave];
}

/**
 * $venda precisa ter id, bot_id, id_telegram e, se houver, id_grupo_telegram / id_operador_fluxo / nome_produto / valor.
 * Nunca lança: um erro aqui não pode derrubar a confirmação do pagamento.
 */
function aposPagamentoConfirmado(array $venda): void
{
    global $pdo;
    // Aviso push de Pix pago (só com o interruptor geral ligado; o código de push nem é carregado desligado). Bloco próprio: uma falha aqui
    // nunca impede o resto da confirmação, e o resto nunca impede o aviso.
    try {
        require_once __DIR__ . '/configuracoes.php';
        if (configSistema('push_ativo') === '1') {
            require_once __DIR__ . '/push.php';
            $id_dono_push = (int) ($venda['id_dono'] ?? 0);
            if ($id_dono_push <= 0) {
                $stmt_dono_push = $pdo->prepare("SELECT id_usuario FROM bots WHERE id = ?");
                $stmt_dono_push->execute([(int) ($venda['bot_id'] ?? 0)]);
                $id_dono_push = (int) $stmt_dono_push->fetchColumn();
            }
            enfileirarPush($id_dono_push, PUSH_TIPO_PIX_PAGO, (int) ($venda['id'] ?? 0));
        }
    } catch (\Throwable $e) {
        error_log('[operacao] aviso push (Pix pago): ' . $e->getMessage());
    }
    try {
        // 1) lead vira "pagante" (filtros Pagou/Não pagou de Leads e público do remarketing leem esta coluna, com índice).
        if (colunaExiste('leads', 'pagou')) {
            $pdo->prepare("UPDATE leads SET pagou = 1 WHERE bot_id = ? AND id_telegram = ? AND pagou = 0")
                ->execute([(int) $venda['bot_id'], (string) $venda['id_telegram']]);
        }

        // 2) venda do Fluxo Guiado (sem bloco de fluxo) paga sem grupo, sem link e sem mensagem de entrega: o cliente recebe "pagamento confirmado" e nenhum acesso.
        $sem_grupo = trim((string) ($venda['id_grupo_telegram'] ?? '')) === '' && trim((string) ($venda['link_entrega'] ?? '')) === ''
            && trim((string) ($venda['mensagem_entrega'] ?? '')) === '';
        $sem_bloco = empty($venda['id_operador_fluxo']);
        if ($sem_grupo && $sem_bloco) {
            $stmt_dono = $pdo->prepare("SELECT id_usuario FROM bots WHERE id = ?");
            $stmt_dono->execute([(int) $venda['bot_id']]);
            $id_dono = (int) $stmt_dono->fetchColumn();
        } else {
            $id_dono = 0;
        }
        if ($id_dono > 0) {
            require_once __DIR__ . '/log.php';
            // No máximo 1 aviso por dia por conta: quem vende de propósito sem grupo (entrega pela mensagem) não pode levar um alerta por venda.
            $stmt_ja = $pdo->prepare("SELECT 1 FROM atividades WHERE id_usuario = ? AND titulo = 'Venda sem grupo' AND criado_em > NOW() - INTERVAL 1 DAY LIMIT 1");
            $stmt_ja->execute([$id_dono]);
            if (!$stmt_ja->fetchColumn()) {
                $produto = trim((string) ($venda['nome_produto'] ?? '')) ?: 'o plano';
                registrarAtividade(
                    $id_dono,
                    'sistema',
                    'Venda sem grupo',
                    'A venda #' . (int) $venda['id'] . ' (' . $produto . ', R$ ' . number_format((float) ($venda['valor'] ?? 0), 2, ',', '.')
                        . ') foi paga, mas o plano não tem grupo, link nem mensagem de entrega: o cliente só recebeu a mensagem de pagamento aprovado, sem acesso. '
                        . 'Se não era isso que você queria, escolha um grupo, um link ou uma mensagem na entrega do plano, no editor do fluxo. Este aviso aparece no máximo 1 vez por dia.'
                );
            }
        }
    } catch (\Throwable $e) {
        error_log('[operacao] aposPagamentoConfirmado: ' . $e->getMessage());
    }
}

/**
 * Olha a resposta de qualquer chamada ao Telegram. 401 (Unauthorized) e 404 (Not Found, que é como o Telegram responde a token
 * que não existe mais) = token revogado/trocado. Marca o bot e avisa o dono no sino, só na primeira vez.
 */
function registrarRespostaTelegram(string $token, $resposta, string $metodo = ''): void
{
    if (!is_array($resposta)) {
        return;
    }
    if (($resposta['ok'] ?? true) !== false) {
        // Mensagem aceita pelo Telegram (send*): entra no histórico do chat do lead, em lote (funcoes/chat.php).
        if ($metodo !== '' && strncmp($metodo, 'send', 4) === 0 && ($resposta['ok'] ?? false) === true) {
            require_once __DIR__ . '/chat.php';
            chatRegistrarEnvio($token, $resposta, $metodo);
        }
        return;
    }
    $codigo = (int) ($resposta['error_code'] ?? 0);
    $descricao = strtolower((string) ($resposta['description'] ?? ''));
    if (!($codigo === 401 || ($codigo === 404 && strpos($descricao, 'not found') !== false))) {
        return;
    }
    static $ja = [];
    if (isset($ja[$token]) || !colunaExiste('bots', 'token_invalido_em')) {
        return;
    }
    $ja[$token] = true;
    global $pdo;
    try {
        $stmt = $pdo->prepare("UPDATE bots SET token_invalido_em = NOW() WHERE token = ? AND token_invalido_em IS NULL");
        $stmt->execute([$token]);
        if ($stmt->rowCount() > 0) {
            $stmt = $pdo->prepare("SELECT id_usuario, nome_usuario FROM bots WHERE token = ? LIMIT 1");
            $stmt->execute([$token]);
            if ($bot = $stmt->fetch()) {
                require_once __DIR__ . '/log.php';
                registrarAtividade(
                    (int) $bot['id_usuario'],
                    'sistema',
                    'Bot desconectado',
                    'O Telegram recusou o token do bot @' . $bot['nome_usuario'] . ' (token revogado ou trocado). Ele parou de atender e de enviar mensagens. Cole o token novo em Meus Bots → Configurar.'
                );
            }
        }
    } catch (\Throwable $e) {
        error_log('[operacao] registrarRespostaTelegram: ' . $e->getMessage());
    }
}

/** Bot voltou a ter token válido (o dono colou o token novo): limpa o aviso. */
function limparTokenInvalido(int $bot_id): void
{
    global $pdo;
    if (!colunaExiste('bots', 'token_invalido_em')) {
        return;
    }
    try {
        $pdo->prepare("UPDATE bots SET token_invalido_em = NULL WHERE id = ?")->execute([$bot_id]);
    } catch (\Throwable $e) {
    }
}

/**
 * Resumo do payload de webhook de gateway para o log: só o que ajuda a investigar (evento, ids, status, valor, ciclo).
 * Sem nome/e-mail/telefone/documento do pagador e sem o código Pix. Se não for JSON, só o tamanho.
 */
function resumoPayloadGateway(string $corpo): string
{
    $j = json_decode($corpo, true);
    if (!is_array($j)) {
        return 'corpo não-JSON (' . strlen($corpo) . ' bytes)';
    }
    $t = is_array($j['transaction'] ?? null) ? $j['transaction'] : [];
    $s = is_array($j['subscription'] ?? null) ? $j['subscription'] : null;
    $partes = [
        'evento=' . (string) ($j['event'] ?? '?'),
        'transacao=' . (string) ($t['id'] ?? '?'),
        'status=' . (string) ($t['status'] ?? '?'),
        'valor=' . (string) ($t['amount'] ?? '?'),
    ];
    if ($s) {
        $partes[] = 'assinatura=' . (string) ($s['id'] ?? '?') . ' ciclo=' . (string) ($s['cycle'] ?? '?');
    }
    return implode(' | ', $partes) . ' (' . strlen($corpo) . ' bytes)';
}

/**
 * Condição SQL para "o lead já pagou?" (sem o AND na frente). Com a coluna leads.pagou é só um índice; sem ela (banco ainda
 * sem a migração) cai no EXISTS por venda, que dá o mesmo resultado, só que mais lento.
 */
function condicaoLeadPagou(bool $pagou, string $alias = 'l'): string
{
    if (colunaExiste('leads', 'pagou')) {
        return "{$alias}.pagou = " . ($pagou ? 1 : 0);
    }
    $existe = "EXISTS (SELECT 1 FROM vendas v WHERE v.id_telegram = {$alias}.id_telegram AND v.bot_id = {$alias}.bot_id AND v.status = 'pago')";
    return $pagou ? $existe : "NOT $existe";
}

/**
 * Guarda o resultado de um COUNT caro por $ttl segundos (arquivo em storage/cache/). É para a numeração de página de listas
 * com centenas de milhares de linhas (ex.: Admin → Transações filtrado): o total pode estar alguns segundos defasado,
 * o que ninguém percebe, e o MySQL deixa de contar a tabela inteira a cada clique. Falha de cache nunca atrapalha:
 * se a pasta não grava, só conta de novo.
 */
function contagemEmCache(string $chave, callable $calcula, int $ttl = 60): int
{
    $dir = dirname(__DIR__) . '/storage/cache';
    $arquivo = $dir . '/cont_' . sha1($chave) . '.txt';
    if (is_file($arquivo) && (time() - (int) @filemtime($arquivo)) < $ttl) {
        $v = @file_get_contents($arquivo);
        if ($v !== false && ctype_digit(trim($v))) {
            return (int) $v;
        }
    }
    $total = (int) $calcula();
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    @file_put_contents($arquivo, (string) $total, LOCK_EX);
    return $total;
}

/**
 * Registra um início de conversa (/start). O dashboard conta "starts" por aqui e não por leads.criado_em: lead só nasce uma vez,
 * então quem já era lead e volta (ou compra de novo) não aparecia como start, e o painel mostrava vendas com 0 starts.
 * Repetições do mesmo lead em menos de 10 minutos (toques seguidos em "Recomeçar") não contam de novo.
 */
function registrarInicioConversa(PDO $pdo, int $bot_id, int $lead_id, bool $lead_novo): void
{
    if (!$lead_novo) {
        $stmt = $pdo->prepare('SELECT 1 FROM leads_starts WHERE lead_id = ? AND criado_em > ? LIMIT 1');
        $stmt->execute([$lead_id, date('Y-m-d H:i:s', time() - 600)]);
        if ($stmt->fetchColumn()) {
            return;
        }
    }
    $pdo->prepare('INSERT INTO leads_starts (lead_id, bot_id, criado_em) VALUES (?, ?, ?)')->execute([$lead_id, $bot_id, date('Y-m-d H:i:s')]);
}
