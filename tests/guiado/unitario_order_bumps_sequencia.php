<?php
declare(strict_types=1);

/**
 * Sequência de Order Bumps no modo Guiado (funcoes/guiado_ofertas.php): ofertas.order_bump.quando = 'depois_pix' com até 5 itens. O lead recebe o Pix
 * normal; se não pagar, o cron manda o 1º bump depois do tempo do 1º item, o 2º depois do tempo do 2º item (contado do envio anterior), e assim por
 * diante, até pagar. Sem rede e sem MySQL: SQLite em memória (o dublê de PDO traduz o NOW() + INTERVAL do MySQL) e um Telegram falso.
 *   php tests/guiado/unitario_order_bumps_sequencia.php   (sai com código 1 se algo falhar)
 */

$ok = 0;
$falhas = 0;
function confere(string $nome, bool $certo, string $detalhe = ''): void
{
    global $ok, $falhas;
    $certo ? $ok++ : $falhas++;
    echo ($certo ? 'OK     ' : 'FALHOU ') . $nome . ($certo || $detalhe === '' ? '' : '  -> ' . $detalhe) . PHP_EOL;
}

ini_set('error_log', sys_get_temp_dir() . '/teste_order_bumps_sequencia.log');   // o teste da tabela ausente provoca error_log de propósito

// ---- dublês do que vem de funcoes/fluxo_blocos.php e conexao.php
$GLOBALS['enviados'] = [];
function requisicaoTelegram(string $token, string $metodo, array $parametros = [], array $arquivos = []): array
{
    $GLOBALS['enviados'][] = ['metodo' => $metodo, 'token' => $token, 'arquivos' => $arquivos] + $parametros;
    return ['ok' => true, 'result' => []];
}
function resolverCaminhoUploadSeguro(string $caminho): ?string
{
    return strpos($caminho, 'uploads/') === 0 ? '/var/www/painel/' . $caminho : null;
}
function garantirConexaoBanco(): void
{
}
require_once dirname(__DIR__, 2) . '/funcoes/guiado_ofertas.php';

/** SQLite no lugar do MySQL: só traduz as formas de NOW() (com e sem INTERVAL) que o código usa. */
class PdoMysqlParaSqlite extends PDO
{
    private function traduzir(string $sql): string
    {
        $sql = preg_replace('/NOW\(\)\s*\+\s*INTERVAL\s+\?\s+MINUTE/i', "datetime('now', '+' || ? || ' minutes')", $sql);
        $sql = preg_replace('/NOW\(\)\s*-\s*INTERVAL\s+(\d+)\s+MINUTE/i', "datetime('now', '-$1 minutes')", $sql);
        $sql = preg_replace('/NOW\(\)\s*-\s*INTERVAL\s+(\d+)\s+DAY/i', "datetime('now', '-$1 days')", $sql);
        return preg_replace('/NOW\(\)/i', "datetime('now')", $sql);
    }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare($this->traduzir($query), $options);
    }
    public function exec(string $statement): int|false
    {
        return parent::exec($this->traduzir($statement));
    }
}

$pdo = new PdoMysqlParaSqlite('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['pdo'] = $pdo;
$pdo->exec('CREATE TABLE fluxos (id INTEGER PRIMARY KEY, modo TEXT, dados_fluxograma TEXT)');
$pdo->exec('CREATE TABLE bots (id INTEGER PRIMARY KEY, id_fluxo_conectado INTEGER, token TEXT)');
// o Remarketing (funcoes/remarketing_ofertas.php) pergunta se a venda pertence a uma campanha com order bump próprio: precisa destas duas tabelas (vazias aqui)
$pdo->exec('CREATE TABLE remarketing_campanhas (id INTEGER PRIMARY KEY, ofertas TEXT)');
$pdo->exec('CREATE TABLE remarketing_vendas (id INTEGER PRIMARY KEY, venda_id INTEGER, campanha_id INTEGER)');
$pdo->exec("CREATE TABLE vendas (id INTEGER PRIMARY KEY, bot_id INTEGER, id_telegram TEXT, status TEXT DEFAULT 'gerado')");
$pdo->exec("CREATE TABLE order_bump_agenda (
    id INTEGER PRIMARY KEY AUTOINCREMENT, bot_id INTEGER NOT NULL, id_telegram TEXT NOT NULL, venda_id INTEGER NOT NULL, id_plano TEXT NOT NULL,
    enviados INTEGER NOT NULL DEFAULT 0, proximo_em TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'pendente',
    criado_em TEXT DEFAULT CURRENT_TIMESTAMP, atualizado_em TEXT DEFAULT CURRENT_TIMESTAMP, UNIQUE (venda_id))");

const PLANOS = [
    ['id' => 'p1', 'nome' => 'Mensal', 'valor' => 20.0],
    ['id' => 'p2', 'nome' => 'Trimestral', 'valor' => 50.0],
    ['id' => 'pr', 'nome' => 'Assinatura', 'valor' => 30.0, 'cobranca' => 'recorrente'],
];
/** Os 3 itens do exemplo do cliente: o 1º depois de 5 min do Pix, o 2º 15 min depois do 1º, o 3º 10 min depois do 2º. */
function itens(): array
{
    return [
        ['nome' => 'Bônus A', 'valor_extra' => 9.9, 'espera_minutos' => 5, 'mensagem' => 'Leve o bônus A!', 'texto_aceitar' => 'Quero A', 'texto_recusar' => 'Não quero A'],
        ['nome' => 'Bônus B', 'valor_extra' => 14.5, 'espera_minutos' => 15],
        ['nome' => 'Bônus C', 'valor_extra' => 4.0, 'espera_minutos' => 10, 'midia_tipo' => 'image', 'midia_path' => 'uploads/c.jpg'],
    ];
}
function bump(array $extra = []): array
{
    return $extra + ['ativo' => true, 'quando' => 'depois_pix', 'itens' => itens()];
}
function dadosFluxo(array $bump): array
{
    return ['ativo' => true, 'planos' => PLANOS, 'ofertas' => ['order_bump' => $bump]];
}
/** Bot 1 ligado a um fluxo guiado com o order bump dado. */
function ligarBot(PDO $pdo, array $bump, array $mudar = []): void
{
    $pdo->exec('DELETE FROM fluxos');
    $pdo->exec('DELETE FROM bots');
    $pdo->prepare("INSERT INTO fluxos (id, modo, dados_fluxograma) VALUES (1, 'basico', ?)")->execute([json_encode($mudar + dadosFluxo($bump))]);
    $pdo->exec("INSERT INTO bots (id, id_fluxo_conectado, token) VALUES (1, 1, 'TOKEN-BOT')");
}
function agenda(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM order_bump_agenda ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
}
function vencer(PDO $pdo): void
{
    $pdo->exec("UPDATE order_bump_agenda SET proximo_em = datetime('now', '-1 minutes') WHERE status = 'pendente'");
}
function minutosAte(array $linha): int
{
    return (int) round((strtotime($linha['proximo_em'] . ' UTC') - time()) / 60);
}
function limparEnvios(): void
{
    $GLOBALS['enviados'] = [];
}
function botoesEnviados(): array
{
    $saida = [];
    foreach ($GLOBALS['enviados'] as $e) {
        foreach (json_decode((string) ($e['reply_markup'] ?? '{}'), true)['inline_keyboard'] ?? [] as $linha) {
            foreach ($linha as $b) {
                $saida[] = ['text' => $b['text'], 'cb' => $b['callback_data']];
            }
        }
    }
    return $saida;
}
function reiniciar(PDO $pdo): void
{
    $pdo->exec('DELETE FROM order_bump_agenda');
    $pdo->exec('DELETE FROM vendas');
    limparEnvios();
}

// ---------- configuração ----------
confere('espera: padrão de 10 min quando o dono não preenche, valor respeitado, limite de 24 h e inválido/negativo volta ao padrão',
    esperaOrderBumpBasico([]) === 10 && esperaOrderBumpBasico(['espera_minutos' => 7]) === 7 && esperaOrderBumpBasico(['espera_minutos' => 99999]) === 1440 && esperaOrderBumpBasico(['espera_minutos' => -3]) === 10);
confere('itens: todos com valor extra > 0, na ordem; limite de 5', array_column(itensOrderBumpBasico(dadosFluxo(bump())), 'nome') === ['Bônus A', 'Bônus B', 'Bônus C']
    && count(itensOrderBumpBasico(dadosFluxo(bump(['itens' => array_fill(0, 9, ['valor_extra' => 3.0])])))) === 5);
confere('itens: item sem valor extra ou que não é lista é ignorado', array_column(itensOrderBumpBasico(dadosFluxo(bump(['itens' => [['nome' => 'X', 'valor_extra' => 0], 'lixo', ['nome' => 'Y', 'valor_extra' => 2.5]]]))), 'nome') === ['Y']);
confere('fluxo salvo ANTES da sequência (sem "itens"): o próprio order bump é o único item', array_column(itensOrderBumpBasico(dadosFluxo(['ativo' => true, 'nome' => 'Velho', 'valor_extra' => 9.9])), 'nome') === ['Velho']);
confere('modo "depois do Pix": só com order bump ativo, quando=depois_pix e algum item válido',
    orderBumpDepoisDoPixBasico(dadosFluxo(bump())) && !orderBumpDepoisDoPixBasico(dadosFluxo(bump(['ativo' => false]))) && !orderBumpDepoisDoPixBasico(dadosFluxo(bump(['quando' => 'depois_pagamento'])))
    && !orderBumpDepoisDoPixBasico(dadosFluxo(['ativo' => true, 'valor_extra' => 5.0])) && !orderBumpDepoisDoPixBasico(dadosFluxo(bump(['itens' => [['valor_extra' => 0]]]))));
confere('texto padrão da oferta: nome e valor em reais', textoPadraoOrderBumpBasico(['nome' => 'Pack', 'valor_extra' => 9.9]) === 'Adicione Pack ao seu pedido por mais R$ 9,90.'
    && textoPadraoOrderBumpBasico(['valor_extra' => 1234.5]) === 'Adicione um extra ao seu pedido por mais R$ 1.234,50.');

// ---------- agendar ----------
ligarBot($pdo, bump());
reiniciar($pdo);
agendarOrderBumpsBasico($pdo, 1, '777', 501, 'p1', dadosFluxo(bump()));
$linhas = agenda($pdo);
confere('agendar: uma linha pendente ligada à venda e ao plano escolhido, nada enviado ainda', count($linhas) === 1 && $linhas[0]['status'] === 'pendente' && (int) $linhas[0]['venda_id'] === 501 && $linhas[0]['id_plano'] === 'p1' && (int) $linhas[0]['enviados'] === 0 && $GLOBALS['enviados'] === [], json_encode($linhas));
confere('agendar: o 1º bump é daqui a ~5 minutos (espera do 1º item)', abs(minutosAte($linhas[0]) - 5) <= 1, (string) minutosAte($linhas[0]));

agendarOrderBumpsBasico($pdo, 1, '777', 502, 'p2', dadosFluxo(bump()));
$linhas = agenda($pdo);
confere('novo Pix do mesmo lead recomeça a contagem (a linha antiga é cancelada, só a nova fica pendente)', count($linhas) === 2 && $linhas[0]['status'] === 'cancelado' && $linhas[1]['status'] === 'pendente' && $linhas[1]['id_plano'] === 'p2', json_encode(array_column($linhas, 'status')));

agendarOrderBumpsBasico($pdo, 1, '888', 503, 'p1', dadosFluxo(bump()));
confere('outro lead não interfere na contagem do primeiro', count(array_filter(agenda($pdo), fn($l) => $l['status'] === 'pendente')) === 2);

reiniciar($pdo);
agendarOrderBumpsBasico($pdo, 1, '777', 504, 'p1', dadosFluxo(bump(['quando' => 'depois_pagamento'])));
agendarOrderBumpsBasico($pdo, 1, '777', 505, 'p1', dadosFluxo(bump(['ativo' => false])));
agendarOrderBumpsBasico($pdo, 1, '777', 0, 'p1', dadosFluxo(bump()));
agendarOrderBumpsBasico($pdo, 1, '777', 506, 'pr', dadosFluxo(bump()));
agendarOrderBumpsBasico($pdo, 1, '777', 507, 'pX', dadosFluxo(bump()));
confere('não agenda: modo "depois do pagamento", desligado, venda não identificada, plano de assinatura recorrente ou plano que não existe', agenda($pdo) === []);

// ---------- enviar (cron): a sequência do exemplo do cliente ----------
reiniciar($pdo);
ligarBot($pdo, bump());
agendarOrderBumpsBasico($pdo, 1, '777', 601, 'p1', dadosFluxo(bump()));
confere('antes do prazo o cron não manda nada', processarOrderBumpsAgendados($pdo) === 0 && $GLOBALS['enviados'] === []);

vencer($pdo);
$qtd = processarOrderBumpsAgendados($pdo);
$botoes = botoesEnviados();
confere('1º bump: manda 1 oferta ao lead certo, com o token do bot e a mensagem do item', $qtd === 1 && count($GLOBALS['enviados']) === 1 && $GLOBALS['enviados'][0]['chat_id'] === '777' && $GLOBALS['enviados'][0]['token'] === 'TOKEN-BOT' && $GLOBALS['enviados'][0]['text'] === 'Leve o bônus A!', json_encode($GLOBALS['enviados']));
confere('1º bump: botões do item com a posição 0 e o plano no callback (cabe nos 64 bytes do Telegram)', $botoes === [['text' => 'Quero A', 'cb' => 'basico::bs::p1::0::1'], ['text' => 'Não quero A', 'cb' => 'basico::bs::p1::0::0']] && strlen($botoes[0]['cb']) <= 64, json_encode($botoes, JSON_UNESCAPED_UNICODE));
$l = agenda($pdo)[0];
confere('depois do 1º: continua pendente (1 enviado) e o 2º vem daqui a ~15 min (espera do 2º item, contada do envio anterior)', $l['status'] === 'pendente' && (int) $l['enviados'] === 1 && abs(minutosAte($l) - 15) <= 1, json_encode($l));

vencer($pdo);
limparEnvios();
processarOrderBumpsAgendados($pdo);
$botoes = botoesEnviados();
confere('2º bump: texto padrão (item sem mensagem) com o nome e o valor, botões padrão e posição 1', ($GLOBALS['enviados'][0]['text'] ?? '') === 'Adicione Bônus B ao seu pedido por mais R$ 14,50.' && $botoes === [['text' => 'Sim, quero!', 'cb' => 'basico::bs::p1::1::1'], ['text' => 'Não, obrigado', 'cb' => 'basico::bs::p1::1::0']], json_encode($GLOBALS['enviados'], JSON_UNESCAPED_UNICODE));
$l = agenda($pdo)[0];
confere('depois do 2º: o 3º vem daqui a ~10 min (espera do 3º item)', $l['status'] === 'pendente' && (int) $l['enviados'] === 2 && abs(minutosAte($l) - 10) <= 1, json_encode($l));

vencer($pdo);
limparEnvios();
processarOrderBumpsAgendados($pdo);
$e = $GLOBALS['enviados'][0] ?? [];
confere('3º bump: sai como foto (mídia do item) com a legenda padrão e os botões', ($e['metodo'] ?? '') === 'sendPhoto' && ($e['arquivos']['photo'] ?? '') === '/var/www/painel/uploads/c.jpg' && strpos($e['caption'] ?? '', 'Bônus C') !== false, json_encode($e, JSON_UNESCAPED_UNICODE));
$l = agenda($pdo)[0];
confere('depois do último item a sequência termina (concluído, 3 enviados) e o cron não manda mais nada', $l['status'] === 'concluido' && (int) $l['enviados'] === 3 && processarOrderBumpsAgendados($pdo) === 0);

// item sem valor no meio: a posição que vai no callback é a da lista de itens VÁLIDOS (mesma usada pelo clique)
reiniciar($pdo);
$com_buraco = bump(['itens' => [['nome' => 'A', 'valor_extra' => 3.0, 'espera_minutos' => 1], ['nome' => 'Zero', 'valor_extra' => 0], ['nome' => 'C', 'valor_extra' => 5.0, 'espera_minutos' => 2]]]);
ligarBot($pdo, $com_buraco);
agendarOrderBumpsBasico($pdo, 1, '777', 602, 'p1', dadosFluxo($com_buraco));
vencer($pdo);
processarOrderBumpsAgendados($pdo);
vencer($pdo);
limparEnvios();
processarOrderBumpsAgendados($pdo);
confere('item sem valor é pulado: o 2º envio é o "C" e o callback usa a posição 1 (igual à do clique no webhook)', botoesEnviados()[0]['cb'] === 'basico::bs::p1::1::1' && strpos($GLOBALS['enviados'][0]['text'], 'C ') !== false, json_encode(botoesEnviados()));

// um item só: envia e termina
reiniciar($pdo);
$um = bump(['itens' => [['nome' => 'Único', 'valor_extra' => 8.0, 'espera_minutos' => 3]]]);
ligarBot($pdo, $um);
agendarOrderBumpsBasico($pdo, 1, '777', 603, 'p1', dadosFluxo($um));
vencer($pdo);
processarOrderBumpsAgendados($pdo);
$l = agenda($pdo)[0];
confere('um item só: manda e encerra (concluído, 1 enviado)', $l['status'] === 'concluido' && (int) $l['enviados'] === 1 && count($GLOBALS['enviados']) === 1);

// ---------- lead pagou ----------
reiniciar($pdo);
ligarBot($pdo, bump());
agendarOrderBumpsBasico($pdo, 1, '777', 611, 'p1', dadosFluxo(bump()));
$pdo->exec("INSERT INTO vendas (id, bot_id, id_telegram, status) VALUES (611, 1, '777', 'pago')");
vencer($pdo);
confere('lead que pagou (o próprio Pix) não recebe nenhum bump e a linha é encerrada', processarOrderBumpsAgendados($pdo) === 0 && $GLOBALS['enviados'] === [] && agenda($pdo)[0]['status'] === 'concluido');

reiniciar($pdo);
agendarOrderBumpsBasico($pdo, 1, '777', 612, 'p1', dadosFluxo(bump()));
vencer($pdo);
processarOrderBumpsAgendados($pdo);                       // 1º bump sai
$pdo->exec("INSERT INTO vendas (id, bot_id, id_telegram, status) VALUES (620, 1, '777', 'pago')");   // paga depois do 1º bump
vencer($pdo);
limparEnvios();
confere('pagou no meio da sequência: o 2º bump não sai e a linha é encerrada', processarOrderBumpsAgendados($pdo) === 0 && $GLOBALS['enviados'] === [] && agenda($pdo)[0]['status'] === 'concluido');

reiniciar($pdo);
agendarOrderBumpsBasico($pdo, 1, '777', 613, 'p1', dadosFluxo(bump()));
$pdo->exec("INSERT INTO vendas (id, bot_id, id_telegram, status) VALUES (300, 1, '777', 'pago')");   // compra ANTERIOR ao Pix agendado não conta
vencer($pdo);
confere('compra paga ANTES do Pix agendado não impede a oferta', processarOrderBumpsAgendados($pdo) === 1);

// ---------- dono mudou o fluxo depois de agendar ----------
$muda = [
    'voltou para "depois do pagamento"' => [bump(['quando' => 'depois_pagamento']), []],
    'desligou o order bump' => [bump(['ativo' => false]), []],
    'desligou o fluxo' => [bump(), ['ativo' => false]],
    'apagou todos os itens com valor' => [bump(['itens' => [['nome' => 'Z', 'valor_extra' => 0]]]), []],
];
foreach ($muda as $quem => [$novo_bump, $mudar]) {
    reiniciar($pdo);
    ligarBot($pdo, bump());
    agendarOrderBumpsBasico($pdo, 1, '777', 630, 'p1', dadosFluxo(bump()));
    ligarBot($pdo, $novo_bump, $mudar + ['ativo' => true]);
    vencer($pdo);
    confere("dono $quem depois de agendar: nada é enviado e a linha é encerrada", processarOrderBumpsAgendados($pdo) === 0 && $GLOBALS['enviados'] === [] && agenda($pdo)[0]['status'] === 'concluido');
}
reiniciar($pdo);
ligarBot($pdo, bump());
agendarOrderBumpsBasico($pdo, 1, '777', 631, 'p1', dadosFluxo(bump()));
ligarBot($pdo, bump(), ['planos' => [PLANOS[1]]]);   // o plano p1 foi removido
vencer($pdo);
confere('plano removido do fluxo depois de agendar: nada é enviado', processarOrderBumpsAgendados($pdo) === 0 && $GLOBALS['enviados'] === []);
reiniciar($pdo);
ligarBot($pdo, bump());
agendarOrderBumpsBasico($pdo, 1, '777', 632, 'p1', dadosFluxo(bump()));
ligarBot($pdo, bump(), ['planos' => [['id' => 'p1', 'nome' => 'Mensal', 'valor' => 20.0, 'cobranca' => 'recorrente']]]);   // virou assinatura
vencer($pdo);
confere('plano virou assinatura recorrente depois de agendar: nada é enviado (o extra seria cobrado todo ciclo)', processarOrderBumpsAgendados($pdo) === 0 && $GLOBALS['enviados'] === []);
reiniciar($pdo);
ligarBot($pdo, bump());
agendarOrderBumpsBasico($pdo, 1, '777', 633, 'p1', dadosFluxo(bump()));
$pdo->exec("UPDATE fluxos SET modo = 'avancado'");
vencer($pdo);
confere('bot passou para um fluxo do Editor Visual: nada é enviado', processarOrderBumpsAgendados($pdo) === 0 && $GLOBALS['enviados'] === []);

// ---------- reivindicação e travas ----------
reiniciar($pdo);
ligarBot($pdo, bump());
agendarOrderBumpsBasico($pdo, 1, '777', 640, 'p1', dadosFluxo(bump()));
vencer($pdo);
$pdo->exec("UPDATE order_bump_agenda SET status = 'processando'");
confere('linha "processando" recente (outro processo está enviando) não é pega de novo', processarOrderBumpsAgendados($pdo) === 0 && $GLOBALS['enviados'] === []);
$pdo->exec("UPDATE order_bump_agenda SET atualizado_em = datetime('now', '-20 minutes')");
confere('linha "processando" parada há mais de 15 min volta para a fila e é enviada', processarOrderBumpsAgendados($pdo) === 1);

// aceitou um bump (ou gerou outro Pix): a sequência pendente do lead é cancelada
reiniciar($pdo);
agendarOrderBumpsBasico($pdo, 1, '777', 641, 'p1', dadosFluxo(bump()));
agendarOrderBumpsBasico($pdo, 1, '888', 642, 'p1', dadosFluxo(bump()));
cancelarOrderBumpsDoLeadBasico($pdo, 1, '777');
vencer($pdo);
confere('lead que aceitou um bump: a sequência dele é cancelada e nada é enviado; a de outro lead segue', processarOrderBumpsAgendados($pdo) === 1 && agenda($pdo)[0]['status'] === 'cancelado' && $GLOBALS['enviados'][0]['chat_id'] === '888');

// limpeza de linhas antigas
reiniciar($pdo);
$pdo->exec("INSERT INTO order_bump_agenda (bot_id, id_telegram, venda_id, id_plano, proximo_em, status, criado_em) VALUES (1, '1', 1, 'p1', datetime('now'), 'concluido', datetime('now', '-8 days'))");
$pdo->exec("INSERT INTO order_bump_agenda (bot_id, id_telegram, venda_id, id_plano, proximo_em, status, criado_em) VALUES (1, '2', 2, 'p1', datetime('now'), 'concluido', datetime('now', '-1 days'))");
processarOrderBumpsAgendados($pdo);
confere('linhas encerradas há mais de 7 dias são apagadas; as recentes ficam', count(agenda($pdo)) === 1 && agenda($pdo)[0]['id_telegram'] === '2');

// ---------- order bump pós-pagamento não repete quando a sequência está ligada ----------
reiniciar($pdo);
limparEnvios();
$venda = ['id' => 700, 'bot_id' => 1, 'id_telegram' => '777', 'id_fluxo' => 1, 'nome_produto' => 'Mensal', 'tipo_cobranca' => 'unica'];
ligarBot($pdo, ['ativo' => true, 'valor_extra' => 9.9, 'nome' => 'Velho'] + bump(['quando' => 'depois_pagamento']));
ofertasPosPagamentoBasico($pdo, 'TOKEN-BOT', $venda);
confere('"depois do pagamento": o order bump (um só) continua sendo oferecido depois de pagar, como antes', count($GLOBALS['enviados']) === 1 && botoesEnviados()[0]['cb'] === 'basico::pos::bp::0::1', json_encode(botoesEnviados()));
limparEnvios();
ligarBot($pdo, ['valor_extra' => 9.9, 'nome' => 'Velho'] + bump());
ofertasPosPagamentoBasico($pdo, 'TOKEN-BOT', $venda);
confere('"depois do Pix" (sequência): quem pagou NÃO recebe o order bump de novo depois do pagamento', $GLOBALS['enviados'] === [], json_encode($GLOBALS['enviados']));

// ---------- tabela ainda não criada (código publicado antes do "Atualizar Banco"): o Guiado não pode quebrar ----------
$pdo->exec('DROP TABLE order_bump_agenda');
$sem_erro = true;
try {
    agendarOrderBumpsBasico($pdo, 1, '777', 800, 'p1', dadosFluxo(bump()));
    cancelarOrderBumpsDoLeadBasico($pdo, 1, '777');
} catch (Throwable $e) {
    $sem_erro = false;
}
confere('sem a tabela order_bump_agenda, agendar e cancelar não lançam erro (só registram no log)', $sem_erro);

echo PHP_EOL . "$ok ok, $falhas falha(s)" . PHP_EOL;
exit($falhas > 0 ? 1 : 0);
