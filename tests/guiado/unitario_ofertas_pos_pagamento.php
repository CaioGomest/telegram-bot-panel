<?php
declare(strict_types=1);

/**
 * Ofertas DEPOIS do pagamento no modo Guiado (funcoes/guiado_ofertas.php): quem recebe, o que recebe e o que o "aceitar" gera.
 * Sem rede e sem MySQL: banco SQLite em memória e um Telegram falso que só anota o que seria enviado.
 *   php tests/guiado/unitario_ofertas_pos_pagamento.php
 */

$ok = 0;
$falhas = 0;
function confere(string $nome, bool $certo, string $detalhe = ''): void
{
    global $ok, $falhas;
    $certo ? $ok++ : $falhas++;
    echo ($certo ? 'OK     ' : 'FALHOU ') . $nome . ($certo || $detalhe === '' ? '' : '  -> ' . $detalhe) . PHP_EOL;
}

// ---- dublês do que vem de funcoes/fluxo_blocos.php
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
require_once dirname(__DIR__, 2) . '/funcoes/pagamentos_guiado.php';   // propriedadesPagamentoBasico(): o que a aba Pagamentos entrega ao bloco Pix

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE fluxos (id INTEGER PRIMARY KEY, modo TEXT, dados_fluxograma TEXT)');
$pdo->exec('CREATE TABLE bots (id INTEGER PRIMARY KEY, id_fluxo_conectado INTEGER)');
// o Remarketing (funcoes/remarketing_ofertas.php) pergunta se a venda pertence a uma campanha com order bump próprio: precisa destas duas tabelas (vazias aqui)
$pdo->exec('CREATE TABLE remarketing_campanhas (id INTEGER PRIMARY KEY, ofertas TEXT)');
$pdo->exec('CREATE TABLE remarketing_vendas (id INTEGER PRIMARY KEY, venda_id INTEGER, campanha_id INTEGER)');
// a mensagem de entrega troca {nome} pelo primeiro nome do lead
$pdo->exec('CREATE TABLE leads (id_telegram TEXT, bot_id INTEGER, nome TEXT)');

function fluxo(PDO $pdo, array $ofertas, array $extra = []): int
{
    $dados = $extra + [
        'ativo' => true,
        'planos' => [
            ['id' => 'p1', 'nome' => 'Mensal', 'valor' => 19.9, 'id_grupo' => '-100111', 'dias_acesso' => 30, 'unidade_acesso' => 'dias'],
            ['id' => 'p2', 'nome' => 'Vitalício', 'valor' => 97.0, 'id_grupo' => '-100222', 'dias_acesso' => 36500, 'unidade_acesso' => 'dias'],
            ['id' => 'p3', 'nome' => 'Assinatura', 'valor' => 29.9, 'cobranca' => 'recorrente', 'periodicidade' => 'mensal'],
        ],
        'ofertas' => $ofertas,
        'pagamentos' => [],
    ];
    $pdo->prepare('INSERT INTO fluxos (modo, dados_fluxograma) VALUES (?, ?)')->execute(['basico', json_encode($dados)]);
    $id = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO bots (id, id_fluxo_conectado) VALUES (?, ?)')->execute([$id, $id]);
    return $id;
}
function venda(int $id_fluxo, array $extra = []): array
{
    return $extra + ['id' => 1, 'bot_id' => $id_fluxo, 'id_fluxo' => $id_fluxo, 'id_telegram' => '777', 'nome_produto' => 'Mensal', 'id_operador_fluxo' => null, 'venda_pai_id' => null, 'tipo_cobranca' => 'unica'];
}
function limpar(): void
{
    $GLOBALS['enviados'] = [];
    $GLOBALS['pix_gerados'] = [];
}
function textos(): array
{
    return array_map(fn($e) => (string) ($e['text'] ?? $e['caption'] ?? ''), array_filter($GLOBALS['enviados'], fn($e) => in_array($e['metodo'], ['sendMessage', 'sendPhoto', 'sendVideo'], true)));
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

$upsell = ['ativo' => true, 'id_plano_destino' => 'p2', 'desconto_percentual' => 10, 'mensagem' => '', 'texto_aceitar' => 'Quero!', 'texto_recusar' => 'Não'];
$bump = ['ativo' => true, 'nome' => 'Guia VIP', 'valor_extra' => 9.9, 'mensagem' => ''];

// ---------- quem recebe o quê ----------
$f = fluxo($pdo, ['upsell' => $upsell, 'order_bump' => $bump]);
limpar();
ofertasPosPagamentoBasico($pdo, 'TOKEN', venda($f));
$t = textos();
confere('plano comum pago: o cliente recebe 2 mensagens (upsell e order bump)', count($t) === 2, json_encode($t, JSON_UNESCAPED_UNICODE));
confere('upsell: pergunta o upgrade com o preço JÁ com o desconto (R$ 87,30)', str_contains($t[0] ?? '', 'Vitalício') && str_contains($t[0] ?? '', '87,30'), $t[0] ?? '');
confere('upsell: botões levam ao plano de destino e carregam aceitar/recusar', botoes(0) === ['basico::pos::up::p2::1', 'basico::pos::up::p2::0'], json_encode(botoes(0)));
confere('order bump: oferece o extra por R$ 9,90 com os botões do bump', str_contains($t[1] ?? '', '9,90') && botoes(1) === ['basico::pos::bp::0::1', 'basico::pos::bp::0::0'], json_encode(botoes(1)));

limpar();
ofertasPosPagamentoBasico($pdo, 'TOKEN', venda($f, ['nome_produto' => 'Vitalício']));
confere('quem JÁ pagou o plano do upsell não recebe o upsell (só o order bump)', count(textos()) === 1 && str_contains(textos()[0], 'extra'), json_encode(textos(), JSON_UNESCAPED_UNICODE));

limpar();
ofertasPosPagamentoBasico($pdo, 'TOKEN', venda($f, ['nome_produto' => 'Upgrade: Vitalício']));
ofertasPosPagamentoBasico($pdo, 'TOKEN', venda($f, ['nome_produto' => 'Extra: Guia VIP']));
confere('a venda de uma oferta (Upgrade:/Extra:) NÃO gera nova oferta (sem laço)', count(textos()) === 0);

limpar();
ofertasPosPagamentoBasico($pdo, 'TOKEN', venda($f, ['venda_pai_id' => 55]));
ofertasPosPagamentoBasico($pdo, 'TOKEN', venda($f, ['tipo_cobranca' => 'assinatura']));
ofertasPosPagamentoBasico($pdo, 'TOKEN', venda($f, ['id_operador_fluxo' => 'op_1']));
confere('renovação, assinatura e venda do editor visual não recebem oferta', count(textos()) === 0);

$f2 = fluxo($pdo, ['upsell' => ['ativo' => false] + $upsell, 'order_bump' => ['ativo' => false] + $bump]);
limpar();
ofertasPosPagamentoBasico($pdo, 'TOKEN', venda($f2));
confere('ofertas desligadas: nada é enviado', count(textos()) === 0);

$f3 = fluxo($pdo, ['upsell' => ['esconder_recusar' => true] + $upsell]);
limpar();
ofertasPosPagamentoBasico($pdo, 'TOKEN', venda($f3));
confere('upsell com "esconder recusar": só o botão aceitar', botoes(0) === ['basico::pos::up::p2::1'], json_encode(botoes(0)));

$f4 = fluxo($pdo, ['order_bump' => ['valor_extra' => 0] + $bump]);
limpar();
ofertasPosPagamentoBasico($pdo, 'TOKEN', venda($f4));
confere('order bump com extra de R$ 0: não é oferecido', count(textos()) === 0);

$pdo->prepare('UPDATE fluxos SET modo = ? WHERE id = ?')->execute(['visual', $f]);
limpar();
ofertasPosPagamentoBasico($pdo, 'TOKEN', venda($f));
confere('fluxo do editor visual (modo diferente de "basico"): nada é enviado', count(textos()) === 0);

limpar();
ofertasPosPagamentoBasico($pdo, 'TOKEN', venda(9999));
confere('fluxo inexistente / banco sem a linha: não lança e não envia', count(textos()) === 0);

// ---------- aceitar gera um Pix novo só da oferta ----------
$f5 = fluxo($pdo, ['upsell' => $upsell, 'order_bump' => $bump]);
$dados = json_decode((string) $pdo->query("SELECT dados_fluxograma FROM fluxos WHERE id = $f5")->fetchColumn(), true);
limpar();
gerarPixOfertaPosBasico('TOKEN', '777', $dados, 'up', 'p2');
$p = $GLOBALS['pix_gerados'][0] ?? [];
confere('aceitar o upsell: Pix de R$ 87,30 chamado "Upgrade: Vitalício", com o grupo e o prazo do plano de destino', ($p['valor'] ?? 0) == 87.3 && ($p['nome'] ?? '') === 'Upgrade: Vitalício'
    && ($p['id_grupo'] ?? '') === '-100222' && ($p['dias_acesso'] ?? 0) === 36500 && ($p['tipo_cobranca'] ?? '') === 'unica', json_encode($p, JSON_UNESCAPED_UNICODE));
limpar();
gerarPixOfertaPosBasico('TOKEN', '777', $dados, 'bp', '0');
$p = $GLOBALS['pix_gerados'][0] ?? [];
confere('aceitar o order bump: Pix só do extra (R$ 9,90) chamado "Extra: Guia VIP", sem grupo', ($p['valor'] ?? 0) == 9.9 && ($p['nome'] ?? '') === 'Extra: Guia VIP' && ($p['id_grupo'] ?? 'x') === '', json_encode($p, JSON_UNESCAPED_UNICODE));
limpar();
gerarPixOfertaPosBasico('TOKEN', '777', $dados, 'up', 'p1');
confere('aceitar um upsell cujo plano de destino mudou: não gera Pix e avisa "não está mais disponível"', count($GLOBALS['pix_gerados']) === 0 && str_contains(textos()[0] ?? '', 'não está mais disponível'));
limpar();
gerarPixOfertaPosBasico('TOKEN', '777', $dados, 'xx', '0');
confere('tipo de oferta desconhecido: nada acontece', count($GLOBALS['pix_gerados']) === 0 && count(textos()) === 0);

// ---------- Entrega por mensagem (item 34, 27/10/2026): o texto do plano/oferta vai numa mensagem própria, logo depois do aviso de pagamento aprovado ----------
$f9 = fluxo($pdo, ['upsell' => $upsell, 'order_bump' => $bump]);
$pdo->prepare('INSERT INTO leads (id_telegram, bot_id, nome) VALUES (?, ?, ?)')->execute(['777', $f9, 'Maria Souza']);
limpar();
ofertasPosPagamentoBasico($pdo, 'TOKEN', venda($f9, ['mensagem_entrega' => "Olá {nome}! Seu plano <b>{plano}</b> está liberado.
<script>x</script>"]));
$t = textos();
confere('venda com mensagem de entrega: o cliente recebe a mensagem ANTES das ofertas (3 mensagens: entrega, upsell e order bump)', count($t) === 3 && str_contains($t[0], 'Olá Maria!') && str_contains($t[1], 'upgrade') && str_contains($t[2], 'extra'), json_encode($t, JSON_UNESCAPED_UNICODE));
confere('a mensagem troca {nome} e {plano}, mantém <b> e escapa a tag perigosa', str_contains($t[0], '<b>Mensal</b>') && !str_contains($t[0], '<script>') && str_contains($t[0], '&lt;script&gt;'), $t[0] ?? '');
$e0 = array_values($GLOBALS['enviados'])[0] ?? [];
confere('a mensagem de entrega vai em HTML, para o chat do cliente', ($e0['parse_mode'] ?? '') === 'HTML' && (string) ($e0['chat_id'] ?? '') === '777', json_encode($e0, JSON_UNESCAPED_UNICODE));
limpar();
ofertasPosPagamentoBasico($pdo, 'TOKEN', venda($f9, ['nome_produto' => 'Upgrade: Vitalício', 'mensagem_entrega' => 'Seu upgrade chegou!']));
confere('venda de Upgrade (Upsell) com mensagem: recebe a mensagem e NENHUMA oferta nova', textos() === ['Seu upgrade chegou!'], json_encode(textos(), JSON_UNESCAPED_UNICODE));
limpar();
ofertasPosPagamentoBasico($pdo, 'TOKEN', venda($f9, ['tipo_cobranca' => 'assinatura', 'mensagem_entrega' => 'Bem-vindo à assinatura!']));
confere('1º pagamento de assinatura com mensagem: recebe a mensagem (e nenhuma oferta, como sempre)', textos() === ['Bem-vindo à assinatura!'], json_encode(textos(), JSON_UNESCAPED_UNICODE));
limpar();
ofertasPosPagamentoBasico($pdo, 'TOKEN', venda($f9, ['mensagem_entrega' => '   ']));
confere('mensagem só com espaços: nada além das ofertas de sempre', count(textos()) === 2);
limpar();
ofertasPosPagamentoBasico($pdo, 'TOKEN', venda($f9, ['mensagem_entrega' => null]));
confere('venda sem mensagem de entrega (coluna NULL): as ofertas de sempre', count(textos()) === 2);
limpar();
$ok_msg = pagamentoEnviarMensagemEntrega($pdo, 'TOKEN', venda($f9, ['mensagem_entrega' => str_repeat('a', 5000)]));
confere('mensagem maior que o limite: é cortada em 3000 caracteres (nunca passa do limite do Telegram)', $ok_msg === true && mb_strlen(textos()[0] ?? '') <= 3000, (string) mb_strlen(textos()[0] ?? ''));
limpar();
confere('lead sem nome conhecido: {nome} vira "Cliente"', pagamentoEnviarMensagemEntrega($pdo, 'TOKEN', venda($f9, ['id_telegram' => '999', 'mensagem_entrega' => 'Oi {nome}']) ) === true && textos() === ['Oi Cliente'], json_encode(textos(), JSON_UNESCAPED_UNICODE));

// ---------- Upsell com plano próprio (plano só do Upsell, que não está na lista de planos; item 33, 27/10/2026) ----------
$proprio = ['ativo' => true, 'id_plano_destino' => 'up_exclusivo', 'desconto_percentual' => 50, 'mensagem' => '', 'texto_aceitar' => 'Quero!', 'texto_recusar' => 'Não',
    'plano_proprio' => ['nome' => 'Pacote VIP', 'valor' => 49.9, 'dias_acesso' => 12, 'unidade_acesso' => 'horas'], 'entrega_tipo' => 'grupo', 'id_grupo' => '-100333'];
$f6 = fluxo($pdo, ['upsell' => $proprio]);
$dados6 = json_decode((string) $pdo->query("SELECT dados_fluxograma FROM fluxos WHERE id = $f6")->fetchColumn(), true);
confere('o plano próprio não está em "planos" (não aparece em "Ver Planos")', localizarPlanoBasico($dados6['planos'], 'up_exclusivo') === null && count($dados6['planos']) === 3);
limpar();
ofertasPosPagamentoBasico($pdo, 'TOKEN', venda($f6));
$t = textos();
confere('plano próprio: o cliente que pagou o Mensal recebe a oferta de upgrade para "Pacote VIP" por R$ 49,90 (o desconto que sobrou no JSON é ignorado)', count($t) === 1 && str_contains($t[0], 'Pacote VIP') && str_contains($t[0], '49,90'), json_encode($t, JSON_UNESCAPED_UNICODE));
confere('plano próprio: os botões carregam o id do plano próprio', botoes(0) === ['basico::pos::up::up_exclusivo::1', 'basico::pos::up::up_exclusivo::0'], json_encode(botoes(0)));
limpar();
ofertasPosPagamentoBasico($pdo, 'TOKEN', venda($f6, ['nome_produto' => 'Pacote VIP']));
confere('plano próprio: quem JÁ pagou o "Pacote VIP" não recebe o upsell de novo', count(textos()) === 0);
limpar();
gerarPixOfertaPosBasico('TOKEN', '777', $dados6, 'up', 'up_exclusivo');
$p = $GLOBALS['pix_gerados'][0] ?? [];
confere('aceitar: Pix de R$ 49,90 "Upgrade: Pacote VIP", com o grupo do próprio Upsell e o prazo digitado (12 horas), pagamento único',
    ($p['valor'] ?? 0) == 49.9 && ($p['nome'] ?? '') === 'Upgrade: Pacote VIP' && ($p['id_grupo'] ?? '') === '-100333' && ($p['link_entrega'] ?? 'x') === ''
    && ($p['dias_acesso'] ?? 0) === 12 && ($p['unidade_acesso'] ?? '') === 'horas' && ($p['tipo_cobranca'] ?? '') === 'unica' && ($p['id_produto'] ?? '') === 'plano_up_exclusivo', json_encode($p, JSON_UNESCAPED_UNICODE));
limpar();
gerarPixOfertaPosBasico('TOKEN', '777', $dados6, 'up', 'p2');
confere('aceitar com o id de OUTRO plano: não gera Pix e avisa "não está mais disponível"', count($GLOBALS['pix_gerados']) === 0 && str_contains(textos()[0] ?? '', 'não está mais disponível'));

$dados7 = $dados6;
$dados7['ofertas']['upsell'] = ['entrega_tipo' => 'link', 'id_grupo' => '', 'link_entrega' => 'https://exemplo.com/vip'] + $proprio;
limpar();
gerarPixOfertaPosBasico('TOKEN', '777', $dados7, 'up', 'up_exclusivo');
$p = $GLOBALS['pix_gerados'][0] ?? [];
confere('entrega por link do Upsell: o Pix leva o link e nenhum grupo', ($p['link_entrega'] ?? '') === 'https://exemplo.com/vip' && ($p['id_grupo'] ?? 'x') === '', json_encode($p, JSON_UNESCAPED_UNICODE));
$dados8 = $dados6;
$dados8['ofertas']['upsell']['entrega_tipo'] = 'plano';   // "igual ao plano oferecido" não existe aqui: não há plano de onde copiar
limpar();
gerarPixOfertaPosBasico('TOKEN', '777', $dados8, 'up', 'up_exclusivo');
$p = $GLOBALS['pix_gerados'][0] ?? [];
confere('entrega "igual ao plano" num plano próprio: sem grupo nem link (a venda sai sem acesso, como "Nenhum")', ($p['id_grupo'] ?? 'x') === '' && ($p['link_entrega'] ?? 'x') === '', json_encode($p, JSON_UNESCAPED_UNICODE));

foreach (['sem nome' => ['nome' => '  ', 'valor' => 49.9], 'valor zero' => ['nome' => 'X', 'valor' => 0], 'sem o plano_proprio' => null] as $quando => $incompleto) {
    $of = ['plano_proprio' => $incompleto] + $proprio;
    if ($incompleto === null) {
        unset($of['plano_proprio']);
    }
    limpar();
    ofertasPosPagamentoBasico($pdo, 'TOKEN', venda(fluxo($pdo, ['upsell' => $of])));
    confere("plano próprio incompleto ($quando): o upsell não é oferecido", count(textos()) === 0);
}
$f8 = fluxo($pdo, ['upsell' => ['ativo' => false] + $proprio]);
limpar();
ofertasPosPagamentoBasico($pdo, 'TOKEN', venda($f8));
confere('Upsell com plano próprio desligado: nada é enviado', count(textos()) === 0);
confere('o desconto de um Upsell com plano próprio é sempre 0 e o de um plano da lista segue valendo', descontoDaOfertaBasico($proprio) === 0.0 && descontoDaOfertaBasico($upsell) === 10.0);

echo PHP_EOL . "RESUMO: $ok ok, $falhas falhas" . PHP_EOL;
exit($falhas > 0 ? 1 : 0);
