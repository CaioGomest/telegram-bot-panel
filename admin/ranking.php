<?php
declare(strict_types=1);

require_once __DIR__ . '/../funcoes/usuario.php';
require_once __DIR__ . '/../funcoes/ranking.php';
verificarAdmin();
$caminho_base = '../';

$mensagem = '';
$erro = '';

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

        // Banner é opcional: sem upload novo, mantém o que já estava salvo (edição) ou fica
        // vazio (campanha nova) — igual ao padrão já usado pra foto de bot em api.php.
        $imagem_banner = null;
        if ($id) {
            $stmt_atual = $pdo->prepare("SELECT imagem_banner FROM campanhas_ranking WHERE id = ?");
            $stmt_atual->execute([$id]);
            $imagem_banner = $stmt_atual->fetchColumn() ?: null;
        }
        if (!empty($_FILES['imagem_banner']['tmp_name'])) {
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
                $stmt_sobrepoe = $pdo->prepare("SELECT titulo FROM campanhas_ranking WHERE id <> ? AND ativa = 1 AND data_inicio < ? AND data_fim > ? LIMIT 1");
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
        $pdo->prepare("UPDATE campanhas_ranking SET ativa = NOT ativa WHERE id = ?")->execute([$id]);
        header('Location: ranking');
        exit;
    }
}

if (isset($_GET['salvo'])) {
    $mensagem = 'Campanha salva com sucesso.';
    if (!empty($_GET['sobrepoe'])) {
        $mensagem .= ' Atenção: o período cruza com "' . (string) $_GET['sobrepoe'] . '" e a tela de Ranking mostra uma campanha por vez (a que termina primeiro). Ajuste as datas ou desligue uma delas.';
    }
}

$campanha_edicao = null;
$premios_edicao = [];
if (isset($_GET['editar'])) {
    $stmt = $pdo->prepare("SELECT * FROM campanhas_ranking WHERE id = ?");
    $stmt->execute([(int) $_GET['editar']]);
    $campanha_edicao = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($campanha_edicao) {
        $stmt = $pdo->prepare("SELECT posicao, titulo, descricao FROM campanhas_ranking_premios WHERE campanha_id = ?");
        $stmt->execute([$campanha_edicao['id']]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $premio) {
            $premios_edicao[(int) $premio['posicao']] = $premio;
        }
    }
}

$campanhas = $pdo->query("SELECT * FROM campanhas_ranking ORDER BY data_inicio DESC")->fetchAll(PDO::FETCH_ASSOC);

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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
            <div class="painel-cabecalho"><h2>Campanhas cadastradas</h2></div>
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
                            <tr><td colspan="5" style="text-align:center;padding:32px;" class="texto-suave">Nenhuma campanha cadastrada ainda.</td></tr>
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
                                    <?php if (!empty($c['finalizada_em'])): ?>Fechada em <?php echo date('d/m/y H:i', strtotime($c['finalizada_em'])); ?>
                                    <?php elseif (strtotime($c['data_fim']) < $agora_ts): ?><span title="O cron do ranking fecha o placar na próxima rodada">Aguardando o cron</span>
                                    <?php elseif (strtotime($c['data_inicio']) > $agora_ts): ?>—
                                    <?php else: ?>Recalculando a cada rodada do cron<?php endif; ?>
                                </td>
                                <td>
                                    <div style="display:flex;gap:6px;">
                                        <a href="ranking_classificacao?id=<?php echo (int) $c['id']; ?>" class="btn-icon" title="Ver classificação e ganhadores">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6M18 9h1.5a2.5 2.5 0 0 0 0-5H18M4 22h16M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22M18 2H6v7a6 6 0 0 0 12 0V2Z"/></svg>
                                        </a>
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
