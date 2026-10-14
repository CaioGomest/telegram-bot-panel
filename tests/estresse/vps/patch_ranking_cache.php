<?php
// EXPERIMENTO (só na cópia de teste): troca buscarRankingMensal() por uma versão que calcula o ranking do mês UMA vez por minuto para todos
// (1 consulta) e responde cada tela a partir desse "retrato", em vez de rodar 3 consultas que varrem as métricas de TODOS os vendedores a cada
// abertura do dashboard e do /ranking. Serve para medir quanto a capacidade do painel sobe com esse conserto.
//   php patch_ranking_cache.php aplicar|desfazer [/var/www/painel-estresse]
$modo = $argv[1] ?? '';
$app = rtrim($argv[2] ?? '/var/www/painel-estresse', '/');
if ($app === '/var/www/painel') { fwrite(STDERR, "recusado: nunca no painel real\n"); exit(2); }
$arq = "$app/funcoes/ranking.php";
$orig = "$arq.orig";
if ($modo === 'desfazer') { if (is_file($orig)) { copy($orig, $arq); unlink($orig); echo "desfeito\n"; } exit; }
if ($modo !== 'aplicar') { fwrite(STDERR, "uso: aplicar|desfazer\n"); exit(2); }
if (!is_file($orig)) copy($arq, $orig);
$c = file_get_contents($orig);
$c = preg_replace('/function buscarRankingMensal\(/', 'function buscarRankingMensalOriginal(', $c, 1);
$c .= <<<'PHP'


/** EXPERIMENTO: retrato do ranking do mês refeito no máximo 1x por minuto (arquivo em /dev/shm), compartilhado por todos os usuários. */
function buscarRankingMensal(int $usuario_atual_id, ?DateTime $referencia = null): array
{
    global $pdo;
    $referencia = $referencia ?? new DateTime();
    $inicio = $referencia->format('Y-m-01');
    $fim = $referencia->format('Y-m-t');
    $f = '/dev/shm/est/rkg_' . $referencia->format('Ym');
    $snap = (is_file($f) && time() - (int) @filemtime($f) < 60) ? @unserialize((string) @file_get_contents($f)) : null;
    if (!is_array($snap)) {
        $st = $pdo->prepare("SELECT m.id_usuario, SUM(m.valor_pago) AS faturamento, MAX(u.apelido_publico) AS apelido_publico
            FROM metricas_horarias_usuario m JOIN usuarios u ON u.id = m.id_usuario
            WHERE m.data BETWEEN ? AND ? AND u.perfil = 'usuario' GROUP BY m.id_usuario HAVING SUM(m.valor_pago) > 0
            ORDER BY faturamento DESC, m.id_usuario ASC");
        $st->execute([$inicio, $fim]);
        $snap = $st->fetchAll(PDO::FETCH_ASSOC);
        @file_put_contents($f . '.' . getmypid(), serialize($snap));
        @rename($f . '.' . getmypid(), $f);
    }
    $vazio = ['top3' => [], 'linhas' => [], 'sua_posicao' => null, 'total_participantes' => 0, 'faturamento_lider' => null, 'faturamento_top5' => null];
    $brutas = array_slice($snap, 0, 10);
    $posicao = 0; $anterior = null;
    foreach ($brutas as $i => $linha) {
        $fat = (float) $linha['faturamento'];
        if ($anterior === null || $fat < $anterior) $posicao = $i + 1;
        $anterior = $fat;
        $brutas[$i]['posicao'] = $posicao;
        $brutas[$i]['faturamento'] = $fat;
    }
    $meu = null; $acima = 0;
    foreach ($snap as $l) { if ((int) $l['id_usuario'] === $usuario_atual_id) { $meu = $l; break; } }
    $sua = null;
    if ($meu) {
        foreach ($snap as $l) { if ((float) $l['faturamento'] > (float) $meu['faturamento']) $acima++; else break; }
        $sua = ['posicao' => $acima + 1, 'id_usuario' => $usuario_atual_id, 'faturamento' => (float) $meu['faturamento'], 'apelido_publico' => $meu['apelido_publico'] ?: null];
    }
    $top5 = $brutas[4]['faturamento'] ?? ($brutas ? $brutas[count($brutas) - 1]['faturamento'] : null);
    return ['top3' => array_slice($brutas, 0, 3), 'linhas' => array_slice($brutas, 3, 7), 'sua_posicao' => $sua, 'total_participantes' => count($snap),
            'faturamento_lider' => $brutas ? (float) $brutas[0]['faturamento'] : null, 'faturamento_top5' => $top5 !== null ? (float) $top5 : null];
}
PHP;
file_put_contents($arq, $c);
echo "aplicado (original em $orig)\n";
