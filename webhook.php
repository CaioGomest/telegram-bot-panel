<?php
declare(strict_types=1);
date_default_timezone_set('America/Sao_Paulo');
require_once 'conexao.php';
require_once __DIR__ . '/funcoes/gateways.php';
require_once __DIR__ . '/funcoes/webhooks.php';
require_once __DIR__ . '/funcoes/fluxo_blocos.php';
require_once __DIR__ . '/funcoes/execucao_fluxo.php';
require_once __DIR__ . '/funcoes/previas.php';
require_once __DIR__ . '/funcoes/packs.php';
require_once __DIR__ . '/funcoes/assinatura.php';
require_once __DIR__ . '/funcoes/guiado_ofertas.php';
require_once __DIR__ . '/funcoes/remarketing_ofertas.php';
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
if (strpos($texto, 'saida::') === 0) {
    // Clique em botão de upsell/downsell/order_bump: "saida::<id_operador>::aceito|recusado".
    [, $id_operador_origem, $saida_escolhida] = array_pad(explode('::', $texto, 3), 3, '');
    try {
        $stmt_fluxo_saida = $pdo->prepare("SELECT f.dados_fluxograma FROM bots b JOIN fluxos f ON b.id_fluxo_conectado = f.id WHERE b.id = ?");
        $stmt_fluxo_saida->execute([$bot['id']]);
        $dados_json_saida = $stmt_fluxo_saida->fetchColumn();
        if ($dados_json_saida && $id_operador_origem !== '' && in_array($saida_escolhida, ['aceito', 'recusado'], true)) {
            $dados_fluxo_saida = json_decode($dados_json_saida, true);
            $dados_fluxo_saida = is_array($dados_fluxo_saida) ? $dados_fluxo_saida : ['operators' => [], 'links' => []];
            $conector = 'output_' . ($saida_escolhida === 'aceito' ? 'aceito' : 'recusado');
            registrarRespostaOferta($pdo, (int) $bot['id'], (string) $id_chat, $dados_fluxo_saida['operators'][$id_operador_origem]['properties'] ?? [], $saida_escolhida === 'aceito');
            $proximo_id_saida = obterProximoNoPorConector($dados_fluxo_saida['links'] ?? [], $id_operador_origem, $conector);
            caminharFluxoAPartirDe($token, $id_chat, $dados_fluxo_saida, $proximo_id_saida);
        }
    } catch (Exception $e) {
        // Ignora erro para não quebrar o webhook
    }
    exit;
}
if (strpos($texto, 'btn::') === 0) {
    // Clique em botão do bloco "Botões": "btn::<id_operador>::<indice>" -- resolve direto por
    // id no clique, sem varrer o grafo procurando texto de botão. Antes o callback_data era só
    // o texto do botão e a busca pegava o primeiro bloco "Botões" do fluxo com aquele texto,
    // não necessariamente o bloco certo (causou PIX com valor errado quando dois blocos tinham
    // um botão de mesmo texto). Callbacks antigos (sem esse prefixo) continuam caindo no método
    // por texto mais abaixo, para não quebrar conversas já em andamento.
    [, $id_operador_botao, $indice_botao] = array_pad(explode('::', $texto, 3), 3, '');
    try {
        $stmt_fluxo_btn = $pdo->prepare("SELECT f.dados_fluxograma FROM bots b JOIN fluxos f ON b.id_fluxo_conectado = f.id WHERE b.id = ?");
        $stmt_fluxo_btn->execute([$bot['id']]);
        $dados_json_btn = $stmt_fluxo_btn->fetchColumn();
        if ($dados_json_btn && $id_operador_botao !== '' && $indice_botao !== '') {
            $dados_fluxo_btn = json_decode($dados_json_btn, true);
            $dados_fluxo_btn = is_array($dados_fluxo_btn) ? $dados_fluxo_btn : ['operators' => [], 'links' => []];
            // Bloco com "sumir após o clique": apaga a mensagem dos botões assim que o cliente escolhe.
            $id_msg_botoes = (int) ($atualizacao['callback_query']['message']['message_id'] ?? 0);
            if ($id_msg_botoes > 0 && !empty($dados_fluxo_btn['operators'][$id_operador_botao]['properties']['sumir_apos_clique'])) {
                requisicaoTelegram($token, 'deleteMessage', ['chat_id' => $id_chat, 'message_id' => $id_msg_botoes]);
            }
            $proximo_id_btn = obterProximoNoPorConector($dados_fluxo_btn['links'] ?? [], $id_operador_botao, 'output_' . $indice_botao);
            caminharFluxoAPartirDe($token, $id_chat, $dados_fluxo_btn, $proximo_id_btn);
        }
    } catch (Exception $e) {
        // Ignora erro para não quebrar o webhook
    }
    exit;
}
if (strpos($texto, 'rmk::') === 0) {
    // Clique em botão de campanha de remarketing ("rmk::<campanha>::<plano>::<variante>"): plano, order bump (se a campanha tiver) e Pix. Ver funcoes/remarketing_ofertas.php.
    tratarCliqueRemarketing($pdo, $token, $bot, (string) $id_chat, $texto);
    exit;
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

                // Venda antiga de antes da coluna id_gateway existir, sem como saber qual
                // gateway foi usado -- não dá pra reconsultar, segue sem confirmar por aqui
                // (o cliente pode continuar tentando; algum outro caminho de confirmação
                // resolve se o pagamento realmente caiu).
                $gateway_config = $nome_gw_venda ? getUserGatewayConfig((int)$id_usuario_dono, $nome_gw_venda) : null;
                $debug_log = __DIR__ . '/logs/verificar_pag_debug.log';
                file_put_contents($debug_log, '[' . date('Y-m-d H:i:s') . '] TXID=' . $txid . ' | gateway=' . $nome_gw_venda . ' | gatewayConfig=' . ($gateway_config ? 'ok' : 'NULL') . PHP_EOL, FILE_APPEND);
                if ($gateway_config) {
                    $prov_verif = resolveGatewayProvider($nome_gw_venda, $gateway_config);
                    $resp = $prov_verif ? $prov_verif->consultarCobranca($txid) : ['sucesso' => false];
                    $status_verif = strtoupper(trim($resp['dados']['status'] ?? $resp['dados']['statusCob'] ?? ''));
                    file_put_contents($debug_log, '[' . date('Y-m-d H:i:s') . '] sucesso=' . var_export($resp['sucesso'] ?? null, true) . ' | statusVerif=' . $status_verif . PHP_EOL, FILE_APPEND);
                    if ($resp['sucesso'] && in_array($status_verif, ['CONCLUIDA', 'PAGO', 'LIQUIDADO', 'PAID', 'APPROVED', 'COMPLETED'])) {
                        // Transição atômica: o UPDATE só afeta a linha se ela ainda não estava paga.
                        // Se o webhook do gateway ou o cron de fallback confirmarem a mesma venda
                        // ao mesmo tempo, só um dos dois ganha a corrida (rowCount() = 1) e segue
                        // adiante — evita disparar o split duas vezes pra mesma venda.
                        $stmt_marca = $pdo->prepare("UPDATE vendas SET status = 'pago', pago_em = NOW() WHERE id = ? AND status != 'pago'");
                        $stmt_marca->execute([$venda['id']]);
                        if ($stmt_marca->rowCount() === 0) {
                             // Já foi processado por outra requisição simultânea. Para aqui.
                             exit;
                        }
                        aposPagamentoConfirmado($venda);

                        require_once __DIR__ . '/funcoes/traqueamento.php';
                        $dados_evento_manual = [
                            'valor' => (float)$venda['valor'],
                            'comissao' => (float)($venda['comissao_admin'] ?? 0),
                            'plano_id' => $venda['id_plano'] ?? null,
                            'nome_produto' => $venda['nome_produto'] ?? null,
                            'pago_em' => date('Y-m-d H:i:s'),
                            'transacao_id' => $txid,
                            'event_id' => $txid,
                            'criado_em' => $venda['criado_em'] ?? null
                        ];
                        $user_data_manual = montarUserDataTraqueamento($pdo, $venda['id_telegram'], $venda['bot_id']);
                        // Mesmo registro que o webhook da gateway faz: aparece em Atividade/notificações do dono.
                        registrarAtividade((int)$id_usuario_dono, 'venda', 'Venda Aprovada', 'PIX de R$ ' . number_format((float)$venda['valor'], 2, ',', '.') . ' foi pago.');

                        enviarEventosTraqueamento((int)$id_usuario_dono, 'compra', $dados_evento_manual, $user_data_manual);
                        dispararWebhooks((int)$id_usuario_dono, 'payment_approved', [
                            'bot_id' => (int)$venda['bot_id'],
                            'id_telegram' => (string)$venda['id_telegram'],
                            'venda_id' => (int)$venda['id'],
                            'transacao_id' => $txid,
                            'gateway' => $nome_gw_venda,
                        ]);

                         $texto_confirmado = textoConfirmacaoPagamentoConfigurado($pdo, $venda);
                         $msg = $texto_confirmado !== null
                             ? escaparMarkdownTelegram($texto_confirmado)
                             : "✅ *Pagamento Confirmado!*\n\nObrigado pela sua compra.";
                        $link_aprovado = null;   // link do grupo desta venda (para a mensagem da aba Pagamentos, modelo novo)
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
                            // usada em webhook_omegapayments.php::liberarAcessoGrupoOmegapayments() e
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
                            $link_aprovado = $link;

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
                                if (acessoVitalicio($minutos_acesso)) {
                                     $msg .= "\n♾️ *Acesso vitalício.*";
                                } elseif ($minutos_acesso < 60) {
                                     $msg .= "\n⏳ *Seu acesso expira em {$minutos_acesso} minutos.*";
                                } elseif ($minutos_acesso < 1440) {
                                     $horas = floor($minutos_acesso / 60);
                                     $msg .= "\n⏳ *Seu acesso expira em {$horas} horas.*";
                                } else {
                                     $dias = floor($minutos_acesso / 1440);
                                     $msg .= "\n⏳ *Seu acesso expira em {$dias} dias.*";
                                }
                                if (!acessoVitalicio($minutos_acesso)) {
                                    $msg .= "\n*(Data exata: " . date('d/m/Y \à\s H:i', strtotime($data_expiracao)) . ")*";
                                }
                            } else {
                                $msg .= "\n\n⚠️ Não foi possível gerar o link do grupo automaticamente. O administrador entrará em contato.";
                            }
                        }
                        // Guiado no modelo novo da aba Pagamentos: mensagem, mídias e botão de acesso escolhidos pelo dono (funcoes/pagamentos_guiado.php);
                        // qualquer outro caso (Editor Visual, fluxo antigo, renovação) segue com o texto de sempre.
                        if (!pagamentoEnviarAprovacao($pdo, $token, $venda, $link_aprovado, !empty($venda['id_grupo_telegram']) && $link_aprovado === null, (int) ($minutos_acesso ?? 0))) {
                            $msg .= textoEntregaLink($venda, 'markdown');   // Fluxo Guiado com entrega por link
                            requisicaoTelegram($token, 'sendMessage', ['chat_id' => $id_chat, 'text' => $msg, 'parse_mode' => 'Markdown']);
                        }
                        ofertasPosPagamentoBasico($pdo, $token, $venda);   // Guiado: Upsell/Order Bump DEPOIS de pagar

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
                                 $proximo_id_pago = obterProximoNoPorConector($dados_fluxo['links'], $venda['id_operador_fluxo'], 'output_pago');
                                 caminharFluxoAPartirDe($token, $id_chat, $dados_fluxo, $proximo_id_pago);
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
        // /start pode chegar como mensagem de texto OU clique num botão (callback_query,
        // ex: botão "Recomeçar" do aviso de renovação) — o campo "from" mora em lugares diferentes.
        $dados_remetente = $atualizacao['message']['from'] ?? $atualizacao['callback_query']['from'] ?? [];
        // Username do Telegram é opcional (pessoa pode nao ter) -- fica NULL nesse caso,
        // nao string vazia, pra distinguir "nunca capturado" de "sabidamente sem username".
        $username_remetente = trim((string) ($dados_remetente['username'] ?? ''));
        $username_remetente = $username_remetente !== '' ? $username_remetente : null;
        $lead_existente = $stmt_lead->fetch();
        if (!$lead_existente) {
            $is_novo_lead = true;
            $nome_usuario = trim(($dados_remetente['first_name'] ?? '') . ' ' . ($dados_remetente['last_name'] ?? ''));
            if ($nome_usuario === '') $nome_usuario = 'Usuário ' . $id_chat;
            $data_criacao = date('Y-m-d H:i:s');
            // origem_rastreio guarda de qual link o lead veio. Antes o $start_param so
            // incrementava contador e era descartado -- sem ele nao da pra dizer depois
            // qual campanha gerou a venda.
            $stmt_insert_lead = $pdo->prepare("INSERT INTO leads (id_telegram, nome, nome_usuario_telegram, origem_rastreio, bot_id, criado_em, id_fluxo) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt_insert_lead->execute([$id_chat, $nome_usuario, $username_remetente, $start_param ?: null, $bot['id'], $data_criacao, $bot['id_fluxo_conectado'] ?: null]);
            $id_lead_novo = (int) $pdo->lastInsertId();
            $stmt_insert_ativ = $pdo->prepare("INSERT INTO atividades (id_usuario, tipo, titulo, descricao, icone, criado_em) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt_insert_ativ->execute([$bot['id_usuario'], 'lead', 'Novo Lead', $nome_usuario . ' iniciou conversa', 'user', $data_criacao]);
            dispararWebhooks((int) $bot['id_usuario'], 'user_joined', [
                'bot_id' => (int) $bot['id'],
                'id_telegram' => (string) $id_chat,
                'lead_id' => $id_lead_novo,
            ]);
        } elseif ($username_remetente !== null) {
            // Lead que já existia: mantém o @username em dia (pode ter sido capturado
            // como NULL antes desta coluna existir, ou a pessoa pode ter trocado de
            // username desde o primeiro /start).
            $pdo->prepare("UPDATE leads SET nome_usuario_telegram = ? WHERE id_telegram = ? AND bot_id = ? AND (nome_usuario_telegram IS NULL OR nome_usuario_telegram != ?)")
                ->execute([$username_remetente, $id_chat, $bot['id'], $username_remetente]);
        }
        // Cada início de conversa (lead novo ou quem voltou) vira uma linha em leads_starts: é o que o dashboard conta como "starts".
        try {
            $id_lead_start = $is_novo_lead ? $id_lead_novo : (int) ($lead_existente['id'] ?? 0);
            if ($id_lead_start > 0) {
                registrarInicioConversa($pdo, (int) $bot['id'], $id_lead_start, $is_novo_lead);
            }
        } catch (Throwable $e) {
            // tabela ainda não criada (falta rodar Admin → Atualizar Banco): o /start segue normalmente
        }
        // Se veio de um link de rastreamento, incrementa starts (sempre) e leads (só se for novo lead)
        if ($start_param) {
            // Lead que ja existia e ainda nao tinha origem: grava a primeira que aparecer.
            // Primeiro toque, de proposito -- se sobrescrevesse, a ultima campanha levaria
            // o credito de um lead que outra trouxe.
            if (!$is_novo_lead) {
                $pdo->prepare("UPDATE leads SET origem_rastreio = ? WHERE id_telegram = ? AND bot_id = ? AND (origem_rastreio IS NULL OR origem_rastreio = '')")
                    ->execute([$start_param, $id_chat, $bot['id']]);
            }
            $campos_rast = 'starts = starts + 1' . ($is_novo_lead ? ', leads = leads + 1' : '');
            $pdo->prepare("UPDATE links_rastreamento SET $campos_rast WHERE bot_id = ? AND identificador = ?")
                ->execute([$bot['id'], $start_param]);
            // Veio de um Redirecionamento (/l/{slug}): start = "rd_{slug}". Conta no link de origem.
            if (strncmp($start_param, 'rd_', 3) === 0) {
                $campos_rd = 'starts = starts + 1' . ($is_novo_lead ? ', leads = leads + 1' : '');
                $pdo->prepare("UPDATE redirecionadores SET $campos_rd WHERE id_usuario = ? AND slug = ?")
                    ->execute([$bot['id_usuario'], strtolower(substr($start_param, 3))]);
            }
        }
    } catch (Exception $e) {
        // Ignora erro para não quebrar o webhook
    }
}
/**
 * Resolve o próximo nó considerando tipos que decidem a saída sozinhos (hoje só o
 * randomizer, que sorteia por peso em vez de seguir o único link que existe). Os outros
 * tipos com múltiplas saídas (pix, upsell/downsell/order_bump) não passam por aqui —
 * eles param a execução (break no loop) e são resolvidos por evento externo (pagamento
 * confirmado, clique de botão), não pela caminhada sequencial.
 */
/**
 * Continua a caminhada do fluxo a partir de $id_partida, mesma mecânica repetida nos 3
 * pontos que já existiam (start, resposta de botão, pagamento confirmado): processa cada
 * bloco e avança, parando em blocos que esperam algo externo (clique, pagamento).
 */
/**
 * Motor de execução do modo básico (formulário guiado, sem grafo). Reaproveita
 * processarEEnviarBloco() montando um "operador" sintético tipo pix -- mesma função que
 * já resolve gateway/fallback/split/mensagem/insert de venda pro editor de nós, sem
 * duplicar essa lógica sensível (é dinheiro de verdade). $id_operador vazio é o mesmo
 * caminho que uma venda gerada fora de qualquer grafo já usa (processarEEnviarBloco trata
 * como null), então a confirmação de pagamento entrega o acesso normalmente sem tentar
 * continuar um grafo que não existe.
 */
function enviarListaPlanosBasico(string $token, $id_chat, array $planos, string $suporte = '', string $cor_suporte = '', array $previas = [], array $dados_packs = []): void {
    if (empty($planos)) {
        requisicaoTelegram($token, 'sendMessage', ['chat_id' => $id_chat, 'text' => 'Nenhum plano disponível no momento.']);
        return;
    }
    $botoes = [];
    foreach ($planos as $p) {
        $rotulo = trim((string) ($p['nome'] ?? 'Plano')) . ' — R$ ' . number_format((float) ($p['valor'] ?? 0), 2, ',', '.') . (planoRecorrenteBasico($p) ? PERIODICIDADES_BASICO[periodicidadePlanoBasico($p)] : '');
        $botoes[] = [['text' => $rotulo, 'callback_data' => 'basico::plano::' . ($p['id'] ?? '')] + estiloBotaoBasicoValor((string) ($p['cor'] ?? ''))];
    }
    if (!empty($previas['ativo']) && previasMidiasEnviaveis($previas)) {
        $botoes[] = [['text' => trim((string) ($previas['texto_botao'] ?? '')) ?: '🔥 Prévias Grátis', 'callback_data' => 'basico::previa']];
    }
    if (packsDisponiveis(['packs' => $dados_packs])) {
        $botoes[] = [['text' => trim((string) ($dados_packs['texto_botao'] ?? '')) ?: '📦 Packs Disponíveis', 'callback_data' => 'basico::packs']];
    }
    $url_suporte = urlSuporteBasico($suporte);
    if ($url_suporte !== '') {
        $botoes[] = [['text' => '💬 Falar com o suporte', 'url' => $url_suporte] + estiloBotaoBasicoValor($cor_suporte)];
    }
    requisicaoTelegram($token, 'sendMessage', [
        'chat_id' => $id_chat,
        'text' => 'Escolha um plano:',
        'reply_markup' => json_encode(['inline_keyboard' => $botoes])
    ]);
}
/**
 * Ofertas do modo guiado, sem estado no banco: a etapa e as escolhas já feitas viajam no
 * callback_data ("basico::of::<etapa>::<plano>[::<variante>]::<1|0>", sempre < 64 bytes).
 * Ordem: upsell -> downsell (só se recusou o upsell ou não há upsell) -> order bump -> Pix.
 * Variante do plano final: o = escolhido, u = destino do upsell, d = destino do downsell.
 */
/** Upsell, downsell e order bump: texto + botões Aceitar/Recusar e, se a oferta tiver, foto ou vídeo (mesma regra das boas-vindas). */
/** Plano final de quem aceitou o downsell: variante "d<posição>" (novo) ou "d" (formato antigo, 1 plano). */
function planoDoDownsellPorVariante(array $dados_basico, string $variante): ?array {
    if ($variante === 'd') {
        return planoDaOfertaBasico($dados_basico, 'downsell');
    }
    if (preg_match('/^d(\d+)$/', $variante, $m)) {
        return planosDoDownsellBasico($dados_basico)[(int) $m[1]] ?? null;
    }
    return null;
}
/**
 * $item_order_bump: um item da sequência de Order Bumps (ofertas.order_bump.itens) aceito pelo cliente; sem ele, o bump é o próprio ofertas.order_bump (jeito antigo).
 * $agendar_ofertas: false no Pix de quem aceitou um bump da sequência (não recomeça downsell nem order bump: sem laço).
 */
function gerarPixBasico(string $token, $id_chat, array $dados_basico, string $id_plano, string $variante, bool $com_bump, ?array $item_order_bump = null, bool $agendar_ofertas = true): void {
    $pagamentos = $dados_basico['pagamentos'] ?? [];
    $plano = localizarPlanoBasico($dados_basico['planos'] ?? [], $id_plano);
    $desconto = 0.0;
    $tipo_oferta = '';   // 'upsell' / 'downsell' quando o cliente aceitou uma oferta (a entrega pode ser da oferta); '' = plano escolhido direto
    if ($variante === 'u' || preg_match('/^d\d*$/', $variante)) {
        $tipo = $variante === 'u' ? 'upsell' : 'downsell';
        $destino = $tipo === 'upsell' ? planoDaOfertaBasico($dados_basico, 'upsell') : planoDoDownsellPorVariante($dados_basico, $variante);
        if ($destino) {
            $plano = $destino;
            $tipo_oferta = $tipo;
            $desconto = descontoDaOfertaBasico($dados_basico['ofertas'][$tipo] ?? []);
        }
    }
    if (!$plano) {
        requisicaoTelegram($token, 'sendMessage', ['chat_id' => $id_chat, 'text' => 'Esse plano não existe mais. Toque em "Ver Planos" de novo.']);
        return;
    }
    $valor = round((float) ($plano['valor'] ?? 0) * (1 - $desconto / 100), 2);
    if ($valor <= 0) {
        requisicaoTelegram($token, 'sendMessage', ['chat_id' => $id_chat, 'text' => 'Não foi possível gerar o pagamento deste plano. Fale com o suporte.']);
        return;
    }
    $nome = (string) ($plano['nome'] ?? 'Produto');
    $recorrente = planoRecorrenteBasico($plano);
    $bump = $item_order_bump !== null ? $item_order_bump + ['ativo' => true] : ($dados_basico['ofertas']['order_bump'] ?? []);
    // Assinatura recorrente: o extra do order bump seria cobrado em todo ciclo, então não é aplicado.
    if ($com_bump && !$recorrente && !empty($bump['ativo'])) {
        $valor = round($valor + max(0.0, (float) ($bump['valor_extra'] ?? 0)), 2);
        $nome .= ' + ' . (trim((string) ($bump['nome'] ?? '')) ?: 'Extra');
    }
    // Upsell/Downsell aceitos podem ter entrega própria; sem ela (ou "igual ao plano") vale a entrega do plano.
    $entrega = entregaCompletaDoItemBasico($tipo_oferta !== '' ? ($dados_basico['ofertas'][$tipo_oferta] ?? []) : $plano, $tipo_oferta !== '' ? $plano : null);
    $propriedades_sinteticas = [
        'type' => 'pix',
        'nome' => $nome,
        'valor' => $valor,
        'tipo_cobranca' => $recorrente ? 'recorrente' : 'unica',
        'periodicidade' => $recorrente ? periodicidadePlanoBasico($plano) : null,
        'id_produto' => 'plano_' . (string) ($plano['id'] ?? ''),
        'expiracao_minutos' => 15,
        'dias_acesso' => (int) ($plano['dias_acesso'] ?? 30),
        'unidade_acesso' => $plano['unidade_acesso'] ?? 'dias',
        'id_grupo' => $entrega['id_grupo'],
        'link_entrega' => $entrega['link_entrega'],
        'mensagem_entrega' => $entrega['mensagem_entrega'],
        // Aba Pagamentos do Guiado: os campos de sempre e, no modelo novo, a configuração completa (funcoes/pagamentos_guiado.php).
    ] + propriedadesPagamentoBasico($pagamentos);
    $GLOBALS['ultima_venda_pix_id'] = 0;
    processarEEnviarBloco($token, $id_chat, ['properties' => $propriedades_sinteticas], '');
    if ($tipo_oferta === '' && $agendar_ofertas && !empty($GLOBALS['ultima_venda_pix_id'])) {
        // Downsell com temporizador e sequência de Order Bumps: o Pix do plano escolhido já saiu; se o lead não pagar, o cron manda as ofertas (funcoes/guiado_ofertas.php).
        garantirConexaoBanco();
        agendarDownsellBasico($GLOBALS['pdo'], (int) $GLOBALS['bot']['id'], (string) $id_chat, (int) $GLOBALS['ultima_venda_pix_id'], $id_plano, $dados_basico);
        agendarOrderBumpsBasico($GLOBALS['pdo'], (int) $GLOBALS['bot']['id'], (string) $id_chat, (int) $GLOBALS['ultima_venda_pix_id'], $id_plano, $dados_basico);
    }
}
function avancarOfertasBasico(string $token, $id_chat, array $dados_basico, string $etapa, string $id_plano, string $variante = 'o'): void {
    $ofertas = $dados_basico['ofertas'] ?? [];
    $id_cache = (string) ($dados_basico['cache_chat_id'] ?? '');  // canal/grupo de cache das mídias (mesmo das boas-vindas)
    if ($etapa === 'up') {
        // Upsell agora é oferecido DEPOIS do pagamento (ofertasPosPagamentoBasico, funcoes/guiado_ofertas.php); antes do Pix o cliente vai direto ao que escolheu.
        $etapa = 'dn';
    }
    if ($etapa === 'dn' && downsellDepoisDoPixBasico($dados_basico)) {
        $etapa = 'bp';   // downsell com temporizador: não entra no lugar do Pix, sai depois dele se o lead não pagar (agendado em gerarPixBasico)
    }
    if ($etapa === 'dn') {
        if (downsellNovoFormatoBasico($dados_basico)) {
            // Formato novo: um botão por plano marcado (o plano que o cliente já escolheu não é repetido). Sem plano sobrando, pula o downsell.
            $candidatos = planosDoDownsellSemOEscolhidoBasico($dados_basico, $id_plano);
            if ($candidatos) {
                enviarDownsellBasico($token, $id_chat, $dados_basico, $candidatos, $id_plano, $id_cache);
                return;
            }
        } else {
            $destino = planoDaOfertaBasico($dados_basico, 'downsell');
            if ($destino && (string) $destino['id'] !== $id_plano) {
                enviarOfertaBasico($token, $id_chat, $ofertas['downsell'], 'basico::of::dn::' . $id_plano, 'Que tal esta opção: ' . ($destino['nome'] ?? 'outro plano') . '?', $id_cache);
                return;
            }
        }
        $etapa = 'bp';
    }
    if ($etapa === 'bp' && $variante === 'ofertaantiga') {
        // (mantido só para botões de order bump de mensagens antigas, ver o tratamento de "basico::of::bp::" abaixo; o bump novo é oferecido depois do pagamento)
        $bump = $ofertas['order_bump'] ?? [];
        $plano_final = $variante === 'u' ? planoDaOfertaBasico($dados_basico, 'upsell') : (strpos($variante, 'd') === 0 ? planoDoDownsellPorVariante($dados_basico, $variante) : localizarPlanoBasico($dados_basico['planos'] ?? [], $id_plano));
        if (!planoRecorrenteBasico($plano_final) && !empty($bump['ativo']) && (float) ($bump['valor_extra'] ?? 0) > 0) {
            enviarOfertaBasico($token, $id_chat, $bump, 'basico::of::bp::' . $id_plano . '::' . $variante, 'Adicione um extra ao seu pedido por R$ ' . number_format((float) $bump['valor_extra'], 2, ',', '.') . '.', $id_cache);
            return;
        }
    }
    gerarPixBasico($token, $id_chat, $dados_basico, $id_plano, $variante, false);
}
function executarFluxoBasico(string $token, $id_chat, array $dados_basico, string $texto): void {
    $boas_vindas = $dados_basico['boas_vindas'] ?? [];
    $planos = $dados_basico['planos'] ?? [];

    if (($dados_basico['ativo'] ?? true) === false) {
        if ($texto === '/start' || strpos($texto, 'basico::') === 0) {
            requisicaoTelegram($token, 'sendMessage', ['chat_id' => $id_chat, 'text' => 'Este atendimento está temporariamente indisponível. Volte em breve!']);
        }
        return;
    }
    if ($texto === '/start') {
        enviarBoasVindasBasico($token, $id_chat, $boas_vindas, (string) ($dados_basico['cache_chat_id'] ?? ''));
        if (!boasVindasMostraCtaBasico($boas_vindas)) {
            // Sem o botão CTA o cliente não teria como ver os planos: a lista vai logo depois da mensagem.
            enviarListaPlanosBasico($token, $id_chat, $planos, (string) ($dados_basico['suporte'] ?? ''), (string) ($dados_basico['suporte_cor'] ?? ''), $dados_basico['previas'] ?? [], $dados_basico['packs'] ?? []);
        }
        return;
    }
    if ($texto === 'basico::ver_planos') {
        enviarListaPlanosBasico($token, $id_chat, $planos, (string) ($dados_basico['suporte'] ?? ''), (string) ($dados_basico['suporte_cor'] ?? ''), $dados_basico['previas'] ?? [], $dados_basico['packs'] ?? []);
        return;
    }
    if ($texto === 'basico::renovar') {
        enviarOfertaRenovacaoBasico($token, $id_chat, $dados_basico);
        return;
    }
    if (strpos($texto, 'basico::renovplano::') === 0) {
        gerarPixRenovacaoBasico($token, $id_chat, $dados_basico, substr($texto, strlen('basico::renovplano::')));
        return;
    }
    if ($texto === 'basico::packs') {
        enviarListaPacksBasico($token, $id_chat, $dados_basico);
        return;
    }
    if (strpos($texto, 'basico::pack::') === 0 || strpos($texto, 'basico::packbuy::') === 0) {
        $comprar = strpos($texto, 'basico::packbuy::') === 0;
        $pack = localizarPackBasico($dados_basico, substr($texto, strlen($comprar ? 'basico::packbuy::' : 'basico::pack::')));
        if (!$pack) {
            requisicaoTelegram($token, 'sendMessage', ['chat_id' => $id_chat, 'text' => 'Esse pack não está mais disponível.']);
            return;
        }
        $comprar ? gerarPixPackBasico($token, $id_chat, $dados_basico, $pack) : enviarPreviaPackBasico($token, $id_chat, $pack);
        return;
    }
    if ($texto === 'basico::previa') {
        global $pdo, $bot;
        enviarPreviasBasico($pdo, $token, $bot, (string) $id_chat, $dados_basico);
        return;
    }
    if (strpos($texto, 'basico::plano::') === 0) {
        $id_plano_escolhido = substr($texto, strlen('basico::plano::'));
        if (!localizarPlanoBasico($planos, $id_plano_escolhido)) {
            requisicaoTelegram($token, 'sendMessage', ['chat_id' => $id_chat, 'text' => 'Esse plano não existe mais. Toque em "Ver Planos" de novo.']);
            return;
        }
        avancarOfertasBasico($token, $id_chat, $dados_basico, 'up', $id_plano_escolhido);
        return;
    }
    if (strpos($texto, 'basico::bs::') === 0) {
        // Order Bump da sequência (Pix não pago): "basico::bs::<id do plano>::<posição do bump>::1|0". Aceitar gera um Pix novo com plano + extra e encerra a sequência.
        $partes = explode('::', substr($texto, strlen('basico::bs::')));
        if (($partes[2] ?? '') !== '1') {
            requisicaoTelegram($token, 'sendMessage', ['chat_id' => $id_chat, 'text' => 'Tudo bem! 🙌']);
            return;
        }
        $id_plano_bump = (string) ($partes[0] ?? '');
        $plano_bump = localizarPlanoBasico($planos, $id_plano_bump);
        $item_bump = orderBumpDepoisDoPixBasico($dados_basico) ? (itensOrderBumpBasico($dados_basico)[(int) ($partes[1] ?? -1)] ?? null) : null;
        if (!$plano_bump || !$item_bump || planoRecorrenteBasico($plano_bump)) {
            requisicaoTelegram($token, 'sendMessage', ['chat_id' => $id_chat, 'text' => 'Essa oferta não está mais disponível.']);
            return;
        }
        garantirConexaoBanco();
        cancelarOrderBumpsDoLeadBasico($GLOBALS['pdo'], (int) $GLOBALS['bot']['id'], (string) $id_chat);
        gerarPixBasico($token, $id_chat, $dados_basico, $id_plano_bump, 'o', true, $item_bump, false);
        return;
    }
    if (strpos($texto, 'basico::pos::') === 0) {
        // Oferta DEPOIS do pagamento: "basico::pos::up::<id do plano de destino>::1|0" (upsell) ou "basico::pos::bp::0::1|0" (order bump).
        $partes = explode('::', substr($texto, strlen('basico::pos::')));
        if (($partes[2] ?? '') === '1') {
            gerarPixOfertaPosBasico($token, $id_chat, $dados_basico, (string) ($partes[0] ?? ''), (string) ($partes[1] ?? ''));
        } else {
            requisicaoTelegram($token, 'sendMessage', ['chat_id' => $id_chat, 'text' => 'Tudo bem! Obrigado pela sua compra. 🙌']);
        }
        return;
    }
    if (strpos($texto, 'basico::of::') === 0) {
        $partes = explode('::', substr($texto, strlen('basico::of::')));
        $etapa = $partes[0] ?? '';
        $id_plano = $partes[1] ?? '';
        if ($etapa === 'up') {
            $aceitou = ($partes[2] ?? '') === '1';
            $aceitou ? avancarOfertasBasico($token, $id_chat, $dados_basico, 'bp', $id_plano, 'u')
                     : avancarOfertasBasico($token, $id_chat, $dados_basico, 'dn', $id_plano);
        } elseif ($etapa === 'dn') {
            if (preg_match('/^p(\d+)$/', (string) ($partes[2] ?? ''), $m)) {
                // formato novo: o cliente tocou num dos planos do downsell
                if (!(planosDoDownsellBasico($dados_basico)[(int) $m[1]] ?? null)) {
                    requisicaoTelegram($token, 'sendMessage', ['chat_id' => $id_chat, 'text' => 'Essa opção não está mais disponível. Toque em "Ver Planos" de novo.']);
                    return;
                }
                cancelarDownsellsDoLeadBasico($GLOBALS['pdo'], (int) $GLOBALS['bot']['id'], (string) $id_chat);   // ele respondeu à oferta: encerra as repetições do temporizador
                avancarOfertasBasico($token, $id_chat, $dados_basico, 'bp', $id_plano, 'd' . (int) $m[1]);
            } else {
                // formato antigo (botões Aceitar/Recusar de mensagens já enviadas)
                $aceitou = ($partes[2] ?? '') === '1';
                avancarOfertasBasico($token, $id_chat, $dados_basico, 'bp', $id_plano, $aceitou ? 'd' : 'o');
            }
        } elseif ($etapa === 'bp') {
            $variante = preg_match('/^(o|u|d\d*)$/', (string) ($partes[2] ?? '')) ? $partes[2] : 'o';
            gerarPixBasico($token, $id_chat, $dados_basico, $id_plano, $variante, ($partes[3] ?? '') === '1');
        }
        return;
    }
}
/** Continua o fluxo do bot a partir de $id_partida (a lógica de verdade fica em funcoes/execucao_fluxo.php). */
function caminharFluxoAPartirDe(string $token, $id_chat, array $dados_fluxo, ?string $id_partida): void {
    global $bot;
    executarFluxoDesde($token, $id_chat, (int) $bot['id'], (int) ($bot['id_fluxo_conectado'] ?? 0), $dados_fluxo, $id_partida);
}
if (!empty($bot['id_fluxo_conectado'])) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM fluxos WHERE id = ?");
        $stmt->execute([$bot['id_fluxo_conectado']]);
        $fluxo = $stmt->fetch();
        if ($fluxo && ($fluxo['modo'] ?? 'avancado') === 'basico') {
            $dados_basico = json_decode($fluxo['dados_fluxograma'] ?? '{}', true);
            executarFluxoBasico($token, $id_chat, is_array($dados_basico) ? $dados_basico : [], $texto);
        } elseif ($fluxo) {
            $dados_fluxo = json_decode($fluxo['dados_fluxograma'] ?? '{}', true);
            $dados_fluxo = is_array($dados_fluxo) ? $dados_fluxo : ['operators' => [], 'links' => []];
            if ($texto === '/start') {
                cancelarContinuacoesDoLead($pdo, (int) $bot['id'], (string) $id_chat);
                limparOfertasDoLead($pdo, (int) $bot['id'], (string) $id_chat);
                caminharFluxoAPartirDe($token, $id_chat, $dados_fluxo, buscarProximoDoInicio($dados_fluxo));
            } else {
                // Tenta identificar resposta a botões (lógica sem estado)
                foreach ($dados_fluxo['operators'] as $op_id => $op) {
                    if (($op['properties']['type'] ?? '') === 'botoes') {
                         // Cada item pode ser texto puro (fluxo antigo) ou ['texto'=>..,'cor'=>..]
                         // desde que o bloco "Botões" ganhou cor por botão -- normaliza antes de
                         // comparar, senão um botão colorido nunca bateria aqui.
                         $botoes = array_map('textoBotaoBloco', $op['properties']['botoes'] ?? []);
                         if (in_array($texto, $botoes)) {
                             $index = array_search($texto, $botoes);
                             $proximo_id = obterProximoNoPorConector($dados_fluxo['links'], $op_id, 'output_' . $index);
                             if ($proximo_id) {
                                caminharFluxoAPartirDe($token, $id_chat, $dados_fluxo, $proximo_id);
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
