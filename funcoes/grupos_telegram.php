<?php

declare(strict_types=1);

/**
 * Registro de grupo/canal pelo ID ou @ (Fluxo Guiado > campo Entrega > "Adicionar grupo").
 *
 * Normalmente o grupo entra em bot_grupos sozinho quando o bot é adicionado a ele (webhook.php, evento my_chat_member). Se o bot já
 * estava lá antes de o webhook apontar para o painel, esse aviso nunca chegou e o grupo não aparece nos seletores de entrega. Aqui o
 * dono informa o ID ou o @ e o painel confere no próprio Telegram (getChat + getChatMember) se o bot está no grupo como administrador
 * com permissão de convidar (sem ela o link de entrada não é gerado na hora da venda); só então registra.
 */

const GRUPO_TIPOS_ACEITOS = ['group', 'supergroup', 'channel'];
const GRUPO_BOTS_CONSULTADOS_MAX = 10;

/**
 * O que o dono digitou -> o que o Telegram aceita em chat_id, ou '' se não der para usar.
 * Aceita: ID numérico de grupo/canal (negativo, ex.: -1001234567890), @usuario (grupo/canal público), link t.me/usuario e link de
 * mensagem de grupo privado (t.me/c/1234567890/5 -> -1001234567890). ID positivo é de pessoa, não de grupo: recusado.
 */
function normalizarIdentificadorGrupo(string $texto): string
{
    $texto = trim($texto);
    if ($texto === '' || strlen($texto) > 200) {
        return '';
    }

    if (preg_match('~^-\d{5,20}$~', $texto)) {
        return $texto;
    }

    if (preg_match('~^(?:https?://)?(?:www\.)?(?:t|telegram)\.me/c/(\d{5,20})(?:[/?#].*)?$~i', $texto, $m)) {
        return '-100' . $m[1];
    }

    if (preg_match('~^(?:https?://)?(?:www\.)?(?:t|telegram)\.me/([A-Za-z][A-Za-z0-9_]{4,31})(?:[/?#].*)?$~i', $texto, $m)) {
        return strcasecmp($m[1], 'joinchat') === 0 ? '' : '@' . $m[1];   // t.me/joinchat/... é convite, não dá para consultar
    }

    if (preg_match('~^@([A-Za-z][A-Za-z0-9_]{4,31})$~', $texto, $m)) {
        return '@' . $m[1];
    }

    return '';
}

/**
 * Resposta do getChatMember sobre o próprio bot -> ['ok' => bool, 'motivo' => 'ok'|'sem_convite'|'sem_admin'|'fora'].
 * Só serve quem é dono do grupo ou administrador com "convidar usuários": é o que o createChatInviteLink exige.
 */
function avaliarBotNoGrupo(array $membro): array
{
    $status = (string) ($membro['status'] ?? '');
    if ($status === 'creator') {
        return ['ok' => true, 'motivo' => 'ok'];
    }
    if ($status === 'administrator') {
        return ($membro['can_invite_users'] ?? false) ? ['ok' => true, 'motivo' => 'ok'] : ['ok' => false, 'motivo' => 'sem_convite'];
    }
    if ($status === 'member' || $status === 'restricted') {
        return ['ok' => false, 'motivo' => 'sem_admin'];
    }
    return ['ok' => false, 'motivo' => 'fora'];
}

/**
 * Confere o grupo com os bots da pessoa (os do fluxo aberto primeiro) e registra em bot_grupos para cada bot que é administrador
 * dele. $telegram(token, metodo, parametros) devolve a resposta decodificada da API (requisicaoTelegram no painel, dublê nos testes).
 * Devolve ['sucesso' => true, 'grupo' => [id_telegram, titulo, tipo], 'bots' => ['@a', ...], 'mensagem' => ...] ou
 * ['sucesso' => false, 'mensagem' => ...]; nunca devolve token.
 */
function registrarGrupoPorIdentificador(PDO $pdo, int $id_usuario, string $texto, callable $telegram, int $id_fluxo = 0): array
{
    $identificador = normalizarIdentificadorGrupo($texto);
    if ($identificador === '') {
        return ['sucesso' => false, 'mensagem' => 'Não entendi esse grupo. Informe o ID (começa com -, por exemplo -1001234567890), o @ do grupo ou canal público, ou um link de mensagem do grupo (t.me/c/…).'];
    }

    $ordem = $id_fluxo > 0 ? 'CASE WHEN id_fluxo_conectado = ' . $id_fluxo . ' THEN 0 ELSE 1 END, id' : 'id';
    $stmt = $pdo->prepare('SELECT id, nome_usuario, token FROM bots WHERE id_usuario = ? ORDER BY ' . $ordem . ' LIMIT ' . GRUPO_BOTS_CONSULTADOS_MAX);
    $stmt->execute([$id_usuario]);
    $bots = $stmt->fetchAll();
    if (!$bots) {
        return ['sucesso' => false, 'mensagem' => 'Você ainda não tem nenhum bot. Crie e conecte um bot antes de adicionar um grupo.'];
    }

    $grupo = null;
    $registrados = [];
    $motivos = [];   // bot (@) => 'nao_encontrado' | 'fora' | 'sem_admin' | 'sem_convite'

    foreach ($bots as $bot) {
        $nome_bot = '@' . $bot['nome_usuario'];
        $token = (string) $bot['token'];

        $chat = $telegram($token, 'getChat', ['chat_id' => $identificador]);
        if (!($chat['ok'] ?? false)) {
            $motivos[$nome_bot] = 'nao_encontrado';
            continue;
        }
        $dados_chat = $chat['result'] ?? [];
        if (!in_array((string) ($dados_chat['type'] ?? ''), GRUPO_TIPOS_ACEITOS, true)) {
            return ['sucesso' => false, 'mensagem' => 'Esse ID é de uma conversa com uma pessoa, não de um grupo ou canal. O ID de grupo começa com - (por exemplo -1001234567890).'];
        }
        $id_chat = (string) ($dados_chat['id'] ?? '');
        if ($id_chat === '') {
            $motivos[$nome_bot] = 'nao_encontrado';
            continue;
        }

        $id_do_bot = (int) strtok($token, ':');
        $membro = $telegram($token, 'getChatMember', ['chat_id' => $id_chat, 'user_id' => $id_do_bot]);
        if (!($membro['ok'] ?? false)) {
            $motivos[$nome_bot] = 'fora';
            continue;
        }
        $avaliacao = avaliarBotNoGrupo($membro['result'] ?? []);
        if (!$avaliacao['ok']) {
            $motivos[$nome_bot] = $avaliacao['motivo'];
            continue;
        }

        $titulo = mb_substr((string) ($dados_chat['title'] ?? $identificador), 0, 255);
        $tipo = (string) $dados_chat['type'];
        $existe = $pdo->prepare('SELECT id FROM bot_grupos WHERE bot_id = ? AND id_telegram = ?');
        $existe->execute([$bot['id'], $id_chat]);
        if ($existe->fetchColumn()) {
            $pdo->prepare('UPDATE bot_grupos SET titulo = ?, tipo = ? WHERE bot_id = ? AND id_telegram = ?')->execute([$titulo, $tipo, $bot['id'], $id_chat]);
        } else {
            $pdo->prepare('INSERT INTO bot_grupos (bot_id, id_telegram, titulo, tipo) VALUES (?, ?, ?, ?)')->execute([$bot['id'], $id_chat, $titulo, $tipo]);
        }
        $grupo = ['id_telegram' => $id_chat, 'titulo' => $titulo, 'tipo' => $tipo];
        $registrados[] = $nome_bot;
    }

    if ($grupo !== null) {
        return [
            'sucesso' => true,
            'grupo' => $grupo,
            'bots' => $registrados,
            'mensagem' => 'Grupo "' . $grupo['titulo'] . '" adicionado (' . implode(', ', $registrados) . ').',
        ];
    }

    return ['sucesso' => false, 'mensagem' => mensagemFalhaGrupo($motivos)];
}

/** Texto para o dono quando nenhum bot conseguiu registrar o grupo: aponta o bot e o passo que falta, do mais perto de dar certo ao mais longe. */
function mensagemFalhaGrupo(array $motivos): string
{
    foreach (['sem_convite', 'sem_admin', 'fora'] as $motivo) {
        $bot = array_search($motivo, $motivos, true);
        if ($bot === false) {
            continue;
        }
        if ($motivo === 'sem_convite') {
            return "O bot $bot é administrador desse grupo, mas sem a permissão de convidar. No Telegram, abra as permissões dele no grupo e ligue \"Convidar usuários via link\" (ou \"Adicionar membros\").";
        }
        if ($motivo === 'sem_admin') {
            return "O bot $bot está nesse grupo, mas não é administrador. No Telegram, promova-o a administrador com a permissão de convidar usuários e tente de novo.";
        }
        return "O bot $bot não está nesse grupo ou canal. Adicione-o como administrador (com a permissão de convidar usuários) e tente de novo.";
    }
    return 'Não encontrei esse grupo com nenhum dos seus bots. Confira o ID (começa com -100) ou o @ e adicione o bot ao grupo como administrador, com a permissão de convidar usuários.';
}
