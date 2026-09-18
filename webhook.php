<?php
declare(strict_types=1);
date_default_timezone_set('America/Sao_Paulo');
require_once 'conexao.php';
require_once __DIR__ . '/funcoes/gateways.php';
require_once __DIR__ . '/funcoes/infopago_split.php';
const DIRETORIO_UPLOADS = __DIR__ . '/uploads';
/** CNPJ fixo (65.915.116/0001-04) para todas as cobranças PIX recorrentes — apenas dígitos */
const CNPJ_PIX_RECORRENTE_FIXO = '65915116000104';
function requisicaoTelegram(string $token, string $metodo, array $parametros = [], array $arquivos = []): array
{
    $url = 'https://api.telegram.org/bot' . $token . '/' . $metodo;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    if (!empty($arquivos)) {
        foreach ($arquivos as $campo => $caminho) {
            if (file_exists($caminho)) {
                $mime = function_exists('mime_content_type') ? (mime_content_type($caminho) ?: 'application/octet-stream') : 'application/octet-stream';
                $parametros[$campo] = new CURLFile($caminho, $mime, basename($caminho));
            }
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $parametros);
    } else {
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($parametros));
    }
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    // Upload de mídia (foto/vídeo/áudio do fluxo) precisa de mais tempo que uma mensagem de texto simples.
    curl_setopt($ch, CURLOPT_TIMEOUT, !empty($arquivos) ? 30 : 15);
    $resposta = curl_exec($ch);
    curl_close($ch);
    $decodificado = json_decode($resposta ?: '', true);
    return is_array($decodificado) ? $decodificado : ['ok' => false, 'description' => 'resposta inválida'];
}
/**
 * Resolve um caminho de mídia salvo em dados_fluxograma (image_path/video_path/audio_path)
 * garantindo que o resultado fica de fato dentro de uploads/. Sem essa checagem, um dono de
 * bot podia gravar um caminho arbitrário (ex. "config.php" ou "certificados/cert_5_1.pem")
 * direto via API e fazer o próprio bot reenviar esse arquivo pra ele — vazando credenciais
 * da plataforma ou de outro usuário. Só o formato salvo pelos endpoints de upload
 * ("uploads/nome_gerado.ext") é aceito; qualquer outra coisa retorna null (não envia nada).
 */
function resolverCaminhoUploadSeguro(string $caminho): ?string
{
    if ($caminho === '' || strpos($caminho, 'uploads/') !== 0) {
        return null;
    }
    $base_real = realpath(DIRETORIO_UPLOADS);
    if ($base_real === false) {
        return null;
    }
    $real = realpath(__DIR__ . '/' . $caminho);
    if ($real === false) {
        return null;
    }
    if ($real !== $base_real && strpos($real, $base_real . DIRECTORY_SEPARATOR) !== 0) {
        return null;
    }
    return $real;
}
function buscarProximoDoInicio(array $dados): ?string
{
    $operadores = $dados['operators'] ?? [];
    $conexoes = $dados['links'] ?? [];
    $id_inicio = null;
    foreach ($operadores as $id => $op) {
        if (($op['properties']['type'] ?? '') === 'start') {
            $id_inicio = $id;
            break;
        }
    }
    if (!$id_inicio) {
        return null;
    }
    foreach ($conexoes as $conexao) {
        if (($conexao['fromOperator'] ?? '') === $id_inicio) {
            return $conexao['toOperator'] ?? null;
        }
    }
    return null;
}
function caminhoEstadoPixRecorrente(): string
{
    return __DIR__ . '/storage/pix_recorrente_estado.json';
}
function carregarEstadoPixRecorrente(): array
{
    $arquivo = caminhoEstadoPixRecorrente();
    if (!file_exists($arquivo)) {
        return [];
    }
    $conteudo = file_get_contents($arquivo);
    $dados = json_decode($conteudo ?: '{}', true);
    return is_array($dados) ? $dados : [];
}
function salvarEstadoPixRecorrente(array $estado): void
{
    $arquivo = caminhoEstadoPixRecorrente();
    $diretorio = dirname($arquivo);
    if (!is_dir($diretorio)) {
        @mkdir($diretorio, 0775, true);
    }
    file_put_contents($arquivo, json_encode($estado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}
function chaveEstadoPixRecorrente(int $bot_id, string $id_chat): string
{
    return $bot_id . ':' . $id_chat;
}
function obterEstadoPixRecorrente(int $bot_id, string $id_chat): ?array
{
    $estado = carregarEstadoPixRecorrente();
    $chave = chaveEstadoPixRecorrente($bot_id, $id_chat);
    return isset($estado[$chave]) && is_array($estado[$chave]) ? $estado[$chave] : null;
}
function limparEstadoPixRecorrente(int $bot_id, string $id_chat): void
{
    $estado = carregarEstadoPixRecorrente();
    $chave = chaveEstadoPixRecorrente($bot_id, $id_chat);
    if (!isset($estado[$chave])) {
        return;
    }
    unset($estado[$chave]);
    salvarEstadoPixRecorrente($estado);
}
function processarEEnviarBloco(string $token, $id_chat, array $operador, string $id_operador = '', string $documento_comprador = ''): void
{
    $propriedades = $operador['properties'] ?? [];
    $tipo = $propriedades['type'] ?? '';
    if ($tipo === 'message') {
        $texto = trim((string) ($propriedades['conteudo'] ?? $propriedades['body'] ?? ''));
        if ($texto !== '') {
            requisicaoTelegram($token, 'sendMessage', ['chat_id' => $id_chat, 'text' => $texto]);
        }
        return;
    }
    if ($tipo === 'image') {
        $caminho_absoluto = resolverCaminhoUploadSeguro((string) ($propriedades['image_path'] ?? ''));
        if ($caminho_absoluto === null) {
            return;
        }
        $parametros = ['chat_id' => $id_chat];
        $legenda = trim((string) ($propriedades['caption'] ?? ''));
        if ($legenda !== '') {
            $parametros['caption'] = $legenda;
        }
        $modo = $propriedades['mode'] ?? 'foto';
        if ($modo === 'documento') {
            requisicaoTelegram($token, 'sendDocument', $parametros, ['document' => $caminho_absoluto]);
        } else {
            if (!empty($propriedades['spoiler'])) {
                $parametros['has_spoiler'] = true;
            }
            requisicaoTelegram($token, 'sendPhoto', $parametros, ['photo' => $caminho_absoluto]);
        }
        return;
    }
    if ($tipo === 'video') {
        $caminho_absoluto = resolverCaminhoUploadSeguro((string) ($propriedades['video_path'] ?? ''));
        if ($caminho_absoluto === null) {
            return;
        }
        $parametros = ['chat_id' => $id_chat];
        $legenda = trim((string) ($propriedades['caption'] ?? ''));
        if ($legenda !== '') {
            $parametros['caption'] = $legenda;
        }
        if (!empty($propriedades['spoiler'])) {
            $parametros['has_spoiler'] = true;
        }
        requisicaoTelegram($token, 'sendVideo', $parametros, ['video' => $caminho_absoluto]);
        return;
    }
    if ($tipo === 'audio') {
        $caminho_absoluto = resolverCaminhoUploadSeguro((string) ($propriedades['audio_path'] ?? ''));
        if ($caminho_absoluto === null) {
            return;
        }
        $parametros = ['chat_id' => $id_chat];
        $legenda = trim((string) ($propriedades['caption'] ?? ''));
        if ($legenda !== '') {
            $parametros['caption'] = $legenda;
        }
        // Tenta enviar como Note de Voz (sendVoice) para parecer gravado na hora
        // Se falhar (por formato não suportado), poderíamos tentar fallback para sendAudio,
        // mas o Telegram costuma aceitar mp3/m4a/ogg como voice.
        $resposta = requisicaoTelegram($token, 'sendVoice', $parametros, ['voice' => $caminho_absoluto]);
        // Se falhar o envio como voice (ex: formato inválido para voice), tenta como áudio normal
        if (!($resposta['ok'] ?? false)) {
             requisicaoTelegram($token, 'sendAudio', $parametros, ['audio' => $caminho_absoluto]);
        }
        return;
    }
    if ($tipo === 'grupo') {
        $id_grupo = $propriedades['id_grupo'] ?? '';
        $texto_botao = $propriedades['texto_botao'] ?? 'Entrar no Grupo';
        if (empty($id_grupo)) {
            requisicaoTelegram($token, 'sendMessage', ['chat_id' => $id_chat, 'text' => 'Erro: Grupo não configurado.']);
            return;
        }
        $invite = requisicaoTelegram($token, 'createChatInviteLink', [
            'chat_id' => $id_grupo,
            'member_limit' => 1,
            'expire_date' => time() + (15 * 60),
            'name' => 'Acesso via Bot'
        ]);
        $link = '';
        if (($invite['ok'] ?? false) && isset($invite['result']['invite_link'])) {
            $link = $invite['result']['invite_link'];
        } else {
            // Se falhar (ex: bot não é admin), tenta pegar link permanente se disponível ou avisa erro
            $export = requisicaoTelegram($token, 'exportChatInviteLink', ['chat_id' => $id_grupo]);
            if (($export['ok'] ?? false)) {
                $link = $export['result'];
            } else {
                requisicaoTelegram($token, 'sendMessage', ['chat_id' => $id_chat, 'text' => 'Não foi possível gerar o link do grupo. Verifique se o bot é administrador do grupo.']);
                return;
            }
        }
        $teclado = [
            'inline_keyboard' => [[
                ['text' => $texto_botao, 'url' => $link]
            ]]
        ];
        requisicaoTelegram($token, 'sendMessage', [
            'chat_id' => $id_chat,
            'text' => "Clique abaixo para entrar:",
            'reply_markup' => json_encode($teclado)
        ]);
        return;
    }
    if ($tipo === 'link') {
        $url = trim((string)($propriedades['url'] ?? ''));
        $texto_botao = trim((string)($propriedades['texto_botao'] ?? '')) ?: 'Acessar';
        if (empty($url)) {
            requisicaoTelegram($token, 'sendMessage', ['chat_id' => $id_chat, 'text' => 'Erro: link não configurado.']);
            return;
        }
        requisicaoTelegram($token, 'sendMessage', [
            'chat_id' => $id_chat,
            'text' => "Clique abaixo:",
            'reply_markup' => json_encode(['inline_keyboard' => [[['text' => $texto_botao, 'url' => $url]]]])
        ]);
        return;
    }
    if ($tipo === 'botoes') {
        $texto = trim((string) ($propriedades['texto'] ?? ''));
        $botoes = $propriedades['botoes'] ?? [];
        $keyboard = [];
        $current_row = [];
        foreach ($botoes as $btn_texto) {
            $current_row[] = ['text' => $btn_texto, 'callback_data' => $btn_texto];
            if (count($current_row) >= 2) {
                $keyboard[] = $current_row;
                $current_row = [];
            }
        }
        if (!empty($current_row)) {
            $keyboard[] = $current_row;
        }
        $parametros = [
            'chat_id' => $id_chat,
            'text' => $texto ?: 'Escolha:',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ])
        ];
        requisicaoTelegram($token, 'sendMessage', $parametros);
        return;
    }
    if ($tipo === 'pix') {
        global $pdo;
        $stmt_bot = $pdo->prepare("SELECT id_usuario FROM bots WHERE token = ?");
        $stmt_bot->execute([$token]);
        $id_usuario_dono = $stmt_bot->fetchColumn();
        if (!$id_usuario_dono) {
            requisicaoTelegram($token, 'sendMessage', ['chat_id' => $id_chat, 'text' => 'Erro interno: Bot não identificado.']);
            return;
        }
        $gateways_usuario = getUserGateways((int)$id_usuario_dono, true);
        $stmt_bot_real = $pdo->prepare("SELECT id FROM bots WHERE token = ?");
        $stmt_bot_real->execute([$token]);
        $id_bot_real = $stmt_bot_real->fetchColumn();
        $id_bot_insert = $id_bot_real ? (int)$id_bot_real : null;
        $stmt_nome = $pdo->prepare("SELECT nome FROM leads WHERE id_telegram = ? AND bot_id = ?");
        $stmt_nome->execute([$id_chat, $id_bot_insert]);
        $nome_usuario = $stmt_nome->fetchColumn() ?: "Cliente Telegram";
        $nome_usuario = substr($nome_usuario, 0, 50);

        if (empty($gateways_usuario)) {
            requisicaoTelegram($token, 'sendMessage', ['chat_id' => $id_chat, 'text' => 'Erro: Nenhum gateway configurado para este usuário.']);
            return;
        }

        $tentativas = [];
        $gateway_selecionado = null;
        $resp = ['sucesso' => false];
        $provedor = null;

        $eh_recorrente = ($propriedades['tipo_cobranca'] ?? 'unica') === 'recorrente';
        $periodicidade_recorrente = strtolower((string)($propriedades['periodicidade'] ?? 'mensal'));
        if ($eh_recorrente) {
            $documento_limpo = CNPJ_PIX_RECORRENTE_FIXO;
            $tipo_documento = 'CNPJ';
        } else {
            $documento_limpo = preg_replace('/\D/', '', $documento_comprador);
            $tipo_documento = strlen($documento_limpo) === 14 ? 'CNPJ' : 'CPF';
        }

        if ($eh_recorrente && $periodicidade_recorrente === 'semanal') {
            requisicaoTelegram($token, 'sendMessage', [
                'chat_id' => $id_chat,
                'text' => 'Periodicidade semanal nao esta disponivel no momento. Use mensal, trimestral, semestral ou anual.'
            ]);
            return;
        }

        foreach ($gateways_usuario as $gw) {
            $nome_gateway = $gw['gateway_nome'] ?? '';

            // Gateway PF não suporta PIX Recorrente — pula para o próximo
            if ($eh_recorrente && ($gw['tipo_conta'] ?? 'pj') === 'pf') {
                $tentativas[] = "Gateway {$nome_gateway} é conta PF, não suporta PIX Recorrente";
                continue;
            }

            $incompleto = empty($gw['client_id']) || empty($gw['client_secret']) || empty($gw['chave_pix']);
            if ($incompleto) {
                $tentativas[] = "Gateway {$nome_gateway} não configurado completamente";
                continue;
            }

            $provedor = resolveGatewayProvider($nome_gateway, $gw);
            if (!$provedor) {
                $tentativas[] = "Provedor $nome_gateway não suportado";
                continue;
            }

            $split_data = null;
            $user_splits = getUserSplits((int)$id_usuario_dono, $nome_gateway);
            if (!empty($user_splits)) {
                $split_data = array_map(fn($s) => [
                    'chave' => $s['chave_pix_split'],
                    'valor' => $s['taxa_split'],
                    'tipo'  => $s['tipo_split'] ?? 'percentual'
                ], $user_splits);
            }

            $chave_pix = $gw['chave_pix'] ?? '';
            if (empty($chave_pix)) {
                $tentativas[] = "Gateway {$nome_gateway} sem chave Pix de recebedor";
                continue;
            }

            $valor = (float)($propriedades['valor'] ?? 0);
            if ($valor <= 0) {
                requisicaoTelegram($token, 'sendMessage', ['chat_id' => $id_chat, 'text' => 'Erro: Valor inválido para o pagamento.']);
                return;
            }

            $eh_recorrente_oficial = false;
            $id_assinatura = null;

            try {
                $tempo_expiracao = (int)($propriedades['expiracao_minutos'] ?? $propriedades['tempo_nao_pago'] ?? 15);
                $expiracao_segundos = $tempo_expiracao * 60;

                if ($eh_recorrente && $nome_gateway === 'infopago') {
                    // ── InfoPago: PIX Automático, Jornada 3 (QR Code composto com cobrança imediata) ──
                    // Paga na hora (acesso liberado igual ao Pix único) e já autoriza a renovação
                    // automática no mesmo QR. Corrigido (2026-07-07): o passo /locrec não faz parte
                    // dessa jornada (dava 403 AcessoNegado — endpoint errado, não falta de permissão).
                    // Fluxo real: /cob/{txid} (cobrança imediata) → /rec (com ativacao.dadosJornada.txid
                    // vinculando a cobrança) → GET /rec/{idRec}?txid={txid} (QR composto em dadosQR.pixCopiaECola).
                    $periodicidade = $propriedades['periodicidade'] ?? 'mensal';

                    $payload_cobranca = $provedor->montaPayloadCobranca($valor, $chave_pix, $split_data, $expiracao_segundos);
                    $resp_cobranca = $provedor->criarCobranca($payload_cobranca);
                    if (!($resp_cobranca['sucesso'] ?? false)) {
                        $tentativas[] = "{$nome_gateway} criarCobranca (imediata p/ recorrência) falhou: " . ($resp_cobranca['erro'] ?? 'desconhecido');
                        continue;
                    }
                    $txid_imediata = $resp_cobranca['dados']['txid'] ?? '';

                    $payload_rec = $provedor->montaPayloadRecorrencia(
                        $valor,
                        null,
                        $periodicidade,
                        $nome_usuario,
                        $documento_limpo,
                        $propriedades['nome'] ?? 'Assinatura',
                        $txid_imediata
                    );
                    $resp_rec = $provedor->criarRecorrencia($payload_rec);
                    if (!($resp_rec['sucesso'] ?? false)) {
                        $tentativas[] = "{$nome_gateway} criarRecorrencia falhou: " . ($resp_rec['erro'] ?? 'desconhecido');
                        continue;
                    }
                    $id_assinatura = $resp_rec['dados']['idRec'] ?? null;
                    if (!$id_assinatura) {
                        $tentativas[] = "{$nome_gateway} idRec ausente na resposta da recorrência";
                        continue;
                    }

                    $resp_consulta_rec = $provedor->consultarRecorrencia($id_assinatura, $txid_imediata);
                    if (!($resp_consulta_rec['sucesso'] ?? false)) {
                        $tentativas[] = "{$nome_gateway} consultarRecorrencia falhou: " . ($resp_consulta_rec['erro'] ?? 'desconhecido');
                        continue;
                    }

                    $eh_recorrente_oficial = true;
                    $resp = $resp_cobranca;
                    // QR composto (paga + autoriza recorrência); some pra trás pro copia-e-cola simples da cobrança se a API não devolver o composto.
                    $pix_copia_cola  = $resp_consulta_rec['dados']['dadosQR']['pixCopiaECola'] ?? ($resp_cobranca['dados']['pixCopiaECola'] ?? '');
                    $txid          = $txid_imediata;
                    $link_pagamento = '';

                } else {
                    $payload = $provedor->montaPayloadCobranca($valor, $chave_pix, $split_data, $expiracao_segundos);

                    $resp = $provedor->criarCobranca($payload);
                    if (!($resp['sucesso'] ?? false)) {
                        $tentativas[] = "{$nome_gateway} criarCobranca falhou: " . ($resp['erro'] ?? 'desconhecido');
                        continue;
                    }

                    // InfoPago já devolve o pixCopiaECola direto na criação da cobrança (sem passo extra de QR code).
                    $pix_copia_cola  = $resp['dados']['pixCopiaECola'] ?? '';
                    $txid          = $resp['dados']['txid'] ?? '';
                    $link_pagamento = '';
                }

                $gateway_selecionado = $gw;
                break;
            } catch (Exception $e) {
                $tentativas[] = "{$nome_gateway} causou exceção: " . $e->getMessage();
                continue;
            }
        }

        if (!$gateway_selecionado || !($resp['sucesso'] ?? false)) {
            $erro_msg = "Erro ao gerar Pix. Tentativas:\n" . implode("\n", $tentativas);
            requisicaoTelegram($token, 'sendMessage', ['chat_id' => $id_chat, 'text' => $erro_msg]);
            return;
        }

        $valor = (float)($propriedades['valor'] ?? 0);
        $nome_produto = $propriedades['nome'] ?? 'Produto';
        $eh_recorrente = ($propriedades['tipo_cobranca'] ?? 'unica') === 'recorrente';

        if ($resp['sucesso']) {
            $msg = "✅ *Pedido Criado com Sucesso!*\n\n";
            $msg .= "🛒 *Produto:* $nome_produto\n";
            $msg .= "💲 *Valor:* R$ " . number_format($valor, 2, ',', '.') . "\n\n";
            $msg .= "⏳ *Aguardando pagamento...*\n";
            $msg .= "Seu acesso será liberado automaticamente em até 1 minuto após a confirmação do pagamento.\n\n";
            $msg .= "👇 *Toque no código abaixo para copiar e pague no app do seu banco:*";
            $teclado_pix = null;
            if (!empty($propriedades['mostrar_confirmar'])) {
                $teclado_pix = [
                    'inline_keyboard' => [
                        [
                            ['text' => 'Já fiz o pagamento ✅', 'callback_data' => 'verificar_pagamento_' . $txid]
                        ]
                    ]
                ];
            }
            try {
                $dias_acesso = isset($propriedades['dias_acesso']) ? (int)$propriedades['dias_acesso'] : 30;
                $unidade_acesso = $propriedades['unidade_acesso'] ?? 'dias';
                $tempo_minutos = 0;
                if ($eh_recorrente) {
                    switch($propriedades['periodicidade'] ?? 'mensal') {
                        case 'semanal': $tempo_minutos = 7 * 1440; break;
                        case 'trimestral': $tempo_minutos = 90 * 1440; break;
                        case 'semestral': $tempo_minutos = 180 * 1440; break;
                        case 'anual': $tempo_minutos = 365 * 1440; break;
                        default: $tempo_minutos = 30 * 1440;
                    }
                    // Mantém compatibilidade com coluna dias_acesso para recorrência
                    $dias_acesso = (int)($tempo_minutos / 1440);
                } else {
                    switch($unidade_acesso) {
                        case 'minutos':
                            $tempo_minutos = $dias_acesso;
                            $dias_acesso = 0; // Marca 0 dias pois é menos que 1 dia (ou não exato)
                            break;
                        case 'horas':
                            $tempo_minutos = $dias_acesso * 60;
                            $dias_acesso = 0; 
                            break;
                        case 'dias':
                        default:
                            $tempo_minutos = $dias_acesso * 1440;
                            break;
                    }
                }
                $id_grupo_acesso = $propriedades['id_grupo'] ?? null;
                if (empty($id_grupo_acesso) || $id_grupo_acesso === '0' || $id_grupo_acesso === '') {
                     $id_grupo_acesso = null;
                }
                // Força a conversão do idOperador para garantir que não seja vazio/nulo se estiver dentro do fluxo
                if (empty($id_operador)) $id_operador = null;
                // Força os tipos de dados para evitar erro silencioso de banco
                $dias_acesso_insert = (int) $dias_acesso;
                $tempo_minutos_insert = (int) $tempo_minutos;
                $tempo_expiracao_insert = (int) $tempo_expiracao;
                $id_usuario_dono_insert = (int) $id_usuario_dono;
                $valor_insert = (float) $valor;
                // A chave estrangeira vendas_ibfk_1 falha se passarmos um idUsuarioDono em bot_id.
                // Na tabela vendas, a coluna 'bot_id' deve receber o ID do bot, e não o ID do usuário dono do bot.
                // Mas não temos o ID do bot na função processarEEnviarBloco.
                // Vamos buscar o ID real do bot usando o token
                $stmt_bot_real = $pdo->prepare("SELECT id FROM bots WHERE token = ?");
                $stmt_bot_real->execute([$token]);
                $id_bot_real = $stmt_bot_real->fetchColumn();
                $id_bot_insert = $id_bot_real ? (int)$id_bot_real : null;
                file_put_contents(__DIR__ . '/logs/vendas_debug.log', "[" . date('Y-m-d H:i:s') . "] TENTANDO INSERIR VENDA - TXID: $txid | OPERADOR: $id_operador | BOT_ID: $id_bot_insert\n", FILE_APPEND);
                $data_criacao = date('Y-m-d H:i:s');
                $stmt_venda = $pdo->prepare("INSERT INTO vendas (id_telegram, bot_id, valor, status, transacao_id, id_grupo_telegram, dias_acesso, tempo_acesso_minutos, id_operador_fluxo, id_gateway, tempo_expiracao_minutos, criado_em, tipo_cobranca, id_assinatura) VALUES (?, ?, ?, 'gerado', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $executou = $stmt_venda->execute([
                    $id_chat,
                    $id_bot_insert,
                    $valor_insert,
                    $txid,
                    $id_grupo_acesso,
                    $dias_acesso_insert,
                    $tempo_minutos_insert,
                    $id_operador,
                    $gateway_selecionado['gateway_id'] ?? $gateway_selecionado['id_gateway'] ?? null,
                    $tempo_expiracao_insert,
                    $data_criacao,
                    $eh_recorrente_oficial ? 'assinatura' : 'unica',
                    $id_assinatura
                ]);
                if (!$executou) {
                     file_put_contents(__DIR__ . '/logs/vendas_debug.log', "[" . date('Y-m-d H:i:s') . "] ERRO PDO: " . print_r($stmt_venda->errorInfo(), true) . "\n", FILE_APPEND);
                } else {
                     file_put_contents(__DIR__ . '/logs/vendas_debug.log', "[" . date('Y-m-d H:i:s') . "] VENDA INSERIDA COM SUCESSO!\n", FILE_APPEND);
                     if ($id_usuario_dono_insert) {
                         require_once __DIR__ . '/funcoes/log.php';
                         $valor_formatado = number_format($valor_insert, 2, ',', '.');
                         registrarAtividade($id_usuario_dono_insert, 'pix_gerado', 'Pix Gerado', "Novo PIX de R$ {$valor_formatado} foi gerado.");

                         require_once __DIR__ . '/funcoes/traqueamento.php';
                         // Tenta buscar dados do lead se houver (futuro: email/telefone)
                         $dados_evento = [
                             'valor' => $valor_insert,
                             'nome_produto' => $nome_produto,
                             'transacao_id' => $txid,
                             'event_id' => $txid
                         ];
                         $user_data = [
                             'id_telegram' => $id_chat,
                         ];
                         enviarEventosTraqueamento($id_usuario_dono_insert, 'pix_gerado', $dados_evento, $user_data);
                     }
                }
            } catch (Exception $e) {
                file_put_contents(__DIR__ . '/logs/vendas_debug.log', "[" . date('Y-m-d H:i:s') . "] EXCECAO: " . $e->getMessage() . "\n", FILE_APPEND);
            }
            requisicaoTelegram($token, 'sendMessage', [
                'chat_id' => $id_chat,
                'text' => $msg,
                'parse_mode' => 'Markdown'
            ]);
            if (!empty($propriedades['mostrar_qrcode'])) {
                 $url_qr_code = '';
                 // Prioriza gerar o QR Code a partir do Copia e Cola para garantir compatibilidade
                 if (!empty($pix_copia_cola)) {
                     $url_qr_code = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($pix_copia_cola);
                     file_put_contents(__DIR__ . "/logs/vendas_debug.log", "[" . date("Y-m-d H:i:s") . "] GERANDO QR CODE VIA API: $url_qr_code\n", FILE_APPEND);
                 } elseif (!empty($link_pagamento) && strpos($link_pagamento, '/loc//') === false) {
                     $url_qr_code = $link_pagamento;
                     file_put_contents(__DIR__ . "/logs/vendas_debug.log", "[" . date("Y-m-d H:i:s") . "] USANDO LINK DE PAGAMENTO: $url_qr_code\n", FILE_APPEND);
                 }
                 
                 if ($url_qr_code) {
                     $res = requisicaoTelegram($token, 'sendPhoto', [
                        'chat_id' => $id_chat, 
                        'photo' => $url_qr_code,
                        'caption' => 'Escaneie o QR Code acima para pagar.'
                     ]);
                     file_put_contents(__DIR__ . "/logs/vendas_debug.log", "[" . date("Y-m-d H:i:s") . "] SEND PHOTO RES: " . json_encode($res) . "\n", FILE_APPEND);
                 } else {
                     file_put_contents(__DIR__ . "/logs/vendas_debug.log", "[" . date("Y-m-d H:i:s") . "] SEM URL QR CODE\n", FILE_APPEND);
                 }
            }
            if ($pix_copia_cola) {
                $params = [
                    'chat_id' => $id_chat, 
                    'text' => "<code>$pix_copia_cola</code>",
                    'parse_mode' => 'HTML'
                ];
                if ($teclado_pix) {
                    $params['reply_markup'] = json_encode($teclado_pix);
                }
                requisicaoTelegram($token, 'sendMessage', $params);
            }
        } else {
            requisicaoTelegram($token, 'sendMessage', ['chat_id' => $id_chat, 'text' => 'Erro ao gerar Pix: ' . ($resp['erro'] ?? 'Desconhecido')]);
        }
        return;
    }
}
http_response_code(200);
$token = $_GET['token'] ?? '';
if ($token === '') {
    echo 'token ausente';
    exit;
}
try {
    $stmt = $pdo->prepare("SELECT * FROM bots WHERE token = ?");
    $stmt->execute([$token]);
    $bot = $stmt->fetch();
} catch (PDOException $e) {
    echo 'erro db';
    exit;
}
if (!$bot) {
    echo 'bot não encontrado';
    exit;
}

// Confirma que a notificação realmente veio do Telegram (e não de alguém que
// descobriu essa URL). Só passa a exigir depois que o bot tiver um segredo
// configurado — bots antigos continuam funcionando até serem reconectados
// (o que gera o segredo automaticamente).
if (!empty($bot['webhook_secret'])) {
    $segredo_recebido = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
    if (!hash_equals((string)$bot['webhook_secret'], $segredo_recebido)) {
        http_response_code(403);
        echo 'assinatura inválida';
        exit;
    }
}

$entrada = file_get_contents('php://input');
$atualizacao = json_decode($entrada ?: '{}', true);
if (isset($atualizacao['my_chat_member'])) {
    $chat = $atualizacao['my_chat_member']['chat'];
    $novo_status = $atualizacao['my_chat_member']['new_chat_member']['status'] ?? '';
    if (in_array($novo_status, ['member', 'administrator', 'creator'])) {
        $stmt = $pdo->prepare("
            INSERT INTO bot_grupos (bot_id, id_telegram, titulo, tipo) 
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE titulo = VALUES(titulo), tipo = VALUES(tipo)
        ");
        $stmt->execute([
            $bot['id'], 
            (string)$chat['id'], 
            $chat['title'] ?? 'Sem Título', 
            $chat['type'] ?? 'group'
        ]);
        registrarAtividade($bot['id_usuario'], 'sistema', 'Grupo', "Bot adicionado ao grupo: " . ($chat['title'] ?? $chat['id']));
    }
    elseif (in_array($novo_status, ['left', 'kicked'])) {
        $stmt = $pdo->prepare("DELETE FROM bot_grupos WHERE bot_id = ? AND id_telegram = ?");
        $stmt->execute([$bot['id'], (string)$chat['id']]);
        registrarAtividade($bot['id_usuario'], 'sistema', 'Grupo', "Bot removido do grupo: " . ($chat['title'] ?? $chat['id']));
    }
    echo 'ok status';
    exit;
}
$id_chat = null;
$texto = '';
if (isset($atualizacao['callback_query'])) {
    $id_chat = (string) $atualizacao['callback_query']['message']['chat']['id'];
    $texto = (string) $atualizacao['callback_query']['data'];
    requisicaoTelegram($token, 'answerCallbackQuery', ['callback_query_id' => $atualizacao['callback_query']['id']]);
} elseif (isset($atualizacao['message']['chat']['id'])) {
    $id_chat = (string) $atualizacao['message']['chat']['id'];
    $texto = trim((string) ($atualizacao['message']['text'] ?? ''));
    if (isset($atualizacao['message']['contact']['phone_number'])) {
        $telefone_lead = preg_replace('/\D/', '', $atualizacao['message']['contact']['phone_number']);
        try {
            $pdo->prepare("UPDATE leads SET telefone = ? WHERE id_telegram = ? AND bot_id = ?")
                ->execute([$telefone_lead, $id_chat, $bot['id']]);
        } catch (Exception $e) {}
        echo 'ok';
        exit;
    }
}
if ($id_chat && isset($atualizacao['message']['text'])) {
    $estado_documento = obterEstadoPixRecorrente((int)$bot['id'], $id_chat);
    if ($estado_documento) {
        limparEstadoPixRecorrente((int)$bot['id'], $id_chat);
        requisicaoTelegram($token, 'sendMessage', [
            'chat_id' => $id_chat,
            'text' => 'O fluxo de pagamento foi atualizado. Toque de novo no botao ou passo de PIX recorrente no menu para continuar.'
        ]);
        exit;
    }
}
// Extrai parâmetro do /start (ex: "/start campanha_maio" → $start_param = "campanha_maio")
$start_param = null;
if (preg_match('/^\/start\s+(.+)$/i', $texto, $start_match)) {
    $start_param = trim($start_match[1]);
    $texto = '/start'; // normaliza para o fluxo tratar igual ao /start comum
}
if (strpos($texto, 'verificar_pagamento_') === 0) {
    $txid = str_replace('verificar_pagamento_', '', $texto);
    try {
        $stmt = $pdo->prepare("SELECT * FROM vendas WHERE transacao_id = ?");
        $stmt->execute([$txid]);
        $venda = $stmt->fetch();
        if ($venda) {
            if ($venda['status'] === 'pago') {
                requisicaoTelegram($token, 'sendMessage', ['chat_id' => $id_chat, 'text' => '✅ Seu pagamento já foi confirmado!']);
            } else {
                $stmt_dono = $pdo->prepare("SELECT id_usuario FROM bots WHERE id = ?");
                $stmt_dono->execute([$venda['bot_id']]);
                $id_usuario_dono = $stmt_dono->fetchColumn();

                $nome_gw_venda = null;
                if (!empty($venda['id_gateway'])) {
                    $stmt_gw_nome = $pdo->prepare("SELECT nome FROM gateways WHERE id = ?");
                    $stmt_gw_nome->execute([$venda['id_gateway']]);
                    $nome_gw_venda = $stmt_gw_nome->fetchColumn() ?: null;
                }
                if (!$nome_gw_venda) {
                    $nome_gw_venda = 'infopago';
                }

                $gateway_config = getUserGatewayConfig((int)$id_usuario_dono, $nome_gw_venda);
                $debug_log = __DIR__ . '/logs/verificar_pag_debug.log';
                file_put_contents($debug_log, '[' . date('Y-m-d H:i:s') . '] TXID=' . $txid . ' | gateway=' . $nome_gw_venda . ' | gatewayConfig=' . ($gateway_config ? 'ok' : 'NULL') . PHP_EOL, FILE_APPEND);
                if ($gateway_config) {
                    $prov_verif = resolveGatewayProvider($nome_gw_venda, $gateway_config);
                    $resp = $prov_verif ? $prov_verif->consultarCobranca($txid) : ['sucesso' => false];
                    $status_verif = strtoupper(trim($resp['dados']['status'] ?? $resp['dados']['statusCob'] ?? ''));
                    file_put_contents($debug_log, '[' . date('Y-m-d H:i:s') . '] resp=' . json_encode($resp) . ' | statusVerif=' . $status_verif . PHP_EOL, FILE_APPEND);
                    if ($resp['sucesso'] && in_array($status_verif, ['CONCLUIDA', 'PAGO', 'LIQUIDADO', 'PAID', 'APPROVED', 'COMPLETED'])) {
                        // Transição atômica: o UPDATE só afeta a linha se ela ainda não estava paga.
                        // Se o webhook do InfoPago ou o cron de fallback confirmarem a mesma venda
                        // ao mesmo tempo, só um dos dois ganha a corrida (rowCount() = 1) e segue
                        // adiante — evita disparar o split duas vezes pra mesma venda.
                        $stmt_marca = $pdo->prepare("UPDATE vendas SET status = 'pago', pago_em = NOW() WHERE id = ? AND status != 'pago'");
                        $stmt_marca->execute([$venda['id']]);
                        if ($stmt_marca->rowCount() === 0) {
                             // Já foi processado por outra requisição simultânea. Para aqui.
                             exit;
                        }

                        if ($nome_gw_venda === 'infopago') {
                            dispararSplitInfopago((int)$id_usuario_dono, (float)$venda['valor'], $txid, (int)$venda['id']);
                        }

                        require_once __DIR__ . '/funcoes/traqueamento.php';
                        $nome_lead_manual = '';
                        try {
                            $stmt_lead = $pdo->prepare("SELECT nome FROM leads WHERE id_telegram = ? AND bot_id = ?");
                            $stmt_lead->execute([$venda['id_telegram'], $venda['bot_id']]);
                            $nome_lead_manual = $stmt_lead->fetchColumn() ?: '';
                        } catch (Exception $e) {}

                        $dados_evento_manual = [
                            'valor' => (float)$venda['valor'],
                            'transacao_id' => $txid,
                            'event_id' => $txid
                        ];
                        $user_data_manual = [
                            'id_telegram' => $venda['id_telegram'],
                            'first_name' => $nome_lead_manual
                        ];

                        enviarEventosTraqueamento((int)$id_usuario_dono, 'compra', $dados_evento_manual, $user_data_manual);

                         $msg = "✅ *Pagamento Confirmado!*\n\nObrigado pela sua compra.";
                        if (!empty($venda['id_grupo_telegram'])) {
                            $id_grupo = $venda['id_grupo_telegram'];
                            // Usa a mesma lógica de cálculo de minutos que foi usada na criação da venda
                            $minutos_acesso = $venda['tempo_acesso_minutos'] ?? 0;
                            if ($minutos_acesso <= 0) {
                                // Fallback para dias se tempo_acesso_minutos estiver zerado (legado)
                                $minutos_acesso = ($venda['dias_acesso'] ?? 0) * 1440;
                            }

                            // Estende a partir da expiração atual se o acesso ainda estiver ativo (ex.
                            // renovação confirmada antes de vencer) -- em vez de sempre "agora + plano",
                            // que descartaria os dias que o cliente ainda tinha pagos. Mesma lógica já
                            // usada em webhook_infopago.php::liberarAcessoGrupoInfopago() e
                            // cron/cron_verificar_pix.php.
                            $stmt_membro_atual = $pdo->prepare("SELECT data_expiracao FROM membros_grupos WHERE id_telegram = ? AND id_grupo_telegram = ? AND bot_id = ?");
                            $stmt_membro_atual->execute([$venda['id_telegram'], $id_grupo, $venda['bot_id']]);
                            $expiracao_atual_manual = $stmt_membro_atual->fetchColumn();
                            if ($expiracao_atual_manual && strtotime($expiracao_atual_manual) > time()) {
                                $data_expiracao = date('Y-m-d H:i:s', strtotime($expiracao_atual_manual) + ($minutos_acesso * 60));
                            } else {
                                $data_expiracao = date('Y-m-d H:i:s', time() + ($minutos_acesso * 60));
                            }

                            // Revoga link antigo do mesmo usuário para impedir reuso/compartilhamento.
                            $stmt_link_anterior = $pdo->prepare("SELECT invite_link FROM membros_grupos WHERE id_telegram = ? AND id_grupo_telegram = ? AND bot_id = ? LIMIT 1");
                            $stmt_link_anterior->execute([$venda['id_telegram'], $id_grupo, $venda['bot_id']]);
                            $link_anterior = (string)($stmt_link_anterior->fetchColumn() ?: '');
                            if ($link_anterior !== '') {
                                requisicaoTelegram($token, 'revokeChatInviteLink', [
                                    'chat_id' => $id_grupo,
                                    'invite_link' => $link_anterior
                                ]);
                            }
                            $invite = requisicaoTelegram($token, 'createChatInviteLink', [
                                'chat_id' => $id_grupo,
                                'member_limit' => 1,
                                'expire_date' => time() + (15 * 60),
                                'name' => 'Venda #' . $venda['id']
                            ]);
                            $link = (($invite['ok'] ?? false) && isset($invite['result']['invite_link'])) ? $invite['result']['invite_link'] : null;

                            // Roda incondicionalmente -- mesmo se a criação do link falhar (Telegram
                            // fora do ar, bot sem permissão, etc.), a venda já foi marcada 'pago' e não
                            // será reprocessada por nenhum outro caminho, então o acesso/expiração
                            // precisa ser gravado de qualquer forma (COALESCE mantém o link antigo no
                            // banco só como registro; ele já foi revogado acima, não funciona mais).
                            $pdo->prepare("
                                INSERT INTO membros_grupos (id_telegram, id_grupo_telegram, bot_id, venda_id, data_expiracao, invite_link, status)
                                VALUES (?, ?, ?, ?, ?, ?, 'ativo')
                                ON DUPLICATE KEY UPDATE
                                    status = 'ativo',
                                    data_expiracao = VALUES(data_expiracao),
                                    venda_id = VALUES(venda_id),
                                    invite_link = COALESCE(VALUES(invite_link), invite_link),
                                    aviso_enviado = 0,
                                    em_renovacao = 0
                            ")->execute([$venda['id_telegram'], $id_grupo, $venda['bot_id'], $venda['id'], $data_expiracao, $link]);

                            if ($link) {
                                $msg .= "\n\n🚀 *Acesso Liberado!*\nClique no link abaixo para entrar no grupo exclusivo:\n\n$link\n\n⚠️ Este link é válido apenas para você.";
                                if ($minutos_acesso < 60) {
                                     $msg .= "\n⏳ *Seu acesso expira em {$minutos_acesso} minutos.*";
                                } elseif ($minutos_acesso < 1440) {
                                     $horas = floor($minutos_acesso / 60);
                                     $msg .= "\n⏳ *Seu acesso expira em {$horas} horas.*";
                                } else {
                                     $dias = floor($minutos_acesso / 1440);
                                     $msg .= "\n⏳ *Seu acesso expira em {$dias} dias.*";
                                }
                                $msg .= "\n*(Data exata: " . date('d/m/Y \à\s H:i', strtotime($data_expiracao)) . ")*";
                            } else {
                                $msg .= "\n\n⚠️ Não foi possível gerar o link do grupo automaticamente. O administrador entrará em contato.";
                            }
                        }
                        requisicaoTelegram($token, 'sendMessage', ['chat_id' => $id_chat, 'text' => $msg, 'parse_mode' => 'Markdown']);
                        
                        // Atualização: Verifica se já foi processado para evitar duplicidade no fluxo
                        // Se for uma renovação (tem venda_pai_id ou foi gerado via cron), o fluxo já pode ter sido processado ou não se aplica.
                        // Mas aqui estamos falando do fluxo principal.
                        // Se o webhook receber 2 notificações (ex: Pix recebido e depois Concluido), ele pode entrar aqui 2 vezes.
                        // O status já foi atualizado para 'pago'.
                        // Vamos verificar se já enviamos algo recentemente? Não, o update status protege.
                        // Mas se o update acontecer muito rápido em paralelo?
                        
                        if (!empty($venda['id_operador_fluxo'])) {
                             $stmt_fluxo = $pdo->prepare("SELECT f.dados_fluxograma FROM bots b JOIN fluxos f ON b.id_fluxo_conectado = f.id WHERE b.id = ?");
                             $stmt_fluxo->execute([$venda['bot_id']]);
                             $dados_json = $stmt_fluxo->fetchColumn();
                             if ($dados_json) {
                                 $dados_fluxo = json_decode($dados_json, true);
                                 $proximo_id = obterProximoNo($dados_fluxo['links'], $venda['id_operador_fluxo']);
                                 $proximo_id_pago = null;
                                 foreach ($dados_fluxo['links'] as $link) {
                                     if (($link['fromOperator'] ?? '') === $venda['id_operador_fluxo'] && ($link['fromConnector'] ?? '') === 'output_pago') {
                                         $proximo_id_pago = $link['toOperator'] ?? null;
                                         break;
                                     }
                                 }
                                 if ($proximo_id_pago) {
                                     while ($proximo_id_pago && isset($dados_fluxo['operators'][$proximo_id_pago])) {
                                         $operador = $dados_fluxo['operators'][$proximo_id_pago];
                                         processarEEnviarBloco($token, $id_chat, $operador, $proximo_id_pago);
                                         if (in_array($operador['properties']['type'] ?? '', ['botoes', 'pix'])) break;
                                         $proximo_id_pago = obterProximoNo($dados_fluxo['links'], $proximo_id_pago);
                                     }
                                 }
                             }
                        }
                    } else {
                        requisicaoTelegram($token, 'sendMessage', ['chat_id' => $id_chat, 'text' => '⏳ Pagamento ainda não identificado. Aguarde alguns instantes e tente novamente.']);
                    }
                }
            }
        } else {
            requisicaoTelegram($token, 'sendMessage', ['chat_id' => $id_chat, 'text' => '❌ Pagamento não encontrado.']);
        }
    } catch (Exception $e) {
        requisicaoTelegram($token, 'sendMessage', ['chat_id' => $id_chat, 'text' => 'Erro ao verificar.']);
    }
    exit;
}
// 2. Detectar se foi adicionado via mensagem de serviço (backup para my_chat_member)
if (isset($atualizacao['message']['new_chat_members'])) {
    foreach ($atualizacao['message']['new_chat_members'] as $membro) {
        if (($membro['id'] ?? 0) == ($bot['id_bot_telegram'] ?? 0)) {
             $chat = $atualizacao['message']['chat'];
             $stmt = $pdo->prepare("
                INSERT INTO bot_grupos (bot_id, id_telegram, titulo, tipo) 
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE titulo = VALUES(titulo), tipo = VALUES(tipo)
            ");
            $stmt->execute([
                $bot['id'], 
                (string)$chat['id'], 
                $chat['title'] ?? 'Sem Título', 
                $chat['type'] ?? 'group'
            ]);
        }
    }
}
if (isset($atualizacao['message']['new_chat_title'])) {
    $chat = $atualizacao['message']['chat'];
    $stmt = $pdo->prepare("UPDATE bot_grupos SET titulo = ? WHERE bot_id = ? AND id_telegram = ?");
    $stmt->execute([$atualizacao['message']['new_chat_title'], $bot['id'], (string)$chat['id']]);
}
if (isset($atualizacao['message']['migrate_to_chat_id'])) {
    $chat_antigo = (string)$atualizacao['message']['chat']['id'];
    $chat_novo = (string)$atualizacao['message']['migrate_to_chat_id'];
    $stmt = $pdo->prepare("UPDATE bot_grupos SET id_telegram = ?, tipo = 'supergroup' WHERE bot_id = ? AND id_telegram = ?");
    $stmt->execute([$chat_novo, $bot['id'], $chat_antigo]);
}
if (!$id_chat) {
    echo 'sem chat';
    exit;
}
if ($texto === '/start') {
    try {
        $is_novo_lead = false;
        $stmt_lead = $pdo->prepare("SELECT id FROM leads WHERE id_telegram = ? AND bot_id = ?");
        $stmt_lead->execute([$id_chat, $bot['id']]);
        if (!$stmt_lead->fetch()) {
            $is_novo_lead = true;
            // /start pode chegar como mensagem de texto OU clique num botão (callback_query,
            // ex: botão "Recomeçar" do aviso de renovação) — o campo "from" mora em lugares diferentes.
            $dados_remetente = $atualizacao['message']['from'] ?? $atualizacao['callback_query']['from'] ?? [];
            $nome_usuario = trim(($dados_remetente['first_name'] ?? '') . ' ' . ($dados_remetente['last_name'] ?? ''));
            if ($nome_usuario === '') $nome_usuario = 'Usuário ' . $id_chat;
            $data_criacao = date('Y-m-d H:i:s');
            $stmt_insert_lead = $pdo->prepare("INSERT INTO leads (id_telegram, nome, bot_id, criado_em) VALUES (?, ?, ?, ?)");
            $stmt_insert_lead->execute([$id_chat, $nome_usuario, $bot['id'], $data_criacao]);
            $stmt_insert_ativ = $pdo->prepare("INSERT INTO atividades (id_usuario, tipo, titulo, descricao, icone, criado_em) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt_insert_ativ->execute([$bot['id_usuario'], 'lead', 'Novo Lead', $nome_usuario . ' iniciou conversa', 'user', $data_criacao]);
        }
        // Se veio de um link de rastreamento, incrementa starts (sempre) e leads (só se for novo lead)
        if ($start_param) {
            $campos_rast = 'starts = starts + 1' . ($is_novo_lead ? ', leads = leads + 1' : '');
            $pdo->prepare("UPDATE links_rastreamento SET $campos_rast WHERE bot_id = ? AND identificador = ?")
                ->execute([$bot['id'], $start_param]);
        }
    } catch (Exception $e) {
        // Ignora erro para não quebrar o webhook
    }
}
function obterProximoNo(array $links, string $id_atual): ?string {
    foreach ($links as $link) {
        if (($link['fromOperator'] ?? '') === $id_atual) {
            return $link['toOperator'] ?? null;
        }
    }
    return null;
}
if (!empty($bot['id_fluxo_conectado'])) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM fluxos WHERE id = ?");
        $stmt->execute([$bot['id_fluxo_conectado']]);
        $fluxo = $stmt->fetch();
        if ($fluxo) {
            $dados_fluxo = json_decode($fluxo['dados_fluxograma'] ?? '{}', true);
            $dados_fluxo = is_array($dados_fluxo) ? $dados_fluxo : ['operators' => [], 'links' => []];
            if ($texto === '/start') {
                $proximo_id = buscarProximoDoInicio($dados_fluxo);
                while ($proximo_id && isset($dados_fluxo['operators'][$proximo_id])) {
                    $operador = $dados_fluxo['operators'][$proximo_id];
                    processarEEnviarBloco($token, $id_chat, $operador, $proximo_id);
                    // Se for Botões, para a execução automática e aguarda interação do usuário
                    if (($operador['properties']['type'] ?? '') === 'botoes') {
                        break;
                    }
                    // Se for Pix, para a execução (aguarda pagamento)
                    if (($operador['properties']['type'] ?? '') === 'pix') {
                        break;
                    }
                    if (($operador['properties']['type'] ?? '') === 'delay') {
                         $segundos = (int)($operador['properties']['delay_min'] ?? 0);
                         if ($segundos > 0 && $segundos <= 5) sleep($segundos);
                    }
                    $proximo_id = obterProximoNo($dados_fluxo['links'], $proximo_id);
                }
            } else {
                // Tenta identificar resposta a botões (lógica sem estado)
                $encontrou = false;
                foreach ($dados_fluxo['operators'] as $op_id => $op) {
                    if (($op['properties']['type'] ?? '') === 'botoes') {
                         $botoes = $op['properties']['botoes'] ?? [];
                         if (in_array($texto, $botoes)) {
                             $index = array_search($texto, $botoes);
                             $output_key = 'output_' . $index;
                             $proximo_id = null;
                             foreach ($dados_fluxo['links'] as $link) {
                                 if (($link['fromOperator'] ?? '') === $op_id && ($link['fromConnector'] ?? '') === $output_key) {
                                     $proximo_id = $link['toOperator'] ?? null;
                                     break;
                                 }
                             }
                             if ($proximo_id) {
                                $encontrou = true;
                                while ($proximo_id && isset($dados_fluxo['operators'][$proximo_id])) {
                                    $operador = $dados_fluxo['operators'][$proximo_id];
                                    processarEEnviarBloco($token, $id_chat, $operador, $proximo_id);
                                    if (($operador['properties']['type'] ?? '') === 'botoes') break;
                                    if (($operador['properties']['type'] ?? '') === 'pix') break;
                                    $proximo_id = obterProximoNo($dados_fluxo['links'], $proximo_id);
                                }
                            }
                             break;
                         }
                    }
                }
            }
        }
    } catch (Exception $e) {
        // Log erro silenciosamente
    }
}
echo 'ok';
