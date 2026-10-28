<?php
declare(strict_types=1);

require_once __DIR__ . '/../funcoes/usuario.php';
require_once __DIR__ . '/../funcoes/ranking.php';
verificarAdmin();
$caminho_base = '../';

$mensagem = '';
$erro = '';
$erro_lista = ''; // recusa de arquivar: aviso na lista, não no formulário

function salvarPremiosCampanha(PDO $pdo, int $campanha_id, array $titulos, array $descricoes): void
{
    $pdo->prepare("DELETE FROM campanhas_ranking_premios WHERE campanha_id = ?")->execute([$campanha_id]);
    $stmt = $pdo->prepare("INSERT INTO campanhas_ranking_premios (campanha_id, posicao, titulo, descricao) VALUES (?, ?, ?, ?)");
    for ($posicao = 1; $posicao <= 5; $posicao++) {
        $titulo = trim($titulos[$posicao] ?? '');
        if ($titulo === '') {
            continue;
        }
        $stmt->execute([$campanha_id, $posicao, $titulo, trim($descricoes[$posicao] ?? '') ?: null]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificarCsrf();
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'salvar_campanha') {
        $id = isset($_POST['id']) && $_POST['id'] !== '' ? (int) $_POST['id'] : null;
        $slug = trim($_POST['slug'] ?? '');
        $titulo = trim($_POST['titulo'] ?? '');
        $subtitulo = trim($_POST['subtitulo'] ?? '');
        $data_inicio = trim($_POST['data_inicio'] ?? '');
        $data_fim = trim($_POST['data_fim'] ?? '');
        $ativa = isset($_POST['ativa']) ? 1 : 0;

        // Campanha arquivada é registro guardado: não se edita (nem por POST direto) sem desarquivar antes.
        if ($id) {
            $stmt_arq = $pdo->prepare("SELECT arquivada_em FROM campanhas_ranking WHERE id = ?");
            $stmt_arq->execute([$id]);
            if (!empty($stmt_arq->fetchColumn())) {
                $erro = 'Campanha arquivada não pode ser editada. Desarquive antes.';
            }
        }

        // Banner é opcional: sem upload novo, mantém o que já estava salvo (edição) ou fica
        // vazio (campanha nova) — igual ao padrão já usado pra foto de bot em api.php.
        $imagem_banner = null;
        if ($id) {
            $stmt_atual = $pdo->prepare("SELECT imagem_banner FROM campanhas_ranking WHERE id = ?");
            $stmt_atual->execute([$id]);
            $imagem_banner = $stmt_atual->fetchColumn() ?: null;
        }
        if ($erro === '' && !empty($_FILES['imagem_banner']['tmp_name'])) {
            $tmp = $_FILES['imagem_banner']['tmp_name'];
            $tamanho = (int) ($_FILES['imagem_banner']['size'] ?? 0);
            if ($tamanho <= 0 || $tamanho > 5 * 1024 * 1024 || !@getimagesize($tmp)) {
                $erro = 'Banner: arquivo inválido, vazio ou maior que 5MB.';
            } else {
                $extensao = strtolower(pathinfo($_FILES['imagem_banner']['name'] ?? 'banner.jpg', PATHINFO_EXTENSION));
                if (!in_array($extensao, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                    $extensao = 'jpg';
                }
                $diretorio_uploads = __DIR__ . '/../uploads';
                if (!is_dir($diretorio_uploads)) {
                    mkdir($diretorio_uploads, 0777, true);
                }
                $caminho_local = $diretorio_uploads . '/' . uniqid('campanha_banner_', true) . '.' . $extensao;
                if (!move_uploaded_file($tmp, $caminho_local) || !file_exists($caminho_local)) {
                    $erro = 'Banner: falha ao salvar o arquivo.';
                } else {
                    $imagem_banner = 'uploads/' . basename($caminho_local);
                }
            }
        }

        if ($erro !== '') {
            // já setado acima (upload inválido) — cai direto pro formulário
        } elseif ($slug === '' || $titulo === '' || $data_inicio === '' || $data_fim === '') {
            $erro = 'Preencha slug, título e as duas datas.';
        } elseif (strtotime($data_inicio) === false || strtotime($data_fim) === false) {
            $erro = 'Datas inválidas.';
        } elseif (strtotime($data_inicio) >= strtotime($data_fim)) {
            $erro = 'A data de início precisa ser antes da data de fim.';
        } else {
            try {
                if ($id) {
                    // Mudou o período? Então a apuração anterior não vale mais: zera finalizada_em pro cron voltar a
                    // recalcular (campanha prorrogada, ou início adiado, não pode ficar congelada no placar antigo).
                    $stmt_datas = $pdo->prepare("SELECT data_inicio, data_fim FROM campanhas_ranking WHERE id = ?");
                    $stmt_datas->execute([$id]);
                    $antes = $stmt_datas->fetch(PDO::FETCH_ASSOC) ?: [];
                    $periodo_mudou = !$antes
                        || strtotime((string) $antes['data_inicio']) !== strtotime($data_inicio)
                        || strtotime((string) $antes['data_fim']) !== strtotime($data_fim);
                    $stmt = $pdo->prepare("
                        UPDATE campanhas_ranking
                        SET slug = ?, titulo = ?, subtitulo = ?, imagem_banner = ?, data_inicio = ?, data_fim = ?, ativa = ?"
                        . ($periodo_mudou ? ", finalizada_em = NULL" : "") . "
                        WHERE id = ?
                    ");
                    $stmt->execute([$slug, $titulo, $subtitulo ?: null, $imagem_banner, $data_inicio, $data_fim, $ativa, $id]);
                    $campanha_id = $id;
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO campanhas_ranking (slug, titulo, subtitulo, imagem_banner, data_inicio, data_fim, ativa)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$slug, $titulo, $subtitulo ?: null, $imagem_banner, $data_inicio, $data_fim, $ativa]);
                    $campanha_id = (int) $pdo->lastInsertId();
                }

                salvarPremiosCampanha($pdo, $campanha_id, $_POST['premio_titulo'] ?? [], $_POST['premio_descricao'] ?? []);

                // A tela de Ranking mostra UMA campanha por vez; períodos que se cruzam disputam o espaço.
                $stmt_sobrepoe = $pdo->prepare("SELECT titulo FROM campanhas_ranking WHERE id <> ? AND ativa = 1 AND arquivada_em IS NULL AND data_inicio < ? AND data_fim > ? LIMIT 1");
                $stmt_sobrepoe->execute([$campanha_id, $data_fim, $data_inicio]);
                $sobreposta = $stmt_sobrepoe->fetchColumn();

                header('Location: ranking?salvo=1' . ($sobreposta !== false && $ativa ? '&sobrepoe=' . urlencode((string) $sobreposta) : ''));
                exit;
            } catch (PDOException $e) {
                $erro = str_contains($e->getMessage(), 'Duplicate')
                    ? 'Já existe uma campanha com esse slug.'
                    : 'Erro ao salvar campanha.';
            }
        }
    } elseif ($acao === 'alternar_ativa') {
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE campanhas_ranking SET ativa = NOT ativa WHERE id = ? AND arquivada_em IS NULL")->execute([$id]);
        header('Location: ranking');
        exit;
    } elseif ($acao === 'arquivar_campanha') {
        // Arquivar guarda o registro (período, prêmios, placar final, ganhadores) e tira a campanha da lista, das telas do usuário
        // e do cron. Nunca apaga. Não deixa arquivar a que ainda está valendo: em andamento e ligada, ou já encerrada mas com o
        // placar final ainda por fechar pelo cron — senão o resultado oficial se perderia.
        $id = (int) ($_POST['id'] ?? 0);
        $stmt_c = $pdo->prepare("SELECT ativa, data_inicio, finalizada_em, arquivada_em FROM campanhas_ranking WHERE id = ?");
        $stmt_c->execute([$id]);
        $alvo = $stmt_c->fetch(PDO::FETCH_ASSOC);
        if (!$alvo) {
            $erro_lista = 'Campanha não encontrada.';
        } elseif (!empty($alvo['arquivada_em'])) {
            header('Location: ranking');
            exit;
        } elseif ((int) $alvo['ativa'] === 1 && strtotime((string) $alvo['data_inicio']) <= time() && empty($alvo['finalizada_em'])) {
            $erro_lista = 'Esta campanha está valendo (em andamento, ou já terminou e o cron ainda vai fechar o placar final). Desligue-a ou espere o placar final antes de arquivar.';
        } else {
            $pdo->prepare("UPDATE campanhas_ranking SET arquivada_em = NOW() WHERE id = ? AND arquivada_em IS NULL")->execute([$id]);
            header('Location: ranking?arquivada=1');
            exit;
        }
    } elseif ($acao === 'desarquivar_campanha') {
        // Volta para a lista como estava (a chave "ativa" não muda: se estava desligada, continua desligada).
        $id = (int) ($_POST['id'] ?? 0);
        $stmt_d = $pdo->prepare("UPDATE campanhas_ranking SET arquivada_em = NULL WHERE id = ? AND arquivada_em IS NOT NULL");
        $stmt_d->execute([$id]);
        header('Location: ranking' . ($stmt_d->rowCount() > 0 ? '?desarquivada=1' : '?arquivadas=1'));
        exit;
    }
}

if (($_GET['aviso'] ?? '') === 'editar') {
    $mensagem = 'Campanha arquivada não pode ser editada: desarquive antes.';
}
if (isset($_GET['salvo'])) {
    $mensagem = 'Campanha salva com sucesso.';
    if (!empty($_GET['sobrepoe'])) {
        $mensagem .= ' Atenção: o período cruza com "' . (string) $_GET['sobrepoe'] . '" e a tela de Ranking mostra uma campanha por vez (a que termina primeiro). Ajuste as datas ou desligue uma delas.';
    }
}

if (isset($_GET['arquivada'])) {
    $mensagem = 'Campanha arquivada. O registro (período, prêmios, placar final e ganhadores) está guardado na aba Arquivadas e dá para desarquivar.';
} elseif (isset($_GET['desarquivada'])) {
    $mensagem = 'Campanha desarquivada: voltou para a lista (com a chave "ativa" como estava).';
}

$campanha_edicao = null;
$premios_edicao = [];
if (isset($_GET['editar'])) {
    $stmt = $pdo->prepare("SELECT * FROM campanhas_ranking WHERE id = ?");
    $stmt->execute([(int) $_GET['editar']]);
    $campanha_edicao = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($campanha_edicao && !empty($campanha_edicao['arquivada_em'])) {
        // Arquivada é registro guardado: para editar, desarquive primeiro.
        header('Location: ranking?arquivadas=1&aviso=editar');
        exit;
    }
    if ($campanha_edicao) {
        $stmt = $pdo->prepare("SELECT posicao, titulo, descricao FROM campanhas_ranking_premios WHERE campanha_id = ?");
        $stmt->execute([$campanha_edicao['id']]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $premio) {
            $premios_edicao[(int) $premio['posicao']] = $premio;
        }
    }
}

$ver_arquivadas = isset($_GET['arquivadas']);
$campanhas = $pdo->query("SELECT * FROM campanhas_ranking WHERE arquivada_em IS " . ($ver_arquivadas ? "NOT NULL ORDER BY arquivada_em DESC, data_inicio DESC" : "NULL ORDER BY data_inicio DESC"))->fetchAll(PDO::FETCH_ASSOC);
$qtd_arquivadas = (int) $pdo->query("SELECT COUNT(*) FROM campanhas_ranking WHERE arquivada_em IS NOT NULL")->fetchColumn();
$qtd_em_uso = (int) $pdo->query("SELECT COUNT(*) FROM campanhas_ranking WHERE arquivada_em IS NULL")->fetchColumn();

function campoPremio(int $posicao, array $premios_edicao): array
{
    return [
        'titulo' => $premios_edicao[$posicao]['titulo'] ?? '',
        'descricao' => $premios_edicao[$posicao]['descricao'] ?? '',
    ];
}
$abrir_modal = $campanha_edicao !== null || $erro !== '';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Campanhas de Ranking - <?php echo htmlspecialchars(nomeSistema()); ?></title>
    <?php include __DIR__ . '/../tema_inline.php'; ?>
    <link rel="stylesheet" href="../assets/css/painel.css?v=<?php echo @filemtime(__DIR__.'/../assets/css/painel.css'); ?>">
</head>
<body>
<div class="layout-painel">
    <?php include __DIR__ . '/../barra_lateral.php'; ?>

    <main class="conteudo-principal">
        <div class="cabecalho-pagina">
            <div>
                <h1>Campanhas de Ranking</h1>
                <p>Crie e gerencie as campanhas que alimentam a tela de Ranking.</p>
            </div>
            <div class="acoes-cabecalho">
                <button type="button" class="botao botao-primario" onclick="abrirModalForm()">+ Nova campanha</button>
                <button type="button" class="alternador-tema" onclick="alternarTema()" aria-label="Alternar tema">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"></path></svg>
                    Tema
                </button>
            </div>
        </div>

        <?php if ($mensagem): ?>
            <div class="painel"><div class="aviso aviso-sucesso"><?php echo htmlspecialchars($mensagem); ?></div></div>
        <?php endif; ?>
        <?php if ($erro_lista): ?>
            <div class="painel"><div class="aviso aviso-erro"><?php echo htmlspecialchars($erro_lista); ?></div></div>
        <?php endif; ?>
        <?php if ($erro && !$abrir_modal): ?>
            <div class="painel"><div class="aviso aviso-erro"><?php echo htmlspecialchars($erro); ?></div></div>
        <?php endif; ?>

        <div id="modal-form" class="sobreposicao-modal<?php echo $abrir_modal ? ' aberto' : ''; ?>">
            <div class="modal-gateway modal-largo">
                <div class="cabecalho-modal">
                    <div class="titulo-modal"><?php echo $campanha_edicao ? 'Editar campanha' : 'Nova campanha'; ?></div>
                    <button type="button" class="fechar-modal" onclick="fecharModalForm()" aria-label="Fechar">✕</button>
                </div>
                <div class="corpo-modal">
            <?php if ($erro && $abrir_modal): ?>
                <div class="aviso aviso-erro"><?php echo htmlspecialchars($erro); ?></div>
            <?php endif; ?>
            <form method="POST" enctype="multipart/form-data">
                <?php echo campoCsrf(); ?>
                <input type="hidden" name="acao" value="salvar_campanha">
                <?php if ($campanha_edicao): ?><input type="hidden" name="id" value="<?php echo (int) $campanha_edicao['id']; ?>"><?php endif; ?>

                <div class="grade grade-2 grade-compacta">
                    <div class="campo">
                        <label for="slug">Slug (identificador único)</label>
                        <input type="text" id="slug" name="slug" required maxlength="60" placeholder="dubai-2026"
                               value="<?php echo htmlspecialchars($campanha_edicao['slug'] ?? ''); ?>">
                    </div>
                    <div class="campo completo">
                        <label for="titulo">Título</label>
                        <input type="text" id="titulo" name="titulo" required maxlength="100" placeholder="Dubai 2026"
                               value="<?php echo htmlspecialchars($campanha_edicao['titulo'] ?? ''); ?>">
                    </div>
                    <div class="campo completo">
                        <label for="subtitulo">Subtítulo (opcional)</label>
                        <input type="text" id="subtitulo" name="subtitulo" maxlength="255" placeholder="Cinco posições, cinco passaportes..."
                               value="<?php echo htmlspecialchars($campanha_edicao['subtitulo'] ?? ''); ?>">
                    </div>
                    <div class="campo completo">
                        <label for="imagem_banner">Banner (opcional)</label>
                        <?php if (!empty($campanha_edicao['imagem_banner'])): ?>
                            <img src="../<?php echo htmlspecialchars($campanha_edicao['imagem_banner']); ?>" alt="" style="max-width:100%;border-radius:10px;margin-bottom:8px;display:block;">
                        <?php endif; ?>
                        <input type="file" id="imagem_banner" name="imagem_banner" accept="image/jpeg,image/png,image/webp">
                        <p class="texto-ajuda">Aparece no lado direito do card do topo da tela de Ranking, esmaecendo para o fundo — use uma imagem larga (ex.: 1200×400), com o assunto principal à direita e sem texto (o título e as datas já aparecem ao lado). Até 5 MB, JPG/PNG/WebP. Sem banner, aparece o logo do sistema.</p>
                    </div>
                    <div class="campo">
                        <label for="data_inicio">Início</label>
                        <input type="datetime-local" id="data_inicio" name="data_inicio" required
                               value="<?php echo $campanha_edicao ? date('Y-m-d\TH:i', strtotime($campanha_edicao['data_inicio'])) : ''; ?>">
                    </div>
                    <div class="campo">
                        <label for="data_fim">Fim</label>
                        <input type="datetime-local" id="data_fim" name="data_fim" required
                               value="<?php echo $campanha_edicao ? date('Y-m-d\TH:i', strtotime($campanha_edicao['data_fim'])) : ''; ?>">
                    </div>
                </div>

                <label class="opcao-ativar-gateway" style="margin-top:14px;">
                    <input type="checkbox" name="ativa" <?php echo (!$campanha_edicao || $campanha_edicao['ativa']) ? 'checked' : ''; ?>>
                    <span>Campanha ativa</span>
                </label>

                <div class="divisor-secao">
                    <h2 style="font-size: 16px; margin-bottom: 14px;">Premiação (até 5 posições)</h2>
                    <div class="grade grade-compacta">
                        <?php for ($posicao = 1; $posicao <= 5; $posicao++): $premio = campoPremio($posicao, $premios_edicao); ?>
                            <div class="grade grade-2 grade-compacta">
                                <div class="campo">
                                    <label>Título — <?php echo $posicao; ?>º lugar</label>
                                    <input type="text" name="premio_titulo[<?php echo $posicao; ?>]" maxlength="100" placeholder="<?php echo $posicao; ?>º lugar"
                                           value="<?php echo htmlspecialchars($premio['titulo']); ?>">
                                </div>
                                <div class="campo">
                                    <label>Descrição</label>
                                    <input type="text" name="premio_descricao[<?php echo $posicao; ?>]" maxlength="255" placeholder="Ex: Pacote completo pra Dubai"
                                           value="<?php echo htmlspecialchars($premio['descricao']); ?>">
                                </div>
                            </div>
                        <?php endfor; ?>
                    </div>
                    <small>Deixe o título em branco pra não premiar aquela posição.</small>
                </div>

                <div class="linha-acoes" style="margin-top:20px;">
                    <button type="button" class="botao" onclick="fecharModalForm()">Cancelar</button>
                    <button type="submit" class="botao botao-primario"><?php echo $campanha_edicao ? 'Salvar alterações' : 'Criar campanha'; ?></button>
                </div>
            </form>
                </div>
            </div>
        </div>

        <div class="painel">
            <div class="painel-cabecalho">
                <h2><?php echo $ver_arquivadas ? 'Campanhas arquivadas' : 'Campanhas cadastradas'; ?></h2>
                <div class="abas-status">
                    <a href="ranking" class="aba-status<?php echo $ver_arquivadas ? '' : ' ativa'; ?>">Em uso (<?php echo $qtd_em_uso; ?>)</a>
                    <a href="ranking?arquivadas=1" class="aba-status<?php echo $ver_arquivadas ? ' ativa' : ''; ?>">Arquivadas (<?php echo $qtd_arquivadas; ?>)</a>
                </div>
            </div>
            <?php if ($ver_arquivadas): ?>
                <p class="texto-suave" style="margin:0 0 12px;">Registro das campanhas já encerradas: período, prêmios, placar final e ganhadores continuam guardados (clique no título ou no troféu). Não aparecem para os usuários nem são recalculadas. Campanha de ranking nunca é apagada.</p>
            <?php endif; ?>
            <div class="tabela-dados">
                <table>
                    <thead>
                        <tr>
                            <th>Título</th>
                            <th>Situação</th>
                            <th>Período</th>
                            <th>Apuração</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($campanhas)): ?>
                            <tr><td colspan="5" style="text-align:center;padding:32px;" class="texto-suave"><?php echo $ver_arquivadas ? 'Nenhuma campanha arquivada.' : 'Nenhuma campanha cadastrada ainda.'; ?></td></tr>
                        <?php else: foreach ($campanhas as $c): ?>
                            <tr>
                                <td><a href="ranking_classificacao?id=<?php echo (int) $c['id']; ?>" style="color:inherit;font-weight:600;"><?php echo htmlspecialchars($c['titulo']); ?></a><div class="celula-sub mono"><?php echo htmlspecialchars($c['slug']); ?></div></td>
                                <?php
                                // Mesma leitura da tela do usuário: quem diz se está rolando são
                                // as datas; `ativa` é só o interruptor do admin.
                                $agora_ts = time();
                                $sit = situacaoCampanhaRanking($c);
                                ?>
                                <td><span class="badge <?php echo $sit[1]; ?>"><?php echo $sit[0]; ?></span></td>
                                <td class="mono texto-suave"><?php echo date('d/m/y', strtotime($c['data_inicio'])); ?> — <?php echo date('d/m/y', strtotime($c['data_fim'])); ?></td>
                                <td class="texto-suave">
                                    <?php if (!empty($c['arquivada_em'])): ?>Arquivada em <?php echo date('d/m/y H:i', strtotime($c['arquivada_em'])); ?><?php if (!empty($c['finalizada_em'])): ?> · placar fechado em <?php echo date('d/m/y H:i', strtotime($c['finalizada_em'])); ?><?php endif; ?>
                                    <?php elseif (!empty($c['finalizada_em'])): ?>Fechada em <?php echo date('d/m/y H:i', strtotime($c['finalizada_em'])); ?>
                                    <?php elseif (strtotime($c['data_fim']) < $agora_ts): ?><span title="O cron do ranking fecha o placar na próxima rodada">Aguardando o cron</span>
                                    <?php elseif (strtotime($c['data_inicio']) > $agora_ts): ?>—
                                    <?php else: ?>Recalculando a cada rodada do cron<?php endif; ?>
                                </td>
                                <td>
                                    <div style="display:flex;gap:6px;">
                                        <a href="ranking_classificacao?id=<?php echo (int) $c['id']; ?>" class="btn-icon" title="Ver classificação e ganhadores">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6M18 9h1.5a2.5 2.5 0 0 0 0-5H18M4 22h16M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22M18 2H6v7a6 6 0 0 0 12 0V2Z"/></svg>
                                        </a>
                                        <?php if (!empty($c['arquivada_em'])): ?>
                                        <form method="POST" style="display:inline;">
                                            <?php echo campoCsrf(); ?>
                                            <input type="hidden" name="acao" value="desarquivar_campanha">
                                            <input type="hidden" name="id" value="<?php echo (int) $c['id']; ?>">
                                            <button type="submit" class="btn-icon" title="Desarquivar" aria-label="Desarquivar">
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/></svg>
                                            </button>
                                        </form>
                                        <?php else: ?>
                                        <a href="ranking.php?editar=<?php echo (int) $c['id']; ?>" class="btn-icon editar" title="Editar">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                        </a>
                                        <form method="POST" style="display:inline;">
                                            <?php echo campoCsrf(); ?>
                                            <input type="hidden" name="acao" value="alternar_ativa">
                                            <input type="hidden" name="id" value="<?php echo (int) $c['id']; ?>">
                                            <label class="chave-gateway chave-cartao" title="<?php echo $c['ativa'] ? 'Ligada — clique para desligar' : 'Desligada — clique para ligar'; ?>">
                                                <input type="checkbox" <?php echo $c['ativa'] ? 'checked' : ''; ?> aria-label="Campanha ativa" onchange="this.disabled=true; this.form.submit();">
                                                <span class="chave-gateway-trilho"></span>
                                            </label>
                                        </form>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Arquivar esta campanha? O registro (período, prêmios, placar final e ganhadores) fica guardado na aba Arquivadas e dá para desarquivar. Ela deixa de aparecer para os usuários.');">
                                            <?php echo campoCsrf(); ?>
                                            <input type="hidden" name="acao" value="arquivar_campanha">
                                            <input type="hidden" name="id" value="<?php echo (int) $c['id']; ?>">
                                            <button type="submit" class="btn-icon" title="Arquivar" aria-label="Arquivar">
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="5" rx="1"/><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8M10 12h4"/></svg>
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>

<script src="../assets/js/tema.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/tema.js'); ?>"></script>
<script>
function abrirModalForm() { document.getElementById('modal-form').classList.add('aberto'); }
function fecharModalForm() {
    <?php if ($abrir_modal && !empty($_GET['editar'])): ?>location.href = 'ranking'; return;<?php endif; ?>
    document.getElementById('modal-form').classList.remove('aberto');
}
document.getElementById('modal-form').addEventListener('click', function (e) { if (e.target === this) fecharModalForm(); });
document.addEventListener('keydown', function (e) { if (e.key === 'Escape') fecharModalForm(); });
</script>
</body>
</html>
