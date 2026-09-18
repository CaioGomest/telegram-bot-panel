<?php
declare(strict_types=1);

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/funcoes/usuario.php';
require_once __DIR__ . '/funcoes/gateways.php';
require_once __DIR__ . '/funcoes/infopago_banco.php';

verificarLogin();
$user_id = $_SESSION['usuario_id'];

$cfg = getUserGatewayConfig($user_id, 'infopago');
$resultado = null;
$erro = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'testar') {
    verificarCsrf();
    if (!$cfg || empty($cfg['client_id']) || empty($cfg['client_secret']) || empty($cfg['certificado']) || empty($cfg['chave_pix'])) {
        $erro = 'Faltam credenciais da InfoPago configuradas (client_id, client_secret, certificado ou chave Pix). Configure em Gateways antes de testar.';
    } else {
        $banco = new InfopagoBanco($cfg['client_id'], $cfg['client_secret'], $cfg['certificado'], true, $cfg['cert_password'] ?? '');

        $passos = [];

        $autenticou = $banco->autenticar();
        $passos[] = ['nome' => 'Autenticação', 'ok' => $autenticou];

        if ($autenticou) {
            $valor_teste = (float)($_POST['valor'] ?? 0.10);
            $payload = $banco->montaPayloadCobranca($valor_teste, $cfg['chave_pix'], null, 3600);
            $resp = $banco->criarCobranca($payload);
            $passos[] = ['nome' => 'Criar cobrança', 'ok' => $resp['sucesso'] ?? false, 'detalhes' => $resp];

            if ($resp['sucesso'] ?? false) {
                $txid = $resp['dados']['txid'] ?? '';
                $pix_copia_cola = $resp['dados']['pixCopiaECola'] ?? '';
                $status = $resp['dados']['status'] ?? '';

                $resp_consulta = $banco->consultarCobranca($txid);
                $passos[] = ['nome' => 'Consultar cobrança', 'ok' => $resp_consulta['sucesso'] ?? false, 'detalhes' => $resp_consulta];

                $resultado = [
                    'txid' => $txid,
                    'status' => $status,
                    'valor' => $valor_teste,
                    'pixCopiaCola' => $pix_copia_cola,
                    'qrCodeUrl' => $pix_copia_cola ? 'https://api.qrserver.com/v1/create-qr-code/?size=280x280&data=' . urlencode($pix_copia_cola) : null,
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
    <?php include 'tema_inline.php'; ?>
    <link rel="stylesheet" href="assets/css/coyote.css?v=<?php echo @filemtime(__DIR__.'/assets/css/coyote.css'); ?>">
    <style>
        .copia-cola { font-size: 11px; word-break: break-all; background: var(--p2); padding: 10px; border-radius: 8px; margin-top: 10px; }
        img.qr { display: block; margin: 14px auto; border-radius: 8px; }
        .passo { display: flex; align-items: center; gap: 8px; font-size: 13px; margin-bottom: 6px; }
        .passo .ok { color: var(--ok); font-weight: 700; }
        .passo .fail { color: var(--da); font-weight: 700; }
    </style>
</head>
<body>
<div class="layout-painel">
    <?php include 'barra_lateral.php'; ?>
    <main class="conteudo-principal">
        <div class="cabecalho-pagina">
            <div>
                <h1>Teste InfoPago</h1>
                <p>Página temporária de diagnóstico — usa as credenciais já salvas em Gateways para a sua conta. Apague este arquivo depois de terminar os testes.</p>
            </div>
        </div>

        <div class="painel" style="max-width: 560px;">
            <?php if ($erro): ?>
                <div class="aviso aviso-erro"><?php echo htmlspecialchars($erro); ?></div>
            <?php endif; ?>

            <?php if (!$cfg): ?>
                <div class="aviso aviso-alerta">Você ainda não configurou o gateway InfoPago na sua conta. Vá em <a href="gateways.php">Gateways</a> primeiro.</div>
            <?php else: ?>
                <form method="POST">
                    <?php echo campoCsrf(); ?>
                    <input type="hidden" name="acao" value="testar">
                    <div class="campo">
                        <label>Valor do teste (R$)</label>
                        <input type="number" step="0.01" min="0.01" name="valor" value="0.10">
                    </div>
                    <button type="submit" class="botao botao-primario" style="margin-top:14px;">Testar autenticação + criar cobrança</button>
                </form>
            <?php endif; ?>

            <?php if ($resultado): ?>
                <div class="resultado" style="margin-top:20px;padding-top:20px;border-top:1px solid var(--bd);">
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
                        <summary class="texto-suave" style="cursor:pointer;">Ver detalhes técnicos (JSON)</summary>
                        <pre class="saida-debug" style="margin-top:8px;"><?php echo htmlspecialchars(json_encode($resultado['passos'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></pre>
                    </details>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<script src="assets/js/tema.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/tema.js'); ?>"></script>
</body>
</html>
