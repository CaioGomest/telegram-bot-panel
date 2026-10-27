<?php
declare(strict_types=1);

/**
 * Avisos push (Web Push) de Pix gerado e Pix pago — parte que fala com o banco e com os serviços de push.
 * Plano completo: deploy/PLANO-PUSH-NOTIFICACOES.md. A parte pura (criptografia, VAPID, validações, agrupamento) fica em push_cripto.php.
 *
 * Todo aviso NASCE NA FILA (push_fila, 1 INSERT) e é entregue por um de três caminhos, escolhido sozinho:
 *   A) trabalhador vivo (cron/push_worker.php --loop dando sinal em storage/push_batimento): pega em até ~100 ms e envia por conexões HTTP/2 já abertas;
 *   B) sem trabalhador: o próprio processo envia DEPOIS de responder ao chamador (fastcgi_finish_request) — enviarPushesPosResposta();
 *   C) o que falhar ou não couber volta para a fila com espera crescente (o cron a cada minuto é a rede de segurança).
 * Nada daqui pode derrubar uma venda: o que roda no caminho quente (enfileirarPush) engole qualquer exceção.
 */

require_once __DIR__ . '/push_cripto.php';
if (!function_exists('configSistema')) {
    require_once __DIR__ . '/configuracoes.php';
}
if (!function_exists('descriptografarSegredo')) {
    require_once __DIR__ . '/criptografia.php';
}

const PUSH_ARQUIVO_BATIMENTO = __DIR__ . '/../storage/push_batimento';
const PUSH_BATIMENTO_VIVO_SEGUNDOS = 10;
const PUSH_LOTE_TAMANHO = 300;
const PUSH_CONCORRENCIA_PADRAO = 64;
/** Caminho B: acima disto em voo (itens "enviando") o processo do site não tenta enviar, deixa para o trabalhador/cron. */
const PUSH_TETO_EM_VOO_CAMINHO_B = 40;
const PUSH_SOBRECARGA_PENDENTES = 50000;
const PUSH_FALHAS_PARA_DESATIVAR = 20;

// ===================== configuração =====================

/** Interruptor geral (padrão DESLIGADO). Lido uma vez por processo (configSistema tem cache). */
function pushAtivo(bool $recarregar = false): bool
{
    static $ativo = null;
    if ($recarregar && isset($GLOBALS['pdo'])) {
        $ativo = pushLerConfig($GLOBALS['pdo'], 'push_ativo') === '1';   // lê de novo, sem o cache (testes e telas do admin)
    }
    if ($ativo === null) {
        $ativo = function_exists('configSistema') && configSistema('push_ativo') === '1';
    }
    return $ativo;
}

/** Lê uma configuração direto do banco, sem o cache de configSistema(): o trabalhador e as telas do admin precisam do valor de agora. */
function pushLerConfig(PDO $pdo, string $chave): string
{
    try {
        $st = $pdo->prepare('SELECT valor FROM configuracoes WHERE chave = ?');
        $st->execute([$chave]);
        $valor = $st->fetchColumn();
        return $valor === false ? '' : (string) $valor;   // "0" é um valor válido (push_ativo desligado), não "vazio"
    } catch (\Throwable $e) {
        return '';
    }
}

function pushVapidPublica(PDO $pdo): string
{
    return pushLerConfig($pdo, 'push_vapid_publica');
}

function pushVapidPrivadaPem(PDO $pdo): string
{
    return descriptografarSegredo(pushLerConfig($pdo, 'push_vapid_privada'));
}

/** Impressão digital da chave pública (12 primeiros caracteres do sha256): é só o que o admin vê. */
function pushImpressaoDigital(PDO $pdo): string
{
    $publica = pushB64uDecodificar(pushVapidPublica($pdo));
    return $publica === null ? '' : substr(hash('sha256', $publica), 0, 12);
}

/** "sub" do VAPID: a Apple responde 403 se não for https:// ou mailto: válido. Config push_contato > url_base > e-mail do primeiro admin. */
function pushContato(PDO $pdo): string
{
    $contato = trim(pushLerConfig($pdo, 'push_contato'));
    if ($contato === '') {
        $base = trim(pushLerConfig($pdo, 'url_base'));
        if ($base !== '' && preg_match('#^https://#i', $base)) {
            $partes = parse_url($base);
            $contato = 'https://' . ($partes['host'] ?? '');
        }
    }
    if ($contato === '' || $contato === 'https://') {
        try {
            $email = (string) $pdo->query("SELECT email FROM usuarios WHERE perfil = 'admin' ORDER BY id LIMIT 1")->fetchColumn();
        } catch (\Throwable $e) {
            $email = '';
        }
        $contato = $email !== '' ? 'mailto:' . $email : 'mailto:admin@example.com';
    }
    return $contato;
}

/** Gera o par VAPID se ainda não existir (ou se $regenerar). A privada vai cifrada. Regenerar INVALIDA todos os aparelhos inscritos. */
function pushGarantirChaves(PDO $pdo, bool $regenerar = false): bool
{
    if (!$regenerar && pushVapidPublica($pdo) !== '' && pushVapidPrivadaPem($pdo) !== '') {
        return true;
    }
    $par = pushGerarChavesVapid();
    if ($par === null) {
        return false;
    }
    $ok = definirConfigSistema('push_vapid_privada', criptografarSegredo($par['privada_pem'])) && definirConfigSistema('push_vapid_publica', $par['publica']);
    if ($ok && $regenerar) {
        $pdo->exec('DELETE FROM push_inscricoes');   // a fila cai junto (CASCADE): chaves novas = ninguém mais recebe com as antigas
    }
    return $ok;
}

// ===================== trabalhador vivo? =====================

/** Sinal de vida do trabalhador: o arquivo é tocado a cada ciclo. Lido por filemtime (nada de consulta ao banco no caminho quente). */
function pushTrabalhadorVivo(): bool
{
    clearstatcache(true, PUSH_ARQUIVO_BATIMENTO);
    $m = @filemtime(PUSH_ARQUIVO_BATIMENTO);
    return $m !== false && (time() - $m) < PUSH_BATIMENTO_VIVO_SEGUNDOS;
}

function pushBatimento(): void
{
    $dir = dirname(PUSH_ARQUIVO_BATIMENTO);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    @touch(PUSH_ARQUIVO_BATIMENTO);
}

// ===================== entrada na fila (caminho quente) =====================

/**
 * Chamado quando uma cobrança Pix é criada ($tipo 1) ou paga ($tipo 2). Um único INSERT … SELECT das inscrições ativas do vendedor com a
 * preferência ligada: 0 linhas para quem não ativou. NUNCA lança.
 */
function enfileirarPush(int $id_usuario, int $tipo, int $id_venda): void
{
    if ($id_usuario <= 0 || $id_venda <= 0 || !isset(PUSH_VALIDADE_SEGUNDOS[$tipo]) || !pushAtivo()) {
        return;
    }
    try {
        global $pdo;
        $preferencia = $tipo === PUSH_TIPO_PIX_PAGO ? 'pref_pix_pago' : 'pref_pix_gerado';
        $viva = pushTrabalhadorVivo();
        $st = $pdo->prepare(
            "INSERT IGNORE INTO push_fila (id_inscricao, tipo, id_venda, disponivel_em, expira_em)
             SELECT id, ?, ?, NOW() + INTERVAL ? SECOND, NOW() + INTERVAL ? SECOND
             FROM push_inscricoes WHERE id_usuario = ? AND ativo = 1 AND $preferencia = 1"
        );
        // Caminho B: 2 s de folga para o trabalhador/cron não pegar antes de o próprio processo tentar enviar.
        $st->execute([$tipo, $id_venda, $viva ? 0 : 2, PUSH_VALIDADE_SEGUNDOS[$tipo], $id_usuario]);
        if (!$viva && $st->rowCount() > 0) {
            pushAgendarEnvioImediato($id_usuario, $tipo, $id_venda);
        }
    } catch (\Throwable $e) {
        error_log('[push] enfileirarPush: ' . $e->getMessage());
    }
}

/** Caminho B: guarda o que esta requisição enfileirou e registra (uma vez) o envio para depois da resposta. */
function pushAgendarEnvioImediato(int $id_usuario, int $tipo, int $id_venda): void
{
    static $registrado = false;
    $GLOBALS['push_envio_imediato'][] = [$id_usuario, $tipo, $id_venda];
    if (!$registrado) {
        $registrado = true;
        ignore_user_abort(true);
        register_shutdown_function('enviarPushesPosResposta');
    }
}

/**
 * Caminho B. Roda no fim da requisição: PRIMEIRO responde ao chamador (webhook da gateway/Telegram não espera o push), só então envia.
 * Reserva só os itens que esta requisição criou (atômico: o trabalhador/cron nunca envia o mesmo item), com teto de envios em voo.
 */
function enviarPushesPosResposta(): void
{
    $pendentes = $GLOBALS['push_envio_imediato'] ?? [];
    $GLOBALS['push_envio_imediato'] = [];
    if (!$pendentes) {
        return;
    }
    if (function_exists('fastcgi_finish_request')) {
        @fastcgi_finish_request();
    }
    try {
        if (function_exists('garantirConexaoBanco')) {
            garantirConexaoBanco();   // atualiza o $pdo global se a conexão caiu
        }
        global $pdo;
        if (!isset($pdo)) {
            return;
        }
        $em_voo = (int) $pdo->query("SELECT COUNT(*) FROM push_fila WHERE estado = 'enviando'")->fetchColumn();
        if ($em_voo > PUSH_TETO_EM_VOO_CAMINHO_B) {
            return;   // deixa na fila: o trabalhador (em lote, conexão aquecida) dá conta melhor que um processo do site
        }
        $ids = [];
        $st = $pdo->prepare("SELECT f.id FROM push_fila f JOIN push_inscricoes i ON i.id = f.id_inscricao
                             WHERE f.tipo = ? AND f.id_venda = ? AND i.id_usuario = ? AND f.estado = 'pendente'");
        foreach ($pendentes as [$id_usuario, $tipo, $id_venda]) {
            $st->execute([$tipo, $id_venda, $id_usuario]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $ids[] = (int) $id;
            }
        }
        $lote = $ids ? pushReservarIds($pdo, $ids) : null;
        if ($lote !== null) {
            pushProcessarLoteReservado($pdo, $lote, ['concorrencia' => 16, 'timeout' => 6, 'conexao_timeout' => 3]);
        }
    } catch (\Throwable $e) {
        error_log('[push] enviarPushesPosResposta: ' . $e->getMessage());
    }
}

// ===================== reserva de itens =====================

function pushNovoLote(): string
{
    return bin2hex(random_bytes(8));
}

/** Reserva até $limite itens prontos (atômico: vários trabalhadores seguros, sem SKIP LOCKED). Devolve o id do lote ou null. */
function pushReservarLote(PDO $pdo, int $limite = PUSH_LOTE_TAMANHO): ?string
{
    $lote = pushNovoLote();
    $st = $pdo->prepare(
        "UPDATE push_fila SET estado = 'enviando', reservado_ate = NOW() + INTERVAL 60 SECOND, lote = ?
         WHERE estado = 'pendente' AND disponivel_em <= NOW() ORDER BY disponivel_em, id LIMIT " . max(1, $limite)
    );
    $st->execute([$lote]);
    return $st->rowCount() > 0 ? $lote : null;
}

/** Reserva itens específicos (caminho B). Só os que ainda estavam pendentes: quem perdeu a corrida não envia. */
function pushReservarIds(PDO $pdo, array $ids): ?string
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (!$ids) {
        return null;
    }
    $lote = pushNovoLote();
    $st = $pdo->prepare(
        "UPDATE push_fila SET estado = 'enviando', reservado_ate = NOW() + INTERVAL 30 SECOND, lote = ?
         WHERE estado = 'pendente' AND id IN (" . implode(',', $ids) . ')'
    );
    $st->execute([$lote]);
    return $st->rowCount() > 0 ? $lote : null;
}

function pushCarregarLote(PDO $pdo, string $lote): array
{
    $st = $pdo->prepare(
        "SELECT f.id, f.id_inscricao, f.tipo, f.id_venda, f.tentativas, UNIX_TIMESTAMP(f.criado_em) AS criado_ts,
                TIMESTAMPDIFF(MICROSECOND, f.criado_em, NOW(3)) / 1000 AS idade_ms,
                i.id_usuario, i.endpoint, i.p256dh, i.auth, i.ativo, UNIX_TIMESTAMP(i.ultimo_aviso_gerado_em) AS ultimo_aviso_gerado_ts,
                v.valor, v.nome_produto AS produto, b.nome_usuario AS bot
         FROM push_fila f
         JOIN push_inscricoes i ON i.id = f.id_inscricao
         LEFT JOIN vendas v ON v.id = f.id_venda
         LEFT JOIN bots b ON b.id = v.bot_id
         WHERE f.lote = ?"
    );
    $st->execute([$lote]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

// ===================== envio HTTP em paralelo =====================

/**
 * Envia em paralelo (curl_multi, HTTP/2 com multiplexação) e devolve [chave => ['http', 'erro', 'retry_after', 'ms']].
 * $envios: lista de ['chave', 'url', 'corpo', 'cabecalhos' => ['Nome: valor']]. O curl_multi fica em memória do processo: no trabalhador
 * as conexões com FCM/Mozilla/Apple ficam abertas entre os ciclos (sem DNS/TLS por aviso). Redirecionamento DESLIGADO; só https (http só no modo de teste).
 */
function pushEnviarEmParalelo(array $envios, int $concorrencia = PUSH_CONCORRENCIA_PADRAO, int $timeout = 10, int $conexao_timeout = 3): array
{
    static $multi = null;
    if ($multi === null) {
        $multi = curl_multi_init();
        curl_multi_setopt($multi, CURLMOPT_PIPELINING, CURLPIPE_MULTIPLEX);
    }
    $protocolos = pushModoTeste() ? (CURLPROTO_HTTP | CURLPROTO_HTTPS) : CURLPROTO_HTTPS;
    $resultados = [];
    $fila = array_values($envios);
    $ativos = [];

    $iniciar = function (array $envio) use ($multi, &$ativos, $protocolos, $timeout, $conexao_timeout) {
        $ch = curl_init($envio['url']);
        $retry_after = '';
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $envio['corpo'],
            CURLOPT_HTTPHEADER => $envio['cabecalhos'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => $protocolos,
            CURLOPT_REDIR_PROTOCOLS => $protocolos,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $conexao_timeout,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_TCP_KEEPALIVE => 1,
            CURLOPT_HEADERFUNCTION => function ($c, string $linha) use (&$retry_after) {
                if (stripos($linha, 'retry-after:') === 0) {
                    $retry_after = trim(substr($linha, 12));
                }
                return strlen($linha);
            },
        ]);
        if (stripos($envio['url'], 'https://') === 0) {
            curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2TLS);
        }
        curl_multi_add_handle($multi, $ch);
        $ativos[spl_object_id($ch)] = ['ch' => $ch, 'chave' => $envio['chave'], 'retry' => &$retry_after];
    };

    while ($fila || $ativos) {
        while ($fila && count($ativos) < max(1, $concorrencia)) {
            $iniciar(array_shift($fila));
        }
        do {
            $status = curl_multi_exec($multi, $executando);
        } while ($status === CURLM_CALL_MULTI_PERFORM);
        while ($info = curl_multi_info_read($multi)) {
            $ch = $info['handle'];
            $id = spl_object_id($ch);
            if (!isset($ativos[$id])) {
                continue;
            }
            $resultados[$ativos[$id]['chave']] = [
                'http' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
                'erro' => $info['result'] !== CURLE_OK ? curl_strerror($info['result']) : '',
                'retry_after' => pushRetryAfterSegundos((string) $ativos[$id]['retry']),
                'ms' => (int) round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000),
            ];
            curl_multi_remove_handle($multi, $ch);
            unset($ativos[$id]);
        }
        if ($ativos) {
            curl_multi_select($multi, 0.05);
        }
    }
    return $resultados;
}

// ===================== processar um lote reservado =====================

/** Pausa por host quando o serviço de push devolveu 429 (disjuntor). Memória do processo. */
function pushHostPausadoAte(string $host, ?int $ate = null): int
{
    static $pausas = [];
    if ($ate !== null) {
        $pausas[$host] = $ate;
    }
    return $pausas[$host] ?? 0;
}

function pushIdsSql(array $ids): string
{
    return implode(',', array_map('intval', $ids));
}

/**
 * Pega o que foi reservado com $lote, agrupa (rajadas), monta + criptografa + assina, envia e trata cada resposta.
 * Devolve contadores. $opcoes: concorrencia, timeout, conexao_timeout.
 */
function pushProcessarLoteReservado(PDO $pdo, string $lote, array $opcoes = []): array
{
    $stats = ['itens' => 0, 'enviados' => 0, 'falhas_temporarias' => 0, 'inscricoes_removidas' => 0, 'descartados' => 0, 'agrupados' => 0, 'erros_fatais' => 0,
        'devolvidos' => 0, 'latencia_soma_ms' => 0, 'latencia_n' => 0, 'latencia_max_ms' => 0];
    $t_carga = microtime(true);
    $itens = pushCarregarLote($pdo, $lote);
    $stats['itens'] = count($itens);
    if (!$itens) {
        return $stats;
    }
    $agora = (int) $pdo->query('SELECT UNIX_TIMESTAMP()')->fetchColumn();

    // Aparelho desativado entre a entrada na fila e agora: os itens dele saem.
    $inativos = array_values(array_map(fn($i) => (int) $i['id'], array_filter($itens, fn($i) => (int) $i['ativo'] !== 1)));
    if ($inativos) {
        $pdo->exec('DELETE FROM push_fila WHERE id IN (' . pushIdsSql($inativos) . ')');
        $stats['descartados'] += count($inativos);
        $itens = array_values(array_filter($itens, fn($i) => (int) $i['ativo'] === 1));
    }

    $grupo = pushAgruparLote($itens, $agora);

    foreach ($grupo['devolver'] as $d) {
        $pdo->prepare("UPDATE push_fila SET estado = 'pendente', lote = NULL, reservado_ate = NULL, disponivel_em = FROM_UNIXTIME(?) WHERE id IN (" . pushIdsSql($d['ids']) . ')')
            ->execute([$d['disponivel_ts']]);
        $stats['devolvidos'] += count($d['ids']);
    }
    $janela_gerado_ok = [];   // aparelhos cujo aviso de Pix gerado foi entregue: só então a janela de 30 s começa

    $publica = pushVapidPublica($pdo);
    $privada = pushVapidPrivadaPem($pdo);
    $contato = pushContato($pdo);
    $versao = pushLerConfig($pdo, 'push_icone_v');
    if ($publica === '' || $privada === '') {
        // Sem chaves não dá para assinar: devolve tudo à fila (o admin vê "chaves ausentes" na aba) em vez de perder avisos.
        $ids = array_map(fn($i) => (int) $i['id'], $itens);
        $pdo->exec("UPDATE push_fila SET estado = 'pendente', lote = NULL, reservado_ate = NULL, disponivel_em = NOW() + INTERVAL 30 SECOND WHERE id IN (" . pushIdsSql($ids) . ')');
        $stats['erros_fatais']++;
        return $stats;
    }

    // Monta uma mensagem por aviso individual e uma por resumo.
    $mensagens = [];
    foreach ($grupo['enviar'] as $item) {
        $mensagens[] = ['ids' => [(int) $item['id']], 'item' => $item, 'dados' => $item, 'tipo' => (int) $item['tipo'], 'kind' => (int) $item['tipo'] === PUSH_TIPO_PIX_PAGO ? 'pp' : 'pg', 'topic' => null];
    }
    foreach ($grupo['resumos'] as $r) {
        $mensagens[] = ['ids' => $r['ids'], 'item' => $r['item_ref'], 'dados' => ['kind' => $r['kind'], 'n' => $r['n'], 'total' => $r['total']],
            'tipo' => $r['kind'] === 'rp' ? PUSH_TIPO_PIX_PAGO : PUSH_TIPO_PIX_GERADO, 'kind' => $r['kind'], 'topic' => $r['kind']];
        $stats['agrupados'] += count($r['ids']);
    }

    $envios = [];
    $mapa = [];
    $n = 0;
    foreach ($mensagens as $m) {
        $item = $m['item'];
        $chave = 'e' . (++$n);
        $json = pushJson(pushMontarMensagem($m['dados'], $versao, $agora));
        $corpo = pushCriptografarPayload($json, (string) $item['p256dh'], (string) $item['auth']);
        $vapid = pushCabecalhoVapid((string) $item['endpoint'], $publica, $privada, $contato, $agora);
        $host = (string) (parse_url((string) $item['endpoint'], PHP_URL_HOST) ?: '');
        if ($corpo === null || $vapid === null) {
            // Chaves do aparelho inválidas: não adianta repetir. Apaga os itens e desativa a inscrição.
            $pdo->exec('DELETE FROM push_fila WHERE id IN (' . pushIdsSql($m['ids']) . ')');
            $pdo->prepare('UPDATE push_inscricoes SET ativo = 0 WHERE id = ?')->execute([(int) $item['id_inscricao']]);
            $stats['erros_fatais']++;
            $stats['descartados'] += count($m['ids']);
            continue;
        }
        $pausado_ate = pushHostPausadoAte($host);
        if ($pausado_ate > $agora) {
            $pdo->prepare("UPDATE push_fila SET estado = 'pendente', lote = NULL, reservado_ate = NULL, disponivel_em = FROM_UNIXTIME(?) WHERE id IN (" . pushIdsSql($m['ids']) . ')')
                ->execute([$pausado_ate]);
            $stats['devolvidos'] += count($m['ids']);
            continue;
        }
        $cabecalhos = [
            'Authorization: ' . $vapid,
            'TTL: ' . PUSH_VALIDADE_SEGUNDOS[$m['tipo']],
            'Urgency: ' . ($m['tipo'] === PUSH_TIPO_PIX_PAGO ? 'high' : 'normal'),
            'Content-Encoding: aes128gcm',
            'Content-Type: application/octet-stream',
        ];
        if ($m['topic'] !== null) {
            $cabecalhos[] = 'Topic: ' . $m['topic'];
        }
        $envios[] = ['chave' => $chave, 'url' => (string) $item['endpoint'], 'corpo' => $corpo, 'cabecalhos' => $cabecalhos];
        $mapa[$chave] = $m + ['host' => $host];
    }

    $resultados = $envios ? pushEnviarEmParalelo($envios, (int) ($opcoes['concorrencia'] ?? PUSH_CONCORRENCIA_PADRAO), (int) ($opcoes['timeout'] ?? 10), (int) ($opcoes['conexao_timeout'] ?? 3)) : [];

    $ok_inscricoes = [];
    foreach ($resultados as $chave => $res) {
        $m = $mapa[$chave];
        $item = $m['item'];
        $ids = pushIdsSql($m['ids']);
        $id_insc = (int) $item['id_inscricao'];
        $classe = pushClassificarResposta((int) $res['http'], (string) $res['erro']);
        if ($classe === 'ok') {
            $pdo->exec("DELETE FROM push_fila WHERE id IN ($ids)");
            $ok_inscricoes[$id_insc] = true;
            if ($m['kind'] === 'pg' || $m['kind'] === 'rg') {
                $janela_gerado_ok[$id_insc] = true;
            }
            $stats['enviados']++;
            $latencia = (int) round((float) $item['idade_ms'] + (microtime(true) - $t_carga) * 1000);
            $stats['latencia_soma_ms'] += $latencia;
            $stats['latencia_n']++;
            $stats['latencia_max_ms'] = max($stats['latencia_max_ms'], $latencia);
        } elseif ($classe === 'morta') {
            $pdo->prepare('DELETE FROM push_inscricoes WHERE id = ?')->execute([$id_insc]);   // a fila dela cai junto (CASCADE)
            $stats['inscricoes_removidas']++;
        } elseif ($classe === 'assinatura' || $classe === 'grande') {
            $pdo->exec("DELETE FROM push_fila WHERE id IN ($ids)");
            $stats['erros_fatais']++;
            $stats['descartados'] += count($m['ids']);
            error_log('[push] resposta ' . $res['http'] . ' de ' . $m['host'] . ' (assinatura/tamanho): verificar chaves VAPID e contato');
        } elseif ($classe === 'limite') {
            $espera = max(5, (int) $res['retry_after'] ?: 30);
            pushHostPausadoAte($m['host'], $agora + $espera);
            $pdo->prepare("UPDATE push_fila SET estado = 'pendente', lote = NULL, reservado_ate = NULL, disponivel_em = NOW() + INTERVAL ? SECOND WHERE id IN ($ids)")->execute([$espera]);
            $stats['falhas_temporarias']++;
        } else {   // temporario
            $stats['falhas_temporarias']++;
            $tentativas = (int) $item['tentativas'] + 1;
            if (!pushPodeTentarDeNovo($m['tipo'], $tentativas)) {
                $pdo->exec("DELETE FROM push_fila WHERE id IN ($ids)");
                $stats['descartados'] += count($m['ids']);
            } else {
                $pdo->prepare("UPDATE push_fila SET estado = 'pendente', lote = NULL, reservado_ate = NULL, tentativas = ?, disponivel_em = NOW() + INTERVAL ? SECOND WHERE id IN ($ids)")
                    ->execute([$tentativas, pushEsperaSegundos($m['tipo'], $tentativas)]);
            }
            $pdo->prepare('UPDATE push_inscricoes SET falhas_seguidas = falhas_seguidas + 1, primeira_falha_em = COALESCE(primeira_falha_em, NOW()) WHERE id = ?')->execute([$id_insc]);
        }
    }
    if ($ok_inscricoes) {
        $pdo->exec('UPDATE push_inscricoes SET ultimo_envio_ok = NOW(), falhas_seguidas = 0, primeira_falha_em = NULL WHERE id IN (' . pushIdsSql(array_keys($ok_inscricoes)) . ')');
    }
    if ($janela_gerado_ok) {
        $pdo->prepare('UPDATE push_inscricoes SET ultimo_aviso_gerado_em = FROM_UNIXTIME(?) WHERE id IN (' . pushIdsSql(array_keys($janela_gerado_ok)) . ')')->execute([$agora]);
    }
    pushRegistrarMetricas($pdo, $stats);
    return $stats;
}

// ===================== métricas e manutenção =====================

function pushRegistrarMetricas(PDO $pdo, array $d): void
{
    if (!($d['enviados'] || $d['falhas_temporarias'] || $d['inscricoes_removidas'] || $d['descartados'] || $d['agrupados'] || $d['erros_fatais'])) {
        return;
    }
    try {
        $pdo->prepare(
            "INSERT INTO push_metricas_hora (hora, enviados, falhas_temporarias, inscricoes_removidas, descartados, agrupados, erros_fatais, latencia_soma_ms, latencia_n, latencia_max_ms)
             VALUES (DATE_FORMAT(NOW(), '%Y-%m-%d %H:00:00'), ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE enviados = enviados + VALUES(enviados), falhas_temporarias = falhas_temporarias + VALUES(falhas_temporarias),
                inscricoes_removidas = inscricoes_removidas + VALUES(inscricoes_removidas), descartados = descartados + VALUES(descartados),
                agrupados = agrupados + VALUES(agrupados), erros_fatais = erros_fatais + VALUES(erros_fatais),
                latencia_soma_ms = latencia_soma_ms + VALUES(latencia_soma_ms), latencia_n = latencia_n + VALUES(latencia_n),
                latencia_max_ms = GREATEST(latencia_max_ms, VALUES(latencia_max_ms))"
        )->execute([$d['enviados'], $d['falhas_temporarias'], $d['inscricoes_removidas'], $d['descartados'], $d['agrupados'], $d['erros_fatais'],
            $d['latencia_soma_ms'], $d['latencia_n'], $d['latencia_max_ms']]);
    } catch (\Throwable $e) {
        error_log('[push] métricas: ' . $e->getMessage());
    }
}

/**
 * Manutenção (a cada ~30 s no trabalhador): devolve itens "enviando" cuja reserva venceu (processo morreu no meio), descarta vencidos,
 * desativa aparelho com muitas falhas por mais de 24 h e, em sobrecarga, derruba Pix gerado antigo (Pix pago nunca é descartado por isso).
 */
function pushManutencao(PDO $pdo): array
{
    $r = ['devolvidos' => 0, 'vencidos' => 0, 'desativados' => 0, 'sobrecarga' => false, 'sobrecarga_descartados' => 0];
    $st = $pdo->exec("UPDATE push_fila SET estado = 'pendente', lote = NULL, reservado_ate = NULL, tentativas = tentativas + 1
                      WHERE estado = 'enviando' AND reservado_ate < NOW()");
    $r['devolvidos'] = (int) $st;
    $r['vencidos'] = (int) $pdo->exec('DELETE FROM push_fila WHERE expira_em < NOW()');
    $r['desativados'] = (int) $pdo->exec('UPDATE push_inscricoes SET ativo = 0 WHERE ativo = 1 AND falhas_seguidas >= ' . PUSH_FALHAS_PARA_DESATIVAR . ' AND primeira_falha_em < NOW() - INTERVAL 1 DAY');
    $pendentes = (int) $pdo->query("SELECT COUNT(*) FROM push_fila WHERE estado = 'pendente'")->fetchColumn();
    if ($pendentes > PUSH_SOBRECARGA_PENDENTES) {
        $r['sobrecarga'] = true;
        $r['sobrecarga_descartados'] = (int) $pdo->exec('DELETE FROM push_fila WHERE tipo = ' . PUSH_TIPO_PIX_GERADO . ' AND criado_em < NOW() - INTERVAL 2 MINUTE');
    }
    pushRegistrarMetricas($pdo, ['enviados' => 0, 'falhas_temporarias' => 0, 'inscricoes_removidas' => 0, 'descartados' => $r['vencidos'] + $r['sobrecarga_descartados'],
        'agrupados' => 0, 'erros_fatais' => 0, 'latencia_soma_ms' => 0, 'latencia_n' => 0, 'latencia_max_ms' => 0]);
    if (mt_rand(1, 2880) === 1) {   // ~1x por dia (a cada 30 s): métricas com mais de 30 dias
        $pdo->exec('DELETE FROM push_metricas_hora WHERE hora < NOW() - INTERVAL 30 DAY');
    }
    return $r;
}

/** Um ciclo do trabalhador: reserva um lote e processa. Devolve quantos itens tratou (0 = fila vazia). */
function pushCicloTrabalhador(PDO $pdo, array $opcoes = []): int
{
    $lote = pushReservarLote($pdo, (int) ($opcoes['lote'] ?? PUSH_LOTE_TAMANHO));
    if ($lote === null) {
        return 0;
    }
    return (int) pushProcessarLoteReservado($pdo, $lote, $opcoes)['itens'];
}

// ===================== saúde (alerta ao admin) =====================

/**
 * Chamada pelo cron de métricas (a cada 5 min). Com o interruptor ligado e aparelho ativo, avisa os admins no sino (no máximo 1 vez por hora) se:
 * há aviso pronto esperando há mais de 2 min (ninguém está enviando), ou muitos erros na última hora (5 erros de assinatura/chaves, ou mais de 20%
 * de falhas com pelo menos 20 tentativas). Devolve o texto do problema ou null.
 */
function pushVerificarSaude(PDO $pdo): ?string
{
    if (pushLerConfig($pdo, 'push_ativo') !== '1') {
        return null;
    }
    if ((int) $pdo->query('SELECT COUNT(*) FROM push_inscricoes WHERE ativo = 1')->fetchColumn() === 0) {
        return null;
    }
    $problemas = [];
    $parados = (int) $pdo->query("SELECT COUNT(*) FROM push_fila WHERE estado = 'pendente' AND disponivel_em < NOW() - INTERVAL 2 MINUTE")->fetchColumn();
    if ($parados > 0) {
        $problemas[] = $parados . ' aviso(s) esperando há mais de 2 minutos sem ninguém enviar (trabalhador parado?)';
    }
    $h = $pdo->query('SELECT COALESCE(SUM(enviados), 0) AS env, COALESCE(SUM(falhas_temporarias), 0) AS fal, COALESCE(SUM(erros_fatais), 0) AS fat
                      FROM push_metricas_hora WHERE hora >= NOW() - INTERVAL 1 HOUR')->fetch(PDO::FETCH_ASSOC) ?: ['env' => 0, 'fal' => 0, 'fat' => 0];
    if ((int) $h['fat'] >= 5) {
        $problemas[] = $h['fat'] . ' erros de assinatura/chaves na última hora (confira as chaves VAPID e o contato)';
    }
    $tentativas = (int) $h['env'] + (int) $h['fal'];
    if ($tentativas >= 20 && (int) $h['fal'] / $tentativas > 0.2) {
        $problemas[] = round(100 * (int) $h['fal'] / $tentativas) . '% das tentativas de envio falharam na última hora';
    }
    if (!$problemas) {
        return null;
    }
    $texto = implode('; ', $problemas) . '.';
    require_once __DIR__ . '/log.php';
    foreach ($pdo->query("SELECT id FROM usuarios WHERE perfil = 'admin'")->fetchAll(PDO::FETCH_COLUMN) as $id_admin) {
        $st = $pdo->prepare("SELECT 1 FROM atividades WHERE id_usuario = ? AND titulo = 'Avisos push com problema' AND criado_em > NOW() - INTERVAL 1 HOUR LIMIT 1");
        $st->execute([(int) $id_admin]);
        if (!$st->fetchColumn()) {
            registrarAtividade((int) $id_admin, 'sistema', 'Avisos push com problema', $texto . ' Veja Configurações → Notificações.');
        }
    }
    return $texto;
}

// ===================== aparelhos (inscrições) =====================

/**
 * Registra (ou atualiza) o aparelho do usuário. $dados: subscription {endpoint, keys:{p256dh,auth}}, plataforma (dica), pref_pix_gerado, pref_pix_pago.
 * O mesmo aparelho (endpoint) entrando em OUTRA conta troca de dono (computador compartilhado) e perde a fila da conta anterior.
 */
function pushInscrever(PDO $pdo, int $id_usuario, array $dados, string $user_agent): array
{
    $sub = is_array($dados['subscription'] ?? null) ? $dados['subscription'] : [];
    $endpoint = (string) ($sub['endpoint'] ?? '');
    $p256dh = (string) ($sub['keys']['p256dh'] ?? '');
    $auth = (string) ($sub['keys']['auth'] ?? '');
    if (!pushEndpointValido($endpoint)) {
        return ['sucesso' => false, 'mensagem' => 'Endereço de aviso não aceito.'];
    }
    if (!pushChavesInscricaoValidas($p256dh, $auth)) {
        return ['sucesso' => false, 'mensagem' => 'Chaves do aparelho inválidas.'];
    }
    $aparelho = pushDescreverAparelho($user_agent, (string) ($dados['plataforma'] ?? ''));
    $hash = hash('sha256', $endpoint);
    $gerado = array_key_exists('pref_pix_gerado', $dados) ? (int) (bool) $dados['pref_pix_gerado'] : 1;
    $pago = array_key_exists('pref_pix_pago', $dados) ? (int) (bool) $dados['pref_pix_pago'] : 1;

    $st = $pdo->prepare('SELECT id, id_usuario FROM push_inscricoes WHERE endpoint_hash = ?');
    $st->execute([$hash]);
    $existente = $st->fetch(PDO::FETCH_ASSOC);
    if ($existente) {
        $mesmo_dono = (int) $existente['id_usuario'] === $id_usuario;
        $pdo->prepare('UPDATE push_inscricoes SET id_usuario = ?, p256dh = ?, auth = ?, plataforma = ?, apelido = ?, ativo = 1, falhas_seguidas = 0, primeira_falha_em = NULL,
                        ultimo_visto = NOW(), pref_pix_gerado = IF(?, pref_pix_gerado, ?), pref_pix_pago = IF(?, pref_pix_pago, ?) WHERE id = ?')
            ->execute([$id_usuario, $p256dh, $auth, $aparelho['plataforma'], $aparelho['apelido'], (int) $mesmo_dono, $gerado, (int) $mesmo_dono, $pago, (int) $existente['id']]);
        if (!$mesmo_dono) {
            $pdo->prepare('DELETE FROM push_fila WHERE id_inscricao = ?')->execute([(int) $existente['id']]);
        }
        $id = (int) $existente['id'];
    } else {
        $pdo->prepare('INSERT INTO push_inscricoes (id_usuario, endpoint_hash, endpoint, p256dh, auth, plataforma, apelido, pref_pix_gerado, pref_pix_pago, ultimo_visto)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())')
            ->execute([$id_usuario, $hash, $endpoint, $p256dh, $auth, $aparelho['plataforma'], $aparelho['apelido'], $gerado, $pago]);
        $id = (int) $pdo->lastInsertId();
    }
    // No máximo PUSH_MAX_APARELHOS por vendedor: o mais antigo (menos visto) sai.
    $st = $pdo->prepare('SELECT id FROM push_inscricoes WHERE id_usuario = ? ORDER BY ultimo_visto DESC, id DESC');
    $st->execute([$id_usuario]);
    $todos = $st->fetchAll(PDO::FETCH_COLUMN);
    if (count($todos) > PUSH_MAX_APARELHOS) {
        $pdo->exec('DELETE FROM push_inscricoes WHERE id IN (' . pushIdsSql(array_slice($todos, PUSH_MAX_APARELHOS)) . ')');
    }
    return ['sucesso' => true, 'id' => $id, 'apelido' => $aparelho['apelido'], 'plataforma' => $aparelho['plataforma']];
}

function pushListarAparelhos(PDO $pdo, int $id_usuario): array
{
    $st = $pdo->prepare('SELECT id, endpoint_hash, plataforma, apelido, pref_pix_gerado, pref_pix_pago, ativo, ultimo_envio_ok, ultimo_visto, criado_em,
                                TIMESTAMPDIFF(SECOND, ultimo_visto, NOW()) AS visto_ha_s, TIMESTAMPDIFF(SECOND, ultimo_envio_ok, NOW()) AS envio_ha_s
                         FROM push_inscricoes WHERE id_usuario = ? ORDER BY ultimo_visto DESC, id DESC');
    $st->execute([$id_usuario]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Remove um aparelho do próprio usuário, por id ou pelo endpoint (o "sair" cancela o aparelho atual). Devolve quantos saíram. */
function pushRemoverAparelho(PDO $pdo, int $id_usuario, ?int $id = null, ?string $endpoint = null): int
{
    if ($id !== null && $id > 0) {
        $st = $pdo->prepare('DELETE FROM push_inscricoes WHERE id = ? AND id_usuario = ?');
        $st->execute([$id, $id_usuario]);
        return $st->rowCount();
    }
    if ($endpoint !== null && $endpoint !== '') {
        $st = $pdo->prepare('DELETE FROM push_inscricoes WHERE endpoint_hash = ? AND id_usuario = ?');
        $st->execute([hash('sha256', $endpoint), $id_usuario]);
        return $st->rowCount();
    }
    return 0;
}

function pushAtualizarPreferencias(PDO $pdo, int $id_usuario, int $id, ?bool $gerado, ?bool $pago): bool
{
    $sets = [];
    $params = [];
    if ($gerado !== null) {
        $sets[] = 'pref_pix_gerado = ?';
        $params[] = (int) $gerado;
    }
    if ($pago !== null) {
        $sets[] = 'pref_pix_pago = ?';
        $params[] = (int) $pago;
    }
    if (!$sets) {
        return false;
    }
    $params[] = $id;
    $params[] = $id_usuario;
    $st = $pdo->prepare('UPDATE push_inscricoes SET ' . implode(', ', $sets) . ' WHERE id = ? AND id_usuario = ?');
    $st->execute($params);
    return $st->rowCount() > 0 || pushAparelhoDoUsuario($pdo, $id_usuario, $id) !== null;
}

function pushAparelhoDoUsuario(PDO $pdo, int $id_usuario, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM push_inscricoes WHERE id = ? AND id_usuario = ?');
    $st->execute([$id, $id_usuario]);
    $linha = $st->fetch(PDO::FETCH_ASSOC);
    return $linha ?: null;
}

/** Aviso de teste direto para UM aparelho do usuário (não passa pela fila). Aparelho que o serviço de push diz estar morto é apagado. */
function pushEnviarTeste(PDO $pdo, int $id_usuario, int $id): array
{
    $ap = pushAparelhoDoUsuario($pdo, $id_usuario, $id);
    if ($ap === null) {
        return ['sucesso' => false, 'mensagem' => 'Aparelho não encontrado.'];
    }
    $publica = pushVapidPublica($pdo);
    $privada = pushVapidPrivadaPem($pdo);
    if ($publica === '' || $privada === '' || !pushAtivo()) {
        return ['sucesso' => false, 'mensagem' => 'Os avisos no celular ainda não estão disponíveis.'];
    }
    $json = pushJson(pushMontarMensagem(['kind' => 'teste'], pushLerConfig($pdo, 'push_icone_v')));
    $corpo = pushCriptografarPayload($json, (string) $ap['p256dh'], (string) $ap['auth']);
    $vapid = pushCabecalhoVapid((string) $ap['endpoint'], $publica, $privada, pushContato($pdo));
    if ($corpo === null || $vapid === null) {
        return ['sucesso' => false, 'mensagem' => 'As chaves deste aparelho não funcionam mais. Ative os avisos de novo.'];
    }
    $res = pushEnviarEmParalelo([[
        'chave' => 't', 'url' => (string) $ap['endpoint'], 'corpo' => $corpo,
        'cabecalhos' => ['Authorization: ' . $vapid, 'TTL: 120', 'Urgency: high', 'Content-Encoding: aes128gcm', 'Content-Type: application/octet-stream'],
    ]], 1, 8, 4)['t'];
    $classe = pushClassificarResposta((int) $res['http'], (string) $res['erro']);
    if ($classe === 'ok') {
        $pdo->prepare('UPDATE push_inscricoes SET ultimo_envio_ok = NOW(), falhas_seguidas = 0, primeira_falha_em = NULL WHERE id = ?')->execute([$id]);
        return ['sucesso' => true, 'mensagem' => 'Aviso de teste enviado. Ele deve aparecer em instantes.'];
    }
    if ($classe === 'morta') {
        $pdo->prepare('DELETE FROM push_inscricoes WHERE id = ?')->execute([$id]);
        return ['sucesso' => false, 'morta' => true, 'mensagem' => 'Este aparelho não recebe mais avisos (a permissão foi retirada ou o app foi removido). Ative de novo.'];
    }
    return ['sucesso' => false, 'mensagem' => 'O serviço de avisos do seu navegador não respondeu agora. Tente de novo em instantes.', 'http' => (int) $res['http']];
}

// ===================== resumo para o admin =====================

function pushResumoAdmin(PDO $pdo): array
{
    $r = ['pendentes' => 0, 'enviando' => 0, 'aparelhos' => ['ios' => 0, 'android' => 0, 'desktop' => 0, 'outro' => 0], 'hora24' => [], 'batimento_segundos' => null, 'erros_fatais_hora' => 0];
    try {
        $r['pendentes'] = (int) $pdo->query("SELECT COUNT(*) FROM push_fila WHERE estado = 'pendente'")->fetchColumn();
        $r['enviando'] = (int) $pdo->query("SELECT COUNT(*) FROM push_fila WHERE estado = 'enviando'")->fetchColumn();
        foreach ($pdo->query('SELECT plataforma, COUNT(*) AS n FROM push_inscricoes WHERE ativo = 1 GROUP BY plataforma')->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $r['aparelhos'][$l['plataforma']] = (int) $l['n'];
        }
        $r['hora24'] = $pdo->query('SELECT COALESCE(SUM(enviados),0) AS enviados, COALESCE(SUM(falhas_temporarias),0) AS falhas, COALESCE(SUM(inscricoes_removidas),0) AS removidas,
                COALESCE(SUM(descartados),0) AS descartados, COALESCE(SUM(agrupados),0) AS agrupados, COALESCE(SUM(erros_fatais),0) AS erros_fatais,
                COALESCE(SUM(latencia_soma_ms),0) AS lat_soma, COALESCE(SUM(latencia_n),0) AS lat_n, COALESCE(MAX(latencia_max_ms),0) AS lat_max
            FROM push_metricas_hora WHERE hora >= NOW() - INTERVAL 24 HOUR')->fetch(PDO::FETCH_ASSOC) ?: [];
        $r['erros_fatais_hora'] = (int) $pdo->query("SELECT COALESCE(SUM(erros_fatais),0) FROM push_metricas_hora WHERE hora >= NOW() - INTERVAL 1 HOUR")->fetchColumn();
    } catch (\Throwable $e) {
        // tabelas ainda não existem: o admin vê tudo zerado
    }
    clearstatcache(true, PUSH_ARQUIVO_BATIMENTO);
    $m = @filemtime(PUSH_ARQUIVO_BATIMENTO);
    $r['batimento_segundos'] = $m === false ? null : max(0, time() - $m);
    return $r;
}
