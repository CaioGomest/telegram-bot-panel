<?php
declare(strict_types=1);

require_once __DIR__ . '/../funcoes/usuario.php';
require_once __DIR__ . '/../funcoes/icones.php';
verificarAdmin();
$caminho_base = '../';

/**
 * Checklist TEMPORÁRIO de testes manuais (sem banco): o progresso fica só no navegador de quem
 * marca (localStorage), por 30 dias desde a última marcação. Apagar esta página (e a entrada em
 * barra_lateral.php) quando a fase de testes acabar.
 *
 * Cada item: [id estável, título, o que conferir, link opcional (página do painel de usuário)].
 * Os links são das telas de USUÁRIO (o admin é redirecionado de lá): abra logado com a conta de teste.
 */
$grupos = [
    'Fluxo Guiado — editor' => [
        ['fg-bots', 'Bots: vincular e desvincular', 'Escolher um bot no select vincula na hora; "Desvincular" solta; contador x/10 e botão de atualizar.', 'fluxos'],
        ['fg-salvar', 'Salvar, "Tudo salvo" e Excluir', 'Mudar um campo mostra "Alterações não salvas"; Salvar volta para "Tudo salvo"; Excluir pede confirmação.', 'fluxos'],
        ['fg-boasvindas', 'Boas-vindas', 'Mensagem, mídia (imagem/vídeo), texto e cor do botão.', null],
        ['fg-planos', 'Planos', 'Adicionar/remover plano, valor, tempo de acesso, grupo de entrega, cor do botão.', null],
        ['fg-ofertas', 'Upsell, Downsell e Order Bump', 'O interruptor "Ativar" mostra os campos; sem ligar, ficam escondidos; salvar e reabrir mantém.', null],
        ['fg-pagamentos', 'Pagamentos', 'Mensagens do Pix gerado/aprovado e botões Copiar / "Já fiz o pagamento".', null],
        ['fg-suporte', 'Suporte e Cache de mídia', 'Usuário do Telegram + cor do botão; seletor de canal/grupo do cache (precisa do bot no grupo).', null],
        ['fg-secoes-bloqueadas', 'Seções bloqueadas em fluxo novo', 'Fluxo novo: só "Bots" liberado, cadeado nas outras; liberam depois de salvar.', null],
        ['fg-mobile', 'Navegação no celular', 'Cartão "Seção atual", lista que sobe de baixo, barra anterior/próxima (largura < 860px).', null],
        ['fg-lista', 'Lista de fluxos', 'Selo Guiado (azul) e Editor Visual (roxo); "Editar fluxo" abre o editor certo.', 'fluxos'],
    ],
    'Fluxo Guiado — no Telegram (bot)' => [
        ['tg-start', '/start', 'Chega a mensagem de boas-vindas com a mídia e o botão "Ver Planos".', null],
        ['tg-planos', 'Ver Planos', 'Lista dos planos + botões de Prévias, Packs e Suporte (só aparecem se ligados).', null],
        ['tg-previas', 'Prévias que somem', 'Mídias protegidas (sem encaminhar/salvar), somem no tempo configurado e entra a mensagem de depois com "Ver Planos". Testar a regra "ver de novo".', null],
        ['tg-packs', 'Packs', 'Lista → prévia do pack com "Comprar" → Pix. Pack desligado ou sem preço não aparece.', null],
        ['tg-ofertas', 'Upsell → Downsell → Order Bump', 'Escolher plano, recusar/aceitar cada oferta e conferir o valor final do Pix (desconto + extra).', null],
        ['tg-suporte', 'Botão de suporte', 'Abre o contato configurado no Telegram.', null],
        ['tg-inativo', 'Fluxo desligado', 'Com "Ativo" desligado o bot responde que o atendimento está indisponível.', null],
    ],
    'Pagamento e entrega' => [
        ['pg-gerar', 'Gerar Pix', 'Hoje falha por causa do split da OmegaPayments (Produtor não encontrado): trocar a chave pelo producerId ou zerar a taxa em Gateways.', 'gateways'],
        ['pg-pagar', 'Pagar e confirmar', 'Pagar o Pix de teste; o bot confirma (mensagem de aprovado) e a venda vira "pago".', null],
        ['pg-entrega', 'Entrega no grupo', 'Depois de pago, o cliente recebe o convite do grupo/canal do plano ou do pack.', null],
        ['pg-split', 'Split da comissão', 'Venda paga registra o split corretamente (Admin → Transações).', null],
        ['pg-expirar', 'Pix expirado', 'Deixar o Pix vencer e conferir o status "expirado".', null],
    ],
    'Assinatura e avisos' => [
        ['as-antes', 'Avisos antes de expirar', 'Com um membro perto do vencimento, chega o aviso do dia certo (14/7/5/3/2/1/no dia), com mídia e variáveis.', null],
        ['as-nodia', 'Avisos no dia da expiração', 'Chegam nos horários escolhidos (1 a 3).', null],
        ['as-duplicado', 'Sem aviso repetido', 'O mesmo aviso não é enviado duas vezes.', null],
        ['as-expirar', 'Mensagem ao expirar', 'Texto/mídia configurados + botão "Renovar agora".', null],
        ['as-expulsar', 'Expulsar do grupo', 'Ligado remove do grupo; desligado só encerra o acesso.', null],
        ['as-renovar', 'Renovação com desconto', '"Renovar agora" lista os planos com o % de desconto e gera o Pix.', null],
    ],
    'Conversões e números' => [
        ['cv-fluxo', 'Dados por fluxo', 'Resumo e Conversões mostram só o que aconteceu no fluxo (não o histórico do bot).', null],
        ['cv-filtros', 'Filtros de período', 'Hoje, 7 dias, 30 dias e Tudo.', null],
        ['cv-venda', 'Venda aparece no fluxo', 'Depois de uma venda paga: leads, Pix gerados, pagos, receita e ticket médio batem.', null],
    ],
    'Bots, grupos e webhook' => [
        ['bt-webhook', 'Webhook do bot', 'Bot responde pelo painel (webhook aponta para este servidor, sem erro 403).', 'bots'],
        ['bt-grupo', 'Bot em grupo', 'Remover e readicionar o bot como administrador: o grupo aparece no Cache de mídia e na entrega.', 'bots'],
        ['bt-duplicado', 'Bot cadastrado em duas contas', 'Tentar cadastrar o mesmo token em outra conta (o sistema deveria impedir).', 'bots'],
    ],
    'Painel do usuário' => [
        ['pu-dashboard', 'Dashboard', 'Cards e gráficos carregam.', 'index'],
        ['pu-bots', 'Meus Bots', 'Criar, editar e remover bot.', 'bots'],
        ['pu-leads', 'Leads', 'Lista, filtros e paginação.', 'leads'],
        ['pu-ranking', 'Ranking', 'Campanha ativa, posição e premiação.', 'ranking'],
        ['pu-remarketing', 'Remarketing', 'Criar campanha, envio e detalhes.', 'remarketing'],
        ['pu-traqueamento', 'Traqueamento e links', 'Links de rastreamento e origem do lead.', 'traqueamento'],
        ['pu-redirecionamento', 'Redirecionamento', 'Editor e links.', 'redirecionamento'],
        ['pu-webhooks', 'Webhooks', 'Cadastro e disparo de eventos.', 'webhooks'],
        ['pu-comunidade', 'Comunidade', 'Listagem e postagens.', 'comunidade'],
        ['pu-gateways', 'Gateways', 'Configuração da OmegaPayments.', 'gateways'],
        ['pu-conta', 'Minha Conta', 'Dados, apelido público e senha.', 'configuracao_usuario'],
        ['pu-visual', 'Editor Visual (fluxo avançado)', 'Abrir um fluxo visual, mover blocos e salvar.', 'fluxos'],
        ['pu-tema', 'Tema claro e escuro', 'Alternar o tema em cada tela e conferir contraste.', null],
        ['pu-mobile', 'Telas no celular', 'Menu inferior e telas principais em largura de celular.', null],
    ],
    'Painel admin' => [
        ['ad-visao', 'Visão Geral', 'Métricas e gráficos.', 'admin/dashboard'],
        ['ad-trans', 'Transações', 'Lista, filtros e status do split.', 'admin/transacoes'],
        ['ad-logs', 'Logs', 'Atividades do sistema.', 'admin/logs'],
        ['ad-usuarios', 'Usuários', 'Criar, editar, remover e regra de split por usuário.', 'admin/usuarios'],
        ['ad-campanhas', 'Campanhas (ranking)', 'Criar campanha e prêmios.', 'admin/ranking'],
        ['ad-config', 'Configurações', 'Identidade visual (nome, logo, cor).', 'admin/configuracoes'],
        ['ad-banco', 'Atualizar Banco', 'Roda sem erro e é idempotente.', 'admin/atualiza_banco'],
        ['ad-venda', 'Consultar Venda', 'Busca por ID e status no gateway.', 'admin/consultar_venda'],
        ['ad-desempenho', 'Testes de Desempenho', 'Página de resultados carrega.', 'admin/testes_desempenho'],
    ],
];

$total_itens = 0;
foreach ($grupos as $itens) {
    $total_itens += count($itens);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checklist de Testes - <?php echo htmlspecialchars(nomeSistema()); ?></title>
    <?php include __DIR__ . '/../tema_inline.php'; ?>
    <link rel="stylesheet" href="../assets/css/painel.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/painel.css'); ?>">
    <style>
        .ck-resumo { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; margin-bottom: 18px; }
        .ck-barra { flex: 1; min-width: 200px; height: 10px; border-radius: 99px; background: var(--p3); overflow: hidden; }
        .ck-barra i { display: block; height: 100%; width: 0; background: var(--ok); border-radius: 99px; transition: width .2s; }
        .ck-numero { font: 800 18px 'Manrope', sans-serif; }
        .ck-ferramentas { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-bottom: 18px; }
        .ck-aviso { font-size: 12px; color: var(--m); }
        .ck-item { display: flex; align-items: flex-start; gap: 12px; padding: 12px 4px; border-bottom: 1px solid var(--bd); }
        .ck-item:last-child { border-bottom: 0; }
        .ck-item input { margin-top: 3px; width: 18px; height: 18px; flex: none; accent-color: var(--ok); cursor: pointer; }
        .ck-item label { cursor: pointer; flex: 1; min-width: 0; }
        .ck-item strong { display: block; font-size: 14px; }
        .ck-item span { display: block; color: var(--m); font-size: 12.5px; line-height: 1.5; margin-top: 2px; }
        .ck-item.feito strong { text-decoration: line-through; color: var(--m); }
        .ck-item .botao { flex: none; padding: 6px 12px; font-size: 12px; }
        .ck-grupo-contagem { font: 600 12px 'Manrope', sans-serif; color: var(--m); }
        body.ck-so-pendentes .ck-item.feito { display: none; }
    </style>
</head>
<body>
<div class="layout-painel">
    <?php include __DIR__ . '/../barra_lateral.php'; ?>

    <main class="conteudo-principal">
        <div class="cabecalho-pagina">
            <div>
                <h1>Checklist de Testes</h1>
                <p>Página temporária para conferir o sistema. Marque o que já testou.</p>
            </div>
            <div class="acoes-cabecalho">
                <button type="button" class="alternador-tema" onclick="alternarTema()" aria-label="Alternar tema">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"></path></svg>
                    Tema
                </button>
            </div>
        </div>

        <div class="painel">
            <div class="ck-resumo">
                <span class="ck-numero"><span id="ck-feitos">0</span> / <?php echo $total_itens; ?></span>
                <div class="ck-barra"><i id="ck-barra"></i></div>
                <span class="ck-aviso" id="ck-validade"></span>
            </div>
            <div class="ck-ferramentas">
                <label class="basico-interruptor sem-caixa" style="margin:0"><input type="checkbox" id="ck-so-pendentes"> <span>Mostrar só pendentes</span></label>
                <button type="button" class="botao botao-claro" id="ck-limpar">Limpar tudo</button>
            </div>
            <p class="ck-aviso" style="margin:0;">O progresso fica salvo só neste navegador (sem banco de dados), por 30 dias desde a última marcação. Os links "Abrir" levam às telas de usuário: abra logado com a conta de teste.</p>
        </div>

        <?php foreach ($grupos as $titulo => $itens): ?>
            <div class="painel ck-grupo">
                <div class="painel-cabecalho">
                    <h2><?php echo htmlspecialchars($titulo); ?></h2>
                    <span class="ck-grupo-contagem"></span>
                </div>
                <?php foreach ($itens as [$id, $nome, $descricao, $link]): ?>
                    <div class="ck-item">
                        <input type="checkbox" id="ck-<?php echo htmlspecialchars($id); ?>" data-id="<?php echo htmlspecialchars($id); ?>">
                        <label for="ck-<?php echo htmlspecialchars($id); ?>">
                            <strong><?php echo htmlspecialchars($nome); ?></strong>
                            <span><?php echo htmlspecialchars($descricao); ?></span>
                        </label>
                        <?php if ($link): ?>
                            <a class="btn-icon abrir" href="../<?php echo htmlspecialchars($link); ?>" target="_blank" rel="noopener" title="Abrir a página" aria-label="Abrir a página"><?php echo iconeAcao('abrir'); ?></a>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </main>
</div>

<script src="../assets/js/tema.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/tema.js'); ?>"></script>
<script>
(function () {
    const CHAVE = 'checklist_testes_v1';
    const VALIDADE_MS = 30 * 24 * 60 * 60 * 1000;
    const total = <?php echo $total_itens; ?>;

    function ler() {
        try {
            const bruto = JSON.parse(localStorage.getItem(CHAVE) || 'null');
            if (!bruto || !bruto.salvo_em || Date.now() - bruto.salvo_em > VALIDADE_MS) {
                localStorage.removeItem(CHAVE);
                return { salvo_em: 0, itens: {} };
            }
            return bruto;
        } catch (e) { return { salvo_em: 0, itens: {} }; }
    }
    function gravar(estado) {
        estado.salvo_em = Date.now();
        try { localStorage.setItem(CHAVE, JSON.stringify(estado)); } catch (e) {}
    }

    let estado = ler();
    const caixas = Array.prototype.slice.call(document.querySelectorAll('.ck-item input'));

    function desenhar() {
        let feitos = 0;
        caixas.forEach(function (c) {
            const marcado = !!estado.itens[c.dataset.id];
            c.checked = marcado;
            c.closest('.ck-item').classList.toggle('feito', marcado);
            if (marcado) feitos++;
        });
        document.getElementById('ck-feitos').textContent = feitos;
        document.getElementById('ck-barra').style.width = (total ? Math.round(feitos / total * 100) : 0) + '%';
        document.querySelectorAll('.ck-grupo').forEach(function (g) {
            const itens = g.querySelectorAll('.ck-item input');
            const ok = g.querySelectorAll('.ck-item input:checked').length;
            g.querySelector('.ck-grupo-contagem').textContent = ok + '/' + itens.length;
        });
        const v = document.getElementById('ck-validade');
        v.textContent = estado.salvo_em ? 'Expira em ' + new Date(estado.salvo_em + VALIDADE_MS).toLocaleDateString('pt-BR') : '';
    }

    caixas.forEach(function (c) {
        c.addEventListener('change', function () {
            if (c.checked) estado.itens[c.dataset.id] = 1; else delete estado.itens[c.dataset.id];
            gravar(estado);
            desenhar();
        });
    });
    document.getElementById('ck-limpar').addEventListener('click', function () {
        if (!confirm('Desmarcar todos os itens?')) return;
        estado = { salvo_em: 0, itens: {} };
        try { localStorage.removeItem(CHAVE); } catch (e) {}
        desenhar();
    });
    document.getElementById('ck-so-pendentes').addEventListener('change', function () {
        document.body.classList.toggle('ck-so-pendentes', this.checked);
    });
    desenhar();
})();
</script>
</body>
</html>
