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

echo PHP_EOL . "RESUMO: $ok ok, $falhas falhas" . PHP_EOL;
exit($falhas > 0 ? 1 : 0);
