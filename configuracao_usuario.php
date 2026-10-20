<?php
declare(strict_types=1);
require_once __DIR__ . '/funcoes/usuario.php';

verificarLogin();

$usuario_id = $_SESSION['usuario_id'];
$mensagem = '';
$tipo_mensagem = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificarCsrf();
    $acao = $_POST['acao'] ?? 'perfil';
    if ($acao === 'foto') {
        $resultado = salvarFotoPerfil($usuario_id, $_FILES['foto'] ?? []);
        $mensagem = $resultado['sucesso'] ? 'Foto de perfil atualizada.' : $resultado['erro'];
        $tipo_mensagem = $resultado['sucesso'] ? 'sucesso' : 'erro';
    } elseif ($acao === 'remover_foto') {
        $resultado = removerFotoPerfil($usuario_id);
        $mensagem = $resultado['sucesso'] ? 'Foto de perfil removida.' : $resultado['erro'];
        $tipo_mensagem = $resultado['sucesso'] ? 'sucesso' : 'erro';
    } else {
        $nome = trim($_POST['nome'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $apelido_publico = trim($_POST['apelido_publico'] ?? '');
        $senha = $_POST['senha'] ?? '';
        $confirmar_senha = $_POST['confirmar_senha'] ?? '';
        $senha_atual = $_POST['senha_atual'] ?? '';

        if (!empty($senha) && $senha !== $confirmar_senha) {
            $mensagem = 'As senhas não conferem.';
            $tipo_mensagem = 'erro';
        } elseif (!empty($senha) && $senha_atual === '') {
            $mensagem = 'Informe a senha atual para definir uma nova.';
            $tipo_mensagem = 'erro';
        } else {
            $resultado = atualizarPerfilUsuario($usuario_id, $nome, $email, empty($senha) ? null : $senha, $apelido_publico, $senha_atual, true);
            if ($resultado['sucesso']) {
                $mensagem = 'Perfil atualizado com sucesso!';
                $tipo_mensagem = 'sucesso';
            } else {
                $mensagem = $resultado['erro'];
                $tipo_mensagem = 'erro';
            }
        }
    }
}

$dados_usuario = obterDetalhesUsuario($usuario_id);

if (empty($dados_usuario)) {
    // Caso raro onde o usuário da sessão não existe mais no banco
    fazerLogout();
}

$nome_conta = trim($dados_usuario['nome'] ?? '');
$partes_nome = preg_split('/\s+/', $nome_conta, -1, PREG_SPLIT_NO_EMPTY) ?: [];
$iniciais_conta = mb_strtoupper(mb_substr($partes_nome[0] ?? '?', 0, 1)
    . (count($partes_nome) > 1 ? mb_substr((string) end($partes_nome), 0, 1) : ''));
$foto_conta = fotoPerfilDaSessao();
$url_foto_conta = urlFotoPerfil($foto_conta);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Minha Conta - <?php echo htmlspecialchars(nomeSistema()); ?></title>
    <?php include 'tema_inline.php'; ?>
    <link rel="stylesheet" href="assets/css/painel.css?v=<?php echo @filemtime(__DIR__.'/assets/css/painel.css'); ?>">
</head>
<body>
<div class="layout-painel">
    <?php include 'barra_lateral.php'; ?>

    <main class="conteudo-principal">
        <header class="a-ph">
            <div>
                <h1 class="a-h1">Minha Conta</h1>
                <p class="a-sub">Dados pessoais e senha.</p>
            </div>
        </header>

        <?php if ($mensagem): ?>
            <div class="a-aviso2 <?php echo $tipo_mensagem === 'sucesso' ? 'ok' : 'er'; ?>" style="margin-bottom: 16px" role="<?php echo $tipo_mensagem === 'sucesso' ? 'status' : 'alert'; ?>">
                <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><?php echo $tipo_mensagem === 'sucesso' ? '<path d="M20 6L9 17l-5-5"></path>' : '<circle cx="12" cy="12" r="9"></circle><path d="M12 8v5M12 16.5v.5"></path>'; ?></svg>
                <?php echo htmlspecialchars($mensagem); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="" class="a-grid">
            <?php echo campoCsrf(); ?>
            <section class="a-card s6">
                <div class="a-ch"><h2>Dados pessoais</h2></div>
                <div class="a-foto">
                    <?php
                    // form-foto-perfil vive FORA do <form> principal (declarado depois dele
                    // fechar, mais abaixo) -- <form> dentro de <form> é HTML inválido, o
                    // navegador descarta a tag interna e todo campo daqui (inclusive o
                    // acao=foto escondido) acaba solto dentro do form de fora, fazendo até o
                    // botão "Atualizar senha" mandar acao=foto sem arquivo nenhum. O atributo
                    // form="form-foto-perfil" no input abaixo associa ele ao form externo.
                    ?>
                    <label class="big" for="input-foto-perfil" title="Trocar foto" style="cursor: pointer">
                        <?php if ($url_foto_conta !== ''): ?>
                            <img src="<?php echo htmlspecialchars($url_foto_conta); ?>" alt="Sua foto de perfil">
                        <?php else: ?>
                            <?php echo htmlspecialchars($iniciais_conta); ?>
                        <?php endif; ?>
                    </label>
                    <input type="file" id="input-foto-perfil" name="foto" form="form-foto-perfil" accept="image/jpeg,image/png,image/webp" hidden>
                    <div class="a-form" style="gap: 8px">
                        <div class="a-acts">
                            <!-- <label for="input-foto-perfil"> em vez de <button>+JS pra abrir o seletor de
                                 arquivo -- é o único jeito 100% nativo (sem depender de .click() via JS,
                                 que em algum navegador/situação não estava disparando). -->
                            <label class="a-btn" for="input-foto-perfil" id="btn-trocar-foto">Trocar foto</label>
                            <?php if ($url_foto_conta !== ''): ?>
                                <button type="submit" class="a-btn" form="form-remover-foto">Remover</button>
                            <?php endif; ?>
                        </div>
                        <span class="a-mut" style="font-size: 12.5px">JPG, PNG ou WebP. Máximo 2 MB.</span>
                    </div>
                </div>
                <div class="a-form">
                    <label class="a-fld" for="nome"><span>Nome completo</span>
                        <input class="a-in" type="text" id="nome" name="nome" required
                               value="<?php echo htmlspecialchars($dados_usuario['nome'] ?? ''); ?>">
                    </label>
                    <label class="a-fld" for="email"><span>E-mail</span>
                        <input class="a-in" type="email" id="email" name="email" required
                               value="<?php echo htmlspecialchars($dados_usuario['email'] ?? ''); ?>">
                    </label>
                    <label class="a-fld" for="apelido_publico"><span>Apelido no Ranking</span>
                        <input class="a-in" type="text" id="apelido_publico" name="apelido_publico" maxlength="40"
                               placeholder="Ex: @seuapelido"
                               value="<?php echo htmlspecialchars($dados_usuario['apelido_publico'] ?? ''); ?>">
                        <small>Nome que os outros usuários veem.</small>
                    </label>
                    <div class="a-acts" style="justify-content: flex-end"><button type="submit" class="a-btn pri">Salvar alterações</button></div>
                </div>
            </section>

            <section class="a-card s6">
                <div class="a-ch"><h2>Segurança</h2></div>
                <div class="a-form">
                    <label class="a-fld" for="senha_atual"><span>Senha atual</span>
                        <input class="a-in" type="password" id="senha_atual" name="senha_atual" autocomplete="current-password" placeholder="Necessária só pra trocar a senha">
                    </label>
                    <label class="a-fld" for="senha"><span>Nova senha</span>
                        <input class="a-in" type="password" id="senha" name="senha" autocomplete="new-password" placeholder="Mínimo de 6 caracteres">
                        <small>Deixe em branco para manter a senha atual.</small>
                    </label>
                    <label class="a-fld" for="confirmar_senha"><span>Confirmar senha</span>
                        <input class="a-in" type="password" id="confirmar_senha" name="confirmar_senha" autocomplete="new-password" placeholder="Repita a nova senha">
                    </label>
                    <div class="a-acts" style="justify-content: flex-end"><button type="submit" class="a-btn">Atualizar senha</button></div>
                </div>
            </section>
        </form>

        <form method="POST" enctype="multipart/form-data" id="form-foto-perfil" hidden>
            <?php echo campoCsrf(); ?>
            <input type="hidden" name="acao" value="foto">
        </form>
        <?php if ($url_foto_conta !== ''): ?>
            <form method="POST" id="form-remover-foto" hidden>
                <?php echo campoCsrf(); ?>
                <input type="hidden" name="acao" value="remover_foto">
            </form>
        <?php endif; ?>

        <div class="a-grid" style="margin-top: 16px">
            <a href="gateways" class="a-tl s6">
                <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="5" width="20" height="14" rx="2"></rect><path d="M2 10h20"></path></svg>
                Gateways de pagamento
                <svg class="ic go" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 18l6-6-6-6"></path></svg>
            </a>
            <a href="logout" class="a-tl er s6">
                <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"></path></svg>
                Sair da conta
                <svg class="ic go" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 18l6-6-6-6"></path></svg>
            </a>
        </div>
    </main>
</div>

<script src="assets/js/tema.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/tema.js'); ?>"></script>
<script>
(function () {
    // Abrir o seletor de arquivo é 100% via <label for="input-foto-perfil"> nativo (avatar e
    // botão "Trocar foto") -- só falta o auto-submit assim que um arquivo é escolhido.
    var form = document.getElementById('form-foto-perfil');
    var input = document.getElementById('input-foto-perfil');
    if (!form || !input) return;
    input.addEventListener('change', function () {
        if (input.files && input.files.length) form.submit();
    });
})();
</script>
</body>
</html>
