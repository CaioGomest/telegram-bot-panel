<?php
declare(strict_types=1);

require_once __DIR__ . '/fluxo_blocos.php';

/**
 * Execução do Editor Visual (grafo de blocos). Antes, três lugares tinham a própria cópia do "vai bloco por
 * bloco": o webhook do Telegram, o webhook de pagamento e a cron do Pix — e duas delas ignoravam o sorteio
 * e o delay. Agora é um só: executarFluxoDesde().
 *
 * - Delay: espera de verdade (mín./máx., segundos ou minutos, "digitando…"). Até 10 s o próprio processo espera;
 *   acima disso a continuação é gravada em `fluxo_continuacoes` e a cron de 1 minuto retoma (precisão de ~1 min).
 * - Ofertas (upsell/downsell/order bump): a resposta do cliente fica guardada em `fluxo_ofertas_aceitas` e o
 *   próximo bloco Pix aplica o desconto / soma o valor extra.
 */

const FLUXO_DELAY_MAXIMO_SEGUNDOS = 604800;   // 7 dias
const FLUXO_DELAY_ESPERA_SEM_LIBERAR = 5;     // até aqui dorme sem soltar o Telegram (como sempre foi)
const FLUXO_DELAY_ESPERA_NO_WEBHOOK = 10;     // até aqui, no webhook, solta o Telegram (200) e dorme
const FLUXO_OFERTAS_VALIDADE_HORAS = 12;
const FLUXO_BLOCOS_INTERATIVOS = ['botoes', 'pix', 'upsell', 'downsell', 'order_bump'];

// ===== Navegação pelo grafo =====

function obterProximoNo(array $links, string $id_atual): ?string
{
    foreach ($links as $link) {
        if (($link['fromOperator'] ?? '') === $id_atual) {
            return $link['toOperator'] ?? null;
        }
    }
    return null;
}

function obterProximoNoPorConector(array $links, string $id_atual, string $conector): ?string
{
    foreach ($links as $link) {
        if (($link['fromOperator'] ?? '') === $id_atual && ($link['fromConnector'] ?? '') === $conector) {
            return $link['toOperator'] ?? null;
        }
    }
    return null;
}

/**
 * Próximo nó considerando tipos que decidem a saída sozinhos (hoje só o sorteio, que escolhe por peso). Os outros
 * tipos com várias saídas (pix, ofertas, botões) param a execução e são resolvidos por evento externo.
 */
function proximoNoConsiderandoTipo(array $dados_fluxo, string $id_atual, array $propriedades_atual): ?string
{
    if (($propriedades_atual['type'] ?? '') === 'randomizer') {
        $caminhos = $propriedades_atual['caminhos'] ?? [];
        $peso_total = 0.0;
        foreach ($caminhos as $c) {
            $peso_total += max(0, (float) ($c['peso'] ?? 0));
        }
        if ($peso_total <= 0 || empty($caminhos)) {
            return obterProximoNo($dados_fluxo['links'] ?? [], $id_atual);
        }
        $sorteio = (mt_rand() / mt_getrandmax()) * $peso_total;
        $acumulado = 0.0;
        $indice_escolhido = 0;
        foreach (array_values($caminhos) as $i => $c) {
            $acumulado += max(0, (float) ($c['peso'] ?? 0));
            if ($sorteio <= $acumulado) {
                $indice_escolhido = $i;
                break;
            }
        }
        return obterProximoNoPorConector($dados_fluxo['links'] ?? [], $id_atual, 'output_path_' . $indice_escolhido);
    }
    return obterProximoNo($dados_fluxo['links'] ?? [], $id_atual);
}

// ===== Delay =====

/** Espera sorteada entre o mínimo e o máximo do bloco, em segundos (0 = sem espera). */
function segundosDoDelay(array $propriedades): int
{
    $fator = ($propriedades['delay_unidade'] ?? 'seg') === 'min' ? 60 : 1;
    $minimo = max(0, (int) ($propriedades['delay_min'] ?? 0));
    $maximo = max($minimo, (int) ($propriedades['delay_max'] ?? 0));
    $valor = $maximo > $minimo ? random_int($minimo, $maximo) : $minimo;
    return min($valor * $fator, FLUXO_DELAY_MAXIMO_SEGUNDOS);
}

function liberarRequisicaoSePossivel(): bool
{
    if (PHP_SAPI === 'cli') {
        return true; // cron: ninguém está esperando resposta
    }
    ignore_user_abort(true);
    @set_time_limit(FLUXO_DELAY_ESPERA_NO_WEBHOOK + 30);
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
        return true;
    }
    if (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
        return true;
    }
    return false;
}

/** Dorme $segundos; com $digitando, mostra "digitando…" no Telegram (a ação dura ~5 s, então repete). */
function esperarComDigitando(string $token, $id_chat, int $segundos, bool $digitando): void
{
    // microtime: com time() (segundos inteiros) uma espera de 1 s podia durar só ~0,1 s se começasse no fim do segundo.
    $fim = microtime(true) + $segundos;
    while (($restante = $fim - microtime(true)) > 0) {
        if ($digitando) {
            requisicaoTelegram($token, 'sendChatAction', ['chat_id' => $id_chat, 'action' => 'typing']);
            $restante = $fim - microtime(true);
            if ($restante <= 0) {
                break;
            }
        }
        usleep((int) (min($restante, 4) * 1000000));
    }
}

function agendarContinuacaoFluxo(PDO $pdo, int $bot_id, string $id_telegram, int $id_fluxo, string $id_operador_destino, int $segundos): bool
{
    try {
        $pdo->prepare("INSERT INTO fluxo_continuacoes (bot_id, id_telegram, id_fluxo, id_operador_destino, executar_em, status, criado_em)
                       VALUES (?, ?, ?, ?, NOW() + INTERVAL ? SECOND, 'pendente', NOW())")
            ->execute([$bot_id, $id_telegram, $id_fluxo, $id_operador_destino, $segundos]);
        return true;
    } catch (\Throwable $e) {
        error_log('[delay] não agendou a continuação: ' . $e->getMessage());
        return false;
    }
}

/** Cliente mandou /start de novo: as esperas pendentes dele (da jornada anterior) não devem mais disparar. */
function cancelarContinuacoesDoLead(PDO $pdo, int $bot_id, string $id_telegram): void
{
    try {
        $pdo->prepare("UPDATE fluxo_continuacoes SET status = 'cancelado' WHERE bot_id = ? AND id_telegram = ? AND status = 'pendente'")->execute([$bot_id, $id_telegram]);
    } catch (\Throwable $e) {
        // tabela ainda não existe: nada a cancelar
    }
}

/** Retoma as esperas vencidas (chamado pelo cron de 1 minuto). Retorna quantas continuações foram executadas. */
function processarContinuacoesFluxo(PDO $pdo, int $limite = 50): int
{
    // "processando" esquecido (cron morreu no meio) não é repetido: o cliente poderia receber duas vezes.
    $pdo->exec("UPDATE fluxo_continuacoes SET status = 'falhou' WHERE status = 'processando' AND atualizado_em < NOW() - INTERVAL 15 MINUTE");
    $stmt = $pdo->prepare("SELECT c.*, b.token FROM fluxo_continuacoes c JOIN bots b ON b.id = c.bot_id
                           WHERE c.status = 'pendente' AND c.executar_em <= NOW() ORDER BY c.executar_em ASC LIMIT " . (int) $limite);
    $stmt->execute();
    $feitas = 0;
    foreach ($stmt->fetchAll() as $c) {
        garantirConexaoBanco(); // execuções anteriores do laço podem ter demorado mais que o wait_timeout
        $pdo = $GLOBALS['pdo'];
        $reivindicou = $pdo->prepare("UPDATE fluxo_continuacoes SET status = 'processando' WHERE id = ? AND status = 'pendente'");
        $reivindicou->execute([$c['id']]);
        if ($reivindicou->rowCount() === 0) {
            continue;
        }
        try {
            $dados = $pdo->prepare("SELECT dados_fluxograma FROM fluxos WHERE id = ?");
            $dados->execute([$c['id_fluxo']]);
            $dados_fluxo = json_decode((string) $dados->fetchColumn(), true);
            if (!is_array($dados_fluxo)) {
                throw new \RuntimeException('fluxo não encontrado');
            }
            executarFluxoDesde((string) $c['token'], (string) $c['id_telegram'], (int) $c['bot_id'], (int) $c['id_fluxo'], $dados_fluxo, (string) $c['id_operador_destino']);
            $pdo->prepare("UPDATE fluxo_continuacoes SET status = 'feito' WHERE id = ?")->execute([$c['id']]);
            $feitas++;
        } catch (\Throwable $e) {
            error_log('[delay] continuação #' . $c['id'] . ' falhou: ' . $e->getMessage());
            $pdo->prepare("UPDATE fluxo_continuacoes SET status = 'falhou' WHERE id = ?")->execute([$c['id']]);
        }
    }
    $pdo->exec("DELETE FROM fluxo_continuacoes WHERE status IN ('feito','falhou','cancelado') AND criado_em < NOW() - INTERVAL 7 DAY");
    return $feitas;
}

// ===== Ofertas (upsell / downsell / order bump) =====

/** Guarda (ou apaga) a resposta do cliente a uma oferta: o próximo Pix usa isso pra ajustar o valor. */
function registrarRespostaOferta(PDO $pdo, int $bot_id, string $id_telegram, array $propriedades, bool $aceitou): void
{
    $tipo = (string) ($propriedades['type'] ?? '');
    if (!in_array($tipo, ['upsell', 'downsell', 'order_bump'], true)) {
        return;
    }
    try {
        if (!$aceitou) {
            $pdo->prepare("DELETE FROM fluxo_ofertas_aceitas WHERE bot_id = ? AND id_telegram = ? AND tipo = ?")->execute([$bot_id, $id_telegram, $tipo]);
            return;
        }
        $pdo->prepare("INSERT INTO fluxo_ofertas_aceitas (bot_id, id_telegram, tipo, desconto_percentual, valor_extra, nome, criado_em)
                       VALUES (?, ?, ?, ?, ?, ?, NOW())
                       ON DUPLICATE KEY UPDATE desconto_percentual = VALUES(desconto_percentual), valor_extra = VALUES(valor_extra), nome = VALUES(nome), criado_em = NOW()")
            ->execute([
                $bot_id, $id_telegram, $tipo,
                max(0.0, min(100.0, (float) ($propriedades['desconto_percentual'] ?? 0))),
                max(0.0, (float) ($propriedades['valor_extra'] ?? 0)),
                mb_substr(trim((string) ($propriedades['nome'] ?? $propriedades['title'] ?? '')), 0, 100),
            ]);
    } catch (\Throwable $e) {
        error_log('[ofertas] não gravou a resposta: ' . $e->getMessage());
    }
}

function limparOfertasDoLead(PDO $pdo, int $bot_id, string $id_telegram): void
{
    try {
        $pdo->prepare("DELETE FROM fluxo_ofertas_aceitas WHERE bot_id = ? AND id_telegram = ?")->execute([$bot_id, $id_telegram]);
    } catch (\Throwable $e) {
        // tabela ainda não existe
    }
}

/**
 * Aplica ao bloco Pix as ofertas que o cliente aceitou nesta jornada: o último desconto aceito (upsell ou
 * downsell) reduz o valor e o order bump soma o valor extra. Nunca deixa o valor abaixo do mínimo aceito pelo gateway.
 */
function aplicarOfertasAceitasAoPix(PDO $pdo, int $bot_id, string $id_telegram, array $propriedades): array
{
    $valor_base = (float) ($propriedades['valor'] ?? 0);
    if ($valor_base <= 0) {
        return $propriedades;
    }
    try {
        $stmt = $pdo->prepare("SELECT tipo, desconto_percentual, valor_extra, nome FROM fluxo_ofertas_aceitas
                               WHERE bot_id = ? AND id_telegram = ? AND criado_em > NOW() - INTERVAL " . FLUXO_OFERTAS_VALIDADE_HORAS . " HOUR ORDER BY criado_em ASC");
        $stmt->execute([$bot_id, $id_telegram]);
        $aceitas = $stmt->fetchAll();
    } catch (\Throwable $e) {
        return $propriedades;
    }
    if (!$aceitas) {
        return $propriedades;
    }
    $desconto = 0.0;
    $extra = 0.0;
    $nome_extra = '';
    foreach ($aceitas as $o) {
        if ($o['tipo'] === 'order_bump') {
            $extra = (float) $o['valor_extra'];
            $nome_extra = trim((string) $o['nome']);
        } else {
            $desconto = (float) $o['desconto_percentual'];
        }
    }
    $novo = round($valor_base * (1 - $desconto / 100) + $extra, 2);
    if (function_exists('getGatewaySplit') && function_exists('precoMinimoOmegaPayments')) {
        $minimo = precoMinimoOmegaPayments(getGatewaySplit('omegapayments'));
        if ($valor_base >= $minimo) {
            $novo = max($novo, $minimo);
        }
    }
    $propriedades['valor'] = $novo;
    if ($extra > 0) {
        $propriedades['nome'] = trim((string) ($propriedades['nome'] ?? 'Produto')) . ' + ' . ($nome_extra !== '' ? $nome_extra : 'Extra');
    }
    return $propriedades;
}

// ===== Execução =====

/**
 * Percorre o fluxo a partir de $id_partida: envia cada bloco, resolve sorteio e delay, e para nos blocos que esperam
 * o cliente (botões, Pix, ofertas). Delay longo grava a continuação e encerra esta execução.
 */
function executarFluxoDesde(string $token, $id_chat, int $bot_id, int $id_fluxo, array $dados_fluxo, ?string $id_partida): void
{
    global $pdo;
    $operadores = $dados_fluxo['operators'] ?? [];
    $proximo_id = $id_partida;
    $liberou = false;
    for ($passos = 0; $proximo_id && isset($operadores[$proximo_id]) && $passos < 200; $passos++) {
        $operador = $operadores[$proximo_id];
        $propriedades = $operador['properties'] ?? [];
        $tipo = $propriedades['type'] ?? '';

        if ($tipo === 'delay') {
            $depois = proximoNoConsiderandoTipo($dados_fluxo, $proximo_id, $propriedades);
            $segundos = segundosDoDelay($propriedades);
            if ($segundos > 0) {
                $cabe_inline = $segundos <= FLUXO_DELAY_ESPERA_SEM_LIBERAR
                    || ($segundos <= FLUXO_DELAY_ESPERA_NO_WEBHOOK && ($liberou || ($liberou = liberarRequisicaoSePossivel())));
                if ($cabe_inline) {
                    esperarComDigitando($token, $id_chat, $segundos, !empty($propriedades['delay_digitando']));
                    garantirConexaoBanco(); // o MySQL fecha conexão ociosa em 20 s
                } else {
                    if ($depois) {
                        agendarContinuacaoFluxo($pdo, $bot_id, (string) $id_chat, $id_fluxo, $depois, $segundos);
                    }
                    return;
                }
            }
            $proximo_id = $depois;
            continue;
        }

        processarEEnviarBloco($token, $id_chat, $operador, $proximo_id);
        if (in_array($tipo, FLUXO_BLOCOS_INTERATIVOS, true)) {
            break;
        }
        $proximo_id = proximoNoConsiderandoTipo($dados_fluxo, $proximo_id, $propriedades);
    }
}
