<?php
// Apaga TUDO que o teste criou (usuário, bot, fluxos, vendas, leads...). Não toca em nada de outros usuários.
require '/tmp/pt/common.php';
$i = ids();
if (!$i) { echo "sem ids.json\n"; exit; }
$uid = (int) $i['uid'];
$bots = array_column(q("SELECT id FROM bots WHERE id_usuario = ?", [$uid]), 'id');
foreach ($bots as $b) {
    foreach (['leads', 'vendas', 'membros_grupos', 'fluxo_continuacoes', 'fluxo_ofertas_aceitas', 'mensagens_agendadas', 'previas_vistas', 'links_rastreamento'] as $t) {
        try { $pdo->prepare("DELETE FROM $t WHERE bot_id = ?")->execute([$b]); } catch (Throwable $e) {}
    }
}
try { $pdo->exec("DELETE FROM avisos_assinatura_enviados WHERE membro_id NOT IN (SELECT id FROM membros_grupos)"); } catch (Throwable $e) {}
$pdo->prepare("DELETE FROM bots WHERE id_usuario = ?")->execute([$uid]);
foreach (['fluxos', 'atividades', 'usuarios_gateways', 'usuarios_traqueamento', 'fila_emails', 'redirecionadores'] as $t) {
    $col = $t === 'fila_emails' ? null : 'id_usuario';
    try { if ($col) $pdo->prepare("DELETE FROM $t WHERE $col = ?")->execute([$uid]); } catch (Throwable $e) { echo "aviso $t: ", $e->getMessage(), "\n"; }
}
try { $pdo->prepare("DELETE FROM fila_emails WHERE para = ? OR destinatario = ?")->execute([$i['email'], $i['email']]); } catch (Throwable $e) {}
try { $pdo->prepare("DELETE FROM tokens_email WHERE id_usuario = ?")->execute([$uid]); } catch (Throwable $e) {}
$pdo->prepare("DELETE FROM usuarios WHERE id = ? AND email = ?")->execute([$uid, $i['email']]);
echo "resíduos restantes: usuarios=", q1("SELECT COUNT(*) FROM usuarios WHERE email = ?", [$i['email']]), " bots=", q1("SELECT COUNT(*) FROM bots WHERE id_usuario = ?", [$uid]), " fluxos=", q1("SELECT COUNT(*) FROM fluxos WHERE id_usuario = ?", [$uid]), "\n";
