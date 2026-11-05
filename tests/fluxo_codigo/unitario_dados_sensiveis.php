<?php
declare(strict_types=1);

/**
 * Dados sensíveis que NÃO viajam no código de exportação (funcoes/fluxo_codigo.php, removerDadosSensiveisFluxo). Sem banco.
 *   php tests/fluxo_codigo/unitario_dados_sensiveis.php   (sai com código 1 se algo falhar)
 */

require_once dirname(__DIR__, 2) . '/funcoes/entrega.php';
require_once dirname(__DIR__, 2) . '/funcoes/fluxo_codigo.php';

$ok = 0;
$falhas = 0;
function confere(string $nome, bool $certo, string $detalhe = ''): void
{
    global $ok, $falhas;
    $certo ? $ok++ : $falhas++;
    echo ($certo ? 'OK     ' : 'FALHOU ') . $nome . ($certo || $detalhe === '' ? '' : '  -> ' . $detalhe) . PHP_EOL;
}
/** Todas as chaves-folha com o nome dado, em qualquer profundidade. */
function valoresDa(array $d, array $chaves): array
{
    $achou = [];
    array_walk_recursive($d, function ($v, $k) use ($chaves, &$achou) {
        if (in_array($k, $chaves, true)) {
            $achou[] = $v;
        }
    });
    return $achou;
}
function sobra(array $d): array
{
    return array_values(array_filter(valoresDa($d, FLUXO_CODIGO_CAMPOS_SENSIVEIS), fn($v) => $v !== '' && $v !== null));
}

// ---------- caso montado à mão ----------
$guiado = [
    'suporte' => '@meususuario', 'suporte_cor' => '#ff0000', 'cache_chat_id' => '-100123456',
    'boas_vindas' => ['mensagem' => 'Entre no grupo https://t.me/+AbCdEfGh123 e veja t.me/joinchat/XyZ ou https://t.me/c/123456/7. Site: https://meusite.com/vip', 'midia_path' => 'uploads/foto.jpg'],
    'planos' => [
        ['id' => 'p1', 'nome' => 'Mensal', 'valor' => 19.9, 'entrega_tipo' => 'grupo', 'id_grupo' => '-1001111', 'link_entrega' => '', 'mensagem_entrega' => ''],
        ['id' => 'p2', 'nome' => 'Vitalício', 'valor' => 99.0, 'entrega_tipo' => 'link', 'id_grupo' => '', 'link_entrega' => 'https://drive.com/privado', 'mensagem_entrega' => ''],
        ['id' => 'p3', 'nome' => 'Texto', 'valor' => 9.0, 'entrega_tipo' => 'mensagem', 'mensagem_entrega' => 'Login: ana / senha: 123'],
        ['id' => 'p4', 'nome' => 'Antigo', 'valor' => 5.0, 'id_grupo' => '-1002222'],
    ],
    'ofertas' => [
        'upsell' => ['ativo' => true, 'id_plano_destino' => 'p2', 'entrega_tipo' => 'plano', 'id_grupo' => ''],
        'downsell' => ['ativo' => true, 'sequencias_extras' => [['entrega_tipo' => 'grupo', 'id_grupo' => '-1003333', 'planos_oferecidos' => ['p1']]]],
    ],
    'packs' => ['itens' => [['nome' => 'Pack', 'preco' => 10, 'id_grupo' => '-1004444', 'midia_path' => 'uploads/pack.jpg']]],
];
$limpo = removerDadosSensiveisFluxo($guiado);
confere('Guiado: nenhum grupo, link, mensagem de entrega, suporte ou cache sobra', sobra($limpo) === [], json_encode(sobra($limpo)));
confere('Guiado: entrega por grupo, link ou mensagem vira "nenhuma"; "igual ao plano" fica',
    $limpo['planos'][0]['entrega_tipo'] === 'nenhuma' && $limpo['planos'][1]['entrega_tipo'] === 'nenhuma' && $limpo['planos'][2]['entrega_tipo'] === 'nenhuma'
    && $limpo['ofertas']['upsell']['entrega_tipo'] === 'plano' && $limpo['ofertas']['downsell']['sequencias_extras'][0]['entrega_tipo'] === 'nenhuma');
confere('Guiado: convites privados do Telegram saem do texto; o resto do texto e o site ficam',
    $limpo['boas_vindas']['mensagem'] === 'Entre no grupo  e veja  ou . Site: https://meusite.com/vip', $limpo['boas_vindas']['mensagem']);
confere('Guiado: nome, valor, ids dos planos, cor do suporte, ofertas e mídias ficam',
    $limpo['planos'][0]['nome'] === 'Mensal' && $limpo['planos'][1]['valor'] === 99.0 && $limpo['planos'][3]['id'] === 'p4' && $limpo['suporte_cor'] === '#ff0000'
    && $limpo['ofertas']['upsell']['id_plano_destino'] === 'p2' && $limpo['ofertas']['downsell']['sequencias_extras'][0]['planos_oferecidos'] === ['p1']
    && $limpo['boas_vindas']['midia_path'] === 'uploads/foto.jpg' && $limpo['packs']['itens'][0]['midia_path'] === 'uploads/pack.jpg');
confere('Guiado limpo continua válido para importar (validação de entrega do servidor)', validarEntregasFluxoGuiado($limpo) === null, (string) validarEntregasFluxoGuiado($limpo));
confere('o original não foi alterado (é uma cópia)', $guiado['planos'][0]['id_grupo'] === '-1001111' && $guiado['suporte'] === '@meususuario');
confere('rodar duas vezes dá o mesmo resultado', removerDadosSensiveisFluxo($limpo) === $limpo);

// ---------- Editor Visual ----------
$visual = [
    'operators' => [
        'a' => ['type' => 'grupo', 'properties' => ['id_grupo' => '-100999', 'nome' => 'VIP']],
        'b' => ['type' => 'pix', 'properties' => ['valor' => 20, 'id_grupo' => '-100888', 'link_entrega' => 'https://x.com/a', 'mensagem_entrega' => 'senha 1', 'nome' => 'Plano']],
        'c' => ['type' => 'message', 'properties' => ['conteudo' => 'Oi! Chame em t.me/+Convite999 agora']],
    ],
    'links' => ['l1' => ['fromOperator' => 'a', 'toOperator' => 'b']],
];
$vl = removerDadosSensiveisFluxo($visual);
confere('Visual: grupo, link e mensagem de entrega saem dos blocos; o convite sai do texto',
    $vl['operators']['a']['properties']['id_grupo'] === '' && $vl['operators']['b']['properties']['id_grupo'] === '' && $vl['operators']['b']['properties']['link_entrega'] === ''
    && $vl['operators']['b']['properties']['mensagem_entrega'] === '' && $vl['operators']['c']['properties']['conteudo'] === 'Oi! Chame em  agora', json_encode($vl));
confere('Visual: blocos, valores e ligações ficam', $vl['operators']['b']['properties']['valor'] === 20 && $vl['links'] === $visual['links'] && array_keys($vl['operators']) === ['a', 'b', 'c']);

// ---------- fluxos de exemplo do projeto ----------
foreach (glob(dirname(__DIR__, 2) . '/deploy/fluxos-teste/*.json') ?: [] as $arq) {
    $j = json_decode((string) file_get_contents($arq), true);
    $d = $j['dados_fluxograma'] ?? $j;
    $l = removerDadosSensiveisFluxo($d);
    confere(basename($arq) . ': nada sensível sobra e o tamanho do fluxo se mantém', sobra($l) === [] && count($l, COUNT_RECURSIVE) === count($d, COUNT_RECURSIVE), json_encode(sobra($l)));
    if (isset($l['planos'])) {
        confere(basename($arq) . ': continua válido para importar', validarEntregasFluxoGuiado($l) === null, (string) validarEntregasFluxoGuiado($l));
    }
}

// ---------- importação de código antigo ----------
confere('código de outra conta (guardado antes do filtro) sai limpo na importação', dadosFluxoParaImportar($guiado, true) === $limpo);
confere('código da própria conta não é refeito na importação', dadosFluxoParaImportar($limpo, false) === $limpo);

echo PHP_EOL . "$ok ok, $falhas falha(s)" . PHP_EOL;
exit($falhas > 0 ? 1 : 0);
