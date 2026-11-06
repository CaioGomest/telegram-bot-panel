<?php
declare(strict_types=1);

/**
 * Formato do vídeo enviado ao Telegram (funcoes/video_info.php, 06/11/2026). Relato do cliente: "tá mandando toda mídia em formato 1:1 pro lead".
 * Causa: vídeo enviado por upload sem width/height/duration e com o moov no fim do MP4 aparece quadrado até o Telegram baixar tudo.
 * Sem rede: monta MP4 mínimos (ftyp/mdat/moov com mvhd e tkhd) e confere o que sai.
 *   php tests/guiado/unitario_video_formato.php   (sai com código 1 se algo falhar)
 */
require_once dirname(__DIR__, 2) . '/funcoes/video_info.php';

$ok = 0;
$falhas = 0;
function confere(string $nome, bool $certo, string $detalhe = ''): void
{
    global $ok, $falhas;
    $certo ? $ok++ : $falhas++;
    echo ($certo ? 'OK     ' : 'FALHOU ') . $nome . ($certo || $detalhe === '' ? '' : '  -> ' . $detalhe) . PHP_EOL;
}

function caixa(string $tipo, string $corpo): string
{
    return pack('N', 8 + strlen($corpo)) . $tipo . $corpo;
}
function mp4(int $largura, int $altura, int $duracao, bool $moov_no_inicio, bool $girado = false, bool $v1 = false): string
{
    $mvhd = $v1
        ? "\x01\0\0\0" . str_repeat("\0", 16) . pack('N', 1000) . pack('J', $duracao * 1000) . str_repeat("\0", 80)
        : "\0\0\0\0" . str_repeat("\0", 8) . pack('N', 1000) . pack('N', $duracao * 1000) . str_repeat("\0", 80);
    $matriz = $girado
        ? pack('N9', 0, 0x10000, 0, 0xFFFF0000, 0, 0, 0, 0, 0x40000000)
        : pack('N9', 0x10000, 0, 0, 0, 0x10000, 0, 0, 0, 0x40000000);
    $tkhd = $v1
        ? "\x01\0\0\x03" . str_repeat("\0", 8 + 8) . pack('N', 1) . pack('N', 0) . pack('J', 0)
        : "\0\0\0\x03" . str_repeat("\0", 4 + 4) . pack('N', 1) . pack('N', 0) . pack('N', 0);
    $tkhd .= str_repeat("\0", 8) . str_repeat("\0", 8) . $matriz . pack('N', $largura << 16) . pack('N', $altura << 16);
    $moov = caixa('moov', caixa('mvhd', $mvhd) . caixa('trak', caixa('tkhd', $tkhd)));
    $ftyp = caixa('ftyp', 'isom' . pack('N', 512) . 'isom');
    $mdat = caixa('mdat', str_repeat('x', 4096));
    return $moov_no_inicio ? $ftyp . $moov . $mdat : $ftyp . $mdat . $moov;
}

$pasta = sys_get_temp_dir() . '/teste_video_formato_' . bin2hex(random_bytes(3));
mkdir($pasta);
$arq = static function (string $nome, string $conteudo) use ($pasta): string {
    file_put_contents($pasta . '/' . $nome, $conteudo);
    return $pasta . '/' . $nome;
};

// Vertical com moov no fim (o caso do cliente: 1080x1934)
$p = parametrosFormatoVideoTelegram($arq('vertical.mp4', mp4(1080, 1934, 16, false)));
confere('vertical, moov no fim: largura 1080', ($p['width'] ?? 0) === 1080, json_encode($p));
confere('vertical, moov no fim: altura 1934', ($p['height'] ?? 0) === 1934);
confere('vertical, moov no fim: duração 16', ($p['duration'] ?? 0) === 16);
confere('sempre pede streaming', ($p['supports_streaming'] ?? false) === true);
confere('infoMp4 sabe que o moov está no fim', infoMp4($pasta . '/vertical.mp4')['moov_inicio'] === false);

// Horizontal com moov no começo
$p = parametrosFormatoVideoTelegram($arq('horizontal.mp4', mp4(1280, 720, 8, true)));
confere('horizontal 1280x720', ($p['width'] ?? 0) === 1280 && ($p['height'] ?? 0) === 720, json_encode($p));
confere('infoMp4 sabe que o moov está no início', infoMp4($pasta . '/horizontal.mp4')['moov_inicio'] === true);

// Versão 1 das caixas (64 bits)
$p = parametrosFormatoVideoTelegram($arq('v1.mp4', mp4(720, 1280, 30, false, false, true)));
confere('caixas de 64 bits: 720x1280, 30 s', ($p['width'] ?? 0) === 720 && ($p['height'] ?? 0) === 1280 && ($p['duration'] ?? 0) === 30, json_encode($p));

// Vídeo gravado com o celular na vertical (matriz de rotação 90°): o formato que o lead vê é o girado
$p = parametrosFormatoVideoTelegram($arq('girado.mov', mp4(1920, 1080, 5, false, true)));
confere('rotação 90°: sai 1080x1920', ($p['width'] ?? 0) === 1080 && ($p['height'] ?? 0) === 1920, json_encode($p));

// Arquivo que não é MP4 ou não existe: não quebra e não inventa medida
$p = parametrosFormatoVideoTelegram($arq('lixo.mp4', str_repeat('z', 3000)));
confere('arquivo inválido: sem largura/altura, mas segue com streaming', !isset($p['width'], $p['height']) && ($p['supports_streaming'] ?? false) === true, json_encode($p));
$p = parametrosFormatoVideoTelegram($pasta . '/nao_existe.mp4');
confere('arquivo que não existe: só streaming', $p === ['supports_streaming' => true], json_encode($p));
confere('arquivo vazio não derruba', is_array(parametrosFormatoVideoTelegram($arq('vazio.mp4', ''))));

// O envio de verdade: requisicaoTelegram acrescenta os parâmetros a todo sendVideo com upload (todas as telas passam por ela)
$fonte = (string) file_get_contents(dirname(__DIR__, 2) . '/funcoes/fluxo_blocos.php');
confere('requisicaoTelegram acrescenta o formato a todo sendVideo com upload', str_contains($fonte, "\$metodo === 'sendVideo' && isset(\$arquivos['video'])") && str_contains($fonte, '$parametros += parametrosFormatoVideoTelegram($arquivos[\'video\']);'));
$guiado = (string) file_get_contents(dirname(__DIR__, 2) . '/funcoes/guiado_ofertas.php');
confere('álbum leva o formato de cada vídeo', str_contains($guiado, '$entrada += parametrosFormatoVideoTelegram($it[\'caminho\']);'));
confere('cache de vídeo antigo (sem o formato) é descartado pela chave', str_contains($guiado, "'|v2'"));

array_map('unlink', glob($pasta . '/*'));
rmdir($pasta);
echo PHP_EOL . "$ok ok, $falhas falha(s)" . PHP_EOL;
exit($falhas > 0 ? 1 : 0);
