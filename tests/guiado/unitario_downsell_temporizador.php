<?php
declare(strict_types=1);

/**
 * Downsell com temporizador no modo Guiado (funcoes/guiado_ofertas.php): ofertas.downsell.quando = 'depois_pix'. O lead recebe o Pix normal, e se não
 * pagar em `espera_minutos` o cron manda a oferta (um botão por plano marcado, menos o que ele já escolheu), repetindo a cada `intervalo_minutos` até
 * `envios` vezes. Sem rede e sem MySQL: SQLite em memória (o dublê de PDO traduz o NOW() + INTERVAL do MySQL) e um Telegram falso.
 *   php tests/guiado/unitario_downsell_temporizador.php   (sai com código 1 se algo falhar)
 */

$ok = 0;
$falhas = 0;
function confere(string $nome, bool $certo, string $detalhe = ''): void
{
    global $ok, $falhas;
    $certo ? $ok++ : $falhas++;
    echo ($certo ? 'OK     ' : 'FALHOU ') . $nome . ($certo || $detalhe === '' ? '' : '  -> ' . $detalhe) . PHP_EOL;
}

ini_set('error_log', sys_get_temp_dir() . '/teste_downsell_temporizador.log');   // o teste da tabela ausente provoca error_log de propósito

// ---- dublês do que vem de funcoes/fluxo_blocos.php e conexao.php
$GLOBALS['enviados'] = [];
function requisicaoTelegram(string $token, string $metodo, array $parametros = [], array $arquivos = []): array
{
    $GLOBALS['enviados'][] = ['metodo' => $metodo, 'token' => $token] + $parametros;
    return ['ok' => true, 'result' => []];
}
function resolverCaminhoUploadSeguro(string $caminho): ?string
{
    return null;
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
$pdo->exec("CREATE TABLE vendas (id INTEGER PRIMARY KEY, bot_id INTEGER, id_telegram TEXT, status TEXT DEFAULT 'gerado')");
$pdo->exec("CREATE TABLE downsell_agenda (
    id INTEGER PRIMARY KEY AUTOINCREMENT, bot_id INTEGER NOT NULL, id_telegram TEXT NOT NULL, venda_id INTEGER NOT NULL, id_plano TEXT NOT NULL,
    enviados INTEGER NOT NULL DEFAULT 0, proximo_em TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'pendente',
    criado_em TEXT DEFAULT CURRENT_TIMESTAMP, atualizado_em TEXT DEFAULT CURRENT_TIMESTAMP, UNIQUE (venda_id))");

const PLANOS = [
    ['id' => 'p1', 'nome' => 'Mensal', 'valor' => 20.0],
    ['id' => 'p2', 'nome' => 'Trimestral', 'valor' => 50.0],
    ['id' => 'p3', 'nome' => 'Vitalício', 'valor' => 100.0],
];
function dadosFluxo(array $downsell): array
{
    return ['ativo' => true, 'planos' => PLANOS, 'ofertas' => ['downsell' => $downsell]];
}
function downsell(array $extra = []): array
{
    return $extra + ['ativo' => true, 'planos_oferecidos' => ['p1', 'p2', 'p3'], 'desconto_percentual' => 20, 'quando' => 'depois_pix', 'espera_minutos' => 10, 'envios' => 1, 'intervalo_minutos' => 30];
}
/** Bot 1 ligado a um fluxo guiado com o downsell dado. */
function ligarBot(PDO $pdo, array $downsell): void
{
    $pdo->exec('DELETE FROM fluxos');
    $pdo->exec('DELETE FROM bots');
    $pdo->prepare("INSERT INTO fluxos (id, modo, dados_fluxograma) VALUES (1, 'basico', ?)")->execute([json_encode(dadosFluxo($downsell))]);
    $pdo->exec("INSERT INTO bots (id, id_fluxo_conectado, token) VALUES (1, 1, 'TOKEN-BOT')");
}
function agenda(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM downsell_agenda ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
}
function vencer(PDO $pdo): void
{
    $pdo->exec("UPDATE downsell_agenda SET proximo_em = datetime('now', '-1 minutes') WHERE status = 'pendente'");
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
    $pdo->exec('DELETE FROM downsell_agenda');
    $pdo->exec('DELETE FROM vendas');
    limparEnvios();
}

// ---------- configuração ----------
confere('temporizador: padrões quando o dono não preenche (10 min, 1 envio, repete a cada 30)', temporizadorDownsellBasico([]) === ['espera' => 10, 'envios' => 1, 'intervalo' => 30], json_encode(temporizadorDownsellBasico([])));
confere('temporizador: valores respeitados', temporizadorDownsellBasico(['espera_minutos' => 5, 'envios' => 3, 'intervalo_minutos' => 60]) === ['espera' => 5, 'envios' => 3, 'intervalo' => 60]);
confere('temporizador: limites (24 h, 5 envios) e valor inválido/negativo volta ao padrão', temporizadorDownsellBasico(['espera_minutos' => 99999, 'envios' => 99, 'intervalo_minutos' => -4]) === ['espera' => 1440, 'envios' => 5, 'intervalo' => 30]);

confere('modo "depois do Pix" só com downsell ativo, formato novo e quando=depois_pix', downsellDepoisDoPixBasico(dadosFluxo(downsell())));
confere('downsell antigo (sem "quando") continua antes do Pix', !downsellDepoisDoPixBasico(dadosFluxo(['quando' => null] + downsell())) && !downsellDepoisDoPixBasico(dadosFluxo(array_diff_key(downsell(), ['quando' => 1]))));
confere('quando=antes_pix, downsell desligado ou formato antigo (um plano + Aceitar/Recusar): não é "depois do Pix"',
    !downsellDepoisDoPixBasico(dadosFluxo(downsell(['quando' => 'antes_pix']))) && !downsellDepoisDoPixBasico(dadosFluxo(downsell(['ativo' => false])))
    && !downsellDepoisDoPixBasico(dadosFluxo(['ativo' => true, 'id_plano_destino' => 'p2', 'quando' => 'depois_pix'])));
confere('candidatos: o plano que o lead escolheu sai da oferta, e a posição original é mantida', array_keys(planosDoDownsellSemOEscolhidoBasico(dadosFluxo(downsell()), 'p1')) === [1, 2]);

// ---------- agendar ----------
ligarBot($pdo, downsell());
reiniciar($pdo);
agendarDownsellBasico($pdo, 1, '777', 501, 'p1', dadosFluxo(downsell()));
$linhas = agenda($pdo);
confere('agendar: uma linha pendente ligada à venda e ao plano escolhido', count($linhas) === 1 && $linhas[0]['status'] === 'pendente' && (int) $linhas[0]['venda_id'] === 501 && $linhas[0]['id_plano'] === 'p1' && (int) $linhas[0]['enviados'] === 0, json_encode($linhas));
$segundos_ate = strtotime($linhas[0]['proximo_em'] . ' UTC') - time();
confere('agendar: o 1º envio é daqui a ~10 minutos (espera_minutos)', $segundos_ate > 9 * 60 && $segundos_ate <= 10 * 60 + 5, (string) $segundos_ate);

agendarDownsellBasico($pdo, 1, '777', 502, 'p2', dadosFluxo(downsell()));
$linhas = agenda($pdo);
confere('novo Pix do mesmo lead recomeça a contagem (a linha antiga é cancelada, só a nova fica pendente)', count($linhas) === 2 && $linhas[0]['status'] === 'cancelado' && $linhas[1]['status'] === 'pendente' && $linhas[1]['id_plano'] === 'p2', json_encode(array_column($linhas, 'status')));

agendarDownsellBasico($pdo, 1, '888', 503, 'p1', dadosFluxo(downsell()));
confere('outro lead não interfere na contagem do primeiro', count(array_filter(agenda($pdo), fn($l) => $l['status'] === 'pendente')) === 2);

reiniciar($pdo);
agendarDownsellBasico($pdo, 1, '777', 504, 'p1', dadosFluxo(downsell(['quando' => 'antes_pix'])));
agendarDownsellBasico($pdo, 1, '777', 0, 'p1', dadosFluxo(downsell()));
agendarDownsellBasico($pdo, 1, '777', 505, 'p1', dadosFluxo(downsell(['planos_oferecidos' => ['p1']])));
confere('não agenda: modo antes do Pix, venda não identificada, ou nenhum plano sobrando além do escolhido', agenda($pdo) === []);

// ---------- enviar (cron) ----------
reiniciar($pdo);
ligarBot($pdo, downsell());
agendarDownsellBasico($pdo, 1, '777', 601, 'p1', dadosFluxo(downsell()));
confere('antes do prazo o cron não manda nada', processarDownsellsAgendados($pdo) === 0 && $GLOBALS['enviados'] === []);

vencer($pdo);
$qtd = processarDownsellsAgendados($pdo);
$botoes = botoesEnviados();
confere('venceu e não pagou: manda 1 oferta ao lead certo, com o token do bot', $qtd === 1 && count($GLOBALS['enviados']) === 1 && $GLOBALS['enviados'][0]['chat_id'] === '777' && $GLOBALS['enviados'][0]['token'] === 'TOKEN-BOT', json_encode($GLOBALS['enviados']));
confere('a oferta traz um botão por plano marcado, menos o escolhido, com o desconto no preço e a posição no callback',
    $botoes === [['text' => 'Trimestral — R$ 40,00 (-20%)', 'cb' => 'basico::of::dn::p1::p1'], ['text' => 'Vitalício — R$ 80,00 (-20%)', 'cb' => 'basico::of::dn::p1::p2']], json_encode($botoes, JSON_UNESCAPED_UNICODE));
$l = agenda($pdo)[0];
confere('envio único: a sequência termina (concluído, 1 enviado) e não manda de novo', $l['status'] === 'concluido' && (int) $l['enviados'] === 1 && processarDownsellsAgendados($pdo) === 0);

// repetição: 2 envios
reiniciar($pdo);
ligarBot($pdo, downsell(['envios' => 2, 'intervalo_minutos' => 45]));
agendarDownsellBasico($pdo, 1, '777', 602, 'p1', dadosFluxo(downsell(['envios' => 2, 'intervalo_minutos' => 45])));
vencer($pdo);
processarDownsellsAgendados($pdo);
$l = agenda($pdo)[0];
$segundos_ate = strtotime($l['proximo_em'] . ' UTC') - time();
confere('2 envios: depois do 1º a linha continua pendente (1 enviado) e o próximo vem em ~45 minutos', $l['status'] === 'pendente' && (int) $l['enviados'] === 1 && $segundos_ate > 44 * 60 && $segundos_ate <= 45 * 60 + 5, json_encode($l));
vencer($pdo);
limparEnvios();
processarDownsellsAgendados($pdo);
$l = agenda($pdo)[0];
confere('2º envio sai e encerra a sequência (concluído, 2 enviados)', count($GLOBALS['enviados']) === 1 && $l['status'] === 'concluido' && (int) $l['enviados'] === 2, json_encode($l));

// lead pagou
reiniciar($pdo);
agendarDownsellBasico($pdo, 1, '777', 603, 'p1', dadosFluxo(downsell()));
$pdo->exec("INSERT INTO vendas (id, bot_id, id_telegram, status) VALUES (603, 1, '777', 'pago')");
vencer($pdo);
confere('lead que pagou (o próprio Pix) não recebe o downsell e a linha é encerrada', processarDownsellsAgendados($pdo) === 0 && $GLOBALS['enviados'] === [] && agenda($pdo)[0]['status'] === 'concluido');

reiniciar($pdo);
agendarDownsellBasico($pdo, 1, '777', 604, 'p1', dadosFluxo(downsell()));
$pdo->exec("INSERT INTO vendas (id, bot_id, id_telegram, status) VALUES (610, 1, '777', 'pago')");
vencer($pdo);
confere('lead que pagou OUTRO Pix depois (ex.: outro plano) também não recebe', processarDownsellsAgendados($pdo) === 0 && $GLOBALS['enviados'] === []);

reiniciar($pdo);
agendarDownsellBasico($pdo, 1, '777', 605, 'p1', dadosFluxo(downsell()));
$pdo->exec("INSERT INTO vendas (id, bot_id, id_telegram, status) VALUES (300, 1, '777', 'pago')");   // compra ANTERIOR ao Pix agendado não conta
vencer($pdo);
confere('compra paga ANTES do Pix agendado não impede a oferta', processarDownsellsAgendados($pdo) === 1);

// dono mudou o fluxo depois de agendar
reiniciar($pdo);
agendarDownsellBasico($pdo, 1, '777', 606, 'p1', dadosFluxo(downsell()));
ligarBot($pdo, downsell(['quando' => 'antes_pix']));
vencer($pdo);
confere('dono voltou o downsell para "antes do Pix" depois de agendar: nada é enviado', processarDownsellsAgendados($pdo) === 0 && $GLOBALS['enviados'] === [] && agenda($pdo)[0]['status'] === 'concluido');

reiniciar($pdo);
ligarBot($pdo, downsell());
agendarDownsellBasico($pdo, 1, '777', 607, 'p1', dadosFluxo(downsell()));
ligarBot($pdo, downsell(['ativo' => false]));
vencer($pdo);
confere('downsell desligado depois de agendar: nada é enviado', processarDownsellsAgendados($pdo) === 0 && $GLOBALS['enviados'] === []);

// reivindicação e travas
reiniciar($pdo);
ligarBot($pdo, downsell());
agendarDownsellBasico($pdo, 1, '777', 608, 'p1', dadosFluxo(downsell()));
vencer($pdo);
$pdo->exec("UPDATE downsell_agenda SET status = 'processando'");
confere('linha "processando" recente (outro processo está enviando) não é pega de novo', processarDownsellsAgendados($pdo) === 0 && $GLOBALS['enviados'] === []);
$pdo->exec("UPDATE downsell_agenda SET atualizado_em = datetime('now', '-20 minutes')");
confere('linha "processando" parada há mais de 15 min volta para a fila e é enviada', processarDownsellsAgendados($pdo) === 1);

// lead tocou num plano do downsell
reiniciar($pdo);
agendarDownsellBasico($pdo, 1, '777', 609, 'p1', dadosFluxo(downsell(['envios' => 3])));
cancelarDownsellsDoLeadBasico($pdo, 1, '777');
vencer($pdo);
confere('lead que tocou num plano da oferta: a sequência é cancelada e nada mais é enviado', processarDownsellsAgendados($pdo) === 0 && agenda($pdo)[0]['status'] === 'cancelado');

// limpeza de linhas antigas
reiniciar($pdo);
$pdo->exec("INSERT INTO downsell_agenda (bot_id, id_telegram, venda_id, id_plano, proximo_em, status, criado_em) VALUES (1, '1', 1, 'p1', datetime('now'), 'concluido', datetime('now', '-8 days'))");
$pdo->exec("INSERT INTO downsell_agenda (bot_id, id_telegram, venda_id, id_plano, proximo_em, status, criado_em) VALUES (1, '2', 2, 'p1', datetime('now'), 'concluido', datetime('now', '-1 days'))");
processarDownsellsAgendados($pdo);
confere('linhas encerradas há mais de 7 dias são apagadas; as recentes ficam', count(agenda($pdo)) === 1 && agenda($pdo)[0]['id_telegram'] === '2');

// tabela ainda não criada (código publicado antes do "Atualizar Banco"): o Guiado não pode quebrar
$pdo->exec('DROP TABLE downsell_agenda');
$sem_erro = true;
try {
    agendarDownsellBasico($pdo, 1, '777', 700, 'p1', dadosFluxo(downsell()));
    cancelarDownsellsDoLeadBasico($pdo, 1, '777');
} catch (Throwable $e) {
    $sem_erro = false;
}
confere('sem a tabela downsell_agenda, agendar e cancelar não lançam erro (só registram no log)', $sem_erro);

echo PHP_EOL . "$ok ok, $falhas falha(s)" . PHP_EOL;
exit($falhas > 0 ? 1 : 0);
