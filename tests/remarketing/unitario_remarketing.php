<?php
declare(strict_types=1);

/**
 * Regras do remarketing em etapas (funcoes/remarketing.php), sem banco nem Telegram: período, SQL do público, validação do que o assistente manda
 * (nome, fluxo, mensagem, variantes A/B, planos, redirect, order bump), variáveis da mensagem, teclado das ofertas e rodízio das variantes.
 *   php tests/remarketing/unitario_remarketing.php      (sai com código 1 se algo falhar)
 */

$raiz = dirname(__DIR__, 2);

// condicaoLeadPagou() vive em funcoes/operacao.php (precisa do banco); aqui entra a mesma forma com a coluna leads.pagou, que é a do banco novo.
function condicaoLeadPagou(bool $pagou, string $alias = 'l'): string
{
    return "{$alias}.pagou = " . ($pagou ? 1 : 0);
}
require_once $raiz . '/funcoes/remarketing.php';

$ok = 0;
$falhas = 0;
function confere(string $nome, bool $certo, $detalhe = ''): void
{
    global $ok, $falhas;
    $certo ? $ok++ : $falhas++;
    echo ($certo ? 'OK     ' : 'FALHOU ') . $nome . ($certo || $detalhe === '' ? '' : '  -> ' . (is_string($detalhe) ? $detalhe : json_encode($detalhe, JSON_UNESCAPED_UNICODE))) . PHP_EOL;
}

// ---------- período ----------
$agora = strtotime('2026-10-25 14:30:00');
confere('período total: sem filtro', remarketingIntervaloPeriodo('total', '', '', $agora) === null);
confere('período hoje', remarketingIntervaloPeriodo('hoje', '', '', $agora) === ['2026-10-25 00:00:00', '2026-10-25 23:59:59']);
confere('período ontem', remarketingIntervaloPeriodo('ontem', '', '', $agora) === ['2026-10-24 00:00:00', '2026-10-24 23:59:59']);
confere('período 7 dias = 7 dias contando hoje', remarketingIntervaloPeriodo('7d', '', '', $agora) === ['2026-10-19 00:00:00', '2026-10-25 23:59:59']);
confere('período 30 dias = 30 dias contando hoje', remarketingIntervaloPeriodo('30d', '', '', $agora) === ['2026-09-26 00:00:00', '2026-10-25 23:59:59']);
confere('período escolhido: dia inteiro nas duas pontas', remarketingIntervaloPeriodo('custom', '2026-10-01', '2026-10-10', $agora) === ['2026-10-01 00:00:00', '2026-10-10 23:59:59']);
confere('período escolhido sem datas: sem filtro', remarketingIntervaloPeriodo('custom', '', '', $agora) === null);
confere('período lixo: sem filtro', remarketingIntervaloPeriodo('amanha', '', '', $agora) === null);
confere('meia-noite: "hoje" muda de dia', remarketingIntervaloPeriodo('hoje', '', '', strtotime('2026-10-26 00:00:01'))[0] === '2026-10-26 00:00:00');
confere('virada de mês: 7 dias', remarketingIntervaloPeriodo('7d', '', '', strtotime('2026-11-02 10:00:00'))[0] === '2026-10-27 00:00:00');

confere('normalizar: total', remarketingNormalizarPeriodo('total')['erro'] === null);
confere('normalizar: tipo desconhecido', remarketingNormalizarPeriodo('semana')['erro'] !== null);
confere('normalizar: escolher sem datas', remarketingNormalizarPeriodo('custom', '', '')['erro'] !== null);
confere('normalizar: data inicial depois da final', remarketingNormalizarPeriodo('custom', '2026-10-10', '2026-10-01')['erro'] !== null);
confere('normalizar: data impossível (31/02)', remarketingNormalizarPeriodo('custom', '2026-02-31', '2026-03-01')['erro'] !== null);
confere('normalizar: data com texto', remarketingNormalizarPeriodo('custom', '2026-10-01; DROP', '2026-10-02')['erro'] !== null);
confere('normalizar: datas boas', remarketingNormalizarPeriodo('custom', '2026-10-01', '2026-10-10') === ['tipo' => 'custom', 'de' => '2026-10-01', 'ate' => '2026-10-10', 'erro' => null]);
confere('normalizar: mesmo dia vale', remarketingNormalizarPeriodo('custom', '2026-10-05', '2026-10-05')['erro'] === null);
confere('normalizar: período relativo ignora as datas mandadas', remarketingNormalizarPeriodo('7d', '2026-10-01', '2026-10-02')['de'] === '');

// ---------- público (SQL) ----------
[$sql, $p] = remarketingCondicaoPublico('todos', [3, 5], null, null);
confere('todos: só os bots do fluxo', $sql === 'l.bot_id IN (3,5)' && $p === [], $sql);
[$sql] = remarketingCondicaoPublico('nao_comprou', [3], null, null);
confere('não compraram usa leads.pagou = 0', $sql === 'l.bot_id IN (3) AND l.pagou = 0', $sql);
[$sql] = remarketingCondicaoPublico('comprou', [3], null, null);
confere('já compraram usa leads.pagou = 1', $sql === 'l.bot_id IN (3) AND l.pagou = 1', $sql);
[$sql] = remarketingCondicaoPublico('pix_gerado', [3], null, null);
confere('pix gerado: não pagou e tem venda gerada/expirada/cancelada', strpos($sql, 'l.pagou = 0') !== false && strpos($sql, "v.status IN ('gerado', 'expirado', 'cancelado')") !== false && strpos($sql, 'v.bot_id = l.bot_id') !== false, $sql);
[$sql] = remarketingCondicaoPublico('expirados', [3], null, null);
confere('expirados: já teve acesso expirado e não tem acesso ativo agora', strpos($sql, "m.status = 'expirado'") !== false && strpos($sql, "NOT EXISTS (SELECT 1 FROM membros_grupos m WHERE m.id_telegram = l.id_telegram AND m.bot_id = l.bot_id AND m.status = 'ativo')") !== false, $sql);
[$sql] = remarketingCondicaoPublico('membros_ativos', [3], null, null);
confere('membros ativos: tem acesso ativo', strpos($sql, "m.status = 'ativo'") !== false && strpos($sql, 'NOT EXISTS') === false, $sql);
[$sql, $p] = remarketingCondicaoPublico('todos', [3], '2026-10-01 00:00:00', '2026-10-10 23:59:59');
confere('período entra como parâmetro (não no SQL cru)', strpos($sql, 'l.criado_em BETWEEN :rm_ini AND :rm_fim') !== false && $p === ['rm_ini' => '2026-10-01 00:00:00', 'rm_fim' => '2026-10-10 23:59:59'] && strpos($sql, '2026') === false, $sql);
[$sql, $p] = remarketingCondicaoPublico('todos', [], null, null);
confere('sem bots: ninguém recebe', $sql === '0 = 1' && $p === []);
[$sql] = remarketingCondicaoPublico('segmento_falso', [3], null, null);
confere('segmento desconhecido: ninguém recebe', $sql === '0 = 1');
[$sql] = remarketingCondicaoPublico('todos', ['3', "4); DROP TABLE leads;--", 'x', -2, 0, 3.9], null, null);
confere('ids de bot viram inteiros (sem injeção)', $sql === 'l.bot_id IN (3,4)', $sql);
confere('ids: lista em texto "1,2,2,x" vira [1,2]', remarketingIdsInteiros('1,2,2,x') === [1, 2]);

// ---------- variáveis ----------
$lead = ['nome' => 'Maria <b>', 'nome_usuario_telegram' => 'maria_sp'];
confere('{nome} escapa HTML (a mensagem vai em HTML)', remarketingAplicarVariaveis('Oi {nome}!', $lead) === 'Oi Maria &lt;b&gt;!');
confere('{usuario} vira @usuário', remarketingAplicarVariaveis('Fale com {usuario}', $lead) === 'Fale com @maria_sp');
confere('{usuario} sem @ no cadastro: usa o nome', remarketingAplicarVariaveis('{usuario}', ['nome' => 'Ana']) === 'Ana');
confere('{nome} vazio vira "cliente"', remarketingAplicarVariaveis('Olá {nome}', ['nome' => '  ']) === 'Olá cliente');
confere('{saudacao} por hora: 8h', remarketingAplicarVariaveis('{saudacao}', [], 8) === 'Bom dia');
confere('{saudacao} por hora: 12h', remarketingAplicarVariaveis('{saudacao}', [], 12) === 'Boa tarde');
confere('{saudacao} por hora: 18h', remarketingAplicarVariaveis('{saudacao}', [], 18) === 'Boa noite');
confere('texto sem variável volta igual', remarketingAplicarVariaveis('Sem variáveis <b>aqui</b>', $lead) === 'Sem variáveis <b>aqui</b>');
confere('variável desconhecida fica como está', remarketingAplicarVariaveis('{email}', $lead) === '{email}');
confere('valor do lead não é reinterpretado como variável', remarketingAplicarVariaveis('{nome}', ['nome' => '{usuario}']) === '{usuario}');

// ---------- variante (mensagem + mídia) ----------
$msg10 = 'Mensagem ok!';   // 12 caracteres
confere('variante: mensagem curta demais', isset(remarketingNormalizarVariante(['mensagem' => 'curta'], 'mensagem principal')['erro']));
confere('variante: 10 caracteres exatos passa', !isset(remarketingNormalizarVariante(['mensagem' => '1234567890'], 'x')['erro']));
confere('variante: 9 caracteres não passa', isset(remarketingNormalizarVariante(['mensagem' => '123456789'], 'x')['erro']));
confere('variante: espaços nas pontas não contam', isset(remarketingNormalizarVariante(['mensagem' => '   123456789   '], 'x')['erro']));
confere('variante: 4096 passa sem mídia', !isset(remarketingNormalizarVariante(['mensagem' => str_repeat('a', 4096)], 'x')['erro']));
confere('variante: 4097 não passa sem mídia', isset(remarketingNormalizarVariante(['mensagem' => str_repeat('a', 4097)], 'x')['erro']));
confere('variante: com mídia o teto cai para 1024 (legenda)', isset(remarketingNormalizarVariante(['mensagem' => str_repeat('a', 1025), 'midia_caminho' => 'uploads/a.jpg', 'midia_tipo' => 'foto'], 'x')['erro']));
confere('variante: com mídia, 1024 passa', !isset(remarketingNormalizarVariante(['mensagem' => str_repeat('a', 1024), 'midia_caminho' => 'uploads/a.jpg', 'midia_tipo' => 'foto'], 'x')['erro']));
confere('variante: acento conta como 1 caractere (não bytes)', !isset(remarketingNormalizarVariante(['mensagem' => str_repeat('ç', 1024), 'midia_caminho' => 'uploads/a.jpg', 'midia_tipo' => 'foto'], 'x')['erro']));
foreach (['../etc/passwd', 'uploads/../config.php', 'uploads/sub/a.jpg', 'http://x/y.jpg', '/uploads/a.jpg', 'uploads/a b.jpg', 'config.php'] as $ruim) {
    confere("variante: caminho de mídia recusado ($ruim)", isset(remarketingNormalizarVariante(['mensagem' => $msg10, 'midia_caminho' => $ruim, 'midia_tipo' => 'foto'], 'x')['erro']));
}
confere('variante: tipo de mídia inválido', isset(remarketingNormalizarVariante(['mensagem' => $msg10, 'midia_caminho' => 'uploads/a.jpg', 'midia_tipo' => 'audio'], 'x')['erro']));
confere('variante: sem mídia zera o tipo', remarketingNormalizarVariante(['mensagem' => $msg10, 'midia_caminho' => '', 'midia_tipo' => 'foto'], 'x') === ['mensagem' => $msg10, 'midia_caminho' => '', 'midia_tipo' => '']);

// ---------- ofertas ----------
$plano = ['id' => 'abc', 'nome' => 'VIP mensal', 'valor' => '29,90', 'acesso' => 'mensal', 'entrega_tipo' => 'grupo', 'id_grupo' => '-1001234567890'];
$r = remarketingNormalizarOfertas(['planos' => [$plano]], 1.50);
confere('plano bom passa', $r['erro'] === null, $r['erro']);
confere('preço com vírgula vira número', ($r['ofertas']['planos'][0]['valor'] ?? 0) === 29.9, $r['ofertas']['planos'][0] ?? []);
confere('mensal = 30 dias', ($r['ofertas']['planos'][0]['dias'] ?? 0) === 30);
confere('id do plano é mantido', ($r['ofertas']['planos'][0]['id'] ?? '') === 'abc');
foreach (['semanal' => 7, 'trimestral' => 90, 'semestral' => 180, 'anual' => 365, 'vitalicio' => 36500] as $chave => $dias) {
    confere("acesso $chave = $dias dias", (remarketingNormalizarOfertas(['planos' => [['acesso' => $chave] + $plano]], 1.5)['ofertas']['planos'][0]['dias'] ?? 0) === $dias);
}
confere('acesso desconhecido vira mensal', (remarketingNormalizarOfertas(['planos' => [['acesso' => 'quinzenal'] + $plano]], 1.5)['ofertas']['planos'][0]['acesso'] ?? '') === 'mensal');
confere('acesso em dias: 45 dias', (remarketingNormalizarOfertas(['planos' => [['acesso' => 'dias', 'dias' => 45] + $plano]], 1.5)['ofertas']['planos'][0]['dias'] ?? 0) === 45);
confere('acesso em dias: 0 recusado', remarketingNormalizarOfertas(['planos' => [['acesso' => 'dias', 'dias' => 0] + $plano]], 1.5)['erro'] !== null);
confere('acesso em dias: 3651 recusado', remarketingNormalizarOfertas(['planos' => [['acesso' => 'dias', 'dias' => 3651] + $plano]], 1.5)['erro'] !== null);
confere('plano sem nome recusado', remarketingNormalizarOfertas(['planos' => [['nome' => '  '] + $plano]], 1.5)['erro'] !== null);
confere('preço abaixo do mínimo da gateway recusado', remarketingNormalizarOfertas(['planos' => [['valor' => 1.49] + $plano]], 1.5)['erro'] !== null);
confere('preço no mínimo passa', remarketingNormalizarOfertas(['planos' => [['valor' => 1.5] + $plano]], 1.5)['erro'] === null);
confere('preço zero/negativo/texto recusado', remarketingNormalizarOfertas(['planos' => [['valor' => 0] + $plano]], 1.5)['erro'] !== null && remarketingNormalizarOfertas(['planos' => [['valor' => -5] + $plano]], 1.5)['erro'] !== null && remarketingNormalizarOfertas(['planos' => [['valor' => 'abc'] + $plano]], 1.5)['erro'] !== null);
confere('preço absurdo recusado', remarketingNormalizarOfertas(['planos' => [['valor' => 100001] + $plano]], 1.5)['erro'] !== null);
confere('entrega por grupo sem grupo recusada', remarketingNormalizarOfertas(['planos' => [['id_grupo' => ''] + $plano]], 1.5)['erro'] !== null);
confere('entrega por grupo com id não numérico recusada', remarketingNormalizarOfertas(['planos' => [['id_grupo' => 'abc; DROP'] + $plano]], 1.5)['erro'] !== null);
confere('entrega por link bom', (remarketingNormalizarOfertas(['planos' => [['entrega_tipo' => 'link', 'link_entrega' => 'https://exemplo.com/acesso'] + $plano]], 1.5)['ofertas']['planos'][0]['link_entrega'] ?? '') === 'https://exemplo.com/acesso');
foreach (['javascript:alert(1)', 'ftp://x.com/a', 'exemplo.com', 'https://a b.com', ''] as $ruim) {
    confere("entrega por link recusada ($ruim)", remarketingNormalizarOfertas(['planos' => [['entrega_tipo' => 'link', 'link_entrega' => $ruim] + $plano]], 1.5)['erro'] !== null);
}
confere('entrega "nenhuma" zera grupo e link', (remarketingNormalizarOfertas(['planos' => [['entrega_tipo' => 'nenhuma', 'id_grupo' => '-100', 'link_entrega' => 'https://x.com'] + $plano]], 1.5)['ofertas']['planos'][0] ?? []) ['id_grupo'] === '');
confere('entrega desconhecida vira "nenhuma"', (remarketingNormalizarOfertas(['planos' => [['entrega_tipo' => 'pombo'] + $plano]], 1.5)['ofertas']['planos'][0]['entrega_tipo'] ?? '') === 'nenhuma');
confere('nome do plano sem HTML e até 40 caracteres', (remarketingNormalizarOfertas(['planos' => [['nome' => '<b>' . str_repeat('x', 60) . '</b>'] + $plano]], 1.5)['ofertas']['planos'][0]['nome'] ?? '') === str_repeat('x', 40));
$sete = array_fill(0, 7, $plano);
foreach ($sete as $i => &$s) { $s['id'] = 'p' . $i; }
unset($s);
confere('no máximo ' . REMARKETING_PLANOS_MAX . ' planos (o resto é descartado)', count(remarketingNormalizarOfertas(['planos' => $sete], 1.5)['ofertas']['planos']) === REMARKETING_PLANOS_MAX);
$dup = remarketingNormalizarOfertas(['planos' => [$plano, $plano]], 1.5)['ofertas']['planos'];
confere('ids de plano repetidos viram únicos', $dup[0]['id'] !== $dup[1]['id'] && $dup[0]['id'] === 'abc');
confere('id de plano vazio ganha um', strlen(remarketingNormalizarOfertas(['planos' => [['id' => ''] + $plano]], 1.5)['ofertas']['planos'][0]['id']) >= 4);
confere('id de plano com símbolos é limpo (cabe no callback_data)', remarketingNormalizarOfertas(['planos' => [['id' => 'a::b/c d'] + $plano]], 1.5)['ofertas']['planos'][0]['id'] === 'abcd');
confere('id de plano longo é cortado em 8', strlen(remarketingNormalizarOfertas(['planos' => [['id' => str_repeat('z', 40)] + $plano]], 1.5)['ofertas']['planos'][0]['id']) === 8);

$r = remarketingNormalizarOfertas([], 1.5);
confere('sem nenhuma oferta: vale (só mensagem)', $r['erro'] === null && $r['ofertas']['planos'] === [] && $r['ofertas']['redirect']['ativo'] === false && $r['ofertas']['order_bump']['ativo'] === false);
confere('redirect ligado sem endereço recusado', remarketingNormalizarOfertas(['redirect' => ['ativo' => true, 'url' => '']], 1.5)['erro'] !== null);
confere('redirect ligado com endereço ruim recusado', remarketingNormalizarOfertas(['redirect' => ['ativo' => true, 'url' => 'javascript:alert(1)']], 1.5)['erro'] !== null);
$r = remarketingNormalizarOfertas(['redirect' => ['ativo' => true, 'url' => 'https://t.me/+abc123', 'texto' => '']], 1.5);
confere('redirect bom (texto padrão "Ver Prévias")', $r['erro'] === null && $r['ofertas']['redirect']['url'] === 'https://t.me/+abc123' && $r['ofertas']['redirect']['texto'] === 'Ver Prévias', $r);
confere('redirect desligado ignora endereço ruim', remarketingNormalizarOfertas(['redirect' => ['ativo' => false, 'url' => 'lixo']], 1.5)['erro'] === null);
confere('order bump ligado sem nome recusado', remarketingNormalizarOfertas(['planos' => [$plano], 'order_bump' => ['ativo' => true, 'nome' => '', 'valor' => 5]], 1.5)['erro'] !== null);
confere('order bump ligado sem preço recusado', remarketingNormalizarOfertas(['planos' => [$plano], 'order_bump' => ['ativo' => true, 'nome' => 'Extra', 'valor' => 0]], 1.5)['erro'] !== null);
confere('order bump sem plano recusado (o extra se soma a um plano)', remarketingNormalizarOfertas(['order_bump' => ['ativo' => true, 'nome' => 'Extra', 'valor' => 5]], 1.5)['erro'] !== null);
confere('order bump bom', remarketingNormalizarOfertas(['planos' => [$plano], 'order_bump' => ['ativo' => true, 'nome' => 'Extra', 'valor' => '5,90', 'descricao' => 'Mais conteúdo']], 1.5)['ofertas']['order_bump']['valor'] === 5.9);
confere('order bump desligado não exige nada', remarketingNormalizarOfertas(['order_bump' => ['ativo' => false, 'nome' => '', 'valor' => 0]], 1.5)['erro'] === null);
confere('order bump: mídia com caminho de fora é descartada', remarketingNormalizarOfertas(['planos' => [$plano], 'order_bump' => ['ativo' => true, 'nome' => 'E', 'valor' => 3, 'midia_caminho' => '../x.jpg', 'midia_tipo' => 'foto']], 1.5)['ofertas']['order_bump']['midia_caminho'] === '');
confere('order bump: mídia boa fica', remarketingNormalizarOfertas(['planos' => [$plano], 'order_bump' => ['ativo' => true, 'nome' => 'E', 'valor' => 3, 'midia_caminho' => 'uploads/b.png', 'midia_tipo' => 'foto']], 1.5)['ofertas']['order_bump']['midia_caminho'] === 'uploads/b.png');

// ---------- entrada completa ----------
$bom = [
    'nome' => 'Recuperação outubro', 'id_fluxo' => 7, 'segmento' => 'nao_comprou', 'periodo_tipo' => '7d',
    'variantes' => [['mensagem' => $msg10]], 'ofertas' => ['planos' => [$plano]],
];
$n = remarketingNormalizarEntrada($bom, 1.5);
confere('entrada boa passa', $n['erro'] === null, $n['erro']);
confere('entrada boa: dados prontos', ($n['dados']['nome'] ?? '') === 'Recuperação outubro' && $n['dados']['id_fluxo'] === 7 && $n['dados']['segmento'] === 'nao_comprou' && $n['dados']['periodo']['tipo'] === '7d' && count($n['dados']['variantes']) === 1);
confere('entrada: nome vazio', remarketingNormalizarEntrada(['nome' => ' '] + $bom, 1.5)['erro'] === 'Dê um nome à campanha.');
confere('entrada: nome cortado em ' . REMARKETING_NOME_MAX, mb_strlen(remarketingNormalizarEntrada(['nome' => str_repeat('n', 80)] + $bom, 1.5)['dados']['nome']) === REMARKETING_NOME_MAX);
confere('entrada: nome sem HTML', remarketingNormalizarEntrada(['nome' => '<script>x</script>Oi'] + $bom, 1.5)['dados']['nome'] === 'xOi');
confere('entrada: sem fluxo', remarketingNormalizarEntrada(['id_fluxo' => 0] + $bom, 1.5)['erro'] === 'Escolha o fluxo da campanha.');
confere('entrada: segmento inválido', remarketingNormalizarEntrada(['segmento' => 'todos_menos_voce'] + $bom, 1.5)['erro'] !== null);
confere('entrada: período inválido', remarketingNormalizarEntrada(['periodo_tipo' => 'custom', 'periodo_de' => '', 'periodo_ate' => ''] + $bom, 1.5)['erro'] !== null);
confere('entrada: sem mensagem', remarketingNormalizarEntrada(['variantes' => []] + $bom, 1.5)['erro'] === 'Escreva a mensagem da campanha.');
confere('entrada: variante B curta aponta a B', strpos((string) remarketingNormalizarEntrada(['variantes' => [['mensagem' => $msg10], ['mensagem' => 'oi']]] + $bom, 1.5)['erro'], 'variante B') !== false);
confere('entrada: no máximo ' . REMARKETING_VARIANTES_MAX . ' variantes', count(remarketingNormalizarEntrada(['variantes' => array_fill(0, 6, ['mensagem' => $msg10])] + $bom, 1.5)['dados']['variantes']) === REMARKETING_VARIANTES_MAX);
confere('entrada: erro de oferta sobe', remarketingNormalizarEntrada(['ofertas' => ['planos' => [['valor' => 0] + $plano]]] + $bom, 1.5)['erro'] !== null);
confere('entrada: tudo texto/valor errado de tipo não quebra', remarketingNormalizarEntrada(['nome' => ['x'], 'id_fluxo' => 'abc', 'variantes' => 'x', 'ofertas' => 'y'], 1.5)['erro'] !== null);

// ---------- gravado -> variantes e ofertas ----------
$linha = ['mensagem' => 'Mensagem A aqui', 'midia_caminho' => 'uploads/a.jpg', 'midia_tipo' => 'foto', 'variantes' => json_encode([['mensagem' => 'Mensagem B aqui', 'midia_caminho' => '', 'midia_tipo' => ''], ['mensagem' => '', 'midia_caminho' => '', 'midia_tipo' => '']]), 'ofertas' => null];
$v = remarketingVariantesDaCampanha($linha);
confere('variantes da campanha: A + B (a vazia some)', count($v) === 2 && $v[0]['mensagem'] === 'Mensagem A aqui' && $v[0]['midia_tipo'] === 'foto' && $v[1]['mensagem'] === 'Mensagem B aqui');
confere('campanha antiga (sem variantes) tem só a A', count(remarketingVariantesDaCampanha(['mensagem' => 'Oi tudo bem', 'midia_caminho' => null, 'midia_tipo' => null])) === 1);
confere('variantes: JSON quebrado é ignorado', count(remarketingVariantesDaCampanha(['mensagem' => 'Oi tudo bem', 'variantes' => '{lixo'])) === 1);
$o = remarketingOfertasDaCampanha(['ofertas' => null]);
confere('ofertas da campanha antiga: tudo vazio e desligado', $o['planos'] === [] && $o['redirect']['ativo'] === false && $o['order_bump']['ativo'] === false && !remarketingTemOferta($o));
confere('ofertas: JSON quebrado é ignorado', remarketingOfertasDaCampanha(['ofertas' => '{x'])['planos'] === []);

// ---------- teclado ----------
$of = remarketingOfertasDaCampanha(['ofertas' => json_encode(['planos' => [
    ['id' => 'abc', 'nome' => 'VIP', 'valor' => 29.9], ['id' => 'xyz', 'nome' => 'Anual', 'valor' => 199],
], 'redirect' => ['ativo' => true, 'texto' => 'Prévias', 'url' => 'https://t.me/+x']])]);
$t = remarketingTeclado(123, 1, $of);
confere('teclado: um botão por plano + o redirect', count($t['inline_keyboard']) === 3);
confere('teclado: callback do plano carrega campanha, plano e variante', $t['inline_keyboard'][0][0]['callback_data'] === 'rmk::123::abc::1', $t['inline_keyboard'][0][0]);
confere('teclado: rótulo "Nome — R$ valor"', $t['inline_keyboard'][0][0]['text'] === 'VIP — R$ 29,90' && $t['inline_keyboard'][1][0]['text'] === 'Anual — R$ 199,00');
confere('teclado: redirect é botão de link', ($t['inline_keyboard'][2][0]['url'] ?? '') === 'https://t.me/+x' && !isset($t['inline_keyboard'][2][0]['callback_data']) && $t['inline_keyboard'][2][0]['text'] === 'Prévias');
$maior = 'rmk::' . PHP_INT_MAX . '::' . str_repeat('z', 8) . '::2::1';
confere('callback_data cabe nos 64 bytes do Telegram (pior caso)', strlen($maior) <= 64, (string) strlen($maior));
confere('callback do order bump cabe em 64 bytes (pior caso)', strlen('rmk::bp::' . PHP_INT_MAX . '::' . str_repeat('z', 8) . '::2::1') <= 64);
confere('sem oferta: sem teclado', remarketingTeclado(1, 0, remarketingOfertasDaCampanha(['ofertas' => null])) === null);
$so_redirect = remarketingOfertasDaCampanha(['ofertas' => json_encode(['redirect' => ['ativo' => true, 'url' => 'https://x.com']])]);
confere('só redirect: 1 botão de link com o texto padrão', count(remarketingTeclado(1, 0, $so_redirect)['inline_keyboard']) === 1 && remarketingTeclado(1, 0, $so_redirect)['inline_keyboard'][0][0]['text'] === 'Ver Prévias');
$redirect_desligado = remarketingOfertasDaCampanha(['ofertas' => json_encode(['redirect' => ['ativo' => false, 'url' => 'https://x.com']])]);
confere('redirect desligado: sem teclado', remarketingTeclado(1, 0, $redirect_desligado) === null && !remarketingTemOferta($redirect_desligado));

// ---------- rodízio das variantes ----------
confere('uma variante: sempre a A', remarketingVarianteDaPosicao(0, 1) === 0 && remarketingVarianteDaPosicao(57, 1) === 0);
$seq = array_map(fn($i) => remarketingVarianteDaPosicao($i, 3), range(0, 8));
confere('três variantes: A B C A B C...', $seq === [0, 1, 2, 0, 1, 2, 0, 1, 2], $seq);
confere('duas variantes: metade cada', array_count_values(array_map(fn($i) => remarketingVarianteDaPosicao($i, 2), range(0, 999))) === [0 => 500, 1 => 500]);
confere('a posição global manda: lote que recomeça no 1000 continua o rodízio', remarketingVarianteDaPosicao(1000, 3) === 1 && remarketingVarianteDaPosicao(1001, 3) === 2);

// ---------- rótulos e datas ----------
confere('rótulo do segmento', remarketingRotuloSegmento('pix_gerado') === 'Pix gerado' && remarketingRotuloSegmento('xx') === 'xx');
confere('todo segmento tem rótulo e descrição', count(array_filter(REMARKETING_SEGMENTOS, fn($s) => count($s) === 2 && $s[0] !== '' && $s[1] !== '')) === count(REMARKETING_SEGMENTOS));
confere('status da campanha em português', REMARKETING_STATUS_ROTULOS['processando'] === 'Ativa' && REMARKETING_STATUS_ROTULOS['rascunho'] === 'Rascunho');
confere('data curta', remarketingDataCurta('2026-10-25 21:30:00') === '25/10/2026 21:30' && remarketingDataCurta(null) === '' && remarketingDataCurta('') === '');

// ---------- reenvio, intervalo de 3 horas por fluxo e recorrência (26/10/2026) ----------
date_default_timezone_set('America/Sao_Paulo');
confere('o intervalo mínimo entre envios do mesmo fluxo é de 3 horas', REMARKETING_INTERVALO_MINIMO_MIN === 180);
confere('as 5 frequências do modal, na ordem do Shark', array_keys(REMARKETING_FREQUENCIAS) === ['uma_vez', 'diario', 'horarios', 'a_cada', 'semana'] && REMARKETING_FREQUENCIAS['a_cada'] === 'A cada X horas');
confere('dias da semana na ordem do date("w") (domingo = 0)', REMARKETING_DIAS_SEMANA[0] === 'Dom' && REMARKETING_DIAS_SEMANA[6] === 'Sáb' && count(REMARKETING_DIAS_SEMANA) === 7);

// quais campanhas dá para (re)agendar ou reenviar
foreach (['rascunho' => [true, false], 'pendente' => [true, false], 'processando' => [false, false], 'concluida' => [true, true], 'falha' => [true, true]] as $st => [$agendavel, $reenviavel]) {
    confere("status $st: agendável = " . ($agendavel ? 'sim' : 'não') . ', reenviável = ' . ($reenviavel ? 'sim' : 'não'), remarketingAgendavel(['status' => $st]) === $agendavel && remarketingReenviavel(['status' => $st]) === $reenviavel);
}
confere('execução anterior: rascunho zerado não tem; terminada, com envios ou com offset têm',
    !remarketingTemExecucaoAnterior(['processado_em' => null, 'enviados' => 0, 'offset_envio' => 0])
    && remarketingTemExecucaoAnterior(['processado_em' => '2026-10-26 10:00:00']) && remarketingTemExecucaoAnterior(['enviados' => 3]) && remarketingTemExecucaoAnterior(['offset_envio' => 7]));
confere('grupo do relógio: o fluxo; a campanha antiga, o bot dela', remarketingChaveGrupo(['id_fluxo' => 10, 'bot_id' => 7]) === 'f10' && remarketingChaveGrupo(['id_fluxo' => null, 'bot_id' => 7]) === 'b7' && remarketingChaveGrupo(['bot_id' => 7]) === 'b7');
confere('fluxos diferentes não dividem o relógio', remarketingChaveGrupo(['id_fluxo' => 10]) !== remarketingChaveGrupo(['id_fluxo' => 11]));

// horário HH:MM
confere('hora válida: normaliza "9:05" e "09:05:30"', remarketingHoraValida('9:05') === '09:05' && remarketingHoraValida('09:05:30') === '09:05' && remarketingHoraValida(' 23:59 ') === '23:59' && remarketingHoraValida('00:00') === '00:00');
confere('hora inválida: 24:00, 12:60, texto, vazio, lista', remarketingHoraValida('24:00') === '' && remarketingHoraValida('12:60') === '' && remarketingHoraValida('abc') === '' && remarketingHoraValida('') === '' && remarketingHoraValida(['09:00']) === '');

// frequência vinda do modal
$f = remarketingNormalizarFrequencia(['tipo' => 'uma_vez', 'data' => '2026-10-27', 'hora' => '9:30']);
confere('uma vez: data e hora viram o 1º envio, sem recorrência', $f['erro'] === null && $f['primeira'] === '2026-10-27 09:30' && $f['recorrencia'] === null, $f);
confere('uma vez sem hora: pede a data e a hora', remarketingNormalizarFrequencia(['tipo' => 'uma_vez', 'data' => '2026-10-27'])['erro'] === 'Informe a data e a hora do envio.');
confere('uma vez com data que não existe (30/02)', remarketingNormalizarFrequencia(['tipo' => 'uma_vez', 'data' => '2026-02-30', 'hora' => '10:00'])['erro'] === 'Informe a data e a hora do envio.');
confere('sem tipo vale "uma vez" (quem manda só data e hora)', remarketingNormalizarFrequencia(['data' => '2026-10-27', 'hora' => '10:00'])['erro'] === null);
confere('tipo desconhecido, vazio ou em lista: erro', remarketingNormalizarFrequencia(['tipo' => 'mensal'])['erro'] !== null && remarketingNormalizarFrequencia(['tipo' => ['diario']])['erro'] !== null && remarketingNormalizarFrequencia('lixo')['erro'] === 'Informe a data e a hora do envio.');
$f = remarketingNormalizarFrequencia(['tipo' => 'diario', 'hora' => '21:00']);
confere('diário: só o horário, sem data (o servidor acha a próxima)', $f['erro'] === null && $f['recorrencia'] === ['tipo' => 'diario', 'hora' => '21:00'] && $f['primeira'] === '', $f);
confere('diário sem horário ou com horário inválido: erro', remarketingNormalizarFrequencia(['tipo' => 'diario'])['erro'] === 'Informe o horário de execução.' && remarketingNormalizarFrequencia(['tipo' => 'diario', 'hora' => '25:00'])['erro'] === 'Informe o horário de execução.');
$f = remarketingNormalizarFrequencia(['tipo' => 'horarios', 'horarios' => ['21:00', '09:00', '9:00', '15:00']]);
confere('horários: tira repetido e põe em ordem', $f['erro'] === null && $f['recorrencia']['horarios'] === ['09:00', '15:00', '21:00'], $f);
confere('horários: lista vazia, horário inválido e lista que não é lista', remarketingNormalizarFrequencia(['tipo' => 'horarios', 'horarios' => []])['erro'] === 'Informe pelo menos um horário.' && remarketingNormalizarFrequencia(['tipo' => 'horarios', 'horarios' => ['09:00', '99:99']])['erro'] === 'Há um horário inválido na lista.' && remarketingNormalizarFrequencia(['tipo' => 'horarios', 'horarios' => 'x'])['erro'] === 'Informe pelo menos um horário.');
confere('horários: 6 cabem, 7 não', remarketingNormalizarFrequencia(['tipo' => 'horarios', 'horarios' => ['00:00', '03:00', '06:00', '09:00', '12:00', '15:00']])['erro'] === null && remarketingNormalizarFrequencia(['tipo' => 'horarios', 'horarios' => ['00:00', '03:00', '06:00', '09:00', '12:00', '15:00', '18:00']])['erro'] !== null);
confere('horários: 3 h exatas entre eles passam; 2 h 59 não', remarketingNormalizarFrequencia(['tipo' => 'horarios', 'horarios' => ['09:00', '12:00']])['erro'] === null && str_contains((string) remarketingNormalizarFrequencia(['tipo' => 'horarios', 'horarios' => ['09:00', '11:59']])['erro'], '3 horas'));
confere('horários: o fim do dia e o começo do dia seguinte também contam (22:00 e 00:30 = 2 h 30)', remarketingNormalizarFrequencia(['tipo' => 'horarios', 'horarios' => ['00:30', '22:00']])['erro'] !== null && remarketingNormalizarFrequencia(['tipo' => 'horarios', 'horarios' => ['00:00', '21:00']])['erro'] === null);
confere('um horário só: o dia seguinte está a 24 h', remarketingNormalizarFrequencia(['tipo' => 'horarios', 'horarios' => ['08:00']])['erro'] === null);
$f = remarketingNormalizarFrequencia(['tipo' => 'a_cada', 'horas' => '6', 'data' => '2026-10-27', 'hora' => '08:00']);
confere('a cada X horas: o número e o 1º envio', $f['erro'] === null && $f['recorrencia'] === ['tipo' => 'a_cada', 'horas' => 6] && $f['primeira'] === '2026-10-27 08:00', $f);
confere('a cada X horas: 3 e 168 passam; 2, 169, 0, texto e lista não', remarketingNormalizarFrequencia(['tipo' => 'a_cada', 'horas' => 3, 'data' => '2026-10-27', 'hora' => '08:00'])['erro'] === null && remarketingNormalizarFrequencia(['tipo' => 'a_cada', 'horas' => 168, 'data' => '2026-10-27', 'hora' => '08:00'])['erro'] === null
    && remarketingNormalizarFrequencia(['tipo' => 'a_cada', 'horas' => 2, 'data' => '2026-10-27', 'hora' => '08:00'])['erro'] !== null && remarketingNormalizarFrequencia(['tipo' => 'a_cada', 'horas' => 169, 'data' => '2026-10-27', 'hora' => '08:00'])['erro'] !== null
    && remarketingNormalizarFrequencia(['tipo' => 'a_cada', 'horas' => 0])['erro'] !== null && remarketingNormalizarFrequencia(['tipo' => 'a_cada', 'horas' => 'abc'])['erro'] !== null && remarketingNormalizarFrequencia(['tipo' => 'a_cada', 'horas' => [6]])['erro'] !== null);
confere('a cada X horas sem o 1º envio: pede data e hora do primeiro envio', remarketingNormalizarFrequencia(['tipo' => 'a_cada', 'horas' => 6])['erro'] === 'Informe a data e a hora do primeiro envio.');
$f = remarketingNormalizarFrequencia(['tipo' => 'semana', 'dias' => [5, 1, '3', 1], 'hora' => '08:00']);
confere('dias da semana: sem repetir e em ordem', $f['erro'] === null && $f['recorrencia'] === ['tipo' => 'semana', 'dias' => [1, 3, 5], 'hora' => '08:00'] && $f['primeira'] === '', $f);
confere('dias da semana: nenhum dia marcado = a frase do Shark', remarketingNormalizarFrequencia(['tipo' => 'semana', 'dias' => [], 'hora' => '08:00'])['erro'] === 'Selecione pelo menos um dia da semana.' && remarketingNormalizarFrequencia(['tipo' => 'semana', 'hora' => '08:00'])['erro'] === 'Selecione pelo menos um dia da semana.');
confere('dias da semana: dia 7 ou texto não conta; sem horário pede o horário', remarketingNormalizarFrequencia(['tipo' => 'semana', 'dias' => [7, 'x', -1], 'hora' => '08:00'])['erro'] === 'Selecione pelo menos um dia da semana.' && remarketingNormalizarFrequencia(['tipo' => 'semana', 'dias' => [1]])['erro'] === 'Informe o horário de execução.');
confere('o domingo (0) é um dia válido', remarketingNormalizarFrequencia(['tipo' => 'semana', 'dias' => [0], 'hora' => '10:00'])['recorrencia']['dias'] === [0]);

// próxima execução (segunda-feira, 14:00)
$seg = strtotime('2026-10-26 14:00:00');
$prox = static fn(array $rec, int $nao_antes, ?int $ultimo = null): string => date('D d/m/Y H:i', remarketingProximaExecucao($rec, $nao_antes, $ultimo));
confere('diário às 21:00: ainda hoje', $prox(['tipo' => 'diario', 'hora' => '21:00'], $seg) === 'Mon 26/10/2026 21:00');
confere('diário às 09:00: já passou, amanhã', $prox(['tipo' => 'diario', 'hora' => '09:00'], $seg) === 'Tue 27/10/2026 09:00');
confere('diário no minuto exato: vale hoje (não antes = o próprio horário)', $prox(['tipo' => 'diario', 'hora' => '14:00'], $seg) === 'Mon 26/10/2026 14:00');
confere('horários: o próximo da lista', $prox(['tipo' => 'horarios', 'horarios' => ['09:00', '15:00', '21:00']], $seg) === 'Mon 26/10/2026 15:00');
confere('horários: passou o último, amanhã no primeiro', $prox(['tipo' => 'horarios', 'horarios' => ['09:00', '15:00', '21:00']], strtotime('2026-10-26 21:30:00')) === 'Tue 27/10/2026 09:00');
confere('dias da semana (seg, qua, sex às 08:00): hoje já passou, quarta', $prox(['tipo' => 'semana', 'dias' => [1, 3, 5], 'hora' => '08:00'], $seg) === 'Wed 28/10/2026 08:00');
confere('dias da semana (só segunda às 08:00): na semana que vem', $prox(['tipo' => 'semana', 'dias' => [1], 'hora' => '08:00'], $seg) === 'Mon 02/11/2026 08:00');
confere('dias da semana (só segunda às 15:00): ainda hoje', $prox(['tipo' => 'semana', 'dias' => [1], 'hora' => '15:00'], $seg) === 'Mon 26/10/2026 15:00');
confere('dias da semana (só domingo): dia 01/11', $prox(['tipo' => 'semana', 'dias' => [0], 'hora' => '10:00'], $seg) === 'Sun 01/11/2026 10:00');
confere('virada de ano: 31/12 às 23:00 → 01/01 às 09:00', $prox(['tipo' => 'diario', 'hora' => '09:00'], strtotime('2026-12-31 23:00:00')) === 'Fri 01/01/2027 09:00');
confere('a cada 6 horas conta do começo da execução anterior', $prox(['tipo' => 'a_cada', 'horas' => 6], strtotime('2026-10-26 17:00:00'), $seg) === 'Mon 26/10/2026 20:00');
confere('a cada 6 horas: execução longa, nunca antes do "não antes"', $prox(['tipo' => 'a_cada', 'horas' => 6], strtotime('2026-10-26 23:00:00'), $seg) === 'Mon 26/10/2026 23:00');
confere('a cada 6 horas sem execução anterior: conta do "não antes"', $prox(['tipo' => 'a_cada', 'horas' => 6], $seg) === 'Mon 26/10/2026 20:00');
confere('recorrência inválida: sem próxima', remarketingProximaExecucao(['tipo' => 'mensal'], $seg) === null && remarketingProximaExecucao([], $seg) === null);

// descrição
confere('descrição: diário e horários', remarketingDescreverRecorrencia(['tipo' => 'diario', 'hora' => '21:00']) === 'Todo dia às 21:00' && remarketingDescreverRecorrencia(['tipo' => 'horarios', 'horarios' => ['09:00', '15:00', '21:00']]) === 'Todo dia às 09:00, 15:00 e 21:00' && remarketingDescreverRecorrencia(['tipo' => 'horarios', 'horarios' => ['09:00', '21:00']]) === 'Todo dia às 09:00 e 21:00' && remarketingDescreverRecorrencia(['tipo' => 'horarios', 'horarios' => ['09:00']]) === 'Todo dia às 09:00');
confere('descrição: a cada X horas e dias da semana', remarketingDescreverRecorrencia(['tipo' => 'a_cada', 'horas' => 6]) === 'A cada 6 horas' && remarketingDescreverRecorrencia(['tipo' => 'semana', 'dias' => [1, 3, 5], 'hora' => '08:00']) === 'Seg, Qua e Sex às 08:00' && remarketingDescreverRecorrencia(['tipo' => 'semana', 'dias' => [6], 'hora' => '08:00']) === 'Sáb às 08:00' && remarketingDescreverRecorrencia(['tipo' => 'semana', 'dias' => [0, 1, 2, 3, 4, 5, 6], 'hora' => '08:00']) === 'Todo dia às 08:00');
confere('descrição: sem recorrência = uma vez', remarketingDescreverRecorrencia(null) === 'Uma vez');

// recorrência gravada na campanha
confere('recorrência gravada: JSON válido volta como lista', remarketingRecorrenciaDaCampanha(['recorrencia' => '{"tipo":"diario","hora":"21:00"}']) === ['tipo' => 'diario', 'hora' => '21:00']);
confere('recorrência gravada: vazio, NULL, lixo e valor que não vale mais = nenhuma', remarketingRecorrenciaDaCampanha(['recorrencia' => null]) === null && remarketingRecorrenciaDaCampanha([]) === null && remarketingRecorrenciaDaCampanha(['recorrencia' => 'lixo']) === null && remarketingRecorrenciaDaCampanha(['recorrencia' => '{"tipo":"a_cada","horas":1}']) === null && remarketingRecorrenciaDaCampanha(['recorrencia' => '{"tipo":"uma_vez"}']) === null);

// o relógio de 3 horas do fluxo
$hoje15 = strtotime('2026-10-26 15:00:00');
$linha = static fn(array $o): array => $o + ['id' => 1, 'nome' => 'Campanha', 'status' => 'concluida', 'agendado_em' => '2026-10-26 10:00:00', 'processado_em' => null, 'enviados' => 0, 'entregues' => 0];
confere('fluxo sem nenhuma outra campanha: livre', remarketingBloqueioDoGrupo([], 5, $hoje15) === null && remarketingBloqueioDoGrupo([$linha(['id' => 5])], 5, $hoje15) === null);
$b = remarketingBloqueioDoGrupo([$linha(['id' => 6, 'nome' => 'Black Friday', 'status' => 'processando'])], 5, $hoje15);
confere('outra campanha do fluxo em envio: espera ela terminar', $b !== null && $b['motivo'] === 'em_envio' && $b['livre_em'] === null && str_contains($b['mensagem'], 'Black Friday') && str_contains($b['mensagem'], '3 horas'), $b);
confere('a própria campanha em envio não se bloqueia', remarketingBloqueioDoGrupo([$linha(['id' => 5, 'status' => 'processando'])], 5, $hoje15) === null);
$b = remarketingBloqueioDoGrupo([$linha(['id' => 6, 'nome' => 'Oferta', 'processado_em' => '2026-10-26 14:00:00', 'entregues' => 40])], 5, $hoje15);
confere('outra campanha terminou há 1 h: libera 17:00', $b !== null && $b['motivo'] === 'intervalo' && date('H:i', $b['livre_em']) === '17:00' && str_contains($b['mensagem'], '14:00') && str_contains($b['mensagem'], '17:00') && str_contains($b['mensagem'], 'outros fluxos'), $b);
confere('terminou há 3 h exatas: livre', remarketingBloqueioDoGrupo([$linha(['id' => 6, 'processado_em' => '2026-10-26 12:00:00', 'entregues' => 40])], 5, $hoje15) === null);
confere('terminou há 2 h 59: ainda bloqueia', remarketingBloqueioDoGrupo([$linha(['id' => 6, 'processado_em' => '2026-10-26 12:01:00', 'entregues' => 40])], 5, $hoje15)['motivo'] === 'intervalo');
confere('terminou sem entregar nada (3 tentativas, todas com falha: bot com token recusado): não conta', remarketingBloqueioDoGrupo([$linha(['id' => 6, 'processado_em' => '2026-10-26 14:30:00', 'enviados' => 3, 'entregues' => 0, 'status' => 'falha'])], 5, $hoje15) === null);
$b = remarketingBloqueioDoGrupo([$linha(['id' => 5, 'processado_em' => '2026-10-26 14:00:00', 'entregues' => 1])], 5, $hoje15);
confere('REENVIAR a mesma campanha 1 h depois: bloqueia (o caso do Lima)', $b !== null && $b['motivo'] === 'intervalo' && date('H:i', $b['livre_em']) === '17:00', $b);
confere('reenviar 3 h depois: libera', remarketingBloqueioDoGrupo([$linha(['id' => 5, 'processado_em' => '2026-10-26 14:00:00', 'entregues' => 1])], 5, strtotime('2026-10-26 17:00:00')) === null);
confere('agendar para depois da liberação passa; para antes não', remarketingBloqueioDoGrupo([$linha(['id' => 5, 'processado_em' => '2026-10-26 14:00:00', 'entregues' => 1])], 5, strtotime('2026-10-26 17:30:00')) === null && remarketingBloqueioDoGrupo([$linha(['id' => 5, 'processado_em' => '2026-10-26 14:00:00', 'entregues' => 1])], 5, strtotime('2026-10-26 16:59:00')) !== null);
$agendada = $linha(['id' => 6, 'nome' => 'Noite', 'status' => 'pendente', 'agendado_em' => '2026-10-26 16:00:00']);
$b = remarketingBloqueioDoGrupo([$agendada], 5, strtotime('2026-10-26 17:30:00'));
confere('outra agendada a 1 h 30 de distância: bloqueia, com o nome e a hora', $b !== null && $b['motivo'] === 'agendada_perto' && str_contains($b['mensagem'], 'Noite') && str_contains($b['mensagem'], '26/10/2026 16:00'), $b);
confere('outra agendada a 3 h exatas (antes ou depois): passa', remarketingBloqueioDoGrupo([$agendada], 5, strtotime('2026-10-26 19:00:00')) === null && remarketingBloqueioDoGrupo([$agendada], 5, strtotime('2026-10-26 13:00:00')) === null);
confere('a própria campanha agendada (reagendar) não conta como "outra"', remarketingBloqueioDoGrupo([$agendada], 6, strtotime('2026-10-26 16:30:00')) === null);
$b = remarketingBloqueioDoGrupo([$agendada, $linha(['id' => 7, 'processado_em' => '2026-10-26 14:00:00', 'entregues' => 3])], 5, strtotime('2026-10-26 16:30:00'));
confere('envio recente e agendada perto: o envio recente vem primeiro', $b['motivo'] === 'intervalo', $b);
confere('campanha sem nome aparece como #id na mensagem', str_contains(remarketingBloqueioDoGrupo([$linha(['id' => 9, 'nome' => '', 'status' => 'processando'])], 5, $hoje15)['mensagem'], 'Campanha #9'));
$b = remarketingBloqueioDoGrupo([$linha(['id' => 6, 'processado_em' => '2026-10-26 13:00:00', 'entregues' => 9]), $linha(['id' => 7, 'processado_em' => '2026-10-26 14:30:00', 'entregues' => 9])], 5, $hoje15);
confere('vários envios recentes: vale o mais recente (libera 17:30)', date('H:i', $b['livre_em']) === '17:30', $b);

echo PHP_EOL . "RESUMO: $ok ok, $falhas falhas" . PHP_EOL;
exit($falhas > 0 ? 1 : 0);
