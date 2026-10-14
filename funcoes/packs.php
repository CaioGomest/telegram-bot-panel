<?php
declare(strict_types=1);

/**
 * Packs do Fluxo Guiado: conteúdos avulsos vendidos além dos planos (até 20 por fluxo).
 * Config em dados_fluxograma['packs'] = {ativo, texto_botao, mensagem, itens[]}; cada item tem
 * emoji, nome, preço, descrição, mídia de preview e id_grupo (destino da entrega). A venda reusa
 * o mesmo motor de Pix dos planos (processarEEnviarBloco): a entrega no grupo e o registro da
 * venda são idênticos, só que o acesso é "longo" (pack não vence como um plano).
 *
 * callback_data (limite de 64 bytes): basico::packs | basico::pack::<id> | basico::packbuy::<id>
 */

const PACKS_ACESSO_DIAS = 36500;

function packsDisponiveis(array $dados_basico): array
{
    $packs = $dados_basico['packs'] ?? [];
    if (empty($packs['ativo'])) {
        return [];
    }
    return array_values(array_filter((array) ($packs['itens'] ?? []), static function ($p): bool {
        return is_array($p) && ($p['ativo'] ?? true) !== false
            && trim((string) ($p['nome'] ?? '')) !== '' && (float) ($p['preco'] ?? 0) > 0;
    }));
}

function localizarPackBasico(array $dados_basico, string $id_pack): ?array
{
    foreach (packsDisponiveis($dados_basico) as $p) {
        if ((string) ($p['id'] ?? '') === $id_pack) {
            return $p;
        }
    }
    return null;
}

function rotuloPackBasico(array $pack): string
{
    return trim((string) ($pack['emoji'] ?? '') . ' ' . trim((string) ($pack['nome'] ?? 'Pack'))) . ' — R$ ' . number_format((float) ($pack['preco'] ?? 0), 2, ',', '.');
}

function enviarListaPacksBasico(string $token, $id_chat, array $dados_basico): void
{
    $itens = packsDisponiveis($dados_basico);
    if (!$itens) {
        requisicaoTelegram($token, 'sendMessage', ['chat_id' => $id_chat, 'text' => 'Nenhum pack disponível no momento.']);
        return;
    }
    $botoes = [];
    foreach ($itens as $p) {
        $botoes[] = [['text' => rotuloPackBasico($p), 'callback_data' => 'basico::pack::' . $p['id']]];
    }
    $mensagem = trim((string) ($dados_basico['packs']['mensagem'] ?? '')) ?: 'Escolha um pack:';
    requisicaoTelegram($token, 'sendMessage', ['chat_id' => $id_chat, 'text' => $mensagem, 'reply_markup' => json_encode(['inline_keyboard' => $botoes])]);
}

/** Prévia do pack (mídia + descrição) com o botão de compra. */
function enviarPreviaPackBasico(string $token, $id_chat, array $pack): void
{
    $descricao = trim((string) ($pack['descricao'] ?? ''));
    $texto = trim((string) ($pack['emoji'] ?? '') . ' ' . trim((string) $pack['nome'])) . ($descricao !== '' ? "\n\n" . $descricao : '');
    $texto .= "\n\nR$ " . number_format((float) $pack['preco'], 2, ',', '.');
    $teclado = json_encode(['inline_keyboard' => [[['text' => '💳 Comprar — R$ ' . number_format((float) $pack['preco'], 2, ',', '.'), 'callback_data' => 'basico::packbuy::' . $pack['id']]]]]);
    $tipos = ['image' => ['sendPhoto', 'photo'], 'video' => ['sendVideo', 'video'], 'audio' => ['sendAudio', 'audio']];
    $caminho = resolverCaminhoUploadSeguro((string) ($pack['midia_path'] ?? ''));
    $tipo = (string) ($pack['midia_tipo'] ?? '');
    if ($caminho && isset($tipos[$tipo])) {
        [$metodo, $campo] = $tipos[$tipo];
        $resposta = requisicaoTelegram($token, $metodo, ['chat_id' => $id_chat, 'caption' => mb_substr($texto, 0, 1000), 'reply_markup' => $teclado], [$campo => $caminho]);
        if ($resposta['ok'] ?? false) {
            return;
        }
    }
    requisicaoTelegram($token, 'sendMessage', ['chat_id' => $id_chat, 'text' => $texto, 'reply_markup' => $teclado]);
}

function gerarPixPackBasico(string $token, $id_chat, array $dados_basico, array $pack): void
{
    $pagamentos = $dados_basico['pagamentos'] ?? [];
    processarEEnviarBloco($token, $id_chat, ['properties' => [
        'type' => 'pix',
        'nome' => trim((string) $pack['nome']),
        'valor' => round((float) $pack['preco'], 2),
        'tipo_cobranca' => 'unica',
        'expiracao_minutos' => 15,
        'dias_acesso' => PACKS_ACESSO_DIAS,
        'unidade_acesso' => 'dias',
        'id_grupo' => $pack['id_grupo'] ?? '',
        'mostrar_copiar' => $pagamentos['mostrar_copiar'] ?? true,
        'mostrar_qrcode' => !empty($pagamentos['qrcode_definido']) ? !empty($pagamentos['mostrar_qrcode']) : true,
        'mostrar_confirmar' => $pagamentos['mostrar_confirmar'] ?? true,
        'msg_instrucoes' => $pagamentos['msg_instrucoes'] ?? '',
        'msg_confirmado' => $pagamentos['msg_confirmado'] ?? '',
    ]], '');
}
