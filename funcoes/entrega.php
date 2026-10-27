<?php
declare(strict_types=1);

/**
 * Entrega do que foi vendido no modo Guiado (planos, packs, Upsell e Downsell): grupo do Telegram, link ou só a venda.
 *
 * Cada item guarda o próprio jeito de entregar em dados_fluxograma:
 *   entrega_tipo  'nenhuma' | 'grupo' | 'link'   (na oferta também 'plano' = a mesma entrega do plano oferecido)
 *   id_grupo      grupo/canal do Telegram (tipo 'grupo')
 *   link_entrega  endereço http(s) enviado ao cliente depois de pagar (tipo 'link')
 * Item salvo antes dessa opção não tem entrega_tipo: plano e pack entregam pelo id_grupo (ou nada) e a oferta usa a entrega do plano.
 *
 * Só funções puras (sem banco nem Telegram): quem monta o Pix e quem confirma o pagamento chama daqui.
 */

const ENTREGA_LINK_MAX = 500;

/** Link de entrega aceito (só http/https, sem espaço, até 500 caracteres) ou '' se não serve. */
function linkEntregaValido($valor): string
{
    $link = trim((string) $valor);
    if ($link === '' || mb_strlen($link) > ENTREGA_LINK_MAX || preg_match('/[\s\x00-\x1F\x7F]/u', $link)) {
        return '';
    }
    if (!preg_match('#^https?://#i', $link) || filter_var($link, FILTER_VALIDATE_URL) === false) {
        return '';
    }
    return $link;
}

/**
 * Como um plano, pack ou oferta entrega: ['id_grupo' => ..., 'link_entrega' => ...], um dos dois (ou nenhum) preenchido.
 * $plano_base só vale para oferta (Upsell/Downsell): é o plano oferecido, que dá a entrega quando a oferta não tem a própria.
 */
function entregaDoItemBasico(array $item, ?array $plano_base = null): array
{
    $nada = ['id_grupo' => '', 'link_entrega' => ''];
    $tipo = (string) ($item['entrega_tipo'] ?? '');
    if ($tipo === 'plano' || ($tipo === '' && $plano_base !== null)) {
        return $plano_base !== null ? entregaDoItemBasico($plano_base) : $nada;
    }
    if ($tipo === 'nenhuma') {
        return $nada;
    }
    if ($tipo === 'link') {
        return ['id_grupo' => '', 'link_entrega' => linkEntregaValido($item['link_entrega'] ?? '')];
    }
    // 'grupo' ou item antigo (sem entrega_tipo): vale o id_grupo.
    return ['id_grupo' => trim((string) ($item['id_grupo'] ?? '')), 'link_entrega' => ''];
}

/**
 * Trecho a somar na mensagem de "pagamento confirmado" quando a venda é entregue por link ('' se não é).
 * $formato é o parse_mode da mensagem: 'markdown' (Markdown clássico do Telegram) ou 'html'. O link vai escapado: um "_" ou "*" no endereço
 * sem escape faz o Telegram recusar a mensagem inteira, e o cliente ficaria sem o aviso de pagamento e sem o link.
 */
function textoEntregaLink(array $venda, string $formato): string
{
    $link = linkEntregaValido($venda['link_entrega'] ?? '');
    if ($link === '') {
        return '';
    }
    if ($formato === 'html') {
        return "\n\n🔗 <b>Seu acesso:</b>\n" . htmlspecialchars($link, ENT_QUOTES);
    }
    return "\n\n🔗 *Seu acesso:*\n" . preg_replace('/([_*`\[])/', '\\\\$1', $link);
}

/**
 * Confere a entrega de cada plano, pack e oferta ativa do Fluxo Guiado antes de salvar: "link" precisa de um endereço http(s) válido.
 * Retorna a mensagem de erro (pronta para mostrar) ou null se está tudo certo.
 */
function validarEntregasFluxoGuiado(array $dados): ?string
{
    $itens = [];
    foreach ((array) ($dados['planos'] ?? []) as $p) {
        $itens[] = ['do plano', $p];
    }
    if (!empty($dados['packs']['ativo'])) {
        foreach ((array) ($dados['packs']['itens'] ?? []) as $k) {
            if (is_array($k) && ($k['ativo'] ?? true) !== false) {
                $itens[] = ['do pack', $k];
            }
        }
    }
    foreach (['upsell' => 'do Upsell', 'downsell' => 'do Downsell'] as $tipo => $rotulo) {
        $oferta = $dados['ofertas'][$tipo] ?? null;
        if (is_array($oferta) && !empty($oferta['ativo'])) {
            $itens[] = [$rotulo, $oferta];
        }
    }
    foreach ($itens as [$rotulo, $item]) {
        if (!is_array($item)) {
            continue;
        }
        $nome = trim((string) ($item['nome'] ?? ''));
        $titulo = $rotulo . ($nome !== '' ? ' "' . $nome . '"' : '');
        if (($item['entrega_tipo'] ?? '') === 'link' && linkEntregaValido($item['link_entrega'] ?? '') === '') {
            return 'A entrega por link ' . $titulo . ' precisa de um endereço completo, começando com https:// (sem espaços).';
        }
        if (($item['entrega_tipo'] ?? '') === 'grupo' && trim((string) ($item['id_grupo'] ?? '')) === '') {
            return 'Escolha o grupo da entrega ' . $titulo . ' (ou mude a entrega para outra opção).';
        }
    }
    return null;
}
