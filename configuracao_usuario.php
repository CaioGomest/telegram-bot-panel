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
    <title>Minha Conta - Configurações</title>
    <?php include 'tema_inline.php'; ?>
    <link rel="stylesheet" href="assets/css/coyote.css?v=<?php echo @filemtime(__DIR__.'/assets/css/coyote.css'); ?>">
</head>
<body>
<div class="layout-painel">
    <?php include 'barra_lateral.php'; ?>

    <main class="conteudo-principal">
        <div class="cabecalho-pagina">
            <div>
                <h1>Minha Conta</h1>
                <p>Dados pessoais e senha.</p>
            </div>
            <div class="acoes-cabecalho">
                <button type="button" class="alternador-tema" onclick="alternarTema()" aria-label="Alternar tema">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"></path></svg>
                    Tema
                </button>
            </div>
        </div>

        <div>
            <?php if ($mensagem): ?>
                <div class="aviso aviso-<?php echo $tipo_mensagem; ?>" style="margin-bottom:14px;">
                    <?php echo htmlspecialchars($mensagem); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <?php echo campoCsrf(); ?>
                <div class="grade grade-2">
                    <div class="painel">
                        <div class="painel-cabecalho"><h2>Dados pessoais</h2></div>
                        <div class="conta-foto">
                            <form method="POST" enctype="multipart/form-data" id="form-foto-perfil">
                                <?php echo campoCsrf(); ?>
                                <input type="hidden" name="acao" value="foto">
                                <label class="conta-avatar" for="input-foto-perfil" title="Trocar foto">
                                    <?php if ($url_foto_conta !== ''): ?>
                                        <img src="<?php echo htmlspecialchars($url_foto_conta); ?>" alt="">
                                    <?php else: ?>
                                        <?php echo htmlspecialchars($iniciais_conta); ?>
                                    <?php endif; ?>
                                </label>
                                <input type="file" id="input-foto-perfil" name="foto" accept="image/jpeg,image/png,image/webp" hidden>
                            </form>
                            <div>
                                <div class="conta-foto-acoes">
                                    <!-- <label for="input-foto-perfil"> em vez de <button>+JS pra abrir o seletor de
                                         arquivo -- é o único jeito 100% nativo (sem depender de .click() via JS,
                                         que em algum navegador/situação não estava disparando). -->
                                    <label class="botao" for="input-foto-perfil" id="btn-trocar-foto">Trocar foto</label>
                                    <?php if ($url_foto_conta !== ''): ?>
                                        <button type="submit" class="botao" form="form-remover-foto">Remover</button>
                                    <?php endif; ?>
                                </div>
                                <span class="texto-ajuda">JPG, PNG ou WebP. Máximo 2 MB.</span>
                            </div>
                        </div>
                        <?php if ($url_foto_conta !== ''): ?>
                            <form method="POST" id="form-remover-foto" hidden>
                                <?php echo campoCsrf(); ?>
                                <input type="hidden" name="acao" value="remover_foto">
                            </form>
                        <?php endif; ?>
                        <div class="grade grade-compacta">
                            <div class="campo">
                                <label for="nome">Nome completo</label>
                                <input type="text" id="nome" name="nome" required
                                       value="<?php echo htmlspecialchars($dados_usuario['nome'] ?? ''); ?>">
                            </div>
                            <div class="campo">
                                <label for="email">E-mail</label>
                                <input type="email" id="email" name="email" required
                                       value="<?php echo htmlspecialchars($dados_usuario['email'] ?? ''); ?>">
                            </div>
                            <div class="campo">
                                <label for="apelido_publico">Apelido no Ranking</label>
                                <input type="text" id="apelido_publico" name="apelido_publico" maxlength="40"
                                       placeholder="Ex: @seuapelido"
                                       value="<?php echo htmlspecialchars($dados_usuario['apelido_publico'] ?? ''); ?>">
                                <span class="texto-ajuda">É esse nome (não seu nome real nem e-mail) que os outros usuários veem no Ranking. Deixe em branco pra aparecer como "Usuário #<?php echo (int) $usuario_id; ?>".</span>
                            </div>
                        </div>
                        <button type="submit" class="botao botao-primario" style="margin-top:16px;">Salvar alterações</button>
                    </div>

                    <div class="painel">
                        <div class="painel-cabecalho"><h2>Segurança</h2></div>
                        <div class="grade grade-compacta">
                            <div class="campo">
                                <label for="senha_atual">Senha atual</label>
                                <input type="password" id="senha_atual" name="senha_atual" autocomplete="current-password" placeholder="Necessária só pra trocar a senha">
                            </div>
                            <div class="campo">
                                <label for="senha">Nova senha</label>
                                <input type="password" id="senha" name="senha" autocomplete="new-password" placeholder="Mínimo de 6 caracteres">
                                <span class="texto-ajuda">Deixe em branco para manter a senha atual.</span>
                            </div>
                            <div class="campo">
                                <label for="confirmar_senha">Confirmar senha</label>
                                <input type="password" id="confirmar_senha" name="confirmar_senha" autocomplete="new-password" placeholder="Repita a nova senha">
                            </div>
                        </div>
                        <button type="submit" class="botao botao-secundario" style="margin-top:16px;">Atualizar senha</button>
                    </div>
                </div>
            </form>

            <div class="conta-lista" style="margin-top:14px;">
                <a href="gateways" class="conta-item">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"></rect><path d="M2 10h20"></path></svg>
                    Gateways de pagamento
                    <span class="conta-item-seta"><svg viewBox="0 0 24 24" fill="none" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg></span>
                </a>

                <a href="logout" class="conta-item">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
                    Sair da conta
                    <span class="conta-item-seta"><svg viewBox="0 0 24 24" fill="none" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg></span>
                </a>
            </div>
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
