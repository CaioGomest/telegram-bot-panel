<?php
declare(strict_types=1);

/**
 * Gera (em JSON, na saída) os resultados de referência do PHP para o teste que compara com a prévia em JavaScript:
 *   node tests/guiado/unitario_pagamentos_previa.js   (chama este script sozinho)
 * Cada caso traz a ENTRADA e o que funcoes/pagamentos_guiado.php devolveu; o teste em Node recalcula a partir da entrada com assets/pagamentos_guiado.js.
 */

function acessoVitalicio($minutos): bool
{
    return (int) $minutos >= 36500 * 1440;
}
require_once dirname(__DIR__, 2) . '/funcoes/pagamentos_guiado.php';

$textos = [
    '', 'simples', '<b>negrito</b> e <i>itálico</i>', '<strong>a</strong><em>b</em><ins>c</ins><del>d</del><strike>e</strike>', '<code>x</code><pre>y</pre><blockquote>z</blockquote><tg-spoiler>w</tg-spoiler>',
    '<script>alert(1)</script><a href="http://x">l</a><img src=x>', '<b class="x">a</b>', 'a & b < c > d', '<b>oi <i>você', 'oi</b> tchau', '<b><i>x</b>y', '<B>x</B><I>y</I>',
    '{saudacao}, {nome}! {plano} por {valor}', '{uf} e {nome} e {codigo_pix}', "linha1\r\nlinha2\rlinha3", "Olá <b>{nome}</b>,\n\nSeu plano <b>{plano}</b> ({valor}).", '&amp; &lt; já escapado', 'emoji 🎉 e acentuação ção',
    str_repeat('ab', 1700), '<b>' . str_repeat('x', 3100), '{nome}{nome}{plano}', '<>', '< b>x</ b>', '<b >x</b >',
];
$variaveis = [
    ['nome' => 'João Silva', 'plano' => 'Premium <VIP>', 'valor' => 29.9, 'hora' => 9],
    ['nome' => '  ', 'plano' => 'P & Q', 'valor' => 1234567.891, 'hora' => 14],
    ['nome' => '<b>Hack</b>', 'plano' => 'x', 'valor' => 0, 'hora' => 23],
    ['nome' => "Ana\tMaria Souza", 'plano' => 'Plano "Ouro"', 'valor' => 0.05, 'hora' => 12],
];
$casos_texto = [];
foreach ($textos as $t) {
    foreach ($variaveis as $v) {
        $vars = pagamentoVariaveis($v['nome'], $v['plano'], (float) $v['valor'], $v['hora']);
        $vars['codigo_pix'] = ['html' => '<code>COD&amp;123</code>'];
        $casos_texto[] = ['texto' => $t, 'v' => $v, 'html' => pagamentoHtmlSeguro($t, $vars), 'curto' => pagamentoHtmlSeguro($t, $vars, 40)];
    }
}

$configs_brutos = [
    [], ['layout' => 'compacto'], ['layout' => 'x', 'qr_exibicao' => 'y', 'codigo_formato' => 'z'], ['qr_exibicao' => 'antes'], ['qr_exibicao' => 'junto'], ['qr_exibicao' => 'nao'], ['qr_exibicao' => 'depois'],
    ['codigo_formato' => 'bloco'], ['codigo_formato' => 'simples'], ['codigo_na_mensagem' => true],
    ['msg_instrucoes' => 'Pague agora, {nome}'], ['msg_instrucoes' => "Cole isto: {codigo_pix}\nObrigado"], ['msg_instrucoes' => '  espaços nas pontas  '],
    ['msg_instrucoes' => str_repeat('a', 1500), 'qr_exibicao' => 'junto'],
    ['msg_instrucoes' => 'T', 'antes_codigo' => ['ativo' => true, 'texto' => 'Copie abaixo, {nome}:']],
    ['msg_instrucoes' => 'T', 'antes_botoes' => ['ativo' => true, 'texto' => 'Depois de pagar, toque abaixo']],
    ['msg_instrucoes' => 'T', 'antes_codigo' => ['ativo' => true], 'antes_botoes' => ['ativo' => true], 'qr_exibicao' => 'antes', 'midias_gerado' => [['tipo' => 'image', 'path' => 'uploads/a.jpg'], ['tipo' => 'video', 'path' => 'uploads/b.mp4']]],
    ['msg_instrucoes' => 'T', 'antes_botoes' => ['ativo' => true], 'codigo_na_mensagem' => true],
    ['msg_instrucoes' => 'T', 'btn_copiar' => ['ativo' => false], 'btn_verificar' => ['ativo' => false], 'antes_botoes' => ['ativo' => true]],
    ['msg_instrucoes' => 'T', 'btn_copiar' => ['ativo' => false, 'texto' => '   '], 'btn_verificar' => ['texto' => 'Já paguei']],
    ['btn_copiar' => ['texto' => str_repeat('c', 99)], 'antes_codigo' => ['ativo' => true, 'texto' => str_repeat('d', 500)]],
    ['midias_gerado' => [['tipo' => 'image', 'path' => 'uploads/a.jpg'], ['tipo' => 'audio', 'path' => 'uploads/b.mp3'], ['tipo' => 'video', 'path' => ''], 'lixo', ['tipo' => 'video', 'path' => 'uploads/c.mp4'], ['tipo' => 'image', 'path' => 'uploads/d.jpg'], ['tipo' => 'image', 'path' => 'uploads/e.jpg']]],
    ['msg_confirmado' => '🎉 Parabéns {nome}! {plano} ({valor}) confirmado.', 'midias_aprovado' => [['tipo' => 'image', 'path' => 'uploads/ok.jpg']]],
    ['msg_confirmado' => 'Valeu', 'botao_acesso' => ['ativo' => false]], ['botao_acesso' => ['ativo' => true, 'texto' => 'Entrar']], ['botao_acesso' => ['texto' => '']],
];
$ctxs_pix = [
    ['nome' => 'João Silva', 'plano' => 'Premium VIP', 'valor' => 29.9, 'codigo' => '00020126580014br.gov.bcb.pix0136abc-123', 'txid' => 'TX1', 'tem_qr' => true, 'hora' => 20],
    ['nome' => '', 'plano' => '<b>Hack</b>', 'valor' => 1.6, 'codigo' => 'AB<C&D', 'txid' => 'TX2', 'tem_qr' => false, 'hora' => 7],
    ['nome' => 'Maria', 'plano' => 'Sem código', 'valor' => 9.9, 'codigo' => '', 'txid' => 'TX3', 'tem_qr' => true, 'hora' => 13],
    ['nome' => 'Cli', 'plano' => 'Código longo', 'valor' => 5, 'codigo' => str_repeat('9', 300), 'txid' => 'TX4', 'tem_qr' => true, 'hora' => 19],
    ['nome' => 'Sem txid', 'plano' => 'P', 'valor' => 2, 'codigo' => 'CODIGO', 'txid' => '', 'tem_qr' => true, 'hora' => 3],
];
$ctxs_aprovacao = [
    ['nome' => 'Maria Souza', 'plano' => 'VIP', 'valor' => 49.9, 'link_grupo' => 'https://t.me/+AbCdEf', 'tempo_minutos' => 43200, 'hora' => 10],
    ['nome' => 'Maria', 'plano' => 'VIP', 'valor' => 49.9, 'link_grupo' => null, 'grupo_falhou' => true, 'tempo_minutos' => 0, 'hora' => 10],
    ['nome' => 'Maria', 'plano' => 'VIP', 'valor' => 49.9, 'link_grupo' => null, 'link_entrega' => 'https://area.exemplo.com/vip', 'hora' => 15],
    ['nome' => 'Maria', 'plano' => 'VIP', 'valor' => 49.9, 'link_grupo' => 'https://t.me/+x', 'tempo_minutos' => 36500 * 1440, 'hora' => 19],
    ['nome' => 'Maria', 'plano' => 'VIP', 'valor' => 49.9, 'link_grupo' => 'https://t.me/+x', 'tempo_minutos' => 120, 'hora' => 19],
    ['nome' => 'Maria', 'plano' => 'VIP', 'valor' => 49.9, 'link_grupo' => 'https://t.me/+x', 'tempo_minutos' => 45, 'hora' => 19],
    ['nome' => 'Maria', 'plano' => 'VIP', 'valor' => 49.9, 'link_grupo' => 'https://t.me/<x>&y', 'link_entrega' => 'https://area.exemplo.com/a&b', 'tempo_minutos' => 90, 'hora' => 19],
    ['nome' => 'A', 'plano' => 'P', 'valor' => 1, 'hora' => 5],
];

$configs = [];
foreach ($configs_brutos as $bruto) {
    $configs[] = ['bruto' => $bruto, 'pg' => pagamentoConfig($bruto)];
}
$planos_pix = [];
$aprovacoes = [];
foreach ($configs as $i => $c) {
    foreach ($ctxs_pix as $k => $ctx) {
        $planos_pix[] = ['config' => $i, 'ctx' => $k, 'passos' => pagamentoPlanoPix($c['pg'], $ctx), 'teclado' => pagamentoTeclado($c['pg'], $ctx)];
    }
    foreach ($ctxs_aprovacao as $k => $ctx) {
        $aprovacoes[] = ['config' => $i, 'ctx' => $k, 'plano' => pagamentoPlanoAprovacao($c['pg'], $ctx)];
    }
}
echo json_encode([
    'textos' => $casos_texto, 'configs' => $configs, 'ctxs_pix' => $ctxs_pix, 'ctxs_aprovacao' => $ctxs_aprovacao, 'planos_pix' => $planos_pix, 'aprovacoes' => $aprovacoes,
    'valores' => array_map(fn($v) => [$v, pagamentoValorTexto((float) $v)], [0, 0.05, 1.6, 29.9, 999.99, 1000, 1234567.891]),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
