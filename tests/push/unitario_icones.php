<?php
declare(strict_types=1);

/**
 * Teste unitário do gerador de ícones dos avisos push (funcoes/push_icones.php): logo PNG transparente, JPEG, WebP, retangular, minúscula e ausente.
 * Cada logo tem que gerar os 5 arquivos com as dimensões certas; o badge do Android tem que ser branco com transparência (nunca um quadrado branco);
 * trocar a logo muda a versão. Usa GD, uma pasta temporária e imagens criadas aqui (não mexe em uploads/ nem na configuração).
 * Rodar: php tests/push/unitario_icones.php   (sai com código 1 se algo falhar)
 */

require_once __DIR__ . '/../../funcoes/push_icones.php';

$ok = 0;
$falhas = 0;
function confere(string $nome, bool $certo, string $detalhe = ''): void
{
    global $ok, $falhas;
    $certo ? $ok++ : $falhas++;
    echo ($certo ? 'OK     ' : 'FALHOU ') . $nome . ($certo || $detalhe === '' ? '' : '  -> ' . $detalhe) . PHP_EOL;
}
if (!extension_loaded('gd')) {
    echo "FALHOU  a extensão GD não está carregada\n";
    exit(1);
}

$base = sys_get_temp_dir() . '/push_icones_teste_' . getmypid();
mkdir($base, 0777, true);
register_shutdown_function(function () use ($base) {
    foreach (glob($base . '/*/*') ?: [] as $a) {
        @unlink($a);
    }
    foreach (glob($base . '/*') ?: [] as $a) {
        is_dir($a) ? @rmdir($a) : @unlink($a);
    }
    @rmdir($base);
});

/** Disco laranja com um "buraco" interno, fundo transparente (ou branco se $fundo_branco). */
function logoRedonda(int $lado, bool $fundo_branco): GdImage
{
    $im = imagecreatetruecolor($lado, $lado);
    imagealphablending($im, false);
    imagesavealpha($im, true);
    imagefill($im, 0, 0, $fundo_branco ? imagecolorallocate($im, 255, 255, 255) : imagecolorallocatealpha($im, 0, 0, 0, 127));
    imagealphablending($im, true);
    imagefilledellipse($im, intdiv($lado, 2), intdiv($lado, 2), (int) ($lado * 0.9), (int) ($lado * 0.9), imagecolorallocate($im, 255, 106, 26));
    imagefilledellipse($im, intdiv($lado, 2), intdiv($lado, 2), (int) ($lado * 0.4), (int) ($lado * 0.4), imagecolorallocate($im, 20, 20, 20));
    return $im;
}
function salvarLogo(GdImage $im, string $caminho, string $tipo): void
{
    imagesavealpha($im, true);
    match ($tipo) {
        'png' => imagepng($im, $caminho),
        'webp' => imagewebp($im, $caminho, 90),
        default => imagejpeg($im, $caminho, 92),
    };
}
function pixel(string $arquivo, int $x, int $y): array
{
    $im = imagecreatefrompng($arquivo);
    $c = imagecolorat($im, $x, $y);
    return ['r' => ($c >> 16) & 0xff, 'g' => ($c >> 8) & 0xff, 'b' => $c & 0xff, 'a' => ($c >> 24) & 0x7f];
}
function dimensoes(string $arquivo): array
{
    $i = @getimagesize($arquivo);
    return $i ? [$i[0], $i[1]] : [0, 0];
}
/** Conta pixels visíveis (alfa < 64) e quantos deles NÃO são brancos. */
function contarBadge(string $arquivo): array
{
    $im = imagecreatefrompng($arquivo);
    $w = imagesx($im);
    $h = imagesy($im);
    $visiveis = 0;
    $nao_brancos = 0;
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $c = imagecolorat($im, $x, $y);
            if ((($c >> 24) & 0x7f) < 64) {
                $visiveis++;
                if (((($c >> 16) & 0xff) < 250) || ((($c >> 8) & 0xff) < 250) || (($c & 0xff) < 250)) {
                    $nao_brancos++;
                }
            }
        }
    }
    return ['visiveis' => $visiveis, 'total' => $w * $h, 'nao_brancos' => $nao_brancos];
}
function gerar(string $rotulo, array $candidatas, ?string $versao_atual = null, bool $forcar = false): array
{
    global $base;
    $pasta = $base . '/saida_' . $rotulo;
    return [pushIconesGerar($forcar, $pasta, $candidatas, $versao_atual), $pasta];
}
function cincoArquivos(string $pasta): bool
{
    $esperado = ['icone-192.png' => [192, 192], 'icone-512.png' => [512, 512], 'icone-512-maskable.png' => [512, 512], 'apple-touch-180.png' => [180, 180], 'badge-96.png' => [96, 96]];
    foreach ($esperado as $nome => $dim) {
        if (!is_file($pasta . '/' . $nome) || dimensoes($pasta . '/' . $nome) !== $dim) {
            return false;
        }
    }
    return true;
}

// ---------- logos de teste ----------
$png = $base . '/logo.png';
salvarLogo(logoRedonda(800, false), $png, 'png');
$jpg = $base . '/logo.jpg';
salvarLogo(logoRedonda(800, true), $jpg, 'jpeg');
$webp = $base . '/logo.webp';
salvarLogo(logoRedonda(800, false), $webp, 'webp');
$retangular = $base . '/retangular.png';
$r = imagecreatetruecolor(800, 300);
imagealphablending($r, false);
imagesavealpha($r, true);
imagefill($r, 0, 0, imagecolorallocatealpha($r, 0, 0, 0, 127));
imagefilledrectangle($r, 50, 50, 749, 249, imagecolorallocatealpha($r, 220, 30, 30, 0));   // faixa vermelha 700x200 com margem transparente
salvarLogo($r, $retangular, 'png');
$minuscula = $base . '/minuscula.png';
salvarLogo(logoRedonda(100, false), $minuscula, 'png');
$lixo = $base . '/lixo.png';
file_put_contents($lixo, 'isto não é uma imagem');
$svg = $base . '/logo.svg';
file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10"/></svg>');

echo "--- PNG com fundo transparente\n";
[$r1, $p1] = gerar('png', ['logo' => $png]);
confere('gera os 5 arquivos com as dimensões certas (192, 512, 512 maskable, 180 e 96)', $r1['sucesso'] && cincoArquivos($p1) && $r1['origem'] === 'logo', json_encode($r1));
confere('o badge sai como SILHUETA da logo (branco com transparência), nunca um quadrado branco cheio', $r1['badge'] === 'silhueta' && (function () use ($p1) {
    $c = contarBadge($p1 . '/badge-96.png');
    return $c['visiveis'] > 96 * 96 * 0.2 && $c['visiveis'] < 96 * 96 * 0.75 && $c['nao_brancos'] === 0 && pixel($p1 . '/badge-96.png', 1, 1)['a'] >= 100;
})());
confere('o ícone da notificação (192) mantém a transparência da logo: o canto é transparente e o centro é do disco laranja... ou do furo escuro', pixel($p1 . '/icone-192.png', 2, 2)['a'] >= 100 && pixel($p1 . '/icone-192.png', 96, 96)['a'] <= 5);
$m = pixel($p1 . '/icone-512-maskable.png', 2, 2);
confere('o maskable tem fundo SÓLIDO (sem transparência) e o ícone do iPhone também', $m['a'] === 0 && pixel($p1 . '/apple-touch-180.png', 2, 2)['a'] === 0 && pixel($p1 . '/apple-touch-180.png', 90, 20)['a'] === 0);
confere('no maskable a logo ocupa 80%: a 5% da borda ainda é fundo, e a 25% do centro já é logo', (function () use ($p1) {
    $borda = pixel($p1 . '/icone-512-maskable.png', 256, 26);
    $dentro = pixel($p1 . '/icone-512-maskable.png', 256, 76);
    return abs($borda['r'] - 20) <= 3 && abs($dentro['r'] - 255) <= 8 && abs($dentro['g'] - 106) <= 8;
})());

echo "\n--- JPEG (sem transparência, como a logo atual da VPS)\n";
[$r2, $p2] = gerar('jpeg', ['logo' => $jpg]);
confere('gera os 5 arquivos com as dimensões certas', $r2['sucesso'] && cincoArquivos($p2), json_encode($r2));
$c = contarBadge($p2 . '/badge-96.png');
confere('o badge usa o glifo NEUTRO (um sino branco), sem tentar adivinhar a silhueta, e nenhum pixel visível é colorido', $r2['badge'] === 'neutro' && $c['nao_brancos'] === 0 && $c['visiveis'] > 400 && $c['visiveis'] < 96 * 96 * 0.6, json_encode($c));
confere('o badge do JPEG tem os cantos transparentes (não é quadrado)', pixel($p2 . '/badge-96.png', 1, 1)['a'] >= 100 && pixel($p2 . '/badge-96.png', 94, 94)['a'] >= 100);
confere('o ícone 192 do JPEG é opaco e mantém o fundo branco da logo nos cantos', pixel($p2 . '/icone-192.png', 2, 2)['a'] === 0 && pixel($p2 . '/icone-192.png', 2, 2)['r'] >= 245);

echo "\n--- WebP com transparência\n";
[$r3, $p3] = gerar('webp', ['logo' => $webp]);
confere('gera os 5 arquivos e o badge vem da silhueta (o WebP tem alfa)', $r3['sucesso'] && cincoArquivos($p3) && $r3['badge'] === 'silhueta', json_encode($r3));

echo "\n--- retangular (800×300): a logo cabe INTEIRA, sem cortar\n";
[$r4, $p4] = gerar('retangular', ['logo' => $retangular]);
confere('gera os 5 arquivos', $r4['sucesso'] && cincoArquivos($p4));
confere('no 512 a logo cabe INTEIRA na largura (a faixa vermelha começa em ~32 px da borda: 6 px é transparente e 40 px já é vermelho) e fica centralizada na vertical (topo e rodapé transparentes)', pixel($p4 . '/icone-512.png', 256, 256)['r'] > 200 && pixel($p4 . '/icone-512.png', 256, 76)['a'] >= 100 && pixel($p4 . '/icone-512.png', 256, 436)['a'] >= 100 && pixel($p4 . '/icone-512.png', 40, 256)['r'] > 200 && pixel($p4 . '/icone-512.png', 6, 256)['a'] >= 100);

echo "\n--- minúscula (100×100): gera, mas AVISA\n";
[$r5, $p5] = gerar('minuscula', ['logo' => $minuscula]);
confere('gera os 5 arquivos e avisa que a imagem é menor que 512 px', $r5['sucesso'] && cincoArquivos($p5) && (bool) array_filter($r5['avisos'], fn($a) => str_contains($a, '512')), json_encode($r5['avisos'], JSON_UNESCAPED_UNICODE));
confere('a imagem de 800 px não gera aviso de tamanho', !array_filter($r1['avisos'], fn($a) => str_contains($a, '512')));

echo "\n--- logo ausente / ilegível: cai para o favicon e depois para o mascote\n";
[$r6, $p6] = gerar('ausente', ['logo' => $base . '/nao_existe.png', 'favicon' => $png]);
confere('logo ausente: usa o favicon e avisa', $r6['sucesso'] && $r6['origem'] === 'favicon' && cincoArquivos($p6) && (bool) array_filter($r6['avisos'], fn($a) => str_contains($a, 'favicon')), json_encode($r6));
[$r7, $p7] = gerar('lixo', ['logo' => $lixo, 'favicon' => $svg, 'mascote' => $png]);
confere('logo corrompida e favicon SVG (o GD não lê): usa o mascote e avisa', $r7['sucesso'] && $r7['origem'] === 'mascote' && cincoArquivos($p7) && (bool) array_filter($r7['avisos'], fn($a) => str_contains($a, 'mascote')), json_encode($r7));
[$r8, $p8] = gerar('nada', ['logo' => $lixo, 'favicon' => $svg]);
confere('sem nenhuma imagem utilizável: não quebra, devolve sucesso=false com explicação e não cria arquivos', !$r8['sucesso'] && $r8['avisos'] && !is_dir($p8), json_encode($r8, JSON_UNESCAPED_UNICODE));
$mascote_real = dirname(__DIR__, 2) . '/assets/img/coyote-animado.webp';
confere('o mascote real do repositório (reserva final) é legível pelo GD', is_file($mascote_real) && pushIconesFonte(['mascote' => $mascote_real]) !== null);
[$r9, $p9] = gerar('mascote_real', ['logo' => $svg, 'mascote' => $mascote_real]);
confere('com o mascote real como reserva, os 5 arquivos saem com as dimensões certas', $r9['sucesso'] && cincoArquivos($p9) && $r9['origem'] === 'mascote', json_encode($r9));

echo "\n--- versão (troca de logo)\n";
confere('a versão é um hash de 8 caracteres e é DIFERENTE para logos diferentes', preg_match('/^[0-9a-f]{8}$/', $r1['v']) === 1 && $r1['v'] !== $r2['v'] && $r2['v'] !== $r3['v']);
[$r10] = gerar('png2', ['logo' => $png]);
confere('a mesma imagem gera a mesma versão', $r10['v'] === $r1['v']);
[$r11] = gerar('png', ['logo' => $png], $r1['v']);
confere('com a mesma versão e os arquivos já existentes não regera nada (mudou = false)', $r11['sucesso'] && $r11['mudou'] === false);
[$r12] = gerar('png', ['logo' => $png], $r1['v'], true);
confere('"forçar" regera mesmo assim', $r12['mudou'] === true);
[$r13] = gerar('png', ['logo' => $jpg], $r1['v']);
confere('trocar a logo (outra imagem na mesma pasta) regera e muda a versão', $r13['mudou'] === true && $r13['v'] === $r2['v']);
confere('a URL do ícone leva a versão', pushIconeUrl('icone-192.png', 'abc12345') === '/uploads/pwa/icone-192.png?v=abc12345' && pushIconeUrl('badge-96.png', '') === '/uploads/pwa/badge-96.png');

echo PHP_EOL . "RESUMO: $ok ok, $falhas falhas" . PHP_EOL;
exit($falhas > 0 ? 1 : 0);
