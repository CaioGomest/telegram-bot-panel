<?php
declare(strict_types=1);

require_once __DIR__ . '/funcoes/usuario.php';
require_once __DIR__ . '/funcoes/gateways.php';
require_once __DIR__ . '/funcoes/log.php';
require_once __DIR__ . '/funcoes/remarketing_campanhas.php';
bloquearAdmin();
$id_usuario = (int) $_SESSION['usuario_id'];

/** Responde em JSON e encerra. */
function respostaRemarketing(array $dados, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Corpo da requisição: JSON (application/json) ou formulário. */
function entradaRemarketing(): array
{
    if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
        $corpo = json_decode((string) file_get_contents('php://input'), true);
        return is_array($corpo) ? $corpo : [];
    }
    return $_POST;
}

// ---------- API da tela (lista e assistente de criação) ----------
// Leituras (GET) e ações (POST, com token CSRF no cabeçalho X-CSRF-Token). A lista e o assistente conversam só com isto.
$acao_get = (string) ($_GET['action'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $acao_get !== '') {
    switch ($acao_get) {
        case 'listar':
            respostaRemarketing(['sucesso' => true] + remarketingListar($pdo, $id_usuario));
        case 'contexto':
            respostaRemarketing(['sucesso' => true] + remarketingContexto($pdo, $id_usuario));
        case 'obter':
            $c = remarketingLinha($pdo, $id_usuario, (int) ($_GET['id'] ?? 0));
            if (!$c) {
                respostaRemarketing(['sucesso' => false, 'mensagem' => 'Campanha não encontrada.'], 404);
            }
            respostaRemarketing(['sucesso' => true, 'campanha' => remarketingParaEdicao($c)]);
        case 'contar':
            // Alcance estimado do passo "Público": quantos leads dos bots do fluxo caem no segmento e no período.
            $id_fluxo = (int) ($_GET['id_fluxo'] ?? 0);
            $segmento = (string) ($_GET['segmento'] ?? '');
            $periodo = remarketingNormalizarPeriodo($_GET['periodo_tipo'] ?? 'total', $_GET['periodo_de'] ?? '', $_GET['periodo_ate'] ?? '');
            if (!isset(REMARKETING_SEGMENTOS[$segmento]) || $periodo['erro'] !== null) {
                respostaRemarketing(['sucesso' => false, 'mensagem' => $periodo['erro'] ?? 'Segmento inválido.'], 422);
            }
            $bots = remarketingBotsDoFluxo($pdo, $id_usuario, $id_fluxo);
            $janela = remarketingIntervaloPeriodo($periodo['tipo'], $periodo['de'], $periodo['ate']);
            $ids_bots = array_column($bots, 'id');
            // O assistente recalcula a cada clique em segmento/período: guarda a conta por 20 s (público grande com Pix gerado/expirados é consulta pesada).
            // Só a estimativa é guardada: iniciar a campanha conta de novo, na hora.
            $total = contagemEmCache('rm_alcance|' . $id_usuario . '|' . implode(',', $ids_bots) . '|' . $segmento . '|' . ($janela ? $janela[0] . '/' . $janela[1] : 'total'),
                static fn() => remarketingContarPublico($pdo, $ids_bots, $segmento, $janela[0] ?? null, $janela[1] ?? null), 20);
            respostaRemarketing(['sucesso' => true, 'bots' => count($bots), 'total' => $total]);
        case 'exportar':
            remarketingExportarCsv($pdo, $id_usuario, (int) ($_GET['id'] ?? 0));
    }
    respostaRemarketing(['sucesso' => false, 'mensagem' => 'Ação inválida.'], 400);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificarCsrf();
    $entrada = entradaRemarketing();
    $id = (int) ($entrada['id'] ?? 0);
    switch ((string) ($entrada['acao'] ?? '')) {
        case 'salvar':
            $r = remarketingSalvar($pdo, $id_usuario, $entrada, $id);
            respostaRemarketing($r, $r['sucesso'] ? 200 : 422);
        case 'iniciar':
            $r = remarketingIniciar($pdo, $id_usuario, $id);
            respostaRemarketing($r, $r['sucesso'] ? 200 : 422);
        case 'agendar':
            // O modal "Agendar campanha" manda a frequência (uma vez, diário, horários, a cada X horas, dias da semana); quem manda só agendado_em é "uma vez".
            $frequencia = $entrada['frequencia'] ?? null;
            if (!is_array($frequencia)) {
                $quando = trim(remarketingTxt($entrada['agendado_em'] ?? ''));
                $frequencia = ['tipo' => 'uma_vez', 'data' => substr($quando, 0, 10), 'hora' => substr($quando, 11, 5)];
            }
            $f = remarketingNormalizarFrequencia($frequencia);
            if ($f['erro'] !== null) {
                respostaRemarketing(['sucesso' => false, 'mensagem' => $f['erro']], 422);
            }
            $r = remarketingIniciar($pdo, $id_usuario, $id, $f['primeira'] !== '' ? $f['primeira'] : null, $f['recorrencia']);
            respostaRemarketing($r, $r['sucesso'] ? 200 : 422);
        case 'parar':
            $r = remarketingParar($pdo, $id_usuario, $id);
            respostaRemarketing($r, $r['sucesso'] ? 200 : 422);
        case 'excluir':
            $r = remarketingExcluir($pdo, $id_usuario, $id);
            respostaRemarketing($r, $r['sucesso'] ? 200 : 404);
    }
    respostaRemarketing(['sucesso' => false, 'mensagem' => 'Ação inválida.'], 400);
}

$ic = static function (string $caminho): string {
    return '<svg class="ic" viewBox="0 0 24 24" aria-hidden="true">' . $caminho . '</svg>';
};
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Remarketing - <?php echo htmlspecialchars(nomeSistema()); ?></title>
    <?php include 'tema_inline.php'; ?>
    <link rel="stylesheet" href="assets/css/painel.css?v=<?php echo @filemtime(__DIR__.'/assets/css/painel.css'); ?>">
    <link rel="stylesheet" href="assets/css/remarketing.css?v=<?php echo @filemtime(__DIR__.'/assets/css/remarketing.css'); ?>">
</head>
<body>
<div class="layout-painel">
    <?php include 'barra_lateral.php'; ?>
    <main class="conteudo-principal" id="rm-pagina">
        <header class="a-ph">
            <div>
                <h1 class="a-h1">Remarketing</h1>
                <p class="a-sub">Centro de comando de campanhas: recupere quem não comprou e engaje quem já é cliente.</p>
            </div>
            <div class="a-acts">
                <a class="a-btn pri" href="remarketing_nova" id="btn-nova-campanha"><?php echo $ic('<path d="M12 5v14M5 12h14"></path>'); ?> Nova campanha</a>
            </div>
        </header>

        <section class="rm-kpis" aria-label="Resumo das campanhas">
            <article class="a-card a-kpi"><div class="a-kh"><span class="a-ki"><?php echo $ic('<circle cx="12" cy="12" r="9"></circle><circle cx="12" cy="12" r="5"></circle><circle cx="12" cy="12" r="1"></circle>'); ?></span><span class="a-lab">Total de campanhas</span></div><div class="a-kv" id="kpi-total">0</div></article>
            <article class="a-card a-kpi"><div class="a-kh"><span class="a-ki"><?php echo $ic('<path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"></path>'); ?></span><span class="a-lab">Ativas agora</span></div><div class="a-kv" id="kpi-ativas">0</div></article>
            <article class="a-card a-kpi"><div class="a-kh"><span class="a-ki"><?php echo $ic('<path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"></path>'); ?></span><span class="a-lab">Mensagens enviadas</span></div><div class="a-kv" id="kpi-enviadas">0</div></article>
            <article class="a-card a-kpi"><div class="a-kh"><span class="a-ki"><?php echo $ic('<path d="M12 2v20M17 6H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>'); ?></span><span class="a-lab">Receita total</span></div><div class="a-kv" id="kpi-receita">R$ 0,00</div></article>
        </section>

        <div class="a-card flat rm-filtros" id="rm-filtros">
            <label class="a-search">
                <?php echo $ic('<circle cx="11" cy="11" r="7"></circle><path d="M21 21l-4.3-4.3"></path>'); ?>
                <input type="search" class="a-in" id="rm-busca" placeholder="Buscar campanhas..." aria-label="Buscar campanhas" autocomplete="off">
            </label>
            <select class="a-in rm-sel" id="rm-status" aria-label="Filtrar por situação">
                <option value="">Todas as situações</option>
                <option value="rascunho">Rascunho</option>
                <option value="pendente">Agendada</option>
                <option value="processando">Ativa</option>
                <option value="concluida">Concluída</option>
                <option value="falha">Falha</option>
            </select>
            <select class="a-in rm-sel" id="rm-ordem" aria-label="Ordenar campanhas">
                <option value="recentes">Mais recentes</option>
                <option value="antigas">Mais antigas</option>
                <option value="receita">Maior receita</option>
                <option value="enviadas">Mais enviadas</option>
                <option value="nome">Nome (A–Z)</option>
            </select>
        </div>

        <section id="rm-lista" aria-live="polite" aria-label="Campanhas por fluxo">
            <div class="a-empty">Carregando campanhas...</div>
        </section>
    </main>
</div>

<!-- Confirmação (iniciar e excluir) -->
<div class="a-over" id="rm-confirma" role="dialog" aria-modal="true" aria-labelledby="rm-confirma-titulo">
    <div class="a-modal rm-modal-peq">
        <div class="a-mh"><h2 id="rm-confirma-titulo">Confirmar</h2></div>
        <p class="a-mut" id="rm-confirma-texto"></p>
        <div class="rm-modal-acoes">
            <button type="button" class="a-btn" id="rm-confirma-nao">Cancelar</button>
            <button type="button" class="a-btn pri" id="rm-confirma-sim">Confirmar</button>
        </div>
    </div>
</div>

<!-- Agendar campanha: uma vez, diário, horários personalizados, a cada X horas ou dias da semana (recorrência) -->
<div class="a-over" id="rm-agenda" role="dialog" aria-modal="true" aria-labelledby="rm-agenda-titulo">
    <div class="a-modal rm-modal-agenda">
        <div class="a-mh"><h2 id="rm-agenda-titulo"><?php echo $ic('<rect x="3" y="4" width="18" height="18" rx="2"></rect><path d="M16 2v4M8 2v4M3 10h18"></path>'); ?> Agendar campanha</h2></div>
        <p class="a-mut" id="rm-agenda-nome"></p>

        <div id="rm-agenda-form">
            <span class="rm-lbl" id="rm-freq-rotulo">Frequência</span>
            <div class="rm-freqs" role="radiogroup" aria-labelledby="rm-freq-rotulo">
                <button type="button" class="rm-freq on" data-freq="uma_vez" role="radio" aria-checked="true">Uma vez</button>
                <button type="button" class="rm-freq" data-freq="diario" role="radio" aria-checked="false">Diário</button>
                <button type="button" class="rm-freq" data-freq="horarios" role="radio" aria-checked="false">Horários personalizados</button>
                <button type="button" class="rm-freq" data-freq="a_cada" role="radio" aria-checked="false">A cada X horas</button>
                <button type="button" class="rm-freq largo" data-freq="semana" role="radio" aria-checked="false">Dias da semana</button>
            </div>

            <div class="rm-painel" data-painel="uma_vez">
                <div class="rm-agenda-campos">
                    <label class="a-fld"><span>Data</span><input type="date" class="a-in" id="rm-agenda-data" min="<?php echo date('Y-m-d'); ?>"></label>
                    <label class="a-fld"><span>Hora</span><input type="time" class="a-in" id="rm-agenda-hora"></label>
                </div>
            </div>
            <div class="rm-painel" data-painel="diario" hidden>
                <label class="a-fld"><span>Horário de execução</span><input type="time" class="a-in" id="rm-ag-diario"></label>
            </div>
            <div class="rm-painel" data-painel="horarios" hidden>
                <span class="rm-lbl">Horários de execução</span>
                <div id="rm-ag-horarios"></div>
                <div class="rm-painel-acoes"><button type="button" class="a-btn" id="rm-ag-horario-mais"><?php echo $ic('<path d="M12 5v14M5 12h14"></path>'); ?> Adicionar horário</button></div>
            </div>
            <div class="rm-painel" data-painel="a_cada" hidden>
                <div class="rm-agenda-campos">
                    <label class="a-fld"><span>A cada (horas)</span><input type="number" class="a-in" id="rm-ag-horas" min="3" max="168" step="1" value="6" inputmode="numeric"></label>
                    <span></span>
                    <label class="a-fld"><span>Primeiro envio: data</span><input type="date" class="a-in" id="rm-ag-cada-data" min="<?php echo date('Y-m-d'); ?>"></label>
                    <label class="a-fld"><span>Primeiro envio: hora</span><input type="time" class="a-in" id="rm-ag-cada-hora"></label>
                </div>
            </div>
            <div class="rm-painel" data-painel="semana" hidden>
                <span class="rm-lbl">Dias da semana</span>
                <div class="rm-dias" id="rm-ag-dias">
                    <?php foreach (REMARKETING_DIAS_SEMANA as $i => $dia): ?><button type="button" class="rm-dia" data-dia="<?php echo $i; ?>" aria-pressed="false"><?php echo $dia; ?></button><?php endforeach; ?>
                </div>
                <label class="a-fld rm-hora-semana"><span>Horário de execução</span><input type="time" class="a-in" id="rm-ag-semana"></label>
            </div>

            <div class="rm-dica" id="rm-agenda-dica" role="status"><?php echo $ic('<circle cx="12" cy="12" r="9"></circle><path d="M12 8v5M12 16h.01"></path>'); ?><span id="rm-agenda-dica-txt"></span></div>
            <p class="rm-nota">Um fluxo só envia remarketing a cada 3 horas, no mínimo. Campanhas de outros fluxos não são afetadas.</p>
        </div>
        <p class="a-mut" id="rm-agenda-so-parar" hidden>Esta campanha está sendo enviada agora e repete sozinha. Desativar a recorrência faz ela parar depois deste envio.</p>

        <p class="rm-erro" id="rm-agenda-erro" hidden></p>
        <div class="rm-modal-acoes">
            <button type="button" class="a-btn dg rm-acao-esq" id="rm-agenda-parar" hidden>Desativar agendamento</button>
            <button type="button" class="a-btn" id="rm-agenda-nao">Cancelar</button>
            <button type="button" class="a-btn pri" id="rm-agenda-sim">Agendar</button>
        </div>
    </div>
</div>

<div id="toast" role="status" aria-live="polite"></div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>if (window.CSRF_TOKEN) { $.ajaxSetup({ headers: { 'X-CSRF-Token': window.CSRF_TOKEN } }); }</script>
<script src="assets/js/tema.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/tema.js'); ?>"></script>
<script src="assets/remarketing.js?v=<?php echo @filemtime(__DIR__ . '/assets/remarketing.js'); ?>"></script>
</body>
</html>
