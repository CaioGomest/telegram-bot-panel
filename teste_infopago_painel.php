<?php
declare(strict_types=1);

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/funcoes/usuario.php';
require_once __DIR__ . '/funcoes/gateways.php';
require_once __DIR__ . '/funcoes/infopago_banco.php';

verificarLogin();
$userId = $_SESSION['usuario_id'];

$cfg = getUserGatewayConfig($userId, 'infopago');
$resultado = null;
$erro = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'testar') {
    if (!$cfg || empty($cfg['client_id']) || empty($cfg['client_secret']) || empty($cfg['certificado']) || empty($cfg['chave_pix'])) {
        $erro = 'Faltam credenciais da InfoPago configuradas (client_id, client_secret, certificado ou chave Pix). Configure em Gateways antes de testar.';
    } else {
        $banco = new InfopagoBanco($cfg['client_id'], $cfg['client_secret'], $cfg['certificado'], true, $cfg['cert_password'] ?? '');

        $passos = [];

        $autenticou = $banco->autenticar();
        $passos[] = ['nome' => 'Autenticação', 'ok' => $autenticou];

        if ($autenticou) {
            $valorTeste = (float)($_POST['valor'] ?? 0.10);
            $payload = $banco->montaPayloadCobranca($valorTeste, $cfg['chave_pix'], null, 3600);
            $resp = $banco->criarCobranca($payload);
            $passos[] = ['nome' => 'Criar cobrança', 'ok' => $resp['sucesso'] ?? false, 'detalhes' => $resp];

            if ($resp['sucesso'] ?? false) {
                $txid = $resp['dados']['txid'] ?? '';
                $pixCopiaCola = $resp['dados']['pixCopiaECola'] ?? '';
                $status = $resp['dados']['status'] ?? '';

                $respConsulta = $banco->consultarCobranca($txid);
                $passos[] = ['nome' => 'Consultar cobrança', 'ok' => $respConsulta['sucesso'] ?? false, 'detalhes' => $respConsulta];

                $resultado = [
                    'txid' => $txid,
                    'status' => $status,
                    'valor' => $valorTeste,
                    'pixCopiaCola' => $pixCopiaCola,
                    'qrCodeUrl' => $pixCopiaCola ? 'https://api.qrserver.com/v1/create-qr-code/?size=280x280&data=' . urlencode($pixCopiaCola) : null,
                ];
            }
        }

        $resultado['passos'] = $passos;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Teste InfoPago (temporário)</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: -apple-system, Segoe UI, Arial, sans-serif; background: #f8fafc; margin: 0; padding: 24px; color: #1e293b; }
        .box { max-width: 560px; margin: 0 auto; background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 24px; }
        h1 { font-size: 1.2rem; margin: 0 0 4px; }
        p.sub { color: #64748b; font-size: .85rem; margin: 0 0 20px; }
        .alert { padding: 12px 16px; border-radius: 8px; font-size: .88rem; margin-bottom: 16px; }
        .alert-error { background: #fee2e2; color: #991b1b; }
        .alert-warn { background: #fef9c3; color: #854d0e; }
        .passo { display: flex; align-items: center; gap: 8px; font-size: .88rem; margin-bottom: 6px; }
        .ok { color: #16a34a; font-weight: 700; }
        .fail { color: #dc2626; font-weight: 700; }
        .campo { margin-bottom: 14px; }
        label { display: block; font-size: .82rem; font-weight: 600; margin-bottom: 4px; color: #475569; }
        input[type=text], input[type=number] { width: 100%; padding: 9px 10px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: .9rem; box-sizing: border-box; }
        .btn { background: #2563eb; color: #fff; border: none; padding: 11px 18px; border-radius: 8px; font-weight: 600; cursor: pointer; font-size: .9rem; }
        .btn:hover { background: #1d4ed8; }
        .resultado { margin-top: 20px; padding-top: 20px; border-top: 1px solid #e2e8f0; }
        .copia-cola { font-size: .75rem; word-break: break-all; background: #f1f5f9; padding: 10px; border-radius: 8px; margin-top: 10px; }
        img.qr { display: block; margin: 14px auto; border-radius: 8px; }
        pre { font-size: .72rem; background: #0f172a; color: #e2e8f0; padding: 10px; border-radius: 8px; overflow-x: auto; }
    </style>
</head>
<body>
    <div class="box">
        <h1>Teste InfoPago</h1>
        <p class="sub">Página temporária de diagnóstico — usa as credenciais já salvas em Gateways para a sua conta. Apague este arquivo depois de terminar os testes.</p>

        <?php if ($erro): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($erro); ?></div>
        <?php endif; ?>

        <?php if (!$cfg): ?>
            <div class="alert alert-warn">Você ainda não configurou o gateway InfoPago na sua conta. Vá em <a href="gateways.php">Gateways</a> primeiro.</div>
        <?php else: ?>
            <form method="POST">
                <input type="hidden" name="acao" value="testar">
                <div class="campo">
                    <label>Valor do teste (R$)</label>
                    <input type="number" step="0.01" min="0.01" name="valor" value="0.10">
                </div>
                <button type="submit" class="btn">Testar autenticação + criar cobrança</button>
            </form>
        <?php endif; ?>

        <?php if ($resultado): ?>
            <div class="resultado">
                <?php foreach ($resultado['passos'] as $p): ?>
                    <div class="passo">
                        <span class="<?php echo $p['ok'] ? 'ok' : 'fail'; ?>"><?php echo $p['ok'] ? '✔' : '✘'; ?></span>
                        <?php echo htmlspecialchars($p['nome']); ?>
                    </div>
                <?php endforeach; ?>

                <?php if (!empty($resultado['pixCopiaCola'])): ?>
                    <p style="margin-top:14px;"><strong>txid:</strong> <?php echo htmlspecialchars($resultado['txid']); ?> — <strong>status:</strong> <?php echo htmlspecialchars($resultado['status']); ?></p>
                    <img class="qr" src="<?php echo htmlspecialchars($resultado['qrCodeUrl']); ?>" width="220" height="220" alt="QR Code Pix">
                    <div class="copia-cola"><?php echo htmlspecialchars($resultado['pixCopiaCola']); ?></div>
                <?php endif; ?>

                <details style="margin-top:14px;">
                    <summary style="cursor:pointer;font-size:.85rem;color:#64748b;">Ver detalhes técnicos (JSON)</summary>
                    <pre><?php echo htmlspecialchars(json_encode($resultado['passos'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></pre>
                </details>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
