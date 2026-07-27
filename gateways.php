<?php
declare(strict_types=1);

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/funcoes/usuario.php';
require_once __DIR__ . '/funcoes/gateways.php';
require_once __DIR__ . '/funcoes/paginador.php';

verificarLogin();

$is_admin = ehAdmin();
$user_id = $_SESSION['usuario_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'reordenar_gateways') {
    header('Content-Type: application/json');
    $ordem = $_POST['ordem'] ?? [];
    if (!is_array($ordem)) { echo json_encode(['sucesso' => false]); exit; }
    try {
        foreach ($ordem as $prioridade => $gw_id) {
            $pdo->prepare("UPDATE usuarios_gateways SET prioridade = ? WHERE id_gateway = ? AND id_usuario = ?")
                ->execute([(int)$prioridade + 1, (int)$gw_id, $user_id]);
        }
        echo json_encode(['sucesso' => true]);
    } catch (Exception $e) {
        echo json_encode(['sucesso' => false]);
    }
    exit;
}

$pagina_atual = isset($_GET['pagina']) ? max(1, (int)$_GET['pagina']) : 1;
$por_pagina = 10;
$offset = ($pagina_atual - 1) * $por_pagina;

$mensagem = '';
$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($is_admin && isset($_POST['acao']) && $_POST['acao'] === 'salvar_admin') {
        $salvos = 0;
        $total = 0;

        if (!empty($_POST['ids']) && is_array($_POST['ids'])) {
            $ids = $_POST['ids'];
            foreach ($ids as $id) {
                $gateway_id = (int)$id;
                $ativo = isset($_POST['ativo'][$gateway_id]) && $_POST['ativo'][$gateway_id] == '1';

                if (saveAdminGatewayConfig($gateway_id, $ativo)) {
                    $salvos++;
                }
                $total++;
            }

            if ($salvos === $total) {
                $mensagem = 'Configurações de todos os gateways foram salvas com sucesso!';
            } elseif ($salvos > 0) {
                $mensagem = "Salvas $salvos de $total gateways. Alguns não foram atualizados.";
            } else {
                $erro = 'Erro ao salvar configurações dos gateways.';
            }
        } else {
            $erro = 'Nenhum gateway encontrado para salvar.';
        }
    } elseif (isset($_POST['acao']) && $_POST['acao'] === 'desativar_user') {
        $gateway_id = (int)$_POST['gateway_id'];
        $stmt = $pdo->prepare("UPDATE usuarios_gateways SET ativo = 0 WHERE id_usuario = ? AND id_gateway = ?");
        if ($stmt->execute([$user_id, $gateway_id])) {
            $_SESSION['gw_mensagem'] = 'Gateway desativado.';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        } else {
            $erro = 'Erro ao desativar o gateway.';
        }
    } elseif (isset($_POST['acao']) && $_POST['acao'] === 'ativar_user') {
        $gateway_id = (int)$_POST['gateway_id'];
        $stmt = $pdo->prepare("UPDATE usuarios_gateways SET ativo = 1 WHERE id_usuario = ? AND id_gateway = ?");
        if ($stmt->execute([$user_id, $gateway_id])) {
            $_SESSION['gw_mensagem'] = 'Gateway ativado!';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        } else {
            $erro = 'Erro ao ativar o gateway.';
        }
    } elseif (isset($_POST['acao']) && $_POST['acao'] === 'salvar_user') {
        $gateway_id = (int)$_POST['gateway_id'];
        $client_id = $_POST['client_id'];
        $client_secret = $_POST['client_secret'];
        $chave_pix = $_POST['chave_pix'] ?? '';
        $ativo = isset($_POST['ativo']);
        $prioridade = isset($_POST['prioridade']) ? max(1, min(999, (int)$_POST['prioridade'])) : 100;
        $tipo_conta = in_array($_POST['tipo_conta'] ?? '', ['pf', 'pj']) ? $_POST['tipo_conta'] : 'pj';
        $stmt = $pdo->prepare("SELECT nome FROM gateways WHERE id = ?");
        $stmt->execute([$gateway_id]);
        $gateway_nome = $stmt->fetchColumn();
        $current_config = getUserGatewayConfig($user_id, $gateway_nome);

        if (isset($_FILES['certificado']) && $_FILES['certificado']['error'] === UPLOAD_ERR_OK) {
            $ext = pathinfo($_FILES['certificado']['name'], PATHINFO_EXTENSION);
            if (!in_array(strtolower($ext), ['pem', 'p12', 'pfx'])) {
                $erro = 'Apenas arquivos .pem, .p12 ou .pfx são permitidos.';
            } else {
                $nome_arquivo = "cert_{$user_id}_{$gateway_id}.{$ext}";
                $caminho_dir = __DIR__ . '/certificados';
                if (!is_dir($caminho_dir)) mkdir($caminho_dir, 0755, true);
                $caminho_destino = $caminho_dir . '/' . $nome_arquivo;

                if (in_array(strtolower($ext), ['p12', 'pfx'], true)) {
                    // .p12 e .pfx são o mesmo formato (PKCS#12) — salva o binário original, sem conversão
                    if (move_uploaded_file($_FILES['certificado']['tmp_name'], $caminho_destino)) {
                        $certificado_path = $caminho_destino;
                    } else {
                        $erro = 'Erro ao salvar o arquivo do certificado.';
                    }
                } else {
                    $conteudo_cert = file_get_contents($_FILES['certificado']['tmp_name']);

                    $padrao = '/(-{5}BEGIN [A-Z ]+-{5})(.*?)(-{5}END [A-Z ]+-{5})/Vs';
                    preg_match_all($padrao, $conteudo_cert, $matches);
                    
                    $novo_conteudo = '';
                    if (!empty($matches[0])) {
                        foreach ($matches[0] as $bloco) {
                            $novo_conteudo .= trim($bloco) . "\n";
                        }
                    } else {
                        $novo_conteudo = $conteudo_cert;
                    }
                    
                    if (file_put_contents($caminho_destino, trim($novo_conteudo))) {
                        $certificado_path = $caminho_destino;
                    } else {
                        $erro = 'Erro ao salvar o arquivo do certificado.';
                    }
                }
            }
        } else {
            $certificado_path = $current_config['certificado'] ?? '';
        }
        
        if (!$erro) {
            $cert_password = trim($_POST['cert_password'] ?? '');
            if ($cert_password === '') {
                $cert_password = $current_config['cert_password'] ?? '';
            }
            if (saveUserGatewayConfig($user_id, $gateway_id, $client_id, $client_secret, $certificado_path, $cert_password, $chave_pix, $ativo, $prioridade, $tipo_conta)) {
                $_SESSION['gw_mensagem'] = 'Suas credenciais foram salvas!';
                $mensagem = 'Suas credenciais foram salvas!';
            } else {
                $erro = 'Erro ao salvar suas credenciais.';
            }
        }
    } elseif (isset($_POST['acao']) && $_POST['acao'] === 'salvar_infopago_split') {
        // Credenciais de Cash-Out (API de Contas, separada da de cobrança) — usadas para simular
        // split via transferência manual. O destino/percentual do split é configurado pelo admin
        // em usuarios.php. Ver docs/infopago/01-api-referencia.md §5.
        $gateway_id = (int)$_POST['gateway_id'];
        $cashout_client_id = trim($_POST['cashout_client_id'] ?? '');
        $cashout_client_secret = trim($_POST['cashout_client_secret'] ?? '');
        $cashout_cert_password = trim($_POST['cashout_cert_password'] ?? '');

        $cashout_certificado_path = null;
        if (isset($_FILES['cashout_certificado']) && $_FILES['cashout_certificado']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['cashout_certificado']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['pem', 'p12', 'pfx'], true)) {
                $erro = 'Certificado de Cash-Out: apenas arquivos .pem, .p12 ou .pfx são permitidos.';
            } else {
                $caminho_dir = __DIR__ . '/certificados';
                if (!is_dir($caminho_dir)) mkdir($caminho_dir, 0755, true);
                $cashout_certificado_path = $caminho_dir . "/cashout_cert_{$user_id}_{$gateway_id}.{$ext}";
                if (!move_uploaded_file($_FILES['cashout_certificado']['tmp_name'], $cashout_certificado_path)) {
                    $erro = 'Erro ao salvar o certificado de Cash-Out.';
                    $cashout_certificado_path = null;
                }
            }
        }

        if (!$erro) {
            if (saveInfopagoCashoutConfig($user_id, $gateway_id, $cashout_client_id, $cashout_client_secret, $cashout_certificado_path, $cashout_cert_password)) {
                $_SESSION['gw_mensagem'] = 'Credenciais de Cash-Out salvas!';
                $mensagem = 'Credenciais de Cash-Out salvas!';
            } else {
                $erro = 'Erro ao salvar as credenciais de Cash-Out.';
            }
        }
    }
    // PRG: redireciona para evitar reenvio do POST ao recarregar
    if (!$erro) {
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

if (!isset($mensagem) && !empty($_SESSION['gw_mensagem'])) {
    $mensagem = $_SESSION['gw_mensagem'];
    unset($_SESSION['gw_mensagem']);
}

if ($is_admin) {
    $total_gateways = contarGatewaysAdmin();
    $gateways = listarGatewaysAdmin($por_pagina, $offset);
}
// O admin também é dono de bots e precisa configurar suas próprias credenciais de gateway,
// então a listagem "de usuário" é sempre carregada (para a própria conta do admin), além do
// painel de toggle admin-only acima.
$total_gateways_usuario = contarGatewaysUsuario();
$gateways_usuario = listarGatewaysUsuario($user_id, $por_pagina, $offset);

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gateways de Pagamento</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/app.css">
    <style>
        .alert { padding: 14px 18px; border-radius: 10px; margin-bottom: 22px; font-weight: 500; font-size: 0.9rem; }
        .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .alert-error   { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        small { color: #64748b; display: block; margin-top: 5px; font-size: 0.82rem; }

        .form-group  { margin-bottom: 18px; }
        .form-label  { display: block; margin-bottom: 7px; font-weight: 500; color: #475569; font-size: 0.875rem; }
        .form-input  {
            width: 100%; padding: 10px 13px;
            border: 1px solid #cbd5e1; border-radius: 8px;
            font-size: 0.925rem; transition: border-color .2s, box-shadow .2s;
            box-sizing: border-box;
        }
        .form-input:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,.1); }
        select.form-input { appearance: auto; }

        .btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 8px; border: none; cursor: pointer; font-size: 0.85rem; font-weight: 500; transition: all .18s; text-decoration: none; }
        .btn-primary   { background: #2563eb; color: #fff; }
        .btn-primary:hover { background: #1d4ed8; }
        .btn-ghost     { background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; }
        .btn-ghost:hover { background: #e2e8f0; }
        .btn-danger    { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .btn-danger:hover { background: #fecaca; }
        .btn-save      { background: #2563eb; color: #fff; width: 100%; padding: 12px; border-radius: 8px; border: none; cursor: pointer; font-weight: 600; font-size: 0.95rem; transition: background .2s; margin-top: 4px; }
        .btn-save:hover { background: #1d4ed8; }
        .btn-sm { padding: 6px 12px; font-size: 0.8rem; }

        .card-gateway {
            background: #fff; border: 1px solid #e2e8f0; border-radius: 12px;
            padding: 24px; margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,.05);
        }
        .gateway-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid #f1f5f9; }
        .gateway-title  { font-size: 1.15rem; font-weight: 600; color: #1e293b; display: flex; align-items: center; gap: 10px; }
        .badge { padding: 3px 9px; border-radius: 20px; font-size: 0.72rem; font-weight: 600; letter-spacing: .02em; text-transform: uppercase; }
        .badge-ativo   { background: #dcfce7; color: #166534; }
        .badge-inativo { background: #f1f5f9; color: #64748b; }
        .btn-salvar { background: #2563eb; color: #fff; border: none; padding: 12px 24px; border-radius: 6px; cursor: pointer; font-weight: 500; font-size: .95rem; transition: background .2s; width: 100%; }
        .btn-salvar:hover { background: #1d4ed8; }

        .gw-section-title {
            display: flex; align-items: center; gap: 10px;
            font-size: 1rem; font-weight: 700; color: #1e293b;
            margin: 0 0 16px;
        }
        .gw-section-title .gw-count {
            background: #2563eb; color: #fff;
            font-size: 0.78rem; font-weight: 700;
            padding: 2px 8px; border-radius: 20px;
        }
        .gw-section { margin-bottom: 36px; }

        .gw-active-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 16px;
            align-items: start;
        }
        .gw-active-card {
            background: #fff; border: 1.5px solid #e2e8f0; border-radius: 14px;
            padding: 0; overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,.05);
            transition: box-shadow .2s, border-color .2s;
            position: relative;
        }
        .gw-active-card:hover { box-shadow: 0 6px 20px rgba(0,0,0,.08); border-color: #bfdbfe; }
        .gw-active-card.is-primary { border-color: #2563eb; }

        .gw-card-top {
            padding: 16px 18px 14px;
            display: flex; align-items: flex-start; justify-content: space-between;
            gap: 12px;
        }
        .gw-priority-badge {
            display: inline-flex; align-items: center; gap: 5px;
            background: #eff6ff; color: #1d4ed8;
            font-size: 0.72rem; font-weight: 700;
            padding: 3px 9px; border-radius: 20px;
        }
        .gw-priority-badge.main { background: #2563eb; color: #fff; }

        .gw-method-badge {
            display: inline-flex; align-items: center; gap: 4px;
            background: #f0fdf4; color: #15803d;
            font-size: 0.7rem; font-weight: 700;
            padding: 2px 8px; border-radius: 12px; border: 1px solid #bbf7d0;
        }

        .gw-card-body {
            padding: 0 18px 14px;
            display: flex; flex-direction: column; align-items: center; text-align: center;
        }
        .gw-icon {
            width: 56px; height: 56px; border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.5rem; font-weight: 800; color: #fff;
            margin-bottom: 10px;
        }
        .gw-icon-infopago  { background: linear-gradient(135deg, #0ea5e9, #0369a1); }
        .gw-icon-default   { background: linear-gradient(135deg, #64748b, #475569); }

        .gw-card-name { font-size: 0.95rem; font-weight: 700; color: #1e293b; margin-bottom: 4px; }
        .gw-card-status {
            display: inline-flex; align-items: center; gap: 5px;
            font-size: 0.8rem; font-weight: 500; color: #64748b;
        }
        .gw-card-status .dot { width: 7px; height: 7px; border-radius: 50%; }
        .gw-card-status.ok .dot   { background: #22c55e; }
        .gw-card-status.warn .dot { background: #f59e0b; }

        .gw-card-actions {
            padding: 12px 18px;
            border-top: 1px solid #f1f5f9;
            display: flex; gap: 8px; flex-wrap: wrap;
        }

        .gw-available-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 14px;
        }
        .gw-avail-card {
            background: #fff; border: 1.5px dashed #e2e8f0; border-radius: 14px;
            padding: 22px 16px 14px; text-align: center;
            transition: all .2s; position: relative;
            display: flex; flex-direction: column; align-items: center;
        }
        .gw-avail-card:hover { border-color: #93c5fd; background: #eff6ff; }
        .gw-avail-plus {
            position: absolute; top: 10px; right: 10px;
            width: 24px; height: 24px; border-radius: 50%;
            background: #eff6ff; color: #2563eb;
            display: flex; align-items: center; justify-content: center;
            font-size: 1rem; font-weight: 700; line-height: 1;
        }
        .gw-avail-card:hover .gw-avail-plus { background: #2563eb; color: #fff; }
        .gw-avail-card .gw-icon { margin: 0 auto 10px; }
        .gw-avail-name { font-size: 0.88rem; font-weight: 600; color: #1e293b; margin-bottom: 6px; }
        .gw-avail-actions { display: flex; gap: 6px; margin-top: 10px; justify-content: center; flex-wrap: wrap; }

        .gw-active-card[draggable] { cursor: grab; }
        .gw-active-card[draggable]:active { cursor: grabbing; }
        .gw-active-card.drag-over { opacity: .4; border-style: dashed; }
        .gw-active-card.dragging { opacity: .25; }

        .gw-modal-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(15,23,42,.45); z-index: 9999;
            align-items: center; justify-content: center;
        }
        .gw-modal-overlay.open { display: flex; }
        .gw-modal {
            background: #fff; border-radius: 16px;
            width: 100%; max-width: 500px; max-height: 90vh;
            overflow-y: auto; box-shadow: 0 25px 60px rgba(0,0,0,.18);
            margin: 16px;
        }
        .gw-modal-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 20px 24px 16px; border-bottom: 1px solid #f1f5f9;
        }
        .gw-modal-title { font-size: 1.05rem; font-weight: 700; color: #1e293b; display: flex; align-items: center; gap: 10px; }
        .gw-modal-close { background: #f1f5f9; border: none; width: 32px; height: 32px; border-radius: 8px; cursor: pointer; font-size: 1.1rem; color: #64748b; display: flex; align-items: center; justify-content: center; }
        .gw-modal-close:hover { background: #e2e8f0; }
        .gw-modal-body { padding: 20px 24px 24px; }

        .gw-empty {
            text-align: center; padding: 48px 20px; background: #fff;
            border-radius: 14px; border: 1px dashed #cbd5e1;
        }
        .gw-empty-icon { width: 64px; height: 64px; background: #f8fafc; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px; color: #94a3b8; }
        .gw-empty h3 { font-size: 1.1rem; font-weight: 600; color: #1e293b; margin-bottom: 8px; }
        .gw-empty p  { color: #64748b; font-size: 0.9rem; max-width: 360px; margin: 0 auto; }

        .gw-filters { display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap; }
        .gw-filter-btn {
            padding: 6px 14px; border-radius: 20px; border: 1.5px solid #e2e8f0;
            background: #fff; color: #64748b; font-size: 0.82rem; font-weight: 600; cursor: pointer;
            transition: all .15s;
        }
        .gw-filter-btn.active { background: #2563eb; color: #fff; border-color: #2563eb; }

        .gw-toggle-wrap { display: flex; align-items: center; gap: 10px; margin-bottom: 18px; cursor: pointer; }
        .gw-toggle-wrap input[type=checkbox] { width: 18px; height: 18px; accent-color: #2563eb; cursor: pointer; }
        .gw-toggle-label { font-size: 0.9rem; font-weight: 500; color: #334155; }
    </style>
</head>
<body>
<div class="dashboard-layout">
    <?php include 'sidebar.php'; ?>
    
    <main class="main-content">
        <div class="cabecalho-pagina">
            <div>
                <h1>Gateways de Pagamento</h1>
                <p>Gerencie as integrações de pagamento.</p>
            </div>
        </div>

        <div class="painel">
            <?php if ($mensagem): ?>
                <div class="alert alert-success"><?php echo $mensagem; ?></div>
            <?php endif; ?>
            <?php if ($erro): ?>
                <div class="alert alert-error"><?php echo $erro; ?></div>
            <?php endif; ?>

            <?php if ($is_admin): ?>
                <style>
                    .adm-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 18px; margin-bottom: 24px; }

                    .adm-card {
                        background: #fff; border: 1.5px solid #e2e8f0; border-radius: 16px;
                        overflow: hidden; transition: box-shadow .2s, border-color .2s;
                    }
                    .adm-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,.07); }
                    .adm-card.is-active { border-color: #93c5fd; }

                    .adm-card-top {
                        background: #f8fafc; padding: 16px 18px;
                        border-bottom: 1px solid #f1f5f9;
                        display: flex; align-items: center; justify-content: space-between; gap: 12px;
                    }
                    .adm-card-identity { display: flex; align-items: center; gap: 11px; min-width: 0; }
                    .adm-gw-icon {
                        width: 38px; height: 38px; border-radius: 10px; flex-shrink: 0;
                        display: flex; align-items: center; justify-content: center;
                        font-size: 1rem; font-weight: 800;
                    }
                    .adm-icon-infopago  { background: #e0f2fe; color: #0369a1; }
                    .adm-icon-default   { background: #f1f5f9; color: #475569; }
                    .adm-card-name { font-size: .9rem; font-weight: 700; color: #1e293b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
                    .adm-card-sub  { font-size: .72rem; color: #94a3b8; margin-top: 1px; }

                    .adm-switch { position: relative; display: inline-block; width: 40px; height: 22px; flex-shrink: 0; }
                    .adm-switch input { opacity: 0; width: 0; height: 0; }
                    .adm-slider { position: absolute; cursor: pointer; inset: 0; background: #cbd5e1; border-radius: 22px; transition: .22s; }
                    .adm-slider::before { content:''; position:absolute; width:16px; height:16px; border-radius:50%; background:#fff; left:3px; bottom:3px; transition:.22s; box-shadow:0 1px 3px rgba(0,0,0,.2); }
                    .adm-switch input:checked + .adm-slider { background: #2563eb; }
                    .adm-switch input:checked + .adm-slider::before { transform: translateX(18px); }

                    .adm-card-body { padding: 14px 18px; }

                    .adm-status-badge {
                        display: inline-flex; align-items: center; gap: 5px;
                        padding: 3px 9px; border-radius: 20px; font-size: .72rem; font-weight: 700;
                    }
                    .adm-status-badge.on  { background: #dcfce7; color: #166534; }
                    .adm-status-badge.off { background: #f1f5f9; color: #64748b; }
                    .adm-dot { width: 7px; height: 7px; border-radius: 50%; background: currentColor; }

                    .adm-card-footer {
                        display: flex; align-items: center;
                        padding-top: 10px; border-top: 1px solid #f8fafc;
                    }
                </style>

                <form method="POST" id="formAdmin">
                    <input type="hidden" name="acao" value="salvar_admin">

                    <div class="adm-grid">
                        <?php foreach ($gateways as $g):
                            $g_id        = (int)$g['id'];
                            $is_ativo    = (bool)$g['ativo'];
                            $nome       = $g['nome'];
                            $icon_class  = match($nome) { 'infopago' => 'adm-icon-infopago', default => 'adm-icon-default' };
                            $icon_letter = match($nome) { 'infopago' => 'I', default => '?' };
                            $subtitle   = match($nome) { 'infopago' => 'OAuth2 + Certificado mTLS', default => 'Gateway' };
                        ?>
                        <div class="adm-card <?php echo $is_ativo ? 'is-active' : ''; ?>" id="adm-card-<?php echo $g_id; ?>">
                            <input type="hidden" name="ids[]" value="<?php echo $g_id; ?>">

                            <div class="adm-card-top">
                                <div class="adm-card-identity">
                                    <div class="adm-gw-icon <?php echo $icon_class; ?>"><?php echo $icon_letter; ?></div>
                                    <div style="min-width:0;">
                                        <div class="adm-card-name"><?php echo htmlspecialchars($g['titulo']); ?></div>
                                        <div class="adm-card-sub"><?php echo $subtitle; ?></div>
                                    </div>
                                </div>
                                <div>
                                    <input type="hidden" name="ativo[<?php echo $g_id; ?>]" value="0">
                                    <label class="adm-switch" title="Ativar/desativar no sistema">
                                        <input type="checkbox" name="ativo[<?php echo $g_id; ?>]" value="1"
                                               <?php echo $is_ativo ? 'checked' : ''; ?>
                                               onchange="admToggleCard(<?php echo $g_id; ?>, this.checked)">
                                        <span class="adm-slider"></span>
                                    </label>
                                </div>
                            </div>

                            <div class="adm-card-body">
                                <div class="adm-card-footer">
                                    <span class="adm-status-badge <?php echo $is_ativo ? 'on' : 'off'; ?>" id="badge-<?php echo $g_id; ?>">
                                        <span class="adm-dot"></span>
                                        <?php echo $is_ativo ? 'Ativo' : 'Inativo'; ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                </form>

                <script>
                    function admToggleCard(id, ativo) {
                        const card  = document.getElementById('adm-card-' + id);
                        const badge = document.getElementById('badge-' + id);
                        card.classList.toggle('is-active', ativo);
                        badge.className = 'adm-status-badge ' + (ativo ? 'on' : 'off');
                        badge.innerHTML = '<span class="adm-dot"></span>' + (ativo ? 'Ativo' : 'Inativo');
                        document.getElementById('formAdmin').submit();
                    }
                </script>

            <?php endif; ?>

            <?php if ($is_admin): ?>
                <hr style="margin:28px 0;border:none;border-top:1px solid #e2e8f0;">
            <?php endif; ?>
            <?php
                // USER VIEW: sempre visível — inclusive para admin, para sua própria conta
                $gateways = $gateways_usuario;
                    $gw_ativos      = array_filter($gateways, fn($g) => !empty($g['user_config']['ativo']));
                    $gw_disponiveis = array_filter($gateways, fn($g) =>  empty($g['user_config']['ativo']));

                    function gwIconClass(string $nome): string {
                        return match($nome) {
                            'infopago'  => 'gw-icon-infopago',
                            default     => 'gw-icon-default',
                        };
                    }
                    function gwIconLetter(string $nome): string {
                        return match($nome) {
                            'infopago'  => 'I',
                            default     => '?',
                        };
                    }

                    function renderGwForm(array $g): void {
                        $nome           = $g['nome'];
                        $id = (int)$g['id'];
                        $cfg = $g['user_config'];

                        // InfoPago usa credenciais compartilhadas do admin — usuário comum só liga/desliga,
                        // sem ver/editar client_id, secret, certificado ou chave Pix.
                        if (!empty($cfg['gerenciado_pelo_admin'])) {
                            ?>
                            <form method="POST">
                                <input type="hidden" name="acao"       value="salvar_user">
                                <input type="hidden" name="gateway_id" value="<?php echo $id; ?>">
                                <input type="hidden" name="client_id"     value="">
                                <input type="hidden" name="client_secret" value="">
                                <input type="hidden" name="chave_pix"     value="">
                                <input type="hidden" name="tipo_conta"    value="pj">
                                <input type="hidden" name="prioridade"    value="<?php echo (int)($cfg['prioridade'] ?? 100); ?>">
                                <input type="hidden" name="cert_password" value="">

                                <label class="gw-toggle-wrap">
                                    <input type="checkbox" name="ativo" <?php echo ($cfg['ativo'] ?? false) ? 'checked' : ''; ?>>
                                    <span class="gw-toggle-label">Habilitar este gateway nos meus bots</span>
                                </label>
                                <button type="submit" class="btn-save" style="margin-top:14px;">Salvar</button>
                            </form>
                            <?php
                            return;
                        }

                        ?>
                        <form method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="acao"       value="salvar_user">
                            <input type="hidden" name="gateway_id" value="<?php echo $id; ?>">

                            <label class="gw-toggle-wrap">
                                <input type="checkbox" name="ativo" <?php echo ($cfg['ativo'] ?? false) ? 'checked' : ''; ?>>
                                <span class="gw-toggle-label">Habilitar este gateway nos meus bots</span>
                            </label>

                            <div class="form-group">
                                <label class="form-label">Client ID (Produção)</label>
                                <input type="text" name="client_id" class="form-input"
                                       value="<?php echo htmlspecialchars($cfg['client_id'] ?? ''); ?>" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Client Secret (Produção)</label>
                                <input type="password" name="client_secret" class="form-input"
                                       value="<?php echo htmlspecialchars($cfg['client_secret'] ?? ''); ?>" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Chave Pix (Recebedor)</label>
                                <input type="text" name="chave_pix" class="form-input"
                                       value="<?php echo htmlspecialchars($cfg['chave_pix'] ?? ''); ?>"
                                       placeholder="CPF, CNPJ, Email…" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Tipo de Conta</label>
                                <select name="tipo_conta" class="form-input">
                                    <option value="pj" <?php echo (($cfg['tipo_conta'] ?? 'pj') === 'pj') ? 'selected' : ''; ?>>Pessoa Jurídica (PJ)</option>
                                    <option value="pf" <?php echo (($cfg['tipo_conta'] ?? 'pj') === 'pf') ? 'selected' : ''; ?>>Pessoa Física (PF)</option>
                                </select>
                                <small>Necessário para habilitar <strong>PIX Recorrente (Assinatura)</strong> nos seus fluxos — esse recurso só funciona em contas PJ.</small>
                            </div>
                            <input type="hidden" name="prioridade" value="<?php echo (int)($cfg['prioridade'] ?? 100); ?>">

                            <div class="form-group">
                                <label class="form-label">Certificado (.p12, .pfx ou .pem)</label>
                                <?php if (!empty($cfg['certificado'])): ?>
                                    <p style="margin:0 0 6px;font-size:.85rem;color:#16a34a;font-weight:500;">✅ Certificado enviado</p>
                                <?php endif; ?>
                                <input type="file" name="certificado" class="form-input" accept=".pem,.p12,.pfx">
                                <small>Recomendado: arquivo .p12/.pfx (PKCS#12) ou .pem original fornecido pelo gateway</small>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Senha do certificado (se houver)</label>
                                <input type="password" name="cert_password" class="form-input"
                                       autocomplete="new-password" data-lpignore="true" data-1p-ignore
                                       placeholder="<?php echo !empty($cfg['cert_password']) ? '••••••••' : ''; ?>">
                                <small>Deixe em branco pra manter a senha já salva.</small>
                            </div>

                            <button type="submit" class="btn-save">Salvar Credenciais</button>
                        </form>

                        <?php if ($nome === 'infopago'): ?>
                        <hr style="margin:18px 0;border:none;border-top:1px solid #e2e8f0;">
                        <p style="font-size:.85rem;color:#64748b;margin:0 0 10px;">
                            <strong>Credenciais de Cash-Out (para split de pagamento)</strong><br>
                            A InfoPago não tem split nativo na cobrança — o sistema simula repassando um valor
                            automaticamente via transferência Pix (Cash-Out) assim que a cobrança é confirmada.
                            Preencha aqui as credenciais da API de Contas/Cash-Out (separadas da API de cobrança acima).
                            O destino e o percentual do split são configurados pelo admin na tela de usuários.
                        </p>
                        <form method="POST" enctype="multipart/form-data" autocomplete="off">
                            <input type="hidden" name="acao" value="salvar_infopago_split">
                            <input type="hidden" name="gateway_id" value="<?php echo $id; ?>">

                            <div class="form-group">
                                <label class="form-label">Client ID (API Contas / Cash-Out)</label>
                                <input type="text" name="cashout_client_id" class="form-input"
                                       autocomplete="off" data-lpignore="true" data-1p-ignore
                                       value="<?php echo htmlspecialchars($cfg['cashout_client_id'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Client Secret (API Contas / Cash-Out)</label>
                                <input type="password" name="cashout_client_secret" class="form-input"
                                       autocomplete="new-password" data-lpignore="true" data-1p-ignore
                                       value="<?php echo htmlspecialchars($cfg['cashout_client_secret'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Certificado de Cash-Out (.p12, .pfx ou .pem)</label>
                                <?php if (!empty($cfg['cashout_certificado'])): ?>
                                    <p style="margin:0 0 6px;font-size:.85rem;color:#16a34a;font-weight:500;">✅ Certificado enviado</p>
                                <?php endif; ?>
                                <input type="file" name="cashout_certificado" class="form-input" accept=".pem,.p12,.pfx">
                                <small>Certificado da pasta ACCOUNTS (Cash-Out) — diferente do de QRCODES-MTLS usado na cobrança acima</small>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Senha do certificado de Cash-Out (se houver)</label>
                                <input type="password" name="cashout_cert_password" class="form-input"
                                       autocomplete="new-password" data-lpignore="true" data-1p-ignore
                                       placeholder="<?php echo !empty($cfg['cashout_cert_password']) ? '••••••••' : ''; ?>">
                                <small>Deixe em branco pra manter a senha já salva.</small>
                            </div>

                            <button type="submit" class="btn-save">Salvar Credenciais de Cash-Out</button>
                        </form>
                        <?php endif; ?>
                        <?php
                    }
                ?>

                <?php if (empty($gateways)): ?>
                    <div class="gw-empty">
                        <div class="gw-empty-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                        </div>
                        <h3>Nenhum Gateway Disponível</h3>
                        <p>A administração ainda não habilitou nenhum método de pagamento. Aguarde novas atualizações.</p>
                    </div>

                <?php else: ?>

                    <?php if (!empty($gw_ativos)): ?>
                    <div class="gw-section">
                        <p class="gw-section-title">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" stroke="#2563eb" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                            Gateways Ativos
                            <span class="gw-count"><?php echo count($gw_ativos); ?></span>
                        </p>
                        <p style="margin:-4px 0 14px;font-size:.82rem;color:#94a3b8;">A prioridade é definida pela ordem dos cards — arraste para reordenar.</p>

                        <div class="gw-active-grid" id="gw-sortable">
                            <?php
                            $idx = 1;
                            foreach ($gw_ativos as $g):
                                $is_primary   = ($idx === 1);
                                $modal_id     = 'modal-active-' . $g['id'];
                            ?>
                            <div class="gw-active-card <?php echo $is_primary ? 'is-primary' : ''; ?>" draggable="true" data-gw-id="<?php echo $g['id']; ?>">

                                <div class="gw-card-top">
                                    <span class="gw-priority-badge <?php echo $is_primary ? 'main' : ''; ?>">
                                        <?php echo $idx; ?>&nbsp;·&nbsp;<?php echo $is_primary ? 'Principal' : 'Fallback ' . ($idx - 1); ?>
                                    </span>
                                    <span class="gw-method-badge">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 512 512" fill="#15803d"><path d="M242.4 292.5C247.8 287.1 257.1 287.1 262.5 292.5L339.5 369.5C353.7 383.7 372.6 391.5 392.6 391.5H407.7L310.6 488.6C280.3 518.1 231.1 518.1 200.8 488.6L103.3 391.2H112.6C132.6 391.2 151.5 383.4 165.7 369.2L242.4 292.5zM262.5 218.9C257.1 224.4 247.8 224.4 242.4 218.9L165.7 142.2C151.5 127.1 132.6 120.2 112.6 120.2H103.3L200.7 22.8C231.1-7.6 280.3-7.6 310.6 22.8L407.7 119.9H392.6C372.6 119.9 353.7 127.7 339.5 141.9L262.5 218.9zM444.6 181.1L505.1 241.5C514.3 250.8 514.3 261.5 505.1 270.8L444.6 331.3V319.9C444.6 299.9 436.8 281 422.6 266.8L346 190.3C343.8 188.1 343.8 184.6 346 182.3L422.6 105.7C436.8 91.5 444.6 72.6 444.6 52.6V181.1zM67.4 19.9V330.9C67.4 350.9 75.2 369.8 89.4 384L166 460.6C168.2 462.8 168.2 466.3 166 468.5L89.4 545.2C75.2 559.4 67.4 578.3 67.4 598.3V607.6L6.9 547.1C-2.3 537.8-2.3 527.1 6.9 517.8L67.4 457.3V19.9z"/></svg>
                                        PIX
                                    </span>
                                </div>

                                <div class="gw-card-body">
                                    <div class="gw-icon <?php echo gwIconClass($g['nome']); ?>">
                                        <?php echo gwIconLetter($g['nome']); ?>
                                    </div>
                                    <div class="gw-card-name"><?php echo htmlspecialchars($g['titulo']); ?></div>
                                    <div class="gw-card-status <?php echo $g['conectado'] ? 'ok' : 'warn'; ?>">
                                        <span class="dot"></span>
                                        <?php echo $g['conectado'] ? 'Conectado' : 'Credenciais pendentes'; ?>
                                    </div>
                                </div>

                                <div class="gw-card-actions">
                                    <button type="button" class="btn btn-ghost btn-sm" onclick="openModal('<?php echo $modal_id; ?>')">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.1 4.9A10 10 0 0 0 4.9 19.1M19.1 19.1A10 10 0 0 0 4.9 4.9"/></svg>
                                        Configurar
                                    </button>
                                    <button type="button" class="btn btn-danger btn-sm" onclick="desativarGateway(<?php echo $g['id']; ?>, '<?php echo htmlspecialchars($g['titulo'], ENT_QUOTES); ?>')">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                        Desativar
                                    </button>
                                </div>
                            </div>

                            <div id="<?php echo $modal_id; ?>" class="gw-modal-overlay" onclick="if(event.target===this)closeModal('<?php echo $modal_id; ?>')">
                                <div class="gw-modal">
                                    <div class="gw-modal-header">
                                        <div class="gw-modal-title">
                                            <div class="gw-icon <?php echo gwIconClass($g['nome']); ?>" style="width:32px;height:32px;font-size:.9rem;border-radius:8px;flex-shrink:0;">
                                                <?php echo gwIconLetter($g['nome']); ?>
                                            </div>
                                            <?php echo htmlspecialchars($g['titulo']); ?>
                                        </div>
                                        <button class="gw-modal-close" onclick="closeModal('<?php echo $modal_id; ?>')">✕</button>
                                    </div>
                                    <div class="gw-modal-body">
                                        <?php renderGwForm($g); ?>
                                    </div>
                                </div>
                            </div>
                            <?php $idx++; endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($gw_disponiveis)): ?>
                    <div class="gw-section">
                        <p class="gw-section-title">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" stroke="#64748b" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
                            Gateways Disponíveis
                            <span class="gw-count" style="background:#64748b;"><?php echo count($gw_disponiveis); ?></span>
                        </p>

                        <div class="gw-available-grid">
                            <?php foreach ($gw_disponiveis as $g):
                                $modal_id = 'modal-' . $g['id'];
                            ?>
                            <?php
                                $cfg = $g['user_config'];
                                $tem_credenciais = !empty($cfg['client_id']);
                            ?>
                            <div class="gw-avail-card">
                                <div class="gw-avail-plus">+</div>
                                <div class="gw-icon <?php echo gwIconClass($g['nome']); ?>" style="margin:0 auto 10px;">
                                    <?php echo gwIconLetter($g['nome']); ?>
                                </div>
                                <div class="gw-avail-name"><?php echo htmlspecialchars($g['titulo']); ?></div>
                                <span class="gw-method-badge" style="justify-content:center;display:inline-flex;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 512 512" fill="#15803d"><path d="M242.4 292.5C247.8 287.1 257.1 287.1 262.5 292.5L339.5 369.5C353.7 383.7 372.6 391.5 392.6 391.5H407.7L310.6 488.6C280.3 518.1 231.1 518.1 200.8 488.6L103.3 391.2H112.6C132.6 391.2 151.5 383.4 165.7 369.2L242.4 292.5zM262.5 218.9C257.1 224.4 247.8 224.4 242.4 218.9L165.7 142.2C151.5 127.1 132.6 120.2 112.6 120.2H103.3L200.7 22.8C231.1-7.6 280.3-7.6 310.6 22.8L407.7 119.9H392.6C372.6 119.9 353.7 127.7 339.5 141.9L262.5 218.9zM444.6 181.1L505.1 241.5C514.3 250.8 514.3 261.5 505.1 270.8L444.6 331.3V319.9C444.6 299.9 436.8 281 422.6 266.8L346 190.3C343.8 188.1 343.8 184.6 346 182.3L422.6 105.7C436.8 91.5 444.6 72.6 444.6 52.6V181.1zM67.4 19.9V330.9C67.4 350.9 75.2 369.8 89.4 384L166 460.6C168.2 462.8 168.2 466.3 166 468.5L89.4 545.2C75.2 559.4 67.4 578.3 67.4 598.3V607.6L6.9 547.1C-2.3 537.8-2.3 527.1 6.9 517.8L67.4 457.3V19.9z"/></svg>
                                    PIX
                                </span>
                                <div class="gw-avail-actions">
                                    <button type="button" class="btn btn-ghost btn-sm" onclick="openModal('<?php echo $modal_id; ?>')">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.1 4.9A10 10 0 0 0 4.9 19.1M19.1 19.1A10 10 0 0 0 4.9 4.9"/></svg>
                                        Configurar
                                    </button>
                                    <?php if ($tem_credenciais): ?>
                                    <button type="button" class="btn btn-primary btn-sm" onclick="ativarGateway(<?php echo $g['id']; ?>)">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                                        Ativar
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div id="<?php echo $modal_id; ?>" class="gw-modal-overlay" onclick="if(event.target===this)closeModal('<?php echo $modal_id; ?>')">
                                <div class="gw-modal">
                                    <div class="gw-modal-header">
                                        <div class="gw-modal-title">
                                            <div class="gw-icon <?php echo gwIconClass($g['nome']); ?>" style="width:32px;height:32px;font-size:.9rem;border-radius:8px;flex-shrink:0;">
                                                <?php echo gwIconLetter($g['nome']); ?>
                                            </div>
                                            <?php echo htmlspecialchars($g['titulo']); ?>
                                        </div>
                                        <button class="gw-modal-close" onclick="closeModal('<?php echo $modal_id; ?>')">✕</button>
                                    </div>
                                    <div class="gw-modal-body">
                                        <?php renderGwForm($g); ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <form id="form-desativar" method="POST" style="display:none;">
                        <input type="hidden" name="acao"       value="desativar_user">
                        <input type="hidden" name="gateway_id" id="desativar-gw-id">
                    </form>
                    <form id="form-ativar" method="POST" style="display:none;">
                        <input type="hidden" name="acao"       value="ativar_user">
                        <input type="hidden" name="gateway_id" id="ativar-gw-id">
                    </form>

                <?php endif; ?>

            <?php echo paginador($total_gateways_usuario, $por_pagina); ?>
        </div>
    </main>
</div>

<script>
function openModal(id)  { document.getElementById(id).classList.add('open');    document.body.style.overflow='hidden'; }
function closeModal(id) { document.getElementById(id).classList.remove('open'); document.body.style.overflow='';       }

function desativarGateway(id, nome) {
    if (!confirm('Desativar "' + nome + '"? Seus bots deixarão de usar este gateway.')) return;
    document.getElementById('desativar-gw-id').value = id;
    document.getElementById('form-desativar').submit();
}

function ativarGateway(id) {
    document.getElementById('ativar-gw-id').value = id;
    document.getElementById('form-ativar').submit();
}

(function () {
    const grid = document.getElementById('gw-sortable');
    if (!grid) return;

    let dragged = null;

    grid.addEventListener('dragstart', e => {
        dragged = e.target.closest('.gw-active-card');
        if (!dragged) return;
        dragged.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
    });

    grid.addEventListener('dragend', () => {
        if (!dragged) return;
        dragged.classList.remove('dragging');
        grid.querySelectorAll('.gw-active-card').forEach(c => c.classList.remove('drag-over'));
        dragged = null;
        salvarOrdem();
    });

    grid.addEventListener('dragover', e => {
        e.preventDefault();
        const target = e.target.closest('.gw-active-card');
        if (!target || target === dragged) return;
        grid.querySelectorAll('.gw-active-card').forEach(c => c.classList.remove('drag-over'));
        target.classList.add('drag-over');
        const after = e.clientY > target.getBoundingClientRect().top + target.offsetHeight / 2;
        grid.insertBefore(dragged, after ? target.nextElementSibling : target);
    });

    grid.addEventListener('dragleave', e => {
        const target = e.target.closest('.gw-active-card');
        if (target) target.classList.remove('drag-over');
    });

    function salvarOrdem() {
        const cards = [...grid.querySelectorAll('.gw-active-card')];
        cards.forEach((card, i) => {
            const badge = card.querySelector('.gw-priority-badge');
            if (!badge) return;
            const is_primary = i === 0;
            badge.className = 'gw-priority-badge' + (is_primary ? ' main' : '');
            badge.innerHTML = (i + 1) + '&nbsp;·&nbsp;' + (is_primary ? 'Principal' : 'Fallback ' + i);
            card.classList.toggle('is-primary', is_primary);
        });

        const ordem = cards.map(c => c.dataset.gwId);
        const fd = new FormData();
        fd.append('acao', 'reordenar_gateways');
        ordem.forEach((id, i) => fd.append('ordem[' + i + ']', id));
        fetch('gateways.php', { method: 'POST', body: fd });
    }
})();
</script>
</body>
</html>
