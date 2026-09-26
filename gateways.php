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
    verificarCsrf();
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
    verificarCsrf();
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

                // Split é salvo junto (mesmo form), mas não entra na contagem de sucesso/erro
                // acima -- é best-effort e sempre um simples UPDATE, não deveria falhar sozinho.
                $taxa_split_post = str_replace(',', '.', (string) ($_POST['taxa_split'][$gateway_id] ?? '0'));
                $taxa_split = (float) $taxa_split_post;
                $tipo_split = (string) ($_POST['tipo_split'][$gateway_id] ?? 'percentual');
                $chave_pix_split = trim((string) ($_POST['chave_pix_split'][$gateway_id] ?? ''));
                saveGatewaySplit($gateway_id, $taxa_split, $tipo_split, $chave_pix_split);
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
            header('Location: ' . preg_replace('/\.php$/', '', $_SERVER['PHP_SELF']));
            exit;
        } else {
            $erro = 'Erro ao desativar o gateway.';
        }
    } elseif (isset($_POST['acao']) && $_POST['acao'] === 'ativar_user') {
        $gateway_id = (int)$_POST['gateway_id'];
        $stmt = $pdo->prepare("UPDATE usuarios_gateways SET ativo = 1 WHERE id_usuario = ? AND id_gateway = ?");
        if ($stmt->execute([$user_id, $gateway_id])) {
            $_SESSION['gw_mensagem'] = 'Gateway ativado!';
            header('Location: ' . preg_replace('/\.php$/', '', $_SERVER['PHP_SELF']));
            exit;
        } else {
            $erro = 'Erro ao ativar o gateway.';
        }
    } elseif (isset($_POST['acao']) && $_POST['acao'] === 'salvar_user') {
        $gateway_id = (int)$_POST['gateway_id'];
        $client_id = $_POST['client_id'];
        $client_secret = trim($_POST['client_secret'] ?? '');
        $chave_pix = trim($_POST['chave_pix'] ?? '');
        $ativo = isset($_POST['ativo']);
        $prioridade = isset($_POST['prioridade']) ? max(1, min(999, (int)$_POST['prioridade'])) : 100;
        $tipo_conta = in_array($_POST['tipo_conta'] ?? '', ['pf', 'pj']) ? $_POST['tipo_conta'] : 'pj';
        $stmt = $pdo->prepare("SELECT nome FROM gateways WHERE id = ?");
        $stmt->execute([$gateway_id]);
        $gateway_nome = $stmt->fetchColumn();
        $current_config = getUserGatewayConfig($user_id, $gateway_nome);

        // Os campos de segredo chegam vazios quando a pessoa não quis trocá-los (o
        // formulário não ecoa mais o valor salvo). Vazio aqui significa "mantém", nunca
        // "apaga" -- senão abrir a tela e salvar zeraria a credencial do gateway.
        if ($client_secret === '') {
            $client_secret = $current_config['client_secret'] ?? '';
        }
        if ($chave_pix === '') {
            $chave_pix = $current_config['chave_pix'] ?? '';
        }

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
    }
    // PRG: redireciona para evitar reenvio do POST ao recarregar
    if (!$erro) {
        header('Location: ' . preg_replace('/\.php$/', '', $_SERVER['PHP_SELF']));
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
    <title>Gateways de Pagamento - <?php echo htmlspecialchars(nomeSistema()); ?></title>
    <?php include 'tema_inline.php'; ?>
    <link rel="stylesheet" href="assets/css/coyote.css?v=<?php echo @filemtime(__DIR__.'/assets/css/coyote.css'); ?>">
</head>
<body>
<div class="layout-painel">
    <?php include 'barra_lateral.php'; ?>

    <main class="conteudo-principal">
        <div class="cabecalho-pagina">
            <div>
                <h1>Gateways de Pagamento</h1>
                <p>Gerencie as integrações de pagamento.</p>
            </div>
            <div class="acoes-cabecalho">
                <button type="button" class="alternador-tema" onclick="alternarTema()" aria-label="Alternar tema">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"></path></svg>
                    Tema
                </button>
            </div>
        </div>


        <div class="painel">
            <?php if ($mensagem): ?>
                <div class="aviso aviso-sucesso"><?php echo $mensagem; ?></div>
            <?php endif; ?>
            <?php if ($erro): ?>
                <div class="aviso aviso-erro"><?php echo $erro; ?></div>
            <?php endif; ?>

            <?php if ($is_admin): ?>
                <form method="POST" id="formAdmin">
                    <?php echo campoCsrf(); ?>
                    <input type="hidden" name="acao" value="salvar_admin">

                    <div class="grade-gateways-admin">
                        <?php foreach ($gateways as $g):
                            $g_id        = (int)$g['id'];
                            $is_ativo    = (bool)$g['ativo'];
                            $nome       = $g['nome'];
                            $icon_class  = match($nome) { 'omegapayments' => 'icone-gateway-omegapayments', default => 'icone-gateway-padrao' };
                            $icon_letter = match($nome) { 'omegapayments' => 'O', default => '?' };
                            $subtitle   = match($nome) { 'omegapayments' => 'Chave Pública/Secreta (API Key)', default => 'Gateway' };
                        ?>
                        <div class="cartao-gateway-admin <?php echo $is_ativo ? 'ativo' : ''; ?>" id="adm-card-<?php echo $g_id; ?>">
                            <input type="hidden" name="ids[]" value="<?php echo $g_id; ?>">

                            <div class="cartao-gateway-admin-topo">
                                <div class="identidade-gateway-admin">
                                    <div class="icone-gateway <?php echo $icon_class; ?>" style="width:38px;height:38px;border-radius:10px;font-size:.95rem;"><?php echo $icon_letter; ?></div>
                                    <div style="min-width:0;">
                                        <div class="nome-gateway"><?php echo htmlspecialchars($g['titulo']); ?></div>
                                        <div class="subtitulo-gateway-admin"><?php echo $subtitle; ?></div>
                                    </div>
                                </div>
                                <div>
                                    <input type="hidden" name="ativo[<?php echo $g_id; ?>]" value="0">
                                    <label class="chave-gateway" title="Ativar/desativar no sistema">
                                        <input type="checkbox" name="ativo[<?php echo $g_id; ?>]" value="1"
                                               <?php echo $is_ativo ? 'checked' : ''; ?>
                                               onchange="admToggleCard(<?php echo $g_id; ?>, this.checked)">
                                        <span class="chave-gateway-trilho"></span>
                                    </label>
                                </div>
                            </div>

                            <div class="corpo-cartao-gateway-admin">
                                <div class="rodape-cartao-gateway-admin">
                                    <span class="badge <?php echo $is_ativo ? 'badge-sucesso' : 'badge-neutro'; ?>" id="badge-<?php echo $g_id; ?>">
                                        <?php echo $is_ativo ? 'Ativo' : 'Inativo'; ?>
                                    </span>
                                </div>

                                <?php
                                // Split é uma regra única por gateway (não por usuário) -- todo mundo que
                                // usa esse gateway repassa pro mesmo destino automaticamente. Zerado/sem
                                // chave = nenhum split acontece (getGatewaySplit() trata isso).
                                ?>
                                <div class="campo" style="margin-top:12px;">
                                    <label>Split padrão (repassado em toda venda)</label>
                                    <div style="display:flex; gap:8px;">
                                        <input type="number" name="taxa_split[<?php echo $g_id; ?>]" step="0.01" min="0" max="100"
                                               value="<?php echo htmlspecialchars((string) ($g['taxa_split'] ?? '0')); ?>"
                                               style="max-width:90px;" placeholder="0,00">
                                        <select name="tipo_split[<?php echo $g_id; ?>]" style="max-width:130px;">
                                            <option value="percentual" <?php echo ($g['tipo_split'] ?? 'percentual') === 'percentual' ? 'selected' : ''; ?>>% do valor</option>
                                            <option value="fixo" <?php echo ($g['tipo_split'] ?? '') === 'fixo' ? 'selected' : ''; ?>>R$ fixo</option>
                                        </select>
                                    </div>
                                    <input type="text" name="chave_pix_split[<?php echo $g_id; ?>]"
                                           value="<?php echo htmlspecialchars((string) ($g['chave_pix_split'] ?? '')); ?>"
                                           placeholder="Chave Pix destino (CPF, CNPJ, e-mail...)" style="margin-top:8px;">
                                    <small>0 ou chave vazia = nenhum split é feito.</small>
                                </div>

                                <button type="submit" class="botao botao-primario botao-bloco" style="margin-top:12px;">Salvar Split</button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                </form>

                <script>
                    // O checkbox dispara submit() da página inteira a cada troca. Sem travar os
                    // controles nesse instante, dar 2-3 cliques rápidos (ativar/desativar em
                    // sequência) empilha navegações umas em cima das outras -- nesse servidor
                    // isso às vezes deixa a conexão HTTP/2 reaproveitada num estado ruim
                    // (ERR_HTTP2_PROTOCOL_ERROR até o navegador abrir uma conexão nova).
                    function admToggleCard(id, ativo) {
                        const card  = document.getElementById('adm-card-' + id);
                        const badge = document.getElementById('badge-' + id);
                        card.classList.toggle('ativo', ativo);
                        badge.className = 'badge ' + (ativo ? 'badge-sucesso' : 'badge-neutro');
                        badge.textContent = ativo ? 'Ativo' : 'Inativo';
                        // submit() primeiro (já captura os valores atuais dos checkboxes), só
                        // depois desabilita -- desabilitar antes tiraria os checkboxes do POST
                        // (campo disabled não é enviado) e zeraria todo mundo pra "inativo".
                        document.getElementById('formAdmin').submit();
                        document.querySelectorAll('#formAdmin input[type="checkbox"]').forEach(cb => cb.disabled = true);
                    }
                </script>

            <?php endif; ?>

            <?php if ($is_admin): ?>
                <hr style="margin:28px 0;">
            <?php endif; ?>
            <?php
                // USER VIEW: sempre visível — inclusive para admin, para sua própria conta
                $gateways = $gateways_usuario;
                    $gw_ativos      = array_filter($gateways, fn($g) => !empty($g['user_config']['ativo']));
                    $gw_disponiveis = array_filter($gateways, fn($g) =>  empty($g['user_config']['ativo']));

                    function gwIconClass(string $nome): string {
                        return match($nome) {
                            'omegapayments' => 'icone-gateway-omegapayments',
                            default         => 'icone-gateway-padrao',
                        };
                    }
                    function gwIconLetter(string $nome): string {
                        return match($nome) {
                            'omegapayments' => 'O',
                            default         => '?',
                        };
                    }

                    function renderGwForm(array $g): void {
                        $nome           = $g['nome'];
                        $id = (int)$g['id'];
                        $cfg = $g['user_config'];
                        ?>
                        <form method="POST" enctype="multipart/form-data">
                            <?php echo campoCsrf(); ?>
                            <input type="hidden" name="acao"       value="salvar_user">
                            <input type="hidden" name="gateway_id" value="<?php echo $id; ?>">

                            <label class="opcao-ativar-gateway">
                                <input type="checkbox" name="ativo" <?php echo ($cfg['ativo'] ?? false) ? 'checked' : ''; ?>>
                                <span>Habilitar este gateway nos meus bots</span>
                            </label>

                            <div class="campo">
                                <label>Client ID</label>
                                <input type="text" name="client_id"
                                       value="<?php echo htmlspecialchars($cfg['client_id'] ?? ''); ?>" required>
                            </div>
                            <div class="campo">
                                <label>Client Secret</label>
                                <?php /* Sem value: o type=password mascara na tela, mas o segredo ia inteiro
                                     no HTML e aparecia em "ver código-fonte". Mesmo padrão que
                                     cert_password já usava aqui embaixo. */ ?>
                                <input type="password" name="client_secret"
                                       autocomplete="new-password" data-lpignore="true" data-1p-ignore
                                       placeholder="<?php echo !empty($cfg['client_secret']) ? '•••••••• (salvo)' : ''; ?>"
                                       <?php echo empty($cfg['client_secret']) ? 'required' : ''; ?>>
                                <?php if (!empty($cfg['client_secret'])): ?>
                                    <small>Deixe em branco para manter o atual.</small>
                                <?php endif; ?>
                            </div>

                            <?php if ($nome !== 'omegapayments'): ?>
                            <div class="campo">
                                <label>Chave Pix (Recebedor)</label>
                                <?php
                                // A chave Pix não é credencial, mas é CPF/CNPJ/e-mail do recebedor -- dado
                                // pessoal, e é ela que decide pra onde o dinheiro vai. Em vez do valor
                                // inteiro no HTML, mostra só os 4 últimos caracteres pra pessoa reconhecer
                                // qual está configurada. Só se aplica a gateway que usa a chave Pix pra
                                // decidir o recebedor (a OmegaPayments não -- é definido pela credencial).
                                $pix_salva = $cfg['chave_pix'] ?? '';
                                $pix_dica = $pix_salva !== ''
                                    ? '•••• ' . mb_substr($pix_salva, -4) . ' (salva)'
                                    : 'CPF, CNPJ, Email…';
                                ?>
                                <input type="text" name="chave_pix"
                                       autocomplete="off" data-lpignore="true" data-1p-ignore
                                       placeholder="<?php echo htmlspecialchars($pix_dica); ?>"
                                       <?php echo $pix_salva === '' ? 'required' : ''; ?>>
                                <?php if ($pix_salva !== ''): ?>
                                    <small>Deixe em branco para manter a atual.</small>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>

                            <?php if ($nome === 'omegapayments'): ?>
                                <!-- OmegaPayments não tem PIX Recorrente nem certificado mTLS (v1) --
                                     esconde os dois campos em vez de mostrar algo que não se aplica. -->
                                <input type="hidden" name="tipo_conta" value="pj">
                            <?php else: ?>
                            <div class="campo">
                                <label>Tipo de Conta</label>
                                <select name="tipo_conta">
                                    <option value="pj" <?php echo (($cfg['tipo_conta'] ?? 'pj') === 'pj') ? 'selected' : ''; ?>>Pessoa Jurídica (PJ)</option>
                                    <option value="pf" <?php echo (($cfg['tipo_conta'] ?? 'pj') === 'pf') ? 'selected' : ''; ?>>Pessoa Física (PF)</option>
                                </select>
                                <small>Necessário para habilitar <strong>PIX Recorrente (Assinatura)</strong> nos seus fluxos — esse recurso só funciona em contas PJ.</small>
                            </div>
                            <?php endif; ?>
                            <input type="hidden" name="prioridade" value="<?php echo (int)($cfg['prioridade'] ?? 100); ?>">

                            <?php if ($nome !== 'omegapayments'): ?>
                            <div class="campo">
                                <label>Certificado (.p12, .pfx ou .pem)</label>
                                <?php if (!empty($cfg['certificado'])): ?>
                                    <p style="margin:0 0 6px;font-size:12.5px;color:var(--ok);font-weight:600;">✅ Certificado enviado</p>
                                <?php endif; ?>
                                <input type="file" name="certificado" accept=".pem,.p12,.pfx">
                                <small>Recomendado: arquivo .p12/.pfx (PKCS#12) ou .pem original fornecido pelo gateway</small>
                            </div>
                            <div class="campo">
                                <label>Senha do certificado (se houver)</label>
                                <input type="password" name="cert_password"
                                       autocomplete="new-password" data-lpignore="true" data-1p-ignore
                                       placeholder="<?php echo !empty($cfg['cert_password']) ? '••••••••' : ''; ?>">
                                <small>Deixe em branco pra manter a senha já salva.</small>
                            </div>
                            <?php endif; ?>

                            <button type="submit" class="botao botao-primario botao-bloco">Salvar Credenciais</button>
                        </form>

                        <?php
                    }
                ?>

                <?php if (empty($gateways)): ?>
                    <div class="estado-vazio">
                        <div class="icone-vazio">
                            <svg width="28" height="28" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                        </div>
                        <h3>Nenhum Gateway Disponível</h3>
                        <p>A administração ainda não habilitou nenhum método de pagamento. Aguarde novas atualizações.</p>
                    </div>

                <?php else: ?>

                    <?php if (!empty($gw_ativos)): ?>
                    <div class="secao-gateway">
                        <p class="titulo-secao-gateway">
                            <svg width="18" height="18" fill="none" stroke="var(--ok)" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                            Gateways Ativos
                            <span class="badge badge-sucesso"><?php echo count($gw_ativos); ?></span>
                        </p>
                        <p class="texto-secao-gateway">A prioridade é definida pela ordem dos cards — arraste para reordenar.</p>

                        <div class="grade-gateways-ativos" id="gw-sortable">
                            <?php
                            $idx = 1;
                            foreach ($gw_ativos as $g):
                                $is_primary   = ($idx === 1);
                                $modal_id     = 'modal-active-' . $g['id'];
                            ?>
                            <div class="cartao-gateway-ativo <?php echo $is_primary ? 'principal' : ''; ?>" draggable="true" data-gw-id="<?php echo $g['id']; ?>">

                                <div class="cartao-gateway-topo">
                                    <span class="selo-prioridade <?php echo $is_primary ? 'principal' : ''; ?>">
                                        <?php echo $idx; ?>&nbsp;·&nbsp;<?php echo $is_primary ? 'Principal' : 'Fallback ' . ($idx - 1); ?>
                                    </span>
                                    <span class="selo-metodo">
                                        <svg width="10" height="10" viewBox="0 0 512 512" fill="currentColor"><path d="M242.4 292.5C247.8 287.1 257.1 287.1 262.5 292.5L339.5 369.5C353.7 383.7 372.6 391.5 392.6 391.5H407.7L310.6 488.6C280.3 518.1 231.1 518.1 200.8 488.6L103.3 391.2H112.6C132.6 391.2 151.5 383.4 165.7 369.2L242.4 292.5zM262.5 218.9C257.1 224.4 247.8 224.4 242.4 218.9L165.7 142.2C151.5 127.1 132.6 120.2 112.6 120.2H103.3L200.7 22.8C231.1-7.6 280.3-7.6 310.6 22.8L407.7 119.9H392.6C372.6 119.9 353.7 127.7 339.5 141.9L262.5 218.9zM444.6 181.1L505.1 241.5C514.3 250.8 514.3 261.5 505.1 270.8L444.6 331.3V319.9C444.6 299.9 436.8 281 422.6 266.8L346 190.3C343.8 188.1 343.8 184.6 346 182.3L422.6 105.7C436.8 91.5 444.6 72.6 444.6 52.6V181.1zM67.4 19.9V330.9C67.4 350.9 75.2 369.8 89.4 384L166 460.6C168.2 462.8 168.2 466.3 166 468.5L89.4 545.2C75.2 559.4 67.4 578.3 67.4 598.3V607.6L6.9 547.1C-2.3 537.8-2.3 527.1 6.9 517.8L67.4 457.3V19.9z"/></svg>
                                        PIX
                                    </span>
                                </div>

                                <div class="corpo-cartao-gateway">
                                    <div class="icone-gateway <?php echo gwIconClass($g['nome']); ?>">
                                        <?php echo gwIconLetter($g['nome']); ?>
                                    </div>
                                    <div class="nome-gateway"><?php echo htmlspecialchars($g['titulo']); ?></div>
                                    <div class="status-gateway <?php echo $g['conectado'] ? 'conectado' : 'pendente'; ?>">
                                        <span class="ponto-status"></span>
                                        <?php echo $g['conectado'] ? 'Conectado' : 'Credenciais pendentes'; ?>
                                    </div>
                                </div>

                                <div class="acoes-cartao-gateway">
                                    <button type="button" class="botao" onclick="openModal('<?php echo $modal_id; ?>')">
                                        <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.1 4.9A10 10 0 0 0 4.9 19.1M19.1 19.1A10 10 0 0 0 4.9 4.9"/></svg>
                                        Configurar
                                    </button>
                                    <button type="button" class="btn-icon desativar" title="Desativar" onclick="desativarGateway(<?php echo $g['id']; ?>, '<?php echo htmlspecialchars($g['titulo'], ENT_QUOTES); ?>', this)">
                                        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                    </button>
                                </div>
                            </div>

                            <div id="<?php echo $modal_id; ?>" class="sobreposicao-modal" onclick="if(event.target===this)closeModal('<?php echo $modal_id; ?>')">
                                <div class="modal-gateway">
                                    <div class="cabecalho-modal">
                                        <div class="titulo-modal">
                                            <div class="icone-gateway <?php echo gwIconClass($g['nome']); ?>" style="width:32px;height:32px;font-size:.9rem;border-radius:8px;flex-shrink:0;">
                                                <?php echo gwIconLetter($g['nome']); ?>
                                            </div>
                                            <?php echo htmlspecialchars($g['titulo']); ?>
                                        </div>
                                        <button class="fechar-modal" onclick="closeModal('<?php echo $modal_id; ?>')">✕</button>
                                    </div>
                                    <div class="corpo-modal">
                                        <?php renderGwForm($g); ?>
                                    </div>
                                </div>
                            </div>
                            <?php $idx++; endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($gw_disponiveis)): ?>
                    <div class="secao-gateway">
                        <p class="titulo-secao-gateway">
                            <svg width="18" height="18" fill="none" stroke="var(--m)" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
                            Gateways Disponíveis
                            <span class="badge badge-neutro"><?php echo count($gw_disponiveis); ?></span>
                        </p>

                        <div class="grade-gateways-disponiveis">
                            <?php foreach ($gw_disponiveis as $g):
                                $modal_id = 'modal-' . $g['id'];
                            ?>
                            <?php
                                $cfg = $g['user_config'];
                                $tem_credenciais = !empty($cfg['client_id']);
                            ?>
                            <div class="cartao-gateway-disponivel">
                                <div class="mais-gateway">+</div>
                                <div class="icone-gateway <?php echo gwIconClass($g['nome']); ?>" style="margin:0 auto 10px;">
                                    <?php echo gwIconLetter($g['nome']); ?>
                                </div>
                                <div class="nome-gateway"><?php echo htmlspecialchars($g['titulo']); ?></div>
                                <span class="selo-metodo">
                                    <svg width="10" height="10" viewBox="0 0 512 512" fill="currentColor"><path d="M242.4 292.5C247.8 287.1 257.1 287.1 262.5 292.5L339.5 369.5C353.7 383.7 372.6 391.5 392.6 391.5H407.7L310.6 488.6C280.3 518.1 231.1 518.1 200.8 488.6L103.3 391.2H112.6C132.6 391.2 151.5 383.4 165.7 369.2L242.4 292.5zM262.5 218.9C257.1 224.4 247.8 224.4 242.4 218.9L165.7 142.2C151.5 127.1 132.6 120.2 112.6 120.2H103.3L200.7 22.8C231.1-7.6 280.3-7.6 310.6 22.8L407.7 119.9H392.6C372.6 119.9 353.7 127.7 339.5 141.9L262.5 218.9zM444.6 181.1L505.1 241.5C514.3 250.8 514.3 261.5 505.1 270.8L444.6 331.3V319.9C444.6 299.9 436.8 281 422.6 266.8L346 190.3C343.8 188.1 343.8 184.6 346 182.3L422.6 105.7C436.8 91.5 444.6 72.6 444.6 52.6V181.1zM67.4 19.9V330.9C67.4 350.9 75.2 369.8 89.4 384L166 460.6C168.2 462.8 168.2 466.3 166 468.5L89.4 545.2C75.2 559.4 67.4 578.3 67.4 598.3V607.6L6.9 547.1C-2.3 537.8-2.3 527.1 6.9 517.8L67.4 457.3V19.9z"/></svg>
                                    PIX
                                </span>
                                <div class="acoes-gateway-disponivel">
                                    <button type="button" class="botao" onclick="openModal('<?php echo $modal_id; ?>')">
                                        <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.1 4.9A10 10 0 0 0 4.9 19.1M19.1 19.1A10 10 0 0 0 4.9 4.9"/></svg>
                                        Configurar
                                    </button>
                                    <?php if ($tem_credenciais): ?>
                                    <button type="button" class="btn-icon ativar" title="Ativar" onclick="ativarGateway(<?php echo $g['id']; ?>, this)">
                                        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div id="<?php echo $modal_id; ?>" class="sobreposicao-modal" onclick="if(event.target===this)closeModal('<?php echo $modal_id; ?>')">
                                <div class="modal-gateway">
                                    <div class="cabecalho-modal">
                                        <div class="titulo-modal">
                                            <div class="icone-gateway <?php echo gwIconClass($g['nome']); ?>" style="width:32px;height:32px;font-size:.9rem;border-radius:8px;flex-shrink:0;">
                                                <?php echo gwIconLetter($g['nome']); ?>
                                            </div>
                                            <?php echo htmlspecialchars($g['titulo']); ?>
                                        </div>
                                        <button class="fechar-modal" onclick="closeModal('<?php echo $modal_id; ?>')">✕</button>
                                    </div>
                                    <div class="corpo-modal">
                                        <?php renderGwForm($g); ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <form id="form-desativar" method="POST" style="display:none;">
                        <?php echo campoCsrf(); ?>
                        <input type="hidden" name="acao"       value="desativar_user">
                        <input type="hidden" name="gateway_id" id="desativar-gw-id">
                    </form>
                    <form id="form-ativar" method="POST" style="display:none;">
                        <?php echo campoCsrf(); ?>
                        <input type="hidden" name="acao"       value="ativar_user">
                        <input type="hidden" name="gateway_id" id="ativar-gw-id">
                    </form>

                <?php endif; ?>

            <?php echo paginador($total_gateways_usuario, $por_pagina); ?>
        </div>
    </main>
</div>

<script src="assets/js/tema.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/tema.js'); ?>"></script>
<script>
function openModal(id)  { document.getElementById(id).classList.add('aberto');    document.body.style.overflow='hidden'; }
function closeModal(id) { document.getElementById(id).classList.remove('aberto'); document.body.style.overflow='';       }

// Desabilita o botão assim que o clique dispara a navegação -- clicar de novo antes da
// página recarregar (ativar/desativar/ativar em sequência rápida) cancela a requisição
// em andamento e, nesse servidor, isso às vezes deixa a conexão HTTP/2 reaproveitada num
// estado ruim (ERR_HTTP2_PROTOCOL_ERROR até o navegador abrir uma conexão nova).
function desativarGateway(id, nome, btn) {
    if (!confirm('Desativar "' + nome + '"? Seus bots deixarão de usar este gateway.')) return;
    if (btn) btn.disabled = true;
    document.getElementById('desativar-gw-id').value = id;
    document.getElementById('form-desativar').submit();
}

function ativarGateway(id, btn) {
    if (btn) btn.disabled = true;
    document.getElementById('ativar-gw-id').value = id;
    document.getElementById('form-ativar').submit();
}

(function () {
    const grid = document.getElementById('gw-sortable');
    if (!grid) return;

    let dragged = null;

    grid.addEventListener('dragstart', e => {
        dragged = e.target.closest('.cartao-gateway-ativo');
        if (!dragged) return;
        dragged.classList.add('arrastando');
        e.dataTransfer.effectAllowed = 'move';
    });

    grid.addEventListener('dragend', () => {
        if (!dragged) return;
        dragged.classList.remove('arrastando');
        grid.querySelectorAll('.cartao-gateway-ativo').forEach(c => c.classList.remove('sobre-alvo'));
        dragged = null;
        salvarOrdem();
    });

    grid.addEventListener('dragover', e => {
        e.preventDefault();
        const target = e.target.closest('.cartao-gateway-ativo');
        if (!target || target === dragged) return;
        grid.querySelectorAll('.cartao-gateway-ativo').forEach(c => c.classList.remove('sobre-alvo'));
        target.classList.add('sobre-alvo');
        const after = e.clientY > target.getBoundingClientRect().top + target.offsetHeight / 2;
        grid.insertBefore(dragged, after ? target.nextElementSibling : target);
    });

    grid.addEventListener('dragleave', e => {
        const target = e.target.closest('.cartao-gateway-ativo');
        if (target) target.classList.remove('sobre-alvo');
    });

    function salvarOrdem() {
        const cards = [...grid.querySelectorAll('.cartao-gateway-ativo')];
        cards.forEach((card, i) => {
            const badge = card.querySelector('.selo-prioridade');
            if (!badge) return;
            const is_primary = i === 0;
            badge.className = 'selo-prioridade' + (is_primary ? ' principal' : '');
            badge.innerHTML = (i + 1) + '&nbsp;·&nbsp;' + (is_primary ? 'Principal' : 'Fallback ' + i);
            card.classList.toggle('principal', is_primary);
        });

        const ordem = cards.map(c => c.dataset.gwId);
        const fd = new FormData();
        fd.append('acao', 'reordenar_gateways');
        fd.append('csrf_token', <?php echo json_encode(csrfToken()); ?>);
        ordem.forEach((id, i) => fd.append('ordem[' + i + ']', id));
        fetch('gateways.php', { method: 'POST', body: fd });
    }
})();
</script>
</body>
</html>
