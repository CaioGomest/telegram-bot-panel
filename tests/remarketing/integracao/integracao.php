<?php
declare(strict_types=1);

/**
 * Integração do remarketing em etapas com banco de verdade (quem chama é rodar.js, que prepara tudo). Fases:
 *   A  migração do banco antigo pelo "Atualizar Banco" de verdade + schema novo = schema migrado = DDL do instalacao.php
 *   B  funções com banco: contexto, salvar, editar, iniciar/agendar, excluir, isolamento entre usuários
 *   C  cron: fluxo com vários bots, variantes A/B, mídia com file_id por bot, lotes (OFFSET), bot com token recusado, destinatário que bloqueou, período, campanha antiga
 *   D  cliques nos botões da campanha pelo webhook: plano, order bump, venda ligada à campanha, entrega, cliques inválidos, oferta pós-pagamento
 *   E  lista, CSV, API (CSRF, outro usuário) e a página de detalhes
 */

$app = (string) getenv('RM_APP');
$tg_log = (string) getenv('RM_TG_LOG');
$sim_dir = (string) getenv('RM_SIM');
$schema_novo = (string) getenv('RM_SCHEMA_NOVO');
$db_porta = (string) getenv('RM_DB_PORTA');
$db_nome = (string) getenv('RM_DB_NOME');
$mb = (string) getenv('RM_MB');
$aqui = str_replace('\\', '/', __DIR__);
chdir($app);

$ok = 0;
$falhas = 0;
function confere(string $nome, bool $certo, $detalhe = ''): void
{
    global $ok, $falhas;
    $certo ? $ok++ : $falhas++;
    $d = is_string($detalhe) ? $detalhe : json_encode($detalhe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    echo ($certo ? 'OK     ' : 'FALHOU ') . $nome . ($certo || $d === '' ? '' : '  -> ' . mb_substr((string) $d, 0, 700)) . PHP_EOL;
}
function fase(string $titulo): void
{
    echo PHP_EOL . "=== $titulo ===" . PHP_EOL;
}
function sh(string $cmd): array
{
    exec($cmd . ' 2>&1', $saida, $codigo);
    return [implode("\n", $saida), $codigo];
}
/** JSON como argumento: vai por arquivo ("@caminho"), porque no Windows o escapeshellarg troca aspas por espaço. */
function arq(string $conteudo): string
{
    static $n = 0;
    $dir = 'C:/tmp/rm_int/args';
    @mkdir($dir, 0777, true);
    $f = $dir . '/' . (++$n) . '.json';
    file_put_contents($f, $conteudo);
    return '@' . $f;
}
function php_cmd(string $script, array $args = [], bool $com_sim = true): string
{
    global $sim_dir;
    $cmd = escapeshellarg(PHP_BINARY) . ($com_sim ? ' -d auto_prepend_file=' . escapeshellarg($sim_dir . '/sim.php') : '') . ' -d display_errors=1 ' . escapeshellarg($script);
    foreach ($args as $a) {
        $cmd .= ' ' . escapeshellarg((string) $a);
    }
    return $cmd;
}

// ============================================================ conexão crua (antes da migração) ============================================================
$raw = new PDO("mysql:host=127.0.0.1;port=$db_porta;dbname=$db_nome;charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$raw->exec("SET time_zone = '-03:00'");
function ins(PDO $p, string $tabela, array $linha): int
{
    $cols = implode(',', array_map(fn($c) => "`$c`", array_keys($linha)));
    $marcas = implode(',', array_fill(0, count($linha), '?'));
    $p->prepare("INSERT INTO `$tabela` ($cols) VALUES ($marcas)")->execute(array_values($linha));
    return (int) $p->lastInsertId();
}
function colunas(PDO $p, string $banco, string $tabela): array
{
    $st = $p->prepare('SELECT column_name AS n, column_type AS t, is_nullable AS nulo, column_default AS pad FROM information_schema.columns WHERE table_schema = ? AND table_name = ?');
    $st->execute([$banco, $tabela]);
    $r = [];
    foreach ($st->fetchAll() as $c) {
        $pad = $c['pad'] === null || $c['pad'] === 'NULL' ? '' : trim((string) $c['pad'], "'");
        $r[$c['n']] = strtolower($c['t']) . '|' . $c['nulo'] . '|' . $pad;
    }
    ksort($r);
    return $r;
}

fase('A. Banco antigo + migração');
$hash = password_hash('x', PASSWORD_DEFAULT);
$admin = ins($raw, 'usuarios', ['nome' => 'Admin', 'email' => 'admin@teste.invalid', 'senha' => $hash, 'perfil' => 'admin']);
$u1 = ins($raw, 'usuarios', ['nome' => 'Vendedor Um', 'email' => 'um@teste.invalid', 'senha' => $hash, 'perfil' => 'usuario']);
$u2 = ins($raw, 'usuarios', ['nome' => 'Vendedor Dois', 'email' => 'dois@teste.invalid', 'senha' => $hash, 'perfil' => 'usuario']);

$dados_f1 = ['ativo' => true, 'boas_vindas' => ['mensagem' => 'Oi'], 'planos' => [
    ['id' => 'fp1', 'nome' => 'Plano Mensal', 'valor' => 29.9, 'dias_acesso' => 30, 'unidade_acesso' => 'dias', 'entrega_tipo' => 'grupo', 'id_grupo' => '-1001'],
    ['id' => 'fp2', 'nome' => 'Plano 12 horas', 'valor' => 5, 'dias_acesso' => 12, 'unidade_acesso' => 'horas'],
    ['id' => 'fp3', 'nome' => 'Anual', 'valor' => 199, 'dias_acesso' => 365, 'unidade_acesso' => 'dias', 'entrega_tipo' => 'link', 'link_entrega' => 'https://exemplo.com/anual'],
    ['id' => 'fp4', 'nome' => 'Plano 45 dias', 'valor' => 60, 'dias_acesso' => 45, 'unidade_acesso' => 'dias'],
], 'ofertas' => ['order_bump' => ['ativo' => true, 'nome' => 'Bônus do fluxo', 'valor_extra' => 5, 'mensagem' => 'Leve o bônus do fluxo', 'quando' => 'depois_pagamento']], 'pagamentos' => []];
$fluxo = fn(int $u, string $nome, string $modo, array $dados) => ins($raw, 'fluxos', ['id_usuario' => $u, 'nome' => $nome, 'modo' => $modo, 'dados_fluxograma' => json_encode($dados)]);
$f1 = $fluxo($u1, 'Funil A', 'basico', $dados_f1);
$f2 = $fluxo($u1, 'Funil sem bot', 'basico', ['ativo' => true, 'planos' => []]);
$f3 = $fluxo($u1, 'Funil visual', 'avancado', ['operators' => new stdClass(), 'links' => []]);
$f5 = $fluxo($u1, 'Funil só bot ruim', 'basico', ['ativo' => true, 'planos' => []]);
$f6 = $fluxo($u2, 'Funil do outro usuário', 'basico', ['ativo' => true, 'planos' => [['id' => 'x1', 'nome' => 'Do outro', 'valor' => 10, 'dias_acesso' => 30, 'unidade_acesso' => 'dias']]]);
$bot = fn(int $u, string $token, string $nome, ?int $f) => ins($raw, 'bots', ['id_usuario' => $u, 'token' => $token, 'id_bot_telegram' => random_int(7000000, 7999999), 'nome_usuario' => $nome . '_bot', 'primeiro_nome' => $nome, 'id_fluxo_conectado' => $f]);
$b1 = $bot($u1, 'OKTOKEN1:aaaa', 'Bot Um', $f1);
$b2 = $bot($u1, 'OKTOKEN2:bbbb', 'Bot Dois', $f1);
$b3 = $bot($u1, 'BADTOKEN3:cccc', 'Bot Ruim', $f3);
$b4 = $bot($u1, 'OKTOKEN4:dddd', 'Bot Quatro', $f3);
$b5 = $bot($u1, 'BADTOKEN5:eeee', 'Bot Cinco', $f5);
$b6 = $bot($u2, 'OKTOKEN6:ffff', 'Bot Seis', $f6);
// Dois fluxos pequenos para o teste de "campanha excluída durante o envio": o 1º tem um lead de resposta lenta (chat terminado em 777)
$f7 = $fluxo($u1, 'Funil lento', 'basico', ['ativo' => true, 'planos' => []]);
$f8 = $fluxo($u1, 'Funil rápido', 'basico', ['ativo' => true, 'planos' => []]);
$b7 = $bot($u1, 'OKTOKEN7:gggg', 'Bot Sete', $f7);
$b8 = $bot($u1, 'OKTOKEN8:hhhh', 'Bot Oito', $f8);
foreach ([[$b1, '-1001', 'grupo 1'], [$b1, '-1002', 'grupo 2'], [$b3, '-3001', 'VIP visual']] as [$bid, $gid, $tit]) {
    ins($raw, 'bot_grupos', ['bot_id' => $bid, 'id_telegram' => $gid, 'titulo' => $tit, 'tipo' => 'supergroup']);
}
$lead = fn(int $bid, string $id, string $nome, int $pagou, ?string $criado = null, ?string $usuario = null) => ins($raw, 'leads', ['id_telegram' => $id, 'nome' => $nome, 'nome_usuario_telegram' => $usuario, 'pagou' => $pagou, 'bot_id' => $bid, 'criado_em' => $criado ?? date('Y-m-d H:i:s')]);
// Fluxo A (2 bots). A ordem de inserção é a ordem do envio (ORDER BY l.id): 6 leads do bot 1, depois 4 do bot 2.
$lead($b1, '1001', 'Ana', 0, '2026-10-01 10:00:00', 'ana_sp');
$lead($b1, '1002', 'Bruno <b>', 0);
$lead($b1, '1003', 'Carla', 1, '2026-09-01 10:00:00');
$lead($b1, '1004', 'Davi', 0, '2026-09-10 10:00:00');
$lead($b1, '1005', 'Eva', 1, '2026-08-01 10:00:00');
$lead($b1, '1006', 'Fábio', 1, '2026-08-05 10:00:00');
$lead($b2, '2001', 'Gil', 0);
$lead($b2, '2002', 'Hana', 0, '2026-09-20 10:00:00');
$lead($b2, '70403', 'Bloqueou', 0, '2026-09-21 10:00:00');
$lead($b2, '2004', 'Iris', 1, '2026-09-22 10:00:00');
// Fluxo visual com um bot ruim (token recusado) e um bom, intercalados
for ($i = 1; $i <= 12; $i++) {
    $lead($i % 2 ? $b3 : $b4, (string) (3000 + $i), 'Lead V' . $i, 0, '2026-09-01 10:00:00');
}
// Fluxo só com o bot ruim
for ($i = 1; $i <= 12; $i++) {
    $lead($b5, (string) (5000 + $i), 'Lead R' . $i, 0, '2026-09-01 10:00:00');
}
// Outro usuário
foreach (['6001', '6002', '6003'] as $id) {
    $lead($b6, $id, 'Do outro ' . $id, 0, '2026-09-01 10:00:00');
}
foreach ([[$b7, '70777'], [$b7, '70778'], [$b8, '80001'], [$b8, '80002']] as [$bid, $idl]) {
    $lead($bid, $idl, 'Lead ' . $idl, 0, '2026-09-01 10:00:00');
}
ins($raw, 'vendas', ['id_telegram' => '1004', 'bot_id' => $b1, 'valor' => 10, 'status' => 'gerado', 'transacao_id' => 'T-1004']);
ins($raw, 'vendas', ['id_telegram' => '2002', 'bot_id' => $b2, 'valor' => 10, 'status' => 'expirado', 'transacao_id' => 'T-2002']);
ins($raw, 'vendas', ['id_telegram' => '1003', 'bot_id' => $b1, 'valor' => 10, 'status' => 'pago', 'transacao_id' => 'T-1003']);
// Linhas "sentinela" nos caches de métricas e vendas sem tempo_acesso_minutos (como as 1,4 mi do teste de carga da VPS): a migração NÃO pode refazer nem reescrever nada disso
ins($raw, 'metricas_horarias_admin', ['data' => '2020-01-01', 'hora' => 1, 'faturamento' => 999, 'comissao' => 1, 'quantidade' => 9]);
ins($raw, 'metricas_horarias_usuario', ['id_usuario' => $u1, 'data' => '2020-01-01', 'hora' => 1, 'valor_pago' => 777, 'qtd_paga' => 7, 'qtd_gerada' => 7, 'qtd_leads' => 7]);
ins($raw, 'membros_grupos', ['id_telegram' => '1005', 'id_grupo_telegram' => '-1001', 'bot_id' => $b1, 'status' => 'expirado', 'data_expiracao' => '2026-09-01 00:00:00']);
ins($raw, 'membros_grupos', ['id_telegram' => '1006', 'id_grupo_telegram' => '-1001', 'bot_id' => $b1, 'status' => 'ativo', 'data_expiracao' => '2027-09-01 00:00:00']);
$raw->exec("UPDATE gateways SET ativo = 1 WHERE nome = 'omegapayments'");
// campanhas do modelo antigo (colunas de antes): uma agendada para amanhã (o cron a envia depois, quando eu antecipar), uma já concluída
$legada_agendada = ins($raw, 'remarketing_campanhas', ['id_usuario' => $u1, 'bot_id' => $b1, 'nome' => 'Campanha antiga', 'audiencia' => 'nao_comprou', 'mensagem' => 'Mensagem da campanha antiga!', 'agendado_em' => date('Y-m-d H:i:s', time() + 86400), 'status' => 'pendente']);
$legada_concluida = ins($raw, 'remarketing_campanhas', ['id_usuario' => $u1, 'bot_id' => $b1, 'nome' => 'Antiga concluída', 'audiencia' => 'comprou', 'mensagem' => 'Já enviada', 'agendado_em' => '2026-08-01 10:00:00', 'status' => 'concluida', 'total_destinatarios' => 5, 'enviados' => 5, 'entregues' => 4, 'falhas' => 1]);
ins($raw, 'remarketing_envios', ['campanha_id' => $legada_concluida, 'id_telegram' => '1003', 'resultado' => 'sucesso']);
ins($raw, 'remarketing_envios', ['campanha_id' => $legada_concluida, 'id_telegram' => '1005', 'resultado' => 'falha', 'resposta' => '{"ok":false}']);
$antes = $raw->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = '$db_nome' AND table_name = 'remarketing_campanhas' AND column_name = 'id_fluxo'")->fetchColumn();
confere('antes da migração o banco é o antigo (sem id_fluxo)', (int) $antes === 0);

[$saida, $codigo] = sh(php_cmd($app . '/deploy/vps/atualizar-banco.php', [$app], false));
confere('Atualizar Banco roda sem erro (1ª vez)', $codigo === 0 && stripos($saida, 'Erro fatal') === false, mb_substr($saida, -500));
confere("Atualizar Banco informa a tabela 'remarketing_vendas'", strpos($saida, "remarketing_vendas' OK") !== false, mb_substr($saida, -300));
[$saida2, $codigo2] = sh(php_cmd($app . '/deploy/vps/atualizar-banco.php', [$app], false));
confere('migração NÃO reescreve vendas antigas sem minutos de acesso (o UPDATE em massa só vale quando a coluna nasce)', $raw->query("SELECT tempo_acesso_minutos FROM vendas WHERE transacao_id = 'T-1004'")->fetchColumn() === null);
confere('migração NÃO refaz o histórico das métricas quando as tabelas já existem (só a linha sentinela de cada uma)', (int) $raw->query('SELECT COUNT(*) FROM metricas_horarias_admin')->fetchColumn() === 1 && (int) $raw->query('SELECT COUNT(*) FROM metricas_horarias_usuario')->fetchColumn() === 1 && (float) $raw->query("SELECT faturamento FROM metricas_horarias_admin WHERE data = '2020-01-01'")->fetchColumn() === 999.0 && (float) $raw->query("SELECT valor_pago FROM metricas_horarias_usuario WHERE data = '2020-01-01'")->fetchColumn() === 777.0);
// tabela que NÃO existe é criada e o histórico é preenchido (o "uma vez" continua valendo)
$raw->exec('DROP TABLE metricas_horarias_admin');
$raw->exec('DROP TABLE metricas_horarias_usuario');
[$saida_m, $codigo_m] = sh(php_cmd($app . '/deploy/vps/atualizar-banco.php', [$app], false));
confere('tabelas de métricas recriadas pela migração voltam com o histórico (vendas pagas, vendas por usuário)', $codigo_m === 0 && (int) $raw->query('SELECT COUNT(*) FROM metricas_horarias_admin')->fetchColumn() >= 1 && (int) $raw->query('SELECT COUNT(*) FROM metricas_horarias_usuario')->fetchColumn() >= 1, mb_substr($saida_m, -300));
confere('rodar de novo é inofensivo (idempotente)', $codigo2 === 0 && stripos($saida2, 'Erro fatal') === false, mb_substr($saida2, -300));

$cols = colunas($raw, $db_nome, 'remarketing_campanhas');
foreach (['id_fluxo', 'bots_ids', 'periodo_tipo', 'periodo_inicio', 'periodo_fim', 'midia_file_ids', 'variantes', 'ofertas', 'recorrencia', 'execucoes', 'acum_entregues', 'acum_falhas'] as $c) {
    confere("migração criou remarketing_campanhas.$c", isset($cols[$c]));
}
confere('bot_id passou a aceitar NULL (campanha de fluxo não tem bot fixo)', ($cols['bot_id'] ?? '') !== '' && strpos($cols['bot_id'], '|YES|') !== false, $cols['bot_id'] ?? '');
confere('audiencia ganhou os segmentos novos sem perder os antigos', strpos($cols['audiencia'], "'nao_comprou','comprou','todos','pix_gerado','expirados','membros_ativos'") !== false, $cols['audiencia']);
confere('status ganhou "rascunho" (no fim da lista)', strpos($cols['status'], "'pendente','processando','concluida','falha','rascunho'") !== false, $cols['status']);
confere('período novo começa em "total"', ($cols['periodo_tipo'] ?? '') === "varchar(10)|NO|total", $cols['periodo_tipo'] ?? '');
confere('remarketing_envios ganhou variante (padrão 0)', (colunas($raw, $db_nome, 'remarketing_envios')['variante'] ?? '') === 'tinyint(4)|NO|0', colunas($raw, $db_nome, 'remarketing_envios')['variante'] ?? '');
confere('tabela remarketing_vendas criada', count(colunas($raw, $db_nome, 'remarketing_vendas')) === 4, array_keys(colunas($raw, $db_nome, 'remarketing_vendas')));
$leg = $raw->query("SELECT * FROM remarketing_campanhas WHERE id = $legada_agendada")->fetch();
confere('campanha antiga continua intacta depois da migração (bot, público, mensagem, situação)', $leg && (int) $leg['bot_id'] === $b1 && $leg['audiencia'] === 'nao_comprou' && $leg['mensagem'] === 'Mensagem da campanha antiga!' && $leg['status'] === 'pendente' && $leg['id_fluxo'] === null && $leg['periodo_tipo'] === 'total', $leg);
$leg2 = $raw->query("SELECT * FROM remarketing_campanhas WHERE id = $legada_concluida")->fetch();
confere('campanha antiga concluída: números e registro de envios preservados', $leg2 && (int) $leg2['entregues'] === 4 && (int) $raw->query("SELECT COUNT(*) FROM remarketing_envios WHERE campanha_id = $legada_concluida AND variante = 0")->fetchColumn() === 2);

// schema novo (instalação do zero) = schema migrado
$raw->exec('DROP DATABASE IF EXISTS rm_novo');
$raw->exec('CREATE DATABASE rm_novo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
[$o, $c] = sh('"' . $mb . '/mariadb.exe" -h127.0.0.1 -P' . $db_porta . ' -uroot rm_novo < ' . escapeshellarg(str_replace('/', '\\', $schema_novo)));
confere('deploy/schema.sql novo carrega num banco vazio', $c === 0, $o);
foreach (['remarketing_campanhas', 'remarketing_envios', 'remarketing_vendas'] as $t) {
    $a = colunas($raw, $db_nome, $t);
    $b = colunas($raw, 'rm_novo', $t);
    confere("schema migrado = schema.sql novo (colunas, tipos, nulidade e padrões) em $t", $a === $b, ['so_migrado' => array_diff_assoc($a, $b), 'so_novo' => array_diff_assoc($b, $a)]);
}
// o CREATE TABLE do instalacao.php: extrai, roda num banco novo e compara
$fonte_inst = (string) file_get_contents($app . '/instalacao.php');
$raw->exec('DROP DATABASE IF EXISTS rm_inst');
$raw->exec('CREATE DATABASE rm_inst CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
[$o, $c] = sh('"' . $mb . '/mariadb.exe" -h127.0.0.1 -P' . $db_porta . ' -uroot rm_inst < ' . escapeshellarg(str_replace('/', '\\', $schema_novo)));
$raw->exec('DROP TABLE rm_inst.remarketing_vendas, rm_inst.remarketing_envios, rm_inst.remarketing_campanhas');
$instalou = true;
foreach (['remarketing_campanhas', 'remarketing_envios', 'remarketing_vendas'] as $t) {
    if (!preg_match('/\$pdo->exec\("(CREATE TABLE IF NOT EXISTS ' . $t . ' \(.*?\) ENGINE=InnoDB[^"]*)"\);/s', $fonte_inst, $m)) {
        $instalou = false;
        confere("instalacao.php tem o CREATE TABLE de $t", false);
        continue;
    }
    try {
        $raw->exec(preg_replace('/CREATE TABLE IF NOT EXISTS ' . $t . '/', 'CREATE TABLE rm_inst.' . $t, $m[1], 1));
    } catch (Throwable $e) {
        $instalou = false;
        confere("DDL de $t em instalacao.php executa", false, $e->getMessage());
    }
}
if ($instalou) {
    foreach (['remarketing_campanhas', 'remarketing_envios', 'remarketing_vendas'] as $t) {
        $a = colunas($raw, 'rm_inst', $t);
        $b = colunas($raw, 'rm_novo', $t);
        confere("instalacao.php = schema.sql em $t", $a === $b, ['so_inst' => array_diff_assoc($a, $b), 'so_schema' => array_diff_assoc($b, $a)]);
    }
}
$raw->exec('DROP DATABASE rm_inst');
$raw->exec('DROP DATABASE rm_novo');

// ============================================================ funções (banco migrado) ============================================================
fase('B. Funções com banco');
require $app . '/conexao.php';   // $pdo global (fuso -03:00, como no painel)
require_once $app . '/funcoes/gateways.php';
require_once $app . '/funcoes/log.php';
require_once $app . '/funcoes/remarketing_campanhas.php';
saveUserGatewayConfig($u1, (int) $pdo->query("SELECT id FROM gateways WHERE nome = 'omegapayments'")->fetchColumn(), 'sim_pub_key', 'sim_secret_key', '', '', 'sim-chave-pix', true, 1, 'pj');   // só o usuário 1 tem gateway

function q(string $sql, array $p = []): array
{
    global $pdo;
    $s = $pdo->prepare($sql);
    $s->execute($p);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}
function q1(string $sql, array $p = [])
{
    $r = q($sql, $p);
    return $r ? array_values($r[0])[0] : null;
}

$bots_f1 = remarketingBotsDoFluxo($pdo, $u1, $f1);
confere('bots do fluxo A: os 2 vinculados, em ordem', array_column($bots_f1, 'id') === [$b1, $b2] && $bots_f1[0]['nome'] === 'Bot Um', $bots_f1);
confere('bots do fluxo de OUTRO usuário: nenhum', remarketingBotsDoFluxo($pdo, $u2, $f1) === []);
confere('fluxo sem bot: nenhum', remarketingBotsDoFluxo($pdo, $u1, $f2) === []);

$ids_f1 = [$b1, $b2];
$conta = fn(string $seg, ?string $ini = null, ?string $fim = null) => remarketingContarPublico($pdo, $ids_f1, $seg, $ini, $fim);
confere('público "todos": 10 leads dos 2 bots', $conta('todos') === 10, $conta('todos'));
confere('público "não compraram": 6', $conta('nao_comprou') === 6, $conta('nao_comprou'));
confere('público "já compraram": 4', $conta('comprou') === 4, $conta('comprou'));
confere('público "pix gerado": 2 (gerou e não pagou: 1004 e 2002)', $conta('pix_gerado') === 2, $conta('pix_gerado'));
confere('público "expirados": 1 (a Eva, VIP que venceu)', $conta('expirados') === 1, $conta('expirados'));
confere('público "membros ativos": 1 (o Fábio)', $conta('membros_ativos') === 1, $conta('membros_ativos'));
$hoje = remarketingIntervaloPeriodo('hoje');
confere('período "hoje": 2 leads (1002 e 2001)', $conta('todos', $hoje[0], $hoje[1]) === 2, $conta('todos', $hoje[0], $hoje[1]));
confere('período "hoje" + "não compraram": 2', $conta('nao_comprou', $hoje[0], $hoje[1]) === 2);
confere('período "hoje" + "já compraram": 0', $conta('comprou', $hoje[0], $hoje[1]) === 0);
confere('período escolhido (só 01/10/2026): 1 lead', $conta('todos', '2026-10-01 00:00:00', '2026-10-01 23:59:59') === 1);
confere('um bot só do fluxo: 6 leads (público do bot 1)', remarketingContarPublico($pdo, [$b1], 'todos', null, null) === 6);
confere('bot de outro fluxo não entra na conta', remarketingContarPublico($pdo, [$b6], 'todos', null, null) === 3);

$ctx = remarketingContexto($pdo, $u1);
$por_id = array_column($ctx['fluxos'], null, 'id');
confere('contexto: os 6 fluxos do usuário 1 (o do outro não aparece)', count($ctx['fluxos']) === 6 && !isset($por_id[$f6]), array_keys($por_id));
confere('contexto: fluxo A com 2 bots e os grupos do bot 1', count($por_id[$f1]['bots']) === 2 && array_column($por_id[$f1]['grupos'], 'id') === ['-1001', '-1002'], $por_id[$f1]['grupos']);
confere('contexto: fluxo sem bot vem com 0 bots', $por_id[$f2]['bots'] === []);
confere('contexto: fluxo visual traz bots e grupo, mas nenhum plano', count($por_id[$f3]['bots']) === 2 && $por_id[$f3]['planos'] === [] && array_column($por_id[$f3]['grupos'], 'id') === ['-3001']);
$pl = array_column($por_id[$f1]['planos'], null, 'id');
confere('contexto: planos do fluxo guiado (4) com acesso traduzido', count($pl) === 4 && $pl['fp1']['acesso'] === 'mensal' && $pl['fp3']['acesso'] === 'anual' && $pl['fp4']['acesso'] === 'dias' && $pl['fp4']['dias'] === 45, $pl);
confere('contexto: plano de horas entra como 1 dia (o menor acesso do remarketing)', $pl['fp2']['acesso'] === 'dias' && $pl['fp2']['dias'] === 1);
confere('contexto: entrega do plano do fluxo (grupo, link)', $pl['fp1']['entrega_tipo'] === 'grupo' && $pl['fp1']['id_grupo'] === '-1001' && $pl['fp3']['entrega_tipo'] === 'link' && $pl['fp3']['link_entrega'] === 'https://exemplo.com/anual');
confere('contexto: preço mínimo da gateway', $ctx['minimo_pix'] >= 1.5, $ctx['minimo_pix']);
$ctx2 = remarketingContexto($pdo, $u2);
confere('contexto do usuário 2: só o fluxo dele', count($ctx2['fluxos']) === 1 && $ctx2['fluxos'][0]['id'] === $f6 && $ctx2['fluxos'][0]['bots'][0]['id'] === $b6);

$entrada_c1 = [
    'nome' => 'Campanha C1', 'id_fluxo' => $f1, 'segmento' => 'todos', 'periodo_tipo' => 'total',
    'variantes' => [
        ['mensagem' => 'Olá {nome}, {saudacao}! Temos uma oferta.'],
        ['mensagem' => 'Versão B para {nome}', 'midia_caminho' => 'uploads/remarketing_foto_b.jpg', 'midia_tipo' => 'foto'],
    ],
    'ofertas' => [
        'planos' => [
            ['id' => 'pa', 'nome' => 'Plano Mensal', 'valor' => 29.9, 'acesso' => 'mensal', 'entrega_tipo' => 'grupo', 'id_grupo' => '-1001'],
            ['id' => 'pb', 'nome' => 'Plano Vitalício', 'valor' => 99, 'acesso' => 'vitalicio', 'entrega_tipo' => 'link', 'link_entrega' => 'https://exemplo.com/vip'],
        ],
        'redirect' => ['ativo' => true, 'texto' => 'Ver Prévias', 'url' => 'https://t.me/+abc'],
        'order_bump' => ['ativo' => false],
    ],
];
$r = remarketingSalvar($pdo, $u1, $entrada_c1);
$c1 = (int) $r['id'];
confere('salvar cria o rascunho', $r['sucesso'] && $c1 > 0 && strpos($r['mensagem'], 'Rascunho criado') === 0, $r);
$linha = q('SELECT * FROM remarketing_campanhas WHERE id = ?', [$c1])[0];
confere('rascunho gravado: situação, fluxo, sem bot fixo, público, período', $linha['status'] === 'rascunho' && (int) $linha['id_fluxo'] === $f1 && $linha['bot_id'] === null && $linha['audiencia'] === 'todos' && $linha['periodo_tipo'] === 'total' && $linha['bots_ids'] === null, $linha);
confere('rascunho gravado: a variante A nas colunas de sempre e a B no JSON, com a mídia', $linha['mensagem'] === 'Olá {nome}, {saudacao}! Temos uma oferta.' && $linha['midia_caminho'] === null && json_decode($linha['variantes'], true)[0]['midia_caminho'] === 'uploads/remarketing_foto_b.jpg', $linha['variantes']);
confere('rascunho gravado: ofertas em JSON com os 2 planos e o redirect', count(json_decode($linha['ofertas'], true)['planos']) === 2 && json_decode($linha['ofertas'], true)['redirect']['url'] === 'https://t.me/+abc');
confere('salvar registra no histórico de atividades do usuário', (int) q1("SELECT COUNT(*) FROM atividades WHERE id_usuario = ? AND titulo = 'Remarketing'", [$u1]) === 1);

$e = remarketingSalvar($pdo, $u1, ['id_fluxo' => $f6] + $entrada_c1);
confere('fluxo de OUTRO usuário é recusado', !$e['sucesso'] && $e['mensagem'] === 'Fluxo não encontrado.', $e);
$e = remarketingSalvar($pdo, $u1, ['id_fluxo' => 99999] + $entrada_c1);
confere('fluxo que não existe é recusado', !$e['sucesso'] && $e['mensagem'] === 'Fluxo não encontrado.');
$e = remarketingSalvar($pdo, $u1, ['id_fluxo' => $f2] + $entrada_c1);
confere('fluxo sem bot vinculado é recusado, com instrução', !$e['sucesso'] && strpos($e['mensagem'], 'sem bot vinculado') === false && strpos($e['mensagem'], 'ainda não tem bot') !== false, $e);
$ruim = $entrada_c1;
$ruim['ofertas']['planos'][0]['id_grupo'] = '-9999';
$e = remarketingSalvar($pdo, $u1, $ruim);
confere('grupo de entrega que não é dos bots do fluxo é recusado', !$e['sucesso'] && strpos($e['mensagem'], 'não pertence aos bots') !== false, $e);
$ruim = $entrada_c1;
$ruim['ofertas']['planos'][0]['id_grupo'] = '-3001';   // grupo do bot 3 (outro fluxo)
confere('grupo de outro fluxo do mesmo usuário também é recusado', !remarketingSalvar($pdo, $u1, $ruim)['sucesso']);
$ruim = $entrada_c1;
$ruim['variantes'][1]['midia_caminho'] = 'uploads/remarketing_foto_inexistente.jpg';
$e = remarketingSalvar($pdo, $u1, $ruim);
confere('mídia que não existe no servidor é recusada', !$e['sucesso'] && strpos($e['mensagem'], 'não foi encontrada') !== false, $e);
$ruim = $entrada_c1;
$ruim['ofertas']['order_bump'] = ['ativo' => true, 'nome' => 'Extra', 'valor' => 5, 'midia_caminho' => 'uploads/remarketing_foto_inexistente.jpg', 'midia_tipo' => 'foto'];
confere('mídia do order bump que não existe é recusada', !remarketingSalvar($pdo, $u1, $ruim)['sucesso']);
confere('nada foi gravado pelas tentativas recusadas', (int) q1('SELECT COUNT(*) FROM remarketing_campanhas WHERE id_usuario = ? AND id_fluxo IS NOT NULL', [$u1]) === 1);

$edit = $entrada_c1;
$edit['nome'] = 'Campanha C1 (editada)';
$r = remarketingSalvar($pdo, $u1, $edit, $c1);
confere('editar o rascunho funciona', $r['sucesso'] && $r['mensagem'] === 'Alterações salvas.' && q1('SELECT nome FROM remarketing_campanhas WHERE id = ?', [$c1]) === 'Campanha C1 (editada)', $r);
$pdo->prepare("UPDATE remarketing_campanhas SET midia_file_id = 'x', midia_file_ids = '{\"a\":\"b\"}' WHERE id = ?")->execute([$c1]);
remarketingSalvar($pdo, $u1, $edit, $c1);
$l = q('SELECT midia_file_id, midia_file_ids FROM remarketing_campanhas WHERE id = ?', [$c1])[0];
confere('editar zera o file_id guardado do Telegram (a mídia pode ter mudado)', $l['midia_file_id'] === null && $l['midia_file_ids'] === null);
$entrada_u2 = ['nome' => 'Do outro', 'id_fluxo' => $f6, 'segmento' => 'todos', 'periodo_tipo' => 'total', 'variantes' => [['mensagem' => 'Mensagem do usuário 2!']], 'ofertas' => []];
$r = remarketingSalvar($pdo, $u2, $entrada_u2, $c1);
confere('editar a campanha de OUTRO usuário é recusado', !$r['sucesso'] && $r['mensagem'] === 'Campanha não encontrada.', $r);
confere('e a campanha do usuário 1 não foi mexida', q1('SELECT nome FROM remarketing_campanhas WHERE id = ?', [$c1]) === 'Campanha C1 (editada)');
$r = remarketingSalvar($pdo, $u1, $edit, 987654);
confere('editar campanha que não existe: "não encontrada"', !$r['sucesso'] && $r['mensagem'] === 'Campanha não encontrada.');
$ed = remarketingParaEdicao(remarketingLinha($pdo, $u1, $c1));
confere('para edição: o assistente recebe variantes, ofertas e "editavel"', $ed['editavel'] === true && count($ed['variantes']) === 2 && count($ed['ofertas']['planos']) === 2 && $ed['segmento'] === 'todos' && $ed['status'] === 'rascunho', array_keys($ed));
confere('campanha de outro usuário não carrega (remarketingLinha)', remarketingLinha($pdo, $u2, $c1) === null);

// iniciar
$r = remarketingIniciar($pdo, $u1, $c1, '2020-01-01 10:00');
confere('agendar para o passado é recusado', !$r['sucesso'] && strpos($r['mensagem'], 'já passou') !== false, $r);
foreach (['amanhã', '2030-13-45 99:99', '2030-01-01', "2030-01-01 10:00'; DROP TABLE x"] as $lixo) {
    $r = remarketingIniciar($pdo, $u1, $c1, $lixo);
    confere("data fora do formato é recusada ($lixo)", !$r['sucesso'] && strpos($r['mensagem'], 'Informe a data e a hora') !== false, $r);
}
$vazio = $entrada_c1;
$vazio['nome'] = 'Sem público';
$vazio['segmento'] = 'membros_ativos';
$vazio['periodo_tipo'] = 'custom';
$vazio['periodo_de'] = '2020-01-01';
$vazio['periodo_ate'] = '2020-01-02';
$c_vazio = (int) remarketingSalvar($pdo, $u1, $vazio)['id'];
$r = remarketingIniciar($pdo, $u1, $c_vazio);
confere('iniciar sem ninguém no público é recusado, e a campanha continua rascunho', !$r['sucesso'] && strpos($r['mensagem'], 'não tem ninguém') !== false && q1('SELECT status FROM remarketing_campanhas WHERE id = ?', [$c_vazio]) === 'rascunho', $r);
$c_u2 = (int) remarketingSalvar($pdo, $u2, ['variantes' => [['mensagem' => 'Mensagem do usuário 2!']], 'ofertas' => ['planos' => [['id' => 'zz', 'nome' => 'P', 'valor' => 10, 'acesso' => 'mensal', 'entrega_tipo' => 'nenhuma']]]] + $entrada_u2)['id'];
$r = remarketingIniciar($pdo, $u2, $c_u2);
confere('campanha com plano e usuário sem gateway é recusada', !$r['sucesso'] && strpos($r['mensagem'], 'gateway') !== false, $r);
$r = remarketingIniciar($pdo, $u1, $c_u2);
confere('iniciar a campanha de OUTRO usuário: "não encontrada"', !$r['sucesso'] && $r['mensagem'] === 'Campanha não encontrada.', $r);
$r = remarketingIniciar($pdo, $u1, $c1, '2030-01-01 10:00');
$l = q('SELECT status, agendado_em, bots_ids FROM remarketing_campanhas WHERE id = ?', [$c1])[0];
confere('agendar: vira "pendente" no horário escolhido e congela os bots do fluxo', $r['sucesso'] && $l['status'] === 'pendente' && $l['agendado_em'] === '2030-01-01 10:00:00' && $l['bots_ids'] === "$b1,$b2" && strpos($r['mensagem'], '01/01/2030 10:00') !== false, [$r, $l]);
$r = remarketingSalvar($pdo, $u1, ['nome' => 'C1 agendada e editada'] + $edit, $c1);
confere('campanha agendada que ainda não começou pode ser editada', $r['sucesso']);
$r = remarketingIniciar($pdo, $u1, $c1, '2030-02-02 11:30');
confere('e reagendada', $r['sucesso'] && q1('SELECT agendado_em FROM remarketing_campanhas WHERE id = ?', [$c1]) === '2030-02-02 11:30:00');
$r = remarketingIniciar($pdo, $u1, $c1, '2030-02-02 11:30');
confere('reagendar para o MESMO horário não é erro (o banco informa 0 linhas alteradas)', $r['sucesso'], $r);
$r = remarketingSalvar($pdo, $u1, ['nome' => 'C1 agendada e editada'] + $edit, $c1);
$r2 = remarketingSalvar($pdo, $u1, ['nome' => 'C1 agendada e editada'] + $edit, $c1);
confere('salvar SEM mudar nada também não é erro', $r['sucesso'] && $r2['sucesso'] && $r2['mensagem'] === 'Alterações salvas.', $r2);
// trocar o FLUXO de uma campanha agendada: os bots congelados passam a ser os do fluxo novo (antes ficavam os do antigo e o envio saía pelo bot errado)
$troca = ['nome' => 'C1 agendada e editada', 'id_fluxo' => $f3, 'ofertas' => []] + $edit;
$r = remarketingSalvar($pdo, $u1, $troca, $c1);
$l = q('SELECT id_fluxo, bots_ids, status FROM remarketing_campanhas WHERE id = ?', [$c1])[0];
confere('trocar o fluxo da campanha agendada leva os bots do fluxo NOVO', $r['sucesso'] && (int) $l['id_fluxo'] === $f3 && $l['bots_ids'] === "$b3,$b4" && $l['status'] === 'pendente', [$r, $l]);
remarketingSalvar($pdo, $u1, ['nome' => 'C1 agendada e editada'] + $edit, $c1);
confere('e de volta ao fluxo A, os bots do fluxo A', q1('SELECT bots_ids FROM remarketing_campanhas WHERE id = ?', [$c1]) === "$b1,$b2");
$pdo->prepare("UPDATE remarketing_campanhas SET status = 'rascunho', bots_ids = NULL, agendado_em = NOW() WHERE id = ?")->execute([$c1]);   // volta a rascunho para a fase do cron

// excluir (cascata do registro de envios e das vendas ligadas)
$c_del = (int) remarketingSalvar($pdo, $u1, ['nome' => 'Para excluir'] + $entrada_c1)['id'];
ins($pdo, 'remarketing_envios', ['campanha_id' => $c_del, 'id_telegram' => '1', 'resultado' => 'sucesso', 'variante' => 0]);
$v_del = ins($pdo, 'vendas', ['id_telegram' => '1001', 'bot_id' => $b1, 'valor' => 5, 'status' => 'pago', 'transacao_id' => 'T-DEL']);
ins($pdo, 'remarketing_vendas', ['venda_id' => $v_del, 'campanha_id' => $c_del, 'variante' => 0]);
$r = remarketingExcluir($pdo, $u2, $c_del);
confere('excluir a campanha de OUTRO usuário é recusado', !$r['sucesso'] && (int) q1('SELECT COUNT(*) FROM remarketing_campanhas WHERE id = ?', [$c_del]) === 1);
$r = remarketingExcluir($pdo, $u1, $c_del);
confere('excluir apaga a campanha e, em cascata, os envios e a ligação com a venda (a venda em si fica)', $r['sucesso'] && (int) q1('SELECT COUNT(*) FROM remarketing_campanhas WHERE id = ?', [$c_del]) === 0 && (int) q1('SELECT COUNT(*) FROM remarketing_envios WHERE campanha_id = ?', [$c_del]) === 0 && (int) q1('SELECT COUNT(*) FROM remarketing_vendas WHERE campanha_id = ?', [$c_del]) === 0 && (int) q1('SELECT COUNT(*) FROM vendas WHERE id = ?', [$v_del]) === 1);
confere('excluir de novo: "não encontrada"', !remarketingExcluir($pdo, $u1, $c_del)['sucesso']);

// ============================================================ cron ============================================================
fase('C. Cron com Telegram falso');
function tg_limpar(): void
{
    global $tg_log;
    file_put_contents($tg_log, '');
}
function tg(): array
{
    global $tg_log;
    $r = [];
    foreach (file($tg_log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
        $r[] = json_decode($l, true);
    }
    return $r;
}
function cron(): string
{
    global $app;
    [$s] = sh(php_cmd($app . '/cron/cron_remarketing.php'));
    return $s;
}
/** Faz passar o tempo: os envios que terminaram há menos de 3 h passam a ter terminado há mais de 4 h (o fluxo libera de novo). */
function envelhecer(int $horas = 4): void
{
    global $pdo;
    $pdo->exec("UPDATE remarketing_campanhas SET processado_em = processado_em - INTERVAL $horas HOUR WHERE processado_em > NOW() - INTERVAL 3 HOUR");
}
function porChat(array $linhas): array
{
    $r = [];
    foreach ($linhas as $l) {
        $r[(string) $l['chat_id']][] = $l;
    }
    return $r;
}
$campanha = fn(int $id) => q('SELECT * FROM remarketing_campanhas WHERE id = ?', [$id])[0];
$variantes_envio = fn(int $id) => array_column(q('SELECT id_telegram, variante FROM remarketing_envios WHERE campanha_id = ? ORDER BY id', [$id]), 'variante', 'id_telegram');

// --- C1: fluxo com 2 bots, variantes A/B, mídia, lotes de 7 ---
$r = remarketingIniciar($pdo, $u1, $c1);
confere('C1 iniciada agora: pendente e com os bots congelados', $r['sucesso'] && $campanha($c1)['status'] === 'pendente' && $campanha($c1)['bots_ids'] === "$b1,$b2", $r);
tg_limpar();
$s = cron();
$log = tg();
$por = porChat($log);
confere('C1 rodada 1: 7 envios (lote de 7 leads, em ordem de id, pelos 2 bots)', count($log) === 7 && array_map('strval', array_keys($por)) === ['1001', '1002', '1003', '1004', '1005', '1006', '2001'], array_keys($por));
$a_chats = ['1001', '1003', '1005', '2001'];
$b_chats = ['1002', '1004', '1006'];
confere('variante A (sem mídia) vai em sendMessage e a B (com foto) em sendPhoto, em rodízio', array_reduce($a_chats, fn($c, $x) => $c && $por[$x][0]['metodo'] === 'sendMessage', true) && array_reduce($b_chats, fn($c, $x) => $c && $por[$x][0]['metodo'] === 'sendPhoto', true));
confere('cada lead sai pelo token do SEU bot', $por['1001'][0]['token'] === 'OKTOKEN1:aaaa' && $por['1006'][0]['token'] === 'OKTOKEN1:aaaa' && $por['2001'][0]['token'] === 'OKTOKEN2:bbbb');
confere('{nome} e {saudacao} preenchidos na variante A', (bool) preg_match('/^Olá Ana, (Bom dia|Boa tarde|Boa noite)! Temos uma oferta\.$/', $por['1001'][0]['text'] ?? ''), $por['1001'][0]['text'] ?? '');
confere('{nome} da variante B vai escapado (HTML) e como legenda da foto', ($por['1002'][0]['caption'] ?? '') === 'Versão B para Bruno &lt;b&gt;', $por['1002'][0]['caption'] ?? '');
$teclado = $por['1001'][0]['reply_markup']['inline_keyboard'] ?? [];
confere('teclado: um botão por plano com o callback da campanha, do plano e da variante, e o redirect', count($teclado) === 3 && $teclado[0][0]['callback_data'] === "rmk::$c1::pa::0" && $teclado[1][0]['callback_data'] === "rmk::$c1::pb::0" && ($teclado[2][0]['url'] ?? '') === 'https://t.me/+abc' && $teclado[0][0]['text'] === 'Plano Mensal — R$ 29,90', $teclado);
confere('a variante B leva a variante 1 no callback', ($por['1002'][0]['reply_markup']['inline_keyboard'][0][0]['callback_data'] ?? '') === "rmk::$c1::pa::1");
$envios_b1 = array_filter($log, fn($l) => $l['token'] === 'OKTOKEN1:aaaa' && $l['metodo'] === 'sendPhoto');
$uploads_b1 = array_filter($envios_b1, fn($l) => !empty($l['arquivos']['photo']));
$por_id_b1 = array_filter($envios_b1, fn($l) => ($l['file_id'] ?? '') !== '' && strpos($l['file_id'], 'FID') === 0);
confere('a foto sobe UMA vez por bot e variante; os demais envios usam o file_id', count($envios_b1) === 3 && count($uploads_b1) === 1 && count($por_id_b1) === 2, [count($envios_b1), count($uploads_b1), count($por_id_b1)]);
$l = $campanha($c1);
confere('C1 rodada 1 no banco: processando, total 10, deslocamento 7, 7 entregues', $l['status'] === 'processando' && (int) $l['total_destinatarios'] === 10 && (int) $l['offset_envio'] === 7 && (int) $l['enviados'] === 7 && (int) $l['entregues'] === 7 && (int) $l['falhas'] === 0, $l);
confere('file_id guardado por bot e variante (só o bot 1 por enquanto)', array_keys(json_decode((string) $l['midia_file_ids'], true) ?: []) === ["$b1:1"], $l['midia_file_ids']);
confere('registro de envios com a variante de cada lead', $variantes_envio($c1) === ['1001' => 0, '1002' => 1, '1003' => 0, '1004' => 1, '1005' => 0, '1006' => 1, '2001' => 0], $variantes_envio($c1));

tg_limpar();
cron();
$log = tg();
$por = porChat($log);
confere('C1 rodada 2: os 3 leads que faltavam', array_map('strval', array_keys($por)) === ['2002', '70403', '2004'], array_keys($por));
confere('bot 2 sobe a foto na 1ª vez dele (file_id é por bot) e reaproveita depois', !empty($por['2002'][0]['arquivos']['photo']) && ($por['2004'][0]['file_id'] ?? '') !== '' && empty($por['2004'][0]['arquivos']), [$por['2002'][0], $por['2004'][0]]);
$l = $campanha($c1);
confere('C1 concluída: 10 enviados, 9 entregues, 1 falha (o lead que bloqueou o bot), deslocamento 10', $l['status'] === 'concluida' && (int) $l['enviados'] === 10 && (int) $l['entregues'] === 9 && (int) $l['falhas'] === 1 && (int) $l['offset_envio'] === 10 && $l['processado_em'] !== null, $l);
confere('quem bloqueou o bot é falha do destinatário: não parou a campanha', (int) q1("SELECT COUNT(*) FROM remarketing_envios WHERE campanha_id = ? AND resultado = 'falha' AND id_telegram = '70403'", [$c1]) === 1);
confere('file_id dos dois bots guardado', array_keys(json_decode((string) $l['midia_file_ids'], true)) === ["$b1:1", "$b2:1"], $l['midia_file_ids']);
tg_limpar();
$s = cron();
confere('nova rodada: nada a fazer e nenhum envio', count(tg()) === 0 && strpos($s, 'Nenhuma campanha pendente') !== false, $s);

// --- C2: fluxo com um único bot de token recusado ---
$e2 = ['nome' => 'C2 bot ruim', 'id_fluxo' => $f5, 'segmento' => 'todos', 'periodo_tipo' => 'total', 'variantes' => [['mensagem' => 'Mensagem para o fluxo do bot ruim']], 'ofertas' => []];
$c2 = (int) remarketingSalvar($pdo, $u1, $e2)['id'];
confere('C2 iniciada', remarketingIniciar($pdo, $u1, $c2)['sucesso']);
tg_limpar();
$s = cron();
$log = tg();
$l = $campanha($c2);
confere('C2: com o token recusado o envio para depois de 3 falhas seguidas (limite do teste) em vez de tentar os 12', count($log) === 3, count($log));
confere('C2: campanha vira "falha", sem entregas, e o deslocamento passa pelos leads descartados', $l['status'] === 'falha' && (int) $l['entregues'] === 0 && (int) $l['falhas'] === 3 && (int) $l['offset_envio'] === 7 && $l['processado_em'] !== null, $l);
confere('C2: o bot fica marcado com token recusado (aviso ao dono)', q1('SELECT token_invalido_em FROM bots WHERE id = ?', [$b5]) !== null);

// --- C3: fluxo com um bot ruim e um bom, intercalados ---
$e3 = ['nome' => 'C3 misto', 'id_fluxo' => $f3, 'segmento' => 'todos', 'periodo_tipo' => 'total', 'variantes' => [['mensagem' => 'Mensagem do fluxo misto']], 'ofertas' => []];
$c3 = (int) remarketingSalvar($pdo, $u1, $e3)['id'];
confere('C3 iniciada', remarketingIniciar($pdo, $u1, $c3)['sucesso']);
tg_limpar();
cron();
$log1 = tg();
$l = $campanha($c3);
confere('C3 rodada 1: o bot ruim para depois de 3 falhas e o bot bom segue; deslocamento 7, ainda processando', $l['status'] === 'processando' && (int) $l['offset_envio'] === 7 && (int) $l['entregues'] === 3 && (int) $l['falhas'] === 3, $l);
confere('C3 rodada 1: o lead 3007 (do bot parado) ficou sem tentativa', !in_array('3007', array_column($log1, 'chat_id'), true) && count($log1) === 6, array_column($log1, 'chat_id'));
tg_limpar();
cron();
$log2 = tg();
$l = $campanha($c3);
confere('C3 concluída (não "falha": o outro bot funciona): todos os 6 leads do bot bom receberam', $l['status'] === 'concluida' && (int) $l['entregues'] === 6 && (int) $l['offset_envio'] === 12, $l);
$entregues_c3 = q("SELECT id_telegram FROM remarketing_envios WHERE campanha_id = ? AND resultado = 'sucesso' ORDER BY id_telegram", [$c3]);
confere('C3: os entregues são exatamente os leads do bot bom (pares)', array_column($entregues_c3, 'id_telegram') === ['3002', '3004', '3006', '3008', '3010', '3012'], array_column($entregues_c3, 'id_telegram'));
confere('C3: nenhum lead do bot ruim recebeu', (int) q1("SELECT COUNT(*) FROM remarketing_envios WHERE campanha_id = ? AND resultado = 'sucesso' AND id_telegram IN ('3001','3003','3005','3007','3009','3011')", [$c3]) === 0);

// --- C4 (campanha antiga) e C5 (período "hoje") juntas, na mesma rodada do cron ---
$pdo->prepare('UPDATE remarketing_campanhas SET agendado_em = NOW() - INTERVAL 1 MINUTE WHERE id = ?')->execute([$legada_agendada]);
$e5 = ['nome' => 'C5 hoje', 'id_fluxo' => $f1, 'segmento' => 'todos', 'periodo_tipo' => '7d', 'variantes' => [['mensagem' => 'Mensagem do período relativo']], 'ofertas' => []];
$c5 = (int) remarketingSalvar($pdo, $u1, $e5)['id'];
$pdo->prepare("UPDATE remarketing_campanhas SET periodo_tipo = 'hoje' WHERE id = ?")->execute([$c5]);
$r_c5 = remarketingIniciar($pdo, $u1, $c5);
confere('C5 (mesmo fluxo da C1, que acabou de sair) é recusada pelo intervalo de 3 horas do fluxo', !$r_c5['sucesso'] && strpos($r_c5['mensagem'], '3 horas') !== false && $campanha($c5)['status'] === 'rascunho', $r_c5);
envelhecer();   // passaram 4 horas
confere('C5 iniciada passadas as 3 horas (período "hoje" fica relativo: sem janela gravada ainda)', remarketingIniciar($pdo, $u1, $c5)['sucesso'] && $campanha($c5)['periodo_inicio'] === null);
// rascunho e agendada para o futuro nunca saem
$c_rasc = (int) remarketingSalvar($pdo, $u1, ['nome' => 'Rascunho que nunca sai', 'variantes' => [['mensagem' => 'MARCADOR-RASCUNHO nunca deve ser enviado']]] + $entrada_c1)['id'];
$c_fut = (int) remarketingSalvar($pdo, $u1, ['nome' => 'Futura', 'variantes' => [['mensagem' => 'MARCADOR-FUTURA ainda não é a hora']]] + $entrada_c1)['id'];
remarketingIniciar($pdo, $u1, $c_fut, '2031-01-01 10:00');
tg_limpar();
cron();
$log = tg();
$por = porChat($log);
$l4 = $campanha($legada_agendada);
confere('C4 (campanha antiga, só bot_id): enviada pelo bot dela aos 3 que nunca compraram, texto puro e sem teclado', $l4['status'] === 'concluida' && (int) $l4['total_destinatarios'] === 3 && (int) $l4['entregues'] === 3 && !isset($por['1001'][0]['reply_markup']) && ($por['1001'][0]['text'] ?? '') === 'Mensagem da campanha antiga!', [$l4, $por['1001'][0] ?? null]);
$l5 = $campanha($c5);
confere('C5 (período "hoje"): só os 2 leads de hoje, e a janela do dia fica gravada', $l5['status'] === 'concluida' && (int) $l5['total_destinatarios'] === 2 && (int) $l5['entregues'] === 2 && $l5['periodo_inicio'] === date('Y-m-d') . ' 00:00:00' && $l5['periodo_fim'] === date('Y-m-d') . ' 23:59:59', $l5);
$textos = array_column($log, 'text');
confere('as duas campanhas saíram na mesma rodada, cada uma com o próprio texto', in_array('Mensagem do período relativo', $textos, true) && in_array('Mensagem da campanha antiga!', $textos, true));
confere('rascunho e campanha agendada para o futuro NÃO foram enviados', !preg_grep('/MARCADOR/', array_map('strval', array_merge($textos, array_column($log, 'caption')))) && $campanha($c_rasc)['status'] === 'rascunho' && $campanha($c_fut)['status'] === 'pendente');
confere('campanha antiga já concluída não foi tocada', (int) $campanha($legada_concluida)['entregues'] === 4);

// campanha EXCLUÍDA enquanto sai: o cron não pode cair (a chave estrangeira recusa o registro de envio) e a outra campanha da mesma rodada tem que concluir
$e_lento = ['nome' => 'Será excluída no meio do envio', 'id_fluxo' => $f7, 'segmento' => 'todos', 'periodo_tipo' => 'total', 'variantes' => [['mensagem' => 'Mensagem da campanha que será excluída']], 'ofertas' => []];
$e_rapido = ['nome' => 'Segue normal', 'id_fluxo' => $f8, 'segmento' => 'todos', 'periodo_tipo' => 'total', 'variantes' => [['mensagem' => 'Mensagem da campanha que segue']], 'ofertas' => []];
$c_lento = (int) remarketingSalvar($pdo, $u1, $e_lento)['id'];
$c_rapido = (int) remarketingSalvar($pdo, $u1, $e_rapido)['id'];
remarketingIniciar($pdo, $u1, $c_lento);
remarketingIniciar($pdo, $u1, $c_rapido);
tg_limpar();
$pipes = [];
$proc = proc_open(php_cmd($app . '/cron/cron_remarketing.php'), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
for ($i = 0; $i < 100; $i++) {   // espera o pedido do lead lento chegar ao Telegram falso (ele fica 1,5 s no ar)
    if (array_filter(tg(), fn($l) => !empty($l['recebido']))) {
        break;
    }
    usleep(100000);
}
$apagou = remarketingExcluir($pdo, $u1, $c_lento);
$saida_cron = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
$codigo_cron = proc_close($proc);
confere('excluir uma campanha EM ENVIO funciona e a mensagem diz que o envio foi interrompido', $apagou['sucesso'] && strpos($apagou['mensagem'], 'interrompido') !== false, $apagou);
confere('cron termina sem erro nem exceção quando a campanha some no meio do envio', $codigo_cron === 0 && !preg_match('/(Fatal|Uncaught|Stack trace)/', $saida_cron), $saida_cron);
confere('o cron avisa que interrompeu a campanha excluída', strpos($saida_cron, "Campanha #$c_lento excluída durante o envio") !== false, $saida_cron);
$l = $campanha($c_rapido);
confere('a outra campanha da mesma rodada conclui normalmente', $l['status'] === 'concluida' && (int) $l['entregues'] === 2 && (int) $l['offset_envio'] === 2, $l);
confere('a campanha excluída sumiu de vez, com o registro dela', (int) q1('SELECT COUNT(*) FROM remarketing_campanhas WHERE id = ?', [$c_lento]) === 0 && (int) q1('SELECT COUNT(*) FROM remarketing_envios WHERE campanha_id = ?', [$c_lento]) === 0);

// já enviada: não edita nem reinicia
$r = remarketingSalvar($pdo, $u1, $edit, $c1);
confere('campanha já enviada não pode mais ser editada', !$r['sucesso'] && strpos($r['mensagem'], 'não pode mais ser editada') !== false, $r);
$r = remarketingIniciar($pdo, $u1, $c1);
confere('iniciar a campanha já enviada é REENVIAR, e o fluxo (que acabou de enviar a C5) pede as 3 horas', !$r['sucesso'] && strpos($r['mensagem'], '3 horas') !== false && $campanha($c1)['status'] === 'concluida', $r);
confere('campanha antiga (sem fluxo) não abre para edição na tela', remarketingEditavel($campanha($legada_concluida)) === false);

// ============================================================ cliques ============================================================
fase('D. Cliques nos botões (webhook)');
$sim_linhas = function (int $desde = 0) use ($sim_dir): array {
    $f = $sim_dir . '/sim.log';
    $r = [];
    foreach (is_file($f) ? file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $i => $l) {
        if ($i >= $desde) {
            $r[] = json_decode($l, true);
        }
    }
    return $r;
};
$n_sim = fn() => count(is_file($sim_dir . '/sim.log') ? file($sim_dir . '/sim.log') : []);
$clique = function (string $token, string $chat, string $data) use ($app, $aqui): string {
    $update = json_encode(['update_id' => random_int(1, 999999), 'callback_query' => ['id' => 'cb' . random_int(1, 99999), 'from' => ['id' => (int) $chat], 'data' => $data, 'message' => ['message_id' => 55, 'chat' => ['id' => (int) $chat]]]]);
    [$s] = sh(php_cmd($aqui . '/hook_rmk.php', [$token, arq($update)]));
    return $s;
};

$ed_a = ['nome' => 'D-A sem bump', 'id_fluxo' => $f1, 'segmento' => 'todos', 'periodo_tipo' => 'total', 'variantes' => [['mensagem' => 'Mensagem da campanha D-A']],
    'ofertas' => ['planos' => [
        ['id' => 'pa', 'nome' => 'Plano Mensal', 'valor' => 29.9, 'acesso' => 'mensal', 'entrega_tipo' => 'grupo', 'id_grupo' => '-1001'],
        ['id' => 'pb', 'nome' => 'Plano Vitalício', 'valor' => 99, 'acesso' => 'vitalicio', 'entrega_tipo' => 'link', 'link_entrega' => 'https://exemplo.com/vip'],
        ['id' => 'pc', 'nome' => 'Plano 45 dias', 'valor' => 10, 'acesso' => 'dias', 'dias' => 45, 'entrega_tipo' => 'nenhuma'],
        ['id' => 'pd', 'nome' => 'Plano Mensagem', 'valor' => 12, 'acesso' => 'mensal', 'entrega_tipo' => 'mensagem', 'mensagem_entrega' => 'Seu acesso: https://exemplo.com/acesso-msg'],
    ]]];
$da = (int) remarketingSalvar($pdo, $u1, $ed_a)['id'];
$ed_b = ['nome' => 'D-B com bump', 'id_fluxo' => $f1, 'segmento' => 'todos', 'periodo_tipo' => 'total', 'variantes' => [['mensagem' => 'Mensagem da campanha D-B']],
    'ofertas' => ['planos' => [['id' => 'pa', 'nome' => 'Plano Mensal', 'valor' => 29.9, 'acesso' => 'mensal', 'entrega_tipo' => 'grupo', 'id_grupo' => '-1001']],
        'order_bump' => ['ativo' => true, 'nome' => 'Extra VIP', 'valor' => 9.9, 'descricao' => 'Mais conteúdo exclusivo.', 'midia_caminho' => 'uploads/remarketing_foto_a.png', 'midia_tipo' => 'foto']]];
$db = (int) remarketingSalvar($pdo, $u1, $ed_b)['id'];
foreach ([$da => '2030-05-05 10:00', $db => '2030-05-05 20:00'] as $idc => $quando) {   // congela os bots e fica para o futuro (o cron não pega); 10 h entre elas: o mesmo fluxo não aceita duas a menos de 3 h
    $r = remarketingIniciar($pdo, $u1, $idc, $quando);
    confere("campanha D agendada ($quando)", $r['sucesso'], $r);
}
$ultima_venda = fn() => q('SELECT * FROM vendas ORDER BY id DESC LIMIT 1')[0];
$qtd_vendas = fn() => (int) q1('SELECT COUNT(*) FROM vendas');

$v_antes = $qtd_vendas();
$s = $clique('OKTOKEN1:aaaa', '1001', "rmk::$da::pa::1");
$v = $ultima_venda();
confere('clique no plano (campanha sem bump): gera UMA venda nova, "gerado"', $qtd_vendas() === $v_antes + 1 && $v['status'] === 'gerado' && (int) $v['bot_id'] === $b1 && $v['id_telegram'] === '1001', [$s, $v]);
confere('a venda leva valor, entrega no grupo, prazo de 30 dias e o fluxo do bot', (float) $v['valor'] === 29.9 && $v['id_grupo_telegram'] === '-1001' && (int) $v['dias_acesso'] === 30 && (int) $v['tempo_acesso_minutos'] === 43200 && (int) $v['id_fluxo'] === $f1, $v);
confere('o nome do produto da venda é o do plano', q1('SELECT nome_produto FROM vendas WHERE id = ?', [$v['id']]) === 'Plano Mensal', q1('SELECT nome_produto FROM vendas WHERE id = ?', [$v['id']]));
$rv = q('SELECT * FROM remarketing_vendas WHERE venda_id = ?', [$v['id']]);
confere('a venda fica ligada à campanha e à variante do clique', count($rv) === 1 && (int) $rv[0]['campanha_id'] === $da && (int) $rv[0]['variante'] === 1, $rv);
$venda_da = (int) $v['id'];
$pix = array_filter($sim_linhas(), fn($l) => isset($l['gw']) && ($l['ok'] ?? false) && ($l['valor'] ?? 0) == 29.9);
confere('a cobrança foi pedida à gateway pelo valor do plano', count($pix) >= 1, array_slice($sim_linhas(), -6));

$clique('OKTOKEN1:aaaa', '1003', "rmk::$da::pb::0");
$v = $ultima_venda();
confere('plano vitalício com entrega por link: 36500 dias, link na venda, sem grupo', (float) $v['valor'] === 99.0 && (int) $v['dias_acesso'] === 36500 && (int) $v['tempo_acesso_minutos'] === 36500 * 1440 && $v['link_entrega'] === 'https://exemplo.com/vip' && $v['id_grupo_telegram'] === null, $v);
$clique('OKTOKEN2:bbbb', '2001', "rmk::$da::pc::0");
$v = $ultima_venda();
confere('plano de 45 dias sem entrega: 64800 minutos, sem grupo nem link; o bot 2 do mesmo fluxo também vale', (float) $v['valor'] === 10.0 && (int) $v['dias_acesso'] === 45 && (int) $v['tempo_acesso_minutos'] === 45 * 1440 && $v['id_grupo_telegram'] === null && $v['link_entrega'] === null && (int) $v['bot_id'] === $b2, $v);

$clique('OKTOKEN1:aaaa', '1005', "rmk::$da::pd::0");
$v = $ultima_venda();
confere('plano com entrega por mensagem: o texto fica na venda, sem grupo e sem link', (float) $v['valor'] === 12.0 && ($v['mensagem_entrega'] ?? null) === 'Seu acesso: https://exemplo.com/acesso-msg' && $v['id_grupo_telegram'] === null && $v['link_entrega'] === null, $v);
confere('o plano com mensagem volta inteiro na campanha salva (para reabrir o assistente)', (function () use ($pdo, $da) {
    $ofertas = json_decode((string) $pdo->query("SELECT ofertas FROM remarketing_campanhas WHERE id = $da")->fetchColumn(), true);
    $pd = array_values(array_filter($ofertas['planos'] ?? [], fn($p) => ($p['id'] ?? '') === 'pd'))[0] ?? [];
    return ($pd['entrega_tipo'] ?? '') === 'mensagem' && ($pd['mensagem_entrega'] ?? '') === 'Seu acesso: https://exemplo.com/acesso-msg';
})());

// order bump da campanha: oferece ANTES do Pix
$v_antes = $qtd_vendas();
$marca = $n_sim();
$clique('OKTOKEN1:aaaa', '1002', "rmk::$db::pa::0");
$novas = $sim_linhas($marca);
$oferta = array_values(array_filter($novas, fn($l) => ($l['tg'] ?? '') === 'sendPhoto' && strpos((string) ($l['p']['caption'] ?? ''), 'Extra VIP') !== false));
confere('com order bump: o clique no plano NÃO gera venda ainda; manda a oferta (foto + texto + preço)', $qtd_vendas() === $v_antes && count($oferta) === 1 && strpos($oferta[0]['p']['caption'], 'Mais conteúdo exclusivo.') !== false && strpos($oferta[0]['p']['caption'], 'R$ 9,90') !== false, $novas);
$bots_bump = $oferta[0]['p']['reply_markup']['inline_keyboard'] ?? [];
confere('a oferta tem "Sim, quero!" e "Não, obrigado" com callbacks do order bump (<= 64 bytes)', count($bots_bump) === 2 && $bots_bump[0][0]['callback_data'] === "rmk::bp::$db::pa::0::1" && $bots_bump[1][0]['callback_data'] === "rmk::bp::$db::pa::0::0" && strlen($bots_bump[0][0]['callback_data']) <= 64 && strpos($bots_bump[0][0]['text'], '+R$ 9,90') !== false, $bots_bump);
$clique('OKTOKEN1:aaaa', '1002', "rmk::bp::$db::pa::0::1");
$v = $ultima_venda();
confere('aceitou o extra: venda de 29,90 + 9,90 = 39,80 com o nome "Plano Mensal + Extra VIP"', (float) $v['valor'] === 39.8 && q1('SELECT nome_produto FROM vendas WHERE id = ?', [$v['id']]) === 'Plano Mensal + Extra VIP' && $v['id_telegram'] === '1002', [$v, q1('SELECT nome_produto FROM vendas WHERE id = ?', [$v['id']])]);
$venda_db_com_bump = (int) $v['id'];
$clique('OKTOKEN1:aaaa', '1004', "rmk::bp::$db::pa::0::0");
$v = $ultima_venda();
confere('recusou o extra: venda só do plano, 29,90', (float) $v['valor'] === 29.9 && $v['id_telegram'] === '1004' && q1('SELECT nome_produto FROM vendas WHERE id = ?', [$v['id']]) === 'Plano Mensal');
$venda_db_sem_bump = (int) $v['id'];
confere('as duas vendas do order bump ficam ligadas à campanha D-B', (int) q1('SELECT COUNT(*) FROM remarketing_vendas WHERE campanha_id = ?', [$db]) === 2);

// cliques inválidos: nada de venda, e o lead recebe aviso
$invalidos = [
    'bot de OUTRO usuário numa campanha do usuário 1' => ['OKTOKEN6:ffff', '6001', "rmk::$da::pa::0"],
    'bot de outro fluxo do mesmo usuário (não está entre os bots da campanha)' => ['OKTOKEN4:dddd', '3002', "rmk::$da::pa::0"],
    'plano que não existe' => ['OKTOKEN1:aaaa', '1001', "rmk::$da::zz::0"],
    'campanha que não existe' => ['OKTOKEN1:aaaa', '1001', 'rmk::999999::pa::0'],
    'order bump de campanha que não existe' => ['OKTOKEN1:aaaa', '1001', 'rmk::bp::999999::pa::0::1'],
    'callback incompleto' => ['OKTOKEN1:aaaa', '1001', 'rmk::abc'],
    'callback só com o prefixo' => ['OKTOKEN1:aaaa', '1001', 'rmk::'],
    'campanha com texto no lugar do número' => ['OKTOKEN1:aaaa', '1001', "rmk::{$da}abc::pa::0"],
    'clique no plano com sufixo de order bump (1|0 sem "bp")' => ['OKTOKEN1:aaaa', '1001', "rmk::$da::pa::0::1"],
    'resposta de order bump sem o 1|0' => ['OKTOKEN1:aaaa', '1001', "rmk::bp::$db::pa::0"],
    'resposta de order bump com valor fora de 1|0' => ['OKTOKEN1:aaaa', '1001', "rmk::bp::$db::pa::0::2"],
    'id de plano com maiúscula/símbolo' => ['OKTOKEN1:aaaa', '1001', "rmk::$da::PA::0"],
    'callback com texto a mais no fim' => ['OKTOKEN1:aaaa', '1001', "rmk::$da::pa::0::x"],
];
foreach ($invalidos as $nome => [$token, $chat, $data]) {
    $v_antes = $qtd_vendas();
    $marca = $n_sim();
    $clique($token, $chat, $data);
    $avisos = array_filter($sim_linhas($marca), fn($l) => ($l['tg'] ?? '') === 'sendMessage' && ($l['p']['text'] ?? '') === 'Essa oferta não está mais disponível.');
    confere("clique inválido ($nome): sem venda e com aviso ao lead", $qtd_vendas() === $v_antes && count($avisos) === 1);
}
$rv_antes = (int) q1('SELECT COUNT(*) FROM remarketing_vendas');
$clique('OKTOKEN1:aaaa', '1001', "rmk::$da::pa::9");
confere('variante absurda no callback é limitada ao teto (A, B ou C)', (int) q1('SELECT MAX(variante) FROM remarketing_vendas') <= 2 && (int) q1('SELECT COUNT(*) FROM remarketing_vendas') === $rv_antes + 1);

// depois do pagamento: o order bump da campanha substitui o do fluxo
$pdo->prepare("UPDATE vendas SET status = 'pago', pago_em = NOW() WHERE id IN (?, ?, ?)")->execute([$venda_da, $venda_db_com_bump, $venda_db_sem_bump]);
$marca = $n_sim();
[$s] = sh(php_cmd($aqui . '/pos_pagamento.php', [$venda_da]));
$ofertou = array_filter($sim_linhas($marca), fn($l) => strpos(json_encode($l['p']['reply_markup'] ?? ''), 'basico::pos::bp') !== false);
confere('venda de campanha SEM order bump próprio: o order bump do fluxo é oferecido depois de pagar, como sempre', count($ofertou) === 1, [$s, array_slice($sim_linhas($marca), -3)]);
$marca = $n_sim();
sh(php_cmd($aqui . '/pos_pagamento.php', [$venda_db_com_bump]));
$ofertou = array_filter($sim_linhas($marca), fn($l) => strpos(json_encode($l['p']['reply_markup'] ?? ''), 'basico::pos::bp') !== false);
confere('venda de campanha COM order bump próprio: o do fluxo NÃO é oferecido (o da campanha substitui)', count($ofertou) === 0, array_slice($sim_linhas($marca), -3));
$marca = $n_sim();
sh(php_cmd($aqui . '/pos_pagamento.php', [$venda_db_sem_bump]));
confere('o mesmo vale para a venda em que o lead recusou o extra (a campanha tem order bump)', count(array_filter($sim_linhas($marca), fn($l) => strpos(json_encode($l['p']['reply_markup'] ?? ''), 'basico::pos::bp') !== false)) === 0);
$v_solta = ins($pdo, 'vendas', ['id_telegram' => '1005', 'bot_id' => $b1, 'valor' => 29.9, 'status' => 'pago', 'transacao_id' => 'T-SOLTA', 'id_fluxo' => $f1]);
$marca = $n_sim();
sh(php_cmd($aqui . '/pos_pagamento.php', [$v_solta]));
confere('venda comum (fora de campanha): o order bump do fluxo segue sendo oferecido', count(array_filter($sim_linhas($marca), fn($l) => strpos(json_encode($l['p']['reply_markup'] ?? ''), 'basico::pos::bp') !== false)) === 1);

// ============================================================ lista, CSV, API, detalhes ============================================================
fase('E. Lista, CSV, API e detalhes');
$lst = remarketingListar($pdo, $u1);
$por_nome = array_column($lst['campanhas'], null, 'nome');
confere('lista: traz as campanhas do usuário 1 e nenhuma do usuário 2', !isset($por_nome['Do outro']) && count($lst['campanhas']) === (int) q1('SELECT COUNT(*) FROM remarketing_campanhas WHERE id_usuario = ?', [$u1]), count($lst['campanhas']));
$c1_item = array_values(array_filter($lst['campanhas'], fn($c) => $c['id'] === $c1))[0];
confere('lista: C1 mostra 9 enviadas, 1 falha, total 10, "Concluída", agrupada no fluxo', $c1_item['enviadas'] === 9 && $c1_item['falhas'] === 1 && $c1_item['total'] === 10 && $c1_item['status_rotulo'] === 'Concluída' && $c1_item['grupo'] === 'f' . $f1 && $c1_item['grupo_nome'] === 'Funil A' && $c1_item['grupo_bots'] === 2 && $c1_item['em_envio'] === false && $c1_item['editavel'] === false, $c1_item);
$antiga_item = array_values(array_filter($lst['campanhas'], fn($c) => $c['id'] === $legada_concluida))[0];
confere('lista: campanha antiga aparece agrupada pelo bot, "modelo antigo" e não editável', $antiga_item['grupo'] === 'b' . $b1 && $antiga_item['grupo_antigo'] === true && $antiga_item['grupo_nome'] === 'Bot Bot Um' && $antiga_item['editavel'] === false && $antiga_item['tem_log'] === true, $antiga_item);
$rasc_item = array_values(array_filter($lst['campanhas'], fn($c) => $c['id'] === $c_rasc))[0];
confere('lista: rascunho é iniciável e editável, sem registro para baixar', $rasc_item['iniciavel'] === true && $rasc_item['editavel'] === true && $rasc_item['tem_log'] === false && $rasc_item['status_rotulo'] === 'Rascunho');
$fut_item = array_values(array_filter($lst['campanhas'], fn($c) => $c['id'] === $c_fut))[0];
confere('lista: agendada para o futuro NÃO conta como "em envio"', $fut_item['em_envio'] === false && $fut_item['status_rotulo'] === 'Agendada' && $fut_item['editavel'] === true);
$da_item = array_values(array_filter($lst['campanhas'], fn($c) => $c['id'] === $da))[0];
confere('lista: a receita vem das vendas PAGAS ligadas à campanha (D-A: 29,90 + 99,00... só a paga)', abs($da_item['receita'] - 29.9) < 0.001, $da_item['receita']);
$db_item = array_values(array_filter($lst['campanhas'], fn($c) => $c['id'] === $db))[0];
confere('lista: receita da D-B = 39,80 + 29,90 (as duas pagas)', abs($db_item['receita'] - 69.7) < 0.001, $db_item['receita']);
confere('lista: resumo soma as entregues e a receita de todas as campanhas', $lst['resumo']['total'] === count($lst['campanhas']) && $lst['resumo']['enviadas'] === array_sum(array_column($lst['campanhas'], 'enviadas')) && abs($lst['resumo']['receita'] - array_sum(array_column($lst['campanhas'], 'receita'))) < 0.001 && $lst['resumo']['ativas'] === 0, $lst['resumo']);

// API pela tela (remarketing.php) como o navegador chamaria
$api = function (string $metodo, int $uid, array $query = [], ?array $corpo = null, bool $csrf = true) use ($aqui): array {
    $args = [$metodo, $uid, arq(json_encode($query)), $corpo === null ? '' : arq(json_encode($corpo))];
    if (!$csrf) {
        $args[] = 'sem-csrf';
    }
    [$s] = sh(php_cmd($aqui . '/api_cli.php', $args));
    $http = preg_match('/#HTTP:(\d+)\s*$/', $s, $m) ? (int) $m[1] : -1;
    $corpo_resp = trim(preg_replace('/\n?#HTTP:\d+\s*$/', '', $s));
    return ['http' => $http, 'json' => json_decode($corpo_resp, true), 'cru' => $corpo_resp];
};
$r = $api('GET', $u1, ['action' => 'listar']);
confere('API listar: 200 e as campanhas', $r['http'] === 200 && ($r['json']['sucesso'] ?? false) && count($r['json']['campanhas']) === count($lst['campanhas']), $r['cru']);
$r = $api('GET', $u2, ['action' => 'listar']);
confere('API listar como usuário 2: só as dele', count($r['json']['campanhas'] ?? []) === 1, $r['cru']);
$r = $api('GET', $u1, ['action' => 'contexto']);
confere('API contexto: fluxos e preço mínimo', $r['http'] === 200 && count($r['json']['fluxos']) === 6 && $r['json']['minimo_pix'] >= 1.5);
$r = $api('GET', $u1, ['action' => 'obter', 'id' => $c_rasc]);
confere('API obter: o rascunho como o assistente edita', $r['http'] === 200 && $r['json']['campanha']['editavel'] === true && $r['json']['campanha']['id'] === $c_rasc);
$r = $api('GET', $u2, ['action' => 'obter', 'id' => $c_rasc]);
confere('API obter campanha de OUTRO usuário: 404', $r['http'] === 404 && ($r['json']['sucesso'] ?? true) === false, $r['cru']);
$r = $api('GET', $u1, ['action' => 'contar', 'id_fluxo' => $f1, 'segmento' => 'pix_gerado', 'periodo_tipo' => 'total']);
confere('API contar: alcance do público "pix gerado" no fluxo A (igual à conta direta; os cliques da fase D geraram Pix também para 1001 e 2001), com 2 bots', $r['http'] === 200 && $r['json']['total'] === remarketingContarPublico($pdo, [$b1, $b2], 'pix_gerado', null, null) && $r['json']['total'] === 4 && $r['json']['bots'] === 2, $r['cru']);
$r = $api('GET', $u1, ['action' => 'contar', 'id_fluxo' => $f1, 'segmento' => 'todos', 'periodo_tipo' => 'hoje']);
confere('API contar com período "hoje" = 2', $r['json']['total'] === 2, $r['cru']);
$r = $api('GET', $u2, ['action' => 'contar', 'id_fluxo' => $f1, 'segmento' => 'todos', 'periodo_tipo' => 'total']);
confere('API contar o fluxo de OUTRO usuário: 0 bots e 0 leads (nada vaza)', $r['http'] === 200 && $r['json']['total'] === 0 && $r['json']['bots'] === 0, $r['cru']);
$r = $api('GET', $u1, ['action' => 'contar', 'id_fluxo' => $f1, 'segmento' => 'x', 'periodo_tipo' => 'total']);
confere('API contar com segmento inválido: 422', $r['http'] === 422, $r['cru']);
$r = $api('GET', $u1, ['action' => 'contar', 'id_fluxo' => $f1, 'segmento' => 'todos', 'periodo_tipo' => ['a']]);
confere('API contar com período em formato de lista (entrada hostil): 422, sem erro do PHP', $r['http'] === 422 && strpos($r['cru'], 'Warning') === false, $r['cru']);
$r = $api('GET', $u1, ['action' => 'nao_existe']);
confere('API ação desconhecida: 400', $r['http'] === 400);

$novo = ['acao' => 'salvar', 'nome' => 'Criada pela API', 'id_fluxo' => $f1, 'segmento' => 'comprou', 'periodo_tipo' => 'total', 'variantes' => [['mensagem' => 'Mensagem criada pela API']], 'ofertas' => []];
$r = $api('POST', $u1, [], $novo, false);
confere('API salvar SEM token CSRF: 403 e nada é criado', $r['http'] === 403 && !(int) q1("SELECT COUNT(*) FROM remarketing_campanhas WHERE nome = 'Criada pela API'"), $r['cru']);
$r = $api('POST', $u1, [], $novo);
confere('API salvar com CSRF: 200 e o rascunho existe', $r['http'] === 200 && ($r['json']['sucesso'] ?? false) && (int) q1("SELECT COUNT(*) FROM remarketing_campanhas WHERE nome = 'Criada pela API' AND status = 'rascunho'") === 1, $r['cru']);
$id_api = (int) ($r['json']['id'] ?? 0);
$r = $api('POST', $u1, [], ['acao' => 'salvar', 'nome' => ''] + $novo);
confere('API salvar com erro de validação: 422 e a mensagem', $r['http'] === 422 && ($r['json']['mensagem'] ?? '') === 'Dê um nome à campanha.', $r['cru']);
$r = $api('POST', $u2, [], ['acao' => 'iniciar', 'id' => $id_api]);
confere('API iniciar a campanha de OUTRO usuário: 422 "não encontrada"', $r['http'] === 422 && $r['json']['mensagem'] === 'Campanha não encontrada.', $r['cru']);
$r = $api('POST', $u1, [], ['acao' => 'agendar', 'id' => $id_api, 'agendado_em' => '2031-03-03 08:15']);
confere('API agendar', $r['http'] === 200 && q1('SELECT agendado_em FROM remarketing_campanhas WHERE id = ?', [$id_api]) === '2031-03-03 08:15:00', $r['cru']);
$r = $api('POST', $u1, [], ['acao' => 'agendar', 'id' => $id_api, 'agendado_em' => '']);
confere('API agendar sem data: 422', $r['http'] === 422 && strpos($r['json']['mensagem'], 'Informe a data') !== false, $r['cru']);
$r = $api('POST', $u2, [], ['acao' => 'excluir', 'id' => $id_api]);
confere('API excluir a campanha de OUTRO usuário: 404 e ela continua lá', $r['http'] === 404 && (int) q1('SELECT COUNT(*) FROM remarketing_campanhas WHERE id = ?', [$id_api]) === 1, $r['cru']);
$r = $api('POST', $u1, [], ['acao' => 'excluir', 'id' => $id_api]);
confere('API excluir a própria', $r['http'] === 200 && (int) q1('SELECT COUNT(*) FROM remarketing_campanhas WHERE id = ?', [$id_api]) === 0);
$r = $api('POST', $u1, [], ['acao' => 'apagar_tudo']);
confere('API ação POST desconhecida: 400', $r['http'] === 400);

// CSV
$r = $api('GET', $u1, ['action' => 'exportar', 'id' => $c1]);
$linhas_csv = array_map(fn($l) => str_getcsv($l, ';', '"', ''), preg_split('/\r?\n/', trim(substr($r['cru'], 3))));   // sem o BOM (ele cola na 1ª aspa e o str_getcsv não a reconheceria)
confere('CSV dos envios: BOM, cabeçalho e uma linha por envio (10)', strpos($r['cru'], "\xEF\xBB\xBF") === 0 && $linhas_csv[0][0] === 'ID Telegram' && $linhas_csv[0][2] === 'Variante' && count($linhas_csv) === 11, [count($linhas_csv), mb_substr($r['cru'], 0, 160)]);
$csv_por_chat = array_column($linhas_csv, null, 0);
confere('CSV: variante em letra e o motivo da falha do bloqueio', ($csv_por_chat['1002'][1] ?? '') === 'sucesso' && ($csv_por_chat['1002'][2] ?? '') === 'B' && ($csv_por_chat['70403'][1] ?? '') === 'falha' && ($csv_por_chat['70403'][2] ?? '') === 'A' && strpos($csv_por_chat['70403'][4] ?? '', 'blocked by the user') !== false, array_slice($linhas_csv, 0, 4));
$r = $api('GET', $u2, ['action' => 'exportar', 'id' => $c1]);
confere('CSV da campanha de OUTRO usuário: 404 sem dados', $r['http'] === 404 && strpos($r['cru'], '70403') === false, $r['cru']);

// detalhes
function pagina(string $arquivo, int $uid, array $query, string $saida): string
{
    global $aqui;
    [$s] = sh(php_cmd($aqui . '/render_pagina.php', [$arquivo, $uid, arq(json_encode($query)), $saida], false));
    return $s;
}
$saida_html = 'C:/tmp/rm_int/detalhes_c1.html';
$s = pagina('remarketing_detalhes.php', $u1, ['campanha' => $c1], $saida_html);
$html = is_file($saida_html) ? (string) file_get_contents($saida_html) : '';
confere('detalhes renderiza sem erro nem aviso do PHP', $html !== '' && !preg_match('/(Warning|Notice|Deprecated|Fatal error|Parse error)/', $html . $s), mb_substr($s, 0, 500));
confere('detalhes: título, KPIs (10 destinatários, 9 enviadas, 1 falha), fluxo e público', strpos($html, 'C1 agendada e editada') !== false && preg_match('/Destinatários<\/span><\/div><div class="a-kv">10</', $html) && preg_match('/Enviadas<\/span><\/div><div class="a-kv">9</', $html) && strpos($html, 'Funil A') !== false && strpos($html, 'Todos os leads') !== false, '');
confere('detalhes: as duas variantes com o texto de cada uma e as contagens', strpos($html, 'Variante A') !== false && strpos($html, 'Variante B') !== false && strpos($html, 'Versão B para {nome}') !== false && strpos($html, 'com foto') !== false);
confere('detalhes: a oferta (planos, redirect) e o registro com 10 linhas e a coluna Variante', strpos($html, 'Plano Vitalício') !== false && strpos($html, 'https://t.me/+abc') !== false && substr_count($html, '<td class="mono">') >= 10 && strpos($html, '<th>Variante</th>') !== false, '');
confere('detalhes: botão de baixar os envios e sem botão de configurar (já enviada)', strpos($html, 'remarketing?action=exportar&amp;id=' . $c1) !== false && strpos($html, 'Configurar campanha') === false);
$s = pagina('remarketing_detalhes.php', $u1, ['campanha' => $da], 'C:/tmp/rm_int/detalhes_da.html');
$html = (string) file_get_contents('C:/tmp/rm_int/detalhes_da.html');
confere('detalhes da D-A: receita de 1 venda paga = R$ 29,90 e a oferta com os 3 planos', strpos($html, 'Receita (1 venda)') !== false && strpos($html, 'R$ 29,90') !== false && strpos($html, 'Plano 45 dias') !== false && strpos($html, 'acesso 45 dias') !== false && strpos($html, 'acesso vitalício') !== false, '');
$s = pagina('remarketing_detalhes.php', $u1, ['campanha' => $c_rasc], 'C:/tmp/rm_int/detalhes_outros.html');
$html = (string) file_get_contents('C:/tmp/rm_int/detalhes_outros.html');
confere('detalhes do rascunho: botão "Configurar campanha" e situação Rascunho', strpos($html, 'remarketing_nova?id=' . $c_rasc) !== false && strpos($html, 'Rascunho') !== false);
$s = pagina('remarketing_detalhes.php', $u1, ['campanha' => $legada_concluida], 'C:/tmp/rm_int/detalhes_outros.html');
$html = (string) file_get_contents('C:/tmp/rm_int/detalhes_outros.html');
confere('detalhes da campanha antiga: mostra o bot, sem aviso do PHP', strpos($html, 'Bot Um') !== false && !preg_match('/(Warning|Notice|Fatal)/', $html) && strpos($html, 'Antiga concluída') !== false, mb_substr($s, 0, 300));
$s = pagina('remarketing_detalhes.php', $u2, ['campanha' => $c1], 'C:/tmp/rm_int/detalhes_outros.html');
$html = (string) file_get_contents('C:/tmp/rm_int/detalhes_outros.html');
confere('detalhes da campanha de OUTRO usuário: "não encontrada" e nenhum dado dela', strpos($html, 'Campanha não encontrada') !== false && strpos($html, '70403') === false && strpos($html, 'Funil A') === false);
$s = pagina('remarketing_detalhes.php', $u1, ['campanha' => 99999999], 'C:/tmp/rm_int/detalhes_outros.html');
confere('detalhes de campanha inexistente: "não encontrada"', strpos((string) file_get_contents('C:/tmp/rm_int/detalhes_outros.html'), 'Campanha não encontrada') !== false);

// páginas com a barra lateral e o tema de verdade (sem trocar os includes)
function pagina_real(string $arquivo, int $uid, array $query, string $saida): string
{
    global $aqui;
    sh(php_cmd($aqui . '/render_pagina.php', [$arquivo, $uid, arq(json_encode($query)), $saida, 'real'], false));
    return is_file($saida) ? (string) file_get_contents($saida) : '';
}
$limpo = fn(string $h) => $h !== '' && !preg_match('/(Warning|Notice|Deprecated|Fatal error|Parse error|Stack trace)/', $h);
$h = pagina_real('remarketing.php', $u1, [], 'C:/tmp/rm_int/real_lista.html');
confere('página Remarketing (lista) com a casca de verdade: sem erro do PHP', $limpo($h), mb_substr($h, 0, 300));
confere('lista: Layout A ligado, CSS e JS novos, contêiner da lista, botão de nova campanha para a tela própria', strpos($h, "classList.add('layout-a')") !== false && strpos($h, 'assets/css/remarketing.css') !== false && strpos($h, 'assets/remarketing.js') !== false && strpos($h, 'id="rm-lista"') !== false && strpos($h, 'href="remarketing_nova"') !== false);
confere('lista: a barra lateral existe e o item Remarketing aparece marcado', strpos($h, 'a-rail') !== false && preg_match('/href="remarketing"[^>]*class="[^"]*ativo|class="[^"]*ativo[^"]*"[^>]*href="remarketing"/', $h) === 1);
confere('lista: o token CSRF vai para o JS (a página não monta formulário antigo)', strpos($h, 'window.CSRF_TOKEN') !== false && strpos($h, 'modal-nova-campanha') === false && strpos($h, 'form-campanha') === false);
$h = pagina_real('remarketing_nova.php', $u1, [], 'C:/tmp/rm_int/real_nova.html');
confere('página Nova campanha com o tema de verdade: sem erro do PHP', $limpo($h), mb_substr($h, 0, 300));
confere('nova: Layout A (só as cores/fonte), CSS e JS do assistente e token CSRF', strpos($h, "classList.add('layout-a')") !== false && strpos($h, 'assets/css/layout_a.css') !== false && strpos($h, 'assets/remarketing_nova.js') !== false && preg_match('/window\.CSRF_TOKEN = "[0-9a-f]{20,}"/', $h) === 1);
confere('nova: tela cheia, sem a barra lateral do painel nem o menu do celular', strpos($h, 'a-rail') === false && strpos($h, 'a-tabbar') === false && strpos($h, 'conteudo-principal') === false && strpos($h, 'id="rmw"') !== false && strpos($h, 'data-id="0"') !== false);
confere('nova: as 5 etapas do mapa e nenhuma menção a "Canal de Suporte (CDN)"', substr_count($h, 'class="rmw-passo"') === 5 && stripos($h, 'CDN') === false && stripos($h, 'Canal de Suporte') === false);
$h = pagina_real('remarketing_nova.php', $u1, ['id' => $c_rasc], 'C:/tmp/rm_int/real_nova_edicao.html');
confere('nova em modo edição: o id da campanha vai no contêiner e o título muda', strpos($h, 'data-id="' . $c_rasc . '"') !== false && strpos($h, 'Editar campanha') !== false);
$h = pagina_real('remarketing_detalhes.php', $u1, ['campanha' => $c1], 'C:/tmp/rm_int/real_detalhes.html');
confere('detalhes com a casca de verdade: sem erro do PHP e com a barra lateral', $limpo($h) && strpos($h, 'a-rail') !== false, mb_substr($h, 0, 300));

// ============================================================ reenvio, intervalo de 3 horas e recorrência ============================================================
fase('F. Reenvio, intervalo de 3 horas por fluxo e recorrência');
$item_lista = function (int $uid, int $id) use ($pdo): ?array {
    foreach (remarketingListar($pdo, $uid)['campanhas'] as $c) {
        if ($c['id'] === $id) {
            return $c;
        }
    }
    return null;
};
$nova = fn(int $fluxo, string $nome, string $msg) => (int) remarketingSalvar($pdo, $u1, ['nome' => $nome, 'id_fluxo' => $fluxo, 'segmento' => 'todos', 'periodo_tipo' => 'total', 'variantes' => [['mensagem' => $msg]], 'ofertas' => []])['id'];
$enviados_para = fn(array $log) => array_values(array_unique(array_map('strval', array_column($log, 'chat_id'))));

// --- F1: o caso do cliente (print do WhatsApp): campanha ANTIGA (só bot_id), concluída, "TESTE", 1 enviada; ele quer reenviar depois de umas 3 horas ---
$bL = $bot($u1, 'OKTOKEN9:lima', 'Bot Lima', null);
$lead($bL, '9001', 'Lead Um', 0);
$lead($bL, '9002', 'Lead Dois', 0);
$teste = ins($raw, 'remarketing_campanhas', ['id_usuario' => $u1, 'bot_id' => $bL, 'nome' => 'TESTE', 'audiencia' => 'nao_comprou', 'mensagem' => 'Mensagem de teste do Lima', 'agendado_em' => date('Y-m-d H:i:s', time() - 3600), 'status' => 'concluida', 'total_destinatarios' => 1, 'enviados' => 1, 'entregues' => 1, 'falhas' => 0, 'processado_em' => date('Y-m-d H:i:s', time() - 3600)]);
ins($raw, 'remarketing_envios', ['campanha_id' => $teste, 'id_telegram' => '9001', 'resultado' => 'sucesso', 'variante' => 0]);
$it = $item_lista($u1, $teste);
confere('F1 lista: a campanha concluída é "reenviável" e "agendável" (o botão da tela passa a ser Reenviar)', $it['reenviavel'] === true && $it['agendavel'] === true && $it['status'] === 'concluida', $it);
confere('F1 lista: mas o fluxo (aqui, o bot dela) enviou há 1 h: bloqueio por intervalo, com a hora em que libera (~2 h adiante)', $it['bloqueio'] !== null && $it['bloqueio']['motivo'] === 'intervalo' && $it['bloqueio']['libera_em'] !== '' && strpos($it['bloqueio']['mensagem'], '3 horas') !== false, $it['bloqueio']);
$r = remarketingIniciar($pdo, $u1, $teste);
confere('F1 reenviar 1 h depois: recusado com o motivo, e a campanha continua concluída, com os números dela', !$r['sucesso'] && strpos($r['mensagem'], '3 horas') !== false && $campanha($teste)['status'] === 'concluida' && (int) $campanha($teste)['entregues'] === 1, $r);
$r = remarketingIniciar($pdo, $u1, $teste, date('Y-m-d H:i', time() + 3600));
confere('F1 agendar o reenvio para daqui a 1 h (antes de liberar): também recusado', !$r['sucesso'] && strpos($r['mensagem'], '3 horas') !== false, $r);
envelhecer();   // passaram 4 horas
$it = $item_lista($u1, $teste);
confere('F1 passadas as 3 horas o bloqueio some', $it['bloqueio'] === null, $it);
$r = remarketingIniciar($pdo, $u1, $teste);
$l = $campanha($teste);
confere('F1 reenviar: "pendente" para já, com a mensagem de reenvio, o bot da campanha antiga congelado e os números da 1ª execução intactos até o cron começar', $r['sucesso'] && strpos($r['mensagem'], 'reenviada') !== false && $l['status'] === 'pendente' && $l['bots_ids'] === (string) $bL && (int) $l['entregues'] === 1 && (int) $l['enviados'] === 1 && $l['processado_em'] !== null, [$r, $l]);
$it = $item_lista($u1, $teste);
confere('F1 lista: reenvio agendado para já aparece como "em envio" (o cron pega no próximo minuto), sem botão de reenviar', $it['em_envio'] === true && $it['status'] === 'pendente' && $it['reenviavel'] === false && $it['editavel'] === false, $it);
tg_limpar();
cron();
$log = tg();
$l = $campanha($teste);
confere('F1 cron: os 2 leads do bot recebem de novo (inclusive o que já tinha recebido)', $enviados_para($log) === ['9001', '9002'] && count($log) === 2 && $log[0]['token'] === 'OKTOKEN9:lima', $enviados_para($log));
confere('F1 depois do reenvio: concluída; contadores da execução NOVA (2 enviadas), a anterior guardada (1) e 2 execuções', $l['status'] === 'concluida' && (int) $l['total_destinatarios'] === 2 && (int) $l['enviados'] === 2 && (int) $l['entregues'] === 2 && (int) $l['acum_entregues'] === 1 && (int) $l['acum_falhas'] === 0 && (int) $l['execucoes'] === 2 && $l['processado_em'] !== null && strtotime($l['processado_em']) > time() - 120, $l);
$it = $item_lista($u1, $teste);
confere('F1 lista: Enviadas soma todas as execuções (3), 2 execuções, concluída e de novo bloqueada por 3 h', $it['enviadas'] === 3 && $it['execucoes'] === 2 && $it['status'] === 'concluida' && $it['bloqueio']['motivo'] === 'intervalo', $it);
confere('F1 o registro de envios guarda as duas execuções (1 + 2)', (int) q1('SELECT COUNT(*) FROM remarketing_envios WHERE campanha_id = ?', [$teste]) === 3);
$r = remarketingIniciar($pdo, $u1, $teste);
confere('F1 reenviar de novo logo em seguida: recusado (as 3 horas contam do fim do reenvio)', !$r['sucesso'] && strpos($r['mensagem'], '3 horas') !== false, $r);
$resumo_f1 = remarketingListar($pdo, $u1)['resumo'];
confere('F1 resumo da lista soma as entregues de todas as execuções', $resumo_f1['enviadas'] === array_sum(array_column(remarketingListar($pdo, $u1)['campanhas'], 'enviadas')), $resumo_f1);

// --- F2: mesmo fluxo espera 3 h; outro fluxo não ---
$fA = $fluxo($u1, 'Funil F-A', 'basico', ['ativo' => true, 'planos' => []]);
$fB = $fluxo($u1, 'Funil F-B', 'basico', ['ativo' => true, 'planos' => []]);
$bA = $bot($u1, 'OKTOKEN10:fa', 'Bot F-A', $fA);
$bB = $bot($u1, 'OKTOKEN11:fb', 'Bot F-B', $fB);
foreach (['9101', '9102', '9103'] as $id) { $lead($bA, $id, 'A' . $id, 0); }
foreach (['9201', '9202'] as $id) { $lead($bB, $id, 'B' . $id, 0); }
$cA1 = $nova($fA, 'Remarketing A1', 'Mensagem da campanha A1 do fluxo A');
$cA2 = $nova($fA, 'Remarketing A2', 'Mensagem da campanha A2 do fluxo A');
$cB1 = $nova($fB, 'Remarketing B1', 'Mensagem da campanha B1 do fluxo B');
confere('F2 rascunhos dos dois fluxos sem bloqueio nenhum', $item_lista($u1, $cA2)['bloqueio'] === null && $item_lista($u1, $cB1)['bloqueio'] === null);
confere('F2 inicia A1', remarketingIniciar($pdo, $u1, $cA1)['sucesso']);
$it = $item_lista($u1, $cA2);
confere('F2 com A1 pendente (vai sair já), A2 do MESMO fluxo não pode começar agora: "agendada perto"', $it['bloqueio'] !== null && $it['bloqueio']['motivo'] === 'agendada_perto' && strpos($it['bloqueio']['mensagem'], 'Remarketing A1') !== false, $it['bloqueio']);
confere('F2 mas B1, de OUTRO fluxo, está livre', $item_lista($u1, $cB1)['bloqueio'] === null);
tg_limpar();
cron();
confere('F2 cron: A1 enviou aos 3 leads do fluxo A (e só a eles)', $enviados_para(tg()) === ['9101', '9102', '9103'] && $campanha($cA1)['status'] === 'concluida', $enviados_para(tg()));
$r = remarketingIniciar($pdo, $u1, $cA2);
confere('F2 A2 (mesmo fluxo) logo depois: recusada, com o nome da A1 e as 3 horas; continua rascunho', !$r['sucesso'] && strpos($r['mensagem'], 'Remarketing A1') !== false && strpos($r['mensagem'], '3 horas') !== false && $campanha($cA2)['status'] === 'rascunho', $r);
$it = $item_lista($u1, $cA2);
confere('F2 lista: A2 mostra "intervalo" e a hora em que libera', $it['bloqueio']['motivo'] === 'intervalo' && $it['bloqueio']['libera_em'] !== '', $it['bloqueio']);
$r = remarketingIniciar($pdo, $u1, $cA2, date('Y-m-d H:i', time() + 2 * 3600));
confere('F2 agendar A2 para daqui a 2 h: recusado; para daqui a 4 h: aceito', !$r['sucesso'] && remarketingIniciar($pdo, $u1, $cA2, date('Y-m-d H:i', time() + 4 * 3600))['sucesso'] && $campanha($cA2)['status'] === 'pendente', $r);
confere('F2 cancelar o agendamento da A2 (nunca saiu) volta a rascunho', remarketingParar($pdo, $u1, $cA2)['sucesso'] && $campanha($cA2)['status'] === 'rascunho' && $campanha($cA2)['recorrencia'] === null);
confere('F2 inicia B1 (outro fluxo) logo depois de A1: pode', remarketingIniciar($pdo, $u1, $cB1)['sucesso']);
tg_limpar();
cron();
confere('F2 cron: B1 enviou só aos leads do fluxo B; A2 não saiu', $enviados_para(tg()) === ['9201', '9202'] && $campanha($cB1)['status'] === 'concluida' && $campanha($cA2)['status'] === 'rascunho', $enviados_para(tg()));
envelhecer();
confere('F2 passadas as 3 horas, A2 inicia', remarketingIniciar($pdo, $u1, $cA2)['sucesso']);
tg_limpar();
cron();
confere('F2 cron: A2 enviou aos 3 leads do fluxo A', $enviados_para(tg()) === ['9101', '9102', '9103'] && $campanha($cA2)['status'] === 'concluida', $enviados_para(tg()));

// --- F3: a trava do cron (duas campanhas do mesmo fluxo vencidas na mesma hora, sem passar pela tela) ---
$fC = $fluxo($u1, 'Funil F-C', 'basico', ['ativo' => true, 'planos' => []]);
$bC = $bot($u1, 'OKTOKEN12:fc', 'Bot F-C', $fC);
foreach (['9301', '9302'] as $id) { $lead($bC, $id, 'C' . $id, 0); }
$cC1 = $nova($fC, 'Remarketing C1', 'Mensagem da campanha C1 do fluxo C');
$cC2 = $nova($fC, 'Remarketing C2', 'Mensagem da campanha C2 do fluxo C');
$pdo->prepare("UPDATE remarketing_campanhas SET status = 'pendente', agendado_em = NOW() - INTERVAL 5 MINUTE WHERE id = ?")->execute([$cC1]);
$pdo->prepare("UPDATE remarketing_campanhas SET status = 'pendente', agendado_em = NOW() - INTERVAL 3 MINUTE WHERE id = ?")->execute([$cC2]);
tg_limpar();
cron();
confere('F3 as duas vencidas no mesmo minuto: só a mais antiga (C1) sai; a C2 fica pendente, sem nenhum envio', $campanha($cC1)['status'] === 'concluida' && $campanha($cC2)['status'] === 'pendente' && (int) $campanha($cC2)['enviados'] === 0 && count(tg()) === 2 && (int) q1('SELECT COUNT(*) FROM remarketing_envios WHERE campanha_id = ?', [$cC2]) === 0, [$campanha($cC1)['status'], $campanha($cC2)['status'], count(tg())]);
tg_limpar();
$s = cron();
confere('F3 o cron seguinte também segura a C2 (o fluxo enviou há instantes): nada é enviado', count(tg()) === 0 && $campanha($cC2)['status'] === 'pendente' && strpos($s, 'Nenhuma campanha pendente') !== false, $s);
$it = $item_lista($u1, $cC2);
confere('F3 lista: a C2 aparece como Agendada ("retida"), com a hora em que sai, e NÃO conta como ativa', $it['retida'] === true && $it['em_envio'] === false && $it['status_rotulo'] === 'Agendada' && $it['libera_em'] !== '' && remarketingListar($pdo, $u1)['resumo']['ativas'] === 0, $it);
envelhecer();
tg_limpar();
cron();
confere('F3 passadas as 3 horas a C2 sai sozinha e conclui', $campanha($cC2)['status'] === 'concluida' && $enviados_para(tg()) === ['9301', '9302'], [$campanha($cC2)['status'], $enviados_para(tg())]);

// --- F4: recorrência ---
$fD = $fluxo($u1, 'Funil F-D', 'basico', ['ativo' => true, 'planos' => []]);
$bD = $bot($u1, 'OKTOKEN13:fd', 'Bot F-D', $fD);
foreach (['9401', '9402'] as $id) { $lead($bD, $id, 'D' . $id, 0); }
$cD = $nova($fD, 'Recorrente diária', 'Mensagem da campanha que se repete todo dia');
$r = remarketingIniciar($pdo, $u1, $cD, null, ['tipo' => 'diario', 'hora' => '21:00']);
$l = $campanha($cD);
$esperado = remarketingProximaExecucao(['tipo' => 'diario', 'hora' => '21:00'], time());
confere('F4 recorrência diária ativada: pendente, JSON gravado e o 1º envio na próxima ocorrência de 21:00', $r['sucesso'] && strpos($r['mensagem'], 'Recorrência ativada: Todo dia às 21:00') !== false && $l['status'] === 'pendente' && json_decode($l['recorrencia'], true) === ['tipo' => 'diario', 'hora' => '21:00'] && strtotime($l['agendado_em']) === $esperado, [$r, $l['agendado_em'], date('Y-m-d H:i', $esperado)]);
$it = $item_lista($u1, $cD);
confere('F4 lista: recorrente, rótulo, Agendada e sem ser "em envio"', $it['recorrente'] === true && $it['recorrencia_rotulo'] === 'Todo dia às 21:00' && $it['status'] === 'pendente' && $it['em_envio'] === false && $it['recorrencia']['tipo'] === 'diario' && $it['execucoes'] === 0, $it);
confere('F4 campanha agendada que nunca saiu continua editável', $it['editavel'] === true);
$pdo->prepare("UPDATE remarketing_campanhas SET agendado_em = NOW() - INTERVAL 1 MINUTE WHERE id = ?")->execute([$cD]);
tg_limpar();
$s = cron();
$l = $campanha($cD);
$proxima = remarketingProximaExecucao(['tipo' => 'diario', 'hora' => '21:00'], time() + 180 * 60);
confere('F4 cron: a 1ª execução envia aos 2 leads', $enviados_para(tg()) === ['9401', '9402'], $enviados_para(tg()));
confere('F4 ao terminar, volta a "pendente" para a próxima ocorrência (21:00, nunca antes de 3 h depois do fim) e a recorrência continua', $l['status'] === 'pendente' && strtotime($l['agendado_em']) === $proxima && strtotime($l['agendado_em']) >= time() + 170 * 60 && json_decode($l['recorrencia'], true)['hora'] === '21:00' && $l['processado_em'] !== null && (int) $l['execucoes'] === 1 && (int) $l['entregues'] === 2, [$l, date('Y-m-d H:i', $proxima)]);
confere('F4 o cron avisa quando a campanha repete', strpos($s, 'repete em ' . date('d/m/Y H:i', $proxima)) !== false, $s);
$it = $item_lista($u1, $cD);
confere('F4 lista depois da 1ª execução: 2 enviadas, Agendada, 1 execução, não editável (já saiu) e agendável', $it['enviadas'] === 2 && $it['status'] === 'pendente' && $it['execucoes'] === 1 && $it['editavel'] === false && $it['agendavel'] === true && $it['em_envio'] === false, $it);
$det = pagina('remarketing_detalhes.php', $u1, ['campanha' => $cD], 'C:/tmp/rm_int/detalhes_rec.html');
$html = (string) file_get_contents('C:/tmp/rm_int/detalhes_rec.html');
confere('F4 detalhes: "Repete", "Próximo envio" e Agendada, sem aviso do PHP', strpos($html, 'Todo dia às 21:00') !== false && strpos($html, 'Próximo envio') !== false && strpos($html, 'Agendada') !== false && $limpo($html), mb_substr($det, 0, 300));
// vencida de novo, mas o fluxo enviou há instantes: espera
$pdo->prepare("UPDATE remarketing_campanhas SET agendado_em = NOW() - INTERVAL 1 MINUTE WHERE id = ?")->execute([$cD]);
tg_limpar();
cron();
confere('F4 vencida de novo logo depois do envio: o cron segura (3 horas do fluxo), nada sai', count(tg()) === 0 && $campanha($cD)['status'] === 'pendente' && (int) $campanha($cD)['execucoes'] === 1);
confere('F4 e a lista mostra "retida", não "ativa"', $item_lista($u1, $cD)['retida'] === true && $item_lista($u1, $cD)['em_envio'] === false);
envelhecer();
tg_limpar();
cron();
$l = $campanha($cD);
confere('F4 passadas as 3 horas: 2ª execução (os mesmos 2 leads), 2 execuções, 4 enviadas no total e agendada de novo', $enviados_para(tg()) === ['9401', '9402'] && $l['status'] === 'pendente' && (int) $l['execucoes'] === 2 && (int) $l['acum_entregues'] === 2 && (int) $l['entregues'] === 2 && (int) q1('SELECT COUNT(*) FROM remarketing_envios WHERE campanha_id = ?', [$cD]) === 4, $l);
confere('F4 lista: 4 enviadas (acumuladas) e 2 execuções', $item_lista($u1, $cD)['enviadas'] === 4 && $item_lista($u1, $cD)['execucoes'] === 2);
$r = remarketingParar($pdo, $u1, $cD);
$l = $campanha($cD);
confere('F4 desativar a recorrência de uma campanha que já saiu: volta a "concluída" sem repetir', $r['sucesso'] && strpos($r['mensagem'], 'Recorrência desativada') !== false && $l['status'] === 'concluida' && $l['recorrencia'] === null && (int) $l['enviados'] === 2, [$r, $l]);
confere('F4 e agora dá para reenviar de novo (concluída)', $item_lista($u1, $cD)['reenviavel'] === true);
confere('F4 desativar de novo: "não está agendada"', !remarketingParar($pdo, $u1, $cD)['sucesso']);
$r = remarketingParar($pdo, $u2, $cD);
confere('F4 outro usuário não mexe na campanha: "não encontrada"', !$r['sucesso'] && $r['mensagem'] === 'Campanha não encontrada.', $r);
// desligar a recorrência durante o envio: o envio termina e a campanha fica concluída (o cron lê a recorrência de novo ao terminar)
$pdo->prepare("UPDATE remarketing_campanhas SET status = 'processando', recorrencia = ? WHERE id = ?")->execute([json_encode(['tipo' => 'diario', 'hora' => '21:00']), $cD]);
$r = remarketingParar($pdo, $u1, $cD);
$l = $campanha($cD);
confere('F4 desativar a recorrência com a campanha EM ENVIO: só desliga a repetição, o envio segue', $r['sucesso'] && strpos($r['mensagem'], 'termina normalmente') !== false && $l['status'] === 'processando' && $l['recorrencia'] === null, [$r, $l]);
$pdo->prepare("UPDATE remarketing_campanhas SET status = 'concluida' WHERE id = ?")->execute([$cD]);

// a cada X horas: o intervalo conta do começo da execução anterior
$cH = $nova($fD, 'Recorrente a cada 4 horas', 'Mensagem da campanha que se repete a cada 4 horas');
$r = remarketingIniciar($pdo, $u1, $cH, date('Y-m-d H:i', time() + 4 * 3600), ['tipo' => 'a_cada', 'horas' => 4]);
confere('F4 "a cada 4 horas": o 1º envio é o horário escolhido (aqui, daqui a 4 h)', $r['sucesso'] && strpos($r['mensagem'], 'A cada 4 horas') !== false && $campanha($cH)['status'] === 'pendente', $r);
$inicio_h = date('Y-m-d H:i:s', time() - 60);
$pdo->prepare("UPDATE remarketing_campanhas SET agendado_em = ? WHERE id = ?")->execute([$inicio_h, $cH]);
envelhecer();
tg_limpar();
cron();
$l = $campanha($cH);
confere('F4 "a cada 4 horas": terminou e repete 4 h depois do COMEÇO da execução', $enviados_para(tg()) === ['9401', '9402'] && $l['status'] === 'pendente' && strtotime($l['agendado_em']) === strtotime($inicio_h) + 4 * 3600, [$l['status'], $l['agendado_em'], $inicio_h]);
confere('F4 cancelar o agendamento (nunca ficou concluída, mas já saiu): volta a concluída', remarketingParar($pdo, $u1, $cH)['sucesso'] && $campanha($cH)['status'] === 'concluida' && $campanha($cH)['recorrencia'] === null);

// recorrência de campanha cujo bot falha: a campanha vira "falha" e NÃO repete sozinha
$fE = $fluxo($u1, 'Funil F-E', 'basico', ['ativo' => true, 'planos' => []]);
$bE = $bot($u1, 'BADTOKEN14:fe', 'Bot F-E', $fE);
foreach (range(1, 8) as $i) { $lead($bE, (string) (9500 + $i), 'E' . $i, 0); }
$cE = $nova($fE, 'Recorrente com bot ruim', 'Mensagem da campanha do bot com token recusado');
confere('F4 recorrência a cada 3 horas aceita (o mínimo)', remarketingIniciar($pdo, $u1, $cE, date('Y-m-d H:i', time() + 120), ['tipo' => 'a_cada', 'horas' => 3])['sucesso']);
$pdo->prepare("UPDATE remarketing_campanhas SET agendado_em = NOW() - INTERVAL 1 MINUTE WHERE id = ?")->execute([$cE]);
tg_limpar();
cron();
$l = $campanha($cE);
confere('F4 bot com token recusado: campanha em "falha", recorrência desligada (não fica tentando de hora em hora)', $l['status'] === 'falha' && $l['recorrencia'] === null && (int) $l['entregues'] === 0, $l);
confere('F4 campanha com falha também pode ser reenviada (os envios que falharam não seguram o fluxo)', $item_lista($u1, $cE)['reenviavel'] === true && $item_lista($u1, $cE)['bloqueio'] === null, $item_lista($u1, $cE)['bloqueio']);

// --- F5: API (CSRF, validações do modal, isolamento) ---
$fF = $fluxo($u1, 'Funil F-F', 'basico', ['ativo' => true, 'planos' => []]);
$bF = $bot($u1, 'OKTOKEN15:ff', 'Bot F-F', $fF);
foreach (['9601', '9602'] as $id) { $lead($bF, $id, 'F' . $id, 0); }
$cF = $nova($fF, 'Campanha da API de recorrência', 'Mensagem da campanha da API de recorrência');
$ag = fn(array $freq, int $uid = 0, int $id = 0) => $api('POST', $uid ?: $u1, [], ['acao' => 'agendar', 'id' => $id ?: $cF, 'frequencia' => $freq]);
$r = $ag(['tipo' => 'semana', 'dias' => [], 'hora' => '08:00']);
confere('F5 API agendar "dias da semana" sem nenhum dia: 422 com a frase do Shark', $r['http'] === 422 && $r['json']['mensagem'] === 'Selecione pelo menos um dia da semana.' && $campanha($cF)['status'] === 'rascunho', $r['cru']);
$r = $ag(['tipo' => 'horarios', 'horarios' => ['09:00', '10:30']]);
confere('F5 API agendar horários a menos de 3 h um do outro: 422', $r['http'] === 422 && strpos($r['json']['mensagem'], '3 horas') !== false, $r['cru']);
$r = $ag(['tipo' => 'a_cada', 'horas' => 2, 'data' => date('Y-m-d', time() + 86400), 'hora' => '10:00']);
confere('F5 API agendar "a cada 2 horas": 422 (mínimo 3)', $r['http'] === 422 && strpos($r['json']['mensagem'], 'de 3 a 168') !== false, $r['cru']);
$r = $ag(['tipo' => 'mensal']);
confere('F5 API agendar frequência desconhecida: 422', $r['http'] === 422);
$r = $ag(['tipo' => 'diario', 'hora' => '07:45'], $u2);
confere('F5 API agendar a campanha de OUTRO usuário: 422 "não encontrada" e nada muda', $r['http'] === 422 && $r['json']['mensagem'] === 'Campanha não encontrada.' && $campanha($cF)['status'] === 'rascunho', $r['cru']);
$r = $api('POST', $u1, [], ['acao' => 'agendar', 'id' => $cF, 'frequencia' => ['tipo' => 'diario', 'hora' => '07:45']], false);
confere('F5 API agendar SEM token CSRF: 403 e nada muda', $r['http'] === 403 && $campanha($cF)['status'] === 'rascunho', $r['cru']);
$r = $ag(['tipo' => 'semana', 'dias' => [1, 3, 5], 'hora' => '07:45']);
$l = $campanha($cF);
confere('F5 API agendar dias da semana (seg, qua, sex às 07:45): 200, pendente e recorrência gravada', $r['http'] === 200 && strpos($r['json']['mensagem'], 'Seg, Qua e Sex às 07:45') !== false && $l['status'] === 'pendente' && json_decode($l['recorrencia'], true) === ['tipo' => 'semana', 'dias' => [1, 3, 5], 'hora' => '07:45'], [$r['cru'], $l['recorrencia']]);
$dia_agendado = (int) date('w', strtotime($l['agendado_em']));
confere('F5 o 1º envio cai num desses dias, às 07:45, no futuro', in_array($dia_agendado, [1, 3, 5], true) && date('H:i', strtotime($l['agendado_em'])) === '07:45' && strtotime($l['agendado_em']) > time(), $l['agendado_em']);
$r = $ag(['tipo' => 'horarios', 'horarios' => ['06:00', '12:00', '18:00']]);
$l = $campanha($cF);
confere('F5 trocar para horários personalizados (reagendar): 200 e a recorrência nova substitui a antiga', $r['http'] === 200 && json_decode($l['recorrencia'], true)['horarios'] === ['06:00', '12:00', '18:00'], [$r['cru'], $l['recorrencia']]);
$lista_f = $api('GET', $u1, ['action' => 'listar']);
$item_f = array_values(array_filter($lista_f['json']['campanhas'], fn($c) => $c['id'] === $cF))[0];
confere('F5 API listar traz a recorrência e os campos novos de cada campanha', $item_f['recorrente'] === true && $item_f['recorrencia']['tipo'] === 'horarios' && $item_f['recorrencia_rotulo'] === 'Todo dia às 06:00, 12:00 e 18:00' && array_key_exists('bloqueio', $item_f) && array_key_exists('retida', $item_f) && array_key_exists('execucoes', $item_f) && $item_f['agendavel'] === true, $item_f);
$r = $api('POST', $u2, [], ['acao' => 'parar', 'id' => $cF]);
confere('F5 API parar a campanha de OUTRO usuário: 422 e a recorrência continua', $r['http'] === 422 && $campanha($cF)['recorrencia'] !== null, $r['cru']);
$r = $api('POST', $u1, [], ['acao' => 'parar', 'id' => $cF]);
confere('F5 API parar: 200, volta a rascunho (nunca saiu) e sem recorrência', $r['http'] === 200 && $campanha($cF)['status'] === 'rascunho' && $campanha($cF)['recorrencia'] === null, $r['cru']);
$r = $api('POST', $u1, [], ['acao' => 'parar', 'id' => $cF]);
confere('F5 API parar de novo: 422 "não está agendada"', $r['http'] === 422 && strpos($r['json']['mensagem'], 'não está agendada') !== false, $r['cru']);
$r = $api('POST', $u1, [], ['acao' => 'agendar', 'id' => $cF, 'agendado_em' => date('Y-m-d H:i', time() + 86400)]);
confere('F5 a forma antiga (só agendado_em) continua valendo como "uma vez" e apaga a recorrência', $r['http'] === 200 && $campanha($cF)['status'] === 'pendente' && $campanha($cF)['recorrencia'] === null, $r['cru']);
$r = $api('POST', $u1, [], ['acao' => 'iniciar', 'id' => $cF]);
confere('F5 API iniciar agora uma campanha já agendada: vale (vira "iniciada agora")', $r['http'] === 200 && strpos($r['json']['mensagem'], 'iniciada') !== false, $r['cru']);

// campanha em envio não é (re)agendada
$pdo->prepare("UPDATE remarketing_campanhas SET status = 'processando' WHERE id = ?")->execute([$cF]);
$r = remarketingIniciar($pdo, $u1, $cF, date('Y-m-d H:i', time() + 86400));
confere('F5 campanha em envio não pode ser iniciada nem agendada de novo', !$r['sucesso'] && strpos($r['mensagem'], 'sendo enviada agora') !== false && $campanha($cF)['status'] === 'processando', $r);
$pdo->prepare("UPDATE remarketing_campanhas SET status = 'rascunho' WHERE id = ?")->execute([$cF]);

// --- F6: a trava vale em cima de uma campanha em envio do mesmo fluxo ---
$cF2 = $nova($fF, 'Segunda do fluxo F-F', 'Mensagem da segunda campanha do fluxo F-F');
$pdo->prepare("UPDATE remarketing_campanhas SET status = 'processando', processado_em = NULL WHERE id = ?")->execute([$cF]);
$r = remarketingIniciar($pdo, $u1, $cF2);
confere('F6 com outra campanha do fluxo EM ENVIO: recusa e pede para esperar terminar', !$r['sucesso'] && strpos($r['mensagem'], 'em envio') !== false && strpos($r['mensagem'], 'Campanha da API de recorrência') !== false, $r);
$it = $item_lista($u1, $cF2);
confere('F6 lista: motivo "em_envio" (sem hora para liberar)', $it['bloqueio']['motivo'] === 'em_envio' && $it['bloqueio']['libera_em'] === '', $it['bloqueio']);
$pdo->prepare("UPDATE remarketing_campanhas SET status = 'rascunho' WHERE id = ?")->execute([$cF]);

// --- F7: banco que ainda não rodou o "Atualizar Banco" (sem as colunas novas): a campanha antiga continua saindo ---
$fG = $fluxo($u1, 'Funil F-G', 'basico', ['ativo' => true, 'planos' => []]);
$bG = $bot($u1, 'OKTOKEN16:fg', 'Bot F-G', $fG);
foreach (['9701', '9702'] as $id) { $lead($bG, $id, 'G' . $id, 0); }
$cG = $nova($fG, 'Campanha do banco antigo', 'Mensagem da campanha que sai sem as colunas novas');
$pdo->exec('ALTER TABLE remarketing_campanhas DROP COLUMN recorrencia, DROP COLUMN execucoes, DROP COLUMN acum_entregues, DROP COLUMN acum_falhas');
$raw->prepare("UPDATE remarketing_campanhas SET status = 'pendente', agendado_em = NOW() - INTERVAL 1 MINUTE WHERE id = ?")->execute([$cG]);
tg_limpar();
$s = cron();
$l = $campanha($cG);
confere('F7 sem as colunas novas o cron ainda envia e conclui a campanha (como antes do reenvio)', $enviados_para(tg()) === ['9701', '9702'] && $l['status'] === 'concluida' && (int) $l['entregues'] === 2 && !preg_match('/(Fatal|Uncaught|Unknown column|SQLSTATE)/', $s), [$s, $l]);
[$saida_f7] = sh(php_cmd($app . '/deploy/vps/atualizar-banco.php', [$app], false));
$cols = colunas($raw, $db_nome, 'remarketing_campanhas');
confere('F7 o "Atualizar Banco" devolve as 4 colunas novas (idempotente) com os padrões certos', ($cols['recorrencia'] ?? '') === 'text|YES|' && ($cols['execucoes'] ?? '') === 'int(11)|NO|0' && ($cols['acum_entregues'] ?? '') === 'int(11)|NO|0' && ($cols['acum_falhas'] ?? '') === 'int(11)|NO|0', array_intersect_key($cols, array_flip(['recorrencia', 'execucoes', 'acum_entregues', 'acum_falhas'])));
confere('F7 as campanhas ganham as colunas zeradas (o teste as apagou antes) e seguem do jeito que estavam', (int) q1('SELECT execucoes FROM remarketing_campanhas WHERE id = ?', [$teste]) === 0 && q1('SELECT status FROM remarketing_campanhas WHERE id = ?', [$cG]) === 'concluida');

echo PHP_EOL . "RESUMO: $ok ok, $falhas falhas" . PHP_EOL;
exit($falhas > 0 ? 1 : 0);
