<?php
declare(strict_types=1);

/**
 * Sistema de renovação do Fluxo Guiado. Configuração em dados_fluxograma['assinatura']:
 *   avisos_antes : {ativo, dias[14|7|5|3|2|1|0], midia_tipo, midia_path, mensagem}   (0 = "no dia")
 *   avisos_no_dia: {ativo, horarios["09:00", ...]}   (usa a mensagem/mídia de avisos_antes)
 *   ao_expirar   : {ativo, midia_tipo, midia_path, mensagem}
 *   renovacao    : {desconto}   (% aplicado aos planos do fluxo na oferta de renovação)
 *   acoes        : {expulsar}   (false = só encerra o acesso, sem remover do grupo)
 *
 * Quem dispara: processarAvisosAssinaturaBasico() roda no cron de 1 minuto. Cada aviso tem um
 * "marco" (ex.: d7@20261010 = 7 dias antes do vencimento de 10/10) gravado em
 * avisos_assinatura_enviados com UNIQUE(membro_id, marco) ANTES do envio: dois crons ao mesmo
 * tempo ou uma renovação (que muda a data de vencimento e, com ela, o sufixo do marco) nunca
 * repetem nem pulam um aviso.
 */

const ASSINATURA_JANELA_DIAS = 15;
const ASSINATURA_MAX_POR_EXECUCAO = 500;

function configAssinaturaBasico(?string $dados_fluxograma_json): array
{
    $dados = json_decode((string) $dados_fluxograma_json, true);
    return is_array($dados) && is_array($dados['assinatura'] ?? null) ? $dados['assinatura'] : [];
}

function saudacaoAssinatura(?int $hora = null): string
{
    $hora = $hora ?? (int) date('G');
    return $hora < 12 ? 'Bom dia' : ($hora < 18 ? 'Boa tarde' : 'Boa noite');
}

function preencherVariaveisAssinatura(string $modelo, array $vars): string
{
    $troca = [];
    foreach ($vars as $nome => $valor) {
        $troca['{' . $nome . '}'] = (string) $valor;
    }
    return strtr($modelo, $troca);
}

/** Envia texto, ou mídia com o texto na legenda (limite de 1024 do Telegram), com o teclado de renovação. */
function enviarMensagemAssinatura(string $token, string $id_chat, string $texto, array $bloco, ?array $teclado = null): bool
{
    $parametros = ['chat_id' => $id_chat];
    if ($teclado) {
        $parametros['reply_markup'] = json_encode($teclado);
    }
    $tipos = ['image' => ['sendPhoto', 'photo'], 'video' => ['sendVideo', 'video'], 'audio' => ['sendAudio', 'audio']];
    $tipo = (string) ($bloco['midia_tipo'] ?? '');
    $caminho = isset($tipos[$tipo]) ? resolverCaminhoUploadSeguro((string) ($bloco['midia_path'] ?? '')) : null;
    if ($caminho) {
        [$metodo, $campo] = $tipos[$tipo];
        $resposta = requisicaoTelegram($token, $metodo, $parametros + ['caption' => mb_substr($texto, 0, 1000)], [$campo => $caminho]);
        if ($resposta['ok'] ?? false) {
            return true;
        }
    }
    $resposta = requisicaoTelegram($token, 'sendMessage', $parametros + ['text' => $texto]);
    return (bool) ($resposta['ok'] ?? false);
}

function tecladoRenovacaoAssinatura(): array
{
    return ['inline_keyboard' => [[['text' => '🔄 Renovar agora', 'callback_data' => 'basico::renovar']]]];
}

/**
 * Nome do plano do fluxo cujo valor bate com a venda (a venda não guarda o nome do plano). Venda com upsell/bump/desconto
 * não bate com nenhum plano: devolve "atual" pra frase continuar certa ("Seu plano atual expira...", e não "Seu plano seu plano").
 */
function nomePlanoDaVenda(array $dados_fluxo, float $valor): string
{
    foreach ((array) ($dados_fluxo['planos'] ?? []) as $p) {
        if (abs((float) ($p['valor'] ?? 0) - $valor) < 0.01 && trim((string) ($p['nome'] ?? '')) !== '') {
            return trim((string) $p['nome']);
        }
    }
    return 'atual';
}

/** Dias de calendário até o vencimento (0 = vence hoje, 1 = amanhã). Conta pela data, não por blocos de 24h. */
function diasDeCalendarioAteVencimento(int $vencimento_ts, ?int $agora = null): int
{
    $agora = $agora ?? time();
    return max(0, (int) round((strtotime(date('Y-m-d', $vencimento_ts)) - strtotime(date('Y-m-d', $agora))) / 86400));
}

/** "hoje", "amanhã" ou "em N dias" — frase pronta pra encaixar em "sua assinatura expira {prazo}". */
function prazoAssinaturaTexto(int $dias): string
{
    return $dias <= 0 ? 'hoje' : ($dias === 1 ? 'amanhã' : 'em ' . $dias . ' dias');
}

function variaveisAssinatura(array $membro, array $dados_fluxo, int $segundos_restantes): array
{
    $dias = diasDeCalendarioAteVencimento(strtotime((string) $membro['data_expiracao']));
    return [
        'nome' => trim((string) ($membro['nome'] ?? '')) ?: 'cliente',
        'plano' => nomePlanoDaVenda($dados_fluxo, (float) ($membro['valor'] ?? 0)),
        'dias' => $dias,
        'prazo' => prazoAssinaturaTexto($dias),
        'data_expiracao' => date('d/m/Y', strtotime((string) $membro['data_expiracao'])),
        'saudacao' => saudacaoAssinatura(),
    ];
}

/** Marcos que valem para este membro agora (cada um já no formato gravado em avisos_assinatura_enviados). */
function marcosDeAvisoDevidos(array $config, array $membro, int $agora): array
{
    $vencimento = strtotime((string) $membro['data_expiracao']);
    $restante = $vencimento - $agora;
    if ($restante <= 0) {
        return [];
    }
    $sufixo = '@' . date('Ymd', $vencimento);
    $duracao = $vencimento - strtotime((string) ($membro['entrou_em'] ?? $membro['data_expiracao']));
    $marcos = [];

    $antes = $config['avisos_antes'] ?? [];
    if (!empty($antes['ativo'])) {
        foreach ((array) ($antes['dias'] ?? []) as $n) {
            $n = (int) $n;
            if ($n >= 1 && $n <= 14 && $restante <= $n * 86400 && $restante > ($n - 1) * 86400 && $duracao > $n * 86400 + 3600) {
                $marcos[] = 'd' . $n . $sufixo;
            }
            if ($n === 0 && date('Ymd', $agora) === date('Ymd', $vencimento)) {
                $marcos[] = 'd0' . $sufixo;
            }
        }
    }
    $no_dia = $config['avisos_no_dia'] ?? [];
    if (!empty($no_dia['ativo']) && date('Ymd', $agora) === date('Ymd', $vencimento)) {
        foreach (array_slice((array) ($no_dia['horarios'] ?? []), 0, 3) as $horario) {
            if (preg_match('/^(\d{2}):00$/', (string) $horario, $m) && (int) $m[1] === (int) date('G', $agora)) {
                $marcos[] = 'h' . $m[1] . $sufixo;
            }
        }
    }
    return $marcos;
}

/** Reserva o marco; false = já tinha sido enviado (ou reservado por outro processo). */
function reservarMarcoAssinatura(PDO $pdo, int $membro_id, string $marco): bool
{
    $stmt = $pdo->prepare("INSERT IGNORE INTO avisos_assinatura_enviados (membro_id, marco, enviado_em) VALUES (?, ?, NOW())");
    $stmt->execute([$membro_id, $marco]);
    return $stmt->rowCount() > 0;
}

function processarAvisosAssinaturaBasico(PDO $pdo): int
{
    $stmt = $pdo->prepare("
        SELECT m.id, m.id_telegram, m.data_expiracao, m.criado_em AS entrou_em, m.bot_id,
               b.token, f.dados_fluxograma, v.valor,
               (SELECT l.nome FROM leads l WHERE l.bot_id = m.bot_id AND l.id_telegram = m.id_telegram ORDER BY l.id DESC LIMIT 1) AS nome
        FROM membros_grupos m
        JOIN bots b ON b.id = m.bot_id
        JOIN fluxos f ON f.id = b.id_fluxo_conectado
        LEFT JOIN vendas v ON v.id = m.venda_id
        WHERE m.status = 'ativo' AND m.data_expiracao > NOW()
          AND m.data_expiracao <= NOW() + INTERVAL " . ASSINATURA_JANELA_DIAS . " DAY
          AND f.dados_fluxograma LIKE '%\"assinatura\"%'
        ORDER BY m.data_expiracao ASC LIMIT " . ASSINATURA_MAX_POR_EXECUCAO);
    $stmt->execute();
    $enviados = 0;
    $agora = time();
    foreach ($stmt->fetchAll() as $membro) {
        $dados_fluxo = json_decode((string) $membro['dados_fluxograma'], true) ?: [];
        $config = $dados_fluxo['assinatura'] ?? [];
        $marcos = marcosDeAvisoDevidos($config, $membro, $agora);
        if (!$marcos) {
            continue;
        }
        // Se vários marcos caírem no mesmo minuto (ex.: "1 dia" e "No dia"), só vai um aviso.
        $enviar = false;
        foreach ($marcos as $marco) {
            $enviar = reservarMarcoAssinatura($pdo, (int) $membro['id'], $marco) || $enviar;
        }
        if (!$enviar) {
            continue;
        }
        $antes = $config['avisos_antes'] ?? [];
        $modelo = trim((string) ($antes['mensagem'] ?? '')) ?: 'Olá {nome}! Sua assinatura do plano {plano} expira {prazo}. Renove agora e continue aproveitando!';
        $texto = preencherVariaveisAssinatura($modelo, variaveisAssinatura($membro, $dados_fluxo, strtotime((string) $membro['data_expiracao']) - $agora));
        if (enviarMensagemAssinatura((string) $membro['token'], (string) $membro['id_telegram'], $texto, $antes, tecladoRenovacaoAssinatura())) {
            $enviados++;
        }
    }
    $pdo->exec("DELETE FROM avisos_assinatura_enviados WHERE enviado_em < NOW() - INTERVAL 60 DAY");
    return $enviados;
}

/** Texto + bloco (mídia) da mensagem de expiração do fluxo, ou null para manter a mensagem padrão do cron. */
function mensagemAoExpirarBasico(?string $dados_fluxograma_json, array $membro): ?array
{
    $dados_fluxo = json_decode((string) $dados_fluxograma_json, true) ?: [];
    $bloco = $dados_fluxo['assinatura']['ao_expirar'] ?? [];
    if (empty($bloco['ativo'])) {
        return null;
    }
    $modelo = trim((string) ($bloco['mensagem'] ?? '')) ?: '{nome}, sua assinatura expirou! Renove agora para continuar com acesso ao conteúdo exclusivo.';
    $membro['data_expiracao'] = $membro['data_expiracao'] ?? date('Y-m-d H:i:s');
    return ['texto' => preencherVariaveisAssinatura($modelo, variaveisAssinatura($membro, $dados_fluxo, 0)), 'bloco' => $bloco];
}

/** Descontos de renovação: planos do fluxo com o % de desconto aplicado (desconto 0 = preço cheio). */
function planosDeRenovacaoBasico(array $dados_basico): array
{
    $desconto = max(0.0, min(90.0, (float) ($dados_basico['assinatura']['renovacao']['desconto'] ?? 0)));
    // Menor valor que o gateway aceita com a comissão atual: se o desconto levaria o plano abaixo disso, o desconto
    // é limitado a esse mínimo; plano que já é mais barato que o mínimo nem entra (o Pix falharia).
    $minimo = function_exists('getGatewaySplit') && function_exists('precoMinimoOmegaPayments')
        ? precoMinimoOmegaPayments(getGatewaySplit('omegapayments'))
        : 0.0;
    $lista = [];
    foreach ((array) ($dados_basico['planos'] ?? []) as $p) {
        $cheio = round((float) ($p['valor'] ?? 0), 2);
        if ($cheio <= 0 || empty($p['id']) || ($minimo > 0 && $cheio < $minimo)) {
            continue;
        }
        $valor = max(round($cheio * (1 - $desconto / 100), 2), $minimo);
        $lista[] = ['plano' => $p, 'valor' => $valor, 'desconto' => $cheio > 0 ? round((1 - $valor / $cheio) * 100, 1) : 0];
    }
    return $lista;
}

function enviarOfertaRenovacaoBasico(string $token, $id_chat, array $dados_basico): void
{
    $itens = planosDeRenovacaoBasico($dados_basico);
    if (!$itens) {
        requisicaoTelegram($token, 'sendMessage', ['chat_id' => $id_chat, 'text' => 'Nenhum plano disponível para renovar no momento.']);
        return;
    }
    $botoes = [];
    foreach ($itens as $i) {
        $rotulo = trim((string) ($i['plano']['nome'] ?? 'Plano')) . ' — R$ ' . number_format($i['valor'], 2, ',', '.') . ($i['desconto'] > 0 ? ' (-' . rtrim(rtrim(number_format($i['desconto'], 1, '.', ''), '0'), '.') . '%)' : '');
        $botoes[] = [['text' => $rotulo, 'callback_data' => 'basico::renovplano::' . $i['plano']['id']]];
    }
    $texto = ($itens[0]['desconto'] > 0) ? 'Renove agora com desconto especial 👇' : 'Escolha o plano para renovar 👇';
    requisicaoTelegram($token, 'sendMessage', ['chat_id' => $id_chat, 'text' => $texto, 'reply_markup' => json_encode(['inline_keyboard' => $botoes])]);
}

function gerarPixRenovacaoBasico(string $token, $id_chat, array $dados_basico, string $id_plano): void
{
    foreach (planosDeRenovacaoBasico($dados_basico) as $i) {
        if ((string) $i['plano']['id'] !== $id_plano) {
            continue;
        }
        $pagamentos = $dados_basico['pagamentos'] ?? [];
        processarEEnviarBloco($token, $id_chat, ['properties' => [
            'type' => 'pix',
            'nome' => trim((string) ($i['plano']['nome'] ?? 'Renovação')),
            'valor' => $i['valor'],
            'tipo_cobranca' => 'unica',
            'expiracao_minutos' => 15,
            'dias_acesso' => (int) ($i['plano']['dias_acesso'] ?? 30),
            'unidade_acesso' => $i['plano']['unidade_acesso'] ?? 'dias',
            'id_grupo' => $i['plano']['id_grupo'] ?? '',
            'mostrar_copiar' => $pagamentos['mostrar_copiar'] ?? true,
            'mostrar_qrcode' => !empty($pagamentos['qrcode_definido']) ? !empty($pagamentos['mostrar_qrcode']) : true,
            'mostrar_confirmar' => $pagamentos['mostrar_confirmar'] ?? true,
            'msg_instrucoes' => $pagamentos['msg_instrucoes'] ?? '',
            'msg_confirmado' => $pagamentos['msg_confirmado'] ?? '',
        ]], '');
        return;
    }
    requisicaoTelegram($token, 'sendMessage', ['chat_id' => $id_chat, 'text' => 'Esse plano não está mais disponível para renovação.']);
}
