<?php
declare(strict_types=1);

require_once __DIR__ . '/../funcoes/usuario.php';
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
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'salvar_campanha') {
        $id = isset($_POST['id']) && $_POST['id'] !== '' ? (int) $_POST['id'] : null;
        $slug = trim($_POST['slug'] ?? '');
        $titulo = trim($_POST['titulo'] ?? '');
        $subtitulo = trim($_POST['subtitulo'] ?? '');
        $tipo = in_array($_POST['tipo'] ?? '', ['oficial', 'mensal'], true) ? $_POST['tipo'] : 'oficial';
        $data_inicio = trim($_POST['data_inicio'] ?? '');
        $data_fim = trim($_POST['data_fim'] ?? '');
        $ativa = isset($_POST['ativa']) ? 1 : 0;

        if ($slug === '' || $titulo === '' || $data_inicio === '' || $data_fim === '') {
            $erro = 'Preencha slug, título e as duas datas.';
        } elseif (strtotime($data_inicio) === false || strtotime($data_fim) === false) {
            $erro = 'Datas inválidas.';
        } elseif (strtotime($data_inicio) >= strtotime($data_fim)) {
            $erro = 'A data de início precisa ser antes da data de fim.';
        } else {
            try {
                if ($id) {
                    $stmt = $pdo->prepare("
                        UPDATE campanhas_ranking
                        SET slug = ?, titulo = ?, subtitulo = ?, tipo = ?, data_inicio = ?, data_fim = ?, ativa = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$slug, $titulo, $subtitulo ?: null, $tipo, $data_inicio, $data_fim, $ativa, $id]);
                    $campanha_id = $id;
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO campanhas_ranking (slug, titulo, subtitulo, tipo, data_inicio, data_fim, ativa)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$slug, $titulo, $subtitulo ?: null, $tipo, $data_inicio, $data_fim, $ativa]);
                    $campanha_id = (int) $pdo->lastInsertId();
                }

                salvarPremiosCampanha($pdo, $campanha_id, $_POST['premio_titulo'] ?? [], $_POST['premio_descricao'] ?? []);

                header('Location: ranking.php?salvo=1');
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
        header('Location: ranking.php');
        exit;
    }
}

if (isset($_GET['salvo'])) {
    $mensagem = 'Campanha salva com sucesso.';
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
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Campanhas de Ranking</title>
    <?php include __DIR__ . '/../tema_inline.php'; ?>
    <link rel="stylesheet" href="../assets/css/coyote.css?v=<?php echo @filemtime(__DIR__.'/../assets/css/coyote.css'); ?>">
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
                <a href="ranking.php" class="botao">Nova campanha</a>
                <button type="button" class="alternador-tema" onclick="alternarTema()" aria-label="Alternar tema">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"></path></svg>
                    Tema
                </button>
            </div>
        </div>

        <?php if ($mensagem): ?>
            <div class="painel"><div class="aviso aviso-sucesso"><?php echo htmlspecialchars($mensagem); ?></div></div>
        <?php endif; ?>
        <?php if ($erro): ?>
            <div class="painel"><div class="aviso aviso-erro"><?php echo htmlspecialchars($erro); ?></div></div>
        <?php endif; ?>

        <div class="painel">
            <div class="painel-cabecalho"><h2><?php echo $campanha_edicao ? 'Editar campanha' : 'Nova campanha'; ?></h2></div>
            <form method="POST">
                <input type="hidden" name="acao" value="salvar_campanha">
                <?php if ($campanha_edicao): ?><input type="hidden" name="id" value="<?php echo (int) $campanha_edicao['id']; ?>"><?php endif; ?>

                <div class="grade grade-2 grade-compacta">
                    <div class="campo">
                        <label for="slug">Slug (identificador único)</label>
                        <input type="text" id="slug" name="slug" required maxlength="60" placeholder="dubai-2026"
                               value="<?php echo htmlspecialchars($campanha_edicao['slug'] ?? ''); ?>">
                    </div>
                    <div class="campo">
                        <label for="tipo">Tipo</label>
                        <select id="tipo" name="tipo">
                            <option value="oficial" <?php echo (($campanha_edicao['tipo'] ?? 'oficial') === 'oficial') ? 'selected' : ''; ?>>Oficial</option>
                            <option value="mensal" <?php echo (($campanha_edicao['tipo'] ?? '') === 'mensal') ? 'selected' : ''; ?>>Mensal</option>
                        </select>
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
                    <?php if ($campanha_edicao): ?><a href="ranking.php" class="botao">Cancelar edição</a><?php endif; ?>
                    <button type="submit" class="botao botao-primario"><?php echo $campanha_edicao ? 'Salvar alterações' : 'Criar campanha'; ?></button>
                </div>
            </form>
        </div>

        <div class="painel">
            <div class="painel-cabecalho"><h2>Campanhas cadastradas</h2></div>
            <div class="tabela-dados">
                <table>
                    <thead>
                        <tr>
                            <th>Título</th>
                            <th>Tipo</th>
                            <th>Período</th>
                            <th>Status</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($campanhas)): ?>
                            <tr><td colspan="5" style="text-align:center;padding:32px;" class="texto-suave">Nenhuma campanha cadastrada ainda.</td></tr>
                        <?php else: foreach ($campanhas as $c): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($c['titulo']); ?><div class="celula-sub mono"><?php echo htmlspecialchars($c['slug']); ?></div></td>
                                <td><?php echo $c['tipo'] === 'mensal' ? 'Mensal' : 'Oficial'; ?></td>
                                <td class="mono texto-suave"><?php echo date('d/m/y', strtotime($c['data_inicio'])); ?> — <?php echo date('d/m/y', strtotime($c['data_fim'])); ?></td>
                                <td><span class="badge <?php echo $c['ativa'] ? 'badge-sucesso' : 'badge-neutro'; ?>"><?php echo $c['ativa'] ? 'Ativa' : 'Inativa'; ?></span></td>
                                <td>
                                    <div style="display:flex;gap:6px;">
                                        <a href="ranking.php?editar=<?php echo (int) $c['id']; ?>" class="botao">Editar</a>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="acao" value="alternar_ativa">
                                            <input type="hidden" name="id" value="<?php echo (int) $c['id']; ?>">
                                            <button type="submit" class="botao"><?php echo $c['ativa'] ? 'Desativar' : 'Ativar'; ?></button>
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

<script src="../assets/js/tema.js"></script>
</body>
</html>
