<?php
declare(strict_types=1);

require_once __DIR__ . '/../funcoes/usuario.php';
require_once __DIR__ . '/../funcoes/gateways.php';
verificarAdmin();
$caminho_base = '../';

$id_venda = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$venda = null;
$erro = null;
$gateway_nome_usado = null;
$resposta_gateway = null;

if ($id_venda > 0) {
    $stmt = $pdo->prepare("
        SELECT v.*, b.id_usuario AS id_dono, COALESCE(b.primeiro_nome, b.nome_usuario) AS nome_bot
        FROM vendas v
        JOIN bots b ON v.bot_id = b.id
        WHERE v.id = ?
    ");
    $stmt->execute([$id_venda]);
    $venda = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$venda) {
        $erro = 'Venda não encontrada.';
    } elseif (empty($venda['transacao_id'])) {
        $erro = 'Essa venda não tem TXID (transacao_id) registrado — não dá pra consultar o gateway.';
    } else {
        $gateway_config = null;

        if (!empty($venda['id_gateway'])) {
            $stmt_gw = $pdo->prepare("SELECT nome FROM gateways WHERE id = ?");
            $stmt_gw->execute([$venda['id_gateway']]);
            $nome = $stmt_gw->fetchColumn();
            if ($nome) {
                $gateway_nome_usado = (string) $nome;
                $gateway_config = getUserGatewayConfig((int) $venda['id_dono'], $gateway_nome_usado);
            }
        }

        if (!$gateway_config) {
            $gateway_config = getPrimaryUserGatewayConfig((int) $venda['id_dono']);
            $gateway_nome_usado = $gateway_config['gateway_nome'] ?? null;
        }

        if (!$gateway_config || !$gateway_nome_usado) {
            $erro = 'Não foi possível encontrar credenciais de gateway configuradas pro dono deste bot.';
        } else {
            $provedor = resolveGatewayProvider($gateway_nome_usado, $gateway_config);
            if (!$provedor) {
                $erro = 'Gateway "' . $gateway_nome_usado . '" não tem consulta de status implementada nesta ferramenta.';
            } else {
                $eh_assinatura = $venda['tipo_cobranca'] === 'assinatura' && !empty($venda['id_assinatura']);
                $resposta_gateway = $eh_assinatura
                    ? $provedor->consultarRecorrencia((string) $venda['id_assinatura'], $venda['transacao_id'] ?: null)
                    : $provedor->consultarCobranca((string) $venda['transacao_id']);
            }
        }
    }
}

function badgeStatusVendaBD(string $status): string
{
    $mapa = [
        'pago' => ['Pago', 'badge-sucesso'],
        'gerado' => ['Aguardando Pix', 'badge-alerta'],
        'cancelado' => ['Cancelado', 'badge-neutro'],
        'expirado' => ['Expirado', 'badge-neutro'],
    ];
    [$texto, $classe] = $mapa[$status] ?? [ucfirst($status), 'badge-neutro'];
    return '<span class="badge ' . $classe . '">' . htmlspecialchars($texto) . '</span>';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consultar Venda</title>
    <?php include __DIR__ . '/../tema_inline.php'; ?>
    <link rel="stylesheet" href="../assets/css/coyote.css?v=<?php echo @filemtime(__DIR__.'/../assets/css/coyote.css'); ?>">
</head>
<body>
<div class="layout-painel">
    <?php include __DIR__ . '/../barra_lateral.php'; ?>

    <main class="conteudo-principal">
        <div class="cabecalho-pagina">
            <div>
                <h1>Consultar Venda</h1>
                <p>Digite o ID de uma venda pra ver os dados salvos e validar o status real direto no gateway.</p>
            </div>
            <div class="acoes-cabecalho">
                <button type="button" class="alternador-tema" onclick="alternarTema()" aria-label="Alternar tema">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"></path></svg>
                    Tema
                </button>
            </div>
        </div>

        <div class="painel" style="max-width: 640px;">
            <form method="GET">
                <div class="campo-com-acao">
                    <input type="number" name="id" min="1" placeholder="ID da venda" value="<?php echo $id_venda ?: ''; ?>" required>
                    <button type="submit" class="botao botao-primario">Consultar</button>
                </div>
            </form>
        </div>

        <?php if ($erro): ?>
            <div class="painel" style="max-width: 640px;">
                <div class="aviso aviso-erro"><?php echo htmlspecialchars($erro); ?></div>
            </div>
        <?php endif; ?>

        <?php if ($venda): ?>
            <div class="painel" style="max-width: 640px;">
                <div class="painel-cabecalho">
                    <h2>Venda #<?php echo (int) $venda['id']; ?></h2>
                    <?php echo badgeStatusVendaBD((string) $venda['status']); ?>
                </div>
                <div class="grade grade-2 grade-compacta">
                    <div class="campo"><label>Bot</label><span><?php echo htmlspecialchars($venda['nome_bot']); ?></span></div>
                    <div class="campo"><label>ID Telegram</label><span class="mono"><?php echo htmlspecialchars((string) $venda['id_telegram']); ?></span></div>
                    <div class="campo"><label>Valor</label><span class="mono">R$ <?php echo number_format((float) $venda['valor'], 2, ',', '.'); ?></span></div>
                    <div class="campo"><label>Tipo de cobrança</label><span><?php echo htmlspecialchars((string) $venda['tipo_cobranca']); ?></span></div>
                    <div class="campo"><label>TXID</label><span class="mono texto-suave"><?php echo htmlspecialchars((string) $venda['transacao_id']); ?></span></div>
                    <div class="campo"><label>ID Assinatura</label><span class="mono texto-suave"><?php echo htmlspecialchars((string) ($venda['id_assinatura'] ?: '—')); ?></span></div>
                    <div class="campo"><label>Criado em</label><span class="mono"><?php echo date('d/m/Y H:i', strtotime((string) $venda['criado_em'])); ?></span></div>
                    <div class="campo"><label>Pago em</label><span class="mono"><?php echo $venda['pago_em'] ? date('d/m/Y H:i', strtotime((string) $venda['pago_em'])) : '—'; ?></span></div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($resposta_gateway): ?>
            <?php
                $status_gateway = strtoupper(trim((string) ($resposta_gateway['dados']['status'] ?? $resposta_gateway['dados']['statusCob'] ?? $resposta_gateway['dados']['statusRec'] ?? '')));
                $pago_no_gateway = in_array($status_gateway, ['CONCLUIDA', 'PAGO', 'LIQUIDADO', 'PAID', 'APPROVED', 'COMPLETED'], true);
            ?>
            <div class="painel" style="max-width: 640px;">
                <div class="painel-cabecalho">
                    <h2>Status no gateway (<?php echo htmlspecialchars((string) $gateway_nome_usado); ?>)</h2>
                    <?php if (!$resposta_gateway['sucesso']): ?>
                        <span class="badge badge-perigo">Falha na consulta</span>
                    <?php elseif ($pago_no_gateway): ?>
                        <span class="badge badge-sucesso">Pago no gateway</span>
                    <?php else: ?>
                        <span class="badge badge-alerta"><?php echo htmlspecialchars($status_gateway ?: 'Sem status'); ?></span>
                    <?php endif; ?>
                </div>

                <?php if (!$resposta_gateway['sucesso']): ?>
                    <div class="aviso aviso-erro"><?php echo htmlspecialchars((string) ($resposta_gateway['erro'] ?? 'Erro desconhecido ao consultar o gateway.')); ?></div>
                <?php elseif ($venda && $status_gateway && $status_gateway !== strtoupper($venda['status'])): ?>
                    <div class="aviso aviso-alerta">Status no gateway (<strong><?php echo htmlspecialchars($status_gateway); ?></strong>) diverge do status salvo no banco (<strong><?php echo htmlspecialchars(strtoupper((string) $venda['status'])); ?></strong>).</div>
                <?php endif; ?>

                <details style="margin-top:12px;">
                    <summary class="texto-suave" style="cursor:pointer;">Ver resposta completa do gateway (JSON)</summary>
                    <pre class="saida-debug" style="margin-top:8px;"><?php echo htmlspecialchars(json_encode($resposta_gateway, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></pre>
                </details>
            </div>
        <?php endif; ?>
    </main>
</div>

<script src="../assets/js/tema.js"></script>
</body>
</html>
