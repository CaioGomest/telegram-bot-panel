<?php
declare(strict_types=1);

require_once __DIR__ . '/entrega.php';

/**
 * Campanhas de remarketing em etapas (tela estilo SharkBot): fluxo, público, período, mensagem (com variantes A/B), oferta e entrega.
 *
 * Uma campanha pertence a um FLUXO e é enviada pelos bots vinculados a ele (a lista de bots é congelada quando a campanha começa).
 * Campanha antiga (feita antes das etapas) tem só bot_id e id_fluxo vazio: continua valendo, com o bot dela.
 *
 * Aqui só ficam as regras (sem tela e sem Telegram): público em SQL, período, validação do que o assistente manda, variáveis da
 * mensagem e o teclado das ofertas. Quem usa: remarketing.php (API da tela), cron/cron_remarketing.php e funcoes/remarketing_ofertas.php.
 * O chamador carrega funcoes/operacao.php antes (condicaoLeadPagou).
 */

const REMARKETING_NOME_MAX = 50;
const REMARKETING_MENSAGEM_MIN = 10;
const REMARKETING_MENSAGEM_MAX = 4096;      // teto de uma mensagem do Telegram
const REMARKETING_LEGENDA_MAX = 1024;       // teto da legenda quando a mensagem leva foto ou vídeo
const REMARKETING_VARIANTES_MAX = 3;        // A + até 2 variantes (B e C)
const REMARKETING_PLANOS_MAX = 6;           // um botão por plano na mensagem
const REMARKETING_CAMPANHAS_MAX = 300;      // por usuário (rascunhos incluídos)
const REMARKETING_BOTS_MAX = 10;            // bots por fluxo (mesmo teto do vínculo bot-fluxo)

/** Quem recebe: chave => [rótulo, descrição]. A chave é o que fica gravado em remarketing_campanhas.audiencia. */
const REMARKETING_SEGMENTOS = [
    'todos'          => ['Todos os leads', 'Todos os contatos'],
    'nao_comprou'    => ['Não compraram', 'Nunca fizeram compra'],
    'comprou'        => ['Já compraram', 'Pagaram ao menos uma vez'],
    'pix_gerado'     => ['Pix gerado', 'Gerou mas não pagou'],
    'expirados'      => ['Expirados', 'VIP que não renovou'],
    'membros_ativos' => ['Membros ativos', 'VIP com acesso ativo'],
];

/** Período da data de ENTRADA do lead. */
const REMARKETING_PERIODOS = ['total' => 'Total', 'hoje' => 'Hoje', 'ontem' => 'Ontem', '7d' => '7 dias', '30d' => '30 dias', 'custom' => 'Escolher'];

const REMARKETING_STATUS_ROTULOS = [
    'rascunho'    => 'Rascunho',
    'pendente'    => 'Agendada',
    'processando' => 'Ativa',
    'concluida'   => 'Concluída',
    'falha'       => 'Falha',
];

/** Quanto tempo o plano dá de acesso: chave do seletor => dias (vitalício = 36500, o mesmo corte que a confirmação de pagamento usa). */
const REMARKETING_ACESSO_DIAS = ['semanal' => 7, 'mensal' => 30, 'trimestral' => 90, 'semestral' => 180, 'anual' => 365, 'vitalicio' => 36500];

/** Variáveis que a mensagem aceita (só as que o sistema realmente sabe preencher: não guardamos e-mail nem estado do lead). */
const REMARKETING_VARIAVEIS = ['{nome}', '{saudacao}', '{usuario}'];

function remarketingRotuloSegmento(string $segmento): string
{
    return REMARKETING_SEGMENTOS[$segmento][0] ?? $segmento;
}

/** Texto vindo do cliente: só escalar vira texto; lista ou objeto (entrada hostil) vira vazio em vez de "Array" e aviso do PHP. */
function remarketingTxt($v): string
{
    return is_scalar($v) ? (string) $v : '';
}

/** Id de bot só com inteiros positivos, sem repetição e na ordem (vira "IN (1,2)" no SQL, por isso não pode vir lixo). */
function remarketingIdsInteiros($ids): array
{
    $saida = [];
    foreach (is_array($ids) ? $ids : explode(',', (string) $ids) as $id) {
        $n = (int) $id;
        if ($n > 0 && !in_array($n, $saida, true)) {
            $saida[] = $n;
        }
    }
    return $saida;
}

// ============================ período ============================

/** 'Y-m-d' válido (data de verdade) ou ''. */
function remarketingDataValida($valor): string
{
    $v = trim(remarketingTxt($valor));
    $d = DateTime::createFromFormat('!Y-m-d', $v);
    return ($d && $d->format('Y-m-d') === $v) ? $v : '';
}

/**
 * Confere o período vindo da tela. Devolve ['tipo', 'de', 'ate', 'erro']: 'de'/'ate' só valem em 'custom' (datas Y-m-d);
 * nos outros tipos ficam '' (o intervalo sai de remarketingIntervaloPeriodo).
 */
function remarketingNormalizarPeriodo($tipo, $de = '', $ate = ''): array
{
    $tipo = remarketingTxt($tipo);
    if (!isset(REMARKETING_PERIODOS[$tipo])) {
        return ['tipo' => 'total', 'de' => '', 'ate' => '', 'erro' => 'Período inválido.'];
    }
    if ($tipo !== 'custom') {
        return ['tipo' => $tipo, 'de' => '', 'ate' => '', 'erro' => null];
    }
    $de_ok = remarketingDataValida($de);
    $ate_ok = remarketingDataValida($ate);
    if ($de_ok === '' || $ate_ok === '') {
        return ['tipo' => 'custom', 'de' => $de_ok, 'ate' => $ate_ok, 'erro' => 'Informe a data inicial e a final do período.'];
    }
    if ($de_ok > $ate_ok) {
        return ['tipo' => 'custom', 'de' => $de_ok, 'ate' => $ate_ok, 'erro' => 'A data inicial não pode ser depois da final.'];
    }
    return ['tipo' => 'custom', 'de' => $de_ok, 'ate' => $ate_ok, 'erro' => null];
}

/**
 * Janela de datas do período como ['Y-m-d H:i:s', 'Y-m-d H:i:s'], ou null para "Total" (sem filtro).
 * "7 dias" e "30 dias" são os últimos dias contando hoje.
 */
function remarketingIntervaloPeriodo(string $tipo, string $de = '', string $ate = '', ?int $agora = null): ?array
{
    $agora = $agora ?? time();
    $dia = static fn(int $ts): string => date('Y-m-d', $ts);
    switch ($tipo) {
        case 'hoje':
            return [$dia($agora) . ' 00:00:00', $dia($agora) . ' 23:59:59'];
        case 'ontem':
            $ontem = $dia(strtotime('-1 day', $agora));
            return [$ontem . ' 00:00:00', $ontem . ' 23:59:59'];
        case '7d':
            return [$dia(strtotime('-6 days', $agora)) . ' 00:00:00', $dia($agora) . ' 23:59:59'];
        case '30d':
            return [$dia(strtotime('-29 days', $agora)) . ' 00:00:00', $dia($agora) . ' 23:59:59'];
        case 'custom':
            if (remarketingDataValida($de) !== '' && remarketingDataValida($ate) !== '') {
                return [$de . ' 00:00:00', $ate . ' 23:59:59'];
            }
            return null;
        default:
            return null;
    }
}

// ============================ público ============================

/**
 * Condição SQL (sobre "leads l") de quem recebe a campanha, mais os parâmetros nomeados.
 * Nada vindo do cliente entra no SQL cru: ids viram inteiros e o resto é parâmetro.
 */
function remarketingCondicaoPublico(string $segmento, $bots_ids, ?string $inicio, ?string $fim): array
{
    $ids = remarketingIdsInteiros($bots_ids);
    if ($ids === [] || !isset(REMARKETING_SEGMENTOS[$segmento])) {
        return ['0 = 1', []];
    }
    $sql = 'l.bot_id IN (' . implode(',', $ids) . ')';
    $params = [];
    $membro = static fn(string $status): string => "EXISTS (SELECT 1 FROM membros_grupos m WHERE m.id_telegram = l.id_telegram AND m.bot_id = l.bot_id AND m.status = '$status')";
    switch ($segmento) {
        case 'nao_comprou':
            $sql .= ' AND ' . condicaoLeadPagou(false);
            break;
        case 'comprou':
            $sql .= ' AND ' . condicaoLeadPagou(true);
            break;
        case 'pix_gerado':
            $sql .= ' AND ' . condicaoLeadPagou(false)
                . " AND EXISTS (SELECT 1 FROM vendas v WHERE v.id_telegram = l.id_telegram AND v.bot_id = l.bot_id AND v.status IN ('gerado', 'expirado', 'cancelado'))";
            break;
        case 'expirados':
            $sql .= ' AND ' . $membro('expirado') . ' AND NOT ' . $membro('ativo');
            break;
        case 'membros_ativos':
            $sql .= ' AND ' . $membro('ativo');
            break;
    }
    if ($inicio !== null && $fim !== null) {
        $sql .= ' AND l.criado_em BETWEEN :rm_ini AND :rm_fim';
        $params['rm_ini'] = $inicio;
        $params['rm_fim'] = $fim;
    }
    return [$sql, $params];
}

/** Bots do usuário vinculados ao fluxo: [['id' => 1, 'nome' => '...'], ...]. */
function remarketingBotsDoFluxo(PDO $pdo, int $id_usuario, int $id_fluxo): array
{
    $stmt = $pdo->prepare("SELECT id, COALESCE(primeiro_nome, nome_usuario) AS nome FROM bots WHERE id_usuario = ? AND id_fluxo_conectado = ? ORDER BY id LIMIT " . REMARKETING_BOTS_MAX);
    $stmt->execute([$id_usuario, $id_fluxo]);
    return array_map(static fn($b) => ['id' => (int) $b['id'], 'nome' => (string) $b['nome']], $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/** Quantos leads recebem (estimativa do assistente e total da campanha). */
function remarketingContarPublico(PDO $pdo, $bots_ids, string $segmento, ?string $inicio, ?string $fim): int
{
    [$onde, $params] = remarketingCondicaoPublico($segmento, $bots_ids, $inicio, $fim);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM leads l WHERE $onde");
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

// ============================ mensagem ============================

function remarketingSaudacao(?int $hora = null): string
{
    $hora = $hora ?? (int) date('G');
    return $hora < 12 ? 'Bom dia' : ($hora < 18 ? 'Boa tarde' : 'Boa noite');
}

/**
 * Troca {nome}, {saudacao} e {usuario} pelos dados do lead (a mensagem do Telegram vai em HTML, então o que é do lead sai escapado).
 * Sem nome vira "cliente"; sem @usuário, vira o nome.
 */
function remarketingAplicarVariaveis(string $texto, array $lead, ?int $hora = null): string
{
    if (strpos($texto, '{') === false) {
        return $texto;
    }
    $nome = trim((string) ($lead['nome'] ?? '')) ?: 'cliente';
    $usuario = trim((string) ($lead['nome_usuario_telegram'] ?? ''));
    return strtr($texto, [
        '{nome}'     => htmlspecialchars($nome, ENT_NOQUOTES, 'UTF-8'),
        '{saudacao}' => remarketingSaudacao($hora),
        '{usuario}'  => $usuario !== '' ? '@' . htmlspecialchars(ltrim($usuario, '@'), ENT_NOQUOTES, 'UTF-8') : htmlspecialchars($nome, ENT_NOQUOTES, 'UTF-8'),
    ]);
}

/** Caminho "uploads/arquivo.ext" que de fato existe dentro de uploads/ (o do servidor de verdade), ou null. */
function remarketingCaminhoUploadSeguro(string $caminho): ?string
{
    if ($caminho === '' || strpos($caminho, 'uploads/') !== 0) {
        return null;
    }
    $base_real = realpath(dirname(__DIR__) . '/uploads');
    $real = $base_real === false ? false : realpath(dirname(__DIR__) . '/' . $caminho);
    if ($real === false || strpos($real, $base_real . DIRECTORY_SEPARATOR) !== 0) {
        return null;
    }
    return $real;
}

/** Forma do caminho (sem olhar o disco): "uploads/nome.ext", sem subpasta nem "..". */
function remarketingCaminhoUploadValido(string $caminho): bool
{
    return (bool) preg_match('#^uploads/[A-Za-z0-9._-]+$#', $caminho) && strpos($caminho, '..') === false;
}

/** Variante (mensagem + mídia opcional) conferida: ['mensagem' => , 'midia_caminho' => ?, 'midia_tipo' => ?] ou ['erro' => ]. */
function remarketingNormalizarVariante($v, string $nome): array
{
    $v = is_array($v) ? $v : [];
    $mensagem = trim(remarketingTxt($v['mensagem'] ?? ''));
    $caminho = trim(remarketingTxt($v['midia_caminho'] ?? ''));
    $tipo = trim(remarketingTxt($v['midia_tipo'] ?? ''));
    if ($caminho !== '') {
        if (!in_array($tipo, ['foto', 'video'], true) || !remarketingCaminhoUploadValido($caminho)) {
            return ['erro' => "A mídia da $nome é inválida. Envie o arquivo de novo."];
        }
    } else {
        $caminho = '';
        $tipo = '';
    }
    $tamanho = mb_strlen($mensagem);
    if ($tamanho < REMARKETING_MENSAGEM_MIN) {
        return ['erro' => "A mensagem da $nome deve ter no mínimo " . REMARKETING_MENSAGEM_MIN . ' caracteres.'];
    }
    $teto = $caminho !== '' ? REMARKETING_LEGENDA_MAX : REMARKETING_MENSAGEM_MAX;
    if ($tamanho > $teto) {
        return ['erro' => $caminho !== ''
            ? "Com foto ou vídeo, a mensagem da $nome tem limite de " . REMARKETING_LEGENDA_MAX . ' caracteres (é a legenda; o Telegram não aceita mais).'
            : "A mensagem da $nome tem limite de " . REMARKETING_MENSAGEM_MAX . ' caracteres (o máximo que o Telegram aceita).'];
    }
    return ['mensagem' => $mensagem, 'midia_caminho' => $caminho, 'midia_tipo' => $tipo];
}

// ============================ ofertas ============================

/** Id curto do plano (vai no callback_data do botão, que tem limite de 64 bytes). */
function remarketingIdPlano($id, array $ja_usados): string
{
    $id = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $id));
    $id = substr($id, 0, 8);
    while ($id === '' || in_array($id, $ja_usados, true)) {
        $id = substr(bin2hex(random_bytes(4)), 0, 6);
    }
    return $id;
}

/**
 * Confere as ofertas da campanha: planos (nome, preço, acesso, entrega), botão redirect e order bump.
 * $minimo_pix é o menor preço que a gateway aceita. Devolve ['ofertas' => ..., 'erro' => ?string].
 * A entrega por grupo é conferida depois, contra os grupos dos bots do fluxo (remarketingSalvarCampanha).
 */
function remarketingNormalizarOfertas($o, float $minimo_pix): array
{
    $o = is_array($o) ? $o : [];
    $planos = [];
    $ids = [];
    foreach (array_slice(array_values((array) ($o['planos'] ?? [])), 0, REMARKETING_PLANOS_MAX) as $p) {
        if (!is_array($p)) {
            continue;
        }
        $nome = trim(mb_substr(strip_tags(remarketingTxt($p['nome'] ?? '')), 0, 40));
        $valor = round((float) str_replace(',', '.', remarketingTxt($p['valor'] ?? 0)), 2);
        if ($nome === '') {
            return ['ofertas' => [], 'erro' => 'Dê um nome a cada plano da oferta.'];
        }
        if ($valor < $minimo_pix) {
            return ['ofertas' => [], 'erro' => 'O preço do plano "' . $nome . '" precisa ser pelo menos R$ ' . number_format($minimo_pix, 2, ',', '.') . '.'];
        }
        if ($valor > 100000) {
            return ['ofertas' => [], 'erro' => 'O preço do plano "' . $nome . '" é alto demais.'];
        }
        // Acesso: um dos prazos prontos, ou "dias" (personalizado, de 1 a 3650).
        $acesso = remarketingTxt($p['acesso'] ?? 'mensal');
        if ($acesso === 'dias') {
            $dias = (int) ($p['dias'] ?? 0);
            if ($dias < 1 || $dias > 3650) {
                return ['ofertas' => [], 'erro' => 'Os dias de acesso do plano "' . $nome . '" precisam ficar entre 1 e 3650.'];
            }
        } else {
            if (!isset(REMARKETING_ACESSO_DIAS[$acesso])) {
                $acesso = 'mensal';
            }
            $dias = REMARKETING_ACESSO_DIAS[$acesso];
        }
        $entrega_tipo = remarketingTxt($p['entrega_tipo'] ?? 'nenhuma');
        $id_grupo = '';
        $link = '';
        $mensagem_entrega = '';
        if ($entrega_tipo === 'grupo') {
            $id_grupo = trim(remarketingTxt($p['id_grupo'] ?? ''));
            if ($id_grupo === '' || !preg_match('/^-?\d{1,20}$/', $id_grupo)) {
                return ['ofertas' => [], 'erro' => 'Escolha o grupo de entrega do plano "' . $nome . '".'];
            }
        } elseif ($entrega_tipo === 'link') {
            $link = linkEntregaValido($p['link_entrega'] ?? '');
            if ($link === '') {
                return ['ofertas' => [], 'erro' => 'O link de entrega do plano "' . $nome . '" precisa começar com http:// ou https://.'];
            }
        } elseif ($entrega_tipo === 'mensagem') {
            // Texto que o cliente recebe, numa mensagem própria, logo depois do aviso de pagamento aprovado (mesmo tipo de entrega do Fluxo Guiado).
            $mensagem_entrega = trim(remarketingTxt($p['mensagem_entrega'] ?? ''));
            if ($mensagem_entrega === '') {
                return ['ofertas' => [], 'erro' => 'Escreva a mensagem de entrega do plano "' . $nome . '".'];
            }
            if (mb_strlen($mensagem_entrega) > ENTREGA_MENSAGEM_MAX) {
                return ['ofertas' => [], 'erro' => 'A mensagem de entrega do plano "' . $nome . '" passa de ' . ENTREGA_MENSAGEM_MAX . ' caracteres.'];
            }
        } else {
            $entrega_tipo = 'nenhuma';
        }
        $id = remarketingIdPlano($p['id'] ?? '', $ids);
        $ids[] = $id;
        $planos[] = [
            'id' => $id, 'nome' => $nome, 'valor' => $valor, 'acesso' => $acesso, 'dias' => $dias,
            'entrega_tipo' => $entrega_tipo, 'id_grupo' => $id_grupo, 'link_entrega' => $link, 'mensagem_entrega' => $mensagem_entrega,
        ];
    }

    $r = is_array($o['redirect'] ?? null) ? $o['redirect'] : [];
    $redirect = ['ativo' => !empty($r['ativo']), 'texto' => trim(mb_substr(strip_tags(remarketingTxt($r['texto'] ?? '')), 0, 30)) ?: 'Ver Prévias', 'url' => trim(remarketingTxt($r['url'] ?? ''))];
    if ($redirect['ativo']) {
        $redirect['url'] = linkEntregaValido($redirect['url']);
        if ($redirect['url'] === '') {
            return ['ofertas' => [], 'erro' => 'Informe o endereço do botão redirect (começando com https://).'];
        }
    } else {
        $redirect['url'] = linkEntregaValido($redirect['url']);
    }

    $b = is_array($o['order_bump'] ?? null) ? $o['order_bump'] : [];
    $bump = [
        'ativo' => !empty($b['ativo']),
        'nome' => trim(mb_substr(strip_tags(remarketingTxt($b['nome'] ?? '')), 0, 60)),
        'valor' => round((float) str_replace(',', '.', remarketingTxt($b['valor'] ?? 0)), 2),
        'descricao' => trim(mb_substr(remarketingTxt($b['descricao'] ?? ''), 0, 500)),
        'midia_caminho' => '',
        'midia_tipo' => '',
    ];
    $caminho_bump = trim(remarketingTxt($b['midia_caminho'] ?? ''));
    if ($caminho_bump !== '' && in_array($b['midia_tipo'] ?? '', ['foto', 'video'], true) && remarketingCaminhoUploadValido($caminho_bump)) {
        $bump['midia_caminho'] = $caminho_bump;
        $bump['midia_tipo'] = (string) $b['midia_tipo'];
    }
    if ($bump['ativo']) {
        if ($bump['nome'] === '') {
            return ['ofertas' => [], 'erro' => 'Dê um nome ao order bump.'];
        }
        if ($bump['valor'] <= 0 || $bump['valor'] > 100000) {
            return ['ofertas' => [], 'erro' => 'Informe o preço do order bump.'];
        }
        if (!$planos) {
            return ['ofertas' => [], 'erro' => 'O order bump só funciona junto de um plano: adicione pelo menos um plano.'];
        }
    }
    return ['ofertas' => ['planos' => $planos, 'redirect' => $redirect, 'order_bump' => $bump], 'erro' => null];
}

/** Ofertas gravadas na campanha, sempre com as 3 partes (planos, redirect, order_bump). */
function remarketingOfertasDaCampanha(array $campanha): array
{
    $o = json_decode((string) ($campanha['ofertas'] ?? ''), true);
    $o = is_array($o) ? $o : [];
    $r = is_array($o['redirect'] ?? null) ? $o['redirect'] : [];
    $b = is_array($o['order_bump'] ?? null) ? $o['order_bump'] : [];
    return [
        'planos' => array_values(array_filter((array) ($o['planos'] ?? []), 'is_array')),
        'redirect' => ['ativo' => !empty($r['ativo']), 'texto' => (string) ($r['texto'] ?? 'Ver Prévias'), 'url' => (string) ($r['url'] ?? '')],
        'order_bump' => [
            'ativo' => !empty($b['ativo']), 'nome' => (string) ($b['nome'] ?? ''), 'valor' => (float) ($b['valor'] ?? 0),
            'descricao' => (string) ($b['descricao'] ?? ''), 'midia_caminho' => (string) ($b['midia_caminho'] ?? ''), 'midia_tipo' => (string) ($b['midia_tipo'] ?? ''),
        ],
    ];
}

/** A campanha tem botão (plano ou redirect) para mostrar junto da mensagem? */
function remarketingTemOferta(array $ofertas): bool
{
    return !empty($ofertas['planos']) || (!empty($ofertas['redirect']['ativo']) && $ofertas['redirect']['url'] !== '');
}

function remarketingRotuloPlano(array $plano): string
{
    return trim((string) ($plano['nome'] ?? 'Plano')) . ' — R$ ' . number_format((float) ($plano['valor'] ?? 0), 2, ',', '.');
}

/**
 * Teclado que vai na mensagem da campanha: um botão por plano ("rmk::<campanha>::<plano>::<variante>", sempre abaixo de 64 bytes)
 * e o botão redirect (link). Sem oferta, não há teclado (null) e a campanha manda só a mensagem.
 */
function remarketingTeclado(int $id_campanha, int $indice_variante, array $ofertas): ?array
{
    $linhas = [];
    foreach ($ofertas['planos'] as $p) {
        $linhas[] = [['text' => remarketingRotuloPlano($p), 'callback_data' => 'rmk::' . $id_campanha . '::' . $p['id'] . '::' . $indice_variante]];
    }
    if (!empty($ofertas['redirect']['ativo']) && $ofertas['redirect']['url'] !== '') {
        $linhas[] = [['text' => $ofertas['redirect']['texto'] ?: 'Ver Prévias', 'url' => $ofertas['redirect']['url']]];
    }
    return $linhas ? ['inline_keyboard' => $linhas] : null;
}

// ============================ campanha inteira ============================

/**
 * Confere tudo que o assistente envia (sem banco). Devolve ['erro' => ?string, 'dados' => [...]].
 * 'dados' já vem pronto para gravar: nome, id_fluxo, segmento, período, variantes (a 1ª é a A), ofertas.
 */
function remarketingNormalizarEntrada(array $in, float $minimo_pix): array
{
    $nome = trim(mb_substr(strip_tags(remarketingTxt($in['nome'] ?? '')), 0, REMARKETING_NOME_MAX));
    if ($nome === '') {
        return ['erro' => 'Dê um nome à campanha.', 'dados' => []];
    }
    $id_fluxo = (int) ($in['id_fluxo'] ?? 0);
    if ($id_fluxo <= 0) {
        return ['erro' => 'Escolha o fluxo da campanha.', 'dados' => []];
    }
    $segmento = remarketingTxt($in['segmento'] ?? '');
    if (!isset(REMARKETING_SEGMENTOS[$segmento])) {
        return ['erro' => 'Escolha quem vai receber a campanha.', 'dados' => []];
    }
    $periodo = remarketingNormalizarPeriodo($in['periodo_tipo'] ?? 'total', $in['periodo_de'] ?? '', $in['periodo_ate'] ?? '');
    if ($periodo['erro'] !== null) {
        return ['erro' => $periodo['erro'], 'dados' => []];
    }
    $brutas = array_values(array_filter((array) ($in['variantes'] ?? []), 'is_array'));
    if (!$brutas) {
        return ['erro' => 'Escreva a mensagem da campanha.', 'dados' => []];
    }
    $variantes = [];
    foreach (array_slice($brutas, 0, REMARKETING_VARIANTES_MAX) as $i => $bruta) {
        $v = remarketingNormalizarVariante($bruta, $i === 0 ? 'mensagem principal' : 'variante ' . chr(65 + $i));
        if (isset($v['erro'])) {
            return ['erro' => $v['erro'], 'dados' => []];
        }
        $variantes[] = $v;
    }
    $o = remarketingNormalizarOfertas($in['ofertas'] ?? [], $minimo_pix);
    if ($o['erro'] !== null) {
        return ['erro' => $o['erro'], 'dados' => []];
    }
    return ['erro' => null, 'dados' => [
        'nome' => $nome, 'id_fluxo' => $id_fluxo, 'segmento' => $segmento, 'periodo' => $periodo,
        'variantes' => $variantes, 'ofertas' => $o['ofertas'],
    ]];
}

/** Variantes da campanha gravada: a A (colunas mensagem/midia) e as extras (coluna variantes, em JSON). */
function remarketingVariantesDaCampanha(array $campanha): array
{
    $lista = [[
        'mensagem' => (string) ($campanha['mensagem'] ?? ''),
        'midia_caminho' => (string) ($campanha['midia_caminho'] ?? ''),
        'midia_tipo' => (string) ($campanha['midia_tipo'] ?? ''),
    ]];
    $extras = json_decode((string) ($campanha['variantes'] ?? ''), true);
    foreach (is_array($extras) ? array_slice($extras, 0, REMARKETING_VARIANTES_MAX - 1) : [] as $v) {
        if (is_array($v) && trim((string) ($v['mensagem'] ?? '')) !== '') {
            $lista[] = [
                'mensagem' => (string) $v['mensagem'],
                'midia_caminho' => (string) ($v['midia_caminho'] ?? ''),
                'midia_tipo' => (string) ($v['midia_tipo'] ?? ''),
            ];
        }
    }
    return $lista;
}

/** Variante que cada destinatário recebe: reveza na ordem do envio (posição 0 = A, 1 = B, 2 = C, 3 = A...). */
function remarketingVarianteDaPosicao(int $posicao, int $total_variantes): int
{
    return $total_variantes > 1 ? $posicao % $total_variantes : 0;
}

// ============================ reenvio, intervalo e recorrência ============================

/**
 * Dois envios de remarketing do MESMO fluxo precisam de pelo menos 3 horas entre si (pedido de cliente, 26/10/2026: "se fizer outro remarketing
 * pro mesmo fluxo só vai conseguir enviá-lo depois das 3 horas que enviou o outro; pra outro fluxo vai dar certo e as mesmas coisas das 3 horas").
 * Vale para iniciar, reenviar, agendar e repetir. Cada fluxo tem o seu relógio; a campanha antiga (sem fluxo) conta por bot.
 */
const REMARKETING_INTERVALO_MINIMO_MIN = 180;

/** Como a campanha é agendada (o modal "Agendar campanha"): chave => rótulo. */
const REMARKETING_FREQUENCIAS = [
    'uma_vez'  => 'Uma vez',
    'diario'   => 'Diário',
    'horarios' => 'Horários personalizados',
    'a_cada'   => 'A cada X horas',
    'semana'   => 'Dias da semana',
];
const REMARKETING_HORARIOS_MAX = 6;          // horários por dia em "Horários personalizados"
const REMARKETING_A_CADA_HORAS_MAX = 168;    // "A cada X horas": de 3 (o intervalo mínimo) até 168 (uma semana)
const REMARKETING_DIAS_SEMANA = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb']; // índice = date('w')

/** Pode ser (re)agendada ou reenviada? Rascunho, já agendada, concluída ou com falha; em envio, não. */
function remarketingAgendavel(array $c): bool
{
    return in_array((string) ($c['status'] ?? ''), ['rascunho', 'pendente', 'concluida', 'falha'], true);
}

/** Já saiu pelo menos uma vez e terminou: o botão da lista é "Reenviar". */
function remarketingReenviavel(array $c): bool
{
    return in_array((string) ($c['status'] ?? ''), ['concluida', 'falha'], true);
}

/** Já saiu alguma vez (concluída, com falha ou repetindo): os contadores dela são os da última execução e o cron guarda e zera quando a próxima começar. */
function remarketingTemExecucaoAnterior(array $c): bool
{
    return !empty($c['processado_em']) || (int) ($c['enviados'] ?? 0) > 0 || (int) ($c['offset_envio'] ?? 0) > 0;
}

/** O grupo que divide o relógio de 3 horas: o fluxo da campanha ou, na campanha antiga, o bot dela. */
function remarketingChaveGrupo(array $c): string
{
    $fluxo = (int) ($c['id_fluxo'] ?? 0);
    return $fluxo > 0 ? 'f' . $fluxo : 'b' . (int) ($c['bot_id'] ?? 0);
}

/** 'HH:MM' válido (00:00 a 23:59; aceita "9:30" e "09:30:00") ou ''. */
function remarketingHoraValida($v): string
{
    if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', trim(remarketingTxt($v)), $m)) {
        return '';
    }
    return str_pad($m[1], 2, '0', STR_PAD_LEFT) . ':' . $m[2];
}

/**
 * Confere a parte repetida do agendamento (diário, horários, a cada X horas, dias da semana).
 * Devolve ['recorrencia' => array|null, 'erro' => string|null]; "uma vez" não tem recorrência (null, sem erro).
 */
function remarketingNormalizarRecorrencia($in): array
{
    $in = is_array($in) ? $in : [];
    $tipo = remarketingTxt($in['tipo'] ?? 'uma_vez');
    $erro = static fn(string $m): array => ['recorrencia' => null, 'erro' => $m];
    if (!isset(REMARKETING_FREQUENCIAS[$tipo])) {
        return $erro('Escolha como a campanha vai ser enviada.');
    }
    switch ($tipo) {
        case 'uma_vez':
            return ['recorrencia' => null, 'erro' => null];
        case 'diario':
            $hora = remarketingHoraValida($in['hora'] ?? '');
            return $hora === '' ? $erro('Informe o horário de execução.') : ['recorrencia' => ['tipo' => 'diario', 'hora' => $hora], 'erro' => null];
        case 'a_cada':
            $horas = $in['horas'] ?? ($in['cada_horas'] ?? '');
            $minimo = intdiv(REMARKETING_INTERVALO_MINIMO_MIN, 60);
            if (!is_scalar($horas) || !preg_match('/^\d{1,4}$/', trim((string) $horas)) || (int) $horas < $minimo || (int) $horas > REMARKETING_A_CADA_HORAS_MAX) {
                return $erro('Informe de quantas em quantas horas (de ' . $minimo . ' a ' . REMARKETING_A_CADA_HORAS_MAX . '): um fluxo só envia remarketing a cada ' . $minimo . ' horas, no mínimo.');
            }
            return ['recorrencia' => ['tipo' => 'a_cada', 'horas' => (int) $horas], 'erro' => null];
        case 'horarios':
            $lista = [];
            foreach (is_array($in['horarios'] ?? null) ? $in['horarios'] : [] as $h) {
                $ok = remarketingHoraValida($h);
                if ($ok === '') {
                    return $erro('Há um horário inválido na lista.');
                }
                $lista[$ok] = true;
            }
            $lista = array_keys($lista);
            sort($lista);
            if (!$lista) {
                return $erro('Informe pelo menos um horário.');
            }
            if (count($lista) > REMARKETING_HORARIOS_MAX) {
                return $erro('Use no máximo ' . REMARKETING_HORARIOS_MAX . ' horários por dia.');
            }
            // O último horário do dia e o primeiro do dia seguinte também precisam respeitar o intervalo.
            $minutos = array_map(static fn(string $h): int => (int) substr($h, 0, 2) * 60 + (int) substr($h, 3, 2), $lista);
            for ($i = 0, $n = count($minutos); $i < $n; $i++) {
                $proximo = $i + 1 < $n ? $minutos[$i + 1] : $minutos[0] + 1440;
                if ($n > 1 && $proximo - $minutos[$i] < REMARKETING_INTERVALO_MINIMO_MIN) {
                    return $erro('Deixe pelo menos ' . intdiv(REMARKETING_INTERVALO_MINIMO_MIN, 60) . ' horas entre um horário e o seguinte: um fluxo só envia remarketing a cada ' . intdiv(REMARKETING_INTERVALO_MINIMO_MIN, 60) . ' horas.');
                }
            }
            return ['recorrencia' => ['tipo' => 'horarios', 'horarios' => $lista], 'erro' => null];
        case 'semana':
            $dias = [];
            foreach (is_array($in['dias'] ?? null) ? $in['dias'] : [] as $d) {
                if (is_scalar($d) && preg_match('/^[0-6]$/', (string) $d)) {
                    $dias[(int) $d] = true;
                }
            }
            $dias = array_keys($dias);
            sort($dias);
            if (!$dias) {
                return $erro('Selecione pelo menos um dia da semana.');
            }
            $hora = remarketingHoraValida($in['hora'] ?? '');
            return $hora === '' ? $erro('Informe o horário de execução.') : ['recorrencia' => ['tipo' => 'semana', 'dias' => $dias, 'hora' => $hora], 'erro' => null];
    }
    return $erro('Escolha como a campanha vai ser enviada.');
}

/**
 * Confere o que o modal "Agendar campanha" mandou. Devolve ['tipo', 'recorrencia', 'primeira', 'erro']:
 * 'recorrencia' é o que fica gravado (null em "Uma vez") e 'primeira' é 'Y-m-d H:i' do 1º envio quando o modal pede data e hora
 * ("Uma vez" e "A cada X horas"); nos outros tipos fica '' e o servidor calcula a próxima ocorrência.
 */
function remarketingNormalizarFrequencia($in): array
{
    $in = is_array($in) ? $in : [];
    $tipo = remarketingTxt($in['tipo'] ?? 'uma_vez');
    $r = remarketingNormalizarRecorrencia($in);
    if ($r['erro'] !== null) {
        return ['tipo' => $tipo, 'recorrencia' => null, 'primeira' => '', 'erro' => $r['erro']];
    }
    $primeira = '';
    if ($tipo === 'uma_vez' || $tipo === 'a_cada') {
        $data = remarketingDataValida($in['data'] ?? '');
        $hora = remarketingHoraValida($in['hora'] ?? '');
        if ($data === '' || $hora === '') {
            return ['tipo' => $tipo, 'recorrencia' => null, 'primeira' => '', 'erro' => $tipo === 'uma_vez' ? 'Informe a data e a hora do envio.' : 'Informe a data e a hora do primeiro envio.'];
        }
        $primeira = $data . ' ' . $hora;
    }
    return ['tipo' => $tipo, 'recorrencia' => $r['recorrencia'], 'primeira' => $primeira, 'erro' => null];
}

/** A recorrência gravada na campanha (JSON na coluna recorrencia), já conferida; null se não tem ou se o JSON não vale mais. */
function remarketingRecorrenciaDaCampanha(array $c): ?array
{
    $bruto = trim((string) ($c['recorrencia'] ?? ''));
    if ($bruto === '') {
        return null;
    }
    return remarketingNormalizarRecorrencia(json_decode($bruto, true))['recorrencia'];
}

/**
 * Próxima execução da recorrência: o primeiro horário que cai em $nao_antes ou depois (timestamps).
 * "A cada X horas" conta do começo da execução anterior ($ultimo_inicio); sem ele, conta de $nao_antes.
 */
function remarketingProximaExecucao(array $rec, int $nao_antes, ?int $ultimo_inicio = null): ?int
{
    $tipo = (string) ($rec['tipo'] ?? '');
    if ($tipo === 'a_cada') {
        return max(($ultimo_inicio ?? $nao_antes) + (int) ($rec['horas'] ?? 0) * 3600, $nao_antes);
    }
    if ($tipo === 'horarios') {
        $horas = (array) ($rec['horarios'] ?? []);
        $dias = [0, 1, 2, 3, 4, 5, 6];
    } elseif ($tipo === 'semana') {
        $horas = [(string) ($rec['hora'] ?? '')];
        $dias = array_map('intval', (array) ($rec['dias'] ?? []));
    } elseif ($tipo === 'diario') {
        $horas = [(string) ($rec['hora'] ?? '')];
        $dias = [0, 1, 2, 3, 4, 5, 6];
    } else {
        return null;
    }
    sort($horas);
    for ($d = 0; $d <= 8; $d++) {
        $dia = strtotime('+' . $d . ' day', $nao_antes);
        if ($dia === false || !in_array((int) date('w', $dia), $dias, true)) {
            continue;
        }
        foreach ($horas as $h) {
            $ts = strtotime(date('Y-m-d', $dia) . ' ' . $h . ':00');
            if ($ts !== false && $ts >= $nao_antes) {
                return $ts;
            }
        }
    }
    return null;
}

/** A recorrência em palavras, para o cartão e os detalhes: "Todo dia às 21:00", "Seg, Qua e Sex às 21:00", "A cada 6 horas". null = "Uma vez". */
function remarketingDescreverRecorrencia(?array $rec): string
{
    $junta = static function (array $itens): string {
        $ultimo = array_pop($itens);
        return $itens ? implode(', ', $itens) . ' e ' . $ultimo : (string) $ultimo;
    };
    switch ($rec['tipo'] ?? 'uma_vez') {
        case 'diario':
            return 'Todo dia às ' . $rec['hora'];
        case 'horarios':
            return 'Todo dia às ' . $junta((array) $rec['horarios']);
        case 'a_cada':
            return 'A cada ' . (int) $rec['horas'] . ' horas';
        case 'semana':
            $dias = array_values(array_unique(array_map('intval', (array) $rec['dias'])));
            if (count($dias) === 7) {
                return 'Todo dia às ' . $rec['hora'];
            }
            return $junta(array_map(static fn(int $d): string => REMARKETING_DIAS_SEMANA[$d] ?? '', $dias)) . ' às ' . $rec['hora'];
    }
    return 'Uma vez';
}

/**
 * O que impede esta campanha de sair em $ts por causa do relógio de 3 horas do grupo (fluxo; campanha antiga, bot).
 * $grupo = as campanhas do grupo (colunas id, nome, status, agendado_em, processado_em, entregues), a própria inclusive.
 * Só conta o envio que chegou a alguém (entregues > 0): campanha que falhou inteira (bot com token recusado, todos bloquearam) não incomodou ninguém e não segura o fluxo.
 * Devolve null (pode) ou ['motivo' => 'em_envio'|'intervalo'|'agendada_perto', 'livre_em' => timestamp|null, 'mensagem' => texto]:
 *  - em_envio: outra campanha do grupo está saindo agora (só dá para saber quando ela termina);
 *  - intervalo: o grupo (esta campanha inclusive, no reenvio) terminou um envio há menos de 3 h antes de $ts;
 *  - agendada_perto: outra campanha do grupo está agendada a menos de 3 h de $ts.
 */
function remarketingBloqueioDoGrupo(array $grupo, int $id_proprio, int $ts): ?array
{
    $minimo = REMARKETING_INTERVALO_MINIMO_MIN * 60;
    $horas = intdiv(REMARKETING_INTERVALO_MINIMO_MIN, 60);
    $nome = static fn(array $l): string => trim((string) ($l['nome'] ?? '')) !== '' ? (string) $l['nome'] : 'Campanha #' . (int) $l['id'];
    $livre_em = 0;
    $ultima = null;
    $perto = null;
    foreach ($grupo as $l) {
        $propria = (int) $l['id'] === $id_proprio;
        $status = (string) ($l['status'] ?? '');
        if (!$propria && $status === 'processando') {
            return ['motivo' => 'em_envio', 'livre_em' => null,
                'mensagem' => 'Este fluxo já tem a campanha "' . $nome($l) . '" em envio. Espere ela terminar: depois disso, o fluxo só libera outro remarketing ' . $horas . ' horas depois.'];
        }
        $fim = !empty($l['processado_em']) && (int) ($l['entregues'] ?? 0) > 0 ? strtotime((string) $l['processado_em']) : false;
        if ($fim !== false && $fim + $minimo > $livre_em) {
            $livre_em = $fim + $minimo;
            $ultima = $l;
        }
        if (!$propria && $status === 'pendente' && $perto === null && abs($ts - (int) strtotime((string) $l['agendado_em'])) < $minimo) {
            $perto = $l;
        }
    }
    if ($ultima !== null && $ts < $livre_em) {
        return ['motivo' => 'intervalo', 'livre_em' => $livre_em,
            'mensagem' => 'Este fluxo enviou o remarketing "' . $nome($ultima) . '" em ' . date('d/m/Y H:i', $livre_em - $minimo) . '. Para não incomodar os mesmos leads, um novo envio do mesmo fluxo só é liberado ' . $horas . ' horas depois, a partir de ' . date('d/m/Y H:i', $livre_em) . '. Campanhas de outros fluxos não são afetadas.'];
    }
    if ($perto !== null) {
        return ['motivo' => 'agendada_perto', 'livre_em' => null,
            'mensagem' => 'Já existe a campanha "' . $nome($perto) . '" agendada para ' . date('d/m/Y H:i', (int) strtotime((string) $perto['agendado_em'])) . ' neste fluxo. Deixe pelo menos ' . $horas . ' horas entre dois envios do mesmo fluxo.'];
    }
    return null;
}

/** Datas que a tela mostra no cartão: "25/10 21:30". */
function remarketingDataCurta(?string $data): string
{
    $ts = $data ? strtotime($data) : false;
    return $ts ? date('d/m/Y H:i', $ts) : '';
}
