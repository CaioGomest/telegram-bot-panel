<?php
declare(strict_types=1);

require_once __DIR__ . '/../funcoes/usuario.php';
require_once __DIR__ . '/../funcoes/google_auth.php';
require_once __DIR__ . '/../funcoes/redirecionadores.php';
require_once __DIR__ . '/../funcoes/email.php';
verificarAdmin();
$caminho_base = '../';

$mensagem = '';
$tipo_mensagem = '';
$mensagem_google = '';
$tipo_mensagem_google = '';
$mensagem_email = '';
$tipo_mensagem_email = '';
$mensagem_dominios = '';
$tipo_mensagem_dominios = '';
$mensagem_push = '';
$tipo_mensagem_push = '';
$mensagem_manutencao = '';
$tipo_mensagem_manutencao = '';

/** Lê a lista de domínios do redirecionamento (um por linha, ou separados por vírgula/espaço). Lança RuntimeException no primeiro inválido. */
function lerDominiosRedirecionamento(string $bruto): array
{
    $validos = [];
    foreach (preg_split('/[\r\n,;\s]+/', $bruto) ?: [] as $dominio) {
        $dominio = strtolower(trim($dominio));
        if ($dominio === '') {
            continue;
        }
        if (!dominioRedirecionamentoValido($dominio)) {
            throw new RuntimeException('Domínio de redirecionamento inválido: ' . $dominio);
        }
        $validos[$dominio] = $dominio;
    }
    return array_values($validos);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'identidade') {
    verificarCsrf();
    try {
        $nome = trim($_POST['nome_sistema'] ?? '');
        if ($nome === '') {
            throw new RuntimeException('O nome do sistema não pode ficar vazio.');
        }

        $cor_primaria = trim($_POST['cor_primaria'] ?? '');
        if (!preg_match('/^#[0-9a-f]{6}$/i', $cor_primaria)) {
            throw new RuntimeException('Cor inválida.');
        }

        // Os domínios do redirecionamento têm aba e formulário próprios: aqui só entram se o campo vier no envio (formulário antigo).
        $dominios_validos = array_key_exists('dominios_redirecionamento', $_POST) ? lerDominiosRedirecionamento((string) $_POST['dominios_redirecionamento']) : null;

        // Tudo validado: só agora grava. Antes cada campo era gravado assim que passava, então um erro em
        // cor/domínio deixava o nome já alterado ("Cor inválida" e mesmo assim o nome mudava).
        definirConfigSistema('nome_sistema', mb_substr($nome, 0, 60));
        definirConfigSistema('cor_primaria', strtolower($cor_primaria));
        definirConfigSistema('login_marca_animada', isset($_POST['login_marca_animada']) ? '1' : '0');
        // Stories: só muda se o campo vier no envio (o bloco do formulário está comentado enquanto o recurso está desligado).
        if (array_key_exists('stories_campo', $_POST)) {
            definirConfigSistema('stories_ativos', isset($_POST['stories_ativos']) ? '1' : '0');
        }
        if ($dominios_validos !== null) {
            definirConfigSistema('dominios_redirecionamento', implode("\n", $dominios_validos));
        }

        if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            definirConfigSistema('logo', salvarArquivoMarca($_FILES['logo'], 'marca_logo'));
        }
        if (isset($_FILES['favicon']) && $_FILES['favicon']['error'] === UPLOAD_ERR_OK) {
            definirConfigSistema('favicon', salvarArquivoMarca($_FILES['favicon'], 'marca_favicon'));
        }

        if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK || isset($_FILES['favicon']) && $_FILES['favicon']['error'] === UPLOAD_ERR_OK) {
            // Os ícones dos avisos e do app saem da logo: com os avisos ligados, regera na hora (a versão nova vai no ?v= e troca o ícone sem mexer no service worker).
            try {
                require_once __DIR__ . '/../funcoes/push.php';
                if (pushLerConfig($pdo, 'push_ativo') === '1') {
                    require_once __DIR__ . '/../funcoes/push_icones.php';
                    pushIconesGerar();
                }
            } catch (Throwable $e) {
                error_log('[push_icones] ' . $e->getMessage());
            }
        }

        registrarAtividade((int) $_SESSION['usuario_id'], 'sistema', 'Identidade visual', 'Nome/logo/favicon do painel atualizados.');
        header('Location: configuracoes?salvo=1');
        exit;
    } catch (RuntimeException $e) {
        $mensagem = $e->getMessage();
        $tipo_mensagem = 'erro';
    }
}

// Avisos push (aba Notificações): interruptor geral + contato VAPID, gerar as chaves e regenerar (invalida todos os aparelhos).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['acao'] ?? '', ['push_salvar', 'push_gerar', 'push_regenerar'], true)) {
    verificarCsrf();
    require_once __DIR__ . '/../funcoes/push.php';
    $acao_push = $_POST['acao'];
    try {
        if ($acao_push === 'push_salvar') {
            $contato = trim((string) ($_POST['push_contato'] ?? ''));
            if ($contato !== '' && !preg_match('#^https://[A-Za-z0-9.-]+(/\S*)?$#', $contato) && !preg_match('/^mailto:[^\s@]+@[^\s@]+\.[^\s@]+$/', $contato)) {
                throw new RuntimeException('Contato inválido: use https://seusite.com.br ou mailto:voce@seusite.com.br (a Apple recusa os avisos com um contato inválido).');
            }
            $ligar = isset($_POST['push_ativo']);
            if ($ligar && !pushGarantirChaves($pdo)) {
                throw new RuntimeException('Não foi possível gerar as chaves dos avisos (OpenSSL).');
            }
            definirConfigSistema('push_contato', $contato);
            definirConfigSistema('push_ativo', $ligar ? '1' : '0');
            if ($ligar) {
                try {
                    require_once __DIR__ . '/../funcoes/push_icones.php';
                    pushIconesGerar();
                } catch (Throwable $e) {
                    error_log('[push_icones] ' . $e->getMessage());
                }
            }
            registrarAtividade((int) $_SESSION['usuario_id'], 'sistema', 'Avisos push', $ligar ? 'Avisos push LIGADOS para os vendedores.' : 'Avisos push DESLIGADOS.');
            header('Location: configuracoes?salvo_push=1#notificacoes');
            exit;
        }
        if ($acao_push === 'push_gerar') {
            if (!pushGarantirChaves($pdo)) {
                throw new RuntimeException('Não foi possível gerar as chaves dos avisos (OpenSSL).');
            }
            registrarAtividade((int) $_SESSION['usuario_id'], 'sistema', 'Avisos push', 'Chaves dos avisos push geradas.');
            header('Location: configuracoes?salvo_push=2#notificacoes');
            exit;
        }
        if (trim((string) ($_POST['confirmacao'] ?? '')) !== 'REGENERAR') {
            throw new RuntimeException('Para regenerar as chaves digite REGENERAR no campo de confirmação.');
        }
        if (!pushGarantirChaves($pdo, true)) {
            throw new RuntimeException('Não foi possível gerar as chaves novas (OpenSSL).');
        }
        registrarAtividade((int) $_SESSION['usuario_id'], 'sistema', 'Avisos push', 'Chaves dos avisos push REGENERADAS: todos os aparelhos precisam ativar os avisos de novo.');
        header('Location: configuracoes?salvo_push=3#notificacoes');
        exit;
    } catch (RuntimeException $e) {
        $mensagem_push = $e->getMessage();
        $tipo_mensagem_push = 'erro';
    }
}

// Modo manutenção (aba Manutenção): ligar/atualizar e desligar. O estado mora num arquivo (funcoes/manutencao.php), não no banco.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['acao'] ?? '', ['manutencao_ligar', 'manutencao_desligar'], true)) {
    verificarCsrf();
    require_once __DIR__ . '/../funcoes/manutencao.php';
    try {
        if ($_POST['acao'] === 'manutencao_ligar') {
            $texto_manutencao = trim((string) ($_POST['manutencao_mensagem'] ?? ''));
            if (mb_strlen($texto_manutencao) > MANUTENCAO_MENSAGEM_MAX) {
                throw new RuntimeException('A mensagem pode ter no máximo ' . MANUTENCAO_MENSAGEM_MAX . ' caracteres.');
            }
            $volta_bruto = trim((string) ($_POST['manutencao_volta_minutos'] ?? ''));
            if ($volta_bruto !== '' && (!ctype_digit($volta_bruto) || (int) $volta_bruto > MANUTENCAO_VOLTA_MAX_MINUTOS)) {
                throw new RuntimeException('A previsão de volta é em minutos, de 1 a ' . MANUTENCAO_VOLTA_MAX_MINUTOS . ' (7 dias). Deixe em branco para não informar.');
            }
            $modo_manutencao = ($_POST['manutencao_modo'] ?? '') === 'total' ? 'total' : 'painel';
            $estado_manutencao = manutencaoLigar($texto_manutencao, $modo_manutencao, $volta_bruto === '' ? 0 : (int) $volta_bruto, (string) ($_SESSION['usuario_email'] ?? 'admin'));
            manutencaoLiberarNavegador($estado_manutencao);   // quem ligou continua enxergando o sistema
            registrarAtividade((int) $_SESSION['usuario_id'], 'sistema', 'Manutenção', 'Modo manutenção LIGADO (' . ($modo_manutencao === 'total' ? 'tudo' : 'só o painel') . ').');
            header('Location: configuracoes?salvo_manutencao=1#manutencao');
            exit;
        }
        manutencaoDesligar((string) ($_SESSION['usuario_email'] ?? 'admin'));
        registrarAtividade((int) $_SESSION['usuario_id'], 'sistema', 'Manutenção', 'Modo manutenção DESLIGADO.');
        header('Location: configuracoes?salvo_manutencao=2#manutencao');
        exit;
    } catch (RuntimeException $e) {
        $mensagem_manutencao = $e->getMessage();
        $tipo_mensagem_manutencao = 'erro';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'google') {
    verificarCsrf();
    $client_id = trim($_POST['google_client_id'] ?? '');
    $client_secret = trim($_POST['google_client_secret'] ?? '');
    if ($client_id === '') {
        $mensagem_google = 'Preencha o Client ID.';
        $tipo_mensagem_google = 'erro';
    } elseif (definirCredenciaisGoogle($client_id, $client_secret)) {
        registrarAtividade((int) $_SESSION['usuario_id'], 'sistema', 'Login com Google', 'Credenciais do login com Google atualizadas.');
        header('Location: configuracoes?salvo_google=1');
        exit;
    } else {
        $mensagem_google = 'Erro ao salvar as credenciais.';
        $tipo_mensagem_google = 'erro';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'dominios') {
    verificarCsrf();
    try {
        $lista_dominios = lerDominiosRedirecionamento((string) ($_POST['dominios_redirecionamento'] ?? ''));
        definirConfigSistema('dominios_redirecionamento', implode("\n", $lista_dominios));
        registrarAtividade((int) $_SESSION['usuario_id'], 'sistema', 'Domínios de redirecionamento', count($lista_dominios) . ' domínio(s) de redirecionamento salvos.');
        header('Location: configuracoes?salvo_dominios=1#dominios');
        exit;
    } catch (RuntimeException $e) {
        $mensagem_dominios = $e->getMessage();
        $tipo_mensagem_dominios = 'erro';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['acao'] ?? '', ['email', 'email_teste', 'email_fila_reprocessar'], true)) {
    verificarCsrf();
    $acao_email = $_POST['acao'];
    if ($acao_email === 'email') {
        $host = trim($_POST['smtp_host'] ?? '');
        $porta = (int) ($_POST['smtp_porta'] ?? 0);
        $seguranca = $_POST['smtp_seguranca'] ?? 'ssl';
        $usuario_smtp = trim($_POST['smtp_usuario'] ?? '');
        $senha_smtp = (string) ($_POST['smtp_senha'] ?? '');
        $remetente = trim($_POST['email_remetente'] ?? '');
        $remetente_nome = trim($_POST['email_remetente_nome'] ?? '');
        $url_base = rtrim(trim($_POST['url_base'] ?? ''), '/');
        if ($host !== '' && !preg_match('/^[A-Za-z0-9.\-]+$/', $host)) {
            $mensagem_email = 'Servidor SMTP inválido (use só o endereço, ex.: smtp.hostinger.com).';
        } elseif ($host !== '' && ($porta < 1 || $porta > 65535)) {
            $mensagem_email = 'Porta inválida.';
        } elseif (!in_array($seguranca, ['ssl', 'tls', 'nenhuma'], true)) {
            $mensagem_email = 'Segurança inválida.';
        } elseif ($remetente !== '' && !filter_var($remetente, FILTER_VALIDATE_EMAIL)) {
            $mensagem_email = 'E-mail do remetente inválido.';
        } elseif ($url_base !== '' && !preg_match('#^https?://[^\s/]+(/[^\s]*)?$#i', $url_base)) {
            $mensagem_email = 'URL do painel inválida (ex.: https://painel.seusite.com.br).';
        } else {
            $ok = definirConfigSistema('smtp_host', $host)
                && definirConfigSistema('smtp_porta', $porta > 0 ? (string) $porta : '')
                && definirConfigSistema('smtp_seguranca', $seguranca)
                && definirConfigSistema('smtp_usuario', $usuario_smtp)
                && definirConfigSistema('email_remetente', $remetente)
                && definirConfigSistema('email_remetente_nome', $remetente_nome)
                && definirConfigSistema('url_base', $url_base);
            if ($ok && $senha_smtp !== '') {
                $ok = definirConfigSistema('smtp_senha', criptografarSegredo($senha_smtp));
            }
            if ($ok) {
                registrarAtividade((int) $_SESSION['usuario_id'], 'sistema', 'E-mail', 'Configurações de envio de e-mail atualizadas.');
                header('Location: configuracoes?salvo_email=1#email');
                exit;
            }
            $mensagem_email = 'Erro ao salvar as configurações de e-mail.';
        }
        $tipo_mensagem_email = 'erro';
    } elseif ($acao_email === 'email_teste') {
        $destino_teste = trim($_POST['email_teste_destino'] ?? '');
        emailContextoEnvio(['tipo' => 'teste', 'origem' => 'teste']);
        if (!filter_var($destino_teste, FILTER_VALIDATE_EMAIL)) {
            $mensagem_email = 'Informe um e-mail válido para o teste.';
            $tipo_mensagem_email = 'erro';
        } elseif (enviarEmail($destino_teste, 'Teste de e-mail - ' . nomeSistema(), emailLayout('Teste de envio', '<p style="margin:0;">Se você está lendo isto, o envio de e-mail do painel está funcionando. ✅</p>'))) {
            $mensagem_email = 'E-mail de teste enviado para ' . $destino_teste . '. Confira a caixa de entrada (e o spam).';
            $tipo_mensagem_email = 'sucesso';
        } else {
            $mensagem_email = 'Falha no envio: ' . rtrim((string) (emailUltimoErro() ?: 'erro desconhecido'), '.') . '.';
            $tipo_mensagem_email = 'erro';
        }
    } else {
        try {
            $pdo->exec("UPDATE fila_emails SET status = 'pendente', tentativas = 0, proximo_envio = NOW() WHERE status = 'falhou'");
            $mensagem_email = 'E-mails com falha recolocados na fila: o cron tenta de novo no próximo minuto.';
            $tipo_mensagem_email = 'sucesso';
        } catch (Throwable $e) {
            $mensagem_email = 'Fila de e-mails indisponível (rode "Atualizar Banco").';
            $tipo_mensagem_email = 'erro';
        }
    }
}

if (isset($_GET['salvo_email'])) {
    $mensagem_email = 'Configurações de e-mail salvas.';
    $tipo_mensagem_email = 'sucesso';
}

// O resultado do teste de envio (e do reenvio da fila) aparece colado no botão que a pessoa acabou de clicar. No topo da seção
// ele ficava fora da tela: o teste falhava e parecia que "não aconteceu nada".
$msg_junto_ao_teste = in_array($acao_email ?? '', ['email_teste', 'email_fila_reprocessar'], true);

if (isset($_GET['salvo'])) {
    $mensagem = 'Identidade visual atualizada.';
    $tipo_mensagem = 'sucesso';
}
if (isset($_GET['salvo_google'])) {
    $mensagem_google = 'Credenciais do Google atualizadas.';
    $tipo_mensagem_google = 'sucesso';
}
if (isset($_GET['salvo_push'])) {
    $mensagem_push = ['1' => 'Configuração dos avisos salva.', '2' => 'Chaves geradas.', '3' => 'Chaves novas geradas. Todos os aparelhos precisam ativar os avisos de novo.'][$_GET['salvo_push']] ?? 'Salvo.';
    $tipo_mensagem_push = 'sucesso';
}
if (isset($_GET['salvo_dominios'])) {
    $mensagem_dominios = 'Domínios do redirecionamento atualizados.';
    $tipo_mensagem_dominios = 'sucesso';
}
if (isset($_GET['salvo_manutencao'])) {
    $mensagem_manutencao = $_GET['salvo_manutencao'] === '2' ? 'Manutenção desligada. O sistema voltou ao normal para todo mundo.' : 'Manutenção ligada. Só quem tem a liberação (este navegador e o link abaixo) enxerga o sistema normal.';
    $tipo_mensagem_manutencao = 'sucesso';
}

// Aba que abre sozinha: a do formulário que acabou de responder (sucesso ou erro). Um #hash na URL manda mais que isto (feito no JavaScript).
$aba_inicial = 'identidade';
if ($mensagem_manutencao !== '') {
    $aba_inicial = 'manutencao';
} elseif ($mensagem_push !== '') {
    $aba_inicial = 'notificacoes';
} elseif ($mensagem_dominios !== '') {
    $aba_inicial = 'dominios';
} elseif ($mensagem_google !== '') {
    $aba_inicial = 'google';
} elseif ($mensagem_email !== '') {
    $aba_inicial = 'email';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configurações - <?php echo htmlspecialchars(nomeSistema()); ?></title>
    <?php include __DIR__ . '/../tema_inline.php'; ?>
    <link rel="stylesheet" href="../assets/css/painel.css?v=<?php echo @filemtime(__DIR__.'/../assets/css/painel.css'); ?>">
    <link rel="stylesheet" href="../assets/css/push.css?v=<?php echo @filemtime(__DIR__.'/../assets/css/push.css'); ?>">
</head>
<body>
<div class="layout-painel">
    <?php include __DIR__ . '/../barra_lateral.php'; ?>

    <main class="conteudo-principal">
        <div class="cabecalho-pagina">
            <div>
                <h1>Configurações</h1>
                <p>Identidade visual, domínios e integrações do painel.</p>
            </div>
            <div class="acoes-cabecalho">
                <button type="button" class="alternador-tema" onclick="alternarTema()" aria-label="Alternar tema">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"></path></svg>
                    Tema
                </button>
            </div>
        </div>

        <?php
        $google_ativo = googleLoginConfigurado();
        $smtp_ativo = emailSmtpConfigurado();
        $logo_url = logoSistema($caminho_base);
        $favicon_url = faviconSistema($caminho_base);
        $cor_atual = corPrimariaSistema();
        $dominios_lista = dominiosRedirecionamento();
        $host_painel = (string) (parse_url(urlBasePainel(), PHP_URL_HOST) ?: '');
        $ip_painel = ($host_painel !== '' && !filter_var($host_painel, FILTER_VALIDATE_IP)) ? gethostbyname($host_painel) : $host_painel;
        if ($ip_painel === $host_painel && !filter_var($ip_painel, FILTER_VALIDATE_IP)) {
            $ip_painel = '';   // não resolveu
        }
        $fila = resumoFilaEmails();
        $envios24 = resumoEnviosEmail24h();
        require_once __DIR__ . '/../funcoes/push.php';
        $push_ligado = pushLerConfig($pdo, 'push_ativo') === '1';
        $push_tem_chaves = pushVapidPublica($pdo) !== '';
        $push_resumo = pushResumoAdmin($pdo);
        $push_impressao = pushImpressaoDigital($pdo);
        $push_contato_salvo = pushLerConfig($pdo, 'push_contato');
        $push_contato_efetivo = pushContato($pdo);
        $push_cripto_ok = function_exists('chaveCriptografiaDisponivel') ? chaveCriptografiaDisponivel() : true;
        require_once __DIR__ . '/../funcoes/push_icones.php';
        $push_icone_fonte = pushIconesFonte();
        $push_icones_ok = pushIconesCompletos();
        $push_icone_v = pushIconesConfig('push_icone_v');
        require_once __DIR__ . '/../funcoes/manutencao.php';
        $manut = manutencaoLer(true);
        $manut_link = ($manut['ativo'] && $manut['segredo'] !== '') ? rtrim(urlBasePainel(), '/') . '/?liberar=' . $manut['segredo'] : '';
        $manut_agora = time();
        ?>

        <div class="abas-status config-abas" role="tablist" aria-label="Seções das configurações">
            <a href="#identidade" class="aba-status" role="tab" data-aba="identidade">Identidade visual</a>
            <a href="#dominios" class="aba-status" role="tab" data-aba="dominios">Domínios<?php if ($dominios_lista): ?> <span class="aba-contagem"><?php echo count($dominios_lista); ?></span><?php endif; ?></a>
            <a href="#google" class="aba-status" role="tab" data-aba="google">Login com Google <span class="ponto-estado<?php echo $google_ativo ? ' ok' : ''; ?>" title="<?php echo $google_ativo ? 'Ativo' : 'Não configurado'; ?>"></span></a>
            <a href="#notificacoes" class="aba-status" role="tab" data-aba="notificacoes">Notificações <span class="ponto-estado<?php echo ($push_ligado && $push_tem_chaves) ? ' ok' : ''; ?>" title="<?php echo ($push_ligado && $push_tem_chaves) ? 'Avisos ligados' : 'Avisos desligados'; ?>"></span></a>
            <a href="#email" class="aba-status" role="tab" data-aba="email">E-mail <span class="ponto-estado<?php echo $smtp_ativo ? ' ok' : ''; ?>" title="<?php echo $smtp_ativo ? 'SMTP ativo' : 'SMTP não configurado'; ?>"></span></a>
            <a href="#manutencao" class="aba-status" role="tab" data-aba="manutencao">Manutenção<?php if ($manut['ativo']): ?> <span class="aba-contagem">ligada</span><?php endif; ?></a>
        </div>

        <div id="config-abas" data-inicial="<?php echo htmlspecialchars($aba_inicial); ?>">

            <!-- ============================== IDENTIDADE VISUAL ============================== -->
            <section class="aba-painel" id="aba-identidade" data-aba="identidade">
                <div class="grade-config">
                    <div class="painel">
                        <div class="painel-cabecalho">
                            <h2>Identidade visual</h2>
                        </div>
                        <?php if ($mensagem): ?>
                            <div class="aviso aviso-<?php echo $tipo_mensagem; ?>"><?php echo htmlspecialchars($mensagem); ?></div>
                        <?php endif; ?>

                        <form method="POST" enctype="multipart/form-data" id="form-identidade" class="bloco-form" data-confirmar="Confirma salvar essas alterações de identidade visual? Isso muda o nome, logo, favicon e/ou cor de destaque pra todo mundo que usa o painel.">
                            <?php echo campoCsrf(); ?>
                            <input type="hidden" name="acao" value="identidade">

                            <div class="campo">
                                <label for="nome_sistema">Nome do sistema</label>
                                <input type="text" id="nome_sistema" name="nome_sistema" maxlength="60" required value="<?php echo htmlspecialchars(nomeSistema()); ?>">
                                <small>Aparece na barra lateral, no topo do celular, no título das abas do navegador e nos e-mails. <span id="contagem-nome" class="mono"></span></small>
                            </div>

                            <div class="grade grade-2">
                                <div class="campo arquivo-campo">
                                    <label for="logo">Logo</label>
                                    <div class="arquivo-linha">
                                        <img id="logo-atual" class="arquivo-miniatura" src="<?php echo htmlspecialchars($logo_url); ?>" alt="Logo atual" onerror="this.style.visibility='hidden'">
                                        <div class="arquivo-acoes">
                                            <label for="logo" class="botao">Trocar logo</label>
                                            <span class="texto-suave arquivo-nome" id="logo-nome">Usando a atual</span>
                                        </div>
                                        <input type="file" id="logo" name="logo" accept=".png,.jpg,.jpeg,.webp" class="arquivo-oculto">
                                    </div>
                                    <small>PNG, JPG ou WEBP, até 2 MB. Quadrada fica melhor.</small>
                                </div>
                                <div class="campo arquivo-campo">
                                    <label for="favicon">Favicon</label>
                                    <div class="arquivo-linha">
                                        <img id="favicon-atual" class="arquivo-miniatura arquivo-miniatura-pequena" src="<?php echo htmlspecialchars($favicon_url); ?>" alt="Favicon atual" onerror="this.style.visibility='hidden'">
                                        <div class="arquivo-acoes">
                                            <label for="favicon" class="botao">Trocar favicon</label>
                                            <span class="texto-suave arquivo-nome" id="favicon-nome">Usando o atual</span>
                                        </div>
                                        <input type="file" id="favicon" name="favicon" accept=".png,.ico,.jpg,.jpeg,.webp" class="arquivo-oculto">
                                    </div>
                                    <small>Ícone da aba do navegador. Sem favicon próprio, o sistema usa a logo.</small>
                                </div>
                            </div>

                            <div class="campo">
                                <label for="cor_primaria">Cor de destaque</label>
                                <div class="cor-linha">
                                    <input type="color" id="cor_primaria" name="cor_primaria" value="<?php echo htmlspecialchars($cor_atual); ?>">
                                    <span class="mono cor-hex" id="cor-hex"><?php echo htmlspecialchars(strtoupper($cor_atual)); ?></span>
                                    <div class="chips" id="cores-sugeridas" aria-label="Cores sugeridas">
                                        <?php foreach (['#ff6a1a', '#3b82f6', '#22c55e', '#a855f7', '#ef4444', '#ec4899', '#eab308'] as $c): ?>
                                            <button type="button" class="amostra-cor" data-cor="<?php echo $c; ?>" style="background:<?php echo $c; ?>;" aria-label="Usar a cor <?php echo $c; ?>"></button>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <small>Usada em botões, links e destaques no painel inteiro (claro e escuro).</small>
                            </div>

                            <div class="campo">
                                <label class="opcao-ativar-gateway">
                                    <input type="checkbox" name="login_marca_animada" value="1" <?php echo marcaAnimadaLogin() ? 'checked' : ''; ?>>
                                    <span>Mascote animado do Coyote no login e no cadastro</span>
                                </label>
                                <small>No lugar da logo, a tela de entrada mostra o Coyote com efeitos de luz. Deixe desligado se esta instalação não for do Coyote.</small>
                            </div>

                            <?php // STORIES DESATIVADOS (20/10/2026, decisão do dono). Para o recurso voltar: apague as duas linhas que cercam o bloco abaixo (a de abertura, que começa com <?php e barra-asterisco, e a de fechamento), marque a opção e clique em Salvar identidade. Desligado, a barra some do dashboard e a API de stories recusa. ?>
                            <?php /*
                            <div class="campo">
                                <input type="hidden" name="stories_campo" value="1">
                                <label class="opcao-ativar-gateway">
                                    <input type="checkbox" name="stories_ativos" value="1" <?php echo storiesAtivos() ? 'checked' : ''; ?>>
                                    <span>Stories no dashboard dos usuários</span>
                                </label>
                                <small>Mostra a barra de stories no topo do dashboard e deixa os usuários publicarem fotos e vídeos (somem em 24 h). Desligado, nada disso aparece.</small>
                            </div>
                            */ ?>

                            <div class="linha-acoes">
                                <button type="submit" class="botao botao-primario">Salvar identidade</button>
                            </div>
                        </form>
                    </div>

                    <div class="coluna-lateral">
                        <div class="painel previa" id="previa" style="--previa-cor: <?php echo htmlspecialchars($cor_atual); ?>;">
                            <div class="painel-cabecalho"><h2>Pré-visualização</h2></div>
                            <p class="texto-suave previa-dica">Muda na hora conforme você edita. Só vale para todos depois de salvar.</p>

                            <div class="previa-rotulo">Barra lateral</div>
                            <div class="previa-lateral">
                                <img id="previa-logo" src="<?php echo htmlspecialchars($logo_url); ?>" alt="" onerror="this.style.visibility='hidden'">
                                <strong id="previa-nome"><?php echo htmlspecialchars(nomeSistema()); ?></strong>
                                <span class="previa-item-ativo">Visão geral</span>
                            </div>

                            <div class="previa-rotulo">Aba do navegador</div>
                            <div class="previa-aba">
                                <img id="previa-favicon" src="<?php echo htmlspecialchars($favicon_url); ?>" alt="" onerror="this.style.visibility='hidden'">
                                <span id="previa-titulo"><?php echo htmlspecialchars(nomeSistema()); ?> — Painel</span>
                            </div>

                            <div class="previa-rotulo">Botões e destaques</div>
                            <div class="previa-botoes">
                                <span class="previa-botao">Salvar</span>
                                <span class="previa-link">Link de exemplo</span>
                                <span class="previa-selo">Ativo</span>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ============================== DOMÍNIOS DO REDIRECIONAMENTO ============================== -->
            <section class="aba-painel" id="aba-dominios" data-aba="dominios">
                <div class="grade-config">
                    <div class="painel">
                        <div class="painel-cabecalho">
                            <h2>Domínios do redirecionamento</h2>
                            <span class="badge <?php echo $dominios_lista ? 'badge-sucesso' : 'badge-neutro'; ?>"><?php echo count($dominios_lista); ?> cadastrado(s)</span>
                        </div>
                        <?php if ($mensagem_dominios): ?>
                            <div class="aviso aviso-<?php echo $tipo_mensagem_dominios; ?>"><?php echo htmlspecialchars($mensagem_dominios); ?></div>
                        <?php endif; ?>

                        <form method="POST" id="form-dominios" class="bloco-form" data-confirmar="Confirma salvar esta lista de domínios? Os usuários passam a poder escolher só estes domínios (além do endereço do painel) nos links /l/.">
                            <?php echo campoCsrf(); ?>
                            <input type="hidden" name="acao" value="dominios">
                            <div class="campo">
                                <label for="dominios_redirecionamento">Domínios (um por linha)</label>
                                <textarea id="dominios_redirecionamento" name="dominios_redirecionamento" rows="7" placeholder="link.seudominio.com&#10;go.outrodominio.com.br"><?php echo htmlspecialchars(implode("\n", $dominios_lista)); ?></textarea>
                                <small>Além do endereço do painel (<span class="mono"><?php echo htmlspecialchars($host_painel ?: 'este painel'); ?></span>), os usuários podem escolher estes domínios para os links <span class="mono">/l/</span>. Digite só o domínio, sem <span class="mono">https://</span>.</small>
                            </div>
                            <div class="linha-acoes">
                                <button type="submit" class="botao botao-primario">Salvar domínios</button>
                            </div>
                        </form>
                    </div>

                    <div class="coluna-lateral">
                        <div class="painel">
                            <div class="painel-cabecalho"><h2>Como adicionar um domínio</h2></div>
                            <ol class="passos">
                                <li><span>No painel de DNS do domínio (onde você o comprou), crie um registro <strong>A</strong> apontando para o IP deste servidor<?php echo $ip_painel ? ': <span class="mono">' . htmlspecialchars($ip_painel) . '</span>' : ''; ?>.</span></li>
                                <li><span>Espere propagar (de 1 minuto a algumas horas) e <strong>peça ao suporte técnico para liberar o HTTPS</strong> desse domínio neste servidor.</span></li>
                                <li><span>Escreva o domínio na lista ao lado e salve. Ele aparece como opção nos links de cada usuário.</span></li>
                            </ol>
                        </div>
                        <?php if ($dominios_lista): ?>
                            <div class="painel">
                                <div class="painel-cabecalho"><h2>Como os links ficam</h2></div>
                                <div class="lista-simples">
                                    <?php foreach (array_slice($dominios_lista, 0, 6) as $d): ?>
                                        <div class="mono"><?php echo htmlspecialchars('https://' . $d . '/l/seu-link'); ?></div>
                                    <?php endforeach; ?>
                                    <?php if (count($dominios_lista) > 6): ?><div class="texto-suave">e mais <?php echo count($dominios_lista) - 6; ?>…</div><?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <!-- ============================== LOGIN COM GOOGLE ============================== -->
            <section class="aba-painel" id="aba-google" data-aba="google">
                <div class="grade-config">
                    <div class="painel">
                        <div class="painel-cabecalho">
                            <h2>Login com Google</h2>
                            <span class="badge <?php echo $google_ativo ? 'badge-sucesso' : 'badge-neutro'; ?>"><?php echo $google_ativo ? 'Ativo' : 'Não configurado'; ?></span>
                        </div>
                        <?php if ($mensagem_google): ?>
                            <div class="aviso aviso-<?php echo $tipo_mensagem_google; ?>"><?php echo htmlspecialchars($mensagem_google); ?></div>
                        <?php endif; ?>

                        <form method="POST" id="form-google" class="bloco-form" data-confirmar="Confirma salvar essas credenciais do login com Google? Se estiverem erradas, o botão de login com Google pode parar de funcionar pra quem já usa.">
                            <?php echo campoCsrf(); ?>
                            <input type="hidden" name="acao" value="google">
                            <div class="campo">
                                <label for="google_client_id">Client ID</label>
                                <input type="text" id="google_client_id" name="google_client_id" autocomplete="off" value="<?php echo htmlspecialchars(googleClientId()); ?>" placeholder="xxxxxxxxxx.apps.googleusercontent.com">
                            </div>
                            <div class="campo">
                                <label for="google_client_secret">Client Secret</label>
                                <input type="password" id="google_client_secret" name="google_client_secret" autocomplete="new-password" data-lpignore="true" data-1p-ignore placeholder="<?php echo googleClientSecret() !== '' ? '•••••••• (salvo)' : ''; ?>">
                                <?php if (googleClientSecret() !== ''): ?><small>Deixe em branco para manter o atual.</small><?php endif; ?>
                            </div>
                            <div class="linha-acoes">
                                <button type="submit" class="botao botao-primario">Salvar credenciais</button>
                            </div>
                        </form>
                    </div>

                    <div class="coluna-lateral">
                        <div class="painel">
                            <div class="painel-cabecalho"><h2>Passo a passo</h2></div>
                            <ol class="passos">
                                <li><span>Abra <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener">console.cloud.google.com/apis/credentials</a> e crie um <strong>ID do cliente OAuth 2.0</strong> do tipo <strong>Aplicativo da Web</strong>.</span></li>
                                <li><span>Em <strong>URIs de redirecionamento autorizados</strong>, cole exatamente esta URL (copie com o botão):</span></li>
                            </ol>
                            <div class="caixa-copiar">
                                <span id="google-redirect-uri" class="mono"><?php echo htmlspecialchars(googleRedirectUri()); ?></span>
                                <button type="button" class="botao" id="btn-copiar-redirect-uri">Copiar</button>
                            </div>
                            <small class="texto-suave dica-copiar">Tem que bater caractere por caractere com o cadastrado no Google, incluindo <span class="mono">https</span>. Se um dia o endereço do painel mudar, cadastre a URL nova lá também, senão o login com Google para de funcionar.</small>
                            <ol class="passos passos-continua">
                                <li><span>Copie o <strong>Client ID</strong> e o <strong>Client Secret</strong> que o Google gerou e cole no formulário ao lado.</span></li>
                                <li><span>Salve e teste na tela de login ("Continuar com Google").</span></li>
                            </ol>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ============================== NOTIFICAÇÕES (AVISOS PUSH) ============================== -->
            <section class="aba-painel" id="aba-notificacoes" data-aba="notificacoes">
                <div class="grade-config">
                    <div class="painel">
                        <div class="painel-cabecalho">
                            <h2>Avisos no celular e no navegador</h2>
                            <span class="badge <?php echo ($push_ligado && $push_tem_chaves) ? 'badge-sucesso' : 'badge-neutro'; ?>"><?php echo ($push_ligado && $push_tem_chaves) ? 'Ligado' : 'Desligado'; ?></span>
                        </div>
                        <p class="texto-suave">Cada vendedor recebe um aviso no celular ou no computador quando um <strong>Pix é gerado</strong> e quando um <strong>Pix é pago</strong>, mesmo com o painel fechado. O ícone do aviso é a logo do sistema.</p>
                        <?php if ($mensagem_push): ?>
                            <div class="aviso aviso-<?php echo $tipo_mensagem_push; ?>"><?php echo htmlspecialchars($mensagem_push); ?></div>
                        <?php endif; ?>
                        <?php if (!$push_cripto_ok): ?>
                            <div class="aviso aviso-erro">A chave de criptografia do sistema (<span class="mono">CHAVE_CRIPTOGRAFIA_GATEWAYS</span> no <span class="mono">config.php</span>) não está configurada: a chave privada dos avisos ficaria sem cifra no banco.</div>
                        <?php endif; ?>

                        <form method="POST" id="form-push" class="bloco-form">
                            <?php echo campoCsrf(); ?>
                            <input type="hidden" name="acao" value="push_salvar">
                            <div class="campo">
                                <label class="opcao-ativar-gateway">
                                    <input type="checkbox" name="push_ativo" value="1" <?php echo $push_ligado ? 'checked' : ''; ?>>
                                    <span>Ligar os avisos para os vendedores</span>
                                </label>
                                <small>Interruptor geral. Desligado, nada é enviado e o sistema nem carrega o código dos avisos. Ao ligar pela primeira vez, as chaves são geradas sozinhas.</small>
                            </div>
                            <div class="campo">
                                <label for="push_contato">Contato do serviço (VAPID)</label>
                                <input type="text" id="push_contato" name="push_contato" maxlength="200" value="<?php echo htmlspecialchars($push_contato_salvo); ?>" placeholder="<?php echo htmlspecialchars($push_contato_efetivo); ?>">
                                <small>Em branco usa <span class="mono"><?php echo htmlspecialchars($push_contato_efetivo); ?></span>. Precisa ser <span class="mono">https://seusite</span> ou <span class="mono">mailto:voce@seusite</span>: a Apple recusa os avisos se o contato for inválido.</small>
                            </div>
                            <div class="linha-acoes">
                                <button type="submit" class="botao botao-primario">Salvar</button>
                            </div>
                        </form>

                        <div class="push-chaves">
                            <div class="painel-cabecalho"><h2>Ícone dos avisos</h2></div>
                            <?php if ($push_icones_ok && $push_icone_fonte): ?>
                                <div class="push-icones">
                                    <img src="<?php echo $caminho_base; ?>uploads/pwa/icone-192.png?v=<?php echo htmlspecialchars($push_icone_v); ?>" width="64" height="64" alt="Ícone do aviso">
                                    <img src="<?php echo $caminho_base; ?>uploads/pwa/badge-96.png?v=<?php echo htmlspecialchars($push_icone_v); ?>" width="40" height="40" alt="Ícone pequeno" class="push-icone-badge">
                                    <p class="texto-suave">Gerado a partir da <strong><?php echo ['logo' => 'logo do sistema', 'favicon' => 'favicon (a logo não pôde ser usada)', 'mascote' => 'mascote do Coyote (nem a logo nem o favicon puderam ser usados)'][$push_icone_fonte['origem']] ?? 'imagem do sistema'; ?></strong> (<?php echo (int) $push_icone_fonte['largura']; ?>×<?php echo (int) $push_icone_fonte['altura']; ?> px). Ao trocar a logo em Identidade visual, o ícone é refeito sozinho.</p>
                                </div>
                                <?php if (min((int) $push_icone_fonte['largura'], (int) $push_icone_fonte['altura']) < 512): ?>
                                    <div class="aviso aviso-erro">A imagem é pequena: envie ao menos 512×512 px (PNG com fundo transparente fica melhor) para o ícone não ficar borrado.</div>
                                <?php endif; ?>
                            <?php elseif ($push_icone_fonte): ?>
                                <p class="texto-suave">Os ícones são gerados ao ligar os avisos.</p>
                            <?php else: ?>
                                <div class="aviso aviso-erro">Nenhuma imagem utilizável (PNG, JPG ou WebP) para gerar os ícones. Envie uma logo em Identidade visual.</div>
                            <?php endif; ?>
                        </div>

                        <div class="push-chaves">
                            <div class="painel-cabecalho"><h2>Chaves</h2></div>
                            <?php if ($push_tem_chaves): ?>
                                <p class="texto-suave">Impressão digital da chave pública: <span class="mono" id="push-impressao"><?php echo htmlspecialchars($push_impressao); ?></span></p>
                                <details class="push-regenerar">
                                    <summary>Regenerar as chaves</summary>
                                    <p class="texto-suave"><strong>Cuidado:</strong> com chaves novas, <strong>todos os aparelhos deixam de receber</strong> até ativarem os avisos de novo. Use só se a chave vazou.</p>
                                    <form method="POST" class="bloco-form">
                                        <?php echo campoCsrf(); ?>
                                        <input type="hidden" name="acao" value="push_regenerar">
                                        <div class="campo">
                                            <label for="push_confirmacao">Digite REGENERAR para confirmar</label>
                                            <input type="text" id="push_confirmacao" name="confirmacao" autocomplete="off" placeholder="REGENERAR">
                                        </div>
                                        <div class="linha-acoes"><button type="submit" class="botao">Regenerar chaves</button></div>
                                    </form>
                                </details>
                            <?php else: ?>
                                <p class="texto-suave">Ainda não há chaves. Elas são geradas ao ligar os avisos, ou agora:</p>
                                <form method="POST" class="bloco-form">
                                    <?php echo campoCsrf(); ?>
                                    <input type="hidden" name="acao" value="push_gerar">
                                    <div class="linha-acoes"><button type="submit" class="botao">Gerar chaves</button></div>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="coluna-lateral">
                        <div class="painel">
                            <div class="painel-cabecalho"><h2>Saúde do envio</h2><a href="configuracoes#notificacoes" class="texto-suave" style="font-size:12px">Atualizar</a></div>
                            <?php
                            $bat = $push_resumo['batimento_segundos'];
                            if ($bat === null) {
                                $bat_classe = 'badge-neutro';
                                $bat_texto = 'Sem trabalhador (os avisos saem pelo envio direto do site)';
                            } elseif ($bat < 10) {
                                $bat_classe = 'badge-sucesso';
                                $bat_texto = 'Trabalhador ativo';
                            } elseif ($bat < 180) {
                                $bat_classe = 'badge-neutro';
                                $bat_texto = 'Trabalhador sem sinal há ' . $bat . ' s';
                            } else {
                                $bat_classe = 'badge-erro';
                                $bat_texto = 'Trabalhador parado há ' . floor($bat / 60) . ' min';
                            }
                            $h24 = $push_resumo['hora24'];
                            $lat_media = ((int) ($h24['lat_n'] ?? 0)) > 0 ? round(((int) $h24['lat_soma']) / ((int) $h24['lat_n'])) : null;
                            $ap = $push_resumo['aparelhos'];
                            ?>
                            <p><span class="badge <?php echo $bat_classe; ?>"><?php echo htmlspecialchars($bat_texto); ?></span></p>
                            <dl class="push-saude">
                                <div><dt>Na fila</dt><dd><?php echo (int) $push_resumo['pendentes']; ?></dd></div>
                                <div><dt>Enviando agora</dt><dd><?php echo (int) $push_resumo['enviando']; ?></dd></div>
                                <div><dt>Enviados (24 h)</dt><dd><?php echo (int) ($h24['enviados'] ?? 0); ?></dd></div>
                                <div><dt>Falhas temporárias (24 h)</dt><dd><?php echo (int) ($h24['falhas'] ?? 0); ?></dd></div>
                                <div><dt>Erros de assinatura (24 h)</dt><dd><?php echo (int) ($h24['erros_fatais'] ?? 0); ?></dd></div>
                                <div><dt>Aparelhos removidos (24 h)</dt><dd><?php echo (int) ($h24['removidas'] ?? 0); ?></dd></div>
                                <div><dt>Resumidos em rajada (24 h)</dt><dd><?php echo (int) ($h24['agrupados'] ?? 0); ?></dd></div>
                                <div><dt>Latência média / máxima</dt><dd><?php echo $lat_media === null ? '—' : ($lat_media . ' ms / ' . (int) $h24['lat_max'] . ' ms'); ?></dd></div>
                                <div><dt>Aparelhos ativos</dt><dd>iPhone <?php echo (int) $ap['ios']; ?> · Android <?php echo (int) $ap['android']; ?> · Computador <?php echo (int) $ap['desktop']; ?></dd></div>
                            </dl>
                            <small class="texto-suave">Se o trabalhador parar ou houver muitos erros, os admins recebem um alerta no sino (no máximo 1 por hora).</small>
                        </div>
                        <div class="painel">
                            <div class="painel-cabecalho"><h2>Como funciona</h2></div>
                            <ol class="passos">
                                <li><span>O vendedor ativa os avisos em <strong>Minha Conta</strong>, em cada aparelho (no iPhone, depois de adicionar o site à Tela de Início).</span></li>
                                <li><span>O aviso nasce na fila no instante do Pix e sai na hora: pelo <strong>trabalhador</strong> (serviço <span class="mono">painel-push</span>, o caminho mais rápido) ou, sem ele, pelo próprio site logo depois de responder.</span></li>
                                <li><span>Se não der para enviar na hora, volta para a fila e tenta de novo; em rajadas, os Pix gerados viram um resumo.</span></li>
                            </ol>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ============================== E-MAIL ============================== -->
            <section class="aba-painel" id="aba-email" data-aba="email">
                <div class="grade-config">
                    <div class="painel">
                        <div class="painel-cabecalho">
                            <h2>Envio de e-mail</h2>
                            <span class="badge <?php echo $smtp_ativo ? 'badge-sucesso' : 'badge-neutro'; ?>"><?php echo $smtp_ativo ? 'SMTP ativo' : 'SMTP não configurado'; ?></span>
                        </div>
                        <?php if ($mensagem_email && !$msg_junto_ao_teste): ?>
                            <div class="aviso aviso-<?php echo $tipo_mensagem_email; ?>"><?php echo htmlspecialchars($mensagem_email); ?></div>
                        <?php endif; ?>

                        <form method="POST" id="form-email" class="bloco-form" data-confirmar="Confirma salvar as configurações de e-mail? Se estiverem erradas, ninguém recebe e-mail de cadastro nem de recuperação de senha (os e-mails ficam na fila e saem quando o SMTP estiver certo).">
                            <?php echo campoCsrf(); ?>
                            <input type="hidden" name="acao" value="email">

                            <div class="grade grade-3 grade-compacta grade-smtp">
                                <div class="campo campo-largo">
                                    <label for="smtp_host">Servidor SMTP</label>
                                    <input type="text" id="smtp_host" name="smtp_host" autocomplete="off" value="<?php echo htmlspecialchars(configSistema('smtp_host') ?: 'smtp-relay.brevo.com'); ?>" placeholder="smtp-relay.brevo.com">
                                </div>
                                <div class="campo">
                                    <label for="smtp_porta">Porta</label>
                                    <input type="number" id="smtp_porta" name="smtp_porta" min="1" max="65535" value="<?php echo htmlspecialchars(configSistema('smtp_porta') ?: '587'); ?>">
                                </div>
                                <div class="campo">
                                    <label for="smtp_seguranca">Segurança</label>
                                    <select id="smtp_seguranca" name="smtp_seguranca">
                                        <?php $seg_atual = configSistema('smtp_seguranca') ?: 'tls'; ?>
                                        <option value="ssl" <?php echo $seg_atual === 'ssl' ? 'selected' : ''; ?>>SSL (465)</option>
                                        <option value="tls" <?php echo $seg_atual === 'tls' ? 'selected' : ''; ?>>STARTTLS (587)</option>
                                        <option value="nenhuma" <?php echo $seg_atual === 'nenhuma' ? 'selected' : ''; ?>>Nenhuma (não recomendado)</option>
                                    </select>
                                </div>
                            </div>

                            <hr class="separador-form">
                            <div class="grade grade-2 grade-compacta">
                                <div class="campo">
                                    <label for="smtp_usuario">Usuário</label>
                                    <input type="text" id="smtp_usuario" name="smtp_usuario" autocomplete="off" value="<?php echo htmlspecialchars(configSistema('smtp_usuario')); ?>" placeholder="xxxxx@smtp-brevo.com">
                                    <small>O “login SMTP” do Brevo (em SMTP e API).</small>
                                </div>
                                <div class="campo">
                                    <label for="smtp_senha">Senha</label>
                                    <input type="password" id="smtp_senha" name="smtp_senha" autocomplete="new-password" data-lpignore="true" data-1p-ignore placeholder="<?php echo configSistema('smtp_senha') !== '' ? '•••••••• (salva)' : ''; ?>">
                                    <small><?php echo configSistema('smtp_senha') !== '' ? 'A chave SMTP do Brevo. Deixe em branco para manter a atual.' : 'A chave SMTP do Brevo.'; ?></small>
                                </div>
                            </div>

                            <hr class="separador-form">
                            <div class="grade grade-2 grade-compacta">
                                <div class="campo">
                                    <label for="email_remetente">E-mail do remetente</label>
                                    <input type="email" id="email_remetente" name="email_remetente" value="<?php echo htmlspecialchars(configSistema('email_remetente')); ?>" placeholder="igual ao usuário SMTP">
                                </div>
                                <div class="campo">
                                    <label for="email_remetente_nome">Nome do remetente</label>
                                    <input type="text" id="email_remetente_nome" name="email_remetente_nome" value="<?php echo htmlspecialchars(configSistema('email_remetente_nome')); ?>" placeholder="<?php echo htmlspecialchars(nomeSistema()); ?>">
                                </div>
                            </div>
                            <div class="campo">
                                <label for="url_base">URL do painel (usada nos links dos e-mails)</label>
                                <input type="text" id="url_base" name="url_base" value="<?php echo htmlspecialchars(configSistema('url_base')); ?>" placeholder="<?php echo htmlspecialchars(urlBasePainel()); ?>">
                                <small>Endereço fixo do painel (<span class="mono">https://…</span>). Sem isso o link sai do endereço da requisição, que pode ser forjado.</small>
                            </div>

                            <div class="linha-acoes">
                                <button type="submit" class="botao botao-primario">Salvar e-mail</button>
                            </div>
                        </form>
                    </div>

                    <div class="coluna-lateral">
                        <div class="painel">
                            <div class="painel-cabecalho"><h2>Testar envio</h2></div>
                            <form method="POST" id="form-teste-email" class="campo-com-acao form-teste">
                                <?php echo campoCsrf(); ?>
                                <input type="hidden" name="acao" value="email_teste">
                                <input type="email" id="email_teste_destino" name="email_teste_destino" required placeholder="seu@email.com" value="<?php echo htmlspecialchars($_SESSION['usuario_email'] ?? ''); ?>" aria-label="Enviar e-mail de teste para">
                                <button type="submit" class="botao" id="btn-teste-email">Enviar teste</button>
                            </form>
                            <?php if ($mensagem_email && $msg_junto_ao_teste): ?>
                                <div class="aviso aviso-<?php echo $tipo_mensagem_email; ?>" id="resultado-email" style="margin:12px 0 0;"><?php echo htmlspecialchars($mensagem_email); ?></div>
                            <?php endif; ?>
                            <small class="texto-suave dica-copiar">Grave as configurações antes: o teste usa o que está salvo. Confira também a caixa de spam.</small>
                        </div>

                        <div class="painel">
                            <div class="painel-cabecalho">
                                <h2>Fila e histórico</h2>
                                <a href="emails" class="texto-suave link-historico">Ver histórico →</a>
                            </div>
                            <?php if (!$fila['disponivel']): ?>
                                <p class="texto-suave" style="margin:0;">A fila ainda não existe. Rode “Atualizar Banco”.</p>
                            <?php else: ?>
                                <div class="estados-fila">
                                    <div><strong><?php echo number_format($envios24['enviados'], 0, ',', '.'); ?></strong><span>enviados (24 h)</span></div>
                                    <div class="<?php echo $envios24['falhas'] > 0 ? 'com-falha' : ''; ?>"><strong><?php echo number_format($envios24['falhas'], 0, ',', '.'); ?></strong><span>falhas (24 h)</span></div>
                                    <div><strong><?php echo number_format($fila['pendente'] + $fila['processando'], 0, ',', '.'); ?></strong><span>na fila agora</span></div>
                                </div>
                                <p class="texto-suave dica-fila">Cadastro e confirmação saem pela fila (o cron de 1 minuto envia; cada e-mail é tentado até 5 vezes). A recuperação de senha sai na hora e só entra na fila se falhar.</p>
                                <?php if ($fila['falhou'] > 0): ?>
                                    <p class="texto-suave dica-fila"><?php echo (int) $fila['falhou']; ?> e-mail(s) esgotaram as 5 tentativas e ficaram parados na fila.</p>
                                    <form method="POST">
                                        <?php echo campoCsrf(); ?>
                                        <input type="hidden" name="acao" value="email_fila_reprocessar">
                                        <button type="submit" class="botao">Tentar de novo os que falharam</button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>

                        <div class="painel">
                            <div class="painel-cabecalho"><h2>Como funciona</h2></div>
                            <p class="texto-suave" style="margin:0;line-height:1.6;font-size:13px;">
                                Usado no cadastro (boas-vindas e confirmação de e-mail), na recuperação de senha e nos avisos de segurança.
                                <strong>Sem o SMTP configurado nenhum e-mail sai</strong> (a hospedagem bloqueia o envio nativo do PHP): os e-mails esperam na fila e saem sozinhos quando o SMTP estiver certo.
                            </p>
                            <p class="texto-suave" style="margin:12px 0 0;line-height:1.6;font-size:13px;">
                                No Brevo, autorize o IP <?php echo $ip_painel !== '' ? '<span class="mono">' . htmlspecialchars($ip_painel) . '</span> ' : 'deste servidor '; ?>em <em>Segurança → IPs autorizados</em>; sem isso o login é recusado.
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ============================== MANUTENÇÃO ============================== -->
            <section class="aba-painel" id="aba-manutencao" data-aba="manutencao">
                <div class="grade-config">
                    <div class="painel">
                        <div class="painel-cabecalho">
                            <h2>Modo manutenção</h2>
                            <span class="badge <?php echo $manut['ativo'] ? 'badge-perigo' : 'badge-neutro'; ?>"><?php echo $manut['ativo'] ? 'Ligada' : 'Desligada'; ?></span>
                        </div>
                        <?php if ($mensagem_manutencao): ?>
                            <div class="aviso aviso-<?php echo $tipo_mensagem_manutencao; ?>"><?php echo htmlspecialchars($mensagem_manutencao); ?></div>
                        <?php endif; ?>

                        <?php if ($manut['ativo']): ?>
                            <div class="aviso aviso-erro" style="line-height:1.6;">
                                <strong>A manutenção está LIGADA</strong> desde <?php echo htmlspecialchars(manutencaoQuando($manut['desde'], $manut_agora)); ?><?php echo $manut['por'] !== '' ? ' (' . htmlspecialchars($manut['por']) . ')' : ''; ?>.
                                Modo: <strong><?php echo $manut['modo'] === 'total' ? 'tudo' : 'só o painel'; ?></strong>.
                                <?php if ($manut['volta_em'] > $manut_agora): ?>Previsão de volta: <strong><?php echo htmlspecialchars(manutencaoQuando($manut['volta_em'], $manut_agora)); ?></strong>.<?php elseif ($manut['volta_em'] > 0): ?>A previsão de volta já passou.<?php endif; ?>
                                Quem não tem a liberação vê a página "Em manutenção". Esta tela e este navegador seguem normais.
                            </div>
                            <?php if ($manut_link !== ''): ?>
                                <div class="campo">
                                    <label>Link de liberação (abre o sistema normal em outro navegador ou para outra pessoa)</label>
                                    <div class="caixa-copiar">
                                        <span id="manutencao-link" class="mono"><?php echo htmlspecialchars($manut_link); ?></span>
                                        <button type="button" class="botao" id="btn-copiar-manutencao-link">Copiar</button>
                                    </div>
                                    <small class="texto-suave dica-copiar">Vale 12 horas no navegador de quem abrir e deixa de valer quando a manutenção for desligada. Não compartilhe com quem não deve ver o sistema nesse período.</small>
                                </div>
                            <?php endif; ?>
                            <form method="POST" id="form-manutencao-desligar" class="bloco-form" data-confirmar="Desligar a manutenção? O sistema volta ao normal para todo mundo agora.">
                                <?php echo campoCsrf(); ?>
                                <input type="hidden" name="acao" value="manutencao_desligar">
                                <div class="linha-acoes">
                                    <button type="submit" class="botao botao-primario">Desligar manutenção</button>
                                </div>
                            </form>
                            <div class="painel-cabecalho" style="margin-top:22px;"><h2>Mudar mensagem, modo ou previsão</h2></div>
                        <?php else: ?>
                            <p class="texto-suave" style="margin:0 0 14px;line-height:1.6;font-size:13px;">Tira o sistema do ar com uma página "Em manutenção" (em vez de erro) enquanto você faz uma atualização. Quem liga continua enxergando tudo normalmente.</p>
                        <?php endif; ?>

                        <form method="POST" id="form-manutencao" class="bloco-form" data-confirmar="<?php echo $manut['ativo'] ? 'Atualizar a mensagem, o modo e a previsão da manutenção?' : 'Ligar a manutenção agora? Quem não tem a liberação passa a ver a página de manutenção. No modo &quot;tudo&quot;, os bots, os pagamentos e os links também param até você desligar.'; ?>">
                            <?php echo campoCsrf(); ?>
                            <input type="hidden" name="acao" value="manutencao_ligar">
                            <div class="campo">
                                <label for="manutencao_modo">O que sai do ar</label>
                                <select id="manutencao_modo" name="manutencao_modo">
                                    <option value="painel"<?php echo $manut['modo'] === 'total' ? '' : ' selected'; ?>>Só o painel (os bots, os pagamentos e os links seguem funcionando)</option>
                                    <option value="total"<?php echo $manut['modo'] === 'total' ? ' selected' : ''; ?>>Tudo (painel, bots, pagamentos, links e crons)</option>
                                </select>
                            </div>
                            <div class="campo">
                                <label for="manutencao_mensagem">Mensagem na página (opcional)</label>
                                <textarea id="manutencao_mensagem" name="manutencao_mensagem" rows="3" maxlength="<?php echo MANUTENCAO_MENSAGEM_MAX; ?>" placeholder="Estamos fazendo uma atualização rápida para deixar tudo ainda melhor. Já voltamos!"><?php echo htmlspecialchars($manut['mensagem']); ?></textarea>
                            </div>
                            <div class="campo">
                                <label for="manutencao_volta_minutos">Previsão de volta, em minutos (opcional)</label>
                                <input type="number" id="manutencao_volta_minutos" name="manutencao_volta_minutos" min="1" max="<?php echo MANUTENCAO_VOLTA_MAX_MINUTOS; ?>" step="1" placeholder="Ex.: 30">
                                <small class="texto-suave">A página mostra o horário previsto. É só informativo: a manutenção <strong>não desliga sozinha</strong>.</small>
                            </div>
                            <div class="linha-acoes">
                                <button type="submit" class="botao <?php echo $manut['ativo'] ? '' : 'botao-primario'; ?>"><?php echo $manut['ativo'] ? 'Atualizar manutenção' : 'Ligar manutenção'; ?></button>
                            </div>
                        </form>
                    </div>

                    <div class="coluna-lateral">
                        <div class="painel">
                            <div class="painel-cabecalho"><h2>Qual modo usar</h2></div>
                            <p class="texto-suave" style="margin:0;line-height:1.6;font-size:13px;">
                                <strong>Só o painel</strong>: para uma atualização visual ou ajuste rápido. Os bots continuam vendendo, os pagamentos continuam sendo confirmados, os links de redirecionamento funcionam e os crons rodam. Só o painel e a API mostram a página de manutenção.
                            </p>
                            <p class="texto-suave" style="margin:12px 0 0;line-height:1.6;font-size:13px;">
                                <strong>Tudo</strong>: para mexer no banco (migração, "Atualizar Banco", restauração). O bot do Telegram e a gateway recebem "tente de novo" e reenviam depois, os links mostram a página e <strong>todos os crons pausam</strong> até você desligar. Não deixe ligado por muito tempo: vendas ficam sem Pix novo e os Pix pendentes só são conferidos quando voltar.
                            </p>
                        </div>
                        <div class="painel">
                            <div class="painel-cabecalho"><h2>Pelo servidor (SSH)</h2></div>
                            <p class="texto-suave" style="margin:0 0 8px;line-height:1.6;font-size:13px;">Na pasta do painel (ex.: <span class="mono">cd /var/www/painel</span>). Funciona mesmo com o banco fora do ar, porque o estado fica num arquivo:</p>
                            <p class="mono" style="margin:0;font-size:12.5px;line-height:1.8;overflow-wrap:anywhere;">php manutencao.php ligar --modo=total --volta-em=30 --msg="Atualizando o banco"<br>php manutencao.php status<br>php manutencao.php desligar</p>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </main>
</div>

<div class="sobreposicao-modal" id="modal-confirmar-config">
    <div class="modal-gateway" style="max-width:420px;">
        <div class="cabecalho-modal">
            <span class="titulo-modal">Confirmar alteração</span>
            <button type="button" class="fechar-modal" id="btn-fechar-confirmar-config">✕</button>
        </div>
        <div class="corpo-modal">
            <p id="texto-confirmar-config" style="margin:0 0 18px;line-height:1.6;"></p>
            <div class="linha-acoes">
                <button type="button" class="botao" id="btn-cancelar-confirmar-config">Cancelar</button>
                <button type="button" class="botao botao-primario" id="btn-confirmar-confirmar-config">Confirmar e salvar</button>
            </div>
        </div>
    </div>
</div>

<script src="../assets/js/tema.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/tema.js'); ?>"></script>
<script>
// Abas: um hash (#email) abre a aba certa (os links antigos "configuracoes#email" continuam valendo). Sem JavaScript todas as seções aparecem empilhadas.
(function () {
    var raiz = document.getElementById('config-abas');
    var abas = [].slice.call(document.querySelectorAll('.config-abas .aba-status'));
    var paineis = [].slice.call(document.querySelectorAll('.aba-painel'));
    var nomes = abas.map(function (a) { return a.dataset.aba; });

    function mostrar(nome, gravarHash) {
        if (nomes.indexOf(nome) < 0) nome = raiz.dataset.inicial || nomes[0];
        abas.forEach(function (a) {
            var ativa = a.dataset.aba === nome;
            a.classList.toggle('ativa', ativa);
            a.setAttribute('aria-selected', ativa ? 'true' : 'false');
        });
        paineis.forEach(function (p) { p.classList.toggle('ativa', p.dataset.aba === nome); });
        if (gravarHash && window.history.replaceState) window.history.replaceState(null, '', '#' + nome);
    }

    raiz.classList.add('com-js');
    abas.forEach(function (a) {
        a.addEventListener('click', function (e) { e.preventDefault(); mostrar(a.dataset.aba, true); });
    });
    window.addEventListener('hashchange', function () { mostrar(location.hash.replace('#', ''), false); });
    mostrar(location.hash.replace('#', '') || raiz.dataset.inicial, false);
})();

// Identidade: pré-visualização ao vivo (nome, cor, logo, favicon) e escolha de arquivos sem o botão feio do navegador.
(function () {
    var nome = document.getElementById('nome_sistema');
    if (!nome) return;
    var previa = document.getElementById('previa');
    var cor = document.getElementById('cor_primaria');

    function atualizarNome() {
        var v = nome.value.trim() || 'Seu painel';
        document.getElementById('previa-nome').textContent = v;
        document.getElementById('previa-titulo').textContent = v + ' — Painel';
        document.getElementById('contagem-nome').textContent = nome.value.length + '/60';
    }
    function atualizarCor() {
        previa.style.setProperty('--previa-cor', cor.value);
        document.getElementById('cor-hex').textContent = cor.value.toUpperCase();
    }
    nome.addEventListener('input', atualizarNome);
    cor.addEventListener('input', atualizarCor);
    [].forEach.call(document.querySelectorAll('.amostra-cor'), function (b) {
        b.addEventListener('click', function () { cor.value = b.dataset.cor; atualizarCor(); });
    });
    atualizarNome();

    function arquivo(inputId, imagens, rotuloId, padrao) {
        var input = document.getElementById(inputId);
        var rotulo = document.getElementById(rotuloId);
        input.addEventListener('change', function () {
            var f = input.files && input.files[0];
            if (!f) { rotulo.textContent = padrao; return; }
            if (f.size > 2 * 1024 * 1024) {
                input.value = '';
                rotulo.textContent = 'Arquivo maior que 2 MB: escolha outro.';
                return;
            }
            rotulo.textContent = f.name + ' (novo, salve para aplicar)';
            var url = URL.createObjectURL(f);
            imagens.forEach(function (id) {
                var img = document.getElementById(id);
                img.src = url;
                img.style.visibility = 'visible';
            });
        });
    }
    arquivo('logo', ['logo-atual', 'previa-logo'], 'logo-nome', 'Usando a atual');
    arquivo('favicon', ['favicon-atual', 'previa-favicon'], 'favicon-nome', 'Usando o atual');
})();

// E-mail: trocar a segurança acerta a porta quando ela ainda é uma das padrão; o teste mostra "Enviando…".
(function () {
    var porta = document.getElementById('smtp_porta');
    var seg = document.getElementById('smtp_seguranca');
    if (!porta || !seg) return;
    seg.addEventListener('change', function () {
        if (['465', '587', '25'].indexOf(String(porta.value)) >= 0) porta.value = seg.value === 'ssl' ? 465 : (seg.value === 'tls' ? 587 : porta.value);
    });
    var teste = document.getElementById('form-teste-email');
    if (teste) teste.addEventListener('submit', function () {
        var b = document.getElementById('btn-teste-email');
        b.disabled = true; b.textContent = 'Enviando…';
    });
})();

// Segunda confirmação antes de salvar: essas telas mudam a identidade visual e as credenciais pra todo mundo (um clique errado aqui
// pode quebrar o login com Google ou o envio de e-mail de quem já usa). Intercepta o submit, mostra o que vai mudar e só envia no "Confirmar".
(function () {
    var modal = document.getElementById('modal-confirmar-config');
    var texto = document.getElementById('texto-confirmar-config');
    var btnConfirmar = document.getElementById('btn-confirmar-confirmar-config');
    var btnCancelar = document.getElementById('btn-cancelar-confirmar-config');
    var btnFechar = document.getElementById('btn-fechar-confirmar-config');
    var formPendente = null;

    function abrirConfirmacao(form) {
        formPendente = form;
        texto.textContent = form.dataset.confirmar || 'Confirma salvar essas alterações?';
        modal.classList.add('aberto');
    }
    function fecharConfirmacao() {
        formPendente = null;
        modal.classList.remove('aberto');
    }

    ['form-identidade', 'form-dominios', 'form-google', 'form-email', 'form-manutencao', 'form-manutencao-desligar'].forEach(function (id) {
        var form = document.getElementById(id);
        if (!form) return;
        form.addEventListener('submit', function (e) {
            if (form.dataset.confirmado === '1') return; // já confirmado, deixa enviar
            e.preventDefault();
            abrirConfirmacao(form);
        });
    });

    btnConfirmar.addEventListener('click', function () {
        if (!formPendente) return;
        formPendente.dataset.confirmado = '1';
        formPendente.submit();
        fecharConfirmacao();
    });
    btnCancelar.addEventListener('click', fecharConfirmacao);
    btnFechar.addEventListener('click', fecharConfirmacao);
    modal.addEventListener('click', function (e) { if (e.target === modal) fecharConfirmacao(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') fecharConfirmacao(); });
})();

// Copiar a URL de retorno do Google: Clipboard API (precisa de https) ou, senão, o jeito antigo por textarea temporária.
(function () {
    var btn = document.getElementById('btn-copiar-redirect-uri');
    if (!btn) return;
    var texto = document.getElementById('google-redirect-uri').textContent.trim();

    function avisar() {
        var original = btn.textContent;
        btn.textContent = 'Copiado!';
        setTimeout(function () { btn.textContent = original; }, 1500);
    }
    function copiarFallback(valor, callback) {
        var tmp = document.createElement('textarea');
        tmp.value = valor;
        document.body.appendChild(tmp);
        tmp.select();
        document.execCommand('copy');
        document.body.removeChild(tmp);
        callback();
    }
    btn.addEventListener('click', function () {
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(texto).then(avisar).catch(function () { copiarFallback(texto, avisar); });
        } else {
            copiarFallback(texto, avisar);
        }
    });
})();

// Manutenção: copiar o link de liberação.
(function () {
    var btn = document.getElementById('btn-copiar-manutencao-link');
    if (!btn) return;
    var texto = document.getElementById('manutencao-link').textContent.trim();
    function avisar() {
        var original = btn.textContent;
        btn.textContent = 'Copiado!';
        setTimeout(function () { btn.textContent = original; }, 1500);
    }
    btn.addEventListener('click', function () {
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(texto).then(avisar).catch(function () { copiarManutencaoFallback(texto, avisar); });
        } else {
            copiarManutencaoFallback(texto, avisar);
        }
    });
    function copiarManutencaoFallback(valor, callback) {
        var tmp = document.createElement('textarea');
        tmp.value = valor;
        document.body.appendChild(tmp);
        tmp.select();
        document.execCommand('copy');
        document.body.removeChild(tmp);
        callback();
    }
})();

// Depois de enviar o teste de e-mail (ou salvar o e-mail) a página recarrega no topo: leva a vista até o resultado.
(function () {
    var alvo = document.getElementById('resultado-email') || document.querySelector('#aba-email .aviso');
    if (alvo && alvo.scrollIntoView && document.getElementById('aba-email').classList.contains('ativa')) { alvo.scrollIntoView({ block: 'center' }); }
})();
</script>
</body>
</html>
