<?php
declare(strict_types=1);
// Define o fuso horário para São Paulo/Brasil para garantir datas corretas
date_default_timezone_set('America/Sao_Paulo');
require_once 'conexao.php';
require_once __DIR__ . '/funcoes/efi_banco.php';
require_once __DIR__ . '/funcoes/gateways.php';
const DIRETORIO_UPLOADS = __DIR__ . '/uploads';
/** CNPJ fixo (65.915.116/0001-04) para todas as cobranças PIX recorrentes — apenas dígitos */
const CNPJ_PIX_RECORRENTE_FIXO = '65915116000104';
function requisicao_telegram(string $token, string $metodo, array $parametros = [], array $arquivos = []): array
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
    $resposta = curl_exec($ch);
    curl_close($ch);
    $decodificado = json_decode($resposta ?: '', true);
    return is_array($decodificado) ? $decodificado : ['ok' => false, 'description' => 'resposta inválida'];
}
function buscar_proximo_do_inicio(array $dados): ?string
{
    $operadores = $dados['operators'] ?? [];
    $conexoes = $dados['links'] ?? [];
    $idInicio = null;
    foreach ($operadores as $id => $op) {
        if (($op['properties']['type'] ?? '') === 'start') {
            $idInicio = $id;
            break;
        }
    }
    if (!$idInicio) {
        return null;
    }
    foreach ($conexoes as $conexao) {
        if (($conexao['fromOperator'] ?? '') === $idInicio) {
            return $conexao['toOperator'] ?? null;
        }
    }
    return null;
}
function caminho_estado_pix_recorrente(): string
{
    return __DIR__ . '/storage/pix_recorrente_estado.json';
}
function carregar_estado_pix_recorrente(): array
{
    $arquivo = caminho_estado_pix_recorrente();
    if (!file_exists($arquivo)) {
        return [];
    }
    $conteudo = file_get_contents($arquivo);
    $dados = json_decode($conteudo ?: '{}', true);
    return is_array($dados) ? $dados : [];
}
function salvar_estado_pix_recorrente(array $estado): void
{
    $arquivo = caminho_estado_pix_recorrente();
    $diretorio = dirname($arquivo);
    if (!is_dir($diretorio)) {
        @mkdir($diretorio, 0775, true);
    }
    file_put_contents($arquivo, json_encode($estado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}
function chave_estado_pix_recorrente(int $botId, string $idChat): string
{
    return $botId . ':' . $idChat;
}
function obter_estado_pix_recorrente(int $botId, string $idChat): ?array
{
    $estado = carregar_estado_pix_recorrente();
    $chave = chave_estado_pix_recorrente($botId, $idChat);
    return isset($estado[$chave]) && is_array($estado[$chave]) ? $estado[$chave] : null;
}
function limpar_estado_pix_recorrente(int $botId, string $idChat): void
{
    $estado = carregar_estado_pix_recorrente();
    $chave = chave_estado_pix_recorrente($botId, $idChat);
    if (!isset($estado[$chave])) {
        return;
    }
    unset($estado[$chave]);
    salvar_estado_pix_recorrente($estado);
}
function processar_e_enviar_bloco(string $token, $idChat, array $operador, string $idOperador = '', string $documentoComprador = ''): void
{
    $propriedades = $operador['properties'] ?? [];
    $tipo = $propriedades['type'] ?? '';
    if ($tipo === 'message') {
        $texto = trim((string) ($propriedades['conteudo'] ?? $propriedades['body'] ?? ''));
        if ($texto !== '') {
            requisicao_telegram($token, 'sendMessage', ['chat_id' => $idChat, 'text' => $texto]);
        }
        return;
    }
    if ($tipo === 'image') {
        $caminho = (string) ($propriedades['image_path'] ?? '');
        if ($caminho === '') {
            return;
        }
        // Corrige caminho se for relativo
        if (strpos($caminho, 'uploads/') === 0) {
            $caminhoAbsoluto = __DIR__ . '/' . str_replace(['..', '\\'], ['', '/'], $caminho);
        } else {
            $caminhoAbsoluto = $caminho;
        }
        // Tenta resolver caminho real
        $real = realpath($caminhoAbsoluto);
        if ($real) {
            $caminhoAbsoluto = $real;
        }
        $parametros = ['chat_id' => $idChat];
        $legenda = trim((string) ($propriedades['caption'] ?? ''));
        if ($legenda !== '') {
            $parametros['caption'] = $legenda;
        }
        $modo = $propriedades['mode'] ?? 'foto';
        if ($modo === 'documento') {
            requisicao_telegram($token, 'sendDocument', $parametros, ['document' => $caminhoAbsoluto]);
        } else {
            if (!empty($propriedades['spoiler'])) {
                $parametros['has_spoiler'] = true;
            }
            requisicao_telegram($token, 'sendPhoto', $parametros, ['photo' => $caminhoAbsoluto]);
        }
        return;
    }
    if ($tipo === 'video') {
        $caminho = (string) ($propriedades['video_path'] ?? '');
        if ($caminho === '') {
            return;
        }
        if (strpos($caminho, 'uploads/') === 0) {
            $caminhoAbsoluto = __DIR__ . '/' . str_replace(['..', '\\'], ['', '/'], $caminho);
        } else {
            $caminhoAbsoluto = $caminho;
        }
        $real = realpath($caminhoAbsoluto);
        if ($real) {
            $caminhoAbsoluto = $real;
        }
        $parametros = ['chat_id' => $idChat];
        $legenda = trim((string) ($propriedades['caption'] ?? ''));
        if ($legenda !== '') {
            $parametros['caption'] = $legenda;
        }
        if (!empty($propriedades['spoiler'])) {
            $parametros['has_spoiler'] = true;
        }
        requisicao_telegram($token, 'sendVideo', $parametros, ['video' => $caminhoAbsoluto]);
        return;
    }
    if ($tipo === 'audio') {
        $caminho = (string) ($propriedades['audio_path'] ?? '');
        if ($caminho === '') {
            return;
        }
        if (strpos($caminho, 'uploads/') === 0) {
            $caminhoAbsoluto = __DIR__ . '/' . str_replace(['..', '\\'], ['', '/'], $caminho);
        } else {
            $caminhoAbsoluto = $caminho;
        }
        $real = realpath($caminhoAbsoluto);
        if ($real) {
            $caminhoAbsoluto = $real;
        }
        $parametros = ['chat_id' => $idChat];
        $legenda = trim((string) ($propriedades['caption'] ?? ''));
        if ($legenda !== '') {
            $parametros['caption'] = $legenda;
        }
        // Tenta enviar como Note de Voz (sendVoice) para parecer gravado na hora
        // Se falhar (por formato não suportado), poderíamos tentar fallback para sendAudio,
        // mas o Telegram costuma aceitar mp3/m4a/ogg como voice.
        $resposta = requisicao_telegram($token, 'sendVoice', $parametros, ['voice' => $caminhoAbsoluto]);
        // Se falhar o envio como voice (ex: formato inválido para voice), tenta como áudio normal
        if (!($resposta['ok'] ?? false)) {
             requisicao_telegram($token, 'sendAudio', $parametros, ['audio' => $caminhoAbsoluto]);
        }
        return;
    }
    if ($tipo === 'grupo') {
        $idGrupo = $propriedades['id_grupo'] ?? '';
        $textoBotao = $propriedades['texto_botao'] ?? 'Entrar no Grupo';
        if (empty($idGrupo)) {
            requisicao_telegram($token, 'sendMessage', ['chat_id' => $idChat, 'text' => 'Erro: Grupo não configurado.']);
            return;
        }
        // Tenta gerar link de convite único
        $invite = requisicao_telegram($token, 'createChatInviteLink', [
            'chat_id' => $idGrupo,
            'member_limit' => 1,
            'expire_date' => time() + (15 * 60),
            'name' => 'Acesso via Bot' // Nome opcional para controle
        ]);
        $link = '';
        if (($invite['ok'] ?? false) && isset($invite['result']['invite_link'])) {
            $link = $invite['result']['invite_link'];
        } else {
            // Se falhar (ex: bot não é admin), tenta pegar link permanente se disponível ou avisa erro
            // Fallback: Tenta exportar o link existente
            $export = requisicao_telegram($token, 'exportChatInviteLink', ['chat_id' => $idGrupo]);
            if (($export['ok'] ?? false)) {
                $link = $export['result'];
            } else {
                requisicao_telegram($token, 'sendMessage', ['chat_id' => $idChat, 'text' => 'Não foi possível gerar o link do grupo. Verifique se o bot é administrador do grupo.']);
                return;
            }
        }
        $teclado = [
            'inline_keyboard' => [[
                ['text' => $textoBotao, 'url' => $link]
            ]]
        ];
        requisicao_telegram($token, 'sendMessage', [
            'chat_id' => $idChat,
            'text' => "Clique abaixo para entrar:",
            'reply_markup' => json_encode($teclado)
        ]);
        return;
    }
    if ($tipo === 'botoes') {
        $texto = trim((string) ($propriedades['texto'] ?? ''));
        $botoes = $propriedades['botoes'] ?? [];
        $keyboard = [];
        $currentRow = [];
        foreach ($botoes as $btnTexto) {
            $currentRow[] = ['text' => $btnTexto, 'callback_data' => $btnTexto];
            if (count($currentRow) >= 2) {
                $keyboard[] = $currentRow;
                $currentRow = [];
            }
        }
        if (!empty($currentRow)) {
            $keyboard[] = $currentRow;
        }
        $parametros = [
            'chat_id' => $idChat,
            'text' => $texto ?: 'Escolha:',
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ])
        ];
        requisicao_telegram($token, 'sendMessage', $parametros);
        return;
    }
    if ($tipo === 'pix') {
        // Carrega credenciais do usuário dono do bot
        // Como a função requer ID do usuário, precisamos buscar o bot no banco antes de chamar essa função, ou passar o ID do usuário para processar_e_enviar_bloco.
        // A função processar_e_enviar_bloco recebe $token, mas não o ID do usuário dono do bot.
        // Vou precisar ajustar a chamada ou buscar o bot aqui dentro (ineficiente).
        // Melhor passar o $bot inteiro para a função.
        // Ajuste rápido: buscar o bot pelo token (já tenho o token)
        global $pdo;
        $stmtBot = $pdo->prepare("SELECT id_usuario FROM bots WHERE token = ?");
        $stmtBot->execute([$token]);
        $idUsuarioDono = $stmtBot->fetchColumn();
        if (!$idUsuarioDono) {
            requisicao_telegram($token, 'sendMessage', ['chat_id' => $idChat, 'text' => 'Erro interno: Bot não identificado.']);
            return;
        }
        $gatewaysUsuario = getUserGateways((int)$idUsuarioDono, true);
        // Busca o ID real do bot (Movido para o início para uso na assinatura)
        $stmtBotReal = $pdo->prepare("SELECT id FROM bots WHERE token = ?");
        $stmtBotReal->execute([$token]);
        $idBotReal = $stmtBotReal->fetchColumn();
        $idBotInsert = $idBotReal ? (int)$idBotReal : null;
        // Busca nome do usuário para cadastro (para usar em metadados de cobrança se precisar)
        $stmtNome = $pdo->prepare("SELECT nome FROM leads WHERE id_telegram = ? AND bot_id = ?");
        $stmtNome->execute([$idChat, $idBotInsert]);
        $nomeUsuario = $stmtNome->fetchColumn() ?: "Cliente Telegram";
        $nomeUsuario = substr($nomeUsuario, 0, 50);

        if (empty($gatewaysUsuario)) {
            requisicao_telegram($token, 'sendMessage', ['chat_id' => $idChat, 'text' => 'Erro: Nenhum gateway configurado para este usuário.']);
            return;
        }

        $tentativas = [];
        $gatewaySelecionado = null;
        $resp = ['sucesso' => false];
        $provedor = null;

        $ehRecorrente = ($propriedades['tipo_cobranca'] ?? 'unica') === 'recorrente';
        $periodicidadeRecorrente = strtolower((string)($propriedades['periodicidade'] ?? 'mensal'));
        if ($ehRecorrente) {
            $documentoLimpo = CNPJ_PIX_RECORRENTE_FIXO;
            $tipoDocumento = 'CNPJ';
        } else {
            $documentoLimpo = preg_replace('/\D/', '', $documentoComprador);
            $tipoDocumento = strlen($documentoLimpo) === 14 ? 'CNPJ' : 'CPF';
        }

        if ($ehRecorrente && $periodicidadeRecorrente === 'semanal') {
            requisicao_telegram($token, 'sendMessage', [
                'chat_id' => $idChat,
                'text' => 'Periodicidade semanal nao esta disponivel no momento. Use mensal, trimestral, semestral ou anual.'
            ]);
            return;
        }

        foreach ($gatewaysUsuario as $gw) {
            $nomeGateway = $gw['gateway_nome'] ?? '';

            // Gateway PF não suporta PIX Recorrente — pula para o próximo
            if ($ehRecorrente && ($gw['tipo_conta'] ?? 'pj') === 'pf') {
                $tentativas[] = "Gateway {$nomeGateway} é conta PF, não suporta PIX Recorrente";
                continue;
            }

            $incompleto = empty($gw['client_id']) ||
                ($nomeGateway !== 'pushinpay' && (empty($gw['client_secret']) || empty($gw['chave_pix'])));
            if ($incompleto) {
                $tentativas[] = "Gateway {$nomeGateway} não configurado completamente";
                continue;
            }

            $provedor = resolveGatewayProvider($nomeGateway, $gw);
            if (!$provedor) {
                $tentativas[] = "Provedor $nomeGateway não suportado";
                continue;
            }

            $splitData = null;
            $userSplits = getUserSplits((int)$idUsuarioDono, $nomeGateway);
            if (!empty($userSplits)) {
                $splitData = array_map(fn($s) => [
                    'chave' => $s['chave_pix_split'],
                    'valor' => $s['taxa_split'],
                    'tipo'  => $s['tipo_split'] ?? 'percentual'
                ], $userSplits);
            }

            $chavePix = $gw['chave_pix'] ?? '';
            if ($nomeGateway !== 'pushinpay' && empty($chavePix)) {
                $tentativas[] = "Gateway {$nomeGateway} sem chave Pix de recebedor";
                continue;
            }

            $valor = (float)($propriedades['valor'] ?? 0);
            if ($valor <= 0) {
                requisicao_telegram($token, 'sendMessage', ['chat_id' => $idChat, 'text' => 'Erro: Valor inválido para o pagamento.']);
                return;
            }

            $ehRecorrenteOficial = false;
            $idAssinatura = null;

            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $pushinpayWebhookUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
                . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/') . '/webhook_pushinpay.php';

            try {
                $tempoExpiracao = (int)($propriedades['expiracao_minutos'] ?? $propriedades['tempo_nao_pago'] ?? 15);
                $expiracaoSegundos = $tempoExpiracao * 60;

                if ($ehRecorrente && $nomeGateway !== 'pushinpay') {
                    // ── EFI Bank: PIX Automático nativo (/v2/locrec → /v2/rec → /v2/loc/:id/qrcode) ──
                    $periodicidade = $propriedades['periodicidade'] ?? 'mensal';

                    // Passo 1: cria location do tipo rec
                    $respLoc = $provedor->criarLocationRecorrencia();
                    if (!($respLoc['sucesso'] ?? false)) {
                        $tentativas[] = "{$nomeGateway} criarLocationRecorrencia falhou: " . ($respLoc['erro'] ?? 'desconhecido');
                        continue;
                    }
                    $idLoc = (int)($respLoc['dados']['loc']['id'] ?? $respLoc['dados']['id'] ?? 0);
                    if (!$idLoc) {
                        $tentativas[] = "{$nomeGateway} loc id ausente na resposta";
                        continue;
                    }

                    // Passo 2: cria a recorrência com payload correto
                    $payloadRec = $provedor->montaPayloadRecorrencia(
                        $valor,
                        $idLoc,
                        $periodicidade,
                        $nomeUsuario,
                        $documentoLimpo,
                        $propriedades['nome'] ?? 'Assinatura'
                    );
                    $respRec = $provedor->criarRecorrencia($payloadRec);
                    if (!($respRec['sucesso'] ?? false)) {
                        $tentativas[] = "{$nomeGateway} criarRecorrencia falhou: " . ($respRec['erro'] ?? 'desconhecido');
                        continue;
                    }
                    $idAssinatura = $respRec['dados']['rec']['idRec'] ?? $respRec['dados']['idRec'] ?? null;
                    if (!$idAssinatura) {
                        $tentativas[] = "{$nomeGateway} idRec ausente na resposta da recorrência";
                        continue;
                    }

                    // Passo 3: busca o QR code da location (é aqui que fica o pixCopiaECola)
                    $respQr = $provedor->obterQrCodeLoc($idLoc);
                    if (!($respQr['sucesso'] ?? false)) {
                        $tentativas[] = "{$nomeGateway} obterQrCodeLoc falhou: " . ($respQr['erro'] ?? 'desconhecido');
                        continue;
                    }

                    $ehRecorrenteOficial = true;
                    $resp = ['sucesso' => true, 'dados' => $respQr['dados']];
                    $pixCopiaCola  = $respQr['dados']['pixCopiaECola'] ?? '';
                    $txid          = $respQr['dados']['txid'] ?? '';
                    $linkPagamento = $respQr['dados']['imagemQrcode'] ?? '';

                } elseif ($ehRecorrente && $nomeGateway === 'pushinpay') {
                    // ── PushinPay: PIX Recorrente nativo (/pix/cashIn/subscription) ──
                    $periodicidade = $propriedades['periodicidade'] ?? 'mensal';
                    $freqMap = [
                        'mensal'     => 'MONTHLY',
                        'trimestral' => 'MONTHLY', // Não há frequência trimestral nativa; mantém mensal por compatibilidade.
                        'semestral'  => 'SEMIANNUALLY',
                        'anual'      => 'ANNUALLY',
                    ];
                    $frequenciaPush = $freqMap[$periodicidade] ?? 'MONTHLY';
                    $nomeProduto = $propriedades['nome'] ?? 'Assinatura';

                    // Monta dados do cliente para PushinPay
                    $customerData = [
                        'name' => $nomeUsuario,
                        'document_type' => $tipoDocumento,
                        'document_number' => $documentoLimpo,
                    ];

                    $payloadAssinatura = $provedor->montaPayloadAssinatura(
                        $valor,
                        $frequenciaPush,
                        $nomeProduto,
                        $splitData,
                        $pushinpayWebhookUrl,
                        $customerData
                    );
                    $respAssinatura = $provedor->criarAssinatura($payloadAssinatura);
                    if (!($respAssinatura['sucesso'] ?? false)) {
                        $tentativas[] = "pushinpay criarAssinatura falhou: " . ($respAssinatura['erro'] ?? 'desconhecido');
                        continue;
                    }

                    $ehRecorrenteOficial = true;
                    $idAssinatura  = (string)($respAssinatura['dados']['subscription_id'] ?? '');
                    $pixCopiaCola  = $respAssinatura['dados']['qr_code'] ?? '';
                    $txid          = (string)($respAssinatura['dados']['id'] ?? '');
                    $linkPagamento = $respAssinatura['dados']['qr_code_base64'] ?? '';
                    $resp = $respAssinatura;

                } else {
                    // ── PIX único — EFI ou PushinPay ──
                    if ($nomeGateway === 'pushinpay') {
                        $payload = $provedor->montaPayloadCobranca($valor, $chavePix, $splitData, $expiracaoSegundos, $pushinpayWebhookUrl);
                    } else {
                        $payload = $provedor->montaPayloadCobranca($valor, $chavePix, $splitData, $expiracaoSegundos);
                    }

                    $resp = $provedor->criarCobranca($payload);
                    if (!($resp['sucesso'] ?? false)) {
                        $tentativas[] = "{$nomeGateway} criarCobranca falhou: " . ($resp['erro'] ?? 'desconhecido');
                        continue;
                    }

                    if ($nomeGateway === 'pushinpay') {
                        $pixCopiaCola  = $resp['dados']['qr_code'] ?? $resp['dados']['pix_copy_paste'] ?? $resp['dados']['copy_paste'] ?? '';
                        $txid          = (string)($resp['dados']['id'] ?? $resp['dados']['uuid'] ?? '');
                        $linkPagamento = $resp['dados']['qr_code_base64'] ?? $resp['dados']['qr_code_image'] ?? '';
                    } else {
                        $pixCopiaCola  = $resp['dados']['pixCopiaECola'] ?? '';
                        $txid          = $resp['dados']['txid'] ?? '';
                        $linkLoc       = $resp['dados']['loc']['id'] ?? null;
                        $linkPagamento = $linkLoc ? "https://pix.sejaefi.com.br/v2/loc/{$linkLoc}/qrcode" : '';
                    }
                }

                $gatewaySelecionado = $gw;
                break;
            } catch (Exception $e) {
                $tentativas[] = "{$nomeGateway} causou exceção: " . $e->getMessage();
                continue;
            }
        }

        if (!$gatewaySelecionado || !($resp['sucesso'] ?? false)) {
            $erroMsg = "Erro ao gerar Pix. Tentativas:\n" . implode("\n", $tentativas);
            requisicao_telegram($token, 'sendMessage', ['chat_id' => $idChat, 'text' => $erroMsg]);
            return;
        }

        $valor = (float)($propriedades['valor'] ?? 0);
        $nomeProduto = $propriedades['nome'] ?? 'Produto';
        $ehRecorrente = ($propriedades['tipo_cobranca'] ?? 'unica') === 'recorrente';
        // O restante do fluxo pode usar $resp, $pixCopiaCola, $txid, $linkPagamento calculados acima.

        if ($resp['sucesso']) {
            $msg = "✅ *Pedido Criado com Sucesso!*\n\n";
            $msg .= "🛒 *Produto:* $nomeProduto\n";
            $msg .= "💲 *Valor:* R$ " . number_format($valor, 2, ',', '.') . "\n\n";
            $msg .= "⏳ *Aguardando pagamento...*\n";
            $msg .= "Seu acesso será liberado automaticamente em até 1 minuto após a confirmação do pagamento.\n\n";
            $msg .= "👇 *Toque no código abaixo para copiar e pague no app do seu banco:*";
            // Teclado para confirmar pagamento
            $tecladoPix = null;
            if (!empty($propriedades['mostrar_confirmar'])) {
                $tecladoPix = [
                    'inline_keyboard' => [
                        [
                            ['text' => 'Já fiz o pagamento ✅', 'callback_data' => 'verificar_pagamento_' . $txid]
                        ]
                    ]
                ];
            }
            // Salvar venda no banco
            try {
                $diasAcesso = isset($propriedades['dias_acesso']) ? (int)$propriedades['dias_acesso'] : 30; // Valor bruto
                $unidadeAcesso = $propriedades['unidade_acesso'] ?? 'dias';
                // Converte tudo para minutos para o banco
                $tempoMinutos = 0;
                if ($ehRecorrente) {
                    switch($propriedades['periodicidade'] ?? 'mensal') {
                        case 'semanal': $tempoMinutos = 7 * 1440; break;
                        case 'trimestral': $tempoMinutos = 90 * 1440; break;
                        case 'semestral': $tempoMinutos = 180 * 1440; break;
                        case 'anual': $tempoMinutos = 365 * 1440; break;
                        default: $tempoMinutos = 30 * 1440; // mensal
                    }
                    // Mantém compatibilidade com coluna dias_acesso para recorrência
                    $diasAcesso = (int)($tempoMinutos / 1440);
                } else {
                    // Pagamento Único com tempo customizado
                    switch($unidadeAcesso) {
                        case 'minutos':
                            $tempoMinutos = $diasAcesso;
                            $diasAcesso = 0; // Marca 0 dias pois é menos que 1 dia (ou não exato)
                            break;
                        case 'horas':
                            $tempoMinutos = $diasAcesso * 60;
                            $diasAcesso = 0; 
                            break;
                        case 'dias':
                        default:
                            $tempoMinutos = $diasAcesso * 1440;
                            break;
                    }
                }
                $idGrupoAcesso = $propriedades['id_grupo'] ?? null;
                if (empty($idGrupoAcesso) || $idGrupoAcesso === '0' || $idGrupoAcesso === '') {
                     $idGrupoAcesso = null;
                }
                // $tempoExpiracao já foi calculado acima para o payload do Pix
                // Força a conversão do idOperador para garantir que não seja vazio/nulo se estiver dentro do fluxo
                if (empty($idOperador)) $idOperador = null;
                // Força os tipos de dados para evitar erro silencioso de banco
                $diasAcessoInsert = (int) $diasAcesso;
                $tempoMinutosInsert = (int) $tempoMinutos;
                $tempoExpiracaoInsert = (int) $tempoExpiracao;
                $idUsuarioDonoInsert = (int) $idUsuarioDono;
                $valorInsert = (float) $valor;
                // A chave estrangeira vendas_ibfk_1 falha se passarmos um idUsuarioDono em bot_id.
                // Na tabela vendas, a coluna 'bot_id' deve receber o ID do bot, e não o ID do usuário dono do bot.
                // Mas não temos o ID do bot na função processar_e_enviar_bloco.
                // Vamos buscar o ID real do bot usando o token
                $stmtBotReal = $pdo->prepare("SELECT id FROM bots WHERE token = ?");
                $stmtBotReal->execute([$token]);
                $idBotReal = $stmtBotReal->fetchColumn();
                $idBotInsert = $idBotReal ? (int)$idBotReal : null;
                // Log direto no arquivo local para garantir que estamos vendo
                file_put_contents(__DIR__ . '/logs/vendas_debug.log', "[" . date('Y-m-d H:i:s') . "] TENTANDO INSERIR VENDA - TXID: $txid | OPERADOR: $idOperador | BOT_ID: $idBotInsert\n", FILE_APPEND);
                // Define data de criação explicitamente com o fuso horário correto
                $dataCriacao = date('Y-m-d H:i:s');
                $stmtVenda = $pdo->prepare("INSERT INTO vendas (id_telegram, bot_id, valor, status, transacao_id, id_grupo_telegram, dias_acesso, tempo_acesso_minutos, id_operador_fluxo, id_gateway, tempo_expiracao_minutos, criado_em, tipo_cobranca, id_assinatura) VALUES (?, ?, ?, 'gerado', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $executou = $stmtVenda->execute([
                    $idChat,
                    $idBotInsert,
                    $valorInsert,
                    $txid,
                    $idGrupoAcesso,
                    $diasAcessoInsert,
                    $tempoMinutosInsert,
                    $idOperador,
                    $gatewaySelecionado['gateway_id'] ?? $gatewaySelecionado['id_gateway'] ?? null,
                    $tempoExpiracaoInsert,
                    $dataCriacao,
                    $ehRecorrenteOficial ? 'assinatura' : 'unica',
                    $idAssinatura
                ]);
                if (!$executou) {
                     file_put_contents(__DIR__ . '/logs/vendas_debug.log', "[" . date('Y-m-d H:i:s') . "] ERRO PDO: " . print_r($stmtVenda->errorInfo(), true) . "\n", FILE_APPEND);
                } else {
                     file_put_contents(__DIR__ . '/logs/vendas_debug.log', "[" . date('Y-m-d H:i:s') . "] VENDA INSERIDA COM SUCESSO!\n", FILE_APPEND);
                     // Registra no Log
                     if ($idUsuarioDonoInsert) {
                         require_once __DIR__ . '/funcoes/log.php';
                         $valorFormatado = number_format($valorInsert, 2, ',', '.');
                         registrarAtividade($idUsuarioDonoInsert, 'pix_gerado', 'Pix Gerado', "Novo PIX de R$ {$valorFormatado} foi gerado.");
                         
                         // Traqueamento de Eventos (Pixel/API)
                         require_once __DIR__ . '/funcoes/traqueamento.php';
                         // Tenta buscar dados do lead se houver (futuro: email/telefone)
                         $dadosEvento = [
                             'valor' => $valorInsert,
                             'nome_produto' => $nomeProduto,
                             'transacao_id' => $txid,
                             'event_id' => $txid
                         ];
                         $userData = [
                             'id_telegram' => $idChat,
                             // 'email' => ..., 'telefone' => ... (se tiver capturado antes)
                         ];
                         enviarEventosTraqueamento($idUsuarioDonoInsert, 'pix_gerado', $dadosEvento, $userData);
                     }
                }
            } catch (Exception $e) {
                file_put_contents(__DIR__ . '/logs/vendas_debug.log', "[" . date('Y-m-d H:i:s') . "] EXCECAO: " . $e->getMessage() . "\n", FILE_APPEND);
            }
            requisicao_telegram($token, 'sendMessage', [
                'chat_id' => $idChat, 
                'text' => $msg, 
                'parse_mode' => 'Markdown'
            ]);
                        // Lógica melhorada para QR Code - COM LOGS
            if (!empty($propriedades['mostrar_qrcode'])) {
                 $urlQrCode = '';
                 // Prioriza gerar o QR Code a partir do Copia e Cola para garantir compatibilidade
                 if (!empty($pixCopiaCola)) {
                     $urlQrCode = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($pixCopiaCola);
                     file_put_contents(__DIR__ . "/logs/vendas_debug.log", "[" . date("Y-m-d H:i:s") . "] GERANDO QR CODE VIA API: $urlQrCode\n", FILE_APPEND);
                 } elseif (!empty($linkPagamento) && strpos($linkPagamento, '/loc//') === false) {
                     $urlQrCode = $linkPagamento;
                     file_put_contents(__DIR__ . "/logs/vendas_debug.log", "[" . date("Y-m-d H:i:s") . "] USANDO LINK EFI: $urlQrCode\n", FILE_APPEND);
                 }
                 
                 if ($urlQrCode) {
                     $res = requisicao_telegram($token, 'sendPhoto', [
                        'chat_id' => $idChat, 
                        'photo' => $urlQrCode,
                        'caption' => 'Escaneie o QR Code acima para pagar.'
                     ]);
                     file_put_contents(__DIR__ . "/logs/vendas_debug.log", "[" . date("Y-m-d H:i:s") . "] SEND PHOTO RES: " . json_encode($res) . "\n", FILE_APPEND);
                 } else {
                     file_put_contents(__DIR__ . "/logs/vendas_debug.log", "[" . date("Y-m-d H:i:s") . "] SEM URL QR CODE\n", FILE_APPEND);
                 }
            }
            if ($pixCopiaCola) {
                $params = [
                    'chat_id' => $idChat, 
                    'text' => "<code>$pixCopiaCola</code>",
                    'parse_mode' => 'HTML'
                ];
                if ($tecladoPix) {
                    $params['reply_markup'] = json_encode($tecladoPix);
                }
                requisicao_telegram($token, 'sendMessage', $params);
                // Se quiser mandar QR Code (imagem), precisaria gerar a imagem a partir do Copia e Cola ou usar a URL da imagem se a API retornasse (a v2/cob retorna imagem em base64 ou link em loc?)
                // A API retorna "loc" -> "location". A imagem deve ser gerada via qrcode generator.
                // A API da Efí tem endpoint para gerar QR Code base64 (`/v2/loc/:id/qrcode`).
                if (isset($resp['dados']['loc']['id'])) {
                     $locId = $resp['dados']['loc']['id'];
                     if (isset($provedor) && is_object($provedor) && method_exists($provedor, 'consultarCobranca')) {
                         $respQr = $provedor->consultarCobranca($txid);
                     }
                     // Simplificação: apenas copia e cola.
                }
            }
        } else {
            requisicao_telegram($token, 'sendMessage', ['chat_id' => $idChat, 'text' => 'Erro ao gerar Pix: ' . ($resp['erro'] ?? 'Desconhecido')]);
        }
        return;
    }
}
// Lógica principal
http_response_code(200);
$token = $_GET['token'] ?? '';
if ($token === '') {
    echo 'token ausente';
    exit;
}
// Busca o bot no banco de dados
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
// Processa o update do Telegram
$entrada = file_get_contents('php://input');
$atualizacao = json_decode($entrada ?: '{}', true);
// 1. Detectar adição/remoção do bot em grupos (my_chat_member)
if (isset($atualizacao['my_chat_member'])) {
    $chat = $atualizacao['my_chat_member']['chat'];
    $novoStatus = $atualizacao['my_chat_member']['new_chat_member']['status'] ?? '';
    // Status que indicam que o bot é membro ou admin
    if (in_array($novoStatus, ['member', 'administrator', 'creator'])) {
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
    // Status que indicam saída
    elseif (in_array($novoStatus, ['left', 'kicked'])) {
        $stmt = $pdo->prepare("DELETE FROM bot_grupos WHERE bot_id = ? AND id_telegram = ?");
        $stmt->execute([$bot['id'], (string)$chat['id']]);
        registrarAtividade($bot['id_usuario'], 'sistema', 'Grupo', "Bot removido do grupo: " . ($chat['title'] ?? $chat['id']));
    }
    echo 'ok status';
    exit;
}
$idChat = null;
$texto = '';
if (isset($atualizacao['callback_query'])) {
    $idChat = (string) $atualizacao['callback_query']['message']['chat']['id'];
    $texto = (string) $atualizacao['callback_query']['data'];
    requisicao_telegram($token, 'answerCallbackQuery', ['callback_query_id' => $atualizacao['callback_query']['id']]);
} elseif (isset($atualizacao['message']['chat']['id'])) {
    $idChat = (string) $atualizacao['message']['chat']['id'];
    $texto = trim((string) ($atualizacao['message']['text'] ?? ''));
    // Captura telefone quando o usuário compartilha o contato
    if (isset($atualizacao['message']['contact']['phone_number'])) {
        $telefoneLead = preg_replace('/\D/', '', $atualizacao['message']['contact']['phone_number']);
        try {
            $pdo->prepare("UPDATE leads SET telefone = ? WHERE id_telegram = ? AND bot_id = ?")
                ->execute([$telefoneLead, $idChat, $bot['id']]);
        } catch (Exception $e) {}
        echo 'ok';
        exit;
    }
}
if ($idChat && isset($atualizacao['message']['text'])) {
    $estadoDocumento = obter_estado_pix_recorrente((int)$bot['id'], $idChat);
    if ($estadoDocumento) {
        limpar_estado_pix_recorrente((int)$bot['id'], $idChat);
        requisicao_telegram($token, 'sendMessage', [
            'chat_id' => $idChat,
            'text' => 'O fluxo de pagamento foi atualizado. Toque de novo no botao ou passo de PIX recorrente no menu para continuar.'
        ]);
        exit;
    }
}
// Extrai parâmetro do /start (ex: "/start campanha_maio" → $startParam = "campanha_maio")
$startParam = null;
if (preg_match('/^\/start\s+(.+)$/i', $texto, $startMatch)) {
    $startParam = trim($startMatch[1]);
    $texto = '/start'; // normaliza para o fluxo tratar igual ao /start comum
}
if (strpos($texto, 'verificar_pagamento_') === 0) {
    $txid = str_replace('verificar_pagamento_', '', $texto);
    // Busca venda no banco
    try {
        $stmt = $pdo->prepare("SELECT * FROM vendas WHERE transacao_id = ?");
        $stmt->execute([$txid]);
        $venda = $stmt->fetch();
        if ($venda) {
            if ($venda['status'] === 'pago') {
                requisicao_telegram($token, 'sendMessage', ['chat_id' => $idChat, 'text' => '✅ Seu pagamento já foi confirmado!']);
            } else {
                // Usa o gateway registrado na venda para verificar o pagamento
                $stmtDono = $pdo->prepare("SELECT id_usuario FROM bots WHERE id = ?");
                $stmtDono->execute([$venda['bot_id']]);
                $idUsuarioDono = $stmtDono->fetchColumn();

                // Descobre qual gateway foi usado na venda
                $nomeGwVenda = null;
                if (!empty($venda['id_gateway'])) {
                    $stmtGwNome = $pdo->prepare("SELECT nome FROM gateways WHERE id = ?");
                    $stmtGwNome->execute([$venda['id_gateway']]);
                    $nomeGwVenda = $stmtGwNome->fetchColumn() ?: null;
                }
                if (!$nomeGwVenda) {
                    // Detecta PushinPay pelo formato UUID do TXID (ex: a15b9de2-7a44-4b1e-a6a3-d9fb280e54c7)
                    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $txid)) {
                        $nomeGwVenda = 'pushinpay';
                    } else {
                        $nomeGwVenda = 'efi'; // fallback para compatibilidade com vendas antigas
                    }
                }

                $gatewayConfig = getUserGatewayConfig((int)$idUsuarioDono, $nomeGwVenda);
                $debugLog = __DIR__ . '/logs/verificar_pag_debug.log';
                file_put_contents($debugLog, '[' . date('Y-m-d H:i:s') . '] TXID=' . $txid . ' | gateway=' . $nomeGwVenda . ' | gatewayConfig=' . ($gatewayConfig ? 'ok' : 'NULL') . PHP_EOL, FILE_APPEND);
                if ($gatewayConfig) {
                    $provVerif = resolveGatewayProvider($nomeGwVenda, $gatewayConfig);
                    $resp = $provVerif ? $provVerif->consultarCobranca($txid) : ['sucesso' => false];
                    $statusVerif = strtoupper(trim($resp['dados']['status'] ?? $resp['dados']['statusCob'] ?? ''));
                    file_put_contents($debugLog, '[' . date('Y-m-d H:i:s') . '] resp=' . json_encode($resp) . ' | statusVerif=' . $statusVerif . PHP_EOL, FILE_APPEND);
                    if ($resp['sucesso'] && in_array($statusVerif, ['CONCLUIDA', 'PAGO', 'LIQUIDADO', 'PAID', 'APPROVED', 'COMPLETED'])) {
                        // Foi pago! Atualiza e libera
                        // VERIFICA SE JÁ ESTAVA PAGO ANTES DE PROCESSAR
                        $stmtCheck = $pdo->prepare("SELECT status FROM vendas WHERE id = ?");
                        $stmtCheck->execute([$venda['id']]);
                        if ($stmtCheck->fetchColumn() === 'pago') {
                             // Já foi processado por outra requisição simultânea. Para aqui.
                             exit;
                        }

                        $pdo->prepare("UPDATE vendas SET status = 'pago', pago_em = NOW() WHERE id = ?")->execute([$venda['id']]);
                        
                        // Traqueamento de Eventos (Pixel/API) - Manual Check
                        require_once __DIR__ . '/funcoes/traqueamento.php';
                        $nomeLeadManual = '';
                        try {
                            $stmtLead = $pdo->prepare("SELECT nome FROM leads WHERE id_telegram = ? AND bot_id = ?");
                            $stmtLead->execute([$venda['id_telegram'], $venda['bot_id']]);
                            $nomeLeadManual = $stmtLead->fetchColumn() ?: '';
                        } catch (Exception $e) {}

                        $dadosEventoManual = [
                            'valor' => (float)$venda['valor'],
                            'transacao_id' => $txid,
                            'event_id' => $txid
                        ];
                        $userDataManual = [
                            'id_telegram' => $venda['id_telegram'],
                            'first_name' => $nomeLeadManual
                        ];
                        
                        // Mapeamento correto de evento para PIX PAGO
                        enviarEventosTraqueamento((int)$idUsuarioDono, 'compra', $dadosEventoManual, $userDataManual);

                        // Libera grupo
                         $msg = "✅ *Pagamento Confirmado!*\n\nObrigado pela sua compra.";
                        if (!empty($venda['id_grupo_telegram'])) {
                            $idGrupo = $venda['id_grupo_telegram'];
                            $tempoMinutos = (int)($venda['tempo_acesso_minutos'] ?? ($venda['dias_acesso'] * 1440));
                            // Revoga link antigo do mesmo usuário para impedir reuso/compartilhamento.
                            $stmtLinkAnterior = $pdo->prepare("SELECT invite_link FROM membros_grupos WHERE id_telegram = ? AND id_grupo_telegram = ? AND bot_id = ? LIMIT 1");
                            $stmtLinkAnterior->execute([$venda['id_telegram'], $idGrupo, $venda['bot_id']]);
                            $linkAnterior = (string)($stmtLinkAnterior->fetchColumn() ?: '');
                            if ($linkAnterior !== '') {
                                requisicao_telegram($token, 'revokeChatInviteLink', [
                                    'chat_id' => $idGrupo,
                                    'invite_link' => $linkAnterior
                                ]);
                            }
                            $invite = requisicao_telegram($token, 'createChatInviteLink', [
                                'chat_id' => $idGrupo,
                                'member_limit' => 1,
                                'expire_date' => time() + (15 * 60),
                                'name' => 'Venda #' . $venda['id']
                            ]);
                            if (($invite['ok'] ?? false) && isset($invite['result']['invite_link'])) {
                            $link = $invite['result']['invite_link'];
                            // Usa a mesma lógica de cálculo de minutos que foi usada na criação da venda
                            $minutosAcesso = $venda['tempo_acesso_minutos'] ?? 0;
                            if ($minutosAcesso <= 0) {
                                // Fallback para dias se tempo_acesso_minutos estiver zerado (legado)
                                $minutosAcesso = ($venda['dias_acesso'] ?? 0) * 1440;
                            }
                            $dataExpiracao = date('Y-m-d H:i:s', strtotime("+$minutosAcesso minutes"));
                            $pdo->prepare("
                                INSERT INTO membros_grupos (id_telegram, id_grupo_telegram, bot_id, venda_id, data_expiracao, invite_link, status)
                                VALUES (?, ?, ?, ?, ?, ?, 'ativo')
                                ON DUPLICATE KEY UPDATE status = 'ativo', data_expiracao = VALUES(data_expiracao), venda_id = VALUES(venda_id), invite_link = VALUES(invite_link)
                            ")->execute([$venda['id_telegram'], $idGrupo, $venda['bot_id'], $venda['id'], $dataExpiracao, $link]);
                            $msg .= "\n\n🚀 *Acesso Liberado!*\nClique no link abaixo para entrar no grupo exclusivo:\n\n$link\n\n⚠️ Este link é válido apenas para você.";
                            if ($minutosAcesso < 60) {
                                 $msg .= "\n⏳ *Seu acesso expira em {$minutosAcesso} minutos.*";
                            } elseif ($minutosAcesso < 1440) {
                                 $horas = floor($minutosAcesso / 60);
                                 $msg .= "\n⏳ *Seu acesso expira em {$horas} horas.*";
                            } else {
                                 $dias = floor($minutosAcesso / 1440);
                                 $msg .= "\n⏳ *Seu acesso expira em {$dias} dias.*";
                            }
                            $msg .= "\n*(Data exata: " . date('d/m/Y \à\s H:i', strtotime($dataExpiracao)) . ")*";
                        }
                        }
                        requisicao_telegram($token, 'sendMessage', ['chat_id' => $idChat, 'text' => $msg, 'parse_mode' => 'Markdown']);
                        
                        // Atualização: Verifica se já foi processado para evitar duplicidade no fluxo
                        // Se for uma renovação (tem venda_pai_id ou foi gerado via cron), o fluxo já pode ter sido processado ou não se aplica.
                        // Mas aqui estamos falando do fluxo principal.
                        // Se o webhook receber 2 notificações (ex: Pix recebido e depois Concluido), ele pode entrar aqui 2 vezes.
                        // O status já foi atualizado para 'pago'.
                        // Vamos verificar se já enviamos algo recentemente? Não, o update status protege.
                        // Mas se o update acontecer muito rápido em paralelo?
                        
                        // Continua Fluxo SOMENTE se tiver operador definido
                        if (!empty($venda['id_operador_fluxo'])) {
                            // Precisamos carregar o fluxo
                             $stmtFluxo = $pdo->prepare("SELECT f.dados_fluxograma FROM bots b JOIN fluxos f ON b.id_fluxo_conectado = f.id WHERE b.id = ?");
                             $stmtFluxo->execute([$venda['bot_id']]);
                             $dadosJson = $stmtFluxo->fetchColumn();
                             if ($dadosJson) {
                                 $dadosFluxo = json_decode($dadosJson, true);
                                 $proximoId = obter_proximo_no($dadosFluxo['links'], $venda['id_operador_fluxo']); // Tenta output_pago (precisaria ajustar obter_proximo_no para aceitar conector)
                                 // Ajuste rápido: obter_proximo_no padrão pega qualquer saída.
                                 // Para pegar 'output_pago', precisamos iterar manualmente ou melhorar a função.
                                 // Vou iterar manualmente aqui rapidinho
                                 $proximoIdPago = null;
                                 foreach ($dadosFluxo['links'] as $link) {
                                     if (($link['fromOperator'] ?? '') === $venda['id_operador_fluxo'] && ($link['fromConnector'] ?? '') === 'output_pago') {
                                         $proximoIdPago = $link['toOperator'] ?? null;
                                         break;
                                     }
                                 }
                                 if ($proximoIdPago) {
                                     while ($proximoIdPago && isset($dadosFluxo['operators'][$proximoIdPago])) {
                                         $operador = $dadosFluxo['operators'][$proximoIdPago];
                                         processar_e_enviar_bloco($token, $idChat, $operador, $proximoIdPago);
                                         if (in_array($operador['properties']['type'] ?? '', ['botoes', 'pix'])) break;
                                         $proximoIdPago = obter_proximo_no($dadosFluxo['links'], $proximoIdPago);
                                     }
                                 }
                             }
                        }
                    } else {
                        requisicao_telegram($token, 'sendMessage', ['chat_id' => $idChat, 'text' => '⏳ Pagamento ainda não identificado. Aguarde alguns instantes e tente novamente.']);
                    }
                }
            }
        } else {
            requisicao_telegram($token, 'sendMessage', ['chat_id' => $idChat, 'text' => '❌ Pagamento não encontrado.']);
        }
    } catch (Exception $e) {
        requisicao_telegram($token, 'sendMessage', ['chat_id' => $idChat, 'text' => 'Erro ao verificar.']);
    }
    exit;
}
// 2. Detectar se foi adicionado via mensagem de serviço (backup para my_chat_member)
if (isset($atualizacao['message']['new_chat_members'])) {
    foreach ($atualizacao['message']['new_chat_members'] as $membro) {
        if (($membro['id'] ?? 0) == ($bot['id_bot_telegram'] ?? 0)) {
             // É o próprio bot
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
// Detectar se o título do grupo mudou
if (isset($atualizacao['message']['new_chat_title'])) {
    $chat = $atualizacao['message']['chat'];
    $stmt = $pdo->prepare("UPDATE bot_grupos SET titulo = ? WHERE bot_id = ? AND id_telegram = ?");
    $stmt->execute([$atualizacao['message']['new_chat_title'], $bot['id'], (string)$chat['id']]);
}
// Detectar migração de grupo para supergrupo
if (isset($atualizacao['message']['migrate_to_chat_id'])) {
    $chatAntigo = (string)$atualizacao['message']['chat']['id'];
    $chatNovo = (string)$atualizacao['message']['migrate_to_chat_id'];
    $stmt = $pdo->prepare("UPDATE bot_grupos SET id_telegram = ?, tipo = 'supergroup' WHERE bot_id = ? AND id_telegram = ?");
    $stmt->execute([$chatNovo, $bot['id'], $chatAntigo]);
}
if (!$idChat) {
    echo 'sem chat';
    exit;
}
// Resposta simples ao /start
if ($texto === '/start') {
    // Registrar Lead e Atividade
    try {
        $isNovoLead = false;
        // Verifica se já existe o lead para este bot
        $stmtLead = $pdo->prepare("SELECT id FROM leads WHERE id_telegram = ? AND bot_id = ?");
        $stmtLead->execute([$idChat, $bot['id']]);
        if (!$stmtLead->fetch()) {
            $isNovoLead = true;
            $nomeUsuario = trim(($atualizacao['message']['from']['first_name'] ?? '') . ' ' . ($atualizacao['message']['from']['last_name'] ?? ''));
            if ($nomeUsuario === '') $nomeUsuario = 'Usuário ' . $idChat;
            $dataCriacao = date('Y-m-d H:i:s');
            $stmtInsertLead = $pdo->prepare("INSERT INTO leads (id_telegram, nome, bot_id, criado_em) VALUES (?, ?, ?, ?)");
            $stmtInsertLead->execute([$idChat, $nomeUsuario, $bot['id'], $dataCriacao]);
            $stmtInsertAtiv = $pdo->prepare("INSERT INTO atividades (id_usuario, tipo, titulo, descricao, icone, criado_em) VALUES (?, ?, ?, ?, ?, ?)");
            $stmtInsertAtiv->execute([$bot['id_usuario'], 'lead', 'Novo Lead', $nomeUsuario . ' iniciou conversa', 'user', $dataCriacao]);
        }
        // Se veio de um link de rastreamento, incrementa starts (sempre) e leads (só se for novo lead)
        if ($startParam) {
            $camposRast = 'starts = starts + 1' . ($isNovoLead ? ', leads = leads + 1' : '');
            $pdo->prepare("UPDATE links_rastreamento SET $camposRast WHERE bot_id = ? AND identificador = ?")
                ->execute([$bot['id'], $startParam]);
        }
    } catch (Exception $e) {
        // Ignora erro para não quebrar o webhook
    }
}
// Função auxiliar para encontrar o próximo nó (conexão padrão)
function obter_proximo_no(array $links, string $idAtual): ?string {
    foreach ($links as $link) {
        // Procura link saindo deste operador (considera output_1 ou qualquer um se for único)
        if (($link['fromOperator'] ?? '') === $idAtual) {
            return $link['toOperator'] ?? null;
        }
    }
    return null;
}
// Se o bot tem um fluxo conectado, tenta executar
if (!empty($bot['id_fluxo_conectado'])) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM fluxos WHERE id = ?");
        $stmt->execute([$bot['id_fluxo_conectado']]);
        $fluxo = $stmt->fetch();
        if ($fluxo) {
            $dadosFluxo = json_decode($fluxo['dados_fluxograma'] ?? '{}', true);
            $dadosFluxo = is_array($dadosFluxo) ? $dadosFluxo : ['operators' => [], 'links' => []];
            if ($texto === '/start') {
                // Início do fluxo
                $proximoId = buscar_proximo_do_inicio($dadosFluxo);
                // Executa em loop até encontrar um ponto de parada (ex: botões)
                while ($proximoId && isset($dadosFluxo['operators'][$proximoId])) {
                    $operador = $dadosFluxo['operators'][$proximoId];
                    processar_e_enviar_bloco($token, $idChat, $operador, $proximoId);
                    // Se for Botões, para a execução automática e aguarda interação do usuário
                    if (($operador['properties']['type'] ?? '') === 'botoes') {
                        break;
                    }
                    // Se for Pix, para a execução (aguarda pagamento)
                    if (($operador['properties']['type'] ?? '') === 'pix') {
                        break;
                    }
                    // Se for Delay
                    if (($operador['properties']['type'] ?? '') === 'delay') {
                         $segundos = (int)($operador['properties']['delay_min'] ?? 0);
                         if ($segundos > 0 && $segundos <= 5) sleep($segundos);
                    }
                    // Busca o próximo nó
                    $proximoId = obter_proximo_no($dadosFluxo['links'], $proximoId);
                }
            } else {
                // Tenta identificar resposta a botões (lógica sem estado)
                $encontrou = false;
                foreach ($dadosFluxo['operators'] as $opId => $op) {
                    if (($op['properties']['type'] ?? '') === 'botoes') {
                         $botoes = $op['properties']['botoes'] ?? [];
                         // Verifica se o texto recebido corresponde a algum botão deste bloco
                         if (in_array($texto, $botoes)) {
                             // Encontrou o botão clicado
                             $index = array_search($texto, $botoes);
                             $outputKey = 'output_' . $index;
                             // Busca para onde esse botão leva
                             $proximoId = null;
                             foreach ($dadosFluxo['links'] as $link) {
                                 if (($link['fromOperator'] ?? '') === $opId && ($link['fromConnector'] ?? '') === $outputKey) {
                                     $proximoId = $link['toOperator'] ?? null;
                                     break;
                                 }
                             }
                             // Se encontrou destino, executa o fluxo a partir dele
                             if ($proximoId) {
                                $encontrou = true;
                                while ($proximoId && isset($dadosFluxo['operators'][$proximoId])) {
                                    $operador = $dadosFluxo['operators'][$proximoId];
                                    // Adicionei a passagem de $proximoId aqui também!
                                    processar_e_enviar_bloco($token, $idChat, $operador, $proximoId);
                                    if (($operador['properties']['type'] ?? '') === 'botoes') break;
                                    if (($operador['properties']['type'] ?? '') === 'pix') break;
                                    $proximoId = obter_proximo_no($dadosFluxo['links'], $proximoId);
                                }
                            }
                             break; // Sai do loop de operadores se achou o botão
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
