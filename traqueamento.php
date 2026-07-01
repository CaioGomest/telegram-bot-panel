<?php
declare(strict_types=1);
require_once 'conexao.php';
require_once __DIR__ . '/funcoes/usuario.php';

verificarLogin();

$usuario_id = $_SESSION['usuario_id'];
$mensagem = '';
$tipo_mensagem = '';

// Buscar configurações atuais
$stmt = $pdo->prepare("SELECT * FROM usuarios_traqueamento WHERE id_usuario = ?");
$stmt->execute([$usuario_id]);
$config = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$config) {
    // Inicializa array vazio se não existir
    $config = [
        'facebook_ativo' => 0,
        'facebook_pixel_id' => '',
        'facebook_access_token' => '',
        'utmfy_ativo' => 0,
        'utmfy_token' => '',
        'tiktok_ativo' => 0,
        'tiktok_pixel_id' => '',
        'tiktok_access_token' => ''
    ];
}

// Processar formulário
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fb_ativo = isset($_POST['facebook_ativo']) ? 1 : 0;
    $fb_pixel = trim($_POST['facebook_pixel_id'] ?? '');
    $fb_token = trim($_POST['facebook_access_token'] ?? '');
    
    $utmfy_ativo = isset($_POST['utmfy_ativo']) ? 1 : 0;
    $utmfy = trim($_POST['utmfy_token'] ?? '');
    
    $tt_ativo = isset($_POST['tiktok_ativo']) ? 1 : 0;
    $tt_pixel = trim($_POST['tiktok_pixel_id'] ?? '');
    $tt_token = trim($_POST['tiktok_access_token'] ?? '');
    
    try {
        // Verifica se já existe registro
        $check = $pdo->prepare("SELECT id FROM usuarios_traqueamento WHERE id_usuario = ?");
        $check->execute([$usuario_id]);
        $exists = $check->fetchColumn();
        
        if ($exists) {
            $sql = "UPDATE usuarios_traqueamento SET 
                    facebook_ativo = ?,
                    facebook_pixel_id = ?, 
                    facebook_access_token = ?, 
                    utmfy_ativo = ?,
                    utmfy_token = ?, 
                    tiktok_ativo = ?,
                    tiktok_pixel_id = ?, 
                    tiktok_access_token = ? 
                    WHERE id_usuario = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$fb_ativo, $fb_pixel, $fb_token, $utmfy_ativo, $utmfy, $tt_ativo, $tt_pixel, $tt_token, $usuario_id]);
        } else {
            $sql = "INSERT INTO usuarios_traqueamento 
                    (id_usuario, facebook_ativo, facebook_pixel_id, facebook_access_token, utmfy_ativo, utmfy_token, tiktok_ativo, tiktok_pixel_id, tiktok_access_token) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$usuario_id, $fb_ativo, $fb_pixel, $fb_token, $utmfy_ativo, $utmfy, $tt_ativo, $tt_pixel, $tt_token]);
        }
        
        $mensagem = 'Configurações de traqueamento salvas com sucesso!';
        $tipo_mensagem = 'sucesso';
        
        // Atualiza variável local
        $config = [
            'facebook_ativo' => $fb_ativo,
            'facebook_pixel_id' => $fb_pixel,
            'facebook_access_token' => $fb_token,
            'utmfy_ativo' => $utmfy_ativo,
            'utmfy_token' => $utmfy,
            'tiktok_ativo' => $tt_ativo,
            'tiktok_pixel_id' => $tt_pixel,
            'tiktok_access_token' => $tt_token
        ];
        
    } catch (PDOException $e) {
        $mensagem = 'Erro ao salvar: ' . $e->getMessage();
        $tipo_mensagem = 'erro';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Traqueamento - Configurações</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/app.css">
    <style>
        .form-container {
            max-width: 800px;
            background: #fff;
            padding: 24px;
            border-radius: 12px;
            border: 1px solid var(--border);
        }
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }
        .section-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .platform-section {
            margin-bottom: 32px;
            padding-bottom: 24px;
            border-bottom: 1px solid var(--border);
        }
        .platform-section:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        .form-group {
            margin-bottom: 16px;
        }
        .form-label {
            display: block;
            margin-bottom: 6px;
            font-weight: 500;
            color: var(--text);
            font-size: 14px;
        }
        .form-control {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-family: inherit;
            font-size: 14px;
            color: var(--text);
            transition: border-color 0.2s;
            box-sizing: border-box;
        }
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-light);
        }
        .form-hint {
            display: block;
            margin-top: 6px;
            font-size: 12px;
            color: var(--muted);
        }
        .btn-salvar {
            background-color: var(--primary);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.2s;
            font-size: 14px;
        }
        .btn-salvar:hover {
            background-color: var(--primary-dark);
        }
        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .alert-sucesso {
            background-color: #ecfdf5;
            color: #047857;
            border: 1px solid #a7f3d0;
        }
        .alert-erro {
            background-color: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }
        
        /* Toggle Switch */
        .switch {
            position: relative;
            display: inline-block;
            width: 48px;
            height: 24px;
        }
        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            -webkit-transition: .4s;
            transition: .4s;
            border-radius: 24px;
        }
        .slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            -webkit-transition: .4s;
            transition: .4s;
            border-radius: 50%;
        }
        input:checked + .slider {
            background-color: var(--primary);
        }
        input:focus + .slider {
            box-shadow: 0 0 1px var(--primary);
        }
        input:checked + .slider:before {
            -webkit-transform: translateX(24px);
            -ms-transform: translateX(24px);
            transform: translateX(24px);
        }

        .platform-icon-svg {
            width: 28px;
            height: 28px;
        }
        .fields-container {
            margin-top: 16px;
            padding-left: 4px;
            transition: opacity 0.3s ease;
        }
        .disabled-section .fields-container {
            opacity: 0.5;
            pointer-events: none;
        }
    </style>
    <script>
        function toggleSection(id) {
            const checkbox = document.getElementById(id + '_ativo');
            const container = document.getElementById(id + '_fields');
            if (checkbox.checked) {
                container.classList.remove('disabled-section');
                // Habilitar inputs
                const inputs = container.querySelectorAll('input');
                inputs.forEach(input => input.disabled = false);
            } else {
                container.classList.add('disabled-section');
                // Desabilitar inputs (opcional, mas bom para UX)
                // const inputs = container.querySelectorAll('input');
                // inputs.forEach(input => input.disabled = true);
            }
        }
        
        document.addEventListener('DOMContentLoaded', () => {
            ['facebook', 'tiktok', 'utmfy'].forEach(id => {
                toggleSection(id);
                document.getElementById(id + '_ativo').addEventListener('change', () => toggleSection(id));
            });
        });
    </script>
</head>
<body>
<div class="dashboard-layout">
    <?php include 'sidebar.php'; ?>
    
    <main class="main-content">
        <div class="cabecalho-pagina">
            <div>
                <h1>Traqueamento</h1>
                <p>Configure seus pixels e APIs de conversão para rastrear vendas.</p>
            </div>
        </div>

        <div class="painel">
            <div class="form-container">
                <?php if ($mensagem): ?>
                    <div class="alert alert-<?php echo $tipo_mensagem == 'sucesso' ? 'sucesso' : 'erro'; ?>">
                        <?php echo htmlspecialchars($mensagem); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="">
                    
                    <!-- Facebook -->
                    <div class="platform-section" id="facebook_fields">
                        <div class="section-header">
                            <div class="section-title">
                                <!-- Logo Facebook -->
                                <svg class="platform-icon-svg" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48"><path fill="#1877F2" d="M24 0C10.7 0 0 10.7 0 24c0 12 8.8 21.9 20.3 23.7V31h-6.1v-7h6.1v-5.3c0-6 3.6-9.3 9-9.3 2.6 0 5.3.5 5.3.5v5.9h-3c-3 0-3.9 1.9-3.9 3.8V24h6.6l-1.1 7h-5.5v16.7C39.2 45.9 48 36 48 24 48 10.7 37.3 0 24 0z"/></svg>
                                Facebook Ads (Meta)
                            </div>
                            <label class="switch">
                                <input type="checkbox" id="facebook_ativo" name="facebook_ativo" <?php echo !empty($config['facebook_ativo']) ? 'checked' : ''; ?>>
                                <span class="slider round"></span>
                            </label>
                        </div>
                        
                        <div class="fields-container">
                            <div class="form-group">
                                <label for="facebook_pixel_id" class="form-label">Pixel ID</label>
                                <input type="text" id="facebook_pixel_id" name="facebook_pixel_id" class="form-control" 
                                       value="<?php echo htmlspecialchars($config['facebook_pixel_id'] ?? ''); ?>" placeholder="Ex: 1234567890">
                            </div>
                            <div class="form-group">
                                <label for="facebook_access_token" class="form-label">Access Token (Conversions API)</label>
                                <input type="text" id="facebook_access_token" name="facebook_access_token" class="form-control" 
                                       value="<?php echo htmlspecialchars($config['facebook_access_token'] ?? ''); ?>" placeholder="Token longo da API de Conversões">
                                <span class="form-hint">Necessário para enviar eventos do servidor (pix gerado/pago). Gere no Gerenciador de Eventos > Configurações > API de Conversões.</span>
                            </div>
                        </div>
                    </div>

                    <!-- TikTok -->
                    <div class="platform-section" id="tiktok_fields">
                        <div class="section-header">
                            <div class="section-title">
                                <!-- Logo TikTok -->
                                <svg class="platform-icon-svg" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 448 512"><path fill="#000000" d="M448 209.91a210.06 210.06 0 0 1-122.77-39.25V349.38A162.55 162.55 0 1 1 185 188.31V278.2a74.62 74.62 0 1 0 52.23 71.18V0l88 0a121.18 121.18 0 0 0 1.86 22.17h0A122.18 122.18 0 0 0 381 102.39a121.43 121.43 0 0 0 67 20.14z"/></svg>
                                TikTok Ads
                            </div>
                            <label class="switch">
                                <input type="checkbox" id="tiktok_ativo" name="tiktok_ativo" <?php echo !empty($config['tiktok_ativo']) ? 'checked' : ''; ?>>
                                <span class="slider round"></span>
                            </label>
                        </div>
                        
                        <div class="fields-container">
                            <div class="form-group">
                                <label for="tiktok_pixel_id" class="form-label">Pixel ID</label>
                                <input type="text" id="tiktok_pixel_id" name="tiktok_pixel_id" class="form-control" 
                                       value="<?php echo htmlspecialchars($config['tiktok_pixel_id'] ?? ''); ?>" placeholder="Ex: C12345ABCDE">
                            </div>
                            <div class="form-group">
                                <label for="tiktok_access_token" class="form-label">Access Token (Events API)</label>
                                <input type="text" id="tiktok_access_token" name="tiktok_access_token" class="form-control" 
                                       value="<?php echo htmlspecialchars($config['tiktok_access_token'] ?? ''); ?>" placeholder="Token da API de Eventos">
                                <span class="form-hint">Gere no TikTok Ads Manager > Assets > Events > Web Events > Settings > Generate Access Token.</span>
                            </div>
                        </div>
                    </div>

                    <!-- UTMfy -->
                    <div class="platform-section" id="utmfy_fields">
                        <div class="section-header">
                            <div class="section-title">
                                <!-- Logo UTMfy (Link Icon estilizado verde) -->
                                <svg class="platform-icon-svg" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>
                                UTMfy
                            </div>
                            <label class="switch">
                                <input type="checkbox" id="utmfy_ativo" name="utmfy_ativo" <?php echo !empty($config['utmfy_ativo']) ? 'checked' : ''; ?>>
                                <span class="slider round"></span>
                            </label>
                        </div>
                        
                        <div class="fields-container">
                            <div class="form-group">
                                <label for="utmfy_token" class="form-label">Token ou URL de Postback</label>
                                <input type="text" id="utmfy_token" name="utmfy_token" class="form-control" 
                                       value="<?php echo htmlspecialchars($config['utmfy_token'] ?? ''); ?>" placeholder="Ex: https://api.utmify.com.br/v1/postback/SEU_TOKEN">
                                <span class="form-hint">Cole a URL de Postback fornecida pela UTMfy para integração.</span>
                            </div>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn-salvar">Salvar Configurações</button>
                    </div>
                </form>
            </div>
        </div>
    </main>
</div>
</body>
</html>
