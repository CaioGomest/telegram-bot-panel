<?php
// Dados FICTÍCIOS para o teste página por página. Uso: php zz_pg.php seed | del | ids
chdir(__DIR__);
require_once 'conexao.php';
$emailU = 'pgteste-user@example.invalid';
$emailA = 'pgteste-admin@example.invalid';
// Starts do dashboard vêm de leads_starts (o webhook grava um por /start): quem insere lead à mão tem que registrar também.
function sincronizarStarts(PDO $pdo): void {
    try { $pdo->exec("INSERT INTO leads_starts (lead_id, bot_id, criado_em) SELECT l.id, l.bot_id, l.criado_em FROM leads l WHERE l.bot_id IS NOT NULL AND l.bot_id IN (SELECT b.id FROM bots b JOIN usuarios u ON u.id = b.id_usuario WHERE u.email LIKE 'pgteste-%') AND NOT EXISTS (SELECT 1 FROM leads_starts s WHERE s.lead_id = l.id)"); } catch (Throwable $e) {}
    // O cron de métricas só recalcula a contagem de starts do último dia; o histórico entra pelo preenchimento do Atualizar Banco. O seed insere starts antigos,
    // então preenche o cache por hora desses usuários como o preenchimento histórico faria.
    try { $pdo->exec("INSERT INTO metricas_horarias_usuario (id_usuario, data, hora, qtd_leads, atualizado_em) SELECT b.id_usuario, DATE(s.criado_em), HOUR(s.criado_em), COUNT(*), NOW() FROM leads_starts s JOIN bots b ON b.id = s.bot_id JOIN usuarios u ON u.id = b.id_usuario WHERE u.email LIKE 'pgteste-%' GROUP BY b.id_usuario, DATE(s.criado_em), HOUR(s.criado_em) ON DUPLICATE KEY UPDATE qtd_leads = VALUES(qtd_leads), atualizado_em = VALUES(atualizado_em)"); } catch (Throwable $e) {}
    // leads.pagou é marcado por aposPagamentoConfirmado() nos 3 caminhos reais de pagamento; o seed grava vendas pagas à mão, então marca também.
    try { $pdo->exec("UPDATE leads l SET l.pagou = 1 WHERE l.pagou = 0 AND l.bot_id IN (SELECT b.id FROM bots b JOIN usuarios u ON u.id = b.id_usuario WHERE u.email LIKE 'pgteste-%') AND EXISTS (SELECT 1 FROM vendas v WHERE v.bot_id = l.bot_id AND v.id_telegram = l.id_telegram AND v.status = 'pago')"); } catch (Throwable $e) {}
}
$modo = $argv[1] ?? 'ids';
function uid(PDO $pdo, string $e) { return (int) $pdo->query("SELECT id FROM usuarios WHERE email = " . $pdo->quote($e))->fetchColumn(); }

if ($modo === 'del') {
    foreach ($pdo->query("SELECT email FROM usuarios WHERE email LIKE 'pgteste-%@example.invalid'")->fetchAll(PDO::FETCH_COLUMN) as $e) {
        $u = uid($pdo, $e);
        if (!$u) continue;
        $bots = $pdo->query("SELECT id FROM bots WHERE id_usuario = $u")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($bots as $b) {
            foreach (['leads', 'vendas', 'membros_grupos', 'fluxo_continuacoes', 'fluxo_ofertas_aceitas', 'mensagens_agendadas', 'previas_vistas', 'links_rastreamento', 'bot_grupos'] as $t) {
                try { $pdo->exec("DELETE FROM $t WHERE bot_id = $b"); } catch (Throwable $x) {}
            }
        }
        try { $pdo->exec("DELETE FROM remarketing_envios WHERE campanha_id IN (SELECT id FROM remarketing_campanhas WHERE id_usuario = $u)"); } catch (Throwable $x) {}
        foreach (['remarketing_campanhas', 'redirecionador_destinos', 'webhooks_envios'] as $t) {
            try {
                if ($t === 'redirecionador_destinos') $pdo->exec("DELETE FROM $t WHERE id_redirecionador IN (SELECT id FROM redirecionadores WHERE id_usuario = $u)");
                elseif ($t === 'webhooks_envios') $pdo->exec("DELETE FROM $t WHERE webhook_id IN (SELECT id FROM webhooks WHERE id_usuario = $u)");
                else $pdo->exec("DELETE FROM $t WHERE id_usuario = $u");
            } catch (Throwable $x) { echo "aviso $t: ", $x->getMessage(), "\n"; }
        }
        try { $pdo->exec("DELETE FROM redirecionador_cliques_dia WHERE id_redirecionador NOT IN (SELECT id FROM redirecionadores)"); } catch (Throwable $x) {}
        $pdo->exec("DELETE FROM bots WHERE id_usuario = $u");
        foreach (['redirecionadores', 'webhooks', 'fluxos', 'atividades', 'usuarios_gateways', 'usuarios_traqueamento', 'usuarios_splits', 'comunidade_links', 'stories'] as $t) {
            try { $pdo->exec("DELETE FROM $t WHERE id_usuario = $u"); } catch (Throwable $x) {}
        }
        try { $pdo->exec("DELETE FROM tokens_email WHERE id_usuario = $u"); } catch (Throwable $x) {}
        try { $pdo->exec("DELETE FROM tentativas_login WHERE identificador LIKE 'pgteste-%'"); } catch (Throwable $x) {}
        $pdo->exec("DELETE FROM usuarios WHERE id = $u AND email = " . $pdo->quote($e));
    }
    echo "usuarios restantes=", $pdo->query("SELECT COUNT(*) FROM usuarios WHERE email IN (" . $pdo->quote($emailU) . "," . $pdo->quote($emailA) . ")")->fetchColumn(), "\n";
    exit;
}
if ($modo === 'act') { $u = uid($pdo, $emailU); $pdo->prepare("INSERT INTO atividades (id_usuario, tipo, titulo, descricao) VALUES (?, 'venda', 'Venda Aprovada', 'PIX R$ 19,90 pago')")->execute([$u]); echo "ok
"; exit; }
if ($modo === 'bot') { $u = uid($pdo, $emailU); $t = 'SIMBOT:AAH-pg-x' . bin2hex(random_bytes(3)); $pdo->prepare("INSERT INTO bots (id_usuario, token, id_bot_telegram, nome_usuario, primeiro_nome, descricao, descricao_curta) VALUES (?, ?, 7100099, 'pg_bot_excluir', 'Bot Excluir', 'x', 'x')")->execute([$u, $t]); echo $pdo->lastInsertId(), "
"; exit; }
if ($modo === 'leadsextra') {
    $u = uid($pdo, $emailU);
    $b1 = (int) $pdo->query("SELECT id FROM bots WHERE id_usuario = $u AND nome_usuario = 'pg_bot_um'")->fetchColumn();
    $ld = $pdo->prepare("INSERT INTO leads (id_telegram, nome, bot_id, criado_em) VALUES (?, ?, ?, ?)");
    for ($i = 1; $i <= 30; $i++) { $ld->execute(['8' . str_pad((string) $i, 3, '0', STR_PAD_LEFT), 'Massa ' . $i, $b1, date('Y-m-d H:i:s', time() - 7200 - $i * 60)]); }
    $ld->execute(['7001', '=HYPERLINK("http://exemplo.invalid","clique")', $b1, date('Y-m-d H:i:s', time() - 100)]);
    $ld->execute(['7002', '<script>window.__lead=1</script>Lead XSS', $b1, date('Y-m-d H:i:s', time() - 110)]);
    $ld->execute(['7003', "O'Brien %_ teste", $b1, date('Y-m-d H:i:s', time() - 120)]);
    sincronizarStarts($pdo);
    echo "ok
"; exit;
}
if ($modo === 'pixnovo') {
    $u = uid($pdo, $emailU);
    $b1 = (int) $pdo->query("SELECT id FROM bots WHERE id_usuario = $u AND nome_usuario = 'pg_bot_um'")->fetchColumn();
    $pdo->exec("DELETE FROM vendas WHERE transacao_id = 'PGTX5'");
    $pdo->prepare("INSERT INTO vendas (id_telegram, bot_id, valor, status, transacao_id, criado_em, dias_acesso, tempo_expiracao_minutos) VALUES ('9002', ?, 19.90, 'gerado', 'PGTX5', NOW(), 30, 600)")->execute([$b1]);
    echo "ok
"; exit;
}
if ($modo === 'limpalinks') {
    $u = uid($pdo, $emailU);
    $pdo->exec("DELETE FROM links_rastreamento WHERE id_usuario = $u AND identificador <> 'camp_pg'");
    echo "ok
"; exit;
}
if ($modo === 'user2') {
    $e2 = 'pgteste-user2@example.invalid';
    $u2 = uid($pdo, $e2);
    if (!$u2) {
        $pdo->prepare("INSERT INTO usuarios (nome, email, senha, perfil, email_verificado_em) VALUES (?, ?, ?, 'usuario', NOW())")->execute(['Usuario Dois PG', $e2, password_hash('123456', PASSWORD_DEFAULT)]);
        $u2 = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO bots (id_usuario, token, id_bot_telegram, nome_usuario, primeiro_nome, descricao, descricao_curta) VALUES (?, ?, 7200001, 'pg2_bot', 'Bot do Usuario 2', 'x', 'x')")->execute([$u2, 'SIMBOT:AAH-pg2-' . $u2]);
        $b = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO fluxos (id_usuario, nome, descricao, modo, dados_fluxograma) VALUES (?, 'Fluxo Secreto U2', 'confidencial', 'avancado', '{\"operators\":{},\"links\":{}}')")->execute([$u2]);
        $f = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO links_rastreamento (id_usuario, titulo, identificador, bot_id) VALUES (?, 'Link U2', 'u2_link', ?)")->execute([$u2, $b]);
        $pdo->prepare("INSERT INTO leads (id_telegram, nome, bot_id, criado_em) VALUES ('5551', 'Lead do U2', ?, NOW())")->execute([$b]);
        $pdo->prepare("INSERT INTO remarketing_campanhas (id_usuario, bot_id, nome, audiencia, mensagem, agendado_em, status) VALUES (?, ?, 'Campanha U2', 'nao_comprou', 'oi', '2030-01-01 10:00:00', 'concluida')")->execute([$u2, $b]);
        $c = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO redirecionadores (id_usuario, titulo, slug, ativo, plataforma, protecao, modo) VALUES (?, 'Redir U2', 'u2-redir-pg', 0, 'meta', 'nenhuma', 'aleatorio')")->execute([$u2]);
        $r = (int) $pdo->lastInsertId();
        sincronizarStarts($pdo);
        echo json_encode(['u2' => $u2, 'bot' => $b, 'fluxo' => $f, 'camp' => $c, 'redir' => $r]), "
";
    } else {
        echo json_encode(['u2' => $u2, 'bot' => (int) $pdo->query("SELECT id FROM bots WHERE id_usuario = $u2")->fetchColumn(), 'fluxo' => (int) $pdo->query("SELECT id FROM fluxos WHERE id_usuario = $u2")->fetchColumn(), 'camp' => (int) $pdo->query("SELECT id FROM remarketing_campanhas WHERE id_usuario = $u2")->fetchColumn(), 'redir' => (int) $pdo->query("SELECT id FROM redirecionadores WHERE id_usuario = $u2")->fetchColumn()]), "
";
    }
    exit;
}
if ($modo === 'limparemk') {
    $u = uid($pdo, $emailU);
    $pdo->exec("DELETE FROM remarketing_campanhas WHERE id_usuario = $u AND nome <> 'Campanha Remarketing PG'");
    echo "ok
"; exit;
}
if ($modo === 'ids') { echo json_encode(['user' => uid($pdo, $emailU), 'admin' => uid($pdo, $emailA)]), "\n"; exit; }

$hash = password_hash('123456', PASSWORD_DEFAULT);
$ins = $pdo->prepare("INSERT INTO usuarios (nome, email, senha, perfil, email_verificado_em) VALUES (?, ?, ?, ?, NOW())");
$ins->execute(['Usuario Pagina Teste', $emailU, $hash, 'usuario']); $u = (int) $pdo->lastInsertId();
$ins->execute(['Admin Pagina Teste', $emailA, $hash, 'admin']); $a = (int) $pdo->lastInsertId();

$bot = $pdo->prepare("INSERT INTO bots (id_usuario, token, id_bot_telegram, nome_usuario, primeiro_nome, descricao, descricao_curta) VALUES (?, ?, ?, ?, ?, 'x', 'x')");
$bot->execute([$u, 'SIMBOT:AAH-pg-' . $u . 'a', 7100001, 'pg_bot_um', 'Bot Um']); $b1 = (int) $pdo->lastInsertId();
$bot->execute([$u, 'SIMBOT:AAH-pg-' . $u . 'b', 7100002, 'pg_bot_dois', 'Bot Dois']); $b2 = (int) $pdo->lastInsertId();

$fl = $pdo->prepare("INSERT INTO fluxos (id_usuario, nome, descricao, modo, dados_fluxograma) VALUES (?, ?, ?, ?, ?)");
$fl->execute([$u, 'Fluxo Guiado PG', 'guiado de teste', 'basico', '{}']); $f1 = (int) $pdo->lastInsertId();
$fl->execute([$u, 'Fluxo Visual PG', 'visual de teste', 'avancado', '{"operators":{},"links":{}}']); $f2 = (int) $pdo->lastInsertId();
$pdo->exec("UPDATE bots SET id_fluxo_conectado = $f1 WHERE id = $b1");

$ld = $pdo->prepare("INSERT INTO leads (id_telegram, nome, telefone, nome_usuario_telegram, origem_rastreio, bot_id, criado_em, id_fluxo) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
foreach ([['9001', 'Maria Silva', '11999990001', 'maria_s', 'camp_pg', 0], ['9002', 'João Souza', null, 'joao_s', null, 1], ['9003', 'Ana Costa', '21988880003', null, 'camp_pg', 2], ['9004', 'Pedro Lima', null, 'pedro_l', null, 3], ['9005', 'Lúcia Dias', '31977770005', 'lucia_d', 'camp_pg', 6]] as [$tg, $n, $tel, $un, $or, $dias]) {
    $ld->execute([$tg, $n, $tel, $un, $or, $b1, date('Y-m-d H:i:s', time() - $dias * 86400), $f1]);
}
$ld->execute(['9101', 'Lead Bot Dois', null, null, null, $b2, date('Y-m-d H:i:s'), null]);

$vd = $pdo->prepare("INSERT INTO vendas (id_telegram, bot_id, valor, status, transacao_id, criado_em, pago_em, dias_acesso, id_fluxo) VALUES (?, ?, ?, ?, ?, ?, ?, 30, ?)");
$agora = time();
$vd->execute(['9001', $b1, 19.90, 'pago', 'PGTX1', date('Y-m-d H:i:s', $agora - 3600), date('Y-m-d H:i:s', $agora - 3500), $f1]);
$vd->execute(['9003', $b1, 49.90, 'pago', 'PGTX2', date('Y-m-d H:i:s', $agora - 86400 * 2), date('Y-m-d H:i:s', $agora - 86400 * 2 + 60), $f1]);
$vd->execute(['9002', $b1, 19.90, 'gerado', 'PGTX3', date('Y-m-d H:i:s', $agora - 600), null, $f1]);
$vd->execute(['9004', $b1, 9.90, 'expirado', 'PGTX4', date('Y-m-d H:i:s', $agora - 86400 * 3), null, $f1]);

$pdo->prepare("INSERT INTO links_rastreamento (id_usuario, titulo, identificador, bot_id, starts, leads, vendas_qtd, vendas_valor) VALUES (?, 'Campanha PG', 'camp_pg', ?, 10, 3, 2, 69.80)")->execute([$u, $b1]);
$pdo->prepare("INSERT INTO remarketing_campanhas (id_usuario, bot_id, nome, audiencia, mensagem, agendado_em, status, total_destinatarios, enviados, entregues, falhas) VALUES (?, ?, 'Campanha Remarketing PG', 'nao_comprou', 'Oi!', '2030-01-01 10:00:00', 'concluida', 10, 10, 8, 2)")->execute([$u, $b1]);
$pdo->prepare("INSERT INTO atividades (id_usuario, tipo, titulo, descricao) VALUES (?, 'venda', 'Venda Aprovada', 'PIX R$ 19,90 pago')")->execute([$u]);
sincronizarStarts($pdo);
echo json_encode(['user' => $u, 'admin' => $a, 'bot1' => $b1, 'bot2' => $b2, 'fluxo_guiado' => $f1, 'fluxo_visual' => $f2]), "\n";
