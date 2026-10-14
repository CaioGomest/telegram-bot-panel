<?php
require_once __DIR__ . '/facebook.php';
require_once __DIR__ . '/tiktok.php';
require_once __DIR__ . '/utmfy.php';
require_once __DIR__ . '/criptografia.php';
require_once __DIR__ . '/../conexao.php';

/** Troca por "***" qualquer segredo que apareça no payload antes de ir pro log (o payload do Facebook leva o access_token). */
function mascararSegredosTraqueamento($valor)
{
    if (!is_array($valor)) {
        return $valor;
    }
    foreach ($valor as $chave => $conteudo) {
        if (is_string($chave) && preg_match('/token|secret|senha|password/i', $chave) && $conteudo !== null && $conteudo !== '') {
            $valor[$chave] = '***';
        } else {
            $valor[$chave] = mascararSegredosTraqueamento($conteudo);
        }
    }
    return $valor;
}

function logTraqueamento($plataforma, $evento, $payload, $resposta, $codigo_http) {
    $payload = mascararSegredosTraqueamento($payload);
    $codigo_http = $codigo_http ?? 0;
    $diretorio_logs = __DIR__ . '/../logs';
    if (!is_dir($diretorio_logs)) {
        mkdir($diretorio_logs, 0777, true);
    }
    
    $arquivo_log = $diretorio_logs . '/traqueamento.log';
    $data_hora = date('Y-m-d H:i:s');
    
    $log = "[$data_hora] PLATAFORMA: $plataforma | EVENTO: $evento | HTTP_CODE: $codigo_http\n";
    $log .= "PAYLOAD ENVIADO: " . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    $log .= "RESPOSTA RECEBIDA: " . (is_string($resposta) ? $resposta : json_encode($resposta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) . "\n";
    $log .= str_repeat("-", 80) . "\n\n";
    
    file_put_contents($arquivo_log, $log, FILE_APPEND);
}

/**
 * Roda o envio de UMA plataforma sem nunca derrubar quem chamou (webhook de pagamento, cron,
 * bloco de Pix): exceção vira resultado de falha, o retorno sempre tem sucesso/resposta/codigo/payload
 * e tudo (inclusive o que falhou antes de chegar na API) vai pro log.
 */
function executarEnvioTraqueamento(string $plataforma, string $evento, callable $envio): array
{
    try {
        $r = $envio();
    } catch (Throwable $e) {
        $r = ['sucesso' => false, 'erro' => 'Exceção: ' . $e->getMessage()];
    }
    if (!is_array($r)) {
        $r = ['sucesso' => false, 'erro' => 'Retorno inválido do envio'];
    }
    $r += ['sucesso' => false, 'resposta' => isset($r['erro']) ? ['erro' => $r['erro']] : null, 'codigo' => 0, 'payload' => []];
    try {
        logTraqueamento($plataforma, $evento, $r['payload'], $r['resposta'], $r['codigo']);
    } catch (Throwable $e) {
        error_log('[traqueamento] falha ao gravar log: ' . $e->getMessage());
    }
    return $r;
}

function enviarEventosTraqueamento($id_usuario, $evento, $dados, $user_data = []) {
    global $pdo;

    $stmt = $pdo->prepare("SELECT * FROM usuarios_traqueamento WHERE id_usuario = ?");
    $stmt->execute([$id_usuario]);
    $config = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$config) {
        return ['sucesso' => false, 'erro' => 'Configuração não encontrada'];
    }

    // facebook_access_token/tiktok_access_token/utmfy_token são salvos cifrados
    // (criptografarSegredo(), mesmo mecanismo do client_secret dos gateways) desde que
    // traqueamento.php passou a mascará-los na tela -- descriptografarSegredo() também
    // devolve de volta, sem alteração, qualquer valor legado salvo em texto puro antes
    // dessa mudança.
    $config['facebook_access_token'] = descriptografarSegredo($config['facebook_access_token'] ?? '');
    $config['tiktok_access_token'] = descriptografarSegredo($config['tiktok_access_token'] ?? '');
    $config['utmfy_token'] = descriptografarSegredo($config['utmfy_token'] ?? '');

    $resultados = [];

    if (!empty($config['facebook_pixel_id']) && !empty($config['facebook_access_token']) && !empty($config['facebook_ativo'])) {
        $resultados['facebook'] = executarEnvioTraqueamento('Facebook', $evento, fn() => enviarEventoFacebook(
            $config['facebook_pixel_id'],
            $config['facebook_access_token'],
            $evento,
            $dados,
            $user_data
        ));
    }

    // ===== TikTok Ads: DESATIVADO =====
    // Comentado em 19/09/2026 a pedido do Caio: ninguém tem conta de TikTok Ads pra
    // validar se o evento chega e se a atribuição funciona. Código que dispara pra uma
    // API de terceiro sem nunca ter sido conferido é pior que código ausente -- "funciona"
    // (devolve HTTP 200) sem ninguém saber se o dado está certo do outro lado.
    //
    // funcoes/tiktok.php continua no repositório, intacto. Pra religar: descomentar este
    // bloco e a seção 'tiktok_fields' em traqueamento.php.
    //
    // if (!empty($config['tiktok_pixel_id']) && !empty($config['tiktok_access_token']) && !empty($config['tiktok_ativo'])) {
    //     $resultados['tiktok'] = enviarEventoTikTok(
    //         $config['tiktok_pixel_id'],
    //         $config['tiktok_access_token'],
    //         $evento,
    //         $dados,
    //         $user_data
    //     );
    //     logTraqueamento('TikTok', $evento, $resultados['tiktok']['payload'] ?? [], $resultados['tiktok']['resposta'], $resultados['tiktok']['codigo']);
    // }

    if (!empty($config['utmfy_token']) && !empty($config['utmfy_ativo'])) {
        $resultados['utmfy'] = executarEnvioTraqueamento('UTMfy', $evento, fn() => enviarEventoUtmfy(
            $config['utmfy_token'],
            $evento,
            $dados,
            $user_data
        ));
    }

    return $resultados;
}

/**
 * Monta o $user_data dos eventos de traqueamento a partir do que o sistema realmente sabe
 * sobre o lead.
 *
 * Existe porque os pontos de chamada (webhook.php x2, webhook_omegapayments.php) montavam
 * esse array na mão e só passavam id_telegram -- então Facebook e UTMify recebiam evento sem
 * nenhum dado de correspondência e a atribuição não acontecia, mesmo com a API devolvendo
 * 200. Ver anotacoes/OBSERVACOES.md.
 *
 * O que dá pra enviar hoje: nome, telefone (quando o lead informou) e a origem do link de
 * rastreamento. E-mail e IP o Telegram não fornece -- ficam de fora em vez de serem
 * inventados.
 */
/**
 * vendas.nome_produto guarda o nome do plano/produto no momento da venda: sem ele, o evento de
 * compra (disparado por webhook/cron, que só enxergam a linha de vendas) saía como "Produto" e o
 * pedido pago atualizava na UTMify o nome que o Pix gerado já tinha mandado certo.
 * Cria a coluna na primeira necessidade (mesmo padrão de garantirColunaFotoPerfil) e nunca
 * derruba a venda se não conseguir: nesse caso devolve false e o nome simplesmente não é gravado.
 */
function vendasTemNomeProduto(PDO $pdo): bool
{
    static $tem = null;
    if ($tem !== null) {
        return $tem;
    }
    try {
        $tem = (bool) $pdo->query("SHOW COLUMNS FROM vendas LIKE 'nome_produto'")->fetchColumn();
        if (!$tem) {
            $pdo->exec("ALTER TABLE vendas ADD COLUMN nome_produto VARCHAR(150) DEFAULT NULL");
            $tem = true;
        }
    } catch (Throwable $e) {
        error_log('[traqueamento] coluna vendas.nome_produto indisponível: ' . $e->getMessage());
        $tem = false;
    }
    return $tem;
}

function registrarNomeProdutoVenda(PDO $pdo, int $id_venda, ?string $nome): void
{
    if ($id_venda <= 0 || $nome === null || trim($nome) === '' || !vendasTemNomeProduto($pdo)) {
        return;
    }
    try {
        $pdo->prepare("UPDATE vendas SET nome_produto = ? WHERE id = ?")->execute([mb_substr(trim($nome), 0, 150), $id_venda]);
    } catch (Throwable $e) {
        error_log('[traqueamento] falha ao gravar nome_produto: ' . $e->getMessage());
    }
}

/** Venda de renovação herda o nome do produto da venda que a originou. */
function herdarNomeProdutoVenda(PDO $pdo, string $transacao_nova, int $id_venda_origem): void
{
    if ($transacao_nova === '' || $id_venda_origem <= 0 || !vendasTemNomeProduto($pdo)) {
        return;
    }
    try {
        $pdo->prepare("UPDATE vendas f JOIN vendas p ON p.id = ? SET f.nome_produto = p.nome_produto WHERE f.transacao_id = ?")->execute([$id_venda_origem, $transacao_nova]);
    } catch (Throwable $e) {
        error_log('[traqueamento] falha ao herdar nome_produto: ' . $e->getMessage());
    }
}

function montarUserDataTraqueamento(PDO $pdo, $id_telegram, $bot_id): array
{
    $user_data = ['id_telegram' => $id_telegram];

    try {
        $stmt = $pdo->prepare("SELECT nome, telefone, origem_rastreio FROM leads WHERE id_telegram = ? AND bot_id = ? LIMIT 1");
        $stmt->execute([$id_telegram, $bot_id]);
        $lead = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[traqueamento] falha ao buscar lead: ' . $e->getMessage());
        return $user_data;
    }

    if (!$lead) {
        return $user_data;
    }

    if (!empty($lead['nome'])) {
        $user_data['first_name'] = $lead['nome'];
    }
    if (!empty($lead['telefone'])) {
        $user_data['telefone'] = $lead['telefone'];
    }

    // A origem é o identificador do link de rastreamento que trouxe o lead. Vira utm_source
    // e utm_campaign: é a única informação de campanha que este sistema tem, e sem ela a
    // UTMify recebe pedido sem origem -- que é justamente o que ela não precisa.
    if (!empty($lead['origem_rastreio'])) {
        $user_data['utm_source']   = 'telegram';
        $user_data['utm_medium']   = 'bot';
        $user_data['utm_campaign'] = $lead['origem_rastreio'];
    }

    return $user_data;
}
