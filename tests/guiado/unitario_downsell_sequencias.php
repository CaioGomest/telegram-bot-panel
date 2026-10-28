<?php
declare(strict_types=1);

/**
 * Downsell em sequência e planos criados no Downsell (item 38, 27/10/2026): ofertas.downsell é a 1ª sequência e ofertas.downsell.sequencias_extras traz as outras (até 5),
 * cada uma com o próprio tempo (contado do envio anterior), mensagem, desconto, planos (marcados da aba Planos ou, com criar_planos, criados ali) e entrega.
 * Cobre as funções puras (funcoes/entrega.php), o envio de cada sequência e a agenda do cron (funcoes/guiado_ofertas.php, com SQLite em memória e um Telegram falso)
 * e as validações de salvar. O Pix e a entrega do plano aceito ficam em unitario_oferta_midia.php (gerarPixBasico) e o valor mínimo em tests/gateways/.
 *   php tests/guiado/unitario_downsell_sequencias.php   (sai com código 1 se algo falhar)
 */

$ok = 0;
$falhas = 0;
function confere(string $nome, bool $certo, string $detalhe = ''): void
{
    global $ok, $falhas;
    $certo ? $ok++ : $falhas++;
    echo ($certo ? 'OK     ' : 'FALHOU ') . $nome . ($certo || $detalhe === '' ? '' : '  -> ' . $detalhe) . PHP_EOL;
}

ini_set('error_log', sys_get_temp_dir() . '/teste_downsell_sequencias.log');

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
const PROPRIOS = [
    ['nome' => 'Relâmpago', 'valor' => 9.9, 'dias_acesso' => 3, 'unidade_acesso' => 'horas', 'entrega_tipo' => 'link', 'link_entrega' => 'https://x.exemplo.com/a'],
    ['nome' => 'Bônus', 'valor' => 5, 'dias_acesso' => 7, 'unidade_acesso' => 'dias', 'entrega_tipo' => 'mensagem', 'mensagem_entrega' => 'Oi {nome}'],
];
function seqB(array $extra = []): array
{
    return $extra + ['mensagem' => 'Oferta B', 'espera_minutos' => 30, 'planos_oferecidos' => ['p3'], 'desconto_percentual' => 40];
}
function seqC(array $extra = []): array
{
    return $extra + ['mensagem' => 'Oferta C', 'espera_minutos' => 45, 'criar_planos' => true, 'planos_proprios' => PROPRIOS, 'desconto_percentual' => 90];
}
/** ofertas.downsell: a 1ª sequência (A) + as extras dadas. */
function downsell(array $extras = [], array $topo = []): array
{
    return $topo + ['ativo' => true, 'quando' => 'depois_pix', 'planos_oferecidos' => ['p1', 'p2'], 'desconto_percentual' => 20, 'espera_minutos' => 10, 'envios' => 1, 'intervalo_minutos' => 30,
        'mensagem' => 'Oferta A', 'sequencias_extras' => $extras];
}
function dadosFluxo(array $downsell): array
{
    return ['ativo' => true, 'planos' => PLANOS, 'ofertas' => ['downsell' => $downsell]];
}
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
function minutosAte(array $linha): int
{
    return (int) round((strtotime($linha['proximo_em'] . ' UTC') - time()) / 60);
}
function reiniciar(PDO $pdo): void
{
    $pdo->exec('DELETE FROM downsell_agenda');
    $pdo->exec('DELETE FROM vendas');
    $GLOBALS['enviados'] = [];
}
function mensagens(): array
{
    return array_values(array_map(fn($e) => (string) ($e['text'] ?? $e['caption'] ?? ''), $GLOBALS['enviados']));
}
function botoesEnviados(): array
{
    $saida = [];
    foreach ($GLOBALS['enviados'] as $e) {
        foreach (json_decode((string) ($e['reply_markup'] ?? '{}'), true)['inline_keyboard'] ?? [] as $linha) {
            foreach ($linha as $b) {
                $saida[] = [$b['text'], $b['callback_data']];
            }
        }
    }
    return $saida;
}
/** Um ciclo do cron: vence o que está pendente e processa. */
function cron(PDO $pdo): int
{
    $GLOBALS['enviados'] = [];
    vencer($pdo);
    return processarDownsellsAgendados($pdo);
}

// ---------- as sequências ----------
$dados = dadosFluxo(downsell([seqB(), seqC()]));
confere('sequências: a 1ª é o próprio ofertas.downsell (sem as extras dentro) e as outras vêm de sequencias_extras, na ordem', count(sequenciasDownsellBasico($dados)) === 3
    && sequenciasDownsellBasico($dados)[0]['mensagem'] === 'Oferta A' && !isset(sequenciasDownsellBasico($dados)[0]['sequencias_extras']) && sequenciasDownsellBasico($dados)[2]['mensagem'] === 'Oferta C');
confere('sequências: em "Antes do Pix" só vale a 1ª (a sequência é disparada pelo Pix não pago)', count(sequenciasDownsellBasico(dadosFluxo(downsell([seqB()], ['quando' => 'antes_pix'])))) === 1);
confere('sequências: fluxo salvo antes (sem extras) é uma sequência só; sem downsell, nenhuma', count(sequenciasDownsellBasico(dadosFluxo(downsell()))) === 1 && sequenciasDownsellBasico(['ofertas' => []]) === []);
confere('sequências: no máximo 5 no total (a 1ª + 4 extras) e item que não é lista é ignorado', count(sequenciasDownsellBasico(dadosFluxo(downsell(array_merge(array_fill(0, 7, seqB()), ['lixo']))))) === 5);

// ---------- planos criados no Downsell ----------
$criados = planosProprioDoDownsellBasico(seqC(), 2);
confere('planos criados: id "dn<sequência>_<posição>", valor digitado e marcados como exclusivos da oferta', array_keys($criados) === [0, 1] && $criados[0]['id'] === 'dn3_1' && $criados[1]['id'] === 'dn3_2'
    && $criados[0]['valor'] === 9.9 && !empty($criados[0]['exclusivo_oferta']) && $criados[0]['cobranca'] === 'unica', json_encode($criados));
confere('planos criados: tempo de acesso, unidade e entrega de cada um', $criados[0]['dias_acesso'] === 3 && $criados[0]['unidade_acesso'] === 'horas' && $criados[0]['entrega_tipo'] === 'link'
    && $criados[0]['link_entrega'] === 'https://x.exemplo.com/a' && $criados[1]['entrega_tipo'] === 'mensagem' && $criados[1]['mensagem_entrega'] === 'Oi {nome}');
confere('planos criados: sem "criar_planos" ligado não devolve nada (os planos escondidos não valem)', planosProprioDoDownsellBasico(seqC(['criar_planos' => false]), 2) === []);
$incompletos = planosProprioDoDownsellBasico(seqC(['planos_proprios' => [['nome' => '', 'valor' => 5], PROPRIOS[0], ['nome' => 'Sem valor', 'valor' => 0], 'lixo']]), 0);
confere('planos criados: plano sem nome, sem valor ou que não é lista não é oferecido, e a posição dos outros não muda', array_keys($incompletos) === [1] && $incompletos[1]['id'] === 'dn1_2', json_encode(array_keys($incompletos)));
$estranho = planosProprioDoDownsellBasico(seqC(['planos_proprios' => [['nome' => 'X', 'valor' => 3, 'dias_acesso' => 0, 'unidade_acesso' => 'anos', 'entrega_tipo' => 'banana', 'id_grupo' => '-100']]]), 0);
confere('planos criados: tempo inválido vira 1+, unidade desconhecida vira dias e entrega desconhecida vira "nenhuma" (não entrega o grupo que sobrou)', $estranho[0]['dias_acesso'] === 1 && $estranho[0]['unidade_acesso'] === 'dias' && $estranho[0]['entrega_tipo'] === 'nenhuma'
    && entregaCompletaDoItemBasico($estranho[0]) === ['id_grupo' => '', 'link_entrega' => '', 'mensagem_entrega' => ''], json_encode($estranho));
confere('planos criados: no máximo 10 por sequência', count(planosProprioDoDownsellBasico(seqC(['planos_proprios' => array_fill(0, 14, PROPRIOS[0])]), 0)) === 10);
confere('planos criados: a entrega de cada um vem do próprio plano (link / mensagem)', entregaCompletaDoItemBasico($criados[0])['link_entrega'] === 'https://x.exemplo.com/a' && entregaCompletaDoItemBasico($criados[1])['mensagem_entrega'] === 'Oi {nome}');

// ---------- posições dos botões ----------
confere('posições: a 1ª sequência mantém as de sempre (0, 1...), então botões de mensagens já enviadas valem', array_keys(planosDaSequenciaDownsellBasico($dados, 0)) === [0, 1]);
confere('posições: a 2ª sequência começa em 100 e os planos criados da 3ª, em 250 (200 + 50)', array_keys(planosDaSequenciaDownsellBasico($dados, 1)) === [100] && array_keys(planosDaSequenciaDownsellBasico($dados, 2)) === [250, 251]);
confere('posições: todos juntos (é o que o toque do cliente procura)', array_keys(planosDoDownsellBasico($dados)) === [0, 1, 100, 250, 251]);
confere('posições: a posição do botão leva à oferta da sequência (mensagem, desconto)', ofertaDaPosicaoDownsellBasico($dados, 100)['mensagem'] === 'Oferta B' && ofertaDaPosicaoDownsellBasico($dados, 251)['mensagem'] === 'Oferta C'
    && ofertaDaPosicaoDownsellBasico($dados, 1)['mensagem'] === 'Oferta A' && ofertaDaPosicaoDownsellBasico($dados, 999)['mensagem'] === 'Oferta A');
confere('posições: sequência com "Criar planos" ligado ignora os planos marcados que ficaram escondidos', array_keys(planosDaSequenciaDownsellBasico(dadosFluxo(downsell([seqC(['planos_oferecidos' => ['p1', 'p2', 'p3']])])), 1)) === [150, 151]);
confere('posições: Downsell desligado não oferece nada', planosDoDownsellBasico(dadosFluxo(downsell([seqB()], ['ativo' => false]))) === []);
confere('candidatos: o plano que o lead escolheu sai da sequência dele; os planos criados nunca saem', array_keys(planosDoDownsellSemOEscolhidoBasico($dados, 'p1', 0)) === [1] && array_keys(planosDoDownsellSemOEscolhidoBasico($dados, 'p1')) === [1, 100, 250, 251]);

// ---------- o envio de cada sequência ----------
$GLOBALS['enviados'] = [];
enviarDownsellBasico('T', 777, $dados, planosDaSequenciaDownsellBasico($dados, 1), 'p1', '', 1);
confere('envio da 2ª sequência: a mensagem e o desconto são os dela, com o botão na posição 100', mensagens() === ['Oferta B'] && botoesEnviados() === [['Vitalício — R$ 60,00 (-40%)', 'basico::of::dn::p1::p100']], json_encode(botoesEnviados(), JSON_UNESCAPED_UNICODE));
$GLOBALS['enviados'] = [];
enviarDownsellBasico('T', 777, $dados, planosDaSequenciaDownsellBasico($dados, 2), 'p1', '', 2);
confere('envio da 3ª (planos criados): o botão mostra o valor digitado, SEM o desconto da sequência (90%), nas posições 250 e 251', botoesEnviados() === [['Relâmpago — R$ 9,90', 'basico::of::dn::p1::p250'], ['Bônus — R$ 5,00', 'basico::of::dn::p1::p251']], json_encode(botoesEnviados(), JSON_UNESCAPED_UNICODE));
$GLOBALS['enviados'] = [];
enviarDownsellBasico('T', 777, $dados, planosDaSequenciaDownsellBasico($dados, 0), 'p3');
confere('envio da 1ª sequência (sem o índice): igual a antes — mensagem A, 20% de desconto e a posição de sempre', mensagens() === ['Oferta A'] && botoesEnviados()[0] === ['Mensal — R$ 16,00 (-20%)', 'basico::of::dn::p3::p0'], json_encode(botoesEnviados(), JSON_UNESCAPED_UNICODE));
$GLOBALS['enviados'] = [];
enviarDownsellBasico('T', 777, $dados, planosDaSequenciaDownsellBasico($dados, 2), 'p1', '', 2);
$mensagem_padrao = dadosFluxo(downsell([seqC(['mensagem' => ''])]));
$GLOBALS['enviados'] = [];
enviarDownsellBasico('T', 777, $mensagem_padrao, planosDaSequenciaDownsellBasico($mensagem_padrao, 1), 'p1', '', 1);
confere('envio: sequência sem mensagem usa o texto padrão ("Que tal uma destas opções?" com mais de um botão)', mensagens() === ['Que tal uma destas opções?'], json_encode(mensagens()));

// ---------- agenda e cron ----------
ligarBot($pdo, downsell([seqB(), seqC()]));
reiniciar($pdo);
agendarDownsellBasico($pdo, 1, '777', 601, 'p1', dadosFluxo(downsell([seqB(), seqC()])));
$linhas = agenda($pdo);
confere('agenda: o 1º envio é daqui a ~10 minutos (a espera da 1ª sequência)', count($linhas) === 1 && abs(minutosAte($linhas[0]) - 10) <= 1, json_encode($linhas));

confere('cron: 1º envio — a sequência A, só com o Trimestral (o Mensal o lead já escolheu)', cron($pdo) === 1 && mensagens() === ['Oferta A'] && botoesEnviados() === [['Trimestral — R$ 40,00 (-20%)', 'basico::of::dn::p1::p1']], json_encode([mensagens(), botoesEnviados()], JSON_UNESCAPED_UNICODE));
$linha = agenda($pdo)[0];
confere('cron: depois do 1º envio fica pendente, com 1 enviado e a próxima daqui a ~30 minutos (a espera da sequência B, contada do envio anterior)', $linha['status'] === 'pendente' && (int) $linha['enviados'] === 1 && abs(minutosAte($linha) - 30) <= 1, json_encode($linha));
confere('cron: 2º envio — a sequência B, com o desconto e o plano dela', cron($pdo) === 1 && mensagens() === ['Oferta B'] && botoesEnviados() === [['Vitalício — R$ 60,00 (-40%)', 'basico::of::dn::p1::p100']], json_encode(botoesEnviados(), JSON_UNESCAPED_UNICODE));
$linha = agenda($pdo)[0];
confere('cron: depois do 2º envio a próxima é daqui a ~45 minutos (a espera da C)', (int) $linha['enviados'] === 2 && $linha['status'] === 'pendente' && abs(minutosAte($linha) - 45) <= 1, json_encode($linha));
confere('cron: 3º envio — a sequência C, com os dois planos criados (valor digitado)', cron($pdo) === 1 && mensagens() === ['Oferta C'] && count(botoesEnviados()) === 2 && botoesEnviados()[0][0] === 'Relâmpago — R$ 9,90', json_encode(botoesEnviados(), JSON_UNESCAPED_UNICODE));
$linha = agenda($pdo)[0];
confere('cron: depois da última sequência a linha termina (concluída, 3 enviados) e nada mais é enviado', $linha['status'] === 'concluido' && (int) $linha['enviados'] === 3 && cron($pdo) === 0 && $GLOBALS['enviados'] === [], json_encode($linha));

reiniciar($pdo);
agendarDownsellBasico($pdo, 1, '777', 602, 'p1', dadosFluxo(downsell([seqB(), seqC()])));
cron($pdo);
$pdo->exec("INSERT INTO vendas (bot_id, id_telegram, status, id) VALUES (1, '777', 'pago', 700)");
confere('cron: o lead pagou entre um envio e outro — a sequência para, sem mandar a próxima', cron($pdo) === 0 && $GLOBALS['enviados'] === [] && agenda($pdo)[0]['status'] === 'concluido');

// sequência sem plano para oferecer: é pulada, mas a seguinte sai no horário dela
reiniciar($pdo);
$com_buraco = downsell([seqB(['planos_oferecidos' => ['p1']]), seqC()]);
ligarBot($pdo, $com_buraco);
agendarDownsellBasico($pdo, 1, '777', 603, 'p1', dadosFluxo($com_buraco));
cron($pdo);   // A
$GLOBALS['enviados'] = [];
$enviou_b = cron($pdo);   // B só tem o plano que o lead já escolheu: nada para mandar
confere('cron: sequência sem plano para oferecer (só tem o que o lead já escolheu) é pulada, sem mensagem', $enviou_b === 0 && $GLOBALS['enviados'] === [] && (int) agenda($pdo)[0]['enviados'] === 2 && agenda($pdo)[0]['status'] === 'pendente', json_encode(agenda($pdo)));
confere('cron: e a seguinte (C) sai no horário dela', abs(minutosAte(agenda($pdo)[0]) - 45) <= 1 && cron($pdo) === 1 && mensagens() === ['Oferta C']);

// a 1ª sequência sem plano sobrando não impede a agenda se outra sequência tem
reiniciar($pdo);
$a_vazia = downsell([seqB()], ['planos_oferecidos' => ['p1']]);
ligarBot($pdo, $a_vazia);
agendarDownsellBasico($pdo, 1, '777', 604, 'p1', dadosFluxo($a_vazia));
confere('agenda: com a 1ª sequência sem plano sobrando mas a 2ª com plano, a agenda é criada', count(agenda($pdo)) === 1);
confere('cron: a 1ª é pulada e a B sai depois do tempo dela (~30 minutos)', cron($pdo) === 0 && abs(minutosAte(agenda($pdo)[0]) - 30) <= 1 && cron($pdo) === 1 && mensagens() === ['Oferta B']);

// não agenda
reiniciar($pdo);
agendarDownsellBasico($pdo, 1, '777', 605, 'p1', dadosFluxo(downsell([seqB()], ['quando' => 'antes_pix'])));
confere('agenda: em "Antes do Pix" (as extras não valem) não agenda nada', agenda($pdo) === []);
$so_o_escolhido = downsell([seqB(['planos_oferecidos' => ['p1']])], ['planos_oferecidos' => ['p1']]);
agendarDownsellBasico($pdo, 1, '777', 606, 'p1', dadosFluxo($so_o_escolhido));
confere('agenda: nenhuma sequência com plano além do escolhido: não agenda', agenda($pdo) === []);

// fluxo sem sequências extras: o repetir de sempre (quantos envios / a cada N minutos) continua igual
reiniciar($pdo);
$antigo = downsell([], ['envios' => 2, 'intervalo_minutos' => 25]);
ligarBot($pdo, $antigo);
agendarDownsellBasico($pdo, 1, '777', 607, 'p1', dadosFluxo($antigo));
cron($pdo);
confere('sem sequências extras: o 1º envio repete a oferta A e a próxima sai no intervalo de sempre (25 minutos)', mensagens() === ['Oferta A'] && abs(minutosAte(agenda($pdo)[0]) - 25) <= 1, json_encode(agenda($pdo)));
cron($pdo);
confere('sem sequências extras: o 2º envio é a mesma oferta A e a linha termina (envios = 2)', mensagens() === ['Oferta A'] && agenda($pdo)[0]['status'] === 'concluido');
// com extras, o "quantos envios" da 1ª sequência não repete: cada sequência sai uma vez
reiniciar($pdo);
$com_envios = downsell([seqB()], ['envios' => 3, 'intervalo_minutos' => 5]);
ligarBot($pdo, $com_envios);
agendarDownsellBasico($pdo, 1, '777', 608, 'p1', dadosFluxo($com_envios));
cron($pdo);
confere('com sequências extras: o "quantos envios" da 1ª não repete a A — a próxima é a B, no tempo dela (30 minutos, não 5)', mensagens() === ['Oferta A'] && abs(minutosAte(agenda($pdo)[0]) - 30) <= 1, json_encode(agenda($pdo)));

// ---------- validações de salvar ----------
confere('salvar: sem planos criados e sem "criar_planos" não há o que validar aqui', validarPlanosProprioDownsellBasico(dadosFluxo(downsell([seqB()]))) === null);
confere('salvar: "Criar planos" ligado sem nenhum plano pede pelo menos 1 e cita o Downsell certo', validarPlanosProprioDownsellBasico(dadosFluxo(downsell([seqC(['planos_proprios' => []])]))) === 'Crie pelo menos 1 plano no Downsell 2 (ou desligue "Criar planos" e marque os planos que já existem).', (string) validarPlanosProprioDownsellBasico(dadosFluxo(downsell([seqC(['planos_proprios' => []])]))));
confere('salvar: Downsell único com "Criar planos" e nenhum plano: o texto não numera', strpos((string) validarPlanosProprioDownsellBasico(dadosFluxo(downsell([], ['criar_planos' => true, 'planos_proprios' => []]))), 'no Downsell (') !== false);
confere('salvar: plano sem nome, sem valor ou sem tempo de acesso é recusado, dizendo qual', validarPlanosProprioDownsellBasico(dadosFluxo(downsell([seqC(['planos_proprios' => [['nome' => ' ', 'valor' => 5, 'dias_acesso' => 3]]])]))) === 'Dê um nome ao plano 1 do Downsell 2.'
    && validarPlanosProprioDownsellBasico(dadosFluxo(downsell([seqC(['planos_proprios' => [['nome' => 'Bônus', 'valor' => 0, 'dias_acesso' => 3]]])]))) === 'O plano "Bônus" do Downsell 2 precisa ter valor maior que zero.'
    && validarPlanosProprioDownsellBasico(dadosFluxo(downsell([seqC(['planos_proprios' => [['nome' => 'Bônus', 'valor' => 3, 'dias_acesso' => 0]]])]))) === 'O plano "Bônus" do Downsell 2 precisa ter um tempo de acesso de pelo menos 1.');
confere('salvar: mais de 10 planos criados numa sequência é recusado', strpos((string) validarPlanosProprioDownsellBasico(dadosFluxo(downsell([seqC(['planos_proprios' => array_fill(0, 11, PROPRIOS[0])])]))), 'no máximo 10') !== false);
confere('salvar: planos criados em Downsell desligado não são conferidos', validarPlanosProprioDownsellBasico(dadosFluxo(downsell([seqC(['planos_proprios' => []])], ['ativo' => false]))) === null);
confere('salvar: planos criados válidos passam', validarPlanosProprioDownsellBasico($dados) === null);

confere('entrega: tudo certo passa', validarEntregasFluxoGuiado($dados) === null, (string) validarEntregasFluxoGuiado($dados));
$link_ruim = dadosFluxo(downsell([seqB(['entrega_tipo' => 'link', 'link_entrega' => 'x y'])]));
confere('entrega: link inválido na 2ª sequência é recusado e cita "Downsell (sequência 2)"', strpos((string) validarEntregasFluxoGuiado($link_ruim), 'do Downsell (sequência 2)') !== false, (string) validarEntregasFluxoGuiado($link_ruim));
$grupo_ruim = dadosFluxo(downsell([seqC(['planos_proprios' => [['nome' => 'Vip', 'valor' => 5, 'dias_acesso' => 3, 'entrega_tipo' => 'grupo', 'id_grupo' => '']]])]));
confere('entrega: plano criado com "Acesso a grupo" sem grupo é recusado, citando o plano', strpos((string) validarEntregasFluxoGuiado($grupo_ruim), 'do plano do Downsell "Vip"') !== false, (string) validarEntregasFluxoGuiado($grupo_ruim));
$msg_vazia = dadosFluxo(downsell([seqC(['planos_proprios' => [['nome' => 'Texto', 'valor' => 5, 'dias_acesso' => 3, 'entrega_tipo' => 'mensagem', 'mensagem_entrega' => ' ']]])]));
confere('entrega: plano criado com "Mensagem" vazia é recusado', strpos((string) validarEntregasFluxoGuiado($msg_vazia), 'Escreva a mensagem da entrega do plano do Downsell "Texto"') !== false, (string) validarEntregasFluxoGuiado($msg_vazia));
$escondido = dadosFluxo(downsell([seqC(['entrega_tipo' => 'link', 'link_entrega' => 'x y'])]));
confere('entrega: com "Criar planos" ligado a entrega da sequência (escondida na tela) não trava o salvamento', validarEntregasFluxoGuiado($escondido) === null, (string) validarEntregasFluxoGuiado($escondido));
$extra_ignorada = dadosFluxo(downsell([seqB(['entrega_tipo' => 'link', 'link_entrega' => 'x y'])], ['quando' => 'antes_pix']));
confere('entrega: em "Antes do Pix" as sequências extras não valem e não são conferidas', validarEntregasFluxoGuiado($extra_ignorada) === null);

echo PHP_EOL . "$ok ok, $falhas falha(s)" . PHP_EOL;
exit($falhas > 0 ? 1 : 0);
