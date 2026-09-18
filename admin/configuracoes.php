<?php
declare(strict_types=1);

require_once __DIR__ . '/../funcoes/usuario.php';
verificarAdmin();
$caminho_base = '../';

$mensagem = '';
$tipo_mensagem = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificarCsrf();
    try {
        $nome = trim($_POST['nome_sistema'] ?? '');
        if ($nome === '') {
            throw new RuntimeException('O nome do sistema não pode ficar vazio.');
        }
        definirConfigSistema('nome_sistema', mb_substr($nome, 0, 60));

        if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            definirConfigSistema('logo', salvarArquivoMarca($_FILES['logo'], 'marca_logo'));
        }
        if (isset($_FILES['favicon']) && $_FILES['favicon']['error'] === UPLOAD_ERR_OK) {
            definirConfigSistema('favicon', salvarArquivoMarca($_FILES['favicon'], 'marca_favicon'));
        }

        registrarAtividade((int) $_SESSION['usuario_id'], 'sistema', 'Identidade visual', 'Nome/logo/favicon do painel atualizados.');
        header('Location: configuracoes?salvo=1');
        exit;
    } catch (RuntimeException $e) {
        $mensagem = $e->getMessage();
        $tipo_mensagem = 'erro';
    }
}

if (isset($_GET['salvo'])) {
    $mensagem = 'Identidade visual atualizada.';
    $tipo_mensagem = 'sucesso';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configurações</title>
    <?php include __DIR__ . '/../tema_inline.php'; ?>
    <link rel="stylesheet" href="../assets/css/coyote.css?v=<?php echo @filemtime(__DIR__.'/../assets/css/coyote.css'); ?>">
</head>
<body>
<div class="layout-painel">
    <?php include __DIR__ . '/../barra_lateral.php'; ?>

    <main class="conteudo-principal">
        <div class="cabecalho-pagina">
            <div>
                <h1>Configurações</h1>
                <p>Nome, logo e favicon que aparecem para todos os usuários do painel.</p>
            </div>
            <div class="acoes-cabecalho">
                <button type="button" class="alternador-tema" onclick="alternarTema()" aria-label="Alternar tema">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"></path></svg>
                    Tema
                </button>
            </div>
        </div>

        <div class="painel" style="max-width: 700px;">
            <?php if ($mensagem): ?>
                <div class="aviso aviso-<?php echo $tipo_mensagem; ?>"><?php echo htmlspecialchars($mensagem); ?></div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data">
                <?php echo campoCsrf(); ?>

                <div class="campo">
                    <label for="nome_sistema">Nome do sistema</label>
                    <input type="text" id="nome_sistema" name="nome_sistema" maxlength="60" required
                           value="<?php echo htmlspecialchars(nomeSistema()); ?>">
                    <small>Aparece na barra lateral, no topo do celular e no título das abas do navegador.</small>
                </div>

                <div class="campo" style="margin-top:18px;">
                    <label>Logo</label>
                    <div style="display:flex;align-items:center;gap:14px;margin-bottom:8px;">
                        <img src="<?php echo htmlspecialchars(logoSistema($caminho_base)); ?>" alt=""
                             style="width:56px;height:56px;border-radius:14px;object-fit:cover;border:1px solid var(--bd);"
                             onerror="this.style.display='none'">
                        <span class="texto-suave">Atual</span>
                    </div>
                    <input type="file" name="logo" accept=".png,.jpg,.jpeg,.webp">
                    <small>PNG, JPG ou WEBP, até 2 MB. Quadrada fica melhor. Deixe em branco para manter a atual.</small>
                </div>

                <div class="campo" style="margin-top:18px;">
                    <label>Favicon</label>
                    <div style="display:flex;align-items:center;gap:14px;margin-bottom:8px;">
                        <img src="<?php echo htmlspecialchars(faviconSistema($caminho_base)); ?>" alt=""
                             style="width:28px;height:28px;border-radius:7px;object-fit:cover;border:1px solid var(--bd);"
                             onerror="this.style.display='none'">
                        <span class="texto-suave">Atual</span>
                    </div>
                    <input type="file" name="favicon" accept=".png,.ico,.jpg,.jpeg,.webp">
                    <small>Ícone da aba do navegador. Sem favicon próprio, o sistema usa a logo.</small>
                </div>

                <div class="linha-acoes" style="margin-top: 22px;">
                    <button type="submit" class="botao botao-primario">Salvar</button>
                </div>
            </form>
        </div>
    </main>
</div>

<script src="../assets/js/tema.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/tema.js'); ?>"></script>
</body>
</html>
