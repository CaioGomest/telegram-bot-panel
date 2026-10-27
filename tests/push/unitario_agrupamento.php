<?php
declare(strict_types=1);

/**
 * Teste unitário das regras de decisão dos avisos push (funcoes/push_cripto.php): agrupamento em rajada, texto do aviso (sem dados do cliente),
 * espera entre tentativas, classificação da resposta do serviço de push e descrição do aparelho. Sem banco, sem rede.
 * Rodar: php tests/push/unitario_agrupamento.php   (sai com código 1 se algo falhar)
 */

require_once __DIR__ . '/../../funcoes/push_cripto.php';

$ok = 0;
$falhas = 0;
function confere(string $nome, bool $certo, string $detalhe = ''): void
{
    global $ok, $falhas;
    $certo ? $ok++ : $falhas++;
    echo ($certo ? 'OK     ' : 'FALHOU ') . $nome . ($certo || $detalhe === '' ? '' : '  -> ' . $detalhe) . PHP_EOL;
}
function item(int $id, int $insc, int $tipo, float $valor = 10.0, ?int $ultimo = null): array
{
    return ['id' => $id, 'id_inscricao' => $insc, 'tipo' => $tipo, 'id_venda' => 1000 + $id, 'valor' => $valor, 'produto' => 'Plano Mensal', 'bot' => 'meubot', 'ultimo_aviso_gerado_ts' => $ultimo];
}
function ids(array $itens): array
{
    return array_map(fn($i) => $i['id'], $itens);
}

$agora = 1_800_000_000;

echo "--- Pix pago: nunca some\n";
$r = pushAgruparLote([item(1, 7, 2), item(2, 7, 2), item(3, 7, 2)], $agora);
confere('3 Pix pagos no mesmo aparelho saem os 3, individuais, sem resumo', ids($r['enviar']) === [1, 2, 3] && !$r['resumos'] && !$r['devolver']);
$lote = [];
for ($i = 1; $i <= 20; $i++) {
    $lote[] = item($i, 7, 2);
}
$r = pushAgruparLote($lote, $agora);
confere('exatamente 20 Pix pagos (o limite) saem todos individuais', count($r['enviar']) === 20 && !$r['resumos']);
$lote[] = item(21, 7, 2, 5.0);
$lote[] = item(22, 7, 2, 7.5);
$r = pushAgruparLote($lote, $agora);
confere('22 Pix pagos no mesmo aparelho: os 22 saem individuais, sem resumo (cada venda é um aviso)', count($r['enviar']) === 22 && !$r['resumos'] && !$r['devolver']);
$r = pushAgruparLote($lote, $agora, 30, 20);
confere('(mecanismo opcional, desligado por padrão) com limite 20: 22 Pix pagos: 19 individuais + 1 resumo "+3" (os 3 últimos), nada some', count($r['enviar']) === 19 && count($r['resumos']) === 1 && $r['resumos'][0]['kind'] === 'rp' && $r['resumos'][0]['n'] === 3 && $r['resumos'][0]['ids'] === [20, 21, 22] && abs($r['resumos'][0]['total'] - 22.5) < 0.001);
confere('as 22 vendas estão todas contabilizadas (19 individuais + 3 no resumo)', count($r['enviar']) + $r['resumos'][0]['n'] === 22);
$r = pushAgruparLote(array_merge($lote, [item(30, 8, 2), item(31, 8, 2)]), $agora);
confere('o limite é POR APARELHO: outro aparelho com 2 pagos sai normal', count(array_filter($r['enviar'], fn($i) => $i['id_inscricao'] === 8)) === 2);

echo "\n--- Pix gerado: 1 aviso a cada 30 s por aparelho\n";
$r = pushAgruparLote([item(1, 7, 1)], $agora);
confere('1 Pix gerado, sem aviso anterior: sai individual e abre a janela de 30 s', ids($r['enviar']) === [1] && $r['avisos_gerado'] === [7 => $agora] && !$r['resumos']);
$r = pushAgruparLote([item(1, 7, 1, 10.0), item(2, 7, 1, 20.0), item(3, 7, 1, 30.5)], $agora);
confere('3 Pix gerados de uma vez: viram UM resumo "3 Pix gerados" com o total e nenhum individual', !$r['enviar'] && count($r['resumos']) === 1 && $r['resumos'][0]['kind'] === 'rg' && $r['resumos'][0]['n'] === 3 && abs($r['resumos'][0]['total'] - 60.5) < 0.001 && $r['avisos_gerado'] === [7 => $agora]);
$r = pushAgruparLote([item(1, 7, 1, 10.0, $agora - 10), item(2, 7, 1, 10.0, $agora - 10)], $agora);
confere('dentro da janela (último aviso há 10 s): nada sai, os itens voltam à fila para daqui a 20 s', !$r['enviar'] && !$r['resumos'] && $r['devolver'] === [['ids' => [1, 2], 'disponivel_ts' => $agora + 20]] && !$r['avisos_gerado']);
$r = pushAgruparLote([item(1, 7, 1, 10.0, $agora - 30)], $agora);
confere('exatamente 30 s depois do último aviso a janela já abriu', ids($r['enviar']) === [1]);
$r = pushAgruparLote([item(1, 7, 1, 10.0, $agora - 29)], $agora);
confere('29 s depois ainda está dentro da janela', !$r['enviar'] && count($r['devolver']) === 1);
$r = pushAgruparLote([item(1, 7, 1, 10.0, $agora - 5), item(2, 8, 1, 10.0, null)], $agora);
confere('a janela é POR APARELHO: o aparelho 8 (sem aviso anterior) envia mesmo com o 7 esperando', ids($r['enviar']) === [2] && count($r['devolver']) === 1 && $r['devolver'][0]['ids'] === [1]);

echo "\n--- pago e gerado juntos\n";
$r = pushAgruparLote([item(1, 7, 1, 10.0, $agora - 5), item(2, 7, 2, 99.0)], $agora);
confere('o Pix pago sai na hora mesmo com o Pix gerado esperando a janela', ids($r['enviar']) === [2] && count($r['devolver']) === 1);
$r = pushAgruparLote([item(1, 7, 1), item(2, 7, 1), item(3, 7, 2)], $agora);
confere('2 gerados + 1 pago: 1 resumo de gerados e o pago individual', ids($r['enviar']) === [3] && count($r['resumos']) === 1 && $r['resumos'][0]['kind'] === 'rg');
confere('lote vazio não quebra', pushAgruparLote([], $agora) === ['enviar' => [], 'resumos' => [], 'devolver' => [], 'avisos_gerado' => []]);

echo "\n--- texto do aviso\n";
$m = pushMontarMensagem(['tipo' => 2, 'id_venda' => 1234, 'valor' => 49.9, 'produto' => 'Plano Mensal', 'bot' => 'meubot', 'criado_ts' => $agora], 'a1b2c3', $agora);
confere('Pix pago: título "Nova Venda!", corpo "Você recebeu R$ …", tag e ícone', $m['k'] === 'pp' && $m['t'] === '💰 Nova Venda!' && $m['b'] === 'Você recebeu R$ 49,90' && $m['g'] === 'pp-1234' && $m['v'] === 'a1b2c3' && $m['u'] === '/vendas' && $m['ts'] === $agora * 1000 && $m['n'] === 1, json_encode($m, JSON_UNESCAPED_UNICODE));
$m = pushMontarMensagem(['tipo' => 1, 'id_venda' => 55, 'valor' => 1234.5, 'produto' => 'Pack VIP'], '', $agora);
confere('Pix gerado: "Aguardando pagamento · plano" e milhar com ponto', $m['k'] === 'pg' && $m['t'] === 'Pix gerado · R$ 1.234,50' && $m['b'] === 'Aguardando pagamento · Pack VIP' && $m['g'] === 'pg-55', json_encode($m, JSON_UNESCAPED_UNICODE));
$m = pushMontarMensagem(['tipo' => 2, 'id_venda' => 9, 'valor' => 10.0], '', $agora);
confere('sem plano e sem bot: pago continua "Você recebeu R$ …" e gerado "Aguardando pagamento"', $m['b'] === 'Você recebeu R$ 10,00' && pushMontarMensagem(['tipo' => 1, 'id_venda' => 9, 'valor' => 10.0])['b'] === 'Aguardando pagamento');
$m = pushMontarMensagem(['kind' => 'rg', 'n' => 12, 'total' => 598.8], '', $agora);
confere('resumo de gerados: "12 Pix gerados · R$ 598,80" e tag fixa (o sistema substitui o anterior)', $m['t'] === '12 Pix gerados · R$ 598,80' && $m['b'] === 'nos últimos 30 s' && $m['g'] === 'pg-resumo' && $m['n'] === 12);
$m = pushMontarMensagem(['kind' => 'rp', 'n' => 3, 'total' => 22.5], '', $agora);
confere('resumo de pagos: "+3 Novas Vendas!" · "Você recebeu R$ 22,50"', $m['t'] === '💰 +3 Novas Vendas!' && $m['b'] === 'Você recebeu R$ 22,50' && $m['g'] === 'pp-resumo');
$m = pushMontarMensagem(['kind' => 'teste'], 'zz', $agora);
confere('aviso de teste: título e corpo fixos, tag própria', $m['k'] === 'teste' && $m['t'] === 'Aviso de teste' && $m['g'] === 'teste' && $m['v'] === 'zz');
$json = pushJson(pushMontarMensagem(['tipo' => 2, 'id_venda' => 1, 'valor' => 5.0, 'produto' => str_repeat('Plano muito longo ', 30), 'bot' => str_repeat('b', 200)], 'abc', $agora));
confere('texto enorme é cortado: o JSON fica abaixo de 400 bytes', strlen($json) < 400, (string) strlen($json));
$m = pushMontarMensagem(['tipo' => 1, 'id_venda' => 1, 'valor' => 5.0, 'produto' => "Plano\nA\t<b>x</b>", 'bot' => '@ja_tem_arroba', 'cliente' => 'Maria Silva', 'email' => 'm@x.com', 'id_telegram' => '123456', 'transacao_id' => 'TX-SECRETO'], '', $agora);
$tudo = json_encode($m, JSON_UNESCAPED_UNICODE);
confere('NUNCA leva nome do cliente, e-mail, ID do Telegram nem código da transação (campos extras são ignorados)', !str_contains($tudo, 'Maria') && !str_contains($tudo, 'm@x.com') && !str_contains($tudo, '123456') && !str_contains($tudo, 'TX-SECRETO'), $tudo);
confere('quebras de linha e abas do nome do plano viram espaço (aviso de Pix gerado)', $m['b'] === 'Aguardando pagamento · Plano A <b>x</b>', $m['b']);

echo "\n--- espera entre tentativas\n";
confere('Pix pago: 2 s, 10 s, 1 min, 5 min, 20 min (sem variação)', array_map(fn($n) => pushEsperaSegundos(2, $n, 0.5), [1, 2, 3, 4, 5]) === [2, 10, 60, 300, 1200]);
confere('Pix gerado: 5 s, 30 s, 2 min (sem variação)', array_map(fn($n) => pushEsperaSegundos(1, $n, 0.5), [1, 2, 3]) === [5, 30, 120]);
confere('a variação fica entre -20% e +20%', pushEsperaSegundos(2, 3, 0.0) === 48 && pushEsperaSegundos(2, 3, 1.0) === 72);
confere('além da escada repete a última espera e nunca é 0', pushEsperaSegundos(2, 99, 0.5) === 1200 && pushEsperaSegundos(2, 0, 0.5) === 2 && pushEsperaSegundos(2, 1, 0.0) >= 1);
confere('máximo de tentativas: Pix pago 5, Pix gerado 3', pushPodeTentarDeNovo(2, 4) && !pushPodeTentarDeNovo(2, 5) && pushPodeTentarDeNovo(1, 2) && !pushPodeTentarDeNovo(1, 3));
confere('validade: Pix gerado 10 min, Pix pago 2 h', PUSH_VALIDADE_SEGUNDOS[1] === 600 && PUSH_VALIDADE_SEGUNDOS[2] === 7200);

echo "\n--- resposta do serviço de push\n";
$esperado = [201 => 'ok', 202 => 'ok', 200 => 'ok', 404 => 'morta', 410 => 'morta', 400 => 'assinatura', 401 => 'assinatura', 403 => 'assinatura', 413 => 'grande', 429 => 'limite', 500 => 'temporario', 502 => 'temporario', 503 => 'temporario', 504 => 'temporario', 408 => 'temporario', 0 => 'temporario', 301 => 'temporario', 418 => 'temporario'];
$erros = [];
foreach ($esperado as $http => $classe) {
    if (pushClassificarResposta($http) !== $classe) {
        $erros[] = $http . '=>' . pushClassificarResposta($http);
    }
}
confere('classificação dos códigos HTTP (201/202 ok · 404/410 morta · 400/401/403 assinatura · 413 grande · 429 limite · resto temporário)', !$erros, implode(', ', $erros));
confere('erro de rede (timeout, DNS) é temporário mesmo com código 0 ou qualquer outro', pushClassificarResposta(0, 'Operation timed out') === 'temporario' && pushClassificarResposta(201, 'erro') === 'temporario');
confere('Retry-After em segundos, em data HTTP, vazio, lixo e com teto de 1 h', pushRetryAfterSegundos('30') === 30 && pushRetryAfterSegundos(gmdate('D, d M Y H:i:s', $agora + 90) . ' GMT', $agora) === 90 && pushRetryAfterSegundos('') === 0 && pushRetryAfterSegundos('abc') === 0 && pushRetryAfterSegundos('999999') === 3600 && pushRetryAfterSegundos(gmdate('D, d M Y H:i:s', $agora - 100) . ' GMT', $agora) === 0);

echo "\n--- descrição do aparelho\n";
$casos = [
    ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1', '', 'ios', 'iPhone · Safari'],
    ['Mozilla/5.0 (iPad; CPU OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1', '', 'ios', 'iPad · Safari'],
    ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15', 'ios', 'ios', 'iPad · Safari'],   // iPadOS 13+ se apresenta como Mac; a dica do JavaScript revela
    ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15', '', 'desktop', 'Mac · Safari'],
    ['Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Mobile Safari/537.36', '', 'android', 'Android · Chrome'],
    ['Mozilla/5.0 (Linux; Android 13; SAMSUNG SM-A546E) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/24.0 Chrome/117.0.0.0 Mobile Safari/537.36', '', 'android', 'Android · Samsung Internet'],
    ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36 Edg/124.0.0.0', '', 'desktop', 'Windows · Edge'],
    ['Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:125.0) Gecko/20100101 Firefox/125.0', '', 'desktop', 'Windows · Firefox'],
    ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36', '', 'desktop', 'Linux · Chrome'],
    ['', '', 'outro', 'Aparelho · navegador'],
];
foreach ($casos as [$ua, $dica, $plataforma, $apelido]) {
    $d = pushDescreverAparelho($ua, $dica);
    confere('"' . ($apelido) . '" (' . $plataforma . ')', $d['plataforma'] === $plataforma && $d['apelido'] === $apelido, json_encode($d, JSON_UNESCAPED_UNICODE));
}

echo PHP_EOL . "RESUMO: $ok ok, $falhas falhas" . PHP_EOL;
exit($falhas > 0 ? 1 : 0);
