<?php
declare(strict_types=1);

if (!defined('DIRETORIO_UPLOADS')) {
    define('DIRETORIO_UPLOADS', dirname(__DIR__) . '/uploads');
}
if (!defined('CNPJ_PIX_RECORRENTE_FIXO')) {
    /** CNPJ fixo (65.915.116/0001-04) para todas as cobranças PIX recorrentes — apenas dígitos */
    define('CNPJ_PIX_RECORRENTE_FIXO', '65915116000104');
}

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
    $real = realpath(dirname(__DIR__) . '/' . $caminho);
    if ($real === false) {
        return null;
    }
    if ($real !== $base_real && strpos($real, $base_real . DIRECTORY_SEPARATOR) !== 0) {
        return null;
    }
    return $real;
}

/**
 * Executor único de blocos do fluxo (mensagem, mídia, botões, grupo, link, upsell/downsell/
 * order_bump, Pix). Usado por webhook.php (fluxo ao vivo), cron/cron_verificar_pix.php e
 * webhook_omegapayments.php (continuação do fluxo após confirmação de pagamento) — antes cada
 * um desses dois últimos tinha uma cópia reduzida própria que só tratava message/image/botoes
 * e ignorava vídeo/áudio/grupo/link/Pix, então o fluxo "PAGO" ficava incompleto quando
 * disparado pelo cron ou pelo webhook do gateway em vez de por uma mensagem do usuário.
 */
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
        // callback_data carrega o id do próprio bloco + o índice do botão (formato
        // "btn::<id_operador>::<indice>") -- resolve direto por id no clique, sem precisar
        // varrer o grafo procurando texto de botão. Antes usava o texto puro como
        // callback_data: se dois blocos "Botões" tinham um botão de mesmo texto (ex: "NÃO
        // QUERO"), o clique sempre ia para o primeiro bloco encontrado na varredura, não
        // necessariamente o bloco certo (causou PIX com valor errado em produção). Sem
        // $id_operador (ex. bloco sintético do modo básico) cai no formato antigo por texto.
        foreach (array_values($botoes) as $indice_botao => $btn_texto) {
            $callback_data = ($id_operador !== '')
                ? ('btn::' . $id_operador . '::' . $indice_botao)
                : (string) $btn_texto;
            $current_row[] = ['text' => $btn_texto, 'callback_data' => $callback_data];
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
    if ($tipo === 'randomizer') {
        // Não envia nada — o sorteio de caminho acontece em proximoNoConsiderandoTipo(),
        // no momento de decidir o próximo nó, não aqui.
        return;
    }
    if (in_array($tipo, ['upsell', 'downsell', 'order_bump'], true)) {
        $mensagem = trim((string) ($propriedades['mensagem'] ?? ''));
        $texto_aceitar = trim((string) ($propriedades['texto_aceitar'] ?? '')) ?: 'Sim, quero! 🔥';
        $texto_recusar = trim((string) ($propriedades['texto_recusar'] ?? '')) ?: 'Não, obrigado';
        if ($mensagem === '') {
            $mensagem = 'Temos uma oferta especial para você.';
        }
        // callback_data carrega o id do próprio bloco + a saída escolhida (formato
        // "saida::<id_operador>::aceito|recusado") -- resolve direto por id no clique, sem
        // precisar varrer o grafo procurando texto de botão.
        $teclado = [
            'inline_keyboard' => [[
                ['text' => $texto_aceitar, 'callback_data' => 'saida::' . $id_operador . '::aceito'],
                ['text' => $texto_recusar, 'callback_data' => 'saida::' . $id_operador . '::recusado'],
            ]]
        ];
        requisicaoTelegram($token, 'sendMessage', [
            'chat_id' => $id_chat,
            'text' => $mensagem,
            'reply_markup' => json_encode($teclado)
        ]);
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
        $nome_produto_recorrente = (string)($propriedades['nome'] ?? 'Produto');
        if ($eh_recorrente) {
            $documento_limpo = CNPJ_PIX_RECORRENTE_FIXO;
            $tipo_documento = 'CNPJ';
        } else {
            $documento_limpo = preg_replace('/\D/', '', $documento_comprador);
            $tipo_documento = strlen($documento_limpo) === 14 ? 'CNPJ' : 'CPF';
        }

        foreach ($gateways_usuario as $gw) {
            $nome_gateway = $gw['gateway_nome'] ?? '';

            // Gateway PF não suporta PIX Recorrente — pula para o próximo
            if ($eh_recorrente && ($gw['tipo_conta'] ?? 'pj') === 'pf') {
                $tentativas[] = "Gateway {$nome_gateway} é conta PF, não suporta PIX Recorrente";
                continue;
            }

            // Chave Pix não é exigida aqui -- alguns gateways (OmegaPayments) definem o
            // recebedor pela própria credencial, não por uma chave configurada à parte.
            $incompleto = empty($gw['client_id']) || empty($gw['client_secret']);
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
            $split_gateway = getGatewaySplit($nome_gateway);
            if ($split_gateway) {
                $split_data = [[
                    'chave' => $split_gateway['chave_pix_split'],
                    'valor' => $split_gateway['taxa_split'],
                    'tipo'  => $split_gateway['tipo_split'] ?? 'percentual',
                ]];
            }

            $chave_pix = $gw['chave_pix'] ?? '';

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

                // $nome_usuario/$documento_limpo (nome do lead + CPF/CNPJ) são passados como
                // argumentos extras — providers que não usam isso simplesmente ignoram (PHP não
                // reclama de argumentos extras em chamadas normais). Necessário pra
                // OmegaPaymentsBanco montar o campo "client" obrigatório na cobrança.
                if ($eh_recorrente) {
                    // POST /gateway/pix/subscription (achado e confirmado em 28/09 -- não é
                    // checkout hospedado, devolve o pixCopiaECola da 1ª cobrança direto,
                    // igual ao avulso; as cobranças seguintes do ciclo a própria OmegaPayments
                    // gera sozinha). id_operador identifica o "produto" de forma estável entre
                    // assinantes diferentes do mesmo bloco Pix do fluxo.
                    $payload = $provedor->montaPayloadAssinatura($valor, $periodicidade_recorrente, 'produto_' . ($id_operador ?: bin2hex(random_bytes(4))), $nome_produto_recorrente, $nome_usuario, $documento_limpo);
                    $resp = $provedor->criarAssinatura($payload);
                } else {
                    $payload = $provedor->montaPayloadCobranca($valor, $chave_pix, $split_data, $expiracao_segundos, $nome_usuario, $documento_limpo);
                    $resp = $provedor->criarCobranca($payload);
                }
                if (!($resp['sucesso'] ?? false)) {
                    $tentativas[] = "{$nome_gateway} " . ($eh_recorrente ? 'criarAssinatura' : 'criarCobranca') . " falhou: " . ($resp['erro'] ?? 'desconhecido');
                    continue;
                }

                // OmegaPayments já devolve o pixCopiaECola direto na criação da cobrança (sem passo extra de QR code).
                $pix_copia_cola  = $resp['dados']['pixCopiaECola'] ?? '';
                $txid          = $resp['dados']['txid'] ?? '';
                $link_pagamento = '';
                if ($eh_recorrente) {
                    $eh_recorrente_oficial = true;
                    $id_assinatura = $resp['dados']['subscriptionId'] ?? null;
                }

                $gateway_selecionado = $gw;
                break;
            } catch (Exception $e) {
                $tentativas[] = "{$nome_gateway} causou exceção: " . $e->getMessage();
                continue;
            }
        }

        if (!$gateway_selecionado || !($resp['sucesso'] ?? false)) {
            // O rastro completo (nome do gateway, "não configurado", erro cru da API do
            // provedor, até exceção de PHP) ia inteiro numa mensagem do bot pro CLIENTE que
            // está tentando comprar -- achado na varredura 15. Quem compra não tem nada a ver
            // com qual gateway o vendedor esqueceu de configurar; e o vendedor, por outro lado,
            // nunca ficava sabendo que o PIX dele estava quebrado, porque isso só existia aqui
            // dentro de uma mensagem de erro que o próprio cliente recebia.
            $detalhe_falha = "Erro ao gerar Pix. Tentativas:\n" . implode("\n", $tentativas);
            file_put_contents(dirname(__DIR__) . '/logs/vendas_debug.log', "[" . date('Y-m-d H:i:s') . "] FALHA AO GERAR PIX (dono id_usuario={$id_usuario_dono}): $detalhe_falha\n", FILE_APPEND);
            if ($id_usuario_dono) {
                // require aqui (não só lá embaixo, no fluxo de sucesso) porque este ramo de
                // falha roda ANTES do require_once original -- sem isto, registrarAtividade()
                // seria uma função indefinida nesse ponto e o webhook cairia com fatal error.
                require_once __DIR__ . '/log.php';
                registrarAtividade((int) $id_usuario_dono, 'sistema', 'Falha ao gerar Pix', 'Um cliente tentou pagar e a geração do Pix falhou em todos os gateways configurados. Veja os logs para detalhes.');
            }
            requisicaoTelegram($token, 'sendMessage', ['chat_id' => $id_chat, 'text' => "Não foi possível gerar seu Pix agora. Tente novamente em instantes."]);
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
                file_put_contents(dirname(__DIR__) . '/logs/vendas_debug.log', "[" . date('Y-m-d H:i:s') . "] TENTANDO INSERIR VENDA - TXID: $txid | OPERADOR: $id_operador | BOT_ID: $id_bot_insert\n", FILE_APPEND);
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
                     file_put_contents(dirname(__DIR__) . '/logs/vendas_debug.log', "[" . date('Y-m-d H:i:s') . "] ERRO PDO: " . print_r($stmt_venda->errorInfo(), true) . "\n", FILE_APPEND);
                } else {
                     file_put_contents(dirname(__DIR__) . '/logs/vendas_debug.log', "[" . date('Y-m-d H:i:s') . "] VENDA INSERIDA COM SUCESSO!\n", FILE_APPEND);
                     $id_venda_criada = (int) $pdo->lastInsertId();
                     if ($id_usuario_dono_insert) {
                         require_once __DIR__ . '/log.php';
                         $valor_formatado = number_format($valor_insert, 2, ',', '.');
                         registrarAtividade($id_usuario_dono_insert, 'pix_gerado', 'Pix Gerado', "Novo PIX de R$ {$valor_formatado} foi gerado.");

                         require_once __DIR__ . '/traqueamento.php';
                         // Tenta buscar dados do lead se houver (futuro: email/telefone)
                         $dados_evento = [
                             'valor' => $valor_insert,
                             'nome_produto' => $nome_produto,
                             'transacao_id' => $txid,
                             'event_id' => $txid
                         ];
                         $user_data = montarUserDataTraqueamento($pdo, $id_chat, $bot['id']);
                         enviarEventosTraqueamento($id_usuario_dono_insert, 'pix_gerado', $dados_evento, $user_data);
                         dispararWebhooks($id_usuario_dono_insert, 'payment_created', [
                             'bot_id' => (int) $id_bot_insert,
                             'id_telegram' => (string) $id_chat,
                             'venda_id' => $id_venda_criada,
                             'pix_code' => $pix_copia_cola ?? '',
                             'plan_name' => $nome_produto ?? '',
                             'gateway' => $nome_gateway ?? '',
                         ]);
                     }
                }
            } catch (Exception $e) {
                file_put_contents(dirname(__DIR__) . '/logs/vendas_debug.log', "[" . date('Y-m-d H:i:s') . "] EXCECAO: " . $e->getMessage() . "\n", FILE_APPEND);
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
                     file_put_contents(dirname(__DIR__) . "/logs/vendas_debug.log", "[" . date("Y-m-d H:i:s") . "] GERANDO QR CODE VIA API: $url_qr_code\n", FILE_APPEND);
                 } elseif (!empty($link_pagamento) && strpos($link_pagamento, '/loc//') === false) {
                     $url_qr_code = $link_pagamento;
                     file_put_contents(dirname(__DIR__) . "/logs/vendas_debug.log", "[" . date("Y-m-d H:i:s") . "] USANDO LINK DE PAGAMENTO: $url_qr_code\n", FILE_APPEND);
                 }

                 if ($url_qr_code) {
                     $res = requisicaoTelegram($token, 'sendPhoto', [
                        'chat_id' => $id_chat,
                        'photo' => $url_qr_code,
                        'caption' => 'Escaneie o QR Code acima para pagar.'
                     ]);
                     file_put_contents(dirname(__DIR__) . "/logs/vendas_debug.log", "[" . date("Y-m-d H:i:s") . "] SEND PHOTO RES: " . json_encode($res) . "\n", FILE_APPEND);
                 } else {
                     file_put_contents(dirname(__DIR__) . "/logs/vendas_debug.log", "[" . date("Y-m-d H:i:s") . "] SEM URL QR CODE\n", FILE_APPEND);
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
