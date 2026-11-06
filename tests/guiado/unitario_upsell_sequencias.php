<?php
declare(strict_types=1);

/**
 * Upsell em sequência (06/11/2026, pedido do Lima: "sequência de upsell, só está dando para colocar um; pagou um, vai pro outro").
 * O 1º upsell é o próprio ofertas.upsell; os outros ficam em ofertas.upsell.sequencias_extras. Depois de pagar o plano vem o 1º; depois de pagar um upsell, o seguinte.
 * Sem rede e sem MySQL: SQLite em memória e um Telegram falso.
 *   php tests/guiado/unitario_upsell_sequencias.php   (sai com código 1 se algo falhar)
 */

$ok = 0;
$falhas = 0;
function confere(string $nome, bool $certo, string $detalhe = ''): void
{
    global $ok, $falhas;
    $certo ? $ok++ : $falhas++;
    echo ($certo ? 'OK     ' : 'FALHOU ') . $nome . ($certo || $detalhe === '' ? '' : '  -> ' . $detalhe) . PHP_EOL;
}

$GLOBALS['enviados'] = [];
$GLOBALS['pix_gerados'] = [];
function requisicaoTelegram(string $token, string $metodo, array $parametros = [], array $arquivos = []): array
{
    $GLOBALS['enviados'][] = ['metodo' => $metodo] + $parametros;
    return ['ok' => true, 'result' => []];
}
function resolverCaminhoUploadSeguro(string $caminho): ?string
{
    return null;
}
function processarEEnviarBloco(string $token, $id_chat, array $operador, string $id_operador = '', string $documento_comprador = ''): void
{
    $GLOBALS['pix_gerados'][] = $operador['properties'] ?? [];
}
require_once dirname(__DIR__, 2) . '/funcoes/guiado_ofertas.php';
require_once dirname(__DIR__, 2) . '/funcoes/pagamentos_guiado.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE fluxos (id INTEGER PRIMARY KEY, modo TEXT, dados_fluxograma TEXT)');
$pdo->exec('CREATE TABLE bots (id INTEGER PRIMARY KEY, id_fluxo_conectado INTEGER)');
$pdo->exec('CREATE TABLE remarketing_campanhas (id INTEGER PRIMARY KEY, ofertas TEXT)');
$pdo->exec('CREATE TABLE remarketing_vendas (id INTEGER PRIMARY KEY, venda_id INTEGER, campanha_id INTEGER)');
$pdo->exec('CREATE TABLE leads (id_telegram TEXT, bot_id INTEGER, nome TEXT)');

function planosBase(): array
{
    return [
        ['id' => 'p1', 'nome' => 'Mensal', 'valor' => 19.9, 'id_grupo' => '-100111', 'dias_acesso' => 30, 'unidade_acesso' => 'dias'],
        ['id' => 'p2', 'nome' => 'Vitalício', 'valor' => 97.0, 'id_grupo' => '-100222', 'dias_acesso' => 36500, 'unidade_acesso' => 'dias'],
        ['id' => 'p3', 'nome' => 'Assinatura', 'valor' => 29.9, 'cobranca' => 'recorrente', 'periodicidade' => 'mensal'],
        ['id' => 'p4', 'nome' => 'Trimestral', 'valor' => 49.9, 'id_grupo' => '-100444', 'dias_acesso' => 90, 'unidade_acesso' => 'dias'],
    ];
}
function fluxo(PDO $pdo, array $ofertas): int
{
    $dados = ['ativo' => true, 'planos' => planosBase(), 'ofertas' => $ofertas, 'pagamentos' => []];
    $pdo->prepare('INSERT INTO fluxos (modo, dados_fluxograma) VALUES (?, ?)')->execute(['basico', json_encode($dados)]);
    $id = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO bots (id, id_fluxo_conectado) VALUES (?, ?)')->execute([$id, $id]);
    return $id;
}
function venda(int $id_fluxo, string $nome): array
{
    return ['id' => 1, 'bot_id' => $id_fluxo, 'id_fluxo' => $id_fluxo, 'id_telegram' => '777', 'nome_produto' => $nome, 'id_operador_fluxo' => null, 'venda_pai_id' => null, 'tipo_cobranca' => 'unica'];
}
function limpar(): void
{
    $GLOBALS['enviados'] = [];
    $GLOBALS['pix_gerados'] = [];
}
function textos(): array
{
    return array_values(array_map(fn($e) => (string) ($e['text'] ?? $e['caption'] ?? ''), array_filter($GLOBALS['enviados'], fn($e) => in_array($e['metodo'], ['sendMessage', 'sendPhoto', 'sendVideo'], true))));
}
function botoes(int $i): array
{
    $e = array_values($GLOBALS['enviados'])[$i] ?? [];
    $t = json_decode((string) ($e['reply_markup'] ?? '{}'), true);
    $saida = [];
    foreach ($t['inline_keyboard'] ?? [] as $linha) {
        foreach ($linha as $b) {
            $saida[] = $b['callback_data'] ?? '';
        }
    }
    return $saida;
}
function dadosDoFluxo(PDO $pdo, int $id): array
{
    return json_decode((string) $pdo->query("SELECT dados_fluxograma FROM fluxos WHERE id = $id")->fetchColumn(), true);
}

$up1 = ['ativo' => true, 'id_plano_destino' => 'p2', 'desconto_percentual' => 10, 'mensagem' => 'Leve o vitalício!', 'texto_aceitar' => 'Quero!', 'texto_recusar' => 'Não'];
$up2 = ['id_plano_destino' => 'up_exclusivo', 'plano_proprio' => ['nome' => 'Pacote Hot', 'valor' => 39.9, 'dias_acesso' => 30, 'unidade_acesso' => 'dias'],
    'mensagem' => 'Mais um pacote só para você', 'texto_aceitar' => 'Adicionar', 'texto_recusar' => 'Agora não', 'entrega_tipo' => 'link', 'link_entrega' => 'https://area.exemplo.com/hot'];
$up3 = ['id_plano_destino' => 'p4', 'desconto_percentual' => 20, 'mensagem' => 'Trimestral com desconto'];
$f = fluxo($pdo, ['upsell' => $up1 + ['sequencias_extras' => [$up2, $up3]]]);
$dados = dadosDoFluxo($pdo, $f);

// ---------- a lista de sequências ----------
confere('sequenciasUpsellBasico: 3 upsells (o 1º é o próprio ofertas.upsell, sem as extras dentro)', count(sequenciasUpsellBasico($dados)) === 3 && !isset(sequenciasUpsellBasico($dados)[0]['sequencias_extras']));
confere('fluxo antigo (sem sequencias_extras): 1 upsell só', count(sequenciasUpsellBasico(dadosDoFluxo($pdo, fluxo($pdo, ['upsell' => $up1])))) === 1);
$desligado = $dados;
$desligado['ofertas']['upsell']['ativo'] = false;
confere('com o Upsell 1 desligado, os extras não valem', count(sequenciasUpsellBasico($desligado)) === 1 && planoDaSequenciaUpsellBasico($desligado, 1) === null);
confere('plano do Upsell 2 (próprio) tem id up_exclusivo_2', (planoDaSequenciaUpsellBasico($dados, 1)['id'] ?? '') === 'up_exclusivo_2');
confere('plano do Upsell 1 continua "p2"', (planoDaSequenciaUpsellBasico($dados, 0)['id'] ?? '') === 'p2');
confere('plano do Upsell 3 é o da lista (p4)', (planoDaSequenciaUpsellBasico($dados, 2)['id'] ?? '') === 'p4');
$muitos = ['upsell' => $up1 + ['sequencias_extras' => array_fill(0, 30, $up3)]];
confere('no máximo ' . UPSELL_MAXIMO_SEQUENCIAS . ' upsells', count(sequenciasUpsellBasico(['ofertas' => $muitos])) === UPSELL_MAXIMO_SEQUENCIAS);

// ---------- pagou o plano: recebe só o 1º ----------
limpar();
ofertasPosPagamentoBasico($pdo, 'TOKEN', venda($f, 'Mensal'));
$t = textos();
confere('pagou o plano: recebe só o Upsell 1', count($t) === 1 && str_contains($t[0], 'Leve o vitalício'), json_encode($t, JSON_UNESCAPED_UNICODE));
confere('Upsell 1: botões "up" (como sempre)', botoes(0) === ['basico::pos::up::p2::1', 'basico::pos::up::p2::0'], json_encode(botoes(0)));

// ---------- pagou o Upsell 1: vem o 2 ----------
limpar();
ofertasPosPagamentoBasico($pdo, 'TOKEN', venda($f, 'Upgrade: Vitalício'));
$t = textos();
confere('pagou o Upsell 1: recebe o Upsell 2 (e nada mais)', count($t) === 1 && str_contains($t[0], 'Mais um pacote'), json_encode($t, JSON_UNESCAPED_UNICODE));
confere('Upsell 2: botões "u2" com o id do plano próprio e os textos configurados', botoes(0) === ['basico::pos::u2::up_exclusivo_2::1', 'basico::pos::u2::up_exclusivo_2::0'], json_encode(botoes(0)));
$botoes_up2 = json_decode((string) ($GLOBALS['enviados'][0]['reply_markup'] ?? '{}'), true)['inline_keyboard'][0] ?? [];
confere('Upsell 2: textos dos botões', ($botoes_up2[0]['text'] ?? '') === 'Adicionar' && ($botoes_up2[1]['text'] ?? '') === 'Agora não');

// ---------- pagou o Upsell 2 (plano próprio): vem o 3 ----------
limpar();
ofertasPosPagamentoBasico($pdo, 'TOKEN', venda($f, 'Upgrade: Pacote Hot'));
$t = textos();
confere('pagou o Upsell 2: recebe o Upsell 3 (a mensagem escrita pelo dono)', count($t) === 1 && str_contains($t[0], 'Trimestral'), json_encode($t, JSON_UNESCAPED_UNICODE));
confere('Upsell 3: botões "u3"', botoes(0) === ['basico::pos::u3::p4::1', 'basico::pos::u3::p4::0'], json_encode(botoes(0)));

// ---------- pagou o último: acabou ----------
limpar();
ofertasPosPagamentoBasico($pdo, 'TOKEN', venda($f, 'Upgrade: Trimestral'));
confere('pagou o último upsell: não recebe mais nada', textos() === [], json_encode(textos(), JSON_UNESCAPED_UNICODE));
limpar();
ofertasPosPagamentoBasico($pdo, 'TOKEN', venda($f, 'Upgrade: Plano que não existe mais'));
confere('upgrade de plano que sumiu/foi renomeado: a sequência para (nada é enviado)', textos() === []);

// ---------- aceitar: o Pix de cada um ----------
limpar();
gerarPixOfertaPosBasico('TOKEN', '777', $dados, 'u2', 'up_exclusivo_2');
$p = $GLOBALS['pix_gerados'][0] ?? [];
confere('aceitar o Upsell 2: Pix "Upgrade: Pacote Hot" de R$ 39,90 (plano próprio, sem desconto)', ($p['nome'] ?? '') === 'Upgrade: Pacote Hot' && (float) ($p['valor'] ?? 0) === 39.9, json_encode($p, JSON_UNESCAPED_UNICODE));
confere('aceitar o Upsell 2: entrega pelo link do próprio upsell', ($p['link_entrega'] ?? '') === 'https://area.exemplo.com/hot' && ($p['id_grupo'] ?? 'x') === '', json_encode($p, JSON_UNESCAPED_UNICODE));
limpar();
gerarPixOfertaPosBasico('TOKEN', '777', $dados, 'u3', 'p4');
$p = $GLOBALS['pix_gerados'][0] ?? [];
confere('aceitar o Upsell 3: Pix "Upgrade: Trimestral" com 20% de desconto (R$ 39,92) e o grupo do plano', ($p['nome'] ?? '') === 'Upgrade: Trimestral' && (float) ($p['valor'] ?? 0) === 39.92 && ($p['id_grupo'] ?? '') === '-100444', json_encode($p, JSON_UNESCAPED_UNICODE));
limpar();
gerarPixOfertaPosBasico('TOKEN', '777', $dados, 'up', 'p2');
$p = $GLOBALS['pix_gerados'][0] ?? [];
confere('aceitar o Upsell 1 (callback "up", como sempre): Pix de R$ 87,30', ($p['nome'] ?? '') === 'Upgrade: Vitalício' && (float) ($p['valor'] ?? 0) === 87.3, json_encode($p, JSON_UNESCAPED_UNICODE));
limpar();
gerarPixOfertaPosBasico('TOKEN', '777', $dados, 'u2', 'p4');
confere('botão do Upsell 2 com id de plano que não é o dele: "não está mais disponível", sem Pix', $GLOBALS['pix_gerados'] === [] && str_contains(textos()[0] ?? '', 'não está mais'), json_encode(textos(), JSON_UNESCAPED_UNICODE));
limpar();
gerarPixOfertaPosBasico('TOKEN', '777', $dados, 'u9', 'p4');
confere('botão de upsell que não existe (u9): sem Pix', $GLOBALS['pix_gerados'] === []);

// ---------- pular upsell sem plano ----------
$apagado = $dados;
$apagado['ofertas']['upsell']['sequencias_extras'][0]['id_plano_destino'] = 'p999';   // o plano da lista foi apagado
limpar();
ofertasPosPagamentoBasico($pdo, 'TOKEN', venda(fluxoComDados($pdo, $apagado), 'Upgrade: Vitalício'));
$t = textos();
confere('Upsell 2 sem plano válido é pulado: vai direto ao 3', count($t) === 1 && str_contains($t[0], 'Trimestral'), json_encode($t, JSON_UNESCAPED_UNICODE));
function fluxoComDados(PDO $pdo, array $dados): int
{
    $pdo->prepare('INSERT INTO fluxos (modo, dados_fluxograma) VALUES (?, ?)')->execute(['basico', json_encode($dados)]);
    $id = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO bots (id, id_fluxo_conectado) VALUES (?, ?)')->execute([$id, $id]);
    return $id;
}

// ---------- plano recorrente e o mesmo plano que acabou de pagar ----------
$rec = $dados;
$rec['ofertas']['upsell']['sequencias_extras'][0] = ['id_plano_destino' => 'p3', 'mensagem' => 'assinatura'];
limpar();
ofertasPosPagamentoBasico($pdo, 'TOKEN', venda(fluxoComDados($pdo, $rec), 'Upgrade: Vitalício'));
confere('upsell de plano recorrente não é oferecido (vai ao seguinte)', count(textos()) === 1 && str_contains(textos()[0], 'Trimestral'), json_encode(textos(), JSON_UNESCAPED_UNICODE));

// ---------- validações de salvar ----------
confere('validação: fluxo certo passa', validarPlanoProprioUpsellBasico($dados) === null);
$ruim = $dados;
$ruim['ofertas']['upsell']['sequencias_extras'][0]['plano_proprio']['nome'] = '';
confere('validação: plano do Upsell 2 sem nome é recusado, citando o "Upsell 2"', str_contains((string) validarPlanoProprioUpsellBasico($ruim), 'Upsell 2'), (string) validarPlanoProprioUpsellBasico($ruim));
$ruim = $dados;
$ruim['ofertas']['upsell']['sequencias_extras'][0]['plano_proprio']['valor'] = 0;
confere('validação: plano do Upsell 2 sem valor é recusado', str_contains((string) validarPlanoProprioUpsellBasico($ruim), 'valor maior que zero'));
$ruim = $dados;
$ruim['ofertas']['upsell']['sequencias_extras'][1]['id_plano_destino'] = '';
confere('validação: Upsell 3 sem plano escolhido é recusado', str_contains((string) validarPlanoProprioUpsellBasico($ruim), 'Upsell 3'), (string) validarPlanoProprioUpsellBasico($ruim));
$ruim = $dados;
$ruim['ofertas']['upsell']['sequencias_extras'][0]['link_entrega'] = 'isso nao e link';
confere('validação da entrega: link inválido no Upsell 2 é recusado', is_string(validarEntregasFluxoGuiado($ruim)) && str_contains((string) validarEntregasFluxoGuiado($ruim), 'Upsell 2'), (string) validarEntregasFluxoGuiado($ruim));
confere('validação da entrega: fluxo certo passa', validarEntregasFluxoGuiado($dados) === null, (string) validarEntregasFluxoGuiado($dados));

// Desconto em R$ fixo no Upsell (06/11/2026): vale como no Downsell; plano criado só para o upsell nunca tem desconto
$fx = ['ativo' => true, 'id_plano_destino' => 'a', 'desconto_tipo' => 'fixo', 'desconto_valor' => 5, 'desconto_percentual' => 0];
$ok_fx = [valorComDescontoDaOfertaBasico($fx, 20.0) === 15.0, valorComDescontoDaOfertaBasico($fx, 3.0) === 0.0, textoDescontoDaOfertaBasico($fx) === 'R$ 5,00',
    valorComDescontoDaOfertaBasico(['id_plano_destino' => 'up_exclusivo_2', 'desconto_tipo' => 'fixo', 'desconto_valor' => 5, 'plano_proprio' => ['valor' => 9]], 9.0) === 9.0,
    valorComDescontoDaOfertaBasico(['id_plano_destino' => 'a', 'desconto_percentual' => 50], 20.0) === 10.0];
confere("desconto fixo no upsell", $ok_fx === [true, true, true, true, true], json_encode($ok_fx));

echo PHP_EOL . "$ok ok, $falhas falha(s)" . PHP_EOL;
exit($falhas > 0 ? 1 : 0);
