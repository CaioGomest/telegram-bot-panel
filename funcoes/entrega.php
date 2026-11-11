<?php
declare(strict_types=1);

/**
 * Entrega do que foi vendido no modo Guiado (planos, packs, Upsell e Downsell): grupo do Telegram, link ou só a venda.
 *
 * Cada item guarda o próprio jeito de entregar em dados_fluxograma:
 *   entrega_tipo  'nenhuma' | 'grupo' | 'link' | 'mensagem' | 'bot'   (na oferta também 'plano' = a mesma entrega do plano oferecido)
 *   id_grupo      grupo/canal do Telegram (tipo 'grupo')
 *   link_entrega  endereço http(s) enviado ao cliente depois de pagar (tipo 'link')
 *   mensagem_entrega  texto enviado ao cliente numa mensagem própria, logo depois do aviso de pagamento aprovado (tipo 'mensagem', até ENTREGA_MENSAGEM_MAX)
 *   id_fluxo_destino  fluxo cujo bot cobra a taxa/entrega (tipo 'bot', 07/11/2026): depois de pagar o cliente recebe o link t.me do bot ligado a esse fluxo
 * Item salvo antes dessa opção não tem entrega_tipo: plano e pack entregam pelo id_grupo (ou nada) e a oferta usa a entrega do plano.
 *
 * Só funções puras (sem banco nem Telegram): quem monta o Pix e quem confirma o pagamento chama daqui.
 */

const ENTREGA_LINK_MAX = 500;
const ENTREGA_BOT_START = 'taxa';   // parâmetro do /start com que o cliente chega ao bot de destino (fica em origem_rastreio do lead)
const ENTREGA_BOT_TEXTO_PADRAO = '✅ Pagamento aprovado! Toque no botão abaixo para continuar.';
const ENTREGA_MENSAGEM_MAX = 3000;   // o mesmo limite dos textos da aba Pagamentos (PAGAMENTO_TEXTO_MAXIMO)

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
 * Link do bot ligado a um fluxo (entrega "Bot/Taxa", 07/11/2026): https://t.me/<usuario> do bot conectado ao fluxo, ou '' se o fluxo não existe, não é do mesmo dono
 * ou não tem bot conectado. Resolvido na hora de montar o Pix (não fica gravado no fluxo), então trocar o bot do fluxo vale para as próximas vendas.
 * Usa o $pdo global (como funcoes/chat.php); sem banco devolve ''. Dentro do webhook só vale fluxo do mesmo dono do bot que está atendendo.
 */
function linkBotTaxaBasico($id_fluxo): string
{
    global $pdo;
    $id = (int) $id_fluxo;
    if ($id <= 0 || !($pdo instanceof PDO)) {
        return '';
    }
    $dono = (int) (($GLOBALS['bot']['id_usuario'] ?? 0));
    try {
        $sql = "SELECT b.nome_usuario FROM bots b JOIN fluxos f ON f.id = b.id_fluxo_conectado AND f.id_usuario = b.id_usuario WHERE f.id = ?"
            . ($dono > 0 ? " AND f.id_usuario = ?" : "") . " ORDER BY b.id LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($dono > 0 ? [$id, $dono] : [$id]);
        $usuario = ltrim(trim((string) $stmt->fetchColumn()), '@');
    } catch (Throwable $e) {
        return '';
    }
    return preg_match('/^[A-Za-z0-9_]{3,64}$/', $usuario) ? 'https://t.me/' . $usuario . '?start=' . ENTREGA_BOT_START : '';
}

/**
 * Entrega Bot/Taxa: o texto do dono segue na mensagem de entrega com uma marca no fim ([[botlink:URL]]); quem envia a mensagem (pagamentoEnviarMensagemEntrega) tira a marca
 * e põe um botão "Continuar" que abre o outro bot já com o /start. A marca vai na própria coluna mensagem_entrega da venda, então não precisa de coluna nova.
 * Devolve [texto sem a marca, url ou ''].
 */
function separarLinkBotMensagemEntrega(string $texto): array
{
    if (!preg_match('/\s*\[\[botlink:(https:\/\/t\.me\/[A-Za-z0-9_]{3,64}\?start=[A-Za-z0-9_-]{1,64})\]\]\s*$/', $texto, $m)) {
        return [$texto, ''];
    }
    return [trim(substr($texto, 0, -strlen($m[0]))), $m[1]];
}

/** Mensagem de entrega (tipo 'mensagem', 27/10/2026): o texto que o cliente recebe depois de pagar, limpo e cortado em ENTREGA_MENSAGEM_MAX caracteres. */
function mensagemEntregaValida($valor): string
{
    return mb_substr(trim((string) $valor), 0, ENTREGA_MENSAGEM_MAX);
}

/**
 * Como um plano, pack ou oferta entrega, com os três jeitos: ['id_grupo' => ..., 'link_entrega' => ..., 'mensagem_entrega' => ...], só um deles preenchido (ou nenhum).
 * $plano_base só vale para oferta (Upsell/Downsell): é o plano oferecido, que dá a entrega quando a oferta não tem a própria.
 */
function entregaCompletaDoItemBasico(array $item, ?array $plano_base = null): array
{
    $nada = ['id_grupo' => '', 'link_entrega' => '', 'mensagem_entrega' => ''];
    $tipo = (string) ($item['entrega_tipo'] ?? '');
    if ($tipo === 'plano' || ($tipo === '' && $plano_base !== null)) {
        return $plano_base !== null ? entregaCompletaDoItemBasico($plano_base) : $nada;
    }
    if ($tipo === 'nenhuma') {
        return $nada;
    }
    if ($tipo === 'link') {
        return array_merge($nada, ['link_entrega' => linkEntregaValido($item['link_entrega'] ?? '')]);
    }
    if ($tipo === 'mensagem') {
        return array_merge($nada, ['mensagem_entrega' => mensagemEntregaValida($item['mensagem_entrega'] ?? '')]);
    }
    if ($tipo === 'bot') {
        // Bot/Taxa: o cliente recebe o texto do dono (ou um padrão) com um botão que abre o bot do fluxo de destino já com o /start. Sem bot conectado, não entrega nada.
        $url_bot = linkBotTaxaBasico($item['id_fluxo_destino'] ?? 0);
        if ($url_bot === '') {
            return $nada;
        }
        $texto_bot = mensagemEntregaValida($item['mensagem_entrega'] ?? '');
        return array_merge($nada, ['mensagem_entrega' => ($texto_bot !== '' ? $texto_bot : ENTREGA_BOT_TEXTO_PADRAO) . "\n[[botlink:" . $url_bot . "]]"]);
    }
    // 'grupo' ou item antigo (sem entrega_tipo): vale o id_grupo.
    return array_merge($nada, ['id_grupo' => trim((string) ($item['id_grupo'] ?? ''))]);
}

/** Só o grupo e o link (o que o Pix gravava antes da mensagem de entrega): ['id_grupo' => ..., 'link_entrega' => ...]. Quem também entrega texto usa entregaCompletaDoItemBasico. */
function entregaDoItemBasico(array $item, ?array $plano_base = null): array
{
    $entrega = entregaCompletaDoItemBasico($item, $plano_base);
    return ['id_grupo' => $entrega['id_grupo'], 'link_entrega' => $entrega['link_entrega']];
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
        if (is_array($p) && is_array($p["order_bump"] ?? null) && !empty($p["order_bump"]["ativo"])) {
            $itens[] = ['do order bump do plano' . (trim((string) ($p['nome'] ?? '')) !== '' ? ' "' . trim((string) $p['nome']) . '"' : ''), $p['order_bump'] + ['nome' => '']];
        }
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
        if (!is_array($oferta) || empty($oferta['ativo'])) {
            continue;
        }
        if ($tipo === 'upsell') {
            // Upsell em sequência: cada upsell entrega pela própria entrega.
            $sequencias_upsell = sequenciasUpsellBasico($dados);
            foreach ($sequencias_upsell as $i => $sequencia_upsell) {
                $itens[] = [$rotulo . (count($sequencias_upsell) > 1 ? ' ' . ($i + 1) : ''), $sequencia_upsell];
            }
            continue;
        }
        if ($tipo !== 'downsell') {
            $itens[] = [$rotulo, $oferta];
            continue;
        }
        // Downsell em sequência: cada sequência entrega pela própria entrega (planos marcados) ou, com "Criar planos", cada plano criado entrega pela sua.
        $sequencias = sequenciasDownsellBasico($dados);
        foreach ($sequencias as $i => $sequencia) {
            $rotulo_sequencia = $rotulo . (count($sequencias) > 1 ? ' (sequência ' . ($i + 1) . ')' : '');
            if (empty($sequencia['criar_planos'])) {
                $itens[] = [$rotulo_sequencia, $sequencia];
                continue;
            }
            foreach ((array) ($sequencia['planos_proprios'] ?? []) as $proprio) {
                $itens[] = ['do plano do Downsell', $proprio];
            }
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
        if (($item['entrega_tipo'] ?? '') === 'bot' && (int) ($item['id_fluxo_destino'] ?? 0) <= 0) {
            return 'Escolha o fluxo de destino da entrega Bot/Taxa ' . $titulo . ' (ou mude a entrega para outra opção).';
        }
        if (($item['entrega_tipo'] ?? '') === 'mensagem') {
            $texto = trim((string) ($item['mensagem_entrega'] ?? ''));
            if ($texto === '') {
                return 'Escreva a mensagem da entrega ' . $titulo . ' (ou mude a entrega para outra opção).';
            }
            if (mb_strlen($texto) > ENTREGA_MENSAGEM_MAX) {
                return 'A mensagem da entrega ' . $titulo . ' passa de ' . ENTREGA_MENSAGEM_MAX . ' caracteres.';
            }
        }
    }
    return null;
}

/**
 * Plano que existe só dentro do Upsell (27/10/2026, pedido do dono: "criar um plano só pro upsell"): ofertas.upsell.plano_proprio = {nome, valor, dias_acesso,
 * unidade_acesso}, com id_plano_destino = ID_PLANO_UPSELL_PROPRIO_BASICO. Não está em `planos`, então não aparece em "Ver Planos". A entrega é a do próprio Upsell.
 * Devolve o plano no mesmo formato dos de `planos` (id, nome, valor, dias_acesso, unidade_acesso, cobranca, entrega_*), ou null se o Upsell não usa plano próprio
 * ou ele está incompleto (sem nome ou sem valor).
 */
const ID_PLANO_UPSELL_PROPRIO_BASICO = 'up_exclusivo';
function planoProprioDoUpsellBasico(array $oferta, int $indice = 0): ?array
{
    $proprio = $oferta['plano_proprio'] ?? null;
    if ((string) ($oferta['id_plano_destino'] ?? '') !== ID_PLANO_UPSELL_PROPRIO_BASICO || !is_array($proprio)) {
        return null;
    }
    $nome = mb_substr(trim((string) ($proprio['nome'] ?? '')), 0, 80);   // o mesmo limite do campo na tela
    $valor = round((float) ($proprio['valor'] ?? 0), 2);
    if ($nome === '' || $valor <= 0) {
        return null;
    }
    $unidade = (string) ($proprio['unidade_acesso'] ?? 'dias');
    $tipo_entrega = (string) ($oferta['entrega_tipo'] ?? '');
    return [
        'id' => idPlanoProprioDoUpsellBasico($indice),
        'nome' => $nome,
        'valor' => $valor,
        'dias_acesso' => max(1, (int) ($proprio['dias_acesso'] ?? 30)),
        'unidade_acesso' => in_array($unidade, ['dias', 'horas', 'minutos'], true) ? $unidade : 'dias',
        'cobranca' => 'unica',
        // "Igual à do plano oferecido" não existe aqui (o plano é o próprio Upsell): sem grupo nem link escolhido, não entrega nada.
        'entrega_tipo' => in_array($tipo_entrega, ['grupo', 'link', 'mensagem', 'bot'], true) ? $tipo_entrega : 'nenhuma',
        'id_fluxo_destino' => (int) ($oferta['id_fluxo_destino'] ?? 0),
        'id_grupo' => (string) ($oferta['id_grupo'] ?? ''),
        'link_entrega' => (string) ($oferta['link_entrega'] ?? ''),
        'mensagem_entrega' => (string) ($oferta['mensagem_entrega'] ?? ''),
    ];
}

/**
 * Upsell em sequência (06/11/2026, pedido do Lima: "sequência de upsell, só está dando para colocar um; pagou um, vai pro outro"): vários upsells, um depois do outro.
 * O 1º é o próprio ofertas.upsell (todo fluxo salvo antes disso é uma sequência só); os outros ficam em ofertas.upsell.sequencias_extras, no mesmo formato de um upsell
 * (id_plano_destino, plano_proprio, desconto_percentual, entrega, mensagem, mídias, textos dos botões), e só valem com o 1º ligado. O cliente recebe o 1º depois de pagar
 * o plano; ao pagar um upsell, recebe o seguinte (ofertasPosPagamentoBasico). Recusar um upsell encerra a sequência.
 */
const UPSELL_MAXIMO_SEQUENCIAS = 10;
function sequenciasUpsellBasico(array $dados): array
{
    $oferta = $dados['ofertas']['upsell'] ?? null;
    if (!is_array($oferta)) {
        return [];
    }
    $primeira = $oferta;
    unset($primeira['sequencias_extras']);
    $sequencias = [$primeira];
    if (!empty($oferta['ativo']) && is_array($oferta['sequencias_extras'] ?? null)) {
        foreach (array_slice(array_values($oferta['sequencias_extras']), 0, UPSELL_MAXIMO_SEQUENCIAS - 1) as $extra) {
            if (is_array($extra)) {
                $sequencias[] = $extra;
            }
        }
    }
    return $sequencias;
}
/** Id do plano próprio de um upsell da sequência: o de sempre no 1º ("up_exclusivo") e "up_exclusivo_<posição + 1>" nos demais (o callback do botão leva esse id). */
function idPlanoProprioDoUpsellBasico(int $indice): string
{
    return $indice <= 0 ? ID_PLANO_UPSELL_PROPRIO_BASICO : ID_PLANO_UPSELL_PROPRIO_BASICO . '_' . ($indice + 1);
}

/** Desconto (%) de uma oferta, de 0 a 100. Upsell com plano próprio não tem desconto: o valor digitado já é o preço. */
function descontoDaOfertaBasico(array $oferta): float
{
    if ((string) ($oferta['id_plano_destino'] ?? '') === ID_PLANO_UPSELL_PROPRIO_BASICO && is_array($oferta['plano_proprio'] ?? null)) {
        return 0.0;
    }
    return max(0.0, min(100.0, (float) ($oferta['desconto_percentual'] ?? 0)));
}

/** Desconto em R$ (ofertas.downsell.desconto_tipo = 'fixo' + desconto_valor); 0 quando o desconto é em %, que é o padrão e o jeito de todo fluxo antigo. */
function descontoFixoDaOfertaBasico(array $oferta): float
{
    if (($oferta['desconto_tipo'] ?? '') !== 'fixo') {
        return 0.0;
    }
    if ((string) ($oferta['id_plano_destino'] ?? '') === ID_PLANO_UPSELL_PROPRIO_BASICO || str_starts_with((string) ($oferta['id_plano_destino'] ?? ''), ID_PLANO_UPSELL_PROPRIO_BASICO . '_')) {
        return 0.0;   // Upsell com plano criado só para ele: o valor digitado já é o preço
    }
    return max(0.0, round((float) ($oferta['desconto_valor'] ?? 0), 2));
}

/** Preço de um plano com o desconto da oferta, em % ou em R$ fixo, nunca abaixo de zero. Oferta vazia (ex.: plano criado no Downsell) = sem desconto. */
function valorComDescontoDaOfertaBasico(array $oferta, float $valor): float
{
    $fixo = descontoFixoDaOfertaBasico($oferta);
    if ($fixo > 0) {
        return max(0.0, round($valor - $fixo, 2));
    }
    return round($valor * (1 - descontoDaOfertaBasico($oferta) / 100), 2);
}

/** Como o desconto aparece nas mensagens: "30%" ou "R$ 5,00"; vazio sem desconto. */
function textoDescontoDaOfertaBasico(array $oferta): string
{
    $fixo = descontoFixoDaOfertaBasico($oferta);
    if ($fixo > 0) {
        return 'R$ ' . number_format($fixo, 2, ',', '.');
    }
    $percentual = descontoDaOfertaBasico($oferta);
    return $percentual > 0 ? rtrim(rtrim(number_format($percentual, 2, ',', ''), '0'), ',') . '%' : '';
}

/**
 * Confere, antes de salvar, o plano próprio de um Upsell ligado: nome e valor maiores que zero e um tempo de acesso válido.
 * Retorna a mensagem de erro (pronta para mostrar) ou null se está tudo certo (ou se o Upsell usa um plano que já existe).
 */
function validarPlanoProprioUpsellBasico(array $dados): ?string
{
    $oferta = $dados['ofertas']['upsell'] ?? null;
    if (!is_array($oferta) || empty($oferta['ativo'])) {
        return null;
    }
    $sequencias = sequenciasUpsellBasico($dados);
    foreach ($sequencias as $i => $sequencia) {
        if ((string) ($sequencia['id_plano_destino'] ?? '') !== ID_PLANO_UPSELL_PROPRIO_BASICO) {
            continue;
        }
        $rotulo = 'Upsell' . (count($sequencias) > 1 ? ' ' . ($i + 1) : '');
        $proprio = is_array($sequencia['plano_proprio'] ?? null) ? $sequencia['plano_proprio'] : [];
        if (trim((string) ($proprio['nome'] ?? '')) === '') {
            return 'Dê um nome ao plano do ' . $rotulo . ' (ou escolha um plano que já existe).';
        }
        if (!((float) ($proprio['valor'] ?? 0) > 0)) {
            return 'O plano do ' . $rotulo . ' "' . trim((string) $proprio['nome']) . '" precisa ter valor maior que zero.';
        }
        if ((int) ($proprio['dias_acesso'] ?? 0) < 1) {
            return 'O plano do ' . $rotulo . ' "' . trim((string) $proprio['nome']) . '" precisa ter um tempo de acesso de pelo menos 1.';
        }
    }
    // Upsell 2 em diante que escolhe um plano da lista: o plano precisa estar escolhido.
    foreach ($sequencias as $i => $sequencia) {
        if ($i > 0 && trim((string) ($sequencia['id_plano_destino'] ?? '')) === '') {
            return 'Escolha o plano oferecido no Upsell ' . ($i + 1) . ' (ou crie um plano só para ele).';
        }
    }
    return null;
}

/**
 * Downsell em sequência (27/10/2026, pedido do dono, no jeito das "Sequências de Downsell" da SharkBot): várias ofertas de downsell, cada uma com o próprio tempo, mensagem,
 * mídia, desconto, planos e entrega. A 1ª sequência é o próprio ofertas.downsell (todo fluxo salvo antes disso é uma sequência só); as outras ficam em
 * ofertas.downsell.sequencias_extras (até DOWNSELL_MAXIMO_SEQUENCIAS no total; o desconto de cada sequência é em % ou em R$ fixo), no mesmo formato de uma oferta, e só valem com quando = 'depois_pix' (o Pix
 * não pago é que dispara a sequência). Cada sequência escolhe os planos de duas maneiras: marcando os que já existem na aba Planos (planos_oferecidos, com o desconto
 * da sequência) ou, com criar_planos ligado, só com planos criados ali mesmo (planos_proprios, que não aparecem em "Ver Planos", valem pelo valor digitado, sem
 * desconto, e cada um tem a própria entrega).
 */
const DOWNSELL_MAXIMO_SEQUENCIAS = 50;   // sem limite prático (era 5): só trava um JSON absurdo; o callback_data cabe até a posição 4950
const DOWNSELL_PLANOS_PROPRIOS_MAXIMO = 10;
/** Posição do botão no callback_data ("p<posição>"): sequência * 100 + posição na sequência; os planos criados começam em +50 (os marcados usam 0 a 49). */
const DOWNSELL_POSICAO_SEQUENCIA = 100;
const DOWNSELL_POSICAO_PROPRIOS = 50;

/** As sequências do Downsell, na ordem da tela: [0] = o próprio ofertas.downsell; as demais só com "depois do Pix". Vazio se não há Downsell. */
function sequenciasDownsellBasico(array $dados): array
{
    $oferta = $dados['ofertas']['downsell'] ?? null;
    if (!is_array($oferta)) {
        return [];
    }
    $primeira = $oferta;
    unset($primeira['sequencias_extras']);
    $sequencias = [$primeira];
    if (($oferta['quando'] ?? '') === 'depois_pix' && is_array($oferta['sequencias_extras'] ?? null)) {
        foreach (array_slice(array_values($oferta['sequencias_extras']), 0, DOWNSELL_MAXIMO_SEQUENCIAS - 1) as $extra) {
            if (is_array($extra)) {
                $sequencias[] = $extra;
            }
        }
    }
    return $sequencias;
}

/**
 * Os planos criados dentro de uma sequência do Downsell, no mesmo formato dos de `planos` e com a posição na lista salva como chave (plano incompleto, sem nome ou
 * sem valor, não é oferecido mas a posição dos outros não muda). O id ("dn<sequência>_<posição>") é só deles; `exclusivo_oferta` avisa o motor de que valem pelo valor
 * digitado (sem o desconto da sequência) e entregam pela própria entrega. Sequência sem "criar_planos" devolve [].
 */
function planosProprioDoDownsellBasico(array $sequencia, int $indice_sequencia): array
{
    if (empty($sequencia['criar_planos']) || !is_array($sequencia['planos_proprios'] ?? null)) {
        return [];
    }
    $planos = [];
    foreach (array_slice(array_values($sequencia['planos_proprios']), 0, DOWNSELL_PLANOS_PROPRIOS_MAXIMO) as $i => $proprio) {
        if (!is_array($proprio)) {
            continue;
        }
        $nome = mb_substr(trim((string) ($proprio['nome'] ?? '')), 0, 80);   // o mesmo limite do campo na tela
        $valor = round((float) ($proprio['valor'] ?? 0), 2);
        if ($nome === '' || $valor <= 0) {
            continue;
        }
        $unidade = (string) ($proprio['unidade_acesso'] ?? 'dias');
        $tipo_entrega = (string) ($proprio['entrega_tipo'] ?? '');
        $planos[$i] = [
            'id' => 'dn' . ($indice_sequencia + 1) . '_' . ($i + 1),
            'nome' => $nome,
            'valor' => $valor,
            'dias_acesso' => max(1, (int) ($proprio['dias_acesso'] ?? 30)),
            'unidade_acesso' => in_array($unidade, ['dias', 'horas', 'minutos'], true) ? $unidade : 'dias',
            'cobranca' => 'unica',
            'entrega_tipo' => in_array($tipo_entrega, ['grupo', 'link', 'mensagem', 'bot'], true) ? $tipo_entrega : 'nenhuma',
            'id_fluxo_destino' => (int) ($proprio['id_fluxo_destino'] ?? 0),
            'id_grupo' => (string) ($proprio['id_grupo'] ?? ''),
            'link_entrega' => (string) ($proprio['link_entrega'] ?? ''),
            'mensagem_entrega' => (string) ($proprio['mensagem_entrega'] ?? ''),
            'exclusivo_oferta' => true,
        ];
    }
    return $planos;
}

/**
 * Confere, antes de salvar, os planos criados no Downsell: cada sequência com "Criar planos" ligado precisa de pelo menos 1 plano (até DOWNSELL_PLANOS_PROPRIOS_MAXIMO),
 * e cada plano de nome, valor maior que zero e tempo de acesso válidos. Retorna a mensagem de erro (pronta para mostrar) ou null se está tudo certo.
 */
function validarPlanosProprioDownsellBasico(array $dados): ?string
{
    $oferta = $dados['ofertas']['downsell'] ?? null;
    if (!is_array($oferta) || empty($oferta['ativo'])) {
        return null;
    }
    $sequencias = sequenciasDownsellBasico($dados);
    foreach ($sequencias as $i => $sequencia) {
        if (empty($sequencia['criar_planos'])) {
            continue;
        }
        $rotulo = 'Downsell' . (count($sequencias) > 1 ? ' ' . ($i + 1) : '');
        $lista = is_array($sequencia['planos_proprios'] ?? null) ? array_values($sequencia['planos_proprios']) : [];
        if (!$lista) {
            return 'Crie pelo menos 1 plano no ' . $rotulo . ' (ou desligue "Criar planos" e marque os planos que já existem).';
        }
        if (count($lista) > DOWNSELL_PLANOS_PROPRIOS_MAXIMO) {
            return 'O ' . $rotulo . ' aceita no máximo ' . DOWNSELL_PLANOS_PROPRIOS_MAXIMO . ' planos criados.';
        }
        foreach ($lista as $n => $proprio) {
            $proprio = is_array($proprio) ? $proprio : [];
            $nome = trim((string) ($proprio['nome'] ?? ''));
            if ($nome === '') {
                return 'Dê um nome ao plano ' . ($n + 1) . ' do ' . $rotulo . '.';
            }
            if (!((float) ($proprio['valor'] ?? 0) > 0)) {
                return 'O plano "' . $nome . '" do ' . $rotulo . ' precisa ter valor maior que zero.';
            }
            if ((int) ($proprio['dias_acesso'] ?? 0) < 1) {
                return 'O plano "' . $nome . '" do ' . $rotulo . ' precisa ter um tempo de acesso de pelo menos 1.';
            }
        }
    }
    return null;
}
