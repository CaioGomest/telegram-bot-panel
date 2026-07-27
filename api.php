<?php

declare(strict_types=1);

require_once 'conexao.php';
require_once 'funcoes/usuario.php';
require_once 'funcoes/log.php';

if (!usuarioLogado()) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(401);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Não autorizado. Faça login para continuar.']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

const DIRETORIO_UPLOADS = __DIR__ . '/uploads';

if (!is_dir(DIRETORIO_UPLOADS)) {
    mkdir(DIRETORIO_UPLOADS, 0777, true);
}

function responder(bool $sucesso, array $dados = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode(array_merge(['sucesso' => $sucesso], $dados), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function dadosRequisicao(): array
{
    $tipo_conteudo = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($tipo_conteudo, 'application/json') !== false) {
        $bruto = file_get_contents('php://input');
        $decodificado = json_decode($bruto ?: '{}', true);
        return is_array($decodificado) ? $decodificado : [];
    }
    return $_POST;
}

function requisicaoTelegram(string $token, string $metodo, array $parametros = [], array $arquivos = []): array
{
    $url = 'https://api.telegram.org/bot' . $token . '/' . $metodo;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    if (!empty($arquivos)) {
        $arquivo_adicionado = false;
        foreach ($arquivos as $campo => $caminho) {
            if (!file_exists($caminho)) {
                continue;
            }
            $mime = function_exists('mime_content_type') ? (mime_content_type($caminho) ?: 'application/octet-stream') : 'application/octet-stream';
            $parametros[$campo] = new CURLFile($caminho, $mime, basename($caminho));
            $arquivo_adicionado = true;
        }
        if (!$arquivo_adicionado) {
            return [
                'ok' => false,
                'description' => 'Arquivo de upload não encontrado.',
                'http_code' => 0,
            ];
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $parametros);
    } else {
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($parametros));
    }

    $resposta = curl_exec($ch);
    $erro_curl = curl_error($ch);
    $codigo_http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resposta === false) {
        return [
            'ok' => false,
            'description' => 'Falha de comunicação com a API do Telegram: ' . $erro_curl,
            'http_code' => $codigo_http,
        ];
    }

    $decodificado = json_decode($resposta, true);
    if (!is_array($decodificado)) {
        return [
            'ok' => false,
            'description' => 'Resposta inválida da API do Telegram.',
            'http_code' => $codigo_http,
            'raw' => $resposta,
        ];
    }

    $decodificado['http_code'] = $codigo_http;
    return $decodificado;
}

function sanitizarTexto(?string $valor, int $tamanho_maximo = 0): string
{
    $valor = trim((string) $valor);
    if ($tamanho_maximo > 0) {
        $valor = mb_substr($valor, 0, $tamanho_maximo);
    }
    return $valor;
}

function obterBotComInfoLive(string $token): array
{
    $eu = requisicaoTelegram($token, 'getMe');
    if (!($eu['ok'] ?? false)) {
        return $eu;
    }

    $desc = requisicaoTelegram($token, 'getMyDescription');
    $curta = requisicaoTelegram($token, 'getMyShortDescription');

    return [
        'ok' => true,
        'result' => [
            'id' => $eu['result']['id'] ?? null,
            'username' => $eu['result']['username'] ?? '',
            'first_name' => $eu['result']['first_name'] ?? '',
            'can_join_groups' => $eu['result']['can_join_groups'] ?? null,
            'can_read_all_group_messages' => $eu['result']['can_read_all_group_messages'] ?? null,
            'supports_inline_queries' => $eu['result']['supports_inline_queries'] ?? null,
            'description' => $desc['result']['description'] ?? '',
            'short_description' => $curta['result']['short_description'] ?? '',
        ],
    ];
}

$usuario_id = (int)$_SESSION['usuario_id'];

$acao = $_GET['action'] ?? $_POST['action'] ?? '';
$entrada = dadosRequisicao();

try {
    switch ($acao) {
        case 'listar_menus':
            $stmt = $pdo->query("SELECT * FROM menus WHERE ativo = 1 ORDER BY ordem ASC");
            $menus = $stmt->fetchAll();
            responder(true, ['menus' => $menus]);
            break;

        case 'listar_grupos_usuario':
            $stmt = $pdo->prepare("
                SELECT bg.*, b.nome_usuario as nome_bot
                FROM bot_grupos bg
                JOIN bots b ON bg.bot_id = b.id
                WHERE b.id_usuario = ?
                ORDER BY bg.titulo ASC
            ");
            $stmt->execute([$usuario_id]);
            $grupos = $stmt->fetchAll();
            responder(true, ['grupos' => $grupos]);
            break;

        case 'gateway_info':
            // Basta um gateway ativo ser PJ para liberar a opção de recorrente.
            // Sem linha própria em usuarios_gateways (ex: InfoPago usando credenciais
            // compartilhadas do admin), tipo_conta é considerado 'pj' por padrão — mesmo
            // fallback usado em getUserGateways() (funcoes/gateways.php).
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM gateways g
                LEFT JOIN usuarios_gateways ug ON ug.id_gateway = g.id AND ug.id_usuario = ?
                WHERE g.ativo = 1
                  AND (ug.ativo = 1 OR ug.id IS NULL)
                  AND COALESCE(ug.tipo_conta, 'pj') = 'pj'
            ");
            $stmt->execute([$usuario_id]);
            $suporta_recorrente = (int)$stmt->fetchColumn() > 0;
            responder(true, ['suporta_recorrente' => $suporta_recorrente]);
            break;

        case 'listar_fluxos':
            $stmt = $pdo->prepare("SELECT * FROM fluxos WHERE id_usuario = ? ORDER BY atualizado_em DESC");
            $stmt->execute([$usuario_id]);
            $fluxos = $stmt->fetchAll();

            foreach ($fluxos as &$f) {
                $f['dados_fluxograma'] = json_decode($f['dados_fluxograma'] ?? '{}', true);
            }
            responder(true, ['fluxos' => $fluxos]);
            break;

        case 'obter_fluxo':
            $id = (int) ($_GET['id'] ?? 0);
            if ($id <= 0) {
                responder(false, ['mensagem' => 'ID do fluxo inválido.'], 422);
            }
            $stmt = $pdo->prepare("SELECT * FROM fluxos WHERE id = ? AND id_usuario = ?");
            $stmt->execute([$id, $usuario_id]);
            $fluxo = $stmt->fetch();

            if (!$fluxo) {
                responder(false, ['mensagem' => 'Fluxo não encontrado.'], 404);
            }
            $fluxo['dados_fluxograma'] = json_decode($fluxo['dados_fluxograma'] ?? '{}', true);
            responder(true, ['fluxo' => $fluxo]);
            break;

        case 'exportar_fluxo':
            $id = (int) ($_GET['id'] ?? 0);
            if ($id <= 0) {
                responder(false, ['mensagem' => 'ID do fluxo inválido.'], 422);
            }
            $stmt = $pdo->prepare("SELECT id, nome, descricao, link_suporte, dados_fluxograma, atualizado_em FROM fluxos WHERE id = ? AND id_usuario = ?");
            $stmt->execute([$id, $usuario_id]);
            $fluxo = $stmt->fetch();
            if (!$fluxo) {
                responder(false, ['mensagem' => 'Fluxo não encontrado.'], 404);
            }
            $exportado = [
                'versao' => 1,
                'id_origem' => (int)($fluxo['id'] ?? 0),
                'nome' => (string)($fluxo['nome'] ?? ''),
                'descricao' => (string)($fluxo['descricao'] ?? ''),
                'link_suporte' => (string)($fluxo['link_suporte'] ?? ''),
                'dados_fluxograma' => json_decode($fluxo['dados_fluxograma'] ?? '{}', true),
                'atualizado_em' => $fluxo['atualizado_em'] ?? null,
            ];
            header('Content-Disposition: attachment; filename="fluxo_' . ($fluxo['id'] ?? $id) . '.json"');
            responder(true, ['fluxo' => $exportado]);
            break;

        case 'importar_fluxo':
            $json = $entrada;
            if (!is_array($json)) {
                responder(false, ['mensagem' => 'JSON inválido.'], 422);
            }
            $nome = sanitizarTexto($json['nome'] ?? 'Fluxo importado', 120);
            $descricao = sanitizarTexto($json['descricao'] ?? '', 500);
            $link_suporte = sanitizarTexto($json['link_suporte'] ?? '', 255);

            // Aceita dados_fluxograma_b64 para contornar WAF/ModSecurity que bloqueiam o JSON do diagrama (403).
            if (!empty($json['dados_fluxograma_b64']) && is_string($json['dados_fluxograma_b64'])) {
                $bruto_b64 = base64_decode($json['dados_fluxograma_b64'], true);
                if ($bruto_b64 === false || $bruto_b64 === '') {
                    responder(false, ['mensagem' => 'dados_fluxograma (codificação) inválidos.'], 422);
                }
                $dados = json_decode($bruto_b64, true);
            } else {
                $dados = $json['dados_fluxograma'] ?? null;
            }

            if (!is_array($dados) || !isset($dados['operators']) || !isset($dados['links'])) {
                responder(false, ['mensagem' => 'Estrutura do fluxo inválida.'], 422);
            }
            $stmt = $pdo->prepare("INSERT INTO fluxos (id_usuario, nome, descricao, link_suporte, dados_fluxograma) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$usuario_id, $nome, $descricao, $link_suporte, json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
            $novo_id = (int)$pdo->lastInsertId();
            $stmt = $pdo->prepare("SELECT * FROM fluxos WHERE id = ?");
            $stmt->execute([$novo_id]);
            $fluxo = $stmt->fetch();
            $fluxo['dados_fluxograma'] = json_decode($fluxo['dados_fluxograma'] ?? '{}', true);
            responder(true, ['mensagem' => 'Fluxo importado com sucesso.', 'fluxo' => $fluxo]);
            break;

        case 'salvar_fluxo':
            $id_fluxo = (int) ($entrada['id'] ?? 0);
            $nome = sanitizarTexto($entrada['nome'] ?? 'Novo fluxo', 120);
            $descricao = sanitizarTexto($entrada['descricao'] ?? '', 500);
            $link_suporte = sanitizarTexto($entrada['link_suporte'] ?? '', 255);

            // Aceita dados_fluxograma_b64 para contornar WAF/ModSecurity que bloqueiam o JSON do diagrama (403).
            $dados_grafico = null;
            if (!empty($entrada['dados_fluxograma_b64']) && is_string($entrada['dados_fluxograma_b64'])) {
                $bruto_b64 = base64_decode($entrada['dados_fluxograma_b64'], true);
                if ($bruto_b64 === false || $bruto_b64 === '') {
                    responder(false, ['mensagem' => 'dados_fluxograma (codificação) inválidos.'], 422);
                }
                $dados_grafico = json_decode($bruto_b64, true);
            } else {
                $dados_grafico = $entrada['dados_fluxograma'] ?? ['operators' => [], 'links' => []];
            }

            if (!is_array($dados_grafico)) {
                responder(false, ['mensagem' => 'dados_fluxograma inválido.'], 422);
            }
            $json_grafico = json_encode($dados_grafico, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if ($id_fluxo > 0) {
                $stmt = $pdo->prepare("UPDATE fluxos SET nome = ?, descricao = ?, link_suporte = ?, dados_fluxograma = ? WHERE id = ? AND id_usuario = ?");
                $stmt->execute([$nome, $descricao, $link_suporte, $json_grafico, $id_fluxo, $usuario_id]);

                $stmt = $pdo->prepare("SELECT * FROM fluxos WHERE id = ?");
                $stmt->execute([$id_fluxo]);
                $fluxo = $stmt->fetch();

                registrarAtividade($usuario_id, 'sistema', 'Fluxo', "Atualizou o fluxo: $nome");
            } else {
                $stmt = $pdo->prepare("INSERT INTO fluxos (id_usuario, nome, descricao, link_suporte, dados_fluxograma) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$usuario_id, $nome, $descricao, $link_suporte, $json_grafico]);
                $novo_id = $pdo->lastInsertId();

                $stmt = $pdo->prepare("SELECT * FROM fluxos WHERE id = ?");
                $stmt->execute([$novo_id]);
                $fluxo = $stmt->fetch();

                registrarAtividade($usuario_id, 'sistema', 'Fluxo', "Criou novo fluxo: $nome");
            }
            
            $fluxo['dados_fluxograma'] = json_decode($fluxo['dados_fluxograma'] ?? '{}', true);
            responder(true, ['mensagem' => 'Fluxo salvo com sucesso.', 'fluxo' => $fluxo]);
            break;

        case 'excluir_fluxo':
            $id = (int) ($entrada['id'] ?? 0);
            if ($id <= 0) {
                responder(false, ['mensagem' => 'ID do fluxo inválido.'], 422);
            }

            // Busca o nome antes de excluir, pois depois do DELETE não dá mais para obtê-lo para o log
            $stmt = $pdo->prepare("SELECT nome FROM fluxos WHERE id = ? AND id_usuario = ?");
            $stmt->execute([$id, $usuario_id]);
            $fluxo_alvo = $stmt->fetch();
            $nome_fluxo = $fluxo_alvo['nome'] ?? "ID $id";

            $stmt = $pdo->prepare("DELETE FROM fluxos WHERE id = ? AND id_usuario = ?");
            $stmt->execute([$id, $usuario_id]);
            
            if ($stmt->rowCount() === 0) {
                responder(false, ['mensagem' => 'Fluxo não encontrado ou já excluído.'], 404);
            }

            registrarAtividade($usuario_id, 'sistema', 'Fluxo', "Excluiu o fluxo: $nome_fluxo");

            // O ON DELETE SET NULL na FK bots.id_fluxo_conectado já cuida de desvincular os bots
            responder(true, ['mensagem' => 'Fluxo excluído com sucesso.']);
            break;

        case 'listar_bots':
            $stmt = $pdo->prepare("SELECT * FROM bots WHERE id_usuario = ? ORDER BY atualizado_em DESC");
            $stmt->execute([$usuario_id]);
            $bots = $stmt->fetchAll();
            responder(true, ['bots' => $bots]);
            break;

        case 'obter_bot':
            $id = (int) ($_GET['id'] ?? 0);
            if ($id <= 0) {
                responder(false, ['mensagem' => 'ID do bot inválido.'], 422);
            }
            $stmt = $pdo->prepare("SELECT * FROM bots WHERE id = ? AND id_usuario = ?");
            $stmt->execute([$id, $usuario_id]);
            $bot = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$bot) {
                responder(false, ['mensagem' => 'Bot não encontrado.'], 404);
            }

            // Não consulta o webhook ao vivo na API do Telegram aqui (deixaria o carregamento lento);
            // para edição rápida, confia nos dados já salvos no banco.
            responder(true, ['bot' => $bot]);
            break;

        case 'testar_bot':
            $token = sanitizarTexto($entrada['token'] ?? '');
            if ($token === '') {
                responder(false, ['mensagem' => 'Informe o token do bot.'], 422);
            }

            $vivo = obterBotComInfoLive($token);
            if (!($vivo['ok'] ?? false)) {
                responder(false, ['mensagem' => $vivo['description'] ?? 'Não foi possível validar o token.'], 400);
            }

            responder(true, ['mensagem' => 'Bot conectado com sucesso.', 'info_bot' => $vivo['result']]);
            break;

        case 'salvar_bot':
            $token = sanitizarTexto($entrada['token'] ?? '');
            $id_fluxo = !empty($entrada['id_fluxo_conectado']) ? (int)$entrada['id_fluxo_conectado'] : null;
            $id_bot = (int) ($entrada['id'] ?? 0);

            if ($token === '') {
                responder(false, ['mensagem' => 'Informe o token do bot.'], 422);
            }

            $vivo = obterBotComInfoLive($token);
            if (!($vivo['ok'] ?? false)) {
                responder(false, ['mensagem' => $vivo['description'] ?? 'Token inválido.'], 400);
            }

            $bot_existente = null;
            if ($id_bot > 0) {
                $stmt = $pdo->prepare("SELECT * FROM bots WHERE id = ? AND id_usuario = ?");
                $stmt->execute([$id_bot, $usuario_id]);
                $bot_existente = $stmt->fetch();
            }
            if (!$bot_existente) {
                $stmt = $pdo->prepare("SELECT * FROM bots WHERE token = ? AND id_usuario = ?");
                $stmt->execute([$token, $usuario_id]);
                $bot_existente = $stmt->fetch();
            }

            $id_bot_telegram = $vivo['result']['id'] ?? null;
            $nome_usuario = $vivo['result']['username'] ?? '';
            $primeiro_nome = $vivo['result']['first_name'] ?? '';
            $descricao = $vivo['result']['description'] ?? '';
            $descricao_curta = $vivo['result']['short_description'] ?? '';

            $fotos = requisicaoTelegram($token, 'getUserProfilePhotos', ['user_id' => $vivo['result']['id'], 'limit' => 1]);
            $caminho_foto = $bot_existente['caminho_foto'] ?? '';
            
            if (($fotos['ok'] ?? false) && !empty($fotos['result']['photos'][0][0]['file_id'])) {
                $file_id = $fotos['result']['photos'][0][0]['file_id'];
                $file_info = requisicaoTelegram($token, 'getFile', ['file_id' => $file_id]);
                
                if (($file_info['ok'] ?? false) && !empty($file_info['result']['file_path'])) {
                    $url_foto = 'https://api.telegram.org/file/bot' . $token . '/' . $file_info['result']['file_path'];

                    $extensao = pathinfo($file_info['result']['file_path'], PATHINFO_EXTENSION) ?: 'jpg';
                    $nome_arquivo = 'bot_avatar_' . $id_bot_telegram . '_' . time() . '.' . $extensao;
                    $caminho_local = DIRETORIO_UPLOADS . '/' . $nome_arquivo;
                    
                    $conteudo_foto = @file_get_contents($url_foto);
                    if ($conteudo_foto) {
                        file_put_contents($caminho_local, $conteudo_foto);
                        $caminho_foto = 'uploads/' . $nome_arquivo;
                    }
                }
            }

            if ($bot_existente) {
                $id_bot = $bot_existente['id'];
                $stmt = $pdo->prepare("UPDATE bots SET 
                    token = ?, id_bot_telegram = ?, nome_usuario = ?, primeiro_nome = ?, 
                    descricao = ?, descricao_curta = ?, id_fluxo_conectado = ?, caminho_foto = ? 
                    WHERE id = ?");
                $stmt->execute([
                    $token, $id_bot_telegram, $nome_usuario, $primeiro_nome, 
                    $descricao, $descricao_curta, $id_fluxo, $caminho_foto,
                    $id_bot
                ]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO bots
                    (id_usuario, token, id_bot_telegram, nome_usuario, primeiro_nome, descricao, descricao_curta, id_fluxo_conectado, caminho_foto) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $usuario_id, $token, $id_bot_telegram, $nome_usuario, $primeiro_nome, 
                    $descricao, $descricao_curta, $id_fluxo, $caminho_foto
                ]);
                $id_bot = $pdo->lastInsertId();
            }

            $esquema = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $caminho_base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
            $url_webhook = $esquema . '://' . $host . $caminho_base . '/webhook.php?token=' . urlencode($token);

            $wh = requisicaoTelegram($token, 'setWebhook', ['url' => $url_webhook]);
            $webhook_definido = (bool) ($wh['ok'] ?? false);

            if ($webhook_definido) {
                $pdo->prepare("UPDATE bots SET url_webhook = ? WHERE id = ?")->execute([$url_webhook, $id_bot]);
            }

            $msg_extra = $webhook_definido ? ' Webhook ativado.' : (' Falha ao ativar webhook: ' . ($wh['description'] ?? 'erro desconhecido') . '.');

            $acao_bot = $bot_existente ? 'Atualizou' : 'Criou';
            registrarAtividade($usuario_id, 'sistema', 'Bot', "$acao_bot o bot @$nome_usuario.");

            $stmt = $pdo->prepare("SELECT * FROM bots WHERE id = ?");
            $stmt->execute([$id_bot]);
            $bot_atualizado = $stmt->fetch();

            responder(true, ['mensagem' => 'Bot salvo com sucesso.' . $msg_extra, 'bot' => $bot_atualizado, 'webhook_definido' => $webhook_definido, 'url_webhook' => $url_webhook]);
            break;

        case 'atualizar_perfil_bot':
            $token = sanitizarTexto($entrada['token'] ?? '');
            $id_bot = (int) ($entrada['id'] ?? 0);
            $nome = sanitizarTexto($entrada['nome'] ?? '', 64);
            $descricao = sanitizarTexto($entrada['descricao'] ?? '', 512);
            $descricao_curta = sanitizarTexto($entrada['descricao_curta'] ?? '', 120);
            $id_fluxo = !empty($entrada['id_fluxo_conectado']) ? (int)$entrada['id_fluxo_conectado'] : null;

            if ($token === '' && $id_bot === 0) {
                responder(false, ['mensagem' => 'Informe o token ou selecione um bot salvo.'], 422);
            }

            $bot = null;
            if ($id_bot > 0) {
                $stmt = $pdo->prepare("SELECT * FROM bots WHERE id = ? AND id_usuario = ?");
                $stmt->execute([$id_bot, $usuario_id]);
                $bot = $stmt->fetch();
            }
            
            if (!$bot && $token !== '') {
                 $stmt = $pdo->prepare("SELECT * FROM bots WHERE token = ? AND id_usuario = ?");
                 $stmt->execute([$token, $usuario_id]);
                 $bot = $stmt->fetch();
            }

            if (!$bot) {
                responder(false, ['mensagem' => 'Bot não encontrado.'], 404);
            }

            $token = $bot['token'];

            $validacao = requisicaoTelegram($token, 'getMe');
            if (!($validacao['ok'] ?? false)) {
                responder(false, ['mensagem' => $validacao['description'] ?? 'Token inválido.'], 400);
            }

            $passos = [];
            $erros = [];

            if ($nome !== '') {
                $resp = requisicaoTelegram($token, 'setMyName', ['name' => $nome]);
                if (!($resp['ok'] ?? false)) {
                    $erros[] = 'Nome: ' . ($resp['description'] ?? 'erro desconhecido');
                } else {
                    $passos[] = 'Nome';
                }
            }

            $resp = requisicaoTelegram($token, 'setMyDescription', ['description' => $descricao]);
            if (!($resp['ok'] ?? false)) {
                $erros[] = 'Descrição: ' . ($resp['description'] ?? 'erro desconhecido');
            } else {
                $passos[] = 'Descrição';
            }

            $resp = requisicaoTelegram($token, 'setMyShortDescription', ['short_description' => $descricao_curta]);
            if (!($resp['ok'] ?? false)) {
                $erros[] = 'Descrição Curta: ' . ($resp['description'] ?? 'erro desconhecido');
            } else {
                $passos[] = 'Descrição Curta';
            }

            $caminho_foto = $bot['caminho_foto'];
            if (!empty($_FILES['photo']['tmp_name'])) {
                $tmp = $_FILES['photo']['tmp_name'];
                $tamanho = (int)($_FILES['photo']['size'] ?? 0);
                if ($tamanho <= 0 || !@getimagesize($tmp)) {
                    $erros[] = 'Foto: Arquivo inválido ou vazio.';
                } else {
                    $extensao = strtolower(pathinfo($_FILES['photo']['name'] ?? 'photo.jpg', PATHINFO_EXTENSION));
                    if (!in_array($extensao, ['jpg', 'jpeg', 'png'], true)) {
                        $extensao = 'jpg';
                    }
                    $caminho_local = DIRETORIO_UPLOADS . '/' . uniqid('bot_photo_', true) . '.' . $extensao;
                    if (!move_uploaded_file($tmp, $caminho_local) || !file_exists($caminho_local)) {
                        $erros[] = 'Foto: Falha ao salvar arquivo local.';
                    } else {
                        $caminho_foto = 'uploads/' . basename($caminho_local);
                        $passos[] = 'Foto (local)';
                    }
                }
            }

            // Atualiza o DB com os valores do formulário mesmo se alguma chamada ao Telegram tiver falhado
            // acima: assim o usuário não perde o que digitou (fica só desincronizado até tentar de novo).
            $stmt = $pdo->prepare("UPDATE bots SET
                nome_usuario = ?, primeiro_nome = ?,
                descricao = ?, descricao_curta = ?, id_fluxo_conectado = ?,
                caminho_foto = ?
                WHERE id = ?");

            // Nota: nome_usuario (username) não muda via API — usamos $validacao['result']['username'] para garantir.
            // O campo 'nome' do formulário é o 'first_name' (Nome de Exibição), não o username.
            $stmt->execute([
                $validacao['result']['username'] ?? $bot['nome_usuario'],
                $nome,
                $descricao,
                $descricao_curta,
                $id_fluxo,
                $caminho_foto,
                $bot['id']
            ]);

            $stmt = $pdo->prepare("SELECT * FROM bots WHERE id = ?");
            $stmt->execute([$bot['id']]);
            $bot_atualizado = $stmt->fetch();

            $msg_sucesso = !empty($passos) ? 'Itens atualizados: ' . implode(', ', $passos) . '.' : '';
            $msg_erro = !empty($erros) ? 'Erros: ' . implode(' | ', $erros) . '.' : '';
            
            $mensagem_final = trim($msg_sucesso . ' ' . $msg_erro);
            
            // Se houve erros, retornamos sucesso=false para o frontend mostrar alerta vermelho,
            // mas ainda retornamos o bot atualizado.
            if (!empty($erros)) {
                responder(false, ['mensagem' => $mensagem_final, 'bot' => $bot_atualizado]);
            }

            responder(true, ['mensagem' => $mensagem_final ?: 'Perfil atualizado com sucesso!', 'bot' => $bot_atualizado]);
            break;

        case 'upload_imagem_fluxo':
            if (empty($_FILES['image']['tmp_name'])) {
                responder(false, ['mensagem' => 'Nenhuma imagem enviada.'], 422);
            }
            $tmp = $_FILES['image']['tmp_name'];
            $tamanho = (int)($_FILES['image']['size'] ?? 0);
            if ($tamanho <= 0 || !@getimagesize($tmp)) {
                responder(false, ['mensagem' => 'Arquivo de imagem inválido.'], 422);
            }
            $extensao = strtolower(pathinfo($_FILES['image']['name'] ?? 'image.jpg', PATHINFO_EXTENSION));
            if (!in_array($extensao, ['jpg', 'jpeg', 'png'], true)) {
                $extensao = 'jpg';
            }
            $caminho_local = DIRETORIO_UPLOADS . '/' . uniqid('flow_image_', true) . '.' . $extensao;
            if (!move_uploaded_file($tmp, $caminho_local) || !file_exists($caminho_local)) {
                responder(false, ['mensagem' => 'Falha ao salvar a imagem.'], 500);
            }
            $relativo = 'uploads/' . basename($caminho_local);
            responder(true, ['caminho' => $relativo]);
            break;

        case 'upload_video_fluxo':
            if (empty($_FILES['video']['tmp_name'])) {
                responder(false, ['mensagem' => 'Nenhum vídeo enviado.'], 422);
            }
            $tmp = $_FILES['video']['tmp_name'];
            $tamanho = (int)($_FILES['video']['size'] ?? 0);
            if ($tamanho <= 0) {
                responder(false, ['mensagem' => 'Arquivo de vídeo inválido.'], 422);
            }
            $extensao = strtolower(pathinfo($_FILES['video']['name'] ?? 'video.mp4', PATHINFO_EXTENSION));
            // Extensões comuns de vídeo suportadas pelo Telegram
            if (!in_array($extensao, ['mp4', 'avi', 'mov', 'mkv'], true)) {
                responder(false, ['mensagem' => 'Formato de vídeo não suportado (use mp4, avi, mov, mkv).'], 422);
            }
            $caminho_local = DIRETORIO_UPLOADS . '/' . uniqid('flow_video_', true) . '.' . $extensao;
            if (!move_uploaded_file($tmp, $caminho_local) || !file_exists($caminho_local)) {
                responder(false, ['mensagem' => 'Falha ao salvar o vídeo.'], 500);
            }
            $relativo = 'uploads/' . basename($caminho_local);
            responder(true, ['caminho' => $relativo]);
            break;

        case 'upload_audio_fluxo':
            if (empty($_FILES['audio']['tmp_name'])) {
                responder(false, ['mensagem' => 'Nenhum áudio enviado.'], 422);
            }
            $tmp = $_FILES['audio']['tmp_name'];
            $tamanho = (int)($_FILES['audio']['size'] ?? 0);
            if ($tamanho <= 0) {
                responder(false, ['mensagem' => 'Arquivo de áudio inválido.'], 422);
            }
            $extensao = strtolower(pathinfo($_FILES['audio']['name'] ?? 'audio.mp3', PATHINFO_EXTENSION));
            // Extensões comuns de áudio suportadas pelo Telegram
            if (!in_array($extensao, ['mp3', 'ogg', 'wav', 'm4a'], true)) {
                responder(false, ['mensagem' => 'Formato de áudio não suportado (use mp3, ogg, wav, m4a).'], 422);
            }
            $caminho_local = DIRETORIO_UPLOADS . '/' . uniqid('flow_audio_', true) . '.' . $extensao;
            if (!move_uploaded_file($tmp, $caminho_local) || !file_exists($caminho_local)) {
                responder(false, ['mensagem' => 'Falha ao salvar o áudio.'], 500);
            }
            $relativo = 'uploads/' . basename($caminho_local);
            responder(true, ['caminho' => $relativo]);
            break;

        case 'enviar_imagem_teste':
            $token = sanitizarTexto($entrada['token'] ?? '');
            $chat_id = sanitizarTexto($entrada['chat_id'] ?? '');
            $modo = sanitizarTexto($entrada['mode'] ?? 'foto');
            $legenda = sanitizarTexto($entrada['caption'] ?? '', 1024);
            $spoiler = (bool)($entrada['spoiler'] ?? false);
            $caminho = sanitizarTexto($entrada['path'] ?? '');
            if ($token === '' || $chat_id === '') {
                responder(false, ['mensagem' => 'Informe token e chat_id.'], 422);
            }
            $caminho_arquivo = '';
            if (!empty($_FILES['image']['tmp_name'])) {
                $tmp = $_FILES['image']['tmp_name'];
                $extensao = strtolower(pathinfo($_FILES['image']['name'] ?? 'image.jpg', PATHINFO_EXTENSION));
                $caminho_arquivo = DIRETORIO_UPLOADS . '/' . uniqid('flow_image_', true) . '.' . $extensao;
                move_uploaded_file($tmp, $caminho_arquivo);
            } elseif ($caminho !== '') {
                $candidato = $caminho;
                if (strpos($candidato, 'uploads/') === 0) {
                    $candidato = __DIR__ . '/' . str_replace(['..', '\\'], ['', '/'], $candidato);
                }
                if (file_exists($candidato)) {
                    $caminho_arquivo = realpath($candidato) ?: $candidato;
                }
            }
            
            if (!$caminho_arquivo) {
                responder(false, ['mensagem' => 'Imagem não encontrada.'], 422);
            }
            
            $caminho_arquivo = str_replace('\\', '/', $caminho_arquivo);
            $parametros = ['chat_id' => $chat_id];
            if ($legenda !== '') {
                $parametros['caption'] = $legenda;
            }
            if ($modo === 'documento') {
                $resp = requisicaoTelegram($token, 'sendDocument', $parametros, ['document' => $caminho_arquivo]);
            } else {
                if ($spoiler) {
                    $parametros['has_spoiler'] = true;
                }
                $resp = requisicaoTelegram($token, 'sendPhoto', $parametros, ['photo' => $caminho_arquivo]);
            }
            
            if (!($resp['ok'] ?? false)) {
                responder(false, ['mensagem' => $resp['description'] ?? 'Falha no envio', 'http_code' => $resp['http_code'] ?? 0], 400);
            }
            responder(true, ['mensagem' => 'Imagem enviada.', 'resultado_telegram' => $resp['result'] ?? null]);
            break;

        case 'descobrir_chat_id':
            $token = sanitizarTexto($entrada['token'] ?? '');
            if ($token === '') {
                responder(false, ['mensagem' => 'Informe o token do bot.'], 422);
            }
            $atualizacoes = requisicaoTelegram($token, 'getUpdates', []);
            if (!($atualizacoes['ok'] ?? false) || empty($atualizacoes['result'])) {
                responder(false, ['mensagem' => $atualizacoes['description'] ?? 'Nenhuma atualização encontrada.'], 404);
            }
            $id_chat = null;
            foreach (array_reverse($atualizacoes['result']) as $upd) {
                if (!empty($upd['message']['chat']['id'])) {
                    $id_chat = (string)$upd['message']['chat']['id'];
                    break;
                }
            }
            if (!$id_chat) {
                responder(false, ['mensagem' => 'Não foi possível localizar um chat recente.'], 404);
            }
            responder(true, ['id_chat' => $id_chat]);
            break;

        case 'excluir_webhook':
            $token = sanitizarTexto($entrada['token'] ?? '');
            if ($token === '') {
                responder(false, ['mensagem' => 'Informe o token do bot.'], 422);
            }
            $resp = requisicaoTelegram($token, 'deleteWebhook', ['drop_pending_updates' => true]);
            if (!($resp['ok'] ?? false)) {
                responder(false, ['mensagem' => $resp['description'] ?? 'Falha ao remover webhook.'], 400);
            }
            $pdo->prepare("UPDATE bots SET url_webhook = NULL WHERE token = ?")->execute([$token]);

            $stmt = $pdo->prepare("SELECT nome_usuario FROM bots WHERE token = ?");
            $stmt->execute([$token]);
            $bot = $stmt->fetch();
            $nome_bot = $bot['nome_usuario'] ?? 'desconhecido';

            registrarAtividade($usuario_id, 'sistema', 'Bot', "Removeu webhook do bot @$nome_bot.");

            responder(true, ['mensagem' => 'Webhook removido.']);
            break;

        case 'obter_info_webhook':
            $token = sanitizarTexto($entrada['token'] ?? '');
            if ($token === '') {
                responder(false, ['mensagem' => 'Informe o token do bot.'], 422);
            }
            $resp = requisicaoTelegram($token, 'getWebhookInfo');
            if (!($resp['ok'] ?? false)) {
                responder(false, ['mensagem' => 'Falha ao consultar webhook: ' . ($resp['description'] ?? 'erro desconhecido')], 400);
            }
            responder(true, ['info' => $resp['result'] ?? []]);
            break;

        case 'reiniciar_webhook':
            $token = sanitizarTexto($entrada['token'] ?? '');
            $id_bot = (int) ($entrada['id'] ?? 0);
            
            if ($token === '') {
                responder(false, ['mensagem' => 'Informe o token do bot.'], 422);
            }

            $resp_del = requisicaoTelegram($token, 'deleteWebhook', ['drop_pending_updates' => true]);
            if (!($resp_del['ok'] ?? false)) {
                responder(false, ['mensagem' => 'Falha ao limpar webhook: ' . ($resp_del['description'] ?? 'erro desconhecido')], 400);
            }

            $esquema = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $caminho_base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
            $url_webhook = $esquema . '://' . $host . $caminho_base . '/webhook.php?token=' . urlencode($token);

            $resp_set = requisicaoTelegram($token, 'setWebhook', ['url' => $url_webhook]);

            if (!($resp_set['ok'] ?? false)) {
                responder(false, ['mensagem' => 'Fila limpa, mas falha ao reativar webhook: ' . ($resp_set['description'] ?? 'erro desconhecido')], 400);
            }

            if ($id_bot > 0) {
                 $pdo->prepare("UPDATE bots SET url_webhook = ? WHERE id = ?")->execute([$url_webhook, $id_bot]);
            }

            responder(true, ['mensagem' => 'Conexão reiniciada e fila de mensagens limpa com sucesso!']);
            break;

        case 'listar_links_rastreamento':
            require_once __DIR__ . '/funcoes/links_rastreamento.php';
            $links = listarLinksRastreamento($usuario_id);
            foreach ($links as &$l) {
                $l['link_gerado'] = gerarUrlLink($l['bot_username'] ?? '', $l['identificador']);
            }
            responder(true, ['links' => $links]);
            break;

        case 'obter_link_rastreamento':
            require_once __DIR__ . '/funcoes/links_rastreamento.php';
            $id = (int) ($_GET['id'] ?? 0);
            if ($id <= 0) {
                responder(false, ['mensagem' => 'ID inválido.'], 422);
            }
            $link = obterLinkRastreamento($usuario_id, $id);
            if (!$link) {
                responder(false, ['mensagem' => 'Link não encontrado.'], 404);
            }
            $link['link_gerado'] = gerarUrlLink($link['bot_username'] ?? '', $link['identificador']);
            responder(true, ['link' => $link]);
            break;

        case 'criar_link_rastreamento':
            require_once __DIR__ . '/funcoes/links_rastreamento.php';
            $titulo = sanitizarTexto($entrada['titulo'] ?? '', 255);
            $identificador = sanitizarTexto($entrada['identificador'] ?? '', 100);
            $bot_id = (int) ($entrada['bot_id'] ?? 0);

            if ($titulo === '' || $identificador === '' || $bot_id <= 0) {
                responder(false, ['mensagem' => 'Preencha todos os campos obrigatórios.'], 422);
            }
            if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $identificador)) {
                responder(false, ['mensagem' => 'Identificador inválido. Use apenas letras, números, _ e -.'], 422);
            }
            $resultado = criarLinkRastreamento($usuario_id, $titulo, $identificador, $bot_id);
            responder($resultado['sucesso'], $resultado, $resultado['sucesso'] ? 200 : 422);
            break;

        case 'editar_link_rastreamento':
            require_once __DIR__ . '/funcoes/links_rastreamento.php';
            $id = (int) ($entrada['id'] ?? 0);
            $titulo = sanitizarTexto($entrada['titulo'] ?? '', 255);
            $identificador = sanitizarTexto($entrada['identificador'] ?? '', 100);
            $bot_id = (int) ($entrada['bot_id'] ?? 0);

            if ($id <= 0 || $titulo === '' || $identificador === '' || $bot_id <= 0) {
                responder(false, ['mensagem' => 'Preencha todos os campos obrigatórios.'], 422);
            }
            if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $identificador)) {
                responder(false, ['mensagem' => 'Identificador inválido. Use apenas letras, números, _ e -.'], 422);
            }
            $resultado = editarLinkRastreamento($usuario_id, $id, $titulo, $identificador, $bot_id);
            responder($resultado['sucesso'], $resultado, $resultado['sucesso'] ? 200 : 422);
            break;

        case 'excluir_link_rastreamento':
            require_once __DIR__ . '/funcoes/links_rastreamento.php';
            $id = (int) ($entrada['id'] ?? 0);
            if ($id <= 0) {
                responder(false, ['mensagem' => 'ID inválido.'], 422);
            }
            $resultado = excluirLinkRastreamento($usuario_id, $id);
            responder($resultado['sucesso'], $resultado, $resultado['sucesso'] ? 200 : 404);
            break;

        default:
            responder(false, ['mensagem' => 'Ação inválida.'], 404);
    }
} catch (PDOException $e) {
    responder(false, ['mensagem' => 'Erro de banco de dados: ' . $e->getMessage()], 500);
} catch (Exception $e) {
    responder(false, ['mensagem' => 'Erro interno: ' . $e->getMessage()], 500);
}
