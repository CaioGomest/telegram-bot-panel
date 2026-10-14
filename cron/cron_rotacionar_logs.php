<?php
declare(strict_types=1);

/**
 * Rotação dos logs (1x por dia). Os arquivos de logs/*.log só crescem: o de acessos e o de avisos já passavam de 5 MB
 * cada, e num servidor com vários bots isso vira gigabytes dentro da cota do banco/disco.
 *
 * Regra: arquivo acima de LIMITE_LOG_BYTES vira .1 (o .1 antigo vira .2, e assim por diante); passa de GERACOES, o mais
 * antigo é apagado. O processo que escreve no log continua normal (cada escrita abre o arquivo de novo e recria o .log).
 * Só mexe em logs/*.log. Seguro de rodar mais de uma vez por dia.
 */

require_once __DIR__ . '/../conexao.php';
require_once __DIR__ . '/../funcoes/cron_guarda.php';
exigirChamadaDeCron('cron_rotacionar_logs');

const LIMITE_LOG_BYTES = 5 * 1024 * 1024;   // 5 MB
const GERACOES_LOG = 3;                     // .1, .2 e .3 ficam guardados

$pasta = __DIR__ . '/../logs';
$rotacionados = 0;
$apagados = 0;

foreach (glob($pasta . '/*.log') ?: [] as $arquivo) {
    if (!is_file($arquivo) || (int) filesize($arquivo) <= LIMITE_LOG_BYTES) {
        continue;
    }
    // abre espaço: apaga a geração mais antiga e empurra as outras
    $mais_antigo = $arquivo . '.' . GERACOES_LOG;
    if (is_file($mais_antigo) && @unlink($mais_antigo)) {
        $apagados++;
    }
    for ($g = GERACOES_LOG - 1; $g >= 1; $g--) {
        if (is_file($arquivo . '.' . $g)) {
            @rename($arquivo . '.' . $g, $arquivo . '.' . ($g + 1));
        }
    }
    if (@rename($arquivo, $arquivo . '.1')) {
        $rotacionados++;
    }
}

// Gerações antigas esquecidas (ex.: arquivo que parou de ser usado): mais de 60 dias, apaga.
foreach (glob($pasta . '/*.log.[0-9]*') ?: [] as $antigo) {
    if (is_file($antigo) && (time() - (int) filemtime($antigo)) > 60 * 86400 && @unlink($antigo)) {
        $apagados++;
    }
}

echo "Rotação de logs: $rotacionados rotacionado(s), $apagados apagado(s).\n";
