<?php
// Limpa o estado volátil do cliente/bot de teste entre cenários.
require '/tmp/pt/common.php';
$i = ids();
$bot = $i['bot'];
$uid = $i['uid'];
foreach (['leads', 'vendas', 'membros_grupos', 'fluxo_continuacoes', 'fluxo_ofertas_aceitas', 'mensagens_agendadas', 'previas_vistas', 'leads_starts'] as $t) {
    try { $pdo->prepare("DELETE FROM $t WHERE bot_id = ?")->execute([$bot]); } catch (Throwable $e) { echo "aviso $t: ", $e->getMessage(), "\n"; }
}
try { $pdo->exec("DELETE FROM avisos_assinatura_enviados WHERE membro_id NOT IN (SELECT id FROM membros_grupos)"); } catch (Throwable $e) {}
$pdo->prepare("DELETE FROM atividades WHERE id_usuario = ?")->execute([$uid]);
@unlink('/tmp/pt/pagos.txt');
@unlink('/tmp/pt/falhas.json');
@file_put_contents('/tmp/pt/app/storage/cache_midia.json', '{}');
echo "reset ok\n";
