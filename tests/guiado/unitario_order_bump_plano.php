<?php
declare(strict_types=1);

/**
 * Order Bump PRÓPRIO do plano (planos[i].order_bump, 05/11/2026, igual ao da Shark): qual bump vale para cada plano, quando e quanto espera, texto da oferta
 * (CTA e botões padrão), entrega no Pix junto e no Pix avulso, plano pago a partir do nome da venda. Sem rede e sem MySQL: Telegram e motor de Pix são dublês.
 *   php tests/guiado/unitario_order_bump_plano.php   (sai com código 1 se algo falhar)
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
$GLOBALS['mensagens'] = [];
function requisicaoTelegram(string $token, string $metodo, array $parametros = [], array $arquivos = []): array
{
    $GLOBALS['mensagens'][] = ['metodo' => $metodo, 'p' => $parametros];
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
require_once dirname(__DIR__, 2) . '/funcoes/entrega.php';

$bump_plano = ['ativo' => true, 'nome' => 'Pack extra', 'valor_extra' => 12.5, 'mensagem' => 'Leve também o pack extra', 'cta' => '👆 CLIQUE ANTES QUE TIREM DO AR', 'entrega_tipo' => 'plano'];
$plano_a = ['id' => 'pa', 'nome' => 'Mensal', 'valor' => 30, 'order_bump' => $bump_plano];
$plano_b = ['id' => 'pb', 'nome' => 'Anual', 'valor' => 90];
$plano_c = ['id' => 'pc', 'nome' => 'Trimestral', 'valor' => 60, 'order_bump' => ['ativo' => false, 'nome' => 'Desligado', 'valor_extra' => 5]];
$plano_rec = ['id' => 'pr', 'nome' => 'Assinatura', 'valor' => 20, 'cobranca' => 'recorrente', 'order_bump' => $bump_plano];
$fluxo_bump = ['ativo' => true, 'quando' => 'depois_pix', 'espera_minutos' => 7, 'nome' => 'Do fluxo', 'valor_extra' => 9.9];
$dados = ['planos' => [$plano_a, $plano_b, $plano_c, $plano_rec], 'ofertas' => ['order_bump' => $fluxo_bump]];

// ---------- quem tem order bump próprio ----------
confere('plano com order_bump ligado tem bump próprio', planoTemBumpProprioBasico($plano_a));
confere('plano sem order_bump não tem', !planoTemBumpProprioBasico($plano_b));
confere('order_bump desligado no plano: não é próprio (vale o do fluxo)', !planoTemBumpProprioBasico($plano_c));
confere('plano nulo não quebra', !planoTemBumpProprioBasico(null));

// ---------- qual bump vale ----------
$itens_a = itensOrderBumpBasico($dados, $plano_a);
confere('plano A: o bump do plano substitui o do fluxo (1 item, o dele)', count($itens_a) === 1 && $itens_a[0]['nome'] === 'Pack extra' && (float) $itens_a[0]['valor_extra'] === 12.5, json_encode($itens_a));
$itens_b = itensOrderBumpBasico($dados, $plano_b);
confere('plano B (sem bump próprio): vale o do fluxo', count($itens_b) === 1 && $itens_b[0]['nome'] === 'Do fluxo', json_encode($itens_b));
$itens_c = itensOrderBumpBasico($dados, $plano_c);
confere('plano C (bump do plano desligado): vale o do fluxo', count($itens_c) === 1 && $itens_c[0]['nome'] === 'Do fluxo');
confere('sem plano: o do fluxo, como sempre', itensOrderBumpBasico($dados)[0]['nome'] === 'Do fluxo');
$sem_valor = $plano_a;
$sem_valor['order_bump']['valor_extra'] = 0;
confere('bump do plano ligado com preço zero: nenhum item (não cai no do fluxo)', itensOrderBumpBasico($dados, $sem_valor) === []);

// ---------- quando enviar e quanto espera ----------
confere('plano A com fluxo "depois do Pix": o bump do plano também sai depois do Pix', orderBumpDepoisDoPixBasico($dados, $plano_a));
$cfg = configOrderBumpDoPlanoBasico($dados, $plano_a);
confere('espera do plano herda a do fluxo (7 min)', esperaOrderBumpBasico($cfg['itens'][0]) === 7, (string) esperaOrderBumpBasico($cfg['itens'][0]));
$dados_pos = $dados;
$dados_pos['ofertas']['order_bump']['quando'] = 'depois_pagamento';
confere('fluxo "depois de pagar": o bump do plano também sai depois de pagar', !orderBumpDepoisDoPixBasico($dados_pos, $plano_a));
$dados_sem_fluxo = ['planos' => [$plano_a], 'ofertas' => []];
confere('fluxo sem order bump: o do plano sai depois do Pix mesmo assim', orderBumpDepoisDoPixBasico($dados_sem_fluxo, $plano_a));
$cfg2 = configOrderBumpDoPlanoBasico($dados_sem_fluxo, $plano_a);
confere('... com a espera padrão (10 min)', esperaOrderBumpBasico($cfg2['itens'][0]) === ORDER_BUMP_ESPERA_PADRAO_MINUTOS);
$dados_fluxo_off = $dados;
$dados_fluxo_off['ofertas']['order_bump']['ativo'] = false;
confere('fluxo com order bump DESLIGADO: plano B não tem bump nenhum', !orderBumpDepoisDoPixBasico($dados_fluxo_off, $plano_b) && orderBumpDepoisDoPixBasico($dados_fluxo_off, $plano_a));

// ---------- texto da oferta ----------
$oferta = comCtaOrderBumpBasico($itens_a[0]);
confere('CTA vai numa linha própria no fim da descrição', $oferta['mensagem'] === "Leve também o pack extra\n\n👆 CLIQUE ANTES QUE TIREM DO AR", $oferta['mensagem']);
confere('botões padrão do plano (como na Shark)', $oferta['texto_aceitar'] === '✅ ADICIONAR' && $oferta['texto_recusar'] === '❌ NÃO QUERO', $oferta['texto_aceitar'] . ' / ' . $oferta['texto_recusar']);
$proprio = $itens_a[0];
$proprio['texto_aceitar'] = 'QUERO!';
confere('botão escrito pelo dono vale', comCtaOrderBumpBasico($proprio)['texto_aceitar'] === 'QUERO!');
$item_fluxo = itensOrderBumpBasico($dados, $plano_b)[0];
confere('item do fluxo não ganha CTA nem botões novos', comCtaOrderBumpBasico($item_fluxo) === $item_fluxo);
$GLOBALS['mensagens'] = [];
enviarOfertaBasico('TOKEN', '777', $oferta, 'basico::bs::pa::0', textoPadraoOrderBumpBasico($oferta));
$msg = $GLOBALS['mensagens'][0] ?? [];
$botoes = json_decode((string) ($msg['p']['reply_markup'] ?? '{}'), true)['inline_keyboard'][0] ?? [];
confere('mensagem enviada leva descrição + CTA e os dois botões', strpos((string) ($msg['p']['text'] ?? ''), 'CLIQUE ANTES') !== false && count($botoes) === 2 && ($botoes[0]['callback_data'] ?? '') === 'basico::bs::pa::0::1', json_encode($msg));

// ---------- plano pago, pelo nome da venda ----------
confere('plano pago: nome igual', (planoPagoDaVendaBasico($dados, 'Mensal')['id'] ?? '') === 'pa');
confere('plano pago: sem diferença de maiúsculas', (planoPagoDaVendaBasico($dados, 'mensal')['id'] ?? '') === 'pa');
confere('plano pago: venda com bump junto ("Plano + Extra")', (planoPagoDaVendaBasico($dados, 'Mensal + Pack extra')['id'] ?? '') === 'pa');
confere('plano pago: nome desconhecido', planoPagoDaVendaBasico($dados, 'Outro') === null);

// ---------- entrega no Pix junto (uma venda só) ----------
$entrega_plano = ['id_grupo' => '-100111', 'link_entrega' => '', 'mensagem_entrega' => 'Bem-vindo!'];
confere('bump "mesmo delivery do plano": a entrega do plano não muda', entregaDoPixComBumpBasico($entrega_plano, ['entrega_tipo' => 'plano']) === $entrega_plano);
$com_msg = entregaDoPixComBumpBasico($entrega_plano, ['entrega_tipo' => 'mensagem', 'mensagem_entrega' => 'Seu extra: https://x.com/extra']);
confere('bump com mensagem própria: segue na mensagem de entrega, junto da do plano', $com_msg['id_grupo'] === '-100111' && $com_msg['mensagem_entrega'] === "Bem-vindo!\n\nSeu extra: https://x.com/extra", json_encode($com_msg));
$com_link = entregaDoPixComBumpBasico(['id_grupo' => '', 'link_entrega' => 'https://plano.com', 'mensagem_entrega' => ''], ['entrega_tipo' => 'link', 'link_entrega' => 'https://extra.com/a']);
confere('bump com link próprio: o endereço vai na mensagem; o link do plano segue', $com_link['link_entrega'] === 'https://plano.com' && $com_link['mensagem_entrega'] === 'https://extra.com/a', json_encode($com_link));
confere('item do fluxo (sem entrega_tipo) não muda a entrega', entregaDoPixComBumpBasico($entrega_plano, ['nome' => 'x']) === $entrega_plano);
confere('link inválido do bump não entra', entregaDoPixComBumpBasico($entrega_plano, ['entrega_tipo' => 'link', 'link_entrega' => 'javascript:alert(1)']) === $entrega_plano);

// ---------- Pix avulso depois do pagamento ----------
$dados_pos2 = ['planos' => [$plano_a, $plano_b, $plano_rec], 'ofertas' => ['order_bump' => ['ativo' => true, 'quando' => 'depois_pagamento', 'nome' => 'Do fluxo', 'valor_extra' => 9.9]]];
$GLOBALS['pix_gerados'] = [];
gerarPixOfertaPosBasico('TOKEN', '777', $dados_pos2, 'bp', 'pa');
$p = $GLOBALS['pix_gerados'][0] ?? [];
confere('aceite do bump do plano A: Pix só do extra, com o valor e o nome do plano', abs(($p['valor'] ?? 0) - 12.5) < 0.001 && ($p['nome'] ?? '') === 'Extra: Pack extra', json_encode($p));
confere('"mesmo delivery do plano" no Pix avulso: sem entrega nova', ($p['id_grupo'] ?? 'x') === '' && ($p['link_entrega'] ?? 'x') === '' && ($p['mensagem_entrega'] ?? 'x') === '', json_encode($p));
$GLOBALS['pix_gerados'] = [];
gerarPixOfertaPosBasico('TOKEN', '777', $dados_pos2, 'bp', '0');
$p = $GLOBALS['pix_gerados'][0] ?? [];
confere('callback antigo ("0"): continua o bump do fluxo', abs(($p['valor'] ?? 0) - 9.9) < 0.001 && ($p['nome'] ?? '') === 'Extra: Do fluxo', json_encode($p));
$GLOBALS['pix_gerados'] = [];
$dados_msg = $dados_pos2;
$dados_msg['planos'][0]['order_bump']['entrega_tipo'] = 'mensagem';
$dados_msg['planos'][0]['order_bump']['mensagem_entrega'] = 'Acesse https://x.com/bonus';
gerarPixOfertaPosBasico('TOKEN', '777', $dados_msg, 'bp', 'pa');
$p = $GLOBALS['pix_gerados'][0] ?? [];
confere('bump do plano com mensagem própria: o Pix avulso entrega a mensagem', ($p['mensagem_entrega'] ?? '') === 'Acesse https://x.com/bonus', json_encode($p));
$GLOBALS['pix_gerados'] = [];
$GLOBALS['mensagens'] = [];
gerarPixOfertaPosBasico('TOKEN', '777', $dados_pos2, 'bp', 'pr');
confere('plano de assinatura não tem order bump (oferta indisponível, nenhum Pix)', $GLOBALS['pix_gerados'] === [] && ($GLOBALS['mensagens'][0]['p']['text'] ?? '') === 'Essa oferta não está mais disponível.');
$GLOBALS['pix_gerados'] = [];
$dados_off = $dados_pos2;
$dados_off['planos'][0]['order_bump']['ativo'] = false;
gerarPixOfertaPosBasico('TOKEN', '777', $dados_off, 'bp', 'pa');
$p = $GLOBALS['pix_gerados'][0] ?? [];
confere('bump do plano desligado depois de enviado: vale o do fluxo (mensagem antiga não vira erro)', abs(($p['valor'] ?? 0) - 9.9) < 0.001, json_encode($p));

// ---------- validação de entrega do bump do plano (servidor) ----------
$dados_val = ['planos' => [array_merge($plano_a, ['order_bump' => $bump_plano + ['nome' => 'X']])]];
confere('entrega válida: sem erro', validarEntregasFluxoGuiado($dados_val) === null);
$dados_val['planos'][0]['order_bump']['entrega_tipo'] = 'link';
$dados_val['planos'][0]['order_bump']['link_entrega'] = 'xyz';
confere('link inválido no bump do plano: recusa e cita o bump', strpos((string) validarEntregasFluxoGuiado($dados_val), 'order bump do plano') !== false, (string) validarEntregasFluxoGuiado($dados_val));
$dados_val['planos'][0]['order_bump']['entrega_tipo'] = 'mensagem';
$dados_val['planos'][0]['order_bump']['mensagem_entrega'] = '';
confere('mensagem vazia no bump do plano: recusa', validarEntregasFluxoGuiado($dados_val) !== null);
$dados_val['planos'][0]['order_bump']['ativo'] = false;
confere('bump do plano desligado não é conferido', validarEntregasFluxoGuiado($dados_val) === null);

echo PHP_EOL . "$ok ok, $falhas falha(s)" . PHP_EOL;
exit($falhas > 0 ? 1 : 0);
