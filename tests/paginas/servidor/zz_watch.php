<?php
// Acompanha, em tempo real e SEM imprimir segredo, as vendas novas do bot 15 e os eventos de confirmação de pagamento. Uso por SSH, depois apague: php zz_watch.php [minutos]
chdir(__DIR__); require_once 'conexao.php';
$limite = time() + 60 * (int) ($argv[1] ?? 25);
$base = (int) $pdo->query("SELECT COALESCE(MAX(id),0) FROM vendas WHERE bot_id = 15")->fetchColumn();
$log = __DIR__ . '/logs/webhook_omegapayments.log';
$pos = is_file($log) ? filesize($log) : 0;
$vistos = [];
$fim_pago = 0;
echo "monitor ligado (vendas do bot 15 acima do id $base)\n"; flush();
while (time() < $limite) {
    foreach ($pdo->query("SELECT id, status, valor, criado_em, pago_em, id_grupo_telegram g, id_plano FROM vendas WHERE bot_id = 15 AND id > $base ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $v) {
        $chave = $v['id'] . '|' . $v['status'];
        if (!isset($vistos[$chave])) {
            $vistos[$chave] = true;
            echo date('H:i:s'), " venda #{$v['id']} status={$v['status']} valor=R$ {$v['valor']} criada={$v['criado_em']} pago_em=" . ($v['pago_em'] ?: '-') . ' grupo=' . ($v['g'] ? 'sim' : 'não') . "\n"; flush();
            if ($v['status'] === 'pago' && !$fim_pago) { $fim_pago = time(); }
        }
    }
    clearstatcache(); $tam = is_file($log) ? filesize($log) : 0;
    if ($tam > $pos) {
        $f = fopen($log, 'r'); fseek($f, $pos); $novo = stream_get_contents($f); fclose($f); $pos = $tam;
        foreach (explode("\n", $novo) as $l) {
            if (preg_match('/Venda #|marcada como PAGO|Processando notificação|Mensagem enviada|consulta direta|Falha ao gerar link|não encontrada|já paga/i', $l)) { echo date('H:i:s'), ' log: ', preg_replace('/[0-9]{6,}:[A-Za-z0-9_-]{20,}/', '[token]', substr($l, 0, 160)), "\n"; flush(); }
        }
    }
    if ($fim_pago && time() - $fim_pago > 90) { echo "venda paga e 90 s de acompanhamento concluídos\n"; break; }
    sleep(4);
}
echo "monitor encerrado\n";
