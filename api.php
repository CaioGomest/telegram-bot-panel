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

// Toda ação que muda estado (salvar fluxo/bot, upload, excluir, etc.) passa por aqui como
// POST -- as únicas chamadas GET a este arquivo são leituras (listar_*/obter_*/exportar_fluxo),
// que não precisam de proteção CSRF. Token vem do header X-CSRF-Token, setado globalmente
// via $.ajaxSetup() nas páginas que usam este endpoint (ver barra_lateral.php).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificarCsrf();
}

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

/**
 * Garante que todo image_path/video_path/audio_path dentro de dados_fluxograma segue
 * exatamente o padrão gerado pelos endpoints de upload ("uploads/nome_gerado.ext", sem
 * subpasta nem "..") antes de gravar no banco. Sem essa checagem, dava pra gravar um
 * caminho arbitrário (ex. "config.php" ou "certificados/cert_5_1.pem") direto via API,
 * pulando a tela do editor, e o bot reenviaria esse arquivo quando o bloco fosse executado
 * — ver anotacoes/varredura-08-lfi-fluxograma-sessao.md. Caminho fora do padrão é zerado
 * em vez de rejeitar o fluxo inteiro, pra não travar o resto da edição.
 */
/** Mesma checagem de "só aceita caminho uploads/nome.ext" usada abaixo pro grafo de nós. */
function caminhoUploadValido(string $valor): bool
{
    $prefixo = 'uploads/';
    return $valor === '' || (
        strpos($valor, $prefixo) === 0
        && strpos($valor, '..') === false
        && basename($valor) === substr($valor, strlen($prefixo))
    );
}

function sanitizarCaminhosMidiaFluxo(array $dados_grafico): array
{
    // Modo básico: só tem 1 caminho de mídia possível hoje, na Boas-vindas.
    if (isset($dados_grafico['boas_vindas']) && is_array($dados_grafico['boas_vindas'])) {
        $midia = (string) ($dados_grafico['boas_vindas']['midia_path'] ?? '');
        if (!caminhoUploadValido($midia)) {
            $dados_grafico['boas_vindas']['midia_path'] = '';
        }
    }
    if (!isset($dados_grafico['operators']) || !is_array($dados_grafico['operators'])) {
        return $dados_grafico;
    }
    foreach ($dados_grafico['operators'] as &$operador) {
        if (!is_array($operador) || !isset($operador['properties']) || !is_array($operador['properties'])) {
            continue;
        }
        foreach (['image_path', 'video_path', 'audio_path'] as $campo) {
            if (!isset($operador['properties'][$campo])) {
                continue;
            }
            $valor = (string) $operador['properties'][$campo];
            $prefixo = 'uploads/';
            $valido = $valor === '' || (
                strpos($valor, $prefixo) === 0
                && strpos($valor, '..') === false
                && basename($valor) === substr($valor, strlen($prefixo))
            );
            if (!$valido) {
                $operador['properties'][$campo] = '';
            }
        }
    }
    unset($operador);
    return $dados_grafico;
}

function gerarOuObterSegredoWebhook(int $id_bot): string
{
    global $pdo;

    try {
        $stmt = $pdo->prepare("SELECT webhook_secret FROM bots WHERE id = ?");
        $stmt->execute([$id_bot]);
        $segredo = $stmt->fetchColumn();

        if (!$segredo) {
            $segredo = bin2hex(random_bytes(24));
            $pdo->prepare("UPDATE bots SET webhook_secret = ? WHERE id = ?")->execute([$segredo, $id_bot]);
        }

        return $segredo;
    } catch (\Throwable $e) {
        // Coluna ainda não existe (banco não atualizado) — segue sem secret_token
        // até rodar o admin/atualiza_banco.php. Não impede o bot de funcionar.
        return '';
    }
}

/**
 * Traduz os erros mais comuns que o getMe da API do Telegram devolve pra um token
 * inválido -- sem isso, a mensagem crua (ex. "Unauthorized") aparecia em inglês pro
 * usuário, no meio de uma interface toda em português (achado em rodada de teste).
 * Qualquer descrição não mapeada cai no $fallback em português, nunca no texto cru.
 */
function traduzirErroTelegramBot(string $description, string $fallback): string
{
    $mapa = [
        'Unauthorized' => 'Token inválido ou revogado. Confira se copiou certo no @BotFather.',
        'Not Found' => 'Bot não encontrado. Confira se o token está completo.',
    ];
    return $mapa[$description] ?? $fallback;
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
            // PIX Recorrente era só da InfoPago (PIX Automático) -- nenhum gateway suportado
            // hoje implementa recorrência (a OmegaPayments não tem esse recurso na v1). Ver
            // anotacoes/pendente/plano-remocao-infopago.md. Sempre false até algum gateway
            // futuro trazer esse recurso de volta.
            responder(true, ['suporta_recorrente' => false]);
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
            $dados = sanitizarCaminhosMidiaFluxo($dados);
            $stmt = $pdo->prepare("INSERT INTO fluxos (id_usuario, nome, descricao, link_suporte, dados_fluxograma) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$usuario_id, $nome, $descricao, $link_suporte, json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
            $novo_id = (int)$pdo->lastInsertId();
            $stmt = $pdo->prepare("SELECT * FROM fluxos WHERE id = ? AND id_usuario = ?");
            $stmt->execute([$novo_id, $usuario_id]);
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
            $dados_grafico = sanitizarCaminhosMidiaFluxo($dados_grafico);
            $json_grafico = json_encode($dados_grafico, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if ($id_fluxo > 0) {
                $stmt = $pdo->prepare("UPDATE fluxos SET nome = ?, descricao = ?, link_suporte = ?, dados_fluxograma = ? WHERE id = ? AND id_usuario = ?");
                $stmt->execute([$nome, $descricao, $link_suporte, $json_grafico, $id_fluxo, $usuario_id]);

                $stmt = $pdo->prepare("SELECT * FROM fluxos WHERE id = ? AND id_usuario = ?");
                $stmt->execute([$id_fluxo, $usuario_id]);
                $fluxo = $stmt->fetch();

                if (!$fluxo) {
                    responder(false, ['mensagem' => 'Fluxo não encontrado.'], 404);
                }

                registrarAtividade($usuario_id, 'sistema', 'Fluxo', "Atualizou o fluxo: $nome");
            } else {
                // "modo" só é aceito na criação -- imutável depois, porque o formato de
                // dados_fluxograma é completamente diferente entre avancado (grafo de nós)
                // e basico (seções de formulário); trocar o modo de um fluxo existente
                // corromperia o conteúdo salvo.
                $modo = ($entrada['modo'] ?? 'avancado') === 'basico' ? 'basico' : 'avancado';
                $stmt = $pdo->prepare("INSERT INTO fluxos (id_usuario, nome, descricao, link_suporte, modo, dados_fluxograma) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$usuario_id, $nome, $descricao, $link_suporte, $modo, $json_grafico]);
                $novo_id = $pdo->lastInsertId();

                $stmt = $pdo->prepare("SELECT * FROM fluxos WHERE id = ? AND id_usuario = ?");
                $stmt->execute([$novo_id, $usuario_id]);
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

        case 'excluir_bot':
            // Achado: o botão de excluir em bots.php chamava essa ação, mas ela nunca existiu
            // aqui -- todo clique caía no "default" (404 "Ação inválida"), então o bot nunca
            // era removido. leads/vendas sobrevivem com bot_id=NULL (FK já é ON DELETE SET
            // NULL); bot_grupos/membros_grupos/links_rastreamento/remarketing_campanhas/
            // webhooks são apagados junto (FK ON DELETE CASCADE) -- histórico de venda/lead
            // nunca é perdido ao excluir um bot.
            $id_bot = (int) ($entrada['id'] ?? 0);
            if ($id_bot <= 0) {
                responder(false, ['mensagem' => 'ID do bot inválido.'], 422);
            }

            $stmt = $pdo->prepare("SELECT primeiro_nome, nome_usuario, caminho_foto FROM bots WHERE id = ? AND id_usuario = ?");
            $stmt->execute([$id_bot, $usuario_id]);
            $bot_alvo = $stmt->fetch();
            if (!$bot_alvo) {
                responder(false, ['mensagem' => 'Bot não encontrado.'], 404);
            }
            $nome_bot = $bot_alvo['primeiro_nome'] ?: ($bot_alvo['nome_usuario'] ?: "ID $id_bot");

            $stmt = $pdo->prepare("DELETE FROM bots WHERE id = ? AND id_usuario = ?");
            $stmt->execute([$id_bot, $usuario_id]);
            if ($stmt->rowCount() === 0) {
                responder(false, ['mensagem' => 'Bot não encontrado ou já excluído.'], 404);
            }

            $foto = (string) ($bot_alvo['caminho_foto'] ?? '');
            if ($foto !== '' && strpos($foto, 'uploads/') === 0 && strpos($foto, '..') === false) {
                $caminho_real = realpath(__DIR__ . '/' . $foto);
                $base_real = realpath(DIRETORIO_UPLOADS);
                if ($caminho_real && $base_real && strpos($caminho_real, $base_real) === 0) {
                    @unlink($caminho_real);
                }
            }

            registrarAtividade($usuario_id, 'sistema', 'Bot', "Excluiu o bot: $nome_bot");
            responder(true, ['mensagem' => 'Bot excluído com sucesso.']);
            break;

        case 'listar_bots':
            // Traz junto o nome do fluxo conectado e os leads dos últimos 7 dias -- é o que os
            // cards da lista mostram (antes o card exibia o ID cru do fluxo, ex. "Fluxo: 2").
            // A contagem usa idx_leads_bot_criado_em (bot_id, criado_em), então é barata.
            $stmt = $pdo->prepare("
                SELECT b.*,
                       f.nome AS nome_fluxo,
                       (SELECT COUNT(*) FROM leads l
                         WHERE l.bot_id = b.id AND l.criado_em >= NOW() - INTERVAL 7 DAY) AS leads_7d
                FROM bots b
                LEFT JOIN fluxos f ON f.id = b.id_fluxo_conectado
                WHERE b.id_usuario = ?
                ORDER BY b.atualizado_em DESC
            ");
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
                responder(false, ['mensagem' => traduzirErroTelegramBot($vivo['description'] ?? '', 'Não foi possível validar o token.')], 400);
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
                responder(false, ['mensagem' => traduzirErroTelegramBot($vivo['description'] ?? '', 'Token inválido.')], 400);
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

            $segredo_webhook = gerarOuObterSegredoWebhook((int)$id_bot);
            $params_webhook = ['url' => $url_webhook];
            if ($segredo_webhook !== '') {
                $params_webhook['secret_token'] = $segredo_webhook;
            }
            $wh = requisicaoTelegram($token, 'setWebhook', $params_webhook);
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
            // Diferente do upload de imagem (getimagesize() já confirma o conteúdo), este só
            // checava a extensão do nome do arquivo -- um .txt renomeado pra .mp4 passava direto
            // e ia parar no Telegram de um lead de verdade como se fosse o vídeo do produto.
            // mime_content_type() já é usado em produção (api.php linha ~65, webhook.php), então
            // a extensão fileinfo está confirmada disponível no servidor.
            $mime_video = function_exists('mime_content_type') ? (mime_content_type($tmp) ?: '') : '';
            if ($mime_video !== '' && strpos($mime_video, 'video/') !== 0) {
                responder(false, ['mensagem' => 'O arquivo não parece ser um vídeo de verdade.'], 422);
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
            // Mesmo raciocínio do vídeo acima: só a extensão do nome não garante o conteúdo.
            $mime_audio = function_exists('mime_content_type') ? (mime_content_type($tmp) ?: '') : '';
            if ($mime_audio !== '' && strpos($mime_audio, 'audio/') !== 0) {
                responder(false, ['mensagem' => 'O arquivo não parece ser um áudio de verdade.'], 422);
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
                if (!@getimagesize($tmp)) {
                    responder(false, ['mensagem' => 'Arquivo de imagem inválido.'], 422);
                }
                $extensao = strtolower(pathinfo($_FILES['image']['name'] ?? 'image.jpg', PATHINFO_EXTENSION));
                if (!in_array($extensao, ['jpg', 'jpeg', 'png'], true)) {
                    $extensao = 'jpg';
                }
                $caminho_arquivo = DIRETORIO_UPLOADS . '/' . uniqid('flow_image_', true) . '.' . $extensao;
                move_uploaded_file($tmp, $caminho_arquivo);
            } elseif ($caminho !== '') {
                // Só aceita reaproveitar arquivo que já está dentro de /uploads
                // (confirma isso pelo caminho real resolvido, não pelo texto recebido).
                $nome_arquivo = basename($caminho);
                $candidato = DIRETORIO_UPLOADS . '/' . $nome_arquivo;
                $real = realpath($candidato);
                if ($real !== false && strpos($real, realpath(DIRETORIO_UPLOADS)) === 0 && is_file($real)) {
                    $caminho_arquivo = $real;
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

            if ($id_bot <= 0) {
                $stmt_id_bot = $pdo->prepare("SELECT id FROM bots WHERE token = ?");
                $stmt_id_bot->execute([$token]);
                $id_bot = (int) ($stmt_id_bot->fetchColumn() ?: 0);
            }

            $resp_del = requisicaoTelegram($token, 'deleteWebhook', ['drop_pending_updates' => true]);
            if (!($resp_del['ok'] ?? false)) {
                responder(false, ['mensagem' => 'Falha ao limpar webhook: ' . ($resp_del['description'] ?? 'erro desconhecido')], 400);
            }

            $esquema = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $caminho_base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
            $url_webhook = $esquema . '://' . $host . $caminho_base . '/webhook.php?token=' . urlencode($token);

            $segredo_webhook = gerarOuObterSegredoWebhook((int)$id_bot);
            $params_webhook = ['url' => $url_webhook];
            if ($segredo_webhook !== '') {
                $params_webhook['secret_token'] = $segredo_webhook;
            }
            $resp_set = requisicaoTelegram($token, 'setWebhook', $params_webhook);

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

        case 'listar_stories':
            require_once __DIR__ . '/funcoes/stories.php';
            responder(true, ['grupos' => listarBarraStories($usuario_id)]);
            break;

        case 'criar_story':
            require_once __DIR__ . '/funcoes/stories.php';
            $validacao = validarUploadStory($_FILES['midia'] ?? []);
            if (!$validacao['sucesso']) {
                responder(false, ['mensagem' => $validacao['mensagem']], 422);
            }
            try {
                $nome_arquivo = salvarArquivoStory($_FILES['midia'], $validacao['extensao']);
            } catch (RuntimeException $e) {
                responder(false, ['mensagem' => 'Falha ao salvar o arquivo. Tente novamente.'], 500);
            }
            $resultado = criarStory($usuario_id, $validacao['tipo_midia'], $nome_arquivo);
            if (!$resultado['sucesso']) {
                // Já validado antes de gravar -- só falha aqui por limite de stories ativos,
                // então desfaz o arquivo que acabou de ser salvo pra não sobrar lixo em disco.
                @unlink(STORIES_DIRETORIO_UPLOADS . '/' . $nome_arquivo);
            }
            responder($resultado['sucesso'], $resultado, $resultado['sucesso'] ? 200 : 422);
            break;

        case 'marcar_story_vista':
            require_once __DIR__ . '/funcoes/stories.php';
            $story_id = (int) ($entrada['story_id'] ?? 0);
            if ($story_id <= 0) {
                responder(false, ['mensagem' => 'ID inválido.'], 422);
            }
            marcarStoryVista($usuario_id, $story_id);
            responder(true);
            break;

        case 'excluir_story':
            require_once __DIR__ . '/funcoes/stories.php';
            $story_id = (int) ($entrada['id'] ?? 0);
            if ($story_id <= 0) {
                responder(false, ['mensagem' => 'ID inválido.'], 422);
            }
            $resultado = excluirStory($usuario_id, $story_id);
            responder($resultado['sucesso'], $resultado, $resultado['sucesso'] ? 200 : 404);
            break;

        default:
            responder(false, ['mensagem' => 'Ação inválida.'], 404);
    }
} catch (PDOException $e) {
    // A mensagem crua do PDO vaza usuário e host do banco, nome da tabela e trecho do SQL
    // direto na tela do usuário (já aconteceu: o erro de permissão apareceu pro Caio com
    // `u214219698_telegram`@`localhost` visível). Detalhe vai pro log do servidor, usuário
    // recebe mensagem genérica.
    error_log('[api.php] acao=' . $acao . ' usuario=' . $usuario_id . ' PDOException: ' . $e->getMessage());
    responder(false, ['mensagem' => 'Não foi possível concluir a operação. Tente novamente.'], 500);
} catch (Exception $e) {
    error_log('[api.php] acao=' . $acao . ' usuario=' . $usuario_id . ' Exception: ' . $e->getMessage());
    responder(false, ['mensagem' => 'Não foi possível concluir a operação. Tente novamente.'], 500);
}
