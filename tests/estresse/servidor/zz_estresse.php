<?php
// Massa de dados do TESTE DE ESTRESSE. Uso: php zz_estresse.php seed [usuarios] | del | contar
// Usuários: cliente1@gmial.com ... clienteN@gmial.com, senha 12345678. Tudo marcado para apagar depois (`del`).
// Só cria vendas pagas/expiradas/canceladas (NUNCA "gerado": a cron real de Pix consultaria o gateway com id falso) e campanhas
// já concluídas (a cron real de remarketing dispararia mensagem pelos bots falsos).
chdir(__DIR__);
require_once 'conexao.php';
set_time_limit(0);
ini_set('memory_limit', '1024M');
$modo = $argv[1] ?? 'contar';
$total_usuarios = (int) ($argv[2] ?? 300);
const BOTS_POR_USUARIO = 2;
const LEADS_POR_BOT = 150;
const VENDAS_POR_USUARIO = 40;
const ATIVIDADES_POR_USUARIO = 40;

function lote(PDO $pdo, string $tabela, array $colunas, array &$linhas): void
{
    if (!$linhas) return;
    $marcas = '(' . implode(',', array_fill(0, count($colunas), '?')) . ')';
    $sql = "INSERT INTO $tabela (" . implode(',', $colunas) . ') VALUES ' . implode(',', array_fill(0, count($linhas), $marcas));
    $pdo->prepare($sql)->execute(array_merge(...array_map('array_values', $linhas)));
    $linhas = [];
}
function aleatorio_data(int $dias): string { return date('Y-m-d H:i:s', time() - random_int(0, $dias * 86400)); }

if ($modo === 'contar') {
    foreach (["usuarios WHERE email LIKE 'cliente%@gmial.com'", "bots WHERE token LIKE 'SIMBOT:AAH-est-%'", "leads WHERE bot_id IN (SELECT id FROM bots WHERE token LIKE 'SIMBOT:AAH-est-%')", "vendas WHERE transacao_id LIKE 'EST%'", "atividades WHERE id_usuario IN (SELECT id FROM usuarios WHERE email LIKE 'cliente%@gmial.com')", "fluxos WHERE id_usuario IN (SELECT id FROM usuarios WHERE email LIKE 'cliente%@gmial.com')"] as $q) {
        echo explode(' ', $q)[0], ': ', $pdo->query("SELECT COUNT(*) FROM $q")->fetchColumn(), "\n";
    }
    exit;
}

if ($modo === 'del') {
    $ids = $pdo->query("SELECT id FROM usuarios WHERE email LIKE 'cliente%@gmial.com'")->fetchAll(PDO::FETCH_COLUMN);
    if (!$ids) { echo "nada a apagar\n"; exit; }
    $lista = implode(',', array_map('intval', $ids));
    $bots = $pdo->query("SELECT id FROM bots WHERE id_usuario IN ($lista)")->fetchAll(PDO::FETCH_COLUMN);
    $lb = $bots ? implode(',', array_map('intval', $bots)) : '0';
    foreach (['vendas' => "bot_id IN ($lb)", 'leads' => "bot_id IN ($lb)", 'links_rastreamento' => "bot_id IN ($lb)", 'membros_grupos' => "bot_id IN ($lb)"] as $t => $w) {
        try { $n = $pdo->exec("DELETE FROM $t WHERE $w"); echo "$t: $n\n"; } catch (Throwable $e) { echo "$t: ", $e->getMessage(), "\n"; }
    }
    try { $pdo->exec("DELETE FROM remarketing_envios WHERE campanha_id IN (SELECT id FROM remarketing_campanhas WHERE id_usuario IN ($lista))"); } catch (Throwable $e) {}
    foreach (['remarketing_campanhas', 'atividades', 'fluxos', 'usuarios_gateways', 'usuarios_traqueamento', 'webhooks', 'redirecionadores', 'metricas_horarias_usuario', 'ranking_cache', 'stories_visualizacoes'] as $t) {
        try { $n = $pdo->exec("DELETE FROM $t WHERE id_usuario IN ($lista)"); echo "$t: $n\n"; } catch (Throwable $e) { echo "$t: ", $e->getMessage(), "\n"; }
    }
    $pdo->exec("DELETE FROM bots WHERE id_usuario IN ($lista)");
    try { $pdo->exec("DELETE FROM tentativas_login WHERE identificador LIKE 'cliente%@gmial.com'"); } catch (Throwable $e) {}
    $n = $pdo->exec("DELETE FROM usuarios WHERE id IN ($lista)");
    echo "usuarios: $n\n";
    exit;
}

// ---------- seed ----------
$t0 = microtime(true);
$existentes = (int) $pdo->query("SELECT COUNT(*) FROM usuarios WHERE email LIKE 'cliente%@gmial.com'")->fetchColumn();
if ($existentes > 0) { echo "já existem $existentes usuários cliente*; rode `del` antes\n"; exit(1); }
$hash = password_hash('12345678', PASSWORD_DEFAULT);
$nomes = ['Ana', 'Bruno', 'Carla', 'Diego', 'Elisa', 'Fabio', 'Gisele', 'Hugo', 'Irene', 'Joao', 'Karen', 'Lucas', 'Marta', 'Nuno', 'Olga', 'Paulo'];
$planos = [['Mensal', 19.90], ['Trimestral', 49.90], ['Anual', 149.90], ['VIP', 97.00], ['Pack Fotos', 29.90], ['Pass 2h', 9.90]];
$estados = ['pago', 'pago', 'pago', 'expirado', 'expirado', 'cancelado'];
$guiado = json_encode(['planos' => [['id' => 'p1', 'nome' => 'Mensal', 'valor' => 19.9, 'dias_acesso' => 30, 'unidade_acesso' => 'dias']], 'boas_vindas' => ['mensagem' => 'Olá! Bem-vindo.', 'texto_botao' => 'Quero entrar']], JSON_UNESCAPED_UNICODE);
$visual = json_encode(['operators' => (object) [], 'links' => (object) []]);

$ins_user = $pdo->prepare("INSERT INTO usuarios (nome, email, senha, perfil, email_verificado_em, apelido_publico) VALUES (?, ?, ?, 'usuario', NOW(), ?)");
$ins_bot = $pdo->prepare("INSERT INTO bots (id_usuario, token, id_bot_telegram, nome_usuario, primeiro_nome, descricao, descricao_curta, id_fluxo_conectado) VALUES (?, ?, ?, ?, ?, 'estresse', 'estresse', ?)");
$ins_fluxo = $pdo->prepare("INSERT INTO fluxos (id_usuario, nome, descricao, modo, dados_fluxograma) VALUES (?, ?, 'estresse', ?, ?)");
$ins_link = $pdo->prepare("INSERT INTO links_rastreamento (id_usuario, titulo, identificador, bot_id, starts, leads, vendas_qtd, vendas_valor) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
$ins_camp = $pdo->prepare("INSERT INTO remarketing_campanhas (id_usuario, bot_id, nome, audiencia, mensagem, agendado_em, status, total_destinatarios, enviados, entregues, falhas) VALUES (?, ?, ?, 'nao_comprou', 'Oi!', '2026-01-01 10:00:00', 'concluida', 100, 100, 95, 5)");

$leads = []; $vendas = []; $ativ = [];
$cols_leads = ['id_telegram', 'nome', 'telefone', 'nome_usuario_telegram', 'origem_rastreio', 'bot_id', 'criado_em', 'id_fluxo'];
$cols_vendas = ['id_telegram', 'bot_id', 'valor', 'status', 'transacao_id', 'criado_em', 'pago_em', 'dias_acesso', 'id_fluxo', 'comissao_admin'];
$cols_ativ = ['id_usuario', 'tipo', 'titulo', 'descricao', 'criado_em'];
$tx = 0;
for ($i = 1; $i <= $total_usuarios; $i++) {
    $ins_user->execute(["Cliente $i", "cliente$i@gmial.com", $hash, "cliente$i"]);
    $uid = (int) $pdo->lastInsertId();
    $ins_fluxo->execute([$uid, "Fluxo guiado $i", 'basico', $guiado]); $f1 = (int) $pdo->lastInsertId();
    $ins_fluxo->execute([$uid, "Fluxo visual $i", 'avancado', $visual]); $f2 = (int) $pdo->lastInsertId();
    $bots_user = [];
    for ($b = 1; $b <= BOTS_POR_USUARIO; $b++) {
        $ins_bot->execute([$uid, "SIMBOT:AAH-est-{$uid}-{$b}", 8000000 + $uid * 10 + $b, "cliente{$i}_bot{$b}", "Bot $b do Cliente $i", $b === 1 ? $f1 : null]);
        $bid = (int) $pdo->lastInsertId();
        $bots_user[] = [$bid, $b === 1 ? $f1 : null];
        $ins_link->execute([$uid, "Campanha $b", "camp{$i}x{$b}", $bid, random_int(50, 400), random_int(20, 150), random_int(0, 30), random_int(0, 2000)]);
        for ($l = 1; $l <= LEADS_POR_BOT; $l++) {
            $leads[] = [(string) (9000000 + $bid * 1000 + $l), $nomes[array_rand($nomes)] . " $l", $l % 3 === 0 ? '119' . random_int(10000000, 99999999) : null, $l % 2 === 0 ? "lead{$bid}_$l" : null, $l % 4 === 0 ? "camp{$i}x{$b}" : null, $bid, aleatorio_data(30), $b === 1 ? $f1 : null];
            if (count($leads) >= 1500) lote($pdo, 'leads', $cols_leads, $leads);
        }
    }
    $ins_camp->execute([$uid, $bots_user[0][0], "Remarketing $i"]);
    for ($v = 1; $v <= VENDAS_POR_USUARIO; $v++) {
        [$bid, $fid] = $bots_user[$v % BOTS_POR_USUARIO];
        [$plano, $valor] = $planos[array_rand($planos)];
        $st = $estados[array_rand($estados)];
        $criado = aleatorio_data(30);
        $vendas[] = [(string) (9000000 + $bid * 1000 + random_int(1, LEADS_POR_BOT)), $bid, $valor, $st, 'EST' . (++$tx) . 'U' . $uid, $criado, $st === 'pago' ? date('Y-m-d H:i:s', strtotime($criado) + 90) : null, 30, $fid, $st === 'pago' ? round($valor * 0.07, 2) : 0];
        if (count($vendas) >= 1500) lote($pdo, 'vendas', $cols_vendas, $vendas);
    }
    for ($a = 1; $a <= ATIVIDADES_POR_USUARIO; $a++) {
        $tipo = ['venda', 'pix_gerado', 'lead', 'sistema'][$a % 4];
        $titulos = ['venda' => 'Venda Aprovada', 'pix_gerado' => 'Pix Gerado', 'lead' => 'Novo Lead', 'sistema' => 'Fluxo'];
        $ativ[] = [$uid, $tipo, $titulos[$tipo], 'Evento de teste de estresse #' . $a, aleatorio_data(10)];
        if (count($ativ) >= 1500) lote($pdo, 'atividades', $cols_ativ, $ativ);
    }
    if ($i % 50 === 0) { echo "  $i usuários... ", round(microtime(true) - $t0, 1), "s\n"; }
}
lote($pdo, 'leads', $cols_leads, $leads); lote($pdo, 'vendas', $cols_vendas, $vendas); lote($pdo, 'atividades', $cols_ativ, $ativ);
echo "seed pronto em ", round(microtime(true) - $t0, 1), "s\n";
