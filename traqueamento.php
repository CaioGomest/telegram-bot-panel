<?php
declare(strict_types=1);
require_once 'conexao.php';
require_once __DIR__ . '/funcoes/usuario.php';

bloquearAdmin();

$usuario_id = $_SESSION['usuario_id'];
$mensagem = '';
$tipo_mensagem = '';

$stmt = $pdo->prepare("SELECT * FROM usuarios_traqueamento WHERE id_usuario = ?");
$stmt->execute([$usuario_id]);
$config = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$config) {
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificarCsrf();
    $fb_ativo = isset($_POST['facebook_ativo']) ? 1 : 0;
    $fb_pixel = trim($_POST['facebook_pixel_id'] ?? '');
    $fb_token = trim($_POST['facebook_access_token'] ?? '');
    
    $utmfy_ativo = isset($_POST['utmfy_ativo']) ? 1 : 0;
    $utmfy = trim($_POST['utmfy_token'] ?? '');
    
    $tt_ativo = isset($_POST['tiktok_ativo']) ? 1 : 0;
    $tt_pixel = trim($_POST['tiktok_pixel_id'] ?? '');
    $tt_token = trim($_POST['tiktok_access_token'] ?? '');
    
    try {
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
        error_log('Erro ao salvar traqueamento (usuario ' . $usuario_id . '): ' . $e->getMessage());
        $mensagem = 'Erro ao salvar as configurações. Tente novamente.';
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
    <?php include 'tema_inline.php'; ?>
    <link rel="stylesheet" href="assets/css/coyote.css?v=<?php echo @filemtime(__DIR__.'/assets/css/coyote.css'); ?>">
    <style>
        .secao-plataforma { margin-bottom: 28px; padding-bottom: 24px; border-bottom: 1px solid var(--bd); }
        .secao-plataforma:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
        .cabecalho-plataforma { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; gap: 12px; }
        .titulo-plataforma { font: 700 15px 'Manrope', sans-serif; display: flex; align-items: center; gap: 10px; }
        .icone-plataforma { width: 26px; height: 26px; }
        .campos-plataforma { margin-top: 16px; transition: opacity .2s ease; }
        .secao-desativada .campos-plataforma { opacity: .5; pointer-events: none; }
    </style>
    <script>
        function alternarSecaoPlataforma(id) {
            const checkbox = document.getElementById(id + '_ativo');
            const secao = document.getElementById(id + '_fields');
            secao.classList.toggle('secao-desativada', !checkbox.checked);
        }
        document.addEventListener('DOMContentLoaded', () => {
            ['facebook', 'utmfy'].forEach(id => {   // 'tiktok' saiu junto com a seção desativada
                alternarSecaoPlataforma(id);
                document.getElementById(id + '_ativo').addEventListener('change', () => alternarSecaoPlataforma(id));
            });
        });
    </script>
</head>
<body>
<div class="layout-painel">
    <?php include 'barra_lateral.php'; ?>

    <main class="conteudo-principal">
        <div class="cabecalho-pagina">
            <div>
                <h1>Traqueamento</h1>
                <p>Configure seus pixels e APIs de conversão para rastrear vendas.</p>
            </div>
            <div class="acoes-cabecalho">
                <button type="button" class="alternador-tema" onclick="alternarTema()" aria-label="Alternar tema">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"></path></svg>
                    Tema
                </button>
            </div>
        </div>

        <div class="painel" style="max-width: 800px;">
            <?php if ($mensagem): ?>
                <div class="aviso aviso-<?php echo $tipo_mensagem == 'sucesso' ? 'sucesso' : 'erro'; ?>">
                    <?php echo htmlspecialchars($mensagem); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <?php echo campoCsrf(); ?>

                <div class="secao-plataforma" id="facebook_fields">
                    <div class="cabecalho-plataforma">
                        <div class="titulo-plataforma">
                            <svg class="icone-plataforma" viewBox="0 0 48 48"><path fill="#1877F2" d="M24 0C10.7 0 0 10.7 0 24c0 12 8.8 21.9 20.3 23.7V31h-6.1v-7h6.1v-5.3c0-6 3.6-9.3 9-9.3 2.6 0 5.3.5 5.3.5v5.9h-3c-3 0-3.9 1.9-3.9 3.8V24h6.6l-1.1 7h-5.5v16.7C39.2 45.9 48 36 48 24 48 10.7 37.3 0 24 0z"/></svg>
                            Facebook Ads (Meta)
                        </div>
                        <label class="chave-gateway">
                            <input type="checkbox" id="facebook_ativo" name="facebook_ativo" <?php echo !empty($config['facebook_ativo']) ? 'checked' : ''; ?>>
                            <span class="chave-gateway-trilho"></span>
                        </label>
                    </div>

                    <div class="campos-plataforma grade grade-compacta">
                        <div class="campo">
                            <label for="facebook_pixel_id">Pixel ID</label>
                            <input type="text" id="facebook_pixel_id" name="facebook_pixel_id"
                                   value="<?php echo htmlspecialchars($config['facebook_pixel_id'] ?? ''); ?>" placeholder="Ex: 1234567890">
                        </div>
                        <div class="campo">
                            <label for="facebook_access_token">Access Token (Conversions API)</label>
                            <input type="text" id="facebook_access_token" name="facebook_access_token"
                                   value="<?php echo htmlspecialchars($config['facebook_access_token'] ?? ''); ?>" placeholder="Token longo da API de Conversões">
                            <small>Necessário para enviar eventos do servidor (pix gerado/pago). Gere no Gerenciador de Eventos &gt; Configurações &gt; API de Conversões.</small>
                        </div>
                    </div>
                </div>

                <?php /* ===== TikTok Ads: DESATIVADO em 19/09/2026 =====
                     Sem conta de TikTok Ads pra validar o envio, o campo só criaria a
                     expectativa de que o traqueamento está funcionando. Pra religar:
                     descomentar isto e o bloco correspondente em funcoes/traqueamento.php.

                <div class="secao-plataforma" id="tiktok_fields">
                    <div class="cabecalho-plataforma">
                        <div class="titulo-plataforma">
                            <svg class="icone-plataforma" viewBox="0 0 448 512"><path fill="currentColor" d="M448 209.91a210.06 210.06 0 0 1-122.77-39.25V349.38A162.55 162.55 0 1 1 185 188.31V278.2a74.62 74.62 0 1 0 52.23 71.18V0l88 0a121.18 121.18 0 0 0 1.86 22.17h0A122.18 122.18 0 0 0 381 102.39a121.43 121.43 0 0 0 67 20.14z"/></svg>
                            TikTok Ads
                        </div>
                        <label class="chave-gateway">
                            <input type="checkbox" id="tiktok_ativo" name="tiktok_ativo" <?php echo !empty($config['tiktok_ativo']) ? 'checked' : ''; ?>>
                            <span class="chave-gateway-trilho"></span>
                        </label>
                    </div>

                    <div class="campos-plataforma grade grade-compacta">
                        <div class="campo">
                            <label for="tiktok_pixel_id">Pixel ID</label>
                            <input type="text" id="tiktok_pixel_id" name="tiktok_pixel_id"
                                   value="<?php echo htmlspecialchars($config['tiktok_pixel_id'] ?? ''); ?>" placeholder="Ex: C12345ABCDE">
                        </div>
                        <div class="campo">
                            <label for="tiktok_access_token">Access Token (Events API)</label>
                            <input type="text" id="tiktok_access_token" name="tiktok_access_token"
                                   value="<?php echo htmlspecialchars($config['tiktok_access_token'] ?? ''); ?>" placeholder="Token da API de Eventos">
                            <small>Gere no TikTok Ads Manager &gt; Assets &gt; Events &gt; Web Events &gt; Settings &gt; Generate Access Token.</small>
                        </div>
                    </div>
                </div>

                */ ?>

                <div class="secao-plataforma" id="utmfy_fields">
                    <div class="cabecalho-plataforma">
                        <div class="titulo-plataforma">
                            <svg class="icone-plataforma" viewBox="0 0 24 24" fill="none" stroke="var(--ok)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>
                            UTMfy
                        </div>
                        <label class="chave-gateway">
                            <input type="checkbox" id="utmfy_ativo" name="utmfy_ativo" <?php echo !empty($config['utmfy_ativo']) ? 'checked' : ''; ?>>
                            <span class="chave-gateway-trilho"></span>
                        </label>
                    </div>

                    <div class="campos-plataforma grade grade-compacta">
                        <div class="campo">
                            <label for="utmfy_token">Token ou URL de Postback</label>
                            <input type="text" id="utmfy_token" name="utmfy_token"
                                   value="<?php echo htmlspecialchars($config['utmfy_token'] ?? ''); ?>" placeholder="Ex: https://api.utmify.com.br/v1/postback/SEU_TOKEN">
                            <small>Cole a URL de Postback fornecida pela UTMfy para integração.</small>
                        </div>
                    </div>
                </div>

                <div class="linha-acoes" style="margin-top:6px;">
                    <button type="submit" class="botao botao-primario">Salvar Configurações</button>
                </div>
            </form>
        </div>
    </main>
</div>

<script src="assets/js/tema.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/tema.js'); ?>"></script>
</body>
</html>
