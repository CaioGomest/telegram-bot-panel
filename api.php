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

// Helper para resposta JSON
function responder(bool $sucesso, array $dados = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode(array_merge(['sucesso' => $sucesso], $dados), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Helper para pegar dados da requisição
function dados_requisicao(): array
{
    $tipoConteudo = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($tipoConteudo, 'application/json') !== false) {
        $bruto = file_get_contents('php://input');
        $decodificado = json_decode($bruto ?: '{}', true);
        return is_array($decodificado) ? $decodificado : [];
    }
    return $_POST;
}

// Helper para requisições Telegram
function requisicao_telegram(string $token, string $metodo, array $parametros = [], array $arquivos = []): array
{
    $url = 'https://api.telegram.org/bot' . $token . '/' . $metodo;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    if (!empty($arquivos)) {
        $arquivoAdicionado = false;
        foreach ($arquivos as $campo => $caminho) {
            if (!file_exists($caminho)) {
                continue;
            }
            $mime = function_exists('mime_content_type') ? (mime_content_type($caminho) ?: 'application/octet-stream') : 'application/octet-stream';
            $parametros[$campo] = new CURLFile($caminho, $mime, basename($caminho));
            $arquivoAdicionado = true;
        }
        if (!$arquivoAdicionado) {
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
    $erroCurl = curl_error($ch);
    $codigoHttp = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resposta === false) {
        return [
            'ok' => false,
            'description' => 'Falha de comunicação com a API do Telegram: ' . $erroCurl,
            'http_code' => $codigoHttp,
        ];
    }

    $decodificado = json_decode($resposta, true);
    if (!is_array($decodificado)) {
        return [
            'ok' => false,
            'description' => 'Resposta inválida da API do Telegram.',
            'http_code' => $codigoHttp,
            'raw' => $resposta,
        ];
    }

    $decodificado['http_code'] = $codigoHttp;
    return $decodificado;
}

function sanitizar_texto(?string $valor, int $tamanhoMaximo = 0): string
{
    $valor = trim((string) $valor);
    if ($tamanhoMaximo > 0) {
        $valor = mb_substr($valor, 0, $tamanhoMaximo);
    }
    return $valor;
}

function obter_bot_com_info_live(string $token): array
{
    $eu = requisicao_telegram($token, 'getMe');
    if (!($eu['ok'] ?? false)) {
        return $eu;
    }

    $desc = requisicao_telegram($token, 'getMyDescription');
    $curta = requisicao_telegram($token, 'getMyShortDescription');

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

// Identificar usuário logado
$usuarioId = (int)$_SESSION['usuario_id'];

$acao = $_GET['action'] ?? $_POST['action'] ?? '';
$entrada = dados_requisicao();

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
            $stmt->execute([$usuarioId]);
            $grupos = $stmt->fetchAll();
            responder(true, ['grupos' => $grupos]);
            break;

        case 'gateway_info':
            // Basta um gateway ativo ser PJ para liberar a opção de recorrente
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM usuarios_gateways ug
                JOIN gateways g ON ug.id_gateway = g.id
                WHERE ug.id_usuario = ? AND ug.ativo = 1 AND ug.tipo_conta = 'pj'
            ");
            $stmt->execute([$usuarioId]);
            $suportaRecorrente = (int)$stmt->fetchColumn() > 0;
            responder(true, ['suporta_recorrente' => $suportaRecorrente]);
            break;

        case 'listar_fluxos':
            $stmt = $pdo->prepare("SELECT * FROM fluxos WHERE id_usuario = ? ORDER BY atualizado_em DESC");
            $stmt->execute([$usuarioId]);
            $fluxos = $stmt->fetchAll();
            
            // Converter dados_fluxograma de JSON string para array
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
            $stmt->execute([$id, $usuarioId]);
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
            $stmt = $pdo->prepare("SELECT id, nome, descricao, dados_fluxograma, atualizado_em FROM fluxos WHERE id = ? AND id_usuario = ?");
            $stmt->execute([$id, $usuarioId]);
            $fluxo = $stmt->fetch();
            if (!$fluxo) {
                responder(false, ['mensagem' => 'Fluxo não encontrado.'], 404);
            }
            $exportado = [
                'versao' => 1,
                'id_origem' => (int)($fluxo['id'] ?? 0),
                'nome' => (string)($fluxo['nome'] ?? ''),
                'descricao' => (string)($fluxo['descricao'] ?? ''),
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
            $nome = sanitizar_texto($json['nome'] ?? 'Fluxo importado', 120);
            $descricao = sanitizar_texto($json['descricao'] ?? '', 500);
            $dados = $json['dados_fluxograma'] ?? null;
            if (!is_array($dados) || !isset($dados['operators']) || !isset($dados['links'])) {
                responder(false, ['mensagem' => 'Estrutura do fluxo inválida.'], 422);
            }
            $stmt = $pdo->prepare("INSERT INTO fluxos (id_usuario, nome, descricao, dados_fluxograma) VALUES (?, ?, ?, ?)");
            $stmt->execute([$usuarioId, $nome, $descricao, json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
            $novoId = (int)$pdo->lastInsertId();
            $stmt = $pdo->prepare("SELECT * FROM fluxos WHERE id = ?");
            $stmt->execute([$novoId]);
            $fluxo = $stmt->fetch();
            $fluxo['dados_fluxograma'] = json_decode($fluxo['dados_fluxograma'] ?? '{}', true);
            responder(true, ['mensagem' => 'Fluxo importado com sucesso.', 'fluxo' => $fluxo]);
            break;

        case 'salvar_fluxo':
            $idFluxo = (int) ($entrada['id'] ?? 0);
            $nome = sanitizar_texto($entrada['nome'] ?? 'Novo fluxo', 120);
            $descricao = sanitizar_texto($entrada['descricao'] ?? '', 500);

            // Aceita dados_fluxograma_b64 para contornar WAF/ModSecurity que bloqueiam o JSON do diagrama (403).
            $dadosGrafico = null;
            if (!empty($entrada['dados_fluxograma_b64']) && is_string($entrada['dados_fluxograma_b64'])) {
                $brutoB64 = base64_decode($entrada['dados_fluxograma_b64'], true);
                if ($brutoB64 === false || $brutoB64 === '') {
                    responder(false, ['mensagem' => 'dados_fluxograma (codificação) inválidos.'], 422);
                }
                $dadosGrafico = json_decode($brutoB64, true);
            } else {
                $dadosGrafico = $entrada['dados_fluxograma'] ?? ['operators' => [], 'links' => []];
            }

            if (!is_array($dadosGrafico)) {
                responder(false, ['mensagem' => 'dados_fluxograma inválido.'], 422);
            }
            $jsonGrafico = json_encode($dadosGrafico, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if ($idFluxo > 0) {
                // Atualizar
                $stmt = $pdo->prepare("UPDATE fluxos SET nome = ?, descricao = ?, dados_fluxograma = ? WHERE id = ? AND id_usuario = ?");
                $stmt->execute([$nome, $descricao, $jsonGrafico, $idFluxo, $usuarioId]);
                
                // Buscar dados atualizados
                $stmt = $pdo->prepare("SELECT * FROM fluxos WHERE id = ?");
                $stmt->execute([$idFluxo]);
                $fluxo = $stmt->fetch();
                
                registrarAtividade($usuarioId, 'sistema', 'Fluxo', "Atualizou o fluxo: $nome");
            } else {
                // Inserir
                $stmt = $pdo->prepare("INSERT INTO fluxos (id_usuario, nome, descricao, dados_fluxograma) VALUES (?, ?, ?, ?)");
                $stmt->execute([$usuarioId, $nome, $descricao, $jsonGrafico]);
                $novoId = $pdo->lastInsertId();
                
                $stmt = $pdo->prepare("SELECT * FROM fluxos WHERE id = ?");
                $stmt->execute([$novoId]);
                $fluxo = $stmt->fetch();
                
                registrarAtividade($usuarioId, 'sistema', 'Fluxo', "Criou novo fluxo: $nome");
            }
            
            $fluxo['dados_fluxograma'] = json_decode($fluxo['dados_fluxograma'] ?? '{}', true);
            responder(true, ['mensagem' => 'Fluxo salvo com sucesso.', 'fluxo' => $fluxo]);
            break;

        case 'excluir_fluxo':
            $id = (int) ($entrada['id'] ?? 0);
            if ($id <= 0) {
                responder(false, ['mensagem' => 'ID do fluxo inválido.'], 422);
            }

            // Pega o nome antes de excluir para o log
            $stmt = $pdo->prepare("SELECT nome FROM fluxos WHERE id = ? AND id_usuario = ?");
            $stmt->execute([$id, $usuarioId]);
            $fluxoAlvo = $stmt->fetch();
            $nomeFluxo = $fluxoAlvo['nome'] ?? "ID $id";

            $stmt = $pdo->prepare("DELETE FROM fluxos WHERE id = ? AND id_usuario = ?");
            $stmt->execute([$id, $usuarioId]);
            
            if ($stmt->rowCount() === 0) {
                responder(false, ['mensagem' => 'Fluxo não encontrado ou já excluído.'], 404);
            }

            registrarAtividade($usuarioId, 'sistema', 'Fluxo', "Excluiu o fluxo: $nomeFluxo");

            // O ON DELETE SET NULL na FK bots.id_fluxo_conectado já cuida de desvincular os bots
            responder(true, ['mensagem' => 'Fluxo excluído com sucesso.']);
            break;

        case 'listar_bots':
            $stmt = $pdo->prepare("SELECT * FROM bots WHERE id_usuario = ? ORDER BY atualizado_em DESC");
            $stmt->execute([$usuarioId]);
            $bots = $stmt->fetchAll();
            responder(true, ['bots' => $bots]);
            break;

        case 'obter_bot':
            $id = (int) ($_GET['id'] ?? 0);
            if ($id <= 0) {
                responder(false, ['mensagem' => 'ID do bot inválido.'], 422);
            }
            $stmt = $pdo->prepare("SELECT * FROM bots WHERE id = ? AND id_usuario = ?");
            $stmt->execute([$id, $usuarioId]);
            $bot = $stmt->fetch(PDO::FETCH_ASSOC); // Garante array associativo limpo
            if (!$bot) {
                responder(false, ['mensagem' => 'Bot não encontrado.'], 404);
            }
            
            // Verifica se o bot tem webhook ativo na API do Telegram
            // Isso pode deixar o carregamento lento, então vamos fazer apenas se solicitado ou confiar no banco
            // Para edição rápida, retornamos os dados do banco.
            
            responder(true, ['bot' => $bot]);
            break;

        case 'testar_bot':
            $token = sanitizar_texto($entrada['token'] ?? '');
            if ($token === '') {
                responder(false, ['mensagem' => 'Informe o token do bot.'], 422);
            }

            $vivo = obter_bot_com_info_live($token);
            if (!($vivo['ok'] ?? false)) {
                responder(false, ['mensagem' => $vivo['description'] ?? 'Não foi possível validar o token.'], 400);
            }

            responder(true, ['mensagem' => 'Bot conectado com sucesso.', 'info_bot' => $vivo['result']]);
            break;

        case 'salvar_bot':
            $token = sanitizar_texto($entrada['token'] ?? '');
            $idFluxo = !empty($entrada['id_fluxo_conectado']) ? (int)$entrada['id_fluxo_conectado'] : null;
            $idBot = (int) ($entrada['id'] ?? 0);

            if ($token === '') {
                responder(false, ['mensagem' => 'Informe o token do bot.'], 422);
            }

            $vivo = obter_bot_com_info_live($token);
            if (!($vivo['ok'] ?? false)) {
                responder(false, ['mensagem' => $vivo['description'] ?? 'Token inválido.'], 400);
            }

            // Verifica se o bot já existe para este usuário (pelo ID ou Token)
            $botExistente = null;
            if ($idBot > 0) {
                $stmt = $pdo->prepare("SELECT * FROM bots WHERE id = ? AND id_usuario = ?");
                $stmt->execute([$idBot, $usuarioId]);
                $botExistente = $stmt->fetch();
            }
            if (!$botExistente) {
                $stmt = $pdo->prepare("SELECT * FROM bots WHERE token = ? AND id_usuario = ?");
                $stmt->execute([$token, $usuarioId]);
                $botExistente = $stmt->fetch();
            }

            $idBotTelegram = $vivo['result']['id'] ?? null;
            $nomeUsuario = $vivo['result']['username'] ?? '';
            $primeiroNome = $vivo['result']['first_name'] ?? '';
            $descricao = $vivo['result']['description'] ?? '';
            $descricaoCurta = $vivo['result']['short_description'] ?? '';

            // Tenta obter a foto de perfil mais recente
            $fotos = requisicao_telegram($token, 'getUserProfilePhotos', ['user_id' => $vivo['result']['id'], 'limit' => 1]);
            $caminhoFoto = $botExistente['caminho_foto'] ?? '';
            
            if (($fotos['ok'] ?? false) && !empty($fotos['result']['photos'][0][0]['file_id'])) {
                $fileId = $fotos['result']['photos'][0][0]['file_id'];
                $fileInfo = requisicao_telegram($token, 'getFile', ['file_id' => $fileId]);
                
                if (($fileInfo['ok'] ?? false) && !empty($fileInfo['result']['file_path'])) {
                    $urlFoto = 'https://api.telegram.org/file/bot' . $token . '/' . $fileInfo['result']['file_path'];
                    
                    // Baixa e salva localmente
                    $extensao = pathinfo($fileInfo['result']['file_path'], PATHINFO_EXTENSION) ?: 'jpg';
                    $nomeArquivo = 'bot_avatar_' . $idBotTelegram . '_' . time() . '.' . $extensao;
                    $caminhoLocal = DIRETORIO_UPLOADS . '/' . $nomeArquivo;
                    
                    $conteudoFoto = @file_get_contents($urlFoto);
                    if ($conteudoFoto) {
                        file_put_contents($caminhoLocal, $conteudoFoto);
                        $caminhoFoto = 'uploads/' . $nomeArquivo;
                    }
                }
            }

            if ($botExistente) {
                // Update
                $idBot = $botExistente['id'];
                $stmt = $pdo->prepare("UPDATE bots SET 
                    token = ?, id_bot_telegram = ?, nome_usuario = ?, primeiro_nome = ?, 
                    descricao = ?, descricao_curta = ?, id_fluxo_conectado = ?, caminho_foto = ? 
                    WHERE id = ?");
                $stmt->execute([
                    $token, $idBotTelegram, $nomeUsuario, $primeiroNome, 
                    $descricao, $descricaoCurta, $idFluxo, $caminhoFoto,
                    $idBot
                ]);
            } else {
                // Insert
                $stmt = $pdo->prepare("INSERT INTO bots 
                    (id_usuario, token, id_bot_telegram, nome_usuario, primeiro_nome, descricao, descricao_curta, id_fluxo_conectado, caminho_foto) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $usuarioId, $token, $idBotTelegram, $nomeUsuario, $primeiroNome, 
                    $descricao, $descricaoCurta, $idFluxo, $caminhoFoto
                ]);
                $idBot = $pdo->lastInsertId();
            }

            // Webhook
            $esquema = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $caminhoBase = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
            $urlWebhook = $esquema . '://' . $host . $caminhoBase . '/webhook.php?token=' . urlencode($token);
            
            $wh = requisicao_telegram($token, 'setWebhook', ['url' => $urlWebhook]);
            $webhookDefinido = (bool) ($wh['ok'] ?? false);
            
            // Salva URL do webhook no banco
            if ($webhookDefinido) {
                $pdo->prepare("UPDATE bots SET url_webhook = ? WHERE id = ?")->execute([$urlWebhook, $idBot]);
            }

            $msgExtra = $webhookDefinido ? ' Webhook ativado.' : (' Falha ao ativar webhook: ' . ($wh['description'] ?? 'erro desconhecido') . '.');

            // Log
            $acaoBot = $botExistente ? 'Atualizou' : 'Criou';
            registrarAtividade($usuarioId, 'sistema', 'Bot', "$acaoBot o bot @$nomeUsuario.");

            // Retorna o bot atualizado
            $stmt = $pdo->prepare("SELECT * FROM bots WHERE id = ?");
            $stmt->execute([$idBot]);
            $botAtualizado = $stmt->fetch();

            responder(true, ['mensagem' => 'Bot salvo com sucesso.' . $msgExtra, 'bot' => $botAtualizado, 'webhook_definido' => $webhookDefinido, 'url_webhook' => $urlWebhook]);
            break;

        case 'atualizar_perfil_bot':
            $token = sanitizar_texto($entrada['token'] ?? '');
            $idBot = (int) ($entrada['id'] ?? 0);
            $nome = sanitizar_texto($entrada['nome'] ?? '', 64);
            $descricao = sanitizar_texto($entrada['descricao'] ?? '', 512);
            $descricaoCurta = sanitizar_texto($entrada['descricao_curta'] ?? '', 120);
            $idFluxo = !empty($entrada['id_fluxo_conectado']) ? (int)$entrada['id_fluxo_conectado'] : null;

            if ($token === '' && $idBot === 0) {
                responder(false, ['mensagem' => 'Informe o token ou selecione um bot salvo.'], 422);
            }

            // Busca bot no banco
            $bot = null;
            if ($idBot > 0) {
                $stmt = $pdo->prepare("SELECT * FROM bots WHERE id = ? AND id_usuario = ?");
                $stmt->execute([$idBot, $usuarioId]);
                $bot = $stmt->fetch();
            }
            
            if (!$bot && $token !== '') {
                 // Tenta achar pelo token se ID não veio
                 $stmt = $pdo->prepare("SELECT * FROM bots WHERE token = ? AND id_usuario = ?");
                 $stmt->execute([$token, $usuarioId]);
                 $bot = $stmt->fetch();
            }

            if (!$bot) {
                responder(false, ['mensagem' => 'Bot não encontrado.'], 404);
            }

            // Garante que temos o token correto
            $token = $bot['token'];

            // Valida no Telegram
            $validacao = requisicao_telegram($token, 'getMe');
            if (!($validacao['ok'] ?? false)) {
                responder(false, ['mensagem' => $validacao['description'] ?? 'Token inválido.'], 400);
            }

            $passos = [];
            $erros = [];

            if ($nome !== '') {
                $resp = requisicao_telegram($token, 'setMyName', ['name' => $nome]);
                if (!($resp['ok'] ?? false)) {
                    $erros[] = 'Nome: ' . ($resp['description'] ?? 'erro desconhecido');
                } else {
                    $passos[] = 'Nome';
                }
            }

            $resp = requisicao_telegram($token, 'setMyDescription', ['description' => $descricao]);
            if (!($resp['ok'] ?? false)) {
                $erros[] = 'Descrição: ' . ($resp['description'] ?? 'erro desconhecido');
            } else {
                $passos[] = 'Descrição';
            }

            $resp = requisicao_telegram($token, 'setMyShortDescription', ['short_description' => $descricaoCurta]);
            if (!($resp['ok'] ?? false)) {
                $erros[] = 'Descrição Curta: ' . ($resp['description'] ?? 'erro desconhecido');
            } else {
                $passos[] = 'Descrição Curta';
            }

            // Upload de foto
            $caminhoFoto = $bot['caminho_foto'];
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
                    $caminhoLocal = DIRETORIO_UPLOADS . '/' . uniqid('bot_photo_', true) . '.' . $extensao;
                    if (!move_uploaded_file($tmp, $caminhoLocal) || !file_exists($caminhoLocal)) {
                        $erros[] = 'Foto: Falha ao salvar arquivo local.';
                    } else {
                        // Salva caminho relativo para o banco
                        $caminhoFoto = 'uploads/' . basename($caminhoLocal);
                        $passos[] = 'Foto (local)';
                    }
                }
            }

            // Atualiza DB com os dados enviados (mesmo que Telegram tenha falhado, salvamos a intenção do usuário?)
            // Ou melhor: salvamos tudo para persistir a edição.
            // Mas precisamos pegar os dados atualizados do Telegram SE quisermos manter sync?
            // Não, aqui estamos forçando o envio. Se falhou, o DB fica com o valor novo (desincronizado) mas o usuário pode tentar de novo.
            // Se não salvarmos no DB, o usuário perde o que digitou.
            
            // Vamos atualizar o DB com os valores do formulário.
            // Exceto campos que o Telegram retornou (como id_bot_telegram), que não mudam aqui.
            // Mas `obter_bot_com_info_live` trazia dados atualizados.
            // Aqui vamos usar os dados do input para atualizar o DB.
            
            // Dados atuais do bot para preencher lacunas se necessário (já temos $bot)
            // Atualiza DB
            $stmt = $pdo->prepare("UPDATE bots SET 
                nome_usuario = ?, primeiro_nome = ?, 
                descricao = ?, descricao_curta = ?, id_fluxo_conectado = ?, 
                caminho_foto = ? 
                WHERE id = ?");
            
            // Nota: nome_usuario (username) não muda via API, mas aqui estamos usando o $validacao['result']['username'] para garantir.
            // O campo 'nome' do formulário é o 'first_name' (Nome de Exibição).
            
            $stmt->execute([
                $validacao['result']['username'] ?? $bot['nome_usuario'], // Username não muda via setMyName
                $nome, // first_name
                $descricao,
                $descricaoCurta,
                $idFluxo,
                $caminhoFoto,
                $bot['id']
            ]);

            // Retorna bot atualizado
            $stmt = $pdo->prepare("SELECT * FROM bots WHERE id = ?");
            $stmt->execute([$bot['id']]);
            $botAtualizado = $stmt->fetch();

            // Monta mensagem final
            $msgSucesso = !empty($passos) ? 'Itens atualizados: ' . implode(', ', $passos) . '.' : '';
            $msgErro = !empty($erros) ? 'Erros: ' . implode(' | ', $erros) . '.' : '';
            
            $mensagemFinal = trim($msgSucesso . ' ' . $msgErro);
            
            // Se houve erros, retornamos sucesso=false para o frontend mostrar alerta vermelho,
            // mas ainda retornamos o bot atualizado.
            if (!empty($erros)) {
                responder(false, ['mensagem' => $mensagemFinal, 'bot' => $botAtualizado]);
            }

            responder(true, ['mensagem' => $mensagemFinal ?: 'Perfil atualizado com sucesso!', 'bot' => $botAtualizado]);
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
            $caminhoLocal = DIRETORIO_UPLOADS . '/' . uniqid('flow_image_', true) . '.' . $extensao;
            if (!move_uploaded_file($tmp, $caminhoLocal) || !file_exists($caminhoLocal)) {
                responder(false, ['mensagem' => 'Falha ao salvar a imagem.'], 500);
            }
            $relativo = 'uploads/' . basename($caminhoLocal);
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
            $caminhoLocal = DIRETORIO_UPLOADS . '/' . uniqid('flow_video_', true) . '.' . $extensao;
            if (!move_uploaded_file($tmp, $caminhoLocal) || !file_exists($caminhoLocal)) {
                responder(false, ['mensagem' => 'Falha ao salvar o vídeo.'], 500);
            }
            $relativo = 'uploads/' . basename($caminhoLocal);
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
            $caminhoLocal = DIRETORIO_UPLOADS . '/' . uniqid('flow_audio_', true) . '.' . $extensao;
            if (!move_uploaded_file($tmp, $caminhoLocal) || !file_exists($caminhoLocal)) {
                responder(false, ['mensagem' => 'Falha ao salvar o áudio.'], 500);
            }
            $relativo = 'uploads/' . basename($caminhoLocal);
            responder(true, ['caminho' => $relativo]);
            break;

        case 'enviar_imagem_teste':
            // ... (mesma lógica, sem dependência de banco)
            $token = sanitizar_texto($entrada['token'] ?? '');
            $chatId = sanitizar_texto($entrada['chat_id'] ?? '');
            $modo = sanitizar_texto($entrada['mode'] ?? 'foto');
            $legenda = sanitizar_texto($entrada['caption'] ?? '', 1024);
            $spoiler = (bool)($entrada['spoiler'] ?? false);
            $caminho = sanitizar_texto($entrada['path'] ?? '');
            if ($token === '' || $chatId === '') {
                responder(false, ['mensagem' => 'Informe token e chat_id.'], 422);
            }
            $caminhoArquivo = '';
            if (!empty($_FILES['image']['tmp_name'])) {
                $tmp = $_FILES['image']['tmp_name'];
                // ... validação ...
                $extensao = strtolower(pathinfo($_FILES['image']['name'] ?? 'image.jpg', PATHINFO_EXTENSION));
                $caminhoArquivo = DIRETORIO_UPLOADS . '/' . uniqid('flow_image_', true) . '.' . $extensao;
                move_uploaded_file($tmp, $caminhoArquivo);
            } elseif ($caminho !== '') {
                $candidato = $caminho;
                if (strpos($candidato, 'uploads/') === 0) {
                    $candidato = __DIR__ . '/' . str_replace(['..', '\\'], ['', '/'], $candidato);
                }
                if (file_exists($candidato)) {
                    $caminhoArquivo = realpath($candidato) ?: $candidato;
                }
            }
            
            if (!$caminhoArquivo) {
                responder(false, ['mensagem' => 'Imagem não encontrada.'], 422);
            }
            
            $caminhoArquivo = str_replace('\\', '/', $caminhoArquivo);
            $parametros = ['chat_id' => $chatId];
            if ($legenda !== '') {
                $parametros['caption'] = $legenda;
            }
            if ($modo === 'documento') {
                $resp = requisicao_telegram($token, 'sendDocument', $parametros, ['document' => $caminhoArquivo]);
            } else {
                if ($spoiler) {
                    $parametros['has_spoiler'] = true;
                }
                $resp = requisicao_telegram($token, 'sendPhoto', $parametros, ['photo' => $caminhoArquivo]);
            }
            
            if (!($resp['ok'] ?? false)) {
                responder(false, ['mensagem' => $resp['description'] ?? 'Falha no envio', 'http_code' => $resp['http_code'] ?? 0], 400);
            }
            responder(true, ['mensagem' => 'Imagem enviada.', 'resultado_telegram' => $resp['result'] ?? null]);
            break;

        case 'descobrir_chat_id':
            // ... (lógica API Telegram apenas)
            $token = sanitizar_texto($entrada['token'] ?? '');
            if ($token === '') {
                responder(false, ['mensagem' => 'Informe o token do bot.'], 422);
            }
            $atualizacoes = requisicao_telegram($token, 'getUpdates', []);
            if (!($atualizacoes['ok'] ?? false) || empty($atualizacoes['result'])) {
                responder(false, ['mensagem' => $atualizacoes['description'] ?? 'Nenhuma atualização encontrada.'], 404);
            }
            $idChat = null;
            foreach (array_reverse($atualizacoes['result']) as $upd) {
                if (!empty($upd['message']['chat']['id'])) {
                    $idChat = (string)$upd['message']['chat']['id'];
                    break;
                }
                // ... check other types
            }
            if (!$idChat) {
                responder(false, ['mensagem' => 'Não foi possível localizar um chat recente.'], 404);
            }
            responder(true, ['id_chat' => $idChat]);
            break;

        case 'excluir_webhook':
            $token = sanitizar_texto($entrada['token'] ?? '');
            if ($token === '') {
                responder(false, ['mensagem' => 'Informe o token do bot.'], 422);
            }
            $resp = requisicao_telegram($token, 'deleteWebhook', ['drop_pending_updates' => true]);
            if (!($resp['ok'] ?? false)) {
                responder(false, ['mensagem' => $resp['description'] ?? 'Falha ao remover webhook.'], 400);
            }
            // Atualiza no banco
            $pdo->prepare("UPDATE bots SET url_webhook = NULL WHERE token = ?")->execute([$token]);
            
            // Busca nome do bot para log
            $stmt = $pdo->prepare("SELECT nome_usuario FROM bots WHERE token = ?");
            $stmt->execute([$token]);
            $bot = $stmt->fetch();
            $nomeBot = $bot['nome_usuario'] ?? 'desconhecido';

            registrarAtividade($usuarioId, 'sistema', 'Bot', "Removeu webhook do bot @$nomeBot.");

            responder(true, ['mensagem' => 'Webhook removido.']);
            break;

        case 'obter_info_webhook':
            $token = sanitizar_texto($entrada['token'] ?? '');
            if ($token === '') {
                responder(false, ['mensagem' => 'Informe o token do bot.'], 422);
            }
            $resp = requisicao_telegram($token, 'getWebhookInfo');
            if (!($resp['ok'] ?? false)) {
                responder(false, ['mensagem' => 'Falha ao consultar webhook: ' . ($resp['description'] ?? 'erro desconhecido')], 400);
            }
            responder(true, ['info' => $resp['result'] ?? []]);
            break;

        case 'reiniciar_webhook':
            $token = sanitizar_texto($entrada['token'] ?? '');
            $idBot = (int) ($entrada['id'] ?? 0);
            
            if ($token === '') {
                responder(false, ['mensagem' => 'Informe o token do bot.'], 422);
            }

            // 1. Remove Webhook e limpa fila
            $respDel = requisicao_telegram($token, 'deleteWebhook', ['drop_pending_updates' => true]);
            if (!($respDel['ok'] ?? false)) {
                responder(false, ['mensagem' => 'Falha ao limpar webhook: ' . ($respDel['description'] ?? 'erro desconhecido')], 400);
            }

            // 2. Define Webhook novamente
            $esquema = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $caminhoBase = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
            $urlWebhook = $esquema . '://' . $host . $caminhoBase . '/webhook.php?token=' . urlencode($token);
            
            $respSet = requisicao_telegram($token, 'setWebhook', ['url' => $urlWebhook]);
            
            if (!($respSet['ok'] ?? false)) {
                responder(false, ['mensagem' => 'Fila limpa, mas falha ao reativar webhook: ' . ($respSet['description'] ?? 'erro desconhecido')], 400);
            }
            
            // Atualiza URL no banco se necessário
            if ($idBot > 0) {
                 $pdo->prepare("UPDATE bots SET url_webhook = ? WHERE id = ?")->execute([$urlWebhook, $idBot]);
            }

            responder(true, ['mensagem' => 'Conexão reiniciada e fila de mensagens limpa com sucesso!']);
            break;

        // --- Links de Rastreamento ---
        case 'listar_links_rastreamento':
            require_once __DIR__ . '/funcoes/links_rastreamento.php';
            $links = listarLinksRastreamento($usuarioId);
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
            $link = obterLinkRastreamento($usuarioId, $id);
            if (!$link) {
                responder(false, ['mensagem' => 'Link não encontrado.'], 404);
            }
            $link['link_gerado'] = gerarUrlLink($link['bot_username'] ?? '', $link['identificador']);
            responder(true, ['link' => $link]);
            break;

        case 'criar_link_rastreamento':
            require_once __DIR__ . '/funcoes/links_rastreamento.php';
            $titulo = sanitizar_texto($entrada['titulo'] ?? '', 255);
            $identificador = sanitizar_texto($entrada['identificador'] ?? '', 100);
            $botId = (int) ($entrada['bot_id'] ?? 0);

            if ($titulo === '' || $identificador === '' || $botId <= 0) {
                responder(false, ['mensagem' => 'Preencha todos os campos obrigatórios.'], 422);
            }
            if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $identificador)) {
                responder(false, ['mensagem' => 'Identificador inválido. Use apenas letras, números, _ e -.'], 422);
            }
            $resultado = criarLinkRastreamento($usuarioId, $titulo, $identificador, $botId);
            responder($resultado['sucesso'], $resultado, $resultado['sucesso'] ? 200 : 422);
            break;

        case 'editar_link_rastreamento':
            require_once __DIR__ . '/funcoes/links_rastreamento.php';
            $id = (int) ($entrada['id'] ?? 0);
            $titulo = sanitizar_texto($entrada['titulo'] ?? '', 255);
            $identificador = sanitizar_texto($entrada['identificador'] ?? '', 100);
            $botId = (int) ($entrada['bot_id'] ?? 0);

            if ($id <= 0 || $titulo === '' || $identificador === '' || $botId <= 0) {
                responder(false, ['mensagem' => 'Preencha todos os campos obrigatórios.'], 422);
            }
            if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $identificador)) {
                responder(false, ['mensagem' => 'Identificador inválido. Use apenas letras, números, _ e -.'], 422);
            }
            $resultado = editarLinkRastreamento($usuarioId, $id, $titulo, $identificador, $botId);
            responder($resultado['sucesso'], $resultado, $resultado['sucesso'] ? 200 : 422);
            break;

        case 'excluir_link_rastreamento':
            require_once __DIR__ . '/funcoes/links_rastreamento.php';
            $id = (int) ($entrada['id'] ?? 0);
            if ($id <= 0) {
                responder(false, ['mensagem' => 'ID inválido.'], 422);
            }
            $resultado = excluirLinkRastreamento($usuarioId, $id);
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
