<?php
declare(strict_types=1);

require_once __DIR__ . '/funcoes/usuario.php';
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/funcoes/gateways.php';
require_once __DIR__ . '/funcoes/infopago_cashout.php';

verificarAdmin();
$userId = (int)$_SESSION['usuario_id'];

$GATEWAYS_SUPORTADOS = ['infopago'];

// ── Ação: testar autenticação de um gateway ─────────────────────────────────
$resultadoAuth = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'testar_auth') {
    $nome = $_POST['gateway_nome'] ?? '';
    $cfg = getUserGatewayConfig($userId, $nome);
    if (!$cfg || empty($cfg['client_id'])) {
        $resultadoAuth = ['gateway' => $nome, 'ok' => false, 'msg' => 'Gateway não configurado.'];
    } else {
        try {
            $provedor = resolveGatewayProvider($nome, $cfg);
            $ok = $provedor && $provedor->validarCredenciais();
            $resultadoAuth = ['gateway' => $nome, 'ok' => $ok, 'msg' => $ok ? 'Autenticado com sucesso.' : 'Falha na autenticação — confira credenciais e certificado.'];
        } catch (Exception $e) {
            $resultadoAuth = ['gateway' => $nome, 'ok' => false, 'msg' => 'Exceção: ' . $e->getMessage()];
        }
    }
}

// ── Ação: criar cobrança de teste ───────────────────────────────────────────
$resultadoCobranca = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'criar_cobranca') {
    $nome = $_POST['gateway_nome'] ?? '';
    $valor = (float)($_POST['valor'] ?? 0.10);
    $cfg = getUserGatewayConfig($userId, $nome);

    if (!$cfg || empty($cfg['client_id']) || empty($cfg['chave_pix'])) {
        $resultadoCobranca = ['ok' => false, 'msg' => 'Gateway não configurado (falta client_id ou chave Pix).'];
    } else {
        try {
            $provedor = resolveGatewayProvider($nome, $cfg);
            $payload = $provedor->montaPayloadCobranca($valor, $cfg['chave_pix'], null, 3600);
            $resp = $provedor->criarCobranca($payload);

            if ($resp['sucesso'] ?? false) {
                $txid = $resp['dados']['txid'] ?? ($resp['dados']['id'] ?? '');
                $pixCopiaCola = $resp['dados']['pixCopiaECola'] ?? ($resp['dados']['qr_code'] ?? '');
                $resultadoCobranca = [
                    'ok' => true,
                    'gateway' => $nome,
                    'txid' => $txid,
                    'status' => $resp['dados']['status'] ?? '?',
                    'pixCopiaCola' => $pixCopiaCola,
                    'qrCodeUrl' => $pixCopiaCola ? 'https://api.qrserver.com/v1/create-qr-code/?size=240x240&data=' . urlencode($pixCopiaCola) : null,
                ];
            } else {
                $resultadoCobranca = ['ok' => false, 'msg' => 'Falha ao criar cobrança: ' . ($resp['erro'] ?? 'desconhecido')];
            }
        } catch (Exception $e) {
            $resultadoCobranca = ['ok' => false, 'msg' => 'Exceção: ' . $e->getMessage()];
        }
    }
}

// ── Ação: simular/testar split InfoPago ─────────────────────────────────────
$resultadoSplit = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'testar_split') {
    $idUsuarioAlvo = (int)($_POST['id_usuario_split'] ?? 0);
    $valorVenda = (float)($_POST['valor_venda'] ?? 0);
    $executarDeVerdade = isset($_POST['executar']);

    $stmt = $pdo->prepare("SELECT chave_pix_split, taxa_split, tipo_split FROM usuarios_splits WHERE id_usuario = ? AND gateway_nome = 'infopago' LIMIT 1");
    $stmt->execute([$idUsuarioAlvo]);
    $split = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$split) {
        $resultadoSplit = ['ok' => false, 'msg' => 'Esse usuário não tem split configurado para InfoPago (configure em Usuários → editar → Splits).'];
    } else {
        $valorSplit = ($split['tipo_split'] === 'fixo') ? (float)$split['taxa_split'] : round($valorVenda * ((float)$split['taxa_split'] / 100), 2);

        $stmtCred = $pdo->prepare("
            SELECT ug.cashout_client_id, ug.cashout_client_secret, ug.cashout_certificado
            FROM usuarios_gateways ug JOIN usuarios u ON ug.id_usuario = u.id JOIN gateways g ON ug.id_gateway = g.id
            WHERE g.nome = 'infopago' AND u.perfil = 'admin' ORDER BY ug.atualizado_em DESC LIMIT 1
        ");
        $stmtCred->execute();
        $cred = $stmtCred->fetch(PDO::FETCH_ASSOC);

        $resultadoSplit = [
            'destino' => $split['chave_pix_split'],
            'tipo' => $split['tipo_split'],
            'taxa' => $split['taxa_split'],
            'valorCalculado' => $valorSplit,
            'temCredenciaisCashout' => !empty($cred['cashout_client_id']) && !empty($cred['cashout_certificado']),
            'executado' => false,
        ];

        if ($executarDeVerdade) {
            if (!$resultadoSplit['temCredenciaisCashout']) {
                $resultadoSplit['ok'] = false;
                $resultadoSplit['msg'] = 'Não é possível executar: credenciais de Cash-Out do admin não configuradas.';
            } else {
                $cashout = new InfopagoCashout($cred['cashout_client_id'], $cred['cashout_client_secret'], $cred['cashout_certificado']);
                $resp = $cashout->transferirPorChavePix($split['chave_pix_split'], $valorSplit, 'Teste de split (painel admin)');
                $resultadoSplit['executado'] = true;
                $resultadoSplit['ok'] = $resp['sucesso'] ?? false;
                $resultadoSplit['msg'] = ($resp['sucesso'] ?? false) ? 'Transferência real enviada com sucesso!' : 'Falha na transferência: ' . ($resp['erro'] ?? 'desconhecido');
            }
        } else {
            $resultadoSplit['ok'] = true;
            $resultadoSplit['msg'] = 'Simulação (dry-run) — nenhuma transferência real foi enviada.';
        }
    }
}

// ── Ação: simular decisão de permanência/expiração (sem tocar Telegram/BD) ──
$resultadoPermanencia = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'testar_permanencia') {
    if (!empty($_POST['membro_id'])) {
        $stmt = $pdo->prepare("
            SELECT m.*, v.tipo_cobranca, v.id_assinatura
            FROM membros_grupos m
            LEFT JOIN vendas v ON m.venda_id = v.id
            WHERE m.id = ?
        ");
        $stmt->execute([(int)$_POST['membro_id']]);
        $membro = $stmt->fetch(PDO::FETCH_ASSOC);
        $dataExpiracao = $membro['data_expiracao'] ?? null;
        $tipoCobranca = $membro['tipo_cobranca'] ?? 'unica';
        $temIdAssinatura = !empty($membro['id_assinatura']);
    } else {
        $dataExpiracao = $_POST['data_expiracao'] ?? null;
        $tipoCobranca = $_POST['tipo_cobranca'] ?? 'unica';
        $temIdAssinatura = !empty($_POST['tem_id_assinatura']);
    }

    if (empty($dataExpiracao)) {
        $resultadoPermanencia = ['erro' => 'Informe uma data de expiração válida.'];
    } else {
        $ehRecorrenteNativo = $temIdAssinatura;
        $diasCarencia = 0;
        if ($tipoCobranca === 'assinatura' || $ehRecorrenteNativo) {
            $diasCarencia = $ehRecorrenteNativo ? 2 : 5;
        }

        $tsExpiracao = strtotime($dataExpiracao);
        $tsLimite = strtotime("+{$diasCarencia} days", $tsExpiracao);
        $agora = time();
        $seriaRemovido = $agora >= $tsLimite;
        $diasRestantes = $seriaRemovido ? 0 : (int)ceil(($tsLimite - $agora) / 86400);

        $motivo = $tipoCobranca === 'unica' && !$ehRecorrenteNativo
            ? 'Cobrança única, sem tolerância — remove imediatamente após vencer.'
            : ($ehRecorrenteNativo
                ? 'Recorrência nativa (PIX Automático) — tolerância de 2 dias para o gateway renovar.'
                : 'Assinatura manual — tolerância de 5 dias para o usuário pagar o Pix de renovação.');

        $resultadoPermanencia = [
            'dataExpiracao' => $dataExpiracao,
            'tipoCobranca' => $tipoCobranca,
            'ehRecorrenteNativo' => $ehRecorrenteNativo,
            'diasCarencia' => $diasCarencia,
            'dataLimite' => date('d/m/Y H:i', $tsLimite),
            'seriaRemovido' => $seriaRemovido,
            'diasRestantes' => $diasRestantes,
            'motivo' => $motivo,
        ];
    }
}

// ── Dados para preencher os formulários ─────────────────────────────────────
$gatewaysConfigurados = [];
foreach ($GATEWAYS_SUPORTADOS as $g) {
    $cfg = getUserGatewayConfig($userId, $g);
    $gatewaysConfigurados[$g] = $cfg && !empty($cfg['client_id']);
}

$usuariosComSplitInfopago = $pdo->query("
    SELECT u.id, u.nome, s.chave_pix_split, s.taxa_split, s.tipo_split
    FROM usuarios_splits s JOIN usuarios u ON s.id_usuario = u.id
    WHERE s.gateway_nome = 'infopago'
    ORDER BY u.nome
")->fetchAll(PDO::FETCH_ASSOC);

$membrosAtivos = $pdo->query("
    SELECT m.id, m.id_telegram, m.data_expiracao, m.status, b.nome_usuario, v.tipo_cobranca, v.id_assinatura
    FROM membros_grupos m
    JOIN bots b ON m.bot_id = b.id
    LEFT JOIN vendas v ON m.venda_id = v.id
    WHERE m.status = 'ativo'
    ORDER BY m.data_expiracao ASC
    LIMIT 30
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Testes de Pagamento</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/app.css">
    <style>
        .tp-container { padding: 28px; max-width: 1100px; }
        .tp-header { margin-bottom: 24px; }
        .tp-header h1 { font-size: 1.4rem; margin: 0 0 4px; }
        .tp-header p { color: #64748b; margin: 0; font-size: .9rem; }
        .tp-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(420px, 1fr)); gap: 20px; }
        .tp-card { background: #fff; border: 1.5px solid #e2e8f0; border-radius: 14px; padding: 20px; }
        .tp-card h2 { font-size: 1rem; margin: 0 0 4px; display: flex; align-items: center; gap: 8px; }
        .tp-card p.desc { color: #64748b; font-size: .82rem; margin: 0 0 16px; }
        .tp-row { display: flex; gap: 10px; margin-bottom: 12px; flex-wrap: wrap; }
        .tp-field { flex: 1; min-width: 140px; }
        .tp-field label { display: block; font-size: .78rem; font-weight: 600; color: #475569; margin-bottom: 4px; }
        .tp-field select, .tp-field input[type=text], .tp-field input[type=number], .tp-field input[type=datetime-local] {
            width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 7px; font-size: .87rem; box-sizing: border-box;
        }
        .tp-btn { background: #2563eb; color: #fff; border: none; padding: 9px 16px; border-radius: 7px; font-weight: 600; cursor: pointer; font-size: .85rem; }
        .tp-btn:hover { background: #1d4ed8; }
        .tp-btn.secondary { background: #f1f5f9; color: #334155; }
        .tp-result { margin-top: 14px; padding: 12px 14px; border-radius: 8px; font-size: .85rem; }
        .tp-result.ok { background: #dcfce7; color: #166534; }
        .tp-result.fail { background: #fee2e2; color: #991b1b; }
        .tp-result.info { background: #f1f5f9; color: #334155; }
        .tp-badge { display: inline-flex; align-items: center; gap: 5px; font-size: .75rem; font-weight: 700; padding: 3px 9px; border-radius: 20px; }
        .tp-badge.ok { background: #dcfce7; color: #166534; }
        .tp-badge.off { background: #f1f5f9; color: #94a3b8; }
        .tp-status-list { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 14px; }
        .tp-qr { display: block; margin: 12px auto 6px; border-radius: 8px; }
        .tp-copia { font-size: .7rem; word-break: break-all; background: #f8fafc; padding: 8px; border-radius: 6px; margin-top: 6px; }
        .tp-checkbox-row { display: flex; align-items: center; gap: 8px; margin-bottom: 12px; font-size: .85rem; }
        .tp-warn { color: #b45309; font-size: .78rem; margin-top: 4px; }
    </style>
</head>
<body>
<div class="dashboard-layout">
    <?php include 'sidebar.php'; ?>
    <main class="main-content" style="padding:0;">
        <div class="tp-container">
            <div class="tp-header">
                <h1>Testes de Pagamento</h1>
                <p>Ferramenta de diagnóstico — testa autenticação, criação de cobrança, split e a lógica de permanência/expiração de acesso, sem afetar dados reais (exceto quando explicitamente marcado).</p>
            </div>

            <div class="tp-grid">

                <!-- ══════════ 1. Autenticação dos Gateways ══════════ -->
                <div class="tp-card">
                    <h2>🔐 Autenticação dos Gateways</h2>
                    <p class="desc">Testa se as credenciais salvas (da sua conta) autenticam de verdade na API de cada gateway.</p>
                    <div class="tp-status-list">
                        <?php foreach ($GATEWAYS_SUPORTADOS as $g): ?>
                            <span class="tp-badge <?php echo $gatewaysConfigurados[$g] ? 'ok' : 'off'; ?>">
                                <?php echo strtoupper($g); ?>: <?php echo $gatewaysConfigurados[$g] ? 'configurado' : 'sem credenciais'; ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="acao" value="testar_auth">
                        <div class="tp-row">
                            <div class="tp-field">
                                <label>Gateway</label>
                                <select name="gateway_nome">
                                    <?php foreach ($GATEWAYS_SUPORTADOS as $g): ?>
                                        <option value="<?php echo $g; ?>" <?php echo (($resultadoAuth['gateway'] ?? '') === $g) ? 'selected' : ''; ?>><?php echo strtoupper($g); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <button type="submit" class="tp-btn">Testar autenticação</button>
                    </form>
                    <?php if ($resultadoAuth): ?>
                        <div class="tp-result <?php echo $resultadoAuth['ok'] ? 'ok' : 'fail'; ?>">
                            <?php echo $resultadoAuth['ok'] ? '✔' : '✘'; ?> <?php echo htmlspecialchars($resultadoAuth['msg']); ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- ══════════ 2. Criar cobrança de teste ══════════ -->
                <div class="tp-card">
                    <h2>💸 Criar Cobrança de Teste</h2>
                    <p class="desc">Gera uma cobrança Pix de verdade (valor baixo) e mostra o QR code + copia-e-cola.</p>
                    <form method="POST">
                        <input type="hidden" name="acao" value="criar_cobranca">
                        <div class="tp-row">
                            <div class="tp-field">
                                <label>Gateway</label>
                                <select name="gateway_nome">
                                    <?php foreach ($GATEWAYS_SUPORTADOS as $g): ?>
                                        <option value="<?php echo $g; ?>"><?php echo strtoupper($g); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="tp-field">
                                <label>Valor (R$)</label>
                                <input type="number" step="0.01" min="0.01" name="valor" value="0.10">
                            </div>
                        </div>
                        <button type="submit" class="tp-btn">Criar cobrança</button>
                    </form>
                    <?php if ($resultadoCobranca): ?>
                        <?php if ($resultadoCobranca['ok']): ?>
                            <div class="tp-result ok">
                                ✔ Cobrança criada — txid: <?php echo htmlspecialchars($resultadoCobranca['txid']); ?> | status: <?php echo htmlspecialchars($resultadoCobranca['status']); ?>
                                <?php if ($resultadoCobranca['qrCodeUrl']): ?>
                                    <img class="tp-qr" src="<?php echo htmlspecialchars($resultadoCobranca['qrCodeUrl']); ?>" width="180" height="180" alt="QR">
                                    <div class="tp-copia"><?php echo htmlspecialchars($resultadoCobranca['pixCopiaCola']); ?></div>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="tp-result fail">✘ <?php echo htmlspecialchars($resultadoCobranca['msg']); ?></div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <!-- ══════════ 3. Testar Split (InfoPago) ══════════ -->
                <div class="tp-card">
                    <h2>🔀 Testar Split (InfoPago)</h2>
                    <p class="desc">Simula (ou executa de verdade) o repasse via Cash-Out para um usuário com split configurado.</p>
                    <?php if (empty($usuariosComSplitInfopago)): ?>
                        <div class="tp-result info">Nenhum usuário tem split configurado para InfoPago ainda. Configure em Usuários → editar → Splits de Pagamento.</div>
                    <?php else: ?>
                        <form method="POST">
                            <input type="hidden" name="acao" value="testar_split">
                            <div class="tp-row">
                                <div class="tp-field">
                                    <label>Usuário</label>
                                    <select name="id_usuario_split">
                                        <?php foreach ($usuariosComSplitInfopago as $u): ?>
                                            <option value="<?php echo (int)$u['id']; ?>">
                                                <?php echo htmlspecialchars($u['nome']); ?> (<?php echo $u['tipo_split'] === 'fixo' ? 'R$ ' . $u['taxa_split'] : $u['taxa_split'] . '%'; ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="tp-field">
                                    <label>Valor da venda simulada (R$)</label>
                                    <input type="number" step="0.01" min="0.01" name="valor_venda" value="50.00">
                                </div>
                            </div>
                            <div class="tp-checkbox-row">
                                <input type="checkbox" name="executar" id="executarSplit">
                                <label for="executarSplit">Executar transferência de verdade (dinheiro real!)</label>
                            </div>
                            <p class="tp-warn">⚠️ Deixe desmarcado para só simular o cálculo, sem mover dinheiro.</p>
                            <button type="submit" class="tp-btn">Testar split</button>
                        </form>
                    <?php endif; ?>
                    <?php if ($resultadoSplit): ?>
                        <?php if (isset($resultadoSplit['msg']) && !isset($resultadoSplit['destino'])): ?>
                            <div class="tp-result fail">✘ <?php echo htmlspecialchars($resultadoSplit['msg']); ?></div>
                        <?php else: ?>
                            <div class="tp-result <?php echo $resultadoSplit['ok'] ? 'ok' : 'fail'; ?>">
                                <?php echo $resultadoSplit['ok'] ? '✔' : '✘'; ?> <?php echo htmlspecialchars($resultadoSplit['msg']); ?><br>
                                Destino: <strong><?php echo htmlspecialchars($resultadoSplit['destino']); ?></strong><br>
                                Valor calculado: <strong>R$ <?php echo number_format($resultadoSplit['valorCalculado'], 2, ',', '.'); ?></strong><br>
                                Credenciais de Cash-Out do admin: <?php echo $resultadoSplit['temCredenciaisCashout'] ? 'configuradas' : 'NÃO configuradas'; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <!-- ══════════ 4. Permanência / Expiração de Acesso ══════════ -->
                <div class="tp-card">
                    <h2>🚪 Permanência / Expiração de Acesso</h2>
                    <p class="desc">Simula a decisão do cron de expiração (seria removido do grupo ou não), sem remover ninguém de verdade.</p>
                    <form method="POST">
                        <input type="hidden" name="acao" value="testar_permanencia">
                        <div class="tp-row">
                            <div class="tp-field" style="flex-basis:100%;">
                                <label>Escolher membro real (opcional)</label>
                                <select name="membro_id" onchange="this.form.querySelectorAll('[data-manual]').forEach(el => el.disabled = this.value !== '')">
                                    <option value="">— Preencher manualmente abaixo —</option>
                                    <?php foreach ($membrosAtivos as $m): ?>
                                        <option value="<?php echo (int)$m['id']; ?>">
                                            <?php echo htmlspecialchars($m['id_telegram']); ?> (@<?php echo htmlspecialchars($m['nome_usuario']); ?>) — expira <?php echo htmlspecialchars($m['data_expiracao']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="tp-row">
                            <div class="tp-field">
                                <label>Data de expiração (manual)</label>
                                <input type="datetime-local" name="data_expiracao" data-manual>
                            </div>
                            <div class="tp-field">
                                <label>Tipo de cobrança</label>
                                <select name="tipo_cobranca" data-manual>
                                    <option value="unica">Única</option>
                                    <option value="assinatura">Assinatura (manual)</option>
                                </select>
                            </div>
                        </div>
                        <div class="tp-checkbox-row">
                            <input type="checkbox" name="tem_id_assinatura" id="temIdAssinatura" data-manual>
                            <label for="temIdAssinatura">É recorrência nativa (tem id_assinatura — PIX Automático)</label>
                        </div>
                        <button type="submit" class="tp-btn">Simular decisão</button>
                    </form>
                    <?php if ($resultadoPermanencia): ?>
                        <?php if (isset($resultadoPermanencia['erro'])): ?>
                            <div class="tp-result fail">✘ <?php echo htmlspecialchars($resultadoPermanencia['erro']); ?></div>
                        <?php else: ?>
                            <div class="tp-result <?php echo $resultadoPermanencia['seriaRemovido'] ? 'fail' : 'ok'; ?>">
                                <?php echo $resultadoPermanencia['seriaRemovido'] ? '🚫 Seria REMOVIDO agora' : '✅ Seria MANTIDO'; ?><br>
                                <?php echo htmlspecialchars($resultadoPermanencia['motivo']); ?><br>
                                Tolerância: <?php echo $resultadoPermanencia['diasCarencia']; ?> dia(s) — limite em <?php echo htmlspecialchars($resultadoPermanencia['dataLimite']); ?><br>
                                <?php if (!$resultadoPermanencia['seriaRemovido']): ?>
                                    Dias restantes de tolerância: <strong><?php echo $resultadoPermanencia['diasRestantes']; ?></strong>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </main>
</div>
</body>
</html>
