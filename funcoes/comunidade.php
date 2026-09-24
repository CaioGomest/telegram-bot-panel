<?php
declare(strict_types=1);

/**
 * Links institucionais da plataforma (grupos, canais, redes).
 *
 * Não é por usuário: o admin cadastra uma vez em admin/comunidade.php e todo
 * mundo vê a mesma lista em comunidade.php. membros_max = 0 significa link sem
 * limite de vagas — o card não mostra contador. Quando o atual alcança o máximo,
 * o card fica LOTADO e deixa de ser clicável.
 */

function tiposComunidade(): array
{
    return [
        'grupo' => 'Grupo',
        'canal' => 'Canal',
        'instagram' => 'Instagram',
        'telefone' => 'Telefone',
        'site' => 'Site',
        'denuncia' => 'Denúncia',
        'outro' => 'Outro',
    ];
}

function iconesComunidade(): array
{
    return [
        'whatsapp' => ['label' => 'WhatsApp', 'path' => 'M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z'],
        'telegram' => ['label' => 'Telegram', 'path' => 'M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z'],
        'instagram' => ['label' => 'Instagram', 'path' => 'M7 2h10a5 5 0 0 1 5 5v10a5 5 0 0 1-5 5H7a5 5 0 0 1-5-5V7a5 5 0 0 1 5-5zM12 16a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM17.5 6.5h.01'],
        'telefone' => ['label' => 'Telefone', 'path' => 'M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z'],
        'site' => ['label' => 'Site', 'path' => 'M2 3h20v14H2zM8 21h8M12 17v4'],
        'denuncia' => ['label' => 'Denúncia', 'path' => 'M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z'],
        'link' => ['label' => 'Link', 'path' => 'M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71'],
    ];
}

function iconePadraoPorTipoComunidade(): array
{
    return [
        'grupo' => 'whatsapp',
        'canal' => 'telegram',
        'instagram' => 'instagram',
        'telefone' => 'telefone',
        'site' => 'site',
        'denuncia' => 'denuncia',
        'outro' => 'link',
    ];
}

function comunidadeDisponivel(): bool
{
    global $pdo;

    try {
        $pdo->query('SELECT 1 FROM comunidade_links LIMIT 1');
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

function listarLinksComunidade(bool $somente_ativos = false): array
{
    global $pdo;

    $sql = 'SELECT * FROM comunidade_links';
    if ($somente_ativos) {
        $sql .= ' WHERE ativo = 1';
    }
    $sql .= ' ORDER BY ordem ASC, id ASC';

    return $pdo->query($sql)->fetchAll();
}

function buscarLinkComunidade(int $id): ?array
{
    global $pdo;

    $stmt = $pdo->prepare('SELECT * FROM comunidade_links WHERE id = ?');
    $stmt->execute([$id]);
    $link = $stmt->fetch();

    return $link ?: null;
}

function linkComunidadeLotado(array $link): bool
{
    $max = (int) ($link['membros_max'] ?? 0);
    if ($max <= 0) {
        return false;
    }

    return (int) $link['membros_atual'] >= $max;
}

function urlComunidadeValida(string $url): bool
{
    if ($url === '' || strlen($url) > 500 || preg_match('/[\x00-\x1F]/', $url)) {
        return false;
    }
    if (preg_match('#^https://#i', $url)) {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
    if (preg_match('#^mailto:[^\s@]+@[^\s@]+\.[^\s@]+$#i', $url)) {
        return true;
    }
    if (preg_match('#^tel:\+?[0-9][0-9().\-\s]{6,24}$#', $url)) {
        return true;
    }

    return false;
}

function inteiroComunidade(mixed $valor, string $campo): int
{
    if (is_array($valor)) {
        throw new RuntimeException($campo . ' inválido.');
    }
    if ($valor === '' || $valor === null) {
        return 0;
    }
    $filtrado = filter_var($valor, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 0, 'max_range' => 1000000],
    ]);
    if ($filtrado === false) {
        throw new RuntimeException($campo . ' precisa ser um número inteiro entre 0 e 1.000.000.');
    }

    return $filtrado;
}

function validarLinkComunidade(array $dados): array
{
    $tipo = (string) ($dados['tipo'] ?? '');
    $icone = (string) ($dados['icone'] ?? '');
    if (!isset(tiposComunidade()[$tipo])) {
        throw new RuntimeException('Escolha um tipo de link.');
    }
    if (!isset(iconesComunidade()[$icone])) {
        throw new RuntimeException('Escolha um ícone.');
    }

    $titulo = trim((string) ($dados['titulo'] ?? ''));
    if ($titulo === '') {
        throw new RuntimeException('Preencha o título.');
    }
    if (mb_strlen($titulo) > 80) {
        throw new RuntimeException('O título pode ter no máximo 80 caracteres.');
    }

    $subtitulo = trim((string) ($dados['subtitulo'] ?? ''));
    if (mb_strlen($subtitulo) > 120) {
        throw new RuntimeException('O subtítulo pode ter no máximo 120 caracteres.');
    }

    $url = trim((string) ($dados['url'] ?? ''));
    if (!urlComunidadeValida($url)) {
        throw new RuntimeException('O link precisa começar com https://, mailto: ou tel:.');
    }

    return [
        'tipo' => $tipo,
        'titulo' => $titulo,
        'subtitulo' => $subtitulo === '' ? null : $subtitulo,
        'url' => $url,
        'icone' => $icone,
        'membros_atual' => inteiroComunidade($dados['membros_atual'] ?? '', 'Vagas atuais'),
        'membros_max' => inteiroComunidade($dados['membros_max'] ?? '', 'Vagas máximas'),
        'ativo' => empty($dados['ativo']) ? 0 : 1,
    ];
}

function salvarLinkComunidade(?int $id, array $dados): void
{
    global $pdo;

    $limpos = validarLinkComunidade($dados);
    if ($id) {
        if (!buscarLinkComunidade($id)) {
            throw new RuntimeException('Link não encontrado.');
        }
        $stmt = $pdo->prepare('
            UPDATE comunidade_links
            SET tipo = ?, titulo = ?, subtitulo = ?, url = ?, icone = ?, membros_atual = ?, membros_max = ?, ativo = ?
            WHERE id = ?
        ');
        $stmt->execute([
            $limpos['tipo'],
            $limpos['titulo'],
            $limpos['subtitulo'],
            $limpos['url'],
            $limpos['icone'],
            $limpos['membros_atual'],
            $limpos['membros_max'],
            $limpos['ativo'],
            $id,
        ]);
        return;
    }

    $ordem = (int) $pdo->query('SELECT COALESCE(MAX(ordem), 0) + 1 FROM comunidade_links')->fetchColumn();
    $stmt = $pdo->prepare('
        INSERT INTO comunidade_links (tipo, titulo, subtitulo, url, icone, membros_atual, membros_max, ordem, ativo)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $limpos['tipo'],
        $limpos['titulo'],
        $limpos['subtitulo'],
        $limpos['url'],
        $limpos['icone'],
        $limpos['membros_atual'],
        $limpos['membros_max'],
        $ordem,
        $limpos['ativo'],
    ]);
}

function excluirLinkComunidade(int $id): ?string
{
    global $pdo;

    $link = buscarLinkComunidade($id);
    if (!$link) {
        return null;
    }
    $pdo->prepare('DELETE FROM comunidade_links WHERE id = ?')->execute([$id]);

    return (string) $link['titulo'];
}

function alternarLinkComunidade(int $id): ?array
{
    global $pdo;

    $link = buscarLinkComunidade($id);
    if (!$link) {
        return null;
    }
    $pdo->prepare('UPDATE comunidade_links SET ativo = IF(ativo = 1, 0, 1) WHERE id = ?')->execute([$id]);
    $link['ativo'] = (int) $link['ativo'] === 1 ? 0 : 1;

    return $link;
}

function moverLinkComunidade(int $id, string $direcao): void
{
    global $pdo;

    if ($direcao !== 'subir' && $direcao !== 'descer') {
        return;
    }

    $links = listarLinksComunidade(false);
    $indice = null;
    foreach ($links as $i => $link) {
        if ((int) $link['id'] === $id) {
            $indice = $i;
            break;
        }
    }
    if ($indice === null) {
        return;
    }

    $vizinho = $direcao === 'subir' ? $indice - 1 : $indice + 1;
    if (!isset($links[$vizinho])) {
        return;
    }

    $ids = array_map(static fn(array $link): int => (int) $link['id'], $links);
    $tmp = $ids[$indice];
    $ids[$indice] = $ids[$vizinho];
    $ids[$vizinho] = $tmp;

    $stmt = $pdo->prepare('UPDATE comunidade_links SET ordem = ? WHERE id = ?');
    foreach ($ids as $posicao => $link_id) {
        $stmt->execute([$posicao + 1, $link_id]);
    }
}

function svgTracoComunidade(string $path, int $tamanho = 20): string
{
    return '<svg width="' . $tamanho . '" height="' . $tamanho . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="' . htmlspecialchars($path, ENT_QUOTES) . '"></path></svg>';
}

function svgIconeComunidade(string $chave): string
{
    $icones = iconesComunidade();
    $path = $icones[$chave]['path'] ?? $icones['link']['path'];

    return svgTracoComunidade($path);
}

function renderizarCardsComunidade(array $links): void
{
    echo '<div class="comunidade-lista">';
    foreach ($links as $link) {
        $icone = (string) $link['icone'];
        $lotado = linkComunidadeLotado($link);
        $url = (string) $link['url'];
        $clicavel = !$lotado && urlComunidadeValida($url);
        $max = (int) $link['membros_max'];
        $subtitulo = trim((string) ($link['subtitulo'] ?? ''));
        $classe_icone = 'comunidade-icone icone-' . preg_replace('/[^a-z]/', '', $icone);

        $miolo = '<span class="' . $classe_icone . '">' . svgIconeComunidade($icone) . '</span>';
        $miolo .= '<span class="comunidade-textos"><strong>' . htmlspecialchars((string) $link['titulo']) . '</strong>';
        if ($subtitulo !== '') {
            $miolo .= '<span>' . htmlspecialchars($subtitulo) . '</span>';
        }
        $miolo .= '</span>';
        if ($max > 0) {
            $miolo .= '<span class="comunidade-vagas">'
                . number_format((int) $link['membros_atual'], 0, ',', '.')
                . '/'
                . number_format($max, 0, ',', '.')
                . '</span>';
        }
        if ($lotado) {
            $miolo .= '<span class="badge badge-perigo">LOTADO</span>';
        } elseif ($clicavel) {
            $miolo .= '<span class="comunidade-seta" aria-hidden="true">' . svgTracoComunidade('M9 6l6 6-6 6', 18) . '</span>';
        }

        if ($clicavel) {
            echo '<a class="comunidade-card" href="' . htmlspecialchars($url, ENT_QUOTES) . '" target="_blank" rel="noopener noreferrer">' . $miolo . '</a>';
        } else {
            echo '<div class="comunidade-card' . ($lotado ? ' lotado' : '') . '"' . ($lotado ? ' aria-disabled="true"' : '') . '>' . $miolo . '</div>';
        }
    }
    echo '</div>';
}
