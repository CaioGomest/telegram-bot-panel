<?php
declare(strict_types=1);

require_once __DIR__ . '/remarketing.php';

/**
 * Campanhas de remarketing no banco: contexto do assistente, salvar (rascunho), iniciar/agendar, listar, excluir e exportar.
 * É o que a API de remarketing.php chama; as regras puras (validação, público, teclado) ficam em funcoes/remarketing.php.
 * O chamador carrega conexao.php (PDO), funcoes/operacao.php, funcoes/gateways.php e funcoes/log.php.
 */

/** Pode mudar? Só o que ainda não foi enviado: rascunho, ou agendada que ainda não começou (nunca saiu: reenvio agendado não é editável). */
function remarketingEditavel(array $c): bool
{
    return ($c['status'] ?? '') === 'rascunho'
        || (($c['status'] ?? '') === 'pendente' && (int) ($c['enviados'] ?? 0) === 0 && (int) ($c['offset_envio'] ?? 0) === 0 && empty($c['processado_em']));
}

/** Menor preço de plano que a gateway aceita (a mesma conta do editor de fluxos). */
function remarketingMinimoPix(): float
{
    return precoMinimoOmegaPayments(getGatewaySplit('omegapayments'));
}

/** Dias de acesso de um plano do fluxo → como o seletor do remarketing mostra (a chave dos dias exatos, ou personalizado). */
function remarketingAcessoDoPlanoDoFluxo(array $plano): array
{
    $dias = max(1, (int) ($plano['dias_acesso'] ?? 30));
    $unidade = (string) ($plano['unidade_acesso'] ?? 'dias');
    if ($unidade !== 'dias') {
        return ['acesso' => 'dias', 'dias' => 1];   // plano de horas/minutos do fluxo: no remarketing o menor acesso é 1 dia
    }
    $chave = array_search($dias, REMARKETING_ACESSO_DIAS, true);
    return $chave !== false ? ['acesso' => (string) $chave, 'dias' => $dias] : ['acesso' => 'dias', 'dias' => $dias];
}

/**
 * O que o assistente precisa saber da conta: os fluxos (com os bots vinculados, os grupos desses bots e, nos fluxos guiados, os
 * planos, para o dono reaproveitar), e o menor preço aceito.
 */
function remarketingContexto(PDO $pdo, int $id_usuario): array
{
    $stmt = $pdo->prepare("SELECT id, nome, modo, CASE WHEN modo = 'basico' THEN dados_fluxograma ELSE NULL END AS dados FROM fluxos WHERE id_usuario = ? ORDER BY atualizado_em DESC");
    $stmt->execute([$id_usuario]);
    $fluxos = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $f) {
        $planos = [];
        $dados = json_decode((string) ($f['dados'] ?? ''), true);
        foreach (is_array($dados) ? (array) ($dados['planos'] ?? []) : [] as $p) {
            if (!is_array($p) || trim((string) ($p['nome'] ?? '')) === '' || (float) ($p['valor'] ?? 0) <= 0) {
                continue;
            }
            $entrega = entregaDoItemBasico($p);
            $planos[] = remarketingAcessoDoPlanoDoFluxo($p) + [
                'id' => (string) ($p['id'] ?? ''),
                'nome' => (string) $p['nome'],
                'valor' => (float) $p['valor'],
                'entrega_tipo' => $entrega['id_grupo'] !== '' ? 'grupo' : ($entrega['link_entrega'] !== '' ? 'link' : 'nenhuma'),
                'id_grupo' => $entrega['id_grupo'],
                'link_entrega' => $entrega['link_entrega'],
            ];
        }
        $fluxos[(int) $f['id']] = ['id' => (int) $f['id'], 'nome' => (string) $f['nome'], 'modo' => (string) $f['modo'], 'bots' => [], 'grupos' => [], 'planos' => $planos];
    }
    $stmt = $pdo->prepare("SELECT id, id_fluxo_conectado, COALESCE(primeiro_nome, nome_usuario) AS nome FROM bots WHERE id_usuario = ? AND id_fluxo_conectado IS NOT NULL ORDER BY id");
    $stmt->execute([$id_usuario]);
    $fluxo_do_bot = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $b) {
        $idf = (int) $b['id_fluxo_conectado'];
        if (isset($fluxos[$idf]) && count($fluxos[$idf]['bots']) < REMARKETING_BOTS_MAX) {
            $fluxos[$idf]['bots'][] = ['id' => (int) $b['id'], 'nome' => (string) $b['nome']];
            $fluxo_do_bot[(int) $b['id']] = $idf;
        }
    }
    if ($fluxo_do_bot) {
        $stmt = $pdo->query('SELECT bot_id, id_telegram, titulo FROM bot_grupos WHERE bot_id IN (' . implode(',', array_keys($fluxo_do_bot)) . ') ORDER BY titulo');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $g) {
            $fluxos[$fluxo_do_bot[(int) $g['bot_id']]]['grupos'][(string) $g['id_telegram']] = ['id' => (string) $g['id_telegram'], 'titulo' => (string) ($g['titulo'] ?: $g['id_telegram'])];
        }
    }
    foreach ($fluxos as &$f) {
        $f['grupos'] = array_values($f['grupos']);
    }
    unset($f);
    return ['fluxos' => array_values($fluxos), 'minimo_pix' => remarketingMinimoPix()];
}

/** Campanha do usuário (linha inteira) ou null. */
function remarketingLinha(PDO $pdo, int $id_usuario, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM remarketing_campanhas WHERE id = ? AND id_usuario = ?');
    $stmt->execute([$id, $id_usuario]);
    $c = $stmt->fetch(PDO::FETCH_ASSOC);
    return $c ?: null;
}

/** A campanha como o assistente a edita (o mesmo formato que ele manda ao salvar). */
function remarketingParaEdicao(array $c): array
{
    $ini = (string) ($c['periodo_inicio'] ?? '');
    $fim = (string) ($c['periodo_fim'] ?? '');
    return [
        'id' => (int) $c['id'],
        'nome' => (string) ($c['nome'] ?? ''),
        'id_fluxo' => (int) ($c['id_fluxo'] ?? 0),
        'segmento' => (string) $c['audiencia'],
        'periodo_tipo' => (string) ($c['periodo_tipo'] ?? 'total'),
        'periodo_de' => ($c['periodo_tipo'] ?? '') === 'custom' && $ini !== '' ? substr($ini, 0, 10) : '',
        'periodo_ate' => ($c['periodo_tipo'] ?? '') === 'custom' && $fim !== '' ? substr($fim, 0, 10) : '',
        'variantes' => remarketingVariantesDaCampanha($c),
        'ofertas' => remarketingOfertasDaCampanha($c),
        'status' => (string) $c['status'],
        'editavel' => remarketingEditavel($c),
        'agendado_em' => (string) $c['agendado_em'],
    ];
}

/**
 * Cria (id = 0) ou atualiza uma campanha. Nova entra como rascunho: nada é enviado até iniciar.
 * Devolve ['sucesso' => bool, 'mensagem' => string, 'id' => int].
 */
function remarketingSalvar(PDO $pdo, int $id_usuario, array $in, int $id = 0): array
{
    $n = remarketingNormalizarEntrada($in, remarketingMinimoPix());
    if ($n['erro'] !== null) {
        return ['sucesso' => false, 'mensagem' => $n['erro'], 'id' => 0];
    }
    $d = $n['dados'];

    $stmt = $pdo->prepare('SELECT nome FROM fluxos WHERE id = ? AND id_usuario = ?');
    $stmt->execute([$d['id_fluxo'], $id_usuario]);
    if ($stmt->fetchColumn() === false) {
        return ['sucesso' => false, 'mensagem' => 'Fluxo não encontrado.', 'id' => 0];
    }
    $bots = remarketingBotsDoFluxo($pdo, $id_usuario, $d['id_fluxo']);
    if (!$bots) {
        return ['sucesso' => false, 'mensagem' => 'Esse fluxo ainda não tem bot vinculado. Vincule um bot a ele (em Fluxos) para enviar a campanha.', 'id' => 0];
    }

    // O grupo de entrega de cada plano tem que ser de um dos bots do fluxo (senão o bot nem consegue convidar).
    $stmt = $pdo->query('SELECT DISTINCT id_telegram FROM bot_grupos WHERE bot_id IN (' . implode(',', array_column($bots, 'id')) . ')');
    $grupos = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    foreach ($d['ofertas']['planos'] as $p) {
        if ($p['entrega_tipo'] === 'grupo' && !in_array($p['id_grupo'], $grupos, true)) {
            return ['sucesso' => false, 'mensagem' => 'O grupo de entrega do plano "' . $p['nome'] . '" não pertence aos bots deste fluxo.', 'id' => 0];
        }
    }
    // A mídia tem que existir no servidor (a conferência da forma já foi feita na validação).
    foreach ($d['variantes'] as $i => $v) {
        if ($v['midia_caminho'] !== '' && remarketingCaminhoUploadSeguro($v['midia_caminho']) === null) {
            return ['sucesso' => false, 'mensagem' => 'A mídia da ' . ($i === 0 ? 'mensagem principal' : 'variante ' . chr(65 + $i)) . ' não foi encontrada. Envie o arquivo de novo.', 'id' => 0];
        }
    }
    $bump = $d['ofertas']['order_bump'];
    if ($bump['midia_caminho'] !== '' && remarketingCaminhoUploadSeguro($bump['midia_caminho']) === null) {
        return ['sucesso' => false, 'mensagem' => 'A mídia do order bump não foi encontrada. Envie o arquivo de novo.', 'id' => 0];
    }

    $intervalo = $d['periodo']['tipo'] === 'custom' ? remarketingIntervaloPeriodo('custom', $d['periodo']['de'], $d['periodo']['ate']) : null;
    $a = $d['variantes'][0];
    $extras = array_slice($d['variantes'], 1);
    $campos = [
        $d['id_fluxo'], $d['nome'], $d['segmento'], $d['periodo']['tipo'], $intervalo[0] ?? null, $intervalo[1] ?? null,
        $a['mensagem'], $a['midia_caminho'] !== '' ? $a['midia_caminho'] : null, $a['midia_tipo'] !== '' ? $a['midia_tipo'] : null,
        $extras ? json_encode($extras, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        json_encode($d['ofertas'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];

    if ($id > 0) {
        $atual = remarketingLinha($pdo, $id_usuario, $id);
        if (!$atual) {
            return ['sucesso' => false, 'mensagem' => 'Campanha não encontrada.', 'id' => 0];
        }
        if (!remarketingEditavel($atual)) {
            return ['sucesso' => false, 'mensagem' => 'Esta campanha já foi enviada (ou está em envio) e não pode mais ser editada.', 'id' => 0];
        }
        // Mídia trocada = o file_id guardado do Telegram não vale mais. Campanha agendada leva os bots congelados do fluxo NOVO (o fluxo pode ter mudado); rascunho ainda não tem.
        // O mesmo "só enquanto não começou" vai no WHERE: se o cron pegou a campanha entre a conferência e o UPDATE, nada é alterado.
        $bots_gravar = $atual['status'] === 'pendente' ? implode(',', array_column($bots, 'id')) : null;
        $st = $pdo->prepare("UPDATE remarketing_campanhas SET id_fluxo = ?, bot_id = NULL, nome = ?, audiencia = ?, periodo_tipo = ?, periodo_inicio = ?, periodo_fim = ?, mensagem = ?, midia_caminho = ?, midia_tipo = ?, variantes = ?, ofertas = ?, bots_ids = ?, midia_file_id = NULL, midia_file_ids = NULL WHERE id = ? AND id_usuario = ? AND (status = 'rascunho' OR (status = 'pendente' AND enviados = 0 AND offset_envio = 0))");
        $st->execute(array_merge($campos, [$bots_gravar, $id, $id_usuario]));
        if ($st->rowCount() === 0) {
            // 0 linhas = nada mudou (salvou igual) ou a campanha começou a sair agora: só o segundo caso é erro.
            $de_novo = remarketingLinha($pdo, $id_usuario, $id);
            if (!$de_novo || !remarketingEditavel($de_novo)) {
                return ['sucesso' => false, 'mensagem' => 'Esta campanha já foi enviada (ou está em envio) e não pode mais ser editada.', 'id' => 0];
            }
        }
        $mensagem = 'Alterações salvas.';
    } else {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM remarketing_campanhas WHERE id_usuario = ?');
        $stmt->execute([$id_usuario]);
        if ((int) $stmt->fetchColumn() >= REMARKETING_CAMPANHAS_MAX) {
            return ['sucesso' => false, 'mensagem' => 'Limite de ' . REMARKETING_CAMPANHAS_MAX . ' campanhas atingido. Exclua alguma para criar outra.', 'id' => 0];
        }
        $pdo->prepare("INSERT INTO remarketing_campanhas (id_usuario, id_fluxo, nome, audiencia, periodo_tipo, periodo_inicio, periodo_fim, mensagem, midia_caminho, midia_tipo, variantes, ofertas, agendado_em, status, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'rascunho', NOW())")
            ->execute(array_merge([$id_usuario], $campos));
        $id = (int) $pdo->lastInsertId();
        $mensagem = 'Rascunho criado. Nada foi enviado: inicie a campanha na lista quando quiser.';
    }
    if (function_exists('registrarAtividade')) {
        registrarAtividade($id_usuario, 'sistema', 'Remarketing', ($mensagem === 'Alterações salvas.' ? 'Editou' : 'Criou') . ' a campanha: ' . $d['nome']);
    }
    return ['sucesso' => true, 'mensagem' => $mensagem, 'id' => $id];
}

/** As campanhas que dividem o relógio de 3 horas com esta (o mesmo fluxo; a campanha antiga, o mesmo bot): só as que ainda contam (em envio, agendadas ou terminadas há pouco). */
function remarketingGrupoDeCampanhas(PDO $pdo, int $id_usuario, array $c): array
{
    $fluxo = (int) ($c['id_fluxo'] ?? 0);
    $filtro = $fluxo > 0 ? 'id_fluxo = ?' : 'id_fluxo IS NULL AND bot_id = ?';
    $stmt = $pdo->prepare("SELECT id, nome, id_fluxo, bot_id, status, agendado_em, processado_em, entregues FROM remarketing_campanhas
        WHERE id_usuario = ? AND $filtro AND (status IN ('processando','pendente') OR processado_em >= ?)");
    $stmt->execute([$id_usuario, $fluxo > 0 ? $fluxo : (int) ($c['bot_id'] ?? 0), date('Y-m-d H:i:s', time() - 6 * 3600)]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Inicia a campanha agora ($quando null), agenda para uma data ('Y-m-d H:i') ou liga a recorrência ($recorrencia, já conferida por
 * remarketingNormalizarFrequencia: sem $quando o servidor calcula a próxima ocorrência; "a cada X horas" leva o $quando do primeiro envio).
 * Serve também para REENVIAR uma campanha concluída ou com falha e para mudar o agendamento de uma que já está agendada.
 * Um fluxo só envia remarketing a cada 3 horas (remarketingBloqueioDoGrupo). Congela os bots e confere que há quem receber.
 * Devolve ['sucesso' => bool, 'mensagem' => string].
 */
function remarketingIniciar(PDO $pdo, int $id_usuario, int $id, ?string $quando = null, ?array $recorrencia = null): array
{
    $c = remarketingLinha($pdo, $id_usuario, $id);
    if (!$c) {
        return ['sucesso' => false, 'mensagem' => 'Campanha não encontrada.'];
    }
    if (!remarketingAgendavel($c)) {
        return ['sucesso' => false, 'mensagem' => 'Esta campanha está sendo enviada agora. Quando terminar, dá para reenviar ou agendar de novo.'];
    }
    if ($quando !== null && !preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/', trim($quando))) {
        return ['sucesso' => false, 'mensagem' => 'Informe a data e a hora do envio.'];
    }
    $agora = time();
    $ts = $quando === null ? ($recorrencia === null || $recorrencia['tipo'] === 'a_cada' ? $agora : null) : strtotime($quando);
    if ($quando !== null && $ts === false) {
        return ['sucesso' => false, 'mensagem' => 'Informe a data e a hora do envio.'];
    }
    if ($quando !== null && $ts < $agora - 60) {
        return ['sucesso' => false, 'mensagem' => 'Esse horário já passou. Escolha uma data e hora futuras.'];
    }
    $grupo = remarketingGrupoDeCampanhas($pdo, $id_usuario, $c);
    if ($ts === null) {
        // Diário, horários ou dias da semana sem data: a próxima ocorrência a partir de agora, depois de liberado o intervalo do fluxo.
        $livre = remarketingBloqueioDoGrupo($grupo, $id, $agora);
        $ts = remarketingProximaExecucao($recorrencia, max($agora, (int) ($livre['livre_em'] ?? 0)));
        if ($ts === null) {
            return ['sucesso' => false, 'mensagem' => 'Não consegui calcular o próximo envio. Confira os dias e os horários.'];
        }
    }
    $bloqueio = remarketingBloqueioDoGrupo($grupo, $id, $ts);
    if ($bloqueio !== null) {
        return ['sucesso' => false, 'mensagem' => $bloqueio['mensagem']];
    }
    $id_fluxo = (int) ($c['id_fluxo'] ?? 0);
    if ($id_fluxo > 0) {
        $bots = remarketingBotsDoFluxo($pdo, $id_usuario, $id_fluxo);
    } else {
        // Campanha do modelo antigo: sai pelo bot dela (se ele ainda existe).
        $stmt = $pdo->prepare('SELECT id FROM bots WHERE id = ? AND id_usuario = ?');
        $stmt->execute([(int) ($c['bot_id'] ?? 0), $id_usuario]);
        $bots = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    if (!$bots) {
        return ['sucesso' => false, 'mensagem' => $id_fluxo > 0 ? 'O fluxo desta campanha não tem bot vinculado. Vincule um bot a ele para enviar.' : 'O bot desta campanha não existe mais.'];
    }
    $ids = array_column($bots, 'id');
    $intervalo = remarketingIntervaloPeriodo((string) ($c['periodo_tipo'] ?? 'total'), substr((string) ($c['periodo_inicio'] ?? ''), 0, 10), substr((string) ($c['periodo_fim'] ?? ''), 0, 10), $ts);
    $total = remarketingContarPublico($pdo, $ids, (string) $c['audiencia'], $intervalo[0] ?? null, $intervalo[1] ?? null);
    if ($total === 0) {
        return ['sucesso' => false, 'mensagem' => 'Esse público não tem ninguém agora. Ajuste o segmento ou o período da campanha.'];
    }
    if (!empty(remarketingOfertasDaCampanha($c)['planos'])) {
        require_once __DIR__ . '/gateways.php';
        // getUserGateways() devolve a gateway da plataforma mesmo para quem nunca configurou (credenciais vazias): conta só a que tem credencial, como o Pix faz.
        $configurados = array_filter(getUserGateways($id_usuario, true), static fn($g) => !empty($g['client_id']) && !empty($g['client_secret']));
        if (!$configurados) {
            return ['sucesso' => false, 'mensagem' => 'Configure um gateway de pagamento antes de enviar uma campanha com planos (em Gateways).'];
        }
    }
    // Os contadores (enviados, entregues, falhas, offset) ficam como estão: nos reenvios eles são os da execução anterior e o cron os guarda e zera quando a nova começa.
    // Basta o status: a campanha vira "processando" no mesmo instante em que o cron a pega, e aí esta atualização não pega mais.
    $stmt = $pdo->prepare("UPDATE remarketing_campanhas SET status = 'pendente', agendado_em = ?, bots_ids = ?, recorrencia = ? WHERE id = ? AND id_usuario = ? AND status IN ('rascunho','pendente','concluida','falha')");
    $stmt->execute([date('Y-m-d H:i:s', $ts), implode(',', $ids), $recorrencia === null ? null : json_encode($recorrencia), $id, $id_usuario]);
    if ($stmt->rowCount() === 0) {
        // 0 linhas = reagendou para o mesmo horário (nada mudou) ou a campanha começou a sair agora: só o segundo caso é erro.
        $de_novo = remarketingLinha($pdo, $id_usuario, $id);
        if (!$de_novo || !remarketingAgendavel($de_novo)) {
            return ['sucesso' => false, 'mensagem' => 'Esta campanha começou a ser enviada agora. Quando terminar, dá para reenviar ou agendar de novo.'];
        }
    }
    $imediato = $quando === null && $recorrencia === null || $ts <= time() + 60;
    $reenvio = remarketingTemExecucaoAnterior($c);
    $nome = $c['nome'] ?: ('#' . $id);
    if (function_exists('registrarAtividade')) {
        $verbo = $recorrencia !== null ? 'Ativou a recorrência (' . remarketingDescreverRecorrencia($recorrencia) . ') da' : ($imediato ? ($reenvio ? 'Reenviou a' : 'Iniciou a') : 'Agendou a');
        registrarAtividade($id_usuario, 'sistema', 'Remarketing', $verbo . ' campanha: ' . $nome);
    }
    if ($recorrencia !== null) {
        return ['sucesso' => true, 'mensagem' => 'Recorrência ativada: ' . remarketingDescreverRecorrencia($recorrencia) . '. Próximo envio em ' . date('d/m/Y H:i', $ts) . '.'];
    }
    return ['sucesso' => true, 'mensagem' => $imediato
        ? ($reenvio ? 'Campanha reenviada. O envio começa no próximo minuto.' : 'Campanha iniciada. O envio começa no próximo minuto.')
        : 'Campanha agendada para ' . date('d/m/Y H:i', $ts) . '.'];
}

/**
 * Cancela o agendamento: a campanha agendada volta a ser rascunho (nunca saiu) ou concluída (já saiu antes) e a recorrência é desligada.
 * Em envio, só desliga a recorrência: o envio em andamento termina normalmente e a campanha não repete mais.
 */
function remarketingParar(PDO $pdo, int $id_usuario, int $id): array
{
    $c = remarketingLinha($pdo, $id_usuario, $id);
    if (!$c) {
        return ['sucesso' => false, 'mensagem' => 'Campanha não encontrada.'];
    }
    $nome = $c['nome'] ?: ('#' . $id);
    $repete = remarketingRecorrenciaDaCampanha($c) !== null;
    if ($c['status'] === 'pendente') {
        $novo = remarketingTemExecucaoAnterior($c) ? 'concluida' : 'rascunho';
        $stmt = $pdo->prepare("UPDATE remarketing_campanhas SET status = ?, recorrencia = NULL WHERE id = ? AND id_usuario = ? AND status = 'pendente'");
        $stmt->execute([$novo, $id, $id_usuario]);
        if ($stmt->rowCount() === 0) {
            return ['sucesso' => false, 'mensagem' => 'Esta campanha começou a ser enviada agora e não dá mais para cancelar o envio.'];
        }
        $mensagem = $repete ? 'Recorrência desativada. A campanha não será mais enviada sozinha.' : 'Agendamento cancelado. A campanha voltou para ' . ($novo === 'rascunho' ? 'rascunho.' : 'concluída.');
    } elseif ($repete) {
        $pdo->prepare('UPDATE remarketing_campanhas SET recorrencia = NULL WHERE id = ? AND id_usuario = ?')->execute([$id, $id_usuario]);
        $mensagem = $c['status'] === 'processando'
            ? 'Recorrência desativada. O envio em andamento termina normalmente e a campanha não repete mais.'
            : 'Recorrência desativada.';
    } else {
        return ['sucesso' => false, 'mensagem' => 'Esta campanha não está agendada.'];
    }
    if (function_exists('registrarAtividade')) {
        registrarAtividade($id_usuario, 'sistema', 'Remarketing', ($repete ? 'Desativou a recorrência da' : 'Cancelou o agendamento da') . ' campanha: ' . $nome);
    }
    return ['sucesso' => true, 'mensagem' => $mensagem];
}

/** Apaga a campanha (e, em cascata, o registro de envios e de vendas dela). Uma campanha em envio é interrompida. */
function remarketingExcluir(PDO $pdo, int $id_usuario, int $id): array
{
    $c = remarketingLinha($pdo, $id_usuario, $id);
    if (!$c) {
        return ['sucesso' => false, 'mensagem' => 'Campanha não encontrada.'];
    }
    $pdo->prepare('DELETE FROM remarketing_campanhas WHERE id = ? AND id_usuario = ?')->execute([$id, $id_usuario]);
    if (function_exists('registrarAtividade')) {
        registrarAtividade($id_usuario, 'sistema', 'Remarketing', 'Excluiu a campanha: ' . ($c['nome'] ?: ('#' . $id)));
    }
    return ['sucesso' => true, 'mensagem' => $c['status'] === 'processando' ? 'Campanha excluída. O envio foi interrompido.' : 'Campanha excluída.'];
}

/** Receita (vendas pagas) por campanha: [id da campanha => total]. */
function remarketingReceitas(PDO $pdo, array $ids): array
{
    $ids = remarketingIdsInteiros($ids);
    if (!$ids) {
        return [];
    }
    $stmt = $pdo->query("SELECT rv.campanha_id, SUM(v.valor) AS receita FROM remarketing_vendas rv JOIN vendas v ON v.id = rv.venda_id AND v.status = 'pago' WHERE rv.campanha_id IN (" . implode(',', $ids) . ') GROUP BY rv.campanha_id');
    $saida = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $saida[(int) $l['campanha_id']] = (float) $l['receita'];
    }
    return $saida;
}

/** O que a lista mostra: o resumo dos 4 cartões e as campanhas (as mais novas primeiro) com os números de cada uma. */
function remarketingListar(PDO $pdo, int $id_usuario): array
{
    $stmt = $pdo->prepare("SELECT c.id, c.nome, c.id_fluxo, c.bot_id, c.audiencia, c.periodo_tipo, c.status, c.agendado_em, c.criado_em, c.total_destinatarios, c.enviados, c.entregues, c.falhas, c.offset_envio,
            c.processado_em, c.recorrencia, c.execucoes, c.acum_entregues, c.acum_falhas,
            f.nome AS nome_fluxo, COALESCE(b.primeiro_nome, b.nome_usuario) AS nome_bot, c.ofertas
        FROM remarketing_campanhas c
        LEFT JOIN fluxos f ON f.id = c.id_fluxo AND f.id_usuario = c.id_usuario
        LEFT JOIN bots b ON b.id = c.bot_id
        WHERE c.id_usuario = ?
        ORDER BY c.criado_em DESC, c.id DESC
        LIMIT " . REMARKETING_CAMPANHAS_MAX);
    $stmt->execute([$id_usuario]);
    $linhas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $receitas = remarketingReceitas($pdo, array_column($linhas, 'id'));

    $stmt = $pdo->prepare('SELECT id_fluxo_conectado, COUNT(*) FROM bots WHERE id_usuario = ? AND id_fluxo_conectado IS NOT NULL GROUP BY id_fluxo_conectado');
    $stmt->execute([$id_usuario]);
    $bots_por_fluxo = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $agora = time();
    // O relógio de 3 horas é por fluxo (campanha antiga, por bot): cada campanha vê as do seu grupo.
    $por_grupo = [];
    foreach ($linhas as $l) {
        $por_grupo[remarketingChaveGrupo($l)][] = $l;
    }
    $campanhas = [];
    $resumo = ['total' => count($linhas), 'ativas' => 0, 'enviadas' => 0, 'receita' => 0.0];
    foreach ($linhas as $l) {
        $id = (int) $l['id'];
        $receita = $receitas[$id] ?? 0.0;
        $status = (string) $l['status'];
        $grupo = $por_grupo[remarketingChaveGrupo($l)];
        $bloqueio = remarketingBloqueioDoGrupo($grupo, $id, $agora);
        // Agendada para já, mas esperando o fluxo liberar (outra campanha em envio ou envio recente): não está em envio.
        $retida = $status === 'pendente' && strtotime((string) $l['agendado_em']) <= $agora && $bloqueio !== null && $bloqueio['motivo'] !== 'agendada_perto';
        $em_envio = $status === 'processando' || ($status === 'pendente' && strtotime((string) $l['agendado_em']) <= $agora && !$retida);
        $rec = remarketingRecorrenciaDaCampanha($l);
        $resumo['ativas'] += $em_envio ? 1 : 0;
        $resumo['enviadas'] += (int) $l['entregues'] + (int) $l['acum_entregues'];
        $resumo['receita'] += $receita;
        $id_fluxo = (int) ($l['id_fluxo'] ?? 0);
        $campanhas[] = [
            'id' => $id,
            'nome' => (string) ($l['nome'] ?: ('Campanha #' . $id)),
            'grupo' => $id_fluxo > 0 ? 'f' . $id_fluxo : 'b' . (int) $l['bot_id'],
            'grupo_nome' => $id_fluxo > 0 ? (string) ($l['nome_fluxo'] ?? 'Fluxo removido') : (string) ($l['nome_bot'] ? 'Bot ' . $l['nome_bot'] : 'Bot removido'),
            'grupo_bots' => $id_fluxo > 0 ? (int) ($bots_por_fluxo[$id_fluxo] ?? 0) : 1,
            'grupo_antigo' => $id_fluxo === 0,
            'status' => $status,
            'status_rotulo' => REMARKETING_STATUS_ROTULOS[$status] ?? $status,
            'em_envio' => $em_envio,
            'retida' => $retida,
            'segmento' => remarketingRotuloSegmento((string) $l['audiencia']),
            'total' => (int) $l['total_destinatarios'],
            'enviadas' => (int) $l['entregues'] + (int) $l['acum_entregues'],   // de todas as execuções; o "restante" e o "total" são os da atual
            'falhas' => (int) $l['falhas'] + (int) $l['acum_falhas'],
            'execucoes' => max((int) $l['execucoes'], remarketingTemExecucaoAnterior($l) || $status === 'processando' ? 1 : 0),
            'recorrente' => $rec !== null,
            'recorrencia' => $rec,
            'recorrencia_rotulo' => $rec !== null ? remarketingDescreverRecorrencia($rec) : '',
            'agendavel' => remarketingAgendavel($l),
            'reenviavel' => remarketingReenviavel($l),
            // Por que não dá para iniciar/reenviar AGORA (o botão mostra o motivo e quando libera); null = pode.
            'bloqueio' => ($status !== 'pendente' && $status !== 'processando' && $bloqueio !== null)
                ? ['motivo' => $bloqueio['motivo'], 'mensagem' => $bloqueio['mensagem'], 'libera_em' => $bloqueio['livre_em'] ? date('d/m H:i', $bloqueio['livre_em']) : '']
                : null,
            'libera_em' => $retida && $bloqueio['livre_em'] ? date('d/m H:i', $bloqueio['livre_em']) : '',
            'restantes' => max(0, (int) $l['total_destinatarios'] - (int) $l['enviados']),
            'receita' => $receita,
            'agendado_em' => remarketingDataCurta($l['agendado_em']),
            'criado_em' => (string) $l['criado_em'],
            'editavel' => $id_fluxo > 0 && remarketingEditavel($l),
            'iniciavel' => $status === 'rascunho',
            'tem_log' => (int) $l['enviados'] > 0 || $status === 'concluida' || $status === 'falha',
            'tem_oferta' => remarketingTemOferta(remarketingOfertasDaCampanha($l)),
        ];
    }
    return ['resumo' => $resumo, 'campanhas' => $campanhas];
}

/** Envia ao navegador o CSV do registro de envios da campanha (quem recebeu, quem falhou, qual variante). Encerra a requisição. */
function remarketingExportarCsv(PDO $pdo, int $id_usuario, int $id): void
{
    $c = remarketingLinha($pdo, $id_usuario, $id);
    if (!$c) {
        http_response_code(404);
        echo 'Campanha não encontrada.';
        exit;
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="campanha-' . $id . '-envios.csv"');
    $saida = fopen('php://output', 'w');
    fwrite($saida, "\xEF\xBB\xBF");
    fputcsv($saida, ['ID Telegram', 'Resultado', 'Variante', 'Data', 'Resposta do Telegram'], ';');
    $stmt = $pdo->prepare('SELECT id_telegram, resultado, variante, enviado_em, resposta FROM remarketing_envios WHERE campanha_id = ? ORDER BY id LIMIT 200000');
    $stmt->execute([$id]);
    while ($l = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $resposta = (string) $l['resposta'];
        // texto que começa com = + - @ vira fórmula no Excel
        if ($resposta !== '' && strpos('=+-@', $resposta[0]) !== false) {
            $resposta = "'" . $resposta;
        }
        fputcsv($saida, [$l['id_telegram'], $l['resultado'], chr(65 + (int) $l['variante']), $l['enviado_em'], $resposta], ';');
    }
    fclose($saida);
    exit;
}
