<?php
declare(strict_types=1);

/**
 * Entrega do modo Guiado por grupo, link ou nada (funcoes/entrega.php) e o que plano, pack e Upsell mandam para o Pix.
 * Sem rede e sem MySQL: o Telegram e o motor de Pix são dublês que só anotam o que receberam.
 *   php tests/guiado/unitario_entrega.php
 */

$ok = 0;
$falhas = 0;
function confere(string $nome, bool $certo, string $detalhe = ''): void
{
    global $ok, $falhas;
    $certo ? $ok++ : $falhas++;
    echo ($certo ? 'OK     ' : 'FALHOU ') . $nome . ($certo || $detalhe === '' ? '' : '  -> ' . $detalhe) . PHP_EOL;
}

$GLOBALS['pix_gerados'] = [];
function requisicaoTelegram(string $token, string $metodo, array $parametros = [], array $arquivos = []): array
{
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
require_once dirname(__DIR__, 2) . '/funcoes/packs.php';

function nada(): array
{
    return ['id_grupo' => '', 'link_entrega' => ''];
}

// ---------- link aceito ----------
confere('https comum é aceito', linkEntregaValido('https://drive.google.com/drive/folders/1AbC_dEf?usp=sharing') === 'https://drive.google.com/drive/folders/1AbC_dEf?usp=sharing');
confere('http também', linkEntregaValido('http://exemplo.com.br/area') === 'http://exemplo.com.br/area');
confere('espaços nas pontas são tirados', linkEntregaValido("  https://t.me/+AbCdEf  \n") === 'https://t.me/+AbCdEf');
foreach (['', 'exemplo.com', 'javascript:alert(1)', 'ftp://exemplo.com/a', 'tg://resolve?domain=x', 'https://', 'https://exa mple.com', "https://exemplo.com/a\nb", 'data:text/html,oi'] as $ruim) {
    confere('link recusado: ' . json_encode($ruim), linkEntregaValido($ruim) === '');
}
confere('link com mais de 500 caracteres é recusado', linkEntregaValido('https://exemplo.com/' . str_repeat('a', 490)) === '');
confere('valor que não é texto não quebra', linkEntregaValido(null) === '' && linkEntregaValido(123) === '');

// ---------- plano e pack: o que cada tipo entrega ----------
confere('plano antigo (só id_grupo): entrega no grupo', entregaDoItemBasico(['id_grupo' => '-1001']) === ['id_grupo' => '-1001', 'link_entrega' => '']);
confere('plano antigo sem grupo: nada', entregaDoItemBasico(['nome' => 'Mensal']) === nada());
confere('plano tipo grupo', entregaDoItemBasico(['entrega_tipo' => 'grupo', 'id_grupo' => '-1002', 'link_entrega' => 'https://x.com']) === ['id_grupo' => '-1002', 'link_entrega' => '']);
confere('plano tipo link: só o link (o grupo que sobrou não vale)', entregaDoItemBasico(['entrega_tipo' => 'link', 'id_grupo' => '-1002', 'link_entrega' => 'https://x.com/a']) === ['id_grupo' => '', 'link_entrega' => 'https://x.com/a']);
confere('plano tipo nenhuma: nada, mesmo com grupo e link guardados', entregaDoItemBasico(['entrega_tipo' => 'nenhuma', 'id_grupo' => '-1002', 'link_entrega' => 'https://x.com/a']) === nada());
confere('plano tipo link com endereço inválido: não entrega nada (nunca um link quebrado)', entregaDoItemBasico(['entrega_tipo' => 'link', 'link_entrega' => 'javascript:alert(1)']) === nada());
confere('tipo desconhecido cai no jeito antigo (id_grupo)', entregaDoItemBasico(['entrega_tipo' => 'xyz', 'id_grupo' => '-1003']) === ['id_grupo' => '-1003', 'link_entrega' => '']);
confere('tipo "plano" num plano (sem plano de base): nada, sem laço', entregaDoItemBasico(['entrega_tipo' => 'plano', 'id_grupo' => '-9']) === nada());

// ---------- oferta: própria ou a do plano ----------
$plano_grupo = ['id' => 'p2', 'id_grupo' => '-100222'];
$plano_link = ['id' => 'p3', 'entrega_tipo' => 'link', 'link_entrega' => 'https://area.exemplo.com/vip'];
confere('oferta antiga (sem entrega_tipo) usa o grupo do plano oferecido', entregaDoItemBasico(['ativo' => true], $plano_grupo) === ['id_grupo' => '-100222', 'link_entrega' => '']);
confere('oferta "igual ao plano" usa o link do plano oferecido', entregaDoItemBasico(['entrega_tipo' => 'plano'], $plano_link) === ['id_grupo' => '', 'link_entrega' => 'https://area.exemplo.com/vip']);
confere('oferta com link próprio ignora o plano', entregaDoItemBasico(['entrega_tipo' => 'link', 'link_entrega' => 'https://bonus.exemplo.com'], $plano_grupo) === ['id_grupo' => '', 'link_entrega' => 'https://bonus.exemplo.com']);
confere('oferta com grupo próprio ignora o plano', entregaDoItemBasico(['entrega_tipo' => 'grupo', 'id_grupo' => '-100777'], $plano_link) === ['id_grupo' => '-100777', 'link_entrega' => '']);
confere('oferta "nenhuma": só a venda, mesmo que o plano tenha grupo', entregaDoItemBasico(['entrega_tipo' => 'nenhuma'], $plano_grupo) === nada());

// ---------- mensagem de pagamento confirmado ----------
confere('venda sem link: nada a somar', textoEntregaLink(['link_entrega' => null], 'markdown') === '' && textoEntregaLink([], 'html') === '');
$md = textoEntregaLink(['link_entrega' => 'https://drive.google.com/file/d/ab_cd*ef/view'], 'markdown');
confere('Markdown: o link vai com "_" e "*" escapados (senão o Telegram recusa a mensagem inteira)', str_contains($md, 'ab\\_cd\\*ef') && str_contains($md, 'Seu acesso'), $md);
$html = textoEntregaLink(['link_entrega' => 'https://exemplo.com/?a=1&b=2'], 'html');
confere('HTML: o "&" do endereço vai escapado', str_contains($html, 'a=1&amp;b=2'), $html);
confere('venda com link inválido gravado: nada a somar', textoEntregaLink(['link_entrega' => 'javascript:alert(1)'], 'html') === '');

// ---------- validação ao salvar o fluxo ----------
$base = fn(array $extra = []) => $extra + [
    'planos' => [['id' => 'p1', 'nome' => 'Mensal', 'valor' => 19.9, 'entrega_tipo' => 'grupo', 'id_grupo' => '-1']],
    'packs' => ['ativo' => false, 'itens' => []],
    'ofertas' => [],
];
confere('fluxo sem link: sem erro', validarEntregasFluxoGuiado($base()) === null);
// "Acesso a grupo" sem grupo escolhido (select "Grupo para acesso" em "Selecione um grupo…"): não salva, para ninguém pagar e ficar sem acesso
$d = $base(['planos' => [['id' => 'p1', 'nome' => 'Mensal', 'entrega_tipo' => 'grupo', 'id_grupo' => '']]]);
$erro = validarEntregasFluxoGuiado($d);
confere('plano em "Acesso a grupo" sem grupo: erro citando o plano e pedindo o grupo', $erro !== null && str_contains($erro, 'Escolha o grupo') && str_contains($erro, 'do plano "Mensal"'), (string) $erro);
$d = $base(['planos' => [['id' => 'p1', 'nome' => 'Mensal', 'entrega_tipo' => 'grupo', 'id_grupo' => '-1001234567890']]]);
confere('plano em "Acesso a grupo" com grupo: sem erro', validarEntregasFluxoGuiado($d) === null);
$d = $base(['planos' => [['id' => 'p1', 'nome' => 'Antigo', 'id_grupo' => '']]]);
confere('plano ANTIGO sem entrega_tipo e sem grupo continua salvando (só vale a regra para quem escolheu "Acesso a grupo")', validarEntregasFluxoGuiado($d) === null);
$d = $base(['packs' => ['ativo' => true, 'itens' => [['nome' => 'Pack A', 'entrega_tipo' => 'grupo', 'id_grupo' => ' ']]]]);
$erro = validarEntregasFluxoGuiado($d);
confere('pack ativo em "Acesso a grupo" sem grupo: erro citando o pack', $erro !== null && str_contains($erro, 'do pack "Pack A"'), (string) $erro);
$d = $base(['ofertas' => ['downsell' => ['ativo' => true, 'entrega_tipo' => 'grupo', 'id_grupo' => ''], 'upsell' => ['ativo' => false, 'entrega_tipo' => 'grupo', 'id_grupo' => '']]]);
$erro = validarEntregasFluxoGuiado($d);
confere('Downsell ligado em "Acesso a grupo" sem grupo: erro citando o Downsell; Upsell desligado não trava', $erro !== null && str_contains($erro, 'do Downsell') && !str_contains($erro, 'Upsell'), (string) $erro);
$d = $base(['planos' => [['id' => 'p1', 'nome' => 'Mensal', 'entrega_tipo' => 'link', 'link_entrega' => 'meusite.com']]]);
$erro = validarEntregasFluxoGuiado($d);
confere('plano com link sem https://: erro citando o plano', $erro !== null && str_contains($erro, 'do plano "Mensal"') && str_contains($erro, 'https://'), (string) $erro);
$d = $base(['planos' => [['id' => 'p1', 'nome' => 'Mensal', 'entrega_tipo' => 'link', 'link_entrega' => 'https://ok.com/a']]]);
confere('plano com link bom: sem erro', validarEntregasFluxoGuiado($d) === null);
$d = $base(['packs' => ['ativo' => true, 'itens' => [['nome' => 'Pack A', 'entrega_tipo' => 'link', 'link_entrega' => '']]]]);
$erro = validarEntregasFluxoGuiado($d);
confere('pack ativo com link vazio: erro citando o pack', $erro !== null && str_contains($erro, 'do pack "Pack A"'), (string) $erro);
$d = $base(['packs' => ['ativo' => true, 'itens' => [['nome' => 'Pack A', 'ativo' => false, 'entrega_tipo' => 'link', 'link_entrega' => '']]]]);
confere('pack desligado com link vazio: não trava o salvar', validarEntregasFluxoGuiado($d) === null);
$d = $base(['packs' => ['ativo' => false, 'itens' => [['nome' => 'Pack A', 'entrega_tipo' => 'link', 'link_entrega' => '']]]]);
confere('seção Packs desligada: não trava o salvar', validarEntregasFluxoGuiado($d) === null);
$d = $base(['ofertas' => ['upsell' => ['ativo' => true, 'entrega_tipo' => 'link', 'link_entrega' => 'x']]]);
$erro = validarEntregasFluxoGuiado($d);
confere('Upsell ligado com link ruim: erro citando o Upsell', $erro !== null && str_contains($erro, 'do Upsell'), (string) $erro);
$d = $base(['ofertas' => ['downsell' => ['ativo' => true, 'entrega_tipo' => 'link', 'link_entrega' => 'x']]]);
confere('Downsell ligado com link ruim: erro citando o Downsell', str_contains((string) validarEntregasFluxoGuiado($d), 'do Downsell'));
$d = $base(['ofertas' => ['upsell' => ['ativo' => false, 'entrega_tipo' => 'link', 'link_entrega' => 'x']]]);
confere('Upsell desligado com link ruim: não trava o salvar', validarEntregasFluxoGuiado($d) === null);

// ---------- Upsell com plano próprio (item 33) ----------
$proprio = ['nome' => 'Pacote VIP', 'valor' => 49.9, 'dias_acesso' => 12, 'unidade_acesso' => 'horas'];
$upp = ['ativo' => true, 'id_plano_destino' => ID_PLANO_UPSELL_PROPRIO_BASICO, 'plano_proprio' => $proprio, 'entrega_tipo' => 'grupo', 'id_grupo' => '-100333'];
$d = $base(['ofertas' => ['upsell' => $upp]]);
confere('Upsell com plano próprio completo: sem erro', validarPlanoProprioUpsellBasico($d) === null);
confere('Upsell com plano que já existe (sem plano_proprio): sem erro', validarPlanoProprioUpsellBasico($base(['ofertas' => ['upsell' => ['ativo' => true, 'id_plano_destino' => 'p1']]])) === null);
$d = $base(['ofertas' => ['upsell' => ['plano_proprio' => ['nome' => '  '] + $proprio] + $upp]]);
confere('plano próprio sem nome: erro pedindo o nome', str_contains((string) validarPlanoProprioUpsellBasico($d), 'Dê um nome ao plano do Upsell'), (string) validarPlanoProprioUpsellBasico($d));
$d = $base(['ofertas' => ['upsell' => ['plano_proprio' => ['valor' => 0] + $proprio] + $upp]]);
confere('plano próprio com valor zero: erro citando o plano', str_contains((string) validarPlanoProprioUpsellBasico($d), 'O plano do Upsell "Pacote VIP" precisa ter valor maior que zero'), (string) validarPlanoProprioUpsellBasico($d));
$d = $base(['ofertas' => ['upsell' => ['plano_proprio' => ['dias_acesso' => 0] + $proprio] + $upp]]);
confere('plano próprio com tempo de acesso zero: erro', str_contains((string) validarPlanoProprioUpsellBasico($d), 'tempo de acesso'), (string) validarPlanoProprioUpsellBasico($d));
$d = $base(['ofertas' => ['upsell' => ['ativo' => true, 'id_plano_destino' => ID_PLANO_UPSELL_PROPRIO_BASICO]]]);
confere('escolheu o plano próprio mas o plano_proprio nem veio no JSON: erro pedindo o nome', validarPlanoProprioUpsellBasico($d) !== null);
$d = $base(['ofertas' => ['upsell' => ['ativo' => false, 'plano_proprio' => ['nome' => ''] + $proprio] + $upp]]);
confere('Upsell desligado com plano próprio incompleto: não trava o salvar', validarPlanoProprioUpsellBasico($d) === null);
$p = planoProprioDoUpsellBasico($upp);
confere('o plano próprio vira um plano no formato dos de "planos" (id fixo, único, 12 horas, entrega do Upsell)', $p !== null && $p['id'] === 'up_exclusivo' && $p['nome'] === 'Pacote VIP' && $p['valor'] === 49.9 && $p['dias_acesso'] === 12 && $p['unidade_acesso'] === 'horas' && $p['cobranca'] === 'unica' && $p['entrega_tipo'] === 'grupo' && $p['id_grupo'] === '-100333', json_encode($p));
$p = planoProprioDoUpsellBasico(['plano_proprio' => ['unidade_acesso' => 'anos', 'dias_acesso' => -5, 'nome' => str_repeat('é', 120)] + $proprio] + $upp);
confere('unidade desconhecida vira dias, tempo menor que 1 vira 1 e o nome é cortado em 80 caracteres', $p !== null && $p['unidade_acesso'] === 'dias' && $p['dias_acesso'] === 1 && mb_strlen($p['nome']) === 80, json_encode($p));
confere('plano próprio sem entrega_tipo (ou "plano"): entrega nenhuma', planoProprioDoUpsellBasico(['entrega_tipo' => 'plano'] + $upp)['entrega_tipo'] === 'nenhuma' && planoProprioDoUpsellBasico(array_diff_key($upp, ['entrega_tipo' => 1]))['entrega_tipo'] === 'nenhuma');
confere('id_plano_destino de um plano comum não vira plano próprio', planoProprioDoUpsellBasico(['id_plano_destino' => 'p1'] + $upp) === null);

// ---------- o que vai para o Pix ----------
$dados = [
    'ativo' => true,
    'planos' => [
        ['id' => 'p1', 'nome' => 'Mensal', 'valor' => 19.9, 'id_grupo' => '-100111', 'dias_acesso' => 30, 'unidade_acesso' => 'dias'],
        ['id' => 'p2', 'nome' => 'Vitalício', 'valor' => 97.0, 'entrega_tipo' => 'link', 'link_entrega' => 'https://area.exemplo.com/vip', 'dias_acesso' => 36500, 'unidade_acesso' => 'dias'],
    ],
    'ofertas' => ['upsell' => ['ativo' => true, 'id_plano_destino' => 'p2', 'desconto_percentual' => 10]],
    'pagamentos' => [],
];
function pixUpsell(array $dados, array $upsell): array
{
    $dados['ofertas']['upsell'] = $upsell + $dados['ofertas']['upsell'];
    $GLOBALS['pix_gerados'] = [];
    gerarPixOfertaPosBasico('TOKEN', '777', $dados, 'up', 'p2');
    return $GLOBALS['pix_gerados'][0] ?? [];
}
$p = pixUpsell($dados, []);
confere('Upsell sem entrega própria: Pix leva a entrega do plano de destino (link)', ($p['id_grupo'] ?? 'x') === '' && ($p['link_entrega'] ?? '') === 'https://area.exemplo.com/vip', json_encode($p));
$p = pixUpsell($dados, ['entrega_tipo' => 'grupo', 'id_grupo' => '-100999']);
confere('Upsell com grupo próprio: Pix leva o grupo da oferta, sem link', ($p['id_grupo'] ?? '') === '-100999' && ($p['link_entrega'] ?? 'x') === '', json_encode($p));
$p = pixUpsell($dados, ['entrega_tipo' => 'link', 'link_entrega' => 'https://bonus.exemplo.com/x']);
confere('Upsell com link próprio: Pix leva o link da oferta', ($p['id_grupo'] ?? 'x') === '' && ($p['link_entrega'] ?? '') === 'https://bonus.exemplo.com/x', json_encode($p));
$p = pixUpsell($dados, ['entrega_tipo' => 'nenhuma']);
confere('Upsell "nenhuma": Pix sem grupo e sem link', ($p['id_grupo'] ?? 'x') === '' && ($p['link_entrega'] ?? 'x') === '', json_encode($p));
$p = pixUpsell($dados, ['entrega_tipo' => 'plano']);
confere('Upsell "igual ao plano": a entrega do plano de destino', ($p['link_entrega'] ?? '') === 'https://area.exemplo.com/vip', json_encode($p));
$dados['ofertas']['order_bump'] = ['ativo' => true, 'nome' => 'Guia', 'valor_extra' => 9.9];
$GLOBALS['pix_gerados'] = [];
gerarPixOfertaPosBasico('TOKEN', '777', $dados, 'bp', '0');
$p = $GLOBALS['pix_gerados'][0] ?? [];
confere('Order Bump continua sem entrega própria', ($p['id_grupo'] ?? 'x') === '' && ($p['link_entrega'] ?? 'x') === '', json_encode($p));

$pack = ['id' => 'k1', 'nome' => 'Pack A', 'preco' => 29.9, 'entrega_tipo' => 'link', 'link_entrega' => 'https://packs.exemplo.com/a'];
$GLOBALS['pix_gerados'] = [];
gerarPixPackBasico('TOKEN', '777', $dados, $pack);
$p = $GLOBALS['pix_gerados'][0] ?? [];
confere('Pack com link: Pix leva o link e nenhum grupo', ($p['link_entrega'] ?? '') === 'https://packs.exemplo.com/a' && ($p['id_grupo'] ?? 'x') === '', json_encode($p));
$GLOBALS['pix_gerados'] = [];
gerarPixPackBasico('TOKEN', '777', $dados, ['id' => 'k2', 'nome' => 'Pack B', 'preco' => 10.0, 'id_grupo' => '-100555']);
$p = $GLOBALS['pix_gerados'][0] ?? [];
confere('Pack antigo (só id_grupo): continua entregando no grupo', ($p['id_grupo'] ?? '') === '-100555' && ($p['link_entrega'] ?? 'x') === '', json_encode($p));

// ---------- entrega por mensagem de texto (item 34, 27/10/2026) ----------
$m = fn(array $extra = []): array => $extra + ['entrega_tipo' => 'mensagem', 'mensagem_entrega' => "  Seu acesso: https://exemplo.com/x  "];
$e = entregaCompletaDoItemBasico($m());
confere('tipo mensagem: só o texto (sem as pontas), sem grupo e sem link', $e === ['id_grupo' => '', 'link_entrega' => '', 'mensagem_entrega' => 'Seu acesso: https://exemplo.com/x'], json_encode($e));
confere('tipo mensagem: grupo e link que sobraram no item não valem', entregaCompletaDoItemBasico($m(['id_grupo' => '-100', 'link_entrega' => 'https://x.com']))['id_grupo'] === '' && entregaCompletaDoItemBasico($m(['id_grupo' => '-100', 'link_entrega' => 'https://x.com']))['link_entrega'] === '');
confere('entregaDoItemBasico (grupo e link) devolve vazio para o tipo mensagem, no mesmo formato de sempre', entregaDoItemBasico($m()) === nada());
confere('os outros tipos não levam mensagem, mesmo com texto guardado', entregaCompletaDoItemBasico(['entrega_tipo' => 'link', 'link_entrega' => 'https://a.com/x', 'mensagem_entrega' => 'sobra'])['mensagem_entrega'] === '' && entregaCompletaDoItemBasico(['entrega_tipo' => 'grupo', 'id_grupo' => '-100', 'mensagem_entrega' => 'sobra'])['mensagem_entrega'] === '' && entregaCompletaDoItemBasico(['entrega_tipo' => 'nenhuma', 'mensagem_entrega' => 'sobra'])['mensagem_entrega'] === '' && entregaCompletaDoItemBasico(['id_grupo' => '-100', 'mensagem_entrega' => 'sobra'])['mensagem_entrega'] === '');
confere('texto da mensagem cortado em 3000 caracteres', mb_strlen(entregaCompletaDoItemBasico($m(['mensagem_entrega' => str_repeat('é', 3500)]))['mensagem_entrega']) === ENTREGA_MENSAGEM_MAX);
confere('oferta "igual ao plano" herda a mensagem do plano oferecido', entregaCompletaDoItemBasico(['entrega_tipo' => 'plano'], $m())['mensagem_entrega'] === 'Seu acesso: https://exemplo.com/x');
confere('oferta com mensagem própria ignora a entrega do plano', entregaCompletaDoItemBasico($m(['mensagem_entrega' => 'do upsell']), $plano_link) === ['id_grupo' => '', 'link_entrega' => '', 'mensagem_entrega' => 'do upsell']);

$d = $base(['planos' => [['id' => 'p1', 'nome' => 'Mensal'] + $m()]]);
confere('plano com mensagem escrita: sem erro', validarEntregasFluxoGuiado($d) === null);
$d = $base(['planos' => [['id' => 'p1', 'nome' => 'Mensal'] + $m(['mensagem_entrega' => '   '])]]);
confere('plano com entrega por mensagem e texto vazio: erro pedindo a mensagem, citando o plano', str_contains((string) validarEntregasFluxoGuiado($d), 'Escreva a mensagem da entrega do plano "Mensal"'), (string) validarEntregasFluxoGuiado($d));
$d = $base(['planos' => [['id' => 'p1', 'nome' => 'Mensal'] + $m(['mensagem_entrega' => str_repeat('a', 3001)])]]);
confere('mensagem com mais de 3000 caracteres: erro', str_contains((string) validarEntregasFluxoGuiado($d), 'passa de 3000 caracteres'), (string) validarEntregasFluxoGuiado($d));
$d = $base(['planos' => [['id' => 'p1', 'nome' => 'Mensal'] + $m(['mensagem_entrega' => str_repeat('a', 3000)])]]);
confere('mensagem com exatamente 3000 caracteres: passa', validarEntregasFluxoGuiado($d) === null);
$d = $base(['packs' => ['ativo' => true, 'itens' => [['nome' => 'Pack A'] + $m(['mensagem_entrega' => ''])]]]);
confere('pack ligado com mensagem vazia: erro citando o pack', str_contains((string) validarEntregasFluxoGuiado($d), 'do pack "Pack A"'));
$d = $base(['ofertas' => ['upsell' => ['ativo' => true] + $m(['mensagem_entrega' => ''])]]);
confere('Upsell ligado com mensagem vazia: erro citando o Upsell', str_contains((string) validarEntregasFluxoGuiado($d), 'do Upsell'));
$d = $base(['ofertas' => ['downsell' => ['ativo' => true] + $m(['mensagem_entrega' => ''])]]);
confere('Downsell ligado com mensagem vazia: erro citando o Downsell', str_contains((string) validarEntregasFluxoGuiado($d), 'do Downsell'));
$d = $base(['ofertas' => ['upsell' => ['ativo' => false] + $m(['mensagem_entrega' => ''])]]);
confere('Upsell desligado com mensagem vazia: não trava o salvar', validarEntregasFluxoGuiado($d) === null);

$p = pixUpsell($dados, $m(['mensagem_entrega' => 'Bem-vindo ao Upsell']));
confere('Upsell com mensagem própria: o Pix leva o texto, sem grupo e sem link', ($p['mensagem_entrega'] ?? '') === 'Bem-vindo ao Upsell' && ($p['id_grupo'] ?? 'x') === '' && ($p['link_entrega'] ?? 'x') === '', json_encode($p, JSON_UNESCAPED_UNICODE));
$p = pixUpsell($dados, ['entrega_tipo' => 'plano']);
confere('Upsell "igual ao plano" cujo plano entrega por link: o Pix leva o link e nenhuma mensagem', ($p['link_entrega'] ?? '') === 'https://area.exemplo.com/vip' && ($p['mensagem_entrega'] ?? 'x') === '', json_encode($p));
$dados_msg = $dados;
$dados_msg['planos'][1] = ['id' => 'p2', 'nome' => 'Vitalício', 'valor' => 97.0, 'dias_acesso' => 36500, 'unidade_acesso' => 'dias'] + $m(['mensagem_entrega' => 'Do plano Vitalício']);
$GLOBALS['pix_gerados'] = [];
gerarPixOfertaPosBasico('TOKEN', '777', $dados_msg, 'up', 'p2');
$p = $GLOBALS['pix_gerados'][0] ?? [];
confere('Upsell sem entrega própria cujo plano entrega por mensagem: o Pix leva a mensagem do plano', ($p['mensagem_entrega'] ?? '') === 'Do plano Vitalício' && ($p['link_entrega'] ?? 'x') === '', json_encode($p, JSON_UNESCAPED_UNICODE));
$GLOBALS['pix_gerados'] = [];
gerarPixOfertaPosBasico('TOKEN', '777', $dados, 'bp', '0');
confere('Order Bump continua sem entrega (nem mensagem)', ($GLOBALS['pix_gerados'][0]['mensagem_entrega'] ?? 'x') === '');
$GLOBALS['pix_gerados'] = [];
gerarPixPackBasico('TOKEN', '777', $dados, ['id' => 'k3', 'nome' => 'Pack C', 'preco' => 19.9] + $m(['mensagem_entrega' => 'Obrigado pelo Pack C']));
$p = $GLOBALS['pix_gerados'][0] ?? [];
confere('Pack com mensagem: o Pix leva o texto, sem grupo e sem link', ($p['mensagem_entrega'] ?? '') === 'Obrigado pelo Pack C' && ($p['id_grupo'] ?? 'x') === '' && ($p['link_entrega'] ?? 'x') === '', json_encode($p, JSON_UNESCAPED_UNICODE));
$GLOBALS['pix_gerados'] = [];
gerarPixPackBasico('TOKEN', '777', $dados, ['id' => 'k4', 'nome' => 'Pack D', 'preco' => 10.0, 'id_grupo' => '-100555']);
confere('Pack com grupo: o Pix leva mensagem vazia', ($GLOBALS['pix_gerados'][0]['mensagem_entrega'] ?? 'x') === '');

// plano próprio do Upsell (item 33) com mensagem de entrega
$upp_msg = ['ativo' => true, 'id_plano_destino' => ID_PLANO_UPSELL_PROPRIO_BASICO, 'plano_proprio' => $proprio] + $m(['mensagem_entrega' => 'Pacote VIP liberado!']);
$pp = planoProprioDoUpsellBasico($upp_msg);
confere('plano próprio do Upsell com entrega por mensagem: o plano montado leva o tipo e o texto', $pp !== null && $pp['entrega_tipo'] === 'mensagem' && $pp['mensagem_entrega'] === 'Pacote VIP liberado!', json_encode($pp, JSON_UNESCAPED_UNICODE));
$dados_pp = $dados;
$dados_pp['ofertas']['upsell'] = $upp_msg;
$GLOBALS['pix_gerados'] = [];
gerarPixOfertaPosBasico('TOKEN', '777', $dados_pp, 'up', 'up_exclusivo');
$p = $GLOBALS['pix_gerados'][0] ?? [];
confere('aceitar o plano próprio com mensagem: o Pix leva o texto do Upsell', ($p['mensagem_entrega'] ?? '') === 'Pacote VIP liberado!' && ($p['id_grupo'] ?? 'x') === '' && ($p['link_entrega'] ?? 'x') === '', json_encode($p, JSON_UNESCAPED_UNICODE));

echo PHP_EOL . "RESUMO: $ok ok, $falhas falhas" . PHP_EOL;
exit($falhas > 0 ? 1 : 0);
