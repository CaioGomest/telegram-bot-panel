<?php
declare(strict_types=1);

/**
 * Admin → E-mails: histórico de TODA tentativa de envio do painel (boas-vindas, verificação, recuperação de senha, teste), o que o
 * servidor SMTP respondeu e o que está na fila agora. "Enviado" = o servidor SMTP aceitou a mensagem; a entrega final na caixa do
 * destinatário só o provedor mostra (no Brevo: Transacional → Logs). O corpo do e-mail nunca é guardado aqui (links e códigos).
 */

require_once __DIR__ . '/../funcoes/usuario.php';
require_once __DIR__ . '/../funcoes/email.php';
require_once __DIR__ . '/../funcoes/paginador.php';
verificarAdmin();
$caminho_base = '../';

$aviso = null;   // [tipo, texto]
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'reenviar_falhas') {
    verificarCsrf();
    try {
        $n = (int) $pdo->exec("UPDATE fila_emails SET status = 'pendente', tentativas = 0, proximo_envio = NOW() WHERE status = 'falhou'");
        header('Location: emails?reenfileirados=' . $n);
        exit;
    } catch (Throwable $e) {
        $aviso = ['erro', 'Fila de e-mails indisponível (rode "Atualizar Banco").'];
    }
}
if (isset($_GET['reenfileirados'])) {
    $n = (int) $_GET['reenfileirados'];
    $aviso = ['sucesso', $n > 0 ? $n . ' e-mail(s) com falha voltaram para a fila: o cron tenta de novo no próximo minuto.' : 'Nenhum e-mail com falha para reenviar.'];
}

$rotulos_tipo = ['boas_vindas' => 'Boas-vindas', 'verificacao' => 'Verificação de e-mail', 'redefinicao' => 'Redefinição de senha',
    'senha_alterada' => 'Senha alterada', 'teste' => 'Teste do admin', 'geral' => 'Geral'];
$rotulos_origem = ['direto' => 'envio direto', 'imediato' => 'na hora', 'fila' => 'pela fila', 'teste' => 'teste'];

$tabela_existe = true;
try {
    $pdo->query('SELECT 1 FROM email_envios LIMIT 1');
} catch (Throwable $e) {
    $tabela_existe = false;
}

$filtro = in_array($_GET['status'] ?? '', ['enviado', 'falhou'], true) ? (string) $_GET['status'] : '';
$busca = trim((string) ($_GET['busca'] ?? ''));
$por_pagina = 25;
$linhas = [];
$total = 0;
$k = ['enviados' => 0, 'falhas' => 0];
if ($tabela_existe) {
    $where = '1 = 1';
    $params = [];
    if ($filtro !== '') {
        $where .= ' AND status = ?';
        $params[] = $filtro;
    }
    if ($busca !== '') {
        $where .= ' AND (para LIKE ? OR assunto LIKE ?)';
        $like = '%' . addcslashes($busca, '%_\\') . '%';
        array_push($params, $like, $like);
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM email_envios WHERE $where");
    $stmt->execute($params);
    $total = (int) $stmt->fetchColumn();
    $paginas = max(1, (int) ceil($total / $por_pagina));
    $pagina = min($paginas, max(1, (int) ($_GET['pagina'] ?? 1)));
    $offset = ($pagina - 1) * $por_pagina;
    $stmt = $pdo->prepare("SELECT * FROM email_envios WHERE $where ORDER BY id DESC LIMIT $por_pagina OFFSET $offset");
    $stmt->execute($params);
    $linhas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $k = resumoEnviosEmail24h();
}

$fila = resumoFilaEmails();
$itens_fila = [];
if ($fila['disponivel']) {
    $itens_fila = $pdo->query("SELECT para, assunto, tipo, status, tentativas, ultimo_erro, proximo_envio FROM fila_emails
        WHERE status IN ('pendente', 'processando', 'falhou') ORDER BY (status = 'falhou') DESC, id DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
}
$na_fila = $fila['pendente'] + $fila['processando'];

$smtp_ok = emailSmtpConfigurado();
$smtp = emailConfigSmtp();
[$remetente_email] = emailRemetente();

function urlEnvios(array $base, array $extra): string
{
    return '?' . http_build_query(array_filter(array_merge($base, $extra), fn($v) => $v !== '' && $v !== null));
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>E-mails - <?php echo htmlspecialchars(nomeSistema()); ?></title>
    <?php include __DIR__ . '/../tema_inline.php'; ?>
    <link rel="stylesheet" href="../assets/css/painel.css?v=<?php echo @filemtime(__DIR__.'/../assets/css/painel.css'); ?>">
</head>
<body>
<div class="layout-painel">
    <?php include __DIR__ . '/../barra_lateral.php'; ?>

    <main class="conteudo-principal">
        <div class="cabecalho-pagina">
            <div>
                <h1>E-mails</h1>
                <p>Tudo que o painel tentou enviar, o que o servidor SMTP respondeu e o que está na fila.</p>
            </div>
            <div class="acoes-cabecalho">
                <a class="botao" href="configuracoes#email">Configurar e-mail</a>
                <button type="button" class="alternador-tema" onclick="alternarTema()" aria-label="Alternar tema">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"></path></svg>
                    Tema
                </button>
            </div>
        </div>

        <?php if ($aviso): ?>
            <div class="painel"><div class="aviso aviso-<?php echo $aviso[0]; ?>"><?php echo htmlspecialchars($aviso[1]); ?></div></div>
        <?php endif; ?>

        <div class="grade-kpi grade-kpi-empilha">
            <div class="cartao-kpi">
                <div class="cartao-kpi-cabecalho"><span class="rotulo-kpi">Enviados (24 h)</span></div>
                <div class="valor-kpi"><?php echo number_format($k['enviados'], 0, ',', '.'); ?></div>
                <div class="texto-suave">aceitos pelo servidor SMTP</div>
            </div>
            <div class="cartao-kpi">
                <div class="cartao-kpi-cabecalho"><span class="rotulo-kpi">Falhas (24 h)</span></div>
                <div class="valor-kpi"><?php echo number_format($k['falhas'], 0, ',', '.'); ?></div>
                <div class="texto-suave">tentativas recusadas</div>
            </div>
            <div class="cartao-kpi">
                <div class="cartao-kpi-cabecalho"><span class="rotulo-kpi">Na fila agora</span></div>
                <div class="valor-kpi"><?php echo number_format($na_fila, 0, ',', '.'); ?></div>
                <div class="texto-suave"><?php echo $fila['falhou'] > 0 ? $fila['falhou'] . ' com falha definitiva' : 'o cron envia a cada minuto'; ?></div>
            </div>
            <div class="cartao-kpi">
                <div class="cartao-kpi-cabecalho">
                    <span class="rotulo-kpi">Servidor SMTP</span>
                    <span class="badge <?php echo $smtp_ok ? 'badge-sucesso' : 'badge-alerta'; ?>" style="margin-left:auto;"><?php echo $smtp_ok ? 'Ativo' : 'Não configurado'; ?></span>
                </div>
                <div class="valor-kpi" style="font-size:20px;overflow-wrap:anywhere;"><?php echo $smtp_ok ? htmlspecialchars($smtp['host']) : '—'; ?></div>
                <div class="texto-suave" style="overflow-wrap:anywhere;"><?php echo $smtp_ok ? 'remetente: ' . htmlspecialchars($remetente_email) : 'sem SMTP o e-mail cai no mail() do PHP e costuma ir para o spam'; ?></div>
            </div>
        </div>

        <?php if (!$tabela_existe): ?>
            <div class="painel">
                <div style="text-align:center;padding:28px;" class="texto-suave">
                    O histórico de envios ainda não existe neste banco. Rode <strong>Atualizar Banco</strong> para criá-lo.
                </div>
            </div>
        <?php else: ?>
            <div class="barra-filtros">
                <form method="GET" class="campo-busca">
                    <?php if ($filtro !== ''): ?><input type="hidden" name="status" value="<?php echo htmlspecialchars($filtro); ?>"><?php endif; ?>
                    <button type="submit" aria-label="Buscar">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"></circle><path d="M20 20l-4-4"></path></svg>
                    </button>
                    <input type="text" name="busca" value="<?php echo htmlspecialchars($busca); ?>" placeholder="Buscar por destinatário ou assunto">
                </form>
                <div class="abas-status">
                    <a href="<?php echo urlEnvios(['busca' => $busca], ['status' => '']); ?>" class="aba-status<?php echo $filtro === '' ? ' ativa' : ''; ?>">Todos</a>
                    <a href="<?php echo urlEnvios(['busca' => $busca], ['status' => 'enviado']); ?>" class="aba-status<?php echo $filtro === 'enviado' ? ' ativa' : ''; ?>">Enviados</a>
                    <a href="<?php echo urlEnvios(['busca' => $busca], ['status' => 'falhou']); ?>" class="aba-status<?php echo $filtro === 'falhou' ? ' ativa' : ''; ?>">Falhas</a>
                </div>
            </div>

            <div class="painel">
                <div class="tabela-dados">
                    <table>
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th>Situação</th>
                                <th>Para</th>
                                <th>Assunto</th>
                                <th>Resposta do servidor</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($linhas)): ?>
                                <tr><td colspan="5" style="text-align:center;padding:20px;color:var(--m);">
                                    <?php echo ($filtro === '' && $busca === '') ? 'Nenhum envio registrado ainda. O primeiro cadastro, recuperação de senha ou e-mail de teste aparece aqui.' : 'Nenhum envio encontrado com esse filtro.'; ?>
                                </td></tr>
                            <?php else: foreach ($linhas as $l):
                                $ok = $l['status'] === 'enviado';
                                $origem = $rotulos_origem[$l['origem']] ?? $l['origem'];
                                if ($l['origem'] === 'fila' && (int) $l['tentativa'] > 1) { $origem .= ', tentativa ' . (int) $l['tentativa']; }
                                $resposta = (string) ($l['resposta'] ?? '');
                            ?>
                                <tr>
                                    <td class="mono" style="white-space:nowrap;"><?php echo date('d/m/y H:i:s', strtotime((string) $l['criado_em'])); ?></td>
                                    <td><span class="badge <?php echo $ok ? 'badge-sucesso' : 'badge-perigo'; ?>"><?php echo $ok ? 'Enviado' : 'Falhou'; ?></span></td>
                                    <td><?php echo htmlspecialchars($l['para']); ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($l['assunto']); ?>
                                        <div class="celula-sub"><?php echo htmlspecialchars(($rotulos_tipo[$l['tipo']] ?? $l['tipo']) . ' · ' . $origem); ?></div>
                                    </td>
                                    <td class="col-resposta<?php echo $ok ? '' : ' erro'; ?>" title="<?php echo htmlspecialchars($resposta); ?>"><?php echo $resposta !== '' ? htmlspecialchars($resposta) : '—'; ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php echo paginador($total, $por_pagina); ?>
            </div>
            <p class="texto-suave" style="margin:12px 4px 0;">“Enviado” quer dizer que o servidor SMTP aceitou a mensagem. Se ela não chegar na caixa do destinatário, o provedor mostra o destino final (no Brevo: Transacional → Logs). O histórico guarda 90 dias e nunca guarda o conteúdo do e-mail.</p>
        <?php endif; ?>

        <?php if (!empty($itens_fila)): ?>
            <div class="painel" style="margin-top:24px;">
                <div class="painel-cabecalho">
                    <h2>Na fila agora</h2>
                    <?php if ($fila['falhou'] > 0): ?>
                        <form method="POST" style="margin-left:auto;">
                            <?php echo campoCsrf(); ?>
                            <input type="hidden" name="acao" value="reenviar_falhas">
                            <button type="submit" class="botao">Reenviar as <?php echo (int) $fila['falhou']; ?> com falha</button>
                        </form>
                    <?php endif; ?>
                </div>
                <div class="tabela-dados">
                    <table>
                        <thead>
                            <tr><th>Situação</th><th>Para</th><th>Assunto</th><th class="col-numerica">Tentativas</th><th>Próxima tentativa</th><th>Último erro</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($itens_fila as $i):
                                $sit = ['pendente' => ['Aguardando', 'badge-neutro'], 'processando' => ['Enviando', 'badge-alerta'], 'falhou' => ['Falha definitiva', 'badge-perigo']][$i['status']] ?? [$i['status'], 'badge-neutro'];
                            ?>
                                <tr>
                                    <td><span class="badge <?php echo $sit[1]; ?>"><?php echo $sit[0]; ?></span></td>
                                    <td><?php echo htmlspecialchars($i['para']); ?></td>
                                    <td><?php echo htmlspecialchars($i['assunto']); ?><div class="celula-sub"><?php echo htmlspecialchars($rotulos_tipo[$i['tipo']] ?? $i['tipo']); ?></div></td>
                                    <td class="mono col-numerica"><?php echo (int) $i['tentativas'] . ' / ' . EMAIL_FILA_MAX_TENTATIVAS; ?></td>
                                    <td class="mono" style="white-space:nowrap;"><?php echo $i['status'] === 'falhou' ? '—' : date('d/m H:i', strtotime((string) $i['proximo_envio'])); ?></td>
                                    <td class="col-resposta erro" title="<?php echo htmlspecialchars((string) $i['ultimo_erro']); ?>"><?php echo $i['ultimo_erro'] !== null && $i['ultimo_erro'] !== '' ? htmlspecialchars((string) $i['ultimo_erro']) : '—'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </main>
</div>

<script src="../assets/js/tema.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/tema.js'); ?>"></script>
</body>
</html>
