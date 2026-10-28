<?php
declare(strict_types=1);

/**
 * Teste unitário da limpeza que o api.php faz ao salvar um fluxo Guiado (sanitizarCaminhosMidiaFluxo): mídias das Boas-vindas (lista de até 10) e itens da
 * sequência de Order Bumps (até 5). Caminho fora de "uploads/nome.ext" nunca chega ao banco (o bot reenviaria o arquivo). As funções são extraídas do
 * api.php (que não pode ser incluído: executa a requisição na hora).
 *   php tests/guiado/unitario_sanitizar_midias.php   (sai com código 1 se algo falhar)
 */

$fonte = (string) file_get_contents(__DIR__ . '/../../api.php');

function extrairFuncao(string $fonte, string $nome): string
{
    $tokens = token_get_all($fonte);
    $n = count($tokens);
    for ($i = 0; $i < $n; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
            continue;
        }
        $j = $i + 1;
        while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }
        if (!is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING || $tokens[$j][1] !== $nome) {
            continue;
        }
        $codigo = '';
        $nivel = 0;
        $abriu = false;
        for ($k = $i; $k < $n; $k++) {
            $t = $tokens[$k];
            $codigo .= is_array($t) ? $t[1] : $t;
            if ($t === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $nivel++;
                $abriu = true;
            } elseif ($t === '}') {
                $nivel--;
                if ($abriu && $nivel === 0) {
                    return $codigo;
                }
            }
        }
    }
    throw new RuntimeException('função não encontrada no api.php: ' . $nome);
}
require_once __DIR__ . '/../../funcoes/entrega.php';   // DOWNSELL_MAXIMO_SEQUENCIAS e DOWNSELL_PLANOS_PROPRIOS_MAXIMO, usados por sanitizarCaminhosMidiaFluxo
eval(extrairFuncao($fonte, 'caminhoUploadValido'));
eval(extrairFuncao($fonte, 'sanitizarMidiasDoItem'));
eval(extrairFuncao($fonte, 'sanitizarCaminhosMidiaFluxo'));

$ok = 0;
$falhas = 0;
function confere(string $nome, bool $certo, string $detalhe = ''): void
{
    global $ok, $falhas;
    $certo ? $ok++ : $falhas++;
    echo ($certo ? 'OK     ' : 'FALHOU ') . $nome . ($certo || $detalhe === '' ? '' : '  -> ' . $detalhe) . PHP_EOL;
}

// ---------- Boas-vindas com várias mídias
$r = sanitizarCaminhosMidiaFluxo(['boas_vindas' => ['midias' => [
    ['tipo' => 'image', 'path' => 'uploads/a.jpg'], ['tipo' => 'video', 'path' => 'uploads/b.mp4'],
]]]);
confere('mídias válidas ficam, na ordem, e a 1ª é copiada para midia_tipo/midia_path', $r['boas_vindas']['midias'] === [['tipo' => 'image', 'path' => 'uploads/a.jpg'], ['tipo' => 'video', 'path' => 'uploads/b.mp4']]
    && $r['boas_vindas']['midia_tipo'] === 'image' && $r['boas_vindas']['midia_path'] === 'uploads/a.jpg', json_encode($r['boas_vindas']));

$r = sanitizarCaminhosMidiaFluxo(['boas_vindas' => ['midias' => [
    ['tipo' => 'image', 'path' => '../config.php'], ['tipo' => 'image', 'path' => 'config.php'], ['tipo' => 'image', 'path' => 'uploads/sub/x.jpg'], ['tipo' => 'image', 'path' => '/etc/passwd'],
    ['tipo' => 'audio', 'path' => 'uploads/x.mp3'], ['tipo' => 'none', 'path' => 'uploads/n.jpg'], ['tipo' => 'image', 'path' => ''], 'lixo', ['tipo' => 'video', 'path' => 'uploads/ok.mp4'],
]]]);
confere('caminho fora de uploads/, tipo inválido, item vazio e item que não é lista são descartados; só sobra a mídia boa', $r['boas_vindas']['midias'] === [['tipo' => 'video', 'path' => 'uploads/ok.mp4']]
    && $r['boas_vindas']['midia_tipo'] === 'video' && $r['boas_vindas']['midia_path'] === 'uploads/ok.mp4', json_encode($r['boas_vindas']));

$muitas = [];
for ($i = 0; $i < 14; $i++) {
    $muitas[] = ['tipo' => 'image', 'path' => 'uploads/f' . $i . '.jpg'];
}
$r = sanitizarCaminhosMidiaFluxo(['boas_vindas' => ['midias' => $muitas]]);
confere('mais de 10 mídias: só as 10 primeiras são guardadas (limite do álbum do Telegram)', count($r['boas_vindas']['midias']) === 10 && $r['boas_vindas']['midias'][9]['path'] === 'uploads/f9.jpg');

$r = sanitizarCaminhosMidiaFluxo(['boas_vindas' => ['midias' => [['tipo' => 'image', 'path' => 'config.php']], 'midia_tipo' => 'image', 'midia_path' => 'uploads/velha.jpg']]);
confere('lista que ficou vazia zera também o par midia_tipo/midia_path (não sobra a mídia antiga)', $r['boas_vindas']['midias'] === [] && $r['boas_vindas']['midia_tipo'] === 'none' && $r['boas_vindas']['midia_path'] === '');

$r = sanitizarCaminhosMidiaFluxo(['boas_vindas' => ['midia_tipo' => 'image', 'midia_path' => 'uploads/velha.jpg']]);
confere('fluxo antigo, sem a lista: o par midia_tipo/midia_path segue como está (e nenhuma lista é inventada)', $r['boas_vindas']['midia_path'] === 'uploads/velha.jpg' && !isset($r['boas_vindas']['midias']));
$r = sanitizarCaminhosMidiaFluxo(['boas_vindas' => ['midia_tipo' => 'image', 'midia_path' => '../config.php']]);
confere('fluxo antigo com caminho ruim: o caminho é zerado', $r['boas_vindas']['midia_path'] === '');

// ---------- Order Bump em sequência
$itens = [
    ['nome' => 'A', 'valor_extra' => 1.0, 'midia_tipo' => 'image', 'midia_path' => 'uploads/a.jpg'],
    ['nome' => 'B', 'valor_extra' => 2.0, 'midia_tipo' => 'image', 'midia_path' => '../config.php'],
    'lixo',
    ['nome' => 'C', 'valor_extra' => 3.0],
    ['nome' => 'D', 'valor_extra' => 4.0], ['nome' => 'E', 'valor_extra' => 5.0], ['nome' => 'F', 'valor_extra' => 6.0],
];
$r = sanitizarCaminhosMidiaFluxo(['ofertas' => ['order_bump' => ['ativo' => true, 'quando' => 'depois_pix', 'itens' => $itens]]]);
$saida = $r['ofertas']['order_bump']['itens'];
confere('itens do order bump: no máximo 5 (contando só os 5 primeiros da lista enviada)', count($saida) <= 5, (string) count($saida));
confere('item que não é lista é descartado; os demais seguem na ordem', array_column($saida, 'nome') === ['A', 'B', 'C', 'D'], json_encode(array_column($saida, 'nome')));
confere('mídia válida do item fica; caminho fora de uploads/ é zerado só nesse item', $saida[0]['midia_path'] === 'uploads/a.jpg' && $saida[1]['midia_path'] === '', json_encode($saida));
confere('o resto da configuração do order bump não é mexido (ativo, quando)', $r['ofertas']['order_bump']['ativo'] === true && $r['ofertas']['order_bump']['quando'] === 'depois_pix');

$r = sanitizarCaminhosMidiaFluxo(['ofertas' => ['order_bump' => ['ativo' => true, 'midia_path' => '../x']]]);
confere('order bump antigo (sem itens): só a mídia dele é limpa e nenhuma lista é inventada', $r['ofertas']['order_bump']['midia_path'] === '' && !isset($r['ofertas']['order_bump']['itens']));

$r = sanitizarCaminhosMidiaFluxo(['ofertas' => ['upsell' => ['midia_path' => 'uploads/u.jpg'], 'downsell' => ['midia_path' => '../d.jpg']]]);
confere('upsell e downsell continuam como antes (a mídia ruim é zerada, a boa fica)', $r['ofertas']['upsell']['midia_path'] === 'uploads/u.jpg' && $r['ofertas']['downsell']['midia_path'] === '');

// ---------- Upsell e Downsell com várias mídias (mesma regra das Boas-vindas)
foreach (['upsell', 'downsell'] as $oferta) {
    $r = sanitizarCaminhosMidiaFluxo(['ofertas' => [$oferta => ['ativo' => true, 'mensagem' => 'oi', 'midias' => [
        ['tipo' => 'video', 'path' => 'uploads/a.mp4'], ['tipo' => 'image', 'path' => '../config.php'], ['tipo' => 'audio', 'path' => 'uploads/x.mp3'], 'lixo', ['tipo' => 'image', 'path' => 'uploads/b.jpg'],
    ]]]]);
    $o = $r['ofertas'][$oferta];
    confere("$oferta: só as mídias válidas ficam, na ordem, e a 1ª é copiada para midia_tipo/midia_path", $o['midias'] === [['tipo' => 'video', 'path' => 'uploads/a.mp4'], ['tipo' => 'image', 'path' => 'uploads/b.jpg']] && $o['midia_tipo'] === 'video' && $o['midia_path'] === 'uploads/a.mp4', json_encode($o));
    confere("$oferta: o resto da oferta não é mexido", $o['ativo'] === true && $o['mensagem'] === 'oi');
    $r = sanitizarCaminhosMidiaFluxo(['ofertas' => [$oferta => ['midias' => $muitas]]]);
    confere("$oferta: mais de 10 mídias, só as 10 primeiras", count($r['ofertas'][$oferta]['midias']) === 10);
    $r = sanitizarCaminhosMidiaFluxo(['ofertas' => [$oferta => ['midias' => [['tipo' => 'image', 'path' => 'config.php']], 'midia_tipo' => 'image', 'midia_path' => 'uploads/velha.jpg']]]);
    confere("$oferta: lista que ficou vazia zera o par midia_tipo/midia_path (não sobra a mídia antiga)", $r['ofertas'][$oferta]['midias'] === [] && $r['ofertas'][$oferta]['midia_tipo'] === 'none' && $r['ofertas'][$oferta]['midia_path'] === '');
    $r = sanitizarCaminhosMidiaFluxo(['ofertas' => [$oferta => ['midia_tipo' => 'image', 'midia_path' => 'uploads/velha.jpg']]]);
    confere("$oferta salvo antes (sem a lista): o par segue como está e nenhuma lista é inventada", $r['ofertas'][$oferta]['midia_path'] === 'uploads/velha.jpg' && !isset($r['ofertas'][$oferta]['midias']));
}

// Downsell em sequência (item 38): cada sequência extra tem a própria mídia (mesma regra), no máximo 4 extras e 10 planos criados por sequência
$extras = [
    ['mensagem' => 'B', 'midia_tipo' => 'video', 'midia_path' => 'uploads/b.mp4'],
    ['mensagem' => 'C', 'midia_tipo' => 'image', 'midia_path' => '../config.php', 'criar_planos' => true, 'planos_proprios' => array_fill(0, 14, ['nome' => 'X', 'valor' => 5])],
    'lixo',
    ['mensagem' => 'D', 'midia_tipo' => 'image', 'midia_path' => 'uploads/d.jpg'], ['mensagem' => 'E'], ['mensagem' => 'F'], ['mensagem' => 'G'],
];
$r = sanitizarCaminhosMidiaFluxo(['ofertas' => ['downsell' => ['ativo' => true, 'mensagem' => 'A', 'sequencias_extras' => $extras, 'planos_proprios' => array_fill(0, 12, ['nome' => 'Y', 'valor' => 3])]]]);
$s = $r['ofertas']['downsell']['sequencias_extras'];
confere('downsell em sequência: a mídia válida da sequência extra fica', $s[0]['midia_path'] === 'uploads/b.mp4' && $s[0]['midia_tipo'] === 'video');
confere('downsell em sequência: caminho fora de uploads/ numa sequência extra é zerado', $s[1]['midia_path'] === '');
confere('downsell em sequência: só as 4 primeiras posições das extras são lidas (a 1ª sequência é o próprio downsell) e o item que não é lista é descartado', count($s) === 3 && $s[2]['mensagem'] === 'D', json_encode(array_column($s, 'mensagem')));
confere('downsell em sequência: no máximo 10 planos criados, na sequência extra e no downsell 1', count($s[1]['planos_proprios']) === 10 && count($r['ofertas']['downsell']['planos_proprios']) === 10);
confere('downsell em sequência: o resto não é mexido', $r['ofertas']['downsell']['mensagem'] === 'A' && $s[1]['criar_planos'] === true && $s[1]['planos_proprios'][0]['nome'] === 'X');
// várias fotos/vídeos numa sequência extra (item 39): só as válidas ficam, na ordem, até 10, e a 1ª é copiada para o par midia_tipo/midia_path
$r = sanitizarCaminhosMidiaFluxo(['ofertas' => ['downsell' => ['ativo' => true, 'sequencias_extras' => [['mensagem' => 'B', 'midias' => [
    ['tipo' => 'image', 'path' => '../config.php'], ['tipo' => 'video', 'path' => 'uploads/a.mp4'], ['tipo' => 'audio', 'path' => 'uploads/a.mp3'], ['tipo' => 'image', 'path' => 'uploads/b.jpg'],
]], ['mensagem' => 'C', 'midias' => array_fill(0, 14, ['tipo' => 'image', 'path' => 'uploads/x.jpg'])]]]]]);
$b = $r['ofertas']['downsell']['sequencias_extras'][0];
confere('downsell em sequência: na sequência extra só as mídias válidas ficam, na ordem, e a 1ª vai para midia_tipo/midia_path', $b['midias'] === [['tipo' => 'video', 'path' => 'uploads/a.mp4'], ['tipo' => 'image', 'path' => 'uploads/b.jpg']] && $b['midia_tipo'] === 'video' && $b['midia_path'] === 'uploads/a.mp4', json_encode($b));
confere('downsell em sequência: mais de 10 mídias na sequência extra, só as 10 primeiras', count($r['ofertas']['downsell']['sequencias_extras'][1]['midias']) === 10);
$r = sanitizarCaminhosMidiaFluxo(['ofertas' => ['downsell' => ['ativo' => true, 'mensagem' => 'A']]]);
confere('downsell salvo antes (sem sequências nem planos criados): nada é inventado',!isset($r['ofertas']['downsell']['sequencias_extras']) && !isset($r['ofertas']['downsell']['planos_proprios']));

echo PHP_EOL . "$ok ok, $falhas falha(s)" . PHP_EOL;
exit($falhas > 0 ? 1 : 0);
