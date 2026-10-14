<?php
// Conferência SOMENTE LEITURA com os serviços REAIS da conta de teste do dono (usuário 36): Telegram getMe/getWebhookInfo e OmegaPayments (consulta de cobrança existente).
// Não envia mensagem a ninguém, não cria cobrança. Não imprime segredo. Uso (por SSH, depois apague): php zz_real.php
chdir(__DIR__); require_once 'conexao.php'; require_once 'funcoes/usuario.php'; require_once 'funcoes/fluxo_blocos.php'; require_once 'funcoes/gateways.php';

$uid = 36;
$bot = $pdo->query("SELECT id, token, nome_usuario, url_webhook FROM bots WHERE id_usuario = $uid ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
echo "== Telegram (bot @{$bot['nome_usuario']}) ==\n";
$me = requisicaoTelegram($bot['token'], 'getMe');
echo 'getMe: ok=', var_export($me['ok'] ?? null, true), ' username=', $me['result']['username'] ?? '-', ' id=', $me['result']['id'] ?? '-', "\n";
$wh = requisicaoTelegram($bot['token'], 'getWebhookInfo');
$r = $wh['result'] ?? [];
echo 'webhook: url=', $r['url'] ?? '-', ' | pendentes=', $r['pending_update_count'] ?? '-', ' | ultimo_erro=', $r['last_error_message'] ?? 'nenhum', ' | ultimo_erro_em=', isset($r['last_error_date']) ? date('Y-m-d H:i:s', $r['last_error_date']) : '-', ' | ips=', $r['ip_address'] ?? '-', ' | conexoes_max=', $r['max_connections'] ?? '-', "\n";
echo 'webhook do banco bate com o do Telegram? ', ($bot['url_webhook'] ?? '') === ($r['url'] ?? '') ? 'sim' : 'NÃO (banco=' . ($bot['url_webhook'] ?? '-') . ')', "\n";

echo "\n== OmegaPayments ==\n";
$cfg = getPrimaryUserGatewayConfig($uid);
echo 'config do usuário: ', $cfg ? 'achada (gateway ' . ($cfg['nome'] ?? '?') . ', ativo)' : 'NÃO achada', "\n";
if ($cfg) {
    $prov = resolveGatewayProvider((string) ($cfg['nome'] ?? 'omegapayments'), $cfg);
    echo 'provider: ', $prov ? get_class($prov) : 'nulo', "\n";
    $v = $pdo->query("SELECT id, transacao_id, status, valor FROM vendas WHERE bot_id = {$bot['id']} AND transacao_id IS NOT NULL AND transacao_id <> '' ORDER BY id DESC LIMIT 2")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($v as $venda) {
        $t = microtime(true);
        $res = $prov->consultarCobranca((string) $venda['transacao_id']);
        $ms = round((microtime(true) - $t) * 1000);
        $d = $res['dados'] ?? $res['data'] ?? $res;
        $chaves = implode(',', array_slice(array_keys((array) $res), 0, 8));
        $st = $res['status'] ?? ($d['status'] ?? ($d['data']['status'] ?? '?'));
        echo "venda {$venda['id']} (banco: {$venda['status']}, R$ {$venda['valor']}) → consulta ao gateway em {$ms} ms: sucesso=", var_export($res['sucesso'] ?? $res['success'] ?? null, true), " status_gateway=", is_scalar($st) ? $st : json_encode($st), " | chaves da resposta: $chaves\n";
    }
}
echo "\n== E-mail / SMTP ==\n";
echo 'funcao de envio de e-mail: ', function_exists('enviarEmail') ? 'enviarEmail()' : 'ver funcoes/email.php', "\n";
