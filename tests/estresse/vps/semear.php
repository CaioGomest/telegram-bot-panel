<?php
// Massa de dados do teste de carga na VPS. Roda NA VPS, pela linha de comando, como root.
//
//   php semear.php seed  --tag=e1 --usuarios=2000 [--bots=2] [--leads-bot=300] [--vendas-usuario=150] [--starts=1.3] [--atividades=60]
//   php semear.php mega  --tag=mega --leads=2000000 --vendas=500000        (um vendedor gigante: 1 usuário, 1 bot)
//   php semear.php contar [--tag=e1]
//   php semear.php apagar --tag=e1                                          (só quando o dono mandar zerar)
//
// Tudo é marcado pela TAG: e-mail  TAG-N@estresse.invalid, token do bot  SIMBOT:TAG-UID-B, vendas  ETAG-n.
// Senha de todos os usuários: Estresse#2026. Só cria vendas pagas/expiradas/canceladas (nunca "gerado") e assinantes com
// validade só em 2027+: as rotinas (cron) de verdade não encontram nada para processar nesses dados (nada sai para o Telegram).
chdir('/var/www/painel');
require_once 'conexao.php';
require_once 'funcoes/gateways.php';
set_time_limit(0);
ini_set('memory_limit', '2048M');

$cmd = $argv[1] ?? 'contar';
$op = [];
foreach (array_slice($argv, 2) as $a) {
    if (preg_match('/^--([a-z-]+)=(.*)$/', $a, $m)) $op[$m[1]] = $m[2];
}
$tag = preg_replace('/[^a-z0-9]/i', '', $op['tag'] ?? 'e1');
$T0 = microtime(true);
function agora(): string { global $T0; return sprintf('[%6.1fs]', microtime(true) - $T0); }
function dt(int $dias): string { return date('Y-m-d H:i:s', time() - random_int(0, $dias * 86400)); }

function lote(PDO $pdo, string $tabela, array $colunas, array &$linhas): int
{
    if (!$linhas) return 0;
    $n = count($linhas);
    $marcas = '(' . implode(',', array_fill(0, count($colunas), '?')) . ')';
    $sql = "INSERT INTO $tabela (" . implode(',', $colunas) . ') VALUES ' . implode(',', array_fill(0, $n, $marcas));
    $pdo->prepare($sql)->execute(array_merge(...$linhas));
    $primeiro = (int) $pdo->lastInsertId();
    $linhas = [];
    return $primeiro;
}

if ($cmd === 'contar') {
    $like = isset($op['tag']) ? "$tag-%@estresse.invalid" : '%@estresse.invalid';
    $u = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE email LIKE ?"); $u->execute([$like]);
    echo "usuarios: ", $u->fetchColumn(), "\n";
    $tokl = isset($op['tag']) ? "SIMBOT:$tag-%" : 'SIMBOT:%';
    $b = $pdo->prepare("SELECT COUNT(*) FROM bots WHERE token LIKE ?"); $b->execute([$tokl]);
    echo "bots: ", $b->fetchColumn(), "\n";
    foreach (['leads', 'leads_starts', 'vendas', 'membros_grupos', 'atividades'] as $t) {
        $r = $pdo->query("SELECT table_rows FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '$t'")->fetchColumn();
        echo "$t (total na tabela, aprox.): $r\n";
    }
    exit;
}

if ($cmd === 'apagar') {
    $ids = $pdo->prepare("SELECT id FROM usuarios WHERE email LIKE ?"); $ids->execute(["$tag-%@estresse.invalid"]);
    $ids = $ids->fetchAll(PDO::FETCH_COLUMN);
    if (!$ids) { echo "nada a apagar\n"; exit; }
    $pdo->exec('SET foreign_key_checks = 0');
    $lu = implode(',', array_map('intval', $ids));
    $bots = $pdo->query("SELECT id FROM bots WHERE id_usuario IN ($lu)")->fetchAll(PDO::FETCH_COLUMN);
    $lb = $bots ? implode(',', array_map('intval', $bots)) : '0';
    foreach (['leads_starts' => "bot_id IN ($lb)", 'membros_grupos' => "bot_id IN ($lb)", 'vendas' => "bot_id IN ($lb)", 'leads' => "bot_id IN ($lb)", 'links_rastreamento' => "bot_id IN ($lb)",
              'atividades' => "id_usuario IN ($lu)", 'usuarios_gateways' => "id_usuario IN ($lu)", 'fluxos' => "id_usuario IN ($lu)", 'bots' => "id_usuario IN ($lu)"] as $t => $w) {
        try { echo agora(), " $t: ", $pdo->exec("DELETE FROM $t WHERE $w"), "\n"; } catch (Throwable $e) { echo "$t: ", $e->getMessage(), "\n"; }
    }
    foreach (['metricas_horarias_usuario', 'ranking_cache', 'tentativas_login'] as $t) {
        try { $col = $t === 'tentativas_login' ? "identificador LIKE '$tag-%@estresse.invalid'" : "id_usuario IN ($lu)"; $pdo->exec("DELETE FROM $t WHERE $col"); } catch (Throwable $e) {}
    }
    echo agora(), " usuarios: ", $pdo->exec("DELETE FROM usuarios WHERE id IN ($lu)"), "\n";
    $pdo->exec('SET foreign_key_checks = 1');
    exit;
}

$pdo->exec('SET unique_checks = 0, foreign_key_checks = 0');
$hash = password_hash('Estresse#2026', PASSWORD_DEFAULT);
$gw = (int) $pdo->query("SELECT id FROM gateways WHERE nome = 'omegapayments'")->fetchColumn();
$nomes = ['Ana', 'Bruno', 'Carla', 'Diego', 'Elisa', 'Fabio', 'Gisele', 'Hugo', 'Irene', 'Joao', 'Karen', 'Lucas', 'Marta', 'Nuno', 'Olga', 'Paulo', 'Rita', 'Saulo', 'Tania', 'Vitor'];
$planos = [19.90, 49.90, 149.90, 97.00, 29.90, 9.90];
$estados = ['pago', 'pago', 'pago', 'pago', 'pago', 'pago', 'expirado', 'expirado', 'expirado', 'cancelado'];
$G1 = '-1001000000001';
$guiado = json_encode(['ativo' => true, 'suporte' => '@suporte', 'suporte_cor' => 'danger', 'cache_chat_id' => '',
    'boas_vindas' => ['mensagem' => 'Oi! 👋 Bem-vindo ao *Curso*.', 'midia_tipo' => 'none', 'midia_path' => '', 'texto_cta' => 'Quero aprender', 'cor_cta' => 'success'],
    'planos' => [
        ['id' => 'p_mensal', 'nome' => 'Mensal', 'valor' => 19.9, 'dias_acesso' => 30, 'unidade_acesso' => 'dias', 'id_grupo' => $G1, 'cor' => 'primary'],
        ['id' => 'p_anual', 'nome' => 'Anual', 'valor' => 149.0, 'dias_acesso' => 365, 'unidade_acesso' => 'dias', 'id_grupo' => $G1, 'cor' => 'success'],
    ],
    'ofertas' => ['upsell' => ['ativo' => false], 'downsell' => ['ativo' => false], 'order_bump' => ['ativo' => false]],
    'previas' => ['ativo' => false], 'packs' => ['ativo' => false],
    'assinatura' => ['avisos_antes' => ['ativo' => false], 'avisos_no_dia' => ['ativo' => false], 'ao_expirar' => ['ativo' => false], 'renovacao' => ['desconto' => 10], 'acoes' => ['expulsar' => true]],
    'pagamentos' => ['msg_instrucoes' => '', 'msg_confirmado' => '', 'mostrar_copiar' => true, 'mostrar_qrcode' => false, 'mostrar_confirmar' => true],
], JSON_UNESCAPED_UNICODE);

$ins_user = $pdo->prepare("INSERT INTO usuarios (nome, email, senha, perfil, email_verificado_em, apelido_publico) VALUES (?, ?, ?, 'usuario', NOW(), ?)");
$ins_fluxo = $pdo->prepare("INSERT INTO fluxos (id_usuario, nome, descricao, modo, dados_fluxograma) VALUES (?, ?, 'estresse', 'basico', ?)");
$ins_bot = $pdo->prepare("INSERT INTO bots (id_usuario, token, id_bot_telegram, nome_usuario, primeiro_nome, descricao, descricao_curta, id_fluxo_conectado, webhook_secret) VALUES (?, ?, ?, ?, ?, 'estresse', 'estresse', ?, ?)");

$cols_leads = ['id_telegram', 'nome', 'telefone', 'nome_usuario_telegram', 'origem_rastreio', 'pagou', 'bot_id', 'criado_em', 'id_fluxo'];
$cols_starts = ['lead_id', 'bot_id', 'criado_em'];
$cols_vendas = ['id_telegram', 'bot_id', 'valor', 'status', 'transacao_id', 'id_gateway', 'id_grupo_telegram', 'dias_acesso', 'criado_em', 'pago_em', 'comissao_admin', 'tipo_cobranca', 'id_fluxo', 'nome_produto'];
$cols_membros = ['id_telegram', 'id_grupo_telegram', 'bot_id', 'venda_id', 'data_expiracao', 'status', 'criado_em'];
$cols_ativ = ['id_usuario', 'tipo', 'titulo', 'descricao', 'criado_em'];
$LOTE = 2000;
$contador_tg = 7000000000 + (int) $pdo->query("SELECT COALESCE(MAX(id), 0) FROM leads")->fetchColumn() * 3;
$seq_venda = (int) $pdo->query("SELECT COUNT(*) FROM vendas WHERE transacao_id LIKE 'E$tag-%'")->fetchColumn();

/** Lote de leads + starts de um bot; devolve os ids_telegram gerados (para as vendas apontarem para leads que existem). */
function leads_do_bot(PDO $pdo, int $bid, int $fid, int $n, float $starts, array $nomes, string $tag, int &$contador_tg, array $cols_leads, array $cols_starts, int $LOTE, int $dias = 90): array
{
    $ids_tg = [];
    $linhas = [];
    for ($i = 1; $i <= $n; $i++) {
        $tg = (string) (++$contador_tg);
        if ($i % 40 === 0 || $n <= 50) $ids_tg[] = $tg;
        $linhas[] = [$tg, $nomes[$i % count($nomes)] . " $i", $i % 3 === 0 ? '119' . random_int(10000000, 99999999) : null, $i % 2 === 0 ? "l{$bid}_$i" : null, $i % 4 === 0 ? "camp$tag" : null, $i % 16 === 0 ? 1 : 0, $bid, dt($dias), $fid];
        if (count($linhas) >= $LOTE) { $ids_tg = array_merge($ids_tg, []); $pri = lote($pdo, 'leads', $cols_leads, $linhas); gerar_starts($pdo, $pri, $LOTE, $bid, $starts, $cols_starts, $dias); }
    }
    if ($linhas) { $q = count($linhas); $pri = lote($pdo, 'leads', $cols_leads, $linhas); gerar_starts($pdo, $pri, $q, $bid, $starts, $cols_starts, $dias); }
    return $ids_tg;
}
function gerar_starts(PDO $pdo, int $primeiro, int $qtd, int $bid, float $fator, array $cols, int $dias): void
{
    $linhas = [];
    $total = (int) round($qtd * $fator);
    for ($i = 0; $i < $total; $i++) $linhas[] = [$primeiro + ($i % $qtd), $bid, dt($dias)];
    for ($o = 0; $o < count($linhas); $o += 4000) { $parte = array_slice($linhas, $o, 4000); lote($pdo, 'leads_starts', $cols, $parte); }
}
function vendas_do_bot(PDO $pdo, int $bid, int $fid, int $uid, int $n, array $ids_tg, array $planos, array $estados, string $tag, int &$seq, int $gw, string $G1, array $cols_v, array $cols_m, int $LOTE): void
{
    $vendas = [];
    $meta = [];
    $flush = function () use (&$vendas, &$meta, $pdo, $cols_v, $cols_m) {
        if (!$vendas) return;
        $pri = lote($pdo, 'vendas', $cols_v, $vendas);
        $membros = [];
        foreach ($meta as $i => [$tg, $exp]) { if ($exp) $membros[] = [$tg, '-1001000000001', $GLOBALS['__bid'], $pri + $i, $exp, 'ativo', date('Y-m-d H:i:s')]; }
        if ($membros) { try { $pdo->prepare('INSERT IGNORE INTO membros_grupos (' . implode(',', $cols_m) . ') VALUES ' . implode(',', array_fill(0, count($membros), '(?,?,?,?,?,?,?)')))->execute(array_merge(...$membros)); } catch (Throwable $e) {} }
        $meta = [];
    };
    $GLOBALS['__bid'] = $bid;
    for ($v = 0; $v < $n; $v++) {
        $st = $estados[array_rand($estados)];
        $valor = $planos[array_rand($planos)];
        $criado = dt(90);
        $tg = $ids_tg ? $ids_tg[array_rand($ids_tg)] : (string) random_int(7000000000, 7100000000);
        $vendas[] = [$tg, $bid, $valor, $st, 'E' . $tag . '-' . (++$seq), $gw, '-1001000000001', 30, $criado, $st === 'pago' ? date('Y-m-d H:i:s', strtotime($criado) + 90) : null,
                     $st === 'pago' ? round($valor * 0.07, 2) : 0, 'unica', $fid, 'Plano estresse'];
        // assinante ativo (validade só em 2027+: nenhuma cron real o encontra); só 1 em 10 pagas, para não explodir a tabela
        $meta[] = [$tg, ($st === 'pago' && $v % 10 === 0) ? date('Y-m-d H:i:s', time() + 400 * 86400) : null];
        if (count($vendas) >= $LOTE) $flush();
    }
    $flush();
}

if ($cmd === 'mega') {
    $leads = (int) ($op['leads'] ?? 2000000);
    $vendas_n = (int) ($op['vendas'] ?? 500000);
    $pdo->prepare("DELETE FROM usuarios WHERE email = ?")->execute(["$tag-1@estresse.invalid"]);
    $ins_user->execute(["Vendedor Gigante", "$tag-1@estresse.invalid", $hash, "gigante$tag"]);
    $uid = (int) $pdo->lastInsertId();
    $ins_fluxo->execute([$uid, 'Fluxo gigante', $guiado]); $fid = (int) $pdo->lastInsertId();
    $ins_bot->execute([$uid, "SIMBOT:$tag-$uid-1", 8000000 + $uid, "gigante_$tag", 'Bot Gigante', $fid, bin2hex(random_bytes(16))]);
    $bid = (int) $pdo->lastInsertId();
    saveUserGatewayConfig($uid, $gw, 'sim_pub_key', 'sim_secret_key', '', '', 'sim-chave-pix', true, 1, 'pj');
    echo agora(), " vendedor gigante uid=$uid bot=$bid\n";
    $ids_tg = leads_do_bot($pdo, $bid, $fid, $leads, 1.3, $nomes, $tag, $contador_tg, $cols_leads, $cols_starts, $LOTE);
    echo agora(), " leads: $leads (+starts)\n";
    vendas_do_bot($pdo, $bid, $fid, $uid, $vendas_n, $ids_tg, $planos, $estados, $tag, $seq_venda, $gw, $G1, $cols_vendas, $cols_membros, $LOTE);
    echo agora(), " vendas: $vendas_n\n";
    $ativ_mega = (int) ($op['atividades'] ?? 0);
    $ativ = [];
    for ($a = 1; $a <= $ativ_mega; $a++) {
        $tipo = ['venda', 'pix_gerado', 'lead', 'sistema'][$a % 4];
        $ativ[] = [$uid, $tipo, ['venda' => 'Venda Aprovada', 'pix_gerado' => 'Pix Gerado', 'lead' => 'Novo Lead', 'sistema' => 'Fluxo'][$tipo], 'Evento de teste de estresse #' . $a, dt(30)];
        if (count($ativ) >= $LOTE) lote($pdo, 'atividades', $cols_ativ, $ativ);
    }
    lote($pdo, 'atividades', $cols_ativ, $ativ);
    echo agora(), " atividades (notificações): $ativ_mega\n";
    exit;
}

// ---------- seed ----------
$usuarios = (int) ($op['usuarios'] ?? 200);
$bots_n = (int) ($op['bots'] ?? 2);
$leads_bot = (int) ($op['leads-bot'] ?? 300);
$vendas_user = (int) ($op['vendas-usuario'] ?? 150);
$starts = (float) ($op['starts'] ?? 1.3);
$ativ_n = (int) ($op['atividades'] ?? 60);
$existentes = (int) $pdo->query("SELECT COUNT(*) FROM usuarios WHERE email LIKE '$tag-%@estresse.invalid'")->fetchColumn();
$inicio = $existentes + 1;
echo agora(), " semeando $usuarios usuários (tag $tag, a partir do nº $inicio): $bots_n bots, $leads_bot leads/bot, $vendas_user vendas/usuário\n";
$ativ = [];
for ($i = $inicio; $i < $inicio + $usuarios; $i++) {
    // fora da transação: saveUserGatewayConfig() → registrarAtividade() pode rodar DDL na 1ª vez (commit implícito) e perderia o resto
    $ins_user->execute(["Vendedor $i", "$tag-$i@estresse.invalid", $hash, "v$tag$i"]);
    $uid = (int) $pdo->lastInsertId();
    $ins_fluxo->execute([$uid, "Fluxo guiado $i", $guiado]); $fid = (int) $pdo->lastInsertId();
    saveUserGatewayConfig($uid, $gw, 'sim_pub_key', 'sim_secret_key', '', '', 'sim-chave-pix', true, 1, 'pj');
    $pdo->beginTransaction();
    $ids_user = [];
    for ($b = 1; $b <= $bots_n; $b++) {
        $ins_bot->execute([$uid, "SIMBOT:$tag-$uid-$b", 8000000 + $uid * 10 + $b, "v{$tag}{$i}_b$b", "Bot $b do vendedor $i", $fid, bin2hex(random_bytes(16))]);
        $bid = (int) $pdo->lastInsertId();
        $ids_user[] = $bid;
        $tgs = leads_do_bot($pdo, $bid, $fid, $leads_bot, $starts, $nomes, $tag, $contador_tg, $cols_leads, $cols_starts, $LOTE);
        vendas_do_bot($pdo, $bid, $fid, $uid, intdiv($vendas_user, $bots_n), $tgs, $planos, $estados, $tag, $seq_venda, $gw, $G1, $cols_vendas, $cols_membros, $LOTE);
    }
    for ($a = 1; $a <= $ativ_n; $a++) {
        $tipo = ['venda', 'pix_gerado', 'lead', 'sistema'][$a % 4];
        $ativ[] = [$uid, $tipo, ['venda' => 'Venda Aprovada', 'pix_gerado' => 'Pix Gerado', 'lead' => 'Novo Lead', 'sistema' => 'Fluxo'][$tipo], 'Evento de teste de estresse #' . $a, dt(10)];
        if (count($ativ) >= $LOTE) lote($pdo, 'atividades', $cols_ativ, $ativ);
    }
    $pdo->commit();
    if (($i - $inicio + 1) % 100 === 0) echo agora(), "   ", ($i - $inicio + 1), " usuários\n";
}
lote($pdo, 'atividades', $cols_ativ, $ativ);
echo agora(), " seed pronto\n";
