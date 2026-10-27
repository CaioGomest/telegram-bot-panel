<?php
declare(strict_types=1);

/**
 * Teste unitário da aba Pagamentos do Guiado, modelo novo (funcoes/pagamentos_guiado.php): texto seguro com variáveis, normalização da configuração,
 * o PLANO do que o cliente recebe no Pix gerado e no pagamento aprovado (ordem, botões, QR) e os envios, com Telegram falso e SQLite. Sem rede e sem MySQL.
 *   php tests/guiado/unitario_pagamentos.php   (sai com código 1 se algo falhar)
 */

$ok = 0;
$falhas = 0;
function confere(string $nome, bool $certo, string $detalhe = ''): void
{
    global $ok, $falhas;
    $certo ? $ok++ : $falhas++;
    echo ($certo ? 'OK     ' : 'FALHOU ') . $nome . ($certo || $detalhe === '' ? '' : '  -> ' . $detalhe) . PHP_EOL;
}
function j($v): string
{
    return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

// ---- dublês do que vem de funcoes/fluxo_blocos.php e conexao.php
$GLOBALS['chamadas'] = [];
$GLOBALS['responder'] = null;   // callable(metodo, parametros): array | null
function requisicaoTelegram(string $token, string $metodo, array $parametros = [], array $arquivos = []): array
{
    $GLOBALS['chamadas'][] = ['metodo' => $metodo, 'p' => $parametros, 'arquivos' => $arquivos];
    $r = $GLOBALS['responder'] ? ($GLOBALS['responder'])($metodo, $parametros) : null;
    return $r ?? ['ok' => true, 'result' => []];
}
function resolverCaminhoUploadSeguro(string $caminho): ?string
{
    return ($caminho === '' || strpos($caminho, 'uploads/') !== 0 || strpos($caminho, '..') !== false) ? null : '/var/www/painel/' . $caminho;
}
function acessoVitalicio($minutos): bool
{
    return (int) $minutos >= 36500 * 1440;
}
require_once dirname(__DIR__, 2) . '/funcoes/qrcode.php';
require_once dirname(__DIR__, 2) . '/funcoes/guiado_ofertas.php';   // obterFileIdCacheMidia, enviarAlbumBasico e (via entrega.php) linkEntregaValido de verdade
require_once dirname(__DIR__, 2) . '/funcoes/pagamentos_guiado.php';
function limpar(?callable $resp = null): void
{
    $GLOBALS['chamadas'] = [];
    $GLOBALS['responder'] = $resp;
}
function metodos(): string
{
    return implode(',', array_map(fn($c) => $c['metodo'], $GLOBALS['chamadas']));
}
function tecladoDe(array $chamada): array
{
    return json_decode((string) ($chamada['p']['reply_markup'] ?? '{}'), true)['inline_keyboard'] ?? [];
}
function passos_resumo(array $passos): string
{
    return implode(' | ', array_map(function ($p) {
        $t = $p['t'];
        return $t . ($t === 'midias' ? '(' . count($p['midias']) . ')' : '') . (!empty($p['botoes']) ? '+botoes' : '');
    }, $passos));
}

// ===================== texto seguro =====================
echo "--- Texto: tags permitidas, escape e variáveis\n";
confere('tags permitidas passam e alias viram a tag do Telegram (strong→b, em→i, ins→u, del→s)', pagamentoHtmlSeguro('<strong>a</strong> <em>b</em> <ins>c</ins> <del>d</del> <b>e</b>') === '<b>a</b> <i>b</i> <u>c</u> <s>d</s> <b>e</b>', pagamentoHtmlSeguro('<strong>a</strong> <em>b</em> <ins>c</ins> <del>d</del> <b>e</b>'));
confere('code, pre, blockquote e tg-spoiler passam', pagamentoHtmlSeguro('<code>x</code><pre>y</pre><blockquote>z</blockquote><tg-spoiler>w</tg-spoiler>') === '<code>x</code><pre>y</pre><blockquote>z</blockquote><tg-spoiler>w</tg-spoiler>');
confere('tag não permitida (<script>, <a>, <img>) vira texto escapado: nenhum link nem script chega ao Telegram', pagamentoHtmlSeguro('<script>alert(1)</script><a href="http://x">l</a><img src=x>') === '&lt;script&gt;alert(1)&lt;/script&gt;&lt;a href="http://x"&gt;l&lt;/a&gt;&lt;img src=x&gt;', pagamentoHtmlSeguro('<script>alert(1)</script><a href="http://x">l</a><img src=x>'));
confere('tag com atributo (<b class="x">) não vale: escapada', strpos(pagamentoHtmlSeguro('<b class="x">a</b>'), '<b class') === false && strpos(pagamentoHtmlSeguro('<b class="x">a</b>'), '&lt;b class') !== false);
confere('& e < soltos são escapados', pagamentoHtmlSeguro('a & b < c > d') === 'a &amp; b &lt; c &gt; d');
confere('tag aberta e esquecida é fechada no fim (o Telegram recusa HTML torto)', pagamentoHtmlSeguro('<b>oi <i>você') === '<b>oi <i>você</i></b>');
confere('fechamento sem abertura some', pagamentoHtmlSeguro('oi</b> tchau') === 'oi tchau');
confere('fechamento fora de ordem fecha o que está aberto por dentro (<b><i>x</b> → <b><i>x</i></b>)', pagamentoHtmlSeguro('<b><i>x</b>y') === '<b><i>x</i></b>y', pagamentoHtmlSeguro('<b><i>x</b>y'));
confere('maiúsculas na tag (<B>) funcionam', pagamentoHtmlSeguro('<B>x</B>') === '<b>x</b>');
confere('texto acima de 3000 caracteres é cortado antes de renderizar e as tags abertas fecham', mb_strlen(pagamentoHtmlSeguro('<b>' . str_repeat('a', 5000))) === 3000 + 4, (string) mb_strlen(pagamentoHtmlSeguro('<b>' . str_repeat('a', 5000))));
$v = pagamentoVariaveis('João Silva', 'Premium <VIP>', 29.9, 9);
confere('variáveis: {nome} é o primeiro nome, {plano} escapado, {valor} em reais, {saudacao} pela hora', pagamentoHtmlSeguro('{saudacao}, {nome}! {plano} por {valor}', $v) === 'Bom dia, João! Premium &lt;VIP&gt; por R$ 29,90', pagamentoHtmlSeguro('{saudacao}, {nome}! {plano} por {valor}', $v));
confere('um nome com HTML não vira tag (o valor da variável é escapado depois de montado o texto)', strpos(pagamentoHtmlSeguro('Oi {nome}', pagamentoVariaveis('<b>Hack</b>', 'x', 1)), '<b>') === false);
confere('variável desconhecida ({uf}) fica como o dono escreveu', pagamentoHtmlSeguro('{uf} e {nome}', $v) === '{uf} e João');
confere('sem nome: "Cliente"', pagamentoVariaveis('', 'p', 1)['nome'] === 'Cliente' && pagamentoVariaveis('  ', 'p', 1)['nome'] === 'Cliente');
confere('saudação: 5h bom dia, 12h boa tarde, 17h boa tarde, 18h boa noite, 0h boa noite', pagamentoSaudacao(5) === 'Bom dia' && pagamentoSaudacao(12) === 'Boa tarde' && pagamentoSaudacao(17) === 'Boa tarde' && pagamentoSaudacao(18) === 'Boa noite' && pagamentoSaudacao(0) === 'Bom dia');
confere('HTML → texto puro (reserva): sem tags e com as entidades de volta', pagamentoHtmlParaTexto('<b>a &amp; b</b> &lt;c&gt;') === 'a & b <c>');

// ===================== configuração =====================
echo "\n--- Configuração normalizada\n";
$padrao = pagamentoConfig([]);
confere('vazio: padrões (layout padrão, QR depois do texto, código inline, botões ligados com texto, botão de acesso ligado, antes dos botões e antes do código desligados)',
    $padrao['layout'] === 'padrao' && $padrao['qr_exibicao'] === 'depois' && $padrao['codigo_formato'] === 'inline' && $padrao['btn_copiar']['ativo'] === true && $padrao['btn_verificar']['ativo'] === true
    && $padrao['antes_botoes']['ativo'] === false && $padrao['antes_codigo']['ativo'] === false && $padrao['botao_acesso']['ativo'] === true && $padrao['codigo_na_mensagem'] === false && $padrao['versao'] === 2, j($padrao));
confere('os campos antigos que o resto do sistema lê andam juntos: mostrar_copiar/mostrar_confirmar/mostrar_qrcode refletem os novos', pagamentoConfig(['btn_copiar' => ['ativo' => false], 'btn_verificar' => ['ativo' => false], 'qr_exibicao' => 'nao'])['mostrar_copiar'] === false
    && pagamentoConfig(['btn_copiar' => ['ativo' => false], 'btn_verificar' => ['ativo' => false], 'qr_exibicao' => 'nao'])['mostrar_confirmar'] === false && pagamentoConfig(['qr_exibicao' => 'nao'])['mostrar_qrcode'] === false && pagamentoConfig(['qr_exibicao' => 'antes'])['mostrar_qrcode'] === true && $padrao['qrcode_definido'] === true);
confere('valor fora da lista (layout/QR/formato) volta ao padrão', pagamentoConfig(['layout' => 'x', 'qr_exibicao' => 'y', 'codigo_formato' => 'z'])['layout'] === 'padrao' && pagamentoConfig(['qr_exibicao' => 'y'])['qr_exibicao'] === 'depois' && pagamentoConfig(['codigo_formato' => 'z'])['codigo_formato'] === 'inline');
confere('limites: texto até 3000, mensagens curtas até 300, botão até 40; texto de botão vazio volta ao padrão', mb_strlen(pagamentoConfig(['msg_instrucoes' => str_repeat('a', 4000)])['msg_instrucoes']) === 3000
    && mb_strlen(pagamentoConfig(['antes_botoes' => ['ativo' => true, 'texto' => str_repeat('b', 500)]])['antes_botoes']['texto']) === 300 && mb_strlen(pagamentoConfig(['btn_copiar' => ['texto' => str_repeat('c', 99)]])['btn_copiar']['texto']) === 40
    && pagamentoConfig(['btn_copiar' => ['texto' => '   ']])['btn_copiar']['texto'] === '📋 Copiar Código');
$mid = pagamentoConfig(['midias_gerado' => [['tipo' => 'image', 'path' => 'uploads/a.jpg'], ['tipo' => 'audio', 'path' => 'uploads/b.mp3'], ['tipo' => 'video', 'path' => ''], 'lixo', ['tipo' => 'video', 'path' => 'uploads/c.mp4'], ['tipo' => 'image', 'path' => 'uploads/d.jpg'], ['tipo' => 'image', 'path' => 'uploads/e.jpg']]]);
confere('mídias: só foto/vídeo com caminho, no máximo 3', count($mid['midias_gerado']) === 3 && $mid['midias_gerado'][1]['path'] === 'uploads/c.mp4', j($mid['midias_gerado']));
confere('mídias: caminho inválido (fora de uploads/) é descartado quando há conferência', count(pagamentoConfig(['midias_aprovado' => [['tipo' => 'image', 'path' => '../config.php'], ['tipo' => 'image', 'path' => 'uploads/ok.jpg']]], fn($c) => strpos($c, 'uploads/') === 0 && strpos($c, '..') === false)['midias_aprovado']) === 1);
confere('modelo novo só com versao >= 2; sem ela o sistema segue como sempre', pagamentoModoNovo(['versao' => 2]) && !pagamentoModoNovo([]) && !pagamentoModoNovo(['versao' => 1, 'msg_instrucoes' => 'x']));
$antigo = ['msg_instrucoes' => 'Pague', 'msg_confirmado' => 'Ok', 'mostrar_copiar' => false, 'mostrar_qrcode' => false, 'qrcode_definido' => true, 'mostrar_confirmar' => true];
confere('fluxo antigo: as propriedades do Pix são EXATAMENTE as de sempre (sem pix_v2 nem pg)', propriedadesPagamentoBasico($antigo) === ['mostrar_copiar' => false, 'mostrar_qrcode' => false, 'mostrar_confirmar' => true, 'msg_instrucoes' => 'Pague', 'msg_confirmado' => 'Ok'], j(propriedadesPagamentoBasico($antigo)));
confere('fluxo antigo sem qrcode_definido: QR ligado como sempre', propriedadesPagamentoBasico(['msg_instrucoes' => 'x'])['mostrar_qrcode'] === true && propriedadesPagamentoBasico([])['mostrar_copiar'] === true);
$novo = propriedadesPagamentoBasico(['versao' => 2, 'msg_instrucoes' => 'Pague', 'qr_exibicao' => 'junto']);
confere('modelo novo: pix_v2 + pg + os campos de sempre coerentes', $novo['pix_v2'] === true && $novo['pg']['qr_exibicao'] === 'junto' && $novo['mostrar_qrcode'] === true && $novo['msg_instrucoes'] === 'Pague');

// ===================== plano do Pix gerado =====================
echo "\n--- Plano do Pix gerado\n";
$CODIGO = '00020126580014br.gov.bcb.pix0136abc-123';
$ctx = ['nome' => 'João Silva', 'plano' => 'Premium VIP', 'valor' => 29.9, 'codigo' => $CODIGO, 'txid' => 'TX1', 'tem_qr' => true, 'hora' => 20];
$cfg = fn(array $x = []) => pagamentoConfig($x + ['msg_instrucoes' => 'Pague agora, {nome}']);

$p = pagamentoPlanoPix($cfg(), $ctx);
confere('padrão (como o bot sempre fez): texto, QR depois do texto, código com os botões', passos_resumo($p) === 'texto | foto_qr | texto+botoes', passos_resumo($p));
confere('o texto padrão tem o cabeçalho do pedido (produto e valor) e o texto do dono com a variável trocada', strpos($p[0]['html'], '<b>Produto:</b> Premium VIP') !== false && strpos($p[0]['html'], 'R$ 29,90') !== false && substr($p[0]['html'], -strlen('Pague agora, João')) === 'Pague agora, João', $p[0]['html']);
confere('a legenda do QR é a de sempre e o código vai em <code> (toque para copiar)', $p[1]['html'] === 'Escaneie o QR Code para pagar.' && $p[2]['html'] === '<code>' . $CODIGO . '</code>');
$c = pagamentoPlanoPix($cfg(['layout' => 'compacto']), $ctx);
confere('layout compacto: só o texto do dono, sem o cabeçalho do pedido', $c[0]['html'] === 'Pague agora, João', $c[0]['html']);
confere('texto vazio usa o texto padrão do Pix', strpos(pagamentoPlanoPix(pagamentoConfig(['layout' => 'compacto']), $ctx)[0]['html'], 'Aguardando pagamento') !== false);
confere('QR antes: a foto vem primeiro', passos_resumo(pagamentoPlanoPix($cfg(['qr_exibicao' => 'antes']), $ctx)) === 'foto_qr | texto | texto+botoes');
$jn = pagamentoPlanoPix($cfg(['qr_exibicao' => 'junto', 'layout' => 'compacto']), $ctx);
confere('QR junto: uma foto só, com o texto na legenda; o código segue com os botões', passos_resumo($jn) === 'foto_qr | texto+botoes' && $jn[0]['html'] === 'Pague agora, João', passos_resumo($jn));
$longo = pagamentoPlanoPix($cfg(['qr_exibicao' => 'junto', 'msg_instrucoes' => str_repeat('a', 1500)]), $ctx);
confere('QR junto com texto acima de 1024 (limite da legenda): foto sozinha e o texto logo depois', passos_resumo($longo) === 'foto_qr | texto | texto+botoes' && $longo[0]['html'] === 'Escaneie o QR Code para pagar.', passos_resumo($longo));
confere('sem QR (nao): nenhuma foto', passos_resumo(pagamentoPlanoPix($cfg(['qr_exibicao' => 'nao']), $ctx)) === 'texto | texto+botoes');
confere('QR configurado mas não gerado (tem_qr falso): nenhuma foto e o texto não se perde (nem no modo junto)', passos_resumo(pagamentoPlanoPix($cfg(['qr_exibicao' => 'junto']), ['tem_qr' => false] + $ctx)) === 'texto | texto+botoes');
$cm = pagamentoPlanoPix($cfg(['codigo_na_mensagem' => true, 'layout' => 'compacto']), $ctx);
confere('código na mesma mensagem: texto + código juntos, com os botões, e não há mensagem só do código', passos_resumo($cm) === 'texto+botoes | foto_qr' && $cm[0]['html'] === "Pague agora, João\n\n<code>$CODIGO</code>", passos_resumo($cm) . ' ' . $cm[0]['html']);
$vc = pagamentoPlanoPix(pagamentoConfig(['layout' => 'compacto', 'msg_instrucoes' => "Cole isto: {codigo_pix}\nObrigado"]), $ctx);
confere('{codigo_pix} no texto: o código entra onde o dono pôs (e não se repete numa mensagem à parte)', $vc[0]['html'] === "Cole isto: <code>$CODIGO</code>\nObrigado" && passos_resumo($vc) === 'texto+botoes | foto_qr', $vc[0]['html'] . ' ' . passos_resumo($vc));
$ac = pagamentoPlanoPix($cfg(['antes_codigo' => ['ativo' => true, 'texto' => 'Copie abaixo, {nome}:']]), $ctx);
confere('mensagem antes do código: sai logo antes dele, sem botões', passos_resumo($ac) === 'texto | foto_qr | texto | texto+botoes' && $ac[2]['html'] === 'Copie abaixo, João:' && $ac[2]['botoes'] === false, passos_resumo($ac));
$ab = pagamentoPlanoPix($cfg(['antes_botoes' => ['ativo' => true, 'texto' => 'Depois de pagar, toque abaixo']]), $ctx);
confere('mensagem antes dos botões: o código sai sem botões e os botões vão numa mensagem final com o texto', passos_resumo($ab) === 'texto | foto_qr | texto | texto+botoes' && $ab[2]['botoes'] === false && $ab[3]['html'] === 'Depois de pagar, toque abaixo', passos_resumo($ab));
$tudo = pagamentoPlanoPix($cfg(['qr_exibicao' => 'antes', 'antes_codigo' => ['ativo' => true], 'antes_botoes' => ['ativo' => true], 'midias_gerado' => [['tipo' => 'image', 'path' => 'uploads/a.jpg'], ['tipo' => 'video', 'path' => 'uploads/b.mp4']]]), $ctx);
confere('tudo ligado, na ordem: mídias → QR → texto → "antes do código" → código → "antes dos botões" com os botões', passos_resumo($tudo) === 'midias(2) | foto_qr | texto | texto | texto | texto+botoes', passos_resumo($tudo));
$ab2 = pagamentoPlanoPix($cfg(['antes_botoes' => ['ativo' => true], 'codigo_na_mensagem' => true]), $ctx);
confere('código na mensagem + mensagem antes dos botões: o texto leva o código sem botões; os botões vão na mensagem final', passos_resumo($ab2) === 'texto | foto_qr | texto+botoes' && $ab2[0]['botoes'] === false, passos_resumo($ab2));
$sb = pagamentoPlanoPix($cfg(['btn_copiar' => ['ativo' => false], 'btn_verificar' => ['ativo' => false], 'antes_botoes' => ['ativo' => true]]), $ctx);
confere('sem nenhum botão ligado: ninguém leva teclado e a "mensagem antes dos botões" não é enviada à toa', strpos(passos_resumo($sb), '+botoes') === false && passos_resumo($sb) === 'texto | foto_qr | texto', passos_resumo($sb));
$sc = pagamentoPlanoPix($cfg(), ['codigo' => ''] + $ctx);
confere('sem código Pix (gateway não devolveu): só o texto e o QR (se houver), sem mensagem de código e sem botão de copiar', passos_resumo($sc) === 'texto+botoes | foto_qr' && count(pagamentoTeclado($cfg(), ['codigo' => ''] + $ctx)) === 1 && isset(pagamentoTeclado($cfg(), ['codigo' => ''] + $ctx)[0][0]['callback_data']), passos_resumo($sc));
confere('formato do código: bloco = <pre>, simples = texto puro', pagamentoCodigoHtml('AB<C', 'bloco') === '<pre>AB&lt;C</pre>' && pagamentoCodigoHtml('ABC', 'simples') === 'ABC' && pagamentoCodigoHtml('ABC', 'inline') === '<code>ABC</code>');
confere('o plano usa o formato escolhido: com bloco o código sai em <pre>', pagamentoPlanoPix($cfg(['codigo_formato' => 'bloco']), $ctx)[2]['html'] === "<pre>$CODIGO</pre>");
$tk = pagamentoTeclado($cfg(), $ctx);
confere('teclado: copiar (copy_text) e verificar (callback com o txid), cada um na sua linha', count($tk) === 2 && $tk[0][0]['copy_text']['text'] === $CODIGO && $tk[0][0]['text'] === '📋 Copiar Código' && $tk[1][0]['callback_data'] === 'verificar_pagamento_TX1' && $tk[1][0]['text'] === '✅ Verificar Status', j($tk));
confere('teclado: código com mais de 256 caracteres não ganha botão de copiar (limite do Telegram)', count(pagamentoTeclado($cfg(), ['codigo' => str_repeat('9', 300)] + $ctx)) === 1);
confere('teclado: botões desligados somem; texto personalizado vale', pagamentoTeclado($cfg(['btn_copiar' => ['ativo' => false], 'btn_verificar' => ['ativo' => false]]), $ctx) === [] && pagamentoTeclado($cfg(['btn_verificar' => ['texto' => 'Já paguei']]), $ctx)[1][0]['text'] === 'Já paguei');
confere('o nome do plano com HTML não vira tag no cabeçalho do pedido', strpos(pagamentoPlanoPix($cfg(), ['plano' => '<b>Hack</b>'] + $ctx)[0]['html'], '<b>Hack') === false);

// ----- migração: um fluxo antigo, salvo no modelo novo, manda o que o envio antigo mandava
// (o envio antigo, em funcoes/fluxo_blocos.php: texto com o pedido, depois a foto do QR com "Escaneie o QR Code para pagar.", depois o código em <code> com os botões
// "📋 Copiar código Pix" e "Já fiz o pagamento ✅"; o editor grava estes valores ao salvar um fluxo antigo, ver padraoLegado() em assets/pagamentos_guiado.js)
$migrado = pagamentoConfig(['layout' => 'padrao', 'msg_instrucoes' => 'Copie o código Pix abaixo e pague no app do seu banco.', 'qr_exibicao' => 'depois', 'codigo_formato' => 'inline',
    'btn_copiar' => ['ativo' => true, 'texto' => '📋 Copiar código Pix'], 'btn_verificar' => ['ativo' => true, 'texto' => 'Já fiz o pagamento ✅']]);
$pm = pagamentoPlanoPix($migrado, $ctx);
$tm = pagamentoTeclado($migrado, $ctx);
confere('migração: mesma sequência do envio antigo (texto, foto do QR com a mesma legenda, código com os botões)', passos_resumo($pm) === 'texto | foto_qr | texto+botoes' && $pm[1]['html'] === 'Escaneie o QR Code para pagar.' && $pm[2]['html'] === '<code>' . $CODIGO . '</code>', passos_resumo($pm));
confere('migração: o cabeçalho do pedido é o de sempre (Pedido Criado, Produto, Valor) e o texto do dono vem no fim', strpos($pm[0]['html'], "✅ <b>Pedido Criado com Sucesso!</b>\n\n🛒 <b>Produto:</b> Premium VIP\n💲 <b>Valor:</b> R$ 29,90\n\n") === 0 && substr($pm[0]['html'], -strlen('pague no app do seu banco.')) === 'pague no app do seu banco.');
confere('migração: os botões são os de sempre (copiar com copy_text, "Já fiz o pagamento ✅" com o callback do txid), um por linha e nesta ordem', $tm[0][0]['text'] === '📋 Copiar código Pix' && $tm[0][0]['copy_text']['text'] === $CODIGO && $tm[1][0]['text'] === 'Já fiz o pagamento ✅' && $tm[1][0]['callback_data'] === 'verificar_pagamento_TX1' && count($tm) === 2);
$am = pagamentoPlanoAprovacao(pagamentoConfig(['msg_confirmado' => 'Pagamento confirmado! Seu acesso foi liberado.', 'botao_acesso' => ['ativo' => false]]), $ctxA ?? ['nome' => 'M', 'plano' => 'VIP', 'valor' => 49.9, 'link_grupo' => 'https://t.me/+AbCdEf', 'tempo_minutos' => 43200]);
confere('migração: a aprovação continua com o link escrito na mensagem (sem botão), como o envio antigo', $am['botao'] === null && strpos($am['html'], "🚀 <b>Acesso Liberado!</b>\nClique no link abaixo para entrar no grupo exclusivo:\n\nhttps://t.me/+AbCdEf\n\n⚠️ Este link é válido apenas para você.") !== false);

// ===================== plano da aprovação =====================
echo "\n--- Plano do pagamento aprovado\n";
$pgA = pagamentoConfig(['msg_confirmado' => '🎉 Parabéns {nome}! {plano} ({valor}) confirmado.', 'midias_aprovado' => [['tipo' => 'image', 'path' => 'uploads/ok.jpg']]]);
$ctxA = ['nome' => 'Maria Souza', 'plano' => 'VIP', 'valor' => 49.9, 'link_grupo' => 'https://t.me/+AbCdEf', 'tempo_minutos' => 30 * 1440, 'hora' => 10];
$a = pagamentoPlanoAprovacao($pgA, $ctxA);
confere('aprovado com botão de acesso (padrão): o texto do dono, o aviso de acesso SEM o link no texto e o botão com o link', strpos($a['html'], '🎉 Parabéns Maria! VIP (R$ 49,90) confirmado.') === 0 && strpos($a['html'], 'Toque no botão abaixo') !== false && strpos($a['html'], 't.me/+AbCdEf') === false && $a['botao'] === ['texto' => '🔓 Acessar Conteúdo', 'url' => 'https://t.me/+AbCdEf'], j($a));
confere('aprovado: a validade do acesso continua aparecendo (30 dias)', strpos($a['html'], 'expira em 30 dias') !== false);
confere('aprovado: as mídias da aba vão antes', $a['midias'] === [['tipo' => 'image', 'path' => 'uploads/ok.jpg']]);
$a2 = pagamentoPlanoAprovacao(pagamentoConfig(['botao_acesso' => ['ativo' => false]]), $ctxA);
confere('botão de acesso desligado: o link vai no texto, como sempre, e não há botão', strpos($a2['html'], 'https://t.me/+AbCdEf') !== false && $a2['botao'] === null);
confere('sem texto do dono: texto padrão de confirmação', strpos($a2['html'], '<b>Pagamento Confirmado!</b>') !== false);
$a3 = pagamentoPlanoAprovacao(pagamentoConfig([]), ['link_grupo' => null, 'grupo_falhou' => true] + $ctxA);
confere('link do grupo não gerado: avisa o cliente e não há botão', strpos($a3['html'], 'Não foi possível gerar o link do grupo') !== false && $a3['botao'] === null);
$a4 = pagamentoPlanoAprovacao(pagamentoConfig([]), ['link_grupo' => null, 'link_entrega' => 'https://area.exemplo.com/vip'] + $ctxA);
confere('entrega por link + botão de acesso: o botão leva ao link e o texto não repete o endereço', $a4['botao']['url'] === 'https://area.exemplo.com/vip' && strpos($a4['html'], 'https://area.exemplo.com/vip') === false && strpos($a4['html'], 'botão abaixo') !== false, j($a4));
$a5 = pagamentoPlanoAprovacao(pagamentoConfig(['botao_acesso' => ['ativo' => false]]), ['link_grupo' => null, 'link_entrega' => 'https://area.exemplo.com/vip'] + $ctxA);
confere('entrega por link sem botão: o endereço vai no texto, como sempre', strpos($a5['html'], "🔗 <b>Seu acesso:</b>\nhttps://area.exemplo.com/vip") !== false && $a5['botao'] === null);
confere('venda sem grupo nem link (só a confirmação): sem botão, mesmo com ele ligado', pagamentoPlanoAprovacao(pagamentoConfig([]), ['nome' => 'A', 'plano' => 'P', 'valor' => 1])['botao'] === null);
confere('acesso vitalício e validade em horas/minutos', strpos(pagamentoPlanoAprovacao(pagamentoConfig([]), ['link_grupo' => 'https://t.me/+x', 'tempo_minutos' => 36500 * 1440] + $ctxA)['html'], 'Acesso vitalício') !== false && strpos(pagamentoPlanoAprovacao(pagamentoConfig([]), ['link_grupo' => 'https://t.me/+x', 'tempo_minutos' => 120] + $ctxA)['html'], 'expira em 2 horas') !== false && strpos(pagamentoPlanoAprovacao(pagamentoConfig([]), ['link_grupo' => 'https://t.me/+x', 'tempo_minutos' => 45] + $ctxA)['html'], 'expira em 45 minutos') !== false);

// ===================== envios (Telegram falso) =====================
echo "\n--- Envios\n";
limpar();
pagamentoEnviarPix('T', 123, $cfg(['qr_exibicao' => 'depois']), ['nome' => 'João', 'plano' => 'VIP', 'valor' => 9.9, 'codigo' => $CODIGO, 'txid' => 'TX9']);
confere('Pix padrão: sendMessage (HTML), sendPhoto do QR (PNG de verdade) e sendMessage do código com os botões', metodos() === 'sendMessage,sendPhoto,sendMessage' && $GLOBALS['chamadas'][0]['p']['parse_mode'] === 'HTML' && isset($GLOBALS['chamadas'][1]['arquivos']['photo']) && count(tecladoDe($GLOBALS['chamadas'][2])) === 2, metodos());
confere('o código sai em <code> e o teclado leva o copy_text e o callback do txid', $GLOBALS['chamadas'][2]['p']['text'] === "<code>$CODIGO</code>" && tecladoDe($GLOBALS['chamadas'][2])[0][0]['copy_text']['text'] === $CODIGO && tecladoDe($GLOBALS['chamadas'][2])[1][0]['callback_data'] === 'verificar_pagamento_TX9');
confere('o arquivo temporário do QR é apagado depois do envio', !is_file((string) (array_values($GLOBALS['chamadas'][1]['arquivos'])[0] ?? '')));
limpar();
pagamentoEnviarPix('T', 123, $cfg(['qr_exibicao' => 'junto', 'layout' => 'compacto']), ['nome' => 'João', 'plano' => 'VIP', 'valor' => 9.9, 'codigo' => $CODIGO, 'txid' => 'TX9']);
confere('QR junto: a foto leva o texto na legenda (HTML) e o código vai depois com os botões', metodos() === 'sendPhoto,sendMessage' && $GLOBALS['chamadas'][0]['p']['caption'] === 'Pague agora, João' && $GLOBALS['chamadas'][0]['p']['parse_mode'] === 'HTML', metodos());
limpar();
pagamentoEnviarPix('T', 123, $cfg(['qr_exibicao' => 'junto', 'layout' => 'compacto']), ['nome' => 'João', 'plano' => 'VIP', 'valor' => 9.9, 'codigo' => str_repeat('A', 9000), 'txid' => 'TX9']);
confere('QR que não dá para gerar (código gigante) no modo junto: o texto NÃO se perde, sai como mensagem', strpos(metodos(), 'sendPhoto') === false && $GLOBALS['chamadas'][0]['metodo'] === 'sendMessage' && $GLOBALS['chamadas'][0]['p']['text'] === 'Pague agora, João', metodos());
limpar(function ($metodo, $p) { return ($metodo === 'sendPhoto' && ($p['parse_mode'] ?? '') === 'HTML') ? ['ok' => false, 'description' => "Bad Request: can't parse entities"] : null; });
pagamentoEnviarPix('T', 123, $cfg(['qr_exibicao' => 'junto', 'layout' => 'compacto', 'msg_instrucoes' => '<b>Oi</b> {nome}']), ['nome' => 'João', 'plano' => 'VIP', 'valor' => 9.9, 'codigo' => $CODIGO, 'txid' => 'TX9']);
confere('Telegram recusa a formatação da legenda: reenvia a foto com texto puro (sem tags)', metodos() === 'sendPhoto,sendPhoto,sendMessage' && !isset($GLOBALS['chamadas'][1]['p']['parse_mode']) && $GLOBALS['chamadas'][1]['p']['caption'] === 'Oi João', metodos() . ' ' . j($GLOBALS['chamadas'][1]['p'] ?? []));
limpar(function ($metodo, $p) { return ($metodo === 'sendMessage' && ($p['parse_mode'] ?? '') === 'HTML' && strpos($p['text'], 'Oi') !== false) ? ['ok' => false, 'description' => "Bad Request: can't parse entities: Unsupported start tag"] : null; });
pagamentoEnviarTexto('T', 5, '<b>Oi</b> &amp; tchau', [[['text' => 'x', 'callback_data' => 'y']]]);
confere('mensagem recusada por formatação: reenvia como texto puro MANTENDO os botões', metodos() === 'sendMessage,sendMessage' && $GLOBALS['chamadas'][1]['p']['text'] === 'Oi & tchau' && count(tecladoDe($GLOBALS['chamadas'][1])) === 1);
limpar(function ($metodo, $p) { return ['ok' => false, 'description' => 'resposta inválida']; });
pagamentoEnviarTexto('T', 5, 'Oi', []);
confere('resposta inválida (rede/tempo esgotado): NÃO reenvia (evita mandar em dobro)', metodos() === 'sendMessage');
limpar(function ($metodo, $p) { return isset($p['reply_markup']) && strpos($p['reply_markup'], 'copy_text') !== false ? ['ok' => false, 'description' => 'Bad Request: BUTTON_TYPE_INVALID'] : null; });
pagamentoEnviarTexto('T', 5, 'codigo', [[['text' => 'c', 'copy_text' => ['text' => 'z']]], [['text' => 'v', 'callback_data' => 'k']]]);
confere('teclado com botão de copiar recusado: reenvia só com os demais botões', metodos() === 'sendMessage,sendMessage' && count(tecladoDe($GLOBALS['chamadas'][1])) === 1 && isset(tecladoDe($GLOBALS['chamadas'][1])[0][0]['callback_data']));
limpar();
pagamentoEnviarMidias('T', 9, [['tipo' => 'image', 'path' => 'uploads/a.jpg'], ['tipo' => 'video', 'path' => 'uploads/b.mp4'], ['tipo' => 'image', 'path' => 'uploads/c.jpg']]);
confere('3 mídias: um álbum (sendMediaGroup); 1 mídia: solta (sendPhoto); caminho fora de uploads/: nada', metodos() === 'sendMediaGroup' && count(json_decode($GLOBALS['chamadas'][0]['p']['media'], true)) === 3);
limpar();
pagamentoEnviarMidias('T', 9, [['tipo' => 'image', 'path' => 'uploads/a.jpg']]);
pagamentoEnviarMidias('T', 9, [['tipo' => 'image', 'path' => '../config.php']]);
confere('1 mídia vai solta e caminho inseguro não envia nada', metodos() === 'sendPhoto', metodos());

// ===================== aprovação, com banco =====================
echo "\n--- Aprovação com banco (SQLite)\n";
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE fluxos (id INTEGER PRIMARY KEY, modo TEXT, dados_fluxograma TEXT)');
$pdo->exec('CREATE TABLE bots (id INTEGER PRIMARY KEY, id_fluxo_conectado INTEGER)');
$pdo->exec('CREATE TABLE leads (id INTEGER PRIMARY KEY, id_telegram TEXT, bot_id INTEGER, nome TEXT)');
function ligarFluxo(PDO $pdo, string $modo, array $pagamentos): void
{
    $pdo->exec('DELETE FROM fluxos');
    $pdo->exec('DELETE FROM bots');
    $pdo->prepare('INSERT INTO fluxos (id, modo, dados_fluxograma) VALUES (1, ?, ?)')->execute([$modo, json_encode(['planos' => [], 'pagamentos' => $pagamentos])]);
    $pdo->exec('INSERT INTO bots (id, id_fluxo_conectado) VALUES (7, 1)');
}
$pdo->exec("INSERT INTO leads (id_telegram, bot_id, nome) VALUES ('555', 7, 'Carlos Lima')");
$venda = ['id' => 1, 'id_telegram' => '555', 'bot_id' => 7, 'valor' => '29.90', 'nome_produto' => 'Premium', 'id_fluxo' => 1, 'tipo_cobranca' => 'unica', 'link_entrega' => ''];

ligarFluxo($pdo, 'basico', ['versao' => 2, 'msg_confirmado' => 'Valeu {nome}! {plano} {valor}', 'botao_acesso' => ['ativo' => true, 'texto' => 'Entrar']]);
limpar();
$r = pagamentoEnviarAprovacao($pdo, 'T', $venda, 'https://t.me/+Zz', false, 1440);
confere('modelo novo: envia a aprovação (devolve true) com o nome do lead, o plano e o valor da venda', $r === true && metodos() === 'sendMessage' && strpos($GLOBALS['chamadas'][0]['p']['text'], 'Valeu Carlos! Premium R$ 29,90') === 0, metodos() . ' ' . ($GLOBALS['chamadas'][0]['p']['text'] ?? ''));
confere('a aprovação leva o botão de acesso com o link do grupo', tecladoDe($GLOBALS['chamadas'][0])[0][0] === ['text' => 'Entrar', 'url' => 'https://t.me/+Zz'], j(tecladoDe($GLOBALS['chamadas'][0])));
limpar();
confere('venda de renovação/ciclo (venda_pai_id ou assinatura): devolve false e não envia nada (segue o texto de sempre)', pagamentoEnviarAprovacao($pdo, 'T', ['venda_pai_id' => 3] + $venda, null, false, 0) === false && pagamentoEnviarAprovacao($pdo, 'T', ['tipo_cobranca' => 'assinatura'] + $venda, null, false, 0) === false && $GLOBALS['chamadas'] === []);
ligarFluxo($pdo, 'basico', ['msg_confirmado' => 'texto antigo']);
confere('fluxo salvo antes do modelo novo (sem versao): devolve false e não envia nada', pagamentoEnviarAprovacao($pdo, 'T', $venda, null, false, 0) === false && $GLOBALS['chamadas'] === []);
ligarFluxo($pdo, 'avancado', ['versao' => 2]);
confere('fluxo do Editor Visual: devolve false', pagamentoEnviarAprovacao($pdo, 'T', $venda, null, false, 0) === false);
$pdo->exec('DROP TABLE fluxos');
confere('banco sem a tabela/erro: devolve false em vez de derrubar a confirmação do pagamento', pagamentoEnviarAprovacao($pdo, 'T', $venda, null, false, 0) === false);

echo PHP_EOL . "RESUMO: $ok ok, $falhas falhas" . PHP_EOL;
exit($falhas > 0 ? 1 : 0);
