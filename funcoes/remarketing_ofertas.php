<?php
declare(strict_types=1);

require_once __DIR__ . '/remarketing.php';

/**
 * O que acontece quando o lead toca num botão da campanha de remarketing (webhook.php, callback "rmk::...").
 *
 *   rmk::<campanha>::<plano>::<variante>              toca num plano
 *   rmk::bp::<campanha>::<plano>::<variante>::1|0     responde ao order bump (1 = quero o extra, 0 = só o plano)
 *
 * Sem estado no banco: o que já foi escolhido viaja no callback_data (sempre abaixo de 64 bytes). O Pix sai pelo mesmo caminho do Pix do
 * Guiado (processarEEnviarBloco), então gateway, comissão, entrega por grupo/link e confirmação de pagamento são os de sempre. A venda fica
 * ligada à campanha em remarketing_vendas (é daí que sai a receita do cartão da campanha).
 * Precisa de funcoes/fluxo_blocos.php e funcoes/guiado_ofertas.php já carregados (o webhook carrega).
 */

/** O bot faz parte da campanha? (bots congelados ao iniciar; campanha antiga: o bot_id dela.) */
function remarketingCampanhaDoBot(array $campanha, int $bot_id): bool
{
    $ids = remarketingIdsInteiros((string) ($campanha['bots_ids'] ?? ''));
    if (!$ids && !empty($campanha['bot_id'])) {
        $ids = [(int) $campanha['bot_id']];
    }
    return in_array($bot_id, $ids, true);
}

function remarketingLocalizarPlano(array $ofertas, string $id_plano): ?array
{
    foreach ($ofertas['planos'] as $p) {
        if ((string) ($p['id'] ?? '') === $id_plano) {
            return $p;
        }
    }
    return null;
}

/** Aba Pagamentos do fluxo guiado do bot (como o Pix aparece para o cliente); fluxo visual ou sem fluxo: o padrão. */
function remarketingPagamentosDoBot(PDO $pdo, int $bot_id): array
{
    $st = $pdo->prepare('SELECT f.modo, f.dados_fluxograma FROM bots b JOIN fluxos f ON f.id = b.id_fluxo_conectado WHERE b.id = ?');
    $st->execute([$bot_id]);
    $f = $st->fetch(PDO::FETCH_ASSOC);
    if (!$f || ($f['modo'] ?? '') !== 'basico') {
        return [];
    }
    $dados = json_decode((string) $f['dados_fluxograma'], true);
    return is_array($dados) && is_array($dados['pagamentos'] ?? null) ? $dados['pagamentos'] : [];
}

/** Gera o Pix do plano (com ou sem o extra do order bump) e liga a venda à campanha. */
function gerarPixRemarketing(string $token, $id_chat, array $bot, array $campanha, array $plano, int $variante, bool $com_bump): void
{
    garantirConexaoBanco();
    $pdo = $GLOBALS['pdo'];
    $ofertas = remarketingOfertasDaCampanha($campanha);
    $valor = (float) $plano['valor'];
    $nome = (string) $plano['nome'];
    $bump = $ofertas['order_bump'];
    if ($com_bump && $bump['ativo'] && $bump['valor'] > 0) {
        $valor = round($valor + $bump['valor'], 2);
        $nome .= ' + ' . ($bump['nome'] !== '' ? $bump['nome'] : 'Extra');
    }
    if ($valor <= 0) {
        requisicaoTelegram($token, 'sendMessage', ['chat_id' => $id_chat, 'text' => 'Não foi possível gerar o pagamento deste plano. Fale com o suporte.']);
        return;
    }
    $GLOBALS['ultima_venda_pix_id'] = 0;
    processarEEnviarBloco($token, $id_chat, ['properties' => [
        'type' => 'pix',
        'nome' => $nome,
        'valor' => $valor,
        'tipo_cobranca' => 'unica',
        'periodicidade' => null,
        'id_produto' => 'rmk_' . (int) $campanha['id'] . '_' . $plano['id'],
        'expiracao_minutos' => 15,
        'dias_acesso' => (int) ($plano['dias'] ?? 30),
        'unidade_acesso' => 'dias',
        'id_grupo' => $plano['entrega_tipo'] === 'grupo' ? (string) $plano['id_grupo'] : '',
        'link_entrega' => $plano['entrega_tipo'] === 'link' ? (string) $plano['link_entrega'] : '',
        'mensagem_entrega' => $plano['entrega_tipo'] === 'mensagem' ? (string) ($plano['mensagem_entrega'] ?? '') : '',
    ] + propriedadesPagamentoBasico(remarketingPagamentosDoBot($pdo, (int) $bot['id']))], '');
    $id_venda = (int) ($GLOBALS['ultima_venda_pix_id'] ?? 0);
    if ($id_venda > 0) {
        try {
            garantirConexaoBanco();
            $GLOBALS['pdo']->prepare('INSERT IGNORE INTO remarketing_vendas (venda_id, campanha_id, variante) VALUES (?, ?, ?)')
                ->execute([$id_venda, (int) $campanha['id'], $variante]);
        } catch (\Throwable $e) {
            error_log('[remarketing] venda ' . $id_venda . ' sem ligação com a campanha: ' . $e->getMessage());
        }
    }
}

/** Mensagem do order bump (texto, mídia opcional) com os botões "quero" e "não, obrigado". */
function enviarOrderBumpRemarketing(string $token, $id_chat, array $campanha, array $plano, int $variante): void
{
    $bump = remarketingOfertasDaCampanha($campanha)['order_bump'];
    $extra = 'R$ ' . number_format($bump['valor'], 2, ',', '.');
    $texto = trim($bump['descricao']) !== '' ? $bump['nome'] . "\n\n" . trim($bump['descricao']) : 'Adicione ' . $bump['nome'] . ' ao seu pedido.';
    $texto .= "\n\n+ " . $extra . ' no seu pedido.';
    $base = 'rmk::bp::' . (int) $campanha['id'] . '::' . $plano['id'] . '::' . $variante;
    $teclado = json_encode(['inline_keyboard' => [
        [['text' => 'Sim, quero! (+' . $extra . ')', 'callback_data' => $base . '::1']],
        [['text' => 'Não, obrigado', 'callback_data' => $base . '::0']],
    ]]);
    $tipo = $bump['midia_tipo'] === 'foto' ? 'image' : ($bump['midia_tipo'] === 'video' ? 'video' : 'none');
    enviarMensagemComMidiaBasico($token, $id_chat, $texto, $teclado, $tipo, $bump['midia_caminho']);
}

/** Trata o callback "rmk::...". Nunca lança: o webhook responde 200 de qualquer jeito. */
function tratarCliqueRemarketing(PDO $pdo, string $token, array $bot, string $id_chat, string $texto): void
{
    $indisponivel = static function () use ($token, $id_chat): void {
        requisicaoTelegram($token, 'sendMessage', ['chat_id' => $id_chat, 'text' => 'Essa oferta não está mais disponível.']);
    };
    try {
        // Formato estrito: nada de "3abc" virando campanha 3. Plano: rmk::<campanha>::<plano>::<variante>; order bump: rmk::bp::<campanha>::<plano>::<variante>::1|0.
        if (!preg_match('/^rmk::(?:(bp)::)?(\d{1,10})::([a-z0-9]{1,8})::(\d)(?:::([01]))?$/', $texto, $m) || (($m[1] ?? '') === 'bp') !== isset($m[5])) {
            $indisponivel();
            return;
        }
        $eh_bump = ($m[1] ?? '') === 'bp';
        $id_campanha = (int) $m[2];
        $id_plano = $m[3];
        $variante = max(0, min(REMARKETING_VARIANTES_MAX - 1, (int) $m[4]));
        $partes = [4 => $m[5] ?? ''];
        $st = $pdo->prepare('SELECT * FROM remarketing_campanhas WHERE id = ? AND id_usuario = ?');
        $st->execute([$id_campanha, (int) $bot['id_usuario']]);
        $campanha = $st->fetch(PDO::FETCH_ASSOC);
        if (!$campanha || !remarketingCampanhaDoBot($campanha, (int) $bot['id'])) {
            $indisponivel();
            return;
        }
        $ofertas = remarketingOfertasDaCampanha($campanha);
        $plano = remarketingLocalizarPlano($ofertas, $id_plano);
        if (!$plano) {
            $indisponivel();
            return;
        }
        if ($eh_bump) {
            gerarPixRemarketing($token, $id_chat, $bot, $campanha, $plano, $variante, ($partes[4] ?? '') === '1');
            return;
        }
        if ($ofertas['order_bump']['ativo'] && $ofertas['order_bump']['valor'] > 0) {
            enviarOrderBumpRemarketing($token, $id_chat, $campanha, $plano, $variante);
            return;
        }
        gerarPixRemarketing($token, $id_chat, $bot, $campanha, $plano, $variante, false);
    } catch (\Throwable $e) {
        error_log('[remarketing] clique ' . $texto . ': ' . $e->getMessage());
    }
}

/** A venda veio de uma campanha com order bump próprio? Então o order bump padrão do fluxo não é oferecido depois do pagamento (o da campanha substitui). */
function remarketingVendaTemBumpProprio(PDO $pdo, int $id_venda): bool
{
    $st = $pdo->prepare('SELECT c.ofertas FROM remarketing_vendas rv JOIN remarketing_campanhas c ON c.id = rv.campanha_id WHERE rv.venda_id = ?');
    $st->execute([$id_venda]);
    $json = $st->fetchColumn();
    return $json !== false && !empty(remarketingOfertasDaCampanha(['ofertas' => $json])['order_bump']['ativo']);
}
