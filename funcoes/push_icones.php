<?php
declare(strict_types=1);

/**
 * Ícones dos avisos push e do app (PWA) gerados a partir da LOGO DO SISTEMA (a configurada em Admin → Configurações; white-label).
 * Plano: deploy/PLANO-PUSH-NOTIFICACOES.md (seção 10). Saída em uploads/pwa/ (serve só estático):
 *   icone-192.png            ícone da notificação (Android e computador) e do manifest
 *   icone-512.png            manifest
 *   icone-512-maskable.png   manifest (maskable): logo a 80% no centro, dentro da zona segura, fundo preenchido
 *   apple-touch-180.png      ícone do app na Tela de Início do iPhone (o iOS IGNORA o ícone do aviso e usa este); sem transparência
 *   badge-96.png             ícone pequeno e MONOCROMÁTICO da barra de status do Android (branco com transparência)
 * Fonte, em ordem: logo → favicon (png/jpg/webp) → mascote assets/img/coyote-animado.webp. O padrão logo-padrao.svg e arquivos .ico o GD não lê.
 * O hash curto da fonte vai em configuracoes.push_icone_v e no aviso (?v=…): trocar a logo atualiza o ícone sem mexer no service worker.
 */

const PUSH_ICONES_PASTA = __DIR__ . '/../uploads/pwa';
const PUSH_ICONES_ARQUIVOS = ['icone-192.png', 'icone-512.png', 'icone-512-maskable.png', 'apple-touch-180.png', 'badge-96.png'];
const PUSH_ICONES_DIMENSAO_MAXIMA = 4096;

/**
 * Lê uma configuração direto do banco (sem o cache por requisição de configSistema): ao salvar a logo no admin, o valor novo precisa valer na mesma requisição.
 * Sem banco, cai no configSistema.
 */
function pushIconesConfig(string $chave): string
{
    $pdo = $GLOBALS['pdo'] ?? null;
    if ($pdo instanceof PDO) {
        try {
            $st = $pdo->prepare('SELECT valor FROM configuracoes WHERE chave = ?');
            $st->execute([$chave]);
            $valor = $st->fetchColumn();
            return $valor === false ? '' : (string) $valor;
        } catch (Throwable $e) {
            // tabela ainda não existe: segue para o padrão
        }
    }
    return function_exists('configSistema') ? configSistema($chave) : '';
}

/**
 * Primeira fonte de imagem que o GD consegue ler: ['caminho' => absoluto, 'origem' => logo|favicon|mascote, 'tipo' => png|jpeg|webp, 'largura', 'altura'].
 * $candidatas (testes): [origem => caminho ABSOLUTO]; por padrão vêm da configuração do sistema.
 */
function pushIconesFonte(?array $candidatas = null): ?array
{
    $raiz = dirname(__DIR__);
    $absolutas = $candidatas !== null;
    $candidatas = $candidatas ?? [
        'logo' => pushIconesConfig('logo'),
        'favicon' => pushIconesConfig('favicon'),
        'mascote' => 'assets/img/coyote-animado.webp',
    ];
    foreach ($candidatas as $origem => $relativo) {
        $relativo = (string) $relativo;
        if (!$absolutas) {
            $relativo = ltrim($relativo, '/');
            if (str_contains($relativo, '..')) {
                continue;
            }
        }
        if ($relativo === '') {
            continue;
        }
        $caminho = $absolutas ? $relativo : $raiz . '/' . $relativo;
        if (!is_file($caminho) || filesize($caminho) > 8 * 1024 * 1024) {
            continue;
        }
        $info = @getimagesize($caminho);
        if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true) || $info[0] < 1 || $info[1] < 1
            || $info[0] > PUSH_ICONES_DIMENSAO_MAXIMA || $info[1] > PUSH_ICONES_DIMENSAO_MAXIMA) {
            continue;
        }
        $tipo = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpeg', IMAGETYPE_WEBP => 'webp'][$info[2]];
        return ['caminho' => $caminho, 'origem' => $origem, 'tipo' => $tipo, 'largura' => (int) $info[0], 'altura' => (int) $info[1]];
    }
    return null;
}

/** Abre a imagem em cor verdadeira, com transparência preservada. */
function pushIconesAbrir(array $fonte)
{
    $im = match ($fonte['tipo']) {
        'png' => @imagecreatefrompng($fonte['caminho']),
        'webp' => @imagecreatefromwebp($fonte['caminho']),
        default => @imagecreatefromjpeg($fonte['caminho']),
    };
    if ($im === false) {
        return false;
    }
    if (!imageistruecolor($im)) {
        imagepalettetotruecolor($im);
    }
    imagealphablending($im, false);
    imagesavealpha($im, true);
    return $im;
}

/** true se a imagem tem transparência de verdade (≥ 3% dos pixels de uma versão 64x64 com alfa relevante). JPEG nunca tem. */
function pushIconesTemTransparencia($im): bool
{
    $pequena = imagecreatetruecolor(64, 64);
    imagealphablending($pequena, false);
    imagesavealpha($pequena, true);
    imagefill($pequena, 0, 0, imagecolorallocatealpha($pequena, 0, 0, 0, 0));
    imagecopyresampled($pequena, $im, 0, 0, 0, 0, 64, 64, imagesx($im), imagesy($im));
    $transparentes = 0;
    for ($y = 0; $y < 64; $y++) {
        for ($x = 0; $x < 64; $x++) {
            if (((imagecolorat($pequena, $x, $y) >> 24) & 0x7f) >= 64) {
                $transparentes++;
            }
        }
    }
    imagedestroy($pequena);
    return $transparentes >= 64 * 64 * 0.03;
}

/** Cor média dos 4 cantos (opaca) — fundo para logo sem transparência que não é quadrada. [r, g, b] */
function pushIconesCorDeFundo($im): array
{
    $w = imagesx($im);
    $h = imagesy($im);
    $r = $g = $b = 0;
    foreach ([[0, 0], [$w - 1, 0], [0, $h - 1], [$w - 1, $h - 1]] as [$x, $y]) {
        $c = imagecolorat($im, $x, $y);
        $r += ($c >> 16) & 0xff;
        $g += ($c >> 8) & 0xff;
        $b += $c & 0xff;
    }
    return [(int) round($r / 4), (int) round($g / 4), (int) round($b / 4)];
}

/**
 * Quadrado $lado x $lado com a logo inteira dentro (sem cortar), centralizada e ocupando $fracao do lado.
 * $fundo: null = transparente; [r,g,b] = fundo sólido.
 */
function pushIconesQuadrado($im, int $lado, float $fracao, ?array $fundo)
{
    $dst = imagecreatetruecolor($lado, $lado);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagefill($dst, 0, 0, $fundo === null ? imagecolorallocatealpha($dst, 0, 0, 0, 127) : imagecolorallocate($dst, $fundo[0], $fundo[1], $fundo[2]));
    imagealphablending($dst, true);
    $w = imagesx($im);
    $h = imagesy($im);
    $escala = ($lado * $fracao) / max($w, $h);
    $nw = max(1, (int) round($w * $escala));
    $nh = max(1, (int) round($h * $escala));
    imagecopyresampled($dst, $im, (int) round(($lado - $nw) / 2), (int) round(($lado - $nh) / 2), 0, 0, $nw, $nh, $w, $h);
    imagealphablending($dst, false);
    return $dst;
}

/** Silhueta branca com o canal alfa da logo (só para logo com transparência), em 96x96 com 10% de margem. */
function pushIconesBadgeDaLogo($im)
{
    $q = pushIconesQuadrado($im, 96, 0.8, null);
    for ($y = 0; $y < 96; $y++) {
        for ($x = 0; $x < 96; $x++) {
            $alfa = (imagecolorat($q, $x, $y) >> 24) & 0x7f;
            imagesetpixel($q, $x, $y, imagecolorallocatealpha($q, 255, 255, 255, $alfa));
        }
    }
    return $q;
}

/** Glifo branco neutro (um sino), para logo sem transparência: o Android pinta o badge de uma cor só, e uma logo colorida viraria um quadrado branco. */
function pushIconesBadgeNeutro()
{
    $b = imagecreatetruecolor(96, 96);
    imagealphablending($b, false);
    imagesavealpha($b, true);
    imagefill($b, 0, 0, imagecolorallocatealpha($b, 0, 0, 0, 127));
    $branco = imagecolorallocatealpha($b, 255, 255, 255, 0);
    imagefilledellipse($b, 48, 38, 44, 44, $branco);                                  // topo do sino
    imagefilledpolygon($b, [26, 40, 70, 40, 78, 66, 18, 66], $branco);               // corpo
    imagefilledrectangle($b, 14, 64, 82, 70, $branco);                               // borda
    imagefilledellipse($b, 48, 78, 16, 12, $branco);                                 // badalo
    imagefilledellipse($b, 48, 18, 8, 8, $branco);                                   // argola
    return $b;
}

function pushIconesSalvar($im, string $nome, string $pasta = PUSH_ICONES_PASTA): bool
{
    if (!is_dir($pasta) && !@mkdir($pasta, 0755, true)) {
        return false;
    }
    $destino = $pasta . '/' . $nome;
    $tmp = $destino . '.tmp' . getmypid();
    imagesavealpha($im, true);
    if (!imagepng($im, $tmp, 9)) {
        return false;
    }
    @chmod($tmp, 0644);
    return rename($tmp, $destino);
}

/** Todos os 5 arquivos existem? */
function pushIconesCompletos(string $pasta = PUSH_ICONES_PASTA): bool
{
    foreach (PUSH_ICONES_ARQUIVOS as $a) {
        if (!is_file($pasta . '/' . $a)) {
            return false;
        }
    }
    return true;
}

/**
 * Gera (ou regenera) os 5 ícones e grava a versão em configuracoes.push_icone_v. Sem $forcar, não faz nada se a fonte não mudou e os arquivos existem.
 * Devolve ['sucesso', 'v', 'origem', 'badge' => 'silhueta'|'neutro', 'avisos' => [...], 'mudou' => bool].
 * $pasta e $candidatas só existem para os testes (pasta temporária e imagens de teste; nesse modo a configuração do sistema não é tocada).
 */
function pushIconesGerar(bool $forcar = false, ?string $pasta = null, ?array $candidatas = null, ?string $versao_atual = null): array
{
    $teste = $pasta !== null;
    $pasta = $pasta ?? PUSH_ICONES_PASTA;
    $fonte = pushIconesFonte($candidatas);
    if ($fonte === null) {
        return ['sucesso' => false, 'v' => '', 'origem' => '', 'badge' => '', 'avisos' => ['Nenhuma imagem utilizável (png, jpg ou webp) para gerar os ícones.'], 'mudou' => false];
    }
    $v = substr(sha1($fonte['origem'] . '|' . sha1_file($fonte['caminho'])), 0, 8);
    $atual = $teste ? (string) $versao_atual : pushIconesConfig('push_icone_v');
    if (!$forcar && $atual === $v && pushIconesCompletos($pasta)) {
        return ['sucesso' => true, 'v' => $v, 'origem' => $fonte['origem'], 'badge' => '', 'avisos' => [], 'mudou' => false];
    }
    $im = pushIconesAbrir($fonte);
    if ($im === false) {
        return ['sucesso' => false, 'v' => '', 'origem' => $fonte['origem'], 'badge' => '', 'avisos' => ['Não foi possível ler a imagem da logo.'], 'mudou' => false];
    }
    $avisos = [];
    if ($fonte['origem'] !== 'logo') {
        $avisos[] = $fonte['origem'] === 'favicon' ? 'A logo não pôde ser usada; os ícones saíram do favicon.' : 'Nem a logo nem o favicon puderam ser usados; os ícones saíram do mascote do Coyote.';
    }
    if (min($fonte['largura'], $fonte['altura']) < 512) {
        $avisos[] = 'A imagem tem ' . $fonte['largura'] . '×' . $fonte['altura'] . ' px: envie ao menos 512×512 (PNG com fundo transparente fica melhor).';
    }
    $transparente = $fonte['tipo'] !== 'jpeg' && pushIconesTemTransparencia($im);
    $fundo_solido = $transparente ? [20, 22, 29] : pushIconesCorDeFundo($im);   // logo transparente: fundo escuro do painel; logo com fundo: a cor do próprio fundo
    $fundo_icone = $transparente ? null : $fundo_solido;

    $ok = true;
    foreach ([
        'icone-192.png' => pushIconesQuadrado($im, 192, 1.0, $fundo_icone),
        'icone-512.png' => pushIconesQuadrado($im, 512, 1.0, $fundo_icone),
        'icone-512-maskable.png' => pushIconesQuadrado($im, 512, 0.8, $fundo_solido),
        'apple-touch-180.png' => pushIconesQuadrado($im, 180, 0.92, $fundo_solido),
        'badge-96.png' => $transparente ? pushIconesBadgeDaLogo($im) : pushIconesBadgeNeutro(),
    ] as $nome => $imagem) {
        $ok = pushIconesSalvar($imagem, $nome, $pasta) && $ok;
        imagedestroy($imagem);
    }
    imagedestroy($im);
    if ($ok && !$teste && function_exists('definirConfigSistema')) {
        definirConfigSistema('push_icone_v', $v);
    }
    return ['sucesso' => $ok, 'v' => $v, 'origem' => $fonte['origem'], 'badge' => $transparente ? 'silhueta' : 'neutro', 'avisos' => $avisos, 'mudou' => true];
}

/** URL (relativa à raiz do site) de um ícone gerado, com a versão. */
function pushIconeUrl(string $arquivo, ?string $versao = null): string
{
    $v = $versao ?? (function_exists('configSistema') ? configSistema('push_icone_v') : '');
    return '/uploads/pwa/' . $arquivo . ($v !== '' ? '?v=' . rawurlencode($v) : '');
}
