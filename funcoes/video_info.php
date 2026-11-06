<?php
declare(strict_types=1);

/**
 * Vídeo no Telegram: enviado por upload sem largura/altura/duração, o Telegram só descobre o formato depois de baixar o arquivo inteiro (MP4 com o moov no
 * fim, o padrão de celular/editor) e, até lá, mostra a prévia QUADRADA (1:1), mesmo para vídeo vertical ou horizontal. Estes dados vão junto no envio.
 */
/** Lê largura/altura/duração de um MP4/MOV (caixas ftyp/moov/mvhd/tkhd). Devolve também se o moov vem antes do mdat (faststart). */
function infoMp4(string $caminho): ?array {
    $f = @fopen($caminho, 'rb');
    if (!$f) return null;
    $tam_arquivo = filesize($caminho);
    $r = ['moov_inicio' => false];
    $pos = 0; $ordem = [];
    $moov = null;
    while ($pos + 8 <= $tam_arquivo) {
        fseek($f, $pos);
        $h = fread($f, 16);
        if (strlen($h) < 8) break;
        $tam = unpack('N', substr($h, 0, 4))[1];
        $tipo = substr($h, 4, 4);
        if ($tam === 1) { $tam = unpack('J', substr($h, 8, 8))[1]; }
        elseif ($tam === 0) { $tam = $tam_arquivo - $pos; }
        if ($tam < 8) break;
        $ordem[] = $tipo;
        if ($tipo === 'moov' && $tam < 20000000) { fseek($f, $pos); $moov = fread($f, $tam); }
        $pos += $tam;
    }
    fclose($f);
    $r['ordem'] = $ordem;
    $r['moov_inicio'] = array_search('moov', $ordem, true) !== false && array_search('moov', $ordem, true) < (array_search('mdat', $ordem, true) ?: 99);
    if ($moov === null) return $r;
    // mvhd: duração
    $i = strpos($moov, 'mvhd');
    if ($i !== false) {
        $v = ord($moov[$i + 4]);
        if ($v === 1) { $esc = unpack('N', substr($moov, $i + 24, 4))[1]; $dur = unpack('J', substr($moov, $i + 28, 8))[1]; }
        else { $esc = unpack('N', substr($moov, $i + 16, 4))[1]; $dur = unpack('N', substr($moov, $i + 20, 4))[1]; }
        if ($esc > 0) $r['duracao'] = (int) round($dur / $esc);
    }
    // tkhd: o de maior largura x altura (faixa de vídeo)
    $o = 0;
    while (($i = strpos($moov, 'tkhd', $o)) !== false) {
        $o = $i + 4;
        $v = ord($moov[$i + 4]);
        // layout: versão/flags(4) + tempos + track_id + reservado + duração + reservado(8) + camada/grupo/volume/res(8) + matriz(36) + largura/altura
        $base = $i + 4 + 4 + ($v === 1 ? 8 + 8 + 4 + 4 + 8 : 4 + 4 + 4 + 4 + 4) + 8 + 8 + 36;
        if ($base + 8 > strlen($moov)) continue;
        $m = array_values(unpack('N9', substr($moov, $base - 36, 36)));
        $w = unpack('N', substr($moov, $base, 4))[1] >> 16;
        $hh = unpack('N', substr($moov, $base + 4, 4))[1] >> 16;
        if ($w > 0 && $hh > 0) {
            // rotação 90/270: matriz a=0,b=±1
            $gira = ($m[0] === 0 && ($m[1] === 0x10000 || $m[1] === 0xFFFF0000 || $m[1] === -0x10000));
            $r['largura'] = $gira ? $hh : $w; $r['altura'] = $gira ? $w : $hh; $r['girado'] = $gira;
        }
    }
    return $r;
}

/** Parâmetros de sendVideo/InputMediaVideo que dizem ao Telegram o formato do vídeo (vazio se o arquivo não for MP4/MOV legível). */
function parametrosFormatoVideoTelegram(string $caminho): array {
    $info = is_file($caminho) ? infoMp4($caminho) : null;
    $p = ['supports_streaming' => true];
    if (!empty($info['largura']) && !empty($info['altura'])) {
        $p['width'] = (int) $info['largura'];
        $p['height'] = (int) $info['altura'];
    }
    if (!empty($info['duracao'])) {
        $p['duration'] = (int) $info['duracao'];
    }
    return $p;
}
