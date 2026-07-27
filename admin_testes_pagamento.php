<?php
declare(strict_types=1);

require_once __DIR__ . '/funcoes/usuario.php';
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/funcoes/gateways.php';
require_once __DIR__ . '/funcoes/infopago_cashout.php';

verificarAdmin();
$user_id = (int)$_SESSION['usuario_id'];

$GATEWAYS_SUPORTADOS = ['infopago'];

$resultado_auth = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'testar_auth') {
    $nome = $_POST['gateway_nome'] ?? '';
    $cfg = getUserGatewayConfig($user_id, $nome);
    if (!$cfg || empty($cfg['client_id'])) {
        $resultado_auth = ['gateway' => $nome, 'ok' => false, 'msg' => 'Gateway não configurado.'];
    } else {
        try {
            $provedor = resolveGatewayProvider($nome, $cfg);
            $ok = $provedor && $provedor->validarCredenciais();
            $resultado_auth = ['gateway' => $nome, 'ok' => $ok, 'msg' => $ok ? 'Autenticado com sucesso.' : 'Falha na autenticação — confira credenciais e certificado.'];
        } catch (Exception $e) {
            $resultado_auth = ['gateway' => $nome, 'ok' => false, 'msg' => 'Exceção: ' . $e->getMessage()];
        }
    }
}

$resultado_cobranca = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'criar_cobranca') {
    $nome = $_POST['gateway_nome'] ?? '';
    $valor = (float)($_POST['valor'] ?? 0.10);
    $cfg = getUserGatewayConfig($user_id, $nome);

    if (!$cfg || empty($cfg['client_id']) || empty($cfg['chave_pix'])) {
        $resultado_cobranca = ['ok' => false, 'msg' => 'Gateway não configurado (falta client_id ou chave Pix).'];
    } else {
        try {
            $provedor = resolveGatewayProvider($nome, $cfg);
            $payload = $provedor->montaPayloadCobranca($valor, $cfg['chave_pix'], null, 3600);
            $resp = $provedor->criarCobranca($payload);

            if ($resp['sucesso'] ?? false) {
                $txid = $resp['dados']['txid'] ?? ($resp['dados']['id'] ?? '');
                $pix_copia_cola = $resp['dados']['pixCopiaECola'] ?? ($resp['dados']['qr_code'] ?? '');
                $resultado_cobranca = [
                    'ok' => true,
                    'gateway' => $nome,
                    'txid' => $txid,
                    'status' => $resp['dados']['status'] ?? '?',
                    'pixCopiaCola' => $pix_copia_cola,
                    'qrCodeUrl' => $pix_copia_cola ? 'https://api.qrserver.com/v1/create-qr-code/?size=240x240&data=' . urlencode($pix_copia_cola) : null,
                ];
            } else {
                $resultado_cobranca = ['ok' => false, 'msg' => 'Falha ao criar cobrança: ' . ($resp['erro'] ?? 'desconhecido')];
            }
        } catch (Exception $e) {
            $resultado_cobranca = ['ok' => false, 'msg' => 'Exceção: ' . $e->getMessage()];
        }
    }
}

$resultado_split = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'testar_split') {
    $id_usuario_alvo = (int)($_POST['id_usuario_split'] ?? 0);
    $valor_venda = (float)($_POST['valor_venda'] ?? 0);
    $executar_de_verdade = isset($_POST['executar']);

    $stmt = $pdo->prepare("SELECT chave_pix_split, taxa_split, tipo_split FROM usuarios_splits WHERE id_usuario = ? AND gateway_nome = 'infopago' LIMIT 1");
    $stmt->execute([$id_usuario_alvo]);
    $split = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$split) {
        $resultado_split = ['ok' => false, 'msg' => 'Esse usuário não tem split configurado para InfoPago (configure em Usuários → editar → Splits).'];
    } else {
        $valor_split = ($split['tipo_split'] === 'fixo') ? (float)$split['taxa_split'] : round($valor_venda * ((float)$split['taxa_split'] / 100), 2);

        $stmt_cred = $pdo->prepare("
            SELECT ug.cashout_client_id, ug.cashout_client_secret, ug.cashout_certificado
            FROM usuarios_gateways ug JOIN usuarios u ON ug.id_usuario = u.id JOIN gateways g ON ug.id_gateway = g.id
            WHERE g.nome = 'infopago' AND u.perfil = 'admin' ORDER BY ug.atualizado_em DESC LIMIT 1
        ");
        $stmt_cred->execute();
        $cred = $stmt_cred->fetch(PDO::FETCH_ASSOC);

        $resultado_split = [
            'destino' => $split['chave_pix_split'],
            'tipo' => $split['tipo_split'],
            'taxa' => $split['taxa_split'],
            'valorCalculado' => $valor_split,
            'temCredenciaisCashout' => !empty($cred['cashout_client_id']) && !empty($cred['cashout_certificado']),
            'executado' => false,
        ];

        if ($executar_de_verdade) {
            if (!$resultado_split['temCredenciaisCashout']) {
                $resultado_split['ok'] = false;
                $resultado_split['msg'] = 'Não é possível executar: credenciais de Cash-Out do admin não configuradas.';
            } else {
                $cashout = new InfopagoCashout($cred['cashout_client_id'], $cred['cashout_client_secret'], $cred['cashout_certificado']);
                $resp = $cashout->transferirPorChavePix($split['chave_pix_split'], $valor_split, 'Teste de split (painel admin)');
                $resultado_split['executado'] = true;
                $resultado_split['ok'] = $resp['sucesso'] ?? false;
                $resultado_split['msg'] = ($resp['sucesso'] ?? false) ? 'Transferência real enviada com sucesso!' : 'Falha na transferência: ' . ($resp['erro'] ?? 'desconhecido');
            }
        } else {
            $resultado_split['ok'] = true;
            $resultado_split['msg'] = 'Simulação (dry-run) — nenhuma transferência real foi enviada.';
        }
    }
}

$resultado_permanencia = null;
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
        $data_expiracao = $membro['data_expiracao'] ?? null;
        $tipo_cobranca = $membro['tipo_cobranca'] ?? 'unica';
        $tem_id_assinatura = !empty($membro['id_assinatura']);
    } else {
        $data_expiracao = $_POST['data_expiracao'] ?? null;
        $tipo_cobranca = $_POST['tipo_cobranca'] ?? 'unica';
        $tem_id_assinatura = !empty($_POST['tem_id_assinatura']);
    }

    if (empty($data_expiracao)) {
        $resultado_permanencia = ['erro' => 'Informe uma data de expiração válida.'];
    } else {
        $eh_recorrente_nativo = $tem_id_assinatura;
        $dias_carencia = 0;
        if ($tipo_cobranca === 'assinatura' || $eh_recorrente_nativo) {
            $dias_carencia = $eh_recorrente_nativo ? 2 : 5;
        }

        $ts_expiracao = strtotime($data_expiracao);
        $ts_limite = strtotime("+{$dias_carencia} days", $ts_expiracao);
        $agora = time();
        $seria_removido = $agora >= $ts_limite;
        $dias_restantes = $seria_removido ? 0 : (int)ceil(($ts_limite - $agora) / 86400);

        $motivo = $tipo_cobranca === 'unica' && !$eh_recorrente_nativo
            ? 'Cobrança única, sem tolerância — remove imediatamente após vencer.'
            : ($eh_recorrente_nativo
                ? 'Recorrência nativa (PIX Automático) — tolerância de 2 dias para o gateway renovar.'
                : 'Assinatura manual — tolerância de 5 dias para o usuário pagar o Pix de renovação.');

        $resultado_permanencia = [
            'dataExpiracao' => $data_expiracao,
            'tipoCobranca' => $tipo_cobranca,
            'ehRecorrenteNativo' => $eh_recorrente_nativo,
            'diasCarencia' => $dias_carencia,
            'dataLimite' => date('d/m/Y H:i', $ts_limite),
            'seriaRemovido' => $seria_removido,
            'diasRestantes' => $dias_restantes,
            'motivo' => $motivo,
        ];
    }
}

$gateways_configurados = [];
foreach ($GATEWAYS_SUPORTADOS as $g) {
    $cfg = getUserGatewayConfig($user_id, $g);
    $gateways_configurados[$g] = $cfg && !empty($cfg['client_id']);
}

$usuarios_com_split_infopago = $pdo->query("
    SELECT u.id, u.nome, s.chave_pix_split, s.taxa_split, s.tipo_split
    FROM usuarios_splits s JOIN usuarios u ON s.id_usuario = u.id
    WHERE s.gateway_nome = 'infopago'
    ORDER BY u.nome
")->fetchAll(PDO::FETCH_ASSOC);

$membros_ativos = $pdo->query("
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

                <div class="tp-card">
                    <h2>🔐 Autenticação dos Gateways</h2>
                    <p class="desc">Testa se as credenciais salvas (da sua conta) autenticam de verdade na API de cada gateway.</p>
                    <div class="tp-status-list">
                        <?php foreach ($GATEWAYS_SUPORTADOS as $g): ?>
                            <span class="tp-badge <?php echo $gateways_configurados[$g] ? 'ok' : 'off'; ?>">
                                <?php echo strtoupper($g); ?>: <?php echo $gateways_configurados[$g] ? 'configurado' : 'sem credenciais'; ?>
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
                                        <option value="<?php echo $g; ?>" <?php echo (($resultado_auth['gateway'] ?? '') === $g) ? 'selected' : ''; ?>><?php echo strtoupper($g); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <button type="submit" class="tp-btn">Testar autenticação</button>
                    </form>
                    <?php if ($resultado_auth): ?>
                        <div class="tp-result <?php echo $resultado_auth['ok'] ? 'ok' : 'fail'; ?>">
                            <?php echo $resultado_auth['ok'] ? '✔' : '✘'; ?> <?php echo htmlspecialchars($resultado_auth['msg']); ?>
                        </div>
                    <?php endif; ?>
                </div>

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
                    <?php if ($resultado_cobranca): ?>
                        <?php if ($resultado_cobranca['ok']): ?>
                            <div class="tp-result ok">
                                ✔ Cobrança criada — txid: <?php echo htmlspecialchars($resultado_cobranca['txid']); ?> | status: <?php echo htmlspecialchars($resultado_cobranca['status']); ?>
                                <?php if ($resultado_cobranca['qrCodeUrl']): ?>
                                    <img class="tp-qr" src="<?php echo htmlspecialchars($resultado_cobranca['qrCodeUrl']); ?>" width="180" height="180" alt="QR">
                                    <div class="tp-copia"><?php echo htmlspecialchars($resultado_cobranca['pixCopiaCola']); ?></div>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="tp-result fail">✘ <?php echo htmlspecialchars($resultado_cobranca['msg']); ?></div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <div class="tp-card">
                    <h2>🔀 Testar Split (InfoPago)</h2>
                    <p class="desc">Simula (ou executa de verdade) o repasse via Cash-Out para um usuário com split configurado.</p>
                    <?php if (empty($usuarios_com_split_infopago)): ?>
                        <div class="tp-result info">Nenhum usuário tem split configurado para InfoPago ainda. Configure em Usuários → editar → Splits de Pagamento.</div>
                    <?php else: ?>
                        <form method="POST">
                            <input type="hidden" name="acao" value="testar_split">
                            <div class="tp-row">
                                <div class="tp-field">
                                    <label>Usuário</label>
                                    <select name="id_usuario_split">
                                        <?php foreach ($usuarios_com_split_infopago as $u): ?>
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
                    <?php if ($resultado_split): ?>
                        <?php if (isset($resultado_split['msg']) && !isset($resultado_split['destino'])): ?>
                            <div class="tp-result fail">✘ <?php echo htmlspecialchars($resultado_split['msg']); ?></div>
                        <?php else: ?>
                            <div class="tp-result <?php echo $resultado_split['ok'] ? 'ok' : 'fail'; ?>">
                                <?php echo $resultado_split['ok'] ? '✔' : '✘'; ?> <?php echo htmlspecialchars($resultado_split['msg']); ?><br>
                                Destino: <strong><?php echo htmlspecialchars($resultado_split['destino']); ?></strong><br>
                                Valor calculado: <strong>R$ <?php echo number_format($resultado_split['valorCalculado'], 2, ',', '.'); ?></strong><br>
                                Credenciais de Cash-Out do admin: <?php echo $resultado_split['temCredenciaisCashout'] ? 'configuradas' : 'NÃO configuradas'; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

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
                                    <?php foreach ($membros_ativos as $m): ?>
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
                    <?php if ($resultado_permanencia): ?>
                        <?php if (isset($resultado_permanencia['erro'])): ?>
                            <div class="tp-result fail">✘ <?php echo htmlspecialchars($resultado_permanencia['erro']); ?></div>
                        <?php else: ?>
                            <div class="tp-result <?php echo $resultado_permanencia['seriaRemovido'] ? 'fail' : 'ok'; ?>">
                                <?php echo $resultado_permanencia['seriaRemovido'] ? '🚫 Seria REMOVIDO agora' : '✅ Seria MANTIDO'; ?><br>
                                <?php echo htmlspecialchars($resultado_permanencia['motivo']); ?><br>
                                Tolerância: <?php echo $resultado_permanencia['diasCarencia']; ?> dia(s) — limite em <?php echo htmlspecialchars($resultado_permanencia['dataLimite']); ?><br>
                                <?php if (!$resultado_permanencia['seriaRemovido']): ?>
                                    Dias restantes de tolerância: <strong><?php echo $resultado_permanencia['diasRestantes']; ?></strong>
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
