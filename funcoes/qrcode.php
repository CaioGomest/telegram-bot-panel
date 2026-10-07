<?php
declare(strict_types=1);

/**
 * Gerador de QR Code em PHP puro (sem biblioteca e sem serviço externo) — usado no QR do Pix.
 * Antes o código do Pix era enviado pela URL de um site de terceiros (api.qrserver.com) só pra desenhar o QR.
 *
 * Modo byte, correção de erro M (15%) com queda pra L se o texto for grande, versões 1 a 40, escolha automática da
 * máscara. Saída: matriz de módulos ou PNG de 1 bit (só zlib, sem GD). Algoritmo conferido contra a biblioteca
 * `qrcode` do npm (matriz idêntica com a mesma máscara) e decodificado com jsQR — ver anotacoes/HISTORICO-CONSOLIDADO.md.
 */
final class CodigoQr
{
    /** Blocos de correção por versão (1..40) para [L, M] — tabela oficial do padrão ISO/IEC 18004. */
    private const BLOCOS = [
        'L' => [1,1,1,1,1,2,2,2,2,4,4,4,4,4,6,6,6,6,7,8,8,9,9,10,12,12,12,13,14,15,16,17,18,19,19,20,21,22,24,25],
        'M' => [1,1,1,2,2,4,4,4,5,5,5,8,9,9,10,10,11,13,14,16,17,17,18,20,21,23,25,26,28,29,31,33,35,37,38,40,43,45,47,49],
    ];
    /** Total de palavras-código de correção por versão (1..40) para [L, M]. */
    private const CORRECAO = [
        'L' => [7,10,15,20,26,36,40,48,60,72,80,96,104,120,132,144,168,180,196,224,224,252,270,300,312,336,360,390,420,450,480,510,540,570,570,600,630,660,720,750],
        'M' => [10,16,26,36,48,64,72,88,110,130,150,176,198,216,240,280,308,338,364,416,442,476,504,560,588,644,700,728,784,812,868,924,980,1036,1064,1120,1204,1260,1316,1372],
    ];
    private const BITS_FORMATO = ['L' => 1, 'M' => 0];

    /** @var int[] */
    private static array $exp = [];
    /** @var int[] */
    private static array $log = [];

    /** Matriz de módulos (true = escuro). $mascara 0..7 força a máscara (testes); null escolhe a de menor penalidade. */
    public static function matriz(string $texto, string $nivel = 'M', ?int $mascara = null): array
    {
        $bytes = array_values(unpack('C*', $texto) ?: []);
        foreach ([$nivel, 'L'] as $tentativa) {
            for ($versao = 1; $versao <= 40; $versao++) {
                $dados_cw = self::totalPalavras($versao) - self::CORRECAO[$tentativa][$versao - 1];
                $bits_necessarios = 4 + ($versao <= 9 ? 8 : 16) + 8 * count($bytes);
                if ($bits_necessarios <= $dados_cw * 8) {
                    return self::montar($bytes, $versao, $tentativa, $mascara);
                }
            }
            if ($tentativa === 'L') {
                break;
            }
        }
        throw new \InvalidArgumentException('Texto grande demais para um QR Code.');
    }

    /** PNG (1 bit, preto no branco) com margem de 4 módulos. */
    public static function png(string $texto, int $escala = 0, int $margem = 4): string
    {
        $m = self::matriz($texto);
        $tam = count($m);
        $escala = $escala > 0 ? $escala : max(4, intdiv(360, $tam + 2 * $margem));
        $lado = ($tam + 2 * $margem) * $escala;
        $bytes_por_linha = intdiv($lado + 7, 8);
        $linhas = '';
        for ($ly = 0; $ly < $tam + 2 * $margem; $ly++) {
            $bits = '';
            for ($lx = 0; $lx < $tam + 2 * $margem; $lx++) {
                $y = $ly - $margem;
                $x = $lx - $margem;
                $escuro = $y >= 0 && $y < $tam && $x >= 0 && $x < $tam && $m[$y][$x];
                $bits .= str_repeat($escuro ? '0' : '1', $escala); // em escala de cinza de 1 bit, 0 = preto
            }
            $bits = str_pad($bits, $bytes_por_linha * 8, '1');
            $linha = "\0" . self::empacotarBits($bits);
            $linhas .= str_repeat($linha, $escala);
        }
        $cabecalho = pack('NNCCCCC', $lado, $lado, 1, 0, 0, 0, 0);
        return "\x89PNG\r\n\x1a\n" . self::blocoPng('IHDR', $cabecalho) . self::blocoPng('IDAT', gzcompress($linhas, 9)) . self::blocoPng('IEND', '');
    }

    // ===== PNG =====

    private static function blocoPng(string $tipo, string $dados): string
    {
        return pack('N', strlen($dados)) . $tipo . $dados . pack('N', crc32($tipo . $dados));
    }

    private static function empacotarBits(string $bits): string
    {
        $saida = '';
        for ($i = 0, $n = strlen($bits); $i < $n; $i += 8) {
            $saida .= chr(bindec(substr($bits, $i, 8)));
        }
        return $saida;
    }

    // ===== QR =====

    private static function totalPalavras(int $versao): int
    {
        $r = (16 * $versao + 128) * $versao + 64;
        if ($versao >= 2) {
            $alinh = intdiv($versao, 7) + 2;
            $r -= (25 * $alinh - 10) * $alinh - 55;
            if ($versao >= 7) {
                $r -= 36;
            }
        }
        return intdiv($r, 8);
    }

    private static function montar(array $bytes, int $versao, string $nivel, ?int $mascara_forcada): array
    {
        $total = self::totalPalavras($versao);
        $ec_total = self::CORRECAO[$nivel][$versao - 1];
        $dados_cw = $total - $ec_total;

        // Fluxo de bits: modo byte (0100) + tamanho + dados + terminador + preenchimento.
        $bits = '0100' . str_pad(decbin(count($bytes)), $versao <= 9 ? 8 : 16, '0', STR_PAD_LEFT);
        foreach ($bytes as $b) {
            $bits .= str_pad(decbin($b), 8, '0', STR_PAD_LEFT);
        }
        $bits .= str_repeat('0', min(4, $dados_cw * 8 - strlen($bits)));
        $bits .= str_repeat('0', (8 - strlen($bits) % 8) % 8);
        $cw = [];
        for ($i = 0, $n = strlen($bits); $i < $n; $i += 8) {
            $cw[] = bindec(substr($bits, $i, 8));
        }
        for ($pad = 0xEC; count($cw) < $dados_cw; $pad ^= 0xEC ^ 0x11) {
            $cw[] = $pad;
        }

        $palavras = self::intercalar($cw, $versao, $nivel, $total, $ec_total);
        return self::desenhar($palavras, $versao, $nivel, $mascara_forcada);
    }

    private static function intercalar(array $dados, int $versao, string $nivel, int $total, int $ec_total): array
    {
        $num_blocos = self::BLOCOS[$nivel][$versao - 1];
        $ec_por_bloco = intdiv($ec_total, $num_blocos);
        $curtos = $num_blocos - $total % $num_blocos;
        $tam_curto = intdiv($total, $num_blocos);
        $gerador = self::geradorRs($ec_por_bloco);
        $blocos = [];
        $k = 0;
        for ($i = 0; $i < $num_blocos; $i++) {
            $tam_dados = $tam_curto - $ec_por_bloco + ($i < $curtos ? 0 : 1);
            $bloco_dados = array_slice($dados, $k, $tam_dados);
            $k += $tam_dados;
            $ecc = self::restoRs($bloco_dados, $gerador);
            if ($i < $curtos) {
                $bloco_dados[] = 0; // posição de preenchimento do bloco curto (pulada na intercalação)
            }
            $blocos[] = array_merge($bloco_dados, $ecc);
        }
        $resultado = [];
        for ($i = 0, $n = count($blocos[0]); $i < $n; $i++) {
            foreach ($blocos as $j => $bloco) {
                if ($i !== $tam_curto - $ec_por_bloco || $j >= $curtos) {
                    $resultado[] = $bloco[$i];
                }
            }
        }
        return $resultado;
    }

    private static function desenhar(array $palavras, int $versao, string $nivel, ?int $mascara_forcada): array
    {
        $tam = $versao * 4 + 17;
        $m = array_fill(0, $tam, array_fill(0, $tam, false));
        $fn = array_fill(0, $tam, array_fill(0, $tam, false)); // módulos de função (não recebem máscara nem dados)
        $def = static function (int $x, int $y, bool $escuro) use (&$m, &$fn, $tam): void {
            if ($x >= 0 && $x < $tam && $y >= 0 && $y < $tam) {
                $m[$y][$x] = $escuro;
                $fn[$y][$x] = true;
            }
        };

        for ($i = 0; $i < $tam; $i++) { // temporização
            $def(6, $i, $i % 2 === 0);
            $def($i, 6, $i % 2 === 0);
        }
        foreach ([[3, 3], [$tam - 4, 3], [3, $tam - 4]] as [$cx, $cy]) { // marcadores de posição + separadores
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $dist = max(abs($dx), abs($dy));
                    $def($cx + $dx, $cy + $dy, $dist !== 2 && $dist !== 4);
                }
            }
        }
        $pos = self::posicoesAlinhamento($versao);
        $n_pos = count($pos);
        for ($i = 0; $i < $n_pos; $i++) { // alinhamento
            for ($j = 0; $j < $n_pos; $j++) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $n_pos - 1) || ($i === $n_pos - 1 && $j === 0)) {
                    continue;
                }
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $def($pos[$i] + $dx, $pos[$j] + $dy, max(abs($dx), abs($dy)) !== 1);
                    }
                }
            }
        }
        self::desenharFormato($def, $tam, $nivel, 0); // reserva a área (reescrita com a máscara final)
        if ($versao >= 7) {
            $resto = $versao;
            for ($i = 0; $i < 12; $i++) {
                $resto = ($resto << 1) ^ (($resto >> 11) * 0x1F25);
            }
            $bits = ($versao << 12) | $resto;
            for ($i = 0; $i < 18; $i++) {
                $bit = (($bits >> $i) & 1) === 1;
                $a = $tam - 11 + $i % 3;
                $b = intdiv($i, 3);
                $def($a, $b, $bit);
                $def($b, $a, $bit);
            }
        }

        // Dados em zigue-zague
        $i = 0;
        $total_bits = count($palavras) * 8;
        for ($direita = $tam - 1; $direita >= 1; $direita -= 2) {
            if ($direita === 6) {
                $direita = 5;
            }
            for ($vert = 0; $vert < $tam; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $direita - $j;
                    $sobe = (($direita + 1) & 2) === 0;
                    $y = $sobe ? $tam - 1 - $vert : $vert;
                    if (!$fn[$y][$x] && $i < $total_bits) {
                        $m[$y][$x] = (($palavras[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                        $i++;
                    }
                }
            }
        }

        // Máscara: a de menor penalidade (ou a forçada, nos testes)
        $melhor = null;
        $melhor_pen = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            if ($mascara_forcada !== null && $mask !== $mascara_forcada) {
                continue;
            }
            $teste = self::aplicarMascara($m, $fn, $tam, $mask);
            $def_teste = static function (int $x, int $y, bool $escuro) use (&$teste, $tam): void {
                if ($x >= 0 && $x < $tam && $y >= 0 && $y < $tam) {
                    $teste[$y][$x] = $escuro;
                }
            };
            self::desenharFormato($def_teste, $tam, $nivel, $mask);
            $pen = self::penalidade($teste, $tam);
            if ($pen < $melhor_pen) {
                $melhor_pen = $pen;
                $melhor = $teste;
            }
        }
        return $melhor;
    }

    private static function aplicarMascara(array $m, array $fn, int $tam, int $mask): array
    {
        for ($y = 0; $y < $tam; $y++) {
            for ($x = 0; $x < $tam; $x++) {
                if ($fn[$y][$x]) {
                    continue;
                }
                $inverte = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => $x * $y % 2 + $x * $y % 3 === 0,
                    6 => ($x * $y % 2 + $x * $y % 3) % 2 === 0,
                    default => (($x + $y) % 2 + $x * $y % 3) % 2 === 0,
                };
                if ($inverte) {
                    $m[$y][$x] = !$m[$y][$x];
                }
            }
        }
        return $m;
    }

    private static function desenharFormato(callable $def, int $tam, string $nivel, int $mask): void
    {
        $dados = (self::BITS_FORMATO[$nivel] << 3) | $mask;
        $resto = $dados;
        for ($i = 0; $i < 10; $i++) {
            $resto = ($resto << 1) ^ (($resto >> 9) * 0x537);
        }
        $bits = (($dados << 10) | $resto) ^ 0x5412;
        $bit = static fn(int $i): bool => (($bits >> $i) & 1) === 1;
        for ($i = 0; $i <= 5; $i++) {
            $def(8, $i, $bit($i));
        }
        $def(8, 7, $bit(6));
        $def(8, 8, $bit(7));
        $def(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) {
            $def(14 - $i, 8, $bit($i));
        }
        for ($i = 0; $i < 8; $i++) {
            $def($tam - 1 - $i, 8, $bit($i));
        }
        for ($i = 8; $i < 15; $i++) {
            $def(8, $tam - 15 + $i, $bit($i));
        }
        $def(8, $tam - 8, true); // módulo escuro fixo
    }

    private static function posicoesAlinhamento(int $versao): array
    {
        if ($versao === 1) {
            return [];
        }
        $n = intdiv($versao, 7) + 2;
        $tam = $versao * 4 + 17;
        $passo = $tam === 145 ? 26 : (int) (ceil(($tam - 13) / (2 * $n - 2)) * 2);
        $pos = [6];
        for ($p = $tam - 7; count($pos) < $n; $p -= $passo) {
            array_splice($pos, 1, 0, [$p]);
        }
        return $pos;
    }

    /** Penalidade padrão (4 regras) usada pra escolher a máscara. */
    private static function penalidade(array $m, int $tam): int
    {
        $pen = 0;
        for ($y = 0; $y < $tam; $y++) { // regra 1 (linhas e colunas) + regra 3
            foreach ([0, 1] as $col) {
                $seq = '';
                $corrida = 0;
                $ultimo = null;
                for ($k = 0; $k < $tam; $k++) {
                    $v = $col ? $m[$k][$y] : $m[$y][$k];
                    $seq .= $v ? '1' : '0';
                    if ($v === $ultimo) {
                        $corrida++;
                    } else {
                        if ($corrida >= 5) {
                            $pen += 3 + $corrida - 5;
                        }
                        $corrida = 1;
                        $ultimo = $v;
                    }
                }
                if ($corrida >= 5) {
                    $pen += 3 + $corrida - 5;
                }
                $pen += 40 * (substr_count($seq, '10111010000') + substr_count($seq, '00001011101'));
            }
        }
        for ($y = 0; $y < $tam - 1; $y++) { // regra 2
            for ($x = 0; $x < $tam - 1; $x++) {
                if ($m[$y][$x] === $m[$y][$x + 1] && $m[$y][$x] === $m[$y + 1][$x] && $m[$y][$x] === $m[$y + 1][$x + 1]) {
                    $pen += 3;
                }
            }
        }
        $escuros = 0;
        foreach ($m as $linha) {
            $escuros += count(array_filter($linha));
        }
        return $pen + 10 * (int) (abs($escuros * 100 / ($tam * $tam) - 50) / 5); // regra 4
    }

    // ===== Reed-Solomon sobre GF(256) =====

    private static function gfInicia(): void
    {
        if (self::$exp) {
            return;
        }
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            self::$exp[$i] = $x;
            self::$log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11D;
            }
        }
        for ($i = 255; $i < 512; $i++) {
            self::$exp[$i] = self::$exp[$i - 255];
        }
    }

    private static function gfMul(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }
        return self::$exp[self::$log[$a] + self::$log[$b]];
    }

    private static function geradorRs(int $grau): array
    {
        self::gfInicia();
        $r = array_fill(0, $grau, 0);
        $r[$grau - 1] = 1;
        $raiz = 1;
        for ($i = 0; $i < $grau; $i++) {
            for ($j = 0; $j < $grau; $j++) {
                $r[$j] = self::gfMul($r[$j], $raiz);
                if ($j + 1 < $grau) {
                    $r[$j] ^= $r[$j + 1];
                }
            }
            $raiz = self::gfMul($raiz, 2);
        }
        return $r;
    }

    private static function restoRs(array $dados, array $gerador): array
    {
        self::gfInicia();
        $n = count($gerador);
        $r = array_fill(0, $n, 0);
        foreach ($dados as $b) {
            $fator = $b ^ array_shift($r);
            $r[] = 0;
            foreach ($gerador as $i => $c) {
                $r[$i] ^= self::gfMul($c, $fator);
            }
        }
        return $r;
    }
}

/** PNG do QR Code de $texto (bytes) ou null se não der pra gerar (texto grande demais). */
function qrCodePng(string $texto): ?string
{
    try {
        return CodigoQr::png($texto);
    } catch (\Throwable $e) {
        error_log('[qrcode] ' . $e->getMessage());
        return null;
    }
}
